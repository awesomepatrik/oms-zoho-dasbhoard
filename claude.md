# Zoho dashboard -- project brief

## What this is
Admin reporting dashboard for a non-profit mission agency.
Pulls data from Zoho Books API (recurring invoices / donor pledges)
and Zoho CRM API (employee records).

## Stack
- PHP (plain, no framework) for OAuth2 token handling and API proxy
- Vanilla JS + jQuery for frontend
- Chart.js (CDN) for charts
- Flat JSON file cache to reduce API calls

## Key reports needed
- Total pledged support per employee vs target
- Agency-wide totals
- Income by month
- Funding % per employee
- Balance trends over time
- Pledge status breakdown (active / paused / cancelled)
- Upcoming invoice run dates

## Zoho API notes
- Books API base: https://www.zohoapis.com.au/books/v3
- CRM API base: https://www.zohoapis.com.au/crm/v3
- OAuth2 server-based application (refresh token stored in /config/tokens.php outside web root)
- Australian data centre -- .com.au endpoints

## Conventions
- Config and tokens never in web root
- API calls proxied through PHP -- no Zoho credentials exposed to browser
- Cache responses for 1 hour minimum
- Australian English in all UI text