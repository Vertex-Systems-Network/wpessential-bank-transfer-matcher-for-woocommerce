"""Assert normal and missing-dependency plugin activation from development ZIPs."""

import argparse
import json
import subprocess
from pathlib import Path
from zipfile import ZipFile

ROOT = Path(__file__).resolve().parents[1]
DIST = ROOT / 'dist'
FREE = 'wpessential-bank-transfer-matcher'
PRO = f'{FREE}-pro'


def build_smoke_bundle(scenario):
    base = scenario if scenario in ('base', 'free', 'pro') else 'base'
    with ZipFile(DIST / f'playground-{base}.zip') as bundle:
        blueprint = json.loads(bundle.read('blueprint.json'))
        resources = {name: bundle.read(name) for name in bundle.namelist()
                     if name != 'blueprint.json'}

    if scenario in ('base', 'free', 'pro'):
        php = (ROOT / 'playground/tests/foundation.php').read_text()
        php = php.replace('WBM_SCENARIO', repr(scenario))
    else:
        slug = FREE if scenario == 'woo-missing' else PRO
        if scenario == 'woo-missing':
            blueprint['steps'] = [step for step in blueprint['steps'] if step['step'] != 'installPlugin']
        archive = f'{slug}.zip'
        resources[archive] = (DIST / archive).read_bytes()
        blueprint['steps'].append({
            'step': 'installPlugin',
            'pluginData': {'resource': 'bundled', 'path': f'/{archive}'},
            'options': {'activate': False},
        })
        php = (ROOT / 'playground/tests/dependencies.php').read_text()
        php = php.replace('WBM_DEPENDENCY_PLUGIN', repr(slug))

    blueprint['steps'].append({'step': 'runPHP', 'code': php})
    smoke = DIST / f'playground-{scenario}-smoke.zip'
    with ZipFile(smoke, 'w') as output:
        output.writestr('blueprint.json', json.dumps(blueprint))
        for name, content in resources.items():
            output.writestr(name, content)
    return smoke


def main():
    scenarios = ('base', 'free', 'pro', 'woo-missing', 'free-missing')
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--cli', help='Local Playground CLI path')
    parser.add_argument('--scenario', action='append', choices=scenarios)
    args = parser.parse_args()
    cli = [args.cli] if args.cli else ['npx', '--yes', '@wp-playground/cli@3.1.65']
    for scenario in args.scenario or scenarios:
        bundle = build_smoke_bundle(scenario)
        subprocess.run([*cli, 'run-blueprint', f'--blueprint={bundle}'], check=True)
        print(f'PASS {scenario} Blueprint and PHP bootstrap assertions', flush=True)


if __name__ == '__main__':
    main()
