<?php
declare(strict_types=1);

// 로그인·로그아웃·내 정보·재정 사용 신청. 가입과 비밀번호 변경은 없다. (교적에서 한다.)

/** 15분 안에 실패가 많으면 잠시 막는다. 아이디 기준 5번, 접속 주소 기준 20번. */
function login_throttled(string $loginId): bool
{
    $byId = (int)val('SELECT COUNT(*) FROM ' . t('login_attempts') . ' WHERE login_id = ? AND at > (NOW() - INTERVAL 15 MINUTE)', [$loginId]);
    $byIp = (int)val('SELECT COUNT(*) FROM ' . t('login_attempts') . ' WHERE ip = ? AND at > (NOW() - INTERVAL 15 MINUTE)', [client_ip()]);
    return $byId >= 5 || $byIp >= 20;
}

route('GET', '/auth/me', function () {
    check_origin();
    $a = current_account();
    $csrf = csrf_token();
    $church = (string)(json_decode((string)val('SELECT church FROM ' . t('settings') . ' WHERE id = 1'), true)['name'] ?? '');
    json_out(['church' => $church, 'csrf' => $csrf, 'me' => $a ? account_payload($a) : null]);
});

route('POST', '/auth/login', function () {
    check_origin();
    session_boot();
    verify_csrf();
    $b = body();
    $loginId = in_str($b, 'loginId', 50, true, '아이디');
    $pw = (string)($b['password'] ?? '');
    if ($pw === '' || strlen($pw) > 200) json_fail('invalid', '비밀번호를 입력해 주세요.', 422);
    if (login_throttled($loginId)) json_fail('throttled', '로그인에 여러 번 실패해서 잠시 막았습니다. 15분 뒤에 다시 시도해 주세요.', 429);

    $u = row('SELECT id, password_hash, status, is_demo, session_epoch FROM ' . cmt('users') . ' WHERE login_id = ?', [$loginId]);
    if (!$u) {
        password_verify($pw, '$2y$10$abcdefghijklmnopqrstuuABCDEFGHIJKLMNOPQRSTUVWXYZ01234'); // 아이디가 있는지 없는지 응답 시간으로 알 수 없게
    }
    if (!$u || !password_verify($pw, $u['password_hash']) || (int)$u['is_demo'] === 1) {
        q('INSERT INTO ' . t('login_attempts') . ' (ip, login_id) VALUES (?, ?)', [client_ip(), $loginId]);
        json_fail('invalid_login', '아이디 또는 비밀번호가 맞지 않습니다.', 401);
    }
    if ($u['status'] !== '사용') {
        json_fail('inactive', $u['status'] === '정지' ? '정지된 계정입니다. 교적 관리자에게 문의해 주세요.' : '교적 관리에서 아직 승인되지 않은 계정입니다.', 403);
    }

    session_regenerate_id(true);
    $_SESSION['cm_uid'] = (int)$u['id'];
    $_SESSION['epoch'] = (int)$u['session_epoch'];
    $_SESSION['seen'] = time();
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    q('DELETE FROM ' . t('login_attempts') . ' WHERE login_id = ?', [$loginId]);
    if (random_int(1, 20) === 1) q('DELETE FROM ' . t('login_attempts') . ' WHERE at < (NOW() - INTERVAL 1 DAY)');
    q('UPDATE ' . t('access') . ' SET last_login_at = NOW() WHERE cm_user_id = ?', [$u['id']]);
    $a = current_account();
    audit('로그인', '', $a);
    json_out(['csrf' => $_SESSION['csrf'], 'me' => account_payload($a)]);
});

route('POST', '/auth/logout', function () {
    check_origin();
    session_boot();
    if (current_account()) verify_csrf();
    audit('로그아웃');
    $_SESSION = [];
    session_destroy();
    json_out(null);
});

/** 재정 사용 신청: 교적 계정으로 로그인한 사람이, 아직 신청하지 않았을 때 한 번 */
route('POST', '/auth/request-access', function () {
    $a = require_account(false);
    if ($a['state'] !== 'none') json_fail('invalid_state', '이미 신청했거나 신청할 수 없는 상태입니다.', 409);
    $reason = in_str(body(), 'reason', 255, false, '신청 내용');
    q(
        'INSERT INTO ' . t('access') . ' (cm_user_id, login_id, name, person_id, status, reason) VALUES (?,?,?,?,?,?)',
        [$a['cm']['id'], $a['cm']['login_id'], $a['cm']['name'], $a['cm']['person_id'], '신청 대기', $reason]
    );
    audit('재정 사용 신청', $a['cm']['login_id'], $a);
    json_out(null);
});

