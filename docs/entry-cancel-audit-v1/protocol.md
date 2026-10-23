# Entry cancellation chronology audit v1

Read only saved initial-composite study attempts; no account rerun, score change, original result replacement or operational patch. Fixed before aggregation of audit outcomes.

Verify saved trades and referenced prepared price files by SHA256. Examine only cancelled_before_entry attempts; follow original order TTL (3 exact segment calendar sessions), preserving prior cancellation precedence and checking that no earlier limit fill was possible.

Classify first cancelling bar in this order: open <= stop; open >= target; high >= target with stop < open <= limit; remaining high >= target with open > limit and low <= limit; high >= target without limit touch. For open <= limit, the existing modeled opening fill is min(limit, open*1.0005). If low > stop, entry-before-target chronology is identified under the daily-bar opening-fill model. If low <= stop, stop/target order remains unknown; report both outcomes and conservative stop-first scenario. For open > limit with both limit and target touched, the entry/target ordering remains unknown, do not assume profitable execution. Real auction fill and intraday sequencing cannot be established from daily OHLC.

Keep existing closed trades exactly fixed. Add only the opening-fill cases to a frozen-attempt sensitivity table, separately showing certain target-only cases, unknown double-touch cases, conservative stop-first and target-first alternatives for the latter. All newly considered opening cases finish on their cancelling bar, so do not extend holding past the original terminal date. Do not revive suppressed signals or recompute account allocation. This does not establish performance of a complete repaired strategy.

Use original plan stop/target, fee 10bp per side, stop exit min(stop,open)*0.9995, target exit target, and per-trade independent KRW10,000,000 integer-quantity budget. Net return formula unchanged. Do not turn ambiguous cancellations into known trades or infer that all cancellations were errors. Report segment splits and ambiguity counts; retain originals and unknown positions. Output private audit evidence and summary; generic code/tests via unmerged PR only.
