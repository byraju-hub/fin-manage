<?php
declare(strict_types=1);

// 행사(프로젝트) 정산: 수련회·기도회처럼 수입(회비)과 지출을 한 행사로 묶어 따로 정산한다.
// 지출결의서 내역과 장부 거래(수입·지출)에 행사를 연결하면 행사별 수입·지출·남은 금액이 자동으로 모인다.

/** 행사를 연결할 수 있는지(같은 회계연도, 종료되지 않음) */
function event_check(?int $eventId, int $year): ?int
{
    if ($eventId === null) return null;
    $e = row('SELECT id, fiscal_year, status FROM ' . t('events') . ' WHERE id = ?', [$eventId]);
    if (!$e || (int)$e['fiscal_year'] !== $year) json_fail('invalid', '행사를 찾을 수 없거나 회계연도가 달라요.', 422);
    if ($e['status'] !== '진행') json_fail('event_closed', '종료된 행사에는 새로 연결할 수 없어요.', 409);
    return $eventId;
}

function event_totals(array $ids): array
{
    if (!$ids) return [];
    $ph = marks($ids);
    $out = [];
    foreach (rows('SELECT event_id, kind, SUM(amount) AS s FROM ' . t('transactions') . " WHERE void_at IS NULL AND kind IN ('수입','지출') AND event_id IN ($ph) GROUP BY event_id, kind", $ids) as $r) $out[(int)$r['event_id']][$r['kind']] = (int)$r['s'];
    $pend = [];
    foreach (rows('SELECT vi.event_id, SUM(vi.amount) AS s FROM ' . t('voucher_items') . ' vi JOIN ' . t('vouchers') . " v ON v.id = vi.voucher_id WHERE v.status IN ('결재 중','결재 완료') AND vi.event_id IN ($ph) GROUP BY vi.event_id", $ids) as $r) $pend[(int)$r['event_id']] = (int)$r['s'];
    foreach ($ids as $i) $out[$i] = ['income' => $out[$i]['수입'] ?? 0, 'expense' => $out[$i]['지출'] ?? 0, 'pending' => $pend[$i] ?? 0];
    return $out;
}

route('GET', '/events', function () {
    require_account();
    $year = in_int($_GET, 'year', 2000, 2100, '연도');
    $r = rows('SELECT e.*, u.name AS unit FROM ' . t('events') . ' e LEFT JOIN ' . t('units') . ' u ON u.id = e.unit_id WHERE e.fiscal_year = ? ORDER BY e.status, e.starts_on DESC, e.id DESC', [$year]);
    $tot = event_totals(array_map(fn($x) => (int)$x['id'], $r));
    json_out(['items' => array_map(fn($e) => [
        'id' => (int)$e['id'], 'name' => $e['name'], 'unitId' => $e['unit_id'] === null ? null : (int)$e['unit_id'], 'unit' => $e['unit'], 'startsOn' => $e['starts_on'], 'endsOn' => $e['ends_on'], 'budget' => (int)$e['budget'], 'status' => $e['status'], 'note' => $e['note'],
        'income' => $tot[(int)$e['id']]['income'], 'expense' => $tot[(int)$e['id']]['expense'], 'pending' => $tot[(int)$e['id']]['pending'], 'net' => $tot[(int)$e['id']]['income'] - $tot[(int)$e['id']]['expense'],
    ], $r)]);
});

function event_fields(array $b, ?array $cur): array
{
    $name = array_key_exists('name', $b) ? in_str($b, 'name', 60, true, '행사 이름') : (string)$cur['name'];
    $unit = array_key_exists('unitId', $b) ? (($b['unitId'] === null || $b['unitId'] === '' || (int)$b['unitId'] <= 0) ? null : in_int($b, 'unitId', 1, PHP_INT_MAX, '재정 단위')) : ($cur['unit_id'] ?? null);
    $starts = array_key_exists('startsOn', $b) ? (($b['startsOn'] ?? '') === '' ? null : in_date($b, 'startsOn', '시작일')) : ($cur['starts_on'] ?? null);
    $ends = array_key_exists('endsOn', $b) ? (($b['endsOn'] ?? '') === '' ? null : in_date($b, 'endsOn', '끝나는 날')) : ($cur['ends_on'] ?? null);
    if ($starts && $ends && $ends < $starts) json_fail('invalid', '끝나는 날이 시작일보다 앞일 수 없어요.', 422);
    $budget = array_key_exists('budget', $b) ? in_int($b, 'budget', 0, 9_000_000_000_000, '행사 예산') : (int)($cur['budget'] ?? 0);
    $note = array_key_exists('note', $b) ? (in_str($b, 'note', 255) ?: null) : ($cur['note'] ?? null);
    return [$name, $unit === null ? null : (int)$unit, $starts, $ends, $budget, $note];
}

