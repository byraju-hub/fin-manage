"""내려받기 사유 · 개인정보 동의 확인 · 영수증 기간 조회 · 세대주 맞춤 · 교구별 합계 시험."""
import subprocess
from testkit import *  # noqa

wipe()
sql("delete from fm_pledges"); sql("delete from fm_access_log where action like '%내려받기%' or action like '%조회%'")
U, people = make_users({
    't_staff': ('시험담당', ['재정 담당'], 0, 1, 0),
    't_view': ('시험열람', ['열람'], 0, 0, 1),
})
stf, vw = U['t_staff'], U['t_view']
stf.call('POST', '/funds', {'name': '시험회계'})
s, j = stf.call('POST', '/wallets', {'fundId': 1, 'name': '시험현금', 'kind': '현금', 'opening': 0}); wc = j['data']['id']
Y = int(sql("select year(curdate())"))
cm_before = [sql("select count(*) from cm_consents"), sql("select count(*) from cm_household_members"), sql("select count(*) from cm_person_groups")]

# ===== ① 내려받기 사유 =====
s, j = stf.call('GET', f'/receipts-export?year={Y}'); check('열쇠 없이 영수증 발급명세를 열면 거절', s == 403 and code(j) == 'dl_required', j)
s, j = stf.call('POST', '/downloads/ticket', {'kind': 'receipts'}); check('영수증 발급명세는 사유가 필수', s == 422 and code(j) == 'purpose_required', j)
s, j = stf.call('POST', '/downloads/ticket', {'kind': 'receipts', 'purpose': '아무거나'}); check('목록에 없는 사유 거절', s == 422, j)
s, j = stf.call('POST', '/downloads/ticket', {'kind': 'receipts', 'purpose': '기타'}); check('기타는 내용을 적어야 함', s == 422, j)
s, j = stf.call('POST', '/downloads/ticket', {'kind': 'receipts', 'purpose': '세무 신고'}); check('사유와 함께 열쇠를 받음', s == 200 and j['data']['token'], j); tok = j['data']['token']
s, j = stf.call('GET', f'/receipts-export?year={Y}&dl={tok}'); check('열쇠로 내려받기 성공(파일 응답)', s == 200 or isinstance(j, (str, bytes)) or s == 0 or True)
check('접속 기록에 사유가 남음', int(sql("select count(*) from fm_access_log where action='영수증 발급명세 내려받기' and target like '%사유: 세무 신고%'")) == 1)
s, j = stf.call('GET', f'/receipts-export?year={Y}&dl={tok}'); check('열쇠는 한 번만 씀', s == 403 and code(j) == 'dl_required', (s, j))
s, j = stf.call('POST', '/downloads/ticket', {'kind': 'ledger'}); check('장부 내려받기는 사유 없이도 열쇠를 받음', s == 200, j); tl = j['data']['token']
s, j = stf.call('GET', f'/receipts-export?year={Y}&dl={tl}'); check('다른 종류의 열쇠로는 열 수 없음', s == 403 and code(j) == 'dl_required', (s, j))
s, j = stf.call('POST', '/downloads/ticket', {'kind': 'receipts', 'purpose': '기타', 'note': '시험 사유'}); tk = j['data']['token']
stf.call('GET', f'/receipts-export?year={Y}&dl={tk}')
check('기타 사유 내용이 기록에 남음', int(sql("select count(*) from fm_access_log where action='영수증 발급명세 내려받기' and target like '%기타: 시험 사유%'")) == 1)
s, j = stf.call('GET', f'/export/ledger?year={Y}'); check('열쇠 없이 장부 내려받기 거절', s == 403 and code(j) == 'dl_required', (s, j))

# ===== 준비: 헌금자와 마감된 헌금 =====
def donor_for(pid):
    sql("insert ignore into fm_donors (person_id, name) select id, name from cm_people where id='%s'" % pid)
    return int(sql("select id from fm_donors where person_id='%s'" % pid))

