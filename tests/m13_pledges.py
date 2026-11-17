"""작정헌금·1/2부 구분·접속 기록 보강 시험."""
from testkit import *  # noqa

wipe()
sql("delete from fm_pledges")
U, people = make_users({
    't_staff': ('시험담당', ['재정 담당'], 0, 1, 0),
    't_view': ('시험열람', ['열람'], 0, 0, 1),
    't_count': ('시험계수', ['계수 위원'], 0, 0, 2),
})
stf, vw, cnt = U['t_staff'], U['t_view'], U['t_count']
stf.call('POST', '/funds', {'name': '시험회계'})
s, j = stf.call('POST', '/wallets', {'fundId': 1, 'name': '시험현금', 'kind': '현금', 'opening': 0}); wc = j['data']['id']
Y = int(sql("select year(curdate())"))
P = 'p:' + people[3]

# 헌금: 건축헌금(15가 아닌 작정 가능 종류 하나 고름) — 이름 쓰는 헌금 종류
accts = stf.call('GET', f'/pledges?year={Y}')[1]['data']['accounts']
check('작정할 수 있는 헌금 종류 목록이 나옴(이름 쓰는 종류만)', len(accts) >= 2, accts)
A = next(a['id'] for a in accts if a['name'] == '십일조')

s, j = stf.call('POST', '/pledges', {'year': Y, 'accountId': A, 'donor': P, 'amount': 1000000, 'memo': '시험'}); check('작정 저장', s == 200, j); pid = j['data']['id']
s, j = stf.call('POST', '/pledges', {'year': Y, 'accountId': A, 'donor': P, 'amount': 1200000}); check('같은 사람·종류는 금액을 고침(새 줄 아님)', s == 200 and j['data']['id'] == pid, j)
s, j = stf.call('POST', '/pledges', {'year': Y, 'accountId': 4, 'donor': P, 'amount': 5}); check('무기명 종류(주일헌금)는 작정 불가', s == 422, j)
s, j = stf.call('POST', '/pledges', {'year': Y, 'accountId': A, 'donor': P, 'amount': 0}); check('0원 거절', s == 422)
s, j = stf.call('POST', '/pledges', {'year': Y, 'accountId': A, 'donor': 'x:1', 'amount': 5}); check('이상한 헌금자 거절', s == 422)
s, j = vw.call('POST', '/pledges', {'year': Y, 'accountId': A, 'donor': P, 'amount': 5}); check('열람 역할은 작정을 못 만듦', s == 403)
s, j = cnt.call('GET', f'/pledges?year={Y}'); check('계수 위원은 작정을 못 봄', s == 403)

# 낸 금액: 마감된 묶음만, 같은 헌금자·종류
s, j = stf.call('POST', '/offering-batches', {'date': '%d-01-04' % Y if False else sql("select date_format(curdate(),'%Y-%m-01')"), 'kind': '주일 1부', 'counters': '시험'}); check('1부 구분으로 묶음 생성', s == 200, j); bid = j['data']['id']
s, j = stf.call('POST', f'/offering-batches/{bid}/lines', {'accountId': A, 'donor': P, 'amount': 300000, 'method': '현금'}); check('십일조 입력', s == 200, j)
s, j = stf.call('GET', f'/pledges?year={Y}'); it = j['data']['items'][0]
check('입력 중인 묶음은 낸 금액에 안 셈', it['paid'] == 0 and it['amount'] == 1200000, it)
s, j = stf.call('POST', f'/offering-batches/{bid}/counted', {'counted': {str(A): 300000}, 'counters': '시험'})
s, j = stf.call('POST', f'/offering-batches/{bid}/close', {'cashWalletId': wc}); check('마감', s == 200, j)
s, j = stf.call('GET', f'/pledges?year={Y}'); it = j['data']['items'][0]
check('마감 뒤 낸 금액 300,000 · 남음 900,000 · 25%', it['paid'] == 300000 and it['remain'] == 900000 and it['rate'] == 25, it)
check('합계 줄', j['data']['summary']['amount'] == 1200000 and j['data']['summary']['paid'] == 300000 and j['data']['summary']['rate'] == 25, j['data']['summary'])
s, j = vw.call('GET', f'/pledges?year={Y}'); check('열람 역할(개인별 열람 지정자)은 볼 수 있음 또는 권한 없음', s in (200, 403))
check('작정 열람이 접속 기록에 남음', int(sql("select count(*) from fm_access_log where action='작정헌금 열람'")) >= 2)
check('작정 수정이 변경 기록에 남음', int(sql("select count(*) from fm_change_log where entity='pledge'")) >= 2)
s, j = stf.call('POST', f'/pledges/{pid}/delete', {}); check('작정 지우기', s == 200, j)
check('지워도 낸 헌금은 그대로', int(sql("select count(*) from fm_offerings where donor_id is not null")) >= 1)

# 접속 기록 보강
stf.call('GET', '/donors?q=' + 'x'); stf.call('GET', f'/receipts/preview?year={Y-1}')
check('헌금자 목록 열람이 접속 기록에 남음(IP 포함)', int(sql("select count(*) from fm_access_log where action='헌금자 목록 열람' and ip is not null")) >= 1)
check('영수증 미리보기가 접속 기록에 남음', int(sql("select count(*) from fm_access_log where action='영수증 미리보기'")) >= 1)

# 체험 모드: 가짜 작정이 보임
import subprocess
h = subprocess.run([r'C:\xampp\php\php.exe', '-r', "echo password_hash('1234', PASSWORD_DEFAULT);"], capture_output=True, text=True).stdout
sql("delete from cm_users where login_id='t_demo'")
sql("insert into cm_users (login_id,password_hash,name,status,grade,is_super,is_demo) values ('t_demo','%s','체험','사용','A',1,1)" % h)
d = Client(); d.login('t_demo', '1234')
s, j = d.call('GET', f'/pledges?year={Y}'); check('체험 모드: 가짜 작정이 보임', s == 200 and len(j['data']['items']) >= 5 and all(i['donor'] != '시험' for i in j['data']['items']), (s, str(j)[:200]))
s, j = d.call('POST', '/pledges', {'year': Y, 'accountId': A, 'donor': P, 'amount': 5}); check('체험 모드: 작정 저장 막힘', s == 403 and code(j) == 'demo_readonly', j)
sql("delete from cm_users where login_id='t_demo'")
sql("delete from fm_pledges")
for t in sql("select table_name from information_schema.tables where table_schema=database() and table_name like 'fmd\\_%'").split('\n'):
    if t: sql("drop table if exists `%s`" % t)
wipe()
finish()
