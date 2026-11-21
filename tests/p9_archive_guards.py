#!/usr/bin/env python3
"""Regression: P9 archive verifier must fail closed even with Python -O."""
import importlib.util
from pathlib import Path
from tempfile import TemporaryDirectory

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location(
    "wbm_archive_guard", ROOT / "scripts/verify-release-archives.py"
)
guard = importlib.util.module_from_spec(spec)
spec.loader.exec_module(guard)

guard.require(True, "sanity")
try:
    guard.require(False, "intentional rejection")
except SystemExit as exc:
    if str(exc) != "intentional rejection":
        raise SystemExit("Guard reported unexpected reason")
else:
    raise SystemExit("CRITICAL: unsafe archive invariant was skipped")

with TemporaryDirectory() as tmp:
    original = guard.ROOT
    guard.ROOT = Path(tmp)
    try:
        guard.validate_archive("free")
    except SystemExit as exc:
        if "Missing package ZIP" not in str(exc):
            raise SystemExit("Wrong missing-archive rejection")
    else:
        raise SystemExit("CRITICAL: missing ZIP allowed in optimized mode")
    finally:
        guard.ROOT = original

print("PASS P9-T004 archive rejection remains active under python -O; no fake ZIP")
