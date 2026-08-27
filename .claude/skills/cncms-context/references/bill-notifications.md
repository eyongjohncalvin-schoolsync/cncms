# Bill Notifications (SMS / Email / WhatsApp) — Design Spec

Status: **Design, not yet implemented — build on explicit go-ahead only** | Owner ask: send each
customer's current bill via SMS, Email, and WhatsApp (bulk via Twilio, gated per-tenant by the
landlord since it costs the tenant money; plus a free manual mode where staff send individually
from their own phone).

**Confidence note, read before implementing**: §1-§3 below are grounded in two dedicated
expert-review passes that fully completed with real research. §4-§5 are NOT — the compliance and
product/UX review agents both failed repeatedly (transient platform overload) and never delivered.
What appears there is my own baseline judgment as a fallback, not independently verified legal or
UX research. Treat §4 especially as "needs real verification before launch," not settled fact —
WhatsApp policy and Cameroon telecom regulation are exactly the kind of area where a wrong guess
is expensive (a banned sending number, a compliance complaint).

---

## 1. The two-mode WhatsApp requirement

- **Bulk mode**: programmatic sending via Twilio's WhatsApp Business API. Costs real money per
  message, borne by the TENANT (not the platform) — gated behind a landlord-controlled per-tenant
  entitlement toggle, since ShalomTech wants to control which client operators get this
  capability. Once enabled, the tenant's own admin supplies and pays for their own Twilio account.
- **Manual/normal mode**: free, always available, no Twilio. Generates a `wa.me` deep link per
  customer (pre-filled bill message) that a staff member clicks to open WhatsApp and sends
  manually from their own phone/session.
- SMS and Email are additional channels, usable in both contexts.

## 2. WhatsApp Business API realities (verified research)

WhatsApp requires **pre-approved message templates** for any business-initiated message sent
outside a 24-hour customer-service session window. A proactive bill reminder is exactly this kind
of message — it cannot be sent as free-form text via the bulk API path. Concretely:

- The template (placeholders for customer name, amount, due date, MOMO payment number) must be
  submitted to Meta for approval before it can be used in bulk sends. Turnaround and rejection
  risk are real operational factors — build this into any rollout timeline, don't assume instant
  availability the moment Twilio credentials are configured.
- The **manual mode is unaffected** by this rule — a human clicking a `wa.me` link and typing/
  sending from their own WhatsApp session is a normal user-initiated conversation, not a
  business-API template send. This is a genuine, practical reason manual mode is valuable beyond
  just "the free option" — it has zero template-approval friction.
- Twilio setup a tenant needs: Account SID, Auth Token, a WhatsApp-enabled Twilio sender number,
  and Meta's WhatsApp Business Account approval. Real cost and real setup friction — don't
  undersell this to tenants as a one-click toggle.

**SMS**: Twilio SMS is the natural default (the dormant `messages.sid` column already anticipates
a Twilio-shaped provider). Cameroon-specific SMS regulatory specifics (sender ID registration,
etc.) were flagged as worth confirming directly with Twilio/a local provider before launch, not
assumed.

## 3. Data model & tenancy architecture (verified research)

- **Landlord-controlled entitlement flag**: lives in `tenants.data` JSON (Stancl's existing
  `VirtualColumn` mechanism, same pattern as `is_active`/`registration_status` on
  `App\Models\Tenant`) — a single boolean feature flag doesn't justify a dedicated
  `tenant_entitlements` table yet; that's speculative infrastructure for a system that doesn't
  exist. Revisit only if/when multiple paid-tier feature flags accumulate.
- **Tenant-supplied Twilio credentials**: do NOT put these on `companies` (branding/contact
  fields have a different sensitivity and lifecycle than API secrets). A new, tenant-scoped
  `notification_settings` table, with Twilio SID/token stored via Laravel's `encrypted` Eloquent
  cast — never plaintext. This table is also the natural home for per-channel on/off toggles
  (SMS/Email/WhatsApp) and future provider config (sender email, SMS gateway key) as those get
  added, without repeated schema churn on `companies`.
- **UI split**: the LANDLORD's tenant-management area shows/controls only the entitlement toggle
  (allowed / not allowed). The TENANT's own Settings area shows their Twilio credential fields —
  hidden or shown-disabled-with-an-explanation ("ask ShalomTech to enable this") when the landlord
  entitlement is off.
- **Cost/usage visibility**: explicitly OUT of scope for v1. Twilio bills the tenant directly;
  CNCMS doesn't need to duplicate that billing/usage view. Revisit only if tenants ask for it.
- **Resolving the entitlement per-request**: read through the existing `TenantContext`/tenant
  resolution pattern already used everywhere else in this codebase — don't build a second,
  parallel resolution path just for this flag.

## 4. Compliance — UNVERIFIED, needs real review before launch

*(This section is my own fallback judgment, not the output of the dedicated compliance-review
agent, which never completed. Do not treat this as legal sign-off.)*

- WhatsApp Business Policy requires some form of customer consent/opt-in before proactive
  messaging. An existing paying-subscriber relationship is a reasonable basis for a
  transactional/account-servicing message (a bill reminder about a bill the customer already
  owes, not marketing), but this should be confirmed against Meta's current policy text directly
  before launch, not assumed from general knowledge.
- Every channel needs a simple opt-out mechanism — at minimum a per-customer flag honored before
  every send.
- Cameroon-specific SMS/telecom regulatory requirements (sender ID registration, any ART —
  Agence de Régulation des Télécommunications — rules) were flagged by the original task as
  needing real research; that research never happened. Do not launch bulk SMS without someone
  actually checking this, ideally directly with a Cameroon-based SMS gateway provider who already
  navigates it.

## 5. Product/UX — baseline judgment, not independently reviewed

*(Same caveat as §4 — the dedicated UX-review agent never completed.)*

- **Placement**: bill notifications are naturally a Manuscripts-area action (a Manuscript literally
  *is* "this period's bill") more than a Customers-area one — put the send action(s) there, both a
  per-customer send and a bulk send, reusing the bulk-select checkbox pattern already established
  this session (`Payments/Index.tsx`'s bulk-verify, `Disconnections/Index.tsx`'s bulk actions).
- **The no-phone-number problem is the single biggest UX risk**: 78% of current customers have no
  phone on file. Any bulk-send UI must show this plainly ("412 of 549 customers have no contact
  info and will be skipped") rather than silently omitting them or failing confusingly.
- **Manual WhatsApp mode UX**: since a human must click-and-send each `wa.me` link individually,
  the highest-value UX addition is a queue/checklist flow — after sending one, auto-advance to the
  next customer needing a reminder, rather than making staff manually re-navigate a list 50 times.
- **Message content**: reuse real data already available (customer name, amount owed, MOMO
  numbers from `companies`, due date) — draft actual template copy at implementation time once the
  §2 Meta-approved-template requirement is being satisfied for real, not as placeholder text.

## 6. Recommended sequencing when this actually gets built

1. Data model first (§3): `notification_settings` table, the landlord entitlement flag, the
   `messages` table extension for channel/template/delivery-status tracking.
2. Manual WhatsApp mode (no Twilio, no template approval needed) — the fastest path to real value,
   ship this before the bulk/Twilio path.
3. Email (simplest channel, Laravel's native `Mail`, no per-message cost or approval process).
4. SMS via Twilio (after confirming Cameroon regulatory specifics per §4).
5. Bulk WhatsApp via Twilio — last, since it has the longest real-world lead time (Meta template
   approval, tenant's own Twilio/WhatsApp Business Account setup) and the most unresolved
   compliance questions (§4).
