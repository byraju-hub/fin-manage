<?php
declare(strict_types=1);

// 교회 정보·직인, 영수증 서식, 예산 초과 처리, 주민번호 방식 바꾸기·정리. (재정 관리자)

function settings_church(): array
{
    return json_decode((string)val('SELECT church FROM ' . t('settings') . ' WHERE id = 1'), true) ?: [];
}

function seal_file(): ?string
{
    $f = glob(FM_DATA . '/seal.*');
    return $f ? $f[0] : null;
}

/** 교회 정보: 영수증·결의서 인쇄에 쓰는 이름·고유번호·주소·대표자·전화. 설치 때 교적에서 복사한 값을 여기서 고친다. */
route('POST', '/settings/church', function () {
    $a = require_admin();
    $b = body();
    $c = settings_church();
    $new = $c;
    foreach (['name' => ['교회 이름', 60, true], 'bizNo' => ['고유번호', 20, false], 'address' => ['소재지', 200, false], 'pastorName' => ['대표자', 50, false], 'phone' => ['전화', 30, false]] as $k => [$label, $max, $req]) {
        if (array_key_exists($k, $b)) $new[$k] = in_str($b, $k, $max, $req, $label);
    }
    if (isset($new['bizNo']) && $new['bizNo'] !== '' && !preg_match('/^[0-9\-]{5,20}$/', $new['bizNo'])) json_fail('invalid', '고유번호는 숫자와 - 만 쓸 수 있어요. (예: 123-82-12345)', 422);
    q('UPDATE ' . t('settings') . ' SET church = ?, updated_by = ? WHERE id = 1', [json_encode($new, JSON_UNESCAPED_UNICODE), $a['access']['id']]);
    change_log('settings', 'church', 'church', $c, $new);
    json_out(null);
});

/** 직인 올리기(PNG·JPG, 작은 파일). 영수증을 인쇄할 때만 서버가 꺼내 보여 준다. */
route('POST', '/settings/seal', function () {
    $a = require_admin();
    [$bin, $mime, $ext] = proof_decode((string)(body()['data'] ?? ''));
    if (!in_array($mime, ['image/png', 'image/jpeg'], true)) json_fail('invalid', '직인은 PNG 또는 JPG 이미지만 올릴 수 있어요.', 422);
    foreach (glob(FM_DATA . '/seal.*') ?: [] as $old) @unlink($old);
    if (file_put_contents(FM_DATA . '/seal.' . $ext, $bin) === false) json_fail('server', '파일을 저장하지 못했습니다.', 500);
    q('UPDATE ' . t('settings') . ' SET seal_path = ? WHERE id = 1', ['seal.' . $ext]);
    audit('직인 올리기', '', $a);
    json_out(null);
});

route('POST', '/settings/seal/delete', function () {
    $a = require_admin();
    foreach (glob(FM_DATA . '/seal.*') ?: [] as $old) @unlink($old);
    q('UPDATE ' . t('settings') . ' SET seal_path = NULL WHERE id = 1');
    audit('직인 지우기', '', $a);
    json_out(null);
});

route('GET', '/settings/seal', function () {
    $a = require_account();
    if (!is_admin_account($a) && !has_role($a, '재정 담당')) json_fail('forbidden', '권한이 없습니다.', 403);
    $f = seal_file();
    if (!$f) json_fail('not_found', '직인이 없습니다.', 404);
    header('Content-Type: ' . (str_ends_with($f, '.png') ? 'image/png' : 'image/jpeg'));
    header('Cache-Control: private, max-age=60');
    header('X-Content-Type-Options: nosniff');
    readfile($f);
    exit;
});

route('POST', '/settings/receipt-form', function () {
    $a = require_admin();
    $b = body();
    $form = receipt_form();
    $new = $form;
    if (array_key_exists('lineMode', $b)) $new['line_mode'] = in_enum($b, 'lineMode', ['year', 'month'], '내용 줄 방식');
    if (array_key_exists('code', $b)) {
        $code = in_str($b, 'code', 4);
        if ($code !== '' && !preg_match('/^\d{1,4}$/', $code)) json_fail('invalid', '기부금 구분 코드는 숫자로 적어 주세요. (종교단체는 보통 41)', 422);
        $new['code'] = $code ?: '41';
    }
    if (array_key_exists('allowFamily', $b)) $new['allow_family'] = (bool)$b['allowFamily'];
    q('UPDATE ' . t('settings') . ' SET receipt_form = ?, updated_by = ? WHERE id = 1', [json_encode($new), $a['access']['id']]);
    change_log('settings', 'receipt_form', 'receipt_form', $form, $new);
    json_out(null);
});

