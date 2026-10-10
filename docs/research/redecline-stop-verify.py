#!/usr/bin/env python3
"""Independent check of docs/pullback-recent-redecline-stop. Standard library only, no PHP code is called.

It re-reads the dataset bars and rebuilds, from the written protocol alone:
  * the frozen hashes (LF-normalised),
  * the big-correction high, the recent re-decline high, the lowest low after it and the applicability of every case,
  * the baseline and the alternative trade of every case (own simulator),
  * every summary number in comparison.json.
ATR14 and the plan levels are taken from alt-stops.json (they come from the existing engine and are not re-derived here).

usage: redecline-stop-verify.py --dir=docs/pullback-recent-redecline-stop --prior-dir=... --recent-dir=...
"""
import hashlib, json, math, os, sys
from datetime import datetime, timezone, timedelta

KST = timezone(timedelta(hours=9))


def lf_sha(path):
    return hashlib.sha256(open(path, 'rb').read().replace(b'\r\n', b'\n')).hexdigest()


def close_ts(row):
    d = datetime.strptime(row['time_kst'][:10], '%Y-%m-%d').replace(hour=15, minute=30, tzinfo=KST)
    return int(d.timestamp())


def day_of(ts):
    return datetime.fromtimestamp(ts, KST).strftime('%Y-%m-%d')


def valid_ohlc(b):
    for k in ('open', 'high', 'low', 'close'):
        v = b.get(k)
        if not isinstance(v, (int, float)) or isinstance(v, bool) or not math.isfinite(v) or v <= 0:
            return False
    return b['low'] <= min(b['open'], b['close']) and b['high'] >= max(b['open'], b['close']) and b['high'] >= b['low']


def completed(rows, session):
    out = {}
    for b in rows:
        t = close_ts(b)
        if b.get('synthetic') or b.get('is_complete') is False or t > session or not valid_ohlc(b):
            continue
        out[t] = dict(b, at=t)
    return [out[t] for t in sorted(out)]


def future(rows, session, cutoff):
    by = {}
    for b in rows:
        t = close_ts(b)
        if t <= session or t > cutoff or b.get('synthetic') or b.get('is_complete') is False:
            continue
        vol = b.get('volume')
        ok = valid_ohlc(b) and isinstance(vol, (int, float)) and math.isfinite(vol) and vol >= 0
        by[t] = None if t in by else (dict(b, at=t) if ok else None)
    return [by[t] for t in sorted(by)][:22]


def pivot_high(bars, i, k=3):
    if i - k < 0 or i + k > len(bars) - 1:
        return False
    v = bars[i]['high']
    return all(bars[j]['high'] < v for j in range(i - k, i + k + 1) if j != i)


def low_after(bars, i):
    best = None
    for j in range(i + 1, len(bars)):
        if best is None or bars[j]['low'] < bars[best]['low']:
            best = j
    return best


def rule(rows, session, atr, entry):
    bars = completed(rows, session)
    n = len(bars)
    first = max(0, n - 120)
    # quality: raw rows in the window's time span that the completed filter dropped, or duplicated sessions
    t0 = bars[first]['at']
    seen = {}
    for b in rows:
        t = close_ts(b)
        if t0 <= t <= session and not b.get('synthetic') and b.get('is_complete') is not False:
            seen[t] = seen.get(t, 0) + 1
    kept = {b['at'] for b in bars[first:]}
    dropped = sum(c - (1 if t in kept else 0) for t, c in seen.items() if t not in kept or c > 1)
    if dropped > 0:
        return {'status': 'not_applicable', 'reason': 'quality_problem'}
    gi = first
    for i in range(first, n):
        if bars[i]['high'] > bars[gi]['high']:
            gi = i
    if gi == n - 1:
        return {'status': 'not_applicable', 'reason': 'h_big_none_manual_A_only'}
    if first > 0 and gi - first < 5:
        return {'status': 'not_applicable', 'reason': 'h_big_hold_window_edge'}
    if n - 1 - gi < 2:
        return {'status': 'not_applicable', 'reason': 'h_big_hold_few_bars_after'}
    lb = low_after(bars, gi)
    rec = None
    for i in range(lb + 1, n):
        if bars[i]['high'] >= bars[gi]['high']:
            continue
        if pivot_high(bars, i):
            rec = i
    ri = gi if rec is None else rec
    if n - 1 - ri < 2:
        return {'status': 'not_applicable', 'reason': 'h_recent_hold_few_bars_after'}
    li = low_after(bars, ri)
    stop = math.floor(bars[li]['low'] - 0.2 * atr)
    out = {'H_big': day_of(bars[gi]['at']), 'H_recent': day_of(bars[ri]['at']), 'low_date': day_of(bars[li]['at']), 'low': bars[li]['low'], 'alt_stop': stop}
    if stop <= 0 or stop >= entry:
        return dict(out, status='not_applicable', reason='alt_stop_invalid')
    return dict(out, status='applicable', reason=None)


