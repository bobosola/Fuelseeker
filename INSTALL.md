# Fuelseeker.net - Installation & Setup Guide

## Overview

Fuelseeker.net uses a **split architecture** to work around the UK-only gov.uk Fuel Finder API:

- **Web Server** (any location): Serves the website, hosts the SQLite database
- **UK PC** (`data_retrieval_server/`): Downloads fuel data and deploys to the web server via HTTPS

This avoids VPN complications when the web server is outside the UK.

## Web Server Setup

### 1. Upload Files

Upload all files to your web server (e.g., `/var/www/fuelseeker/`).

### 2. Configure API Credentials

Create the secrets file for the web server:

```bash
cp scripts/.secrets.example scripts/.secrets
```

Edit `scripts/.secrets` and add your credentials:

```bash
# Ordnance Survey API (for geocoding postcodes/place names)
# Get credentials from: https://osdatahub.os.uk/
OS_API_KEY=your_api_key_here
OS_API_SECRET=your_api_secret_here

# Deployment API key (must match the UK PC)
# Generate with: openssl rand -hex 32
DEPLOY_API_KEY=your_64_character_key_here
```

Verify `.secrets` is NOT committed to Git:
```bash
git status
# .secrets should NOT appear (it's in `.gitignore`)
```

### 3. Create Data Directory

```bash
cd /path/to/fuelseeker
mkdir data
chmod 755 data
```

The database will be deployed automatically from the UK PC. No manual database creation needed.

### 4. Verify Web Server Setup

Check the deployment endpoint is accessible:

```bash
curl "https://your-domain.com/scripts/db_deploy.php?action=status"
```

Expected response (before first deployment):
```json
{"status":"error","message":"No database found"}
```

This is normal - the database will be created on first deployment from the UK PC.

---

## UK PC Setup (Data Retrieval)

The UK PC downloads fuel data from the gov.uk API (UK IP required) and deploys it to your web server.

### 1. Copy Files to UK PC

Copy the `data_retrieval_server/` directory to a UK-based computer (home, office, or UK VPS):

```bash
# On your UK computer
mkdir -p ~/fuelseeker
cd ~/fuelseeker

# Copy files from the project
cp -r /path/to/project/data_retrieval_server/* .
```

### 2. Configure Credentials

```bash
cp .secrets.example .secrets
```

Edit `.secrets` and add your credentials:

```bash
# Fuel Finder API (from https://www.fuel-finder.service.gov.uk)
FUEL_CLIENT_ID=your_client_id_here
FUEL_CLIENT_SECRET=your_client_secret_here

# Deployment API key (must match web server)
DEPLOY_API_KEY=your_64_character_key_here

# Web server deployment URL
DEPLOY_URL=https://your-domain.com/scripts/db_deploy.php
```

**Note:** The `DEPLOY_API_KEY` must be identical on both the UK PC and the web server.

### 3. Test Deployment

Run the deployment script manually:

```bash
php deploy_to_remote_server.php
```

Expected output:
```
[2026-03-24 10:00:00] === Starting Deployment ===
[2026-03-24 10:00:02] Downloaded 7167 stations
[2026-03-24 10:00:15] Database built: fuel_data.db (12.5 MB)
[2026-03-24 10:00:20] Upload successful (HTTP 200)
[2026-03-24 10:00:20] === Deployment Complete ===
```

### 4. Verify Database on Web Server

```bash
curl "https://your-domain.com/scripts/db_deploy.php?action=status"
```

Expected response:
```json
{
  "status": "ok",
  "version": 1,
  "file": "fuel_data.db.v1",
  "stations": 7167,
  "size_mb": 12.5,
  "updated": "2026-03-24 10:00:20"
}
```

### 5. Set Up Automatic Updates

Create a cron job on the UK PC to run 3x daily:

```bash
crontab -e
```

Add:
```
# Fuelseeker updates at 06:00, 14:00, and 22:00
0 6,14,22 * * * cd /home/username/fuelseeker && /usr/bin/php deploy_to_remote_server.php >> logs/deploy.log 2>&1
```

**Or using systemd timer (Linux):**

Create the service file (`/etc/systemd/system/fuelseeker-deploy.service`):
```ini
[Unit]
Description=Fuelseeker Database Deployment
After=network-online.target
Wants=network-online.target

[Service]
Type=oneshot
WorkingDirectory=/home/username/fuelseeker
ExecStart=/usr/bin/php deploy_to_remote_server.php
StandardOutput=append:/home/username/fuelseeker/logs/deploy.log
StandardError=append:/home/username/fuelseeker/logs/deploy.log
User=username
```

Create the timer file (`/etc/systemd/system/fuelseeker-deploy.timer`):
```ini
[Unit]
Description=Run Fuelseeker deployment 3x daily

[Timer]
OnCalendar=*-*-* 06:00:00
OnCalendar=*-*-* 14:00:00
OnCalendar=*-*-* 22:00:00
Persistent=true

[Install]
WantedBy=timers.target
```

Enable and start:
```bash
sudo systemctl daemon-reload
sudo systemctl enable fuelseeker-deploy.timer
sudo systemctl start fuelseeker-deploy.timer
```

