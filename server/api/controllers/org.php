<?php
declare(strict_types=1);

// 재정 단위(위원회·부서·예비비·기타)와 담당자(회계·부서장·위원장), 교인 찾기.
// 단위는 해마다 한 벌이다. 교적 조직표에서 가져오거나 작년 것을 복사한다.

const FM_UNIT_KINDS = ['위원회', '부서', '기타', '예비비'];
const FM_UNIT_ROLES = ['회계', '부서장', '위원장'];

/** 초성 문자열 (한글이 아닌 글자는 그대로) */
function chosung(string $s): string
{
    static $cho = ['ㄱ', 'ㄲ', 'ㄴ', 'ㄷ', 'ㄸ', 'ㄹ', 'ㅁ', 'ㅂ', 'ㅃ', 'ㅅ', 'ㅆ', 'ㅇ', 'ㅈ', 'ㅉ', 'ㅊ', 'ㅋ', 'ㅌ', 'ㅍ', 'ㅎ'];
    $out = '';
    foreach (mb_str_split($s) as $ch) {
        $c = mb_ord($ch);
        $out .= ($c >= 0xAC00 && $c <= 0xD7A3) ? $cho[intdiv($c - 0xAC00, 588)] : $ch;
    }
    return $out;
}

function person_label(array $p): array
{
    $by = $p['birth_date'] ? (int)substr((string)$p['birth_date'], 0, 4) : null;
    $tail = $p['phone'] ? substr(preg_replace('/\D/', '', (string)$p['phone']), -4) : '';
    return [
        'id' => $p['id'],
        'name' => $p['name'] . ($p['name_note'] ? ' ' . $p['name_note'] : ''),
        'birthYear' => $by,
        'gender' => $p['gender'],
        'phoneTail' => $tail,
        'head' => $p['head_name'] ?? null, // 같은 세대의 세대주 이름 (동명이인을 구분하는 데 씀)
    ];
}

/** 교인 찾기: 이름 일부, 초성(ㄱㅅㅇ), 전화 끝자리 중 하나로. 동명이인을 구분할 수 있게 출생연도·세대주·전화 끝 4자리를 함께 돌려준다. */
route('GET', '/people/search', function () {
    $a = require_account();
    if (!is_admin_account($a) && !has_role($a, '재정 담당', '재정위원장')) json_fail('forbidden', '교인을 찾을 권한이 없습니다.', 403);
    $qs = trim((string)($_GET['q'] ?? ''));
    if ($qs === '' || mb_strlen($qs) > 30) json_out(['items' => []]);
    $isCho = (bool)preg_match('/^[ㄱ-ㅎ]+$/u', $qs);
    $digits = preg_replace('/\D/', '', $qs);
    $isPhone = strlen($digits) >= 3 && strlen($digits) === strlen(str_replace(' ', '', $qs));

    $sql = 'SELECT p.id, p.name, p.name_note, p.birth_date, p.gender, p.phone,
                   (SELECT p2.name FROM ' . cmt('household_members') . ' m1
                      JOIN ' . cmt('household_members') . ' m2 ON m2.household_id = m1.household_id AND m2.relation = \'본인\'
                      JOIN ' . cmt('people') . ' p2 ON p2.id = m2.person_id
                     WHERE m1.person_id = p.id LIMIT 1) AS head_name
              FROM ' . cmt('people') . ' p WHERE p.purged_on IS NULL';
    $params = [];
    if ($isPhone) {
        $sql .= ' AND REPLACE(p.phone, \'-\', \'\') LIKE ?';
        $params[] = '%' . $digits;
    } elseif (!$isCho) {
        $sql .= ' AND p.name LIKE ?';
        $params[] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $qs) . '%';
    }
    $sql .= ' ORDER BY p.name, p.seq LIMIT ' . ($isCho ? 3000 : 30);
    $out = [];
    foreach (rows($sql, $params) as $p) {
        if ($isCho && !str_starts_with(chosung($p['name']), $qs)) continue;
        $out[] = person_label($p);
        if (count($out) >= 30) break;
    }
    json_out(['items' => $out]);
});

function cm_person(string $id): array
{
    $p = row('SELECT id, name, name_note FROM ' . cmt('people') . ' WHERE id = ? AND purged_on IS NULL', [$id]);
    if (!$p) json_fail('invalid', '교적에서 해당 교인을 찾을 수 없습니다.', 422);
    return $p;
}

