---
paths:
  - app/Models/Project.php
---

# Models

## Keep public dashboard links revocable and section-scoped
Public sharing is disabled by default. Store the bearer token encrypted with a SHA-256 lookup hash; disabling preserves the URL, while rotation replaces it immediately. Serialize only the configured public dashboard sections, and require a verified active domain before enabling or resolving a public dashboard.
