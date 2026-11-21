#!/usr/bin/env python3
"""Fail-closed validation of executable WordPress Playground Free/Pro bundles.

The bundled plugin ZIP bytes must exactly match the independent production ZIPs.
Never claim this byte-validation itself proves live Blueprint execution.
"""
import argparse
import hashlib
from io import BytesIO
import json
from pathlib import Path, PurePosixPath
from zipfile import ZipFile

ROOT = Path(__file__).resolve().parents[1]
SLUG = "wpessential-bank-transfer-matcher"
EXPECTED = {
    "base": (),
    "free": (SLUG + ".zip",),
    "pro": (SLUG + ".zip", SLUG + "-pro.zip"),
}

def require(value, message):
    if not value:
        raise SystemExit(message)

def sha256(data):
    return hashlib.sha256(data).hexdigest()

def verify_bundle(edition, archive_bytes=None, root=ROOT):
    require(edition in EXPECTED, "Unknown Playground edition")
    dist = root / "dist"
    raw = archive_bytes if archive_bytes is not None else (dist / f"playground-{edition}.zip").read_bytes()
    bp_source = json.loads((root / "playground/blueprints" / edition / "blueprint.json").read_text())
    with ZipFile(BytesIO(raw)) as z:
        members = z.namelist()
        expected = {"blueprint.json", *EXPECTED[edition]}
        require(len(members) == len(set(members)), "Duplicate bundle ZIP member")
        require(set(members) == expected, "Unexpected or missing Playground resource")
        for name in members:
            pure = PurePosixPath(name)
            require(bool(pure.parts) and len(pure.parts) == 1 and not name.startswith(".") and ".." not in pure.parts and "\\" not in name, "Unsafe bundle resource path")
        require(z.testzip() is None, "Bundle CRC failed")
        bp_actual = json.loads(z.read("blueprint.json"))
        require(bp_actual == bp_source, "Packaged Blueprint differs from source")
        steps = bp_actual.get("steps")
        require(isinstance(steps, list), "Missing Blueprint steps")
        installed = [s for s in steps if s.get("step") == "installPlugin"]
        require(bool(installed) and installed[0].get("pluginData", {}).get("slug") == "woocommerce", "Missing native WooCommerce install step")
        resources = tuple(s["pluginData"]["path"].lstrip("/") for s in installed if s.get("pluginData", {}).get("resource") == "bundled")
        require(resources == EXPECTED[edition], "Incorrect plugin installation order or edition")
        require(all(s.get("options", {}).get("activate") is True for s in installed), "Plugin not activated in Blueprint")
        deps = []
        for plugin in resources:
            actual = z.read(plugin)
            expected_bytes = (dist / plugin).read_bytes()
            require(actual == expected_bytes, "Bundled plugin ZIP differs from exact production archive: " + plugin)
            deps.append({"file": plugin, "sha256": sha256(actual), "bytes": len(actual)})
    return {"edition": edition, "file": f"playground-{edition}.zip", "sha256": sha256(raw),
            "bytes": len(raw), "embedded_production_archives": deps,
            "built_from_canonical_blueprint": True, "executed": False}

def negative_tests():
    original = (ROOT / "dist/playground-free.zip").read_bytes()
    with ZipFile(BytesIO(original)) as z:
        # Keep a formally valid ZIP while altering the nested Free plugin bytes.
        output = BytesIO()
        with ZipFile(output, "w") as mutated:
            for member in z.infolist():
                content = z.read(member.filename)
                if member.filename == SLUG + ".zip":
                    content += b"tampered"
                mutated.writestr(member.filename, content)
    try:
        verify_bundle("free", output.getvalue())
    except SystemExit as error:
        require("differs from exact production archive" in str(error), "Unexpected tamper rejection")
    else:
        raise SystemExit("CRITICAL: tampered plugin bytes accepted")
    print("PASS P9-T005 negative tampered ZIP rejected")

def main():
    p = argparse.ArgumentParser()
    p.add_argument("--output", default="dist/p9-playground-bundle-manifest.json")
    p.add_argument("--negative-tests", action="store_true")
    args = p.parse_args()
    rows = [verify_bundle(edition) for edition in EXPECTED]
    if args.negative_tests:
        negative_tests()
    report = {"schema_version": 1, "scope": "P9-T005 exact built Playground bundles",
              "run_required_for_live_execution": True,
              "synthetic_only": True, "release_published": False, "bundles": rows}
    output = ROOT / args.output
    require(output.resolve().is_relative_to((ROOT / "dist").resolve()), "Manifest must stay within dist")
    output.write_text(json.dumps(report, indent=2) + "\n")
    print("PASS P9-T005 base/free/pro Blueprint source, resource isolation and SHA256 parity; actual Playground runtime verified by separate test-playground-foundation.py")

if __name__ == "__main__":
    main()
