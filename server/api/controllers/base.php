<?php
declare(strict_types=1);

// 기준 자료: 회계연도, 회계(펀드), 계좌, 수입 항목. 고치는 것은 재정 관리자·재정 담당, 보는 것은 승인된 모든 사람.

function in_date(array $b, string $key, string $label): string
{
    $v = in_str($b, $key, 10, true, $label);
    $d = DateTime::createFromFormat('Y-m-d', $v);
    if (!$d || $d->format('Y-m-d') !== $v) json_fail('invalid', $label . ' 형식이 올바르지 않습니다. (예: 2026-01-01)', 422);
    return $v;
}

function base_payload(): array
{
    $years = rows('SELECT year, start_date, end_date, status, budget_confirmed_on FROM ' . t('fiscal_years') . ' ORDER BY year DESC');
    $funds = rows('SELECT id, name, is_default, sort_order, is_active FROM ' . t('funds') . ' ORDER BY sort_order, id');
    $wallets = rows('SELECT id, fund_id, name, kind, bank, acct_tail, opening_balance, sort_order, is_active FROM ' . t('wallets') . ' ORDER BY sort_order, id');
    $accts = rows('SELECT id, parent_id, name, sort_order, is_offering, named, receiptable, fund_id, needs_unit, needs_purpose, is_active FROM ' . t('income_accounts') . ' ORDER BY parent_id IS NOT NULL, sort_order, id');
    $int = fn($v) => $v === null ? null : (int)$v;
    return [
        'years' => array_map(fn($r) => ['year' => (int)$r['year'], 'start' => $r['start_date'], 'end' => $r['end_date'], 'status' => $r['status'], 'budgetConfirmedOn' => $r['budget_confirmed_on']], $years),
        'funds' => array_map(fn($r) => ['id' => (int)$r['id'], 'name' => $r['name'], 'isDefault' => (int)$r['is_default'] === 1, 'active' => (int)$r['is_active'] === 1], $funds),
        'wallets' => array_map(fn($r) => ['id' => (int)$r['id'], 'fundId' => (int)$r['fund_id'], 'name' => $r['name'], 'kind' => $r['kind'], 'bank' => $r['bank'], 'acctTail' => $r['acct_tail'], 'opening' => (int)$r['opening_balance'], 'active' => (int)$r['is_active'] === 1], $wallets),
        'incomeAccounts' => array_map(fn($r) => [
            'id' => (int)$r['id'], 'parentId' => $int($r['parent_id']), 'name' => $r['name'], 'sort' => (int)$r['sort_order'],
            'isOffering' => (int)$r['is_offering'] === 1, 'named' => (int)$r['named'] === 1, 'receiptable' => (int)$r['receiptable'] === 1,
            'fundId' => $int($r['fund_id']), 'needsUnit' => (int)$r['needs_unit'] === 1, 'needsPurpose' => (int)$r['needs_purpose'] === 1, 'active' => (int)$r['is_active'] === 1,
        ], $accts),
    ];
}

route('GET', '/base', function () {
    require_account();
    json_out(base_payload());
});

// ----- 회계연도 -----
route('POST', '/fiscal-years', function () {
    require_staff();
    $b = body();
    $year = in_int($b, 'year', 2000, 2100, '연도');
    $start = in_date($b, 'start', '시작일');
    $end = in_date($b, 'end', '종료일');
    if ($end <= $start) json_fail('invalid', '종료일이 시작일보다 뒤여야 합니다.', 422);
    if (val('SELECT COUNT(*) FROM ' . t('fiscal_years') . ' WHERE year = ?', [$year])) json_fail('duplicate', $year . '년 회계연도가 이미 있습니다.', 409);
    q('INSERT INTO ' . t('fiscal_years') . ' (year, start_date, end_date, status) VALUES (?,?,?,?)', [$year, $start, $end, '편성 중']);
    change_log('fiscal_year', $year, 'create', null, $start . ' ~ ' . $end);
    json_out(null);
});

/** 상태는 편성 중 → 진행 → 마감 순서로만 바뀐다. 진행 중인 해는 하나뿐이다. (마감을 되돌리는 것은 마감 단계에서 다룬다.) */
route('POST', '/fiscal-years/{id}/status', function (int $year) {
    require_staff();
    $to = in_enum(body(), 'status', ['진행', '마감'], '상태');
    $cur = row('SELECT * FROM ' . t('fiscal_years') . ' WHERE year = ?', [$year]);
    if (!$cur) json_fail('not_found', '회계연도를 찾을 수 없습니다.', 404);
    $ok = ($cur['status'] === '편성 중' && $to === '진행') || ($cur['status'] === '진행' && $to === '마감');
    if (!$ok) json_fail('invalid_state', $cur['status'] . ' 상태에서 ' . $to . '(으)로 바꿀 수 없습니다.', 409);
    if ($to === '진행' && val('SELECT COUNT(*) FROM ' . t('fiscal_years') . " WHERE status = '진행'")) {
        json_fail('invalid_state', '이미 진행 중인 회계연도가 있습니다. 먼저 마감해 주세요.', 409);
    }
    require_reconfirm();
    q('UPDATE ' . t('fiscal_years') . ' SET status = ? WHERE year = ?', [$to, $year]);
    change_log('fiscal_year', $year, 'status', $cur['status'], $to);
    audit('회계연도 ' . $to, (string)$year);
    json_out(null);
});

