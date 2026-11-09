<?php
declare(strict_types=1);
// Attendance Export by company in John's payroll template layout (2026-11-07): one choice per company, the Ocampo's group
// companies merged as "Ocampos", and the Excel is one "Timekeep Summary" sheet like PAYROLL SEP 19- OCT 3, 2026_OCAMPOS.xlsx.
// Scratch copy of real data (cutoff 2026-10-19, which has clock times); currentReport rebuilds the scratch cutoff's daily rows, as the page does. The saved cutoff is never filtered.
require __DIR__.'/ticket_test_support.php';
require_once dirname(__DIR__).'/app/View.php';
require_once dirname(__DIR__).'/app/PayrollModule.php';
$admin=row('SELECT u.id,u.full_name name,u.email,r.code role_code,r.portal role_portal,r.name role_name,u.role_id FROM users u JOIN roles r ON r.id=u.role_id WHERE r.code="SUPER_ADMIN" AND u.status="ACTIVE" ORDER BY u.id LIMIT 1');
act($admin+['client_id'=>null]);

// 1. choices: Ocampos = every OC-group company; other companies one by one
$companies=PayrollCutoffDocuments::companies();
$ocNames=array_column(db()->query('SELECT bu.name FROM business_units bu WHERE bu.company_group="OC" AND EXISTS(SELECT 1 FROM employees e WHERE e.business_unit_id=bu.id)')->fetchAll(),'name');
$labels=array_column($companies,'label');
check('one "Ocampos" choice holds every Ocampo\'s-group company (Almandite, Artchic, Cryssoberyl, Ocampo\'s Ventures, Ocampo\'s)',isset($companies['OC'])&&$companies['OC']['label']==='Ocampos'&&count($ocNames)>=5&&!array_diff($ocNames,$companies['OC']['names'])&&!array_diff($companies['OC']['names'],$ocNames));
check('no Ocampo\'s-group company is listed on its own; SW companies like 2L.O.G. still are',!array_intersect($ocNames,$labels)&&in_array('2L.O.G., INC.',$labels,true));

// 2. filter
$only=new ReflectionMethod(PayrollCutoffDocuments::class,'onlyCompany');$only->setAccessible(true);
$cutoff=(int)row('SELECT id FROM payroll_cutoffs WHERE period_start="2026-10-19"')['id'];
$saved=PayrollCutoffExportService::currentReport($cutoff);$all=count($saved['employees']);
$oc=$only->invoke(null,$saved,$companies['OC']['names'],'Ocampos');
$ocIn=array_values(array_unique(array_map(fn($e)=>$e['company_name'],$oc['employees'])));sort($ocIn);
check('Ocampos keeps every Ocampo\'s-group employee and nobody else',count($oc['employees'])>0&&!array_diff($ocIn,$ocNames)&&count($ocIn)>1&&!array_filter($oc['rows'],fn($d)=>!in_array($d['_employee']['company_name'],$ocNames,true)));
check('Ocampos count equals the OC-group employees in the cutoff',count($oc['employees'])===count(array_filter($saved['employees'],fn($e)=>in_array($e['company_name'],$ocNames,true))));
$sw=$only->invoke(null,$saved,['2L.O.G., INC.'],'2L.O.G., INC.');
check('a single company (2L.O.G.) keeps only its employees; no all-company Source Summary',array_values(array_unique(array_map(fn($e)=>$e['company_name'],$sw['employees'])))===['2L.O.G., INC.']&&!isset($sw['source_gaps'])&&count($saved['employees'])===$all);
check('a company with nobody in the cutoff is refused with a clear message',(function()use($only,$saved){try{$only->invoke(null,$saved,['Nobody Inc.'],'Nobody Inc.');return false;}catch(RuntimeException $e){return str_contains($e->getMessage(),'No employees of Nobody Inc.');}})());

