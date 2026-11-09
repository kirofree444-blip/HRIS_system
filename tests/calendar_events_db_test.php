<?php
declare(strict_types=1);
// dashboard-calendar (migration 039, John 2026-11-07). Synthetic rows only; scratch copy with 039 applied.
require __DIR__.'/ticket_test_support.php';
$uid=(int)db()->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
$_SESSION['user']=['id'=>$uid];
$base=['title'=>'Zz cutoff','event_type'=>'COMPANY_EVENT','start_date'=>'2091-03-01','end_date'=>'2091-03-15','visible_to_employees'=>'1'];
$month=static fn(string $ym,bool $emp)=>array_column(CalendarEvents::forMonth(CalendarEvents::month($ym),$emp),'id');

// 1. save, range and month overlap
$id=CalendarEvents::save($base,$uid);
$r=row('SELECT * FROM calendar_events WHERE id=?',[$id]);
check('save stores the event with its range and author',$r['title']==='Zz cutoff'&&$r['start_date']==='2091-03-01'&&$r['end_date']==='2091-03-15'&&(int)$r['created_by']===$uid&&(int)$r['visible_to_employees']===1);
$span=CalendarEvents::save(['title'=>'Zz span','event_type'=>'COMPANY_EVENT','start_date'=>'2091-02-26','end_date'=>'2091-03-02'],$uid);
check('an event crossing months shows in both months',in_array($span,$month('2091-02',false),true)&&in_array($span,$month('2091-03',false),true)&&!in_array($span,$month('2091-04',false),true));
$one=CalendarEvents::save(['title'=>'Zz pay','event_type'=>'PAY_DATE','start_date'=>'2091-03-20','end_date'=>''],$uid);
check('a blank end date means the same day',row('SELECT end_date FROM calendar_events WHERE id=?',[$one])['end_date']==='2091-03-20');
$meet=CalendarEvents::save(['title'=>'Zz HR meeting','event_type'=>'MEETING','start_date'=>'2091-03-05','start_time'=>'14:00','end_time'=>'15:00'],$uid);
check('times are stored and the event is HR-only when the box is not ticked',($m=row('SELECT start_time,end_time,visible_to_employees FROM calendar_events WHERE id=?',[$meet]))&&$m['start_time']==='14:00:00'&&$m['end_time']==='15:00:00'&&(int)$m['visible_to_employees']===0);

// 2. validation refuses and stores nothing
$count=static fn()=>(int)row('SELECT COUNT(*) c FROM calendar_events')['c'];$before=$count();
check('end before start is refused',rejects(fn()=>CalendarEvents::save(['end_date'=>'2091-02-28']+$base,$uid)));
check('an unknown type is refused',rejects(fn()=>CalendarEvents::save(['event_type'=>'PAYROLL']+$base,$uid)));
check('Payroll cutoff can no longer be typed in (it is fixed)',rejects(fn()=>CalendarEvents::save(['event_type'=>'PAYROLL_CUTOFF']+$base,$uid)));
check('an impossible date (Feb 30) is refused',rejects(fn()=>CalendarEvents::save(['start_date'=>'2091-02-30','end_date'=>'']+$base,$uid)));
check('a blank or 121-character title is refused',rejects(fn()=>CalendarEvents::save(['title'=>'  ']+$base,$uid))&&rejects(fn()=>CalendarEvents::save(['title'=>str_repeat('a',121)]+$base,$uid)));
check('a bad time, or an end time without a start time, is refused',rejects(fn()=>CalendarEvents::save(['start_time'=>'25:00']+$base,$uid))&&rejects(fn()=>CalendarEvents::save(['end_time'=>'10:00']+$base,$uid)));
check('notes over 500 characters are refused',rejects(fn()=>CalendarEvents::save(['notes'=>str_repeat('n',501)]+$base,$uid)));
check('nothing was stored by the refused saves',$count()===$before);

// 3. employee view: only visible, never deleted, no author columns
$emp=CalendarEvents::forMonth(CalendarEvents::month('2091-03'),true);
check('employee view hides HR-only events',!in_array($meet,array_column($emp,'id'),true)&&in_array($id,array_column($emp,'id'),true));
check('employee view carries no author columns',$emp&&!array_key_exists('created_by',$emp[0])&&!array_key_exists('updated_by',$emp[0]));

