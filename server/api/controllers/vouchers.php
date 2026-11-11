<?php
declare(strict_types=1);

// 지출결의서와 전자결재: 기안 → 상신(결재선을 사람 이름과 함께 고정) → 결재/반려 → 지급 처리(장부에 지출).
// 상신 뒤 내용은 고치지 못하고, 결재할 때마다 그 순간 내용의 해시를 남긴다.

const FM_PAY_METHODS = ['계좌이체', '먼저 쓰고 청구', '현금', '법인카드'];
const FM_STEP_ROLES = ['부서장', '위원장', '재정위원장', '담임목사'];
const FM_FILE_MAX = 80000; // 호스팅의 요청 크기 제한(128KB)을 base64(약 1.33배)로 보내도 넘지 않게. 사진은 화면에서 줄여 올린다

// ---------- 결재 규칙(관리자) ----------
function rules_payload(): array
{
    return array_map(fn($r) => ['id' => (int)$r['id'], 'kind' => $r['kind'], 'name' => $r['name'], 'minAmount' => (int)$r['min_amount'], 'maxAmount' => $r['max_amount'] === null ? null : (int)$r['max_amount'],
        'steps' => json_decode((string)$r['steps'], true), 'addOn' => json_decode((string)$r['add_on'], true) ?: new stdClass()], rows('SELECT * FROM ' . t('approval_rules') . ' ORDER BY kind, min_amount, id'));
}

route('GET', '/approval-rules', function () {
    require_account();
    json_out(['items' => rules_payload(), 'roles' => FM_STEP_ROLES]);
});

/** 금액 구간표를 통째로 저장한다. 구간은 0원부터 빈틈·겹침 없이 이어져야 하고 마지막은 위로 끝없어야 한다. */
route('POST', '/approval-rules', function () {
    require_admin();
    require_reconfirm();
    $items = body()['items'] ?? null;
    if (!is_array($items) || !$items) json_fail('invalid', '구간이 하나 이상 있어야 합니다.', 422);
    $prevMax = -1;
    $clean = [];
    foreach (array_values($items) as $n => $it) {
        if (!is_array($it)) json_fail('invalid', '구간 형식이 올바르지 않습니다.', 422);
        $min = in_int($it, 'minAmount', 0, 9_000_000_000_000, '시작 금액');
        $max = isset($it['maxAmount']) && $it['maxAmount'] !== null && $it['maxAmount'] !== '' ? in_int($it, 'maxAmount', 0, 9_000_000_000_000, '끝 금액') : null;
        if ($min !== $prevMax + 1) json_fail('invalid', ($n + 1) . '번째 구간은 ' . number_format($prevMax + 1) . '원부터 시작해야 합니다. (빈틈·겹침 없이)', 422);
        if ($max === null && $n !== count($items) - 1) json_fail('invalid', '끝 금액이 없는 구간은 마지막이어야 합니다.', 422);
        if ($max !== null && $max < $min) json_fail('invalid', ($n + 1) . '번째 구간의 끝이 시작보다 작습니다.', 422);
        if ($n === count($items) - 1 && $max !== null) json_fail('invalid', '마지막 구간은 위로 끝이 없어야 합니다. (끝 금액 비우기)', 422);
        $steps = [];
        foreach (is_array($it['steps'] ?? null) ? $it['steps'] : [] as $s) {
            $role = (string)($s['role'] ?? '');
            $mode = (string)($s['mode'] ?? '결재');
            if (!in_array($role, FM_STEP_ROLES, true) || !in_array($mode, ['결재', '확인'], true)) json_fail('invalid', '결재 단계가 올바르지 않습니다.', 422);
            $steps[] = ['role' => $role, 'mode' => $mode];
        }
        if (!array_filter($steps, fn($s) => $s['mode'] === '결재')) json_fail('invalid', ($n + 1) . '번째 구간에는 결재 단계가 하나 이상 있어야 합니다.', 422);
        $add = [];
        foreach (['over_budget', 'reserve'] as $k) {
            $v = (string)($it['addOn'][$k] ?? '');
            if ($v !== '' && !in_array($v, FM_STEP_ROLES, true)) json_fail('invalid', '추가 단계가 올바르지 않습니다.', 422);
            if ($v !== '') $add[$k] = $v;
        }
        $clean[] = [in_str($it, 'name', 30, true, '구간 이름'), $min, $max, json_encode($steps, JSON_UNESCAPED_UNICODE), json_encode($add ?: new stdClass(), JSON_UNESCAPED_UNICODE), $n + 1];
        $prevMax = $max ?? $min;
    }
    $old = rules_payload();
    tx(function () use ($clean) {
        q('DELETE FROM ' . t('approval_rules') . " WHERE kind = '일반'");
        foreach ($clean as $c) q('INSERT INTO ' . t('approval_rules') . " (kind, name, min_amount, max_amount, steps, add_on, sort_order) VALUES ('일반',?,?,?,?,?,?)", $c);
    });
    change_log('approval_rules', 'general', 'rules', $old, rules_payload());
    audit('결재 규칙 변경');
    json_out(null);
});

// ---------- 대리 결재 ----------
route('GET', '/delegations', function () {
    require_admin();
    json_out(['items' => array_map(fn($d) => ['id' => (int)$d['id'], 'from' => $d['from_name'], 'to' => $d['to_name'], 'startsOn' => $d['starts_on'], 'endsOn' => $d['ends_on']], rows('SELECT * FROM ' . t('delegations') . ' ORDER BY ends_on DESC, id DESC LIMIT 100'))]);
});
route('POST', '/delegations', function () {
    $a = require_admin();
    $b = body();
    $from = cm_person(in_str($b, 'fromPersonId', 36, true, '맡기는 분'));
    $to = cm_person(in_str($b, 'toPersonId', 36, true, '대신 결재할 분'));
    if ($from['id'] === $to['id']) json_fail('invalid', '본인에게 맡길 수 없습니다.', 422);
    $s = in_date($b, 'startsOn', '시작일');
    $e = in_date($b, 'endsOn', '종료일');
    if ($e < $s) json_fail('invalid', '종료일이 시작일보다 빠릅니다.', 422);
    $id = insert_id('INSERT INTO ' . t('delegations') . ' (from_person_id, from_name, to_person_id, to_name, starts_on, ends_on, created_by) VALUES (?,?,?,?,?,?,?)', [$from['id'], $from['name'], $to['id'], $to['name'], $s, $e, $a['access']['id']]);
    change_log('delegation', $id, 'create', null, $from['name'] . ' → ' . $to['name'] . " $s~$e");
    json_out(['id' => $id]);
});
route('POST', '/delegations/{id}/delete', function (int $id) {
    require_admin();
    q('DELETE FROM ' . t('delegations') . ' WHERE id = ?', [$id]);
    change_log('delegation', $id, 'delete', null, null);
    json_out(null);
});

