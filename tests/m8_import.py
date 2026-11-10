"""기존 자료 가져오기 시험: 헌금 내역(미리보기·중복·마감), 예산(지출·수입)."""
from testkit import *  # noqa

wipe()
U, people = make_users({'t_staff': ('시험담당', ['재정 담당'], 0, 1, 0), 't_view': ('시험열람', ['열람'], 0, 0, 1)})
stf, vw = U['t_staff'], U['t_view']
Y = 2098
stf.call('POST', '/fiscal-years', {'year': Y, 'start': '2098-01-01', 'end': '2098-12-31'})
s, j = stf.call('POST', '/wallets', {'fundId': 1, 'name': '시험현금', 'kind': '현금', 'opening': 0}); wc = j['data']['id']
s, j = stf.call('POST', '/wallets', {'fundId': 1, 'name': '시험통장', 'kind': '통장', 'opening': 0}); wb = j['data']['id']
nm = sql("select name from cm_people where purged_on is null group by name having count(*)=1 order by min(seq) limit 1")
dup = sql("select name from cm_people where purged_on is null group by name having count(*)>1 limit 1")
rows_ = [
    {'date': '2098-03-01', 'account': '십일조', 'name': nm, 'amount': '100,000'},
    {'date': '2098-03-01', 'account': '주일헌금', 'name': '', 'amount': 300000, 'method': '현금'},
    {'date': '2098-03-08', 'account': '감사헌금', 'name': nm, 'amount': 50000, 'method': '계좌이체'},
    {'date': '2098-03-08', 'account': '십일조', 'name': '시험외부인', 'amount': 20000},
    {'date': '2098/03/08', 'account': '십일조', 'name': nm, 'amount': 1},
    {'date': '2098-03-08', 'account': '없는종류', 'name': nm, 'amount': 1000},
    {'date': '2097-03-08', 'account': '십일조', 'name': nm, 'amount': 1000},
    {'date': '2098-03-08', 'account': '십일조', 'name': nm, 'amount': 0},
    {'date': '2098-03-08', 'account': '부서헌금', 'name': nm, 'amount': 5000},
    {'date': '2098-03-08', 'account': '십일조', 'name': '교적에없는사람시험', 'amount': 5000},
]
if dup:
    rows_.append({'date': '2098-03-08', 'account': '십일조', 'name': dup, 'amount': 7000})
s, j = vw.call('POST', '/import/offerings/preview', {'rows': rows_}); check('열람 역할은 가져오기 불가', s == 403)
s, j = stf.call('POST', '/import/offerings/preview', {'rows': rows_}); r = j['data']['rows']
check('미리보기: 맞는 줄 3개, 외부인은 옵션 없이는 오류', [x['ok'] for x in r[:4]] == [True, True, True, False], r[:4])
check('날짜 형식·없는 종류·회계연도 없음·금액 0·부서 없음·교적에 없는 이름은 오류', [x['ok'] for x in r[4:10]] == [False] * 6, r[4:10])
if dup:
    check('동명이인은 출생연도를 요구', not r[10]['ok'] and '출생연도' in r[10]['error'], r[10])
s, j = stf.call('POST', '/import/offerings/preview', {'rows': rows_, 'createOutsiders': True}); check('옵션을 켜면 외부인은 새 헌금자로', j['data']['rows'][3]['ok'] and j['data']['rows'][3]['newDonor'], j['data']['rows'][3])
s, j = stf.call('POST', '/import/offerings/commit', {'rows': rows_, 'createOutsiders': True}); check('가져오기: 옵션을 켜면 교적에 없는 이름도 새 헌금자로 5줄 저장', s == 200 and j['data']['added'] == 5, j)
check('날짜별 가져온 자료 묶음(3/1, 3/8)이 입력 중', sql("select count(*) from fm_offering_batches where counters='가져온 자료' and status='입력 중' and fiscal_year=2098") == '2')
check('저장되지 않은 줄은 없음', sql("select count(*) from fm_offerings o join fm_offering_batches b on b.id=o.batch_id where b.fiscal_year=2098") == '5')
s, j = stf.call('POST', '/import/offerings/commit', {'rows': rows_[:3], 'createOutsiders': True}); check('같은 줄을 다시 가져오면 중복으로 건너뜀', j['data']['added'] == 0 and all(x['dup'] for x in j['data']['rows']), j)
s, j = stf.call('GET', '/import/offerings/pending'); check('마감 대기 묶음 목록', len(j['data']['items']) == 2, j)
s, j = stf.call('POST', '/import/offerings/close', {'cashWalletId': wc}); check('통장 계좌 없이는 마감 불가', s == 422 and code(j) == 'wallet', j)
s, j = stf.call('POST', '/import/offerings/close', {'cashWalletId': wc, 'bankWalletId': wb}); check('한꺼번에 마감', s == 200 and j['data']['closed'] == 2, j)
check('장부에 수입이 올라감 (475,000)', sql("select sum(amount) from fm_transactions where fiscal_year=2098 and kind='수입' and void_at is null") == '475000')
s, j = stf.call('GET', f'/receipts/preview?year={Y}'); check('가져온 헌금이 영수증 대상에도 잡힘', s == 200 and sum(i['total'] for i in j['data']['items']) == 175000, j)

s, j = stf.call('POST', '/units', {'year': Y, 'kind': '부서', 'name': '시험부서'})
b = [{'unit': '시험부서', 'item': '시험행사비', 'amount': '500,000', 'basis': '연 2회'}, {'unit': '시험부서', 'item': '시험교육비', 'amount': 300000},
     {'unit': '없는부서시험', 'item': '시험비품', 'amount': 100000}, {'unit': '시험부서', 'item': '시험행사비', 'amount': 1}, {'unit': '시험부서', 'item': '', 'amount': 5}, {'unit': '시험부서', 'item': '시험음수', 'amount': 'abc'}]
s, j = stf.call('POST', '/import/budget/preview', {'year': Y, 'type': 'expense', 'rows': b}); r = j['data']['rows']
check('예산 미리보기: 2줄 정상, 나머지 오류', [x['ok'] for x in r] == [True, True, False, False, False, False], r)
s, j = stf.call('POST', '/import/budget/commit', {'year': Y, 'type': 'expense', 'rows': b, 'createUnits': True}); check('없는 부서 새로 만들기 옵션으로 3줄 저장', j['data']['done'] == 3, j)
s, j = stf.call('POST', '/import/budget/commit', {'year': Y, 'type': 'expense', 'rows': [{'unit': '시험부서', 'item': '시험행사비', 'amount': 650000}]}); check('같은 항목은 금액만 바꿈', j['data']['done'] == 1 and sql("select amount from fm_budget_items where name='시험행사비'") == '650000', j)
s, j = stf.call('POST', '/import/budget/commit', {'year': Y, 'type': 'income', 'rows': [{'account': '십일조', 'amount': 9000000}, {'account': '없는항목', 'amount': 1}]}); check('수입 예산: 1줄 저장, 1줄 오류', j['data']['done'] == 1 and sql("select amount from fm_income_budgets where fiscal_year=2098") == '9000000', j)
sql("update fm_fiscal_years set budget_confirmed_on='2098-01-05' where year=2098")
s, j = stf.call('POST', '/import/budget/preview', {'year': Y, 'type': 'expense', 'rows': b[:1]}); check('예산 확정 뒤에는 가져오기 불가', s == 409, j)
wipe()
finish()
