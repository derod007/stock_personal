import importlib.util,json,tempfile,unittest
from pathlib import Path
from datetime import date,timedelta
spec=importlib.util.spec_from_file_location('historical_summary',Path(__file__).parents[1]/'bin/summarize_historical_decisions.py')
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
class HistoricalSummaryTest(unittest.TestCase):
 def test_empty_php_arrays_and_overlapping_price_outcomes(self):
  with tempfile.TemporaryDirectory() as tmp:
   p=Path(tmp);(p/'diagnosis').mkdir();(p/'dataset/bars').mkdir(parents=True)
   days=[str(date(2025,1,1)+timedelta(days=i)) for i in range(21)]
   syms=[f'{i:06d}.KS' for i in range(100)];dates={}
   for day in days:
    dates[day]={'rows':[{'symbol':s} for s in syms]}
    rows=[{'date':day,'symbol':s,'name':s,'quality':{'can_simulate':False,'reasons':['insufficient_history']},'final':[],'patterns':[],'recommendation_eligible':False} for s in syms]
    (p/'diagnosis'/f'day-{day}.json').write_text(json.dumps({'payload':{'records':rows}}))
   (p/'candidates.json').write_text(json.dumps({'days':days,'dates':dates}))
   bars=[{'time_kst':d+' 09:00:00','open':100,'high':120,'low':80,'close':90,'volume':100} for d in days]
   (p/'dataset/bars'/f'{syms[0]}.json').write_text(json.dumps({'rows':bars}))
   r=m.summarize(p/'diagnosis',p/'dataset',p/'candidates.json')
   self.assertEqual(r['observations'],2100);self.assertEqual(r['valid_outcome_episodes'],1)
   self.assertEqual(r['states'],{'quality_blocked':2100})
   self.assertEqual(r['episodes'][0]['order_15_minus5'],'same_bar_unknown')
   self.assertTrue(r['episodes'][0]['hit15']);self.assertLess(r['episodes'][0]['end_return'],0)
if __name__=='__main__':unittest.main()
