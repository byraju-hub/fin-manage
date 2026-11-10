<?php
declare(strict_types=1);

// 설치 마법사. 브라우저에서 /install/ 에 들어와 DB 정보, 주민등록번호 방식, 재정 관리자 확인만 하면 설치가 끝난다.
// 재정은 교적 관리(ch-manage)가 같은 DB 에 먼저 설치되어 있어야 한다. (교적이 없으면 2단계에서 멈춘다.)
// 설치가 끝나면 data/config.php 가 생기고, 그 뒤로 이 화면은 "이미 설치됨"만 보여준다.

define('FM_ROOT', dirname(__DIR__));
define('FM_DATA', FM_ROOT . '/data');
require FM_ROOT . '/api/lib/paths.php';
require FM_ROOT . '/api/lib/migrate.php';
require FM_ROOT . '/api/lib/cmlink.php';
require __DIR__ . '/finalize.php';

ini_set('display_errors', '0');
date_default_timezone_set('Asia/Seoul');
mb_internal_encoding('UTF-8');

$base = fm_site_base(); // 예: /fm
session_name('fm_install');
session_set_cookie_params(['lifetime' => 0, 'path' => $base . '/install/', 'httponly' => true, 'samesite' => 'Lax']);
session_start();

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function page(string $title, string $content, int $step = 0): never
{
    $steps = ['환경 점검', '데이터베이스', '교회 · 주민번호', '재정 관리자', '완료'];
    echo '<!doctype html><html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex"><title>' . h($title) . ' · fin-manage 설치</title><style>
:root{--accent:#2b3e95;--ink:#17202a;--muted:#5b6678;--line:#e1e7f5;--line2:#d5ddf0;--bg:#f3f6ff}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:14px/1.6 "Noto Sans KR","Malgun Gothic","Apple SD Gothic Neo",system-ui,sans-serif}
.wrap{max-width:760px;margin:0 auto;padding:32px 16px 64px}.brand{display:flex;align-items:center;gap:10px;margin-bottom:20px}
.logo{width:42px;height:42px;border-radius:13px;background:var(--accent);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:19px}
.bn{font-size:17px;font-weight:700;line-height:1.2}.bs{font-size:12.5px;color:var(--muted)}
ol.steps{display:flex;gap:6px;list-style:none;margin:0 0 20px;padding:0;flex-wrap:wrap}
ol.steps li{padding:4px 12px;border-radius:999px;background:#e6ecfa;color:var(--muted);font-weight:600;font-size:12.5px}
ol.steps li.on{background:var(--accent);color:#fff}ol.steps li.done{background:#ddf5e8;color:#0f6b40}
.card{background:#fff;border:1px solid var(--line);border-radius:16px;padding:24px 26px}
h1{margin:0 0 4px;font-size:22px}p.sub{margin:0 0 16px;color:var(--muted)}
label{display:block;font-weight:700;font-size:13px;color:#475467;margin-bottom:4px}
input[type=text],input[type=password],select{width:100%;height:44px;border:1px solid var(--line2);border-radius:12px;padding:0 12px;font:inherit;background:#fff}
.row{margin-bottom:14px}.grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.hint{font-size:12.5px;color:var(--muted);margin-top:4px}
.btn{display:inline-flex;align-items:center;justify-content:center;height:46px;padding:0 24px;border-radius:12px;border:1px solid var(--line2);background:#fff;font:inherit;font-weight:700;cursor:pointer;text-decoration:none;color:var(--ink)}
.btn.p{background:var(--accent);border-color:transparent;color:#fff}.btn[disabled]{opacity:.5;cursor:not-allowed}
.actions{display:flex;justify-content:space-between;margin-top:20px;gap:10px}
.err{background:#ffe3ea;color:#be123c;border-radius:12px;padding:10px 14px;margin-bottom:14px;font-weight:600}
.ok{background:#ddf5e8;color:#0f6b40;border-radius:12px;padding:10px 14px;margin-bottom:14px;font-weight:600}
.warn{background:#fff2eb;border:1px solid #fde0d0;color:#7a3410;border-radius:12px;padding:10px 14px;margin-bottom:14px}
table.chk{width:100%;border-collapse:collapse}table.chk td{padding:8px 0;border-top:1px solid #edf1fa}table.chk td:last-child{text-align:right;font-weight:700}
.good{color:#0f6b40}.bad{color:#be123c}.wn{color:#a04a07}
.opt{display:block;border:1px solid var(--line2);border-radius:14px;padding:14px 16px;margin-bottom:10px;cursor:pointer;font-weight:400}
.opt b{display:block;font-size:14px;color:var(--ink)}.opt small{display:block;color:var(--muted);font-weight:400;margin-top:2px;line-height:1.5}
.opt input{float:right;margin-top:4px}.opt:has(input:checked){border-color:var(--accent);background:#f0f2fa;box-shadow:0 0 0 3px rgba(43,62,149,.14)}
code.key{display:block;word-break:break-all;background:#f5f6fb;border:1px solid var(--line2);border-radius:10px;padding:10px 12px;font-size:13px;margin:8px 0}
@media(max-width:600px){.grid{grid-template-columns:1fr}.card{padding:18px 16px}}
</style></head><body><div class="wrap"><div class="brand"><div class="logo">재</div><div><div class="bn">fin-manage</div><div class="bs">교회 재정 관리 · 설치</div></div></div>';
    if ($step > 0) {
        echo '<ol class="steps">';
        foreach ($steps as $i => $s) {
            $n = $i + 1;
            echo '<li class="' . ($n < $step ? 'done' : ($n === $step ? 'on' : '')) . '">' . $n . '. ' . h($s) . '</li>';
        }
        echo '</ol>';
    }
    echo '<div class="card">' . $content . '</div></div></body></html>';
    exit;
}

// ===== 이미 설치된 경우 =====
// 새 버전 zip 을 덮어쓴 뒤 이 화면을 한 번 열면: 화면 파일의 폴더 주소를 마무리하고, 새 버전에 데이터베이스 변경이 있으면 함께 적용한다.
if (is_file(FM_DATA . '/config.php')) {
    $fixed = fm_needs_finalize() ? fm_finalize_web() : 0;
    $cfg = require FM_DATA . '/config.php';
    $migrated = 0;
    $migrateError = '';
    try {
        $pdo0 = new PDO(fm_dsn($cfg['db']), $cfg['db']['user'], $cfg['db']['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $migrated = run_migrations($pdo0, $cfg['prefix'], FM_ROOT . '/migrations');
    } catch (Throwable $e) {
        $migrateError = '데이터베이스를 새 버전으로 바꾸지 못했어요. (' . h(preg_replace('/\s+/', ' ', mb_substr($e->getMessage(), 0, 160))) . ')';
    }
    page('이미 설치됨',
        ($fixed ? '<div class="ok">새 버전의 화면 파일 ' . $fixed . '개를 이 폴더에 맞게 마무리했어요.</div>' : '')
        . ($migrated ? '<div class="ok">데이터베이스를 새 버전으로 바꿨어요. (변경 ' . $migrated . '건)</div>' : '')
        . ($migrateError !== '' ? '<div class="err" role="alert">' . $migrateError . '</div>' : '')
        . '<h1>이미 설치되어 있어요</h1><p class="sub">이 프로그램은 설치가 끝났습니다. 다시 설치하려면 서버의 <b>data/config.php</b> 를 지운 뒤 이 화면을 다시 여세요. (데이터베이스의 자료는 지워지지 않습니다.)</p><p><a class="btn p" href="' . h($base) . '/">프로그램으로 가기</a></p>');
}

$S = &$_SESSION['inst'];
$S ??= ['step' => 1];
$S['csrf'] ??= bin2hex(random_bytes(16));
$error = '';

function csrf_field(): string { global $S; return '<input type="hidden" name="csrf" value="' . h($S['csrf']) . '">'; }

// ===== 환경 점검 =====
function checks(): array
{
    $c = [];
    $c[] = ['PHP 8.1 이상', PHP_VERSION, version_compare(PHP_VERSION, '8.1.0', '>='), true];
    foreach (['pdo_mysql' => 'PDO MySQL (데이터베이스)', 'mbstring' => 'mbstring (한글 처리)', 'json' => 'JSON'] as $e => $label) {
        $c[] = [$label, extension_loaded($e) ? '사용 가능' : '없음', extension_loaded($e), true];
    }
    $c[] = ['OpenSSL (주민등록번호 암호화 보관용)', extension_loaded('openssl') ? '사용 가능' : '없음 (암호화 보관 방식을 고를 수 없어요)', extension_loaded('openssl'), false];
    $c[] = ['GD (증빙 사진 줄이기)', extension_loaded('gd') ? '사용 가능' : '없음 (사진 기능을 쓸 수 없어요)', extension_loaded('gd'), false];
    $c[] = ['data 폴더에 쓰기', is_writable(FM_DATA) ? '가능' : '불가능 (폴더 권한을 707 또는 777로 바꿔 주세요)', is_writable(FM_DATA), true];
    $has = is_dir(FM_ROOT . '/migrations') && (bool)migration_files(FM_ROOT . '/migrations');
    $c[] = ['데이터베이스 구조 파일', $has ? '있음' : '없음', $has, true];
    return $c;
}

function connect(array $d): PDO
{
    return new PDO(fm_dsn($d), $d['user'], $d['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

// ===== 입력 처리 =====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($S['csrf'], (string)($_POST['csrf'] ?? ''))) {
        $error = '화면이 오래되었어요. 새로고침한 뒤 다시 시도해 주세요.';
    } else {
        $act = (string)($_POST['act'] ?? '');
        // 단계를 건너뛰는 요청은 받지 않는다. (각 동작은 해당 단계 화면에서만 실행된다.)
        $need = ['check' => 1, 'db' => 2, 'church' => 3, 'install' => 4];
        if (isset($need[$act]) && (int)$S['step'] !== $need[$act]) {
            $act = '';
            $error = '순서가 맞지 않아요. 화면의 단계를 따라 진행해 주세요.';
        }

        if ($act === 'back') {
            $S['step'] = max(1, (int)$S['step'] - 1);
        } elseif ($act === 'check') {
            $blocked = array_filter(checks(), fn($c) => $c[3] && !$c[2]);
            if ($blocked) $error = '필수 항목을 먼저 해결해 주세요.';
            else $S['step'] = 2;
        } elseif ($act === 'db') {
            $d = ['host' => trim((string)($_POST['host'] ?? '')), 'name' => trim((string)($_POST['name'] ?? '')), 'user' => trim((string)($_POST['user'] ?? '')), 'pass' => (string)($_POST['pass'] ?? '')];
            $prefix = trim((string)($_POST['prefix'] ?? 'fm_'));
            $cmPrefix = trim((string)($_POST['cm_prefix'] ?? 'cm_'));
            $S['db_form'] = ['host' => $d['host'], 'name' => $d['name'], 'user' => $d['user'], 'prefix' => $prefix, 'cm_prefix' => $cmPrefix];
            if ($d['host'] === '' || $d['name'] === '' || $d['user'] === '') $error = '접속 주소, 데이터베이스 이름, 아이디를 모두 입력해 주세요.';
            elseif (!valid_prefix($prefix)) $error = '재정의 표 이름 접두어는 영문 소문자로 시작하고 밑줄(_)로 끝나게 써 주세요. 예: fm_';
            else {
                try {
                    $pdo = connect($d);
                    $cm = fm_cm_check($pdo, $cmPrefix, $prefix);
                    if (!$cm['ok']) {
                        $error = h($cm['message']);
                    } elseif (prefix_in_use($pdo, $prefix)) {
                        $error = "이 데이터베이스에 '" . h($prefix) . "' 로 시작하는 표가 이미 있어요. 다른 접두어를 쓰거나, 예전 설치 흔적이라면 직접 지운 뒤 다시 시도해 주세요. (기존 자료를 보호하려고 자동으로 덮어쓰지 않아요.)";
                    } else {
                        $S['db'] = $d;
                        $S['prefix'] = $prefix;
                        $S['cm_prefix'] = $cmPrefix;
                        $S['cm'] = $cm;
                        $S['church_info'] = fm_cm_church($pdo, $cmPrefix);
                        $S['step'] = 3;
                    }
                } catch (Throwable $e) {
                    $error = '데이터베이스에 연결하지 못했어요. 입력한 정보를 다시 확인해 주세요. (' . h(preg_replace('/\s+/', ' ', mb_substr($e->getMessage(), 0, 120))) . ')';
                }
            }
        } elseif ($act === 'church') {
            $mode = (string)($_POST['rrn_mode'] ?? '');
            $S['rrn_mode'] = $mode;
            if (!in_array($mode, ['keep', 'none'], true)) $error = '주민등록번호 처리 방식을 꼭 하나 골라 주세요.';
            elseif ($mode === 'keep' && !extension_loaded('openssl')) $error = '이 서버에는 OpenSSL 이 없어 암호화 보관을 쓸 수 없어요. 「보관하지 않음」을 고르거나 호스팅 설정에서 OpenSSL 을 켜 주세요.';
            else $S['step'] = 4;
        } elseif ($act === 'install') {
            $login = trim((string)($_POST['login'] ?? ''));
            $pw = (string)($_POST['pw'] ?? '');
            $S['admin_form'] = ['login' => $login];
            if ($login === '' || $pw === '') $error = '교적 관리의 최고관리자 아이디와 비밀번호를 입력해 주세요.';
            elseif (empty($S['db']) || empty($S['prefix']) || empty($S['cm_prefix']) || empty($S['rrn_mode'])) $error = '앞 단계 입력이 사라졌어요. 처음부터 다시 진행해 주세요.';
            else {
                $pdo = null;
                $created = false;
                try {
                    $p = $S['prefix'];
                    $cp = $S['cm_prefix'];
                    $pdo = connect($S['db']);

                    // 교적의 최고관리자 계정으로 본인 확인. 재정 관리자는 교적 계정에서 정해진다.
                    $st = $pdo->prepare("SELECT id, login_id, name, person_id, password_hash, status, is_super, is_demo FROM `{$cp}users` WHERE login_id = ?");
                    $st->execute([$login]);
                    $cu = $st->fetch();
                    if (!$cu || !password_verify($pw, $cu['password_hash']) || $cu['status'] !== '사용' || (int)$cu['is_super'] !== 1 || (int)$cu['is_demo'] === 1) {
                        throw new InvalidArgumentException('교적 관리의 최고관리자 아이디·비밀번호가 맞지 않아요. (교적 관리에 가입해 승인된 최고관리자 계정이어야 해요.)');
                    }

                    if (prefix_in_use($pdo, $p)) throw new RuntimeException('이미 같은 접두어의 표가 있어요. 2단계에서 다시 시도해 주세요.');
                    $created = true;
                    run_migrations($pdo, $p, FM_ROOT . '/migrations');

                    $mode = $S['rrn_mode'];
                    $pdo->prepare("UPDATE `{$p}settings` SET church = ?, rrn_mode = ? WHERE id = 1")
                        ->execute([json_encode($S['church_info'] ?: new stdClass(), JSON_UNESCAPED_UNICODE), $mode]);
                    $y = (int)date('Y');
                    $pdo->prepare("INSERT INTO `{$p}fiscal_years` (year, start_date, end_date, status) VALUES (?, ?, ?, '진행')")
                        ->execute([$y, "$y-01-01", "$y-12-31"]);
                    $pdo->prepare("INSERT INTO `{$p}meta` (k, v) VALUES ('installed_at', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)")->execute([json_encode(date('c'))]);

                    // 첫 재정 관리자 = 교적 최고관리자. 처음 설정을 도울 수 있게 '재정 담당' 역할도 함께 준다. (이후 승인 화면에서 바꿀 수 있다.)
                    $pdo->prepare("INSERT INTO `{$p}access` (cm_user_id, login_id, name, person_id, status, is_admin, can_view_donors, reason, decided_at) VALUES (?,?,?,?, '사용', 1, 1, '설치 때 등록', NOW())")
                        ->execute([$cu['id'], $cu['login_id'], $cu['name'], $cu['person_id']]);
                    $aid = (int)$pdo->lastInsertId();
                    $pdo->prepare("INSERT INTO `{$p}access_roles` (access_id, role) VALUES (?, '재정 담당')")->execute([$aid]);

                    $config = ['db' => $S['db'], 'prefix' => $p, 'cm_prefix' => $cp, 'secret' => bin2hex(random_bytes(32)), 'installed_at' => date('c')];
                    if ($mode === 'keep') {
                        $config['rrn_key'] = base64_encode(random_bytes(32)); // 주민등록번호 암호화 열쇠. 백업 파일에는 넣지 않는다.
                        $S['rrn_key_shown'] = $config['rrn_key'];
                    }
                    // 마지막에 설정 파일을 만든다. 이 파일이 있어야 "설치됨"으로 본다.
                    if (file_put_contents(FM_DATA . '/config.php', "<?php\nreturn " . var_export($config, true) . ";\n", LOCK_EX) === false) {
                        throw new RuntimeException('data/config.php 를 만들지 못했어요. data 폴더의 쓰기 권한을 확인해 주세요.');
                    }
                    @chmod(FM_DATA . '/config.php', 0600);
                    fm_finalize_web();
                    $S['step'] = 5;
                } catch (Throwable $e) {
                    // 이번 시도에서 만든 표만 정리해서 처음부터 다시 할 수 있게 한다.
                    if ($pdo && $created) {
                        try {
                            $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
                            foreach ($pdo->query('SHOW TABLES LIKE ' . $pdo->quote(str_replace('_', '\\_', $S['prefix']) . '%'))->fetchAll(PDO::FETCH_COLUMN) as $tbl) {
                                $pdo->exec('DROP TABLE IF EXISTS `' . str_replace('`', '', $tbl) . '`');
                            }
                            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
                        } catch (Throwable) {
                        }
                    }
                    if ($e instanceof InvalidArgumentException) {
                        $error = h($e->getMessage());
                    } else {
                        @file_put_contents(FM_DATA . '/install-error.log', date('c') . ' ' . $e->getMessage() . "\n", FILE_APPEND);
                        $error = '설치하는 중 문제가 생겼어요: ' . h(preg_replace('/\s+/', ' ', mb_substr($e->getMessage(), 0, 200))) . ' (이번에 만든 표는 정리했어요. 다시 시도할 수 있어요.)';
                    }
                }
            }
        }
    }
}

$step = (int)$S['step'];
$err = $error !== '' ? '<div class="err" role="alert">' . $error . '</div>' : '';

// ===== 화면 =====
if ($step === 1) {
    $rows = '';
    $blocked = false;
    foreach (checks() as [$label, $value, $okk, $must]) {
        if ($must && !$okk) $blocked = true;
        $cls = $okk ? 'good' : ($must ? 'bad' : 'wn');
        $rows .= '<tr><td>' . h($label) . '</td><td class="' . $cls . '">' . h((string)$value) . ($okk ? ' ✓' : '') . '</td></tr>';
    }
    page('환경 점검', $err . '<h1>환경을 점검해요</h1><p class="sub">이 서버에서 프로그램이 동작할 수 있는지 확인합니다.</p><table class="chk">' . $rows . '</table>'
        . '<form method="post">' . csrf_field() . '<input type="hidden" name="act" value="check"><div class="actions"><span></span><button class="btn p" type="submit"' . ($blocked ? ' disabled' : '') . '>다음: 데이터베이스</button></div></form>', 1);
}

if ($step === 2) {
    $f = $S['db_form'] ?? ['host' => 'localhost', 'name' => '', 'user' => '', 'prefix' => 'fm_', 'cm_prefix' => 'cm_'];
    page('데이터베이스', $err . '<h1>데이터베이스를 연결해요</h1><p class="sub">재정은 <b>교적 관리와 같은 데이터베이스</b>에 설치합니다. 교적 관리를 설치할 때 입력한 접속 정보를 그대로 넣어 주세요.</p>'
        . '<form method="post" autocomplete="off">' . csrf_field() . '<input type="hidden" name="act" value="db">'
        . '<div class="grid"><div class="row"><label for="host">접속 주소</label><input id="host" type="text" name="host" value="' . h($f['host']) . '"></div><div class="row"><label for="name">데이터베이스 이름</label><input id="name" type="text" name="name" value="' . h($f['name']) . '"></div></div>'
        . '<div class="grid"><div class="row"><label for="user">아이디</label><input id="user" type="text" name="user" value="' . h($f['user']) . '"></div><div class="row"><label for="pass">비밀번호</label><input id="pass" type="password" name="pass" autocomplete="new-password"></div></div>'
        . '<div class="grid"><div class="row"><label for="prefix">재정 표 이름 접두어</label><input id="prefix" type="text" name="prefix" value="' . h($f['prefix']) . '"><div class="hint">기본값 <b>fm_</b> 그대로 쓰면 돼요.</div></div>'
        . '<div class="row"><label for="cm_prefix">교적 관리 표 이름 접두어</label><input id="cm_prefix" type="text" name="cm_prefix" value="' . h($f['cm_prefix']) . '"><div class="hint">교적 관리를 설치할 때 정한 값이에요. 보통 <b>cm_</b></div></div></div>'
        . '<div class="actions"><button class="btn" type="submit" name="act" value="back" formnovalidate>이전</button><button class="btn p" type="submit">연결 확인하고 다음</button></div></form>', 2);
}

if ($step === 3) {
    $m = $S['rrn_mode'] ?? '';
    $church = (string)($S['church_info']['name'] ?? '');
    $cmInfo = $S['cm'] ?? ['schema' => 0, 'people' => 0];
    $opt = fn(string $v, string $title, string $desc) => '<label class="opt"><input type="radio" name="rrn_mode" value="' . $v . '"' . ($m === $v ? ' checked' : '') . '><b>' . h($title) . '</b><small>' . h($desc) . '</small></label>';
    page('교회 · 주민번호', $err
        . '<div class="ok">교적 관리와 연결됐어요. 교회 이름 <b>' . h($church !== '' ? $church : '(교적에 아직 없음)') . '</b> · 교인 ' . (int)$cmInfo['people'] . '명 · 교적 구조 번호 ' . (int)$cmInfo['schema'] . '</div>'
        . '<h1>주민등록번호는 어떻게 할까요?</h1><p class="sub">기부금 영수증에는 기부자의 주민등록번호 칸이 있어요. 교회에서 정하는 방식을 고르세요. 설치한 뒤 설정에서 바꿀 수 있어요.</p>'
        . '<form method="post">' . csrf_field() . '<input type="hidden" name="act" value="church">'
        . $opt('keep', 'A. 암호화해서 보관', '헌금자 화면에서 번호를 입력해 두면 영수증을 만들 때 자동으로 채워져요. 번호는 암호화되어 저장되고, 열쇠는 서버 설정 파일에만 있어요(백업 파일에는 들어가지 않아요). 발급할 때마다 사용 기록이 남아요.')
        . $opt('none', 'B. 보관하지 않음', '번호를 어디에도 저장하지 않아요. 영수증에는 생년월일 앞 6자리까지만 찍히고 뒷자리는 비워서, 받는 분이 직접 적어요.')
        . '<div class="hint">두 방식 중 하나를 꼭 골라야 다음으로 넘어가요.</div>'
        . '<div class="actions"><button class="btn" type="submit" name="act" value="back" formnovalidate>이전</button><button class="btn p" type="submit">다음: 재정 관리자</button></div></form>', 3);
}

if ($step === 4) {
    $a = $S['admin_form'] ?? ['login' => ''];
    page('재정 관리자', $err . '<h1>재정 관리자를 정해요</h1><p class="sub">재정 사용을 승인하고 역할을 정하는 첫 번째 관리자예요. <b>교적 관리의 최고관리자 계정</b>으로 본인 확인을 해 주세요. 비밀번호는 저장하지 않고 확인에만 써요.</p>'
        . '<form method="post" autocomplete="off">' . csrf_field() . '<input type="hidden" name="act" value="install">'
        . '<div class="grid"><div class="row"><label for="login">교적 아이디</label><input id="login" type="text" name="login" value="' . h($a['login']) . '" autocomplete="username"></div><div class="row"><label for="pw">교적 비밀번호</label><input id="pw" type="password" name="pw" autocomplete="current-password"></div></div>'
        . '<div class="hint">이후 다른 사람의 재정 사용은 교적 계정으로 로그인한 뒤 신청하고, 이 관리자가 승인해요.</div>'
        . '<div class="actions"><button class="btn" type="submit" name="act" value="back" formnovalidate>이전</button><button class="btn p" type="submit">설치하기</button></div></form>', 4);
}

// 5단계: 완료
$key = $S['rrn_key_shown'] ?? null;
unset($_SESSION['inst']);
page('완료', '<h1>설치가 끝났어요</h1><div class="ok">재정 표를 만들고 재정 관리자를 등록했어요.</div>'
    . ($key ? '<div class="warn"><b>주민등록번호 암호화 열쇠</b>예요. 이 화면에서만 보여 드려요. 안전한 곳에 따로 적어 두세요. 서버가 바뀌거나 설정 파일을 잃으면 이 열쇠 없이는 저장된 번호를 풀 수 없어요.<code class="key">' . h($key) . '</code></div>' : '')
    . '<p class="sub">이제 교적 관리와 같은 아이디·비밀번호로 로그인하세요. 보안을 위해 이 설치 화면은 앞으로 열리지 않아요.</p>'
    . '<p><a class="btn p" href="' . h($base) . '/">프로그램으로 가기</a></p>');
