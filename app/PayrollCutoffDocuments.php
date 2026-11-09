<?php
declare(strict_types=1);

/** Standalone downloads: native XLSX (ZipArchive) and PDF, without office software. */
final class PayrollCutoffDocuments
{
    public static function sections(array $saved): array
    {
        $sections=[];
        foreach($saved['rows'] as $day) {
            $key=$day['_employee']['export_section'];
            $sections[$key][(int)$day['employee_id']][]=$day;
        }
        ksort($sections,SORT_NATURAL|SORT_FLAG_CASE);
        foreach($sections as &$employees) uasort($employees,static fn($a,$b)=>strcasecmp($a[0]['_employee']['employee_name'],$b[0]['_employee']['employee_name']));
        unset($employees);return $sections;
    }

    /**
     * Export company choices: key => [label, Company Names]. Companies (business units) with employees; every company in the
     * Ocampo's group (OC: Almandite, Artchic, Cryssoberyl, Ocampo's Ventures, Ocampo's) is one choice "Ocampos" (John 2026-11-07).
     */
    public static function companies(): array
    {
        if(!FoundationRepository::tableExists('business_units') || !FoundationRepository::columnExists('employees','business_unit_id')) return [];
        $out=[];$oc=[];$group=FoundationRepository::columnExists('business_units','company_group')?'bu.company_group':'""';
        foreach(db()->query('SELECT bu.id,bu.name,'.$group.' grp FROM business_units bu WHERE EXISTS(SELECT 1 FROM employees e WHERE e.business_unit_id=bu.id) ORDER BY bu.name')->fetchAll() as $row) {
            if($row['grp']==='OC')$oc[]=(string)$row['name']; else $out[(string)$row['id']]=['label'=>(string)$row['name'],'names'=>[(string)$row['name']]];
        }
        if($oc)$out['OC']=['label'=>'Ocampos','names'=>$oc];
        uasort($out,static fn($a,$b)=>strcasecmp($a['label'],$b['label']));
        return $out;
    }

    /** Keep only the chosen company's employees (by Company Name on the saved row). The saved cutoff itself is never changed. */
    private static function onlyCompany(array $saved,array $names,string $company): array
    {
        $saved['rows']=array_values(array_filter($saved['rows'],static fn($d)=>in_array((string)($d['_employee']['company_name']??''),$names,true)));
        $saved['employees']=array_filter($saved['employees'],static fn($e)=>in_array((string)($e['company_name']??''),$names,true));
        unset($saved['source_gaps']);$saved['company_label']=$company;// CI "BILL TO"// the source summary covers every company, so it is left out of a one-company file
        if(!$saved['rows']) throw new RuntimeException('No employees of '.$company.' are in this cutoff.');
        return $saved;
    }

    public static function download(int $cutoffId,string $format,bool $forReview=false,string $companyKey=''): never
    {
        Auth::requirePermission('payroll.export');
        if(!in_array($format,['xlsx','pdf'],true))throw new RuntimeException('Choose Excel or PDF.');
        $company=null;
        if($companyKey!==''){$choice=self::companies()[$companyKey]??null;if($choice===null)throw new RuntimeException('Choose a company from the list.');$company=$choice['label'];}
        if($forReview) {
            $saved=PayrollCutoffExportService::currentReport($cutoffId);$run=$saved['run'];$batch=$saved['report_meta'];
        } else {
            $saved=PayrollCutoffExportService::saved($cutoffId);$run=PayrollAttendanceService::run($cutoffId);$batch=PayrollCutoffExportService::batch($cutoffId);
        }
        if($company!==null){$saved=self::onlyCompany($saved,$choice['names'],$company);$batch['employee_count']=count($saved['employees']);}
        $file=tempnam(sys_get_temp_dir(),'hris-cutoff-');
        if($file===false)throw new RuntimeException('The server could not create the download. Check its temporary folder.');
        try {
            if($format==='xlsx') self::excel($file,$saved,$run,$batch,$company!==null); else self::pdf($file,$saved,$run,$batch);
            audit('Payroll',$forReview?'DOWNLOAD_ATTENDANCE_FOR_REVIEW':'DOWNLOAD_COMPLETE_ATTENDANCE','payroll_cutoff',$cutoffId,['format'=>$format,'content_hash'=>$batch['content_hash']??null,'employees'=>$batch['employee_count'],'for_review'=>$forReview,'company'=>$company]);
            header('Content-Type: '.($format==='xlsx'?'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet':'application/pdf'));
            header('Content-Disposition: attachment; filename="PMBSI-Attendance-'.($forReview?'For-Review-':'').$run['period_start'].'-'.$run['period_end'].($company!==null?'-'.trim(preg_replace('/[^A-Za-z0-9]+/','-',$company),'-'):'').'.'.$format.'"');
            header('Cache-Control: no-store, private');header('X-Content-Type-Options: nosniff');header('Content-Length: '.filesize($file));
            readfile($file);
        } finally {if(is_file($file))unlink($file);}
        exit;
    }

    public static function stateLabel(string $state): string
    {
        return match($state){'COMPLETE','CORRECTED'=>'Complete','APPROVED_OB'=>'OB','APPROVED_LEAVE'=>'Leave','REST_DAY'=>'Rest day','NO_WORK'=>'No work','ABSENT'=>'Absent','NOT_EMPLOYED'=>'Before start date','ZERO_CREDIT'=>'Reviewed: no work credit',default=>ucwords(strtolower(str_replace('_',' ',$state)))};
    }

    private static function notes(array $day): string
    {
        $notes=[$day['export_day_label']??self::stateLabel($day['state'])];
        if(!empty($day['export_missing']))$notes[]='Missing: '.implode(', ',$day['export_missing']);
        foreach($day['export_review_notes']??[] as $note)$notes[]=$note;
        foreach(array_unique(array_values($day['export_marks']??[])) as $type)$notes[]=$type.' approved';
        if(!empty($day['export_note']))$notes[]=$day['export_note'];
        foreach(json_decode($day['sources_json'],true)?:[] as $source) {
            if(!empty($source['no']))$notes[]=$source['no'];
            if(($source['type']??'')==='ZERO_CREDIT')$notes[]=(string)($source['note']??'');
        }
        return implode(' / ',array_filter(array_unique($notes)));
    }

    private static function xml(string $value): string
    {
        $value=preg_replace('/[^\x09\x0A\x0D\x20-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u','',$value)??'';
        return htmlspecialchars($value,ENT_XML1|ENT_QUOTES,'UTF-8');
    }

    private static function column(int $number): string
    {
        $result='';for(;$number>0;$number=intdiv($number-1,26))$result=chr(65+($number-1)%26).$result;return $result;
    }

    private static function cell(int $column,int $row,mixed $value,int $style=0,bool $number=false): string
    {
        if($value===null||$value==='')return '';
        $at=self::column($column).$row;
        return '<c r="'.$at.'" s="'.$style.'"'.($number?'':' t="inlineStr"').'>'.($number?'<v>'.(string)$value.'</v>':'<is><t xml:space="preserve">'.self::xml((string)$value).'</t></is>').'</c>';
    }

    private static function dateSerial(string $date): int
    {
        return (int)(new DateTimeImmutable('1899-12-30'))->diff(new DateTimeImmutable(substr($date,0,10)))->format('%r%a');
    }

    private static function timeSerial(string $stamp): float
    {
        $time=new DateTimeImmutable($stamp);return ((int)$time->format('H')*3600+(int)$time->format('i')*60+(int)$time->format('s'))/86400;
    }

    private static function sheetName(array $employee,array &$used): string
    {
        $name=($employee['export_workplace']==='HO'?'HO ':'').($employee['export_company']?:'NA').' '.($employee['export_workplace']==='RETAIL'?($employee['area_code']?:$employee['area_name']).' - '.($employee['branch_code']?:$employee['branch_name']):$employee['export_department']);
        $name=trim(str_replace(['\\','/',':','*','?','[',']'], '-', $name)," '\t\r\n");
        $base=mb_substr($name?:'To assign',0,31);$name=$base;$n=1;
        while(isset($used[mb_strtolower($name)]))$name=mb_substr($base,0,26).' '.(++$n);
        $used[mb_strtolower($name)]=true;return $name;
    }

