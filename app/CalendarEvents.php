<?php
declare(strict_types=1);

/**
 * Manual dashboard calendar events (migration 039, John 2026-11-07). Display only: never an input to payroll
 * day types or cutoffs. Any HR Dashboard user (dashboard.hr.view) may add, edit and remove; employees see only
 * rows marked visible_to_employees, without who entered them.
 */
final class CalendarEvents
{
    /** code => [label, short label for day cells]. Order is the form and legend order. */
    public const TYPES = [
        'PAYROLL_CUTOFF' => ['Payroll cutoff', 'Cutoff'],
        'PAY_DATE' => ['Pay date', 'Pay date'],
        'HOLIDAY' => ['Holiday', 'Holiday'],
        'COMPANY_EVENT' => ['Company event', 'Event'],
        'MEETING' => ['Meeting', 'Meeting'],
        'OTHER' => ['Other', 'Other'],
    ];
    /** Types that default to "show on Employee dashboard" in the form; meetings and other stay HR-only unless ticked. */
    public const EMPLOYEE_DEFAULT = ['PAY_DATE', 'HOLIDAY', 'COMPANY_EVENT'];
    /** Payroll cutoffs are fixed (payrollSchedule), so HR cannot type one in (John 2026-11-07). */
    public const MANUAL = ['PAY_DATE', 'HOLIDAY', 'COMPANY_EVENT', 'MEETING', 'OTHER'];

    public static function ready(): bool
    {
        try { return (bool)db()->query("SHOW TABLES LIKE 'calendar_events'")->fetchColumn(); } catch (Throwable) { return false; }
    }

    /** First day of the requested month (?cal=YYYY-MM, 2000-2100); anything else means this month. */
    public static function month(?string $ym): DateTimeImmutable
    {
        $now = new DateTimeImmutable('first day of this month');
        if (!is_string($ym) || !preg_match('/^(20\d{2}|2100)-(0[1-9]|1[0-2])$/', $ym)) return $now->setTime(0, 0);
        return (new DateTimeImmutable($ym.'-01'))->setTime(0, 0);
    }

    /**
     * Fixed payroll schedule for the month (John 2026-11-07): cutoff 4-18 is paid on the 25th; cutoff 19-3 is paid on the 10th
     * of the next month. Read-only rows (fixed=true, id 0), shown to everyone.
     * shortcut: computed from that rule, not read from payroll_cutoffs (all 48 stored cutoffs matched it on 2026-11-07); read the table if cutoffs ever vary.
     */
    public static function payrollSchedule(DateTimeImmutable $first): array
    {
        $prev = $first->modify('-1 month'); $next = $first->modify('+1 month'); $out = [];
        $fmt = static fn(string $a, string $b) => date('M j', strtotime($a)).' - '.(substr($a, 0, 7) === substr($b, 0, 7) ? date('j', strtotime($b)) : date('M j', strtotime($b)));
        foreach ([[$prev->format('Y-m-19'), $first->format('Y-m-03'), $first->format('Y-m-10')], [$first->format('Y-m-04'), $first->format('Y-m-18'), $first->format('Y-m-25')], [$first->format('Y-m-19'), $next->format('Y-m-03'), null]] as [$from, $to, $pay]) {
            $out[] = ['id' => 0, 'fixed' => true, 'title' => 'Payroll cutoff '.$fmt($from, $to), 'event_type' => 'PAYROLL_CUTOFF', 'start_date' => $from, 'end_date' => $to, 'start_time' => null, 'end_time' => null, 'notes' => null, 'visible_to_employees' => 1];
            if ($pay) $out[] = ['id' => 0, 'fixed' => true, 'title' => 'Pay date (cutoff '.$fmt($from, $to).')', 'event_type' => 'PAY_DATE', 'start_date' => $pay, 'end_date' => $pay, 'start_time' => null, 'end_time' => null, 'notes' => null, 'visible_to_employees' => 1];
        }
        return $out;
    }

