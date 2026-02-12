# Fuelseeker.net - Agent Documentation

## Project Overview

Fuelseeker.net is a fast, lightweight web application for finding fuel stations and comparing petrol and diesel prices across the UK. Unlike typical apps that query an API on every search, it uses a local SQLite database with ~7,000 UK fuel stations for instant results.

**Key Characteristics:**
- Zero external dependencies for runtime (no npm, webpack, etc.)
- Pure vanilla JavaScript (ES9/ES2018+) with ES6 modules
- Plain CSS3 with CSS variables
- PHP 7.4+ backend for API proxying and database management
- SQLite for local data caching
- Updated once daily at 02:00 via systemd timer (with VPN auto-connect for non-UK servers)

## Technology Stack

### Frontend
- **HTML5** - Semantic markup, single-page static files
- **JavaScript** - Vanilla ES9 (ES2018+), ES6 modules with import maps
- **CSS3** - Plain CSS with custom properties (variables)
- **Leaflet.js** - Interactive maps (loaded from CDN)
- **Proj4js** - Coordinate system conversion for UK National Grid (loaded from CDN)

### Backend
- **PHP 7.4+** - Server-side API proxying and database updates
- **SQLite** - Local database via PDO
- **cURL** - HTTP client for external APIs

### External APIs
- **gov.uk Fuel Finder API** - Fuel station data and prices (OAuth2 authenticated)
- **Ordnance Survey Names API** - Geocoding for postcodes and place names (OAuth2 authenticated)

### Data Source Attribution
- Fuel prices: Crown copyright, Open Government Licence v3.0
- Maps: OpenStreetMap contributors
- Geocoding: Ordnance Survey

## Project Structure

```
fuel/
├── index.html              # Home page with search form
├── map.html                # Results page with map and price table
├── about.html              # About page
├── css/
│   └── styles.css          # Main stylesheet with CSS variables
├── js/
│   ├── api.js              # API calls (local SQLite, OS geocoding)
│   ├── index.js            # Home page logic (search, geolocation)
│   ├── map.js              # Map page logic (Leaflet, table sorting)
│   └── utils.js            # Utility functions (distance calc, formatting)
├── scripts/                # PHP backend (API credentials required)
│   ├── config.php          # Configuration loader (.env file support)
│   ├── local_api.php       # Fast local API endpoints (SQLite queries)
│   ├── api_proxy.php       # Fuel Finder API proxy with CSRF protection
│   ├── token.php           # CSRF token handler
│   └── os_token.php        # Ordnance Survey OAuth proxy
├── data/                   # SQLite database (created at runtime, gitignored)
│   ├── fuel_data.db        # Symlink to active database file
│   ├── fuel_data.db.v1     # Actual database file (alternating)
│   ├── fuel_data.db.v2     # Actual database file (alternating)
│   ├── update.log          # Cron job logs
│   └── update_error.log    # Error logs
├── Docs/                   # API documentation (gov.uk Fuel Finder)
├── not_for_website/        # Deployment scripts and database schema
│   ├── schema.sql          # SQLite database schema
│   ├── update_data_streaming.php # **RECOMMENDED** Streaming update (low memory, no hangs)
│   ├── DATABASE-UPDATE-OPTIMIZATION.md # Performance optimization guide
│   ├── fuel-update-with-vpn.sh # VPN wrapper for update script
│   ├── fuelseeker-vpn-update.service # systemd service file
│   └── fuelseeker-vpn-update.timer   # systemd timer file (runs 3x daily)
└── errors/                 # Error page templates
```

## Configuration

### Environment Variables (`.env` file)

Create `.env` in the `scripts/` directory (NOT in git):

```bash
# Fuel Finder API credentials (from https://www.fuel-finder.service.gov.uk)
FUEL_CLIENT_ID=your_client_id_here
FUEL_CLIENT_SECRET=your_client_secret_here

# Ordnance Survey API credentials (from https://osdatahub.os.uk/)
OS_API_KEY=your_api_key_here
OS_API_SECRET=your_api_secret_here
```

The `.env` file is automatically loaded by `scripts/config.php`.

### File Permissions

```bash
# Data directory must be writable by web server
chmod 755 data
chmod 666 data/fuel_data.db

# PHP scripts
chmod 644 scripts/*.php
```

## Build and Deployment

### No Build Step Required

This project has **no build process**. Files are deployed as-is to the web server.

### Initial Setup

