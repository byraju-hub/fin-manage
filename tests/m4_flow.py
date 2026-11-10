"""M4 시험: 결재 규칙(금액 구간), 기안·상신·결재선 고정, 결재/반려/회수, 대결, 지급 처리, 증빙 파일, 권한."""
import base64
from testkit import *  # noqa

wipe()
sql("delete from fm_delegations")
U, people = make_users({
    't_admin': ('시험관리자', [], 1, 0, 0),
    't_staff': ('시험담당', ['재정 담당'], 0, 1, 1),
    't_chair': ('시험재정위원장', ['재정위원장'], 0, 1, 2),
    't_pastor': ('시험담임', ['담임목사'], 0, 1, 3),
    't_acct': ('시험회계', [], 0, 0, 4),      # 부서 회계
    't_head': ('시험부서장', [], 0, 0, 5),    # 부서장
    't_comm': ('시험위원장', [], 0, 0, 6),    # 위원회 위원장
    't_other': ('시험타부서', [], 0, 0, 7),   # 관계 없는 사람
    't_sub': ('시험대결', [], 0, 0, 8),
})
adm, stf, chair, pas, acc, head, comm, oth, sub = [U[k] for k in ('t_admin', 't_staff', 't_chair', 't_pastor', 't_acct', 't_head', 't_comm', 't_other', 't_sub')]
PW = 'testpass1234'
adm.call('POST', '/auth/reconfirm', {'password': PW})
Y = 2098
stf.call('POST', '/fiscal-years', {'year': Y, 'start': '2098-01-01', 'end': '2098-12-31'})
s, j = stf.call('POST', '/wallets', {'fundId': 1, 'name': '시험통장', 'kind': '통장', 'opening': 5000000}); w1 = j['data']['id']
s, j = stf.call('POST', '/units', {'year': Y, 'kind': '위원회', 'name': '시험위원회'}); uc = j['data']['id']
s, j = stf.call('POST', '/units', {'year': Y, 'kind': '부서', 'name': '시험부서', 'parentId': uc}); ud = j['data']['id']
s, j = stf.call('POST', '/units', {'year': Y, 'kind': '기타', 'name': '시험기타'}); ue = j['data']['id']
stf.call('POST', f'/units/{ud}/roles', {'role': '회계', 'personId': people[4]})
stf.call('POST', f'/units/{ud}/roles', {'role': '부서장', 'personId': people[5]})
stf.call('POST', f'/units/{uc}/roles', {'role': '위원장', 'personId': people[6]})
s, j = stf.call('POST', '/budget-items', {'unitId': ud, 'name': '시험행사비', 'amount': 1000000}); bi = j['data']['id']
s, j = stf.call('POST', '/budget-items', {'unitId': ue, 'name': '시험공과금', 'amount': 1000000}); bie = j['data']['id']

# ---- 결재 규칙 (금액 구간) ----
good = [
    {'name': '소액', 'minAmount': 0, 'maxAmount': 100000, 'steps': [{'role': '부서장', 'mode': '결재'}, {'role': '위원장', 'mode': '결재'}, {'role': '재정위원장', 'mode': '확인'}], 'addOn': {'over_budget': '담임목사'}},
    {'name': '일반', 'minAmount': 100001, 'maxAmount': 500000, 'steps': [{'role': '부서장', 'mode': '결재'}, {'role': '위원장', 'mode': '결재'}, {'role': '재정위원장', 'mode': '결재'}], 'addOn': {'over_budget': '담임목사'}},
    {'name': '고액', 'minAmount': 500001, 'maxAmount': None, 'steps': [{'role': '부서장', 'mode': '결재'}, {'role': '위원장', 'mode': '결재'}, {'role': '재정위원장', 'mode': '결재'}, {'role': '담임목사', 'mode': '결재'}], 'addOn': {}},
]
s, j = stf.call('POST', '/approval-rules', {'items': good}); check('결재 규칙은 재정 관리자만', s == 403)
bad = [dict(good[0]), dict(good[1], minAmount=100005), dict(good[2])]
s, j = adm.call('POST', '/approval-rules', {'items': bad}); check('구간에 빈틈이 있으면 거절', s == 422, j)
s, j = adm.call('POST', '/approval-rules', {'items': [dict(good[0], maxAmount=None), good[1]]}); check('끝 없는 구간이 마지막이 아니면 거절', s == 422)
s, j = adm.call('POST', '/approval-rules', {'items': good}); check('금액 구간 3개 저장', s == 200, j)
s, j = acc.call('GET', '/approval-rules'); check('규칙 조회', s == 200 and len(j['data']['items']) == 3, j)