// 3. the Excel file in the template layout
$excel=new ReflectionMethod(PayrollCutoffDocuments::class,'excel');$excel->setAccessible(true);
$xf=new ReflectionMethod(PayrollCutoffDocuments::class,'tkXf');$xf->setAccessible(true);$s=fn(int $num,bool $bold,int $fill,bool $center=false,bool $border=true)=>(string)$xf->invoke(null,$num,$bold,$fill,$center,$border);
$file=tempnam(sys_get_temp_dir(),'zzx');$excel->invoke(null,$file,$oc,$saved['run'],['employee_count'=>count($oc['employees'])]+$saved['report_meta'],true);
$zip=new ZipArchive();$zip->open($file);$wb=(string)$zip->getFromName('xl/workbook.xml');$sheet=(string)$zip->getFromName('xl/worksheets/sheet1.xml');$ps=(string)$zip->getFromName('xl/worksheets/sheet2.xml');$bill=(string)$zip->getFromName('xl/worksheets/sheet3.xml');$seg=(string)$zip->getFromName('xl/worksheets/sheet4.xml');$sum=(string)$zip->getFromName('xl/worksheets/sheet5.xml');$ci=(string)$zip->getFromName('xl/worksheets/sheet6.xml');$billB=(string)$zip->getFromName('xl/worksheets/sheet7.xml');$styles=(string)$zip->getFromName('xl/styles.xml');$sheets=0;
for($i=0;$i<$zip->numFiles;$i++)if(str_starts_with($zip->getNameIndex($i),'xl/worksheets/'))$sheets++;
$zip->close();@unlink($file);
check('seven sheets in template order, no SSS Table sheet (John 2026-11-07)',$sheets===7&&(bool)preg_match('/<sheets><sheet name="Timekeep Summary"[^>]*\/><sheet name="Payroll Summary"[^>]*\/><sheet name="Billing Sheet"[^>]*\/><sheet name="Sales Seg"[^>]*\/><sheet name="Summary Sheet"[^>]*\/><sheet name="CI"[^>]*\/><sheet name="Billing B"[^>]*\/><\/sheets>/',$wb)&&!str_contains($wb,'SSS Table'));
check('rows 1-9 empty; header on row 10 with the template columns (Code .. LEGHRD, no extra OT column)',!preg_match('/<row r="[1-9]"/',$sheet)&&str_contains($sheet,'<c r="A10" s="'.$s(0,true,1,true).'" t="inlineStr"><is><t xml:space="preserve">Code</t>')&&str_contains($sheet,'<c r="AR10"')&&!str_contains($sheet,'<c r="AS10"')&&str_contains($sheet,'>Postion<'));
check('Date header spans I:J and Training spans S:T',str_contains($sheet,'<mergeCell ref="I10:J10"/>')&&str_contains($sheet,'<mergeCell ref="S10:T10"/>'));
check('employee info row first (row 11: code, LAST, FIRST name, company), days start on row 12 with the full day name',(bool)preg_match('/<row r="11">.*<c r="B11" s="'.$s(0,true,1).'" t="inlineStr"><is><t xml:space="preserve">[^<,]+, [^<]+<\/t>/U',$sheet)&&str_contains($sheet,'<c r="I12" s="'.$s(1,false,6).'">')&&(bool)preg_match('/<c r="J12" s="'.$s(0,false,6).'" t="inlineStr"><is><t xml:space="preserve">(Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday)<\/t>/',$sheet));
check('template formulas on each day: Late Hours and TOTAL',str_contains($sheet,'<c r="W12" s="'.$s(3,false,2).'"><f>ROUND(((Q12+R12)/60),2)</f></c>')&&str_contains($sheet,'<c r="Y12" s="'.$s(3,false,3).'"><f>ROUND((U12*8-W12-X12),2)</f></c>'));
$days=count(array_unique(array_column($oc['rows'],'work_date')));$tot=12+$days+1;// info 11, days 12.., blank, TOTAL
check('TOTAL row after a blank row: label over I:J and ROUND(SUM()) for Q..AR (not V)',str_contains($sheet,'<mergeCell ref="I'.$tot.':J'.$tot.'"/>')&&str_contains($sheet,'<c r="Q'.$tot.'" s="'.$s(3,true,2).'"><f>ROUND((SUM(Q12:Q'.($tot-2).')),2)</f></c>')&&str_contains($sheet,'<c r="AR'.$tot.'"')&&!preg_match('/<c r="V'.$tot.'"[^>]*><f>/',$sheet));
check('next employee starts two rows after TOTAL',count($oc['employees'])<2||(bool)preg_match('/<row r="'.($tot+3).'"><c r="A'.($tot+3).'"/',$sheet));
check('Days Present is 8 (hours, as in the template) only on days with clock times',preg_match('/<c r="U\d+" s="'.$s(3,false,1).'"><v>8<\/v>/',$sheet)&&!preg_match('/<c r="U\d+" s="'.$s(3,false,1).'"><v>(?!8<)/',$sheet));
$colNo=static function(string $ref):int{$n=0;foreach(str_split(preg_replace('/\d+/','',$ref)) as $ch)$n=$n*26+ord($ch)-64;return $n;};
$ordered=static function(string $x)use($colNo):bool{foreach(preg_split('/<\/row>/',$x) as $row){preg_match_all('/<c r="([A-Z]+)\d+"/',$row,$m);$n=array_map($colNo,$m[1]);$o=$n;sort($o);if($n!==$o||count($n)!==count(array_unique($n)))return false;}return true;};
$colours=['FFFFF68F','FFFF6A6A','FFFF9912','FFCD96CD','FF00CD66','FF63B8FF','FFE3A869','FFFFEC8B','FFEE3B3B'];
check('template look: Tahoma 8, thin borders, template colours, hidden columns as in the template, cells in column order',str_contains($styles,'<name val="Tahoma"/>')&&str_contains($styles,'<border><left style="thin">')&&count(array_filter($colours,fn($c)=>str_contains($styles,$c)))===count($colours)&&substr_count($styles,'<xf ')===539&&str_contains($styles,'<cellXfs count="538">')&&str_contains($sheet,'<col min="4" max="4" width="18.5" hidden="1"')&&!str_contains($sheet,'<col min="5" max="5" width="24" hidden')&&$ordered($sheet)&&$ordered($ps)&&$ordered($bill)&&$ordered($seg)&&$ordered($sum)&&$ordered($ci)&&$ordered($billB));

