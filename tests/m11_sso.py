"""교적 '재정 열기' 입장권 받기 시험. 교적에 cm_sso_tickets 표가 생긴 것처럼 임시 표를 만들어 확인한다."""
import hashlib, secrets
from testkit import *  # noqa

wipe()
sql("drop table if exists cm_sso_tickets")
U, people = make_users({'t_staff': ('시험담당', ['재정 담당'], 0, 1, 0)})
uid = sql("select id from cm_users where login_id='t_staff'")

c = Client(); c.call('GET', '/auth/me')
tk = secrets.token_urlsafe(32)
s, j = c.call('POST', '/auth/sso', {'ticket': tk}); check('교적에 입장권 표가 없으면 404 (일반 로그인은 그대로)', s == 404 and code(j) == 'sso_unavailable', j)

sql("create table cm_sso_tickets (id bigint unsigned auto_increment primary key, token_hash char(64) not null, user_id int unsigned not null, expires_at datetime not null, created_at datetime not null default current_timestamp, unique key uniq_token (token_hash), index idx_expires (expires_at), constraint fk_cm_sso_user foreign key (user_id) references cm_users(id) on delete cascade) engine=InnoDB default charset=utf8mb4 collate=utf8mb4_unicode_ci")  # ch-manage 007 과 같은 모양(외래키 포함)
def issue(ttl='interval 30 second'):
    t = secrets.token_urlsafe(32)
    sql("insert into cm_sso_tickets (token_hash, user_id, expires_at) values ('%s', %s, date_add(now(), %s))" % (hashlib.sha256(t.encode()).hexdigest(), uid, ttl))
    return t
t = issue()
s, j = c.call('POST', '/auth/sso', {'ticket': 'x'}); check('형식이 틀린 입장권 거절', s == 422)
s, j = c.call('POST', '/auth/sso', {'ticket': t}); check('입장권으로 로그인', s == 200 and j['data']['me']['state'] == 'active' and j['data']['me']['user']['loginId'] == 't_staff', j)
c.csrf = j['data']['csrf']
s, j = c.call('GET', '/dashboard'); check('로그인된 세션으로 화면 자료를 받음', s == 200, j)
check('입장권은 쓰자마자 지워짐', sql("select count(*) from cm_sso_tickets") == '0')
c2 = Client(); c2.call('GET', '/auth/me')
s, j = c2.call('POST', '/auth/sso', {'ticket': t}); check('같은 입장권은 두 번 못 씀', s == 401 and code(j) == 'invalid_ticket', j)
t2 = issue('interval -5 second')
s, j = c2.call('POST', '/auth/sso', {'ticket': t2}); check('만료된 입장권 거절', s == 401, j)
t3 = issue(); sql("update cm_users set status='정지' where id=%s" % uid)
s, j = c2.call('POST', '/auth/sso', {'ticket': t3}); check('교적에서 정지된 계정은 입장권이 있어도 거절', s == 403, j)
sql("update cm_users set status='사용' where id=%s" % uid)
t4 = issue(); sql("update cm_users set grade='D', is_super=0 where id=%s" % uid)
s, j = c2.call('POST', '/auth/sso', {'ticket': t4}); check('소그룹 인도자(등급 D)는 입장권이 있어도 거절', s == 403 and code(j) == 'inactive', j)
s, j = Client().login('t_staff'); check('비밀번호 로그인에서도 소그룹 인도자는 거절', s == 403 and code(j) == 'not_eligible', j)
sql("update cm_users set is_super=1 where id=%s" % uid)
s, j = c2.call('POST', '/auth/sso', {'ticket': issue()}); check('최고관리자는 등급 D 여도 예외', s == 200, j)
sql("update cm_users set grade='C', is_super=0 where id=%s" % uid)
c3 = Client(); c3.call('GET', '/auth/me')
s, j = c3.call('POST', '/auth/sso', {'ticket': issue()}, headers={'Origin': 'http://evil.example'}); check('다른 사이트에서 온 요청 거절', s == 403)
for _ in range(25): c3.call('POST', '/auth/sso', {'ticket': secrets.token_urlsafe(32)})
s, j = c3.call('POST', '/auth/sso', {'ticket': issue()}); check('입장권 추측을 반복하면 잠시 막힘(429)', s == 429, j)

import urllib.request
r = urllib.request.urlopen('http://127.0.0.1:8091/api/v1/auth/me'); check('API 응답에 Referrer-Policy: no-referrer', r.headers['Referrer-Policy'] == 'no-referrer', r.headers['Referrer-Policy'])
sql("drop table if exists cm_sso_tickets")
sql("delete from fm_login_attempts")
wipe()
finish()
