---
paths:
  - 'app/Jobs/GenerateInsightRecommendations.php,app/Http/Controllers/GenerateDashboardAiInsightsController.php,app/Services/Analytics/AiInsightContextBuilder.php'
---

# Controllers Services Analytics

## Reuse completed AI insight contexts
Treat AI insight generation as idempotent by aggregate context. A completed run for the same project, period, and aggregate candidate values must be reused without another provider call; changed data and failed or skipped runs may generate again. Keep manual and automatic queue identities separate so an automatic cooldown job cannot suppress an explicit admin request.
