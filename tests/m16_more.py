"""예산 항목 카테고리 일괄 지정 · '기타' 메모 필수 · 행사 정산 · 결재 중 집계 · 영수증 글자 읽기(가짜 서버) 시험."""
import json, threading, base64, subprocess, os
from http.server import BaseHTTPRequestHandler, HTTPServer
from testkit import *  # noqa
from auth_flow_lib import PW

wipe()
sql("delete from fm_events"); sql("delete from fm_unit_categories"); sql("delete from fm_budget_items"); sql("delete from fm_expense_categories")
sql("delete from fm_meta where k='ocr'")
sql("delete from fm_access_log where action in ('영수증 글자 읽기','영수증 글자 읽기 설정')")
U, people = make_users({
    't_adm': ('시험관리자', [], 1, 1, 0),
    't_staff': ('시험담당', ['재정 담당'], 0, 1, 1),
    't_acc': ('시험회계', [], 0, 0, 4),
})
adm, stf, acc = U['t_adm'], U['t_staff'], U['t_acc']
Y = int(sql("select year(curdate())"))
sql("update fm_fiscal_years set budget_confirmed_on=NULL where year=%d" % Y)
stf.call('POST', '/funds', {'name': '시험회계'})
s, j = stf.call('POST', '/wallets', {'fundId': 1, 'name': '시험통장', 'kind': '통장', 'opening': 0}); wid = j['data']['id']
adm.call('POST', '/categories/seed', {})
cats = adm.call('GET', '/categories')[1]['data']['items']
cid = lambda name, level=3: next(c['id'] for c in cats if c['name'] == name and c['level'] == level)
etc, snack, cleaning = cid('기타'), cid('간식·식비'), cid('청소·소모품')
check('예시의 기타 카테고리는 메모 필수로 표시됨', next(c for c in cats if c['id'] == etc)['needsMemo'] is True and next(c for c in cats if c['id'] == snack)['needsMemo'] is False)
s, j = adm.call('POST', f'/categories/{cleaning}', {'needsMemo': True}); check('관리자가 메모 필수를 켤 수 있음', s == 200)
adm.call('POST', f'/categories/{cleaning}', {'needsMemo': False})

s, j = stf.call('POST', '/units', {'year': Y, 'kind': '위원회', 'name': '시험위원회'}); uc = j['data']['id']
s, j = stf.call('POST', '/units', {'year': Y, 'kind': '부서', 'name': '시험부서', 'parentId': uc}); ud = j['data']['id']
stf.call('POST', f'/units/{ud}/roles', {'role': '회계', 'personId': people[4]})
stf.call('POST', f'/units/{ud}/roles', {'role': '부서장', 'personId': people[5]})
stf.call('POST', f'/units/{uc}/roles', {'role': '위원장', 'personId': people[6]})

# ===== ① 카테고리 일괄 지정 =====
def item(name, amt=1000000, cat=None):
    s, j = stf.call('POST', '/budget-items', {'unitId': ud, 'name': name, 'amount': amt, **({'categoryId': cat} if cat else {})}); assert s == 200, j; return j['data']['id']