    /** Fixed payroll schedule plus manual events overlapping the month, by start date. $employeeView keeps only employee-visible rows and drops author columns. */
    public static function forMonth(DateTimeImmutable $first, bool $employeeView, int $viewerId = 0): array
    {
        $rows = [];
        if (self::ready()) {
            // Employees see "Everyone" events, plus "Invited guests only" events they are a guest of (migration 040).
            $sql = 'SELECT e.id,e.title,e.event_type,e.start_date,e.end_date,e.start_time,e.end_time,e.notes,e.meeting_url,e.visible_to_employees FROM calendar_events e'
                .' WHERE e.deleted_at IS NULL AND e.start_date<=? AND e.end_date>=?'
                .($employeeView ? ' AND (e.visible_to_employees=1 OR EXISTS(SELECT 1 FROM calendar_event_guests g WHERE g.event_id=e.id AND g.user_id=?))' : '')
                .' ORDER BY e.start_date,e.start_time IS NULL,e.start_time,e.id LIMIT 300';
            $q = db()->prepare($sql);
            $q->execute(array_merge([$first->format('Y-m-t'), $first->format('Y-m-d')], $employeeView ? [$viewerId] : []));
            $rows = $q->fetchAll();
            if (!$employeeView && $rows) {// HR edit forms need the guest list; employees never get it
                $ids = array_column($rows, 'id'); $guests = [];
                $g = db()->prepare('SELECT g.event_id,u.id,u.full_name name FROM calendar_event_guests g JOIN users u ON u.id=g.user_id WHERE g.event_id IN ('.implode(',', array_fill(0, count($ids), '?')).') ORDER BY u.full_name');
                $g->execute($ids);
                foreach ($g->fetchAll() as $r) $guests[(int)$r['event_id']][] = ['id' => (int)$r['id'], 'name' => (string)$r['name']];
                foreach ($rows as &$r) $r['guests'] = $guests[(int)$r['id']] ?? []; unset($r);
            }
        }
        $all = array_merge(self::payrollSchedule($first), $rows);
        usort($all, static fn($a, $b) => [$a['start_date'], empty($a['fixed'])] <=> [$b['start_date'], empty($b['fixed'])]);
        return $all;
    }

    /** Who can see an event: ALL = every employee, GUESTS = only invited guests (plus HR), HR = HR Dashboard users only. */
    public const AUDIENCES = ['ALL' => 'Everyone', 'GUESTS' => 'Invited guests only', 'HR' => 'HR only'];
    public const MAX_GUESTS = 200;

    /** Validate and create (id 0) or update. Returns the event id. Throws RuntimeException with a user-facing message. */
    public static function save(array $in, int $userId): int
    {
        $id = (int)($in['id'] ?? 0);
        $title = trim((string)($in['title'] ?? ''));
        if ($title === '' || mb_strlen($title) > 120) throw new RuntimeException('Enter a title of up to 120 characters.');
        $type = (string)($in['event_type'] ?? '');
        if (!in_array($type, self::MANUAL, true)) throw new RuntimeException('Choose an event type from the list. Payroll cutoffs are fixed on the calendar.');
        $start = self::date($in['start_date'] ?? '');
        $endRaw = trim((string)($in['end_date'] ?? ''));
        $end = $endRaw === '' ? $start : self::date($endRaw);
        if ($start === null || $end === null) throw new RuntimeException('Enter valid dates.');
        if ($end < $start) throw new RuntimeException('The end date cannot be before the start date.');
        $from = self::time($in['start_time'] ?? ''); $to = self::time($in['end_time'] ?? '');
        if ($from === false || $to === false) throw new RuntimeException('Enter valid times.');
        if ($to !== null && $from === null) throw new RuntimeException('Enter a start time before an end time.');
        if ($from !== null && $to !== null && $start === $end && $to < $from) throw new RuntimeException('The end time cannot be before the start time.');
        $notes = trim((string)($in['notes'] ?? ''));
        if (mb_strlen($notes) > 500) throw new RuntimeException('Notes can be up to 500 characters.');
        $notes = $notes === '' ? null : $notes;
        $link = self::meetingUrl($in['meeting_url'] ?? '');
        $audience = (string)($in['audience'] ?? (!empty($in['visible_to_employees']) ? 'ALL' : 'HR'));
        if (!isset(self::AUDIENCES[$audience])) throw new RuntimeException('Choose who can see this event.');
        $guests = $audience === 'GUESTS' ? self::guestIds($in['guests'] ?? []) : [];
        if ($audience === 'GUESTS' && !$guests) throw new RuntimeException('Add at least one guest, or choose Everyone or HR only.');
        $visible = $audience === 'ALL' ? 1 : 0;
        $vals = [$title, $type, $start, $end, $from, $to, $notes, $link, $visible, $userId];
        $pdo = db(); $pdo->beginTransaction();
        try {
            if ($id > 0) {
                $pdo->prepare('UPDATE calendar_events SET title=?,event_type=?,start_date=?,end_date=?,start_time=?,end_time=?,notes=?,meeting_url=?,visible_to_employees=?,updated_by=? WHERE id=? AND deleted_at IS NULL')->execute([...$vals, $id]);
                if (!self::exists($id)) throw new RuntimeException('That calendar event no longer exists.');
                $pdo->prepare('DELETE FROM calendar_event_guests WHERE event_id=?')->execute([$id]);
                $action = 'CALENDAR_EVENT_UPDATED';
            } else {
                $pdo->prepare('INSERT INTO calendar_events(title,event_type,start_date,end_date,start_time,end_time,notes,meeting_url,visible_to_employees,created_by) VALUES(?,?,?,?,?,?,?,?,?,?)')->execute($vals);
                $id = (int)$pdo->lastInsertId();
                $action = 'CALENDAR_EVENT_CREATED';
            }
            $ins = $pdo->prepare('INSERT INTO calendar_event_guests(event_id,user_id,added_by) VALUES(?,?,?)');
            foreach ($guests as $g) $ins->execute([$id, $g, $userId]);
            audit('Calendar', $action, 'calendar_event', $id, ['type' => $type, 'start' => $start, 'end' => $end, 'audience' => $audience, 'guests' => count($guests), 'link' => $link !== null]);
            $pdo->commit();
        } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
        return $id;
    }

