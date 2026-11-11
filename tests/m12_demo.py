"""재정 체험 모드 시험: 교적의 체험 계정(is_demo=1)으로 로그인 → 가짜 자료만 보고, 보기만 할 수 있다."""
import subprocess, urllib.request, urllib.error
from testkit import *  # noqa

BASE = 'http://127.0.0.1:8091/api/v1'
wipe()
for t in sql("select table_name from information_schema.tables where table_schema=database() and table_name like 'fmd\\\\_%'").split('\n'):
    if t: sql("drop table if exists `%s`" % t)
sql("delete from cm_users where login_id in ('t_demo')")
# 체험 계정: 교적의 데모 계정과 같은 모양 (is_demo=1, 최고관리자, 등급 A, 비밀번호 1234)
h = subprocess.run([r'C:\xampp\php\php.exe', '-r', "echo password_hash('1234', PASSWORD_DEFAULT);"], capture_output=True, text=True).stdout
sql("insert into cm_users (login_id,password_hash,name,status,grade,is_super,is_demo) values ('t_demo','%s','체험','사용','A',1,1)" % h)

# 진짜 재정 자료(표시를 달아 둔다): 체험 화면에 이것이 나오면 실패
U, people = make_users({'t_staff': ('시험담당', ['재정 담당'], 0, 1, 0)})
stf = U['t_staff']
real_name = sql("select name from cm_people where purged_on is null order by seq limit 1")
real_pid = sql("select id from cm_people where purged_on is null order by seq limit 1")
s, j = stf.call('POST', '/wallets', {'fundId': 1, 'name': '실제비밀계좌시험', 'kind': '통장', 'opening': 123456789}); wid = j['data']['id']
Y = int(sql("select year(curdate())"))
s, j = stf.call('POST', '/transactions', {'kind': '수입', 'date': '%d-11-01' % Y, 'walletId': wid, 'incomeAccountId': 21, 'amount': 777, 'summary': '실제비밀거래시험'}); check('진짜 자료 준비(표시한 거래)', s == 200, j)
stf.call('POST', '/donors', {'name': '실제비밀이름시험'})
MARK = ['실제비밀계좌시험', '실제비밀거래시험', '실제비밀이름시험', '123456789']
before_log = sql("select count(*) from fm_access_log")

check('교적에 켜진 체험 계정이 있으면 로그인 창에 체험 안내(demoAvailable)', Client().call('GET', '/auth/me')[1]['data']['demoAvailable'] is True)
d = Client()
s, j = d.login('t_demo', 'wrong'); check('비밀번호가 틀리면 거절', s == 401)
s, j = d.login('t_demo', '1234'); me = j['data']['me'] if s == 200 else {}
check('demo/1234 로그인 → 체험 모드(승인 절차 없이 active, 관리자 아님)', s == 200 and me.get('demo') is True and me['state'] == 'active' and me['access']['isAdmin'] is False, j)
check('체험 계정은 교적에서 최고관리자여도 재정 관리자 권한이 없음', me['access']['isAdmin'] is False)

Yq = Y
gets = ['/dashboard', '/base', f'/units?year={Y}', f'/budget?year={Y}', f'/ledger?year={Y}', '/wallets/balances', f'/offering-batches?year={Y}', '/reports/weekly', f'/reports/monthly?year={Y}&month={sql("select month(curdate())")}',
        f'/reports/execution?year={Y}', f'/reports/settlement?year={Y}', f'/vouchers?year={Y}', '/approvals/inbox', '/vouchers-to-pay', f'/receipts/preview?year={Y-1}', f'/receipts?year={Y-1}', '/donors',
        f'/closings?year={Y}', '/approval-rules', '/people/search?q=' + '%EA%B9%80', '/donors/search?q=' + '%EA%B9%80', '/my/units', f'/budget-changes?year={Y}', '/reports/bulletin?from=2020-01-01&to=2099-01-01']
leak = []
for g in gets:
    r = d.op.open(urllib.request.Request(BASE + g)) if False else None
    s, j = d.call('GET', g)
    ok = s == 200
    body = str(j)
    if not ok: print('   ', g, s, body[:150])
    check('체험: ' + g.split('?')[0] + ' 열림', ok, j)
    for m in MARK:
        if m in body: leak.append((g, m))
