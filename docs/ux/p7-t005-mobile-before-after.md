# P7-T005 actual mobile UI before/after visual QA evidence

Status: **verified** at code SHA `27e2c46bf7e26e983a5b908fdab4a57f91c29c85` with full GitHub Actions browser 44682670860, Foundation/native 44682670864 and 44682677458 SUCCESS.

## What was visually checked

Baseline **111 actual WP/WC browser screenshots**: run 44674030975, artifact ID 14601346876. The 375px LTR and actual Arabic RTL screenshots identified cramped original bank batch and page-size fields, Filter/Apply buttons sharing narrow rows, unclear indication that bank/review/ledger table columns scroll, and a live WP PHP wrapper mismatch in automated submit-button selector. After-source screenshots **115 actual PNGs**, browser run 44682670860, artifact ID **14605361910**, LTR 59 + Arabic RTL 56, include real GET submission screenshots, 0 automated issues.

Mobile UI improvements: the existing source-batch selector is visibly labelled and stacked; all three genuine finance filter forms have >=80%-width fields and full-width native WordPress direct submit inputs, with no overlaps; scroll notes explain all columns remain available without claiming bank clearance. Reviewer priority and state pills remain text+border, never color alone. Allocation ledger deep paging clamped at 10,000, phantom Next avoided at complete last pages; native disposable 20/21 boundary tests guard real source pagination.

## Evidence and tests

- Real browser script: `tests/browser/p7-visual.mjs` — 14 unique UI IDs at 1440/1024/375, high contrast, actual Arabic WP locale, keyboard scroll/Tab, 6 field geometry and 4 genuine GET filter submissions, 0 issues, no money mutations.
- Native WP/WC/MariaDB: `pro-import-ui.php`, `pro-review-ui.php`, `pro-allocation-ui.php` plus 20/21 synthetic row pagination fixtures and strict PHP native UI CSS scope tests.
- Exact SHA three green actions: [browser 44682670860](https://github.com/Vertex-Systems-Network/wpessential-bank-transfer-matcher-for-woocommerce/actions/runs/44682670860), [Foundation/native 44682670864](https://github.com/Vertex-Systems-Network/wpessential-bank-transfer-matcher-for-woocommerce/actions/runs/44682670864), [Foundation/native 44682677458](https://github.com/Vertex-Systems-Network/wpessential-bank-transfer-matcher-for-woocommerce/actions/runs/44682677458).
- Relevant screenshot comparisons in above artifacts: before/after `ltr-UI-008-pro-bank-transactions-375.png`, `rtl-UI-008-pro-bank-transactions-375.png`, `rtl-UI-009-pro-review-queue-375.png`, and after `rtl-functional-review-filter-empty-375.png`, `ltr-functional-bank-source-page-size-375.png`.

Not claimed: complete plugin Arabic locale translation, WCAG manual screen-reader conformance or P7-T006 navigation/P7-T007 full rubric/P7-T008 role-based walkthrough. No production money processing or release.
