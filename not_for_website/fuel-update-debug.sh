#!/bin/bash
# Debug version of fuel update

LOG_FILE="/var/www/fuelseeker.net/data/cron.log"
ERROR_FILE="/var/www/fuelseeker.net/data/update_error.log"
FUEL_DIR="/var/www/fuelseeker.net"

echo "=== Debug Fuel Update ===" | tee -a "$LOG_FILE"

# Check if PHP is available
which php
echo "PHP Version: $(php -v | head -1)"

# Check if files exist
echo "Checking files..."
ls -la ./update_data.php
ls -la "$FUEL_DIR/data/"

# Check environment variables
echo "Checking environment..."
echo "OS_API_KEY: $(grep OS_API_KEY "$FUEL_DIR/scripts/.env" 2>/dev/null | head -1)"
echo "FUEL_CLIENT_ID: $(grep FUEL_CLIENT_ID "$FUEL_DIR/scripts/.env" 2>/dev/null | head -1)"

# Remove stale lock file if exists
if [ -f "$FUEL_DIR/data/update.lock" ]; then
    echo "Removing stale lock file..."
    rm -f "$FUEL_DIR/data/update.lock"
fi

# Run update with full error output
echo "Running update..."
cd "$FUEL_DIR"
php "$FUEL_DIR/scripts/update_data.php" 2>&1 | tee -a "$LOG_FILE"
EXIT_CODE=${PIPESTATUS[0]}

echo "Exit code: $EXIT_CODE"
echo "=== End Debug ===" | tee -a "$LOG_FILE"
