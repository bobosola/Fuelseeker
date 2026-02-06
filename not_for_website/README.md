# Not For Website

These files are used for updating the SQLite database from the Fuel API. They should be separately uploaded to a non-public facing location on the server (outside the web root).

## Files

- `update_data_safe.php` - Main update script with zero-downtime symlink swapping
- `schema.sql` - SQLite database schema
- `fuel-update-with-vpn.sh` - VPN wrapper script for non-UK servers
- `fuelseeker-vpn-update.service` - systemd service file
- `fuelseeker-vpn-update.timer` - systemd timer file (runs at 02:00 daily)
- `INSTALL-SYSTEMD.md` - Systemd installation instructions

## Important Notes

- **Paths may need checking and amending!** - Update the paths in these files to match your server setup
- The `update_data_safe.php` script must be run from CLI only (not web accessible)
- The script uses a two-file symlink system for zero-downtime updates
- Updates should be scheduled for 02:00 UK time to minimize server impact

See `../INSTALL.md` for complete installation and setup instructions.