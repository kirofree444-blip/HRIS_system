<?php
declare(strict_types=1);

/**
 * dashboard-calendar over HTTP (migration 039, John 2026-11-07): real login, real routes, real HTML. Needs a SCRATCH database copy with 039:
 *   HRIS_TEST_DB=<scratch> php tests/calendar_events_http_test.php
mppphpphp.exe testslendar_events_http_test.php
 * The app is copied to a temp folder and served by php -S; fixtures are written to the scratch DB only.
 */
$dbName = getenv('HRIS_TEST_DB') ?: '';
if ($dbName === '' || $dbName === 'pmbsi_hris') { fwrite(STDERR, "Set HRIS_TEST_DB to a scratch copy (not pmbsi_hris).\n"); exit(2); }
$root = dirname(__DIR__);
$tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'hris_cal_http_'.bin2hex(random_bytes(4));
$port = 8120;
$pdo = new PDO('mysql:host=127.0.0.1;dbname='.$dbName.';charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

function copyTree(string $from, string $to): void {
    @mkdir($to, 0777, true);
    foreach (new DirectoryIterator($from) as $f) {
        if ($f->isDot()) continue;
        $f->isDir() ? copyTree($f->getPathname(), $to.DIRECTORY_SEPARATOR.$f->getFilename()) : copy($f->getPathname(), $to.DIRECTORY_SEPARATOR.$f->getFilename());
    }
}
function rmTree(string $dir): void {
    if (!is_dir($dir)) return;
    foreach (new DirectoryIterator($dir) as $f) { if ($f->isDot()) continue; $f->isDir() ? rmTree($f->getPathname()) : @unlink($f->getPathname()); }
    @rmdir($dir);
}

foreach (['app', 'config', 'public', 'vendor'] as $d) copyTree($root.'/'.$d, $tmp.'/'.$d);
copy($root.'/index.php', $tmp.'/index.php'); copy($root.'/router.php', $tmp.'/router.php');
@mkdir($tmp.'/storage/employee_documents', 0777, true);
$key = base64_encode(random_bytes(32));
file_put_contents($tmp.'/config/config.local.php', "<?php\nreturn ['app'=>['debug'=>true],'mail'=>[],'security'=>['credential_key'=>".var_export($key, true)."],'db'=>['host'=>'127.0.0.1','port'=>'3306','database'=>".var_export($dbName, true).",'username'=>'root','password'=>'','charset'=>'utf8mb4'],'incident_documents'=>['storage_root'=>".var_export($tmp.'/private', true)."]];\n");

// ---- server ----
$proc = proc_open(array_merge([PHP_BINARY], extension_loaded('gd') ? ['-d', 'extension=gd'] : [], ['-S', '127.0.0.1:'.$port, '-t', $tmp, $tmp.'/router.php']), [['pipe', 'r'], ['file', $tmp.'/server.log', 'w'], ['file', $tmp.'/server.log', 'a']], $pipes, $tmp);
register_shutdown_function(function () use ($proc, $tmp) { if (is_resource($proc)) { proc_terminate($proc); proc_close($proc); } usleep(300000); rmTree($tmp); });
for ($i = 0; $i < 50; $i++) { $s = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.2); if ($s) { fclose($s); break; } usleep(100000); }

$runStart = (string)$pdo->query('SELECT NOW()')->fetchColumn();
$fail = 0;
function check(string $label, bool $ok, string $why = ''): void { global $fail; echo ($ok ? 'PASS ' : 'FAIL ').$label.($ok || $why === '' ? '' : '  ['.$why.']')."\n"; if (!$ok) $fail++; }

