<?php
/**
 * auth.php — SatPass KR 인증 처리
 */

declare(strict_types=1);

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
const MIN_PW_LEN = 6;

function respond(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function loadUsers(): array
{
    if (!is_file(USERS_FILE)) return [];
    $raw = file_get_contents(USERS_FILE);
    $data = json_decode($raw ?: '[]', true);
    return is_array($data) ? $data : [];
}

function saveUsers(array $users): void
{
    if (!is_dir(dirname(USERS_FILE))) {
        mkdir(dirname(USERS_FILE), 0775, true);
    }
    file_put_contents(USERS_FILE, json_encode($users, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
}

function safeUser(array $u): array
{
    return ['id' => $u['id'], 'username' => $u['username'], 'created_at' => $u['created_at']];
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// ── GET: 현재 세션 확인 ──
if ($method === 'GET' && $action === 'me') {
    if (!empty($_SESSION['user'])) {
        respond(200, ['ok' => true, 'user' => $_SESSION['user']]);
    }
    respond(200, ['ok' => false, 'user' => null]);
}

if ($method !== 'POST') {
    respond(405, ['ok' => false, 'error' => 'POST 요청만 허용합니다.']);
}

// ── 로그아웃 ──
if ($action === 'logout') {
    $_SESSION = [];
    session_destroy();
    respond(200, ['ok' => true]);
}

$username = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';

if ($username === '' || $password === '') {
    respond(400, ['ok' => false, 'error' => '아이디와 비밀번호를 모두 입력하세요.']);
}
if (!preg_match('/^[a-zA-Z0-9_]{3,20}$/', $username)) {
    respond(400, ['ok' => false, 'error' => '아이디는 영문·숫자·밑줄 3~20자로 입력하세요.']);
}

// ── 회원가입 ──
if ($action === 'register') {
    if (strlen($password) < MIN_PW_LEN) {
        respond(400, ['ok' => false, 'error' => '비밀번호는 6자 이상이어야 합니다.']);
    }
    $users = loadUsers();
    foreach ($users as $u) {
        if (strtolower($u['username']) === strtolower($username)) {
            respond(409, ['ok' => false, 'error' => '이미 사용 중인 아이디입니다.']);
        }
    }
    $newUser = [
        'id'            => 'u_' . bin2hex(random_bytes(8)),
        'username'      => $username,
        'password_hash' => password_hash($password, PASSWORD_BCRYPT),
        'created_at'    => gmdate('Y-m-d\TH:i:s\Z'),
        'station'       => null,
    ];
    $users[] = $newUser;
    saveUsers($users);
    $_SESSION['user'] = safeUser($newUser);
    respond(201, ['ok' => true, 'user' => $_SESSION['user']]);
}

// ── 로그인 ──
if ($action === 'login') {
    $users = loadUsers();
    foreach ($users as $u) {
        if (strtolower($u['username']) === strtolower($username)) {
            if (password_verify($password, $u['password_hash'])) {
                $_SESSION['user'] = safeUser($u);
                respond(200, ['ok' => true, 'user' => $_SESSION['user']]);
            }
            break;
        }
    }
    respond(401, ['ok' => false, 'error' => '아이디 또는 비밀번호가 올바르지 않습니다.']);
}

respond(400, ['ok' => false, 'error' => '알 수 없는 action입니다.']);