    /** '' => null; otherwise an absolute http(s) URL up to 500 characters (no javascript:, data:, ftp: ...). */
    private static function meetingUrl(mixed $v): ?string
    {
        $v = trim((string)$v);
        if ($v === '') return null;
        $scheme = strtolower((string)parse_url($v, PHP_URL_SCHEME));
        if (mb_strlen($v) > 500 || !in_array($scheme, ['http', 'https'], true) || filter_var($v, FILTER_VALIDATE_URL) === false || (string)parse_url($v, PHP_URL_HOST) === '')
            throw new RuntimeException('Enter a meeting link that starts with https:// (Zoom, Google Meet, Teams or another meeting app).');
        return $v;
    }

    /** Guest user ids: unique, active accounts on the employee/HR/admin portals, at most MAX_GUESTS. */
    private static function guestIds(mixed $raw): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', is_array($raw) ? $raw : []), fn($i) => $i > 0)));
        if (count($ids) > self::MAX_GUESTS) throw new RuntimeException('Up to '.self::MAX_GUESTS.' guests per event.');
        if (!$ids) return [];
        $q = db()->prepare('SELECT COUNT(*) FROM users u JOIN roles r ON r.id=u.role_id AND r.portal IN ("employee","hr","admin") WHERE u.status="ACTIVE" AND u.id IN ('.implode(',', array_fill(0, count($ids), '?')).')');
        $q->execute($ids);
        if ((int)$q->fetchColumn() !== count($ids)) throw new RuntimeException('One of the guests is not an active account. Remove them and try again.');
        return $ids;
    }


    /** Soft delete; the row stays for history and the 039 rollback guard. */
    public static function remove(int $id, int $userId): void
    {
        $q = db()->prepare('UPDATE calendar_events SET deleted_at=NOW(),deleted_by=? WHERE id=? AND deleted_at IS NULL');
        $q->execute([$userId, $id]);
        if ($q->rowCount() !== 1) throw new RuntimeException('That calendar event no longer exists.');
        audit('Calendar', 'CALENDAR_EVENT_REMOVED', 'calendar_event', $id, null);
    }

    private static function exists(int $id): bool
    {
        $q = db()->prepare('SELECT 1 FROM calendar_events WHERE id=? AND deleted_at IS NULL'); $q->execute([$id]);
        return (bool)$q->fetchColumn();
    }

    /** Strict Y-m-d (2000-2100), round-tripped so 2026-02-30 is refused. */
    private static function date(mixed $v): ?string
    {
        $v = trim((string)$v);
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v);
        return $d && $d->format('Y-m-d') === $v && $v >= '2000-01-01' && $v <= '2100-12-31' ? $v : null;
    }

    /** '' => null (no time), valid H:i => 'H:i:00', anything else => false. */
    private static function time(mixed $v): string|null|false
    {
        $v = trim((string)$v);
        if ($v === '') return null;
        $t = DateTimeImmutable::createFromFormat('!H:i', $v);
        return $t && $t->format('H:i') === $v ? $v.':00' : false;
    }
}