1. **Upload files** to web server (e.g., `/var/www/fuel/`)
2. **Create `.env` file** in `scripts/` with API credentials
3. **Create data directory**:
   ```bash
   mkdir data
   chmod 755 data
   ```
4. **Run initial data population**:
   ```bash
   # Copy to system location:
   sudo cp not_for_website/update_data_streaming.php /usr/local/bin/
   sudo cp not_for_website/fuel-update-with-vpn.sh /usr/local/bin/
   
   # Run update (non-UK servers must use VPN wrapper):
   sudo /usr/local/bin/fuel-update-with-vpn.sh
   ```

### Database Updates

The fuel prices change throughout the day. With the streaming import method, the database can be updated **3 times daily** without server impact:

```bash
# Systemd timer runs: /usr/local/bin/fuel-update-with-vpn.sh
# Default schedule: 06:00, 14:00, 22:00 (every 8 hours)
```

**Update Scripts:**
- **`not_for_website/update_data_streaming.php`** (RECOMMENDED): Streaming CSV import with minimal memory (~10MB) and CPU throttling. No server hangs.
- **`not_for_website/fuel-update-with-vpn.sh`**: VPN wrapper that handles NordVPN connection, port whitelisting, and IPv6 management automatically.

**Update frequency options:**
- **3x daily (recommended)**: 06:00, 14:00, 22:00 - Good price accuracy
- **4x daily**: Every 6 hours - Maximum freshness
- **Custom**: Edit the systemd timer with `systemctl edit`

**Important:** The gov.uk Fuel Finder API is **UK-only**. Non-UK servers will get HTTP 403.
Solutions:
1. Run updates from a UK-based computer, copy database to server
2. Use NordVPN CLI on the server (see `not_for_website/fuel-update-with-vpn.sh`)
3. Use a UK proxy server
4. Run a UK-based VPS solely for updates

See `INSTALL.md` for detailed VPN setup instructions.

See `not_for_website/DATABASE-UPDATE-OPTIMIZATION.md` for performance details and troubleshooting.

### Zero-Downtime Updates (Production)

The streaming update script uses **atomic symlink swap** for zero-downtime:

```bash
# Database file structure
# fuel_data.db -> symlink (points to v1 or v2)
# fuel_data.db.v1 -> actual database file
# fuel_data.db.v2 -> actual database file

# Each update builds to the INACTIVE file, then atomically swaps the symlink
# This allows existing PHP connections to finish reading from the old file
```

**Streaming Update Benefits:**

| Metric | Value |
|--------|-------|
| Method | Streaming CSV + SQLite CLI |
| Speed | ~25-45s total |
| Memory | ~10MB (constant) |
| Server impact | None (no hangs) |

The streaming version writes data directly to CSV during API fetch, avoiding memory exhaustion that can hang small VPS servers. See `not_for_website/DATABASE-UPDATE-OPTIMIZATION.md` for details.

## Code Style Guidelines

### JavaScript
- Use ES6 modules (`import`/`export`)
- Use `const` and `let`, never `var`
- Use arrow functions for callbacks
- Use async/await for asynchronous code
- Use template literals for string interpolation
- Add JSDoc comments for function documentation
- Target browsers from 2020 onwards (ES9/ES2018+)

Example:
```javascript
/**
 * Calculate distance between two coordinates
 * @param {number} lat1 - Latitude of first point
 * @param {number} lng1 - Longitude of first point
 * @param {number} lat2 - Latitude of second point
 * @param {number} lng2 - Longitude of second point
 * @returns {number} Distance in miles
 */
export function calculateDistance(lat1, lng1, lat2, lng2) {
    // Implementation
}
```

### CSS
- Use CSS custom properties (variables) for colors and spacing
- Use BEM-like naming for component classes
- Mobile-first responsive design with `@media` queries
- Avoid deep nesting

Example:
```css
:root {
    --primary-color: #1a5f2a;
    --primary-hover: #134620;
    --radius: 20px;
}

.search-box {
    background: var(--bg-white);
    border-radius: var(--radius);
}
```

### PHP
- Use `require_once` for includes
- Use `const` for configuration constants
- Use prepared statements for all database queries
- Always validate and sanitize input
- Return JSON with proper headers for API endpoints
- Include comprehensive CORS header comments

## Security Considerations

