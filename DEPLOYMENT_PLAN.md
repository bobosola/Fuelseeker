# FuelSeeker Deployment Plan: UK PC to Remote Web Server

## Goal
Replace unreliable VPN-based API access with a dedicated UK-based PC that downloads fuel data and deploys via HTTPS to a webserver outside the UK. This is required because the fuel data is geo-blocked for non-UK users.

## Architecture Overview

```
┌─────────────────┐         HTTPS          ┌──────────────────┐
│   UK Home PC    │  ───────────────────►  │  Hetzner Server  │
│  (Debian + PHP) │   POST database file   │   (Finland)      │
└─────────────────┘                        └──────────────────┘
       │                                           │
       │ Fuel Finder API (UK IP, no VPN needed)    │ Serves website
       ▼                                           ▼
  Downloads data                              SQLite Database
  Builds SQLite DB                            (Atomic swap)
```

## Why HTTPS Upload (as opposed to scp, rsync etc.)

**Pros:**
- No SSH key passphrase complications for automation
- No ssh-agent required on UK PC
- Single request uploads file AND triggers swap
- Works through NAT/firewalls
- Easy to debug and retry
- Uses existing project's PHP infrastructure

**Cons:**
- No resume capability (must retry full upload on failure)
- Requires PHP upload limit configuration on server

## Files to Create

### On Hetzner Server (Live)

**File:** `scripts/db_deploy.php`
- Receives HTTPS POST with:
  - `X-Deploy-Key` header for authentication
  - `database` multipart file upload
- Validates:
  - API key matches environment variable
  - File is valid SQLite (magic bytes check)
  - File size is reasonable (1-50MB)
- Performs atomic symlink swap:
  - Determines target version (v1 or v2) based on current symlink
  - Saves uploaded file to inactive version
  - Atomically switches symlink
  - Logs deployment
- Returns JSON success/error response

**Server Configuration Changes:**
- Add to `.secrets`: `DEPLOY_API_KEY=<random-64-char-hex>`
- Set PHP upload limits:
  - `post_max_size = 20M`
  - `upload_max_filesize = 20M`
  - `max_execution_time = 120`

### On UK PC

**File:** `data_retrieval_server/deploy_to_remote_server.php`
- Builds database
- Determines which version was just built (v1 or v2)
- Reads API key from `.secrets`
- Uploads via HTTPS POST with curl:
  - Uses `CURLFile` for multipart upload
  - Sets 120-second timeout
  - Verifies SSL certificate
- Handles response:
  - Success: logs deployment, cleanup
  - Failure: retries up to 3 times with exponential backoff
  - Logs all attempts locally

**File:** `~/.secrets`
- Contains the same 64-character API key as server's `.secrets`

## Security Considerations

1. **API Key Generation:**
   ```bash
   openssl rand -hex 32
   # or
   uuidgen | sha256sum | head -c 64
   ```

2. **API Key Storage:**
   - Server & UK PC: `.secrets` file (existing, already secure)

