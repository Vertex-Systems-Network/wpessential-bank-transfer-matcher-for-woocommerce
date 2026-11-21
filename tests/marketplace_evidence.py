"""P9-T002: fail closed on invented QIT passes or broken reviewer evidence."""
import copy
import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PATH = ROOT / "docs/submission/woocommerce-marketplace/p9-t002-qit-matrix.json"
REVIEW = ROOT / "docs/submission/woocommerce-marketplace/p9-t002-reviewer-preflight.md"
SUITES = {"activation", "woo_api", "end_to_end", "security", "malware", "php_compatibility", "validation"}
VALID = {"not_run", "blocked", "passed", "failed"}

def validate(data):
    assert data["schema_version"] == 1
    assert data["phase"] == "P9-T002"
    assert data["edition"] == "wpessential-bank-transfer-matcher-pro"
    assert data["official_vendor_approval"] in {"not_verified", "approved"}
    rows = data["suites"]
    assert isinstance(rows, list) and {r["id"] for r in rows} == SUITES
    assert len(rows) == len(SUITES), "Duplicate QIT suite IDs"
    for row in rows:
        assert row["official_qit_status"] in VALID
        assert row["remaining_gate"] == "WBM-P9-T010"
        evidence = row["native_evidence"]
        assert isinstance(evidence, str) and evidence.startswith("docs/") and ".." not in Path(evidence).parts
        assert (ROOT / evidence).is_file(), evidence
        if row["official_qit_status"] == "passed":
            assert isinstance(row["official_run_id"], (int, str)) and str(row["official_run_id"]).strip()
            assert isinstance(row["official_report_url"], str) and row["official_report_url"].startswith("https://")
        else:
            assert row["official_run_id"] is None and row["official_report_url"] is None, "Cannot present unverifiable QIT run details"
    assert REVIEW.is_file()
    txt = REVIEW.read_text(encoding="utf-8")
    for token in ["NOT RUN", "NOT VERIFIED", "NOT AUTHORIZED", "p9-t002-qit-matrix.json", "P9-T010"]:
        assert token in txt, token

def expect_bad(data):
    try:
        validate(data)
    except (AssertionError, KeyError, TypeError):
        return
    raise AssertionError("Invalid QIT assertion was accepted")

def main():
    data = json.loads(PATH.read_text(encoding="utf-8"))
    validate(data)
    mutant = copy.deepcopy(data); mutant["suites"][0]["official_qit_status"] = "passed"; expect_bad(mutant)
    mutant = copy.deepcopy(data); mutant["suites"][0]["native_evidence"] = "../outside"; expect_bad(mutant)
    mutant = copy.deepcopy(data); mutant["suites"].append(copy.deepcopy(mutant["suites"][0])); expect_bad(mutant)
    mutant = copy.deepcopy(data); mutant["suites"][0]["official_run_id"] = "fake"; expect_bad(mutant)
    print("PASS P9-T002 reviewer matrix structural/source audit and 4 negative mutation tests; official QIT NOT RUN")

if __name__ == "__main__":
    main()
