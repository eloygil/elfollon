#!/usr/bin/python3
# This script cleans the invitation table, then generates new ones
# and the associated QR codes in a printable format

import hashlib
import mysql.connector
import sys
import time


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
        cursor.execute("INSERT INTO `grupos` (gid, id) VALUES (%s, %s)", (stub_groups[-1], stub_gid))
        stub_gid += 1

# Generate N new different hashes and insert them into the invitation table
h = hashlib.new('sha1')
for i in range(n_inv):
    salt = '-'.join([str(i), str(time.time())])
    seed = "ElFollon" + salt
    h.update(seed.encode())
    if getIsStub():
        try:
            cursor.execute("INSERT INTO `invitaciones` (uid, gid, label) VALUES (%s, %s, %s)", (h.hexdigest(), stub_groups[i], i+1))
        except IndexError:
            cursor.execute("INSERT INTO `invitaciones` (uid, label) VALUES (%s, %s)", (h.hexdigest(), i+1))
    else:
        cursor.execute("INSERT INTO `invitaciones` (uid, label) VALUES (%s, %s)", (h.hexdigest(), i+1))
conn.commit()

# Check that the inserted number of invitations match the expectations
cursor.execute("SELECT COUNT(*) FROM `invitaciones`")
result = cursor.fetchall()[0][0]
if n_inv != result:
    print("Generation went wrong; invitations in DB are {result} while we wanted {n_inv}. Try again.")
    sys.exit(1)

# Get invitation hashes sorted from DB to be printed
cursor.execute("SELECT uid FROM `invitaciones` ORDER BY uid")
hashes = []
for row in cursor.fetchall():
    hashes.append(row[0])

# TO-DO generate printable tags (QR and last 8 chars)
