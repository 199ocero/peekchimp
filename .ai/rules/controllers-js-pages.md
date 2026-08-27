---
paths:
  - 'app/Jobs/GenerateInsightRecommendations.php,app/Http/Controllers/GenerateDashboardAiInsightsController.php,resources/js/pages/Dashboard.vue'
---

# Controllers Js Pages

## Keep AI generation explicit and rate-limited
Automatic AI enrichment remains on its project-wide cooldown. Only workspace admins may explicitly queue a one-off generation for the current dashboard period; that user action may bypass the cooldown to make testing and refreshes predictable.
