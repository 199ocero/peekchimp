---
paths:
  - 'routes/**'
---

# Routes

## Gate product routes on verified website setup
Authenticated product pages must use the `website.configured` middleware. Setup is complete only when the user has an active project with a verified domain; onboarding and essential Fortify/logout routes stay outside the gate.
