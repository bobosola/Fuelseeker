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

## Database Update Options

The fuel prices change throughout the day. The database should be updated 3 times daily (06:00, 13:00 & 18:00).

**⚠️ IMPORTANT**: The gov.uk Fuel Finder API is only accessible from UK IP addresses. If your server is outside the UK (e.g., Germany, US, etc.), the API will block requests with HTTP 403.

### Option 1: Manual Copy from Local Machine (Simplest)

If you have a UK-based computer (home/office), run the update locally and copy the database to your server:

**On your UK computer:**
```bash
cd /path/to/fuel
php scripts/update_data.php
```

**Then copy to your server:**
```bash
scp data/fuel_data.db user@fuelseeker.net:/var/www/fuelseeker.net/data/
```

You can set this up as a scheduled task on your UK computer (cron on Mac/Linux, Task Scheduler on Windows).

### Option 2: Using NordVPN CLI on Your Server (Recommended for VPS)

If you have a NordVPN subscription, you can install their CLI client on your Debian server and connect to a UK server before running updates.

**Install NordVPN:**
```bash
# Download and install NordVPN
sh <(curl -sSf https://downloads.nordcdn.com/apps/linux/install.sh)

# Get a login token as per https://support.nordvpn.com/hc/en-us/articles/20286980309265-How-to-log-in-to-NordVPN-without-a-GUI-using-a-token

# Log in
nordvpn login --token <your token here>
```
The response should be:
```
Welcome to NordVPN! You can now connect to the VPN by using 'nordvpn connect'.

NOTE: By default, all users who are members of the 'nordvpn' group have permission to control the NordVPN application.
To limit access exclusively to the root user, remove all users from the 'nordvpn' group.
```



**Create an update script with VPN:**
```bash
sudo nano /usr/local/bin/fuel-update-with-vpn.sh
```

**Content:**
```bash
#!/bin/bash
# Update fuel database with UK VPN connection

# Connect to UK server
nordvpn connect United_Kingdom

# Wait for connection
sleep 10

# Run update
/usr/bin/php /path/to/update_data.php >> /var/www/fuelseeker.net/data/update.log 2>&1

# Disconnect VPN
nordvpn disconnect
```

**Make executable and set up cron:**
```bash
chmod +x /usr/local/bin/fuel-update-with-vpn.sh

# Add to crontab (twice daily)
crontab -e
# Add: 0 6,18 * * * /usr/local/bin/fuel-update-with-vpn.sh
```

**Alternative - using NordVPN's SOCKS5 proxy (no VPN connection needed):**
If NordVPN supports SOCKS5 proxy, you can configure curl to use it without connecting the VPN:
```bash
# Add to update_data.php curl options:
# curl_setopt($ch, CURLOPT_PROXY, 'uk-proxy.nordvpn.com:1080');
# curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5);
```

### Option 3: Using a UK Proxy Server

If you have access to a UK proxy server, you can route API requests through it:

1. Edit `scripts/update_data.php` and `scripts/api_proxy.php`
2. Add proxy options to curl calls:
   ```php
   curl_setopt($ch, CURLOPT_PROXY, 'your-uk-proxy.com:8080');
   curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP);
   ```

### Option 4: UK-Based VPS for Updates Only

Run a small, cheap UK-based VPS (DigitalOcean London, AWS London, etc.) solely for running the update script, then sync the database to your main server:

**On UK VPS (cron job):**
```bash
0 6,18 * * * /usr/bin/php /path/to/update_data.php && scp /path/to/data/fuel_data.db main-server:/var/www/fuelseeker.net/data/
```

---

## Setting Up Automatic Updates (Cron Job)

### Using Crontab

Edit your user's crontab:

```bash
crontab -e
```

Add this line to update at 6 AM and 6 PM daily:

```
0 6,18 * * * /usr/bin/php /path/to/fuel/scripts/update_data.php >> /path/to/fuel/data/update.log 2>&1
```

Replace `/path/to/fuel` with your actual installation path.

**Examples:**

