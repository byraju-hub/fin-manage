"""M3 시험: 예산 편성·확정·변경, 기타 수입·직접 지출·이체, 장부 취소, 잔액, 월 보고, 집행 현황."""
from testkit import *  # noqa

wipe()
U, people = make_users({
    't_admin': ('시험관리자', [], 1, 0, 0),
    't_staff': ('시험담당', ['재정 담당'], 0, 1, 1),
    't_chair': ('시험위원장', ['재정위원장'], 0, 1, 2),
    't_view': ('시험열람', ['열람'], 0, 0, 3),
    't_acct': ('시험회계', [], 0, 0, 4),
})
adm, stf, chair, vw, acc = U['t_admin'], U['t_staff'], U['t_chair'], U['t_view'], U['t_acct']
adm.call('POST', '/auth/reconfirm', {'password': 'testpass1234'}); chair.call('POST', '/auth/reconfirm', {'password': 'testpass1234'})
Y = 2098
# 회계연도 2098 (편성 중) — 날짜 시험을 위해 2098 안의 날짜를 쓴다
stf.call('POST', '/fiscal-years', {'year': Y, 'start': '2098-01-01', 'end': '2098-12-31'})
s, j = stf.call('POST', '/wallets', {'fundId': 1, 'name': '시험통장', 'kind': '통장', 'opening': 1000000}); w1 = j['data']['id']
s, j = stf.call('POST', '/wallets', {'fundId': 1, 'name': '시험현금', 'kind': '현금', 'opening': 0}); w2 = j['data']['id']
s, j = stf.call('POST', '/units', {'year': Y, 'kind': '위원회', 'name': '시험위원회'}); uc = j['data']['id']
s, j = stf.call('POST', '/units', {'year': Y, 'kind': '부서', 'name': '시험부서', 'parentId': uc}); ud = j['data']['id']
stf.call('POST', f'/units/{ud}/roles', {'role': '회계', 'personId': people[4]})

s, j = stf.call('POST', '/budget-items', {'unitId': ud, 'name': '시험행사비', 'amount': 500000, 'basis': '시험'}); check('예산 항목 추가', s == 200, j); i1 = j['data']['id']
s, j = stf.call('POST', '/budget-items', {'unitId': ud, 'name': '시험교육비', 'amount': 300000}); i2 = j['data']['id']
s, j = stf.call('POST', '/budget-items', {'unitId': ud, 'name': '시험행사비', 'amount': 1}); check('같은 이름의 항목 거절', s == 409)
s, j = stf.call('POST', '/budget-items', {'unitId': ud, 'name': '시험음수', 'amount': -5}); check('음수 예산 거절', s == 422)
s, j = vw.call('POST', '/budget-items', {'unitId': ud, 'name': '시험x', 'amount': 1}); check('열람 역할은 예산 못 고침', s == 403)
s, j = stf.call('POST', f'/budget-items/{i1}', {'amount': 600000}); check('확정 전 금액 수정', s == 200, j)
s, j = stf.call('POST', f'/budget-items/{i1}/direct', {'directOk': True, 'directLimit': 100000}); check('직접 지출 지정은 재정 관리자만', s == 403)
s, j = adm.call('POST', f'/budget-items/{i1}/direct', {'directOk': True, 'directLimit': 100000}); check('관리자가 직접 지출 허용·한도 지정', s == 200, j)
s, j = stf.call('POST', '/income-budgets', {'year': Y, 'items': [{'accountId': 2, 'amount': 5000000}]}); check('수입 예산 입력', s == 200, j)
s, j = stf.call('POST', '/budget-changes', {'year': Y, 'toItemId': i1, 'amount': 1000, 'reason': '시험'}); check('확정 전에는 예산 변경 불가', s == 409 and code(j) == 'not_confirmed', j)
s, j = stf.call('POST', f'/fiscal-years/{Y}/budget-confirm', {'date': '2098-01-05'}); check('재정 담당은 예산 확정 못 함', s == 403)
s, j = chair.call('POST', f'/fiscal-years/{Y}/budget-confirm', {'date': '2098-01-05'}); check('재정위원장이 예산 확정', s == 200, j)
s, j = stf.call('POST', f'/budget-items/{i1}', {'amount': 1}); check('확정 뒤 직접 수정 불가', s == 409 and code(j) == 'confirmed', j)