def trade(plan, bars, post_stop=None):
    entry, stop, target = plan['entry'], plan['stop'], plan['target']
    after = stop if post_stop is None else post_stop
    ttl, hold, fee, slip = 3, 20, 10 / 10000, 5 / 10000
    seq = []
    bad = False
    for b in bars:
        if b is None:
            bad = True
            break
        seq.append(b)
    res = {'status': None, 'first_exit': None, 'net': None, 'fill': None}
    filled = None
    fill = None
    for i, b in enumerate(seq):
        if filled is None:
            if i >= ttl:
                return dict(res, status='unfilled')
            if b['open'] <= stop or b['open'] >= target or b['high'] >= target:
                return dict(res, status='cancelled_before_entry')
            if b['low'] > entry:
                continue
            filled = i
            fill = min(entry, b['open'] * (1 + slip))
            res['fill'] = fill
            if after >= fill:
                return dict(res, status='alt_stop_not_below_fill')
            stop = after
        held = i - filled + 1
        stop_hit = b['low'] <= stop
        target_hit = b['high'] >= target
        why = exit_ = None
        if i > filled and b['open'] >= target:
            why, exit_ = 'target', target
        elif stop_hit:
            why, exit_ = 'stop', min(stop, b['open']) * (1 - slip)
        elif target_hit:
            why, exit_ = 'target', target
        elif held >= hold:
            why, exit_ = 'time', b['close'] * (1 - slip)
        if exit_ is not None:
            net = (exit_ * (1 - fee)) / (fill * (1 + fee)) - 1
            return dict(res, status='closed', first_exit=why, net=round(net * 100, 4))
    if filled is not None:
        return dict(res, status='incomplete')
    if len(seq) >= ttl:
        return dict(res, status='unfilled')
    return dict(res, status='future_quality_blocked' if bad else 'pending')


def mean(v):
    return sum(v) / len(v) if v else None


