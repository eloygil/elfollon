#!/usr/bin/python3
import mysql.connector

class table(object):
    def __init__(self, n_seats):
        self._size = n_seats
        self._used = 0

    def getAvailable(self):
        return self._size - self._used

    def setReservation(self, number):
        seat = self._used + 1
        self._used += number
        return seat

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
                    creds['mysql_' + key] = line.split("=")[-1].strip().replace("'","").replace(";","")
    return creds

def getMap(n_std_tables=4, std_size=80):
    # Default map, to be adjusted if not all tables have the same size and are totally empty
    dimensions = {}
    for i in range(1, n_std_tables+1):
        dimensions[i] = table(std_size) # All standard tables have, at least, this number of (free) seats
    # If a pre-reserved, custom, table must be defined it will go here
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
    done = False
    i = 0
    while True:
        # There MUST be slots for all members, otherwise this function may turn into an infinite loop
        t = mapa[preference[i]]
        if t.getAvailable() >= n_seats:
            seat = t.setReservation(n_seats)
            # Update MySQL database
            cursor.execute("UPDATE `cena_grupos` SET mesa=%s, asiento=%s WHERE gid = %s", (preference[i], seat, gid))
            return preference[i]
        i += 1

creds = getMySQLCredentials()
conn = mysql.connector.connect(user=creds['mysql_username'], password=creds['mysql_password'], database=creds['mysql_database'])
cursor = conn.cursor()
mapa = getMap(4, 4)  # Smaller tables while testing

cursor.execute("SELECT gid, COUNT(*) as count FROM `cena_invitaciones` WHERE 1 GROUP BY gid ORDER BY count DESC")
rows = cursor.fetchall()
for row in rows:
    gid, n = row
    a = getAllocation(cursor, mapa, gid, n)
    print(gid, n)
    print(f"Sitting in table {a}")

conn.commit()
