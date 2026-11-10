"""M1 시험: 재정 사용 승인·역할·열람 지정, 기준 자료, 재정 단위·담당자, 교인 찾기.
   실행: PYTHONIOENCODING=utf-8 python tests/m1_flow.py  (MariaDB 와 php -S 127.0.0.1:8091 가 떠 있어야 함)"""
import os, sys, subprocess
from urllib.parse import quote
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from auth_flow_lib import Client, sql, PW  # noqa

fails = 0


def check(name, cond, extra=''):
    global fails
    print(('PASS ' if cond else 'FAIL ') + name + (' ' + str(extra)[:300] if not cond else ''))
    if not cond:
        fails += 1


def code(j):
    return (j.get('error') or {}).get('code')


def cleanup():
    from testkit import wipe
    wipe()
    sql("delete from fm_access where login_id like 't\\_%'")
    sql("delete from cm_users where login_id like 't\\_%'")
    sql("delete from fm_units where fiscal_year in (2097,2098,2099)")
    sql("delete from fm_fiscal_years where year in (2097,2098,2099)")
    sql("delete from fm_income_accounts where name like '시험%'")
    sql("delete from fm_wallets where name like '시험%'")
    sql("delete from fm_funds where name like '시험%'")
    sql("delete from fm_change_log where by_name like '시험%'")


cleanup()
people = sql("select id from cm_people where purged_on is null order by seq limit 3").split('\n')
h = subprocess.run([r'C:\xampp\php\php.exe', '-r', f"echo password_hash('{PW}', PASSWORD_DEFAULT);"], capture_output=True, text=True).stdout
sql(f"insert into cm_users (login_id,password_hash,name,person_id,status,grade) values "
    f"('t_admin','{h}','시험관리자','{people[0]}','사용','C'),('t_staff','{h}','시험담당','{people[1]}','사용','C'),"
    f"('t_user','{h}','시험신청자','{people[2]}','사용','C'),('t_nolink','{h}','시험비연결',NULL,'사용','C')")
sql("insert into fm_access (cm_user_id,login_id,name,person_id,status,is_admin) select id,login_id,name,person_id,'사용',1 from cm_users where login_id='t_admin'")
sql("insert into fm_access (cm_user_id,login_id,name,person_id,status) select id,login_id,name,person_id,'사용' from cm_users where login_id='t_staff'")
sql("insert into fm_access_roles select id,'재정 담당' from fm_access where login_id='t_staff'")

adm, stf, usr, nl = Client(), Client(), Client(), Client()
for c, u in ((adm, 't_admin'), (stf, 't_staff'), (usr, 't_user'), (nl, 't_nolink')):
    c.login(u)

# ---- 승인 ----
usr.call('POST', '/auth/request-access', {'reason': '시험'})
sql("insert into fm_access (cm_user_id,login_id,name,person_id,status) select id,login_id,name,person_id,'신청 대기' from cm_users where login_id='t_nolink'")
s, j = stf.call('GET', '/admin/access'); check('재정 담당은 승인 화면 자료 못 봄(403)', s == 403)
s, j = adm.call('GET', '/admin/access'); items = j['data']['items']
uid = next(i['id'] for i in items if i['loginId'] == 't_user')
check('관리자는 신청 대기 목록을 봄', any(i['loginId'] == 't_user' and i['status'] == '신청 대기' for i in items))
s, j = adm.call('POST', f'/admin/access/{uid}', {'status': '사용', 'roles': ['감사']}); check('비밀번호 재확인 없이는 거절', s == 403 and code(j) == 'reconfirm', j)
s, j = adm.call('POST', '/auth/reconfirm', {'password': 'wrong'}); check('재확인 비밀번호 틀림', s == 401)
s, j = adm.call('POST', '/auth/reconfirm', {'password': PW}); check('재확인 성공', s == 200)
s, j = adm.call('POST', f'/admin/access/{uid}', {'status': '사용', 'roles': ['열람'], 'canViewDonors': True}); check('열람 역할에는 개인별 헌금 열람을 켤 수 없음', s == 422, j)
s, j = adm.call('POST', f'/admin/access/{uid}', {'status': '사용', 'roles': ['감사']}); check('감사로 승인', s == 200, j)
s, j = usr.call('GET', '/auth/me'); me = j['data']['me']
check('승인되면 active, 감사 역할, 개인별 헌금은 기본 꺼짐', me['state'] == 'active' and me['access']['roles'] == ['감사'] and not me['access']['canViewDonors'], me)
s, j = adm.call('POST', f'/admin/access/{uid}', {'canViewDonors': True}); check('감사에게 개인별 헌금 열람 지정', s == 200, j)
s, j = usr.call('GET', '/auth/me'); check('지정하면 canViewDonors 켜짐', j['data']['me']['access']['canViewDonors'])
s, j = adm.call('POST', f'/admin/access/{uid}', {'roles': ['열람']}); check('역할을 바꿔 열람 대상이 아니게 되면 저장됨', s == 200, j)
s, j = usr.call('GET', '/auth/me'); check('  → canViewDonors 자동으로 꺼짐', not j['data']['me']['access']['canViewDonors'])
s, j = adm.call('GET', '/admin/log?kind=donor'); check('열람 지정·해제가 기록에 남음', any('개인별 헌금 열람' in i['action'] for i in j['data']['items']), j)
nid = next(i['id'] for i in adm.call('GET', '/admin/access')[1]['data']['items'] if i['loginId'] == 't_nolink')
s, j = adm.call('POST', f'/admin/access/{nid}', {'status': '사용'}); check('교인과 연결 안 된 계정은 승인 거절', s == 409 and code(j) == 'nolink', j)
s, j = adm.call('POST', f'/admin/access/{nid}/reject', {}); check('신청 거절(삭제)', s == 200, j)
myid = next(i['id'] for i in adm.call('GET', '/admin/access')[1]['data']['items'] if i['loginId'] == 't_admin')
s, j = adm.call('POST', f'/admin/access/{myid}', {'status': '정지'}); check('자기 자신은 정지할 수 없음', s == 409 and code(j) == 'self', j)
s, j = usr.call('POST', f'/admin/access/{uid}', {'roles': ['재정 담당']}); check('일반 사용자는 역할 변경 불가(403)', s == 403)

