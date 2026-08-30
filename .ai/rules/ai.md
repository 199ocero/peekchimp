---
paths:
  - app/Services/Ai/ChatConversationService.php
---

# Ai

## Hydrate only the latest approval state
Only the conversation's newest row with approval_state can represent an active approval pause. If its pending map is empty, return no approval cards; never scan backward for an older non-empty pause because abandoned approvals remain stored for SDK replay and deduplication.