    private static function excel(string $file,array $saved,array $run,array $batch,bool $timekeep=false): void
    {
        if(!class_exists('ZipArchive'))throw new RuntimeException('Enable the PHP zip extension to download Excel files.');
        $zip=new ZipArchive();if($zip->open($file,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true)throw new RuntimeException('Could not prepare the Excel file.');
        try {
            $types='<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
            $workbook='<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
            $relations='<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';$used=[];$i=0;
            if(!empty($saved['source_gaps'])) {
                $i++;$used['source summary']=true;
                $types.='<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
                $workbook.='<sheet name="Source Summary" sheetId="1" r:id="rId1"/>';
                $relations.='<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>';
                if(!$zip->addFromString('xl/worksheets/sheet1.xml',self::summarySheet($saved['source_gaps'],$saved['employees'],$run,$batch)))throw new RuntimeException('Could not write the Excel summary worksheet.');
            }
            if($timekeep) {// one company: John's payroll template sheets; Payroll Summary formulas read the Timekeep Summary TOTAL rows
                $index=[];$layout=[];$tk=self::timekeepSheet($saved,$index);$ps=self::payrollSheet($index,$run,$layout);$cfg=require dirname(__DIR__).'/config/payroll_summary.php';
                $sheets=['Timekeep Summary'=>$tk,'Payroll Summary'=>$ps,'Billing Sheet'=>self::billingSheet($layout,$cfg['billing_sheet']),'Sales Seg'=>self::salesSegSheet($layout),'Summary Sheet'=>self::attendanceSummarySheet($layout),'CI'=>self::ciSheet($layout,$run,(string)($saved['company_label']??''),$cfg['ci']),'Billing B'=>self::billingSheet($layout,$cfg['billing_b'])];// template order
                foreach($sheets as $name=>$content) {
                    $i++;
                    $types.='<Override PartName="/xl/worksheets/sheet'.$i.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
                    $workbook.='<sheet name="'.$name.'" sheetId="'.$i.'" r:id="rId'.$i.'"/>';
                    $relations.='<Relationship Id="rId'.$i.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$i.'.xml"/>';
                    if(!$zip->addFromString('xl/worksheets/sheet'.$i.'.xml',$content))throw new RuntimeException('Could not write the '.$name.' worksheet.');
                }
            }
            foreach($timekeep?[]:self::sections($saved) as $section=>$employees) {
                $i++;$employee=reset($employees)[0]['_employee'];$name=self::sheetName($employee,$used);
                $types.='<Override PartName="/xl/worksheets/sheet'.$i.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
                $workbook.='<sheet name="'.self::xml($name).'" sheetId="'.$i.'" r:id="rId'.$i.'"/>';
                $relations.='<Relationship Id="rId'.$i.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$i.'.xml"/>';
                if(!$zip->addFromString('xl/worksheets/sheet'.$i.'.xml',self::worksheet($section,$employees,$run,$batch)))throw new RuntimeException('Could not write an Excel worksheet.');
            }
            $relations.='<Relationship Id="styles" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
            $parts=['[Content_Types].xml'=>$types.'</Types>','_rels/.rels'=>'<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="workbook" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>', 'xl/workbook.xml'=>$workbook.'</sheets><calcPr calcId="191029" fullCalcOnLoad="1"/></workbook>','xl/_rels/workbook.xml.rels'=>$relations,'xl/styles.xml'=>self::styles()];
            foreach($parts as $path=>$content)if(!$zip->addFromString($path,$content))throw new RuntimeException('Could not finish the Excel file.');
        } catch(Throwable $e){$zip->close();throw $e;}
        if(!$zip->close())throw new RuntimeException('Could not save the Excel download.');
    }

    /** Which departments / branches have employees with no biometric logs, so the missing files are obvious. */
    private static function summarySheet(array $gaps,array $employees,array $run,array $batch): string
    {
        $xml='<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetFormatPr defaultRowHeight="20"/><cols><col min="1" max="1" width="48" customWidth="1"/><col min="2" max="4" width="16" customWidth="1"/><col min="5" max="5" width="34" customWidth="1"/></cols><sheetData>';
        $r=1;$xml.='<row r="1" ht="26" customHeight="1">'.self::cell(1,1,'PMBSI - Biometric source summary / FOR REVIEW',8).'</row>';
        $r++;$xml.='<row r="'.$r.'">'.self::cell(1,$r,$run['period_start'].' to '.$run['period_end'].' / Generated: '.$batch['generated_at']).'</row>';
        $r++;$xml.='<row r="'.$r.'">'.self::cell(1,$r,'Yellow = no biometric log received for any employee in that group. Request or import that file; nothing is assumed absent.').'</row>';
        $r+=2;$xml.='<row r="'.$r.'" ht="30" customHeight="1">';
        foreach(['Group / Department / Branch','Employees','With logs','Without logs','Status'] as $c=>$h)$xml.=self::cell($c+1,$r,$h,1);
        $xml.='</row>';
        foreach($gaps['sections'] as $section=>$count) {
            $r++;$missing=$count['total']-$count['with_logs'];$none=$count['with_logs']===0;
            $status=$none?'NO LOGS RECEIVED':($missing?'PARTIAL - '.$missing.' employees without logs':'Complete');
            $style=$none?9:($missing?2:0);
            $xml.='<row r="'.$r.'">'.self::cell(1,$r,$section,$style).self::cell(2,$r,$count['total'],$style,true).self::cell(3,$r,$count['with_logs'],$style,true).self::cell(4,$r,$missing,$style,true).self::cell(5,$r,$status,$style).'</row>';
        }
        $r+=2;$xml.='<row r="'.$r.'" ht="30" customHeight="1">';
        foreach(['Employees without biometric logs','Code','','','Group'] as $c=>$h)if($h!=='')$xml.=self::cell($c+1,$r,$h,1);
        $xml.='</row>';
        foreach($gaps['no_log_employees'] as $id) {
            $e=$employees[$id]??null;if(!$e)continue;$r++;
            $xml.='<row r="'.$r.'">'.self::cell(1,$r,$e['employee_name']).self::cell(2,$r,$e['employee_no']?:'Code to follow').self::cell(5,$r,$e['export_section']).'</row>';
        }
        return $xml.'</sheetData><pageMargins left="0.3" right="0.3" top="0.4" bottom="0.4" header="0.2" footer="0.2"/><pageSetup paperSize="9" orientation="portrait"/></worksheet>';
    }

    private static function worksheet(string $section,array $employees,array $run,array $batch): string
    {
        $headers=['Code','Name','Date Hired','Company','Branch','Department','Position','COLA','Date','Day','C-In','B-Out','B-In','C-Out','Remarks','Schedule',"Late\n(min)","Late\nin Between", "Training\nDays / Amount",'',"Days\nPresent",'',"Late\nHours","UT\nHours",'TOTAL','Excess','Offset','ROT','EROT','RND','NDOT','RD','RDOT','RDND','SOT','ESOT','SPHD','SPHOT','SPHND','SPHRD','LEGHD','LEGHWRK','LEGHOT','LEGHRD','Approved OT (h)'];
        $xml='<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane xSplit="2" ySplit="10" topLeftCell="C11" activePane="bottomRight" state="frozen"/></sheetView></sheetViews><sheetFormatPr defaultRowHeight="22"/><cols>';
        foreach([1=>16,2=>30,3=>15,4=>22,5=>20,6=>25,7=>25,8=>10,9=>16,10=>12,11=>13,12=>13,13=>13,14=>13,15=>55,16=>24] as $c=>$width)$xml.='<col min="'.$c.'" max="'.$c.'" width="'.$width.'" customWidth="1"/>';
        $xml.='<col min="17" max="45" width="13" customWidth="1"/></cols><sheetData>';
        $review=!empty($batch['for_review']);
        $headings=[1=>'PMBSI - Payroll Timekeep / '.($review?'FOR REVIEW':'Complete Attendance'),2=>$run['period_start'].' to '.$run['period_end'],3=>$section,4=>'Generated: '.$batch['generated_at'].' / '.count($employees).' employees',5=>'Yellow: approved TA / OB clock changes and matched approved OT',6=>$review?'FOR REVIEW: missing times stay blank. Pending requests are not applied. Cutoff status stays the same.':'Blank pay columns stay available for Payroll. Attendance hours do not calculate salary.'];
        if($review)$headings[7]=$batch['notice'];
        foreach($headings as $r=>$label)$xml.='<row r="'.$r.'" ht="26" customHeight="1">'.self::cell(1,$r,$label,$r===1?8:0).'</row>';
        $xml.='<row r="10" ht="42" customHeight="1">';foreach($headers as $c=>$value)$xml.=self::cell($c+1,10,$value,1);$xml.='</row>';$r=10;
        foreach($employees as $days) {
            foreach($days as $n=>$day) {
                $r++;$e=$day['_employee'];$cells='';
                if($n===0) {
                    foreach([1=>$e['employee_no']?:'Code to follow',2=>$e['employee_name']] as $c=>$v)$cells.=self::cell($c,$r,$v,2);
                    $cells.=!empty($e['hire_date'])?self::cell(3,$r,self::dateSerial($e['hire_date']),3,true):self::cell(3,$r,'To follow',2);
                    foreach([4=>$e['company_name']?:'Company to assign',5=>trim($e['branch_code'].' / '.$e['branch_name'],' /'),6=>$e['export_department'],7=>$e['position_name']] as $c=>$v)$cells.=self::cell($c,$r,$v,2);
                }
                $cells.=self::cell(9,$r,self::dateSerial($day['work_date']),3,true).self::cell(10,$r,date('D',strtotime($day['work_date'])));
                foreach(PayrollCalculator::FIELDS as $c=>$field)if(!empty($day[$field]))$cells.=self::cell(11+$c,$r,self::timeSerial($day[$field]),isset($day['export_marks'][$field])?5:4,true);
                $note=self::notes($day);$cells.=self::cell(15,$r,$note,!empty($day['export_marks'])?9:0);
                $site=(json_decode($day['rate_snapshot_json'],true)?:[])['site']??[];
                if(!empty($site['shift_start'])&&!empty($site['shift_end']))$cells.=self::cell(16,$r,date('g:i A',strtotime($site['shift_start'])).' - '.date('g:i A',strtotime($site['shift_end'])));
                $hasClocks=(bool)array_filter(array_intersect_key($day,array_flip(PayrollCalculator::FIELDS)));
                if($hasClocks) {
                    if(!empty($site['shift_start']))$cells.=self::cell(17,$r,(int)$day['late_minutes'],6,true).self::cell(23,$r,round((int)$day['late_minutes']/60,4),6,true).self::cell(24,$r,round((int)$day['undertime_minutes']/60,4),6,true);
                    // The source template's TOTAL/pay categories stay blank: no assumed payroll formulas.
                }
                $ot=PayrollCutoffExportService::otMinutes($day);if($ot>0)$cells.=self::cell(45,$r,round($ot/60,4),7,true);
                $height=$review?max(34,min(409,10+15*(int)ceil(mb_strlen($note)/48))):34;
                $xml.='<row r="'.$r.'" ht="'.$height.'" customHeight="1">'.$cells.'</row>';
            }
            $r++;$xml.='<row r="'.$r.'" ht="12" customHeight="1"/>';
        }
        $xml.='</sheetData><mergeCells count="'.count($headings).'">';for($n=1;$n<=count($headings);$n++)$xml.='<mergeCell ref="A'.$n.':AS'.$n.'"/>';
        return $xml.'</mergeCells><printOptions gridLines="true"/><pageMargins left="0.3" right="0.3" top="0.4" bottom="0.4" header="0.2" footer="0.2"/><pageSetup paperSize="9" orientation="landscape"/></worksheet>';
    }

    private static function formula(int $column,int $row,string $formula,int $style=6): string
    {
        return '<c r="'.self::column($column).$row.'" s="'.$style.'"><f>'.self::xml($formula).'</f></c>';
    }

    /**
     * John's payroll template, sheet "Timekeep Summary" (PAYROLL SEP 19- OCT 3, 2026_OCAMPOS.xlsx, 2026-11-07): rows 1-9 empty, header on
     * row 10 (Date over I:J, Training over S:T), then per employee an info row, one row per day, a blank row, a TOTAL row and two blank rows.
     * Template formulas kept as-is: Late Hours W=ROUND((Q+R)/60,2), TOTAL Y=ROUND(U*8-W-X,2), TOTAL row = ROUND(SUM(),2) per column.
     * Look copied from the template too: Tahoma 8, thin borders, column-group colours, d-mmm / h:mm / accounting formats, same hidden columns.
     */
    private static function timekeepSheet(array $saved,array &$index=[]): string
    {
        $headers=['Code','Name','Date Hired','Company','Branch','Department','Postion','COLA','Date','','C-In','B-Out','B-In','C-Out','Remarks','Schedule',"Late\n(min)","Late\nin Between","Training\nDays / Amount",'',"Days\nPresent",'',"Late\nHours","UT\nHours",'TOTAL','Excess','Offset','ROT','EROT','RND','NDOT','RD','RDOT','RDND','SOT','ESOT','SPHD','SPHOT','SPHND','SPHRD','LEGHD','LEGHWRK','LEGHOT','LEGHRD'];
        $widths=[12.3,25.8,9.5,18.5,24,10.7,7,5.3,6.8,10.5,5.8,5.8,5.8,5.8,20.8,8.3,8.2,8.5,10.8,2.2,9.2,2.2,6,8.2,10.8,6,5.7,8.2,5,6,6,6.8,8,5.7,4,4.8,6,6.2,6.5,6.3,6.7,9.2,9.3,9.3];
        $hidden=[3,4,6,7,8,20,22,26,27,29,34,35,36,38,39,40,43,44];// hidden in the template; still in the file, unhide in Excel
        $xml='<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView tabSelected="1" workbookViewId="0"><pane xSplit="7" ySplit="10" topLeftCell="H11" activePane="bottomRight" state="frozen"/></sheetView></sheetViews><sheetFormatPr defaultRowHeight="10.5"/><cols>';
        foreach($widths as $i=>$w)$xml.='<col min="'.($i+1).'" max="'.($i+1).'" width="'.$w.'"'.(in_array($i+1,$hidden,true)?' hidden="1"':'').' customWidth="1"/>';
        $xml.='</cols><sheetData>';
        $cells=[];foreach($headers as $c=>$v)$cells[$c+1]=$v;
        $xml.=self::tkRow(10,'h',$cells,'ht="42" customHeight="1"');
        $employees=[];foreach($saved['rows'] as $day)$employees[(int)$day['employee_id']][]=$day;
        $key=static fn(array $x)=>[strtolower((string)$x['branch_name']),strtolower((string)($x['last_name']??$x['employee_name'])),strtolower((string)($x['first_name']??''))];
        uasort($employees,static fn($a,$b)=>$key($a[0]['_employee'])<=>$key($b[0]['_employee']));// by branch, then name, as in the template
        $r=10;$merges=['I10:J10','S10:T10'];
        $sumCols=[17,18,19,20,21,23,24,25,26,27,28,29,30,31,32,33,34,35,36,37,38,39,40,41,42,43,44];// Q..AR except V, as in the template TOTAL row
        foreach($employees as $employeeId=>$days) {
            usort($days,static fn($a,$b)=>strcmp($a['work_date'],$b['work_date']));
            $e=$days[0]['_employee'];$r++;
            $code=(string)($e['employee_no']?:'');$name=isset($e['last_name'])?trim(((string)$e['last_name']).', '.((string)($e['first_name']??'')),', '):(string)$e['employee_name'];// final snapshots keep only employee_name
            $cells=[1=>$code!==''&&ctype_digit($code)?['n'=>(int)$code]:($code!==''?$code:'Code to follow'),2=>$name,4=>$e['company_name']?:'Company to assign',5=>$e['branch_name'],6=>$e['export_department'],7=>$e['position_name']];
            if(!empty($e['hire_date']))$cells[3]=['n'=>self::dateSerial($e['hire_date'])];
            $xml.=self::tkRow($r,'i',$cells);$info=$r;$first=$r+1;$gaps=[];
            foreach($days as $day) {
                $r++;$cells=[9=>['n'=>self::dateSerial($day['work_date'])],10=>date('l',strtotime($day['work_date']))];
                foreach(PayrollCalculator::FIELDS as $c=>$field)if(!empty($day[$field]))$cells[11+$c]=['n'=>self::timeSerial($day[$field]),'mark'=>isset($day['export_marks'][$field])];
                $note=self::notes($day);if($note!=='')$cells[15]=['s'=>$note,'mark'=>!empty($day['export_marks'])];
                $site=(json_decode((string)($day['rate_snapshot_json']??''),true)?:[])['site']??[];
                if(!empty($site['shift_start'])&&!empty($site['shift_end']))$cells[16]=date('g:i A',strtotime($site['shift_start'])).' - '.date('g:i A',strtotime($site['shift_end']));
                $hasClocks=(bool)array_filter(array_intersect_key($day,array_flip(PayrollCalculator::FIELDS)));
                if($hasClocks) {
                    if(!empty($site['shift_start'])){$cells[17]=['n'=>(int)$day['late_minutes']];$cells[24]=['n'=>round((int)$day['undertime_minutes']/60,2)];}
                    $missing=array_keys(array_filter(['time in'=>empty($day['time_in']),'break out'=>empty($day['lunch_out']),'break in'=>empty($day['lunch_in']),'time out'=>empty($day['time_out'])]));
                    if($missing)$gaps[date('m/d',strtotime($day['work_date']))]=implode(';',array_map(static fn($m)=>'no '.$m,$missing)).';';// Summary Sheet: days that need an OB/TA
                    $cells[21]=['n'=>8];// Days Present: 8 (hours) on a day with clock times, as in the template; Payroll Summary Basic = Hourly x this total
                }
                $cells[23]=['f'=>'ROUND(((Q'.$r.'+R'.$r.')/60),2)'];$cells[25]=['f'=>'ROUND((U'.$r.'*8-W'.$r.'-X'.$r.'),2)'];
                $xml.=self::tkRow($r,'d',$cells);
            }
            $last=$r;$r+=2;$cells=[9=>'TOTAL'];
            foreach($sumCols as $c){$col=self::column($c);$cells[$c]=['f'=>'ROUND((SUM('.$col.$first.':'.$col.$last.')),2)'];}
            $xml.=self::tkRow($r,'t',$cells);$merges[]='I'.$r.':J'.$r;
            $index[]=['employee_id'=>(int)$employeeId,'code'=>$code,'branch'=>(string)$e['branch_name'],'info'=>$info,'total'=>$r,'gaps'=>$gaps];$r+=2;
        }
        $xml.='</sheetData><mergeCells count="'.count($merges).'">';foreach($merges as $m)$xml.='<mergeCell ref="'.$m.'"/>';
        return $xml.'</mergeCells><pageMargins left="0.3" right="0.3" top="0.4" bottom="0.4" header="0.2" footer="0.2"/><pageSetup orientation="landscape"/></worksheet>';
    }

    /** One Timekeep Summary row in column order; empty bordered cells are written too so the grid matches the template. Kinds: h header, i info, d day, t TOTAL. */
    private static function tkRow(int $r,string $kind,array $cells,string $attrs=''): string
    {
        $xml='<row r="'.$r.'"'.($attrs!==''?' '.$attrs:'').'>';
        for($c=in_array($kind,['h','i'],true)?1:8;$c<=44;$c++) {
            $v=$cells[$c]??null;$v=is_array($v)?$v:($v===null||$v===''?[]:['s'=>(string)$v]);
            $s=self::tkStyle($kind,$c,!empty($v['mark']));$at=self::column($c).$r;
            if(isset($v['f']))$xml.='<c r="'.$at.'" s="'.$s.'"><f>'.self::xml($v['f']).'</f></c>';
            elseif(isset($v['n']))$xml.='<c r="'.$at.'" s="'.$s.'"><v>'.$v['n'].'</v></c>';
            elseif(isset($v['s']))$xml.='<c r="'.$at.'" s="'.$s.'" t="inlineStr"><is><t xml:space="preserve">'.self::xml($v['s']).'</t></is></c>';
            else $xml.='<c r="'.$at.'" s="'.$s.'"/>';
        }
        return $xml.'</row>';
    }

    /** Template look per row kind and column. Fill: 1 yellow, 2 red, 3 orange, 4 purple, 5 green, 6 blue, 7 tan, 8 bright yellow (approved change). */
    private static function tkStyle(string $kind,int $c,bool $mark): int
    {
        $group=match(true){in_array($c,[17,18,23,24],true)=>2,$c>=19&&$c<=22=>1,$c>=25&&$c<=27=>3,$c>=28&&$c<=31=>4,$c>=32&&$c<=36=>5,$c>=37&&$c<=40=>6,$c>=41=>7,default=>0};
        if($kind==='h')return self::tkXf(0,true,$c<=7?1:$group,true);
        if($kind==='i')return $c<=7?self::tkXf($c===3?4:0,in_array($c,[1,2,4,5],true),1):self::tkXf($c>=17?3:0,false,0);
        if($kind==='t')return in_array($c,[9,10],true)?self::tkXf(0,true,6,true):self::tkXf($c>=17?3:0,true,$group);
        if($c===9)return self::tkXf(1,false,6);
        if($c===10)return self::tkXf(0,false,6);
        if($c>=11&&$c<=15)return self::tkXf($c<=14?2:0,false,$mark?8:0);
        return self::tkXf($c>=17?3:0,false,$group);
    }

    /**
     * Index into the template cellXfs block that styles() appends after the 10 base styles.
     * num: 0 general, 1 d-mmm, 2 h:mm, 3 accounting, 4 mmm d, yyyy, 5 #,##0.00. fill: see tkStyle(), plus 9 gold, 10 deductions red.
     */
    private static function tkXf(int $num,bool $bold,int $fill,bool $center=false,bool $border=true): int
    {
        return 10+(((($num*2+($bold?1:0))*11+$fill)*2+($center?1:0))*2+($border?0:1));
    }

    /**
     * John's template sheet "Payroll Summary" (2026-11-07, BR-800): one row per employee grouped by branch with a subtotal, then a grand
     * total. Every amount is the template's own Excel formula, reading the Timekeep Summary TOTAL rows, so Payroll can still edit inputs.
     * Daily = the employee's rate for the cutoff (Payroll Setup > Employee rates) or the configured default. SSS = EE share looked up by
     * the cutoff Total Gross Pay in John's SSS brackets (config, written into the formula; no SSS sheet, John 2026-11-07); PhilHealth = % of Total Basic; Pag-IBIG = fixed per cutoff (config/payroll_summary.php).
     * Salary/allowance adjustments, loans, EC, DISCRE and SMART stay blank for Payroll to type.
     */
    private static function payrollSheet(array $index,array $run,array &$layout=[]): string
    {
        $cfg=require dirname(__DIR__).'/config/payroll_summary.php';
        $ot=['ROT','EROT','RND','NDOT','RD','RDOT','RDND','SOT','ESOT','SPHD','SPHOT','SPHND','SPHRD','LEGHD','LEGHWRK','LEGHOT','LEGHRD'];
        $pct=['125%','137.5%','10%','12.5%','130%','169%','17%','150%','169%','130%','169%','0','150%','100%','100%','260%','160%'];
        $mult=[1.25,1.375,0.1,0.125,1.3,1.69,0.17,1.5,1.69,1.3,1.69,0,1.5,1,1,2.6,1.6];
        $widths=[1.7,0,1.2,24.3,12.2,25.8,24,10.2,12.2,9.2,8.2,10,7.5,9.2,6.7,5.7,9.2,7.7,5,4.8,5.7,6.7,5.3,5.7,4.7,4.8,5.5,6.2,6.5,6.3,6.5,9,7.2,7.3,11.2,9.2,7.7,11.2,6.7,10.2,8,7.2,7.2,9.2,7.7,5.7,5.5,5.5,5.7,6.2,6.5,6.3,6.5,9,7.2,7.3,10.2,11.2,10.2,10.2,9.2,9.2,12.7,5.5,4.8,4.8,9.7,9.2,4.8,10.2,9.2,9.2,11.2,10.2,7.7,4.8,4.8,4.8,4.8,2.2,2.2,2.2,2.2,2.2,2.2,2.2,13.5,13.3];
        $hidden=[2,4,8,9,12,19,24,25,26,27,28,29,30,31,32,33,34,41,46,47,48,49,50,51,52,53,54,55,56,76,77,78,79,80,81,82,83,84,85,86];// as in the template
        $head=[5=>'Code',6=>'Name',7=>'Branch',8=>'Monthly',9=>'Semi-Monthly',10=>'Daily',11=>'Hourly',12=>'Per Minute',13=>'Training',14=>"Present\ndays",15=>"Late\nhrs",16=>"UT\nhrs",17=>'Total Hrs',35=>'Basic',36=>'Late',37=>'UT',38=>"Total\nBasic",39=>'E-Cola',57=>"Total\nOT",58=>"Cut-off\nTotal",59=>"Training\nDays",60=>'Salary',63=>'Total Gross Pay',64=>'Salary Adj.',67=>"SSS\nLoan",68=>"Pag-ibig\nLoan",69=>'EC',70=>'SSS',71=>'Phic',72=>'HDMF',73=>'Total',74=>'DISCRE',75=>'SMART',87=>'TotalDeduction',88=>'NET PAY'];
        foreach($ot as $k=>$label){$head[18+$k]=$label;$head[40+$k]=$label;}
        $sub=[39=>['n'=>10],59=>['n'=>200],60=>'Basic',61=>'ALLOWANCE',62=>'OT',64=>'Basic',65=>'Cola',66=>'OT'];foreach($mult as $k=>$m)$sub[40+$k]=['n'=>$m];
        $xml='<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane xSplit="12" ySplit="10" topLeftCell="M11" activePane="bottomRight" state="frozen"/></sheetView></sheetViews><sheetFormatPr defaultRowHeight="11.25"/><cols>';
        foreach($widths as $i=>$w)$xml.='<col min="'.($i+1).'" max="'.($i+1).'" width="'.$w.'"'.(in_array($i+1,$hidden,true)?' hidden="1"':'').' customWidth="1"/>';
        $xml.='</cols><sheetData>'.self::psRow(8,'h8',[60=>'Other Earnings',64=>'D E D U C T I O N S']).self::psRow(9,'h9',$head,'ht="22.5" customHeight="1"').self::psRow(10,'h10',$sub);
        $rates=self::dailyRates(array_column($index,'employee_id'),(string)$run['period_start'],(string)$run['period_end']);
        $groups=[];foreach($index as $x)$groups[$x['branch']][]=$x;// Timekeep order is already by branch, then name
        $T="'Timekeep Summary'!";$r=10;$subtotals=[];$layout=['rows'=>[],'subs'=>[],'grand'=>null];
        $sss='{'.implode(',',array_column($cfg['sss_table'],0)).'},{'.implode(',',array_column($cfg['sss_table'],12)).'}';// SSS brackets (FROM) and EE share, inline so no table sheet is needed
        foreach($groups as $people) {
            $first=$r+1;
            foreach($people as $p) {
                $r++;$t=$p['total'];$c=[];$layout['rows'][$r]=$p;
                $c[4]=['f'=>$T.'B'.$p['info']];$c[5]=$p['code']!==''&&ctype_digit($p['code'])?['n'=>(int)$p['code']]:$p['code'];$c[6]=['f'=>$T.'B'.$p['info']];$c[7]=['f'=>$T.'E'.$p['info']];
                $c[8]=['f'=>"+J$r*30"];$c[9]=['f'=>"+J$r*15"];$c[10]=['n'=>$rates[$p['employee_id']]??(float)$cfg['default_daily_rate']];$c[11]=['f'=>"+J$r/8"];$c[12]=['f'=>"+K$r/60"];
                foreach([13=>'S',14=>'U',15=>'W',16=>'X'] as $col=>$src)$c[$col]=['f'=>$T.$src.$t];
                $c[17]=['f'=>"(N$r*1)-SUM(O$r:P$r)"];
                for($k=0;$k<17;$k++)$c[18+$k]=['f'=>$T.self::column(28+$k).$t];// ROT..LEGHRD hours = Timekeep AB..AR
                $c[35]=['f'=>"K$r*N$r"];$c[36]=['f'=>"O$r*K$r"];$c[37]=['f'=>"P$r*K$r"];$c[38]=['f'=>"ROUND(AI$r-SUM(AJ$r:AK$r),2)"];$c[39]=['f'=>"ROUND((Q$r+AA$r+AF$r+AE$r+V$r)*0,2)"];
                foreach($pct as $k=>$m)$c[40+$k]=['f'=>'ROUND('.self::column(18+$k).$r.'*K'.$r.'*'.$m.',2)'];
                $c[57]=['f'=>"ROUND(SUM(AN$r:BD$r),2)"];$c[58]=['f'=>"ROUND(AL$r+AM$r+BE$r,2)"];$c[59]=['f'=>"M$r*J$r"];$c[63]=['f'=>"SUM(BF$r:BJ$r)"];
                $c[70]=['f'=>"IF(BK$r<=0,0,LOOKUP(BK$r,$sss))"];// EE share of the SSS bracket holding the cutoff Total Gross Pay
                $c[71]=['f'=>'ROUND(AL'.$r.'*'.$cfg['phic_percent'].'%,2)'];$c[72]=['f'=>"IF(BK$r<=0,0,".$cfg['hdmf_per_cutoff'].")"];// no pay in the cutoff: no Pag-IBIG, so NET PAY is not negative
                $c[73]=['f'=>"ROUND(BK$r-SUM(BL$r:BT$r),2)"];$c[87]=['f'=>"ROUND(SUM(BV$r:CH$r),2)"];$c[88]=['f'=>"+BU$r-CI$r"];
                $xml.=self::psRow($r,'d',$c);
            }
            $r++;$c=[];for($col=13;$col<=88;$col++){$L=self::column($col);$c[$col]=['f'=>"ROUND((SUM($L$first:$L".($r-1).")),2)"];}
            $xml.=self::psRow($r,'s',$c);$subtotals[]=$r;$layout['subs'][]=[$r,$first,$r-1];$r+=2;
        }
        if($subtotals) {
            $r=end($subtotals)+1;$c=[];
            for($col=13;$col<=88;$col++){$L=self::column($col);$c[$col]=['f'=>'ROUND((SUM('.implode(',',array_map(static fn($s)=>$L.$s,$subtotals)).')),2)'];}
            $xml.=self::psRow($r,'s',$c);$layout['grand']=$r;
        }
        $xml.='</sheetData><mergeCells count="4"><mergeCell ref="BH8:BJ8"/><mergeCell ref="BH9:BJ9"/><mergeCell ref="BL8:CH8"/><mergeCell ref="BL9:BN9"/></mergeCells>';
        return $xml.'<pageMargins left="0.3" right="0.3" top="0.4" bottom="0.4" header="0.2" footer="0.2"/><pageSetup orientation="landscape"/></worksheet>';
    }

    /** Daily rate per employee for the cutoff from Payroll Setup > Employee rates (latest rate in force during the period); others get the default. */
    private static function dailyRates(array $ids,string $start,string $end): array
    {
        $ids=array_values(array_unique(array_map('intval',$ids)));if(!$ids)return [];
        $q=db()->prepare('SELECT employee_id,salary_basis,salary_amount,hourly_divisor FROM payroll_employee_rates WHERE employee_id IN ('.implode(',',array_fill(0,count($ids),'?')).') AND effective_from<=? AND COALESCE(effective_to,"9999-12-31")>=? ORDER BY employee_id,effective_from');
        $q->execute([...$ids,$end,$start]);$rates=[];
        foreach($q->fetchAll() as $x)$rates[(int)$x['employee_id']]=round($x['salary_basis']==='DAILY'?(float)$x['salary_amount']:(float)$x['salary_amount']/(float)$x['hourly_divisor']*8,4);// later rows win; 8 h/day as the template's Hourly = Daily/8
        return $rates;
    }

    /** One Payroll Summary row in column order (A..CJ). Kinds: h8/h9/h10 header rows, d employee, s subtotal or grand total. */
    private static function psRow(int $r,string $kind,array $cells,string $attrs=''): string
    {
        $xml='<row r="'.$r.'"'.($attrs!==''?' '.$attrs:'').'>';
        for($c=1;$c<=88;$c++) {
            $v=$cells[$c]??null;$v=is_array($v)?$v:($v===null||$v===''?[]:['s'=>(string)$v]);
            if($kind==='s'&&!$v)continue;
            $s=self::psStyle($kind,$c);$at=self::column($c).$r;
            if(isset($v['f']))$xml.='<c r="'.$at.'" s="'.$s.'"><f>'.self::xml($v['f']).'</f></c>';
            elseif(isset($v['n']))$xml.='<c r="'.$at.'" s="'.$s.'"><v>'.$v['n'].'</v></c>';
            elseif(isset($v['s']))$xml.='<c r="'.$at.'" s="'.$s.'" t="inlineStr"><is><t xml:space="preserve">'.self::xml($v['s']).'</t></is></c>';
            else $xml.='<c r="'.$at.'" s="'.$s.'"/>';
        }
        return $xml.'</row>';
    }

    /** Payroll Summary look from the template: Tahoma 8, borders on header and employee rows, header colours per group, bold totals. */
    private static function psStyle(string $kind,int $c): int
    {
        $num=$c>=13?3:0;
        if($kind==='s')return self::tkXf(3,true,0,false,false);
        if($kind!=='d') {
            $fill=match(true){in_array($c,[17,38,58,63],true)=>9,($c>=18&&$c<=21)||($c>=40&&$c<=43)=>4,($c>=22&&$c<=26)||($c>=44&&$c<=48)=>5,($c>=27&&$c<=30)||($c>=49&&$c<=52)=>6,($c>=31&&$c<=34)||($c>=53&&$c<=56)=>7,default=>0};
            if($kind==='h8'&&$c>=64&&$c<=86)$fill=10;
            return self::tkXf($num,$kind!=='h10',$fill,true);
        }
        if($c>=8&&$c<=12)return self::tkXf(5,true,0);
        $fill=match($c){17,38,58=>9,63,73=>1,88=>2,default=>0};
        return self::tkXf($num,$c===5||$fill>0,$fill);
    }

    /** A plain template row A..$to: cells as in tkRow(); $style(col, hasValue) gives the style; empty cells are written only when $grid. */
    private static function gridRow(int $r,array $cells,int $to,callable $style,bool $grid=true,string $attrs=''): string
    {
        $xml='<row r="'.$r.'"'.($attrs!==''?' '.$attrs:'').'>';
        for($c=1;$c<=$to;$c++) {
            $v=$cells[$c]??null;$v=is_array($v)?$v:($v===null||$v===''?[]:['s'=>(string)$v]);
            if(!$v&&!$grid)continue;
            $s=$style($c,(bool)$v);$at=self::column($c).$r;
            if(isset($v['f']))$xml.='<c r="'.$at.'" s="'.$s.'"><f>'.self::xml($v['f']).'</f></c>';
            elseif(isset($v['n']))$xml.='<c r="'.$at.'" s="'.$s.'"><v>'.$v['n'].'</v></c>';
            elseif(isset($v['s']))$xml.='<c r="'.$at.'" s="'.$s.'" t="inlineStr"><is><t xml:space="preserve">'.self::xml($v['s']).'</t></is></c>';
            else $xml.='<c r="'.$at.'" s="'.$s.'"/>';
        }
        return $xml.'</row>';
    }

    /** Column widths (template order, from column 1) and hidden column numbers as a <cols> block. */
    private static function colsXml(array $widths,array $hidden): string
    {
        $xml='<cols>';foreach($widths as $i=>$w)$xml.='<col min="'.($i+1).'" max="'.($i+1).'" width="'.$w.'"'.(in_array($i+1,$hidden,true)?' hidden="1"':'').' customWidth="1"/>';
        return $xml.'</cols>';
    }

    private static function sheetStart(string $pane,string $cols): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0">'.$pane.'</sheetView></sheetViews><sheetFormatPr defaultRowHeight="11.25"/>'.$cols.'<sheetData>';
    }

