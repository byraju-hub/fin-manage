<?php
declare(strict_types=1);

// 헌금 묶음 구분 '주일예배' → '주일' (날짜 뒤에 '주일'만 보이게)
return [
    "UPDATE `{p}offering_batches` SET kind = '주일' WHERE kind = '주일예배'",
    "ALTER TABLE `{p}offering_batches` MODIFY kind VARCHAR(20) NOT NULL DEFAULT '주일'",
];
