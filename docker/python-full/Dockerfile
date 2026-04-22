FROM python:3.12-slim

ENV PYTHONUNBUFFERED=1 \
    PIP_NO_CACHE_DIR=1 \
    PROJECT_ROOT=/app \
    MODELS_DIR=/app/models \
    KRONOS_DIR=/app/repos/Kronos \
    FINGPT_DIR=/app/repos/FinGPT \
    HF_HOME=/app/.hf-cache \
    YFINANCE_CACHE_DIR=/app/var/cache/yfinance

WORKDIR /app

RUN apt-get update \
    && apt-get install -y --no-install-recommends ca-certificates gcc g++ git \
    && rm -rf /var/lib/apt/lists/*

COPY stock-system/requirements.txt /tmp/requirements.txt
RUN pip install --no-cache-dir --default-timeout=300 --index-url https://download.pytorch.org/whl/cpu "torch==2.6.0+cpu" \
    && grep -v -E '^torch([=<> ].*)?$' /tmp/requirements.txt > /tmp/requirements-pipeline.txt \
    && pip install --no-cache-dir -r /tmp/requirements-pipeline.txt

COPY stock-system ./stock-system

RUN mkdir -p /app/models /app/repos/Kronos /app/repos/FinGPT /app/.hf-cache /app/var/cache/yfinance

CMD ["python", "stock-system/scripts/run_pipeline.py", "--mode=db", "--source=all"]
