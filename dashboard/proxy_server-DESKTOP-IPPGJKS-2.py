"""
tempcare.kr → 로컬 프록시 서버
────────────────────────────────────────────────────────────────
실행: python proxy_server.py
접속: http://localhost:8765/api/data

대시보드(temphumid_dashboard.html)가 이 서버에서 데이터를 읽습니다.
"""

import http.server
import json
import os
import threading
import time
from datetime import datetime, timedelta

try:
    import requests
except ImportError:
    print("[ERROR] requests 모듈이 없습니다. 아래 명령을 먼저 실행하세요:")
    print("   pip install requests")
    exit(1)

# ════════════════════════════════════════════════════
#  ★ 설정 — 여기만 수정하세요
# ════════════════════════════════════════════════════
COMPANY_ID   = "jaaltec"           # 회사 ID
USERNAME     = "jaaltec"           # tempcare.kr 로그인 아이디
PASSWORD     = "jaaltec@"          # tempcare.kr 로그인 비밀번호
INTERVAL_MIN = 10                   # 데이터 간격(분)
FETCH_DAYS   = 3                   # 최근 며칠치 데이터
PORT         = 8765                # 로컬 서버 포트

# ── 브라우저 세션 직접 사용 (자동 로그인 실패 시)
# Chrome F12 → Application → Cookies → www.tempcare.kr → JSESSIONID 값 붙여넣기
# 세션이 만료되면 브라우저에서 다시 복사 후 재시작
MANUAL_SESSION = "6C2FB58DBEBE552DF9B4486B5C68D73D"   # 예: "466BD43955C1D0F6E550A6501B46BE8D"

# session.txt가 있으면 파일에서 로드하여 MANUAL_SESSION 덮어쓰기
_base_dir = os.path.dirname(os.path.abspath(__file__))
_session_file = os.path.join(_base_dir, "session.txt")
if os.path.exists(_session_file):
    try:
        with open(_session_file, "r", encoding="utf-8") as _f:
            _saved_session = _f.read().strip()
            if _saved_session:
                MANUAL_SESSION = _saved_session
    except Exception as _e:
        print(f"[WARN] session.txt 로드 에러: {_e}")
# ════════════════════════════════════════════════════

BASE_URL  = "http://www.tempcare.kr"
API_URL   = f"{BASE_URL}/api/{COMPANY_ID}/report/storage/data/logs"
LOGIN_URL = f"{BASE_URL}/login"

# sensorTypeSeq → 종류 매핑 (API 분석 결과)
SENSOR_TEMP  = 3    # rtemp (실내 온도)
SENSOR_HUMID = 26   # rhum  (습도)

sess = requests.Session()
sess.headers.update({
    "User-Agent":       "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36",
    "X-Requested-With": "XMLHttpRequest",
    "Referer":          f"{BASE_URL}/{COMPANY_ID}/dashboard",
    "Accept":           "*/*",
})

# 전역 캐시
storage_room_map = {}   # {storageSeq(int): room_number(1~6)}
cached_rows      = []   # [{time, room1_temp, room1_humid, ...}, ...]
cache_updated_at = None
login_ok         = False


# ────────────────────────────────────────────────────
#  로그인  (API 응답으로 성공 여부 검증)
# ────────────────────────────────────────────────────
def _verify_api():
    """실제 API 호출로 로그인 상태 확인"""
    today = datetime.now().strftime("%Y-%m-%d")
    try:
        r = sess.get(API_URL, params={
            "dateFrom": today, "hourFrom": "00",
            "dateTo":   today, "hourTo":   "23",
            "intervalMinutes": INTERVAL_MIN,
            "storageSeq": "", "sensorTypeSeq": "",
            "size": 1, "page": 0,
        }, timeout=15)
        if r.status_code == 200:
            j = r.json()
            return "content" in j   # JSON 정상 반환이면 인증됨
    except Exception:
        pass
    return False

