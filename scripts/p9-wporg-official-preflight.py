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

def process_result(args):
    raw=(DIST/"p9-wporg-plugin-check-results.json").read_text()
    stderr=(DIST/"p9-wporg-plugin-check-stderr.txt").read_text()
    parsed=None
    try:
        parsed=json.loads(raw)
    except ValueError:
        pass
    if not isinstance(parsed,(list,dict)):
        pcp_state="INVALID_OR_NON_JSON"
        result_count=None
        results=[]
    else:
        results=parsed if isinstance(parsed,list) else parsed.get("results",[])
        if not isinstance(results,list):
            results=[]
        result_count=len(results)
        pcp_state="PASSED_WITH_NO_FINDINGS" if args.plugin_check_exit==0 and result_count==0 else "FINDINGS_OR_FAILED"
    html_path=DIST/"p9-wporg-official-readme-response.html"
    html=html_path.read_text() if html_path.is_file() else ""
    if args.readme_curl_exit!=0 or args.readme_http_status!="200":
        online="UNVERIFIED_NETWORK_OR_HTTP"
    else:
        successful=bool(re.search(r'class=["\'][^"\']*notice-success[^"\']*["\'][^>]*>.*?Congratulations! No errors found\.',html,re.I|re.S))
        fatal=bool(re.search(r'Fatal Errors:|class=["\']errors error["\']',html,re.I))
        if successful and not fatal:
            online="PASSED_OFFICIAL_FORM_RESPONSE"
        elif fatal:
            online="FATAL_ERRORS_OFFICIAL_FORM_RESPONSE"
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
        "plugin_check_raw_sha256":sha(raw.encode()),
        "plugin_check_stderr_sha256":sha(stderr.encode()),
        "official_readme_url":OFFICIAL_URL,
        "official_readme_form_post_attempted":True,
        "official_readme_http_status":args.readme_http_status,
        "official_readme_curl_exit":args.readme_curl_exit,
        "official_readme_response":online,
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
        print("RECORDED actual WP.org Plugin Check:",pcp_state,"exit",args.plugin_check_exit,"findings",result_count)
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
    a=p.parse_args()
    if a.svn_dry_run:
        svn_dry_run()
    else:
        require(a.plugin_check_exit is not None and a.readme_curl_exit is not None,
                "Missing actual external command return codes")
        process_result(a)
if __name__=="__main__":
    main()
