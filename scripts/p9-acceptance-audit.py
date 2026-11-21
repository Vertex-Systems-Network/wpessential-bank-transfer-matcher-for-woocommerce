#!/usr/bin/env python3
"""Audit feature, screen, task verification and requirement traceability without inventing acceptance."""
import argparse
import csv
import json
from pathlib import Path, PurePosixPath
import re

ROOT = Path(__file__).resolve().parents[1]
SCREEN_IDS = [f"UI-{i:03d}" for i in range(1, 15)]

def require(value, reason):
    if not value:
        raise SystemExit(reason)

def valid_repo_file(path):
    require(isinstance(path,str) and path and "\\" not in path, "Invalid evidence source path")
    parts = PurePosixPath(path).parts
    require(parts and not path.startswith("/") and ".." not in parts and path not in (".", ".."), "Unsafe source path")
    candidate = ROOT / path
    require(candidate.is_file(), "Evidence file not found: " + path)
    return candidate

def verify_release_claim(data):
    s=data["summary"]
    if s["unverified_feature_ids"] or s["planned_traceability_rows"] or s["missing_test_evidence"]:
        require(data["release_eligible"] is False, "Release approval claim hides unresolved evidence")
    require(data["official_qit"] == "NOT RUN", "Do not promote managed Woo QIT without actual reports")

def make_report():
    features=json.loads(valid_repo_file("docs/source/feature-catalog.json").read_text())
    tasks=json.loads(valid_repo_file(".ai/tasks.json").read_text())
    release=json.loads(valid_repo_file("dist/p9-validation-report.json").read_text())
    require(release["releasable"] is False, "Source release report prematurely marked releasable")
    require(release["status"] == "development_candidate", "Unsupported source release status")
    by_verify={}
    for task in tasks:
        key=task["verification"]
        require(key not in by_verify, "Duplicate verification task id: " + key)
        by_verify[key]=task
    feat_ids=set()
    enriched=[]
    for feat in features:
        fid=feat["id"]
        require(fid not in feat_ids and fid.startswith("F-"), "Invalid/duplicate feature ID")
        feat_ids.add(fid)
        v=feat["test_id"]
        require(v in by_verify, "Missing source task for feature test: " + fid)
        task=by_verify[v]
        require(feat["status"] in {"verified","planned"}, "Unknown feature acceptance state")
        if feat["status"]=="verified":
            valid_repo_file(feat["evidence"])
            require(task["status"] in {"done","verified"} and bool(task.get("evidence")),
                    "Verified feature lacks completed source task evidence: " + fid)
        else:
            require(not feat.get("evidence"), "Planned feature must not present accepted evidence")
        enriched.append({
            "feature_id": fid, "edition": feat["edition"], "catalog_status": feat["status"],
            "verification_id": v, "source_task": task["id"],
            "source_task_status": task["status"],
            "evidence": feat.get("evidence") or None,
        })
    screen_contract=valid_repo_file("docs/ux/p7-source-backed-screen-contracts.md").read_text()
    screens=["UI-"+n for n in re.findall(r"^## UI-(\d{3}) —",screen_contract,flags=re.MULTILINE)]
    require(screens == SCREEN_IDS, "UI-001 through UI-014 must have distinct ordered source contracts")
    with valid_repo_file("docs/testing/traceability.csv").open(newline="") as source:
        rows=list(csv.DictReader(source))
    require(bool(rows), "Empty traceability data")
    keys=set()
    ui_in_requirements=set()
    trace=[]
    for row in rows:
        key=row.get("Requirement ID")
        require(bool(key) and key not in keys, "Duplicate/missing requirement ID")
        keys.add(key)
        ui=row.get("Screen ID") or ""
        if ui:
            require(ui in SCREEN_IDS, "Unrecognized screen ID in requirement: " + key)
            ui_in_requirements.add(ui)
        documentation=row.get("Documentation") or ""
        require(":" in documentation, "Requirement missing source line: " + key)
        path,line=documentation.rsplit(":",1)
        doc=valid_repo_file(path)
        require(line.isdigit() and 0<int(line)<=len(doc.read_text().splitlines()),
                "Unresolved requirement source location: " + key)
        status=row.get("Status") or "unknown"
        require(status in {"planned","in_progress","verified","done","blocked"},
                "Unsupported requirement acceptance status: " + key)
        trace.append({"requirement_id":key,"screen_id":ui or None,
                      "status":status,"source":documentation,
                      "e2e_test_ref":row.get("E2E Test") or None})
    require(ui_in_requirements == set(SCREEN_IDS), "One or more UI screens lack traceability")
    require(len(tasks)==103, "Task graph count changed; revisit acceptance criteria")
    task_evidence_missing=[t["id"] for t in tasks if t["status"] in {"done","verified"} and not t.get("evidence")]
    unverified=[x["feature_id"] for x in enriched if x["catalog_status"]!="verified"]
    planned=[x for x in trace if x["status"] not in {"verified","done"}]
    report={
        "schema_version":1,"scope":"P9-T007 source-backed acceptance AUDIT, not product approval",
        "release_eligible":False,"official_qit":"NOT RUN",
        "files_checked":[ "docs/source/feature-catalog.json","docs/ux/p7-source-backed-screen-contracts.md",
                         "docs/testing/traceability.csv",".ai/tasks.json","dist/p9-validation-report.json"],
        "summary":{
            "catalog_features":len(enriched),"verified_feature_ids":len(enriched)-len(unverified),
            "unverified_feature_ids":unverified,
            "screen_contracts":len(screens),"traceability_rows":len(trace),
            "planned_traceability_rows":len(planned),
            "missing_test_evidence":task_evidence_missing,
            "task_graph_count":len(tasks),
            "note":"Planned traceability rows mean unverified documentation annotations, not automatically missing software implementations.",
        },
        "features":enriched,"screen_ids":screens,"traceability":trace,
        "blockers":[
            "Reconcile planned feature and 168 planned requirement statuses against real CI/browser/integration proof; do not bulk-promote.",
            "Verify full feature/screen/action acceptance against actual per-ID tests and current runtime versions.",
            "Official Woo QIT, WordPress.org Plugin Check and marketplace approvals are separate later gates.",
        ],
    }
    verify_release_claim(report)
    return report

def negative_tests(report):
    fake=json.loads(json.dumps(report))
    fake["release_eligible"]=True
    try:
        verify_release_claim(fake)
    except SystemExit as exc:
        require("unresolved evidence" in str(exc), "Incorrect false-release-denial reason")
    else:
        raise SystemExit("CRITICAL: unsupported release approval accepted")
    try:
        valid_repo_file("../outside")
    except SystemExit as exc:
        require("Unsafe source path" in str(exc), "Incorrect traversal denial reason")
    else:
        raise SystemExit("CRITICAL: unsafe evidence path accepted")
    print("PASS P9-T007 negative false approval and traversal rejection")

def main():
    p=argparse.ArgumentParser()
    p.add_argument("--negative-tests",action="store_true")
    args=p.parse_args()
    result=make_report()
    if args.negative_tests:
        negative_tests(result)
    output=ROOT/"dist/p9-acceptance-audit.json"
    output.write_text(json.dumps(result,indent=2)+"\n",encoding="utf-8")
    print("PASS P9-T007 audited",result["summary"]["catalog_features"],"catalog features,",
          result["summary"]["screen_contracts"],"screens,",
          result["summary"]["traceability_rows"],"requirement rows and task verification IDs.",
          "Release eligible:",result["release_eligible"])

if __name__=="__main__":
    main()
