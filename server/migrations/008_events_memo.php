<?php
declare(strict_types=1);

// 행사(프로젝트) 정산, '메모 필수' 카테고리(기타 등).
return [
    "ALTER TABLE `{p}expense_categories` ADD needs_memo TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active",
    "UPDATE `{p}expense_categories` SET needs_memo = 1 WHERE name = '기타'",
    "CREATE TABLE `{p}events` (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        fiscal_year SMALLINT UNSIGNED NOT NULL,
        unit_id INT UNSIGNED NULL,
        name VARCHAR(60) NOT NULL,
        starts_on DATE NULL,
        ends_on DATE NULL,
        budget BIGINT NOT NULL DEFAULT 0,
        status VARCHAR(10) NOT NULL DEFAULT '진행',
        note VARCHAR(255) NULL,
        created_by INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_event (fiscal_year, name),
        INDEX idx_unit (unit_id),
        CONSTRAINT fk_{p}ev_year FOREIGN KEY (fiscal_year) REFERENCES `{p}fiscal_years`(year),
        CONSTRAINT fk_{p}ev_unit FOREIGN KEY (unit_id) REFERENCES `{p}units`(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "ALTER TABLE `{p}voucher_items` ADD event_id INT UNSIGNED NULL, ADD INDEX idx_event (event_id),
        ADD CONSTRAINT fk_{p}vi_event FOREIGN KEY (event_id) REFERENCES `{p}events`(id) ON DELETE SET NULL",
    "ALTER TABLE `{p}transactions` ADD event_id INT UNSIGNED NULL, ADD INDEX idx_tx_event (event_id),
        ADD CONSTRAINT fk_{p}tx_event FOREIGN KEY (event_id) REFERENCES `{p}events`(id) ON DELETE SET NULL",
];
