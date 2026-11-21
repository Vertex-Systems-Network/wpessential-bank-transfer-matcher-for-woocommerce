#!/usr/bin/env python3
"""Derive release-candidate hashes and cautious compatibility evidence from actual CI artifacts.

No managed QIT, latest-version support, vendor account or public release is inferred.
"""
import argparse
import copy
import hashlib
import json
import os
from pathlib import Path
import re
from zipfile import ZipFile

ROOT = Path(__file__).resolve().parents[1]
ARCHIVES = (
    "wpessential-bank-transfer-matcher.zip",
    "wpessential-bank-transfer-matcher-pro.zip",
    "playground-base.zip",
    "playground-free.zip",
    "playground-pro.zip",
)
def require(value, reason):
    if not value:
        raise SystemExit(reason)

def digest(path):
    return hashlib.sha256(Path(path).read_bytes()).hexdigest()

def check_bundle_hashes(bundles, digests):
    require([entry.get("edition") for entry in bundles] == ["base", "free", "pro"], "Unexpected Blueprint editions")
    expected_nested = {
        "base": [],
        "free": ["wpessential-bank-transfer-matcher.zip"],
        "pro": ["wpessential-bank-transfer-matcher.zip", "wpessential-bank-transfer-matcher-pro.zip"],
    }
    for entry in bundles:
        edition = entry["edition"]
        actual = entry.get("embedded_production_archives", [])
        require([row.get("file") for row in actual] == expected_nested[edition], "Unexpected Playground installation order")
        require(entry["file"] == "playground-" + edition + ".zip", "Incorrect Blueprint artifact name")
        require(entry.get("sha256") == digests[entry["file"]], "Playground bundle manifest hash mismatch")
        require(entry.get("built_from_canonical_blueprint") is True, "Blueprint source provenance unverified")
        require(entry.get("executed") is False, "Manifest cannot independently prove live execution")
        for row in actual:
            require(row.get("sha256") == digests[row["file"]], "Nested production ZIP SHA256 mismatch")

def configured_version_matrix(root, metadata):
    workflow = (root / ".github/workflows/p8-wc-php-matrix.yml").read_text()
    php_match = re.search(r"^\s*php:\s*\[([^\]]+)\]", workflow, flags=re.MULTILINE)
    wc_match = re.search(r"^\s*woocommerce:\s*\[([^\]]+)\]", workflow, flags=re.MULTILINE)
    wp_match = re.search(r"wp core download[^\n]*--version=([0-9]+\.[0-9]+\.[0-9]+)", workflow)
    require(bool(php_match and wc_match and wp_match), "Cannot establish exact installed version test configuration")
    php = re.findall(r"\d+\.\d+", php_match.group(1))
    wc = re.findall(r"\d+\.\d+\.\d+", wc_match.group(1))
    wp = wp_match.group(1)
    require(php == ["8.3","8.4"] and wc == ["10.8.0","11.3.0"] and wp == "7.0.8",
            "Version suite changed; human review current evidence before updating claims")
    require(metadata["tested_wc"] == "11.3.0" and metadata["requires_php"] == "8.3",
            "Canonical version claims no longer match this fixture")
    return {
        "schema_version": 1,
        "type": "installed_source_matrix_historical_not_current_recertification",
        "configured_wordpress": [wp], "configured_php": php,
        "configured_woocommerce": wc, "database": "MariaDB 11.4",
        "storage_modes": ["classic","hpos"],
        "source_workflow": ".github/workflows/p8-wc-php-matrix.yml",
        "source_evidence": "docs/security/p8-t006-checkout-compatibility.md",
        "historical_ci_run": "44829621415",
        "wp_latest_patch": "NOT VERIFIED",
        "woocommerce_latest_patch": "NOT VERIFIED",
        "official_woocommerce_qit": "NOT RUN",
        "note": "This reads configured versions and documented previous success; it does not run the version matrix or assert support for untested releases.",
    }

