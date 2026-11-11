# ch-manage(교적 관리)에서 해야 할 작업 — fin-manage 연동용

> 이 문서를 **ch-manage 저장소의 Claude 세션에 그대로 붙여넣어** 진행하세요.
> fin-manage(재정)는 교적(`cm_`)을 읽기만 합니다. 아래 작업은 모두 ch-manage 쪽에서 합니다.
> 재정 쪽 받는 코드는 이미 만들어져 있고(`POST /fm/api/v1/auth/sso`), 시험도 통과했습니다(`tests/m11_sso.py`).
> 관련 설계: fin-manage `docs/ARCHITECTURE.md` 3.4절, M1-b 단계.

## 작업 A. '재정 열기' (통합 로그인 바로가기)

### 목적
교적에 로그인한 사용자가 버튼 한 번으로 재정에 다시 로그인하지 않고 들어가게 한다. 두 프로그램은 같은 도메인의 다른 폴더(`/cm`, `/fm`)에 있고 세션 쿠키가 서로 다른 이름·경로라서(`fm_sid`는 `/fm`에만 감) 세션을 공유할 수 없다. 그래서 **1회용 입장권**을 쓴다.

### 흐름
1. 교적 화면의 '재정 열기' 버튼을 누른다.
2. 교적 서버가 무작위 입장권(예: `random_bytes(32)` → base64url, 43자)을 만들고, **해시(sha256)만** `cm_sso_tickets`에 저장한다(원문은 DB에 두지 않는다). 유효 30초.
3. 교적이 입장권 원문을 담아 `/fm/sso?t=<입장권>` 으로 이동시킨다(새 탭 `window.open` 또는 같은 탭 이동).
4. 재정 화면이 `POST /fm/api/v1/auth/sso {ticket}` 를 호출한다. 재정 서버는 해시로 조회해 유효하면 **그 행을 지우고**(재정이 교적 표에 쓰는 유일한 경우) 자기 세션을 만든다.

### 1. 마이그레이션 `007_sso_tickets.php`
(현재 마지막 번호가 006 `session_epoch` 이므로 007. 이미 설치된 곳은 업데이트 때 자동 적용되는 기존 방식을 그대로 따른다.)

```sql
CREATE TABLE `{p}sso_tickets` (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    token_hash CHAR(64) NOT NULL,                 -- sha256(입장권 원문) 의 16진수
    user_id INT UNSIGNED NOT NULL,                -- {p}users.id  (재정은 외래키 없이 읽는다. 교적 안에서는 FK 를 걸어도 됨 — ON DELETE CASCADE)
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_token (token_hash),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```
- **열 이름과 표 이름을 정확히 이 모양으로** 만든다. 재정은 `SELECT id, user_id FROM {cm_prefix}sso_tickets WHERE token_hash = ? AND expires_at > NOW() FOR UPDATE` 로 읽고 `DELETE FROM {cm_prefix}sso_tickets WHERE id = ?` 로 지운다.
- 구조 번호(schema_version)를 7로 올린다. fin-manage 의 `FM_MIN_CM_SCHEMA` 는 6 그대로여도 되고(표가 없으면 재정이 404를 돌려주고 일반 로그인을 안내), 7로 올리고 싶으면 fin-manage 쪽에서 알려 달라.

### 2. API: `POST /api/v1/auth/sso-ticket`
- **로그인한 교적 세션에서만** (기존 인증·CSRF 검사 그대로). 체험(`is_demo`)·정지·승인 안 된 계정은 거절.
- **재정 사용 자격을 교적이 판단하지 않는다.** 입장권은 "이 교적 계정으로 재정에 들어가도 되는지 재정이 따로 판단한다"는 전제로 발급한다(재정 승인 여부는 재정이 확인).
- 처리: `$t = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=')` → `INSERT ... token_hash = hash('sha256', $t), user_id, expires_at = NOW() + INTERVAL 30 SECOND` → `{ticket: $t}` 응답.
- 사용자당 분당 발급 횟수 제한(예: 10회), 만료된 행 정리(발급할 때 `DELETE ... WHERE expires_at < NOW() - INTERVAL 1 DAY`).
- 응답은 캐시 금지 헤더. 입장권을 로그·오류 메시지에 남기지 않는다.

### 3. 화면: '재정 열기' 버튼
- 교적 상단 메뉴(또는 사용자 메뉴)에 '재정 열기'. 재정이 **같은 DB에 설치되어 있을 때만** 보이게 한다(`information_schema`로 `fm_` 접두어의 `fm_meta` 표 존재 확인 — 접두어를 모르면 `fm_`를 기본값으로 하고 설정에서 바꿀 수 있게).
- 누르면 `POST /auth/sso-ticket` → 받은 입장권으로 `window.location = <재정 주소>/sso?t=<입장권>`.
- **재정 주소**: 교적 사이트 폴더가 `/cm` 이고 재정이 `/fm` 이므로 기본은 같은 도메인의 `../fm/` (교적 `hrefTo` 규칙으로 계산: 사이트 폴더의 형제 폴더 `fm`). 폴더 이름이 다르면 설정에서 지정할 수 있게 한다(설정 JSON 에 `finance_path`, 기본 `fm`).
- 재정이 아직 이 기능을 지원하지 않거나 입장권이 거절되면 재정 화면의 로그인 창이 그대로 열린다(오류 메시지가 로그인 화면에 보임). 교적은 따로 처리할 것이 없다.

