/**
 * Home page JavaScript for Fuel Finder
 */

import {
    isValidPostcode,
    showError,
    hideError,
    showLoading,
    debounce
} from './utils-202603271253.js';

import { getCurrentPosition } from './api-202603271253.js';

// Function to reset UI state (used on page load and when coming back from bfcache)
function resetUIState() {
    showLoading('loadingIndicator', false);
    hideError('errorMessage');
}

document.addEventListener('DOMContentLoaded', () => {
    const postcodeInput = document.getElementById('postcode');
    const placeInput = document.getElementById('place');
    const btnSearch = document.getElementById('btnSearch');
    const btnLocateMe = document.getElementById('btnLocateMe');
    
    // Reset loading state on initial page load
    resetUIState();
    
    // Load last update time
    loadLastUpdated();
    
    postcodeInput.addEventListener('input', debounce(() => {
        if (postcodeInput.value.trim()) {
            placeInput.value = '';
        }
        hideError('errorMessage');
    }, 100));
    
    placeInput.addEventListener('input', debounce(() => {
        if (placeInput.value.trim()) {
            postcodeInput.value = '';
        }
        hideError('errorMessage');
    }, 100));
    
    btnSearch.addEventListener('click', handleSearch);
    
    postcodeInput.addEventListener('keypress', (e) => {
        if (e.key === 'Enter') {
            handleSearch();
        }
    });
    
    placeInput.addEventListener('keypress', (e) => {
        if (e.key === 'Enter') {
            handleSearch();
        }
    });
    
    btnLocateMe.addEventListener('click', handleLocateMe);
});

// Handle back-forward cache (bfcache) - fires when page is restored from cache
window.addEventListener('pageshow', (event) => {
    if (event.persisted) {
        // Page was restored from bfcache, reset UI state
        resetUIState();
    }
});

async function handleSearch() {
    const postcodeInput = document.getElementById('postcode');
    const placeInput = document.getElementById('place');
    const postcode = postcodeInput.value.trim();
    const place = placeInput.value.trim();
    
    hideError('errorMessage');
    
    if (!postcode && !place) {
        showError('errorMessage', 'Please enter a postcode or a town/street name');
        return;
    }
    
    let queryParam = '';
    let typeParam = '';
    
    if (postcode) {
        if (!isValidPostcode(postcode)) {
            showError('errorMessage', 'Please enter a valid UK postcode (e.g., SW1A 1AA)');
            return;
        }
        queryParam = encodeURIComponent(postcode.toUpperCase());
        typeParam = 'postcode';
    } else {
        queryParam = encodeURIComponent(place);
        typeParam = 'place';
    }
    
    window.location.href = `/map.html?${typeParam}=${queryParam}`;
}

async function handleLocateMe() {
    hideError('errorMessage');
    showLoading('loadingIndicator', true);
    
    try {
        const position = await getCurrentPosition();
        
        const lat = encodeURIComponent(position.lat.toFixed(6));
        const lng = encodeURIComponent(position.lng.toFixed(6));
        window.location.href = `/map.html?lat=${lat}&lng=${lng}`;
        
    } catch (error) {
        showLoading('loadingIndicator', false);
        showError('errorMessage', error.message);
    }
}

async function loadLastUpdated() {
    const lastUpdatedEl = document.getElementById('lastUpdated');
    if (!lastUpdatedEl) return;
    
    try {
        const response = await fetch('/scripts/local_api.php?action=status');
        if (!response.ok) throw new Error('Failed to fetch status');
        
        const data = await response.json();
        if (data.last_update) {
            const date = new Date(data.last_update);
            const day = date.toLocaleString('en-GB', { day: '2-digit' });
            const month = date.toLocaleString('en-GB', { month: 'short' });
            const year = date.toLocaleString('en-GB', { year: '2-digit' });
            const time = date.toLocaleString('en-GB', { hour: '2-digit', minute: '2-digit' });
            const formatted = `${day}-${month}-${year}, ${time}`;
            lastUpdatedEl.textContent = `Data: ${data.stations_with_prices.toLocaleString()} fuel stations at ${formatted}`;
        } else {
            lastUpdatedEl.textContent = '';
        }
    } catch (error) {
        lastUpdatedEl.textContent = '';
    }
}
