<?php
declare(strict_types=1);

// 헌금: 헌금자, 헌금 묶음(날짜+구분), 빠른 입력 줄, 대조, 마감(장부에 수입으로), 다시 열기, 보고서.
// 입력은 재정 담당과 계수 위원(자기가 만든 묶음만). 마감·다시 열기는 재정 담당만.

const FM_BATCH_KINDS = ['주일예배', '수요예배', '새벽기도', '절기', '계좌이체분', '기타'];
const FM_METHODS = ['현금', '계좌이체', '온라인'];

function require_entry(): array
{
    $a = require_account();
    if (!has_role($a, '재정 담당', '계수 위원')) json_fail('forbidden', '헌금을 입력할 권한이 없습니다.', 403);
    return $a;
}

/** 계수 위원은 자기가 만든 묶음만 다룬다. */
function batch_for_entry(int $id, array $a, bool $forUpdate = false): array
{
    $b = row('SELECT * FROM ' . t('offering_batches') . ' WHERE id = ?' . ($forUpdate ? ' FOR UPDATE' : ''), [$id]);
    if (!$b) json_fail('not_found', '헌금 묶음을 찾을 수 없습니다.', 404);
    if (!has_role($a, '재정 담당') && (int)$b['created_by'] !== (int)($a['access']['id'] ?? 0)) json_fail('forbidden', '내가 만든 헌금 묶음만 열 수 있습니다.', 403);
    return $b;
}

/** 교인에게서 헌금자 줄을 얻는다(없으면 만든다). 교적에서 사라진 교인이면 오류. */
function ensure_donor_for_person(string $personId): int
{
    $d = val('SELECT id FROM ' . t('donors') . ' WHERE person_id = ?', [$personId]);
    if ($d) return (int)$d;
    $p = row('SELECT name FROM ' . cmt('people') . ' WHERE id = ? AND purged_on IS NULL', [$personId]);
    if (!$p) json_fail('invalid', '교적에서 해당 교인을 찾을 수 없습니다.', 422);
    return insert_id('INSERT INTO ' . t('donors') . ' (person_id, name) VALUES (?, ?)', [$personId, $p['name']]);
}

/** 헌금자 찾기: 교적 교인(이름·초성·전화 끝자리) + 교인이 아닌 헌금자(이름) */
route('GET', '/donors/search', function () {
    require_entry();
    $qs = trim((string)($_GET['q'] ?? ''));
    if ($qs === '' || mb_strlen($qs) > 30) json_out(['items' => []]);
    $isCho = (bool)preg_match('/^[ㄱ-ㅎ]+$/u', $qs);
    $digits = preg_replace('/\D/', '', $qs);
    $isPhone = strlen($digits) >= 3 && strlen($digits) === strlen($qs);
    $sql = 'SELECT p.id, p.name, p.name_note, p.birth_date, p.gender, p.phone,
              (SELECT p2.name FROM ' . cmt('household_members') . ' m1 JOIN ' . cmt('household_members') . ' m2 ON m2.household_id = m1.household_id AND m2.relation = \'본인\'
                 JOIN ' . cmt('people') . ' p2 ON p2.id = m2.person_id WHERE m1.person_id = p.id LIMIT 1) AS head_name,
              (SELECT d.donor_no FROM ' . t('donors') . ' d WHERE d.person_id = p.id) AS donor_no
            FROM ' . cmt('people') . ' p WHERE p.purged_on IS NULL';
    $params = [];
    if ($isPhone) { $sql .= " AND REPLACE(p.phone, '-', '') LIKE ?"; $params[] = '%' . $digits; }
    elseif (!$isCho) { $sql .= ' AND p.name LIKE ?'; $params[] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $qs) . '%'; }
    // 봉투 번호로 찾기
    if (ctype_digit($qs) && strlen($qs) <= 6) {
        $sql = 'SELECT p.id, p.name, p.name_note, p.birth_date, p.gender, p.phone, NULL AS head_name, d.donor_no FROM ' . t('donors') . ' d JOIN ' . cmt('people') . ' p ON p.id = d.person_id WHERE d.donor_no = ?';
        $params = [$qs];
    }
    $sql .= ' ORDER BY p.name, p.seq LIMIT ' . ($isCho ? 3000 : 30);
    $out = [];
    foreach (rows($sql, $params) as $p) {
        if ($isCho && !str_starts_with(chosung($p['name']), $qs)) continue;
        $l = person_label($p);
        $out[] = ['key' => 'p:' . $p['id']] + $l + ['donorNo' => $p['donor_no'], 'nonMember' => false];
        if (count($out) >= 30) break;
    }
    // 교인이 아닌 헌금자(이름으로)
    if (!$isCho && !$isPhone && !ctype_digit($qs) && count($out) < 30) {
        foreach (rows('SELECT id, name, memo FROM ' . t('donors') . ' WHERE person_id IS NULL AND name LIKE ? LIMIT 10', ['%' . $qs . '%']) as $d) {
            $out[] = ['key' => 'd:' . $d['id'], 'id' => null, 'name' => $d['name'], 'birthYear' => null, 'gender' => null, 'phoneTail' => '', 'head' => $d['memo'], 'donorNo' => null, 'nonMember' => true];
        }
    }
    json_out(['items' => $out]);
});

