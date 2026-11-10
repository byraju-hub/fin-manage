# fin-manage
교회 재정 관리 시스템 (설계·디자인 단계)

헌금(개인별 기록)과 기부금 영수증, 예산, 장부, 지출결의서 전자결재를 다루는 교회 재정 프로그램입니다. 교적 관리 [ch-manage](https://github.com/byraju-hub/ch-manage)(`cm_`), 부서 관리 [dep-manage](https://github.com/byraju-hub/dep-manage)(`dm_`)와 같은 호스팅 계정·같은 DB에 설치해 함께 쓰며, 표 이름 접두어는 `fm_` 입니다. 교적 계정으로 로그인하고, 재정 사용을 승인받은 사람만 쓸 수 있습니다.

> 지금은 **설계와 디자인 시안**만 있고 코드는 아직 없습니다.

- 구조 설계: [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md)
- 디자인 시안: [design/index.html](design/index.html) (브라우저로 열면 됩니다. 이름과 금액은 모두 예시)
- 라이선스: [GNU AGPL-3.0](LICENSE)
