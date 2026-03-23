# Fuelseeker.net - Agent Documentation

## Project Overview

Fuelseeker.net is a fast, lightweight web application for finding fuel stations and comparing petrol and diesel prices across the UK. Unlike typical apps that query an API on every search, it uses a local SQLite database with ~7,000 UK fuel stations for instant results.

**Key Characteristics:**
- Zero external dependencies for runtime (no npm, webpack, etc.)
- Pure vanilla JavaScript (ES9/ES2018+) with ES6 modules
- Plain CSS3 with CSS variables
- PHP 7.4+ backend for API proxying and database management
- SQLite for local data caching
- **Split architecture**: Data retrieved by UK PC, deployed to web server via HTTPS

**Architecture:**
- **Web Server** (any location): Serves website, hosts SQLite database
- **UK PC** (`data_retrieval_server/`): Downloads fuel data from UK-only gov.uk API and deploys to web server

This avoids VPN complications when the web server is outside the UK.

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
│   └── styles-*.css        # Main stylesheet with CSS variables (timestamped)
├── js/
│   ├── api-*.js            # API calls (local SQLite, OS geocoding)
│   ├── index-*.js          # Home page logic (search, geolocation)
│   ├── map-*.js            # Map page logic (Leaflet, table sorting)
│   └── utils-*.js          # Utility functions (distance calc, formatting)
├── scripts/                # PHP backend (web accessible)
│   ├── db_deploy.php       # Database deployment endpoint (receives from UK PC)
│   ├── config.php          # Configuration loader (.secrets file support)
│   ├── local_api.php       # Fast local API endpoints (SQLite queries)
│   ├── os_token.php        # Ordnance Survey OAuth proxy
│   ├── token.php           # CSRF token handler
│   └── .secrets            # API credentials (gitignored)
├── data/                   # SQLite database (auto-deployed, gitignored)
│   ├── fuel_data.db        # Symlink to active database file
│   ├── fuel_data.db.v1     # Actual database file (alternating)
│   ├── fuel_data.db.v2     # Actual database file (alternating)
│   └── deploy.log          # Deployment logs
├── data_retrieval_server/  # UK PC data retrieval (copy to UK PC)
│   ├── deploy_to_remote_server.php  # Main deployment script
│   ├── config.php          # Configuration loader
│   ├── schema.sql          # Database schema
│   ├── .secrets.example    # Example secrets file
│   └── README.md           # UK PC setup instructions
├── Docs/                   # Documentation
│   ├── fuel API/           # gov.uk Fuel Finder API documentation
│   ├── Application_Design.md
│   └── README.md
└── errors/                 # Error page templates
```

## Configuration

### Secret Configuration (`.secrets` file)

Create `.secrets` in the `scripts/` directory (NOT in git):

```bash
cp scripts/.secrets.example scripts/.secrets
# Edit scripts/.secrets and add your API credentials
```

Content:
```bash
# Ordnance Survey API credentials (from https://osdatahub.os.uk/)
OS_API_KEY=your_api_key_here
OS_API_SECRET=your_api_secret_here

# Deployment API key (must match UK PC)
DEPLOY_API_KEY=your_deployment_key_here
```

The `.secrets` file is automatically loaded by `scripts/config.php` which defines constants directly from the values.

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
2. **Create `.secrets` file** in `scripts/` with API credentials
3. **Create data directory**:
   ```bash
   mkdir data
   chmod 755 data
   ```
4. **Run initial data population**:
   ```bash
   # For UK PC deployment (recommended):
   cd data_retrieval_server/
   php deploy_to_remote_server.php
   
   # For legacy VPN method (not recommended):
   # sudo /usr/local/bin/fuel-update-with-vpn.sh
   ```

### Database Updates (UK PC Deployment)

The fuel prices change throughout the day. The database is updated via **deployment from a UK-based PC** to avoid VPN complications on the web server.

**Architecture:**
```
UK PC (Home/Office)          Web Server (Any location)
     │                               │
     ├── Downloads fuel data ───────►│
     │    (UK-only API access)       │
     │                               │
     └── Deploys via HTTPS ────────►├─ Receives database
                                    ├─ Performs atomic swap
                                    └─ Serves updated data
