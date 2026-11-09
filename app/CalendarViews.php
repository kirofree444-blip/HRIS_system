<?php
declare(strict_types=1);
require_once __DIR__.'/CalendarEvents.php';

/** Date range + optional time, e.g. "Nov 1 - 15" or "Nov 20, 2:00 PM - 3:00 PM". */
function cal_when(array $ev): string
{
    $s = strtotime((string)$ev['start_date']); $e = strtotime((string)$ev['end_date']);
    $out = $s === $e ? date('M j', $s) : (date('Ym', $s) === date('Ym', $e) ? date('M j', $s).' - '.date('j', $e) : date('M j', $s).' - '.date('M j', $e));
    if (!empty($ev['start_time'])) $out .= ', '.date('g:i A', strtotime((string)$ev['start_time'])).(!empty($ev['end_time']) ? ' - '.date('g:i A', strtotime((string)$ev['end_time'])) : '');
    return $out;
}

/** Add/edit form. $ev null = new event (defaults to a company event shown to employees). Payroll cutoff is not offered: it is fixed. */
function cal_form(?array $ev, string $back, DateTimeImmutable $first): void
{
    $k = $ev ? 'e'.(int)$ev['id'] : 'new'; $type = $ev['event_type'] ?? 'COMPANY_EVENT';
    $guests = $ev['guests'] ?? [];
    $aud = $ev ? ($ev['visible_to_employees'] ? 'ALL' : ($guests ? 'GUESTS' : 'HR')) : (in_array($type, CalendarEvents::EMPLOYEE_DEFAULT, true) ? 'ALL' : 'HR');
    $start = $ev['start_date'] ?? ($first->format('Ym') === date('Ym') ? date('Y-m-d') : $first->format('Y-m-d'));?>
      <form class="cal-form" method="post" action="<?=e($back)?>" data-cal-form data-employee-default="<?=e(implode(',', CalendarEvents::EMPLOYEE_DEFAULT))?>"><?=csrf_field()?>
        <input type="hidden" name="action" value="calendar_save"><input type="hidden" name="cal" value="<?=e($first->format('Y-m'))?>"><?php if($ev):?><input type="hidden" name="id" value="<?=(int)$ev['id']?>"><?php endif;?>
        <div class="field"><label for="cal-title-<?=$k?>">Title</label><input id="cal-title-<?=$k?>" name="title" maxlength="120" required value="<?=e($ev['title'] ?? '')?>" placeholder="e.g. Town hall meeting"></div>
        <div class="field"><label for="cal-type-<?=$k?>">Type</label><select id="cal-type-<?=$k?>" name="event_type" data-cal-type><?php foreach(CalendarEvents::MANUAL as $code):[$label]=CalendarEvents::TYPES[$code];?><option value="<?=e($code)?>"<?=$code===$type?' selected':''?>><?=e($label)?></option><?php endforeach;?></select></div>
        <div class="cal-form-row"><div class="field"><label for="cal-start-<?=$k?>">Start date</label><input type="date" id="cal-start-<?=$k?>" name="start_date" required value="<?=e($start)?>"></div>
          <div class="field"><label for="cal-end-<?=$k?>">End date</label><input type="date" id="cal-end-<?=$k?>" name="end_date" value="<?=e($ev['end_date'] ?? '')?>" aria-describedby="cal-end-hint-<?=$k?>"><small id="cal-end-hint-<?=$k?>">Blank = same day</small></div></div>
        <div class="cal-form-row"><div class="field"><label for="cal-from-<?=$k?>">Start time</label><input type="time" id="cal-from-<?=$k?>" name="start_time" value="<?=e(isset($ev['start_time']) ? substr((string)$ev['start_time'], 0, 5) : '')?>"></div>
          <div class="field"><label for="cal-to-<?=$k?>">End time</label><input type="time" id="cal-to-<?=$k?>" name="end_time" value="<?=e(isset($ev['end_time']) ? substr((string)$ev['end_time'], 0, 5) : '')?>"></div></div>
        <div class="field"><label for="cal-notes-<?=$k?>">Notes <span class="cal-opt">(optional)</span></label><textarea id="cal-notes-<?=$k?>" name="notes" rows="2" maxlength="500"><?=e($ev['notes'] ?? '')?></textarea></div>
        <div class="field"><label for="cal-link-<?=$k?>">Meeting link <span class="cal-opt">(optional)</span></label><input type="url" id="cal-link-<?=$k?>" name="meeting_url" maxlength="500" inputmode="url" placeholder="https://zoom.us/j/... or https://meet.google.com/..." value="<?=e($ev['meeting_url'] ?? '')?>"></div>
        <div class="field"><label for="cal-aud-<?=$k?>">Who can see this</label><select id="cal-aud-<?=$k?>" name="audience" data-cal-visible><?php foreach(CalendarEvents::AUDIENCES as $code=>$label):?><option value="<?=$code?>"<?=$code===$aud?' selected':''?>><?=e($label)?></option><?php endforeach;?></select></div>
        <fieldset class="cal-guests" data-cal-picker data-endpoint="<?=e(url('calendar-people'))?>"<?=$aud==='GUESTS'?'':' hidden'?>><legend>Guests</legend>
          <div class="field"><label for="cal-gq-<?=$k?>">Add an employee by name</label><input type="search" id="cal-gq-<?=$k?>" autocomplete="off" data-cal-q aria-describedby="cal-gs-<?=$k?>"></div>
          <p class="cal-hint" id="cal-gs-<?=$k?>" role="status" aria-live="polite" data-cal-status>Only the guests (and HR) will see this event.</p>
          <ul class="cal-results" data-cal-results aria-label="Search results"></ul>
          <ul class="cal-people" data-cal-people aria-label="Guests added"><?php foreach($guests as $g):?><li><span><?=e($g['name'])?></span><input type="hidden" name="guests[]" value="<?=(int)$g['id']?>"><button type="button" class="btn sm" data-cal-remove>Remove<span class="sr-only"> <?=e($g['name'])?></span></button></li><?php endforeach;?></ul>
        </fieldset>
        <button class="btn primary sm" type="submit"><?=$ev?'Save changes':'Add event'?></button>
      </form>
<?php }

