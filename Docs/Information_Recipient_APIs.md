# Information Recipient API's

API to fetch fuel prices and PFS station information, including incremental updates.

AUTHORIZATIONS:OAuth2

## Fetch all PFS fuel prices

API used to fetch all fuel prices.

URL GET: https://www.fuel-finder.service.gov.uk/api/v1/pfs/fuel-prices?batch-number=1

RESPONSE SCHEMA: application/json 200 success
example:
[
  {
    "node_id": "0028acef5f3afc41c7e7d56fb285a940dfb64d6fea01cb4accd79c148321112d",
    "mft_organisation_name": "789 LTD",
    "public_phone_number": null,
    "trading_name": "FORECOURT 4",
    "fuel_prices": [
      {
        "price": null,
        "fuel_type": "B10",
        "price_last_updated": null
      },
      {
        "price": null,
        "fuel_type": "B7_PREMIUM",
        "price_last_updated": null
      },
      {
        "price": null,
        "fuel_type": "E5",
        "price_last_updated": null
      },
      {
        "price": null,
        "fuel_type": "HVO",
        "price_last_updated": null
      },
      {
        "price": "0120.0000",
        "fuel_type": "E10",
        "price_last_updated": "2025-12-31T08:15:23"
      },
      {
        "price": "0235.9000",
        "fuel_type": "B7_STANDARD",
        "price_last_updated": "2025-12-31T13:16:29"
      }
    ]
  },
  {
    "node_id": "01da92125c3751767044d06b202f45da5933f0e16e256fa3e98a16af8386308d",
    "mft_organisation_name": "VE3",
    "public_phone_number": "",
    "trading_name": "KHJH",
    "fuel_prices": [
      {
        "price": null,
        "fuel_type": "E10",
        "price_last_updated": null
      }
    ]
  },
  {
    "node_id": "020592cd81196efdb61ab2135f837ddf3d2bee4e64346810270f0b088b4c09d8",
    "mft_organisation_name": "FUEL TEST",
    "public_phone_number": null,
    "trading_name": "Test 12",
    "fuel_prices": [
      {
        "price": null,
        "fuel_type": "B7_STANDARD",
        "price_last_updated": null
      },
      {
        "price": "0140.0000",
        "fuel_type": "HVO",
        "price_last_updated": "2026-01-13T09:27:32"
      },
      {
        "price": "0183.0000",
        "fuel_type": "E10",
        "price_last_updated": "2026-01-12T08:15:21"
      },
      {
        "price": "0333.4000",
        "fuel_type": "B10",
        "price_last_updated": "2026-01-13T09:26:40"
      }
    ]
  }
]

RESPONSE SCHEMA: application/json 401 Unauthorized
{
  "error": "Unauthorized",
  "message": "Invalid API key or missing authentication header."
}

RESPONSE SCHEMA: application/json 500 Internal server error
{
"success": false,
"statusCode": 500,
"error": "Something went wrong"
}


## Fetch incremental PFS fuel prices

API used to fetch all fuel prices incrementally based on the provided effective start timestamp.

URL GET: https://www.fuel-finder.service.gov.uk/api/v1/pfs/fuel-prices?batch-number=1&effective-start-timestamp=<YYYY-MM-DD HH:MM:SS> where YYYY-MM-DD HH:MM:SS is a timestamp string

