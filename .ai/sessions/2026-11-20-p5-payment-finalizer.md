# Handoff — P5-T008 Pro explicit WooCommerce finalization

- Branch development/autonomous-release-track, previous head 102af01e40ae168079672cdd24b1860f08e808d0; PR #9 remains draft/unmerged.
- Pure P5-T008 PaymentReadiness passed GitHub Actions 44627524479 and 44627511410, all foundation/Playground/native checks.
- New internal ProPaymentFinalizer committed; no REST/web/import hook. It requires explicit authorized finance actor + bank-clearance flag, independent approval events, complete exactly verified Pro ledger, active cleared unique imported sources, merchant-confirmed receiving bank, clean exceptions and stable request key. It deliberately blocks mixed Free/Pro manual allocations until equivalent manual provenance review is possible.
- Durable pending intent is committed before invoking WC payment_complete outside transaction. Success commits allocations/events and intent; explicit retry repairs paid+pending audit without running hooks twice. Wrong request/actor and externally paid/no-intent are blocked.
- Added native `pro-payment-finalizer.php` fixture: 2 fully funded bank sources/orders, 1 partial unpayable order, separate maker/checker, source drift, independent lock, hook failure after paid status and crash recovery, 3 events per committed credit.
- **New code unverified**: check CI immediately and fix native or PHP syntax failures before P5-T008 completion. Extend multiple credits/one order and split group payment transition, then failure/race coverage; do not merge or release.
