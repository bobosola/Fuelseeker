# Application design overview

## Website

This is a website application to be written in HTML5, ES8 (ES2017) Javascript and CSS. Avoid using very recent JS and CSS features, aim to use features which can be read and understood in browsers from around 2017 onwards. There is no need to support older browsers. 

Do NOT use any libraries such as React or Angular. Use plain vanilla Javascript & plain CSS. The site should not require any kind of build tool like Webpack etc. The site should be fully readable when doing a 'View Source' in a browser. Minification or any other code obscuring techniques must not be used. Use import maps where possible.

There is no requirement for application monitoring or logging, so you can ignore that advice in Developer_Guidelines.md.

The site will have a home page which lets the user enter:
 * a Post Code
 * a town or street name
 
or choose to allow the site to get the user's current location if the location API is available

Then the site will show a map centred on the user's current location (e.g. on phones and tablets) or the chosen location (e.g. desktop computers). The site will then access the gov.uk fuel prices API (documentation for this starts in [Docs/README.md](README.md)) to display local fuel stations and prices on the map.

The map should show open fuel stations as green icons in the immediate location within an approximateradius of 5 miles. Clicking on an icon should reveal a popup with useful details about the fuel station (address, opening times etc.). Any fuel station that is not currently open should have a red icon.

You can use code from the solentmaps.uk site in /Users/bobosola/Sites/solentmaps.uk to find out how to access the Ordnance Survey API to get locations from Post Codes or place names (this is a free API). You can also use either the OpenStreet or Google Maps code from that site to create the maps. NB: Do not use the Ordnance Survey maps code as this API will restrict access to maps if the site attracts large numbers of users.

Underneath the map there will be a table showing the fuel prices for all of the displayed fuel stations ordered to show the cheapest diesel price first. There will be 4 columns:
- the fuel station name
- diesel price
- petrol price
- Post Code, to be clickable so that the map can be centred on that location, when the page will re-calculate using the new location as the radius centre.

The User can click the column headers to change the order of the columns. There should also be a link back to the home page where the user can choose another location.

## Server

There will be a small amount of server side code to obtain Oauth access tokens to be written in PHP. It will hold the Client Secret and Client ID required to get a short term access token to enable use of the Fuel API. The secret and ID must be kept out of public access, so devise a method (using CSRF perhaps?) to keep these details secret.


 