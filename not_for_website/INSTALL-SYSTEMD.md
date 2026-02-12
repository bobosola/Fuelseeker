# Systemd Installation Instructions

These instructions deploy the systemd timer for FuelSeeker updates (runs 3x daily at 06:00, 14:00, 22:00).

## Files

1. `fuel-update-with-vpn.sh` - VPN wrapper script (handles NordVPN connection, port whitelisting, IPv6)
2. `update_data_streaming.php` - Streaming database update script (low memory, no hangs)
3. `fuelseeker-vpn-update.service` - Systemd service definition
4. `fuelseeker-vpn-update.timer` - Systemd timer (runs 3x daily)

## Deployment Steps

```bash
# 1. Copy scripts to system location
sudo cp not_for_website/update_data_streaming.php /usr/local/bin/
sudo cp not_for_website/fuel-update-with-vpn.sh /usr/local/bin/
sudo chmod +x /usr/local/bin/fuel-update-with-vpn.sh

# 2. Copy systemd files
sudo cp not_for_website/fuelseeker-vpn-update.service /etc/systemd/system/
sudo cp not_for_website/fuelseeker-vpn-update.timer /etc/systemd/system/

# 3. Create data directory if needed
sudo mkdir -p /var/www/fuelseeker.net/data/tmp
sudo chown -R www-data:www-data /var/www/fuelseeker.net/data

# 4. Reload systemd and enable timer
sudo systemctl daemon-reload
sudo systemctl enable fuelseeker-vpn-update.timer
sudo systemctl start fuelseeker-vpn-update.timer

# 5. Verify timer is active
sudo systemctl list-timers fuelseeker-vpn-update.timer
sudo systemctl status fuelseeker-vpn-update.timer
```

## Monitoring

```bash
# Check timer status
sudo systemctl list-timers fuelseeker-vpn-update.timer

# Check last run status
sudo systemctl status fuelseeker-vpn-update.service

# View logs
sudo journalctl -u fuelseeker-vpn-update.service -f
tail -f /var/www/fuelseeker.net/data/update.log

# Check for errors
tail -f /var/www/fuelseeker.net/data/update_error.log
```

## Manual Trigger (for testing)

```bash
# Run update manually
sudo /usr/local/bin/fuel-update-with-vpn.sh

# Or via systemd
sudo systemctl start fuelseeker-vpn-update.service

# Watch the logs
tail -f /var/www/fuelseeker.net/data/update.log
```

## Rollback (if needed)

```bash
# Stop and disable timer
sudo systemctl stop fuelseeker-vpn-update.timer
sudo systemctl disable fuelseeker-vpn-update.timer
```
