"""M5 시험: 기부금 영수증 — 대상 합계(영수증 대상 항목만, 마감분만), 가족 합산, 발급·재발급·정정, 주민번호 두 방식."""
import os, base64, urllib.request
from testkit import *  # noqa

CFG = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'server', 'data', 'config.php')
orig_cfg = open(CFG, encoding='utf-8').read()
key = base64.b64encode(os.urandom(32)).decode()
open(CFG, 'w', encoding='utf-8').write(orig_cfg.replace('return array (', "return array (\n  'rrn_key' => '%s'," % key, 1))
orig_mode = sql("select rrn_mode from fm_settings")

def valid_rrn(prefix12):
    w = [2, 3, 4, 5, 6, 7, 8, 9, 2, 3, 4, 5]
    s = sum(int(c) * x for c, x in zip(prefix12, w))
    return prefix12 + str((11 - s % 11) % 10)

try:
    wipe()
    U, people = make_users({
        't_staff': ('시험담당', ['재정 담당'], 0, 1, 0),
        't_blind': ('시험비열람', ['재정 담당'], 0, 0, 1),
        't_view': ('시험열람', ['열람'], 0, 0, 2),
        't_adm': ('시험관리자', [], 1, 0, 3),
    })
    stf, blind, vw = U['t_staff'], U['t_blind'], U['t_view']
    sql("update fm_settings set rrn_mode='none'")
    Y = 2098
    stf.call('POST', '/fiscal-years', {'year': Y, 'start': '2098-01-01', 'end': '2098-12-31'})
    s, j = stf.call('POST', '/wallets', {'fundId': 1, 'name': '시험현금', 'kind': '현금', 'opening': 0}); wc = j['data']['id']
    s, j = stf.call('POST', '/income-accounts', {'parentId': 1, 'name': '시험비과세헌금', 'isOffering': True}); nonrc = j['data']['id']

    def offer(date, lines):
        s, j = stf.call('POST', '/offering-batches', {'date': date, 'kind': '주일'}); bid = j['data']['id']
        for acct, donor, amt in lines:
            s, j = stf.call('POST', f'/offering-batches/{bid}/lines', {'accountId': acct, 'donor': donor, 'amount': amt, 'method': '현금'}); assert s == 200, j
        s, j = stf.call('POST', f'/offering-batches/{bid}/close', {'cashWalletId': wc}); assert s == 200, j
        return bid
    P1, P2 = 'p:' + people[5], 'p:' + people[6]
    offer('2098-03-01', [(2, P1, 100000), (nonrc, P1, 50000), (2, P2, 30000)])
    s, j = stf.call('POST', '/offering-batches', {'date': '2098-04-05', 'kind': '주일'}); open_b = j['data']['id']
    stf.call('POST', f'/offering-batches/{open_b}/lines', {'accountId': 2, 'donor': P1, 'amount': 999999, 'method': '현금'})   # 마감 안 한 묶음

    s, j = blind.call('GET', f'/receipts/preview?year={Y}'); check('개인별 열람 권한이 없는 재정 담당은 영수증 못 다룸', s == 403)
    s, j = vw.call('GET', f'/receipts/preview?year={Y}'); check('열람 역할은 영수증 못 다룸', s == 403)
    s, j = stf.call('GET', f'/receipts/preview?year={Y}'); it = {i['name']: i for i in j['data']['items']}
    names = [i['name'] for i in j['data']['items']]
    check('미리보기: 영수증 대상(비과세 항목·미마감 제외) — P1 100,000, P2 30,000', len(names) == 2 and sorted(i['total'] for i in j['data']['items']) == [30000, 100000], j)
    donors = {d['name']: d['id'] for d in stf.call('GET', '/donors')[1]['data']['items']}
    nm1 = sql("select name from cm_people where id='%s'" % people[5]); nm2 = sql("select name from cm_people where id='%s'" % people[6])
    d1, d2 = donors[nm1], donors[nm2]
    s, j = stf.call('POST', '/receipts/issue', {'year': Y, 'donorIds': [d1]}); check('주소가 없으면 발급 건너뜀(이유 안내)', s == 200 and j['data']['issued'] == 0 and '주소' in j['data']['skipped'][0]['why'], j)
    s, j = stf.call('POST', f'/donors/{d1}', {'address': '서울시 시험구 시험로 1'}); check('영수증 주소 입력', s == 200, j)
    s, j = stf.call('POST', f'/donors/{d1}', {'rrn': '9001011234567'}); check('미보관 방식에서는 주민번호 입력 불가', s == 409 and code(j) == 'rrn_none', j)
    s, j = stf.call('POST', f'/donors/{d2}', {'receiptTo': d1}); check('가족 합산 설정(P2 → P1)', s == 200, j)
    s, j = stf.call('POST', f'/donors/{d1}', {'receiptTo': d2}); check('합산 대표가 서로 순환되는 설정 거절', s == 422, j)
    s, j = stf.call('GET', f'/receipts/preview?year={Y}'); items = j['data']['items']
    check('합산하면 한 장 130,000 (두 사람 이름 포함)', len(items) == 1 and items[0]['total'] == 130000 and len(items[0]['members']) == 2, items)
    s, j = stf.call('POST', '/receipts/issue', {'year': Y, 'donorIds': [d1]}); check('발급', s == 200 and j['data']['issued'] == 1, j)
    s, j = stf.call('GET', f'/receipts?year={Y}'); rc = j['data']['items'][0]
    check('발급 번호 2098-0001', rc['docNo'] == '2098-0001' and rc['total'] == 130000, rc)
    s, j = stf.call('POST', '/receipts/issue', {'year': Y, 'donorIds': [d1]}); check('같은 내용을 다시 발급하면 재발급(같은 번호)', j['data']['reissued'] == 1 and j['data']['issued'] == 0, j)
    s, j = stf.call('GET', f'/receipts/{rc["id"]}'); sn = j['data']['snapshot']
    check('대장 스냅숏: 이름·주소·금액·교회, 주민번호 값 없음', sn['total'] == 130000 and sn['address'].startswith('서울') and sn['rrnMode'] == 'none' and j['data']['rrnFull'] is None, sn)
    offer('2098-05-03', [(2, P1, 20000)])
    s, j = stf.call('GET', f'/receipts/preview?year={Y}'); check('발급 뒤 헌금이 늘면 금액 변동 표시', j['data']['items'][0]['issued']['changed'] is True, j)
    s, j = stf.call('POST', '/receipts/issue', {'year': Y, 'donorIds': [d1]}); check('금액이 달라지면 정정 발급(이전 것 무효)', j['data']['corrected'] == 1, j)
    rs = stf.call('GET', f'/receipts?year={Y}')[1]['data']['items']
    check('새 번호 2098-0002, 이전 것은 무효 표시', rs[0]['docNo'] == '2098-0002' and rs[0]['correction'] and rs[1]['void'] and rs[0]['total'] == 150000, rs)
    req = urllib.request.Request('http://127.0.0.1:8091/api/v1/receipts-export?year=%d' % Y)
    resp = stf.op.open(req); body = resp.read().decode('utf-8-sig')
    check('발급 명세 CSV(유효한 영수증만)', '2098-0002' in body and '2098-0001' not in body and '150000' in body, body)

    # ---- 보관(keep) 방식 ----
    sql("update fm_settings set rrn_mode='keep'")
    s, j = stf.call('POST', f'/donors/{d1}', {'rrn': '9001011234567'}); check('잘못된 주민번호(검증 숫자) 거절', s == 422, j)
    good = valid_rrn('900101123456')
    s, j = stf.call('POST', '/receipts/issue', {'year': Y, 'donorIds': [d1]}); check('보관 방식에서 번호가 없으면 발급 건너뜀', j['data']['reissued'] + j['data']['corrected'] + j['data']['issued'] == 0 and '주민등록번호' in j['data']['skipped'][0]['why'], j)
    s, j = stf.call('POST', f'/donors/{d1}', {'rrn': good}); check('주민번호 암호화 저장', s == 200, j)
    enc = sql("select rrn_enc from fm_donors where id=%d" % d1)
    check('DB 에는 암호문만(숫자가 그대로 보이지 않음)', enc and good[:6] not in enc and good not in enc and len(enc) > 30, enc)
    ds = stf.call('GET', '/donors')[1]['data']; check('목록에는 보관 여부만 표시되고 번호 값은 없음', any(d['hasRrn'] for d in ds['items']) and good not in str(ds))
    s, j = stf.call('POST', '/receipts/issue', {'year': Y, 'donorIds': [d1]}); check('같은 내용 재발급', j['data']['reissued'] == 1, j)
    rid = stf.call('GET', f'/receipts?year={Y}')[1]['data']['items'][0]['id']
    s, j = stf.call('GET', f'/receipts/{rid}'); check('기본 조회는 주민번호를 풀지 않음', j['data']['rrnFull'] is None)
    s, j = stf.call('GET', f'/receipts/{rid}?rrn=1'); check('인쇄 때만 서버가 풀어 줌', j['data']['rrnFull'] == good[:6] + '-' + good[6:], j)
    check('주민번호 사용이 기록에 남음', int(sql("select count(*) from fm_access_log where action='주민번호 사용' and who='시험담당'")) >= 1)
    sql("update fm_settings set rrn_mode='none'")
    s, j = stf.call('POST', f'/donors/{d1}', {'address': '서울시 시험구 시험로 2'}); check('주소 수정', s == 200)
finally:
    open(CFG, 'w', encoding='utf-8').write(orig_cfg)
    sql("update fm_settings set rrn_mode='%s'" % orig_mode)
    wipe()
    sql("delete from fm_meta where k like 'receipt_seq_209%' or k like 'voucher_seq_209%'")
    sql("delete from fm_donors where name like '시험%'")
finish()
