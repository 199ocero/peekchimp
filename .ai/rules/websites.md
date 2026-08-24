---
paths:
  - 'app/Services/Websites/**'
---

# Websites

## Persist the selected website
Store the selected active website in users.current_project_id. Dashboard and shared navigation resolve that verified project, falling back to the oldest active verified project when the selection is empty or invalid. Pending websites remain setup_required and cannot be selected until verified.
