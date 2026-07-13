# 중앙알텍 포털 — LINE WORKS 로그인 설치 가이드

## 📁 파일 구성

```
/web/portal/
├── index.html          ← 로그인 페이지 (새로 추가)
├── auth-config.js      ← LINE WORKS OAuth 설정
├── auth-guard.js       ← 페이지 보호 스크립트
├── token-proxy.php     ← 토큰 교환 프록시 (선택)
└── portal.html         ← 기존 index.html → 이름 변경
```

---

## 🔧 설치 순서

### 1단계: LINE WORKS Developer Console 앱 등록

1. [LINE WORKS Developer Console](https://developers.worksmobile.com/) 접속
2. 관리자 계정으로 로그인
3. **API 2.0** → **앱 추가** 클릭
4. 아래 정보 입력:
   - 앱 이름: `중앙알텍 사내포털`
   - Redirect URI: `https://your-nas-domain.com/index.html`
   - OAuth Scope: `user.read` 체크
5. **Client ID** 와 **Client Secret** 을 복사해 둡니다

### 2단계: 기존 파일 이름 변경

```bash
# 기존 index.html 을 portal.html 로 변경
mv index.html portal.html
```

### 3단계: 새 파일 업로드

`index.html`, `auth-config.js`, `auth-guard.js` 파일을
NAS 웹 폴더에 업로드합니다.

### 4단계: auth-config.js 수정

```javascript
CLIENT_ID:     '발급받은_Client_ID',
CLIENT_SECRET: '발급받은_Client_Secret',
REDIRECT_URI:  'https://실제NAS주소/index.html',
MAIN_PAGE:     'portal.html',
```

### 5단계: 기존 portal.html 에 보호 스크립트 삽입

`portal.html` 의 `<head>` 태그 안 최상단에 아래 2줄 추가:

```html
<head>
  <script src="auth-config.js"></script>
  <script src="auth-guard.js"></script>
  <!-- ... 기존 코드 ... -->
</head>
```

### 6단계: (선택) PHP 프록시 설정

보안을 위해 `token-proxy.php`를 사용하면
Client Secret이 브라우저에 노출되지 않습니다.

1. `token-proxy.php` 업로드
2. 파일 안의 설정값을 `auth-config.js`와 동일하게 수정
3. Synology Web Station에서 PHP 활성화 확인

> PHP 프록시가 없어도 동작합니다 (내부망 전용 시).

---

## 🔒 보안 참고

| 항목 | PHP 프록시 사용 | 프록시 미사용 |
|------|:-:|:-:|
| Client Secret 브라우저 노출 | ✕ | ○ |
| 외부 인터넷 공개 가능 | ○ | ✕ (내부망만) |
| 추가 서버 설정 필요 | ○ | ✕ |

---

## 💡 활용 팁

### 로그인한 사용자 이름 표시하기

`portal.html` 에서 로그인 사용자 정보에 접근:

```javascript
// auth-guard.js 가 설정한 전역 변수
const user = window.__LW_USER;
document.getElementById('welcome').textContent = 
  user.name + '님 환영합니다';
```

### 로그아웃 버튼 추가

```html
<button onclick="sessionStorage.removeItem('lw_session'); location.href='index.html';">
  로그아웃
</button>
```

### 여러 페이지 보호하기

보호할 모든 HTML 파일의 `<head>`에 동일하게 추가:

```html
<script src="auth-config.js"></script>
<script src="auth-guard.js"></script>
```