- cPanel/shared hosting:
```
0 6,18 * * * /usr/bin/php /home/username/public_html/fuel/scripts/update_data.php >> /home/username/public_html/fuel/data/update.log 2>&1
```

- VPS/dedicated server:
```
0 6,18 * * * /usr/bin/php /var/www/fuel/scripts/update_data.php >> /var/www/fuel/data/update.log 2>&1
```

### Option 2: Using Systemd Timer (Linux VPS - Non-UK Servers)

For servers outside the UK, use a systemd service with the VPN wrapper script and safe update mechanism.

#### Prerequisites

**1. Install the safe update script** (`/usr/local/bin/update_data.php`):

This is the database update script with automatic backup and restore:

```bash
# Copy the safe update script to your server
scp not_for_website/update_data_safe.php user@fuelseeker.net:/tmp/
ssh user@fuelseeker.net "sudo mv /tmp/update_data_safe.php /usr/local/bin/update_data.php"
```

**Features of the safe update script:**
- Creates a backup before any changes
- Downloads all data BEFORE touching the database
- Automatic rollback if update fails
- Restores backup on any error
- Your site stays online even if update fails

**2. Create the wrapper script** (`/usr/local/bin/fuel-update-with-vpn.sh`):

```bash
#!/bin/bash
# Update fuel database with UK VPN connection (Safe Version)

LOG_FILE="/var/www/fuelseeker.net/data/update.log"
UPDATE_SCRIPT="/usr/loca/bin/update_data_safe.php"

log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $1" | tee -a "$LOG_FILE"
}

log "=== Starting Fuel Update (Safe Mode) ==="

# Check if update script exists
if [ ! -f "$UPDATE_SCRIPT" ]; then
    log "ERROR: Update script not found at $UPDATE_SCRIPT"
    exit 1
fi

# Remove stale lock file if it exists
LOCK_FILE="/var/www/fuelseeker.net/data/update.lock"
if [ -f "$LOCK_FILE" ]; then
    log "Lock file exists - removing to allow this update"
    rm -f "$LOCK_FILE"
fi

# Check if we can reach the API without VPN
log "Testing API access without VPN..."
API_TEST=$(curl -s -o /dev/null -w "%{http_code}" --max-time 10 \
    "https://www.fuel-finder.service.gov.uk/api/v1/pfs?batch-number=1" 2>/dev/null)
log "API test HTTP code: $API_TEST"

if [ "$API_TEST" = "200" ]; then
    log "API accessible without VPN"
    VPN_NEEDED=false
else
    log "API not accessible without VPN (HTTP $API_TEST) - need VPN"
    VPN_NEEDED=true
fi

# Only use VPN if needed
if [ "$VPN_NEEDED" = true ]; then
    log "Whitelisting SSH port to prevent connection drop..."
    nordvpn whitelist add port 22
    
    log "Connecting to NordVPN UK server..."
    nordvpn connect United_Kingdom
    
    if [ $? -ne 0 ]; then
        log "ERROR: Failed to connect to VPN"
        exit 1
    fi
    
    log "Waiting for VPN connection..."
    sleep 10
    
    if ! nordvpn status | grep -q "Connected"; then
        log "ERROR: VPN not connected"
        exit 1
    fi
    
    log "VPN connected successfully"
fi

# Run the update (safe version with backup)
log "Running fuel data update (safe mode)..."
UPDATE_OUTPUT=$(php "$UPDATE_SCRIPT" 2>&1)
UPDATE_STATUS=$?

echo "$UPDATE_OUTPUT" | while read line; do
    log "  $line"
done

if [ $UPDATE_STATUS -eq 0 ]; then
    log "Update completed successfully"
else
    log "ERROR: Update failed with status $UPDATE_STATUS"
    log "Database backup was restored automatically if needed"
fi

# Disconnect VPN if we connected
if [ "$VPN_NEEDED" = true ]; then
    log "Disconnecting VPN..."
    echo "n" | nordvpn disconnect > /dev/null 2>&1
    log "Removing SSH whitelist..."
    nordvpn whitelist remove port 22 > /dev/null 2>&1
fi

log "=== Update Process Complete ==="
exit $UPDATE_STATUS
```