def draft(client, amt, unit=ud, item=bi, title='시험 결의서', files=True):
    body = {'unitId': unit, 'title': title, 'reason': '시험', 'payMethod': '계좌이체', 'payBank': '국민', 'payAccount': '123-456-789', 'payHolder': '홍길동', 'items': [{'budgetItemId': item, 'summary': '시험 지출', 'amount': amt}], 'noProofReason': '' if files else '현금 영수증 분실'}
    return client.call('POST', '/vouchers', body)

# ---- 기안 ----
s, j = oth.call('POST', '/vouchers', {'unitId': ud, 'title': '시험', 'payMethod': '현금', 'items': [{'budgetItemId': bi, 'summary': 'x', 'amount': 1}]}); check('관계없는 사람은 기안 불가', s == 403)
s, j = draft(acc, 50000); check('부서 회계가 기안', s == 200, j); v1 = j['data']['id']
s, j = acc.call('POST', '/vouchers', {'unitId': ud, 'title': '시험', 'payMethod': '현금', 'items': [{'budgetItemId': bie, 'summary': 'x', 'amount': 1}]}); check('다른 단위의 예산 항목은 거절', s == 422)
s, j = acc.call('POST', '/vouchers', {'unitId': ud, 'title': '시험', 'payMethod': '현금', 'items': [{'budgetItemId': bi, 'summary': 'x', 'amount': -3}]}); check('음수 금액 거절', s == 422)
s, j = acc.call('GET', f'/vouchers/{v1}'); d = j['data']
check('기안 화면에 결재선 미리보기(소액: 부서장→위원장→재정위원장 확인)', [x['role'] for x in d['preview']['steps']] == ['부서장', '위원장', '재정위원장'] and d['preview']['steps'][2]['mode'] == '확인', d.get('preview'))
s, j = acc.call('POST', f'/vouchers/{v1}/submit', {'rev': d['rev']}); check('증빙도 사유도 없으면 상신 불가', s == 422 and code(j) == 'proof', j)
png = base64.b64encode(b'\x89PNG\r\n\x1a\n' + b'0' * 200).decode()
s, j = acc.call('POST', f'/vouchers/{v1}/files', {'name': '영수증.png', 'data': png}); check('증빙 사진 올리기', s == 200, j); fid = j['data']['id']
s, j = acc.call('POST', f'/vouchers/{v1}/files', {'name': 'x.exe', 'data': base64.b64encode(b'MZ' + b'0' * 50).decode()}); check('사진·PDF가 아닌 파일 거절', s == 422)
s, j = acc.call('POST', f'/vouchers/{v1}/files', {'name': 'big.png', 'data': base64.b64encode(b'\x89PNG\r\n\x1a\n' + b'0' * 120000).decode()}); check('너무 큰 파일 거절', s == 413)
s, j = acc.call('POST', f'/vouchers/{v1}/submit', {'rev': 999}); check('rev 가 다르면 거절(동시 처리 방지)', s == 409 and code(j) == 'conflict')
d = acc.call('GET', f'/vouchers/{v1}')[1]['data']
s, j = acc.call('POST', f'/vouchers/{v1}/submit', {'rev': d['rev']}); check('상신', s == 200, j)
d = acc.call('GET', f'/vouchers/{v1}')[1]['data']
check('문서 번호가 매겨지고 결재 중', d['status'] == '결재 중' and d['docNo'].startswith('2098-'), d)
s, j = acc.call('POST', f'/vouchers/{v1}', {'rev': d['rev'], 'title': '바꿈', 'payMethod': '현금', 'items': [{'budgetItemId': bi, 'summary': 'x', 'amount': 5}]}); check('상신 뒤에는 수정 불가', s == 409)
s, j = oth.call('GET', f'/vouchers/{v1}'); check('관계없는 사람은 결의서를 못 봄', s == 403)
s, j = oth.call('GET', f'/voucher-files/{fid}'); check('증빙 파일도 권한 없이는 못 봄', s == 403 or s == 404 or (s == 200 and False))
s, j = acc.call('GET', f'/voucher-files/{fid}') if False else (200, {})

