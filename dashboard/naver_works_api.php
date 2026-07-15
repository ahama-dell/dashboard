<?php
/**
 * 네이버웍스(Naver Works) API 연동 및 경보 메시지 발송 중계 모듈
 * 
 * - 포털(index01.html)에서 비동기(AJAX)로 메일 및 알림 내역을 조회하는 역할을 수행합니다.
 * - 시스템 알림 봇(Message Bot) 발송을 처리할 수 있는 API를 지원합니다.
 * - 시놀로지 NAS(Web Station) PHP 환경에 맞추어 작성되었습니다.
 */

ob_start();
ini_set('display_errors', 0); // 프론트엔드 응답이 깨지지 않도록 에러 출력은 끕니다.
error_reporting(E_ALL);

// 응답 헤더 설정
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, X-Dashboard-Password");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    ob_end_clean();
    exit(0);
}

// curl 확장 미설치 환경에서 500 대신 명확한 안내 반환
if (!function_exists('curl_init')) {
    $actionCheck = isset($_GET['action']) ? $_GET['action'] : '';
    $needsCurl = in_array($actionCheck, ['get_token', 'refresh_token', 'summary']) || $_SERVER['REQUEST_METHOD'] === 'POST';
    if ($needsCurl) {
        echo json_encode([
            "success" => false,
            "message" => "서버 PHP에 curl 확장이 비활성화되어 있습니다. Web Station > PHP 설정 > 확장 프로그램에서 curl을 체크하세요."
        ], JSON_UNESCAPED_UNICODE);
        ob_end_flush();
        exit;
    }
}

// ────────────────────────────────────────────────────────────────
// 🔑 [설정] 네이버웍스 Developer Console 인증 정보
//
// 발급 절차 (https://developers.worksmobile.com/ → Console):
//   1. 앱(Client App) 생성 → Client ID / Client Secret 발급
//   2. OAuth Scope에 "mail.read" 추가 (메일 조회용)
//   3. Redirect URL에 메일 모듈 주소 등록
//      (예: http://218.158.57.134:8080/dashboard/works_mail.html)
//      ※ 로그인 팝업이 보내는 redirect_uri와 정확히 일치해야 함
//   4. 발급값은 이 파일이 아니라 같은 폴더의 works_config.php에 입력
//      (works_config.php는 git에 올라가지 않으므로 비밀키가 GitHub에 노출되지 않음)
//      works_config.example.php를 복사해 works_config.php로 만들고 값만 채우면 됨
// ────────────────────────────────────────────────────────────────
if (file_exists(__DIR__ . '/works_config.php')) {
    require __DIR__ . '/works_config.php'; // 실제 인증키 (git 미추적)
}
// works_config.php가 없으면 아래 기본값 → 목업(데모) 모드로 동작
if (!defined('WORKS_CLIENT_ID'))       define('WORKS_CLIENT_ID', 'YOUR_CLIENT_ID');
if (!defined('WORKS_CLIENT_SECRET'))   define('WORKS_CLIENT_SECRET', 'YOUR_CLIENT_SECRET');
if (!defined('WORKS_SERVICE_ACCOUNT')) define('WORKS_SERVICE_ACCOUNT', 'YOUR_SERVICE_ACCOUNT'); // Server API용 서비스 어카운트
if (!defined('WORKS_PRIVATE_KEY'))     define('WORKS_PRIVATE_KEY', 'YOUR_PRIVATE_KEY');         // JWT 서명용 비밀키 내용

// ────────────────────────────────────────────────────────────────
// 🧪 [기능 선택] 실제 연동 모드 검사
// ────────────────────────────────────────────────────────────────
// 설정 키값이 그대로 제공되었을 경우 목업(시뮬레이션) 모드로 작동
$isMockMode = (WORKS_CLIENT_ID === 'YOUR_CLIENT_ID' || empty(WORKS_CLIENT_ID));