/** 비밀번호 다시 확인 (교적 비밀번호). 맞으면 10분 동안 중요한 작업을 할 수 있다. */
route('POST', '/auth/reconfirm', function () {
    $a = require_account();
    $pw = (string)(body()['password'] ?? '');
    $loginId = $a['cm']['login_id'];
    if ($pw === '' || strlen($pw) > 200) json_fail('invalid', '비밀번호를 입력해 주세요.', 422);
    if (login_throttled($loginId)) json_fail('throttled', '여러 번 틀려서 잠시 막았습니다. 15분 뒤에 다시 시도해 주세요.', 429);
    $hash = (string)val('SELECT password_hash FROM ' . cmt('users') . ' WHERE id = ?', [$a['cm']['id']]);
    if (!password_verify($pw, $hash)) {
        q('INSERT INTO ' . t('login_attempts') . ' (ip, login_id) VALUES (?, ?)', [client_ip(), $loginId]);
        json_fail('invalid_login', '비밀번호가 맞지 않습니다.', 401);
    }
    $_SESSION['reconfirm_at'] = time();
    json_out(null);
});

/**
 * 교적에서 '재정 열기'를 눌러 넘어오는 1회용 입장권으로 로그인한다. (설계 3.4, docs/ch-manage-changes.md)
 * 입장권은 무작위 값의 해시만 교적(cm_sso_tickets)에 있고 30초 동안만 유효하다. 확인하자마자 지운다 — 재정이 교적 표에 쓰는 유일한 경우.
 * 교적에 이 표가 없으면(아직 교적을 고치지 않았으면) 404 를 돌려주고 일반 로그인은 그대로 쓸 수 있다.
 */
route('POST', '/auth/sso', function () {
    check_origin();
    session_boot();
    verify_csrf();
    $ticket = (string)(body()['ticket'] ?? '');
    if (!preg_match('/^[A-Za-z0-9_\-]{32,128}$/', $ticket)) json_fail('invalid', '입장권이 올바르지 않습니다.', 422);
    if (!val("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?", [fm_config()['cm_prefix'] . 'sso_tickets'])) json_fail('sso_unavailable', '교적 관리가 아직 이 기능을 지원하지 않습니다. 아이디·비밀번호로 로그인해 주세요.', 404);
    if (login_throttled('sso')) json_fail('throttled', '잠시 뒤에 다시 시도해 주세요.', 429);
    $uid = tx(function () use ($ticket) {
        $r = row('SELECT id, user_id FROM ' . cmt('sso_tickets') . ' WHERE token_hash = ? AND expires_at > NOW() FOR UPDATE', [hash('sha256', $ticket)]);
        if ($r) q('DELETE FROM ' . cmt('sso_tickets') . ' WHERE id = ?', [$r['id']]); // 한 번 쓰면 끝
        q('DELETE FROM ' . cmt('sso_tickets') . ' WHERE expires_at < (NOW() - INTERVAL 1 DAY)');
        return $r ? (int)$r['user_id'] : 0;
    });
    if (!$uid) {
        q('INSERT INTO ' . t('login_attempts') . ' (ip, login_id) VALUES (?, ?)', [client_ip(), 'sso']);
        json_fail('invalid_ticket', '입장권이 만료되었거나 이미 사용되었습니다. 교적에서 다시 눌러 주세요.', 401);
    }
    $u = row('SELECT id, login_id, status, is_demo, session_epoch FROM ' . cmt('users') . ' WHERE id = ?', [$uid]);
    if (!$u || $u['status'] !== '사용' || (int)$u['is_demo'] === 1) json_fail('inactive', '사용할 수 없는 계정입니다.', 403);
    session_regenerate_id(true);
    $_SESSION['cm_uid'] = (int)$u['id'];
    $_SESSION['epoch'] = (int)$u['session_epoch'];
    $_SESSION['seen'] = time();
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    q('UPDATE ' . t('access') . ' SET last_login_at = NOW() WHERE cm_user_id = ?', [$u['id']]);
    $a = current_account();
    audit('로그인(교적에서 열기)', '', $a);
    json_out(['csrf' => $_SESSION['csrf'], 'me' => account_payload($a)]);
});
