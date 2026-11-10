<?php
declare(strict_types=1);

// 교적 관리(ch-manage)와의 연결. 재정은 교적이 같은 DB 에 설치되어 있어야 동작한다.
// 교적의 표는 읽기만 한다. (예외: 통합 로그인의 1회용 입장권 삭제 — 이후 단계에서 추가)

/** 이 번호보다 낮은 교적(ch-manage)에는 설치하지 않는다 */
const FM_MIN_CM_SCHEMA = 6;

/** 재정이 읽는 교적 표와 열. 하나라도 없으면 교적 구조가 달라진 것이므로 연결을 막고 이유를 알려 준다. */
const FM_CM_REQUIRED = [
    'users' => ['id', 'login_id', 'password_hash', 'name', 'person_id', 'status', 'is_super', 'session_epoch', 'must_change_password', 'is_demo'],
    'people' => ['id', 'name', 'birth_date', 'gender', 'phone', 'address_zip', 'address1', 'address2', 'category_id'],
    'households' => ['id', 'no'],
    'household_members' => ['household_id', 'person_id', 'relation'],
    'org_units' => ['id', 'parent_id', 'name', 'leader_id', 'sort_order'],
    'site_settings' => ['id', 'church_info'],
    'meta' => ['k', 'v'],
];

/**
 * 교적이 이 DB 에 제대로 설치되어 있는지 확인한다. 설치 마법사와 API 가 함께 쓴다. (설치 중에는 설정 파일이 없으므로 PDO 를 직접 받는다.)
 * @return array{ok: bool, reason: string, message: string, schema: int, people: int}
 */
function fm_cm_check(PDO $pdo, string $cmPrefix, string $myPrefix): array
{
    $fail = fn(string $reason, string $message, int $schema = 0) => ['ok' => false, 'reason' => $reason, 'message' => $message, 'schema' => $schema, 'people' => 0];
    if (!valid_prefix($cmPrefix) || $cmPrefix === $myPrefix) {
        return $fail('same_prefix', '교적의 표 이름 접두어는 재정과 달라야 하고, 영문 소문자로 시작해 밑줄(_)로 끝나야 해요. 예: cm_');
    }
    $st = $pdo->prepare('SELECT TABLE_NAME AS t, COLUMN_NAME AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE ?');
    $st->execute([str_replace(['_', '%'], ['\\_', '\\%'], $cmPrefix) . '%']);
    $have = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $have[$r['t']][$r['c']] = true;
    if (empty($have[$cmPrefix . 'users']) || empty($have[$cmPrefix . 'people'])) {
        return $fail('not_installed', "이 데이터베이스에서 교적 관리('{$cmPrefix}' 로 시작하는 표)를 찾지 못했어요. 재정은 교적 관리가 같은 데이터베이스에 먼저 설치되어 있어야 해요.");
    }
    foreach (FM_CM_REQUIRED as $table => $cols) {
        foreach ($cols as $c) {
            if (empty($have[$cmPrefix . $table][$c])) {
                return $fail('incompatible', "교적 관리의 구조가 달라서 연결할 수 없어요. ({$cmPrefix}{$table}.{$c} 이 없음) 교적 관리를 최신 버전으로 올려 주세요.");
            }
        }
    }
    $v = $pdo->query("SELECT v FROM `{$cmPrefix}meta` WHERE k = 'schema_version'")->fetchColumn();
    $schema = $v === false ? 0 : (int)json_decode((string)$v);
    if ($schema < FM_MIN_CM_SCHEMA) {
        return $fail('schema_old', "교적 관리의 구조 번호가 {$schema} 이에요. 재정은 " . FM_MIN_CM_SCHEMA . ' 이상이 필요하니, 교적 관리를 최신 버전으로 올려 주세요.', $schema);
    }
    $people = (int)$pdo->query("SELECT COUNT(*) FROM `{$cmPrefix}people`")->fetchColumn();
    return ['ok' => true, 'reason' => '', 'message' => '', 'schema' => $schema, 'people' => $people];
}

/** 교적의 교회 정보(이름·고유번호·주소·대표자 …). 읽지 못하면 빈 배열. */
function fm_cm_church(PDO $pdo, string $cmPrefix): array
{
    try {
        $j = $pdo->query("SELECT church_info FROM `{$cmPrefix}site_settings` WHERE id = 1")->fetchColumn();
        $d = $j === false ? null : json_decode((string)$j, true);
        return is_array($d) ? $d : [];
    } catch (Throwable) {
        return [];
    }
}

/** 설치된 뒤 API 안에서 쓰는 교적 연결 상태 (요청 안에서 한 번만 확인) */
function cm_status(): array
{
    static $s = null;
    if ($s !== null) return $s;
    $c = fm_config() ?? [];
    return $s = fm_cm_check(db(), $c['cm_prefix'] ?? 'cm_', $c['prefix'] ?? 'fm_');
}
