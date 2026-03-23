# Data Retrieval Server

These files are deployed to a UK-based PC to collect fuel data from the UK-only gov.uk Fuel Finder API and upload it to the live web server.

## Why This Architecture?

The gov.uk Fuel Finder API is restricted to UK IP addresses. If your web server is outside the UK (e.g., Germany, US), you cannot update the database directly from the server.

**Solution:** Use a UK-based PC (home/office) to download the data and deploy it to your web server via HTTPS.

## Architecture

```
┌─────────────────┐         HTTPS          ┌──────────────────┐
│   UK Home PC    │  ───────────────────►  │  Web Server      │
│  (Debian + PHP) │   POST database file   │  (Any location)  │
└─────────────────┘                        └──────────────────┘
       │                                           │
       │ Fuel Finder API (UK IP, no VPN needed)    │ Serves website
       ▼                                           ▼
  Downloads data                              SQLite Database
  Builds SQLite DB                            (Atomic swap)
  Gzip compression (optional)                 Decompression
```

**Compression:** The database is automatically gzip-compressed before upload, reducing transfer size by ~60-70% (typically 13MB → 4-5MB). This is enabled by default but can be disabled in `.secrets` if needed.

**Performance:** The entire deployment process (API token → database build → upload) completes in under 30 seconds. This is significantly faster than the old VPN-based approach which typically took several minutes due to VPN connection overhead and uncompressed uploads.

## Setup Instructions

### 1. Prerequisites (UK PC)

Install PHP and required extensions:

```bash
sudo apt update
sudo apt install php-cli php-curl php-sqlite3 sqlite3
```

### 2. Configuration

Copy these files to your UK PC (e.g., `/home/user/fuelseeker/data_retrieval_server/`):

- `deploy_to_remote_server.php`
- `config.php`
- `schema.sql`
- `.secrets.example`

Create the `.secrets` file:

```bash
cp .secrets.example .secrets
nano .secrets
```

Add your credentials:

```bash
# Gov.uk Fuel Finder API Credentials
# Get these from: https://www.fuel-finder.service.gov.uk
FUEL_CLIENT_ID=your_fuel_client_id_here
FUEL_CLIENT_SECRET=your_fuel_client_secret_here

# Deployment API Key (must match the web server's DEPLOY_API_KEY)
# Generate with: openssl rand -hex 32
DEPLOY_API_KEY=your_64_character_deployment_key_here
```

**Important:** The `DEPLOY_API_KEY` must match the key configured on your web server in `scripts/.secrets`.

### 3. Web Server Configuration

On your web server, ensure `scripts/db_deploy.php` exists and `scripts/.secrets` contains:

```bash
DEPLOY_API_KEY=your_64_character_deployment_key_here
```

Also ensure PHP upload limits are sufficient:
- `post_max_size = 20M`
- `upload_max_filesize = 20M`

### 4. Test Deployment

Run the deployment script manually:

```bash
php deploy_to_remote_server.php
```

Expected output:
```
[2026-03-21 20:00:00] [INFO] === FuelSeeker UK PC Deployment ===
[2026-03-21 20:00:00] [INFO] Data dir: /home/user/fuelseeker/data
[2026-03-21 20:00:00] [INFO] API key loaded (64 chars)
[2026-03-21 20:00:00] [INFO] === Starting Database Build ===
[2026-03-21 20:00:05] [INFO] [1/4] Getting OAuth token...
[2026-03-21 20:00:06] [INFO] Got token
[2026-03-21 20:00:06] [INFO] [2/4] Downloading stations to CSV...
[2026-03-21 20:00:30] [INFO] Downloaded 7167 stations
[2026-03-21 20:00:30] [INFO] [3/4] Downloading prices to CSV...
[2026-03-21 20:00:45] [INFO] Downloaded prices for 7167 stations
[2026-03-21 20:00:45] [INFO] [4/4] Building database via SQLite CLI...
[2026-03-21 20:01:00] [INFO] Database built: 13.5 MB
[2026-03-21 20:01:00] [INFO] === Starting Deployment ===
[2026-03-21 20:01:00] [INFO] Target: https://fuelseeker.net/scripts/db_deploy.php
[2026-03-21 20:01:15] [INFO] Upload attempt 1 of 3...
[2026-03-21 20:01:25] [INFO] Deployment successful!
[2026-03-21 20:01:25] [INFO] === Deployment Complete ===
```

### 5. Set Up Automatic Updates

#### Option A: Systemd Timer (Recommended)

Pre-made systemd files are in the `systemd_timer/` directory:

```bash
# Copy files to system
sudo cp systemd_timer/fuelseeker-deploy.service /etc/systemd/system/
sudo cp systemd_timer/fuelseeker-deploy.timer /etc/systemd/system/

# Edit to set your username and paths
sudo systemctl edit fuelseeker-deploy.service

# Enable and start
sudo systemctl daemon-reload
sudo systemctl enable fuelseeker-deploy.timer
sudo systemctl start fuelseeker-deploy.timer
```

See `systemd_timer/INSTALL-SYSTEMD.md` for detailed instructions.

#### Option B: Cron

Add to crontab for 3x daily updates:

```bash
crontab -e
```

Add:
```
# FuelSeeker data update - 06:00, 14:00, 22:00
0 6,14,22 * * * cd /home/user/fuelseeker && php deploy_to_remote_server.php >> data/logs/deploy.log 2>&1
```

## Troubleshooting

### "Failed to get OAuth token"

- Ensure the UK PC has a UK IP address
- Verify `FUEL_CLIENT_ID` and `FUEL_CLIENT_SECRET` in `.secrets`
- Test API access: `curl -I https://www.fuel-finder.service.gov.uk/`

### "Invalid or missing deployment key"

- Ensure `DEPLOY_API_KEY` matches on both UK PC and web server
- Check the key is being read: add debug logging to verify

### "File too large" or upload timeout

- Increase PHP limits on web server
- Check UK PC upload bandwidth
- Retry will happen automatically (3 attempts with backoff)

### "Swap failed: Failed to move uploaded file to target location"

This indicates the web server cannot write to the `data/` directory.

**On the web server, run:**
```bash
cd scripts/
php check_deploy.php
```

**Common fixes:**
```bash
# Fix permissions
chmod 755 data
chown www-data:www-data data  # Adjust user for your web server (could be www-data, apache, nginx, etc.)

# If data directory doesn't exist:
mkdir data
chmod 755 data
chown www-data:www-data data
```

### Check deployment status

```bash
curl "https://fuelseeker.net/scripts/db_deploy.php?action=status"
```

## File Structure

```
data_retrieval_server/
├── deploy_to_remote_server.php  # Main deployment script
├── config.php                   # Configuration loader (.secrets support)
├── schema.sql                   # Database schema
├── systemd_timer/               # Systemd timer files for auto-deployment
│   ├── fuelseeker-deploy.service
│   ├── fuelseeker-deploy.timer
│   └── INSTALL-SYSTEMD.md
├── .secrets                     # API credentials (create from .secrets.example)
├── .secrets.example             # Example secrets file
└── README.md                    # This file
```

## Security Notes

1. **Keep `.secrets` secure** - it contains API credentials
2. **Use HTTPS only** - never deploy over HTTP
3. **Strong deployment key** - use `openssl rand -hex 32` to generate
4. **Firewall** - web server should only accept deployments from known IPs (optional)
