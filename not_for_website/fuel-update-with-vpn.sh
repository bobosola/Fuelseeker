#!/bin/bash
export FUELSEEKER_SCRIPT_DIR=/var/www/fuelseeker.net/scripts
export FUELSEEKER_DATA_DIR=/var/www/fuelseeker.net/data

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

# Track VPN state for cleanup
VPN_WAS_CONNECTED=false

log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $1" | tee -a "$LOG_FILE"
}

# Cleanup function - ALWAYS runs on exit to restore network
cleanup() {
    local exit_code=$?
    log "Running cleanup (exit code: $exit_code)..."
    
    # Disconnect VPN if we connected it
    if [ "$VPN_WAS_CONNECTED" = "true" ]; then
        log "Disconnecting VPN..."
        nordvpn disconnect >/dev/null 2>&1 || true
    fi
    
    # Always remove whitelists
    nordvpn whitelist remove port 22 >/dev/null 2>&1 || true
    nordvpn whitelist remove port 80 >/dev/null 2>&1 || true
    nordvpn whitelist remove port 443 >/dev/null 2>&1 || true
    
    # Remove routing fix
    if [ -n "$SERVER_IP" ]; then
        ip rule del from $SERVER_IP table 100 2>/dev/null || true
        ip route del default via $GATEWAY dev eth0 table 100 2>/dev/null || true
    fi
    
    # ALWAYS re-enable IPv6 - critical for server connectivity
    log "Re-enabling IPv6..."
    sysctl -w net.ipv6.conf.all.disable_ipv6=0 >/dev/null 2>&1 || true
    sysctl -w net.ipv6.conf.default.disable_ipv6=0 >/dev/null 2>&1 || true
    sysctl -w net.ipv6.conf.eth0.disable_ipv6=0 >/dev/null 2>&1 || true
    
    # Restart networking to restore static IPv6 config
    systemctl restart networking >/dev/null 2>&1 || true
    
    log "Cleanup complete."
    exit $exit_code
}

# Set trap to run cleanup on ANY exit (success, error, or interrupt)
trap cleanup EXIT INT TERM ERR

log "=========================================="
log "Starting fuel update (streaming method)..."

# Check if running from interactive SSH session
if [ -n "$SSH_CLIENT" ] && [ "$TERM" != "screen" ] && [ "$TERM" != "tmux-256color" ] && [ -z "$NOHUP" ]; then
    log "WARNING: Running from SSH session. VPN connection will disconnect SSH."
    log "The update will continue, but you may lose connection."
    log "To avoid this, use: sudo nohup $0 > /tmp/update.log 2>&1 &"
    log "Waiting 5 seconds... (Ctrl+C to cancel and use nohup instead)"
    sleep 5
fi

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

# Whitelist SSH and web ports BEFORE doing anything else
# This MUST be done before VPN connects to prevent SSH lockout
log "Whitelisting ports (22, 80, 443)..."
nordvpn whitelist add port 22 >/dev/null 2>&1 || true
nordvpn whitelist add port 80 >/dev/null 2>&1 || true
nordvpn whitelist add port 443 >/dev/null 2>&1 || true

# Verify whitelisting worked
if ! nordvpn settings | grep -q "22 (UDP|TCP)"; then
    log "WARNING: Port 22 whitelist may not have applied correctly"
    log "Current settings:"
    nordvpn settings | grep -i whitelist | tee -a "$LOG_FILE" || true
fi

# Connect VPN
log "Connecting to NordVPN UK server..."
nordvpn connect United_Kingdom
sleep 10

# NOW disable IPv6 (after VPN is connected, so SSH stays on IPv4)
# This prevents hangs when browsers try IPv6 first
log "Disabling IPv6..."
sysctl -w net.ipv6.conf.all.disable_ipv6=1 >/dev/null 2>&1 || true
sysctl -w net.ipv6.conf.default.disable_ipv6=1 >/dev/null 2>&1 || true
sysctl -w net.ipv6.conf.eth0.disable_ipv6=1 >/dev/null 2>&1 || true

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
    exit 1
fi

VPN_WAS_CONNECTED=true
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

# Run update with timeout protection (max 15 minutes)
log "Running update script..."

# Create a status file that PHP can write to
STATUS_FILE="/tmp/fuel_update_exit_$$"
echo "1" > "$STATUS_FILE"

# Run the PHP script with real-time output to both console and log
# PHP will write exit code to status file at the end
timeout 900 nice -n 10 /usr/bin/php -d output_buffering=Off "$UPDATE_SCRIPT" "$STATUS_FILE" 2>&1 | tee -a "$LOG_FILE"

# Get exit status from file (PHP will write it, or timeout leaves it as 124)
UPDATE_STATUS=$(cat "$STATUS_FILE" 2>/dev/null || echo "1")
rm -f "$STATUS_FILE"

# Check if timeout killed it (exit code 124 means timeout)
if [ "$UPDATE_STATUS" = "124" ]; then
    log "ERROR: Update timed out after 15 minutes"
fi

# Note: VPN disconnection, IPv6 re-enable, and cleanup are handled by the EXIT trap
# This ensures cleanup happens even if the script fails or is interrupted

# Check memory after
log "Memory after: $(free -h | grep Mem | awk '{print $7}') available"

if [ "$UPDATE_STATUS" = "0" ]; then
    log "Update completed successfully"
else
    log "ERROR: Update failed with status $UPDATE_STATUS"
fi

log "=========================================="
exit $UPDATE_STATUS
