# ADR-0006 — P5-T006 fail-closed mismatch classification

A bank deposit and a WooCommerce order can only form an exact-match review candidate when the source is unique, a merchant has independently confirmed bank clearance, the order is unpaid and uses BACS, bank currency/exponent and order amounts match exactly, the normalized payment reference agrees, and the receiving bank account is explicitly mapped to that order.

**Missing receiving account binding is not permission to assume a match.** If the order has no confirmed receiving account ID (represented by \`expected_bank_account_id=0\`), the \`receiving_bank_account_unconfirmed\` code is mandatory. A different confirmed account emits \`receiving_bank_account_mismatch\`. A credit above outstanding is an overpayment exception; a smaller credit is a partial-payment review, not a false exact match. A negative, float or malformed currency precision is rejected before matching.

The initial \`MismatchReview::inspect\` classifier is side-effect free; its \`exact_candidate_review\` status **never authorizes a payment**. Native persistence, source-backed reason snapshots, displayed review cases and account-selection UI are pending P5-T006 and later review/UI tasks. This policy does not override the existing stringent maker/checker and source uniqueness controls.


## Explicit order receiving-account binding

\`OrderReceivingAccountRepository\` now records an immutable, merchant-confirmed expected receiving account for each unpaid BACS order, with a mandatory finance reason and append-only audit event. Only a user with both Free approval and Pro bank-account-management permissions may confirm it. The account must be active and match the order currency. A binding cannot silently be changed or inferred from an imported row.

All Pro one-to-one and split allocation proposal/approval paths now require that the imported receiving bank account exactly matches the order's confirmed account. A missing binding fails closed, even if amount and reference otherwise match. This **prevents approval** rather than completing a payment. Historical records need explicit merchant confirmation before any new allocations. Mismatch cases remain a separate source-backed review workflow pending P5-T006 native verification.
