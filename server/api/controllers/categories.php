<?php
declare(strict_types=1);

// 지출 카테고리(교회 공통 관 → 항 → 목), 부서별 사용 카테고리, 부서별 지출 정리 보고서.
// 카테고리는 재정 관리자가 정하고, 예산 항목이 카테고리에 연결되어 지출이 부서 × 카테고리로 모인다.

const FM_CAT_MAX_LEVEL = 3;

/** 기본 카테고리(출발용 예시). 관리자가 고치거나 지울 수 있다. 관 → 항 → 목 */
function default_categories(): array
{
    return [
        '예배비' => ['예배운영' => ['주보·인쇄', '성찬용품', '꽃꽂이', '강사사례'], '찬양' => ['악보', '가운·의상', '악기 구입·수리', '지휘자·반주자 사례']],
        '교육비' => ['부서운영' => ['교재·공과', '간식·식비', '활동비', '선물·시상'], '행사' => ['수련회', '절기행사', '교사교육'], '장학' => ['장학금']],
        '선교·전도비' => ['선교' => ['국내선교 후원', '국외선교사 후원', '단기선교'], '전도' => ['전도용품', '훈련·교재']],
        '구제·봉사비' => ['구제' => ['성도 구제', '지역 구제'], '봉사' => ['식재료', '봉사자 용품']],
        '공동체·친교비' => ['소그룹' => ['모임비', '심방', '경조비'], '새가족' => ['환영 선물', '양육교재']],
        '인건비' => ['사례' => ['목회자 사례', '직원 급여·상여', '4대보험']],
        '관리비' => ['시설' => ['공과금', '수선·유지보수', '청소·소모품', '보험'], '차량' => ['유류비', '정비', '보험·세금'], '수양관' => ['유지비', '공과금']],
        '행정·미디어비' => ['행정' => ['사무용품', '통신비', '수수료'], '미디어' => ['방송장비', '소프트웨어·구독', '콘텐츠 제작']],
        '상회비' => ['상회' => ['총회·노회 상회비']],
        '자본지출' => ['자산취득' => ['비품·장비 구입', '건축']],
        '예비비' => ['예비비' => ['예비비']],
        '기타' => ['기타' => ['기타']],
    ];
}

/** 기본 카테고리를 넣는다(비어 있을 때만). 넣은 개수를 돌려준다. */
function seed_default_categories(): int
{
    if ((int)val('SELECT COUNT(*) FROM ' . t('expense_categories'))) return 0;
    $n = 0;
    $s1 = 1;
    foreach (default_categories() as $g => $hangs) {
        $gid = insert_id('INSERT INTO ' . t('expense_categories') . ' (parent_id, name, sort_order) VALUES (NULL,?,?)', [$g, $s1++]);
        $n++;
        $s2 = 1;
        foreach ($hangs as $h => $moks) {
            $hid = insert_id('INSERT INTO ' . t('expense_categories') . ' (parent_id, name, sort_order) VALUES (?,?,?)', [$gid, $h, $s2++]);
            $n++;
            $s3 = 1;
            foreach ($moks as $m) {
                q('INSERT INTO ' . t('expense_categories') . ' (parent_id, name, sort_order) VALUES (?,?,?)', [$hid, $m, $s3++]);
                $n++;
            }
        }
    }
    return $n;
}

