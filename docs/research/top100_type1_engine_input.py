"""Freeze PR107 classifications for the unmodified PHP portfolio engine."""
import argparse
import json
from pathlib import Path
import top100_type1_portfolio as v1


def main():
    ap=argparse.ArgumentParser(description=__doc__)
    for k in ('centers','candidates','dataset','sector-map','output'):
        ap.add_argument('--'+k,type=Path,required=True)
    args=ap.parse_args()
    centers=v1.centers_from(args.centers)
    days,bars,signals,skips,hashes=v1.prepare(args.candidates,args.dataset,centers)
    for s in signals:
        close=bars[s['symbol']][s['anchor']]['close']
        s.update(entry=close,stop=close*.95,target=close*1.15,order_valid_bars=1)
    out=dict(schema=1,days=days,signals=signals,skips=skips,
             dataset=str(args.dataset.resolve()),sectors=v1.read(args.sector_map)['sectors'],
             price_basis='yahoo_chart_quote_ohlcv_daily_adjustment_unverified',
             hashes=dict(centers=v1.digest(args.centers),candidates=v1.digest(args.candidates),
                         sector_map=v1.digest(args.sector_map),bars=hashes,
                         input_builder=v1.digest(__file__),classifier=v1.digest(v1.__file__)))
    # Never overwrite a prior research input.
    with args.output.open('x',encoding='utf-8') as f:
        json.dump(out,f,ensure_ascii=False,indent=2);f.write('\n')
    print(json.dumps(dict(signals=len(signals),skips=skips,output=str(args.output))))

if __name__=='__main__':main()
