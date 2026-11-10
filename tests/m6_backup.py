"""M6 시험: 백업 내려받기와 되살리기(조각 업로드)."""
import json, os, urllib.request
from testkit import *  # noqa

wipe()
U, people = make_users({'t_admin': ('시험관리자', [], 1, 0, 0), 't_staff': ('시험담당', ['재정 담당'], 0, 1, 1)})
adm, stf = U['t_admin'], U['t_staff']
PW = 'testpass1234'
s, j = stf.call('GET', '/backup/info'); check('관리자가 아니면 백업 정보 못 봄', s == 403)
s, j = adm.call('GET', '/backup/info'); check('백업 정보(표별 건수)', s == 200 and 'funds' in j['data']['tables'], j)
try:
    adm.op.open(urllib.request.Request('http://127.0.0.1:8091/api/v1/backup/download')); check('비밀번호 재확인 없이는 내려받기 불가', False)
except urllib.error.HTTPError as e:
    check('비밀번호 재확인 없이는 내려받기 불가', e.code == 403)
adm.call('POST', '/auth/reconfirm', {'password': PW})
stf.call('POST', '/funds', {'name': '시험회계'})
raw = adm.op.open(urllib.request.Request('http://127.0.0.1:8091/api/v1/backup/download')).read().decode('utf-8')
d = json.loads(raw)
check('백업 파일 형식과 내용(시험회계 포함, 열쇠·교적 자료 없음)', d['format'] == 'fin-manage-backup' and any(f['name'] == '시험회계' for f in d['tables']['funds']) and 'login_attempts' not in d['tables'] and 'rrn_key' not in raw)
sql("delete from fm_funds where name='시험회계'")
check('지운 상태', sql("select count(*) from fm_funds where name='시험회계'") == '0')
cid = os.urandom(8).hex()
chunks = [raw[i:i + 60000] for i in range(0, len(raw), 60000)]
for i, c in enumerate(chunks):
    s, j = adm.call('POST', '/backup/restore/chunk', {'id': cid, 'i': i, 'data': c}); assert s == 200, j
s, j = adm.call('POST', '/backup/restore/apply', {'id': cid, 'n': len(chunks), 'confirm': '네'}); check('확인 문구가 없으면 거절', s == 422 and code(j) == 'confirm', j)
s, j = stf.call('POST', '/backup/restore/apply', {'id': cid, 'n': len(chunks), 'confirm': '되살리기'}); check('관리자가 아니면 되살리기 불가', s == 403)
s, j = adm.call('POST', '/backup/restore/apply', {'id': cid, 'n': len(chunks) + 1, 'confirm': '되살리기'}); check('조각이 모자라면 거절', s == 422 and code(j) == 'missing', j)
s, j = adm.call('POST', '/backup/restore/apply', {'id': cid, 'n': len(chunks), 'confirm': '되살리기'}); check('되살리기', s == 200, j)
check('지웠던 회계가 되살아남', sql("select count(*) from fm_funds where name='시험회계'") == '1')
check('되살리기 전 상태가 파일로 남음', len([f for f in os.listdir(os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'server', 'data', 'backups')) if f.startswith('before-restore')]) >= 1)
bad = json.dumps({'format': 'x'})
adm.call('POST', '/auth/reconfirm', {'password': PW})
adm.call('POST', '/backup/restore/chunk', {'id': cid[::-1], 'i': 0, 'data': bad})
s, j = adm.call('POST', '/backup/restore/apply', {'id': cid[::-1], 'n': 1, 'confirm': '되살리기'}); check('재정 백업 파일이 아니면 거절', s == 422, j)
wipe()
bk = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'server', 'data', 'backups')
for f in os.listdir(bk):
    if f.startswith('before-restore'): os.remove(os.path.join(bk, f))
finish()
