/**
 * API handling for Fuel Finder
 * Uses local SQLite database for fast responses
 */

const LOCAL_API_BASE = '/scripts/local_api.php';
const OS_API_BASE = 'https://api.os.uk';

let csrfToken = null;

// BNG (British National Grid) projection definition (EPSG:27700)
const BNG_PROJECTION = '+proj=tmerc +lat_0=49 +lon_0=-2 +k=0.9996012717 +x_0=400000 +y_0=-100000 +ellps=airy +towgs84=446.448,-125.157,542.06,0.15,0.247,0.842,-20.489 +units=m +no_defs';
const WGS84_PROJECTION = '+proj=longlat +datum=WGS84 +no_defs';

function bngToWgs84(easting, northing) {
    if (typeof proj4 !== 'undefined') {
        return proj4(BNG_PROJECTION, WGS84_PROJECTION, [easting, northing]);
    } else {
        const lat = 49 + (northing - 100000) / 111320;
        const lng = -2 + (easting - 400000) / (111320 * Math.cos(lat * Math.PI / 180));
        return [lng, lat];
    }
}

async function initializeCsrfToken() {
    try {
        const response = await fetch('/scripts/token.php?action=get_csrf_token');
        if (response.ok) {
            const data = await response.json();
            csrfToken = data.csrf_token;
        }
    } catch (error) {
        console.error('Failed to initialize CSRF token:', error);
    }
}

async function getOsToken() {
    if (!csrfToken) {
        await initializeCsrfToken();
    }
    
    const response = await fetch('/scripts/os_token.php', {
        method: 'GET',
        headers: { 'X-CSRF-Token': csrfToken }
    });
    
    if (!response.ok) {
        throw new Error('OS token request failed');
    }
    
    const data = await response.json();
    return data.access_token;
}

/**
 * Fetch stations near a location - FAST local query
 */
export async function fetchStationsNearLocation(lat, lng, radiusMiles = 5) {
    console.log(`Fetching stations near ${lat}, ${lng} within ${radiusMiles} miles...`);
    
    const url = `${LOCAL_API_BASE}?action=nearby&lat=${lat}&lng=${lng}&radius=${radiusMiles}&limit=100`;
    
    const response = await fetch(url);
    
    if (!response.ok) {
        const error = await response.text();
        throw new Error(`Local API error: ${response.status} - ${error}`);
    }
    
    const stations = await response.json();
    console.log(`Found ${stations.length} stations nearby`);
    
    return stations;
}

/**
 * Legacy function for compatibility - now uses fast local query
 */
export async function fetchAllStations() {
    throw new Error('Use fetchStationsNearLocation() instead');
}

export async function fetchAllFuelPrices() {
    throw new Error('Use fetchStationsNearLocation() instead - prices are included');
}

/**
 * Merge station data with fuel prices - no longer needed as prices are included
 */
export function mergeStationData(stations, prices) {
    return stations;
}

/**
 * Check if a string looks like a UK postcode
 */
function isPostcode(query) {
    const postcodeRegex = /^[A-Z]{1,2}[0-9][A-Z0-9]?\s?[0-9][A-Z]{2}$/i;
    return postcodeRegex.test(query.trim());
}

/**
 * Search for a location using Ordnance Survey Names API
 * For postcodes, returns only exact matches
 */
export async function searchLocation(query, maxResults = 25) {
    const token = await getOsToken();
    const isPostcodeQuery = isPostcode(query);
    
    // If it's a postcode, search with filter for postcodes only
    const url = isPostcodeQuery 
        ? `${OS_API_BASE}/search/names/v1/find?query=${encodeURIComponent(query)}&fq=LOCAL_TYPE:Postcode&maxresults=${maxResults}`
        : `${OS_API_BASE}/search/names/v1/find?query=${encodeURIComponent(query)}&maxresults=${maxResults}`;
    
    const response = await fetch(url, {
        headers: {
            'Authorization': `Bearer ${token}`,
            'Accept': 'application/json'
        }
    });
    
    if (!response.ok) {
        if (response.status === 401) {
            return searchLocation(query, maxResults);
        }
        throw new Error(`Geocoding failed: ${response.status}`);
    }
    
    const data = await response.json();
    
    if (data.results && data.results.length > 0) {
        let results = data.results.map(r => {
            const result = r.GAZETTEER_ENTRY;
            const easting = parseFloat(result.GEOMETRY_X);
            const northing = parseFloat(result.GEOMETRY_Y);
            const [lng, lat] = bngToWgs84(easting, northing);
            
            return {
                name: result.NAME1,
                lat: lat,
                lng: lng,
                postcode: result.POSTCODE_DISTRICT || '',
                type: result.LOCAL_TYPE,
                county: result.COUNTY_UNITARY || '',
                region: result.REGION || '',
                country: result.COUNTRY || ''
            };
        });
        
        // If searching for a postcode, filter to exact match only
        if (isPostcodeQuery) {
            const normalizedQuery = query.toUpperCase().replace(/\s+/g, ' ').trim();
            results = results.filter(r => {
                const normalizedResult = r.name.toUpperCase().replace(/\s+/g, ' ').trim();
                return normalizedResult === normalizedQuery;
            });
            
            // Return exact match or nothing
            return results.length > 0 ? results : null;
        }
        
        return results;
    }
    
    return null;
}

export function getCurrentPosition() {
    return new Promise((resolve, reject) => {
        if (!navigator.geolocation) {
            reject(new Error('Geolocation not supported'));
            return;
        }
        
        navigator.geolocation.getCurrentPosition(
            (position) => resolve({
                lat: position.coords.latitude,
                lng: position.coords.longitude
            }),
            (error) => reject(new Error('Location access denied')),
            { enableHighAccuracy: true, timeout: 10000, maximumAge: 60000 }
        );
    });
}

/**
 * Check database status
 */
export async function getDatabaseStatus() {
    const response = await fetch(`${LOCAL_API_BASE}?action=status`);
    return response.json();
}
