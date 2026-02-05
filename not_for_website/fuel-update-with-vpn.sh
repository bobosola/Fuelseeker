#!/bin/bash
# Update fuel database with UK VPN connection
# Run this on your Debian server

LOG_FILE="/var/www/fuelseeker.net/data/cron.log"
FUEL_DIR="/var/www/fuelseeker.net"
LOCK_FILE="$FUEL_DIR/data/update.lock"

# Function to log messages
log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $1" | tee -a "$LOG_FILE"
}

log "=== Starting Fuel Update ==="

# Remove stale lock file if it exists (from previous crashed run)
if [ -f "$LOCK_FILE" ]; then
    log "Lock file exists - removing to allow this update"
    rm -f "$LOCK_FILE"
fi

# Check if we can reach the API without VPN (test actual API endpoint)
log "Testing API access without VPN..."
API_TEST=$(curl -s -o /dev/null -w "%{http_code}" --max-time 10 "https://www.fuel-finder.service.gov.uk/api/v1/pfs?batch-number=1" 2>/dev/null)
log "API test HTTP code: $API_TEST"

if [ "$API_TEST" = "200" ]; then
    log "API accessible without VPN (you may not need VPN)"
    VPN_NEEDED=false
else
    log "API not accessible without VPN (HTTP $API_TEST) - need VPN"
    VPN_NEEDED=true
fi

# Only use VPN if needed
if [ "$VPN_NEEDED" = true ]; then
    log "Whitelisting SSH port to prevent connection drop..."
    nordvpn whitelist add port 22
    
    log "Connecting to NordVPN UK server..."
    nordvpn connect United_Kingdom
    
    if [ $? -ne 0 ]; then
        log "ERROR: Failed to connect to VPN"
        exit 1
    fi
    
    # Wait for connection to establish
    log "Waiting for VPN connection..."
    sleep 10
    
    # Verify connection
    if ! nordvpn status | grep -q "Connected"; then
        log "ERROR: VPN not connected"
        exit 1
    fi
    
    log "VPN connected successfully"
fi

# Run the update
log "Running fuel data update..."
cd "$FUEL_DIR"

# Capture both stdout and stderr
UPDATE_OUTPUT=$(/usr/bin/php ./update_data.php 2>&1)
UPDATE_STATUS=$?

# Log the output
log "Update output:"
echo "$UPDATE_OUTPUT" | while read line; do
    log "  $line"
done

if [ $UPDATE_STATUS -eq 0 ]; then
    log "Update completed successfully"
else
    log "ERROR: Update failed with status $UPDATE_STATUS"
fi

# Disconnect VPN if we connected
if [ "$VPN_NEEDED" = true ]; then
    log "Disconnecting VPN..."
    echo "n" | nordvpn disconnect > /dev/null 2>&1
    
    log "Removing SSH whitelist..."
    nordvpn whitelist remove port 22 > /dev/null 2>&1
fi

log "=== Update Process Complete ==="
exit $UPDATE_STATUS
