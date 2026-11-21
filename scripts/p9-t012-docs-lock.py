#!/usr/bin/env python3
"""Verify, never forge, canonical P9 Free+Pro documentation and real archive metadata."""
import argparse
import hashlib
import json
import os
from pathlib import Path
from zipfile import ZipFile

ROOT=Path(__file__).resolve().parents[1]
SLUG="wpessential-bank-transfer-matcher"
ED=["free","pro"]

def req(value,msg):
    if not value: raise SystemExit(msg)
def sha(raw):
    return hashlib.sha256(raw).hexdigest()

def validate_versions(meta,free_readme):
    version=meta["version"]
    req(meta["status"]=="verified","Do not claim public release from preview metadata")
    require_text=[
       f"Stable tag: {version}",
       f"Tested up to: {meta['tested_wp']}",
       f"WC tested up to: {meta['tested_wc']}",
       f"Requires at least: {meta['requires_wp']}",
       f"WC requires at least: {meta['requires_wc']}",
       f"Requires PHP: {meta['requires_php']}",
       "Requires Plugins: woocommerce",
       "License: GPL-2.0-or-later"
    ]
    for expected in require_text:
        req(f"\n{expected}\n" in "\n"+free_readme,
            "Free WordPress.org README version/capability header mismatch: "+expected)
    req("== Changelog ==" in free_readme and "== Installation ==" in free_readme,
        "Generated WordPress.org Free README sections absent")