/** 교인이 아닌 헌금자(외부인·단체)를 새로 만든다 */
route('POST', '/donors', function () {
    require_entry();
    $b = body();
    $name = in_str($b, 'name', 50, true, '이름');
    $memo = in_str($b, 'memo', 255);
    $id = insert_id('INSERT INTO ' . t('donors') . ' (name, memo) VALUES (?, ?)', [$name, $memo ?: null]);
    json_out(['id' => $id, 'key' => 'd:' . $id]);
});

function batch_totals(int $batchId): array
{
    $r = rows('SELECT income_account_id AS a, COUNT(*) AS n, SUM(amount) AS s FROM ' . t('offerings') . ' WHERE batch_id = ? GROUP BY income_account_id', [$batchId]);
    $out = [];
    foreach ($r as $x) $out[(int)$x['a']] = ['count' => (int)$x['n'], 'sum' => (int)$x['s']];
    return $out;
}

function batch_item(array $b, ?array $totals = null): array
{
    $tot = $totals ?? batch_totals((int)$b['id']);
    return [
        'id' => (int)$b['id'], 'year' => (int)$b['fiscal_year'], 'date' => $b['batch_date'], 'kind' => $b['kind'], 'status' => $b['status'],
        'counters' => (string)$b['counters'], 'counted' => $b['counted'] ? json_decode((string)$b['counted'], true) : new stdClass(),
        'diffReason' => (string)$b['diff_reason'], 'total' => array_sum(array_column($tot, 'sum')), 'count' => array_sum(array_column($tot, 'count')),
        'totals' => $tot ?: new stdClass(), 'closedAt' => $b['closed_at'], 'reopened' => (int)$b['reopened_count'],
    ];
}

route('GET', '/offering-batches', function () {
    $a = require_account();
    if (!has_role($a, '재정 담당', '계수 위원') && !can_see_totals($a)) json_fail('forbidden', '권한이 없습니다.', 403);
    $year = in_int($_GET, 'year', 2000, 2100, '연도');
    $own = !has_role($a, '재정 담당') && !can_see_totals($a); // 계수 위원만 있는 사람은 자기 묶음만
    $sql = 'SELECT * FROM ' . t('offering_batches') . ' WHERE fiscal_year = ?' . ($own ? ' AND created_by = ' . (int)$a['access']['id'] : '') . ' ORDER BY batch_date DESC, id DESC LIMIT 300';
    $items = array_map(fn($b) => batch_item($b), rows($sql, [$year]));
    json_out(['items' => $items]);
});

