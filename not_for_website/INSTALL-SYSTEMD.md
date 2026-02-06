# Systemd Installation Instructions

These instructions deploy the systemd timer for FuelSeeker updates (runs once daily at 02:00).

## Files Created

1. `fuel-update-with-vpn.sh` - Updated VPN wrapper script (cleaner exit handling)
2. `update_data_safe.php` - Updated with WAL mode PRAGMAs
3. `fuelseeker-vpn-update.service` - Systemd service with resource limits
4. `fuelseeker-vpn-update.timer` - Systemd timer (runs once daily at 02:00)

## Deployment Steps (Run on osola.org.uk)

```bash
# 1. SSH to the server
ssh osola.org.uk

# 2. Copy systemd files to system location
sudo cp /var/www/fuelseeker.net/not_for_website/fuelseeker-vpn-update.service /etc/systemd/system/
sudo cp /var/www/fuelseeker.net/not_for_website/fuelseeker-vpn-update.timer /etc/systemd/system/

# 3. Ensure the VPN script is executable
sudo chmod +x /var/www/fuelseeker.net/not_for_website/fuel-update-with-vpn.sh

# 4. Create data directory if needed
sudo mkdir -p /var/www/fuelseeker.net/data
sudo chown -R www-data:www-data /var/www/fuelseeker.net/data

# 5. Stop any existing cron job (if using cron)
sudo crontab -e
# Remove or comment out the existing fuel update cron line

# 6. Reload systemd and enable timer
sudo systemctl daemon-reload
sudo systemctl enable fuelseeker-vpn-update.timer
sudo systemctl start fuelseeker-vpn-update.timer

# 7. Verify timer is active
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
sudo systemctl start fuelseeker-vpn-update.service

# Watch the logs
tail -f /var/www/fuelseeker.net/data/update.log
```

## Resource Limits Applied

| Limit | Value | Purpose |
|-------|-------|---------|
| CPUQuota | 25% | Prevents CPU starvation |
| MemoryMax | 512M | Hard memory cap |
| MemorySwapMax | 0 | Prevents swap thrashing |
| IOWeight | 10 | Low disk priority |
| Nice | 10 | Lower CPU scheduling priority |
| IOSchedulingClass | idle | Idle I/O class |

## Rollback (if needed)

```bash
# Stop and disable timer
sudo systemctl stop fuelseeker-vpn-update.timer
sudo systemctl disable fuelseeker-vpn-update.timer

# Restore cron if desired
sudo crontab -e
# Add back: 0 2 * * * /var/www/fuelseeker.net/not_for_website/fuel-update-with-vpn.sh
```
