# Initial composite score selection diagnostic v1

Frozen before computing forward-return results, 2026-10-23. Exploratory reuse of previously examined history, not an independent validation. No trading/account execution.

Input: stored original-composite-study-v1 observations and its prepared manifest/bars. Verify observation SHA256 against the previous artifact file-hashes and all loaded price files against the prepared manifest. Scores/actions are never recomputed. Original score modules are frozen at e39d0f38a3c2dedc7258062281ed5ac317fb1156.

Units: each evaluated (segment, signal date, symbol), including repeated appearances. Blocked evaluations remain excluded with their saved reasons. Fixed score bins: <40, 40–59, 60–69, 70–84, >=85. Two prespecified contrasts: score >=70 vs <70; action add_on_pullback vs all other evaluated actions. Candidates include signals suppressed in the prior execution study, not just filled trades.

Outcome: raw percentage change from T+1 open to T+h close for h=5,10,20 market sessions. The entry day is holding session 1. No stop, target, limit-order selection, costs, cash allocation or portfolio simulation. Use exact market dates from each segment calendar; require valid completed OHLCV at every session T+1..T+h. Missing/invalid sessions and segment-end shortages are unknown, never zero or losses; no cross-segment price stitching. Corporate actions and price adjustment remain unverified.

For each contrast, include only dates with at least one valid outcome in BOTH groups at that horizon. Compute per-date group means, medians and positive-return proportions, then equally weight dates. The main statistic is the mean of within-date mean-return differences in percentage points. Also report each group's pooled median/win proportion and observations. There is no minimum sample exclusion after seeing outcomes; always show the paired-date count.

Report overall, by signal year and by price segment. Overall statistics are descriptive because sources differ. Symbol sensitivity: for each observation subtract its same-day opposing-group mean; average these excesses within symbol, then equally across symbols, separately for each side. Nonoverlap sensitivity: greedily retain each symbol's earliest evaluated signal, then the next strictly more than 20 market sessions later, determined WITHOUT outcome availability, score bin or recommendation status; recompute same-date contrasts, flag sparse pairs.

Score bins report raw and same-day rest-of-universe excess means with pooled median and positive-return proportion. This describes ordering; never choose new thresholds from favorable results. All horizons and all groups remain in the report.

Interpretation: assess sign consistency across horizons, years, segments, symbol sensitivity and nonoverlap. A positive pooled result alone is insufficient. Negative comparisons across years/segments support retiring the current selection claim; mixed/sparse evidence is inconclusive. These are descriptive diagnostics, not significance tests or proof of future profitability. Do not search replacement parameters, infer causal exit failures, or recommend deployment from this diagnostic.

Outputs: private observations with outcomes/missing reasons, paired-date comparisons, summary and report, input/source/protocol hashes. Code and synthetic tests only in GitHub; no private prices or results. No modification to original data, existing ledgers, production, settings or schedules.