// ---------- 결재선 만들기 ----------
function finance_chief(string $role, ?string $notPerson = null): ?array
{
    $r = row('SELECT a.person_id, a.name FROM ' . t('access') . ' a JOIN ' . t('access_roles') . " r ON r.access_id = a.id WHERE r.role = ? AND a.status = '사용' AND a.person_id IS NOT NULL AND a.person_id <> ? ORDER BY a.id LIMIT 1", [$role, $notPerson ?? '']);
    return $r ? ['person_id' => $r['person_id'], 'name' => $r['name']] : null;
}

function unit_person(int $unitId, string $role): ?array
{
    $r = row('SELECT person_id, person_name FROM ' . t('unit_roles') . ' WHERE unit_id = ? AND role = ? ORDER BY sort_order, id LIMIT 1', [$unitId, $role]);
    return $r ? ['person_id' => $r['person_id'], 'name' => $r['person_name']] : null;
}

/** 이 결의서 합계의 예산 초과 여부 (항목별 남은 금액 < 이번 결의서의 그 항목 합계) */
function over_budget(array $v, array $items): bool
{
    $st = item_stats((int)$v['fiscal_year']);
    $per = [];
    foreach ($items as $i) $per[(int)$i['budget_item_id']] = ($per[(int)$i['budget_item_id']] ?? 0) + (int)$i['amount'];
    foreach ($per as $id => $sum) {
        $init = (int)val('SELECT amount FROM ' . t('budget_items') . ' WHERE id = ?', [$id]);
        $remain = $init + ($st['changes'][$id] ?? 0) - ($st['paid'][$id] ?? 0) - ($st['pending'][$id] ?? 0);
        if ($remain < $sum) return true;
    }
    return false;
}

/** 결재선을 계산한다. 오류가 있으면 error 에 사람이 읽을 이유를 담는다. (상신할 때와 기안 화면 미리보기가 같이 쓴다.) */
function build_steps(array $v, array $items): array
{
    $total = (int)$v['total'];
    $rule = row('SELECT * FROM ' . t('approval_rules') . " WHERE kind = '일반' AND min_amount <= ? AND (max_amount IS NULL OR max_amount >= ?) ORDER BY min_amount DESC LIMIT 1", [$total, $total]);
    if (!$rule) return ['error' => '이 금액에 맞는 결재 규칙이 없습니다. 재정 관리자에게 문의해 주세요.', 'steps' => [], 'rule' => null];
    $unit = row('SELECT * FROM ' . t('units') . ' WHERE id = ?', [$v['unit_id']]);
    $steps = json_decode((string)$rule['steps'], true) ?: [];
    $add = json_decode((string)$rule['add_on'], true) ?: [];
    $have = array_column($steps, 'role');
    if (!empty($add['over_budget']) && !in_array($add['over_budget'], $have, true) && over_budget($v, $items)) { $steps[] = ['role' => $add['over_budget'], 'mode' => '결재']; $have[] = $add['over_budget']; }
    if (!empty($add['reserve']) && $unit['kind'] === '예비비' && !in_array($add['reserve'], $have, true)) $steps[] = ['role' => $add['reserve'], 'mode' => '결재'];

    // 기안자 본인이 재정위원장이면 그 단계를 담임목사가, 담임목사이면 그 단계를 재정위원장이 맡는다 (자기 결의서를 자기가 결재하지 않게)
    $dRoles = array_column(rows('SELECT r.role FROM ' . t('access_roles') . ' r JOIN ' . t('access') . " a ON a.id = r.access_id WHERE a.person_id = ? AND a.status = '사용'", [$v['drafter_person_id']]), 'role');
    foreach ($steps as &$st) {
        if ($st['role'] === '재정위원장' && in_array('재정위원장', $dRoles, true)) $st['role'] = '담임목사';
        elseif ($st['role'] === '담임목사' && in_array('담임목사', $dRoles, true)) $st['role'] = '재정위원장';
    }
    unset($st);
    $out = [];
    foreach ($steps as $s) {
        $role = $s['role'];
        $p = null;
        if ($role === '부서장') {
            if ($unit['kind'] !== '부서') continue; // 위원회 직속·기타 지출은 부서장 단계가 없다
            $p = unit_person((int)$unit['id'], '부서장');
        } elseif ($role === '위원장') {
            $cid = $unit['kind'] === '위원회' ? (int)$unit['id'] : ($unit['kind'] === '부서' && $unit['parent_id'] ? (int)$unit['parent_id'] : 0);
            if (!$cid) continue; // 위원회에 속하지 않는 단위(기타·예비비)는 위원장 단계가 없다
            $p = unit_person($cid, '위원장');
        } else {
            $p = finance_chief($role, $v['drafter_person_id']);
        }
        if (!$p) return ['error' => ($role === '부서장' || $role === '위원장' ? $unit['name'] . '의 ' : '') . $role . ' 담당자가 지정되지 않았습니다. ' . ($role === '재정위원장' || $role === '담임목사' ? '재정 사용 승인 화면에서 해당 역할을 가진 사람을 지정해 주세요.' : '재정 단위 화면에서 지정해 주세요.'), 'steps' => [], 'rule' => (int)$rule['id']];
        $out[] = ['role' => $role, 'person_id' => $p['person_id'], 'name' => $p['name'], 'mode' => $s['mode'], 'status' => '대기', 'comment' => null];
    }
    // 같은 사람이 연달아 두 단계면 한 번 결재로 처리하고 역할 이름을 합친다
    $merged = [];
    foreach ($out as $s) {
        $last = count($merged) - 1;
        if ($last >= 0 && $merged[$last]['person_id'] === $s['person_id'] && $merged[$last]['mode'] === '결재' && $s['mode'] === '결재') $merged[$last]['role'] .= '·' . $s['role'];
        else $merged[] = $s;
    }
    // 기안자 본인 단계는 넘어가되, 기안자가 아닌 사람의 결재가 최소 한 번은 있어야 한다
    $real = 0;
    foreach ($merged as &$s) {
        if ($s['person_id'] === $v['drafter_person_id'] && $s['mode'] === '결재') { $s['status'] = '건너뜀'; $s['comment'] = '기안자 본인'; }
        elseif ($s['mode'] === '결재') $real++;
    }
    unset($s);
    if ($real === 0) { // 막는 결재가 하나도 없으면(예: 위원회에 속하지 않는 소액 지출) 사후 확인 단계를 결재로 올린다
        foreach ($merged as &$s) if ($s['mode'] === '확인' && $s['person_id'] !== $v['drafter_person_id']) { $s['mode'] = '결재'; $real = 1; break; }
        unset($s);
    }
    if ($real === 0) return ['error' => '기안자 본인 외에 결재할 사람이 없습니다. 결재 담당자 지정을 확인해 주세요.', 'steps' => $merged, 'rule' => (int)$rule['id']];
    return ['error' => null, 'steps' => $merged, 'rule' => (int)$rule['id'], 'ruleName' => $rule['name']];
}

