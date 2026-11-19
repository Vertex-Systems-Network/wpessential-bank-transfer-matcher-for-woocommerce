# ADR-0009 — P5-T009 correction and reversal investigations (in progress)

A bank reversal, wrongly attributed credit, duplicate deposit, or refund request is a **financial exception**, not permission to edit WooCommerce paid status or decrement an approved ledger. Every case must be source- and allocation-linked, uniquely idempotent, auditable, and reviewed by an independent authorized finance actor. Any reported case blocks additional source/order reconciliation and payment completion until a separate, authorized resolution workflow exists; a reviewed case alone **never releases the hold**.

Case creation and review must serialize with all allocation writers under the existing named source and order locks, carry immutable exact-money/source/order snapshots, and append audit events in the same DB transaction. Neither creation nor review may execute refunds, bank transfers, change allocation states, remove evidence, or call WooCommerce `payment_complete()`. Already-paid orders remain paid; any real-world bank return/refund requires explicit external operations and their own separate auditable model. Free continues to observe Pro correction holds even if the Pro plugin is temporarily disabled.

Implementation and native CI pending. P5-T010 UI, P5-T011 concurrency and P8 release gating remain mandatory.