// 4. update and soft delete
CalendarEvents::save(['id'=>$id,'title'=>'Zz 1st cutoff','visible_to_employees'=>'']+$base,$uid);
$r=row('SELECT title,visible_to_employees,updated_by FROM calendar_events WHERE id=?',[$id]);
check('edit updates the same row (no duplicate)',$r['title']==='Zz 1st cutoff'&&(int)$r['visible_to_employees']===0&&(int)$r['updated_by']===$uid&&$count()===$before);
check('an edit that untick employees removes it from the employee view',!in_array($id,$month('2091-03',true),true));
CalendarEvents::remove($one,$uid);
check('remove is a soft delete and hides the event everywhere',($d=row('SELECT deleted_at,deleted_by FROM calendar_events WHERE id=?',[$one]))&&$d['deleted_at']!==null&&(int)$d['deleted_by']===$uid&&!in_array($one,$month('2091-03',false),true));
check('removing twice or editing a removed event is refused',rejects(fn()=>CalendarEvents::remove($one,$uid))&&rejects(fn()=>CalendarEvents::save(['id'=>$one]+$base,$uid)));
check('audit has create, update and remove rows',(int)row('SELECT COUNT(DISTINCT action) c FROM audit_logs WHERE record_type="calendar_event" AND record_id IN (?,?) AND action IN ("CALENDAR_EVENT_CREATED","CALENDAR_EVENT_UPDATED","CALENDAR_EVENT_REMOVED")',[$id,$one])['c']===3);

