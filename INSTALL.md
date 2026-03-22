# Fuelseeker.net - Installation & Setup Guide

## Overview

This application uses a local SQLite database to cache fuel station data for fast queries. The database is updated via a **systemd timer** that fetches data from the gov.uk Fuel Finder API.

## Initial Setup

### 1. Install Files

Upload all files to your web server (e.g., `/var/www/fuelseeker/` ).

**IMPORTANT: API Credentials**
This application requires API credentials that are NOT included in the repository for security.

1. Copy `.secrets.example` to `.secrets`:
   ```bash
   cp scripts/.secrets.example scripts/.secrets
   ```

2. Edit `scripts/.secrets` and add your API credentials:
   ```bash
   # Fuel Finder API (from https://www.fuel-finder.service.gov.uk)
   FUEL_CLIENT_ID=your_client_id_here
   FUEL_CLIENT_SECRET=your_client_secret_here
   
   # Ordnance Survey API (from https://osdatahub.os.uk/)
   OS_API_KEY=your_api_key_here
   OS_API_SECRET=your_api_secret_here
   ```

3. Ensure `.secrets` is NOT committed to Git:
   ```bash
   git status
   # .secrets should NOT appear (it's in `.gitignore`)
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

**Non-UK servers (using VPN):**
```bash
sudo /usr/local/bin/fuel-update-with-vpn.sh
```

**UK servers:**
```bash
php /usr/local/bin/update_data_streaming.php
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

The fuel prices change throughout the day. The database is updated 3x daily at 06:00, 14:00, and 22:00 for better price accuracy.

**⚠️ IMPORTANT**: The gov.uk Fuel Finder API is only accessible from UK IP addresses. If your server is outside the UK (e.g., Germany, US, etc.), the API will block requests with HTTP 403.

### Recommended: UK PC Deployment (New)

The recommended approach is to use a UK-based PC to download the data and deploy it to your web server via HTTPS. This avoids VPN complications entirely.

See [DEPLOYMENT_PLAN.md](DEPLOYMENT_PLAN.md) and `data_retrieval_server/README.md` for setup instructions.

**Quick Setup:**
1. Copy `data_retrieval_server/` to a UK PC
2. Configure `data_retrieval_server/.secrets` with API credentials
3. Run `php deploy_to_remote_server.php`
4. Set up cron/systemd timer for automatic updates

---

### Legacy Options (VPN-based - Not Recommended)

The following options use VPN on the web server itself. These are kept for reference but the UK PC method above is preferred.

### Option 1: Manual Copy from Local Machine (Simplest)

If you have a UK-based computer (home/office), run the update locally and copy the database to your server:

**On your UK computer:**
```bash
cd /path/to/fuel
php not_for_website/update_data_streaming.php
```

**Then copy to your server:**
```bash
scp data/fuel_data.db.v1 user@fuelseeker.net:/var/www/fuelseeker.net/data/
# Then activate via symlink on server - see activation instructions
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

# Set paths for the update script (required when script is in /usr/local/bin/)
export FUELSEEKER_SCRIPT_DIR=/var/www/fuelseeker.net/scripts
export FUELSEEKER_DATA_DIR=/var/www/fuelseeker.net/data

# Connect to UK server
nordvpn connect United_Kingdom

# Wait for connection
sleep 10

# Run update
/usr/bin/php /usr/local/bin/update_data_streaming.php >> /var/www/fuelseeker.net/data/update.log 2>&1

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

1. Edit `not_for_website/update_data_streaming.php` and `scripts/api_proxy.php`
2. Add proxy options to curl calls:
   ```php
   curl_setopt($ch, CURLOPT_PROXY, 'your-uk-proxy.com:8080');
   curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP);
   ```

### Option 4: UK-Based VPS for Updates Only

Run a small, cheap UK-based VPS (DigitalOcean London, AWS London, etc.) solely for running the update script, then sync the database to your main server:

**On UK VPS (systemd timer or cron):**
```bash
# Using systemd timer (recommended):
# Copy update_data_streaming.php to /usr/local/bin/ and use systemd service

