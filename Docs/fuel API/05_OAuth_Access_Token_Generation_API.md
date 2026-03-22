# API documentation


Information Recipient API's

API to fetch fuel prices and PFS station information, including incremental updates.

## OAuth Access Token Generation API

This API generates OAuth-style access tokens using a client_id and client_secret. The generated token is used to authenticate subsequent API requests within the Fuel Finder ecosystem.

###Version	1

Description	This API generates OAuth-style access tokens using a client_id and client_secret. The generated token is used to authenticate subsequent API requests within the Fuel Finder ecosystem.




URL: https://www.fuel-finder.service.gov.uk/api/v1/oauth/generate_access_token
REQUEST BODY SCHEMA: application/json POST
{
"client_id": "xxx",
"client_secret": "xxx"
}

## Responses 

Http response code 200
{
  "success": true,
  "data": {
    "access_token": "xxx",
    "token_type": "Bearer",
    "expires_in": 3600,
    "refresh_token": "xxx"
  },
  "message": "Operation successful"
}

Http response code 400
{
"success": false,
"message": "client_id or client_secret missing or invalid"
}

Http response code 401
{
"success": false,
"message": "Invalid client_id or client_secret"
}

Http response code 500
{
"success": false,
"statusCode": 500,
"error": "Something went wrong"
}

## Regenerate OAuth access token using refresh token

URL: https://www.fuel-finder.service.gov.uk/api/v1/oauth/regenerate_access_token
REQUEST BODY SCHEMA: application/json POST
{
"client_id": "xxx",
"refresh_token": "xxx"
}

## Responses
Http response code 200
{
  "access_token": "xxx",
  "token_type": "Bearer",
  "expires_in": 3600
}

Http response code 400
{
  "success": false,
  "message": "Invalid refresh_token or client_id"
}

Http response code 401
{
  "success": false,
  "message": "Refresh token expired or revoked"
}