RESPONSE SCHEMA: application/json 200 success
example:
[
  {
    "node_id": "0028acef5f3afc41c7e7d56fb285a940dfb64d6fea01cb4accd79c148321112d",
    "mft_organisation_name": "789 LTD",
    "public_phone_number": null,
    "trading_name": "FORECOURT 4",
    "fuel_prices": [
      {
        "price": "0120.0000",
        "fuel_type": "E10",
        "price_last_updated": "2025-12-31T08:15:23"
      },
      {
        "price": "0235.9000",
        "fuel_type": "B7_STANDARD",
        "price_last_updated": "2025-12-31T13:16:29"
      }
    ]
  },
  {
    "node_id": "020592cd81196efdb61ab2135f837ddf3d2bee4e64346810270f0b088b4c09d8",
    "mft_organisation_name": "FUEL TEST",
    "public_phone_number": null,
    "trading_name": "Test 12",
    "fuel_prices": [
      {
        "price": "0140.0000",
        "fuel_type": "HVO",
        "price_last_updated": "2026-01-13T09:27:32"
      },
      {
        "price": "0183.0000",
        "fuel_type": "E10",
        "price_last_updated": "2026-01-12T08:15:21"
      },
      {
        "price": "0333.4000",
        "fuel_type": "B10",
        "price_last_updated": "2026-01-13T09:26:40"
      }
    ]
  },
  {
    "node_id": "04b39ce398e156be65e96e164024bd17e208b263628612f76293121748b151c6",
    "mft_organisation_name": "Sun Farm 7",
    "public_phone_number": "+442079460958",
    "trading_name": "Shell Petrol Station - Updated",
    "fuel_prices": [
      {
        "price": "0147.5500",
        "fuel_type": "E5",
        "price_last_updated": "2026-01-06T17:50:50"
      }
    ]
  }
]

RESPONSE SCHEMA: application/json 401 Unauthorized
{
  "error": "Unauthorized",
  "message": "Invalid API key or missing authentication header."
}

RESPONSE SCHEMA: application/json 500 Internal server error
{
  "success": false,
  "statusCode": 500,
  "error": "Something went wrong"
}


## Fetch PFS information

API used to fetch all the PFS (Petrol Fuel Station) information. Note: Each API response returns data for up to 500 forecourts. To retrieve additional forecourts, the consumer must pass the query parameter batch-number. For example, the first call returns forecourts 0-500, and the second call with batch-number=2 returns forecourts 501-1000.

URL GET: https://www.fuel-finder.service.gov.uk/api/v1/pfs?batch-number=1

