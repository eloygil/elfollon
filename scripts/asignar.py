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
    def __init__(self, n_seats, blocked_seats=0):
        self._size = n_seats
        self._blocked = blocked_seats if blocked_seats is not None else 0
        self._used = 0

    def getAvailable(self):
        # Los asientos disponibles son el total menos los usados y menos los bloqueados
        return self._size - self._used - self._blocked

    def getSize(self):
        return self._size

    def getBlocked(self):
        return self._blocked

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
    Consulta la tabla reserva_mesas para obtener el ID de mesa, el número de asientos disponibles
    y el número de asientos bloqueados.
    Retorna un diccionario donde la clave es el ID de la mesa y el valor es un objeto Table.
    """
    mapa = {}
    try:
        # Modificada la consulta para incluir la columna de asientos bloqueados
        # COALESCE maneja el caso cuando el valor es NULL (lo convierte a 0)
        cursor.execute("SELECT id, n_asientos, COALESCE(asientos_bloqueados, 0) FROM `reserva_mesas` ORDER BY id ASC")
        
        for mesa_id, asientos, bloqueados in cursor.fetchall():
            mapa[mesa_id] = Table(asientos, bloqueados)
            debug_print(f"Mesa {mesa_id}: {asientos} asientos totales, {bloqueados} bloqueados, {asientos - bloqueados} disponibles")

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
            debug_print(f"Grupo {gid} ({n_seats} personas) asignado a mesa {tid}, asientos {seat}-{seat+n_seats-1}")
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
            
            # Generar todos los asientos, incluyendo los bloqueados
            total_seats = table.getSize()
            blocked_seats = table.getBlocked()
            
            seats = []
            for s in range(1, total_seats + 1):
                # Los últimos M asientos estarán bloqueados
                if s > total_seats - blocked_seats:
                    seats.append(f"<div class='asiento asiento-bloqueado m{n}a{s}'>{s}</div>")
                else:
                    seats.append(f"<div class='asiento m{n}a{s}'>{s}</div>")
            
            # Organizar en dos filas como antes
            row1, row2 = seats[::2], seats[1::2]
            for seat1, seat2 in zip(row1, row2):
                f.write(seat1 + seat2)
            if len(row1) > len(row2):
                f.write(row1[-1])
            
            f.write("</div>")
        f.write("</div>")
        f.write("</body></html>")

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

# Mostrar resumen de mesas disponibles
debug_print("=== RESUMEN DE MESAS ===")
for tid, table in mapa.items():
    debug_print(f"Mesa {tid}: {table.getSize()} asientos totales, {table.getBlocked()} bloqueados, {table.getAvailable()} disponibles")

# Allocate seats for all groups
groups = getGroups(cursor)
debug_print(f"=== ASIGNANDO {len(groups)} GRUPOS ===")

for idx, (gid, n) in enumerate(groups):
    result = getAllocation(cursor, mapa, gid, n)
    if result:
        tid, first_seat = result
        setGroupCSS(gid, tid, first_seat, n)
    else:
        debug_print(f"No se pudo asignar el grupo {gid} ({n} personas) - Sin espacio disponible", "WARNING")

setRevision(cursor)
setHTML(mapa)

if force:
    cursor.execute("UPDATE `reserva_config` SET fecha = NULL WHERE 1")

conn.commit()
debug_print("=== PROCESO COMPLETADO ===")
