# API authentication

Access to API services requires authentication. Fuel Finder uses *OAuth 2.0 (client credentials)* in both test and production environments.

## OAuth 2.0 (client credentials)

Use OAuth 2.0 client credentials to obtain a shortlived access token. You’ll need a client and client secret for your application.

## Token request (example)
```
POST: 
Content-Type: application/x-www-form-urlencoded 
grant_type: client_credentials&client_id=YOUR_CLIENT_ID&client_secret=YOUR_CLIENT_SECRET&scope=fuelfinder.read 
```
## Successful response (example)

```{ "access_token": "eyJhbGciOi...", "token_type": "Bearer", "expires_in": 3600 } ```

## Call the API with your token

Include the token in the Authorization header on each request.

```GET : /v1/prices?fuel_type=unleaded 
Authorization: Bearer ACCESS_TOKEN 
```

If the token is missing, expired or invalid, the API returns 401 Unauthorized.

## Environments

Test – use test client credentials to integrate and trial your application.
Production – use production client credentials for live data.

## Next steps

Learn how to create an application (Information Recipients) and get client credentials.

Read the developer guidelines for rate limits, security and pagination.