function units_payload(int $year): array
{
    $units = rows('SELECT id, parent_id, kind, name, cm_org_unit_id, sort_order FROM ' . t('units') . ' WHERE fiscal_year = ? ORDER BY sort_order, id', [$year]);
    $roles = [];
    $ids = array_column($units, 'id');
    if ($ids) {
        foreach (rows('SELECT id, unit_id, role, person_id, person_name FROM ' . t('unit_roles') . ' WHERE unit_id IN (' . marks($ids) . ') ORDER BY sort_order, id', $ids) as $r) {
            $roles[(int)$r['unit_id']][] = ['id' => (int)$r['id'], 'role' => $r['role'], 'personId' => $r['person_id'], 'name' => $r['person_name']];
        }
    }
    return array_map(fn($u) => [
        'id' => (int)$u['id'], 'parentId' => $u['parent_id'] === null ? null : (int)$u['parent_id'], 'kind' => $u['kind'], 'name' => $u['name'],
        'fromCm' => $u['cm_org_unit_id'] !== null, 'roles' => $roles[(int)$u['id']] ?? [],
    ], $units);
}

route('GET', '/units', function () {
    require_account();
    $year = in_int($_GET, 'year', 2000, 2100, '연도');
    json_out(['items' => units_payload($year)]);
});

/** 내가 담당자로 지정된 단위 (부서 회계·부서장·위원장으로 로그인하면 자기 부서만 보이게 하는 기준) */
route('GET', '/my/units', function () {
    $a = require_account();
    $pid = $a['cm']['person_id'];
    if (!$pid) json_out(['items' => []]);
    $year = isset($_GET['year']) ? in_int($_GET, 'year', 2000, 2100, '연도')
        : ((int)val('SELECT year FROM ' . t('fiscal_years') . " WHERE status = '진행' ORDER BY year DESC LIMIT 1") ?: (int)val('SELECT MAX(year) FROM ' . t('fiscal_years')));
    $items = rows(
        'SELECT u.id, u.name, u.kind, r.role FROM ' . t('unit_roles') . ' r JOIN ' . t('units') . ' u ON u.id = r.unit_id WHERE r.person_id = ? AND u.fiscal_year = ? ORDER BY u.sort_order, u.id',
        [$pid, $year]
    );
    json_out(['year' => $year, 'items' => array_map(fn($r) => ['id' => (int)$r['id'], 'name' => $r['name'], 'kind' => $r['kind'], 'role' => $r['role']], $items)]);
});

function require_year_open(int $year): void
{
    $st = val('SELECT status FROM ' . t('fiscal_years') . ' WHERE year = ?', [$year]);
    if ($st === false || $st === null) json_fail('not_found', $year . '년 회계연도가 없습니다. 먼저 회계연도를 만들어 주세요.', 404);
    if ($st === '마감') json_fail('closed', $year . '년은 마감되어 재정 단위를 바꿀 수 없습니다.', 409);
}

function ensure_special_units(int $year): void
{
    foreach ([['예비비', '예비비'], ['기타', '기타 지출']] as [$kind, $name]) {
        if (!val('SELECT COUNT(*) FROM ' . t('units') . ' WHERE fiscal_year = ? AND kind = ?', [$year, $kind])) {
            q('INSERT INTO ' . t('units') . ' (fiscal_year, kind, name, sort_order) VALUES (?,?,?,?)', [$year, $kind, $name, $kind === '예비비' ? 9000 : 9100]);
        }
    }
}

/** 교적 조직표에서 가져오기: 최상위는 위원회, 그 아래는 부서. 이미 가져온 것은 건너뛰고, 위원장·부서장은 교적의 책임자로 미리 채운다. */
route('POST', '/units/import-cm', function () {
    require_staff();
    $year = in_int(body(), 'year', 2000, 2100, '연도');
    require_year_open($year);
    $added = tx(function () use ($year) {
        $org = rows('SELECT id, parent_id, name, leader_id, sort_order FROM ' . cmt('org_units') . ' ORDER BY sort_order, seq');
        $map = [];
        foreach (rows('SELECT id, cm_org_unit_id FROM ' . t('units') . ' WHERE fiscal_year = ? AND cm_org_unit_id IS NOT NULL', [$year]) as $r) $map[$r['cm_org_unit_id']] = (int)$r['id'];
        $n = 0;
        $byId = array_column($org, null, 'id');
        $pending = $org;
        for ($pass = 0; $pass < 6 && $pending; $pass++) { // 부모가 먼저 만들어지도록 몇 번 훑는다
            $left = [];
            foreach ($pending as $o) {
                if (isset($map[$o['id']])) continue;
                if ($o['parent_id'] !== null && !isset($map[$o['parent_id']])) {
                    if (isset($byId[$o['parent_id']])) { $left[] = $o; continue; }
                }
                $parent = $o['parent_id'] !== null ? ($map[$o['parent_id']] ?? null) : null;
                $uid = insert_id(
                    'INSERT INTO ' . t('units') . ' (fiscal_year, parent_id, kind, name, cm_org_unit_id, sort_order) VALUES (?,?,?,?,?,?)',
                    [$year, $parent, $parent === null ? '위원회' : '부서', $o['name'], $o['id'], (int)$o['sort_order']]
                );
                $map[$o['id']] = $uid;
                $n++;
                if ($o['leader_id']) {
                    $p = row('SELECT id, name, name_note FROM ' . cmt('people') . ' WHERE id = ? AND purged_on IS NULL', [$o['leader_id']]);
                    if ($p) q('INSERT INTO ' . t('unit_roles') . ' (unit_id, role, person_id, person_name) VALUES (?,?,?,?)', [$uid, $parent === null ? '위원장' : '부서장', $p['id'], $p['name']]);
                }
            }
            $pending = $left;
        }
        ensure_special_units($year);
        return $n;
    });
    change_log('units', $year, 'import_cm', null, $added . '개');
    json_out(['added' => $added]);
});

