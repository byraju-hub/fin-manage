<?php
declare(strict_types=1);

// 데이터베이스 구조 만들기와 업그레이드. 설치 마법사와 "업데이트 적용"에서 함께 쓴다.
// 이 파일의 함수는 db()/fm_config() 에 기대지 않고, 받은 PDO 와 접두어만 쓴다. (설치 중에는 설정 파일이 아직 없다.)

/** 접속 정보에서 DSN 을 만든다. 접속 주소에 포트를 붙여 쓸 수 있다. 예: localhost:3307 */
function fm_dsn(array $d): string
{
    $host = (string)$d['host'];
    $port = '';
    if (preg_match('/^(.+):(\d{1,5})$/', $host, $m)) {
        $host = $m[1];
        $port = ';port=' . $m[2];
    }
    return 'mysql:host=' . $host . $port . ';dbname=' . $d['name'] . ';charset=utf8mb4';
}

/** 접두어는 영문 소문자로 시작하고 밑줄로 끝나는 짧은 글자만 허용한다. 예: fm_ */
function valid_prefix(string $p): bool
{
    return (bool)preg_match('/^[a-z][a-z0-9]{0,14}_$/', $p);
}

/** 접두어가 붙은 표가 이미 하나라도 있으면 true */
function prefix_in_use(PDO $pdo, string $p): bool
{
    $st = $pdo->prepare('SHOW TABLES LIKE ?');
    $st->execute([str_replace(['_', '%'], ['\\_', '\\%'], $p) . '%']);
    return (bool)$st->fetchColumn();
}

function current_schema_version(PDO $pdo, string $p): int
{
    try {
        $st = $pdo->prepare("SELECT v FROM `{$p}meta` WHERE k = 'schema_version'");
        $st->execute();
        $v = $st->fetchColumn();
        return $v === false ? 0 : (int)json_decode((string)$v);
    } catch (PDOException) {
        return 0; // 표가 아직 없다
    }
}

/** @return string[] 번호 순서의 마이그레이션 파일 경로 */
function migration_files(string $dir): array
{
    $files = glob($dir . '/[0-9][0-9][0-9]_*.php') ?: [];
    sort($files);
    return $files;
}

function latest_schema_version(string $dir): int
{
    $files = migration_files($dir);
    return $files ? (int)substr(basename(end($files)), 0, 3) : 0;
}

/** 아직 적용하지 않은 마이그레이션을 순서대로 적용한다. @return int 적용한 파일 수 */
function run_migrations(PDO $pdo, string $p, string $dir): int
{
    $current = current_schema_version($pdo, $p);
    $applied = 0;
    foreach (migration_files($dir) as $file) {
        $n = (int)substr(basename($file), 0, 3);
        if ($n <= $current) continue;
        $statements = require $file;
        foreach ($statements as $sql) {
            if (is_callable($sql)) $sql($pdo, $p); // 문장 하나로 안 되는 변경은 함수로 적는다
            else $pdo->exec(str_replace('{p}', $p, $sql));
        }
        $st = $pdo->prepare("INSERT INTO `{$p}meta` (k, v) VALUES ('schema_version', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)");
        $st->execute([json_encode($n)]);
        $applied++;
    }
    return $applied;
}
