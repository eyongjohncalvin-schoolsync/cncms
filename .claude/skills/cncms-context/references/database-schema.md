# CNCMS Database Schema Reference

Database: `cncms` (single physical Postgres database) · Engine: PostgreSQL 16 · Charset: UTF-8
Extensions: `uuid-ossp`, `pgcrypto`, `btree_gin`

Tenancy: **schema-per-tenant**. Tenant tables below live in each tenant's own schema
(e.g. `tenant_swecom`), set via `search_path` when tenancy is initialized.

Central/`public` schema: stores `tenants`, `domains`, global `users`,
`personal_access_tokens` (Sanctum), and platform config.

---

## Table of Contents
1. [zones](#zones)
2. [customers](#customers)
3. [payments](#payments)
4. [payment_verifications](#payment-verifications)
5. [manuscripts](#manuscripts)
6. [agents](#agents)
7. [users](#users)
8. [companies](#companies)
9. [messages](#messages)
10. [uploads](#uploads)
11. [alerts](#alerts)
12. [command_runs](#command-runs)
13. [expense_categories](#expense-categories)
14. [expenditures](#expenditures)
15. [budgets](#budgets)
16. [audit_logs](#audit-logs)
17. [sync_queue](#sync-queue)
18. [user_activitylogs](#user-activitylogs)
19. [Central (public) Schema](#central-public-schema)

---

## ID Strategy: Dual-Key Pattern

Every tenant table uses this pattern:

```sql
id     BIGSERIAL PRIMARY KEY,          -- internal: fast auto-increment, never exposed
uuid   UUID NOT NULL DEFAULT gen_random_uuid() UNIQUE,  -- external: UUID v7 for API/offline
-- or for time-ordered UUID v7:
-- uuid   UUID NOT NULL DEFAULT uuid_generate_v7() UNIQUE,
```

**Usage rules:**
- **Internal JOINs and FKs** always reference `id` (BIGSERIAL) for performance.
- **API responses, mobile sync payloads, receipt codes** always use `uuid` (UUID).
- **FKs to other tables** point to `id` (the BIGSERIAL), not `uuid`.
- This prevents enumeration attacks (no sequential IDs in URLs) while keeping
  JOIN performance optimal with integer primary keys.

---

## zones

Geographic service areas. All 29 zones belong to `KUMBA 3`.

```sql
CREATE TABLE zones (
    id         BIGSERIAL PRIMARY KEY,
    uuid       UUID NOT NULL DEFAULT gen_random_uuid() UNIQUE,
    name       VARCHAR(25) NOT NULL UNIQUE,
    town       VARCHAR(25) DEFAULT 'KUMBA 3',
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW()
);

CREATE INDEX idx_zones_uuid ON zones (uuid);
CREATE INDEX idx_zones_name ON zones (name);
```

**Full zone list (ID -> Name):**
1=THR01 (3/CORNERS), 2=TR 03, 3=AR01(JUNCTION), 4=AR02(HANS), 5=AR03(SEC),
6=AR04, 7=AR 05 (BELMONT), 8=GARE 1, 9=LR R01 (LADUMA ROAD), 10=LR R02,
11=M R01 (DODO), 12=M R02, 13=M R03 (OIL MILL), 14=M R04 (JERUME),
15=MA R01, 16=MA R02(ELDER), 17=T R01, 18=T R02 (BAPTIST), 19=T R03 (BENJI),
20=T R04 (weldering), 21=T R05(BELTHA), 22=T R06(UNCLE ETA), 23=T R07(school),
24=TRANSMITTER, 25=MAHOLE 6, 26=NDIFANG, 27=BI, 28=TANCHA SCHOOL, 29=BANGA FARM

**Customer concentration (top zones):**
THR01 (3/CORNERS)=71, AR01(JUNCTION)=55, M R01 (DODO)=43,
LR R01 (LADUMA ROAD)=35, AR02(HANS)=34, M R04 (JERUME)=33

---

## customers

~549 registered subscribers.

```sql
CREATE TABLE customers (
    id          BIGSERIAL PRIMARY KEY,
    uuid        UUID NOT NULL DEFAULT gen_random_uuid() UNIQUE,
    zone_id     BIGINT NOT NULL REFERENCES zones(id) ON DELETE RESTRICT,
    name        VARCHAR(50) NOT NULL,
    location    VARCHAR(30),
    bill        DECIMAL(12,2) NOT NULL,           -- monthly rate in FCFA
    others      DECIMAL(12,2) DEFAULT 0,          -- initial carried-over balance
    phone       VARCHAR(20),                     -- normalised: 9 digits, leading 6
    description TEXT,
    level       VARCHAR(20) NOT NULL DEFAULT 'normal' CHECK (level IN ('normal','Vip','Operator')),
    status      VARCHAR(20) NOT NULL DEFAULT 'active' CHECK (status IN ('active','passive','disconnected','suspended')),
    created_at  TIMESTAMPTZ DEFAULT NOW(),
    updated_at  TIMESTAMPTZ DEFAULT NOW()
);

CREATE INDEX idx_customers_uuid ON customers (uuid);
CREATE INDEX idx_customers_zone ON customers (zone_id);
CREATE INDEX idx_customers_status ON customers (status);
CREATE INDEX idx_customers_level ON customers (level);
CREATE INDEX idx_customers_name ON customers (name);
CREATE INDEX idx_customers_phone ON customers (phone) WHERE phone IS NOT NULL;
```

**Bill rate distribution:**
- 2,000 FCFA — economy tier (~26 customers)
- 2,500 FCFA — standard tier (~274 customers, 62%)
- 3,000 FCFA — premium tier (~134 customers, 30%)
- 4,000-12,500 FCFA — VIP/Operator or special packages

**Status breakdown (from customer_upload_main.xlsx, 441 rows):**
- Active: 345 (78%)
- Disconnected: 96 (22%)

**Migration notes from v1:**
- `amount` column (DEFAULT 10000, legacy FLOAT) has been dropped
- `bill` and `others` migrated from FLOAT to DECIMAL(12,2)
- `phone` now VARCHAR(20) with normalised format — old multi-format values
  (`(67) 321-7927`, `6740774444`) cleaned during migration

---

## payments

2,591 payment records — the income ledger.

```sql
CREATE TABLE payments (
    id                BIGSERIAL PRIMARY KEY,
    uuid              UUID NOT NULL DEFAULT gen_random_uuid() UNIQUE,
    customer_id       BIGINT NOT NULL REFERENCES customers(id) ON DELETE RESTRICT,
    amount            DECIMAL(12,2) NOT NULL CHECK (amount > 0),
    credit            DECIMAL(12,2) DEFAULT 0,
    frequency         VARCHAR(10) NOT NULL CHECK (frequency IN ('monthly','yearly','months')),
    expiration_date   DATE,
    months            INT DEFAULT NULL CHECK (months > 0),
    verification_status VARCHAR(20) NOT NULL DEFAULT 'pending'
                        CHECK (verification_status IN ('pending','verified','rejected')),
    processed_at      TIMESTAMPTZ,
    recorded_offline  BOOLEAN NOT NULL DEFAULT FALSE,
    recorded_by_device VARCHAR(255),
    created_at         TIMESTAMPTZ DEFAULT NOW(),
    updated_at         TIMESTAMPTZ DEFAULT NOW()
);

CREATE INDEX idx_payments_uuid ON payments (uuid);
CREATE INDEX idx_payments_customer ON payments (customer_id);
CREATE INDEX idx_payments_processed ON payments (processed_at) WHERE processed_at IS NOT NULL;
CREATE INDEX idx_payments_verification ON payments (verification_status);
CREATE INDEX idx_payments_created ON payments (created_at);
CREATE INDEX idx_payments_customer_processed ON payments (customer_id, processed_at);
```

**New fields vs v1:**
- `uuid` — external identifier for API and mobile sync
- `verification_status` — tracks approval state (pending/verified/rejected)
- `recorded_offline` — TRUE if payment was created offline by a field agent
- `recorded_by_device` — device fingerprint of the mobile device that created the payment
- `amount` and `credit` changed from FLOAT to DECIMAL(12,2)

**Frequency logic:**
- `monthly` — single month payment, no expiration, processed at next month-end
- `months` — `N` months prepaid; `expiration_date = created_at + N months`
- `yearly` — `expiration_date = created_at + 12 months`

**Payment amount range:** 1,000 FCFA (partial) to 30,000 FCFA (12-month prepay at 2,500/mo)

---

## payment_verifications

New table — tracks the verification/approval workflow for payments.

```sql
CREATE TABLE payment_verifications (
    id              BIGSERIAL PRIMARY KEY,
    uuid            UUID NOT NULL DEFAULT gen_random_uuid() UNIQUE,
    payment_id      BIGINT NOT NULL REFERENCES payments(id) ON DELETE CASCADE,
    receipt_photo_path VARCHAR(255),                -- uploaded receipt image
    momo_ref        VARCHAR(50),                     -- MOMO transaction reference
    momo_status     VARCHAR(20) CHECK (momo_status IN ('confirmed','failed','pending','not_checked')),
    verified_by     BIGINT REFERENCES users(id),    -- NULL if still pending
    verified_at     TIMESTAMPTZ,
    status          VARCHAR(20) NOT NULL DEFAULT 'pending'
                     CHECK (status IN ('pending','approved','rejected')),
    notes           TEXT,
    created_at      TIMESTAMPTZ DEFAULT NOW(),
    updated_at      TIMESTAMPTZ DEFAULT NOW()
);

CREATE INDEX idx_pv_payment ON payment_verifications (payment_id);
CREATE INDEX idx_pv_status ON payment_verifications (status);
CREATE INDEX idx_pv_uuid ON payment_verifications (uuid);
```

**Workflow:**
1. Agent records payment (offline or online) -> `payment.verification_status = 'pending'`
2. Agent optionally attaches receipt photo -> `payment_verifications` row created
3. Admin/manager reviews and either approves or rejects
4. On approval: `payment.verification_status = 'verified'`, `momo_status` checked
5. On rejection: `payment.verification_status = 'rejected'`, payment excluded from manuscript
6. All verification actions are audit-logged

---

## manuscripts

521 records — one per customer, updated monthly. The billing ledger.

```sql
CREATE TABLE manuscripts (
    id                  BIGSERIAL PRIMARY KEY,
    uuid                UUID NOT NULL DEFAULT gen_random_uuid() UNIQUE,
    customer_id         BIGINT NOT NULL REFERENCES customers(id) ON DELETE CASCADE,
    bill                DECIMAL(12,2) NOT NULL,
    total_arrears       DECIMAL(12,2) NOT NULL DEFAULT 0,
    credit              DECIMAL(12,2) NOT NULL DEFAULT 0,
    total_bill          DECIMAL(12,2) NOT NULL DEFAULT 0,
    payment_expiration  DATE,
    period              VARCHAR(7),                   -- 'YYYY-MM'
    created_at          TIMESTAMPTZ DEFAULT NOW(),
    updated_at          TIMESTAMPTZ DEFAULT NOW()
);

CREATE INDEX idx_manuscripts_uuid ON manuscripts (uuid);
CREATE INDEX idx_manuscripts_customer ON manuscripts (customer_id);
CREATE INDEX idx_manuscripts_period ON manuscripts (period);
CREATE INDEX idx_manuscripts_customer_period ON manuscripts (customer_id, period);
```

**Core formula:** `total_bill = bill + total_arrears - credit`

**Migration notes from v1:**
- All FLOAT columns migrated to DECIMAL(12,2)
- Added `uuid` for API access
- `period` changed from TIMESTAMP to VARCHAR(7) for cleaner querying

**Special cases:**
- Disconnected customer: `total_bill = 0` (arrears frozen, no new charges)
- Prepaid customer with `payment_expiration` in future: `total_bill = 0`, credit consumed
- Credit > total due: `total_bill = 0`, remaining credit carried forward

---

## agents

Field collectors, one per zone (or shared across zones).

```sql
CREATE TABLE agents (
    id             BIGSERIAL PRIMARY KEY,
    uuid           UUID NOT NULL DEFAULT gen_random_uuid() UNIQUE,
    zone_id        BIGINT NOT NULL REFERENCES zones(id) ON DELETE RESTRICT,
    user_id        BIGINT REFERENCES users(id),        -- link to system user (nullable for now)
    name           VARCHAR(50) NOT NULL,
    location       VARCHAR(50) NOT NULL,
    phone          VARCHAR(20) NOT NULL,
    salary         DECIMAL(12,2) NOT NULL,
    email          VARCHAR(50),
    dob            DATE,
    marital_status VARCHAR(10) CHECK (marital_status IN ('yes','no')),
    children       INT DEFAULT 0,
    status         VARCHAR(10) NOT NULL DEFAULT 'active' CHECK (status IN ('active','inactive')),
    picture        VARCHAR(255),
    sync_token     VARCHAR(255) UNIQUE,                 -- for offline sync auth
    last_sync_at   TIMESTAMPTZ,
    created_at     TIMESTAMPTZ DEFAULT NOW(),
    updated_at     TIMESTAMPTZ DEFAULT NOW()
);

CREATE INDEX idx_agents_uuid ON agents (uuid);
CREATE INDEX idx_agents_zone ON agents (zone_id);
CREATE INDEX idx_agents_user ON agents (user_id);
CREATE INDEX idx_agents_sync ON agents (sync_token) WHERE sync_token IS NOT NULL;
```

**New fields vs v1:**
- `uuid` — for API and mobile sync references
- `user_id` — FK to users table (linking agent profile to login account)
- `sync_token` — unique token used by the mobile app for offline sync authentication
- `last_sync_at` — timestamp of the most recent successful sync
- `salary` already DECIMAL(12,2) (was the only field using DECIMAL in v1)

---

## users

System login accounts. Stored in the central `public` schema (not duplicated per
tenant) — accessible from any tenant schema via an ordinary cross-schema join, since
it's all one physical database.

```sql
-- In the public (central) schema:
CREATE TABLE users (
    id                BIGSERIAL PRIMARY KEY,
    uuid              UUID NOT NULL DEFAULT gen_random_uuid() UNIQUE,
    name              VARCHAR(255) NOT NULL,
    username          VARCHAR(50) NOT NULL UNIQUE,
    email             VARCHAR(255) NOT NULL UNIQUE,
    status            VARCHAR(10) NOT NULL DEFAULT 'active' CHECK (status IN ('active','passive')),
    email_verified_at TIMESTAMPTZ,
    password          VARCHAR(255) NOT NULL,
    remember_token    VARCHAR(100),
    created_at        TIMESTAMPTZ DEFAULT NOW(),
    updated_at        TIMESTAMPTZ DEFAULT NOW()
);

-- Per-tenant role assignment (in each tenant's own schema, e.g. tenant_swecom):
CREATE TABLE tenant_users (
    id         BIGSERIAL PRIMARY KEY,
    user_id    BIGINT NOT NULL REFERENCES public.users(id),  -- cross-schema FK to central users
    tenant_id  INT NOT NULL,                           -- references public.tenants.id
    role       VARCHAR(20) NOT NULL DEFAULT 'agent'
               CHECK (role IN ('super','admin','manager','agent','worker')),
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW(),
    UNIQUE (user_id, tenant_id)
);

CREATE INDEX idx_tenant_users_role ON tenant_users (role);
CREATE INDEX idx_tenant_users_user ON tenant_users (user_id);
```

**User roles:** `super`, `admin`, `manager`, `agent`, `worker`

**Real users (ID 6):** `Ebaieyong Kelvin Mekume` / username `miskhan` / role `super`
(IDs 1-5 are seeded test/demo users from initial migration)

**Sanctum tokens** are stored in Laravel's `personal_access_tokens` table (standard
Sanctum migration) in the central `public` schema, linked to `users.id`. Keeping this
central (rather than per-tenant) means token validation never has to guess which
tenant schema to check first — the tenant is resolved afterward, from the
authenticated user's `tenant_users` membership.

---

## companies

Single company record per tenant, for tenants to organised what happen on their documents...for example, recovery agents contact for a zone, 

```sql
CREATE TABLE companies (
    id           BIGSERIAL PRIMARY KEY,
    uuid         UUID NOT NULL DEFAULT gen_random_uuid() UNIQUE,
    name         VARCHAR(50) NOT NULL,         -- "SWECOM PLC"
    location     VARCHAR(30) NOT NULL,         -- "3/CORNERS"
    email        VARCHAR(50),                   -- shalomtech@gmail.com
    phone        VARCHAR(30) NOT NULL,          -- 679715363/672528022
    tech_number  VARCHAR(30),                   -- technical support line
    momo_number  VARCHAR(30),                   -- 676876509/672528022
    momo_name    VARCHAR(50),                   -- MUNGWAN HANS/KELVIN MEKUME
    logo         VARCHAR(255),
    created_at   TIMESTAMPTZ DEFAULT NOW(),
    updated_at   TIMESTAMPTZ DEFAULT NOW()
);
```

---

## messages

SMS/notification log per customer.

```sql
CREATE TABLE messages (
    id           BIGSERIAL PRIMARY KEY,
    uuid         UUID NOT NULL DEFAULT gen_random_uuid() UNIQUE,
    customer_id  BIGINT NOT NULL REFERENCES customers(id) ON DELETE CASCADE,
    content      TEXT NOT NULL,
    sid          VARCHAR(255),                 -- external message SID (Twilio-style)
    status       VARCHAR(20) NOT NULL DEFAULT 'sent',
    type         VARCHAR(20) NOT NULL,
    created_at   TIMESTAMPTZ DEFAULT NOW(),
    updated_at   TIMESTAMPTZ DEFAULT NOW()
);

CREATE INDEX idx_messages_customer ON messages (customer_id);
CREATE INDEX idx_messages_uuid ON messages (uuid);
```

---

## uploads

File import queue for bulk data loading.

```sql
CREATE TABLE uploads (
    id         BIGSERIAL PRIMARY KEY,
    uuid       UUID NOT NULL DEFAULT gen_random_uuid() UNIQUE,
    file_name  VARCHAR(255) NOT NULL,
    file_path  VARCHAR(255) NOT NULL,
    status     VARCHAR(20) NOT NULL DEFAULT 'pending'
               CHECK (status IN ('pending','processing','completed','failed')),
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW()
);
```

---

## alerts

Simple in-app notification store.

```sql
CREATE TABLE alerts (
    id         BIGSERIAL PRIMARY KEY,
    uuid       UUID NOT NULL DEFAULT gen_random_uuid() UNIQUE,
    name       VARCHAR(50) NOT NULL,
    message    TEXT NOT NULL,
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW()
);
```

---

## command_runs

Audit log of scheduled Artisan command executions.

```sql
CREATE TABLE command_runs (
    id         BIGSERIAL PRIMARY KEY,
    uuid       UUID NOT NULL DEFAULT gen_random_uuid() UNIQUE,
    command    VARCHAR(100) NOT NULL,        -- e.g. 'manuscript:calculate'
    period     VARCHAR(7) NOT NULL,          -- e.g. '2026-06'
    ran_at     TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    metadata   JSONB DEFAULT '{}',            -- run stats, row counts, errors
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW()
);

CREATE INDEX idx_command_runs_command ON command_runs (command);
CREATE INDEX idx_command_runs_period ON command_runs (period);
```

**Run history:** 14 executions of `manuscript:calculate` from `2025-05` to `2026-07`.
(Months 2026-02 and 2026-03 appear to be missing.)

**New:** `metadata` JSONB field stores run statistics (rows processed, errors, duration)
for richer observability.

---

## expense_categories

Reference table for expenditure categorisation (Resources module).

```sql
CREATE TABLE expense_categories (
    id          BIGSERIAL PRIMARY KEY,
    uuid        UUID NOT NULL DEFAULT gen_random_uuid() UNIQUE,
    name        VARCHAR(60) NOT NULL,
    icon        VARCHAR(30),                   -- Tabler icon class
    is_active   BOOLEAN NOT NULL DEFAULT TRUE,
    sort_order  SMALLINT DEFAULT 0,
    created_at  TIMESTAMPTZ DEFAULT NOW(),
    updated_at  TIMESTAMPTZ DEFAULT NOW()
);

CREATE INDEX idx_expense_cats_active ON expense_categories (is_active) WHERE is_active = TRUE;
```

**Seed data:**

| ID | Name | Icon |
|---|---|---|
| 1 | Staff & Labour | ti-users |
| 2 | Field Operations | ti-truck |
| 3 | Network Maintenance | ti-tool |
| 4 | Office & Admin | ti-building |
| 5 | Utilities | ti-bolt |
| 6 | MOMO Transaction Fees | ti-credit-card |
| 7 | Broadcaster / Signal Fees | ti-antenna |
| 8 | Equipment Purchase | ti-device-tv |
| 9 | Miscellaneous | ti-dots |

---

## expenditures

Daily expenditure entries (Resources module).

```sql
CREATE TABLE expenditures (
    id              BIGSERIAL PRIMARY KEY,
    uuid            UUID NOT NULL DEFAULT gen_random_uuid() UNIQUE,
    category_id     BIGINT NOT NULL REFERENCES expense_categories(id) ON DELETE RESTRICT,
    user_id         BIGINT NOT NULL REFERENCES users(id),
    amount          DECIMAL(12,2) NOT NULL CHECK (amount > 0),
    description     TEXT,
    receipt_path    VARCHAR(255),               -- uploaded receipt photo
    spent_at        DATE NOT NULL,
    notes           TEXT,
    recorded_offline BOOLEAN NOT NULL DEFAULT FALSE,
    recorded_by_device VARCHAR(255),
    created_at      TIMESTAMPTZ DEFAULT NOW(),
    updated_at      TIMESTAMPTZ DEFAULT NOW()
);

CREATE INDEX idx_expenditures_uuid ON expenditures (uuid);
CREATE INDEX idx_expenditures_category ON expenditures (category_id);
CREATE INDEX idx_expenditures_spent ON expenditures (spent_at);
CREATE INDEX idx_expenditures_user ON expenditures (user_id);
CREATE INDEX idx_expenditures_spent_category ON expenditures (spent_at, category_id);
```

---

## budgets

Optional monthly budget targets per category (Resources module, Phase 2).

```sql
CREATE TABLE budgets (
    id           BIGSERIAL PRIMARY KEY,
    uuid         UUID NOT NULL DEFAULT gen_random_uuid() UNIQUE,
    category_id  BIGINT NOT NULL REFERENCES expense_categories(id) ON DELETE RESTRICT,
    period       VARCHAR(7) NOT NULL,           -- 'YYYY-MM'
    amount       DECIMAL(12,2) NOT NULL CHECK (amount > 0),
    created_at   TIMESTAMPTZ DEFAULT NOW(),
    updated_at   TIMESTAMPTZ DEFAULT NOW(),
    UNIQUE (category_id, period)
);
```

---

## audit_logs

Comprehensive event-sourced audit trail. Records every mutation across all tenant tables.

```sql
CREATE TABLE audit_logs (
    id          BIGSERIAL PRIMARY KEY,
    tenant_id   INT NOT NULL,                    -- references landlord.tenants.id
    table_name  VARCHAR(100) NOT NULL,           -- e.g. 'payments', 'customers'
    record_uuid UUID NOT NULL,                  -- UUID of the affected record
    record_id   BIGINT,                          -- internal ID of the affected record
    action      VARCHAR(10) NOT NULL CHECK (action IN ('create','update','delete')),
    old_values  JSONB,                            -- previous state (NULL for create)
    new_values  JSONB,                            -- new state (NULL for delete)
    user_id     BIGINT REFERENCES users(id),     -- who performed the action
    ip_address  INET,                             -- client IP
    user_agent  TEXT,                             -- client user-agent string
    device_id   VARCHAR(255),                    -- for mobile/agent actions
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- GIN index for fast JSONB queries (e.g. "find all changes to customer status")
CREATE INDEX idx_audit_jsonb_new ON audit_logs USING GIN (new_values);
CREATE INDEX idx_audit_jsonb_old ON audit_logs USING GIN (old_values);
CREATE INDEX idx_audit_table ON audit_logs (table_name);
CREATE INDEX idx_audit_record ON audit_logs (record_uuid);
CREATE INDEX idx_audit_user ON audit_logs (user_id);
CREATE INDEX idx_audit_action ON audit_logs (action);
CREATE INDEX idx_audit_created ON audit_logs (created_at);
CREATE INDEX idx_audit_tenant ON audit_logs (tenant_id);

-- Prevent deletion of audit records
CREATE RULE audit_no_delete AS
    ON DELETE TO audit_logs DO INSTEAD NOTHING;
```

**Design principles:**
- Append-only — no UPDATE or DELETE allowed on audit_logs itself
- JSONB for old/new values allows flexible querying without schema changes
- GIN indexes enable fast JSONB containment queries
- `tenant_id` allows federated audit views across tenants
- Every model mutation fires an observer that writes to this table
- See `references/audit-strategy.md` for the full implementation spec

---

## sync_queue

Offline sync queue between mobile apps and server.

```sql
CREATE TABLE sync_queue (
    id              BIGSERIAL PRIMARY KEY,
    uuid            UUID NOT NULL DEFAULT gen_random_uuid() UNIQUE,
    device_id       VARCHAR(255) NOT NULL,        -- mobile device fingerprint
    direction       VARCHAR(5) NOT NULL CHECK (direction IN ('up','down')),
    entity_type     VARCHAR(50) NOT NULL,           -- 'payment', 'expenditure', 'customer'
    entity_uuid     UUID,                            -- UUID of the entity (assigned on server sync)
    local_uuid      UUID,                            -- client-generated UUID (for 'up' items)
    payload         JSONB NOT NULL,                  -- full entity data
    status          VARCHAR(20) NOT NULL DEFAULT 'pending'
                    CHECK (status IN ('pending','processing','synced','conflict','failed')),
    attempt_count   SMALLINT DEFAULT 0,
    attempted_at    TIMESTAMPTZ,
    completed_at    TIMESTAMPTZ,
    error_message   TEXT,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_sync_device ON sync_queue (device_id);
CREATE INDEX idx_sync_status ON sync_queue (status);
CREATE INDEX idx_sync_direction ON sync_queue (direction, status);
CREATE INDEX idx_sync_entity ON sync_queue (entity_type, entity_uuid);
CREATE INDEX idx_sync_created ON sync_queue (created_at);
```

**Direction logic:**
- `up` — mobile -> server (agent recorded something offline)
- `down` — server -> mobile (admin updated something, needs to reach the device)

See `references/offline-sync-strategy.md` for the full sync protocol.

---

## bill_batches / bill_batch_files

**New tables** (tenant-scoped, migration `2026_08_30_120000_create_bill_batches_tables.php`,
already run on `swecom` + `multimedia-digital-cable-network`). Back the async bulk bill
generation — see `bill-printing.md` §4.

```sql
CREATE TABLE bill_batches (
    id            BIGSERIAL PRIMARY KEY,
    uuid          UUID NOT NULL UNIQUE DEFAULT gen_random_uuid(),
    period        VARCHAR(7) NOT NULL,                 -- 'YYYY-MM'
    status        VARCHAR(20) NOT NULL DEFAULT 'queued', -- queued|processing|completed|partial|failed|cancelled
    density       SMALLINT NOT NULL DEFAULT 1,         -- bills_per_page snapshot (1-4)
    template      VARCHAR(32) NOT NULL DEFAULT 'classic',
    filters       JSONB,                               -- {period, zone_uuid?, status?, search?}
    total_bills   INTEGER NOT NULL DEFAULT 0,
    total_zones   INTEGER NOT NULL DEFAULT 0,          -- expected per-zone PDF count (completed vs partial)
    generated_by  BIGINT REFERENCES public.users(id) ON DELETE SET NULL,  -- cross-schema FK
    batch_id      VARCHAR(255),                        -- Illuminate\Bus\Batch id (job_batches, central schema)
    error_message TEXT,
    started_at    TIMESTAMPTZ,
    completed_at  TIMESTAMPTZ,
    created_at    TIMESTAMPTZ,
    updated_at    TIMESTAMPTZ
);
CREATE INDEX idx_bill_batches_period ON bill_batches (period, created_at);
CREATE INDEX idx_bill_batches_generated_by ON bill_batches (generated_by);

CREATE TABLE bill_batch_files (
    id            BIGSERIAL PRIMARY KEY,
    uuid          UUID NOT NULL UNIQUE DEFAULT gen_random_uuid(),
    bill_batch_id BIGINT NOT NULL REFERENCES bill_batches(id) ON DELETE CASCADE,
    zone_id       BIGINT REFERENCES zones(id) ON DELETE SET NULL,  -- NULL = the bulk PDF or the ZIP
    zone_name     VARCHAR(255),                        -- denormalized (stable if a zone is renamed/deleted)
    kind          VARCHAR(16) NOT NULL DEFAULT 'zone', -- zone | bulk | zip
    disk          VARCHAR(32) NOT NULL DEFAULT 'local',
    path          VARCHAR(255) NOT NULL,               -- bill-batches/{tenantId}/{batchUuid}/...
    bill_count    INTEGER NOT NULL DEFAULT 0,
    page_count    INTEGER,
    size_bytes    BIGINT NOT NULL DEFAULT 0,
    created_at    TIMESTAMPTZ,
    updated_at    TIMESTAMPTZ
);
CREATE INDEX idx_bill_batch_files_batch ON bill_batch_files (bill_batch_id);
```

---

## user_activitylogs

Session/activity tracking per user (existing, enhanced).

```sql
CREATE TABLE user_activitylogs (
    id             BIGSERIAL PRIMARY KEY,
    user_id        BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    last_activity  TIMESTAMPTZ,
    lockout_token  VARCHAR(255),
    ip_address     INET,
    device_id      VARCHAR(255),
    created_at     TIMESTAMPTZ DEFAULT NOW(),
    updated_at     TIMESTAMPTZ DEFAULT NOW()
);
```

---

## Central (`public`) Schema

The `public` schema of the single `cncms` database is managed by Stancl tenancy.

```sql
-- public schema (central)

CREATE TABLE tenants (
    id         BIGSERIAL PRIMARY KEY,
    uuid       UUID NOT NULL DEFAULT gen_random_uuid() UNIQUE,
    name       VARCHAR(100) NOT NULL,             -- e.g. 'SWECOM PLC'
    slug       VARCHAR(50) NOT NULL UNIQUE,       -- e.g. 'swecom'
    schema     VARCHAR(100) NOT NULL UNIQUE,      -- e.g. 'tenant_swecom'
    domain     VARCHAR(100),                      -- subdomain: 'swecom.cncms.app'
    is_active  BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW()
);

-- Users table lives centrally in public (see users section above)
-- Stancl tenancy switches the Postgres search_path per tenant schema; the
-- tenant_users pivot (inside each tenant schema) maps central users to roles
```

---

## Sequences and Auto-Increment Ceilings (as of July 2026 export)

| Table | Next ID | Meaning |
|---|---|---|
| customers | 550 | ~549 customers registered |
| manuscripts | 522 | 521 billing records |
| payments | 2592 | 2,591 payments recorded |
| zones | 30 | 29 zones |
| users | 7 | 6 users |
| command_runs | 15 | 14 manuscript runs |

**Note:** PostgreSQL sequences survive migration. After importing existing data
with explicit IDs, sequences must be reset:

```sql
SELECT setval('customers_id_seq', (SELECT MAX(id) FROM customers));
SELECT setval('payments_id_seq', (SELECT MAX(id) FROM payments));
-- ... repeat for all tables
```

---

## Migration Strategy (MariaDB -> PostgreSQL)

### Automated via Laravel migration

1. **Export MariaDB data** as JSON/CSV with explicit integer IDs
2. **Create PostgreSQL schema** with new columns (uuid, verification_status, etc.)
3. **Import data** mapping old INT IDs to new BIGSERIAL IDs (preserved for continuity)
4. **Generate UUIDs** for all existing records using `gen_random_uuid()`
5. **Migrate FLOAT -> DECIMAL(12,2)** for bill, amount, credit, arrears, total_bill
6. **Normalise phone numbers** during import (strip formatting, enforce 9-digit + leading 6)
7. **Reset sequences** after bulk import
8. **Seed Stancl tenant** record for SWECOM (creates the `tenant_swecom` schema) in
   the central `public` schema
9. **Run integrity checks** — FK counts should match, manuscript formulas should recalculate correctly

### Things that break and need attention

- MariaDB `ENUM` types become PostgreSQL `CHECK` constraints or `VARCHAR` with CHECK
- MariaDB `TIMESTAMP` becomes PostgreSQL `TIMESTAMPTZ` (timezone-aware)
- MariaDB `AUTO_INCREMENT` becomes PostgreSQL `BIGSERIAL` (sequence-based)
- MariaDB `FLOAT` becomes PostgreSQL `DECIMAL(12,2)` — may reveal rounding artefacts
- MariaDB `ON DELETE CASCADE` semantics are identical, but verify all FK chains
- MariaDB group_concat -> PostgreSQL STRING_AGG
- MariaDB IF() -> PostgreSQL CASE WHEN