Make it executable:
```bash
sudo chmod +x /usr/local/bin/fuel-update-with-vpn.sh
```

**2. Create the service file** (`/etc/systemd/system/fuelseeker-vpn-update.service`):

```ini
[Unit]
Description=Fuel Finder Database Update with VPN
After=network-online.target
Wants=network-online.target

[Service]
Type=oneshot
ExecStart=/usr/local/bin/fuel-update-with-vpn.sh
StandardOutput=append:/var/www/fuelseeker.net/data/update.log
StandardError=append:/var/www/fuelseeker.net/data/update.log

# Note: This runs as root because nordvpn requires root privileges
User=root
```

**3. Create the timer file** (`/etc/systemd/system/fuelseeker-vpn-update.timer`):

```ini
[Unit]
Description=Run Fuel Finder VPN Update three times Daily

[Timer]
# Run at 06:00, 13:00 and 18:00 every day
OnCalendar=*-*-* 06,13,18:00:00

# Add a random delay up to 5 minutes to avoid server overload
RandomizedDelaySec=5m

# Ensure it runs if system was off at scheduled time
Persistent=true

[Install]
WantedBy=timers.target
```

**4. Install and enable:**

```bash
sudo systemctl daemon-reload
sudo systemctl enable fuelseeker-vpn-update.timer
sudo systemctl start fuelseeker-vpn-update.timer
```

**5. Check status:**

```bash
sudo systemctl list-timers fuelseeker-vpn-update.timer
sudo journalctl -u fuelseeker-vpn-update.service --since "1 hour ago"
```

---

### Option 3: Using Systemd Timer (Linux VPS - UK Servers)

For UK-based servers (no VPN needed), create a simpler systemd service:

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
Description=Run Fuel Finder update three times daily

[Timer]
# Run at 06:00, 13:00 and 18:00 every day
OnCalendar=*-*-* 06,13,18:00:00
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

### Option 4: cPanel Cron Jobs

1. Log in to cPanel
2. Go to **Cron Jobs**
3. Under **Add New Cron Job**, set:
   - **Common Settings**: Select "Twice a day (0 */12 * * *)" OR enter custom: `0 6,18 * * *`
   - **Command**: `/usr/bin/php /home/username/public_html/fuel/scripts/update_data.php >> /home/username/public_html/fuel/data/update.log 2>&1`
4. Click **Add New Cron Job**

## Monitoring & Troubleshooting

### Check Last Update Time

```bash
curl https://your-domain.com/scripts/local_api.php?action=status
```

### View Update Logs

```bash
# View cron log
tail -f /path/to/fuel/data/update.log

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

#### 2. "Failed to get OAuth token" / HTTP 403 from CloudFront

**Cause**: Your server is outside the UK and the gov.uk API is geoblocked.

**Fix**: See [Database Update Options](#database-update-options) section above. Solutions include:
- Using NordVPN CLI on your server
- Copying database from a UK-based computer
- Using a UK proxy server

**To verify**: Check if you can reach the API:
```bash
curl -I https://www.fuel-finder.service.gov.uk/
```
If you see `HTTP 403` with `server: CloudFront`, your IP is blocked.

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
│   ├── update.log            (auto-created)
│   └── update_error.log    (auto-created)
├── scripts/
│   ├── update_data.php     (644)
│   ├── local_api.php       (644)
│   ├── token.php           (644)
│   ├── os_token.php        (644)
│   └── api_proxy.php       (644)
│   └── .env      (644)
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

4. **Caddy Web Server**: Unlike Apache/nginx, Caddy does NOT hide dotfiles by default. This could expose your `.env` file containing API credentials. Add this to your Caddyfile:
   ```caddy
   file_server {
       hide .*
   }
   ```
   Or specifically hide sensitive files:
   ```caddy
   file_server {
       hide .env .git .gitignore
   }
   ```

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

## Changelog

- **Feb 2026**: Added geolocation workaround documentation for non-UK servers
- **Feb 2026**: Updated config.php to support .env file in scripts/ directory
- **Feb 2026**: Fixed domain whitelisting for os_token.php and token.php
