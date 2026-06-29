@echo off
cd /d "%~dp0"
start "tempcare 프록시" /min python proxy_server.py
