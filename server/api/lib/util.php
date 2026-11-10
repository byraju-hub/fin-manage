<?php
declare(strict_types=1);

// 입력 확인과 자잘한 도우미.

function client_ip(): string
{
    return mb_substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

/** 문자열 입력. 앞뒤 공백을 지우고 길이를 확인한다. */
function in_str(array $b, string $key, int $max, bool $required = false, string $label = ''): string
{
    $v = $b[$key] ?? '';
    if (!is_string($v) && !is_int($v)) $v = '';
    $v = trim((string)$v);
    $label = $label !== '' ? $label : $key;
    if ($required && $v === '') json_fail('invalid', $label . '을(를) 입력해 주세요.', 422);
    if (mb_strlen($v) > $max) json_fail('invalid', $label . '은(는) ' . $max . '자 이하로 입력해 주세요.', 422);
    return $v;
}

/** 정해진 값 중 하나만 허용 */
function in_enum(array $b, string $key, array $allowed, string $label = ''): string
{
    $v = $b[$key] ?? null;
    if (!is_string($v) || !in_array($v, $allowed, true)) json_fail('invalid', ($label !== '' ? $label : $key) . ' 값이 올바르지 않습니다.', 422);
    return $v;
}

/** 금액·정수 입력. 범위를 벗어나거나 숫자가 아니면 거절한다. (돈은 원 단위 정수만 받는다.) */
function in_int(array $b, string $key, int $min, int $max, string $label = ''): int
{
    $v = $b[$key] ?? null;
    if (is_string($v) && preg_match('/^-?\d+$/', $v)) $v = (int)$v;
    if (!is_int($v) || $v < $min || $v > $max) json_fail('invalid', ($label !== '' ? $label : $key) . ' 값이 올바르지 않습니다.', 422);
    return $v;
}

function today_str(): string
{
    return date('Y-m-d');
}

/** 새 UUID(v4) */
function new_uuid(): string
{
    return sprintf('%08x-%04x-4%03x-8%03x-%012x', random_int(0, 0xffffffff), random_int(0, 0xffff), random_int(0, 0xfff), random_int(0, 0xfff), random_int(0, 0xffffffffffff));
}
