<?php
declare(strict_types=1);

// 영수증 보조: 기간(월·분기) 조회(참고용, 발급 아님)와 세대주 맞춤(교적의 세대와 헌금자 합산이 어긋난 곳 찾기·맞추기).
// 교적(cm_)은 읽기만 한다.

/** 'YYYY-MM' 두 개를 날짜 범위로 */
function period_bounds(string $from, string $to): array
{
    if (!preg_match('/^(\d{4})-(\d{2})$/', $from, $a) || !preg_match('/^(\d{4})-(\d{2})$/', $to, $b)) json_fail('invalid', '기간은 YYYY-MM 으로 입력해 주세요.', 422);
    if ((int)$a[2] < 1 || (int)$a[2] > 12 || (int)$b[2] < 1 || (int)$b[2] > 12 || $from > $to) json_fail('invalid', '기간이 올바르지 않습니다.', 422);
    if (((int)$b[1] - (int)$a[1]) * 12 + (int)$b[2] - (int)$a[2] > 35) json_fail('invalid', '기간은 36개월을 넘길 수 없습니다.', 422);
    return [$from . '-01', date('Y-m-t', strtotime($to . '-01'))];
}

/** 기간 합계(참고용): 마감된 영수증 대상 헌금을 대표 헌금자별·월별로. 영수증 발급과는 무관하다. */
route('GET', '/receipts/period', function () {
    $a = require_receipt_staff();
    [$f, $t] = period_bounds((string)($_GET['from'] ?? ''), (string)($_GET['to'] ?? ''));
    $out = [];
    $months = [];
    for ($d = new DateTime(substr($f, 0, 7) . '-01'); $d->format('Y-m-d') <= $t; $d->modify('+1 month')) $months[] = $d->format('Y-m');
    foreach (receipt_groups_range($f, $t) as $tid => $g) {
        $d = row('SELECT name FROM ' . t('donors') . ' WHERE id = ?', [$tid]);
        $cells = [];
        foreach ($g['months'] as $m => $sum) $cells[(string)$m] = (int)$sum;
        $out[] = ['donorId' => $tid, 'name' => $d['name'], 'members' => array_values($g['names']), 'total' => (int)$g['total'], 'months' => (object)$cells];
    }
    usort($out, fn($x, $y) => strcmp($x['name'], $y['name']));
    audit('영수증 기간 조회', substr($f, 0, 7) . '~' . substr($t, 0, 7), $a);
    json_out(['from' => substr($f, 0, 7), 'to' => substr($t, 0, 7), 'months' => $months, 'items' => $out, 'total' => array_sum(array_column($out, 'total'))]);
});

// ---------------------------------------------------------------- 세대주 맞춤

function hh_dismissed(): array
{
    $v = val('SELECT v FROM ' . t('meta') . " WHERE k = 'hh_dismiss'");
    $d = $v ? json_decode((string)$v, true) : [];
    return is_array($d) ? $d : [];
}

/** 교적 세대와 어긋난 합산 찾기. 어떤 것도 바꾸지 않는다. */
function household_issues(): array
{
    $donors = rows('SELECT id, name, person_id, receipt_to_donor_id AS rt FROM ' . t('donors') . ' WHERE person_id IS NOT NULL LIMIT 5000');
    if (!$donors) return [];
    $pids = array_column($donors, 'person_id');
    $hh = [];
    foreach (array_chunk($pids, 800) as $chunk) {
        foreach (rows('SELECT person_id, household_id FROM ' . cmt('household_members') . ' WHERE person_id IN (' . marks($chunk) . ')', $chunk) as $r) $hh[$r['person_id']] = $r['household_id'];
    }
    $byId = [];
    $byPerson = [];
    foreach ($donors as $d) { $byId[(int)$d['id']] = $d; $byPerson[$d['person_id']] = (int)$d['id']; }
    // 세대별 세대주(본인) 헌금자
    $headDonor = [];
    $hids = array_values(array_unique(array_values($hh)));
    foreach (array_chunk($hids, 800) as $chunk) {
        foreach (rows("SELECT household_id, person_id FROM " . cmt('household_members') . " WHERE relation = '본인' AND household_id IN (" . marks($chunk) . ')', $chunk) as $r) {
            if (isset($byPerson[$r['person_id']])) $headDonor[$r['household_id']] = $byPerson[$r['person_id']];
        }
    }
    $members = []; // 세대 → 헌금자 id 목록
    foreach ($donors as $d) if (isset($hh[$d['person_id']])) $members[$hh[$d['person_id']]][] = (int)$d['id'];
    $targets = [];
    foreach ($donors as $d) if ($d['rt'] !== null) $targets[(int)$d['rt']][] = (int)$d['id'];
    $issues = [];
    $seen = [];
    foreach ($donors as $d) {
        if ($d['rt'] === null) continue;
        $id = (int)$d['id'];
        $tid = (int)$d['rt'];
        $T = $byId[$tid] ?? null;
        $h = $hh[$d['person_id']] ?? null;
        $ht = $T ? ($hh[$T['person_id']] ?? null) : null;
        if (!$T || !$T['person_id']) continue; // 교인이 아닌 대표(단체 등)는 사람이 정한 것으로 본다
        if ($h === null || $ht === null || $h !== $ht) {
            $issues[] = ['key' => "moved:$id", 'type' => 'moved', 'text' => $d['name'] . ' 님은 합산 대표 ' . $T['name'] . ' 님과 교적에서 같은 세대가 아닙니다.', 'action' => 'unmerge', 'donorId' => $id, 'toId' => null, 'names' => [$d['name']]];
            continue;
        }
        $H = $headDonor[$h] ?? null;
        if ($H !== null && $H !== $tid && !isset($seen["head:$tid:$H"])) {
            $seen["head:$tid:$H"] = 1;
            $issues[] = ['key' => "head:$tid:$H", 'type' => 'head', 'text' => '합산 대표 ' . $T['name'] . ' 님이 교적의 세대주가 아닙니다. 세대주 ' . $byId[$H]['name'] . ' 님으로 맞출까요?', 'action' => 'repoint', 'donorId' => $tid, 'toId' => $H, 'names' => [$T['name'], $byId[$H]['name']]];
        }
    }
    // 같은 세대의 헌금자가 둘 이상인데 아무도 합산되어 있지 않은 곳(제안)
    foreach ($members as $h => $ids) {
        if (count($ids) < 2) continue;
        $anyMerged = false;
        foreach ($ids as $i) if ($byId[$i]['rt'] !== null || isset($targets[$i])) { $anyMerged = true; break; }
        if ($anyMerged) continue;
        $H = $headDonor[$h] ?? $ids[0];
        $names = array_map(fn($i) => $byId[$i]['name'], $ids);
        $issues[] = ['key' => "merge:$h", 'type' => 'merge', 'text' => '같은 세대 ' . implode('·', $names) . ' 님이 따로 발급됩니다. ' . $byId[$H]['name'] . ' 님 명의로 합산할까요?', 'action' => 'merge', 'donorId' => $H, 'toId' => null, 'ids' => $ids, 'names' => $names];
    }
    $dis = hh_dismissed();
    $yr = (int)date('Y');
    return array_values(array_filter($issues, fn($i) => (int)($dis[$i['key']] ?? 0) !== $yr));
}