def do_login():
    global login_ok
    import re
    login_page = f"{LOGIN_URL}?companyId={COMPANY_ID}"
    print(f"[{_now()}] 로그인 시도: {USERNAME}@{COMPANY_ID}")

    try:
        # Step 1: 로그인 페이지 GET (세션 쿠키 초기화)
        sess.cookies.clear()
        sess.headers.update({"Accept": "text/html,application/xhtml+xml,*/*"})
        page = sess.get(login_page, timeout=15, allow_redirects=True)
        sess.headers.update({"Accept": "*/*"})

        # CSRF 토큰 추출 (있을 경우)
        csrf_token = None
        for pat in [
            r'name=["\']_csrf["\'][^>]*value=["\']([^"\']+)["\']',
            r'value=["\']([^"\']+)["\'][^>]*name=["\']_csrf["\']',
            r'"_csrf"\s*:\s*"([^"]+)"',
        ]:
            m = re.search(pat, page.text)
            if m:
                csrf_token = m.group(1)
                print(f"[{_now()}]   CSRF 토큰 확인됨")
                break

        # Step 2: POST 로그인
        data = {"id": USERNAME, "password": PASSWORD}
        if csrf_token:
            data["_csrf"] = csrf_token

        r = sess.post(
            login_page,
            data=data,
            allow_redirects=True,
            timeout=15,
            headers={
                "Referer":      login_page,
                "Content-Type": "application/x-www-form-urlencoded",
                "Accept":       "text/html,application/xhtml+xml,*/*",
            },
        )
        sess.headers.update({"Accept": "*/*"})

        print(f"[{_now()}]   POST 결과: status={r.status_code}  url={r.url}")
        print(f"[{_now()}]   쿠키: {dict(sess.cookies)}")

        # Step 3: API 실제 호출로 인증 검증
        if _verify_api():
            print(f"[{_now()}] [OK] 로그인 성공 (API 인증 확인)")
            login_ok = True
            return True

        # 실패 원인 진단
        if "error" in r.url.lower() or "login" in r.url.lower():
            print(f"[{_now()}] [ERROR] 로그인 실패 - 아이디/비밀번호를 확인하세요")
        else:
            print(f"[{_now()}] [ERROR] 로그인 후 API 접근 거부 (권한 문제)")

    except Exception as e:
        print(f"[{_now()}] [ERROR] 로그인 오류: {e}")

    login_ok = False
    return False


# ────────────────────────────────────────────────────
#  데이터 수집 (페이지네이션 자동 처리)
# ────────────────────────────────────────────────────
def fetch_all_pages(date_from: str, date_to: str) -> list:
    all_content = []
    page = 0
    PAGE_SIZE = 2000

    while True:
        try:
            r = sess.get(API_URL, params={
                "dateFrom":        date_from,
                "hourFrom":        "00",
                "dateTo":          date_to,
                "hourTo":          "23",
                "intervalMinutes": INTERVAL_MIN,
                "storageSeq":      "",
                "sensorTypeSeq":   "",
                "size":            PAGE_SIZE,
                "page":            page,
            }, timeout=30)
        except Exception as e:
            print(f"[{_now()}] 네트워크 오류: {e}")
            break

        # 세션 만료 → 재로그인
        if r.status_code in (302, 401, 403):
            print(f"[{_now()}] 세션 만료, 재로그인...")
            if do_login():
                continue
            break

        try:
            data = r.json()
        except Exception:
            print(f"[{_now()}] JSON 파싱 실패 (status={r.status_code})")
            break

        content = data.get("content", [])
        all_content.extend(content)

        total = data.get("totalElements", 0)
        is_last = data.get("last", True)
        fetched = len(all_content)
        print(f"[{_now()}]   페이지 {page} - {len(content)}건 수신 ({fetched}/{total})")

        if is_last or not content:
            break
        page += 1

    return all_content


# ────────────────────────────────────────────────────
#  storageSeq → 호실 번호 자동 매핑
# ────────────────────────────────────────────────────
def build_room_map(content: list):
    global storage_room_map
    seqs = sorted(set(r["storageSeq"] for r in content if "storageSeq" in r))
    storage_room_map = {seq: i + 1 for i, seq in enumerate(seqs)}
    room_info = ", ".join(f"{seq}->{n}호" for seq, n in storage_room_map.items())
    print(f"[{_now()}] 양생실 매핑: {room_info}")