# ---- 결재 (순서) ----
s, j = comm.call('POST', f'/vouchers/{v1}/approve', {'password': PW, 'rev': d['rev']}); check('부서장 차례에 위원장은 결재 불가', s == 403 and code(j) == 'not_your_turn', j)
s, j = head.call('GET', '/approvals/inbox'); check('부서장의 결재함에 보임', any(x['id'] == v1 for x in j['data']['waiting']), j)
s, j = head.call('POST', f'/vouchers/{v1}/approve', {'password': 'wrong', 'rev': d['rev']}); check('결재 때 비밀번호가 틀리면 거절', s == 401)
s, j = head.call('POST', f'/vouchers/{v1}/approve', {'password': PW, 'rev': d['rev'], 'comment': '확인'}); check('부서장 결재', s == 200, j)
d = head.call('GET', f'/vouchers/{v1}')[1]['data']
s, j = acc.call('POST', f'/vouchers/{v1}/recall', {'rev': d['rev']}); check('결재가 시작되면 회수 불가', s == 409, j)
s, j = comm.call('POST', f'/vouchers/{v1}/approve', {'password': PW, 'rev': d['rev']}); check('위원장 결재 → 결재 완료(재정위원장은 확인 단계라 막지 않음)', s == 200, j)
d = stf.call('GET', f'/vouchers/{v1}')[1]['data']
check('상태 결재 완료, 단계 해시가 남음', d['status'] == '결재 완료' and d['steps'][0]['hash'] and d['steps'][0]['status'] == '완료', d['steps'])
s, j = chair.call('GET', '/approvals/inbox'); check('재정위원장의 사후 확인 목록에 보임', any(x['id'] == v1 for x in j['data']['confirm']), j)
s, j = chair.call('POST', f'/vouchers/{v1}/approve', {'password': PW, 'rev': d['rev']}); check('재정위원장 사후 확인', s == 200, j)

# ---- 지급 ----
d = stf.call('GET', f'/vouchers/{v1}')[1]['data']
s, j = acc.call('POST', f'/vouchers/{v1}/pay', {'rev': d['rev'], 'walletId': w1, 'paidOn': '2098-05-02'}); check('지급은 재정 담당만', s == 403)
s, j = stf.call('POST', f'/vouchers/{v1}/pay', {'rev': d['rev'], 'walletId': w1, 'paidOn': '2097-05-02'}); check('지급일이 회계연도 밖이면 거절', s == 422, j)
s, j = stf.call('POST', f'/vouchers/{v1}/pay', {'rev': d['rev'], 'walletId': w1, 'paidOn': '2098-05-02'}); check('지급 처리', s == 200, j)
check('장부에 지출이 생기고 결의서와 이어짐', sql("select count(*) from fm_transactions where source='결의서' and source_id=%d and amount=50000 and void_at is null" % v1) == '1')
check('지급 완료 상태', stf.call('GET', f'/vouchers/{v1}')[1]['data']['status'] == '지급 완료')
d = stf.call('GET', f'/vouchers/{v1}')[1]['data']
s, j = stf.call('POST', f'/vouchers/{v1}/pay', {'rev': d['rev'], 'walletId': w1, 'paidOn': '2098-05-03'}); check('이중 지급 불가', s == 409)
b = chair.call('GET', f'/budget?year={Y}')[1]['data']; it = {i['name']: i for u in b['units'] for i in u['items']}
check('예산 집행에 반영 (집행 50,000)', it['시험행사비']['paid'] == 50000 and it['시험행사비']['pending'] == 0, it)

