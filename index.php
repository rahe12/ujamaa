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
 id SERIAL PRIMARY KEY,full_name VARCHAR(255) NOT NULL UNIQUE,phone VARCHAR(50),gender VARCHAR(20),
 date_of_birth DATE,zone_id INT REFERENCES academy_zones(id),guardian_name VARCHAR(255),
 guardian_phone VARCHAR(50),position VARCHAR(50),school_name VARCHAR(255),
 monthly_fee NUMERIC(12,2) DEFAULT 0,due_day INT DEFAULT 5,is_active BOOLEAN DEFAULT TRUE,
 notes TEXT,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
ALTER TABLE members ADD COLUMN IF NOT EXISTS zone_id INT REFERENCES academy_zones(id);
ALTER TABLE members ADD COLUMN IF NOT EXISTS guardian_name VARCHAR(255);
ALTER TABLE members ADD COLUMN IF NOT EXISTS guardian_phone VARCHAR(50);
ALTER TABLE members ADD COLUMN IF NOT EXISTS position VARCHAR(50);
ALTER TABLE members ADD COLUMN IF NOT EXISTS school_name VARCHAR(255);
ALTER TABLE members ADD COLUMN IF NOT EXISTS notes TEXT;
ALTER TABLE members ADD COLUMN IF NOT EXISTS admission_number VARCHAR(50);
ALTER TABLE members ADD COLUMN IF NOT EXISTS class_name VARCHAR(100);
UPDATE members SET zone_id=(SELECT id FROM academy_zones WHERE name='Gisenyi' LIMIT 1) WHERE zone_id IS NULL;
CREATE TABLE IF NOT EXISTS sessions(
 id SERIAL PRIMARY KEY,name VARCHAR(255) NOT NULL,session_date DATE NOT NULL DEFAULT CURRENT_DATE,
 zone_id INT REFERENCES academy_zones(id),created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
ALTER TABLE sessions ADD COLUMN IF NOT EXISTS session_date DATE;
ALTER TABLE sessions ADD COLUMN IF NOT EXISTS zone_id INT REFERENCES academy_zones(id);
UPDATE sessions SET zone_id=(SELECT id FROM academy_zones WHERE name='Gisenyi' LIMIT 1) WHERE zone_id IS NULL;
CREATE TABLE IF NOT EXISTS attendance(
 id SERIAL PRIMARY KEY,session_id INT REFERENCES sessions(id) ON DELETE CASCADE,
 member_id INT REFERENCES members(id) ON DELETE CASCADE,status VARCHAR(20) DEFAULT 'present',
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,UNIQUE(session_id,member_id)
);
CREATE TABLE IF NOT EXISTS monthly_bills(
 id SERIAL PRIMARY KEY,member_id INT REFERENCES members(id) ON DELETE CASCADE,
 period CHAR(7) NOT NULL,expected_amount NUMERIC(12,2) DEFAULT 0,paid_amount NUMERIC(12,2) DEFAULT 0,
 due_date DATE NOT NULL,note TEXT,paid_at TIMESTAMP,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(member_id,period)
);
CREATE TABLE IF NOT EXISTS payment_logs(
 id SERIAL PRIMARY KEY,member_id INT REFERENCES members(id) ON DELETE CASCADE,
 amount_paid NUMERIC(12,2) NOT NULL,period CHAR(7),note TEXT,
 payment_date DATE DEFAULT CURRENT_DATE,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
ALTER TABLE payment_logs ADD COLUMN IF NOT EXISTS period CHAR(7);
ALTER TABLE payment_logs ADD COLUMN IF NOT EXISTS payment_date DATE DEFAULT CURRENT_DATE;
CREATE TABLE IF NOT EXISTS staff(
 id SERIAL PRIMARY KEY,full_name VARCHAR(255) NOT NULL,phone VARCHAR(50),role VARCHAR(50) NOT NULL,
 zone_id INT REFERENCES academy_zones(id),monthly_salary NUMERIC(12,2) DEFAULT 0,
 is_active BOOLEAN DEFAULT TRUE,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
ALTER TABLE staff ADD COLUMN IF NOT EXISTS zone_id INT REFERENCES academy_zones(id);
UPDATE staff SET zone_id=(SELECT id FROM academy_zones WHERE name='Gisenyi' LIMIT 1) WHERE zone_id IS NULL;
CREATE TABLE IF NOT EXISTS coach_payroll(
 id SERIAL PRIMARY KEY,staff_id INT REFERENCES staff(id) ON DELETE CASCADE,period CHAR(7) NOT NULL,
 base_salary NUMERIC(12,2) DEFAULT 0,bonus NUMERIC(12,2) DEFAULT 0,deductions NUMERIC(12,2) DEFAULT 0,
 net_salary NUMERIC(12,2) DEFAULT 0,amount_paid NUMERIC(12,2) DEFAULT 0,
 payment_status VARCHAR(30) DEFAULT 'UNPAID',
 paid_at TIMESTAMP,note TEXT,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,UNIQUE(staff_id,period)
);
ALTER TABLE coach_payroll ADD COLUMN IF NOT EXISTS net_salary NUMERIC(12,2) DEFAULT 0;
ALTER TABLE coach_payroll ADD COLUMN IF NOT EXISTS payment_status VARCHAR(30) DEFAULT 'UNPAID';
CREATE TABLE IF NOT EXISTS expenses(
 id SERIAL PRIMARY KEY,expense_date DATE DEFAULT CURRENT_DATE,category VARCHAR(100),description TEXT NOT NULL,
 amount NUMERIC(12,2) NOT NULL,paid_to VARCHAR(255),approved_by VARCHAR(255),
 zone_id INT REFERENCES academy_zones(id),created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
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

/* ────────────────────────────────
   ATTENDANCE MATRIX (date-range) REPORT
   Builds a per-child, per-day attendance grid for a custom
   start/end date range. Includes ALL active children, and
   marks days with no session/attendance as "no_record".
──────────────────────────────── */
function attendance_matrix($pdo,$start,$end){
    // Build the list of calendar days in the range (inclusive)
    $days=[];
    $cur=new DateTime($start);
    $endD=new DateTime($end);
    if($endD<$cur){ $tmp=$cur; $cur=$endD; $endD=$tmp; }
    // Safety cap to avoid runaway ranges
    $maxDays=370;
    $count=0;
    while($cur<=$endD && $count<$maxDays){
        $days[]=$cur->format('Y-m-d');
        $cur->modify('+1 day');
        $count++;
    }

    // All active children, regardless of whether they have attendance records
    $members=$pdo->query("
        SELECT m.*, z.name AS zone_name
        FROM members m
        LEFT JOIN academy_zones z ON z.id=m.zone_id
        WHERE m.is_active=TRUE
        ORDER BY z.id, m.full_name
    ")->fetchAll();

    // All attendance records within the range, keyed by member+date.
    // If a child has more than one session on the same day, the
    // best status wins (present > late > excused > absent).
    $stmt=$pdo->prepare("
        SELECT a.member_id, COALESCE(s.session_date,s.date) AS sdate, a.status
        FROM attendance a
        JOIN sessions s ON s.id=a.session_id
        WHERE COALESCE(s.session_date,s.date) BETWEEN ? AND ?
    ");
    $stmt->execute([$days[0]??$start,$days[count($days)-1]??$end]);
    $rank=['present'=>4,'late'=>3,'excused'=>2,'absent'=>1];
    $map=[];
    foreach($stmt->fetchAll() as $r){
        $key=$r['member_id'].'|'.$r['sdate'];
        $st=strtolower(trim($r['status']));
        if(!isset($map[$key]) || ($rank[$st]??0) > ($rank[$map[$key]]??0)){
            $map[$key]=$st;
        }
    }

    $rows=[];
    $tot_present=0;$tot_absent=0;$tot_late=0;$tot_excused=0;$tot_recorded=0;
    foreach($members as $m){
        $row=['member'=>$m,'days'=>[],'present'=>0,'absent'=>0,'late'=>0,'excused'=>0];
        foreach($days as $d){
            $status=$map[$m['id'].'|'.$d]??'no_record';
            $row['days'][$d]=$status;
            if($status==='present')$row['present']++;
            elseif($status==='absent')$row['absent']++;
            elseif($status==='late')$row['late']++;
            elseif($status==='excused')$row['excused']++;
        }
        $recorded=$row['present']+$row['absent']+$row['late']+$row['excused'];
        $row['recorded']=$recorded;
        $row['rate']=$recorded>0?round((($row['present']+$row['late'])/$recorded)*100,1):0;
        $rows[]=$row;
        $tot_present+=$row['present'];$tot_absent+=$row['absent'];$tot_late+=$row['late'];$tot_excused+=$row['excused'];$tot_recorded+=$recorded;
    }

    $overall_rate=$tot_recorded>0?round((($tot_present+$tot_late)/$tot_recorded)*100,1):0;

    return [
        'days'=>$days,
        'rows'=>$rows,
        'totals'=>[
            'present'=>$tot_present,'absent'=>$tot_absent,'late'=>$tot_late,'excused'=>$tot_excused,
            'recorded'=>$tot_recorded,'rate'=>$overall_rate,'children'=>count($members)
        ]
    ];
}

function attendance_status_label($status){
    switch($status){
        case 'present': return 'Present';
        case 'absent':  return 'Absent';
        case 'late':    return 'Late';
        case 'excused': return 'Excused';
        default:        return 'No Record';
    }
}

/* ────────────────────────────────
   EXPORT HELPERS
──────────────────────────────── */
function export_csv($data,$filename,$headers){
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="'.$filename.'_'.date('Ymd_His').'.csv"');
    $out=fopen('php://output','w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM for Excel
    fputcsv($out,$headers);
    foreach($data as $row){fputcsv($out,array_values((array)$row));}
    fclose($out);
    exit;
}

/* Generic printable HTML report page */
function print_report_page($title,$subtitle,$table_html,$summary_html=''){
    $ts=date('Y-m-d H:i');
    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>{$title}</title>
<style>
  *{box-sizing:border-box;margin:0;padding:0;}
  body{font-family:'Segoe UI',Arial,sans-serif;background:#fff;color:#111;padding:30px;font-size:12px;}
  .rpt-header{display:flex;justify-content:space-between;align-items:flex-start;border-bottom:3px solid #111;padding-bottom:14px;margin-bottom:18px;}
  .rpt-logo{font-size:22px;font-weight:900;letter-spacing:-1px;}
  .rpt-logo span{color:#5a9e2f;}
  .rpt-meta{text-align:right;font-size:11px;color:#555;}
  .rpt-title{font-size:18px;font-weight:800;margin-bottom:4px;}
  .rpt-sub{font-size:12px;color:#555;margin-bottom:16px;}
  .summary-boxes{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:18px;}
  .sbox{border:2px solid #111;border-radius:8px;padding:10px 18px;min-width:130px;}
  .sbox-label{font-size:10px;text-transform:uppercase;letter-spacing:.1em;color:#555;margin-bottom:3px;}
  .sbox-val{font-size:20px;font-weight:900;}
  table{width:100%;border-collapse:collapse;margin-bottom:20px;}
  thead th{background:#111;color:#fff;padding:8px 10px;text-align:left;font-size:10.5px;text-transform:uppercase;letter-spacing:.08em;white-space:nowrap;}
  tbody td{padding:7px 10px;border-bottom:1px solid #e5e5e5;vertical-align:middle;}
  tbody tr:nth-child(even) td{background:#f7f7f7;}
  .total-row td{font-weight:800;background:#f0f0f0!important;border-top:2px solid #111;}
  .badge{display:inline-block;padding:2px 8px;border-radius:999px;font-size:10px;font-weight:700;letter-spacing:.04em;}
  .b-paid{background:#d4edda;color:#155724;}
  .b-partial{background:#fff3cd;color:#856404;}
  .b-unpaid{background:#f8d7da;color:#721c24;}
  .b-nobill{background:#e9ecef;color:#495057;}
  .b-present{background:#d4edda;color:#155724;}
  .b-absent{background:#f8d7da;color:#721c24;}
  .b-late{background:#fff3cd;color:#856404;}
  .b-excused{background:#e0d4fd;color:#4b2e83;}
  .b-norecord{background:#e9ecef;color:#868e96;}
  .b-zone{background:#cce5ff;color:#004085;}
  .no-print{margin-bottom:18px;}
  @media print{
    .no-print{display:none!important;}
    thead th{-webkit-print-color-adjust:exact;print-color-adjust:exact;background:#111!important;color:#fff!important;}
    tbody tr:nth-child(even) td{-webkit-print-color-adjust:exact;print-color-adjust:exact;background:#f7f7f7!important;}
    .total-row td{-webkit-print-color-adjust:exact;print-color-adjust:exact;}
  }
  .footer{border-top:1px solid #ddd;padding-top:10px;font-size:10px;color:#888;display:flex;justify-content:space-between;}
</style>
</head>
<body>
<div class="no-print">
  <button onclick="window.print()" style="background:#111;color:#fff;border:0;border-radius:6px;padding:10px 20px;font-size:13px;font-weight:700;cursor:pointer;margin-right:8px;">🖨 Print / Save as PDF</button>
  <button onclick="window.close()" style="background:#eee;color:#111;border:1px solid #ccc;border-radius:6px;padding:10px 20px;font-size:13px;cursor:pointer;">✕ Close</button>
</div>
<div class="rpt-header">
  <div>
    <div class="rpt-logo">Academy <span>AMS</span></div>
    <div style="font-size:11px;color:#555;margin-top:3px;">Academy Management System</div>
  </div>
  <div class="rpt-meta">
    <div style="font-size:14px;font-weight:700;">{$title}</div>
    <div>{$subtitle}</div>
    <div>Generated: {$ts}</div>
  </div>
</div>
{$summary_html}
{$table_html}
<div class="footer"><span>Academy AMS — Confidential</span><span>Printed: {$ts}</span></div>
</body></html>
HTML;
    exit;
}

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
   POST HANDLERS
════════════════════════════════════════════════════ */
if($_SERVER['REQUEST_METHOD']==='POST'){
    $a=$_POST['action']??'';

    if($a==='save_member'){
        $id=$_POST['id']??'';
        $data=[$_POST['full_name'],$_POST['phone']?:null,$_POST['gender']?:null,$_POST['date_of_birth']?:null,
            $_POST['zone_id']?:default_zone($pdo),$_POST['guardian_name']?:null,$_POST['guardian_phone']?:null,
            $_POST['position']?:null,$_POST['school_name']?:null,(float)($_POST['monthly_fee']??0),(int)($_POST['due_day']??5),$_POST['notes']?:null,
            $_POST['admission_number']?:null,$_POST['class_name']?:null];
        if($id){
            $pdo->prepare("UPDATE members SET full_name=?,phone=?,gender=?,date_of_birth=?,zone_id=?,guardian_name=?,guardian_phone=?,position=?,school_name=?,monthly_fee=?,due_day=?,notes=?,admission_number=?,class_name=? WHERE id=?")->execute([...$data,$id]);
            go('members','Athlete updated');
        }else{
            $pdo->prepare("INSERT INTO members(full_name,phone,gender,date_of_birth,zone_id,guardian_name,guardian_phone,position,school_name,monthly_fee,due_day,notes,admission_number,class_name) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?) ON CONFLICT(full_name) DO NOTHING")->execute($data);
            go('members','Athlete added');
        }
    }
    if($a==='delete_member'){$pdo->prepare("UPDATE members SET is_active=FALSE WHERE id=?")->execute([$_POST['id']]);go('members','Athlete deactivated');}

    if($a==='save_session'){
        $id=$_POST['id']??'';
        if($id){$pdo->prepare("UPDATE sessions SET name=?,session_date=?,zone_id=? WHERE id=?")->execute([$_POST['name'],$_POST['session_date'],$_POST['zone_id'],$id]);go('attendance','Session updated');}
        else{$pdo->prepare("INSERT INTO sessions(name,session_date,zone_id) VALUES(?,?,?)")->execute([$_POST['name'],$_POST['session_date'],$_POST['zone_id']?:default_zone($pdo)]);go('attendance','Session created');}
    }
    if($a==='delete_session'){$pdo->prepare("DELETE FROM sessions WHERE id=?")->execute([$_POST['id']]);go('attendance','Session deleted');}

    if($a==='attendance'){
        $sid=$_POST['session_id'];$mid=$_POST['member_id'];$status=$_POST['status'];
        $check=$pdo->prepare("SELECT COUNT(*) FROM sessions s JOIN members m ON m.zone_id=s.zone_id WHERE s.id=? AND m.id=?");
        $check->execute([$sid,$mid]);
        if(!$check->fetchColumn()) go('attendance','Wrong zone: athlete does not belong to that session zone');
        $pdo->prepare("INSERT INTO attendance(session_id,member_id,status) VALUES(?,?,?) ON CONFLICT(session_id,member_id) DO UPDATE SET status=EXCLUDED.status")->execute([$sid,$mid,$status]);
        $pdo->prepare("INSERT INTO attendance(session_id,member_id,status) SELECT s.id,m.id,'absent' FROM sessions s JOIN members m ON m.zone_id=s.zone_id WHERE s.id=? AND m.is_active=TRUE AND NOT EXISTS(SELECT 1 FROM attendance a WHERE a.session_id=s.id AND a.member_id=m.id)")->execute([$sid]);
        go('attendance','Attendance saved. Unmarked same-zone athletes became absent.');
    }

    if($a==='payment'){
        $mid=(int)$_POST['member_id'];$amount=(float)$_POST['amount'];$per=trim($_POST['period']);
        if($mid<=0||$amount<=0) go('payments','Invalid payment data');
        ensure_bill($pdo,$mid,$per);
        $pdo->prepare("UPDATE monthly_bills SET paid_amount=paid_amount+?,paid_at=NOW(),updated_at=NOW(),note=? WHERE member_id=? AND period=?")->execute([$amount,$_POST['note']?:null,$mid,$per]);
        $pdo->prepare("INSERT INTO payment_logs(member_id,amount_paid,period,note,payment_date) VALUES(?,?,?,?,CURRENT_DATE)")->execute([$mid,$amount,$per,$_POST['note']?:null]);
        go('payments','Payment recorded');
    }

    if($a==='edit_payment'){
        $bill_id=(int)$_POST['bill_id'];
        $new_paid=(float)$_POST['paid_amount'];
        $note=trim($_POST['note']??'');
        if($bill_id<=0) go('payments','Invalid bill');
        $pdo->prepare("UPDATE monthly_bills SET paid_amount=?,note=?,updated_at=NOW(),paid_at=CASE WHEN ?::numeric>0 THEN NOW() ELSE paid_at END WHERE id=?")->execute([$new_paid,$note,$new_paid,$bill_id]);
        go('payments','Payment record updated');
    }

    if($a==='delete_payment'){
        $bill_id=(int)$_POST['bill_id'];
        if($bill_id<=0) go('payments','Invalid bill');
        $pdo->prepare("UPDATE monthly_bills SET paid_amount=0,paid_at=NULL,note=NULL,updated_at=NOW() WHERE id=?")->execute([$bill_id]);
        go('payments','Payment cleared');
    }

    if($a==='save_staff'){
        $id=$_POST['id']??'';
        if($id){$pdo->prepare("UPDATE staff SET full_name=?,phone=?,role=?,zone_id=?,monthly_salary=? WHERE id=?")->execute([$_POST['full_name'],$_POST['phone']?:null,$_POST['role'],$_POST['zone_id'],(float)($_POST['monthly_salary']??0),$id]);go('staff','Staff updated');}
        else{$pdo->prepare("INSERT INTO staff(full_name,phone,role,zone_id,monthly_salary) VALUES(?,?,?,?,?)")->execute([$_POST['full_name'],$_POST['phone']?:null,$_POST['role'],$_POST['zone_id']?:default_zone($pdo),(float)($_POST['monthly_salary']??0)]);go('staff','Staff added');}
    }
    if($a==='delete_staff'){$pdo->prepare("UPDATE staff SET is_active=FALSE WHERE id=?")->execute([$_POST['id']]);go('staff','Staff deactivated');}

    if($a==='payroll'){
        $base=(float)$_POST['base_salary'];$bonus=(float)$_POST['bonus'];$ded=(float)$_POST['deductions'];
        $net=$base+$bonus-$ded;
        $paid=(float)$_POST['amount_paid'];
        $status=($paid<=0)?'UNPAID':(($paid<$net)?'PARTIAL':'PAID');
        $pdo->prepare("
            INSERT INTO coach_payroll(staff_id,period,base_salary,bonus,deductions,net_salary,amount_paid,payment_status,paid_at,note)
            VALUES(?,?,?,?,?,?,?,?,NOW(),?)
            ON CONFLICT(staff_id,period) DO UPDATE SET
              base_salary=EXCLUDED.base_salary,bonus=EXCLUDED.bonus,deductions=EXCLUDED.deductions,
              net_salary=EXCLUDED.net_salary,amount_paid=EXCLUDED.amount_paid,
              payment_status=EXCLUDED.payment_status,paid_at=NOW(),note=EXCLUDED.note
        ")->execute([$_POST['staff_id'],$_POST['period'],$base,$bonus,$ded,$net,$paid,$status,$_POST['note']?:null]);
        go('payroll','Payroll saved');
    }

    if($a==='expense'){
        $pdo->prepare("INSERT INTO expenses(expense_date,category,description,amount,paid_to,approved_by,zone_id) VALUES(?,?,?,?,?,?,?)")
            ->execute([$_POST['expense_date'],$_POST['category'],$_POST['description'],(float)$_POST['amount'],$_POST['paid_to'],$_POST['approved_by'],$_POST['zone_id']?:default_zone($pdo)]);
        go('expenses','Expense saved');
    }

    if($a==='save_uniform'){
        $id=$_POST['id']??'';
        $member_id=(int)($_POST['member_id']??0);
        $jersey_number=(int)($_POST['jersey_number']??0);
        if($member_id<=0||$jersey_number<=0) go('uniforms','Please select athlete and provide jersey number');
        $check=$pdo->prepare("SELECT id FROM athlete_uniforms WHERE jersey_number=?".($id?" AND id!=?":''));
        $id?$check->execute([$jersey_number,$id]):$check->execute([$jersey_number]);
        if($check->fetch()) go('uniforms','Jersey number '.$jersey_number.' is already assigned to another athlete.');
        $data=[$member_id,$jersey_number,$_POST['jersey_category']??'Adult Unisex V-Neck',$_POST['jersey_size']??'',$_POST['jersey_chest']?:null,$_POST['jersey_length']?:null,$_POST['shorts_category']??'Adult Unisex Shorts',$_POST['shorts_size']??'',$_POST['shorts_waist']?:null,$_POST['shorts_inseam']?:null,(int)($_POST['quantity']??1),$_POST['issued_date']?:date('Y-m-d'),$_POST['note']?:null];
        try{
            if($id){$pdo->prepare("UPDATE athlete_uniforms SET member_id=?,jersey_number=?,jersey_category=?,jersey_size=?,jersey_chest=?,jersey_length=?,shorts_category=?,shorts_size=?,shorts_waist=?,shorts_inseam=?,quantity=?,issued_date=?,note=? WHERE id=?")->execute([...$data,$id]);go('uniforms','Uniform updated');}
            else{$pdo->prepare("INSERT INTO athlete_uniforms(member_id,jersey_number,jersey_category,jersey_size,jersey_chest,jersey_length,shorts_category,shorts_size,shorts_waist,shorts_inseam,quantity,issued_date,note) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute($data);go('uniforms','Uniform saved');}
        }catch(PDOException $e){
            if(strpos($e->getMessage(),'unique')!==false) go('uniforms','Jersey number already assigned. Use another number.');
            throw $e;
        }
    }
    if($a==='delete_uniform'){$pdo->prepare("DELETE FROM athlete_uniforms WHERE id=?")->execute([$_POST['id']]);go('uniforms','Uniform record deleted');}
}

/* ════════════════════════════════════════════════════
   EXPORTS — CSV
════════════════════════════════════════════════════ */
$p=period();

/* ── CSV exports ── */
$export_type=$_GET['export']??'';

if($export_type==='non_payers'){
    $non_payers=non_payers_with_attendance($pdo,$p,$_GET['att_month']??$p);
    export_csv(array_map(fn($r)=>[$r['full_name'],$r['zone_name'],$r['phone'],$r['guardian_name'],$r['guardian_phone'],$r['expected_amount'],$r['paid_amount'],$r['remaining'],$r['sessions_attended'],$r['sessions_list']],$non_payers),'non_payers_attendance_report',['Athlete','Zone','Phone','Guardian','Guardian Phone','Expected (RWF)','Paid (RWF)','Remaining (RWF)','Sessions Attended','Sessions List']);
}

if($export_type==='overdue'){
    $overdue=overdue_payments_report($pdo,$p);
    export_csv(array_map(fn($r)=>[$r['full_name'],$r['zone_name'],$r['phone'],$r['guardian_name'],$r['expected_amount'],$r['paid_amount'],$r['remaining'],$r['due_date'],$r['days_overdue'],bill_status($r['expected_amount'],$r['paid_amount'])],$overdue),'overdue_payments_report',['Athlete','Zone','Phone','Guardian','Expected (RWF)','Paid (RWF)','Remaining (RWF)','Due Date','Days Overdue','Status']);
}

if($export_type==='attendance_summary_csv'){
    $att_summary=attendance_summary($pdo,null,$_GET['att_month']??$p);
    export_csv(array_map(fn($r)=>[$r['full_name'],$r['zone_name'],$r['total_sessions'],$r['present_count'],$r['absent_count'],$r['late_count'],$r['attendance_rate']],$att_summary),'attendance_summary_report',['Athlete','Zone','Total Sessions','Present','Absent','Late','Attendance Rate %']);
}

if($export_type==='athletes_csv'){
    $rows=members($pdo);
    export_csv(array_map(fn($r)=>[$r['full_name'],$r['zone_name'],$r['phone'],$r['gender'],$r['date_of_birth'],$r['position'],$r['school_name'],$r['guardian_name'],$r['guardian_phone'],$r['monthly_fee'],$r['due_day'],$r['is_active']?'Active':'Inactive',$r['notes'],$r['created_at']],$rows),'athletes_report',['Full Name','Zone','Phone','Gender','DOB','Position','School','Guardian','Guardian Phone','Monthly Fee (RWF)','Due Day','Status','Notes','Registered']);
}

if($export_type==='staff_csv'){
    $rows=staff($pdo);
    export_csv(array_map(fn($r)=>[$r['full_name'],$r['zone_name'],$r['phone'],$r['role'],$r['monthly_salary'],$r['is_active']?'Active':'Inactive',$r['created_at']],$rows),'staff_report',['Full Name','Zone','Phone','Role','Monthly Salary (RWF)','Status','Added']);
}

if($export_type==='payments_csv'){
    $rows=$pdo->prepare("SELECT m.full_name,z.name zone_name,b.period,b.expected_amount,b.paid_amount,GREATEST(b.expected_amount-b.paid_amount,0) remaining,b.due_date,b.note,b.paid_at FROM monthly_bills b JOIN members m ON m.id=b.member_id LEFT JOIN academy_zones z ON z.id=m.zone_id WHERE b.period=? ORDER BY z.id,m.full_name");
    $rows->execute([$p]);$rows=$rows->fetchAll();
    export_csv(array_map(fn($r)=>[$r['full_name'],$r['zone_name'],$r['period'],$r['expected_amount'],$r['paid_amount'],$r['remaining'],bill_status($r['expected_amount'],$r['paid_amount']),$r['due_date'],$r['note'],$r['paid_at']],$rows),'billing_report_'.$p,['Athlete','Zone','Period','Expected (RWF)','Paid (RWF)','Remaining (RWF)','Status','Due Date','Note','Paid At']);
}

if($export_type==='payment_logs_csv'){
    $rows=$pdo->query("SELECT pl.payment_date,pl.created_at,m.full_name,z.name zone_name,pl.period,pl.amount_paid,pl.note FROM payment_logs pl JOIN members m ON m.id=pl.member_id LEFT JOIN academy_zones z ON z.id=m.zone_id ORDER BY pl.created_at DESC LIMIT 5000")->fetchAll();
    export_csv(array_map(fn($r)=>[$r['payment_date'],$r['created_at'],$r['full_name'],$r['zone_name'],$r['period'],$r['amount_paid'],$r['note']],$rows),'payment_logs',['Date','Created At','Athlete','Zone','Period','Amount (RWF)','Note']);
}

if($export_type==='payroll_csv'){
    $rows=$pdo->prepare("SELECT c.*,s.full_name,z.name zone_name FROM coach_payroll c JOIN staff s ON s.id=c.staff_id LEFT JOIN academy_zones z ON z.id=s.zone_id WHERE c.period=? ORDER BY z.id,s.full_name");
    $rows->execute([$p]);$rows=$rows->fetchAll();
    export_csv(array_map(fn($r)=>[$r['full_name'],$r['zone_name'],$r['period'],$r['base_salary'],$r['bonus'],$r['deductions'],$r['net_salary'],$r['amount_paid'],$r['payment_status'],$r['note'],$r['paid_at']],$rows),'payroll_report_'.$p,['Staff','Zone','Period','Base (RWF)','Bonus (RWF)','Deductions (RWF)','Net Salary (RWF)','Paid (RWF)','Status','Note','Paid At']);
}

if($export_type==='expenses_csv'){
    $rows=$pdo->query("SELECT e.expense_date,z.name zone_name,e.category,e.description,e.amount,e.paid_to,e.approved_by FROM expenses e LEFT JOIN academy_zones z ON z.id=e.zone_id ORDER BY e.expense_date DESC")->fetchAll();
    export_csv(array_map(fn($r)=>[$r['expense_date'],$r['zone_name'],$r['category'],$r['description'],$r['amount'],$r['paid_to'],$r['approved_by']],$rows),'expenses_report',['Date','Zone','Category','Description','Amount (RWF)','Paid To','Approved By']);
}

if($export_type==='uniform_excel'){
    $rows=$pdo->query("SELECT u.*,m.full_name,z.name zone_name FROM athlete_uniforms u JOIN members m ON m.id=u.member_id LEFT JOIN academy_zones z ON z.id=m.zone_id ORDER BY u.jersey_number ASC")->fetchAll();
    export_csv(array_map(fn($r)=>[$r['jersey_number'],$r['full_name'],$r['zone_name'],$r['jersey_category'],$r['jersey_size'],$r['jersey_chest'],$r['jersey_length'],$r['shorts_category'],$r['shorts_size'],$r['shorts_waist'],$r['shorts_inseam'],$r['quantity'],$r['issued_date'],$r['note']],$rows),'uniform_report',['Jersey #','Athlete','Zone','Jersey Category','Jersey Size','Chest','Length','Shorts Category','Shorts Size','Waist','Inseam','Qty','Issued Date','Note']);
}

/* ── SESSION ATTENDANCE per-session CSV ── */
if($export_type==='session_attendance_csv'){
    $sid=(int)($_GET['session_id']??0);
    if(!$sid){header("Location:index.php?view=attendance&period={$p}&msg=".urlencode('Select a session'));exit;}
    $sess=$pdo->prepare("SELECT s.*,COALESCE(s.session_date,s.date) AS sdate,z.name zone_name FROM sessions s LEFT JOIN academy_zones z ON z.id=s.zone_id WHERE s.id=?");
    $sess->execute([$sid]);$sess=$sess->fetch();
    $rows=$pdo->prepare("SELECT m.full_name,z.name zone_name,m.phone,m.position,COALESCE(a.status,'absent') status FROM members m LEFT JOIN academy_zones z ON z.id=m.zone_id LEFT JOIN attendance a ON a.member_id=m.id AND a.session_id=? WHERE m.zone_id=? AND m.is_active=TRUE ORDER BY m.full_name");
    $rows->execute([$sid,$sess['zone_id']]);$rows=$rows->fetchAll();
    export_csv(array_map(fn($r)=>[$r['full_name'],$r['zone_name'],$r['phone'],$r['position'],strtoupper($r['status'])],$rows),'session_attendance_'.($sess['sdate']??'na').'_'.($sess['name']??'session'),['Athlete','Zone','Phone','Position','Status']);
}

/* ── ATTENDANCE MATRIX (date-range) — CSV / Excel ── */
if($export_type==='attendance_matrix_csv'){
    $start=preg_match('/^\d{4}-\d{2}-\d{2}$/',$_GET['start_date']??'')?$_GET['start_date']:$p.'-01';
    $end  =preg_match('/^\d{4}-\d{2}-\d{2}$/',$_GET['end_date']??'')?$_GET['end_date']:date('Y-m-t',strtotime($start));
    $mx=attendance_matrix($pdo,$start,$end);

    $headers=['Full Name','Admission Number','Class','Parent/Guardian','Zone'];
    foreach($mx['days'] as $d){ $headers[]=date('d M',strtotime($d)); }
    $headers=array_merge($headers,['Total Present','Total Absent','Total Late','Total Excused','Attendance %']);

    $rows=[];
    foreach($mx['rows'] as $row){
        $m=$row['member'];
        $line=[$m['full_name'],$m['admission_number'],$m['class_name'],$m['guardian_name'],$m['zone_name']];
        foreach($mx['days'] as $d){ $line[]=attendance_status_label($row['days'][$d]); }
        $line[]=$row['present'];$line[]=$row['absent'];$line[]=$row['late'];$line[]=$row['excused'];$line[]=$row['rate'].'%';
        $rows[]=$line;
    }
    // Overall academy stats as a trailing summary row
    $summaryLine=array_fill(0,5,'');
    $summaryLine[0]='TOTAL / ACADEMY AVERAGE';
    foreach($mx['days'] as $d){ $summaryLine[]=''; }
    $summaryLine[]=$mx['totals']['present'];
    $summaryLine[]=$mx['totals']['absent'];
    $summaryLine[]=$mx['totals']['late'];
    $summaryLine[]=$mx['totals']['excused'];
    $summaryLine[]=$mx['totals']['rate'].'%';
    $rows[]=$summaryLine;

    export_csv($rows,'attendance_report_'.$start.'_to_'.$end,$headers);
}

/* ════════════════════════════════════════════════════
   EXPORTS — PRINTABLE PDF/HTML (open in new tab)
════════════════════════════════════════════════════ */

/* ── Athletes PDF ── */
if($export_type==='athletes_pdf'){
    $rows=members($pdo);
    $active=count(array_filter($rows,fn($r)=>$r['is_active']));
    $inactive=count($rows)-$active;
    ob_start();
    echo '<div class="summary-boxes">';
    echo '<div class="sbox"><div class="sbox-label">Total Athletes</div><div class="sbox-val">'.count($rows).'</div></div>';
    echo '<div class="sbox"><div class="sbox-label">Active</div><div class="sbox-val" style="color:#155724">'.$active.'</div></div>';
    echo '<div class="sbox"><div class="sbox-label">Inactive</div><div class="sbox-val" style="color:#721c24">'.$inactive.'</div></div>';
    echo '</div>';
    $sum=ob_get_clean();
    ob_start();
    echo '<table><thead><tr><th>#</th><th>Athlete</th><th>Zone</th><th>Phone</th><th>Gender</th><th>Position</th><th>School</th><th>Guardian</th><th>Monthly Fee</th><th>Status</th></tr></thead><tbody>';
    if(empty($rows)) echo '<tr><td colspan="10" style="text-align:center;padding:20px;color:#888">No athletes found</td></tr>';
    foreach($rows as $i=>$r){
        $bs=$r['is_active']?'b-active':'b-inactive';
        $st=$r['is_active']?'Active':'Inactive';
        echo '<tr>';
        echo '<td>'.($i+1).'</td>';
        echo '<td><strong>'.h($r['full_name']).'</strong></td>';
        echo '<td><span class="badge b-zone">'.h($r['zone_name']).'</span></td>';
        echo '<td>'.h($r['phone']).'</td>';
        echo '<td>'.h($r['gender']).'</td>';
        echo '<td>'.h($r['position']).'</td>';
        echo '<td>'.h($r['school_name']).'</td>';
        echo '<td>'.h($r['guardian_name']).'<br><small style="color:#666">'.h($r['guardian_phone']).'</small></td>';
        echo '<td>'.money($r['monthly_fee']).'</td>';
        echo '<td><span class="badge '.$bs.'">'.$st.'</span></td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
    $tbl=ob_get_clean();
    print_report_page('Athletes Report','All Registered Athletes — '.date('Y-m-d'),$tbl,$sum);
}

/* ── Staff PDF ── */
if($export_type==='staff_pdf'){
    $rows=staff($pdo);
    $active=count(array_filter($rows,fn($r)=>$r['is_active']));
    ob_start();
    echo '<div class="summary-boxes">';
    echo '<div class="sbox"><div class="sbox-label">Total Staff</div><div class="sbox-val">'.count($rows).'</div></div>';
    echo '<div class="sbox"><div class="sbox-label">Active</div><div class="sbox-val" style="color:#155724">'.$active.'</div></div>';
    $totSal=array_sum(array_column($rows,'monthly_salary'));
    echo '<div class="sbox"><div class="sbox-label">Total Salaries</div><div class="sbox-val" style="font-size:14px">'.money($totSal).'</div></div>';
    echo '</div>';
    $sum=ob_get_clean();
    ob_start();
    echo '<table><thead><tr><th>#</th><th>Staff</th><th>Zone</th><th>Phone</th><th>Role</th><th>Monthly Salary</th><th>Status</th></tr></thead><tbody>';
    if(empty($rows)) echo '<tr><td colspan="7" style="text-align:center;padding:20px;color:#888">No staff found</td></tr>';
    foreach($rows as $i=>$r){
        echo '<tr>';
        echo '<td>'.($i+1).'</td>';
        echo '<td><strong>'.h($r['full_name']).'</strong></td>';
        echo '<td><span class="badge b-zone">'.h($r['zone_name']).'</span></td>';
        echo '<td>'.h($r['phone']).'</td>';
        echo '<td style="text-transform:capitalize">'.h($r['role']).'</td>';
        echo '<td>'.money($r['monthly_salary']).'</td>';
        echo '<td><span class="badge '.($r['is_active']?'b-paid':'b-unpaid').'">'.($r['is_active']?'Active':'Inactive').'</span></td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
    $tbl=ob_get_clean();
    print_report_page('Staff Report','All Staff Members — '.date('Y-m-d'),$tbl,$sum);
}

/* ── Payments PDF ── */
if($export_type==='payments_pdf'){
    $rows=$pdo->prepare("SELECT m.full_name,z.name zone_name,b.period,b.expected_amount,b.paid_amount,GREATEST(b.expected_amount-b.paid_amount,0) remaining,b.due_date,b.note,b.paid_at FROM monthly_bills b JOIN members m ON m.id=b.member_id LEFT JOIN academy_zones z ON z.id=m.zone_id WHERE b.period=? ORDER BY z.id,m.full_name");
    $rows->execute([$p]);$rows=$rows->fetchAll();
    $totExp=array_sum(array_column($rows,'expected_amount'));
    $totPaid=array_sum(array_column($rows,'paid_amount'));
    $totRem=array_sum(array_column($rows,'remaining'));
    $paid_c=count(array_filter($rows,fn($r)=>bill_status($r['expected_amount'],$r['paid_amount'])==='PAID'));
    $partial_c=count(array_filter($rows,fn($r)=>bill_status($r['expected_amount'],$r['paid_amount'])==='PARTIAL'));
    $unpaid_c=count(array_filter($rows,fn($r)=>bill_status($r['expected_amount'],$r['paid_amount'])==='UNPAID'));
    ob_start();
    echo '<div class="summary-boxes">';
    echo '<div class="sbox"><div class="sbox-label">Expected</div><div class="sbox-val" style="font-size:14px">'.money($totExp).'</div></div>';
    echo '<div class="sbox"><div class="sbox-label">Collected</div><div class="sbox-val" style="font-size:14px;color:#155724">'.money($totPaid).'</div></div>';
    echo '<div class="sbox"><div class="sbox-label">Outstanding</div><div class="sbox-val" style="font-size:14px;color:#856404">'.money($totRem).'</div></div>';
    echo '<div class="sbox"><div class="sbox-label">Paid</div><div class="sbox-val" style="color:#155724">'.$paid_c.'</div></div>';
    echo '<div class="sbox"><div class="sbox-label">Partial</div><div class="sbox-val" style="color:#856404">'.$partial_c.'</div></div>';
    echo '<div class="sbox"><div class="sbox-label">Unpaid</div><div class="sbox-val" style="color:#721c24">'.$unpaid_c.'</div></div>';
    echo '</div>';
    $sum=ob_get_clean();
    ob_start();
    echo '<table><thead><tr><th>#</th><th>Athlete</th><th>Zone</th><th>Expected</th><th>Paid</th><th>Remaining</th><th>Status</th><th>Due Date</th><th>Note</th></tr></thead><tbody>';
    if(empty($rows)) echo '<tr><td colspan="9" style="text-align:center;padding:20px;color:#888">No billing records for '.$p.'</td></tr>';
    foreach($rows as $i=>$r){
        $stt=bill_status($r['expected_amount'],$r['paid_amount']);
        $bc=$stt==='PAID'?'b-paid':($stt==='PARTIAL'?'b-partial':($stt==='NO BILL'?'b-nobill':'b-unpaid'));
        echo '<tr>';
        echo '<td>'.($i+1).'</td>';
        echo '<td><strong>'.h($r['full_name']).'</strong></td>';
        echo '<td><span class="badge b-zone">'.h($r['zone_name']).'</span></td>';
        echo '<td>'.money($r['expected_amount']).'</td>';
        echo '<td>'.money($r['paid_amount']).'</td>';
        echo '<td>'.money($r['remaining']).'</td>';
        echo '<td><span class="badge '.$bc.'">'.$stt.'</span></td>';
        echo '<td>'.h($r['due_date']).'</td>';
        echo '<td>'.h($r['note']).'</td>';
        echo '</tr>';
    }
    if(!empty($rows)){
        echo '<tr class="total-row">';
        echo '<td colspan="3">TOTALS ('.(count($rows)).' records)</td>';
        echo '<td>'.money($totExp).'</td>';
        echo '<td>'.money($totPaid).'</td>';
        echo '<td>'.money($totRem).'</td>';
        echo '<td colspan="3">—</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
    $tbl=ob_get_clean();
    print_report_page('Billing & Payments Report','Period: '.$p,$tbl,$sum);
}

/* ── Payment Logs PDF ── */
if($export_type==='payment_logs_pdf'){
    $rows=$pdo->query("SELECT pl.payment_date,pl.created_at,m.full_name,z.name zone_name,pl.period,pl.amount_paid,pl.note FROM payment_logs pl JOIN members m ON m.id=pl.member_id LEFT JOIN academy_zones z ON z.id=m.zone_id ORDER BY pl.created_at DESC LIMIT 5000")->fetchAll();
    $total=array_sum(array_column($rows,'amount_paid'));
    ob_start();
    echo '<div class="summary-boxes">';
    echo '<div class="sbox"><div class="sbox-label">Total Transactions</div><div class="sbox-val">'.count($rows).'</div></div>';
    echo '<div class="sbox"><div class="sbox-label">Total Collected</div><div class="sbox-val" style="font-size:14px;color:#155724">'.money($total).'</div></div>';
    echo '</div>';
    $sum=ob_get_clean();
    ob_start();
    echo '<table><thead><tr><th>#</th><th>Date</th><th>Athlete</th><th>Zone</th><th>Period</th><th>Amount</th><th>Note</th></tr></thead><tbody>';
    if(empty($rows)) echo '<tr><td colspan="7" style="text-align:center;padding:20px;color:#888">No payment logs</td></tr>';
    foreach($rows as $i=>$r){
        echo '<tr>';
        echo '<td>'.($i+1).'</td>';
        echo '<td>'.h(substr($r['created_at'],0,10)).'</td>';
        echo '<td><strong>'.h($r['full_name']).'</strong></td>';
        echo '<td><span class="badge b-zone">'.h($r['zone_name']).'</span></td>';
        echo '<td>'.h(trim($r['period'])).'</td>';
        echo '<td>'.money($r['amount_paid']).'</td>';
        echo '<td>'.h($r['note']).'</td>';
        echo '</tr>';
    }
    if(!empty($rows)){
        echo '<tr class="total-row"><td colspan="5">TOTAL</td><td>'.money($total).'</td><td>—</td></tr>';
    }
    echo '</tbody></table>';
    $tbl=ob_get_clean();
    print_report_page('Payment Logs Report','All Payment Transactions',$tbl,$sum);
}

/* ── Payroll PDF ── */
if($export_type==='payroll_pdf'){
    $rows=$pdo->prepare("SELECT c.*,s.full_name,z.name zone_name FROM coach_payroll c JOIN staff s ON s.id=c.staff_id LEFT JOIN academy_zones z ON z.id=s.zone_id WHERE c.period=? ORDER BY z.id,s.full_name");
    $rows->execute([$p]);$rows=$rows->fetchAll();
    $totBase=array_sum(array_column($rows,'base_salary'));
    $totBonus=array_sum(array_column($rows,'bonus'));
    $totDed=array_sum(array_column($rows,'deductions'));
    $totNet=array_sum(array_column($rows,'net_salary'));
    $totPaid2=array_sum(array_column($rows,'amount_paid'));
    ob_start();
    echo '<div class="summary-boxes">';
    echo '<div class="sbox"><div class="sbox-label">Staff</div><div class="sbox-val">'.count($rows).'</div></div>';
    echo '<div class="sbox"><div class="sbox-label">Total Net Salaries</div><div class="sbox-val" style="font-size:14px">'.money($totNet).'</div></div>';
    echo '<div class="sbox"><div class="sbox-label">Total Paid</div><div class="sbox-val" style="font-size:14px;color:#155724">'.money($totPaid2).'</div></div>';
    echo '<div class="sbox"><div class="sbox-label">Outstanding</div><div class="sbox-val" style="font-size:14px;color:#856404">'.money(max(0,$totNet-$totPaid2)).'</div></div>';
    echo '</div>';
    $sum=ob_get_clean();
    ob_start();
    echo '<table><thead><tr><th>#</th><th>Staff</th><th>Zone</th><th>Base</th><th>Bonus</th><th>Deductions</th><th>Net Salary</th><th>Paid</th><th>Status</th><th>Note</th></tr></thead><tbody>';
    if(empty($rows)) echo '<tr><td colspan="10" style="text-align:center;padding:20px;color:#888">No payroll records for '.$p.'</td></tr>';
    foreach($rows as $i=>$r){
        $ps=$r['payment_status']??'UNPAID';
        $bc=$ps==='PAID'?'b-paid':($ps==='PARTIAL'?'b-partial':'b-unpaid');
        echo '<tr>';
        echo '<td>'.($i+1).'</td>';
        echo '<td><strong>'.h($r['full_name']).'</strong></td>';
        echo '<td><span class="badge b-zone">'.h($r['zone_name']).'</span></td>';
        echo '<td>'.money($r['base_salary']).'</td>';
        echo '<td>'.money($r['bonus']).'</td>';
        echo '<td>'.money($r['deductions']).'</td>';
        echo '<td><strong>'.money($r['net_salary']).'</strong></td>';
        echo '<td>'.money($r['amount_paid']).'</td>';
        echo '<td><span class="badge '.$bc.'">'.$ps.'</span></td>';
        echo '<td>'.h($r['note']).'</td>';
        echo '</tr>';
    }
    if(!empty($rows)){
        echo '<tr class="total-row">';
        echo '<td colspan="3">TOTALS</td>';
        echo '<td>'.money($totBase).'</td>';
        echo '<td>'.money($totBonus).'</td>';
        echo '<td>'.money($totDed).'</td>';
        echo '<td>'.money($totNet).'</td>';
        echo '<td>'.money($totPaid2).'</td>';
        echo '<td colspan="2">—</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
    $tbl=ob_get_clean();
    print_report_page('Payroll Report','Period: '.$p,$tbl,$sum);
}

/* ── Expenses PDF ── */
if($export_type==='expenses_pdf'){
    $rows=$pdo->query("SELECT e.*,z.name zone_name FROM expenses e LEFT JOIN academy_zones z ON z.id=e.zone_id ORDER BY e.expense_date DESC")->fetchAll();
    $total=array_sum(array_column($rows,'amount'));
    $by_zone=[];foreach($rows as $r){$by_zone[$r['zone_name']]=($by_zone[$r['zone_name']]??0)+$r['amount'];}
    ob_start();
    echo '<div class="summary-boxes">';
    echo '<div class="sbox"><div class="sbox-label">Total Records</div><div class="sbox-val">'.count($rows).'</div></div>';
    echo '<div class="sbox"><div class="sbox-label">Total Amount</div><div class="sbox-val" style="font-size:14px;color:#721c24">'.money($total).'</div></div>';
    foreach($by_zone as $zn=>$za){
        echo '<div class="sbox"><div class="sbox-label">'.h($zn).'</div><div class="sbox-val" style="font-size:14px">'.money($za).'</div></div>';
    }
    echo '</div>';
    $sum=ob_get_clean();
    ob_start();
    echo '<table><thead><tr><th>#</th><th>Date</th><th>Zone</th><th>Category</th><th>Description</th><th>Amount</th><th>Paid To</th><th>Approved By</th></tr></thead><tbody>';
    if(empty($rows)) echo '<tr><td colspan="8" style="text-align:center;padding:20px;color:#888">No expenses found</td></tr>';
    foreach($rows as $i=>$r){
        echo '<tr>';
        echo '<td>'.($i+1).'</td>';
        echo '<td>'.h($r['expense_date']).'</td>';
        echo '<td><span class="badge b-zone">'.h($r['zone_name']).'</span></td>';
        echo '<td>'.h($r['category']).'</td>';
        echo '<td>'.h($r['description']).'</td>';
        echo '<td>'.money($r['amount']).'</td>';
        echo '<td>'.h($r['paid_to']).'</td>';
        echo '<td>'.h($r['approved_by']).'</td>';
        echo '</tr>';
    }
    if(!empty($rows)){
        echo '<tr class="total-row"><td colspan="5">TOTAL EXPENSES</td><td>'.money($total).'</td><td colspan="2">—</td></tr>';
    }
    echo '</tbody></table>';
    $tbl=ob_get_clean();
    print_report_page('Expenses Report','All Recorded Expenses',$tbl,$sum);
}

/* ── Uniforms PDF ── */
if($export_type==='uniform_pdf'){
    $rows=$pdo->query("SELECT u.*,m.full_name,z.name zone_name FROM athlete_uniforms u JOIN members m ON m.id=u.member_id LEFT JOIN academy_zones z ON z.id=m.zone_id ORDER BY u.jersey_number ASC")->fetchAll();
    $totalQty=array_sum(array_column($rows,'quantity'));
    ob_start();
    echo '<div class="summary-boxes">';
    echo '<div class="sbox"><div class="sbox-label">Records</div><div class="sbox-val">'.count($rows).'</div></div>';
    echo '<div class="sbox"><div class="sbox-label">Total Kits</div><div class="sbox-val">'.$totalQty.'</div></div>';
    echo '</div>';
    $sum=ob_get_clean();
    ob_start();
    echo '<table><thead><tr><th>#</th><th>Athlete</th><th>Zone</th><th>Jersey Cat.</th><th>Jersey Size</th><th>Chest</th><th>Length</th><th>Shorts Cat.</th><th>Shorts Size</th><th>Waist</th><th>Inseam</th><th>Qty</th><th>Issued</th><th>Note</th></tr></thead><tbody>';
    if(empty($rows)) echo '<tr><td colspan="14" style="text-align:center;padding:20px;color:#888">No uniform records</td></tr>';
    foreach($rows as $i=>$r){
        echo '<tr>';
        echo '<td><strong>'.h($r['jersey_number']).'</strong></td>';
        echo '<td>'.h($r['full_name']).'</td>';
        echo '<td><span class="badge b-zone">'.h($r['zone_name']).'</span></td>';
        echo '<td>'.h($r['jersey_category']).'</td>';
        echo '<td>'.h($r['jersey_size']).'</td>';
        echo '<td>'.h($r['jersey_chest']).'</td>';
        echo '<td>'.h($r['jersey_length']).'</td>';
        echo '<td>'.h($r['shorts_category']).'</td>';
        echo '<td>'.h($r['shorts_size']).'</td>';
        echo '<td>'.h($r['shorts_waist']).'</td>';
        echo '<td>'.h($r['shorts_inseam']).'</td>';
        echo '<td>'.h($r['quantity']).'</td>';
        echo '<td>'.h($r['issued_date']).'</td>';
        echo '<td>'.h($r['note']).'</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
    $tbl=ob_get_clean();
    print_report_page('Uniform Report','All Athlete Uniform Records — '.date('Y-m-d'),$tbl,$sum);
}

/* ── Attendance Summary PDF ── */
if($export_type==='attendance_summary_pdf'){
    $att_month=$_GET['att_month']??$p;
    $att_summary=attendance_summary($pdo,null,$att_month);
    $total_sessions=max(array_column($att_summary,'total_sessions') ?: [0]);
    $avg_rate=count($att_summary)?round(array_sum(array_column($att_summary,'attendance_rate'))/count($att_summary),1):0;
    ob_start();
    echo '<div class="summary-boxes">';
    echo '<div class="sbox"><div class="sbox-label">Athletes</div><div class="sbox-val">'.count($att_summary).'</div></div>';
    echo '<div class="sbox"><div class="sbox-label">Max Sessions</div><div class="sbox-val">'.$total_sessions.'</div></div>';
    echo '<div class="sbox"><div class="sbox-label">Avg Attendance</div><div class="sbox-val">'.$avg_rate.'%</div></div>';
    echo '</div>';
    $sum=ob_get_clean();
    ob_start();
    echo '<table><thead><tr><th>#</th><th>Athlete</th><th>Zone</th><th>Total Sessions</th><th>Present</th><th>Absent</th><th>Late</th><th>Attendance Rate</th></tr></thead><tbody>';
    if(empty($att_summary)) echo '<tr><td colspan="8" style="text-align:center;padding:20px;color:#888">No attendance records for '.$att_month.'</td></tr>';
    foreach($att_summary as $i=>$att){
        $rate=(float)($att['attendance_rate']??0);
        $col=$rate>=80?'#155724':($rate>=50?'#856404':'#721c24');
        $bg=$rate>=80?'#d4edda':($rate>=50?'#fff3cd':'#f8d7da');
        echo '<tr>';
        echo '<td>'.($i+1).'</td>';
        echo '<td><strong>'.h($att['full_name']).'</strong></td>';
        echo '<td><span class="badge b-zone">'.h($att['zone_name']).'</span></td>';
        echo '<td>'.$att['total_sessions'].'</td>';
        echo '<td><span class="badge b-present">'.$att['present_count'].'</span></td>';
        echo '<td><span class="badge b-absent">'.$att['absent_count'].'</span></td>';
        echo '<td><span class="badge b-late">'.$att['late_count'].'</span></td>';
        echo '<td><span style="background:'.$bg.';color:'.$col.';padding:2px 10px;border-radius:999px;font-weight:700;font-size:11px">'.$rate.'%</span></td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
    $tbl=ob_get_clean();
    print_report_page('Attendance Summary Report','Period: '.$att_month,$tbl,$sum);
}

/* ── Per-Session Attendance PDF ── */
if($export_type==='session_attendance_pdf'){
    $sid=(int)($_GET['session_id']??0);
    if(!$sid){header("Location:index.php?view=attendance&period={$p}&msg=".urlencode('Select a session'));exit;}
    $sess=$pdo->prepare("SELECT s.*,COALESCE(s.session_date,s.date) AS sdate,z.name zone_name FROM sessions s LEFT JOIN academy_zones z ON z.id=s.zone_id WHERE s.id=?");
    $sess->execute([$sid]);$sess=$sess->fetch();
    if(!$sess){header("Location:index.php?view=attendance&period={$p}&msg=".urlencode('Session not found'));exit;}
    $rows=$pdo->prepare("SELECT m.full_name,z.name zone_name,m.phone,m.position,m.school_name,COALESCE(a.status,'absent') status FROM members m LEFT JOIN academy_zones z ON z.id=m.zone_id LEFT JOIN attendance a ON a.member_id=m.id AND a.session_id=? WHERE m.zone_id=? AND m.is_active=TRUE ORDER BY m.full_name");
    $rows->execute([$sid,$sess['zone_id']]);$rows=$rows->fetchAll();
    $present=count(array_filter($rows,fn($r)=>$r['status']==='present'));
    $absent=count(array_filter($rows,fn($r)=>$r['status']==='absent'));
    $late=count(array_filter($rows,fn($r)=>$r['status']==='late'));
    $rate=count($rows)?round(($present+$late)/count($rows)*100,1):0;
    ob_start();
    echo '<div class="summary-boxes">';
    echo '<div class="sbox"><div class="sbox-label">Total Athletes</div><div class="sbox-val">'.count($rows).'</div></div>';
    echo '<div class="sbox"><div class="sbox-label">Present</div><div class="sbox-val" style="color:#155724">'.$present.'</div></div>';
    echo '<div class="sbox"><div class="sbox-label">Absent</div><div class="sbox-val" style="color:#721c24">'.$absent.'</div></div>';
    echo '<div class="sbox"><div class="sbox-label">Late</div><div class="sbox-val" style="color:#856404">'.$late.'</div></div>';
    echo '<div class="sbox"><div class="sbox-label">Attendance Rate</div><div class="sbox-val">'.$rate.'%</div></div>';
    echo '</div>';
    $sum=ob_get_clean();
    ob_start();
    echo '<table><thead><tr><th>#</th><th>Athlete</th><th>Zone</th><th>Phone</th><th>Position</th><th>School</th><th>Status</th></tr></thead><tbody>';
    if(empty($rows)) echo '<tr><td colspan="7" style="text-align:center;padding:20px;color:#888">No athletes in this zone/session</td></tr>';
    foreach($rows as $i=>$r){
        $bc=$r['status']==='present'?'b-present':($r['status']==='late'?'b-late':'b-absent');
        echo '<tr>';
        echo '<td>'.($i+1).'</td>';
        echo '<td><strong>'.h($r['full_name']).'</strong></td>';
        echo '<td><span class="badge b-zone">'.h($r['zone_name']).'</span></td>';
        echo '<td>'.h($r['phone']).'</td>';
        echo '<td>'.h($r['position']).'</td>';
        echo '<td>'.h($r['school_name']).'</td>';
        echo '<td><span class="badge '.$bc.'">'.strtoupper($r['status']).'</span></td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
    $tbl=ob_get_clean();
    print_report_page('Session Attendance Report',h($sess['name']).' — '.h($sess['zone_name']).' — '.h($sess['sdate']),$tbl,$sum);
}

/* ── ATTENDANCE MATRIX (date-range) — PDF ── */
if($export_type==='attendance_matrix_pdf'){
    $start=preg_match('/^\d{4}-\d{2}-\d{2}$/',$_GET['start_date']??'')?$_GET['start_date']:$p.'-01';
    $end  =preg_match('/^\d{4}-\d{2}-\d{2}$/',$_GET['end_date']??'')?$_GET['end_date']:date('Y-m-t',strtotime($start));
    $mx=attendance_matrix($pdo,$start,$end);
    $tot=$mx['totals'];

    ob_start();
    echo '<div class="summary-boxes">';
    echo '<div class="sbox"><div class="sbox-label">Children</div><div class="sbox-val">'.$tot['children'].'</div></div>';
    echo '<div class="sbox"><div class="sbox-label">Days in Period</div><div class="sbox-val">'.count($mx['days']).'</div></div>';
    echo '<div class="sbox"><div class="sbox-label">Total Present</div><div class="sbox-val" style="color:#155724">'.$tot['present'].'</div></div>';
    echo '<div class="sbox"><div class="sbox-label">Total Absent</div><div class="sbox-val" style="color:#721c24">'.$tot['absent'].'</div></div>';
    echo '<div class="sbox"><div class="sbox-label">Total Late</div><div class="sbox-val" style="color:#856404">'.$tot['late'].'</div></div>';
    echo '<div class="sbox"><div class="sbox-label">Total Excused</div><div class="sbox-val" style="color:#4b2e83">'.$tot['excused'].'</div></div>';
    echo '<div class="sbox"><div class="sbox-label">Academy Attendance</div><div class="sbox-val">'.$tot['rate'].'%</div></div>';
    echo '</div>';
    $sum=ob_get_clean();

    ob_start();
    echo '<table><thead><tr><th>Full Name</th><th>Admission #</th><th>Class</th><th>Guardian</th><th>Zone</th>';
    foreach($mx['days'] as $d){ echo '<th>'.h(date('d M',strtotime($d))).'</th>'; }
    echo '<th>Present</th><th>Absent</th><th>Late</th><th>Excused</th><th>Attendance %</th></tr></thead><tbody>';
    if(empty($mx['rows'])) echo '<tr><td colspan="'.(10+count($mx['days'])).'" style="text-align:center;padding:20px;color:#888">No registered children found</td></tr>';
    foreach($mx['rows'] as $row){
        $m=$row['member'];
        echo '<tr>';
        echo '<td><strong>'.h($m['full_name']).'</strong></td>';
        echo '<td>'.h($m['admission_number']).'</td>';
        echo '<td>'.h($m['class_name']).'</td>';
        echo '<td>'.h($m['guardian_name']).'</td>';
        echo '<td><span class="badge b-zone">'.h($m['zone_name']).'</span></td>';
        foreach($mx['days'] as $d){
            $st=$row['days'][$d];
            $lbl=attendance_status_label($st);
            $cls=$st==='present'?'b-present':($st==='absent'?'b-absent':($st==='late'?'b-late':($st==='excused'?'b-excused':'b-norecord')));
            $short=$st==='no_record'?'—':strtoupper(substr($lbl,0,1));
            echo '<td style="text-align:center"><span class="badge '.$cls.'" title="'.h($lbl).'">'.$short.'</span></td>';
        }
        echo '<td>'.$row['present'].'</td>';
        echo '<td>'.$row['absent'].'</td>';
        echo '<td>'.$row['late'].'</td>';
        echo '<td>'.$row['excused'].'</td>';
        echo '<td><strong>'.$row['rate'].'%</strong></td>';
        echo '</tr>';
    }
    if(!empty($mx['rows'])){
        echo '<tr class="total-row"><td colspan="5">ACADEMY TOTALS</td>';
        foreach($mx['days'] as $d){ echo '<td>—</td>'; }
        echo '<td>'.$tot['present'].'</td><td>'.$tot['absent'].'</td><td>'.$tot['late'].'</td><td>'.$tot['excused'].'</td><td>'.$tot['rate'].'%</td></tr>';
    }
    echo '</tbody></table>';
    echo '<p style="font-size:10px;color:#888;margin-top:-10px">Legend: P = Present · A = Absent · L = Late · E = Excused · — = No Record (no session recorded that day)</p>';
    $tbl=ob_get_clean();
    print_report_page('Attendance Report',date('d M Y',strtotime($start)).' – '.date('d M Y',strtotime($end)),$tbl,$sum);
}

/* ── Non-Payers PDF ── */
if($export_type==='non_payers_pdf'){
    $att_month=$_GET['att_month']??$p;
    $non_payers=non_payers_with_attendance($pdo,$p,$att_month);
    $totRem=array_sum(array_column($non_payers,'remaining'));
    ob_start();
    echo '<div class="summary-boxes">';
    echo '<div class="sbox"><div class="sbox-label">Non-Payers</div><div class="sbox-val" style="color:#721c24">'.count($non_payers).'</div></div>';
    echo '<div class="sbox"><div class="sbox-label">Total Remaining</div><div class="sbox-val" style="font-size:14px;color:#856404">'.money($totRem).'</div></div>';
    echo '</div>';
    $sum=ob_get_clean();
    ob_start();
    echo '<table><thead><tr><th>#</th><th>Athlete</th><th>Zone</th><th>Phone</th><th>Guardian</th><th>Expected</th><th>Paid</th><th>Remaining</th><th>Sessions</th></tr></thead><tbody>';
    if(empty($non_payers)) echo '<tr><td colspan="9" style="text-align:center;padding:20px;color:#888">No non-payers found for this period.</td></tr>';
    foreach($non_payers as $i=>$np){
        echo '<tr>';
        echo '<td>'.($i+1).'</td>';
        echo '<td><strong>'.h($np['full_name']).'</strong></td>';
        echo '<td><span class="badge b-zone">'.h($np['zone_name']).'</span></td>';
        echo '<td>'.h($np['phone']).'</td>';
        echo '<td>'.h($np['guardian_name']).'<br><small style="color:#666">'.h($np['guardian_phone']).'</small></td>';
        echo '<td>'.money($np['expected_amount']).'</td>';
        echo '<td>'.money($np['paid_amount']).'</td>';
        echo '<td>'.money($np['remaining']).'</td>';
        echo '<td>'.$np['sessions_attended'].'</td>';
        echo '</tr>';
    }
    if(!empty($non_payers)){
        echo '<tr class="total-row"><td colspan="5">TOTALS</td><td>'.money(array_sum(array_column($non_payers,'expected_amount'))).'</td><td>'.money(array_sum(array_column($non_payers,'paid_amount'))).'</td><td>'.money($totRem).'</td><td>—</td></tr>';
    }
    echo '</tbody></table>';
    $tbl=ob_get_clean();
    print_report_page('Non-Payers Report','Athletes who attend but have not paid fully — Period: '.$p,$tbl,$sum);
}

/* ── Overdue PDF ── */
if($export_type==='overdue_pdf'){
    $overdue=overdue_payments_report($pdo,$p);
    $totRem=array_sum(array_column($overdue,'remaining'));
    ob_start();
    echo '<div class="summary-boxes">';
    echo '<div class="sbox"><div class="sbox-label">Overdue Records</div><div class="sbox-val" style="color:#721c24">'.count($overdue).'</div></div>';
    echo '<div class="sbox"><div class="sbox-label">Total Overdue</div><div class="sbox-val" style="font-size:14px;color:#856404">'.money($totRem).'</div></div>';
    echo '</div>';
    $sum=ob_get_clean();
    ob_start();
    echo '<table><thead><tr><th>#</th><th>Athlete</th><th>Zone</th><th>Phone</th><th>Expected</th><th>Paid</th><th>Remaining</th><th>Due Date</th><th>Days Overdue</th><th>Status</th></tr></thead><tbody>';
    if(empty($overdue)) echo '<tr><td colspan="10" style="text-align:center;padding:20px;color:#888">No overdue payments for this period.</td></tr>';
    foreach($overdue as $i=>$od){
        $stt=bill_status($od['expected_amount'],$od['paid_amount']);
        $bc=$stt==='PARTIAL'?'b-partial':'b-unpaid';
        echo '<tr>';
        echo '<td>'.($i+1).'</td>';
        echo '<td><strong>'.h($od['full_name']).'</strong></td>';
        echo '<td><span class="badge b-zone">'.h($od['zone_name']).'</span></td>';
        echo '<td>'.h($od['phone']).'</td>';
        echo '<td>'.money($od['expected_amount']).'</td>';
        echo '<td>'.money($od['paid_amount']).'</td>';
        echo '<td>'.money($od['remaining']).'</td>';
        echo '<td>'.h($od['due_date']).'</td>';
        echo '<td><span class="badge b-unpaid">'.(int)$od['days_overdue'].' days</span></td>';
        echo '<td><span class="badge '.$bc.'">'.$stt.'</span></td>';
        echo '</tr>';
    }
    if(!empty($overdue)){
        echo '<tr class="total-row"><td colspan="4">TOTALS</td><td>'.money(array_sum(array_column($overdue,'expected_amount'))).'</td><td>'.money(array_sum(array_column($overdue,'paid_amount'))).'</td><td>'.money($totRem).'</td><td colspan="3">—</td></tr>';
    }
    echo '</tbody></table>';
    $tbl=ob_get_clean();
    print_report_page('Overdue Payments Report','Period: '.$p,$tbl,$sum);
}

/* ── Zone Financial PDF ── */
if($export_type==='zone_financial_pdf'){
    $safe_p_q=$pdo->quote($p);
    $zfin=$pdo->query("
        SELECT z.name,
        COALESCE((SELECT SUM(b2.expected_amount) FROM monthly_bills b2 JOIN members m2 ON m2.id=b2.member_id WHERE m2.zone_id=z.id AND b2.period=$safe_p_q),0) expected,
        COALESCE((SELECT SUM(b2.paid_amount) FROM monthly_bills b2 JOIN members m2 ON m2.id=b2.member_id WHERE m2.zone_id=z.id AND b2.period=$safe_p_q),0) paid,
        COALESCE((SELECT SUM(e2.amount) FROM expenses e2 WHERE e2.zone_id=z.id AND TO_CHAR(e2.expense_date,'YYYY-MM')=$safe_p_q),0) expenses,
        COALESCE((SELECT SUM(c2.amount_paid) FROM coach_payroll c2 JOIN staff s2 ON s2.id=c2.staff_id WHERE s2.zone_id=z.id AND c2.period=$safe_p_q),0) payroll
        FROM academy_zones z ORDER BY z.id")->fetchAll();
    $gPaid=array_sum(array_column($zfin,'paid'));
    $gExp=array_sum(array_column($zfin,'expenses'));
    $gPay=array_sum(array_column($zfin,'payroll'));
    ob_start();
    echo '<div class="summary-boxes">';
    echo '<div class="sbox"><div class="sbox-label">Total Collected</div><div class="sbox-val" style="font-size:14px;color:#155724">'.money($gPaid).'</div></div>';
    echo '<div class="sbox"><div class="sbox-label">Total Expenses</div><div class="sbox-val" style="font-size:14px;color:#721c24">'.money($gExp).'</div></div>';
    echo '<div class="sbox"><div class="sbox-label">Total Payroll</div><div class="sbox-val" style="font-size:14px;color:#856404">'.money($gPay).'</div></div>';
    $net=$gPaid-$gExp-$gPay;
    echo '<div class="sbox"><div class="sbox-label">Net Income</div><div class="sbox-val" style="font-size:14px;color:'.($net>=0?'#155724':'#721c24').'">'.money($net).'</div></div>';
    echo '</div>';
    $sum=ob_get_clean();
    ob_start();
    echo '<table><thead><tr><th>Zone</th><th>Expected</th><th>Collected</th><th>Remaining</th><th>Expenses</th><th>Payroll</th><th>Net Income</th></tr></thead><tbody>';
    foreach($zfin as $x){
        $rem=max(0,$x['expected']-$x['paid']);$net=$x['paid']-$x['expenses']-$x['payroll'];
        echo '<tr>';
        echo '<td><strong>'.h($x['name']).'</strong></td>';
        echo '<td>'.money($x['expected']).'</td>';
        echo '<td>'.money($x['paid']).'</td>';
        echo '<td>'.money($rem).'</td>';
        echo '<td>'.money($x['expenses']).'</td>';
        echo '<td>'.money($x['payroll']).'</td>';
        echo '<td style="font-weight:700;color:'.($net>=0?'#155724':'#721c24').'">'.money($net).'</td>';
        echo '</tr>';
    }
    echo '<tr class="total-row"><td>TOTAL</td><td>—</td><td>'.money($gPaid).'</td><td>'.money(array_sum(array_map(fn($x)=>max(0,$x['expected']-$x['paid']),$zfin))).'</td><td>'.money($gExp).'</td><td>'.money($gPay).'</td><td>'.money($gPaid-$gExp-$gPay).'</td></tr>';
    echo '</tbody></table>';
    $tbl=ob_get_clean();
    print_report_page('Zone Financial Report','Period: '.$p,$tbl,$sum);
}

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
.b-excused{background:rgba(167,139,250,0.12);color:var(--purple);border:1px solid rgba(167,139,250,0.25);}
.b-norecord{background:rgba(77,106,138,0.12);color:var(--muted);border:1px solid rgba(77,106,138,0.2);}

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

/* AUTOCOMPLETE */
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
      <div class="form-group"><label>Admission Number</label><input name="admission_number" value="<?= h($edit_member['admission_number']??'') ?>" placeholder="e.g. ADM-0231"></div>
      <div class="form-group"><label>Class</label><input name="class_name" value="<?= h($edit_member['class_name']??'') ?>" placeholder="e.g. P4, Grade 6"></div>
      <div class="form-group"><label>Guardian Name</label><input name="guardian_name" value="<?= h($edit_member['guardian_name']??'') ?>" placeholder="Parent / Guardian"></div>
      <div class="form-group"><label>Guardian Phone</label><input name="guardian_phone" value="<?= h($edit_member['guardian_phone']??'') ?>"></div>
      <div class="form-group"><label>School</label><input name="school_name" value="<?= h($edit_member['school_name']??'') ?>" placeholder="School name"></div>
      <div class="form-group"><label>Monthly Fee (RWF)</label><input type="number" name="monthly_fee" value="<?= h($edit_member['monthly_fee']??0) ?>" placeholder="0"></div>
      <div class="form-group"><label>Due Day</label><input type="number" name="due_day" min="1" max="31" value="<?= h($edit_member['due_day']??5) ?>"></div>
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
    <thead><tr><th>Athlete</th><th>Zone</th><th>Phone</th><th>Position</th><th>Monthly Fee</th><th>Status</th><th>Actions</th></tr></thead>
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
   ATTENDANCE
════════════════════════════════════ */
if($v==='attendance'):
$membersJson=json_encode(array_map(fn($x)=>['id'=>$x['id'],'name'=>$x['full_name'],'zone'=>$x['zone_name'],'phone'=>$x['phone'],'position'=>$x['position']],$am));
$mx_default_start=$p.'-01';
$mx_default_end=date('Y-m-t',strtotime($mx_default_start));
?>
<div class="page-header">
  <div>
    <div class="page-title">Attendance <em>Tracker</em></div>
    <div class="page-sub"><?= count($s) ?> sessions recorded</div>
  </div>
</div>

<div class="card">
  <div class="card-corner"></div>
  <div class="card-header"><div class="card-title"><span class="card-title-bar"></span><?= $edit_session?'Edit Session':'Create Session' ?></div></div>
  <form method="POST">
    <input type="hidden" name="action" value="save_session">
    <input type="hidden" name="id" value="<?= h($edit_session['id']??'') ?>">
    <div class="form-grid">
      <div class="form-group"><label>Session Name *</label><input name="name" required value="<?= h($edit_session['name']??'') ?>" placeholder="e.g. Morning Training"></div>
      <div class="form-group"><label>Date *</label><input type="date" name="session_date" required value="<?= h($edit_session['session_date']??date('Y-m-d')) ?>"></div>
      <div class="form-group"><label>Zone</label><select name="zone_id"><?php foreach($z as $zone): ?><option value="<?= $zone['id'] ?>" <?= (($edit_session['zone_id']??'')==$zone['id'])?'selected':'' ?>><?= h($zone['name']) ?></option><?php endforeach; ?></select></div>
    </div>
    <div class="form-actions">
      <button class="btn btn-primary" type="submit">💾 <?= $edit_session?'Update Session':'Create Session' ?></button>
      <?php if($edit_session): ?><a class="btn btn-ghost" href="?view=attendance&period=<?= h($p) ?>">✕ Cancel</a><?php endif; ?>
    </div>
  </form>
</div>

<div class="card">
  <div class="card-corner"></div>
  <div class="card-header"><div class="card-title"><span class="card-title-bar"></span>Record Attendance</div></div>
  <form method="POST" id="attendanceForm">
    <input type="hidden" name="action" value="attendance">
    <input type="hidden" name="member_id" id="att_member_id" value="">
    <div class="form-grid">
      <div class="form-group">
        <label>Session</label>
        <select name="session_id">
          <?php foreach($s as $ss): ?>
          <option value="<?= $ss['id'] ?>"><?= h($ss['session_date'].' — '.$ss['name'].' ['.$ss['zone_name'].']') ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>Search Athlete</label>
        <div class="autocomplete-wrap" id="attAcWrap">
          <input type="text" id="attAthleteSearch" placeholder="Type athlete name…" autocomplete="off">
          <div class="autocomplete-dropdown" id="attDropdown"></div>
        </div>
        <div class="selected-athlete-info" id="attSelectedInfo">
          <div class="sa-avatar" id="attSelAvatar"></div>
          <div><div class="sa-name" id="attSelName"></div><div class="sa-detail" id="attSelDetail"></div></div>
        </div>
      </div>
      <div class="form-group">
        <label>Status</label>
        <select name="status">
          <option value="present">✓ Present</option>
          <option value="absent">✗ Absent</option>
          <option value="late">◷ Late</option>
          <option value="excused">✎ Excused</option>
        </select>
      </div>
    </div>
    <div class="form-actions">
      <button class="btn btn-primary" type="submit" id="attSubmitBtn" disabled>✓ Save Attendance</button>
      <span style="font-size:12px;color:var(--muted);font-family:var(--font-mono)" id="attHint">Search and select an athlete above</span>
    </div>
  </form>
</div>

<!-- ── Attendance Summary download (period-level) ── -->
<div class="card">
  <div class="card-corner"></div>
  <div class="card-header"><div class="card-title"><span class="card-title-bar"></span>📋 Download Attendance Reports</div></div>
  <div class="report-panel">
    <div class="report-panel-title">Attendance Summary — Period: <?= h($p) ?></div>
    <div class="report-btns">
      <a class="btn btn-ghost btn-sm" href="?view=attendance&period=<?= h($p) ?>&export=attendance_summary_csv&att_month=<?= h($p) ?>">📊 CSV — This Period</a>
      <a class="btn btn-teal btn-sm" href="?view=attendance&period=<?= h($p) ?>&export=attendance_summary_pdf&att_month=<?= h($p) ?>" target="_blank">📄 PDF — This Period</a>
    </div>
  </div>

  <!-- ── Full attendance matrix report: date range, all children, per-day columns ── -->
  <div class="report-panel" style="margin-top:14px">
    <div class="report-panel-title">📅 Complete Attendance Report — Custom Date Range</div>
    <p style="font-size:12px;color:var(--text2);margin-bottom:14px;line-height:1.5">
      Includes every registered child (even those with no attendance yet), one column per day in the
      range you choose, and totals + attendance % per child. Days without a recorded session show as
      <strong>No Record</strong>.
    </p>
    <form id="matrixForm" class="form-grid" onsubmit="return false;">
      <div class="form-group">
        <label>Start Date</label>
        <input type="date" id="mxStart" value="<?= h($mx_default_start) ?>">
      </div>
      <div class="form-group">
        <label>End Date</label>
        <input type="date" id="mxEnd" value="<?= h($mx_default_end) ?>">
      </div>
      <div class="form-group">
        <label>Quick Range</label>
        <select id="mxQuick" onchange="applyQuickRange(this.value)">
          <option value="">Custom (use dates above)</option>
          <option value="this_month">This Month (<?= h($p) ?>)</option>
          <option value="last_7">Last 7 Days</option>
          <option value="last_30">Last 30 Days</option>
        </select>
      </div>
    </form>
    <div class="form-actions" style="margin-top:14px">
      <button type="button" class="btn btn-ghost btn-sm" onclick="downloadMatrix('attendance_matrix_csv')">📊 Download Excel/CSV</button>
      <button type="button" class="btn btn-teal btn-sm" onclick="downloadMatrix('attendance_matrix_pdf')">📄 Download PDF</button>
      <span style="font-size:11px;color:var(--muted);font-family:var(--font-mono)">Opens PDF in a new tab — use "Print / Save as PDF" there to save.</span>
    </div>
  </div>

  <div style="margin-top:14px">
    <div class="report-panel-title" style="font-size:10px;text-transform:uppercase;letter-spacing:.12em;color:var(--muted);font-family:var(--font-mono);margin-bottom:10px">Download Per-Session Attendance Report</div>
    <div id="sessionReportList" style="display:flex;flex-direction:column;gap:8px;">
    <?php foreach($s as $ss): ?>
      <div style="display:flex;align-items:center;justify-content:space-between;background:var(--surface2);border:1px solid var(--border);border-radius:var(--radius-sm);padding:10px 14px;flex-wrap:wrap;gap:8px;">
        <div>
          <strong style="font-family:var(--font-display);font-size:13px"><?= h($ss['name']) ?></strong>
          <span style="font-family:var(--font-mono);font-size:11px;color:var(--muted);margin-left:10px"><?= h($ss['session_date']) ?></span>
          <span class="badge b-zone" style="margin-left:8px"><?= h($ss['zone_name']) ?></span>
        </div>
        <div style="display:flex;gap:6px">
          <a class="btn btn-ghost btn-sm" href="?view=attendance&period=<?= h($p) ?>&export=session_attendance_csv&session_id=<?= $ss['id'] ?>">📊 CSV</a>
          <a class="btn btn-teal btn-sm" href="?view=attendance&period=<?= h($p) ?>&export=session_attendance_pdf&session_id=<?= $ss['id'] ?>" target="_blank">📄 PDF</a>
        </div>
      </div>
    <?php endforeach; ?>
    <?php if(empty($s)): ?>
      <div style="color:var(--muted);font-family:var(--font-mono);font-size:12px;padding:10px">No sessions yet. Create a session first.</div>
    <?php endif; ?>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-corner"></div>
  <div class="card-header"><div class="card-title"><span class="card-title-bar"></span>Sessions</div></div>
  <div class="toolbar">
    <div class="search-box"><span class="search-box-icon">🔍</span><input type="text" id="sessionSearch" placeholder="Search sessions…" oninput="filterTable('sessionSearch','sessionTbl','sessionCnt')"></div>
  </div>
  <div class="result-count" id="sessionCnt"></div>
  <div class="table-wrap">
  <table id="sessionTbl">
    <thead><tr><th>Date</th><th>Session Name</th><th>Zone</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach($s as $ss): ?>
    <tr>
      <td style="font-family:var(--font-mono);color:var(--text2)"><?= h($ss['session_date']) ?></td>
      <td><strong><?= h($ss['name']) ?></strong></td>
      <td><span class="badge b-zone"><?= h($ss['zone_name']) ?></span></td>
      <td>
        <div class="actions-cell">
          <a class="btn btn-ghost btn-sm" href="?view=attendance&period=<?= h($p) ?>&edit_session=<?= $ss['id'] ?>">Edit</a>
          <form method="POST" style="display:inline" onsubmit="return confirm('Delete this session?')">
            <input type="hidden" name="action" value="delete_session">
            <input type="hidden" name="id" value="<?= $ss['id'] ?>">
            <button class="btn btn-danger btn-sm" type="submit">Delete</button>
          </form>
        </div>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>

<script>
function pad2(n){return String(n).padStart(2,'0');}
function toISO(d){return d.getFullYear()+'-'+pad2(d.getMonth()+1)+'-'+pad2(d.getDate());}
function applyQuickRange(val){
  const today=new Date();
  if(val==='this_month'){
    document.getElementById('mxStart').value='<?= h($mx_default_start) ?>';
    document.getElementById('mxEnd').value='<?= h($mx_default_end) ?>';
  }else if(val==='last_7'){
    const start=new Date();start.setDate(today.getDate()-6);
    document.getElementById('mxStart').value=toISO(start);
    document.getElementById('mxEnd').value=toISO(today);
  }else if(val==='last_30'){
    const start=new Date();start.setDate(today.getDate()-29);
    document.getElementById('mxStart').value=toISO(start);
    document.getElementById('mxEnd').value=toISO(today);
  }
}
function downloadMatrix(exportType){
  const start=document.getElementById('mxStart').value;
  const end=document.getElementById('mxEnd').value;
  if(!start||!end){alert('Please choose both a start and end date.');return;}
  if(start>end){alert('Start date must be before end date.');return;}
  const url='?view=attendance&period=<?= h($p) ?>&export='+exportType+'&start_date='+start+'&end_date='+end;
  if(exportType==='attendance_matrix_pdf'){window.open(url,'_blank');}
  else{window.location.href=url;}
}
(function(){
  const members=<?= $membersJson ?>;
  const searchInput=document.getElementById('attAthleteSearch');
  const dropdown=document.getElementById('attDropdown');
  const memberIdInput=document.getElementById('att_member_id');
  const selectedInfo=document.getElementById('attSelectedInfo');
  const selAvatar=document.getElementById('attSelAvatar');
  const selName=document.getElementById('attSelName');
  const selDetail=document.getElementById('attSelDetail');
  const submitBtn=document.getElementById('attSubmitBtn');
  const hint=document.getElementById('attHint');
  let focusedIndex=-1;
  function escH(s){return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}
  function renderDropdown(items){
    dropdown.innerHTML='';
    if(!items.length){dropdown.innerHTML='<div class="ac-empty">No athletes found</div>';}
    else items.slice(0,10).forEach((m)=>{
      const div=document.createElement('div');div.className='ac-item';div.dataset.id=m.id;
      const ini=m.name.split(' ').slice(0,2).map(w=>w[0]).join('').toUpperCase();
      div.innerHTML=`<div class="ac-avatar">${ini}</div><div class="ac-info"><div class="ac-name">${escH(m.name)}</div><div class="ac-meta">${escH(m.zone||'')}${m.position?' · '+escH(m.position):''}</div></div><div class="ac-badge">${escH(m.phone||'')}</div>`;
      div.addEventListener('mousedown',()=>selectAthlete(m));
      dropdown.appendChild(div);
    });
    dropdown.classList.add('open');focusedIndex=-1;
  }
  function selectAthlete(m){
    searchInput.value=m.name;memberIdInput.value=m.id;dropdown.classList.remove('open');
    selectedInfo.classList.add('visible');
    selAvatar.textContent=m.name.split(' ').slice(0,2).map(w=>w[0]).join('').toUpperCase();
    selName.textContent=m.name;selDetail.textContent=(m.zone||'')+(m.position?' · '+m.position:'')+(m.phone?' · '+m.phone:'');
    submitBtn.disabled=false;hint.textContent='Athlete selected — choose status and save';
  }
  searchInput.addEventListener('input',function(){
    const q=this.value.toLowerCase().trim();
    memberIdInput.value='';selectedInfo.classList.remove('visible');submitBtn.disabled=true;hint.textContent='Search and select an athlete above';
    if(q.length<1){dropdown.classList.remove('open');return;}
    renderDropdown(members.filter(m=>m.name.toLowerCase().includes(q)||(m.phone||'').includes(q)||(m.zone||'').toLowerCase().includes(q)));
  });
  searchInput.addEventListener('keydown',function(e){
    const items=dropdown.querySelectorAll('.ac-item');
    if(e.key==='ArrowDown'){e.preventDefault();focusedIndex=Math.min(focusedIndex+1,items.length-1);items.forEach((el,i)=>el.classList.toggle('focused',i===focusedIndex));}
    else if(e.key==='ArrowUp'){e.preventDefault();focusedIndex=Math.max(focusedIndex-1,0);items.forEach((el,i)=>el.classList.toggle('focused',i===focusedIndex));}
    else if(e.key==='Enter'&&focusedIndex>=0){e.preventDefault();const id=parseInt(items[focusedIndex].dataset.id);const m=members.find(x=>x.id===id);if(m)selectAthlete(m);}
    else if(e.key==='Escape'){dropdown.classList.remove('open');}
  });
  document.addEventListener('click',function(e){if(!document.getElementById('attAcWrap').contains(e.target))dropdown.classList.remove('open');});
  document.getElementById('attendanceForm').addEventListener('submit',function(e){if(!memberIdInput.value){e.preventDefault();alert('Please select an athlete first.');}});
})();
</script>
<?php endif; ?>


<?php /* ════════════════════════════════════
   PAYMENTS / BILLING
════════════════════════════════════ */
if($v==='payments'):
foreach($am as $mbr) ensure_bill($pdo,$mbr['id'],$p);
$attendedAthletes=athletes_with_attendance($pdo,$p);
$membersJson=json_encode($attendedAthletes);
?>
<div class="page-header">
  <div>
    <div class="page-title">Billing <em>&amp; Payments</em></div>
    <div class="page-sub">Period: <?= h($p) ?> · Athletes who attended sessions</div>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
    <div class="period-nav">
      <a href="?view=payments&period=<?= $prev ?>">← Prev</a>
      <span class="cur"><?= h($p) ?></span>
      <a href="?view=payments&period=<?= $next ?>">Next →</a>
    </div>
    <a class="btn btn-ghost btn-sm" href="?view=payments&period=<?= h($p) ?>&export=payments_csv">📊 CSV</a>
    <a class="btn btn-teal btn-sm" href="?view=payments&period=<?= h($p) ?>&export=payments_pdf" target="_blank">📄 PDF</a>
    <a class="btn btn-ghost btn-sm" href="?view=payments&period=<?= h($p) ?>&export=payment_logs_csv">📋 Logs CSV</a>
    <a class="btn btn-teal btn-sm" href="?view=payments&period=<?= h($p) ?>&export=payment_logs_pdf" target="_blank">📋 Logs PDF</a>
  </div>
</div>

<!-- ── RECORD NEW PAYMENT ── -->
<div class="card">
  <div class="card-corner"></div>
  <div class="card-header"><div class="card-title"><span class="card-title-bar"></span>Record Payment</div></div>
  <form method="POST" id="paymentForm">
    <input type="hidden" name="action" value="payment">
    <input type="hidden" name="member_id" id="pay_member_id" value="">
    <div class="form-grid">
      <div class="form-group">
        <label>Search Athlete *</label>
        <div class="autocomplete-wrap" id="payAcWrap">
          <input type="text" id="payAthleteSearch" placeholder="Type athlete name…" autocomplete="off" required>
          <div class="autocomplete-dropdown" id="payDropdown"></div>
        </div>
        <div class="selected-athlete-info" id="paySelectedInfo">
          <div class="sa-avatar" id="paySelAvatar"></div>
          <div><div class="sa-name" id="paySelName"></div><div class="sa-detail" id="paySelDetail"></div></div>
        </div>
      </div>
      <div class="form-group"><label>Amount (RWF) *</label><input type="number" name="amount" id="payAmount" required min="1" placeholder="0"></div>
      <div class="form-group"><label>Period</label><input name="period" value="<?= h($p) ?>" pattern="\d{4}-\d{2}" title="YYYY-MM"></div>
      <div class="form-group"><label>Note / Receipt #</label><input name="note" placeholder="Optional reference"></div>
    </div>
    <div class="form-actions">
      <button class="btn btn-primary" type="submit" id="paySubmitBtn" disabled>💳 Record Payment</button>
      <span style="font-size:12px;color:var(--muted);font-family:var(--font-mono)" id="payHint">Search and select an athlete above</span>
    </div>
  </form>
</div>

<!-- ── EDIT PAYMENT MODAL ── -->
<div class="modal-overlay hidden" id="editPayModal">
  <div class="modal-box">
    <button class="modal-close" onclick="document.getElementById('editPayModal').classList.add('hidden')">✕</button>
    <div class="modal-title">✏️ Edit Payment Record</div>
    <form method="POST">
      <input type="hidden" name="action" value="edit_payment">
      <input type="hidden" name="bill_id" id="editBillId">
      <div class="form-grid-2">
        <div class="form-group"><label>Athlete</label><input id="editBillAthlete" readonly style="opacity:0.6"></div>
        <div class="form-group"><label>Period</label><input id="editBillPeriod" readonly style="opacity:0.6"></div>
        <div class="form-group"><label>Expected Amount (RWF)</label><input id="editBillExpected" readonly style="opacity:0.6"></div>
        <div class="form-group"><label>Paid Amount (RWF) *</label><input type="number" name="paid_amount" id="editBillPaid" min="0" step="0.01" required></div>
      </div>
      <div class="form-group" style="margin-top:14px"><label>Note</label><input name="note" id="editBillNote" placeholder="Optional note"></div>
      <div class="form-actions">
        <button class="btn btn-primary" type="submit">💾 Save Changes</button>
        <button type="button" class="btn btn-ghost" onclick="document.getElementById('editPayModal').classList.add('hidden')">Cancel</button>
      </div>
    </form>
  </div>
</div>

<!-- ── CLEAR PAYMENT MODAL ── -->
<div class="modal-overlay hidden" id="clearPayModal">
  <div class="modal-box">
    <button class="modal-close" onclick="document.getElementById('clearPayModal').classList.add('hidden')">✕</button>
    <div class="modal-title" style="color:var(--red)">⚠️ Clear Payment</div>
    <p style="color:var(--text2);margin-bottom:20px;font-size:14px">This will reset the paid amount to 0 for <strong id="clearBillName"></strong> (period: <span id="clearBillPeriod"></span>). This cannot be undone easily.</p>
    <form method="POST">
      <input type="hidden" name="action" value="delete_payment">
      <input type="hidden" name="bill_id" id="clearBillId">
      <div class="form-actions">
        <button class="btn btn-danger" type="submit">🗑 Yes, Clear Payment</button>
        <button type="button" class="btn btn-ghost" onclick="document.getElementById('clearPayModal').classList.add('hidden')">Cancel</button>
      </div>
    </form>
  </div>
</div>

<!-- ── ATTENDED ATHLETES TABLE ── -->
<div class="card">
  <div class="card-corner"></div>
  <div class="card-header">
    <div class="card-title"><span class="card-title-bar"></span>Attended Athletes — <?= h($p) ?></div>
    <span class="badge b-present"><?= count($attendedAthletes) ?> athletes</span>
  </div>
  <div class="toolbar">
    <div class="search-box"><span class="search-box-icon">🔍</span><input type="text" id="attendedPaySearch" placeholder="Search athlete, zone, status…" oninput="filterTable('attendedPaySearch','attendedPayTbl','attendedPayCnt')"></div>
    <select id="apStatusF" onchange="filterTable('attendedPaySearch','attendedPayTbl','attendedPayCnt')">
      <option value="">All Status</option><option value="PAID">Paid</option><option value="PARTIAL">Partial</option><option value="UNPAID">Unpaid</option>
    </select>
    <select id="apZoneF" onchange="filterTable('attendedPaySearch','attendedPayTbl','attendedPayCnt')">
      <option value="">All Zones</option>
      <?php foreach($z as $zone): ?><option value="<?= h($zone['name']) ?>"><?= h($zone['name']) ?></option><?php endforeach; ?>
    </select>
  </div>
  <div class="result-count" id="attendedPayCnt"></div>
  <div class="table-wrap">
  <table id="attendedPayTbl">
    <thead><tr><th>Athlete</th><th>Zone</th><th>Phone</th><th>Sessions</th><th>Expected</th><th>Paid</th><th>Remaining</th><th>Status</th><th>Actions</th></tr></thead>
    <tbody>
    <?php if(empty($attendedAthletes)): ?>
    <tr><td colspan="9" class="no-data">No athletes attended sessions this period. Mark attendance first.</td></tr>
    <?php endif; ?>
    <?php foreach($attendedAthletes as $att):
      $stt=bill_status($att['expected_amount'],$att['paid_amount']);
      $billRow=$pdo->prepare("SELECT id FROM monthly_bills WHERE member_id=? AND period=?");
      $billRow->execute([$att['id'],$p]);$billRow=$billRow->fetch();$bill_id=$billRow['id']??null;
    ?>
    <tr>
      <td>
        <div style="display:flex;align-items:center;gap:10px">
          <div style="width:32px;height:32px;border-radius:10px;background:var(--lime-dim);display:flex;align-items:center;justify-content:center;font-weight:700;color:var(--lime)"><?= mb_substr($att['full_name'],0,1) ?></div>
          <strong><?= h($att['full_name']) ?></strong>
        </div>
      </td>
      <td><span class="badge b-zone"><?= h($att['zone_name']) ?></span></td>
      <td style="font-family:var(--font-mono);font-size:12px"><?= h($att['phone']) ?></td>
      <td><span class="badge b-present"><?= $att['sessions_attended'] ?></span></td>
      <td style="font-family:var(--font-mono);color:var(--text2)"><?= money($att['expected_amount']) ?></td>
      <td style="font-family:var(--font-mono);color:var(--lime)"><?= money($att['paid_amount']) ?></td>
      <td style="font-family:var(--font-mono);color:<?= $att['remaining']>0?'var(--amber)':'var(--muted)' ?>"><?= money($att['remaining']) ?></td>
      <td><span class="badge <?= $stt==='PAID'?'b-paid':($stt==='PARTIAL'?'b-partial':'b-unpaid') ?>"><?= $stt ?></span></td>
      <td>
        <div class="actions-cell">
          <button class="btn btn-primary btn-sm" onclick="selectAthleteForPayment(<?= h(json_encode($att)) ?>)">💰 Pay</button>
          <?php if($bill_id): ?>
          <button class="btn btn-warning btn-sm" onclick="openEditModal(<?= $bill_id ?>,'<?= h(addslashes($att['full_name'])) ?>','<?= h($p) ?>',<?= (float)$att['expected_amount'] ?>,<?= (float)$att['paid_amount'] ?>,'')">✏️ Edit</button>
          <button class="btn btn-danger btn-sm" onclick="openClearModal(<?= $bill_id ?>,'<?= h(addslashes($att['full_name'])) ?>','<?= h($p) ?>')">🗑</button>
          <?php endif; ?>
        </div>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>

<!-- ── FULL BILLING LIST ── -->
<div class="card">
  <div class="card-corner"></div>
  <div class="card-header">
    <div class="card-title"><span class="card-title-bar"></span>Full Billing List — <?= h($p) ?></div>
  </div>
  <div class="toolbar">
    <div class="search-box"><span class="search-box-icon">🔍</span><input type="text" id="billSearch" placeholder="Search athlete, zone, status…" oninput="filterTable('billSearch','billTbl','billCnt')"></div>
    <select id="bStatusF" onchange="filterTable('billSearch','billTbl','billCnt')">
      <option value="">All Status</option><option value="PAID">Paid</option><option value="PARTIAL">Partial</option><option value="UNPAID">Unpaid</option><option value="NO BILL">No Bill</option>
    </select>
  </div>
  <div class="result-count" id="billCnt"></div>
  <div class="table-wrap">
  <table id="billTbl">
    <thead><tr><th>Athlete</th><th>Zone</th><th>Expected</th><th>Paid</th><th>Remaining</th><th>Due Date</th><th>Status</th><th>Actions</th></tr></thead>
    <tbody>
    <?php
    $billRows=$pdo->prepare("SELECT m.full_name,m.phone,z.name zone_name,b.*,GREATEST(b.expected_amount-b.paid_amount,0) remaining FROM monthly_bills b JOIN members m ON m.id=b.member_id LEFT JOIN academy_zones z ON z.id=m.zone_id WHERE b.period=? ORDER BY z.id,m.full_name");
    $billRows->execute([$p]);$billRows=$billRows->fetchAll();
    $totExp2=0;$totPaid2=0;$totRem2=0;
    foreach($billRows as $br):
      $stt=bill_status($br['expected_amount'],$br['paid_amount']);
      $totExp2+=$br['expected_amount'];$totPaid2+=$br['paid_amount'];$totRem2+=$br['remaining'];
    ?>
    <tr>
      <td><strong><?= h($br['full_name']) ?></strong></td>
      <td><span class="badge b-zone"><?= h($br['zone_name']) ?></span></td>
      <td style="font-family:var(--font-mono);color:var(--text2)"><?= money($br['expected_amount']) ?></td>
      <td style="font-family:var(--font-mono);color:var(--lime)"><?= money($br['paid_amount']) ?></td>
      <td style="font-family:var(--font-mono);color:<?= $br['remaining']>0?'var(--amber)':'var(--muted)' ?>"><?= money($br['remaining']) ?></td>
      <td style="font-family:var(--font-mono);font-size:12px;color:var(--text2)"><?= h($br['due_date']) ?></td>
      <td><span class="badge <?= $stt==='PAID'?'b-paid':($stt==='PARTIAL'?'b-partial':($stt==='NO BILL'?'b-nobill':'b-unpaid')) ?>"><?= $stt ?></span></td>
      <td>
        <div class="actions-cell">
          <button class="btn btn-warning btn-sm" onclick="openEditModal(<?= $br['id'] ?>,'<?= h(addslashes($br['full_name'])) ?>','<?= h($br['period']) ?>',<?= (float)$br['expected_amount'] ?>,<?= (float)$br['paid_amount'] ?>,'<?= h(addslashes($br['note']??'')) ?>')">✏️ Edit</button>
          <button class="btn btn-danger btn-sm" onclick="openClearModal(<?= $br['id'] ?>,'<?= h(addslashes($br['full_name'])) ?>','<?= h($br['period']) ?>')">🗑 Clear</button>
        </div>
      </td>
    </tr>
    <?php endforeach; ?>
    <?php if(!empty($billRows)): ?>
    <tr class="summary-row">
      <td colspan="2">TOTALS</td>
      <td><?= money($totExp2) ?></td>
      <td><?= money($totPaid2) ?></td>
      <td><?= money($totRem2) ?></td>
      <td colspan="3">—</td>
    </tr>
    <?php endif; ?>
    <?php if(empty($billRows)): ?><tr><td colspan="8" class="no-data">No billing records for <?= h($p) ?>.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</div>

<script>
function escH(s){return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}
function formatMoney(n){return Number(n).toLocaleString()+' RWF';}
function openEditModal(billId,name,period,expected,paid,note){
  document.getElementById('editBillId').value=billId;
  document.getElementById('editBillAthlete').value=name;
  document.getElementById('editBillPeriod').value=period;
  document.getElementById('editBillExpected').value=formatMoney(expected);
  document.getElementById('editBillPaid').value=paid;
  document.getElementById('editBillNote').value=note;
  document.getElementById('editPayModal').classList.remove('hidden');
}
function openClearModal(billId,name,period){
  document.getElementById('clearBillId').value=billId;
  document.getElementById('clearBillName').textContent=name;
  document.getElementById('clearBillPeriod').textContent=period;
  document.getElementById('clearPayModal').classList.remove('hidden');
}
function selectAthleteForPayment(athlete){
  document.getElementById('payAthleteSearch').value=athlete.full_name;
  document.getElementById('pay_member_id').value=athlete.id;
  document.getElementById('paySelectedInfo').classList.add('visible');
  const ini=athlete.full_name.split(' ').slice(0,2).map(w=>w[0]).join('').toUpperCase();
  document.getElementById('paySelAvatar').textContent=ini;
  document.getElementById('paySelName').textContent=athlete.full_name;
  document.getElementById('paySelDetail').textContent=(athlete.zone_name||'')+' · Fee: '+formatMoney(athlete.expected_amount)+'/month · '+athlete.sessions_attended+' sessions';
  document.getElementById('payAmount').value=athlete.remaining>0?athlete.remaining:athlete.expected_amount;
  document.getElementById('paySubmitBtn').disabled=false;
  document.getElementById('payHint').textContent='Athlete selected — enter amount and save';
  document.getElementById('payDropdown').classList.remove('open');
  document.getElementById('payAthleteSearch').scrollIntoView({behavior:'smooth',block:'center'});
}
(function(){
  const members=<?= $membersJson ?>;
  const searchInput=document.getElementById('payAthleteSearch');
  const dropdown=document.getElementById('payDropdown');
  const memberIdInput=document.getElementById('pay_member_id');
  const selectedInfo=document.getElementById('paySelectedInfo');
  const selAvatar=document.getElementById('paySelAvatar');
  const selName=document.getElementById('paySelName');
  const selDetail=document.getElementById('paySelDetail');
  const submitBtn=document.getElementById('paySubmitBtn');
  const hint=document.getElementById('payHint');
  const amountInput=document.getElementById('payAmount');
  let focusedIndex=-1;
  function renderDropdown(items){
    dropdown.innerHTML='';
    if(!items.length){dropdown.innerHTML='<div class="ac-empty">No athletes found</div>';}
    else items.slice(0,10).forEach((m)=>{
      const div=document.createElement('div');div.className='ac-item';div.dataset.id=m.id;
      const ini=m.full_name.split(' ').slice(0,2).map(w=>w[0]).join('').toUpperCase();
      div.innerHTML=`<div class="ac-avatar">${ini}</div><div class="ac-info"><div class="ac-name">${escH(m.full_name)}</div><div class="ac-meta">${escH(m.zone_name||'')} · ${m.sessions_attended} sessions</div></div><div class="ac-badge">${formatMoney(m.expected_amount)}/mo</div>`;
      div.addEventListener('mousedown',()=>{
        memberIdInput.value=m.id;searchInput.value=m.full_name;dropdown.classList.remove('open');
        selectedInfo.classList.add('visible');
        const ini2=m.full_name.split(' ').slice(0,2).map(w=>w[0]).join('').toUpperCase();
        selAvatar.textContent=ini2;selName.textContent=m.full_name;
        selDetail.textContent=(m.zone_name||'')+' · '+m.sessions_attended+' sessions · Fee: '+formatMoney(m.expected_amount)+'/month';
        amountInput.value=m.remaining>0?m.remaining:m.expected_amount;
        submitBtn.disabled=false;hint.textContent='Athlete selected — enter amount and save';
      });
      dropdown.appendChild(div);
    });
    dropdown.classList.add('open');focusedIndex=-1;
  }
  searchInput.addEventListener('input',function(){
    const q=this.value.toLowerCase().trim();
    memberIdInput.value='';selectedInfo.classList.remove('visible');submitBtn.disabled=true;hint.textContent='Search and select an athlete above';
    if(!q){dropdown.classList.remove('open');return;}
    renderDropdown(members.filter(m=>m.full_name.toLowerCase().includes(q)||(m.phone||'').includes(q)||(m.zone_name||'').toLowerCase().includes(q)));
  });
  searchInput.addEventListener('keydown',function(e){
    const items=dropdown.querySelectorAll('.ac-item');
    if(e.key==='ArrowDown'){e.preventDefault();focusedIndex=Math.min(focusedIndex+1,items.length-1);items.forEach((el,i)=>el.classList.toggle('focused',i===focusedIndex));}
    else if(e.key==='ArrowUp'){e.preventDefault();focusedIndex=Math.max(focusedIndex-1,0);items.forEach((el,i)=>el.classList.toggle('focused',i===focusedIndex));}
    else if(e.key==='Enter'&&focusedIndex>=0){e.preventDefault();items[focusedIndex].dispatchEvent(new Event('mousedown'));}
    else if(e.key==='Escape'){dropdown.classList.remove('open');}
  });
  document.addEventListener('click',function(e){if(!document.getElementById('payAcWrap').contains(e.target))dropdown.classList.remove('open');});
  document.getElementById('paymentForm').addEventListener('submit',function(e){if(!memberIdInput.value){e.preventDefault();alert('Please select an athlete first.');}});
})();
['editPayModal','clearPayModal'].forEach(id=>{
  document.getElementById(id).addEventListener('click',function(e){if(e.target===this)this.classList.add('hidden');});
});
</script>
<?php endif; ?>


<?php /* ════════════════════════════════════
   STAFF
════════════════════════════════════ */
if($v==='staff'): ?>
<div class="page-header">
  <div>
    <div class="page-title">Staff <em>Management</em></div>
    <div class="page-sub"><?= count($st) ?> total staff</div>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <a class="btn btn-ghost btn-sm" href="?view=staff&period=<?= h($p) ?>&export=staff_csv">📊 CSV</a>
    <a class="btn btn-teal btn-sm" href="?view=staff&period=<?= h($p) ?>&export=staff_pdf" target="_blank">📄 PDF Report</a>
  </div>
</div>
<div class="card">
  <div class="card-corner"></div>
  <div class="card-header"><div class="card-title"><span class="card-title-bar"></span><?= $edit_staff?'Edit Staff Member':'Add Staff Member' ?></div></div>
  <form method="POST">
    <input type="hidden" name="action" value="save_staff">
    <input type="hidden" name="id" value="<?= h($edit_staff['id']??'') ?>">
    <div class="form-grid">
      <div class="form-group"><label>Full Name *</label><input name="full_name" required value="<?= h($edit_staff['full_name']??'') ?>"></div>
      <div class="form-group"><label>Phone</label><input name="phone" value="<?= h($edit_staff['phone']??'') ?>"></div>
      <div class="form-group"><label>Role</label>
        <select name="role">
          <?php foreach(['coach','assistant_coach','manager','accountant'] as $role): ?>
          <option <?= (($edit_staff['role']??'')===$role)?'selected':'' ?>><?= $role ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group"><label>Zone</label>
        <select name="zone_id">
          <?php foreach($z as $zone): ?><option value="<?= $zone['id'] ?>" <?= (($edit_staff['zone_id']??'')==$zone['id'])?'selected':'' ?>><?= h($zone['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="form-group"><label>Monthly Salary (RWF)</label><input type="number" name="monthly_salary" value="<?= h($edit_staff['monthly_salary']??0) ?>"></div>
    </div>
    <div class="form-actions">
      <button class="btn btn-primary" type="submit">💾 <?= $edit_staff?'Update Staff':'Save Staff' ?></button>
      <?php if($edit_staff): ?><a class="btn btn-ghost" href="?view=staff&period=<?= h($p) ?>">✕ Cancel</a><?php endif; ?>
    </div>
  </form>
</div>
<div class="card">
  <div class="card-corner"></div>
  <div class="card-header"><div class="card-title"><span class="card-title-bar"></span>Staff Directory</div></div>
  <div class="toolbar">
    <div class="search-box"><span class="search-box-icon">🔍</span><input type="text" id="staffSearch" placeholder="Search name, role, zone…" oninput="filterTable('staffSearch','staffTbl','staffCnt')"></div>
    <select id="stZoneF" onchange="filterTable('staffSearch','staffTbl','staffCnt')"><option value="">All Zones</option><?php foreach($z as $zone): ?><option value="<?= h($zone['name']) ?>"><?= h($zone['name']) ?></option><?php endforeach; ?></select>
  </div>
  <div class="result-count" id="staffCnt"></div>
  <div class="table-wrap">
  <table id="staffTbl">
    <thead><tr><th>Name</th><th>Role</th><th>Zone</th><th>Phone</th><th>Salary</th><th>Status</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach($st as $x): ?>
    <tr>
      <td><strong><?= h($x['full_name']) ?></strong></td>
      <td style="text-transform:capitalize;color:var(--text2)"><?= h($x['role']) ?></td>
      <td><span class="badge b-zone"><?= h($x['zone_name']) ?></span></td>
      <td style="font-family:var(--font-mono);font-size:12px;color:var(--text2)"><?= h($x['phone']) ?></td>
      <td style="font-family:var(--font-mono);color:var(--lime)"><?= money($x['monthly_salary']) ?></td>
      <td><span class="badge <?= $x['is_active']?'b-active':'b-inactive' ?>"><?= $x['is_active']?'Active':'Inactive' ?></span></td>
      <td>
        <div class="actions-cell">
          <a class="btn btn-ghost btn-sm" href="?view=staff&period=<?= h($p) ?>&edit_staff=<?= $x['id'] ?>">Edit</a>
          <form method="POST" style="display:inline" onsubmit="return confirm('Deactivate this staff member?')">
            <input type="hidden" name="action" value="delete_staff">
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
   PAYROLL
════════════════════════════════════ */
if($v==='payroll'): ?>
<div class="page-header">
  <div>
    <div class="page-title">Coach <em>Payroll</em></div>
    <div class="page-sub">Period: <?= h($p) ?></div>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
    <div class="period-nav">
      <a href="?view=payroll&period=<?= $prev ?>">← Prev</a>
      <span class="cur"><?= h($p) ?></span>
      <a href="?view=payroll&period=<?= $next ?>">Next →</a>
    </div>
    <a class="btn btn-ghost btn-sm" href="?view=payroll&period=<?= h($p) ?>&export=payroll_csv">📊 CSV</a>
    <a class="btn btn-teal btn-sm" href="?view=payroll&period=<?= h($p) ?>&export=payroll_pdf" target="_blank">📄 PDF Report</a>
  </div>
</div>
<div class="card">
  <div class="card-corner"></div>
  <div class="card-header"><div class="card-title"><span class="card-title-bar"></span>Add / Update Payroll Entry</div></div>
  <form method="POST">
    <input type="hidden" name="action" value="payroll">
    <div class="form-grid">
      <div class="form-group"><label>Staff Member</label>
        <select name="staff_id">
          <?php foreach($st as $x): ?><option value="<?= $x['id'] ?>"><?= h($x['full_name'].' ['.$x['zone_name'].']') ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="form-group"><label>Period</label><input name="period" value="<?= h($p) ?>" pattern="\d{4}-\d{2}"></div>
      <div class="form-group"><label>Base Salary (RWF)</label><input type="number" name="base_salary" value="0" min="0"></div>
      <div class="form-group"><label>Bonus (RWF)</label><input type="number" name="bonus" value="0" min="0"></div>
      <div class="form-group"><label>Deductions (RWF)</label><input type="number" name="deductions" value="0" min="0"></div>
      <div class="form-group"><label>Amount Paid (RWF)</label><input type="number" name="amount_paid" value="0" min="0"></div>
      <div class="form-group"><label>Note</label><input name="note" placeholder="Optional"></div>
    </div>
    <div class="form-actions"><button class="btn btn-primary" type="submit">💾 Save Payroll</button></div>
  </form>
</div>
<div class="card">
  <div class="card-corner"></div>
  <div class="card-header"><div class="card-title"><span class="card-title-bar"></span>Payroll Records — <?= h($p) ?></div></div>
  <div class="toolbar">
    <div class="search-box"><span class="search-box-icon">🔍</span><input type="text" id="payrollSearch" placeholder="Search staff name…" oninput="filterTable('payrollSearch','payrollTbl','payrollCnt')"></div>
  </div>
  <div class="result-count" id="payrollCnt"></div>
  <div class="table-wrap">
  <table id="payrollTbl">
    <thead><tr><th>Staff</th><th>Zone</th><th>Base</th><th>Bonus</th><th>Deductions</th><th>Net Salary</th><th>Paid</th><th>Status</th></tr></thead>
    <tbody>
    <?php
    $safe_p3=$pdo->quote($p);
    $pay=$pdo->query("SELECT c.*,s.full_name,z.name zone_name FROM coach_payroll c JOIN staff s ON s.id=c.staff_id LEFT JOIN academy_zones z ON z.id=s.zone_id WHERE c.period=$safe_p3 ORDER BY z.id,s.full_name")->fetchAll();
    $totBase=$totBonus=$totDed=$totNet=$totPaid=0;
    foreach($pay as $x):
      $totBase+=$x['base_salary'];$totBonus+=$x['bonus'];$totDed+=$x['deductions'];$totNet+=$x['net_salary'];$totPaid+=$x['amount_paid'];
      $ps=$x['payment_status']??'UNPAID';
    ?>
    <tr>
      <td><strong><?= h($x['full_name']) ?></strong></td>
      <td><span class="badge b-zone"><?= h($x['zone_name']) ?></span></td>
      <td style="font-family:var(--font-mono);color:var(--text2)"><?= money($x['base_salary']) ?></td>
      <td style="font-family:var(--font-mono);color:var(--lime)"><?= money($x['bonus']) ?></td>
      <td style="font-family:var(--font-mono);color:var(--red)"><?= money($x['deductions']) ?></td>
      <td style="font-family:var(--font-mono);font-weight:700;color:var(--text)"><?= money($x['net_salary']) ?></td>
      <td style="font-family:var(--font-mono);color:var(--lime)"><?= money($x['amount_paid']) ?></td>
      <td><span class="badge <?= $ps==='PAID'?'b-paid':($ps==='PARTIAL'?'b-partial':'b-unpaid') ?>"><?= h($ps) ?></span></td>
    </tr>
    <?php endforeach; ?>
    <?php if(!empty($pay)): ?>
    <tr class="summary-row"><td colspan="2">TOTALS</td><td><?= money($totBase) ?></td><td><?= money($totBonus) ?></td><td><?= money($totDed) ?></td><td><?= money($totNet) ?></td><td><?= money($totPaid) ?></td><td>—</td></tr>
    <?php endif; ?>
    <?php if(empty($pay)): ?><tr><td colspan="8" class="no-data">No payroll records for <?= h($p) ?></td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endif; ?>


<?php /* ════════════════════════════════════
   EXPENSES
════════════════════════════════════ */
if($v==='expenses'): ?>
<div class="page-header">
  <div>
    <div class="page-title">Expenses <em>Ledger</em></div>
    <div class="page-sub">All periods</div>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
    <div class="period-nav">
      <a href="?view=expenses&period=<?= $prev ?>">← Prev</a>
      <span class="cur"><?= h($p) ?></span>
      <a href="?view=expenses&period=<?= $next ?>">Next →</a>
    </div>
    <a class="btn btn-ghost btn-sm" href="?view=expenses&period=<?= h($p) ?>&export=expenses_csv">📊 CSV</a>
    <a class="btn btn-teal btn-sm" href="?view=expenses&period=<?= h($p) ?>&export=expenses_pdf" target="_blank">📄 PDF Report</a>
  </div>
</div>
<div class="card">
  <div class="card-corner"></div>
  <div class="card-header"><div class="card-title"><span class="card-title-bar"></span>Log New Expense</div></div>
  <form method="POST">
    <input type="hidden" name="action" value="expense">
    <div class="form-grid">
      <div class="form-group"><label>Date</label><input type="date" name="expense_date" value="<?= date('Y-m-d') ?>"></div>
      <div class="form-group"><label>Zone</label><select name="zone_id"><?php foreach($z as $zone): ?><option value="<?= $zone['id'] ?>"><?= h($zone['name']) ?></option><?php endforeach; ?></select></div>
      <div class="form-group"><label>Category</label><input name="category" placeholder="e.g. Equipment, Utility, Travel"></div>
      <div class="form-group"><label>Description *</label><input name="description" required placeholder="What was this expense for?"></div>
      <div class="form-group"><label>Amount (RWF) *</label><input type="number" name="amount" required placeholder="0" min="1"></div>
      <div class="form-group"><label>Paid To</label><input name="paid_to" placeholder="Vendor / person name"></div>
      <div class="form-group"><label>Approved By</label><input name="approved_by" placeholder="Manager / supervisor"></div>
    </div>
    <div class="form-actions"><button class="btn btn-primary" type="submit">💾 Save Expense</button></div>
  </form>
</div>
<div class="card">
  <div class="card-corner"></div>
  <div class="card-header"><div class="card-title"><span class="card-title-bar"></span>Expense Records</div></div>
  <?php $expenses=$pdo->query("SELECT e.*,z.name zone_name FROM expenses e LEFT JOIN academy_zones z ON z.id=e.zone_id ORDER BY e.expense_date DESC,e.id DESC")->fetchAll(); ?>
  <div class="toolbar">
    <div class="search-box"><span class="search-box-icon">🔍</span><input type="text" id="expenseSearch" placeholder="Search description, category, zone…" oninput="filterTable('expenseSearch','expenseTbl','expenseCnt')"></div>
    <select id="eZoneF" onchange="filterTable('expenseSearch','expenseTbl','expenseCnt')"><option value="">All Zones</option><?php foreach($z as $zone): ?><option value="<?= h($zone['name']) ?>"><?= h($zone['name']) ?></option><?php endforeach; ?></select>
  </div>
  <div class="result-count" id="expenseCnt"></div>
  <div class="table-wrap">
  <table id="expenseTbl">
    <thead><tr><th>Date</th><th>Zone</th><th>Category</th><th>Description</th><th>Amount</th><th>Paid To</th><th>Approved</th></tr></thead>
    <tbody>
    <?php $totExp=0; foreach($expenses as $e): $totExp+=$e['amount']; ?>
    <tr>
      <td style="font-family:var(--font-mono);font-size:12px;color:var(--text2)"><?= h($e['expense_date']) ?></td>
      <td><span class="badge b-zone"><?= h($e['zone_name']) ?></span></td>
      <td style="color:var(--muted)"><?= h($e['category']) ?></td>
      <td><?= h($e['description']) ?></td>
      <td style="font-family:var(--font-mono);color:var(--red)"><?= money($e['amount']) ?></td>
      <td style="color:var(--text2)"><?= h($e['paid_to']) ?></td>
      <td style="color:var(--muted)"><?= h($e['approved_by']) ?></td>
    </tr>
    <?php endforeach; ?>
    <?php if(!empty($expenses)): ?>
    <tr class="summary-row"><td colspan="4">TOTAL EXPENSES</td><td><?= money($totExp) ?></td><td colspan="2">—</td></tr>
    <?php endif; ?>
    <?php if(empty($expenses)): ?><tr><td colspan="7" class="no-data">No expenses recorded yet</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endif; ?>


<?php /* ════════════════════════════════════
   UNIFORMS
════════════════════════════════════ */
if($v==='uniforms'):
$uniforms=$pdo->query("SELECT u.*,m.full_name,z.name zone_name FROM athlete_uniforms u JOIN members m ON m.id=u.member_id LEFT JOIN academy_zones z ON z.id=m.zone_id ORDER BY u.jersey_number ASC")->fetchAll();
$totalQty=0;foreach($uniforms as $uu){$totalQty+=(int)$uu['quantity'];}
?>
<div class="page-header">
  <div>
    <div class="page-title"><?= $edit_uniform?'Edit <em>Uniform</em>':'Athlete <em>Uniforms</em>' ?></div>
    <div class="page-sub">Jersey numbers · Sizes · Kit report</div>
  </div>
  <div style="display:flex;gap:10px;flex-wrap:wrap">
    <a class="btn btn-ghost btn-sm" href="?view=uniforms&period=<?= h($p) ?>&export=uniform_excel">📊 CSV Export</a>
    <a class="btn btn-teal btn-sm" href="?view=uniforms&period=<?= h($p) ?>&export=uniform_pdf" target="_blank">📄 PDF Export</a>
  </div>
</div>
<div class="stat-grid">
  <div class="stat-card" data-color="lime"><div class="stat-icon">▤</div><div class="stat-label">Uniform Records</div><div class="stat-value"><?= count($uniforms) ?></div></div>
  <div class="stat-card" data-color="blue"><div class="stat-icon">#</div><div class="stat-label">Total Kits Qty</div><div class="stat-value"><?= $totalQty ?></div></div>
  <div class="stat-card" data-color="amber"><div class="stat-icon">👕</div><div class="stat-label">Active Athletes</div><div class="stat-value"><?= count($am) ?></div></div>
</div>
<div class="card">
  <div class="card-corner"></div>
  <div class="card-header"><div class="card-title"><span class="card-title-bar"></span><?= $edit_uniform?'Edit Uniform Data':'Insert Uniform Data' ?></div></div>
  <form method="POST">
    <input type="hidden" name="action" value="save_uniform">
    <input type="hidden" name="id" value="<?= h($edit_uniform['id']??'') ?>">
    <div class="form-grid">
      <div class="form-group"><label>Athlete *</label><select name="member_id" required><option value="">Select athlete</option><?php foreach($am as $x): ?><option value="<?= $x['id'] ?>" <?= (($edit_uniform['member_id']??'')==$x['id'])?'selected':'' ?>><?= h($x['full_name']) ?> — <?= h($x['zone_name']) ?></option><?php endforeach; ?></select></div>
      <div class="form-group"><label>Jersey Number *</label><input type="number" min="0" name="jersey_number" required value="<?= h($edit_uniform['jersey_number']??'') ?>" placeholder="e.g. 23" onchange="checkJerseyNumber(this)"></div>
      <div class="form-group"><label>Quantity</label><input type="number" min="1" name="quantity" value="<?= h($edit_uniform['quantity']??1) ?>"></div>
      <div class="form-group"><label>Jersey Category *</label><select name="jersey_category" required><?php $jc=$edit_uniform['jersey_category']??''; foreach(['Adult Unisex V-Neck','Youth V-Neck','Women\'s Racerback','Girls Jersey','Reversible Adult','Reversible Women\'s','Reversible Youth'] as $opt): ?><option <?= $jc===$opt?'selected':'' ?>><?= h($opt) ?></option><?php endforeach; ?></select></div>
      <div class="form-group"><label>Jersey Size *</label><input name="jersey_size" required value="<?= h($edit_uniform['jersey_size']??'') ?>" placeholder="ML / YM / WXL"></div>
      <div class="form-group"><label>Jersey Chest (inches)</label><input type="number" step="0.01" name="jersey_chest" value="<?= h($edit_uniform['jersey_chest']??'') ?>"></div>
      <div class="form-group"><label>Jersey Length (inches)</label><input type="number" step="0.01" name="jersey_length" value="<?= h($edit_uniform['jersey_length']??'') ?>"></div>
      <div class="form-group"><label>Shorts Category *</label><select name="shorts_category" required><?php $sc=$edit_uniform['shorts_category']??''; foreach(['Adult Unisex Shorts','Women\'s Shorts','Youth Shorts'] as $opt): ?><option <?= $sc===$opt?'selected':'' ?>><?= h($opt) ?></option><?php endforeach; ?></select></div>
      <div class="form-group"><label>Shorts Size *</label><input name="shorts_size" required value="<?= h($edit_uniform['shorts_size']??'') ?>" placeholder="ML / YM / WXL"></div>
      <div class="form-group"><label>Shorts Waist (inches)</label><input type="number" step="0.01" name="shorts_waist" value="<?= h($edit_uniform['shorts_waist']??'') ?>"></div>
      <div class="form-group"><label>Shorts Inseam (inches)</label><input type="number" step="0.01" name="shorts_inseam" value="<?= h($edit_uniform['shorts_inseam']??'') ?>"></div>
      <div class="form-group"><label>Issued Date</label><input type="date" name="issued_date" value="<?= h($edit_uniform['issued_date']??date('Y-m-d')) ?>"></div>
      <div class="form-group"><label>Note</label><input name="note" value="<?= h($edit_uniform['note']??'') ?>" placeholder="Optional notes"></div>
    </div>
    <div class="form-actions">
      <button class="btn btn-primary" type="submit">💾 <?= $edit_uniform?'Update Uniform':'Save Uniform' ?></button>
      <?php if($edit_uniform): ?><a class="btn btn-ghost" href="?view=uniforms&period=<?= h($p) ?>">Cancel</a><?php endif; ?>
    </div>
  </form>
</div>
<div class="card">
  <div class="card-corner"></div>
  <div class="card-header"><div class="card-title"><span class="card-title-bar"></span>Uniform Report</div></div>
  <div class="toolbar"><div class="search-box"><span class="search-box-icon">🔍</span><input type="text" id="uniformSearch" placeholder="Search athlete, zone, jersey number, size…" oninput="filterTable('uniformSearch','uniformTbl','uniformCnt')"></div></div>
  <div class="result-count" id="uniformCnt"></div>
  <div class="table-wrap">
  <table id="uniformTbl">
    <thead><tr><th>No.</th><th>Athlete</th><th>Zone</th><th>Jersey Category</th><th>Jersey Size</th><th>Chest</th><th>Length</th><th>Shorts Cat.</th><th>Shorts Size</th><th>Waist</th><th>Inseam</th><th>Qty</th><th>Date</th><th>Actions</th></tr></thead>
    <tbody>
    <?php if(empty($uniforms)): ?><tr><td colspan="14" class="no-data">No uniform data yet.</td></tr><?php endif; ?>
    <?php foreach($uniforms as $u): ?>
    <tr>
      <td><strong style="font-family:var(--font-display);color:var(--lime)"><?= h($u['jersey_number']) ?></strong></td>
      <td><strong><?= h($u['full_name']) ?></strong></td>
      <td><span class="badge b-zone"><?= h($u['zone_name']) ?></span></td>
      <td><?= h($u['jersey_category']) ?></td>
      <td><?= h($u['jersey_size']) ?></td>
      <td><?= h($u['jersey_chest']) ?></td>
      <td><?= h($u['jersey_length']) ?></td>
      <td><?= h($u['shorts_category']) ?></td>
      <td><?= h($u['shorts_size']) ?></td>
      <td><?= h($u['shorts_waist']) ?></td>
      <td><?= h($u['shorts_inseam']) ?></td>
      <td><?= h($u['quantity']) ?></td>
      <td style="font-family:var(--font-mono);color:var(--text2)"><?= h($u['issued_date']) ?></td>
      <td>
        <div class="actions-cell">
          <a class="btn btn-ghost btn-sm" href="?view=uniforms&period=<?= h($p) ?>&edit_uniform=<?= $u['id'] ?>">Edit</a>
          <form method="POST" style="display:inline" onsubmit="return confirm('Delete this uniform record?')">
            <input type="hidden" name="action" value="delete_uniform">
            <input type="hidden" name="id" value="<?= $u['id'] ?>">
            <button class="btn btn-danger btn-sm" type="submit">Delete</button>
          </form>
        </div>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<script>
function checkJerseyNumber(input){
  const jerseyNum=input.value;
  const currentId=document.querySelector('input[name="id"]').value||'';
  if(!jerseyNum) return;
  fetch(`?check_jersey=${jerseyNum}&current_id=${currentId}`)
    .then(r=>r.json())
    .then(data=>{
      input.style.borderColor=data.exists?'var(--red)':'var(--border2)';
      if(data.exists) alert('Warning: Jersey number '+jerseyNum+' is already assigned!');
    })
    .catch(()=>{});
}
</script>
<?php endif; ?>


<?php /* ════════════════════════════════════
   REPORTS
════════════════════════════════════ */
if($v==='reports'):
$att_month=$_GET['att_month']??$p;
$non_payers=non_payers_with_attendance($pdo,$p,$att_month);
$overdue=overdue_payments_report($pdo,$p);
$att_summary=attendance_summary($pdo,null,$p);
$totalRev=(float)$stats['revenue'];$totalExp=(float)$stats['expenses'];$totalPay=(float)$stats['payroll'];
$netIncome=$totalRev-$totalExp-$totalPay;
$mx_default_start=$p.'-01';
$mx_default_end=date('Y-m-t',strtotime($mx_default_start));
?>
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

<!-- ── REPORT DOWNLOADS HUB ── -->
<div class="card">
  <div class="card-corner"></div>
  <div class="card-header"><div class="card-title"><span class="card-title-bar"></span>📥 Download All Reports</div></div>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:12px">

    <div class="report-panel">
      <div class="report-panel-title">👤 Athletes</div>
      <div class="report-btns">
        <a class="btn btn-ghost btn-sm" href="?view=reports&period=<?= h($p) ?>&export=athletes_csv">📊 CSV</a>
        <a class="btn btn-teal btn-sm" href="?view=reports&period=<?= h($p) ?>&export=athletes_pdf" target="_blank">📄 PDF</a>
      </div>
    </div>

    <div class="report-panel">
      <div class="report-panel-title">💳 Billing — <?= h($p) ?></div>
      <div class="report-btns">
        <a class="btn btn-ghost btn-sm" href="?view=reports&period=<?= h($p) ?>&export=payments_csv">📊 CSV</a>
        <a class="btn btn-teal btn-sm" href="?view=reports&period=<?= h($p) ?>&export=payments_pdf" target="_blank">📄 PDF</a>
      </div>
    </div>

    <div class="report-panel">
      <div class="report-panel-title">📋 Payment Logs</div>
      <div class="report-btns">
        <a class="btn btn-ghost btn-sm" href="?view=reports&period=<?= h($p) ?>&export=payment_logs_csv">📊 CSV</a>
        <a class="btn btn-teal btn-sm" href="?view=reports&period=<?= h($p) ?>&export=payment_logs_pdf" target="_blank">📄 PDF</a>
      </div>
    </div>

    <div class="report-panel">
      <div class="report-panel-title">⚠️ Non-Payers — <?= h($p) ?></div>
      <div class="report-btns">
        <a class="btn btn-ghost btn-sm" href="?view=reports&period=<?= h($p) ?>&export=non_payers&att_month=<?= h($att_month) ?>">📊 CSV</a>
        <a class="btn btn-teal btn-sm" href="?view=reports&period=<?= h($p) ?>&export=non_payers_pdf&att_month=<?= h($att_month) ?>" target="_blank">📄 PDF</a>
      </div>
    </div>

    <div class="report-panel">
      <div class="report-panel-title">⏰ Overdue Payments — <?= h($p) ?></div>
      <div class="report-btns">
        <a class="btn btn-ghost btn-sm" href="?view=reports&period=<?= h($p) ?>&export=overdue">📊 CSV</a>
        <a class="btn btn-teal btn-sm" href="?view=reports&period=<?= h($p) ?>&export=overdue_pdf" target="_blank">📄 PDF</a>
      </div>
    </div>

    <div class="report-panel">
      <div class="report-panel-title">📋 Attendance Summary — <?= h($p) ?></div>
      <div class="report-btns">
        <a class="btn btn-ghost btn-sm" href="?view=reports&period=<?= h($p) ?>&export=attendance_summary_csv&att_month=<?= h($p) ?>">📊 CSV</a>
        <a class="btn btn-teal btn-sm" href="?view=reports&period=<?= h($p) ?>&export=attendance_summary_pdf&att_month=<?= h($p) ?>" target="_blank">📄 PDF</a>
      </div>
    </div>

    <div class="report-panel">
      <div class="report-panel-title">📅 Complete Attendance Report (Date Range)</div>
      <div class="report-btns">
        <a class="btn btn-ghost btn-sm" href="?view=reports&period=<?= h($p) ?>&export=attendance_matrix_csv&start_date=<?= h($mx_default_start) ?>&end_date=<?= h($mx_default_end) ?>">📊 CSV — <?= h($p) ?></a>
        <a class="btn btn-teal btn-sm" href="?view=reports&period=<?= h($p) ?>&export=attendance_matrix_pdf&start_date=<?= h($mx_default_start) ?>&end_date=<?= h($mx_default_end) ?>" target="_blank">📄 PDF — <?= h($p) ?></a>
        <a class="btn btn-ghost btn-sm" href="?view=attendance&period=<?= h($p) ?>">⚙ Pick a Custom Range →</a>
      </div>
    </div>

    <div class="report-panel">
      <div class="report-panel-title">👤 Staff</div>
      <div class="report-btns">
        <a class="btn btn-ghost btn-sm" href="?view=reports&period=<?= h($p) ?>&export=staff_csv">📊 CSV</a>
        <a class="btn btn-teal btn-sm" href="?view=reports&period=<?= h($p) ?>&export=staff_pdf" target="_blank">📄 PDF</a>
      </div>
    </div>

    <div class="report-panel">
      <div class="report-panel-title">▣ Payroll — <?= h($p) ?></div>
      <div class="report-btns">
        <a class="btn btn-ghost btn-sm" href="?view=reports&period=<?= h($p) ?>&export=payroll_csv">📊 CSV</a>
        <a class="btn btn-teal btn-sm" href="?view=reports&period=<?= h($p) ?>&export=payroll_pdf" target="_blank">📄 PDF</a>
      </div>
    </div>

    <div class="report-panel">
      <div class="report-panel-title">◐ Expenses</div>
      <div class="report-btns">
        <a class="btn btn-ghost btn-sm" href="?view=reports&period=<?= h($p) ?>&export=expenses_csv">📊 CSV</a>
        <a class="btn btn-teal btn-sm" href="?view=reports&period=<?= h($p) ?>&export=expenses_pdf" target="_blank">📄 PDF</a>
      </div>
    </div>

    <div class="report-panel">
      <div class="report-panel-title">▤ Uniforms</div>
      <div class="report-btns">
        <a class="btn btn-ghost btn-sm" href="?view=reports&period=<?= h($p) ?>&export=uniform_excel">📊 CSV</a>
        <a class="btn btn-teal btn-sm" href="?view=reports&period=<?= h($p) ?>&export=uniform_pdf" target="_blank">📄 PDF</a>
      </div>
    </div>

    <div class="report-panel">
      <div class="report-panel-title">🏦 Zone Financial — <?= h($p) ?></div>
      <div class="report-btns">
        <a class="btn btn-teal btn-sm" href="?view=reports&period=<?= h($p) ?>&export=zone_financial_pdf" target="_blank">📄 PDF</a>
      </div>
    </div>

  </div>
</div>

<div class="stat-grid">
  <div class="stat-card" data-color="teal"><div class="stat-icon">📥</div><div class="stat-label">Total Revenue</div><div class="stat-value" style="font-size:20px"><?= money($totalRev) ?></div></div>
  <div class="stat-card" data-color="red"><div class="stat-icon">📤</div><div class="stat-label">Total Outgoings</div><div class="stat-value" style="font-size:20px"><?= money($totalExp+$totalPay) ?></div></div>
  <div class="stat-card" data-color="<?= $netIncome>=0?'lime':'red' ?>"><div class="stat-icon"><?= $netIncome>=0?'📈':'📉' ?></div><div class="stat-label">Net Income</div><div class="stat-value" style="font-size:20px"><?= money($netIncome) ?></div></div>
</div>

<!-- Non-Payers -->
<div class="card">
  <div class="card-corner"></div>
  <div class="card-header">
    <div class="card-title"><span class="card-title-bar"></span>⚠️ Non-Payers Who Attend — <?= h($p) ?></div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <a class="btn btn-ghost btn-sm" href="?view=reports&period=<?= h($p) ?>&export=non_payers&att_month=<?= h($att_month) ?>">📊 CSV</a>
      <a class="btn btn-teal btn-sm" href="?view=reports&period=<?= h($p) ?>&export=non_payers_pdf&att_month=<?= h($att_month) ?>" target="_blank">📄 PDF</a>
    </div>
  </div>
  <div class="toolbar">
    <div class="search-box"><span class="search-box-icon">🔍</span><input type="text" id="nonPaySearch" placeholder="Search athlete, zone…" oninput="filterTable('nonPaySearch','nonPayTbl','nonPayCnt')"></div>
    <select onchange="window.location.href='?view=reports&period=<?= h($p) ?>&att_month='+this.value">
      <option value="<?= h($p) ?>" <?= $att_month===$p?'selected':'' ?>>Current (<?= h($p) ?>)</option>
      <option value="<?= $prev ?>" <?= $att_month===$prev?'selected':'' ?>>Previous (<?= $prev ?>)</option>
    </select>
  </div>
  <div class="result-count" id="nonPayCnt"></div>
  <div class="table-wrap">
  <table id="nonPayTbl">
    <thead><tr><th>Athlete</th><th>Zone</th><th>Phone</th><th>Guardian</th><th>Expected</th><th>Paid</th><th>Remaining</th><th>Sessions</th><th>Sessions List</th></tr></thead>
    <tbody>
    <?php foreach($non_payers as $np): ?>
    <tr>
      <td><strong><?= h($np['full_name']) ?></strong></td>
      <td><span class="badge b-zone"><?= h($np['zone_name']) ?></span></td>
      <td style="font-family:var(--font-mono);font-size:12px"><?= h($np['phone']) ?></td>
      <td style="font-size:12px"><?= h($np['guardian_name']) ?><br><small style="color:var(--muted)"><?= h($np['guardian_phone']) ?></small></td>
      <td style="font-family:var(--font-mono);color:var(--amber)"><?= money($np['expected_amount']) ?></td>
      <td style="font-family:var(--font-mono);color:var(--lime)"><?= money($np['paid_amount']) ?></td>
      <td style="font-family:var(--font-mono);color:var(--red)"><?= money($np['remaining']) ?></td>
      <td><span class="badge b-present"><?= $np['sessions_attended'] ?></span></td>
      <td style="max-width:240px;font-size:11px;color:var(--text2);word-break:break-all"><?= h(mb_substr($np['sessions_list']??'',0,120)) ?>…</td>
    </tr>
    <?php endforeach; ?>
    <?php if(empty($non_payers)): ?><tr><td colspan="9" class="no-data">No non-payers attending sessions this period.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</div>

<!-- Overdue -->
<div class="card">
  <div class="card-corner"></div>
  <div class="card-header">
    <div class="card-title"><span class="card-title-bar"></span>⏰ Overdue Payments — <?= h($p) ?></div>
    <div style="display:flex;gap:8px">
      <a class="btn btn-ghost btn-sm" href="?view=reports&period=<?= h($p) ?>&export=overdue">📊 CSV</a>
      <a class="btn btn-teal btn-sm" href="?view=reports&period=<?= h($p) ?>&export=overdue_pdf" target="_blank">📄 PDF</a>
    </div>
  </div>
  <div class="toolbar"><div class="search-box"><span class="search-box-icon">🔍</span><input type="text" id="overdueSearch" placeholder="Search athlete…" oninput="filterTable('overdueSearch','overdueTbl','overdueCnt')"></div></div>
  <div class="result-count" id="overdueCnt"></div>
  <div class="table-wrap">
  <table id="overdueTbl">
    <thead><tr><th>Athlete</th><th>Zone</th><th>Expected</th><th>Paid</th><th>Remaining</th><th>Due Date</th><th>Days Overdue</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach($overdue as $od): $stt=bill_status($od['expected_amount'],$od['paid_amount']); ?>
    <tr>
      <td><strong><?= h($od['full_name']) ?></strong></td>
      <td><span class="badge b-zone"><?= h($od['zone_name']) ?></span></td>
      <td style="font-family:var(--font-mono);color:var(--text2)"><?= money($od['expected_amount']) ?></td>
      <td style="font-family:var(--font-mono);color:var(--lime)"><?= money($od['paid_amount']) ?></td>
      <td style="font-family:var(--font-mono);color:var(--red)"><?= money($od['remaining']) ?></td>
      <td style="font-family:var(--font-mono);font-size:12px"><?= h($od['due_date']) ?></td>
      <td><span class="badge b-unpaid"><?= (int)$od['days_overdue'] ?> days</span></td>
      <td><span class="badge <?= $stt==='PARTIAL'?'b-partial':'b-unpaid' ?>"><?= $stt ?></span></td>
    </tr>
    <?php endforeach; ?>
    <?php if(empty($overdue)): ?><tr><td colspan="8" class="no-data">No overdue payments for this period.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</div>

<!-- Attendance Summary -->
<div class="card">
  <div class="card-corner"></div>
  <div class="card-header">
    <div class="card-title"><span class="card-title-bar"></span>📋 Attendance Summary — <?= h($p) ?></div>
    <div style="display:flex;gap:8px">
      <a class="btn btn-ghost btn-sm" href="?view=reports&period=<?= h($p) ?>&export=attendance_summary_csv&att_month=<?= h($p) ?>">📊 CSV</a>
      <a class="btn btn-teal btn-sm" href="?view=reports&period=<?= h($p) ?>&export=attendance_summary_pdf&att_month=<?= h($p) ?>" target="_blank">📄 PDF</a>
    </div>
  </div>
  <div class="toolbar"><div class="search-box"><span class="search-box-icon">🔍</span><input type="text" id="attSumSearch" placeholder="Search athlete, zone…" oninput="filterTable('attSumSearch','attSumTbl','attSumCnt')"></div></div>
  <div class="result-count" id="attSumCnt"></div>
  <div class="table-wrap">
  <table id="attSumTbl">
    <thead><tr><th>Athlete</th><th>Zone</th><th>Total Sessions</th><th>Present</th><th>Absent</th><th>Late</th><th>Attendance Rate</th></tr></thead>
    <tbody>
    <?php foreach($att_summary as $att): ?>
    <tr>
      <td><strong><?= h($att['full_name']) ?></strong></td>
      <td><span class="badge b-zone"><?= h($att['zone_name']) ?></span></td>
      <td style="font-family:var(--font-mono)"><?= $att['total_sessions'] ?></td>
      <td><span class="badge b-present"><?= $att['present_count'] ?></span></td>
      <td><span class="badge b-absent"><?= $att['absent_count'] ?></span></td>
      <td><span class="badge b-late"><?= $att['late_count'] ?></span></td>
      <td>
        <div style="display:flex;align-items:center;gap:8px">
          <div style="flex:1;height:5px;background:var(--surface2);border-radius:3px;overflow:hidden;min-width:60px">
            <div style="width:<?= min(100,$att['attendance_rate']??0) ?>%;height:100%;background:<?= ($att['attendance_rate']??0)>=80?'var(--lime)':(($att['attendance_rate']??0)>=50?'var(--amber)':'var(--red)') ?>;border-radius:3px"></div>
          </div>
          <span style="font-family:var(--font-mono);font-size:11px;color:<?= ($att['attendance_rate']??0)>=80?'var(--lime)':(($att['attendance_rate']??0)>=50?'var(--amber)':'var(--red)') ?>"><?= $att['attendance_rate']??0 ?>%</span>
        </div>
      </td>
    </tr>
    <?php endforeach; ?>
    <?php if(empty($att_summary)): ?><tr><td colspan="7" class="no-data">No attendance records.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</div>

<!-- Zone Financial -->
<div class="card">
  <div class="card-corner"></div>
  <div class="card-header">
    <div class="card-title"><span class="card-title-bar"></span>Zone Financial Report — <?= h($p) ?></div>
    <a class="btn btn-teal btn-sm" href="?view=reports&period=<?= h($p) ?>&export=zone_financial_pdf" target="_blank">📄 PDF</a>
  </div>
  <div class="table-wrap">
  <table>
    <thead><tr><th>Zone</th><th>Expected</th><th>Collected</th><th>Remaining</th><th>Expenses</th><th>Payroll</th><th>Net</th></tr></thead>
    <tbody>
    <?php
    $safe_p4=$pdo->quote($p);
    $zfin=$pdo->query("
    SELECT z.name,
    COALESCE((SELECT SUM(b2.expected_amount) FROM monthly_bills b2 JOIN members m2 ON m2.id=b2.member_id WHERE m2.zone_id=z.id AND b2.period=$safe_p4),0) expected,
    COALESCE((SELECT SUM(b2.paid_amount) FROM monthly_bills b2 JOIN members m2 ON m2.id=b2.member_id WHERE m2.zone_id=z.id AND b2.period=$safe_p4),0) paid,
    COALESCE((SELECT SUM(e2.amount) FROM expenses e2 WHERE e2.zone_id=z.id AND TO_CHAR(e2.expense_date,'YYYY-MM')=$safe_p4),0) expenses,
    COALESCE((SELECT SUM(c2.amount_paid) FROM coach_payroll c2 JOIN staff s2 ON s2.id=c2.staff_id WHERE s2.zone_id=z.id AND c2.period=$safe_p4),0) payroll
    FROM academy_zones z ORDER BY z.id")->fetchAll();
    $gPaid=0;$gExp=0;$gPay=0;$gRem=0;
    foreach($zfin as $x):
      $rem=max(0,$x['expected']-$x['paid']);$net=$x['paid']-$x['expenses']-$x['payroll'];
      $gPaid+=$x['paid'];$gExp+=$x['expenses'];$gPay+=$x['payroll'];$gRem+=$rem;
    ?>
    <tr>
      <td><strong style="font-family:var(--font-display)"><?= h($x['name']) ?></strong></td>
      <td style="font-family:var(--font-mono);color:var(--text2)"><?= money($x['expected']) ?></td>
      <td style="font-family:var(--font-mono);color:var(--lime)"><?= money($x['paid']) ?></td>
      <td style="font-family:var(--font-mono);color:var(--amber)"><?= money($rem) ?></td>
      <td style="font-family:var(--font-mono);color:var(--red)"><?= money($x['expenses']) ?></td>
      <td style="font-family:var(--font-mono);color:var(--purple)"><?= money($x['payroll']) ?></td>
      <td style="font-family:var(--font-mono);font-weight:700;color:<?= $net>=0?'var(--lime)':'var(--red)' ?>"><?= money($net) ?></td>
    </tr>
    <?php endforeach; ?>
    <tr class="summary-row"><td>TOTAL</td><td>—</td><td><?= money($gPaid) ?></td><td><?= money($gRem) ?></td><td><?= money($gExp) ?></td><td><?= money($gPay) ?></td><td><?= money($gPaid-$gExp-$gPay) ?></td></tr>
    </tbody>
  </table>
  </div>
</div>

<!-- Payment Logs -->
<div class="card">
  <div class="card-corner"></div>
  <div class="card-header">
    <div class="card-title"><span class="card-title-bar"></span>Payment Logs (Latest 200)</div>
    <div style="display:flex;gap:8px">
      <a class="btn btn-ghost btn-sm" href="?view=reports&period=<?= h($p) ?>&export=payment_logs_csv">📊 CSV</a>
      <a class="btn btn-teal btn-sm" href="?view=reports&period=<?= h($p) ?>&export=payment_logs_pdf" target="_blank">📄 PDF</a>
    </div>
  </div>
  <div class="toolbar"><div class="search-box"><span class="search-box-icon">🔍</span><input type="text" id="paylogSearch" placeholder="Search by athlete, period…" oninput="filterTable('paylogSearch','paylogTbl','paylogCnt')"></div></div>
  <div class="result-count" id="paylogCnt"></div>
  <div class="table-wrap">
  <table id="paylogTbl">
    <thead><tr><th>Date</th><th>Athlete</th><th>Period</th><th>Amount</th><th>Note</th></tr></thead>
    <tbody>
    <?php
    $logs=$pdo->query("SELECT pl.*,m.full_name FROM payment_logs pl JOIN members m ON m.id=pl.member_id ORDER BY pl.created_at DESC LIMIT 200")->fetchAll();
    $totLogs=0;
    foreach($logs as $l): $totLogs+=$l['amount_paid']; ?>
    <tr>
      <td style="font-family:var(--font-mono);font-size:12px;color:var(--text2)"><?= h(substr($l['created_at'],0,10)) ?></td>
      <td><strong><?= h($l['full_name']) ?></strong></td>
      <td style="font-family:var(--font-mono);color:var(--text2)"><?= h(trim($l['period'])) ?></td>
      <td style="font-family:var(--font-mono);color:var(--lime)"><?= money($l['amount_paid']) ?></td>
      <td style="color:var(--muted)"><?= h($l['note']) ?></td>
    </tr>
    <?php endforeach; ?>
    <?php if(!empty($logs)): ?>
    <tr class="summary-row"><td colspan="3">TOTAL</td><td><?= money($totLogs) ?></td><td>—</td></tr>
    <?php endif; ?>
    <?php if(empty($logs)): ?><tr><td colspan="5" class="no-data">No payment logs yet.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endif; ?>

</main>

<script>
/* ── Universal table filter — fixed typo in countId ── */
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
    ['sessionSearch','sessionTbl','sessionCnt'],
    ['billSearch','billTbl','billCnt'],
    ['staffSearch','staffTbl','staffCnt'],
    ['payrollSearch','payrollTbl','payrollCnt'],
    ['expenseSearch','expenseTbl','expenseCnt'],
    ['paylogSearch','paylogTbl','paylogCnt'],
    ['attSumSearch','attSumTbl','attSumCnt'],
    ['overdueSearch','overdueTbl','overdueCnt'],
    ['nonPaySearch','nonPayTbl','nonPayCnt'],
    ['attendedPaySearch','attendedPayTbl','attendedPayCnt'],
    ['uniformSearch','uniformTbl','uniformCnt'],
  ].forEach(([s,t,c]) => { if(document.getElementById(t)) filterTable(s,t,c); });
});
</script>
</body>
</html>