/** 작년(다른 해) 단위와 담당자를 복사한다. 이미 단위가 있는 해에는 복사하지 않는다. */
route('POST', '/units/copy-year', function () {
    require_staff();
    $b = body();
    $from = in_int($b, 'from', 2000, 2100, '복사할 연도');
    $to = in_int($b, 'to', 2000, 2100, '대상 연도');
    if ($from === $to) json_fail('invalid', '같은 해로는 복사할 수 없습니다.', 422);
    require_year_open($to);
    if (val('SELECT COUNT(*) FROM ' . t('units') . ' WHERE fiscal_year = ?', [$to])) json_fail('not_empty', $to . '년에는 이미 재정 단위가 있어 복사하지 않습니다.', 409);
    $n = tx(function () use ($from, $to) {
        $map = [];
        $units = rows('SELECT * FROM ' . t('units') . ' WHERE fiscal_year = ? ORDER BY (parent_id IS NOT NULL), id', [$from]);
        foreach ($units as $u) {
            $map[(int)$u['id']] = insert_id(
                'INSERT INTO ' . t('units') . ' (fiscal_year, parent_id, kind, name, cm_org_unit_id, sort_order) VALUES (?,?,?,?,?,?)',
                [$to, $u['parent_id'] === null ? null : ($map[(int)$u['parent_id']] ?? null), $u['kind'], $u['name'], $u['cm_org_unit_id'], $u['sort_order']]
            );
        }
        foreach ($units as $u) {
            foreach (rows('SELECT role, person_id, person_name, sort_order FROM ' . t('unit_roles') . ' WHERE unit_id = ?', [$u['id']]) as $r) {
                // 교적에서 사라진 교인은 담당자 복사에서 뺀다
                if (!val('SELECT COUNT(*) FROM ' . cmt('people') . ' WHERE id = ? AND purged_on IS NULL', [$r['person_id']])) continue;
                q('INSERT INTO ' . t('unit_roles') . ' (unit_id, role, person_id, person_name, sort_order) VALUES (?,?,?,?,?)', [$map[(int)$u['id']], $r['role'], $r['person_id'], $r['person_name'], $r['sort_order']]);
            }
        }
        ensure_special_units($to);
        return count($units);
    });
    change_log('units', $to, 'copy_year', $from, $n . '개');
    json_out(['copied' => $n]);
});

route('POST', '/units', function () {
    require_staff();
    $b = body();
    $year = in_int($b, 'year', 2000, 2100, '연도');
    require_year_open($year);
    $kind = in_enum($b, 'kind', FM_UNIT_KINDS, '종류');
    $name = in_str($b, 'name', 60, true, '이름');
    $parent = isset($b['parentId']) && $b['parentId'] !== null ? in_int($b, 'parentId', 1, PHP_INT_MAX, '상위') : null;
    if ($parent !== null && (int)val('SELECT COUNT(*) FROM ' . t('units') . ' WHERE id = ? AND fiscal_year = ?', [$parent, $year]) === 0) json_fail('invalid', '상위 단위를 찾을 수 없습니다.', 422);
    $id = insert_id(
        'INSERT INTO ' . t('units') . ' (fiscal_year, parent_id, kind, name, sort_order) VALUES (?,?,?,?,?)',
        [$year, $parent, $kind, $name, (int)val('SELECT COALESCE(MAX(sort_order),0)+1 FROM ' . t('units') . ' WHERE fiscal_year = ? AND sort_order < 9000', [$year])]
    );
    change_log('unit', $id, 'create', null, $name);
    json_out(['id' => $id]);
});

