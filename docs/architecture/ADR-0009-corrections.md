# ADR-0009 — P5-T009 correction and reversal investigations (in progress)

A bank reversal, wrongly attributed credit, duplicate deposit, or refund request is a **financial exception**, not permission to edit WooCommerce paid status or decrement an approved ledger. Every case must be source- and allocation-linked, uniquely idempotent, auditable, and reviewed by an independent authorized finance actor. Any reported case blocks additional source/order reconciliation and payment completion until a separate, authorized resolution workflow exists; a reviewed case alone **never releases the hold**.

Case creation and review must serialize with all allocation writers under the existing named source and order locks, carry immutable exact-money/source/order snapshots, and append audit events in the same DB transaction. Neither creation nor review may execute refunds, bank transfers, change allocation states, remove evidence, or call WooCommerce `payment_complete()`. Already-paid orders remain paid; any real-world bank return/refund requires explicit external operations and their own separate auditable model. Free continues to observe Pro correction holds even if the Pro plugin is temporarily disabled.

Implementation and native CI pending. P5-T010 UI, P5-T011 concurrency and P8 release gating remain mandatory.


## Initial case implementation (native CI pending)

\`CorrectionCaseRepository\` creates a Pro-owned unique allocation-linked case and append-only events. It stores exact immutable allocation/source/order amounts, original state, unique request key, source fingerprint, case type, finance maker identity, and a SHA-256 snapshot including imported canonical row, account/batch status, warning/error, current order total and paid status. Creating or reviewing a case requires an authorized finance actor; a separate reviewer may annotate only \`continue_hold\`, \`request_bank_restatement\`, \`request_external_refund_review\` or \`reject_unsupported_claim\`. These are **investigation annotations**, not permission for payment or refunds.

Case operations serialize the shared imported source and **all** split recipient orders with existing Free/Pro named locks and a SQL transaction. Every allocation writer and Free/Pro finalizer now checks a shared Free-owned \`CorrectionHoldGuard\`, including when Pro is deactivated. The guard propagates a source hold across all linked Pro recipient orders and never releases it merely because a second actor reviewed the case.

Native fixtures now challenge the correction lifecycle on both an unpaid approved allocation and a paid committed split source: duplicate/idempotent cases, maker/checker, source/order drift, unchanged order payments and ledger rows, and a source-wide hold across split recipient orders. Case creation does not reverse a bank transfer or refund WooCommerce payments. Real financial correction resolution, including any reversal of committed allocation states, remains a future explicitly authorized audited operation.
