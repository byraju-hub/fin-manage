"""지출 카테고리(관·항·목) · 부서별 사용 카테고리 · 조직도 일괄 추가 · 부서별 지출 정리 시험."""
import subprocess
from testkit import *  # noqa

wipe()
sql("delete from fm_unit_categories"); sql("delete from fm_budget_items"); sql("delete from fm_expense_categories")
U, people = make_users({
    't_adm': ('시험관리자', [], 1, 1, 0),
    't_staff': ('시험담당', ['재정 담당'], 0, 1, 1),
    't_view': ('시험열람', ['열람'], 0, 0, 2),
})
adm, stf, vw = U['t_adm'], U['t_staff'], U['t_view']
Y = int(sql("select year(curdate())"))
sql("update fm_fiscal_years set budget_confirmed_on=NULL where year=%d" % Y)
stf.call('POST', '/funds', {'name': '시험회계'})
s, j = stf.call('POST', '/wallets', {'fundId': 1, 'name': '시험통장', 'kind': '통장', 'opening': 0}); wid = j['data']['id']

# ===== 카테고리 관리 =====
s, j = stf.call('POST', '/categories', {'name': '시험'}); check('관리자가 아니면 카테고리를 못 만듦', s == 403, j)
s, j = adm.call('POST', '/categories/seed', {}); check('예시 카테고리 넣기', s == 200 and j['data']['added'] > 50, j)
s, j = adm.call('POST', '/categories/seed', {}); check('이미 있으면 예시를 다시 넣지 않음', s == 409, j)
items = adm.call('GET', '/categories')[1]['data']['items']
roots = [i for i in items if i['level'] == 1]
check('관·항·목 3단계로 들어감', len(roots) >= 10 and any(i['level'] == 3 for i in items))
s, j = vw.call('GET', '/categories'); check('다른 사용자도 카테고리 목록은 볼 수 있음(예산 화면용)', s == 200)
s, j = adm.call('POST', '/categories', {'name': '시험관'}); g = j['data']['id']
s, j = adm.call('POST', '/categories', {'name': '시험관'}); check('같은 이름 거절', s == 409)
s, j = adm.call('POST', '/categories', {'parentId': g, 'name': '시험항'}); h = j['data']['id']
s, j = adm.call('POST', '/categories', {'parentId': h, 'name': '시험목'}); m = j['data']['id']
s, j = adm.call('POST', '/categories', {'parentId': m, 'name': '넷째'}); check('4단계는 거절', s == 422, j)
s, j = adm.call('POST', '/categories', {'parentId': h, 'name': '시험목2'}); m2 = j['data']['id']
s, j = adm.call('POST', f'/categories/{m2}/move', {'dir': 'up'}); check('위로 옮기기', s == 200)
order = [i['id'] for i in adm.call('GET', '/categories')[1]['data']['items'] if i['parentId'] == h]
check('순서가 바뀜', order == [m2, m], order)
s, j = adm.call('POST', f'/categories/{m2}', {'name': '시험목둘'}); check('이름 고치기', s == 200)
s, j = adm.call('POST', f'/categories/{g}/delete', {}); check('하위가 있으면 못 지움', s == 409)
s, j = adm.call('POST', f'/categories/{m2}/delete', {}); check('안 쓰는 말단은 지움', s == 200)