route('GET', '/donors/household-check', function () {
    $a = require_receipt_staff();
    $items = household_issues();
    audit('세대 합산 확인', count($items) . '건', $a);
    json_out(['items' => $items]);
});

/** 영수증을 이미 낸 대표를 바꾼 경우의 안내: 정정 발급이 필요한 연도 */
function hh_receipt_years(array $donorIds): array
{
    if (!$donorIds) return [];
    return array_map('intval', array_column(rows('SELECT DISTINCT tax_year FROM ' . t('receipts') . ' WHERE voided_at IS NULL AND donor_id IN (' . marks($donorIds) . ') ORDER BY tax_year DESC', $donorIds), 'tax_year'));
}

route('POST', '/donors/household-apply', function () {
    $a = require_receipt_staff();
    $b = body();
    $action = in_enum($b, 'action', ['unmerge', 'repoint', 'merge'], '맞추기 종류');
    $touched = [];
    tx(function () use ($action, $b, &$touched) {
        $setRt = function (int $id, ?int $to) use (&$touched) {
            $old = val('SELECT receipt_to_donor_id FROM ' . t('donors') . ' WHERE id = ? FOR UPDATE', [$id]);
            $oldI = $old === null ? null : (int)$old;
            if ($oldI === $to) return;
            q('UPDATE ' . t('donors') . ' SET receipt_to_donor_id = ? WHERE id = ?', [$to, $id]);
            change_log('donor', (string)$id, 'receipt_to_donor_id', $oldI, $to, '세대주 맞춤');
            $touched[] = $id;
            if ($oldI !== null) $touched[] = $oldI;
            if ($to !== null) $touched[] = $to;
        };
        $exists = fn(int $id) => (bool)val('SELECT COUNT(*) FROM ' . t('donors') . ' WHERE id = ?', [$id]);
        if ($action === 'unmerge') {
            $id = in_int($b, 'donorId', 1, PHP_INT_MAX, '헌금자');
            if (!$exists($id)) json_fail('not_found', '헌금자를 찾을 수 없습니다.', 404);
            $setRt($id, null);
        } elseif ($action === 'repoint') {
            $from = in_int($b, 'donorId', 1, PHP_INT_MAX, '지금 대표');
            $to = in_int($b, 'toId', 1, PHP_INT_MAX, '새 대표');
            if ($from === $to || !$exists($from) || !$exists($to)) json_fail('invalid', '대상이 올바르지 않습니다.', 422);
            $setRt($to, null);
            foreach (rows('SELECT id FROM ' . t('donors') . ' WHERE receipt_to_donor_id = ? AND id <> ?', [$from, $to]) as $r) $setRt((int)$r['id'], $to);
            $setRt($from, $to);
        } else {
            $head = in_int($b, 'donorId', 1, PHP_INT_MAX, '대표');
            $ids = $b['ids'] ?? [];
            if (!is_array($ids) || count($ids) < 2 || count($ids) > 30 || !in_array($head, array_map('intval', $ids), true)) json_fail('invalid', '대상이 올바르지 않습니다.', 422);
            foreach ($ids as $i) if (!$exists((int)$i)) json_fail('invalid', '대상이 올바르지 않습니다.', 422);
            $setRt($head, null);
            foreach ($ids as $i) if ((int)$i !== $head) $setRt((int)$i, $head);
        }
    });
    audit('세대 합산 맞춤', $action, $a);
    json_out(['correctYears' => hh_receipt_years(array_values(array_unique($touched)))]);
});

/** 이 알림을 올해는 그만 보기(다음 해에 다시 나옴) */
route('POST', '/donors/household-dismiss', function () {
    require_receipt_staff();
    $key = (string)(body()['key'] ?? '');
    if (!preg_match('/^(moved|head|merge):[0-9:]+$/', $key)) json_fail('invalid', '알림이 올바르지 않습니다.', 422);
    $d = hh_dismissed();
    $d[$key] = (int)date('Y');
    q('INSERT INTO ' . t('meta') . " (k, v) VALUES ('hh_dismiss', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)", [json_encode($d)]);
    json_out(null);
});