    private static function sheetEnd(array $merges=[]): string
    {
        $xml='</sheetData>';
        if($merges){$xml.='<mergeCells count="'.count($merges).'">';foreach($merges as $m)$xml.='<mergeCell ref="'.$m.'"/>';$xml.='</mergeCells>';}
        return $xml.'<pageMargins left="0.3" right="0.3" top="0.4" bottom="0.4" header="0.2" footer="0.2"/><pageSetup orientation="landscape"/></worksheet>';
    }

    /** Subtotal per branch and grand total of the subtotals for columns $from..$to, on the Payroll Summary row numbers. */
    private static function totalRows(array $layout,int $from,int $to,callable $style): string
    {
        $xml='';
        foreach($layout['subs'] as [$r,$first,$last]){$c=[];for($col=$from;$col<=$to;$col++){$L=self::column($col);$c[$col]=['f'=>"ROUND((SUM($L$first:$L$last)),2)"];}$xml.=self::gridRow($r,$c,$to,$style,false);}
        if($layout['grand']){$c=[];for($col=$from;$col<=$to;$col++){$L=self::column($col);$c[$col]=['f'=>'ROUND((SUM('.implode(',',array_map(static fn($s)=>$L.$s[0],$layout['subs'])).')),2)'];}$xml.=self::gridRow($layout['grand'],$c,$to,$style,false);}
        return $xml;
    }

