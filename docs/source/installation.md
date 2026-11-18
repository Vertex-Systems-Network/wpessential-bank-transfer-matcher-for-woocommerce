Activate WooCommerce 10.8 or later first. In WordPress, open Plugins → Add Plugin → Upload Plugin, choose the Free edition ZIP, and activate it. A complete production ZIP already contains its Composer autoloader; the server does not need Composer.

Enable WooCommerce Direct bank transfer (BACS) and configure the receiving account under WooCommerce → Settings → Payments. New eligible BACS orders receive an immutable payment reference. Open WooCommerce → Bank Transfer Matcher to review eligible orders, customer claims, merchant bank evidence, exact allocations, and the separate payment-completion action.

Settings controls receipt uploads and shop-manager access. Receipt uploads are disabled by default. Test the complete workflow with a low-value or synthetic order before using the development preview on store data.
