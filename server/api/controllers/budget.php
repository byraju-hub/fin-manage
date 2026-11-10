<?php
declare(strict_types=1);

// 예산(편성·확정·변경)과 집행 현황.
// 현재 예산 = 처음 예산 + 변경으로 늘어난 금액 − 변경으로 줄어든 금액. 집행 = 장부의 지출(취소 제외), 결재 중 = 아직 지급 전인 결의서 내역.

const FM_VIEW_ALL_ROLES = ['재정 담당', '재정위원장', '담임목사', '감사', '열람'];

/** 모든 재정 단위를 볼 수 있는가 (그 밖의 사람은 자기가 담당자인 단위만) */
function sees_all_units(array $a): bool
{
    return is_admin_account($a) || has_role($a, ...FM_VIEW_ALL_ROLES);
}

/** 내가 담당자(회계·부서장·위원장)인 단위 번호. 위원장이면 소속 부서도 포함한다. */
function my_unit_ids(array $a, int $year): array
{
    $pid = $a['cm']['person_id'] ?? null;
    if (!$pid) return [];
    $ids = array_map('intval', array_column(rows('SELECT u.id FROM ' . t('unit_roles') . ' r JOIN ' . t('units') . ' u ON u.id = r.unit_id WHERE r.person_id = ? AND u.fiscal_year = ?', [$pid, $year]), 'id'));
    if ($ids) {
        $kids = array_map('intval', array_column(rows('SELECT id FROM ' . t('units') . ' WHERE fiscal_year = ? AND parent_id IN (' . marks($ids) . ')', array_merge([$year], $ids)), 'id'));
        $ids = array_values(array_unique(array_merge($ids, $kids)));
    }
    return $ids;
}

function budget_confirmed(int $year): bool
{
    return val('SELECT budget_confirmed_on FROM ' . t('fiscal_years') . ' WHERE year = ?', [$year]) !== null;
}

/** 항목별 집계: 변경 합계, 지급(집행), 결재 중 */
function item_stats(int $year): array
{
    $ch = [];
    foreach (rows('SELECT to_item_id AS i, SUM(amount) AS s FROM ' . t('budget_changes') . ' WHERE fiscal_year = ? AND to_item_id IS NOT NULL GROUP BY to_item_id', [$year]) as $r) $ch[(int)$r['i']] = ($ch[(int)$r['i']] ?? 0) + (int)$r['s'];
    foreach (rows('SELECT from_item_id AS i, SUM(amount) AS s FROM ' . t('budget_changes') . ' WHERE fiscal_year = ? AND from_item_id IS NOT NULL GROUP BY from_item_id', [$year]) as $r) $ch[(int)$r['i']] = ($ch[(int)$r['i']] ?? 0) - (int)$r['s'];
    $paid = [];
    foreach (rows('SELECT budget_item_id AS i, SUM(amount) AS s FROM ' . t('transactions') . " WHERE fiscal_year = ? AND kind = '지출' AND void_at IS NULL AND budget_item_id IS NOT NULL GROUP BY budget_item_id", [$year]) as $r) $paid[(int)$r['i']] = (int)$r['s'];
    $pend = [];
    foreach (rows('SELECT vi.budget_item_id AS i, SUM(vi.amount) AS s FROM ' . t('voucher_items') . ' vi JOIN ' . t('vouchers') . " v ON v.id = vi.voucher_id WHERE v.fiscal_year = ? AND v.status IN ('결재 중','결재 완료') GROUP BY vi.budget_item_id", [$year]) as $r) $pend[(int)$r['i']] = (int)$r['s'];
    return ['changes' => $ch, 'paid' => $paid, 'pending' => $pend];
}

