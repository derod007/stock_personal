# Type1 per-trade chart atlas — descriptive protocol

Consumes saved baseline (T+1 opening) trades from the entry-delay research, with no account rerun or rule changes. KRX and Yahoo periods remain separate. All type1 signals are displayed; unresolved trades are not coded as losses. The original capital-selected recent subset is a separate viewer filter, not the universe for the analysis.

- Before: 60 completed OHLCV candles through T. No post-T inputs enter features or matching vectors.
- After: all 20 calendar sessions T+1..T+20, with actual stored exit marked. Post-exit candles are shaded and are not portfolio P&L.
- Features: 5/20/59 close return; 60-candle high-low range divided by T close; close position within 20-candle high-low range; mean volume10/mean volume50; simple mean of last14 true ranges divided by T close (not Wilder ATR); next-open gap. The gap is available at execution, not the previous close.
- Prehistory with invalid OHLC or reference-price discontinuity: features and matching unavailable, but trade retained. Source file hashes verified using original manifests.
- Matching: L2-normalized 60 closes and 60 volumes concatenated, equal weights, Euclidean distance, same period, different symbol, opposite realized net sign. This is a retrospective case comparison, not an entry filter, independent validation, or evidence that the pair is objectively similar. No same-date or same-time-to-rise constraint. Every eligible trade gets its nearest opposite, without a fitted cutoff. Matches may be reused. Distance shown.
- Path: excursion only through the day BEFORE exit. Including the exit day's high can incorrectly assume it preceded a stop. Entry-day exits therefore have a conservative 0 excursion lower bound, not evidence that price never rose. For stops, later +15% requires a strictly later session, within original T+20. Missing/invalid candles or reference events make that later-window classification unresolved. Same-day rebound remains unknown.
- 3% excursion is a descriptive scale, not a proposed exit rule or optimized threshold; also report 1/3/5% counts. Later rebound and earlier rise can overlap; they are not exclusive categories.
- 1/2/3-candle closing returns describe the market path, even after a trade exited; they are not achievable holding returns or pre-entry predictors.
- Outcome comparison: net positive versus net negative closed trades, feature medians and P(win feature > loss feature), ties half. Not win-prediction accuracy. No pooling of sources, statistical significance claim, optimization, new strategy, or return forecast. Repeated symbols and market regimes can explain associations.

Viewer is a local standalone HTML file, with full private OHLC data embedded. Public PR contains only generic scripts, HTML template, synthetic tests and this protocol. No private market rows or results.
