---
paths:
  - 'app/{Http/Controllers,Jobs,Services/SearchConsole}/**'
---

# Search Console

## Keep Search Console access tenant-safe
A Search Console property must exactly match a project's verified host; do not treat www, root, or subdomains as interchangeable. Only workspace admins may connect, sync, or disconnect credentials; members may view imported aggregate reports. Keep GSC data out of public dashboards, and disconnect must delete tokens, imported metrics, and GSC-derived insights.
