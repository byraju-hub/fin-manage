"""M0 통합 로그인 흐름 시험. 로컬 개발 서버(8091)와 개발 DB(church_dev)를 쓴다.
   실행: python tests/auth_flow.py   (XAMPP MariaDB 와 `php -S 127.0.0.1:8091 -t server server/dev-router.php` 가 떠 있어야 함)"""
import json, subprocess, sys, urllib.request, urllib.error, http.cookiejar

A = 'http://127.0.0.1:8091/api/v1'
MYSQL = r'C:\xampp\mysql\bin\mysql.exe'
PW = 'testpass1234'
fails = 0

def sql(q): return subprocess.run([MYSQL, '-uroot', '--default-character-set=utf8mb4', 'church_dev', '-N'], input=q, capture_output=True, text=True, encoding='utf-8').stdout.strip()  # 한글이 깨지지 않게 표준입력으로 보냄

class Client:
    def __init__(s):
        s.cj = http.cookiejar.CookieJar(); s.op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(s.cj)); s.csrf = ''
    def call(s, method, path, body=None, headers=None, csrf=True):
        h = {'Content-Type': 'application/json', **(headers or {})}
        if csrf and method != 'GET': h['X-CSRF-Token'] = s.csrf
        req = urllib.request.Request(A + path, json.dumps(body).encode() if body is not None else None, h, method=method)
        try: r = s.op.open(req); status = r.status; data = r.read()
        except urllib.error.HTTPError as e: status = e.code; data = e.read()
        j = json.loads(data)
        if path in ('/auth/me', '/auth/login') and j.get('ok'): s.csrf = j['data']['csrf']
        return status, j
    def login(s, user, pw=PW):
        s.call('GET', '/auth/me'); return s.call('POST', '/auth/login', {'loginId': user, 'password': pw})

def check(name, cond, extra=''):
    global fails
    print(('PASS ' if cond else 'FAIL ') + name + (' ' + str(extra) if not cond else ''))
    if not cond: fails += 1

# 준비: 시험용 교적 계정 (교인과 연결 / 연결 없음), 재정 승인 기록 초기화
pid = sql('select id from cm_people order by seq limit 1')
h = subprocess.run([r'C:\xampp\php\php.exe', '-r', f"echo password_hash('{PW}', PASSWORD_DEFAULT);"], capture_output=True, text=True).stdout
sql(f"delete from fm_access where login_id in ('tester','nolink')")
sql(f"delete from cm_users where login_id in ('tester','nolink','demo2')")
sql(f"insert into cm_users (login_id,password_hash,name,person_id,status,grade) values ('tester','{h}','시험사용자','{pid}','사용','C'),('nolink','{h}','연결없음',NULL,'사용','C'),('demo2','{h}','체험','{pid}','사용','C')")
sql("update cm_users set is_demo=1 where login_id='demo2'")

c = Client()
s, j = c.call('GET', '/dashboard'); check('로그인 전 첫 화면 자료는 401', s == 401)
s, j = c.login('tester', 'wrong'); check('틀린 비밀번호 거절', s == 401 and j['error']['code'] == 'invalid_login')
s, j = Client().login('demo2'); check('체험 계정(is_demo)은 재정 체험 모드로 로그인(승인 절차 없음, 자세한 시험은 m12_demo.py)', s == 200 and j['data']['me']['demo'] is True)
s, j = c.call('POST', '/auth/login', {'loginId': 'tester', 'password': PW}, csrf=False); check('CSRF 토큰 없이 로그인 거절', s == 403 and j['error']['code'] == 'csrf')
s, j = c.login('nolink'); check('교인 연결 없는 계정 → nolink', s == 200 and j['data']['me']['state'] == 'nolink')
s, j = c.call('POST', '/auth/request-access', {'reason': 'x'}); check('nolink 는 재정 사용 신청 불가', s == 409)

c = Client(); s, j = c.login('tester'); check('승인 전 로그인 → none', j['data']['me']['state'] == 'none')
s, j = c.call('GET', '/dashboard'); check('승인 전 첫 화면 자료 403', s == 403 and j['error']['code'] == 'no_access')
s, j = c.call('POST', '/auth/request-access', {'reason': '유치부 회계'}); check('재정 사용 신청', s == 200)
s, j = c.call('POST', '/auth/request-access', {'reason': '또'}); check('중복 신청 거절', s == 409)
s, j = c.call('GET', '/auth/me'); check('신청 뒤 상태 pending', j['data']['me']['state'] == 'pending')
s, j = c.call('GET', '/dashboard'); check('pending 도 자료 403', s == 403)

sql("update fm_access set status='사용' where login_id='tester'")
s, j = c.call('GET', '/auth/me'); check('관리자가 승인하면 active', j['data']['me']['state'] == 'active')
s, j = c.call('GET', '/dashboard'); check('active 는 첫 화면 자료 200, 교인 수 포함', s == 200 and j['data']['cm']['people'] > 0, j)
s, j = c.call('POST', '/auth/logout', {}, headers={'Origin': 'http://evil.example'}); check('다른 사이트에서 온 요청(Origin) 거절', s == 403 and j['error']['code'] == 'forbidden_origin')

sql("update fm_access set status='정지' where login_id='tester'")
s, j = c.call('GET', '/auth/me'); check('재정 정지 → suspended', j['data']['me']['state'] == 'suspended')
sql("update fm_access set status='사용' where login_id='tester'")
sql("update cm_users set session_epoch=session_epoch+1 where login_id='tester'")
s, j = c.call('GET', '/auth/me'); check('교적에서 비밀번호를 바꾸면(세션 번호 증가) 재정 세션 종료', j['data']['me'] is None)
sql("update cm_users set must_change_password=1 where login_id='tester'")
c = Client(); s, j = c.login('tester'); check('임시 비밀번호 상태 → must_change', j['data']['me']['state'] == 'must_change')
sql("update cm_users set must_change_password=0 where login_id='tester'")
sql("update cm_users set status='정지' where login_id='tester'")
s, j = Client().login('tester'); check('교적에서 정지된 계정은 재정 로그인 거절', s == 403)
sql("update cm_users set status='사용' where login_id='tester'")

# 로그인 시도 제한: 같은 아이디로 연달아 틀리면 막는다
c = Client(); [c.login('tester', 'bad') for _ in range(5)]
s, j = c.login('tester', PW); check('5번 틀리면 올바른 비밀번호도 잠시 막힘(429)', s == 429)
sql("delete from fm_login_attempts")

# 시험 계정 정리
sql("delete from fm_access where login_id in ('tester','nolink')")
sql("delete from cm_users where login_id in ('tester','nolink','demo2')")
print('\n실패 %d건' % fails); sys.exit(1 if fails else 0)
