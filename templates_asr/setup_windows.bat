@echo off
echo ===================================================
echo   Reading Assessment Python ASR Setup (Windows)
echo ===================================================

if not exist venv (
    echo Creating Python virtual environment...
    python -m venv venv
)

echo Installing dependencies...
call venv\Scripts\activate.bat
pip install -r requirements.txt

echo.
echo ===================================================
echo   Setup Complete! Use run_windows.bat to start.
echo ===================================================
