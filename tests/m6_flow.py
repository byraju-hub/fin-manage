"""M6 시험: 월 마감(대조·순서·승인·풀기), 직접 지출 확인, 연 마감·이월, 결산서, 내려받기."""
import urllib.request
from testkit import *  # noqa

wipe()
U, people = make_users({
    't_admin': ('시험관리자', [], 1, 0, 0),
    't_staff': ('시험담당', ['재정 담당'], 0, 1, 1),
    't_chair': ('시험위원장', ['재정위원장'], 0, 1, 2),
    't_view': ('시험열람', ['열람'], 0, 0, 3),
})
adm, stf, chair, vw = U['t_admin'], U['t_staff'], U['t_chair'], U['t_view']
PW = 'testpass1234'
for c in (adm, chair): c.call('POST', '/auth/reconfirm', {'password': PW})
Y = 2098
stf.call('POST', '/fiscal-years', {'year': Y, 'start': '2098-01-01', 'end': '2098-12-31'})
s, j = stf.call('POST', '/wallets', {'fundId': 1, 'name': '시험통장', 'kind': '통장', 'opening': 1000000}); w1 = j['data']['id']
s, j = stf.call('POST', '/units', {'year': Y, 'kind': '기타', 'name': '시험기타'}); ue = j['data']['id']
s, j = stf.call('POST', '/budget-items', {'unitId': ue, 'name': '시험공과금', 'amount': 500000}); bi = j['data']['id']
adm.call('POST', f'/budget-items/{bi}/direct', {'directOk': True})
s, j = stf.call('POST', '/transactions', {'kind': '수입', 'date': '2098-01-10', 'walletId': w1, 'incomeAccountId': 21, 'amount': 300000, 'summary': '시험 보조금'}); check('1월 수입', s == 200, j)
s, j = stf.call('POST', '/transactions', {'kind': '지출', 'date': '2098-01-20', 'walletId': w1, 'budgetItemId': bi, 'amount': 100000, 'summary': '시험 전기료'}); check('1월 직접 지출', s == 200, j); tdir = j['data']['id']
BOOK = 1000000 + 300000 - 100000

s, j = vw.call('GET', f'/closings?year={Y}'); check('열람 역할은 마감 현황 못 봄', s == 403)
s, j = stf.call('POST', '/closings', {'period': '2098-01', 'actual': {}}); check('실제 잔액을 안 적으면 마감 불가', s == 422)
s, j = stf.call('POST', '/closings', {'period': '2098-01', 'actual': {str(w1): BOOK - 5000}}); check('실제 잔액이 다른데 사유가 없으면 거절', s == 422 and code(j) == 'diff_note', j)
s, j = stf.call('POST', '/closings', {'period': '2098-03', 'actual': {str(w1): BOOK}}); check('앞 달을 건너뛰고 마감 불가', s == 409 and code(j) == 'order', j)
sql("update fm_wallets set is_active=0 where name like '%통장%' and name not like '시험%'")   # 개발 DB 의 다른 계좌가 대조 대상에 끼지 않게
others = {w['id']: w['balance'] for w in stf.call('GET', '/wallets/balances')[1]['data']['items'] if w['active'] or w['balance']}
act = {str(k): v for k, v in others.items()}
s, j = stf.call('POST', '/closings', {'period': '2098-01', 'actual': act}); check('1월 마감', s == 200, j)
s, j = stf.call('POST', '/transactions', {'kind': '수입', 'date': '2098-01-25', 'walletId': w1, 'incomeAccountId': 21, 'amount': 1, 'summary': '시험'}); check('마감한 달에는 거래 불가', s == 409 and code(j) == 'month_closed')
s, j = stf.call('POST', '/closings/2098-01/approve', {}); check('승인은 재정위원장만', s == 403)
s, j = chair.call('POST', '/closings/2098-01/approve', {}); check('직접 지출을 확인하지 않으면 승인 불가', s == 409 and code(j) == 'direct_unchecked', j)
s, j = chair.call('GET', '/direct-expenses?month=2098-01'); check('직접 지출 목록', s == 200 and len(j['data']['items']) == 1 and j['data']['canCheck'], j)
s, j = stf.call('POST', '/direct-expenses/check', {'ids': [tdir]}); check('직접 지출 확인은 재정위원장만', s == 403)
s, j = chair.call('POST', '/direct-expenses/check', {'ids': [tdir]}); check('직접 지출 일괄 확인', s == 200, j)
s, j = chair.call('POST', '/closings/2098-01/approve', {}); check('월 마감 승인', s == 200, j)
s, j = stf.call('POST', '/closings/2098-01/reopen', {'reason': 'x'}); check('마감 풀기는 재정위원장만', s == 403)
s, j = chair.call('POST', '/closings/2098-01/reopen', {}); check('마감 풀기는 사유 필수', s == 422)
s, j = chair.call('POST', '/closings/2098-01/reopen', {'reason': '시험 정정'}); check('마감 풀기', s == 200, j)
s, j = stf.call('POST', '/transactions', {'kind': '수입', 'date': '2098-01-25', 'walletId': w1, 'incomeAccountId': 21, 'amount': 7, 'summary': '시험 정정수입'}); check('풀면 다시 거래 가능', s == 200, j)
others = {w['id']: w['balance'] for w in stf.call('GET', '/wallets/balances')[1]['data']['items'] if w['active'] or w['balance']}
act = {str(k): v for k, v in others.items()}
s, j = stf.call('POST', '/closings', {'period': '2098-01', 'actual': act}); check('다시 마감', s == 200, j)
chair.call('POST', '/closings/2098-01/approve', {})

