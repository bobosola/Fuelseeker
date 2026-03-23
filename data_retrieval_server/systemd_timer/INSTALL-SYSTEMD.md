# Systemd Timer Installation for UK PC

These instructions set up automatic database deployment from the UK PC to your web server 3x daily.

## Prerequisites

- UK-based PC (or VPN connection to UK)
- PHP 8.0+ with curl, sqlite3, and zlib extensions
- Fuel Finder API credentials
- Web server configured to receive deployments

**Note:** The zlib extension is required for gzip compression. If not available, uploads will fall back to uncompressed (slower but still works).

## Files

- `fuelseeker-deploy.service` - Systemd service definition
- `fuelseeker-deploy.timer` - Systemd timer (runs 3x daily at 06:00, 14:00, 22:00)

## Installation

### 1. Copy systemd files

```bash
# Copy to system location
sudo cp data_retrieval_server/systemd_timer/fuelseeker-deploy.service /etc/systemd/system/
sudo cp data_retrieval_server/systemd_timer/fuelseeker-deploy.timer /etc/systemd/system/
```

### 2. Configure the service

Edit the service file to match your setup:

```bash
sudo systemctl edit fuelseeker-deploy.service
```

Update these values:
- `User=` - the user that owns the fuelseeker files
- `WorkingDirectory=` - full path to your data_retrieval_server directory
- `ExecStart=` - full path to PHP (run `which php` to verify)
- Log paths - update username in StandardOutput/StandardError

Example for user `bob`:
```ini
[Service]
User=bob
WorkingDirectory=/home/bob/fuelseeker/data_retrieval_server
ExecStart=/usr/bin/php deploy_to_remote_server.php
StandardOutput=append:/home/bob/fuelseeker/logs/deploy.log
StandardError=append:/home/bob/fuelseeker/logs/deploy.log
```

### 3. Enable and start the timer

```bash
# Reload systemd
sudo systemctl daemon-reload

# Enable timer (starts automatically on boot)
sudo systemctl enable fuelseeker-deploy.timer

# Start timer now
sudo systemctl start fuelseeker-deploy.timer

# Verify timer is active
sudo systemctl list-timers fuelseeker-deploy.timer
```

## Monitoring

```bash
# Check timer status
sudo systemctl list-timers fuelseeker-deploy.timer

# Check last run status
sudo systemctl status fuelseeker-deploy.service

# View deployment logs
tail -f ~/fuelseeker/data/logs/deploy.log

# View systemd logs
sudo journalctl -u fuelseeker-deploy.service -f
```

## Manual Trigger (for testing)

```bash
# Run deployment manually
sudo systemctl start fuelseeker-deploy.service

# Or run directly
cd ~/fuelseeker
php deploy_to_remote_server.php
```

## Troubleshooting

### "Failed to get OAuth token"
- Ensure the PC has a UK IP address
- Check `~/fuelseeker/.secrets` has valid credentials

### "Invalid or missing deployment key"
- Ensure `DEPLOY_API_KEY` in `.secrets` matches the web server

### "Connection failed"
- Check internet connectivity
- Verify the `DEPLOY_URL` is correct

## Disable / Remove

```bash
# Stop and disable timer
sudo systemctl stop fuelseeker-deploy.timer
sudo systemctl disable fuelseeker-deploy.timer

# Remove files
sudo rm /etc/systemd/system/fuelseeker-deploy.*
sudo systemctl daemon-reload
```

## Schedule Customization

Edit the timer to change when deployments run:

```bash
sudo systemctl edit fuelseeker-deploy.timer
```

Examples:
```ini
# Every 6 hours (4x daily)
OnCalendar=*-*-* 00,06,12,18:00:00

# Once daily at 02:00
OnCalendar=*-*-* 02:00:00

# Every 4 hours
OnCalendar=*:0/4
```

Then reload:
```bash
sudo systemctl daemon-reload
```
