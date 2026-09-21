"""Update the fixed research source, then both entry experiment arms from that one input."""
import argparse
import json
import os
import pathlib
import re
import subprocess
import sys
import time
import uuid
from paper_daily import ROOT, save_record
from paper_research_daily import run


def update(config, state, experiment, runner=subprocess.run, source_run=run):
    if not re.fullmatch(r'[a-z0-9_-]{1,64}', experiment):
        raise ValueError('Invalid experiment ID')
    account = json.loads(config.read_text(encoding='utf-8'))['id']
    log = state / 'entry-experiment-runs' / experiment / (uuid.uuid4().hex + '.json')
    record = {'started_at': int(time.time()), 'finished_at': None, 'status': 'running', 'stage': 'source'}
    save_record(log, record)
    try:
        code = source_run(config, state, runner)
        if code:
            record['status'] = 'halted' if code == 2 else 'failed'
            return code
        record['stage'] = 'comparison'
        save_record(log, record)
        runner(['php', str(ROOT / 'bin/paper_entry_compare.php'), '--source=' + account,
                '--experiment=' + experiment], cwd=ROOT, env=dict(os.environ, PAPER_STATE_DIR=str(state)),
               check=True, timeout=1800)
        record['status'] = 'success'
        return 0
    except (Exception, KeyboardInterrupt) as exc:
        record['status'] = 'failed'
        record['error_type'] = type(exc).__name__
        print('Entry experiment failed: ' + str(exc), file=sys.stderr)
        return 1
    finally:
        record['finished_at'] = int(time.time())
        save_record(log, record)


def main():
    p = argparse.ArgumentParser()
    p.add_argument('--config', default='config/paper-research-us-v1.json')
    p.add_argument('--experiment', required=True)
    a = p.parse_args()
    config = pathlib.Path(a.config)
    if not config.is_absolute(): config = ROOT / config
    state = pathlib.Path(os.environ.get('PAPER_STATE_DIR', str(ROOT.parent / 'stock-personal-paper')))
    if not state.is_absolute(): state = ROOT / state
    return update(config.resolve(), state.resolve(), a.experiment)


if __name__ == '__main__': sys.exit(main())
