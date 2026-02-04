/**
 * Utility functions for Fuel Finder
 */

/**
 * Calculate the distance between two coordinates using the Haversine formula
 */
export function calculateDistance(lat1, lon1, lat2, lon2) {
    const R = 3959; // Earth's radius in miles
    const dLat = toRadians(lat2 - lat1);
    const dLon = toRadians(lon2 - lon1);
    
    const a = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
              Math.cos(toRadians(lat1)) * Math.cos(toRadians(lat2)) *
              Math.sin(dLon / 2) * Math.sin(dLon / 2);
    
    const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
    
    return R * c;
}

function toRadians(degrees) {
    return degrees * (Math.PI / 180);
}

/**
 * Format a price from the API (e.g., "0145.9000") to display format (145.9)
 */
export function formatPrice(price) {
    if (price === null || price === undefined || price === '') {
        return '-';
    }
    
    const numPrice = parseFloat(price);
    if (isNaN(numPrice)) {
        return '-';
    }
    
    return numPrice.toFixed(1);
}

/**
 * Check if a station is currently open based on its opening times
 */
export function isStationOpen(openingTimes) {
    if (!openingTimes || !openingTimes.usual_days) {
        return true;
    }
    
    const now = new Date();
    const dayNames = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];
    const currentDay = dayNames[now.getDay()];
    const daySchedule = openingTimes.usual_days[currentDay];
    
    if (!daySchedule) {
        return true;
    }
    
    if (daySchedule.is_24_hours) {
        return true;
    }
    
    if (!daySchedule.open || !daySchedule.close) {
        return true;
    }
    
    const currentTime = now.getHours() * 60 + now.getMinutes();
    
    const [openHours, openMinutes] = daySchedule.open.split(':').map(Number);
    const openTime = openHours * 60 + openMinutes;
    
    const [closeHours, closeMinutes] = daySchedule.close.split(':').map(Number);
    const closeTime = closeHours * 60 + closeMinutes;
    
    if (closeTime < openTime) {
        return currentTime >= openTime || currentTime <= closeTime;
    }
    
    return currentTime >= openTime && currentTime <= closeTime;
}

/**
 * Format opening times for display
 */
export function formatOpeningTimes(openingTimes) {
    if (!openingTimes || !openingTimes.usual_days) {
        return 'Opening times not available';
    }
    
    const dayNames = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    const now = new Date();
    const currentDayIndex = now.getDay();
    const currentDayName = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'][currentDayIndex];
    const todaySchedule = openingTimes.usual_days[currentDayName];
    
    if (!todaySchedule) {
        return 'Opening times not available';
    }
    
    if (todaySchedule.is_24_hours) {
        return `Today (${dayNames[currentDayIndex]}): Open 24 hours`;
    }
    
    if (todaySchedule.open && todaySchedule.close) {
        const openTime = formatTime(todaySchedule.open);
        const closeTime = formatTime(todaySchedule.close);
        return `Today (${dayNames[currentDayIndex]}): ${openTime} - ${closeTime}`;
    }
    
    return 'Opening times not available';
}

function formatTime(timeStr) {
    if (!timeStr) return '';
    const [hours, minutes] = timeStr.split(':');
    const h = parseInt(hours, 10);
    const ampm = h >= 12 ? 'PM' : 'AM';
    const h12 = h % 12 || 12;
    return `${h12}:${minutes} ${ampm}`;
}

/**
 * Validate a UK postcode format
 */
export function isValidPostcode(postcode) {
    const postcodeRegex = /^[A-Z]{1,2}[0-9][A-Z0-9]?\s?[0-9][A-Z]{2}$/i;
    return postcodeRegex.test(postcode.trim());
}

/**
 * Show an error message in the specified element
 */
export function showError(elementId, message) {
    const element = document.getElementById(elementId);
    if (element) {
        element.textContent = message;
        element.classList.remove('hidden');
    }
}

/**
 * Hide an error message
 */
export function hideError(elementId) {
    const element = document.getElementById(elementId);
    if (element) {
        element.textContent = '';
        element.classList.add('hidden');
    }
}

/**
 * Show loading indicator
 */
export function showLoading(elementId, show = true) {
    const element = document.getElementById(elementId);
    if (element) {
        if (show) {
            element.classList.remove('hidden');
        } else {
            element.classList.add('hidden');
        }
    }
}

/**
 * Debounce function to limit how often a function can fire
 */
export function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

/**
 * Get fuel price from station data for a specific fuel type
 */
export function getFuelPrice(station, fuelType) {
    if (!station.fuel_prices || !Array.isArray(station.fuel_prices)) {
        return null;
    }
    
    const fuelPrice = station.fuel_prices.find(fp => fp.fuel_type === fuelType);
    return fuelPrice ? fuelPrice.price : null;
}

/**
 * Extract address from station location data
 */
export function formatAddress(station) {
    if (!station.location) {
        return 'Address not available';
    }
    
    const parts = [];
    if (station.location.address_line_1) parts.push(station.location.address_line_1);
    if (station.location.address_line_2) parts.push(station.location.address_line_2);
    if (station.location.city) parts.push(station.location.city);
    if (station.location.postcode) parts.push(station.location.postcode);
    
    return parts.join(', ') || 'Address not available';
}