Check status:
```bash
sudo systemctl list-timers fuelseeker-deploy.timer
```

---

## Architecture Details

### How It Works

```
UK PC (Home/Office)          Web Server (Any location)
     │                               │
     ├── Downloads fuel data ───────►│
     │    (UK-only API access)       │
     │                               │
     └── Deploys via HTTPS ────────►├─ Receives database
                                    ├─ Performs atomic swap
                                    └─ Serves updated data
```

### Zero-Downtime Updates

The deployment uses an **atomic symlink swap**:

```
data/
├── fuel_data.db          ← symlink (points to v1 or v2)
├── fuel_data.db.v1       ← actual database file
└── fuel_data.db.v2       ← actual database file
```

1. Each update builds to the INACTIVE file (v1 or v2)
2. Symlink is atomically switched to point to the new file
3. Existing PHP connections finish reading from the old file
4. New requests use the updated database immediately

### Streaming Update Benefits

| Metric | Value |
|--------|-------|
| Method | Streaming CSV + SQLite CLI |
| Speed | ~25-45s total |
| Memory | ~10MB (constant) |
| Server impact | None (no hangs) |

---

## Troubleshooting

### UK PC Deployment Issues

#### "Could not find .secrets file"

**Cause:** The `.secrets` file is missing from `data_retrieval_server/`.

**Fix:**
```bash
cd data_retrieval_server/
cp .secrets.example .secrets
# Edit .secrets and add your credentials
```

#### "Invalid or missing deployment key"

**Cause:** The `DEPLOY_API_KEY` on the UK PC doesn't match the web server.

**Fix:** Ensure both files have the same 64-character key:
- UK PC: `data_retrieval_server/.secrets`
- Web server: `scripts/.secrets`

Generate a new key:
```bash
openssl rand -hex 32
```

#### "File too large" or "upload_max_filesize"

**Cause:** PHP upload limits on the web server are too low.

**Fix:** Update `php.ini` on the web server:
```ini
post_max_size = 20M
upload_max_filesize = 20M
```

Then restart PHP-FPM/web server.

#### "cURL error" or timeout during upload

**Cause:** Network issues between UK PC and web server.

**Fix:**
- Check internet connectivity on both ends
- Verify the `DEPLOY_URL` is correct
- Check firewall rules on web server
- The script will retry automatically (3 attempts)

#### "Failed to get OAuth token" / HTTP 403

**Cause:** The UK PC cannot access the gov.uk Fuel Finder API (requires UK IP).

**Fix:** Ensure the UK PC is:
- Located in the UK, OR
- Connected via a UK VPN

### Web Server Issues

#### "Database not initialized" Error

**Cause:** The deployment hasn't been run yet.

**Fix:** Run deployment from UK PC:
```bash
cd /path/to/data_retrieval_server
php deploy_to_remote_server.php
```

#### Permission Denied Errors

**Fix:** Ensure the web server can write to the `data` directory:
```bash
chown -R www-data:www-data /path/to/fuel/data
chmod -R 755 /path/to/fuel/data
```

#### "Failed to open stream: config.php" Error

**Cause:** PHP cannot find the project configuration.

**Fix:** Ensure file paths are correct in your web server config and the `scripts/` directory is readable.

---

## File Permissions

```
fuelseeker/
├── data/                    (755 - writable by web server)
│   ├── fuel_data.db        (symlink)
│   ├── fuel_data.db.v1     (644 or 666)
│   ├── fuel_data.db.v2     (644 or 666)
│   └── deploy.log          (auto-created)
├── scripts/
│   ├── db_deploy.php       (644)
│   ├── local_api.php       (644)
│   ├── token.php           (644)
│   ├── os_token.php        (644)
│   ├── config.php          (644)
│   └── .secrets            (640 - readable by web server only)
├── css/                    (755)
├── js/                     (755)
├── index.html              (644)
├── map.html                (644)
└── about.html              (644)
```

---

## Security Notes

1. **Protect the data directory:** Add to `.htaccess` or nginx config:
   ```apache
   # Deny access to data directory
   <Directory "/path/to/fuel/data">
       Order deny,allow
       Deny from all
   </Directory>
   ```

2. **Protect dotfiles (Caddy):** Unlike Apache/nginx, Caddy does NOT hide dotfiles by default:
   ```caddy
   file_server {
       hide .secrets .git .gitignore
   }
   ```

3. **API Credentials:** Store only on the UK PC (`FUEL_*`) and web server (`OS_*`). Never commit `.secrets` files.

4. **HTTPS:** Always use HTTPS in production to protect user location data.

5. **Deployment Key:** Use a strong 64-character key for `DEPLOY_API_KEY`.

---

## Monitoring

### Check Deployment Status

```bash
curl "https://your-domain.com/scripts/db_deploy.php?action=status"
```

### View UK PC Logs

```bash
tail -f /path/to/fuelseeker/logs/deploy.log
```

### Check Database via Local API

```bash
curl "https://your-domain.com/scripts/local_api.php?action=status"
```

---

**Last Updated:** March 2026
