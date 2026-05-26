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
ALTER TABLE athlete_uniforms ADD COLUMN IF NOT EXISTS jersey_number INT;
ALTER TABLE athlete_uniforms ADD COLUMN IF NOT EXISTS jersey_category VARCHAR(60);
ALTER TABLE athlete_uniforms ADD COLUMN IF NOT EXISTS jersey_size VARCHAR(20);
ALTER TABLE athlete_uniforms ADD COLUMN IF NOT EXISTS jersey_chest NUMERIC(6,2);
ALTER TABLE athlete_uniforms ADD COLUMN IF NOT EXISTS jersey_length NUMERIC(6,2);
ALTER TABLE athlete_uniforms ADD COLUMN IF NOT EXISTS shorts_category VARCHAR(60);
ALTER TABLE athlete_uniforms ADD COLUMN IF NOT EXISTS shorts_size VARCHAR(20);
ALTER TABLE athlete_uniforms ADD COLUMN IF NOT EXISTS shorts_waist NUMERIC(6,2);
ALTER TABLE athlete_uniforms ADD COLUMN IF NOT EXISTS shorts_inseam NUMERIC(6,2);
ALTER TABLE athlete_uniforms ADD COLUMN IF NOT EXISTS quantity INT DEFAULT 1;
ALTER TABLE athlete_uniforms ADD COLUMN IF NOT EXISTS issued_date DATE DEFAULT CURRENT_DATE;
ALTER TABLE athlete_uniforms ADD COLUMN IF NOT EXISTS note TEXT;
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

    if($a==='save_uniform'){
        $id=$_POST['id']??'';
        $member_id=(int)($_POST['member_id']??0);
        $jersey_number=(int)($_POST['jersey_number']??0);
        if($member_id<=0 || $jersey_number<=0) go('uniforms','Please select athlete and jersey number');
        $data=[
            $member_id,$jersey_number,
            $_POST['jersey_category']??'Adult Unisex V-Neck',$_POST['jersey_size']??'',$_POST['jersey_chest']?:null,$_POST['jersey_length']?:null,
            $_POST['shorts_category']??'Adult Unisex Shorts',$_POST['shorts_size']??'',$_POST['shorts_waist']?:null,$_POST['shorts_inseam']?:null,
            $_POST['quantity']?:1,$_POST['issued_date']?:date('Y-m-d'),$_POST['note']?:null
        ];
        try{
            if($id){
                $stmt=$pdo->prepare("UPDATE athlete_uniforms SET member_id=?,jersey_number=?,jersey_category=?,jersey_size=?,jersey_chest=?,jersey_length=?,shorts_category=?,shorts_size=?,shorts_waist=?,shorts_inseam=?,quantity=?,issued_date=?,note=? WHERE id=?");
                $stmt->execute([...$data,$id]);
                go('uniforms','Uniform updated');
            }else{
                $stmt=$pdo->prepare("INSERT INTO athlete_uniforms(member_id,jersey_number,jersey_category,jersey_size,jersey_chest,jersey_length,shorts_category,shorts_size,shorts_waist,shorts_inseam,quantity,issued_date,note) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)");
                $stmt->execute($data);
                go('uniforms','Uniform saved');
            }
        }catch(PDOException $e){
            if(strpos($e->getMessage(),'unique')!==false) go('uniforms','This jersey number is already assigned. Use another number.');
            throw $e;
        }
    }
    if($a==='delete_uniform'){
        $pdo->prepare("DELETE FROM athlete_uniforms WHERE id=?")->execute([$_POST['id']]);
        go('uniforms','Uniform record deleted');
    }
    if($a==='expense'){
        $pdo->prepare("INSERT INTO expenses(expense_date,category,description,amount,paid_to,approved_by,zone_id) VALUES(?,?,?,?,?,?,?)")
            ->execute([$_POST['expense_date'],$_POST['category'],$_POST['description'],$_POST['amount'],$_POST['paid_to'],$_POST['approved_by'],$_POST['zone_id']?:default_zone($pdo)]);
        go('expenses','Expense saved');
    }
}

$z=zones($pdo);$m=members($pdo);$am=active_members($pdo);$s=sessions($pdo);$st=staff($pdo);$p=period();$v=view();$msg=$_GET['msg']??'';
$edit_member=null;$edit_staff=null;$edit_session=null;$edit_uniform=null;
if(isset($_GET['edit_member'])){$q=$pdo->prepare("SELECT * FROM members WHERE id=?");$q->execute([$_GET['edit_member']]);$edit_member=$q->fetch();}
if(isset($_GET['edit_staff'])){$q=$pdo->prepare("SELECT * FROM staff WHERE id=?");$q->execute([$_GET['edit_staff']]);$edit_staff=$q->fetch();}
if(isset($_GET['edit_session'])){$q=$pdo->prepare("SELECT * FROM sessions WHERE id=?");$q->execute([$_GET['edit_session']]);$edit_session=$q->fetch();}


if(($_GET['export']??'')==='uniforms'){
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="uniform_report_'.date('Ymd_His').'.csv"');
    $out=fopen('php://output','w');
    fputcsv($out,['Jersey Number','Athlete','Zone','Jersey Category','Jersey Size','Chest','Length','Shorts Category','Shorts Size','Waist','Inseam','Quantity','Issued Date','Note']);
    $rows=$pdo->query("SELECT u.*,m.full_name,z.name zone_name FROM athlete_uniforms u JOIN members m ON m.id=u.member_id LEFT JOIN academy_zones z ON z.id=m.zone_id ORDER BY u.jersey_number ASC,m.full_name ASC")->fetchAll();
    foreach($rows as $r){
        fputcsv($out,[$r['jersey_number'],$r['full_name'],$r['zone_name'],$r['jersey_category'],$r['jersey_size'],$r['jersey_chest'],$r['jersey_length'],$r['shorts_category'],$r['shorts_size'],$r['shorts_waist'],$r['shorts_inseam'],$r['quantity'],$r['issued_date'],$r['note']]);
    }
    fclose($out);exit;
}

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
    'dashboard' => ['icon'=>'▲','label'=>'Dashboard'],
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
<link href="https://fonts.googleapis.com/css2?family=Clash+Display:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@300;400;500;600;700&family=JetBrains+Mono:wght@300;400;500&display=swap" rel="stylesheet">
<style>
/* ─────────────────────────────────────────────────────────
   DESIGN TOKENS
───────────────────────────────────────────────────────── */
:root {
  --bg:        #040810;
  --bg2:       #060c18;
  --surface:   #0a1628;
  --surface2:  #0f1e38;
  --surface3:  #142440;
  --border:    #1c2e4a;
  --border2:   #243a5e;
  --border3:   #2e4870;

  --lime:      #c6f135;
  --lime-dim:  rgba(198,241,53,0.12);
  --lime-glow: rgba(198,241,53,0.25);
  --teal:      #00d9c0;
  --teal-dim:  rgba(0,217,192,0.1);
  --blue:      #4d9fff;
  --blue-dim:  rgba(77,159,255,0.1);
  --amber:     #ffb740;
  --amber-dim: rgba(255,183,64,0.1);
  --red:       #ff4f6b;
  --red-dim:   rgba(255,79,107,0.1);
  --purple:    #a78bfa;

  --text:      #e8f0fe;
  --text2:     #9bb5d8;
  --muted:     #4d6a8a;
  --muted2:    #3a5070;

  --radius:    16px;
  --radius-sm: 10px;
  --radius-xs: 6px;
  --sidebar-w: 256px;

  --font-display: 'Clash Display', sans-serif;
  --font-body:    'Plus Jakarta Sans', sans-serif;
  --font-mono:    'JetBrains Mono', monospace;

  --transition: 0.2s cubic-bezier(0.4,0,0.2,1);
}

*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}

html { scroll-behavior: smooth; }

body {
  background: var(--bg);
  color: var(--text);
  font-family: var(--font-body);
  font-size: 14px;
  min-height: 100vh;
  display: flex;
  overflow-x: hidden;
}

/* subtle noise texture */
body::before {
  content: '';
  position: fixed;
  inset: 0;
  background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='0.02'/%3E%3C/svg%3E");
  pointer-events: none;
  z-index: 0;
}

/* ─── SIDEBAR ────────────────────────────────────── */
.sidebar {
  position: fixed;
  top: 0; left: 0;
  width: var(--sidebar-w);
  height: 100vh;
  background: var(--surface);
  border-right: 1px solid var(--border);
  display: flex;
  flex-direction: column;
  padding: 0;
  overflow-y: auto;
  z-index: 100;
  box-shadow: 4px 0 40px rgba(0,0,0,0.4);
}

.sidebar-top {
  padding: 28px 20px 24px;
  border-bottom: 1px solid var(--border);
}

.logo {
  display: flex;
  align-items: center;
  gap: 12px;
}
.logo-mark {
  width: 42px; height: 42px;
  background: var(--lime);
  border-radius: 12px;
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0;
  position: relative;
  overflow: hidden;
}
.logo-mark::after {
  content: '';
  position: absolute;
  inset: 0;
  background: linear-gradient(135deg, rgba(255,255,255,0.3), transparent);
}
.logo-mark span {
  font-family: var(--font-display);
  font-size: 20px;
  font-weight: 700;
  color: #000;
  position: relative;
  z-index: 1;
}
.logo-text {
  font-family: var(--font-display);
  font-size: 17px;
  font-weight: 700;
  color: var(--text);
  letter-spacing: -0.01em;
  line-height: 1.15;
}
.logo-sub {
  font-size: 10px;
  color: var(--muted);
  font-family: var(--font-mono);
  letter-spacing: 0.15em;
  text-transform: uppercase;
  margin-top: 1px;
}

.nav-body {
  padding: 16px 12px;
  flex: 1;
}
.nav-label {
  font-size: 10px;
  color: var(--muted);
  letter-spacing: 0.2em;
  text-transform: uppercase;
  font-family: var(--font-mono);
  padding: 0 8px;
  margin: 8px 0 6px;
}
.nav a {
  display: flex;
  align-items: center;
  gap: 10px;
  color: var(--text2);
  text-decoration: none;
  padding: 10px 12px;
  border-radius: var(--radius-sm);
  margin-bottom: 1px;
  font-size: 13.5px;
  font-weight: 500;
  transition: all var(--transition);
  border: 1px solid transparent;
  position: relative;
}
.nav a:hover {
  color: var(--text);
  background: var(--surface2);
  border-color: var(--border);
}
.nav a.active {
  color: var(--lime);
  background: var(--lime-dim);
  border-color: rgba(198,241,53,0.2);
  font-weight: 600;
}
.nav a.active::before {
  content: '';
  position: absolute;
  left: -1px; top: 20%; bottom: 20%;
  width: 3px;
  background: var(--lime);
  border-radius: 0 2px 2px 0;
}
.nav-icon {
  font-size: 13px;
  width: 16px;
  text-align: center;
  opacity: 0.8;
}