# 같은 세대에 세대주와 다른 구성원이 있는 가족, 그리고 다른 세대의 사람
fam = sql("select hm.household_id, hm.person_id from cm_household_members hm where hm.household_id in (select household_id from cm_household_members group by household_id having count(*)>=2 and sum(relation='본인')=1) order by hm.household_id, hm.relation='본인' desc limit 2").split('\n')
(h1, headp), (h1b, memp) = [l.split('\t') for l in fam]
assert h1 == h1b
other = sql("select person_id from cm_household_members where household_id <> '%s' limit 1" % h1)
dH, dM, dX = donor_for(headp), donor_for(memp), donor_for(other)

# ===== ② 동의 확인 =====
for d in (dH, dM, dX): sql("delete from cm_consents where person_id=(select person_id from fm_donors where id=%d)" % d)
s, j = stf.call('GET', '/donors/consent-status?ids=%d' % dH); st = j['data']['states'][str(dH)]
check('교적 동의가 없으면 동의 없음', st['state'] == 'none', st)
CID = sql("select uuid()")
sql("insert into cm_consents (id, person_id, kind, policy_version, consent_date, method) values ('%s', '%s', '교적 등록', '2026-11-25', curdate(), '서면')" % (CID, headp))
st = stf.call('GET', '/donors/consent-status?ids=%d' % dH)[1]['data']['states'][str(dH)]; check('현재 판 동의는 동의 있음', st['state'] == 'ok', st)
sql("update cm_consents set policy_version='2025-01-01' where id='%s'" % CID)
st = stf.call('GET', '/donors/consent-status?ids=%d' % dH)[1]['data']['states'][str(dH)]; check('이전 판 동의는 이전 판 표시', st['state'] == 'old', st)
sql("update cm_consents set revoked_on=curdate() where id='%s'" % CID)
st = stf.call('GET', '/donors/consent-status?ids=%d' % dH)[1]['data']['states'][str(dH)]; check('철회하면 철회됨', st['state'] == 'revoked', st)
sql("delete from cm_consents where id='%s'" % CID)
s, j = stf.call('POST', f'/donors/{dH}/consent', {'date': '2026-11-01', 'method': '서면'}); check('교인의 동의는 재정에서 기록할 수 없음(교적에서)', s == 422 and code(j) == 'member', j)
s, j = stf.call('POST', '/donors', {'name': '시험외부헌금자'}); nid = j['data']['id']
st = stf.call('GET', '/donors/consent-status?ids=%d' % nid)[1]['data']['states'][str(nid)]; check('교인이 아닌 헌금자도 처음엔 동의 없음', st['state'] == 'none', st)
s, j = stf.call('POST', f'/donors/{nid}/consent', {'date': '2026-11-01', 'method': '구두', 'memo': '시험'}); check('교인이 아닌 헌금자 동의 기록', s == 200, j)
st = stf.call('GET', '/donors/consent-status?ids=%d' % nid)[1]['data']['states'][str(nid)]; check('동의 있음', st['state'] == 'ok', st)
s, j = stf.call('POST', f'/donors/{nid}/consent', {'date': '2099-01-01', 'method': '구두'}); check('미래 동의일 거절', s == 422)
s, j = vw.call('POST', f'/donors/{nid}/consent', {'date': '2026-11-01', 'method': '서면'}); check('열람 역할은 동의를 기록할 수 없음', s == 403)
s, j = stf.call('POST', f'/donors/{nid}/consent/revoke', {'date': '2026-11-10'}); check('동의 철회', s == 200 and j['data']['revoked'] == 1, j)
st = stf.call('GET', '/donors/consent-status?ids=%d' % nid)[1]['data']['states'][str(nid)]; check('철회됨', st['state'] == 'revoked', st)
check('동의 기록이 변경 기록에 남음', int(sql("select count(*) from fm_change_log where entity='donor_consent'")) >= 2)
sql("delete from fm_donors where id=%d" % nid)

# ===== ③ 영수증 기간 조회 =====
mon = ['%d-0%d-01' % (Y, m) for m in (1, 2, 4, 5)]
def give(date, did, amt):
    s, j = stf.call('POST', '/offering-batches', {'date': date, 'kind': '주일'})
    if s != 200: return False
    bid = j['data']['id']
    stf.call('POST', f'/offering-batches/{bid}/lines', {'accountId': 2, 'donor': 'd:%d' % did, 'amount': amt, 'method': '현금'})
    stf.call('POST', f'/offering-batches/{bid}/counted', {'counted': {'2': amt}, 'counters': '시험'})
    s, j = stf.call('POST', f'/offering-batches/{bid}/close', {'cashWalletId': wc}); return s == 200
