<?php
declare(strict_types=1);

// 데이터베이스 구조(마이그레이션)가 빈 DB 에 처음부터 끝까지 적용되는지 확인한다. (CI 에서도 쓴다.)
// 사용: php tests/migrate_check.php [host] [db] [user] [pass]
require __DIR__ . '/../server/api/lib/migrate.php';

[$host, $db, $user, $pass] = [$argv[1] ?? '127.0.0.1', $argv[2] ?? 'fm_ci', $argv[3] ?? 'root', $argv[4] ?? ''];
$pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$dir = __DIR__ . '/../server/migrations';
$p = 'fm_';
$n = run_migrations($pdo, $p, $dir);
$latest = latest_schema_version($dir);
$now = current_schema_version($pdo, $p);
$tables = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'fm\_%'")->fetchColumn();
echo "적용한 파일 {$n}개, 구조 번호 {$now}/{$latest}, 표 {$tables}개\n";
if ($now !== $latest || $tables < 28) { fwrite(STDERR, "마이그레이션이 끝까지 적용되지 않았습니다.\n"); exit(1); }
// 다시 돌려도 아무것도 하지 않아야 한다
if (run_migrations($pdo, $p, $dir) !== 0) { fwrite(STDERR, "이미 적용된 마이그레이션이 다시 실행되었습니다.\n"); exit(1); }
echo "OK\n";