    /** Merge data rows and total rows by row number (sheetData must be in row order). */
    private static function rowsInOrder(array $rows): string
    {
        ksort($rows);return implode('',$rows);
    }

    /**
     * John's template sheets "Billing Sheet" (share 60%, admin 10%) and "Billing B" (share 40%, admin 5%), BR-801: same rows as the
     * Payroll Summary. Earnings are recomputed from Payroll Summary hours and rate with the template's OT %; Total Direct Cost =
     * (Total Earnings + EC/SSS/Phic/HDMF) x share, ADMIN = Direct Cost x admin %, VAT = 12% of ADMIN, TOTAL BILLING adds Training Days.
     * OT Allowance, EC/SSS/Phic/HDMF, 13th Month and Incentives stay blank inputs, as in the template. Rates: config/payroll_summary.php.
     */
    private static function billingSheet(array $layout,array $bill): string
    {
        $P="'Payroll Summary'!";$ot=['ROT','EROT','RND','NDOT','RD','RDOT','RDND','SOT','ESOT','SPHD','SPHOT','SPHND','SPHRD','LEGHD','LEGHWRK','LEGHOT','LEGHRD'];
        $pct=['125%','137.5%','10%','12.5%','130%','169%','17%','150%','169%','130%','169%','0','150%','100%','100%','260%','160%'];
        $head=[3=>'Code',6=>'Name',7=>'Branch',8=>'Monthly',9=>'Semi-Monthly',10=>'Daily',11=>'Hourly',12=>'Per Minute',13=>"Regular\nDays\nHours",31=>'Reg Hours',49=>'OT Allowance',50=>'E-COLA',51=>'Total Earnings',52=>'EC',53=>'SSS',54=>'Phic',55=>'HDMF',56=>"Total Direct\nCost",57=>'ADMIN',58=>'13th Month',59=>"VAT\n(ADMIN)",60=>'TOTAL',61=>'Training Days',62=>'Incentives',63=>'TOTAL BILLING'];
        foreach($ot as $k=>$l){$head[14+$k]=$l;$head[32+$k]=$l;}
        $sub=[57=>['n'=>$bill['admin_percent']/100],59=>['n'=>$bill['vat_percent']/100]];for($k=0;$k<17;$k++)$sub[32+$k]=['f'=>$P.self::column(40+$k).'10'];
        $widths=[1.7,0,5.2,0,12.2,25.8,24,10.2,12,9.2,8.2,9.8,8.2,4.7,5,4.7,5.5,4.7,5.3,5.7,4.7,4.8,5.3,6.2,6.5,6.3,6.3,8.8,7.2,7.3,10.2,5.5,8,7,7,5.5,5.5,5.7,5.5,5.5,5.5,6.2,6.5,6.3,6.3,8.8,7.2,7.3,11.7,6.5,12.2,4.7,4.7,4.7,5.8,10.2,9.2,10.3,8.2,10.2,11.7,0,12.2];
        $hidden=[2,3,4,8,9,12,15,18,19,20,21,22,24,25,26,29,30,33,36,37,38,39,40,42,43,44,47,48,49,52,53,54,55,58,61,62];
        $head_style=fn($c,$v)=>self::tkXf(0,true,in_array($c,[51,56,60,63],true)?9:0,true);
        $data_style=fn($c,$v)=>$c===5?self::tkXf(0,true,0):($c>=8&&$c<=12?self::tkXf(5,true,0):self::tkXf($c>=13?3:0,in_array($c,[51,56,60,63],true),in_array($c,[51,56,60,63],true)?9:0));
        $total_style=fn($c,$v)=>self::tkXf(3,true,0,false,false);
        $rows=[9=>self::gridRow(9,$head,63,$head_style,true,'ht="33.75" customHeight="1"'),10=>self::gridRow(10,$sub,63,fn($c,$v)=>self::tkXf($c>=13?3:0,false,0,true))];
        foreach($layout['rows'] as $r=>$p) {
            $c=[];foreach([5=>'E',6=>'F',7=>'G',10=>'J',13=>'Q'] as $col=>$src)$c[$col]=['f'=>$P.$src.$r];
            $c[8]=['f'=>"+J$r*30"];$c[9]=['f'=>"+J$r*15"];$c[11]=['f'=>"+J$r/8"];$c[12]=['f'=>"+K$r/60"];
            for($k=0;$k<17;$k++){$c[14+$k]=['f'=>$P.self::column(18+$k).$r];$c[32+$k]=['f'=>'ROUND('.self::column(14+$k).$r.'*K'.$r.'*'.$pct[$k].',2)'];}
            $c[31]=['f'=>"+K$r*+M$r"];$c[50]=['f'=>$P.'AM'.$r];$c[51]=['f'=>"SUM(AE$r:AX$r)"];
            $c[56]=['f'=>"SUM(AY$r:BC$r)*".$bill['share']];$c[57]=['f'=>"ROUND(BD$r*".$bill['admin_percent'].'%,2)'];$c[59]=['f'=>"ROUND(BE$r*".$bill['vat_percent'].'%,2)'];
            $c[60]=['f'=>"SUM(BD$r:BG$r)"];$c[61]=['f'=>$P.'BG'.$r];$c[63]=['f'=>"SUM(BH$r:BJ$r)"];
            $rows[$r]=self::gridRow($r,$c,63,$data_style);
        }
        foreach(explode('</row>',self::totalRows($layout,13,63,$total_style)) as $x)if($x!==''&&preg_match('/<row r="(\d+)"/',$x,$m))$rows[(int)$m[1]]=$x.'</row>';
        return self::sheetStart('<pane xSplit="7" ySplit="10" topLeftCell="H11" activePane="bottomRight" state="frozen"/>',self::colsXml($widths,$hidden)).self::rowsInOrder($rows).self::sheetEnd();
    }

