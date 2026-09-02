---
paths:
  - 'app/{Actions,Queries,Services}/Analytics/**'
---

# Actions Queries Services Analytics

## Keep visitor maps pseudonymous and project-day scoped
Persist only approximate city coordinates resolved transiently from IP; never persist raw IP addresses. Visitor maps run from local midnight through now in the project's configured timezone, mark visitors active for five minutes, and keep the rest visible until that local day ends.
