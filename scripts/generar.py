def draw_cutting_grid(c, cols, rows, qr_size, text_height, margin_h, margin_v, spacing_h, spacing_v, page_width, page_height):
    """Draw cutting grid lines between QR codes instead of around each one"""
    # Calculate the grid's total size
    available_width = page_width - (2 * margin_h)
    x_offset = margin_h + (available_width - (cols * qr_size + (cols - 1) * spacing_h)) / 2
    
    # Set line style for cutting guides
    c.setStrokeColor(gray)
    c.setDash([2, 2], 0)  # Dotted line pattern
    
    # Draw vertical lines between columns (not at edges)
    for col in range(1, cols):
        x = x_offset + col * qr_size + (col - 0.5) * spacing_h
        c.line(x, margin_v, x, page_height - margin_v)
    
    # Draw horizontal lines between rows (not at edges)
    y_start = page_height - margin_v
    for row in range(1, rows):
        y = y_start - row * (qr_size + text_height + spacing_v) + spacing_v/2
        c.line(x_offset, y, x_offset + cols * qr_size + (cols - 1) * spacing_h, y)#!/usr/bin/python3
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
from reportlab.lib.colors import black, gray, gray
from reportlab.graphics.shapes import Line, Drawing
from reportlab.graphics import renderPDF
import os
from PIL import Image, ImageDraw, ImageFont


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


def generate_qr_code_with_logo(uid, label):
    """
    Generate QR code with embedded logo for a given invitation hash
    Preserves the logo's original aspect ratio while scaling it to fit optimally
    """
    url = f"https://reserva.elfollon.com/?invitacion={uid}"
    qr = qrcode.QRCode(
        version=1,
        error_correction=qrcode.constants.ERROR_CORRECT_H,  # Highest error correction
        box_size=10,
        border=0,  # Reduced border to maximize the QR code area
    )
    qr.add_data(url)
    qr.make(fit=True)
    img = qr.make_image(fill_color="black", back_color="white")

    # Convert to PIL Image
    img_pil = img.get_image()
    qr_width, qr_height = img_pil.size

    # Load logo image
    try:
        logo_path = "../reserva/img/logo-asiento.png"
        logo = Image.open(logo_path)
        logo_width, logo_height = logo.size

        # Increased logo size for better readability - now 35% of QR code
        max_size = int(qr_width * 0.35)

        # Determine the scaling factor to maintain aspect ratio
        scale_factor = min(max_size / logo_width, max_size / logo_height)

        # Calculate new dimensions
        new_logo_width = int(logo_width * scale_factor)
        new_logo_height = int(logo_height * scale_factor)

        # Resize logo with preserved aspect ratio
        logo = logo.resize((new_logo_width, new_logo_height))

        # Calculate position to place logo in center
        pos_x = (qr_width - new_logo_width) // 2
        pos_y = (qr_height - new_logo_height) // 2

        # Create white background for logo (slightly larger than the logo)
        padding = 8  # Increased padding for better visibility
        white_box = Image.new('RGBA',
                              (new_logo_width + padding*2, new_logo_height + padding*2),
                              (255, 255, 255, 255))

        # Paste white background
        img_pil.paste(white_box, (pos_x - padding, pos_y - padding), white_box)

        # Paste logo onto QR code
        img_pil.paste(logo, (pos_x, pos_y), logo)
    except Exception as e:
        # If logo embedding fails, just continue with regular QR code
        print(f"Warning: Could not embed logo: {e}")

    # Create a temporary file path
    img_path = f"temp_qr_{label}.png"
    img_pil.save(img_path)
    return img_path, url


