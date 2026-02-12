#!/bin/bash
# Fuel Data Update with VPN Wrapper
# 
# Features:
# - Auto-connects NordVPN UK for API access (required for non-UK servers)
# - Whitelists ports 22, 80, 443 to prevent web server from hanging
# - Temporarily disables IPv6 to prevent connection timeouts
# - Uses nice CPU priority to reduce server impact
# - Streaming import with minimal memory usage (~10MB)
#
# Requirements:
# - nordvpn CLI installed and configured
# - sqlite3 CLI installed
# - /var/www/fuelseeker.net/data/ directory writable

set -e

LOG_FILE="/var/www/fuelseeker.net/data/update.log"
# Use the streaming version that doesn't accumulate data in memory
UPDATE_SCRIPT="/usr/local/bin/update_data_streaming.php"

log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $1" | tee -a "$LOG_FILE"
}

log "=========================================="
log "Starting fuel update (streaming method)..."

# Check available memory before starting
log "Memory before: $(free -h | grep Mem | awk '{print $7}') available"

# Remove stale lock file if it exists
LOCK_FILE="/var/www/fuelseeker.net/data/update.lock"
if [ -f "$LOCK_FILE" ]; then
    LOCK_AGE=$(($(date +%s) - $(stat -c %Y "$LOCK_FILE" 2>/dev/null || echo 0)))
    log "Lock file exists (age: ${LOCK_AGE}s) - removing to allow this update"
    rm -f "$LOCK_FILE"
fi

# Save original gateway for routing fix
GATEWAY="172.31.1.1"
SERVER_IP=$(curl -s --max-time 5 ifconfig.me 2>/dev/null || echo "")

# Whitelist SSH and web ports to prevent connection drop during VPN
log "Whitelisting ports (22, 80, 443)..."
nordvpn whitelist add port 22 >/dev/null 2>&1 || true
nordvpn whitelist add port 80 >/dev/null 2>&1 || true
nordvpn whitelist add port 443 >/dev/null 2>&1 || true

# Disable IPv6 to prevent connection hangs (VPN disables it anyway)
log "Disabling IPv6..."
sysctl -w net.ipv6.conf.all.disable_ipv6=1 >/dev/null 2>&1 || true
sysctl -w net.ipv6.conf.default.disable_ipv6=1 >/dev/null 2>&1 || true
sysctl -w net.ipv6.conf.eth0.disable_ipv6=1 >/dev/null 2>&1 || true

# Connect VPN
log "Connecting to NordVPN UK server..."
nordvpn connect United_Kingdom
sleep 10

# Fix routing: ensure web traffic responses go out eth0, not VPN
# This prevents Caddy from hanging while VPN is connected
if [ -n "$SERVER_IP" ]; then
    log "Fixing routing for web server (IP: $SERVER_IP)..."
    ip rule add from $SERVER_IP table 100 2>/dev/null || true
    ip route add default via $GATEWAY dev eth0 table 100 2>/dev/null || true
fi

# Check connection
if ! nordvpn status | grep -q "Connected"; then
    log "ERROR: VPN not connected"
    nordvpn whitelist remove port 22 >/dev/null 2>&1 || true
    exit 1
fi

log "VPN connected successfully"
IP=$(curl -s --max-time 10 https://ipinfo.io/ip 2>/dev/null || echo "unknown")
log "Current IP: $IP"

# Verify API is reachable
log "Checking API reachability..."
if ! curl -s --max-time 15 https://www.fuel-finder.service.gov.uk >/dev/null; then
    log "ERROR: Cannot reach fuel API"
    nordvpn disconnect >/dev/null 2>&1 || true
    nordvpn whitelist remove port 22 >/dev/null 2>&1 || true
    exit 1
fi
log "API is reachable"

# Run update with timeout protection (max 10 minutes)
log "Running update script..."
timeout 300 nice -n 10 /usr/bin/php "$UPDATE_SCRIPT" 2>&1 | tee -a "$LOG_FILE"
UPDATE_STATUS=${PIPESTATUS[0]}

# Check if timeout killed it
if [ $UPDATE_STATUS -eq 124 ]; then
    log "ERROR: Update timed out after 10 minutes"
fi

# Disconnect VPN
log "Disconnecting VPN..."
nordvpn disconnect >/dev/null 2>&1 || true
nordvpn whitelist remove port 22 >/dev/null 2>&1 || true
nordvpn whitelist remove port 80 >/dev/null 2>&1 || true
nordvpn whitelist remove port 443 >/dev/null 2>&1 || true

# Remove routing fix
if [ -n "$SERVER_IP" ]; then
    ip rule del from $SERVER_IP table 100 2>/dev/null || true
    ip route del default via $GATEWAY dev eth0 table 100 2>/dev/null || true
fi

# Re-enable IPv6 and restore network config (Hetzner fix)
log "Re-enabling IPv6..."
sysctl -w net.ipv6.conf.all.disable_ipv6=0 >/dev/null 2>&1 || true
sysctl -w net.ipv6.conf.default.disable_ipv6=0 >/dev/null 2>&1 || true
sysctl -w net.ipv6.conf.eth0.disable_ipv6=0 >/dev/null 2>&1 || true

# Restart networking to restore static IPv6 config (required for Hetzner)
# This system uses traditional Debian /etc/network/interfaces
systemctl restart networking >/dev/null 2>&1 || true
sleep 3

# Check memory after
log "Memory after: $(free -h | grep Mem | awk '{print $7}') available"

if [ $UPDATE_STATUS -eq 0 ]; then
    log "Update completed successfully"
else
    log "ERROR: Update failed with status $UPDATE_STATUS"
fi

log "=========================================="
exit $UPDATE_STATUS