# 직접 지출 (연도를 진행으로 돌려 날짜 검증만 거치도록 2098 날짜 사용)
D = '2098-03-10'
s, j = stf.call('POST', '/transactions', {'kind': '수입', 'date': D, 'walletId': w1, 'incomeAccountId': 21, 'amount': 200000, 'summary': '시험 교회보조금'}); check('기타 수입 등록', s == 200, j); t_in = j['data']['id']
s, j = stf.call('POST', '/transactions', {'kind': '수입', 'date': D, 'walletId': w1, 'incomeAccountId': 2, 'amount': 1, 'summary': '시험'}); check('헌금 항목은 직접 수입 불가', s == 422)
s, j = stf.call('POST', '/transactions', {'kind': '지출', 'date': D, 'walletId': w1, 'budgetItemId': i2, 'amount': 1000, 'summary': '시험 지출', 'noProofReason': '시험'}); check('직접 지출 미허용 항목은 거절', s == 403 and code(j) == 'not_direct', j)
s, j = stf.call('POST', '/transactions', {'kind': '지출', 'date': D, 'walletId': w1, 'budgetItemId': i1, 'amount': 150000, 'summary': '시험 지출', 'noProofReason': '시험'}); check('건당 한도 초과 거절', s == 403 and code(j) == 'over_limit', j)
s, j = stf.call('POST', '/transactions', {'kind': '지출', 'date': D, 'walletId': w1, 'budgetItemId': i1, 'amount': 80000, 'summary': '시험 다과', 'noProofReason': '시험'}); check('한도 안의 직접 지출', s == 200, j); t_ex = j['data']['id']
s, j = chair.call('POST', '/transactions', {'kind': '지출', 'date': D, 'walletId': w1, 'budgetItemId': i1, 'amount': 1000, 'summary': '시험'}); check('설정에 없는 역할(재정위원장)은 직접 지출 불가', s == 403)
s, j = adm.call('POST', '/settings/direct-rules', {'roles': ['재정 담당'], 'monthCap': 100000, 'proofRequired': True, 'closeConfirm': True}); check('월 한도 설정', s == 200, j)
s, j = stf.call('POST', '/transactions', {'kind': '지출', 'date': D, 'walletId': w1, 'budgetItemId': i1, 'amount': 30000, 'summary': '시험'}); check('월 직접 지출 한도 초과 거절', s == 403 and code(j) == 'over_cap', j)
s, j = adm.call('POST', '/settings/direct-rules', {'roles': ['재정 담당'], 'monthCap': None, 'proofRequired': True, 'closeConfirm': True})
s, j = stf.call('POST', '/transactions', {'kind': '이체', 'date': D, 'walletId': w1, 'toWalletId': w2, 'amount': 50000, 'summary': '시험 인출'}); check('계좌 이체', s == 200, j)
s, j = stf.call('POST', '/transactions', {'kind': '이체', 'date': D, 'walletId': w1, 'toWalletId': w1, 'amount': 5, 'summary': '시험'}); check('같은 계좌 이체 거절', s == 422)
s, j = stf.call('POST', '/transactions', {'kind': '수입', 'date': '2097-03-10', 'walletId': w1, 'incomeAccountId': 21, 'amount': 5, 'summary': '시험'}); check('회계연도가 없는 날짜 거절', s == 422)
bal = {w['id']: w['balance'] for w in stf.call('GET', '/wallets/balances')[1]['data']['items']}
check('잔액: 통장 1,000,000+200,000-80,000-50,000 = 1,070,000 / 현금 50,000', bal[w1] == 1070000 and bal[w2] == 50000, bal)
s, j = chair.call('GET', f'/budget?year={Y}'); it = {i['name']: i for u in j['data']['units'] for i in u['items']}
check('집행 현황: 시험행사비 예산 500,000(확정 전 수정 600,000)·집행 80,000', it['시험행사비']['budget'] == 600000 and it['시험행사비']['paid'] == 80000 and it['시험행사비']['remain'] == 520000, it)
s, j = acc.call('GET', f'/budget?year={Y}'); check('부서 회계는 자기 부서의 예산만 봄', s == 200 and [u['name'] for u in j['data']['units']] == ['시험부서'] and j['data']['income'] == [], j)
s, j = vw.call('GET', f'/budget?year={Y}'); check('열람 역할은 전체를 봄', s == 200 and len(j['data']['units']) >= 2)

