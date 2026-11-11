<?php
declare(strict_types=1);

// 시스템 상태와 첫 화면 자료. (M0: 교적 연결 상태와 교인 수)

/** 첫 화면 자료: 권한에 맞는 것만 담는다. (합계는 합계를 볼 수 있는 역할, 부서 현황은 담당자 범위) */
function dashboard_finance(array $a): array
{
    $year = (int)val('SELECT year FROM ' . t('fiscal_years') . " WHERE status = '진행' ORDER BY year DESC LIMIT 1") ?: (int)val('SELECT MAX(year) FROM ' . t('fiscal_years'));
    $out = ['year' => $year ?: null, 'totals' => null, 'weekly' => [], 'units' => [], 'recent' => [], 'mine' => [], 'alerts' => []];
    if (!$year) return $out;
    $units = budget_units($year, $a);
    $all = sees_all_units($a);
    $bud = $paid = $pend = 0;
    $rank = [];
    foreach ($units as $u) {
        $ub = array_sum(array_column($u['items'], 'budget'));
        $up = array_sum(array_column($u['items'], 'paid'));
        $bud += $ub;
        $paid += $up;
        $pend += array_sum(array_column($u['items'], 'pending'));
        if ($ub > 0) $rank[] = ['name' => $u['name'], 'budget' => $ub, 'paid' => $up, 'rate' => (int)round($up * 100 / $ub)];
        foreach ($u['items'] as $i) if ($i['remain'] < 0) $out['alerts'][] = ['text' => $u['name'] . ' · ' . $i['name'] . ' 예산을 ' . number_format(-$i['remain']) . '원 넘었어요.', 'to' => '/budget'];
    }
    usort($rank, fn($x, $y) => $y['rate'] <=> $x['rate']);
    $out['units'] = array_slice($rank, 0, 6);
    $out['budget'] = ['budget' => $bud, 'paid' => $paid, 'pending' => $pend, 'rate' => $bud > 0 ? (int)round($paid * 100 / $bud) : 0, 'scope' => $all ? '전체' : '내 부서'];
    if (can_see_totals($a)) {
        $m = date('Y-m');
        $sum = fn(string $kind, string $cond = '', array $p = []) => (int)val('SELECT COALESCE(SUM(amount),0) FROM ' . t('transactions') . " WHERE kind = ? AND void_at IS NULL AND fiscal_year = ? $cond", array_merge([$kind, $year], $p));
        $out['totals'] = [
            'income' => $sum('수입'), 'expense' => $sum('지출'), 'incomeMonth' => $sum('수입', "AND DATE_FORMAT(tx_date,'%Y-%m') = ?", [$m]), 'expenseMonth' => $sum('지출', "AND DATE_FORMAT(tx_date,'%Y-%m') = ?", [$m]),
            'balance' => array_sum(array_column(wallet_balances(null), 'balance')),
        ];
        // 최근 8주(일요일 기준) 헌금
        $weeks = [];
        $d = new DateTime('today');
        $d->modify('-' . (int)$d->format('w') . ' days');
        for ($i = 7; $i >= 0; $i--) {
            $e = (clone $d)->modify("-{$i} weeks");
            $weeks[] = [$e->format('Y-m-d'), (clone $e)->modify('+6 days')->format('Y-m-d')];
        }
        foreach ($weeks as [$f, $t]) {
            $sumW = (int)val('SELECT COALESCE(SUM(o.amount),0) FROM ' . t('offerings') . ' o JOIN ' . t('offering_batches') . " b ON b.id = o.batch_id AND b.status = '마감' WHERE o.offering_date BETWEEN ? AND ?", [$f, $t]);
            $out['weekly'][] = ['label' => substr($f, 5), 'sum' => $sumW];
        }
    }
    // 내 부서(담당자로 지정된 단위)
    $pid = $a['cm']['person_id'] ?? null;
    if ($pid) {
        foreach (rows('SELECT u.id, u.name, r.role FROM ' . t('unit_roles') . ' r JOIN ' . t('units') . ' u ON u.id = r.unit_id WHERE r.person_id = ? AND u.fiscal_year = ? ORDER BY u.sort_order, u.id', [$pid, $year]) as $r) {
            $row = null;
            foreach ($units as $u) if ((int)$u['id'] === (int)$r['id']) $row = $u;
            $b = $row ? array_sum(array_column($row['items'], 'budget')) : 0;
            $p = $row ? array_sum(array_column($row['items'], 'paid')) : 0;
            $out['mine'][] = ['id' => (int)$r['id'], 'name' => $r['name'], 'role' => $r['role'], 'budget' => $b, 'paid' => $p, 'remain' => $b - $p - ($row ? array_sum(array_column($row['items'], 'pending')) : 0)];
        }
    }
    // 최근 결의서 (내가 볼 수 있는 것)
    $all2 = has_role($a, '재정 담당', '재정위원장', '담임목사', '감사');
    $w = 'v.fiscal_year = ?';
    $p = [$year];
    if (!$all2) { $w .= ' AND (v.drafter_access_id = ? OR v.id IN (SELECT voucher_id FROM ' . t('approval_steps') . ' WHERE approver_person_id = ?))'; $p[] = (int)$a['access']['id']; $p[] = $pid ?? ''; }
    foreach (rows('SELECT v.id, v.title, v.total, v.status, u.name AS unit FROM ' . t('vouchers') . ' v JOIN ' . t('units') . " u ON u.id = v.unit_id WHERE $w ORDER BY v.id DESC LIMIT 5", $p) as $v) {
        $out['recent'][] = ['id' => (int)$v['id'], 'title' => $v['title'], 'total' => (int)$v['total'], 'status' => $v['status'], 'unit' => $v['unit']];
    }
    if (has_role($a, '재정 담당')) {
        $last = date('Y-m', strtotime('first day of last month'));
        if ((int)val('SELECT COUNT(*) FROM ' . t('transactions') . " WHERE DATE_FORMAT(tx_date,'%Y-%m') = ? AND void_at IS NULL", [$last]) && !val('SELECT COUNT(*) FROM ' . t('closings') . ' WHERE period = ? AND reopened_at IS NULL', [$last])) {
            $out['alerts'][] = ['text' => $last . ' 월이 아직 마감되지 않았어요.', 'to' => '/closing'];
        }
    }
    return $out;
}

