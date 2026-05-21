<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

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
function overdue($due,$status){
    if(in_array($status,['PAID','NO BILL']))return 0;
    $today=new DateTime(date('Y-m-d'));$d=new DateTime($due);
    return $today>$d?$d->diff($today)->days:0;
}

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
ALTER TABLE members DROP COLUMN IF EXISTS province;
ALTER TABLE members DROP COLUMN IF EXISTS district;
ALTER TABLE members DROP COLUMN IF EXISTS sector;
ALTER TABLE members DROP COLUMN IF EXISTS cell;
ALTER TABLE members DROP COLUMN IF EXISTS village;
ALTER TABLE members DROP COLUMN IF EXISTS branch;
UPDATE members SET zone_id=(SELECT id FROM academy_zones WHERE name='Gisenyi' LIMIT 1) WHERE zone_id IS NULL;
CREATE TABLE IF NOT EXISTS sessions(
 id SERIAL PRIMARY KEY,name VARCHAR(255) NOT NULL,session_date DATE NOT NULL DEFAULT CURRENT_DATE,
 zone_id INT REFERENCES academy_zones(id),created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
ALTER TABLE sessions ADD COLUMN IF NOT EXISTS session_date DATE;
ALTER TABLE sessions ADD COLUMN IF NOT EXISTS zone_id INT REFERENCES academy_zones(id);
UPDATE sessions SET session_date = date WHERE session_date IS NULL AND EXISTS(SELECT 1 FROM information_schema.columns WHERE table_name='sessions' AND column_name='date');
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
 amount_paid NUMERIC(12,2) NOT NULL,period CHAR(7) NOT NULL,note TEXT,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS staff(
 id SERIAL PRIMARY KEY,full_name VARCHAR(255) NOT NULL,phone VARCHAR(50),role VARCHAR(50) NOT NULL,
 zone_id INT REFERENCES academy_zones(id),monthly_salary NUMERIC(12,2) DEFAULT 0,
 is_active BOOLEAN DEFAULT TRUE,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
ALTER TABLE staff ADD COLUMN IF NOT EXISTS zone_id INT REFERENCES academy_zones(id);
ALTER TABLE staff DROP COLUMN IF EXISTS branch;
UPDATE staff SET zone_id=(SELECT id FROM academy_zones WHERE name='Gisenyi' LIMIT 1) WHERE zone_id IS NULL;
CREATE TABLE IF NOT EXISTS coach_payroll(
 id SERIAL PRIMARY KEY,staff_id INT REFERENCES staff(id) ON DELETE CASCADE,period CHAR(7) NOT NULL,
 base_salary NUMERIC(12,2) DEFAULT 0,bonus NUMERIC(12,2) DEFAULT 0,deductions NUMERIC(12,2) DEFAULT 0,
 net_salary NUMERIC(12,2) DEFAULT 0,amount_paid NUMERIC(12,2) DEFAULT 0,status VARCHAR(30) DEFAULT 'UNPAID',
 paid_at TIMESTAMP,note TEXT,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,UNIQUE(staff_id,period)
);
ALTER TABLE coach_payroll ADD COLUMN IF NOT EXISTS net_salary NUMERIC(12,2) DEFAULT 0;
CREATE TABLE IF NOT EXISTS expenses(
 id SERIAL PRIMARY KEY,expense_date DATE DEFAULT CURRENT_DATE,category VARCHAR(100),description TEXT NOT NULL,
 amount NUMERIC(12,2) NOT NULL,paid_to VARCHAR(255),approved_by VARCHAR(255),
 zone_id INT REFERENCES academy_zones(id),created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
ALTER TABLE expenses ADD COLUMN IF NOT EXISTS zone_id INT REFERENCES academy_zones(id);
ALTER TABLE expenses DROP COLUMN IF EXISTS branch;
UPDATE expenses SET zone_id=(SELECT id FROM academy_zones WHERE name='Gisenyi' LIMIT 1) WHERE zone_id IS NULL;
");
}
schema($pdo);

function zones($pdo){return $pdo->query("SELECT * FROM academy_zones ORDER BY id")->fetchAll();}
function default_zone($pdo){return $pdo->query("SELECT id FROM academy_zones WHERE is_default=TRUE LIMIT 1")->fetchColumn();}
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
    return $pdo->query("SELECT s.*,z.name zone_name FROM sessions s LEFT JOIN academy_zones z ON z.id=s.zone_id ORDER BY s.session_date DESC,s.id DESC")->fetchAll();
}
function ensure_bill($pdo,$member_id,$period){
    $m=$pdo->prepare("SELECT * FROM members WHERE id=?");$m->execute([$member_id]);$m=$m->fetch();
    if(!$m)return;
    $due=due_date($period,$m['due_day']??5);
    $stmt=$pdo->prepare("INSERT INTO monthly_bills(member_id,period,expected_amount,paid_amount,due_date) VALUES(?,?,?,?,?) ON CONFLICT(member_id,period) DO NOTHING");
    $stmt->execute([$member_id,$period,$m['monthly_fee']??0,0,$due]);
}
function billing_rows($pdo,$period){
    foreach(active_members($pdo) as $m) ensure_bill($pdo,$m['id'],$period);
    $stmt=$pdo->prepare("
    SELECT m.full_name,m.phone,z.name zone_name,b.*,GREATEST(b.expected_amount-b.paid_amount,0) remaining
    FROM monthly_bills b JOIN members m ON m.id=b.member_id LEFT JOIN academy_zones z ON z.id=m.zone_id
    WHERE b.period=? ORDER BY z.id,m.full_name");
    $stmt->execute([$period]);
    return $stmt->fetchAll();
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    $a=$_POST['action']??'';
    if($a==='save_member'){
        $id=$_POST['id']??'';
        $data=[$_POST['full_name'],$_POST['phone']?:null,$_POST['gender']?:null,$_POST['date_of_birth']?:null,
            $_POST['zone_id']?:default_zone($pdo),$_POST['guardian_name']?:null,$_POST['guardian_phone']?:null,
            $_POST['position']?:null,$_POST['school_name']?:null,$_POST['monthly_fee']?:0,$_POST['due_day']?:5,$_POST['notes']?:null];
        if($id){
            $stmt=$pdo->prepare("UPDATE members SET full_name=?,phone=?,gender=?,date_of_birth=?,zone_id=?,guardian_name=?,guardian_phone=?,position=?,school_name=?,monthly_fee=?,due_day=?,notes=? WHERE id=?");
            $stmt->execute([...$data,$id]);go('members','Athlete updated');
        }else{
            $stmt=$pdo->prepare("INSERT INTO members(full_name,phone,gender,date_of_birth,zone_id,guardian_name,guardian_phone,position,school_name,monthly_fee,due_day,notes) VALUES(?,?,?,?,?,?,?,?,?,?,?,?) ON CONFLICT(full_name) DO NOTHING");
            $stmt->execute($data);go('members','Athlete added');
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
        $mid=$_POST['member_id'];$amount=(float)$_POST['amount'];$per=$_POST['period'];
        ensure_bill($pdo,$mid,$per);
        $pdo->prepare("UPDATE monthly_bills SET paid_amount=paid_amount+?,paid_at=NOW(),updated_at=NOW(),note=? WHERE member_id=? AND period=?")->execute([$amount,$_POST['note']?:null,$mid,$per]);
        $pdo->prepare("INSERT INTO payment_logs(member_id,amount_paid,period,note) VALUES(?,?,?,?)")->execute([$mid,$amount,$per,$_POST['note']?:null]);
        go('payments','Payment recorded');
    }
    if($a==='save_staff'){
        $id=$_POST['id']??'';
        if($id){$pdo->prepare("UPDATE staff SET full_name=?,phone=?,role=?,zone_id=?,monthly_salary=? WHERE id=?")->execute([$_POST['full_name'],$_POST['phone']?:null,$_POST['role'],$_POST['zone_id'],$_POST['monthly_salary']?:0,$id]);go('staff','Staff updated');}
        else{$pdo->prepare("INSERT INTO staff(full_name,phone,role,zone_id,monthly_salary) VALUES(?,?,?,?,?)")->execute([$_POST['full_name'],$_POST['phone']?:null,$_POST['role'],$_POST['zone_id']?:default_zone($pdo),$_POST['monthly_salary']?:0]);go('staff','Staff added');}
    }
    if($a==='delete_staff'){$pdo->prepare("UPDATE staff SET is_active=FALSE WHERE id=?")->execute([$_POST['id']]);go('staff','Staff deactivated');}
    if($a==='payroll'){
        $net=(float)$_POST['base_salary']+(float)$_POST['bonus']-(float)$_POST['deductions'];
        $status=((float)$_POST['amount_paid']<=0)?'UNPAID':(((float)$_POST['amount_paid']<$net)?'PARTIAL':'PAID');
        $pdo->prepare("INSERT INTO coach_payroll(staff_id,period,base_salary,bonus,deductions,net_salary,amount_paid,status,paid_at,note) VALUES(?,?,?,?,?,?,?,?,NOW(),?) ON CONFLICT(staff_id,period) DO UPDATE SET base_salary=EXCLUDED.base_salary,bonus=EXCLUDED.bonus,deductions=EXCLUDED.deductions,net_salary=EXCLUDED.net_salary,amount_paid=EXCLUDED.amount_paid,status=EXCLUDED.status,paid_at=NOW(),note=EXCLUDED.note")
        ->execute([$_POST['staff_id'],$_POST['period'],$_POST['base_salary'],$_POST['bonus'],$_POST['deductions'],$net,$_POST['amount_paid'],$status,$_POST['note']?:null]);
        go('payroll','Payroll saved');
    }
    if($a==='expense'){
        $pdo->prepare("INSERT INTO expenses(expense_date,category,description,amount,paid_to,approved_by,zone_id) VALUES(?,?,?,?,?,?,?)")
            ->execute([$_POST['expense_date'],$_POST['category'],$_POST['description'],$_POST['amount'],$_POST['paid_to'],$_POST['approved_by'],$_POST['zone_id']?:default_zone($pdo)]);
        go('expenses','Expense saved');
    }
}

$z=zones($pdo);$m=members($pdo);$am=active_members($pdo);$s=sessions($pdo);$st=staff($pdo);$p=period();$v=view();$msg=$_GET['msg']??'';
$edit_member=null;$edit_staff=null;$edit_session=null;
if(isset($_GET['edit_member'])){$q=$pdo->prepare("SELECT * FROM members WHERE id=?");$q->execute([$_GET['edit_member']]);$edit_member=$q->fetch();}
if(isset($_GET['edit_staff'])){$q=$pdo->prepare("SELECT * FROM staff WHERE id=?");$q->execute([$_GET['edit_staff']]);$edit_staff=$q->fetch();}
if(isset($_GET['edit_session'])){$q=$pdo->prepare("SELECT * FROM sessions WHERE id=?");$q->execute([$_GET['edit_session']]);$edit_session=$q->fetch();}

$stats=$pdo->query("
SELECT
(SELECT COUNT(*) FROM members WHERE is_active=TRUE) athletes,
(SELECT COUNT(*) FROM staff WHERE is_active=TRUE) staff,
(SELECT COALESCE(SUM(paid_amount),0) FROM monthly_bills WHERE period='$p') revenue,
(SELECT COALESCE(SUM(expected_amount-paid_amount),0) FROM monthly_bills WHERE period='$p') outstanding,
(SELECT COALESCE(SUM(amount),0) FROM expenses WHERE TO_CHAR(expense_date,'YYYY-MM')='$p') expenses,
(SELECT COALESCE(SUM(amount_paid),0) FROM coach_payroll WHERE period='$p') payroll
")->fetch();

$nav_items = [
    'dashboard' => ['icon'=>'⬡','label'=>'Dashboard'],
    'members'   => ['icon'=>'◈','label'=>'Athletes'],
    'attendance'=> ['icon'=>'◉','label'=>'Attendance'],
    'payments'  => ['icon'=>'◆','label'=>'Billing'],
    'staff'     => ['icon'=>'◍','label'=>'Staff'],
    'payroll'   => ['icon'=>'▣','label'=>'Payroll'],
    'expenses'  => ['icon'=>'◐','label'=>'Expenses'],
    'reports'   => ['icon'=>'◧','label'=>'Reports'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Academy AMS</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Mono:wght@300;400;500&family=Instrument+Sans:wght@400;500;600&display=swap" rel="stylesheet">
<style>
:root {
  --bg:       #05080f;
  --surface:  #0c1220;
  --surface2: #111c2e;
  --border:   #1a2740;
  --border2:  #243450;
  --accent:   #00e676;
  --accent2:  #00bfa5;
  --accent3:  #64ffda;
  --text:     #e2eaf8;
  --muted:    #5c7299;
  --danger:   #ff4444;
  --warn:     #ffab40;
  --info:     #448aff;
  --paid:     #00e676;
  --radius:   14px;
  --radius-sm:8px;
  --sidebar-w:260px;
  --font-head: 'Syne', sans-serif;
  --font-body: 'Instrument Sans', sans-serif;
  --font-mono: 'DM Mono', monospace;
}

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

body {
  background: var(--bg);
  color: var(--text);
  font-family: var(--font-body);
  font-size: 14px;
  min-height: 100vh;
  display: flex;
}

/* ── SIDEBAR ──────────────────────────────────── */
.sidebar {
  position: fixed;
  top: 0; left: 0;
  width: var(--sidebar-w);
  height: 100vh;
  background: var(--surface);
  border-right: 1px solid var(--border);
  display: flex;
  flex-direction: column;
  padding: 28px 16px;
  overflow-y: auto;
  z-index: 100;
}

.logo {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 0 8px 28px;
  border-bottom: 1px solid var(--border);
  margin-bottom: 20px;
}
.logo-icon {
  width: 36px; height: 36px;
  background: var(--accent);
  border-radius: 10px;
  display: flex; align-items: center; justify-content: center;
  font-size: 18px; color: #000;
  font-weight: 900;
  flex-shrink: 0;
}
.logo-text { font-family: var(--font-head); font-size: 15px; font-weight: 800; letter-spacing: 0.05em; line-height: 1.2; }
.logo-sub { font-size: 10px; color: var(--muted); font-family: var(--font-mono); letter-spacing: 0.1em; text-transform: uppercase; }

.nav-section { font-size: 10px; color: var(--muted); letter-spacing: 0.15em; text-transform: uppercase; font-family: var(--font-mono); padding: 0 8px; margin: 16px 0 6px; }

.nav a {
  display: flex;
  align-items: center;
  gap: 10px;
  color: var(--muted);
  text-decoration: none;
  padding: 10px 12px;
  border-radius: var(--radius-sm);
  margin-bottom: 2px;
  font-size: 13.5px;
  font-weight: 500;
  transition: all 0.18s ease;
  border: 1px solid transparent;
}
.nav a:hover { color: var(--text); background: var(--surface2); }
.nav a.active { color: var(--accent); background: rgba(0,230,118,0.08); border-color: rgba(0,230,118,0.15); }
.nav-icon { font-size: 14px; width: 18px; text-align: center; }

.sidebar-footer {
  margin-top: auto;
  padding-top: 20px;
  border-top: 1px solid var(--border);
}
.period-pill {
  background: var(--surface2);
  border: 1px solid var(--border2);
  border-radius: var(--radius-sm);
  padding: 8px 12px;
  font-family: var(--font-mono);
  font-size: 11px;
  color: var(--muted);
}
.period-pill strong { color: var(--accent); font-size: 13px; display: block; margin-bottom: 3px; }

/* ── MAIN ─────────────────────────────────────── */
.main {
  margin-left: var(--sidebar-w);
  flex: 1;
  padding: 32px 36px;
  max-width: calc(100vw - var(--sidebar-w));
}

/* ── PAGE HEADER ──────────────────────────────── */
.page-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  margin-bottom: 28px;
  flex-wrap: wrap;
  gap: 12px;
}
.page-title {
  font-family: var(--font-head);
  font-size: 28px;
  font-weight: 800;
  letter-spacing: -0.02em;
  line-height: 1;
}
.page-title span { color: var(--accent); }
.page-period {
  font-family: var(--font-mono);
  font-size: 11px;
  color: var(--muted);
  margin-top: 5px;
}

/* ── PERIOD NAV ───────────────────────────────── */
.period-nav {
  display: flex;
  align-items: center;
  gap: 8px;
}
.period-nav a {
  color: var(--muted);
  text-decoration: none;
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
  padding: 6px 12px;
  font-size: 12px;
  transition: all 0.15s;
}
.period-nav a:hover { border-color: var(--accent); color: var(--accent); }
.period-nav .cur {
  font-family: var(--font-mono);
  color: var(--text);
  font-size: 13px;
  padding: 6px 14px;
  background: var(--surface2);
  border: 1px solid var(--border2);
  border-radius: var(--radius-sm);
}

/* ── FLASH MSG ────────────────────────────────── */
.msg {
  background: rgba(0,230,118,0.08);
  border: 1px solid rgba(0,230,118,0.25);
  color: var(--accent3);
  padding: 12px 18px;
  border-radius: var(--radius);
  margin-bottom: 20px;
  font-size: 13px;
  display: flex;
  align-items: center;
  gap: 10px;
}
.msg::before { content: '✓'; font-weight: 900; color: var(--accent); }

/* ── CARDS ────────────────────────────────────── */
.card {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  padding: 24px;
  margin-bottom: 20px;
}
.card-title {
  font-family: var(--font-head);
  font-size: 16px;
  font-weight: 700;
  margin-bottom: 18px;
  display: flex;
  align-items: center;
  gap: 8px;
}
.card-title::before { content: ''; display: block; width: 3px; height: 16px; background: var(--accent); border-radius: 2px; }

/* ── STAT GRID ────────────────────────────────── */
.stat-grid {
  display: grid;
  grid-template-columns: repeat(3,1fr);
  gap: 14px;
  margin-bottom: 20px;
}
.stat-card {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  padding: 20px 22px;
  position: relative;
  overflow: hidden;
  transition: border-color 0.2s;
}
.stat-card:hover { border-color: var(--border2); }
.stat-card::after {
  content: '';
  position: absolute;
  top: 0; right: 0;
  width: 60px; height: 60px;
  background: radial-gradient(circle at top right, rgba(0,230,118,0.06), transparent 70%);
}
.stat-label {
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: 0.12em;
  color: var(--muted);
  font-family: var(--font-mono);
  margin-bottom: 10px;
}
.stat-value {
  font-family: var(--font-head);
  font-size: 26px;
  font-weight: 800;
  color: var(--text);
  line-height: 1;
}
.stat-value.accent { color: var(--accent); }
.stat-value.warn { color: var(--warn); }
.stat-value.danger { color: var(--danger); }

/* ── SEARCH BAR ───────────────────────────────── */
.search-wrap {
  position: relative;
  margin-bottom: 16px;
}
.search-wrap input {
  width: 100%;
  padding: 11px 16px 11px 42px;
  background: var(--surface2);
  border: 1px solid var(--border2);
  border-radius: var(--radius-sm);
  color: var(--text);
  font-family: var(--font-body);
  font-size: 13.5px;
  outline: none;
  transition: border-color 0.2s;
}
.search-wrap input:focus { border-color: var(--accent); }
.search-wrap input::placeholder { color: var(--muted); }
.search-icon {
  position: absolute;
  left: 14px; top: 50%;
  transform: translateY(-50%);
  color: var(--muted);
  font-size: 15px;
  pointer-events: none;
}
.search-count {
  font-size: 11px;
  color: var(--muted);
  font-family: var(--font-mono);
  margin-bottom: 10px;
}

/* ── TABLES ───────────────────────────────────── */
.table-wrap { overflow-x: auto; }
table { width: 100%; border-collapse: collapse; }
th {
  color: var(--muted);
  font-size: 11px;
  font-family: var(--font-mono);
  text-transform: uppercase;
  letter-spacing: 0.1em;
  padding: 10px 14px;
  border-bottom: 1px solid var(--border);
  text-align: left;
  white-space: nowrap;
}
td {
  padding: 12px 14px;
  border-bottom: 1px solid var(--border);
  font-size: 13.5px;
  transition: background 0.15s;
}
tr:last-child td { border-bottom: none; }
tr:hover td { background: rgba(255,255,255,0.02); }
.no-results { text-align: center; color: var(--muted); padding: 40px 0; font-size: 13px; font-family: var(--font-mono); }

/* ── BADGES ───────────────────────────────────── */
.badge {
  display: inline-block;
  padding: 3px 10px;
  border-radius: 999px;
  font-size: 11px;
  font-weight: 600;
  font-family: var(--font-mono);
  white-space: nowrap;
}
.badge-zone { background: rgba(68,138,255,0.15); color: #82b1ff; border: 1px solid rgba(68,138,255,0.2); }
.badge-paid { background: rgba(0,230,118,0.12); color: var(--accent); border: 1px solid rgba(0,230,118,0.2); }
.badge-partial { background: rgba(255,171,64,0.12); color: var(--warn); border: 1px solid rgba(255,171,64,0.2); }
.badge-unpaid { background: rgba(255,68,68,0.12); color: var(--danger); border: 1px solid rgba(255,68,68,0.2); }
.badge-nobill { background: rgba(92,114,153,0.12); color: var(--muted); border: 1px solid rgba(92,114,153,0.2); }
.badge-active { background: rgba(0,230,118,0.1); color: var(--accent); }
.badge-inactive { background: rgba(255,68,68,0.1); color: var(--danger); }
.badge-present { background: rgba(0,230,118,0.1); color: var(--accent); }
.badge-absent { background: rgba(255,68,68,0.1); color: var(--danger); }
.badge-late { background: rgba(255,171,64,0.1); color: var(--warn); }

/* ── FORMS ────────────────────────────────────── */
.form-grid { display: grid; grid-template-columns: repeat(3,1fr); gap: 14px; }
.form-grid-2 { display: grid; grid-template-columns: repeat(2,1fr); gap: 14px; }
.form-group label {
  display: block;
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: 0.1em;
  color: var(--muted);
  font-family: var(--font-mono);
  margin-bottom: 6px;
}
.form-group input, .form-group select, .form-group textarea {
  width: 100%;
  padding: 10px 14px;
  background: var(--surface2);
  border: 1px solid var(--border2);
  border-radius: var(--radius-sm);
  color: var(--text);
  font-family: var(--font-body);
  font-size: 13.5px;
  outline: none;
  transition: border-color 0.2s, box-shadow 0.2s;
  -webkit-appearance: none;
}
.form-group input:focus, .form-group select:focus, .form-group textarea:focus {
  border-color: var(--accent);
  box-shadow: 0 0 0 3px rgba(0,230,118,0.08);
}
.form-group select { cursor: pointer; }
.form-actions { display: flex; gap: 10px; align-items: center; margin-top: 18px; flex-wrap: wrap; }

/* ── BUTTONS ──────────────────────────────────── */
.btn {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 9px 18px;
  border-radius: var(--radius-sm);
  font-family: var(--font-head);
  font-size: 13px;
  font-weight: 700;
  cursor: pointer;
  border: none;
  text-decoration: none;
  transition: all 0.18s ease;
  white-space: nowrap;
  letter-spacing: 0.02em;
}
.btn-primary { background: var(--accent); color: #000; }
.btn-primary:hover { background: var(--accent3); box-shadow: 0 0 20px rgba(0,230,118,0.3); }
.btn-ghost { background: var(--surface2); color: var(--text); border: 1px solid var(--border2); }
.btn-ghost:hover { border-color: var(--border); color: var(--text); }
.btn-danger { background: rgba(255,68,68,0.12); color: var(--danger); border: 1px solid rgba(255,68,68,0.2); }
.btn-danger:hover { background: rgba(255,68,68,0.2); }
.btn-sm { padding: 6px 12px; font-size: 12px; }

.actions-cell { display: flex; gap: 6px; flex-wrap: wrap; }

/* ── ZONE SUMMARY TABLE ───────────────────────── */
.zone-table td:first-child { font-weight: 600; }

/* ── FILTER BAR ───────────────────────────────── */
.filter-bar {
  display: flex;
  gap: 8px;
  margin-bottom: 16px;
  flex-wrap: wrap;
  align-items: center;
}
.filter-bar select, .filter-bar input {
  padding: 8px 12px;
  background: var(--surface2);
  border: 1px solid var(--border2);
  border-radius: var(--radius-sm);
  color: var(--text);
  font-size: 13px;
  outline: none;
  transition: border-color 0.2s;
}
.filter-bar select:focus, .filter-bar input:focus { border-color: var(--accent); }

/* ── OVERDUE CHIP ─────────────────────────────── */
.overdue-chip {
  font-family: var(--font-mono);
  font-size: 11px;
  padding: 2px 8px;
  border-radius: 999px;
}
.overdue-chip.over { background: rgba(255,68,68,0.12); color: var(--danger); }
.overdue-chip.ok { color: var(--muted); }

/* ── MOBILE ───────────────────────────────────── */
@media(max-width:900px){
  .sidebar { position: relative; width: 100%; height: auto; flex-direction: row; flex-wrap: wrap; padding: 12px; }
  .main { margin-left: 0; padding: 18px; max-width: 100vw; }
  .stat-grid { grid-template-columns: repeat(2,1fr); }
  .form-grid { grid-template-columns: repeat(2,1fr); }
}
@media(max-width:600px){
  .stat-grid,.form-grid,.form-grid-2 { grid-template-columns: 1fr; }
}

/* ── SCROLLBAR ────────────────────────────────── */
::-webkit-scrollbar { width: 6px; height: 6px; }
::-webkit-scrollbar-track { background: var(--bg); }
::-webkit-scrollbar-thumb { background: var(--border2); border-radius: 3px; }

/* ── DIVIDER ──────────────────────────────────── */
.divider { height: 1px; background: var(--border); margin: 20px 0; }
</style>
</head>
<body>

<!-- ── SIDEBAR ── -->
<div class="sidebar">
  <div class="logo">
    <div class="logo-icon">A</div>
    <div>
      <div class="logo-text">Academy AMS</div>
      <div class="logo-sub">Management System</div>
    </div>
  </div>
  <div class="nav-section">Navigation</div>
  <div class="nav">
    <?php foreach($nav_items as $key=>$item): ?>
    <a class="<?= $v===$key?'active':'' ?>" href="?view=<?= $key ?>&period=<?= h($p) ?>">
      <span class="nav-icon"><?= $item['icon'] ?></span>
      <?= $item['label'] ?>
    </a>
    <?php endforeach; ?>
  </div>
  <div class="sidebar-footer">
    <div class="period-pill">
      <strong><?= h($p) ?></strong>
      Current Period
    </div>
  </div>
</div>

<!-- ── MAIN ── -->
<div class="main">

<?php if($msg): ?>
<div class="msg"><?= h($msg) ?></div>
<?php endif; ?>

<!-- ── PERIOD NAV (shared) ── -->
<?php
$prev = date('Y-m', strtotime($p.'-01 -1 month'));
$next = date('Y-m', strtotime($p.'-01 +1 month'));
?>

<!-- ════════════════════════════════════════════════════
     DASHBOARD
════════════════════════════════════════════════════ -->
<?php if($v==='dashboard'): ?>
<div class="page-header">
  <div>
    <div class="page-title">Dashboard <span>Overview</span></div>
    <div class="page-period">Period: <?= h($p) ?></div>
  </div>
  <div class="period-nav">
    <a href="?view=dashboard&period=<?= $prev ?>">← Prev</a>
    <span class="cur"><?= h($p) ?></span>
    <a href="?view=dashboard&period=<?= $next ?>">Next →</a>
  </div>
</div>

<div class="stat-grid">
  <div class="stat-card">
    <div class="stat-label">Active Athletes</div>
    <div class="stat-value accent"><?= $stats['athletes'] ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Active Staff</div>
    <div class="stat-value"><?= $stats['staff'] ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Revenue <?= h($p) ?></div>
    <div class="stat-value accent"><?= money($stats['revenue']) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Outstanding</div>
    <div class="stat-value warn"><?= money($stats['outstanding']) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Expenses</div>
    <div class="stat-value danger"><?= money($stats['expenses']) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Payroll Paid</div>
    <div class="stat-value"><?= money($stats['payroll']) ?></div>
  </div>
</div>

<div class="card">
  <div class="card-title">Zone Summary</div>
  <div class="table-wrap">
  <table class="zone-table">
    <tr><th>Zone</th><th>Athletes</th><th>Staff</th><th>Revenue</th><th>Expenses</th></tr>
    <?php
    $rows=$pdo->query("
    SELECT z.name,COUNT(DISTINCT m.id) athletes,COUNT(DISTINCT st.id) staff,
    COALESCE(SUM(DISTINCT b.paid_amount),0) revenue,COALESCE(SUM(DISTINCT e.amount),0) expenses
    FROM academy_zones z
    LEFT JOIN members m ON m.zone_id=z.id AND m.is_active=TRUE
    LEFT JOIN staff st ON st.zone_id=z.id AND st.is_active=TRUE
    LEFT JOIN monthly_bills b ON b.member_id=m.id AND b.period='$p'
    LEFT JOIN expenses e ON e.zone_id=z.id AND TO_CHAR(e.expense_date,'YYYY-MM')='$p'
    GROUP BY z.id,z.name ORDER BY z.id")->fetchAll();
    foreach($rows as $r): ?>
    <tr>
      <td><strong><?= h($r['name']) ?></strong></td>
      <td><?= $r['athletes'] ?></td>
      <td><?= $r['staff'] ?></td>
      <td><span style="color:var(--accent);font-family:var(--font-mono)"><?= money($r['revenue']) ?></span></td>
      <td><span style="color:var(--danger);font-family:var(--font-mono)"><?= money($r['expenses']) ?></span></td>
    </tr>
    <?php endforeach; ?>
  </table>
  </div>
</div>
<?php endif; ?>


<!-- ════════════════════════════════════════════════════
     MEMBERS / ATHLETES
════════════════════════════════════════════════════ -->
<?php if($v==='members'): ?>
<div class="page-header">
  <div>
    <div class="page-title"><?= $edit_member ? 'Edit' : 'Athletes' ?> <span><?= $edit_member ? h($edit_member['full_name']) : 'Registry' ?></span></div>
    <div class="page-period"><?= count($m) ?> total members</div>
  </div>
</div>

<div class="card">
  <div class="card-title"><?= $edit_member ? 'Edit Athlete' : 'Add New Athlete' ?></div>
  <form method="POST">
    <input type="hidden" name="action" value="save_member">
    <input type="hidden" name="id" value="<?= h($edit_member['id']??'') ?>">
    <div class="form-grid">
      <div class="form-group"><label>Full Name *</label><input name="full_name" required value="<?= h($edit_member['full_name']??'') ?>" placeholder="Enter full name"></div>
      <div class="form-group"><label>Phone</label><input name="phone" value="<?= h($edit_member['phone']??'') ?>" placeholder="+250..."></div>
      <div class="form-group"><label>Zone</label><select name="zone_id"><?php foreach($z as $zone): ?><option value="<?= $zone['id'] ?>" <?= (($edit_member['zone_id']??'')==$zone['id'])?'selected':'' ?>><?= h($zone['name']) ?></option><?php endforeach; ?></select></div>
      <div class="form-group"><label>Gender</label><select name="gender"><option value="">— Select —</option><option <?= (($edit_member['gender']??'')==='Male')?'selected':'' ?>>Male</option><option <?= (($edit_member['gender']??'')==='Female')?'selected':'' ?>>Female</option></select></div>
      <div class="form-group"><label>Date of Birth</label><input type="date" name="date_of_birth" value="<?= h($edit_member['date_of_birth']??'') ?>"></div>
      <div class="form-group"><label>Position</label><input name="position" value="<?= h($edit_member['position']??'') ?>" placeholder="e.g. Forward, GK"></div>
      <div class="form-group"><label>Guardian Name</label><input name="guardian_name" value="<?= h($edit_member['guardian_name']??'') ?>"></div>
      <div class="form-group"><label>Guardian Phone</label><input name="guardian_phone" value="<?= h($edit_member['guardian_phone']??'') ?>"></div>
      <div class="form-group"><label>School</label><input name="school_name" value="<?= h($edit_member['school_name']??'') ?>"></div>
      <div class="form-group"><label>Monthly Fee (RWF)</label><input type="number" name="monthly_fee" value="<?= h($edit_member['monthly_fee']??0) ?>"></div>
      <div class="form-group"><label>Due Day</label><input type="number" name="due_day" min="1" max="31" value="<?= h($edit_member['due_day']??5) ?>"></div>
      <div class="form-group"><label>Notes</label><input name="notes" value="<?= h($edit_member['notes']??'') ?>" placeholder="Optional notes"></div>
    </div>
    <div class="form-actions">
      <button class="btn btn-primary" type="submit">💾 <?= $edit_member ? 'Update Athlete' : 'Save Athlete' ?></button>
      <?php if($edit_member): ?><a class="btn btn-ghost" href="?view=members&period=<?= h($p) ?>">Cancel</a><?php endif; ?>
    </div>
  </form>
</div>

<div class="card">
  <div class="card-title">Athletes List</div>
  <div class="filter-bar">
    <div class="search-wrap" style="flex:1;margin-bottom:0">
      <span class="search-icon">🔍</span>
      <input type="text" id="memberSearch" placeholder="Search by name, phone, zone, position, school…" oninput="filterTable('memberSearch','memberTable','memberCount')">
    </div>
    <select id="memberZoneFilter" onchange="filterTable('memberSearch','memberTable','memberCount')">
      <option value="">All Zones</option>
      <?php foreach($z as $zone): ?><option value="<?= h($zone['name']) ?>"><?= h($zone['name']) ?></option><?php endforeach; ?>
    </select>
    <select id="memberStatusFilter" onchange="filterTable('memberSearch','memberTable','memberCount')">
      <option value="">All Status</option>
      <option value="Active">Active</option>
      <option value="Inactive">Inactive</option>
    </select>
  </div>
  <div class="search-count" id="memberCount"></div>
  <div class="table-wrap">
  <table id="memberTable">
    <tr><th>Name</th><th>Zone</th><th>Phone</th><th>Position</th><th>Fee / Month</th><th>Status</th><th>Actions</th></tr>
    <?php foreach($m as $x): ?>
    <tr>
      <td><strong><?= h($x['full_name']) ?></strong></td>
      <td><span class="badge badge-zone"><?= h($x['zone_name']) ?></span></td>
      <td style="font-family:var(--font-mono);font-size:12px"><?= h($x['phone']) ?></td>
      <td><?= h($x['position']) ?></td>
      <td style="font-family:var(--font-mono);color:var(--accent)"><?= money($x['monthly_fee']) ?></td>
      <td><span class="badge <?= $x['is_active']?'badge-active':'badge-inactive' ?>"><?= $x['is_active']?'Active':'Inactive' ?></span></td>
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
  </table>
  </div>
</div>
<?php endif; ?>


<!-- ════════════════════════════════════════════════════
     ATTENDANCE
════════════════════════════════════════════════════ -->
<?php if($v==='attendance'): ?>
<div class="page-header">
  <div><div class="page-title">Attendance <span>Tracker</span></div></div>
</div>

<div class="card">
  <div class="card-title"><?= $edit_session ? 'Edit Session' : 'Create Session' ?></div>
  <form method="POST">
    <input type="hidden" name="action" value="save_session">
    <input type="hidden" name="id" value="<?= h($edit_session['id']??'') ?>">
    <div class="form-grid">
      <div class="form-group"><label>Session Name *</label><input name="name" required value="<?= h($edit_session['name']??'') ?>" placeholder="e.g. Morning Training"></div>
      <div class="form-group"><label>Date *</label><input type="date" name="session_date" required value="<?= h($edit_session['session_date']??date('Y-m-d')) ?>"></div>
      <div class="form-group"><label>Zone</label><select name="zone_id"><?php foreach($z as $zone): ?><option value="<?= $zone['id'] ?>" <?= (($edit_session['zone_id']??'')==$zone['id'])?'selected':'' ?>><?= h($zone['name']) ?></option><?php endforeach; ?></select></div>
    </div>
    <div class="form-actions">
      <button class="btn btn-primary" type="submit">💾 <?= $edit_session ? 'Update Session' : 'Create Session' ?></button>
      <?php if($edit_session): ?><a class="btn btn-ghost" href="?view=attendance&period=<?= h($p) ?>">Cancel</a><?php endif; ?>
    </div>
  </form>
</div>

<div class="card">
  <div class="card-title">Record Attendance</div>
  <form method="POST">
    <input type="hidden" name="action" value="attendance">
    <div class="form-grid">
      <div class="form-group"><label>Session</label><select name="session_id"><?php foreach($s as $ss): ?><option value="<?= $ss['id'] ?>"><?= h($ss['session_date'].' — '.$ss['name'].' ['.$ss['zone_name'].']') ?></option><?php endforeach; ?></select></div>
      <div class="form-group"><label>Athlete</label><select name="member_id"><?php foreach($am as $x): ?><option value="<?= $x['id'] ?>"><?= h($x['full_name'].' ['.$x['zone_name'].']') ?></option><?php endforeach; ?></select></div>
      <div class="form-group"><label>Status</label><select name="status"><option value="present">Present</option><option value="absent">Absent</option><option value="late">Late</option></select></div>
    </div>
    <div class="form-actions"><button class="btn btn-primary" type="submit">✓ Save Attendance</button></div>
  </form>
</div>

<div class="card">
  <div class="card-title">Sessions</div>
  <div class="search-wrap">
    <span class="search-icon">🔍</span>
    <input type="text" id="sessionSearch" placeholder="Search sessions by name, date, zone…" oninput="filterTable('sessionSearch','sessionTable','sessionCount')">
  </div>
  <div class="search-count" id="sessionCount"></div>
  <div class="table-wrap">
  <table id="sessionTable">
    <tr><th>Date</th><th>Session Name</th><th>Zone</th><th>Actions</th></tr>
    <?php foreach($s as $ss): ?>
    <tr>
      <td style="font-family:var(--font-mono);color:var(--muted)"><?= h($ss['session_date']) ?></td>
      <td><strong><?= h($ss['name']) ?></strong></td>
      <td><span class="badge badge-zone"><?= h($ss['zone_name']) ?></span></td>
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
  </table>
  </div>
</div>
<?php endif; ?>


<!-- ════════════════════════════════════════════════════
     PAYMENTS / BILLING
════════════════════════════════════════════════════ -->
<?php if($v==='payments'): ?>
<div class="page-header">
  <div>
    <div class="page-title">Billing <span>&amp; Payments</span></div>
    <div class="page-period">Period: <?= h($p) ?></div>
  </div>
  <div class="period-nav">
    <a href="?view=payments&period=<?= $prev ?>">← Prev</a>
    <span class="cur"><?= h($p) ?></span>
    <a href="?view=payments&period=<?= $next ?>">Next →</a>
  </div>
</div>

<div class="card">
  <div class="card-title">Record Payment</div>
  <form method="POST">
    <input type="hidden" name="action" value="payment">
    <div class="form-grid">
      <div class="form-group"><label>Athlete</label><select name="member_id"><?php foreach($am as $x): ?><option value="<?= $x['id'] ?>"><?= h($x['full_name'].' ['.$x['zone_name'].']') ?></option><?php endforeach; ?></select></div>
      <div class="form-group"><label>Amount (RWF) *</label><input type="number" name="amount" required placeholder="0"></div>
      <div class="form-group"><label>Period</label><input name="period" value="<?= h($p) ?>"></div>
      <div class="form-group"><label>Note</label><input name="note" placeholder="Optional reference"></div>
    </div>
    <div class="form-actions"><button class="btn btn-primary" type="submit">💳 Record Payment</button></div>
  </form>
</div>

<div class="card">
  <div class="card-title">Billing Status — <?= h($p) ?></div>
  <div class="filter-bar">
    <div class="search-wrap" style="flex:1;margin-bottom:0">
      <span class="search-icon">🔍</span>
      <input type="text" id="billSearch" placeholder="Search by athlete name, zone, status…" oninput="filterTable('billSearch','billTable','billCount')">
    </div>
    <select id="billStatusFilter" onchange="filterTable('billSearch','billTable','billCount')">
      <option value="">All Status</option>
      <option value="PAID">Paid</option>
      <option value="PARTIAL">Partial</option>
      <option value="UNPAID">Unpaid</option>
      <option value="NO BILL">No Bill</option>
    </select>
    <select id="billZoneFilter" onchange="filterTable('billSearch','billTable','billCount')">
      <option value="">All Zones</option>
      <?php foreach($z as $zone): ?><option value="<?= h($zone['name']) ?>"><?= h($zone['name']) ?></option><?php endforeach; ?>
    </select>
  </div>
  <div class="search-count" id="billCount"></div>
  <div class="table-wrap">
  <table id="billTable">
    <tr><th>Athlete</th><th>Zone</th><th>Expected</th><th>Paid</th><th>Remaining</th><th>Due Date</th><th>Status</th><th>Overdue</th></tr>
    <?php foreach(billing_rows($pdo,$p) as $b): $stt=bill_status($b['expected_amount'],$b['paid_amount']); $od=overdue($b['due_date'],$stt); ?>
    <tr>
      <td><strong><?= h($b['full_name']) ?></strong></td>
      <td><span class="badge badge-zone"><?= h($b['zone_name']) ?></span></td>
      <td style="font-family:var(--font-mono)"><?= money($b['expected_amount']) ?></td>
      <td style="font-family:var(--font-mono);color:var(--accent)"><?= money($b['paid_amount']) ?></td>
      <td style="font-family:var(--font-mono);color:<?= $b['remaining']>0?'var(--warn)':'var(--muted)' ?>"><?= money($b['remaining']) ?></td>
      <td style="font-family:var(--font-mono);font-size:12px;color:var(--muted)"><?= h($b['due_date']) ?></td>
      <td><span class="badge <?= $stt==='PAID'?'badge-paid':($stt==='PARTIAL'?'badge-partial':($stt==='UNPAID'?'badge-unpaid':'badge-nobill')) ?>"><?= $stt ?></span></td>
      <td><span class="overdue-chip <?= $od>0?'over':'ok' ?>"><?= $od>0?$od.'d':'-' ?></span></td>
    </tr>
    <?php endforeach; ?>
  </table>
  </div>
</div>
<?php endif; ?>


<!-- ════════════════════════════════════════════════════
     STAFF
════════════════════════════════════════════════════ -->
<?php if($v==='staff'): ?>
<div class="page-header">
  <div><div class="page-title">Staff <span>Management</span></div></div>
</div>

<div class="card">
  <div class="card-title"><?= $edit_staff ? 'Edit Staff Member' : 'Add Staff Member' ?></div>
  <form method="POST">
    <input type="hidden" name="action" value="save_staff">
    <input type="hidden" name="id" value="<?= h($edit_staff['id']??'') ?>">
    <div class="form-grid">
      <div class="form-group"><label>Full Name *</label><input name="full_name" required value="<?= h($edit_staff['full_name']??'') ?>"></div>
      <div class="form-group"><label>Phone</label><input name="phone" value="<?= h($edit_staff['phone']??'') ?>"></div>
      <div class="form-group"><label>Role</label><select name="role"><?php foreach(['coach','assistant_coach','manager','accountant'] as $role): ?><option <?= (($edit_staff['role']??'')===$role)?'selected':'' ?>><?= $role ?></option><?php endforeach; ?></select></div>
      <div class="form-group"><label>Zone</label><select name="zone_id"><?php foreach($z as $zone): ?><option value="<?= $zone['id'] ?>" <?= (($edit_staff['zone_id']??'')==$zone['id'])?'selected':'' ?>><?= h($zone['name']) ?></option><?php endforeach; ?></select></div>
      <div class="form-group"><label>Monthly Salary (RWF)</label><input type="number" name="monthly_salary" value="<?= h($edit_staff['monthly_salary']??0) ?>"></div>
    </div>
    <div class="form-actions">
      <button class="btn btn-primary" type="submit">💾 <?= $edit_staff ? 'Update Staff' : 'Save Staff' ?></button>
      <?php if($edit_staff): ?><a class="btn btn-ghost" href="?view=staff&period=<?= h($p) ?>">Cancel</a><?php endif; ?>
    </div>
  </form>
</div>

<div class="card">
  <div class="card-title">Staff List</div>
  <div class="filter-bar">
    <div class="search-wrap" style="flex:1;margin-bottom:0">
      <span class="search-icon">🔍</span>
      <input type="text" id="staffSearch" placeholder="Search by name, role, zone, phone…" oninput="filterTable('staffSearch','staffTable','staffCount')">
    </div>
    <select id="staffRoleFilter" onchange="filterTable('staffSearch','staffTable','staffCount')">
      <option value="">All Roles</option>
      <?php foreach(['coach','assistant_coach','manager','accountant'] as $role): ?>
      <option value="<?= $role ?>"><?= $role ?></option>
      <?php endforeach; ?>
    </select>
    <select id="staffZoneFilter" onchange="filterTable('staffSearch','staffTable','staffCount')">
      <option value="">All Zones</option>
      <?php foreach($z as $zone): ?><option value="<?= h($zone['name']) ?>"><?= h($zone['name']) ?></option><?php endforeach; ?>
    </select>
  </div>
  <div class="search-count" id="staffCount"></div>
  <div class="table-wrap">
  <table id="staffTable">
    <tr><th>Name</th><th>Role</th><th>Zone</th><th>Phone</th><th>Salary</th><th>Status</th><th>Actions</th></tr>
    <?php foreach($st as $x): ?>
    <tr>
      <td><strong><?= h($x['full_name']) ?></strong></td>
      <td style="text-transform:capitalize"><?= h($x['role']) ?></td>
      <td><span class="badge badge-zone"><?= h($x['zone_name']) ?></span></td>
      <td style="font-family:var(--font-mono);font-size:12px"><?= h($x['phone']) ?></td>
      <td style="font-family:var(--font-mono);color:var(--accent)"><?= money($x['monthly_salary']) ?></td>
      <td><span class="badge <?= $x['is_active']?'badge-active':'badge-inactive' ?>"><?= $x['is_active']?'Active':'Inactive' ?></span></td>
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
  </table>
  </div>
</div>
<?php endif; ?>


<!-- ════════════════════════════════════════════════════
     PAYROLL
════════════════════════════════════════════════════ -->
<?php if($v==='payroll'): ?>
<div class="page-header">
  <div>
    <div class="page-title">Coach <span>Payroll</span></div>
    <div class="page-period">Period: <?= h($p) ?></div>
  </div>
  <div class="period-nav">
    <a href="?view=payroll&period=<?= $prev ?>">← Prev</a>
    <span class="cur"><?= h($p) ?></span>
    <a href="?view=payroll&period=<?= $next ?>">Next →</a>
  </div>
</div>

<div class="card">
  <div class="card-title">Add / Update Payroll Entry</div>
  <form method="POST">
    <input type="hidden" name="action" value="payroll">
    <div class="form-grid">
      <div class="form-group"><label>Staff Member</label><select name="staff_id"><?php foreach($st as $x): ?><option value="<?= $x['id'] ?>"><?= h($x['full_name'].' ['.$x['zone_name'].']') ?></option><?php endforeach; ?></select></div>
      <div class="form-group"><label>Period</label><input name="period" value="<?= h($p) ?>"></div>
      <div class="form-group"><label>Base Salary (RWF)</label><input type="number" name="base_salary" value="0"></div>
      <div class="form-group"><label>Bonus (RWF)</label><input type="number" name="bonus" value="0"></div>
      <div class="form-group"><label>Deductions (RWF)</label><input type="number" name="deductions" value="0"></div>
      <div class="form-group"><label>Amount Paid (RWF)</label><input type="number" name="amount_paid" value="0"></div>
      <div class="form-group"><label>Note</label><input name="note" placeholder="Optional"></div>
    </div>
    <div class="form-actions"><button class="btn btn-primary" type="submit">💾 Save Payroll</button></div>
  </form>
</div>

<div class="card">
  <div class="card-title">Payroll — <?= h($p) ?></div>
  <div class="search-wrap">
    <span class="search-icon">🔍</span>
    <input type="text" id="payrollSearch" placeholder="Search by staff name, zone, status…" oninput="filterTable('payrollSearch','payrollTable','payrollCount')">
  </div>
  <div class="search-count" id="payrollCount"></div>
  <div class="table-wrap">
  <table id="payrollTable">
    <tr><th>Staff</th><th>Zone</th><th>Base</th><th>Bonus</th><th>Deductions</th><th>Net Salary</th><th>Paid</th><th>Status</th></tr>
    <?php
    $pay=$pdo->query("SELECT c.*,s.full_name,z.name zone_name FROM coach_payroll c JOIN staff s ON s.id=c.staff_id LEFT JOIN academy_zones z ON z.id=s.zone_id WHERE c.period='$p' ORDER BY z.id,s.full_name")->fetchAll();
    foreach($pay as $x): ?>
    <tr>
      <td><strong><?= h($x['full_name']) ?></strong></td>
      <td><span class="badge badge-zone"><?= h($x['zone_name']) ?></span></td>
      <td style="font-family:var(--font-mono)"><?= money($x['base_salary']) ?></td>
      <td style="font-family:var(--font-mono);color:var(--accent)"><?= money($x['bonus']) ?></td>
      <td style="font-family:var(--font-mono);color:var(--danger)"><?= money($x['deductions']) ?></td>
      <td style="font-family:var(--font-mono);font-weight:700"><?= money($x['net_salary']) ?></td>
      <td style="font-family:var(--font-mono);color:var(--accent)"><?= money($x['amount_paid']) ?></td>
      <td><span class="badge <?= $x['status']==='PAID'?'badge-paid':($x['status']==='PARTIAL'?'badge-partial':'badge-unpaid') ?>"><?= h($x['status']) ?></span></td>
    </tr>
    <?php endforeach; ?>
    <?php if(empty($pay)): ?><tr><td colspan="8" class="no-results">No payroll records for <?= h($p) ?></td></tr><?php endif; ?>
  </table>
  </div>
</div>
<?php endif; ?>


<!-- ════════════════════════════════════════════════════
     EXPENSES
════════════════════════════════════════════════════ -->
<?php if($v==='expenses'): ?>
<div class="page-header">
  <div>
    <div class="page-title">Expenses <span>Ledger</span></div>
    <div class="page-period">Period: <?= h($p) ?></div>
  </div>
  <div class="period-nav">
    <a href="?view=expenses&period=<?= $prev ?>">← Prev</a>
    <span class="cur"><?= h($p) ?></span>
    <a href="?view=expenses&period=<?= $next ?>">Next →</a>
  </div>
</div>

<div class="card">
  <div class="card-title">Log New Expense</div>
  <form method="POST">
    <input type="hidden" name="action" value="expense">
    <div class="form-grid">
      <div class="form-group"><label>Date</label><input type="date" name="expense_date" value="<?= date('Y-m-d') ?>"></div>
      <div class="form-group"><label>Zone</label><select name="zone_id"><?php foreach($z as $zone): ?><option value="<?= $zone['id'] ?>"><?= h($zone['name']) ?></option><?php endforeach; ?></select></div>
      <div class="form-group"><label>Category</label><input name="category" placeholder="e.g. Equipment, Utility"></div>
      <div class="form-group"><label>Description *</label><input name="description" required placeholder="What was this expense for?"></div>
      <div class="form-group"><label>Amount (RWF) *</label><input type="number" name="amount" required></div>
      <div class="form-group"><label>Paid To</label><input name="paid_to" placeholder="Vendor / person name"></div>
      <div class="form-group"><label>Approved By</label><input name="approved_by"></div>
    </div>
    <div class="form-actions"><button class="btn btn-primary" type="submit">💾 Save Expense</button></div>
  </form>
</div>

<div class="card">
  <div class="card-title">Expense Records</div>
  <?php
  $expenses = $pdo->query("SELECT e.*,z.name zone_name FROM expenses e LEFT JOIN academy_zones z ON z.id=e.zone_id ORDER BY e.expense_date DESC,e.id DESC")->fetchAll();
  ?>
  <div class="filter-bar">
    <div class="search-wrap" style="flex:1;margin-bottom:0">
      <span class="search-icon">🔍</span>
      <input type="text" id="expenseSearch" placeholder="Search by description, category, zone, paid to…" oninput="filterTable('expenseSearch','expenseTable','expenseCount')">
    </div>
    <select id="expenseZoneFilter" onchange="filterTable('expenseSearch','expenseTable','expenseCount')">
      <option value="">All Zones</option>
      <?php foreach($z as $zone): ?><option value="<?= h($zone['name']) ?>"><?= h($zone['name']) ?></option><?php endforeach; ?>
    </select>
  </div>
  <div class="search-count" id="expenseCount"></div>
  <div class="table-wrap">
  <table id="expenseTable">
    <tr><th>Date</th><th>Zone</th><th>Category</th><th>Description</th><th>Amount</th><th>Paid To</th><th>Approved By</th></tr>
    <?php foreach($expenses as $e): ?>
    <tr>
      <td style="font-family:var(--font-mono);font-size:12px;color:var(--muted)"><?= h($e['expense_date']) ?></td>
      <td><span class="badge badge-zone"><?= h($e['zone_name']) ?></span></td>
      <td style="color:var(--muted)"><?= h($e['category']) ?></td>
      <td><?= h($e['description']) ?></td>
      <td style="font-family:var(--font-mono);color:var(--danger)"><?= money($e['amount']) ?></td>
      <td><?= h($e['paid_to']) ?></td>
      <td style="color:var(--muted)"><?= h($e['approved_by']) ?></td>
    </tr>
    <?php endforeach; ?>
    <?php if(empty($expenses)): ?><tr><td colspan="7" class="no-results">No expenses recorded yet</td></tr><?php endif; ?>
  </table>
  </div>
</div>
<?php endif; ?>


<!-- ════════════════════════════════════════════════════
     REPORTS
════════════════════════════════════════════════════ -->
<?php if($v==='reports'): ?>
<div class="page-header">
  <div>
    <div class="page-title">Reports <span>&amp; Analytics</span></div>
    <div class="page-period">Period: <?= h($p) ?></div>
  </div>
  <div class="period-nav">
    <a href="?view=reports&period=<?= $prev ?>">← Prev</a>
    <span class="cur"><?= h($p) ?></span>
    <a href="?view=reports&period=<?= $next ?>">Next →</a>
  </div>
</div>

<div class="card">
  <div class="card-title">Zone Financial Report — <?= h($p) ?></div>
  <div class="search-wrap">
    <span class="search-icon">🔍</span>
    <input type="text" id="zoneRepSearch" placeholder="Search zones…" oninput="filterTable('zoneRepSearch','zoneRepTable','zoneRepCount')">
  </div>
  <div class="search-count" id="zoneRepCount"></div>
  <div class="table-wrap">
  <table id="zoneRepTable">
    <tr><th>Zone</th><th>Expected</th><th>Paid</th><th>Remaining</th><th>Expenses</th><th>Payroll</th></tr>
    <?php
    $r=$pdo->query("
    SELECT z.name,
    COALESCE(SUM(DISTINCT b.expected_amount),0) expected,
    COALESCE(SUM(DISTINCT b.paid_amount),0) paid,
    COALESCE(SUM(DISTINCT b.expected_amount-b.paid_amount),0) remaining,
    COALESCE(SUM(DISTINCT e.amount),0) expenses,
    COALESCE(SUM(DISTINCT c.amount_paid),0) payroll
    FROM academy_zones z
    LEFT JOIN members m ON m.zone_id=z.id
    LEFT JOIN monthly_bills b ON b.member_id=m.id AND b.period='$p'
    LEFT JOIN expenses e ON e.zone_id=z.id AND TO_CHAR(e.expense_date,'YYYY-MM')='$p'
    LEFT JOIN staff st ON st.zone_id=z.id
    LEFT JOIN coach_payroll c ON c.staff_id=st.id AND c.period='$p'
    GROUP BY z.id,z.name ORDER BY z.id")->fetchAll();
    foreach($r as $x): ?>
    <tr>
      <td><strong><?= h($x['name']) ?></strong></td>
      <td style="font-family:var(--font-mono)"><?= money($x['expected']) ?></td>
      <td style="font-family:var(--font-mono);color:var(--accent)"><?= money($x['paid']) ?></td>
      <td style="font-family:var(--font-mono);color:var(--warn)"><?= money($x['remaining']) ?></td>
      <td style="font-family:var(--font-mono);color:var(--danger)"><?= money($x['expenses']) ?></td>
      <td style="font-family:var(--font-mono)"><?= money($x['payroll']) ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
  </div>
</div>

<div class="card">
  <div class="card-title">Payment Logs</div>
  <div class="search-wrap">
    <span class="search-icon">🔍</span>
    <input type="text" id="paylogSearch" placeholder="Search payment logs by athlete, period, note…" oninput="filterTable('paylogSearch','paylogTable','paylogCount')">
  </div>
  <div class="search-count" id="paylogCount"></div>
  <div class="table-wrap">
  <table id="paylogTable">
    <tr><th>Date</th><th>Athlete</th><th>Period</th><th>Amount</th><th>Note</th></tr>
    <?php
    $logs = $pdo->query("SELECT pl.*,m.full_name FROM payment_logs pl JOIN members m ON m.id=pl.member_id ORDER BY pl.created_at DESC LIMIT 200")->fetchAll();
    foreach($logs as $l): ?>
    <tr>
      <td style="font-family:var(--font-mono);font-size:12px;color:var(--muted)"><?= h(substr($l['created_at'],0,10)) ?></td>
      <td><?= h($l['full_name']) ?></td>
      <td style="font-family:var(--font-mono)"><?= h($l['period']) ?></td>
      <td style="font-family:var(--font-mono);color:var(--accent)"><?= money($l['amount_paid']) ?></td>
      <td style="color:var(--muted)"><?= h($l['note']) ?></td>
    </tr>
    <?php endforeach; ?>
    <?php if(empty($logs)): ?><tr><td colspan="5" class="no-results">No payment logs yet</td></tr><?php endif; ?>
  </table>
  </div>
</div>

<div class="card">
  <div class="card-title">Attendance Report</div>
  <div class="search-wrap">
    <span class="search-icon">🔍</span>
    <input type="text" id="attRepSearch" placeholder="Search sessions, zones…" oninput="filterTable('attRepSearch','attRepTable','attRepCount')">
  </div>
  <div class="search-count" id="attRepCount"></div>
  <div class="table-wrap">
  <table id="attRepTable">
    <tr><th>Session</th><th>Date</th><th>Zone</th><th>Present</th><th>Absent</th><th>Late</th><th>Total</th></tr>
    <?php
    $a=$pdo->query("
    SELECT s.name,s.session_date,z.name zone,
    SUM(CASE WHEN a.status='present' THEN 1 ELSE 0 END) present,
    SUM(CASE WHEN a.status='absent' THEN 1 ELSE 0 END) absent,
    SUM(CASE WHEN a.status='late' THEN 1 ELSE 0 END) late,
    COUNT(a.id) total
    FROM sessions s LEFT JOIN academy_zones z ON z.id=s.zone_id
    LEFT JOIN attendance a ON a.session_id=s.id
    GROUP BY s.id,s.name,s.session_date,z.name ORDER BY s.session_date DESC")->fetchAll();
    foreach($a as $x): ?>
    <tr>
      <td><?= h($x['name']) ?></td>
      <td style="font-family:var(--font-mono);font-size:12px;color:var(--muted)"><?= h($x['session_date']) ?></td>
      <td><span class="badge badge-zone"><?= h($x['zone']) ?></span></td>
      <td><span class="badge badge-present"><?= $x['present']??0 ?></span></td>
      <td><span class="badge badge-absent"><?= $x['absent']??0 ?></span></td>
      <td><span class="badge badge-late"><?= $x['late']??0 ?></span></td>
      <td style="color:var(--muted);font-family:var(--font-mono)"><?= $x['total']??0 ?></td>
    </tr>
    <?php endforeach; ?>
    <?php if(empty($a)): ?><tr><td colspan="7" class="no-results">No attendance records yet</td></tr><?php endif; ?>
  </table>
  </div>
</div>
<?php endif; ?>

</div><!-- /main -->

<script>
/**
 * Universal table search + dropdown filter function.
 * Reads the search input + any select[id$="Filter"] siblings in the same .filter-bar.
 */
function filterTable(searchId, tableId, countId) {
  const searchInput = document.getElementById(searchId);
  const table = document.getElementById(tableId);
  const countEl = document.getElementById(countId);
  if(!table) return;

  const query = searchInput ? searchInput.value.toLowerCase().trim() : '';

  // Collect all filter selects in the same filter-bar as search input
  const bar = searchInput ? searchInput.closest('.filter-bar, .search-wrap') : null;
  const card = table.closest('.card');
  const filterSelects = card ? card.querySelectorAll('select[id$="Filter"]') : [];

  const rows = table.querySelectorAll('tbody tr, tr:not(:first-child)');
  let visible = 0;

  rows.forEach(row => {
    const text = row.textContent.toLowerCase();
    let show = true;

    // Text search
    if(query && !text.includes(query)) show = false;

    // Dropdown filters
    filterSelects.forEach(sel => {
      const val = sel.value.toLowerCase();
      if(val && !text.includes(val)) show = false;
    });

    row.style.display = show ? '' : 'none';
    if(show) visible++;
  });

  // Show count
  if(countEl) {
    const total = rows.length;
    countEl.textContent = query || [...filterSelects].some(s=>s.value)
      ? `Showing ${visible} of ${total} records`
      : `${total} record${total!==1?'s':''}`;
  }

  // Show/hide no-results row
  let noRes = table.querySelector('.no-results-dynamic');
  if(visible === 0 && rows.length > 0) {
    if(!noRes) {
      const colspan = table.querySelector('tr').children.length;
      const tr = document.createElement('tr');
      tr.className = 'no-results-dynamic';
      tr.innerHTML = `<td colspan="${colspan}" class="no-results">No results match your search</td>`;
      table.appendChild(tr);
    }
  } else {
    if(noRes) noRes.remove();
  }
}

// Init counts on page load for all tables with search
document.addEventListener('DOMContentLoaded', () => {
  const pairs = [
    ['memberSearch','memberTable','memberCount'],
    ['sessionSearch','sessionTable','sessionCount'],
    ['billSearch','billTable','billCount'],
    ['staffSearch','staffTable','staffCount'],
    ['payrollSearch','payrollTable','payrollCount'],
    ['expenseSearch','expenseTable','expenseCount'],
    ['zoneRepSearch','zoneRepTable','zoneRepCount'],
    ['paylogSearch','paylogTable','paylogCount'],
    ['attRepSearch','attRepTable','attRepCount'],
  ];
  pairs.forEach(([s,t,c]) => {
    if(document.getElementById(t)) filterTable(s,t,c);
  });
});
</script>
</body>
</html>
