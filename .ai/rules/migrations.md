---
paths:
  - 'app/{Ai,Services/Ai,Http/Controllers}/**,app/Models/User.php,database/migrations/*agent_conversations*,database/migrations/*chat_conversation_contexts*'
---

# Migrations

## Keep dashboard chat on SDK conversations
Use Laravel AI's published agent_conversations / agent_conversation_messages migration and Conversation models as the chat history source of truth. Keep website scoping in chat_conversation_contexts, authorize by both the SDK participant and current project, and inject project_id server-side into wrapped read-only MCP tools.
