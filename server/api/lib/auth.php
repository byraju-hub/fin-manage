<?php
declare(strict_types=1);

// 통합 로그인: 계정과 비밀번호는 교적(cm_users)이 주인이다. 재정에는 가입도 비밀번호도 없다.
// 재정이 따로 정하는 것은 "이 교적 계정이 재정을 써도 되는가"(fm_access)와 역할(fm_access_roles)뿐이다.
//
// 상태(state): active 사용 / pending 승인 대기 / none 신청 전 / suspended 정지 / nolink 교인과 연결 안 됨 / must_change 교적에서 비밀번호를 바꿔야 함

const SESSION_IDLE_SECONDS = 30 * 60; // 30분 동안 쓰지 않으면 로그아웃
const FM_ROLES = ['재정 담당', '재정위원장', '담임목사', '계수 위원', '감사', '열람'];
const FM_DONOR_ROLES = ['재정 담당', '재정위원장', '담임목사', '감사']; // 개인별 헌금 열람 스위치를 켤 수 있는 역할 (열람·계수 위원은 불가)

function session_boot(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    // 같은 도메인의 다른 프로그램(/cm, /dm)과 쿠키가 섞이지 않도록 이 사이트 폴더(예: /fm)에만 쿠키를 보낸다.
    $base = fm_site_base();
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $dir = FM_DATA . '/sessions';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    if (is_dir($dir) && is_writable($dir)) session_save_path($dir);
    ini_set('session.gc_maxlifetime', (string)(SESSION_IDLE_SECONDS * 4));
    session_name('fm_sid');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => $base === '' ? '/' : $base,
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
    if (isset($_SESSION['cm_uid'], $_SESSION['seen']) && time() - (int)$_SESSION['seen'] > SESSION_IDLE_SECONDS) {
        $_SESSION = [];
    }
    if (isset($_SESSION['cm_uid'])) $_SESSION['seen'] = time();
}

function csrf_token(): string
{
    session_boot();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}

function is_unsafe_method(): bool
{
    return !in_array(req_method(), ['GET', 'HEAD', 'OPTIONS'], true);
}

/** 다른 사이트에서 보낸 요청이면 막는다 (Origin 의 주소가 이 사이트와 다를 때). */
function check_origin(): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin === '') return;
    $oh = strtolower((string)parse_url($origin, PHP_URL_HOST));
    $hh = strtolower(preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? '')));
    if ($oh !== $hh) json_fail('forbidden_origin', '허용되지 않는 요청입니다.', 403);
}

function verify_csrf(): void
{
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $have = $_SESSION['csrf'] ?? '';
    if ($have === '' || !is_string($sent) || !hash_equals($have, $sent)) {
        json_fail('csrf', '페이지가 오래되었습니다. 새로고침한 뒤 다시 시도해 주세요.', 403);
    }
}

/**
 * 로그인한 교적 계정과 재정 사용 정보. 교적 계정이 정지되었거나, 비밀번호를 바꿔 세션 번호가 달라졌으면 로그아웃시킨다.
 * @return array{cm: array<string, mixed>, access: ?array<string, mixed>, roles: string[], state: string}|null
 */
function current_account(): ?array
{
    static $a = false;
    if ($a !== false) return $a;
    session_boot();
    $id = $_SESSION['cm_uid'] ?? null;
    if (!$id) return $a = null;
    $u = row('SELECT id, login_id, name, person_id, status, is_super, session_epoch, must_change_password, is_demo FROM ' . cmt('users') . ' WHERE id = ?', [$id]);
    if (!$u || $u['status'] !== '사용' || (int)$u['is_demo'] === 1) {
        unset($_SESSION['cm_uid']);
        return $a = null;
    }
    // 교적에서 비밀번호를 바꾸면 그 전에 로그인한 재정 세션도 끝난다.
    if ((int)($_SESSION['epoch'] ?? 0) !== (int)$u['session_epoch']) {
        $_SESSION = [];
        return $a = null;
    }
    $acc = row('SELECT * FROM ' . t('access') . ' WHERE cm_user_id = ?', [$u['id']]);
    $roles = $acc ? array_column(rows('SELECT role FROM ' . t('access_roles') . ' WHERE access_id = ?', [$acc['id']]), 'role') : [];
    return $a = ['cm' => $u, 'access' => $acc, 'roles' => $roles, 'state' => account_state($u, $acc)];
}

function account_state(array $u, ?array $acc): string
{
    if ((int)$u['must_change_password'] === 1) return 'must_change';
    $isAdmin = $acc && (int)$acc['is_admin'] === 1;
    if (($u['person_id'] === null || $u['person_id'] === '') && !$isAdmin) return 'nolink'; // 결재선·담당자는 교인 단위라 연결이 필요 (재정 관리자는 예외)
    if (!$acc) return 'none';
    return match ($acc['status']) {
        '사용' => 'active',
        '정지' => 'suspended',
        default => 'pending',
    };
}

