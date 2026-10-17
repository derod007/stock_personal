"""Isolated research simulator. Never reads/writes an operating account ledger."""
from __future__ import annotations
import argparse
from collections import Counter, defaultdict
from datetime import date
import hashlib
import importlib.util
import json
import math
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
BUY, SELL = 1.0015, .9985

def module(name):
    spec = importlib.util.spec_from_file_location(name, Path(__file__).with_name(name + '.py'))
    obj = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(obj)
    return obj

def digest(path):
    return hashlib.sha256(Path(path).read_bytes()).hexdigest()

def read(path):
    return json.loads(Path(path).read_text(encoding='utf-8'))

def centers_from(path):
    c = read(path)['centers']
    if len(c) != 6 or any(len(row) != 120 for row in c):
        raise ValueError('Expected frozen six centers of 120 dimensions')
    if any(not isinstance(x, (int, float)) or not math.isfinite(x) for r in c for x in r):
        raise ValueError('Invalid center')
    return c

def prepare(candidates, dataset, centers):
    """Classification only uses bars <= anchor, never future outcome eligibility."""
    v2, v4 = module('top100_pre_rise_episode'), module('top100_pre_rise_shapes')
    raw = read(candidates)
    days = raw['days']
    if days != sorted(set(days)):
        raise ValueError('Calendar must be sorted and unique')
    for day in days:
        date.fromisoformat(day)
    if not (Path(dataset)/"bars").is_dir():
        raise ValueError("Dataset bars directory is missing")
    cache, last, signals, skips, hashes = {}, {}, [], Counter(), {}
    for i, day in enumerate(days):
        for row in sorted(raw['dates'][day]['rows'], key=lambda r: r['symbol']):
            sym = row['symbol']
            if i <= last.get(sym, -1):
                continue
            last[sym] = i + 20
            if i + 20 >= len(days):
                skips['period_end'] += 1
                continue
            if sym not in cache:
                p = Path(dataset) / 'bars' / (sym + '.json')
                cache[sym] = v2.load_symbol(p)[0]
                hashes[sym] = digest(p) if p.is_file() else None
            bars = cache[sym]
            if day not in bars:
                skips['missing_anchor'] += 1
                continue
            series = v4.series60(v2._past(bars, day))
            if series is None:
                skips['insufficient_history'] += 1
                continue
            typ = v4.assign([series['feat']], centers)[0]
            signals.append(dict(symbol=sym, anchor=day, entry_day=days[i+1], end=days[i+20], cluster=typ))
    return days, cache, signals, dict(skips), hashes

def simulate(days, bars, signals, cfg, sectors, only_type=None):
    """Opening auction sizing; entries precede intraday exits. Not operating engine parity."""
    cash = float(cfg['initial_cash'])
    positions, events, curve, trades = {}, [], [], []
    due = defaultdict(list)
    for signal in signals:
        if only_type is None or signal['cluster'] == only_type:
            due[signal['entry_day']].append(signal)
    peak, mdd, max_active, max_used = cash, 0., 0, 0.
    halted = None
    for day in days:
        # No stale marks, no skipping a missing next-open or a held-symbol day.
        needed = set(positions) | {s['symbol'] for s in due[day]}
        missing = sorted(sym for sym in needed if day not in bars.get(sym, {}))
        if missing:
            halted = dict(day=day, reason='missing_required_bar', symbols=missing)
            events.append(dict(event='halt', **halted))
            break
        for sym, p in positions.items():
            p['mark'] = bars[sym][day]['open']
        for order_index, s in enumerate(sorted(due[day], key=lambda s: s['symbol'])):
            sym = s['symbol']
            sector = sectors.get(sym) or 'unclassified'
            equity = cash + sum(p['qty'] * p['mark'] for p in positions.values())
            risk_used = sum(p['risk'] for p in positions.values())
            sector_used = sum(max(p['cost'], p['qty'] * p['mark']) for p in positions.values() if p['sector'] == sector)
            snapshot = dict(cash=cash, equity=equity, held=len(positions),
                            pending_intents=len(due[day])-order_index, reserved_cash=0,
                            risk_used=risk_used, sector_used=sector_used, sector=sector)
            reason = 'already_active' if sym in positions else ('position_limit' if len(positions) >= cfg['max_positions'] else None)
            entry = bars[sym][day]['open']
            risk_budget = max(0, min(equity*cfg['risk_pct'], equity*cfg['total_risk_pct']-risk_used))
            capacity = max(0, min(cash, equity*cfg['position_pct'], equity*cfg['sector_pct']-sector_used))
            unit_risk = entry*BUY - entry*.95*SELL
            qty = math.floor(min(risk_budget/unit_risk, capacity/(entry*BUY)))
            if qty < 1 and reason is None:
                reason = 'cash_or_risk_or_sector_limit'
            if reason:
                events.append(dict(event='blocked', day=day, symbol=sym, reason=reason, before=snapshot))
                continue
            cost = qty*entry*BUY
            p = dict(**s, sector=sector, qty=qty, entry=entry, cost=cost,
                     mark=entry, risk=qty*unit_risk, held_bars=0)
            positions[sym] = p
            cash -= cost
            events.append(dict(event='fill', day=day, symbol=sym, qty=qty, price=entry, cost=cost, before=snapshot))
        max_active = max(max_active, len(positions))
        opening_equity = cash + sum(p['qty']*p['mark'] for p in positions.values())
        if opening_equity > 0:
            max_used = max(max_used, 1-cash/opening_equity)
        for sym, p in list(positions.items()):
            b = bars[sym][day]
            p['held_bars'] += 1
            stop, target = p['entry']*.95, p['entry']*1.15
            stopped, targeted = b['low'] <= stop, b['high'] >= target
            reason, price = None, None
            if stopped:
                reason, price = 'stop', min(stop, b['open'])
            elif targeted:
                reason, price = 'target', target
            elif day == p['end']:
                reason, price = 'time', b['close']
            if reason:
                proceeds = p['qty']*price*SELL
                cash += proceeds
                trade = dict(**p, exit_day=day, exit_price=price, exit_reason=reason,
                             pnl=proceeds-p['cost'], net_pct=(proceeds/p['cost']-1)*100,
                             same_bar_order_unknown=stopped and targeted)
                trades.append(trade)
                events.append(dict(event='exit', day=day, symbol=sym, reason=reason, proceeds=proceeds))
                del positions[sym]
            else:
                p['mark'] = b['close']
        equity = cash + sum(p['qty']*p['mark'] for p in positions.values())
        peak = max(peak, equity)
        mdd = max(mdd, 1-equity/peak)
        curve.append(dict(day=day, cash=cash, equity=equity, held=len(positions)))
    completed = halted is None and not positions
    return dict(status='completed' if completed else 'halted', halt=halted,
                through=curve[-1]['day'] if curve else None,
                return_pct=(cash/cfg['initial_cash']-1)*100 if completed else None,
                max_drawdown_pct=mdd*100, max_concurrent=max_active,
                max_invested_pct=max_used*100, signals=sum(map(len,due.values())),
                fills=len(trades)+len(positions), closed=len(trades),
                blocked=dict(Counter(e['reason'] for e in events if e['event']=='blocked')),
                open_positions=positions, trades=trades, equity=curve, events=events)