// 4. Payroll Summary (John 2026-11-07, BR-800)
$T="'Timekeep Summary'!";
$has=fn(string $at,string $formula)=>(bool)preg_match('/<c r="'.$at.'" s="\d+"><f>'.preg_quote(htmlspecialchars($formula,ENT_XML1|ENT_QUOTES),'/').'<\/f><\/c>/',$ps);
check('headers on rows 8-10 like the template (Other Earnings / DEDUCTIONS bands, multipliers row)',str_contains($ps,'<mergeCell ref="BL8:CH8"/>')&&str_contains($ps,'>D E D U C T I O N S<')&&str_contains($ps,'>NET PAY<')&&str_contains($ps,'<c r="AN10" s="'.$s(3,false,4,true).'"><v>1.25</v>')&&str_contains($ps,'<c r="BC10" s="'.$s(3,false,7,true).'"><v>2.6</v>'));
check('employee row reads the Timekeep Summary: name from the info row, hours and OT from the TOTAL row',$has('F11',$T.'B11')&&$has('G11',$T.'E11')&&$has('N11',$T.'U'.$tot)&&$has('O11',$T.'W'.$tot)&&$has('R11',$T.'AB'.$tot)&&$has('AH11',$T.'AR'.$tot));
check('template pay formulas: Monthly/Hourly from Daily, Basic = Hourly x hours, OT 125%, Gross and NET PAY',$has('H11','+J11*30')&&$has('K11','+J11/8')&&$has('AI11','K11*N11')&&$has('AL11','ROUND(AI11-SUM(AJ11:AK11),2)')&&$has('AN11','ROUND(R11*K11*125%,2)')&&$has('BD11','ROUND(AH11*K11*160%,2)')&&$has('BK11','SUM(BF11:BJ11)')&&$has('BU11','ROUND(BK11-SUM(BL11:BT11),2)')&&$has('CJ11','+BU11-CI11'));
check('no rate in Payroll Setup: Daily = 755 default',str_contains($ps,'<c r="J11" s="'.$s(5,true,0).'"><v>755</v>'));
$cfg=require dirname(__DIR__).'/config/payroll_summary.php';$lk='{'.implode(',',array_column($cfg['sss_table'],0)).'},{'.implode(',',array_column($cfg['sss_table'],12)).'}';
check('SSS = EE share of John\'s bracket for the Total Gross Pay (brackets inside the formula); PhilHealth = 2.5% of Total Basic; Pag-IBIG 100 (none when the cutoff has no pay)',$has('BR11',"IF(BK11<=0,0,LOOKUP(BK11,$lk))")&&str_starts_with($lk,'{0,5250,5750,')&&str_ends_with($lk,',1725,1750}')&&$has('BS11','ROUND(AL11*2.5%,2)')&&$has('BT11','IF(BK11<=0,0,100)'));
check('adjustments, loans, EC, DISCRE and SMART are blank input cells',!preg_match('/<c r="(BH|BI|BJ|BL|BM|BN|BO|BP|BQ|BV|BW)11" s="\d+">/',$ps));
preg_match_all('/<row r="(\d+)"><c r="M\1" s="'.$s(3,true,0,false,false).'"><f>ROUND\(\(SUM\(([^)]*)\)\),2\)<\/f>/',$ps,$sub);$last=end($sub[2]);
check('a subtotal per branch, then one grand total of the subtotals',count($sub[0])>=2&&count(array_unique(array_column($oc['employees'],'branch_name')))===count($sub[0])-1&&substr_count((string)$last,',')===count($sub[0])-2);
$t=$cfg['sss_table'];$ok=count($t)===61&&$t[0][0]===0;for($i=1;$i<61;$i++)if($t[$i][0]<=$t[$i-1][0]||($i>1&&abs($t[$i][0]-$t[$i-1][1]-0.01)>0.001)||abs($t[$i][12]-$t[$i][6]-$t[$i][10])>0.001)$ok=false;
check('SSS config table is consistent: brackets ascend with no gaps, EE share = EE1 + MPF-EE on every row',$ok);
$pe=(int)$oc['rows'][0]['employee_id'];$rid=0;
try{db()->prepare('INSERT INTO payroll_employee_rates(employee_id,effective_from,effective_to,salary_basis,salary_amount,hourly_divisor) VALUES(?,?,NULL,"DAILY",812.5,8)')->execute([$pe,$saved['run']['period_start']]);$rid=(int)db()->lastInsertId();
 $dr=new ReflectionMethod(PayrollCutoffDocuments::class,'dailyRates');$dr->setAccessible(true);$got=$dr->invoke(null,[$pe,$pe+999999],$saved['run']['period_start'],$saved['run']['period_end']);}