// ────────────────────────────────────────────────────────────────
// 📥 GET 요청 처리
// ────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = isset($_GET['action']) ? $_GET['action'] : '';

    // 🔑 0. OAuth 설정 조회 API
    if ($action === 'config') {
        echo json_encode([
            "success" => true,
            "client_id" => WORKS_CLIENT_ID === 'YOUR_CLIENT_ID' ? '' : WORKS_CLIENT_ID,
            "isMockMode" => $isMockMode
        ]);
        ob_end_flush();
        exit;
    }

    // 🔑 1. OAuth 2.0 authorization_code 토큰 발급 API
    if ($action === 'get_token') {
        if ($isMockMode) {
            echo json_encode([
                "success" => true,
                "access_token" => "mock_access_token_" . time(),
                "refresh_token" => "mock_refresh_token_" . time(),
                "expires_in" => 86400
            ]);
            ob_end_flush();
            exit;
        }
        
        $code = isset($_GET['code']) ? $_GET['code'] : '';
        $redirectUri = isset($_GET['redirect_uri']) ? $_GET['redirect_uri'] : '';
        
        if (empty($code) || empty($redirectUri)) {
            echo json_encode(["success" => false, "message" => "필수 파라미터(code, redirect_uri)가 누락되었습니다."]);
            ob_end_flush();
            exit;
        }
        
        $ch = curl_init('https://auth.worksmobile.com/oauth2/v2.0/token');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'code' => $code,
            'grant_type' => 'authorization_code',
            'client_id' => WORKS_CLIENT_ID,
            'client_secret' => WORKS_CLIENT_SECRET,
            'redirect_uri' => $redirectUri
        ]));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/x-www-form-urlencoded'
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            echo json_encode([
                "success" => false, 
                "message" => "토큰 발급 실패 (HTTP $httpCode)",
                "raw" => $response ? json_decode($response, true) : null
            ]);
        } else {
            $tokenData = json_decode($response, true);
            $tokenData['success'] = true;
            echo json_encode($tokenData);
        }
        ob_end_flush();
        exit;
    }

    // 🔑 2. OAuth 2.0 refresh_token 토큰 갱신 API
    if ($action === 'refresh_token') {
        if ($isMockMode) {
            echo json_encode([
                "success" => true,
                "access_token" => "mock_access_token_refreshed_" . time(),
                "expires_in" => 86400
            ]);
            ob_end_flush();
            exit;
        }
        
        $refreshToken = isset($_GET['refresh_token']) ? $_GET['refresh_token'] : '';
        if (empty($refreshToken)) {
            echo json_encode(["success" => false, "message" => "refresh_token이 누락되었습니다."]);
            ob_end_flush();
            exit;
        }
        
        $ch = curl_init('https://auth.worksmobile.com/oauth2/v2.0/token');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => WORKS_CLIENT_ID,
            'client_secret' => WORKS_CLIENT_SECRET
        ]));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/x-www-form-urlencoded'
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            echo json_encode([
                "success" => false, 
                "message" => "토큰 갱신 실패 (HTTP $httpCode)",
                "raw" => $response ? json_decode($response, true) : null
            ]);
        } else {
            $tokenData = json_decode($response, true);
            $tokenData['success'] = true;
            echo json_encode($tokenData);
        }
        ob_end_flush();
        exit;
    }

    // 🔔 2-1. 시스템 알림 피드 API (로그인 불필요 — 서버 로컬 경보만 반환)
    if ($action === 'notifications') {
        echo json_encode([
            "success" => true,
            "notifications" => getSystemNotificationsLocal()
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        ob_end_flush();
        exit;
    }

    // ✉️ 3. 개별 사용자 메일 및 알림 요약 정보 API
    if ($action === 'summary') {
        if ($isMockMode) {
            // 디버그 및 프론트엔드 정합성 테스트용 Mock 데이터 제공
            $mockResponse = [
                "success" => true,
                "isMockMode" => true,
                "unreadMailCount" => 3,
                "mails" => [
                    [
                        "subject" => "[알림] 양평동 현장 자재 조립 단계 시작 완료의 건",
                        "senderName" => "홍길동 차장",
                        "senderEmail" => "kdhong@ahama.com",
                        "receivedTime" => "10:42",
                        "body" => "안녕하세요. 관리팀 홍길동 차장입니다.\n\n양평동 현장의 조립 단계 자재 준비 및 공정 개시가 금일 10시 30분을 기점으로 정상 시작 완료되었습니다.\n\n실시간 생산현황 대시보드를 통해 현장 실적을 확인하실 수 있으니 많은 참고 바랍니다.\n\n감사합니다.",
                        "isUnread" => true,
                        "link" => "https://mail.worksmobile.com/"
                    ],
                    [
                        "subject" => "6월 생산실적 현황 보고서 송부 및 검토 요청",
                        "senderName" => "김철수 부장",
                        "senderEmail" => "cskim@ahama.com",
                        "receivedTime" => "09:15",
                        "body" => "대시보드 운영담당자님께,\n\n지난 6월 한 달 동안 집계된 사내 생산실적 종합 주간/월간 리포트 초안을 작성하여 공유해 드립니다.\n\n기존 대시보드 저장 기능 테스트 데이터를 토대로 도출한 결과입니다.\n수정 사항이 있다면 오늘 오후 6시 전까지 피드백 회신 바랍니다.\n\n수고하십시오.\n김철수 드림.",
                        "isUnread" => true,
                        "link" => "https://mail.worksmobile.com/"
                    ],
                    [
                        "subject" => "양생실 온습도 실시간 모니터링 주간 리포트",
                        "senderName" => "시스템 알림봇",
                        "senderEmail" => "bot@ahama.com",
                        "receivedTime" => "어제",
                        "body" => "본 메일은 시스템 자동 정기 보고서입니다.\n\n지난 주(06.28~07.04) 동안 기록된 총 6개 양생실의 내부 온습도 실시간 데이터 집계 통계입니다.\n\n- 유효 인증 유지 비율: 99.8%\n- 최대 측정 온도: 23.1℃ (3호실)\n- 최소 측정 습도: 58% (5호실)\n\n상세 트렌드 그래프는 대시보드 사이트의 온습도 실시간 모니터링 페이지에서 조회하실 수 있습니다.",
                        "isUnread" => true,
                        "link" => "https://mail.worksmobile.com/"
                    ],
                    [
                        "subject" => "[협조] 차주 자재 입고 일정 확인의 건",
                        "senderName" => "이영희 과장",
                        "senderEmail" => "yhlee@ahama.com",
                        "receivedTime" => "어제",
                        "body" => "안녕하세요. 이영희 과장입니다.\n\n차주 월요일부터 양평동 현장으로 조립 부품 및 가공 판재가 순차 입고될 예정입니다.\n\n현장 하역 공간 확보 및 크레인 장비 일정을 미리 확인하시어 부품 입고 시 혼선이 없도록 협조 요청 드립니다.\n\n세부 입고 수량 명세서는 첨부된 엑셀 리스트를 참조하십시오.",
                        "isUnread" => false,
                        "link" => "https://mail.worksmobile.com/"
                    ],
                    [
                        "subject" => "사내 통합 모니터링 시스템 로그인 비밀번호 변경 안내",
                        "senderName" => "IT지원팀",
                        "senderEmail" => "it@ahama.com",
                        "receivedTime" => "07.02",
                        "body" => "보안 정책에 따라 통합 모니터링 대시보드 및 포털 관리자 패스워드를 정기적으로 변경해 주시기 바랍니다.\n\n현재 설정된 기본 비밀번호는 주기적으로 만료 처리될 수 있으며, 새로운 비밀번호 설정 시 영문 대소문자, 숫자, 특수문자를 혼용하여 설정하십시오.\n\n관련 문의는 내선 114번으로 연락 바랍니다.",
                        "isUnread" => false,
                        "link" => "https://mail.worksmobile.com/"
                    ]
                ],
                "notifications" => [
                    [
                        "source" => "⚠️ 양생실 경보",
                        "level" => "error",
                        "time" => "10:50",
                        "message" => "양생실 3호실 온도가 유효 인증 범위(20℃~22℃)를 초과하여 24.5℃로 상승했습니다. 즉시 확인 바랍니다.\n\n[세부 상태]\n- 3호실 현재 온도: 24.5℃\n- 습도: 62%\n- 알림 대상: 시설물 비상 담당자 전원 및 관리소장"
                    ],
                    [
                        "source" => "✅ 실적 저장",
                        "level" => "info",
                        "time" => "09:30",
                        "message" => "양평동 현장 20251113-1의 금일 오전 조립 공정 생산 실적 데이터 저장이 완료되었습니다.\n\n- 조립 완료 수량: 45 개\n- 불량 발생 수량: 0 개\n- 작업 작업자: 조립 A팀\n- 데이터 저장 경로: Synology NAS /volume1/web/dashboard/data_20251113-1.json"
                    ]
                ]
            ];
            echo json_encode($mockResponse, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            ob_end_flush();
            exit;
        } else {
            // 헤더 또는 쿼리 파라미터에서 access_token 추출
            $accessToken = '';
            
            // HTTP Authorization 헤더 확인
            $authHeader = '';
            if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
                $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
            } elseif (function_exists('apache_request_headers')) {
                $requestHeaders = apache_request_headers();
                if (isset($requestHeaders['Authorization'])) {
                    $authHeader = $requestHeaders['Authorization'];
                }
            }
            
            if (!empty($authHeader) && preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
                $accessToken = $matches[1];
            } elseif (isset($_GET['access_token'])) {
                $accessToken = $_GET['access_token'];
            }
            
            if (empty($accessToken)) {
                echo json_encode([
                    "success" => false, 
                    "message" => "인증 토큰(access_token)이 전달되지 않았습니다."
                ]);
                ob_end_flush();
                exit;
            }
            
            // 실제 네이버웍스 API 연동 로직 (전달받은 Access Token을 매핑)
            $summaryData = getNaverWorksSummary($accessToken);
            echo json_encode($summaryData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            ob_end_flush();
            exit;
        }
    }
}

// ────────────────────────────────────────────────────────────────
// 📤 POST 요청 처리 (알림 봇 발송 API 등)
// ────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $inputRaw = file_get_contents('php://input');
    $payload = json_decode($inputRaw, true);

    $action = isset($payload['action']) ? $payload['action'] : '';

    if ($action === 'send_alert') {
        $message = isset($payload['message']) ? $payload['message'] : '';
        $botId = isset($payload['botId']) ? $payload['botId'] : '';
        $channelId = isset($payload['channelId']) ? $payload['channelId'] : '';

        if (empty($message)) {
            echo json_encode(["success" => false, "message" => "메시지 내용이 없습니다."], JSON_UNESCAPED_UNICODE);
            ob_end_flush();
            exit;
        }

        if ($isMockMode) {
            // Mock 발송 성공 응답
            echo json_encode([
                "success" => true,
                "message" => "[Mock] 네이버웍스 알림 메시지가 전송되었습니다.",
                "sent_message" => $message
            ], JSON_UNESCAPED_UNICODE);
            ob_end_flush();
            exit;
        } else {
            // 실제 봇 메시지 발송 연동
            $res = sendNaverWorksBotMessage($botId, $channelId, $message);
            echo json_encode($res, JSON_UNESCAPED_UNICODE);
            ob_end_flush();
            exit;
        }
    }
}

// ────────────────────────────────────────────────────────────────
// 📡 네이버웍스 실제 API 연동 함수군
// ────────────────────────────────────────────────────────────────

// 0. Works API GET 공통 호출 헬퍼
function worksApiGet($url, $accessToken) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer {$accessToken}",
        "Content-Type: application/json"
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$httpCode, $res ? json_decode($res, true) : null];
}

