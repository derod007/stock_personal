import importlib.util
import json
from datetime import date, timedelta
from pathlib import Path
import tempfile
import unittest

PATH = Path(__file__).resolve().parents[1]/'docs/research/top100_type1_portfolio.py'
spec = importlib.util.spec_from_file_location('portfolio', PATH)
m = importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)

class PortfolioTest(unittest.TestCase):
    def setUp(self):
        self.cfg = dict(initial_cash=1000000, max_positions=4, risk_pct=.01,
                        position_pct=.2, sector_pct=.4, total_risk_pct=.03)
    def bar(self, o=100, h=101, l=99, c=100):
        return dict(open=o, high=h, low=l, close=c, volume=100, is_complete=True)
    def signal(self, sym='A', typ=1, entry='02', end='03'):
        return dict(symbol=sym, cluster=typ, anchor='01', entry_day=entry, end=end)
    def test_trade_matches_v2_target_stop_gap_samebar_and_time(self):
        v2 = m.module('top100_pre_rise_episode')
        cases = [[self.bar(h=116)], [self.bar(l=94)],
                 [self.bar(), self.bar(o=80,h=82,l=79,c=81)],
                 [self.bar(h=116,l=94)], [self.bar(c=101)]]
        for window in cases:
            with self.subTest(window=window):
                ds = ['02','03'][:len(window)]
                r = m.simulate(ds, {'A':dict(zip(ds,window))}, [self.signal(end=ds[-1])], self.cfg, {})
                expected = v2.simulate(window, 100)
                t = r['trades'][0]
                self.assertAlmostEqual(t['net_pct'], expected['tradable_net_pct'])
                self.assertEqual(t['exit_reason'], expected['exit_reason'])
                self.assertEqual(t['held_bars'], expected['bars_held'])
    def test_limits_and_deterministic_order(self):
        cfg = dict(self.cfg, max_positions=1)
        sig = [self.signal('B'), self.signal('A')]
        bars = {s:{'02':self.bar(),'03':self.bar()} for s in ('A','B')}
        r = m.simulate(['02','03'], bars, sig, cfg, {})
        self.assertEqual([t['symbol'] for t in r['trades']], ['A'])
        self.assertEqual(r['blocked'], {'position_limit':1})
        self.assertEqual(r, m.simulate(['02','03'], bars, list(reversed(sig)), cfg, {}))
    def test_sector_and_risk_caps(self):
        for cfg in (dict(self.cfg, sector_pct=.001), dict(self.cfg, total_risk_pct=.001)):
            sig = [self.signal(s) for s in 'ABCD']
            bars = {s:{'02':self.bar(),'03':self.bar()} for s in 'ABCD'}
            r = m.simulate(['02','03'],bars,sig,cfg,{})
            fills = [e for e in r['events'] if e['event']=='fill']
            self.assertLessEqual(sum(e['cost'] for e in fills), cfg['initial_cash']*cfg['sector_pct']+.001)
            unit = 100*m.BUY-95*m.SELL
            self.assertLessEqual(sum(e['qty']*unit for e in fills),cfg['initial_cash']*cfg['total_risk_pct']+.001)
            self.assertGreater(sum(r['blocked'].values()),0)
    def test_missing_future_halts_instead_of_excluding_trade(self):
        r = m.simulate(['02','03'], {'A':{'02':self.bar()}}, [self.signal()], self.cfg, {})
        self.assertEqual(r['status'],'halted')
        self.assertEqual(r['fills'],1)
        self.assertIsNone(r['return_pct'])
        self.assertEqual(r['through'],'02')
    def test_missing_open_halts(self):
        r = m.simulate(['02','03'], {'A':{}}, [self.signal()], self.cfg, {})
        self.assertEqual(r['halt']['day'],'02')
        self.assertEqual(r['fills'],0)
    def test_exit_cash_not_reused_at_same_open(self):
        cfg = dict(self.cfg, max_positions=1)
        sig = [self.signal('A', end='03'), self.signal('B',entry='03')]
        bars = {'A':{'02':self.bar(),'03':self.bar(h=116)},'B':{'03':self.bar()}}
        r = m.simulate(['02','03'], bars, sig,cfg,{})
        self.assertEqual(r['blocked'],{'position_limit':1})
        self.assertEqual(r['closed'],1)
    def test_cash_accounting_drawdown_and_type_filter(self):
        sig = [self.signal('A'),self.signal('B',typ=2)]
        bars = {s:{'02':self.bar(c=99),'03':self.bar(l=94)} for s in 'AB'}
        r = m.simulate(['02','03'],bars,sig,self.cfg,{},1)
        self.assertEqual(r['signals'],1)
        self.assertAlmostEqual(r['equity'][-1]['cash'],self.cfg['initial_cash']+sum(t['pnl'] for t in r['trades']))
        self.assertAlmostEqual(r['max_drawdown_pct'],-r['return_pct'])
    def test_prepare_does_not_inspect_future_for_signal_eligibility(self):
        with tempfile.TemporaryDirectory() as tmp:
            p=Path(tmp); (p/'bars').mkdir()
            days=[f'2026-01-{i:02}' for i in range(1,23)]
            past=[dict(self.bar(), time_kst=str(date(2025,10,1)+timedelta(days=i))) for i in range(1,61)]
            past.append(dict(self.bar(), time_kst=days[0]))
            (p/'bars/A.json').write_text(json.dumps({'rows':past}))
            cand=p/'candidates.json'
            cand.write_text(json.dumps({'days':days,'dates':{d:{'rows':[{'symbol':'A'}]} for d in days}}))
            out=m.prepare(cand,p,[[0.]*120 for _ in range(6)])
            self.assertEqual(len(out[2]),1)
            self.assertEqual(out[2][0]['entry_day'],days[1])
            self.assertEqual(out[3],{'period_end':1})
    def test_bad_centers_fail(self):
        with tempfile.TemporaryDirectory() as tmp:
            p=Path(tmp)/'centers.json';p.write_text('{"centers": [[0]]}')
            with self.assertRaises(ValueError): m.centers_from(p)

if __name__ == '__main__': unittest.main()
