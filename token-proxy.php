<?php
/**
 * ============================================================
 *  token-proxy.php — 네이버웍스 토큰 교환 서버 프록시 (로그인 게이트용)
 * ============================================================
 *  - Client Secret을 브라우저에 노출하지 않기 위한 서버측 프록시
 *  - 인증키는 dashboard/works_config.php에서 읽음 (git 미추적)
 *  - 토큰 교환 성공 시 사용자 프로필(users/me)도 서버측에서 함께 조회해
 *    응답에 profile 필드로 병합 (브라우저 직접 호출은 CORS 차단되므로)
 * ============================================================
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

// ── 인증키 로드 (메일 연동과 동일한 설정 파일 재사용) ──
if (file_exists(__DIR__ . '/dashboard/works_config.php')) {
    require __DIR__ . '/dashboard/works_config.php';
}
$CLIENT_ID     = defined('WORKS_CLIENT_ID') ? WORKS_CLIENT_ID : 'YOUR_CLIENT_ID_HERE';
$CLIENT_SECRET = defined('WORKS_CLIENT_SECRET') ? WORKS_CLIENT_SECRET : 'YOUR_CLIENT_SECRET_HERE';
$REDIRECT_URI  = 'https://joongang.myds.me/index.html'; // Developer Console에 등록된 값과 일치해야 함
$TOKEN_URL     = 'https://auth.worksmobile.com/oauth2/v2.0/token';
$PROFILE_URL   = 'https://www.worksapis.com/v1.0/users/me';

// ── POST만 허용 (HEAD는 프록시 존재 확인용으로 200 반환) ──
if ($_SERVER['REQUEST_METHOD'] === 'HEAD') {
    http_response_code(200);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(array('error' => 'Method not allowed'));
    exit;
}

$code = isset($_POST['code']) ? $_POST['code'] : '';
if (empty($code)) {
    http_response_code(400);
    echo json_encode(array('error' => 'Missing authorization code'));
    exit;
}

// ── 1) 토큰 교환 ──
$postData = http_build_query(array(
    'grant_type'    => 'authorization_code',
    'client_id'     => $CLIENT_ID,
    'client_secret' => $CLIENT_SECRET,
    'code'          => $code,
    'redirect_uri'  => $REDIRECT_URI,
));

$ch = curl_init($TOKEN_URL);
curl_setopt_array($ch, array(
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $postData,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_HTTPHEADER     => array('Content-Type: application/x-www-form-urlencoded'),
));
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error    = curl_error($ch);
curl_close($ch);

if ($error) {
    http_response_code(502);
    echo json_encode(array('error' => 'Token request failed', 'detail' => $error));
    exit;
}

$data = $response ? json_decode($response, true) : null;

// ── 2) 토큰 발급 성공 시 프로필 서버측 조회 후 응답에 병합 ──
if ($httpCode === 200 && is_array($data) && isset($data['access_token'])) {
    $ch = curl_init($PROFILE_URL);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => array('Authorization: Bearer ' . $data['access_token']),
    ));
    $profileRes  = curl_exec($ch);
    $profileCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $profileOut = array('userName' => '', 'email' => '', 'userId' => '');
    if ($profileCode === 200 && $profileRes) {
        $p = json_decode($profileRes, true);
        if (is_array($p)) {
            // userName은 {lastName, firstName} 객체 형태 → 표시용 문자열로 변환
            $name = '';
            if (isset($p['userName']) && is_array($p['userName'])) {
                $last  = isset($p['userName']['lastName'])  ? $p['userName']['lastName']  : '';
                $first = isset($p['userName']['firstName']) ? $p['userName']['firstName'] : '';
                $name  = trim($last . $first);
            }
            $email = isset($p['email']) ? $p['email'] : '';
            $profileOut['userName'] = $name !== '' ? $name : ($email !== '' ? $email : '사용자');
            $profileOut['email']    = $email;
            $profileOut['userId']   = isset($p['userId']) ? $p['userId'] : '';
        }
    }
    $data['profile'] = $profileOut;

    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// 실패 응답은 원문 그대로 전달
http_response_code($httpCode ? $httpCode : 502);
echo $response;
