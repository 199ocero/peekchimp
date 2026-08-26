---
paths:
  - bootstrap/app.php
---

# Bootstrap

## Trust the Coolify proxy
Coolify terminates TLS before forwarding requests to the app container. Keep `$middleware->trustProxies(at: '*')` so Laravel honors `X-Forwarded-Proto` and generates HTTPS asset URLs.
