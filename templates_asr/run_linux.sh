#!/bin/bash
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
cd "$SCRIPT_DIR"

echo "Starting Python ASR Service on Port 8000 (Linux)..."
if [ -f "./venv/bin/python" ]; then
    ./venv/bin/python service.py
else
    python3 service.py
fi
