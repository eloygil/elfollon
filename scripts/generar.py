#!/usr/bin/python3
# This script cleans the invitation table, then generates new ones
# and the associated QR codes in a printable format

import hashlib
import mysql.connector
import sys
import time
import qrcode
from reportlab.lib.pagesizes import A4
from reportlab.pdfgen import canvas
from reportlab.lib.units import mm
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
import os


def getMySQLCredentials():
    # The purpose of this function is avoiding yet another set of credentials hardcoded in plaintext
    # We are using the ones the website uses, as the source is a PHP file it needs to be parsed
    creds = {}
    keys = ['database', 'hostname', 'username', 'password']
    for path in ['../../php-require/mysql.php', '../../php-require/mysql-elfollon.php']:
        f = open(path, 'r')
        for line in f.readlines():
            for key in keys:
                if '["mysql_' + key + '"] =' in line:
                    creds[key] = line.split("=")[-1].strip().replace("'", "").replace(";", "")
    return creds


def getIsStub():
    try:
        return sys.argv[2] == 'stub'
    except IndexError:
        return False


def generate_qr_code(uid, label):
    """Generate QR code for a given invitation hash"""
    url = f"https://reserva.elfollon.com/?invitacion={uid}"
    qr = qrcode.QRCode(
        version=1,
        error_correction=qrcode.constants.ERROR_CORRECT_L,
        box_size=10,
        border=1,
    )
    qr.add_data(url)
    qr.make(fit=True)
    img = qr.make_image(fill_color="black", back_color="white")
    
    # Create a temporary file path
    img_path = f"temp_qr_{label}.png"
    img.save(img_path)
    return img_path, url


def create_printable_pdf(hashes, labels):
    """Create a printable PDF with QR codes and labels"""
    # A4 size in mm: 210 x 297
    page_width, page_height = A4
    
    # Define sizes and margins
    margin = 10 * mm  # 10mm margins
    qr_size = 30 * mm  # QR code size (30mm)
    text_height = 5 * mm  # Height for text
    spacing = 2 * mm  # Space between elements
    
    # Calculate how many QR codes fit per row and column
    codes_per_row = int((page_width - 2 * margin) / (qr_size + spacing))
    codes_per_column = int((page_height - 2 * margin) / (qr_size + 2 * text_height + spacing))
    
    # Create PDF
    pdf_filename = "invitaciones_qr.pdf"
    c = canvas.Canvas(pdf_filename, pagesize=A4)
    
    # Try to register a sans-serif font, fall back to Helvetica if not available
    try:
        pdfmetrics.registerFont(TTFont('DejaVuSans', 'DejaVuSans.ttf'))
        font_name = 'DejaVuSans'
    except:
        font_name = 'Helvetica'
    
    # Generate and place QR codes
    temp_files = []
    for i, (uid, label) in enumerate(zip(hashes, labels)):
        # Calculate position for this QR code
        page_num = i // (codes_per_row * codes_per_column)
        position_on_page = i % (codes_per_row * codes_per_column)
        row = position_on_page // codes_per_row
        col = position_on_page % codes_per_row
        
        # Create a new page if needed
        if position_on_page == 0 and i > 0:
            c.showPage()
        
        # Calculate x, y coordinates (bottom-left corner)
        x = margin + col * (qr_size + spacing)
        y = page_height - margin - (row + 1) * (qr_size + 2 * text_height + spacing)
        
        # Generate QR code
        img_path, _ = generate_qr_code(uid, label)
        temp_files.append(img_path)
        
        # Draw title text "RESERVA ASIENTO"
        c.setFont(font_name, 8)
        c.drawCentredString(x + qr_size/2, y + qr_size + text_height/2, "RESERVA ASIENTO")
        
        # Draw QR code
        c.drawImage(img_path, x, y, width=qr_size, height=qr_size)
        
        # Draw label text "#XXXX"
        c.setFont(font_name, 9)
        c.drawCentredString(x + qr_size/2, y - text_height/2, f"#{label}")
    
    # Save the PDF
    c.save()
    
    # Clean up temporary files
    for temp_file in temp_files:
        try:
            os.remove(temp_file)
        except:
            pass
    
    print(f"PDF generado con éxito: {pdf_filename}")
    print(f"Se han creado {len(hashes)} códigos QR distribuidos en {page_num + 1} páginas")
    print(f"Disposición: {codes_per_row} códigos por fila, {codes_per_column} filas por página")


# Main script execution
if __name__ == "__main__":
    # Check the number of invitations to be generated has been provided
    try:
        n_inv = int(sys.argv[1])
    except IndexError:
        print("ERROR: A number of invitations must be provided.")
        sys.exit(1)

    # Get MySQL credentials and connect
    creds = getMySQLCredentials()
    conn = mysql.connector.connect(user=creds['username'], password=creds['password'], 
                                database=creds['database'])
    cursor = conn.cursor()

    # Clean invitation and groups database
    cursor.execute("DELETE FROM `invitaciones` WHERE 1")
    cursor.execute("DELETE FROM `grupos` WHERE 1")

    # If this is a stub run; obtain groups and create them in the DB
    stub_groups = []
    if getIsStub():
        import random
        biggest = min(32, n_inv * 0.08)
        remaining = n_inv
        stub_gid = 1
        while remaining > n_inv * 0.10:
            gsize = random.randint(2, biggest)
            remaining -= gsize
            for _ in range(gsize):
                stub_groups.append(str(stub_gid).zfill(40))
            cursor.execute("INSERT INTO `grupos` (gid, id) VALUES (%s, %s)", 
                          (stub_groups[-1], stub_gid))
            stub_gid += 1

    # Generate N new different hashes and insert them into the invitation table
    h = hashlib.new('sha1')
    hashes = []
    labels = []
    
    for i in range(n_inv):
        salt = '-'.join([str(i), str(time.time())])
        seed = "ElFollon" + salt
        h.update(seed.encode())
        uid = h.hexdigest()
        hashes.append(uid)
        labels.append(i+1)
        
        if getIsStub():
            try:
                cursor.execute("INSERT INTO `invitaciones` (uid, gid, label) VALUES (%s, %s, %s)", 
                              (uid, stub_groups[i], i+1))
            except IndexError:
                cursor.execute("INSERT INTO `invitaciones` (uid, label) VALUES (%s, %s)", 
                              (uid, i+1))
        else:
            cursor.execute("INSERT INTO `invitaciones` (uid, label) VALUES (%s, %s)", 
                          (uid, i+1))
    
    conn.commit()

    # Check that the inserted number of invitations match the expectations
    cursor.execute("SELECT COUNT(*) FROM `invitaciones`")
    result = cursor.fetchall()[0][0]
    if n_inv != result:
        print(f"Generation went wrong; invitations in DB are {result} while we wanted {n_inv}. Try again.")
        sys.exit(1)

    # Get invitation hashes sorted from DB to be printed
    cursor.execute("SELECT uid, label FROM `invitaciones` ORDER BY CAST(label AS UNSIGNED)")
    hashes = []
    labels = []
    for row in cursor.fetchall():
        hashes.append(row[0])
        labels.append(row[1])

    # Generate the printable PDF with QR codes
    create_printable_pdf(hashes, labels)
