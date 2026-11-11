<?php
declare(strict_types=1);

// 재정 체험 모드: 교적의 체험 계정(cm_users.is_demo = 1)으로 로그인하면 별도 표(fmd_*)의 가짜 자료만 보여 주고 보기만 할 수 있다.
//  - 실제 재정 표(fm_*)와 교적의 교인(cm_people)은 체험 모드에서 읽지 않는다. 모든 질의가 t() 를 거치므로 접두어만 fmd_ 로 바뀐다.
//  - 가짜 자료는 처음 체험 로그인할 때 만들고, 7일이 지나면 '최근 8주'가 어긋나지 않게 다시 만든다.
//  - 보기 외의 요청과 민감한 화면은 index.php 의 demo_guard() 가 막는다.

const FM_DEMO_ROLES = ['재정 담당', '재정위원장', '담임목사', '감사', '열람']; // 모든 보기 화면을 열 수 있게. 쓰기는 서버가 막는다.
const FM_DEMO_FRESH_DAYS = 7;

/** 체험 모드 스위치. 켜지면 t() 가 가짜 자료 표를 가리킨다. */
function fm_demo_on(?bool $set = null): bool
{
    static $on = false;
    if ($set !== null) $on = $set;
    return $on;
}

function fm_demo_prefix(): string
{
    return rtrim((string)(fm_config()['prefix'] ?? 'fm_'), '_') . 'd_';
}

/** 교적에 켜져 있는 체험 계정이 있는가 (없으면 로그인 창의 체험 안내를 숨긴다) */
function demo_available(): bool
{
    try {
        return (int)val('SELECT COUNT(*) FROM ' . cmt('users') . " WHERE is_demo = 1 AND status = '사용'") > 0;
    } catch (Throwable) {
        return false;
    }
}

/** 체험 사용자의 계정 정보(교적 계정 + 가짜 재정 권한). 교인과 연결하지 않고(person_id 없음) 최고관리자 권한도 주지 않는다. */
function demo_account(array $u): array
{
    $u['person_id'] = null;
    $u['is_super'] = 0;
    return [
        'cm' => $u,
        'access' => ['id' => 1, 'cm_user_id' => (int)$u['id'], 'login_id' => $u['login_id'], 'name' => '체험 사용자', 'person_id' => null, 'status' => '사용', 'is_admin' => 0, 'can_view_donors' => 1, 'reason' => '', 'requested_at' => date('Y-m-d H:i:s')],
        'roles' => FM_DEMO_ROLES,
        'state' => 'active',
        'demo' => true,
    ];
}

/** 체험 표를 준비한다(없거나 오래되면 새로 만듦). 체험 스위치를 켠 채로 부른다. */
function demo_ensure(): void
{
    static $done = false;
    if ($done) return;
    $meta = '`' . fm_demo_prefix() . 'meta`';
    $pdo = db();
    $seeded = null;
    try {
        $seeded = $pdo->query("SELECT v FROM $meta WHERE k = 'demo_seeded_on'")->fetchColumn();
    } catch (Throwable) {
    }
    if ($seeded && (time() - strtotime((string)$seeded)) < FM_DEMO_FRESH_DAYS * 86400) {
        $done = true;
        return;
    }
    // 만드는 도중에 다시 불려도(예: 마감 처리 안의 변경 기록이 계정을 확인할 때) 또 만들지 않는다
    $done = true;
    // 동시에 여러 명이 처음 들어와도 한 번만 만든다
    $got = (int)$pdo->query("SELECT GET_LOCK('fm_demo_seed', 30)")->fetchColumn();
    try {
        $again = null;
        try {
            $again = $pdo->query("SELECT v FROM $meta WHERE k = 'demo_seeded_on'")->fetchColumn();
        } catch (Throwable) {
        }
        if (!$again || (time() - strtotime((string)$again)) >= FM_DEMO_FRESH_DAYS * 86400) demo_rebuild();
    } finally {
        if ($got) $pdo->query("SELECT RELEASE_LOCK('fm_demo_seed')");
    }
    $done = true;
}

