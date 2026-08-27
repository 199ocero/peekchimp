---
paths:
  - 'app/Services/Analytics/**,app/Jobs/GenerateInsightRecommendations.php,app/Ai/Agents/AnalyticsInsightAgent.php'
---

# Agents

## Only label distinct aggregate-safe AI recommendations
Do not include deterministic fallback recommendations in the AI prompt. Mark an insight AI-enhanced only when the provider returns a substantive, distinct recommendation that differs from the fallback and from other recommendations in the same run. Recommendations must use aggregate reports or segments and must never request lists, exports, or inspection of individual visitors.
