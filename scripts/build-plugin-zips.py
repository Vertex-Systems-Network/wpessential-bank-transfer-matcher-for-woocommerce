"""Write independently installable development packages from built Composer roots."""

from pathlib import Path
from zipfile import ZIP_DEFLATED, ZipFile, ZipInfo

root = Path(__file__).resolve().parents[1]
dist = root / 'dist'
dist.mkdir(exist_ok=True)

for suffix in ('', '-pro'):
    slug = f'wpessential-bank-transfer-matcher{suffix}'
    plugin = root / 'plugins' / slug
    required = (plugin / f'{slug}.php', plugin / 'composer.json', plugin / 'composer.lock',
                plugin / 'vendor/autoload.php')
    if not all(path.is_file() for path in required):
        raise SystemExit(f'Incomplete {slug} package. Run composer install --no-dev first.')
    with ZipFile(dist / f'{slug}.zip', 'w', ZIP_DEFLATED) as archive:
        for path in sorted(plugin.rglob('*')):
            if not path.is_file() or path.is_symlink():
                continue
            relative = path.relative_to(plugin)
            if relative.parts[0] in ('tests', 'node_modules') or any(part.startswith('.') for part in relative.parts):
                continue
            entry = ZipInfo(f'{slug}/{relative.as_posix()}')
            entry.compress_type = ZIP_DEFLATED
            entry.external_attr = 0o100644 << 16
            archive.writestr(entry, path.read_bytes())
