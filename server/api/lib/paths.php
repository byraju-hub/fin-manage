<?php
declare(strict_types=1);

// 주소 계산 도우미. API 와 설치 마법사가 함께 쓴다.

/** 주소의 위 폴더. Windows 의 PHP 는 dirname('/api') 가 '\' 를 돌려주므로 슬래시로 바로잡고, 맨 위면 빈 문자열을 돌려준다. */
function fm_dirname(string $path): string
{
    $d = str_replace('\\', '/', dirname($path));
    return ($d === '/' || $d === '.' || $d === '') ? '' : rtrim($d, '/');
}

/** 사이트를 올린 폴더의 주소. 맨 위면 빈 문자열, /fm 에 올렸으면 '/fm'. api/index.php 와 install/index.php 어디서 불러도 같다. */
function fm_site_base(): string
{
    return fm_dirname(fm_dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/api/index.php')));
}
