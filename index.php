<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

/* ════════════════════════════════════════════════════
   DATABASE CONNECTION
════════════════════════════════════════════════════ */
function db(){
    $url = getenv("DATABASE_URL");
    if(!$url) die("DATABASE_URL is missing.");
    $p = parse_url($url);
    $dsn = "pgsql:host={$p['host']};port=".($p['port']??5432).";dbname=".ltrim($p['path'],'/').";sslmode=require";
    return new PDO($dsn,$p['user'],$p['pass'],[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC
    ]);
}
$pdo=db();

/* ════════════════════════════════════════════════════
   HELPERS
════════════════════════════════════════════════════ */
function h($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function money($v){return number_format((float)$v,0)." RWF";}
function period(){return preg_match('/^\d{4}-\d{2}$/',$_GET['period']??'')?$_GET['period']:date('Y-m');}
function view(){return $_GET['view']??'dashboard';}
function go($v,$m=''){header("Location:index.php?view=$v&period=".period()."&msg=".urlencode($m));exit;}
function due_date($period,$day){$last=(int)date('t',strtotime($period.'-01'));return $period.'-'.str_pad(min(max(1,(int)$day),$last),2,'0',STR_PAD_LEFT);}
function bill_status($expected,$paid){
    $expected=(float)$expected;$paid=(float)$paid;
    if($expected<=0)return "NO BILL";
    if($paid<=0)return "UNPAID";
    if($paid<$expected)return "PARTIAL";
    return "PAID";
}
function overdue_days($due,$status){
    if(in_array($status,['PAID','NO BILL']))return 0;
    $today=new DateTime(date('Y-m-d'));$d=new DateTime($due);
    return $today>$d?$d->diff($today)->days:0;
}

/* ════════════════════════════════════════════════════
   SCHEMA (idempotent)
════════════════════════════════════════════════════ */
function schema($pdo){
$pdo->exec("
CREATE TABLE IF NOT EXISTS academy_zones(
 id SERIAL PRIMARY KEY,
 name VARCHAR(100) NOT NULL UNIQUE,
 is_default BOOLEAN DEFAULT FALSE
);
INSERT INTO academy_zones(name,is_default) VALUES('Gisenyi',TRUE),('Rugerero',FALSE),('Byahi',FALSE) ON CONFLICT(name) DO NOTHING;

CREATE TABLE IF NOT EXISTS members(
 id SERIAL PRIMARY KEY,
 full_name VARCHAR(255) NOT NULL UNIQUE,
 phone VARCHAR(50),
 gender VARCHAR(20),
 date_of_birth DATE,
 zone_id INT REFERENCES academy_zones(id),
 guardian_name VARCHAR(255),
 guardian_phone VARCHAR(50),
 position VARCHAR(50),
 school_name VARCHAR(255),
 monthly_fee NUMERIC(12,2) DEFAULT 0,
 due_day INT DEFAULT 5,
 is_active BOOLEAN DEFAULT TRUE,
 admission_number VARCHAR(50),
 class_name VARCHAR(100),
 parent_email VARCHAR(100),
 notes TEXT,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
ALTER TABLE members ADD COLUMN IF NOT EXISTS zone_id INT REFERENCES academy_zones(id);
ALTER TABLE members ADD COLUMN IF NOT EXISTS guardian_name VARCHAR(255);
ALTER TABLE members ADD COLUMN IF NOT EXISTS guardian_phone VARCHAR(50);
ALTER TABLE members ADD COLUMN IF NOT EXISTS position VARCHAR(50);
ALTER TABLE members ADD COLUMN IF NOT EXISTS school_name VARCHAR(255);
ALTER TABLE members ADD COLUMN IF NOT EXISTS notes TEXT;
ALTER TABLE members ADD COLUMN IF NOT EXISTS admission_number VARCHAR(50);
ALTER TABLE members ADD COLUMN IF NOT EXISTS class_name VARCHAR(100);
ALTER TABLE members ADD COLUMN IF NOT EXISTS parent_email VARCHAR(100);
UPDATE members SET zone_id=(SELECT id FROM academy_zones WHERE name='Gisenyi' LIMIT 1) WHERE zone_id IS NULL;

CREATE TABLE IF NOT EXISTS sessions(
 id SERIAL PRIMARY KEY,
 name VARCHAR(255) NOT NULL,
 session_date DATE NOT NULL DEFAULT CURRENT_DATE,
 zone_id INT REFERENCES academy_zones(id),
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
ALTER TABLE sessions ADD COLUMN IF NOT EXISTS session_date DATE;
ALTER TABLE sessions ADD COLUMN IF NOT EXISTS zone_id INT REFERENCES academy_zones(id);
UPDATE sessions SET zone_id=(SELECT id FROM academy_zones WHERE name='Gisenyi' LIMIT 1) WHERE zone_id IS NULL;

CREATE TABLE IF NOT EXISTS attendance(
 id SERIAL PRIMARY KEY,
 session_id INT REFERENCES sessions(id) ON DELETE CASCADE,
 member_id INT REFERENCES members(id) ON DELETE CASCADE,
 status VARCHAR(20) DEFAULT 'present',
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(session_id,member_id)
);

CREATE TABLE IF NOT EXISTS monthly_bills(
 id SERIAL PRIMARY KEY,
 member_id INT REFERENCES members(id) ON DELETE CASCADE,
 period CHAR(7) NOT NULL,
 expected_amount NUMERIC(12,2) DEFAULT 0,
 paid_amount NUMERIC(12,2) DEFAULT 0,
 due_date DATE NOT NULL,
 note TEXT,
 paid_at TIMESTAMP,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(member_id,period)
);

CREATE TABLE IF NOT EXISTS payment_logs(
 id SERIAL PRIMARY KEY,
 member_id INT REFERENCES members(id) ON DELETE CASCADE,
 amount_paid NUMERIC(12,2) NOT NULL,
 period CHAR(7),
 note TEXT,
 payment_date DATE DEFAULT CURRENT_DATE,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
ALTER TABLE payment_logs ADD COLUMN IF NOT EXISTS period CHAR(7);
ALTER TABLE payment_logs ADD COLUMN IF NOT EXISTS payment_date DATE DEFAULT CURRENT_DATE;

CREATE TABLE IF NOT EXISTS staff(
 id SERIAL PRIMARY KEY,
 full_name VARCHAR(255) NOT NULL,
 phone VARCHAR(50),
 role VARCHAR(50) NOT NULL,
 zone_id INT REFERENCES academy_zones(id),
 monthly_salary NUMERIC(12,2) DEFAULT 0,
 is_active BOOLEAN DEFAULT TRUE,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
ALTER TABLE staff ADD COLUMN IF NOT EXISTS zone_id INT REFERENCES academy_zones(id);
UPDATE staff SET zone_id=(SELECT id FROM academy_zones WHERE name='Gisenyi' LIMIT 1) WHERE zone_id IS NULL;

CREATE TABLE IF NOT EXISTS coach_payroll(
 id SERIAL PRIMARY KEY,
 staff_id INT REFERENCES staff(id) ON DELETE CASCADE,
 period CHAR(7) NOT NULL,
 base_salary NUMERIC(12,2) DEFAULT 0,
 bonus NUMERIC(12,2) DEFAULT 0,
 deductions NUMERIC(12,2) DEFAULT 0,
 net_salary NUMERIC(12,2) DEFAULT 0,
 amount_paid NUMERIC(12,2) DEFAULT 0,
 payment_status VARCHAR(30) DEFAULT 'UNPAID',
 paid_at TIMESTAMP,
 note TEXT,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(staff_id,period)
);
ALTER TABLE coach_payroll ADD COLUMN IF NOT EXISTS net_salary NUMERIC(12,2) DEFAULT 0;
ALTER TABLE coach_payroll ADD COLUMN IF NOT EXISTS payment_status VARCHAR(30) DEFAULT 'UNPAID';

CREATE TABLE IF NOT EXISTS expenses(
 id SERIAL PRIMARY KEY,
 expense_date DATE DEFAULT CURRENT_DATE,
 category VARCHAR(100),
 description TEXT NOT NULL,
 amount NUMERIC(12,2) NOT NULL,
 paid_to VARCHAR(255),
 approved_by VARCHAR(255),
 zone_id INT REFERENCES academy_zones(id),
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
ALTER TABLE expenses ADD COLUMN IF NOT EXISTS zone_id INT REFERENCES academy_zones(id);
UPDATE expenses SET zone_id=(SELECT id FROM academy_zones WHERE name='Gisenyi' LIMIT 1) WHERE zone_id IS NULL;

CREATE TABLE IF NOT EXISTS athlete_uniforms(
 id SERIAL PRIMARY KEY,
 member_id INT REFERENCES members(id) ON DELETE CASCADE,
 jersey_number INT NOT NULL,
 jersey_category VARCHAR(60) NOT NULL,
 jersey_size VARCHAR(20) NOT NULL,
 jersey_chest NUMERIC(6,2),
 jersey_length NUMERIC(6,2),
 shorts_category VARCHAR(60) NOT NULL,
 shorts_size VARCHAR(20) NOT NULL,
 shorts_waist NUMERIC(6,2),
 shorts_inseam NUMERIC(6,2),
 quantity INT DEFAULT 1,
 issued_date DATE DEFAULT CURRENT_DATE,
 note TEXT,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(jersey_number)
);
");
}
schema($pdo);

/* ════════════════════════════════════════════════════
   DATA HELPERS
════════════════════════════════════════════════════ */
function zones($pdo){return $pdo->query("SELECT * FROM academy_zones ORDER BY id")->fetchAll();}
function default_zone($pdo){$r=$pdo->query("SELECT id FROM academy_zones WHERE is_default=TRUE LIMIT 1")->fetchColumn();return $r?$r:1;}
function members($pdo){
    return $pdo->query("SELECT m.*,z.name zone_name FROM members m LEFT JOIN academy_zones z ON z.id=m.zone_id ORDER BY z.id,m.full_name")->fetchAll();
}
function active_members($pdo){
    return $pdo->query("SELECT m.*,z.name zone_name FROM members m LEFT JOIN academy_zones z ON z.id=m.zone_id WHERE m.is_active=TRUE ORDER BY z.id,m.full_name")->fetchAll();
}
function staff($pdo){
    return $pdo->query("SELECT s.*,z.name zone_name FROM staff s LEFT JOIN academy_zones z ON z.id=s.zone_id ORDER BY z.id,s.full_name")->fetchAll();
}
function sessions($pdo){
    return $pdo->query("SELECT s.*,COALESCE(s.session_date,s.date) AS session_date,z.name zone_name FROM sessions s LEFT JOIN academy_zones z ON z.id=s.zone_id ORDER BY COALESCE(s.session_date,s.date) DESC,s.id DESC")->fetchAll();
}
function ensure_bill($pdo,$member_id,$period){
    $m=$pdo->prepare("SELECT * FROM members WHERE id=?");$m->execute([$member_id]);$m=$m->fetch();
    if(!$m)return;
    $due=due_date($period,$m['due_day']??5);
    $stmt=$pdo->prepare("INSERT INTO monthly_bills(member_id,period,expected_amount,paid_amount,due_date) VALUES(?,?,?,?,?) ON CONFLICT(member_id,period) DO NOTHING");
    $stmt->execute([$member_id,$period,$m['monthly_fee']??0,0,$due]);
}

function athletes_with_attendance($pdo,$period){
    $stmt=$pdo->prepare("
        SELECT DISTINCT
            m.id, m.full_name, m.phone, m.guardian_name,
            z.name AS zone_name,
            COALESCE(b.expected_amount,0) AS expected_amount,
            COALESCE(b.paid_amount,0) AS paid_amount,
            GREATEST(COALESCE(b.expected_amount,0)-COALESCE(b.paid_amount,0),0) AS remaining,
            COUNT(DISTINCT s.id) AS sessions_attended
        FROM members m
        LEFT JOIN academy_zones z ON z.id=m.zone_id
        LEFT JOIN monthly_bills b ON b.member_id=m.id AND b.period=?
        LEFT JOIN attendance a ON a.member_id=m.id
        LEFT JOIN sessions s ON s.id=a.session_id
            AND TO_CHAR(COALESCE(s.session_date,s.date),'YYYY-MM')=?
        WHERE m.is_active=TRUE AND a.id IS NOT NULL
        GROUP BY m.id,m.full_name,m.phone,m.guardian_name,z.name,b.expected_amount,b.paid_amount
        HAVING COUNT(DISTINCT s.id)>0
        ORDER BY z.name,m.full_name
    ");
    $stmt->execute([$period,$period]);
    return $stmt->fetchAll();
}

function non_payers_with_attendance($pdo,$period,$attendance_month=null){
    $att_month=$attendance_month?:$period;
    $stmt=$pdo->prepare("
        SELECT DISTINCT
            m.id,m.full_name,m.phone,m.guardian_name,m.guardian_phone,
            z.name AS zone_name,
            COALESCE(b.expected_amount,0) AS expected_amount,
            COALESCE(b.paid_amount,0) AS paid_amount,
            GREATEST(COALESCE(b.expected_amount,0)-COALESCE(b.paid_amount,0),0) AS remaining,
            COUNT(DISTINCT s.id) AS sessions_attended,
            STRING_AGG(DISTINCT s.name||' ('||COALESCE(s.session_date,s.date)||')',', ') AS sessions_list
        FROM members m
        LEFT JOIN academy_zones z ON z.id=m.zone_id
        LEFT JOIN monthly_bills b ON b.member_id=m.id AND b.period=?
        LEFT JOIN attendance a ON a.member_id=m.id
        LEFT JOIN sessions s ON s.id=a.session_id
            AND TO_CHAR(COALESCE(s.session_date,s.date),'YYYY-MM')=?
        WHERE m.is_active=TRUE
            AND (b.paid_amount IS NULL OR b.paid_amount<COALESCE(b.expected_amount,0))
            AND a.id IS NOT NULL
        GROUP BY m.id,m.full_name,m.phone,m.guardian_name,m.guardian_phone,z.name,b.expected_amount,b.paid_amount
        HAVING COUNT(DISTINCT s.id)>0
        ORDER BY z.name,m.full_name
    ");
    $stmt->execute([$period,$att_month]);
    return $stmt->fetchAll();
}

function overdue_payments_report($pdo,$period){
    $stmt=$pdo->prepare("
        SELECT m.id AS member_id, m.full_name, m.phone, m.guardian_name,
               z.name AS zone_name, b.*,
               GREATEST(b.expected_amount-b.paid_amount,0) AS remaining,
               GREATEST(DATE_PART('day',(CURRENT_DATE::timestamp - b.due_date::timestamp))::int, 0) AS days_overdue
        FROM monthly_bills b
        JOIN members m ON m.id=b.member_id
        LEFT JOIN academy_zones z ON z.id=m.zone_id
        WHERE b.period=?
          AND b.paid_amount < b.expected_amount
          AND b.due_date < CURRENT_DATE
          AND m.is_active=TRUE
        ORDER BY b.due_date ASC
    ");
    $stmt->execute([$period]);
    return $stmt->fetchAll();
}

function attendance_summary($pdo,$member_id=null,$year_month=null){
    $sql="
        SELECT m.id,m.full_name,z.name AS zone_name,
            COUNT(DISTINCT s.id) AS total_sessions,
            SUM(CASE WHEN a.status='present' THEN 1 ELSE 0 END) AS present_count,
            SUM(CASE WHEN a.status='absent'  THEN 1 ELSE 0 END) AS absent_count,
            SUM(CASE WHEN a.status='late'    THEN 1 ELSE 0 END) AS late_count,
            ROUND(
              (SUM(CASE WHEN a.status IN ('present','late') THEN 1 ELSE 0 END)::decimal
               / NULLIF(COUNT(DISTINCT s.id),0)*100),1
            ) AS attendance_rate
        FROM members m
        LEFT JOIN academy_zones z ON z.id=m.zone_id
        LEFT JOIN attendance a ON a.member_id=m.id
        LEFT JOIN sessions s ON s.id=a.session_id
        WHERE m.is_active=TRUE
    ";
    $params=[];
    if($member_id){$sql.=" AND m.id=?";$params[]=$member_id;}
    if($year_month){$sql.=" AND TO_CHAR(COALESCE(s.session_date,s.date),'YYYY-MM')=?";$params[]=$year_month;}
    $sql.=" GROUP BY m.id,m.full_name,z.name ORDER BY m.full_name";
    $stmt=$pdo->prepare($sql);$stmt->execute($params);
    return $stmt->fetchAll();
}

/* ════════════════════════════════════════════════════
   ATTENDANCE REPORT GENERATOR
════════════════════════════════════════════════════ */
function generateAttendanceReport($pdo, $yearMonth, $format = 'pdf') {
    $period = $yearMonth;
    $year = (int)substr($period, 0, 4);
    $month = (int)substr($period, 5, 2);
    $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $month, $year);
    
    // Get all active children
    $children = $pdo->query("
        SELECT m.id, m.full_name, m.phone, m.guardian_name, m.guardian_phone,
               m.position, m.school_name, z.name as zone_name,
               COALESCE(m.admission_number, 'N/A') as admission_number,
               COALESCE(m.class_name, 'N/A') as class_name,
               COALESCE(m.parent_email, '') as parent_email
        FROM members m
        LEFT JOIN academy_zones z ON z.id = m.zone_id
        WHERE m.is_active = TRUE
        ORDER BY m.full_name
    ")->fetchAll();
    
    // Get attendance records for the period
    $attendance = $pdo->prepare("
        SELECT a.member_id, a.status, 
               EXTRACT(DAY FROM COALESCE(s.session_date, s.date)) as day_num
        FROM attendance a
        JOIN sessions s ON s.id = a.session_id
        WHERE TO_CHAR(COALESCE(s.session_date, s.date), 'YYYY-MM') = ?
        ORDER BY s.session_date, a.member_id
    ");
    $attendance->execute([$period]);
    $attRecords = $attendance->fetchAll();
    
    // Build attendance matrix
    $attMatrix = [];
    $sessionDays = [];
    foreach ($attRecords as $rec) {
        $day = (int)$rec['day_num'];
        $sessionDays[$day] = true;
        $attMatrix[$rec['member_id']][$day] = strtolower($rec['status']);
    }
    
    $sessionDayList = array_keys($sessionDays);
    sort($sessionDayList);
    
    if (empty($sessionDayList)) {
        $sessionDayList = range(1, $daysInMonth);
    }
    
    // Build report data
    $reportData = [];
    $academyTotals = [
        'total_present' => 0,
        'total_absent' => 0,
        'total_late' => 0,
        'total_excused' => 0,
        'total_children' => count($children),
        'total_possible' => 0,
        'total_actual' => 0
    ];
    
    foreach ($children as $child) {
        $row = [
            'full_name' => $child['full_name'],
            'admission_number' => $child['admission_number'],
            'class_name' => $child['class_name'],
            'guardian_name' => $child['guardian_name'],
            'guardian_phone' => $child['guardian_phone'],
            'parent_email' => $child['parent_email'],
            'zone_name' => $child['zone_name'],
            'position' => $child['position'],
            'school_name' => $child['school_name'],
            'phone' => $child['phone'],
            'daily_status' => [],
            'totals' => ['present' => 0, 'absent' => 0, 'late' => 0, 'excused' => 0, 'no_record' => 0]
        ];
        
        $childId = $child['id'];
        $hasAnyRecord = false;
        
        foreach ($sessionDayList as $day) {
            $status = $attMatrix[$childId][$day] ?? 'no_record';
            $row['daily_status'][$day] = $status;
            
            if ($status !== 'no_record') {
                $hasAnyRecord = true;
                if ($status === 'present') $row['totals']['present']++;
                elseif ($status === 'absent') $row['totals']['absent']++;
                elseif ($status === 'late') $row['totals']['late']++;
                elseif ($status === 'excused') $row['totals']['excused']++;
            } else {
                $row['totals']['no_record']++;
            }
        }
        
        $totalSessions = count($sessionDayList);
        $row['totals']['total_sessions'] = $totalSessions;
        $row['totals']['attended'] = $row['totals']['present'] + $row['totals']['late'];
        $row['totals']['attendance_percentage'] = $totalSessions > 0 
            ? round(($row['totals']['attended'] / $totalSessions) * 100, 1) 
            : 0;
        
        if ($hasAnyRecord) {
            $academyTotals['total_present'] += $row['totals']['present'];
            $academyTotals['total_absent'] += $row['totals']['absent'];
            $academyTotals['total_late'] += $row['totals']['late'];
            $academyTotals['total_excused'] += $row['totals']['excused'];
            $academyTotals['total_actual'] += $row['totals']['attended'];
            $academyTotals['total_possible'] += $totalSessions;
        }
        
        $reportData[] = $row;
    }
    
    $academyTotals['attendance_percentage'] = $academyTotals['total_possible'] > 0
        ? round(($academyTotals['total_actual'] / $academyTotals['total_possible']) * 100, 1)
        : 0;
    
    if ($format === 'excel') {
        exportAttendanceExcel($reportData, $sessionDayList, $period, $academyTotals);
    } else {
        exportAttendancePDF($reportData, $sessionDayList, $period, $academyTotals);
    }
}

function exportAttendanceExcel($reportData, $sessionDayList, $period, $totals) {
    $dateObj = DateTime::createFromFormat('Y-m', $period);
    $monthName = $dateObj ? $dateObj->format('F Y') : $period;
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="Attendance_Report_' . $period . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
    
    fputcsv($out, ['ACADEMY ATTENDANCE REPORT']);
    fputcsv($out, ['Period:', $monthName]);
    fputcsv($out, ['Generated:', date('Y-m-d H:i:s')]);
    fputcsv($out, []);
    
    $headers = ['#', 'Full Name', 'Admission No.', 'Class', 'Guardian', 'Guardian Phone'];
    foreach ($sessionDayList as $day) {
        $headers[] = 'Day ' . $day;
    }
    $headers = array_merge($headers, ['Present', 'Absent', 'Late', 'Excused', 'No Record', 'Total Sessions', 'Attendance %']);
    fputcsv($out, $headers);
    
    $index = 1;
    foreach ($reportData as $row) {
        $csvRow = [
            $index++,
            $row['full_name'],
            $row['admission_number'],
            $row['class_name'],
            $row['guardian_name'],
            $row['guardian_phone']
        ];
        
        foreach ($sessionDayList as $day) {
            $status = $row['daily_status'][$day] ?? 'no_record';
            $display = ucfirst(str_replace('_', ' ', $status));
            $csvRow[] = $display;
        }
        
        $csvRow = array_merge($csvRow, [
            $row['totals']['present'],
            $row['totals']['absent'],
            $row['totals']['late'],
            $row['totals']['excused'],
            $row['totals']['no_record'],
            $row['totals']['total_sessions'],
            $row['totals']['attendance_percentage'] . '%'
        ]);
        
        fputcsv($out, $csvRow);
    }
    
    fputcsv($out, []);
    fputcsv($out, ['--- ACADEMY SUMMARY ---']);
    fputcsv($out, ['Total Children', $totals['total_children']]);
    fputcsv($out, ['Total Present', $totals['total_present']]);
    fputcsv($out, ['Total Absent', $totals['total_absent']]);
    fputcsv($out, ['Total Late', $totals['total_late']]);
    fputcsv($out, ['Total Excused', $totals['total_excused']]);
    fputcsv($out, ['Overall Attendance %', $totals['attendance_percentage'] . '%']);
    
    fclose($out);
    exit;
}

function exportAttendancePDF($reportData, $sessionDayList, $period, $totals) {
    $dateObj = DateTime::createFromFormat('Y-m', $period);
    $monthName = $dateObj ? $dateObj->format('F Y') : $period;
    $ts = date('Y-m-d H:i');
    
    $headers = '';
    foreach ($sessionDayList as $day) {
        $headers .= '<th style="padding:4px 6px;text-align:center;font-size:8px;min-width:32px;">' . $day . '</th>';
    }
    
    $rows = '';
    $index = 1;
    foreach ($reportData as $row) {
        $cells = '';
        foreach ($sessionDayList as $day) {
            $status = $row['daily_status'][$day] ?? 'no_record';
            $class = '';
            $display = 'NR';
            if ($status === 'present') { $class = 'bg-present'; $display = 'P'; }
            elseif ($status === 'absent') { $class = 'bg-absent'; $display = 'A'; }
            elseif ($status === 'late') { $class = 'bg-late'; $display = 'L'; }
            elseif ($status === 'excused') { $class = 'bg-excused'; $display = 'E'; }
            $cells .= '<td class="' . $class . '" style="text-align:center;font-size:8px;padding:2px 3px;">' . $display . '</td>';
        }
        
        $percent = $row['totals']['attendance_percentage'];
        $color = $percent >= 80 ? '#155724' : ($percent >= 60 ? '#856404' : '#721c24');
        
        $rows .= '<tr>';
        $rows .= '<td style="padding:3px 5px;font-size:9px;text-align:center;">' . $index++ . '</td>';
        $rows .= '<td style="padding:3px 5px;font-size:9px;font-weight:600;white-space:nowrap;">' . htmlspecialchars($row['full_name']) . '</td>';
        $rows .= '<td style="padding:3px 5px;font-size:8px;">' . htmlspecialchars($row['admission_number']) . '</td>';
        $rows .= '<td style="padding:3px 5px;font-size:8px;">' . htmlspecialchars($row['class_name']) . '</td>';
        $rows .= '<td style="padding:3px 5px;font-size:8px;">' . htmlspecialchars($row['guardian_name']) . '</td>';
        $rows .= '<td style="padding:3px 5px;font-size:8px;">' . htmlspecialchars($row['guardian_phone']) . '</td>';
        $rows .= $cells;
        $rows .= '<td style="text-align:center;font-size:9px;padding:2px 4px;font-weight:600;">' . $row['totals']['present'] . '</td>';
        $rows .= '<td style="text-align:center;font-size:9px;padding:2px 4px;font-weight:600;">' . $row['totals']['absent'] . '</td>';
        $rows .= '<td style="text-align:center;font-size:9px;padding:2px 4px;font-weight:600;">' . $row['totals']['late'] . '</td>';
        $rows .= '<td style="text-align:center;font-size:9px;padding:2px 4px;font-weight:600;">' . $row['totals']['excused'] . '</td>';
        $rows .= '<td style="text-align:center;font-size:9px;padding:2px 4px;font-weight:700;color:' . $color . ';">' . $percent . '%</td>';
        $rows .= '</tr>';
    }
    
    $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Attendance Report - $period</title>
<style>
  *{margin:0;padding:0;box-sizing:border-box;}
  body{font-family:'Segoe UI',Arial,sans-serif;background:#fff;color:#222;padding:20px;font-size:10px;}
  .header{display:flex;justify-content:space-between;align-items:center;border-bottom:3px solid #1a3a5c;padding-bottom:10px;margin-bottom:15px;}
  .header h1{font-size:20px;color:#1a3a5c;}
  .header .meta{text-align:right;font-size:10px;color:#666;}
  .sub-title{font-size:11px;color:#444;margin-bottom:12px;background:#f5f7fa;padding:8px 12px;border-radius:6px;}
  table{width:100%;border-collapse:collapse;font-size:9px;}
  thead th{background:#1a3a5c;color:#fff;padding:4px 6px;text-align:center;font-size:8px;text-transform:uppercase;letter-spacing:0.3px;white-space:nowrap;}
  tbody td{border-bottom:1px solid #e5e7eb;padding:3px 5px;}
  tbody tr:nth-child(even){background:#f9fafb;}
  .bg-present{background:#d4edda;color:#155724;}
  .bg-absent{background:#f8d7da;color:#721c24;}
  .bg-late{background:#fff3cd;color:#856404;}
  .bg-excused{background:#d1ecf1;color:#0c5460;}
  .summary-box{display:flex;gap:12px;flex-wrap:wrap;margin:15px 0;padding:12px 15px;background:#f5f7fa;border-radius:6px;border:1px solid #dde1e6;}
  .summary-item{flex:1;min-width:80px;text-align:center;}
  .summary-item .label{font-size:8px;text-transform:uppercase;color:#666;letter-spacing:0.3px;}
  .summary-item .value{font-size:16px;font-weight:700;color:#1a3a5c;}
  .summary-item .value.green{color:#155724;}
  .summary-item .value.red{color:#721c24;}
  .summary-item .value.amber{color:#856404;}
  .footer{margin-top:20px;border-top:1px solid #ddd;padding-top:8px;font-size:8px;color:#888;display:flex;justify-content:space-between;}
  .badge{display:inline-block;padding:1px 6px;border-radius:999px;font-size:7px;font-weight:600;}
  .badge-present{background:#d4edda;color:#155724;}
  .badge-absent{background:#f8d7da;color:#721c24;}
  .badge-late{background:#fff3cd;color:#856404;}
  .badge-excused{background:#d1ecf1;color:#0c5460;}
  .badge-nr{background:#e9ecef;color:#495057;}
  @media print{
    body{padding:10px;}
    .no-print{display:none!important;}
    thead th{-webkit-print-color-adjust:exact;print-color-adjust:exact;}
    .bg-present,.bg-absent,.bg-late,.bg-excused{-webkit-print-color-adjust:exact;print-color-adjust:exact;}
    tbody tr:nth-child(even){-webkit-print-color-adjust:exact;print-color-adjust:exact;background:#f9fafb!important;}
  }
  .no-print{margin-bottom:10px;}
</style>
</head>
<body>
<div class="no-print">
  <button onclick="window.print()" style="background:#1a3a5c;color:#fff;border:0;border-radius:4px;padding:8px 16px;font-size:12px;font-weight:600;cursor:pointer;margin-right:6px;">🖨 Print / Save as PDF</button>
  <button onclick="window.close()" style="background:#eee;border:1px solid #ccc;border-radius:4px;padding:8px 16px;font-size:12px;cursor:pointer;">✕ Close</button>
</div>

<div class="header">
  <div>
    <h1>📋 Attendance Report</h1>
    <div style="font-size:10px;color:#666;margin-top:2px;">Academy Management System</div>
  </div>
  <div class="meta">
    <div><strong>$monthName</strong></div>
    <div>Generated: $ts</div>
  </div>
</div>

<div class="sub-title">
  <strong>Period:</strong> $monthName &nbsp;|&nbsp; 
  <strong>Total Children:</strong> {$totals['total_children']} &nbsp;|&nbsp;
  <strong>Report Days:</strong> ${count($sessionDayList)} sessions
  <div style="margin-top:4px;font-size:9px;color:#666;">
    <span class="badge badge-present">P = Present</span>
    <span class="badge badge-absent">A = Absent</span>
    <span class="badge badge-late">L = Late</span>
    <span class="badge badge-excused">E = Excused</span>
    <span class="badge badge-nr">NR = No Record</span>
  </div>
</div>

<div class="summary-box">
  <div class="summary-item">
    <div class="label">Total Children</div>
    <div class="value">{$totals['total_children']}</div>
  </div>
  <div class="summary-item">
    <div class="label">Present</div>
    <div class="value green">{$totals['total_present']}</div>
  </div>
  <div class="summary-item">
    <div class="label">Absent</div>
    <div class="value red">{$totals['total_absent']}</div>
  </div>
  <div class="summary-item">
    <div class="label">Late</div>
    <div class="value amber">{$totals['total_late']}</div>
  </div>
  <div class="summary-item">
    <div class="label">Excused</div>
    <div class="value" style="color:#0c5460;">{$totals['total_excused']}</div>
  </div>
  <div class="summary-item">
    <div class="label">Attendance Rate</div>
    <div class="value" style="color:" . ($totals['attendance_percentage'] >= 80 ? '#155724' : '#856404') . ">{$totals['attendance_percentage']}%</div>
  </div>
</div>

<table>
  <thead>
    <tr>
      <th style="width:25px;">#</th>
      <th style="text-align:left;min-width:100px;">Full Name</th>
      <th style="min-width:60px;">Admission</th>
      <th style="min-width:60px;">Class</th>
      <th style="min-width:80px;">Guardian</th>
      <th style="min-width:70px;">Guardian Phone</th>
      $headers
      <th style="min-width:30px;">P</th>
      <th style="min-width:30px;">A</th>
      <th style="min-width:30px;">L</th>
      <th style="min-width:30px;">E</th>
      <th style="min-width:40px;">%</th>
    </tr>
  </thead>
  <tbody>
    $rows
  </tbody>
</table>

<div class="footer">
  <span>Academy AMS — Confidential Report</span>
  <span>Printed: $ts</span>
</div>
</body>
</html>
HTML;

    echo $html;
    exit;
}

/* ════════════════════════════════════════════════════
   EXPORT HANDLERS - Add this after your existing exports
════════════════════════════════════════════════════ */
$p=period();

/* ── Attendance Report Export ── */
if (isset($_GET['export_attendance_report'])) {
    $period = $_GET['period'] ?? date('Y-m');
    $format = $_GET['format'] ?? 'pdf';
    generateAttendanceReport($pdo, $period, $format);
}

/* ── Existing CSV exports (keep your existing code) ── */
$export_type=$_GET['export']??'';

// ... (your existing export handlers here) ...

/* ════════════════════════════════════════════════════
   JERSEY CHECK AJAX
════════════════════════════════════════════════════ */
if(isset($_GET['check_jersey'])){
    $jnum=(int)$_GET['check_jersey'];
    $cid=(int)($_GET['current_id']??0);
    $q=$pdo->prepare("SELECT id FROM athlete_uniforms WHERE jersey_number=?".($cid?" AND id!=?":''));
    $cid?$q->execute([$jnum,$cid]):$q->execute([$jnum]);
    header('Content-Type: application/json');
    echo json_encode(['exists'=>(bool)$q->fetch()]);
    exit;
}

/* ════════════════════════════════════════════════════
   POST HANDLERS (keep your existing code)
════════════════════════════════════════════════════ */
// ... (your existing POST handlers) ...

/* ════════════════════════════════════════════════════
   PAGE DATA
════════════════════════════════════════════════════ */
$z=zones($pdo);$m=members($pdo);$am=active_members($pdo);$s=sessions($pdo);$st=staff($pdo);$v=view();$msg=$_GET['msg']??'';
$edit_member=null;$edit_staff=null;$edit_session=null;$edit_uniform=null;$edit_bill=null;
if(isset($_GET['edit_member'])){$q=$pdo->prepare("SELECT * FROM members WHERE id=?");$q->execute([$_GET['edit_member']]);$edit_member=$q->fetch();}
if(isset($_GET['edit_staff'])){$q=$pdo->prepare("SELECT * FROM staff WHERE id=?");$q->execute([$_GET['edit_staff']]);$edit_staff=$q->fetch();}
if(isset($_GET['edit_session'])){$q=$pdo->prepare("SELECT * FROM sessions WHERE id=?");$q->execute([$_GET['edit_session']]);$edit_session=$q->fetch();}
if(isset($_GET['edit_uniform'])){$q=$pdo->prepare("SELECT * FROM athlete_uniforms WHERE id=?");$q->execute([$_GET['edit_uniform']]);$edit_uniform=$q->fetch();}
if(isset($_GET['edit_bill'])){$q=$pdo->prepare("SELECT b.*,m.full_name FROM monthly_bills b JOIN members m ON m.id=b.member_id WHERE b.id=?");$q->execute([$_GET['edit_bill']]);$edit_bill=$q->fetch();}

$safe_p=$pdo->quote($p);
$stats=$pdo->query("
SELECT
(SELECT COUNT(*) FROM members WHERE is_active=TRUE) athletes,
(SELECT COUNT(*) FROM staff WHERE is_active=TRUE) staff_count,
(SELECT COALESCE(SUM(paid_amount),0) FROM monthly_bills WHERE period=$safe_p) revenue,
(SELECT COALESCE(SUM(GREATEST(expected_amount-paid_amount,0)),0) FROM monthly_bills WHERE period=$safe_p) outstanding,
(SELECT COALESCE(SUM(amount),0) FROM expenses WHERE TO_CHAR(expense_date,'YYYY-MM')=$safe_p) expenses,
(SELECT COALESCE(SUM(amount_paid),0) FROM coach_payroll WHERE period=$safe_p) payroll
")->fetch();

$nav_items=[
    'dashboard'=>['icon'=>'▲','label'=>'Dashboard'],
    'members'  =>['icon'=>'◈','label'=>'Athletes'],
    'attendance'=>['icon'=>'◉','label'=>'Attendance'],
    'payments' =>['icon'=>'◆','label'=>'Billing'],
    'staff'    =>['icon'=>'◍','label'=>'Staff'],
    'payroll'  =>['icon'=>'▣','label'=>'Payroll'],
    'expenses' =>['icon'=>'◐','label'=>'Expenses'],
    'uniforms' =>['icon'=>'▤','label'=>'Uniforms'],
    'reports'  =>['icon'=>'◧','label'=>'Reports'],
];
$prev=date('Y-m',strtotime($p.'-01 -1 month'));
$next=date('Y-m',strtotime($p.'-01 +1 month'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Academy AMS</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Clash+Display:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@300;400;500;600;700&family=JetBrains+Mono:wght@300;400;500&display=swap" rel="stylesheet">
<style>
/* ... (your existing CSS) ... */
:root{
  --bg:#040810;--bg2:#060c18;--surface:#0a1628;--surface2:#0f1e38;--surface3:#142440;
  --border:#1c2e4a;--border2:#243a5e;--border3:#2e4870;
  --lime:#c6f135;--lime-dim:rgba(198,241,53,0.12);--lime-glow:rgba(198,241,53,0.25);
  --teal:#00d9c0;--teal-dim:rgba(0,217,192,0.1);
  --blue:#4d9fff;--blue-dim:rgba(77,159,255,0.1);
  --amber:#ffb740;--amber-dim:rgba(255,183,64,0.1);
  --red:#ff4f6b;--red-dim:rgba(255,79,107,0.1);
  --purple:#a78bfa;
  --text:#e8f0fe;--text2:#9bb5d8;--muted:#4d6a8a;--muted2:#3a5070;
  --radius:16px;--radius-sm:10px;--radius-xs:6px;--sidebar-w:256px;
  --font-display:'Clash Display',sans-serif;--font-body:'Plus Jakarta Sans',sans-serif;--font-mono:'JetBrains Mono',monospace;
  --transition:0.2s cubic-bezier(0.4,0,0.2,1);
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
html{scroll-behavior:smooth;}
body{background:var(--bg);color:var(--text);font-family:var(--font-body);font-size:14px;min-height:100vh;display:flex;overflow-x:hidden;}
body::before{content:'';position:fixed;inset:0;background-image:url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='0.02'/%3E%3C/svg%3E");pointer-events:none;z-index:0;}

/* SIDEBAR */
.sidebar{position:fixed;top:0;left:0;width:var(--sidebar-w);height:100vh;background:var(--surface);border-right:1px solid var(--border);display:flex;flex-direction:column;overflow-y:auto;z-index:100;box-shadow:4px 0 40px rgba(0,0,0,0.4);}
.sidebar-top{padding:28px 20px 24px;border-bottom:1px solid var(--border);}
.logo{display:flex;align-items:center;gap:12px;}
.logo-mark{width:42px;height:42px;background:var(--lime);border-radius:12px;display:flex;align-items:center;justify-content:center;flex-shrink:0;position:relative;overflow:hidden;}
.logo-mark::after{content:'';position:absolute;inset:0;background:linear-gradient(135deg,rgba(255,255,255,0.3),transparent);}
.logo-mark span{font-family:var(--font-display);font-size:20px;font-weight:700;color:#000;position:relative;z-index:1;}
.logo-text{font-family:var(--font-display);font-size:17px;font-weight:700;color:var(--text);letter-spacing:-0.01em;line-height:1.15;}
.logo-sub{font-size:10px;color:var(--muted);font-family:var(--font-mono);letter-spacing:0.15em;text-transform:uppercase;margin-top:1px;}
.nav-body{padding:16px 12px;flex:1;}
.nav-label{font-size:10px;color:var(--muted);letter-spacing:0.2em;text-transform:uppercase;font-family:var(--font-mono);padding:0 8px;margin:8px 0 6px;}
.nav a{display:flex;align-items:center;gap:10px;color:var(--text2);text-decoration:none;padding:10px 12px;border-radius:var(--radius-sm);margin-bottom:1px;font-size:13.5px;font-weight:500;transition:all var(--transition);border:1px solid transparent;position:relative;}
.nav a:hover{color:var(--text);background:var(--surface2);border-color:var(--border);}
.nav a.active{color:var(--lime);background:var(--lime-dim);border-color:rgba(198,241,53,0.2);font-weight:600;}
.nav a.active::before{content:'';position:absolute;left:-1px;top:20%;bottom:20%;width:3px;background:var(--lime);border-radius:0 2px 2px 0;}
.nav-icon{font-size:13px;width:16px;text-align:center;opacity:0.8;}
.sidebar-footer{padding:16px;border-top:1px solid var(--border);}
.period-widget{background:var(--surface2);border:1px solid var(--border2);border-radius:var(--radius-sm);padding:12px 14px;position:relative;overflow:hidden;}
.period-widget::before{content:'';position:absolute;top:-20px;right:-20px;width:80px;height:80px;background:radial-gradient(circle,var(--lime-glow),transparent 70%);}
.period-widget-label{font-size:10px;color:var(--muted);font-family:var(--font-mono);letter-spacing:0.15em;text-transform:uppercase;margin-bottom:4px;}
.period-widget-val{font-family:var(--font-display);font-size:18px;font-weight:700;color:var(--lime);letter-spacing:-0.01em;}

/* MAIN */
.main{margin-left:var(--sidebar-w);flex:1;padding:36px 40px;max-width:calc(100vw - var(--sidebar-w));position:relative;z-index:1;}

/* PAGE HEADER */
.page-header{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:30px;flex-wrap:wrap;gap:14px;}
.page-title{font-family:var(--font-display);font-size:32px;font-weight:700;letter-spacing:-0.03em;line-height:1;color:var(--text);}
.page-title em{font-style:normal;color:var(--lime);}
.page-sub{font-size:12px;color:var(--muted);font-family:var(--font-mono);margin-top:6px;letter-spacing:0.05em;}

/* PERIOD NAV */
.period-nav{display:flex;align-items:center;gap:6px;background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-sm);padding:5px;}
.period-nav a{display:inline-flex;align-items:center;justify-content:center;color:var(--text2);text-decoration:none;background:transparent;border:1px solid transparent;border-radius:var(--radius-xs);padding:6px 12px;font-size:12px;font-family:var(--font-mono);transition:all var(--transition);}
.period-nav a:hover{border-color:var(--border2);color:var(--text);background:var(--surface2);}
.period-nav .cur{font-family:var(--font-mono);color:var(--lime);font-size:13px;font-weight:500;padding:6px 14px;background:var(--lime-dim);border:1px solid rgba(198,241,53,0.2);border-radius:var(--radius-xs);cursor:default;letter-spacing:0.04em;}

/* FLASH */
.flash{display:flex;align-items:center;gap:12px;background:linear-gradient(90deg,rgba(198,241,53,0.08),rgba(0,217,192,0.05));border:1px solid rgba(198,241,53,0.2);border-left:3px solid var(--lime);color:var(--lime);padding:13px 18px;border-radius:var(--radius-sm);margin-bottom:24px;font-size:13px;font-weight:500;animation:slideDown 0.3s ease;}
.flash-icon{width:22px;height:22px;background:var(--lime);border-radius:50%;display:flex;align-items:center;justify-content:center;color:#000;font-size:12px;font-weight:900;flex-shrink:0;}
@keyframes slideDown{from{opacity:0;transform:translateY(-8px);}to{opacity:1;transform:translateY(0);}}

/* CARDS */
.card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:26px;margin-bottom:20px;position:relative;overflow:hidden;transition:border-color var(--transition);}
.card:hover{border-color:var(--border2);}
.card-corner{position:absolute;top:0;right:0;width:100px;height:100px;background:radial-gradient(circle at top right,rgba(198,241,53,0.04),transparent 70%);pointer-events:none;}
.card-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;gap:10px;flex-wrap:wrap;}
.card-title{font-family:var(--font-display);font-size:15px;font-weight:600;display:flex;align-items:center;gap:10px;letter-spacing:-0.01em;}
.card-title-bar{width:4px;height:18px;background:linear-gradient(180deg,var(--lime),var(--teal));border-radius:2px;flex-shrink:0;}

/* STAT GRID */
.stat-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:20px;}
.stat-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:22px 24px;position:relative;overflow:hidden;transition:all var(--transition);cursor:default;}
.stat-card:hover{border-color:var(--border3);transform:translateY(-1px);box-shadow:0 8px 30px rgba(0,0,0,0.3);}
.stat-card::after{content:'';position:absolute;bottom:-20px;right:-20px;width:90px;height:90px;border-radius:50%;background:var(--stat-glow,rgba(198,241,53,0.05));}
.stat-card[data-color="lime"]{--stat-glow:rgba(198,241,53,0.06);}
.stat-card[data-color="teal"]{--stat-glow:rgba(0,217,192,0.06);}
.stat-card[data-color="amber"]{--stat-glow:rgba(255,183,64,0.06);}
.stat-card[data-color="red"]{--stat-glow:rgba(255,79,107,0.06);}
.stat-card[data-color="blue"]{--stat-glow:rgba(77,159,255,0.06);}
.stat-card[data-color="purple"]{--stat-glow:rgba(167,139,250,0.06);}
.stat-icon{width:36px;height:36px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:16px;margin-bottom:14px;}
.stat-card[data-color="lime"]  .stat-icon{background:var(--lime-dim);}
.stat-card[data-color="teal"]  .stat-icon{background:var(--teal-dim);}
.stat-card[data-color="amber"] .stat-icon{background:var(--amber-dim);}
.stat-card[data-color="red"]   .stat-icon{background:var(--red-dim);}
.stat-card[data-color="blue"]  .stat-icon{background:var(--blue-dim);}
.stat-card[data-color="purple"].stat-icon{background:rgba(167,139,250,0.1);}
.stat-label{font-size:11px;text-transform:uppercase;letter-spacing:0.12em;color:var(--muted);font-family:var(--font-mono);margin-bottom:6px;}
.stat-value{font-family:var(--font-display);font-size:28px;font-weight:700;line-height:1;letter-spacing:-0.03em;}
.stat-card[data-color="lime"]  .stat-value{color:var(--lime);}
.stat-card[data-color="teal"]  .stat-value{color:var(--teal);}
.stat-card[data-color="amber"] .stat-value{color:var(--amber);}
.stat-card[data-color="red"]   .stat-value{color:var(--red);}
.stat-card[data-color="blue"]  .stat-value{color:var(--blue);}
.stat-card[data-color="purple"].stat-value{color:var(--purple);}

/* TABLES */
.table-wrap{overflow-x:auto;border-radius:var(--radius-sm);}
table{width:100%;border-collapse:collapse;}
thead th{color:var(--muted);font-size:10.5px;font-family:var(--font-mono);text-transform:uppercase;letter-spacing:0.12em;padding:11px 14px;border-bottom:1px solid var(--border);text-align:left;white-space:nowrap;background:var(--surface2);}
tbody td{padding:13px 14px;border-bottom:1px solid rgba(28,46,74,0.6);font-size:13.5px;transition:background var(--transition);vertical-align:middle;}
tbody tr:last-child td{border-bottom:none;}
tbody tr:hover td{background:rgba(255,255,255,0.018);}
.no-data{text-align:center;color:var(--muted);padding:50px 0;font-size:13px;font-family:var(--font-mono);letter-spacing:0.05em;}

/* BADGES */
.badge{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:999px;font-size:11px;font-weight:600;font-family:var(--font-mono);white-space:nowrap;letter-spacing:0.04em;}
.b-zone   {background:var(--blue-dim);color:#82b4ff;border:1px solid rgba(77,159,255,0.2);}
.b-paid   {background:var(--lime-dim);color:var(--lime);border:1px solid rgba(198,241,53,0.2);}
.b-partial{background:var(--amber-dim);color:var(--amber);border:1px solid rgba(255,183,64,0.2);}
.b-unpaid {background:var(--red-dim);color:var(--red);border:1px solid rgba(255,79,107,0.2);}
.b-nobill {background:rgba(77,106,138,0.1);color:var(--muted);border:1px solid rgba(77,106,138,0.2);}
.b-active {background:var(--lime-dim);color:var(--lime);}
.b-inactive{background:var(--red-dim);color:var(--red);}
.b-present{background:var(--lime-dim);color:var(--lime);}
.b-absent {background:var(--red-dim);color:var(--red);}
.b-late   {background:var(--amber-dim);color:var(--amber);}

/* FORMS */
.form-grid  {display:grid;grid-template-columns:repeat(3,1fr);gap:14px;}
.form-grid-2{display:grid;grid-template-columns:repeat(2,1fr);gap:14px;}
.form-grid-4{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;}
.form-group label{display:block;font-size:10.5px;text-transform:uppercase;letter-spacing:0.12em;color:var(--muted);font-family:var(--font-mono);margin-bottom:7px;}
.form-group input,.form-group select,.form-group textarea{width:100%;padding:10px 14px;background:var(--surface2);border:1px solid var(--border2);border-radius:var(--radius-sm);color:var(--text);font-family:var(--font-body);font-size:13.5px;outline:none;transition:border-color var(--transition),box-shadow var(--transition),background var(--transition);-webkit-appearance:none;}
.form-group input:focus,.form-group select:focus,.form-group textarea:focus{border-color:var(--lime);background:var(--surface3);box-shadow:0 0 0 3px var(--lime-glow);}
.form-group input::placeholder{color:var(--muted2);}
.form-group select{cursor:pointer;}
.form-group select option{background:var(--surface2);}
.form-actions{display:flex;gap:10px;align-items:center;margin-top:20px;padding-top:18px;border-top:1px solid var(--border);flex-wrap:wrap;}

/* BUTTONS */
.btn{display:inline-flex;align-items:center;gap:7px;padding:10px 20px;border-radius:var(--radius-sm);font-family:var(--font-display);font-size:13px;font-weight:600;cursor:pointer;border:none;text-decoration:none;transition:all var(--transition);white-space:nowrap;letter-spacing:0.01em;position:relative;overflow:hidden;}
.btn::after{content:'';position:absolute;inset:0;background:linear-gradient(135deg,rgba(255,255,255,0.08),transparent);opacity:0;transition:opacity var(--transition);}
.btn:hover::after{opacity:1;}
.btn-primary{background:var(--lime);color:#050f0a;box-shadow:0 4px 16px rgba(198,241,53,0.2);}
.btn-primary:hover{background:#d4f540;box-shadow:0 6px 24px rgba(198,241,53,0.35);transform:translateY(-1px);}
.btn-ghost{background:var(--surface2);color:var(--text2);border:1px solid var(--border2);}
.btn-ghost:hover{border-color:var(--border3);color:var(--text);background:var(--surface3);}
.btn-danger{background:var(--red-dim);color:var(--red);border:1px solid rgba(255,79,107,0.2);}
.btn-danger:hover{background:rgba(255,79,107,0.2);}
.btn-warning{background:var(--amber-dim);color:var(--amber);border:1px solid rgba(255,183,64,0.2);}
.btn-warning:hover{background:rgba(255,183,64,0.2);}
.btn-teal{background:var(--teal-dim);color:var(--teal);border:1px solid rgba(0,217,192,0.2);}
.btn-teal:hover{background:rgba(0,217,192,0.2);}
.btn-sm{padding:6px 13px;font-size:12px;}
.btn-xs{padding:4px 10px;font-size:11px;}
.actions-cell{display:flex;gap:6px;flex-wrap:wrap;align-items:center;}

/* REPORT DOWNLOAD PANEL */
.report-panel{background:var(--surface2);border:1px solid var(--border2);border-radius:var(--radius-sm);padding:16px 18px;margin-bottom:6px;}
.report-panel-title{font-size:11px;text-transform:uppercase;letter-spacing:.12em;color:var(--muted);font-family:var(--font-mono);margin-bottom:10px;}
.report-btns{display:flex;gap:8px;flex-wrap:wrap;}

/* TOOLBAR / SEARCH */
.toolbar{display:flex;gap:10px;margin-bottom:16px;flex-wrap:wrap;align-items:center;}
.search-box{position:relative;flex:1;min-width:200px;}
.search-box-icon{position:absolute;left:13px;top:50%;transform:translateY(-50%);color:var(--muted);font-size:14px;pointer-events:none;}
.search-box input{width:100%;padding:10px 14px 10px 40px;background:var(--surface2);border:1px solid var(--border2);border-radius:var(--radius-sm);color:var(--text);font-family:var(--font-body);font-size:13.5px;outline:none;transition:all var(--transition);}
.search-box input:focus{border-color:var(--lime);background:var(--surface3);box-shadow:0 0 0 3px var(--lime-glow);}
.search-box input::placeholder{color:var(--muted2);}
.toolbar select{padding:10px 14px;background:var(--surface2);border:1px solid var(--border2);border-radius:var(--radius-sm);color:var(--text);font-size:13px;outline:none;cursor:pointer;transition:all var(--transition);font-family:var(--font-body);-webkit-appearance:none;}
.toolbar select:focus{border-color:var(--lime);box-shadow:0 0 0 3px var(--lime-glow);}
.toolbar select option{background:var(--surface2);}
.result-count{font-size:11px;color:var(--muted);font-family:var(--font-mono);margin-bottom:12px;letter-spacing:0.04em;}

/* AUT COMPLETE */
.autocomplete-wrap{position:relative;}
.autocomplete-dropdown{position:absolute;top:calc(100% + 6px);left:0;right:0;background:var(--surface2);border:1px solid var(--border2);border-radius:var(--radius-sm);box-shadow:0 16px 48px rgba(0,0,0,0.5);z-index:999;max-height:320px;overflow-y:auto;display:none;}
.autocomplete-dropdown.open{display:block;}
.ac-item{display:flex;align-items:center;gap:12px;padding:11px 14px;cursor:pointer;transition:background var(--transition);border-bottom:1px solid var(--border);}
.ac-item:last-child{border-bottom:none;}
.ac-item:hover,.ac-item.focused{background:var(--surface3);}
.ac-avatar{width:32px;height:32px;border-radius:10px;background:var(--lime-dim);border:1px solid rgba(198,241,53,0.15);display:flex;align-items:center;justify-content:center;font-family:var(--font-display);font-size:13px;font-weight:700;color:var(--lime);flex-shrink:0;text-transform:uppercase;}
.ac-info{flex:1;min-width:0;}
.ac-name{font-size:13.5px;font-weight:600;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.ac-meta{font-size:11px;color:var(--muted);font-family:var(--font-mono);margin-top:1px;}
.ac-badge{font-size:11px;color:var(--teal);font-family:var(--font-mono);white-space:nowrap;}
.ac-empty{padding:20px;text-align:center;color:var(--muted);font-size:12px;font-family:var(--font-mono);}
.selected-athlete-info{display:none;align-items:center;gap:14px;background:var(--surface2);border:1px solid rgba(198,241,53,0.2);border-radius:var(--radius-sm);padding:12px 16px;margin-top:10px;}
.selected-athlete-info.visible{display:flex;}
.sa-avatar{width:40px;height:40px;border-radius:12px;background:var(--lime-dim);border:1px solid rgba(198,241,53,0.2);display:flex;align-items:center;justify-content:center;font-family:var(--font-display);font-size:16px;font-weight:700;color:var(--lime);flex-shrink:0;text-transform:uppercase;}
.sa-name{font-size:14px;font-weight:600;color:var(--text);}
.sa-detail{font-size:11px;color:var(--muted);font-family:var(--font-mono);margin-top:2px;}

/* MODAL */
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,0.7);z-index:500;display:flex;align-items:center;justify-content:center;backdrop-filter:blur(4px);}
.modal-overlay.hidden{display:none;}
.modal-box{background:var(--surface);border:1px solid var(--border2);border-radius:var(--radius);padding:28px;width:90%;max-width:520px;position:relative;box-shadow:0 24px 80px rgba(0,0,0,0.6);}
.modal-title{font-family:var(--font-display);font-size:18px;font-weight:700;margin-bottom:20px;color:var(--lime);}
.modal-close{position:absolute;top:16px;right:16px;background:var(--surface2);border:1px solid var(--border2);color:var(--text2);width:28px;height:28px;border-radius:50%;cursor:pointer;font-size:16px;display:flex;align-items:center;justify-content:center;}
.modal-close:hover{color:var(--red);border-color:var(--red);}

/* SUMMARY ROW */
.summary-row td{font-weight:700;background:var(--surface2)!important;color:var(--lime);font-family:var(--font-mono);border-top:2px solid var(--border2);}

/* SCROLLBAR */
::-webkit-scrollbar{width:6px;height:6px;}
::-webkit-scrollbar-track{background:var(--bg);}
::-webkit-scrollbar-thumb{background:var(--border2);border-radius:3px;}
::-webkit-scrollbar-thumb:hover{background:var(--border3);}

@media(max-width:960px){
  :root{--sidebar-w:220px;}
  .main{padding:20px;}
  .stat-grid,.form-grid,.form-grid-4{grid-template-columns:repeat(2,1fr);}
}
@media(max-width:680px){
  :root{--sidebar-w:0px;}
  .sidebar{transform:translateX(-100%);}
  .stat-grid,.form-grid,.form-grid-2,.form-grid-4{grid-template-columns:1fr;}
}
</style>
</head>
<body>

<!-- ── SIDEBAR ── -->
<aside class="sidebar">
  <div class="sidebar-top">
    <div class="logo">
      <div class="logo-mark"><span>A</span></div>
      <div>
        <div class="logo-text">Academy AMS</div>
        <div class="logo-sub">Management</div>
      </div>
    </div>
  </div>
  <div class="nav-body">
    <div class="nav-label">Navigation</div>
    <nav class="nav">
      <?php foreach($nav_items as $key=>$item): ?>
      <a class="<?= $v===$key?'active':'' ?>" href="?view=<?= $key ?>&period=<?= h($p) ?>">
        <span class="nav-icon"><?= $item['icon'] ?></span>
        <?= $item['label'] ?>
      </a>
      <?php endforeach; ?>
    </nav>
  </div>
  <div class="sidebar-footer">
    <div class="period-widget">
      <div class="period-widget-label">Active Period</div>
      <div class="period-widget-val"><?= h($p) ?></div>
    </div>
  </div>
</aside>

<!-- ── MAIN ── -->
<main class="main">

<?php if($msg): ?>
<div class="flash"><div class="flash-icon">✓</div><?= h($msg) ?></div>
<?php endif; ?>

<?php /* ════════════════════════════════════
   DASHBOARD
════════════════════════════════════ */
if($v==='dashboard'): ?>
<div class="page-header">
  <div>
    <div class="page-title">Good day, <em>Coach</em></div>
    <div class="page-sub">Period: <?= h($p) ?> · Academy Management System</div>
  </div>
  <div class="period-nav">
    <a href="?view=dashboard&period=<?= $prev ?>">← Prev</a>
    <span class="cur"><?= h($p) ?></span>
    <a href="?view=dashboard&period=<?= $next ?>">Next →</a>
  </div>
</div>

<div class="stat-grid">
  <div class="stat-card" data-color="lime"><div class="stat-icon">⚽</div><div class="stat-label">Active Athletes</div><div class="stat-value"><?= $stats['athletes'] ?></div></div>
  <div class="stat-card" data-color="blue"><div class="stat-icon">👤</div><div class="stat-label">Active Staff</div><div class="stat-value"><?= $stats['staff_count'] ?></div></div>
  <div class="stat-card" data-color="teal"><div class="stat-icon">💰</div><div class="stat-label">Revenue <?= h($p) ?></div><div class="stat-value" style="font-size:18px"><?= money($stats['revenue']) ?></div></div>
  <div class="stat-card" data-color="amber"><div class="stat-icon">⏳</div><div class="stat-label">Outstanding</div><div class="stat-value" style="font-size:18px"><?= money($stats['outstanding']) ?></div></div>
  <div class="stat-card" data-color="red"><div class="stat-icon">📤</div><div class="stat-label">Expenses</div><div class="stat-value" style="font-size:18px"><?= money($stats['expenses']) ?></div></div>
  <div class="stat-card" data-color="purple"><div class="stat-icon">💳</div><div class="stat-label">Payroll Paid</div><div class="stat-value" style="font-size:18px"><?= money($stats['payroll']) ?></div></div>
</div>

<div class="card">
  <div class="card-corner"></div>
  <div class="card-header">
    <div class="card-title"><span class="card-title-bar"></span>Zone Summary — <?= h($p) ?></div>
  </div>
  <div class="table-wrap">
  <table>
    <thead><tr><th>Zone</th><th>Athletes</th><th>Staff</th><th>Revenue</th><th>Expenses</th></tr></thead>
    <tbody>
    <?php
    $safe_p2=$pdo->quote($p);
    $zrows=$pdo->query("
    SELECT z.name,COUNT(DISTINCT m.id) athletes,COUNT(DISTINCT st.id) staff_cnt,
    COALESCE((SELECT SUM(b2.paid_amount) FROM monthly_bills b2 JOIN members m2 ON m2.id=b2.member_id WHERE m2.zone_id=z.id AND b2.period=$safe_p2),0) revenue,
    COALESCE((SELECT SUM(e2.amount) FROM expenses e2 WHERE e2.zone_id=z.id AND TO_CHAR(e2.expense_date,'YYYY-MM')=$safe_p2),0) expenses
    FROM academy_zones z
    LEFT JOIN members m ON m.zone_id=z.id AND m.is_active=TRUE
    LEFT JOIN staff st ON st.zone_id=z.id AND st.is_active=TRUE
    GROUP BY z.id,z.name ORDER BY z.id")->fetchAll();
    foreach($zrows as $r): ?>
    <tr>
      <td><strong style="font-family:var(--font-display)"><?= h($r['name']) ?></strong></td>
      <td><span style="font-family:var(--font-mono);color:var(--blue)"><?= $r['athletes'] ?></span></td>
      <td><span style="font-family:var(--font-mono);color:var(--text2)"><?= $r['staff_cnt'] ?></span></td>
      <td><span style="font-family:var(--font-mono);color:var(--lime)"><?= money($r['revenue']) ?></span></td>
      <td><span style="font-family:var(--font-mono);color:var(--red)"><?= money($r['expenses']) ?></span></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>

<?php
$unpaid=$pdo->query("SELECT COUNT(*) FROM monthly_bills WHERE period=$safe_p AND paid_amount=0 AND expected_amount>0")->fetchColumn();
$partial=$pdo->query("SELECT COUNT(*) FROM monthly_bills WHERE period=$safe_p AND paid_amount>0 AND paid_amount<expected_amount")->fetchColumn();
?>
<div class="card">
  <div class="card-corner"></div>
  <div class="card-header">
    <div class="card-title"><span class="card-title-bar"></span>Billing Snapshot — <?= h($p) ?></div>
    <a href="?view=payments&period=<?= h($p) ?>" class="btn btn-ghost btn-sm">View Billing →</a>
  </div>
  <div style="display:flex;gap:14px;flex-wrap:wrap">
    <div style="background:var(--red-dim);border:1px solid rgba(255,79,107,0.2);border-radius:var(--radius-sm);padding:16px 22px;flex:1;min-width:140px"><div class="stat-label">Unpaid</div><div style="font-family:var(--font-display);font-size:28px;font-weight:700;color:var(--red)"><?= $unpaid ?></div></div>
    <div style="background:var(--amber-dim);border:1px solid rgba(255,183,64,0.2);border-radius:var(--radius-sm);padding:16px 22px;flex:1;min-width:140px"><div class="stat-label">Partial</div><div style="font-family:var(--font-display);font-size:28px;font-weight:700;color:var(--amber)"><?= $partial ?></div></div>
    <div style="background:var(--lime-dim);border:1px solid rgba(198,241,53,0.2);border-radius:var(--radius-sm);padding:16px 22px;flex:1;min-width:140px"><div class="stat-label">Net Income</div><div style="font-family:var(--font-display);font-size:20px;font-weight:700;color:var(--lime)"><?= money((float)$stats['revenue']-(float)$stats['expenses']-(float)$stats['payroll']) ?></div></div>
  </div>
</div>
<?php endif; ?>

<?php /* ════════════════════════════════════
   ATHLETES
════════════════════════════════════ */
if($v==='members'): ?>
<div class="page-header">
  <div>
    <div class="page-title"><?= $edit_member?'Edit <em>Athlete</em>':'Athletes <em>Registry</em>' ?></div>
    <div class="page-sub"><?= count($m) ?> total · <?= count($am) ?> active</div>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <a class="btn btn-ghost btn-sm" href="?view=members&period=<?= h($p) ?>&export=athletes_csv">📊 CSV</a>
    <a class="btn btn-teal btn-sm" href="?view=members&period=<?= h($p) ?>&export=athletes_pdf" target="_blank">📄 PDF Report</a>
  </div>
</div>

<div class="card">
  <div class="card-corner"></div>
  <div class="card-header"><div class="card-title"><span class="card-title-bar"></span><?= $edit_member?'Edit Athlete':'Register New Athlete' ?></div></div>
  <form method="POST">
    <input type="hidden" name="action" value="save_member">
    <input type="hidden" name="id" value="<?= h($edit_member['id']??'') ?>">
    <div class="form-grid">
      <div class="form-group"><label>Full Name *</label><input name="full_name" required value="<?= h($edit_member['full_name']??'') ?>" placeholder="e.g. Jean Paul Mugisha"></div>
      <div class="form-group"><label>Phone</label><input name="phone" value="<?= h($edit_member['phone']??'') ?>" placeholder="+250 7xx xxx xxx"></div>
      <div class="form-group"><label>Zone</label><select name="zone_id"><?php foreach($z as $zone): ?><option value="<?= $zone['id'] ?>" <?= (($edit_member['zone_id']??'')==$zone['id'])?'selected':'' ?>><?= h($zone['name']) ?></option><?php endforeach; ?></select></div>
      <div class="form-group"><label>Gender</label><select name="gender"><option value="">— Select —</option><option <?= (($edit_member['gender']??'')==='Male')?'selected':'' ?>>Male</option><option <?= (($edit_member['gender']??'')==='Female')?'selected':'' ?>>Female</option></select></div>
      <div class="form-group"><label>Date of Birth</label><input type="date" name="date_of_birth" value="<?= h($edit_member['date_of_birth']??'') ?>"></div>
      <div class="form-group"><label>Position</label><input name="position" value="<?= h($edit_member['position']??'') ?>" placeholder="e.g. Forward, Goalkeeper"></div>
      <div class="form-group"><label>Guardian Name</label><input name="guardian_name" value="<?= h($edit_member['guardian_name']??'') ?>" placeholder="Parent / Guardian"></div>
      <div class="form-group"><label>Guardian Phone</label><input name="guardian_phone" value="<?= h($edit_member['guardian_phone']??'') ?>"></div>
      <div class="form-group"><label>School</label><input name="school_name" value="<?= h($edit_member['school_name']??'') ?>" placeholder="School name"></div>
      <div class="form-group"><label>Monthly Fee (RWF)</label><input type="number" name="monthly_fee" value="<?= h($edit_member['monthly_fee']??0) ?>" placeholder="0"></div>
      <div class="form-group"><label>Due Day</label><input type="number" name="due_day" min="1" max="31" value="<?= h($edit_member['due_day']??5) ?>"></div>
      <div class="form-group"><label>Admission Number</label><input name="admission_number" value="<?= h($edit_member['admission_number']??'') ?>" placeholder="e.g. A-2024-001"></div>
      <div class="form-group"><label>Class</label><input name="class_name" value="<?= h($edit_member['class_name']??'') ?>" placeholder="e.g. Grade 5"></div>
      <div class="form-group"><label>Parent Email</label><input name="parent_email" value="<?= h($edit_member['parent_email']??'') ?>" placeholder="parent@email.com"></div>
      <div class="form-group"><label>Notes</label><input name="notes" value="<?= h($edit_member['notes']??'') ?>" placeholder="Optional notes"></div>
    </div>
    <div class="form-actions">
      <button class="btn btn-primary" type="submit">💾 <?= $edit_member?'Update Athlete':'Save Athlete' ?></button>
      <?php if($edit_member): ?><a class="btn btn-ghost" href="?view=members&period=<?= h($p) ?>">✕ Cancel</a><?php endif; ?>
    </div>
  </form>
</div>

<div class="card">
  <div class="card-corner"></div>
  <div class="card-header"><div class="card-title"><span class="card-title-bar"></span>All Athletes</div></div>
  <div class="toolbar">
    <div class="search-box"><span class="search-box-icon">🔍</span><input type="text" id="memberSearch" placeholder="Search name, phone, zone, position…" oninput="filterTable('memberSearch','memberTbl','memberCnt')"></div>
    <select id="mZoneF" onchange="filterTable('memberSearch','memberTbl','memberCnt')"><option value="">All Zones</option><?php foreach($z as $zone): ?><option value="<?= h($zone['name']) ?>"><?= h($zone['name']) ?></option><?php endforeach; ?></select>
    <select id="mStatF" onchange="filterTable('memberSearch','memberTbl','memberCnt')"><option value="">All Status</option><option value="Active">Active</option><option value="Inactive">Inactive</option></select>
  </div>
  <div class="result-count" id="memberCnt"></div>
  <div class="table-wrap">
  <table id="memberTbl">
    <thead><tr><th>Athlete</th><th>Zone</th><th>Phone</th><th>Position</th><th>Admission</th><th>Class</th><th>Monthly Fee</th><th>Status</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach($m as $x): ?>
    <tr>
      <td>
        <div style="display:flex;align-items:center;gap:10px">
          <div style="width:32px;height:32px;border-radius:10px;background:var(--lime-dim);display:flex;align-items:center;justify-content:center;font-weight:700;color:var(--lime);font-size:12px"><?= mb_substr($x['full_name'],0,1) ?></div>
          <strong><?= h($x['full_name']) ?></strong>
        </div>
      </td>
      <td><span class="badge b-zone"><?= h($x['zone_name']) ?></span></td>
      <td style="font-family:var(--font-mono);font-size:12px;color:var(--text2)"><?= h($x['phone']) ?></td>
      <td style="color:var(--text2)"><?= h($x['position']) ?></td>
      <td style="font-family:var(--font-mono);font-size:11px;color:var(--text2)"><?= h($x['admission_number']??'N/A') ?></td>
      <td style="color:var(--text2)"><?= h($x['class_name']??'N/A') ?></td>
      <td style="font-family:var(--font-mono);color:var(--lime)"><?= money($x['monthly_fee']) ?></td>
      <td><span class="badge <?= $x['is_active']?'b-active':'b-inactive' ?>"><?= $x['is_active']?'Active':'Inactive' ?></span></td>
      <td>
        <div class="actions-cell">
          <a class="btn btn-ghost btn-sm" href="?view=members&period=<?= h($p) ?>&edit_member=<?= $x['id'] ?>">Edit</a>
          <form method="POST" style="display:inline" onsubmit="return confirm('Deactivate <?= h(addslashes($x['full_name'])) ?>?')">
            <input type="hidden" name="action" value="delete_member">
            <input type="hidden" name="id" value="<?= $x['id'] ?>">
            <button class="btn btn-danger btn-sm" type="submit">Deactivate</button>
          </form>
        </div>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endif; ?>

<?php /* ════════════════════════════════════
   REPORTS (Includes Attendance Report)
════════════════════════════════════ */
if($v==='reports'): ?>
<div class="page-header">
  <div>
    <div class="page-title">Reports <em>&amp; Analytics</em></div>
    <div class="page-sub">Period: <?= h($p) ?></div>
  </div>
  <div class="period-nav">
    <a href="?view=reports&period=<?= $prev ?>">← Prev</a>
    <span class="cur"><?= h($p) ?></span>
    <a href="?view=reports&period=<?= $next ?>">Next →</a>
  </div>
</div>

<!-- ── ATTENDANCE REPORT DOWNLOAD ── -->
<div class="card">
  <div class="card-corner"></div>
  <div class="card-header">
    <div class="card-title"><span class="card-title-bar"></span>📋 Download Attendance Report</div>
  </div>
  <div style="background:var(--surface2);border:1px solid var(--border2);border-radius:var(--radius-sm);padding:20px;">
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;align-items:end;">
      <div class="form-group">
        <label>Select Month</label>
        <input type="month" id="reportMonth" value="<?= date('Y-m') ?>" 
               style="width:100%;padding:10px 14px;background:var(--surface);border:1px solid var(--border2);border-radius:var(--radius-sm);color:var(--text);font-size:13px;">
      </div>
      <div class="form-group">
        <label>Format</label>
        <select id="reportFormat" style="width:100%;padding:10px 14px;background:var(--surface);border:1px solid var(--border2);border-radius:var(--radius-sm);color:var(--text);font-size:13px;">
          <option value="pdf">📄 PDF (Printable)</option>
          <option value="excel">📊 Excel (CSV)</option>
        </select>
      </div>
      <div style="padding-bottom:2px;">
        <button onclick="downloadAttendanceReport()" class="btn btn-primary" style="width:100%;">
          ⬇ Download Report
        </button>
      </div>
    </div>
    <div style="margin-top:14px;font-size:12px;color:var(--muted);display:flex;gap:20px;flex-wrap:wrap;">
      <span>📌 All active children included</span>
      <span>📆 Daily attendance columns for each session day</span>
      <span>📊 Shows Present/Absent/Late/Excused/No Record</span>
    </div>
  </div>
</div>

<script>
function downloadAttendanceReport() {
  const period = document.getElementById('reportMonth').value;
  const format = document.getElementById('reportFormat').value;
  const currentView = '<?= $v ?>';
  window.location.href = `?view=${currentView}&export_attendance_report=1&period=${period}&format=${format}`;
}
</script>

<?php
// ... rest of your existing reports code here ...
// (Keep your existing reports: Non-Payers, Overdue, Attendance Summary, etc.)
?>

<?php endif; ?>

</main>

<script>
/* ── Universal table filter ── */
function filterTable(searchId, tableId, countId){
  const searchInput = document.getElementById(searchId);
  const table       = document.getElementById(tableId);
  const countEl     = document.getElementById(countId);
  if(!table) return;
  const query = searchInput ? searchInput.value.toLowerCase().trim() : '';
  const card  = table.closest('.card');
  const filterSelects = card ? card.querySelectorAll('select[id$="F"]') : [];
  const rows  = table.querySelectorAll('tbody tr:not(.summary-row):not(.no-results-dyn)');
  let visible = 0;
  rows.forEach(row => {
    const text = row.textContent.toLowerCase();
    let show = true;
    if(query && !text.includes(query)) show = false;
    filterSelects.forEach(sel => {
      const val = sel.value.toLowerCase();
      if(val && !text.includes(val)) show = false;
    });
    row.style.display = show ? '' : 'none';
    if(show) visible++;
  });
  if(countEl){
    const total = rows.length;
    countEl.textContent = (query || [...filterSelects].some(s=>s.value))
      ? `Showing ${visible} of ${total} records`
      : `${total} record${total!==1?'s':''}`;
  }
  let noRes = table.querySelector('.no-results-dyn');
  if(visible === 0 && rows.length > 0){
    if(!noRes){
      const colspan = table.querySelector('thead tr')?.children.length || 6;
      const tr = document.createElement('tr');
      tr.className = 'no-results-dyn';
      tr.innerHTML = `<td colspan="${colspan}" class="no-data">No results match your search</td>`;
      table.querySelector('tbody').appendChild(tr);
    }
  } else { noRes?.remove(); }
}

document.addEventListener('DOMContentLoaded', () => {
  [
    ['memberSearch','memberTbl','memberCnt'],
    // ... (add your other table filters here)
  ].forEach(([s,t,c]) => { if(document.getElementById(t)) filterTable(s,t,c); });
});
</script>
</body>
</html>
