#!/usr/bin/env python3
"""Fail-closed audit of publication blockers; this script NEVER submits or publishes."""
import argparse
import json
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
INVENTORY=ROOT/"docs/release/p9-t008-publication-gates.json"
EXPECTED={
    "product_acceptance", "latest_supported_matrix", "full_phase_exact_head_ci",
    "wp_org_plugin_check", "wp_org_readme_validator", "woocommerce_managed_qit",
    "final_release_zip_integrity", "final_brand_listing_review",
    "commercial_pro_support_license", "wordpress_org_2fa_authorized_account",
    "wordpress_org_directory_review", "wordpress_org_svn_write_access",
    "woo_marketplace_vendor_access", "woo_marketplace_contract_listing",
    "public_submission_explicit_approval",
}
def require(check,reason):
    if not check:
        raise SystemExit(reason)
def verify(document,acceptance):
    require(document["schema_version"]==1, "Publication schema drift")
    require(document["scope"].startswith("P9-T008"),"Unexpected publisher runbook scope")
    require(document["public_release_authorized"] is False,"Unauthorized public release flag")
    require(document["owner_approval"]=="NOT AUTHORIZED","Owner permission fabricated")
    require(document["release_ready"] is False,"Unverified release prematurely marked ready")
    ids=[x.get("id") for x in document.get("gates",[])]
    require(len(ids)==len(set(ids)) and set(ids)==EXPECTED,"Publication gates missing or duplicated")
    for g in document["gates"]:
        require(g.get("kind") in ("technical","business","permission"),"Unknown gate type")
        require(g.get("status") in ("pending","not_run","unverified","not_authorized"),"Prematurely verified release gate: "+g["id"])
        require(len(g.get("proof") or "")>=18,"Missing actionable blocker evidence")
        require("password" not in g.get("proof","").lower(),"Do not embed passwords or credentials")
    require(acceptance.get("release_eligible") is False,"Product acceptance audit was improperly promoted")
    summary=acceptance["summary"]
    require(summary["traceability_rows"]==168 and summary["screen_contracts"]==14,
            "Acceptance ID scope changed; revise runbook")
    require(summary["planned_traceability_rows"]>0 or summary["unverified_feature_ids"],
            "Technical blockers changed; refresh publication ledger")
    require(document["official_sources"] and all(str(s).startswith("https://") for s in document["official_sources"]),
            "Official source verification missing")
    return {"schema_version":1,"release_ready":False,"may_submit":False,"owner_approval":"NOT AUTHORIZED",
            "unresolved_count":len(ids),"categories":{k:sum(x["kind"]==k for x in document["gates"]) for k in ("technical","business","permission")},
            "source_acceptance_rows":summary["traceability_rows"],
            "source_planned_rows":summary["planned_traceability_rows"],
            "source_unverified_feature_ids":summary["unverified_feature_ids"],
            "evidence":"Real source audit, publication ledger, explicit unverified external/permission gates",
            "no_credentials_collected":True}
def negative_tests(document,acceptance):
    for key,value in (("release_ready",True),("public_release_authorized",True),("owner_approval","APPROVED")):
        fixture=json.loads(json.dumps(document));fixture[key]=value
        try:verify(fixture,acceptance)
        except SystemExit:pass
        else:raise SystemExit("CRITICAL: fabricated publication authorization accepted: "+key)
    fixture=json.loads(json.dumps(document));fixture["gates"][0]["status"]="verified"
    try:verify(fixture,acceptance)
    except SystemExit:pass
    else:raise SystemExit("CRITICAL: unverified release gate accepted")
    print("PASS P9-T008 denied fabricated approval/ready/verified statuses")
def main():
    ap=argparse.ArgumentParser()
    ap.add_argument("--negative-tests",action="store_true")
    args=ap.parse_args()
    d=json.loads(INVENTORY.read_text())
    a=json.loads((ROOT/"dist/p9-acceptance-audit.json").read_text())
    r=verify(d,a)
    if args.negative_tests:negative_tests(d,a)
    out=ROOT/"dist/p9-publication-preflight.json"
    out.write_text(json.dumps(r,indent=2)+"\n")
    print(f"PASS P9-T008 {r['unresolved_count']} distinct publication gates classified, no permission to publish")
if __name__=="__main__":
    main()
