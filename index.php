<?php
/**
 * UJAMAA ACADEMY - APEX EDITION V9.0
 * Strong Attendance + Proper Monthly Billing Logic
 *
 * What this version does:
 * - Keep existing members, sessions, attendance, reports.
 * - Add real monthly billing: expected amount, due date, amount paid, remaining balance.
 * - Support partial payment and manual remaining balance.
 * - Show overdue days after due date.
 * - Month naturally resets because each month has its own billing period: YYYY-MM.
 * - Download payment, attendance, manager, debtor, and full summary CSV reports.
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

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

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function n2($v) { return number_format((float)$v, 0); }
function money($v) { return 'RWF ' . number_format((float)$v, 0); }
function current_period() { return date('Y-m'); }
function valid_period($p) { return preg_match('/^\d{4}-\d{2}$/', (string)$p) ? $p : current_period(); }
function period_start($p) { return valid_period($p) . '-01'; }
function due_date_from_day($period, $day) {
    $day = max(1, min(31, (int)$day));
    $last = (int)date('t', strtotime($period . '-01'));
    return $period . '-' . str_pad((string)min($day, $last), 2, '0', STR_PAD_LEFT);
}
function remaining_amount($expected, $paid, $manualRemaining) {
    if ($manualRemaining !== null && $manualRemaining !== '') return (float)$manualRemaining;
    return max(0, (float)$expected - (float)$paid);
}
function bill_status($expected, $paid, $remaining) {
    $expected = (float)$expected; $paid = (float)$paid; $remaining = (float)$remaining;
    if ($expected <= 0 && $paid <= 0) return 'NO BILL';
    if ($remaining <= 0 && $paid > 0) return 'PAID';
    if ($paid > 0 && $remaining > 0) return 'PARTIAL';
    return 'UNPAID';
}
function overdue_days_for_bill($dueDate, $status) {
    if (in_array($status, ['PAID', 'NO BILL'], true)) return 0;
    $today = new DateTime(date('Y-m-d'));
    $due = new DateTime($dueDate);
    if ($today <= $due) return 0;
    return (int)$due->diff($today)->days;
}

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

    $pdo->exec("ALTER TABLE members ADD COLUMN IF NOT EXISTS phone TEXT");
    $pdo->exec("ALTER TABLE members ADD COLUMN IF NOT EXISTS default_monthly_fee NUMERIC(12,2) NOT NULL DEFAULT 0");
    $pdo->exec("ALTER TABLE members ADD COLUMN IF NOT EXISTS default_due_day INTEGER NOT NULL DEFAULT 5");
    $pdo->exec("ALTER TABLE members ADD COLUMN IF NOT EXISTS is_active BOOLEAN NOT NULL DEFAULT TRUE");

    // Compatibility with older versions that used monthly_fee/due_day.
    $pdo->exec("ALTER TABLE members ADD COLUMN IF NOT EXISTS monthly_fee NUMERIC(12,2) NOT NULL DEFAULT 0");
    $pdo->exec("ALTER TABLE members ADD COLUMN IF NOT EXISTS due_day INTEGER NOT NULL DEFAULT 5");
    $pdo->exec("UPDATE members SET default_monthly_fee = monthly_fee WHERE default_monthly_fee = 0 AND monthly_fee > 0");
    $pdo->exec("UPDATE members SET default_due_day = due_day WHERE default_due_day = 5 AND due_day <> 5");

    $pdo->exec("CREATE TABLE IF NOT EXISTS monthly_bills (
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
    )");

    // Safe fixes if table already existed but missed columns.
    $pdo->exec("ALTER TABLE monthly_bills ADD COLUMN IF NOT EXISTS expected_amount NUMERIC(12,2) NOT NULL DEFAULT 0");
    $pdo->exec("ALTER TABLE monthly_bills ADD COLUMN IF NOT EXISTS paid_amount NUMERIC(12,2) NOT NULL DEFAULT 0");
    $pdo->exec("ALTER TABLE monthly_bills ADD COLUMN IF NOT EXISTS manual_remaining_amount NUMERIC(12,2)");
    $pdo->exec("ALTER TABLE monthly_bills ADD COLUMN IF NOT EXISTS due_date DATE NOT NULL DEFAULT CURRENT_DATE");
    $pdo->exec("ALTER TABLE monthly_bills ADD COLUMN IF NOT EXISTS paid_at TIMESTAMP");
    $pdo->exec("ALTER TABLE monthly_bills ADD COLUMN IF NOT EXISTS note TEXT");
    $pdo->exec("ALTER TABLE monthly_bills ADD COLUMN IF NOT EXISTS created_at TIMESTAMP NOT NULL DEFAULT NOW()");
    $pdo->exec("ALTER TABLE monthly_bills ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NOT NULL DEFAULT NOW()");

    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_monthly_bills_period ON monthly_bills(period)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_sessions_date ON sessions(date)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_attendance_member ON attendance(member_id)");

    // Older v8 payments table may exist. Keep it untouched; this version uses monthly_bills.
}

function get_billing_rows(PDO $pdo, $period) {
    $stmt = $pdo->prepare("SELECT
            m.id, m.full_name, m.phone, m.is_active, m.default_monthly_fee, m.default_due_day,
            b.expected_amount, b.paid_amount, b.manual_remaining_amount, b.due_date, b.paid_at, b.note
        FROM members m
        LEFT JOIN monthly_bills b ON b.member_id = m.id AND b.period = ?
        ORDER BY m.full_name ASC");
    $stmt->execute([$period]);
    $rows = [];
    foreach ($stmt->fetchAll() as $r) {
        $expected = $r['expected_amount'] !== null ? (float)$r['expected_amount'] : (float)$r['default_monthly_fee'];
        $paid = $r['paid_amount'] !== null ? (float)$r['paid_amount'] : 0;
        $due = $r['due_date'] ?: due_date_from_day($period, $r['default_due_day']);
        $remaining = remaining_amount($expected, $paid, $r['manual_remaining_amount']);
        $status = bill_status($expected, $paid, $remaining);
        $r['effective_expected'] = $expected;
        $r['effective_paid'] = $paid;
        $r['effective_remaining'] = $remaining;
        $r['effective_due_date'] = $due;
        $r['effective_status'] = $status;
        $r['overdue_days'] = overdue_days_for_bill($due, $status);
        $rows[] = $r;
    }
    return $rows;
}

function csv_headers($filename) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename=' . $filename);
}

if (isset($_GET['export_type'])) {
    try {
        $pdo = get_db_connection();
        ensure_schema($pdo);
        $type = $_GET['export_type'];
        $period = valid_period($_GET['period'] ?? current_period());

        if ($type === 'payment_report' || $type === 'debtors_report' || $type === 'full_summary') {
            $rows = get_billing_rows($pdo, $period);
            csv_headers($type . '_' . $period . '.csv');
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Athlete', 'Phone', 'Period', 'Expected', 'Paid', 'Remaining', 'Due Date', 'Status', 'Overdue Days', 'Note']);
            foreach ($rows as $r) {
                if ($type === 'debtors_report' && !in_array($r['effective_status'], ['UNPAID', 'PARTIAL'], true)) continue;
                fputcsv($out, [
                    $r['full_name'], $r['phone'], $period, $r['effective_expected'], $r['effective_paid'],
                    $r['effective_remaining'], $r['effective_due_date'], $r['effective_status'], $r['overdue_days'], $r['note'] ?? ''
                ]);
            }
            exit;
        }

        if ($type === 'monthly_attendance_report') {
            csv_headers('monthly_attendance_' . $period . '.csv');
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Athlete', 'Period', 'Times Attended']);
            $stmt = $pdo->prepare("SELECT m.full_name, COUNT(a.id) AS total
                FROM members m
                LEFT JOIN attendance a ON a.member_id = m.id
                LEFT JOIN sessions s ON s.id = a.session_id AND TO_CHAR(s.date, 'YYYY-MM') = ?
                WHERE m.is_active = TRUE
                GROUP BY m.full_name
                ORDER BY total DESC, m.full_name ASC");
            $stmt->execute([$period]);
            while ($row = $stmt->fetch()) fputcsv($out, [$row['full_name'], $period, $row['total']]);
            exit;
        }

        if ($type === 'manager_summary') {
            $rows = array_filter(get_billing_rows($pdo, $period), fn($r) => $r['is_active']);
            $active = count($rows); $expected = 0; $paid = 0; $remaining = 0; $paidCount = 0; $partialCount = 0; $unpaidCount = 0; $overdueCount = 0;
            foreach ($rows as $r) {
                $expected += $r['effective_expected']; $paid += $r['effective_paid']; $remaining += max(0, $r['effective_remaining']);
                if ($r['effective_status'] === 'PAID') $paidCount++;
                if ($r['effective_status'] === 'PARTIAL') $partialCount++;
                if ($r['effective_status'] === 'UNPAID') $unpaidCount++;
                if ($r['overdue_days'] > 0) $overdueCount++;
            }
            csv_headers('manager_summary_' . $period . '.csv');
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Metric', 'Value']);
            fputcsv($out, ['Period', $period]);
            fputcsv($out, ['Active members', $active]);
            fputcsv($out, ['Expected income', $expected]);
            fputcsv($out, ['Collected income', $paid]);
            fputcsv($out, ['Remaining balance', $remaining]);
            fputcsv($out, ['Paid members', $paidCount]);
            fputcsv($out, ['Partial members', $partialCount]);
            fputcsv($out, ['Unpaid members', $unpaidCount]);
            fputcsv($out, ['Overdue members', $overdueCount]);
            exit;
        }

        if ($type === 'filtered_status') {
            $sid = (int)$_GET['sid'];
            $status = $_GET['status'] === 'ABSENT' ? 'ABSENT' : 'PRESENT';
            csv_headers('session_' . strtolower($status) . '.csv');
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Athlete']);
            $query = $status === 'PRESENT'
                ? "SELECT m.full_name FROM members m JOIN attendance a ON a.member_id = m.id WHERE a.session_id = ? ORDER BY m.full_name ASC"
                : "SELECT m.full_name FROM members m WHERE m.id NOT IN (SELECT member_id FROM attendance WHERE session_id = ?) ORDER BY m.full_name ASC";
            $stmt = $pdo->prepare($query); $stmt->execute([$sid]);
            while ($row = $stmt->fetch()) fputcsv($out, [$row['full_name']]);
            exit;
        }

        if ($type === 'unique_attendees' || $type === 'master_list') {
            $ids = $_GET['sids'] ?? [];
            if (empty($ids)) die('Select at least one session.');
            $ids = array_map('intval', $ids);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            csv_headers($type . '.csv');
            $out = fopen('php://output', 'w');
            if ($type === 'unique_attendees') {
                fputcsv($out, ['Athlete']);
                $stmt = $pdo->prepare("SELECT DISTINCT m.full_name FROM members m JOIN attendance a ON a.member_id = m.id WHERE a.session_id IN ($placeholders) ORDER BY m.full_name ASC");
                $stmt->execute($ids);
                while ($row = $stmt->fetch()) fputcsv($out, [$row['full_name']]);
            } else {
                fputcsv($out, ['Athlete', 'Total Sessions Attended']);
                $stmt = $pdo->prepare("SELECT m.full_name, COUNT(a.id) total FROM members m JOIN attendance a ON a.member_id = m.id WHERE a.session_id IN ($placeholders) GROUP BY m.full_name ORDER BY total DESC, m.full_name ASC");
                $stmt->execute($ids);
                while ($row = $stmt->fetch()) fputcsv($out, [$row['full_name'], $row['total']]);
            }
            exit;
        }

        if ($type === 'consistency') {
            csv_headers('consistency.csv');
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Athlete', 'Session A', 'Session B', 'Logic']);
            $stmt = $pdo->prepare("SELECT m.full_name,
                CASE WHEN a1.id IS NOT NULL THEN 'PRESENT' ELSE 'ABSENT' END sA,
                CASE WHEN a2.id IS NOT NULL THEN 'PRESENT' ELSE 'ABSENT' END sB,
                CASE WHEN a1.id IS NOT NULL AND a2.id IS NOT NULL THEN 'CONSISTENT'
                     WHEN a1.id IS NULL AND a2.id IS NULL THEN 'ABSENT BOTH'
                     ELSE 'INCONSISTENT' END logic
                FROM members m
                LEFT JOIN attendance a1 ON a1.member_id = m.id AND a1.session_id = ?
                LEFT JOIN attendance a2 ON a2.member_id = m.id AND a2.session_id = ?
                ORDER BY m.full_name ASC");
            $stmt->execute([(int)$_GET['sidA'], (int)$_GET['sidB']]);
            while ($row = $stmt->fetch()) fputcsv($out, [$row['full_name'], $row['sa'], $row['sb'], $row['logic']]);
            exit;
        }
    } catch (Exception $e) { die('Export Error: ' . h($e->getMessage())); }
}

try {
    $pdo = get_db_connection();
    ensure_schema($pdo);
    $period = valid_period($_GET['period'] ?? current_period());

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['save_athlete'])) {
            $pdo->prepare("INSERT INTO members(full_name, phone, default_monthly_fee, default_due_day, monthly_fee, due_day) VALUES (?, ?, ?, ?, ?, ?)")
                ->execute([trim($_POST['full_name']), trim($_POST['phone'] ?? ''), (float)($_POST['default_monthly_fee'] ?? 0), (int)($_POST['default_due_day'] ?? 5), (float)($_POST['default_monthly_fee'] ?? 0), (int)($_POST['default_due_day'] ?? 5)]);
        }
        if (isset($_POST['update_athlete'])) {
            $pdo->prepare("UPDATE members SET full_name=?, phone=?, default_monthly_fee=?, default_due_day=?, monthly_fee=?, due_day=?, is_active=? WHERE id=?")
                ->execute([trim($_POST['full_name']), trim($_POST['phone'] ?? ''), (float)$_POST['default_monthly_fee'], (int)$_POST['default_due_day'], (float)$_POST['default_monthly_fee'], (int)$_POST['default_due_day'], isset($_POST['is_active']) ? 1 : 0, (int)$_POST['mid']]);
        }
        if (isset($_POST['delete_athlete'])) $pdo->prepare("DELETE FROM members WHERE id=?")->execute([(int)$_POST['mid']]);
        if (isset($_POST['save_session'])) $pdo->prepare("INSERT INTO sessions(name, date) VALUES (?, ?)")->execute([trim($_POST['s_name']), $_POST['s_date']]);
        if (isset($_POST['mark'])) $pdo->prepare("INSERT INTO attendance(session_id, member_id) VALUES (?, ?) ON CONFLICT DO NOTHING")->execute([(int)$_POST['sid'], (int)$_POST['mid']]);
        if (isset($_POST['clear_attendance'])) $pdo->prepare("DELETE FROM attendance WHERE session_id=? AND member_id=?")->execute([(int)$_POST['sid'], (int)$_POST['mid']]);

        if (isset($_POST['save_bill'])) {
            $expected = (float)($_POST['expected_amount'] ?? 0);
            $paid = (float)($_POST['paid_amount'] ?? 0);
            $manualRaw = trim((string)($_POST['manual_remaining_amount'] ?? ''));
            $manual = ($manualRaw === '') ? null : (float)$manualRaw;
            $dueDate = $_POST['due_date'] ?: due_date_from_day(valid_period($_POST['period']), 5);
            $finalRemaining = remaining_amount($expected, $paid, $manual);
            $paidAt = ($finalRemaining <= 0 && $paid > 0) ? date('Y-m-d H:i:s') : null;
            $pdo->prepare("INSERT INTO monthly_bills(member_id, period, expected_amount, paid_amount, manual_remaining_amount, due_date, paid_at, note, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ON CONFLICT(member_id, period) DO UPDATE SET
                    expected_amount=EXCLUDED.expected_amount,
                    paid_amount=EXCLUDED.paid_amount,
                    manual_remaining_amount=EXCLUDED.manual_remaining_amount,
                    due_date=EXCLUDED.due_date,
                    paid_at=EXCLUDED.paid_at,
                    note=EXCLUDED.note,
                    updated_at=NOW()")
                ->execute([(int)$_POST['mid'], valid_period($_POST['period']), $expected, $paid, $manual, $dueDate, $paidAt, trim($_POST['note'] ?? '')]);
        }

        if (isset($_POST['reset_bill'])) {
            $pdo->prepare("DELETE FROM monthly_bills WHERE member_id=? AND period=?")->execute([(int)$_POST['mid'], valid_period($_POST['period'])]);
        }

        header("Location: index.php?session=" . ($_POST['sid'] ?? ($_GET['session'] ?? '')) . "&period=" . valid_period($_POST['period'] ?? $period));
        exit;
    }

    $sessions = $pdo->query("SELECT * FROM sessions ORDER BY date DESC, id DESC LIMIT 150")->fetchAll();
    $current_sid = $_GET['session'] ?? ($sessions[0]['id'] ?? null);
    $active_s = null;
    foreach ($sessions as $s) if ((string)$s['id'] === (string)$current_sid) $active_s = $s;

    $members = $pdo->query("SELECT * FROM members ORDER BY full_name ASC")->fetchAll();

    $attended_ids = [];
    if ($current_sid) {
        $stmt = $pdo->prepare("SELECT member_id FROM attendance WHERE session_id=?");
        $stmt->execute([(int)$current_sid]);
        $attended_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    $attStmt = $pdo->prepare("SELECT m.id, COUNT(a.id) total
        FROM members m
        LEFT JOIN attendance a ON a.member_id = m.id
        LEFT JOIN sessions s ON s.id = a.session_id AND TO_CHAR(s.date, 'YYYY-MM') = ?
        GROUP BY m.id");
    $attStmt->execute([$period]);
    $attendanceMonth = [];
    foreach ($attStmt->fetchAll() as $r) $attendanceMonth[$r['id']] = (int)$r['total'];

    $billingRows = get_billing_rows($pdo, $period);
    $activeBillingRows = array_filter($billingRows, fn($r) => $r['is_active']);
    $expectedIncome = 0; $collectedIncome = 0; $remainingIncome = 0; $paidCount = 0; $partialCount = 0; $unpaidCount = 0; $overdueCount = 0;
    foreach ($activeBillingRows as $r) {
        $expectedIncome += $r['effective_expected'];
        $collectedIncome += $r['effective_paid'];
        $remainingIncome += max(0, $r['effective_remaining']);
        if ($r['effective_status'] === 'PAID') $paidCount++;
        if ($r['effective_status'] === 'PARTIAL') $partialCount++;
        if ($r['effective_status'] === 'UNPAID') $unpaidCount++;
        if ($r['overdue_days'] > 0) $overdueCount++;
    }
} catch (Exception $e) { die('System Error: ' . h($e->getMessage())); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ujamaa Academy | Apex V9</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; background: #f8fafc; }
        .glass { background: rgba(255,255,255,.86); backdrop-filter: blur(14px); }
        .soft-card { background:#fff; border:1px solid #e2e8f0; box-shadow:0 18px 50px rgba(15,23,42,.06); }
        .pill { border-radius:999px; padding:.35rem .7rem; font-size:10px; font-weight:900; text-transform:uppercase; }
        .modal { display:none; }
        .modal.show { display:flex; }
    </style>
</head>
<body class="text-slate-900">
<div class="min-h-screen lg:flex">
    <aside class="lg:w-80 bg-slate-950 text-white p-6 lg:p-8 flex flex-col gap-8">
        <div>
            <h1 class="text-3xl font-black tracking-tight">UJAMAA<span class="text-indigo-400">.</span></h1>
            <p class="text-[10px] uppercase tracking-[.28em] text-slate-500 font-black mt-1">Academy Manager V9</p>
        </div>

        <nav class="grid grid-cols-2 lg:grid-cols-1 gap-3">
            <button onclick="view('dashboard')" id="n-dashboard" class="nav-btn bg-indigo-600 text-white p-4 rounded-2xl font-black text-left">Dashboard</button>
            <button onclick="view('attendance')" id="n-attendance" class="nav-btn text-slate-400 hover:bg-slate-900 p-4 rounded-2xl font-black text-left">Attendance</button>
            <button onclick="view('payments')" id="n-payments" class="nav-btn text-slate-400 hover:bg-slate-900 p-4 rounded-2xl font-black text-left">Payments</button>
            <button onclick="view('members')" id="n-members" class="nav-btn text-slate-400 hover:bg-slate-900 p-4 rounded-2xl font-black text-left">Members</button>
            <button onclick="view('reports')" id="n-reports" class="nav-btn text-slate-400 hover:bg-slate-900 p-4 rounded-2xl font-black text-left">Reports</button>
        </nav>

        <div class="bg-white/5 border border-white/10 p-5 rounded-[2rem]">
            <h3 class="text-[10px] font-black uppercase tracking-widest text-indigo-300 mb-4">Add Athlete</h3>
            <form method="POST" class="space-y-3">
                <input name="full_name" placeholder="Full name" class="w-full bg-slate-900 border border-white/10 p-3 rounded-xl text-sm outline-none" required>
                <input name="phone" placeholder="Phone optional" class="w-full bg-slate-900 border border-white/10 p-3 rounded-xl text-sm outline-none">
                <input name="default_monthly_fee" type="number" step="0.01" placeholder="Monthly amount to pay" class="w-full bg-slate-900 border border-white/10 p-3 rounded-xl text-sm outline-none" required>
                <input name="default_due_day" type="number" min="1" max="31" value="5" placeholder="Default due day" class="w-full bg-slate-900 border border-white/10 p-3 rounded-xl text-sm outline-none" required>
                <button name="save_athlete" class="w-full bg-white text-slate-950 py-3 rounded-xl font-black text-[10px] uppercase">Save Athlete</button>
            </form>
        </div>
    </aside>

    <main class="flex-1 p-5 lg:p-10 overflow-x-hidden">
        <div class="max-w-7xl mx-auto">
            <header class="flex flex-col xl:flex-row xl:items-center xl:justify-between gap-5 mb-8">
                <div>
                    <h2 class="text-3xl lg:text-5xl font-black tracking-tight">Academy Control Center</h2>
                    <p class="text-slate-500 font-bold mt-2">Period: <?= h($period) ?> · Today: <?= date('Y-m-d') ?></p>
                </div>
                <form method="GET" class="glass border border-slate-200 p-2 rounded-2xl flex gap-2 items-center">
                    <input type="hidden" name="session" value="<?= h($current_sid) ?>">
                    <input name="period" type="month" value="<?= h($period) ?>" class="bg-white rounded-xl px-4 py-3 font-black outline-none border border-slate-100">
                    <button class="bg-slate-950 text-white px-5 py-3 rounded-xl font-black text-xs uppercase">Open Month</button>
                </form>
            </header>

            <section id="v-dashboard" class="page space-y-8">
                <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-5">
                    <div class="soft-card p-6 rounded-[2rem]"><p class="text-xs font-black uppercase text-slate-400">Expected</p><h3 class="text-3xl font-black mt-2"><?= money($expectedIncome) ?></h3></div>
                    <div class="soft-card p-6 rounded-[2rem]"><p class="text-xs font-black uppercase text-slate-400">Collected</p><h3 class="text-3xl font-black mt-2 text-emerald-600"><?= money($collectedIncome) ?></h3></div>
                    <div class="soft-card p-6 rounded-[2rem]"><p class="text-xs font-black uppercase text-slate-400">Remaining</p><h3 class="text-3xl font-black mt-2 text-amber-600"><?= money($remainingIncome) ?></h3></div>
                    <div class="soft-card p-6 rounded-[2rem]"><p class="text-xs font-black uppercase text-slate-400">Overdue</p><h3 class="text-3xl font-black mt-2 text-red-600"><?= (int)$overdueCount ?> members</h3></div>
                </div>

                <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">
                    <div class="xl:col-span-2 soft-card p-6 rounded-[2rem] overflow-x-auto">
                        <div class="flex items-center justify-between mb-5">
                            <h3 class="text-xl font-black">Manager Summary List</h3>
                            <a href="?export_type=full_summary&period=<?= h($period) ?>" class="bg-indigo-600 text-white px-4 py-3 rounded-xl text-xs font-black uppercase">Download</a>
                        </div>
                        <table class="w-full text-sm">
                            <thead><tr class="text-left text-[10px] uppercase tracking-widest text-slate-400 border-b"><th class="py-3">Athlete</th><th>Status</th><th>Expected</th><th>Paid</th><th>Remaining</th><th>Attend.</th></tr></thead>
                            <tbody>
                            <?php foreach ($billingRows as $r): ?>
                                <tr class="border-b border-slate-100">
                                    <td class="py-4 font-black"><?= h($r['full_name']) ?></td>
                                    <td><span class="pill <?= $r['effective_status']==='PAID'?'bg-emerald-100 text-emerald-700':($r['effective_status']==='PARTIAL'?'bg-amber-100 text-amber-700':'bg-red-100 text-red-700') ?>"><?= h($r['effective_status']) ?></span></td>
                                    <td><?= money($r['effective_expected']) ?></td>
                                    <td><?= money($r['effective_paid']) ?></td>
                                    <td class="font-black"><?= money($r['effective_remaining']) ?></td>
                                    <td><?= (int)($attendanceMonth[$r['id']] ?? 0) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="soft-card p-6 rounded-[2rem]">
                        <h3 class="text-xl font-black mb-5">Payment Health</h3>
                        <div class="space-y-4">
                            <div class="flex justify-between bg-emerald-50 p-4 rounded-2xl"><b>Paid</b><b><?= $paidCount ?></b></div>
                            <div class="flex justify-between bg-amber-50 p-4 rounded-2xl"><b>Partial</b><b><?= $partialCount ?></b></div>
                            <div class="flex justify-between bg-red-50 p-4 rounded-2xl"><b>Unpaid</b><b><?= $unpaidCount ?></b></div>
                            <div class="flex justify-between bg-slate-100 p-4 rounded-2xl"><b>Active athletes</b><b><?= count($activeBillingRows) ?></b></div>
                        </div>
                    </div>
                </div>
            </section>

            <section id="v-attendance" class="page hidden space-y-6">
                <div class="soft-card p-6 rounded-[2rem]">
                    <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4 mb-6">
                        <div>
                            <h3 class="text-2xl font-black"><?= $active_s ? h($active_s['name']) : 'No Session Yet' ?></h3>
                            <p class="text-slate-500 font-bold"><?= $active_s ? h($active_s['date']) : 'Create a session first.' ?></p>
                        </div>
                        <div class="flex gap-2">
                            <select onchange="location.href='?session='+this.value+'&period=<?= h($period) ?>'" class="bg-white rounded-xl px-4 py-3 font-bold text-sm shadow-sm ring-1 ring-slate-200">
                                <?php foreach($sessions as $s): ?><option value="<?= h($s['id']) ?>" <?= $current_sid == $s['id'] ? 'selected' : '' ?>><?= h($s['date']) ?> - <?= h($s['name']) ?></option><?php endforeach; ?>
                            </select>
                            <button onclick="openModal('m-session')" class="bg-indigo-600 text-white px-5 rounded-xl font-black">+</button>
                        </div>
                    </div>
                    <input id="attendanceSearch" onkeyup="searchRows('attendanceSearch','.attendance-row')" placeholder="Search athlete..." class="w-full p-4 bg-slate-50 rounded-2xl outline-none border border-slate-200 mb-5">
                    <div class="divide-y divide-slate-100">
                        <?php foreach ($members as $m): $isP = in_array($m['id'], $attended_ids); ?>
                            <div class="attendance-row flex items-center justify-between py-4">
                                <div><b class="row-name text-lg"><?= h($m['full_name']) ?></b><p class="text-xs font-bold text-slate-400">This month: <?= (int)($attendanceMonth[$m['id']] ?? 0) ?> time(s)</p></div>
                                <?php if ($current_sid): ?>
                                    <?php if ($isP): ?>
                                        <form method="POST"><input type="hidden" name="sid" value="<?= (int)$current_sid ?>"><input type="hidden" name="mid" value="<?= (int)$m['id'] ?>"><input type="hidden" name="period" value="<?= h($period) ?>"><button name="clear_attendance" class="bg-emerald-500 text-white px-6 py-3 rounded-xl text-xs font-black uppercase">Present</button></form>
                                    <?php else: ?>
                                        <form method="POST"><input type="hidden" name="sid" value="<?= (int)$current_sid ?>"><input type="hidden" name="mid" value="<?= (int)$m['id'] ?>"><input type="hidden" name="period" value="<?= h($period) ?>"><button name="mark" class="border border-slate-300 px-6 py-3 rounded-xl text-xs font-black uppercase">Mark</button></form>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>

            <section id="v-payments" class="page hidden space-y-6">
                <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                    <div><h3 class="text-3xl font-black">Payments</h3><p class="text-slate-500 font-bold">Set due date, expected amount, paid amount, and remaining balance for <?= h($period) ?>.</p></div>
                    <a href="?export_type=payment_report&period=<?= h($period) ?>" class="bg-slate-950 text-white px-5 py-4 rounded-2xl font-black text-xs uppercase text-center">Download Payment List</a>
                </div>
                <input id="paymentSearch" onkeyup="searchRows('paymentSearch','.payment-row')" placeholder="Search payment record..." class="w-full p-4 bg-white rounded-2xl outline-none border border-slate-200">

                <div class="grid grid-cols-1 gap-5">
                    <?php foreach ($billingRows as $r): ?>
                    <div class="payment-row soft-card rounded-[2rem] p-5">
                        <div class="grid grid-cols-1 xl:grid-cols-12 gap-4 xl:items-center">
                            <div class="xl:col-span-3">
                                <h4 class="row-name text-lg font-black"><?= h($r['full_name']) ?></h4>
                                <p class="text-xs font-bold text-slate-400">Due: <?= h($r['effective_due_date']) ?> · <?= (int)$r['overdue_days'] ?> overdue day(s)</p>
                            </div>
                            <div class="xl:col-span-2"><span class="pill <?= $r['effective_status']==='PAID'?'bg-emerald-100 text-emerald-700':($r['effective_status']==='PARTIAL'?'bg-amber-100 text-amber-700':'bg-red-100 text-red-700') ?>"><?= h($r['effective_status']) ?></span></div>
                            <div class="xl:col-span-2"><p class="text-[10px] font-black uppercase text-slate-400">Expected</p><b><?= money($r['effective_expected']) ?></b></div>
                            <div class="xl:col-span-2"><p class="text-[10px] font-black uppercase text-slate-400">Paid</p><b><?= money($r['effective_paid']) ?></b></div>
                            <div class="xl:col-span-2"><p class="text-[10px] font-black uppercase text-slate-400">Remaining</p><b class="<?= $r['effective_remaining'] > 0 ? 'text-red-600' : 'text-emerald-600' ?>"><?= money($r['effective_remaining']) ?></b></div>
                            <div class="xl:col-span-1"><button onclick="openBill(<?= (int)$r['id'] ?>,'<?= h($r['full_name']) ?>','<?= h($period) ?>','<?= h($r['effective_expected']) ?>','<?= h($r['effective_paid']) ?>','<?= h($r['manual_remaining_amount']) ?>','<?= h($r['effective_due_date']) ?>','<?= h($r['note'] ?? '') ?>')" class="bg-indigo-600 text-white px-4 py-3 rounded-xl text-xs font-black uppercase w-full">Edit</button></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <section id="v-members" class="page hidden space-y-6">
                <h3 class="text-3xl font-black">Members</h3>
                <div class="soft-card rounded-[2rem] overflow-hidden">
                    <?php foreach ($members as $m): ?>
                    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 p-5 border-b border-slate-100">
                        <div>
                            <h4 class="text-lg font-black"><?= h($m['full_name']) ?></h4>
                            <p class="text-xs font-bold text-slate-400">Fee: <?= money($m['default_monthly_fee']) ?> · Due day: <?= (int)$m['default_due_day'] ?> · <?= $m['is_active'] ? 'Active' : 'Inactive' ?></p>
                        </div>
                        <div class="flex gap-2">
                            <button onclick="editMember(<?= (int)$m['id'] ?>,'<?= h($m['full_name']) ?>','<?= h($m['phone']) ?>','<?= h($m['default_monthly_fee']) ?>','<?= h($m['default_due_day']) ?>',<?= $m['is_active'] ? 'true':'false' ?>)" class="bg-slate-100 px-5 py-3 rounded-xl text-xs font-black uppercase">Edit</button>
                            <button onclick="deleteMember(<?= (int)$m['id'] ?>)" class="bg-red-50 text-red-600 px-5 py-3 rounded-xl text-xs font-black uppercase">Delete</button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <section id="v-reports" class="page hidden space-y-6">
                <h3 class="text-3xl font-black">Reports</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-5">
                    <a class="soft-card p-6 rounded-[2rem] font-black text-indigo-600" href="?export_type=payment_report&period=<?= h($period) ?>">Payment Report</a>
                    <a class="soft-card p-6 rounded-[2rem] font-black text-red-600" href="?export_type=debtors_report&period=<?= h($period) ?>">Debtors / Remaining</a>
                    <a class="soft-card p-6 rounded-[2rem] font-black text-emerald-600" href="?export_type=monthly_attendance_report&period=<?= h($period) ?>">Monthly Attendance</a>
                    <a class="soft-card p-6 rounded-[2rem] font-black text-slate-900" href="?export_type=manager_summary&period=<?= h($period) ?>">Manager Summary</a>
                </div>

                <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
                    <div class="soft-card p-6 rounded-[2rem]">
                        <h4 class="font-black mb-4">Present / Absent List</h4>
                        <form method="GET" class="space-y-3"><input type="hidden" name="export_type" value="filtered_status"><select name="sid" class="w-full p-3 bg-slate-50 rounded-xl font-bold border border-slate-200"><?php foreach($sessions as $s): ?><option value="<?= h($s['id']) ?>"><?= h($s['date']) ?> - <?= h($s['name']) ?></option><?php endforeach; ?></select><select name="status" class="w-full p-3 bg-slate-50 rounded-xl font-bold border border-slate-200"><option value="PRESENT">Present only</option><option value="ABSENT">Absent only</option></select><button class="w-full bg-slate-950 text-white p-4 rounded-xl font-black uppercase text-xs">Download</button></form>
                    </div>
                    <div class="soft-card p-6 rounded-[2rem]">
                        <h4 class="font-black mb-4">Consistency Comparison</h4>
                        <form method="GET" class="space-y-3"><input type="hidden" name="export_type" value="consistency"><div class="grid grid-cols-2 gap-3"><select name="sidA" class="p-3 bg-slate-50 rounded-xl font-bold border border-slate-200" required><option value="">Session A</option><?php foreach($sessions as $s): ?><option value="<?= h($s['id']) ?>"><?= h($s['date']) ?></option><?php endforeach; ?></select><select name="sidB" class="p-3 bg-slate-50 rounded-xl font-bold border border-slate-200" required><option value="">Session B</option><?php foreach($sessions as $s): ?><option value="<?= h($s['id']) ?>"><?= h($s['date']) ?></option><?php endforeach; ?></select></div><button class="w-full bg-indigo-600 text-white p-4 rounded-xl font-black uppercase text-xs">Download</button></form>
                    </div>
                </div>

                <div class="soft-card p-6 rounded-[2rem]">
                    <h4 class="font-black mb-4">Multi-Session Reports</h4>
                    <form method="GET"><div class="grid grid-cols-2 md:grid-cols-4 xl:grid-cols-6 gap-3 max-h-56 overflow-y-auto bg-slate-50 p-4 rounded-2xl mb-4"><?php foreach($sessions as $s): ?><label class="bg-white border border-slate-200 rounded-xl p-3 text-xs font-bold"><input type="checkbox" name="sids[]" value="<?= h($s['id']) ?>"> <?= h($s['date']) ?></label><?php endforeach; ?></div><div class="flex flex-col md:flex-row gap-3"><button name="export_type" value="unique_attendees" class="flex-1 bg-indigo-600 text-white p-4 rounded-xl font-black uppercase text-xs">Unique Attendees</button><button name="export_type" value="master_list" class="flex-1 bg-slate-950 text-white p-4 rounded-xl font-black uppercase text-xs">Master Frequency</button></div></form>
                </div>
            </section>
        </div>
    </main>
</div>

<div id="m-session" class="modal fixed inset-0 bg-slate-950/80 z-50 items-center justify-center p-5">
    <div class="bg-white rounded-[2rem] p-7 w-full max-w-md">
        <h3 class="text-2xl font-black mb-5">New Session</h3>
        <form method="POST" class="space-y-4">
            <input name="s_name" placeholder="Session title" class="w-full p-4 bg-slate-50 rounded-xl border border-slate-200" required>
            <input name="s_date" type="date" value="<?= date('Y-m-d') ?>" class="w-full p-4 bg-slate-50 rounded-xl border border-slate-200" required>
            <input type="hidden" name="period" value="<?= h($period) ?>">
            <button name="save_session" class="w-full bg-indigo-600 text-white p-4 rounded-xl font-black uppercase text-xs">Create</button>
            <button type="button" onclick="closeModal('m-session')" class="w-full p-2 font-bold text-slate-400">Cancel</button>
        </form>
    </div>
</div>

<div id="m-member" class="modal fixed inset-0 bg-slate-950/80 z-50 items-center justify-center p-5">
    <div class="bg-white rounded-[2rem] p-7 w-full max-w-md">
        <h3 class="text-2xl font-black mb-5">Edit Member</h3>
        <form method="POST" class="space-y-4">
            <input type="hidden" name="mid" id="em-id">
            <input name="full_name" id="em-name" class="w-full p-4 bg-slate-50 rounded-xl border border-slate-200" required>
            <input name="phone" id="em-phone" placeholder="Phone" class="w-full p-4 bg-slate-50 rounded-xl border border-slate-200">
            <input name="default_monthly_fee" id="em-fee" type="number" step="0.01" class="w-full p-4 bg-slate-50 rounded-xl border border-slate-200" required>
            <input name="default_due_day" id="em-due" type="number" min="1" max="31" class="w-full p-4 bg-slate-50 rounded-xl border border-slate-200" required>
            <label class="flex items-center gap-2 font-bold"><input type="checkbox" name="is_active" id="em-active"> Active member</label>
            <input type="hidden" name="period" value="<?= h($period) ?>">
            <button name="update_athlete" class="w-full bg-indigo-600 text-white p-4 rounded-xl font-black uppercase text-xs">Save</button>
            <button type="button" onclick="closeModal('m-member')" class="w-full p-2 font-bold text-slate-400">Cancel</button>
        </form>
    </div>
</div>

<div id="m-delete" class="modal fixed inset-0 bg-slate-950/80 z-50 items-center justify-center p-5">
    <div class="bg-white rounded-[2rem] p-7 w-full max-w-md text-center">
        <h3 class="text-2xl font-black text-red-600 mb-3">Delete Member?</h3>
        <p class="text-slate-500 font-bold mb-5">This deletes their attendance and payment records too.</p>
        <form method="POST">
            <input type="hidden" name="mid" id="del-id">
            <input type="hidden" name="period" value="<?= h($period) ?>">
            <button name="delete_athlete" class="w-full bg-red-600 text-white p-4 rounded-xl font-black uppercase text-xs">Delete</button>
            <button type="button" onclick="closeModal('m-delete')" class="w-full p-2 font-bold text-slate-400">Cancel</button>
        </form>
    </div>
</div>

<div id="m-bill" class="modal fixed inset-0 bg-slate-950/80 z-50 items-center justify-center p-5">
    <div class="bg-white rounded-[2rem] p-7 w-full max-w-lg">
        <h3 class="text-2xl font-black mb-1">Edit Payment</h3>
        <p id="bill-athlete" class="text-slate-500 font-bold mb-5"></p>
        <form method="POST" class="space-y-4">
            <input type="hidden" name="mid" id="bill-mid">
            <input type="hidden" name="period" id="bill-period" value="<?= h($period) ?>">
            <label class="block"><span class="text-xs font-black uppercase text-slate-400">Due payment date</span><input name="due_date" id="bill-due" type="date" class="w-full p-4 bg-slate-50 rounded-xl border border-slate-200" required></label>
            <label class="block"><span class="text-xs font-black uppercase text-slate-400">How much she/he has to pay</span><input name="expected_amount" id="bill-expected" type="number" step="0.01" class="w-full p-4 bg-slate-50 rounded-xl border border-slate-200" required></label>
            <label class="block"><span class="text-xs font-black uppercase text-slate-400">Amount paid</span><input name="paid_amount" id="bill-paid" type="number" step="0.01" class="w-full p-4 bg-slate-50 rounded-xl border border-slate-200" required></label>
            <label class="block"><span class="text-xs font-black uppercase text-slate-400">Remaining balance optional override</span><input name="manual_remaining_amount" id="bill-remaining" type="number" step="0.01" placeholder="Leave empty to auto-calculate expected - paid" class="w-full p-4 bg-slate-50 rounded-xl border border-slate-200"></label>
            <label class="block"><span class="text-xs font-black uppercase text-slate-400">Note</span><input name="note" id="bill-note" class="w-full p-4 bg-slate-50 rounded-xl border border-slate-200" placeholder="Example: paid by cash, parent promised balance Friday"></label>
            <div class="grid grid-cols-2 gap-3">
                <button name="save_bill" class="bg-indigo-600 text-white p-4 rounded-xl font-black uppercase text-xs">Save Payment</button>
                <button name="reset_bill" class="bg-slate-100 text-slate-700 p-4 rounded-xl font-black uppercase text-xs">Reset This Month</button>
            </div>
            <button type="button" onclick="closeModal('m-bill')" class="w-full p-2 font-bold text-slate-400">Cancel</button>
        </form>
    </div>
</div>

<script>
function view(id) {
    document.querySelectorAll('.page').forEach(p => p.classList.add('hidden'));
    document.getElementById('v-' + id).classList.remove('hidden');
    document.querySelectorAll('.nav-btn').forEach(b => b.className = 'nav-btn text-slate-400 hover:bg-slate-900 p-4 rounded-2xl font-black text-left');
    document.getElementById('n-' + id).className = 'nav-btn bg-indigo-600 text-white p-4 rounded-2xl font-black text-left';
}
function searchRows(inputId, selector) {
    const q = document.getElementById(inputId).value.toLowerCase();
    document.querySelectorAll(selector).forEach(row => {
        const name = row.querySelector('.row-name')?.innerText.toLowerCase() || '';
        row.style.display = name.includes(q) ? '' : 'none';
    });
}
function openModal(id) { document.getElementById(id).classList.add('show'); }
function closeModal(id) { document.getElementById(id).classList.remove('show'); }
function editMember(id, name, phone, fee, due, active) {
    document.getElementById('em-id').value = id;
    document.getElementById('em-name').value = name;
    document.getElementById('em-phone').value = phone;
    document.getElementById('em-fee').value = fee;
    document.getElementById('em-due').value = due;
    document.getElementById('em-active').checked = active;
    openModal('m-member');
}
function deleteMember(id) {
    document.getElementById('del-id').value = id;
    openModal('m-delete');
}
function openBill(id, name, period, expected, paid, remaining, due, note) {
    document.getElementById('bill-mid').value = id;
    document.getElementById('bill-period').value = period;
    document.getElementById('bill-athlete').innerText = name + ' · ' + period;
    document.getElementById('bill-expected').value = expected;
    document.getElementById('bill-paid').value = paid;
    document.getElementById('bill-remaining').value = remaining;
    document.getElementById('bill-due').value = due;
    document.getElementById('bill-note').value = note;
    openModal('m-bill');
}
document.addEventListener('keydown', e => { if (e.key === 'Escape') document.querySelectorAll('.modal').forEach(m => m.classList.remove('show')); });
</script>
</body>
</html>
