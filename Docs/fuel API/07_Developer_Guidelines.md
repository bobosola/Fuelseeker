# Developer Guidelines

Best practices and guidelines for developing applications with the Fuel Finder API.

## API rate limits for Information Recipients

To protect platform stability and ensure fair usage, API requests are subject to rate limits. Limits differ between test and live environments.

Test environment rate limits

* 30 requests per minute per client (burst up to 60 RPM)
* 5,000 requests per day per client
* 1 concurrent request allowed
* Short bursts allowed but throttled automatically

Test keys must not be used for performance testing or high-volume traffic. External integrations should use mocked or cached responses where possible.

## Live environment rate limits

* 120 requests per minute per client
* 10,000 requests per day per client
* 2 concurrent requests allowed
* Controlled burst handling with automatic throttling

## External dependency limits

* Ordnance Survey APIs: 50 RPM in development mode, 600 RPM in live mode.
* Companies House APIs: strict rate limits apply. Test environments should use cached or mocked responses to avoid quota exhaustion.

*Exceeding limits returns HTTP 429 (Too Many Requests).*

If you require higher limits for approved use cases, please contact us.

## Code Standards

### Data Validation

Validate API responses before using the data:

* Check that required fields are present
* alidate data types and formats
* Handle null or undefined values gracefully
* Sanitize user inputs before making API requests

## Performance Guidelines

### Caching

Implement appropriate caching strategies:

* Station data: Cache for 1 hour (stations don't change frequently)
* Price data: Cache for 15 minutes (prices change more often)
* Search results: Cache for 5 minutes (balance between freshness and performance)
* Use HTTP caching headers when available
* Implement cache invalidation strategies

## Request Optimization

Optimize your API requests for better performance:

* Use appropriate pagination to limit response sizes
* Request only the data fields you need
* Implement request batching where possible
* Use compression for large requests
* Avoid making unnecessary requests

## Security Best Practices

### OAuth 2.0 (client credentials) security

*Warning Never expose client secrets or access tokens in clientside code or public repositories.*

Secure handling practices:

* Store client secrets in environment variables or a secure secrets manager (not in source control).
* Use separate credentials per environment (test and production) and per application.
* Rotate client secrets regularly and immediately if you suspect compromise.
* Request the minimum scopes your integration needs (principle of least privilege).
* Keep access tokens shortlived; rely on issuing new tokens rather than reusing old ones.
* Do not log secrets or tokens; mask sensitive values in logs and error messages.
* Use HTTPS (TLS 1.2+) for all calls to the token endpoint and API endpoints.
* Monitor token usage for anomalies (unexpected IPs, spikes in requests) and revoke credentials if required.
* Protect server-to-server flows: run OAuth on backend services only; do not expose secrets to browsers or mobile apps

## Input Sanitization

Always sanitize user inputs before making API requests:

* Validate data formats
* Sanitize search queries
* Escape special characters in URLs

## User Experience Guidelines

### Loading States

Provide clear feedback to users during API requests:

* Show loading indicators for API calls
* Display progress for long-running operations
* Provide estimated completion times when possible
* Allow users to cancel requests if appropriate

## Error Handling

Always implement proper error handling in your applications:

* Check HTTP status codes before processing responses
* Handle network timeouts and connection errors
* Implement retry logic with exponential backoff
* Log errors appropriately for debugging

Present user-friendly error messages:

* Translate technical errors into user-friendly language
* Provide actionable suggestions when errors occur
* Offer alternative actions when possible
* nclude contact information for support

## Testing Guidelines

### Unit Testing

Write comprehensive unit tests for your API integration:

* Test all API endpoints you use
* Mock API responses for consistent testing
* Test error handling scenarios
* Validate data parsing and transformation
* Test authentication and authorization

## Integration Testing

Test your application with the API:

* Use the sandbox environment for testing
* Test with various data scenarios
* Verify rate limiting behavior
* Test network failure scenarios
* Validate performance under load

## Monitoring and Logging

### Application Monitoring

Implement monitoring for your API usage:

* Track API request success/failure rates
* Monitor response times and performance
* Alert on unusual usage patterns
* Track rate limit usage
* Monitor error rates and types

## Logging Best Practices

Implement proper logging for debugging and monitoring:

* Log API requests and responses (without sensitive data)
* Include request IDs for tracing
* Log performance metrics
* Use appropriate log levels
* Implement log rotation and retention policies

## Compliance and Legal

### Terms of Service

Ensure compliance with our terms of service:

* Respect rate limits and usage quotas
* Don't attempt to circumvent security measures
* Use data responsibly and ethically
* Respect user privacy and data protection
* Follow applicable laws and regulations

## Data Usage

Use API data appropriately:

* Don't redistribute raw API data
* Attribute data sources appropriately
* Respect intellectual property rights
* Don't use data for illegal or harmful purposes

## Frontend Asset Management

### Cache Busting with Timestamps

This project uses **timestamp-based versioning** for CSS and JavaScript files to ensure users always receive the latest versions after updates.

#### File Naming Format
```
filename-YYYYMMDDHHMM.ext
```

Examples:
- `styles-202603011751.css` (updated March 1, 2026 at 17:51)
- `utils-202603212044.js`
- `map-202603212044.js`

#### When Modifying Assets

**After any CSS or JS change:**

1. Generate a new timestamp: `date +%Y%m%d%H%M`
2. Rename the modified file with the new timestamp
3. Update ALL references in HTML files and JS imports
4. Delete or keep old versions (old files can be removed after deployment)

**Example workflow:**
```bash
# 1. Make your changes to js/utils-20260206143452.js

# 2. Generate new timestamp
NEW_TS=$(date +%Y%m%d%H%M)  # e.g., 202603011751

# 3. Rename the file
mv js/utils-20260206143452.js js/utils-${NEW_TS}.js

# 4. Update all references
sed -i "s/utils-20260206143452/utils-${NEW_TS}/g" js/map-*.js js/index-*.js

# 5. Verify
grep -r "20260206143452" --include="*.js" --include="*.html" .
# Should return no results
```

#### Why Not Query Strings?

Some developers use `file.css?v=123` for cache busting. We avoid this because:
- **CDN compatibility**: Some CDNs ignore query strings for caching
- **Cleaner URLs**: Timestamp in filename is more explicit
- **Version tracking**: Easy to see when a file was last modified

## Need help?

If you have any issues about using Fuel Finder, [contact the team](support.md)