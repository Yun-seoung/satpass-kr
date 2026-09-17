<?php
/**
 * station.php — 관측지(지상국) 저장·조회
 */

declare(strict_types=1);

// 세션 저장 경로를 명시적으로 지정
$sessionPath = __DIR__ . '/cache/sessions';
if (!is_dir($sessionPath)) {
    mkdir($sessionPath, 0775, true);
}
ini_set('session.save_path',   $sessionPath);
ini_set('session.cookie_path', '/');
session_name('SATPASSKR');
session_start();

header('Content-Type: application/json; charset=utf-8');

const USERS_FILE = __DIR__ . '/cache/users.json';

function respond(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

// mbstring 확장이 비활성화된 환경(PHP 8.3 + 일부 XAMPP/Apache 설치)에서도
// 안전하게 동작하도록 mb_substr을 폴백 처리 (api.php와 동일한 패턴)
function safeSubstr(string $text, int $len): string
{
    return function_exists('mb_substr') ? mb_substr($text, 0, $len) : substr($text, 0, $len);
}

if (empty($_SESSION['user']['id'])) {
    respond(401, ['ok' => false, 'error' => '로그인이 필요합니다. 다시 로그인해 주세요.']);
}

$uid = $_SESSION['user']['id'];

function loadUsers(): array
{
    if (!is_file(USERS_FILE)) return [];
    $data = json_decode(file_get_contents(USERS_FILE) ?: '[]', true);
    return is_array($data) ? $data : [];
}

function saveUsers(array $users): void
{
    file_put_contents(USERS_FILE, json_encode($users, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
}

// ── GET: 관측지 조회 ──
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $users = loadUsers();
    foreach ($users as $u) {
        if ($u['id'] === $uid) {
            respond(200, ['ok' => true, 'station' => $u['station'] ?? null]);
        }
    }
    respond(404, ['ok' => false, 'error' => '사용자를 찾을 수 없습니다.']);
}

// ── POST: 관측지 저장 ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $lat   = filter_input(INPUT_POST, 'lat',   FILTER_VALIDATE_FLOAT);
    $lon   = filter_input(INPUT_POST, 'lon',   FILTER_VALIDATE_FLOAT);
    $minEl = filter_input(INPUT_POST, 'minEl', FILTER_VALIDATE_FLOAT);
    $label = trim($_POST['label'] ?? '내 관측지');

    if ($lat   === false || $lat   === null || $lat   < -90  || $lat   > 90)  respond(400, ['ok'=>false,'error'=>'위도는 -90~90 사이 숫자로 입력하세요.']);
    if ($lon   === false || $lon   === null || $lon   < -180 || $lon   > 180) respond(400, ['ok'=>false,'error'=>'경도는 -180~180 사이 숫자로 입력하세요.']);
    if ($minEl === false || $minEl === null || $minEl < 0    || $minEl > 80)  respond(400, ['ok'=>false,'error'=>'최소 앙각은 0~80° 사이로 입력하세요.']);

    $station = [
        'label' => safeSubstr($label, 30),
        'lat'   => round($lat,  6),
        'lon'   => round($lon,  6),
        'minEl' => round($minEl, 1),
    ];

    $users = loadUsers();
    $found = false;
    foreach ($users as &$u) {
        if ($u['id'] === $uid) {
            $u['station'] = $station;
            $found = true;
            break;
        }
    }
    unset($u);

    if (!$found) respond(404, ['ok' => false, 'error' => '사용자를 찾을 수 없습니다.']);

    saveUsers($users);
    respond(200, ['ok' => true, 'station' => $station]);
}

respond(405, ['ok' => false, 'error' => 'GET 또는 POST 요청만 허용합니다.']);
