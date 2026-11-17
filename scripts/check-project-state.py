"""Validate the persistent task graph and planned requirement references."""

import csv
import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
tasks = json.loads((ROOT / '.ai/tasks.json').read_text())
state = json.loads((ROOT / '.ai/state.json').read_text())
next_task = json.loads((ROOT / '.ai/next-task.json').read_text())

seen = set()
complete = set()
for task in tasks:
    key = task['id']
    if key in seen or not set(task['depends_on']) <= seen:
        raise ValueError(f'Invalid task order or duplicate ID: {key}')
    seen.add(key)
    if task['status'] in ('done', 'verified'):
        if not task['evidence']:
            raise ValueError(f'Completed task requires evidence: {key}')
        complete.add(key)

if complete != set(state['completed_tasks']):
    raise ValueError('Task statuses and checkpoint are out of sync.')
if next_task and (next_task['id'] != state['active_task_id'] or not set(next_task['depends_on']) <= complete):
    raise ValueError('The resume pointer is invalid.')

with (ROOT / 'docs/testing/traceability.csv').open() as file:
    requirements = set()
    screens = set()
    for requirement in csv.DictReader(file):
        key = requirement['Requirement ID']
        if key in requirements:
            raise ValueError(f'Duplicate requirement ID: {key}')
        requirements.add(key)
        screen = requirement['Screen ID']
        if screen:
            screens.add(screen)
        path, line = requirement['Documentation'].rsplit(':', 1)
        if not 0 < int(line) <= len((ROOT / path).read_text().splitlines()):
            raise ValueError(f'Invalid source location: {key}')
    if screens != {f'UI-{number:03}' for number in range(1, 15)}:
        raise ValueError('The planned traceability matrix is missing a screen.')

print(f'PASS {len(tasks)} tasks and {len(requirements)} requirement source references')
