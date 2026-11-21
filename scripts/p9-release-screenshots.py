#!/usr/bin/env python3
"""Produce byte-identical Free/Pro screenshot candidates from genuine browser CI evidence."""
import argparse
import hashlib
import json
from pathlib import Path
import struct
import zipfile

CAPTURES = {
    "free": [
        ("screenshot-1.png", "ltr-UI-001-free-overview-1440.png", "BACS overview"),
        ("screenshot-2.png", "ltr-UI-002-free-orders-on-hold-1440.png", "Outstanding BACS orders"),
        ("screenshot-3.png", "ltr-UI-006-bacs-customer-received-1440.png", "Unpaid customer reference"),
        ("screenshot-4.png", "ltr-UI-005-manual-reconciliation-1440.png", "Manual bank evidence review"),
        ("screenshot-5.png", "ltr-UI-013-free-settings-1440.png", "Free settings"),
    ],
    "pro": [
        ("screenshot-1.png", "ltr-UI-001-pro-finance-dashboard-1440.png", "Finance dashboard"),
        ("screenshot-2.png", "ltr-UI-007-pro-statement-import-1440.png", "CSV source import"),
        ("screenshot-3.png", "ltr-UI-009-pro-review-queue-1440.png", "Review queue"),
        ("screenshot-4.png", "ltr-UI-010-pro-finance-allocations-1440.png", "Allocation review"),
        ("screenshot-5.png", "ltr-UI-011-pro-finance-reports-1440.png", "Financial reports"),
        ("screenshot-6.png", "ltr-UI-013-pro-mapping-profiles-1440.png", "Statement mappings"),
    ],
    "qa": [
        ("rtl-import-1440.png", "rtl-UI-007-pro-statement-import-1440.png", "Arabic RTL import QA"),
        ("mobile-review-375.png", "ltr-UI-009-pro-review-queue-375.png", "375px mobile review QA"),
    ],
}
REPORTS = (
    "p7-report-ltr.json", "p7-report-rtl.json",
    "p7-navigation-ltr.json", "p7-navigation-rtl.json",
    "p7-usability-ltr.json", "p7-usability-rtl.json",
)
def prepare(source, output, sha, run_id):
    source, output = Path(source), Path(output)
    z = zipfile.ZipFile(source) if source.is_file() else None
    def read(name):
        if "/" in name or "\\" in name or name in (".", ".."):
            raise ValueError("Unsafe browser file name")
        return z.read(name) if z is not None else (source / name).read_bytes()
    try:
        known = {}
        for name in REPORTS:
            report = json.loads(read(name))
            if report.get("disposable") is not True or report.get("issues") != [] or report.get("no_financial_mutations", True) is not True:
                raise ValueError("Failed/missing real browser evidence: " + name)
            if name.startswith("p7-report-"):
                for screen in report.get("screens", []):
                    known[screen["screenshot"]] = (screen["width"], screen["mode"])
        selected = []
        for edition, choices in CAPTURES.items():
            for target, source_name, caption in choices:
                if source_name not in known:
                    raise ValueError("Missing verified browser capture: " + source_name)
                data = read(source_name)
                if not data.startswith(b"\x89PNG\r\n\x1a\n") or data[12:16] != b"IHDR":
                    raise ValueError("Not a PNG: " + source_name)
                width, height = struct.unpack(">II", data[16:24])
                expected_width = 375 if source_name.endswith("-375.png") else 1440
                direction = "rtl" if source_name.startswith("rtl-") else "ltr"
                if width != expected_width or height < 350 or known[source_name] != (width, direction):
                    raise ValueError("Unverified image geometry/direction: " + source_name)
                selected.append((edition, target, source_name, caption, width, height, data))
        if len(selected) != 13:
            raise ValueError("Incorrect Free/Pro/QA capture count")
        manifest = {
            "schema_version": 1, "source_commit": sha, "source_run_id": str(run_id),
            "browser_artifact": "p7-real-wordpress-visual-evidence",
            "report_files_checked": list(REPORTS), "synthetic_only": True,
            "financial_mutations": False, "pixel_edits": False,
            "marketplace_approval": False, "screenshots": [],
        }
        for edition, name, source_name, caption, width, height, data in selected:
            out = output / edition / name
            out.parent.mkdir(parents=True, exist_ok=True)
            out.write_bytes(data)
            manifest["screenshots"].append({
                "edition": edition, "filename": str(out.relative_to(output)), "source": source_name,
                "caption": caption, "width": width, "height": height,
                "sha256": hashlib.sha256(data).hexdigest(),
            })
        (output / "evidence-manifest.json").write_text(json.dumps(manifest, indent=2) + "\n", encoding="utf-8")
        return manifest
    finally:
        if z is not None: z.close()
if __name__ == "__main__":
    parser=argparse.ArgumentParser()
    parser.add_argument("--source", required=True)
    parser.add_argument("--output", required=True)
    parser.add_argument("--source-sha", required=True)
    parser.add_argument("--run-id", required=True)
    a=parser.parse_args()
    manifest=prepare(a.source,a.output,a.source_sha,a.run_id)
    print("PASS source-verified",len(manifest["screenshots"]),"Free/Pro/RTL/mobile browser screenshots, immutable PNG bytes")
