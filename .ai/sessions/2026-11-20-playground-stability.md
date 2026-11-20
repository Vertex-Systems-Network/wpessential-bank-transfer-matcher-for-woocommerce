# Handoff — targeted upstream Playground ZIP download retry

- Branch development/autonomous-release-track, previous parent a6e11e45bc3f798cd2312e56ff7b1af1e3254bd4; draft PR #9 unmerged.
- Repeated prior CI failures were solely `Could not download https://downloads.wordpress.org/plugin/woocommerce.latest-stable.zip` from WordPress Playground. Native WordPress/WooCommerce/MariaDB tests, syntax and static checks passed.
- Added a bounded two-retry path ONLY for that exact external download failure; all other Blueprint/PHP assertion failures propagate immediately. Captured CLI output is shown, and retry timings are 2 and 4 seconds. Added Python unit fixture covering transient recovery, assertion hard fail and exhausted budget; runs inside foundation before actual Playground.
- This CI harness change is **pending green verification**. P5-T007 continues with real separate-connection maker/checker and split group lock challenge tests.
- No production payment mutation, release or merge.
