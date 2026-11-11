<?php
declare(strict_types=1);

// 장부(수입·지출·이체), 계좌 잔액, 직접 지출, 월 재정 보고, 설정(직접 지출 규칙).
// 거래는 지우지 않고 취소(사유 필수)한다.

function require_ledger_view(): array
{
    $a = require_account();
    if (!has_role($a, '재정 담당', '재정위원장', '담임목사', '감사')) json_fail('forbidden', '장부를 볼 권한이 없습니다.', 403);
    return $a;
}

/** 계좌별 잔액. $asOf 가 있으면 그 날(포함)까지. */
function wallet_balances(?string $asOf = null): array
{
    $cond = $asOf ? ' AND tx_date <= ?' : '';
    $p = $asOf ? [$asOf] : [];
    $w = rows('SELECT id, fund_id, name, kind, opening_balance, is_active FROM ' . t('wallets') . ' ORDER BY sort_order, id');
    $bal = [];
    foreach ($w as $x) $bal[(int)$x['id']] = (int)$x['opening_balance'];
    foreach (rows('SELECT wallet_id, kind, SUM(amount) AS s FROM ' . t('transactions') . ' WHERE void_at IS NULL' . $cond . ' GROUP BY wallet_id, kind', $p) as $r) {
        $s = (int)$r['s'];
        if ($r['kind'] === '수입') $bal[(int)$r['wallet_id']] += $s;
        elseif ($r['kind'] !== '이월') $bal[(int)$r['wallet_id']] -= $s; // 지출·이체(보내는 쪽). 이월은 장부에 보이는 기록일 뿐 잔액은 이어져 있어 더하지 않는다.
    }
    foreach (rows('SELECT to_wallet_id, SUM(amount) AS s FROM ' . t('transactions') . " WHERE void_at IS NULL AND kind = '이체' AND to_wallet_id IS NOT NULL" . $cond . ' GROUP BY to_wallet_id', $p) as $r) $bal[(int)$r['to_wallet_id']] += (int)$r['s'];
    return array_map(fn($x) => ['id' => (int)$x['id'], 'name' => $x['name'], 'kind' => $x['kind'], 'active' => (int)$x['is_active'] === 1, 'balance' => $bal[(int)$x['id']]], $w);
}

route('GET', '/wallets/balances', function () {
    require_ledger_view();
    json_out(['items' => wallet_balances(isset($_GET['asOf']) ? in_date($_GET, 'asOf', '기준일') : null)]);
});

function direct_rules(): array
{
    $r = json_decode((string)val('SELECT direct_rules FROM ' . t('settings') . ' WHERE id = 1'), true) ?: [];
    return $r + ['roles' => ['재정 담당'], 'month_cap' => null, 'proof_required' => true, 'close_confirm' => true];
}

route('GET', '/settings', function () {
    $a = require_account();
    $s = row('SELECT church, rrn_mode, security, direct_rules, receipt_form FROM ' . t('settings') . ' WHERE id = 1');
    json_out(['church' => json_decode((string)$s['church'], true) ?: new stdClass(), 'rrnMode' => $s['rrn_mode'], 'security' => json_decode((string)$s['security'], true), 'directRules' => direct_rules(), 'receiptForm' => json_decode((string)$s['receipt_form'], true), 'isAdmin' => is_admin_account($a)]);
});

route('POST', '/settings/direct-rules', function () {
    require_admin();
    require_reconfirm();
    $b = body();
    $roles = [];
    foreach (is_array($b['roles'] ?? null) ? $b['roles'] : [] as $r) {
        if (!in_array($r, ['재정 담당', '재정위원장'], true)) json_fail('invalid', '직접 지출을 등록할 수 있는 역할은 재정 담당·재정위원장 중에서 고르세요.', 422);
        $roles[] = $r;
    }
    if (!$roles) json_fail('invalid', '등록할 수 있는 역할을 하나 이상 고르세요.', 422);
    $cap = isset($b['monthCap']) && $b['monthCap'] !== null && $b['monthCap'] !== '' ? in_int($b, 'monthCap', 1, 9_000_000_000_000, '월 한도') : null;
    $new = ['roles' => array_values(array_unique($roles)), 'month_cap' => $cap, 'proof_required' => (bool)($b['proofRequired'] ?? true), 'close_confirm' => (bool)($b['closeConfirm'] ?? true)];
    $old = direct_rules();
    q('UPDATE ' . t('settings') . ' SET direct_rules = ?, updated_by = ? WHERE id = 1', [json_encode($new, JSON_UNESCAPED_UNICODE), current_account()['access']['id']]);
    change_log('settings', 'direct_rules', 'direct_rules', $old, $new);
    audit('직접 지출 공통 설정 변경');
    json_out(null);
});

