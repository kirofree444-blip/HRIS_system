<?php
declare(strict_types=1);
// Remarks column of the attendance export: a fully complete day should be blank, not "Complete" noise.
// No bootstrap/DB needed -- exercises PayrollCutoffDocuments::notes() directly via reflection.
require_once dirname(__DIR__).'/app/PayrollCutoffDocuments.php';

$pass = 0; $fail = 0;
function check(string $label, bool $ok): void { global $pass, $fail; echo ($ok ? 'PASS ' : 'FAIL ').$label."\n"; $ok ? $pass++ : $fail++; }

$ref = new ReflectionMethod(PayrollCutoffDocuments::class, 'notes');
$ref->setAccessible(true);
$call = fn(array $day) => $ref->invoke(null, $day);

check('complete day with nothing else is blank', $call(['state' => 'COMPLETE', 'sources_json' => '[]']) === '');
check('corrected day with nothing else is blank', $call(['state' => 'CORRECTED', 'sources_json' => '[]']) === '');
check('complete day with TA mark+note drops the "Complete" prefix', $call([
    'state' => 'COMPLETE',
    'export_marks' => ['time_in' => 'TA'],
    'export_note' => 'PTR-20261110-0002',
    'sources_json' => '[]',
]) === 'TA approved / PTR-20261110-0002');
check('absent day still shows its label', $call(['state' => 'ABSENT', 'sources_json' => '[]']) === 'Absent');
check('missing punches still reported even without the Complete label', $call([
    'state' => 'COMPLETE',
    'export_missing' => ['Break Out'],
    'sources_json' => '[]',
]) === 'Missing: Break Out');

echo "RESULT $pass passed, $fail failed\n";
