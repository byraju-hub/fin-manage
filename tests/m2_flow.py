"""M2 시험: 헌금 묶음·입력·대조·마감·다시 열기, 보고서, 개인별 열람 권한."""
from testkit import *  # noqa
import testkit
from urllib.parse import quote

wipe()
U, people = make_users({
    't_staff': ('시험담당', ['재정 담당'], 0, 1, 0),
    't_count': ('시험계수', ['계수 위원'], 0, 0, 1),
    't_count2': ('시험계수2', ['계수 위원'], 0, 0, 2),
    't_view': ('시험열람', ['열람'], 0, 0, 3),
})
stf, cnt, cnt2, vw = U['t_staff'], U['t_count'], U['t_count2'], U['t_view']
stf.call('POST', '/funds', {'name': '시험회계'})
s, j = stf.call('POST', '/wallets', {'fundId': 1, 'name': '시험현금', 'kind': '현금', 'opening': 0}); wc = j['data']['id']
s, j = stf.call('POST', '/wallets', {'fundId': 1, 'name': '시험통장', 'kind': '통장', 'opening': 0}); wb = j['data']['id']
sql("delete from fm_closings where period='2026-11'")

DATE = '2026-11-08'
s, j = vw.call('POST', '/offering-batches', {'date': DATE, 'kind': '주일'}); check('열람 역할은 헌금 묶음을 못 만듦', s == 403)
s, j = cnt.call('POST', '/offering-batches', {'date': DATE, 'kind': '주일', 'counters': '시험계수'}); check('계수 위원이 묶음 생성', s == 200, j); bid = j['data']['id']
s, j = cnt.call('POST', '/offering-batches', {'date': '1999-01-01', 'kind': '주일'}); check('회계연도가 없는 날짜는 거절', s == 422)
P = 'p:' + people[5]
s, j = cnt.call('POST', f'/offering-batches/{bid}/lines', {'accountId': 2, 'donor': P, 'amount': 100000, 'method': '현금'}); check('십일조 입력(교인)', s == 200, j); lid = j['data']['id']
s, j = cnt.call('POST', f'/offering-batches/{bid}/lines', {'accountId': 2, 'donor': P, 'amount': 1.5, 'method': '현금'}); check('소수점 금액 거절', s == 422)
s, j = cnt.call('POST', f'/offering-batches/{bid}/lines', {'accountId': 4, 'donor': P, 'amount': 5000, 'method': '현금'}); check('주일헌금(무기명)에 이름 입력 거절', s == 422)
s, j = cnt.call('POST', f'/offering-batches/{bid}/lines', {'accountId': 4, 'amount': 300000, 'method': '현금'}); check('주일헌금 합계 입력', s == 200, j)
s, j = cnt.call('POST', f'/offering-batches/{bid}/lines', {'accountId': 15, 'donor': P, 'amount': 20000, 'method': '현금'}); check('부서헌금은 부서가 필요', s == 422)
s, j = cnt.call('POST', f'/offering-batches/{bid}/lines', {'accountId': 16, 'donor': P, 'amount': 20000, 'method': '현금'}); check('목적헌금은 목적이 필요', s == 422)
s, j = cnt.call('POST', f'/offering-batches/{bid}/lines', {'accountId': 16, 'donor': P, 'amount': 20000, 'method': '현금', 'purpose': '시험 목적'}); check('목적헌금 입력', s == 200, j)
s, j = cnt.call('POST', f'/offering-batches/{bid}/lines', {'accountId': 3, 'donor': P, 'amount': 50000, 'method': '카드'}); check('받은 방법 검증', s == 422)
s, j = cnt.call('POST', f'/offering-batches/{bid}/lines', {'accountId': 3, 'donor': P, 'amount': 50000, 'method': '계좌이체', 'memo': '시험'}); check('계좌이체 감사헌금', s == 200, j)
s, j = cnt2.call('GET', f'/offering-batches/{bid}'); check('다른 계수 위원은 내 묶음을 못 봄', s == 403)
s, j = cnt2.call('POST', f'/offering-batches/{bid}/lines', {'accountId': 2, 'amount': 1, 'method': '현금'}); check('다른 계수 위원은 줄 입력 불가', s == 403)
s, j = cnt.call('POST', f'/offering-batches/{bid}/close', {'cashWalletId': wc, 'bankWalletId': wb}); check('계수 위원은 마감 불가', s == 403)
s, j = cnt.call('POST', f'/offering-lines/{lid}', {'accountId': 2, 'donor': P, 'amount': 120000, 'method': '현금'}); check('줄 수정', s == 200, j)
s, j = cnt.call('POST', f'/offering-batches/{bid}/counted', {'counted': {'2': 100000, '4': 300000}, 'counters': '시험계수'}); check('계수 금액 저장', s == 200, j)
s, j = stf.call('POST', f'/offering-batches/{bid}/close', {'cashWalletId': wc, 'bankWalletId': wb}); check('십일조 입력(120000)이 계수(100000)와 달라 사유 없이는 마감 불가', s == 422 and code(j) == 'diff_reason', j)
s, j = stf.call('POST', f'/offering-batches/{bid}/close', {'cashWalletId': wc, 'diffReason': '시험 사유'}); check('통장 계좌를 안 고르면 거절(계좌이체 줄 있음)', s == 422 and code(j) == 'wallet', j)
s, j = stf.call('POST', f'/offering-batches/{bid}/close', {'cashWalletId': wc, 'bankWalletId': wb, 'diffReason': '시험 사유'}); check('재정 담당이 마감', s == 200, j)
tot = sql("select sum(amount) from fm_transactions where source='헌금' and source_id=%d and void_at is null" % bid)
check('마감하면 장부에 수입 합계가 올라감 (120000+300000+20000+50000=490000)', tot == '490000', tot)
check('현금·통장 계좌별로 나뉨', sql("select sum(amount) from fm_transactions where source_id=%d and wallet_id=%d" % (bid, wb)) == '50000')
s, j = cnt.call('POST', f'/offering-batches/{bid}/lines', {'accountId': 2, 'amount': 1, 'method': '현금'}); check('마감된 묶음에는 입력 불가', s == 409)
s, j = stf.call('POST', f'/offering-batches/{bid}/close', {'cashWalletId': wc, 'bankWalletId': wb}); check('이중 마감 불가', s == 409)
s, j = vw.call('GET', f'/offering-batches/{bid}'); check('열람 역할은 합계만(이름 줄 없음)', s == 200 and j['data']['lines'] == [] and not j['data']['seeNames'], j)
s, j = vw.call('GET', '/reports/weekly?from=2026-11-02&to=2026-11-08'); it = {i['name']: i for i in j['data']['items']} if s == 200 else {}
check('주간 보고에 종류별 합계가 나옴', s == 200 and it['십일조']['sum'] == 120000 and it['주일헌금']['sum'] == 300000, j)
s, j = stf.call('GET', '/reports/bulletin?from=2026-11-02&to=2026-11-08'); check('주보용 명단(재정 담당)', s == 200 and any(g['account'] == '십일조' for g in j['data']['groups']), j)
s, j = vw.call('GET', '/reports/bulletin?from=2026-11-02&to=2026-11-08'); check('주보용 명단은 열람 역할 불가', s == 403)
s, j = stf.call('GET', '/donors/history?donor=' + quote(P) + '&from=2026-01-01&to=2026-12-31'); check('개인별 내역(열람 권한자)', s == 200 and j['data']['total'] == 120000 + 20000 + 50000, j)
s, j = vw.call('GET', '/donors/history?donor=' + quote(P)); check('개인별 내역은 열람 역할 불가', s == 403)
check('개인별 열람이 기록에 남음', int(sql("select count(*) from fm_access_log where action='개인별 헌금 열람' and who='시험담당'")) >= 1)