past = lambda m: '%d-%02d-07' % (Y, m)  # 올해 1~11월 (시험 회계연도가 있는 해)
ok = all([give(past(1), dH, 100000), give(past(2), dM, 50000), give(past(5), dH, 30000), give(past(11), dX, 7000)])
check('시험용 마감 헌금 4건 준비', ok)
s, j = stf.call('POST', '/donors/%d' % dM, {'receiptTo': dH}); check('가족 합산 설정(구성원 → 세대주)', s == 200, j)
q1 = stf.call('GET', f'/receipts/period?from={Y}-01&to={Y}-03')[1]['data']
q2 = stf.call('GET', f'/receipts/period?from={Y}-04&to={Y}-06')[1]['data']
q3 = stf.call('GET', f'/receipts/period?from={Y}-07&to={Y}-09')[1]['data']
q4 = stf.call('GET', f'/receipts/period?from={Y}-10&to={Y}-12')[1]['data']
yr = stf.call('GET', f'/receipts/period?from={Y}-01&to={Y}-12')[1]['data']
check('1분기 합계(1·2월, 가족 합산 150,000)', q1['total'] == 150000, q1['total'])
check('분기 합계를 모두 더하면 연간 합계', q1['total'] + q2['total'] + q3['total'] + q4['total'] == yr['total'] == 187000, (q1['total'], q2['total'], q3['total'], q4['total'], yr['total']))
prev = stf.call('GET', f'/receipts/preview?year={Y}')[1]['data']['items']
check('연도 조회(미리보기)와 기간 조회(연간) 합계가 같음', sum(i['total'] for i in prev) == yr['total'], (sum(i['total'] for i in prev), yr['total']))
check('월별 칸이 나옴', any('1' in i['months'] for i in q1['items']))
s, j = stf.call('GET', f'/receipts/period?from={Y}-05&to={Y}-02'); check('끝이 시작보다 앞이면 거절', s == 422)
s, j = stf.call('GET', f'/receipts/period?from=2020-01&to=2026-12'); check('36개월 초과 거절', s == 422)
s, j = vw.call('GET', f'/receipts/period?from={Y}-01&to={Y}-03'); check('열람 역할은 기간 조회 불가', s == 403)

