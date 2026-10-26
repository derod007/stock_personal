import importlib.util
from pathlib import Path
import unittest
spec=importlib.util.spec_from_file_location('diagnostic',Path(__file__).resolve().parents[1]/'docs/research/score_selection_diagnostic.py')
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)

def bar(o=100,c=110):return dict(open=o,close=c,high=max(o,c),low=min(o,c),volume=1,is_complete=True)
def row(date,score,value,symbol='A',index=0):return dict(segment='x',date=date,score=score,symbol=symbol,index=index,returns={'5':value})

class DiagnosticTests(unittest.TestCase):
    def test_exact_entry_and_exit(self):
        days=['t','1','2','3','4','5'];bars={d:bar() for d in days};bars['t']=bar(1,999);bars['5']=bar(120,130)
        self.assertAlmostEqual(m.outcome(bars,days,0,5)[0],30)
    def test_missing_does_not_shift_forward(self):
        days=['t','1','2','3','4','5'];bars={d:bar() for d in days if d!='2'}
        self.assertEqual(m.outcome(bars,days,0,5),(None,'missing_future_session'))
    def test_boundary_and_invalid(self):
        self.assertEqual(m.outcome({},['t'],0,5),(None,'segment_end'))
        self.assertFalse(m.valid_bar(dict(bar(),high=1)))
    def test_equal_dates_not_pooled(self):
        rows=[row('d1',70,10)]+[row('d1',0,0,'B') for _ in range(9)]+[row('d2',70,0),row('d2',0,10,'B')]
        r,p=m.contrast(rows,5,lambda r:r['score']>=70)
        self.assertEqual(r['paired_days'],2);self.assertEqual(r['difference']['mean'],0)
    def test_unpaired_and_missing_excluded(self):
        r,p=m.contrast([row('d',70,10),row('d',0,None,'B')],5,lambda r:r['score']>=70)
        self.assertEqual(r['paired_days'],0);self.assertIsNone(r['difference']['mean'])
    def test_nonoverlap_does_not_look_at_outcome(self):
        rs=[row('a',0,None,index=0),row('b',90,10,index=20),row('c',90,10,index=21)]
        self.assertEqual([r['index'] for r in m.nonoverlap(rs)],[0,21])
    def test_symbol_equal_differs_from_repeated_observations(self):
        rs=[row('d'+str(i),70,10,'A') for i in range(9)]
        rs += [row('d'+str(i),0,0,'C') for i in range(9)]
        rs += [row('last',70,-20,'B'),row('last',0,0,'C')]
        result,_=m.contrast(rs,5,lambda r:r['score']>=70)
        self.assertEqual(result['difference']['mean'],7)
        self.assertEqual(result['a_symbol_equal_excess']['mean'],-5)

    def test_fixed_bins(self):
        self.assertEqual([m.score_bin(x) for x in (39,40,59,60,69,70,84,85)],['<40','40-59','40-59','60-69','60-69','70-84','70-84','85+'])

if __name__=='__main__':unittest.main()
