<?php
declare(strict_types=1);

// 교구(소그룹)별 헌금 합계. 합계·헌금한 사람 수·1인 평균만 보이고 개인별 금액은 보이지 않는다.
// 헌금자가 3명 미만인 모임은 합계로 개인 헌금을 알 수 있으므로 '기타(소규모)'로 합쳐 보여 준다. 교적(cm_)은 읽기만 한다.

const FM_GROUP_MIN_GIVERS = 3;

route('GET', '/reports/groups', function () {
    $a = require_account();
    if (!can_view_donors($a) && !has_role($a, '재정 담당')) json_fail('forbidden', '권한이 없습니다.', 403);
    [$f, $t] = period_bounds((string)($_GET['from'] ?? ''), (string)($_GET['to'] ?? ''));
    $demo = !empty($a['demo']);
    $label = '소그룹';
    $available = $demo || (cm_table_exists('small_groups') && cm_table_exists('person_groups'));
    if (!$demo) {
        $ci = fm_cm_church(db(), (string)(fm_config()['cm_prefix'] ?? 'cm_'));
        $label = trim((string)($ci['unit2'] ?? '')) ?: '소그룹';
    } else {
        $label = '교구';
    }
    if (!$available) json_out(['available' => false, 'label' => $label, 'message' => '교적 관리를 최신 버전으로 올리면 ' . $label . '별 합계를 볼 수 있어요.', 'items' => [], 'total' => 0, 'min' => FM_GROUP_MIN_GIVERS]);

    $sql = 'SELECT o.donor_id, SUM(o.amount) AS s FROM ' . t('offerings') . ' o JOIN ' . t('offering_batches') . " b ON b.id = o.batch_id AND b.status = '마감' WHERE o.offering_date BETWEEN ? AND ?";
    $params = [$f, $t];
    $acct = (int)($_GET['accountId'] ?? 0);
    if ($acct) {
        $sql .= ' AND (o.income_account_id = ? OR o.income_account_id IN (SELECT c.id FROM ' . t('income_accounts') . ' c WHERE c.parent_id = ?))';
        array_push($params, $acct, $acct);
    }
    $sums = rows($sql . ' GROUP BY o.donor_id', $params);
    $total = array_sum(array_map(fn($r) => (int)$r['s'], $sums));

    // 헌금자 → 모임
    $donorIds = array_values(array_filter(array_map(fn($r) => $r['donor_id'] === null ? null : (int)$r['donor_id'], $sums)));
    $of = []; // donor_id → [key, name]
    $members = []; // key → 소속 인원
    if ($demo) {
        $names = ['1교구', '2교구', '3교구', '4교구'];
        foreach ($donorIds as $d) $of[$d] = ['g' . ($d % 4), $names[$d % 4]];
        foreach ($names as $i => $n) $members['g' . $i] = 9 + $i;
    } elseif ($donorIds) {
        foreach (array_chunk($donorIds, 800) as $chunk) {
            $r = rows('SELECT d.id, sg.id AS gid, sg.name FROM ' . t('donors') . ' d JOIN ' . cmt('person_groups') . ' pg ON pg.person_id = d.person_id JOIN ' . cmt('small_groups') . ' sg ON sg.id = pg.small_group_id WHERE d.id IN (' . marks($chunk) . ')', $chunk);
            foreach ($r as $x) $of[(int)$x['id']] = [(string)$x['gid'], (string)$x['name']];
        }
        foreach (rows('SELECT small_group_id AS gid, COUNT(*) AS n FROM ' . cmt('person_groups') . ' GROUP BY small_group_id') as $x) $members[(string)$x['gid']] = (int)$x['n'];
    }
    $g = []; // key → [name, sum, givers]
    foreach ($sums as $r) {
        $s = (int)$r['s'];
        if ($r['donor_id'] === null) { $k = '_anon'; $n = '이름 없는 헌금'; }
        elseif (isset($of[(int)$r['donor_id']])) [$k, $n] = $of[(int)$r['donor_id']];
        else { $k = '_none'; $n = $label . ' 소속 없음'; }
        $g[$k] ??= ['key' => $k, 'name' => $n, 'sum' => 0, 'givers' => 0];
        $g[$k]['sum'] += $s;
        if ($r['donor_id'] !== null && $s > 0) $g[$k]['givers']++;
    }
    // 작은 모임 보호: 헌금한 사람이 적은 모임은 '기타(소규모)'로 합친다. (이름 없는 헌금은 사람 수가 없어 그대로 둔다)
    $items = [];
    $small = ['key' => '_small', 'name' => '기타(소규모)', 'sum' => 0, 'givers' => 0, 'n' => 0];
    foreach ($g as $x) {
        if ($x['key'] !== '_anon' && $x['givers'] < FM_GROUP_MIN_GIVERS) {
            $small['sum'] += $x['sum']; $small['givers'] += $x['givers']; $small['n']++;
            continue;
        }
        $items[] = $x;
    }
    if ($small['n'] > 0) $items[] = $small;
    usort($items, fn($x, $y) => $y['sum'] <=> $x['sum']);
    $out = array_map(fn($x) => [
        'name' => $x['name'], 'sum' => $x['sum'], 'givers' => $x['givers'],
        'members' => in_array($x['key'], ['_anon', '_none', '_small'], true) ? null : ($members[$x['key']] ?? null),
        'avg' => $x['givers'] > 0 ? (int)round($x['sum'] / $x['givers']) : null,
        'share' => $total > 0 ? round($x['sum'] * 100 / $total, 1) : 0,
    ], $items);
    audit('교구별 헌금 조회', substr($f, 0, 7) . '~' . substr($t, 0, 7), $a);
    json_out(['available' => true, 'label' => $label, 'from' => substr($f, 0, 7), 'to' => substr($t, 0, 7), 'items' => $out, 'total' => $total, 'min' => FM_GROUP_MIN_GIVERS]);
});