RESPONSE SCHEMA: application/json 200 success
example:
[
  {
    "node_id": "9b275ab576eeba3c6677984be15ee22a74e54fdfe8e5ea700e84a03178dc4ac1",
    "mft_organisation_name": "USERM123",
    "public_phone_number": null,
    "trading_name": "TEST",
    "is_same_trading_and_brand_name": true,
    "brand_name": "TEST",
    "temporary_closure": false,
    "permanent_closure": false,
    "permanent_closure_date": null,
    "is_motorway_service_station": false,
    "is_supermarket_service_station": false,
    "location": {
      "address_line_1": "HALL & WOODHOUSE, TAPLOW BOATYARD, MILL LANE, TAPLOW, MAIDENHEAD, SL6 0AA",
      "address_line_2": null,
      "city": "MAIDENHEAD",
      "country": "England",
      "county": null,
      "postcode": "SL6 0AA",
      "latitude": "51.5268585",
      "longitude": "-0.7003610"
    },
    "amenities": [
      "water_filling"
    ],
    "opening_times": {
      "usual_days": {
        "monday": {
          "open": "00:00:00",
          "close": "00:00:00",
          "is_24_hours": false
        },
        "tuesday": {
          "open": "00:00:00",
          "close": "00:00:00",
          "is_24_hours": false
        },
        "wednesday": {
          "open": "00:00:00",
          "close": "00:00:00",
          "is_24_hours": false
        },
        "thursday": {
          "open": "00:00:00",
          "close": "00:00:00",
          "is_24_hours": false
        },
        "friday": {
          "open": "00:00:00",
          "close": "00:00:00",
          "is_24_hours": false
        },
        "saturday": {
          "open": "00:00:00",
          "close": "00:00:00",
          "is_24_hours": false
        },
        "sunday": {
          "open": "00:00:00",
          "close": "23:59:00",
          "is_24_hours": true
        }
      },
      "bank_holiday": {
        "type": "bank holiday",
        "open_time": "00:00:00",
        "close_time": "00:00:00",
        "is_24_hours": false
      }
    },
    "fuel_types": [
      "E10",
      "E5",
      "HVO",
      "B10"
    ]
  },
  {
    "node_id": "4fd9a4c6b48358b9b5c95989fba100fdcbb87c9e909ed4ce1ad96f64ffb8b56a",
    "mft_organisation_name": "Test 78",
    "public_phone_number": "+44 7723608248",
    "trading_name": "TEST FORECOURT 1",
    "is_same_trading_and_brand_name": true,
    "brand_name": "TEXACO ONE",
    "temporary_closure": false,
    "permanent_closure": null,
    "permanent_closure_date": null,
    "is_motorway_service_station": false,
    "is_supermarket_service_station": false,
    "location": {
      "address_line_1": "NEWPORT",
      "address_line_2": "",
      "city": "BROUGH",
      "country": "ENGLAND",
      "county": "EAST YORKSHIRE",
      "postcode": "HU15 2RD",
      "latitude": "51.2585030",
      "longitude": "-3.4175670"
    },
    "amenities": [
      "adblue_packaged",
      "adblue_pumps",
      "car_wash",
      "customer_toilets"
    ],
    "opening_times": {
      "usual_days": {
        "monday": {
          "open": "06:00:01",
          "close": "23:00:01",
          "is_24_hours": false
        },
        "tuesday": {
          "open": "06:00:01",
          "close": "23:00:01",
          "is_24_hours": false
        },
        "wednesday": {
          "open": "06:00:01",
          "close": "23:00:01",
          "is_24_hours": false
        },
        "thursday": {
          "open": "06:00:01",
          "close": "23:00:01",
          "is_24_hours": false
        },
        "friday": {
          "open": "06:00:01",
          "close": "23:00:01",
          "is_24_hours": false
        },
        "saturday": {
          "open": "06:00:01",
          "close": "23:00:01",
          "is_24_hours": false
        },
        "sunday": {
          "open": "06:00:01",
          "close": "23:00:01",
          "is_24_hours": false
        }
      },
      "bank_holiday": {
        "type": "standard",
        "open_time": "06:00:01",
        "close_time": "23:00:01",
        "is_24_hours": false
      }
    },
    "fuel_types": [
      "B10"
    ]
  },
  {
    "node_id": "91bdda1c07fa05110a31639cc66932f9ed8bd388d4f6be542a423365bcfd53e1",
    "mft_organisation_name": "YHJ PROPERTIES LIMITED",
    "public_phone_number": "+442071930000",
    "trading_name": "SUPERFUEL LOUGHBOROUGH 12",
    "is_same_trading_and_brand_name": true,
    "brand_name": "SUPERFUEL STATION 4",
    "temporary_closure": false,
    "permanent_closure": null,
    "permanent_closure_date": null,
    "is_motorway_service_station": false,
    "is_supermarket_service_station": false,
    "location": {
      "address_line_1": "14 LONDON ROAD",
      "address_line_2": "FUELVILLE",
      "city": "LOUGHBOROUGH",
      "country": "ENGLAND",
      "county": "LEICESTERSHIRE",
      "postcode": "LE11 9AA",
      "latitude": "50.5033430",
      "longitude": "2.1244400"
    },
    "amenities": [
      "adblue_packaged",
      "adblue_pumps",
      "car_wash",
      "customer_toilets",
      "water_filling"
    ],
    "opening_times": {
      "usual_days": {
        "monday": {
          "open": "06:00:00",
          "close": "22:00:00",
          "is_24_hours": false
        },
        "tuesday": {
          "open": "06:00:00",
          "close": "22:00:00",
          "is_24_hours": false
        },
        "wednesday": {
          "open": "06:00:00",
          "close": "22:00:00",
          "is_24_hours": false
        },
        "thursday": {
          "open": "06:00:00",
          "close": "22:00:00",
          "is_24_hours": false
        },
        "friday": {
          "open": "06:00:00",
          "close": "22:00:00",
          "is_24_hours": false
        },
        "saturday": {
          "open": "06:00:00",
          "close": "22:00:00",
          "is_24_hours": false
        },
        "sunday": {
          "open": "06:00:00",
          "close": "22:00:00",
          "is_24_hours": false
        }
      },
      "bank_holiday": {
        "type": "standard",
        "open_time": "08:00:00",
        "close_time": "20:00:00",
        "is_24_hours": false
      }
    },
    "fuel_types": [
      "E5",
      "HVO",
      "B10",
      "B7_PREMIUM",
      "B7_STANDARD"
    ]
  }
]

