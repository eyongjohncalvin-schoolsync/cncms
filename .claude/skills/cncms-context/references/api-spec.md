# CNCMS RESTful API Specification

Status: **v2 Design** | Base URL: `/api/v1` | Auth: Laravel Sanctum (Bearer token)

---

## 1. Authentication

### 1.1 Login (issue token)

```
POST /api/v1/auth/login
Content-Type: application/json

{
    "username": "agent01",
    "password": "********"
}

Response 200:
{
    "user": {
        "uuid": "...",
        "name": "Agent Name",
        "username": "agent01",
        "role": "agent",
        "agent": {
            "uuid": "...",
            "zone_uuid": "...",
            "zone_name": "THR01 (3/CORNERS)",
            "sync_token": "..."
        }
    },
    "token": "1|abc123sanctumtoken...",
    "permissions": ["payment:record", "expenditure:record", "customer:view"]
}
```

### 1.2 Logout (revoke token)

```
POST /api/v1/auth/logout
Authorization: Bearer {token}

Response 200:
{
    "message": "Logged out"
}
```

### 1.3 Refresh token

```
POST /api/v1/auth/refresh
Authorization: Bearer {token}

Response 200:
{
    "token": "2|newtoken..."
}
```

---

## 2. Customers

### 2.1 List customers

```
GET /api/v1/customers?page=1&per_page=25&zone={uuid}&status=active&search=john
Authorization: Bearer {token}

Response 200:
{
    "data": [
        {
            "uuid": "...",
            "name": "JOHN DOE",
            "phone": "677440670",
            "zone_uuid": "...",
            "zone_name": "THR01 (3/CORNERS)",
            "bill": 2500.00,
            "others": 0,
            "level": "normal",
            "status": "active",
            "location": "3/Corners"
        }
    ],
    "meta": {
        "current_page": 1,
        "per_page": 25,
        "total": 549,
        "last_page": 22
    }
}
```

**Filters:** `zone` (UUID), `status` (active/passive/disconnected/suspended),
`level` (normal/Vip/Operator), `search` (name, phone), `has_phone` (boolean),
`arrears_gt` (amount).

### 2.2 Show customer

```
GET /api/v1/customers/{uuid}
Authorization: Bearer {token}

Response 200:
{
    "uuid": "...",
    "name": "JOHN DOE",
    "phone": "677440670",
    "zone_uuid": "...",
    "zone_name": "THR01 (3/CORNERS)",
    "bill": 2500.00,
    "others": 0,
    "level": "normal",
    "status": "active",
    "location": "3/Corners",
    "created_at": "2025-01-15T10:30:00Z",
    "manuscript": {
        "uuid": "...",
        "bill": 2500.00,
        "total_arrears": 2500.00,
        "credit": 0,
        "total_bill": 5000.00,
        "payment_expiration": null,
        "period": "2026-08"
    },
    "recent_payments": [
        {
            "uuid": "...",
            "amount": 2500.00,
            "frequency": "monthly",
            "verification_status": "verified",
            "created_at": "2026-07-28T14:00:00Z"
        }
    ]
}
```

### 2.3 Create customer (admin/manager only)

```
POST /api/v1/customers
Authorization: Bearer {token} (role: admin, manager, super)

{
    "name": "NEW CUSTOMER",
    "phone": "670000000",
    "zone_uuid": "...",
    "bill": 2500.00,
    "level": "normal",
    "status": "active",
    "location": "3/Corners"
}
```

### 2.4 Update customer

```
PATCH /api/v1/customers/{uuid}
Authorization: Bearer {token} (role: admin, manager, super)

{
    "bill": 3000.00,
    "status": "disconnected"
}
```

---

## 3. Payments

### 3.1 List payments

```
GET /api/v1/payments?page=1&per_page=25&customer_uuid=...&verification_status=verified&from=2026-07-01&to=2026-07-31
Authorization: Bearer {token}

Response 200:
{
    "data": [
        {
            "uuid": "...",
            "customer_uuid": "...",
            "customer_name": "JOHN DOE",
            "amount": 2500.00,
            "credit": 0,
            "frequency": "monthly",
            "months": null,
            "expiration_date": null,
            "verification_status": "verified",
            "recorded_offline": true,
            "created_at": "2026-07-28T14:00:00Z",
            "processed_at": "2026-08-01T00:00:00Z"
        }
    ],
    "meta": { ... }
}
```

