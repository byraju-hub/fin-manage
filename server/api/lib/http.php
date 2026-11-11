<?php
declare(strict_types=1);

// 요청과 응답 도우미.

/** 모든 API 응답에 붙이는 보안 헤더. (사이트 폴더의 .htaccess 에도 같은 것이 있지만, 그 모듈이 없는 호스팅을 위해 여기서도 보낸다.) */
function fm_security_headers(): void
{
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: no-referrer');
    header("Content-Security-Policy: frame-ancestors 'self'");
    header('Permissions-Policy: camera=(self), microphone=(), geolocation=()');
    if ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')) {
        header('Strict-Transport-Security: max-age=15552000');
    }
}

/** 설치 화면(/install/)을 열어 마무리해야 하는 .htaccess 규칙(https 이동·보안 헤더)이 빠져 있는가 */
function fm_htaccess_outdated(): bool
{
    $ht = FM_ROOT . '/.htaccess';
    if (!is_file($ht)) return true;
    $s = (string)file_get_contents($ht);
    return !str_contains($s, 'fin-manage-https') || !str_contains($s, 'fin-manage-headers') || !str_contains($s, 'Referrer-Policy "no-referrer"');
}

function json_out(mixed $data = null, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}

function json_fail(string $code, string $message, int $status = 400): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['ok' => false, 'error' => ['code' => $code, 'message' => $message]], JSON_UNESCAPED_UNICODE);
    exit;
}

/** 요청 본문(JSON)을 배열로. */
function body(): array
{
    static $b = null;
    if ($b === null) {
        $raw = file_get_contents('php://input');
        $d = $raw === '' || $raw === false ? [] : json_decode($raw, true);
        if (!is_array($d)) json_fail('bad_json', '요청 내용을 읽지 못했습니다.', 400);
        $b = $d;
    }
    return $b;
}
