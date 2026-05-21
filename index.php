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

INSERT INTO academy_zones(name,is_default) VALUES
('Gisenyi',TRUE),('Rugerero',FALSE),('Byahi',FALSE)
ON CONFLICT(name) DO NOTHING;

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
 notes TEXT,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
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
 id SERIAL PRIMARY KEY,
 name VARCHAR(255) NOT NULL,
 session_date DATE NOT NULL DEFAULT CURRENT_DATE,
 zone_id INT REFERENCES academy_zones(id),
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

ALTER TABLE sessions ADD COLUMN IF NOT EXISTS session_date DATE;
ALTER TABLE sessions ADD COLUMN IF NOT EXISTS zone_id INT REFERENCES academy_zones(id);
UPDATE sessions SET session_date = date WHERE session_date IS NULL AND EXISTS(
 SELECT 1 FROM information_schema.columns WHERE table_name='sessions' AND column_name='date'
);
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
 period CHAR(7) NOT NULL,
 note TEXT,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

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
ALTER TABLE staff DROP COLUMN IF EXISTS branch;
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
 status VARCHAR(30) DEFAULT 'UNPAID',
 paid_at TIMESTAMP,
 note TEXT,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(staff_id,period)
);

ALTER TABLE coach_payroll ADD COLUMN IF NOT EXISTS net_salary NUMERIC(12,2) DEFAULT 0;

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
    $stmt=$pdo->prepare("INSERT INTO monthly_bills(member_id,period,expected_amount,paid_amount,due_date)
    VALUES(?,?,?,?,?) ON CONFLICT(member_id,period) DO NOTHING");
    $stmt->execute([$member_id,$period,$m['monthly_fee']??0,0,$due]);
}
function billing_rows($pdo,$period){
    foreach(active_members($pdo) as $m) ensure_bill($pdo,$m['id'],$period);
    $stmt=$pdo->prepare("
    SELECT m.full_name,m.phone,z.name zone_name,b.*, 
    GREATEST(b.expected_amount-b.paid_amount,0) remaining
    FROM monthly_bills b 
    JOIN members m ON m.id=b.member_id
    LEFT JOIN academy_zones z ON z.id=m.zone_id
    WHERE b.period=?
    ORDER BY z.id,m.full_name");
    $stmt->execute([$period]);
    return $stmt->fetchAll();
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    $a=$_POST['action']??'';

    if($a==='save_member'){
        $id=$_POST['id']??'';
        $data=[
            $_POST['full_name'],$_POST['phone']?:null,$_POST['gender']?:null,$_POST['date_of_birth']?:null,
            $_POST['zone_id']?:default_zone($pdo),$_POST['guardian_name']?:null,$_POST['guardian_phone']?:null,
            $_POST['position']?:null,$_POST['school_name']?:null,$_POST['monthly_fee']?:0,$_POST['due_day']?:5,$_POST['notes']?:null
        ];
        if($id){
            $stmt=$pdo->prepare("UPDATE members SET full_name=?,phone=?,gender=?,date_of_birth=?,zone_id=?,guardian_name=?,guardian_phone=?,position=?,school_name=?,monthly_fee=?,due_day=?,notes=? WHERE id=?");
            $stmt->execute([...$data,$id]);
            go('members','Athlete updated');
        }else{
            $stmt=$pdo->prepare("INSERT INTO members(full_name,phone,gender,date_of_birth,zone_id,guardian_name,guardian_phone,position,school_name,monthly_fee,due_day,notes)
            VALUES(?,?,?,?,?,?,?,?,?,?,?,?) ON CONFLICT(full_name) DO NOTHING");
            $stmt->execute($data);
            go('members','Athlete added');
        }
    }

    if($a==='delete_member'){
        $pdo->prepare("UPDATE members SET is_active=FALSE WHERE id=?")->execute([$_POST['id']]);
        go('members','Athlete deactivated');
    }

    if($a==='save_session'){
        $id=$_POST['id']??'';
        if($id){
            $pdo->prepare("UPDATE sessions SET name=?,session_date=?,zone_id=? WHERE id=?")
                ->execute([$_POST['name'],$_POST['session_date'],$_POST['zone_id'],$id]);
            go('attendance','Session updated');
        }else{
            $pdo->prepare("INSERT INTO sessions(name,session_date,zone_id) VALUES(?,?,?)")
                ->execute([$_POST['name'],$_POST['session_date'],$_POST['zone_id']?:default_zone($pdo)]);
            go('attendance','Session created');
        }
    }

    if($a==='delete_session'){
        $pdo->prepare("DELETE FROM sessions WHERE id=?")->execute([$_POST['id']]);
        go('attendance','Session deleted');
    }

    if($a==='attendance'){
        $sid=$_POST['session_id'];$mid=$_POST['member_id'];$status=$_POST['status'];

        $check=$pdo->prepare("SELECT COUNT(*) FROM sessions s JOIN members m ON m.zone_id=s.zone_id WHERE s.id=? AND m.id=?");
        $check->execute([$sid,$mid]);
        if(!$check->fetchColumn()) go('attendance','Wrong zone: athlete does not belong to that session zone');

        $pdo->prepare("INSERT INTO attendance(session_id,member_id,status) VALUES(?,?,?)
        ON CONFLICT(session_id,member_id) DO UPDATE SET status=EXCLUDED.status")->execute([$sid,$mid,$status]);

        $pdo->prepare("
        INSERT INTO attendance(session_id,member_id,status)
        SELECT s.id,m.id,'absent'
        FROM sessions s JOIN members m ON m.zone_id=s.zone_id
        WHERE s.id=? AND m.is_active=TRUE
        AND NOT EXISTS(SELECT 1 FROM attendance a WHERE a.session_id=s.id AND a.member_id=m.id)
        ")->execute([$sid]);

        go('attendance','Attendance saved. Unmarked same-zone athletes became absent.');
    }

    if($a==='payment'){
        $mid=$_POST['member_id'];$amount=(float)$_POST['amount'];$per=$_POST['period'];
        ensure_bill($pdo,$mid,$per);
        $pdo->prepare("UPDATE monthly_bills SET paid_amount=paid_amount+?,paid_at=NOW(),updated_at=NOW(),note=? WHERE member_id=? AND period=?")
            ->execute([$amount,$_POST['note']?:null,$mid,$per]);
        $pdo->prepare("INSERT INTO payment_logs(member_id,amount_paid,period,note) VALUES(?,?,?,?)")
            ->execute([$mid,$amount,$per,$_POST['note']?:null]);
        go('payments','Payment recorded');
    }

    if($a==='save_staff'){
        $id=$_POST['id']??'';
        if($id){
            $pdo->prepare("UPDATE staff SET full_name=?,phone=?,role=?,zone_id=?,monthly_salary=? WHERE id=?")
                ->execute([$_POST['full_name'],$_POST['phone']?:null,$_POST['role'],$_POST['zone_id'],$_POST['monthly_salary']?:0,$id]);
            go('staff','Staff updated');
        }else{
            $pdo->prepare("INSERT INTO staff(full_name,phone,role,zone_id,monthly_salary) VALUES(?,?,?,?,?)")
                ->execute([$_POST['full_name'],$_POST['phone']?:null,$_POST['role'],$_POST['zone_id']?:default_zone($pdo),$_POST['monthly_salary']?:0]);
            go('staff','Staff added');
        }
    }

    if($a==='delete_staff'){
        $pdo->prepare("UPDATE staff SET is_active=FALSE WHERE id=?")->execute([$_POST['id']]);
        go('staff','Staff deactivated');
    }

    if($a==='payroll'){
        $net=(float)$_POST['base_salary']+(float)$_POST['bonus']-(float)$_POST['deductions'];
        $status=((float)$_POST['amount_paid']<=0)?'UNPAID':(((float)$_POST['amount_paid']<$net)?'PARTIAL':'PAID');
        $pdo->prepare("INSERT INTO coach_payroll(staff_id,period,base_salary,bonus,deductions,net_salary,amount_paid,status,paid_at,note)
        VALUES(?,?,?,?,?,?,?,?,NOW(),?)
        ON CONFLICT(staff_id,period) DO UPDATE SET base_salary=EXCLUDED.base_salary,bonus=EXCLUDED.bonus,deductions=EXCLUDED.deductions,net_salary=EXCLUDED.net_salary,amount_paid=EXCLUDED.amount_paid,status=EXCLUDED.status,paid_at=NOW(),note=EXCLUDED.note")
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
?>
<!DOCTYPE html>
<html>
<head>
<title>Academy AMS</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
body{margin:0;background:#090d14;color:#e5e7eb;font-family:Arial,sans-serif}
.sidebar{position:fixed;top:0;left:0;width:245px;height:100vh;background:#111827;padding:20px;box-sizing:border-box}
.logo{font-weight:900;font-size:22px;color:#22c55e;margin-bottom:25px}
.nav a{display:block;color:#cbd5e1;text-decoration:none;padding:12px;border-radius:12px;margin:5px 0}
.nav a.active,.nav a:hover{background:#1f2937;color:#22c55e}
.main{margin-left:245px;padding:26px}
.grid{display:grid;grid-template-columns:repeat(3,1fr);gap:14px}
.card{background:#111827;border:1px solid #1f2937;border-radius:18px;padding:18px;margin-bottom:18px}
.stat{font-size:27px;font-weight:900}
small,label{color:#94a3b8}
.formgrid{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}
input,select,textarea{width:100%;box-sizing:border-box;padding:11px;border-radius:10px;border:1px solid #334155;background:#0b1220;color:white}
button,.btn{display:inline-block;background:#22c55e;color:#031409;border:0;border-radius:10px;padding:10px 14px;font-weight:900;text-decoration:none;cursor:pointer}
.btn.red,button.red{background:#ef4444;color:white}
.btn.gray{background:#334155;color:white}
table{width:100%;border-collapse:collapse}th,td{padding:11px;border-bottom:1px solid #1f2937;text-align:left}th{color:#94a3b8;font-size:12px}
.badge{background:#052e16;color:#86efac;border-radius:999px;padding:5px 9px;font-size:12px}
.warn{background:#422006;color:#fcd34d}.bad{background:#450a0a;color:#fecaca}.ok{background:#052e16;color:#86efac}
.msg{background:#052e16;color:#86efac;padding:12px;border-radius:12px;margin-bottom:14px}
.actions{display:flex;gap:6px;flex-wrap:wrap}
@media(max-width:850px){.sidebar{position:relative;width:100%;height:auto}.main{margin-left:0}.grid,.formgrid{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="sidebar">
<div class="logo">🏀 Academy AMS</div>
<div class="nav">
<?php foreach(['dashboard'=>'Dashboard','members'=>'Athletes','attendance'=>'Attendance','payments'=>'Billing','staff'=>'Staff','payroll'=>'Payroll','expenses'=>'Expenses','reports'=>'Reports'] as $key=>$label): ?>
<a class="<?= $v===$key?'active':'' ?>" href="?view=<?= $key ?>&period=<?= h($p) ?>"><?= $label ?></a>
<?php endforeach; ?>
</div>
</div>

<div class="main">
<?php if($msg): ?><div class="msg"><?= h($msg) ?></div><?php endif; ?>

<?php if($v==='dashboard'): ?>
<h1>Dashboard — <?= h($p) ?></h1>
<div class="grid">
<div class="card"><small>Active Athletes</small><div class="stat"><?= $stats['athletes'] ?></div></div>
<div class="card"><small>Active Staff</small><div class="stat"><?= $stats['staff'] ?></div></div>
<div class="card"><small>Revenue</small><div class="stat"><?= money($stats['revenue']) ?></div></div>
<div class="card"><small>Outstanding</small><div class="stat"><?= money($stats['outstanding']) ?></div></div>
<div class="card"><small>Expenses</small><div class="stat"><?= money($stats['expenses']) ?></div></div>
<div class="card"><small>Payroll Paid</small><div class="stat"><?= money($stats['payroll']) ?></div></div>
</div>
<div class="card">
<h2>Zone Summary</h2>
<table><tr><th>Zone</th><th>Athletes</th><th>Staff</th><th>Revenue</th><th>Expenses</th></tr>
<?php
$rows=$pdo->query("
SELECT z.name,
COUNT(DISTINCT m.id) athletes,
COUNT(DISTINCT st.id) staff,
COALESCE(SUM(DISTINCT b.paid_amount),0) revenue,
COALESCE(SUM(DISTINCT e.amount),0) expenses
FROM academy_zones z
LEFT JOIN members m ON m.zone_id=z.id AND m.is_active=TRUE
LEFT JOIN staff st ON st.zone_id=z.id AND st.is_active=TRUE
LEFT JOIN monthly_bills b ON b.member_id=m.id AND b.period='$p'
LEFT JOIN expenses e ON e.zone_id=z.id AND TO_CHAR(e.expense_date,'YYYY-MM')='$p'
GROUP BY z.id,z.name ORDER BY z.id")->fetchAll();
foreach($rows as $r): ?>
<tr><td><?= h($r['name']) ?></td><td><?= $r['athletes'] ?></td><td><?= $r['staff'] ?></td><td><?= money($r['revenue']) ?></td><td><?= money($r['expenses']) ?></td></tr>
<?php endforeach; ?>
</table>
</div>
<?php endif; ?>

<?php if($v==='members'): ?>
<h1>Athletes</h1>
<div class="card">
<h2><?= $edit_member?'Edit Athlete':'Add Athlete' ?></h2>
<form method="POST">
<input type="hidden" name="action" value="save_member">
<input type="hidden" name="id" value="<?= h($edit_member['id']??'') ?>">
<div class="formgrid">
<div><label>Full Name</label><input name="full_name" required value="<?= h($edit_member['full_name']??'') ?>"></div>
<div><label>Phone</label><input name="phone" value="<?= h($edit_member['phone']??'') ?>"></div>
<div><label>Zone</label><select name="zone_id"><?php foreach($z as $zone): ?><option value="<?= $zone['id'] ?>" <?= (($edit_member['zone_id']??'')==$zone['id'])?'selected':'' ?>><?= h($zone['name']) ?></option><?php endforeach; ?></select></div>
<div><label>Gender</label><select name="gender"><option></option><option <?= (($edit_member['gender']??'')==='Male')?'selected':'' ?>>Male</option><option <?= (($edit_member['gender']??'')==='Female')?'selected':'' ?>>Female</option></select></div>
<div><label>Date of Birth</label><input type="date" name="date_of_birth" value="<?= h($edit_member['date_of_birth']??'') ?>"></div>
<div><label>Position</label><input name="position" value="<?= h($edit_member['position']??'') ?>"></div>
<div><label>Guardian</label><input name="guardian_name" value="<?= h($edit_member['guardian_name']??'') ?>"></div>
<div><label>Guardian Phone</label><input name="guardian_phone" value="<?= h($edit_member['guardian_phone']??'') ?>"></div>
<div><label>School</label><input name="school_name" value="<?= h($edit_member['school_name']??'') ?>"></div>
<div><label>Monthly Fee</label><input type="number" name="monthly_fee" value="<?= h($edit_member['monthly_fee']??0) ?>"></div>
<div><label>Due Day</label><input type="number" name="due_day" value="<?= h($edit_member['due_day']??5) ?>"></div>
<div><label>Notes</label><input name="notes" value="<?= h($edit_member['notes']??'') ?>"></div>
</div><br><button>Save Athlete</button>
</form>
</div>
<div class="card"><h2>List</h2><table><tr><th>Name</th><th>Zone</th><th>Phone</th><th>Fee</th><th>Status</th><th>Actions</th></tr>
<?php foreach($m as $x): ?><tr>
<td><?= h($x['full_name']) ?></td><td><span class="badge"><?= h($x['zone_name']) ?></span></td><td><?= h($x['phone']) ?></td><td><?= money($x['monthly_fee']) ?></td><td><?= $x['is_active']?'Active':'Inactive' ?></td>
<td class="actions"><a class="btn gray" href="?view=members&period=<?= h($p) ?>&edit_member=<?= $x['id'] ?>">Edit</a><form method="POST"><input type="hidden" name="action" value="delete_member"><input type="hidden" name="id" value="<?= $x['id'] ?>"><button class="red">Deactivate</button></form></td>
</tr><?php endforeach; ?></table></div>
<?php endif; ?>

<?php if($v==='attendance'): ?>
<h1>Attendance</h1>
<div class="card"><h2><?= $edit_session?'Edit Session':'Create Session' ?></h2>
<form method="POST"><input type="hidden" name="action" value="save_session"><input type="hidden" name="id" value="<?= h($edit_session['id']??'') ?>">
<div class="formgrid">
<div><label>Name</label><input name="name" required value="<?= h($edit_session['name']??'') ?>"></div>
<div><label>Date</label><input type="date" name="session_date" required value="<?= h($edit_session['session_date']??date('Y-m-d')) ?>"></div>
<div><label>Zone</label><select name="zone_id"><?php foreach($z as $zone): ?><option value="<?= $zone['id'] ?>" <?= (($edit_session['zone_id']??'')==$zone['id'])?'selected':'' ?>><?= h($zone['name']) ?></option><?php endforeach; ?></select></div>
</div><br><button>Save Session</button>
</form></div>

<div class="card"><h2>Record Attendance</h2>
<form method="POST"><input type="hidden" name="action" value="attendance">
<div class="formgrid">
<div><label>Session</label><select name="session_id"><?php foreach($s as $ss): ?><option value="<?= $ss['id'] ?>"><?= h($ss['session_date'].' - '.$ss['name'].' - '.$ss['zone_name']) ?></option><?php endforeach; ?></select></div>
<div><label>Athlete</label><select name="member_id"><?php foreach($am as $x): ?><option value="<?= $x['id'] ?>"><?= h($x['full_name'].' - '.$x['zone_name']) ?></option><?php endforeach; ?></select></div>
<div><label>Status</label><select name="status"><option>present</option><option>absent</option><option>late</option></select></div>
</div><br><button>Save Attendance</button>
</form></div>

<div class="card"><h2>Sessions</h2><table><tr><th>Date</th><th>Name</th><th>Zone</th><th>Actions</th></tr>
<?php foreach($s as $ss): ?><tr><td><?= h($ss['session_date']) ?></td><td><?= h($ss['name']) ?></td><td><?= h($ss['zone_name']) ?></td>
<td class="actions"><a class="btn gray" href="?view=attendance&period=<?= h($p) ?>&edit_session=<?= $ss['id'] ?>">Edit</a><form method="POST"><input type="hidden" name="action" value="delete_session"><input type="hidden" name="id" value="<?= $ss['id'] ?>"><button class="red">Delete</button></form></td></tr><?php endforeach; ?>
</table></div>
<?php endif; ?>

<?php if($v==='payments'): ?>
<h1>Billing & Payments</h1>
<div class="card"><h2>Record Payment</h2>
<form method="POST"><input type="hidden" name="action" value="payment">
<div class="formgrid">
<div><label>Athlete</label><select name="member_id"><?php foreach($am as $x): ?><option value="<?= $x['id'] ?>"><?= h($x['full_name'].' - '.$x['zone_name']) ?></option><?php endforeach; ?></select></div>
<div><label>Amount</label><input type="number" name="amount" required></div>
<div><label>Period</label><input name="period" value="<?= h($p) ?>"></div>
<div><label>Note</label><input name="note"></div>
</div><br><button>Record</button>
</form></div>
<div class="card"><h2>Billing Status</h2><table><tr><th>Athlete</th><th>Zone</th><th>Expected</th><th>Paid</th><th>Remaining</th><th>Due</th><th>Status</th><th>Overdue</th></tr>
<?php foreach(billing_rows($pdo,$p) as $b): $stt=bill_status($b['expected_amount'],$b['paid_amount']); ?>
<tr><td><?= h($b['full_name']) ?></td><td><?= h($b['zone_name']) ?></td><td><?= money($b['expected_amount']) ?></td><td><?= money($b['paid_amount']) ?></td><td><?= money($b['remaining']) ?></td><td><?= h($b['due_date']) ?></td><td><span class="badge <?= $stt==='PAID'?'ok':($stt==='PARTIAL'?'warn':'bad') ?>"><?= $stt ?></span></td><td><?= overdue($b['due_date'],$stt) ?> days</td></tr>
<?php endforeach; ?></table></div>
<?php endif; ?>

<?php if($v==='staff'): ?>
<h1>Staff</h1>
<div class="card"><h2><?= $edit_staff?'Edit Staff':'Add Staff' ?></h2>
<form method="POST"><input type="hidden" name="action" value="save_staff"><input type="hidden" name="id" value="<?= h($edit_staff['id']??'') ?>">
<div class="formgrid">
<div><label>Full Name</label><input name="full_name" required value="<?= h($edit_staff['full_name']??'') ?>"></div>
<div><label>Phone</label><input name="phone" value="<?= h($edit_staff['phone']??'') ?>"></div>
<div><label>Role</label><select name="role"><?php foreach(['coach','assistant_coach','manager','accountant'] as $role): ?><option <?= (($edit_staff['role']??'')===$role)?'selected':'' ?>><?= $role ?></option><?php endforeach; ?></select></div>
<div><label>Zone</label><select name="zone_id"><?php foreach($z as $zone): ?><option value="<?= $zone['id'] ?>" <?= (($edit_staff['zone_id']??'')==$zone['id'])?'selected':'' ?>><?= h($zone['name']) ?></option><?php endforeach; ?></select></div>
<div><label>Monthly Salary</label><input type="number" name="monthly_salary" value="<?= h($edit_staff['monthly_salary']??0) ?>"></div>
</div><br><button>Save Staff</button></form></div>
<div class="card"><table><tr><th>Name</th><th>Role</th><th>Zone</th><th>Salary</th><th>Actions</th></tr>
<?php foreach($st as $x): ?><tr><td><?= h($x['full_name']) ?></td><td><?= h($x['role']) ?></td><td><?= h($x['zone_name']) ?></td><td><?= money($x['monthly_salary']) ?></td>
<td class="actions"><a class="btn gray" href="?view=staff&period=<?= h($p) ?>&edit_staff=<?= $x['id'] ?>">Edit</a><form method="POST"><input type="hidden" name="action" value="delete_staff"><input type="hidden" name="id" value="<?= $x['id'] ?>"><button class="red">Deactivate</button></form></td></tr><?php endforeach; ?>
</table></div>
<?php endif; ?>

<?php if($v==='payroll'): ?>
<h1>Coach Payroll</h1>
<div class="card"><form method="POST"><input type="hidden" name="action" value="payroll">
<div class="formgrid">
<div><label>Staff</label><select name="staff_id"><?php foreach($st as $x): ?><option value="<?= $x['id'] ?>"><?= h($x['full_name'].' - '.$x['zone_name']) ?></option><?php endforeach; ?></select></div>
<div><label>Period</label><input name="period" value="<?= h($p) ?>"></div>
<div><label>Base Salary</label><input type="number" name="base_salary" value="0"></div>
<div><label>Bonus</label><input type="number" name="bonus" value="0"></div>
<div><label>Deductions</label><input type="number" name="deductions" value="0"></div>
<div><label>Amount Paid</label><input type="number" name="amount_paid" value="0"></div>
<div><label>Note</label><input name="note"></div>
</div><br><button>Save Payroll</button></form></div>
<div class="card"><table><tr><th>Staff</th><th>Zone</th><th>Net Salary</th><th>Paid</th><th>Status</th></tr>
<?php
$pay=$pdo->query("SELECT c.*,s.full_name,z.name zone_name FROM coach_payroll c JOIN staff s ON s.id=c.staff_id LEFT JOIN academy_zones z ON z.id=s.zone_id WHERE c.period='$p' ORDER BY z.id,s.full_name")->fetchAll();
foreach($pay as $x): ?><tr><td><?= h($x['full_name']) ?></td><td><?= h($x['zone_name']) ?></td><td><?= money($x['net_salary']) ?></td><td><?= money($x['amount_paid']) ?></td><td><?= h($x['status']) ?></td></tr><?php endforeach; ?>
</table></div>
<?php endif; ?>

<?php if($v==='expenses'): ?>
<h1>Expenses</h1>
<div class="card"><form method="POST"><input type="hidden" name="action" value="expense">
<div class="formgrid">
<div><label>Date</label><input type="date" name="expense_date" value="<?= date('Y-m-d') ?>"></div>
<div><label>Zone</label><select name="zone_id"><?php foreach($z as $zone): ?><option value="<?= $zone['id'] ?>"><?= h($zone['name']) ?></option><?php endforeach; ?></select></div>
<div><label>Category</label><input name="category"></div>
<div><label>Description</label><input name="description" required></div>
<div><label>Amount</label><input type="number" name="amount" required></div>
<div><label>Paid To</label><input name="paid_to"></div>
<div><label>Approved By</label><input name="approved_by"></div>
</div><br><button>Save Expense</button></form></div>
<?php endif; ?>

<?php if($v==='reports'): ?>
<h1>Reports</h1>
<div class="card"><h2>Zone Financial Report</h2><table><tr><th>Zone</th><th>Expected</th><th>Paid</th><th>Remaining</th><th>Expenses</th><th>Payroll</th></tr>
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
<tr><td><?= h($x['name']) ?></td><td><?= money($x['expected']) ?></td><td><?= money($x['paid']) ?></td><td><?= money($x['remaining']) ?></td><td><?= money($x['expenses']) ?></td><td><?= money($x['payroll']) ?></td></tr>
<?php endforeach; ?></table></div>

<div class="card"><h2>Attendance Report</h2><table><tr><th>Session</th><th>Date</th><th>Zone</th><th>Present</th><th>Absent</th><th>Late</th></tr>
<?php
$a=$pdo->query("
SELECT s.name,s.session_date,z.name zone,
SUM(CASE WHEN a.status='present' THEN 1 ELSE 0 END) present,
SUM(CASE WHEN a.status='absent' THEN 1 ELSE 0 END) absent,
SUM(CASE WHEN a.status='late' THEN 1 ELSE 0 END) late
FROM sessions s
LEFT JOIN academy_zones z ON z.id=s.zone_id
LEFT JOIN attendance a ON a.session_id=s.id
GROUP BY s.id,s.name,s.session_date,z.name
ORDER BY s.session_date DESC")->fetchAll();
foreach($a as $x): ?>
<tr><td><?= h($x['name']) ?></td><td><?= h($x['session_date']) ?></td><td><?= h($x['zone']) ?></td><td><?= $x['present']??0 ?></td><td><?= $x['absent']??0 ?></td><td><?= $x['late']??0 ?></td></tr>
<?php endforeach; ?></table></div>
<?php endif; ?>

</div>
</body>
</html>