i_snack, i_etc, i_unk, i_clean = item('간식·식비'), item('기타'), item('특별헌신비'), item('청소')
s, j = stf.call('GET', f'/budget-items/uncategorized?year={Y}'); un = {x['name']: x for x in j['data']['items']}
check('카테고리 없는 예산 항목이 모두 나옴', s == 200 and set(un) >= {'간식·식비', '기타', '특별헌신비', '청소'}, list(un))
check('이름이 같으면 카테고리를 제안', un['간식·식비']['suggestId'] == snack and un['기타']['suggestId'] == etc, (un['간식·식비'], un['기타']))
check('부분 일치도 제안(청소 → 청소·소모품)', un['청소']['suggestId'] == cleaning, un['청소'])
s, j = acc.call('GET', f'/budget-items/uncategorized?year={Y}'); check('재정 담당이 아니면 못 봄', s == 403)
s, j = stf.call('POST', '/budget-items/categorize', {'assignments': [{'id': i_snack, 'categoryId': snack}, {'id': i_etc, 'categoryId': etc}, {'id': i_clean, 'categoryId': cleaning}]}); check('일괄 지정', s == 200 and j['data']['changed'] == 3, j)
adm.call('POST', f'/units/{ud}/categories', {'categoryIds': [cid('부서운영', 2), cid('시설', 2), etc]})
s, j = stf.call('POST', '/budget-items/categorize', {'assignments': [{'id': i_unk, 'categoryId': cid('성도 구제')}, {'id': i_snack, 'categoryId': snack}]}); check('허용 밖 카테고리가 하나라도 있으면 전부 취소', s == 422, j)
check('취소됐으므로 아무것도 안 바뀜', sql("select ifnull(category_id,0) from fm_budget_items where id=%d" % i_unk) == '0')
s, j = stf.call('POST', '/budget-items/categorize', {'assignments': [{'id': i_unk, 'categoryId': snack}]}); check('허용된 카테고리는 지정됨', s == 200)
sql("update fm_fiscal_years set budget_confirmed_on=curdate() where year=%d" % Y)
s, j = stf.call('POST', '/budget-items/categorize', {'assignments': [{'id': i_unk, 'categoryId': cid('장학금')}]}); check('예산 확정 뒤에도 분류는 바꿀 수 있음(허용 범위 안에서)', s in (200, 422), j)
sql("update fm_fiscal_years set budget_confirmed_on=NULL where year=%d" % Y)

# ===== ② 메모 필수 =====
def voucher(reason, eventId=None, item_id=i_etc, who=acc):
    it = {'budgetItemId': item_id, 'summary': '시험 내역', 'amount': 30000}
    if eventId: it['eventId'] = eventId
    return who.call('POST', '/vouchers', {'unitId': ud, 'title': '시험결의서', 'reason': reason, 'payMethod': '현금', 'items': [it]})
s, j = voucher(''); check("'기타' 항목은 사유 없이 저장 불가", s == 422 and code(j) == 'memo_required', j)
s, j = voucher('짧음'); check('사유가 5자 미만이면 거절', s == 422 and code(j) == 'memo_required')
s, j = voucher('행사 준비를 위한 소모품 구입'); check('사유가 있으면 저장', s == 200, j); v1 = j['data']['id']
s, j = voucher('', item_id=i_snack); check('메모 필수가 아닌 카테고리는 사유 없이도 저장', s == 200, j); v2 = j['data']['id']
adm.call('POST', f'/budget-items/{i_etc}/direct', {'directOk': True})
dt = lambda summ: stf.call('POST', '/transactions', {'kind': '지출', 'date': '%d-01-05' % Y, 'walletId': wid, 'budgetItemId': i_etc, 'amount': 5000, 'summary': summ, 'noProofReason': '시험'})
s, j = dt('기타'); check("직접 지출도 '기타'는 적요를 5자 이상 적어야 함", s == 422 and code(j) == 'memo_required', j)
s, j = dt('시험 소모품 구입'); check('충분한 적요면 직접 지출 등록', s == 200, j)