def median(v):
    v = sorted(v)
    n = len(v)
    if not n:
        return None
    return v[n // 2] if n % 2 else (v[n // 2 - 1] + v[n // 2]) / 2


def close(a, b, tol=1.5e-4):
    if a is None or b is None:
        return a is b
    return abs(a - b) <= tol


def main():
    o = dict(a[2:].split('=', 1) for a in sys.argv[1:] if a.startswith('--'))
    d = o['dir']
    dirs = {'prior': o['prior-dir'], 'recent': o['recent-dir']}
    fz = json.load(open(d + '/freeze.json', encoding='utf8'))
    cmp_ = json.load(open(d + '/comparison.json', encoding='utf8'))
    alt = json.load(open(d + '/alt-stops.json', encoding='utf8'))
    problems = []
    checks = {}

    bad = [f for f, h in fz['files_sha256_lf'].items() if lf_sha(os.path.join(d, f)) != h]
    root = os.path.abspath(o.get('root') or os.path.join(d, '..', '..'))
    bad += [f for f, h in fz['code_sha256_lf'].items() if lf_sha(os.path.join(root, f)) != h]
    checks['frozen_files_and_code_unchanged'] = {'pass': not bad, 'changed': bad}
    checks['comparison_names_this_freeze'] = {'pass': cmp_['freeze_sha256_lf'] == lf_sha(d + '/freeze.json')}

    # bars
    cache = {}

    def rows_of(period, symbol):
        key = (period, symbol)
        if key not in cache:
            cache[key] = json.load(open('%s/bars/%s.json' % (dirs[period], symbol), encoding='utf8'))['rows']
        return cache[key]

    cutoff = {p: json.load(open(dirs[p] + '/dataset.json', encoding='utf8'))['as_of']['close_ts'] for p in dirs}
    byid = {c['id']: c for c in cmp_['cases']}
    rule_bad, trade_bad, leak_bad = [], [], []
    mine = {}
    for c in alt['cases']:
        rows = rows_of(c['period'], c['symbol'])
        # the signal-day decision may only see rows closed at or before the session: pass only those
        past = [b for b in rows if close_ts(b) <= c['session']]
        if completed(past, c['session'])[-1]['at'] != c['session']:
            leak_bad.append(c['id'])
        r = rule(past, c['session'], c['atr14'], c['plan']['entry'])
        if r['status'] != c['status'] or r['reason'] != c['reason']:
            rule_bad.append((c['id'], c['status'], r['status'], c['reason'], r['reason']))
        elif r['status'] == 'applicable':
            if r['alt_stop'] != c['alt_stop'] or r['H_recent'] != c['H_recent']['date'] or r['H_big'] != c['H_big']['date'] or r['low_date'] != c['low_after_recent']['date']:
                rule_bad.append((c['id'], 'values', r, c['alt_stop']))
        fut = future(rows, c['session'], cutoff[c['period']])
        base = trade(c['plan'], fut)
        al = trade(c['plan'], fut, c['alt_stop']) if c['status'] == 'applicable' else None
        mine[c['id']] = (base, al)
        row = byid[c['id']]
        if base['status'] != row['baseline']['status'] or base['first_exit'] != row['baseline']['first_exit'] or not close(base['net'], row['baseline']['net_return_pct']):
            trade_bad.append((c['id'], 'baseline', base, row['baseline']['status'], row['baseline']['net_return_pct']))
        if al is None:
            if row['alt'] is not None:
                trade_bad.append((c['id'], 'alt should be absent'))
        elif al['status'] != row['alt']['status'] or al['first_exit'] != row['alt']['first_exit'] or not close(al['net'], row['alt']['net_return_pct']):
            trade_bad.append((c['id'], 'alt', al, row['alt']['status'], row['alt']['net_return_pct']))
    checks['alt_stops_and_applicability_recomputed'] = {'pass': not rule_bad, 'cases': len(alt['cases']), 'mismatches': rule_bad[:5]}
    checks['signal_day_is_last_bar_used'] = {'pass': not leak_bad, 'bad': leak_bad[:5]}
    checks['baseline_and_alt_trades_recomputed'] = {'pass': not trade_bad, 'cases': len(mine), 'mismatches': trade_bad[:5]}

    # summaries from the per-case rows, by an independent path
    sum_bad = []
    for p in ('prior', 'recent'):
        rows = [c for c in cmp_['cases'] if c['period'] == p]
        s = cmp_['summary'][p]
        common = [r for r in rows if r['alt'] and r['baseline']['status'] == 'closed' and r['alt']['status'] == 'closed']
        bn = [r['baseline']['net_return_pct'] for r in common]
        an = [r['alt']['net_return_pct'] for r in common]
        cc = s['common_closed']
        exp = {
            'selected': (len(rows), s['selected']),
            'applicable': (sum(1 for r in rows if r['applicability']['signal'] == 'applicable'), s['applicability']['applicable']),
            'not_applicable': (sum(1 for r in rows if r['applicability']['signal'] == 'not_applicable'), s['applicability']['not_applicable']),
            'common_n': (len(common), cc['n']),
            'base_mean': (round(mean(bn), 4) if bn else None, cc['baseline']['mean']),
            'alt_mean': (round(mean(an), 4) if an else None, cc['alt']['mean']),
            'base_median': (round(median(bn), 4) if bn else None, cc['baseline']['median']),
            'alt_median': (round(median(an), 4) if an else None, cc['alt']['median']),
            'improved': (sum(1 for x, y in zip(bn, an) if round(y - x, 4) > 0), cc['improved']),
            'worse': (sum(1 for x, y in zip(bn, an) if round(y - x, 4) < 0), cc['worse']),
            'same': (sum(1 for x, y in zip(bn, an) if round(y - x, 4) == 0), cc['same']),
            'base_stop_exits': (sum(1 for r in common if r['baseline']['first_exit'] == 'stop'), s['exits_common_closed']['baseline_stop']),
            'alt_stop_exits': (sum(1 for r in common if r['alt']['first_exit'] == 'stop'), s['exits_common_closed']['alt_stop']),
            'winner_to_stop': (sum(1 for r in common if r['baseline']['net_return_pct'] > 0 and r['alt']['first_exit'] == 'stop'), s['exits_common_closed']['baseline_winner_became_alt_stop_exit']),
            'big_loss_base': (sum(1 for x in bn if x <= -10), s['big_loss']['baseline_count']),
            'big_loss_alt': (sum(1 for x in an if x <= -10), s['big_loss']['alt_count']),
        }
        for r in common:
            pass
        rb = [r['baseline']['net_return_pct'] / r['baseline']['risk_fill_pct'] for r in common]
        r1 = [r['alt']['net_return_pct'] / r['baseline']['risk_fill_pct'] for r in common]
        r2 = [r['alt']['net_return_pct'] / r['alt']['risk_fill_pct'] for r in common]
        exp['R_common_den_alt_mean'] = (round(mean(r1), 4) if r1 else None, s['R_common_closed']['common_denominator_baseline_risk']['alt']['mean'])
        exp['R_own_alt_mean'] = (round(mean(r2), 4) if r2 else None, s['R_common_closed']['own_initial_risk']['alt']['mean'])
        exp['R_base_mean'] = (round(mean(rb), 4) if rb else None, s['R_common_closed']['own_initial_risk']['baseline']['mean'])
        trans = {}
        for r in rows:
            a = r['alt']['status'] if r['alt'] else 'not_applicable'
            trans.setdefault(r['baseline']['status'], {})
            trans[r['baseline']['status']][a] = trans[r['baseline']['status']].get(a, 0) + 1
        for k, (mine_v, stored) in exp.items():
            if not (mine_v == stored or (isinstance(mine_v, float) and close(mine_v, stored))):
                sum_bad.append((p, k, mine_v, stored))
        if {k: dict(sorted(v.items())) for k, v in sorted(trans.items())} != s['transitions']:
            sum_bad.append((p, 'transitions'))
    checks['summary_numbers_recomputed_from_case_rows'] = {'pass': not sum_bad, 'mismatches': sum_bad[:8]}

    ok = all(v['pass'] for v in checks.values())
    text = json.dumps({'all_passed': ok, 'checks': checks}, ensure_ascii=False, indent=2)
    if o.get('out'):
        with open(o['out'], 'w', encoding='utf8', newline='\n') as f:
            f.write(text + '\n')
    print(text)
    sys.exit(0 if ok else 1)


if __name__ == '__main__':
    main()