def build(root):
    dist = root / "dist"
    meta = json.loads((root / "docs/source/product-metadata.json").read_text())
    version = meta["version"]
    require(bool(re.fullmatch(r"[0-9]+\.[0-9]+\.[0-9]+", version)) and meta["status"] == "verified",
            "Missing verified canonical development version")
    archives = {}
    for name in ARCHIVES:
        f = dist / name
        require(f.is_file(), "Missing actual packaged release artifact: " + name)
        with ZipFile(f) as z:
            require(z.testzip() is None, "Corrupt archive member in " + name)
        archives[name] = {"sha256": digest(f), "bytes": f.stat().st_size}
    license_report = json.loads((dist / "p9-license-inventory.json").read_text())
    require(license_report.get("no_public_release") is True, "License inventory incorrectly claims release")
    require([r.get("edition") for r in license_report["editions"]] == ["free","pro"],
            "Missing separate edition inventory")
    for row, filename in zip(license_report["editions"], ARCHIVES[:2]):
        require(row.get("sha256") == archives[filename]["sha256"], "Plugin inventory mismatches generated ZIP")
        require(row.get("license") == "GPL-2.0-or-later", "Plugin license mismatch")
        require(row.get("runtime_composer_packages") == [], "New runtime vendor packages require review")
    bp_manifest = json.loads((dist / "p9-playground-bundle-manifest.json").read_text())
    require(bp_manifest.get("release_published") is False, "Playground manifest claims public distribution")
    check_bundle_hashes(bp_manifest["bundles"], {k:v["sha256"] for k,v in archives.items()})
    matrix = configured_version_matrix(root, meta)
    changelog_file = root / "docs/source/changelog" / (version + ".md")
    changelog = changelog_file.read_bytes()
    require(changelog.startswith(b"## Free\n") and b"## Pro\n" in changelog,
            "Canonical changelog lacks edition separation")
    name = "CHANGELOG-" + version + ".md"
    (dist / name).write_bytes(changelog)
    (dist / "SHA256SUMS").write_text(
        "".join(archives[f]["sha256"] + "  " + f + "\n" for f in sorted(archives)),
        encoding="utf-8")
    (dist / "p9-compatibility-matrix.json").write_text(json.dumps(matrix, indent=2) + "\n", encoding="utf-8")
    report = {
        "schema_version": 1,
        "version": version,
        "edition": "separate_free_and_pro",
        "source_commit": os.getenv("WBM_SOURCE_HEAD_SHA") or os.getenv("GITHUB_SHA") or "NOT VERIFIED local input",
        "ci_checkout_sha": os.getenv("GITHUB_SHA") or "NOT VERIFIED local checkout",
        "ci_event_name": os.getenv("GITHUB_EVENT_NAME") or "NOT VERIFIED local event",
        "source_ci_run_id": os.getenv("GITHUB_RUN_ID") or "NOT VERIFIED local run",
        "scope": "P9-T006 candidate evidence - generated after ordered Foundation Playground test step",
        "status": "development_candidate",
        "releasable": False,
        "external_marketplace_submission": "NOT AUTHORIZED",
        "official_wordpress_plugin_check": "NOT RUN",
        "official_wordpress_org_readme_validator": "NOT VERIFIED",
        "managed_woo_qit": "NOT RUN",
        "native_mariadb_job": "SEPARATE CI JOB; read its conclusion independently",
        "live_blueprint_executed": "NOT SELF PROVED by generated hash report; inspect parent CI Foundation run",
        "latest_patch_compatibility": "NOT VERIFIED",
        "changelog_source": str(changelog_file.relative_to(root)),
        "changelog_sha256": hashlib.sha256(changelog).hexdigest(),
        "release_archives": archives,
        "hashes_include": list(sorted(archives)),
        "dependency_source": "dist/p9-license-inventory.json",
        "playground_source": "dist/p9-playground-bundle-manifest.json",
        "compatibility_source": ".github/workflows/p8-wc-php-matrix.yml",
    }
    (dist / "p9-validation-report.json").write_text(json.dumps(report, indent=2)+"\n", encoding="utf-8")
    return report

def negative_tests(report):
    fake = copy.deepcopy(report)
    fake["release_archives"]["playground-pro.zip"]["sha256"] = "00" * 32
    rows = [
        {"edition": "base", "file": "playground-base.zip", "sha256": report["release_archives"]["playground-base.zip"]["sha256"],
         "embedded_production_archives": [], "built_from_canonical_blueprint": True, "executed": False},
        {"edition": "free", "file": "playground-free.zip", "sha256": report["release_archives"]["playground-free.zip"]["sha256"],
         "embedded_production_archives": [{"file": ARCHIVES[0], "sha256": report["release_archives"][ARCHIVES[0]]["sha256"]}],
         "built_from_canonical_blueprint": True, "executed": False},
        {"edition": "pro", "file": "playground-pro.zip", "sha256": report["release_archives"]["playground-pro.zip"]["sha256"],
         "embedded_production_archives": [{"file": ARCHIVES[0], "sha256": report["release_archives"][ARCHIVES[0]]["sha256"]},
            {"file": ARCHIVES[1], "sha256": report["release_archives"][ARCHIVES[1]]["sha256"]}],
         "built_from_canonical_blueprint": True, "executed": False},
    ]
    try:
        check_bundle_hashes(rows, {k:v["sha256"] for k,v in fake["release_archives"].items()})
    except SystemExit as exc:
        require("hash mismatch" in str(exc), "Wrong hash mutation rejection")
    else:
        raise SystemExit("CRITICAL: mismatch in bundled release artifact hash accepted")
    rows[2]["executed"] = True
    try:
        check_bundle_hashes(rows, {k:v["sha256"] for k,v in report["release_archives"].items()})
    except SystemExit as exc:
        require("cannot independently prove" in str(exc), "Wrong fake live execution rejection")
    else:
        raise SystemExit("CRITICAL: fabricated execution status accepted")

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--negative-tests", action="store_true")
    args = parser.parse_args()
    report = build(ROOT)
    if args.negative_tests:
        negative_tests(report)
    print("PASS P9-T006 real Free/Pro/3-Blueprint ZIP SHA256SUMS, source changelog, source-pinned historical compatibility and cautious validation report")

if __name__ == "__main__":
    main()