/** Tiny browser: cookie jar per instance. */
final class Browser {
    private string $jar;
    public function __construct(private int $port) { $this->jar = tempnam(sys_get_temp_dir(), 'jar'); }
    public function req(string $method, string $path, array $post = [], array $headers = []): array {
        $ch = curl_init('http://127.0.0.1:'.$this->port.$path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 30]);
        if ($method === 'POST') curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
        $raw = (string)curl_exec($ch); $size = curl_getinfo($ch, CURLINFO_HEADER_SIZE); $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
        $head = substr($raw, 0, $size);
        return ['code' => $code, 'head' => $head, 'body' => substr($raw, $size), 'loc' => preg_match('/^Location:\s*(.+)$/mi', $head, $m) ? trim($m[1]) : ''];
    }
    public function login(string $email, string $password): bool {
        $page = $this->req('GET', '/index.php?page=login');
        if (!preg_match('/name="csrf" value="([^"]+)"/', $page['body'], $m)) return false;
        $r = $this->req('POST', '/index.php?page=login', ['csrf' => html_entity_decode($m[1]), 'action' => 'login', 'email' => $email, 'password' => $password]);
        return $r['code'] === 302 && !str_contains($r['loc'], 'page=login');
    }
    public function csrf(string $html): string { return preg_match('/data-csrf="([^"]+)"/', $html, $m) ? html_entity_decode($m[1]) : ''; }

}
function e2(string $s): string { return htmlspecialchars($s, ENT_QUOTES); }
$csrfOf = static fn(string $html): string => preg_match('/name="csrf" value="([^"]+)"/', $html, $m) ? html_entity_decode($m[1]) : '';
$pw = 'Test#1234';
$mkUser = static function (string $role, string $label) use ($pdo, $pw): array {
    $email = 'zz_cal_'.$label.'_'.bin2hex(random_bytes(3)).'@example.test';
    $pdo->prepare('INSERT INTO users(role_id,full_name,email,password_hash,status) VALUES((SELECT id FROM roles WHERE code=?),?,?,?,"ACTIVE")')->execute([$role, 'Zz '.$label, $email, password_hash($pw, PASSWORD_DEFAULT)]);
    return ['id' => (int)$pdo->lastInsertId(), 'email' => $email];
};
$uHr = $mkUser('HR_USER', 'hr'); $uStaff = $mkUser('EMPLOYEE', 'staff');
$hr = new Browser($port); $staff = new Browser($port);
check('fixture accounts sign in', $hr->login($uHr['email'], $pw) && $staff->login($uStaff['email'], $pw));
$count = static fn(string $title): int => (int)$GLOBALS['pdo']->query('SELECT COUNT(*) FROM calendar_events WHERE deleted_at IS NULL AND title='.$GLOBALS['pdo']->quote($title))->fetchColumn();
$sfx = bin2hex(random_bytes(3));
$tCut = 'Zz cutoff '.$sfx; $tMeet = 'Zz meeting <b>'.$sfx.'</b>';

