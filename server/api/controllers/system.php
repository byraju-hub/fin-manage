<?php
declare(strict_types=1);

// 시스템 상태와 첫 화면 자료. (M0: 교적 연결 상태와 교인 수)

/** 첫 화면: 교적 연결이 정상인지, 교인은 몇 명인지. 재정 사용이 승인된 사람만. */
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
    ]);
});
