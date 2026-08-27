---
paths:
  - 'app/Queries/Analytics/**,app/Services/Analytics/**'
---

# Analytics Services Analytics

## Require a real comparison baseline for insights
Do not generate change or engagement insights when the previous matching period has no traffic. Return a comparison-pending state instead; new dimension values within an otherwise valid baseline must state the current and previous values explicitly.