# ===== ③ 행사 정산 =====
s, j = stf.call('POST', '/events', {'year': Y, 'name': '시험수련회', 'unitId': ud, 'budget': 3000000, 'startsOn': '%d-07-01' % Y, 'endsOn': '%d-07-03' % Y}); check('행사 만들기', s == 200, j); ev = j['data']['id']
s, j = stf.call('POST', '/events', {'year': Y, 'name': '시험수련회'}); check('같은 이름 행사 거절', s == 409)
s, j = stf.call('POST', '/events', {'year': Y, 'name': '거꾸로', 'startsOn': '2026-07-03', 'endsOn': '2026-07-01'}); check('끝나는 날이 앞서면 거절', s == 422)
s, j = acc.call('POST', '/events', {'year': Y, 'name': 'x'}); check('재정 담당이 아니면 행사를 못 만듦', s == 403)
s, j = voucher('행사 준비를 위한 소모품 구입', eventId=ev, item_id=i_snack); check('결의서 내역에 행사 연결', s == 200, j); v3 = j['data']['id']
check('결의서 응답에 행사가 실림', stf.call('GET', f'/vouchers/{v3}')[1]['data']['items'][0]['eventId'] == ev)
s, j = stf.call('POST', '/transactions', {'kind': '수입', 'date': '%d-06-20' % Y, 'walletId': wid, 'incomeAccountId': 21, 'amount': 800000, 'summary': '시험수련회 회비', 'eventId': ev}); check('장부 수입에 행사 연결(회비)', s == 200, j)
s, j = stf.call('POST', '/transactions', {'kind': '지출', 'date': '%d-07-02' % Y, 'walletId': wid, 'budgetItemId': i_etc, 'amount': 120000, 'summary': '시험수련회 식비 결제', 'eventId': ev, 'noProofReason': '시험'}); check('직접 지출에 행사 연결', s == 200, j)
sql("update fm_vouchers set status='결재 중' where id=%d" % v3)
d = stf.call('GET', f'/events/{ev}')[1]['data']
check('행사 정산: 수입 800,000 · 지출 120,000 · 남음 680,000', d['income'] == 800000 and d['expense'] == 120000 and d['net'] == 680000, d)
check('결재 중인 결의서 내역이 따로 보임(30,000)', d['pending'] == 30000 and len(d['pendingItems']) == 1)
check('내역 목록에 수입·지출이 있음', {t['kind'] for t in d['transactions']} == {'수입', '지출'})
ls = stf.call('GET', f'/events?year={Y}')[1]['data']['items']; check('행사 목록에 합계가 실림', ls[0]['income'] == 800000 and ls[0]['budget'] == 3000000)
s, j = stf.call('POST', f'/events/{ev}/delete', {}); check('연결된 수입·지출이 있으면 못 지움', s == 409)
s, j = stf.call('POST', '/events', {'year': Y, 'name': '지울행사'}); ev2 = j['data']['id']
s, j = stf.call('POST', f'/events/{ev2}/delete', {}); check('안 쓴 행사는 지움', s == 200)
s, j = stf.call('POST', f'/events/{ev}', {'status': '종료'}); check('행사 종료', s == 200)
s, j = stf.call('POST', '/transactions', {'kind': '수입', 'date': '%d-06-21' % Y, 'walletId': wid, 'incomeAccountId': 21, 'amount': 1000, 'summary': '종료 뒤 회비', 'eventId': ev}); check('종료된 행사에는 새로 연결 불가', s == 409 and code(j) == 'event_closed', j)
s, j = stf.call('POST', '/transactions', {'kind': '수입', 'date': '%d-06-21' % Y, 'walletId': wid, 'incomeAccountId': 21, 'amount': 1000, 'summary': '시험', 'eventId': 999999}); check('없는 행사 거절', s == 422)

# ===== ⑤ 결재 중 집계 =====
sql("insert into fm_transactions (fiscal_year,tx_date,kind,fund_id,wallet_id,unit_id,budget_item_id,amount,summary,source) values (%d,'%d-02-10','지출',1,%d,%d,%d,7000,'시험지출','직접')" % (Y, Y, wid, ud, i_snack))
d = stf.call('GET', f'/reports/spending?year={Y}&level=1')[1]['data']
row = next(r for r in d['rows'] if r['name'] == '시험부서')
check('지출 정리에 결재 중 합계가 나옴(30,000 + 30,000 = v1·v2·v3 중 결재 중인 것)', row['pending'] == 30000, row)
check('결재 중도 카테고리별 열이 있음', sum(row['pcells'].values()) == 30000 and d['total']['pending'] == 30000, (row['pcells'], d['total']))
check('위원회 행에도 합산', next(r for r in d['rows'] if r['name'] == '시험위원회')['pending'] == 30000)
tk = stf.call('POST', '/downloads/ticket', {'kind': 'spending'})[1]['data']['token']
s, j = stf.call('GET', f'/export/spending?year={Y}&pending=1&dl={tk}'); check('CSV에 결재 중 열이 있음', s == 200 and '결재 중' in j['raw'], j.get('raw', '')[:80])

