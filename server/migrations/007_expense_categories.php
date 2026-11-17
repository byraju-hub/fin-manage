<?php
declare(strict_types=1);

// 지출 카테고리(교회 공통 관·항·목 3단계), 부서별 사용 카테고리, 예산 항목의 카테고리 연결.
// 카테고리는 교회 전체에 하나만 두고, 부서는 그중 쓸 것만 고른다(부서마다 따로 만들면 합산·비교가 안 되므로).
return [
    "CREATE TABLE `{p}expense_categories` (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        parent_id INT UNSIGNED NULL,
        name VARCHAR(40) NOT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        UNIQUE KEY uniq_name (parent_id, name),
        CONSTRAINT fk_{p}ec_parent FOREIGN KEY (parent_id) REFERENCES `{p}expense_categories`(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TABLE `{p}unit_categories` (
        unit_id INT UNSIGNED NOT NULL,
        category_id INT UNSIGNED NOT NULL,
        PRIMARY KEY (unit_id, category_id),
        CONSTRAINT fk_{p}uc_unit FOREIGN KEY (unit_id) REFERENCES `{p}units`(id) ON DELETE CASCADE,
        CONSTRAINT fk_{p}uc_cat FOREIGN KEY (category_id) REFERENCES `{p}expense_categories`(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "ALTER TABLE `{p}budget_items` ADD category_id INT UNSIGNED NULL AFTER nature, ADD INDEX idx_cat (category_id),
        ADD CONSTRAINT fk_{p}bi_cat FOREIGN KEY (category_id) REFERENCES `{p}expense_categories`(id) ON DELETE SET NULL",
];