/** 전체 카테고리: id, parent, name, level(1~3), 활성, 사용 여부 */
function categories_all(): array
{
    $r = rows('SELECT c.id, c.parent_id, c.name, c.sort_order, c.is_active,
                 (SELECT COUNT(*) FROM ' . t('budget_items') . ' b WHERE b.category_id = c.id) + (SELECT COUNT(*) FROM ' . t('unit_categories') . ' u WHERE u.category_id = c.id) AS used
               FROM ' . t('expense_categories') . ' c ORDER BY c.sort_order, c.id');
    $by = array_column($r, null, 'id');
    $level = function (int $id) use (&$level, $by): int {
        $p = $by[$id]['parent_id'];
        return $p === null ? 1 : 1 + $level((int)$p);
    };
    $out = [];
    foreach ($r as $c) $out[] = ['id' => (int)$c['id'], 'parentId' => $c['parent_id'] === null ? null : (int)$c['parent_id'], 'name' => $c['name'], 'level' => $level((int)$c['id']), 'active' => (int)$c['is_active'] === 1, 'sort' => (int)$c['sort_order'], 'used' => (int)$c['used']];
    return $out;
}

/** 어떤 카테고리와 그 아래 모든 카테고리 번호 */
function category_family(array $ids): array
{
    $all = rows('SELECT id, parent_id FROM ' . t('expense_categories'));
    $kids = [];
    foreach ($all as $c) if ($c['parent_id'] !== null) $kids[(int)$c['parent_id']][] = (int)$c['id'];
    $out = [];
    $stack = array_map('intval', $ids);
    while ($stack) {
        $i = array_pop($stack);
        if (isset($out[$i])) continue;
        $out[$i] = true;
        foreach ($kids[$i] ?? [] as $k) $stack[] = $k;
    }
    return array_keys($out);
}

/** 이 단위에서 예산 항목에 쓸 수 있는 카테고리인가. 단위에 지정한 카테고리가 없으면 어떤 것이든 가능. */
function unit_category_ok(int $unitId, ?int $cat): void
{
    $allowed = array_map('intval', array_column(rows('SELECT category_id FROM ' . t('unit_categories') . ' WHERE unit_id = ?', [$unitId]), 'category_id'));
    if ($cat !== null) {
        $row = row('SELECT id, is_active FROM ' . t('expense_categories') . ' WHERE id = ?', [$cat]);
        if (!$row) json_fail('invalid', '카테고리를 찾을 수 없습니다.', 422);
        if ((int)$row['is_active'] !== 1) json_fail('invalid', '사용하지 않는 카테고리입니다.', 422);
    }
    if (!$allowed) return;
    if ($cat === null) json_fail('category_required', '이 부서는 쓸 수 있는 카테고리가 정해져 있어요. 카테고리를 골라 주세요.', 422);
    if (!in_array($cat, category_family($allowed), true)) json_fail('category_not_allowed', '이 부서에서 쓸 수 없는 카테고리입니다. (재정 관리자가 부서별 사용 카테고리를 정합니다.)', 422);
}

route('GET', '/categories', function () {
    require_account();
    json_out(['items' => categories_all()]);
});

route('POST', '/categories', function () {
    $a = require_admin();
    $b = body();
    $parent = isset($b['parentId']) && $b['parentId'] !== null && $b['parentId'] !== '' ? in_int($b, 'parentId', 1, PHP_INT_MAX, '상위') : null;
    $name = in_str($b, 'name', 40, true, '이름');
    if ($parent !== null) {
        $lv = 0;
        $cur = $parent;
        while ($cur !== null && $lv < 10) {
            $r = row('SELECT parent_id FROM ' . t('expense_categories') . ' WHERE id = ?', [$cur]);
            if (!$r) json_fail('invalid', '상위 카테고리를 찾을 수 없습니다.', 422);
            $lv++;
            $cur = $r['parent_id'] === null ? null : (int)$r['parent_id'];
        }
        if ($lv >= FM_CAT_MAX_LEVEL) json_fail('invalid', '카테고리는 관·항·목 3단계까지예요.', 422);
    }
    if (val('SELECT COUNT(*) FROM ' . t('expense_categories') . ' WHERE name = ? AND parent_id <=> ?', [$name, $parent])) json_fail('duplicate', '같은 곳에 같은 이름이 있습니다.', 409);
    $id = insert_id('INSERT INTO ' . t('expense_categories') . ' (parent_id, name, sort_order) VALUES (?,?,?)', [$parent, $name, (int)val('SELECT COALESCE(MAX(sort_order),0)+1 FROM ' . t('expense_categories') . ' WHERE parent_id <=> ?', [$parent])]);
    change_log('category', $id, 'create', null, $name);
    audit('지출 카테고리 추가', $name, $a);
    json_out(['id' => $id]);
});

route('POST', '/categories/seed', function () {
    $a = require_admin();
    $n = seed_default_categories();
    if (!$n) json_fail('not_empty', '이미 카테고리가 있어요. 비어 있을 때만 예시를 넣을 수 있습니다.', 409);
    audit('지출 카테고리 예시 넣기', $n . '개', $a);
    json_out(['added' => $n]);
});

route('POST', '/categories/{id}', function (int $id) {
    $a = require_admin();
    $cur = row('SELECT * FROM ' . t('expense_categories') . ' WHERE id = ?', [$id]);
    if (!$cur) json_fail('not_found', '카테고리를 찾을 수 없습니다.', 404);
    $b = body();
    $name = array_key_exists('name', $b) ? in_str($b, 'name', 40, true, '이름') : $cur['name'];
    $active = array_key_exists('active', $b) ? (int)(bool)$b['active'] : (int)$cur['is_active'];
    if ($name !== $cur['name'] && val('SELECT COUNT(*) FROM ' . t('expense_categories') . ' WHERE name = ? AND parent_id <=> ? AND id <> ?', [$name, $cur['parent_id'], $id])) json_fail('duplicate', '같은 곳에 같은 이름이 있습니다.', 409);
    q('UPDATE ' . t('expense_categories') . ' SET name = ?, is_active = ? WHERE id = ?', [$name, $active, $id]);
    if ($name !== $cur['name']) change_log('category', $id, 'name', $cur['name'], $name);
    if ($active !== (int)$cur['is_active']) change_log('category', $id, 'active', $cur['is_active'], $active);
    json_out(null);
});

/** 같은 상위 안에서 위·아래로 옮기기 */
route('POST', '/categories/{id}/move', function (int $id) {
    require_admin();
    $dir = in_enum(body(), 'dir', ['up', 'down'], '방향');
    $cur = row('SELECT * FROM ' . t('expense_categories') . ' WHERE id = ?', [$id]);
    if (!$cur) json_fail('not_found', '카테고리를 찾을 수 없습니다.', 404);
    $sib = rows('SELECT id FROM ' . t('expense_categories') . ' WHERE parent_id <=> ? ORDER BY sort_order, id', [$cur['parent_id']]);
    $ids = array_map('intval', array_column($sib, 'id'));
    $i = array_search($id, $ids, true);
    $j = $dir === 'up' ? $i - 1 : $i + 1;
    if ($i === false || $j < 0 || $j >= count($ids)) json_out(null);
    [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
    foreach ($ids as $k => $cid) q('UPDATE ' . t('expense_categories') . ' SET sort_order = ? WHERE id = ?', [$k + 1, $cid]);
    json_out(null);
});

/** 하위가 없고 어디에도 쓰이지 않은 카테고리만 지운다. 쓰인 것은 '사용 안 함'으로 둔다. */
route('POST', '/categories/{id}/delete', function (int $id) {
    $a = require_admin();
    $cur = row('SELECT * FROM ' . t('expense_categories') . ' WHERE id = ?', [$id]);
    if (!$cur) json_fail('not_found', '카테고리를 찾을 수 없습니다.', 404);
    if (val('SELECT COUNT(*) FROM ' . t('expense_categories') . ' WHERE parent_id = ?', [$id])) json_fail('in_use', '하위 카테고리가 있어 지울 수 없어요.', 409);
    if (val('SELECT (SELECT COUNT(*) FROM ' . t('budget_items') . ' WHERE category_id = ?) + (SELECT COUNT(*) FROM ' . t('unit_categories') . ' WHERE category_id = ?)', [$id, $id])) json_fail('in_use', '예산 항목이나 부서에서 쓰고 있어 지울 수 없어요. 대신 "사용 안 함"으로 바꿔 주세요.', 409);
    q('DELETE FROM ' . t('expense_categories') . ' WHERE id = ?', [$id]);
    change_log('category', $id, 'delete', $cur['name'], null);
    audit('지출 카테고리 삭제', $cur['name'], $a);
    json_out(null);
});

// ---------------------------------------------------------------- 부서별 사용 카테고리

route('GET', '/unit-categories', function () {
    require_account();
    $year = in_int($_GET, 'year', 2000, 2100, '연도');
    $units = rows('SELECT id, parent_id, kind, name FROM ' . t('units') . ' WHERE fiscal_year = ? ORDER BY sort_order, id', [$year]);
    $map = [];
    foreach (rows('SELECT uc.unit_id, uc.category_id FROM ' . t('unit_categories') . ' uc JOIN ' . t('units') . ' u ON u.id = uc.unit_id WHERE u.fiscal_year = ?', [$year]) as $r) $map[(int)$r['unit_id']][] = (int)$r['category_id'];
    json_out(['items' => array_map(fn($u) => ['id' => (int)$u['id'], 'parentId' => $u['parent_id'] === null ? null : (int)$u['parent_id'], 'kind' => $u['kind'], 'name' => $u['name'], 'categoryIds' => $map[(int)$u['id']] ?? []], $units)]);
});

/** 한 단위의 사용 카테고리를 통째로 바꾼다. applyToChildren 이면 소속 부서에도 같은 목록을 넣는다. */
route('POST', '/units/{id}/categories', function (int $id) {
    $a = require_admin();
    $u = row('SELECT id, name FROM ' . t('units') . ' WHERE id = ?', [$id]);
    if (!$u) json_fail('not_found', '재정 단위를 찾을 수 없습니다.', 404);
    $b = body();
    $ids = $b['categoryIds'] ?? [];
    if (!is_array($ids) || count($ids) > 300) json_fail('invalid', '카테고리 목록이 올바르지 않습니다.', 422);
    $ids = array_values(array_unique(array_map('intval', $ids)));
    if ($ids && (int)val('SELECT COUNT(*) FROM ' . t('expense_categories') . ' WHERE id IN (' . marks($ids) . ')', $ids) !== count($ids)) json_fail('invalid', '없는 카테고리가 들어 있습니다.', 422);
    $targets = [$id];
    if (!empty($b['applyToChildren'])) foreach (rows('SELECT id FROM ' . t('units') . ' WHERE parent_id = ?', [$id]) as $c) $targets[] = (int)$c['id'];
    tx(function () use ($targets, $ids) {
        foreach ($targets as $t) {
            q('DELETE FROM ' . t('unit_categories') . ' WHERE unit_id = ?', [$t]);
            foreach ($ids as $c) q('INSERT INTO ' . t('unit_categories') . ' (unit_id, category_id) VALUES (?,?)', [$t, $c]);
        }
    });
    change_log('unit_categories', $id, 'set', null, count($ids) . '개' . (count($targets) > 1 ? ' (소속 부서 포함)' : ''));
    audit('부서별 사용 카테고리 지정', $u['name'] . ' ' . count($ids) . '개', $a);
    json_out(['units' => count($targets)]);
});

// ---------------------------------------------------------------- 부서별 지출 정리

/** 지출 정리: 위원회 → 부서 × 카테고리(관·항·목 중 고른 단계)별 지급 금액. 장부의 지출(취소 제외)을 기준으로 한다. */
function spending_data(array $a, int $year, ?string $from, ?string $to, int $level): array
{
    $units = budget_units($year, $a); // 보는 사람이 볼 수 있는 단위만
    $visible = array_column($units, null, 'id');
    $cats = categories_all();
    $byCat = array_column($cats, null, 'id');
    // 카테고리를 고른 단계(level)로 올려 담는다. 더 얕은 카테고리는 그대로.
    $up = function (?int $id) use (&$up, $byCat, $level): ?int {
        if ($id === null || !isset($byCat[$id])) return null;
        $c = $byCat[$id];
        return $c['level'] <= $level ? $id : $up($c['parentId']);
    };
    $sql = 'SELECT t.unit_id, bi.category_id AS cat, SUM(t.amount) AS s FROM ' . t('transactions') . ' t LEFT JOIN ' . t('budget_items') . " bi ON bi.id = t.budget_item_id WHERE t.fiscal_year = ? AND t.kind = '지출' AND t.void_at IS NULL AND t.unit_id IS NOT NULL";
    $params = [$year];
    if ($from) { $sql .= ' AND t.tx_date >= ?'; $params[] = $from . '-01'; }
    if ($to) { $sql .= ' AND t.tx_date <= ?'; $params[] = date('Y-m-t', strtotime($to . '-01')); }
    $cells = []; // unit → col → amount
    $colTotals = [];
    $tree = []; // 카테고리 번호 → 합계(조상에도 더함)
    foreach (rows($sql . ' GROUP BY t.unit_id, bi.category_id', $params) as $r) {
        $uid = (int)$r['unit_id'];
        if (!isset($visible[$uid])) continue;
        $s = (int)$r['s'];
        $cat = $r['cat'] === null ? null : (int)$r['cat'];
        $col = $up($cat) ?? 0; // 0 = 미분류
        $cells[$uid][$col] = ($cells[$uid][$col] ?? 0) + $s;
        $colTotals[$col] = ($colTotals[$col] ?? 0) + $s;
        for ($c = $cat; $c !== null && isset($byCat[$c]); $c = $byCat[$c]['parentId']) $tree[$c] = ($tree[$c] ?? 0) + $s;
    }
    $columns = [];
    foreach ($cats as $c) if ($c['level'] === $level || ($c['level'] < $level && !array_filter($cats, fn($x) => $x['parentId'] === $c['id']))) if (!empty($colTotals[$c['id']])) $columns[] = ['id' => $c['id'], 'name' => $c['name']];
    if (!empty($colTotals[0])) $columns[] = ['id' => 0, 'name' => '미분류'];
    $unitBudget = fn(array $u) => array_sum(array_column($u['items'], 'budget'));
    $rowsOut = [];
    $addRow = function (array $u, int $depth, array $cell, int $budget) use (&$rowsOut) {
        $paid = array_sum($cell);
        $rowsOut[] = ['id' => $u['id'], 'name' => $u['name'], 'kind' => $u['kind'], 'depth' => $depth, 'budget' => $budget, 'paid' => $paid, 'cells' => (object)$cell];
    };
    $children = [];
    foreach ($units as $u) if ($u['parentId'] !== null && isset($visible[$u['parentId']])) $children[$u['parentId']][] = $u;
    $sum = function (array $list) {
        $o = [];
        foreach ($list as $c) foreach ($c as $k => $v) $o[$k] = ($o[$k] ?? 0) + $v;
        return $o;
    };
    foreach ($units as $u) {
        if ($u['parentId'] !== null && isset($visible[$u['parentId']])) continue; // 하위는 상위 밑에서
        $kids = $children[$u['id']] ?? [];
        $own = $cells[$u['id']] ?? [];
        $groupCells = $sum(array_merge([$own], array_map(fn($k) => $cells[$k['id']] ?? [], $kids)));
        $groupBudget = $unitBudget($u) + array_sum(array_map($unitBudget, $kids));
        if (!$kids && !$groupCells && !$groupBudget) continue; // 예산도 지출도 없는 빈 단위는 감춤
        if ($kids) {
            $addRow($u, 0, $groupCells, $groupBudget);
            $rowsOut[count($rowsOut) - 1]['group'] = true;
            if ($own || $unitBudget($u)) { $addRow(['id' => $u['id'], 'name' => $u['name'] . ' 직할', 'kind' => $u['kind']], 1, $own, $unitBudget($u)); }
            foreach ($kids as $k) $addRow($k, 1, $cells[$k['id']] ?? [], $unitBudget($k));
        } else {
            $addRow($u, 0, $own, $unitBudget($u));
        }
    }
    $total = ['budget' => 0, 'paid' => 0, 'cells' => []];
    // 총계: 맨 윗단계 행만 더한다(위원회 행은 소속 부서를 이미 포함)
    foreach ($rowsOut as $r) {
        if ($r['depth'] !== 0) continue;
        $total['budget'] += $r['budget'];
        $total['paid'] += $r['paid'];
        foreach ((array)$r['cells'] as $k => $v) $total['cells'][$k] = ($total['cells'][$k] ?? 0) + $v;
    }
    $total['cells'] = (object)$total['cells'];
    $treeOut = [];
    foreach ($cats as $c) if (!empty($tree[$c['id']])) $treeOut[] = ['id' => $c['id'], 'parentId' => $c['parentId'], 'name' => $c['name'], 'level' => $c['level'], 'paid' => $tree[$c['id']]];
    if (!empty($colTotals[0])) $treeOut[] = ['id' => 0, 'parentId' => null, 'name' => '미분류', 'level' => 1, 'paid' => $colTotals[0]];
    return ['year' => $year, 'from' => $from, 'to' => $to, 'level' => $level, 'columns' => $columns, 'rows' => $rowsOut, 'total' => $total, 'tree' => $treeOut];
}

route('GET', '/reports/spending', function () {
    $a = require_account();
    $year = in_int($_GET, 'year', 2000, 2100, '연도');
    $level = max(1, min(3, (int)($_GET['level'] ?? 1)));
    $from = isset($_GET['from']) && $_GET['from'] !== '' ? (string)$_GET['from'] : null;
    $to = isset($_GET['to']) && $_GET['to'] !== '' ? (string)$_GET['to'] : null;
    foreach ([$from, $to] as $m) if ($m !== null && !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m)) json_fail('invalid', '기간은 YYYY-MM 으로 입력해 주세요.', 422);
    audit('부서별 지출 정리 조회', $year . '년' . ($from ? " $from~$to" : ''), $a);
    json_out(spending_data($a, $year, $from, $to, $level));
});

route('GET', '/export/spending', function () {
    $a = require_account();
    if (!can_see_totals($a)) json_fail('forbidden', '권한이 없습니다.', 403);
    $year = in_int($_GET, 'year', 2000, 2100, '연도');
    $level = max(1, min(3, (int)($_GET['level'] ?? 1)));
    $from = isset($_GET['from']) && $_GET['from'] !== '' ? (string)$_GET['from'] : null;
    $to = isset($_GET['to']) && $_GET['to'] !== '' ? (string)$_GET['to'] : null;
    $why = dl_take('spending');
    $d = spending_data($a, $year, $from, $to, $level);
    audit('부서별 지출 정리 내려받기', $year . $why, $a);
    $head = ['위원회·부서', '예산'];
    foreach ($d['columns'] as $c) $head[] = $c['name'];
    $head[] = '지출 합계';
    $out = [$head];
    foreach ($d['rows'] as $r) {
        $line = [str_repeat('  ', $r['depth']) . $r['name'], (int)$r['budget']];
        foreach ($d['columns'] as $c) $line[] = (int)(((array)$r['cells'])[$c['id']] ?? 0);
        $line[] = (int)$r['paid'];
        $out[] = $line;
    }
    $line = ['합계', (int)$d['total']['budget']];
    foreach ($d['columns'] as $c) $line[] = (int)(((array)$d['total']['cells'])[$c['id']] ?? 0);
    $line[] = (int)$d['total']['paid'];
    $out[] = $line;
    csv_out('spending-' . $year, $out);
});
