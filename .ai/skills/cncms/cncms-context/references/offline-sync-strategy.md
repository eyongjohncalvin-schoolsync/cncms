# Offline Sync Strategy — Field Agent Mobile Architecture

Status: **Design** | Applies to: v2 architecture

---

## 1. Problem Statement

SWECOM's field agents operate in areas of Kumba 3 with unreliable or absent internet
connectivity. Agents walk their assigned zones collecting payments and recording
expenditures. Under v1, agents had to return to the office with paper records for
manual data entry — slow, error-prone, and creates data lag of days.

The v2 architecture introduces a mobile app (Android, via Capacitor or React Native)
that works **fully offline** and syncs automatically when connectivity is restored.
The app uses a local SQLite database as its primary store, and a server-side
`sync_queue` table to manage bidirectional data flow.

---

## 2. Architecture Overview

```
+------------------+          +------------------+          +------------------+
|   Mobile App     |          |   Server (API)   |          |   PostgreSQL     |
|                  |  HTTP    |                  |  Eloquent |                  |
|  SQLite (local)  | <----->  |  sync_controller | <----->  |  tenant DB       |
|  - payments      |          |  sync_queue      |          |  - payments      |
|  - expenditures  |          |  audit_logs      |          |  - expenditures  |
|  - customers(*)  |          |                  |          |  - customers      |
|                  |          |                  |          |                  |
+------------------+          +------------------+          +------------------+
     |                                |
     | 1. Agent records               | 3. Server assigns UUID
     |    payment offline             |    and stores record
     |                                |
     | 2. Sync triggered              | 4. Server queues any
     |    (connectivity detected)      |    server-side changes
```

(*) Customers are read-only cache on mobile — agents cannot create or edit
customers from the field app. Customer data is pulled from server on first login
and refreshed on sync.

---

## 3. Local Database Schema (SQLite)

The mobile app maintains a subset of the server schema in SQLite:

```sql
-- Local payments (created offline or synced from server)
CREATE TABLE local_payments (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    local_uuid  TEXT NOT NULL UNIQUE,       -- client-generated UUID v4
    server_uuid TEXT,                       -- assigned by server on sync (NULL if pending)
    customer_id INTEGER NOT NULL,           -- FK to local_customers.id
    customer_server_uuid TEXT NOT NULL,      -- server UUID of the customer
    amount      REAL NOT NULL,
    credit      REAL DEFAULT 0,
    frequency   TEXT NOT NULL CHECK (frequency IN ('monthly','yearly','months')),
    expiration_date TEXT,
    months      INTEGER,
    recorded_offline INTEGER NOT NULL DEFAULT 1,
    created_at  TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at  TEXT NOT NULL DEFAULT (datetime('now')),
    sync_status TEXT NOT NULL DEFAULT 'pending'
                CHECK (sync_status IN ('pending','syncing','synced','conflict','failed'))
);

-- Local expenditures (created offline or synced from server)
CREATE TABLE local_expenditures (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    local_uuid      TEXT NOT NULL UNIQUE,
    server_uuid     TEXT,
    category_id     INTEGER NOT NULL,
    category_server_uuid TEXT NOT NULL,
    amount          REAL NOT NULL,
    description     TEXT,
    receipt_local_path TEXT,                -- local photo path before upload
    receipt_server_path TEXT,                -- server path after upload
    spent_at        TEXT NOT NULL,
    notes           TEXT,
    recorded_offline INTEGER NOT NULL DEFAULT 1,
    created_at      TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at      TEXT NOT NULL DEFAULT (datetime('now')),
    sync_status     TEXT NOT NULL DEFAULT 'pending'
                    CHECK (sync_status IN ('pending','syncing','synced','conflict','failed'))
);

-- Customer cache (read-only, synced from server)
CREATE TABLE local_customers (
    id          INTEGER PRIMARY KEY,
    server_uuid TEXT NOT NULL UNIQUE,
    zone_id     INTEGER,
    name        TEXT NOT NULL,
    phone       TEXT,
    bill        REAL NOT NULL,
    location    TEXT,
    level       TEXT,
    status      TEXT,
    last_synced TEXT NOT NULL DEFAULT (datetime('now'))
);

-- Sync metadata
CREATE TABLE sync_metadata (
    key     TEXT PRIMARY KEY,
    value   TEXT NOT NULL
);
-- Stores: last_sync_at, device_id, sync_token, server_url
```