route('POST', '/units/{id}', function (int $id) {
    require_staff();
    $cur = row('SELECT * FROM ' . t('units') . ' WHERE id = ?', [$id]);
    if (!$cur) json_fail('not_found', '단위를 찾을 수 없습니다.', 404);
    require_year_open((int)$cur['fiscal_year']);
    $name = in_str(body(), 'name', 60, true, '이름');
    q('UPDATE ' . t('units') . ' SET name = ? WHERE id = ?', [$name, $id]);
    if ($name !== $cur['name']) change_log('unit', $id, 'name', $cur['name'], $name);
    json_out(null);
});

/** 예산·지출·결의서가 하나도 없는 단위만 지운다. (그 밖에는 이름을 고치거나 다음 해에 복사하지 않는다.) */
route('POST', '/units/{id}/delete', function (int $id) {
    require_staff();
    $cur = row('SELECT * FROM ' . t('units') . ' WHERE id = ?', [$id]);
    if (!$cur) json_fail('not_found', '단위를 찾을 수 없습니다.', 404);
    require_year_open((int)$cur['fiscal_year']);
    $used = val('SELECT (SELECT COUNT(*) FROM ' . t('budget_items') . ' WHERE unit_id = ?) + (SELECT COUNT(*) FROM ' . t('transactions') . ' WHERE unit_id = ?) + (SELECT COUNT(*) FROM ' . t('vouchers') . ' WHERE unit_id = ?) + (SELECT COUNT(*) FROM ' . t('units') . ' WHERE parent_id = ?)', [$id, $id, $id, $id]);
    if ((int)$used > 0) json_fail('in_use', '하위 단위, 예산, 지출이 있는 단위는 지울 수 없습니다.', 409);
    q('DELETE FROM ' . t('units') . ' WHERE id = ?', [$id]);
    change_log('unit', $id, 'delete', $cur['name'], null);
    json_out(null);
});

route('POST', '/units/{id}/roles', function (int $id) {
    require_staff();
    $unit = row('SELECT * FROM ' . t('units') . ' WHERE id = ?', [$id]);
    if (!$unit) json_fail('not_found', '단위를 찾을 수 없습니다.', 404);
    require_year_open((int)$unit['fiscal_year']);
    $b = body();
    $role = in_enum($b, 'role', FM_UNIT_ROLES, '담당 역할');
    if ($role === '위원장' && $unit['kind'] !== '위원회') json_fail('invalid', '위원장은 위원회에만 지정할 수 있습니다.', 422);
    if ($role === '부서장' && $unit['kind'] !== '부서') json_fail('invalid', '부서장은 부서에만 지정할 수 있습니다.', 422);
    $p = cm_person(in_str($b, 'personId', 36, true, '교인'));
    if (val('SELECT COUNT(*) FROM ' . t('unit_roles') . ' WHERE unit_id = ? AND role = ? AND person_id = ?', [$id, $role, $p['id']])) json_fail('duplicate', '이미 지정되어 있습니다.', 409);
    // 부서장·위원장은 결재선의 한 칸이라 한 명만 둔다. 회계는 여러 명 가능.
    if ($role !== '회계' && val('SELECT COUNT(*) FROM ' . t('unit_roles') . ' WHERE unit_id = ? AND role = ?', [$id, $role])) {
        json_fail('duplicate', $role . '은(는) 한 명만 지정할 수 있어요. 먼저 기존 담당자를 빼 주세요.', 409);
    }
    $rid = insert_id('INSERT INTO ' . t('unit_roles') . ' (unit_id, role, person_id, person_name, sort_order) VALUES (?,?,?,?,?)', [$id, $role, $p['id'], $p['name'], (int)val('SELECT COALESCE(MAX(sort_order),0)+1 FROM ' . t('unit_roles') . ' WHERE unit_id = ?', [$id])]);
    change_log('unit_role', $rid, $unit['name'] . ' ' . $role, null, $p['name']);
    json_out(['id' => $rid]);
});

route('POST', '/unit-roles/{id}/delete', function (int $id) {
    require_staff();
    $r = row('SELECT r.*, u.fiscal_year, u.name AS unit_name FROM ' . t('unit_roles') . ' r JOIN ' . t('units') . ' u ON u.id = r.unit_id WHERE r.id = ?', [$id]);
    if (!$r) json_fail('not_found', '담당자를 찾을 수 없습니다.', 404);
    require_year_open((int)$r['fiscal_year']);
    q('DELETE FROM ' . t('unit_roles') . ' WHERE id = ?', [$id]);
    change_log('unit_role', $id, $r['unit_name'] . ' ' . $r['role'], $r['person_name'], null);
    json_out(null);
});
