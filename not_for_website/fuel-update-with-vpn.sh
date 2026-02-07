#!/bin/bash
set -e

LOG_FILE="/var/www/fuelseeker.net/data/update.log"
UPDATE_SCRIPT="/usr/local/bin/update_data_safe.php"

log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $1" | tee -a "$LOG_FILE"
}

log "Starting fuel update..."

# Remove stale lock file if it exists
LOCK_FILE="/var/www/fuelseeker.net/data/update.lock"
if [ -f "$LOCK_FILE" ]; then
    log "Lock file exists - removing to allow this update"
    rm -f "$LOCK_FILE"
fi

# Whitelist SSH to prevent connection drop during VPN
log "Whitelisting SSH port..."
nordvpn whitelist add port 22 >/dev/null 2>&1 || true

# Connect VPN
log "Connecting to NordVPN UK server..."
nordvpn connect United_Kingdom
sleep 10

# Check connection
if ! nordvpn status | grep -q "Connected"; then
    log "ERROR: VPN not connected"
    nordvpn whitelist remove port 22 >/dev/null 2>&1 || true
    exit 1
fi

log "VPN connected successfully"

# Verify API is reachable
if ! curl -s --max-time 10 https://www.fuel-finder.service.gov.uk >/dev/null; then
    log "ERROR: Cannot reach fuel API"
    nordvpn disconnect >/dev/null 2>&1 || true
    nordvpn whitelist remove port 22 >/dev/null 2>&1 || true
    exit 1
fi

# Run update
log "Running update script..."
/usr/bin/php "$UPDATE_SCRIPT" 2>&1 | tee -a "$LOG_FILE"
UPDATE_STATUS=${PIPESTATUS[0]}

# Disconnect VPN
log "Disconnecting VPN..."
nordvpn disconnect >/dev/null 2>&1 || true
nordvpn whitelist remove port 22 >/dev/null 2>&1 || true

if [ $UPDATE_STATUS -eq 0 ]; then
    log "Update completed successfully"
else
    log "ERROR: Update failed with status $UPDATE_STATUS"
fi

exit $UPDATE_STATUS
