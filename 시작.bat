@echo off
cd /d "%~dp0"
if exist dashboard\proxy_server.py (
    start "tempcare 프록시" /min python dashboard\proxy_server.py
) else (
    start "tempcare 프록시" /min python proxy_server.py
)

