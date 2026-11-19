# ADR-0010 — P5-T010 native allocation summaries and finance audit workspace

Status: in progress.

A finance allocation workspace must remain WordPress/WooCommerce-native, using normal admin menu entries, scoped capabilities, real ledger/event/provenance data and exact per-currency minor units. It must show pending versus independently approved or committed allocations, split source links, mixed Free/Pro balance holds, and correction/dispute audit without treating a review case as a completed bank refund.

The first UI is explicitly **read-only**: no import action, payment completion, refunds, reversal, approval, or REST mutation route is exposed. Read-only filters, linked order snapshots and append-only audit history can be shipped once permission, escaping, malformed money, HPOS and native tests pass. Later P5-T010 maker/checker action modals require dedicated nonce/capability/CSRF/idempotency, dual-actor state checks and further integration tests. A UI button must never bypass the existing internal service contracts.


## Read-only first implementation (native CI pending)

\`AllocationReviewPage\` is registered by the Pro bootstrap as a WooCommerce admin submenu. Both Pro import and Free finance-approval capabilities are required to render the page; source references are masked and all bank/order/audit fields are escaped. The GET filter allows order ID and allocation state with bounded pagination. Visible-page totals are computed using exact integers, segregated by currency, precision and state, without counting proposed money as paid. Invalid unsigned database numbers fail closed instead of being cast.

An allocation's workspace shows its original source/expected/allocated amounts, reviewer and split-group identity, current WooCommerce status, bank-source warning, shared correction and reversal holds, correction event timeline and append-only allocation history. It deliberately exposes no POST form, REST mutation, payment, refund, approval or reversal control.

Native checks now assert queue filters, empty state, fully committed split allocation review, masked bank source, correction history, ledger audit and absence of mutation forms.


## Manual finance action forms (native CI pending)

A separate \`AllocationAdminActions\` adapter registers WordPress \`admin_post\` handlers for one-to-one proposals, independent one-to-one or atomic split-group approval, creation of correction holds and independent correction review. Every actual handler requires POST, context-specific WordPress nonce, both finance capabilities, exact IDs and stable request keys; services revalidate immutable imported sources, order balances, clearance and independent actor identity under database/named locks. A split recipient ID is validated *before* the group service is invoked, never after it commits. Review details are rendered in native expandable finance panels, with explicit clearance checkbox and reason.

None of these actions calls \`payment_complete()\`, triggers a refund, removes a case hold, changes an order status or imports files. The queue forms create proposals that remain unapproved until a second reviewer acts. Actual WooCommerce payment completion remains a separate internal, explicit P5-T008 service without an admin action route. Native tests cover idempotent admin adapter replay, forged split recipient, finance form markup and absence of payment/refund controls. Tests pending.