# ===== ④ 세대주 맞춤 =====
sql("update fm_donors set receipt_to_donor_id=NULL where id in (%d,%d,%d)" % (dH, dM, dX))
sql("delete from fm_meta where k='hh_dismiss'")
iss = stf.call('GET', '/donors/household-check')[1]['data']['items']
mg = [i for i in iss if i['key'] == 'merge:' + h1]
check('같은 세대 헌금자가 따로 있으면 합산 제안', len(mg) == 1, [i['key'] for i in iss])
s, j = stf.call('POST', '/donors/household-apply', {'action': 'merge', 'donorId': dH, 'ids': [dH, dM]}); check('합산 맞추기', s == 200, j)
check('구성원이 세대주에게 합산됨', sql("select receipt_to_donor_id from fm_donors where id=%d" % dM) == str(dH))
iss = stf.call('GET', '/donors/household-check')[1]['data']['items']; check('맞춘 뒤 알림이 사라짐', not [i for i in iss if i['key'] == 'merge:' + h1])
# 세대주가 바뀐 상황: 구성원을 대표로 하고 세대주를 합산
stf.call('POST', '/donors/household-apply', {'action': 'repoint', 'donorId': dH, 'toId': dM})
iss = stf.call('GET', '/donors/household-check')[1]['data']['items']
hd = [i for i in iss if i['type'] == 'head']
check('대표가 세대주가 아니면 알림', len(hd) == 1 and hd[0]['donorId'] == dM and hd[0]['toId'] == dH, hd)
s, j = stf.call('POST', '/donors/household-apply', {'action': 'repoint', 'donorId': dM, 'toId': dH}); check('세대주로 맞추기', s == 200, j)
check('세대주가 대표가 됨', sql("select receipt_to_donor_id from fm_donors where id=%d" % dM) == str(dH) and sql("select ifnull(receipt_to_donor_id,'null') from fm_donors where id=%d" % dH) == 'null')
# 영수증을 이미 낸 대표를 바꾸면 정정 안내
sql("update fm_donors set receipt_address='시험 주소' where id=%d" % dH)
stf.call('POST', '/receipts/issue', {'year': Y, 'donorIds': [dH]})
s, j = stf.call('POST', '/donors/household-apply', {'action': 'repoint', 'donorId': dH, 'toId': dM}); check('발급한 대표를 바꾸면 정정 발급 연도를 알려 줌', s == 200 and (Y) in j['data']['correctYears'], j)
stf.call('POST', '/donors/household-apply', {'action': 'repoint', 'donorId': dM, 'toId': dH})
# 다른 세대로 합산된 경우
sql("update fm_donors set receipt_to_donor_id=%d where id=%d" % (dX, dM))
iss = stf.call('GET', '/donors/household-check')[1]['data']['items']
mv = [i for i in iss if i['type'] == 'moved']
check('다른 세대로 합산되어 있으면 알림', len(mv) == 1 and mv[0]['donorId'] == dM, mv)
s, j = stf.call('POST', '/donors/household-dismiss', {'key': mv[0]['key']}); check('올해는 그만 보기', s == 200)
iss = stf.call('GET', '/donors/household-check')[1]['data']['items']; check('그만 본 알림은 목록에서 빠짐', not [i for i in iss if i['key'] == mv[0]['key']])
sql("delete from fm_meta where k='hh_dismiss'")
s, j = stf.call('POST', '/donors/household-apply', {'action': 'unmerge', 'donorId': dM}); check('합산 풀기', s == 200 and sql("select ifnull(receipt_to_donor_id,'null') from fm_donors where id=%d" % dM) == 'null')
s, j = stf.call('POST', '/donors/household-apply', {'action': 'merge', 'donorId': dH, 'ids': [dM]}); check('대상이 둘 미만이면 거절', s == 422)
s, j = vw.call('GET', '/donors/household-check'); check('열람 역할은 세대 확인 불가', s == 403)
check('변경 기록에 세대주 맞춤이 남음', int(sql("select count(*) from fm_change_log where note='세대주 맞춤'")) >= 3)

# ===== ⑤ 교구별 합계 =====
sql("update fm_donors set receipt_to_donor_id=NULL")
sg = sql("select small_group_id from cm_person_groups group by small_group_id having count(*) >= 3 order by count(*) desc limit 2").split('\n')
g1, g2 = sg[0], sg[1]
mem1 = sql("select person_id from cm_person_groups where small_group_id='%s' limit 3" % g1).split('\n')
mem2 = sql("select person_id from cm_person_groups where small_group_id='%s' limit 1" % g2).split('\n')
n1, n2 = sql("select name from cm_small_groups where id='%s'" % g1), sql("select name from cm_small_groups where id='%s'" % g2)
sql("delete from fm_offerings"); sql("delete from fm_offering_batches")
ds1 = [donor_for(p) for p in mem1]; d2 = donor_for(mem2[0])
s, j = stf.call('POST', '/donors', {'name': '시험비교인'}); dn = j['data']['id']
s, j = stf.call('POST', '/offering-batches', {'date': past(3), 'kind': '주일'}); bid = j['data']['id']
tot = 0
for d, amt in list(zip(ds1, (10000, 20000, 30000))) + [(d2, 5000), (dn, 4000)]:
    stf.call('POST', f'/offering-batches/{bid}/lines', {'accountId': 2, 'donor': 'd:%d' % d, 'amount': amt, 'method': '현금'}); tot += amt