# ---- 기준 자료 ----
s, j = usr.call('POST', '/fiscal-years', {'year': 2098, 'start': '2098-01-01', 'end': '2098-12-31'}); check('열람 사용자는 회계연도 못 만듦', s == 403)
s, j = stf.call('POST', '/fiscal-years', {'year': 2098, 'start': '2098-01-01', 'end': '2098-12-31'}); check('재정 담당이 회계연도 생성', s == 200, j)
s, j = stf.call('POST', '/fiscal-years', {'year': 2098, 'start': '2098-01-01', 'end': '2098-12-31'}); check('같은 연도 중복 거절', s == 409)
s, j = stf.call('POST', '/fiscal-years', {'year': 2097, 'start': '2097-12-31', 'end': '2097-01-01'}); check('끝이 시작보다 빠르면 거절', s == 422)
s, j = stf.call('POST', '/fiscal-years/2098/status', {'status': '마감'}); check('편성 중에서 바로 마감 불가', s == 409)
s, j = stf.call('POST', '/fiscal-years/2098/status', {'status': '진행'}); check('진행 중인 해가 이미 있으면 다른 해를 진행으로 못 바꿈', s == 409, j)
s, j = stf.call('POST', '/funds', {'name': '시험회계'}); check('회계 추가', s == 200, j); fid = j['data']['id']
s, j = stf.call('POST', '/funds', {'name': '시험회계'}); check('회계 이름 중복 거절', s == 409)
s, j = stf.call('POST', '/wallets', {'fundId': fid, 'name': '시험통장', 'kind': '통장', 'bank': '국민', 'acctTail': '1234', 'opening': 1000000}); check('계좌 추가', s == 200, j)
s, j = stf.call('POST', '/wallets', {'fundId': fid, 'name': '시험통장2', 'kind': '통장', 'acctTail': '123-456-789012', 'opening': 0}); check('계좌번호 전체 입력 거절(끝 자리만)', s == 422)
s, j = stf.call('POST', '/wallets', {'fundId': fid, 'name': '시험현금', 'kind': '현금', 'opening': 1.5}); check('소수점 금액 거절', s == 422)
s, j = stf.call('POST', '/income-accounts', {'parentId': 8, 'name': '시험하위'}); check('3단계 항목 추가(절기헌금 아래)', s == 200, j); aid = j['data']['id']
s, j = stf.call('POST', '/income-accounts', {'parentId': aid, 'name': '시험4단계'}); check('4단계 항목 거절', s == 422, j)
s, j = stf.call('POST', f'/income-accounts/{aid}', {'receiptable': True, 'active': False}); check('수입 항목 수정(영수증 포함·사용 중지)', s == 200, j)
b = stf.call('GET', '/base')[1]['data']
check('기준 자료 목록에 반영', any(a['id'] == aid and a['receiptable'] and not a['active'] for a in b['incomeAccounts']) and any(w['name'] == '시험통장' for w in b['wallets']))