// 0-1. 수신시각을 목록 표시용 문자열로 변환 (오늘=시:분, 어제='어제', 그 외=월.일)
function formatWorksMailTime($isoTime) {
    if (empty($isoTime)) return '';
    try {
        $tz = new DateTimeZone('Asia/Seoul');
        $dt = new DateTime($isoTime);
        $dt->setTimezone($tz);
        $now = new DateTime('now', $tz);
        $diffDays = (int)$now->format('Ymd') - (int)$dt->format('Ymd');
        if ($diffDays === 0) return $dt->format('H:i');
        if ($diffDays === 1) return '어제';
        return $dt->format('m.d');
    } catch (Exception $e) {
        return '';
    }
}

// 1. 네이버웍스 메일 요약 데이터 가져오기 (Mail API v1.0, 사용자 계정 토큰 필요 / scope: mail.read)
function getNaverWorksSummary($accessToken) {
    if (empty($accessToken)) {
        return ["success" => false, "message" => "인증 토큰(access_token)이 누락되었습니다.", "mails" => [], "unreadMailCount" => 0];
    }

    $apiBase = 'https://www.worksapis.com/v1.0/users/me';

    // 1) 메일함 목록 조회 → 받은메일함 folderId 및 안읽은 메일 수 확보
    list($codeFolders, $folderData) = worksApiGet("{$apiBase}/mail/mailfolders", $accessToken);

    if ($codeFolders === 401 || $codeFolders === 403) {
        return [
            "success" => false,
            "needsReauth" => true,
            "message" => "네이버웍스 인증이 만료되었거나 mail.read 권한이 없습니다. (HTTP {$codeFolders})"
        ];
    }
    if ($codeFolders !== 200 || !isset($folderData['mailFolders'])) {
        return [
            "success" => false,
            "message" => "메일함 목록 조회 실패 (HTTP {$codeFolders})",
            "raw" => $folderData
        ];
    }

    // 받은메일함 탐색: 이름 매칭 우선, 없으면 시스템 폴더 중 folderId가 가장 작은 것
    $inboxFolderId = null;
    $unreadCount = 0;
    $systemFolders = [];
    foreach ($folderData['mailFolders'] as $f) {
        $name = isset($f['folderName']) ? $f['folderName'] : '';
        if ($name === '받은메일함' || strcasecmp($name, 'Inbox') === 0) {
            $inboxFolderId = $f['folderId'];
            $unreadCount = isset($f['unreadMailCount']) ? (int)$f['unreadMailCount'] : 0;
            break;
        }
        if (isset($f['folderType']) && $f['folderType'] === 'S') $systemFolders[] = $f;
    }
    if ($inboxFolderId === null && count($systemFolders) > 0) {
        usort($systemFolders, function($a, $b) { return $a['folderId'] - $b['folderId']; });
        $inboxFolderId = $systemFolders[0]['folderId'];
        $unreadCount = isset($systemFolders[0]['unreadMailCount']) ? (int)$systemFolders[0]['unreadMailCount'] : 0;
    }
    if ($inboxFolderId === null) {
        return ["success" => false, "message" => "받은메일함을 찾을 수 없습니다.", "raw" => $folderData];
    }

    // 2) 받은메일함 최근 메일 목록 조회 (최소 허용값 15건)
    list($codeList, $listData) = worksApiGet("{$apiBase}/mail/mailfolders/{$inboxFolderId}/children?count=15", $accessToken);

    if ($codeList === 401 || $codeList === 403) {
        return [
            "success" => false,
            "needsReauth" => true,
            "message" => "네이버웍스 인증이 만료되었습니다. (HTTP {$codeList})"
        ];
    }
    if ($codeList !== 200) {
        return ["success" => false, "message" => "메일 목록 조회 실패 (HTTP {$codeList})", "raw" => $listData];
    }

    $mails = [];
    if (isset($listData['mails']) && is_array($listData['mails'])) {
        foreach ($listData['mails'] as $idx => $m) {
            $isUnread = (isset($m['status']) && $m['status'] === 'Unread');
            $bodyContent = '';

            // 3) 본문 조회 제거 (속도 극대화)
            // 개별 본문을 조회하지 않으므로 API 호출 1회로 단축됨
            $bodyContent = '';

            $mails[] = [
                "mailId" => isset($m['mailId']) ? $m['mailId'] : '',
                "subject" => isset($m['subject']) && $m['subject'] !== '' ? $m['subject'] : '(제목 없음)',
                "senderName" => isset($m['from']['name']) && $m['from']['name'] !== '' ? $m['from']['name'] : (isset($m['from']['email']) ? $m['from']['email'] : '알 수 없음'),
                "senderEmail" => isset($m['from']['email']) ? $m['from']['email'] : '',
                "receivedTime" => isset($m['receivedTime']) ? formatWorksMailTime($m['receivedTime']) : '',
                "isUnread" => $isUnread,
                "body" => $bodyContent,
                "link" => "https://mail.worksmobile.com/" // 기본 메일함 이동 링크
            ];
        }


    }

    // (목록 조회 시에는 본문을 가져오지 않음 - 속도 최적화)

    return [
        "success" => true,
        "isMockMode" => false,
        "unreadMailCount" => $unreadCount,
        "mails" => $mails,
        "notifications" => getSystemNotificationsLocal()
    ];
}

