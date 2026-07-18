@echo off
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0octane-start.ps1" --port=8100 %*