3. **HTTPS Required:**
   - Never send API key or database over HTTP
   - curl verifies SSL certificate by default
   - Server must have valid SSL cert (Let's Encrypt OK)

4. **File Validation:**
   - Check SQLite magic bytes (`SQLite format 3\0`)
   - Verify file size bounds (1-50MB)
   - Use `move_uploaded_file()` (safer than manual copy)

5. **Rate Limiting (Optional Enhancement):**
   - Track deployment timestamps
   - Reject if < 5 minutes between requests (prevents accidental loops)

6. **IP Whitelisting (Optional Enhancement):**
   - If UK PC has static IP, add check:
     ```php
     $allowed_ips = ['203.0.113.50']; // Your home IP
     if (!in_array($_SERVER['REMOTE_ADDR'], $allowed_ips)) {
         http_response_code(403);
         exit;
     }
     ```

## Error Handling Strategy

### UK PC Side

1. **Build Failure:**
   - Log error locally
   - Exit without attempting upload
   - Do not retry (API issue, wait for next scheduled run)

2. **Upload Failure:**
   - Retry up to 3 times with delays: 5s, 15s, 45s
   - Log each attempt
   - If all fail, alert (email/log) for manual intervention

3. **Network Timeout:**
   - curl timeout: 120 seconds (enough for 13MB on slow upload)
   - Consider split: upload file (separate request) then trigger swap

### Server Side

1. **Invalid Key:**
   - HTTP 403
   - Log attempt with IP (security monitoring)
   - No database operations

2. **Invalid File:**
   - HTTP 400
   - Delete temp file
   - Log validation failure reason

3. **Swap Failure:**
   - HTTP 500
   - Ensure old symlink still valid (don't leave system broken)
   - Log detailed error

## Setup Steps

### Phase 1: Server (Hetzner)

1. Generate API key:
   ```bash
   openssl rand -hex 32
   ```

2. Add to `/var/www/fuelseeker.net/scripts/.secrets`:
   ```bash
   DEPLOY_API_KEY=abc123...def456
   ```

3. Create `scripts/db_deploy.php` (see file details above)

4. Configure PHP upload limits (nginx/Apache/php-fpm as appropriate)

5. Test endpoint manually:
   ```bash
   curl -X POST -H "X-Deploy-Key: test" https://fuelseeker.net/scripts/db_deploy.php
   # Should get 403 (invalid key)
   ```

### Phase 2: UK PC

1. Install PHP and required extensions:
   ```bash
   sudo apt update
   sudo apt install php-cli php-curl php-sqlite3 sqlite3
   ```

2. Clone/pull fuelseeker repository

3. Add to local PC script folder `.secrets`:
   ```bash
   DEPLOY_API_KEY=abc123...def456
   ```

4. Create `data_retrieval_server/deploy_to_remote_server.php`

5. Test manually:
   ```bash
   php data_retrieval_server/deploy_to_remote_server.php
   ```

### Phase 3: Transition

1. Disable VPN-based updates on Hetzner server:
   ```bash
   sudo systemctl stop fuelseeker-vpn-update.timer
   sudo systemctl disable fuelseeker-vpn-update.timer
   ```

2. Monitor UK PC logs for first few runs

3. Verify live site still updates (check `data/update.log` on server)

4. After 24 hours of stable operation, remove VPN scripts from server

## Rollback Plan

If UK PC approach fails:

1. Re-enable VPN timer on server:
   ```bash
   sudo systemctl enable fuelseeker-vpn-update.timer
   sudo systemctl start fuelseeker-vpn-update.timer
   ```

## Monitoring & Alerting (Future Enhancement)

Consider adding:

1. **Health check endpoint:**
   - `GET /scripts/db_deploy.php?action=status`
   - Returns last deployment time, current DB version

2. **Email alerts on failure:**
   - UK PC sends email if 3 retries fail
   - Server sends email if no deployment in 12 hours

3. **Slack/Discord webhook:**
   - Post success/failure notifications

## File Size Estimate

- Current database: ~13MB (compressed SQLite)
- Upload time: 10-15 seconds on 10 Mbps upload
- Memory usage on server during upload: ~20MB (PHP temp file handling)
- Swap operation: < 1 second (atomic symlink change)

## Success Criteria

- [ ] UK PC successfully builds database without VPN
- [ ] HTTPS upload completes in < 2 minutes
- [ ] Server performs atomic swap correctly
- [ ] Website serves new data after swap
- [ ] Failed uploads retry automatically
- [ ] Zero-downtime maintained during swaps
- [ ] Rollback to VPN method possible if needed

---

## Next Steps

Decide:
1. Confirm this plan meets your requirements
2. Choose whether to implement IP whitelisting
3. Choose retry strategy (3 attempts with backoff vs other)
4. Then write the code

Ready to proceed with implementation?
