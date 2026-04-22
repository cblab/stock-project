FROM python:3.12-slim

ENV PYTHONUNBUFFERED=1 \
    PIP_NO_CACHE_DIR=1 \
    PROJECT_ROOT=/app \
    YFINANCE_CACHE_DIR=/app/var/cache/yfinance

WORKDIR /app

RUN apt-get update \
    && apt-get install -y --no-install-recommends ca-certificates gcc g++ \
    && rm -rf /var/lib/apt/lists/*

COPY stock-system/requirements-intake.txt /tmp/requirements-intake.txt
RUN pip install --no-cache-dir -r /tmp/requirements-intake.txt

COPY stock-system ./stock-system

RUN mkdir -p /app/var/cache/yfinance

CMD ["python", "stock-system/scripts/run_watchlist_intake.py", "--mode=db"]
