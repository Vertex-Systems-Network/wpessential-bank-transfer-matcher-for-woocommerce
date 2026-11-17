# Customer payment claims

Free displays a claim form for eligible BACS orders with a stored payment reference. My Account uses the authenticated order owner. A guest needs the WooCommerce order key and billing email. Both submission paths require a WordPress nonce scoped to the order.

MariaDB enforces one claim per order. The first accepted claim is preserved when the customer resubmits. Each order can make eight verified-key or owner submission attempts per 15-minute window. The limiter stores the order ID, window and count. It does not collect IP addresses. Expired windows are removed in bounded batches during submissions.

Claims capture the date, amount in order currency, optional bank reference, optional sender name and optional customer note. The amount accepts plain decimal notation and uses the WooCommerce price precision. Confirmation reflects a submitted claim. The workflow does not mark or verify payment.

The current form accepts text only. Private attachments, retention and reviewer transitions are covered by later tasks. Native tests call the submission controller in WordPress with a live MariaDB database. Full browser form and redirect tests remain open.
