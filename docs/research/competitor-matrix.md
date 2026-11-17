# Competitor research

Public evidence checked 2026-11-17. Unless listed, precise pricing, WP/WC matrix, split and partial coverage, and actual runtime behavior remain unknown.

| Product | Verified public findings | Product implication |
| --- | --- | --- |
| [WooCommerce BACS](https://woocommerce.com/document/bacs/) | Native offline instructions, bank account details, on-hold orders and manual confirmation. Public setup screenshots. | Keep the core checkout and bank account configuration. |
| [Rusa Payment Verification](https://wordpress.org/plugins/rusa-payment-verification-for-woocommerce/) | Listed in WordPress.org with an optional Premium add-on. Documents BACS proof uploads (JPG/PNG/PDF), an admin queue, and Premium duplicate reference checks and CSV exports. | Distinguish customer claim review from bank reconciliation approval. |
| [PeproDev Receipt Uploader](https://github.com/peprodev/wc-upload-reciept) | Public source advertises image/PDF upload and merchant approval for BACS orders. | Give claim evidence a separate lifecycle. |
| [Kairos Labs Bank Transfer Reconciliation](https://plugins.wpmayor.com/plugin/kairos-labs-wise-bank-transfer-for-woocommerce) | Third-party index confirms the product name. Primary documentation, statement formats and operating model require verification. | Leave format and duplicate handling claims open. |
| [WooPayments reconciliation reports](https://developer.woocommerce.com/2026/08/06/woopayments-reports/) | Woo documents period reconciliation summaries and spreadsheet export. Woo's BACS docs confirm WooPayments does not interface with native BACS. | Benchmark reporting affordances while keeping offline statement accounting distinct. |


## Direct statement reconciliation

[WooCommerce Payments BACS Extended](https://codecanyon.net/item/woocommerce-payments-bacs-extended/64116770) is commercially listed. The publisher advertises CAMT.053 and CSV with reusable mapping, incoming-credit matching by order number and amount, duplicate protection, review, and per-order or bulk booking. The listing states WordPress 6.9+, WooCommerce 8.0+ tested through 10.9, and PHP 7.4+. Duplicate algorithm, split and partial semantics, private storage, and checkout claims were not independently tested. Benchmark mapping and review, and keep approval and balance checks explicit.
