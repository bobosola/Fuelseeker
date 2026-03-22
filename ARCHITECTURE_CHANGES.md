# Architecture Changes Summary

## Overview

The site has been restructured to use a **split architecture** where data updates are performed by a UK-based PC and deployed to the web server via HTTPS. This replaces the previous VPN-based approach.

## Why This Change?

The gov.uk Fuel Finder API is restricted to UK IP addresses. When the web server is outside the UK (e.g., Germany), the previous solution required:
- NordVPN CLI on the server
- Complex VPN connection management
- Risk of SSH disconnections
- Web server hanging during VPN connection

**New solution:** Use a UK-based PC to download the data and deploy it to the web server.

## New Architecture

```
┌─────────────────┐         HTTPS          ┌──────────────────┐
│   UK Home PC    │  ───────────────────►  │  Web Server      │
│  (Debian + PHP) │   POST database file   │  (Any location)  │
└─────────────────┘                        └──────────────────┘
       │                                           │
       │ Fuel Finder API (UK IP)                   │ Serves website
       ▼                                           ▼
  Downloads data                              SQLite Database
  Builds SQLite DB                            (Atomic swap)
```

## Directory Structure Changes

### New: `data_retrieval_server/`

This directory contains files for the UK PC:
- `deploy_to_remote_server.php` - Main deployment script
- `config.php` - Configuration loader
- `schema.sql` - SQLite database schema
- `.secrets.example` - Example secrets file
- `README.md` - UK PC setup instructions

### Removed: `not_for_website/`

The old `not_for_website/` directory has been removed. Its contents were:
- `update_data_streaming.php` - Now integrated into `deploy_to_remote_server.php`
- `fuel-update-with-vpn.sh` - No longer needed (VPN handled by UK PC location)
- `schema.sql` - Moved to `data_retrieval_server/`
- `DATABASE-UPDATE-OPTIMIZATION.md` - Concepts integrated into docs

### Updated: `scripts/`

- Added `db_deploy.php` - Receives database from UK PC via HTTPS
- Updated `.env` to include `DEPLOY_API_KEY` for authentication

## Documentation Updates

### Updated Files

1. **README.md**
   - Updated "How It Works" section to describe split architecture
   - Updated "Quick Start" for web server setup
   - Added UK PC automatic updates section
   - Updated file structure diagram

2. **AGENTS.md**
   - Updated project overview with architecture diagram
   - Updated file structure
   - Replaced VPN-based update instructions with UK PC deployment
   - Updated troubleshooting sections

3. **INSTALL.md**
   - Added "Recommended: UK PC Deployment" section at top
   - Marked VPN sections as "Legacy" and "Not Recommended"
   - Added UK PC deployment troubleshooting

4. **data_retrieval_server/README.md** (New)
   - Complete setup instructions for UK PC
   - Configuration guide
   - Automatic update setup (cron/systemd)
   - Troubleshooting

## Code Changes

### `data_retrieval_server/deploy_to_remote_server.php`

**Issues Fixed:**

1. **CSV column mismatch** - The original code wrote API fields directly to CSV, but the database schema expects different columns. Fixed by mapping API fields to schema columns correctly.

2. **Schema file path** - Changed from hardcoded `/not_for_website/schema.sql` to check `__DIR__ . '/schema.sql'` first.

3. **Configurable DEPLOY_URL** - Added support for `DEPLOY_URL` environment variable to allow custom domains.

4. **Config loading** - Improved error handling when `.env` file is missing.

### `data_retrieval_server/config.php`

**Fixed:**
- Removed `logMsg()` call when `.env` not found (function not defined yet at that point)
- Now writes to STDERR and exits cleanly

## Migration Guide

### For New Installations

1. **Web Server Setup:**
   ```bash
   # Upload website files
   # Create data/ directory
   # Configure scripts/.env with OS API credentials
   # Ensure DEPLOY_API_KEY is set
   ```

2. **UK PC Setup:**
   ```bash
   # Copy data_retrieval_server/ to UK PC
   cd data_retrieval_server/
   cp .env.example .env
   # Edit .env with Fuel API credentials and DEPLOY_API_KEY
   php deploy_to_remote_server.php
   ```

3. **Set up automatic updates on UK PC:**
   ```bash
   crontab -e
   # Add: 0 6,14,22 * * * cd /path/to/data_retrieval_server && php deploy_to_remote_server.php >> ../logs/deploy.log 2>&1
   ```

### For Existing Installations (VPN → UK PC)

1. **On Web Server:**
   ```bash
   # Disable VPN-based updates
   sudo systemctl stop fuelseeker-vpn-update.timer
   sudo systemctl disable fuelseeker-vpn-update.timer
   
   # Ensure db_deploy.php exists and .env has DEPLOY_API_KEY
   ```

2. **On UK PC:**
   ```bash
   # Copy data_retrieval_server/ files
   # Configure .env with matching DEPLOY_API_KEY
   # Test deployment
   php deploy_to_remote_server.php
   ```

3. **Verify:**
   ```bash
   curl https://your-domain.com/scripts/db_deploy.php?action=status
   ```

## Files Removed

The following files/directories are no longer needed and can be removed:

- `not_for_website/` directory (entirely)
- `/usr/local/bin/update_data_streaming.php` (from server)
- `/usr/local/bin/fuel-update-with-vpn.sh` (from server)
- systemd timer/service files (from server)

## Security Considerations

1. **DEPLOY_API_KEY** must be:
   - At least 64 characters (generate with `openssl rand -hex 32`)
   - Identical on UK PC and web server
   - Kept secret (in `.env` files, not committed to git)

2. **HTTPS Required** - Never deploy over HTTP

3. **File Validation** - Server validates:
   - SQLite magic bytes
   - File size (1-50MB)
   - API key authentication

## Troubleshooting

### UK PC Issues

**"Could not find .env file"**
- Ensure `.env` exists in `data_retrieval_server/` directory
- Check file permissions

**"Failed to get OAuth token"**
- Verify UK PC has UK IP address
- Check `FUEL_CLIENT_ID` and `FUEL_CLIENT_SECRET`

**"Invalid or missing deployment key"**
- Ensure `DEPLOY_API_KEY` matches on UK PC and web server

### Web Server Issues

**"File too large"**
- Increase PHP limits: `post_max_size = 20M`, `upload_max_filesize = 20M`

**Check deployment status:**
```bash
curl "https://your-domain.com/scripts/db_deploy.php?action=status"
```
