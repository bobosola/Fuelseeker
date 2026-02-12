# Not For Website

These files are used for updating the SQLite database from the Fuel API. They should be installed to `/usr/local/bin/` on the server (outside the web root for security).

## File Locations

### Source Files (this directory)
| File | Description |
|------|-------------|
| `update_data_streaming.php` | Main update script - streaming CSV import, low memory (~10MB) |
| `fuel-update-with-vpn.sh` | VPN wrapper for non-UK servers (auto-connects NordVPN, handles port whitelisting and IPv6) |
| `fuelseeker-vpn-update.service` | systemd service file |
| `fuelseeker-vpn-update.timer` | systemd timer file (runs 3x daily: 06:00, 14:00, 22:00) |
| `schema.sql` | SQLite database schema |
| `DATABASE-UPDATE-OPTIMIZATION.md` | Performance optimization guide |
| `INSTALL-SYSTEMD.md` | Systemd installation instructions |

### Installation Paths

```
/usr/local/bin/                    # System scripts (outside web root)
├── update_data_streaming.php      # Main update script
└── fuel-update-with-vpn.sh        # VPN wrapper script

/etc/systemd/system/               # systemd configuration
├── fuelseeker-vpn-update.service  # Service definition
└── fuelseeker-vpn-update.timer    # Timer (3x daily)

/var/www/fuelseeker.net/           # Web root
├── data/                          # Database directory
│   ├── fuel_data.db               # Symlink to active database
│   ├── fuel_data.db.v1            # Database file (alternating)
│   ├── fuel_data.db.v2            # Database file (alternating)
│   ├── tmp/                       # Temp CSV files during update
│   ├── update.log                 # Update logs
│   └── update_error.log           # Error logs
│
├── scripts/                       # PHP backend
│   ├── local_api.php              # Local API endpoint
│   ├── api_proxy.php              # External API proxy
│   └── ...
│
└── not_for_website/               # This directory (deployment files)
```

## Installation

### Non-UK servers (most common):

```bash
# 1. Copy scripts to system location
sudo cp not_for_website/update_data_streaming.php /usr/local/bin/
sudo cp not_for_website/fuel-update-with-vpn.sh /usr/local/bin/
sudo chmod +x /usr/local/bin/fuel-update-with-vpn.sh

# 2. Copy systemd files
sudo cp not_for_website/fuelseeker-vpn-update.service /etc/systemd/system/
sudo cp not_for_website/fuelseeker-vpn-update.timer /etc/systemd/system/

# 3. Create data directory
sudo mkdir -p /var/www/fuelseeker.net/data/tmp
sudo chown -R www-data:www-data /var/www/fuelseeker.net/data

# 4. Reload systemd and enable timer
sudo systemctl daemon-reload
sudo systemctl enable fuelseeker-vpn-update.timer
sudo systemctl start fuelseeker-vpn-update.timer

# 5. Run initial update
sudo /usr/local/bin/fuel-update-with-vpn.sh
```

### UK servers:

```bash
# Copy script to system location
sudo cp not_for_website/update_data_streaming.php /usr/local/bin/

# Run initial update (no VPN needed)
php /usr/local/bin/update_data_streaming.php
```

## Testing the API

After installation, test the local API:

```bash
# Check database status
curl "https://fuelseeker.net/scripts/local_api.php?action=status"

# Search for stations
curl "https://fuelseeker.net/scripts/local_api.php?action=search&q=SO31&limit=5"

# Find nearby stations (lat/lng in decimal degrees, radius in miles)
curl "https://fuelseeker.net/scripts/local_api.php?action=nearby&lat=51.5&lng=-0.1&radius=5"
```

### Example API Responses

**Status:**
```json
{
  "total_stations": 7067,
  "stations_with_prices": 7067,
  "last_update": "2026-02-12 11:06:21",
  "status": "ok"
}
```

**Search:**
```json
[
  {
    "node_id": "abc123...",
    "trading_name": "Tesco Superstore",
    "brand_name": "Tesco",
    "location": {
      "postcode": "SO31 7ET",
      "latitude": "50.8792",
      "longitude": "-1.2805"
    },
    "fuel_prices": [
      {"fuel_type": "B7_STANDARD", "price": "0149.9000"},
      {"fuel_type": "E10", "price": "0145.9000"}
    ]
  }
]
```

## Monitoring

```bash
# Check timer status
sudo systemctl status fuelseeker-vpn-update.timer
sudo systemctl list-timers fuelseeker-vpn-update.timer

# View update logs
sudo tail -f /var/www/fuelseeker.net/data/update.log

# View errors
sudo tail -f /var/www/fuelseeker.net/data/update_error.log

# Check database
sqlite3 /var/www/fuelseeker.net/data/fuel_data.db "SELECT COUNT(*) FROM stations;"
```

## Important Notes

- **Install location:** Scripts must be in `/usr/local/bin/` (outside web root for security)
- **Non-UK servers:** You **cannot** run the PHP script directly due to geo-blocking. Use `fuel-update-with-vpn.sh` which connects to a UK VPN first.
- **CLI only:** These scripts must be run from command line (not web accessible)
- **Systemd timer:** Updates are controlled by systemd timer, not cron
- **Update frequency:** Runs 3x daily (06:00, 14:00, 22:00) for better price accuracy

## VPN & Network Notes

The `fuel-update-with-vpn.sh` wrapper handles several network issues automatically:

1. **Port Whitelisting** - Whitelists ports 22, 80, 443 before connecting VPN to prevent iptables from dropping inbound web traffic
2. **IPv6 Management** - Temporarily disables IPv6 during VPN to prevent browser timeouts, then restores it after via `systemctl restart networking`
3. **CPU Throttling** - Runs SQLite import with `nice -n 15` to reduce server impact

See `DATABASE-UPDATE-OPTIMIZATION.md` for detailed troubleshooting.

See `../INSTALL.md` for complete installation and systemd setup instructions.
