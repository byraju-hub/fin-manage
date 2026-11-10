<?php
declare(strict_types=1);

// 기부금 영수증: 헌금자 정보(주소·가족 합산·주민번호), 발급 미리보기, 발급(번호는 거래 안에서), 발급 대장, 정정, 엑셀(CSV).
// 주민등록번호: rrn_mode 가 keep 이면 AES-256-GCM 으로 암호화 보관, none 이면 입력칸도 저장도 없다. (설계 11.2)

function require_receipt_staff(): array
{
    $a = require_role('재정 담당');
    if (!can_view_donors($a)) json_fail('forbidden', '개인별 헌금을 볼 수 있는 권한이 있어야 영수증을 다룰 수 있습니다. (재정 관리자가 열람을 켜 줍니다.)', 403);
    return $a;
}

function rrn_mode(): string
{
    return (string)val('SELECT rrn_mode FROM ' . t('settings') . ' WHERE id = 1');
}

function rrn_key(): string
{
    $k = base64_decode((string)(fm_config()['rrn_key'] ?? ''), true);
    if ($k === false || strlen($k) !== 32) json_fail('no_key', '주민등록번호 암호화 열쇠가 설정되어 있지 않습니다.', 500);
    return $k;
}

function rrn_encrypt(string $plain): string
{
    $iv = random_bytes(12);
    $tag = '';
    $c = openssl_encrypt($plain, 'aes-256-gcm', rrn_key(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($c === false) json_fail('crypto', '암호화하지 못했습니다.', 500);
    return base64_encode($iv . $tag . $c);
}

function rrn_decrypt(string $blob): string
{
    $raw = base64_decode($blob, true);
    if ($raw === false || strlen($raw) < 29) return '';
    $p = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', rrn_key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    return $p === false ? '' : $p;
}

function rrn_valid(string $d): bool
{
    if (!preg_match('/^\d{13}$/', $d)) return false;
    $w = [2, 3, 4, 5, 6, 7, 8, 9, 2, 3, 4, 5];
    $s = 0;
    foreach ($w as $i => $x) $s += (int)$d[$i] * $x;
    return (11 - $s % 11) % 10 === (int)$d[12];
}

function rrn_mask(string $d): string
{
    return strlen($d) === 13 ? substr($d, 0, 6) . '-*******' : '';
}

/** 헌금자 한 명의 영수증 정보 */
function donor_info(array $d): array
{
    $cm = $d['person_id'] ? row('SELECT birth_date, address_zip, address1, address2 FROM ' . cmt('people') . ' WHERE id = ?', [$d['person_id']]) : null;
    $addr = trim((string)($d['receipt_address'] ?? ''));
    if ($addr === '' && $cm) $addr = trim(trim((string)$cm['address1']) . ' ' . trim((string)$cm['address2']));
    $birth6 = $cm && $cm['birth_date'] ? substr(str_replace('-', '', (string)$cm['birth_date']), 2) : '';
    return ['address' => $addr, 'birth6' => $birth6];
}

route('GET', '/donors', function () {
    require_receipt_staff();
    $qs = trim((string)($_GET['q'] ?? ''));
    $keep = rrn_mode() === 'keep';
    $sql = 'SELECT d.*, (SELECT COUNT(*) FROM ' . t('donors') . ' x WHERE x.receipt_to_donor_id = d.id) AS merged FROM ' . t('donors') . ' d';
    $p = [];
    if ($qs !== '') { $sql .= ' WHERE d.name LIKE ? OR d.donor_no = ?'; $p = ['%' . $qs . '%', $qs]; }
    $r = rows($sql . ' ORDER BY d.name, d.id LIMIT 100', $p);
    json_out(['rrnMode' => rrn_mode(), 'items' => array_map(function ($d) use ($keep) {
        $info = donor_info($d);
        return ['id' => (int)$d['id'], 'name' => $d['name'], 'member' => $d['person_id'] !== null, 'donorNo' => $d['donor_no'], 'address' => $d['receipt_address'], 'cmAddress' => $info['address'], 'receiptTo' => $d['receipt_to_donor_id'] ? (int)$d['receipt_to_donor_id'] : null, 'merged' => (int)$d['merged'],
            'hasRrn' => $keep && $d['rrn_enc'] !== null, 'memo' => $d['memo']];
    }, $r)]);
});

/** 영수증 정보 수정: 번호·주소·합산 대상·(keep 방식이면) 주민번호 */
route('POST', '/donors/{id}', function (int $id) {
    $a = require_receipt_staff();
    $d = row('SELECT * FROM ' . t('donors') . ' WHERE id = ?', [$id]);
    if (!$d) json_fail('not_found', '헌금자를 찾을 수 없습니다.', 404);
    $b = body();
    $no = array_key_exists('donorNo', $b) ? in_str($b, 'donorNo', 20) : (string)$d['donor_no'];
    if ($no !== '' && $no !== (string)$d['donor_no'] && val('SELECT COUNT(*) FROM ' . t('donors') . ' WHERE donor_no = ? AND id <> ?', [$no, $id])) json_fail('duplicate', '이미 쓰고 있는 헌금자 번호입니다.', 409);
    $addr = array_key_exists('address', $b) ? in_str($b, 'address', 255) : (string)$d['receipt_address'];
    $memo = array_key_exists('memo', $b) ? in_str($b, 'memo', 255) : (string)$d['memo'];
    $to = $d['receipt_to_donor_id'];
    if (array_key_exists('receiptTo', $b)) {
        $to = $b['receiptTo'] === null || $b['receiptTo'] === '' ? null : in_int($b, 'receiptTo', 1, PHP_INT_MAX, '합산 대상');
        if ($to === $id) $to = null;
        if ($to !== null) {
            $t2 = row('SELECT id, receipt_to_donor_id FROM ' . t('donors') . ' WHERE id = ?', [$to]);
            if (!$t2) json_fail('invalid', '합산 대상 헌금자를 찾을 수 없습니다.', 422);
            if ($t2['receipt_to_donor_id'] !== null) json_fail('invalid', '합산 대상이 다시 다른 사람에게 합산되고 있습니다. 대표가 될 사람을 직접 골라 주세요.', 422);
            if (val('SELECT COUNT(*) FROM ' . t('donors') . ' WHERE receipt_to_donor_id = ?', [$id])) json_fail('invalid', '이 사람에게 합산 중인 다른 분이 있어 다른 사람에게 합산할 수 없습니다.', 422);
        }
    }
    $enc = $d['rrn_enc'];
    if (array_key_exists('rrn', $b)) {
        if (rrn_mode() !== 'keep') json_fail('rrn_none', '이 교회는 주민등록번호를 보관하지 않는 방식입니다.', 409);
        $digits = preg_replace('/\D/', '', (string)$b['rrn']);
        if ($digits === '') $enc = null;
        else {
            if (!rrn_valid($digits)) json_fail('invalid', '주민등록번호가 올바르지 않습니다.', 422);
            $enc = rrn_encrypt($digits);
        }
        audit('주민번호 입력', $d['name'], $a);
    }
    q('UPDATE ' . t('donors') . ' SET donor_no=?, receipt_address=?, memo=?, receipt_to_donor_id=?, rrn_enc=? WHERE id=?', [$no ?: null, $addr ?: null, $memo ?: null, $to, $enc, $id]);
    change_log('donor', $id, 'receipt_info', null, '수정', $d['name']);
    json_out(null);
});

/** 같은 세대의 다른 헌금자 (합산 제안용. 묶을지는 사람이 정한다) */
route('GET', '/donors/{id}/household', function (int $id) {
    require_receipt_staff();
    $d = row('SELECT person_id FROM ' . t('donors') . ' WHERE id = ?', [$id]);
    if (!$d || !$d['person_id']) json_out(['items' => []]);
    $r = rows('SELECT dn.id, dn.name FROM ' . cmt('household_members') . ' m1 JOIN ' . cmt('household_members') . ' m2 ON m2.household_id = m1.household_id JOIN ' . t('donors') . ' dn ON dn.person_id = m2.person_id WHERE m1.person_id = ? AND dn.id <> ?', [$d['person_id'], $id]);
    json_out(['items' => array_map(fn($x) => ['id' => (int)$x['id'], 'name' => $x['name']], $r)]);
});

/** 과세 연도(1.1~12.31)의 영수증 대상 합계: 대표 헌금자별(가족 합산 포함) */
function receipt_groups(int $year): array
{
    $r = rows('SELECT COALESCE(d.receipt_to_donor_id, d.id) AS target, d.id AS did, d.name AS dname, MONTH(o.offering_date) AS m, SUM(o.amount) AS s
               FROM ' . t('offerings') . ' o JOIN ' . t('offering_batches') . " b ON b.id = o.batch_id AND b.status = '마감' JOIN " . t('income_accounts') . ' ia ON ia.id = o.income_account_id AND ia.receiptable = 1
               JOIN ' . t('donors') . ' d ON d.id = o.donor_id WHERE o.offering_date BETWEEN ? AND ? GROUP BY target, d.id, d.name, m', ["$year-01-01", "$year-12-31"]);
    $g = [];
    foreach ($r as $x) {
        $t = (int)$x['target'];
        $g[$t]['months'][(int)$x['m']] = ($g[$t]['months'][(int)$x['m']] ?? 0) + (int)$x['s'];
        $g[$t]['total'] = ($g[$t]['total'] ?? 0) + (int)$x['s'];
        $g[$t]['names'][(int)$x['did']] = $x['dname'];
    }
    return $g;
}

route('GET', '/receipts/preview', function () {
    require_receipt_staff();
    $year = in_int($_GET, 'year', 2000, 2100, '과세 연도');
    $keep = rrn_mode() === 'keep';
    $out = [];
    foreach (receipt_groups($year) as $tid => $g) {
        $d = row('SELECT * FROM ' . t('donors') . ' WHERE id = ?', [$tid]);
        $info = donor_info($d);
        $issued = row('SELECT id, doc_no, total FROM ' . t('receipts') . ' WHERE donor_id = ? AND tax_year = ? AND voided_at IS NULL', [$tid, $year]);
        $out[] = ['donorId' => $tid, 'name' => $d['name'], 'total' => $g['total'], 'members' => array_values($g['names']), 'address' => $info['address'], 'missingAddress' => $info['address'] === '',
            'missingRrn' => $keep && $d['rrn_enc'] === null, 'issued' => $issued ? ['id' => (int)$issued['id'], 'docNo' => $issued['doc_no'], 'total' => (int)$issued['total'], 'changed' => (int)$issued['total'] !== $g['total']] : null];
    }
    usort($out, fn($a, $b) => strcmp($a['name'], $b['name']));
    json_out(['year' => $year, 'rrnMode' => rrn_mode(), 'items' => $out]);
});

function receipt_form(): array
{
    return (json_decode((string)val('SELECT receipt_form FROM ' . t('settings') . ' WHERE id = 1'), true) ?: []) + ['line_mode' => 'year', 'allow_family' => true];
}

function next_receipt_no(int $year): string
{
    $k = 'receipt_seq_' . $year;
    q('INSERT INTO ' . t('meta') . " (k, v) VALUES (?, '0') ON DUPLICATE KEY UPDATE v = v", [$k]);
    $n = (int)val('SELECT v FROM ' . t('meta') . ' WHERE k = ? FOR UPDATE', [$k]) + 1;
    q('UPDATE ' . t('meta') . ' SET v = ? WHERE k = ?', [(string)$n, $k]);
    return sprintf('%d-%04d', $year, $n);
}

/** 발급: 선택한 대표 헌금자들의 영수증을 만든다. 번호 매기기와 대장 기록은 한 거래. 같은 내용이면 재발급(같은 번호), 금액이 달라졌으면 정정 발급(이전 것 무효). */
route('POST', '/receipts/issue', function () {
    $a = require_receipt_staff();
    $b = body();
    $year = in_int($b, 'year', 2000, 2100, '과세 연도');
    $ids = array_values(array_unique(array_map('intval', is_array($b['donorIds'] ?? null) ? $b['donorIds'] : [])));
    if (!$ids) json_fail('invalid', '발급할 헌금자를 고르세요.', 422);
    if (count($ids) > 600) json_fail('invalid', '한 번에 600명까지 발급할 수 있습니다.', 422);
    $church = json_decode((string)val('SELECT church FROM ' . t('settings') . ' WHERE id = 1'), true) ?: [];
    $form = receipt_form();
    $keep = rrn_mode() === 'keep';
    $groups = receipt_groups($year);
    $res = tx(function () use ($ids, $year, $groups, $church, $form, $keep, $a) {
        $out = ['issued' => 0, 'reissued' => 0, 'corrected' => 0, 'skipped' => []];
        foreach ($ids as $tid) {
            $g = $groups[$tid] ?? null;
            $d = row('SELECT * FROM ' . t('donors') . ' WHERE id = ?', [$tid]);
            if (!$g || !$d) { $out['skipped'][] = ['id' => $tid, 'why' => '영수증 대상 금액이 없습니다.']; continue; }
            $info = donor_info($d);
            if ($info['address'] === '') { $out['skipped'][] = ['id' => $tid, 'name' => $d['name'], 'why' => '주소가 없습니다. 교적 주소나 영수증 주소를 입력해 주세요.']; continue; }
            if ($keep && $d['rrn_enc'] === null) { $out['skipped'][] = ['id' => $tid, 'name' => $d['name'], 'why' => '주민등록번호가 입력되지 않았습니다.']; continue; }
            $lines = [];
            if ($form['line_mode'] === 'month') { ksort($g['months']); foreach ($g['months'] as $m => $s) $lines[] = ['label' => sprintf('%d년 %d월', $year, $m), 'amount' => $s]; }
            else $lines[] = ['label' => $year . '년 1월 ~ 12월', 'amount' => $g['total']];
            $snap = [
                'taxYear' => $year, 'name' => $d['name'], 'address' => $info['address'], 'rrnMasked' => $keep ? rrn_mask(rrn_decrypt((string)$d['rrn_enc'])) : ($info['birth6'] !== '' ? $info['birth6'] . '-*******' : ''), 'rrnMode' => $keep ? 'keep' : 'none',
                'church' => ['name' => $church['name'] ?? '', 'bizNo' => $church['bizNo'] ?? '', 'address' => $church['address'] ?? '', 'rep' => $church['pastorName'] ?? ''],
                'lines' => $lines, 'total' => $g['total'], 'included' => array_values($g['names']),
            ];
            $old = row('SELECT * FROM ' . t('receipts') . ' WHERE donor_id = ? AND tax_year = ? AND voided_at IS NULL FOR UPDATE', [$tid, $year]);
            if ($old && (int)$old['total'] === $g['total']) {
                q('UPDATE ' . t('receipts') . ' SET reissue_count = reissue_count + 1, snapshot = ? WHERE id = ?', [json_encode($snap, JSON_UNESCAPED_UNICODE), $old['id']]); // 재발급 때 주소·번호 방식의 고친 내용을 반영
                $out['reissued']++;
                continue;
            }
            $no = next_receipt_no($year);
            $rid = insert_id('INSERT INTO ' . t('receipts') . ' (doc_no, tax_year, donor_id, total, snapshot, issued_by, void_of) VALUES (?,?,?,?,?,?,?)', [$no, $year, $tid, $g['total'], json_encode($snap, JSON_UNESCAPED_UNICODE), $a['access']['id'], $old ? $old['id'] : null]);
            if ($old) { q('UPDATE ' . t('receipts') . ' SET voided_at = NOW() WHERE id = ?', [$old['id']]); $out['corrected']++; } else $out['issued']++;
            change_log('receipt', $rid, 'issue', null, "$no {$d['name']} {$g['total']}", $old ? '정정 발급' : '');
        }
        return $out;
    });
    audit('영수증 발급', "$year / " . ($res['issued'] + $res['corrected']) . '건', $a);
    json_out($res);
});

route('GET', '/receipts', function () {
    require_receipt_staff();
    $year = in_int($_GET, 'year', 2000, 2100, '과세 연도');
    $r = rows('SELECT r.id, r.doc_no, r.total, r.issued_at, r.voided_at, r.reissue_count, r.void_of, d.name FROM ' . t('receipts') . ' r JOIN ' . t('donors') . ' d ON d.id = r.donor_id WHERE r.tax_year = ? ORDER BY r.doc_no DESC LIMIT 1000', [$year]);
    json_out(['items' => array_map(fn($x) => ['id' => (int)$x['id'], 'docNo' => $x['doc_no'], 'name' => $x['name'], 'total' => (int)$x['total'], 'issuedAt' => substr((string)$x['issued_at'], 0, 10), 'void' => $x['voided_at'] !== null, 'reissued' => (int)$x['reissue_count'], 'correction' => $x['void_of'] !== null], $r)]);
});

/** 영수증 한 장(인쇄용). keep 방식이면 서버가 주민번호를 풀어 넣고 그때마다 기록한다. */
route('GET', '/receipts/{id}', function (int $id) {
    $a = require_receipt_staff();
    $r = row('SELECT r.*, d.rrn_enc FROM ' . t('receipts') . ' r JOIN ' . t('donors') . ' d ON d.id = r.donor_id WHERE r.id = ?', [$id]);
    if (!$r) json_fail('not_found', '영수증을 찾을 수 없습니다.', 404);
    $snap = json_decode((string)$r['snapshot'], true);
    $full = null;
    if (($snap['rrnMode'] ?? '') === 'keep' && $r['rrn_enc'] !== null && ($_GET['rrn'] ?? '') === '1') {
        $full = rrn_decrypt((string)$r['rrn_enc']);
        $full = strlen($full) === 13 ? substr($full, 0, 6) . '-' . substr($full, 6) : null;
        audit('주민번호 사용', '영수증 ' . $r['doc_no'], $a);
    }
    audit('영수증 열람', $r['doc_no'], $a);
    json_out(['id' => (int)$r['id'], 'docNo' => $r['doc_no'], 'issuedAt' => substr((string)$r['issued_at'], 0, 10), 'void' => $r['voided_at'] !== null, 'snapshot' => $snap, 'rrnFull' => $full]);
});

/** 세무서 제출용 발급 명세 (CSV, 엑셀에서 열림). 주민번호는 마스킹한 값만 낸다. */
route('GET', '/receipts-export', function () {
    $a = require_receipt_staff();
    $year = in_int($_GET, 'year', 2000, 2100, '과세 연도');
    $r = rows('SELECT r.doc_no, r.total, r.issued_at, r.snapshot FROM ' . t('receipts') . ' r WHERE r.tax_year = ? AND r.voided_at IS NULL ORDER BY r.doc_no', [$year]);
    $esc = fn($v) => '"' . str_replace('"', '""', (string)$v) . '"';
    $lines = ["\xEF\xBB\xBF" . implode(',', ['발급번호', '성명', '주민등록번호(마스킹)', '주소', '기부금액', '발급일'])];
    foreach ($r as $x) {
        $s = json_decode((string)$x['snapshot'], true);
        $lines[] = implode(',', [$esc($x['doc_no']), $esc($s['name']), $esc($s['rrnMasked']), $esc($s['address']), (int)$x['total'], $esc(substr((string)$x['issued_at'], 0, 10))]);
    }
    audit('영수증 발급명세 내려받기', (string)$year, $a);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="receipts-' . $year . '.csv"');
    header('X-Content-Type-Options: nosniff');
    echo implode("\r\n", $lines) . "\r\n";
    exit;
});