route('POST', '/events', function () {
    $a = require_staff();
    $b = body();
    $year = in_int($b, 'year', 2000, 2100, '연도');
    if (val('SELECT status FROM ' . t('fiscal_years') . ' WHERE year = ?', [$year]) !== '진행') json_fail('closed', $year . '년에는 행사를 만들 수 없어요.', 409);
    [$name, $unit, $s, $e, $budget, $note] = event_fields($b, null);
    if ($unit !== null && !val('SELECT COUNT(*) FROM ' . t('units') . ' WHERE id = ? AND fiscal_year = ?', [$unit, $year])) json_fail('invalid', '재정 단위가 올바르지 않습니다.', 422);
    if (val('SELECT COUNT(*) FROM ' . t('events') . ' WHERE fiscal_year = ? AND name = ?', [$year, $name])) json_fail('duplicate', '같은 이름의 행사가 있어요.', 409);
    $id = insert_id('INSERT INTO ' . t('events') . ' (fiscal_year, unit_id, name, starts_on, ends_on, budget, note, created_by) VALUES (?,?,?,?,?,?,?,?)', [$year, $unit, $name, $s, $e, $budget, $note, $a['access']['id']]);
    change_log('event', $id, 'create', null, $name);
    json_out(['id' => $id]);
});

route('POST', '/events/{id}', function (int $id) {
    require_staff();
    $cur = row('SELECT * FROM ' . t('events') . ' WHERE id = ?', [$id]);
    if (!$cur) json_fail('not_found', '행사를 찾을 수 없습니다.', 404);
    $b = body();
    [$name, $unit, $s, $e, $budget, $note] = event_fields($b, $cur);
    $status = array_key_exists('status', $b) ? in_enum($b, 'status', ['진행', '종료'], '상태') : $cur['status'];
    if ($name !== $cur['name'] && val('SELECT COUNT(*) FROM ' . t('events') . ' WHERE fiscal_year = ? AND name = ? AND id <> ?', [$cur['fiscal_year'], $name, $id])) json_fail('duplicate', '같은 이름의 행사가 있어요.', 409);
    q('UPDATE ' . t('events') . ' SET name=?, unit_id=?, starts_on=?, ends_on=?, budget=?, note=?, status=? WHERE id=?', [$name, $unit, $s, $e, $budget, $note, $status, $id]);
    if ($status !== $cur['status']) change_log('event', $id, 'status', $cur['status'], $status);
    if ($budget !== (int)$cur['budget']) change_log('event', $id, 'budget', $cur['budget'], $budget);
    json_out(null);
});

route('POST', '/events/{id}/delete', function (int $id) {
    require_staff();
    $cur = row('SELECT * FROM ' . t('events') . ' WHERE id = ?', [$id]);
    if (!$cur) json_fail('not_found', '행사를 찾을 수 없습니다.', 404);
    if (val('SELECT (SELECT COUNT(*) FROM ' . t('transactions') . ' WHERE event_id = ?) + (SELECT COUNT(*) FROM ' . t('voucher_items') . ' WHERE event_id = ?)', [$id, $id])) json_fail('in_use', '연결된 수입·지출이 있어 지울 수 없어요. 대신 "종료"로 바꿔 주세요.', 409);
    q('DELETE FROM ' . t('events') . ' WHERE id = ?', [$id]);
    change_log('event', $id, 'delete', $cur['name'], null);
    json_out(null);
});

/** 행사 정산 상세: 수입·지출 내역과 결재 중인 결의서 내역 */
route('GET', '/events/{id}', function (int $id) {
    require_account();
    $e = row('SELECT e.*, u.name AS unit FROM ' . t('events') . ' e LEFT JOIN ' . t('units') . ' u ON u.id = e.unit_id WHERE e.id = ?', [$id]);
    if (!$e) json_fail('not_found', '행사를 찾을 수 없습니다.', 404);
    $tx = rows('SELECT t.id, t.tx_date, t.kind, t.amount, t.summary, t.vendor, ia.name AS acct, bi.name AS item FROM ' . t('transactions') . ' t LEFT JOIN ' . t('income_accounts') . ' ia ON ia.id = t.income_account_id LEFT JOIN ' . t('budget_items') . " bi ON bi.id = t.budget_item_id WHERE t.event_id = ? AND t.void_at IS NULL AND t.kind IN ('수입','지출') ORDER BY t.tx_date, t.id", [$id]);
    $pend = rows('SELECT v.doc_no, v.title, v.status, vi.summary, vi.amount FROM ' . t('voucher_items') . ' vi JOIN ' . t('vouchers') . " v ON v.id = vi.voucher_id WHERE vi.event_id = ? AND v.status IN ('결재 중','결재 완료') ORDER BY v.id", [$id]);
    $tot = event_totals([$id])[$id];
    json_out([
        'id' => (int)$e['id'], 'name' => $e['name'], 'unit' => $e['unit'], 'startsOn' => $e['starts_on'], 'endsOn' => $e['ends_on'], 'budget' => (int)$e['budget'], 'status' => $e['status'], 'note' => $e['note'],
        'income' => $tot['income'], 'expense' => $tot['expense'], 'pending' => $tot['pending'], 'net' => $tot['income'] - $tot['expense'],
        'transactions' => array_map(fn($t) => ['id' => (int)$t['id'], 'date' => $t['tx_date'], 'kind' => $t['kind'], 'amount' => (int)$t['amount'], 'summary' => $t['summary'], 'vendor' => $t['vendor'], 'acct' => $t['acct'], 'item' => $t['item']], $tx),
        'pendingItems' => array_map(fn($p) => ['docNo' => $p['doc_no'], 'title' => $p['title'], 'status' => $p['status'], 'summary' => $p['summary'], 'amount' => (int)$p['amount']], $pend),
    ]);
});