# ===== 부서별 사용 카테고리 =====
s, j = stf.call('POST', '/units', {'year': Y, 'kind': '위원회', 'name': '시험위원회'}); uc = j['data']['id']
s, j = stf.call('POST', '/units', {'year': Y, 'kind': '부서', 'name': '시험부서', 'parentId': uc}); ud = j['data']['id']
s, j = stf.call('POST', '/units', {'year': Y, 'kind': '위원회', 'name': '시험자유위원회'}); uf = j['data']['id']
s, j = stf.call('POST', f'/units/{uc}/categories', {'categoryIds': [g]}); check('담당(비관리자)은 부서별 카테고리를 못 정함', s == 403)
s, j = adm.call('POST', f'/units/{uc}/categories', {'categoryIds': [g], 'applyToChildren': True}); check('위원회에 지정(소속 부서 포함)', s == 200 and j['data']['units'] == 2, j)
uk = {u['id']: u for u in adm.call('GET', f'/unit-categories?year={Y}')[1]['data']['items']}
check('소속 부서에도 들어감', uk[ud]['categoryIds'] == [g] and uk[uc]['categoryIds'] == [g])
other = next(i['id'] for i in items if i['level'] == 3)
s, j = stf.call('POST', '/budget-items', {'unitId': ud, 'name': '시험항목A', 'amount': 1000000}); check('지정된 부서는 카테고리 없이 예산 항목을 못 만듦', s == 422 and code(j) == 'category_required', j)
s, j = stf.call('POST', '/budget-items', {'unitId': ud, 'name': '시험항목A', 'amount': 1000000, 'categoryId': other}); check('허용 밖 카테고리 거절', s == 422 and code(j) == 'category_not_allowed', j)
s, j = stf.call('POST', '/budget-items', {'unitId': ud, 'name': '시험항목A', 'amount': 1000000, 'categoryId': m}); check('허용된 관의 목은 가능(하위 포함)', s == 200, j); ia = j['data']['id']
s, j = stf.call('POST', '/budget-items', {'unitId': uf, 'name': '시험항목F', 'amount': 500000}); check('지정이 없는 부서는 카테고리 없이도 가능', s == 200, j); iff = j['data']['id']
s, j = stf.call('POST', '/budget-items', {'unitId': uf, 'name': '시험항목G', 'amount': 700000, 'categoryId': other}); check('지정이 없는 부서는 아무 카테고리나 가능', s == 200, j); ig = j['data']['id']
s, j = adm.call('POST', f'/categories/{m}', {'active': False}); s2, j2 = stf.call('POST', '/budget-items', {'unitId': uf, 'name': '시험항목H', 'amount': 1, 'categoryId': m}); check('사용 안 함 카테고리는 새로 못 고름', s2 == 422, j2)
adm.call('POST', f'/categories/{m}', {'active': True})
s, j = adm.call('POST', f'/categories/{m}/delete', {}); check('쓰고 있는 카테고리는 못 지움', s == 409)
b = stf.call('GET', f'/budget?year={Y}')[1]['data']; it = {i['name']: i for u in b['units'] for i in u['items']}
check('예산 화면 응답에 카테고리가 실림', it['시험항목A']['categoryId'] == m)
s, j = stf.call('POST', f'/budget-items/{iff}', {'categoryId': m}); check('카테고리 나중에 지정', s == 200)

# ===== 부서별 지출 정리 =====
def spend(uid, item, amt, date, void=False):
    sql("insert into fm_transactions (fiscal_year,tx_date,kind,fund_id,wallet_id,unit_id,budget_item_id,amount,summary,source,void_at) values (%d,'%s','지출',1,%d,%d,%s,%d,'시험지출','직접',%s)" % (Y, date, wid, uid, item if item else 'NULL', amt, 'now()' if void else 'NULL'))
spend(ud, ia, 100000, '%d-01-10' % Y)
spend(ud, ia, 50000, '%d-03-10' % Y)
spend(uc, None, 7000, '%d-02-10' % Y)          # 항목 없는 지출 = 미분류
spend(uf, iff, 20000, '%d-01-15' % Y)
spend(uf, ig, 3000, '%d-04-15' % Y)
spend(ud, ia, 999999, '%d-01-11' % Y, void=True)  # 취소는 제외
r = stf.call('GET', f'/reports/spending?year={Y}&level=1'); d = r[1]['data']
check('부서별 지출 정리 조회', r[0] == 200 and d['total']['paid'] == 180000, d['total'])
rows = {x['name']: x for x in d['rows']}
check('부서 행: 시험부서 150,000(취소 제외)', rows['시험부서']['paid'] == 150000, rows['시험부서'])
check('위원회 행은 소속 부서를 합산: 157,000(직할 7,000 포함)', rows['시험위원회']['paid'] == 157000 and rows['시험위원회'].get('group'), rows['시험위원회'])
check('직할 행이 따로 나옴', rows['시험위원회 직할']['paid'] == 7000)
check('총계는 맨 윗단계만 더함(중복 없음)', d['total']['paid'] == sum(x['paid'] for x in d['rows'] if x['depth'] == 0))
check('미분류 열이 있음(항목 없는 지출)', any(c['id'] == 0 for c in d['columns']) and d['total']['cells'].get('0') == 7000, d['columns'])
check('관 단계 열에 시험관이 있음', any(c['id'] == g and c['name'] == '시험관' for c in d['columns']))
check('시험관 열 합계 170,000 + 3,000은 다른 관', d['total']['cells'].get(str(g)) == 170000, d['total']['cells'])
d3 = stf.call('GET', f'/reports/spending?year={Y}&level=3')[1]['data']
check('목 단계에서는 시험목 열', any(c['id'] == m for c in d3['columns']) and d3['total']['paid'] == 180000, d3['columns'])
d2 = stf.call('GET', f'/reports/spending?year={Y}&level=2')[1]['data']
check('항 단계에서는 시험항 열 170,000', d2['total']['cells'].get(str(h)) == 170000, d2['total']['cells'])
dq = stf.call('GET', f'/reports/spending?year={Y}&from={Y}-01&to={Y}-02')[1]['data']
check('기간(1~2월) 조회: 100,000+7,000+20,000', dq['total']['paid'] == 127000, dq['total'])
check('카테고리 트리 합계(관 > 항 > 목)가 맞음', {t['id']: t['paid'] for t in d['tree']}.get(g) == 170000 and {t['id']: t['paid'] for t in d['tree']}.get(h) == 170000)
s, j = stf.call('GET', f'/reports/spending?year={Y}&from=2026-13'); check('기간 형식 오류 거절', s == 422)
s, j = vw.call('GET', f'/reports/spending?year={Y}'); check('열람 역할도 전체 지출 정리를 볼 수 있음', s == 200 and j['data']['total']['paid'] == 180000)
# 내려받기
s, j = stf.call('GET', f'/export/spending?year={Y}'); check('열쇠 없이 CSV 거절', s == 403 and code(j) == 'dl_required')
tk = stf.call('POST', '/downloads/ticket', {'kind': 'spending'})[1]['data']['token']
s, j = stf.call('GET', f'/export/spending?year={Y}&dl={tk}'); check('CSV 내려받기', s == 200 and '시험부서' in j.get('raw', '') and '합계' in j.get('raw', ''), str(j)[:200])

