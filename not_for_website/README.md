# Not For Website

These files are used for updating the SQLite database from the Fuel API. They should be installed to `/usr/local/bin/` on the server (outside the web root for security).

## Files

- `update_data_safe.php` - Main update script with zero-downtime symlink swapping (installs to `/usr/local/bin/`)
- `schema.sql` - SQLite database schema
- `fuel-update-with-vpn.sh` - VPN wrapper script for non-UK servers (installs to `/usr/local/bin/`)
- `fuelseeker-vpn-update.service` - systemd service file
- `fuelseeker-vpn-update.timer` - systemd timer file (runs at 02:00 daily)
- `INSTALL-SYSTEMD.md` - Systemd installation instructions

## Important Notes

- **Install location:** Copy `update_data_safe.php` and `fuel-update-with-vpn.sh` to `/usr/local/bin/`
- **Cannot run directly:** On non-UK servers, you **cannot** run `update_data_safe.php` directly due to geo-blocking. You must use `fuel-update-with-vpn.sh` which connects to a UK VPN first.
- **CLI only:** These scripts must be run from command line (not web accessible)
- **Systemd timer:** Updates are controlled by systemd timer, not cron (see INSTALL.md)
- **Update time:** Runs once daily at 02:00 UK time to minimize server impact

## Usage

### Non-UK servers (most common):
```bash
# Initial setup:
sudo cp update_data_safe.php /usr/local/bin/
sudo cp fuel-update-with-vpn.sh /usr/local/bin/

# Run update (connects VPN automatically):
sudo /usr/local/bin/fuel-update-with-vpn.sh

# Check status:
sudo systemctl status fuelseeker-vpn-update.timer
```

### UK servers:
```bash
# Initial setup:
sudo cp update_data_safe.php /usr/local/bin/

# Run update (no VPN needed):
php /usr/local/bin/update_data_safe.php
```

See `../INSTALL.md` for complete installation and systemd setup instructions.