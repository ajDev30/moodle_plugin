#!/bin/bash
echo "==================================================="
echo "  Reading Assessment Python ASR Setup (Linux)"
echo "==================================================="

SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
cd "$SCRIPT_DIR"

if [ ! -d "venv" ]; then
    echo "Creating Python virtual environment..."
    python3 -m venv venv || virtualenv venv
fi

echo "Installing dependencies..."
./venv/bin/pip install -r requirements.txt

echo ""
echo "==================================================="
echo "  Setup Complete! Use run_linux.sh to start."
echo "==================================================="