# Or using cron (UK servers only):
0 2 * * * /usr/bin/php /usr/local/bin/update_data_streaming.php && scp /var/www/fuelseeker.net/data/fuel_data.db.v1 main-server:/var/www/fuelseeker.net/data/
# Then activate on main server via symlink swap
```

---

## Setting Up Automatic Updates

### Using Crontab

Edit your user's crontab:

```bash
crontab -e
```

**⚠️ For non-UK servers: Do NOT use cron.** Use the systemd timer method below which handles VPN connection automatically.

**For UK servers** (no VPN needed):
```
0 6,14,22 * * * /usr/bin/php /usr/local/bin/update_data_streaming.php >> /var/www/fuelseeker.net/data/update.log 2>&1
```

### Option 2: Using Systemd Timer (Linux VPS - Non-UK Servers) - Daily at 02:00

For servers outside the UK, use a systemd service with the VPN wrapper script and safe update mechanism.

#### Prerequisites

**1. Install the safe update script** (`/usr/local/bin/update_data.php`):

This is the streaming database update script with low memory usage:

```bash
# Copy the update script and required schema file to your server
scp not_for_website/update_data_streaming.php user@fuelseeker.net:/tmp/
scp not_for_website/schema.sql user@fuelseeker.net:/tmp/
scp not_for_website/fuel-update-with-vpn.sh user@fuelseeker.net:/tmp/
ssh user@fuelseeker.net "sudo mv /tmp/update_data_streaming.php /usr/local/bin/update_data_streaming.php && sudo mv /tmp/schema.sql /usr/local/bin/schema.sql && sudo mv /tmp/fuel-update-with-vpn.sh /usr/local/bin/fuel-update-with-vpn.sh && sudo chmod +x /usr/local/bin/fuel-update-with-vpn.sh"
```

**Note:** 
- `schema.sql` must be in the same directory as `update_data_streaming.php` (or in the project directory). The script searches multiple locations to find it.
- The update has a 15-minute timeout. If your connection is slow, it may need more time.

**Features of the streaming update script:**
- Streams data directly to CSV (low memory ~10MB)
- Builds database using SQLite CLI
- Atomic symlink swap for zero downtime
- Automatic retry on API timeouts
- Site stays accessible during updates

**2. Create the wrapper script** (`/usr/local/bin/fuel-update-with-vpn.sh`):

```bash
#!/bin/bash
# Update fuel database with UK VPN connection

LOG_FILE="/var/www/fuelseeker.net/data/update.log"
UPDATE_SCRIPT="/usr/local/bin/update_data_streaming.php"

log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $1" | tee -a "$LOG_FILE"
}

log "=== Starting Fuel Update ==="

# Check if update script exists
if [ ! -f "$UPDATE_SCRIPT" ]; then
    log "ERROR: Update script not found at $UPDATE_SCRIPT"
    exit 1
fi

# Important: Do NOT run update_data_streaming.php directly on non-UK servers
# This script (fuel-update-with-vpn.sh) handles the VPN connection first

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

# Run the update
log "Running fuel data update..."
UPDATE_OUTPUT=$(php "$UPDATE_SCRIPT" 2>&1)
UPDATE_STATUS=$?

echo "$UPDATE_OUTPUT" | while read line; do
    log "  $line"
done

if [ $UPDATE_STATUS -eq 0 ]; then
    log "Update completed successfully"
else
    log "ERROR: Update failed with status $UPDATE_STATUS"
    log "Check update_error.log for details"
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

Make it executable and add environment variables:
```bash
sudo chmod +x /usr/local/bin/fuel-update-with-vpn.sh
```

**Important:** Add environment variables at the top of the script (after the shebang) so the PHP script knows where to find the project files:

```bash
#!/bin/bash
# Update fuel database with UK VPN connection

# Set paths for the update script (required when script is in /usr/local/bin/)
export FUELSEEKER_SCRIPT_DIR=/var/www/fuelseeker.net/scripts
export FUELSEEKER_DATA_DIR=/var/www/fuelseeker.net/data

LOG_FILE="/var/www/fuelseeker.net/data/update.log"
...
```

**2. Create the service file** (`/etc/systemd/system/fuelseeker-vpn-update.service`):

