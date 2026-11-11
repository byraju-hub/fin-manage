<?php
declare(strict_types=1);

// 데이터베이스 연결과 질의 도우미. 같은 DB 에 교적(cm_)·부서 관리(dm_)의 표가 있어도 섞이지 않도록
// 이 프로그램의 모든 표 이름에는 접두어(기본 fm_)가 붙는다. 교적의 표는 cmt() 로만 읽는다.

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $c = fm_config();
    if (!$c) json_fail('not_installed', '아직 설치되지 않았습니다.', 503);
    $d = $c['db'];
    require_once __DIR__ . '/migrate.php';
    $pdo = new PDO(fm_dsn($d), $d['user'], $d['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec("SET time_zone = '+09:00'");
    return $pdo;
}

/** 재정 표 이름. t('access') → `fm_access` */
function t(string $name): string
{
    if (fm_demo_on()) return '`' . fm_demo_prefix() . $name . '`'; // 체험 모드: 가짜 자료 표
    return '`' . (fm_config()['prefix'] ?? 'fm_') . $name . '`';
}

/** 교적 표 이름 (읽기 전용으로만 쓴다). cmt('people') → `cm_people` */
function cmt(string $name): string
{
    return '`' . (fm_config()['cm_prefix'] ?? 'cm_') . $name . '`';
}

function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

/** @return array<int, array<string, mixed>> */
function rows(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll();
}

function row(string $sql, array $params = []): ?array
{
    $r = q($sql, $params)->fetch();
    return $r === false ? null : $r;
}

function val(string $sql, array $params = []): mixed
{
    $r = q($sql, $params)->fetchColumn();
    return $r === false ? null : $r;
}

function insert_id(string $sql, array $params = []): int
{
    q($sql, $params);
    return (int)db()->lastInsertId();
}

/** IN (?, ?, ?) 자리표시자 */
function marks(array $values): string
{
    return implode(',', array_fill(0, max(1, count($values)), '?'));
}

/** 한 묶음으로 실행한다. 중간에 오류가 나면 전부 되돌린다. (헌금 마감, 지급, 영수증 번호 매기기 등 돈이 오가는 곳은 모두 이것을 거친다.) */
function tx(callable $fn): mixed
{
    $pdo = db();
    if ($pdo->inTransaction()) return $fn(); // 이미 묶음 안이면 바깥 묶음에 합친다 (오류도 바깥에서 한꺼번에 되돌려진다)
    $pdo->beginTransaction();
    try {
        $r = $fn();
        $pdo->commit();
        return $r;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
