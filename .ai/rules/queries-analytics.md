---
paths:
  - 'app/Services/Analytics/**,app/Queries/Analytics/**'
---

# Queries Analytics

## Exclude internal domains from acquisition sources
Treat referrers matching any configured project domain as Direct acquisition traffic. Explicit UTM sources still take precedence.
