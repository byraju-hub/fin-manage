<?php
declare(strict_types=1);

// 배포용 zip 은 주소의 앞부분(설치 폴더)을 모르는 채로 만들어져 있어서, 설치할 때 실제 폴더 이름으로 바꿔 넣는다.
// 새 버전 zip 을 덮어쓴 뒤에도 이 화면을 한 번 열면 같은 작업이 다시 된다. 몇 번을 해도 결과가 같다.

const FM_PLACEHOLDER = '/__FM_BASE__';

/** 주소가 http:// 로 들어오면 https:// 로 보내는 규칙. 비밀번호와 헌금 내역이 암호화 없이 오가지 않게 한다.
 *  내 컴퓨터(localhost)에서 시험할 때는 건드리지 않고, 호스팅 앞단이 https 를 처리하는 경우(X-Forwarded-Proto)도 인정한다. */
const FM_HTTPS_RULES = "# fin-manage-https: http 로 들어오면 https 로\n"
    . "RewriteCond %{HTTPS} !=on\n"
    . "RewriteCond %{HTTP:X-Forwarded-Proto} !=https\n"
    . "RewriteCond %{HTTP_HOST} !^(localhost|127\\.0\\.0\\.1)(:\\d+)?$ [NC]\n"
    . "RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]\n";

/** 보안 헤더: 다른 사이트가 이 화면을 액자처럼 끼워 넣지 못하게 하고, 브라우저가 파일 종류를 멋대로 추측하지 못하게 한다. */
const FM_HEADER_RULES = "# fin-manage-headers: 보안 헤더\n"
    . "<IfModule mod_headers.c>\n"
    . "  Header always set X-Content-Type-Options \"nosniff\"\n"
    . "  Header always set X-Frame-Options \"SAMEORIGIN\"\n"
    . "  Header always set Referrer-Policy \"strict-origin-when-cross-origin\"\n"
    . "  Header always set Permissions-Policy \"camera=(self), microphone=(), geolocation=()\"\n"
    . "  Header always set Content-Security-Policy \"frame-ancestors 'self'; base-uri 'self'; object-src 'none'\"\n"
    . "  <IfModule mod_authz_core.c>\n"
    . "    Header always set Strict-Transport-Security \"max-age=15552000\" \"expr=%{HTTPS} == 'on'\"\n"
    . "  </IfModule>\n"
    . "</IfModule>\n";

/** 아직 바꾸지 않은 파일이 있는가 (zip 을 새로 풀었거나 처음 설치한 직후) */
function fm_needs_finalize(): bool
{
    $index = FM_ROOT . '/index.html';
    if (!is_file($index)) return false;
    $ht = FM_ROOT . '/.htaccess';
    $h = is_file($ht) ? (string)file_get_contents($ht) : '';
    return str_contains((string)file_get_contents($index), FM_PLACEHOLDER) || !str_contains($h, 'fin-manage-https') || !str_contains($h, 'fin-manage-headers');
}

/** 화면 파일 안의 자리표시자를 실제 폴더 주소로 바꾸고, 주소 처리용 .htaccess 를 만든다. 바꾼 파일 수를 돌려준다. */
function fm_finalize_web(): int
{
    $base = fm_site_base();
    $changed = 0;
    $skip = ['api', 'data', 'migrations', 'install'];
    $ext = ['html', 'js', 'css', 'json', 'svg', 'txt', 'webmanifest', 'map'];
    $it = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator(FM_ROOT, FilesystemIterator::SKIP_DOTS),
        function (SplFileInfo $f) use ($skip) {
            // 맨 위 폴더 바로 아래의 서버용 폴더는 건드리지 않는다.
            if ($f->isDir() && $f->getPath() === FM_ROOT && in_array($f->getFilename(), $skip, true)) return false;
            return true;
        }
    ));
    foreach ($it as $f) {
        if (!$f->isFile() || !in_array(strtolower($f->getExtension()), $ext, true)) continue;
        $s = file_get_contents($f->getPathname());
        if ($s === false || !str_contains($s, FM_PLACEHOLDER)) continue;
        if (file_put_contents($f->getPathname(), str_replace(FM_PLACEHOLDER, $base, $s), LOCK_EX) !== false) $changed++;
    }

    // 주소 처리 규칙. 이미 있으면(직접 고친 경우 포함) 건드리지 않는다.
    $ht = FM_ROOT . '/.htaccess';
    if (!is_file($ht)) {
        $rules = "# fin-manage: 화면(SPA) 주소 처리\n"
            . "RewriteEngine On\n"
            . 'RewriteBase ' . $base . "/\n"
            . FM_HTTPS_RULES
            . "RewriteCond %{REQUEST_FILENAME} !-f\n"
            . "RewriteCond %{REQUEST_FILENAME} !-d\n"
            . "RewriteRule ^ index.html [L]\n\n"
            . "<IfModule mod_headers.c>\n"
            . "  <FilesMatch \"\\.html$\">\n    Header set Cache-Control \"no-cache\"\n  </FilesMatch>\n"
            . "</IfModule>\n\n"
            . FM_HEADER_RULES;
        @file_put_contents($ht, $rules, LOCK_EX);
    } elseif (!str_contains((string)file_get_contents($ht), 'fin-manage-https')) {
        $cur = (string)file_get_contents($ht);
        $new = preg_replace('/^RewriteEngine On\R/mi', '$0' . addcslashes(FM_HTTPS_RULES, '\\$'), $cur, 1, $n);
        if ($n === 1) @file_put_contents($ht, $new, LOCK_EX);
    }
    if (is_file($ht) && !str_contains((string)file_get_contents($ht), 'fin-manage-headers')) {
        @file_put_contents($ht, "\n" . FM_HEADER_RULES, FILE_APPEND | LOCK_EX);
    }
    return $changed;
}
