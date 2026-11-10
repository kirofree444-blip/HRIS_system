<?php
declare(strict_types=1);
// Leave routes to immediate supervisor (Manager for Head Office, ADL for branch) first, then HR. Synthetic data; run on a scratch copy.
require __DIR__.'/ticket_test_support.php';
$f=fixtures();

// fixtures() does not link manager_employee_id or grant leave.approve_hr; wire them for this test.
db()->prepare('UPDATE employees SET manager_employee_id=? WHERE id=?')->execute([$f['manager_employee'],$f['requester']]);
db()->prepare('UPDATE employees SET manager_employee_id=? WHERE id=?')->execute([$f['adl_employee'],$f['branch_requester']]);
$leaveHrPerm=row('SELECT id FROM permissions WHERE code=?',['leave.approve_hr']);
db()->prepare('INSERT INTO role_permissions(role_id,permission_id) VALUES(?,?)')->execute([$f['hr']['role_id'],$leaveHrPerm['id']]);

$vl=row('SELECT id,requires_credit FROM leave_types WHERE code=?',['VL']);
foreach([$f['requester'],$f['branch_requester']] as $empId) {
    db()->prepare('INSERT INTO employee_leave_balances(employee_id,leave_type_id,balance) VALUES(?,?,10) ON DUPLICATE KEY UPDATE balance=10')->execute([$empId,$vl['id']]);
}

function fileLeave(int $empId,int $leaveTypeId,string $tag): int {
    return PayrollRepository::createLeaveRequest($empId,['leave_type_id'=>$leaveTypeId,'date_from'=>'2027-03-01','date_to'=>'2027-03-02','reason'=>'Test '.$tag]);
}

// Head Office employee (account: $f['employee']) -> routes to the Manager (account: $f['manager']).
act($f['employee']);
$hoReqId=fileLeave($f['requester'],(int)$vl['id'],'ho');
$hoReq=row('SELECT status FROM leave_requests WHERE id=?',[$hoReqId]);
$hoStep1=row('SELECT approver_type,approver_user_id FROM leave_request_approvals WHERE request_id=? AND step_no=1',[$hoReqId]);
check('Head Office leave routes to Manager',$hoReq['status']==='FOR_MANAGER_APPROVAL'&&$hoStep1['approver_type']==='MANAGER'&&(int)$hoStep1['approver_user_id']===(int)$f['manager']['id']);
check('Manager gets a bell notification on file',(bool)row('SELECT id FROM notifications WHERE recipient_user_id=? AND dedupe_key=?',[$f['manager']['id'],'leave-approval:'.$hoReqId.':1']));

// Branch employee (account: $f['outsider']) -> routes to the ADL (account: $f['adl']).
act($f['outsider']);
$brReqId=fileLeave($f['branch_requester'],(int)$vl['id'],'branch');
$brReq=row('SELECT status FROM leave_requests WHERE id=?',[$brReqId]);
$brStep1=row('SELECT approver_type,approver_user_id FROM leave_request_approvals WHERE request_id=? AND step_no=1',[$brReqId]);
check('Branch leave routes to ADL',$brReq['status']==='FOR_ADL_APPROVAL'&&$brStep1['approver_type']==='ADL'&&(int)$brStep1['approver_user_id']===(int)$f['adl']['id']);
check('ADL gets a bell notification on file',(bool)row('SELECT id FROM notifications WHERE recipient_user_id=? AND dedupe_key=?',[$f['adl']['id'],'leave-approval:'.$brReqId.':1']));

// Wrong approver cannot act.
act($f['adl']);
check('ADL cannot approve a request routed to the Manager',rejects(fn()=>PayrollRepository::decideLeave($hoReqId,'APPROVE')));
act($f['manager']);
check('Manager cannot approve a request routed to the ADL',rejects(fn()=>PayrollRepository::decideLeave($brReqId,'APPROVE')));

// Correct supervisor approves -> HR step, HR notified.
db()->prepare("DELETE FROM notifications WHERE kind='LEAVE_APPROVAL'")->execute();
act($f['manager']);
PayrollRepository::decideLeave($hoReqId,'APPROVE');
$hoReq=row('SELECT status,current_step FROM leave_requests WHERE id=?',[$hoReqId]);
check('After Manager approval, leave waits for HR',$hoReq['status']==='FOR_HR_LEAVE_APPROVAL'&&(int)$hoReq['current_step']===2);
check('HR gets notified only after the Manager step clears',(bool)row('SELECT id FROM notifications WHERE recipient_user_id=? AND dedupe_key=?',[$f['hr']['id'],'leave-approval-hr:'.$hoReqId.':'.$f['hr']['id']]));

act($f['adl']);
PayrollRepository::decideLeave($brReqId,'APPROVE');
check('HR gets notified after the ADL step clears too',(bool)row('SELECT id FROM notifications WHERE recipient_user_id=? AND dedupe_key=?',[$f['hr']['id'],'leave-approval-hr:'.$brReqId.':'.$f['hr']['id']]));

// HR approves -> APPROVED, credit deducted. SIL is forced to a single day (date_to posted as 2027-03-02 but ignored), so 1 day only.
act($f['hr']);
PayrollRepository::decideLeave($hoReqId,'APPROVE');
$hoReq=row('SELECT status,date_from,date_to,days FROM leave_requests WHERE id=?',[$hoReqId]);
$bal=row('SELECT balance FROM employee_leave_balances WHERE employee_id=? AND leave_type_id=?',[$f['requester'],$vl['id']]);
check('SIL is forced to a single day regardless of the posted To date',$hoReq['date_from']===$hoReq['date_to']&&(float)$hoReq['days']===1.0);
check('HR approval finalizes the leave and deducts credit',$hoReq['status']==='APPROVED'&&abs((float)$bal['balance']-9.0)<0.001);

// Date rules: SIL/other types cannot be backdated; Unpaid Leave can.
$paternity=row('SELECT id FROM leave_types WHERE code=?',['PATERNITY']);
$unpaid=row('SELECT id FROM leave_types WHERE code=?',['UNPAID']);
act($f['employee']);
check('A non-Unpaid type rejects a past date',rejects(fn()=>PayrollRepository::createLeaveRequest($f['requester'],['leave_type_id'=>$paternity['id'],'date_from'=>'2020-01-01','date_to'=>'2020-01-01','reason'=>'Backdated'])));
$unpaidId=PayrollRepository::createLeaveRequest($f['requester'],['leave_type_id'=>$unpaid['id'],'date_from'=>'2020-01-01','date_to'=>'2020-01-02','reason'=>'Backdated unpaid']);
check('Unpaid Leave accepts a past date',$unpaidId>0);

finish();
