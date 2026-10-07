@echo off
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0start-local-database.ps1"
if errorlevel 1 exit /b 1
