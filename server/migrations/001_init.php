<?php
declare(strict_types=1);

// 첫 번째 데이터베이스 구조. {p} 는 표 이름 접두어(기본 fm_)로 바뀐다.
// 이미 설치된 곳을 바꿀 때는 이 파일을 고치지 말고 002_..., 003_... 파일을 새로 만든다.
//
// 설계: docs/ARCHITECTURE.md 11장.
//  - 돈은 모두 원 단위 정수(BIGINT). 소수점을 쓰지 않는다.
//  - 교적(cm_)의 교인·사용자·조직은 person_id / cm_user_id 로만 가리킨다. 외래키는 걸지 않는다.
//    (교적에서 교인을 지우거나 되살려도 재정 기록이 막히거나 지워지면 안 되므로, 이름을 함께 적어 둔다.)
//  - 고정된 목록(상태·종류)은 DB 의 ENUM 이 아니라 PHP 에서 확인한다.
//  - 표를 만드는 순서가 곧 외래키 순서다. (참조되는 표를 먼저 만든다.)

$ENGINE = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

return [
    // ===== 시스템 =====
    "CREATE TABLE `{p}meta` (
        k VARCHAR(40) NOT NULL PRIMARY KEY,
        v TEXT NOT NULL
    ) $ENGINE",
    "INSERT INTO `{p}meta` (k, v) VALUES ('data_version', '1')",

    // 한 줄짜리 설정. rrn_mode: keep(암호화 보관) / none(보관하지 않음) — 설치할 때 반드시 고른다.
    "CREATE TABLE `{p}settings` (
        id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
        church JSON NOT NULL,                       -- 교회 이름·고유번호·주소·대표자 (설치 때 교적에서 복사)
        rrn_mode VARCHAR(10) NOT NULL,
        logo_path VARCHAR(255) NULL,
        seal_path VARCHAR(255) NULL,                -- 직인: 영수증을 만들 때만 서버가 꺼냄
        security JSON NOT NULL,                     -- 중요 동작 전 비밀번호 재확인 등
        direct_rules JSON NOT NULL,                 -- 직접 지출 공통 설정 (등록 가능 역할·월 한도·증빙 필수·마감 확인)
        receipt_form JSON NOT NULL,                 -- 영수증 서식 (내용 줄 방식·가족 합산 허용)
        backup JSON NOT NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        updated_by INT UNSIGNED NULL,
        CHECK (id = 1)
    ) $ENGINE",

    // ===== 재정 사용 승인 (계정은 교적 cm_users 가 주인, 여기서는 승인과 역할만) =====
    "CREATE TABLE `{p}access` (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        cm_user_id INT UNSIGNED NOT NULL,           -- 교적 계정 번호. 외래키 없음
        login_id VARCHAR(50) NOT NULL,              -- 교적 백업을 되살려 번호가 바뀌어도 다시 이을 수 있게 적어 둠
        name VARCHAR(50) NOT NULL,
        person_id CHAR(36) NULL,                    -- 교적에서 교인과 연결한 값의 스냅숏
        status VARCHAR(10) NOT NULL DEFAULT '신청 대기',   -- 신청 대기·사용·정지
        is_admin TINYINT(1) NOT NULL DEFAULT 0,     -- 재정 관리자
        can_view_donors TINYINT(1) NOT NULL DEFAULT 0,     -- 개인별 헌금 열람
        reason VARCHAR(255) NOT NULL DEFAULT '',
        requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        decided_at DATETIME NULL,
        decided_by INT UNSIGNED NULL,
        last_login_at DATETIME NULL,
        UNIQUE KEY uniq_cm_user (cm_user_id),
        INDEX idx_login (login_id),
        INDEX idx_person (person_id)
    ) $ENGINE",
    "CREATE TABLE `{p}access_roles` (
        access_id INT UNSIGNED NOT NULL,
        role VARCHAR(20) NOT NULL,                  -- 재정 담당·재정위원장·담임목사·계수 위원·감사·열람
        PRIMARY KEY (access_id, role),
        CONSTRAINT fk_{p}ar_access FOREIGN KEY (access_id) REFERENCES `{p}access`(id) ON DELETE CASCADE
    ) $ENGINE",
    "CREATE TABLE `{p}login_attempts` (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        login_id VARCHAR(50) NOT NULL,
        ip VARCHAR(45) NOT NULL,
        at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_login (login_id, at),
        INDEX idx_ip (ip, at)
    ) $ENGINE",

    // ===== 기준: 회계연도 · 회계 · 계좌 · 수입 항목 =====
    "CREATE TABLE `{p}fiscal_years` (
        year SMALLINT UNSIGNED NOT NULL PRIMARY KEY,
        start_date DATE NOT NULL,
        end_date DATE NOT NULL,
        status VARCHAR(10) NOT NULL DEFAULT '편성 중',   -- 편성 중·진행·마감
        budget_confirmed_on DATE NULL                   -- 공동의회에서 예산을 확정한 날
    ) $ENGINE",
    "CREATE TABLE `{p}funds` (                      -- 회계: 기본은 일반회계 하나, 건축·선교 같은 특별회계를 더할 수 있다
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(40) NOT NULL,
        is_default TINYINT(1) NOT NULL DEFAULT 0,
        sort_order INT NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        UNIQUE KEY uniq_name (name)
    ) $ENGINE",
    "CREATE TABLE `{p}wallets` (                    -- 계좌: 현금, 통장
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        fund_id INT UNSIGNED NOT NULL,
        name VARCHAR(40) NOT NULL,
        kind VARCHAR(10) NOT NULL DEFAULT '통장',       -- 통장·현금
        bank VARCHAR(30) NULL,
        acct_tail VARCHAR(10) NULL,                     -- 계좌번호 끝 몇 자리만 (전체 번호는 두지 않는다)
        opening_balance BIGINT NOT NULL DEFAULT 0,
        sort_order INT NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        CONSTRAINT fk_{p}wl_fund FOREIGN KEY (fund_id) REFERENCES `{p}funds`(id)
    ) $ENGINE",
    "CREATE TABLE `{p}income_accounts` (            -- 수입 항목 (최대 3단계)
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        parent_id INT UNSIGNED NULL,
        name VARCHAR(40) NOT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        is_offering TINYINT(1) NOT NULL DEFAULT 0,      -- 헌금 입력 화면에 나옴
        named TINYINT(1) NOT NULL DEFAULT 1,            -- 이름을 적는 헌금인가 (주일헌금은 무기명 합계)
        receiptable TINYINT(1) NOT NULL DEFAULT 0,      -- 기부금 영수증에 포함
        fund_id INT UNSIGNED NULL,                      -- 들어갈 회계 (비면 일반회계)
        needs_unit TINYINT(1) NOT NULL DEFAULT 0,       -- 부서헌금: 어느 부서인지 함께 받음
        needs_purpose TINYINT(1) NOT NULL DEFAULT 0,    -- 목적헌금: 목적을 적음
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        CONSTRAINT fk_{p}ia_parent FOREIGN KEY (parent_id) REFERENCES `{p}income_accounts`(id),
        CONSTRAINT fk_{p}ia_fund FOREIGN KEY (fund_id) REFERENCES `{p}funds`(id)
    ) $ENGINE",

    // ===== 재정 단위 · 담당자 · 대리 결재 · 결재 규칙 =====
    "CREATE TABLE `{p}units` (                      -- 해마다 한 벌. 교적 조직표에서 가져오거나 작년 것을 복사
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        fiscal_year SMALLINT UNSIGNED NOT NULL,
        parent_id INT UNSIGNED NULL,
        kind VARCHAR(10) NOT NULL,                      -- 위원회·부서·기타·예비비
        name VARCHAR(60) NOT NULL,
        cm_org_unit_id CHAR(36) NULL,                   -- 교적 조직표의 단위 (외래키 없음)
        sort_order INT NOT NULL DEFAULT 0,
        INDEX idx_year (fiscal_year),
        CONSTRAINT fk_{p}un_year FOREIGN KEY (fiscal_year) REFERENCES `{p}fiscal_years`(year),
        CONSTRAINT fk_{p}un_parent FOREIGN KEY (parent_id) REFERENCES `{p}units`(id) ON DELETE CASCADE
    ) $ENGINE",
    "CREATE TABLE `{p}unit_roles` (                 -- 부서 회계·부서장·위원장 (교인 목록에서 지정)
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        unit_id INT UNSIGNED NOT NULL,
        role VARCHAR(10) NOT NULL,                      -- 회계·부서장·위원장
        person_id CHAR(36) NOT NULL,
        person_name VARCHAR(50) NOT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        UNIQUE KEY uniq_role (unit_id, role, person_id),
        INDEX idx_person (person_id),
        CONSTRAINT fk_{p}ur_unit FOREIGN KEY (unit_id) REFERENCES `{p}units`(id) ON DELETE CASCADE
    ) $ENGINE",
    "CREATE TABLE `{p}delegations` (                -- 대리 결재: 기간 동안 누구 대신 누가 결재하는가
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        from_person_id CHAR(36) NOT NULL,
        from_name VARCHAR(50) NOT NULL,
        to_person_id CHAR(36) NOT NULL,
        to_name VARCHAR(50) NOT NULL,
        starts_on DATE NOT NULL,
        ends_on DATE NOT NULL,
        created_by INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_from (from_person_id, starts_on, ends_on),
        INDEX idx_to (to_person_id)
    ) $ENGINE",
    "CREATE TABLE `{p}approval_rules` (             -- 금액 구간별 결재선. 금액 기준은 관리자가 설정한다
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        kind VARCHAR(20) NOT NULL DEFAULT '일반',       -- 일반 · 직속 · 사례비 등 지출 종류별 규칙표
        name VARCHAR(30) NOT NULL,
        min_amount BIGINT NOT NULL DEFAULT 0,           -- 이 금액 이상
        max_amount BIGINT NULL,                         -- 이 금액 이하 (NULL 이면 위로 끝없이)
        steps JSON NOT NULL,                            -- [{role, mode: 결재|확인}]
        add_on JSON NOT NULL,                           -- 예산 초과·예비비일 때 더할 단계
        sort_order INT NOT NULL DEFAULT 0
    ) $ENGINE",

    // ===== 예산 =====
    "CREATE TABLE `{p}budget_items` (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        unit_id INT UNSIGNED NOT NULL,
        name VARCHAR(60) NOT NULL,
        amount BIGINT NOT NULL DEFAULT 0,               -- 처음 예산. 확정 뒤에는 budget_changes 로만 바뀐다
        basis VARCHAR(255) NULL,                        -- 산출 근거
        nature VARCHAR(20) NULL,                        -- 인건비·행사비·비품 … 성격 꼬리표(선택)
        direct_ok TINYINT(1) NOT NULL DEFAULT 0,        -- 직접 지출 허용 (재정 관리자가 항목마다 지정)
        direct_limit BIGINT NULL,                       -- 직접 지출 건당 한도 (NULL 이면 한도 없음)
        sort_order INT NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        INDEX idx_unit (unit_id),
        CONSTRAINT fk_{p}bi_unit FOREIGN KEY (unit_id) REFERENCES `{p}units`(id) ON DELETE CASCADE
    ) $ENGINE",
    "CREATE TABLE `{p}income_budgets` (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        fiscal_year SMALLINT UNSIGNED NOT NULL,
        income_account_id INT UNSIGNED NOT NULL,
        amount BIGINT NOT NULL DEFAULT 0,
        UNIQUE KEY uniq_acct (fiscal_year, income_account_id),
        CONSTRAINT fk_{p}ib_year FOREIGN KEY (fiscal_year) REFERENCES `{p}fiscal_years`(year),
        CONSTRAINT fk_{p}ib_acct FOREIGN KEY (income_account_id) REFERENCES `{p}income_accounts`(id)
    ) $ENGINE",
    "CREATE TABLE `{p}budget_changes` (             -- 추경·전용
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        fiscal_year SMALLINT UNSIGNED NOT NULL,
        from_item_id INT UNSIGNED NULL,                 -- 줄이는 항목 (추경이면 NULL)
        to_item_id INT UNSIGNED NULL,                   -- 늘리는 항목
        amount BIGINT NOT NULL,
        reason VARCHAR(255) NOT NULL,
        decided_on DATE NULL,                           -- 의결한 날
        by_user_id INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_year (fiscal_year),
        CONSTRAINT fk_{p}bc_from FOREIGN KEY (from_item_id) REFERENCES `{p}budget_items`(id),
        CONSTRAINT fk_{p}bc_to FOREIGN KEY (to_item_id) REFERENCES `{p}budget_items`(id)
    ) $ENGINE",

    // ===== 헌금 =====
    "CREATE TABLE `{p}donors` (                     -- 헌금자: 교인이면 person_id 로 교적과 이어짐
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        person_id CHAR(36) NULL,
        name VARCHAR(50) NOT NULL,                      -- 이름 스냅숏 (교적에서 지워져도 남음)
        donor_no VARCHAR(20) NULL,                      -- 헌금자 번호(봉투 번호). 쓰는 교회만
        rrn_enc VARCHAR(255) NULL,                      -- 주민등록번호 암호문. rrn_mode 가 keep 일 때만
        receipt_to_donor_id INT UNSIGNED NULL,          -- 영수증을 합산해 발급할 대상 (비면 본인 명의)
        receipt_address VARCHAR(255) NULL,              -- 비면 교적의 주소
        memo VARCHAR(255) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_person (person_id),
        UNIQUE KEY uniq_no (donor_no),
        INDEX idx_name (name),
        CONSTRAINT fk_{p}dn_to FOREIGN KEY (receipt_to_donor_id) REFERENCES `{p}donors`(id) ON DELETE SET NULL
    ) $ENGINE",
    "CREATE TABLE `{p}offering_batches` (           -- 헌금 묶음: 날짜 + 구분. 마감하면 장부에 올라간다
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        fiscal_year SMALLINT UNSIGNED NOT NULL,
        batch_date DATE NOT NULL,
        kind VARCHAR(20) NOT NULL DEFAULT '주일예배',
        status VARCHAR(10) NOT NULL DEFAULT '입력 중',   -- 입력 중·마감
        counters VARCHAR(255) NULL,                     -- 계수 위원 이름
        counted JSON NULL,                              -- 종류별 실제 센 금액 {수입항목id: 금액}
        diff_reason VARCHAR(255) NULL,                  -- 입력과 계수 금액이 다를 때 사유
        reopened_count INT NOT NULL DEFAULT 0,
        created_by INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        closed_at DATETIME NULL,
        closed_by INT UNSIGNED NULL,
        INDEX idx_date (batch_date),
        CONSTRAINT fk_{p}ob_year FOREIGN KEY (fiscal_year) REFERENCES `{p}fiscal_years`(year)
    ) $ENGINE",
    "CREATE TABLE `{p}offerings` (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        batch_id INT UNSIGNED NOT NULL,
        offering_date DATE NOT NULL,                    -- 묶음 날짜의 사본 (기부금 영수증 집계용)
        income_account_id INT UNSIGNED NOT NULL,
        donor_id INT UNSIGNED NULL,                     -- 무기명이면 NULL
        amount BIGINT NOT NULL,
        method VARCHAR(10) NOT NULL DEFAULT '현금',      -- 현금·계좌이체·온라인
        unit_id INT UNSIGNED NULL,                      -- 부서헌금의 부서
        purpose VARCHAR(100) NULL,                      -- 목적헌금의 목적
        memo VARCHAR(255) NULL,
        hide_in_bulletin TINYINT(1) NOT NULL DEFAULT 0,
        created_by INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_batch (batch_id),
        INDEX idx_donor_date (donor_id, offering_date),
        INDEX idx_date (offering_date),
        CONSTRAINT fk_{p}of_batch FOREIGN KEY (batch_id) REFERENCES `{p}offering_batches`(id),
        CONSTRAINT fk_{p}of_acct FOREIGN KEY (income_account_id) REFERENCES `{p}income_accounts`(id),
        CONSTRAINT fk_{p}of_donor FOREIGN KEY (donor_id) REFERENCES `{p}donors`(id)
    ) $ENGINE",

    // ===== 장부 · 마감 =====
    "CREATE TABLE `{p}transactions` (               -- 모든 돈의 움직임. 지우지 않고 취소(사유 필수)한다
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        fiscal_year SMALLINT UNSIGNED NOT NULL,
        tx_date DATE NOT NULL,
        kind VARCHAR(10) NOT NULL,                      -- 수입·지출·이체·이월
        fund_id INT UNSIGNED NOT NULL,
        wallet_id INT UNSIGNED NOT NULL,
        to_wallet_id INT UNSIGNED NULL,                 -- 이체: 받는 계좌
        income_account_id INT UNSIGNED NULL,
        unit_id INT UNSIGNED NULL,
        budget_item_id INT UNSIGNED NULL,
        amount BIGINT NOT NULL,
        summary VARCHAR(255) NOT NULL DEFAULT '',
        vendor VARCHAR(100) NULL,
        source VARCHAR(10) NOT NULL DEFAULT '직접',     -- 직접·헌금·결의서·이월
        source_id INT UNSIGNED NULL,
        is_direct TINYINT(1) NOT NULL DEFAULT 0,        -- 결의서 없이 직접 등록한 지출 (월 마감 때 재정위원장 확인)
        direct_checked_at DATETIME NULL,
        void_at DATETIME NULL,
        void_reason VARCHAR(255) NULL,
        void_by INT UNSIGNED NULL,
        created_by INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_date (tx_date),
        INDEX idx_wallet (wallet_id, tx_date),
        INDEX idx_item (budget_item_id),
        INDEX idx_source (source, source_id),
        CONSTRAINT fk_{p}tx_year FOREIGN KEY (fiscal_year) REFERENCES `{p}fiscal_years`(year),
        CONSTRAINT fk_{p}tx_fund FOREIGN KEY (fund_id) REFERENCES `{p}funds`(id),
        CONSTRAINT fk_{p}tx_wallet FOREIGN KEY (wallet_id) REFERENCES `{p}wallets`(id),
        CONSTRAINT fk_{p}tx_towallet FOREIGN KEY (to_wallet_id) REFERENCES `{p}wallets`(id),
        CONSTRAINT fk_{p}tx_acct FOREIGN KEY (income_account_id) REFERENCES `{p}income_accounts`(id),
        CONSTRAINT fk_{p}tx_unit FOREIGN KEY (unit_id) REFERENCES `{p}units`(id),
        CONSTRAINT fk_{p}tx_item FOREIGN KEY (budget_item_id) REFERENCES `{p}budget_items`(id)
    ) $ENGINE",
    "CREATE TABLE `{p}closings` (                   -- 월 마감(2026-03) · 연 마감(2026)
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        period VARCHAR(7) NOT NULL,
        balances JSON NOT NULL,                         -- 계좌별 {장부 잔액, 실제 잔액}
        note VARCHAR(255) NULL,
        closed_by INT UNSIGNED NULL,
        closed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        approved_by INT UNSIGNED NULL,
        approved_at DATETIME NULL,
        reopened_at DATETIME NULL,
        reopened_by INT UNSIGNED NULL,
        UNIQUE KEY uniq_period (period)
    ) $ENGINE",

    // ===== 지출결의서 · 결재 =====
    "CREATE TABLE `{p}vouchers` (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        doc_no VARCHAR(20) NULL,                        -- 2026-0153. 상신할 때 서버가 매김
        fiscal_year SMALLINT UNSIGNED NOT NULL,
        unit_id INT UNSIGNED NOT NULL,
        drafter_access_id INT UNSIGNED NOT NULL,
        drafter_person_id CHAR(36) NULL,
        drafter_name VARCHAR(50) NOT NULL,
        title VARCHAR(150) NOT NULL,
        reason TEXT NULL,
        total BIGINT NOT NULL DEFAULT 0,
        pay_method VARCHAR(10) NOT NULL DEFAULT '계좌이체',   -- 계좌이체·먼저 쓰고 청구·현금·법인카드
        pay_bank VARCHAR(30) NULL,
        pay_account VARCHAR(40) NULL,
        pay_holder VARCHAR(50) NULL,
        desired_date DATE NULL,
        no_proof_reason VARCHAR(255) NULL,              -- 증빙이 없을 때 사유
        status VARCHAR(10) NOT NULL DEFAULT '작성 중',   -- 작성 중·결재 중·결재 완료·지급 완료·반려·회수·취소
        rev INT NOT NULL DEFAULT 1,                     -- 동시에 고치는 것 막기
        round INT NOT NULL DEFAULT 1,                   -- 재상신 회차
        rule_id INT UNSIGNED NULL,                      -- 상신 때 적용된 결재 규칙
        submitted_at DATETIME NULL,
        approved_at DATETIME NULL,
        paid_at DATETIME NULL,
        paid_wallet_id INT UNSIGNED NULL,
        paid_by INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_doc_no (doc_no),
        INDEX idx_status (status),
        INDEX idx_unit (unit_id),
        INDEX idx_drafter (drafter_access_id),
        CONSTRAINT fk_{p}vc_year FOREIGN KEY (fiscal_year) REFERENCES `{p}fiscal_years`(year),
        CONSTRAINT fk_{p}vc_unit FOREIGN KEY (unit_id) REFERENCES `{p}units`(id),
        CONSTRAINT fk_{p}vc_drafter FOREIGN KEY (drafter_access_id) REFERENCES `{p}access`(id),
        CONSTRAINT fk_{p}vc_wallet FOREIGN KEY (paid_wallet_id) REFERENCES `{p}wallets`(id)
    ) $ENGINE",
    "CREATE TABLE `{p}voucher_items` (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        voucher_id INT UNSIGNED NOT NULL,
        budget_item_id INT UNSIGNED NOT NULL,
        summary VARCHAR(255) NOT NULL DEFAULT '',
        amount BIGINT NOT NULL,
        transaction_id INT UNSIGNED NULL,               -- 지급 뒤 장부 거래
        INDEX idx_voucher (voucher_id),
        INDEX idx_item (budget_item_id),
        CONSTRAINT fk_{p}vi_voucher FOREIGN KEY (voucher_id) REFERENCES `{p}vouchers`(id) ON DELETE CASCADE,
        CONSTRAINT fk_{p}vi_item FOREIGN KEY (budget_item_id) REFERENCES `{p}budget_items`(id),
        CONSTRAINT fk_{p}vi_tx FOREIGN KEY (transaction_id) REFERENCES `{p}transactions`(id) ON DELETE SET NULL
    ) $ENGINE",
    "CREATE TABLE `{p}voucher_files` (              -- 증빙: 파일은 data 폴더에 두고 PHP 가 권한을 확인한 뒤 내려준다
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        voucher_id INT UNSIGNED NOT NULL,
        path VARCHAR(255) NOT NULL,
        mime VARCHAR(40) NOT NULL,
        size INT UNSIGNED NOT NULL,
        orig_name VARCHAR(100) NULL,
        uploaded_by INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_voucher (voucher_id),
        CONSTRAINT fk_{p}vf_voucher FOREIGN KEY (voucher_id) REFERENCES `{p}vouchers`(id) ON DELETE CASCADE
    ) $ENGINE",
    "CREATE TABLE `{p}approval_steps` (             -- 상신할 때 사람 이름과 함께 고정되는 결재선
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        voucher_id INT UNSIGNED NOT NULL,
        round INT NOT NULL DEFAULT 1,
        step_no INT NOT NULL,
        role VARCHAR(10) NOT NULL,                      -- 부서장·위원장·재정위원장·담임목사 …
        approver_person_id CHAR(36) NULL,
        approver_name VARCHAR(50) NOT NULL,
        mode VARCHAR(4) NOT NULL DEFAULT '결재',         -- 결재·확인(사후 확인)
        status VARCHAR(10) NOT NULL DEFAULT '대기',      -- 대기·완료·반려·건너뜀
        acted_at DATETIME NULL,
        acted_by_access_id INT UNSIGNED NULL,
        as_delegate TINYINT(1) NOT NULL DEFAULT 0,      -- 대리 결재(대결)
        comment VARCHAR(500) NULL,
        content_hash CHAR(64) NULL,                     -- 결재하던 순간 결의서 내용의 해시
        acted_ip VARCHAR(45) NULL,
        INDEX idx_voucher (voucher_id, round, step_no),
        INDEX idx_approver (approver_person_id, status),
        CONSTRAINT fk_{p}as_voucher FOREIGN KEY (voucher_id) REFERENCES `{p}vouchers`(id) ON DELETE CASCADE
    ) $ENGINE",

    // ===== 기부금 영수증 =====
    "CREATE TABLE `{p}receipts` (                   -- 발급 대장: 발급 당시 내용을 그대로 남긴다
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        doc_no VARCHAR(20) NOT NULL,
        tax_year SMALLINT UNSIGNED NOT NULL,
        donor_id INT UNSIGNED NOT NULL,
        total BIGINT NOT NULL,
        snapshot JSON NOT NULL,                         -- 이름·주소·줄별 금액 (주민번호는 마스킹한 값만)
        issued_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        issued_by INT UNSIGNED NULL,
        void_of INT UNSIGNED NULL,                      -- 정정 발급이면 무효가 된 이전 영수증
        voided_at DATETIME NULL,
        reissue_count INT NOT NULL DEFAULT 0,
        UNIQUE KEY uniq_doc_no (doc_no),
        INDEX idx_donor_year (donor_id, tax_year),
        CONSTRAINT fk_{p}rc_donor FOREIGN KEY (donor_id) REFERENCES `{p}donors`(id)
    ) $ENGINE",

    // ===== 기록: 화면에서 지울 수 없다. 사용자를 지워도 남도록 사용자 표에는 외래키를 걸지 않는다 =====
    "CREATE TABLE `{p}access_log` (                 -- 로그인·개인별 헌금 열람·주민번호 사용·내려받기·권한 변경
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        access_id INT UNSIGNED NULL,
        who VARCHAR(50) NOT NULL DEFAULT '',
        action VARCHAR(40) NOT NULL,
        target VARCHAR(200) NOT NULL DEFAULT '',
        ip VARCHAR(45) NULL,
        INDEX idx_at (at),
        INDEX idx_access (access_id, at),
        INDEX idx_action (action, at)
    ) $ENGINE",
    "CREATE TABLE `{p}change_log` (                 -- 헌금·거래·예산·담당자의 수정 전후 값, 다시 열기·취소·마감 풀기 사유
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        by_access_id INT UNSIGNED NULL,
        by_name VARCHAR(50) NOT NULL DEFAULT '',
        entity VARCHAR(30) NOT NULL,
        entity_id VARCHAR(40) NOT NULL DEFAULT '',
        field VARCHAR(60) NOT NULL DEFAULT '',
        before_value TEXT NULL,
        after_value TEXT NULL,
        note VARCHAR(255) NULL,
        INDEX idx_entity (entity, entity_id),
        INDEX idx_at (at)
    ) $ENGINE",

    // ===== 기본 자료 =====
    "INSERT INTO `{p}settings` (id, church, rrn_mode, security, direct_rules, receipt_form, backup) VALUES
        (1, '{}', 'none',
         '{\"reconfirm\": true}',
         '{\"roles\": [\"재정 담당\"], \"month_cap\": null, \"proof_required\": true, \"close_confirm\": true}',
         '{\"line_mode\": \"year\", \"allow_family\": true}',
         '{\"interval_days\": 7, \"keep\": 8}')",
    "INSERT INTO `{p}funds` (id, name, is_default, sort_order) VALUES (1, '일반회계', 1, 1)",

    // 수입 항목: 헌금 > (십일조 … 절기헌금 > 신년·부활·맥추감사·추수감사·성탄 …), 기타 수입 > (이월금 …). 교회가 고칠 수 있다.
    "INSERT INTO `{p}income_accounts` (id, parent_id, name, sort_order, is_offering, named, receiptable, needs_unit, needs_purpose) VALUES
        (1,  NULL, '헌금',       1, 0, 1, 0, 0, 0),
        (2,  1,    '십일조',     1, 1, 1, 1, 0, 0),
        (3,  1,    '감사헌금',   2, 1, 1, 1, 0, 0),
        (4,  1,    '주일헌금',   3, 1, 0, 1, 0, 0),
        (5,  1,    '건축헌금',   4, 1, 1, 1, 0, 0),
        (6,  1,    '선교헌금',   5, 1, 1, 1, 0, 0),
        (7,  1,    '구제헌금',   6, 1, 1, 1, 0, 0),
        (8,  1,    '절기헌금',   7, 0, 1, 1, 0, 0),
        (9,  8,    '신년헌금',   1, 1, 1, 1, 0, 0),
        (10, 8,    '부활절헌금', 2, 1, 1, 1, 0, 0),
        (11, 8,    '맥추감사헌금', 3, 1, 1, 1, 0, 0),
        (12, 8,    '추수감사헌금', 4, 1, 1, 1, 0, 0),
        (13, 8,    '성탄헌금',   5, 1, 1, 1, 0, 0),
        (14, 1,    '총회헌금',   8, 1, 1, 1, 0, 0),
        (15, 1,    '부서헌금',   9, 1, 1, 1, 1, 0),
        (16, 1,    '목적헌금',  10, 1, 1, 1, 0, 1),
        (17, 1,    '기타헌금',  11, 1, 1, 1, 0, 0),
        (18, NULL, '기타 수입',  2, 0, 1, 0, 0, 0),
        (19, 18,   '이월금',     1, 0, 1, 0, 0, 0),
        (20, 18,   '교회보조금', 2, 0, 1, 0, 0, 0),
        (21, 18,   '이자수입',   3, 0, 1, 0, 0, 0),
        (22, 18,   '기타',       4, 0, 1, 0, 0, 0)",

    // 결재 규칙 기본: 구간 하나(부서장 → 위원장 → 재정위원장). 소액·고액 기준 금액은 관리자가 '결재 규칙'에서 입력한다.
    "INSERT INTO `{p}approval_rules` (kind, name, min_amount, max_amount, steps, add_on, sort_order) VALUES
        ('일반', '기본', 0, NULL,
         '[{\"role\":\"부서장\",\"mode\":\"결재\"},{\"role\":\"위원장\",\"mode\":\"결재\"},{\"role\":\"재정위원장\",\"mode\":\"결재\"}]',
         '{\"over_budget\":\"담임목사\",\"reserve\":\"담임목사\"}', 1)",
];
