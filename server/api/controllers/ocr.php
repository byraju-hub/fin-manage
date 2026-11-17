<?php
declare(strict_types=1);

// 영수증 사진 글자 읽기(선택 기능, 기본 꺼짐). 관리자가 켜고 API 열쇠를 넣었을 때만 동작한다.
// 켜면 영수증 사진이 외부 AI 업체(국외)로 전송되므로 개인정보 처리방침에 적혀 있고, 사진을 올릴 때마다 사람이 결과를 확인해 채운다.
// 사진은 읽은 뒤 서버에 저장하지 않는다(결의서 증빙으로 따로 올릴 때만 저장).

const FM_OCR_DEFAULT_MODEL = 'claude-haiku-5-5';
const FM_OCR_DEFAULT_ENDPOINT = 'https://api.anthropic.com/v1/messages';
const FM_OCR_DAILY_LIMIT = 60;

/** API 열쇠는 DB(백업에 실림)가 아니라 data 폴더의 파일에 둔다. 웹에서는 data 폴더가 막혀 있다. */
function ocr_key_path(): string
{
    return FM_DATA . '/ocr.key';
}

function ocr_key_read(): string
{
    $k = @file_get_contents(ocr_key_path());
    return $k === false ? '' : trim($k);
}

function ocr_config(): array
{
    $v = json_decode((string)val('SELECT v FROM ' . t('meta') . " WHERE k = 'ocr'"), true);
    $c = (is_array($v) ? $v : []) + ['enabled' => false, 'model' => FM_OCR_DEFAULT_MODEL, 'endpoint' => FM_OCR_DEFAULT_ENDPOINT];
    $c['has_key'] = is_file(ocr_key_path()) && ocr_key_read() !== '';
    return $c;
}

function ocr_enabled(): bool
{
    $c = ocr_config();
    return !empty($c['enabled']) && $c['has_key'];
}

route('GET', '/settings/ocr', function () {
    require_admin();
    $c = ocr_config();
    json_out(['enabled' => (bool)$c['enabled'], 'hasKey' => $c['has_key'], 'model' => $c['model']]);
});

route('POST', '/settings/ocr', function () {
    $a = require_admin();
    require_reconfirm();
    $b = body();
    $c = ocr_config();
    if (isset($b['apiKey']) && is_string($b['apiKey']) && trim($b['apiKey']) !== '') {
        $k = trim($b['apiKey']);
        if (!preg_match('/^[A-Za-z0-9_\-]{20,200}$/', $k)) json_fail('invalid', 'API 열쇠 형식이 올바르지 않습니다.', 422);
        if (@file_put_contents(ocr_key_path(), $k) === false) json_fail('server', '열쇠를 저장하지 못했습니다. data 폴더 쓰기 권한을 확인해 주세요.', 500);
        @chmod(ocr_key_path(), 0600);
        $c['has_key'] = true;
    }
    if (!empty($b['clearKey'])) { @unlink(ocr_key_path()); $c['has_key'] = false; $c['enabled'] = false; }
    if (array_key_exists('model', $b)) {
        $m = in_str($b, 'model', 60);
        if ($m !== '' && !preg_match('/^[A-Za-z0-9._\-]{3,60}$/', $m)) json_fail('invalid', '모델 이름이 올바르지 않습니다.', 422);
        $c['model'] = $m ?: FM_OCR_DEFAULT_MODEL;
    }
    if (array_key_exists('enabled', $b)) {
        $want = (bool)$b['enabled'];
        if ($want && !$c['has_key']) json_fail('no_key', '켜려면 API 열쇠를 먼저 넣어 주세요.', 422);
        if ($want && empty($b['consent'])) json_fail('consent', '영수증 사진이 외부 AI 업체(국외)로 전송되는 것을 확인하고 동의해야 켤 수 있어요.', 422);
        $c['enabled'] = $want;
    }
    $save = $c;
    unset($save['has_key']);
    q('INSERT INTO ' . t('meta') . " (k, v) VALUES ('ocr', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)", [json_encode($save)]);
    audit('영수증 글자 읽기 설정', ($c['enabled'] ? '켬' : '끔') . ($c['has_key'] ? ' · 열쇠 있음' : ' · 열쇠 없음'), $a);
    json_out(['enabled' => (bool)$c['enabled'], 'hasKey' => $c['has_key'], 'model' => $c['model']]);
});

/** 맨 아래 단계 카테고리 이름(제안용) → id */
function ocr_leaf_categories(): array
{
    $cats = categories_all();
    $leaf = [];
    foreach ($cats as $c) {
        if (!$c['active'] || array_filter($cats, fn($k) => $k['parentId'] === $c['id'] && $k['active'])) continue;
        $leaf[$c['id']] = $c['name'];
    }
    return array_slice($leaf, 0, 150, true);
}

