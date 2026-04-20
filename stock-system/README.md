# Stock System

Minimal local pipeline:

1. manual ticker list from `config/settings.yaml`
2. OHLCV via `yfinance`
3. Kronos forecast via `repos/Kronos` and configured model paths
4. news via `yfinance`
5. FinGPT adapter with FinBERT fallback
6. weighted merge and reports under `runs/YYYY-MM-DD_HH-MM`

## Score model

Directional scores use `[-1, 1]`.

- Kronos raw score is the forecast return over the configured horizon.
- Kronos normalized score is `clamp(kronos_raw_score / kronos_return_scale, -1, 1)`.
- Sentiment raw score is the unweighted article label balance.
- Sentiment normalized score is the confidence-weighted article label balance.
- The merged score combines normalized Kronos and Sentiment scores using the
  configured weights in `config/settings.yaml`.

The current minimal default uses daily candles and a 3-trading-day Kronos
forecast horizon. The score is treated as current for 24 hours.

## ETF sentiment routing

Equities use direct ticker news sentiment. ETFs use `etf_context` mode:

- direct ETF news is kept as a secondary input
- holdings-lookthrough news supplies the first numeric context signal
- benchmark, region, sector, and macro profiles are carried as explicit context
  fields for later sector/index/macro modules

The first ETF implementation does not invent sector or macro scores. Those
profile blocks are transparent interfaces until real context-news modules are
added.

## FinGPT integration status

The adapter actively inspects these local paths:

- `repos/FinGPT/fingpt/FinGPT_Sentiment_Analysis_v1`
- `repos/FinGPT/fingpt/FinGPT_Sentiment_Analysis_v3`
- `repos/FinGPT/finogrid/fingpt_integration/sentiment/crypto_sentiment.py`

The current default run uses FinBERT because this workspace contains a local
FinBERT checkpoint, but no local FinGPT Llama/ChatGLM base model plus LoRA
sentiment checkpoint. Direct FinGPT inference can be enabled by setting
`fingpt_base_model_path` and `fingpt_lora_model_path` in `config/models.yaml`.

## SEPA / Minervi Phase-1 calibration

The SEPA model has two deterministic OHLCV layers:

- Structure Layer: market, stage, relative strength, base quality, volume,
  momentum, risk, and superperformance potential.
- SEPA / Minervini Execution Layer: VCP contraction quality, short-term
  microstructure, and breakout readiness.

The blended total score uses 72% Structure and 28% Execution. Execution is
large enough to matter, but hard structural failures remain separated from
late-entry or setup-quality warnings.

The traffic light separates structural failure from entry and execution risk
warnings:

- Hard triggers: market or data failure, price below the 200-DMA, 50-DMA
  below 200-DMA, 50-DMA structure break, 63-day momentum breakdown, lost base
  support, missing relative-strength leadership, and negative up/down volume.
- Soft warnings: unattractive stop distance, overextension from the 50-DMA,
  sharp recent momentum drawdown, deep or chaotic base, expanding base
  volatility, high ATR risk, distance from 52-week high, insufficient
  category history, low superperformance potential, weak/missing VCP
  contraction sequence, sloppy microstructure, and late or loose breakout
  readiness. Distribution pressure is reported as a soft warning unless it is
  accompanied by hard trend, support, momentum, or leadership failure.

Red is reserved for multiple hard structure failures, a hard failure with a
weak total score, or a very low total score. Strong leaders with no hard
failure but late-entry risk are Yellow instead of Red. Green requires a strong
score and no active hard triggers. Elite scores can remain Green with at most
minor, non-blocking execution notes; blocking setup, extension, volatility, or
distribution warnings keep the result Yellow.

Raw trigger ids remain in `detail_json`, but the UI displays deduplicated
warning summaries such as `Base zu tief oder unruhig`, `Entry spaet oder
ueberdehnt`, and `Setup nicht tight genug`.

Execution details are stored in `detail_json.execution_layer`; the main table
also stores `vcp_score`, `microstructure_score`, `breakout_readiness_score`,
`structure_score`, and `execution_score` for direct display and filtering.

The web UI includes `/buy-signal-matrix`, a consolidated table that joins the
latest Kronos/Sentiment run item with the latest SEPA snapshot per instrument.
The legacy `/signal-matrix` route remains as an alias. The Buy Signal Matrix
defaults to sorting by merged Kronos/Sentiment score and then SEPA total score.

## EPA / Exit & Risk Layer

EPA means Exit Point Analysis. It is the sell-side counterpart to SEPA and is
run as a separate deterministic DB-first job:

```powershell
python stock-system\scripts\run_epa.py --mode=db --source=portfolio
python stock-system\scripts\run_epa.py --mode=db --source=watchlist
python stock-system\scripts\run_epa.py --mode=db --source=all
python stock-system\scripts\run_epa.py --mode=db --source=all --tickers=LITE,COHR,ASML
```

EPA writes historical snapshots to `instrument_epa_snapshot` with one row per
instrument and `as_of_date`. It uses active DB instruments and respects the same
`portfolio`, `watchlist`, and `all` scope modes as SEPA.

The first EPA layer uses OHLCV, moving averages, ATR, relative strength versus
the market benchmark, and the latest SEPA snapshot as context. It does not use
fundamental data or ownership data.

Implemented EPA blocks:

- Failure Exit Score: failed setup pressure, 10/20-DMA loss, recent pullback,
  and heavy down-days after a setup.
- Trend Exit Score: 20/50/200-DMA loss, moving-average slope deterioration,
  momentum breakdown, and relative-strength breakdown.
- Climax / Overextension Score: extension from 20/50-DMA, vertical acceleration,
  volume surge, ATR and range expansion.
- Risk Management / Stop Quality: stop distance, trailing distance, volatility,
  and tightening need after short-term trend loss.

EPA actions are `HOLD`, `TIGHTEN_RISK`, `TRIM`, `EXIT`, and `FAILED_SETUP`.
Hard triggers can force `EXIT` or `FAILED_SETUP`; soft warnings drive
`TIGHTEN_RISK` or `TRIM` when risk is elevated but the structure has not fully
failed.

The web UI shows EPA on each instrument detail page under
`EPA / Exit & Risk Snapshot`. The new `/sell-signal-matrix` page shows the
sell-side matrix with EPA action, total risk, Failure, Trend Exit, Climax, Risk,
freshness, and instrument links. Buy and Sell matrices are intentionally kept
separate:

- Buy Signal Matrix: Kronos, Sentiment, Merged Score, SEPA Structure/Execution.
- Sell Signal Matrix: EPA Exit & Risk only.

## Start

```powershell
python -m pip install -r stock-system\requirements.txt
python stock-system\scripts\run_pipeline.py
```

The Kronos config uses the local `NeoQuasar/Kronos-base` checkpoint at
`E:/stock-project/models/kronos/models/Kronos-base`. `Kronos-base` uses the
`NeoQuasar/Kronos-Tokenizer-base` tokenizer, so `kronos_tokenizer_path` should
continue to point at
`E:/stock-project/models/kronos/tokenizers/Kronos-Tokenizer-base` for offline
runs.