**Key differences from server schema:**
- Uses TEXT for UUIDs (SQLite has no native UUID type)
- Uses REAL for amounts (sufficient for local calculations; server uses DECIMAL)
- `local_uuid` is generated on the client as UUID v4 before any server assignment
- `sync_status` tracks whether the record has been pushed to server
- `local_customers` is a denormalised cache — no FKs needed

---

## 4. Sync Protocol

### 4.1 Sync Initiation

The app attempts sync in these situations:
1. **Foreground sync** — user manually pulls down to refresh
2. **Background sync** — app detects connectivity change (WiFi/mobile)
3. **Periodic sync** — every 15 minutes while app is in foreground and online
4. **On payment save** — immediately after saving a new payment/expenditure

### 4.2 Push (Mobile -> Server)

```
POST /api/v1/sync/push
Authorization: Bearer {sanctum_token}
Content-Type: application/json

{
    "device_id": "abc123-device-fingerprint",
    "last_sync_at": "2026-08-17T10:30:00Z",
    "changes": {
        "payments": [
            {
                "local_uuid": "550e8400-e29b-41d4-a716-446655440000",
                "customer_uuid": "server-customer-uuid-here",
                "amount": 2500.00,
                "credit": 0,
                "frequency": "monthly",
                "months": null,
                "recorded_offline": true,
                "created_at": "2026-08-17T09:15:00Z"
            }
        ],
        "expenditures": [
            {
                "local_uuid": "660e8400-e29b-41d4-a716-446655440001",
                "category_uuid": "server-category-uuid-here",
                "amount": 1500.00,
                "description": "Fuel for zone rounds",
                "spent_at": "2026-08-17",
                "notes": null
            }
        ]
    }
}
```

**Server response:**
```json
{
    "status": "success",
    "synced_at": "2026-08-17T10:30:05Z",
    "results": {
        "payments": [
            {
                "local_uuid": "550e8400-e29b-41d4-a716-446655440000",
                "server_uuid": "server-assigned-uuid-v7",
                "status": "synced",
                "payment_verification_uuid": "verification-record-uuid"
            }
        ],
        "expenditures": [
            {
                "local_uuid": "660e8400-e29b-41d4-a716-446655440001",
                "server_uuid": "server-assigned-uuid-v7",
                "status": "synced"
            }
        ]
    },
    "errors": []
}
```

### 4.3 Pull (Server -> Mobile)

```
GET /api/v1/sync/pull?since=2026-08-17T10:30:00Z
Authorization: Bearer {sanctum_token}
```

**Server response:**
```json
{
    "synced_at": "2026-08-17T11:00:00Z",
    "changes": {
        "customers": {
            "upserted": [
                {"uuid": "...", "name": "...", "status": "active", ...}
            ],
            "deleted": ["uuid-of-deleted-customer"]
        },
        "payments": {
            "verified": [
                {"uuid": "...", "verification_status": "verified", ...}
            ],
            "rejected": [
                {"uuid": "...", "verification_status": "rejected", "rejection_reason": "..."}
            ]
        }
    }
}
```

### 4.4 Receipt Photo Upload

After a successful sync push, the app uploads receipt photos separately:

```
POST /api/v1/sync/upload-receipt
Content-Type: multipart/form-data

{
    "entity_type": "payment|expenditure",
    "entity_uuid": "server-uuid-v7",
    "receipt": <binary file>
}
```