/** 외부 AI 호출. 시험에서는 로컬 주소(127.0.0.1)의 가짜 서버로 바꿔 쓴다. */
function ocr_call(array $cfg, string $mime, string $b64, array $leaf): array
{
    $endpoint = (string)$cfg['endpoint'];
    $u = parse_url($endpoint);
    $local = in_array($u['host'] ?? '', ['127.0.0.1', 'localhost'], true);
    if (($u['scheme'] ?? '') !== 'https' && !$local) json_fail('server', '글자 읽기 주소 설정이 올바르지 않습니다.', 500);
    $key = ocr_key_read();
    if ($key === '') json_fail('no_key', '글자 읽기 열쇠를 읽지 못했습니다. 관리자가 다시 넣어 주세요.', 500);
    $names = $leaf ? implode(', ', array_values($leaf)) : '';
    $prompt = "이 사진은 한국 교회의 지출 영수증입니다. 보이는 내용만 읽어서 JSON 한 개만 답하세요(설명 금지). 모르는 값은 null.\n"
        . '{"date":"YYYY-MM-DD","vendor":"가맹점명","bizNo":"사업자등록번호(숫자와 -)","total":합계금액(원, 정수),"payMethod":"법인카드|개인카드|현금|계좌이체 중 하나 또는 null","cardLast4":"카드 끝 4자리 또는 null","summary":"산 물건을 15자 이내로 요약","category":"아래 목록 중 가장 알맞은 이름 하나 또는 null"}'
        . ($names !== '' ? "\n카테고리 목록: $names" : '') . "\n카드번호 전체나 개인정보는 적지 마세요.";
    $payload = json_encode([
        'model' => (string)$cfg['model'], 'max_tokens' => 500,
        'messages' => [['role' => 'user', 'content' => [['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => $b64]], ['type' => 'text', 'text' => $prompt]]]],
    ], JSON_UNESCAPED_UNICODE);
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 40, CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER => ['content-type: application/json', 'x-api-key: ' . $key, 'anthropic-version: 2023-06-01']]);
    $res = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($res === false || $code < 200 || $code >= 300) {
        fm_log('error', 'ocr upstream http ' . $code);
        json_fail('ocr_failed', $code === 401 || $code === 403 ? '글자 읽기 열쇠가 올바르지 않아요. 관리자에게 알려 주세요.' : '글자 읽기 서비스에 연결하지 못했어요. 잠시 뒤 다시 하거나 직접 입력해 주세요.', 502);
    }
    $j = json_decode((string)$res, true);
    $text = '';
    foreach (($j['content'] ?? []) as $part) if (($part['type'] ?? '') === 'text') $text .= (string)$part['text'];
    if (!preg_match('/\{.*\}/su', $text, $m)) json_fail('ocr_failed', '영수증에서 내용을 읽지 못했어요. 직접 입력해 주세요.', 422);
    $d = json_decode($m[0], true);
    if (!is_array($d)) json_fail('ocr_failed', '영수증에서 내용을 읽지 못했어요. 직접 입력해 주세요.', 422);
    return $d;
}

/** 사진 한 장을 읽어 지출 입력 칸에 채울 값을 돌려준다. 결과는 반드시 사람이 확인한다. */
route('POST', '/receipts-ocr', function () {
    $a = require_account();
    if (!ocr_enabled()) json_fail('ocr_off', '영수증 글자 읽기를 쓰고 있지 않아요. 직접 입력해 주세요.', 404);
    if (!$a['cm']['person_id']) json_fail('nolink', '교인과 연결된 계정만 쓸 수 있습니다.', 403);
    $used = (int)val('SELECT COUNT(*) FROM ' . t('access_log') . " WHERE action = '영수증 글자 읽기' AND at > DATE_SUB(NOW(), INTERVAL 1 DAY) AND access_id = ?", [$a['access']['id']]);
    if ($used >= FM_OCR_DAILY_LIMIT) json_fail('rate_limited', '오늘 쓸 수 있는 횟수를 넘었어요. 직접 입력해 주세요.', 429);
    [$bin, $mime] = proof_decode((string)(body()['data'] ?? ''));
    if (!str_starts_with($mime, 'image/')) json_fail('invalid', '사진(JPG·PNG·WEBP)만 읽을 수 있어요. PDF는 직접 입력해 주세요.', 422);
    audit('영수증 글자 읽기', '');
    $leaf = ocr_leaf_categories();
    $d = ocr_call(ocr_config(), $mime, base64_encode($bin), $leaf);
    $warn = [];
    $date = is_string($d['date'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d['date']) && strtotime($d['date']) ? $d['date'] : null;
    if ($date && $date > today_str()) { $warn[] = '날짜가 오늘보다 뒤예요. 확인해 주세요.'; }
    if ($date && fiscal_year_of($date) === null) $warn[] = '이 날짜가 속한 회계연도가 없어요.';
    $total = isset($d['total']) && is_numeric($d['total']) ? (int)$d['total'] : null;
    if ($total !== null && ($total < 1 || $total > 9_000_000_000_000)) $total = null;
    $pm = in_array($d['payMethod'] ?? null, ['법인카드', '개인카드', '현금', '계좌이체'], true) ? $d['payMethod'] : null;
    $catId = null;
    if (is_string($d['category'] ?? null)) foreach ($leaf as $id => $name) if ($name === trim($d['category'])) { $catId = $id; break; }
    $clean = fn($v, int $max) => is_string($v) && trim($v) !== '' ? mb_substr(strip_tags(trim($v)), 0, $max) : null;
    $biz = is_string($d['bizNo'] ?? null) && preg_match('/^[0-9\-]{5,20}$/', $d['bizNo']) ? $d['bizNo'] : null;
    $c4 = is_string($d['cardLast4'] ?? null) && preg_match('/^\d{4}$/', $d['cardLast4']) ? $d['cardLast4'] : null;
    json_out(['date' => $date, 'vendor' => $clean($d['vendor'] ?? null, 100), 'bizNo' => $biz, 'total' => $total, 'payMethod' => $pm, 'cardLast4' => $c4, 'summary' => $clean($d['summary'] ?? null, 60), 'categoryId' => $catId, 'warnings' => $warn, 'confirm' => true]);
});
