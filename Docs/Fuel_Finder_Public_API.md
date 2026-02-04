# Fuel Finder Public API

Access the Fuel Finder API to retrieve real-time fuel prices and forecourt amenities data. This API provides comprehensive information about fuel prices and services across all registered filling stations to help you build applications and services for drivers to find fuel and amenities.

The API provides data that is updated within 30 minutes of any changes, as required by The Motor Fuel Price (Open Data) Regulations 2025.

## Before you start

You'll need:

* A Gov.UK One Login to access the API
* Understanding of REST API principles
* Technical capability to integrate with JSON APIs
* Knowledge of your target geographic areas or specific forecourt requirements

The API follows REST principles: resources have stable URLs and map to standard HTTP methods. You can read data by issuing simple GET requests to the relevant resource URL.

This API covers filling stations in the United Kingdom.

What you get:

* Current retail prices of all the petrol stations by fuel type
* Forecourt details (address, operator, brand)
* Site amenities and opening hours
* Update timestamps for each price and site


## API authentication

Access to API services requires authentication. The Fuel Finder API supports OAuth 2.0 (client credentials).

## Developer guidelines

Read the [developer guidelines](Developer_Guidelines.md) for information about security, API rate limits, pagination and enumerated types as you build your application.