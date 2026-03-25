# Fuelseeker.net

A web application to find fuel stations and compare petrol and diesel prices across the UK.

## Features

- **Fast**: Uses local SQLite database - no waiting for API calls
- **Location Search**: Find stations by postcode, town name, or current location
- **Interactive Map**: OpenStreetMap with colour-coded markers (green=open, red=closed)
- **Price Comparison**: Sortable table showing diesel and petrol prices
- **Smart Radius**: Shows all stations within 20 miles of your location
- **Location Picker**: Choose the right location when multiple matches exist

## How It Works

Unlike apps that query an API every time you search, Fuelseeker.net:

1. **Downloads all UK fuel station data** (~7,000 stations) to a local SQLite database
2. **Updates 3x daily** via automated deployment from a UK-based PC to keep prices current
3. **Queries locally** for instant results - no network delays!

### Architecture

The site uses a split architecture:
- **Web Server** (any location): Serves the website and hosts the SQLite database
- **UK PC** (`data_retrieval_server/`): Downloads fuel data from the UK-only gov.uk API and deploys it to the web server via HTTPS

This avoids geo-blocking/VPN complications when the web server is outside the UK.

## Quick Start

### 1. Web Server Setup

```bash
# Clone/download files to your web server
cd /var/www/fuel

# Copy and configure environment variables
cp scripts/.secrets.example scripts/.secrets
# Edit scripts/.secrets and add your API credentials (for OS geocoding API)

# Create data directory
mkdir data
chmod 755 data

# The database will be deployed automatically from the UK PC
# See data_retrieval_server/ for UK PC setup
```

**Note:** API credentials are stored in `scripts/.secrets` (not committed to Git). Copy `scripts/.secrets.example` to `scripts/.secrets` and fill in your credentials.

### 2. Access the Site

Open `https://your-domain.com/` in your browser.

### 3. Set Up Automatic Updates (UK PC)

On a UK-based PC, set up the data retrieval system:

```bash
cd data_retrieval_server/
cp .secrets.example .secrets
# Edit .secrets and add your API credentials

# Test deployment
php deploy_to_remote_server.php
```

See [DEPLOYMENT_PLAN.md](DEPLOYMENT_PLAN.md) for complete setup instructions.

The UK PC should run this automatically via cron or systemd timer.

## Detailed Installation

See **[INSTALL.md](INSTALL.md)** for complete installation instructions including:
- systemd configuration
- Troubleshooting
- File permissions
- Security notes

## Technology Stack

- **Frontend**: HTML5, Vanilla JavaScript (ES9), CSS3, Leaflet.js
- **Backend**: PHP 7.4+
- **Database**: SQLite (local cache)
- **Data Source**: gov.uk Fuel Finder API
- **Geocoding**: Ordnance Survey Names API

## Data Attribution

- **Fuel prices**: Crown copyright, provided under the Open Government Licence v3.0
- **Maps**: OpenStreetMap contributors
- **Geocoding**: Ordnance Survey

## File Structure

```
fuel/
├── data/                   # SQLite database (auto-deployed from UK PC)
│   ├── fuel_data.db        # Symlink to active database
│   ├── fuel_data.db.v1     # Database file (alternating)
│   ├── fuel_data.db.v2     # Database file (alternating)
│   ├── update.log          # Update logs
│   └── deploy.log          # Deployment logs
├── data_retrieval_server/  # UK PC data retrieval scripts
│   ├── deploy_to_remote_server.php  # Main deployment script
│   ├── config.php          # Configuration loader
│   ├── schema.sql          # Database schema
│   ├── .secrets.example    # Example secrets file
│   └── README.md           # UK PC setup instructions
├── scripts/                # PHP backend scripts (web accessible)
│   ├── db_deploy.php       # Database deployment endpoint (receives from UK PC)
│   ├── local_api.php       # Fast local API endpoints
│   ├── config.php          # Configuration loader
│   ├── os_token.php        # Ordnance Survey token handler
│   ├── token.php           # CSRF token handler
│   └── .secrets            # API credentials (gitignored)
├── js/                     # JavaScript modules
│   ├── api-*.js            # API calls
│   ├── index-*.js          # Home page logic
│   ├── map-*.js            # Map page logic
│   └── utils-*.js          # Utility functions
├── css/
│   └── styles-*.css        # Stylesheet
├── Docs/                   # Documentation
│   ├── fuel API/           # gov.uk Fuel Finder API documentation
│   ├── Application_Design.md
│   └── README.md
├── errors/                 # Error page templates
├── about.html              # About page
├── index.html              # Home page
├── map.html                # Results page
├── DEPLOYMENT_PLAN.md      # UK PC deployment architecture
├── INSTALL.md              # Installation guide (legacy VPN method)
└── README.md               # This file
```

## API Endpoints

### Local API (`scripts/local_api.php`)

| Endpoint | Description | Example |
|----------|-------------|---------|
| `?action=status` | Database status | `curl https://fuelseeker.net/scripts/local_api.php?action=status` |
| `?action=search&q=QUERY` | Search by postcode/town | `curl "https://fuelseeker.net/scripts/local_api.php?action=search&q=SO31&limit=5"` |
| `?action=nearby&lat=XX&lng=YY` | Stations near location | `curl "https://fuelseeker.net/scripts/local_api.php?action=nearby&lat=51.5&lng=-0.1&radius=5"` |

### Example Response

```json
[
  {
    "node_id": "abc123...",
    "trading_name": "Tesco Superstore",
    "brand_name": "Tesco",
    "location": {
      "postcode": "SO31 7ET",
      "latitude": "50.8792",
      "longitude": "-1.2805"
    },
    "fuel_prices": [
      {"fuel_type": "B7_STANDARD", "price": "0149.9000"},
      {"fuel_type": "E10", "price": "0145.9000"}
    ]
  }
]
```

## Troubleshooting

### Database not initialized
Run: `sudo /usr/local/bin/fuel-update-with-vpn.sh` (non-UK servers) or `php /usr/local/bin/update_data_streaming.php` (UK servers)

### Permission errors
```bash
chmod 755 data
chmod 666 data/fuel_data.db
```

### Check database status
```bash
curl https://fuelseeker.net/scripts/local_api.php?action=status
```

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

**Data Attribution:** The fuel price data is Crown copyright, provided by the UK government under the Open Government Licence v3.0.

---