# 12개월 마감·승인 → 연 마감
s, j = chair.call('POST', f'/fiscal-years/{Y}/close-year', {}); check('모든 달이 마감되지 않으면 연 마감 불가', s == 409 and code(j) == 'months', j)
for m in range(2, 13):
    others = {w['id']: w['balance'] for w in stf.call('GET', '/wallets/balances')[1]['data']['items'] if w['active'] or w['balance']}
    s, j = stf.call('POST', '/closings', {'period': '2098-%02d' % m, 'actual': {str(k): v for k, v in others.items()}}); assert s == 200, j
    s, j = chair.call('POST', '/closings/2098-%02d/approve' % m, {}); assert s == 200, j
s, j = stf.call('GET', f'/closings?year={Y}'); check('12개월 모두 승인됨', all(m['status'] == '승인됨' for m in j['data']['months']) and len(j['data']['months']) == 12, [m['status'] for m in j['data']['months']])
s, j = chair.call('POST', f'/fiscal-years/{Y}/close-year', {}); check('다음 해 회계연도가 없으면 연 마감 불가', s == 409 and code(j) == 'no_next', j)
stf.call('POST', '/fiscal-years', {'year': 2099, 'start': '2099-01-01', 'end': '2099-12-31'})
before = {w['id']: w['balance'] for w in stf.call('GET', '/wallets/balances')[1]['data']['items']}
s, j = stf.call('POST', f'/fiscal-years/{Y}/close-year', {}); check('연 마감은 재정위원장만', s == 403)
s, j = chair.call('POST', f'/fiscal-years/{Y}/close-year', {}); check('연 마감', s == 200, j)
check('이 해가 마감 상태', stf.call('GET', '/base')[1]['data']['years'][1]['status'] in ('마감', '편성 중') and sql("select status from fm_fiscal_years where year=2098") == '마감')
check('다음 해에 이월 거래가 생김(계좌 잔액만큼)', int(sql("select coalesce(sum(amount),0) from fm_transactions where kind='이월' and fiscal_year=2099 and wallet_id=%d" % w1)) == before[w1], before)
after = {w['id']: w['balance'] for w in stf.call('GET', '/wallets/balances')[1]['data']['items']}
check('이월해도 계좌 잔액은 그대로(이중으로 더해지지 않음)', after == before, (before, after))
s, j = chair.call('POST', '/closings/2098-12/reopen', {'reason': 'x'}); check('연 마감된 해의 월 마감은 풀 수 없음', s == 409 and code(j) == 'closed', j)
s, j = stf.call('POST', '/transactions', {'kind': '수입', 'date': '2098-12-30', 'walletId': w1, 'incomeAccountId': 21, 'amount': 1, 'summary': '시험'}); check('마감된 해에는 거래 불가', s == 409)
s, j = vw.call('GET', f'/reports/settlement?year={Y}'); d = j['data'] if s == 200 else {}
check('결산서: 수입 결산 300,007, 지출 100,000', s == 200 and sum(i['actual'] for i in d['income']) == 300007 and sum(i['actual'] for u in d['expense'] for i in u['items']) == 100000, d)
for path, who in (('/export/settlement?year=%d' % Y, vw), ('/export/ledger?year=%d' % Y, stf)):
    r = who.op.open(urllib.request.Request('http://127.0.0.1:8091/api/v1' + path)); txt = r.read().decode('utf-8-sig')
    check('CSV 내려받기 ' + path.split('?')[0], r.status == 200 and ('시험' in txt or '수입' in txt) and 'text/csv' in r.headers['Content-Type'], txt[:200])
try:
    vw.op.open(urllib.request.Request('http://127.0.0.1:8091/api/v1/export/ledger?year=%d' % Y)); check('열람 역할은 장부 내려받기 불가', False)
except urllib.error.HTTPError as e:
    check('열람 역할은 장부 내려받기 불가', e.code == 403)

sql("update fm_wallets set is_active=1 where name like '%통장%' and name not like '시험%'")
sql("update fm_fiscal_years set status='편성 중' where year in (2098,2099)")
sql("delete from fm_closings where period like '2098-%' or period like '2099-%'")
wipe()
finish()
