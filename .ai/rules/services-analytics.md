---
paths:
  - app/Services/Analytics/EventNormalizer.php
  - 'app/Services/Analytics/**'
---

# Services Analytics

## Require Cloudflare IP geolocation for country capture
Production country capture depends on the tracker hostname being Cloudflare-proxied with Network > IP Geolocation enabled so requests include CF-IPCountry. Keep country nullable when that header is absent, reserved, or malformed; do not infer location from browser language or retain raw visitor IPs.

## Resolve countries without requiring a CDN
Resolve country from the configured trusted hosting headers first, then fall back to the local MMDB database using the transient request IP. Persist only the normalized ISO country code, keep raw IPs out of analytics storage, and degrade to null when neither source is available. Keep the DB-IP attribution visible wherever country results are displayed.

## Require Cloudflare IP geolocation for country capture
Cloudflare is optional and this former requirement is superseded. Country capture must use configured trusted hosting headers when present and otherwise fall back to the local MMDB database; it must never require a specific CDN.

## Normalize user agents before classification
Normalize user-agent strings to lowercase before matching. Keep distinctive Chromium-family tokens such as Edge and Opera ahead of generic Chrome/Safari checks, and cover desktop and iOS token variants with ingestion tests.
