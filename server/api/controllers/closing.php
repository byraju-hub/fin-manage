<?php
declare(strict_types=1);

// 월 마감·연 마감(이월), 직접 지출 확인, 결산서·예산서, 엑셀(CSV) 내려받기.
// 월 마감을 만드는 순간 그 달의 헌금·거래는 잠긴다(month_closed). 재정위원장이 승인하고, 풀 수 있는 사람도 재정위원장이다.

function require_chair(): array
{
    return require_role('재정위원장');
}

function period_range(string $period): array
{
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) json_fail('invalid', '월 형식이 올바르지 않습니다. (예: 2026-03)', 422);
    return [$period . '-01', date('Y-m-t', strtotime($period . '-01'))];
}

/** 회계연도 안의 달 목록 (YYYY-MM) */
function year_months(int $year): array
{
    $fy = row('SELECT start_date, end_date FROM ' . t('fiscal_years') . ' WHERE year = ?', [$year]);
    if (!$fy) return [];
    $out = [];
    for ($d = new DateTime(substr($fy['start_date'], 0, 7) . '-01'); $d->format('Y-m') <= substr($fy['end_date'], 0, 7); $d->modify('+1 month')) $out[] = $d->format('Y-m');
    return $out;
}

route('GET', '/closings', function () {
    $a = require_account();
    if (!has_role($a, '재정 담당', '재정위원장', '감사', '담임목사')) json_fail('forbidden', '권한이 없습니다.', 403);
    $year = in_int($_GET, 'year', 2000, 2100, '연도');
    $rows = array_column(rows('SELECT * FROM ' . t('closings') . ' WHERE period LIKE ?', [$year . '%']), null, 'period');
    $months = [];
    foreach (year_months($year) as $m) {
        $c = $rows[$m] ?? null;
        [$from, $to] = period_range($m);
        $open = (int)val('SELECT COUNT(*) FROM ' . t('offering_batches') . " WHERE status = '입력 중' AND batch_date BETWEEN ? AND ?", [$from, $to]);
        $directUnchecked = (int)val('SELECT COUNT(*) FROM ' . t('transactions') . " WHERE is_direct = 1 AND void_at IS NULL AND direct_checked_at IS NULL AND tx_date BETWEEN ? AND ?", [$from, $to]);
        $months[] = ['period' => $m, 'status' => !$c || $c['reopened_at'] ? '열림' : ($c['approved_at'] ? '승인됨' : '마감(승인 대기)'), 'openBatches' => $open, 'directUnchecked' => $directUnchecked,
            'balances' => $c && !$c['reopened_at'] ? json_decode((string)$c['balances'], true) : null, 'note' => $c['note'] ?? null, 'closedAt' => $c['closed_at'] ?? null];
    }
    $fy = row('SELECT status FROM ' . t('fiscal_years') . ' WHERE year = ?', [$year]);
    json_out(['year' => $year, 'yearStatus' => $fy['status'] ?? null, 'months' => $months, 'balances' => wallet_balances(null)]);
});