// ----- 회계(펀드) · 계좌 -----
route('POST', '/funds', function () {
    require_staff();
    $name = in_str(body(), 'name', 40, true, '회계 이름');
    if (val('SELECT COUNT(*) FROM ' . t('funds') . ' WHERE name = ?', [$name])) json_fail('duplicate', '같은 이름의 회계가 있습니다.', 409);
    $id = insert_id('INSERT INTO ' . t('funds') . ' (name, sort_order) VALUES (?, ?)', [$name, (int)val('SELECT COALESCE(MAX(sort_order),0)+1 FROM ' . t('funds'))]);
    change_log('fund', $id, 'create', null, $name);
    json_out(['id' => $id]);
});

route('POST', '/funds/{id}', function (int $id) {
    require_staff();
    $b = body();
    $cur = row('SELECT * FROM ' . t('funds') . ' WHERE id = ?', [$id]);
    if (!$cur) json_fail('not_found', '회계를 찾을 수 없습니다.', 404);
    $name = array_key_exists('name', $b) ? in_str($b, 'name', 40, true, '회계 이름') : $cur['name'];
    $active = array_key_exists('active', $b) ? (int)(bool)$b['active'] : (int)$cur['is_active'];
    if ((int)$cur['is_default'] === 1 && !$active) json_fail('invalid', '기본 회계는 사용 중지할 수 없습니다.', 409);
    if ($name !== $cur['name'] && val('SELECT COUNT(*) FROM ' . t('funds') . ' WHERE name = ? AND id <> ?', [$name, $id])) json_fail('duplicate', '같은 이름의 회계가 있습니다.', 409);
    q('UPDATE ' . t('funds') . ' SET name = ?, is_active = ? WHERE id = ?', [$name, $active, $id]);
    if ($name !== $cur['name']) change_log('fund', $id, 'name', $cur['name'], $name);
    json_out(null);
});

route('POST', '/wallets', function () {
    require_staff();
    $b = body();
    $fund = in_int($b, 'fundId', 1, PHP_INT_MAX, '회계');
    if (!val('SELECT COUNT(*) FROM ' . t('funds') . ' WHERE id = ?', [$fund])) json_fail('invalid', '회계를 찾을 수 없습니다.', 422);
    $name = in_str($b, 'name', 40, true, '계좌 이름');
    $kind = in_enum($b, 'kind', ['통장', '현금'], '종류');
    $bank = in_str($b, 'bank', 30);
    $tail = in_str($b, 'acctTail', 10);
    if ($tail !== '' && !preg_match('/^[0-9\-]{1,10}$/', $tail)) json_fail('invalid', '계좌번호는 끝 몇 자리 숫자만 적어 주세요. (전체 번호는 저장하지 않습니다.)', 422);
    $opening = in_int($b, 'opening', -9_000_000_000_000, 9_000_000_000_000, '시작 잔액');
    $id = insert_id(
        'INSERT INTO ' . t('wallets') . ' (fund_id, name, kind, bank, acct_tail, opening_balance, sort_order) VALUES (?,?,?,?,?,?,?)',
        [$fund, $name, $kind, $bank ?: null, $tail ?: null, $opening, (int)val('SELECT COALESCE(MAX(sort_order),0)+1 FROM ' . t('wallets'))]
    );
    change_log('wallet', $id, 'create', null, $name . ' / 시작 잔액 ' . $opening);
    json_out(['id' => $id]);
});

route('POST', '/wallets/{id}', function (int $id) {
    require_staff();
    $b = body();
    $cur = row('SELECT * FROM ' . t('wallets') . ' WHERE id = ?', [$id]);
    if (!$cur) json_fail('not_found', '계좌를 찾을 수 없습니다.', 404);
    $name = array_key_exists('name', $b) ? in_str($b, 'name', 40, true, '계좌 이름') : $cur['name'];
    $bank = array_key_exists('bank', $b) ? in_str($b, 'bank', 30) : (string)$cur['bank'];
    $tail = array_key_exists('acctTail', $b) ? in_str($b, 'acctTail', 10) : (string)$cur['acct_tail'];
    if ($tail !== '' && !preg_match('/^[0-9\-]{1,10}$/', $tail)) json_fail('invalid', '계좌번호는 끝 몇 자리 숫자만 적어 주세요.', 422);
    $active = array_key_exists('active', $b) ? (int)(bool)$b['active'] : (int)$cur['is_active'];
    $opening = (int)$cur['opening_balance'];
    if (array_key_exists('opening', $b)) {
        $opening = in_int($b, 'opening', -9_000_000_000_000, 9_000_000_000_000, '시작 잔액');
        // 거래가 들어간 뒤의 시작 잔액 수정은 장부가 어긋나므로 막는다 (이월 거래로 바로잡는다)
        if ($opening !== (int)$cur['opening_balance'] && val('SELECT COUNT(*) FROM ' . t('transactions') . ' WHERE wallet_id = ? OR to_wallet_id = ?', [$id, $id])) {
            json_fail('locked', '이 계좌에는 이미 거래가 있어 시작 잔액을 바꿀 수 없습니다.', 409);
        }
    }
    q('UPDATE ' . t('wallets') . ' SET name=?, bank=?, acct_tail=?, opening_balance=?, is_active=? WHERE id=?', [$name, $bank ?: null, $tail ?: null, $opening, $active, $id]);
    if ($opening !== (int)$cur['opening_balance']) change_log('wallet', $id, 'opening_balance', $cur['opening_balance'], $opening);
    json_out(null);
});