### 4. 시험
- 새 입장권으로 `/fm/sso?t=...` → 재정에 로그인됨(이미 재정 사용 승인된 계정).
- 같은 입장권 재사용 → 실패(`invalid_ticket`).
- 31초 뒤 → 실패.
- 정지된 교적 계정 → 입장권 발급 자체가 거절.
- 다른 사용자의 입장권으로 이 사용자가 재정에 못 들어간다(입장권이 사용자에 묶임).
- 참고: fin-manage `tests/m11_sso.py` 가 같은 표를 임시로 만들어 재정 쪽을 시험한다.

## 작업 B. 교적의 '문서 › 기부금 영수증' 화면 안내

- 현재 교적에는 기부금 영수증 금액을 **손으로 적는** 화면이 있다. 재정이 설치되어 있으면(같은 DB의 `fm_meta` 존재) 이 화면 위에 안내를 띄운다:
  "재정 관리가 설치되어 있어요. 기부금 영수증은 **재정 › 기부금 영수증**에서 헌금 기록으로 한꺼번에 발급할 수 있어요." + 재정 열기 링크(작업 A의 버튼과 같은 동작).
- **교적에는 헌금 금액을 가져오거나 보여 주지 않는다**(교적 사용자에게 개인별 헌금이 노출되면 안 되므로). 안내와 링크만.
- 교적의 증명서 대장(`cm_certificates`)과 재정의 영수증 번호(`fm_meta.receipt_seq_<연도>`)는 **서로 섞지 않는다**. 건드리지 않는다.

## 작업 C. Windows 개발 환경 쿠키 경로 버그 (4줄 수정)

- 증상: Windows 의 PHP 에서 `dirname(dirname('/api/index.php'))` 가 `\` 를 돌려줘, 세션·설치 쿠키의 `path` 가 `\` 가 되고 브라우저가 쿠키를 버린다. **운영(Linux)에서는 나타나지 않고 Windows 로컬 개발에서만** 로그인·설치가 안 된다.
- 해결: 사이트 폴더 경로를 계산하는 곳(세션 쿠키 경로, 설치 마법사의 쿠키 경로·`api` 기준 경로 등 `dirname()` 으로 base 를 구하는 모든 곳)에서 결과의 `\` 를 `/` 로 바꾼다. 예: `str_replace('\\', '/', dirname(...))` 후 `rtrim($x, '/')`, 결과가 `.`이면 빈 문자열.
- fin-manage 는 `server/api/lib/paths.php` 의 `fm_dirname()` / `fm_site_base()` 로 이미 처리했다(참고용으로 가져다 써도 된다).
- 참고로 같은 이유로 세션 쿠키 이름이 겹치면(`/cm` 와 `/fm`) 문제가 될 수 있으니 교적의 세션 쿠키 이름이 `fm_sid` 가 아니라는 것만 확인한다(재정은 `fm_sid`, 경로 `/fm`).

## 하지 말 것
- 재정의 표(`fm_`)를 교적에서 읽거나 쓰지 않는다(버튼 노출 여부를 알아보는 `fm_meta` 존재 확인만 예외).
- 입장권 원문을 DB·로그에 저장하지 않는다. 만료 30초, 1회용을 늘리지 않는다.
- 교적 계정의 비밀번호·해시를 재정으로 넘기는 방식은 쓰지 않는다. (재정은 필요하면 교적의 해시를 읽기만 해서 비밀번호 로그인을 직접 검증한다.)

## 끝났는지 확인하는 기준
교적에 로그인한 상태에서 '재정 열기'를 누르면 재정 첫 화면이 비밀번호 없이 열리고, 같은 주소를 다시 열면 재정 로그인 창이 나온다. 재정 사용이 승인되지 않은 계정은 재정의 '재정 사용 신청' 화면이 나온다.

## 참고: 재정 → 교적 방향 링크는 재정에 이미 있음
재정 상단의 **바로가기 ▾** 메뉴가 같은 도메인의 형제 폴더(기본 `/cm/`, 부서 관리 `/dm/`는 설정에서 켬)로 이동시킨다. 폴더 이름은 재정의 `설정 › 교회 정보·영수증·보안 › 다른 프로그램 바로가기`에서 바꾼다. 교적 쪽에는 반대 방향인 '재정 열기'(작업 A)만 만들면 된다. dep-manage(`/dm`)에도 같은 버튼을 두고 싶으면 작업 A의 입장권 방식을 그대로 쓰면 된다(입장권 표는 교적 것을 공유하거나 부서 쪽에 같은 모양으로 만든다).
