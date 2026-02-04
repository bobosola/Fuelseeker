# Fuelseeker.net - Installation & Setup Guide

## Overview

This application uses a local SQLite database to cache fuel station data for fast queries. The database is updated via a cron job that fetches data from the gov.uk Fuel Finder API.

## Initial Setup

### 1. Install Files

Upload all files to your web server (e.g., `/var/www/fuel/` or `public_html/`).

**IMPORTANT: API Credentials**
This application requires API credentials that are NOT included in the repository for security.

1. Copy `.env.example` to `.env`:
   ```bash
   cp .env.example .env
   ```

2. Edit `.env` and add your API credentials:
   ```bash
   # Fuel Finder API (from https://www.fuel-finder.service.gov.uk)
   FUEL_CLIENT_ID=your_client_id_here
   FUEL_CLIENT_SECRET=your_client_secret_here
   
   # Ordnance Survey API (from https://osdatahub.os.uk/)
   OS_API_KEY=your_api_key_here
   OS_API_SECRET=your_api_secret_here
   ```

3. Ensure `.env` is NOT committed to Git:
   ```bash
   git status
   # .env should NOT appear (it's in .gitignore)
   ```

### 2. Create Data Directory

The application needs a `data` directory to store the SQLite database:

```bash
cd /path/to/fuel
mkdir data
chmod 755 data
```

### 3. Initial Database Population

Run the update script once to download all fuel station data:

```bash
php /path/to/fuel/scripts/update_data.php
```

This will:
- Download ~7,000 fuel stations from the gov.uk API
- Download current fuel prices
- Create the SQLite database at `data/fuel_data.db`
- Take approximately 2-3 minutes

Expected output:
```
Starting fuel data update...
Getting OAuth token...
Got token
Downloading stations...
  Batch 1: 500 stations (total: 500)
  Batch 2: 500 stations (total: 1000)
  ...
  Batch 14: 167 stations (total: 7167)
Downloaded 7167 stations
Downloading fuel prices...
  Prices batch 1: 500 stations
  ...
Updating database...
  Processed 500 stations...
  Processed 1000 stations...
  ...
Database update complete. Total stations: 7167
Update completed successfully
```

### 4. Verify Installation

Check the database status:

```bash
curl https://your-domain.com/scripts/local_api.php?action=status
```

Expected response:
```json
{
  "total_stations": 7167,
  "stations_with_prices": 7167,
  "last_update": "2026-02-04 12:00:00",
  "status": "ok"
}
```

Access the website in your browser:
```
https://your-domain.com/
```

## Setting Up Automatic Updates (Cron Job)

The fuel prices change throughout the day. Set up a cron job to update the database twice daily.

### Option 1: Using Crontab (Recommended)

Edit your user's crontab:

```bash
crontab -e
```

Add this line to update at 6 AM and 6 PM daily:

```
0 6,18 * * * /usr/bin/php /path/to/fuel/scripts/update_data.php >> /path/to/fuel/data/cron.log 2>&1
```

Replace `/path/to/fuel` with your actual installation path.

**Examples:**

- cPanel/shared hosting:
```
0 6,18 * * * /usr/bin/php /home/username/public_html/fuel/scripts/update_data.php >> /home/username/public_html/fuel/data/cron.log 2>&1
```

- VPS/dedicated server:
```
0 6,18 * * * /usr/bin/php /var/www/fuel/scripts/update_data.php >> /var/www/fuel/data/cron.log 2>&1
```

### Option 2: Using Systemd Timer (Linux VPS)

Create a systemd service file:

```bash
sudo nano /etc/systemd/system/fuel-update.service
```

Content:
```ini
[Unit]
Description=Fuel Finder Database Update
After=network.target

[Service]
Type=oneshot
ExecStart=/usr/bin/php /path/to/fuel/scripts/update_data.php
User=www-data
```

Create a timer file:

```bash
sudo nano /etc/systemd/system/fuel-update.timer
```

Content:
```ini
[Unit]
Description=Run Fuel Finder update twice daily

[Timer]
OnCalendar=*-*-* 06,18:00:00
Persistent=true

[Install]
WantedBy=timers.target
```

Enable and start the timer:

```bash
sudo systemctl daemon-reload
sudo systemctl enable fuel-update.timer
sudo systemctl start fuel-update.timer
```

Check status:
```bash
sudo systemctl list-timers --all
```

### Option 3: cPanel Cron Jobs

1. Log in to cPanel
2. Go to **Cron Jobs**
3. Under **Add New Cron Job**, set:
   - **Common Settings**: Select "Twice a day (0 */12 * * *)" OR enter custom: `0 6,18 * * *`
   - **Command**: `/usr/bin/php /home/username/public_html/fuel/scripts/update_data.php >> /home/username/public_html/fuel/data/cron.log 2>&1`
4. Click **Add New Cron Job**

## Monitoring & Troubleshooting

### Check Last Update Time

```bash
curl https://your-domain.com/scripts/local_api.php?action=status
```

### View Update Logs

```bash
# View cron log
tail -f /path/to/fuel/data/cron.log

# View error log (if any)
tail /path/to/fuel/data/update_error.log
```

### Manual Update

If you need to force an update:

```bash
php /path/to/fuel/scripts/update_data.php
```

### Common Issues

#### 1. "Database not initialized" Error

**Cause**: The update script hasn't been run yet.

**Fix**: Run the update script manually:
```bash
php /path/to/fuel/scripts/update_data.php
```

#### 2. "Failed to get OAuth token"

**Cause**: API credentials issue or network problem.

**Fix**: Check your internet connection and verify the API credentials in `scripts/update_data.php`.

#### 3. Permission Denied Errors

**Fix**: Ensure the web server can write to the `data` directory:
```bash
chown -R www-data:www-data /path/to/fuel/data
chmod -R 755 /path/to/fuel/data
```

On shared hosting, you may need to use:
```bash
chmod -R 777 /path/to/fuel/data
```

#### 4. Cron Job Not Running

**Check**: Verify the PHP path:
```bash
which php
```

**Test**: Run the command manually to see any errors:
```bash
/usr/bin/php /path/to/fuel/scripts/update_data.php
```

## File Permissions

Ensure these permissions are set:

```
fuel/
├── data/                    (755 - writable by web server)
│   ├── fuel_data.db        (644 or 666)
│   ├── update.lock         (auto-created)
│   ├── cron.log            (auto-created)
│   └── update_error.log    (auto-created)
├── scripts/
│   ├── update_data.php     (644)
│   ├── local_api.php       (644)
│   ├── token.php           (644)
│   ├── os_token.php        (644)
│   └── api_proxy.php       (644)
├── css/                    (755)
├── js/                     (755)
├── index.html              (644)
└── map.html                (644)
```

## Security Notes

1. **Protect the data directory**: Add to `.htaccess` or nginx config:
   ```apache
   # Deny access to data directory
   <Directory "/path/to/fuel/data">
       Order deny,allow
       Deny from all
   </Directory>
   ```

2. **API Credentials**: The OAuth credentials are stored server-side only in PHP files.

3. **HTTPS**: Always use HTTPS in production to protect user location data.

## Updating the Application

When updating to a new version:

1. Back up your database:
   ```bash
   cp /path/to/fuel/data/fuel_data.db /path/to/fuel/data/fuel_data.db.backup
   ```

2. Upload new files (except `data/` directory)

3. The database will remain intact and continue working.

## Support

For issues with:
- **Fuel data**: Contact gov.uk Fuel Finder team
- **This application**: Check error logs in `data/update_error.log`

---

**Last Updated**: February 2026
