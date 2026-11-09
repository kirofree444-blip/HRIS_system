<?php
declare(strict_types=1);
// Payroll Ticket Review option A (John 2026-11-07): "Verify & send to approver" sends one ticket straight to its Manager/ADL.
// Synthetic rows on a scratch copy. The form must carry exactly what payroll_v2_bulk_verify validates.
require __DIR__.'/ticket_test_support.php';
require_once dirname(__DIR__).'/app/View.php';
require_once dirname(__DIR__).'/app/PayrollModule.php';
require_once dirname(__DIR__).'/app/PayrollTicketViews.php';
$f=fixtures();
db()->prepare('UPDATE employees SET manager_employee_id=? WHERE id=?')->execute([$f['manager_employee'],$f['requester']]);
act($f['employee']);
$type=row('SELECT * FROM payroll_request_types WHERE code="TA"');
db()->prepare('UPDATE payroll_request_types SET requires_attachment=0 WHERE id=?')->execute([$type['id']]);
try{$id=PayrollRepository::createPayrollRequest($f['requester'],['request_type_id'=>$type['id'],'affected_date'=>date('Y-m-d'),'reason'=>'Synthetic send-one','time_in'=>'08:00']);}
finally{db()->prepare('UPDATE payroll_request_types SET requires_attachment=? WHERE id=?')->execute([$type['requires_attachment'],$type['id']]);}
$r=PayrollRepository::payrollRequest($id);
$approval=row('SELECT * FROM payroll_request_approvals WHERE request_id=? AND step_no=? AND status="PENDING"',[$id,(int)$r['current_step']]);
check('fixture: the ticket waits for HR verification',$r['status']==='FOR_HR_TIMEKEEPING_APPROVAL'&&$approval['approver_type']==='HR_TIMEKEEPING');

// 1. the HR detail panel has the one-click form with the fields the bulk handler checks
act($f['hr']);ob_start();payroll_ticket_review_detail($r,true,$approval);$html=ob_get_clean();
preg_match('#<form method="post" class="hr-review-send" data-hr-send-one[^>]*>(.*?)</form>#s',$html,$m);
$form=$m[1]??'';
check('HR panel shows Verify & send to approver as the main action',$form!==''&&str_contains($form,'Verify &amp; send to approver')&&str_contains($form,'btn primary'));
$val=static fn(string $name)=>preg_match('#name="'.preg_quote($name,'#').'" value="([^"]*)"#',$form,$x)?$x[1]:null;
check('the form posts payroll_v2_bulk_verify for this ticket only, marked reviewed, with its current HR step',$val('action')==='payroll_v2_bulk_verify'&&$val('ticket_ids[]')===(string)$id&&$val('reviewed['.$id.']')==='1'&&$val('hr_steps['.$id.']')===(string)$r['current_step']&&str_contains($form,'name="csrf"'));
check('the confirm names the approver',(bool)preg_match('#data-payroll-confirm="Verify this request and send it to [^"]+\?"#',$m[0]??''));
check('Mark reviewed stays as a secondary action for batching',str_contains($html,'data-hr-mark-reviewed="'.$id.'"')&&str_contains($html,'Mark reviewed (send later)'));
check('a panel for a ticket not at the HR step has no send form',(function()use($r){ob_start();payroll_ticket_review_detail($r,false,null);return !str_contains((string)ob_get_clean(),'data-hr-send-one');})());

// 2. what the handler does with those fields: the ticket reaches the Manager's Approvals queue
PayrollRepository::decidePayroll($id,'APPROVE','Reviewed by HR; verified in the selected ticket batch.',(int)$val('hr_steps['.$id.']'));
check('after sending, the ticket waits for the Manager',PayrollRepository::payrollRequest($id)['status']==='FOR_MANAGER_APPROVAL');
act($f['manager']);
check('the assigned Manager now sees it in Approvals',in_array($id,array_map('intval',array_column(PayrollRepository::managerQueue(),'id')),true));
// 2b. bell + Approvals badge for the assigned Manager (option C, John 2026-11-07)
$mgrUid=(int)$f['manager']['id'];$step2=(int)PayrollRepository::payrollRequest($id)['current_step'];
$n=row('SELECT * FROM notifications WHERE recipient_user_id=? AND kind="PAYROLL_APPROVAL" AND dedupe_key=?',[$mgrUid,'payroll-approval:'.$id.':'.$step2]);
check('the assigned Manager gets one unread bell notification linked to the ticket',$n&&$n['read_at']===null&&$n['link']==='index.php?page=payroll-request&id='.$id&&str_contains($n['title'],'Payroll ticket to approve')&&$n['email_to']===null);
check('nobody else is notified for this ticket',(int)row('SELECT COUNT(*) c FROM notifications WHERE kind="PAYROLL_APPROVAL" AND dedupe_key LIKE ?',['payroll-approval:'.$id.':%'])['c']===1);
act($f['manager']);
check('the Manager sees a Team Approvals badge',(NotificationService::unreadSummary($mgrUid)['sidebar_counts']['manager-approvals']??0)>=1);
act($f['employee']);
check('the employee gets no Approvals badge from it',!isset(NotificationService::unreadSummary((int)$f['employee']['id'])['sidebar_counts']['manager-approvals']));
act($f['hr']);
check('sending the same ticket again is refused (no double send)',rejects(fn()=>PayrollRepository::decidePayroll($id,'APPROVE','again',(int)$val('hr_steps['.$id.']'))));
act($f['manager']);PayrollRepository::decidePayroll($id,'APPROVE','Synthetic manager approval');
check('once the Manager decides, the bell row is read and the badge clears',row('SELECT read_at FROM notifications WHERE id=?',[(int)$n['id']])['read_at']!==null&&!isset(NotificationService::unreadSummary($mgrUid)['sidebar_counts']['manager-approvals']));
act($f['hr']);

// 3. handler contract and the stale-page guard in the browser script
$mod=(string)file_get_contents(dirname(__DIR__).'/app/PayrollModule.php');$js=(string)file_get_contents(dirname(__DIR__).'/public/assets/payroll.js');
check('the bulk handler still requires reviewed[id]=1 and a numeric HR step',str_contains($mod,"(\$reviewed[\$id]??'')!=='1'")&&str_contains($mod,"ctype_digit((string)\$steps[\$id])"));
check('a changed request also disables the send button',str_contains($js,"body.querySelector('[data-hr-send-one] button'); if (sendOne) sendOne.disabled = true;"));
finish();