**Filters:** `customer_uuid`, `verification_status` (pending/verified/rejected),
`frequency`, `recorded_offline`, `from`, `to`, `zone_uuid` (via customer join).

### 3.2 Create payment

```
POST /api/v1/payments
Authorization: Bearer {token}

{
    "customer_uuid": "...",
    "amount": 2500.00,
    "credit": 0,
    "frequency": "monthly",
    "months": null,
    "recorded_offline": false
}

Response 201:
{
    "message": "Payment recorded",
    "payment": {
        "uuid": "...",
        "amount": 2500.00,
        "verification_status": "pending",
        ...
    },
    "note": "Payment requires verification by an admin before billing"
}
```

**Behaviour:**
- If user is admin/super: `verification_status = 'verified'` (auto-approved)
- If user is agent: `verification_status = 'pending'` (requires review)
- `recorded_offline` is set by the sync system, not by the client directly

### 3.3 Show payment

```
GET /api/v1/payments/{uuid}
Authorization: Bearer {token}

Response 200:
{
    "uuid": "...",
    "customer_uuid": "...",
    "customer_name": "JOHN DOE",
    "amount": 2500.00,
    "credit": 0,
    "frequency": "monthly",
    "months": null,
    "expiration_date": null,
    "verification_status": "pending",
    "recorded_offline": false,
    "created_at": "...",
    "processed_at": null,
    "verification": {
        "uuid": "...",
        "status": "pending",
        "receipt_photo_url": null,
        "momo_ref": null,
        "momo_status": null,
        "verified_by": null,
        "verified_at": null,
        "notes": null
    },
    "audit_trail": [
        {
            "action": "create",
            "user_name": "Agent Name",
            "created_at": "...",
            "new_values": { "amount": 2500, ... }
        }
    ]
}
```

### 3.4 Verify payment (admin/manager)

```
POST /api/v1/payments/{uuid}/verify
Authorization: Bearer {token} (role: admin, manager, super)

{
    "action": "approve",
    "momo_ref": "MOMO-20260817-001",
    "notes": "Confirmed via MOMO statement"
}

Response 200:
{
    "message": "Payment verified",
    "payment": {
        "uuid": "...",
        "verification_status": "verified"
    }
}
```

```
POST /api/v1/payments/{uuid}/verify
Authorization: Bearer {token}

{
    "action": "reject",
    "notes": "Amount does not match MOMO record. Expected 3000 FCFA."
}

Response 200:
{
    "message": "Payment rejected",
    "payment": {
        "uuid": "...",
        "verification_status": "rejected"
    }
}
```

### 3.5 Upload payment receipt

```
POST /api/v1/payments/{uuid}/receipt
Authorization: Bearer {token}
Content-Type: multipart/form-data

{
    "receipt": <binary file (jpg/png, max 2MB)>
}

Response 200:
{
    "message": "Receipt uploaded",
    "receipt_url": "/storage/receipts/payments/uuid-123456.jpg"
}
```

---

## 4. Manuscripts

### 4.1 Current period manuscripts

```
GET /api/v1/manuscripts?period=2026-08&zone_uuid=...&status=active
Authorization: Bearer {token}

Response 200:
{
    "period": "2026-08",
    "summary": {
        "total_customers": 521,
        "total_bill": 1302500.00,
        "total_arrears": 450000.00,
        "total_credit": 75000.00,
        "total_collected": 950000.00,
        "collection_rate": 72.9
    },
    "data": [
        {
            "customer_uuid": "...",
            "customer_name": "JOHN DOE",
            "zone_name": "THR01 (3/CORNERS)",
            "bill": 2500.00,
            "total_arrears": 2500.00,
            "credit": 0,
            "total_bill": 5000.00,
            "payment_expiration": null,
            "status": "active"
        }
    ],
    "meta": { ... }
}
```

### 4.2 Customer manuscript history

```
GET /api/v1/customers/{uuid}/manuscripts
Authorization: Bearer {token}

Response 200:
{
    "data": [
        {
            "period": "2026-08",
            "bill": 2500.00,
            "total_arrears": 2500.00,
            "credit": 0,
            "total_bill": 5000.00
        },
        {
            "period": "2026-07",
            "bill": 2500.00,
            "total_arrears": 5000.00,
            "credit": 0,
            "total_bill": 7500.00
        }
    ]
}
```