Server stores the file in `storage/receipts/` and updates the corresponding record.

---

## 5. UUID Assignment Strategy

| Scenario | Who generates | Type | When assigned |
|---|---|---|---|
| Record created online (web) | Server | UUID v7 | At creation time |
| Record created online (mobile) | Server | UUID v7 | At creation time |
| Record created offline (mobile) | Client (temporary) | UUID v4 | At local creation |
| Record synced to server | Server (replacement) | UUID v7 | On sync confirmation |
| After sync, mobile stores | Both | UUID v7 | Server tells client |

**Flow for offline-created records:**
1. Agent creates payment offline -> app assigns `local_uuid` (UUID v4)
2. On sync push, server receives the record with `local_uuid`
3. Server creates the DB record, assigns `uuid` (UUID v7)
4. Server returns `{local_uuid -> server_uuid}` mapping
5. Mobile app updates `local_payments.server_uuid = server_uuid`
6. Mobile app updates `local_payments.sync_status = 'synced'`
7. For future API calls, mobile uses `server_uuid` (UUID v7)

---

## 6. Conflict Resolution

| Conflict type | Resolution | User action |
|---|---|---|
| Same entity edited on client AND server | **Server wins** | Mobile overwrites local with server version |
| New entity created on both sides | **Merge both** | No conflict — both records kept |
| Payment rejected by admin (server) after offline creation | **Server wins** | Mobile shows "Rejected" badge, agent can re-submit |
| Sync push fails (network) | **Retry with backoff** | Exponential backoff: 5s, 15s, 45s, 2m, 5m (max 5) |
| Permanent sync failure | **Flag for manual review** | Admin sees item in sync dashboard |

---

## 7. Data Integrity Checks

### On the mobile app (before sync)
- Validate customer UUID exists in local cache
- Validate category UUID exists in local cache (for expenditures)
- Validate amount is positive and within expected range
- Validate frequency is one of the allowed values

### On the server (during sync)
- Validate Sanctum token and device_id match
- Validate customer UUID exists in tenant database
- Validate category UUID exists for expenditures
- Validate agent has permission for the zone
- Check for duplicate detection (same customer, same amount, same date within 1 hour)
- Create audit_log entry for each synced record

### Post-sync reconciliation
- Server sends sync confirmation with counts
- Mobile compares local count vs server response count
- If counts mismatch, trigger a full re-sync (pull from `last_sync_at`)

---

## 8. Performance Considerations

### Mobile side
- SQLite WAL mode for concurrent read/write
- Index on `sync_status` for fast "pending" queries
- Batch push: send up to 50 changes per request
- Lazy customer cache refresh: only pull delta since `last_sync_at`
- Receipt photos: compress before upload (max 500KB per image)

### Server side
- Use `upsert` (INSERT ... ON CONFLICT) for idempotent sync processing
- Process sync_queue items in background jobs (Laravel queue)
- Rate limit sync endpoints: max 60 requests/minute per device
- Monitor `sync_queue` for stuck items (> 24 hours in 'pending')

---

## 9. Security

- All sync endpoints require valid Sanctum token
- Device ID is verified against `agents.sync_token`
- If a device token is revoked (agent leaves company), all pending syncs
  for that device are cancelled
- Offline data on the device is encrypted at rest (SQLCipher) if the
  mobile OS supports it (Android Keystore)
- Receipt photos are uploaded over HTTPS only
- Sync payloads are limited to 5MB per request

---

## 10. Monitoring & Debugging

### Metrics to track
- Sync success rate (synced / total attempted)
- Average sync latency (time from record creation to server confirmation)
- Offline time per agent (time between last sync and current sync)
- Failed sync count by device
- Conflict count by type

### Debug endpoint (admin only)
```
GET /api/v1/admin/sync/status?device_id=abc123
```

Returns: pending items, failed items, last sync time, conflict history.