/** 월 마감: 계좌별 실제 잔액을 적고 장부 잔액과 대조한다. 차이가 있으면 메모가 필요하다. */
route('POST', '/closings', function () {
    $a = require_role('재정 담당');
    $b = body();
    $period = in_str($b, 'period', 7, true, '월');
    [$from, $to] = period_range($period);
    $year = fiscal_year_of($from);
    if ($year === null) json_fail('invalid', '이 달이 속한 회계연도가 없습니다.', 422);
    $existing = row('SELECT * FROM ' . t('closings') . ' WHERE period = ?', [$period]);
    if ($existing && !$existing['reopened_at']) json_fail('invalid_state', '이미 마감된 달입니다.', 409);
    $idx = array_search($period, year_months($year), true);
    if ($idx > 0) {
        $prev = year_months($year)[$idx - 1];
        if (!val('SELECT COUNT(*) FROM ' . t('closings') . ' WHERE period = ? AND reopened_at IS NULL', [$prev])) json_fail('order', $prev . ' 월이 아직 마감되지 않았습니다. 앞 달부터 마감해 주세요.', 409);
    }
    if ((int)val('SELECT COUNT(*) FROM ' . t('offering_batches') . " WHERE status = '입력 중' AND batch_date BETWEEN ? AND ?", [$from, $to])) json_fail('open_batches', '마감하지 않은 헌금 묶음이 있습니다. 헌금을 먼저 마감해 주세요.', 409);
    $pendingVouchers = (int)val('SELECT COUNT(*) FROM ' . t('vouchers') . " WHERE status = '결재 완료' AND approved_at BETWEEN ? AND ?", [$from . ' 00:00:00', $to . ' 23:59:59']);
    $actual = is_array($b['actual'] ?? null) ? $b['actual'] : [];
    $bal = [];
    $diff = false;
    foreach (wallet_balances($to) as $w) {
        if (!$w['active'] && $w['balance'] === 0) continue;
        $act = array_key_exists((string)$w['id'], $actual) && $actual[(string)$w['id']] !== '' ? in_int($actual, (string)$w['id'], -9_000_000_000_000, 9_000_000_000_000, $w['name'] . ' 실제 잔액') : null;
        if ($act === null) json_fail('invalid', $w['name'] . '의 실제 잔액(통장 잔액·현금 시재)을 적어 주세요.', 422);
        if ($act !== $w['balance']) $diff = true;
        $bal[(string)$w['id']] = ['name' => $w['name'], 'book' => $w['balance'], 'actual' => $act];
    }
    $note = in_str($b, 'note', 255);
    if ($diff && $note === '') json_fail('diff_note', '장부 잔액과 실제 잔액이 다른 계좌가 있어요. 마감하려면 차이의 사유를 적어 주세요.', 422);
    tx(function () use ($existing, $period, $bal, $note, $a) {
        if ($existing) q('UPDATE ' . t('closings') . ' SET balances = ?, note = ?, closed_by = ?, closed_at = NOW(), approved_by = NULL, approved_at = NULL, reopened_at = NULL, reopened_by = NULL WHERE period = ?', [json_encode($bal, JSON_UNESCAPED_UNICODE), $note ?: null, $a['access']['id'], $period]);
        else q('INSERT INTO ' . t('closings') . ' (period, balances, note, closed_by) VALUES (?,?,?,?)', [$period, json_encode($bal, JSON_UNESCAPED_UNICODE), $note ?: null, $a['access']['id']]);
        change_log('closing', $period, 'close', null, '마감', $note);
    });
    audit('월 마감', $period, $a);
    json_out(['pendingPay' => $pendingVouchers]);
});

route('POST', '/closings/{period}/approve', function (string $period) {
    $a = require_chair();
    $c = row('SELECT * FROM ' . t('closings') . ' WHERE period = ?', [$period]);
    if (!$c || $c['reopened_at']) json_fail('not_found', '마감된 달이 아닙니다.', 404);
    if ($c['approved_at']) json_fail('invalid_state', '이미 승인되었습니다.', 409);
    [$from, $to] = period_range($period);
    $rules = direct_rules();
    if (!empty($rules['close_confirm']) && (int)val('SELECT COUNT(*) FROM ' . t('transactions') . ' WHERE is_direct = 1 AND void_at IS NULL AND direct_checked_at IS NULL AND tx_date BETWEEN ? AND ?', [$from, $to])) {
        json_fail('direct_unchecked', '직접 지출을 아직 모두 확인하지 않았습니다. 먼저 확인해 주세요.', 409);
    }
    q('UPDATE ' . t('closings') . ' SET approved_by = ?, approved_at = NOW() WHERE period = ?', [$a['access']['id'], $period]);
    audit('월 마감 승인', $period, $a);
    json_out(null);
});

