<?php
declare(strict_types=1);

// 한 사람이 연달아 두 단계를 맡으면 한 번에 결재하고 역할 이름을 '부서장·위원장' 처럼 합쳐 적는다. 칸을 넓힌다.
return [
    "ALTER TABLE `{p}approval_steps` MODIFY role VARCHAR(30) NOT NULL",
];
