# Audit Strategy — Comprehensive Event-Sourced Audit Trail

Status: **Design** | Applies to: v2 architecture

---

## 1. Problem Statement

The v1 system had minimal audit capability — only a `user_activitylogs` table that
tracked login sessions, and `command_runs` for scheduled commands. There was no way to
answer questions like:

- Who changed a customer's status from active to disconnected, and when?
- Who recorded this payment, and was it verified?
- What was the previous value of this customer's monthly bill before the admin edited it?
- Who deleted an expenditure entry, and what was its value?
- What did this customer's arrears look like 6 months ago?

The v2 architecture introduces a comprehensive, immutable audit trail that captures
every mutation across all tenant tables as structured JSONB events.

---

## 2. Design Principles

1. **Append-only** — audit_logs records are never updated or deleted. Even if the
   source record is deleted, the audit entry remains forever.
2. **Complete before/after state** — every UPDATE captures both the old and new values
   as full JSONB objects, not just diffs.
3. **Automatic via observers** — Laravel model observers fire on every Eloquent event
   (created, updated, deleted) and write to audit_logs. No developer needs to remember
   to log manually.
4. **JSONB for flexibility** — storing old/new values as JSONB means the audit trail
   automatically adapts to schema changes without migration.
5. **Tenant-aware** — every audit record includes `tenant_id` for federated queries
   across tenants (for the landlord/super admin).
6. **Queryable** — PostgreSQL GIN indexes on JSONB columns allow fast queries like
   "find all times customer status changed to disconnected" without full table scans.

---

## 3. Database Schema

```sql
CREATE TABLE audit_logs (
    id          BIGSERIAL PRIMARY KEY,
    tenant_id   INT NOT NULL,                    -- references landlord.tenants.id
    table_name  VARCHAR(100) NOT NULL,           -- e.g. 'payments', 'customers'
    record_uuid UUID NOT NULL,                    -- UUID of the affected record
    record_id   BIGINT,                           -- internal ID of the affected record
    action      VARCHAR(10) NOT NULL CHECK (action IN ('create','update','delete')),
    old_values  JSONB,                            -- previous state (NULL for create)
    new_values  JSONB,                            -- new state (NULL for delete)
    user_id     BIGINT REFERENCES users(id),      -- who performed the action
    ip_address  INET,                             -- client IP
    user_agent  TEXT,                             -- client user-agent string
    device_id   VARCHAR(255),                      -- for mobile/agent actions
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- GIN indexes for fast JSONB queries
CREATE INDEX idx_audit_jsonb_new ON audit_logs USING GIN (new_values);
CREATE INDEX idx_audit_jsonb_old ON audit_logs USING GIN (old_values);

-- Standard B-tree indexes
CREATE INDEX idx_audit_table ON audit_logs (table_name);
CREATE INDEX idx_audit_record ON audit_logs (record_uuid);
CREATE INDEX idx_audit_record_id ON audit_logs (record_id);
CREATE INDEX idx_audit_user ON audit_logs (user_id);
CREATE INDEX idx_audit_action ON audit_logs (action);
CREATE INDEX idx_audit_created ON audit_logs (created_at);
CREATE INDEX idx_audit_tenant ON audit_logs (tenant_id);

-- Composite indexes for common query patterns
CREATE INDEX idx_audit_table_record ON audit_logs (table_name, record_uuid);
CREATE INDEX idx_audit_table_action ON audit_logs (table_name, action);
CREATE INDEX idx_audit_tenant_created ON audit_logs (tenant_id, created_at DESC);

-- Immutable: prevent deletion via database rule
CREATE RULE audit_no_delete AS
    ON DELETE TO audit_logs DO INSTEAD NOTHING;
```

---

## 4. Laravel Implementation

### 4.1 Audit Observer (auto-registered for all tenant models)

