"""Research-only structural labels. Classify reads A; compare opens B/C after labels freeze."""
import argparse, hashlib, json, statistics
from pathlib import Path
from collections import Counter

ROOT = Path(__file__).resolve().parents[2]
PROTOCOL = ROOT / 'docs/pullback-structure-protocol.md'

def sha(path):
    return hashlib.sha256(Path(path).read_bytes()).hexdigest()

def read(path):
    return json.loads(Path(path).read_text(encoding='utf-8-sig'))

def encode(value):
    return json.dumps(value, ensure_ascii=False, indent=2) + '\n'

def structural(bars):
    window = bars[-120:]
    out = dict(label='U', reason='insufficient_structure', L1=None, L2=None, H=None,
               low_broken_after_L2=None, bullish_recovery=False)
    if len(window) < 7 or any(b['ohlc_invalid'] for b in window):
        out['reason'] = 'insufficient_or_invalid_window'
        return out
    lows, highs = [], []
    for i in range(2, len(window)-2):
        b = window[i]
        adjacent = [window[j] for j in range(i-2, i+3) if j != i]
        low = all(b['low'] < p['low'] for p in adjacent)
        high = all(b['high'] > p['high'] for p in adjacent)
        if low == high:
            continue
        pivot = dict(index=i, date=b['date'], confirmed_date=window[i+2]['date'],
                     price=b['low'] if low else b['high'])
        (lows if low else highs).append(pivot)
    if len(lows) < 2:
        return out
    l1, l2 = lows[-2:]
    out.update(L1=l1, L2=l2)
    between = [p for p in highs if l1['index'] < p['index'] < l2['index']]
    if not between:
        out['reason'] = 'no_confirmed_intervening_high'
        return out
    h = max(between, key=lambda p: p['price'])
    broken = any(b['low'] < l2['price'] for b in window[l2['index']+1:])
    last, prev = window[-1], window[-2]
    recovery = last['close'] > last['open'] and last['close'] > prev['high']
    out.update(H=h, low_broken_after_L2=broken, bullish_recovery=recovery)
    if not recovery:
        out['reason'] = 'no_bullish_recovery'
    elif l2['price'] < l1['price'] or broken:
        out.update(label='D', reason='lower_low' if l2['price'] < l1['price'] else 'higher_low_broken')
    elif l2['price'] > l1['price']:
        out.update(label='H', reason='higher_low_held')
    else:
        out['reason'] = 'equal_lows'
    return out

def classify(pack):
    manifest = read(pack/'meta/manifest.json')
    targets = sorted((c for c in manifest['case_index'] if c['cohort']=='selected' and c['pattern']=='trend_pullback'), key=lambda c:c['case_id'])
    if len(targets) != 157:
        raise ValueError('Expected fixed 157-case cohort')
    rows = []
    for c in targets:
        cid = c['case_id']; a = read(pack/f'json/a/{cid}.json')
        input_path = pack/f'json/a/{cid}-input.json'; bars = read(input_path)['bars']
        if not bars or max(b['available_at'] for b in bars) > a['session']:
            raise ValueError('Invalid as-of input')
        last = bars[-1]
        rows.append(dict(case_id=cid, dataset=a['dataset_id'], symbol=a['symbol'], name=a['name'],
                         date=a['session_date'], input_file_sha256=sha(input_path),
                         ma20=last['ma20'], close=last['close'], below_ma20=last['ma20'] is not None and last['close']<last['ma20'],
                         **structural(bars)))
    return dict(kind='pullback_structure_classification_v1', protocol_sha256=sha(PROTOCOL),
                script_sha256=sha(__file__), manifest_sha256=sha(pack/'meta/manifest.json'),
                strategy_fingerprint=manifest['strategy_fingerprint'], rows=rows)

def performance(rows):
    closed=[r for r in rows if r['status']=='closed']; rets=[r['net_return_pct'] for r in closed]
    mean=lambda v:statistics.mean(v) if v else None
    return dict(selected=len(rows), statuses=dict(sorted(Counter(r['status'] for r in rows).items())),
                closed=len(closed), wins=sum(v>0 for v in rets), mean_pct=mean(rets),
                median_pct=statistics.median(rets) if rets else None, mean_R=mean([r['R'] for r in closed]),
                median_signal_stop_pct=statistics.median([r['signal_stop_pct'] for r in rows]) if rows else None)

def compare(pack, labels_path):
    frozen=read(labels_path)
    if frozen['protocol_sha256']!=sha(PROTOCOL) or frozen['script_sha256']!=sha(__file__) or frozen['manifest_sha256']!=sha(pack/'meta/manifest.json'):
        raise ValueError('Protocol, script, or pack changed after classification')
    if classify(pack)!=frozen:
        raise ValueError('Frozen labels differ from pure A-input classification')
    joined=[]
    for r in frozen['rows']:
        cid=r['case_id']; b=read(pack/f'json/b/{cid}.json'); c=read(pack/f'json/c/{cid}.json'); t=c['trade']
        if c['status']!=t['status'] or not b['operational_ready']:
            raise ValueError('Not a selected baseline trade')
        initial_risk=(t['entry_fill']-b['stop'])/t['entry_fill']*100 if t['filled'] else None
        if t['status']=='closed' and not initial_risk>0:
            raise ValueError('Invalid initial risk')
        joined.append(dict(**r, status=t['status'], net_return_pct=t['net_return_pct'],
                           signal_stop_pct=(b['entry']-b['stop'])/b['entry']*100,
                           R=t['net_return_pct']/initial_risk if t['status']=='closed' else None,
                           outcome_file_sha256=sha(pack/f'json/c/{cid}.json')))
    report={}
    for ds in sorted({r['dataset'] for r in joined}):
        report[ds]={label:performance([r for r in joined if r['dataset']==ds and r['label']==label]) for label in ['H','D','U']}
    return dict(kind='pullback_structure_comparison_v1', labels_sha256=sha(labels_path), groups=report, rows=joined)

def self_test():
    def b(i,low,high=30,close=20,op=19):
        return dict(date=str(i),available_at=i,low=low,high=high,close=close,open=op,ohlc_invalid=False)
    lows=[15,14,10,14,15,16,15,14,12,14,15,16]
    bars=[b(i,v) for i,v in enumerate(lows)]
    bars[5]['high']=40
    bars[-2]['high']=24;bars[-1].update(close=25,open=23)
    assert structural(bars)['label']=='H'
    lower=[dict(x) for x in bars];lower[8]['low']=8
    assert structural(lower)['label']=='D'
    broken=[dict(x) for x in bars];broken[-1]['low']=11
    assert structural(broken)['reason']=='higher_low_broken'
    equal=[dict(x) for x in bars];equal[8]['low']=10
    assert structural(equal)['reason']=='equal_lows'
    bad=[dict(x) for x in bars];bad[2]['ohlc_invalid']=True
    assert structural(bad)['label']=='U'
    early=bars[:10]
    assert structural(early)['L2'] is None # Second low has not acquired two right-hand bars.
    outside=[dict(x) for x in bars];outside[8]['high']=100
    assert structural(outside)['L2'] is None
    assert performance([dict(status='pending',signal_stop_pct=10)])['mean_pct'] is None
    print('8 structural and denominator tests passed')

if __name__=='__main__':
    ap=argparse.ArgumentParser();ap.add_argument('command',choices=['classify','compare','test']);ap.add_argument('--pack',type=Path);ap.add_argument('--labels',type=Path)
    args=ap.parse_args()
    if args.command=='test':self_test()
    elif args.command=='classify':print(encode(classify(args.pack)),end='')
    else:print(encode(compare(args.pack,args.labels)),end='')
