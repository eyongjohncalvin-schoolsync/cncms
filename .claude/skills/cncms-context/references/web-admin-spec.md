# Web Admin Panel — Design Specification

Status: **v2 Design** | Stack: Inertia.js + React 18 + TypeScript + Tailwind CSS

---

## 1. Overview

The web admin panel is the primary management interface used by office staff
(super admins, admins, managers) and optionally by agents for read-only views.
It is a server-side rendered SPA built with Laravel Inertia.js and React 18 + TypeScript,
using Tailwind CSS for styling and Headless UI (@headlessui/react) components for interactive elements.

**Key principle:** The web panel is always online. It does not need offline support.
It renders pages server-side via Inertia adapters (no separate API calls for page loads)
and uses Axios for any client-side data mutations (form submissions, live search, etc.).

---

## 2. Layout & Navigation

### Sidebar navigation (collapsible)

```
CNCMS Logo
-----------
Dashboard
-----------
CUSTOMERS
  |-- All Customers
  |-- Add Customer
  |-- Bulk Import
ZONES
  |-- All Zones
PAYMENTS
  |-- All Payments
  |-- Record Payment
  |-- Pending Verification     [badge: count]
MANUSCRIPTS
  |-- Current Period
  |-- Print Bills
  |-- Export Manuscript
AGENTS
  |-- All Agents
  |-- Assign Zones
RESOURCES
  |-- P&L Dashboard
  |-- Record Expense
  |-- All Expenses
  |-- Categories              [admin only]
  |-- Export Report
-----------
AUDIT LOG                      [admin only]
SETTINGS                       [admin only]
  |-- Company Info
  |-- Users & Roles
  |-- Command Runs
-----------
[User avatar dropdown]
  |-- Profile
  |-- Logout
```

### Top bar

- Search (global customer search by name/phone)
- Notification bell (alerts count)
- Agent sync status indicator (green/red for each agent)
- User avatar + name + role badge

---

## 3. Pages — Detailed Specs

### 3.1 Dashboard

**Route:** `GET /` (Inertia page)

**Layout:** Grid of stat cards + charts + recent activity

**Stat cards (row 1):**
```
+------------------+  +------------------+  +------------------+  +------------------+
|  Total Customers |  |  Active This Mo  |  |  Pending Verify  |  |  Monthly Income   |
|     [549]        |  |     [345]        |  |     [12]         |  |   [1,250,000]    |
|  +3 this month   |  |  78% of total    |  |  Action needed   |  |  FCFA            |
+------------------+  +------------------+  +------------------+  +------------------+
+------------------+  +------------------+  +------------------+
|  Arrears > 3mo   |  |  Agents Synced  |  |  Total Expenses   |
|     [45]         |  |   5/6 online     |  |   [450,000]      |
|  At-risk         |  |  1 offline       |  |  FCFA            |
+------------------+  +------------------+  +------------------+
```

**Charts (row 2):**
- **Income vs Expenses** — bar chart, last 6 months
- **Collection rate by zone** — horizontal bar chart
- **Payment verification status** — donut chart (verified / pending / rejected)

**Recent activity feed (row 3):**
- Last 20 audit events (payment recorded, customer disconnected, expense added, etc.)
- Each item shows: timestamp, user name, action description, entity link

### 3.2 Customers — List

**Route:** `GET /customers`

**Filters bar:**
- Zone (dropdown, populated from zones table)
- Status (tabs: All / Active / Passive / Disconnected / Suspended)
- Level (tabs: All / Normal / Vip / Operator)
- Search (text input, searches name + phone)
- Bill range (min-max slider or inputs)

**Table columns:**
| Name | Phone | Zone | Bill (FCFA) | Level | Status | Arrears | Total Bill | Actions |

**Actions column:**
- View (eye icon -> detail page)
- Edit (pencil icon -> edit modal or page)
- Disconnect/Suspend (dropdown for status change)

**Bulk actions:**
- Export filtered list to Excel
- Print selected customers' bills

**Pagination:** Cursor-based, 25 per page

### 3.3 Customers — Add/Edit

**Route:** `GET/POST /customers/create`, `GET/PATCH /customers/{uuid}/edit`

**Form fields:**
- Name (text, required)
- Phone (tel, optional, normalised format)
- Zone (searchable dropdown)
- Location (text)
- Bill amount (number, FCFA)
- Level (radio: normal / Vip / Operator)
- Status (select: active / passive / disconnected / suspended)
- Notes (textarea)

**Validation:** Server-side, redirects back with errors on failure.

### 3.4 Customers — Bulk Import

**Route:** `GET/POST /customers/import`

