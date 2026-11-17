"""Render resume pointers and the backlog from recorded task statuses."""

import json
from pathlib import Path

root = Path(__file__).resolve().parents[1]
tasks = json.loads((root / '.ai/tasks.json').read_text())
state_path = root / '.ai/state.json'
state = json.loads(state_path.read_text())
complete = {task['id'] for task in tasks if task['status'] in ('done', 'verified')}
next_task = next((task for task in tasks
                  if task['status'] in ('planned', 'ready', 'in_progress')
                  and set(task['depends_on']).issubset(complete)), None)
if next_task:
    state.update(phase=next_task['phase'], active_task_id=next_task['id'],
                 next_action=next_task.get('next_action', next_task['title']))
else:
    state.update(active_task_id=None, next_action=None)
state['completed_tasks'] = sorted(complete)
state['blocked_tasks'] = [task['id'] for task in tasks if task['status'] == 'blocked']
state_path.write_text(json.dumps(state, indent=2) + '\n')
(root / '.ai/next-task.json').write_text(json.dumps(next_task, indent=2) + '\n')
lines = ['# Backlog', '', 'The task graph lives in `.ai/tasks.json`. Run `python3 scripts/update-backlog.py` after recording evidence.', '',
         '| Task | Acceptance | Owner | Files | Prerequisites | Verification | Status | Evidence |',
         '| --- | --- | --- | --- | --- | --- | --- | --- |']
for task in tasks:
    values = [task['id'], task['title'], task['owner'], task['paths'], ', '.join(task['depends_on']),
              task['verification'], task['status'], task['evidence']]
    lines.append('| ' + ' | '.join(value.replace('|', '\\|') for value in values) + ' |')
(root / 'BACKLOG.md').write_text('\n'.join(lines) + '\n')
