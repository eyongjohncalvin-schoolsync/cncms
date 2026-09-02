# Work plans — coordination

Three initiatives requested 2026-09-02, to run **one wave at a time** (the
shared dev Postgres deadlocks under concurrent `UsesDisposableTenant` /
transaction-wrapped test runs — see
`.claude/skills/cncms-context/references/` and MEMORY). No two implementation
agents touch the same files in the same wave.

| # | Plan | Waves | Status |
|---|---|---|---|
| 1 | [RBAC v2 — configurable roles & permissions](./rbac-v2-configurable-roles.md) | 4 | Wave 1 built (awaiting coordinator commit); Waves 2-4 not started |
| 2 | [Payment receipts + WhatsApp send](./payment-receipts-and-whatsapp.md) | 3 | not started |
| 3 | [Customer record export](./customer-record-export.md) | 1 | not started |

## Execution order

RBAC v2 is the long pole and touches every policy/controller. Receipts and
the customer export both add rows to the "what can this role do" surface, so
they must land **after** RBAC v2 wave 2 (enforcement swap) or they'll be
written against the old `isAnyOf()` pattern and need reworking.

```
RBAC w1  →  RBAC w2  →  ┬→ RBAC w3  →  RBAC w4
                        ├→ Receipts w1 → w2 → w3
                        └→ Customer export w1
```

RBAC w3/w4, Receipts, and Customer export CAN overlap in calendar time once
RBAC w2 is merged, but each still runs its test suite alone. The coordinator
(main session) shepherds one agent at a time, verifies, commits, then starts
the next.

## Rules for every agent

- Branch `prepayment-drawdown-credit` (pushes to `origin/preview`).
- Read the plan doc AND `.claude/skills/cncms-context/references/rbac-permissions.md`
  (the v1 "deliberately shelved the matrix" history — v2 is narrower than
  what was shelved; do not re-expand it) before writing code.
- Match existing code style and the heavy "why" comment convention.
- Write tests. Run ONLY your plan's named test files, one `phpunit` process
  at a time. If a run hangs >3 min, a killed run left an `idle in transaction`
  Postgres backend — `pg_terminate_backend` it (creds in `.env`) and retry.
  Never launch parallel test runs.
- Do NOT commit or push — hand back to the coordinator with a file list +
  test results. The coordinator commits.
- Update this table's Status column and the plan doc's checklist as you finish.
