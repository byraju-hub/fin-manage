<?php
declare(strict_types=1);

// 기존 자료 가져오기 (재정 담당): 엑셀·CSV에서 읽어 온 줄을 화면이 조각(300줄)으로 보낸다.
//  - 헌금 내역: 미리보기(저장 안 함) → 가져오기(날짜별 '가져온 자료' 묶음에 입력) → 마감(장부에 수입으로)
//  - 예산: 지출 예산 항목, 수입 예산. 확정 전에만.
// 줄마다 맞지 않는 이유를 돌려주고, 맞는 줄만 넣는다.

const FM_IMPORT_MAX_ROWS = 400;
const FM_IMPORT_TAG = '가져온 자료';

function import_rows(): array
{
    $r = body()['rows'] ?? null;
    if (!is_array($r) || !$r) json_fail('invalid', '가져올 줄이 없습니다.', 422);
    if (count($r) > FM_IMPORT_MAX_ROWS) json_fail('invalid', '한 번에 ' . FM_IMPORT_MAX_ROWS . '줄까지 보낼 수 있습니다.', 422);
    return array_values(array_map(fn($x) => is_array($x) ? $x : [], $r));
}

function imp_str(array $r, string $k, int $max = 100): string
{
    $v = $r[$k] ?? '';
    return mb_substr(trim(is_scalar($v) ? (string)$v : ''), 0, $max);
}

function imp_int($v, int $min, int $max): ?int
{
    if (is_string($v)) $v = preg_replace('/[,\s원]/u', '', $v);
    if (is_string($v) && preg_match('/^-?\d+$/', $v)) $v = (int)$v;
    return is_int($v) && $v >= $min && $v <= $max ? $v : null;
}

function imp_date(string $v): ?string
{
    $d = DateTime::createFromFormat('Y-m-d', $v);
    return $d && $d->format('Y-m-d') === $v ? $v : null;
}

/** 헌금 종류 이름 → 헌금 입력 가능한 수입 항목. '십일조' '십일조헌금' 모두 맞는다. */
function imp_offering_accounts(): array
{
    $m = [];
    foreach (rows('SELECT * FROM ' . t('income_accounts') . ' WHERE is_offering = 1 AND is_active = 1') as $a) {
        $m[$a['name']] = $a;
        $m[preg_replace('/헌금$/u', '', $a['name'])] ??= $a;
        $m[preg_replace('/헌금$/u', '', $a['name']) . '헌금'] ??= $a;
    }
    return $m;
}

