#!/usr/bin/python3
from datetime import datetime, timedelta
import mysql.connector
import pytz
import sys, time

DEBUG = True
SITE_PATH = '../reserva'

class table(object):
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

def getEventDate(cfg=SITE_PATH + '/getSettings.php'):
    # The purpose of this function is reading the event date from the PHP config file
    date = []
    f = open(cfg, 'r')
    for line in f.readlines():
        if '$date =' in line:
            for symbol in ["$date = ", "]", "[", ";", "'", " "]:
                line = line.replace(symbol, "")
            date = line.strip().split(',')
            return datetime.strptime('-'.join([date[2], date[1], date[0]]) + ' ' + ':'.join([date[3], date[4]]), "%Y-%m-%d %H:%M")
    print(f'Event date cannot be parsed from {cfg}')
    sys.exit(1)

def getEventLimit(cfg=SITE_PATH + '/getSettings.php'):
    # The purpose of this function is reading the event time limit from the PHP config file
    f = open(cfg, 'r')
    for line in f.readlines():
        if '$limitMinutes = ' in line:
            return int(line.replace('$limitMinutes = ','').replace(';','').strip())

def getGreenlight():
    # It will exit in most cases, but let the execution happen if it is time to assign seats
    dt = getEventDate() - timedelta(minutes=getEventLimit())  # The calculation happens some minutes before the event
    now = datetime.now(tz=pytz.timezone("Europe/Madrid"))     # Adjusted to Spanish peninsular time
    if now.year != dt.year or now.month != dt.month or now.day != dt.day or now.hour != dt.hour or now.minute != dt.minute:
        debug_print(f"Year: {now.year} vs {dt.year}")
        debug_print(f"Month: {now.month} vs {dt.month}")
        debug_print(f"Day: {now.day} vs {dt.day}")
        debug_print(f"Hour: {now.hour} vs {dt.hour}")
        debug_print(f"Minute: {now.minute} vs {dt.minute}")
        debug_print("Nothing to do here!")
        exit(0)
    time.sleep(2)

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
                    creds[key] = line.split("=")[-1].strip().replace("'","").replace(";","")
    return creds

def getMap(n_std_tables=4, std_size=80):
    # Default map, to be adjusted if not all tables have the same size and are totally empty
    dimensions = {}
    for i in range(1, n_std_tables+1):
        dimensions[i] = table(std_size) # All standard tables have, at least, this number of (free) seats
    # If a pre-reserved, custom, table must be defined it will go here #
    return dimensions

def getPreference(mapa, n_seats):
    # We assume we have 50-50 odd and even number groups
    # We prioritise grouping them as it is better for having people from your group closer
    # e.g. a group of 4 people next to a group of 6 people will be facing someone from their group
    #      but if you sit a group of 3 people in between the third group will have 2 people sitting
    #      next to someone from other group
    # Best effort: We will have at least two dedicated odd/even tables, then the rest.
    ids = set(mapa.keys())
    preference = []
    fav = 1 if n_seats % 2 == 1 else 2
    preference.append(fav)
    ids.remove(fav)
    for i in ids:
        preference.append(i)
    return preference

def getAllocation(cursor, mapa, gid, n_seats):
    # Assigns the given number of seats to the given group, by preference
    preference = getPreference(mapa, n_seats)
    for i in range(len(preference)):
        # We assume there MUST be slots for all members, if not they'll stay unassigned
        t = mapa[preference[i]]
        if t.getAvailable() >= n_seats:
            seat = t.setReservation(n_seats)
            # Update MySQL database
            cursor.execute("UPDATE `grupos` SET mesa=%s, asiento=%s WHERE gid = %s", (preference[i], seat, gid))
            # Generate CSS for group
            setCSS(gid, preference[i], seat, n_seats)
            return preference[i]

def setCSS(gid, tid, first_seat, n_seats):
    # Generates the CSS for a group
    f = open(f'{SITE_PATH}/css/{gid}.css', 'w')
    for seat in range(first_seat, first_seat + n_seats):
        f.write("td.m" + str(tid) + "a" + str(seat) + " { color: white; background-color: red; }\n")
    f.close()

def setHTML(mapa):
    from tabulate import tabulate
    from bs4 import BeautifulSoup
    import re
    f = open(f'{SITE_PATH}/mapa.html', 'w')
    f.write("<table>")
    for n in range(1, len(mapa) + 1):
        f.write("<tr><td>Mesa " + str(n) + "</td><td>")
        mesa = tabulate([list(range(1, mapa[n].getSize()+1, 2)), list(range(2, mapa[n].getSize()+1, 2))], tablefmt='html')
        soup = BeautifulSoup(mesa, "html.parser")
        for cell in soup.find_all("td"):
            seat = re.sub("[^0-9]", "", str(cell.string))  # Bugfix
            cell['class'] = "m" + str(n) + "a" + seat
            cell['style'] = "border: 1px solid black;"
        f.write(str(soup))
        f.write("</tr>")
    f.write("</table>")
    f.close()

getGreenlight()
creds = getMySQLCredentials()
conn = mysql.connector.connect(user=creds['username'], password=creds['password'], database=creds['database'])
cursor = conn.cursor()
mapa = getMap(4, 80)

cursor.execute("SELECT gid, COUNT(*) as count FROM `invitaciones` WHERE gid IS NOT NULL GROUP BY gid HAVING count > 1 ORDER BY count DESC")
rows = cursor.fetchall()
for gid, n in rows:
    a = getAllocation(cursor, mapa, gid, n)
    print(f"Group #{n} ({gid}) has been assigned to table {a}")

# Generate HTML map of tables
setHTML(mapa)

conn.commit()
