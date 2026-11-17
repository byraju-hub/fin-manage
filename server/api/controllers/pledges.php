<?php
declare(strict_types=1);

// 작정헌금: 헌금자별·헌금 종류별 한 해 작정 금액과 이행(낸 금액·잔액·%). 개인별 헌금이므로 열람 권한자만, 열 때마다 기록한다.

function pledge_donor(string $key): int
{
    if (str_starts_with($key, 'p:')) return ensure_donor_for_person(substr($key, 2));
    if (str_starts_with($key, 'd:') && ctype_digit(substr($key, 2)) && val('SELECT COUNT(*) FROM ' . t('donors') . ' WHERE id = ?', [(int)substr($key, 2)])) return (int)substr($key, 2);
    json_fail('invalid', '헌금자가 올바르지 않습니다.', 422);
}

/** 작정 목록. 낸 금액은 그 해 마감된 헌금 중 같은 헌금자·같은 종류(하위 종류 포함)의 합계 */
route('GET', '/pledges', function () {
    $a = require_account();
    if (!can_view_donors($a) && !has_role($a, '재정 담당')) json_fail('forbidden', '개인별 헌금을 볼 수 있는 권한이 없습니다.', 403);
    $year = in_int($_GET, 'year', 2000, 2100, '연도');
    $sql = 'SELECT p.id, p.donor_id, d.name AS donor, p.income_account_id, ia.name AS acct, p.amount, p.memo,
              COALESCE((SELECT SUM(o.amount) FROM ' . t('offerings') . ' o JOIN ' . t('offering_batches') . ' b ON b.id = o.batch_id
                        WHERE b.status = \'마감\' AND b.fiscal_year = p.fiscal_year AND o.donor_id = p.donor_id
                          AND (o.income_account_id = p.income_account_id OR o.income_account_id IN (SELECT c.id FROM ' . t('income_accounts') . ' c WHERE c.parent_id = p.income_account_id))), 0) AS paid
            FROM ' . t('pledges') . ' p JOIN ' . t('donors') . ' d ON d.id = p.donor_id JOIN ' . t('income_accounts') . ' ia ON ia.id = p.income_account_id
            WHERE p.fiscal_year = ?';
    $params = [$year];
    if (!empty($_GET['accountId'])) { $sql .= ' AND p.income_account_id = ?'; $params[] = (int)$_GET['accountId']; }
    $q = trim((string)($_GET['q'] ?? ''));
    if ($q !== '') { $sql .= ' AND d.name LIKE ?'; $params[] = '%' . $q . '%'; }
    $rows = rows($sql . ' ORDER BY ia.name, d.name LIMIT 1000', $params);
    $items = array_map(function ($r) {
        $amt = (int)$r['amount']; $paid = (int)$r['paid'];
        return ['id' => (int)$r['id'], 'donorId' => (int)$r['donor_id'], 'donor' => $r['donor'], 'accountId' => (int)$r['income_account_id'], 'acct' => $r['acct'], 'amount' => $amt, 'paid' => $paid, 'remain' => $amt - $paid, 'rate' => $amt > 0 ? (int)floor($paid * 100 / $amt) : 0, 'memo' => $r['memo']];
    }, $rows);
    $sum = ['count' => count($items), 'amount' => array_sum(array_column($items, 'amount')), 'paid' => array_sum(array_column($items, 'paid'))];
    $sum['rate'] = $sum['amount'] > 0 ? (int)floor($sum['paid'] * 100 / $sum['amount']) : 0;
    // 작정할 수 있는 헌금 종류(헌금 입력에 나오는 종류)
    $accts = array_map(fn($x) => ['id' => (int)$x['id'], 'name' => $x['name']], rows('SELECT id, name FROM ' . t('income_accounts') . ' WHERE is_offering = 1 AND is_active = 1 AND named = 1 ORDER BY sort_order, id'));
    audit('작정헌금 열람', $year . '년' . ($q !== '' ? ' ' . $q : ''), $a);
    json_out(['year' => $year, 'items' => $items, 'summary' => $sum, 'accounts' => $accts]);
});

/** 작정 저장(같은 사람·같은 종류가 있으면 금액을 고침) */
route('POST', '/pledges', function () {
    $a = require_staff();
    $b = body();
    $year = in_int($b, 'year', 2000, 2100, '연도');
    if (!val('SELECT COUNT(*) FROM ' . t('fiscal_years') . ' WHERE year = ?', [$year])) json_fail('invalid', '그 회계연도가 없습니다.', 422);
    $acct = in_int($b, 'accountId', 1, PHP_INT_MAX, '헌금 종류');
    if (!val('SELECT COUNT(*) FROM ' . t('income_accounts') . ' WHERE id = ? AND is_offering = 1 AND is_active = 1 AND named = 1', [$acct])) json_fail('invalid', '작정할 수 없는 헌금 종류입니다.', 422);
    $amount = in_int($b, 'amount', 1, 9_000_000_000_000, '작정 금액');
    $memo = in_str($b, 'memo', 255) ?: null;
    $donor = pledge_donor((string)($b['donor'] ?? ''));
    $res = tx(function () use ($year, $donor, $acct, $amount, $memo, $a) {
        $old = row('SELECT * FROM ' . t('pledges') . ' WHERE fiscal_year = ? AND donor_id = ? AND income_account_id = ? FOR UPDATE', [$year, $donor, $acct]);
        if ($old) {
            q('UPDATE ' . t('pledges') . ' SET amount = ?, memo = ? WHERE id = ?', [$amount, $memo, $old['id']]);
            change_log('pledge', (string)$old['id'], 'amount', ['amount' => (int)$old['amount']], ['amount' => $amount]);
            return (int)$old['id'];
        }
        $id = insert_id('INSERT INTO ' . t('pledges') . ' (fiscal_year, donor_id, income_account_id, amount, memo, created_by) VALUES (?,?,?,?,?,?)', [$year, $donor, $acct, $amount, $memo, $a['access']['id']]);
        change_log('pledge', (string)$id, 'create', null, ['amount' => $amount]);
        return $id;
    });
    json_out(['id' => $res]);
});

route('POST', '/pledges/{id}/delete', function (int $id) {
    require_staff();
    $p = row('SELECT * FROM ' . t('pledges') . ' WHERE id = ?', [$id]);
    if (!$p) json_fail('not_found', '작정을 찾을 수 없습니다.', 404);
    q('DELETE FROM ' . t('pledges') . ' WHERE id = ?', [$id]);
    change_log('pledge', (string)$id, 'delete', ['amount' => (int)$p['amount']], null);
    json_out(null);
});