/** 로그인을 요구한다. 변경 요청이면 CSRF 토큰도 확인한다. 재정 사용 승인(active)까지 필요하면 $needActive. */
function require_account(bool $needActive = true): array
{
    check_origin();
    $a = current_account();
    if (!$a) json_fail('unauthorized', '로그인이 필요합니다.', 401);
    if (is_unsafe_method()) verify_csrf();
    if ($needActive && $a['state'] !== 'active') json_fail('no_access', '재정 사용이 승인되지 않았습니다.', 403);
    return $a;
}

function is_admin_account(array $a): bool
{
    return $a['access'] && (int)$a['access']['is_admin'] === 1;
}

function has_role(array $a, string ...$roles): bool
{
    return (bool)array_intersect($roles, $a['roles']);
}

/** 재정 관리자만 (재정 사용 승인·역할·설정) */
function require_admin(): array
{
    $a = require_account();
    if (!is_admin_account($a)) json_fail('forbidden', '재정 관리자만 할 수 있습니다.', 403);
    return $a;
}

/** 지정한 역할 중 하나가 있어야 한다. (재정 관리자라고 해서 재정 업무 권한이 자동으로 생기지는 않는다.) */
function require_role(string ...$roles): array
{
    $a = require_account();
    if (!has_role($a, ...$roles)) json_fail('forbidden', '이 작업을 할 권한이 없습니다.', 403);
    return $a;
}

/** 개인별 헌금·주민번호 같은 민감 자료를 볼 수 있는가. 서버가 요청마다 다시 계산한다. */
function can_view_donors(array $a): bool
{
    return $a['access'] && (int)$a['access']['can_view_donors'] === 1 && has_role($a, ...FM_DONOR_ROLES);
}

/** 화면이 쓰는 내 정보. 비밀번호 관련 값은 절대 넣지 않는다. */
function account_payload(array $a): array
{
    $acc = $a['access'];
    return [
        'state' => $a['state'],
        'user' => ['loginId' => $a['cm']['login_id'], 'name' => $a['cm']['name'], 'personId' => $a['cm']['person_id']],
        'access' => $acc ? [
            'status' => $acc['status'],
            'isAdmin' => (int)$acc['is_admin'] === 1,
            'roles' => $a['roles'],
            'canViewDonors' => can_view_donors($a),
            'requestedAt' => substr((string)$acc['requested_at'], 0, 10),
        ] : null,
    ];
}

/** 접속 기록. 기록이 실패해도 본 작업은 막지 않되, 실패는 app.log 에 남긴다. */
function audit(string $action, string $target = '', ?array $acct = null): void
{
    try {
        $a = $acct ?? current_account();
        q(
            'INSERT INTO ' . t('access_log') . ' (access_id, who, action, target, ip) VALUES (?,?,?,?,?)',
            [$a['access']['id'] ?? null, mb_substr((string)($a['cm']['name'] ?? ''), 0, 50), mb_substr($action, 0, 40), mb_substr($target, 0, 200), client_ip()]
        );
    } catch (Throwable $e) {
        fm_log('error', 'audit: ' . $e->getMessage());
    }
}

/** 중요한 동작(권한 변경 등) 전에 최근 10분 안에 비밀번호를 다시 확인했는가. 설정에서 끌 수 있다. */
function require_reconfirm(): void
{
    $sec = json_decode((string)val('SELECT security FROM ' . t('settings') . ' WHERE id = 1'), true) ?: [];
    if (empty($sec['reconfirm'])) return;
    if (time() - (int)($_SESSION['reconfirm_at'] ?? 0) > 600) {
        json_fail('reconfirm', '중요한 작업이라 비밀번호를 한 번 더 확인합니다.', 403);
    }
}

/** 재정 기준 자료를 고칠 수 있는 사람: 재정 관리자 또는 재정 담당 */
function require_staff(): array
{
    $a = require_account();
    if (!is_admin_account($a) && !has_role($a, '재정 담당')) json_fail('forbidden', '재정 관리자 또는 재정 담당만 할 수 있습니다.', 403);
    return $a;
}

/** 변경 기록 (수정 전후 값) */
function change_log(string $entity, string|int $id, string $field, mixed $before, mixed $after, string $note = ''): void
{
    $a = current_account();
    $enc = fn($v) => $v === null ? null : (is_scalar($v) ? (string)$v : json_encode($v, JSON_UNESCAPED_UNICODE));
    q(
        'INSERT INTO ' . t('change_log') . ' (by_access_id, by_name, entity, entity_id, field, before_value, after_value, note) VALUES (?,?,?,?,?,?,?,?)',
        [$a['access']['id'] ?? null, mb_substr((string)($a['cm']['name'] ?? ''), 0, 50), $entity, (string)$id, $field, $enc($before), $enc($after), $note === '' ? null : mb_substr($note, 0, 255)]
    );
}
