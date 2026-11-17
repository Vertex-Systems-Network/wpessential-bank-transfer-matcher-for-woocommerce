# Capability discovery

`GET /wp-json/wpessential-wbm/v1/capabilities` returns the authenticated user's effective Matcher capability flags. WordPress REST authentication applies.

| Capability | Intended access |
| --- | --- |
| `wbm_view` | Open the Matcher admin page and read the user's own capability map |
| `wbm_review_claim` | Review claims when the claims workflow is available |
| `wbm_approve` | Approve verification when the approval workflow is available |

Free grants these capabilities by default to administrators and WooCommerce shop managers. An explicitly denied capability survives default provisioning. Subsequent role edits persist through plugin reloads and reactivation. Other roles require an explicit grant.

The response contains only the capability flags. Anonymous users receive HTTP 401. Authenticated users without `wbm_view` receive HTTP 403. Implement object-level authorization alongside any future order, claim or payout endpoint.
