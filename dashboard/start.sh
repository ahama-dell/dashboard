#!/bin/bash
# 현재 폴더 경로로 이동
cd "$(dirname "$0")"

# 이미 실행 중인 프록시 서버가 있다면 강제 종료 후 재실행
PID=$(ps -ef | grep "proxy_server.py" | grep -v "grep" | awk '{print $2}')
if [ -z "$PID" ]; then
    PID=$(ps | grep "proxy_server.py" | grep -v "grep" | awk '{print $1}')
fi

if [ ! -z "$PID" ]; then
    echo "기존 프록시 프로세스($PID)를 종료합니다."
    kill -9 $PID
fi

# 프록시 서버 가동 (로그는 nohup.out에 기록됨, -u 옵션으로 실시간 로그 기록)
echo "프록시 서버를 시작합니다..."
python3 -u proxy_server.py > nohup.out 2>&1