// ---------- 접근 범위 ----------
function voucher_row(int $id): array
{
    $v = row('SELECT * FROM ' . t('vouchers') . ' WHERE id = ?', [$id]);
    if (!$v) json_fail('not_found', '결의서를 찾을 수 없습니다.', 404);
    return $v;
}

function can_see_voucher(array $a, array $v): bool
{
    if (has_role($a, '재정 담당', '재정위원장', '담임목사', '감사')) return true;
    if ((int)$v['drafter_access_id'] === (int)($a['access']['id'] ?? 0)) return true;
    $pid = $a['cm']['person_id'] ?? null;
    if (!$pid) return false;
    if (val('SELECT COUNT(*) FROM ' . t('approval_steps') . ' WHERE voucher_id = ? AND approver_person_id = ?', [$v['id'], $pid])) return true;
    return in_array((int)$v['unit_id'], my_unit_ids($a, (int)$v['fiscal_year']), true);
}

function require_voucher_view(int $id): array
{
    $a = require_account();
    $v = voucher_row($id);
    if (!can_see_voucher($a, $v)) json_fail('forbidden', '이 결의서를 볼 권한이 없습니다.', 403);
    return [$a, $v];
}

/** 내가 이 결의서를 기안할 수 있는가: 그 단위의 회계·부서장·위원장, 또는 재정 담당 */
function can_draft_for_unit(array $a, int $unitId, int $year): bool
{
    return has_role($a, '재정 담당') || in_array($unitId, my_unit_ids($a, $year), true);
}

function voucher_items(int $id): array
{
    return rows('SELECT vi.id, vi.budget_item_id, vi.summary, vi.amount, bi.name AS item FROM ' . t('voucher_items') . ' vi JOIN ' . t('budget_items') . ' bi ON bi.id = vi.budget_item_id WHERE vi.voucher_id = ? ORDER BY vi.id', [$id]);
}

function content_hash(array $v, array $items): string
{
    return hash('sha256', json_encode([
        'id' => (int)$v['id'], 'round' => (int)$v['round'], 'unit' => (int)$v['unit_id'], 'title' => $v['title'], 'reason' => $v['reason'], 'total' => (int)$v['total'], 'pay' => [$v['pay_method'], $v['pay_bank'], $v['pay_account'], $v['pay_holder']],
        'items' => array_map(fn($i) => [(int)$i['budget_item_id'], $i['summary'], (int)$i['amount']], $items),
    ], JSON_UNESCAPED_UNICODE));
}

/** 현재 결재할 차례(결재 모드의 가장 앞 대기 단계) */
function current_step(array $v): ?array
{
    if ($v['status'] !== '결재 중') return null;
    return row('SELECT * FROM ' . t('approval_steps') . " WHERE voucher_id = ? AND round = ? AND status = '대기' AND mode = '결재' ORDER BY step_no LIMIT 1", [$v['id'], $v['round']]);
}

/** 이 단계를 내가 결재할 수 있는가: 본인이거나, 기간 안의 대리 결재 지정을 받은 경우(대결) */
function step_actor(array $a, array $step): ?string
{
    $pid = $a['cm']['person_id'] ?? null;
    if (!$pid || !$step['approver_person_id']) return null;
    if ($step['approver_person_id'] === $pid) return 'self';
    foreach (['재정위원장', '담임목사'] as $r) {
        if (str_contains((string)$step['role'], $r) && has_role($a, $r) && !is_drafter_of($step, $pid)) return 'self'; // 같은 역할을 가진 다른 분도 결재할 수 있다
    }
    if (val('SELECT COUNT(*) FROM ' . t('delegations') . ' WHERE from_person_id = ? AND to_person_id = ? AND CURDATE() BETWEEN starts_on AND ends_on', [$step['approver_person_id'], $pid])) return 'delegate';
    return null;
}

/** 이 단계의 결의서를 내가 기안했는가 (자기 결의서는 결재할 수 없다) */
function is_drafter_of(array $step, string $pid): bool
{
    return (string)val('SELECT drafter_person_id FROM ' . t('vouchers') . ' WHERE id = ?', [$step['voucher_id']]) === $pid;
}

