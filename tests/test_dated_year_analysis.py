"""Read-only baseline reconciliation and a corrupt-result negative test."""
import hashlib
import importlib.util
import json
import shutil
import tempfile
import unittest
from pathlib import Path
ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('year_analysis', ROOT/'docs/research/dated-year-analysis.py')
m = importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)
class AnalysisTests(unittest.TestCase):
    def test_baseline_and_no_source_writes(self):
        files = list(m.SRC.glob('*'))
        before = {p: hashlib.sha256(p.read_bytes()).hexdigest() for p in files if p.is_file()}
        result = m.analyze()
        self.assertEqual(result['validation']['daily_equity_and_realized_reconciled'], 244)
        self.assertEqual(result['total']['closed'], 56)
        self.assertEqual(result['funnel']['no_signal_sessions'], 170)
        self.assertEqual(before, {p: hashlib.sha256(p.read_bytes()).hexdigest() for p in before})
    def test_changed_realized_pnl_is_rejected(self):
        with tempfile.TemporaryDirectory() as tmp:
            dest = Path(tmp)
            for name in ('trades.json', 'summary.json', 'daily-log.jsonl'):
                shutil.copyfile(m.SRC/name, dest/name)
            p = dest/'trades.json'
            data = json.loads(p.read_text())
            next(t for t in data['trades'] if t['exit'])['exit']['net_pnl'] += 100
            p.write_text(json.dumps(data))
            with self.assertRaisesRegex(ValueError, 'Ledger mismatch'):
                m.analyze(dest)
if __name__ == '__main__':
    unittest.main()