// 1-2. 단일 메일 본문 가져오기 (Lazy Loading)
if ($action === 'getMailBody') {
    if (!isset($_GET['mailId']) || empty($_GET['mailId'])) {
        echo json_encode(["success" => false, "message" => "mailId가 없습니다."]);
        exit;
    }
    $mailId = $_GET['mailId'];
    
    // HTTP Authorization 헤더 확인 (인증 토큰 추출)
    $accessToken = '';
    $authHeader = '';
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
    } elseif (function_exists('apache_request_headers')) {
        $requestHeaders = apache_request_headers();
        if (isset($requestHeaders['Authorization'])) {
            $authHeader = $requestHeaders['Authorization'];
        }
    }
    
    if (!empty($authHeader) && preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
        $accessToken = $matches[1];
    } elseif (isset($_GET['access_token'])) {
        $accessToken = $_GET['access_token'];
    }
    
    if (empty($accessToken)) {
        echo json_encode(["success" => false, "message" => "인증 토큰(access_token)이 없습니다."]);
        exit;
    }

    $apiBase = 'https://www.worksapis.com/v1.0/users/me';
    
    list($code, $detail) = worksApiGet("{$apiBase}/mail/{$mailId}", $accessToken);
    if ($code === 200 && isset($detail['mail']['body'])) {
        echo json_encode(["success" => true, "body" => $detail['mail']['body']]);
    } else {
        $extra = ($code === 200) ? " (응답구조: " . json_encode($detail, JSON_UNESCAPED_UNICODE) . ")" : "";
        echo json_encode(["success" => false, "message" => "본문 조회 실패 (HTTP {$code}){$extra}"]);
    }
    exit;
}

