<?php
declare(strict_types=1);

// 샘플 자료 (재정 관리자): 교적의 교인·조직표를 바탕으로 연습용 재정 자료를 만든다.
// 헌금자·부서·담당자는 교적의 실제(샘플) 교인에서 뽑고, 헌금·예산·결의서·영수증 대상을 채운다.
// 만든 것의 기록(fm_meta.sample_info)을 남겨 두어 '샘플 지우기'가 자기가 만든 것만 지운다.
// 이미 헌금·장부·결의서·예산 항목·헌금자가 있는 곳에는 올리지 않는다.

function sample_info(): ?array
{
    $v = val('SELECT v FROM ' . t('meta') . " WHERE k = 'sample_info'");
    return $v ? json_decode((string)$v, true) : null;
}

function sample_busy(): ?string
{
    foreach (['offering_batches' => '헌금', 'transactions' => '장부 거래', 'vouchers' => '지출결의서', 'budget_items' => '예산 항목', 'donors' => '헌금자'] as $tbl => $label) {
        if ((int)val('SELECT COUNT(*) FROM ' . t($tbl))) return $label;
    }
    return null;
}

/** 샘플을 올린 뒤 개수가 달라진 항목 (샘플 밖의 자료가 들어왔다는 뜻) */
function sample_changes(array $info): array
{
    $now = ['헌금 묶음' => (int)val('SELECT COUNT(*) FROM ' . t('offering_batches')), '헌금' => (int)val('SELECT COUNT(*) FROM ' . t('offerings')), '장부 거래' => (int)val('SELECT COUNT(*) FROM ' . t('transactions')),
        '예산 항목' => (int)val('SELECT COUNT(*) FROM ' . t('budget_items')), '결의서' => (int)val('SELECT COUNT(*) FROM ' . t('vouchers')), '영수증' => (int)val('SELECT COUNT(*) FROM ' . t('receipts'))];
    $was = ($info['counts'] ?? []) + ['영수증' => 0];
    $out = [];
    foreach ($now as $k => $n) if (isset($was[$k]) && $n !== (int)$was[$k]) $out[$k] = ['was' => (int)$was[$k], 'now' => $n];
    return $out;
}

route('GET', '/admin/sample', function () {
    require_admin();
    $info = sample_info();
    json_out(['loaded' => $info !== null, 'busy' => $info === null ? sample_busy() : null, 'info' => $info, 'changes' => $info ? sample_changes($info) : new stdClass(), 'cmPeople' => cm_status()['people']]);
});

route('POST', '/admin/sample/load', function () {
    $a = require_admin();
    require_reconfirm();
    if (sample_info()) json_fail('invalid_state', '이미 샘플 자료가 올라와 있습니다.', 409);
    if ($why = sample_busy()) json_fail('busy', "이미 {$why} 자료가 있어 샘플을 올릴 수 없습니다. 샘플은 비어 있는 재정에만 올립니다.", 409);
    if (!$a['access']) json_fail('forbidden', '재정 사용 기록이 필요합니다.', 403);
    $info = tx(fn() => sample_load($a));
    audit('샘플 자료 올리기', json_encode($info['counts'], JSON_UNESCAPED_UNICODE), $a);
    json_out($info);
});

route('POST', '/admin/sample/clear', function () {
    $a = require_admin();
    require_reconfirm();
    $info = sample_info();
    if (!$info) json_fail('invalid_state', '지울 샘플 자료가 없습니다.', 409);
    $ch = sample_changes($info);
    if ($ch && empty(body()['force'])) {
        json_fail('changed', '샘플을 올린 뒤에 직접 입력하거나 바꾼 자료가 있어요 (' . implode(', ', array_map(fn($k, $v) => "$k {$v['was']}→{$v['now']}", array_keys($ch), $ch)) . '). 지우면 이 자료도 함께 사라집니다.', 409);
    }
    tx(fn() => sample_clear($info));
    audit('샘플 자료 지우기', '', $a);
    json_out(null);
});

