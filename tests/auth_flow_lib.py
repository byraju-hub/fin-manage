"""시험 공용 도우미 (auth_flow.py 와 같은 내용)."""
import json, subprocess, sys, urllib.request, urllib.error, http.cookiejar

A = 'http://127.0.0.1:8091/api/v1'
MYSQL = r'C:\xampp\mysql\bin\mysql.exe'
PW = 'testpass1234'

def sql(q): return subprocess.run([MYSQL, '-uroot', '--default-character-set=utf8mb4', 'church_dev', '-N'], input=q, capture_output=True, text=True, encoding='utf-8').stdout.strip()  # 한글이 깨지지 않게 표준입력으로 보냄

class Client:
    def __init__(s):
        s.cj = http.cookiejar.CookieJar(); s.op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(s.cj)); s.csrf = ''
    def call(s, method, path, body=None, headers=None, csrf=True):
        h = {'Content-Type': 'application/json', **(headers or {})}
        if csrf and method != 'GET': h['X-CSRF-Token'] = s.csrf
        req = urllib.request.Request(A + path, json.dumps(body).encode() if body is not None else None, h, method=method)
        try: r = s.op.open(req); status = r.status; data = r.read()
        except urllib.error.HTTPError as e: status = e.code; data = e.read()
        j = json.loads(data)
        if path in ('/auth/me', '/auth/login') and j.get('ok'): s.csrf = j['data']['csrf']
        return status, j
    def login(s, user, pw=PW):
        s.call('GET', '/auth/me'); return s.call('POST', '/auth/login', {'loginId': user, 'password': pw})