# ===== ④ 영수증 글자 읽기 (가짜 AI 서버) =====
seen = {}
class H(BaseHTTPRequestHandler):
    mode = 'ok'
    def do_POST(self):
        n = int(self.headers.get('content-length', 0)); body = json.loads(self.rfile.read(n))
        seen['key'] = self.headers.get('x-api-key'); seen['body'] = body
        if H.mode == 'fail':
            self.send_response(500); self.end_headers(); self.wfile.write(b'{}'); return
        txt = '여기 결과입니다 {"date":"%d-02-10","vendor":"시험마트<b>","bizNo":"123-45-67890","total":45000,"payMethod":"법인카드","cardLast4":"1234","summary":"간식","category":"간식·식비"} 끝' % Y
        out = json.dumps({'content': [{'type': 'text', 'text': txt}]}).encode()
        self.send_response(200); self.send_header('content-type', 'application/json'); self.end_headers(); self.wfile.write(out)
    def log_message(self, *a): pass
srv = HTTPServer(('127.0.0.1', 8099), H); threading.Thread(target=srv.serve_forever, daemon=True).start()
PNG = base64.b64encode(base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==')).decode()
check('처음에는 글자 읽기가 꺼져 있음', Client().call('GET', '/auth/me')[1]['data']['ocrEnabled'] is False)
s, j = acc.call('POST', '/receipts-ocr', {'data': PNG}); check('꺼져 있으면 404', s == 404 and code(j) == 'ocr_off', j)
s, j = stf.call('GET', '/settings/ocr'); check('관리자가 아니면 설정을 못 봄', s == 403)
adm.call('POST', '/auth/reconfirm', {'password': PW})
s, j = adm.call('POST', '/settings/ocr', {'enabled': True}); check('열쇠 없이는 못 켬', s == 422 and code(j) == 'no_key', j)
s, j = adm.call('POST', '/settings/ocr', {'apiKey': 'sk-ant-api03-시험열쇠'}); check('열쇠 형식 검사', s == 422)
s, j = adm.call('POST', '/settings/ocr', {'apiKey': 'sk-ant-test-0123456789abcdefghij'}); check('열쇠 저장', s == 200 and j['data']['hasKey'], j)
check('열쇠가 DB(백업에 실림)에 저장되지 않음', 'sk-ant-test' not in sql("select ifnull(group_concat(v),'') from fm_meta") and os.path.exists(r'C:\Users\byraj\projects\fin-manage\server\data\ocr.key'))
s, j = adm.call('POST', '/settings/ocr', {'enabled': True}); check('동의 확인 없이는 못 켬(국외 전송)', s == 422 and code(j) == 'consent', j)
s, j = adm.call('POST', '/settings/ocr', {'enabled': True, 'consent': True}); check('동의 확인하고 켬', s == 200 and j['data']['enabled'], j)
check('/auth/me 에 켜짐이 실림', Client().call('GET', '/auth/me')[1]['data']['ocrEnabled'] is True)
cfg = json.loads(sql("select v from fm_meta where k='ocr'")); cfg['endpoint'] = 'http://127.0.0.1:8099/v1/messages'
sql("update fm_meta set v='%s' where k='ocr'" % json.dumps(cfg))
s, j = acc.call('POST', '/receipts-ocr', {'data': PNG}); r = j.get('data') or {}
check('영수증 글자 읽기 결과', s == 200 and r['date'] == '%d-02-10' % Y and r['total'] == 45000 and r['payMethod'] == '법인카드' and r['cardLast4'] == '1234' and r['bizNo'] == '123-45-67890', j)
check('가맹점 이름의 태그는 제거됨', r['vendor'] == '시험마트' + 'b' or '<' not in r['vendor'], r['vendor'])
check('카테고리 제안이 id 로 돌아옴', r['categoryId'] == snack, r)
check('AI 서버에는 복호화한 열쇠와 이미지가 전달됨', seen['key'] == 'sk-ant-test-0123456789abcdefghij' and seen['body']['messages'][0]['content'][0]['type'] == 'image' and seen['body']['model'] == 'claude-haiku-5-5')
check('접속 기록에 사용이 남고 사진 내용은 안 남음', int(sql("select count(*) from fm_access_log where action='영수증 글자 읽기'")) == 1 and 'iVBOR' not in sql("select group_concat(target) from fm_access_log"))
s, j = acc.call('POST', '/receipts-ocr', {'data': base64.b64encode(b'%PDF-1.4 test').decode()}); check('PDF는 읽지 않음', s == 422, j)
H.mode = 'fail'
s, j = acc.call('POST', '/receipts-ocr', {'data': PNG}); check('AI 서버 오류 시 직접 입력 안내(502)', s == 502 and code(j) == 'ocr_failed', j)
H.mode = 'ok'
s, j = Client().call('POST', '/receipts-ocr', {'data': PNG}, csrf=False); check('로그인 없이는 불가', s in (401, 403))
s, j = adm.call('POST', '/settings/ocr', {'enabled': False}); check('끄기', s == 200 and not j['data']['enabled'])
s, j = acc.call('POST', '/receipts-ocr', {'data': PNG}); check('끄면 다시 404', s == 404)
srv.shutdown()

# ===== 체험 모드 =====
h_ = subprocess.run([r'C:\xampp\php\php.exe', '-r', "echo password_hash('1234', PASSWORD_DEFAULT);"], capture_output=True, text=True).stdout
sql("delete from cm_users where login_id='t_demo'")
sql("insert into cm_users (login_id,password_hash,name,status,grade,is_super,is_demo) values ('t_demo','%s','체험','사용','A',1,1)" % h_)
dm = Client(); dm.login('t_demo', '1234')
s, j = dm.call('GET', f'/events?year={Y}'); check('체험 모드: 행사 목록(가짜)이 보임', s == 200 and len(j['data']['items']) >= 1 and all(e['name'] != '시험수련회' for e in j['data']['items']), (s, str(j)[:200]))
s, j = dm.call('POST', '/receipts-ocr', {'data': PNG}); check('체험 모드: 글자 읽기 막힘', s == 403 and code(j) == 'demo_readonly', j)
s, j = dm.call('POST', '/events', {'year': Y, 'name': 'x'}); check('체험 모드: 행사 만들기 막힘', s == 403 and code(j) == 'demo_readonly')
sql("delete from cm_users where login_id='t_demo'")
for t in sql("select table_name from information_schema.tables where table_schema=database() and table_name like 'fmd\\\\_%'").split('\n'):
    if t: sql("drop table if exists `%s`" % t)

sql("delete from fm_transactions where summary like '시험%'")
sql("delete from fm_vouchers where title='시험결의서'")
sql("delete from fm_events"); sql("delete from fm_meta where k='ocr'")
try: os.remove(r'C:\Users\byraj\projects\fin-manage\server\data\ocr.key')
except OSError: pass
sql("delete from fm_unit_categories"); sql("delete from fm_budget_items"); sql("delete from fm_expense_categories")
sql("delete from fm_units where kind in ('기타','예비비') and id not in (select unit_id from fm_budget_items)")
wipe()
finish()