function voucher_detail(array $a, array $v): array
{
    $items = voucher_items((int)$v['id']);
    $steps = rows('SELECT * FROM ' . t('approval_steps') . ' WHERE voucher_id = ? ORDER BY round, step_no', [$v['id']]);
    $files = rows('SELECT id, mime, size, orig_name FROM ' . t('voucher_files') . ' WHERE voucher_id = ? ORDER BY id', [$v['id']]);
    $cur = current_step($v);
    $mine = null;
    foreach ($steps as $s) if ((int)$s['round'] === (int)$v['round'] && $s['mode'] === '확인' && $s['status'] === '대기' && step_actor($a, $s)) $mine = $s;
    $drafter = (int)$v['drafter_access_id'] === (int)($a['access']['id'] ?? 0);
    $editable = in_array($v['status'], ['작성 중', '반려', '회수'], true) && ($drafter || has_role($a, '재정 담당'));
    $unit = row('SELECT name, kind FROM ' . t('units') . ' WHERE id = ?', [$v['unit_id']]);
    $acted = (int)val('SELECT COUNT(*) FROM ' . t('approval_steps') . " WHERE voucher_id = ? AND round = ? AND status = '완료'", [$v['id'], $v['round']]);
    return [
        'id' => (int)$v['id'], 'docNo' => $v['doc_no'], 'year' => (int)$v['fiscal_year'], 'unitId' => (int)$v['unit_id'], 'unit' => $unit['name'], 'drafter' => $v['drafter_name'], 'title' => $v['title'], 'reason' => $v['reason'],
        'total' => (int)$v['total'], 'payMethod' => $v['pay_method'], 'payBank' => $v['pay_bank'], 'payAccount' => $v['pay_account'], 'payHolder' => $v['pay_holder'], 'desiredDate' => $v['desired_date'], 'noProofReason' => $v['no_proof_reason'],
        'status' => $v['status'], 'rev' => (int)$v['rev'], 'round' => (int)$v['round'], 'submittedAt' => $v['submitted_at'], 'approvedAt' => $v['approved_at'], 'paidAt' => $v['paid_at'],
        'items' => array_map(fn($i) => ['id' => (int)$i['id'], 'budgetItemId' => (int)$i['budget_item_id'], 'item' => $i['item'], 'summary' => $i['summary'], 'amount' => (int)$i['amount']], $items),
        'files' => array_map(fn($f) => ['id' => (int)$f['id'], 'mime' => $f['mime'], 'size' => (int)$f['size'], 'name' => $f['orig_name']], $files),
        'steps' => array_map(fn($s) => ['round' => (int)$s['round'], 'no' => (int)$s['step_no'], 'role' => $s['role'], 'name' => $s['approver_name'], 'mode' => $s['mode'], 'status' => $s['status'], 'actedAt' => $s['acted_at'], 'delegate' => (int)$s['as_delegate'] === 1, 'comment' => $s['comment'], 'hash' => $s['content_hash'] ? substr($s['content_hash'], 0, 12) : null], $steps),
        'can' => [
            'edit' => $editable, 'submit' => $editable, 'recall' => $v['status'] === '결재 중' && $drafter && $acted === 0, 'cancel' => $editable,
            'approve' => $cur !== null && step_actor($a, $cur) !== null, 'confirm' => $mine !== null, 'pay' => $v['status'] === '결재 완료' && has_role($a, '재정 담당'),
        ],
    ];
}

// ---------- 목록 ----------
route('GET', '/vouchers', function () {
    $a = require_account();
    $year = in_int($_GET, 'year', 2000, 2100, '연도');
    $all = has_role($a, '재정 담당', '재정위원장', '담임목사', '감사');
    $where = 'v.fiscal_year = ?';
    $p = [$year];
    if (!$all) {
        $mine = my_unit_ids($a, $year);
        $pid = $a['cm']['person_id'] ?? '';
        $or = ['v.drafter_access_id = ?', 'v.id IN (SELECT voucher_id FROM ' . t('approval_steps') . ' WHERE approver_person_id = ?)'];
        $p[] = (int)$a['access']['id'];
        $p[] = $pid;
        if ($mine) { $or[] = 'v.unit_id IN (' . marks($mine) . ')'; $p = array_merge($p, $mine); }
        $where .= ' AND (' . implode(' OR ', $or) . ')';
    }
    if (!empty($_GET['status']) && in_array($_GET['status'], ['작성 중', '결재 중', '결재 완료', '지급 완료', '반려', '회수', '취소'], true)) { $where .= ' AND v.status = ?'; $p[] = $_GET['status']; }
    $r = rows('SELECT v.id, v.doc_no, v.title, v.total, v.status, v.drafter_name, v.submitted_at, v.updated_at, u.name AS unit FROM ' . t('vouchers') . ' v JOIN ' . t('units') . " u ON u.id = v.unit_id WHERE $where ORDER BY v.id DESC LIMIT 300", $p);
    json_out(['items' => array_map(fn($x) => ['id' => (int)$x['id'], 'docNo' => $x['doc_no'], 'title' => $x['title'], 'total' => (int)$x['total'], 'status' => $x['status'], 'drafter' => $x['drafter_name'], 'unit' => $x['unit'], 'at' => substr((string)($x['submitted_at'] ?? $x['updated_at']), 0, 10)], $r)]);
});

/** 내 결재함: 내가 결재할 차례인 것(본인·대결), 사후 확인할 것 */
function inbox_cards(array $a): array
{
    $pid = $a['cm']['person_id'] ?? null;
    $wait = [];
    $confirm = [];
    if ($pid) {
        // 내 단계이거나, 지금 기간 안에 나에게 대결을 맡긴 결재자의 단계가 있는 문서만 (건수에 상관없이 빠지지 않는다)
        $pids = array_merge([$pid], array_column(rows('SELECT from_person_id FROM ' . t('delegations') . ' WHERE to_person_id = ? AND CURDATE() BETWEEN starts_on AND ends_on', [$pid]), 'from_person_id'));
        $roleIn = [];
        foreach (['재정위원장', '담임목사'] as $r) if (has_role($a, $r)) $roleIn[] = '%' . $r . '%';
        $cond = 'approver_person_id IN (' . marks($pids) . ')';
        $params = $pids;
        foreach ($roleIn as $like) { $cond .= ' OR role LIKE ?'; $params[] = $like; }
        $cands = rows('SELECT v.* FROM ' . t('vouchers') . " v WHERE v.status IN ('결재 중','결재 완료','지급 완료') AND v.id IN (SELECT voucher_id FROM " . t('approval_steps') . " WHERE status = '대기' AND ($cond)) ORDER BY v.id", $params);
        foreach ($cands as $v) {
            $cur = current_step($v);
            $u = row('SELECT name FROM ' . t('units') . ' WHERE id = ?', [$v['unit_id']]);
            $card = ['id' => (int)$v['id'], 'docNo' => $v['doc_no'], 'title' => $v['title'], 'total' => (int)$v['total'], 'drafter' => $v['drafter_name'], 'unit' => $u['name'], 'at' => substr((string)$v['submitted_at'], 0, 10)];
            if ($cur && step_actor($a, $cur)) $wait[] = $card + ['role' => $cur['role'], 'delegate' => step_actor($a, $cur) === 'delegate'];
            foreach (rows('SELECT * FROM ' . t('approval_steps') . " WHERE voucher_id = ? AND round = ? AND mode = '확인' AND status = '대기'", [$v['id'], $v['round']]) as $s) if (step_actor($a, $s)) $confirm[] = $card + ['role' => $s['role']];
        }
    }
    return ['waiting' => $wait, 'confirm' => $confirm];
}

