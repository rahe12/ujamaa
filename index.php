<?php
/**
 * UJAMAA ACADEMY - APEX EDITION V10.0
 * Payment Cycle Logic + Attendance + Reports
 *
 * Payment rule:
 * - Every athlete has one NEXT DUE DATE.
 * - When you save/mark payment, the system records the payment.
 * - By default, it moves the athlete's next due date forward by 30 days.
 * - If today passes the next due date, the athlete automatically appears as UNPAID/OVERDUE.
 * - Remaining balance is carried on the athlete record until cleared.
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
function money($v) { return 'RWF ' . number_format((float)$v, 0); }
function today() { return date('Y-m-d'); }
function month_now() { return date('Y-m'); }
function valid_date($d, $fallback = null) {
    if (!$fallback) $fallback = today();
    $dt = DateTime::createFromFormat('Y-m-d', (string)$d);
    return ($dt && $dt->format('Y-m-d') === $d) ? $d : $fallback;
}
function add_30_days($date) {
    $dt = new DateTime(valid_date($date));
    $dt->modify('+30 days');
    return $dt->format('Y-m-d');
}
function days_overdue($dueDate) {
    $today = new DateTime(today());
    $due = new DateTime(valid_date($dueDate));
    return $today > $due ? (int)$due->diff($today)->days : 0;
}
function member_payment_status($nextDueDate, $balanceRemaining) {
    $overdue = days_overdue($nextDueDate);
    $balance = (float)$balanceRemaining;
    if ($balance > 0 && $overdue > 0) return 'OVERDUE + BALANCE';
    if ($overdue > 0) return 'UNPAID';
    if ($balance > 0) return 'BALANCE LEFT';
    return 'PAID / ACTIVE';
}
function status_class($status) {
    if (str_contains($status, 'OVERDUE') || $status === 'UNPAID') return 'bg-red-100 text-red-700 ring-red-200';
    if ($status === 'BALANCE LEFT') return 'bg-amber-100 text-amber-700 ring-amber-200';
    return 'bg-emerald-100 text-emerald-700 ring-emerald-200';
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
    $pdo->exec("ALTER TABLE members ADD COLUMN IF NOT EXISTS monthly_fee NUMERIC(12,2) NOT NULL DEFAULT 0");
    $pdo->exec("ALTER TABLE members ADD COLUMN IF NOT EXISTS next_due_date DATE NOT NULL DEFAULT CURRENT_DATE");
    $pdo->exec("ALTER TABLE members ADD COLUMN IF NOT EXISTS balance_remaining NUMERIC(12,2) NOT NULL DEFAULT 0");
    $pdo->exec("ALTER TABLE members ADD COLUMN IF NOT EXISTS is_active BOOLEAN NOT NULL DEFAULT TRUE");
    $pdo->exec("ALTER TABLE members ADD COLUMN IF NOT EXISTS created_at TIMESTAMP NOT NULL DEFAULT NOW()");

    $pdo->exec("CREATE TABLE IF NOT EXISTS payment_logs (
        id SERIAL PRIMARY KEY,
        member_id INTEGER NOT NULL REFERENCES members(id) ON DELETE CASCADE,
        payment_date DATE NOT NULL DEFAULT CURRENT_DATE,
        due_date_before DATE NOT NULL,
        next_due_date_after DATE NOT NULL,
        amount_due NUMERIC(12,2) NOT NULL DEFAULT 0,
        amount_paid NUMERIC(12,2) NOT NULL DEFAULT 0,
        remaining_after NUMERIC(12,2) NOT NULL DEFAULT 0,
        advanced_cycle BOOLEAN NOT NULL DEFAULT TRUE,
        note TEXT,
        created_at TIMESTAMP NOT NULL DEFAULT NOW()
    )");

    $pdo->exec("ALTER TABLE payment_logs ADD COLUMN IF NOT EXISTS payment_date DATE NOT NULL DEFAULT CURRENT_DATE");
    $pdo->exec("ALTER TABLE payment_logs ADD COLUMN IF NOT EXISTS due_date_before DATE NOT NULL DEFAULT CURRENT_DATE");
    $pdo->exec("ALTER TABLE payment_logs ADD COLUMN IF NOT EXISTS next_due_date_after DATE NOT NULL DEFAULT CURRENT_DATE");
    $pdo->exec("ALTER TABLE payment_logs ADD COLUMN IF NOT EXISTS amount_due NUMERIC(12,2) NOT NULL DEFAULT 0");
    $pdo->exec("ALTER TABLE payment_logs ADD COLUMN IF NOT EXISTS amount_paid NUMERIC(12,2) NOT NULL DEFAULT 0");
    $pdo->exec("ALTER TABLE payment_logs ADD COLUMN IF NOT EXISTS remaining_after NUMERIC(12,2) NOT NULL DEFAULT 0");
    $pdo->exec("ALTER TABLE payment_logs ADD COLUMN IF NOT EXISTS advanced_cycle BOOLEAN NOT NULL DEFAULT TRUE");
    $pdo->exec("ALTER TABLE payment_logs ADD COLUMN IF NOT EXISTS note TEXT");
    $pdo->exec("ALTER TABLE payment_logs ADD COLUMN IF NOT EXISTS created_at TIMESTAMP NOT NULL DEFAULT NOW()");

    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_members_due ON members(next_due_date)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_payment_logs_member ON payment_logs(member_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_payment_logs_date ON payment_logs(payment_date)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_sessions_date ON sessions(date)");
}

function csv_headers($name) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename=' . $name);
}

try {
    $pdo = get_db_connection();
    ensure_schema($pdo);

    if (isset($_GET['export_type'])) {
        $type = $_GET['export_type'];
        if ($type === 'payment_summary') {
            csv_headers('payment_summary_' . today() . '.csv');
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Athlete', 'Phone', 'Monthly Fee', 'Next Due Date', 'Status', 'Overdue Days', 'Balance Remaining']);
            $rows = $pdo->query("SELECT * FROM members WHERE is_active = TRUE ORDER BY full_name ASC")->fetchAll();
            foreach ($rows as $m) {
                $status = member_payment_status($m['next_due_date'], $m['balance_remaining']);
                fputcsv($out, [$m['full_name'], $m['phone'], $m['monthly_fee'], $m['next_due_date'], $status, days_overdue($m['next_due_date']), $m['balance_remaining']]);
            }
            exit;
        }
        if ($type === 'unpaid_list') {
            csv_headers('unpaid_overdue_kids_' . today() . '.csv');
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Athlete', 'Phone', 'Next Due Date', 'Overdue Days', 'Balance Remaining']);
            $stmt = $pdo->query("SELECT * FROM members WHERE is_active = TRUE AND (next_due_date < CURRENT_DATE OR balance_remaining > 0) ORDER BY next_due_date ASC, full_name ASC");
            while ($m = $stmt->fetch()) fputcsv($out, [$m['full_name'], $m['phone'], $m['next_due_date'], days_overdue($m['next_due_date']), $m['balance_remaining']]);
            exit;
        }
        if ($type === 'payment_history') {
            csv_headers('payment_history_' . today() . '.csv');
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Date Paid', 'Athlete', 'Due Date Before', 'Next Due Date After', 'Amount Due', 'Amount Paid', 'Remaining After', 'Advanced 30 Days', 'Note']);
            $stmt = $pdo->query("SELECT p.*, m.full_name FROM payment_logs p JOIN members m ON m.id = p.member_id ORDER BY p.payment_date DESC, p.id DESC");
            while ($p = $stmt->fetch()) fputcsv($out, [$p['payment_date'], $p['full_name'], $p['due_date_before'], $p['next_due_date_after'], $p['amount_due'], $p['amount_paid'], $p['remaining_after'], $p['advanced_cycle'] ? 'YES' : 'NO', $p['note']]);
            exit;
        }
        if ($type === 'attendance_month') {
            $month = preg_match('/^\d{4}-\d{2}$/', $_GET['month'] ?? '') ? $_GET['month'] : month_now();
            csv_headers('attendance_' . $month . '.csv');
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Athlete', 'Month', 'Times Attended']);
            $stmt = $pdo->prepare("SELECT m.full_name, COUNT(a.id) total
                FROM members m
                LEFT JOIN attendance a ON a.member_id = m.id
                LEFT JOIN sessions s ON s.id = a.session_id AND TO_CHAR(s.date, 'YYYY-MM') = ?
                WHERE m.is_active = TRUE
                GROUP BY m.full_name
                ORDER BY total DESC, m.full_name ASC");
            $stmt->execute([$month]);
            while ($r = $stmt->fetch()) fputcsv($out, [$r['full_name'], $month, $r['total']]);
            exit;
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['save_athlete'])) {
            $name = trim($_POST['full_name'] ?? '');
            if ($name !== '') {
                $pdo->prepare("INSERT INTO members(full_name, phone, monthly_fee, next_due_date) VALUES (?, ?, ?, ?)")
                    ->execute([$name, trim($_POST['phone'] ?? ''), (float)($_POST['monthly_fee'] ?? 0), valid_date($_POST['next_due_date'] ?? today())]);
            }
            header('Location: index.php?view=payments'); exit;
        }

        if (isset($_POST['update_athlete'])) {
            $pdo->prepare("UPDATE members SET full_name=?, phone=?, monthly_fee=?, next_due_date=?, balance_remaining=?, is_active=? WHERE id=?")
                ->execute([
                    trim($_POST['full_name'] ?? ''), trim($_POST['phone'] ?? ''), (float)($_POST['monthly_fee'] ?? 0),
                    valid_date($_POST['next_due_date'] ?? today()), (float)($_POST['balance_remaining'] ?? 0), isset($_POST['is_active']), (int)$_POST['mid']
                ]);
            header('Location: index.php?view=members'); exit;
        }

        if (isset($_POST['delete_athlete'])) {
            $pdo->prepare("DELETE FROM members WHERE id=?")->execute([(int)$_POST['mid']]);
            header('Location: index.php?view=members'); exit;
        }

        if (isset($_POST['save_session'])) {
            $pdo->prepare("INSERT INTO sessions(name, date) VALUES (?, ?)")->execute([trim($_POST['s_name'] ?? 'Session'), valid_date($_POST['s_date'] ?? today())]);
            header('Location: index.php?view=attendance'); exit;
        }

        if (isset($_POST['mark'])) {
            $pdo->prepare("INSERT INTO attendance(session_id, member_id) VALUES (?, ?) ON CONFLICT DO NOTHING")
                ->execute([(int)$_POST['sid'], (int)$_POST['mid']]);
            header('Location: index.php?view=attendance&session=' . (int)$_POST['sid']); exit;
        }

        if (isset($_POST['save_payment'])) {
            $mid = (int)$_POST['mid'];
            $memberStmt = $pdo->prepare("SELECT * FROM members WHERE id=?");
            $memberStmt->execute([$mid]);
            $member = $memberStmt->fetch();
            if (!$member) die('Member not found');

            $dueBefore = valid_date($_POST['due_date_before'] ?? $member['next_due_date'], $member['next_due_date']);
            $amountDue = max(0, (float)($_POST['amount_due'] ?? $member['monthly_fee']));
            $amountPaid = max(0, (float)($_POST['amount_paid'] ?? 0));
            $remaining = ($_POST['remaining_after'] ?? '') === '' ? max(0, $amountDue - $amountPaid) : max(0, (float)$_POST['remaining_after']);
            $advance = isset($_POST['advance_cycle']);
            $nextDue = $advance ? add_30_days($dueBefore) : $dueBefore;
            $paymentDate = valid_date($_POST['payment_date'] ?? today());
            $note = trim($_POST['note'] ?? '');

            $pdo->beginTransaction();
            $pdo->prepare("INSERT INTO payment_logs(member_id, payment_date, due_date_before, next_due_date_after, amount_due, amount_paid, remaining_after, advanced_cycle, note)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")
                ->execute([$mid, $paymentDate, $dueBefore, $nextDue, $amountDue, $amountPaid, $remaining, $advance, $note]);
            $pdo->prepare("UPDATE members SET monthly_fee=?, next_due_date=?, balance_remaining=? WHERE id=?")
                ->execute([$amountDue, $nextDue, $remaining, $mid]);
            $pdo->commit();

            header('Location: index.php?view=payments&paid=' . $mid); exit;
        }
    }

    if (isset($_GET['action']) && $_GET['action'] === 'unmark') {
        $pdo->prepare("DELETE FROM attendance WHERE member_id=? AND session_id=?")->execute([(int)$_GET['mid'], (int)$_GET['sid']]);
        header('Location: index.php?view=attendance&session=' . (int)$_GET['sid']); exit;
    }

    $view = $_GET['view'] ?? 'dashboard';
    $members = $pdo->query("SELECT * FROM members ORDER BY full_name ASC")->fetchAll();
    $activeMembers = array_values(array_filter($members, fn($m) => $m['is_active']));
    $sessions = $pdo->query("SELECT * FROM sessions ORDER BY date DESC, id DESC LIMIT 100")->fetchAll();
    $current_sid = $_GET['session'] ?? ($sessions[0]['id'] ?? null);
    $active_s = null; foreach ($sessions as $s) if ((string)$s['id'] === (string)$current_sid) $active_s = $s;
    $attended_ids = [];
    if ($current_sid) {
        $st = $pdo->prepare("SELECT member_id FROM attendance WHERE session_id=?");
        $st->execute([(int)$current_sid]);
        $attended_ids = $st->fetchAll(PDO::FETCH_COLUMN);
    }
    $history = $pdo->query("SELECT p.*, m.full_name FROM payment_logs p JOIN members m ON m.id=p.member_id ORDER BY p.payment_date DESC, p.id DESC LIMIT 20")->fetchAll();

    $totalExpected = 0; $totalBalance = 0; $unpaidCount = 0; $overdueCount = 0; $activeCount = 0;
    foreach ($activeMembers as $m) {
        $activeCount++;
        $totalExpected += (float)$m['monthly_fee'];
        $totalBalance += (float)$m['balance_remaining'];
        if (days_overdue($m['next_due_date']) > 0 || (float)$m['balance_remaining'] > 0) $unpaidCount++;
        if (days_overdue($m['next_due_date']) > 0) $overdueCount++;
    }

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    die('System Error: ' . h($e->getMessage()));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Ujamaa Academy | Apex v10</title>
<script src="https://cdn.tailwindcss.com"></script>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<style>
body{font-family:'Plus Jakarta Sans',sans-serif;background:#f4f7fb}.glass{background:rgba(255,255,255,.82);backdrop-filter:blur(18px)}.nav-active{background:#4f46e5;color:white;box-shadow:0 16px 35px rgba(79,70,229,.25)}.nav-idle{color:#94a3b8}.nav-idle:hover{background:rgba(255,255,255,.06);color:white}.modal{display:none}.modal.show{display:flex}
</style>
</head>
<body class="text-slate-900">
<div class="min-h-screen flex flex-col lg:flex-row">
<aside class="lg:w-80 bg-slate-950 text-white p-6 lg:p-8 flex flex-col">
    <div class="mb-8"><h1 class="text-3xl font-black italic">UJAMAA<span class="text-indigo-400">.</span></h1><p class="text-xs text-slate-400 font-bold mt-1">Academy Manager v10</p></div>
    <nav class="space-y-2 flex-1">
        <?php $navs=['dashboard'=>'Dashboard','payments'=>'Payments','attendance'=>'Attendance','members'=>'Members','reports'=>'Reports']; foreach($navs as $k=>$label): ?>
        <a href="?view=<?= $k ?>" class="block rounded-2xl px-5 py-4 font-extrabold text-sm <?= $view===$k?'nav-active':'nav-idle' ?>"><?= $label ?></a>
        <?php endforeach; ?>
    </nav>
    <div class="bg-white/5 rounded-[2rem] p-5 border border-white/10 mt-6">
        <h3 class="text-[10px] uppercase tracking-widest font-black text-indigo-300 mb-3">Fast Add Athlete</h3>
        <form method="POST" class="space-y-3">
            <input name="full_name" placeholder="Full name" class="w-full bg-slate-900 p-3 rounded-xl text-sm outline-none" required>
            <input name="phone" placeholder="Phone optional" class="w-full bg-slate-900 p-3 rounded-xl text-sm outline-none">
            <input type="number" step="1" name="monthly_fee" placeholder="Monthly fee" class="w-full bg-slate-900 p-3 rounded-xl text-sm outline-none">
            <label class="text-[10px] font-black text-slate-400 uppercase">First due date</label>
            <input type="date" name="next_due_date" value="<?= today() ?>" class="w-full bg-slate-900 p-3 rounded-xl text-sm outline-none">
            <button name="save_athlete" class="w-full bg-white text-slate-950 rounded-xl py-3 text-xs font-black uppercase">Add Athlete</button>
        </form>
    </div>
</aside>

<main class="flex-1 p-5 lg:p-10">
<?php if ($view === 'dashboard'): ?>
    <div class="max-w-7xl mx-auto space-y-8">
        <header><p class="text-sm font-bold text-indigo-600">Today: <?= today() ?></p><h2 class="text-4xl lg:text-5xl font-black tracking-tight">Manager Dashboard</h2></header>
        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-5 gap-4">
            <div class="bg-white p-6 rounded-[2rem] shadow-sm"><p class="text-xs font-black text-slate-400 uppercase">Active Kids</p><h3 class="text-3xl font-black mt-2"><?= $activeCount ?></h3></div>
            <div class="bg-white p-6 rounded-[2rem] shadow-sm"><p class="text-xs font-black text-slate-400 uppercase">Expected Cycle Money</p><h3 class="text-2xl font-black mt-2"><?= money($totalExpected) ?></h3></div>
            <div class="bg-white p-6 rounded-[2rem] shadow-sm"><p class="text-xs font-black text-slate-400 uppercase">Remaining Balance</p><h3 class="text-2xl font-black mt-2 text-amber-600"><?= money($totalBalance) ?></h3></div>
            <div class="bg-white p-6 rounded-[2rem] shadow-sm"><p class="text-xs font-black text-slate-400 uppercase">Unpaid / Balance</p><h3 class="text-3xl font-black mt-2 text-red-600"><?= $unpaidCount ?></h3></div>
            <div class="bg-white p-6 rounded-[2rem] shadow-sm"><p class="text-xs font-black text-slate-400 uppercase">Overdue Date Passed</p><h3 class="text-3xl font-black mt-2 text-red-700"><?= $overdueCount ?></h3></div>
        </div>
        <section class="bg-white rounded-[2.5rem] p-6 shadow-sm overflow-x-auto">
            <div class="flex flex-col md:flex-row md:items-center justify-between gap-3 mb-5"><h3 class="text-xl font-black">Unpaid kids / overdue / balance left</h3><a href="?export_type=unpaid_list" class="bg-red-600 text-white rounded-xl px-4 py-3 text-xs font-black uppercase">Download unpaid list</a></div>
            <table class="w-full text-sm"><thead><tr class="text-left text-slate-400 uppercase text-[10px]"><th class="p-3">Name</th><th>Next Due</th><th>Status</th><th>Overdue</th><th>Balance</th></tr></thead><tbody>
            <?php foreach($activeMembers as $m): $st=member_payment_status($m['next_due_date'],$m['balance_remaining']); if($st==='PAID / ACTIVE') continue; ?>
            <tr class="border-t"><td class="p-3 font-black"><?= h($m['full_name']) ?></td><td><?= h($m['next_due_date']) ?></td><td><span class="px-3 py-1 rounded-full text-[10px] font-black ring-1 <?= status_class($st) ?>"><?= h($st) ?></span></td><td><?= days_overdue($m['next_due_date']) ?> days</td><td><?= money($m['balance_remaining']) ?></td></tr>
            <?php endforeach; ?>
            </tbody></table>
        </section>
    </div>

<?php elseif ($view === 'payments'): ?>
    <div class="max-w-7xl mx-auto space-y-6">
        <header class="flex flex-col md:flex-row md:items-end justify-between gap-4"><div><p class="text-sm font-bold text-indigo-600">30-day automatic due cycle</p><h2 class="text-4xl font-black">Payments</h2><p class="text-slate-500 font-semibold mt-2">Save a payment, and the next due date moves forward by 30 days by default.</p></div><a href="?export_type=payment_summary" class="bg-slate-950 text-white rounded-2xl px-5 py-4 text-xs font-black uppercase">Download Summary</a></header>
        <input id="paySearch" onkeyup="filterRows('paySearch','pay-row','pay-name')" placeholder="Search athlete..." class="w-full bg-white rounded-2xl p-5 outline-none ring-1 ring-slate-200 focus:ring-indigo-500 font-bold">
        <div class="grid grid-cols-1 xl:grid-cols-2 gap-5">
        <?php foreach($activeMembers as $m): $st=member_payment_status($m['next_due_date'],$m['balance_remaining']); $suggestedDue=(float)$m['monthly_fee']+(float)$m['balance_remaining']; ?>
            <article class="pay-row bg-white rounded-[2rem] p-6 shadow-sm ring-1 ring-slate-100">
                <div class="flex items-start justify-between gap-4 mb-5"><div><h3 class="pay-name text-xl font-black"><?= h($m['full_name']) ?></h3><p class="text-xs font-bold text-slate-400"><?= h($m['phone']) ?></p></div><span class="px-3 py-2 rounded-full text-[10px] font-black ring-1 <?= status_class($st) ?>"><?= h($st) ?></span></div>
                <div class="grid grid-cols-3 gap-3 mb-5">
                    <div class="bg-slate-50 p-4 rounded-2xl"><p class="text-[10px] uppercase font-black text-slate-400">Next Due</p><p class="font-black"><?= h($m['next_due_date']) ?></p></div>
                    <div class="bg-slate-50 p-4 rounded-2xl"><p class="text-[10px] uppercase font-black text-slate-400">Overdue</p><p class="font-black"><?= days_overdue($m['next_due_date']) ?> days</p></div>
                    <div class="bg-slate-50 p-4 rounded-2xl"><p class="text-[10px] uppercase font-black text-slate-400">Balance</p><p class="font-black"><?= money($m['balance_remaining']) ?></p></div>
                </div>
                <form method="POST" class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <input type="hidden" name="mid" value="<?= $m['id'] ?>">
                    <div><label class="text-[10px] font-black uppercase text-slate-400">Payment Date</label><input type="date" name="payment_date" value="<?= today() ?>" class="w-full bg-slate-50 rounded-xl p-3 font-bold"></div>
                    <div><label class="text-[10px] font-black uppercase text-slate-400">Current Due Date</label><input type="date" name="due_date_before" value="<?= h($m['next_due_date']) ?>" class="w-full bg-slate-50 rounded-xl p-3 font-bold"></div>
                    <div><label class="text-[10px] font-black uppercase text-slate-400">How much must pay</label><input type="number" step="1" name="amount_due" value="<?= h($suggestedDue) ?>" class="w-full bg-slate-50 rounded-xl p-3 font-bold"></div>
                    <div><label class="text-[10px] font-black uppercase text-slate-400">Amount paid</label><input type="number" step="1" name="amount_paid" value="<?= h($suggestedDue) ?>" class="w-full bg-slate-50 rounded-xl p-3 font-bold"></div>
                    <div><label class="text-[10px] font-black uppercase text-slate-400">Remaining after payment</label><input type="number" step="1" name="remaining_after" placeholder="Auto if empty" class="w-full bg-slate-50 rounded-xl p-3 font-bold"></div>
                    <div><label class="text-[10px] font-black uppercase text-slate-400">Note</label><input name="note" placeholder="Optional" class="w-full bg-slate-50 rounded-xl p-3 font-bold"></div>
                    <label class="md:col-span-2 flex items-center gap-3 bg-indigo-50 text-indigo-800 p-4 rounded-2xl font-black text-xs"><input type="checkbox" name="advance_cycle" checked class="w-5 h-5"> After saving, automatically add 30 days to next due date</label>
                    <button name="save_payment" class="md:col-span-2 bg-indigo-600 text-white rounded-2xl p-4 font-black uppercase text-xs">Save Payment / Mark This Cycle</button>
                </form>
            </article>
        <?php endforeach; ?>
        </div>
    </div>

<?php elseif ($view === 'attendance'): ?>
    <div class="max-w-6xl mx-auto space-y-6">
        <header class="flex flex-col md:flex-row justify-between gap-4"><div><h2 class="text-4xl font-black">Attendance</h2><p class="text-slate-500 font-bold"><?= $active_s ? h($active_s['name']).' - '.h($active_s['date']) : 'Create a session first' ?></p></div><button onclick="openModal('m-session')" class="bg-indigo-600 text-white rounded-2xl px-5 py-4 font-black text-xs uppercase">New Session</button></header>
        <select onchange="location.href='?view=attendance&session='+this.value" class="w-full bg-white p-4 rounded-2xl font-bold ring-1 ring-slate-200">
            <?php foreach($sessions as $s): ?><option value="<?= $s['id'] ?>" <?= (string)$current_sid===(string)$s['id']?'selected':'' ?>><?= h($s['date']) ?> - <?= h($s['name']) ?></option><?php endforeach; ?>
        </select>
        <input id="attSearch" onkeyup="filterRows('attSearch','att-row','att-name')" placeholder="Search athlete..." class="w-full bg-white rounded-2xl p-5 outline-none ring-1 ring-slate-200 font-bold">
        <div class="bg-white rounded-[2.5rem] shadow-sm overflow-hidden">
        <?php foreach($activeMembers as $m): $isP=in_array($m['id'],$attended_ids); ?>
            <div class="att-row flex items-center justify-between gap-4 p-5 border-b last:border-0">
                <span class="att-name font-black text-lg"><?= h($m['full_name']) ?></span>
                <?php if($current_sid): ?>
                    <?php if($isP): ?><a href="?view=attendance&action=unmark&mid=<?= $m['id'] ?>&sid=<?= $current_sid ?>" class="bg-emerald-500 text-white px-6 py-3 rounded-xl text-xs font-black uppercase">Present</a>
                    <?php else: ?><form method="POST"><input type="hidden" name="sid" value="<?= $current_sid ?>"><input type="hidden" name="mid" value="<?= $m['id'] ?>"><button name="mark" class="bg-slate-100 text-slate-600 px-6 py-3 rounded-xl text-xs font-black uppercase">Mark</button></form><?php endif; ?>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        </div>
    </div>

<?php elseif ($view === 'members'): ?>
    <div class="max-w-7xl mx-auto space-y-6"><header><h2 class="text-4xl font-black">Members</h2><p class="text-slate-500 font-bold">Edit fees, due dates, balances, and active status.</p></header>
    <div class="grid grid-cols-1 xl:grid-cols-2 gap-5">
    <?php foreach($members as $m): ?>
        <form method="POST" class="bg-white rounded-[2rem] p-6 shadow-sm grid grid-cols-1 md:grid-cols-2 gap-3">
            <input type="hidden" name="mid" value="<?= $m['id'] ?>">
            <div class="md:col-span-2"><label class="text-[10px] font-black uppercase text-slate-400">Full Name</label><input name="full_name" value="<?= h($m['full_name']) ?>" class="w-full bg-slate-50 p-3 rounded-xl font-bold"></div>
            <div><label class="text-[10px] font-black uppercase text-slate-400">Phone</label><input name="phone" value="<?= h($m['phone']) ?>" class="w-full bg-slate-50 p-3 rounded-xl font-bold"></div>
            <div><label class="text-[10px] font-black uppercase text-slate-400">Monthly Fee</label><input type="number" step="1" name="monthly_fee" value="<?= h($m['monthly_fee']) ?>" class="w-full bg-slate-50 p-3 rounded-xl font-bold"></div>
            <div><label class="text-[10px] font-black uppercase text-slate-400">Next Due Date</label><input type="date" name="next_due_date" value="<?= h($m['next_due_date']) ?>" class="w-full bg-slate-50 p-3 rounded-xl font-bold"></div>
            <div><label class="text-[10px] font-black uppercase text-slate-400">Balance Remaining</label><input type="number" step="1" name="balance_remaining" value="<?= h($m['balance_remaining']) ?>" class="w-full bg-slate-50 p-3 rounded-xl font-bold"></div>
            <label class="flex items-center gap-3 bg-slate-50 p-3 rounded-xl font-black text-xs"><input type="checkbox" name="is_active" <?= $m['is_active']?'checked':'' ?>> Active</label>
            <div class="flex gap-2"><button name="update_athlete" class="flex-1 bg-slate-950 text-white p-3 rounded-xl text-xs font-black uppercase">Save</button><button name="delete_athlete" onclick="return confirm('Delete this member permanently?')" class="bg-red-600 text-white p-3 rounded-xl text-xs font-black uppercase">Delete</button></div>
        </form>
    <?php endforeach; ?>
    </div></div>

<?php elseif ($view === 'reports'): ?>
    <div class="max-w-6xl mx-auto space-y-6"><header><h2 class="text-4xl font-black">Reports</h2><p class="text-slate-500 font-bold">Download lists for accounting and attendance.</p></header>
        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4">
            <a href="?export_type=payment_summary" class="bg-white rounded-[2rem] p-6 shadow-sm font-black">Payment Summary<br><span class="text-xs text-indigo-600 uppercase">Download CSV</span></a>
            <a href="?export_type=unpaid_list" class="bg-white rounded-[2rem] p-6 shadow-sm font-black">Unpaid Kids<br><span class="text-xs text-red-600 uppercase">Download CSV</span></a>
            <a href="?export_type=payment_history" class="bg-white rounded-[2rem] p-6 shadow-sm font-black">Payment History<br><span class="text-xs text-indigo-600 uppercase">Download CSV</span></a>
            <form method="GET" class="bg-white rounded-[2rem] p-6 shadow-sm"><input type="hidden" name="export_type" value="attendance_month"><label class="text-xs uppercase font-black text-slate-400">Month</label><input type="month" name="month" value="<?= month_now() ?>" class="w-full bg-slate-50 rounded-xl p-3 my-3 font-bold"><button class="bg-indigo-600 text-white rounded-xl p-3 w-full text-xs font-black uppercase">Attendance CSV</button></form>
        </div>
        <section class="bg-white rounded-[2.5rem] p-6 shadow-sm overflow-x-auto"><h3 class="font-black text-xl mb-4">Recent payment history</h3><table class="w-full text-sm"><thead><tr class="text-left text-slate-400 text-[10px] uppercase"><th class="p-3">Date</th><th>Name</th><th>Due Before</th><th>Next Due</th><th>Due</th><th>Paid</th><th>Remain</th></tr></thead><tbody><?php foreach($history as $p): ?><tr class="border-t"><td class="p-3"><?= h($p['payment_date']) ?></td><td class="font-black"><?= h($p['full_name']) ?></td><td><?= h($p['due_date_before']) ?></td><td><?= h($p['next_due_date_after']) ?></td><td><?= money($p['amount_due']) ?></td><td><?= money($p['amount_paid']) ?></td><td><?= money($p['remaining_after']) ?></td></tr><?php endforeach; ?></tbody></table></section>
    </div>
<?php endif; ?>
</main>
</div>

<div id="m-session" class="modal fixed inset-0 bg-slate-950/80 items-center justify-center p-6 z-50">
    <div class="bg-white rounded-[2.5rem] p-8 w-full max-w-md"><h3 class="text-2xl font-black mb-5">New Session</h3><form method="POST" class="space-y-4"><input name="s_name" placeholder="Session title" class="w-full bg-slate-50 p-4 rounded-xl font-bold" required><input type="date" name="s_date" value="<?= today() ?>" class="w-full bg-slate-50 p-4 rounded-xl font-bold"><button name="save_session" class="w-full bg-indigo-600 text-white p-4 rounded-xl font-black uppercase text-xs">Create</button><button type="button" onclick="closeModal('m-session')" class="w-full p-2 font-bold text-slate-400">Cancel</button></form></div>
</div>
<script>
function openModal(id){document.getElementById(id).classList.add('show')}
function closeModal(id){document.getElementById(id).classList.remove('show')}
function filterRows(inputId,rowClass,nameClass){const q=document.getElementById(inputId).value.toLowerCase();document.querySelectorAll('.'+rowClass).forEach(row=>{const el=row.querySelector('.'+nameClass);row.style.display=el&&el.innerText.toLowerCase().includes(q)?'':'none'})}
</script>
</body>
</html>
