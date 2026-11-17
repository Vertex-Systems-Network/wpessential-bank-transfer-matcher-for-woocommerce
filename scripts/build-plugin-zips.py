"""Package and inspect independently installable production Composer builds."""

import argparse
import json
from pathlib import Path, PurePosixPath
from tempfile import TemporaryDirectory
from zipfile import ZIP_DEFLATED, ZipFile, ZipInfo

ROOT = Path(__file__).resolve().parents[1]
DIST = ROOT / 'dist'


def build(edition: str) -> None:
    slug = 'wpessential-bank-transfer-matcher' + ('-pro' if edition == 'pro' else '')
    plugin = ROOT / 'plugins' / slug
    manifest = json.loads((plugin / 'build/package-manifest.json').read_text())
    if manifest['slug'] != slug:
        raise SystemExit(f'Incorrect package manifest slug for {edition}.')

    locked = json.loads((plugin / 'composer.lock').read_text())['packages']
    required = set(manifest['required'])
    vendor_roots = {'vendor/composer', *(f'vendor/{package["name"]}' for package in locked)}
    source_roots = set(manifest['source_roots'])

    def allowed(relative: PurePosixPath) -> bool:
        name = relative.as_posix()
        if any(part.startswith('.') or part in manifest['forbidden_path_parts'] for part in relative.parts):
            return False
        if relative.suffix in manifest['forbidden_suffixes']:
            return False
        return (name in required or relative.parts[0] in source_roots
                or any(name.startswith(f'{prefix}/') for prefix in vendor_roots))

    with TemporaryDirectory(dir=DIST) as staging:
        output = Path(staging) / f'{slug}.zip'
        with ZipFile(output, 'w', ZIP_DEFLATED) as archive:
            for path in sorted(plugin.rglob('*')):
                relative = PurePosixPath(path.relative_to(plugin).as_posix())
                if not allowed(relative):
                    continue
                if path.is_symlink():
                    raise SystemExit(f'Symlink in package input: {relative}')
                if path.is_file():
                    entry = ZipInfo(f'{slug}/{relative}')
                    entry.compress_type = ZIP_DEFLATED
                    entry.external_attr = 0o100644 << 16
                    archive.writestr(entry, path.read_bytes())

        with ZipFile(output) as archive:
            names = archive.namelist()
            members = {name.removeprefix(f'{slug}/') for name in names}
            missing = sorted(required - members)
            if missing:
                raise SystemExit(f'Incomplete {slug} archive. Missing: {", ".join(missing)}')
            if len(names) != len(members) or any(not name.startswith(f'{slug}/') or
                    not allowed(PurePosixPath(name.removeprefix(f'{slug}/'))) for name in names):
                raise SystemExit(f'Unexpected members in {slug} archive.')
            installed = json.loads(archive.read(f'{slug}/vendor/composer/installed.json'))
            expected_packages = {(package['name'], package['version']) for package in locked}
            actual_packages = {(package['name'], package['version']) for package in installed['packages']}
            if installed.get('dev') is not False or actual_packages != expected_packages:
                raise SystemExit(f'{slug} vendor tree differs from the production lockfile.')
            if archive.testzip() is not None:
                raise SystemExit(f'{slug} archive failed its CRC check.')
        output.replace(DIST / output.name)
    print(f'PASS {edition} ZIP verified against its manifest and Composer lockfile')


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--edition', choices=('free', 'pro'))
    args = parser.parse_args()
    DIST.mkdir(exist_ok=True)
    for selected in (args.edition,) if args.edition else ('free', 'pro'):
        build(selected)
