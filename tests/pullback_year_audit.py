"""Requires the supplied decision-day ZIP extracted locally; never uses network."""
import argparse, importlib.util, json, pathlib, tempfile, unittest, shutil, copy
ROOT=pathlib.Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('audit',ROOT/'docs/research/pullback-year-audit.py');m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
p=argparse.ArgumentParser();p.add_argument('--prices-dir',type=pathlib.Path,required=True);args,remaining=p.parse_known_args()
class AuditTests(unittest.TestCase):
    def test_all_42_and_frozen_join(self):
        result=m.analyze(args.prices_dir)
        self.assertEqual(result['verification']['cases'],42)
        self.assertEqual(m.join_outcomes(result)['total']['losses'],31)
    def changed_copy(self,change):
        with tempfile.TemporaryDirectory() as tmp:
            dest=pathlib.Path(tmp);shutil.copytree(args.prices_dir,dest,dirs_exist_ok=True)
            case=m.read(m.PACK/'list.json')['cases'][0];path=dest/'json/a'/f"{case['case_id']}-input.json"
            data=m.read(path);change(data);path.write_text(json.dumps(data))
            with self.assertRaises(ValueError):m.analyze(dest)
    def test_ohlc_mutation(self):
        self.changed_copy(lambda d:d['bars'][-1].update(close=d['bars'][-1]['close']+1))
    def test_ma_mutation_not_hidden_by_price_hash(self):
        self.changed_copy(lambda d:d['bars'][-1].update(ma20=d['bars'][-1]['ma20']+1))
    def test_future_timestamp_not_hidden_by_price_hash(self):
        self.changed_copy(lambda d:d['bars'][-1].update(available_at=d['bars'][-1]['available_at']+86400))
    def test_svg_coordinate_mutation(self):
        case=m.read(m.PACK/'list.json')['cases'][0];rows=m.read(args.prices_dir/'json/a'/f"{case['case_id']}-input.json")['bars'][-120:]
        svg=m.PACK/'a'/f"{case['case_id']}-120.svg"
        import xml.etree.ElementTree as ET
        tree=ET.parse(svg);line=next(e for e in tree.getroot() if e.tag.endswith('line') and e.get('stroke') in ('#c0392b','#2471a3'))
        line.set('y1',str(float(line.get('y1'))+1))
        with tempfile.TemporaryDirectory() as tmp:
            bad=pathlib.Path(tmp)/'bad.svg';tree.write(bad)
            with self.assertRaises(ValueError):m.check_svg(bad,rows)
    def test_asof_metric_mutation_rejected_before_outcome_join(self):
        result=m.read(m.PACK/'audit/asof-metrics.json');result['cases'][0]['signal_below_ma20']=not result['cases'][0]['signal_below_ma20']
        with self.assertRaisesRegex(ValueError,'freeze'):m.join_outcomes(result)
if __name__=='__main__':unittest.main(argv=['audit_tests']+remaining)
