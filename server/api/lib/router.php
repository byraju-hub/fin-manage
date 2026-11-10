<?php
declare(strict_types=1);

// 아주 작은 라우터. route('GET', '/vouchers/{id}', fn(int $id) => ...) 처럼 등록하고 dispatch() 가 맞는 것을 실행한다.
//   {id} → 숫자 (정수로 넘겨준다)

/** @var array<int, array{string, string, callable}> */
$GLOBALS['fm_routes'] = [];

function route(string $method, string $pattern, callable $handler): void
{
    $rx = str_replace(['{id}', '{period}'], ['(\d+)', '(\d{4}-\d{2})'], $pattern);
    $GLOBALS['fm_routes'][] = [$method, '#^' . $rx . '$#', $handler];
}

function req_method(): string
{
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
}

function dispatch(string $path): never
{
    $method = req_method();
    $pathMatched = false;
    foreach ($GLOBALS['fm_routes'] as [$m, $rx, $handler]) {
        if (!preg_match($rx, $path, $match)) continue;
        $pathMatched = true;
        if ($m !== $method) continue;
        array_shift($match);
        $args = array_map(fn($v) => ctype_digit($v) ? (int)$v : $v, $match);
        $handler(...$args);
        json_out(null); // 핸들러가 응답하지 않았다면 빈 성공
    }
    if ($pathMatched) json_fail('method_not_allowed', '허용되지 않는 요청 방식입니다.', 405);
    json_fail('not_found', '찾을 수 없는 주소입니다.', 404);
}
