---
paths:
  - 'app/Actions/Fortify/**,app/Http/Middleware/EnsureRegistrationIsAvailable.php,config/fortify.php'
---

# Middleware

## Keep Fortify registration for first-admin bootstrap
Fortify registration and verification features remain enabled for future SaaS use. The registration endpoints are guarded only when an is_admin user already exists; first-admin creation is protected by a cache lock.