.sidebar-footer {
  padding: 16px;
  border-top: 1px solid var(--border);
}
.period-widget {
  background: var(--surface2);
  border: 1px solid var(--border2);
  border-radius: var(--radius-sm);
  padding: 12px 14px;
  position: relative;
  overflow: hidden;
}
.period-widget::before {
  content: '';
  position: absolute;
  top: -20px; right: -20px;
  width: 80px; height: 80px;
  background: radial-gradient(circle, var(--lime-glow), transparent 70%);
}
.period-widget-label {
  font-size: 10px;
  color: var(--muted);
  font-family: var(--font-mono);
  letter-spacing: 0.15em;
  text-transform: uppercase;
  margin-bottom: 4px;
}
.period-widget-val {
  font-family: var(--font-display);
  font-size: 18px;
  font-weight: 700;
  color: var(--lime);
  letter-spacing: -0.01em;
}

/* ─── MAIN ────────────────────────────────────────── */
.main {
  margin-left: var(--sidebar-w);
  flex: 1;
  padding: 36px 40px;
  max-width: calc(100vw - var(--sidebar-w));
  position: relative;
  z-index: 1;
}

/* ─── PAGE HEADER ─────────────────────────────────── */
.page-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  margin-bottom: 30px;
  flex-wrap: wrap;
  gap: 14px;
}
.page-title {
  font-family: var(--font-display);
  font-size: 32px;
  font-weight: 700;
  letter-spacing: -0.03em;
  line-height: 1;
  color: var(--text);
}
.page-title em {
  font-style: normal;
  color: var(--lime);
}
.page-sub {
  font-size: 12px;
  color: var(--muted);
  font-family: var(--font-mono);
  margin-top: 6px;
  letter-spacing: 0.05em;
}

/* ─── PERIOD NAV ──────────────────────────────────── */
.period-nav {
  display: flex;
  align-items: center;
  gap: 6px;
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
  padding: 5px;
}
.period-nav a {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  color: var(--text2);
  text-decoration: none;
  background: transparent;
  border: 1px solid transparent;
  border-radius: var(--radius-xs);
  padding: 6px 12px;
  font-size: 12px;
  font-family: var(--font-mono);
  transition: all var(--transition);
}
.period-nav a:hover {
  border-color: var(--border2);
  color: var(--text);
  background: var(--surface2);
}
.period-nav .cur {
  font-family: var(--font-mono);
  color: var(--lime);
  font-size: 13px;
  font-weight: 500;
  padding: 6px 14px;
  background: var(--lime-dim);
  border: 1px solid rgba(198,241,53,0.2);
  border-radius: var(--radius-xs);
  cursor: default;
  letter-spacing: 0.04em;
}

/* ─── FLASH MESSAGE ───────────────────────────────── */
.flash {
  display: flex;
  align-items: center;
  gap: 12px;
  background: linear-gradient(90deg, rgba(198,241,53,0.08), rgba(0,217,192,0.05));
  border: 1px solid rgba(198,241,53,0.2);
  border-left: 3px solid var(--lime);
  color: var(--lime);
  padding: 13px 18px;
  border-radius: var(--radius-sm);
  margin-bottom: 24px;
  font-size: 13px;
  font-weight: 500;
  animation: slideDown 0.3s ease;
}
.flash-icon {
  width: 22px; height: 22px;
  background: var(--lime);
  border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  color: #000;
  font-size: 12px;
  font-weight: 900;
  flex-shrink: 0;
}
@keyframes slideDown { from { opacity:0; transform: translateY(-8px); } to { opacity:1; transform: translateY(0); } }

/* ─── CARDS ───────────────────────────────────────── */
.card {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  padding: 26px;
  margin-bottom: 20px;
  position: relative;
  overflow: hidden;
  transition: border-color var(--transition);
}
.card:hover { border-color: var(--border2); }
.card-corner {
  position: absolute;
  top: 0; right: 0;
  width: 100px; height: 100px;
  background: radial-gradient(circle at top right, rgba(198,241,53,0.04), transparent 70%);
  pointer-events: none;
}

.card-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 20px;
}
.card-title {
  font-family: var(--font-display);
  font-size: 15px;
  font-weight: 600;
  display: flex;
  align-items: center;
  gap: 10px;
  letter-spacing: -0.01em;
}
.card-title-bar {
  width: 4px; height: 18px;
  background: linear-gradient(180deg, var(--lime), var(--teal));
  border-radius: 2px;
  flex-shrink: 0;
}

/* ─── STAT GRID ───────────────────────────────────── */
.stat-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 14px;
  margin-bottom: 20px;
}
.stat-card {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  padding: 22px 24px;
  position: relative;
  overflow: hidden;
  transition: all var(--transition);
  cursor: default;
}
.stat-card:hover {
  border-color: var(--border3);
  transform: translateY(-1px);
  box-shadow: 0 8px 30px rgba(0,0,0,0.3);
}
.stat-card::after {
  content: '';
  position: absolute;
  bottom: -20px; right: -20px;
  width: 90px; height: 90px;
  border-radius: 50%;
  background: var(--stat-glow, rgba(198,241,53,0.05));
}
.stat-card[data-color="lime"]  { --stat-glow: rgba(198,241,53,0.06); }
.stat-card[data-color="teal"]  { --stat-glow: rgba(0,217,192,0.06); }
.stat-card[data-color="amber"] { --stat-glow: rgba(255,183,64,0.06); }
.stat-card[data-color="red"]   { --stat-glow: rgba(255,79,107,0.06); }
.stat-card[data-color="blue"]  { --stat-glow: rgba(77,159,255,0.06); }
.stat-card[data-color="purple"]{ --stat-glow: rgba(167,139,250,0.06); }

.stat-icon {
  width: 36px; height: 36px;
  border-radius: 10px;
  display: flex; align-items: center; justify-content: center;
  font-size: 16px;
  margin-bottom: 14px;
  background: var(--stat-icon-bg, var(--lime-dim));
}
.stat-card[data-color="lime"]   .stat-icon { background: var(--lime-dim); }
.stat-card[data-color="teal"]   .stat-icon { background: var(--teal-dim); }
.stat-card[data-color="amber"]  .stat-icon { background: var(--amber-dim); }
.stat-card[data-color="red"]    .stat-icon { background: var(--red-dim); }
.stat-card[data-color="blue"]   .stat-icon { background: var(--blue-dim); }
.stat-card[data-color="purple"] .stat-icon { background: rgba(167,139,250,0.1); }

.stat-label {
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: 0.12em;
  color: var(--muted);
  font-family: var(--font-mono);
  margin-bottom: 6px;
}
.stat-value {
  font-family: var(--font-display);
  font-size: 28px;
  font-weight: 700;
  line-height: 1;
  letter-spacing: -0.03em;
}
.stat-card[data-color="lime"]   .stat-value { color: var(--lime); }
.stat-card[data-color="teal"]   .stat-value { color: var(--teal); }
.stat-card[data-color="amber"]  .stat-value { color: var(--amber); }
.stat-card[data-color="red"]    .stat-value { color: var(--red); }
.stat-card[data-color="blue"]   .stat-value { color: var(--blue); }
.stat-card[data-color="purple"] .stat-value { color: var(--purple); }

/* ─── TABLES ──────────────────────────────────────── */
.table-wrap { overflow-x: auto; border-radius: var(--radius-sm); }
table { width: 100%; border-collapse: collapse; }
thead th {
  color: var(--muted);
  font-size: 10.5px;
  font-family: var(--font-mono);
  text-transform: uppercase;
  letter-spacing: 0.12em;
  padding: 11px 14px;
  border-bottom: 1px solid var(--border);
  text-align: left;
  white-space: nowrap;
  background: var(--surface2);
}
thead th:first-child { border-radius: var(--radius-xs) 0 0 0; }
thead th:last-child  { border-radius: 0 var(--radius-xs) 0 0; }
tbody td {
  padding: 13px 14px;
  border-bottom: 1px solid rgba(28,46,74,0.6);
  font-size: 13.5px;
  transition: background var(--transition);
  vertical-align: middle;
}
tbody tr:last-child td { border-bottom: none; }
tbody tr:hover td { background: rgba(255,255,255,0.018); }
.no-data {
  text-align: center;
  color: var(--muted);
  padding: 50px 0;
  font-size: 13px;
  font-family: var(--font-mono);
  letter-spacing: 0.05em;
}