    /**
     * John's template sheet "Sales Seg" (BR-801): per employee the pay based on the billing (Basic, E-Cola, OT, Gross, 13th = (Basic +
     * Excess)/12, Direct Cost), employer share columns, then the DEBIT Billed Amount = Billing Sheet TOTAL BILLING and CREDIT columns
     * (cost of service, government share, 13th, admin fee and VAT from the Billing Sheet), Other Income = Billed - credits.
     * Template references kept: PHIC / Pagibig employer share read Payroll Summary CG / CH (blank there), as in John's file.
     */
    private static function salesSegSheet(array $layout): string
    {
        $P="'Payroll Summary'!";$B="'Billing Sheet'!";
        $head=[1=>' ',3=>'Code',5=>'Name',6=>'Branch',7=>'Rate',8=>'Hourly',9=>'Total Basic',10=>'Basic Pay',11=>'Honorarium',12=>'SIL',13=>'Excess/Allow',14=>'E-Cola',15=>'OT Pay',16=>'Gross Pay',17=>'EC',18=>'SSS',19=>'PHIC',20=>'Pagibig',21=>'13th',22=>'Direct Cost',25=>'COST OF SERVICE',30=>'13th mo/SIL',32=>'VAT'];
        $band=[9=>'BASED ON BILLING',17=>'EMPLOYER SHARE (LEE)',22=>'TOTAL',24=>'DEBIT',25=>'CREDIT'];
        $sub=[23=>"CI\nNO",24=>"Billed\nAMOUNT",25=>'BASIC',26=>'COLA',27=>'OT',28=>'DE_MINIMIS / ALLOW',29=>"Govt\nShare",31=>"ADMIN\nFEE",33=>"Other\nIncome"];
        $widths=[1.5,0,11.8,0,24.3,24.5,9.2,8.2,9.2,10.2,10.5,4.7,11.3,5.8,6.2,10.2,4.7,4.7,4.8,6.7,9.2,10.2,4.7,10.2,5.7,5.3,4.7,10.8,5.5,10.7,9.2,8.2,10.2,12];// AH wider than the template so large totals do not show ####
        $hs=fn($c,$v)=>self::tkXf(0,true,0,true);$ds=fn($c,$v)=>self::tkXf($c>=7?3:0,in_array($c,[16,22,24,33],true),0);$ts=fn($c,$v)=>self::tkXf(3,true,0,false,false);
        $rows=[8=>self::gridRow(8,$band,34,$hs),9=>self::gridRow(9,$head,34,$hs),10=>self::gridRow(10,$sub,34,$hs,true,'ht="22.5" customHeight="1"')];
        foreach($layout['rows'] as $r=>$p) {
            $c=[3=>['f'=>$P.'E'.$r],5=>['f'=>$P.'F'.$r],6=>['f'=>$P.'G'.$r],7=>['f'=>$P.'J'.$r],8=>['f'=>"+G$r/8"],9=>['f'=>$P.'Q'.$r],10=>['f'=>"+H$r*I$r"],14=>['f'=>'+'.$P.'AM'.$r],15=>['f'=>$P.'BE'.$r],16=>['f'=>"+SUM(J$r:O$r)"],
                19=>['f'=>$P.'CG'.$r],20=>['f'=>$P.'CH'.$r],21=>['f'=>"(J$r+M$r)/12"],22=>['f'=>"SUM(P$r:U$r)"],24=>['f'=>$B.'BK'.$r],29=>['f'=>"SUM(Q$r:T$r)"],30=>['f'=>"U$r"],31=>['f'=>$B.'BE'.$r],32=>['f'=>'+'.$B.'BG'.$r],33=>['f'=>"X$r-SUM(Y$r:AF$r)"],34=>['f'=>"P$r-SUM(Y$r:AB$r)"]];
            $rows[$r]=self::gridRow($r,$c,34,$ds);
        }
        foreach(explode('</row>',self::totalRows($layout,10,34,$ts)) as $x)if($x!==''&&preg_match('/<row r="(\d+)"/',$x,$m))$rows[(int)$m[1]]=$x.'</row>';
        return self::sheetStart('<pane xSplit="6" ySplit="10" topLeftCell="G11" activePane="bottomRight" state="frozen"/>',self::colsXml($widths,[2,3,4,12])).self::rowsInOrder($rows).self::sheetEnd(['I8:O8','Q8:T8','Y8:AG8','Y9:AB9']);
    }

