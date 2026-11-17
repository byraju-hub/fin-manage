<?php
declare(strict_types=1);

// 교인이 아닌 헌금자(외부인·단체)의 개인정보 동의 기록. 교인은 교적의 동의 기록(cm_consents)을 읽기만 한다.
return [
    "CREATE TABLE `{p}donor_consents` (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        donor_id INT UNSIGNED NOT NULL,
        policy_version VARCHAR(30) NOT NULL,
        consent_date DATE NOT NULL,
        method VARCHAR(10) NOT NULL,
        memo VARCHAR(255) NULL,
        revoked_on DATE NULL,
        created_by INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_donor (donor_id),
        CONSTRAINT fk_{p}dc_donor FOREIGN KEY (donor_id) REFERENCES `{p}donors`(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];