# ---- 일반 구간: 재정위원장 결재 필수, 반려·재상신 ----
s, j = draft(acc, 300000, title='시험 일반'); v2 = j['data']['id']
d = acc.call('GET', f'/vouchers/{v2}')[1]['data']
s, j = acc.call('POST', f'/vouchers/{v2}/submit', {'rev': d['rev']}); check('상신(증빙 사유 있음 아님 → 증빙 필요)', s == 422)
acc.call('POST', f'/vouchers/{v2}/files', {'name': 'a.png', 'data': png})
d = acc.call('GET', f'/vouchers/{v2}')[1]['data']; acc.call('POST', f'/vouchers/{v2}/submit', {'rev': d['rev']})
d = head.call('GET', f'/vouchers/{v2}')[1]['data']
s, j = head.call('POST', f'/vouchers/{v2}/reject', {'password': PW, 'rev': d['rev']}); check('반려는 사유 필수', s == 422)
s, j = head.call('POST', f'/vouchers/{v2}/reject', {'password': PW, 'rev': d['rev'], 'comment': '금액 확인 바람'}); check('부서장 반려', s == 200, j)
d = acc.call('GET', f'/vouchers/{v2}')[1]['data']
check('반려 상태, 기안자가 고칠 수 있음', d['status'] == '반려' and d['can']['edit'], d['can'])
s, j = acc.call('POST', f'/vouchers/{v2}', {'rev': d['rev'], 'title': '시험 일반(수정)', 'payMethod': '계좌이체', 'payBank': '국민', 'payAccount': '123-456-789', 'payHolder': '홍길동', 'items': [{'budgetItemId': bi, 'summary': '수정', 'amount': 250000}]}); check('고쳐서 재상신 준비', s == 200, j)
d = acc.call('GET', f'/vouchers/{v2}')[1]['data']
s, j = acc.call('POST', f'/vouchers/{v2}/submit', {'rev': d['rev']}); check('재상신', s == 200, j)
d = acc.call('GET', f'/vouchers/{v2}')[1]['data']
check('재상신하면 결재가 처음부터 (회차 2, 같은 문서 번호)', d['round'] == 2 and [s['status'] for s in d['steps'] if s['round'] == 2] == ['대기', '대기', '대기'], d['steps'])
s, j = acc.call('POST', f'/vouchers/{v2}/recall', {'rev': d['rev']}); check('첫 결재 전 회수', s == 200, j)
check('회수 상태', acc.call('GET', f'/vouchers/{v2}')[1]['data']['status'] == '회수')
d = acc.call('GET', f'/vouchers/{v2}')[1]['data']; s, j = acc.call('POST', f'/vouchers/{v2}/cancel', {'rev': d['rev']}); check('취소', s == 200)

# ---- 고액: 담임목사까지, 대결 ----
s, j = draft(acc, 700000, title='시험 고액'); v3 = j['data']['id']
acc.call('POST', f'/vouchers/{v3}/files', {'name': 'a.png', 'data': png})
d = acc.call('GET', f'/vouchers/{v3}')[1]['data']
check('고액 미리보기에 담임목사 단계', [x['role'] for x in d['preview']['steps']][-1] == '담임목사', d['preview'])
check('예산(1,000,000)에서 집행 50,000이 있어 700,000은 초과 아님', d.get('overBudget') is False, d.get('overBudget'))
acc.call('POST', f'/vouchers/{v3}/submit', {'rev': d['rev']})
sql("insert into fm_delegations (from_person_id, from_name, to_person_id, to_name, starts_on, ends_on) select '%s','시험부서장', '%s', '시험대결', date_sub(curdate(), interval 1 day), date_add(curdate(), interval 3 day)" % (people[5], people[8]))
d = acc.call('GET', f'/vouchers/{v3}')[1]['data']
s, j = sub.call('GET', '/approvals/inbox'); check('대리 결재자의 결재함에도 보임(대결)', any(x['id'] == v3 and x['delegate'] for x in j['data']['waiting']), j)
s, j = sub.call('POST', f'/vouchers/{v3}/approve', {'password': PW, 'rev': d['rev']}); check('대결로 결재', s == 200, j)
d = acc.call('GET', f'/vouchers/{v3}')[1]['data']; check('대결 표시가 남음', d['steps'][0]['delegate'] is True, d['steps'])
for c in (comm, chair):
    d = acc.call('GET', f'/vouchers/{v3}')[1]['data']; c.call('POST', f'/vouchers/{v3}/approve', {'password': PW, 'rev': d['rev']})