def main(argv=None):
    ap = argparse.ArgumentParser(description=__doc__)
    for name in ('centers', 'candidates', 'dataset', 'output'):
        ap.add_argument('--'+name, required=True, type=Path)
    ap.add_argument('--config', type=Path, default=ROOT/'config/paper-kr-recovery-v1.json')
    ap.add_argument('--sector-map', type=Path)
    args = ap.parse_args(argv)
    centers = centers_from(args.centers)
    cfg = read(args.config)
    if cfg.get('currency') != 'KRW' or cfg['initial_cash'] <= 0 or cfg['max_positions'] < 1:
        raise ValueError('Invalid KR research capital/position configuration')
    for key in ('risk_pct', 'position_pct', 'sector_pct', 'total_risk_pct'):
        if not 0 < cfg[key] <= 1:
            raise ValueError('Invalid fraction: '+key)
    sectors = read(args.sector_map)['sectors'] if args.sector_map else {}
    # Refuse overwrites even for prior failed runs; never accepts account IDs/ledger paths.
    args.output.mkdir(parents=True, exist_ok=False)
    days, bars, signals, skips, hashes = prepare(args.candidates, args.dataset, centers)
    results = {name: simulate(days, bars, signals, cfg, sectors, typ)
               for name, typ in [('all_types', None), ('type1', 1)]}
    # Record common coverage, but never compare partial runs as full periods.
    common = min(r['through'] for r in results.values()) if all(r['through'] for r in results.values()) else None
    for name, result in results.items():
        result['research_id'] = 'research-type1-portfolio-v1-' + args.candidates.parent.name + '-' + name
    comparable = all(r['status']=='completed' for r in results.values())
    out = dict(version='type1_portfolio_v1', exploratory=True,
               model='opening_sizing_entries_before_intraday_exits', config=cfg,
               sector_source='explicit_map' if args.sector_map else 'none_all_unclassified',
               hashes=dict(centers=digest(args.centers), candidates=digest(args.candidates),
                           config=digest(args.config), script=digest(__file__), bars=hashes,
                           sector_map=digest(args.sector_map) if args.sector_map else None),
               skips=skips, signal_count=len(signals),
               limitations=['Post-hoc selection; reused periods; not independent validation',
                            'Opening sizing differs from operating reserved limit-order engine',
                            'No future-bar eligibility filter; sample may differ from PR106',
                            'Unknown sectors share one bucket; supplied sector maps may be current-day mappings',
                            'End-of-day equity drawdown, not intraday drawdown',
                            'Daily stop-first ordering is an assumption; adjustment unverified'],
               full_period_comparable=comparable,
               common_through=common, results=results)
    (args.output/'results.json').write_text(json.dumps(out, ensure_ascii=False, indent=2)+'\n')
    print(json.dumps({k: {x:r[x] for x in ('status','through','return_pct','fills','blocked')} for k,r in results.items()}, ensure_ascii=False))
    return 0 if comparable else 2

if __name__ == '__main__':
    raise SystemExit(main())
