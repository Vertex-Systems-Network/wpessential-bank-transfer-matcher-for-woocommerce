# WPEssential Bank Transfer Matcher Pro for WooCommerce

Development preview of the separate add-on. Requires a compatible active Free edition.

## Requirements

WordPress 7.0+, PHP 8.3+, and WooCommerce 10.8+ are minimum requirements for the development build.

## Development installation

Activate WooCommerce 10.8 or later first. In WordPress, open Plugins → Add Plugin → Upload Plugin, choose the Free edition ZIP, and activate it. A complete production ZIP already contains its Composer autoloader; the server does not need Composer.

Enable WooCommerce Direct bank transfer (BACS) and configure the receiving account under WooCommerce → Settings → Payments. New eligible BACS orders receive an immutable payment reference. Open WooCommerce → Bank Transfer Matcher to review eligible orders, customer claims, merchant bank evidence, exact allocations, and the separate payment-completion action.

Settings controls receipt uploads and shop-manager access. Receipt uploads are disabled by default. Test the complete workflow with a low-value or synthetic order before using the development preview on store data. Activate the Free edition before Pro. Pro requires Free API `>=1.0.0 <2.0.0`.

## Verified preview scope

- Separate add-on activation and Free dependency checks.
- Import and validate bank statement rows.

## FAQ

### Is this ready for a live store?

This is a development preview. Install it on a disposable WordPress site.

### Does a customer claim mark an order paid?

No. A claim records what the customer declared. A permitted reviewer must check merchant bank evidence, apply an exact allocation, and use the separate acknowledged payment-completion action.

### Where are bank account details configured?

Continue to use WooCommerce's native Direct bank transfer settings. The Free edition does not create a second bank-account secret store.

### How are receipt files handled?

Receipt uploads are off by default. When enabled, PDF, JPEG, and PNG files up to 2 MB are stored privately with the claim and their bytes expire after 30 days. WordPress personal-data export excludes receipt bytes; erasure removes the sender, customer note, and receipt while retaining financial reconciliation records.

### Which staff roles can reconcile transfers?

Administrators and shop managers receive view, claim-review, and approval capabilities by default. An administrator can change the shop-manager grants under Bank Transfer Matcher → Settings.

## Support and privacy

Use the project repository's issue tracker for development feedback. Keep bank statements, customer information, receipt files, reviewer comments, and credentials out of public reports. Use synthetic data when reporting a problem.

Customer claims, merchant evidence, allocations, review history, and payment-completion records support financial reconciliation. WordPress privacy tools export customer claim fields and redact the claim sender, customer note, and receipt during erasure. Amounts, dates, references, statuses, and audit records remain; the merchant must set a suitable retention policy for its legal and accounting obligations. Backups may retain older copies according to the store's backup policy.

## Changelog

### 0.1.0

- Added an independent add-on entry point with Free dependency checks. Verified in `92f7a4d`.

## Upgrade note

Development preview. Use a disposable WordPress site for installation and upgrade testing.
