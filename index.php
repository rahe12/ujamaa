<?php
/**
 * UJAMAA ACADEMY - ENTERPRISE EDITION V4.5
 * Integrated Attendance & Automated Payment Deadlines
 */

// 1. EXPORT ENGINE
if (isset($_GET['export_type'])) {
    $databaseUrl = getenv("DATABASE_URL");
    $url = parse_url($databaseUrl);
    $dsn = "pgsql:host={$url['host']};port=" . ($url['port'] ?? 5432) . ";dbname=" . ltrim($url['path'], '/') . ";sslmode=require";
    
    try {
        $pdo = new PDO($dsn, $url['user'], $url['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $date = $_GET['export_date'] ?? date('Y-m-d');
        
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=Ujamaa_Full_Report_' . $date . '.csv');
        
        $output = fopen('php://output', 'w');
        fputcsv($output, ['Athlete', 'Attendance', 'Payment Status', 'Due Date']);
        
        $stmt = $pdo->prepare("
            SELECT m.full_name, 
            CASE WHEN a.id IS NOT NULL THEN 'PRESENT' ELSE 'ABSENT' END as att,
            COALESCE(p.status, 'No Record') as pay,
            p.due_date
            FROM members m 
            CROSS JOIN sessions s 
            LEFT JOIN attendance a ON a.member_id = m.id AND a.session_id = s.id 
            LEFT JOIN payments p ON p.member_id = m.id
            WHERE s.date = ?
        ");
        $stmt->execute([$date]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) { fputcsv($output, $row); }
        exit; 
    } catch (Exception $e) { die("Export Error"); }
}

// 2. MAIN LOGIC
$databaseUrl = getenv("DATABASE_URL");
$url = parse_url($databaseUrl);
$dsn = "pgsql:host={$url['host']};port=" . ($url['port'] ?? 5432) . ";dbname=" . ltrim($url['path'], '/') . ";sslmode=require";

try {
    $pdo = new PDO($dsn, $url['user'], $url['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, 
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Save Member
        if (isset($_POST['save_member'])) {
            if (!empty($_POST['member_id'])) {
                $pdo->prepare("UPDATE members SET full_name = ? WHERE id = ?")->execute([trim($_POST['name']), $_POST['member_id']]);
            } else {
                $pdo->prepare("INSERT INTO members(full_name) VALUES (?) ON CONFLICT DO NOTHING")->execute([trim($_POST['name'])]);
            }
        }
        // Save Session
        if (isset($_POST['save_session'])) {
            $pdo->prepare("INSERT INTO sessions(name, date) VALUES (?, ?)")->execute([trim($_POST['s_name']), $_POST['s_date']]);
        }
        // Mark Attendance
        if (isset($_POST['mark'])) {
            $pdo->prepare("INSERT INTO attendance(session_id, member_id) VALUES (?, ?)")->execute([$_POST['sid'], $_POST['mid']]);
        }
        // SET PAYMENT (Setting the 9th/Deadline)
        if (isset($_POST['set_payment'])) {
            $pdo->prepare("INSERT INTO payments (member_id, amount, due_date, status) 
                           VALUES (?, ?, ?, 'unpaid') 
                           ON CONFLICT (member_id) DO UPDATE 
                           SET amount = EXCLUDED.amount, due_date = EXCLUDED.due_date, status = 'unpaid'")
                ->execute([$_POST['mid'], $_POST['amount'], $_POST['due_date']]);
        }
        // PROCESS PAYMENT (Mark as Paid)
        if (isset($_POST['pay_now'])) {
            $pdo->prepare("UPDATE payments SET status = 'paid', paid_at = NOW() WHERE member_id = ?")
                ->execute([$_POST['mid']]);
        }
        header("Location: index.php" . (isset($_POST['sid']) ? "?session=".$_POST['sid'] : "")); exit;
    }

    if (isset($_GET['action'])) {
        if ($_GET['action'] === 'del_m') $pdo->prepare("DELETE FROM members WHERE id = ?")->execute([$_GET['id']]);
        if ($_GET['action'] === 'del_s') $pdo->prepare("DELETE FROM sessions WHERE id = ?")->execute([$_GET['id']]);
        if ($_GET['action'] === 'unmark') $pdo->prepare("DELETE FROM attendance WHERE member_id = ? AND session_id = ?")->execute([$_GET['mid'], $_GET['sid']]);
        header("Location: index.php"); exit;
    }

    // Load Members + Payment Alerts (Overdue if unpaid and date passed)
    $members = $pdo->query("
        SELECT m.*, p.amount, p.due_date, p.status,
        CASE WHEN p.status = 'unpaid' AND p.due_date <= CURRENT_DATE THEN 1 ELSE 0 END as is_overdue
        FROM members m
        LEFT JOIN payments p ON m.id = p.member_id
        ORDER BY is_overdue DESC, m.full_name ASC
    ")->fetchAll();

    $sessions = $pdo->query("SELECT * FROM sessions ORDER BY date DESC, id DESC")->fetchAll();
    $current_sid = $_GET['session'] ?? ($sessions[0]['id'] ?? null);
    
    $attended_ids = [];
    if ($current_sid) {
        $stmt = $pdo->prepare("SELECT member_id FROM attendance WHERE session_id = ?");
        $stmt->execute([$current_sid]);
        $attended_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

} catch (Exception $e) { die("System Error: " . $e->getMessage()); }
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ujamaa Admin | Enterprise</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; background: #f8fafc; }
        .tab-active { background: #4f46e5 !important; color: white !important; }
        .overdue-pulse { animation: pulse 2s infinite; }
        @keyframes pulse { 0% { opacity: 1; } 50% { opacity: 0.5; } 100% { opacity: 1; } }
    </style>
</head>
<body class="p-4 lg:p-10">

<div class="max-w-7xl mx-auto grid grid-cols-1 lg:grid-cols-12 gap-8">
    
    <!-- SIDEBAR -->
    <div class="lg:col-span-4 space-y-6">
        <div class="bg-slate-900 rounded-[2.5rem] p-8 text-white shadow-2xl">
            <h1 class="text-3xl font-800 mb-1">UJAMAA</h1>
            <p class="text-slate-500 text-xs font-bold uppercase tracking-widest mb-8">Management Hub</p>
            <nav class="space-y-2">
                <button onclick="toggleView('registry')" id="btn-registry" class="w-full text-left px-6 py-4 rounded-2xl font-bold transition-all tab-active">Athlete Registry</button>
                <button onclick="toggleView('sessions')" id="btn-sessions" class="w-full text-left px-6 py-4 rounded-2xl font-bold transition-all text-slate-400 hover:text-white">Sessions</button>
                <button onclick="toggleView('export')" id="btn-export" class="w-full text-left px-6 py-4 rounded-2xl font-bold transition-all text-slate-400 hover:text-white">Reports</button>
            </nav>
        </div>

        <!-- QUICK ADD -->
        <div class="bg-white rounded-[2.5rem] p-8 border border-slate-100 shadow-sm">
            <h3 class="font-800 text-lg mb-4" id="m-title">Register Athlete</h3>
            <form method="POST" class="space-y-3">
                <input type="hidden" name="member_id" id="m_id">
                <input name="name" id="m_name" placeholder="Full Name" class="w-full p-4 bg-slate-50 rounded-2xl border-none text-sm ring-1 ring-slate-100 outline-none" required>
                <button name="save_member" class="w-full bg-indigo-600 text-white py-4 rounded-2xl font-bold shadow-lg">Save Changes</button>
            </form>
        </div>
    </div>

    <!-- MAIN PANELS -->
    <div class="lg:col-span-8">
        
        <!-- REGISTRY & PAYMENTS -->
        <div id="view-registry" class="panel space-y-6">
            <div class="bg-white rounded-[2.5rem] p-8 border border-slate-100 shadow-sm">
                <div class="flex justify-between items-center mb-8">
                    <h2 class="text-2xl font-800">Academy Registry</h2>
                    <select onchange="window.location.href='?session='+this.value" class="bg-slate-50 border-none rounded-xl px-4 py-2 text-sm font-bold ring-1 ring-slate-100">
                        <?php foreach($sessions as $s): ?>
                            <option value="<?= $s['id'] ?>" <?= $current_sid == $s['id'] ? 'selected' : '' ?>><?= htmlspecialchars($s['name']) ?> (<?= $s['date'] ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full">
                        <tbody id="athlete-rows">
                            <?php foreach($members as $m): ?>
                            <tr class="border-b border-slate-50 hover:bg-slate-50/50">
                                <td class="py-6">
                                    <div class="font-bold text-slate-700"><?= htmlspecialchars($m['full_name']) ?></div>
                                    <?php if($m['is_overdue']): ?>
                                        <span class="text-[10px] font-bold text-red-500 uppercase overdue-pulse">● Overdue since <?= $m['due_date'] ?></span>
                                    <?php elseif($m['status'] === 'paid'): ?>
                                        <span class="text-[10px] font-bold text-emerald-500 uppercase">● Paid</span>
                                    <?php else: ?>
                                        <span class="text-[10px] font-bold text-slate-300 uppercase">● No payment set</span>
                                    <?php endif; ?>
                                </td>
                                <td class="py-6 text-right space-x-2">
                                    <!-- Attendance -->
                                    <?php if(in_array($m['id'], $attended_ids)): ?>
                                        <a href="?action=unmark&mid=<?= $m['id'] ?>&sid=<?= $current_sid ?>" class="text-[10px] font-900 bg-emerald-100 text-emerald-600 px-3 py-1 rounded-full uppercase">Present</a>
                                    <?php else: ?>
                                        <form method="POST" class="inline">
                                            <input type="hidden" name="sid" value="<?= $current_sid ?>"><input type="hidden" name="mid" value="<?= $m['id'] ?>">
                                            <button name="mark" class="text-indigo-600 font-bold text-xs hover:underline">Mark Presence</button>
                                        </form>
                                    <?php endif; ?>

                                    <!-- Payment Actions -->
                                    <button onclick="openPaymentModal(<?= $m['id'] ?>, '<?= addslashes($m['full_name']) ?>')" class="text-slate-400 hover:text-indigo-600 font-bold text-xs">Set Due</button>
                                    
                                    <?php if($m['status'] === 'unpaid'): ?>
                                        <form method="POST" class="inline">
                                            <input type="hidden" name="mid" value="<?= $m['id'] ?>">
                                            <button name="pay_now" class="bg-slate-900 text-white px-3 py-1 rounded-lg text-[10px] font-bold">Collect $<?= $m['amount'] ?></button>
                                        </form>
                                    <?php endif; ?>

                                    <button onclick="editM('<?= $m['id'] ?>','<?= addslashes($m['full_name']) ?>')" class="text-slate-300 hover:text-indigo-500 font-bold text-xs">Edit</button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- SESSIONS VIEW -->
        <div id="view-sessions" class="panel hidden space-y-6">
            <div class="bg-white rounded-[2.5rem] p-8 border border-slate-100 shadow-sm">
                <h2 class="text-2xl font-800 mb-6">Sessions</h2>
                <form method="POST" class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-8">
                    <input name="s_name" placeholder="Session Name" class="p-4 bg-slate-50 rounded-2xl border-none text-sm ring-1 ring-slate-100 outline-none" required>
                    <input type="date" name="s_date" value="<?= date('Y-m-d') ?>" class="p-4 bg-slate-50 rounded-2xl border-none text-sm ring-1 ring-slate-100 outline-none">
                    <button name="save_session" class="bg-slate-900 text-white rounded-2xl font-bold">Create</button>
                </form>
                <?php foreach($sessions as $s): ?>
                    <div class="flex justify-between items-center p-4 border-b border-slate-50">
                        <span class="font-bold"><?= htmlspecialchars($s['name']) ?> (<?= $s['date'] ?>)</span>
                        <a href="?action=del_s&id=<?= $s['id'] ?>" class="text-red-400 text-xs font-bold">Delete</a>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- EXPORT VIEW -->
        <div id="view-export" class="panel hidden">
            <div class="bg-white rounded-[2.5rem] p-8 border border-slate-100 shadow-sm">
                <h2 class="text-2xl font-800 mb-4">Reports</h2>
                <form method="GET" class="flex gap-4">
                    <input type="date" name="export_date" value="<?= date('Y-m-d') ?>" class="flex-1 p-4 bg-slate-50 rounded-2xl border-none ring-1 ring-slate-100">
                    <button type="submit" name="export_type" value="all" class="bg-indigo-600 text-white px-8 rounded-2xl font-bold">Download CSV</button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- PAYMENT MODAL -->
<div id="pay-modal" class="hidden fixed inset-0 bg-slate-900/60 flex items-center justify-center p-4 z-50">
    <div class="bg-white rounded-[2.5rem] p-10 w-full max-w-md shadow-2xl">
        <h3 class="text-2xl font-800 mb-2">Set Payment Due</h3>
        <p id="pay-athlete-name" class="text-indigo-600 font-bold mb-6"></p>
        <form method="POST" class="space-y-4">
            <input type="hidden" name="mid" id="pay_mid">
            <div>
                <label class="text-[10px] font-bold text-slate-400 uppercase ml-2">Amount Due</label>
                <input type="number" name="amount" value="50.00" class="w-full p-4 bg-slate-50 rounded-2xl border-none ring-1 ring-slate-100 mt-1 outline-none">
            </div>
            <div>
                <label class="text-[10px] font-bold text-slate-400 uppercase ml-2">Due Date (e.g. the 9th)</label>
                <input type="date" name="due_date" class="w-full p-4 bg-slate-50 rounded-2xl border-none ring-1 ring-slate-100 mt-1 outline-none" required>
            </div>
            <button name="set_payment" class="w-full bg-indigo-600 text-white py-4 rounded-2xl font-bold shadow-lg">Save Deadline</button>
            <button type="button" onclick="closeModal()" class="w-full text-slate-400 font-bold text-xs uppercase mt-2 text-center">Cancel</button>
        </form>
    </div>
</div>

<script>
    function toggleView(v) {
        document.querySelectorAll('.panel').forEach(p => p.classList.add('hidden'));
        document.getElementById('view-' + v).classList.remove('hidden');
        document.querySelectorAll('nav button').forEach(b => b.classList.remove('tab-active', 'text-white'));
        document.getElementById('btn-' + v).classList.add('tab-active', 'text-white');
    }

    function openPaymentModal(id, name) {
        document.getElementById('pay_mid').value = id;
        document.getElementById('pay-athlete-name').innerText = name;
        document.getElementById('pay-modal').classList.remove('hidden');
    }

    function closeModal() { document.getElementById('pay-modal').classList.add('hidden'); }

    function editM(id, name) {
        document.getElementById('m_id').value = id;
        document.getElementById('m_name').value = name;
        document.getElementById('m-title').innerText = "Edit Athlete";
    }
</script>
</body>
</html>
