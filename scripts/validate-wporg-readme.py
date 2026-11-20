#!/usr/bin/env python3
"""Offline WordPress.org Free readme integrity gate.

This is NOT the official WordPress.org online readme validator and does not
submit or attest to a listing. Canonical metadata and the plugin header
remain authoritative. Run: python3 scripts/validate-wporg-readme.py
"""
from __future__ import annotations

import json
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def fail(reason: str) -> None:
    raise ValueError(reason)


def get_field(text: str, field: str) -> str:
    values = re.findall(rf"^{re.escape(field)}: (.+)$", text, flags=re.MULTILINE)
    if len(values) != 1:
        fail(f"Header {field} must appear exactly once")
    return values[0].strip()


def php_field(text: str, field: str) -> str:
    values = re.findall(rf"^\s*\* {re.escape(field)}: (.+)$", text, flags=re.MULTILINE)
    if len(values) != 1:
        fail(f"Main PHP header {field} missing or duplicated")
    return values[0].strip()


def at_least(actual: str, minimum: str) -> bool:
    if not re.fullmatch(r"\d+(?:\.\d+){1,2}", actual):
        return False
    if not re.fullmatch(r"\d+(?:\.\d+){1,2}", minimum):
        return False
    a = tuple(int(x) for x in actual.split("."))
    b = tuple(int(x) for x in minimum.split("."))
    return a + (0,) * (3 - len(a)) >= b + (0,) * (3 - len(b))