$h = $hr->req('GET', '/index.php?page=hr-dashboard&cal=2091-05')['body'];
$tok = $csrfOf($h);
check('HR dashboard shows the calendar for the chosen month with Add event and month links', str_contains($h, 'id="hd-cal-title"') && str_contains($h, 'May 2091') && str_contains($h, 'Add event') && str_contains($h, 'cal=2091-04') && str_contains($h, 'cal=2091-06') && $tok !== '');
$save = static fn(Browser $b, array $f): array => $b->req('POST', '/index.php?page=hr-dashboard&cal=2091-05', $f + ['action' => 'calendar_save', 'cal' => '2091-05', 'event_type' => 'COMPANY_EVENT', 'start_date' => '2091-05-01', 'end_date' => '2091-05-15']);
$r = $save($hr, ['csrf' => $tok, 'title' => $tCut, 'visible_to_employees' => '1']);
check('header: month centred between the arrows, Today on the right, no Add event button in the header', str_contains($h, '<strong class="hd-cal-current" aria-live="polite">May 2091</strong>') && str_contains($h, 'data-cal-nav="prev"') && str_contains($h, 'data-cal-nav="today"') && !str_contains($h, '>+ Add event</button>') && !str_contains($h, 'hd-cal-tools'));
check('HR adds a cutoff: redirect back to the same month and the row exists', $r['code'] === 302 && str_contains($r['loc'], 'page=hr-dashboard') && str_contains($r['loc'], 'cal=2091-05#calendar') && $count($tCut) === 1);
$r = $save($hr, ['csrf' => $tok, 'title' => $tMeet, 'event_type' => 'MEETING', 'start_date' => '2091-05-10', 'end_date' => '', 'start_time' => '14:00']);
check('HR adds an HR-only meeting', $r['code'] === 302 && $count($tMeet) === 1);
$h = $hr->req('GET', '/index.php?page=hr-dashboard&cal=2091-05')['body'];
check('full-width calendar: no agenda rail, every date is a button that opens the day modal', !str_contains($h, 'hd-cal-agenda') && str_contains($h, '<dialog class="cal-dialog" id="cal-dialog"') && substr_count($h, 'class="cal-daybtn"') === 31 && str_contains($h, 'data-cal-day="2091-05-01"') && str_contains($h, 'data-start="2091-05-01" data-end="2091-05-15"'));
check('the type list has no Payroll cutoff; the fixed cutoff and pay date show without Edit/Remove', !str_contains($h, 'option value="PAYROLL_CUTOFF"') && str_contains($h, 'Payroll cutoff May 4 - 18') && str_contains($h, 'Pay date (cutoff May 4 - 18)') && str_contains($h, 'Fixed payroll schedule'));
check('HR sees both events, titles escaped, with Edit and Remove', str_contains($h, e2($tCut)) && str_contains($h, htmlspecialchars($tMeet, ENT_QUOTES)) && !str_contains($h, '<b>'.$sfx) && str_contains($h, 'value="calendar_delete"') && str_contains($h, 'class="cal-vis">HR only</small>') && str_contains($h, 'class="cal-vis">Everyone</small>'));
$r = $save($hr, ['csrf' => $tok, 'title' => 'Zz bad '.$sfx, 'end_date' => '2091-04-01']);
check('end before start: back to the month, nothing stored, error shown', $r['code'] === 302 && str_contains($r['loc'], 'cal=2091-05') && $count('Zz bad '.$sfx) === 0 && str_contains($hr->req('GET', '/index.php?page=hr-dashboard&cal=2091-05')['body'], 'The end date cannot be before the start date.'));
$r = $save($hr, ['title' => 'Zz nocsrf '.$sfx]);
check('no CSRF token: 419 and nothing stored', $r['code'] === 419 && $count('Zz nocsrf '.$sfx) === 0);

$e = $staff->req('GET', '/index.php?page=employee-dashboard&cal=2091-05')['body'];
$staffTok = $csrfOf($staff->req('GET', '/index.php?page=change-password')['body']);
check('Employee dashboard shows the employee-visible cutoff only', str_contains($e, 'id="hd-cal-title"') && str_contains($e, e2($tCut)) && !str_contains($e, 'Zz meeting'));
check('Employee dashboard has no add, edit or remove controls', !str_contains($e, 'calendar_save') && !str_contains($e, 'calendar_delete') && !str_contains($e, 'Add event'));
$r = $save($staff, ['csrf' => $staffTok, 'title' => 'Zz staff '.$sfx]);
check('an Employee-portal user cannot add an event (403) and nothing is stored', $r['code'] === 403 && $count('Zz staff '.$sfx) === 0);
$id = (int)$pdo->query('SELECT id FROM calendar_events WHERE title='.$pdo->quote($tCut))->fetchColumn();
$r = $staff->req('POST', '/index.php?page=hr-dashboard', ['csrf' => $staffTok, 'action' => 'calendar_delete', 'id' => $id, 'cal' => '2091-05']);
check('an Employee-portal user cannot remove an event (403)', $r['code'] === 403 && $count($tCut) === 1);