route('POST', '/offering-batches', function () {
    $a = require_entry();
    $b = body();
    $date = in_date($b, 'date', '날짜');
    $kind = in_enum($b, 'kind', FM_BATCH_KINDS, '구분');
    $year = fiscal_year_of($date);
    if ($year === null) json_fail('invalid', '이 날짜가 속한 회계연도가 없습니다. 기준 자료에서 회계연도를 먼저 만들어 주세요.', 422);
    if (val('SELECT status FROM ' . t('fiscal_years') . ' WHERE year = ?', [$year]) === '마감') json_fail('closed', $year . '년은 마감되었습니다.', 409);
    require_month_open($date);
    $counters = in_str($b, 'counters', 255);
    $id = insert_id('INSERT INTO ' . t('offering_batches') . ' (fiscal_year, batch_date, kind, counters, created_by) VALUES (?,?,?,?,?)', [$year, $date, $kind, $counters ?: null, $a['access']['id']]);
    json_out(['id' => $id]);
});

route('GET', '/offering-batches/{id}', function (int $id) {
    $a = require_account();
    $b = row('SELECT * FROM ' . t('offering_batches') . ' WHERE id = ?', [$id]);
    if (!$b) json_fail('not_found', '헌금 묶음을 찾을 수 없습니다.', 404);
    $entry = has_role($a, '재정 담당') || (has_role($a, '계수 위원') && (int)$b['created_by'] === (int)$a['access']['id']);
    $seeNames = $entry || can_view_donors($a);
    if (!$entry && !can_see_totals($a)) json_fail('forbidden', '권한이 없습니다.', 403);
    $lines = [];
    if ($seeNames) {
        $lines = rows('SELECT o.id, o.income_account_id AS a, o.amount, o.method, o.unit_id, o.purpose, o.memo, o.hide_in_bulletin AS hide, d.name AS donor, d.person_id AS pid, d.id AS did
                       FROM ' . t('offerings') . ' o LEFT JOIN ' . t('donors') . ' d ON d.id = o.donor_id WHERE o.batch_id = ? ORDER BY o.id', [$id]);
        if (!$entry) audit('개인별 헌금 열람', '헌금 묶음 ' . $b['batch_date'] . ' ' . $b['kind']);
    }
    json_out(['batch' => batch_item($b), 'entry' => $entry && $b['status'] === '입력 중', 'canClose' => has_role($a, '재정 담당'), 'seeNames' => $seeNames,
        'lines' => array_map(fn($l) => ['id' => (int)$l['id'], 'accountId' => (int)$l['a'], 'amount' => (int)$l['amount'], 'method' => $l['method'], 'unitId' => $l['unit_id'] ? (int)$l['unit_id'] : null,
            'purpose' => $l['purpose'], 'memo' => $l['memo'], 'hide' => (int)$l['hide'] === 1, 'donor' => $l['donor'], 'donorId' => $l['did'] ? (int)$l['did'] : null], $lines)]);
});

function line_fields(array $b, array $batch): array
{
    $acct = row('SELECT * FROM ' . t('income_accounts') . ' WHERE id = ? AND is_offering = 1 AND is_active = 1', [in_int($b, 'accountId', 1, PHP_INT_MAX, '헌금 종류')]);
    if (!$acct) json_fail('invalid', '헌금 종류가 올바르지 않습니다.', 422);
    $amount = in_int($b, 'amount', 1, 9_000_000_000_000, '금액');
    $method = in_enum($b, 'method', FM_METHODS, '받은 방법');
    $donor = null;
    $key = (string)($b['donor'] ?? '');
    if ($key !== '') {
        if ((int)$acct['named'] === 0) json_fail('invalid', $acct['name'] . '은(는) 이름 없이 합계로만 입력합니다.', 422);
        if (str_starts_with($key, 'p:')) $donor = ensure_donor_for_person(substr($key, 2));
        elseif (str_starts_with($key, 'd:') && ctype_digit(substr($key, 2)) && val('SELECT COUNT(*) FROM ' . t('donors') . ' WHERE id = ?', [(int)substr($key, 2)])) $donor = (int)substr($key, 2);
        else json_fail('invalid', '헌금자가 올바르지 않습니다.', 422);
    }
    $unit = null;
    if ((int)$acct['needs_unit'] === 1) {
        $unit = in_int($b, 'unitId', 1, PHP_INT_MAX, '부서');
        if (!val('SELECT COUNT(*) FROM ' . t('units') . ' WHERE id = ? AND fiscal_year = ?', [$unit, $batch['fiscal_year']])) json_fail('invalid', '부서가 올바르지 않습니다.', 422);
    }
    $purpose = null;
    if ((int)$acct['needs_purpose'] === 1) $purpose = in_str($b, 'purpose', 100, true, '목적');
    return [(int)$acct['id'], $donor, $amount, $method, $unit, $purpose, in_str($b, 'memo', 255) ?: null, (int)(bool)($b['hide'] ?? false)];
}

route('POST', '/offering-batches/{id}/lines', function (int $id) {
    $a = require_entry();
    $batch = batch_for_entry($id, $a);
    if ($batch['status'] !== '입력 중') json_fail('invalid_state', '마감된 묶음입니다. 재정 담당이 다시 열어야 고칠 수 있어요.', 409);
    require_month_open($batch['batch_date']);
    [$acct, $donor, $amount, $method, $unit, $purpose, $memo, $hide] = line_fields(body(), $batch);
    $lid = insert_id(
        'INSERT INTO ' . t('offerings') . ' (batch_id, offering_date, income_account_id, donor_id, amount, method, unit_id, purpose, memo, hide_in_bulletin, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
        [$id, $batch['batch_date'], $acct, $donor, $amount, $method, $unit, $purpose, $memo, $hide, $a['access']['id']]
    );
    json_out(['id' => $lid]);
});

function line_for_edit(int $lid, array $a): array
{
    $l = row('SELECT * FROM ' . t('offerings') . ' WHERE id = ?', [$lid]);
    if (!$l) json_fail('not_found', '헌금 줄을 찾을 수 없습니다.', 404);
    $batch = batch_for_entry((int)$l['batch_id'], $a);
    if ($batch['status'] !== '입력 중') json_fail('invalid_state', '마감된 묶음입니다.', 409);
    require_month_open($batch['batch_date']);
    return [$l, $batch];
}

route('POST', '/offering-lines/{id}', function (int $lid) {
    $a = require_entry();
    [$l, $batch] = line_for_edit($lid, $a);
    [$acct, $donor, $amount, $method, $unit, $purpose, $memo, $hide] = line_fields(body(), $batch);
    q('UPDATE ' . t('offerings') . ' SET income_account_id=?, donor_id=?, amount=?, method=?, unit_id=?, purpose=?, memo=?, hide_in_bulletin=? WHERE id=?', [$acct, $donor, $amount, $method, $unit, $purpose, $memo, $hide, $lid]);
    if ((int)$l['amount'] !== $amount) change_log('offering', $lid, 'amount', $l['amount'], $amount);
    json_out(null);
});

route('POST', '/offering-lines/{id}/delete', function (int $lid) {
    $a = require_entry();
    [$l] = line_for_edit($lid, $a);
    q('DELETE FROM ' . t('offerings') . ' WHERE id = ?', [$lid]);
    change_log('offering', $lid, 'delete', $l['amount'], null, '입력 중 삭제');
    json_out(null);
});

/** 계수 위원이 실제로 센 종류별 금액과 계수 위원 이름 */
route('POST', '/offering-batches/{id}/counted', function (int $id) {
    $a = require_entry();
    $batch = batch_for_entry($id, $a);
    if ($batch['status'] !== '입력 중') json_fail('invalid_state', '마감된 묶음입니다.', 409);
    $b = body();
    $counted = [];
    foreach (is_array($b['counted'] ?? null) ? $b['counted'] : [] as $k => $v) {
        if (!ctype_digit((string)$k) || $v === '' || $v === null) continue;
        $counted[(int)$k] = in_int(['v' => $v], 'v', 0, 9_000_000_000_000, '계수 금액');
    }
    q('UPDATE ' . t('offering_batches') . ' SET counted = ?, counters = ? WHERE id = ?', [json_encode($counted ?: new stdClass()), in_str($b, 'counters', 255) ?: null, $id]);
    json_out(null);
});

route('POST', '/offering-batches/{id}/close', function (int $id) {
    $a = require_role('재정 담당');
    $b = body();
    tx(function () use ($id, $a, $b) {
        $batch = row('SELECT * FROM ' . t('offering_batches') . ' WHERE id = ? FOR UPDATE', [$id]);
        if (!$batch) json_fail('not_found', '헌금 묶음을 찾을 수 없습니다.', 404);
        if ($batch['status'] !== '입력 중') json_fail('invalid_state', '이미 마감된 묶음입니다.', 409);
        require_month_open($batch['batch_date']);
        $lines = rows('SELECT income_account_id AS a, method, unit_id, SUM(amount) AS s FROM ' . t('offerings') . ' WHERE batch_id = ? GROUP BY income_account_id, method, unit_id', [$id]);
        if (!$lines) json_fail('empty', '입력된 헌금이 없어 마감할 수 없습니다.', 409);
        // 대조: 계수 금액을 적은 종류는 입력 합계와 같아야 하고, 다르면 사유가 필요하다
        $counted = $batch['counted'] ? json_decode((string)$batch['counted'], true) : [];
        $sums = [];
        foreach ($lines as $l) $sums[(int)$l['a']] = ($sums[(int)$l['a']] ?? 0) + (int)$l['s'];
        $diff = false;
        foreach ($counted as $acct => $c) if (($sums[(int)$acct] ?? 0) !== (int)$c) $diff = true;
        $reason = in_str($b, 'diffReason', 255);
        if ($diff && $reason === '') json_fail('diff_reason', '입력한 합계와 실제 센 금액이 달라요. 마감하려면 차이의 사유를 적어 주세요.', 422);
        $cash = isset($b['cashWalletId']) ? (int)$b['cashWalletId'] : 0;
        $bank = isset($b['bankWalletId']) ? (int)$b['bankWalletId'] : 0;
        foreach ($lines as $l) {
            $w = $l['method'] === '현금' ? $cash : $bank;
            if (!$w) json_fail('wallet', ($l['method'] === '현금' ? '현금' : '통장') . ' 입금 계좌를 골라 주세요.', 422);
        }
        foreach (array_unique([$cash, $bank]) as $w) {
            if ($w && !val('SELECT COUNT(*) FROM ' . t('wallets') . ' WHERE id = ? AND is_active = 1', [$w])) json_fail('wallet', '계좌가 올바르지 않습니다.', 422);
        }
        $names = array_column(rows('SELECT id, name FROM ' . t('income_accounts')), 'name', 'id');
        foreach ($lines as $l) {
            $wid = $l['method'] === '현금' ? $cash : $bank;
            $w = row('SELECT fund_id FROM ' . t('wallets') . ' WHERE id = ?', [$wid]);
            q('INSERT INTO ' . t('transactions') . ' (fiscal_year, tx_date, kind, fund_id, wallet_id, income_account_id, unit_id, amount, summary, source, source_id, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
                [$batch['fiscal_year'], $batch['batch_date'], '수입', $w['fund_id'], $wid, $l['a'], $l['unit_id'], $l['s'], ($names[$l['a']] ?? '') . ' (' . $batch['kind'] . ')', '헌금', $id, $a['access']['id']]);
        }
        q('UPDATE ' . t('offering_batches') . " SET status='마감', diff_reason=?, closed_at=NOW(), closed_by=? WHERE id=?", [$reason ?: null, $a['access']['id'], $id]);
        change_log('offering_batch', $id, 'close', '입력 중', '마감', $reason);
    });
    json_out(null);
});

route('POST', '/offering-batches/{id}/reopen', function (int $id) {
    $a = require_role('재정 담당');
    $reason = in_str(body(), 'reason', 255, true, '다시 여는 사유');
    tx(function () use ($id, $a, $reason) {
        $batch = row('SELECT * FROM ' . t('offering_batches') . ' WHERE id = ? FOR UPDATE', [$id]);
        if (!$batch) json_fail('not_found', '헌금 묶음을 찾을 수 없습니다.', 404);
        if ($batch['status'] !== '마감') json_fail('invalid_state', '마감된 묶음만 다시 열 수 있습니다.', 409);
        require_month_open($batch['batch_date']);
        q('UPDATE ' . t('transactions') . " SET void_at = NOW(), void_reason = ?, void_by = ? WHERE source = '헌금' AND source_id = ? AND void_at IS NULL", ['헌금 묶음 다시 열기: ' . $reason, $a['access']['id'], $id]);
        q('UPDATE ' . t('offering_batches') . " SET status='입력 중', reopened_count = reopened_count + 1, closed_at = NULL, closed_by = NULL WHERE id = ?", [$id]);
        change_log('offering_batch', $id, 'reopen', '마감', '입력 중', $reason);
    });
    audit('헌금 묶음 다시 열기', (string)$id);
    json_out(null);
});

// ----- 보고서 -----
function sums_between(string $from, string $to): array
{
    $r = rows('SELECT o.income_account_id AS a, COUNT(*) AS n, SUM(o.amount) AS s FROM ' . t('offerings') . ' o JOIN ' . t('offering_batches') . " b ON b.id = o.batch_id WHERE b.status = '마감' AND o.offering_date BETWEEN ? AND ? GROUP BY o.income_account_id", [$from, $to]);
    $out = [];
    foreach ($r as $x) $out[(int)$x['a']] = ['count' => (int)$x['n'], 'sum' => (int)$x['s']];
    return $out;
}

/** 주간 헌금 보고: 기간(기본 가장 가까운 지난 일요일의 한 주)의 종류별 합계와 지난주·작년 같은 주 비교 */
route('GET', '/reports/weekly', function () {
    $a = require_account();
    if (!can_see_totals($a)) json_fail('forbidden', '권한이 없습니다.', 403);
    $to = isset($_GET['to']) ? in_date($_GET, 'to', '끝 날짜') : date('Y-m-d');
    $from = isset($_GET['from']) ? in_date($_GET, 'from', '시작 날짜') : date('Y-m-d', strtotime($to . ' -6 days'));
    $span = (int)((strtotime($to) - strtotime($from)) / 86400);
    $prev = [date('Y-m-d', strtotime($from . ' -' . ($span + 1) . ' days')), date('Y-m-d', strtotime($from . ' -1 day'))];
    $last = [date('Y-m-d', strtotime($from . ' -364 days')), date('Y-m-d', strtotime($to . ' -364 days'))];
    $acc = rows('SELECT id, parent_id, name FROM ' . t('income_accounts') . ' WHERE is_offering = 1 ORDER BY sort_order, id');
    $cur = sums_between($from, $to); $p = sums_between($prev[0], $prev[1]); $ly = sums_between($last[0], $last[1]);
    $items = array_map(fn($x) => ['id' => (int)$x['id'], 'name' => $x['name'], 'count' => $cur[(int)$x['id']]['count'] ?? 0, 'sum' => $cur[(int)$x['id']]['sum'] ?? 0, 'prev' => $p[(int)$x['id']]['sum'] ?? 0, 'lastYear' => $ly[(int)$x['id']]['sum'] ?? 0], $acc);
    $open = (int)val('SELECT COUNT(*) FROM ' . t('offering_batches') . " WHERE status = '입력 중' AND batch_date BETWEEN ? AND ?", [$from, $to]);
    $batches = rows('SELECT batch_date, kind, counters, status FROM ' . t('offering_batches') . ' WHERE batch_date BETWEEN ? AND ? ORDER BY batch_date, id', [$from, $to]);
    json_out(['from' => $from, 'to' => $to, 'prevRange' => $prev, 'lastYearRange' => $last, 'items' => $items, 'openBatches' => $open, 'batches' => $batches]);
});

/** 주보용 헌금자 명단: 종류별 이름(금액 없음). 재정 담당만. 싣지 않기로 표시한 줄은 뺀다. */
route('GET', '/reports/bulletin', function () {
    $a = require_role('재정 담당');
    $to = isset($_GET['to']) ? in_date($_GET, 'to', '끝 날짜') : date('Y-m-d');
    $from = isset($_GET['from']) ? in_date($_GET, 'from', '시작 날짜') : date('Y-m-d', strtotime($to . ' -6 days'));
    $r = rows('SELECT ia.name AS acct, ia.id AS aid, d.name FROM ' . t('offerings') . ' o JOIN ' . t('offering_batches') . ' b ON b.id = o.batch_id JOIN ' . t('income_accounts') . ' ia ON ia.id = o.income_account_id
               JOIN ' . t('donors') . " d ON d.id = o.donor_id WHERE b.status = '마감' AND o.hide_in_bulletin = 0 AND o.offering_date BETWEEN ? AND ? ORDER BY ia.sort_order, ia.id, d.name", [$from, $to]);
    $out = [];
    foreach ($r as $x) $out[$x['acct']][$x['name']] = true;
    audit('주보용 헌금자 명단 열람', "$from~$to", $a);
    json_out(['from' => $from, 'to' => $to, 'groups' => array_map(fn($k, $v) => ['account' => $k, 'names' => array_keys($v)], array_keys($out), $out)]);
});

/** 개인별 헌금 내역: 개인별 헌금 열람 권한자만, 열 때마다 기록 */
route('GET', '/donors/history', function () {
    $a = require_account();
    if (!can_view_donors($a)) json_fail('forbidden', '개인별 헌금을 볼 수 있는 권한이 없습니다.', 403);
    $key = (string)($_GET['donor'] ?? '');
    $did = null;
    if (str_starts_with($key, 'p:')) $did = val('SELECT id FROM ' . t('donors') . ' WHERE person_id = ?', [substr($key, 2)]);
    elseif (str_starts_with($key, 'd:') && ctype_digit(substr($key, 2))) $did = (int)substr($key, 2);
    if (!$did) json_out(['donor' => null, 'items' => [], 'total' => 0]);
    $d = row('SELECT id, name FROM ' . t('donors') . ' WHERE id = ?', [$did]);
    $from = isset($_GET['from']) ? in_date($_GET, 'from', '시작') : date('Y') . '-01-01';
    $to = isset($_GET['to']) ? in_date($_GET, 'to', '끝') : date('Y') . '-12-31';
    $items = rows('SELECT o.offering_date AS d, ia.name AS acct, o.amount, o.method, o.memo, b.status FROM ' . t('offerings') . ' o JOIN ' . t('offering_batches') . ' b ON b.id = o.batch_id JOIN ' . t('income_accounts') . ' ia ON ia.id = o.income_account_id WHERE o.donor_id = ? AND o.offering_date BETWEEN ? AND ? ORDER BY o.offering_date, o.id', [$did, $from, $to]);
    audit('개인별 헌금 열람', $d['name'] . " $from~$to", $a);
    json_out(['donor' => $d['name'], 'from' => $from, 'to' => $to, 'items' => $items, 'total' => array_sum(array_map(fn($x) => $x['status'] === '마감' ? (int)$x['amount'] : 0, $items))]);
});

/** 헌금 통계: 월별·종류별 합계(마감분) */
route('GET', '/reports/offering-stats', function () {
    $a = require_account();
    if (!can_see_totals($a)) json_fail('forbidden', '권한이 없습니다.', 403);
    $year = in_int($_GET, 'year', 2000, 2100, '연도');
    $r = rows('SELECT DATE_FORMAT(o.offering_date, \'%m\') AS m, o.income_account_id AS a, SUM(o.amount) AS s, COUNT(DISTINCT o.donor_id) AS donors FROM ' . t('offerings') . ' o JOIN ' . t('offering_batches') . " b ON b.id = o.batch_id WHERE b.status = '마감' AND b.fiscal_year = ? GROUP BY m, o.income_account_id", [$year]);
    json_out(['items' => array_map(fn($x) => ['month' => (int)$x['m'], 'accountId' => (int)$x['a'], 'sum' => (int)$x['s']], $r)]);
});

/**
 * 통장 내역으로 헌금 맞추기(확장): 이름·금액 줄을 받아 계좌이체 헌금으로 넣는다.
 * 이름이 교적에서 한 명으로만 맞을 때(또는 교인이 아닌 헌금자 이름과 정확히 같을 때)만 넣고, 나머지는 사람이 고르도록 돌려준다.
 */
route('POST', '/offering-batches/{id}/import', function (int $id) {
    $a = require_entry();
    $batch = batch_for_entry($id, $a);
    if ($batch['status'] !== '입력 중') json_fail('invalid_state', '마감된 묶음입니다.', 409);
    require_month_open($batch['batch_date']);
    $b = body();
    $acct = row('SELECT * FROM ' . t('income_accounts') . ' WHERE id = ? AND is_offering = 1 AND is_active = 1 AND named = 1', [in_int($b, 'accountId', 1, PHP_INT_MAX, '헌금 종류')]);
    if (!$acct) json_fail('invalid', '이름을 적는 헌금 종류를 골라 주세요.', 422);
    $in = $b['rows'] ?? null;
    if (!is_array($in) || !$in || count($in) > 500) json_fail('invalid', '내역은 1~500줄이어야 합니다.', 422);
    $added = 0;
    $unmatched = [];
    tx(function () use ($in, $acct, $batch, $a, $id, &$added, &$unmatched) {
        foreach (array_values($in) as $n => $r) {
            $name = trim((string)($r['name'] ?? ''));
            $amt = $r['amount'] ?? null;
            if (is_string($amt) && preg_match('/^\d+$/', $amt)) $amt = (int)$amt;
            if ($name === '' || !is_int($amt) || $amt < 1 || $amt > 9_000_000_000_000) { $unmatched[] = ['row' => $n, 'name' => $name, 'amount' => $r['amount'] ?? null, 'why' => '이름이나 금액이 올바르지 않습니다.']; continue; }
            $people = rows('SELECT id FROM ' . cmt('people') . ' WHERE name = ? AND purged_on IS NULL LIMIT 3', [$name]);
            $donor = null;
            if (count($people) === 1) $donor = ensure_donor_for_person($people[0]['id']);
            elseif (count($people) > 1) { $unmatched[] = ['row' => $n, 'name' => $name, 'amount' => $amt, 'why' => '같은 이름의 교인이 여러 명입니다. 직접 골라 주세요.']; continue; }
            else {
                $d = rows('SELECT id FROM ' . t('donors') . ' WHERE person_id IS NULL AND name = ? LIMIT 2', [$name]);
                if (count($d) === 1) $donor = (int)$d[0]['id'];
                else { $unmatched[] = ['row' => $n, 'name' => $name, 'amount' => $amt, 'why' => '교적에서 찾지 못했습니다. 이름을 확인하거나 교인이 아닌 헌금자로 추가해 주세요.']; continue; }
            }
            q('INSERT INTO ' . t('offerings') . ' (batch_id, offering_date, income_account_id, donor_id, amount, method, memo, created_by) VALUES (?,?,?,?,?,?,?,?)',
                [$id, $batch['batch_date'], $acct['id'], $donor, $amt, '계좌이체', mb_substr(trim((string)($r['memo'] ?? '')) ?: '통장 내역', 0, 255), $a['access']['id']]);
            $added++;
        }
    });
    audit('통장 내역 가져오기', "묶음 $id: $added 건");
    json_out(['added' => $added, 'unmatched' => $unmatched]);
});
