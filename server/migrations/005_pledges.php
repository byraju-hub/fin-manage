<?php
declare(strict_types=1);

// 작정헌금(약정): 헌금자가 한 해에 어느 헌금 종류에 얼마를 작정했는지. 낸 금액은 마감된 헌금에서 그때그때 계산한다.
return [
    "CREATE TABLE `{p}pledges` (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        fiscal_year SMALLINT UNSIGNED NOT NULL,
        donor_id INT UNSIGNED NOT NULL,
        income_account_id INT UNSIGNED NOT NULL,
        amount BIGINT NOT NULL,
        memo VARCHAR(255) NULL,
        created_by INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_pledge (fiscal_year, donor_id, income_account_id),
        INDEX idx_year (fiscal_year),
        CONSTRAINT fk_{p}pl_donor FOREIGN KEY (donor_id) REFERENCES `{p}donors`(id) ON DELETE CASCADE,
        CONSTRAINT fk_{p}pl_acct FOREIGN KEY (income_account_id) REFERENCES `{p}income_accounts`(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];
