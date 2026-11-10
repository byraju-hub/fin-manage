<?php
// 개발용(PHP 내장 서버): Apache 의 .htaccess 규칙을 흉내 낸다. 배포 서버에서는 쓰지 않는다.
//   php -S 127.0.0.1:8091 -t server server/dev-router.php      (그리고 다른 창에서 npm run dev)
// /api/ 아래 없는 파일은 api/index.php 로 보낸다. 설치 마법사는 /install/ 로 연다.
$uri = rawurldecode((string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
if (preg_match('#^/(data|migrations)(/|$)#', $uri)) { http_response_code(403); echo 'forbidden'; return true; }
if (str_starts_with($uri, '/api/') && !is_file(__DIR__ . $uri)) {
    $_SERVER['SCRIPT_NAME'] = '/api/index.php';
    chdir(__DIR__ . '/api');
    require __DIR__ . '/api/index.php';
    return true;
}
return false;