RESPONSE SCHEMA: application/json 401 Unauthorized
{
  "error": "Unauthorized",
  "message": "Invalid API key or missing authentication header."
}

RESPONSE SCHEMA: application/json 500 Internal server error
{
  "success": false,
  "statusCode": 500,
  "error": "Something went wrong"
}

## Fetch incremental PFS information

API used to fetch PFS information incrementally based on the provided effective start timestamp.

URL GET: https://www.fuel-finder.service.gov.uk/api/v1/pfs?batch-number=1&effective-start-timestamp=<YYYY-MM-DD HH:MM:SS> where YYYY-MM-DD HH:MM:SS is a timestamp string

RESPONSE SCHEMA: application/json 200 success
example:

[
  {
    "node_id": "9b275ab576eeba3c6677984be15ee22a74e54fdfe8e5ea700e84a03178dc4ac1",
    "mft_organisation_name": "USERM123",
    "public_phone_number": null,
    "trading_name": "TEST",
    "is_same_trading_and_brand_name": true,
    "brand_name": "TEST",
    "temporary_closure": false,
    "permanent_closure": false,
    "permanent_closure_date": null,
    "is_motorway_service_station": false,
    "is_supermarket_service_station": false,
    "location": {
      "address_line_1": "HALL & WOODHOUSE, TAPLOW BOATYARD, MILL LANE, TAPLOW, MAIDENHEAD, SL6 0AA",
      "address_line_2": null,
      "city": "MAIDENHEAD",
      "country": "England",
      "county": null,
      "postcode": "SL6 0AA",
      "latitude": "51.5268585",
      "longitude": "-0.7003610"
    },
    "amenities": [
      "water_filling"
    ],
    "opening_times": {
      "usual_days": {
        "monday": {
          "open": "00:00:00",
          "close": "00:00:00",
          "is_24_hours": false
        },
        "tuesday": {
          "open": "00:00:00",
          "close": "00:00:00",
          "is_24_hours": false
        },
        "wednesday": {
          "open": "00:00:00",
          "close": "00:00:00",
          "is_24_hours": false
        },
        "thursday": {
          "open": "00:00:00",
          "close": "00:00:00",
          "is_24_hours": false
        },
        "friday": {
          "open": "00:00:00",
          "close": "00:00:00",
          "is_24_hours": false
        },
        "saturday": {
          "open": "00:00:00",
          "close": "00:00:00",
          "is_24_hours": false
        },
        "sunday": {
          "open": "00:00:00",
          "close": "23:59:00",
          "is_24_hours": true
        }
      },
      "bank_holiday": {
        "type": "bank holiday",
        "open_time": "00:00:00",
        "close_time": "00:00:00",
        "is_24_hours": false
      }
    },
    "fuel_types": [
      "B10"
    ]
  },
  {
    "node_id": "d596bd700d04e36f346661db721b7648617f120aa88a2b47fe0aed617e8533c0",
    "mft_organisation_name": "ICON LTD",
    "public_phone_number": null,
    "trading_name": "Yono",
    "is_same_trading_and_brand_name": true,
    "brand_name": "Yono",
    "temporary_closure": false,
    "permanent_closure": false,
    "permanent_closure_date": null,
    "is_motorway_service_station": false,
    "is_supermarket_service_station": false,
    "location": {
      "address_line_1": "SQUARE CIRCLE MEDIA, 86-90, PAUL STREET, LONDON, EC2A 4NE",
      "address_line_2": null,
      "city": "LONDON",
      "country": "England",
      "county": null,
      "postcode": "EC2A 4NE",
      "latitude": "51.5256151",
      "longitude": "-0.0836283"
    },
    "amenities": [
      "adblue_packaged",
      "adblue_pumps"
    ],
    "opening_times": {
      "usual_days": {
        "monday": {
          "open": "00:00:00",
          "close": "23:59:00",
          "is_24_hours": true
        },
        "tuesday": {
          "open": "00:00:00",
          "close": "00:00:00",
          "is_24_hours": false
        },
        "wednesday": {
          "open": "00:00:00",
          "close": "00:00:00",
          "is_24_hours": false
        },
        "thursday": {
          "open": "00:00:00",
          "close": "00:00:00",
          "is_24_hours": false
        },
        "friday": {
          "open": "00:00:00",
          "close": "00:00:00",
          "is_24_hours": false
        },
        "saturday": {
          "open": "00:00:00",
          "close": "00:00:00",
          "is_24_hours": false
        },
        "sunday": {
          "open": "00:00:00",
          "close": "23:59:00",
          "is_24_hours": true
        }
      },
      "bank_holiday": {
        "type": "bank holiday",
        "open_time": "00:00:00",
        "close_time": "23:59:00",
        "is_24_hours": true
      }
    },
    "fuel_types": [
      "E5"
    ]
  },
  {
    "node_id": "126e885886a4324153aa53b714542d22b71fab0b76f843d97c58f1b1174fb152",
    "mft_organisation_name": "Sparta",
    "public_phone_number": null,
    "trading_name": "csvprice",
    "is_same_trading_and_brand_name": true,
    "brand_name": "csvprice",
    "temporary_closure": false,
    "permanent_closure": false,
    "permanent_closure_date": null,
    "is_motorway_service_station": false,
    "is_supermarket_service_station": true,
    "location": {
      "address_line_1": "THE PRINCESS OF SHOREDITCH, 76-78, PAUL STREET, LONDON, EC2A 4NE",
      "address_line_2": null,
      "city": "LONDON",
      "country": "England",
      "county": null,
      "postcode": "EC2A 4NE",
      "latitude": "51.5254010",
      "longitude": "-0.0837382"
    },
    "amenities": [],
    "opening_times": {
      "usual_days": {
        "monday": {
          "open": "00:00:00",
          "close": "00:00:00",
          "is_24_hours": false
        },
        "tuesday": {
          "open": "00:00:00",
          "close": "00:00:00",
          "is_24_hours": false
        },
        "wednesday": {
          "open": "00:00:00",
          "close": "00:00:00",
          "is_24_hours": false
        },
        "thursday": {
          "open": "00:00:00",
          "close": "00:00:00",
          "is_24_hours": false
        },
        "friday": {
          "open": "00:00:00",
          "close": "23:59:00",
          "is_24_hours": true
        },
        "saturday": {
          "open": "00:00:00",
          "close": "00:00:00",
          "is_24_hours": false
        },
        "sunday": {
          "open": "00:00:00",
          "close": "00:00:00",
          "is_24_hours": false
        }
      },
      "bank_holiday": {
        "type": "bank holiday",
        "open_time": "00:00:00",
        "close_time": "00:00:00",
        "is_24_hours": false
      }
    },
    "fuel_types": [
      "E10",
      "E5",
      "B10",
      "B7_PREMIUM"
    ]
  }
]

RESPONSE SCHEMA: application/json 401 Unauthorized
{
  "error": "Unauthorized",
  "message": "Invalid API key or missing authentication header."
}

RESPONSE SCHEMA: application/json 500 Internal server error
{
  "success": false,
  "statusCode": 500,
  "error": "Something went wrong"
}

