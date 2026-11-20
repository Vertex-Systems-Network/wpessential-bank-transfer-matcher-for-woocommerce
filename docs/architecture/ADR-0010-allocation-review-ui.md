# ADR-0010 — P5-T010 native allocation summaries and finance audit workspace

Status: verified in GitHub Actions runs 44639405880 and 44639399125 (Foundation, Playground and native WordPress/WooCommerce/MariaDB).

A finance allocation workspace must remain WordPress/WooCommerce-native, using normal admin menu entries, scoped capabilities, real ledger/event/provenance data and exact per-currency minor units. It must show pending versus independently approved or committed allocations, split source links, mixed Free/Pro balance holds, and correction/dispute audit without treating a review case as a completed bank refund.

The queue and audit information is **read-only**, while explicit POST action panels now support proposal, independent checker allocation approval, and correction/reversal investigation. WordPress admin POST actions require contextual nonces, both finance capabilities, exact IDs/idempotency keys, verified bank clearance and independent finance actor, and reuse audited internal services under database locks. No payment completion, bank refund or silent reversal action is exposed. These forms passed native finance permission, WordPress nonce, independent maker/checker, source-id and split-recipient authenticity tests. They do not expose WooCommerce payment completion or bank refunds.


## Read-only first implementation (native CI verified)

\`AllocationReviewPage\` is registered by the Pro bootstrap as a WooCommerce admin submenu. Both Pro import and Free finance-approval capabilities are required to render the page; source references are masked and all bank/order/audit fields are escaped. The GET filter allows order ID and allocation state with bounded pagination. Visible-page totals are computed using exact integers, segregated by currency, precision and state, without counting proposed money as paid. Invalid unsigned database numbers fail closed instead of being cast.

An allocation's workspace shows its original source/expected/allocated amounts, reviewer and split-group identity, current WooCommerce status, bank-source warning, shared correction and reversal holds, correction event timeline and append-only allocation history. The evidence and audit tables are read-only; the surrounding workspace has explicitly gated POST proposal, independent review, and correction hold forms. It exposes no payment, bank refund or automatic reversal control.

Native checks now assert queue filters, empty state, fully committed split allocation review, masked bank source, correction history, ledger audit and absence of mutation forms.


## Manual finance action forms (native CI verified)

A separate \`AllocationAdminActions\` adapter registers WordPress \`admin_post\` handlers for one-to-one proposals, independent one-to-one or atomic split-group approval, creation of correction holds and independent correction review. Every actual handler requires POST, context-specific WordPress nonce, both finance capabilities, exact IDs and stable request keys; services revalidate immutable imported sources, order balances, clearance and independent actor identity under database/named locks. A split recipient ID is validated *before* the group service is invoked, never after it commits. Review details are rendered in native expandable finance panels, with explicit clearance checkbox and reason.

None of these actions calls \`payment_complete()\`, triggers a refund, removes a case hold, changes an order status or imports files. The queue forms create proposals that remain unapproved until a second reviewer acts. Actual WooCommerce payment completion remains a separate internal, explicit P5-T008 service without an admin action route. Native tests cover idempotent admin adapter replay, forged split recipient, finance form markup and absence of payment/refund controls. Verified in the named full CI runs.


## Accessible review dialogs (native and package CI verified)

Finance action forms are ordinary native HTML \`<details>\` panels without JavaScript. On the finance admin page, the packaged \`assets/js/finance-dialogs.js\` progressively upgrades them to keyboard-accessible native \`<dialog>\` elements, with explicit close button, built-in Escape-to-close and focus restoration to the launcher. The script **moves the existing WordPress form** without cloning or rewriting its nonce, bank source or idempotency key. Browsers without \`showModal\` retain the functional native panels.

Only the finance submenu enqueues the dialog script, and the package manifest requires the asset to be present in the production Pro ZIP. CI validates JS syntax; native WP checks registration and scoped enqueue. The dialog layer adds no network requests, direct payment hooks or other financial side effects.
