/**
 * ============================================================
 *  auth-guard.js — 페이지 접근 제한 스크립트
 * ============================================================
 *  
 *  기존 HTML 파일의 <head> 안에 아래 두 줄을 추가하면
 *  로그인하지 않은 사용자는 자동으로 login 페이지로 이동합니다.
 *
 *    <script src="auth-config.js"></script>
 *    <script src="auth-guard.js"></script>
 *
 * ============================================================
 */
(function() {
  'use strict';

  const session = localStorage.getItem('lw_session');

  if (!session) {
    // 세션 없음 → 로그인 페이지로
    window.location.replace('index.html');
    return;
  }

  try {
    const data = JSON.parse(session);

    // 세션 만료 확인
    if (Date.now() > data.expiresAt) {
      localStorage.removeItem('lw_session');
      window.location.replace('index.html');
      return;
    }

    // ── 전역에서 사용자 정보 접근 가능 ──
    window.__LW_USER = {
      name:   data.userName,
      email:  data.email,
      userId: data.userId,
      token:  data.accessToken,
    };

    // ── 메일 위젯(works_mail.html iframe)과 토큰 동기화 ──
    // localStorage에 토큰이 있으나 localStorage에 없는 경우 복원
    if (data.accessToken && !localStorage.getItem('naver_works_access_token')) {
      localStorage.setItem('naver_works_access_token', data.accessToken);
      localStorage.setItem('naver_works_token_expires', String(data.expiresAt));
    }

  } catch (e) {
    localStorage.removeItem('lw_session');
    window.location.replace('index.html');
  }
})();
