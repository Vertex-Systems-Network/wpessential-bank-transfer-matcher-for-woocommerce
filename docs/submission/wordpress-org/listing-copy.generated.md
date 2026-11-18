# WPEssential Bank Transfer Matcher for WooCommerce

Development draft. Submission and publication are pending release verification.

Development preview for customer bank-transfer claims and deliberate manual reconciliation in WooCommerce.

- WooCommerce admin entry point with role-based access.
- Issue immutable payment references for WooCommerce orders.
- Accept secure customer payment claims without treating them as confirmed payment.
- Record merchant bank evidence, exact allocations, and a separate acknowledged payment completion.
- Review customer claims with private comments and customer-safe status notifications.
- Use native WooCommerce administration for balances, claims, evidence, allocations, settings, and privacy controls.

Activate WooCommerce 10.8 or later first. In WordPress, open Plugins → Add Plugin → Upload Plugin, choose the Free edition ZIP, and activate it. A complete production ZIP already contains its Composer autoloader; the server does not need Composer.

Enable WooCommerce Direct bank transfer (BACS) and configure the receiving account under WooCommerce → Settings → Payments. New eligible BACS orders receive an immutable payment reference. Open WooCommerce → Bank Transfer Matcher to review eligible orders, customer claims, merchant bank evidence, exact allocations, and the separate payment-completion action.

Settings controls receipt uploads and shop-manager access. Receipt uploads are disabled by default. Test the complete workflow with a low-value or synthetic order before using the development preview on store data.