    /**
     * John's template sheet "Summary Sheet" (BR-801): per branch a title row, then per employee Regular (Payroll Summary present hours),
     * Special holiday hours, days that need an OB/TA with the missing punches (from the cutoff's clock times), Late and Undertime hours.
     * Legal holiday, rest day, absent, pending days and training stay as inputs (0 / blank), as in the template.
     */
    private static function attendanceSummarySheet(array $layout): string
    {
        $P="'Payroll Summary'!";
        $head=[1=>'BRANCH',2=>'NAME',3=>'REGULAR DAYS',4=>'SPHOLIDAY',5=>'LEGHOLIDAY',6=>"UPAID DAY/S\n(NEED OB/TA)",7=>"UNPAID\nDAY/S\nREMARKS",8=>'REST DAY',9=>'ABSENT',10=>'TOTAL',11=>"PENDING\nDAYS (LAST\nCUT OFF)",12=>'TRAINING',13=>'TOTAL',14=>'LATE',15=>'UNDERTIME'];
        $hs=fn($c,$v)=>self::tkXf(0,true,0,true);$ds=fn($c,$v)=>in_array($c,[6,7],true)?self::tkXf(0,false,0,true):self::tkXf(in_array($c,[1,2],true)?0:3,$c===10||$c===13,0);// F/G wrap: one line per day
        $xml=self::sheetStart('<pane xSplit="2" ySplit="9" topLeftCell="C10" activePane="bottomRight" state="frozen"/>',self::colsXml([24.5,24.3,12.8,10,11,6.8,36.3,8.3,7,6,8.2,8.7,6,4.8,10.3],[]));
        $xml.=self::gridRow(8,[3=>'PRESENT CUT-OFF',11=>'PREVIOUS',14=>'LOGS'],15,$hs).self::gridRow(9,$head,15,$hs,true,'ht="33.75" customHeight="1"');
        $r=10;$branch=null;reset($layout['rows']);
        foreach($layout['rows'] as $pr=>$p) {
            if($p['branch']!==$branch){$branch=$p['branch'];$r++;$xml.=self::gridRow($r,[1=>['f'=>$P.'G'.$pr]],15,fn($c,$v)=>self::tkXf(0,true,0),false);}
            $r++;$g=$p['gaps'];
            $c=[1=>['f'=>$P.'E'.$pr],2=>['f'=>$P.'F'.$pr],3=>['f'=>$P.'N'.$pr],4=>['f'=>$P.'AA'.$pr],5=>['n'=>0],6=>implode("\n",array_keys($g)),7=>implode("\n",array_map(static fn($d,$m)=>$d.'-'.$m,array_keys($g),$g)),8=>['n'=>0],9=>['n'=>0],
                10=>['f'=>"ROUND((SUM(C$r,D$r,E$r,H$r,I$r)),2)"],13=>['f'=>"ROUND((SUM(K$r,L$r)),2)"],14=>['f'=>$P.'O'.$pr],15=>['f'=>$P.'P'.$pr]];
            $xml.=self::gridRow($r,$c,15,$ds);
            if(next($layout['rows'])===false||current($layout['rows'])['branch']!==$branch)$r++;// blank row before the next branch
        }
        return $xml.self::sheetEnd(['C8:J8','K8:M8','N8:O8']);
    }

