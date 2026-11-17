<?php
declare(strict_types=1);

// 개인정보 동의 확인. 교인 헌금자는 교적의 동의 기록(cm_consents)을 읽기만 하고, 교인이 아닌 헌금자는 재정에서 직접 기록한다(fm_donor_consents).
// 동의가 없어도 영수증 발급을 막지는 않는다(경고만). 교적에는 아무것도 쓰지 않는다.

function cm_table_exists(string $name): bool
{
    static $c = [];
    if (isset($c[$name])) return $c[$name];
    try {
        $r = (int)val('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [(fm_config()['cm_prefix'] ?? 'cm_') . $name]);
    } catch (Throwable) {
        $r = 0;
    }
    return $c[$name] = $r > 0;
}

const FM_CONSENT_LABELS = ['ok' => '동의 있음', 'old' => '이전 판 동의', 'none' => '동의 없음', 'revoked' => '철회됨', 'guardian' => '보호자 동의 필요', 'unknown' => '확인 불가'];

/** 헌금자 여러 명의 동의 상태. @return array<int, array{state:string,label:string,version:?string,date:?string}> */
function consent_states(array $ids): array
{
    $ids = array_values(array_unique(array_map('intval', $ids)));
    $out = [];
    if (!$ids) return $out;
    $ver = FM_POLICY_VERSION;
    $dn = rows('SELECT id, person_id FROM ' . t('donors') . ' WHERE id IN (' . marks($ids) . ')', $ids);
    $mem = [];
    foreach ($dn as $d) if ($d['person_id']) $mem[(string)$d['person_id']] = (int)$d['id'];
    $cm = [];
    $birth = [];
    $cmOk = $mem && cm_table_exists('consents');
    if ($cmOk) {
        $pids = array_keys($mem);
        foreach (rows('SELECT person_id, kind, policy_version, consent_date, revoked_on FROM ' . cmt('consents') . ' WHERE person_id IN (' . marks($pids) . ')', $pids) as $c) $cm[$c['person_id']][] = $c;
        foreach (rows('SELECT id, birth_date FROM ' . cmt('people') . ' WHERE id IN (' . marks($pids) . ')', $pids) as $p) $birth[$p['id']] = $p['birth_date'];
    }
    $own = [];
    foreach (rows('SELECT donor_id, policy_version, consent_date, revoked_on FROM ' . t('donor_consents') . ' WHERE donor_id IN (' . marks($ids) . ') ORDER BY consent_date, id', $ids) as $c) $own[(int)$c['donor_id']][] = $c;
    $mk = fn(string $s, ?string $v = null, ?string $date = null) => ['state' => $s, 'label' => FM_CONSENT_LABELS[$s], 'version' => $v, 'date' => $date];
    foreach ($dn as $d) {
        $id = (int)$d['id'];
        if ($d['person_id']) {
            if (!$cmOk) { $out[$id] = $mk('unknown'); continue; }
            $list = $cm[$d['person_id']] ?? [];
            $active = array_values(array_filter($list, fn($c) => $c['revoked_on'] === null));
            $base = array_values(array_filter($active, fn($c) => in_array($c['kind'], ['교적 등록', '민감정보'], true)));
            $bd = $birth[$d['person_id']] ?? null;
            $minor = $bd && strtotime((string)$bd) > strtotime('-14 years');
            if ($minor && !array_filter($active, fn($c) => $c['kind'] === '보호자 동의')) { $out[$id] = $mk('guardian'); continue; }
            if ($base) {
                usort($base, fn($a, $b) => strcmp((string)$b['consent_date'], (string)$a['consent_date']));
                $v = (string)$base[0]['policy_version'];
                $out[$id] = $mk($v === $ver ? 'ok' : 'old', $v, $base[0]['consent_date'] ? substr((string)$base[0]['consent_date'], 0, 10) : null);
            } else {
                $out[$id] = $mk($list ? 'revoked' : 'none');
            }
        } else {
            $list = $own[$id] ?? [];
            $active = array_values(array_filter($list, fn($c) => $c['revoked_on'] === null));
            if ($active) {
                $last = end($active);
                $out[$id] = $mk((string)$last['policy_version'] === $ver ? 'ok' : 'old', (string)$last['policy_version'], substr((string)$last['consent_date'], 0, 10));
            } else {
                $out[$id] = $mk($list ? 'revoked' : 'none');
            }
        }
    }
    return $out;
}

route('GET', '/donors/consent-status', function () {
    require_receipt_staff();
    $ids = array_filter(explode(',', (string)($_GET['ids'] ?? '')), fn($x) => ctype_digit($x));
    if (count($ids) > 300) json_fail('invalid', '대상이 너무 많습니다.', 422);
    json_out(['policyVersion' => FM_POLICY_VERSION, 'states' => (object)consent_states($ids)]);
});

/** 교인이 아닌 헌금자의 동의 기록 목록 */
route('GET', '/donors/{id}/consents', function (int $id) {
    require_receipt_staff();
    $d = row('SELECT id, person_id FROM ' . t('donors') . ' WHERE id = ?', [$id]);
    if (!$d) json_fail('not_found', '헌금자를 찾을 수 없습니다.', 404);
    $items = $d['person_id'] ? [] : array_map(
        fn($c) => ['id' => (int)$c['id'], 'version' => $c['policy_version'], 'date' => substr((string)$c['consent_date'], 0, 10), 'method' => $c['method'], 'memo' => $c['memo'], 'revoked' => $c['revoked_on'] ? substr((string)$c['revoked_on'], 0, 10) : null],
        rows('SELECT * FROM ' . t('donor_consents') . ' WHERE donor_id = ? ORDER BY consent_date DESC, id DESC', [$id])
    );
    json_out(['member' => (bool)$d['person_id'], 'items' => $items, 'state' => consent_states([$id])[$id] ?? null]);
});

route('POST', '/donors/{id}/consent', function (int $id) {
    $a = require_receipt_staff();
    $b = body();
    $d = row('SELECT id, name, person_id FROM ' . t('donors') . ' WHERE id = ?', [$id]);
    if (!$d) json_fail('not_found', '헌금자를 찾을 수 없습니다.', 404);
    if ($d['person_id']) json_fail('member', '교인의 동의는 교적 관리에서 기록합니다. 교적 담당자에게 요청해 주세요.', 422);
    $date = in_date($b, 'date', '동의일');
    if ($date > today_str()) json_fail('invalid', '동의일이 오늘보다 뒤일 수 없습니다.', 422);
    $method = in_enum($b, 'method', ['서면', '구두', '앱'], '동의 방법');
    $cid = insert_id('INSERT INTO ' . t('donor_consents') . ' (donor_id, policy_version, consent_date, method, memo, created_by) VALUES (?,?,?,?,?,?)', [$id, FM_POLICY_VERSION, $date, $method, in_str($b, 'memo', 255) ?: null, $a['access']['id']]);
    change_log('donor_consent', (string)$cid, 'create', null, ['donor' => $id, 'method' => $method, 'date' => $date]);
    audit('헌금자 동의 기록', $d['name'] . ' ' . $method, $a);
    json_out(['id' => $cid]);
});

route('POST', '/donors/{id}/consent/revoke', function (int $id) {
    $a = require_receipt_staff();
    $b = body();
    $d = row('SELECT id, name, person_id FROM ' . t('donors') . ' WHERE id = ?', [$id]);
    if (!$d) json_fail('not_found', '헌금자를 찾을 수 없습니다.', 404);
    if ($d['person_id']) json_fail('member', '교인의 동의 철회는 교적 관리에서 처리합니다.', 422);
    $date = in_date($b, 'date', '철회일');
    $n = q('UPDATE ' . t('donor_consents') . ' SET revoked_on = ? WHERE donor_id = ? AND revoked_on IS NULL', [$date, $id])->rowCount();
    change_log('donor_consent', (string)$id, 'revoke', null, ['date' => $date]);
    audit('헌금자 동의 철회', $d['name'], $a);
    json_out(['revoked' => $n, 'hint' => '주민등록번호를 보관 중이면 영수증 정보에서 지워 주세요.']);
});
