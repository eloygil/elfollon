# El Follón Reservation System

A web-based reservation and seating assignment system for "El Follón" events, primarily designed for managing group seating arrangements at gatherings.

## System Overview

This system allows attendees to:
- Create a reservation using a unique QR code
- Form or join groups with other attendees
- View assigned seating when groups are finalized
- Share group links with others via direct links or WhatsApp

The system also features an automated seat assignment system that distributes groups across tables and generates visual seating charts.

## Project Structure

### Apache Configuration Files

Located in `/apache/`:
- `020-elfollon.com.conf` - Main domain HTTP configuration with redirect to HTTPS
- `020-elfollon.com-le-ssl.conf` - HTTPS configuration for main domain
- `021-reserva.elfollon.com.conf` - HTTP configuration for reservation subdomain
- `021-reserva.elfollon.com-le-ssl.conf` - HTTPS configuration for reservation subdomain

### Cron Jobs

Located in `/cron/`:
- `elfollon.crontab` - Scheduled task to run the seating assignment script

### Reservation System

Located in `/reserva/`:
- `index.php` - Main application interface for the reservation system
- `helpers.php` - Utility functions for the reservation system
- `settings.php` - Configuration and settings for the reservation system

### Scripts

Located in `/scripts/`:
- `asignar.py` - Python script that allocates groups to tables and creates seating assignments
- `generar.py` - Script to generate invitation hashes, QR codes, and a printable PDF of invitations
- `generar.py-backup` - Backup version of the invitation generation script

### Main Website

Located in `/web/`:
- `index.html` - Simple redirect to Instagram page

## Key Components

### Invitation System

- Unique SHA-1 hashes are generated for each invitation
- QR codes link to the reservation system with the invitation code embedded
- Users scan their QR code to identify themselves in the system

### Group Management

- Users can create new groups or join existing ones via shared links
- Multiple attendees can coordinate to sit together
- Group changes are locked at a configurable time before the event (default: 60 minutes)

### Seating Assignment

- The `asignar.py` script automatically runs at the specified lock time
- Groups are sorted by size and assigned to tables
- The system generates:
  - Table assignments with specific seat numbers
  - Visual seating chart with color coding for assigned seats
  - CSS files for highlighting assigned seats on the map

### Database Structure

The system uses MySQL/MariaDB with the following key tables:
- `invitaciones` - Stores invitation codes, group assignments, and labels
- `grupos` - Stores group information including table and seat assignments
- `reserva_config` - Configuration settings for the event (date, time, location)

## Configuration

Event settings are controlled via the `reserva_config` table including:
- Event name
- Date and time
- Location
- Time before event when reservations lock

## Installation

1. Set up Apache with the provided configuration files
2. Import database schema (not included in this repository)
3. Configure the PHP MySQL connection settings in a separate file
4. Ensure Python dependencies are installed for the scripts:
   - mysql-connector
   - pytz
   - qrcode
   - reportlab

## Usage

### Generating Invitations

```bash
cd scripts
python3 generar.py [number_of_invitations]
```

This will generate a PDF with QR codes that can be printed and distributed.

### Running the Seat Assignment Manually

```bash
cd scripts
python3 asignar.py force
```

The `force` parameter runs the assignment immediately, otherwise it only runs at the configured lock time.

## Security Notes

- The system uses HTTPS with Let's Encrypt certificates
- Database credentials are stored outside the web root
- Input sanitization is implemented for user inputs
- Session management is used for user authentication

## License

This project is proprietary software maintained by Peña "El Follón" staff.

## Contact

For inquiries, contact the administrator at hello@eloygil.com
