<?php
declare(strict_types=1);

// 재정 관리자: 재정 사용 승인, 역할, 개인별 헌금 열람 지정, 기록 보기.

const FM_DONOR_DEFAULT_ROLES = ['재정 담당', '재정위원장', '담임목사']; // 개인별 헌금 열람이 기본으로 켜지는 역할 (감사는 지정한 사람만)

function access_item(array $r, array $roles): array
{
    return [
        'id' => (int)$r['id'],
        'loginId' => $r['login_id'],
        'name' => $r['name'],
        'linked' => !empty($r['person_id']),
        'status' => $r['status'],
        'isAdmin' => (int)$r['is_admin'] === 1,
        'canViewDonors' => (int)$r['can_view_donors'] === 1,
        'roles' => $roles,
        'reason' => $r['reason'],
        'requestedAt' => substr((string)$r['requested_at'], 0, 16),
        'decidedAt' => $r['decided_at'] ? substr((string)$r['decided_at'], 0, 16) : null,
        'lastLoginAt' => $r['last_login_at'] ? substr((string)$r['last_login_at'], 0, 16) : null,
    ];
}

route('GET', '/admin/access', function () {
    require_admin();
    $roles = [];
    foreach (rows('SELECT access_id, role FROM ' . t('access_roles')) as $r) $roles[(int)$r['access_id']][] = $r['role'];
    $items = array_map(
        fn($r) => access_item($r, $roles[(int)$r['id']] ?? []),
        rows('SELECT * FROM ' . t('access') . " ORDER BY FIELD(status,'신청 대기','사용','정지'), requested_at DESC")
    );
    json_out(['items' => $items, 'roles' => FM_ROLES, 'donorRoles' => FM_DONOR_ROLES]);
});

