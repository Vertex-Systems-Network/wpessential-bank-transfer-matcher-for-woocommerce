# Customer payment claims

Free displays a claim form for eligible BACS orders with a stored payment reference. My Account uses the authenticated order owner. A guest needs the WooCommerce order key and billing email. Both submission paths require a WordPress nonce scoped to the order.

MariaDB enforces one claim per order. The first accepted claim is preserved when the customer resubmits. Each order can make eight verified-key or owner submission attempts per 15-minute window. The limiter stores the order ID, window and count. It does not collect IP addresses. Expired windows are removed in bounded batches during submissions.

Claims capture the date, amount in order currency, optional bank reference, optional sender name and optional customer note. The amount accepts plain decimal notation and uses the WooCommerce price precision. Confirmation reflects a submitted claim. The workflow does not mark or verify payment.

Native tests call the submission controller in WordPress with a live MariaDB database. Full browser form and redirect tests remain open.

## Private receipts

Uploads are off by default. Set `wpessential_wbm_claim_uploads` to `yes` on a configured site to enable the optional receipt field. The settings screen will expose this control in P2-T011. The server accepts PDF, JPEG or PNG up to 2 MB after checking upload provenance and inspecting content. Receipts are stored as bytes with the claim in the database, outside the Media Library.

A reviewer can download an unexpired receipt from the WooCommerce order screen. The handler requires the review capability and an order-scoped nonce and returns the file as an attachment. After 30 days the file is no longer downloadable. A daily WordPress scheduled event clears expired bytes in batches of 100, retaining the claim record. The event needs a working WordPress cron runner. Database backups may retain copies according to the store's backup policy.

Native coverage includes validation, private byte persistence, nonce and capability checks, and expiry cleanup. Browser multipart upload and HTTP download headers remain unverified.
