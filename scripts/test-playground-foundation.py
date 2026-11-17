"""Assert PHP bootstrap state after installing each real development ZIP."""

import json
import subprocess
import sys
from pathlib import Path
from zipfile import ZipFile

ROOT = Path(__file__).resolve().parents[1]
DIST = ROOT / 'dist'
PHP = (ROOT / 'playground/tests/foundation.php').read_text()
CLI = sys.argv[1:] or ['npx', '--yes', '@wp-playground/cli@3.1.65']
for edition in ('base', 'free', 'pro'):
    source = DIST / f'playground-{edition}.zip'
    with ZipFile(source) as bundle:
        blueprint = json.loads(bundle.read('blueprint.json'))
        blueprint['steps'].append({'step': 'runPHP', 'code': PHP.replace('WBM_SCENARIO', repr(edition))})
        smoke = DIST / f'playground-{edition}-smoke.zip'
        with ZipFile(smoke, 'w') as output:
            output.writestr('blueprint.json', json.dumps(blueprint))
            for item in bundle.namelist():
                if item != 'blueprint.json':
                    output.writestr(item, bundle.read(item))
    result = subprocess.run([*CLI, 'run-blueprint', f'--blueprint={smoke}'])
    result.check_returncode()
    print(f'PASS {edition} Blueprint and PHP bootstrap assertions', flush=True)