/** 첫 화면: 재정 현황과 할 일. 재정 사용이 승인된 사람만. */
route('GET', '/dashboard', function () {
    $a = require_account();
    $cm = cm_status();
    $church = fm_cm_church(db(), fm_config()['cm_prefix'] ?? 'cm_');
    json_out([
        'cm' => ['ok' => $cm['ok'], 'reason' => $cm['reason'], 'message' => $cm['message'], 'schema' => $cm['schema'], 'people' => $cm['people'], 'churchName' => (string)($church['name'] ?? '')],
        'fm' => ['version' => FM_VERSION, 'schema' => current_schema_version(db(), fm_config()['prefix'])],
        'rrnMode' => (string)val('SELECT rrn_mode FROM ' . t('settings') . ' WHERE id = 1'),
        'isAdmin' => is_admin_account($a),
        'waiting' => count(inbox_cards($a)['waiting']),
        'toPay' => has_role($a, '재정 담당') ? (int)val('SELECT COUNT(*) FROM ' . t('vouchers') . " WHERE status = '결재 완료'") : 0,
        'openBatches' => has_role($a, '재정 담당') ? (int)val('SELECT COUNT(*) FROM ' . t('offering_batches') . " WHERE status = '입력 중'") : 0,
        'cmSso' => $cm['schema'] >= FM_CM_SSO_SCHEMA,
        'pendingAccess' => is_admin_account($a) ? (int)val('SELECT COUNT(*) FROM ' . t('access') . " WHERE status = '신청 대기'") : 0,
        'fin' => dashboard_finance($a),
    ]);
});
