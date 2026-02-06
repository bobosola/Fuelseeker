/**
 * Home page JavaScript for Fuel Finder
 */

import {
    isValidPostcode,
    showError,
    hideError,
    showLoading,
    debounce
} from './utils-20260206130251.js';

import { getCurrentPosition } from './api-20260206130251.js';

document.addEventListener('DOMContentLoaded', () => {
    const postcodeInput = document.getElementById('postcode');
    const placeInput = document.getElementById('place');
    const btnSearch = document.getElementById('btnSearch');
    const btnLocateMe = document.getElementById('btnLocateMe');
    
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
