"""Package a Playground Blueprint with its required, already-built plugin archives."""

import json
from pathlib import Path
from zipfile import ZIP_DEFLATED, ZipFile

ROOT = Path(__file__).resolve().parents[1]
DIST = ROOT / 'dist'

for edition in ('base', 'free', 'pro'):
    source = ROOT / 'playground' / 'blueprints' / edition / 'blueprint.json'
    blueprint = json.loads(source.read_text())
    resources = [step['pluginData']['path'].removeprefix('/') for step in blueprint['steps']
                 if step.get('pluginData', {}).get('resource') == 'bundled']
    for resource in resources:
        if Path(resource).name != resource or not (DIST / resource).is_file():
            raise SystemExit(f'Build required plugin archive first: {resource}')
    with ZipFile(DIST / f'playground-{edition}.zip', 'w', ZIP_DEFLATED) as bundle:
        bundle.write(source, 'blueprint.json')
        for resource in resources:
            bundle.write(DIST / resource, resource)
