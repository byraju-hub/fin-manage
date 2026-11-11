<?php
declare(strict_types=1);

// 직접 지출의 증빙: 파일(영수증 사진 등)과 '증빙 없음' 사유. 설정의 '증빙 필수'가 이제 실제로 적용된다.
return [
    "ALTER TABLE `{p}transactions` ADD proof_note VARCHAR(255) NULL AFTER vendor",
    "CREATE TABLE `{p}transaction_files` (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        transaction_id INT UNSIGNED NOT NULL,
        path VARCHAR(255) NOT NULL,
        mime VARCHAR(40) NOT NULL,
        size INT UNSIGNED NOT NULL,
        orig_name VARCHAR(100) NULL,
        uploaded_by INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_tx (transaction_id),
        CONSTRAINT fk_{p}tf_tx FOREIGN KEY (transaction_id) REFERENCES `{p}transactions`(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];