```ini
[Unit]
Description=Fuel Finder Database Update with VPN
After=network-online.target
Wants=network-online.target

[Service]
Type=oneshot
# Set paths for the update script (required when script is in /usr/local/bin/)
Environment="FUELSEEKER_SCRIPT_DIR=/var/www/fuelseeker.net/scripts"
Environment="FUELSEEKER_DATA_DIR=/var/www/fuelseeker.net/data"
ExecStart=/usr/local/bin/fuel-update-with-vpn.sh
StandardOutput=append:/var/www/fuelseeker.net/data/update.log
StandardError=append:/var/www/fuelseeker.net/data/update.log

# Note: This runs as root because nordvpn requires root privileges
User=root
```

**3. Create the timer file** (`/etc/systemd/system/fuelseeker-vpn-update.timer`):

```ini
[Unit]
Description=Run Fuel Finder VPN Update Daily at 02:00

[Timer]
# Run at 02:00 every day (low traffic time)
OnCalendar=*-*-* 02:00:00

# Add a random delay up to 10 minutes to avoid server overload
RandomizedDelaySec=10m

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

### Option 3: Using Systemd Timer (Linux VPS - UK Servers) - Daily at 02:00

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
ExecStart=/usr/local/bin/fuel-update-with-vpn.sh
User=www-data
```

Create a timer file:

```bash
sudo nano /etc/systemd/system/fuel-update.timer
```

Content:
```ini
[Unit]
Description=Run Fuel Finder update daily at 02:00

[Timer]
# Run at 02:00 every day (low traffic time)
OnCalendar=*-*-* 02:00:00
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

### Option 4: cPanel / Shared Hosting

**⚠️ Important:** cPanel/shared hosting on **non-UK servers** is not supported because:
- You cannot install NordVPN CLI without root access
- You cannot install scripts to `/usr/local/bin/`
- The Fuel API will block requests with HTTP 403

**For UK-based shared hosting only:**

1. Log in to cPanel
2. Go to **Cron Jobs**
3. Under **Add New Cron Job**, set:
   - **Common Settings**: Select "Once a day" OR enter custom: `0 2 * * *`
   - **Command**: `/usr/bin/php /home/username/public_html/fuel/not_for_website/update_data_streaming.php >> /home/username/public_html/fuel/data/update.log 2>&1`
4. Click **Add New Cron Job**

**For non-UK shared hosting:** Use Option 1 (copy from a UK computer) instead.

## How the Update Works

The VPN update script uses a **symlink-based atomic swap** system to minimize downtime:

### Database File Structure

```
data/
├── fuel_data.db          ← symlink (points to either v1 or v2)
├── fuel_data.db.v1       ← actual database file
└── fuel_data.db.v2       ← actual database file
```

### The Update Process

1. **Download Phase:** All data (7,000+ stations) is downloaded from the API
2. **Build Phase:** New database is built in the INACTIVE file (v1 or v2)
3. **Swap Phase:** Symlink is atomically switched to point to the new file

### Why Two Files?

| Benefit | Explanation |
|---------|-------------|
| **Zero-downtime** | Symlink switch is instant (microseconds) |
| **Safety** | Old file remains for any open PHP connections to finish |
| **Alternation** | Each update overwrites the older file, not the active one |

### Example Update Sequence

**Update 1:**
- Builds `fuel_data.db.v2` with new data
- Switches symlink: `fuel_data.db → v2`
- `v1` preserved (old data)

**Update 2:**
- Builds `fuel_data.db.v1` with new data
- Switches symlink: `fuel_data.db → v1`
- `v2` preserved (old data)

**Update 3:**
- Builds `fuel_data.db.v2` with new data
- Switches symlink: `fuel_data.db → v2`
- And so on...

### Checking Which Database Is Active

```bash
# See which file the symlink points to
ls -la /var/www/fuelseeker.net/data/fuel_data.db

# Or read the symlink directly
readlink /var/www/fuelseeker.net/data/fuel_data.db
```

---

## Monitoring & Troubleshooting

### Check Last Update Time

**For UK PC deployment:**
```bash
# Check deployment status
curl "https://your-domain.com/scripts/db_deploy.php?action=status"