route('POST', '/closings/{period}/reopen', function (string $period) {
    $a = require_chair();
    require_reconfirm();
    $reason = in_str(body(), 'reason', 255, true, '마감을 푸는 사유');
    $c = row('SELECT * FROM ' . t('closings') . ' WHERE period = ?', [$period]);
    if (!$c || $c['reopened_at']) json_fail('not_found', '마감된 달이 아닙니다.', 404);
    if (val('SELECT COUNT(*) FROM ' . t('closings') . ' WHERE period > ? AND reopened_at IS NULL', [$period])) json_fail('order', '뒤 달이 마감되어 있습니다. 뒤 달부터 풀어 주세요.', 409);
    if (val('SELECT status FROM ' . t('fiscal_years') . ' WHERE year = ?', [(int)substr($period, 0, 4)]) === '마감') json_fail('closed', '연 마감된 회계연도입니다.', 409);
    q('UPDATE ' . t('closings') . ' SET reopened_at = NOW(), reopened_by = ? WHERE period = ?', [$a['access']['id'], $period]);
    change_log('closing', $period, 'reopen', '마감', '열림', $reason);
    audit('월 마감 풀기', $period . ' ' . $reason, $a);
    json_out(null);
});

/** 직접 지출 목록(확인 전/후)과 일괄 확인: 재정위원장이 월 마감 때 한꺼번에 확인한다 */
route('GET', '/direct-expenses', function () {
    $a = require_account();
    if (!has_role($a, '재정위원장', '재정 담당', '감사')) json_fail('forbidden', '권한이 없습니다.', 403);
    $m = (string)($_GET['month'] ?? '');
    [$from, $to] = period_range($m);
    $r = rows('SELECT t.id, t.tx_date, t.amount, t.summary, t.vendor, t.direct_checked_at, u.name AS uname, bi.name AS iname FROM ' . t('transactions') . ' t LEFT JOIN ' . t('units') . ' u ON u.id = t.unit_id LEFT JOIN ' . t('budget_items') . ' bi ON bi.id = t.budget_item_id WHERE t.is_direct = 1 AND t.void_at IS NULL AND t.tx_date BETWEEN ? AND ? ORDER BY t.tx_date, t.id', [$from, $to]);
    json_out(['items' => array_map(fn($x) => ['id' => (int)$x['id'], 'date' => $x['tx_date'], 'amount' => (int)$x['amount'], 'summary' => $x['summary'], 'vendor' => $x['vendor'], 'unit' => $x['uname'], 'item' => $x['iname'], 'checked' => $x['direct_checked_at'] !== null], $r), 'canCheck' => has_role($a, '재정위원장')]);
});

route('POST', '/direct-expenses/check', function () {
    $a = require_chair();
    $ids = array_values(array_unique(array_map('intval', is_array(body()['ids'] ?? null) ? body()['ids'] : [])));
    if (!$ids) json_fail('invalid', '확인할 항목을 고르세요.', 422);
    q('UPDATE ' . t('transactions') . ' SET direct_checked_at = NOW() WHERE is_direct = 1 AND direct_checked_at IS NULL AND id IN (' . marks($ids) . ')', $ids);
    audit('직접 지출 확인', count($ids) . '건', $a);
    json_out(null);
});

