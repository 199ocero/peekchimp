---
paths:
  - 'app/Http/{Controllers,Requests}/WorkspaceMapboxSettings*.php'
---

# Controllers Requests

## Mapbox tokens are workspace-owned public credentials
Only workspace admins may manage the shared Mapbox token. Accept browser-safe pk. public tokens only, reject sk. secrets, encrypt the value at rest, and expose it solely to authenticated workspace dashboards.