// 5. month parameter
check('a bad ?cal value falls back to this month',CalendarEvents::month("2091-13")->format('Y-m')===date('Y-m')&&CalendarEvents::month("x' OR 1")->format('Y-m')===date('Y-m')&&CalendarEvents::month('2091-03')->format('Y-m-d')==='2091-03-01');
// 6. fixed payroll schedule: cutoff 4-18 paid on the 25th, 19-3 paid on the 10th of the next month
$fx=static fn(string $ym)=>array_map(fn($r)=>$r['event_type'].' '.$r['start_date'].' '.$r['end_date'],array_values(array_filter(CalendarEvents::forMonth(CalendarEvents::month($ym),true),fn($r)=>!empty($r['fixed']))));
check('May shows cutoffs Apr 19-May 3, May 4-18, May 19-Jun 3 and pay dates May 10 and May 25',$fx('2091-05')===['PAYROLL_CUTOFF 2091-04-19 2091-05-03','PAYROLL_CUTOFF 2091-05-04 2091-05-18','PAY_DATE 2091-05-10 2091-05-10','PAYROLL_CUTOFF 2091-05-19 2091-06-03','PAY_DATE 2091-05-25 2091-05-25']);
check('the schedule crosses the year (Dec 19 - Jan 3 paid Jan 10)',in_array('PAYROLL_CUTOFF 2091-12-19 2092-01-03',$fx('2092-01'),true)&&in_array('PAY_DATE 2092-01-10 2092-01-10',$fx('2092-01'),true));
check('fixed rows are shown to employees and have no id to edit',($r=CalendarEvents::payrollSchedule(CalendarEvents::month('2091-05'))[0])&&$r['id']===0&&$r['visible_to_employees']===1);
// 7. meeting link (migration 040): http(s) only
$lk=CalendarEvents::save(['title'=>'Zz zoom','event_type'=>'MEETING','start_date'=>'2091-07-01','meeting_url'=>'https://zoom.us/j/123?pwd=x','audience'=>'ALL'],$uid);
check('an https meeting link is stored and shown to employees',($r=row('SELECT meeting_url,visible_to_employees FROM calendar_events WHERE id=?',[$lk]))&&$r['meeting_url']==='https://zoom.us/j/123?pwd=x'&&(int)$r['visible_to_employees']===1&&in_array($lk,$month('2091-07',true),true));
check('javascript:, data:, ftp: and bare words are refused as links',rejects(fn()=>CalendarEvents::save(['meeting_url'=>'javascript:alert(1)']+$base,$uid))&&rejects(fn()=>CalendarEvents::save(['meeting_url'=>'data:text/html,x']+$base,$uid))&&rejects(fn()=>CalendarEvents::save(['meeting_url'=>'ftp://x.test/a']+$base,$uid))&&rejects(fn()=>CalendarEvents::save(['meeting_url'=>'zoom meeting']+$base,$uid)));
// 8. invited guests only
$g1=insert('INSERT INTO users(role_id,full_name,email,password_hash,status) VALUES((SELECT id FROM roles WHERE code="EMPLOYEE"),?,?,?,"ACTIVE")',['Zz Guest One','zz_g1_'.bin2hex(random_bytes(3)).'@example.test','x']);
$g2=insert('INSERT INTO users(role_id,full_name,email,password_hash,status) VALUES((SELECT id FROM roles WHERE code="EMPLOYEE"),?,?,?,"ACTIVE")',['Zz Guest Two','zz_g2_'.bin2hex(random_bytes(3)).'@example.test','x']);
$off=insert('INSERT INTO users(role_id,full_name,email,password_hash,status) VALUES((SELECT id FROM roles WHERE code="EMPLOYEE"),?,?,?,"INACTIVE")',['Zz Inactive','zz_g3_'.bin2hex(random_bytes(3)).'@example.test','x']);
$ann=CalendarEvents::save(['title'=>'Zz announcement','event_type'=>'COMPANY_EVENT','start_date'=>'2091-07-02','audience'=>'GUESTS','guests'=>[(string)$g1,(string)$g1]],$uid);
$emp=static fn(int $who)=>array_column(CalendarEvents::forMonth(CalendarEvents::month('2091-07'),true,$who),'id');
check('a guests-only event is seen by its guest and not by another employee',in_array($ann,$emp($g1),true)&&!in_array($ann,$emp($g2),true)&&(int)row('SELECT COUNT(*) c FROM calendar_event_guests WHERE event_id=?',[$ann])['c']===1);
check('HR sees the guests-only event with its guest list; employees never get the list',($h=array_values(array_filter(CalendarEvents::forMonth(CalendarEvents::month('2091-07'),false),fn($r)=>$r['id']==$ann))[0]??null)&&$h['guests']===[['id'=>$g1,'name'=>'Zz Guest One']]&&!array_key_exists('guests',array_values(array_filter(CalendarEvents::forMonth(CalendarEvents::month('2091-07'),true,$g1),fn($r)=>$r['id']==$ann))[0]));
$before=$count();
check('guests-only with no guest, or with an inactive or unknown account, is refused and stores nothing',rejects(fn()=>CalendarEvents::save(['audience'=>'GUESTS','guests'=>[]]+$base,$uid))&&rejects(fn()=>CalendarEvents::save(['audience'=>'GUESTS','guests'=>[$g1,$off]]+$base,$uid))&&rejects(fn()=>CalendarEvents::save(['audience'=>'GUESTS','guests'=>[999999999]]+$base,$uid))&&rejects(fn()=>CalendarEvents::save(['audience'=>'SOME']+$base,$uid))&&$count()===$before);
CalendarEvents::save(['id'=>$ann,'title'=>'Zz announcement','event_type'=>'COMPANY_EVENT','start_date'=>'2091-07-02','audience'=>'GUESTS','guests'=>[$g2]],$uid);
check('editing the guest list replaces it (old guest loses access, new guest gains it)',!in_array($ann,$emp($g1),true)&&in_array($ann,$emp($g2),true));
CalendarEvents::save(['id'=>$ann,'title'=>'Zz announcement','event_type'=>'COMPANY_EVENT','start_date'=>'2091-07-02','audience'=>'HR','guests'=>[$g2]],$uid);
check('switching to HR only clears the guests and hides it from them',(int)row('SELECT COUNT(*) c FROM calendar_event_guests WHERE event_id=?',[$ann])['c']===0&&!in_array($ann,$emp($g2),true));
finish();
