<?php
declare(strict_types=1);

// fin-manage API 공통 시작 파일. 모든 요청이 api/index.php 를 거쳐 여기로 온다.

const FM_VERSION = '0.1.0';
define('FM_API_DIR', dirname(__DIR__));
define('FM_ROOT', dirname(FM_API_DIR));     // 사이트 폴더 (예: /fm)
define('FM_DATA', FM_ROOT . '/data');       // 설정·업로드·로그. 웹에서 직접 열리지 않는다.

date_default_timezone_set('Asia/Seoul');
mb_internal_encoding('UTF-8');
ini_set('display_errors', '0');

require __DIR__ . '/paths.php';
require __DIR__ . '/http.php';
fm_security_headers();
require __DIR__ . '/db.php';
require __DIR__ . '/router.php';
require __DIR__ . '/util.php';
require __DIR__ . '/cmlink.php';
require __DIR__ . '/auth.php';

/** 설치 때 만들어지는 설정(DB 접속 정보 등). 설치 전이면 null. */
function fm_config(): ?array
{
    static $c = false;
    if ($c === false) {
        $f = FM_DATA . '/config.php';
        $c = is_file($f) ? require $f : null;
    }
    return $c;
}

// 처리하지 못한 오류는 내용을 숨기고 기록만 남긴다.
set_exception_handler(function (Throwable $e): void {
    fm_log('error', $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    if (!headers_sent()) {
        json_fail('server_error', '서버에서 문제가 생겼습니다. 잠시 뒤 다시 시도해 주세요.', 500);
    }
});

function fm_log(string $level, string $message): void
{
    $line = date('Y-m-d H:i:s') . " [$level] " . str_replace("\n", ' ', $message) . "\n";
    @file_put_contents(FM_DATA . '/app.log', $line, FILE_APPEND | LOCK_EX);
}
