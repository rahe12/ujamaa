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

CREATE TABLE IF NOT EXISTS sessions(
 id SERIAL PRIMARY KEY,
 name VARCHAR(255) NOT NULL,
 session_date DATE NOT NULL DEFAULT CURRENT_DATE,
 zone_id INT REFERENCES academy_zones(id),
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(session_date, zone_id, name)  -- Prevent duplicate sessions
);

CREATE TABLE IF NOT EXISTS attendance(
 id SERIAL PRIMARY KEY,
 session_id INT REFERENCES sessions(id) ON DELETE CASCADE,
 member_id INT REFERENCES members(id) ON DELETE CASCADE,
 status VARCHAR(20) DEFAULT 'present',
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(session_id, member_id)  -- One attendance per session per athlete
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
 UNIQUE(member_id, period)  -- One bill per athlete per month
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
 full_name VARCHAR(255) NOT NULL UNIQUE,
 phone VARCHAR(50),
 role VARCHAR(50) NOT NULL,
 zone_id INT REFERENCES academy_zones(id),
 monthly_salary NUMERIC(12,2) DEFAULT 0,
 is_active BOOLEAN DEFAULT TRUE,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

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
 UNIQUE(staff_id, period)  -- One payroll per staff per month
);

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

CREATE TABLE IF NOT EXISTS athlete_uniforms(
 id SERIAL PRIMARY KEY,
 member_id INT REFERENCES members(id) ON DELETE CASCADE,
 jersey_number INT NOT NULL UNIQUE,  -- No duplicate jersey numbers
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
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Add missing columns if they don't exist
ALTER TABLE members ADD COLUMN IF NOT EXISTS zone_id INT REFERENCES academy_zones(id);
ALTER TABLE members ADD COLUMN IF NOT EXISTS guardian_name VARCHAR(255);
ALTER TABLE members ADD COLUMN IF NOT EXISTS guardian_phone VARCHAR(50);
ALTER TABLE members ADD COLUMN IF NOT EXISTS position VARCHAR(50);
ALTER TABLE members ADD COLUMN IF NOT EXISTS school_name VARCHAR(255);
ALTER TABLE members ADD COLUMN IF NOT EXISTS notes TEXT;

ALTER TABLE sessions ADD COLUMN IF NOT EXISTS session_date DATE;
ALTER TABLE sessions ADD COLUMN IF NOT EXISTS zone_id INT REFERENCES academy_zones(id);

ALTER TABLE staff ADD COLUMN IF NOT EXISTS zone_id INT REFERENCES academy_zones(id);
ALTER TABLE coach_payroll ADD COLUMN IF NOT EXISTS net_salary NUMERIC(12,2) DEFAULT 0;
ALTER TABLE expenses ADD COLUMN IF NOT EXISTS zone_id INT REFERENCES academy_zones(id);
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
    // Use INSERT with ON CONFLICT to prevent duplicates
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

// Get athletes who attended sessions (no duplicates due to DISTINCT)
function athletes_with_attendance($pdo, $period) {
    $stmt = $pdo->prepare("
        SELECT DISTINCT 
            m.id, m.full_name, m.phone, m.guardian_name,
            z.name as zone_name,
            COALESCE(b.expected_amount, 0) as expected_amount, 
            COALESCE(b.paid_amount, 0) as paid_amount,
            GREATEST(COALESCE(b.expected_amount, 0) - COALESCE(b.paid_amount, 0), 0) as remaining,
            COUNT(DISTINCT s.id) as sessions_attended,
            STRING_AGG(DISTINCT s.name, ', ') as session_names
        FROM members m
        LEFT JOIN academy_zones z ON z.id = m.zone_id
        LEFT JOIN monthly_bills b ON b.member_id = m.id AND b.period = ?
        LEFT JOIN attendance a ON a.member_id = m.id
        LEFT JOIN sessions s ON s.id = a.session_id 
            AND TO_CHAR(s.session_date, 'YYYY-MM') = ?
        WHERE m.is_active = TRUE
            AND a.id IS NOT NULL
        GROUP BY m.id, m.full_name, m.phone, m.guardian_name, z.name, b.expected_amount, b.paid_amount
        HAVING COUNT(DISTINCT s.id) > 0
        ORDER BY z.name, m.full_name
    ");
    $stmt->execute([$period, $period]);
    return $stmt->fetchAll();
}

// Non-payers with attendance (no duplicates)
function non_payers_with_attendance($pdo, $period, $attendance_month = null) {
    $att_month = $attendance_month ?: $period;
    
    $stmt = $pdo->prepare("
        SELECT DISTINCT 
            m.id, m.full_name, m.phone, m.guardian_name, m.guardian_phone,
            z.name as zone_name,
            COALESCE(b.expected_amount, 0) as expected_amount, 
            COALESCE(b.paid_amount, 0) as paid_amount, 
            GREATEST(COALESCE(b.expected_amount, 0) - COALESCE(b.paid_amount, 0), 0) as remaining,
            COUNT(DISTINCT s.id) as sessions_attended,
            STRING_AGG(DISTINCT s.name || ' (' || s.session_date || ')', ', ') as sessions_list
        FROM members m
        LEFT JOIN academy_zones z ON z.id = m.zone_id
        LEFT JOIN monthly_bills b ON b.member_id = m.id AND b.period = ?
        LEFT JOIN attendance a ON a.member_id = m.id
        LEFT JOIN sessions s ON s.id = a.session_id 
            AND TO_CHAR(s.session_date, 'YYYY-MM') = ?
        WHERE m.is_active = TRUE
            AND (b.paid_amount IS NULL OR b.paid_amount < COALESCE(b.expected_amount, 0))
            AND a.id IS NOT NULL
        GROUP BY m.id, m.full_name, m.phone, m.guardian_name, m.guardian_phone, z.name, b.expected_amount, b.paid_amount
        HAVING COUNT(DISTINCT s.id) > 0
        ORDER BY z.name, m.full_name
    ");
    $stmt->execute([$period, $att_month]);
    return $stmt->fetchAll();
}

// Overdue payments (no duplicates)
function overdue_payments_report($pdo, $period) {
    $stmt = $pdo->prepare("
        SELECT DISTINCT m.*, z.name as zone_name, b.*,
            GREATEST(b.expected_amount - b.paid_amount, 0) as remaining,
            EXTRACT(DAY FROM (CURRENT_DATE - b.due_date)) as days_overdue
        FROM monthly_bills b
        JOIN members m ON m.id = b.member_id
        LEFT JOIN academy_zones z ON z.id = m.zone_id
        WHERE b.period = ?
            AND b.paid_amount < b.expected_amount
            AND b.due_date < CURRENT_DATE
            AND m.is_active = TRUE
        ORDER BY b.due_date ASC
    ");
    $stmt->execute([$period]);
    return $stmt->fetchAll();
}

// Attendance summary (no duplicates)
function attendance_summary($pdo, $member_id = null, $year_month = null) {
    $sql = "
        SELECT 
            m.id, m.full_name, z.name as zone_name,
            COUNT(DISTINCT s.id) as total_sessions,
            SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present_count,
            SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) as absent_count,
            SUM(CASE WHEN a.status = 'late' THEN 1 ELSE 0 END) as late_count,
            ROUND((SUM(CASE WHEN a.status IN ('present', 'late') THEN 1 ELSE 0 END)::decimal / NULLIF(COUNT(DISTINCT s.id), 0) * 100), 1) as attendance_rate
        FROM members m
        LEFT JOIN academy_zones z ON z.id = m.zone_id
        LEFT JOIN attendance a ON a.member_id = m.id
        LEFT JOIN sessions s ON s.id = a.session_id
        WHERE m.is_active = TRUE
    ";
    
    $params = [];
    if ($member_id) {
        $sql .= " AND m.id = ?";
        $params[] = $member_id;
    }
    if ($year_month) {
        $sql .= " AND TO_CHAR(s.session_date, 'YYYY-MM') = ?";
        $params[] = $year_month;
    }
    
    $sql .= " GROUP BY m.id, m.full_name, z.name ORDER BY m.full_name";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

// Export CSV (no duplicates in export)
function export_csv($data, $filename, $headers) {
    // Remove duplicates by ID if present
    $unique = [];
    foreach($data as $row) {
        $key = isset($row['id']) ? $row['id'] : (isset($row['member_id']) ? $row['member_id'] : uniqid());
        if(!isset($unique[$key])) {
            $unique[$key] = $row;
        }
    }
    $data = array_values($unique);
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, $headers);
    foreach ($data as $row) {
        fputcsv($out, (array)$row);
    }
    fclose($out);
    exit;
}

// Check for duplicate before insert/update
function check_duplicate_member($pdo, $full_name, $exclude_id = null) {
    $sql = "SELECT id FROM members WHERE full_name = ?";
    $params = [$full_name];
    if($exclude_id) {
        $sql .= " AND id != ?";
        $params[] = $exclude_id;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetch();
}

function check_duplicate_staff($pdo, $full_name, $exclude_id = null) {
    $sql = "SELECT id FROM staff WHERE full_name = ?";
    $params = [$full_name];
    if($exclude_id) {
        $sql .= " AND id != ?";
        $params[] = $exclude_id;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetch();
}

function check_duplicate_session($pdo, $name, $session_date, $zone_id, $exclude_id = null) {
    $sql = "SELECT id FROM sessions WHERE name = ? AND session_date = ? AND zone_id = ?";
    $params = [$name, $session_date, $zone_id];
    if($exclude_id) {
        $sql .= " AND id != ?";
        $params[] = $exclude_id;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetch();
}

function check_duplicate_uniform_number($pdo, $jersey_number, $exclude_id = null) {
    $sql = "SELECT id FROM athlete_uniforms WHERE jersey_number = ?";
    $params = [$jersey_number];
    if($exclude_id) {
        $sql .= " AND id != ?";
        $params[] = $exclude_id;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetch();
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    $a=$_POST['action']??'';
    
    if($a==='save_member'){
        $id=$_POST['id']??'';
        $full_name = trim($_POST['full_name']);
        
        // Check for duplicate name
        if(check_duplicate_member($pdo, $full_name, $id ?: null)) {
            go('members', 'Duplicate: Athlete with name "' . h($full_name) . '" already exists!');
        }
        
        $data=[$full_name, $_POST['phone']?:null, $_POST['gender']?:null, $_POST['date_of_birth']?:null,
            $_POST['zone_id']?:default_zone($pdo), $_POST['guardian_name']?:null, $_POST['guardian_phone']?:null,
            $_POST['position']?:null, $_POST['school_name']?:null, $_POST['monthly_fee']?:0, $_POST['due_day']?:5, $_POST['notes']?:null];
        if($id){
            $stmt=$pdo->prepare("UPDATE members SET full_name=?,phone=?,gender=?,date_of_birth=?,zone_id=?,guardian_name=?,guardian_phone=?,position=?,school_name=?,monthly_fee=?,due_day=?,notes=? WHERE id=?");
            $stmt->execute([...$data,$id]);
            go('members','Athlete updated');
        }else{
            $stmt=$pdo->prepare("INSERT INTO members(full_name,phone,gender,date_of_birth,zone_id,guardian_name,guardian_phone,position,school_name,monthly_fee,due_day,notes) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)");
            try {
                $stmt->execute($data);
                go('members','Athlete added');
            } catch(PDOException $e) {
                if(strpos($e->getMessage(),'unique') !== false) {
                    go('members','Duplicate athlete name. Please use a different name.');
                }
                throw $e;
            }
        }
    }
    
    if($a==='delete_member'){
        $pdo->prepare("UPDATE members SET is_active=FALSE WHERE id=?")->execute([$_POST['id']]);
        go('members','Athlete deactivated');
    }
    
    if($a==='save_session'){
        $id=$_POST['id']??'';
        $name = trim($_POST['name']);
        $session_date = $_POST['session_date'];
        $zone_id = $_POST['zone_id'];
        
        // Check for duplicate session
        if(check_duplicate_session($pdo, $name, $session_date, $zone_id, $id ?: null)) {
            go('attendance', 'Duplicate: A session with same name, date, and zone already exists!');
        }
        
        if($id){
            $pdo->prepare("UPDATE sessions SET name=?,session_date=?,zone_id=? WHERE id=?")->execute([$name, $session_date, $zone_id, $id]);
            go('attendance','Session updated');
        }else{
            try {
                $pdo->prepare("INSERT INTO sessions(name,session_date,zone_id) VALUES(?,?,?)")->execute([$name, $session_date, $zone_id?:default_zone($pdo)]);
                go('attendance','Session created');
            } catch(PDOException $e) {
                if(strpos($e->getMessage(),'unique') !== false) {
                    go('attendance','Duplicate session. A session with same name, date, and zone already exists.');
                }
                throw $e;
            }
        }
    }
    
    if($a==='delete_session'){
        $pdo->prepare("DELETE FROM sessions WHERE id=?")->execute([$_POST['id']]);
        go('attendance','Session deleted');
    }
    
    if($a==='attendance'){
        $sid=$_POST['session_id'];
        $mid=$_POST['member_id'];
        $status=$_POST['status'];
        
        // Check zone compatibility
        $check=$pdo->prepare("SELECT COUNT(*) FROM sessions s JOIN members m ON m.zone_id=s.zone_id WHERE s.id=? AND m.id=?");
        $check->execute([$sid,$mid]);
        if(!$check->fetchColumn()) {
            go('attendance','Wrong zone: athlete does not belong to that session zone');
        }
        
        // Use ON CONFLICT to prevent duplicates
        $pdo->prepare("INSERT INTO attendance(session_id,member_id,status) VALUES(?,?,?) ON CONFLICT(session_id,member_id) DO UPDATE SET status=EXCLUDED.status")->execute([$sid,$mid,$status]);
        
        // Mark unmarked athletes as absent (only once)
        $pdo->prepare("INSERT INTO attendance(session_id,member_id,status) SELECT s.id,m.id,'absent' FROM sessions s JOIN members m ON m.zone_id=s.zone_id WHERE s.id=? AND m.is_active=TRUE AND NOT EXISTS(SELECT 1 FROM attendance a WHERE a.session_id=s.id AND a.member_id=m.id)")->execute([$sid]);
        
        go('attendance','Attendance saved');
    }
    
    if($a==='payment'){
        $mid=$_POST['member_id'];
        $amount=(float)$_POST['amount'];
        $per=$_POST['period'];
        
        if($amount <= 0) {
            go('payments','Amount must be greater than zero');
        }
        
        ensure_bill($pdo,$mid,$per);
        
        // Update payment - accumulate amount
        $pdo->prepare("UPDATE monthly_bills SET paid_amount=paid_amount+?, paid_at=NOW(), updated_at=NOW(), note=? WHERE member_id=? AND period=?")->execute([$amount, $_POST['note']?:null, $mid, $per]);
        
        // Log payment
        $pdo->prepare("INSERT INTO payment_logs(member_id,amount_paid,period,note) VALUES(?,?,?,?)")->execute([$mid, $amount, $per, $_POST['note']?:null]);
        
        go('payments','Payment recorded');
    }
    
    if($a==='save_staff'){
        $id=$_POST['id']??'';
        $full_name = trim($_POST['full_name']);
        
        // Check for duplicate staff name
        if(check_duplicate_staff($pdo, $full_name, $id ?: null)) {
            go('staff', 'Duplicate: Staff member with name "' . h($full_name) . '" already exists!');
        }
        
        if($id){
            $pdo->prepare("UPDATE staff SET full_name=?,phone=?,role=?,zone_id=?,monthly_salary=? WHERE id=?")->execute([$full_name, $_POST['phone']?:null, $_POST['role'], $_POST['zone_id'], $_POST['monthly_salary']?:0, $id]);
            go('staff','Staff updated');
        }else{
            try {
                $pdo->prepare("INSERT INTO staff(full_name,phone,role,zone_id,monthly_salary) VALUES(?,?,?,?,?)")->execute([$full_name, $_POST['phone']?:null, $_POST['role'], $_POST['zone_id']?:default_zone($pdo), $_POST['monthly_salary']?:0]);
                go('staff','Staff added');
            } catch(PDOException $e) {
                if(strpos($e->getMessage(),'unique') !== false) {
                    go('staff','Duplicate staff name. Please use a different name.');
                }
                throw $e;
            }
        }
    }
    
    if($a==='delete_staff'){
        $pdo->prepare("UPDATE staff SET is_active=FALSE WHERE id=?")->execute([$_POST['id']]);
        go('staff','Staff deactivated');
    }
    
    if($a==='payroll'){
        $staff_id = $_POST['staff_id'];
        $period = $_POST['period'];
        
        // Check if payroll already exists for this staff and period
        $check = $pdo->prepare("SELECT id FROM coach_payroll WHERE staff_id = ? AND period = ?");
        $check->execute([$staff_id, $period]);
        if($check->fetch()) {
            // Update existing
            $net=(float)$_POST['base_salary']+(float)$_POST['bonus']-(float)$_POST['deductions'];
            $status=((float)$_POST['amount_paid']<=0)?'UNPAID':(((float)$_POST['amount_paid']<$net)?'PARTIAL':'PAID');
            $pdo->prepare("UPDATE coach_payroll SET base_salary=?, bonus=?, deductions=?, net_salary=?, amount_paid=?, status=?, paid_at=NOW(), note=? WHERE staff_id=? AND period=?")
                ->execute([$_POST['base_salary'], $_POST['bonus'], $_POST['deductions'], $net, $_POST['amount_paid'], $status, $_POST['note']?:null, $staff_id, $period]);
        } else {
            // Insert new
            $net=(float)$_POST['base_salary']+(float)$_POST['bonus']-(float)$_POST['deductions'];
            $status=((float)$_POST['amount_paid']<=0)?'UNPAID':(((float)$_POST['amount_paid']<$net)?'PARTIAL':'PAID');
            $pdo->prepare("INSERT INTO coach_payroll(staff_id,period,base_salary,bonus,deductions,net_salary,amount_paid,status,paid_at,note) VALUES(?,?,?,?,?,?,?,?,NOW(),?)")
                ->execute([$staff_id, $period, $_POST['base_salary'], $_POST['bonus'], $_POST['deductions'], $net, $_POST['amount_paid'], $status, $_POST['note']?:null]);
        }
        go('payroll','Payroll saved');
    }

    if($a==='save_uniform'){
        $id=$_POST['id']??'';
        $member_id=(int)($_POST['member_id']??0);
        $jersey_number=(int)($_POST['jersey_number']??0);
        
        if($member_id<=0 || $jersey_number<=0) {
            go('uniforms','Please select athlete and jersey number');
        }
        
        // Check if jersey number already exists
        if(check_duplicate_uniform_number($pdo, $jersey_number, $id ?: null)) {
            go('uniforms','Jersey number ' . $jersey_number . ' is already assigned to another athlete.');
        }
        
        $data=[
            $member_id, $jersey_number,
            $_POST['jersey_category']??'Adult Unisex V-Neck', $_POST['jersey_size']??'', $_POST['jersey_chest']?:null, $_POST['jersey_length']?:null,
            $_POST['shorts_category']??'Adult Unisex Shorts', $_POST['shorts_size']??'', $_POST['shorts_waist']?:null, $_POST['shorts_inseam']?:null,
            $_POST['quantity']?:1, $_POST['issued_date']?:date('Y-m-d'), $_POST['note']?:null
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
            if(strpos($e->getMessage(),'unique')!==false) {
                go('uniforms','This jersey number is already assigned. Use another number.');
            }
            throw $e;
        }
    }
    
    if($a==='delete_uniform'){
        $pdo->prepare("DELETE FROM athlete_uniforms WHERE id=?")->execute([$_POST['id']]);
        go('uniforms','Uniform record deleted');
    }
    
    if($a==='expense'){
        $pdo->prepare("INSERT INTO expenses(expense_date,category,description,amount,paid_to,approved_by,zone_id) VALUES(?,?,?,?,?,?,?)")
            ->execute([$_POST['expense_date'], $_POST['category'], $_POST['description'], $_POST['amount'], $_POST['paid_to'], $_POST['approved_by'], $_POST['zone_id']?:default_zone($pdo)]);
        go('expenses','Expense saved');
    }
}

$z=zones($pdo);
$m=members($pdo);
$am=active_members($pdo);
$s=sessions($pdo);
$st=staff($pdo);
$p=period();
$v=view();
$msg=$_GET['msg']??'';
$edit_member=null;
$edit_staff=null;
$edit_session=null;
$edit_uniform=null;

if(isset($_GET['edit_member'])){$q=$pdo->prepare("SELECT * FROM members WHERE id=?");$q->execute([$_GET['edit_member']]);$edit_member=$q->fetch();}
if(isset($_GET['edit_staff'])){$q=$pdo->prepare("SELECT * FROM staff WHERE id=?");$q->execute([$_GET['edit_staff']]);$edit_staff=$q->fetch();}
if(isset($_GET['edit_session'])){$q=$pdo->prepare("SELECT * FROM sessions WHERE id=?");$q->execute([$_GET['edit_session']]);$edit_session=$q->fetch();}
if(isset($_GET['edit_uniform'])){$q=$pdo->prepare("SELECT * FROM athlete_uniforms WHERE id=?");$q->execute([$_GET['edit_uniform']]);$edit_uniform=$q->fetch();}

// Handle exports
if(($export_type = $_GET['export'] ?? '')) {
    switch($export_type) {
        case 'non_payers':
            $non_payers = non_payers_with_attendance($pdo, $p, $_GET['att_month'] ?? $p);
            $headers = ['Athlete', 'Zone', 'Phone', 'Guardian', 'Guardian Phone', 'Expected', 'Paid', 'Remaining', 'Sessions Attended', 'Sessions List'];
            export_csv($non_payers, 'non_payers_attendance_report', $headers);
            break;
        case 'overdue':
            $overdue = overdue_payments_report($pdo, $p);
            $headers = ['Athlete', 'Zone', 'Phone', 'Guardian', 'Expected', 'Paid', 'Remaining', 'Due Date', 'Days Overdue', 'Status'];
            export_csv($overdue, 'overdue_payments_report', $headers);
            break;
        case 'attendance_summary':
            $att_summary = attendance_summary($pdo, null, $_GET['att_month'] ?? $p);
            $headers = ['Athlete', 'Zone', 'Total Sessions', 'Present', 'Absent', 'Late', 'Attendance Rate %'];
            export_csv($att_summary, 'attendance_summary_report', $headers);
            break;
    }
}

if(($_GET['export']??'')==='uniform_excel'){
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="uniform_report_'.date('Ymd_His').'.csv"');
    $out=fopen('php://output','w');
    fputcsv($out,['Jersey Number','Athlete','Zone','Jersey Category','Jersey Size','Chest','Length','Shorts Category','Shorts Size','Waist','Inseam','Quantity','Issued Date','Note']);
    $rows=$pdo->query("SELECT u.*,m.full_name,z.name zone_name FROM athlete_uniforms u JOIN members m ON m.id=u.member_id LEFT JOIN academy_zones z ON z.id=m.zone_id ORDER BY u.jersey_number ASC,m.full_name ASC")->fetchAll();
    foreach($rows as $r){
        fputcsv($out,[$r['jersey_number'],$r['full_name'],$r['zone_name'],$r['jersey_category'],$r['jersey_size'],$r['jersey_chest'],$r['jersey_length'],$r['shorts_category'],$r['shorts_size'],$r['shorts_waist'],$r['shorts_inseam'],$r['quantity'],$r['issued_date'],$r['note']]);
    }
    fclose($out);
    exit;
}

if(($_GET['export']??'')==='uniform_pdf'){
    $rows=$pdo->query("SELECT u.*,m.full_name,z.name zone_name FROM athlete_uniforms u JOIN members m ON m.id=u.member_id LEFT JOIN academy_zones z ON z.id=m.zone_id ORDER BY u.jersey_number ASC,m.full_name ASC")->fetchAll();
    $totalQty=0; foreach($rows as $r){ $totalQty += (int)$r['quantity']; }
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Uniform Report</title>
<style>
body{font-family:Arial,sans-serif;color:#111;margin:0;padding:28px;background:#fff}
table{width:100%;border-collapse:collapse;font-size:11px}
th,td{border:1px solid #ccc;padding:7px;text-align:left}
th{background:#111;color:#fff}
@media print{body{padding:0}}
</style>
</head>
<body>
<h1>Uniform Report</h1>
<p>Generated: <?= date('Y-m-d H:i') ?> | Total Records: <?= count($rows) ?> | Total Quantity: <?= $totalQty ?></p>
<table>
<thead><tr><th>#</th><th>Athlete</th><th>Zone</th><th>Jersey Category</th><th>Jersey Size</th><th>Shorts Size</th><th>Qty</th><th>Date</th></tr></thead>
<tbody>
<?php foreach($rows as $r): ?>
<tr>
    <td><?= h($r['jersey_number']) ?></td>
    <td><?= h($r['full_name']) ?></td>
    <td><?= h($r['zone_name']) ?></td>
    <td><?= h($r['jersey_category']) ?></td>
    <td><?= h($r['jersey_size']) ?></td>
    <td><?= h($r['shorts_size']) ?></td>
    <td><?= h($r['quantity']) ?></td>
    <td><?= h($r['issued_date']) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</body>
</html>
<?php exit; }

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
    'uniforms'  => ['icon'=>'▤','label'=>'Uniforms'],
    'reports'   => ['icon'=>'◧','label'=>'Reports'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Academy AMS</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<style>
*{margin:0;padding:0;box-sizing:border-box;}
body{font-family:'Inter',sans-serif;background:#0a0c12;color:#eef2ff;}
.sidebar{position:fixed;left:0;top:0;width:260px;height:100vh;background:#11131a;border-right:1px solid #1f2230;padding:24px 16px;}
.main{margin-left:260px;padding:32px;}
.card{background:#141824;border-radius:16px;border:1px solid #202433;padding:24px;margin-bottom:24px;}
.btn{display:inline-flex;align-items:center;gap:8px;padding:8px 16px;border-radius:8px;font-weight:500;cursor:pointer;border:none;transition:all 0.2s;}
.btn-primary{background:#10b981;color:#fff;}
.btn-primary:hover{background:#059669;}
.btn-ghost{background:#1f2230;color:#eef2ff;border:1px solid #2d3142;}
.btn-danger{background:#ef4444;color:#fff;}
table{width:100%;border-collapse:collapse;}
th,td{padding:12px;text-align:left;border-bottom:1px solid #202433;}
th{color:#9ca3af;font-weight:500;font-size:12px;}
.badge{display:inline-flex;padding:4px 10px;border-radius:20px;font-size:11px;font-weight:600;}
.b-paid{background:#10b98120;color:#10b981;}
.b-partial{background:#f59e0b20;color:#f59e0b;}
.b-unpaid{background:#ef444420;color:#ef4444;}
.b-zone{background:#3b82f620;color:#60a5fa;}
input,select{background:#1f2230;border:1px solid #2d3142;padding:10px 14px;border-radius:8px;color:#eef2ff;width:100%;}
.form-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;}
.result-count{font-size:12px;color:#6b7280;margin-bottom:12px;}
</style>
</head>
<body>
<aside class="sidebar">
    <div style="margin-bottom:32px"><h2 style="color:#10b981">Academy AMS</h2><p style="font-size:12px;color:#6b7280">Management System</p></div>
    <nav style="display:flex;flex-direction:column;gap:4px">
    <?php foreach($nav_items as $key=>$item): ?>
    <a href="?view=<?= $key ?>&period=<?= h($p) ?>" style="display:flex;align-items:center;gap:12px;padding:10px 12px;border-radius:8px;color:<?= $v===$key?'#10b981':'#9ca3af' ?>;text-decoration:none;background:<?= $v===$key?'#10b98110':'transparent' ?>"><?= $item['icon'] ?> <?= $item['label'] ?></a>
    <?php endforeach; ?>
    </nav>
</aside>
<main class="main">
<?php if($msg): ?>
<div style="background:#10b98120;border:1px solid #10b981;border-radius:8px;padding:12px 16px;margin-bottom:20px;color:#10b981">✓ <?= h($msg) ?></div>
<?php endif; ?>

<?php
$prev = date('Y-m', strtotime($p.'-01 -1 month'));
$next = date('Y-m', strtotime($p.'-01 +1 month'));

// DASHBOARD VIEW
if($v==='dashboard'): ?>
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:32px">
    <h1 style="font-size:32px">Dashboard <span style="color:#10b981"><?= h($p) ?></span></h1>
    <div style="display:flex;gap:8px">
        <a href="?view=dashboard&period=<?= $prev ?>" class="btn btn-ghost">← Prev</a>
        <span style="background:#1f2230;padding:8px 16px;border-radius:8px"><?= h($p) ?></span>
        <a href="?view=dashboard&period=<?= $next ?>" class="btn btn-ghost">Next →</a>
    </div>
</div>
<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:24px">
    <div class="card"><div>⚽ Active Athletes</div><div style="font-size:32px;font-weight:700;margin-top:8px"><?= $stats['athletes'] ?></div></div>
    <div class="card"><div>👤 Active Staff</div><div style="font-size:32px;font-weight:700;margin-top:8px"><?= $stats['staff'] ?></div></div>
    <div class="card"><div>💰 Revenue</div><div style="font-size:32px;font-weight:700;margin-top:8px;color:#10b981"><?= money($stats['revenue']) ?></div></div>
    <div class="card"><div>⏳ Outstanding</div><div style="font-size:32px;font-weight:700;margin-top:8px;color:#f59e0b"><?= money($stats['outstanding']) ?></div></div>
    <div class="card"><div>📤 Expenses</div><div style="font-size:32px;font-weight:700;margin-top:8px;color:#ef4444"><?= money($stats['expenses']) ?></div></div>
    <div class="card"><div>💳 Payroll</div><div style="font-size:32px;font-weight:700;margin-top:8px"><?= money($stats['payroll']) ?></div></div>
</div>
<?php endif; ?>

// MEMBERS VIEW
if($v==='members'): ?>
<div class="card">
    <h3 style="margin-bottom:20px"><?= $edit_member ? 'Edit Athlete' : 'Add New Athlete' ?></h3>
    <form method="POST">
        <input type="hidden" name="action" value="save_member">
        <input type="hidden" name="id" value="<?= h($edit_member['id']??'') ?>">
        <div class="form-grid">
            <div><label>Full Name *</label><input name="full_name" required value="<?= h($edit_member['full_name']??'') ?>"></div>
            <div><label>Phone</label><input name="phone" value="<?= h($edit_member['phone']??'') ?>"></div>
            <div><label>Zone</label><select name="zone_id"><?php foreach($z as $zone): ?><option value="<?= $zone['id'] ?>" <?= (($edit_member['zone_id']??'')==$zone['id'])?'selected':'' ?>><?= h($zone['name']) ?></option><?php endforeach; ?></select></div>
            <div><label>Gender</label><select name="gender"><option>Male</option><option>Female</option></select></div>
            <div><label>Monthly Fee (RWF)</label><input type="number" name="monthly_fee" value="<?= h($edit_member['monthly_fee']??0) ?>"></div>
            <div><label>Due Day</label><input type="number" name="due_day" min="1" max="31" value="<?= h($edit_member['due_day']??5) ?>"></div>
        </div>
        <div style="margin-top:20px"><button class="btn btn-primary" type="submit">Save Athlete</button></div>
    </form>
</div>

<div class="card">
    <h3 style="margin-bottom:20px">All Athletes</h3>
    <div class="table-wrap">
    <table>
        <thead><tr><th>Athlete</th><th>Zone</th><th>Phone</th><th>Monthly Fee</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach($m as $x): ?>
        <tr>
            <td><strong><?= h($x['full_name']) ?></strong></td>
            <td><span class="badge b-zone"><?= h($x['zone_name']) ?></span></td>
            <td><?= h($x['phone']) ?></td>
            <td><?= money($x['monthly_fee']) ?></td>
            <td><span class="badge <?= $x['is_active']?'b-paid':'b-unpaid' ?>"><?= $x['is_active']?'Active':'Inactive' ?></span></td>
            <td><a href="?view=members&period=<?= h($p) ?>&edit_member=<?= $x['id'] ?>" class="btn btn-ghost" style="padding:4px 12px">Edit</a>
            <form method="POST" style="display:inline" onsubmit="return confirm('Deactivate?')"><input type="hidden" name="action" value="delete_member"><input type="hidden" name="id" value="<?= $x['id'] ?>"><button class="btn btn-danger" style="padding:4px 12px">Deactivate</button></form></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php endif; ?>

// PAYMENTS VIEW - ONLY ATHLETES WHO ATTENDED
if($v==='payments'): 
$attendedAthletes = athletes_with_attendance($pdo, $p);
?>
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:32px">
    <h1>Billing <span style="color:#10b981"><?= h($p) ?></span></h1>
    <div><a href="?view=payments&period=<?= $prev ?>" class="btn btn-ghost">← Prev</a> <span style="background:#1f2230;padding:8px 16px;border-radius:8px"><?= h($p) ?></span> <a href="?view=payments&period=<?= $next ?>" class="btn btn-ghost">Next →</a></div>
</div>

<div class="card">
    <h3 style="margin-bottom:20px">Record Payment</h3>
    <form method="POST" id="paymentForm">
        <input type="hidden" name="action" value="payment">
        <input type="hidden" name="member_id" id="pay_member_id" value="">
        <div class="form-grid">
            <div><label>Search Athlete *</label><input type="text" id="payAthleteSearch" placeholder="Type athlete name..." list="athleteList" autocomplete="off"><datalist id="athleteList"><?php foreach($attendedAthletes as $att): ?><option value="<?= h($att['full_name']) ?>"><?php endforeach; ?></datalist></div>
            <div><label>Amount (RWF)</label><input type="number" name="amount" id="payAmount" required></div>
            <div><label>Period</label><input name="period" value="<?= h($p) ?>"></div>
            <div><label>Note</label><input name="note" placeholder="Receipt #"></div>
        </div>
        <div style="margin-top:20px"><button class="btn btn-primary" type="submit">Record Payment</button></div>
    </form>
</div>

<div class="card">
    <h3 style="margin-bottom:20px">Athletes Who Attended Sessions (<?= count($attendedAthletes) ?>)</h3>
    <div class="table-wrap">
    <table>
        <thead><tr><th>Athlete</th><th>Zone</th><th>Sessions</th><th>Expected</th><th>Paid</th><th>Remaining</th><th>Status</th><th>Action</th></tr></thead>
        <tbody>
        <?php foreach($attendedAthletes as $att): $stt = bill_status($att['expected_amount'], $att['paid_amount']); ?>
        <tr>
            <td><strong><?= h($att['full_name']) ?></strong></td>
            <td><span class="badge b-zone"><?= h($att['zone_name']) ?></span></td>
            <td><span class="badge b-paid"><?= $att['sessions_attended'] ?></span> sessions</td>
            <td><?= money($att['expected_amount']) ?></td>
            <td><?= money($att['paid_amount']) ?></td>
            <td><?= money($att['remaining']) ?></td>
            <td><span class="badge <?= $stt==='PAID'?'b-paid':($stt==='PARTIAL'?'b-partial':'b-unpaid') ?>"><?= $stt ?></span></td>
            <td><button class="btn btn-primary" onclick="selectAthlete('<?= h(addslashes($att['full_name'])) ?>', <?= $att['id'] ?>, <?= $att['expected_amount'] ?>)">Pay</button></td>
        </tr>
        <?php endforeach; ?>
        <?php if(empty($attendedAthletes)): ?><tr><td colspan="8" style="text-align:center;padding:40px">No athletes attended sessions this period. Please mark attendance first.</td></tr><?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

<script>
function selectAthlete(name, id, amount) {
    document.getElementById('payAthleteSearch').value = name;
    document.getElementById('pay_member_id').value = id;
    document.getElementById('payAmount').value = amount;
}
document.getElementById('payAthleteSearch').addEventListener('input', function() {
    var input = this.value;
    var options = document.getElementById('athleteList').options;
    for(var i=0; i<options.length; i++) {
        if(options[i].value === input) {
            // Find athlete data
            <?php foreach($attendedAthletes as $att): ?>
            if(input === '<?= h(addslashes($att['full_name'])) ?>') {
                document.getElementById('pay_member_id').value = <?= $att['id'] ?>;
                document.getElementById('payAmount').value = <?= $att['expected_amount'] ?>;
            }
            <?php endforeach; ?>
            break;
        }
    }
});
</script>
<?php endif; ?>

// REPORTS VIEW
if($v==='reports'): 
$non_payers = non_payers_with_attendance($pdo, $p);
$overdue = overdue_payments_report($pdo, $p);
$att_summary = attendance_summary($pdo, null, $p);
?>
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:32px">
    <h1>Reports & Analytics <span style="color:#10b981"><?= h($p) ?></span></h1>
    <div><a href="?view=reports&period=<?= $prev ?>" class="btn btn-ghost">← Prev</a> <span style="background:#1f2230;padding:8px 16px;border-radius:8px"><?= h($p) ?></span> <a href="?view=reports&period=<?= $next ?>" class="btn btn-ghost">Next →</a></div>
</div>

<!-- Non-Payers Who Attend -->
<div class="card">
    <h3>⚠️ Non-Payers Who Attend Sessions (<?= count($non_payers) ?>)</h3>
    <div class="table-wrap">
    <table>
        <thead><tr><th>Athlete</th><th>Zone</th><th>Guardian</th><th>Expected</th><th>Paid</th><th>Remaining</th><th>Sessions</th></tr></thead>
        <tbody>
        <?php foreach($non_payers as $np): ?>
        <tr>
            <td><strong><?= h($np['full_name']) ?></strong></td>
            <td><?= h($np['zone_name']) ?></td>
            <td><?= h($np['guardian_name']) ?><br><small><?= h($np['guardian_phone']) ?></small></td>
            <td><?= money($np['expected_amount']) ?></td>
            <td><?= money($np['paid_amount']) ?></td>
            <td style="color:#ef4444"><?= money($np['remaining']) ?></td>
            <td><span class="badge b-paid"><?= $np['sessions_attended'] ?></span></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<!-- Overdue Payments -->
<div class="card">
    <h3>⏰ Overdue Payments (<?= count($overdue) ?>)</h3>
    <div class="table-wrap">
    <table>
        <thead><tr><th>Athlete</th><th>Zone</th><th>Expected</th><th>Paid</th><th>Remaining</th><th>Due Date</th><th>Days Overdue</th></tr></thead>
        <tbody>
        <?php foreach($overdue as $od): ?>
        <tr>
            <td><strong><?= h($od['full_name']) ?></strong></td>
            <td><?= h($od['zone_name']) ?></td>
            <td><?= money($od['expected_amount']) ?></td>
            <td><?= money($od['paid_amount']) ?></td>
            <td style="color:#ef4444"><?= money($od['remaining']) ?></td>
            <td><?= h($od['due_date']) ?></td>
            <td><span class="badge b-unpaid"><?= $od['days_overdue'] ?> days</span></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<!-- Attendance Summary -->
<div class="card">
    <h3>📋 Attendance Summary (<?= h($p) ?>)</h3>
    <div class="table-wrap">
    <table>
        <thead><tr><th>Athlete</th><th>Zone</th><th>Total</th><th>Present</th><th>Absent</th><th>Late</th><th>Rate</th></tr></thead>
        <tbody>
        <?php foreach($att_summary as $att): ?>
        <tr>
            <td><strong><?= h($att['full_name']) ?></strong></td>
            <td><?= h($att['zone_name']) ?></td>
            <td><?= $att['total_sessions'] ?></td>
            <td><span class="badge b-paid"><?= $att['present_count'] ?></span></td>
            <td><span class="badge b-unpaid"><?= $att['absent_count'] ?></span></td>
            <td><span class="badge b-partial"><?= $att['late_count'] ?></span></td>
            <td><strong style="color:<?= $att['attendance_rate']>=80?'#10b981':($att['attendance_rate']>=50?'#f59e0b':'#ef4444') ?>"><?= $att['attendance_rate'] ?>%</strong></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php endif; ?>

</main>
</body>
</html>