route('GET', '/approvals/inbox', function () {
    json_out(inbox_cards(require_account()));
});

route('GET', '/vouchers/{id}', function (int $id) {
    [$a, $v] = require_voucher_view($id);
    $d = voucher_detail($a, $v);
    // 기안 화면에서 결재선 미리보기 · 예산 남은 금액
    if ($d['can']['submit']) {
        $items = voucher_items($id);
        $b = build_steps($v, $items);
        $d['preview'] = ['error' => $b['error'], 'rule' => $b['ruleName'] ?? null, 'steps' => array_map(fn($s) => ['role' => $s['role'], 'name' => $s['name'], 'mode' => $s['mode'], 'skip' => $s['status'] === '건너뜀'], $b['steps'])];
        $d['overBudget'] = $items ? over_budget($v, $items) : false;
    }
    json_out($d);
});

// ---------- 기안 ----------
function voucher_fields(array $b, array $a, ?array $cur): array
{
    $unitId = $cur ? (int)$cur['unit_id'] : in_int($b, 'unitId', 1, PHP_INT_MAX, '재정 단위');
    $unit = row('SELECT * FROM ' . t('units') . ' WHERE id = ?', [$unitId]);
    if (!$unit) json_fail('invalid', '재정 단위를 찾을 수 없습니다.', 422);
    $year = (int)$unit['fiscal_year'];
    if (!can_draft_for_unit($a, $unitId, $year)) json_fail('forbidden', '이 재정 단위의 결의서를 쓸 권한이 없습니다. (그 단위의 회계·부서장·위원장만 쓸 수 있어요.)', 403);
    $st = val('SELECT status FROM ' . t('fiscal_years') . ' WHERE year = ?', [$year]);
    if ($st === '마감') json_fail('closed', $year . '년은 마감되었습니다.', 409);
    $title = in_str($b, 'title', 150, true, '제목');
    $reason = in_str($b, 'reason', 2000);
    $pay = in_enum($b, 'payMethod', FM_PAY_METHODS, '지급 방법');
    $bank = in_str($b, 'payBank', 30);
    $account = in_str($b, 'payAccount', 40);
    $holder = in_str($b, 'payHolder', 50);
    if ($account !== '' && !preg_match('/^[0-9\- ]{4,40}$/', $account)) json_fail('invalid', '계좌번호는 숫자와 - 만 쓸 수 있어요.', 422);
    $desired = isset($b['desiredDate']) && $b['desiredDate'] !== '' ? in_date($b, 'desiredDate', '지급 희망일') : null;
    $items = [];
    foreach (is_array($b['items'] ?? null) ? $b['items'] : [] as $it) {
        $bi = in_int($it, 'budgetItemId', 1, PHP_INT_MAX, '예산 항목');
        if (!val('SELECT COUNT(*) FROM ' . t('budget_items') . ' WHERE id = ? AND unit_id = ? AND is_active = 1', [$bi, $unitId])) json_fail('invalid', '예산 항목이 이 결의서의 재정 단위에 속하지 않습니다.', 422);
        $items[] = [$bi, in_str($it, 'summary', 255, true, '적요'), in_int($it, 'amount', 1, 9_000_000_000_000, '금액')];
    }
    if (!$items) json_fail('invalid', '지출 내역을 한 줄 이상 적어 주세요.', 422);
    return [$unitId, $year, $title, $reason, $pay, $bank, $account, $holder, $desired, in_str($b, 'noProofReason', 255), $items, array_sum(array_column($items, 2))];
}

function save_items(int $vid, array $items): void
{
    q('DELETE FROM ' . t('voucher_items') . ' WHERE voucher_id = ?', [$vid]);
    foreach ($items as [$bi, $sum, $amt]) q('INSERT INTO ' . t('voucher_items') . ' (voucher_id, budget_item_id, summary, amount) VALUES (?,?,?,?)', [$vid, $bi, $sum, $amt]);
}

route('POST', '/vouchers', function () {
    $a = require_account();
    if (!$a['cm']['person_id']) json_fail('nolink', '교인과 연결된 계정만 결의서를 쓸 수 있습니다.', 403);
    [$unitId, $year, $title, $reason, $pay, $bank, $account, $holder, $desired, $noProof, $items, $total] = voucher_fields(body(), $a, null);
    $id = tx(function () use ($a, $unitId, $year, $title, $reason, $pay, $bank, $account, $holder, $desired, $noProof, $items, $total) {
        $id = insert_id(
            'INSERT INTO ' . t('vouchers') . ' (fiscal_year, unit_id, drafter_access_id, drafter_person_id, drafter_name, title, reason, total, pay_method, pay_bank, pay_account, pay_holder, desired_date, no_proof_reason) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [$year, $unitId, $a['access']['id'], $a['cm']['person_id'], $a['cm']['name'], $title, $reason ?: null, $total, $pay, $bank ?: null, $account ?: null, $holder ?: null, $desired, $noProof ?: null]
        );
        save_items($id, $items);
        return $id;
    });
    json_out(['id' => $id]);
});

