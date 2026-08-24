---
paths:
  - 'app/Http/Controllers/Api/**'
---

# Api

## Verify domains from accepted pageviews only
A project domain becomes verified only when a newly accepted, non-bot `page_view` arrives from that exact normalized Origin host. Missing/disallowed origins, duplicates, and custom events must not verify onboarding.