/** 줄마다 검사하고 어디에 넣을지 정한다. (저장하지 않는다.) */
function imp_check_offerings(array $in, bool $createOutsiders): array
{
    $accts = imp_offering_accounts();
    $years = [];
    foreach (rows('SELECT year, start_date, end_date, status FROM ' . t('fiscal_years')) as $y) $years[] = $y;
    $units = [];
    $out = [];
    foreach ($in as $n => $r) {
        $e = fn(string $m) => ['ok' => false, 'error' => $m, 'dup' => false];
        $date = imp_date(imp_str($r, 'date', 10));
        if (!$date) { $out[$n] = $e('날짜를 읽지 못했습니다. (예: 2025-03-02)'); continue; }
        $fy = null;
        foreach ($years as $y) if ($date >= $y['start_date'] && $date <= $y['end_date']) $fy = $y;
        if (!$fy) { $out[$n] = $e(substr($date, 0, 4) . '년 회계연도가 없습니다. 기준 자료에서 먼저 만들어 주세요.'); continue; }
        if ($fy['status'] === '마감') { $out[$n] = $e($fy['year'] . '년은 마감되어 가져올 수 없습니다.'); continue; }
        if (month_closed($date)) { $out[$n] = $e(substr($date, 0, 7) . ' 월은 마감되어 가져올 수 없습니다.'); continue; }
        $an = imp_str($r, 'account', 40);
        $acct = $accts[$an] ?? $accts[preg_replace('/헌금$/u', '', $an)] ?? null;
        if (!$acct) { $out[$n] = $e("헌금 종류 '$an'을(를) 찾지 못했습니다. 기준 자료의 수입 항목 이름과 같게 적어 주세요."); continue; }
        $amount = imp_int($r['amount'] ?? null, 1, 9_000_000_000_000);
        if ($amount === null) { $out[$n] = $e('금액이 올바르지 않습니다.'); continue; }
        $method = imp_str($r, 'method', 10);
        $method = $method === '' ? '현금' : (str_contains($method, '이체') ? '계좌이체' : (str_contains($method, '온라인') || str_contains($method, '카드') ? '온라인' : ($method === '현금' ? '현금' : '')));
        if ($method === '') { $out[$n] = $e('받은 방법은 현금·계좌이체·온라인 중 하나여야 합니다.'); continue; }
        $name = imp_str($r, 'name', 50);
        $donor = null; // null 무기명 / int 헌금자 번호 / string 새로 만들 교인 아닌 헌금자 이름
        if ((int)$acct['named'] === 0) {
            $name = '';
        } elseif ($name !== '') {
            $birth = imp_int($r['birth'] ?? null, 1900, 2100);
            $sql = 'SELECT id FROM ' . cmt('people') . ' WHERE name = ? AND purged_on IS NULL' . ($birth ? ' AND YEAR(birth_date) = ?' : '') . ' LIMIT 3';
            $ppl = rows($sql, $birth ? [$name, $birth] : [$name]);
            if (count($ppl) === 1) $donor = ensure_donor_for_person($ppl[0]['id']);
            elseif (count($ppl) > 1) { $out[$n] = $e("같은 이름 '$name' 교인이 여러 명입니다. 출생연도 칸을 채워 주세요."); continue; }
            else {
                $d = rows('SELECT id FROM ' . t('donors') . ' WHERE person_id IS NULL AND name = ? LIMIT 2', [$name]);
                if (count($d) === 1) $donor = (int)$d[0]['id'];
                elseif ($createOutsiders) $donor = $name;
                else { $out[$n] = $e("교적에서 '$name'을(를) 찾지 못했습니다. 이름을 확인하거나 '교인이 아닌 헌금자로 새로 만들기'를 켜 주세요."); continue; }
            }
        }
        $unit = null;
        if ((int)$acct['needs_unit'] === 1) {
            $un = imp_str($r, 'unit', 60);
            $units[$fy['year']] ??= array_column(rows('SELECT id, name FROM ' . t('units') . ' WHERE fiscal_year = ?', [$fy['year']]), 'id', 'name');
            $unit = $units[$fy['year']][$un] ?? null;
            if (!$unit) { $out[$n] = $e("부서 '$un'을(를) 찾지 못했습니다. (재정 단위 이름과 같게)"); continue; }
        }
        $purpose = null;
        if ((int)$acct['needs_purpose'] === 1) {
            $purpose = imp_str($r, 'purpose', 100);
            if ($purpose === '') { $out[$n] = $e('목적헌금은 목적이 필요합니다.'); continue; }
        }
        $dup = false;
        if (!is_string($donor)) {
            $dup = (int)val('SELECT COUNT(*) FROM ' . t('offerings') . ' WHERE offering_date = ? AND income_account_id = ? AND amount = ? AND donor_id <=> ?', [$date, $acct['id'], $amount, $donor]) > 0;
        }
        $out[$n] = ['ok' => true, 'error' => null, 'dup' => $dup, 'date' => $date, 'year' => (int)$fy['year'], 'acct' => (int)$acct['id'], 'accountName' => $acct['name'], 'donor' => $donor, 'amount' => $amount, 'method' => $method, 'unit' => $unit, 'purpose' => $purpose, 'memo' => imp_str($r, 'memo', 255) ?: null, 'who' => $name];
    }
    return $out;
}

function imp_view(array $res): array
{
    return array_map(fn($x) => ['ok' => $x['ok'], 'error' => $x['error'], 'dup' => $x['dup'], 'newDonor' => $x['ok'] && is_string($x['donor'])], $res);
}

route('POST', '/import/offerings/preview', function () {
    require_role('재정 담당');
    $res = imp_check_offerings(import_rows(), !empty(body()['createOutsiders']));
    json_out(['rows' => imp_view($res)]);
});

