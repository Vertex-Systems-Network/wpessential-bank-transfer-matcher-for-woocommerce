#!/usr/bin/env python3
"""Verify actual Free/Pro release ZIP bytes and generate a factual dependency/license report."""
import argparse
import hashlib
import json
from pathlib import Path, PurePosixPath
from zipfile import ZipFile

ROOT = Path(__file__).resolve().parents[1]
SLUGS = {
    "free": "wpessential-bank-transfer-matcher",
    "pro": "wpessential-bank-transfer-matcher-pro",
}
def require(condition, reason):
    """Always-on ZIP safety invariant (not disabled by python -O)."""
    if not condition:
        raise SystemExit(reason)

def validate_archive(edition):
    slug = SLUGS[edition]
    plugin = ROOT / "plugins" / slug
    package = ROOT / "dist" / (slug + ".zip")
    require(package.is_file(), "Missing package ZIP: " + str(package))
    composer = json.loads((plugin / "composer.json").read_text())
    lock = json.loads((plugin / "composer.lock").read_text())
    required = set(json.loads((plugin / "build/package-manifest.json").read_text())["required"])
    require(composer["license"] == "GPL-2.0-or-later", "Composer SPDX license mismatch")
    require(bool(composer["autoload"]["psr-4"]), "Empty Composer PSR-4 map")
    require(not lock.get("packages-dev"), "Production package must not lock dev dependencies")
    require(isinstance(lock["packages"], list), "Invalid Composer production lock packages")
    with ZipFile(package) as z:
        names = z.namelist()
        require(len(names) == len(set(names)), "Duplicate ZIP paths")
        prefix = slug + "/"
        namespaced = {}
        for info in z.infolist():
            name = info.filename
            require(name.startswith(prefix), "Wrong package root")
            relative = PurePosixPath(name[len(prefix):])
            require(bool(relative.parts) and ".." not in relative.parts and "\\" not in name, "Unsafe archive path")
            require(all(not part.startswith(".") for part in relative.parts), "Hidden release artifact")
            require(info.date_time == (1980,1,1,0,0,0), "Nondeterministic timestamp")
            namespaced[relative.as_posix()] = info
        require(required <= set(namespaced), "Missing manifest member: " + str(required - set(namespaced)))
        require("LICENSE" in namespaced, "Missing LICENSE in release archive")
        license_notice = z.read(prefix + "LICENSE").decode("utf-8")
        require("SPDX-License-Identifier: GPL-2.0-or-later" in license_notice, "Missing GPL-2.0-or-later SPDX notice")
        require("WITHOUT" in license_notice and "ANY WARRANTY" in license_notice, "Missing GPL warranty disclosure")
        plugin_header = z.read(prefix + slug + ".php").decode("utf-8")
        require("License: GPL-2.0-or-later" in plugin_header, "Plugin header license mismatch")
        require("vendor/autoload.php" in namespaced, "Missing Composer autoloader")
        require("vendor/composer/autoload_classmap.php" in namespaced, "Missing optimized Composer classmap")
        require("vendor/composer/autoload_psr4.php" in namespaced, "Missing Composer PSR-4 loader")
        require("vendor/composer/installed.json" in namespaced, "Missing Composer installed manifest")
        installed = json.loads(z.read(prefix + "vendor/composer/installed.json"))
        actual = {(row["name"], row["version"]) for row in installed["packages"]}
        expected = {(row["name"], row["version"]) for row in lock["packages"]}
        require(installed.get("dev") is False and actual == expected, "Composer lock/install drift")
        require(z.testzip() is None, "Broken ZIP CRC")
        # No Pro implementation should leak into the WordPress.org Free archive.
        if edition == "free":
            require(not any("bank-transfer-matcher-pro" in n or "/src/Pro/" in n for n in names), "Pro source leaked into Free archive")
        for row in lock["packages"]:
            require(bool(row.get("license")), "Missing locked vendor license metadata")
            require(row["name"] not in {"wpessential/bank-transfer-matcher", "wpessential/bank-transfer-matcher-pro"}, "Circular Composer package dependency")
        # Composer vendor packages are intentionally zero in this release. Any future
        # third-party package must receive separate provenance/license and audit review.
        if lock["packages"]:
            raise ValueError("P9 requires explicit manual third-party vendor license review before packaging")
    return {
        "edition": edition, "slug": slug,
        "sha256": hashlib.sha256(package.read_bytes()).hexdigest(),
        "bytes": package.stat().st_size,
        "license": composer["license"],
        "runtime_composer_packages": [],
        "composer_audit": "not_applicable_no_locked_runtime_packages",
        "optimized_composer_classmap": True,
        "no_dev_dependencies": True,
        "clean_install_evidence": "separate WordPress/MariaDB CI required; not proven by archive scan",
    }

def main():
    p = argparse.ArgumentParser()
    p.add_argument("--output", default="")
    a = p.parse_args()
    report = {
        "schema_version": 1, "scope": "P9-T004 development archive integrity",
        "no_marketplace_approval": True, "no_public_release": True,
        "editions": [validate_archive("free"), validate_archive("pro")],
    }
    if a.output:
        out = Path(a.output)
        out.parent.mkdir(parents=True, exist_ok=True)
        out.write_text(json.dumps(report, indent=2) + "\n", encoding="utf-8")
    print("PASS P9-T004 two edition-isolated deterministic-timestamp GPL/optimized-Composer ZIPs; zero locked third-party packages; no public release")

if __name__ == "__main__":
    main()