function budget_units(int $year, array $a): array
{
    $units = rows('SELECT id, parent_id, kind, name FROM ' . t('units') . ' WHERE fiscal_year = ? ORDER BY sort_order, id', [$year]);
    if (!sees_all_units($a)) {
        $mine = my_unit_ids($a, $year);
        $units = array_values(array_filter($units, fn($u) => in_array((int)$u['id'], $mine, true)));
    }
    $items = rows('SELECT * FROM ' . t('budget_items') . ' WHERE unit_id IN (SELECT id FROM ' . t('units') . ' WHERE fiscal_year = ?) AND is_active = 1 ORDER BY sort_order, id', [$year]);
    $st = item_stats($year);
    $by = [];
    foreach ($items as $i) {
        $id = (int)$i['id'];
        $cur = (int)$i['amount'] + ($st['changes'][$id] ?? 0);
        $paid = $st['paid'][$id] ?? 0;
        $pend = $st['pending'][$id] ?? 0;
        $by[(int)$i['unit_id']][] = [
            'id' => $id, 'name' => $i['name'], 'initial' => (int)$i['amount'], 'budget' => $cur, 'paid' => $paid, 'pending' => $pend, 'remain' => $cur - $paid - $pend,
            'rate' => $cur > 0 ? (int)round($paid * 100 / $cur) : 0, 'basis' => $i['basis'], 'nature' => $i['nature'], 'directOk' => (int)$i['direct_ok'] === 1, 'directLimit' => $i['direct_limit'] === null ? null : (int)$i['direct_limit'],
        ];
    }
    return array_map(fn($u) => ['id' => (int)$u['id'], 'parentId' => $u['parent_id'] === null ? null : (int)$u['parent_id'], 'kind' => $u['kind'], 'name' => $u['name'], 'items' => $by[(int)$u['id']] ?? []], $units);
}

route('GET', '/budget', function () {
    $a = require_account();
    $year = in_int($_GET, 'year', 2000, 2100, '연도');
    $fy = row('SELECT year, status, budget_confirmed_on FROM ' . t('fiscal_years') . ' WHERE year = ?', [$year]);
    if (!$fy) json_fail('not_found', '회계연도를 찾을 수 없습니다.', 404);
    $units = budget_units($year, $a);
    $income = [];
    if (sees_all_units($a)) {
        $act = [];
        foreach (rows('SELECT income_account_id AS i, SUM(amount) AS s FROM ' . t('transactions') . " WHERE fiscal_year = ? AND kind = '수입' AND void_at IS NULL GROUP BY income_account_id", [$year]) as $r) $act[(int)$r['i']] = (int)$r['s'];
        $bud = array_column(rows('SELECT income_account_id AS i, amount FROM ' . t('income_budgets') . ' WHERE fiscal_year = ?', [$year]), 'amount', 'i');
        foreach (rows('SELECT id, parent_id, name FROM ' . t('income_accounts') . ' WHERE is_active = 1 AND id NOT IN (SELECT DISTINCT parent_id FROM ' . t('income_accounts') . ' WHERE parent_id IS NOT NULL) ORDER BY sort_order, id') as $r) {
            $income[] = ['accountId' => (int)$r['id'], 'name' => $r['name'], 'budget' => (int)($bud[$r['id']] ?? 0), 'actual' => $act[(int)$r['id']] ?? 0];
        }
    }
    json_out(['year' => $year, 'status' => $fy['status'], 'confirmedOn' => $fy['budget_confirmed_on'], 'units' => $units, 'income' => $income, 'canEdit' => has_role($a, '재정 담당') || is_admin_account($a), 'canConfirm' => has_role($a, '재정위원장'), 'isAdmin' => is_admin_account($a)]);
});

function budget_edit_guard(int $year): void
{
    if (val('SELECT status FROM ' . t('fiscal_years') . ' WHERE year = ?', [$year]) === '마감') json_fail('closed', $year . '년은 마감되었습니다.', 409);
    if (budget_confirmed($year)) json_fail('confirmed', '이미 확정된 예산이에요. 고치려면 예산 변경(추경·전용)을 쓰세요.', 409);
}

function unit_year(int $unitId): int
{
    $y = val('SELECT fiscal_year FROM ' . t('units') . ' WHERE id = ?', [$unitId]);
    if ($y === false || $y === null) json_fail('invalid', '재정 단위를 찾을 수 없습니다.', 422);
    return (int)$y;
}

