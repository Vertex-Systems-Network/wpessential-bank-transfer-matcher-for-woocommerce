# Official source checks

Reviewed on 2026-11-17. The runtime compatibility matrix remains open.

| Area | Source | Engineering consequence |
| --- | --- | --- |
| WordPress APIs and directory requirements | [Plugin Handbook](https://developer.wordpress.org/plugins/) · [Directory guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/) | Use native lifecycle hooks and ship a self-contained Free edition. |
| Coding and translation | [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/) · [Plugin Handbook](https://developer.wordpress.org/plugins/) | Escape output and use edition-specific text domains. |
| Runtime | [WordPress requirements](https://wordpress.org/about/requirements/) · [WooCommerce server recommendations](https://woocommerce.com/document/server-requirements/) | Test the implemented minimum before claiming compatibility. |
| BACS | [WooCommerce BACS](https://woocommerce.com/document/bacs/) | Preserve the native offline flow and on-hold status until verification. |
| Order storage | [HPOS recipe book](https://developer.woocommerce.com/docs/features/orders/high-performance-order-storage/recipe-book/) | Use WooCommerce CRUD across both storage modes. |
| Checkout | [Checkout Blocks integration](https://developer.woocommerce.com/docs/block-development/extensible-blocks/cart-and-checkout-blocks/checkout-payment-methods/payment-method-integration) | Exercise classic and Blocks independently. |
| Jobs | [Action Scheduler API](https://actionscheduler.org/api/) | Use named groups for bounded background jobs. |
| Composer | [Composer basic usage](https://getcomposer.org/doc/01-basic-usage.md) | Use each lockfile to produce its own optimized production autoloader. |
| WordPress.org validation | [Plugin Check](https://developer.wordpress.org/plugins/developer-tools/helper-plugins/) · [Readme validation](https://developer.wordpress.org/plugins/wordpress-org/how-your-readme-txt-works/) | Run both checks on the final Free package. |
| Woo Marketplace | [Woo review and QIT](https://developer.woocommerce.com/2025/03/26/getting-to-know-the-woo-marketplace-tools-we-use-and-what-we-look-for/) | Record QIT evidence from actual runs. |
| Playground | [Blueprints](https://developer.wordpress.org/playground/blueprints/) · [Blueprint bundles](https://wordpress.github.io/wordpress-playground/blueprints/using-blueprints/) | Keep base, Free and Pro scenarios reproducible. |