finally{if($rid)db()->prepare('DELETE FROM payroll_employee_rates WHERE id=?')->execute([$rid]);}
check('an employee rate in Payroll Setup replaces the 755 default for that employee only',($got??[])===[$pe=>812.5]);

// 4b. Billing Sheet, Billing B, Sales Seg, Summary Sheet, CI (John 2026-11-07, BR-801): template formulas on the Payroll Summary rows
$in=fn(string $x,string $at,string $formula)=>(bool)preg_match('/<c r="'.$at.'" s="\d+"><f>'.preg_quote(htmlspecialchars($formula,ENT_XML1|ENT_QUOTES),'/').'<\/f><\/c>/',$x);$P="'Payroll Summary'!";
check('Billing Sheet: earnings from the Payroll Summary row, Direct Cost = 60% of earnings, ADMIN 10%, VAT 12% of ADMIN, TOTAL BILLING',$in($bill,'F11',$P.'F11')&&$in($bill,'M11',$P.'Q11')&&$in($bill,'N11',$P.'R11')&&$in($bill,'AE11','+K11*+M11')&&$in($bill,'AF11','ROUND(N11*K11*125%,2)')&&$in($bill,'AY11','SUM(AE11:AX11)')&&$in($bill,'BD11','SUM(AY11:BC11)*0.6')&&$in($bill,'BE11','ROUND(BD11*10%,2)')&&$in($bill,'BG11','ROUND(BE11*12%,2)')&&$in($bill,'BI11',$P.'BG11')&&$in($bill,'BK11','SUM(BH11:BJ11)'));
check('Billing B: same sheet with 40% share and ADMIN 5%',$in($billB,'BD11','SUM(AY11:BC11)*0.4')&&$in($billB,'BE11','ROUND(BD11*5%,2)')&&$in($billB,'AE11','+K11*+M11'));
$g=(int)end($sub[1]);
check('Billing sheets total per branch and grand total on the same rows as the Payroll Summary',$in($bill,'BK'.$g,'ROUND((SUM('.implode(',',array_map(fn($r)=>'BK'.$r,array_slice($sub[1],0,-1))).')),2)')&&$in($billB,'BD'.$g,'ROUND((SUM('.implode(',',array_map(fn($r)=>'BD'.$r,array_slice($sub[1],0,-1))).')),2)'));
check('Sales Seg: basic, 13th = (Basic+Excess)/12, billed = Billing Sheet TOTAL BILLING, admin/VAT from the Billing Sheet, Other Income',$in($seg,'J11','+H11*I11')&&$in($seg,'U11','(J11+M11)/12')&&$in($seg,'X11',"'Billing Sheet'!BK11")&&$in($seg,'AE11',"'Billing Sheet'!BE11")&&$in($seg,'AG11','X11-SUM(Y11:AF11)')&&str_contains($seg,'>BASED ON BILLING<'));
$partial=0;foreach($oc['rows'] as $d){$k=count(array_filter(array_intersect_key($d,array_flip(PayrollCalculator::FIELDS))));if($k>0&&$k<4)$partial++;}
check('Summary Sheet: branch title row, then per employee hours, late/UT from the Payroll Summary and every day with missing punches listed (need OB/TA)',$in($sum,'A11',$P.'G11')&&$in($sum,'A12',$P.'E11')&&$in($sum,'C12',$P.'N11')&&$in($sum,'N12',$P.'O11')&&$in($sum,'J12','ROUND((SUM(C12,D12,E12,H12,I12)),2)')&&$partial>0&&substr_count($sum,'-no ')===$partial);
check('CI: bill to the company, earnings from each billing grand total, admin 3%, VAT 12%, EWT 2%; right invoice totals amounts (template slip fixed)',str_contains($ci,'>Ocampos<')&&$in($ci,'E14',"'Billing Sheet'!AE".$g)&&$in($ci,'Q14',"'Billing B'!AE".$g)&&$in($ci,'G30',"'Billing Sheet'!BA".$g)&&$in($ci,'G38','ROUND(+G37*0.03,2)')&&$in($ci,'G39','ROUND(+G38*0.12,2)')&&$in($ci,'G46','ROUND(G38*0.02,2)*-1')&&$in($ci,'S27','ROUND(SUM(Q14:Q24),2)')&&$in($ci,'S48','ROUND(+S41+S46,2)'));

// 5. wiring
$view=(string)file_get_contents(dirname(__DIR__).'/app/PayrollAttendanceExportViews.php');$mod=(string)file_get_contents(dirname(__DIR__).'/app/PayrollModule.php');
check('the page lists choices by label with All companies first',str_contains($view,'<select id="export-company" name="company_id"><option value="">All companies</option>')&&str_contains($view,"e(\$choice['label'])"));
check('company_id is carried by links, forms, the download route and its error/final redirects (OC or a number only)',str_contains($view,"+\$companyArg)")&&substr_count($view,'name="company_id" value="<?=e($companyId)?>"')===2&&substr_count($mod,'payroll_company_arg(')>=4&&payroll_company_arg(['company_id'=>'OC'])===['company_id'=>'OC']&&payroll_company_arg(['company_id'=>"1 OR 1"])===[]&&payroll_company_arg(['company_id'=>['x']])===[]);
check('an unknown company is refused before anything is built',(function()use($cutoff){try{PayrollCutoffDocuments::download($cutoff,'xlsx',true,'999999999');}catch(RuntimeException $e){return $e->getMessage()==='Choose a company from the list.';}return false;})());
finish();
