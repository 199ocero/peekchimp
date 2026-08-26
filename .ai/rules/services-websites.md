---
paths:
  - 'app/Models/User.php,app/Policies/ProjectPolicy.php,app/Services/Websites/**'
---

# Services Websites

## Resolve website access through the workspace owner
The sole admin owns the workspace projects. Invited members store workspace_owner_id and resolve project access, onboarding, website creation, and current-site selection through that owner.