# ---- 교인 찾기 ----
nm = sql("select name from cm_people where id='%s'" % people[0])
s, j = stf.call('GET', '/people/search?q=' + quote(nm[:2])); check('이름으로 교인 찾기', s == 200 and any(nm in i['name'] for i in j['data']['items']), j)
cho = ''.join('ㄱㄲㄴㄷㄸㄹㅁㅂㅃㅅㅆㅇㅈㅉㅊㅋㅌㅍㅎ'[(ord(ch) - 0xAC00) // 588] for ch in nm)
s, j = stf.call('GET', '/people/search?q=' + quote(cho)); check('초성으로 교인 찾기', s == 200 and any(nm in i['name'] for i in j['data']['items']), j)
tail = sql("select right(replace(phone,'-',''),4) from cm_people where id='%s'" % people[0])
if tail.isdigit():
    s, j = stf.call('GET', '/people/search?q=' + tail); check('전화 끝자리로 교인 찾기', s == 200 and any(i['id'] == people[0] for i in j['data']['items']), j)
s, j = usr.call('GET', '/people/search?q=' + quote(nm)); check('권한 없는 사용자는 교인 찾기 불가', s == 403)

# ---- 재정 단위 ----
s, j = stf.call('POST', '/units/import-cm', {'year': 2098}); added = j['data']['added'] if s == 200 else -1
check('교적 조직표 가져오기', s == 200 and added > 0, j)
s, j = stf.call('POST', '/units/import-cm', {'year': 2098}); check('다시 가져와도 중복 생성 없음', s == 200 and j['data']['added'] == 0, j)
us = stf.call('GET', '/units?year=2098')[1]['data']['items']
check('위원회·부서·예비비·기타 단위가 있음', {'위원회', '부서', '예비비', '기타'} <= {u['kind'] for u in us}, {u['kind'] for u in us})
dept = next(u for u in us if u['kind'] == '부서')
comm = next(u for u in us if u['kind'] == '위원회' and any(x['parentId'] == u['id'] for x in us))
for r in list(dept['roles']):
    stf.call('POST', f"/unit-roles/{r['id']}/delete", {})
s, j = stf.call('POST', f"/units/{dept['id']}/roles", {'role': '위원장', 'personId': people[1]}); check('부서에 위원장 지정 거절', s == 422)
s, j = stf.call('POST', f"/units/{dept['id']}/roles", {'role': '부서장', 'personId': people[1]}); check('부서장 지정', s == 200, j)
s, j = stf.call('POST', f"/units/{dept['id']}/roles", {'role': '부서장', 'personId': people[2]}); check('부서장은 한 명만', s == 409)
s, j = stf.call('POST', f"/units/{dept['id']}/roles", {'role': '회계', 'personId': people[2]}); check('회계 지정(여러 명 가능)', s == 200, j)
s, j = stf.call('POST', f"/units/{dept['id']}/roles", {'role': '회계', 'personId': '00000000-0000-4000-8000-000000000000'}); check('교적에 없는 교인 지정 거절', s == 422)
s, j = usr.call('GET', '/my/units?year=2098'); mine = j['data']['items'] if s == 200 else []
check('부서 회계로 로그인하면 내 부서가 나옴', any(u['id'] == dept['id'] and u['role'] == '회계' for u in mine), j)
s, j = stf.call('POST', f"/units/{comm['id']}/delete", {}); check('하위 부서가 있는 위원회는 삭제 불가', s == 409, j)
s, j = stf.call('POST', f"/units/{dept['id']}/delete", {}); check('하위가 없는 부서는 삭제됨', s == 200, j)
s, j = stf.call('POST', '/fiscal-years', {'year': 2099, 'start': '2099-01-01', 'end': '2099-12-31'})
s, j = stf.call('POST', '/units/copy-year', {'from': 2098, 'to': 2099}); check('작년 단위 복사', s == 200 and j['data']['copied'] > 0, j)
s, j = stf.call('POST', '/units/copy-year', {'from': 2098, 'to': 2099}); check('이미 단위가 있는 해에는 복사 거절', s == 409)
sql("update fm_fiscal_years set status='마감' where year=2098")
s, j = stf.call('POST', '/units', {'year': 2098, 'kind': '부서', 'name': '시험부서'}); check('마감된 해에는 단위를 못 만듦', s == 409, j)

cleanup()
print('\n실패 %d건' % fails)
sys.exit(1 if fails else 0)
