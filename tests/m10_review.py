"""검토 보완 시험: 교회 정보·직인·영수증 서식, 예산 초과 차단, 주민번호 방식 전환·정리, 직접 지출 증빙, 계수 위원 열람 범위, 결재 예외."""
import os, base64, urllib.request, urllib.error
from testkit import *  # noqa

CFG = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'server', 'data', 'config.php')
orig_cfg = open(CFG, encoding='utf-8').read()
orig_mode = sql("select rrn_mode from fm_settings")
orig_church = sql("select church from fm_settings")
orig_sec = sql("select security from fm_settings")
orig_form = sql("select receipt_form from fm_settings")
PW = 'testpass1234'
png = base64.b64encode(b'\x89PNG\r\n\x1a\n' + b'0' * 300).decode()
try:
    wipe()
    sql("delete from fm_delegations")
    U, people = make_users({
        't_admin': ('시험관리자', [], 1, 0, 0),
        't_staff': ('시험담당', ['재정 담당'], 0, 1, 1),
        't_chair': ('시험재정위원장', ['재정위원장'], 0, 1, 2),
        't_chair2': ('시험재정위원장2', ['재정위원장'], 0, 1, 3),
        't_pastor': ('시험담임', ['담임목사'], 0, 1, 4),
        't_acct': ('시험회계', [], 0, 0, 5),
        't_head': ('시험부서장', [], 0, 0, 6),
        't_comm': ('시험위원장', [], 0, 0, 7),
        't_count': ('시험계수', ['계수 위원'], 0, 0, 8),
    })
    adm, stf, ch1, ch2, pas, acc, head, comm, cnt = [U[k] for k in ('t_admin', 't_staff', 't_chair', 't_chair2', 't_pastor', 't_acct', 't_head', 't_comm', 't_count')]
    adm.call('POST', '/auth/reconfirm', {'password': PW})

    # ---- 교회 정보·직인·영수증 서식 ----
    s, j = stf.call('POST', '/settings/church', {'name': '시험교회'}); check('교회 정보는 재정 관리자만', s == 403)
    s, j = adm.call('POST', '/settings/church', {'name': '시험교회', 'bizNo': '123-82-12345', 'address': '서울시 시험구 1', 'pastorName': '홍목사'}); check('교회 정보 저장', s == 200, j)
    s, j = adm.call('POST', '/settings/church', {'name': '시험교회', 'bizNo': 'abc'}); check('고유번호 형식 검사', s == 422)
    s, j = stf.call('GET', '/settings'); check('설정에 교회 정보가 반영됨', j['data']['church']['bizNo'] == '123-82-12345' and j['data']['church']['pastorName'] == '홍목사', j)
    s, j = stf.call('GET', '/settings/seal'); check('직인이 없으면 404', s == 404)
    s, j = adm.call('POST', '/settings/seal', {'data': base64.b64encode(b'MZ' + b'0' * 50).decode()}); check('이미지가 아닌 직인 거절', s == 422)
    s, j = adm.call('POST', '/settings/seal', {'data': png}); check('직인 올리기', s == 200, j)
    s, j = stf.call('GET', '/settings'); check('직인 유무가 설정에 나옴', j['data']['hasSeal'] is True)
    s, j = acc.call('GET', '/settings/seal'); check('일반 사용자는 직인을 못 봄', s == 403)
    s, j = adm.call('POST', '/settings/seal/delete', {}); check('직인 지우기', s == 200)
    s, j = adm.call('POST', '/settings/receipt-form', {'lineMode': 'month', 'code': '41'}); check('영수증 줄 방식·구분 코드 저장', s == 200, j)
    s, j = adm.call('POST', '/settings/receipt-form', {'code': 'abc'}); check('구분 코드는 숫자만', s == 422)

    # ---- 직접 지출 증빙 ----
    Y = 2098
    stf.call('POST', '/fiscal-years', {'year': Y, 'start': '2098-01-01', 'end': '2098-12-31'})
    s, j = stf.call('POST', '/wallets', {'fundId': 1, 'name': '시험통장', 'kind': '통장', 'opening': 1000000}); w1 = j['data']['id']
    s, j = stf.call('POST', '/units', {'year': Y, 'kind': '위원회', 'name': '시험위원회'}); uc = j['data']['id']
    s, j = stf.call('POST', '/units', {'year': Y, 'kind': '부서', 'name': '시험부서', 'parentId': uc}); ud = j['data']['id']
    s, j = stf.call('POST', '/units', {'year': Y, 'kind': '기타', 'name': '시험기타'}); ue = j['data']['id']
    for unit, role, pi in ((ud, '회계', 5), (ud, '부서장', 6), (uc, '위원장', 7)):
        stf.call('POST', f'/units/{unit}/roles', {'role': role, 'personId': people[pi]})
    s, j = stf.call('POST', '/budget-items', {'unitId': ud, 'name': '시험행사비', 'amount': 100000}); bi = j['data']['id']
    s, j = stf.call('POST', '/budget-items', {'unitId': ue, 'name': '시험공과금', 'amount': 1000000}); bie = j['data']['id']
    adm.call('POST', f'/budget-items/{bie}/direct', {'directOk': True})
    D = '2098-02-10'
    base = {'kind': '지출', 'date': D, 'walletId': w1, 'budgetItemId': bie, 'amount': 5000, 'summary': '시험 전기료'}
    s, j = stf.call('POST', '/transactions', base); check('직접 지출은 증빙 없이 등록 불가', s == 422 and code(j) == 'proof', j)
    s, j = stf.call('POST', '/transactions', dict(base, noProofReason='자동이체 영수증 없음')); check('증빙 없는 사유로 등록', s == 200, j)
    s, j = stf.call('POST', '/transactions', dict(base, proof={'name': 'a.png', 'data': png})); check('영수증 사진과 함께 등록', s == 200, j)
    items = stf.call('GET', f'/ledger?year={Y}&limit=100')[1]['data']
    pr = [i for i in items['items'] if i['proofs']]
    check('장부에 증빙이 연결되어 보임', len(pr) == 1 and items['total'] == 2, items)
    r = stf.op.open(urllib.request.Request('http://127.0.0.1:8091/api/v1/transaction-files/%d' % pr[0]['proofs'][0]['id'])); check('증빙 파일 내려받기(권한자)', r.status == 200 and r.headers['Content-Type'] == 'image/png')
    s, j = acc.call('GET', f"/transaction-files/{pr[0]['proofs'][0]['id']}"); check('증빙은 권한 없으면 못 봄', s == 403)
    adm.call('POST', '/settings/direct-rules', {'roles': ['재정 담당'], 'monthCap': None, 'proofRequired': False, 'closeConfirm': True})
    s, j = stf.call('POST', '/transactions', base); check('증빙 필수를 끄면 증빙 없이도 등록', s == 200, j)
    s, j = stf.call('GET', f'/ledger?year={Y}&limit=100'); check('장부 건수 안내(total)', j['data']['total'] == 3)

    # ---- 예산 초과 차단 ----
    chk = stf.call('POST', f'/fiscal-years/{Y}/status', {'status': '진행'})  # 진행 중인 해가 있으면 실패해도 무방
    ch1.call('POST', '/auth/reconfirm', {'password': PW})
    def draft(client, amt, title):
        s, j = client.call('POST', '/vouchers', {'unitId': ud, 'title': title, 'reason': '시험', 'payMethod': '현금', 'noProofReason': '시험', 'items': [{'budgetItemId': bi, 'summary': 'x', 'amount': amt}]})
        return j['data']['id']
    v1 = draft(acc, 500000, '시험 초과')   # 예산 100,000 초과
    d = acc.call('GET', f'/vouchers/{v1}')[1]['data']
    s, j = adm.call('POST', '/settings/over-budget', {'mode': 'block'}); check('예산 초과 처리 방식 변경', s == 200, j)
    s, j = acc.call('POST', f'/vouchers/{v1}/submit', {'rev': d['rev']}); check('차단 방식: 예산을 넘는 결의서는 상신 불가', s == 409 and code(j) == 'over_budget', j)
    adm.call('POST', '/settings/over-budget', {'mode': 'warn'})
    s, j = acc.call('POST', f'/vouchers/{v1}/submit', {'rev': d['rev']}); check('경고 방식: 상신됨(담임목사 단계가 더해짐)', s == 200, j)
    d = acc.call('GET', f'/vouchers/{v1}')[1]['data']
    check('초과 결의서에 담임목사 단계', d['steps'][-1]['role'] == '담임목사', d['steps'])

    # ---- 결재 예외: 재정위원장이 여럿이면 누구나, 기안자 본인은 제외, 기안자가 재정위원장이면 담임목사가 대신 ----
    v2 = draft(acc, 50000, '시험 일반')
    d = acc.call('GET', f'/vouchers/{v2}')[1]['data']; acc.call('POST', f'/vouchers/{v2}/submit', {'rev': d['rev']})
    for c in (head, comm):
        d = acc.call('GET', f'/vouchers/{v2}')[1]['data']; c.call('POST', f'/vouchers/{v2}/approve', {'password': PW, 'rev': d['rev']})
    d = acc.call('GET', f'/vouchers/{v2}')[1]['data']
    chief_step = [s for s in d['steps'] if s['role'] == '재정위원장'][0]
    approver = ch2 if chief_step['name'] == '시험재정위원장' else ch1
    s, j = approver.call('POST', f'/vouchers/{v2}/approve', {'password': PW, 'rev': d['rev']}); check('재정위원장이 여럿이면 지정되지 않은 다른 분도 결재 가능', s == 200, j)
    # 기안자가 재정위원장 → 재정위원장 단계는 담임목사로
    stf.call('POST', f'/units/{ud}/roles', {'role': '회계', 'personId': people[2]})
    s, j = ch1.call('POST', '/vouchers', {'unitId': ud, 'title': '시험 위원장기안', 'payMethod': '현금', 'noProofReason': 'x', 'items': [{'budgetItemId': bi, 'summary': 'x', 'amount': 5000}]}); v3 = j['data']['id']
    d = ch1.call('GET', f'/vouchers/{v3}')[1]['data']
    check('기안자가 재정위원장이면 결재선의 재정위원장 단계가 담임목사로 바뀜', [s['role'] for s in d['preview']['steps']] == ['부서장', '위원장', '담임목사'], d['preview'])
    ch1.call('POST', f'/vouchers/{v3}/submit', {'rev': d['rev']})
    d = ch1.call('GET', f'/vouchers/{v3}')[1]['data']
    for c in (head, comm):
        c.call('POST', f'/vouchers/{v3}/approve', {'password': PW, 'rev': d['rev']}); d = ch1.call('GET', f'/vouchers/{v3}')[1]['data']
    s, j = ch2.call('POST', f'/vouchers/{v3}/approve', {'password': PW, 'rev': d['rev']}); check('다른 재정위원장도 담임목사 단계는 못 함', s == 403, j)
    s, j = ch1.call('POST', f'/vouchers/{v3}/approve', {'password': PW, 'rev': d['rev']}); check('기안자 본인은 자기 결의서를 결재할 수 없음', s == 403, j)
    s, j = pas.call('POST', f'/vouchers/{v3}/approve', {'password': PW, 'rev': d['rev']}); check('담임목사가 결재', s == 200, j)

    # ---- 계수 위원: 마감하면 더 볼 수 없다 ----
    s, j = stf.call('POST', '/wallets', {'fundId': 1, 'name': '시험현금', 'kind': '현금', 'opening': 0}); wc = j['data']['id']
    s, j = cnt.call('POST', '/offering-batches', {'date': '2098-03-01', 'kind': '주일예배'}); bid = j['data']['id']
    cnt.call('POST', f'/offering-batches/{bid}/lines', {'accountId': 4, 'amount': 100000, 'method': '현금'})
    s, j = cnt.call('GET', f'/offering-batches/{bid}'); check('입력 중에는 계수 위원이 자기 묶음을 봄', s == 200 and j['data']['entry'])
    stf.call('POST', f'/offering-batches/{bid}/close', {'cashWalletId': wc})
    s, j = cnt.call('GET', f'/offering-batches/{bid}'); check('마감하면 계수 위원은 더 볼 수 없음', s == 403, j)
    s, j = cnt.call('GET', f'/offering-batches?year={Y}'); check('계수 위원의 목록에서도 마감된 묶음이 빠짐', j['data']['items'] == [], j)
    s, j = stf.call('GET', f'/offering-batches/{bid}'); check('재정 담당은 마감 뒤에도 봄', s == 200)

    # ---- 주민번호 방식 전환·정리 ----
    sql("update fm_settings set rrn_mode='none'")
    s, j = adm.call('POST', '/settings/rrn-mode', {'mode': 'keep'}); check('보관 방식으로 전환(열쇠가 없으면 새로 만들어 줌)', s == 200 and (j['data']['newKey'] or 'rrn_key' in open(CFG, encoding='utf-8').read()), j)
    check('설정 파일에 열쇠가 들어감', 'rrn_key' in open(CFG, encoding='utf-8').read())
    s, j = stf.call('POST', '/donors', {'name': '시험보관자'}); did = j['data']['id']
    sql("update fm_donors set rrn_enc='ZZZZ' where id=%d" % did)
    s, j = adm.call('POST', '/settings/rrn-purge', {'years': 5}); check('기간 지난 번호 정리 대상 수(방금 만든 헌금자는 해당 없음)', s == 200 and j['data']['count'] == 0, j)
    sql("update fm_donors set created_at = date_sub(now(), interval 8 year) where id=%d" % did)
    s, j = adm.call('POST', '/settings/rrn-purge', {'years': 5}); check('8년 전 헌금자는 정리 대상', j['data']['count'] == 1 and not j['data']['applied'], j)
    s, j = adm.call('POST', '/settings/rrn-purge', {'years': 5, 'apply': True}); check('정리 실행', j['data']['applied'] and sql("select count(*) from fm_donors where id=%d and rrn_enc is null" % did) == '1', j)
    sql("update fm_donors set rrn_enc='ZZZZ' where id=%d" % did)
    s, j = adm.call('POST', '/settings/rrn-mode', {'mode': 'none'}); check('미보관으로 바꿀 때 확인 문구 필요', s == 422 and code(j) == 'confirm', j)
    s, j = adm.call('POST', '/settings/rrn-mode', {'mode': 'none', 'confirm': '번호 삭제'}); check('미보관 전환', s == 200, j)
    check('저장된 번호가 모두 지워짐', sql("select count(*) from fm_donors where rrn_enc is not null") == '0')
    check('모드가 none', sql("select rrn_mode from fm_settings") == 'none')
finally:
    open(CFG, 'w', encoding='utf-8').write(orig_cfg)
    sql("update fm_settings set rrn_mode='%s'" % orig_mode)
    wipe()
    sql("delete from fm_delegations")
    sql("delete from fm_transactions where fiscal_year in (2097,2098,2099)")
    # 설정 원상복구 (한글·따옴표가 들어 있어 표준입력으로 보내는 sql() 을 그대로 쓴다)
    def esc(v): return v.replace('\\', '\\\\').replace("'", "''")
    sql("update fm_settings set church='%s', security='%s', receipt_form='%s'" % (esc(orig_church), esc(orig_sec), esc(orig_form)))
    sql("update fm_settings set direct_rules='{\"roles\": [\"재정 담당\"], \"month_cap\": null, \"proof_required\": true, \"close_confirm\": true}'")
    for f in os.listdir(os.path.join(os.path.dirname(CFG))):
        if f.startswith('seal.'): os.remove(os.path.join(os.path.dirname(CFG), f))
finish()
