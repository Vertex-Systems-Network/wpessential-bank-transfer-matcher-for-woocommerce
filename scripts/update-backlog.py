"""Validate the task graph and render deterministic resume pointers.

The active task is preserved when it remains unfinished. Otherwise select the
earliest unblocked task in backlog order. Terminal/deferred work is never
selected. This script intentionally does not mark implementation complete.
"""
import json
from pathlib import Path

root = Path(__file__).resolve().parents[1]
tasks_path = root / '.ai/tasks.json'
state_path = root / '.ai/state.json'
tasks = json.loads(tasks_path.read_text())
state = json.loads(state_path.read_text())

allowed = {'planned', 'ready', 'in_progress', 'in_review', 'blocked', 'done', 'verified', 'deferred'}
by_id = {}
for task in tasks:
    task_id = task.get('id')
    if not task_id or task_id in by_id:
        raise SystemExit(f'duplicate or missing task id: {task_id!r}')
    if task.get('status') not in allowed:
        raise SystemExit(f'{task_id}: unsupported status {task.get("status")!r}')
    task.setdefault('depends_on', [])
    task.setdefault('evidence', '')
    by_id[task_id] = task

for task in tasks:
    missing = [dep for dep in task['depends_on'] if dep not in by_id]
    if missing:
        raise SystemExit(f'{task["id"]}: missing dependencies: {", ".join(missing)}')

visiting, visited = set(), set()
def visit(task_id):
    if task_id in visiting:
        raise SystemExit(f'task dependency cycle at {task_id}')
    if task_id in visited:
        return
    visiting.add(task_id)
    for dep in by_id[task_id]['depends_on']:
        visit(dep)
    visiting.remove(task_id)
    visited.add(task_id)
for task in tasks:
    visit(task['id'])

complete = {task['id'] for task in tasks if task['status'] in {'done', 'verified'}}
active = by_id.get(state.get('active_task_id'))
if active and active['status'] in {'planned', 'ready', 'in_progress', 'in_review'} and set(active['depends_on']).issubset(complete):
    next_task = active
else:
    next_task = next((task for task in tasks
                      if task['status'] in {'planned', 'ready', 'in_progress', 'in_review'}
                      and set(task['depends_on']).issubset(complete)), None)
if next_task:
    if next_task['status'] in {'planned', 'ready'}:
        next_task['status'] = 'in_progress'
    state.update(phase=next_task['phase'], active_task_id=next_task['id'],
                 next_action=next_task.get('next_action', next_task['title']))
else:
    state.update(active_task_id=None, next_action=None)
state['completed_tasks'] = sorted(complete)
state['blocked_tasks'] = [task['id'] for task in tasks if task['status'] == 'blocked']
state['execution_status'] = 'in_progress' if next_task else 'release_candidate_pending'
state_path.write_text(json.dumps(state, indent=2) + '\n')
(root / '.ai/next-task.json').write_text(json.dumps(next_task, indent=2) + '\n')

lines = ['# Backlog', '', 'The task graph lives in `.ai/tasks.json`. Run `python3 scripts/update-backlog.py` after recording implementation and evidence.', '',
         '| Task | Acceptance | Owner | Files | Prerequisites | Verification | Status | Evidence |',
         '| --- | --- | --- | --- | --- | --- | --- | --- |']
for task in tasks:
    values = [task['id'], task['title'], task['owner'], task['paths'], ', '.join(task['depends_on']),
              task['verification'], task['status'], task['evidence']]
    lines.append('| ' + ' | '.join(str(value).replace('|', '\\|') for value in values) + ' |')
(root / 'BACKLOG.md').write_text('\n'.join(lines) + '\n')
