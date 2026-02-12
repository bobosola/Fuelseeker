# Not For Website

These files are used for updating the SQLite database from the Fuel API. They should be installed to `/usr/local/bin/` on the server (outside the web root for security).

## Files

### Main Update Script

- `update_data_streaming.php` - **Streaming CSV + SQLite CLI import**. Low memory usage (~10MB), fast (~25-45s), no server hangs. Streams data directly to CSV during API fetch.

### Supporting Files

- `schema.sql` - SQLite database schema
- `fuel-update-with-vpn.sh` - VPN wrapper for non-UK servers (auto-connects NordVPN, handles port whitelisting and IPv6)
- `fuelseeker-vpn-update.service` - systemd service file
- `fuelseeker-vpn-update.timer` - systemd timer file (runs 3x daily: 06:00, 14:00, 22:00)
- `INSTALL-SYSTEMD.md` - Systemd installation instructions
- `DATABASE-UPDATE-OPTIMIZATION.md` - Performance optimization guide

## Important Notes

- **Install location:** Copy scripts to `/usr/local/bin/` (outside web root)
- **Non-UK servers:** You **cannot** run the PHP script directly due to geo-blocking. Use `fuel-update-with-vpn.sh` which connects to a UK VPN first.
- **CLI only:** These scripts must be run from command line (not web accessible)
- **Systemd timer:** Updates are controlled by systemd timer, not cron
- **Update frequency:** Runs 3x daily for better price accuracy

## Usage

### Non-UK servers (most common):

```bash
# Initial setup:
sudo cp update_data_streaming.php /usr/local/bin/
sudo cp fuel-update-with-vpn.sh /usr/local/bin/

# Run update (connects VPN automatically, keeps site accessible):
sudo /usr/local/bin/fuel-update-with-vpn.sh

# Check status:
sudo systemctl status fuelseeker-vpn-update.timer

# View logs:
sudo tail -f /var/www/fuelseeker.net/data/update.log
```

### UK servers:

```bash
# Initial setup:
sudo cp update_data_streaming.php /usr/local/bin/

# Run update (no VPN needed):
php /usr/local/bin/update_data_streaming.php
```

## VPN & Network Notes

The `fuel-update-with-vpn.sh` wrapper handles several network issues automatically:

1. **Port Whitelisting** - Whitelists ports 22, 80, 443 before connecting VPN to prevent iptables from dropping inbound web traffic
2. **IPv6 Management** - Temporarily disables IPv6 during VPN to prevent browser timeouts, then restores it after
3. **CPU Throttling** - Runs SQLite import with `nice -n 15` to reduce server impact

See `DATABASE-UPDATE-OPTIMIZATION.md` for detailed troubleshooting.

See `../INSTALL.md` for complete installation and systemd setup instructions.