route('POST', '/budget-items', function () {
    require_staff();
    $b = body();
    $unit = in_int($b, 'unitId', 1, PHP_INT_MAX, '재정 단위');
    budget_edit_guard(unit_year($unit));
    $name = in_str($b, 'name', 60, true, '항목 이름');
    if (val('SELECT COUNT(*) FROM ' . t('budget_items') . ' WHERE unit_id = ? AND name = ? AND is_active = 1', [$unit, $name])) json_fail('duplicate', '같은 이름의 예산 항목이 있습니다.', 409);
    $id = insert_id(
        'INSERT INTO ' . t('budget_items') . ' (unit_id, name, amount, basis, nature, sort_order) VALUES (?,?,?,?,?,?)',
        [$unit, $name, in_int($b, 'amount', 0, 9_000_000_000_000, '예산 금액'), in_str($b, 'basis', 255) ?: null, in_str($b, 'nature', 20) ?: null, (int)val('SELECT COALESCE(MAX(sort_order),0)+1 FROM ' . t('budget_items') . ' WHERE unit_id = ?', [$unit])]
    );
    change_log('budget_item', $id, 'create', null, $name . ' ' . ($b['amount'] ?? 0));
    json_out(['id' => $id]);
});

route('POST', '/budget-items/{id}', function (int $id) {
    require_staff();
    $cur = row('SELECT * FROM ' . t('budget_items') . ' WHERE id = ?', [$id]);
    if (!$cur) json_fail('not_found', '예산 항목을 찾을 수 없습니다.', 404);
    budget_edit_guard(unit_year((int)$cur['unit_id']));
    $b = body();
    $name = array_key_exists('name', $b) ? in_str($b, 'name', 60, true, '항목 이름') : $cur['name'];
    $amount = array_key_exists('amount', $b) ? in_int($b, 'amount', 0, 9_000_000_000_000, '예산 금액') : (int)$cur['amount'];
    $basis = array_key_exists('basis', $b) ? in_str($b, 'basis', 255) : (string)$cur['basis'];
    $nature = array_key_exists('nature', $b) ? in_str($b, 'nature', 20) : (string)$cur['nature'];
    q('UPDATE ' . t('budget_items') . ' SET name=?, amount=?, basis=?, nature=? WHERE id=?', [$name, $amount, $basis ?: null, $nature ?: null, $id]);
    if ($amount !== (int)$cur['amount']) change_log('budget_item', $id, 'amount', $cur['amount'], $amount);
    json_out(null);
});

route('POST', '/budget-items/{id}/delete', function (int $id) {
    require_staff();
    $cur = row('SELECT * FROM ' . t('budget_items') . ' WHERE id = ?', [$id]);
    if (!$cur) json_fail('not_found', '예산 항목을 찾을 수 없습니다.', 404);
    budget_edit_guard(unit_year((int)$cur['unit_id']));
    $used = (int)val('SELECT (SELECT COUNT(*) FROM ' . t('transactions') . ' WHERE budget_item_id = ?) + (SELECT COUNT(*) FROM ' . t('voucher_items') . ' WHERE budget_item_id = ?)', [$id, $id]);
    if ($used) json_fail('in_use', '지출이나 결의서가 있는 항목은 지울 수 없습니다.', 409);
    q('DELETE FROM ' . t('budget_items') . ' WHERE id = ?', [$id]);
    change_log('budget_item', $id, 'delete', $cur['name'], null);
    json_out(null);
});

/** 직접 지출 허용과 건당 한도: 재정 관리자가 항목마다 지정한다. 이미 등록된 건에는 영향이 없고, 바꾼 기록이 남는다. */
route('POST', '/budget-items/{id}/direct', function (int $id) {
    require_admin();
    $cur = row('SELECT * FROM ' . t('budget_items') . ' WHERE id = ?', [$id]);
    if (!$cur) json_fail('not_found', '예산 항목을 찾을 수 없습니다.', 404);
    $b = body();
    $ok = (int)(bool)($b['directOk'] ?? false);
    $limit = isset($b['directLimit']) && $b['directLimit'] !== null && $b['directLimit'] !== '' ? in_int($b, 'directLimit', 1, 9_000_000_000_000, '건당 한도') : null;
    q('UPDATE ' . t('budget_items') . ' SET direct_ok = ?, direct_limit = ? WHERE id = ?', [$ok, $limit, $id]);
    change_log('budget_item', $id, 'direct', $cur['direct_ok'] . '/' . ($cur['direct_limit'] ?? '-'), $ok . '/' . ($limit ?? '-'), $cur['name']);
    audit('직접 지출 설정 변경', $cur['name']);
    json_out(null);
});

