#!/usr/bin/python3
from datetime import datetime, timedelta
from pathlib import Path
import mysql.connector
import pytz
import sys
import time
import os
import glob

DEBUG = True
SITE_PATH = '../reserva'
REVISION = datetime.now().strftime("%Y%m%d%H%M%S")

class Table:
    def __init__(self, n_seats):
        self._size = n_seats
        self._used = 0

    def getAvailable(self):
        return self._size - self._used

    def getSize(self):
        return self._size

    def setReservation(self, number):
        seat = self._used + 1
        self._used += number
        return seat

def debug_print(msg, level='DEBUG'):
    if DEBUG:
        print(f"[{level}] {msg}")

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

def getValueFromDb(query):
    cursor.execute(query)
    return cursor.fetchone()[0]

def getEventDate():
    try:
        date_db = getValueFromDb("SELECT fecha FROM `reserva_config` LIMIT 1")
        return date_db if date_db else datetime.now()
    except Exception as e:
        print(f'Event date cannot be obtained from the database: {e}')
        sys.exit(1)

def getEventLimit():
    return int(getValueFromDb("SELECT limite_min FROM `reserva_config` LIMIT 1"))

def getGreenlight():
    try:
        if sys.argv[1] == "force":
            return True
    except IndexError:
        pass
    time.sleep(1)
    dt = getEventDate() - timedelta(minutes=getEventLimit())
    now = datetime.now(tz=pytz.timezone("Europe/Madrid"))
    if now.year != dt.year or now.month != dt.month or now.day != dt.day or now.hour != dt.hour or now.minute != dt.minute:
        debug_print("Nothing to do here!")
        exit(0)

def getMap(n_std_tables=5, std_size=100):
    """
    Obtiene la configuración de mesas desde la base de datos.
    Consulta la tabla reserva_mesas para obtener el ID de mesa y el número de asientos disponibles.
    Retorna un diccionario donde la clave es el ID de la mesa y el valor es un objeto Table.
    """
    mapa = {}
    try:
        cursor.execute("SELECT id, n_asientos FROM `reserva_mesas` ORDER BY id ASC")
        for mesa_id, asientos in cursor.fetchall():
            mapa[mesa_id] = Table(asientos)

        if not mapa:
            debug_print("No se encontraron mesas en la base de datos. Usando configuración por defecto.", "WARNING")
            return {i: Table(std_size) for i in range(1, n_std_tables+1)}

        return mapa
    except Exception as e:
        debug_print(f"Error al obtener las mesas desde la base de datos: {e}", "ERROR")
        # En caso de error, devolver una configuración por defecto
        return {i: Table(std_size) for i in range(1, n_std_tables+1)}

def getAllocation(cursor, mapa, gid, n_seats):
    for tid, t in mapa.items():
        if t.getAvailable() >= n_seats:
            seat = t.setReservation(n_seats)
            cursor.execute("UPDATE `grupos` SET mesa=%s, asiento=%s WHERE gid = %s", (tid, seat, gid))
            return tid, seat

def cleanCSSDirectory():
    """
    Clean all CSS files from previous executions in the css/groups directory
    """
    css_dir = f'{SITE_PATH}/css/groups'
    
    # Ensure the directory exists
    os.makedirs(css_dir, exist_ok=True)
    
    # Remove all CSS files in the directory
    css_files = glob.glob(f'{css_dir}/*.css')
    for file in css_files:
        try:
            os.remove(file)
            debug_print(f"Removed existing CSS file: {file}")
        except Exception as e:
            debug_print(f"Error removing CSS file {file}: {e}", "ERROR")

def setGroupCSS(gid, tid, first_seat, n):
    with open(f'{SITE_PATH}/css/groups/{gid}-{REVISION}.css', 'w') as f:
        for i in range(n):
            seat = first_seat + i
            f.write(f".m{tid}a{seat} {{ background-color: red; color: white; }}\n")

def setRevision(cursor):
    cursor.execute("UPDATE `reserva_config` SET revision = %s WHERE 1", (REVISION,))

def setHTML(mapa):
    with open(f'{SITE_PATH}/mapa.html', 'w') as f:
        f.write("<html><head><title>Mapa de asignaciones</title></head><body>")
        f.write("<div class='mesa-container'>")

        for n, table in mapa.items():
            f.write(f"<div class='mesa' id='mesa{n}'>")
            f.write(f"<div class='mesa-title'>Mesa {n}</div>")
            seats = [f"<div class='asiento m{n}a{s}'>{s}</div>" for s in range(1, table.getSize()+1)]
            row1, row2 = seats[::2], seats[1::2]
            for seat1, seat2 in zip(row1, row2):
                f.write(seat1 + seat2)
            if len(row1) > len(row2):
                f.write(row1[-1])
            f.write("</div>")

        f.write("</div></body></html>")

def getGroups(cursor):
    # Only groups with 2 or more members are considered as valid
    # Sorted (desc) by member number but even before odd-member groups
    cursor.execute("DELETE FROM `grupos` WHERE gid IN (SELECT gid FROM `invitaciones` GROUP BY gid HAVING count(*) = 1)")
    cursor.execute("SELECT gid, COUNT(*) as count FROM `invitaciones` WHERE gid IS NOT NULL GROUP BY gid HAVING count > 1 ORDER BY count DESC")
    groups = [[], []]
    for row in cursor.fetchall():
        groups[row[1] % 2].append(row)
    return groups[0] + groups[1]

creds = getMySQLCredentials()
conn = mysql.connector.connect(user=creds['username'], password=creds['password'], database=creds['database'])
cursor = conn.cursor()
force = getGreenlight()
mapa = getMap(3, 40)

# Clean the CSS directory before generating new files
cleanCSSDirectory()

# Allocate seats for all groups
groups = getGroups(cursor)
for idx, (gid, n) in enumerate(groups):
    tid, first_seat = getAllocation(cursor, mapa, gid, n)
    setGroupCSS(gid, tid, first_seat, n)

setRevision(cursor)
setHTML(mapa)

if force:
    cursor.execute("UPDATE `reserva_config` SET fecha = NULL WHERE 1")

conn.commit()
