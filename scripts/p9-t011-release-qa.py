#!/usr/bin/env python3
"""P9-T011: derive honest release-candidate evidence from actual CI jobs.

This verifier never treats a manifest as proof that some other CI job succeeded.
It never touches production bank data and never generates fake screenshots.
"""
import argparse
import hashlib
import json
import os
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
NEEDED={
    "real_cross_process_races":"PASS real simultaneous WooCommerce maker, checker, split recipient and paid-intent races",
    "hpos_paired_crud":"PASS P8-T005 real Free+Pro Woo HPOS compatibility declarations and hpos WC CRUD",
    "classic_paired_crud":"PASS P8-T005 real Free+Pro Woo HPOS compatibility declarations and classic WC CRUD",
    "free_plus_pro_install":"PASS paired ZIP replacement and bootstrap",
}
REPORT_NAMES=("p7-report-ltr.json","p7-report-rtl.json","p7-navigation-ltr.json",
              "p7-navigation-rtl.json","p7-usability-ltr.json","p7-usability-rtl.json")

def require(v,msg):
    if not v: raise SystemExit(msg)

def sha(raw):
    return hashlib.sha256(raw).hexdigest()

def base_report():
    return {"schema_version":1,"phase":"P9-T011","run_id":os.getenv("GITHUB_RUN_ID") or "NOT VERIFIED",
            "source_commit":os.getenv("GITHUB_SHA") or "NOT VERIFIED",
            "role":"development candidate CI, NOT public release",
            "browser_and_native_synthetic":True,
            "managed_woo_qit":"NOT RUN",
            "production_payment_or_bank_actions":False,
            "other_independent_job_checks":"MUST REVIEW SUCCESS ON SAME EXACT HEAD SHA",
            "publication":"NOT AUTHORIZED",
            "release_ready":False}

def native(source):
    lines=Path(source).read_text(errors="replace")
    require(os.getenv("GITHUB_ACTIONS") == "true","Native evidence requires genuine Actions environment")
    require("wbm_ci" in (ROOT/".github/workflows/php-ci.yml").read_text(),"Disposable MariaDB CI target missing")
    matched={}
    for key,needle in NEEDED.items():
        count=lines.count(needle)
        require(count>=1,"Genuine native WooCommerce evidence missing "+key)
        matched[key]={"count":count,"assertion":needle}
    require("PHP Fatal error:" not in lines and "There has been a critical error" not in lines,
            "Native CI output included unhandled PHP fatal error")
    out={**base_report(),"type":"real_native_mariadb_cross_request_races",
         "required_logged_assertions":matched,
         "native_woo_source":"scripts/test-native-install.sh",
         "script_invokes_real_race":"scripts/test-pro-cross-process-races.sh" in (ROOT/"scripts/test-native-install.sh").read_text(),
         "wordpress_requested":"7.0.8", "woocommerce_requested":"11.3.0",
         "database_requested":"MariaDB 11.4",
         "latest_patch_tested_elsewhere":"REFER TO SEPARATE real p9-latest-version-native.yml CI",
         "independent_playground":"SEPARATE Foundation job",
         "independent_browser":"SEPARATE real Chromium job"}
    require(out["script_invokes_real_race"],"Native CI script no longer executes two-process races")
    target=ROOT/"dist/p9-t011-native-matrix.json"
    target.write_text(json.dumps(out,indent=2)+"\n")
    print("PASS genuine native WooCommerce/MariaDB + cross-request maker/checker/split lock and HPOS/classic tests; no fake cross-job success")

def browser(source, output):
    root=Path(source); out=Path(output)
    m=json.loads((out/"evidence-manifest.json").read_text())
    require(os.getenv("GITHUB_ACTIONS")=="true","Browser evidence requires real CI environment")
    require(m.get("source_commit")==os.getenv("GITHUB_SHA") and
            m.get("source_run_id")==os.getenv("GITHUB_RUN_ID"),
            "Visual screenshots not from exact-source CI job")
    require(m.get("synthetic_only") is True and m.get("pixel_edits") is False and
            m.get("financial_mutations") is False and m.get("marketplace_approval") is False,
            "Browser manifest claims edited screens, non-synthetic state or release")
    screenshots=m["screenshots"]
    require(len(screenshots)==13 and
            [sum(s["edition"]==x for s in screenshots) for x in ("free","pro","qa")]==[5,6,2],
            "Incorrect Free/Pro/RTL/mobile capture counts")
    verified=[]
    for pic in screenshots:
        original=root/pic["source"]
        rendered=out/pic["filename"]
        source_bytes=original.read_bytes()
        target_bytes=rendered.read_bytes()
        require(source_bytes==target_bytes and sha(source_bytes)==pic["sha256"],
                "Real screenshot differs from source WordPress/Chromium capture")
        require(pic["filename"].startswith(pic["edition"]+"/"),"Edition-specific screenshot path not isolated")
        verified.append({"file":pic["filename"],"sha256":pic["sha256"],
                         "source":pic["source"],"width":pic["width"],"height":pic["height"]})
    checked=[]
    for name in REPORT_NAMES:
        data=json.loads((root/name).read_text())
        require(data.get("disposable") is True and data.get("issues")==[] and
                data.get("no_financial_mutations",True) is True,
                "Real browser RTL/navigation/usability reports do not pass")
        checked.append({"name":name,"sha256":sha((root/name).read_bytes()),"issue_count":0})
    report={**base_report(),"type":"real_chromium_wordpress_free_pro_ltr_arabic_rtl",
            "screenshots":verified,"report_files":checked,"no_raw_credential_or_private_csv_upload":True,
            "native_mariadb_race":"SEPARATE CI JOB, MUST CHECK EXACT SOURCE HEAD",
            "playground_executed":"SEPARATE Foundation CI job, not proven by browser report"}
    (out/"p9-t011-browser-matrix.json").write_text(json.dumps(report,indent=2)+"\n")
    print("PASS 13 authentic and unchanged Free/Pro/mobile/RTL browser screenshots and six original report SHA256s at exact CI source")

def main():
    a=argparse.ArgumentParser()
    a.add_argument("--native-log")
    a.add_argument("--browser-dir")
    a.add_argument("--screens-dir")
    args=a.parse_args()
    require(bool(args.native_log) != bool(args.browser_dir),"Use exactly one real evidence mode")
    if args.native_log:
        native(args.native_log)
    else:
        require(args.screens_dir,"Missing exact-source browser screenshot directory")
        browser(args.browser_dir,args.screens_dir)
if __name__=="__main__":main()