// 2. 메시지 봇을 통한 메시지 전송
function sendNaverWorksBotMessage($botId, $channelId, $message) {
    $accessToken = getNaverWorksAccessToken();
    if (!$accessToken) {
        return ["success" => false, "message" => "인증 토큰 발급에 실패했습니다."];
    }

    // 네이버웍스 봇 메시지 전송 API 호출
    $url = "https://www.worksapis.com/v2/bots/" . urlencode($botId) . "/channels/" . urlencode($channelId) . "/messages";
    
    $payload = [
        "content" => [
            "type" => "text",
            "text" => $message
        ]
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer {$accessToken}",
        "Content-Type: application/json"
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 201 || $httpCode === 200) {
        return ["success" => true, "message" => "메시지 발송 성공"];
    } else {
        return ["success" => false, "message" => "메시지 발송 실패 (HTTP Code: {$httpCode})", "raw_response" => $res];
    }
}

// 3. JWT를 이용한 Access Token 획득 로직
function getNaverWorksAccessToken() {
    return null; // 실제 연동 전에는 연동 설정이 되지 않았으므로 null 반환
}

// 4. 로컬 서버의 임시 모니터링 로그 반환
function getSystemNotificationsLocal() {
    return [
        [
            "source" => "⚠️ 양생실 경보",
            "level" => "error",
            "time" => date('H:i', time() - 300), // 5분 전
            "message" => "양생실 3호실 온도가 유효 인증 범위(20℃~22℃)를 초과하여 24.5℃로 상승했습니다. 즉시 확인 바랍니다."
        ],
        [
            "source" => "✅ 실적 저장",
            "level" => "info",
            "time" => date('H:i', time() - 5400), // 1시간 반 전
            "message" => "양평동 현장 20251113-1의 금일 오전 조립 공정 생산 실적 데이터 저장이 완료되었습니다."
        ]
    ];
}

ob_end_flush();