# ===== 조직도 일괄 추가 =====
txt = "시험공동체위원회: 열매부, 줄기부\n시험기획위원회\n- 기획1부\n- 기획2부\n  들여쓴부서"
s, j = stf.call('POST', '/units/bulk', {'year': Y, 'text': txt}); check('조직도 일괄 추가', s == 200 and j['data']['committees'] == 2 and j['data']['depts'] == 5, j)
us = stf.call('GET', f'/units?year={Y}')[1]['data']['items']
byname = {u['name']: u for u in us}
check('부서가 위원회 밑에 들어감', byname['열매부']['parentId'] == byname['시험공동체위원회']['id'] and byname['들여쓴부서']['parentId'] == byname['시험기획위원회']['id'] and byname['기획1부']['kind'] == '부서')
s, j = stf.call('POST', '/units/bulk', {'year': Y, 'text': txt}); check('다시 넣으면 건너뜀(중복 없음)', s == 200 and j['data']['committees'] == 0 and j['data']['depts'] == 0 and j['data']['skipped'] >= 7, j)
s, j = stf.call('POST', '/units/bulk', {'year': Y, 'text': '- 위원회 없이 부서'}); check('위원회 없이 부서만 있으면 거절', s == 422, j)
s, j = vw.call('POST', '/units/bulk', {'year': Y, 'text': 'x'}); check('열람 역할은 조직도를 못 넣음', s == 403)

# ===== 체험 모드 =====
h_ = subprocess.run([r'C:\xampp\php\php.exe', '-r', "echo password_hash('1234', PASSWORD_DEFAULT);"], capture_output=True, text=True).stdout
sql("delete from cm_users where login_id='t_demo'")
sql("insert into cm_users (login_id,password_hash,name,status,grade,is_super,is_demo) values ('t_demo','%s','체험','사용','A',1,1)" % h_)
dm = Client(); dm.login('t_demo', '1234')
s, j = dm.call('GET', f'/reports/spending?year={Y}&level=1'); dd = j['data'] if s == 200 else {}
check('체험 모드: 가짜 카테고리로 부서별 지출이 정리됨', s == 200 and dd['total']['paid'] > 0 and any(c['name'] in ('예배비', '인건비', '관리비') for c in dd['columns']) and all(x['name'] not in ('시험부서', '시험위원회') for x in dd['rows']), str(dd)[:200])
s, j = dm.call('POST', '/categories', {'name': 'x'}); check('체험 모드: 카테고리 추가 막힘', s == 403 and code(j) == 'demo_readonly')
s, j = dm.call('POST', '/units/bulk', {'year': Y, 'text': 'x'}); check('체험 모드: 조직도 추가 막힘', s == 403 and code(j) == 'demo_readonly')
sql("delete from cm_users where login_id='t_demo'")
for t in sql("select table_name from information_schema.tables where table_schema=database() and table_name like 'fmd\\\\_%'").split('\n'):
    if t: sql("drop table if exists `%s`" % t)

sql("delete from fm_transactions where summary='시험지출'")
sql("delete from fm_units where kind in ('기타','예비비') and id not in (select unit_id from fm_budget_items)")  # 조직도 일괄 추가가 만든 특수 단위 정리
sql("delete from fm_unit_categories"); sql("delete from fm_budget_items"); sql("delete from fm_expense_categories")
wipe()
finish()
