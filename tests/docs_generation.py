"""Exercise the readme gate with isolated source and output mutations."""

import json
import shutil
import subprocess
from pathlib import Path
from tempfile import TemporaryDirectory

root = Path(__file__).resolve().parents[1]

with TemporaryDirectory(prefix='wbm-docs-') as temp:
    fixture = Path(temp)
    for path in ('docs', 'scripts', '.ai', 'plugins', 'playground/evidence'):
        shutil.copytree(root / path, fixture / path, ignore=shutil.ignore_patterns('vendor', '__pycache__'))
    shutil.copy(root / 'README.md', fixture / 'README.md')

    def assert_rejected(file: Path, replacement: str, expected: str) -> None:
        original = file.read_text()
        file.write_text(replacement)
        result = subprocess.run(
            ['node', 'scripts/generate-readmes.ts', '--check'],
            cwd=fixture, capture_output=True, text=True,
        )
        file.write_text(original)
        if not result.returncode or expected not in result.stderr:
            raise AssertionError(result.stderr or f'Expected rejection: {expected}')

    free = fixture / 'plugins/wpessential-bank-transfer-matcher'
    readme = free / 'readme.txt'
    catalog = fixture / 'docs/source/feature-catalog.json'
    features = json.loads(catalog.read_text())
    copy = readme.read_text()
    for feature in features:
        should_show = feature['edition'] == 'free' and feature['status'] in ('verified', 'released')
        if (feature['summary'] in copy) != should_show:
            raise AssertionError(f'Unexpected Free copy for {feature["id"]}')
    print('PASS planned copy excluded and Free/Pro copy isolated')

    assert_rejected(readme, copy + '\nDrift\n', 'Generated documentation drift')
    header = free / 'wpessential-bank-transfer-matcher.php'
    assert_rejected(header, header.read_text().replace('* Version: ', '* Version: 99', 1), 'Plugin header metadata drift')
    future = next((feature for feature in features if feature['status'] == 'planned'), features[0])
    future['status'] = 'verified'
    future.pop('evidence', None)
    assert_rejected(catalog, json.dumps(features), 'Missing verified evidence')
    print('PASS generated-file drift, plugin header drift, and unverified feature promotion rejected')

    # The structural validator must also reject source-mismatched readme
    # content, not just the generator's equality comparison.
    def assert_offline_rejected(copy_with_defect: str, expected: str) -> None:
        readme.write_text(copy_with_defect)
        result = subprocess.run(
            ['python3', 'scripts/validate-wporg-readme.py'],
            cwd=fixture, capture_output=True, text=True,
        )
        readme.write_text(copy)
        if result.returncode == 0 or expected not in result.stderr:
            raise AssertionError(result.stderr or f'Expected structural rejection: {expected}')

    assert_offline_rejected(
        copy.replace('Stable tag: 0.1.0', 'Stable tag: 9.9.9'),
        'Stable tag'
    )
    assert_offline_rejected(
        copy.replace('== Changelog ==', '== Release notes =='),
        'Missing readme section'
    )
    assert_offline_rejected(
        copy.replace('== Installation ==',
                     '- Import and validate bank statement rows.\n\n== Installation =='),
        'unverified or Pro-only feature'
    )
    assert_offline_rejected(
        copy.replace('Tags: woocommerce, ', 'Tags: woocommerce, woocommerce, ', 1),
        'Tags'
    )
    print('PASS offline WordPress readme tag, verified Free scope, stable tag and section mutation denials')
