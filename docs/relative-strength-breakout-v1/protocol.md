# Relative strength, contraction and breakout — fixed before outcome calculation

Purpose: stagewise stock-selection diagnostic, independent of original recommendation scores, type1, Elliott or Fibonacci. Not a portfolio or executable stop/target strategy. Prior examined periods are exploratory, not new independent validation.

Universe: exact saved dated TOP100 membership from PR119 observations, including observations that original score could not evaluate. Require all22 consecutive index-calendar sessions T−21..T to have valid completed non-synthetic stock OHLCV; zero historical volume is allowed. Compare only each symbol's own KOSPI or KOSDAQ index, using supplied Naver index CSV unchanged. No official-index claim or sector filter. No market-uptrend gate is included in this first selection diagnostic.

Fixed stages:
- all: all eligible dated TOP100 observations.
- strong: stock close(T)/close(T−20)−1 > respective index return over the exact same dates. Absolute stock return need not be positive.
- contraction: strong AND mean True Range over T−5..T−1 < mean TR over T−20..T−6. TR=max(high−low,abs(high−previous close),abs(low−previous close)). T excluded from contraction window. Equality fails.
- breakout: contraction AND close(T)>max(high over T−5..T−1). Equality fails.
- Volume ratio over the same5/15 historical windows recorded only, never used as a filter.

Outcome: entry T+1 open, exit T+5/T+10/T+20 close, buy cost multiplier1.0015 and sell0.9985. Entry/exit volume must be positive for modeled execution; missing/invalid bars anywhere through horizon produce unknown. No prices across dataset segment-end are consumed even if index continues. Raw high/low excursions over the future window are market paths, not exact held intraday extremes. No stop/target and no claimed real fills. Fees/slippage simplified, consistent across all stages.

Report each stage vs all and vs same-date complement; dates with no selected or complement cannot contribute a daily paired difference. Primary horizon20. Use date-equal means, including stock-weighted mean and symbol-equal mean, eligible/complete/unknown, unique dates/symbols, positive days, monthly paired differences. Repeat-removal sensitivity: per-stage/per-symbol keep first qualifying observation, suppress next20 sessions including exit-day, using signal dates only (never outcomes); compare against all eligible same-date controls. No identical dates or identical time-to-rise required across different stocks, only same-date controls for market-condition adjustment.

Research gate, not operational adoption: primary breakout sample>=30 completed observations,>=20 symbols,>=10 dates, positive date-equal net and positive same-date complement difference in EACH of three source periods; report whether nonoverlap sensitivity preserves these directions. If too few, inconclusive. No tuning if gate fails. Keep price-source/corporate-action adjustment uncertainty. Official holiday status of source gaps remains unconfirmed; require equality with previously stored research calendars and never fill gaps.
