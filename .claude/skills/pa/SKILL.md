---
name: pa
description: The directors' personal assistant. Daily brief (today's top jobs, money board, who to pay, what's overdue), update the shared to-do list and money board, draft chasers and replies. Use when the user says /pa, "what's today", "what do I need to do", "who do I pay", or tells you money/to-do updates.
---

1. Hand the work to the `pa` agent (or follow `.claude/agents/pa.md` yourself).
2. It reads `docs/pa/todo.md`, the HANDOVER session log, BUSINESS_FACTS and the newest
   business review, then applies whatever the director just said (payments made,
   money received, new jobs, new to-dos).
3. It updates `docs/pa/todo.md` (dated) and replies with a phone-length brief:
   - Top 3 things today (ranked by money)
   - Money board: cash, owed to us, we owe, ring-fenced prepaid jobs
   - Overdue / due this week
   - Drafts ready to send (if any)
4. Nothing is sent; a director sends every message. Commit the to-do file with the next
   deploy (it's shared memory for both directors' Claude accounts).