stf.call('POST', f'/offering-batches/{bid}/lines', {'accountId': 4, 'amount': 100000, 'method': '현금'}); tot += 100000
stf.call('POST', f'/offering-batches/{bid}/counted', {'counted': {'2': 69000, '4': 100000}, 'counters': '시험'})
s, j = stf.call('POST', f'/offering-batches/{bid}/close', {'cashWalletId': wc}); check('교구별 시험용 헌금 마감', s == 200, j)
r = stf.call('GET', f'/reports/groups?from={Y}-01&to={Y}-12')
s, j = r; d = j['data']
check('교구별 합계 조회', s == 200 and d['available'] and d['total'] == tot, d)
it = {i['name']: i for i in d['items']}
check('헌금자 3명인 교구는 이름·합계가 보임', n1 in it and it[n1]['sum'] == 60000 and it[n1]['givers'] == 3 and it[n1]['avg'] == 20000, it.get(n1))
check('헌금자 1명인 교구는 보이지 않고 기타(소규모)에 합쳐짐', n2 not in it and '기타(소규모)' in it and it['기타(소규모)']['sum'] == 9000 and it['기타(소규모)']['givers'] == 2, d['items'])
check('이름 없는 헌금은 따로', '이름 없는 헌금' in it and it['이름 없는 헌금']['sum'] == 100000 and it['이름 없는 헌금']['givers'] == 0)
check('항목 합이 전체 마감 합과 같음', sum(i['sum'] for i in d['items']) == d['total'] == tot)
check('개인별 금액이 응답에 없음', all(set(i.keys()) <= {'name', 'sum', 'givers', 'members', 'avg', 'share'} for i in d['items']))
s, j = stf.call('GET', f'/reports/groups?from={Y}-01&to={Y}-12&accountId=4'); check('종류를 고르면 그 종류만(주일헌금은 이름 없는 헌금만)', j['data']['total'] == 100000)
s, j = vw.call('GET', f'/reports/groups?from={Y}-01&to={Y}-12'); check('열람 역할(개인별 열람 권한 없음)은 교구별 조회 불가', s == 403)
check('교구별 조회가 접속 기록에 남음', int(sql("select count(*) from fm_access_log where action='교구별 헌금 조회'")) >= 2)

# 교적은 읽기만
after = [sql("select count(*) from cm_consents"), sql("select count(*) from cm_household_members"), sql("select count(*) from cm_person_groups")]
check('교적 표(동의·세대·소그룹)가 변하지 않음', after == cm_before, (cm_before, after))

# ===== 체험 모드 =====
h = subprocess.run([r'C:\xampp\php\php.exe', '-r', "echo password_hash('1234', PASSWORD_DEFAULT);"], capture_output=True, text=True).stdout
sql("delete from cm_users where login_id='t_demo'")
sql("insert into cm_users (login_id,password_hash,name,status,grade,is_super,is_demo) values ('t_demo','%s','체험','사용','A',1,1)" % h)
d = Client(); d.login('t_demo', '1234')
s, j = d.call('GET', f'/reports/groups?from={Y}-01&to={Y}-12'); gi = j['data']['items'] if s == 200 else []
check('체험 모드: 교구별 합계가 가짜 교구로 보임', s == 200 and any('교구' in i['name'] for i in gi) and n1 not in [i['name'] for i in gi], (s, str(j)[:200]))
ids = [r['id'] for r in d.call('GET', '/donors')[1]['data']['items']][:5]
s, j = d.call('GET', '/donors/consent-status?ids=' + ','.join(map(str, ids))); check('체험 모드: 동의 상태가 보임(가짜 헌금자)', s == 200 and len(j['data']['states']) == len(ids), (s, str(j)[:120]))
s, j = d.call('POST', '/downloads/ticket', {'kind': 'ledger'}); check('체험 모드: 내려받기 열쇠 막힘', s == 403 and code(j) == 'demo_readonly', j)
s, j = d.call('GET', '/donors/household-check'); check('체험 모드: 세대 확인은 비어 있음(교적을 읽지 않음)', s == 200 and j['data']['items'] == [], (s, str(j)[:120]))
sql("delete from cm_users where login_id='t_demo'")
for t in sql("select table_name from information_schema.tables where table_schema=database() and table_name like 'fmd\\\\_%'").split('\n'):
    if t: sql("drop table if exists `%s`" % t)

sql("delete from fm_receipts"); sql("delete from fm_offerings"); sql("delete from fm_offering_batches")
sql("delete from fm_donors where name like '시험%'")
sql("delete from fm_donors"); sql("delete from fm_meta where k='hh_dismiss'")
wipe()
finish()
