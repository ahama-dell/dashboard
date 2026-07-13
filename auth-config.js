/**
 * ============================================================
 *  LINE WORKS OAuth 2.0 설정
 * ============================================================
 *  
 *  [설정 방법]
 *  1. LINE WORKS Admin  →  Developer Console 접속
 *     https://developers.worksmobile.com/
 *  
 *  2. "API 2.0" 앱 등록 (또는 기존 앱 사용)
 *     - 앱 이름: 중앙알텍 포털
 *     - Redirect URI: 아래 REDIRECT_URI 값과 동일하게 등록
 *     - Scope: user.read (사용자 정보 읽기)
 *  
 *  3. 발급받은 Client ID / Client Secret 을 아래에 입력
 * ============================================================
 */
const AUTH_CONFIG = {
  // ── LINE WORKS Developer Console 에서 발급 (앱: 생산현황포털) ──
  CLIENT_ID:     'L8RrxdrSY_EvEYZ3485Y',
  // Secret은 서버 프록시(token-proxy.php)가 처리하므로 여기에 넣지 않음 (노출 방지)
  CLIENT_SECRET: 'USE_SERVER_PROXY',

  // ── 콜백 주소 (Developer Console 의 Redirect URI 와 동일해야 함) ──
  REDIRECT_URI:  'https://joongang.myds.me/index.html',

  // ── LINE WORKS OAuth 엔드포인트 ──
  AUTH_URL:      'https://auth.worksmobile.com/oauth2/v2.0/authorize',
  TOKEN_URL:     'https://auth.worksmobile.com/oauth2/v2.0/token',
  PROFILE_URL:   'https://www.worksapis.com/v1.0/users/me',

  // ── 요청 권한 (user.read: 프로필, mail.read: 메일 조회) ──
  SCOPE:         'user.read mail.read',

  // ── 세션 유지 시간 (밀리초, 기본 8시간) ──
  SESSION_TTL:   8 * 60 * 60 * 1000,

  // ── 로그인 후 이동할 메인 페이지 ──
  MAIN_PAGE:     'portal.html',
};
