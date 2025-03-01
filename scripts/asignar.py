#!/usr/bin/python3
from datetime import datetime, timedelta
from pathlib import Path
import mysql.connector
import pytz
import sys
import time

DEBUG = True
SITE_PATH = '../reserva'

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

def getMap(n_std_tables=4, std_size=80):
    return {i: Table(std_size) for i in range(1, n_std_tables + 1)}

def getAllocation(cursor, mapa, gid, n_seats):
    for tid, t in mapa.items():
        if t.getAvailable() >= n_seats:
            seat = t.setReservation(n_seats)
            cursor.execute("UPDATE `grupos` SET mesa=%s, asiento=%s WHERE gid = %s", (tid, seat, gid))
            return tid, seat

def setGroupCSS(gid, tid, first_seat, n):
    with open(f'{SITE_PATH}/css/groups/{gid}.css', 'w') as f:
        for i in range(n):
            seat = first_seat + i
            f.write(f".m{tid}a{seat} {{ background-color: red; color: white; }}\n")

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
mapa = getMap(4, 80)

# Allocate seats for all groups
groups = getGroups(cursor)
for idx, (gid, n) in enumerate(groups):
    tid, first_seat = getAllocation(cursor, mapa, gid, n)
    setGroupCSS(gid, tid, first_seat, n)

setHTML(mapa)

if force:
    cursor.execute("UPDATE `reserva_config` SET fecha = NULL WHERE 1")

conn.commit()
