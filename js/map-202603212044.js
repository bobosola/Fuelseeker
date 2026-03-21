/**
 * Map page JavaScript for Fuel Finder
 */

import {
    fetchStationsNearLocation,
    searchLocation,
    getCurrentPosition
} from './api-20260206143452.js';

import {
    calculateDistance,
    formatPrice,
    isStationOpen,
    formatOpeningTimes,
    formatAddress,
    getFuelPrice
} from './utils-202603212044.js';

const SEARCH_RADIUS_MILES = 20;
const DEFAULT_ZOOM = 13;

let map = null;
let markers = [];
let stations = [];
let currentSort = { column: 'diesel', direction: 'asc' };
let userLocation = null;

document.addEventListener('DOMContentLoaded', async () => {
    initMap();
    
    const urlParams = new URLSearchParams(window.location.search);
    const lat = urlParams.get('lat');
    const lng = urlParams.get('lng');
    const postcode = urlParams.get('postcode');
    const place = urlParams.get('place');
    
    try {
        if (lat && lng) {
            userLocation = {
                lat: parseFloat(lat),
                lng: parseFloat(lng)
            };
            updateLocationTitle('your location');
        } else if (postcode) {
            const decodedPostcode = decodeURIComponent(postcode);
            updateLocationTitle(decodedPostcode);
            showLoading('Loading location...');
            const locations = await searchLocation(decodedPostcode);
            if (locations && locations.length > 0) {
                // Postcodes now return exact match only, use first result
                userLocation = { lat: locations[0].lat, lng: locations[0].lng };
                updateLocationDetails(`${locations[0].name}`);
            } else {
                throw new Error('Postcode not found. Please check and try again.');
            }
        } else if (place) {
            const decodedPlace = decodeURIComponent(place);
            updateLocationTitle(decodedPlace);
            showLoading('Loading location...');
            const locations = await searchLocation(decodedPlace);
            if (locations && locations.length > 0) {
                if (locations.length === 1) {
                    userLocation = { lat: locations[0].lat, lng: locations[0].lng };
                    updateLocationDetails(locations[0].name);
                } else {
                    hideLoading();
                    showLocationPicker(locations, decodedPlace);
                    return;
                }
            } else {
                throw new Error('Location not found. Please try a different place name.');
            }
        } else {
            showLoading('Detecting your location...');
            try {
                const position = await getCurrentPosition();
                userLocation = position;
                updateLocationTitle('your location');
            } catch (error) {
                userLocation = { lat: 51.5074, lng: -0.1278 };
                updateLocationTitle('London (Default)');
                updateLocationDetails('Could not detect your location. Showing London as default.');
            }
        }
        
        map.setView([userLocation.lat, userLocation.lng], DEFAULT_ZOOM);
        addUserLocationMarker(userLocation);
        
        showLoading('Loading nearby fuel stations...');
        await loadAndDisplayStations();
        
        hideLoading();
        
    } catch (error) {
        hideLoading();
        showErrorBanner(error.message);
    }
    
    setupTableSorting();
});

function initMap() {
    map = L.map('map').setView([54.5, -3], 6);
    
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
        maxZoom: 19
    }).addTo(map);
}

function addUserLocationMarker(location) {
    const userIcon = L.divIcon({
        className: 'user-location-marker',
        html: '<div class="user-marker-inner"></div>',
        iconSize: [20, 20],
        iconAnchor: [10, 10]
    });
    
    L.marker([location.lat, location.lng], { icon: userIcon })
        .addTo(map)
        .bindPopup('your location');
}