---

## 5. Zones

### 5.1 List zones

```
GET /api/v1/zones
Authorization: Bearer {token}

Response 200:
{
    "data": [
        {
            "uuid": "...",
            "name": "THR01 (3/CORNERS)",
            "town": "KUMBA 3",
            "customer_count": 71,
            "agent": {
                "uuid": "...",
                "name": "Agent Name",
                "phone": "670000000"
            }
        }
    ]
}
```

---

## 6. Resources / Expenditures

### 6.1 List expenditures

```
GET /api/v1/resources/expenditures?page=1&per_page=25&category_uuid=...&from=2026-07-01&to=2026-07-31&user_uuid=...
Authorization: Bearer {token}

Response 200:
{
    "data": [
        {
            "uuid": "...",
            "category_uuid": "...",
            "category_name": "Field Operations",
            "amount": 1500.00,
            "description": "Fuel for zone rounds",
            "spent_at": "2026-07-15",
            "receipt_url": "/storage/receipts/expenditures/...",
            "recorded_by": {
                "uuid": "...",
                "name": "Agent Name"
            },
            "created_at": "2026-07-15T10:30:00Z"
        }
    ],
    "meta": { ... }
}
```

### 6.2 Create expenditure

```
POST /api/v1/resources/expenditures
Authorization: Bearer {token}

{
    "category_uuid": "...",
    "amount": 1500.00,
    "description": "Fuel for zone rounds",
    "spent_at": "2026-07-15",
    "notes": "Round trip to T R01 and T R02"
}

Response 201:
{
    "uuid": "...",
    "amount": 1500.00,
    ...
}
```

### 6.3 Update expenditure (admin/manager only)

```
PATCH /api/v1/resources/expenditures/{uuid}
Authorization: Bearer {token} (role: admin, super)

{
    "amount": 2000.00,
    "description": "Fuel for zone rounds - corrected amount"
}
```

### 6.4 Delete expenditure (admin/super only)

```
DELETE /api/v1/resources/expenditures/{uuid}
Authorization: Bearer {token} (role: admin, super)

Response 200:
{
    "message": "Expenditure deleted"
}
```

### 6.5 P&L Dashboard

```
GET /api/v1/resources/dashboard?period=2026-07
Authorization: Bearer {token} (role: admin, manager, super)

Response 200:
{
    "period": "2026-07",
    "income": {
        "total": 1250000.00,
        "verified": 1200000.00,
        "pending_verification": 50000.00,
        "rejected": 5000.00,
        "payment_count": 487
    },
    "expenses": {
        "total": 450000.00,
        "by_category": [
            {"name": "Staff & Labour", "amount": 180000.00, "count": 12},
            {"name": "Field Operations", "amount": 120000.00, "count": 35},
            {"name": "Network Maintenance", "amount": 80000.00, "count": 18},
            {"name": "Office & Admin", "amount": 40000.00, "count": 8},
            {"name": "Miscellaneous", "amount": 30000.00, "count": 10}
        ]
    },
    "pnl": {
        "net": 800000.00,
        "margin_pct": 64.0
    },
    "budgets": [
        {
            "category": "Field Operations",
            "budgeted": 150000.00,
            "actual": 120000.00,
            "variance": 30000.00,
            "variance_pct": 20.0
        }
    ]
}
```

### 6.6 Categories (admin only)

```
GET /api/v1/resources/categories          — List
POST /api/v1/resources/categories         — Create
PATCH /api/v1/resources/categories/{uuid} — Update
DELETE /api/v1/resources/categories/{uuid} — Deactivate (not hard delete)
```

---

## 7. Offline Sync

### 7.1 Push (mobile -> server)

```
POST /api/v1/sync/push
Authorization: Bearer {token}
Content-Type: application/json

{
    "device_id": "abc123-device-fingerprint",
    "last_sync_at": "2026-08-17T10:30:00Z",
    "changes": {
        "payments": [...],
        "expenditures": [...]
    }
}

Response 200:
{
    "status": "success",
    "synced_at": "2026-08-17T10:30:05Z",
    "results": {
        "payments": [
            {"local_uuid": "...", "server_uuid": "...", "status": "synced"}
        ],
        "expenditures": [
            {"local_uuid": "...", "server_uuid": "...", "status": "synced"}
        ]
    },
    "errors": []
}
```