route('POST', '/vouchers/{id}', function (int $id) {
    $a = require_account();
    $v = voucher_row($id);
    if (!in_array($v['status'], ['작성 중', '반려', '회수'], true)) json_fail('invalid_state', '상신된 결의서는 고칠 수 없습니다. 회수하거나 반려된 뒤에 고칠 수 있어요.', 409);
    if ((int)$v['drafter_access_id'] !== (int)$a['access']['id'] && !has_role($a, '재정 담당')) json_fail('forbidden', '기안자만 고칠 수 있습니다.', 403);
    $b = body();
    if ((int)($b['rev'] ?? -1) !== (int)$v['rev']) json_fail('conflict', '다른 곳에서 먼저 고쳤습니다. 새로고침한 뒤 다시 시도해 주세요.', 409);
    [$unitId, $year, $title, $reason, $pay, $bank, $account, $holder, $desired, $noProof, $items, $total] = voucher_fields($b, $a, $v);
    tx(function () use ($id, $title, $reason, $pay, $bank, $account, $holder, $desired, $noProof, $items, $total) {
        q('UPDATE ' . t('vouchers') . ' SET title=?, reason=?, total=?, pay_method=?, pay_bank=?, pay_account=?, pay_holder=?, desired_date=?, no_proof_reason=?, rev = rev + 1 WHERE id = ?', [$title, $reason ?: null, $total, $pay, $bank ?: null, $account ?: null, $holder ?: null, $desired, $noProof ?: null, $id]);
        save_items($id, $items);
    });
    json_out(null);
});

// ---------- 증빙 파일 ----------
route('POST', '/vouchers/{id}/files', function (int $id) {
    $a = require_account();
    $v = voucher_row($id);
    if (!in_array($v['status'], ['작성 중', '반려', '회수'], true)) json_fail('invalid_state', '상신된 결의서에는 증빙을 더할 수 없습니다.', 409);
    if ((int)$v['drafter_access_id'] !== (int)$a['access']['id'] && !has_role($a, '재정 담당')) json_fail('forbidden', '기안자만 올릴 수 있습니다.', 403);
    if ((int)val('SELECT COUNT(*) FROM ' . t('voucher_files') . ' WHERE voucher_id = ?', [$id]) >= 10) json_fail('too_many', '증빙은 10장까지 올릴 수 있습니다.', 409);
    $b = body();
    $bin = base64_decode((string)($b['data'] ?? ''), true);
    if ($bin === false || $bin === '') json_fail('invalid', '파일 내용을 읽지 못했습니다.', 422);
    if (strlen($bin) > FM_FILE_MAX) json_fail('too_big', '파일이 너무 큽니다. (' . (int)(FM_FILE_MAX / 1000) . 'KB 이하) 사진은 자동으로 줄여서 올라갑니다.', 413);
    $mime = str_starts_with($bin, "\xFF\xD8\xFF") ? 'image/jpeg' : (str_starts_with($bin, "\x89PNG\r\n\x1a\n") ? 'image/png' : (str_starts_with($bin, '%PDF-') ? 'application/pdf' : (str_starts_with($bin, 'RIFF') && substr($bin, 8, 4) === 'WEBP' ? 'image/webp' : '')));
    if ($mime === '') json_fail('invalid', '사진(JPG·PNG·WEBP)이나 PDF만 올릴 수 있습니다.', 422);
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'][$mime];
    $dir = FM_DATA . '/vouchers/' . $id;
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    $name = bin2hex(random_bytes(12)) . '.' . $ext;
    if (@file_put_contents($dir . '/' . $name, $bin) === false) json_fail('server', '파일을 저장하지 못했습니다.', 500);
    $fid = insert_id('INSERT INTO ' . t('voucher_files') . ' (voucher_id, path, mime, size, orig_name, uploaded_by) VALUES (?,?,?,?,?,?)', [$id, $id . '/' . $name, $mime, strlen($bin), in_str($b, 'name', 100) ?: null, $a['access']['id']]);
    json_out(['id' => $fid]);
});

route('GET', '/voucher-files/{id}', function (int $fid) {
    $f = row('SELECT * FROM ' . t('voucher_files') . ' WHERE id = ?', [$fid]);
    if (!$f) json_fail('not_found', '파일을 찾을 수 없습니다.', 404);
    require_voucher_view((int)$f['voucher_id']);
    $path = FM_DATA . '/vouchers/' . $f['path'];
    if (!is_file($path)) json_fail('not_found', '파일을 찾을 수 없습니다.', 404);
    header('Content-Type: ' . $f['mime']);
    header('Content-Length: ' . filesize($path));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=300');
    readfile($path);
    exit;
});

route('POST', '/voucher-files/{id}/delete', function (int $fid) {
    $a = require_account();
    $f = row('SELECT * FROM ' . t('voucher_files') . ' WHERE id = ?', [$fid]);
    if (!$f) json_fail('not_found', '파일을 찾을 수 없습니다.', 404);
    $v = voucher_row((int)$f['voucher_id']);
    if (!in_array($v['status'], ['작성 중', '반려', '회수'], true) || ((int)$v['drafter_access_id'] !== (int)$a['access']['id'] && !has_role($a, '재정 담당'))) json_fail('forbidden', '지울 수 없습니다.', 403);
    @unlink(FM_DATA . '/vouchers/' . $f['path']);
    q('DELETE FROM ' . t('voucher_files') . ' WHERE id = ?', [$fid]);
    json_out(null);
});

// ---------- 상신·결재·반려·회수·취소·지급 ----------
function next_doc_no(int $year): string
{
    $k = 'voucher_seq_' . $year;
    q("INSERT INTO " . t('meta') . " (k, v) VALUES (?, '0') ON DUPLICATE KEY UPDATE v = v", [$k]);
    $n = (int)val('SELECT v FROM ' . t('meta') . ' WHERE k = ? FOR UPDATE', [$k]) + 1;
    q('UPDATE ' . t('meta') . ' SET v = ? WHERE k = ?', [(string)$n, $k]);
    return sprintf('%d-%04d', $year, $n);
}

function check_rev(array $v): void
{
    if ((int)(body()['rev'] ?? -1) !== (int)$v['rev']) json_fail('conflict', '다른 곳에서 먼저 처리했습니다. 새로고침한 뒤 다시 확인해 주세요.', 409);
}