def create_printable_pdf(hashes, labels):
    """Create a printable PDF with QR codes and labels, optimizing space to fit at least 75 codes per page"""
    # A4 size in mm: 210 x 297
    page_width, page_height = A4
    
    # Minimal margins to maximize space utilization
    margin_h = 5 * mm  # Horizontal margin
    margin_v = 5 * mm  # Vertical margin
    
    # Calculate available space
    available_width = page_width - (2 * margin_h)
    available_height = page_height - (2 * margin_v)
    
    # Minimal spacing between elements
    spacing_h = 1 * mm  # Horizontal spacing between QR codes
    spacing_v = 1 * mm  # Vertical spacing between QR code cells
    text_height = 3 * mm  # Height for label text
    
    # Calculate optimal grid layout for at least 75 QR codes
    # We'll try different combinations to find the one that gives largest QR size
    target_count = 75
    best_layout = None
    best_qr_size = 0
    
    # Try different grid layouts to find the optimal one
    for cols in range(5, 11):  # Try from 5 to 10 columns
        # Calculate rows needed to fit target_count
        rows = (target_count + cols - 1) // cols  # Ceiling division
        
        # Calculate QR size for this layout
        width_per_qr = (available_width - (cols - 1) * spacing_h) / cols
        height_available = available_height - rows * (text_height + spacing_v)
        height_per_qr = height_available / rows
        
        # QR codes must be square
        qr_size = min(width_per_qr, height_per_qr)
        
        # If this layout gives bigger QR codes, save it
        if qr_size > best_qr_size:
            best_qr_size = qr_size
            best_layout = (cols, rows)
    
    # Use the best layout
    codes_per_row, codes_per_column = best_layout
    qr_size = best_qr_size
    
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
    page_num = 0
    
    # Set up grid properties
    for i, (uid, label) in enumerate(zip(hashes, labels)):
        # Calculate position for this QR code
        page_num = i // (codes_per_row * codes_per_column)
        position_on_page = i % (codes_per_row * codes_per_column)
        row = position_on_page // codes_per_row
        col = position_on_page % codes_per_row
        
        # Create a new page if needed
        if position_on_page == 0 and i > 0:
            c.showPage()
            
            # Draw the cutting grid lines for the new page (after showing the page)
            draw_cutting_grid(c, codes_per_row, codes_per_column, qr_size, text_height, 
                             margin_h, margin_v, spacing_h, spacing_v, page_width, page_height)
        
        # If this is the first element on the first page, draw the grid lines
        if i == 0:
            draw_cutting_grid(c, codes_per_row, codes_per_column, qr_size, text_height, 
                             margin_h, margin_v, spacing_h, spacing_v, page_width, page_height)
        
        # Calculate x, y coordinates with equal distribution to fill the page
        # Center the grid on the page
        x_offset = margin_h + (available_width - (codes_per_row * qr_size + (codes_per_row - 1) * spacing_h)) / 2
        x = x_offset + col * (qr_size + spacing_h)
        y = page_height - margin_v - row * (qr_size + text_height + spacing_v) - qr_size
        
        # Generate QR code with embedded logo
        img_path, _ = generate_qr_code_with_logo(uid, label)
        temp_files.append(img_path)
        
        # Draw QR code
        c.drawImage(img_path, x, y, width=qr_size, height=qr_size)
        
        # Draw label text "#XXXX"
        c.setFont(font_name, 7)
        c.drawCentredString(x + qr_size/2, y - text_height + 0.5*mm, f"#{label}")
        
        # No more individual borders around each QR
    
    # Save the PDF
    c.save()
    
    # Clean up temporary files
    for temp_file in temp_files:
        try:
            os.remove(temp_file)
        except:
            pass
    
    total_pages = page_num + 1
    total_codes = len(hashes)
    codes_per_page = codes_per_row * codes_per_column
    
    print(f"PDF generado con éxito: {pdf_filename}")
    print(f"Se han creado {total_codes} códigos QR distribuidos en {total_pages} páginas")
    print(f"Disposición optimizada: {codes_per_row} códigos por fila, {codes_per_column} filas por página")
    print(f"Total de {codes_per_page} códigos por página")
    print(f"Tamaño de cada QR: {qr_size/mm:.1f}mm x {qr_size/mm:.1f}mm")


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
        # Convertir 'biggest' a entero para evitar error en random.randint()
        biggest = min(32, int(n_inv * 0.08))
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