/**
 * Month calendar panel, full width (John 2026-11-07: agenda rail removed). Clicking a date opens a modal with that day's
 * events. $editable = HR Dashboard (add/edit/remove in the modal); false = Employee dashboard (read-only, caller passes
 * only employee-visible events). $page/$keep build the month links; ?cal=YYYY-MM picks the month.
 */
function calendar_panel(DateTimeImmutable $first, array $events, bool $editable, string $page, array $keep = []): void
{
    $lead = (int)$first->format('w'); $days = (int)$first->format('t'); $ym = $first->format('Y-m');
    $isThisMonth = $ym === date('Y-m'); $today = (int)date('j');
    $cells = (int)(ceil(($lead + $days) / 7) * 7); $prevDays = (int)$first->modify('-1 month')->format('t');
    $link = static fn(string $m) => url($page, $keep + ['cal' => $m]);
    $back = $link($ym);
    $dayIso = static fn(int $d) => $first->format('Y-m-').str_pad((string)$d, 2, '0', STR_PAD_LEFT);
    $byDay = [];
    foreach ($events as $ev) {
        $from = $ev['start_date'] < $first->format('Y-m-d') ? 1 : (int)substr((string)$ev['start_date'], 8, 2);
        $to = $ev['end_date'] > $first->format('Y-m-t') ? $days : (int)substr((string)$ev['end_date'], 8, 2);
        // Fixed cutoffs span ~15 days: chip only on the first and last day so the grid stays readable (the modal lists it on every day).
        for ($d = $from; $d <= $to; $d++) if (empty($ev['fixed']) || $ev['event_type'] !== 'PAYROLL_CUTOFF' || $first->format('Y-m-').sprintf('%02d', $d) === $ev['start_date'] || $first->format('Y-m-').sprintf('%02d', $d) === $ev['end_date']) $byDay[$d][] = $ev;
    }?>
    <section class="panel hd-cal" id="calendar" aria-labelledby="hd-cal-title"><div class="hd-cal-head">
      <div class="hd-cal-label"><h2 id="hd-cal-title">Calendar</h2><p><?=$editable?'Select a date to see or add events':'Select a date to see its events'?></p></div>
      <nav class="hd-cal-month" aria-label="Calendar month"><a class="hd-cal-arrow" data-cal-nav="prev" href="<?=e($link($first->modify('-1 month')->format('Y-m')))?>#calendar" aria-label="Previous month, <?=e($first->modify('-1 month')->format('F Y'))?>">‹</a>
        <strong class="hd-cal-current" aria-live="polite"><?=e($first->format('F Y'))?></strong>
        <a class="hd-cal-arrow" data-cal-nav="next" href="<?=e($link($first->modify('+1 month')->format('Y-m')))?>#calendar" aria-label="Next month, <?=e($first->modify('+1 month')->format('F Y'))?>">›</a></nav>
      <div class="hd-cal-right"><a class="btn sm" data-cal-nav="today" href="<?=e($link(date('Y-m')))?>#calendar"<?=$isThisMonth?' aria-current="date"':''?>>Today</a></div></div>
      <div class="panel-body">
        <table class="hd-cal-grid" aria-label="<?=e($first->format('F Y').', '.count($events).' event'.(count($events)===1?'':'s'))?>">
          <thead><tr><?php foreach(['Sun'=>'Sunday','Mon'=>'Monday','Tue'=>'Tuesday','Wed'=>'Wednesday','Thu'=>'Thursday','Fri'=>'Friday','Sat'=>'Saturday'] as $s=>$l):?><th scope="col" abbr="<?=$l?>"><?=$s?></th><?php endforeach;?></tr></thead>
          <tbody><?php for($i=0;$i<$cells;$i++):$n=$i-$lead+1;if($i%7===0)echo '<tr>';
            if($n<1||$n>$days):?><td class="is-out" aria-hidden="true"><span class="d"><?=$n<1?$prevDays+$n:$n-$days?></span></td><?php
            else:$evs=$byDay[$n]??[];$c=count($evs);$cur=$isThisMonth&&$n===$today;$dt=new DateTimeImmutable($dayIso($n));
              $aria=$dt->format('F j').($cur?' (today)':'').', '.($c?$c.' event'.($c===1?'':'s'):'no events');?><td class="<?=$cur?'is-today':''?><?=$i%7===0||$i%7===6?' is-weekend':''?>"<?=$cur?' aria-current="date"':''?>><button type="button" class="cal-daybtn" data-cal-day="<?=e($dayIso($n))?>" data-label="<?=e($dt->format('l, F j'))?>" aria-label="<?=e($aria)?>"><span class="d"><?=$n?></span><?php
              foreach(array_slice($evs,0,3) as $ev):?><span class="cal-ev t-<?=e(strtolower((string)$ev['event_type']))?>"><?=e(!empty($ev['fixed'])&&$ev['event_type']==='PAYROLL_CUTOFF'?($ev['start_date']===$dayIso($n)?'Cutoff starts':'Cutoff ends'):(!empty($ev['fixed'])?'Pay date':(string)$ev['title']))?></span><?php endforeach;
              if($c>3):?><span class="cal-more">+<?=$c-3?> more</span><?php endif;?></button></td><?php endif;
            if($i%7===6)echo '</tr>';endfor;?></tbody></table>
        <ul class="cal-legend" aria-label="Event types"><?php foreach(CalendarEvents::TYPES as $code=>[$label]):?><li><i class="t-<?=e(strtolower($code))?>" aria-hidden="true"></i><?=e($label)?></li><?php endforeach;?></ul>
      </div>
      <dialog class="cal-dialog" id="cal-dialog" aria-labelledby="cal-dialog-title"><div class="cal-dialog-in">
        <div class="cal-dialog-head"><h3 id="cal-dialog-title"><?=e($first->format('F Y'))?></h3><button type="button" class="cal-close" data-cal-close aria-label="Close">×</button></div>
        <p class="empty" data-cal-empty>No events on this day.</p>
        <?php if($events):?><ul class="cal-list"><?php foreach($events as $ev):[$label]=CalendarEvents::TYPES[$ev['event_type']]??['Other'];?>
          <li class="cal-item t-<?=e(strtolower((string)$ev['event_type']))?>" data-cal-item data-start="<?=e((string)$ev['start_date'])?>" data-end="<?=e((string)$ev['end_date'])?>"><div class="cal-item-head"><span class="cal-type"><?=e($label)?></span><span class="cal-when"><?=e(cal_when($ev))?></span></div>
            <strong><?=e((string)$ev['title'])?></strong><?php if(!empty($ev['notes'])):?><p><?=e((string)$ev['notes'])?></p><?php endif;?>
            <?php if(!empty($ev['meeting_url'])):?><a class="btn sm cal-join" href="<?=e((string)$ev['meeting_url'])?>" target="_blank" rel="noopener noreferrer">Join meeting<span class="cal-host"> · <?=e((string)parse_url((string)$ev['meeting_url'], PHP_URL_HOST))?></span><span class="sr-only"> (opens in a new tab)</span></a><?php endif;?>
            <?php if(!empty($ev['fixed'])):?><small class="cal-vis">Fixed payroll schedule</small><?php elseif($editable):$gs=$ev['guests']??[];?><small class="cal-vis"><?=$ev['visible_to_employees']?'Everyone':($gs?'Invited guests only ('.count($gs).'): '.e(implode(', ',array_column($gs,'name'))):'HR only')?></small>
            <div class="cal-actions"><details><summary class="btn sm">Edit</summary><?php cal_form($ev,$back,$first);?></details>
              <form method="post" action="<?=e($back)?>" onsubmit="return confirm('Remove this calendar event?')"><?=csrf_field()?><input type="hidden" name="action" value="calendar_delete"><input type="hidden" name="cal" value="<?=e($ym)?>"><input type="hidden" name="id" value="<?=(int)$ev['id']?>"><button class="btn sm" type="submit">Remove<span class="sr-only"> <?=e((string)$ev['title'])?></span></button></form></div><?php endif;?></li>
        <?php endforeach;?></ul><?php endif;?>
        <?php if($editable):?><details class="cal-add" data-cal-add><summary class="btn sm">+ Add event on this day</summary><?php cal_form(null,$back,$first);?></details><?php endif;?>
      </div></dialog>
    </section>
    <script>(function(){function init(){var p=document.getElementById('calendar'),dlg=document.getElementById('cal-dialog');if(!p||!dlg||!dlg.showModal)return;
      var t=document.getElementById('cal-dialog-title'),empty=dlg.querySelector('[data-cal-empty]'),items=dlg.querySelectorAll('[data-cal-item]'),add=dlg.querySelector('[data-cal-add]'),opener=null;
      p.addEventListener('click',function(ev){var b=ev.target.closest('[data-cal-day]');if(!b||dlg.contains(b))return;var day=b.dataset.calDay,n=0;opener=b;t.textContent=b.dataset.label;
        items.forEach(function(li){var on=li.dataset.start<=day&&li.dataset.end>=day;li.hidden=!on;if(on)n++});empty.hidden=n>0;
        if(add){add.querySelector('[name=start_date]').value=day;add.querySelector('[name=end_date]').value='';add.open=n===0}dlg.showModal()});
      dlg.querySelector('[data-cal-close]').addEventListener('click',function(){dlg.close()});
      dlg.addEventListener('click',function(ev){if(ev.target===dlg)dlg.close()});
      dlg.addEventListener('close',function(){if(opener)opener.focus()});
      document.querySelectorAll('[data-cal-form]').forEach(function(f){var ty=f.querySelector('[data-cal-type]'),v=f.querySelector('[data-cal-visible]'),d=f.dataset.employeeDefault.split(','),touched=!!f.querySelector('[name=id]'),pk=f.querySelector('[data-cal-picker]');
        if(!pk)return;var q=pk.querySelector('[data-cal-q]'),st=pk.querySelector('[data-cal-status]'),res=pk.querySelector('[data-cal-results]'),list=pk.querySelector('[data-cal-people]'),timer=null;
        function sync(){pk.hidden=v.value!=='GUESTS'}
        v.addEventListener('change',function(){touched=true;sync()});ty.addEventListener('change',function(){if(!touched){v.value=d.indexOf(ty.value)>-1?'ALL':'HR';sync()}});
        function has(id){return !!list.querySelector('input[value="'+id+'"]')}
        function add(p){if(has(p.id))return;var li=document.createElement('li'),s=document.createElement('span'),h=document.createElement('input'),b=document.createElement('button'),sr=document.createElement('span');
          s.textContent=p.name+(p.branch?' · '+p.branch:'');h.type='hidden';h.name='guests[]';h.value=p.id;b.type='button';b.className='btn sm';b.setAttribute('data-cal-remove','');b.textContent='Remove';sr.className='sr-only';sr.textContent=' '+p.name;b.appendChild(sr);
          li.append(s,h,b);list.appendChild(li);st.textContent=p.name+' added as a guest.';res.textContent='';q.value='';q.focus()}
        list.addEventListener('click',function(e){var b=e.target.closest('[data-cal-remove]');if(!b)return;var n=b.parentNode.querySelector('span').textContent;b.parentNode.remove();st.textContent=n+' removed.';q.focus()});
        q.addEventListener('keydown',function(e){if(e.key==='Enter'){e.preventDefault();var first=res.querySelector('button');if(first)first.click()}});
        q.addEventListener('input',function(){clearTimeout(timer);var term=q.value.trim();if(term.length<2){res.textContent='';st.textContent='Type at least 2 letters.';return}
          timer=setTimeout(function(){fetch(pk.dataset.endpoint+'&q='+encodeURIComponent(term),{credentials:'same-origin',headers:{'Accept':'application/json'}}).then(function(r){if(!r.ok)throw 0;return r.json()}).then(function(j){res.textContent='';var n=0;
            (j.people||[]).forEach(function(p){if(has(p.id))return;n++;var li=document.createElement('li'),b=document.createElement('button');b.type='button';b.className='cal-result';b.textContent=p.name+(p.branch?' · '+p.branch:'');b.addEventListener('click',function(){add(p)});li.appendChild(b);res.appendChild(li)});
            st.textContent=n?n+' result'+(n===1?'':'s')+'. Press Enter to add the first.':'No matching active employee.'}).catch(function(){st.textContent='Search failed. Try again.'})},250)});});}
      init();if(window.__hrisCalNav)return;window.__hrisCalNav=1;
      // Month links swap only the calendar in place so the page does not jump (John 2026-11-07). Without JS the links still work.
      document.addEventListener('click',function(e){var a=e.target.closest('#calendar [data-cal-nav]');if(!a||e.ctrlKey||e.metaKey||e.shiftKey||e.button)return;e.preventDefault();
        var sec=document.getElementById('calendar'),which=a.dataset.calNav,url=a.href.split('#')[0];sec.setAttribute('aria-busy','true');
        fetch(url,{credentials:'same-origin'}).then(function(r){if(!r.ok)throw 0;return r.text()}).then(function(html){var n=new DOMParser().parseFromString(html,'text/html').getElementById('calendar');if(!n)throw 0;
          sec.replaceWith(n);history.replaceState(null,'',url);init();var f=n.querySelector('[data-cal-nav="'+which+'"]');if(f)f.focus({preventScroll:true})}).catch(function(){location.href=a.href})});})();</script>
<?php }