/** 예산 초과 결의서: warn(경고하고 담임목사 단계 추가) / block(상신 차단) */
route('POST', '/settings/over-budget', function () {
    $a = require_admin();
    $mode = in_enum(body(), 'mode', ['warn', 'block'], '처리 방식');
    $sec = json_decode((string)val('SELECT security FROM ' . t('settings') . ' WHERE id = 1'), true) ?: [];
    $old = $sec['over_budget'] ?? 'warn';
    $sec['over_budget'] = $mode;
    q('UPDATE ' . t('settings') . ' SET security = ?, updated_by = ? WHERE id = 1', [json_encode($sec), $a['access']['id']]);
    change_log('settings', 'over_budget', 'over_budget', $old, $mode);
    json_out(null);
});

function write_config(array $cfg): void
{
    $f = FM_DATA . '/config.php';
    $tmp = $f . '.tmp';
    if (file_put_contents($tmp, "<?php\nreturn " . var_export($cfg, true) . ";\n", LOCK_EX) === false || !rename($tmp, $f)) json_fail('server', '설정 파일을 저장하지 못했습니다. data 폴더의 쓰기 권한을 확인해 주세요.', 500);
}

/**
 * 주민번호 방식 바꾸기.
 *  keep → none: 저장된 번호를 모두 지운다(되돌릴 수 없음). 확인 문구 '번호 삭제' 필요.
 *  none → keep: 암호화 열쇠가 없으면 새로 만들어 설정 파일에 넣고, 화면에 한 번만 보여 준다. 번호는 그 뒤 헌금자별로 입력한다.
 */
route('POST', '/settings/rrn-mode', function () {
    $a = require_admin();
    require_reconfirm();
    $b = body();
    $to = in_enum($b, 'mode', ['keep', 'none'], '방식');
    $cur = rrn_mode();
    if ($to === $cur) json_fail('invalid', '이미 그 방식입니다.', 409);
    $key = null;
    if ($to === 'none') {
        if (($b['confirm'] ?? '') !== '번호 삭제') json_fail('confirm', "저장된 주민등록번호가 모두 지워집니다. 확인 문구 '번호 삭제'를 입력해 주세요.", 422);
        $n = (int)val('SELECT COUNT(*) FROM ' . t('donors') . ' WHERE rrn_enc IS NOT NULL');
        tx(function () use ($a) {
            q('UPDATE ' . t('donors') . ' SET rrn_enc = NULL');
            q("UPDATE " . t('settings') . " SET rrn_mode = 'none', updated_by = ? WHERE id = 1", [$a['access']['id']]);
        });
        change_log('settings', 'rrn_mode', 'rrn_mode', 'keep', 'none', "번호 {$n}건 삭제");
    } else {
        $cfg = fm_config();
        if (empty($cfg['rrn_key'])) {
            $key = base64_encode(random_bytes(32));
            $cfg['rrn_key'] = $key;
            write_config($cfg);
        }
        q("UPDATE " . t('settings') . " SET rrn_mode = 'keep', updated_by = ? WHERE id = 1", [$a['access']['id']]);
        change_log('settings', 'rrn_mode', 'rrn_mode', 'none', 'keep');
    }
    audit('주민번호 방식 변경', "$cur → $to", $a);
    json_out(['mode' => $to, 'newKey' => $key]);
});

/** 마지막 헌금 뒤 N년이 지난 헌금자의 주민번호 지우기. apply=false 이면 대상 수만 알려 준다. */
route('POST', '/settings/rrn-purge', function () {
    $a = require_admin();
    $b = body();
    $years = in_int($b, 'years', 1, 30, '기간(년)');
    $cut = date('Y-m-d', strtotime("-{$years} years"));
    $ids = array_column(rows('SELECT d.id FROM ' . t('donors') . ' d WHERE d.rrn_enc IS NOT NULL AND COALESCE((SELECT MAX(o.offering_date) FROM ' . t('offerings') . ' o WHERE o.donor_id = d.id), DATE(d.created_at)) < ?', [$cut]), 'id');
    if (!empty($b['apply']) && $ids) {
        require_reconfirm();
        q('UPDATE ' . t('donors') . ' SET rrn_enc = NULL WHERE id IN (' . marks($ids) . ')', $ids);
        audit('주민번호 기간 지난 것 삭제', count($ids) . "건 ({$years}년)", $a);
    }
    json_out(['count' => count($ids), 'applied' => !empty($b['apply']) && $ids]);
});
