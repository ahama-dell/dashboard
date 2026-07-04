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

// ────────────────────────────────────────────────────────────────
// 🔑 [설정] 네이버웍스 Developer Console 인증 정보
// ────────────────────────────────────────────────────────────────
define('WORKS_CLIENT_ID', 'YOUR_CLIENT_ID');
define('WORKS_CLIENT_SECRET', 'YOUR_CLIENT_SECRET');
define('WORKS_SERVICE_ACCOUNT', 'YOUR_SERVICE_ACCOUNT'); // Server API용 서비스 어카운트
define('WORKS_PRIVATE_KEY', 'YOUR_PRIVATE_KEY');         // JWT 서명용 비밀키 내용

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
                        "link" => "https://mail.worksmobile.com/"
                    ],
                    [
                        "subject" => "6월 생산실적 현황 보고서 송부 및 검토 요청",
                        "senderName" => "김철수 부장",
                        "senderEmail" => "cskim@ahama.com",
                        "receivedTime" => "09:15",
                        "body" => "대시보드 운영담당자님께,\n\n지난 6월 한 달 동안 집계된 사내 생산실적 종합 주간/월간 리포트 초안을 작성하여 공유해 드립니다.\n\n기존 대시보드 저장 기능 테스트 데이터를 토대로 도출한 결과입니다.\n수정 사항이 있다면 오늘 오후 6시 전까지 피드백 회신 바랍니다.\n\n수고하십시오.\n김철수 드림.",
                        "link" => "https://mail.worksmobile.com/"
                    ],
                    [
                        "subject" => "양생실 온습도 실시간 모니터링 주간 리포트",
                        "senderName" => "시스템 알림봇",
                        "senderEmail" => "bot@ahama.com",
                        "receivedTime" => "어제",
                        "body" => "본 메일은 시스템 자동 정기 보고서입니다.\n\n지난 주(06.28~07.04) 동안 기록된 총 6개 양생실의 내부 온습도 실시간 데이터 집계 통계입니다.\n\n- 유효 인증 유지 비율: 99.8%\n- 최대 측정 온도: 23.1℃ (3호실)\n- 최소 측정 습도: 58% (5호실)\n\n상세 트렌드 그래프는 대시보드 사이트의 온습도 실시간 모니터링 페이지에서 조회하실 수 있습니다.",
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
            // 실제 네이버웍스 API 연동 로직
            $summaryData = getNaverWorksSummary();
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

// 1. 네이버웍스 메일 요약 데이터 가져오기
function getNaverWorksSummary() {
    $accessToken = getNaverWorksAccessToken();
    if (!$accessToken) {
        return ["success" => false, "message" => "인증 토큰 발급에 실패했습니다.", "mails" => [], "unreadMailCount" => 0];
    }

    // 안 읽은 메일 수 가져오기 API 호출 예시
    $ch = curl_init('https://www.worksapis.com/v2/mails/folders/INBOX');
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer {$accessToken}",
        "Content-Type: application/json"
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $res = curl_exec($ch);
    curl_close($ch);

    $inbox = $res ? json_decode($res, true) : null;
    $unreadCount = isset($inbox['unreadMailCount']) ? $inbox['unreadMailCount'] : 0;

    // 최근 메일 목록 가져오기 API 호출 예시
    $ch = curl_init('https://www.worksapis.com/v2/mails?pageSize=5');
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer {$accessToken}",
        "Content-Type: application/json"
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $resList = curl_exec($ch);
    curl_close($ch);

    $mailList = $resList ? json_decode($resList, true) : null;
    $mails = [];
    if (isset($mailList['mails']) && is_array($mailList['mails'])) {
        foreach ($mailList['mails'] as $m) {
            $mails[] = [
                "subject" => isset($m['subject']) ? $m['subject'] : '(제목 없음)',
                "senderName" => isset($m['from']['name']) ? $m['from']['name'] : '알 수 없음',
                "senderEmail" => isset($m['from']['email']) ? $m['from']['email'] : '',
                "receivedTime" => isset($m['receivedTime']) ? date('H:i', strtotime($m['receivedTime'])) : '',
                "link" => "https://mail.worksmobile.com/" // 기본 메일함 이동 링크
            ];
        }
    }

    return [
        "success" => true,
        "isMockMode" => false,
        "unreadMailCount" => $unreadCount,
        "mails" => $mails,
        "notifications" => getSystemNotificationsLocal() // 로컬 서버 시스템 경보 로그 반환
    ];
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