route('GET', '/ledger', function () {
    require_ledger_view();
    $year = in_int($_GET, 'year', 2000, 2100, '연도');
    $w = [' t.fiscal_year = ? '];
    $p = [$year];
    if (!empty($_GET['wallet'])) { $w[] = '(t.wallet_id = ? OR t.to_wallet_id = ?)'; $p[] = (int)$_GET['wallet']; $p[] = (int)$_GET['wallet']; }
    if (!empty($_GET['month']) && preg_match('/^\d{4}-\d{2}$/', (string)$_GET['month'])) { $w[] = "DATE_FORMAT(t.tx_date, '%Y-%m') = ?"; $p[] = (string)$_GET['month']; }
    $limit = isset($_GET['limit']) ? in_int($_GET, 'limit', 100, 5000, '건수') : 500;
    $total = (int)val('SELECT COUNT(*) FROM ' . t('transactions') . ' t WHERE ' . implode(' AND ', $w), $p);
    $r = rows('SELECT t.*, w.name AS wname, w2.name AS w2name, ia.name AS aname, u.name AS uname, bi.name AS iname FROM ' . t('transactions') . ' t JOIN ' . t('wallets') . ' w ON w.id = t.wallet_id LEFT JOIN ' . t('wallets') . ' w2 ON w2.id = t.to_wallet_id
              LEFT JOIN ' . t('income_accounts') . ' ia ON ia.id = t.income_account_id LEFT JOIN ' . t('units') . ' u ON u.id = t.unit_id LEFT JOIN ' . t('budget_items') . ' bi ON bi.id = t.budget_item_id
              WHERE ' . implode(' AND ', $w) . ' ORDER BY t.tx_date DESC, t.id DESC LIMIT ' . $limit, $p);
    json_out(['total' => $total, 'limit' => $limit, 'items' => array_map(fn($x) => [
        'id' => (int)$x['id'], 'date' => $x['tx_date'], 'kind' => $x['kind'], 'wallet' => $x['wname'], 'toWallet' => $x['w2name'], 'account' => $x['aname'], 'unit' => $x['uname'], 'item' => $x['iname'],
        'amount' => (int)$x['amount'], 'summary' => $x['summary'], 'vendor' => $x['vendor'], 'source' => $x['source'], 'isDirect' => (int)$x['is_direct'] === 1, 'checked' => $x['direct_checked_at'] !== null,
        'void' => $x['void_at'] !== null, 'voidReason' => $x['void_reason'],
    ], $r)]);
});

/** 기타 수입·직접 지출·이체를 직접 등록한다. 헌금과 결의서는 각자의 흐름에서 자동으로 들어온다. */
route('POST', '/transactions', function () {
    $a = require_account();
    $b = body();
    $kind = in_enum($b, 'kind', ['수입', '지출', '이체'], '종류');
    $date = in_date($b, 'date', '날짜');
    $amount = in_int($b, 'amount', 1, 9_000_000_000_000, '금액');
    $summary = in_str($b, 'summary', 255, true, '적요');
    $vendor = in_str($b, 'vendor', 100);
    $year = fiscal_year_of($date);
    if ($year === null) json_fail('invalid', '이 날짜가 속한 회계연도가 없습니다.', 422);
    if (val('SELECT status FROM ' . t('fiscal_years') . ' WHERE year = ?', [$year]) === '마감') json_fail('closed', $year . '년은 마감되었습니다.', 409);
    require_month_open($date);
    $wid = in_int($b, 'walletId', 1, PHP_INT_MAX, '계좌');
    $w = row('SELECT * FROM ' . t('wallets') . ' WHERE id = ? AND is_active = 1', [$wid]);
    if (!$w) json_fail('invalid', '계좌가 올바르지 않습니다.', 422);
    $acct = $unit = $item = $to = null;
    $isDirect = 0;
    if ($kind === '수입') {
        if (!has_role($a, '재정 담당')) json_fail('forbidden', '수입은 재정 담당이 등록합니다.', 403);
        $acct = row('SELECT * FROM ' . t('income_accounts') . ' WHERE id = ? AND is_active = 1 AND is_offering = 0', [in_int($b, 'incomeAccountId', 1, PHP_INT_MAX, '수입 항목')]);
        if (!$acct) json_fail('invalid', '수입 항목이 올바르지 않습니다. (헌금은 헌금 화면에서 입력합니다.)', 422);
        if (val('SELECT COUNT(*) FROM ' . t('income_accounts') . ' WHERE parent_id = ?', [$acct['id']])) json_fail('invalid', '세부 항목을 골라 주세요.', 422);
        $acct = (int)$acct['id'];
    } elseif ($kind === '이체') {
        if (!has_role($a, '재정 담당')) json_fail('forbidden', '이체는 재정 담당이 등록합니다.', 403);
        $to = in_int($b, 'toWalletId', 1, PHP_INT_MAX, '받는 계좌');
        if ($to === $wid) json_fail('invalid', '같은 계좌끼리는 이체할 수 없습니다.', 422);
        if (!val('SELECT COUNT(*) FROM ' . t('wallets') . ' WHERE id = ? AND is_active = 1', [$to])) json_fail('invalid', '받는 계좌가 올바르지 않습니다.', 422);
    } else {
        $rules = direct_rules();
        if (!has_role($a, ...$rules['roles'])) json_fail('forbidden', '직접 지출을 등록할 수 있는 역할이 아닙니다. 지출결의서를 쓰세요.', 403);
        $it = row('SELECT i.*, u.fiscal_year FROM ' . t('budget_items') . ' i JOIN ' . t('units') . ' u ON u.id = i.unit_id WHERE i.id = ? AND i.is_active = 1', [in_int($b, 'budgetItemId', 1, PHP_INT_MAX, '예산 항목')]);
        if (!$it || (int)$it['fiscal_year'] !== $year) json_fail('invalid', '예산 항목이 올바르지 않습니다.', 422);
        if ((int)$it['direct_ok'] !== 1) json_fail('not_direct', "'" . $it['name'] . "' 항목은 직접 지출이 허용되지 않았어요. 지출결의서를 써 주세요.", 403);
        if ($it['direct_limit'] !== null && $amount > (int)$it['direct_limit']) json_fail('over_limit', '이 항목의 직접 지출 한도(' . number_format((int)$it['direct_limit']) . '원)를 넘습니다. 지출결의서를 써 주세요.', 403);
        if ($rules['month_cap']) {
            $used = (int)val('SELECT COALESCE(SUM(amount),0) FROM ' . t('transactions') . " WHERE is_direct = 1 AND void_at IS NULL AND DATE_FORMAT(tx_date,'%Y-%m') = ?", [substr($date, 0, 7)]);
            if ($used + $amount > (int)$rules['month_cap']) json_fail('over_cap', '이번 달 직접 지출 한도(' . number_format((int)$rules['month_cap']) . '원)를 넘습니다.', 403);
        }
        $item = (int)$it['id'];
        $unit = (int)$it['unit_id'];
        $isDirect = 1;
    }
    $id = insert_id(
        'INSERT INTO ' . t('transactions') . ' (fiscal_year, tx_date, kind, fund_id, wallet_id, to_wallet_id, income_account_id, unit_id, budget_item_id, amount, summary, vendor, source, is_direct, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
        [$year, $date, $kind, $w['fund_id'], $wid, $to, $acct, $unit, $item, $amount, $summary, $vendor ?: null, '직접', $isDirect, $a['access']['id']]
    );
    change_log('transaction', $id, 'create', null, "$kind $amount $summary");
    json_out(['id' => $id]);
});

route('POST', '/transactions/{id}/void', function (int $id) {
    $a = require_role('재정 담당');
    $reason = in_str(body(), 'reason', 255, true, '취소 사유');
    $t = row('SELECT * FROM ' . t('transactions') . ' WHERE id = ?', [$id]);
    if (!$t) json_fail('not_found', '거래를 찾을 수 없습니다.', 404);
    if ($t['void_at'] !== null) json_fail('invalid_state', '이미 취소된 거래입니다.', 409);
    if ($t['source'] === '헌금') json_fail('invalid_state', '헌금 장부는 헌금 묶음을 다시 열어서 고칩니다.', 409);
    if ($t['source'] === '결의서') json_fail('invalid_state', '지급된 결의서의 거래는 직접 취소하지 않고 정정 거래를 냅니다.', 409);
    require_month_open($t['tx_date']);
    q('UPDATE ' . t('transactions') . ' SET void_at = NOW(), void_reason = ?, void_by = ? WHERE id = ?', [$reason, $a['access']['id'], $id]);
    change_log('transaction', $id, 'void', null, $reason);
    audit('거래 취소', "#$id $reason");
    json_out(null);
});

/** 월 재정 보고: 전월 이월, 수입·지출 총괄(이번 달·누계), 월말 계좌 잔액 */
route('GET', '/reports/monthly', function () {
    $a = require_account();
    if (!can_see_totals($a)) json_fail('forbidden', '권한이 없습니다.', 403);
    $year = in_int($_GET, 'year', 2000, 2100, '연도');
    $month = in_int($_GET, 'month', 1, 12, '월');
    $first = sprintf('%04d-%02d-01', $year, $month);
    $last = date('Y-m-t', strtotime($first));
    $fy = row('SELECT start_date FROM ' . t('fiscal_years') . ' WHERE year = ?', [$year]);
    $start = $fy ? $fy['start_date'] : "$year-01-01";
    $prevDay = date('Y-m-d', strtotime($first . ' -1 day'));
    $in = [];
    foreach (rows('SELECT ia.name, SUM(CASE WHEN t.tx_date >= ? THEN t.amount ELSE 0 END) AS m, SUM(t.amount) AS c FROM ' . t('transactions') . ' t JOIN ' . t('income_accounts') . " ia ON ia.id = t.income_account_id WHERE t.kind = '수입' AND t.void_at IS NULL AND t.tx_date BETWEEN ? AND ? GROUP BY ia.id, ia.name, ia.sort_order ORDER BY ia.sort_order, ia.id", [$first, $start, $last]) as $r) {
        $in[] = ['name' => $r['name'], 'month' => (int)$r['m'], 'cum' => (int)$r['c']];
    }
    $ex = [];
    foreach (rows('SELECT COALESCE(u.name, \'(단위 없음)\') AS name, SUM(CASE WHEN t.tx_date >= ? THEN t.amount ELSE 0 END) AS m, SUM(t.amount) AS c FROM ' . t('transactions') . ' t LEFT JOIN ' . t('units') . " u ON u.id = t.unit_id WHERE t.kind = '지출' AND t.void_at IS NULL AND t.tx_date BETWEEN ? AND ? GROUP BY u.id, u.name ORDER BY u.sort_order, u.id", [$first, $start, $last]) as $r) {
        $ex[] = ['name' => $r['name'], 'month' => (int)$r['m'], 'cum' => (int)$r['c']];
    }
    $carryIn = array_sum(array_column(wallet_balances($prevDay), 'balance'));
    $wallets = wallet_balances($last);
    json_out(['period' => sprintf('%04d년 %d월', $year, $month), 'carryIn' => $carryIn, 'carryOut' => array_sum(array_column($wallets, 'balance')),
        'income' => $in, 'expense' => $ex, 'incomeTotal' => array_sum(array_column($in, 'month')), 'expenseTotal' => array_sum(array_column($ex, 'month')),
        'wallets' => array_map(fn($w) => ['name' => $w['name'], 'balance' => $w['balance']], array_filter($wallets, fn($w) => $w['active'] || $w['balance'] !== 0))]);
});