async function loadAndDisplayStations() {
    try {
        // Use fast local geospatial query instead of downloading all data
        stations = await fetchStationsNearLocation(
            userLocation.lat, 
            userLocation.lng, 
            SEARCH_RADIUS_MILES
        );
        
        stations = stations.map(station => ({
            ...station,
            distance: calculateDistance(
                userLocation.lat,
                userLocation.lng,
                parseFloat(station.location.latitude),
                parseFloat(station.location.longitude)
            )
        }));
        
        sortStations('diesel', 'asc');
        displayMarkers();
        displayTable();
        
        const noResults = document.getElementById('noResults');
        if (stations.length === 0) {
            noResults.classList.remove('hidden');
        } else {
            noResults.classList.add('hidden');
        }
        
    } catch (error) {
        console.error('Failed to load stations:', error);
        showErrorBanner('Failed to load fuel station data. Please try again later.');
    }
}

function filterStationsByRadius(allStations, location, radiusMiles) {
    return allStations.filter(station => {
        if (!station.location || !station.location.latitude || !station.location.longitude) {
            return false;
        }
        
        if (station.permanent_closure) {
            return false;
        }
        
        if (station.temporary_closure) {
            return false;
        }
        
        const distance = calculateDistance(
            location.lat,
            location.lng,
            parseFloat(station.location.latitude),
            parseFloat(station.location.longitude)
        );
        
        return distance <= radiusMiles;
    });
}

function displayMarkers() {
    markers.forEach(marker => map.removeLayer(marker));
    markers = [];
    
    stations.forEach(station => {
        const lat = parseFloat(station.location.latitude);
        const lng = parseFloat(station.location.longitude);
        const open = isStationOpen(station.opening_times);
        
        const iconClass = open ? 'station-open' : 'station-closed';
        const iconHtml = `<div class="station-icon ${iconClass}">⛽</div>`;
        
        const customIcon = L.divIcon({
            className: 'station-marker',
            html: iconHtml,
            iconSize: [32, 32],
            iconAnchor: [16, 32],
            popupAnchor: [0, -32]
        });
        
        const popupContent = createPopupContent(station, open);
        
        const marker = L.marker([lat, lng], { icon: customIcon })
            .addTo(map)
            .bindPopup(popupContent);
        
        markers.push(marker);
    });
}

