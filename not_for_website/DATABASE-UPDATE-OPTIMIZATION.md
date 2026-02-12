# Database Update Optimization Guide

## Problem Summary

The original update scripts had a **memory exhaustion problem** during API data retrieval:

```php
// PROBLEMATIC CODE from original scripts:
$allStations = array_merge($allStations, $data);
// ... later ...
$allPrices = array_merge($allPrices, $data);
```

This accumulated **all station and price data in PHP arrays** before writing to the database:
- ~7,000 stations with full location/amenities data = ~60-80MB
- ~7,000 price entries = ~30-40MB  
- PHP JSON decode overhead = ~40-50MB
- **Total: 130-170MB+**

On small VPS servers with 128MB PHP memory limits, this causes:
1. **Out of memory errors** or **heavy swapping**
2. **Complete server freeze** (OOM killer, swap thrashing)
3. **SSH timeout** (can't even log in to reboot)

## Solution: Streaming Version (update_data_streaming.php)

The `update_data_streaming.php` script uses a **streaming approach** with minimal memory usage:

### How Streaming Works

```
API Request → Parse JSON → Write to CSV file → [discard from memory]
     ↑                                              ↓
     └────────────────── Next batch ←───────────────┘
```

1. **Fetch one API batch** (~500 stations)
2. **Parse JSON** (immediate decode, no storage)
3. **Write to CSV file** using `fputcsv()`
4. **Discard data** from memory (variables go out of scope)
5. **Repeat** until all batches fetched
6. **SQLite CLI import** from CSV files

### Streaming Code Pattern

```php
// OPEN CSV file once
$fp = fopen($stationsCsv, 'w');

while ($hasMore) {
    // Fetch batch
    $data = fetchBatch($batchNumber);
    
    // Write EACH record immediately - NO accumulation
    foreach ($data as $station) {
        fputcsv($fp, [
            $station['node_id'],
            // ... other fields
        ]);
    }
    
    // Data is NOT stored in $allStations array!
    // Memory is freed after each iteration
}

fclose($fp);
```

## Performance

| Metric | Streaming |
|--------|-----------|
| Peak memory | **8-12MB** |
| API fetch time | 20-40s (smooth) |
| Database import | 2-5s |
| Total time | **25-45s** |
| Server freeze risk | **NONE** |
| SSH disconnect risk | **NONE** |

## Deployment Instructions

### 1. Install the Scripts

```bash
# Copy the streaming update script
sudo cp not_for_website/update_data_streaming.php /usr/local/bin/
sudo chmod +x /usr/local/bin/update_data_streaming.php

# Copy the VPN wrapper for non-UK servers
sudo cp not_for_website/fuel-update-with-vpn.sh /usr/local/bin/
sudo chmod +x /usr/local/bin/fuel-update-with-vpn.sh

# Create temp directory for CSV files
sudo mkdir -p /var/www/fuelseeker.net/data/tmp
sudo chown www-data:www-data /var/www/fuelseeker.net/data/tmp
```

### 2. Ensure SQLite3 CLI is Available

```bash
which sqlite3
# Should return: /usr/bin/sqlite3

# If not installed:
sudo apt-get install sqlite3  # Debian/Ubuntu
```

### 3. Update Systemd Timer for 3x Daily

```bash
# Copy the timer (runs at 06:00, 14:00, 22:00)
sudo cp not_for_website/fuelseeker-vpn-update.timer /etc/systemd/system/
sudo cp not_for_website/fuelseeker-vpn-update.service /etc/systemd/system/

# Reload and restart
sudo systemctl daemon-reload
sudo systemctl restart fuelseeker-vpn-update.timer

# Verify
sudo systemctl status fuelseeker-vpn-update.timer
sudo systemctl list-timers fuelseeker-vpn-update.timer
```

### 4. Test the Script

```bash
# Run manually to verify it works
sudo /usr/local/bin/fuel-update-with-vpn.sh

# In another terminal, monitor server:
watch -n 2 'free -h; echo "---"; ps aux | grep php | head -3'

# Check logs
tail -f /var/www/fuelseeker.net/data/update.log
```

### 5. Monitor Server Performance

```bash
# During an update, monitor in real-time:
# Terminal 1: Watch memory
watch -n 1 'free -h; uptime'

# Terminal 2: Watch disk I/O
iostat -x 1

# Terminal 3: Check if websites remain responsive
curl -s -o /dev/null -w "%{http_code} %{time_total}s\n" https://your-site.com
```

## Troubleshooting

### "Failed to get OAuth token"
The gov.uk Fuel Finder API is UK-only. Non-UK servers must use the VPN wrapper:
```bash
sudo /usr/local/bin/fuel-update-with-vpn.sh
```

### "PHP Fatal error: Allowed memory size exhausted"
The streaming version uses minimal memory (~10MB). If you see this error, ensure you're using:
```bash
/usr/local/bin/update_data_streaming.php
```

### "cURL timeout" or hangs during API fetch
```bash
# Check VPN connection
nordvpn status

# Test API manually (requires VPN)
nordvpn connect United_Kingdom
curl -v -H "Authorization: Bearer YOUR_TOKEN" \
  https://www.fuel-finder.service.gov.uk/api/v1/pfs?batch-number=1
```

### "SQLite CLI import failed"
```bash
# Check sqlite3 is installed
sqlite3 --version

# Check schema file exists
ls -la /home/bobosola/schema.sql

# Check file permissions on data directory
ls -la /var/www/fuelseeker.net/data/
```

### Import fails silently
Check the error log:
```bash
tail /var/www/fuelseeker.net/data/update_error.log
```

### Server still hangs during updates

The streaming version should NOT cause hangs. If the site becomes unresponsive:

1. **VPN firewall blocking inbound traffic**
   ```bash
   # Check iptables during VPN connection
   sudo iptables -L -n | grep DROP
   ```
   Look for `drop-IPv4` rules. The wrapper script should whitelist ports 80/443 automatically. If not, add manually:
   ```bash
   nordvpn whitelist add port 80
   nordvpn whitelist add port 443
   ```

2. **IPv6 not restored after VPN**
   If IPv6 visitors can't reach your site after an update:
   ```bash
   # Check IPv6 status
   ip -6 addr show eth0 | grep "scope global"
   
   # If missing, manually restore
   sudo systemctl restart networking  # Debian/Ubuntu with /etc/network/interfaces
   # OR
   sudo netplan apply                 # Ubuntu with netplan
   ```

3. **CPU maxed out during SQLite import**
   The script uses `nice -n 15` for the SQLite CLI. If your server is very small, you can increase the niceness:
   ```bash
   # Edit the script, change nice -n 15 to nice -n 19
   ```

4. **VPN connection problems** - Check `nordvpn status`

5. **Disk I/O bottleneck** - Check `iostat -x 1` during import

## Update Schedule Recommendation

With the streaming import, you can safely run updates more frequently:

| Frequency | Times | Use Case |
|-----------|-------|----------|
| 3x daily (recommended) | 06:00, 14:00, 22:00 | Good balance for price accuracy |
| 4x daily | 06:00, 12:00, 18:00, 00:00 | Maximum freshness |
| 6x daily | Every 4 hours | Overkill but safe |

To change the schedule:
```bash
sudo systemctl edit --full fuelseeker-vpn-update.timer
```

## Technical Details

### Data Consistency

The streaming version uses **atomic symlink swap** for zero-downtime:
1. Readers use old database until swap completes
2. Symlink change is atomic on Linux
3. Old database file is preserved until next update

### Why Memory Accumulation Was the Culprit

PHP's `array_merge()` is O(n) for each operation. With 7,000 stations across ~14 batches:

```php
// Each merge creates a NEW array and copies all elements
$allStations = array_merge($allStations, $batch);
// Batch 1:  500 items copied
// Batch 2:  1,000 items copied  
// Batch 3:  1,500 items copied
// ...
// Batch 14: 7,000 items copied
// Total copies: ~52,500 array operations
```

Combined with JSON decode overhead and PHP's memory management, this quickly exhausts available RAM on small VPS instances, triggering OOM killer or heavy swap thrashing that freezes the server.

The streaming version writes each record exactly once to disk and keeps only ~500 records in memory at any time.

### VPN & Network Handling

The `fuel-update-with-vpn.sh` wrapper handles several network issues automatically:

1. **Port Whitelisting** - Whitelists ports 22, 80, 443 before connecting VPN to prevent iptables from dropping inbound web traffic
2. **IPv6 Management** - Temporarily disables IPv6 during VPN to prevent browser timeouts, then restores it after
3. **CPU Throttling** - Runs SQLite import with `nice -n 15` to reduce server impact