    /**
     * John's template sheet "CI" (charge invoice, BR-801): two invoices side by side, left from the Billing Sheet grand total, right from
     * Billing B. Earnings lines (hours and amount), E-COLA, statutory benefits, Total Direct Cost, Admin Fee (CI rate) and its VAT, less EWT
     * on the Admin Fee. Two template slips fixed: the right invoice totals the amounts (the template summed its hours column) and SSS /
     * EC read the SSS / EC columns (the template had them swapped). Training and incentives stay blank inputs, as in the template.
     */
    private static function ciSheet(array $layout,array $run,string $company,array $ci): string
    {
        $g=$layout['grand'];$lines=[14=>['REGULAR HOURS','M','AE'],15=>['REGULAR OT','N','AF'],16=>['REGULAR ND','P','AH'],17=>['REGULAR OT-ND','Q','AI'],18=>['SPECIAL HOLIDAY','W','AO'],19=>['LEGAL HOLIDAY','AA','AS'],20=>['LEGAL WORK','AB','AT']];
        $rows=[];$put=function(int $r,int $col,$v)use(&$rows){$rows[$r][$col]=$v;};
        foreach([0=>"'Billing Sheet'!",12=>"'Billing B'!"] as $o=>$S) {
            $col=fn(string $L)=>self::column(ord($L)-64+$o);$A=1+$o;
            foreach(['Prime Mover Business Solutions Inc.','2F Guadalupe Commercial Complex','Guadalupe Nuevo Makati City','Tel No. 882-0512, 882-5778'] as $i=>$t)$put($i+1,$A,$t);
            $put(1,5+$o,'CI#');$put(5,5+$o,'Pay Period :');$put(5,6+$o,date('m/d/Y',strtotime((string)$run['period_start'])));$put(6,6+$o,date('m/d/Y',strtotime((string)$run['period_end'])));
            $put(7,$A,'BILL TO :');$put(7,2+$o,$company);$put(9,7+$o,'Amount');$put(12,2+$o,'A.');$put(12,3+$o,'EARNINGS');
            if($g)foreach($lines as $r=>[$label,$h,$amt]){$put($r,3+$o,$label);$put($r,4+$o,['f'=>$S.$h.$g]);$put($r,5+$o,['f'=>$S.$amt.$g]);}
            $put(23,2+$o,'B.');$put(23,3+$o,'EARNINGS');$put(24,3+$o,'E-COLA');if($g)$put(24,5+$o,['f'=>$S.'AX'.$g]);
            $put(27,3+$o,'TOTAL EARNINGS');$put(27,6+$o,'Php');$put(27,7+$o,['f'=>'ROUND(SUM('.$col('E').'14:'.$col('E').'24),2)']);
            $put(29,2+$o,'C.');$put(29,3+$o,'Statutory Benefits');
            foreach([30=>['SSS Contribution','BA'],31=>['EC Share','AZ'],32=>['Philhealth','BB'],33=>['HDMF','BC']] as $r=>[$label,$src]){$put($r,3+$o,$label);if($g)$put($r,7+$o,['f'=>$S.$src.$g]);}
            $G=$col('G');
            $put(35,3+$o,'TOTAL');$put(35,6+$o,'Php');$put(35,7+$o,['f'=>"ROUND(SUM({$G}30:{$G}34),2)"]);
            $put(37,3+$o,'Total Direct Cost');$put(37,7+$o,['f'=>"ROUND(+{$G}35+{$G}27,2)"]);
            $put(38,3+$o,'Admin Fee');$put(38,7+$o,['f'=>"ROUND(+{$G}37*".($ci['admin_percent']/100).',2)']);
            $put(39,3+$o,'VAT '.$ci['vat_percent'].'% on Admin Fee');$put(39,7+$o,['f'=>"ROUND(+{$G}38*".($ci['vat_percent']/100).',2)']);
            $put(41,2+$o,'TOTAL');$put(41,7+$o,['f'=>"ROUND(+{$G}37+{$G}38+{$G}39,2)"]);
            $put(42,2+$o,'ADD: OTHER ADVANCES');$put(43,3+$o,'TRAINING');$put(44,3+$o,'INCENTIVES');
            $put(46,2+$o,'Less: EWT '.$ci['ewt_percent'].'% on Admin Fee');$put(46,7+$o,['f'=>"ROUND({$G}38*".($ci['ewt_percent']/100).',2)*-1']);
            $put(48,2+$o,'TOTAL BILLING AMOUNT');$put(48,6+$o,'Php');$put(48,7+$o,['f'=>"ROUND(+{$G}41+{$G}46,2)"]);
            $put(52,$A,'Prepared by:');$put(52,7+$o,'Noted by:');$put(56,$A,'Billing Officer');$put(56,7+$o,'Accounting Head');
            foreach([59=>'Please settle your account on or before _______________________ . Further, please notify us immediately of any billing error ',60=>'in the Statement of Account (SOA) or Charge Invoice (CI).. If no error is reported within fifteen calendar (15) days from ',61=>'the date of received, billing shall be considered final and accurate.',63=>'To all withholding agents : As applicable, kindly issue corresponding certificate of taxes withheld (BIR 2307) to PMBSI',64=>'upon remittance of payment.'] as $r=>$t)$put($r,$A,$t);
        }
        $bold=[1,27,37,41,48];
        $xml=self::sheetStart('',self::colsXml([9.3,9.3,18.8,9.3,9.3,10.5,12,1,1,1,1,1,9.3,9.3,18.8,9.3,10.3,10.5,12],[]));
        ksort($rows);foreach($rows as $r=>$cells)$xml.=self::gridRow($r,$cells,19,fn($c,$v)=>self::tkXf(in_array($c,[4,5,7,16,17,19],true)&&$r>=14?3:0,in_array($r,$bold,true),0,false,false),false);
        return $xml.self::sheetEnd();
    }

    private static function styles(): string
    {
        $tkFills=['FFFFF68F','FFFF6A6A','FFFF9912','FFCD96CD','FF00CD66','FF63B8FF','FFE3A869','FFFFFF00','FFFFEC8B','FFEE3B3B'];// template colours = fills 5..14
        $styles='<?xml version="1.0" encoding="UTF-8"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><numFmts count="4"><numFmt numFmtId="43" formatCode="_(* #,##0.00_);_(* \(#,##0.00\);_(* &quot;-&quot;??_);_(@_)"/><numFmt numFmtId="164" formatCode="mmm d, yyyy"/><numFmt numFmtId="165" formatCode="h:mm AM/PM"/><numFmt numFmtId="166" formatCode="0.00"/></numFmts><fonts count="5"><font><sz val="11"/><name val="Arial"/></font><font><b/><color rgb="FFFFFFFF"/><sz val="10"/><name val="Arial"/></font><font><b/><sz val="16"/><name val="Arial"/></font><font><sz val="8"/><name val="Tahoma"/><family val="2"/></font><font><b/><sz val="8"/><name val="Tahoma"/><family val="2"/></font></fonts><fills count="15"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF18212F"/><bgColor indexed="64"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFF1F5F9"/><bgColor indexed="64"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFFFEF99"/><bgColor indexed="64"/></patternFill></fill>';
        foreach($tkFills as $rgb)$styles.='<fill><patternFill patternType="solid"><fgColor rgb="'.$rgb.'"/><bgColor indexed="64"/></patternFill></fill>';
        $thin='<left style="thin"><color indexed="64"/></left><right style="thin"><color indexed="64"/></right><top style="thin"><color indexed="64"/></top><bottom style="thin"><color indexed="64"/></bottom><diagonal/>';
        $styles.='</fills><borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border><border>'.$thin.'</border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="538">';
        foreach([[0,0,0],[0,1,2],[0,0,3],[164,0,0],[165,0,0],[165,0,4],[166,0,0],[166,0,4],[0,2,0],[0,0,4]] as [$num,$font,$fill])$styles.='<xf numFmtId="'.$num.'" fontId="'.$font.'" fillId="'.$fill.'" borderId="0" xfId="0" applyNumberFormat="1" applyFill="1" applyFont="1" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf>';
        foreach([0,16,20,43,164,4] as $num)foreach([3,4] as $font)for($fill=0;$fill<=10;$fill++)foreach([false,true] as $center)foreach([1,0] as $border)// order must match tkXf()
            $styles.='<xf numFmtId="'.$num.'" fontId="'.$font.'" fillId="'.($fill?4+$fill:0).'" borderId="'.$border.'" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1"'.($center?' applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>':'/>');
        return $styles.'</cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
    }

