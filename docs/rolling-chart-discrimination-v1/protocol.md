# Rolling D-20..D chart discrimination

Exploration on already examined periods, not independent holdout. Every decision date D uses only its past candles and membership. Universe is PR132 rolling20 eligible observations: union of TOP100 over D-19..D, with first19 membership sessions excluded and22 valid historical candles inherited. No PR132 precursor/score/type1 filter.

Chart window: D-20..D inclusive (21 completed candles). Six features fixed before this run:
1 return20=closeD/close(D-20)-1.
2 drawdown20=min(close/cumulative_max_close-1) in the21 candles; more negative means deeper decline.
3 low_change10=minlow(D-9..D)/minlow(D-19..D-10)-1.
4 distance_high20=closeD/maxhigh(D-19..D)-1.
5 volume_ratio5_15=meanvolume(D-4..D)/meanvolume(D-19..D-5).
6 range_ratio5_15=mean((high-low)/close over D-4..D)/mean over D-19..D-5.
Zero denominator/nonfinite => feature unknown, excluded from multifeature analysis and reported, not a failure outcome. All six must be known to keep a common denominator.

Future label is unconstrained high >=1.15*D+1 open anywhere through D+20, not next-day+10 label of PR132. Different stocks need not rise on the same date or after equal delays. Require complete valid20-bar path under PR132, including positive entry/exit volume, for both label and economic comparison; preserve all unknown outcomes. Record first hit offset1..20. It is a market high label, not a tradable realized return. Economic outcome is unchanged PR132 next-open to20-close net with per-side0.15% model cost, no stop/target. Also label+15 cases' eventual losses are disclosed.

Fit six 1/3,2/3 quantile boundaries in earliest KRX eligible feature-known observations, without future labels. Freeze all cuts. For each feature report low/middle/high separately in all periods. Previous period rise/nonrise medians show overlap descriptively. Select exactly one extreme-bin feature (12 possible) on KRX only by maximum same-date high-hit-rate difference vs complement, requiring>=500 complete rows,>=60dates and>=50symbols; tie by fixed feature order then low before high. If none positive, retain no candidate. Outcomes are deliberately used for earliest-period selection; disclose selection bias. No second feature, conjunction, boundary/horizon tuning or alternative picking based on later results.

Apply frozen chosen feature/bin to both later periods. Report same-date complement hit-rate difference AND20-net difference, absolute20-net, counts, unknowns, date-equal primary and trade/symbol-equal sensitivity. Nonoverlap selected by decision dates before outcomes, earliest signal per symbol then suppress next20sessions; compare same-date all eligible. Distinguish label discrimination from profitability. A useful lead requires positive hit-rate difference in both later periods; tradable evidence additionally requires positive20-net and complement advantage in both, direction preserved nonoverlap. Neither means operational approval or independent validation.

Show actual rising and nonrising charts in each period, with pre-D/future separated. Pick a rising example near the selected subset's median past normalized shape, then its nearest nonrising past shape; all similarity inputs pre-D only, labels used only to illustrate opposing outcomes. No claim these examples are representative of performance. Corporate-action adjustments unverified, daily opening fills unverified. No raw edits, account replay, operational changes, or merging.
