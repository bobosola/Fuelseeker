-- Fuel Finder Database Schema

-- Stations table
CREATE TABLE IF NOT EXISTS stations (
    node_id TEXT PRIMARY KEY,
    mft_organisation_name TEXT,
    public_phone_number TEXT,
    trading_name TEXT,
    brand_name TEXT,
    temporary_closure INTEGER DEFAULT 0,
    permanent_closure INTEGER DEFAULT 0,
    is_motorway_service_station INTEGER DEFAULT 0,
    is_supermarket_service_station INTEGER DEFAULT 0,
    address_line_1 TEXT,
    address_line_2 TEXT,
    city TEXT,
    country TEXT,
    county TEXT,
    postcode TEXT,
    latitude REAL,
    longitude REAL,
    amenities TEXT,
    opening_times TEXT,
    fuel_types TEXT,
    last_updated DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Fuel prices table
CREATE TABLE IF NOT EXISTS fuel_prices (
    node_id TEXT PRIMARY KEY,
    fuel_prices TEXT,
    last_updated DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (node_id) REFERENCES stations(node_id)
);

-- Cache metadata table
CREATE TABLE IF NOT EXISTS cache_metadata (
    key TEXT PRIMARY KEY,
    value TEXT,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Create index for faster location searches
CREATE INDEX IF NOT EXISTS idx_stations_location ON stations(latitude, longitude);
CREATE INDEX IF NOT EXISTS idx_stations_postcode ON stations(postcode);
