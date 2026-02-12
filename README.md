# Fuelseeker.net

A fast web application to find fuel stations and compare petrol and diesel prices across the UK.

## Features

- **⚡ Lightning Fast**: Uses local SQLite database - no waiting for API calls
- **📍 Location Search**: Find stations by postcode, town name, or current location
- **🗺️ Interactive Map**: OpenStreetMap with colour-coded markers (green=open, red=closed)
- **💰 Price Comparison**: Sortable table showing diesel and petrol prices
- **🎯 Smart Radius**: Shows all stations within 10 miles of your location
- **📱 Location Picker**: Choose the right location when multiple matches exist

## How It Works

Unlike typical apps that query an API every time you search, Fuelseeker.net:

1. **Downloads all UK fuel station data** (~7,000 stations) to a local SQLite database
2. **Updates 3x daily** via systemd timer to keep prices current (auto-connects VPN for non-UK servers)
3. **Queries locally** for instant results - no network delays!

## Quick Start

### 1. Initial Setup

```bash
# Clone/download files to your web server
cd /var/www/fuel

# Copy and configure environment variables
cp .env.example .env
# Edit .env and add your API credentials

# Create data directory
mkdir data
chmod 755 data

# Download initial fuel data (takes 1-2 minutes)
# First copy scripts to system location:
sudo cp not_for_website/update_data_streaming.php /usr/local/bin/
sudo cp not_for_website/fuel-update-with-vpn.sh /usr/local/bin/

# Then run (non-UK servers must use VPN wrapper):
# UK servers: php /usr/local/bin/update_data_streaming.php
# Non-UK servers: sudo /usr/local/bin/fuel-update-with-vpn.sh
```

**Note:** API credentials are stored in `.env` (not committed to Git). Copy `.env.example` to `.env` and fill in your credentials.

### 2. Access the Site

Open `https://your-domain.com/` in your browser.

### 3. Set Up Automatic Updates

Set up automatic updates using **systemd timer** (recommended) - see [INSTALL.md](INSTALL.md) for details.

The update runs 3x daily at 06:00, 14:00, and 22:00 using a VPN connection for non-UK servers.

## Detailed Installation

See **[INSTALL.md](INSTALL.md)** for complete installation instructions including:
- cPanel setup
- VPS/systemd configuration
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
├── data/                   # SQLite database (auto-created)
│   ├── fuel_data.db        # Symlink to active database
│   ├── fuel_data.db.v1     # Database file (alternating)
│   ├── fuel_data.db.v2     # Database file (alternating)
│   ├── update.log          # Update logs
│   └── update_error.log    # Error logs
├── not_for_website/        # Server-side scripts (not web accessible)
│   ├── update_data_streaming.php # Streaming update script (low memory, no hangs)
│   ├── schema.sql              # Database schema
│   └── fuel-update-with-vpn.sh # VPN wrapper script
├── scripts/                # PHP backend scripts (web accessible)
│   ├── api_proxy.php       # Fuel Finder API proxy
│   ├── config.php          # Configuration loader
│   ├── local_api.php       # Fast local API endpoints
│   ├── os_token.php        # Ordnance Survey token handler
│   ├── token.php           # CSRF token handler
│   └── .env                # API credentials (gitignored)
├── js/                     # JavaScript modules
│   ├── api.js              # API calls
│   ├── index.js            # Home page logic
│   ├── map.js              # Map page logic
│   └── utils.js            # Utility functions
├── css/
│   └── styles.css          # Stylesheet
├── Docs/                   # API documentation
│   ├── API_authentication.md
│   ├── API_Testing.md
│   ├── Application_Design.md
│   ├── Developer_Guidelines.md
│   ├── Fuel_Finder_Public_API.md
│   ├── Fuel_Finder_REST_API.md
│   ├── Information_Recipient_APIs.md
│   ├── OAuth_Access_Token_Generation_API.md
│   ├── README.md
│   └── Support.md
├── errors/
│   └── errors.html         # Error page
├── stats/
│   └── index.html          # Stats page
├── about.html              # About page
├── index.html              # Home page
├── map.html                # Results page
├── INSTALL.md              # Installation guide
└── README.md               # This file
```

## API Endpoints

### Local API (`scripts/local_api.php`)

- `?action=status` - Database status
- `?action=nearby&lat=xx&lng=yy&radius=10` - Stations near location

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
curl https://your-domain.com/scripts/local_api.php?action=status
```

## License

This project is provided as-is for educational purposes. The fuel price data is provided by the UK government under the Open Government Licence v3.0.

---

**Made with ⛽ in the UK**
