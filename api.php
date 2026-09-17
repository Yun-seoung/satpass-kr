<?php
/**
 * api.php — SatPass KR 외부 데이터 수집·캐시 프록시
 *
 * 브라우저가 외부 사이트를 직접 부르지 않고, 이 파일이 대신 받아서 cache/ 폴더에 저장한 뒤 돌려준다.
 *   - CORS 문제 회피
 *   - CelesTrak 사용 규칙(2시간에 1번 이하, 오류 시 재요청 금지) 준수
 *   - 외부 장애 시 마지막으로 받은 데이터로 대체(stale)
 *
 * 사용법
 *   api.php?src=kp               NOAA SWPC 관측 Kp        → [{time_tag, kp, ...}]
 *   api.php?src=kp_forecast      NOAA SWPC Kp 예보(3일)   → [{time_tag, kp, observed, noaa_scale}]
 *   api.php?src=gp&catnr=40536   CelesTrak 궤도요소(OMM JSON, 허용 목록만)
 *   api.php?src=sats             허용 위성 목록
 *   api.php?src=status           캐시·수집 상태(관리자 화면용)
 *
 * 응답 형식
 *   {"ok":true, "source":"kp", "fetched_at":"2026-09-15T01:00:00Z", "stale":false, "data":[...]}
 *   stale=true  → 외부 요청 실패 또는 쿨다운 중이라 예전 캐시를 돌려준 것
 *
 * 요구사항: PHP 8.1+, php.ini에서 extension=openssl 활성화, allow_url_fopen=On
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

// ───────────────────────── 설정 ─────────────────────────

const CACHE_DIR    = __DIR__ . '/cache';
const HTTP_TIMEOUT = 10; // 초
// 연락처를 넣어두면 데이터 제공처가 문제 발생 시 연락할 수 있다(예의상 권장)
const USER_AGENT   = 'SatPassKR-student-project/0.1 (contact: your-email@example.com)';

// 조회를 허용할 위성(NORAD 번호 => 표시 이름). 임의 번호 조회를 막아 공개 프록시로 악용되는 것을 방지
const ALLOWED_SATS = [
    25544 => 'ISS (ZARYA)',
    38338 => 'KOMPSAT-3 (아리랑 3호)',
    39227 => 'KOMPSAT-5 (아리랑 5호)',
    40536 => 'KOMPSAT-3A (아리랑 3A호)',
    66820 => 'KOMPSAT-7 (아리랑 7호)',
];

// ttl: 캐시 유효 시간(초), cooldown: 실패 후 재요청 금지 시간(초)
const SOURCES = [
    'kp' => [
        'url'      => 'https://services.swpc.noaa.gov/products/noaa-planetary-k-index.json',
        'ttl'      => 600,
        'cooldown' => 600,
    ],
    'kp_forecast' => [
        'url'      => 'https://services.swpc.noaa.gov/products/noaa-planetary-k-index-forecast.json',
        'ttl'      => 1800,
        'cooldown' => 900,
    ],
    'gp' => [
        // 반드시 .org 도메인 + FORMAT=JSON 명시 (.com은 301 리다이렉트 → 오류로 집계됨, FORMAT 생략 시 CSV)
        'url'      => 'https://celestrak.org/NORAD/elements/gp.php?CATNR=%d&FORMAT=JSON',
        'ttl'      => 7200, // CelesTrak 갱신 주기 2시간
        'cooldown' => 7200, // 403/404를 받으면 2시간 동안 다시 부르지 않음
    ],
];

// ───────────────────────── 공통 함수 ─────────────────────────

/** JSON 응답을 보내고 종료 */
function respond(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** 현재 시각(UTC, ISO 8601) */
function now_iso(?int $ts = null): string
{
    return gmdate('Y-m-d\TH:i:s\Z', $ts ?? time());
}

/** JSON 파일 읽기(없거나 깨졌으면 null) */
function read_json_file(string $path): ?array
{
    if (!is_file($path)) {
        return null;
    }
    $raw = file_get_contents($path);
    if ($raw === false) {
        return null;
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

/** JSON 파일 쓰기(동시 접근 대비 잠금) */
function write_json_file(string $path, array $data): void
{
    $ok = file_put_contents(
        $path,
        json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );
    if ($ok === false) {
        throw new RuntimeException("캐시 파일을 쓸 수 없습니다: $path");
    }
}

/** 오류 로그 한 줄 추가 */
function log_error(string $key, string $message): void
{
    $line = sprintf("[%s] %s: %s\n", now_iso(), $key, $message);
    file_put_contents(CACHE_DIR . '/error.log', $line, FILE_APPEND | LOCK_EX);
}

/**
 * HTTP GET. 리다이렉트는 따라가지 않는다(301도 오류로 드러나게).
 * @return array{status:int, body:string, error:?string}
 */
function http_get(string $url): array
{
    $context = stream_context_create([
        'http' => [
            'method'          => 'GET',
            'timeout'         => HTTP_TIMEOUT,
            'ignore_errors'   => true, // 4xx/5xx여도 본문과 상태코드를 받기 위함
            'follow_location' => 0,
            'header'          => "User-Agent: " . USER_AGENT . "\r\nAccept: application/json\r\n",
        ],
    ]);

    $body = @file_get_contents($url, false, $context);

    // PHP 8.4+는 http_get_last_response_headers(), 이전 버전은 $http_response_header
    $headers = function_exists('http_get_last_response_headers')
        ? (http_get_last_response_headers() ?? [])
        : ($http_response_header ?? []);

    if ($body === false && $headers === []) {
        $err = error_get_last()['message'] ?? '알 수 없는 네트워크 오류';
        return ['status' => 0, 'body' => '', 'error' => $err];
    }

    $status = 0;
    if (isset($headers[0]) && preg_match('#^HTTP/\S+\s+(\d{3})#', $headers[0], $m)) {
        $status = (int)$m[1];
    }
    return ['status' => $status, 'body' => (string)$body, 'error' => null];
}

/** 긴 문자열 자르기(mbstring이 없어도 동작) */
function short(string $text, int $len): string
{
    return function_exists('mb_substr') ? mb_substr($text, 0, $len) : substr($text, 0, $len);
}

/** 숫자 문자열이면 float로 변환 */
function to_number_if_numeric(mixed $v): mixed
{
    return (is_string($v) && is_numeric($v)) ? (float)$v : $v;
}

/**
 * SWPC 표 형식 JSON을 [{키:값}] 배열로 통일.
 *  - 새 형식(2026-03-31 이후): [{"time_tag":"...","Kp":2.33,...}, ...]
 *  - 옛 형식: [["time_tag","Kp",...], ["2026-...","2.33",...], ...]  (첫 행이 키)
 * 키 이름의 대소문자 차이(Kp/kp)는 'kp'로 맞춘다.
 */
function normalize_swpc_table(array $decoded): array
{
    if ($decoded === []) {
        return [];
    }

    $rows = [];
    $first = $decoded[0] ?? null;

    if (is_array($first) && array_is_list($first)) {
        // 옛 형식: 첫 행 = 헤더
        $header = array_map('strval', $first);
        foreach (array_slice($decoded, 1) as $row) {
            if (!is_array($row) || count($row) !== count($header)) {
                continue;
            }
            $rows[] = array_combine($header, $row);
        }
    } else {
        // 새 형식: 객체 배열
        foreach ($decoded as $row) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }
    }

    $out = [];
    foreach ($rows as $row) {
        $item = [];
        foreach ($row as $k => $v) {
            $key = strtolower((string)$k) === 'kp' ? 'kp' : (string)$k;
            $item[$key] = ($key === 'time_tag') ? (string)$v : to_number_if_numeric($v);
        }
        if (!isset($item['time_tag'], $item['kp']) || (!is_float($item['kp']) && !is_int($item['kp']))) {
            continue; // Kp 값이 없는 행은 버림
        }
        $out[] = $item;
    }
    return $out;
}

/** CelesTrak OMM JSON 검증 */
function validate_gp(array $decoded, int $catnr): array
{
    if ($decoded === [] || !array_is_list($decoded)) {
        throw new UnexpectedValueException('궤도요소가 비어 있습니다');
    }
    foreach ($decoded as $omm) {
        foreach (['OBJECT_NAME', 'EPOCH', 'MEAN_MOTION', 'NORAD_CAT_ID'] as $field) {
            if (!is_array($omm) || !array_key_exists($field, $omm)) {
                throw new UnexpectedValueException("필수 필드 누락: $field");
            }
        }
        if ((int)$omm['NORAD_CAT_ID'] !== $catnr) {
            throw new UnexpectedValueException('요청한 위성 번호와 응답이 다릅니다');
        }
    }
    return $decoded;
}

// ───────────────────────── 요청 처리 ─────────────────────────

try {
    if (!is_dir(CACHE_DIR) && !mkdir(CACHE_DIR, 0775, true) && !is_dir(CACHE_DIR)) {
        respond(500, ['ok' => false, 'error' => 'cache 폴더를 만들 수 없습니다. 쓰기 권한을 확인하세요.']);
    }

    $src = $_GET['src'] ?? '';

    // 허용 위성 목록
    if ($src === 'sats') {
        $list = [];
        foreach (ALLOWED_SATS as $id => $name) {
            $list[] = ['catnr' => $id, 'name' => $name];
        }
        respond(200, ['ok' => true, 'source' => 'sats', 'data' => $list]);
    }

    // 수집 상태(관리자용)
    if ($src === 'status') {
        $items = [];
        foreach (glob(CACHE_DIR . '/*.meta.json') ?: [] as $metaPath) {
            $meta = read_json_file($metaPath) ?? [];
            $items[] = ['key' => basename($metaPath, '.meta.json')] + $meta;
        }
        respond(200, ['ok' => true, 'source' => 'status', 'data' => $items]);
    }

    if (!isset(SOURCES[$src])) {
        respond(400, ['ok' => false, 'error' => 'src는 kp, kp_forecast, gp, sats, status 중 하나여야 합니다.']);
    }

    if (!extension_loaded('openssl')) {
        respond(500, [
            'ok'    => false,
            'error' => 'HTTPS 요청에 필요한 openssl 확장이 꺼져 있습니다. php.ini에서 ;extension=openssl 앞의 ; 를 지우고 Apache를 재시작하세요.',
        ]);
    }

    $conf = SOURCES[$src];
    $key  = $src;
    $url  = $conf['url'];
    $catnr = 0;

    if ($src === 'gp') {
        $catnrRaw = $_GET['catnr'] ?? '';
        if (!preg_match('/^\d{1,9}$/', $catnrRaw)) {
            respond(400, ['ok' => false, 'error' => 'catnr은 1~9자리 숫자여야 합니다.']);
        }
        $catnr = (int)$catnrRaw;
        if (!array_key_exists($catnr, ALLOWED_SATS)) {
            respond(403, ['ok' => false, 'error' => '허용 목록에 없는 위성입니다. api.php의 ALLOWED_SATS에 추가하세요.']);
        }
        $key = "gp_$catnr";
        $url = sprintf($url, $catnr);
    }

    $dataPath = CACHE_DIR . "/$key.json";
    $metaPath = CACHE_DIR . "/$key.meta.json";
    $cache = read_json_file($dataPath);
    $meta  = read_json_file($metaPath) ?? [];
    $now   = time();

    $cachedPayload = static function (bool $stale, ?string $note = null) use ($src, $cache, $meta): array {
        return [
            'ok'         => true,
            'source'     => $src,
            'fetched_at' => isset($meta['fetched_at']) ? now_iso((int)$meta['fetched_at']) : null,
            'stale'      => $stale,
            'note'       => $note,
            'data'       => $cache,
        ];
    };

    // 1) 캐시가 아직 유효하면 외부 요청 없이 반환
    if ($cache !== null && isset($meta['fetched_at']) && $now - (int)$meta['fetched_at'] < $conf['ttl']) {
        respond(200, $cachedPayload(false));
    }

    // 2) 최근 실패로 쿨다운 중이면 외부 요청 금지
    if (isset($meta['blocked_until']) && $now < (int)$meta['blocked_until']) {
        $note = '최근 수집 실패로 ' . now_iso((int)$meta['blocked_until']) . '까지 재요청을 멈춘 상태입니다.';
        if ($cache !== null) {
            respond(200, $cachedPayload(true, $note));
        }
        respond(503, ['ok' => false, 'source' => $src, 'error' => $note]);
    }

    // 3) 외부 요청
    $res = http_get($url);
    $failure = null;
    $normalized = null;

    if ($res['error'] !== null) {
        $failure = '네트워크 오류: ' . $res['error'];
    } elseif ($res['status'] !== 200) {
        // CelesTrak은 403 본문에 차단 사유를 적어 보낸다 → 로그에 앞부분 저장
        $failure = "HTTP {$res['status']}: " . short(trim(strip_tags($res['body'])), 200);
    } else {
        $decoded = json_decode($res['body'], true);
        if (!is_array($decoded)) {
            // 예: CelesTrak은 데이터가 없으면 200과 함께 일반 텍스트를 보낼 수 있음
            $failure = 'JSON이 아닌 응답: ' . short(trim($res['body']), 120);
        } else {
            try {
                $normalized = ($src === 'gp')
                    ? validate_gp($decoded, $catnr)
                    : normalize_swpc_table($decoded);
                if ($normalized === []) {
                    $failure = '해석 가능한 행이 없습니다(데이터 형식 변경 가능성).';
                    $normalized = null;
                }
            } catch (UnexpectedValueException $e) {
                $failure = '형식 검증 실패: ' . $e->getMessage();
            }
        }
    }

    // 4-a) 성공: 캐시 갱신
    if ($failure === null && $normalized !== null) {
        write_json_file($dataPath, $normalized);
        $meta = ['fetched_at' => $now, 'last_status' => $res['status'], 'last_error' => null, 'blocked_until' => null];
        write_json_file($metaPath, $meta);
        $cache = $normalized;
        respond(200, [
            'ok'         => true,
            'source'     => $src,
            'fetched_at' => now_iso($now),
            'stale'      => false,
            'note'       => null,
            'data'       => $normalized,
        ]);
    }

    // 4-b) 실패: 쿨다운 기록 + 로그 + 예전 캐시로 대체
    log_error($key, (string)$failure);
    $meta['last_status']   = $res['status'];
    $meta['last_error']    = $failure;
    $meta['last_error_at'] = $now;
    $meta['blocked_until'] = $now + $conf['cooldown'];
    write_json_file($metaPath, $meta);

    if ($cache !== null) {
        respond(200, $cachedPayload(true, '외부 수집 실패로 마지막 저장 데이터를 표시합니다.'));
    }
    respond(502, ['ok' => false, 'source' => $src, 'error' => $failure]);

} catch (Throwable $e) {
    respond(500, ['ok' => false, 'error' => '서버 내부 오류: ' . $e->getMessage()]);
}