// ----- 수입 항목 (최대 3단계) -----
function income_depth(?int $parentId): int
{
    $d = 1;
    while ($parentId !== null) {
        $d++;
        $parentId = ($p = val('SELECT parent_id FROM ' . t('income_accounts') . ' WHERE id = ?', [$parentId])) === null ? null : (int)$p;
        if ($d > 5) break;
    }
    return $d;
}

route('POST', '/income-accounts', function () {
    require_staff();
    $b = body();
    $parent = isset($b['parentId']) && $b['parentId'] !== null ? in_int($b, 'parentId', 1, PHP_INT_MAX, '상위 항목') : null;
    if ($parent !== null && !val('SELECT COUNT(*) FROM ' . t('income_accounts') . ' WHERE id = ?', [$parent])) json_fail('invalid', '상위 항목을 찾을 수 없습니다.', 422);
    if (income_depth($parent) > 3) json_fail('invalid', '수입 항목은 3단계까지만 만들 수 있습니다.', 422);
    $name = in_str($b, 'name', 40, true, '항목 이름');
    if (val('SELECT COUNT(*) FROM ' . t('income_accounts') . ' WHERE name = ? AND parent_id <=> ?', [$name, $parent])) json_fail('duplicate', '같은 곳에 같은 이름의 항목이 있습니다.', 409);
    $flag = fn(string $k, int $def = 0) => array_key_exists($k, $b) ? (int)(bool)$b[$k] : $def;
    $id = insert_id(
        'INSERT INTO ' . t('income_accounts') . ' (parent_id, name, sort_order, is_offering, named, receiptable, needs_unit, needs_purpose) VALUES (?,?,?,?,?,?,?,?)',
        [$parent, $name, (int)val('SELECT COALESCE(MAX(sort_order),0)+1 FROM ' . t('income_accounts') . ' WHERE parent_id <=> ?', [$parent]), $flag('isOffering'), $flag('named', 1), $flag('receiptable'), $flag('needsUnit'), $flag('needsPurpose')]
    );
    change_log('income_account', $id, 'create', null, $name);
    json_out(['id' => $id]);
});

route('POST', '/income-accounts/{id}', function (int $id) {
    require_staff();
    $b = body();
    $cur = row('SELECT * FROM ' . t('income_accounts') . ' WHERE id = ?', [$id]);
    if (!$cur) json_fail('not_found', '수입 항목을 찾을 수 없습니다.', 404);
    $name = array_key_exists('name', $b) ? in_str($b, 'name', 40, true, '항목 이름') : $cur['name'];
    $f = fn(string $k, string $col) => array_key_exists($k, $b) ? (int)(bool)$b[$k] : (int)$cur[$col];
    $new = ['is_offering' => $f('isOffering', 'is_offering'), 'named' => $f('named', 'named'), 'receiptable' => $f('receiptable', 'receiptable'), 'needs_unit' => $f('needsUnit', 'needs_unit'), 'needs_purpose' => $f('needsPurpose', 'needs_purpose'), 'is_active' => $f('active', 'is_active')];
    if ($name !== $cur['name'] && val('SELECT COUNT(*) FROM ' . t('income_accounts') . ' WHERE name = ? AND parent_id <=> ? AND id <> ?', [$name, $cur['parent_id'], $id])) json_fail('duplicate', '같은 곳에 같은 이름의 항목이 있습니다.', 409);
    // 이미 헌금이 기록된 항목의 영수증 포함 여부를 바꾸면 지난 영수증과 어긋날 수 있어 기록을 남긴다
    q('UPDATE ' . t('income_accounts') . ' SET name=?, is_offering=?, named=?, receiptable=?, needs_unit=?, needs_purpose=?, is_active=? WHERE id=?',
        [$name, $new['is_offering'], $new['named'], $new['receiptable'], $new['needs_unit'], $new['needs_purpose'], $new['is_active'], $id]);
    if ($name !== $cur['name']) change_log('income_account', $id, 'name', $cur['name'], $name);
    foreach ($new as $col => $v) if ($v !== (int)$cur[$col]) change_log('income_account', $id, $col, $cur[$col], $v);
    json_out(null);
});