**UI:**
- File upload dropzone (accepts .xlsx only)
- Template download link (generates blank template from current schema)
- Import preview: table showing parsed rows with validation errors highlighted
- "Import" button (disabled until all errors resolved)
- Import progress bar with row count

### 3.5 Payments — List

**Route:** `GET /payments`

**Filters bar:**
- Date range (date picker)
- Customer (searchable dropdown)
- Zone (cascading from customer)
- Verification status (tabs: All / Pending / Verified / Rejected)
- Frequency (tabs: All / Monthly / Multi-month / Yearly)
- Recorded by (user dropdown)
- Offline (toggle: show offline-recorded only)

**Table columns:**
| Date | Customer | Zone | Amount (FCFA) | Freq. | Verification | Recorded By | Actions |

**Verification column shows:**
- Green badge: "Verified"
- Yellow badge: "Pending" (clickable -> verify modal)
- Red badge: "Rejected" (clickable -> view reason)

**Actions column:**
- View detail
- Verify/Reject (admin only, opens modal)

### 3.6 Payments — Record

**Route:** `GET/POST /payments/create`

**Form fields:**
- Customer (searchable by name or phone)
- Amount (number, FCFA, pre-filled with customer's monthly bill)
- Frequency (radio: monthly / months / yearly)
- Months count (shown only when frequency = 'months')
- Credit (number, optional)
- Receipt photo (file upload, optional)
- Notes (textarea, optional)

**Auto-behaviour:**
- When customer is selected, show their current bill, arrears, and last payment date
- Amount validation: warn if amount is less than monthly bill
- For admin users: auto-set `verification_status = 'verified'`
- For agent users: set `verification_status = 'pending'`

### 3.7 Payments — Verify/Reject Modal

**Triggered by:** clicking "Pending" badge or "Verify" action button

**Modal content:**
- Payment details (customer, amount, date, recorded by, receipt photo)
- Action: Approve / Reject (two buttons)
- MOMO reference field (optional, for cross-check)
- Notes field (required for rejection, optional for approval)
- "Confirm" button

### 3.8 Manuscripts — Current Period

**Route:** `GET /manuscripts`

**Summary bar (top):**
```
Period: August 2026
Total Customers: 521 | Total Billed: 1,302,500 FCFA | Total Arrears: 450,000 FCFA
Total Collected: 950,000 FCFA | Collection Rate: 72.9%
```

**Table columns:**
| # | Name | Code | Phone | Zone | Level | Bill | Arrears | Credit | Expiry | Total Bill | Status |

**Features:**
- Column sorting (click header)
- Zone filter dropdown
- Status filter (active/disconnected)
- Export to Excel
- "Print Bills" button (generates PDF per customer or bulk ZIP)
- "Run Manuscript Calculation" button (admin only, with confirmation modal)

### 3.9 Agents — List & Management

**Route:** `GET /agents`

**Table columns:**
| Name | Phone | Zone | Salary (FCFA) | Status | Last Sync | Sync Health | Actions |

**Sync Health column shows:**
- Green dot + "Online" (synced within 15 minutes)
- Yellow dot + "Stale" (synced > 1 hour ago)
- Red dot + "Offline" (synced > 24 hours ago)
- Grey dot + "Never" (no sync recorded)

**Actions:**
- View detail
- Edit (name, zone, salary, status)
- View sync log (list of recent sync events)

### 3.10 Resources — P&L Dashboard

**Route:** `GET /resources/dashboard`

See the Resources module spec for detailed layout. The web version includes:
- Full-width stat cards (income, expenses, net, margin)
- Income vs Expenses bar chart (last 6 months, Chart.js or ApexCharts)
- Expense breakdown donut chart (by category)
- Budget vs Actual table (if budgets configured)
- Export button (PDF or Excel for the period)

### 3.11 Resources — Record Expense

**Route:** `GET/POST /resources/expenditures/create`

**Form fields:**
- Date (date picker, defaults to today)
- Category (dropdown with icons)
- Amount (number, FCFA)
- Description (text)
- Receipt photo (file upload, optional)
- Notes (textarea, optional)

**Quick-entry mode:** Toggle to show a minimal floating form (always visible on the page)
for rapid expense recording without page navigation.

### 3.12 Audit Logs

**Route:** `GET /audit/logs` (admin/manager/super only)

**Filters bar:**
- Table name (dropdown: customers, payments, manuscripts, expenditures, etc.)
- Action type (tabs: All / Create / Update / Delete)
- User (dropdown)
- Date range (date picker)
- Record UUID (search input)

**Table columns:**
| Timestamp | User | Table | Action | Summary | Details |

**Summary column (auto-generated):**
- "Created payment: 2,500 FCFA for JOHN DOE"
- "Changed customer status: active -> disconnected"
- "Verified payment: approved by Admin Kelvin"
- "Deleted expenditure: 1,500 FCFA (Staff & Labour)"

**Details column:**
- Expandable accordion showing full JSONB old_values / new_values
- Syntax-highlighted JSON (use `react-json-view-lite` or similar)

### 3.13 Settings — Company Info

**Route:** `GET/PATCH /settings/company`

**Form fields:**
- Company name
- Location
- Email
- Phone numbers
- Technical support number
- MOMO numbers + names
- Logo upload

### 3.14 Settings — Users & Roles

**Route:** `GET /settings/users`

**Table:** user list with name, username, email, role, status, last login

**Actions:** Create user, Edit role, Deactivate, Reset password

---

## 4. Component Library

### Shared React + TypeScript components (used by both web and mobile)

```
src/
  components/
    ui/
      AppButton.tsx
      AppBadge.tsx
      AppCard.tsx
      AppModal.tsx
      AppTable.tsx
      AppDatePicker.tsx
      AppDropdown.tsx
      AppSearchInput.tsx
      AppFileUpload.tsx
      AppStatCard.tsx
      AppChart.tsx           -- wrapper for Recharts
      Pagination.tsx
      LoadingSpinner.tsx
      EmptyState.tsx
    customers/
      CustomerSearch.tsx
      CustomerDetail.tsx
      CustomerForm.tsx
      CustomerFilters.tsx
    payments/
      PaymentTable.tsx
      PaymentVerifyModal.tsx
      PaymentReceiptUpload.tsx
      PaymentFilters.tsx
    manuscripts/
      ManuscriptTable.tsx
      ManuscriptSummary.tsx
    agents/
      AgentTable.tsx
      SyncHealthBadge.tsx
    resources/
      ExpenditureForm.tsx
      PnLCards.tsx
      BudgetVarianceTable.tsx
      CategoryDropdown.tsx
    audit/
      AuditLogTable.tsx
      AuditSummaryText.tsx
      JsonViewer.tsx
    shared/
      ZoneBadge.tsx
      CurrencyInput.tsx     -- FCFA formatting
      StatusBadge.tsx
      RoleBadge.tsx
      VerificationBadge.tsx
  hooks/
    useDebounce.ts
    usePolling.ts
    useSyncStatus.ts
  stores/
    syncStore.ts           -- Zustand store for sync state
    notificationStore.ts   -- Zustand store for toast notifications
  types/
    customer.ts
    payment.ts
    expenditure.ts
    manuscript.ts
    agent.ts
    audit.ts
    api.ts                 -- shared API response types
  lib/
    api.ts                 -- Axios instance + interceptors
    formatCurrency.ts      -- FCFA number formatting
    normalizePhone.ts
```

### Styling approach

- **Tailwind CSS** utility classes for layout and spacing
- **Headless UI** (@headlessui/react) for interactive components (modals, dropdowns, tabs, dialogs)
- **Tabler Icons** for consistent iconography
- **Inter** font family (clean, professional, good readability)
- Color palette: slate-based neutrals + blue accent + semantic colors (green/success, yellow/warning, red/danger)

### Responsive breakpoints

- `sm` (640px) — stacked cards on mobile
- `md` (768px) — tablet layout, sidebar collapsed
- `lg` (1024px) — full desktop layout, sidebar expanded
- `xl` (1280px) — wide dashboard, multi-column charts

---

## 5. Inertia.js Page Structure

```php
// Example: Customers list page
// app/Http/Controllers/CustomerController.php

public function index(Request $request)
{
    $customers = Customer::query()
        ->with('zone')
        ->when($request->zone_uuid, fn($q, $uuid) =>
            $q->where('zone_id', Zone::where('uuid', $uuid)->firstOrFail()->id))
        ->when($request->status, fn($q, $status) => $q->where('status', $status))
        ->when($request->search, fn($q, $search) =>
            $q->where('name', 'ILIKE', "%{$search}%")
                ->orWhere('phone', 'LIKE', "%{$search}%"))
        ->orderBy('name')
        ->paginate(25);

    return Inertia::render('Customers/Index', [
        'customers' => $customers,
        'zones'     => Zone::orderBy('name')->get(),
        'filters'   => $request->only('zone_uuid', 'status', 'level', 'search', 'page'),
    ]);
}
```

```tsx
// resources/tsx/Pages/Customers/Index.tsx
import AppLayout from '@/layouts/AppLayout';
import { Link, usePage } from '@inertiajs/react';
import { CustomerFilters } from '@/components/customers/CustomerFilters';
import { AppTable } from '@/components/ui/AppTable';
import { ZoneBadge } from '@/components/shared/ZoneBadge';
import { CurrencyInput } from '@/components/shared/CurrencyInput';
import { StatusBadge } from '@/components/shared/StatusBadge';
import { Pagination } from '@/components/ui/Pagination';

interface Props {
  customers: PaginatedResponse<Customer>;
  zones: Zone[];
  filters: Record<string, string>;
}

export default function CustomerIndex({ customers, zones, filters }: Props) {
  return (
    <AppLayout title="Customers">
      <AppLayout.Header>
        <h1 className="text-2xl font-semibold">Customers</h1>
        <Link href="/customers/create" className="btn btn-primary">
          Add Customer
        </Link>
      </AppLayout.Header>

      <CustomerFilters zones={zones} filters={filters} />

      <AppTable data={customers.data}>
        <AppTable.Head>
          <th>Name</th>
          <th>Phone</th>
          <th>Zone</th>
          <th>Bill</th>
          <th>Status</th>
          <th>Actions</th>
        </AppTable.Head>
        <AppTable.Body>
          {customers.data.map((customer) => (
            <tr key={customer.uuid}>
              <td>{customer.name}</td>
              <td>{customer.phone || '—'}</td>
              <td><ZoneBadge zone={customer.zone} /></td>
              <td><CurrencyInput value={customer.bill} /></td>
              <td><StatusBadge status={customer.status} /></td>
              <td>
                <Link href={`/customers/${customer.uuid}`}>View</Link>
                <Link href={`/customers/${customer.uuid}/edit`}>Edit</Link>
              </td>
            </tr>
          ))}
        </AppTable.Body>
      </AppTable>

      <Pagination links={customers.links} />
    </AppLayout>
  );
}
```

---

## 6. Real-Time Features (Phase 2)

### Polling-based updates (no WebSocket for v2.1)

- **Dashboard:** Auto-refresh stat cards every 30 seconds via Axios polling
- **Payment verification queue:** Poll every 15 seconds for new pending payments
  (show toast notification when one arrives)
- **Agent sync status:** Poll every 60 seconds to update online/offline indicators

### Future: Laravel Echo + Pusher

For v2.2, consider adding real-time via Laravel Echo:
- Live payment notifications when agents sync
- Live audit log feed (new events appear instantly)
- Agent location tracking (if GPS enabled on mobile)

---

## 7. Print & Export

### Bill printing

- Generate PDF per customer via Dompdf
- Company header (logo, name, contact) from `companies` table
- Match the existing bill slip format from v1
- Batch print: generate ZIP of all customer bills for the period

### Manuscript export

- Printable table format matching v1 manuscript layout
- Export as PDF (Dompdf) or Excel (Laravel Excel)
- 8 pages covering all active manuscript entries

### Resource report export

- PDF with company header, P&L summary, category breakdown
- Excel with detailed expenditure rows
- Period selector (month picker)

---

## 8. Performance Targets

| Metric | Target |
|---|---|
| Initial page load (SSR) | < 500ms TTFB |
| Customer list (25 rows) | < 300ms |
| Manuscript table (521 rows) | < 800ms (paginated) |
| Dashboard (all cards + charts) | < 1.2s |
| Search (customer name/phone) | < 200ms (debounced) |
| PDF generation (single bill) | < 500ms |
| Manuscript ZIP export | < 10s |

---

## 9. File Structure

```
resources/
  tsx/
    pages/              -- Inertia page components (.tsx)
      Dashboard.tsx
      Customers/
        Index.tsx
        Create.tsx
        Edit.tsx
        Show.tsx
        Import.tsx
      Payments/
        Index.tsx
        Create.tsx
        Show.tsx
      Manuscripts/
        Index.tsx
      Agents/
        Index.tsx
        Edit.tsx
      Resources/
        Dashboard.tsx
        Expenditures/
          Index.tsx
          Create.tsx
        Categories.tsx
      Audit/
        Index.tsx
      Settings/
        Company.tsx
        Users.tsx
    layouts/             -- Layout components
      AppLayout.tsx
      AuthLayout.tsx
    components/          -- Shared components (see Component Library above)
      ui/
      customers/
      payments/
      manuscripts/
      agents/
      resources/
      audit/
      shared/
    hooks/               -- Custom React hooks
      useDebounce.ts
      usePolling.ts
      useSyncStatus.ts
    stores/               -- Zustand stores
      syncStore.ts
      notificationStore.ts
    types/                -- TypeScript interfaces
      customer.ts
      payment.ts
      expenditure.ts
      manuscript.ts
      agent.ts
      audit.ts
      api.ts
    lib/                  -- Utility functions
      api.ts              -- Axios instance + interceptors
      formatCurrency.ts
      normalizePhone.ts
    app.tsx               -- Inertia React app entry point
    ssr.tsx               -- SSR entry point
  tsconfig.json
  vite.config.ts
  tailwind.config.ts
  postcss.config.js
```