/** 승인·정지·역할·열람 지정을 한 번에 바꾼다. 보내지 않은 값은 그대로 둔다. */
route('POST', '/admin/access/{id}', function (int $id) {
    $me = require_admin();
    require_reconfirm();
    $b = body();
    $cur = row('SELECT * FROM ' . t('access') . ' WHERE id = ?', [$id]);
    if (!$cur) json_fail('not_found', '대상을 찾을 수 없습니다.', 404);
    $curRoles = array_column(rows('SELECT role FROM ' . t('access_roles') . ' WHERE access_id = ?', [$id]), 'role');

    $status = array_key_exists('status', $b) ? in_enum($b, 'status', ['신청 대기', '사용', '정지'], '상태') : $cur['status'];
    $isAdmin = array_key_exists('isAdmin', $b) ? (int)(bool)$b['isAdmin'] : (int)$cur['is_admin'];
    $roles = $curRoles;
    if (array_key_exists('roles', $b)) {
        if (!is_array($b['roles'])) json_fail('invalid', '역할 값이 올바르지 않습니다.', 422);
        $roles = [];
        foreach ($b['roles'] as $r) {
            if (!is_string($r) || !in_array($r, FM_ROLES, true)) json_fail('invalid', '알 수 없는 역할입니다.', 422);
            $roles[$r] = $r;
        }
        $roles = array_values($roles);
    }

    $isSelf = (int)$cur['cm_user_id'] === (int)$me['cm']['id'];
    if ($isSelf && ($status !== '사용' || !$isAdmin)) json_fail('self', '내 계정의 정지나 관리자 해제는 다른 재정 관리자가 해야 합니다.', 409);
    if ($status === '사용' && empty($cur['person_id']) && !$isAdmin) {
        json_fail('nolink', '교적에서 이 계정을 교인과 연결한 뒤에 승인할 수 있어요. (결재선은 교인 단위로 정합니다.)', 409);
    }

    // 개인별 헌금 열람: 보낸 값이 있으면 그 값, 없으면 이번에 처음 승인하는 경우에 한해 역할에 따른 기본값, 그 밖에는 그대로
    $view = (int)$cur['can_view_donors'];
    if (array_key_exists('canViewDonors', $b)) $view = (int)(bool)$b['canViewDonors'];
    elseif ($cur['status'] !== '사용' && $status === '사용') $view = array_intersect(FM_DONOR_DEFAULT_ROLES, $roles) ? 1 : 0;
    if (!array_intersect(FM_DONOR_ROLES, $roles)) {
        if ($view === 1 && array_key_exists('canViewDonors', $b)) json_fail('invalid', '개인별 헌금 열람은 재정 담당·재정위원장·담임목사·감사 역할에만 켤 수 있어요.', 422);
        $view = 0;
    }
    if ($status !== '사용') $view = 0;

    tx(function () use ($id, $cur, $curRoles, $status, $isAdmin, $roles, $view, $me) {
        // 남는 활성 관리자가 한 명도 없게 되는 변경은 막는다
        $others = (int)val('SELECT COUNT(*) FROM ' . t('access') . " WHERE is_admin = 1 AND status = '사용' AND id <> ?", [$id]);
        if ($others === 0 && ($status !== '사용' || !$isAdmin) && (int)$cur['is_admin'] === 1 && $cur['status'] === '사용') {
            json_fail('last_admin', '재정 관리자가 한 명도 남지 않게 되는 변경은 할 수 없어요.', 409);
        }
        $decided = $status !== $cur['status'] ? ', decided_at = NOW(), decided_by = ' . (int)$me['access']['id'] : '';
        q('UPDATE ' . t('access') . " SET status = ?, is_admin = ?, can_view_donors = ? $decided WHERE id = ?", [$status, $isAdmin, $view, $id]);
        q('DELETE FROM ' . t('access_roles') . ' WHERE access_id = ?', [$id]);
        foreach ($roles as $r) q('INSERT INTO ' . t('access_roles') . ' (access_id, role) VALUES (?,?)', [$id, $r]);

        $who = $cur['name'] . '(' . $cur['login_id'] . ')';
        if ($status !== $cur['status']) {
            audit('재정 사용 ' . $status, $who, $me);
            change_log('access', $id, 'status', $cur['status'], $status);
        }
        if ($isAdmin !== (int)$cur['is_admin']) {
            audit($isAdmin ? '재정 관리자 지정' : '재정 관리자 해제', $who, $me);
            change_log('access', $id, 'is_admin', $cur['is_admin'], $isAdmin);
        }
        if (array_diff($roles, $curRoles) || array_diff($curRoles, $roles)) {
            audit('역할 변경', $who . ': ' . implode(',', $roles), $me);
            change_log('access', $id, 'roles', $curRoles, $roles);
        }
        if ($view !== (int)$cur['can_view_donors']) {
            audit($view ? '개인별 헌금 열람 지정' : '개인별 헌금 열람 해제', $who, $me);
            change_log('access', $id, 'can_view_donors', $cur['can_view_donors'], $view);
        }
    });
    json_out(null);
});

/** 신청을 거절한다. 신청 대기인 것만 지우고, 본인은 다시 신청할 수 있다. */
route('POST', '/admin/access/{id}/reject', function (int $id) {
    $me = require_admin();
    require_reconfirm();
    $cur = row('SELECT * FROM ' . t('access') . ' WHERE id = ?', [$id]);
    if (!$cur) json_fail('not_found', '대상을 찾을 수 없습니다.', 404);
    if ($cur['status'] !== '신청 대기') json_fail('invalid_state', '신청 대기인 것만 거절할 수 있어요. 사용 중인 사람은 정지해 주세요.', 409);
    q('DELETE FROM ' . t('access') . ' WHERE id = ?', [$id]);
    audit('재정 사용 신청 거절', $cur['name'] . '(' . $cur['login_id'] . ')', $me);
    json_out(null);
});

/** 접속·열람 기록. kind=donor 이면 개인별 헌금·주민번호 관련만 */
route('GET', '/admin/log', function () {
    require_admin();
    $donor = ($_GET['kind'] ?? '') === 'donor';
    $where = $donor ? " WHERE action LIKE '개인별%' OR action LIKE '주민번호%' OR action LIKE '헌금자%'" : '';
    $items = rows('SELECT at, who, action, target, ip FROM ' . t('access_log') . $where . ' ORDER BY id DESC LIMIT 100');
    json_out(['items' => array_map(fn($r) => ['at' => substr((string)$r['at'], 0, 16), 'who' => $r['who'], 'action' => $r['action'], 'target' => $r['target'], 'ip' => $r['ip']], $items)]);
});
