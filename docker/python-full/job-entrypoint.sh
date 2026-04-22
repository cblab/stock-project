#!/bin/sh
set -eu

case "${1:-intake}" in
    intake)
        shift || true
        exec python stock-system/scripts/run_watchlist_intake.py --mode=db "$@"
        ;;
    sepa)
        shift || true
        exec python stock-system/scripts/run_sepa.py --mode=db --source=all "$@"
        ;;
    epa)
        shift || true
        exec python stock-system/scripts/run_epa.py --mode=db --source=all "$@"
        ;;
    pipeline)
        shift || true
        exec python stock-system/scripts/run_pipeline.py --mode=db --source=all "$@"
        ;;
    python|python3|sh|bash)
        exec "$@"
        ;;
    *)
        echo "Unknown job command: $1" >&2
        echo "Use one of: intake, sepa, epa, pipeline, or pass an explicit python command." >&2
        exit 64
        ;;
esac