/** 연 마감: 12개 달이 모두 마감·승인되어야 한다. 계좌별 남은 돈을 다음 회계연도의 '이월금'으로 올리고 이 해를 마감한다. */
route('POST', '/fiscal-years/{id}/close-year', function (int $year) {
    $a = require_chair();
    require_reconfirm();
    $fy = row('SELECT * FROM ' . t('fiscal_years') . ' WHERE year = ?', [$year]);
    if (!$fy) json_fail('not_found', '회계연도를 찾을 수 없습니다.', 404);
    if ($fy['status'] === '마감') json_fail('invalid_state', '이미 마감된 회계연도입니다.', 409);
    foreach (year_months($year) as $m) {
        $c = row('SELECT * FROM ' . t('closings') . ' WHERE period = ?', [$m]);
        if (!$c || $c['reopened_at'] || !$c['approved_at']) json_fail('months', $m . ' 월이 마감·승인되지 않았습니다. 모든 달을 마감하고 승인한 뒤에 연 마감을 할 수 있어요.', 409);
    }
    $next = row('SELECT * FROM ' . t('fiscal_years') . ' WHERE year = ?', [$year + 1]);
    if (!$next) json_fail('no_next', ($year + 1) . '년 회계연도를 먼저 만들어 주세요. (남은 돈을 이월금으로 올릴 곳)', 409);
    if ($next['status'] === '마감') json_fail('closed', ($year + 1) . '년이 이미 마감되어 있습니다.', 409);
    $acct = (int)val('SELECT id FROM ' . t('income_accounts') . " WHERE name = '이월금' ORDER BY id LIMIT 1");
    tx(function () use ($year, $fy, $next, $acct, $a) {
        foreach (wallet_balances($fy['end_date']) as $w) {
            if ($w['balance'] === 0) continue;
            $fund = (int)val('SELECT fund_id FROM ' . t('wallets') . ' WHERE id = ?', [$w['id']]);
            q('INSERT INTO ' . t('transactions') . ' (fiscal_year, tx_date, kind, fund_id, wallet_id, income_account_id, amount, summary, source, created_by) VALUES (?,?,?,?,?,?,?,?,?,?)',
                [$year + 1, $next['start_date'], '이월', $fund, $w['id'], $acct ?: null, $w['balance'], $year . '년 이월금 (' . $w['name'] . ')', '이월', $a['access']['id']]);
        }
        q('UPDATE ' . t('fiscal_years') . " SET status = '마감' WHERE year = ?", [$year]);
        change_log('fiscal_year', $year, 'year_close', null, '연 마감 및 이월');
    });
    audit('연 마감', (string)$year, $a);
    json_out(null);
});

// ---------- 결산서·예산서 ----------
function settlement_data(int $year): array
{
    $inc = [];
    $rows = fn(int $y) => rows('SELECT ia.id, ia.name, COALESCE(SUM(t.amount),0) AS s FROM ' . t('income_accounts') . ' ia LEFT JOIN ' . t('transactions') . " t ON t.income_account_id = ia.id AND t.kind = '수입' AND t.void_at IS NULL AND t.fiscal_year = ? WHERE ia.id NOT IN (SELECT DISTINCT parent_id FROM " . t('income_accounts') . ' WHERE parent_id IS NOT NULL) GROUP BY ia.id, ia.name ORDER BY ia.sort_order, ia.id', [$y]);
    $prior = array_column($rows($year - 1), 's', 'id');
    $bud = array_column(rows('SELECT income_account_id AS i, amount FROM ' . t('income_budgets') . ' WHERE fiscal_year = ?', [$year]), 'amount', 'i');
    foreach ($rows($year) as $r) if ((int)$r['s'] || (int)($bud[$r['id']] ?? 0) || (int)($prior[$r['id']] ?? 0)) $inc[] = ['name' => $r['name'], 'budget' => (int)($bud[$r['id']] ?? 0), 'actual' => (int)$r['s'], 'prior' => (int)($prior[$r['id']] ?? 0)];
    $exp = [];
    $paid = fn(int $y) => array_column(rows('SELECT t.unit_id AS u, t.budget_item_id AS i, SUM(t.amount) AS s FROM ' . t('transactions') . " t WHERE t.kind = '지출' AND t.void_at IS NULL AND t.fiscal_year = ? GROUP BY t.unit_id, t.budget_item_id", [$y]), 's', 'i');
    $cur = $paid($year);
    $units = rows('SELECT id, name FROM ' . t('units') . ' WHERE fiscal_year = ? ORDER BY sort_order, id', [$year]);
    $st = item_stats($year);
    foreach ($units as $u) {
        $items = [];
        foreach (rows('SELECT id, name, amount FROM ' . t('budget_items') . ' WHERE unit_id = ? ORDER BY sort_order, id', [$u['id']]) as $i) {
            $b = (int)$i['amount'] + ($st['changes'][(int)$i['id']] ?? 0);
            $a = (int)($cur[$i['id']] ?? 0);
            if ($b || $a) $items[] = ['name' => $i['name'], 'budget' => $b, 'actual' => $a];
        }
        if ($items) $exp[] = ['unit' => $u['name'], 'items' => $items];
    }
    $carry = (int)val('SELECT COALESCE(SUM(amount),0) FROM ' . t('transactions') . " WHERE kind = '이월' AND void_at IS NULL AND fiscal_year = ?", [$year]);
    $fy = row('SELECT end_date FROM ' . t('fiscal_years') . ' WHERE year = ?', [$year]);
    return ['year' => $year, 'income' => $inc, 'expense' => $exp, 'carryIn' => $carry, 'wallets' => wallet_balances($fy['end_date'] ?? null)];
}