function demo_rebuild(): void
{
    $pdo = db();
    $real = (string)fm_config()['prefix'];
    $dp = fm_demo_prefix();
    $names = $pdo->prepare('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE ?');
    $names->execute([str_replace(['_', '%'], ['\\_', '\\%'], $dp) . '%']);
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach ($names->fetchAll(PDO::FETCH_COLUMN) as $tbl) $pdo->exec('DROP TABLE IF EXISTS `' . $tbl . '`');
    $names->execute([str_replace(['_', '%'], ['\\_', '\\%'], $real) . '%']);
    foreach ($names->fetchAll(PDO::FETCH_COLUMN) as $tbl) {
        $short = substr($tbl, strlen($real));
        if ($short === 'login_attempts') continue;
        $pdo->exec('CREATE TABLE `' . $dp . $short . '` LIKE `' . $tbl . '`');
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    tx(fn() => demo_seed());
}

/** 가짜 이름 목록 (성 20 × 이름 20 중 결정적으로 뽑음). 실제 교인 이름을 쓰지 않는다. */
function demo_names(int $n): array
{
    $sn = ['김', '이', '박', '최', '정', '강', '조', '윤', '장', '임', '한', '오', '서', '신', '권', '황', '안', '송', '류', '홍'];
    $gv = ['가온', '나래', '다솜', '라온', '마루', '보람', '소담', '아람', '여울', '이슬', '자람', '채움', '하람', '해솔', '누리', '두리', '새봄', '슬기', '온새', '한울'];
    $out = [];
    for ($i = 0; count($out) < $n; $i++) {
        $out[] = $sn[($i * 7) % 20] . $gv[($i * 3 + intdiv($i, 20)) % 20];
        $out = array_values(array_unique($out));
    }
    return array_slice($out, 0, $n);
}

function demo_seed(): void
{
    mt_srand(777);
    $p = fm_demo_prefix();
    $now = new DateTime('today');
    $year = (int)$now->format('Y');
    $prior = $year - 1;
    $a = ['access' => ['id' => 1], 'cm' => ['name' => '체험 사용자', 'person_id' => null]];

    // 기본 자료: 설정, 회계, 수입 항목(항목 이름 구조만 복사), 결재 규칙(체험용으로 새로 만듦)
    q('INSERT INTO `' . $p . 'settings` (id, church, rrn_mode, security, direct_rules, receipt_form, backup) VALUES (1, ?, ?, ?, ?, ?, ?)', [
        json_encode(['name' => '체험교회', 'bizNo' => '000-00-00000', 'address' => '체험시 체험구 체험로 1', 'pastorName' => '체험목사', 'phone' => '000-000-0000'], JSON_UNESCAPED_UNICODE), 'none', '{"reconfirm": false}',
        '{"roles": ["재정 담당"], "month_cap": null, "proof_required": true, "close_confirm": true}', '{"line_mode": "year", "allow_family": true, "code": "41"}', '{"interval_days": 7, "keep": 8}',
    ]);
    q('INSERT INTO `' . $p . 'funds` (id, name, is_default, sort_order) VALUES (1, ?, 1, 1)', ['일반회계']);
    q('INSERT INTO `' . $p . 'income_accounts` SELECT * FROM `' . fm_config()['prefix'] . 'income_accounts`');
    q('INSERT INTO `' . $p . "approval_rules` (kind, name, min_amount, max_amount, steps, add_on, sort_order) VALUES
        ('일반', '소액', 0, 100000, '[{\"role\":\"부서장\",\"mode\":\"결재\"},{\"role\":\"위원장\",\"mode\":\"결재\"},{\"role\":\"재정위원장\",\"mode\":\"확인\"}]', '{\"over_budget\":\"담임목사\"}', 1),
        ('일반', '일반', 100001, 500000, '[{\"role\":\"부서장\",\"mode\":\"결재\"},{\"role\":\"위원장\",\"mode\":\"결재\"},{\"role\":\"재정위원장\",\"mode\":\"결재\"}]', '{\"over_budget\":\"담임목사\"}', 2),
        ('일반', '고액', 500001, NULL, '[{\"role\":\"부서장\",\"mode\":\"결재\"},{\"role\":\"위원장\",\"mode\":\"결재\"},{\"role\":\"재정위원장\",\"mode\":\"결재\"},{\"role\":\"담임목사\",\"mode\":\"결재\"}]', '{}', 3)");
    q('INSERT INTO `' . $p . "access` (id, cm_user_id, login_id, name, status, is_admin) VALUES (1, 0, 'demo', '체험 사용자', '사용', 0)");
    q('INSERT INTO `' . $p . 'fiscal_years` (year, start_date, end_date, status, budget_confirmed_on) VALUES (?,?,?,?,?)', [$year, "$year-01-01", "$year-12-31", '진행', "$year-01-05"]);
    q('INSERT INTO `' . $p . 'fiscal_years` (year, start_date, end_date, status) VALUES (?,?,?,?)', [$prior, "$prior-01-01", "$prior-12-31", '편성 중']);
    $cash = insert_id('INSERT INTO ' . t('wallets') . ' (fund_id, name, kind, opening_balance, sort_order) VALUES (1, ?, ?, ?, 1)', ['현금', '현금', 0]);
    $bank = insert_id('INSERT INTO ' . t('wallets') . ' (fund_id, name, kind, bank, acct_tail, opening_balance, sort_order) VALUES (1, ?, ?, ?, ?, ?, 2)', ['주거래 통장', '통장', '체험은행', '0000', 8000000]);

    $acct = fn(string $name) => (int)(val('SELECT id FROM ' . t('income_accounts') . ' WHERE name = ? LIMIT 1', [$name]) ?: val('SELECT id FROM ' . t('income_accounts') . ' WHERE is_offering = 1 LIMIT 1'));
    [$tithe, $thanks, $sun, $mission, $build] = [$acct('십일조'), $acct('감사헌금'), $acct('주일헌금'), $acct('선교헌금'), $acct('건축헌금')];

    // 재정 단위·담당자(모두 가짜 이름)
    $names = demo_names(80);
    $ni = 0;
    $person = function () use (&$ni, $names) { return [new_uuid(), $names[$ni++ % count($names)]]; };
    $unit = fn(int $y, ?int $parent, string $kind, string $name, int $sort) => insert_id('INSERT INTO ' . t('units') . ' (fiscal_year, parent_id, kind, name, sort_order) VALUES (?,?,?,?,?)', [$y, $parent, $kind, $name, $sort]);
    $role = function (int $uid, string $r) use ($person) { [$pid, $nm] = $person(); q('INSERT INTO ' . t('unit_roles') . ' (unit_id, role, person_id, person_name) VALUES (?,?,?,?)', [$uid, $r, $pid, $nm]); };
    $tree = ['예배위원회' => ['예배부', '찬양대'], '교육위원회' => ['유치부', '청년부'], '선교위원회' => ['국내선교부', '해외선교부'], '관리위원회' => ['시설관리부']];
    $depts = [];
    foreach ([$year, $prior] as $y) {
        $s = 1;
        foreach ($tree as $cn => $ds) {
            $c = $unit($y, null, '위원회', $cn, $s++);
            if ($y === $year) $role($c, '위원장');
            foreach ($ds as $dn) {
                $d = $unit($y, $c, '부서', $dn, $s++);
                if ($y === $year) { $role($d, '회계'); $role($d, '부서장'); $depts[] = ['id' => $d, 'name' => $dn, 'parent' => $c]; }
            }
        }
        $unit($y, null, '기타', '기타 지출', 9100);
        $unit($y, null, '예비비', '예비비', 9000);
    }
    $etc = (int)val('SELECT id FROM ' . t('units') . " WHERE fiscal_year = ? AND kind = '기타'", [$year]);
    $reserve = (int)val('SELECT id FROM ' . t('units') . " WHERE fiscal_year = ? AND kind = '예비비'", [$year]);

    // 예산
    $items = [];
    foreach ($depts as $d) foreach (['행사비' => [3, 12], '교육비' => [2, 8], '운영비' => [1, 5]] as $n => [$lo, $hi]) {
        $items[$d['id']][$n] = insert_id('INSERT INTO ' . t('budget_items') . ' (unit_id, name, amount, basis, sort_order) VALUES (?,?,?,?,?)', [$d['id'], $n, mt_rand($lo, $hi) * 100000, '체험용 산출 근거', count($items[$d['id']] ?? []) + 1]);
    }
    $direct = [];
    foreach (['사례비' => 60000000, '공과금' => 12000000, '관리비' => 6000000] as $n => $amt) {
        $direct[$n] = insert_id('INSERT INTO ' . t('budget_items') . ' (unit_id, name, amount, basis, direct_ok, direct_limit, sort_order) VALUES (?,?,?,?,?,?,?)', [$etc, $n, $amt, '체험용', $n === '사례비' ? 0 : 1, $n === '사례비' ? null : 1000000, count($direct) + 1]);
    }
    insert_id('INSERT INTO ' . t('budget_items') . ' (unit_id, name, amount, sort_order) VALUES (?,?,?,1)', [$reserve, '예비비', 5000000]);
    foreach ([$tithe => 90000000, $thanks => 12000000, $sun => 20000000, $mission => 8000000, $build => 6000000] as $ia => $amt) q('INSERT INTO ' . t('income_budgets') . ' (fiscal_year, income_account_id, amount) VALUES (?,?,?) ON DUPLICATE KEY UPDATE amount = VALUES(amount)', [$year, $ia, $amt]);

    // 헌금자(교인이 아닌 헌금자로 — 교적과 이어지지 않는다)
    $donors = [];
    foreach (array_slice(demo_names(40), 0, 36) as $nm) {
        $did = insert_id('INSERT INTO ' . t('donors') . ' (name, receipt_address) VALUES (?, ?)', [$nm, '체험시 체험구 체험로 ' . mt_rand(1, 99)]);
        $donors[] = ['id' => $did, 'tithe' => mt_rand(3, 30) * 10000];
    }
    $mk = function (string $date, int $y, array $who) use ($a, $cash, $bank, $tithe, $thanks, $sun, $mission, $build) {
        $bid = insert_id('INSERT INTO ' . t('offering_batches') . ' (fiscal_year, batch_date, kind, counters, created_by) VALUES (?,?,?,?,?)', [$y, $date, '주일예배', '체험 계수 위원', 1]);
        $add = fn(int $ac, ?int $d, int $amt, string $m = '현금') => q('INSERT INTO ' . t('offerings') . ' (batch_id, offering_date, income_account_id, donor_id, amount, method, created_by) VALUES (?,?,?,?,?,?,1)', [$bid, $date, $ac, $d, $amt, $m]);
        foreach ($who as $d) {
            if (mt_rand(1, 10) <= 7) $add($tithe, $d['id'], $d['tithe'], mt_rand(1, 6) === 1 ? '계좌이체' : '현금');
            if (mt_rand(1, 10) <= 2) $add($thanks, $d['id'], mt_rand(1, 10) * 10000);
            if (mt_rand(1, 10) <= 1) $add($mission, $d['id'], mt_rand(1, 5) * 10000);
            if (mt_rand(1, 20) <= 1) $add($build, $d['id'], mt_rand(5, 20) * 10000);
        }
        $add($sun, null, mt_rand(18, 40) * 10000);
        close_batch_in_tx($bid, $a, $cash, $bank, '');
    };
    $sundays = [];
    for ($d = new DateTime('today'); count($sundays) < 8; $d->modify('-1 day')) if ($d->format('w') === '0' && $d->format('Y') === (string)$year) $sundays[] = $d->format('Y-m-d');
    foreach (array_reverse($sundays ?: [date('Y-m-d')]) as $s) $mk($s, $year, $donors);
    for ($m = 1; $m <= 12; $m++) {
        $d = new DateTime("$prior-" . sprintf('%02d', $m) . '-01');
        while ($d->format('w') !== '0') $d->modify('+1 day');
        $mk($d->format('Y-m-d'), $prior, array_slice($donors, 0, 24));
    }

    // 기타 수입·직접 지출·이체
    $d0 = $sundays ? $sundays[0] : date('Y-m-d');
    $tx = fn(string $kind, int $w, ?int $ac, ?int $u, ?int $it, int $amt, string $sum, int $dir = 0, ?int $to = null) => q('INSERT INTO ' . t('transactions') . ' (fiscal_year, tx_date, kind, fund_id, wallet_id, to_wallet_id, income_account_id, unit_id, budget_item_id, amount, summary, proof_note, source, is_direct, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,1)', [$year, $d0, $kind, 1, $w, $to, $ac, $u, $it, $amt, $sum, $dir ? '체험용' : null, '직접', $dir]);
    $other = (int)val('SELECT id FROM ' . t('income_accounts') . " WHERE name = '교회보조금' LIMIT 1") ?: null;
    $tx('수입', $bank, $other, null, null, 500000, '노회 보조금');
    $tx('이체', $bank, null, null, null, 300000, '현금 인출', 0, $cash);
    $tx('지출', $bank, null, $etc, (int)$direct['공과금'], 385000, '전기·수도 요금', 1);
    $tx('지출', $bank, null, $etc, (int)$direct['관리비'], 220000, '청소 용역', 1);
    $tx('지출', $bank, null, $etc, (int)$direct['사례비'], 4500000, '사례비', 0);

    // 지출결의서 3건 (지급 완료 / 결재 중 / 작성 중)
    $dept = $depts[0];
    [$hp, $hn] = $person(); [$cp, $cn] = $person(); [$ap, $an] = $person(); [$fp, $fn] = $person();
    $vo = function (string $title, int $item, int $amt, string $status, array $steps, ?string $doc) use ($dept, $year, $ap, $an) {
        $vid = insert_id('INSERT INTO ' . t('vouchers') . ' (doc_no, fiscal_year, unit_id, drafter_access_id, drafter_person_id, drafter_name, title, reason, total, pay_method, pay_bank, pay_account, pay_holder, status, round, submitted_at, approved_at) VALUES (?,?,?,1,?,?,?,?,?,?,?,?,?,?,1,?,?)',
            [$doc, $year, $dept['id'], $ap, $an, $title, '체험용 지출 사유입니다.', $amt, '계좌이체', '체험은행', '000-000-000000', '체험상사', $status, $doc ? date('Y-m-d H:i:s', strtotime('-3 days')) : null, in_array($status, ['결재 완료', '지급 완료'], true) ? date('Y-m-d H:i:s', strtotime('-1 day')) : null]);
        q('INSERT INTO ' . t('voucher_items') . ' (voucher_id, budget_item_id, summary, amount) VALUES (?,?,?,?)', [$vid, $item, $title, $amt]);
        foreach ($steps as $n => [$r, $pid, $nm, $st]) q('INSERT INTO ' . t('approval_steps') . ' (voucher_id, round, step_no, role, approver_person_id, approver_name, mode, status, acted_at, comment) VALUES (?,?,?,?,?,?,?,?,?,?)', [$vid, 1, $n + 1, $r, $pid, $nm, '결재', $st, $st === '완료' ? date('Y-m-d H:i:s', strtotime('-2 days')) : null, $st === '완료' ? '체험용 결재' : null]);
        return $vid;
    };
    $v1 = $vo('11월 교사 모임 다과', (int)$items[$dept['id']]['운영비'], 180000, '지급 완료', [['부서장', $hp, $hn, '완료'], ['위원장', $cp, $cn, '완료'], ['재정위원장', $fp, $fn, '완료']], "$year-0001");
    q('INSERT INTO ' . t('transactions') . ' (fiscal_year, tx_date, kind, fund_id, wallet_id, unit_id, budget_item_id, amount, summary, vendor, source, source_id, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,1)', [$year, $d0, '지출', 1, $bank, $dept['id'], $items[$dept['id']]['운영비'], 180000, "$year-0001 11월 교사 모임 다과", '체험상사', '결의서', $v1]);
    q('UPDATE ' . t('voucher_items') . ' SET transaction_id = ? WHERE voucher_id = ?', [(int)db()->lastInsertId(), $v1]);
    q('UPDATE ' . t('vouchers') . ' SET paid_at = ?, paid_wallet_id = ?, paid_by = 1 WHERE id = ?', [$d0 . ' 00:00:00', $bank, $v1]);
    $vo('성탄 행사 준비물', (int)$items[$dept['id']]['행사비'], 420000, '결재 중', [['부서장', $hp, $hn, '완료'], ['위원장', $cp, $cn, '대기'], ['재정위원장', $fp, $fn, '대기']], "$year-0002");
    $vo('교육 교재 구입', (int)$items[$dept['id']]['교육비'], 150000, '작성 중', [], null);
    q('INSERT INTO ' . t('meta') . " (k, v) VALUES (?, '2') ON DUPLICATE KEY UPDATE v = '2'", ['voucher_seq_' . $year]);
    q('INSERT INTO ' . t('meta') . " (k, v) VALUES ('demo_seeded_on', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)", [date('Y-m-d H:i:s')]);
}

/** 체험 모드에서 보기 외의 요청과 민감한 화면을 막는다. 로그아웃만 예외. */
function demo_guard(string $path): void
{
    if (is_unsafe_method()) {
        if ($path === '/auth/logout') return;
        json_fail('demo_readonly', '체험 계정은 보기만 할 수 있어요. 저장하거나 바꿀 수는 없어요.', 403);
    }
    foreach (['/admin', '/backup', '/import', '/export', '/receipts-export', '/settings', '/delegations', '/voucher-files', '/transaction-files'] as $prefix) {
        if ($path === $prefix || str_starts_with($path, $prefix . '/')) json_fail('demo_blocked', '체험 계정에서는 이 화면을 열 수 없어요.', 403);
    }
}
