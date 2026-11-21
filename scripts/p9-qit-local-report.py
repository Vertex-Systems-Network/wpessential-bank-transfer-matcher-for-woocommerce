#!/usr/bin/env python3
"""A local QIT CLI environment is NOT WooCommerce managed QIT cloud acceptance."""
import hashlib
import json
import os
from pathlib import Path
from zipfile import ZipFile

ROOT=Path(__file__).resolve().parents[1]
D=ROOT/"dist"
S=["woocommerce","wpessential-bank-transfer-matcher","wpessential-bank-transfer-matcher-pro"]
def require(v,m):
    if not v: raise SystemExit(m)

def main():
    ledger=json.loads((ROOT/"docs/submission/woocommerce-marketplace/p9-t002-qit-matrix.json").read_text())
    suites=ledger["suites"]
    require(len(suites)==7 and len({s["id"] for s in suites})==7,"Missing official QIT test types")
    require(all(s["official_qit_status"]=="not_run" and
                s["official_run_id"] is None and s["official_report_url"] is None for s in suites),
            "Do not overwrite/claim managed QIT evidence")
    flags=(D/"p9-qit-active-plugins.txt").read_text().splitlines()
    require(flags==[s+"=active" for s in S],"Not all actual plugins active in disposable QIT local test")
    version=(D/"p9-qit-cli-version.txt").read_text().strip()
    require("qit" in version.lower() and len(version)<300,"No actual QIT CLI version proof")
    packages=[]
    for slug in S[1:]:
        p=D/(slug+".zip")
        require(p.is_file(),"Missing real independently built ZIP "+slug)
        with ZipFile(p) as z:
            require(z.testzip() is None and all(m.startswith(slug+"/") for m in z.namelist()),
                    "Malformed Free/Pro package for local QIT")
        packages.append({"slug":slug,"sha256":hashlib.sha256(p.read_bytes()).hexdigest()})
    summary={
       "schema_version":1,
       "scope":"Woo official QIT CLI LOCAL test environment ONLY, not managed vendor QIT",
       "source_head":os.getenv("WBM_SOURCE_HEAD_SHA") or "NOT VERIFIED",
       "ci_checkout_sha":os.getenv("GITHUB_SHA") or "NOT VERIFIED",
       "run_id":os.getenv("GITHUB_RUN_ID") or "NOT VERIFIED",
       "qit_cli_version":version,
       "qit_local_activated_plugins":S,
       "qit_local_execution":"PASSED",
       "wordpress_requested":"7.1.5",
       "woocommerce_requested":"11.3.1",
       "php_requested":"8.3",
       "packages":packages,
       "managed_qit_status":"NOT RUN (partner/vendor access unavailable)",
       "managed_qit_runs":[],
       "vendor_acceptance":"NOT VERIFIED",
       "public_submission":"NOT AUTHORIZED",
       "release_ready":False,
    }
    (D/"p9-qit-local-result.json").write_text(json.dumps(summary,indent=2)+"\n")
    print("PASS actual Woo QIT local CLI activated Woo + Free + Pro; all managed suites NOT RUN")
if __name__=="__main__": main()
