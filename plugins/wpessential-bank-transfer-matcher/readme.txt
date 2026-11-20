=== WPEssential Bank Transfer Matcher for WooCommerce ===
Requires at least: 7.0
Tested up to: 7.0
Requires PHP: 8.3
WC requires at least: 10.8
WC tested up to: 11.3.0
Stable tag: 0.1.0
License: GPL-2.0-or-later

Development preview for customer bank-transfer claims and deliberate manual reconciliation in WooCommerce.

== Description ==

- WooCommerce admin entry point with role-based access.
- Issue immutable payment references for WooCommerce orders.
- Accept secure customer payment claims without treating them as confirmed payment.
- Record merchant bank evidence, exact allocations, and a separate acknowledged payment completion.
- Review customer claims with private comments and customer-safe status notifications.
- Use native WooCommerce administration for balances, claims, evidence, allocations, settings, and privacy controls.

== Installation ==

Activate WooCommerce 10.8 or later first. In WordPress, open Plugins → Add Plugin → Upload Plugin, choose the Free edition ZIP, and activate it. A complete production ZIP already contains its Composer autoloader; the server does not need Composer.

Enable WooCommerce Direct bank transfer (BACS) and configure the receiving account under WooCommerce → Settings → Payments. New eligible BACS orders receive an immutable payment reference. Open WooCommerce → Bank Transfer Matcher to review eligible orders, customer claims, merchant bank evidence, exact allocations, and the separate payment-completion action.

Settings controls receipt uploads and shop-manager access. Receipt uploads are disabled by default. Test the complete workflow with a low-value or synthetic order before using the development preview on store data.

== Frequently Asked Questions ==

= Is this ready for a live store? =

This is a development preview. Install it on a disposable WordPress site.

= Does a customer claim mark an order paid? =

No. A claim records what the customer declared. A permitted reviewer must check merchant bank evidence, apply an exact allocation, and use the separate acknowledged payment-completion action.

= Where are bank account details configured? =

Continue to use WooCommerce's native Direct bank transfer settings. The Free edition does not create a second bank-account secret store.

= How are receipt files handled? =

Receipt uploads are off by default. When enabled, PDF, JPEG, and PNG files up to 2 MB are stored privately with the claim and their bytes expire after 30 days. WordPress personal-data export excludes receipt bytes; erasure removes the sender, customer note, and receipt while retaining financial reconciliation records.

= Which staff roles can reconcile transfers? =

Administrators and shop managers receive view, claim-review, and approval capabilities by default. An administrator can change the shop-manager grants under Bank Transfer Matcher → Settings.

== Changelog ==

= 0.1.0 =

- Added the WooCommerce admin entry point and role-based access. Verified in `317b26b`.
- Verified standalone installation and reinstall. Verified in `875938a`.
- Added immutable BACS payment references and customer payment claims with optional private receipts.
- Added claim review, merchant bank evidence, exact allocations, private-safe activity, and explicit payment completion.
- Added native Free overview, order reconciliation actions, settings, role grants, and WordPress privacy integration.
- Verified the checkout-to-claim-to-payment workflow in WooCommerce classic and HPOS storage.

== Upgrade Notice ==

= 0.1.0 =

Development preview. Use a disposable WordPress site for installation and upgrade testing.