### 7.2 Pull (server -> mobile)

```
GET /api/v1/sync/pull?since=2026-08-17T10:30:00Z
Authorization: Bearer {token}

Response 200:
{
    "synced_at": "2026-08-17T11:00:00Z",
    "changes": {
        "customers": {
            "upserted": [...],
            "deleted": [...]
        },
        "payments": {
            "verified": [...],
            "rejected": [...]
        }
    }
}
```

### 7.3 Upload receipt photo (after sync)

```
POST /api/v1/sync/upload-receipt
Authorization: Bearer {token}
Content-Type: multipart/form-data

{
    "entity_type": "payment|expenditure",
    "entity_uuid": "...",
    "receipt": <binary file>
}
```

### 7.4 Sync status check

```
GET /api/v1/sync/status
Authorization: Bearer {token}

Response 200:
{
    "device_id": "abc123",
    "last_sync_at": "2026-08-17T10:30:05Z",
    "pending_push": 3,
    "pending_pull": 7,
    "failed_items": 0
}
```

---

## 8. Audit Logs

### 8.1 Query audit logs (admin/manager/super)

```
GET /api/v1/audit/logs?table=payments&record_uuid=...&action=update&user_uuid=...&from=2026-07-01&to=2026-07-31&page=1
Authorization: Bearer {token} (role: admin, manager, super)

Response 200:
{
    "data": [
        {
            "uuid": "...",
            "table_name": "payments",
            "record_uuid": "...",
            "action": "update",
            "old_values": {"verification_status": "pending"},
            "new_values": {"verification_status": "verified", "verified_by": 2},
            "user": {
                "uuid": "...",
                "name": "Admin Kelvin"
            },
            "ip_address": "192.168.1.100",
            "device_id": null,
            "created_at": "2026-08-17T10:30:00Z"
        }
    ],
    "meta": { ... }
}
```

---

## 9. Bills / Print

### 9.1 Generate bill slip (PDF)

```
GET /api/v1/bills/{customer_uuid}/print?period=2026-08
Authorization: Bearer {token}

Response: PDF binary (Content-Type: application/pdf)
```

### 9.2 Generate manuscript report (ZIP)

```
GET /api/v1/manuscripts/export?period=2026-08&format=zip
Authorization: Bearer {token} (role: admin, super)

Response: ZIP binary (Content-Type: application/zip)
```

---

## 10. Error Responses

All errors follow a consistent format:

```json
{
    "message": "Human-readable error message",
    "errors": {
        "field_name": ["Specific validation error"]
    },
    "code": "ERROR_CODE"
}
```

**Common error codes:**

| HTTP Status | Code | Meaning |
|---|---|---|
| 401 | `UNAUTHENTICATED` | Missing or invalid token |
| 403 | `FORBIDDEN` | Role lacks permission |
| 404 | `NOT_FOUND` | UUID not found |
| 422 | `VALIDATION_ERROR` | Input validation failed |
| 409 | `CONFLICT` | Sync conflict detected |
| 429 | `RATE_LIMITED` | Too many requests |
| 500 | `SERVER_ERROR` | Unexpected server error |

---

## 11. Rate Limiting

| Endpoint type | Limit | Window |
|---|---|---|
| Auth (login) | 5 requests | per minute per IP |
| Sync push/pull | 60 requests | per minute per device |
| Standard CRUD | 120 requests | per minute per token |
| Export (PDF/ZIP) | 10 requests | per minute per token |
| Audit logs | 30 requests | per minute per token |

---

## 12. Pagination

All list endpoints use cursor-based pagination for large datasets:

```
GET /api/v1/payments?cursor=abc123&per_page=25&direction=next
```

Response includes:
```json
{
    "data": [...],
    "pagination": {
        "next_cursor": "def456",
        "prev_cursor": "xyz789",
        "per_page": 25,
        "has_more": true
    }
}
```

For smaller datasets (zones, categories), standard page-based pagination is used:
`?page=1&per_page=25`
