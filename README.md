# fin-manage
교회 재정 관리 시스템

헌금(개인별 기록)과 기부금 영수증, 예산, 장부, 지출결의서 전자결재를 다루는 교회 재정 프로그램입니다. 교적 관리 [ch-manage](https://github.com/byraju-hub/ch-manage)(`cm_`), 부서 관리 [dep-manage](https://github.com/byraju-hub/dep-manage)(`dm_`)와 같은 호스팅 계정·같은 DB에 설치해 함께 쓰며, 표 이름 접두어는 `fm_` 입니다. **교적 계정으로 로그인**하고, 재정 사용을 승인받은 사람만 쓸 수 있습니다. 무료로 배포합니다.

> 개발 중입니다. 지금은 **M0(뼈대·설치 마법사·통합 로그인)** 까지 되어 있습니다. 헌금·예산·결재 화면은 단계마다 채워집니다. ([개발 단계](docs/ARCHITECTURE.md#14-개발-단계))

- 구조 설계: [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md)
- 디자인 시안: [design/index.html](design/index.html) (브라우저로 열면 됩니다. 이름과 금액은 모두 예시)
- 라이선스: [GNU AGPL-3.0](LICENSE)

## 지금 되는 것 (M0)

- **설치 마법사** (`/install/`): 환경 점검 → 데이터베이스(교적과 같은 DB) → **교적 감지**(없거나 구조가 낮으면 멈춤) → **주민등록번호 방식 선택**(암호화 보관 / 보관하지 않음, 반드시 고름) → 교적 최고관리자로 본인 확인 → 끝. 표 28개를 만듭니다.
- **통합 로그인**: 교적(`cm_users`)의 아이디·비밀번호로 로그인하고, 재정 사용 신청 → 재정 관리자 승인을 거쳐야 쓸 수 있습니다. 교적에서 정지하거나 비밀번호를 바꾸면 재정 로그인도 바로 끊깁니다. 상태 6가지(사용·승인 대기·신청 전·정지·교인 연결 없음·비밀번호 변경 필요)를 화면이 안내합니다.
- **첫 화면**: 교적 연결 상태와 교인 수.

## 구조

```
src/      SvelteKit 정적 앱 (adapter-static). 서버의 PHP API(/api/v1)와 이야기합니다.
server/   PHP API, 설치 마법사, 데이터베이스 구조(migrations). 사이트 폴더에 그대로 올립니다.
docs/     설계 문서         design/  화면 시안(정적 HTML)         tests/  통합 시험
```

## 설치해서 쓰기

[Releases](https://github.com/byraju-hub/fin-manage/releases)에서 `fin-manage-vX.Y.Z.zip` 을 받아 압축을 풀고, 안의 내용을 호스팅 사이트 폴더(예: `www/fm`)에 올린 뒤 `/install/` 을 엽니다. **교적 관리(ch-manage)가 같은 데이터베이스에 먼저 설치되어 있어야 합니다.**

서버 요구사항: PHP 8.1 이상 (`pdo_mysql`·`mbstring` 필요, `openssl`·`gd` 권장), MariaDB 10.x / MySQL, Apache mod_rewrite, ch-manage v0.1.0 이상(구조 번호 6 이상).

## 개발

교적이 같은 DB에 설치되어 있어야 하므로, 개발용 DB에 ch-manage를 먼저 설치하고 샘플 데이터를 넣어 둡니다.

```
npm install
php -S 127.0.0.1:8091 -t server server/dev-router.php   # PHP API + 설치 마법사 (http://127.0.0.1:8091/install/)
npm run dev                                              # 화면 (http://127.0.0.1:5174) — /api 는 위 PHP 서버로 넘어갑니다
python tests/auth_flow.py                                # 통합 로그인 흐름 시험 (개발 DB 사용)
npm run check                                            # 화면 타입 검사
MSYS_NO_PATHCONV=1 bash scripts/build-release.sh         # 배포용 zip (dist/)
```

지킬 것:
- 내부 링크는 `href="/…"` 가 아니라 `hrefTo('/…')` (`src/lib/paths.ts`)를 거칩니다. 서브폴더에 올려도 주소가 맞아야 하기 때문입니다.
- PHP에서 사이트 폴더 주소는 `dirname()` 을 직접 쓰지 말고 `fm_site_base()` / `fm_dirname()` 을 씁니다. (Windows 의 PHP 는 `dirname('/api')` 가 `\` 를 돌려줘 쿠키 경로가 깨집니다.)
- 교적(`cm_`) 표는 읽기만 합니다. 교인·사용자는 `person_id`/`cm_user_id` 로만 가리키고 외래키를 걸지 않으며, 이름을 함께 적어 둡니다.

---

**제작: [byraju](https://github.com/byraju-hub)**