route('GET', '/reports/settlement', function () {
    $a = require_account();
    if (!can_see_totals($a)) json_fail('forbidden', '권한이 없습니다.', 403);
    json_out(settlement_data(in_int($_GET, 'year', 2000, 2100, '연도')));
});

function csv_out(string $name, array $rows): never
{
    $esc = fn($v) => is_int($v) ? (string)$v : '"' . str_replace('"', '""', (string)$v) . '"';
    $lines = ["\xEF\xBB\xBF" . implode(',', array_map($esc, array_shift($rows)))];
    foreach ($rows as $r) $lines[] = implode(',', array_map($esc, $r));
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $name . '.csv"');
    header('X-Content-Type-Options: nosniff');
    echo implode("\r\n", $lines) . "\r\n";
    exit;
}

/** 엑셀로 내려받기(CSV): 장부, 결산서. 개인별 헌금 자료는 내보내지 않는다. */
route('GET', '/export/ledger', function () {
    $a = require_ledger_view();
    $year = in_int($_GET, 'year', 2000, 2100, '연도');
    $r = rows('SELECT t.tx_date, t.kind, w.name AS w, ia.name AS a, u.name AS u, bi.name AS i, t.amount, t.summary, t.vendor, t.void_at FROM ' . t('transactions') . ' t JOIN ' . t('wallets') . ' w ON w.id = t.wallet_id LEFT JOIN ' . t('income_accounts') . ' ia ON ia.id = t.income_account_id LEFT JOIN ' . t('units') . ' u ON u.id = t.unit_id LEFT JOIN ' . t('budget_items') . ' bi ON bi.id = t.budget_item_id WHERE t.fiscal_year = ? ORDER BY t.tx_date, t.id', [$year]);
    $out = [['날짜', '구분', '계좌', '수입 항목', '재정 단위', '예산 항목', '금액', '적요', '거래처', '취소']];
    foreach ($r as $x) $out[] = [$x['tx_date'], $x['kind'], $x['w'], (string)$x['a'], (string)$x['u'], (string)$x['i'], (int)$x['amount'], $x['summary'], (string)$x['vendor'], $x['void_at'] ? '취소' : ''];
    audit('장부 내려받기', (string)$year, $a);
    csv_out('ledger-' . $year, $out);
});

route('GET', '/export/settlement', function () {
    $a = require_account();
    if (!can_see_totals($a)) json_fail('forbidden', '권한이 없습니다.', 403);
    $d = settlement_data(in_int($_GET, 'year', 2000, 2100, '연도'));
    $out = [['구분', '항목', '예산', '결산', '전년']];
    foreach ($d['income'] as $i) $out[] = ['수입', $i['name'], $i['budget'], $i['actual'], $i['prior']];
    foreach ($d['expense'] as $u) foreach ($u['items'] as $i) $out[] = ['지출', $u['unit'] . ' · ' . $i['name'], $i['budget'], $i['actual'], 0];
    csv_out('settlement-' . $d['year'], $out);
});
