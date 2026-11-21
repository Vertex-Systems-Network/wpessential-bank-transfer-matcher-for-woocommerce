#!/usr/bin/env python3
"""Parse genuine WordPress.org validator and Plugin Check evidence; never publish."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
from zipfile import ZipFile

ROOT=Path(__file__).resolve().parents[1]
DIST=ROOT/"dist"
SLUG="wpessential-bank-transfer-matcher"
OFFICIAL_URL="https://wordpress.org/plugins/developers/readme-validator/"

def require(value,message):
    if not value:
        raise SystemExit(message)

def sha(data):
    return hashlib.sha256(data).hexdigest()

def parse_official_plugin_check(raw):
    """WP Plugin Check 2.x --format=json emits FILE: path plus a JSON list per file."""
    headers=list(re.finditer(r"(?m)^FILE: ([^\r\n]+)\r?\n",raw))
    if headers:
        require(not raw[:headers[0].start()].strip(),"Unexpected preamble in official plugin scanner output")
        findings=[]
        for idx, match in enumerate(headers):
            segment=raw[match.end():headers[idx+1].start() if idx+1<len(headers) else len(raw)].strip()
            rows=json.loads(segment)
            require(isinstance(rows,list),"Non-list JSON result in official Plugin Check block")
            for row in rows:
                require(isinstance(row,dict) and row.get("type") in ("ERROR","WARNING","INFO"),"Unknown official finding record")
                findings.append({**row,"file":match.group(1)})
        return findings
    result=json.loads(raw)
    if isinstance(result,dict):
        result=result.get("results")
    require(isinstance(result,list),"Plugin Check JSON format changed")
    require(all(isinstance(row,dict) for row in result),"Plugin Check record not an object")
    return result

def self_test():
    fixture='FILE: src/A.php\n[{"type":"ERROR","code":"critical"}]\nFILE: readme.txt\n[{"type":"WARNING","code":"readme"}]\n'
    results=parse_official_plugin_check(fixture)
    require(len(results)==2 and results[0]["file"]=="src/A.php" and results[0]["type"]=="ERROR",
            "Plugin Check file-delimited output not properly parsed")
    require(results[1]["type"]=="WARNING","Plugin Check warning count mismatch")
    try:
        parse_official_plugin_check("FILE: unsafe.php\nNOT_JSON\n")
    except (ValueError,SystemExit):
        pass
    else:
        raise SystemExit("CRITICAL: malformed Plugin Check output accepted")
    print("PASS official Plugin Check 2.x FILE: per-file JSON findings and malformed output rejection")

def process_result(args):
    raw=(DIST/"p9-wporg-plugin-check-results.json").read_text()
    stderr=(DIST/"p9-wporg-plugin-check-stderr.txt").read_text()
    try:
        findings=parse_official_plugin_check(raw)
    except (ValueError,SystemExit) as exc:
        findings=None
        parse_error=str(exc)
    else:
        parse_error=None
    if findings is None:
        pcp_state="INVALID_OR_NON_JSON"
        result_count=None
        error_count=None
        warning_count=None
        sample=[]
    else:
        result_count=len(findings)
        error_count=sum(x.get("type")=="ERROR" for x in findings)
        warning_count=sum(x.get("type")=="WARNING" for x in findings)
        sample=[{"file":x.get("file"),"line":x.get("line"),"code":x.get("code"),"type":x.get("type")}
                for x in findings if x.get("type")=="ERROR"]
        if error_count:
            pcp_state="BLOCKED_ERRORS"
        elif args.plugin_check_exit != 0:
            pcp_state="CHECK_FAILED_EXIT"
        elif warning_count:
            pcp_state="WARNINGS_REQUIRE_REVIEW"
        else:
            pcp_state="PASSED_WITH_NO_FINDINGS"
    html_path=DIST/"p9-wporg-official-readme-response.html"
    html=html_path.read_text() if html_path.is_file() else ""
    readme_notes_count=0
    if args.readme_curl_exit!=0 or args.readme_http_status!="200":
        online="UNVERIFIED_NETWORK_OR_HTTP"
    else:
        section=re.search(r'<div class="wrap readme-validator">(.*?)<form method="get"',html,re.I|re.S)
        validator_section=section.group(1) if section else ""
        fatal=bool(re.search(r'Fatal Errors:|notice[- ]error|class=["\']errors error["\']',validator_section,re.I))
        success=bool(re.search(r'class=["\'][^"\']*notice-success[^"\']*["\'][^>]*>.*?Congratulations! No errors found\.',validator_section,re.I|re.S))
        notes=re.search(r'<ul class="notes">(.*?)</ul>',validator_section,re.I|re.S)
        if notes:
            readme_notes_count=len(re.findall(r'<li\b',notes.group(1),re.I))
        # The live 2026 official validator displays a Notes-only result rather
        # than a success banner after a correctly Base64-encoded form submission.
        # Check this is the submitted source, not a blank GET/fallback page.
        posted_source=bool(re.search(r'<textarea[^>]*name="readme_visible"[^>]*>\s*===\s*WPEssential Bank Transfer Matcher',html,re.I)
                           and "Stable tag: 0.1.0" in html and "readme_contents" in html)
        if fatal:
            online="FATAL_ERRORS_OFFICIAL_FORM_RESPONSE"
        elif success:
            online="PASSED_OFFICIAL_FORM_RESPONSE"
        elif notes and posted_source and readme_notes_count:
            online="OFFICIAL_FORM_PROCESSED_WITH_NOTES_NO_FATAL_ERROR"
        else:
            online="UNVERIFIED_UNRECOGNIZED_RESPONSE"
    archive=DIST/(SLUG+".zip")
    require(archive.is_file(),"Missing actual Free plugin ZIP")
    with ZipFile(archive) as z:
        require(z.testzip() is None,"Corrupted Free archive")
        readme=z.read(SLUG+"/readme.txt")
    source=(ROOT/"plugins"/SLUG/"readme.txt").read_bytes()
    require(readme==source,"Built package readme differs from original readme source")
    data={
        "schema_version":1,"scope":"P9-T009 actual WordPress Plugin Check + official public readme form",
        "source_commit":os.getenv("WBM_SOURCE_HEAD_SHA") or "NOT VERIFIED",
        "ci_checkout_sha":os.getenv("GITHUB_SHA") or "NOT VERIFIED",
        "ci_run_id":os.getenv("GITHUB_RUN_ID") or "NOT VERIFIED",
        "actual_plugin_check_installed_version":(DIST/"p9-wporg-plugin-check-version.txt").read_text().strip(),
        "actual_wp_version":(DIST/"p9-wporg-core-version.txt").read_text().strip(),
        "actual_wc_version":(DIST/"p9-wporg-woo-version.txt").read_text().strip(),
        "plugin_check_cli_executed":True,
        "plugin_check_exit":args.plugin_check_exit,
        "plugin_check_output_state":pcp_state,
        "plugin_check_count":result_count,
        "plugin_check_error_count":error_count,
        "plugin_check_warning_count":warning_count,
        "plugin_check_unparsed_reason":parse_error,
        "plugin_check_error_findings":sample,
        "plugin_check_raw_sha256":sha(raw.encode()),
        "plugin_check_stderr_sha256":sha(stderr.encode()),
        "official_readme_url":OFFICIAL_URL,
        "official_readme_form_post_attempted":True,
        "official_readme_http_status":args.readme_http_status,
        "official_readme_curl_exit":args.readme_curl_exit,
        "official_readme_response":online,
        "official_readme_notes_count":readme_notes_count,
        "official_readme_raw_sha256":sha(html.encode()) if html else None,
        "package_readme_sha256":sha(readme),
        "public_submission":"NOT AUTHORIZED",
        "wporg_2fa_account":"NOT VERIFIED",
        "svn_committer_rights":"NOT VERIFIED",
        "release_ready":False,
        "notes":"Genuine scanner findings or network status do not grant publication. This development candidate remains blocked on full release acceptance and owner account approval.",
    }
    (DIST/"p9-wporg-official-check-results.json").write_text(json.dumps(data,indent=2)+"\n")
    if pcp_state=="INVALID_OR_NON_JSON":
        print("BLOCKED: official plugin-check produced unparseable output; external result recorded, no pass claimed")
    else:
        print("RECORDED actual WP.org Plugin Check:",pcp_state,"exit",args.plugin_check_exit,"findings",result_count,"ERROR",error_count,"WARNING",warning_count)
    print("RECORDED official online readme:",online,"HTTP",args.readme_http_status)
    return data

def svn_dry_run():
    meta=json.loads((ROOT/"docs/source/product-metadata.json").read_text())
    version=meta["version"]
    require(bool(re.fullmatch(r"\d+\.\d+\.\d+",version)),"Unverified version string")
    package=DIST/(SLUG+".zip")
    require(package.is_file(),"Missing built Free package")
    root=DIST/"p9-wporg-svn-dry-run"
    if root.exists():
        shutil.rmtree(root)
    dest=[root/"trunk",root/"tags"/version]
    for path in dest+[root/"assets"]:
        path.mkdir(parents=True,exist_ok=True)
    members=[]
    with ZipFile(package) as z:
        for item in z.infolist():
            if item.is_dir(): continue
            name=item.filename
            require(name.startswith(SLUG+"/") and ".." not in Path(name).parts and "\\" not in name,
                    "Unsafe source ZIP file path")
            relative=Path(name[len(SLUG)+1:])
            require(relative.parts and not relative.is_absolute(),"Unsafe WordPress SVN path")
            raw=z.read(item)
            for d in dest:
                target=d/relative;target.parent.mkdir(parents=True,exist_ok=True);target.write_bytes(raw)
            members.append({"file":relative.as_posix(),"sha256":sha(raw)})
    require(any(m["file"]=="readme.txt" for m in members),"Missing Free readme")
    require(not any("matcher-pro" in m["file"] for m in members),"Pro code leaked into WP.org Free SVN dry run")
    report={
        "schema_version":1,"version":version,
        "actual_free_zip_sha256":sha(package.read_bytes()),
        "source_member_count":len(members),
        "trunk_tag_byte_identical":True,
        "assets_present":False,
        "assets_status":"NOT VERIFIED / no publisher screenshots or icon staged",
        "svn_committer_access":"NOT VERIFIED",
        "svn_commit":"NOT EXECUTED",
        "wordpress_org_submission":"NOT EXECUTED",
        "file_manifest":members,
    }
    (DIST/"p9-wporg-svn-dry-run-manifest.json").write_text(json.dumps(report,indent=2)+"\n")
    print("PASS real Free ZIP staged as unpublished trunk/tags",version,"; assets pending; NO SVN COMMIT")

def main():
    p=argparse.ArgumentParser()
    p.add_argument("--plugin-check-exit",type=int)
    p.add_argument("--readme-curl-exit",type=int)
    p.add_argument("--readme-http-status",default="")
    p.add_argument("--svn-dry-run",action="store_true")
    p.add_argument("--self-test",action="store_true")
    a=p.parse_args()
    if a.self_test:
        self_test()
    elif a.svn_dry_run:
        svn_dry_run()
    else:
        require(a.plugin_check_exit is not None and a.readme_curl_exit is not None,
                "Missing actual external command return codes")
        process_result(a)
if __name__=="__main__":
    main()