s, j = stf.call('POST', f'/offering-batches/{bid}/reopen', {}); check('다시 열기는 사유 필수', s == 422)
s, j = cnt.call('POST', f'/offering-batches/{bid}/reopen', {'reason': 'x'}); check('계수 위원은 다시 열기 불가', s == 403)
s, j = stf.call('POST', f'/offering-batches/{bid}/reopen', {'reason': '시험 정정'}); check('재정 담당이 다시 열기', s == 200, j)
check('다시 열면 장부 수입이 취소됨', sql("select count(*) from fm_transactions where source='헌금' and source_id=%d and void_at is null" % bid) == '0')
s, j = stf.call('POST', f'/offering-batches/{bid}/close', {'cashWalletId': wc, 'bankWalletId': wb, 'diffReason': '시험'}); check('다시 마감', s == 200, j)
sql("insert into fm_closings (period, balances, note) values ('2026-11','{}','시험')")
s, j = stf.call('POST', f'/offering-batches/{bid}/reopen', {'reason': '시험'}); check('월 마감된 달은 다시 열 수 없음', s == 409 and code(j) == 'month_closed', j)
s, j = cnt.call('POST', '/offering-batches', {'date': DATE, 'kind': '수요예배'}); check('월 마감된 달에는 새 묶음도 못 만듦', s == 409)
sql("delete from fm_closings where period='2026-11'")
s, j = stf.call('GET', '/donors/search?q=' + quote(sql("select name from cm_people where id='%s'" % people[5])[:2])); check('헌금자 찾기', s == 200 and len(j['data']['items']) > 0, j)

wipe()
finish()