function sample_clear(array $info): void
{
    foreach (['receipts', 'voucher_files', 'voucher_items', 'approval_steps', 'vouchers', 'transactions', 'offerings', 'offering_batches'] as $tbl) q('DELETE FROM ' . t($tbl));
    q('UPDATE ' . t('donors') . ' SET receipt_to_donor_id = NULL');
    q('DELETE FROM ' . t('donors'));
    foreach (['budget_changes', 'income_budgets', 'budget_items', 'delegations'] as $tbl) q('DELETE FROM ' . t($tbl));
    q('DELETE FROM ' . t('closings'));
    foreach ($info['unitYears'] ?? [] as $y) {
        q('DELETE FROM ' . t('unit_roles') . ' WHERE unit_id IN (SELECT id FROM ' . t('units') . ' WHERE fiscal_year = ?)', [$y]);
        q('UPDATE ' . t('units') . ' SET parent_id = NULL WHERE fiscal_year = ?', [$y]);
        q('DELETE FROM ' . t('units') . ' WHERE fiscal_year = ?', [$y]);
    }
    foreach ($info['wallets'] ?? [] as $w) q('DELETE FROM ' . t('wallets') . ' WHERE id = ?', [(int)$w]);
    foreach ($info['years'] ?? [] as $y) q('DELETE FROM ' . t('fiscal_years') . ' WHERE year = ?', [(int)$y]);
    q("DELETE FROM " . t('meta') . " WHERE k = 'sample_info' OR k LIKE 'voucher\\_seq\\_%' OR k LIKE 'receipt\\_seq\\_%'");
    foreach (['vouchers', 'proofs'] as $sub) {
        $dir = FM_DATA . '/' . $sub;
        foreach (glob($dir . '/*/*') ?: [] as $f) @unlink($f);
        foreach (glob($dir . '/*', GLOB_ONLYDIR) ?: [] as $d) @rmdir($d);
    }
}

function sample_year(int $year, string $status, array &$created): void
{
    if (val('SELECT COUNT(*) FROM ' . t('fiscal_years') . ' WHERE year = ?', [$year])) return;
    q('INSERT INTO ' . t('fiscal_years') . ' (year, start_date, end_date, status) VALUES (?,?,?,?)', [$year, "$year-01-01", "$year-12-31", $status]);
    $created[] = $year;
}

