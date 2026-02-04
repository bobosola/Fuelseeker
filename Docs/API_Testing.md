# API Testing

## Environments

Fuel Finder APIs run in live and test environments. Live provides access to real data and production services. test environment mirrors live API versions and lets developers test integrations safely.

## Live

The live environment runs all production APIs listed in the developer documentation.

API Testing

Environments

Fuel Finder APIs run in live and test environments. Live provides access to real data and production services. test environment mirrors live API versions and lets developers test integrations safely.

Live

The live environment runs all production APIs listed in the developer documentation.

* Public data for information recipients (read-only)
* Price submission for motor fuel traders (write operations)

## Test

The test environment mirrors the live API and allows developers to integrate and experiment safely without affecting production data.

To use the test environment, you must create a separate test account and obtain dedicated OAuth client credentials. Visit the test onboarding page at some https://www.fuel-finder.service.gov.uk to register a test user, set up your application, and generate your test OAuth keys.

Test environment credentials are not valid in the live environment, and live credentials cannot be used for testing. Make sure your application targets the correct base URL and key set when switching between environments.

## Testing public data APIs (information recipients)

Public data APIs are read-only and allow information recipients to access trusted open data (current prices by fuel type, forecourt details, amenities, timestamped updates). These endpoints support GET requests with Bearer tokens obtained through OAuth 2.0 client credentials.

Because they are read only, you can test public data APIs against live or test environment.

## Testing price submission APIs (motor fuel traders)

Price submission APIs allow creation and update of data (for example, submitting new prices). These endpoints use the HTTP POST method and require a Bearer access token from OAuth 2.0 (client credentials).

Testing write operations must be done in the test environment only.

### How developers can test price submission APIs in the test environment

* Register your Motor Fuel Trader (MFT) organisation and the associated Petrol Fuelling Stations (PFS) using the Fuel Finder Portal (FF service staging URL).
* Use the forecourt IDs of the registered PFSs to create your price submission transactions.
Obtain an access token from the sandbox identity service using your client ID and client secret (OAuth 2.0 client credentials).
* Submit a price update for one or more PFSs using the access token and the forecourt ID(s).
* Poll the transaction for mock status updates and handle accepted or rejected outcomes.

## Need help?

If you have any issues about using Fuel Finder, contact the team.