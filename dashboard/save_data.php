<?php
// 1. 헤더 전송 오류(UTF-8 BOM 또는 예기치 않은 공백 출력 등)를 방지하기 위해 아웃풋 버퍼링을 활성화합니다.
ob_start();

ini_set('display_errors', 1);
error_reporting(E_ALL);

/**
 * 생산 모니터링 대시보드 - 서버 데이터 저장 API (Synology NAS Web Station 용)
 *
 * - 보안을 위해 POST 요청 및 비밀번호 검증을 수행합니다.
 * - X-Dashboard-Mode: main  → data_{project}.json 에 직접 덮어씌워 저장
 * - X-Dashboard-Mode: history (기본값) → data_{project}_{timestamp}.json 이력 파일로 저장
 */

// 응답 JSON 문자열 생성 도우미 함수 (함수 선언 순서 문제를 방지하기 위해 파일 상단에 정의합니다)
function json_construct($success, $message) {
    return json_encode([
        "success" => $success,
        "message" => $message,
        "timestamp" => date('Y-m-d H:i:s')
    ], defined('JSON_UNESCAPED_UNICODE') ? JSON_UNESCAPED_UNICODE : 0);
}

// 2. CORS 및 응답 헤더 설정
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, X-Dashboard-Password, X-Dashboard-Filename, X-Dashboard-Project, X-Dashboard-Mode");
header("Content-Type: application/json; charset=UTF-8");

// OPTIONS 사전 요청 처리 (CORS 대응)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    ob_end_clean();
    exit(0);
}

// 3. 환경설정 - 관리자 비밀번호
define('ADMIN_PASSWORD', '1234'); 

// 4. GET 요청 처리 (저장 이력 목록 반환)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = isset($_GET['action']) ? $_GET['action'] : '';
    if ($action === 'list') {
        $project = isset($_GET['project']) ? $_GET['project'] : '';
        $project = preg_replace('/[^a-zA-Z0-9_-]/', '', $project); // 보안 정화
        
        $files = [];
        if (!empty($project)) {
            // data_{project}_*.json 패턴 매칭 (타임스탬프 이력 파일만)
            $pattern = __DIR__ . "/data_{$project}_????????_??????.json";
            $found = glob($pattern);
            if ($found) {
                rsort($found); // 파일명 내림차순 정렬 (최신순)
                foreach ($found as $filepath) {
                    $files[] = basename($filepath);
                }
            }
        }
        
        echo json_encode([
            "success" => true,
            "files" => $files,
            "project" => $project
        ], defined('JSON_UNESCAPED_UNICODE') ? JSON_UNESCAPED_UNICODE : 0);
        ob_end_flush();
        exit;
    }
    
    // 프로젝트 목록 조회 (신규 현장 추가 드롭다운용)
    if ($action === 'list_projects') {
        $projects = [];
        $seen = [];
        // 1) 기존 data_*.json 파일에서 프로젝트 정보 수집
        $files = glob(__DIR__ . '/data_*.json') ?: [];
        foreach ($files as $file) {
            $basename = basename($file);
            if (in_array($basename, ['data.json', 'data_portal_index.json'])) continue;
            // data_[no].json 에서 번호 추출 (타임스탬프 이력 파일 제외)
            if (!preg_match('/^data_([a-zA-Z0-9_-]+)\.json$/', $basename, $m)) continue;
            $no = $m[1];
            $raw = @file_get_contents($file);
            $data = $raw ? json_decode($raw, true) : null;
            $name = ($data && isset($data['projectName'])) ? $data['projectName'] : $no;
            $projects[] = ['no' => $no, 'name' => $name];
            $seen[] = $no;
        }
        // 2) data.json (종합)의 rows에서 수주번호 컬럼 추출
        $mainRaw = @file_get_contents(__DIR__ . '/data.json');
        if ($mainRaw) {
            $main = json_decode($mainRaw, true);
            $rows = ($main && isset($main['rows'])) ? $main['rows'] : [];
            $orderKeys = ['수주번호', '수주 번호', 'order_no', 'orderNo', 'OrderNo'];
            $nameKeys  = ['프로젝트명', '현장명', '공사명'];
            foreach ($rows as $row) {
                $no = '';
                foreach ($orderKeys as $k) { if (!empty($row[$k])) { $no = trim((string)$row[$k]); break; } }
                if (!$no || in_array($no, $seen)) continue;
                $name = $no;
                foreach ($nameKeys as $k) { if (!empty($row[$k])) { $name = trim((string)$row[$k]); break; } }
                $projects[] = ['no' => $no, 'name' => $name];
                $seen[] = $no;
            }
        }
        echo json_encode(['success' => true, 'projects' => $projects], JSON_UNESCAPED_UNICODE);
        ob_end_flush(); exit;
    }

    // 파일 삭제 액션
    if ($action === 'delete') {
        $clientPassword = isset($_SERVER['HTTP_X_DASHBOARD_PASSWORD']) ? $_SERVER['HTTP_X_DASHBOARD_PASSWORD'] : '';
        if (empty($clientPassword) && function_exists('getallheaders')) {
            $headers = getallheaders();
            foreach ($headers as $key => $val) {
                if (strcasecmp($key, 'X-Dashboard-Password') === 0) { $clientPassword = $val; break; }
            }
        }
        if ($clientPassword !== ADMIN_PASSWORD) {
            echo json_construct(false, '비밀번호가 올바르지 않습니다.');
            ob_end_flush(); exit;
        }
        $project = isset($_GET['project']) ? $_GET['project'] : '';
        $project = preg_replace('/[^a-zA-Z0-9_-]/', '', $project);
        if (empty($project)) {
            echo json_construct(false, '프로젝트 번호가 없습니다.');
            ob_end_flush(); exit;
        }
        $filename = "data_{$project}.json";
        $filePath = __DIR__ . '/' . $filename;
        if (!file_exists($filePath)) {
            echo json_encode(['success' => true, 'message' => '파일이 이미 존재하지 않습니다.', 'filename' => $filename], JSON_UNESCAPED_UNICODE);
            ob_end_flush(); exit;
        }
        if (unlink($filePath)) {
            echo json_encode(['success' => true, 'message' => "{$filename} 파일이 삭제되었습니다.", 'filename' => $filename], JSON_UNESCAPED_UNICODE);
        } else {
            echo json_construct(false, "{$filename} 파일 삭제에 실패했습니다. 쓰기 권한을 확인하세요.");
        }
        ob_end_flush(); exit;
    }

    echo json_construct(false, "지원하지 않는 GET 액션입니다.");
    ob_end_flush();
    exit;
}