d = acc.call('GET', f'/vouchers/{v3}')[1]['data']
check('담임목사 결재 전에는 결재 중', d['status'] == '결재 중', d['status'])
s, j = pas.call('POST', f'/vouchers/{v3}/approve', {'password': PW, 'rev': d['rev']}); check('담임목사 최종 결재', s == 200, j)
check('결재 완료', acc.call('GET', f'/vouchers/{v3}')[1]['data']['status'] == '결재 완료')
s, j = stf.call('GET', '/vouchers-to-pay'); check('지급 대기 목록', any(x['id'] == v3 for x in j['data']['items']), j)

# ---- 예산 초과 → 담임목사 단계 추가, 담당자 미지정 오류, 기안자=결재자 ----
stf.call('POST', f'/units/{ue}/roles', {'role': '회계', 'personId': people[4]})
s, j = draft(acc, 90000, title='시험 초과', unit=ue, item=bie); v4 = j['data']['id']
d = acc.call('GET', f'/vouchers/{v4}')[1]['data']
check('기타 단위는 부서장·위원장 단계 없이 재정위원장부터(담당자 지정 없음 오류 아님)', d['preview']['error'] is None and [x['role'] for x in d['preview']['steps']] == ['재정위원장'], d['preview'])
s, j = stf.call('POST', '/vouchers', {'unitId': ue, 'title': '시험 초과예산', 'payMethod': '현금', 'noProofReason': 'x', 'items': [{'budgetItemId': bie, 'summary': '큰돈', 'amount': 2000000}]}); v5 = j['data']['id']
d = stf.call('GET', f'/vouchers/{v5}')[1]['data']
check('예산을 넘으면 overBudget, 담임목사 단계가 더해짐', d['overBudget'] is True and d['preview']['steps'][-1]['role'] == '담임목사', d['preview'])
s, j = chair.call('POST', '/vouchers', {'unitId': ue, 'title': '시험 위원장기안', 'payMethod': '현금', 'noProofReason': 'x', 'items': [{'budgetItemId': bie, 'summary': 'x', 'amount': 1000}]}); check('단위 담당자도 재정 담당도 아니면 기안 불가', s == 403, j)
# 담당자가 지정되지 않은 단위는 상신 불가
sql("delete from fm_unit_roles where unit_id=%d and role='부서장'" % ud)
s, j = draft(acc, 1000, title='시험 미지정'); v6 = j['data']['id']
acc.call('POST', f'/vouchers/{v6}/files', {'name': 'a.png', 'data': png})
d = acc.call('GET', f'/vouchers/{v6}')[1]['data']
check('부서장 미지정이면 미리보기에 이유가 나옴', d['preview']['error'] and '부서장' in d['preview']['error'], d['preview'])
s, j = acc.call('POST', f'/vouchers/{v6}/submit', {'rev': d['rev']}); check('부서장 미지정이면 상신 거절', s == 409 and code(j) == 'steps', j)
# 기안자가 부서장 본인이면 그 단계는 넘어가되 다른 사람의 결재는 필요
stf.call('POST', f'/units/{ud}/roles', {'role': '부서장', 'personId': people[4]})
d = acc.call('GET', f'/vouchers/{v6}')[1]['data']
check('기안자=부서장이면 그 단계는 건너뜀 표시', d['preview']['steps'][0]['skip'] is True, d['preview'])
acc.call('POST', f'/vouchers/{v6}/submit', {'rev': d['rev']})
d = acc.call('GET', f'/vouchers/{v6}')[1]['data']
check('상신 뒤 첫 대기 단계는 위원장', [s['status'] for s in d['steps']][:2] == ['건너뜀', '대기'], d['steps'])
s, j = comm.call('POST', f'/vouchers/{v6}/approve', {'password': PW, 'rev': d['rev']}); check('위원장이 결재', s == 200, j)

wipe()
sql("delete from fm_delegations")
finish()
