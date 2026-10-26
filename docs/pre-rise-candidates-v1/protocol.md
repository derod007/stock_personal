# Pre-rise candidate diagnostic v1

Exploratory follow-up after PR131 and next-open decomposition, not independent validation. No threshold optimization or operational changes. Assess this fixed precursor, not whether all chart prediction is possible.

Decision time is each session T close. Two separate universes: (1) saved T TOP100; (2) union of saved TOP100 from T−19..T inclusive within each source segment. Do not carry membership across segment boundaries. Rolling window first19 segment sessions have incomplete lookback and are excluded from primary rolling statistics. Daily universe has no such exclusion. Missing candles are unknown, never forward filled. A symbol's later TOP100 appearance cannot establish earlier membership.

Fixed precursor: exact PR131 strong AND contraction AND NOT breakout. strong = stock20-session return > own index20-session return. contraction = meanTR(T−5..T−1)<meanTR(T−20..T−6). breakout = closeT>maxhigh(T−5..T−1). Require22 consecutive valid completed nonsynthetic OHLCV T−21..T. T contraction exclusions are inherited deliberately, not optimized. No volume, daily-rise cap, market-trend, sector or new score filter.

Primary forecast label: close(T+1)/close(T)−1 >=10%. It uses future only as a label; never to select. Unknown future is not negative. Also report whether PR131 breakout observations had known membership/eligible features/precursor on their immediately preceding session. Coverage of +10% events is explicitly limited to next-session saved TOP100, not all listed equities.

Economic outcomes: enter T+1 open, exit T+1/T+5/T+20 close. Costs buy1.0015/sell0.9985. Require valid full path and positive entry/exit volume, otherwise unknown. No stop/target, no capital limits, no actual fills claim. Segment-end truncated windows unknown. Separate overnight gap from first-day return. Outcome horizons each use their own complete denominator, with unknown counts reported.

Report all eligible vs precursor, date-equal means primary, trade-equal supplementary. Use same-date complement for return difference and next-day label precision difference. Recall denominators are outcome-known observations/events, not future-filtered candidate membership. Nonoverlap: signal-only earliest per symbol, suppress next20sessions including exit day; then evaluate outcomes, compare with same-date eligible all. No re-entry optimization.

A potentially useful lead requires in all3periods >=30 complete20 outcomes,>=20symbols,>=10dates, positive date-equal20 net, positive same-date complement20 difference, and positive same-date next-day jump-rate difference. Nonoverlap must preserve positive20 net and positive same-date-all difference. Passing would still require fresh independent data; failing is not a universal rejection of chart trading. Two universes are both reported, not selected posthoc.

Corporate-action adjustment remains unverified, daily data do not certify executable opening fills or intraday sequence, and repeated observations have dependence. Price source segments remain separate. Keep all outliers and disclose unknowns. Public PR contains generic code/protocol/synthetic tests only; outputs remain private.