// 5. POST 요청 여부 확인
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_construct(false, "허용되지 않는 요청 메서드입니다.");
    ob_end_flush();
    exit;
}

// 5. 인증 비밀번호 검증
$clientPassword = isset($_SERVER['HTTP_X_DASHBOARD_PASSWORD']) ? $_SERVER['HTTP_X_DASHBOARD_PASSWORD'] : '';

if (empty($clientPassword)) {
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        foreach ($headers as $key => $val) {
            if (strcasecmp($key, 'X-Dashboard-Password') === 0) {
                $clientPassword = $val;
                break;
            }
        }
    }
}

if (empty($clientPassword)) {
    $rawInput = file_get_contents('php://input');
    $decodedInput = json_decode($rawInput, true);
    if (isset($decodedInput['password'])) {
        $clientPassword = $decodedInput['password'];
    }
}

// 비밀번호 대조
if ($clientPassword !== ADMIN_PASSWORD) {
    echo json_construct(false, "비밀번호가 올바르지 않습니다. 저장할 수 없습니다.");
    ob_end_flush();
    exit;
}

// 6. JSON 본문 읽기 및 유효성 검사
$jsonRaw = file_get_contents('php://input');
$data = json_decode($jsonRaw, true);

if ($data === null) {
    echo json_construct(false, "올바른 JSON 데이터 형식이 아닙니다.");
    ob_end_flush();
    exit;
}

// 만약 JSON 본문 안에 'password' 필드가 포함되어 요청이 들어온 경우, 파일에 비밀번호를 저장하지 않도록 제거합니다.
if (isset($data['password'])) {
    unset($data['password']);
    $encodeOptions = 0;
    if (defined('JSON_PRETTY_PRINT')) {
        $encodeOptions |= JSON_PRETTY_PRINT;
    }
    if (defined('JSON_UNESCAPED_UNICODE')) {
        $encodeOptions |= JSON_UNESCAPED_UNICODE;
    }
    $jsonRaw = json_encode($data, $encodeOptions);
}

// 7. 저장 모드 결정: main = 메인 파일 덮어쓰기, history = 타임스탬프 이력 파일
// (사용자 요청에 따라 무조건 메인 파일 덮어쓰기로 작동하도록 변경됨)

// 8. 저장 대상 파일명 결정
$project = '';
if (isset($_SERVER['HTTP_X_DASHBOARD_PROJECT'])) {
    $project = $_SERVER['HTTP_X_DASHBOARD_PROJECT'];
}
if (empty($project) && function_exists('getallheaders')) {
    $headers = getallheaders();
    foreach ($headers as $key => $val) {
        if (strcasecmp($key, 'X-Dashboard-Project') === 0) {
            $project = $val;
            break;
        }
    }
}
if (empty($project)) {
    if (is_array($data) && isset($data['project'])) {
        $project = $data['project'];
    }
}

// 보안 정화 (영숫자, 대시, 언더바만 허용)
$project = preg_replace('/[^a-zA-Z0-9_-]/', '', $project);

date_default_timezone_set('Asia/Seoul');

// ── 무조건 메인 파일에 덮어쓰기 (타임스탬프 이력 방지)
if (!empty($project)) {
    $filename = "data_{$project}.json";
} else {
    $filename = 'data.json';
}

$filePath = __DIR__ . '/' . $filename;

// 파일 쓰기 권한 확인 및 저장
if (file_put_contents($filePath, $jsonRaw) === false) {
    echo json_construct(false, "서버에서 {$filename} 파일 작성에 실패했습니다. (디렉토리의 쓰기 권한이 올바른지 확인하세요)");
    ob_end_flush();
    exit;
}

// 9. 성공 응답
echo json_encode([
    "success" => true,
    "message" => "서버에 데이터가 성공적으로 저장되었습니다!",
    "filename" => $filename,
    "timestamp" => date('Y-m-d H:i:s')
], defined('JSON_UNESCAPED_UNICODE') ? JSON_UNESCAPED_UNICODE : 0);
ob_end_flush();
?>

