# Fuel Finder REST API

The Fuel Finder API is a REST API that gives a simple, consistent way to request, create and update data. REST stands for Representational State Transfer which is an architectural software style in which standard HTTP request methods are used to retrieve and modify representations of data. This is identical to the process of retrieving a web page or submitting a web form.

## Representational State Transfer (REST) web services

In a RESTful API, each data resource has a unique URL and is manipulated using standard HTTP verbs such as:

* GET to request a resource
* POST to create a resource (not used for read-only endpoints)
* PUT to change a resource (not used for read-only endpoints)
* DELETE to remove a resource (not used for read-only endpoints)
* Example: request a price resource

`GET: https://api.fuelfinder.service.gov.uk/v1/prices/GB-12345 HTTP/1.1` 
The request uses GET and does not include a request body.

In a RESTful API, a resource is modified by POSTing a revised resource representation, in this case JSON, to the same resource URL:

```POST: https://api.fuelfinder.service.gov.uk/v1/<endpoint> 
Content-Type: text/json 
	 { 
	 	"CustomerName": "Joe Bloggs", 
	 	"Address": "", 
	 	"etc": etc 
	 } 
```
REST builds on the features of HTTP. Because each resource has a globally unique URL and can be fetched with GET, REST APIs can benefit from existing network components such as caches and proxies.

## The JSON data format

Responses use JSON (JavaScript Object Notation). JSON is a compact, widely used format for storing and exchanging data. Most programming languages support JSON, which makes it well suited to HTTP-based API services.

## API specifications

The API specifications describe every Fuel Finder operation.

* Information recipients: read trusted open data — current prices by fuel type, forecourt details, amenities, and time-stamped updates.
* Motor fuel traders: submit fuel prices.
     