def check():
    meta=json.loads((ROOT/"docs/source/product-metadata.json").read_text())
    source=(ROOT/"plugins"/SLUG/"readme.txt").read_bytes()
    validate_versions(meta,source.decode())
    generated=json.loads((ROOT/"docs/release/metadata-sync-report.json").read_text())
    catalog=json.loads((ROOT/"docs/source/feature-catalog.json").read_text())
    req(generated["version"]==meta["version"] and generated["status"]=="verified",
        "Generated metadata version/status drift")
    req(set(generated["checked_headers"])=={meta[k]["slug"] for k in ED},
        "Both Free/Pro headers not covered")
    ids={x["id"] for x in catalog if x["status"] in ("verified","released")}
    req(ids=={x["id"] for x in generated["verified_preview_features"]},
        "Generated feature catalog has fabricated/missing verified IDs")
    for x in generated["verified_preview_features"]:
        evidence=ROOT/x["evidence"]
        req(evidence.is_file() and x["test_id"].startswith("VERIFY-"),
            "Unsupported generated feature proof: "+x["id"])
    report=json.loads((ROOT/"dist/p9-validation-report.json").read_text())
    req(report["version"]==meta["version"] and report["releasable"] is False and
        report["external_marketplace_submission"]=="NOT AUTHORIZED" and
        report["managed_woo_qit"]=="NOT RUN",
        "Release manifest not safe or canonical version mismatch")
    req(report["source_commit"]==(os.getenv("WBM_SOURCE_HEAD_SHA") or os.getenv("GITHUB_SHA")),
        "Candidate docs check source SHA does not match CI checkout/source event")
    changelog=(ROOT/"docs/source/changelog"/(meta["version"]+".md")).read_bytes()
    from_dist=(ROOT/"dist"/("CHANGELOG-"+meta["version"]+".md")).read_bytes()
    req(changelog==from_dist and sha(changelog)==report["changelog_sha256"],
        "Versioned Free/Pro changelog drift")
    packages=[]
    for edition in ED:
        slug=meta[edition]["slug"]
        path=ROOT/"dist"/(slug+".zip")
        req(path.is_file(),"Missing actual production ZIP: "+slug)
        root=ROOT/"plugins"/slug
        with ZipFile(path) as zipfile:
            req(zipfile.testzip() is None,"Corrupt edition ZIP")
            basename=slug+"/"+slug+".php"
            ziphead=zipfile.read(basename)
            req(ziphead==(root/(slug+".php")).read_bytes(),
                "Source/plugin main PHP header is not identical to released ZIP: "+slug)
            header=ziphead.decode()
            req(f"* Version: {meta['version']}\n" in header and
                f"* WC tested up to: {meta['tested_wc']}\n" in header and
                f"* Requires at least: {meta['requires_wp']}\n" in header and
                f"* Requires PHP: {meta['requires_php']}\n" in header and
                "* Tested up to:" not in header,
                "Edition main PHP contains unsupported/old version header "+slug)
            req(zipfile.read(slug+"/README.md")==(root/"README.md").read_bytes(),
                "Generated edition README not packaged identically")
            req(zipfile.read(slug+"/LICENSE")==(root/"LICENSE").read_bytes(),
                "Edition GPL notice/contents mismatch")
            if edition=="free":
                req(zipfile.read(slug+"/readme.txt")==source,
                    "Free WordPress.org readme differs from canonical source")
            else:
                req(not any("readme.txt"==name.removeprefix(slug+"/") for name in zipfile.namelist()),
                    "Unexpected WP.org Free readme in Pro package")
        req(report["release_archives"][slug+".zip"]["sha256"]==sha(path.read_bytes()),
            "Release SHA256 inventory disagrees with original edition package")
        packages.append({"edition":edition,"slug":slug,"sha256":sha(path.read_bytes()),
                         "zip_source_readmes_identical":True,"main_plugin_php_header_identical":True,
                         "gpl_license_included":True})
    out={
      "schema_version":1,"source_head":os.getenv("WBM_SOURCE_HEAD_SHA") or "NOT VERIFIED",
      "ci_checkout_sha":os.getenv("GITHUB_SHA") or "NOT VERIFIED",
      "ci_run_id":os.getenv("GITHUB_RUN_ID") or "NOT VERIFIED",
      "candidate_version":meta["version"],"tested_wordpress":meta["tested_wp"],
      "tested_woocommerce":meta["tested_wc"],
      "historical_four_cell_matrix":"SEPARATE SOURCE CI GATE",
      "latest_patch_test":"SEPARATE real native CI gate",
      "canonical_changelog_sha256":sha(changelog),
      "wordpress_org_readme_sha256":sha(source),
      "catalog_verified_ids":sorted(ids),
      "generated_docs_checks":"actual npm run docs:check and negative fixtures run separately in Foundation CI",
      "source_packages":packages,
      "managed_qit":"NOT RUN","marketplace_publication":"NOT AUTHORIZED",
      "reviewed_release_status":"DEVELOPMENT CANDIDATE ONLY",
      "release_ready":False
    }
    (ROOT/"dist/p9-t012-docs-lock.json").write_text(json.dumps(out,indent=2)+"\n")
    return out

def negative_tests():
    metadata=json.loads((ROOT/"docs/source/product-metadata.json").read_text())
    readme=(ROOT/"plugins"/SLUG/"readme.txt").read_text()
    fake={**metadata,"version":"9.9.9"}
    try:
        validate_versions(fake,readme)
    except SystemExit as e: req("mismatch" in str(e),"Wrong version denial")
    else: raise SystemExit("CRITICAL version mismatch accepted")
    invalid=readme.replace("Tested up to: "+metadata["tested_wp"],
                           "Tested up to: 9.9")
    try:
        validate_versions(metadata,invalid)
    except SystemExit as e: req("mismatch" in str(e),"Wrong tested version denial")
    else: raise SystemExit("CRITICAL falsified tested-up-to accepted")
    print("PASS canonical version/tag and fake-tested-version negative tests")

def main():
    p=argparse.ArgumentParser()
    p.add_argument("--negative-tests",action="store_true")
    args=p.parse_args()
    if args.negative_tests: negative_tests()
    out=check()
    print("PASS original Free/Pro ZIP canonical metadata, 0.1.0 changelog and all verified feature docs; release remains blocked",len(out["source_packages"]))
if __name__=="__main__":main()