# View UK PC logs
tail -f /path/to/fuelseeker/logs/deploy.log
```

**Legacy VPN method:**
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

**For UK PC deployment:**
```bash
cd /path/to/data_retrieval_server
php deploy_to_remote_server.php
```

**Legacy VPN method:**
```bash
sudo /usr/local/bin/fuel-update-with-vpn.sh
```

### Common Issues

#### UK PC Deployment Issues

##### 1. "Could not find .secrets file"

**Cause**: The `.secrets` file is missing from `data_retrieval_server/`.

**Fix**: 
```bash
cd data_retrieval_server/
cp .secrets.example .secrets
# Edit .secrets and add your credentials
```

##### 2. "Invalid or missing deployment key"

**Cause**: The `DEPLOY_API_KEY` on the UK PC doesn't match the web server.

**Fix**: Ensure both files have the same 64-character key:
- UK PC: `data_retrieval_server/.secrets`
- Web server: `scripts/.secrets`

Generate a new key:
```bash
openssl rand -hex 32
```

##### 3. "File too large" or "upload_max_filesize"

**Cause**: PHP upload limits on the web server are too low.

**Fix**: Update `php.ini` on the web server:
```ini
post_max_size = 20M
upload_max_filesize = 20M
```

Then restart PHP-FPM/web server.

##### 4. "cURL error" or timeout during upload

**Cause**: Network issues between UK PC and web server.

**Fix**: 
- Check internet connectivity on both ends
- Verify the `DEPLOY_URL` is correct
- Check firewall rules on web server
- The script will retry automatically (3 attempts)

#### Legacy VPN Issues (Not Recommended)

#### 1. "Database not initialized" Error

**Cause**: The update script hasn't been run yet.

**Fix**: Run the update script manually:
```bash
sudo /usr/local/bin/fuel-update-with-vpn.sh
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

#### 4. "Failed to open stream: config.php" Error

**Cause**: The update script cannot find the project files because it's running from `/usr/local/bin/` but looking in the wrong location.

**Fix**: Ensure environment variables are set in the wrapper script or systemd service:

In `/usr/local/bin/fuel-update-with-vpn.sh`, add after the shebang:
```bash
export FUELSEEKER_SCRIPT_DIR=/var/www/fuelseeker.net/scripts
export FUELSEEKER_DATA_DIR=/var/www/fuelseeker.net/data
```

Or in the systemd service file `/etc/systemd/system/fuelseeker-vpn-update.service`, add to the [Service] section:
```ini
Environment="FUELSEEKER_SCRIPT_DIR=/var/www/fuelseeker.net/scripts"
Environment="FUELSEEKER_DATA_DIR=/var/www/fuelseeker.net/data"
```

Then reload: `sudo systemctl daemon-reload`

#### 5. "Could not find schema.sql" Error

**Cause**: The `update_data_streaming.php` script needs `schema.sql` to build the database, but it can't find it.

**Fix**: Copy `schema.sql` to the same directory as the update script:

```bash
# If update script is in /usr/local/bin/
sudo cp not_for_website/schema.sql /usr/local/bin/schema.sql

# Or copy from the project directory
sudo cp /var/www/fuelseeker.net/not_for_website/schema.sql /usr/local/bin/schema.sql
```

The script searches for `schema.sql` in multiple locations:
1. Same directory as the update script
2. Project's `not_for_website/` directory
3. `/var/www/fuelseeker.net/not_for_website/schema.sql` (common deployment path)

#### 6. SSH Disconnects When VPN Connects

**Cause**: When NordVPN connects and IPv6 is disabled, your SSH connection may be dropped.

**Fix**: Run the update using `nohup` or `screen` so it continues in the background:

```bash
# Using nohup
sudo nohup /usr/local/bin/fuel-update-with-vpn.sh > /tmp/update.log 2>&1 &

# Or using screen
sudo screen -dmS fuelupdate /usr/local/bin/fuel-update-with-vpn.sh
# Check status later: sudo screen -r fuelupdate
```

For systemd timer-based execution, this is not an issue since it runs without an SSH session.