```php
// App\Observers\AuditableObserver

class AuditableObserver
{
    public function created($model): void
    {
        $this->writeAudit($model, 'create');
    }

    public function updated($model): void
    {
        $this->writeAudit($model, 'update');
    }

    public function deleted($model): void
    {
        $this->writeAudit($model, 'delete');
    }

    protected function writeAudit($model, string $action): void
    {
        AuditLog::create([
            'tenant_id'   => tenant()->id,
            'table_name'  => $model->getTable(),
            'record_uuid' => $model->uuid,
            'record_id'   => $model->id,
            'action'      => $action,
            'old_values'  => $action === 'create' ? null : $model->getOriginal(),
            'new_values'  => $action === 'delete' ? null : $model->getAttributes(),
            'user_id'     => auth()->id(),
            'ip_address'  => request()->ip(),
            'user_agent'  => request()->userAgent(),
            'device_id'   => request()->header('X-Device-ID'),
        ]);
    }
}
```

### 4.2 Trait for auditable models

```php
// App\Traits\Auditable

trait Auditable
{
    public static function bootAuditable(): void
    {
        static::observe(AuditableObserver::class);
    }
}
```

Add `use Auditable;` to every model that should be audit-tracked:
- Customer, Payment, Manuscript, Agent, Zone, Expenditure,
  ExpenseCategory, Budget, Company, Message

### 4.3 Excluded fields (noise reduction)

Some fields change on every update but are not meaningful for audit:

```php
// In AuditableObserver
protected function filterFields(array $attributes): array
{
    return Arr::except($attributes, [
        'created_at',
        'updated_at',
        'last_sync_at',     // changes on every sync
        'sync_status',      // changes during sync process
        'attempt_count',    // sync retry counter
    ]);
}
```

---

## 5. Audit Queries (Practical Examples)

### Who changed a customer's status?

```sql
SELECT al.created_at,
       u.name AS actor,
       al.old_values->>'status' AS old_status,
       al.new_values->>'status' AS new_status,
       al.ip_address
FROM audit_logs al
JOIN users u ON al.user_id = u.id
WHERE al.table_name = 'customers'
  AND al.record_uuid = 'specific-customer-uuid'
  AND al.action = 'update'
  AND (
       al.old_values->>'status' IS DISTINCT FROM al.new_values->>'status'
    OR al.old_values->>'bill' IS DISTINCT FROM al.new_values->>'bill'
  )
ORDER BY al.created_at DESC;
```

### Full payment history (who recorded, who verified)

```sql
-- Payment creation
SELECT al.created_at,
       u.name AS actor,
       al.action,
       al.new_values->>'amount' AS amount,
       al.new_values->>'verification_status' AS verification_status,
       al.device_id
FROM audit_logs al
JOIN users u ON al.user_id = u.id
WHERE al.table_name = 'payments'
  AND al.record_uuid = 'specific-payment-uuid'
ORDER BY al.created_at ASC;
```

### All deletions in the last 30 days

```sql
SELECT al.created_at,
       u.name AS actor,
       al.table_name,
       al.record_uuid,
       al.old_values   -- full state before deletion
FROM audit_logs al
JOIN users u ON al.user_id = u.id
WHERE al.action = 'delete'
  AND al.created_at > NOW() - INTERVAL '30 days'
ORDER BY al.created_at DESC;
```

### Customer arrears history (time travel query)

```sql
SELECT al.created_at,
       al.new_values->>'total_arrears' AS arrears,
       al.new_values->>'total_bill' AS total_bill,
       al.new_values->>'credit' AS credit
FROM audit_logs al
WHERE al.table_name = 'manuscripts'
  AND al.record_uuid = 'specific-manuscript-uuid'
  AND al.action IN ('create', 'update')
ORDER BY al.created_at ASC;
```

### Agent activity summary (daily)

```sql
SELECT DATE(al.created_at) AS date,
       u.name AS agent,
       COUNT(*) FILTER (WHERE al.table_name = 'payments' AND al.action = 'create') AS payments_recorded,
       COUNT(*) FILTER (WHERE al.table_name = 'expenditures' AND al.action = 'create') AS expenses_recorded,
       COUNT(*) FILTER (WHERE al.action = 'update') AS updates
FROM audit_logs al
JOIN users u ON al.user_id = u.id
WHERE al.created_at > NOW() - INTERVAL '7 days'
  AND al.table_name IN ('payments', 'expenditures')
GROUP BY DATE(al.created_at), u.name
ORDER BY date DESC, agent;
```