$r = $hr->req('POST', '/index.php?page=hr-dashboard', ['csrf' => $tok, 'action' => 'calendar_delete', 'id' => $id, 'cal' => '2091-05']);
check('HR removes the cutoff: soft deleted and gone from both dashboards', $r['code'] === 302 && $count($tCut) === 0 && (int)$pdo->query('SELECT COUNT(*) FROM calendar_events WHERE id='.$id.' AND deleted_at IS NOT NULL')->fetchColumn() === 1 && !str_contains($staff->req('GET', '/index.php?page=employee-dashboard&cal=2091-05')['body'], e2($tCut)));
// ---- migration 040: meeting link + invited guests ----
$uOther = $mkUser('EMPLOYEE', 'other'); $other = new Browser($port); $other->login($uOther['email'], $pw);
$r = $hr->req('GET', '/index.php?page=calendar-people&q=Zz%20staff');
$j = json_decode($r['body'], true);
check('guest search: HR gets names and branch only (JSON)', $r['code'] === 200 && str_contains($r['head'], 'application/json') && in_array($uStaff['id'], array_column($j['people'] ?? [], 'id'), true) && array_keys(($j['people'] ?? [[]])[0]) === ['id', 'name', 'branch']);
check('guest search is refused for an Employee-portal user', $staff->req('GET', '/index.php?page=calendar-people&q=Zz')['code'] === 403);
$tAnn = 'Zz announcement '.$sfx;
$r = $hr->req('POST', '/index.php?page=hr-dashboard&cal=2091-05', ['csrf' => $tok, 'action' => 'calendar_save', 'cal' => '2091-05', 'title' => $tAnn, 'event_type' => 'MEETING', 'start_date' => '2091-05-12', 'meeting_url' => 'https://meet.google.com/abc-defg-hij', 'audience' => 'GUESTS', 'guests' => [$uStaff['id']]]);
check('HR saves a guests-only meeting with a Google Meet link', $r['code'] === 302 && $count($tAnn) === 1);
$e = $staff->req('GET', '/index.php?page=employee-dashboard&cal=2091-05')['body'];
check('the guest sees it with a Join meeting link that opens safely in a new tab', str_contains($e, e2($tAnn)) && (bool)preg_match('#<a class="btn sm cal-join" href="https://meet.google.com/abc-defg-hij" target="_blank" rel="noopener noreferrer">#', $e));
$o = $other->req('GET', '/index.php?page=employee-dashboard&cal=2091-05')['body'];
check('an employee who is not a guest does not see it', !str_contains($o, e2($tAnn)) && !str_contains($o, 'meet.google.com'));
$h = $hr->req('GET', '/index.php?page=hr-dashboard&cal=2091-05')['body'];
check('HR sees who is invited and the edit form keeps the guest', str_contains($h, 'Invited guests only (1): Zz staff') && str_contains($h, 'name="guests[]" value="'.$uStaff['id'].'"'));
$r = $hr->req('POST', '/index.php?page=hr-dashboard&cal=2091-05', ['csrf' => $tok, 'action' => 'calendar_save', 'cal' => '2091-05', 'title' => 'Zz js '.$sfx, 'event_type' => 'MEETING', 'start_date' => '2091-05-12', 'meeting_url' => 'javascript:alert(1)', 'audience' => 'ALL']);
check('a javascript: link is refused and nothing is stored', $r['code'] === 302 && $count('Zz js '.$sfx) === 0);
$log = preg_replace("/^.*Module \"openssl\" is already loaded.*$/m", "", (string)@file_get_contents($tmp."/server.log")); // shortcut: this laptop loads openssl twice at startup; only that line is ignored
check("no PHP warnings or fatals during the run", !preg_match("/PHP (Warning|Fatal|Notice|Deprecated)/", $log));
exit($fail ? 1 : 0);
