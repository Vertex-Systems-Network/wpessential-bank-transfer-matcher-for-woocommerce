"""Fail-closed tests for narrowly scoped external WooCommerce ZIP retry behavior."""
import importlib.util
import subprocess
from pathlib import Path
from unittest.mock import patch

path = Path(__file__).resolve().parents[1] / 'scripts/test-playground-foundation.py'
spec = importlib.util.spec_from_file_location('wbm_playground_foundation', path)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
command = ['fake-playground', 'run-blueprint', '--blueprint=fixture.zip']
transient = subprocess.CompletedProcess(
    command, 1, 'Error: Could not download "https://downloads.wordpress.org/plugin/woocommerce.latest-stable.zip".'
)
unrelated = subprocess.CompletedProcess(command, 1, 'Assertion failed: order was marked paid.')
success = subprocess.CompletedProcess(command, 0, 'PASS blueprint')

with patch.object(module.subprocess, 'run', side_effect=[transient, transient, success]) as invoked, \
     patch.object(module.time, 'sleep') as sleeper:
    module.run_with_download_retry(command)
    assert invoked.call_count == 3, 'WooCommerce ZIP retry did not recover'
    assert sleeper.call_count == 2, 'Unexpected retry timing'
    assert [call.args[0] for call in sleeper.call_args_list] == [2, 4]

with patch.object(module.subprocess, 'run', return_value=unrelated) as invoked, \
     patch.object(module.time, 'sleep') as sleeper:
    try:
        module.run_with_download_retry(command)
        raise AssertionError('Non-network failure was silently retried or accepted')
    except subprocess.CalledProcessError:
        pass
    assert invoked.call_count == 1, 'Assertion failures must not be retried'
    assert sleeper.call_count == 0, 'Assertion failures must fail immediately'

with patch.object(module.subprocess, 'run', side_effect=[transient, transient, transient]) as invoked, \
     patch.object(module.time, 'sleep') as sleeper:
    try:
        module.run_with_download_retry(command)
        raise AssertionError('Repeated network error was silently accepted')
    except subprocess.CalledProcessError:
        pass
    assert invoked.call_count == 3, 'Retry budget was exceeded'
    assert sleeper.call_count == 2, 'Retry was attempted after budget exhaustion'

print('PASS narrow WooCommerce download retry; test failures remain fatal')