---

## 6. Payment Verification Audit

Every verification action (approve/reject) is captured as an audit event on the
`payment_verifications` table:

```
Event: payment_verifications.update
old_values: {"status": "pending"}
new_values: {"status": "approved", "verified_by": 2, "verified_at": "2026-08-17T10:30:00Z"}
user_id: 2 (the admin who approved)
```

This creates a complete chain of custody for every payment:

1. **Creation:** Agent creates payment -> audit: `payments.create`
2. **Verification request:** (optional) Agent attaches receipt -> audit: `payment_verifications.create`
3. **Approval:** Admin approves -> audit: `payment_verifications.update` (pending -> approved)
4. **Billing:** manuscript:calculate processes it -> audit: `payments.update` (processed_at set)
5. **Rejection (if any):** Admin rejects -> audit: `payment_verifications.update` (pending -> rejected)

---

## 7. Retention & Storage

### Estimated volume

| Table | Daily events | Monthly | Yearly |
|---|---|---|---|
| payments (create) | ~15 | ~450 | ~5,400 |
| payments (update/verify) | ~30 | ~900 | ~10,800 |
| manuscripts (update) | ~521 (monthly) | ~521 | ~6,252 |
| customers (update) | ~5 | ~150 | ~1,800 |
| expenditures (create) | ~5 | ~150 | ~1,800 |
| Other | ~10 | ~300 | ~3,600 |
| **Total** | **~65/day** | **~2,470** | **~29,650** |

At ~65 events/day with ~500 bytes per JSONB event, annual storage is approximately:
`65 * 500 * 365 = ~11.8 MB/year`

PostgreSQL handles this without any performance issues for years. No archiving
needed for the first 5 years of operation.

### Archiving strategy (optional, phase 2)

For deployments that grow beyond 10 tenants or 5 years of history:

```sql
-- Archive audit_logs older than 3 years to a separate table
CREATE TABLE audit_logs_archive (LIKE audit_logs INCLUDING ALL);

INSERT INTO audit_logs_archive
SELECT * FROM audit_logs WHERE created_at < NOW() - INTERVAL '3 years';

DELETE FROM audit_logs WHERE created_at < NOW() - INTERVAL '3 years';
```

The archive table can be moved to a cheaper storage tier or a separate database
while remaining queryable for compliance investigations.

---

## 8. Compliance & Security Considerations

- **Immutable by design:** Database-level rule prevents deletion
- **No UPDATE:** Audit records are never modified after creation
- **User attribution:** Every event links to a user_id (NULL for system/scheduled events)
- **IP tracking:** Client IP stored for every web-initiated action
- **Device tracking:** Mobile device ID stored for field agent actions
- **Tenant isolation:** `tenant_id` ensures audit queries never cross tenant boundaries
  (enforced at query level by Stancl tenancy middleware)
- **Super admin view:** Landlord-level users can query federated audit across all tenants
  via the central database connection

---

## 9. UI Integration

### Admin audit viewer (web)

**Route:** `GET /audit/logs`

Filters:
- Table name (dropdown)
- Action type (create/update/delete)
- User (dropdown)
- Date range (date picker)
- Record UUID (search)

Columns: Timestamp | User | Table | Action | Summary | Details (expandable JSON)

The summary column shows a human-readable diff:
- "Changed customer status: active -> disconnected"
- "Recorded payment: 2,500 FCFA"
- "Verified payment: approved by Admin Kelvin"
- "Deleted expenditure: 1,500 FCFA (Staff & Labour)"

### API endpoint

**Route:** `GET /api/v1/audit/logs?table=payments&record_uuid=...&from=...&to=...`

Returns paginated audit events with full old/new JSONB values. Available to
admin/manager/super roles only.
