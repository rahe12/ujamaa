<?php
/**
 * UJAMAA ACADEMY - APEX EDITION V8.0
 * Attendance + Payment Tracking + Monthly Auto Reset + Manager Reports
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// 1. DATABASE CONNECTION
function get_db_connection() {
    $databaseUrl = getenv("DATABASE_URL");
    if (!$databaseUrl) die("DATABASE_URL is missing.");

    $url = parse_url($databaseUrl);
    $dsn = "pgsql:host={$url['host']};port=" . ($url['port'] ?? 5432) . ";dbname=" . ltrim($url['path'], '/') . ";sslmode=require";

    return new PDO($dsn, $url['user'], $url['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
}

function h($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function money($value) { return number_format((float)$value, 0); }
function current_period() { return date('Y-m'); }
function current_month_start() { return date('Y-m-01'); }
function current_month_end() { return date('Y-m-t'); }
function clamp_due_day($day) { return max(1, min(31, (int)$day)); }
function member_due_date($period, $dueDay) {
    $dueDay = clamp_due_day($dueDay);
    $lastDay = (int)date('t', strtotime($period . '-01'));
    $safeDay = min($dueDay, $lastDay);
    return $period . '-' . str_pad((string)$safeDay, 2, '0', STR_PAD_LEFT);
}
function overdue_days($period, $dueDay, $isPaid) {
    if ($isPaid) return 0;
    $today = new DateTime(date('Y-m-d'));
    $due = new DateTime(member_due_date($period, $dueDay));
    if ($today <= $due) return 0;
    return (int)$due->diff($today)->days;
}

// 2. DATABASE MIGRATION - safe to run every page load on PostgreSQL
function ensure_schema(PDO $pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS members (
        id SERIAL PRIMARY KEY,
        full_name TEXT NOT NULL
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS sessions (
        id SERIAL PRIMARY KEY,
        name TEXT NOT NULL,
        date DATE NOT NULL DEFAULT CURRENT_DATE
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS attendance (
        id SERIAL PRIMARY KEY,
        session_id INTEGER NOT NULL REFERENCES sessions(id) ON DELETE CASCADE,
        member_id INTEGER NOT NULL REFERENCES members(id) ON DELETE CASCADE,
        UNIQUE(session_id, member_id)
    )");

    $pdo->exec("ALTER TABLE members ADD COLUMN IF NOT EXISTS due_day INTEGER NOT NULL DEFAULT 5");
    $pdo->exec("ALTER TABLE members ADD COLUMN IF NOT EXISTS monthly_fee NUMERIC(12,2) NOT NULL DEFAULT 0");
    $pdo->exec("ALTER TABLE members ADD COLUMN IF NOT EXISTS is_active BOOLEAN NOT NULL DEFAULT TRUE");

    $pdo->exec("CREATE TABLE IF NOT EXISTS payments (
        id SERIAL PRIMARY KEY,
        member_id INTEGER NOT NULL REFERENCES members(id) ON DELETE CASCADE,
        period CHAR(7) NOT NULL DEFAULT TO_CHAR(CURRENT_DATE, 'YYYY-MM'),
        amount NUMERIC(12,2) NOT NULL DEFAULT 0,
        paid_at TIMESTAMP NOT NULL DEFAULT NOW(),
        note TEXT
    )");

    // Fix old payments tables that may have been created without these columns.
    $pdo->exec("ALTER TABLE payments ADD COLUMN IF NOT EXISTS member_id INTEGER");
    $pdo->exec("ALTER TABLE payments ADD COLUMN IF NOT EXISTS period CHAR(7) NOT NULL DEFAULT TO_CHAR(CURRENT_DATE, 'YYYY-MM')");
    $pdo->exec("ALTER TABLE payments ADD COLUMN IF NOT EXISTS amount NUMERIC(12,2) NOT NULL DEFAULT 0");
    $pdo->exec("ALTER TABLE payments ADD COLUMN IF NOT EXISTS paid_at TIMESTAMP NOT NULL DEFAULT NOW()");
    $pdo->exec("ALTER TABLE payments ADD COLUMN IF NOT EXISTS note TEXT");

    // Add constraints/indexes safely after columns exist.
    $pdo->exec("DO $$ BEGIN
        IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'payments_member_id_fkey') THEN
            ALTER TABLE payments ADD CONSTRAINT payments_member_id_fkey FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE;
        END IF;
    END $$;");
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS payments_member_period_unique ON payments(member_id, period)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_payments_period ON payments(period)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_sessions_date ON sessions(date)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_attendance_member ON attendance(member_id)");
}

// 3. REPORTING ENGINE
if (isset($_GET['export_type'])) {
    try {
        $pdo = get_db_connection();
        ensure_schema($pdo);
        $type = $_GET['export_type'];
        $period = $_GET['period'] ?? current_period();

        if ($type === 'filtered_status') {
            $sid = (int)$_GET['sid'];
            $status_filter = $_GET['status'];
            header('Content-Type: text/csv');
            header("Content-Disposition: attachment; filename=Session_{$status_filter}_List.csv");
            $output = fopen('php://output', 'w');
            fputcsv($output, ["LIST OF ATHLETES: $status_filter"]);

            $query = ($status_filter === 'PRESENT')
                ? "SELECT m.full_name FROM members m JOIN attendance a ON a.member_id = m.id WHERE a.session_id = ? ORDER BY m.full_name ASC"
                : "SELECT m.full_name FROM members m WHERE m.id NOT IN (SELECT member_id FROM attendance WHERE session_id = ?) ORDER BY m.full_name ASC";

            $stmt = $pdo->prepare($query); $stmt->execute([$sid]);
            while ($row = $stmt->fetch()) fputcsv($output, $row);
            exit;
        }

        if ($type === 'unique_attendees') {
            $session_ids = $_GET['sids'] ?? [];
            if(empty($session_ids)) die("Select at least one session");
            $placeholders = implode(',', array_fill(0, count($session_ids), '?'));
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename=Unique_Attendees.csv');
            $output = fopen('php://output', 'w');
            fputcsv($output, ["Athletes who attended at least one selected session"]);
            $stmt = $pdo->prepare("SELECT DISTINCT m.full_name FROM members m JOIN attendance a ON a.member_id = m.id WHERE a.session_id IN ($placeholders) ORDER BY m.full_name ASC");
            $stmt->execute($session_ids);
            while ($row = $stmt->fetch()) fputcsv($output, $row);
            exit;
        }

        if ($type === 'master_list') {
            $session_ids = $_GET['sids'] ?? [];
            if(empty($session_ids)) die("Select sessions");
            $placeholders = implode(',', array_fill(0, count($session_ids), '?'));
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename=Master_Attendance_List.csv');
            $output = fopen('php://output', 'w');
            fputcsv($output, ['Athlete Name', 'Total Sessions Attended']);
            $stmt = $pdo->prepare("SELECT m.full_name, COUNT(a.id) as total FROM members m JOIN attendance a ON a.member_id = m.id WHERE a.session_id IN ($placeholders) GROUP BY m.full_name ORDER BY total DESC, m.full_name ASC");
            $stmt->execute($session_ids);
            while ($row = $stmt->fetch()) fputcsv($output, $row);
            exit;
        }

        if ($type === 'consistency') {
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename=Consistency.csv');
            $output = fopen('php://output', 'w');
            fputcsv($output, ['Athlete', 'Session A', 'Session B', 'Logic']);
            $stmt = $pdo->prepare("SELECT m.full_name,
                CASE WHEN a1.id IS NOT NULL THEN 'PRESENT' ELSE 'ABSENT' END as sA,
                CASE WHEN a2.id IS NOT NULL THEN 'PRESENT' ELSE 'ABSENT' END as sB,
                CASE
                    WHEN a1.id IS NOT NULL AND a2.id IS NOT NULL THEN 'CONSISTENT'
                    WHEN a1.id IS NULL AND a2.id IS NULL THEN 'ABSENT BOTH'
                    ELSE 'INCONSISTENT'
                END as logic
                FROM members m
                LEFT JOIN attendance a1 ON a1.member_id = m.id AND a1.session_id = ?
                LEFT JOIN attendance a2 ON a2.member_id = m.id AND a2.session_id = ?
                ORDER BY m.full_name ASC");
            $stmt->execute([(int)$_GET['sidA'], (int)$_GET['sidB']]);
            while ($row = $stmt->fetch()) fputcsv($output, $row);
            exit;
        }

        if ($type === 'payment_report') {
            header('Content-Type: text/csv');
            header("Content-Disposition: attachment; filename=Payment_Report_{$period}.csv");
            $output = fopen('php://output', 'w');
            fputcsv($output, ['Athlete', 'Period', 'Monthly Fee', 'Due Date', 'Status', 'Days Overdue', 'Paid At', 'Amount Paid', 'Note']);
            $stmt = $pdo->prepare("SELECT m.id, m.full_name, m.monthly_fee, m.due_day, p.amount, p.paid_at, p.note
                FROM members m
                LEFT JOIN payments p ON p.member_id = m.id AND p.period = ?
                WHERE m.is_active = TRUE
                ORDER BY m.full_name ASC");
            $stmt->execute([$period]);
            while ($r = $stmt->fetch()) {
                $isPaid = !empty($r['paid_at']);
                fputcsv($output, [
                    $r['full_name'], $period, $r['monthly_fee'], member_due_date($period, $r['due_day']),
                    $isPaid ? 'PAID' : 'UNPAID', overdue_days($period, $r['due_day'], $isPaid),
                    $r['paid_at'] ?? '', $r['amount'] ?? 0, $r['note'] ?? ''
                ]);
            }
            exit;
        }

        if ($type === 'monthly_attendance_report') {
            header('Content-Type: text/csv');
            header("Content-Disposition: attachment; filename=Monthly_Attendance_{$period}.csv");
            $output = fopen('php://output', 'w');
            fputcsv($output, ['Athlete', 'Period', 'Times Attended']);
            $stmt = $pdo->prepare("SELECT m.full_name, COUNT(a.id) AS total
                FROM members m
                LEFT JOIN attendance a ON a.member_id = m.id
                LEFT JOIN sessions s ON s.id = a.session_id AND TO_CHAR(s.date, 'YYYY-MM') = ?
                WHERE m.is_active = TRUE
                GROUP BY m.full_name
                ORDER BY total DESC, m.full_name ASC");
            $stmt->execute([$period]);
            while ($row = $stmt->fetch()) fputcsv($output, [$row['full_name'], $period, $row['total']]);
            exit;
        }

        if ($type === 'manager_summary') {
            header('Content-Type: text/csv');
            header("Content-Disposition: attachment; filename=Manager_Summary_{$period}.csv");
            $output = fopen('php://output', 'w');
            fputcsv($output, ['Metric', 'Value']);
            $paid = (int)$pdo->prepare("SELECT COUNT(*) FROM payments WHERE period = ?")->execute([$period]);
            $active = (int)$pdo->query("SELECT COUNT(*) FROM members WHERE is_active = TRUE")->fetchColumn();
            $paidCountStmt = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE period = ?"); $paidCountStmt->execute([$period]);
            $paidCount = (int)$paidCountStmt->fetchColumn();
            $incomeStmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE period = ?"); $incomeStmt->execute([$period]);
            $income = $incomeStmt->fetchColumn();
            $sessionsCountStmt = $pdo->prepare("SELECT COUNT(*) FROM sessions WHERE TO_CHAR(date, 'YYYY-MM') = ?"); $sessionsCountStmt->execute([$period]);
            $sessionsCount = (int)$sessionsCountStmt->fetchColumn();
            fputcsv($output, ['Active members', $active]);
            fputcsv($output, ['Paid members', $paidCount]);
            fputcsv($output, ['Unpaid members', max(0, $active - $paidCount)]);
            fputcsv($output, ['Collected income', $income]);
            fputcsv($output, ['Sessions this month', $sessionsCount]);
            exit;
        }

    } catch (Exception $e) { die("Export Error: " . $e->getMessage()); }
}

// 4. DATA CONTROLLER
try {
    $pdo = get_db_connection();
    ensure_schema($pdo);
    $period = $_GET['period'] ?? current_period();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['save_athlete'])) {
            $pdo->prepare("INSERT INTO members(full_name, due_day, monthly_fee) VALUES (?, ?, ?)")
                ->execute([trim($_POST['full_name']), clamp_due_day($_POST['due_day'] ?? 5), (float)($_POST['monthly_fee'] ?? 0)]);
        }
        if (isset($_POST['update_athlete'])) {
            $pdo->prepare("UPDATE members SET full_name = ?, due_day = ?, monthly_fee = ?, is_active = ? WHERE id = ?")
                ->execute([trim($_POST['full_name']), clamp_due_day($_POST['due_day']), (float)$_POST['monthly_fee'], isset($_POST['is_active']) ? 1 : 0, (int)$_POST['mid']]);
        }
        if (isset($_POST['delete_athlete'])) $pdo->prepare("DELETE FROM members WHERE id = ?")->execute([(int)$_POST['mid']]);
        if (isset($_POST['save_session'])) $pdo->prepare("INSERT INTO sessions(name, date) VALUES (?, ?)")->execute([trim($_POST['s_name']), $_POST['s_date']]);
        if (isset($_POST['mark'])) $pdo->prepare("INSERT INTO attendance(session_id, member_id) VALUES (?, ?) ON CONFLICT DO NOTHING")->execute([(int)$_POST['sid'], (int)$_POST['mid']]);
        if (isset($_POST['mark_paid'])) {
            $amount = (float)$_POST['amount'];
            $pdo->prepare("INSERT INTO payments(member_id, period, amount, note) VALUES (?, ?, ?, ?)
                ON CONFLICT(member_id, period) DO UPDATE SET amount = EXCLUDED.amount, paid_at = NOW(), note = EXCLUDED.note")
                ->execute([(int)$_POST['mid'], $_POST['period'], $amount, trim($_POST['note'] ?? '')]);
        }
        if (isset($_POST['unmark_paid'])) {
            $pdo->prepare("DELETE FROM payments WHERE member_id = ? AND period = ?")->execute([(int)$_POST['mid'], $_POST['period']]);
        }
        header("Location: index.php?session=" . ($_POST['sid'] ?? ($_GET['session'] ?? '')) . "&period=" . ($_POST['period'] ?? $period)); exit;
    }

    if (isset($_GET['action']) && $_GET['action'] === 'unmark') {
        $pdo->prepare("DELETE FROM attendance WHERE member_id = ? AND session_id = ?")->execute([(int)$_GET['mid'], (int)$_GET['sid']]);
        header("Location: index.php?session=" . (int)$_GET['sid'] . "&period=" . h($period)); exit;
    }

    $sessions = $pdo->query("SELECT * FROM sessions ORDER BY date DESC, id DESC LIMIT 100")->fetchAll();
    $current_sid = $_GET['session'] ?? ($sessions[0]['id'] ?? null);
    $active_s = null; foreach($sessions as $s) if($s['id'] == $current_sid) $active_s = $s;
    $members = $pdo->query("SELECT * FROM members ORDER BY full_name ASC")->fetchAll();

    $attended_ids = [];
    if ($current_sid) {
        $stmt = $pdo->prepare("SELECT member_id FROM attendance WHERE session_id = ?");
        $stmt->execute([(int)$current_sid]);
        $attended_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    $payStmt = $pdo->prepare("SELECT * FROM payments WHERE period = ?");
    $payStmt->execute([$period]);
    $payments = [];
    foreach ($payStmt->fetchAll() as $p) $payments[$p['member_id']] = $p;

    $attMonthStmt = $pdo->prepare("SELECT m.id, COUNT(a.id) AS total
        FROM members m
        LEFT JOIN attendance a ON a.member_id = m.id
        LEFT JOIN sessions s ON s.id = a.session_id AND TO_CHAR(s.date, 'YYYY-MM') = ?
        GROUP BY m.id");
    $attMonthStmt->execute([$period]);
    $attendanceMonth = [];
    foreach ($attMonthStmt->fetchAll() as $r) $attendanceMonth[$r['id']] = (int)$r['total'];

    $activeMembers = array_filter($members, fn($m) => !isset($m['is_active']) || $m['is_active']);
    $paidCount = 0; $unpaidCount = 0; $overdueCount = 0; $expectedIncome = 0; $collectedIncome = 0;
    foreach ($activeMembers as $m) {
        $expectedIncome += (float)$m['monthly_fee'];
        $isPaid = isset($payments[$m['id']]);
        if ($isPaid) { $paidCount++; $collectedIncome += (float)$payments[$m['id']]['amount']; }
        else { $unpaidCount++; if (overdue_days($period, $m['due_day'], false) > 0) $overdueCount++; }
    }

} catch (Exception $e) { die("System Error: " . h($e->getMessage())); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ujamaa Academy | Apex v8.0</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;800&display=swap" rel="stylesheet">
    <style> body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: #f8fafc; } </style>
</head>
<body class="antialiased">
<div class="flex flex-col lg:flex-row min-h-screen">
    <aside class="w-full lg:w-80 bg-slate-900 p-8 text-white flex flex-col">
        <div class="mb-12"><h1 class="text-3xl font-extrabold italic">UJAMAA<span class="text-indigo-400">.</span></h1><p class="text-[10px] text-slate-500 font-black uppercase">Apex v8 Manager</p></div>
        <nav class="space-y-4 flex-1">
            <button onclick="view('marking')" id="n-marking" class="nav-btn w-full text-left p-4 rounded-2xl font-bold bg-indigo-600 shadow-xl">Registry Hub</button>
            <button onclick="view('payments')" id="n-payments" class="nav-btn w-full text-left p-4 rounded-2xl font-bold text-slate-400 hover:bg-slate-800 transition">Payments</button>
            <button onclick="view('summary')" id="n-summary" class="nav-btn w-full text-left p-4 rounded-2xl font-bold text-slate-400 hover:bg-slate-800 transition">Summary</button>
            <button onclick="view('intel')" id="n-intel" class="nav-btn w-full text-left p-4 rounded-2xl font-bold text-slate-400 hover:bg-slate-800 transition">Reports</button>
        </nav>
        <div class="bg-white/5 p-6 rounded-[2rem] border border-white/10 mt-10">
            <h3 class="text-[10px] font-black uppercase text-indigo-400 mb-3 tracking-widest">New Recruit</h3>
            <form method="POST" class="space-y-3">
                <input name="full_name" placeholder="Full Name" class="w-full bg-slate-950 border-none p-3 rounded-xl text-sm" required>
                <input name="monthly_fee" type="number" step="0.01" placeholder="Monthly Fee" class="w-full bg-slate-950 border-none p-3 rounded-xl text-sm">
                <input name="due_day" type="number" min="1" max="31" value="5" class="w-full bg-slate-950 border-none p-3 rounded-xl text-sm" required>
                <button name="save_athlete" class="w-full bg-white text-slate-900 py-3 rounded-xl font-black text-[10px] uppercase">Add Athlete</button>
            </form>
        </div>
    </aside>

    <main class="flex-1 p-6 lg:p-12 overflow-y-auto">
        <div id="v-marking" class="page max-w-5xl mx-auto">
            <header class="flex flex-col md:flex-row md:justify-between md:items-center gap-4 mb-10">
                <div><h2 class="text-3xl font-black text-slate-900"><?= $active_s ? h($active_s['name']) : 'Dashboard' ?></h2><p class="text-slate-400 font-bold"><?= $active_s ? h($active_s['date']) : '' ?></p></div>
                <div class="flex gap-2">
                    <select onchange="location.href='?session='+this.value+'&period=<?= h($period) ?>'" class="bg-white rounded-xl px-4 py-3 font-bold text-sm shadow-sm ring-1 ring-slate-200">
                        <?php foreach($sessions as $s): ?><option value="<?= h($s['id']) ?>" <?= $current_sid == $s['id'] ? 'selected' : '' ?>><?= h($s['date']) ?> - <?= h($s['name']) ?></option><?php endforeach; ?>
                    </select>
                    <button onclick="openModal('m-session')" class="bg-indigo-600 text-white w-12 h-12 rounded-xl font-bold shadow-lg">+</button>
                </div>
            </header>

            <input type="text" id="aSearch" onkeyup="searchRows('aSearch', '.a-row')" placeholder="Search athlete..." class="w-full p-5 bg-white rounded-3xl mb-8 shadow-sm ring-1 ring-slate-200 outline-none focus:ring-2 focus:ring-indigo-500">

            <div class="bg-white rounded-[2.5rem] shadow-sm ring-1 ring-slate-200 overflow-hidden">
                <?php foreach($members as $m): $isP = in_array($m['id'], $attended_ids); ?>
                <div class="a-row flex items-center justify-between px-10 py-5 border-b border-slate-50 last:border-0">
                    <div>
                        <span class="font-extrabold text-lg text-slate-800 row-name"><?= h($m['full_name']) ?></span>
                        <div class="text-[10px] font-bold text-slate-400 mt-1">This month: <?= $attendanceMonth[$m['id']] ?? 0 ?> attendance<?= (($attendanceMonth[$m['id']] ?? 0) == 1) ? '' : 's' ?></div>
                        <div class="flex gap-3 mt-1">
                            <button onclick="editMember(<?= (int)$m['id'] ?>, '<?= h($m['full_name']) ?>', '<?= h($m['monthly_fee']) ?>', '<?= h($m['due_day']) ?>', <?= !empty($m['is_active']) ? 'true' : 'false' ?>)" class="text-[9px] font-black uppercase text-slate-400 hover:text-indigo-600">Edit</button>
                            <button onclick="deleteMember(<?= (int)$m['id'] ?>)" class="text-[9px] font-black uppercase text-slate-400 hover:text-red-500">Delete</button>
                        </div>
                    </div>
                    <?php if($isP): ?>
                        <a href="?action=unmark&mid=<?= (int)$m['id'] ?>&sid=<?= (int)$current_sid ?>&period=<?= h($period) ?>" class="bg-emerald-500 text-white px-8 py-3 rounded-2xl text-[10px] font-black uppercase shadow-lg shadow-emerald-100">Present</a>
                    <?php else: ?>
                        <form method="POST"><input type="hidden" name="sid" value="<?= (int)$current_sid ?>"><input type="hidden" name="mid" value="<?= (int)$m['id'] ?>"><button name="mark" class="border-2 border-slate-100 text-slate-400 px-8 py-3 rounded-2xl text-[10px] font-black uppercase hover:border-indigo-600">Mark</button></form>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div id="v-payments" class="page hidden max-w-6xl mx-auto space-y-8">
            <header class="flex flex-col md:flex-row md:items-end md:justify-between gap-4">
                <div><h2 class="text-4xl font-black text-slate-900">Payments</h2><p class="text-slate-400 font-bold">Month: <?= h($period) ?>. A new month automatically starts unpaid until you mark paid.</p></div>
                <form method="GET" class="flex gap-2"><input type="hidden" name="session" value="<?= h($current_sid) ?>"><input type="month" name="period" value="<?= h($period) ?>" class="p-3 rounded-xl bg-white ring-1 ring-slate-200 font-bold"><button class="bg-slate-900 text-white px-5 rounded-xl font-black text-xs uppercase">Open</button></form>
            </header>

            <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
                <div class="bg-white p-5 rounded-3xl"><p class="text-[10px] uppercase font-black text-slate-400">Active</p><b class="text-2xl"><?= count($activeMembers) ?></b></div>
                <div class="bg-emerald-50 p-5 rounded-3xl"><p class="text-[10px] uppercase font-black text-emerald-600">Paid</p><b class="text-2xl"><?= $paidCount ?></b></div>
                <div class="bg-rose-50 p-5 rounded-3xl"><p class="text-[10px] uppercase font-black text-rose-600">Unpaid</p><b class="text-2xl"><?= $unpaidCount ?></b></div>
                <div class="bg-amber-50 p-5 rounded-3xl"><p class="text-[10px] uppercase font-black text-amber-600">Overdue</p><b class="text-2xl"><?= $overdueCount ?></b></div>
                <div class="bg-indigo-50 p-5 rounded-3xl"><p class="text-[10px] uppercase font-black text-indigo-600">Collected</p><b class="text-2xl"><?= money($collectedIncome) ?></b></div>
            </div>

            <input type="text" id="pSearch" onkeyup="searchRows('pSearch', '.p-row')" placeholder="Search payment member..." class="w-full p-5 bg-white rounded-3xl shadow-sm ring-1 ring-slate-200 outline-none">

            <div class="bg-white rounded-[2.5rem] shadow-sm ring-1 ring-slate-200 overflow-hidden">
                <?php foreach($members as $m):
                    $isPaid = isset($payments[$m['id']]);
                    $daysLate = overdue_days($period, $m['due_day'], $isPaid);
                    $dueDate = member_due_date($period, $m['due_day']);
                ?>
                <div class="p-row grid grid-cols-1 lg:grid-cols-12 gap-4 items-center px-8 py-5 border-b border-slate-50 last:border-0">
                    <div class="lg:col-span-4"><b class="row-name text-slate-800"><?= h($m['full_name']) ?></b><p class="text-[10px] font-bold text-slate-400">Due: <?= h($dueDate) ?> | Fee: <?= money($m['monthly_fee']) ?></p></div>
                    <div class="lg:col-span-2">
                        <?php if($isPaid): ?><span class="bg-emerald-100 text-emerald-700 px-4 py-2 rounded-xl text-[10px] font-black uppercase">Paid</span><?php else: ?><span class="bg-rose-100 text-rose-700 px-4 py-2 rounded-xl text-[10px] font-black uppercase">Unpaid</span><?php endif; ?>
                    </div>
                    <div class="lg:col-span-2 text-sm font-black <?= $daysLate > 0 ? 'text-rose-600' : 'text-slate-400' ?>"><?= $daysLate > 0 ? $daysLate . ' day(s) late' : 'No overdue' ?></div>
                    <div class="lg:col-span-4">
                        <?php if($isPaid): ?>
                        <form method="POST" class="flex gap-2 justify-end"><input type="hidden" name="mid" value="<?= (int)$m['id'] ?>"><input type="hidden" name="period" value="<?= h($period) ?>"><button name="unmark_paid" class="bg-slate-100 text-slate-500 px-5 py-3 rounded-xl text-[10px] font-black uppercase">Undo Paid</button></form>
                        <?php else: ?>
                        <form method="POST" class="flex gap-2 justify-end"><input type="hidden" name="mid" value="<?= (int)$m['id'] ?>"><input type="hidden" name="period" value="<?= h($period) ?>"><input name="amount" type="number" step="0.01" value="<?= h($m['monthly_fee']) ?>" class="w-28 bg-slate-50 rounded-xl p-3 text-sm font-bold"><input name="note" placeholder="Note" class="hidden md:block w-28 bg-slate-50 rounded-xl p-3 text-sm"><button name="mark_paid" class="bg-indigo-600 text-white px-5 py-3 rounded-xl text-[10px] font-black uppercase">Mark Paid</button></form>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div id="v-summary" class="page hidden max-w-6xl mx-auto space-y-8">
            <h2 class="text-4xl font-black text-slate-900">Summary List</h2>
            <div class="bg-white p-8 rounded-[2.5rem] ring-1 ring-slate-200 overflow-x-auto">
                <table class="w-full text-sm">
                    <thead><tr class="text-left text-[10px] uppercase text-slate-400 font-black border-b"><th class="p-3">Athlete</th><th>Paid?</th><th>Overdue Days</th><th>Times Attended</th><th>Monthly Fee</th></tr></thead>
                    <tbody>
                    <?php foreach($members as $m): $isPaid = isset($payments[$m['id']]); $daysLate = overdue_days($period, $m['due_day'], $isPaid); ?>
                        <tr class="border-b border-slate-50"><td class="p-3 font-bold"><?= h($m['full_name']) ?></td><td><?= $isPaid ? 'PAID' : 'UNPAID' ?></td><td><?= $daysLate ?></td><td><?= $attendanceMonth[$m['id']] ?? 0 ?></td><td><?= money($m['monthly_fee']) ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div id="v-intel" class="page hidden max-w-5xl mx-auto space-y-8">
            <h2 class="text-4xl font-black text-slate-900">Reports</h2>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <a href="?export_type=payment_report&period=<?= h($period) ?>" class="bg-white p-8 rounded-[2.5rem] shadow-sm border border-slate-100 font-black text-indigo-600">Download Payment Report</a>
                <a href="?export_type=monthly_attendance_report&period=<?= h($period) ?>" class="bg-white p-8 rounded-[2.5rem] shadow-sm border border-slate-100 font-black text-indigo-600">Download Monthly Attendance</a>
                <a href="?export_type=manager_summary&period=<?= h($period) ?>" class="bg-white p-8 rounded-[2.5rem] shadow-sm border border-slate-100 font-black text-indigo-600">Download Manager Summary</a>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div class="bg-white p-8 rounded-[2.5rem] shadow-sm border border-slate-100">
                    <h3 class="font-black text-indigo-600 uppercase text-xs tracking-widest mb-4">Strict Status Filter</h3>
                    <form method="GET" class="space-y-3"><input type="hidden" name="export_type" value="filtered_status"><select name="sid" class="w-full p-3 bg-slate-50 rounded-xl font-bold"><?php foreach($sessions as $s): ?><option value="<?= h($s['id']) ?>"><?= h($s['date']) ?> - <?= h($s['name']) ?></option><?php endforeach; ?></select><select name="status" class="w-full p-3 bg-slate-50 rounded-xl font-bold"><option value="PRESENT">Show Only Present</option><option value="ABSENT">Show Only Absent</option></select><button class="w-full bg-slate-900 text-white p-4 rounded-xl font-black uppercase text-[10px]">Generate List</button></form>
                </div>
                <div class="bg-indigo-900 p-8 rounded-[2.5rem] text-white">
                    <h3 class="font-black text-indigo-300 uppercase text-xs tracking-widest mb-4">Consistency Comparison</h3>
                    <form method="GET" class="space-y-3"><input type="hidden" name="export_type" value="consistency"><div class="flex gap-2"><select name="sidA" class="w-1/2 p-3 bg-white/10 rounded-xl text-xs" required><option value="">Session A</option><?php foreach($sessions as $s): ?><option value="<?= h($s['id']) ?>"><?= h($s['date']) ?></option><?php endforeach; ?></select><select name="sidB" class="w-1/2 p-3 bg-white/10 rounded-xl text-xs" required><option value="">Session B</option><?php foreach($sessions as $s): ?><option value="<?= h($s['id']) ?>"><?= h($s['date']) ?></option><?php endforeach; ?></select></div><button class="w-full bg-amber-400 text-amber-950 p-4 rounded-xl font-black uppercase text-[10px]">Analyze</button></form>
                </div>
            </div>

            <div class="bg-white p-10 rounded-[3rem] shadow-sm border border-slate-100">
                <h3 class="font-black text-slate-800 uppercase text-xs tracking-widest mb-6 text-center">Multi-Session Global Reports</h3>
                <form method="GET"><div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8 max-h-48 overflow-y-auto p-4 bg-slate-50 rounded-3xl"><?php foreach($sessions as $s): ?><label class="flex items-center gap-2 bg-white p-3 rounded-xl border border-slate-200 cursor-pointer"><input type="checkbox" name="sids[]" value="<?= h($s['id']) ?>"><span class="text-[10px] font-bold text-slate-600"><?= h($s['date']) ?></span></label><?php endforeach; ?></div><div class="flex flex-col md:flex-row gap-4"><button type="submit" name="export_type" value="unique_attendees" class="flex-1 bg-indigo-600 text-white p-5 rounded-2xl font-black uppercase text-[10px]">Download Unique Attendee List</button><button type="submit" name="export_type" value="master_list" class="flex-1 bg-slate-900 text-white p-5 rounded-2xl font-black uppercase text-[10px]">Download Master Frequency List</button></div></form>
            </div>
        </div>
    </main>
</div>

<div id="m-edit" class="hidden fixed inset-0 bg-slate-900/90 backdrop-blur-sm flex items-center justify-center p-6 z-50"><div class="bg-white p-8 rounded-[2.5rem] w-full max-w-md shadow-2xl"><h3 class="text-xl font-black mb-6">Modify Athlete</h3><form method="POST" class="space-y-4"><input type="hidden" name="mid" id="e-mid"><input name="full_name" id="e-name" class="w-full p-4 bg-slate-50 rounded-xl outline-none border border-slate-100" required><input name="monthly_fee" id="e-fee" type="number" step="0.01" class="w-full p-4 bg-slate-50 rounded-xl outline-none border border-slate-100" required><input name="due_day" id="e-due" type="number" min="1" max="31" class="w-full p-4 bg-slate-50 rounded-xl outline-none border border-slate-100" required><label class="flex items-center gap-2 text-sm font-bold"><input type="checkbox" name="is_active" id="e-active"> Active Member</label><button name="update_athlete" class="w-full bg-indigo-600 text-white p-4 rounded-xl font-black uppercase text-xs">Save Update</button><button type="button" onclick="closeModal('m-edit')" class="w-full text-slate-400 font-bold p-2">Cancel</button></form></div></div>
<div id="m-delete" class="hidden fixed inset-0 bg-slate-900/90 flex items-center justify-center p-6 z-50"><div class="bg-white p-8 rounded-[2.5rem] w-full max-w-md text-center"><h3 class="text-xl font-black mb-2 text-red-600">Delete Member?</h3><p class="text-slate-500 mb-6 text-sm">This removes the member and their attendance/payment history.</p><form method="POST"><input type="hidden" name="mid" id="d-mid"><button name="delete_athlete" class="w-full bg-red-600 text-white p-4 rounded-xl font-black uppercase text-xs">Yes, Delete</button><button type="button" onclick="closeModal('m-delete')" class="w-full text-slate-400 font-bold p-2">Cancel</button></form></div></div>
<div id="m-session" class="hidden fixed inset-0 bg-slate-900/90 flex items-center justify-center p-6 z-50"><div class="bg-white p-8 rounded-[2.5rem] w-full max-w-md"><h3 class="text-xl font-black mb-6">New Session</h3><form method="POST" class="space-y-4"><input name="s_name" placeholder="Session Title" class="w-full p-4 bg-slate-50 rounded-xl border-none ring-1 ring-slate-100" required><input type="date" name="s_date" value="<?= date('Y-m-d') ?>" class="w-full p-4 bg-slate-50 rounded-xl border-none ring-1 ring-slate-100"><button name="save_session" class="w-full bg-indigo-600 text-white p-4 rounded-xl font-black uppercase text-xs">Create Session</button><button type="button" onclick="closeModal('m-session')" class="w-full text-slate-400 font-bold p-2">Close</button></form></div></div>

<script>
function view(id) {
    document.querySelectorAll('.page').forEach(p => p.classList.add('hidden'));
    document.getElementById('v-' + id).classList.remove('hidden');
    document.querySelectorAll('.nav-btn').forEach(b => b.className = 'nav-btn w-full text-left p-4 rounded-2xl font-bold text-slate-400 hover:bg-slate-800 transition');
    document.getElementById('n-' + id).className = 'nav-btn w-full text-left p-4 rounded-2xl font-bold bg-indigo-600 shadow-xl';
}
function searchRows(inputId, selector) {
    const q = document.getElementById(inputId).value.toLowerCase();
    document.querySelectorAll(selector).forEach(row => {
        row.style.display = row.querySelector('.row-name').innerText.toLowerCase().includes(q) ? '' : 'none';
    });
}
function editMember(id, name, fee, due, active) {
    document.getElementById('e-mid').value = id;
    document.getElementById('e-name').value = name;
    document.getElementById('e-fee').value = fee;
    document.getElementById('e-due').value = due;
    document.getElementById('e-active').checked = active;
    openModal('m-edit');
}
function deleteMember(id) { document.getElementById('d-mid').value = id; openModal('m-delete'); }
function openModal(id) { document.getElementById(id).classList.remove('hidden'); }
function closeModal(id) { document.getElementById(id).classList.add('hidden'); }
</script>
</body>
</html>