    private static function pdf(string $file,array $saved,array $run,array $batch): void
    {
        $pdf=new PayrollCutoffPdf();$page=0;
        if(!empty($saved['source_gaps'])) {
            $y=0;
            $summaryHeader=static function()use($pdf,$run,$batch,&$page,&$y):void {
                $pdf->page();$page++;$pdf->text(36,40,'PMBSI - Biometric source summary / FOR REVIEW',17,true,523);
                $pdf->text(36,58,$run['period_start'].' to '.$run['period_end'].' / Generated '.$batch['generated_at'],9,false,523);
                $pdf->text(36,76,'Groups marked NO LOGS have no biometric punches in any imported file. Nothing is assumed absent.',8,false,523);
                $pdf->rect(36,90,523,22,[.94,.96,.98]);$pdf->text(42,105,'Group / Department / Branch',9,true,280);$pdf->text(330,105,'With logs / Total',9,true,90);$pdf->text(430,105,'Status',9,true,125);
                $pdf->text(490,822,'Page '.$page,8,false,65);$y=130;
            };
            $summaryHeader();
            foreach($saved['source_gaps']['sections'] as $name=>$count) {
                if($y>785)$summaryHeader();
                $missing=$count['total']-$count['with_logs'];
                if($count['with_logs']===0)$pdf->rect(36,$y-11,523,16,[1,.94,.60]);
                $pdf->text(42,$y,$name,8,false,280);$pdf->text(330,$y,$count['with_logs'].' / '.$count['total'],8,false,90);
                $pdf->text(430,$y,$count['with_logs']===0?'NO LOGS RECEIVED':($missing?$missing.' without logs':'Complete'),8,$count['with_logs']===0,125);$y+=16;
            }
        }
        foreach(self::sections($saved) as $section=>$employees)foreach($employees as $days) {
            $e=$days[0]['_employee'];$y=0;$continuation=false;
            $header=static function()use($pdf,$run,$batch,$section,$e,&$page,&$y,&$continuation):void {
                $review=!empty($batch['for_review']);
                $pdf->page();$page++;$pdf->text(36,40,$review?'PMBSI - Attendance / FOR REVIEW':'PMBSI - Cutoff Attendance Logs',17,true,523);
                $pdf->text(36,58,$run['period_start'].' to '.$run['period_end'].' / Generated '.$batch['generated_at'],9,false,523);
                $y=81;
                if($review)foreach($pdf->wrap($batch['notice'],100) as $line){$pdf->text(36,$y,$line,8,false,523);$y+=13;}
                foreach($pdf->wrap($section,80) as $line){$pdf->text(36,$y,$line,10,true,523);$y+=14;}
                $pdf->text(36,$y+4,$e['employee_name'].($continuation?' (continued)':''),11,true,523);$y+=22;
                $pdf->text(36,$y,($e['employee_no']?:'Code to follow / HRIS ID '.$e['id']).' / '.$e['company_name'].' / '.$e['position_name'],9,false,523);$y+=18;
                $pdf->rect(36,$y-10,523,24,[.94,.96,.98]);foreach([[42,'No. / Employee Code'],[170,'Date / Time'],[347,'Status'],[443,'Source']] as [$x,$text])$pdf->text($x,$y+5,$text,9,true,$x===170?172:120);
                $y+=27;
                if($review)$pdf->text(36,792,'FOR REVIEW: missing times are blank; pending requests are not applied; cutoff status stays the same.',8,true,523);
                $pdf->text(36,807,'Yellow: approved TA / OB / OT changes. Salary is calculated separately.',8,false,523);$pdf->text(490,822,'Page '.$page,8,false,65);$continuation=true;
            };
            $header();
            foreach($days as $day) {
                $items=[];
                foreach(PayrollCalculator::FIELDS as $field)if(!empty($day[$field]))$items[]=[$day[$field],match($field){'time_in'=>'IN','lunch_out'=>'BREAK OUT','lunch_in'=>'BREAK IN',default=>'OUT'},$day['export_marks'][$field]??'Biometric',isset($day['export_marks'][$field])];
                if(!$items)$items[]=[$day['work_date'],$day['export_day_label']??self::stateLabel($day['state']),'Day record',false];
                foreach($day['export_missing']??[] as $missing)$items[]=[$day['work_date'],'Missing '.$missing,'For review',false];
                if(($ot=PayrollCutoffExportService::otMinutes($day))>0)$items[]=[$day['work_date'],'OT '.number_format($ot/60,2).' h','Approved OT',true];
                foreach($items as [$stamp,$status,$source,$yellow]) {
                    if($y>767)$header();
                    if($yellow)$pdf->rect(36,$y-11,523,20,[1,.94,.60]);
                    $pdf->text(42,$y,(string)($e['employee_no']?:'HRIS '.$e['id']),9,false,123);
                    $pdf->text(170,$y,date(strlen($stamp)>10?'M d, Y g:i A':'M d, Y',strtotime($stamp)),9,false,172);
                    $pdf->text(347,$y,$status,9,false,91);$pdf->text(443,$y,$source,9,$yellow,110);
                    $pdf->line(36,$y+7,559,$y+7);$y+=20;
                }
                $note=self::notes($day);
                if($note!=='Complete')foreach($pdf->wrap($note,100) as $line){if($y>767)$header();$pdf->text(42,$y,$line,8,false,511);$y+=13;}
            }
        }
        $pdf->save($file);
    }
}

/** A small PDF writer: standard embedded references, WinAnsi fonts, no executable content. */
final class PayrollCutoffPdf
{
    private array $pages=[];
    public function page():void {$this->pages[]='';}
    private function append(string $command):void {$this->pages[count($this->pages)-1].=$command."\n";}
    private function encoded(string $text):string {return iconv('UTF-8','Windows-1252//TRANSLIT',str_replace(["\r","\n"],' ',$text))?:'';}
    public function wrap(string $text,int $length):array {return explode("\n",wordwrap($this->encoded($text),$length,"\n",true));}
    public function text(float $x,float $top,string $text,int $size,bool $bold,float $width):void
    {
        // wrap() returns WinAnsi lines; recover UTF-8 once so encoding is never applied twice.
        if(!mb_check_encoding($text,'UTF-8'))$text=iconv('Windows-1252','UTF-8',$text)?:$text;
        $text=$this->encoded($text);$limit=max(1,(int)floor($width/($size*.62)));
        if(strlen($text)>$limit)$text=substr($text,0,max(0,$limit-3)).'...';
        $escaped=str_replace(['\\','(',')'],['\\\\','\\(','\\)'],$text);
        $this->append('0.09 0.13 0.18 rg BT /'.($bold?'F2':'F1').' '.$size.' Tf 1 0 0 1 '.$x.' '.(842-$top).' Tm ('.$escaped.') Tj ET');
    }
    public function rect(float $x,float $top,float $width,float $height,array $rgb):void {$this->append(implode(' ',$rgb).' rg '.$x.' '.(842-$top-$height).' '.$width.' '.$height.' re f');}
    public function line(float $x,float $top,float $endX,float $endTop):void {$this->append('0.88 0.90 0.93 RG 0.4 w '.$x.' '.(842-$top).' m '.$endX.' '.(842-$endTop).' l S');}
    public function save(string $file):void
    {
        $objects=[1=>'<< /Type /Catalog /Pages 2 0 R >>',3=>'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',4=>'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>'];$kids=[];$next=5;
        foreach($this->pages as $stream){$page=$next++;$content=$next++;$kids[]=$page.' 0 R';$objects[$page]='<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents '.$content.' 0 R >>';$objects[$content]='<< /Length '.strlen($stream).' >>' ."\nstream\n".$stream."endstream";}
        $objects[2]='<< /Type /Pages /Kids ['.implode(' ',$kids).'] /Count '.count($kids).' >>';ksort($objects);
        $output="%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";$offsets=[0];foreach($objects as $id=>$object){$offsets[$id]=strlen($output);$output.=$id." 0 obj\n".$object."\nendobj\n";}
        $xref=strlen($output);$output.='xref' ."\n0 ".$next."\n0000000000 65535 f \n";for($id=1;$id<$next;$id++)$output.=sprintf('%010d 00000 n ',$offsets[$id])."\n";
        $output.='trailer' ."\n<< /Size ".$next.' /Root 1 0 R >>'."\nstartxref\n".$xref."\n%%EOF\n";
        if(file_put_contents($file,$output)===false)throw new RuntimeException('Could not save the PDF download.');
    }
}
