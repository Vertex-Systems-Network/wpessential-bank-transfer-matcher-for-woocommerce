#!/usr/bin/env python3
"""Audit original Free/Pro ZIPs and emit bounded real CI-only no-Composer proof."""
import argparse,hashlib,json,os
from pathlib import Path
from zipfile import ZipFile
ROOT=Path(__file__).resolve().parents[1]
SLUGS=("wpessential-bank-transfer-matcher","wpessential-bank-transfer-matcher-pro")
def require(v,m):
    if not v:raise SystemExit(m)
def sha(raw):return hashlib.sha256(raw).hexdigest()
def write(filename,report):
    (ROOT/"dist"/filename).write_text(json.dumps(report,indent=2)+"\n")
def common():
    return {"schema_version":1,"source_commit":os.getenv("WBM_SOURCE_HEAD_SHA") or "NOT VERIFIED",
            "ci_checkout_sha":os.getenv("GITHUB_SHA") or "NOT VERIFIED",
            "ci_run_id":os.getenv("GITHUB_RUN_ID") or "NOT VERIFIED",
            "release_ready":False,"marketplace_publication":"NOT AUTHORIZED",
            "managed_qit":"NOT RUN"}
def audit():
    inventory=json.loads((ROOT/"dist/p9-license-inventory.json").read_text())
    manifest=json.loads((ROOT/"dist/p9-validation-report.json").read_text())
    require(manifest["releasable"] is False and inventory["no_public_release"],"Release gate falsified")
    results=[]
    for index,slug in enumerate(SLUGS):
        raw=(ROOT/"dist"/(slug+".zip")).read_bytes()
        with ZipFile(ROOT/"dist"/(slug+".zip")) as z:
            names=z.namelist();rel=[x.removeprefix(slug+"/") for x in names]
            require(z.testzip() is None and len(names)==len(set(names)),"Bad CRC/duplicate ZIP member")
            require(all(n.startswith(slug+"/") for n in names),"Unexpected ZIP root")
            require(all(x in rel for x in ["LICENSE","vendor/autoload.php",
                    "vendor/composer/autoload_classmap.php","vendor/composer/autoload_psr4.php",
                    "vendor/composer/installed.json","composer.lock"]),"Missing optimized Composer runtime")
            require(not any("node_modules" in x or "tests/" in x for x in names),"Dev files in released ZIP")
            if index==0:require(not any("-pro/" in x or "src/Pro/" in x for x in names),"Pro code leaked into Free ZIP")
            lock=json.loads(z.read(slug+"/composer.lock"))
            installed=json.loads(z.read(slug+"/vendor/composer/installed.json"))
            require(lock["packages"]==[] and not lock.get("packages-dev") and
                    installed["packages"]==[] and installed["dev"] is False,
                    "Vendor production dependencies require separate audit")
            require(b"SPDX-License-Identifier: GPL-2.0-or-later" in z.read(slug+"/LICENSE"),"GPL notice missing")
        digest=sha(raw)
        require(digest==inventory["editions"][index]["sha256"] and
                digest==manifest["release_archives"][slug+".zip"]["sha256"],
                "Actual ZIP SHA256 differs from independent license/release reports")
        results.append({"edition":"free" if index==0 else "pro","slug":slug,
                        "sha256":digest,"members":len(names),
                        "optimized_composer":True,"locked_runtime_dependencies":0,
                        "license":"GPL-2.0-or-later","native_runtime":"SEPARATE CI JOB"})
    d={**common(),"scope":"P9-T013 original production ZIP byte audit",
       "editions":results,"runtime_no_composer":"SEPARATE real MariaDB CI job"}
    write("p9-t013-final-zip-report.json",d)
    print("PASS original Free/Pro isolated ZIP archive, Composer autoload+classmap, GPL and identical checksum manifest")
def native(log_path):
    require(os.getenv("GITHUB_ACTIONS") == "true", "Composerless native report only in CI")
    expected = (
        "PASS actual Woo+Free+Pro composerless boot and synthetic unpaid order round trip",
        "PASS actual plugin ZIP native runtime without Composer in PATH",
    )
    log = log_path.read_bytes()
    lines = log.decode().splitlines()
    require(all(lines.count(marker) == 1 for marker in expected)
            and lines.index(expected[0]) < lines.index(expected[1]),
            "Missing or invalid completed Composerless native smoke test output")
    d={**common(),"scope":"P9-T013 real composerless native WordPress installation",
       "native_log_sha256":sha(log),
       "composer_executable_available_to_wp":False,
       "actual_installed_woo_free_pro_bootstrap":"PASSED in native CI smoke test",
       "unpaid_synthetic_wc_crud":"PASSED in native CI smoke test",
       "zip_audit":"SEPARATE Foundation CI JOB",
       "public_submission":False}
    write("p9-t013-no-composer-report.json",d)
    print("PASS actual installed WooCommerce Free+Pro runtime without Composer after successful native shell")
def main():
    p=argparse.ArgumentParser()
    g=p.add_mutually_exclusive_group(required=True)
    g.add_argument("--archive",action="store_true")
    g.add_argument("--native-log", type=Path)
    a=p.parse_args()
    if a.archive:audit()
    else:native(a.native_log)
if __name__=="__main__":main()