route('POST', '/import/offerings/commit', function () {
    $a = require_role('재정 담당');
    $b = body();
    $skipDup = !isset($b['skipDup']) || (bool)$b['skipDup'];
    $res = tx(function () use ($a, $b, $skipDup) {
        $res = imp_check_offerings(import_rows(), !empty($b['createOutsiders']));
        $batches = [];
        $added = 0;
        foreach ($res as $n => &$x) {
            if (!$x['ok'] || ($x['dup'] && $skipDup)) { $x['added'] = false; continue; }
            $key = $x['date'];
            if (!isset($batches[$key])) {
                $bid = val('SELECT id FROM ' . t('offering_batches') . " WHERE batch_date = ? AND kind = '기타' AND counters = ? AND status = '입력 중' ORDER BY id LIMIT 1", [$x['date'], FM_IMPORT_TAG]);
                $batches[$key] = $bid ? (int)$bid : insert_id('INSERT INTO ' . t('offering_batches') . ' (fiscal_year, batch_date, kind, counters, created_by) VALUES (?,?,?,?,?)', [$x['year'], $x['date'], '기타', FM_IMPORT_TAG, $a['access']['id']]);
            }
            $donor = $x['donor'];
            if (is_string($donor)) {
                $donor = (int)val('SELECT id FROM ' . t('donors') . ' WHERE person_id IS NULL AND name = ? LIMIT 1', [$donor]) ?: insert_id('INSERT INTO ' . t('donors') . ' (name) VALUES (?)', [$x['donor']]);
            }
            q('INSERT INTO ' . t('offerings') . ' (batch_id, offering_date, income_account_id, donor_id, amount, method, unit_id, purpose, memo, created_by) VALUES (?,?,?,?,?,?,?,?,?,?)',
                [$batches[$key], $x['date'], $x['acct'], $donor, $x['amount'], $x['method'], $x['unit'], $x['purpose'], $x['memo'], $a['access']['id']]);
            $x['added'] = true;
            $added++;
        }
        unset($x);
        return ['added' => $added, 'rows' => $res];
    });
    audit('헌금 내역 가져오기', $res['added'] . '건', $a);
    json_out(['added' => $res['added'], 'rows' => array_map(fn($x) => ['ok' => $x['ok'], 'error' => $x['error'], 'dup' => $x['dup'], 'added' => $x['added'] ?? false], $res['rows'])]);
});

/** 가져온 헌금 묶음 현황과 한꺼번에 마감 */
route('GET', '/import/offerings/pending', function () {
    require_role('재정 담당');
    $r = rows('SELECT b.id, b.batch_date, (SELECT COUNT(*) FROM ' . t('offerings') . ' o WHERE o.batch_id = b.id) AS n, (SELECT COALESCE(SUM(amount),0) FROM ' . t('offerings') . ' o WHERE o.batch_id = b.id) AS s FROM ' . t('offering_batches') . " b WHERE b.counters = ? AND b.status = '입력 중' ORDER BY b.batch_date", [FM_IMPORT_TAG]);
    json_out(['items' => array_map(fn($x) => ['id' => (int)$x['id'], 'date' => $x['batch_date'], 'count' => (int)$x['n'], 'total' => (int)$x['s']], $r)]);
});

route('POST', '/import/offerings/close', function () {
    $a = require_role('재정 담당');
    $b = body();
    $cash = isset($b['cashWalletId']) ? (int)$b['cashWalletId'] : 0;
    $bank = isset($b['bankWalletId']) ? (int)$b['bankWalletId'] : 0;
    $ids = array_map('intval', array_column(rows('SELECT id FROM ' . t('offering_batches') . " WHERE counters = ? AND status = '입력 중' ORDER BY batch_date", [FM_IMPORT_TAG]), 'id'));
    if (!$ids) json_fail('empty', '마감할 가져온 묶음이 없습니다.', 409);
    tx(function () use ($ids, $a, $cash, $bank) {
        foreach ($ids as $id) close_batch_in_tx($id, $a, $cash, $bank, '가져온 자료');
    });
    audit('가져온 헌금 마감', count($ids) . '개 묶음', $a);
    json_out(['closed' => count($ids)]);
});