route('POST', '/vouchers/{id}/submit', function (int $id) {
    $a = require_account();
    tx(function () use ($id, $a) {
        $v = row('SELECT * FROM ' . t('vouchers') . ' WHERE id = ? FOR UPDATE', [$id]);
        if (!$v) json_fail('not_found', '결의서를 찾을 수 없습니다.', 404);
        if (!in_array($v['status'], ['작성 중', '반려', '회수'], true)) json_fail('invalid_state', '이미 상신되었습니다.', 409);
        if ((int)$v['drafter_access_id'] !== (int)$a['access']['id'] && !has_role($a, '재정 담당')) json_fail('forbidden', '기안자만 상신할 수 있습니다.', 403);
        check_rev($v);
        $items = voucher_items($id);
        if (!$items) json_fail('invalid', '지출 내역이 없습니다.', 422);
        if (in_array($v['pay_method'], ['계좌이체', '먼저 쓰고 청구'], true) && (!$v['pay_bank'] || !$v['pay_account'] || !$v['pay_holder'])) json_fail('invalid', '받을 분의 은행·계좌번호·예금주를 적어 주세요.', 422);
        $files = (int)val('SELECT COUNT(*) FROM ' . t('voucher_files') . ' WHERE voucher_id = ?', [$id]);
        if (!$files && !$v['no_proof_reason']) json_fail('proof', '증빙(영수증·견적서)을 올리거나, 증빙이 없는 사유를 적어 주세요.', 422);
        $b = build_steps($v, $items);
        if ($b['error']) json_fail('steps', $b['error'], 409);
        $round = $v['status'] === '작성 중' ? (int)$v['round'] : (int)$v['round'] + 1;
        $docNo = $v['doc_no'] ?: next_doc_no((int)$v['fiscal_year']);
        foreach ($b['steps'] as $n => $s) {
            q('INSERT INTO ' . t('approval_steps') . ' (voucher_id, round, step_no, role, approver_person_id, approver_name, mode, status, comment) VALUES (?,?,?,?,?,?,?,?,?)', [$id, $round, $n + 1, $s['role'], $s['person_id'], $s['name'], $s['mode'], $s['status'], $s['comment']]);
        }
        q('UPDATE ' . t('vouchers') . " SET status='결재 중', round=?, doc_no=?, rule_id=?, submitted_at=NOW(), rev = rev + 1 WHERE id=?", [$round, $docNo, $b['rule'], $id]);
        audit('결의서 상신', $docNo, $a);
    });
    json_out(null);
});

function verify_password(array $a): void
{
    $pw = (string)(body()['password'] ?? '');
    if ($pw === '' || strlen($pw) > 200) json_fail('invalid', '결재하려면 비밀번호를 입력해 주세요.', 422);
    $loginId = $a['cm']['login_id'];
    if (login_throttled($loginId)) json_fail('throttled', '여러 번 틀려서 잠시 막았습니다. 15분 뒤에 다시 시도해 주세요.', 429);
    if (!password_verify($pw, (string)val('SELECT password_hash FROM ' . cmt('users') . ' WHERE id = ?', [$a['cm']['id']]))) {
        q('INSERT INTO ' . t('login_attempts') . ' (ip, login_id) VALUES (?, ?)', [client_ip(), $loginId]);
        json_fail('invalid_login', '비밀번호가 맞지 않습니다.', 401);
    }
}

route('POST', '/vouchers/{id}/approve', function (int $id) {
    $a = require_account();
    verify_password($a);
    $comment = in_str(body(), 'comment', 500);
    tx(function () use ($id, $a, $comment) {
        $v = row('SELECT * FROM ' . t('vouchers') . ' WHERE id = ? FOR UPDATE', [$id]);
        if (!$v) json_fail('not_found', '결의서를 찾을 수 없습니다.', 404);
        check_rev($v);
        $items = voucher_items($id);
        $cur = current_step($v);
        $isConfirm = false;
        if (!$cur || !step_actor($a, $cur)) {
            // 사후 확인 단계
            $cur = null;
            foreach (rows('SELECT * FROM ' . t('approval_steps') . " WHERE voucher_id = ? AND round = ? AND mode = '확인' AND status = '대기' ORDER BY step_no", [$id, $v['round']]) as $s) if (step_actor($a, $s)) { $cur = $s; $isConfirm = true; break; }
            if (!$cur) json_fail('not_your_turn', '지금은 내가 결재할 차례가 아닙니다.', 403);
        }
        $as = step_actor($a, $cur);
        q('UPDATE ' . t('approval_steps') . " SET status='완료', acted_at=NOW(), acted_by_access_id=?, as_delegate=?, comment=?, content_hash=?, acted_ip=? WHERE id=?", [$a['access']['id'], $as === 'delegate' ? 1 : 0, $comment ?: null, content_hash($v, $items), client_ip(), $cur['id']]);
        if (!$isConfirm) {
            $left = (int)val('SELECT COUNT(*) FROM ' . t('approval_steps') . " WHERE voucher_id = ? AND round = ? AND status = '대기' AND mode = '결재'", [$id, $v['round']]);
            q('UPDATE ' . t('vouchers') . ' SET rev = rev + 1' . ($left === 0 ? ", status='결재 완료', approved_at=NOW()" : '') . ' WHERE id = ?', [$id]);
        }
        audit($isConfirm ? '결의서 확인' : '결의서 결재' . ($as === 'delegate' ? '(대결)' : ''), (string)$v['doc_no'], $a);
    });
    json_out(null);
});

route('POST', '/vouchers/{id}/reject', function (int $id) {
    $a = require_account();
    verify_password($a);
    $comment = in_str(body(), 'comment', 500, true, '반려 사유');
    tx(function () use ($id, $a, $comment) {
        $v = row('SELECT * FROM ' . t('vouchers') . ' WHERE id = ? FOR UPDATE', [$id]);
        if (!$v) json_fail('not_found', '결의서를 찾을 수 없습니다.', 404);
        check_rev($v);
        $cur = current_step($v);
        if (!$cur || !step_actor($a, $cur)) json_fail('not_your_turn', '지금은 내가 결재할 차례가 아닙니다.', 403);
        $as = step_actor($a, $cur);
        q('UPDATE ' . t('approval_steps') . " SET status='반려', acted_at=NOW(), acted_by_access_id=?, as_delegate=?, comment=?, content_hash=?, acted_ip=? WHERE id=?", [$a['access']['id'], $as === 'delegate' ? 1 : 0, $comment, content_hash($v, voucher_items($id)), client_ip(), $cur['id']]);
        q('UPDATE ' . t('approval_steps') . " SET status='건너뜀' WHERE voucher_id = ? AND round = ? AND status = '대기'", [$id, $v['round']]);
        q("UPDATE " . t('vouchers') . " SET status='반려', rev = rev + 1 WHERE id = ?", [$id]);
        audit('결의서 반려', (string)$v['doc_no'], $a);
    });
    json_out(null);
});

