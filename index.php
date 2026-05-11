<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

function db() {
    $databaseUrl = getenv("DATABASE_URL");
    if (!$databaseUrl) die("DATABASE_URL is missing.");

    $url = parse_url($databaseUrl);
    $port = $url['port'] ?? 5432;
    $dsn = "pgsql:host={$url['host']};port={$port};dbname=" . ltrim($url['path'], '/') . ";sslmode=require";

    return new PDO($dsn, $url['user'], $url['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
}

function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function js($v){ return json_encode($v, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT); }
function money($v){ return 'RWF ' . number_format((float)$v, 0); }
function current_period(){ return date('Y-m'); }
function valid_period($p){ return preg_match('/^\d{4}-\d{2}$/', (string)$p) ? $p : current_period(); }
function valid_view($v){ return in_array($v, ['dashboard','attendance','payments','members','reports'], true) ? $v : 'dashboard'; }
function redirect_app($period, $session = '', $view = 'dashboard'){
    $url = 'index.php?period=' . urlencode(valid_period($period)) . '&view=' . urlencode(valid_view($view));
    if ($session !== '' && $session !== null) $url .= '&session=' . urlencode((string)$session);
    header("Location: $url");
    exit;
}
function due_date_from_day($period,$day){
    $day=max(1,min(31,(int)$day));
    $last=(int)date('t',strtotime($period.'-01'));
    return $period.'-'.str_pad(min($day,$last),2,'0',STR_PAD_LEFT);
}
function remaining_amount($expected,$paid,$manual){
    if ($manual !== null && $manual !== '') return max(0,(float)$manual);
    return max(0,(float)$expected-(float)$paid);
}
function bill_status($expected,$paid,$remaining){
    if ((float)$expected <= 0) return 'NO BILL';
    if ((float)$remaining <= 0 && (float)$paid > 0) return 'PAID';
    if ((float)$paid > 0 && (float)$remaining > 0) return 'PARTIAL';
    return 'UNPAID';
}
function overdue_days($due,$status){
    if (in_array($status,['PAID','NO BILL'],true)) return 0;
    $today=new DateTime(date('Y-m-d'));
    $d=new DateTime($due);
    return $today > $d ? (int)$d->diff($today)->days : 0;
}
function is_true($v){
    return $v === true || $v === 1 || $v === '1' || $v === 't' || $v === 'true';
}

function ensure_schema($pdo){
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS members(
            id SERIAL PRIMARY KEY,
            full_name TEXT NOT NULL,
            phone TEXT,
            default_monthly_fee NUMERIC(12,2) NOT NULL DEFAULT 0,
            default_due_day INTEGER NOT NULL DEFAULT 5,
            monthly_fee NUMERIC(12,2) NOT NULL DEFAULT 0,
            due_day INTEGER NOT NULL DEFAULT 5,
            is_active BOOLEAN NOT NULL DEFAULT TRUE
        );

        CREATE TABLE IF NOT EXISTS sessions(
            id SERIAL PRIMARY KEY,
            name TEXT NOT NULL,
            date DATE NOT NULL DEFAULT CURRENT_DATE
        );

        CREATE TABLE IF NOT EXISTS attendance(
            id SERIAL PRIMARY KEY,
            session_id INTEGER NOT NULL REFERENCES sessions(id) ON DELETE CASCADE,
            member_id INTEGER NOT NULL REFERENCES members(id) ON DELETE CASCADE,
            UNIQUE(session_id, member_id)
        );

        CREATE TABLE IF NOT EXISTS monthly_bills(
            id SERIAL PRIMARY KEY,
            member_id INTEGER NOT NULL REFERENCES members(id) ON DELETE CASCADE,
            period CHAR(7) NOT NULL,
            expected_amount NUMERIC(12,2) NOT NULL DEFAULT 0,
            paid_amount NUMERIC(12,2) NOT NULL DEFAULT 0,
            manual_remaining_amount NUMERIC(12,2),
            due_date DATE NOT NULL,
            paid_at TIMESTAMP,
            note TEXT,
            created_at TIMESTAMP NOT NULL DEFAULT NOW(),
            updated_at TIMESTAMP NOT NULL DEFAULT NOW(),
            UNIQUE(member_id, period)
        );

        CREATE INDEX IF NOT EXISTS idx_bills_period ON monthly_bills(period);
        CREATE INDEX IF NOT EXISTS idx_attendance_member ON attendance(member_id);
        CREATE INDEX IF NOT EXISTS idx_sessions_date ON sessions(date);
    ");
}

function billing_rows($pdo,$period){
    $stmt=$pdo->prepare("
        SELECT m.*, b.expected_amount, b.paid_amount, b.manual_remaining_amount,
               b.due_date, b.paid_at, b.note
        FROM members m
        LEFT JOIN monthly_bills b ON b.member_id=m.id AND b.period=?
        ORDER BY m.full_name ASC
    ");
    $stmt->execute([$period]);
    $rows=[];

    foreach($stmt->fetchAll() as $r){
        $expected = $r['expected_amount'] !== null ? (float)$r['expected_amount'] : (float)$r['default_monthly_fee'];
        $paid = $r['paid_amount'] !== null ? (float)$r['paid_amount'] : 0;
        $due = $r['due_date'] ?: due_date_from_day($period,$r['default_due_day']);
        $remaining = remaining_amount($expected,$paid,$r['manual_remaining_amount']);
        $status = bill_status($expected,$paid,$remaining);

        $r['effective_expected']=$expected;
        $r['effective_paid']=$paid;
        $r['effective_due_date']=$due;
        $r['effective_remaining']=$remaining;
        $r['effective_status']=$status;
        $r['overdue_days']=overdue_days($due,$status);
        $rows[]=$r;
    }
    return $rows;
}

function csv_headers($name){
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="'.$name.'"');
}

try{
    $pdo=db();
    ensure_schema($pdo);

    $period=valid_period($_GET['period'] ?? current_period());
    $active_view=valid_view($_GET['view'] ?? 'dashboard');

    if(isset($_GET['export_type'])){
        $type=$_GET['export_type'];

        if(in_array($type,['payment_report','debtors_report','full_summary'],true)){
            csv_headers($type.'_'.$period.'.csv');
            $out=fopen('php://output','w');
            fputcsv($out,['Athlete','Phone','Period','Expected','Paid','Remaining','Due Date','Status','Overdue Days','Note']);

            foreach(billing_rows($pdo,$period) as $r){
                if($type==='debtors_report' && !in_array($r['effective_status'],['UNPAID','PARTIAL'],true)) continue;
                fputcsv($out,[$r['full_name'],$r['phone'],$period,$r['effective_expected'],$r['effective_paid'],$r['effective_remaining'],$r['effective_due_date'],$r['effective_status'],$r['overdue_days'],$r['note']]);
            }
            exit;
        }

        if($type==='monthly_attendance_report'){
            csv_headers('monthly_attendance_'.$period.'.csv');
            $out=fopen('php://output','w');
            fputcsv($out,['Athlete','Period','Times Attended']);

            $stmt=$pdo->prepare("
                SELECT m.full_name, COUNT(DISTINCT a.session_id) AS total
                FROM members m
                LEFT JOIN attendance a ON a.member_id=m.id
                LEFT JOIN sessions s ON s.id=a.session_id
                WHERE m.is_active=TRUE
                  AND (s.id IS NULL OR TO_CHAR(s.date,'YYYY-MM')=?)
                GROUP BY m.id,m.full_name
                ORDER BY total DESC,m.full_name ASC
            ");
            $stmt->execute([$period]);
            while($row=$stmt->fetch()) fputcsv($out,[$row['full_name'],$period,$row['total']]);
            exit;
        }

        if($type==='two_session_report'){
            $sid1=(int)($_GET['sid1'] ?? 0);
            $sid2=(int)($_GET['sid2'] ?? 0);
            if($sid1 <= 0 || $sid2 <= 0 || $sid1 === $sid2) die('Choose two different sessions.');

            $stmt=$pdo->prepare("SELECT id,name,date FROM sessions WHERE id IN (?,?) ORDER BY date ASC,id ASC");
            $stmt->execute([$sid1,$sid2]);
            $picked=$stmt->fetchAll();
            if(count($picked) < 2) die('One of the selected sessions was not found.');

            $sessionNames=[];
            foreach($picked as $ss){
                $sessionNames[(int)$ss['id']]=$ss['date'].' - '.$ss['name'];
            }

            csv_headers('two_session_attendance_'.$sid1.'_and_'.$sid2.'.csv');
            $out=fopen('php://output','w');
            fputcsv($out,['Athlete','Phone','Session 1','Session 1 Status','Session 2','Session 2 Status','Times Attended','Overall']);

            $stmt=$pdo->prepare("
                SELECT
                    m.id,
                    m.full_name,
                    m.phone,
                    MAX(CASE WHEN a.session_id=? THEN 1 ELSE 0 END) AS attended_s1,
                    MAX(CASE WHEN a.session_id=? THEN 1 ELSE 0 END) AS attended_s2,
                    COUNT(DISTINCT CASE WHEN a.session_id IN (?,?) THEN a.session_id END) AS total_attended
                FROM members m
                LEFT JOIN attendance a ON a.member_id=m.id AND a.session_id IN (?,?)
                WHERE m.is_active=TRUE
                GROUP BY m.id,m.full_name,m.phone
                ORDER BY total_attended DESC,m.full_name ASC
            ");
            $stmt->execute([$sid1,$sid2,$sid1,$sid2,$sid1,$sid2]);
            while($row=$stmt->fetch()){
                $s1=(int)$row['attended_s1']===1 ? 'PRESENT':'ABSENT';
                $s2=(int)$row['attended_s2']===1 ? 'PRESENT':'ABSENT';
                $total=(int)$row['total_attended'];
                $overall=$total===2 ? 'ATTENDED BOTH' : ($total===1 ? 'ATTENDED ONE':'MISSED BOTH');
                fputcsv($out,[$row['full_name'],$row['phone'],$sessionNames[$sid1] ?? $sid1,$s1,$sessionNames[$sid2] ?? $sid2,$s2,$total,$overall]);
            }
            exit;
        }

        if($type==='manager_summary'){
            $rows=array_filter(billing_rows($pdo,$period),fn($r)=>is_true($r['is_active']));
            $expected=$paid=$remaining=$paidCount=$partialCount=$unpaidCount=$overdueCount=0;

            foreach($rows as $r){
                $expected += $r['effective_expected'];
                $paid += $r['effective_paid'];
                $remaining += $r['effective_remaining'];
                if($r['effective_status']==='PAID') $paidCount++;
                if($r['effective_status']==='PARTIAL') $partialCount++;
                if($r['effective_status']==='UNPAID') $unpaidCount++;
                if($r['overdue_days']>0) $overdueCount++;
            }

            csv_headers('manager_summary_'.$period.'.csv');
            $out=fopen('php://output','w');
            foreach([
                ['Period',$period],
                ['Active members',count($rows)],
                ['Expected income',$expected],
                ['Collected income',$paid],
                ['Remaining balance',$remaining],
                ['Paid members',$paidCount],
                ['Partial members',$partialCount],
                ['Unpaid members',$unpaidCount],
                ['Overdue members',$overdueCount],
            ] as $line) fputcsv($out,$line);
            exit;
        }

        if($type==='filtered_status'){
            $sid=(int)($_GET['sid'] ?? 0);
            $status=($_GET['status'] ?? 'PRESENT') === 'ABSENT' ? 'ABSENT':'PRESENT';
            csv_headers('session_'.strtolower($status).'.csv');
            $out=fopen('php://output','w');
            fputcsv($out,['Athlete']);

            $sql=$status==='PRESENT'
                ? "SELECT m.full_name FROM members m JOIN attendance a ON a.member_id=m.id WHERE a.session_id=? ORDER BY m.full_name"
                : "SELECT m.full_name FROM members m WHERE m.is_active=TRUE AND m.id NOT IN (SELECT member_id FROM attendance WHERE session_id=?) ORDER BY m.full_name";

            $stmt=$pdo->prepare($sql);
            $stmt->execute([$sid]);
            while($row=$stmt->fetch()) fputcsv($out,[$row['full_name']]);
            exit;
        }
    }

    if($_SERVER['REQUEST_METHOD']==='POST'){
        $posted_period=valid_period($_POST['period'] ?? $period);
        $posted_session=$_POST['sid'] ?? ($_POST['session'] ?? ($_GET['session'] ?? ''));
        $posted_view=valid_view($_POST['view'] ?? ($_GET['view'] ?? 'dashboard'));

        if(isset($_POST['save_athlete'])){
            $name=trim($_POST['full_name'] ?? '');
            if($name !== ''){
                $phone=trim($_POST['phone'] ?? '');
                $feeRaw=trim((string)($_POST['default_monthly_fee'] ?? ''));
                $dayRaw=trim((string)($_POST['default_due_day'] ?? ''));
                $fee=$feeRaw === '' ? 0 : (float)$feeRaw;
                $day=$dayRaw === '' ? 5 : (int)$dayRaw;

                $pdo->prepare("
                    INSERT INTO members(full_name,phone,default_monthly_fee,default_due_day,monthly_fee,due_day)
                    VALUES(?,?,?,?,?,?)
                ")->execute([$name,$phone,$fee,$day,$fee,$day]);
            }
            redirect_app($posted_period, $posted_session, 'members');
        }

        if(isset($_POST['update_athlete'])){
            $fee=(float)($_POST['default_monthly_fee'] ?? 0);
            $day=(int)($_POST['default_due_day'] ?? 5);
            $pdo->prepare("
                UPDATE members SET full_name=?, phone=?, default_monthly_fee=?, default_due_day=?, monthly_fee=?, due_day=?, is_active=?
                WHERE id=?
            ")->execute([
                trim($_POST['full_name']),
                trim($_POST['phone'] ?? ''),
                $fee,$day,$fee,$day,
                isset($_POST['is_active']) ? 1 : 0,
                (int)$_POST['mid']
            ]);
            redirect_app($posted_period, $posted_session, 'members');
        }

        if(isset($_POST['delete_athlete'])){
            $pdo->prepare("DELETE FROM members WHERE id=?")->execute([(int)$_POST['mid']]);
            redirect_app($posted_period, $posted_session, 'members');
        }

        if(isset($_POST['save_session'])){
            $sname=trim($_POST['s_name'] ?? '');
            $sdate=$_POST['s_date'] ?? date('Y-m-d');
            if($sname !== ''){
                $stmt=$pdo->prepare("INSERT INTO sessions(name,date) VALUES(?,?) RETURNING id");
                $stmt->execute([$sname,$sdate]);
                $posted_session=$stmt->fetchColumn();
            }
            redirect_app($posted_period, $posted_session, 'attendance');
        }

        if(isset($_POST['mark'])){
            $pdo->prepare("INSERT INTO attendance(session_id,member_id) VALUES(?,?) ON CONFLICT DO NOTHING")
                ->execute([(int)$_POST['sid'],(int)$_POST['mid']]);
            redirect_app($posted_period, $_POST['sid'], 'attendance');
        }

        if(isset($_POST['clear_attendance'])){
            $pdo->prepare("DELETE FROM attendance WHERE session_id=? AND member_id=?")
                ->execute([(int)$_POST['sid'],(int)$_POST['mid']]);
            redirect_app($posted_period, $_POST['sid'], 'attendance');
        }

        if(isset($_POST['save_bill'])){
            $expected=(float)($_POST['expected_amount'] ?? 0);
            $paid=(float)($_POST['paid_amount'] ?? 0);
            $manualRaw=trim((string)($_POST['manual_remaining_amount'] ?? ''));
            $manual=$manualRaw==='' ? null : (float)$manualRaw;
            $p=valid_period($_POST['period'] ?? $period);
            $due=$_POST['due_date'] ?: due_date_from_day($p,5);
            $finalRemaining=remaining_amount($expected,$paid,$manual);
            $paidAt=($finalRemaining<=0 && $paid>0) ? date('Y-m-d H:i:s') : null;

            $pdo->prepare("
                INSERT INTO monthly_bills(member_id,period,expected_amount,paid_amount,manual_remaining_amount,due_date,paid_at,note,updated_at)
                VALUES(?,?,?,?,?,?,?,?,NOW())
                ON CONFLICT(member_id,period) DO UPDATE SET
                    expected_amount=EXCLUDED.expected_amount,
                    paid_amount=EXCLUDED.paid_amount,
                    manual_remaining_amount=EXCLUDED.manual_remaining_amount,
                    due_date=EXCLUDED.due_date,
                    paid_at=EXCLUDED.paid_at,
                    note=EXCLUDED.note,
                    updated_at=NOW()
            ")->execute([
                (int)$_POST['mid'],$p,$expected,$paid,$manual,$due,$paidAt,trim($_POST['note'] ?? '')
            ]);
            redirect_app($p, $posted_session, 'payments');
        }

        if(isset($_POST['reset_bill'])){
            $pdo->prepare("DELETE FROM monthly_bills WHERE member_id=? AND period=?")
                ->execute([(int)$_POST['mid'],valid_period($_POST['period'] ?? $period)]);
            redirect_app($posted_period, $posted_session, 'payments');
        }

        redirect_app($posted_period, $posted_session, $posted_view);
    }

    $sessions=$pdo->query("SELECT * FROM sessions ORDER BY date DESC,id DESC LIMIT 150")->fetchAll();
    $current_sid=$_GET['session'] ?? ($sessions[0]['id'] ?? null);

    $active_s=null;
    foreach($sessions as $s){
        if((string)$s['id']===(string)$current_sid) $active_s=$s;
    }

    $members=$pdo->query("SELECT * FROM members ORDER BY full_name ASC")->fetchAll();

    $attended_ids=[];
    if($current_sid){
        $stmt=$pdo->prepare("SELECT member_id FROM attendance WHERE session_id=?");
        $stmt->execute([(int)$current_sid]);
        $attended_ids=$stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    $attStmt=$pdo->prepare("
        SELECT m.id, COUNT(DISTINCT a.session_id) AS total
        FROM members m
        LEFT JOIN attendance a ON a.member_id=m.id
        LEFT JOIN sessions s ON s.id=a.session_id
        WHERE s.id IS NULL OR TO_CHAR(s.date,'YYYY-MM')=?
        GROUP BY m.id
    ");
    $attStmt->execute([$period]);
    $attendanceMonth=[];
    foreach($attStmt->fetchAll() as $r) $attendanceMonth[$r['id']] = (int)$r['total'];

    $billingRows=billing_rows($pdo,$period);
    $activeBillingRows=array_filter($billingRows,fn($r)=>is_true($r['is_active']));

    $expectedIncome=$collectedIncome=$remainingIncome=$paidCount=$partialCount=$unpaidCount=$overdueCount=0;
    foreach($activeBillingRows as $r){
        $expectedIncome += $r['effective_expected'];
        $collectedIncome += $r['effective_paid'];
        $remainingIncome += $r['effective_remaining'];
        if($r['effective_status']==='PAID') $paidCount++;
        if($r['effective_status']==='PARTIAL') $partialCount++;
        if($r['effective_status']==='UNPAID') $unpaidCount++;
        if($r['overdue_days']>0) $overdueCount++;
    }

}catch(Exception $e){
    die("System Error: ".h($e->getMessage()));
}
?><!DOCTYPE html><html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Ujamaa Academy Manager</title>
<script src="https://cdn.tailwindcss.com"></script>
<style>
body{font-family:Arial,sans-serif;background:#f8fafc}
.soft-card{background:#fff;border:1px solid #e2e8f0;box-shadow:0 15px 40px rgba(15,23,42,.06)}
.pill{border-radius:999px;padding:.35rem .7rem;font-size:10px;font-weight:900;text-transform:uppercase}
.modal{display:none}.modal.show{display:flex}
.glass{background:linear-gradient(135deg,rgba(255,255,255,.96),rgba(248,250,252,.92));backdrop-filter:blur(14px)}
.report-card{transition:.2s ease;position:relative;overflow:hidden}.report-card:hover{transform:translateY(-3px);box-shadow:0 20px 50px rgba(15,23,42,.10)}
.report-card:before{content:'';position:absolute;inset:0 0 auto 0;height:4px;background:linear-gradient(90deg,#4f46e5,#06b6d4)}
.input-clean{width:100%;padding:.9rem 1rem;background:#f8fafc;border:1px solid #e2e8f0;border-radius:1rem;font-weight:800;color:#0f172a}
.btn-dark{background:#020617;color:#fff;padding:1rem;border-radius:1rem;font-size:11px;font-weight:900;text-transform:uppercase}
</style>
</head><body class="text-slate-900">
<div class="min-h-screen lg:flex"><aside class="lg:w-80 bg-slate-950 text-white p-6 flex flex-col gap-6">
    <div>
        <h1 class="text-3xl font-black">UJAMAA<span class="text-indigo-400">.</span></h1>
        <p class="text-xs text-slate-400 font-bold">Academy Manager</p>
    </div><nav class="grid grid-cols-2 lg:grid-cols-1 gap-3">
    <button onclick="view('dashboard')" id="n-dashboard" class="nav-btn text-slate-400 hover:bg-slate-900 p-4 rounded-2xl font-black text-left">Dashboard</button>
    <button onclick="view('attendance')" id="n-attendance" class="nav-btn text-slate-400 hover:bg-slate-900 p-4 rounded-2xl font-black text-left">Attendance</button>
    <button onclick="view('payments')" id="n-payments" class="nav-btn text-slate-400 hover:bg-slate-900 p-4 rounded-2xl font-black text-left">Payments</button>
    <button onclick="view('members')" id="n-members" class="nav-btn text-slate-400 hover:bg-slate-900 p-4 rounded-2xl font-black text-left">Members</button>
    <button onclick="view('reports')" id="n-reports" class="nav-btn text-slate-400 hover:bg-slate-900 p-4 rounded-2xl font-black text-left">Reports</button>
</nav>

<div class="bg-white/5 border border-white/10 p-5 rounded-3xl">
    <h3 class="text-xs font-black uppercase text-indigo-300 mb-4">Add Athlete</h3>
    <form method="POST" class="space-y-3">
        <input name="full_name" placeholder="Full name only is enough" class="w-full bg-slate-900 border border-white/10 p-3 rounded-xl text-sm" required>
        <input name="phone" placeholder="Phone optional" class="w-full bg-slate-900 border border-white/10 p-3 rounded-xl text-sm">
        <input name="default_monthly_fee" type="number" step="0.01" placeholder="Monthly fee optional" class="w-full bg-slate-900 border border-white/10 p-3 rounded-xl text-sm">
        <input name="default_due_day" type="number" min="1" max="31" placeholder="Due day optional, default 5" class="w-full bg-slate-900 border border-white/10 p-3 rounded-xl text-sm">
        <input type="hidden" name="period" value="<?= h($period) ?>">
        <input type="hidden" name="sid" value="<?= h($current_sid) ?>">
        <input type="hidden" name="view" value="members">
        <button name="save_athlete" class="w-full bg-white text-slate-950 py-3 rounded-xl font-black text-xs uppercase">Save Athlete</button>
    </form>
</div>

</aside><main class="flex-1 p-5 lg:p-10">
<div class="max-w-7xl mx-auto"><header class="flex flex-col xl:flex-row xl:items-center xl:justify-between gap-5 mb-8">
    <div>
        <h2 class="text-3xl lg:text-5xl font-black">Academy Control Center</h2>
        <p class="text-slate-500 font-bold mt-2">Period: <?= h($period) ?> · Today: <?= date('Y-m-d') ?></p>
    </div>
    <form method="GET" class="bg-white border p-2 rounded-2xl flex gap-2">
        <input type="hidden" name="session" value="<?= h($current_sid) ?>">
        <input type="hidden" name="view" value="<?= h($active_view) ?>">
        <input name="period" type="month" value="<?= h($period) ?>" class="rounded-xl px-4 py-3 font-black border">
        <button class="bg-slate-950 text-white px-5 py-3 rounded-xl font-black text-xs uppercase">Open Month</button>
    </form>
</header><section id="v-dashboard" class="page space-y-8 hidden">
    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-5">
        <div class="soft-card p-6 rounded-3xl"><p class="text-xs font-black text-slate-400 uppercase">Expected</p><h3 class="text-3xl font-black"><?= money($expectedIncome) ?></h3></div>
        <div class="soft-card p-6 rounded-3xl"><p class="text-xs font-black text-slate-400 uppercase">Collected</p><h3 class="text-3xl font-black text-emerald-600"><?= money($collectedIncome) ?></h3></div>
        <div class="soft-card p-6 rounded-3xl"><p class="text-xs font-black text-slate-400 uppercase">Remaining</p><h3 class="text-3xl font-black text-amber-600"><?= money($remainingIncome) ?></h3></div>
        <div class="soft-card p-6 rounded-3xl"><p class="text-xs font-black text-slate-400 uppercase">Overdue</p><h3 class="text-3xl font-black text-red-600"><?= $overdueCount ?> members</h3></div>
    </div><div class="soft-card p-6 rounded-3xl overflow-x-auto">
    <div class="flex justify-between items-center mb-5">
        <h3 class="text-xl font-black">Manager Summary</h3>
        <a href="?export_type=full_summary&period=<?= h($period) ?>" class="bg-indigo-600 text-white px-4 py-3 rounded-xl text-xs font-black uppercase">Download</a>
    </div>
    <table class="w-full text-sm">
        <thead>
        <tr class="text-left text-xs uppercase text-slate-400 border-b">
            <th class="py-3">Athlete</th><th>Status</th><th>Expected</th><th>Paid</th><th>Remaining</th><th>Attend.</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach($billingRows as $r): ?>
        <tr class="border-b">
            <td class="py-4 font-black"><?= h($r['full_name']) ?></td>
            <td><span class="pill <?= $r['effective_status']==='PAID'?'bg-emerald-100 text-emerald-700':($r['effective_status']==='PARTIAL'?'bg-amber-100 text-amber-700':($r['effective_status']==='NO BILL'?'bg-slate-100 text-slate-600':'bg-red-100 text-red-700')) ?>"><?= h($r['effective_status']) ?></span></td>
            <td><?= money($r['effective_expected']) ?></td>
            <td><?= money($r['effective_paid']) ?></td>
            <td class="font-black"><?= money($r['effective_remaining']) ?></td>
            <td><?= (int)($attendanceMonth[$r['id']] ?? 0) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

</section><section id="v-attendance" class="page hidden space-y-6">
    <div class="soft-card p-6 rounded-3xl">
        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4 mb-6">
            <div>
                <h3 class="text-2xl font-black"><?= $active_s ? h($active_s['name']) : 'No Session Yet' ?></h3>
                <p class="text-slate-500 font-bold"><?= $active_s ? h($active_s['date']) : 'Create a session first, then mark attendance.' ?></p>
            </div>
            <div class="flex flex-col md:flex-row gap-2">
                <?php if(count($sessions)>0): ?>
                <select onchange="location.href='?session='+this.value+'&period=<?= h($period) ?>&view=attendance'" class="rounded-xl px-4 py-3 font-bold border">
                    <?php foreach($sessions as $s): ?>
                    <option value="<?= h($s['id']) ?>" <?= (string)$current_sid===(string)$s['id']?'selected':'' ?>><?= h($s['date']) ?> - <?= h($s['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php endif; ?>
                <button onclick="openModal('m-session')" class="bg-indigo-600 text-white px-5 py-3 rounded-xl font-black">Create Session</button>
            </div>
        </div><?php if(!$current_sid): ?>
        <div class="bg-amber-50 border border-amber-200 text-amber-800 p-5 rounded-2xl font-bold">No session selected. Create a session before marking attendance.</div>
    <?php else: ?>
        <input id="attendanceSearch" onkeyup="searchRows('attendanceSearch','.attendance-row')" placeholder="Search athlete..." class="w-full p-4 bg-slate-50 rounded-2xl border mb-5">

        <?php foreach($members as $m): if(!is_true($m['is_active'])) continue; $isP=in_array($m['id'],$attended_ids); ?>
        <div class="attendance-row flex items-center justify-between py-4 border-b">
            <div>
                <b class="row-name text-lg"><?= h($m['full_name']) ?></b>
                <p class="text-xs font-bold text-slate-400">This month: <?= (int)($attendanceMonth[$m['id']] ?? 0) ?> time(s)</p>
            </div>
            <form method="POST">
                <input type="hidden" name="sid" value="<?= (int)$current_sid ?>">
                <input type="hidden" name="mid" value="<?= (int)$m['id'] ?>">
                <input type="hidden" name="period" value="<?= h($period) ?>">
                <input type="hidden" name="view" value="attendance">
                <?php if($isP): ?>
                    <button name="clear_attendance" class="bg-emerald-500 text-white px-6 py-3 rounded-xl text-xs font-black uppercase">Present</button>
                <?php else: ?>
                    <button name="mark" class="border px-6 py-3 rounded-xl text-xs font-black uppercase">Mark</button>
                <?php endif; ?>
            </form>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

</section><section id="v-payments" class="page hidden space-y-6">
    <div class="flex justify-between gap-4">
        <div>
            <h3 class="text-3xl font-black">Payments</h3>
            <p class="text-slate-500 font-bold">Month: <?= h($period) ?></p>
        </div>
        <a href="?export_type=payment_report&period=<?= h($period) ?>" class="bg-slate-950 text-white px-5 py-4 rounded-2xl font-black text-xs uppercase">Download</a>
    </div><input id="paymentSearch" onkeyup="searchRows('paymentSearch','.payment-row')" placeholder="Search payment..." class="w-full p-4 bg-white rounded-2xl border">

<?php foreach($billingRows as $r): ?>
<div class="payment-row soft-card rounded-3xl p-5">
    <div class="grid grid-cols-1 xl:grid-cols-12 gap-4 xl:items-center">
        <div class="xl:col-span-3">
            <h4 class="row-name text-lg font-black"><?= h($r['full_name']) ?></h4>
            <p class="text-xs text-slate-400 font-bold">Due: <?= h($r['effective_due_date']) ?> · <?= (int)$r['overdue_days'] ?> overdue day(s)</p>
        </div>
        <div class="xl:col-span-2"><span class="pill <?= $r['effective_status']==='PAID'?'bg-emerald-100 text-emerald-700':($r['effective_status']==='PARTIAL'?'bg-amber-100 text-amber-700':($r['effective_status']==='NO BILL'?'bg-slate-100 text-slate-600':'bg-red-100 text-red-700')) ?>"><?= h($r['effective_status']) ?></span></div>
        <div class="xl:col-span-2"><small>Expected</small><br><b><?= money($r['effective_expected']) ?></b></div>
        <div class="xl:col-span-2"><small>Paid</small><br><b><?= money($r['effective_paid']) ?></b></div>
        <div class="xl:col-span-2"><small>Remaining</small><br><b class="<?= $r['effective_remaining']>0?'text-red-600':'text-emerald-600' ?>"><?= money($r['effective_remaining']) ?></b></div>
        <div class="xl:col-span-1">
            <button onclick='openBill(<?= js([
                'id'=>$r['id'],
                'name'=>$r['full_name'],
                'period'=>$period,
                'expected'=>$r['effective_expected'],
                'paid'=>$r['effective_paid'],
                'remaining'=>$r['manual_remaining_amount'],
                'due'=>$r['effective_due_date'],
                'note'=>$r['note'] ?? ''
            ]) ?>)' class="bg-indigo-600 text-white px-4 py-3 rounded-xl text-xs font-black uppercase w-full">Edit</button>
        </div>
    </div>
</div>
<?php endforeach; ?>

</section><section id="v-members" class="page hidden space-y-6">
    <h3 class="text-3xl font-black">Members</h3>
    <div class="soft-card rounded-3xl overflow-hidden">
        <?php foreach($members as $m): ?>
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 p-5 border-b">
            <div>
                <h4 class="text-lg font-black"><?= h($m['full_name']) ?></h4>
                <p class="text-xs text-slate-400 font-bold">Fee: <?= money($m['default_monthly_fee']) ?> · Due day: <?= (int)$m['default_due_day'] ?> · <?= is_true($m['is_active'])?'Active':'Inactive' ?></p>
            </div>
            <div class="flex gap-2">
                <button onclick='editMember(<?= js([
                    'id'=>$m['id'],
                    'name'=>$m['full_name'],
                    'phone'=>$m['phone'],
                    'fee'=>$m['default_monthly_fee'],
                    'due'=>$m['default_due_day'],
                    'active'=>is_true($m['is_active'])
                ]) ?>)' class="bg-slate-100 px-5 py-3 rounded-xl text-xs font-black uppercase">Edit</button>
                <button onclick="deleteMember(<?= (int)$m['id'] ?>)" class="bg-red-50 text-red-600 px-5 py-3 rounded-xl text-xs font-black uppercase">Delete</button>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</section><section id="v-reports" class="page hidden space-y-6">
    <div class="flex flex-col lg:flex-row lg:items-end lg:justify-between gap-4">
        <div>
            <p class="text-xs font-black uppercase text-indigo-600">Exports & attendance analysis</p>
            <h3 class="text-3xl lg:text-4xl font-black">Reports</h3>
            <p class="text-slate-500 font-bold mt-1">Generate payment reports, monthly attendance, and compare two sessions without duplicate athlete rows.</p>
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-5">
        <a class="report-card soft-card p-6 rounded-3xl font-black text-indigo-600" href="?export_type=payment_report&period=<?= h($period) ?>">
            <span class="block text-slate-900 text-lg mb-2">Payment Report</span>
            <span class="text-xs text-slate-400">Expected, paid, balance</span>
        </a>
        <a class="report-card soft-card p-6 rounded-3xl font-black text-red-600" href="?export_type=debtors_report&period=<?= h($period) ?>">
            <span class="block text-slate-900 text-lg mb-2">Debtors Report</span>
            <span class="text-xs text-slate-400">Unpaid and partial only</span>
        </a>
        <a class="report-card soft-card p-6 rounded-3xl font-black text-emerald-600" href="?export_type=monthly_attendance_report&period=<?= h($period) ?>">
            <span class="block text-slate-900 text-lg mb-2">Monthly Attendance</span>
            <span class="text-xs text-slate-400">Times attended in <?= h($period) ?></span>
        </a>
        <a class="report-card soft-card p-6 rounded-3xl font-black text-slate-900" href="?export_type=manager_summary&period=<?= h($period) ?>">
            <span class="block text-slate-900 text-lg mb-2">Manager Summary</span>
            <span class="text-xs text-slate-400">Income and status totals</span>
        </a>
    </div>

    <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
        <div class="glass soft-card p-6 rounded-3xl">
            <div class="mb-5">
                <h4 class="text-2xl font-black">Two Sessions Attendance Report</h4>
                <p class="text-sm text-slate-500 font-bold mt-1">This report gives one row per athlete, Session 1 status, Session 2 status, and total times attended: 0, 1, or 2.</p>
            </div>
            <form method="GET" class="space-y-4">
                <input type="hidden" name="export_type" value="two_session_report">
                <input type="hidden" name="period" value="<?= h($period) ?>">
                <div>
                    <label class="text-xs font-black uppercase text-slate-400">First session</label>
                    <select name="sid1" class="input-clean mt-2" required>
                        <option value="">Choose first session</option>
                        <?php foreach($sessions as $s): ?>
                        <option value="<?= h($s['id']) ?>"><?= h($s['date']) ?> - <?= h($s['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="text-xs font-black uppercase text-slate-400">Second session</label>
                    <select name="sid2" class="input-clean mt-2" required>
                        <option value="">Choose second session</option>
                        <?php foreach($sessions as $s): ?>
                        <option value="<?= h($s['id']) ?>"><?= h($s['date']) ?> - <?= h($s['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button class="w-full btn-dark">Download Two-Session Report</button>
            </form>
        </div>

        <div class="glass soft-card p-6 rounded-3xl">
            <div class="mb-5">
                <h4 class="text-2xl font-black">Present / Absent List</h4>
                <p class="text-sm text-slate-500 font-bold mt-1">Export only present athletes or only absent athletes for one selected session.</p>
            </div>
            <form method="GET" class="space-y-4">
                <input type="hidden" name="export_type" value="filtered_status">
                <input type="hidden" name="period" value="<?= h($period) ?>">
                <div>
                    <label class="text-xs font-black uppercase text-slate-400">Session</label>
                    <select name="sid" class="input-clean mt-2" required>
                        <?php foreach($sessions as $s): ?>
                        <option value="<?= h($s['id']) ?>"><?= h($s['date']) ?> - <?= h($s['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="text-xs font-black uppercase text-slate-400">Status</label>
                    <select name="status" class="input-clean mt-2">
                        <option value="PRESENT">Present only</option>
                        <option value="ABSENT">Absent only</option>
                    </select>
                </div>
                <button class="w-full btn-dark">Download List</button>
            </form>
        </div>
    </div>
</section></div>
</main>
</div><div id="m-session" class="modal fixed inset-0 bg-slate-950/80 z-50 items-center justify-center p-5">
<div class="bg-white rounded-3xl p-7 w-full max-w-md">
<h3 class="text-2xl font-black mb-5">New Session</h3>
<form method="POST" class="space-y-4">
<input name="s_name" placeholder="Session title" class="w-full p-4 bg-slate-50 rounded-xl border" required>
<input name="s_date" type="date" value="<?= date('Y-m-d') ?>" class="w-full p-4 bg-slate-50 rounded-xl border" required>
<input type="hidden" name="period" value="<?= h($period) ?>">
<input type="hidden" name="view" value="attendance">
<button name="save_session" class="w-full bg-indigo-600 text-white p-4 rounded-xl font-black uppercase text-xs">Create</button>
<button type="button" onclick="closeModal('m-session')" class="w-full p-2 font-bold text-slate-400">Cancel</button>
</form>
</div>
</div><div id="m-member" class="modal fixed inset-0 bg-slate-950/80 z-50 items-center justify-center p-5">
<div class="bg-white rounded-3xl p-7 w-full max-w-md">
<h3 class="text-2xl font-black mb-5">Edit Member</h3>
<form method="POST" class="space-y-4">
<input type="hidden" name="mid" id="em-id">
<input name="full_name" id="em-name" class="w-full p-4 bg-slate-50 rounded-xl border" required>
<input name="phone" id="em-phone" class="w-full p-4 bg-slate-50 rounded-xl border">
<input name="default_monthly_fee" id="em-fee" type="number" step="0.01" class="w-full p-4 bg-slate-50 rounded-xl border">
<input name="default_due_day" id="em-due" type="number" min="1" max="31" class="w-full p-4 bg-slate-50 rounded-xl border">
<label class="flex gap-2 font-bold"><input type="checkbox" name="is_active" id="em-active"> Active member</label>
<input type="hidden" name="period" value="<?= h($period) ?>">
<input type="hidden" name="sid" value="<?= h($current_sid) ?>">
<input type="hidden" name="view" value="members">
<button name="update_athlete" class="w-full bg-indigo-600 text-white p-4 rounded-xl font-black uppercase text-xs">Save</button>
<button type="button" onclick="closeModal('m-member')" class="w-full p-2 font-bold text-slate-400">Cancel</button>
</form>
</div>
</div><div id="m-delete" class="modal fixed inset-0 bg-slate-950/80 z-50 items-center justify-center p-5">
<div class="bg-white rounded-3xl p-7 w-full max-w-md text-center">
<h3 class="text-2xl font-black text-red-600 mb-3">Delete Member?</h3>
<p class="text-slate-500 font-bold mb-5">This deletes attendance and payment records too.</p>
<form method="POST">
<input type="hidden" name="mid" id="del-id">
<input type="hidden" name="period" value="<?= h($period) ?>">
<input type="hidden" name="sid" value="<?= h($current_sid) ?>">
<input type="hidden" name="view" value="members">
<button name="delete_athlete" class="w-full bg-red-600 text-white p-4 rounded-xl font-black uppercase text-xs">Delete</button>
<button type="button" onclick="closeModal('m-delete')" class="w-full p-2 font-bold text-slate-400">Cancel</button>
</form>
</div>
</div><div id="m-bill" class="modal fixed inset-0 bg-slate-950/80 z-50 items-center justify-center p-5">
<div class="bg-white rounded-3xl p-7 w-full max-w-lg">
<h3 class="text-2xl font-black mb-1">Edit Payment</h3>
<p id="bill-athlete" class="text-slate-500 font-bold mb-5"></p>
<form method="POST" class="space-y-4">
<input type="hidden" name="mid" id="bill-mid">
<input type="hidden" name="period" id="bill-period">
<input type="hidden" name="sid" value="<?= h($current_sid) ?>">
<input type="hidden" name="view" value="payments">
<input name="due_date" id="bill-due" type="date" class="w-full p-4 bg-slate-50 rounded-xl border" required>
<input name="expected_amount" id="bill-expected" type="number" step="0.01" class="w-full p-4 bg-slate-50 rounded-xl border" required>
<input name="paid_amount" id="bill-paid" type="number" step="0.01" class="w-full p-4 bg-slate-50 rounded-xl border" required>
<input name="manual_remaining_amount" id="bill-remaining" type="number" step="0.01" placeholder="Leave empty to auto-calculate" class="w-full p-4 bg-slate-50 rounded-xl border">
<input name="note" id="bill-note" placeholder="Payment note" class="w-full p-4 bg-slate-50 rounded-xl border">
<div class="grid grid-cols-2 gap-3">
<button name="save_bill" class="bg-indigo-600 text-white p-4 rounded-xl font-black uppercase text-xs">Save Payment</button>
<button name="reset_bill" class="bg-slate-100 p-4 rounded-xl font-black uppercase text-xs">Reset Month</button>
</div>
<button type="button" onclick="closeModal('m-bill')" class="w-full p-2 font-bold text-slate-400">Cancel</button>
</form>
</div>
</div><script>
const initialView = <?= js($active_view) ?>;
function view(id){
    document.querySelectorAll('.page').forEach(p=>p.classList.add('hidden'));
    document.getElementById('v-'+id).classList.remove('hidden');
    document.querySelectorAll('.nav-btn').forEach(b=>b.className='nav-btn text-slate-400 hover:bg-slate-900 p-4 rounded-2xl font-black text-left');
    document.getElementById('n-'+id).className='nav-btn bg-indigo-600 p-4 rounded-2xl font-black text-left';
    const url = new URL(window.location.href);
    url.searchParams.set('view', id);
    history.replaceState(null, '', url.toString());
}
function searchRows(inputId,selector){
    const q=document.getElementById(inputId).value.toLowerCase();
    document.querySelectorAll(selector).forEach(row=>{
        const name=row.querySelector('.row-name')?.innerText.toLowerCase() || '';
        row.style.display=name.includes(q)?'':'none';
    });
}
function openModal(id){document.getElementById(id).classList.add('show')}
function closeModal(id){document.getElementById(id).classList.remove('show')}
function editMember(m){
    document.getElementById('em-id').value=m.id;
    document.getElementById('em-name').value=m.name || '';
    document.getElementById('em-phone').value=m.phone || '';
    document.getElementById('em-fee').value=m.fee || 0;
    document.getElementById('em-due').value=m.due || 5;
    document.getElementById('em-active').checked=!!m.active;
    openModal('m-member');
}
function deleteMember(id){
    document.getElementById('del-id').value=id;
    openModal('m-delete');
}
function openBill(b){
    document.getElementById('bill-mid').value=b.id;
    document.getElementById('bill-period').value=b.period;
    document.getElementById('bill-athlete').innerText=b.name+' · '+b.period;
    document.getElementById('bill-expected').value=b.expected || 0;
    document.getElementById('bill-paid').value=b.paid || 0;
    document.getElementById('bill-remaining').value=b.remaining ?? '';
    document.getElementById('bill-due').value=b.due;
    document.getElementById('bill-note').value=b.note || '';
    openModal('m-bill');
}
document.addEventListener('keydown',e=>{
    if(e.key==='Escape') document.querySelectorAll('.modal').forEach(m=>m.classList.remove('show'));
});
document.addEventListener('DOMContentLoaded',()=>view(initialView));
</script></body>
</html>
