<?php
declare(strict_types=1);

// 백업·되살리기 (재정 관리자). 재정의 표(fm_)만 다룬다. 교적의 표와 설정 파일(암호화 열쇠 포함)은 백업에 넣지 않는다.
// 호스팅의 요청 크기 제한 때문에 되살릴 파일은 화면에서 조각으로 나눠 올린다.

const FM_BACKUP_FORMAT = 'fin-manage-backup';
const FM_BACKUP_SKIP = ['login_attempts'];

function backup_tables(): array
{
    $p = fm_config()['prefix'];
    $st = db()->prepare('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE ? ORDER BY TABLE_NAME');
    $st->execute([str_replace(['_', '%'], ['\\_', '\\%'], $p) . '%']);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $name) {
        $short = substr($name, strlen($p));
        if (!in_array($short, FM_BACKUP_SKIP, true)) $out[] = $short;
    }
    return $out;
}

function backup_dump(): array
{
    $tables = [];
    foreach (backup_tables() as $t) $tables[$t] = rows('SELECT * FROM ' . t($t));
    return ['format' => FM_BACKUP_FORMAT, 'version' => FM_VERSION, 'schema' => current_schema_version(db(), fm_config()['prefix']), 'createdAt' => date('Y-m-d H:i:s'), 'tables' => $tables];
}

/** 증빙 파일은 백업에 들어가지 않는다는 점을 알리기 위한 요약 */
route('GET', '/backup/info', function () {
    require_admin();
    $n = [];
    foreach (backup_tables() as $t) $n[$t] = (int)val('SELECT COUNT(*) FROM ' . t($t));
    $dir = FM_DATA . '/vouchers';
    $files = is_dir($dir) ? count(glob($dir . '/*/*') ?: []) : 0;
    json_out(['tables' => $n, 'voucherFiles' => $files]);
});

route('GET', '/backup/download', function () {
    $a = require_admin();
    require_reconfirm();
    $json = json_encode(backup_dump(), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    audit('백업 내려받기', '', $a);
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="fin-manage-backup-' . date('Ymd-His') . '.json"');
    header('X-Content-Type-Options: nosniff');
    echo $json;
    exit;
});

route('POST', '/backup/restore/chunk', function () {
    require_admin();
    require_reconfirm();
    $b = body();
    $id = in_str($b, 'id', 32, true, '업로드 번호');
    if (!preg_match('/^[a-f0-9]{8,32}$/', $id)) json_fail('invalid', '업로드 번호가 올바르지 않습니다.', 422);
    $i = in_int($b, 'i', 0, 2000, '조각 번호');
    $data = (string)($b['data'] ?? '');
    if ($data === '' || strlen($data) > 110000) json_fail('invalid', '조각 크기가 올바르지 않습니다.', 422);
    $dir = FM_DATA . '/restore';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    file_put_contents($dir . "/$id-$i.part", $data);
    json_out(null);
});

route('POST', '/backup/restore/apply', function () {
    $a = require_admin();
    require_reconfirm();
    $b = body();
    if (($b['confirm'] ?? '') !== '되살리기') json_fail('confirm', "확인 문구 '되살리기'를 입력해 주세요.", 422);
    $id = in_str($b, 'id', 32, true, '업로드 번호');
    if (!preg_match('/^[a-f0-9]{8,32}$/', $id)) json_fail('invalid', '업로드 번호가 올바르지 않습니다.', 422);
    $n = in_int($b, 'n', 1, 2001, '조각 수');
    $dir = FM_DATA . '/restore';
    $json = '';
    for ($i = 0; $i < $n; $i++) {
        $f = $dir . "/$id-$i.part";
        if (!is_file($f)) json_fail('missing', "조각 $i 이(가) 올라오지 않았습니다. 처음부터 다시 올려 주세요.", 422);
        $json .= file_get_contents($f);
    }
    $d = json_decode($json, true);
    if (!is_array($d) || ($d['format'] ?? '') !== FM_BACKUP_FORMAT || !is_array($d['tables'] ?? null)) json_fail('invalid', '재정 백업 파일이 아닙니다.', 422);
    $schema = current_schema_version(db(), fm_config()['prefix']);
    if ((int)($d['schema'] ?? 0) !== $schema) json_fail('schema', '백업의 구조 번호(' . (int)($d['schema'] ?? 0) . ')가 지금(' . $schema . ')과 다릅니다. 같은 버전에서 만든 백업만 되살릴 수 있어요.', 409);
    $known = backup_tables();
    foreach ($d['tables'] as $name => $rowsIn) if (!in_array($name, $known, true) || !is_array($rowsIn)) json_fail('invalid', "알 수 없는 표: $name", 422);
    // 되살리기 전 지금 상태를 파일로 남긴다 (되돌릴 수 있게). data 폴더는 웹에서 열리지 않는다.
    $bak = FM_DATA . '/backups';
    if (!is_dir($bak)) @mkdir($bak, 0700, true);
    file_put_contents($bak . '/before-restore-' . date('Ymd-His') . '.json', json_encode(backup_dump(), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    $pdo = db();
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    try {
        $pdo->beginTransaction();
        foreach ($known as $name) {
            if (!array_key_exists($name, $d['tables'])) continue;
            q('DELETE FROM ' . t($name));
            foreach ($d['tables'][$name] as $r) {
                if (!is_array($r) || !$r) continue;
                $cols = array_keys($r);
                foreach ($cols as $c) if (!preg_match('/^[a-z_0-9]+$/', (string)$c)) throw new RuntimeException('열 이름 오류');
                q('INSERT INTO ' . t($name) . ' (`' . implode('`,`', $cols) . '`) VALUES (' . marks($cols) . ')', array_values($r));
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        fm_log('error', 'restore: ' . $e->getMessage());
        json_fail('restore_failed', '되살리지 못했습니다. 기존 자료는 그대로입니다. (' . $e->getMessage() . ')', 500);
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    foreach (glob($dir . "/$id-*.part") ?: [] as $f) @unlink($f);
    audit('백업 되살리기', (string)($d['createdAt'] ?? ''), $a);
    json_out(['createdAt' => $d['createdAt'] ?? null]);
});