/* ─── BADGES ──────────────────────────────────────── */
.badge {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 3px 10px;
  border-radius: 999px;
  font-size: 11px;
  font-weight: 600;
  font-family: var(--font-mono);
  white-space: nowrap;
  letter-spacing: 0.04em;
}
.b-zone    { background: var(--blue-dim); color: #82b4ff; border: 1px solid rgba(77,159,255,0.2); }
.b-paid    { background: var(--lime-dim); color: var(--lime); border: 1px solid rgba(198,241,53,0.2); }
.b-partial { background: var(--amber-dim); color: var(--amber); border: 1px solid rgba(255,183,64,0.2); }
.b-unpaid  { background: var(--red-dim); color: var(--red); border: 1px solid rgba(255,79,107,0.2); }
.b-nobill  { background: rgba(77,106,138,0.1); color: var(--muted); border: 1px solid rgba(77,106,138,0.2); }
.b-active  { background: var(--lime-dim); color: var(--lime); }
.b-inactive{ background: var(--red-dim); color: var(--red); }
.b-present { background: var(--lime-dim); color: var(--lime); }
.b-absent  { background: var(--red-dim); color: var(--red); }
.b-late    { background: var(--amber-dim); color: var(--amber); }

/* ─── FORMS ───────────────────────────────────────── */
.form-grid   { display: grid; grid-template-columns: repeat(3,1fr); gap: 14px; }
.form-grid-2 { display: grid; grid-template-columns: repeat(2,1fr); gap: 14px; }
.form-grid-4 { display: grid; grid-template-columns: repeat(4,1fr); gap: 14px; }

.form-group label {
  display: block;
  font-size: 10.5px;
  text-transform: uppercase;
  letter-spacing: 0.12em;
  color: var(--muted);
  font-family: var(--font-mono);
  margin-bottom: 7px;
}
.form-group input,
.form-group select,
.form-group textarea {
  width: 100%;
  padding: 10px 14px;
  background: var(--surface2);
  border: 1px solid var(--border2);
  border-radius: var(--radius-sm);
  color: var(--text);
  font-family: var(--font-body);
  font-size: 13.5px;
  outline: none;
  transition: border-color var(--transition), box-shadow var(--transition), background var(--transition);
  -webkit-appearance: none;
}
.form-group input:focus,
.form-group select:focus,
.form-group textarea:focus {
  border-color: var(--lime);
  background: var(--surface3);
  box-shadow: 0 0 0 3px var(--lime-glow);
}
.form-group input::placeholder { color: var(--muted2); }
.form-group select { cursor: pointer; }
.form-group select option { background: var(--surface2); }

.form-actions {
  display: flex;
  gap: 10px;
  align-items: center;
  margin-top: 20px;
  padding-top: 18px;
  border-top: 1px solid var(--border);
  flex-wrap: wrap;
}

/* ─── BUTTONS ─────────────────────────────────────── */
.btn {
  display: inline-flex;
  align-items: center;
  gap: 7px;
  padding: 10px 20px;
  border-radius: var(--radius-sm);
  font-family: var(--font-display);
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
  border: none;
  text-decoration: none;
  transition: all var(--transition);
  white-space: nowrap;
  letter-spacing: 0.01em;
  position: relative;
  overflow: hidden;
}
.btn::after {
  content: '';
  position: absolute;
  inset: 0;
  background: linear-gradient(135deg, rgba(255,255,255,0.08), transparent);
  opacity: 0;
  transition: opacity var(--transition);
}
.btn:hover::after { opacity: 1; }

.btn-primary {
  background: var(--lime);
  color: #050f0a;
  box-shadow: 0 4px 16px rgba(198,241,53,0.2);
}
.btn-primary:hover {
  background: #d4f540;
  box-shadow: 0 6px 24px rgba(198,241,53,0.35);
  transform: translateY(-1px);
}
.btn-ghost {
  background: var(--surface2);
  color: var(--text2);
  border: 1px solid var(--border2);
}
.btn-ghost:hover {
  border-color: var(--border3);
  color: var(--text);
  background: var(--surface3);
}
.btn-danger {
  background: var(--red-dim);
  color: var(--red);
  border: 1px solid rgba(255,79,107,0.2);
}
.btn-danger:hover { background: rgba(255,79,107,0.2); }
.btn-sm { padding: 6px 13px; font-size: 12px; }
.btn-xs { padding: 4px 10px; font-size: 11px; }

.actions-cell { display: flex; gap: 6px; flex-wrap: wrap; align-items: center; }

/* ─── SEARCH & FILTER BAR ─────────────────────────── */
.toolbar {
  display: flex;
  gap: 10px;
  margin-bottom: 16px;
  flex-wrap: wrap;
  align-items: center;
}
.search-box {
  position: relative;
  flex: 1;
  min-width: 200px;
}
.search-box-icon {
  position: absolute;
  left: 13px; top: 50%;
  transform: translateY(-50%);
  color: var(--muted);
  font-size: 14px;
  pointer-events: none;
}
.search-box input {
  width: 100%;
  padding: 10px 14px 10px 40px;
  background: var(--surface2);
  border: 1px solid var(--border2);
  border-radius: var(--radius-sm);
  color: var(--text);
  font-family: var(--font-body);
  font-size: 13.5px;
  outline: none;
  transition: all var(--transition);
}
.search-box input:focus {
  border-color: var(--lime);
  background: var(--surface3);
  box-shadow: 0 0 0 3px var(--lime-glow);
}
.search-box input::placeholder { color: var(--muted2); }
.toolbar select {
  padding: 10px 14px;
  background: var(--surface2);
  border: 1px solid var(--border2);
  border-radius: var(--radius-sm);
  color: var(--text);
  font-size: 13px;
  outline: none;
  cursor: pointer;
  transition: all var(--transition);
  font-family: var(--font-body);
  -webkit-appearance: none;
}
.toolbar select:focus {
  border-color: var(--lime);
  box-shadow: 0 0 0 3px var(--lime-glow);
}
.toolbar select option { background: var(--surface2); }

.result-count {
  font-size: 11px;
  color: var(--muted);
  font-family: var(--font-mono);
  margin-bottom: 12px;
  letter-spacing: 0.04em;
}

/* ─── OVERDUE CHIP ────────────────────────────────── */
.overdue {
  font-family: var(--font-mono);
  font-size: 11px;
  padding: 3px 9px;
  border-radius: 999px;
}
.overdue.over { background: var(--red-dim); color: var(--red); }
.overdue.ok   { color: var(--muted); }

/* ─── ATHLETE SEARCH AUTOCOMPLETE ────────────────── */
.autocomplete-wrap { position: relative; }
.autocomplete-dropdown {
  position: absolute;
  top: calc(100% + 6px); left: 0; right: 0;
  background: var(--surface2);
  border: 1px solid var(--border2);
  border-radius: var(--radius-sm);
  box-shadow: 0 16px 48px rgba(0,0,0,0.5);
  z-index: 999;
  max-height: 320px;
  overflow-y: auto;
  display: none;
}
.autocomplete-dropdown.open { display: block; }
.ac-item {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 11px 14px;
  cursor: pointer;
  transition: background var(--transition);
  border-bottom: 1px solid var(--border);
}
.ac-item:last-child { border-bottom: none; }
.ac-item:hover, .ac-item.focused { background: var(--surface3); }
.ac-avatar {
  width: 32px; height: 32px;
  border-radius: 10px;
  background: var(--lime-dim);
  border: 1px solid rgba(198,241,53,0.15);
  display: flex; align-items: center; justify-content: center;
  font-family: var(--font-display);
  font-size: 13px;
  font-weight: 700;
  color: var(--lime);
  flex-shrink: 0;
  text-transform: uppercase;
}
.ac-info { flex: 1; min-width: 0; }
.ac-name { font-size: 13.5px; font-weight: 600; color: var(--text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.ac-meta { font-size: 11px; color: var(--muted); font-family: var(--font-mono); margin-top: 1px; }
.ac-badge { font-size: 11px; color: var(--teal); font-family: var(--font-mono); white-space: nowrap; }
.ac-empty { padding: 20px; text-align: center; color: var(--muted); font-size: 12px; font-family: var(--font-mono); }
.selected-athlete-info {
  display: none;
  align-items: center;
  gap: 14px;
  background: var(--surface2);
  border: 1px solid rgba(198,241,53,0.2);
  border-radius: var(--radius-sm);
  padding: 12px 16px;
  margin-top: 10px;
}
.selected-athlete-info.visible { display: flex; }
.sa-avatar {
  width: 40px; height: 40px;
  border-radius: 12px;
  background: var(--lime-dim);
  border: 1px solid rgba(198,241,53,0.2);
  display: flex; align-items: center; justify-content: center;
  font-family: var(--font-display);
  font-size: 16px;
  font-weight: 700;
  color: var(--lime);
  flex-shrink: 0;
  text-transform: uppercase;
}
.sa-name { font-size: 14px; font-weight: 600; color: var(--text); }
.sa-detail { font-size: 11px; color: var(--muted); font-family: var(--font-mono); margin-top: 2px; }

/* ─── ZONE TABLE ──────────────────────────────────── */
.zone-section th { background: var(--bg2); }

/* ─── DIVIDER ─────────────────────────────────────── */
.divider { height: 1px; background: var(--border); margin: 22px 0; }

/* ─── SCROLLBAR ───────────────────────────────────── */
::-webkit-scrollbar { width: 6px; height: 6px; }
::-webkit-scrollbar-track { background: var(--bg); }
::-webkit-scrollbar-thumb { background: var(--border2); border-radius: 3px; }
::-webkit-scrollbar-thumb:hover { background: var(--border3); }

/* ─── MOBILE ──────────────────────────────────────── */
@media(max-width:960px){
  :root { --sidebar-w: 220px; }
  .main { padding: 20px; }
  .stat-grid { grid-template-columns: repeat(2,1fr); }
  .form-grid { grid-template-columns: repeat(2,1fr); }
  .form-grid-4 { grid-template-columns: repeat(2,1fr); }
}
@media(max-width:680px){
  :root { --sidebar-w: 0px; }
  .sidebar { transform: translateX(-100%); }
  .stat-grid,.form-grid,.form-grid-2,.form-grid-4 { grid-template-columns: 1fr; }
}

/* ─── REPORT SUMMARY ROW ──────────────────────────── */
.summary-row td { font-weight: 700; background: var(--surface2) !important; color: var(--lime); font-family: var(--font-mono); border-top: 2px solid var(--border2); }
</style>
</head>
<body>

<!-- ── SIDEBAR ───────────────────────────────────────── -->
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

<!-- ── MAIN ──────────────────────────────────────────── -->
<main class="main">

<?php if($msg): ?>
<div class="flash"><div class="flash-icon">✓</div><?= h($msg) ?></div>
<?php endif; ?>

<?php
$prev = date('Y-m', strtotime($p.'-01 -1 month'));
$next = date('Y-m', strtotime($p.'-01 +1 month'));

/* ════════════════════════════════════════════════════
   DASHBOARD
════════════════════════════════════════════════════ */
if($v==='dashboard'):
?>
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
  <div class="stat-card" data-color="lime">
    <div class="stat-icon">⚽</div>
    <div class="stat-label">Active Athletes</div>
    <div class="stat-value"><?= $stats['athletes'] ?></div>
  </div>
  <div class="stat-card" data-color="blue">
    <div class="stat-icon">👤</div>
    <div class="stat-label">Active Staff</div>
    <div class="stat-value"><?= $stats['staff'] ?></div>
  </div>
  <div class="stat-card" data-color="teal">
    <div class="stat-icon">💰</div>
    <div class="stat-label">Revenue <?= h($p) ?></div>
    <div class="stat-value" style="font-size:18px"><?= money($stats['revenue']) ?></div>
  </div>
  <div class="stat-card" data-color="amber">
    <div class="stat-icon">⏳</div>
    <div class="stat-label">Outstanding</div>
    <div class="stat-value" style="font-size:18px"><?= money($stats['outstanding']) ?></div>
  </div>
  <div class="stat-card" data-color="red">
    <div class="stat-icon">📤</div>
    <div class="stat-label">Expenses</div>
    <div class="stat-value" style="font-size:18px"><?= money($stats['expenses']) ?></div>
  </div>
  <div class="stat-card" data-color="purple">
    <div class="stat-icon">💳</div>
    <div class="stat-label">Payroll Paid</div>
    <div class="stat-value" style="font-size:18px"><?= money($stats['payroll']) ?></div>
  </div>
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
      <td><strong style="font-family:var(--font-display)"><?= h($r['name']) ?></strong></td>
      <td><span style="font-family:var(--font-mono);color:var(--blue)"><?= $r['athletes'] ?></span></td>
      <td><span style="font-family:var(--font-mono);color:var(--text2)"><?= $r['staff'] ?></span></td>
      <td><span style="font-family:var(--font-mono);color:var(--lime)"><?= money($r['revenue']) ?></span></td>
      <td><span style="font-family:var(--font-mono);color:var(--red)"><?= money($r['expenses']) ?></span></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>

<?php /* Quick unpaid summary */
$unpaid=$pdo->query("SELECT COUNT(*) FROM monthly_bills WHERE period='$p' AND paid_amount=0 AND expected_amount>0")->fetchColumn();
$partial=$pdo->query("SELECT COUNT(*) FROM monthly_bills WHERE period='$p' AND paid_amount>0 AND paid_amount<expected_amount")->fetchColumn();
?>
<div class="card">
  <div class="card-corner"></div>
  <div class="card-header">
    <div class="card-title"><span class="card-title-bar"></span>Billing Snapshot — <?= h($p) ?></div>
    <a href="?view=payments&period=<?= h($p) ?>" class="btn btn-ghost btn-sm">View Billing →</a>
  </div>
  <div style="display:flex;gap:14px;flex-wrap:wrap">
    <div style="background:var(--red-dim);border:1px solid rgba(255,79,107,0.2);border-radius:var(--radius-sm);padding:16px 22px;flex:1;min-width:140px">
      <div class="stat-label">Unpaid</div>
      <div style="font-family:var(--font-display);font-size:28px;font-weight:700;color:var(--red)"><?= $unpaid ?></div>
    </div>
    <div style="background:var(--amber-dim);border:1px solid rgba(255,183,64,0.2);border-radius:var(--radius-sm);padding:16px 22px;flex:1;min-width:140px">
      <div class="stat-label">Partial</div>
      <div style="font-family:var(--font-display);font-size:28px;font-weight:700;color:var(--amber)"><?= $partial ?></div>
    </div>
    <div style="background:var(--lime-dim);border:1px solid rgba(198,241,53,0.2);border-radius:var(--radius-sm);padding:16px 22px;flex:1;min-width:140px">
      <div class="stat-label">Net Income</div>
      <div style="font-family:var(--font-display);font-size:20px;font-weight:700;color:var(--lime)"><?= money((float)$stats['revenue']-(float)$stats['expenses']-(float)$stats['payroll']) ?></div>
    </div>
  </div>
</div>

<?php endif; ?>

<?php /* ════════════════════════════════════════════════════
   MEMBERS / ATHLETES
════════════════════════════════════════════════════ */
if($v==='members'): ?>
<div class="page-header">
  <div>
    <div class="page-title"><?= $edit_member ? 'Edit <em>Athlete</em>' : 'Athletes <em>Registry</em>' ?></div>
    <div class="page-sub"><?= count($m) ?> total registered · <?= count($am) ?> active</div>
  </div>
</div>

<div class="card">
  <div class="card-corner"></div>
  <div class="card-header">
    <div class="card-title"><span class="card-title-bar"></span><?= $edit_member ? 'Edit Athlete' : 'Register New Athlete' ?></div>
  </div>
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
      <div class="form-group"><label>Notes</label><input name="notes" value="<?= h($edit_member['notes']??'') ?>" placeholder="Optional notes"></div>
    </div>
    <div class="form-actions">
      <button class="btn btn-primary" type="submit">💾 <?= $edit_member ? 'Update Athlete' : 'Save Athlete' ?></button>
      <?php if($edit_member): ?><a class="btn btn-ghost" href="?view=members&period=<?= h($p) ?>">✕ Cancel</a><?php endif; ?>
    </div>
  </form>
</div>

<div class="card">
  <div class="card-corner"></div>
  <div class="card-header">
    <div class="card-title"><span class="card-title-bar"></span>All Athletes</div>
  </div>
  <div class="toolbar">
    <div class="search-box">
      <span class="search-box-icon">🔍</span>
      <input type="text" id="memberSearch" placeholder="Search name, phone, zone, position, school…" oninput="filterTable('memberSearch','memberTbl','memberCnt')">
    </div>
    <select id="mZoneF" onchange="filterTable('memberSearch','memberTbl','memberCnt')">
      <option value="">All Zones</option>
      <?php foreach($z as $zone): ?><option value="<?= h($zone['name']) ?>"><?= h($zone['name']) ?></option><?php endforeach; ?>
    </select>
    <select id="mStatF" onchange="filterTable('memberSearch','memberTbl','memberCnt')">
      <option value="">All Status</option>
      <option value="Active">Active</option>
      <option value="Inactive">Inactive</option>
    </select>
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
          <div style="width:32px;height:32px;border-radius:10px;background:var(--lime-dim);border:1px solid rgba(198,241,53,0.15);display:flex;align-items:center;justify-content:center;font-family:var(--font-display);font-size:12px;font-weight:700;color:var(--lime);flex-shrink:0;text-transform:uppercase"><?= mb_substr($x['full_name'],0,1) ?></div>
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

<?php /* ════════════════════════════════════════════════════
   ATTENDANCE
════════════════════════════════════════════════════ */
if($v==='attendance'):
// Build members JSON for autocomplete
$membersJson = json_encode(array_map(fn($x)=>['id'=>$x['id'],'name'=>$x['full_name'],'zone'=>$x['zone_name'],'phone'=>$x['phone'],'position'=>$x['position']],$am));
?>
<div class="page-header">
  <div>
    <div class="page-title">Attendance <em>Tracker</em></div>
    <div class="page-sub"><?= count($s) ?> sessions recorded</div>
  </div>
</div>

<div class="card">
  <div class="card-corner"></div>
  <div class="card-header">
    <div class="card-title"><span class="card-title-bar"></span><?= $edit_session ? 'Edit Session' : 'Create Session' ?></div>
  </div>
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
      <?php if($edit_session): ?><a class="btn btn-ghost" href="?view=attendance&period=<?= h($p) ?>">✕ Cancel</a><?php endif; ?>
    </div>
  </form>
</div>

<div class="card">
  <div class="card-corner"></div>
  <div class="card-header">
    <div class="card-title"><span class="card-title-bar"></span>Record Attendance</div>
  </div>
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
          <input type="text" id="attAthleteSearch" placeholder="Type athlete name to search…" autocomplete="off">
          <div class="autocomplete-dropdown" id="attDropdown"></div>
        </div>
        <div class="selected-athlete-info" id="attSelectedInfo">
          <div class="sa-avatar" id="attSelAvatar"></div>
          <div>
            <div class="sa-name" id="attSelName"></div>
            <div class="sa-detail" id="attSelDetail"></div>
          </div>
        </div>
      </div>
      <div class="form-group">
        <label>Status</label>
        <select name="status">
          <option value="present">✓ Present</option>
          <option value="absent">✗ Absent</option>
          <option value="late">◷ Late</option>
        </select>
      </div>
    </div>
    <div class="form-actions">
      <button class="btn btn-primary" type="submit" id="attSubmitBtn" disabled>✓ Save Attendance</button>
      <span style="font-size:12px;color:var(--muted);font-family:var(--font-mono)" id="attHint">Search and select an athlete above</span>
    </div>
  </form>
</div>

<div class="card">
  <div class="card-corner"></div>
  <div class="card-header">
    <div class="card-title"><span class="card-title-bar"></span>Sessions</div>
  </div>
  <div class="toolbar">
    <div class="search-box">
      <span class="search-box-icon">🔍</span>
      <input type="text" id="sessionSearch" placeholder="Search sessions by name, date, zone…" oninput="filterTable('sessionSearch','sessionTbl','sessionCnt')">
    </div>
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
(function(){
  const members = <?= $membersJson ?>;
  const searchInput = document.getElementById('attAthleteSearch');
  const dropdown = document.getElementById('attDropdown');
  const memberIdInput = document.getElementById('att_member_id');
  const selectedInfo = document.getElementById('attSelectedInfo');
  const selAvatar = document.getElementById('attSelAvatar');
  const selName = document.getElementById('attSelName');
  const selDetail = document.getElementById('attSelDetail');
  const submitBtn = document.getElementById('attSubmitBtn');
  const hint = document.getElementById('attHint');
  let focusedIndex = -1;

  function renderDropdown(items){
    dropdown.innerHTML = '';
    if(items.length === 0){
      dropdown.innerHTML = '<div class="ac-empty">No athletes found</div>';
    } else {
      items.slice(0,10).forEach((m,i)=>{
        const div = document.createElement('div');
        div.className = 'ac-item';
        div.dataset.id = m.id;
        const initials = m.name.split(' ').slice(0,2).map(w=>w[0]).join('').toUpperCase();
        div.innerHTML = `
          <div class="ac-avatar">${initials}</div>
          <div class="ac-info">
            <div class="ac-name">${escHtml(m.name)}</div>
            <div class="ac-meta">${escHtml(m.zone||'')}${m.position?' · '+escHtml(m.position):''}</div>
          </div>
          <div class="ac-badge">${escHtml(m.phone||'')}</div>`;
        div.addEventListener('mousedown',()=>selectAthlete(m));
        dropdown.appendChild(div);
      });
    }
    dropdown.classList.add('open');
    focusedIndex = -1;
  }

  function selectAthlete(m){
    searchInput.value = m.name;
    memberIdInput.value = m.id;
    dropdown.classList.remove('open');
    selectedInfo.classList.add('visible');
    const initials = m.name.split(' ').slice(0,2).map(w=>w[0]).join('').toUpperCase();
    selAvatar.textContent = initials;
    selName.textContent = m.name;
    selDetail.textContent = (m.zone||'') + (m.position?' · '+m.position:'') + (m.phone?' · '+m.phone:'');
    submitBtn.disabled = false;
    hint.textContent = 'Athlete selected — choose status and save';
  }

  function escHtml(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

  searchInput.addEventListener('input',function(){
    const q = this.value.toLowerCase().trim();
    memberIdInput.value = '';
    selectedInfo.classList.remove('visible');
    submitBtn.disabled = true;
    hint.textContent = 'Search and select an athlete above';
    if(q.length < 1){ dropdown.classList.remove('open'); return; }
    const filtered = members.filter(m=>m.name.toLowerCase().includes(q) || (m.phone||'').includes(q) || (m.zone||'').toLowerCase().includes(q));
    renderDropdown(filtered);
  });

  searchInput.addEventListener('keydown',function(e){
    const items = dropdown.querySelectorAll('.ac-item');
    if(e.key==='ArrowDown'){
      e.preventDefault();
      focusedIndex = Math.min(focusedIndex+1, items.length-1);
      items.forEach((el,i)=>el.classList.toggle('focused',i===focusedIndex));
    } else if(e.key==='ArrowUp'){
      e.preventDefault();
      focusedIndex = Math.max(focusedIndex-1, 0);
      items.forEach((el,i)=>el.classList.toggle('focused',i===focusedIndex));
    } else if(e.key==='Enter' && focusedIndex>=0){
      e.preventDefault();
      const id = parseInt(items[focusedIndex].dataset.id);
      const m = members.find(x=>x.id===id);
      if(m) selectAthlete(m);
    } else if(e.key==='Escape'){
      dropdown.classList.remove('open');
    }
  });

  document.addEventListener('click',function(e){
    if(!document.getElementById('attAcWrap').contains(e.target)){
      dropdown.classList.remove('open');
    }
  });

  document.getElementById('attendanceForm').addEventListener('submit',function(e){
    if(!memberIdInput.value){ e.preventDefault(); alert('Please select an athlete first.'); }
  });
})();
</script>

<?php endif; ?>

<?php /* ════════════════════════════════════════════════════
   PAYMENTS / BILLING
════════════════════════════════════════════════════ */
if($v==='payments'):
$membersJson = json_encode(array_map(fn($x)=>['id'=>$x['id'],'name'=>$x['full_name'],'zone'=>$x['zone_name'],'phone'=>$x['phone'],'fee'=>$x['monthly_fee']],$am));
?>
<div class="page-header">
  <div>
    <div class="page-title">Billing <em>&amp; Payments</em></div>
    <div class="page-sub">Period: <?= h($p) ?></div>
  </div>
  <div class="period-nav">
    <a href="?view=payments&period=<?= $prev ?>">← Prev</a>
    <span class="cur"><?= h($p) ?></span>
    <a href="?view=payments&period=<?= $next ?>">Next →</a>
  </div>
</div>

<div class="card">
  <div class="card-corner"></div>
  <div class="card-header">
    <div class="card-title"><span class="card-title-bar"></span>Record Payment</div>
  </div>
  <form method="POST" id="paymentForm">
    <input type="hidden" name="action" value="payment">
    <input type="hidden" name="member_id" id="pay_member_id" value="">
    <div class="form-grid">
      <div class="form-group">
        <label>Search Athlete *</label>
        <div class="autocomplete-wrap" id="payAcWrap">
          <input type="text" id="payAthleteSearch" placeholder="Type athlete name to search…" autocomplete="off" required>
          <div class="autocomplete-dropdown" id="payDropdown"></div>
        </div>
        <div class="selected-athlete-info" id="paySelectedInfo">
          <div class="sa-avatar" id="paySelAvatar"></div>
          <div>
            <div class="sa-name" id="paySelName"></div>
            <div class="sa-detail" id="paySelDetail"></div>
          </div>
        </div>
      </div>
      <div class="form-group"><label>Amount (RWF) *</label><input type="number" name="amount" id="payAmount" required placeholder="0"></div>
      <div class="form-group"><label>Period</label><input name="period" value="<?= h($p) ?>"></div>
      <div class="form-group"><label>Note</label><input name="note" placeholder="Optional reference / receipt no."></div>
    </div>
    <div class="form-actions">
      <button class="btn btn-primary" type="submit" id="paySubmitBtn" disabled>💳 Record Payment</button>
      <span style="font-size:12px;color:var(--muted);font-family:var(--font-mono)" id="payHint">Search and select an athlete above</span>
    </div>
  </form>
</div>

<script>
(function(){
  const members = <?= $membersJson ?>;
  const searchInput = document.getElementById('payAthleteSearch');
  const dropdown = document.getElementById('payDropdown');
  const memberIdInput = document.getElementById('pay_member_id');
  const selectedInfo = document.getElementById('paySelectedInfo');
  const selAvatar = document.getElementById('paySelAvatar');
  const selName = document.getElementById('paySelName');
  const selDetail = document.getElementById('paySelDetail');
  const submitBtn = document.getElementById('paySubmitBtn');
  const hint = document.getElementById('payHint');
  const amountInput = document.getElementById('payAmount');
  let focusedIndex = -1;

  function escHtml(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
  function fmtMoney(v){ return Number(v).toLocaleString()+' RWF'; }

  function renderDropdown(items){
    dropdown.innerHTML = '';
    if(items.length === 0){
      dropdown.innerHTML = '<div class="ac-empty">No athletes found</div>';
    } else {
      items.slice(0,10).forEach((m,i)=>{
        const div = document.createElement('div');
        div.className = 'ac-item';
        div.dataset.id = m.id;
        div.dataset.fee = m.fee;
        const initials = m.name.split(' ').slice(0,2).map(w=>w[0]).join('').toUpperCase();
        div.innerHTML = `
          <div class="ac-avatar">${initials}</div>
          <div class="ac-info">
            <div class="ac-name">${escHtml(m.name)}</div>
            <div class="ac-meta">${escHtml(m.zone||'')}${m.phone?' · '+escHtml(m.phone):''}</div>
          </div>
          <div class="ac-badge">${fmtMoney(m.fee)}/mo</div>`;
        div.addEventListener('mousedown',()=>selectAthlete(m));
        dropdown.appendChild(div);
      });
    }
    dropdown.classList.add('open');
    focusedIndex = -1;
  }

  function selectAthlete(m){
    searchInput.value = m.name;
    memberIdInput.value = m.id;
    dropdown.classList.remove('open');
    selectedInfo.classList.add('visible');
    const initials = m.name.split(' ').slice(0,2).map(w=>w[0]).join('').toUpperCase();
    selAvatar.textContent = initials;
    selName.textContent = m.name;
    selDetail.textContent = (m.zone||'') + (m.phone?' · '+m.phone:'') + ' · Fee: '+fmtMoney(m.fee)+'/month';
    if(m.fee > 0) amountInput.value = m.fee;
    submitBtn.disabled = false;
    hint.textContent = 'Athlete selected — enter amount and save';
  }

  searchInput.addEventListener('input',function(){
    const q = this.value.toLowerCase().trim();
    memberIdInput.value = '';
    selectedInfo.classList.remove('visible');
    submitBtn.disabled = true;
    hint.textContent = 'Search and select an athlete above';
    if(q.length < 1){ dropdown.classList.remove('open'); return; }
    const filtered = members.filter(m=>m.name.toLowerCase().includes(q)||(m.phone||'').includes(q)||(m.zone||'').toLowerCase().includes(q));
    renderDropdown(filtered);
  });

  searchInput.addEventListener('keydown',function(e){
    const items = dropdown.querySelectorAll('.ac-item');
    if(e.key==='ArrowDown'){ e.preventDefault(); focusedIndex=Math.min(focusedIndex+1,items.length-1); items.forEach((el,i)=>el.classList.toggle('focused',i===focusedIndex)); }
    else if(e.key==='ArrowUp'){ e.preventDefault(); focusedIndex=Math.max(focusedIndex-1,0); items.forEach((el,i)=>el.classList.toggle('focused',i===focusedIndex)); }
    else if(e.key==='Enter'&&focusedIndex>=0){ e.preventDefault(); const id=parseInt(items[focusedIndex].dataset.id); const m=members.find(x=>x.id===id); if(m)selectAthlete(m); }
    else if(e.key==='Escape'){ dropdown.classList.remove('open'); }
  });

  document.addEventListener('click',function(e){
    if(!document.getElementById('payAcWrap').contains(e.target)) dropdown.classList.remove('open');
  });

  document.getElementById('paymentForm').addEventListener('submit',function(e){
    if(!memberIdInput.value){ e.preventDefault(); alert('Please select an athlete first.'); }
  });
})();
</script>

<div class="card">
  <div class="card-corner"></div>
  <div class="card-header">
    <div class="card-title"><span class="card-title-bar"></span>Billing Status — <?= h($p) ?></div>
  </div>
  <div class="toolbar">
    <div class="search-box">
      <span class="search-box-icon">🔍</span>
      <input type="text" id="billSearch" placeholder="Search by athlete, zone, status…" oninput="filterTable('billSearch','billTbl','billCnt')">
    </div>
    <select id="bStatF" onchange="filterTable('billSearch','billTbl','billCnt')">
      <option value="">All Status</option>
      <option value="PAID">Paid</option>
      <option value="PARTIAL">Partial</option>
      <option value="UNPAID">Unpaid</option>
      <option value="NO BILL">No Bill</option>
    </select>
    <select id="bZoneF" onchange="filterTable('billSearch','billTbl','billCnt')">
      <option value="">All Zones</option>
      <?php foreach($z as $zone): ?><option value="<?= h($zone['name']) ?>"><?= h($zone['name']) ?></option><?php endforeach; ?>
    </select>
  </div>
  <div class="result-count" id="billCnt"></div>
  <div class="table-wrap">
  <table id="billTbl">
    <thead><tr><th>Athlete</th><th>Zone</th><th>Expected</th><th>Paid</th><th>Remaining</th><th>Due Date</th><th>Status</th><th>Overdue</th></tr></thead>
    <tbody>
    <?php foreach(billing_rows($pdo,$p) as $b): $stt=bill_status($b['expected_amount'],$b['paid_amount']); $od=overdue($b['due_date'],$stt); ?>
    <tr>
      <td><strong><?= h($b['full_name']) ?></strong></td>
      <td><span class="badge b-zone"><?= h($b['zone_name']) ?></span></td>
      <td style="font-family:var(--font-mono);color:var(--text2)"><?= money($b['expected_amount']) ?></td>
      <td style="font-family:var(--font-mono);color:var(--lime)"><?= money($b['paid_amount']) ?></td>
      <td style="font-family:var(--font-mono);color:<?= $b['remaining']>0?'var(--amber)':'var(--muted)' ?>"><?= money($b['remaining']) ?></td>
      <td style="font-family:var(--font-mono);font-size:12px;color:var(--muted)"><?= h($b['due_date']) ?></td>
      <td><span class="badge <?= $stt==='PAID'?'b-paid':($stt==='PARTIAL'?'b-partial':($stt==='UNPAID'?'b-unpaid':'b-nobill')) ?>"><?= $stt ?></span></td>
      <td><span class="overdue <?= $od>0?'over':'ok' ?>"><?= $od>0?$od.'d':'-' ?></span></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>

<?php endif; ?>

<?php /* ════════════════════════════════════════════════════
   STAFF
════════════════════════════════════════════════════ */
if($v==='staff'): ?>
<div class="page-header">
  <div>
    <div class="page-title">Staff <em>Management</em></div>
    <div class="page-sub"><?= count($st) ?> total staff</div>
  </div>
</div>

<div class="card">
  <div class="card-corner"></div>
  <div class="card-header">
    <div class="card-title"><span class="card-title-bar"></span><?= $edit_staff ? 'Edit Staff Member' : 'Add Staff Member' ?></div>
  </div>
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
      <button class="btn btn-primary" type="submit">💾 <?= $edit_staff ? 'Update Staff' : 'Save Staff' ?></button>
      <?php if($edit_staff): ?><a class="btn btn-ghost" href="?view=staff&period=<?= h($p) ?>">✕ Cancel</a><?php endif; ?>
    </div>
  </form>
</div>

<div class="card">
  <div class="card-corner"></div>
  <div class="card-header">
    <div class="card-title"><span class="card-title-bar"></span>Staff Directory</div>
  </div>
  <div class="toolbar">
    <div class="search-box">
      <span class="search-box-icon">🔍</span>
      <input type="text" id="staffSearch" placeholder="Search name, role, zone, phone…" oninput="filterTable('staffSearch','staffTbl','staffCnt')">
    </div>
    <select id="stRoleF" onchange="filterTable('staffSearch','staffTbl','staffCnt')">
      <option value="">All Roles</option>
      <?php foreach(['coach','assistant_coach','manager','accountant'] as $role): ?>
      <option value="<?= $role ?>"><?= $role ?></option>
      <?php endforeach; ?>
    </select>
    <select id="stZoneF" onchange="filterTable('staffSearch','staffTbl','staffCnt')">
      <option value="">All Zones</option>
      <?php foreach($z as $zone): ?><option value="<?= h($zone['name']) ?>"><?= h($zone['name']) ?></option><?php endforeach; ?>
    </select>
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

<?php /* ════════════════════════════════════════════════════
   PAYROLL
════════════════════════════════════════════════════ */
if($v==='payroll'): ?>
<div class="page-header">
  <div>
    <div class="page-title">Coach <em>Payroll</em></div>
    <div class="page-sub">Period: <?= h($p) ?></div>
  </div>
  <div class="period-nav">
    <a href="?view=payroll&period=<?= $prev ?>">← Prev</a>
    <span class="cur"><?= h($p) ?></span>
    <a href="?view=payroll&period=<?= $next ?>">Next →</a>
  </div>
</div>

<div class="card">
  <div class="card-corner"></div>
  <div class="card-header">
    <div class="card-title"><span class="card-title-bar"></span>Add / Update Payroll Entry</div>
  </div>
  <form method="POST">
    <input type="hidden" name="action" value="payroll">
    <div class="form-grid">
      <div class="form-group"><label>Staff Member</label>
        <select name="staff_id">
          <?php foreach($st as $x): ?><option value="<?= $x['id'] ?>"><?= h($x['full_name'].' ['.$x['zone_name'].']') ?></option><?php endforeach; ?>
        </select>
      </div>
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
  <div class="card-corner"></div>
  <div class="card-header">
    <div class="card-title"><span class="card-title-bar"></span>Payroll — <?= h($p) ?></div>
  </div>
  <div class="toolbar">
    <div class="search-box">
      <span class="search-box-icon">🔍</span>
      <input type="text" id="payrollSearch" placeholder="Search staff name, zone, status…" oninput="filterTable('payrollSearch','payrollTbl','payrollCnt')">
    </div>
  </div>
  <div class="result-count" id="payrollCnt"></div>
  <div class="table-wrap">
  <table id="payrollTbl">
    <thead><tr><th>Staff</th><th>Zone</th><th>Base</th><th>Bonus</th><th>Deductions</th><th>Net Salary</th><th>Paid</th><th>Status</th></tr></thead>
    <tbody>
    <?php
    $pay=$pdo->query("SELECT c.*,s.full_name,z.name zone_name FROM coach_payroll c JOIN staff s ON s.id=c.staff_id LEFT JOIN academy_zones z ON z.id=s.zone_id WHERE c.period='$p' ORDER BY z.id,s.full_name")->fetchAll();
    $totBase=$totBonus=$totDed=$totNet=$totPaid=0;
    foreach($pay as $x):
      $totBase+=$x['base_salary'];$totBonus+=$x['bonus'];$totDed+=$x['deductions'];$totNet+=$x['net_salary'];$totPaid+=$x['amount_paid'];
    ?>
    <tr>
      <td><strong><?= h($x['full_name']) ?></strong></td>
      <td><span class="badge b-zone"><?= h($x['zone_name']) ?></span></td>
      <td style="font-family:var(--font-mono);color:var(--text2)"><?= money($x['base_salary']) ?></td>
      <td style="font-family:var(--font-mono);color:var(--lime)"><?= money($x['bonus']) ?></td>
      <td style="font-family:var(--font-mono);color:var(--red)"><?= money($x['deductions']) ?></td>
      <td style="font-family:var(--font-mono);font-weight:700;color:var(--text)"><?= money($x['net_salary']) ?></td>
      <td style="font-family:var(--font-mono);color:var(--lime)"><?= money($x['amount_paid']) ?></td>
      <td><span class="badge <?= $x['status']==='PAID'?'b-paid':($x['status']==='PARTIAL'?'b-partial':'b-unpaid') ?>"><?= h($x['status']) ?></span></td>
    </tr>
    <?php endforeach; ?>
    <?php if(!empty($pay)): ?>
    <tr class="summary-row">
      <td colspan="2">TOTALS</td>
      <td><?= money($totBase) ?></td>
      <td><?= money($totBonus) ?></td>
      <td><?= money($totDed) ?></td>
      <td><?= money($totNet) ?></td>
      <td><?= money($totPaid) ?></td>
      <td>—</td>
    </tr>
    <?php endif; ?>
    <?php if(empty($pay)): ?><tr><td colspan="8" class="no-data">No payroll records for <?= h($p) ?></td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</div>

<?php endif; ?>

<?php /* ════════════════════════════════════════════════════
   EXPENSES
════════════════════════════════════════════════════ */
if($v==='expenses'): ?>
<div class="page-header">
  <div>
    <div class="page-title">Expenses <em>Ledger</em></div>
    <div class="page-sub">Period: <?= h($p) ?></div>
  </div>
  <div class="period-nav">
    <a href="?view=expenses&period=<?= $prev ?>">← Prev</a>
    <span class="cur"><?= h($p) ?></span>
    <a href="?view=expenses&period=<?= $next ?>">Next →</a>
  </div>
</div>

<div class="card">
  <div class="card-corner"></div>
  <div class="card-header">
    <div class="card-title"><span class="card-title-bar"></span>Log New Expense</div>
  </div>
  <form method="POST">
    <input type="hidden" name="action" value="expense">
    <div class="form-grid">
      <div class="form-group"><label>Date</label><input type="date" name="expense_date" value="<?= date('Y-m-d') ?>"></div>
      <div class="form-group"><label>Zone</label><select name="zone_id"><?php foreach($z as $zone): ?><option value="<?= $zone['id'] ?>"><?= h($zone['name']) ?></option><?php endforeach; ?></select></div>
      <div class="form-group"><label>Category</label><input name="category" placeholder="e.g. Equipment, Utility, Travel"></div>
      <div class="form-group"><label>Description *</label><input name="description" required placeholder="What was this expense for?"></div>
      <div class="form-group"><label>Amount (RWF) *</label><input type="number" name="amount" required placeholder="0"></div>
      <div class="form-group"><label>Paid To</label><input name="paid_to" placeholder="Vendor / person name"></div>
      <div class="form-group"><label>Approved By</label><input name="approved_by" placeholder="Manager / supervisor"></div>
    </div>
    <div class="form-actions"><button class="btn btn-primary" type="submit">💾 Save Expense</button></div>
  </form>
</div>

<div class="card">
  <div class="card-corner"></div>
  <div class="card-header">
    <div class="card-title"><span class="card-title-bar"></span>Expense Records</div>
  </div>
  <?php $expenses = $pdo->query("SELECT e.*,z.name zone_name FROM expenses e LEFT JOIN academy_zones z ON z.id=e.zone_id ORDER BY e.expense_date DESC,e.id DESC")->fetchAll(); ?>
  <div class="toolbar">
    <div class="search-box">
      <span class="search-box-icon">🔍</span>
      <input type="text" id="expenseSearch" placeholder="Search description, category, zone, paid to…" oninput="filterTable('expenseSearch','expenseTbl','expenseCnt')">
    </div>
    <select id="eZoneF" onchange="filterTable('expenseSearch','expenseTbl','expenseCnt')">
      <option value="">All Zones</option>
      <?php foreach($z as $zone): ?><option value="<?= h($zone['name']) ?>"><?= h($zone['name']) ?></option><?php endforeach; ?>
    </select>
  </div>
  <div class="result-count" id="expenseCnt"></div>
  <div class="table-wrap">
  <table id="expenseTbl">
    <thead><tr><th>Date</th><th>Zone</th><th>Category</th><th>Description</th><th>Amount</th><th>Paid To</th><th>Approved</th></tr></thead>
    <tbody>
    <?php
    $totExp=0;
    foreach($expenses as $e): $totExp+=$e['amount']; ?>
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
    <tr class="summary-row">
      <td colspan="4">TOTAL EXPENSES</td>
      <td><?= money($totExp) ?></td>
      <td colspan="2">—</td>
    </tr>
    <?php endif; ?>
    <?php if(empty($expenses)): ?><tr><td colspan="7" class="no-data">No expenses recorded yet</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</div>

<?php endif; ?>


<?php /* ════════════════════════════════════════════════════
   UNIFORMS
════════════════════════════════════════════════════ */
if($v==='uniforms'):
$uniforms=$pdo->query("SELECT u.*,m.full_name,z.name zone_name FROM athlete_uniforms u JOIN members m ON m.id=u.member_id LEFT JOIN academy_zones z ON z.id=m.zone_id ORDER BY u.jersey_number ASC,m.full_name ASC")->fetchAll();
$totalQty=0; foreach($uniforms as $uu){ $totalQty += (int)$uu['quantity']; }
?>
<div class="page-header">
  <div>
    <div class="page-title"><?= $edit_uniform ? 'Edit <em>Uniform</em>' : 'Athlete <em>Uniforms</em>' ?></div>
    <div class="page-sub">Jersey numbers · Jersey sizes · Shorts sizes · Uniform report</div>
  </div>
  <a class="btn btn-ghost" href="?view=uniforms&period=<?= h($p) ?>&export=uniforms">Export CSV</a>
</div>

<div class="stat-grid">
  <div class="stat-card" data-color="lime"><div class="stat-icon">▤</div><div class="stat-label">Uniform Records</div><div class="stat-value"><?= count($uniforms) ?></div></div>
  <div class="stat-card" data-color="blue"><div class="stat-icon">#</div><div class="stat-label">Total Kits Qty</div><div class="stat-value"><?= $totalQty ?></div></div>
  <div class="stat-card" data-color="amber"><div class="stat-icon">👕</div><div class="stat-label">Active Athletes</div><div class="stat-value"><?= count($am) ?></div></div>
</div>

<div class="card">
  <div class="card-corner"></div>
  <div class="card-header"><div class="card-title"><span class="card-title-bar"></span><?= $edit_uniform ? 'Edit Uniform Data' : 'Insert Uniform Data' ?></div></div>
  <form method="POST">
    <input type="hidden" name="action" value="save_uniform">
    <input type="hidden" name="id" value="<?= h($edit_uniform['id']??'') ?>">
    <div class="form-grid">
      <div class="form-group"><label>Athlete *</label><select name="member_id" required><option value="">Select athlete</option><?php foreach($am as $x): ?><option value="<?= $x['id'] ?>" <?= (($edit_uniform['member_id']??'')==$x['id'])?'selected':'' ?>><?= h($x['full_name']) ?> — <?= h($x['zone_name']) ?></option><?php endforeach; ?></select></div>
      <div class="form-group"><label>Jersey Number *</label><input type="number" min="0" name="jersey_number" required value="<?= h($edit_uniform['jersey_number']??'') ?>" placeholder="e.g. 23"></div>
      <div class="form-group"><label>Quantity</label><input type="number" min="1" name="quantity" value="<?= h($edit_uniform['quantity']??1) ?>"></div>
      <div class="form-group"><label>Jersey Category *</label><select name="jersey_category" required><?php $jc=$edit_uniform['jersey_category']??''; foreach(['Adult Unisex V-Neck','Youth V-Neck','Women\'s Racerback','Girls Jersey','Reversible Adult','Reversible Women\'s','Reversible Youth'] as $opt): ?><option <?= $jc===$opt?'selected':'' ?>><?= h($opt) ?></option><?php endforeach; ?></select></div>
      <div class="form-group"><label>Jersey Size *</label><input name="jersey_size" required value="<?= h($edit_uniform['jersey_size']??'') ?>" placeholder="ML / YM / WXL"></div>
      <div class="form-group"><label>Jersey Chest</label><input type="number" step="0.01" name="jersey_chest" value="<?= h($edit_uniform['jersey_chest']??'') ?>" placeholder="23"></div>
      <div class="form-group"><label>Jersey Length</label><input type="number" step="0.01" name="jersey_length" value="<?= h($edit_uniform['jersey_length']??'') ?>" placeholder="29"></div>
      <div class="form-group"><label>Shorts Category *</label><select name="shorts_category" required><?php $sc=$edit_uniform['shorts_category']??''; foreach(['Adult Unisex Shorts','Women\'s Shorts','Youth Shorts'] as $opt): ?><option <?= $sc===$opt?'selected':'' ?>><?= h($opt) ?></option><?php endforeach; ?></select></div>
      <div class="form-group"><label>Shorts Size *</label><input name="shorts_size" required value="<?= h($edit_uniform['shorts_size']??'') ?>" placeholder="ML / YM / WXL"></div>
      <div class="form-group"><label>Shorts Waist</label><input type="number" step="0.01" name="shorts_waist" value="<?= h($edit_uniform['shorts_waist']??'') ?>" placeholder="15.5"></div>
      <div class="form-group"><label>Shorts Inseam</label><input type="number" step="0.01" name="shorts_inseam" value="<?= h($edit_uniform['shorts_inseam']??'') ?>" placeholder="10.75"></div>
      <div class="form-group"><label>Issued Date</label><input type="date" name="issued_date" value="<?= h($edit_uniform['issued_date']??date('Y-m-d')) ?>"></div>
      <div class="form-group"><label>Note</label><input name="note" value="<?= h($edit_uniform['note']??'') ?>" placeholder="Optional"></div>
    </div>
    <div class="form-actions">
      <button class="btn btn-primary" type="submit">💾 <?= $edit_uniform ? 'Update Uniform' : 'Save Uniform' ?></button>
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
    <thead><tr><th>No.</th><th>Athlete</th><th>Zone</th><th>Jersey Category</th><th>Jersey Size</th><th>Chest</th><th>Length</th><th>Shorts Category</th><th>Shorts Size</th><th>Waist</th><th>Inseam</th><th>Qty</th><th>Date</th><th>Actions</th></tr></thead>
    <tbody>
    <?php if(!$uniforms): ?><tr><td colspan="14" class="no-data">No uniform data inserted yet.</td></tr><?php endif; ?>
    <?php foreach($uniforms as $u): ?>
      <tr>
        <td><strong style="font-family:var(--font-display);color:var(--lime)"><?= h($u['jersey_number']) ?></strong></td>
        <td><strong><?= h($u['full_name']) ?></strong></td>
        <td><span class="badge b-zone"><?= h($u['zone_name']) ?></span></td>
        <td><?= h($u['jersey_category']) ?></td><td><?= h($u['jersey_size']) ?></td><td><?= h($u['jersey_chest']) ?></td><td><?= h($u['jersey_length']) ?></td>
        <td><?= h($u['shorts_category']) ?></td><td><?= h($u['shorts_size']) ?></td><td><?= h($u['shorts_waist']) ?></td><td><?= h($u['shorts_inseam']) ?></td>
        <td><?= h($u['quantity']) ?></td><td style="font-family:var(--font-mono);color:var(--text2)"><?= h($u['issued_date']) ?></td>
        <td><div class="actions-cell"><a class="btn btn-ghost btn-sm" href="?view=uniforms&period=<?= h($p) ?>&edit_uniform=<?= $u['id'] ?>">Edit</a><form method="POST" style="display:inline" onsubmit="return confirm('Delete this uniform record?')"><input type="hidden" name="action" value="delete_uniform"><input type="hidden" name="id" value="<?= $u['id'] ?>"><button class="btn btn-danger btn-sm" type="submit">Delete</button></form></div></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endif; ?>

<?php /* ════════════════════════════════════════════════════
   REPORTS
════════════════════════════════════════════════════ */
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

<!-- Financial Summary KPIs -->
<?php
$totalRev  = (float)$stats['revenue'];
$totalExp  = (float)$stats['expenses'];
$totalPay  = (float)$stats['payroll'];
$totalOut  = (float)$stats['outstanding'];
$netIncome = $totalRev - $totalExp - $totalPay;
$totalExp2 = $totalExp + $totalPay;
?>
<div class="stat-grid" style="margin-bottom:20px">
  <div class="stat-card" data-color="teal">
    <div class="stat-icon">📥</div>
    <div class="stat-label">Total Revenue</div>
    <div class="stat-value" style="font-size:20px"><?= money($totalRev) ?></div>
  </div>
  <div class="stat-card" data-color="red">
    <div class="stat-icon">📤</div>
    <div class="stat-label">Total Outgoings</div>
    <div class="stat-value" style="font-size:20px"><?= money($totalExp2) ?></div>
  </div>
  <div class="stat-card" data-color="<?= $netIncome>=0?'lime':'red' ?>">
    <div class="stat-icon"><?= $netIncome>=0?'📈':'📉' ?></div>
    <div class="stat-label">Net Income</div>
    <div class="stat-value" style="font-size:20px"><?= money($netIncome) ?></div>
  </div>
</div>

<!-- Zone Financial Report -->
<div class="card">
  <div class="card-corner"></div>
  <div class="card-header">
    <div class="card-title"><span class="card-title-bar"></span>Zone Financial Report — <?= h($p) ?></div>
  </div>
  <div class="toolbar">
    <div class="search-box">
      <span class="search-box-icon">🔍</span>
      <input type="text" id="zoneRepSearch" placeholder="Search zones…" oninput="filterTable('zoneRepSearch','zoneRepTbl','zoneRepCnt')">
    </div>
  </div>
  <div class="result-count" id="zoneRepCnt"></div>
  <div class="table-wrap">
  <table id="zoneRepTbl">
    <thead><tr><th>Zone</th><th>Expected</th><th>Collected</th><th>Remaining</th><th>Expenses</th><th>Payroll</th><th>Net</th></tr></thead>
    <tbody>
    <?php
    $r=$pdo->query("
    SELECT z.name,
    COALESCE(SUM(DISTINCT b.expected_amount),0) expected,
    COALESCE(SUM(DISTINCT b.paid_amount),0) paid,
    COALESCE(SUM(DISTINCT GREATEST(b.expected_amount-b.paid_amount,0)),0) remaining,
    COALESCE(SUM(DISTINCT e.amount),0) expenses,
    COALESCE(SUM(DISTINCT c.amount_paid),0) payroll
    FROM academy_zones z
    LEFT JOIN members m ON m.zone_id=z.id
    LEFT JOIN monthly_bills b ON b.member_id=m.id AND b.period='$p'
    LEFT JOIN expenses e ON e.zone_id=z.id AND TO_CHAR(e.expense_date,'YYYY-MM')='$p'
    LEFT JOIN staff st ON st.zone_id=z.id
    LEFT JOIN coach_payroll c ON c.staff_id=st.id AND c.period='$p'
    GROUP BY z.id,z.name ORDER BY z.id")->fetchAll();
    $gExp=$gPaid=$gRem=$gExpenses=$gPayroll=0;
    foreach($r as $x):
      $net=(float)$x['paid']-(float)$x['expenses']-(float)$x['payroll'];
      $gPaid+=$x['paid']; $gRem+=$x['remaining']; $gExpenses+=$x['expenses']; $gPayroll+=$x['payroll'];
    ?>
    <tr>
      <td><strong style="font-family:var(--font-display)"><?= h($x['name']) ?></strong></td>
      <td style="font-family:var(--font-mono);color:var(--text2)"><?= money($x['expected']) ?></td>
      <td style="font-family:var(--font-mono);color:var(--lime)"><?= money($x['paid']) ?></td>
      <td style="font-family:var(--font-mono);color:var(--amber)"><?= money($x['remaining']) ?></td>
      <td style="font-family:var(--font-mono);color:var(--red)"><?= money($x['expenses']) ?></td>
      <td style="font-family:var(--font-mono);color:var(--purple)"><?= money($x['payroll']) ?></td>
      <td style="font-family:var(--font-mono);font-weight:700;color:<?= $net>=0?'var(--lime)':'var(--red)' ?>"><?= money($net) ?></td>
    </tr>
    <?php endforeach; ?>
    <tr class="summary-row">
      <td>TOTAL</td>
      <td>—</td>
      <td><?= money($gPaid) ?></td>
      <td><?= money($gRem) ?></td>
      <td><?= money($gExpenses) ?></td>
      <td><?= money($gPayroll) ?></td>
      <td><?= money($gPaid-$gExpenses-$gPayroll) ?></td>
    </tr>
    </tbody>
  </table>
  </div>
</div>

<!-- Billing Status Summary -->
<?php
$bSummary=$pdo->query("
SELECT
  COUNT(*) FILTER (WHERE expected_amount>0 AND paid_amount>=expected_amount) AS paid_ct,
  COUNT(*) FILTER (WHERE paid_amount>0 AND paid_amount<expected_amount) AS partial_ct,
  COUNT(*) FILTER (WHERE expected_amount>0 AND paid_amount=0) AS unpaid_ct,
  COUNT(*) FILTER (WHERE expected_amount=0) AS nobill_ct
FROM monthly_bills WHERE period='$p'")->fetch();
$total_athletes=(int)$stats['athletes'];
?>
<div class="card">
  <div class="card-corner"></div>
  <div class="card-header">
    <div class="card-title"><span class="card-title-bar"></span>Payment Status Summary — <?= h($p) ?></div>
  </div>
  <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px">
    <div style="background:var(--lime-dim);border:1px solid rgba(198,241,53,0.2);border-radius:var(--radius-sm);padding:16px;text-align:center">
      <div class="stat-label" style="text-align:center">Paid</div>
      <div style="font-family:var(--font-display);font-size:30px;font-weight:700;color:var(--lime)"><?= $bSummary['paid_ct']??0 ?></div>
    </div>
    <div style="background:var(--amber-dim);border:1px solid rgba(255,183,64,0.2);border-radius:var(--radius-sm);padding:16px;text-align:center">
      <div class="stat-label" style="text-align:center">Partial</div>
      <div style="font-family:var(--font-display);font-size:30px;font-weight:700;color:var(--amber)"><?= $bSummary['partial_ct']??0 ?></div>
    </div>
    <div style="background:var(--red-dim);border:1px solid rgba(255,79,107,0.2);border-radius:var(--radius-sm);padding:16px;text-align:center">
      <div class="stat-label" style="text-align:center">Unpaid</div>
      <div style="font-family:var(--font-display);font-size:30px;font-weight:700;color:var(--red)"><?= $bSummary['unpaid_ct']??0 ?></div>
    </div>
    <div style="background:var(--blue-dim);border:1px solid rgba(77,159,255,0.2);border-radius:var(--radius-sm);padding:16px;text-align:center">
      <div class="stat-label" style="text-align:center">No Bill</div>
      <div style="font-family:var(--font-display);font-size:30px;font-weight:700;color:var(--blue)"><?= $bSummary['nobill_ct']??0 ?></div>
    </div>
  </div>
</div>

<!-- Attendance Summary -->
<div class="card">
  <div class="card-corner"></div>
  <div class="card-header">
    <div class="card-title"><span class="card-title-bar"></span>Attendance Report</div>
  </div>
  <div class="toolbar">
    <div class="search-box">
      <span class="search-box-icon">🔍</span>
      <input type="text" id="attRepSearch" placeholder="Search sessions, zones…" oninput="filterTable('attRepSearch','attRepTbl','attRepCnt')">
    </div>
  </div>
  <div class="result-count" id="attRepCnt"></div>
  <div class="table-wrap">
  <table id="attRepTbl">
    <thead><tr><th>Session</th><th>Date</th><th>Zone</th><th>Present</th><th>Absent</th><th>Late</th><th>Total</th><th>Rate</th></tr></thead>
    <tbody>
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
    foreach($a as $x):
      $rate = ($x['total']>0)?round(((int)$x['present']+(int)$x['late'])/$x['total']*100):0;
    ?>
    <tr>
      <td><strong><?= h($x['name']) ?></strong></td>
      <td style="font-family:var(--font-mono);font-size:12px;color:var(--text2)"><?= h($x['session_date']) ?></td>
      <td><span class="badge b-zone"><?= h($x['zone']) ?></span></td>
      <td><span class="badge b-present"><?= $x['present']??0 ?></span></td>
      <td><span class="badge b-absent"><?= $x['absent']??0 ?></span></td>
      <td><span class="badge b-late"><?= $x['late']??0 ?></span></td>
      <td style="color:var(--muted);font-family:var(--font-mono)"><?= $x['total']??0 ?></td>
      <td>
        <div style="display:flex;align-items:center;gap:8px">
          <div style="flex:1;height:5px;background:var(--surface2);border-radius:3px;overflow:hidden;min-width:60px">
            <div style="width:<?= $rate ?>%;height:100%;background:<?= $rate>=80?'var(--lime)':($rate>=50?'var(--amber)':'var(--red)') ?>;border-radius:3px;transition:width 0.6s ease"></div>
          </div>
          <span style="font-family:var(--font-mono);font-size:11px;color:<?= $rate>=80?'var(--lime)':($rate>=50?'var(--amber)':'var(--red)') ?>;white-space:nowrap"><?= $rate ?>%</span>
        </div>
      </td>
    </tr>
    <?php endforeach; ?>
    <?php if(empty($a)): ?><tr><td colspan="8" class="no-data">No attendance records yet</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</div>

<!-- Payment Logs -->
<div class="card">
  <div class="card-corner"></div>
  <div class="card-header">
    <div class="card-title"><span class="card-title-bar"></span>Payment Logs (Latest 200)</div>
  </div>
  <div class="toolbar">
    <div class="search-box">
      <span class="search-box-icon">🔍</span>
      <input type="text" id="paylogSearch" placeholder="Search by athlete, period, note…" oninput="filterTable('paylogSearch','paylogTbl','paylogCnt')">
    </div>
  </div>
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
      <td style="font-family:var(--font-mono);color:var(--text2)"><?= h($l['period']) ?></td>
      <td style="font-family:var(--font-mono);color:var(--lime)"><?= money($l['amount_paid']) ?></td>
      <td style="color:var(--muted)"><?= h($l['note']) ?></td>
    </tr>
    <?php endforeach; ?>
    <?php if(!empty($logs)): ?>
    <tr class="summary-row">
      <td colspan="3">TOTAL COLLECTED (logs shown)</td>
      <td><?= money($totLogs) ?></td>
      <td>—</td>
    </tr>
    <?php endif; ?>
    <?php if(empty($logs)): ?><tr><td colspan="5" class="no-data">No payment logs yet</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</div>

<?php endif; ?>

</main>

<script>
/**
 * Universal table search + dropdown filter
 */
function filterTable(searchId, tableId, countId) {
  const searchInput = document.getElementById(searchId);
  const table = document.getElementById(tableId);
  const countEl = document.getElementById(countId);
  if(!table) return;

  const query = searchInput ? searchInput.value.toLowerCase().trim() : '';
  const card = table.closest('.card');
  const filterSelects = card ? card.querySelectorAll('select[id$="F"]') : [];

  const rows = table.querySelectorAll('tbody tr:not(.summary-row):not(.no-results-dyn)');
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

  if(countEl) {
    const total = rows.length;
    countEl.textContent = (query || [...filterSelects].some(s=>s.value))
      ? `Showing ${visible} of ${total} records`
      : `${total} record${total!==1?'s':''}`;
  }

  // Dynamic no-results row
  let noRes = table.querySelector('.no-results-dyn');
  if(visible === 0 && rows.length > 0) {
    if(!noRes) {
      const colspan = table.querySelector('thead tr')?.children.length || 6;
      const tr = document.createElement('tr');
      tr.className = 'no-results-dyn';
      tr.innerHTML = `<td colspan="${colspan}" class="no-data">No results match your search</td>`;
      table.querySelector('tbody').appendChild(tr);
    }
  } else {
    noRes?.remove();
  }
}

// Initialize all table counts on load
document.addEventListener('DOMContentLoaded', () => {
  const maps = [
    ['memberSearch','memberTbl','memberCnt'],
    ['sessionSearch','sessionTbl','sessionCnt'],
    ['billSearch','billTbl','billCnt'],
    ['staffSearch','staffTbl','staffCnt'],
    ['payrollSearch','payrollTbl','payrollCnt'],
    ['expenseSearch','expenseTbl','expenseCnt'],
    ['zoneRepSearch','zoneRepTbl','zoneRepCnt'],
    ['paylogSearch','paylogTbl','paylogCnt'],
    ['attRepSearch','attRepTbl','attRepCnt'],
  ];
  maps.forEach(([s,t,c]) => {
    if(document.getElementById(t)) filterTable(s,t,c);
  });
});
</script>
</body>
</html>
