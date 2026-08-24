---
paths:
  - 'app/Queries/Analytics/**'
---

# Analytics

## Match chart buckets to dashboard range
Dashboard timeseries use hourly buckets for Today and Yesterday (Today stops at the current project-local hour). Seven-day, 30-day, month, and custom ranges use daily buckets. Build labels in the project timezone and normalize query boundaries back to the application timezone.

## Keep analytics database comparisons in UTC
PostgreSQL connections must use UTC when comparing analytics timestamps. Convert project-local range boundaries to UTC before querying; only convert event instants back to the project timezone for chart labels and buckets. An offset-less UTC string under a non-UTC PostgreSQL session silently shifts the effective range.

## Attribute AI referrals from sessions
Classify AI referrals at dashboard query time from retained session referrer hosts and explicit UTM sources, counting sessions as visits. Do not infer a specific AI answer or treat browser analytics as crawler coverage; crawler reporting requires a server/CDN collector.

## Count dashboard geography from sessions
Dashboard country geography represents visits, so aggregate analytics_sessions by country within the selected range instead of counting page-view events. Return every known country plus a separate unknown count; do not reintroduce a top-N limit for globe data.