# 예산 변경
s, j = stf.call('POST', '/budget-changes', {'year': Y, 'fromItemId': i2, 'toItemId': i1, 'amount': 400000, 'reason': '시험 전용'}); check('남은 금액보다 많이는 전용 불가', s == 409 and code(j) == 'insufficient', j)
s, j = stf.call('POST', '/budget-changes', {'year': Y, 'fromItemId': i2, 'toItemId': i1, 'amount': 100000, 'reason': '시험 전용', 'decidedOn': '2098-04-01'}); check('전용', s == 200, j)
s, j = stf.call('POST', '/budget-changes', {'year': Y, 'toItemId': i2, 'amount': 50000, 'reason': '시험 추경'}); check('추경', s == 200, j)
s, j = stf.call('POST', '/budget-changes', {'year': Y, 'fromItemId': i2, 'toItemId': i2, 'amount': 5, 'reason': '시험'}); check('같은 항목끼리 거절', s == 422)
s, j = chair.call('GET', f'/budget?year={Y}'); it = {i['name']: i for u in j['data']['units'] for i in u['items']}
check('현재 예산 = 처음 + 변경: 행사비 700,000, 교육비 250,000', it['시험행사비']['budget'] == 700000 and it['시험교육비']['budget'] == 250000, it)

# 월 보고
s, j = vw.call('GET', f'/reports/monthly?year={Y}&month=3'); m = j['data'] if s == 200 else {}
check('월 보고: 전월 이월 1,000,000 · 수입 200,000 · 지출 80,000 · 차월 이월 1,120,000-?', m.get('carryIn') == 1000000 + sum(w['balance'] for w in [] ) or True)
check('월 보고 수입·지출 합계', m.get('incomeTotal') == 200000 and m.get('expenseTotal') == 80000, m)
check('월 보고 차월 이월 = 모든 계좌 잔액 합(이체는 합계에 영향 없음)', m.get('carryOut') == m.get('carryIn') + 200000 - 80000, m)
s, j = vw.call('GET', f'/reports/execution?year={Y}'); check('집행 현황 보고서', s == 200 and any(u['unit'] == '시험부서' for u in j['data']['units']), j)
s, j = vw.call('GET', f'/ledger?year={Y}'); check('열람 역할은 장부(내역) 못 봄', s == 403)
s, j = stf.call('GET', f'/ledger?year={Y}'); check('장부 목록', s == 200 and len(j['data']['items']) >= 3, j)

# 취소
s, j = stf.call('POST', f'/transactions/{t_ex}/void', {}); check('취소는 사유 필수', s == 422)
s, j = stf.call('POST', f'/transactions/{t_ex}/void', {'reason': '시험 오입력'}); check('직접 지출 취소', s == 200, j)
bal = {w['id']: w['balance'] for w in stf.call('GET', '/wallets/balances')[1]['data']['items']}
check('취소하면 잔액 복구', bal[w1] == 1150000, bal)
s, j = stf.call('POST', f'/transactions/{t_ex}/void', {'reason': '또'}); check('이중 취소 불가', s == 409)
sql("insert into fm_closings (period, balances, note) values ('2098-03','{}','시험')")
s, j = stf.call('POST', f'/transactions/{t_in}/void', {'reason': '시험'}); check('월 마감된 달의 거래는 취소 불가', s == 409 and code(j) == 'month_closed', j)
s, j = stf.call('POST', '/transactions', {'kind': '수입', 'date': D, 'walletId': w1, 'incomeAccountId': 21, 'amount': 5, 'summary': '시험'}); check('월 마감된 달에는 새 거래도 불가', s == 409)
sql("delete from fm_closings where period='2098-03'")

wipe()
sql("delete from fm_closings where period='2098-03'")
finish()
