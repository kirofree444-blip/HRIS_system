<?php
declare(strict_types=1);
// adl-areas-only-for-adl (SPEC-adl-areas-only-for-adl.md, John 2026-11-07: "dapat ADL lang"). Synthetic rows on a scratch copy.
require __DIR__.'/ticket_test_support.php';
$f=fixtures();$emp=$f['requester'];
$pos=static fn(string $name)=>(int)(row('SELECT id FROM positions WHERE UPPER(TRIM(name))=? ORDER BY id LIMIT 1',[strtoupper($name)])['id']??0);
$adlPos=$pos('Area Development Leader');$scPos=$pos('Sales Captain');
$areas=array_map('intval',array_column(db()->query('SELECT id FROM areas WHERE active=1 ORDER BY id LIMIT 2')->fetchAll(),'id'));
check('fixtures: an ADL position, a Sales Captain position and two active areas exist',$adlPos>0&&$scPos>0&&count($areas)===2);
[$a1,$a2]=$areas;

// 1. who counts as ADL (same rule as isAdlSql: Directory role first, else the position)
check('ADL position with Automatic role is an ADL',EmployeeDirectoryPlacement::isAdl('',$adlPos));
check('Sales Captain with Automatic role is not an ADL',!EmployeeDirectoryPlacement::isAdl('',$scPos)&&!EmployeeDirectoryPlacement::isAdl('',0));
check('Directory role ADL makes any position an ADL; Employees/Department Managers override an ADL position',EmployeeDirectoryPlacement::isAdl('ADL',$scPos)&&!EmployeeDirectoryPlacement::isAdl('EMPLOYEE',$adlPos)&&!EmployeeDirectoryPlacement::isAdl('DEPARTMENT_MANAGER',$adlPos));

// 2. saving: a non-ADL never changes stored areas; an ADL saves and may clear
$save=static function(int $position,string $role,array $ids)use($emp):string{
    $old=row('SELECT * FROM employees WHERE id=?',[$emp]);db()->beginTransaction();
    try{EmployeeDirectoryPlacement::save($emp,['directory_fields_present'=>true,'directory_company_group'=>'','directory_role'=>$role,'directory_area_ids'=>$ids,'position_id'=>$position],$old);db()->commit();}
    catch(Throwable $t){db()->rollBack();throw $t;}
    return (string)row('SELECT directory_area_ids v FROM employees WHERE id=?',[$emp])['v'];
};
db()->prepare('UPDATE employees SET directory_area_ids=? WHERE id=?')->execute([','.$a1.',',$emp]);
check('non-ADL save with the box hidden (no areas posted) keeps the stored area',$save($scPos,'',[])===','.$a1.',');
check('non-ADL save cannot add areas even if they are posted',$save($scPos,'',[(string)$a2])===','.$a1.',');
check('Directory role Employees on an ADL position also keeps the stored area',$save($adlPos,'EMPLOYEE',[])===','.$a1.',');
$both=','.min($a1,$a2).','.max($a1,$a2).',';
check('an ADL (by position) saves the chosen areas',$save($adlPos,'',[(string)$a1,(string)$a2])===$both);
check('an ADL by Directory role saves areas too',$save($scPos,'ADL',[(string)$a2])===','.$a2.',');
check('an ADL can clear all areas',$save($adlPos,'',[])==='');
check('ADL area changes are still audited',(int)row('SELECT COUNT(*) c FROM audit_logs WHERE action="CHANGE_ADL_AREAS" AND record_id=?',[$emp])['c']>=3);

// 3. the edit form: box only for ADLs, hidden AND disabled otherwise, live toggle on Position and Directory role
$src=file_get_contents(dirname(__DIR__).'/index.php');
check('the areas box is rendered hidden and disabled for non-ADLs',str_contains($src,'$isAdlNow=EmployeeDirectoryPlacement::isAdl(')&&str_contains($src,"<?=\$isAdlNow?'':' hidden disabled'?>"));
check('the form toggles the box when Position or Directory role changes',str_contains($src,"role.addEventListener('change',sync);pos.addEventListener('change',sync);")&&str_contains($src,'box.hidden=!on;box.disabled=!on'));
check('CSS keeps a hidden box hidden',str_contains((string)file_get_contents(dirname(__DIR__).'/public/assets/employee-directory.css'),'.directory-assigned-areas[hidden]{display:none}'));
finish();
