#!/usr/bin/python3
# This script cleans the invitation table, then generates new ones
# and the associated QR codes in a printable format

import hashlib
import mysql.connector
import sys, time

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

creds = getMySQLCredentials()
conn = mysql.connector.connect(user=creds['username'], password=creds['password'], database=creds['database'])
cursor = conn.cursor()

# Check the number of invitations to be generated has been provided
try:
    n_inv = int(sys.argv[1])
except IndexError:
    print("ERROR: A number of invitations must be provided.")
    sys.exit(1)

# Clean invitation and groups database
cursor.execute("DELETE FROM `cena_invitaciones` WHERE 1")
cursor.execute("DELETE FROM `cena_grupos` WHERE 1")

# Generate N new different hashes and insert them into the invitation table
h = hashlib.new('sha1')
for i in range(n_inv):
    salt = '-'.join([str(i), str(time.time())])
    seed = "ElFollon" + salt
    h.update(seed.encode())
    cursor.execute("INSERT INTO `cena_invitaciones` (uid) VALUES (%s)", (h.hexdigest(),))
conn.commit()

# Check that the inserted number of invitations match the expectations
cursor.execute("SELECT COUNT(*) FROM `cena_invitaciones`")
result = cursor.fetchall()[0][0]
if n_inv != result:
    print("Generation went wrong; invitations in DB are {result} while we wanted {n_inv}. Try again.")
    sys.exit(1)

# Get invitation hashes sorted from DB to be printed
cursor.execute("SELECT uid FROM `cena_invitaciones` ORDER BY uid")
hashes = []
for row in cursor.fetchall():
    hashes.append(row[0])

# TO-DO generate printable tags (QR and last 8 chars)