# ────────────────────────────────────────────────────
#  API 응답 → 대시보드 형식 변환
#  {time, room1_temp, room1_humid, room2_temp, ...}
# ────────────────────────────────────────────────────
def transform(content: list) -> list:
    groups = {}
    for row in content:
        t_raw = row.get("loggedTime", "")
        if not t_raw:
            continue
        t_key = t_raw[:16]          # "2026-06-12 15:34"
        t_val = t_key + ":00"       # "2026-06-12 15:34:00"

        if t_key not in groups:
            groups[t_key] = {"time": t_val}

        rn  = storage_room_map.get(row.get("storageSeq"))
        seq = row.get("sensorTypeSeq")
        val = row.get("measuredValue")

        if rn is None or val is None:
            continue

        if seq == SENSOR_TEMP:
            groups[t_key][f"room{rn}_temp"]  = round(float(val), 1)
        elif seq == SENSOR_HUMID:
            groups[t_key][f"room{rn}_humid"] = round(float(val), 1)

    rows = sorted(groups.values(), key=lambda x: x["time"])
    return rows


# ────────────────────────────────────────────────────
#  캐시 갱신
# ────────────────────────────────────────────────────
def refresh_cache():
    global cached_rows, cache_updated_at, login_ok
    now     = datetime.now()
    d_to    = now.strftime("%Y-%m-%d")
    d_from  = (now - timedelta(days=FETCH_DAYS - 1)).strftime("%Y-%m-%d")

    print(f"[{_now()}] 데이터 수집 시작 ({d_from} ~ {d_to})")
    content = fetch_all_pages(d_from, d_to)

    if not content:
        print(f"[{_now()}] [WARN] 수집된 데이터 없음")
        login_ok = False
        return

    build_room_map(content)
    cached_rows      = transform(content)
    cache_updated_at = now
    login_ok         = True
    print(f"[{_now()}] [OK] 캐시 갱신 완료 - {len(cached_rows)}개 포인트")


# ────────────────────────────────────────────────────
#  백그라운드 자동 갱신 스레드
# ────────────────────────────────────────────────────
def auto_refresh_loop():
    global login_ok, MANUAL_SESSION
    
    last_failed_key = None
    print(f"[{_now()}] 백그라운드 세션 유지 & 자동 갱신 스레드 가동")

    while True:
        # 1. session.txt에서 주기적으로 최신 세션 키(JSESSIONID)가 저장되었는지 감지하여 동기화
        if os.path.exists(_session_file):
            try:
                with open(_session_file, "r", encoding="utf-8") as f:
                    saved_session = f.read().strip()
                    if saved_session and saved_session != MANUAL_SESSION:
                        MANUAL_SESSION = saved_session
                        sess.cookies.set("JSESSIONID", MANUAL_SESSION, domain="www.tempcare.kr", path="/")
                        print(f"[{_now()}] [KEY] session.txt로부터 새 세션 키가 자동 동기화되었습니다: {MANUAL_SESSION[:8]}...")
            except Exception as e:
                print(f"[{_now()}] [WARN] session.txt 감지 중 오류: {e}")

        # 2. 세션 검증 또는 자동 로그인 시도
        current_cookie = sess.cookies.get("JSESSIONID")
        
        # MANUAL_SESSION은 채워져 있으나 쿠키가 없는 경우 동기화
        if MANUAL_SESSION.strip() and not current_cookie:
            sess.cookies.set("JSESSIONID", MANUAL_SESSION.strip(), domain="www.tempcare.kr", path="/")
            current_cookie = MANUAL_SESSION.strip()

        if current_cookie:
            # 이미 검증에 실패했던 키라면 중복 호출 방지
            if current_cookie == last_failed_key:
                login_ok = False
            else:
                if _verify_api():
                    if not login_ok:
                        print(f"[{_now()}] [OK] 세션이 유효합니다. 백그라운드 데이터 수집 및 세션 유지를 시작합니다.")
                    login_ok = True
                    last_failed_key = None # 성공했으므로 초기화
                else:
                    if login_ok or last_failed_key != current_cookie:
                        print(f"[{_now()}] [WARN] 세션이 만료되었거나 올바르지 않습니다. (새 세션 키 대기 중...)")
                    login_ok = False
                    last_failed_key = current_cookie
        else:
            # 쿠키가 없고 수동 세션도 없는 경우 자동 로그인 시도
            if do_login():
                login_ok = True
                last_failed_key = None
            else:
                login_ok = False

        # 3. 로그인 상태(세션 유효)인 경우 캐시 갱신 (및 세션 연장)
        if login_ok:
            try:
                refresh_cache()
            except Exception as e:
                print(f"[{_now()}] 갱신 오류: {e}")
            time.sleep(INTERVAL_MIN * 60)
        else:
            # 세션이 비정상일 때는 60초 후 session.txt를 재검사하여 새로운 세션이 등록되는지 대기
            # (last_failed_key가 설정되어 있으면 _verify_api()를 중복 요청하지 않으므로 부하가 없습니다)
            time.sleep(60)



