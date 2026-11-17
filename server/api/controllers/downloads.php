<?php
declare(strict_types=1);

// 내려받기 사유: 파일을 내려받기 전에 사유를 받아 1회용(60초) 열쇠를 주고, 파일 주소는 그 열쇠가 있어야 열린다.
// 사유는 접속 기록(access_log)의 대상 칸에 남는다. 열쇠는 로그인 세션 안에만 두므로 별도 표가 없다.

const FM_DL_PURPOSES = ['세무 신고', '감사 제출', '결산 보고', '백업 보관', '기타'];
const FM_DL_TTL = 60;
/** 내려받기 종류 → 사유 필수 여부. 개인 이름이 들어가는 자료(영수증 발급명세·백업)는 사유가 필수다. */
const FM_DL_KINDS = ['receipts' => true, 'backup' => true, 'ledger' => false, 'settlement' => false, 'spending' => false];

route('POST', '/downloads/ticket', function () {
    $a = require_account();
    $b = body();
    $kind = in_enum($b, 'kind', array_keys(FM_DL_KINDS), '내려받기 종류');
    $purpose = (string)($b['purpose'] ?? '');
    if ($purpose === '') {
        if (FM_DL_KINDS[$kind]) json_fail('purpose_required', '내려받는 사유를 골라 주세요.', 422);
    } else {
        if (!in_array($purpose, FM_DL_PURPOSES, true)) json_fail('invalid', '사유 값이 올바르지 않습니다.', 422);
        if ($purpose === '기타') {
            $note = in_str($b, 'note', 100, true, '기타 사유');
            $purpose = '기타: ' . $note;
        }
    }
    $tok = bin2hex(random_bytes(16));
    $_SESSION['dl'] = array_filter($_SESSION['dl'] ?? [], fn($x) => $x['exp'] > time());
    $_SESSION['dl'][hash('sha256', $tok)] = ['kind' => $kind, 'purpose' => $purpose, 'exp' => time() + FM_DL_TTL];
    json_out(['token' => $tok, 'ttl' => FM_DL_TTL]);
});

/** 내려받기 요청에서 열쇠를 확인하고(한 번만 씀) 사유 문구를 돌려준다. 없거나 틀리면 거절한다. */
function dl_take(string $kind): string
{
    $tok = (string)($_GET['dl'] ?? '');
    $h = hash('sha256', $tok);
    $d = $tok !== '' ? ($_SESSION['dl'][$h] ?? null) : null;
    if ($tok !== '') unset($_SESSION['dl'][$h]);
    if (!$d || $d['kind'] !== $kind || $d['exp'] < time()) json_fail('dl_required', '내려받기 사유를 먼저 입력해 주세요. 화면의 내려받기 단추를 다시 눌러 주세요.', 403);
    return $d['purpose'] !== '' ? ' · 사유: ' . $d['purpose'] : ' · 사유 없음';
}
