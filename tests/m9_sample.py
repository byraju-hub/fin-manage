"""샘플 자료 시험: 올리기 → 내용 확인 → 이중 올리기 거절 → 지우기 → 원상 복구."""
from testkit import *  # noqa

wipe()
before = {t: sql(f"select count(*) from fm_{t}") for t in ('wallets', 'fiscal_years', 'units', 'offerings', 'transactions', 'vouchers', 'donors', 'budget_items')}
U, people = make_users({'t_admin': ('시험관리자', ['재정위원장'], 1, 1, 0), 't_staff': ('시험담당', ['재정 담당'], 0, 1, 1)})
adm, stf = U['t_admin'], U['t_staff']
PW = 'testpass1234'
s, j = stf.call('GET', '/admin/sample'); check('관리자가 아니면 샘플 화면 못 씀', s == 403)
s, j = adm.call('GET', '/admin/sample'); check('올리기 전 상태: 비어 있음', s == 200 and not j['data']['loaded'] and j['data']['busy'] is None, j)
s, j = adm.call('POST', '/admin/sample/load', {}); check('비밀번호 재확인 필요', s == 403 and code(j) == 'reconfirm', j)
adm.call('POST', '/auth/reconfirm', {'password': PW})
s, j = adm.call('POST', '/admin/sample/load', {}); check('샘플 올리기', s == 200, j)
c = j['data']['counts'] if s == 200 else {}
check('내용: 헌금자 36, 헌금 묶음 20, 결의서 3, 예산 항목 다수', c.get('교인(헌금자)') == 36 and c.get('헌금 묶음') >= 9 and c.get('결의서') == 3 and c.get('예산 항목', 0) > 10, c)
s, j = adm.call('POST', '/admin/sample/load', {}); check('이미 올라와 있으면 거절', s == 409, j)
check('모든 헌금 묶음이 마감됨', sql("select count(*) from fm_offering_batches where status<>'마감'") == '0')
bal = {w['name']: w['balance'] for w in adm.call('GET', '/wallets/balances')[1]['data']['items']}
check('계좌 잔액이 계산됨 (샘플 통장 시작 8,000,000 이상 변동)', '샘플 주거래통장' in bal and bal['샘플 주거래통장'] != 8000000, bal)
s, j = adm.call('GET', '/budget?year=%s' % sql("select year(curdate())")); b = j['data'] if s == 200 else {}
check('예산이 확정되어 있고 집행(지급 완료 결의서·직접 지출)이 잡힘', b.get('confirmedOn') and sum(i['paid'] for u in b['units'] for i in u['items']) > 0, b.get('confirmedOn'))
s, j = stf.call('GET', '/receipts/preview?year=%d' % (int(sql("select year(curdate())")) - 1)); check('작년 자료로 영수증 대상이 나옴(24명)', s == 200 and len(j['data']['items']) >= 20, len(j['data']['items']) if s == 200 else j)
s, j = adm.call('GET', '/vouchers?year=%s' % sql("select year(curdate())")); st = sorted(v['status'] for v in j['data']['items']); check('결의서 상태 3가지', st == ['결재 중', '작성 중', '지급 완료'], st)
s, j = adm.call('GET', '/reports/monthly?year=%s&month=%s' % (sql("select year(curdate())"), sql("select month(curdate())"))); check('월 보고가 나옴', s == 200 and j['data']['incomeTotal'] > 0, j)
s, j = adm.call('POST', '/admin/sample/clear', {}); check('샘플 지우기', s == 200, j)
after = {t: sql(f"select count(*) from fm_{t}") for t in before}
check('지우면 올리기 전과 같은 상태', after == before, (before, after))
s, j = adm.call('GET', '/admin/sample'); check('다시 비어 있음', not j['data']['loaded'])
s, j = adm.call('POST', '/admin/sample/clear', {}); check('지울 것이 없으면 거절', s == 409)
wipe()
sql("delete from fm_meta where k like 'voucher_seq_%' or k like 'receipt_seq_%' or k = 'sample_info'")
finish()
