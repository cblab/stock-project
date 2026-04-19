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

## Start

```powershell
python -m pip install -r stock-system\requirements.txt
python stock-system\scripts\run_pipeline.py
```

The Kronos config uses the local `Kronos-small` model and the Hugging Face
tokenizer id `NeoQuasar/Kronos-Tokenizer-base`. For a fully offline run, place
that tokenizer locally and change `kronos_tokenizer_path`.
