"""M7 시험: 통장 내역으로 계좌이체 헌금 맞추기."""
from testkit import *  # noqa

wipe()
U, people = make_users({'t_staff': ('시험담당', ['재정 담당'], 0, 1, 0), 't_view': ('시험열람', ['열람'], 0, 0, 1)})
stf, vw = U['t_staff'], U['t_view']
s, j = stf.call('POST', '/offering-batches', {'date': '2026-11-01', 'kind': '계좌이체분'}); bid = j['data']['id']
nm = sql("select name from cm_people where purged_on is null group by name having count(*)=1 order by min(seq) limit 1")
dup = sql("select name from cm_people where purged_on is null group by name having count(*)>1 limit 1")
rows_ = [{'name': nm, 'amount': 50000}, {'name': dup, 'amount': 10000}, {'name': '없는이름시험', 'amount': 7000}, {'name': nm, 'amount': 'abc'}]
s, j = vw.call('POST', f'/offering-batches/{bid}/import', {'accountId': 2, 'rows': rows_}); check('열람 역할은 가져오기 불가', s == 403)
s, j = stf.call('POST', f'/offering-batches/{bid}/import', {'accountId': 4, 'rows': rows_}); check('이름 없는 헌금 종류에는 가져오기 불가', s == 422)
s, j = stf.call('POST', f'/offering-batches/{bid}/import', {'accountId': 2, 'rows': rows_}); d = j['data']
check('한 명으로 맞는 이름만 들어가고 나머지는 이유와 함께 돌려줌', s == 200 and d['added'] == 1 and len(d['unmatched']) == 3, j)
check('동명이인은 자동으로 넣지 않음', any('여러 명' in u['why'] for u in d['unmatched']) if dup else True)
s, j = stf.call('GET', f'/offering-batches/{bid}'); l = j['data']['lines']
check('계좌이체 헌금으로 입력됨', len(l) == 1 and l[0]['method'] == '계좌이체' and l[0]['amount'] == 50000 and l[0]['donor'] == nm, l)
s, j = stf.call('POST', f'/offering-batches/{bid}/import', {'accountId': 2, 'rows': []}); check('빈 내역 거절', s == 422)
wipe()
finish()