route('POST', '/income-budgets', function () {
    require_staff();
    $b = body();
    $year = in_int($b, 'year', 2000, 2100, '연도');
    budget_edit_guard($year);
    tx(function () use ($b, $year) {
        foreach (is_array($b['items'] ?? null) ? $b['items'] : [] as $it) {
            $acct = in_int($it, 'accountId', 1, PHP_INT_MAX, '수입 항목');
            if (!val('SELECT COUNT(*) FROM ' . t('income_accounts') . ' WHERE id = ?', [$acct])) json_fail('invalid', '수입 항목이 올바르지 않습니다.', 422);
            $amt = in_int($it, 'amount', 0, 9_000_000_000_000, '수입 예산');
            q('INSERT INTO ' . t('income_budgets') . ' (fiscal_year, income_account_id, amount) VALUES (?,?,?) ON DUPLICATE KEY UPDATE amount = VALUES(amount)', [$year, $acct, $amt]);
        }
    });
    json_out(null);
});

/** 공동의회에서 확정한 날을 적고 예산을 확정한다. 재정위원장만. 확정 뒤에는 예산 변경으로만 바뀐다. */
route('POST', '/fiscal-years/{id}/budget-confirm', function (int $year) {
    require_role('재정위원장');
    require_reconfirm();
    $date = in_date(body(), 'date', '확정한 날');
    if (!val('SELECT COUNT(*) FROM ' . t('fiscal_years') . ' WHERE year = ?', [$year])) json_fail('not_found', '회계연도를 찾을 수 없습니다.', 404);
    if (budget_confirmed($year)) json_fail('confirmed', '이미 확정되었습니다.', 409);
    if (!val('SELECT COUNT(*) FROM ' . t('budget_items') . ' WHERE unit_id IN (SELECT id FROM ' . t('units') . ' WHERE fiscal_year = ?)', [$year])) json_fail('empty', '예산 항목이 하나도 없어 확정할 수 없습니다.', 409);
    q('UPDATE ' . t('fiscal_years') . ' SET budget_confirmed_on = ? WHERE year = ?', [$date, $year]);
    change_log('fiscal_year', $year, 'budget_confirm', null, $date);
    audit('예산 확정', "$year / $date");
    json_out(null);
});

route('POST', '/budget-changes', function () {
    $a = require_staff();
    $b = body();
    $year = in_int($b, 'year', 2000, 2100, '연도');
    if (!budget_confirmed($year)) json_fail('not_confirmed', '예산이 확정된 뒤에 추경·전용을 할 수 있어요. 확정 전에는 항목을 직접 고치세요.', 409);
    $amount = in_int($b, 'amount', 1, 9_000_000_000_000, '금액');
    $from = isset($b['fromItemId']) && $b['fromItemId'] ? in_int($b, 'fromItemId', 1, PHP_INT_MAX, '줄이는 항목') : null;
    $to = isset($b['toItemId']) && $b['toItemId'] ? in_int($b, 'toItemId', 1, PHP_INT_MAX, '늘리는 항목') : null;
    if ($from === null && $to === null) json_fail('invalid', '줄이는 항목이나 늘리는 항목 중 하나는 있어야 합니다.', 422);
    if ($from === $to) json_fail('invalid', '같은 항목끼리는 옮길 수 없습니다.', 422);
    $reason = in_str($b, 'reason', 255, true, '사유');
    $decided = isset($b['decidedOn']) && $b['decidedOn'] !== '' ? in_date($b, 'decidedOn', '의결한 날') : null;
    foreach (array_filter([$from, $to]) as $i) {
        $y = val('SELECT u.fiscal_year FROM ' . t('budget_items') . ' i JOIN ' . t('units') . ' u ON u.id = i.unit_id WHERE i.id = ?', [$i]);
        if ((int)$y !== $year) json_fail('invalid', '다른 회계연도의 항목이 섞여 있습니다.', 422);
    }
    tx(function () use ($year, $from, $to, $amount, $reason, $decided, $a) {
        if ($from !== null) {
            $st = item_stats($year);
            $init = (int)val('SELECT amount FROM ' . t('budget_items') . ' WHERE id = ? FOR UPDATE', [$from]);
            $remain = $init + ($st['changes'][$from] ?? 0) - ($st['paid'][$from] ?? 0) - ($st['pending'][$from] ?? 0);
            if ($remain < $amount) json_fail('insufficient', '줄이려는 항목의 남은 금액(' . number_format($remain) . '원)이 옮길 금액보다 적습니다.', 409);
        }
        $id = insert_id('INSERT INTO ' . t('budget_changes') . ' (fiscal_year, from_item_id, to_item_id, amount, reason, decided_on, by_user_id) VALUES (?,?,?,?,?,?,?)', [$year, $from, $to, $amount, $reason, $decided, $a['access']['id']]);
        change_log('budget_change', $id, $from ? '전용' : '추경', null, "$from -> $to : $amount", $reason);
    });
    json_out(null);
});

