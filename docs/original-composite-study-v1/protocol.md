# Original composite score — research protocol

This is an independent-trade diagnostic, not a portfolio replay or the first historical application release. Original score snapshot: e39d0f38a3c2dedc7258062281ed5ac317fb1156 (2026-09-03, first version containing all requested components). Execution/data-validation snapshot: be02d7c75d3d466e5c56e868c0e1e90dbac5ccf2. Current SpikeDump differs from the original; the later close/support correction is intentionally not loaded. It directly calls the unchanged FeatureEngine and AccountPlaybook(custom) containing all user-specified original scoring components. No newer pattern entry, type1, bottom-wave, recovery gate, RR minimum, or half-risk protection is added.

## Fixed before aggregate results

- Stored daily TOP100 only; existing KRX selector reconstructs missing membership from full raw markets. Never use a future symbol union as the daily membership.
- Only completed bars through that day's close; rolling two calendar years of available history. Require 120 valid bars so the horizontal-level lookback is populated. This is a research warm-up restriction, not an original score condition. No 2022 input requested.
- Existing PaperQuality checks apply. Missing/invalid observations are unknown, not zero scores. No market-transfer stitching or price adjustment is invented.
- Original AccountPlaybook `add_on_pullback` is the only entry action (70+, higher-low, flush, and original earlier guards). `watchlist_buy_zone` remains an observation, not an order.
- Limit = original entry-zone midpoint, stop = original invalidation, target = original first target. Invalid level ordering is recorded rather than repaired.
- Existing TradeSimulator: order activates after signal, TTL 3 sessions, holding 20 bars, fixed stop/target; fee 10bp per side and modeled slippage 5bp. No separate tax model. These execution assumptions operationalize the original advisory score; they are not proof of identical old live operation.
- A symbol cannot open overlapping orders/positions in a segment. At an unresolved path, later entries in that symbol/segment stay suppressed. Exit-day signals stay suppressed conservatively.
- Price segments remain separate. No positions or prices are spliced across source/dataset boundaries. Segment-end positions remain unresolved, not forced-liquidated or valued at zero. No future prices outside the segment are used.
- Future missing/invalid OHLCV makes the remaining path unresolved. Completed earlier exits remain valid under the stored-price model. Source corporate actions/adjustments are unverified and must be disclosed; no unsupported corrections.
- Fixed budget KRW 10,000,000 per trade, integer quantity including buy fee. Sum of trade cash P&L is not portfolio profit, compound return, or a single-account equity curve. No capital, sector, or concurrent-position limit is modeled.
- Holding-bar high/low excursions include the full entry/exit bars; daily data cannot establish whether every extreme happened while held. Do not call them exact MFE/MAE.
- All rows and outcomes remain in private deliverables. Repo contains generic research code/protocol/tests only. No operating code/config/scheduler/ledger writes or account reruns.

## Run

Use a fresh output directory at every step. Inputs are the supplied KRX archives and two Yahoo bars archives; the preparation script does not download market data.

```
python docs/research/original_composite_prepare.py --inputs PRIVATE_ARCHIVE_DIR --output PRIVATE_PREP_DIR
ORIGINAL_SCORE_ROOT=PRIVATE_ORIGINAL_SCORE_DIR php -d memory_limit=1G docs/research/OriginalCompositeStudy.php PRIVATE_PREP_DIR PRIVATE_RESULT_DIR
```

Freeze FeatureEngine, AccountPlaybook, AccountProfile, HorizontalLevels, NoramuLesson1, NoramuTopPattern, SpikeDump, ThemeSmell from the original score commit into PRIVATE_ORIGINAL_SCORE_DIR before execution. Original modules are loaded before the runtime autoloader.