# ────────────────────────────────────────────────────
#  HTTP 핸들러
# ────────────────────────────────────────────────────
class ProxyHandler(http.server.BaseHTTPRequestHandler):

    def do_OPTIONS(self):
        self._cors(); self.end_headers()

    def do_GET(self):
        global MANUAL_SESSION, login_ok
        path = self.path.split("?")[0]
        
        # dashboard 하위 폴더 분리 처리에 따른 경로 보정
        if path.startswith("/dashboard/"):
            path = "/" + path[11:]
            
        if path == "/save_data.php":
            # GET 파라미터 파싱 (이력 조회용)
            from urllib.parse import parse_qs, urlsplit
            query = urlsplit(self.path).query
            params = parse_qs(query)
            action = params.get("action", [None])[0]
            project = params.get("project", [None])[0]
            
            if action == "list" and project:
                import glob
                import re
                
                project = re.sub(r'[^a-zA-Z0-9_-]', '', project)
                
                base_dir = os.path.dirname(os.path.abspath(__file__))
                pattern = os.path.join(base_dir, f"data_{project}_*.json")
                found = glob.glob(pattern)
                files = []
                if found:
                    found.sort(reverse=True) # 최신순
                    files = [os.path.basename(f) for f in found]
                
                body = json.dumps({
                    "success": True,
                    "files": files,
                    "project": project
                }, ensure_ascii=False).encode("utf-8")
                
                self._cors()
                self.send_header("Content-Type", "application/json; charset=utf-8")
                self.send_header("Content-Length", str(len(body)))
                self.end_headers()
                self.wfile.write(body)
                return
            else:
                self.send_response(400)
                self.end_headers()
                return

        if path == "/api/data":
            body = json.dumps({
                "rows":       cached_rows,
                "updatedAt":  cache_updated_at.isoformat() if cache_updated_at else None,
                "roomMap":    {str(k): v for k, v in storage_room_map.items()},
                "loginOk":    login_ok,
                "totalRows":  len(cached_rows),
            }, ensure_ascii=False).encode("utf-8")
            self._cors()
            self.send_header("Content-Type", "application/json; charset=utf-8")
            self.send_header("Content-Length", str(len(body)))
            self.end_headers()
            self.wfile.write(body)

        elif path == "/api/refresh":
            threading.Thread(target=refresh_cache, daemon=True).start()
            self._cors()
            self.send_header("Content-Type", "application/json")
            self.end_headers()
            self.wfile.write(b'{"ok":true,"message":"refresh started"}')

        elif path == "/api/status":
            body = json.dumps({
                "loginOk":   login_ok,
                "updatedAt": cache_updated_at.isoformat() if cache_updated_at else None,
                "totalRows": len(cached_rows),
                "rooms":     len(storage_room_map),
            }).encode()
            self._cors()
            self.send_header("Content-Type", "application/json")
            self.send_header("Content-Length", str(len(body)))
            self.end_headers()
            self.wfile.write(body)

        elif path == "/api/session":
            from urllib.parse import parse_qs, urlsplit
            query = urlsplit(self.path).query
            params = parse_qs(query)
            key = params.get("key", [None])[0]
            
            if key:
                key = key.strip()
                MANUAL_SESSION = key
                # session.txt에 저장
                try:
                    base_dir = os.path.dirname(os.path.abspath(__file__))
                    session_file = os.path.join(base_dir, "session.txt")
                    with open(session_file, "w", encoding="utf-8") as f:
                        f.write(key)
                except Exception as e:
                    print(f"[WARN] session.txt 저장 실패: {e}")

                # 쿠키 세팅 및 즉시 검증
                sess.cookies.set("JSESSIONID", key, domain="www.tempcare.kr", path="/")
                login_ok = _verify_api()
                
                if login_ok:
                    # 백그라운드 캐시 갱신 즉시 트리거
                    threading.Thread(target=refresh_cache, daemon=True).start()
                    body = json.dumps({"ok": True, "message": "Session updated & verified successfully"}).encode("utf-8")
                else:
                    body = json.dumps({"ok": False, "message": "Session updated but verification failed"}).encode("utf-8")
            else:
                body = json.dumps({"ok": False, "message": "Missing 'key' parameter"}).encode("utf-8")

            self._cors()
            self.send_header("Content-Type", "application/json; charset=utf-8")
            self.send_header("Content-Length", str(len(body)))
            self.end_headers()
            self.wfile.write(body)

        elif path in ("/", "/index.html"):
            self._serve_file("index.html")

        elif path in ("/production", "/production_dashboard.html"):
            self._serve_file("production_dashboard.html")

        elif path in ("/temp", "/temp.html", "/temphumid_dashboard.html"):
            base_dir = os.path.dirname(os.path.abspath(__file__))
            if os.path.exists(os.path.join(base_dir, "temp.html")):
                self._serve_file("temp.html")
            else:
                self._serve_file("temphumid_dashboard.html")

        elif path.endswith(".json"):
            import re
            filename = os.path.basename(path)
            # 보안 필터: data.json 또는 data_*.json 형태만 허용
            if re.match(r'^(data_[a-zA-Z0-9_-]+\.json|data\.json)$', filename):
                self._serve_file(filename, content_type="application/json; charset=utf-8")
            else:
                self.send_response(403)
                self.end_headers()

        else:
            self.send_response(404); self.end_headers()

    def do_POST(self):
        path = self.path.split("?")[0]
        
        # dashboard 하위 폴더 분리 처리에 따른 경로 보정
        if path.startswith("/dashboard/"):
            path = "/" + path[11:]

        if path == "/save_data.php":
            content_length = int(self.headers.get('Content-Length', 0))
            post_data = self.rfile.read(content_length)

            client_password = self.headers.get('X-Dashboard-Password', '')

            try:
                data = json.loads(post_data.decode('utf-8'))
            except Exception:
                self._send_json_response(200, {
                    "success": False,
                    "message": "올바른 JSON 데이터 형식이 아닙니다.",
                    "timestamp": datetime.now().strftime('%Y-%m-%d %H:%M:%S')
                })
                return

            if not client_password and isinstance(data, dict):
                client_password = data.get('password', '')

            # 관리자 비밀번호 검증 (save_data.php의 ADMIN_PASSWORD와 대조)
            ADMIN_PASSWORD = "1234"
            if client_password != ADMIN_PASSWORD:
                self._send_json_response(200, {
                    "success": False,
                    "message": "비밀번호가 올바르지 않습니다. 저장할 수 없습니다.",
                    "timestamp": datetime.now().strftime('%Y-%m-%d %H:%M:%S')
                })
                return

            # 비밀번호가 JSON에 포함되어 있다면 제거 후 저장
            if isinstance(data, dict) and 'password' in data:
                del data['password']

            # 프로젝트명 추출 (헤더 또는 body)
            project = self.headers.get('X-Dashboard-Project', '')
            if not project and isinstance(data, dict):
                project = data.get('project', '')
            
            import re
            project = re.sub(r'[^a-zA-Z0-9_-]', '', project)
            
            if project:
                # 무조건 메인 파일에 덮어쓰기 (타임스탬프 이력 방지)
                filename = f"data_{project}.json"
            else:
                filename = "data.json"

            base_dir = os.path.dirname(os.path.abspath(__file__))
            filepath = os.path.join(base_dir, filename)

            try:
                with open(filepath, "w", encoding="utf-8") as f:
                    json.dump(data, f, ensure_ascii=False, indent=2)

                self._send_json_response(200, {
                    "success": True,
                    "message": "서버에 데이터가 성공적으로 저장되었습니다!",
                    "filename": filename,
                    "timestamp": datetime.now().strftime('%Y-%m-%d %H:%M:%S')
                })
            except Exception as e:
                self._send_json_response(200, {
                    "success": False,
                    "message": f"서버에서 {filename} 파일 작성에 실패했습니다. (에러: {e})",
                    "timestamp": datetime.now().strftime('%Y-%m-%d %H:%M:%S')
                })
        else:
            self.send_response(404)
            self.end_headers()

    def _send_json_response(self, code, data_dict):
        body = json.dumps(data_dict, ensure_ascii=False).encode("utf-8")
        self.send_response(code)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(body)))
        self.send_header("Access-Control-Allow-Origin", "*")
        self.end_headers()
        self.wfile.write(body)

    def _serve_file(self, filename, content_type="text/html; charset=utf-8"):
        import os
        base_dir = os.path.dirname(os.path.abspath(__file__))
        filepath = os.path.join(base_dir, filename)

        # 현재 폴더에 없으면 부모 폴더(루트) 탐색 (상위 index.html 서빙 목적)
        if not os.path.exists(filepath):
            parent_dir = os.path.dirname(base_dir)
            alt_path = os.path.join(parent_dir, filename)
            if os.path.exists(alt_path):
                filepath = alt_path
            else:
                self.send_response(404)
                self.send_header("Content-Type", "text/plain; charset=utf-8")
                self.end_headers()
                self.wfile.write(f"File not found: {filename}".encode("utf-8"))
                return

        try:
            with open(filepath, "r", encoding="utf-8") as f:
                content = f.read()
            body = content.encode("utf-8")
            self.send_response(200)
            self.send_header("Content-Type", content_type)
            self.send_header("Content-Length", str(len(body)))
            self.send_header("Access-Control-Allow-Origin", "*")
            self.end_headers()
            self.wfile.write(body)
        except Exception as e:
            self.send_response(500)
            self.send_header("Content-Type", "text/plain; charset=utf-8")
            self.end_headers()
            self.wfile.write(f"Internal Server Error: {e}".encode("utf-8"))

    def send_response(self, code, message=None):
        super().send_response(code, message)

    def _cors(self):
        self.send_response(200)
        self.send_header("Access-Control-Allow-Origin",  "*")
        self.send_header("Access-Control-Allow-Methods", "GET, POST, OPTIONS")
        self.send_header("Access-Control-Allow-Headers", "Content-Type, X-Dashboard-Password, X-Dashboard-Filename, X-Dashboard-Project")

    def log_message(self, fmt, *args):
        pass   # 콘솔 로그 조용히


# ────────────────────────────────────────────────────
#  진입점
# ────────────────────────────────────────────────────
def _now():
    return datetime.now().strftime("%H:%M:%S")


if __name__ == "__main__":
    if USERNAME == "여기에_아이디":
        print("=" * 55)
        print("[WARN] proxy_server.py 를 메모장으로 열어서")
        print("   USERNAME 과 PASSWORD 를 입력하세요.")
        print("=" * 55)
        input("엔터를 누르면 종료...")
        exit(0)

    print("=" * 55)
    print(f"  tempcare.kr 프록시 서버  (포트 {PORT})")
    print(f"  회사: {COMPANY_ID}  /  간격: {INTERVAL_MIN}분  /  기간: {FETCH_DAYS}일")
    print("=" * 55)

    t = threading.Thread(target=auto_refresh_loop, daemon=True)
    t.start()

    with http.server.HTTPServer(("", PORT), ProxyHandler) as srv:
        print(f"\n[OK] 서버 실행 중: http://0.0.0.0:{PORT}/api/data")
        print("   대시보드를 열면 자동으로 연결됩니다.")
        print("   종료: Ctrl + C\n")
        try:
            srv.serve_forever()
        except KeyboardInterrupt:
            print("\n서버 종료")