function createPopupContent(station, open) {
    const dieselPrice = formatPrice(getFuelPrice(station, 'B7_STANDARD'));
    const petrolPrice = formatPrice(getFuelPrice(station, 'E10'));
    const address = formatAddress(station);
    const openingInfo = formatOpeningTimes(station.opening_times);
    const statusText = open ? 'Open' : 'Closed';
    const statusClass = open ? 'open' : 'closed';
    const lat = parseFloat(station.location.latitude);
    const lng = parseFloat(station.location.longitude);
    const googleMapsUrl = `https://www.google.com/maps/search/?api=1&query=${lat},${lng}`;
    
    return `
        <div class="station-popup">
            <h3>${escapeHtml(station.trading_name || station.brand_name || 'Unknown Station')}</h3>
            <p class="station-status ${statusClass}">${statusText}</p>
            <a href="${googleMapsUrl}" target="_blank" rel="noopener noreferrer" class="google-maps-link">view in Google maps</a>
            <p class="station-address">${escapeHtml(address)}</p>
            <div class="station-prices">
                <div class="price-row">
                    <span class="fuel-type">Diesel:</span>
                    <span class="price ${dieselPrice === 'Not reported' ? 'no-price' : ''}">${dieselPrice !== 'Not reported' ? dieselPrice + 'p' : 'Not reported'}</span>
                </div>
                <div class="price-row">
                    <span class="fuel-type">Petrol:</span>
                    <span class="price ${petrolPrice === 'Not reported' ? 'no-price' : ''}">${petrolPrice !== 'Not reported' ? petrolPrice + 'p' : 'Not reported'}</span>
                </div>
            </div>
            <p class="station-hours">${escapeHtml(openingInfo)}</p>
        </div>
    `;
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function displayTable() {
    const tbody = document.getElementById('fuelTableBody');
    tbody.innerHTML = '';
    
    stations.forEach(station => {
        const row = document.createElement('tr');
        
        const dieselPrice = getFuelPrice(station, 'B7_STANDARD');
        const petrolPrice = getFuelPrice(station, 'E10');
        
        const dieselDisplay = formatPrice(dieselPrice);
        const petrolDisplay = formatPrice(petrolPrice);
        const postcode = station.location.postcode || 'N/A';
        const distance = station.distance.toFixed(1);
        
        row.innerHTML = `
            <td class="station-name">${escapeHtml(station.trading_name || station.brand_name || 'Unknown')}</td>
            <td class="price-cell ${dieselDisplay !== 'Not reported' ? 'has-price' : 'no-price'}">${dieselDisplay !== 'Not reported' ? dieselDisplay : '<span class="no-price">Not reported</span>'}</td>
            <td class="price-cell ${petrolDisplay !== 'Not reported' ? 'has-price' : 'no-price'}">${petrolDisplay !== 'Not reported' ? petrolDisplay : '<span class="no-price">Not reported</span>'}</td>
            <td class="postcode-cell">
                <a href="#" class="postcode-link" data-lat="${station.location.latitude}" data-lng="${station.location.longitude}">
                    ${escapeHtml(postcode)}
                </a>
            </td>
            <td class="distance-cell">${distance} mi</td>
        `;
        
        const postcodeLink = row.querySelector('.postcode-link');
        postcodeLink.addEventListener('click', (e) => {
            e.preventDefault();
            const lat = parseFloat(postcodeLink.dataset.lat);
            const lng = parseFloat(postcodeLink.dataset.lng);
            map.setView([lat, lng], 16);
            
            const marker = markers.find(m => {
                const markerLatLng = m.getLatLng();
                return Math.abs(markerLatLng.lat - lat) < 0.0001 && 
                       Math.abs(markerLatLng.lng - lng) < 0.0001;
            });
            if (marker) {
                marker.openPopup();
                window.scrollTo({ top: 0, behavior: 'smooth' });
            }
        });
        
        tbody.appendChild(row);
    });
    
    updateSortIndicators();
}

function setupTableSorting() {
    const headers = document.querySelectorAll('#fuelTable th.sortable');
    
    headers.forEach(header => {
        header.addEventListener('click', () => {
            const column = header.dataset.sort;
            
            let direction = 'asc';
            if (currentSort.column === column) {
                direction = currentSort.direction === 'asc' ? 'desc' : 'asc';
            }
            
            currentSort = { column, direction };
            sortStations(column, direction);
            displayTable();
        });
    });
}

function sortStations(column, direction) {
    stations.sort((a, b) => {
        let valA, valB;
        
        switch (column) {
            case 'name':
                valA = (a.trading_name || a.brand_name || '').toLowerCase();
                valB = (b.trading_name || b.brand_name || '').toLowerCase();
                break;
            case 'diesel':
                valA = parseFloat(getFuelPrice(a, 'B7_STANDARD')) || Infinity;
                valB = parseFloat(getFuelPrice(b, 'B7_STANDARD')) || Infinity;
                break;
            case 'petrol':
                valA = parseFloat(getFuelPrice(a, 'E10')) || Infinity;
                valB = parseFloat(getFuelPrice(b, 'E10')) || Infinity;
                break;
            case 'postcode':
                valA = (a.location.postcode || '').toLowerCase();
                valB = (b.location.postcode || '').toLowerCase();
                break;
            default:
                return 0;
        }
        
        if (valA < valB) return direction === 'asc' ? -1 : 1;
        if (valA > valB) return direction === 'asc' ? 1 : -1;
        return 0;
    });
}

function updateSortIndicators() {
    const headers = document.querySelectorAll('#fuelTable th.sortable');
    
    headers.forEach(header => {
        const indicator = header.querySelector('.sort-indicator');
        const column = header.dataset.sort;
        
        header.classList.remove('sorted-asc', 'sorted-desc');
        indicator.textContent = '';
        
        if (currentSort.column === column) {
            header.classList.add(currentSort.direction === 'asc' ? 'sorted-asc' : 'sorted-desc');
            indicator.textContent = currentSort.direction === 'asc' ? '▲' : '▼';
        }
    });
}

function showLocationPicker(locations, query) {
    const picker = document.getElementById('locationPicker');
    const list = document.getElementById('locationList');
    const message = document.getElementById('locationPickerMessage');
    
    if (!picker || !list) return;
    
    message.textContent = `Multiple locations found for "${query}". Please select one:`;
    
    list.innerHTML = '';
    
    // Sort locations: places first, then alphabetically by county/region
    const placeTypes = ['village', 'hamlet', 'suburban area', 'other settlement', 'town'];
    
    const sortedLocations = [...locations].sort((a, b) => {
        const aType = a.type.toLowerCase();
        const bType = b.type.toLowerCase();
        
        const aIsPlace = placeTypes.some(t => aType.includes(t));
        const bIsPlace = placeTypes.some(t => bType.includes(t));
        
        if (aIsPlace && !bIsPlace) return -1;
        if (!aIsPlace && bIsPlace) return 1;
        
        const aLocation = `${a.county || ''} ${a.region || ''}`.toLowerCase();
        const bLocation = `${b.county || ''} ${b.region || ''}`.toLowerCase();
        
        if (aLocation < bLocation) return -1;
        if (aLocation > bLocation) return 1;
        
        return aType.localeCompare(bType);
    });
    
    sortedLocations.forEach((location) => {
        const li = document.createElement('li');
        
        const details = [];
        if (location.county) details.push(location.county);
        if (location.region) details.push(location.region);
        if (location.postcode) details.push(location.postcode);
        
        const detailText = details.join(', ');
        
        li.innerHTML = `
            <button type="button">
                <span class="location-name">${escapeHtml(location.name)}</span>
                <span class="location-type">${escapeHtml(location.type)}</span>
                ${detailText ? `<span class="location-details">${escapeHtml(detailText)}</span>` : ''}
            </button>
        `;
        
        const button = li.querySelector('button');
        button.addEventListener('click', () => {
            selectLocation(location);
        });
        
        list.appendChild(li);
    });
    
    const cancelBtn = document.getElementById('btnCancelPicker');
    if (cancelBtn) {
        cancelBtn.onclick = () => {
            hideLocationPicker();
            window.location.href = '/';
        };
    }
    
    picker.classList.remove('hidden');
}

function hideLocationPicker() {
    const picker = document.getElementById('locationPicker');
    if (picker) {
        picker.classList.add('hidden');
    }
}

async function selectLocation(location) {
    hideLocationPicker();
    showLoading('Loading fuel stations...');
    
    userLocation = { lat: location.lat, lng: location.lng };
    updateLocationDetails(`${location.name}${location.county ? ', ' + location.county : ''}`);
    
    map.setView([userLocation.lat, userLocation.lng], DEFAULT_ZOOM);
    addUserLocationMarker(userLocation);
    
    await loadAndDisplayStations();
    
    hideLoading();
    setupTableSorting();
}

function updateLocationTitle(title) {
    const element = document.getElementById('locationTitle');
    if (element) {
        element.textContent = `Fuel stations near ${title} - click the icons for details and a Google Maps link:`;
    }
}

function updateLocationDetails(details) {
    const element = document.getElementById('locationDetails');
    if (element) {
        element.textContent = details;
    }
}

function showLoading(text) {
    const loadingText = document.getElementById('loadingText');
    const loadingIndicator = document.getElementById('loadingIndicator');
    
    if (loadingText) loadingText.textContent = text;
    if (loadingIndicator) loadingIndicator.classList.remove('hidden');
}

function hideLoading() {
    const loadingIndicator = document.getElementById('loadingIndicator');
    if (loadingIndicator) {
        loadingIndicator.classList.add('hidden');
    }
}

function showErrorBanner(message) {
    const errorElement = document.getElementById('errorMessage');
    if (errorElement) {
        errorElement.textContent = message;
        errorElement.classList.remove('hidden');
    }
}