### API Credentials
- **NEVER** commit `.env` to Git (it's in `.gitignore`)
- API credentials are stored server-side only in PHP files
- PHP scripts validate Referer/Origin headers
- CSRF tokens required for sensitive operations

### CSRF Protection
- All state-changing requests require CSRF token
- Token is generated per-session and validated server-side
- Frontend fetches token via `/scripts/token.php?action=get_csrf_token`

### CORS
- API endpoints include CORS headers for cross-origin requests
- Preflight OPTIONS requests are handled explicitly

### Data Directory
- **MUST** be protected from web access in production
- Apache/nginx: Deny access to `data/` directory
- Caddy: Use `hide` directive to block access to dotfiles and data

### HTTPS
- Always use HTTPS in production to protect user location data

## API Endpoints

### Local API (`scripts/local_api.php`)

| Action | Parameters | Description |
|--------|------------|-------------|
| `search` | `q` (query string), `limit` | Search stations by postcode/name/city |
| `nearby` | `lat`, `lng`, `radius`, `limit` | Get stations near coordinates |
| `status` | - | Database status (count, last update) |

### External API Proxies

- `/scripts/api_proxy.php?endpoint=stations&batch=N` - Proxy to Fuel Finder stations API
- `/scripts/api_proxy.php?endpoint=prices&batch=N` - Proxy to Fuel Finder prices API
- `/scripts/os_token.php` - Get Ordnance Survey OAuth token (CSRF protected)
- `/scripts/token.php?action=get_csrf_token` - Get CSRF token

## Database Schema

See `not_for_website/schema.sql` for full schema.

**Tables:**
- `stations` - Fuel station details (location, amenities, opening times)
- `fuel_prices` - Fuel prices per station (JSON array)
- `cache_metadata` - Update timestamps and metadata

**Indexes:**
- `idx_stations_location` - For geospatial queries
- `idx_stations_postcode` - For postcode searches

## Testing

### Manual Testing Checklist

1. **Home page**
   - Postcode validation
   - Place name search
   - "Use My Location" button (requires HTTPS or localhost)

2. **Map page**
   - Map loads with Leaflet
   - Markers display (green for open, red for closed)
   - Popup shows station details
   - Table sorts by clicking headers
   - Postcode links center map

3. **API**
   - `curl https://your-domain.com/scripts/local_api.php?action=status`
   - Should return JSON with station count and last update
   
4. **Database update**
   - Run `sudo /usr/local/bin/fuel-update-with-vpn.sh` (non-UK) or `php /usr/local/bin/update_data_streaming.php` (UK)
   - Check `data/update.log` for success
   - Verify `data/fuel_data.db` exists and is populated
   - Site should remain accessible during the update (no hangs)

### No Automated Tests

This project does not have a test suite. Testing is manual.

## Common Issues

### "Database not initialized" Error
Run: `sudo /usr/local/bin/fuel-update-with-vpn.sh` (non-UK) or `php /usr/local/bin/update_data_streaming.php` (UK)

### "Failed to get OAuth token" / HTTP 403
Your server is outside the UK. See "Database Updates" section above for VPN solutions.

### Web Server Hangs During VPN Connection
If your web server (Caddy/nginx/Apache) appears to hang when the VPN connects:

**Root Cause:** NordVPN adds iptables rules that drop all incoming IPv4 traffic on eth0 except whitelisted ports. By default only SSH (22) is whitelisted.

**Solution:** The `fuel-update-with-vpn.sh` script now automatically whitelists ports 80, 443, and 22 before connecting VPN. If you're using a custom VPN setup, add:
```bash
nordvpn whitelist add port 80
nordvpn whitelist add port 443
```

**IPv6 Issue:** NordVPN also disables IPv6 routing when connected. The script temporarily disables IPv6 at the kernel level during updates to prevent browsers from hanging while trying IPv6 first. IPv6 is re-enabled after VPN disconnects.

### Permission Denied
```bash
chown -R www-data:www-data /path/to/fuel/data
chmod -R 755 /path/to/fuel/data
```

### Caddy Web Server Dotfile Exposure
Caddy does NOT hide dotfiles by default. Add to Caddyfile:
```caddy
file_server {
    hide .env .git .gitignore
}
```

## Documentation

- `README.md` - User-facing quick start guide
- `INSTALL.md` - Detailed installation and deployment guide
- `Docs/` - gov.uk Fuel Finder API documentation

## Browser Support

- Chrome/Edge 80+ (2020+)
- Firefox 75+ (2020+)
- Safari 13.1+ (2020+)
- No IE support required

## License

This project is provided as-is for educational purposes. Fuel price data is Crown copyright under Open Government Licence v3.0.