```

**UK PC Setup:**
1. Copy `data_retrieval_server/` files to a UK-based PC
2. Configure `data_retrieval_server/.secrets` with API credentials
3. Run `php deploy_to_remote_server.php` to test
4. Set up cron/systemd timer for automatic updates (3x daily recommended)

**Update frequency options:**
- **3x daily (recommended)**: 06:00, 14:00, 22:00 - Good price accuracy
- **4x daily**: Every 6 hours - Maximum freshness

**How it works:**
1. UK PC downloads fuel data from gov.uk API (UK IP required)
2. Builds SQLite database locally using streaming import (~10MB memory)
3. Uploads database to web server via HTTPS POST to `scripts/db_deploy.php`
4. Web server performs atomic symlink swap for zero downtime

See `DEPLOYMENT_PLAN.md` for complete setup instructions.

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

The streaming version writes data directly to CSV during API fetch, avoiding memory exhaustion. The UK PC uses this same streaming approach in `deploy_to_remote_server.php`.

### Cache Busting (File Versioning)

To ensure users always get the latest CSS and JavaScript files after updates, this project uses **timestamp-based cache busting**:

#### File Naming Convention

All CSS and JS files include a timestamp in their filename:
```
css/styles-202603011751.css       # Format: YYYYMMDDHHMM
js/utils-202603212044.js
js/map-202603212044.js
js/index-202603011751.js
```

#### When to Update Timestamps

**Update timestamps when you modify:**
- CSS files (styles)
- JavaScript files (functionality changes)

**No need to update when you modify:**
- HTML files (they reference the versioned assets)
- PHP backend files
- Database schema

#### How to Update Timestamps

1. **Generate new timestamp:**
   ```bash
   date +%Y%m%d%H%M
   # Output: 202603011751
   ```

2. **Rename the modified file(s):**
   ```bash
   mv css/styles-20260206143452.css css/styles-202603011751.css
   mv js/utils-20260206143452.js js/utils-202603212044.js
   ```

3. **Update all references** in HTML and JS files:
   ```bash
   # Update HTML files
   sed -i 's/styles-20260206143452/styles-202603011751/g' index.html map.html about.html
   sed -i 's/utils-20260206143452/utils-202603212044/g' map.html
   
   # Update JS imports
   sed -i 's/utils-20260206143452/utils-202603212044/g' js/index-20260324090000.js js/map-202603212044.js
   ```

4. **Verify no old references remain:**
   ```bash
   grep -r "20260206143452" --include="*.html" --include="*.js" .
   ```

#### Why This Approach?

| Benefit | Explanation |
|---------|-------------|
| **Cache invalidation** | Browsers treat each timestamped file as unique, forcing download of new versions |
| **Simple deployment** | No build process or CDN configuration needed |
| **Version tracking** | Timestamps show exactly when each file was last modified |
| **Easy rollback** | Keep old versions or revert to previous timestamps if needed |

#### Path Detection for Update Script

The `update_data_streaming.php` script uses smart path detection:

```php
// Priority order:
// 1. Environment variables (FUELSEEKER_SCRIPT_DIR, FUELSEEKER_DATA_DIR)
// 2. Auto-detection based on __DIR__ (works on both local and live)
// 3. Fallback to hardcoded local paths
```

**Usage examples:**
```bash
# UK PC deployment (recommended)
cd data_retrieval_server/
php deploy_to_remote_server.php

# With custom deploy URL
DEPLOY_URL=https://your-domain.com/scripts/db_deploy.php \
php deploy_to_remote_server.php
```

See `INSTALL.md` for more details on path configuration.

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
- **NEVER** commit `.secrets` to Git (it's in `.gitignore`)
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
- **MUST** be writable by PHP/web server for database deployment
  ```bash
  chmod 755 data
  chown www-data:www-data data  # Adjust user/group for your web server
  ```

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

See `data_retrieval_server/schema.sql` for full schema.

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
   
4. **Database deployment**
   - Run `php deploy_to_remote_server.php` from the UK PC
   - Check `data/logs/deploy.log` for success
   - Verify `data/fuel_data.db` exists and is populated on web server
   - Check deployment status: `curl https://your-domain.com/scripts/db_deploy.php?action=status`

### No Automated Tests

This project does not have a test suite. Testing is manual.

## Common Issues

### "Database not initialized" Error
Run the deployment script from the UK PC:
```bash
cd data_retrieval_server/
php deploy_to_remote_server.php
```

### "Failed to get OAuth token" / HTTP 403
The UK PC cannot access the gov.uk Fuel Finder API. Ensure:
- The PC is located in the UK (or using a UK VPN)
- API credentials in `data_retrieval_server/.secrets` are correct

### Deployment Failed / Upload Error

**Check the UK PC logs:**
```bash
tail -f data/logs/deploy.log
```

**Common causes:**
1. **Network timeout**: Check internet connection between UK PC and web server
2. **Invalid API key**: Ensure `DEPLOY_API_KEY` matches in both UK PC and web server `.secrets` files
3. **PHP upload limits**: Web server must allow uploads up to 20MB
4. **SSL certificate error**: Ensure web server has valid SSL certificate

### Permission Denied
```bash
chown -R www-data:www-data /path/to/fuel/data
chmod -R 755 /path/to/fuel/data
```

### Caddy Web Server Dotfile Exposure
Caddy does NOT hide dotfiles by default. Add to Caddyfile:
```caddy
file_server {
    hide .secrets .git .gitignore
}
```

## Documentation

- `README.md` - User-facing quick start guide
- `INSTALL.md` - Detailed installation and deployment guide
- `Docs/` - Documentation including gov.uk Fuel Finder API docs

## Browser Support

- Chrome/Edge 80+ (2020+)
- Firefox 75+ (2020+)
- Safari 13.1+ (2020+)
- No IE support required

## License

This project is provided as-is for educational purposes. Fuel price data is Crown copyright under Open Government Licence v3.0.