function sample_load(array $a): array
{
    mt_srand(20261111); // 같은 교적이면 같은 샘플이 나오도록
    $now = new DateTime('today');
    $year = (int)$now->format('Y');
    $prior = $year - 1;
    $me = (int)$a['access']['id'];
    $myPid = $a['cm']['person_id'] ?? null;
    $created = [];
    $hasProgress = (int)val('SELECT COUNT(*) FROM ' . t('fiscal_years') . " WHERE status = '진행'") > 0;
    sample_year($year, $hasProgress ? '편성 중' : '진행', $created);
    sample_year($prior, '편성 중', $created);
    foreach ([$year, $prior] as $y) if (!in_array((int)val('SELECT status FROM ' . t('fiscal_years') . ' WHERE year = ?', [$y]), [0], true) && val('SELECT status FROM ' . t('fiscal_years') . ' WHERE year = ?', [$y]) === '마감') json_fail('closed', "$y 년이 마감되어 있어 샘플을 올릴 수 없습니다.", 409);

    // 계좌
    $cash = insert_id('INSERT INTO ' . t('wallets') . ' (fund_id, name, kind, opening_balance, sort_order) VALUES (1, ?, ?, ?, 90)', ['샘플 현금', '현금', 0]);
    $bank = insert_id('INSERT INTO ' . t('wallets') . ' (fund_id, name, kind, bank, acct_tail, opening_balance, sort_order) VALUES (1, ?, ?, ?, ?, ?, 91)', ['샘플 주거래통장', '통장', '샘플은행', '0000', 8000000]);

    // 교인 풀: 성인 위주로 섞어서
    $adult = $now->modify('-20 years')->format('Y-m-d');
    $pool = rows('SELECT id, name, address1, address2 FROM ' . cmt('people') . ' WHERE purged_on IS NULL AND (birth_date IS NULL OR birth_date <= ?) ORDER BY seq LIMIT 600', [$adult]);
    if (count($pool) < 20) json_fail('few_people', '교적에 교인이 20명 이상 있어야 샘플을 만들 수 있습니다.', 409);
    shuffle($pool);
    $donors = [];
    foreach (array_slice($pool, 0, 36) as $p) {
        $d = ensure_donor_for_person($p['id']);
        $addr = trim(trim((string)$p['address1']) . ' ' . trim((string)$p['address2']));
        if ($addr === '') q('UPDATE ' . t('donors') . ' SET receipt_address = ? WHERE id = ?', ['샘플시 샘플구 샘플로 ' . mt_rand(1, 99), $d]);
        $donors[] = ['id' => $d, 'tithe' => mt_rand(3, 30) * 10000];
    }

    // 재정 단위 (교적 조직표) + 담당자
    $unitYears = [];
    foreach ([$year, $prior] as $y) {
        if (!val('SELECT COUNT(*) FROM ' . t('units') . ' WHERE fiscal_year = ?', [$y])) {
            import_cm_units($y);
            $unitYears[] = $y;
        }
    }
    $depts = rows('SELECT id, name, parent_id FROM ' . t('units') . " WHERE fiscal_year = ? AND kind = '부서' ORDER BY sort_order, id", [$year]);
    $persons = array_slice($pool, 36, 60) ?: $pool;
    foreach ($depts as $i => $d) {
        if (!val('SELECT COUNT(*) FROM ' . t('unit_roles') . " WHERE unit_id = ? AND role = '회계'", [$d['id']])) {
            $p = $persons[$i % count($persons)];
            q('INSERT INTO ' . t('unit_roles') . ' (unit_id, role, person_id, person_name) VALUES (?,?,?,?)', [$d['id'], '회계', $p['id'], $p['name']]);
        }
        if (!val('SELECT COUNT(*) FROM ' . t('unit_roles') . " WHERE unit_id = ? AND role = '부서장'", [$d['id']])) {
            $p = $persons[($i * 3 + 1) % count($persons)];
            q('INSERT INTO ' . t('unit_roles') . ' (unit_id, role, person_id, person_name) VALUES (?,?,?,?)', [$d['id'], '부서장', $p['id'], $p['name']]);
        }
    }
    foreach (rows('SELECT id FROM ' . t('units') . " WHERE fiscal_year = ? AND kind = '위원회'", [$year]) as $i => $c) {
        if (!val('SELECT COUNT(*) FROM ' . t('unit_roles') . " WHERE unit_id = ? AND role = '위원장'", [$c['id']])) {
            $p = $persons[($i * 5 + 2) % count($persons)];
            q('INSERT INTO ' . t('unit_roles') . ' (unit_id, role, person_id, person_name) VALUES (?,?,?,?)', [$c['id'], '위원장', $p['id'], $p['name']]);
        }
    }
    $etc = (int)val('SELECT id FROM ' . t('units') . " WHERE fiscal_year = ? AND kind = '기타' LIMIT 1", [$year]);
    $reserve = (int)val('SELECT id FROM ' . t('units') . " WHERE fiscal_year = ? AND kind = '예비비' LIMIT 1", [$year]);

    // 예산: 부서마다 항목 3개, 기타 지출(사례비·공과금·관리비), 예비비
    $items = [];
    foreach ($depts as $d) {
        foreach (['행사비' => [3, 12], '교육비' => [2, 8], '운영비' => [1, 5]] as $name => [$lo, $hi]) {
            $items[$d['id']][$name] = insert_id('INSERT INTO ' . t('budget_items') . ' (unit_id, name, amount, basis, sort_order) VALUES (?,?,?,?,?)', [$d['id'], $name, mt_rand($lo, $hi) * 100000, '샘플 산출 근거', count($items[$d['id']] ?? []) + 1]);
        }
    }
    $direct = [];
    if ($etc) {
        foreach (['사례비' => 60000000, '공과금' => 12000000, '관리비' => 6000000] as $i => $amt) {
            $direct[$i] = insert_id('INSERT INTO ' . t('budget_items') . ' (unit_id, name, amount, basis, direct_ok, direct_limit, sort_order) VALUES (?,?,?,?,?,?,?)', [$etc, $i, $amt, '샘플', $i === '사례비' ? 0 : 1, $i === '사례비' ? null : 1000000, count($direct) + 1]);
        }
    }
    if ($reserve) insert_id('INSERT INTO ' . t('budget_items') . ' (unit_id, name, amount, sort_order) VALUES (?,?,?,1)', [$reserve, '예비비', 5000000]);
    foreach ([2 => 90000000, 3 => 12000000, 4 => 20000000, 5 => 8000000, 6 => 6000000, 7 => 3000000, 20 => 5000000] as $acct => $amt) q('INSERT INTO ' . t('income_budgets') . ' (fiscal_year, income_account_id, amount) VALUES (?,?,?)', [$year, $acct, $amt]);
    q('UPDATE ' . t('fiscal_years') . ' SET budget_confirmed_on = ? WHERE year = ?', ["$year-01-05", $year]);

    // 헌금: 올해 최근 8번의 일요일, 작년 매달 첫 일요일(영수증 연습용). 모두 마감해서 장부에 올린다.
    $closeBatch = function (int $bid) use ($a, $cash, $bank) { close_batch_in_tx($bid, $a, $cash, $bank, ''); };
    $mkBatch = function (string $date, int $y, array $who, bool $heavy) use ($me, $donors, $closeBatch) {
        $bid = insert_id('INSERT INTO ' . t('offering_batches') . ' (fiscal_year, batch_date, kind, counters, created_by) VALUES (?,?,?,?,?)', [$y, $date, '주일예배', '샘플 계수 위원', $me]);
        $add = function (int $acct, ?int $donor, int $amt, string $method = '현금') use ($bid, $date, $me) {
            q('INSERT INTO ' . t('offerings') . ' (batch_id, offering_date, income_account_id, donor_id, amount, method, created_by) VALUES (?,?,?,?,?,?,?)', [$bid, $date, $acct, $donor, $amt, $method, $me]);
        };
        foreach ($who as $d) {
            if (mt_rand(1, 10) <= 7) $add(2, $d['id'], $d['tithe'], mt_rand(1, 6) === 1 ? '계좌이체' : '현금');
            if ($heavy) {
                if (mt_rand(1, 10) <= 2) $add(3, $d['id'], mt_rand(1, 10) * 10000);
                if (mt_rand(1, 10) <= 1) $add(6, $d['id'], mt_rand(1, 5) * 10000);
                if (mt_rand(1, 20) <= 1) $add(5, $d['id'], mt_rand(5, 20) * 10000);
            }
        }
        $add(4, null, mt_rand(18, 40) * 10000);
        $closeBatch($bid);
        return $bid;
    };
    $sundays = [];
    for ($d = new DateTime('today'); count($sundays) < 8; $d->modify('-1 day')) if ($d->format('w') === '0' && $d->format('Y') === (string)$year) $sundays[] = $d->format('Y-m-d');
    if (!$sundays) $sundays[] = date('Y-m-d');
    foreach (array_reverse($sundays) as $s) $mkBatch($s, $year, $donors, true);
    $firstSun = [];
    for ($m = 1; $m <= 12; $m++) {
        $d = new DateTime("$prior-" . sprintf('%02d', $m) . '-01');
        while ($d->format('w') !== '0') $d->modify('+1 day');
        $firstSun[] = $d->format('Y-m-d');
    }
    foreach (array_slice($donors, 0, 24) as $i => $_) {} // (작년 헌금자는 앞의 24명)
    foreach ($firstSun as $s) $mkBatch($s, $prior, array_slice($donors, 0, 24), true);

    // 기타 수입 · 직접 지출 · 이체 (올해)
    $d0 = $sundays[count($sundays) - 1];
    $tx = fn(string $kind, string $date, int $w, ?int $acct, ?int $unit, ?int $item, int $amt, string $sum, int $direct = 0, ?int $to = null) => q('INSERT INTO ' . t('transactions') . ' (fiscal_year, tx_date, kind, fund_id, wallet_id, to_wallet_id, income_account_id, unit_id, budget_item_id, amount, summary, source, is_direct, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)', [$year, $date, $kind, 1, $w, $to, $acct, $unit, $item, $amt, $sum, '직접', $direct, $me]);
    $tx('수입', $d0, $bank, 20, null, null, 500000, '샘플 노회 보조금');
    $tx('수입', $d0, $bank, 21, null, null, 12400, '샘플 예금 이자');
    $tx('이체', $d0, $bank, null, null, null, 300000, '샘플 현금 인출', 0, $cash);
    if ($etc && $direct) {
        $tx('지출', $d0, $bank, null, $etc, (int)$direct['공과금'], 385000, '샘플 전기·수도 요금', 1);
        $tx('지출', $d0, $bank, null, $etc, (int)$direct['관리비'], 220000, '샘플 청소 용역', 1);
        $tx('지출', $d0, $bank, null, $etc, (int)$direct['사례비'], 0 + 4500000, '샘플 사례비', 0);
    }

    // 지출결의서 3건: 지급 완료 / 결재 중(내 차례) / 작성 중
    $nv = 0;
    $dept = $depts[0] ?? null;
    if ($dept) {
        $roles = fn(int $u, string $r) => row('SELECT person_id, person_name FROM ' . t('unit_roles') . ' WHERE unit_id = ? AND role = ? LIMIT 1', [$u, $r]);
        $head = $roles((int)$dept['id'], '부서장');
        $acct = $roles((int)$dept['id'], '회계');
        $chair = $dept['parent_id'] ? $roles((int)$dept['parent_id'], '위원장') : null;
        $mk = function (string $title, int $itemId, int $amt, string $status, array $steps, ?string $docNo) use ($dept, $acct, $year, $me) {
            $vid = insert_id('INSERT INTO ' . t('vouchers') . ' (doc_no, fiscal_year, unit_id, drafter_access_id, drafter_person_id, drafter_name, title, reason, total, pay_method, pay_bank, pay_account, pay_holder, status, round, submitted_at, approved_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,?,?)',
                [$docNo, $year, $dept['id'], $me, $acct['person_id'] ?? null, $acct['person_name'] ?? '샘플 회계', $title, '샘플 지출 사유입니다.', $amt, '계좌이체', '샘플은행', '000-000-000000', '샘플상사', $status, $docNo ? date('Y-m-d H:i:s', strtotime('-3 days')) : null, in_array($status, ['결재 완료', '지급 완료'], true) ? date('Y-m-d H:i:s', strtotime('-1 day')) : null]);
            q('INSERT INTO ' . t('voucher_items') . ' (voucher_id, budget_item_id, summary, amount) VALUES (?,?,?,?)', [$vid, $itemId, $title, $amt]);
            foreach ($steps as $n => [$role, $pid, $name, $st]) {
                q('INSERT INTO ' . t('approval_steps') . ' (voucher_id, round, step_no, role, approver_person_id, approver_name, mode, status, acted_at, comment) VALUES (?,?,?,?,?,?,?,?,?,?)', [$vid, 1, $n + 1, $role, $pid, $name, '결재', $st, $st === '완료' ? date('Y-m-d H:i:s', strtotime('-2 days')) : null, $st === '완료' ? '샘플 결재' : null]);
            }
            return $vid;
        };
        $s1 = [['부서장', $head['person_id'] ?? null, $head['person_name'] ?? '샘플 부서장', '완료'], ['위원장', $chair['person_id'] ?? null, $chair['person_name'] ?? '샘플 위원장', '완료'], ['재정위원장', $myPid, $a['cm']['name'] . ' (샘플)', '완료']];
        $v1 = $mk('샘플 11월 교사 모임 다과', (int)$items[$dept['id']]['운영비'], 180000, '지급 완료', $s1, sprintf('%d-%04d', $year, 1));
        q('INSERT INTO ' . t('transactions') . ' (fiscal_year, tx_date, kind, fund_id, wallet_id, unit_id, budget_item_id, amount, summary, vendor, source, source_id, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)', [$year, $d0, '지출', 1, $bank, $dept['id'], $items[$dept['id']]['운영비'], 180000, "{$year}-0001 샘플 11월 교사 모임 다과", '샘플상사', '결의서', $v1, $me]);
        q('UPDATE ' . t('voucher_items') . ' SET transaction_id = ? WHERE voucher_id = ?', [(int)db()->lastInsertId(), $v1]);
        q('UPDATE ' . t('vouchers') . ' SET paid_at = ?, paid_wallet_id = ?, paid_by = ? WHERE id = ?', [$d0 . ' 00:00:00', $bank, $me, $v1]);
        $s2 = [['부서장', $head['person_id'] ?? null, $head['person_name'] ?? '샘플 부서장', '완료'], ['위원장', $chair['person_id'] ?? null, $chair['person_name'] ?? '샘플 위원장', '완료'], ['재정위원장', $myPid, $a['cm']['name'] . ' (샘플)', '대기']];
        $mk('샘플 성탄 행사 준비물', (int)$items[$dept['id']]['행사비'], 420000, '결재 중', $s2, sprintf('%d-%04d', $year, 2));
        $mk('샘플 교육 교재 구입', (int)$items[$dept['id']]['교육비'], 150000, '작성 중', [], null);
        q('INSERT INTO ' . t('meta') . " (k, v) VALUES ('voucher_seq_{$year}', '2') ON DUPLICATE KEY UPDATE v = '2'");
        $nv = 3;
    }

    $counts = [
        '교인(헌금자)' => count($donors), '재정 단위' => (int)val('SELECT COUNT(*) FROM ' . t('units') . ' WHERE fiscal_year = ?', [$year]), '예산 항목' => (int)val('SELECT COUNT(*) FROM ' . t('budget_items')),
        '헌금 묶음' => (int)val('SELECT COUNT(*) FROM ' . t('offering_batches')), '헌금' => (int)val('SELECT COUNT(*) FROM ' . t('offerings')), '장부 거래' => (int)val('SELECT COUNT(*) FROM ' . t('transactions')), '결의서' => $nv,
    ];
    $info = ['years' => $created, 'unitYears' => $unitYears, 'wallets' => [$cash, $bank], 'counts' => $counts, 'at' => date('Y-m-d H:i:s'), 'year' => $year, 'prior' => $prior];
    q('INSERT INTO ' . t('meta') . " (k, v) VALUES ('sample_info', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)", [json_encode($info, JSON_UNESCAPED_UNICODE)]);
    return $info;
}