#### 7. IPv6 Stays Disabled / Can't Connect to Server After Failed Update

**Cause**: If the script fails or is interrupted (e.g., SSH disconnect), IPv6 may remain disabled, breaking connectivity.

**Fix**: The script now has automatic cleanup that **always** runs on exit (even on error):
- Re-enables IPv6
- Disconnects VPN
- Removes port whitelists
- Restores network config

If you're still locked out, reboot the server or manually re-enable IPv6:
```bash
# From console/serial access (not SSH)
sudo sysctl -w net.ipv6.conf.all.disable_ipv6=0
sudo sysctl -w net.ipv6.conf.default.disable_ipv6=0
sudo sysctl -w net.ipv6.conf.eth0.disable_ipv6=0
sudo systemctl restart networking
```

#### 9. "Update timed out" Error

**Cause**: The API is responding slowly or the connection is slow. The update script has a 15-minute timeout.

**Fix**: This is usually temporary. Simply run the update again:

```bash
sudo nohup /usr/local/bin/fuel-update-with-vpn.sh > /tmp/update.log 2>&1 &
```

If it consistently times out, check:
- VPN connection speed: `nordvpn status`
- API reachability: `curl -s https://www.fuel-finder.service.gov.uk`
- Server load: `free -h` and `top`

#### 10. "Failed to fetch batch X: HTTP 500" Error

**Cause**: The gov.uk Fuel Finder API returned a server error (HTTP 500). This is a temporary issue on their side.

**Fix**: The script automatically retries once on 5xx errors. If it still fails, simply run the update again:

```bash
sudo nohup /usr/local/bin/fuel-update-with-vpn.sh > /tmp/update.log 2>&1 &
```

This is normal and happens occasionally when the API is under heavy load.

#### 11. Cron Job Not Running

**Check**: Verify the PHP path:
```bash
which php
```

**Test**: Run the command manually (use `nohup` - SSH will disconnect!):
```bash
# IMPORTANT: SSH will disconnect when VPN connects!
# Use nohup to keep the script running in background
sudo nohup /usr/local/bin/fuel-update-with-vpn.sh > /tmp/update.log 2>&1 &

# Wait a moment, then check the log
sleep 15
tail -f /tmp/update.log

# To stop watching: Ctrl+C
# The script continues running in background even if you disconnect
```

**Why SSH disconnects**: When NordVPN connects, it changes the network routing table. Your SSH connection was established on the original IP/route, so it gets broken. This is **normal and expected**. The script continues running on the server.

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
│   └── .secrets  (644)
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

4. **Caddy Web Server**: Unlike Apache/nginx, Caddy does NOT hide dotfiles by default. This could expose your `.secrets` file containing API credentials. Add this to your Caddyfile:
   ```caddy
   file_server {
       hide .*
   }
   ```
   Or specifically hide sensitive files:
   ```caddy
   file_server {
       hide .secrets .git .gitignore
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

## Path Configuration for Update Script

The `update_data_streaming.php` script automatically detects the correct paths for your installation:

### Auto-Detection (Default)

The script uses `__DIR__` to auto-detect paths relative to its location:
- Script location: `not_for_website/update_data_streaming.php`
- Detected base directory: Parent of `not_for_website/`
- Looks for `scripts/` and `data/` subdirectories

**No configuration needed** - the script works out of the box on both local and live servers.

### Environment Variables (Optional Override)

For custom installations or testing different paths, use environment variables:

```bash
# Custom paths
FUELSEEKER_SCRIPT_DIR=/custom/scripts FUELSEEKER_DATA_DIR=/custom/data php update_data_streaming.php

# Note: update_data_streaming.php is no longer used. Use deploy_to_remote_server.php on the UK PC instead.
```

The script will output which path detection method it's using when run.

---

## Changelog

- **Mar 2026**: Added smart path detection to update_data_streaming.php
- **Feb 2026**: Added geolocation workaround documentation for non-UK servers
- **Mar 2026**: Changed from .env to .secrets for configuration files
- **Feb 2026**: Updated config.php to support .env file in scripts/ directory
- **Feb 2026**: Fixed domain whitelisting for os_token.php and token.php