def verify() -> None:
    meta = json.loads((ROOT / "docs/source/product-metadata.json").read_text())
    catalog = json.loads((ROOT / "docs/source/feature-catalog.json").read_text())
    slug = meta["free"]["slug"]
    folder = ROOT / "plugins" / slug
    content = (folder / "readme.txt").read_text(encoding="utf-8")
    plugin = (folder / (slug + ".php")).read_text(encoding="utf-8")
    pro = (ROOT / "plugins" / meta["pro"]["slug"] / "README.md").read_text(
        encoding="utf-8"
    )
    if "\r" in content or "\x00" in content or content.count("\n") < 20:
        fail("WordPress.org readme requires complete UTF-8 Unix text")
    lines = content.splitlines()
    if lines[0] != "=== " + meta["free"]["title"] + " ===":
        fail("Free readme title mismatch")

    first_empty = next((i for i, line in enumerate(lines[1:], 1) if not line.strip()), None)
    if first_empty is None:
        fail("WordPress.org readme header/body separator missing")
    body_lines = []
    for line in lines[first_empty + 1:]:
        if not line.strip():
            break
        body_lines.append(line)
    summary = " ".join(body_lines)
    if not 1 <= len(summary) <= 150:
        fail("WordPress.org short description must contain 1–150 characters")

    expected = {
        "Stable tag": meta["version"],
        "Requires at least": meta["requires_wp"],
        "Tested up to": meta["tested_wp"],
        "Requires PHP": meta["requires_php"],
        "WC requires at least": meta["requires_wc"],
        "WC tested up to": meta["tested_wc"],
        "License": meta["license"],
        "Requires Plugins": ", ".join(meta["free"]["dependencies"]),
    }
    for name, value in expected.items():
        observed = get_field(content, name)
        if observed != value:
            fail(f"{name} mismatch: expected {value}, got {observed}")
    if get_field(content, "Stable tag") != php_field(plugin, "Version"):
        fail("Stable tag mismatch with main Free plugin PHP Version")
    for key in ("Requires at least", "Requires PHP", "WC requires at least"):
        if get_field(content, key) != php_field(plugin, key):
            fail(f"{key} header mismatch between readme and main PHP")
    if not (at_least(meta["tested_wp"], meta["requires_wp"]) and
            at_least(meta["tested_wc"], meta["requires_wc"])):
        fail("Tested versions predate required versions")
    if meta["license"] != "GPL-2.0-or-later":
        fail("WordPress.org readme license is not canonical GPL")

    tags = [x.strip() for x in get_field(content, "Tags").split(",")]
    if not 1 <= len(tags) <= 5 or tags != meta["wordpress_org_tags"] or len(set(tags)) != len(tags):
        fail("Tags must be 1–5 unique canonical factual terms")
    if not all(re.fullmatch(r"[a-z0-9][a-z0-9 -]{1,19}", tag) for tag in tags):
        fail("Tags contain invalid or oversized WordPress.org term")
    usernames = meta["wordpress_org_contributors"]
    found = re.search(r"^Contributors: (.+)$", content, flags=re.MULTILINE)
    if usernames:
        if not found or [x.strip() for x in found.group(1).split(",")] != usernames:
            fail("Contributors do not match verified canonical WordPress.org usernames")
    elif found:
        fail("Do not invent WordPress.org contributors")

    headings = list(re.finditer(r"^== (.+?) ==$", content, flags=re.MULTILINE))
    actual = [match.group(1) for match in headings]
    needed = ["Description", "Installation", "Frequently Asked Questions",
              "Changelog", "Upgrade Notice"]
    if actual != needed and actual != needed[:3] + ["Screenshots"] + needed[3:]:
        fail("Missing readme section, duplicate section or incorrect WordPress.org section order")
    bodies = {}
    for index, match in enumerate(headings):
        end = headings[index + 1].start() if index + 1 < len(headings) else len(content)
        bodies[match.group(1)] = content[match.end():end].strip()
        if not bodies[match.group(1)]:
            fail(f"Empty WordPress.org readme section {match.group(1)}")
    for section in ("Changelog", "Upgrade Notice"):
        if not re.search(rf"^= {re.escape(meta['version'])} =$",
                         bodies[section], flags=re.MULTILINE):
            fail(f"{section} missing matching version heading")
    if meta["status"] == "released":
        fail("Unapproved development readme cannot advertise published release")

    allowed = [x["summary"] for x in catalog
               if x["edition"] == "free" and x["status"] in ("verified", "released")]
    actual_bullets = [line[2:].strip() for line in bodies["Description"].splitlines()
                      if line.startswith("- ")]
    if actual_bullets != allowed:
        fail("Readme contains unverified or Pro-only feature, missing Free feature or changed source copy")
    if any(x["edition"] != "free" and x["summary"] in bodies["Description"]
           for x in catalog):
        fail("Readme contains unverified or Pro-only feature")
    if meta["pro"]["free_api"] not in pro or "Pro requires Free API" not in pro:
        fail("Pro readme missing verified Free API dependency range")

    statuses = {x["id"]: x["status"] for x in json.loads(
        (ROOT / ".ai/tasks.json").read_text(encoding="utf-8")
    )}
    for task in ("WBM-P8-T005", "WBM-P8-T006"):
        if statuses.get(task) not in ("done", "verified"):
            fail("WC tested-version metadata lacks completed native HPOS/checkout evidence")
    for report in ("docs/security/p8-t005-hpos-compatibility.md",
                   "docs/security/p8-t006-checkout-compatibility.md"):
        if not (ROOT / report).is_file():
            fail("WC tested-version metadata lacks source-backed report: " + report)

    print(f"PASS offline Free WordPress.org readme grammar, canonical tags/stable tag, "
          f"{len(allowed)} verified Free features, GPL, WC matrix and Pro Free API constraints")
    print("INFO WordPress.org official online readme validator: NOT VERIFIED (P9-T009).")
    if not usernames:
        print("INFO WordPress.org contributor usernames: NOT VERIFIED / omitted.")


if __name__ == "__main__":
    try:
        verify()
    except (ValueError, KeyError, OSError, json.JSONDecodeError) as exc:
        print("FAIL offline WordPress.org readme: " + str(exc), file=sys.stderr)
        sys.exit(1)