// ---------- 예산 ----------
function imp_check_budget(int $year, string $type, array $in, bool $createUnits): array
{
    $units = array_column(rows('SELECT id, name, kind FROM ' . t('units') . ' WHERE fiscal_year = ?', [$year]), null, 'name');
    $accts = [];
    foreach (rows('SELECT id, name FROM ' . t('income_accounts') . ' WHERE is_active = 1 AND id NOT IN (SELECT DISTINCT parent_id FROM ' . t('income_accounts') . ' WHERE parent_id IS NOT NULL)') as $a) $accts[$a['name']] = (int)$a['id'];
    $seen = [];
    $out = [];
    foreach ($in as $n => $r) {
        $e = fn(string $m) => ['ok' => false, 'error' => $m, 'action' => ''];
        $amount = imp_int($r['amount'] ?? null, 0, 9_000_000_000_000);
        if ($amount === null) { $out[$n] = $e('금액이 올바르지 않습니다.'); continue; }
        if ($type === 'income') {
            $an = imp_str($r, 'account', 40);
            $id = $accts[$an] ?? $accts[preg_replace('/헌금$/u', '', $an)] ?? $accts[$an . '헌금'] ?? null;
            if (!$id) { $out[$n] = $e("수입 항목 '$an'을(를) 찾지 못했습니다. (세부 항목 이름)"); continue; }
            $out[$n] = ['ok' => true, 'error' => null, 'action' => '수입 예산 입력', 'acct' => $id, 'amount' => $amount];
            continue;
        }
        $un = imp_str($r, 'unit', 60);
        $item = imp_str($r, 'item', 60);
        if ($un === '' || $item === '') { $out[$n] = $e('부서(재정 단위)와 항목 이름이 필요합니다.'); continue; }
        $u = $units[$un] ?? null;
        $newUnit = false;
        if (!$u) {
            if (!$createUnits) { $out[$n] = $e("재정 단위 '$un'을(를) 찾지 못했습니다. 재정 단위 이름과 같게 적거나 '없는 부서는 새로 만들기'를 켜 주세요."); continue; }
            $newUnit = true;
        }
        $dupKey = $un . '|' . $item;
        if (isset($seen[$dupKey])) { $out[$n] = $e('같은 부서·항목이 위에 또 있습니다.'); continue; }
        $seen[$dupKey] = true;
        $exists = $u ? val('SELECT id FROM ' . t('budget_items') . ' WHERE unit_id = ? AND name = ? AND is_active = 1', [$u['id'], $item]) : false;
        $out[$n] = ['ok' => true, 'error' => null, 'action' => $newUnit ? '부서 새로 만들고 항목 추가' : ($exists ? '금액 바꿈' : '항목 추가'), 'unit' => $u ? (int)$u['id'] : null, 'unitName' => $un, 'item' => $item, 'exists' => $exists ? (int)$exists : null, 'amount' => $amount, 'basis' => imp_str($r, 'basis', 255) ?: null, 'nature' => imp_str($r, 'nature', 20) ?: null];
    }
    return $out;
}

route('POST', '/import/budget/preview', function () {
    require_role('재정 담당');
    $b = body();
    $year = in_int($b, 'year', 2000, 2100, '연도');
    budget_edit_guard($year);
    $res = imp_check_budget($year, in_enum($b, 'type', ['expense', 'income'], '종류'), import_rows(), !empty($b['createUnits']));
    json_out(['rows' => array_map(fn($x) => ['ok' => $x['ok'], 'error' => $x['error'], 'action' => $x['action']], $res)]);
});

route('POST', '/import/budget/commit', function () {
    $a = require_role('재정 담당');
    $b = body();
    $year = in_int($b, 'year', 2000, 2100, '연도');
    budget_edit_guard($year);
    $type = in_enum($b, 'type', ['expense', 'income'], '종류');
    $n = tx(function () use ($year, $type, $b) {
        $res = imp_check_budget($year, $type, import_rows(), !empty($b['createUnits']));
        $done = 0;
        $newUnits = [];
        foreach ($res as $x) {
            if (!$x['ok']) continue;
            if ($type === 'income') {
                q('INSERT INTO ' . t('income_budgets') . ' (fiscal_year, income_account_id, amount) VALUES (?,?,?) ON DUPLICATE KEY UPDATE amount = VALUES(amount)', [$year, $x['acct'], $x['amount']]);
            } else {
                $uid = $x['unit'];
                if (!$uid) {
                    $uid = $newUnits[$x['unitName']] ??= insert_id('INSERT INTO ' . t('units') . ' (fiscal_year, kind, name, sort_order) VALUES (?,?,?,?)', [$year, '부서', $x['unitName'], (int)val('SELECT COALESCE(MAX(sort_order),0)+1 FROM ' . t('units') . ' WHERE fiscal_year = ? AND sort_order < 9000', [$year])]);
                }
                if ($x['exists']) q('UPDATE ' . t('budget_items') . ' SET amount = ?, basis = COALESCE(?, basis), nature = COALESCE(?, nature) WHERE id = ?', [$x['amount'], $x['basis'], $x['nature'], $x['exists']]);
                else q('INSERT INTO ' . t('budget_items') . ' (unit_id, name, amount, basis, nature, sort_order) VALUES (?,?,?,?,?,?)', [$uid, $x['item'], $x['amount'], $x['basis'], $x['nature'], (int)val('SELECT COALESCE(MAX(sort_order),0)+1 FROM ' . t('budget_items') . ' WHERE unit_id = ?', [$uid])]);
            }
            $done++;
        }
        return ['done' => $done, 'rows' => $res];
    });
    change_log('import', 'budget', $type, null, $year . '년 ' . $n['done'] . '줄');
    json_out(['done' => $n['done'], 'rows' => array_map(fn($x) => ['ok' => $x['ok'], 'error' => $x['error'], 'action' => $x['action']], $n['rows'])]);
});