check('체험 화면 어디에도 진짜 재정 자료(계좌·거래·헌금자 표식)가 없음', not leak, leak)
s, j = d.call('GET', '/dashboard'); dj = j['data']
check('대시보드의 교적 정보는 가짜(교인 수가 진짜 교적 수와 다름, 교회 이름 체험교회)', dj['cm']['people'] != int(sql("select count(*) from cm_people")) and dj['cm']['churchName'] == '체험교회', dj['cm'])
check('체험 대시보드에 수입·지출·잔액이 채워져 있음', dj['fin']['totals']['income'] > 0 and dj['fin']['totals']['balance'] != 123456789 + 777, dj['fin']['totals'])
s, j = d.call('GET', '/people/search?q=' + __import__('urllib.parse').parse.quote(real_name[:1])); ids = [i['id'] for i in j['data']['items']]
check('교인 찾기도 교적의 실제 교인이 아니라 가짜 헌금자에서 찾음', s == 200 and all(str(i).startswith('demo-') for i in ids) and real_pid not in ids, ids[:3])
s, j = d.call('GET', f'/offering-batches?year={Y}'); first = j['data']['items'][0]['id']
s, j = d.call('GET', f'/offering-batches/{first}'); check('헌금 묶음 상세(가짜 헌금자 이름)가 보임', s == 200 and len(j['data']['lines']) > 5 and all(MARK[2] not in str(l) for l in j['data']['lines']), j)
names = {l['donor'] for l in j['data']['lines'] if l['donor']}
check('체험 헌금자 이름은 교적 연결 없이 만든 가짜(교적 people 과 id 로 이어지지 않음)', sql("select count(*) from fmd_donors where person_id is not null") == '0')

# 보기 외 요청은 모두 막힘
for method, path, body in (('POST', '/funds', {'name': 'x'}), ('POST', '/offering-batches', {'date': '2099-01-01', 'kind': '주일예배'}), ('POST', '/transactions', {}), ('POST', '/auth/reconfirm', {'password': '1234'}),
                           ('POST', '/vouchers', {}), ('POST', '/receipts/issue', {'year': 2025, 'donorIds': [1]}), ('PUT', '/budget-items/1', {}), ('DELETE', '/units/1', {})):
    try:
        s, j = d.call(method, path, body)
    except Exception as e:  # PUT/DELETE 가 라우터에 없어도 서버가 막아야 한다
        s, j = 0, {}
    check(f'체험: {method} {path} → 403 demo_readonly', s == 403 and code(j) == 'demo_readonly' and '보기만' in j['error']['message'], (s, j))
for path in ('/admin/access', '/admin/sample', '/backup/info', '/backup/download', '/settings', '/export/ledger?year=%d' % Y, '/receipts-export?year=%d' % Y, '/import/offerings/pending', '/delegations'):
    s, j = d.call('GET', path); check('체험: 민감한 화면 ' + path.split('?')[0] + ' 막힘', s == 403 and code(j) == 'demo_blocked', (s, j))
check('체험 요청은 진짜 접속 기록(fm_access_log)에 아무것도 남기지 않음', sql("select count(*) from fm_access_log") == before_log, (before_log, sql("select count(*) from fm_access_log")))
check('체험 로그인은 진짜 사용 승인 표에 기록을 만들지 않음', sql("select count(*) from fm_access where login_id='t_demo'") == '0')
s, j = d.call('POST', '/auth/logout', {}); check('로그아웃은 가능', s == 200, j)

# 일반 사용자는 그대로 진짜 자료
s, j = stf.call('GET', f'/ledger?year={Y}'); check('일반 사용자는 진짜 자료를 봄(표식 거래 보임, 체험 모드 아님)', s == 200 and '실제비밀거래시험' in str(j), j)
s, j = stf.call('GET', '/auth/me'); check('일반 사용자는 체험 모드가 아님', j['data']['me']['demo'] is False)

# 교적에서 체험 계정을 끄면 재정에서도 못 들어감
d2 = Client(); d2.login('t_demo', '1234')
sql("update cm_users set status='정지' where login_id='t_demo'")
s, j = d2.call('GET', '/auth/me'); check('교적에서 정지하면 이미 열린 체험 세션도 끝남', j['data']['me'] is None, j)
s, j = Client().login('t_demo', '1234'); check('정지된 체험 계정은 로그인 불가', s == 403, j)
sql("update cm_users set status='사용' where login_id='t_demo'")
d3 = Client(); d3.login('t_demo', '1234')
sql("delete from cm_users where login_id='t_demo'")
s, j = d3.call('GET', '/auth/me'); check('교적에서 체험 계정을 지우면 세션이 끝남', j['data']['me'] is None)
s, j = Client().login('t_demo', '1234'); check('지운 체험 계정은 로그인 불가', s == 401, j)
check('체험 계정이 없으면 로그인 창의 체험 안내가 사라짐(demoAvailable)', Client().call('GET', '/auth/me')[1]['data']['demoAvailable'] is (sql("select count(*) from cm_users where is_demo=1 and status='사용'") != '0'))
# 체험이 아닌 일반 계정이 SSO 입장권으로 들어오는 경우는 그대로(체험 계정은 교적이 발급을 거절)

for t in sql("select table_name from information_schema.tables where table_schema=database() and table_name like 'fmd\\\\_%'").split('\n'):
    if t: sql("drop table if exists `%s`" % t)
sql("delete from fm_donors where name like '실제비밀%'")
sql("delete from fm_transactions where summary like '실제비밀%'")
sql("delete from fm_wallets where name like '실제비밀%'")
wipe()
finish()