/** 회수: 첫 결재가 있기 전에 기안자가 되가져온다 */
route('POST', '/vouchers/{id}/recall', function (int $id) {
    $a = require_account();
    tx(function () use ($id, $a) {
        $v = row('SELECT * FROM ' . t('vouchers') . ' WHERE id = ? FOR UPDATE', [$id]);
        if (!$v) json_fail('not_found', '결의서를 찾을 수 없습니다.', 404);
        check_rev($v);
        if ($v['status'] !== '결재 중') json_fail('invalid_state', '결재 중인 결의서만 회수할 수 있습니다.', 409);
        if ((int)$v['drafter_access_id'] !== (int)$a['access']['id']) json_fail('forbidden', '기안자만 회수할 수 있습니다.', 403);
        if (val('SELECT COUNT(*) FROM ' . t('approval_steps') . " WHERE voucher_id = ? AND round = ? AND status = '완료'", [$id, $v['round']])) json_fail('invalid_state', '이미 결재가 시작되어 회수할 수 없습니다. 결재자에게 반려를 요청하세요.', 409);
        q('UPDATE ' . t('approval_steps') . " SET status='건너뜀', comment='회수' WHERE voucher_id = ? AND round = ? AND status = '대기'", [$id, $v['round']]);
        q("UPDATE " . t('vouchers') . " SET status='회수', rev = rev + 1 WHERE id = ?", [$id]);
        audit('결의서 회수', (string)$v['doc_no'], $a);
    });
    json_out(null);
});

route('POST', '/vouchers/{id}/cancel', function (int $id) {
    $a = require_account();
    $v = voucher_row($id);
    check_rev($v);
    if (!in_array($v['status'], ['작성 중', '반려', '회수'], true)) json_fail('invalid_state', '지금 상태에서는 취소할 수 없습니다.', 409);
    if ((int)$v['drafter_access_id'] !== (int)$a['access']['id'] && !has_role($a, '재정 담당')) json_fail('forbidden', '기안자만 취소할 수 있습니다.', 403);
    q("UPDATE " . t('vouchers') . " SET status='취소', rev = rev + 1 WHERE id = ?", [$id]);
    audit('결의서 취소', (string)($v['doc_no'] ?? $id), $a);
    json_out(null);
});

/** 지급 처리: 결재 완료 → 지급 완료. 내역 줄마다 장부에 지출이 생기고 결의서와 이어진다. 한 번만, 한 거래로. */
route('POST', '/vouchers/{id}/pay', function (int $id) {
    $a = require_role('재정 담당');
    $b = body();
    $wid = in_int($b, 'walletId', 1, PHP_INT_MAX, '출금 계좌');
    $paidOn = in_date($b, 'paidOn', '지급일');
    tx(function () use ($id, $a, $wid, $paidOn) {
        $v = row('SELECT * FROM ' . t('vouchers') . ' WHERE id = ? FOR UPDATE', [$id]);
        if (!$v) json_fail('not_found', '결의서를 찾을 수 없습니다.', 404);
        check_rev($v);
        if ($v['status'] !== '결재 완료') json_fail('invalid_state', '결재가 끝난 결의서만 지급할 수 있습니다.', 409);
        $w = row('SELECT * FROM ' . t('wallets') . ' WHERE id = ? AND is_active = 1', [$wid]);
        if (!$w) json_fail('invalid', '출금 계좌가 올바르지 않습니다.', 422);
        if (fiscal_year_of($paidOn) !== (int)$v['fiscal_year']) json_fail('invalid', '지급일이 결의서의 회계연도(' . $v['fiscal_year'] . '년) 안에 있어야 합니다.', 422);
        require_month_open($paidOn);
        foreach (voucher_items($id) as $i) {
            $tid = insert_id(
                'INSERT INTO ' . t('transactions') . ' (fiscal_year, tx_date, kind, fund_id, wallet_id, unit_id, budget_item_id, amount, summary, vendor, source, source_id, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [$v['fiscal_year'], $paidOn, '지출', $w['fund_id'], $wid, $v['unit_id'], $i['budget_item_id'], $i['amount'], mb_substr($v['doc_no'] . ' ' . $v['title'] . ' - ' . $i['summary'], 0, 255), $v['pay_holder'], '결의서', $id, $a['access']['id']]
            );
            q('UPDATE ' . t('voucher_items') . ' SET transaction_id = ? WHERE id = ?', [$tid, $i['id']]);
        }
        q("UPDATE " . t('vouchers') . " SET status='지급 완료', paid_at=?, paid_wallet_id=?, paid_by=?, rev = rev + 1 WHERE id = ?", [$paidOn . ' 00:00:00', $wid, $a['access']['id'], $id]);
        audit('결의서 지급', (string)$v['doc_no'], $a);
    });
    json_out(null);
});

/** 지급 대기 목록(재정 담당) */
route('GET', '/vouchers-to-pay', function () {
    require_role('재정 담당');
    $r = rows('SELECT v.id, v.doc_no, v.title, v.total, v.pay_method, v.pay_bank, v.pay_account, v.pay_holder, v.desired_date, u.name AS unit FROM ' . t('vouchers') . ' v JOIN ' . t('units') . " u ON u.id = v.unit_id WHERE v.status = '결재 완료' ORDER BY v.approved_at");
    json_out(['items' => array_map(fn($x) => ['id' => (int)$x['id'], 'docNo' => $x['doc_no'], 'title' => $x['title'], 'total' => (int)$x['total'], 'payMethod' => $x['pay_method'], 'bank' => $x['pay_bank'], 'account' => $x['pay_account'], 'holder' => $x['pay_holder'], 'desiredDate' => $x['desired_date'], 'unit' => $x['unit']], $r)]);
});
