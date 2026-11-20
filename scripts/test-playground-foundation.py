"""Assert normal and missing-dependency plugin activation from development ZIPs."""

import argparse
from io import BytesIO
import json
import subprocess
import time
from pathlib import Path
from zipfile import ZipFile

ROOT = Path(__file__).resolve().parents[1]
DIST = ROOT / 'dist'
FREE = 'wpessential-bank-transfer-matcher'
PRO = f'{FREE}-pro'
VERSION_SCENARIOS = ('woo-outdated', 'free-outdated', 'free-new-major')
LIFECYCLE = ('deactivate-pro', 'deactivate-free', 'reactivate-free', 'reactivate-pro', 'restored')


def with_free_api_version(archive, version):
    changed = BytesIO()
    bootstrap = f'{FREE}/src/Bootstrap/Plugin.php'
    with ZipFile(BytesIO(archive)) as source, ZipFile(changed, 'w') as target:
        for name in source.namelist():
            content = source.read(name)
            if name == bootstrap:
                expected = b"const API_VERSION = '1.0.0';"
                if content.count(expected) != 1:
                    raise ValueError('Update the Free API compatibility fixture to match the source.')
                content = content.replace(expected, f"const API_VERSION = '{version}';".encode())
            target.writestr(name, content)
    return changed.getvalue()


def outdated_woo_fixture():
    archive = BytesIO()
    with ZipFile(archive, 'w') as plugin:
        plugin.writestr('woocommerce/woocommerce.php', "<?php\n/* Plugin Name: WooCommerce test fixture */\n"
                       "define('WC_VERSION', '10.7.0');\n")
    return archive.getvalue()


def build_smoke_bundle(scenario):
    base = scenario if scenario in ('base', 'free', 'pro') else 'base'
    if scenario in ('woo-outdated', 'access'):
        base = 'free'
    elif scenario in (*VERSION_SCENARIOS[1:], 'lifecycle'):
        base = 'pro'
    with ZipFile(DIST / f'playground-{base}.zip') as bundle:
        blueprint = json.loads(bundle.read('blueprint.json'))
        resources = {name: bundle.read(name) for name in bundle.namelist()
                     if name != 'blueprint.json'}

    if scenario in ('base', 'free', 'pro'):
        php = (ROOT / 'playground/tests/foundation.php').read_text()
        php = php.replace('WBM_SCENARIO', repr(scenario))
    elif scenario == 'access':
        php = (ROOT / 'playground/tests/access.php').read_text()
    elif scenario in VERSION_SCENARIOS:
        if scenario == 'woo-outdated':
            resources['woocommerce-fixture.zip'] = outdated_woo_fixture()
            woo_install = next(step for step in blueprint['steps'] if step['step'] == 'installPlugin')
            woo_install['pluginData'] = {'resource': 'bundled', 'path': '/woocommerce-fixture.zip'}
        else:
            archive = f'{FREE}.zip'
            version = '0.9.0' if scenario == 'free-outdated' else '2.0.0'
            resources[archive] = with_free_api_version(resources[archive], version)
        php = (ROOT / 'playground/tests/incompatible.php').read_text()
        php = php.replace('WBM_VERSION_SCENARIO', repr(scenario))
    elif scenario == 'lifecycle':
        php = (ROOT / 'playground/tests/lifecycle.php').read_text()
        for state in LIFECYCLE[:-1]:
            blueprint['steps'].append({'step': 'runPHP', 'code': php.replace('WBM_LIFECYCLE_STATE', repr(state))})
        php = php.replace('WBM_LIFECYCLE_STATE', repr(LIFECYCLE[-1]))
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


def run_with_download_retry(command, retries=2):
    """Retry only transient upstream WooCommerce ZIP downloads, never failed assertions."""
    for attempt in range(retries + 1):
        result = subprocess.run(command, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True)
        if result.stdout:
            print(result.stdout, end='' if result.stdout.endswith('\n') else '\n', flush=True)
        if result.returncode == 0:
            return
        transient = ('Could not download' in (result.stdout or '')
                     and 'downloads.wordpress.org/plugin/woocommerce.latest-stable.zip'
                     in (result.stdout or ''))
        if not transient or attempt >= retries:
            raise subprocess.CalledProcessError(result.returncode, command)
        print(f'RETRY transient WooCommerce ZIP download ({attempt + 1}/{retries})', flush=True)
        time.sleep(2 * (attempt + 1))


def main():
    scenarios = ('base', 'free', 'pro', 'woo-missing', 'free-missing', *VERSION_SCENARIOS, 'lifecycle', 'access')
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--cli', help='Local Playground CLI path')
    parser.add_argument('--scenario', action='append', choices=scenarios)
    args = parser.parse_args()
    cli = [args.cli] if args.cli else ['npx', '--yes', '@wp-playground/cli@3.1.65']
    for scenario in args.scenario or scenarios:
        bundle = build_smoke_bundle(scenario)
        run_with_download_retry([*cli, 'run-blueprint', f'--blueprint={bundle}'])
        print(f'PASS {scenario} Blueprint and PHP bootstrap assertions', flush=True)


if __name__ == '__main__':
    main()