route('GET', '/budget-changes', function () {
    $a = require_account();
    if (!sees_all_units($a)) json_fail('forbidden', '권한이 없습니다.', 403);
    $year = in_int($_GET, 'year', 2000, 2100, '연도');
    $r = rows('SELECT c.id, c.amount, c.reason, c.decided_on, c.created_at, f.name AS fname, t.name AS tname FROM ' . t('budget_changes') . ' c LEFT JOIN ' . t('budget_items') . ' f ON f.id = c.from_item_id LEFT JOIN ' . t('budget_items') . ' t ON t.id = c.to_item_id WHERE c.fiscal_year = ? ORDER BY c.id DESC', [$year]);
    json_out(['items' => array_map(fn($x) => ['id' => (int)$x['id'], 'amount' => (int)$x['amount'], 'reason' => $x['reason'], 'decidedOn' => $x['decided_on'], 'from' => $x['fname'], 'to' => $x['tname'], 'at' => substr((string)$x['created_at'], 0, 10)], $r)]);
});

/** 예산 대비 집행 현황: 단위 › 항목 (열람 범위 밖의 단위는 나오지 않는다) */
route('GET', '/reports/execution', function () {
    $a = require_account();
    $year = in_int($_GET, 'year', 2000, 2100, '연도');
    $units = budget_units($year, $a);
    $out = [];
    foreach ($units as $u) if ($u['items']) $out[] = ['unit' => $u['name'], 'items' => array_map(fn($i) => ['name' => $i['name'], 'budget' => $i['budget'], 'paid' => $i['paid'], 'pending' => $i['pending'], 'remain' => $i['remain'], 'rate' => $i['rate']], $u['items'])];
    $income = [];
    if (sees_all_units($a)) {
        $act = [];
        foreach (rows('SELECT income_account_id AS i, SUM(amount) AS s FROM ' . t('transactions') . " WHERE fiscal_year = ? AND kind = '수입' AND void_at IS NULL GROUP BY income_account_id", [$year]) as $r) $act[(int)$r['i']] = (int)$r['s'];
        foreach (rows('SELECT i.id, i.name, COALESCE(b.amount,0) AS bud FROM ' . t('income_accounts') . ' i LEFT JOIN ' . t('income_budgets') . ' b ON b.income_account_id = i.id AND b.fiscal_year = ? WHERE i.id NOT IN (SELECT DISTINCT parent_id FROM ' . t('income_accounts') . ' WHERE parent_id IS NOT NULL) ORDER BY i.sort_order, i.id', [$year]) as $r) {
            if ((int)$r['bud'] || ($act[(int)$r['id']] ?? 0)) $income[] = ['name' => $r['name'], 'budget' => (int)$r['bud'], 'actual' => $act[(int)$r['id']] ?? 0];
        }
    }
    json_out(['units' => $out, 'income' => $income]);
});
