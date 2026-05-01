<?php
/**
 * UJAMAA ACADEMY - ENTERPRISE ALL-IN-ONE
 * Features: Attendance, Financial Ledger, Daily Reporting, View Toggling
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);

// Database Connection
$databaseUrl = getenv("DATABASE_URL");
if (!$databaseUrl) die("Database configuration missing.");
$url = parse_url($databaseUrl);
$dsn = "pgsql:host={$url['host']};port=" . ($url['port'] ?? 5432) . ";dbname=" . ltrim($url['path'], '/') . ";sslmode=require";

try {
    $pdo = new PDO($dsn, $url['user'], $url['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

    // --- CONTROLLER: POST ACTIONS ---
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['add_member'])) {
            $pdo->prepare("INSERT INTO members(full_name) VALUES (?) ON CONFLICT DO NOTHING")->execute([trim($_POST['name'])]);
        }
        if (isset($_POST['create_session'])) {
            $pdo->prepare("INSERT INTO sessions(name, date) VALUES (?, ?)")->execute([trim($_POST['session']), $_POST['sdate']]);
        }
        if (isset($_POST['mark_attendance'])) {
            $pdo->prepare("INSERT INTO attendance(session_id, member_id) VALUES (?, ?)")->execute([$_POST['session_id'], $_POST['member_id']]);
        }
        if (isset($_POST['set_payment'])) {
            $pdo->prepare("INSERT INTO payments (member_id, amount, due_date) VALUES (?, ?, ?)")->execute([$_POST['member_id'], $_POST['amount'], $_POST['due_date']]);
        }
        header("Location: " . $_SERVER['REQUEST_URI']); exit;
    }

    // --- CONTROLLER: GET ACTIONS ---
    if (isset($_GET['action'])) {
        if ($_GET['action'] === 'del_member') {
            $pdo->prepare("DELETE FROM members WHERE id = ?")->execute([$_GET['id']]);
        }
        if ($_GET['action'] === 'unmark') {
            $pdo->prepare("DELETE FROM attendance WHERE member_id = ? AND session_id = ?")->execute([$_GET['mid'], $_GET['sid']]);
        }
        if ($_GET['action'] === 'mark_paid') {
            $pdo->prepare("UPDATE payments SET status = 'paid', paid_at = NOW() WHERE id = ?")->execute([$_GET['pid']]);
        }
        header("Location: " . strtok($_SERVER["REQUEST_URI"], '?') . (isset($_GET['session']) ? "?session=".$_GET['session'] : ""));
        exit;
    }

    // --- DATA FETCHING ---
    $today = date('Y-m-d');
    $sessions = $pdo->query("SELECT * FROM sessions ORDER BY date DESC, id DESC")->fetchAll();
    $current_sid = $_GET['session'] ?? ($sessions[0]['id'] ?? null);
    $members = $pdo->query("SELECT * FROM members ORDER BY full_name ASC")->fetchAll();
    
    // Attendance Stats
    $attended_ids = [];
    if ($current_sid) {
        $stmt = $pdo->prepare("SELECT member_id FROM attendance WHERE session_id = ?");
        $stmt->execute([$current_sid]);
        $attended_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    // Payment Stats (Due Today or Overdue)
    $due_today = $pdo->query("SELECT p.*, m.full_name FROM payments p JOIN members m ON p.member_id = m.id WHERE p.due_date <= '$today' AND p.status = 'unpaid' ORDER BY p.due_date ASC")->fetchAll();

} catch (Exception $e) { die("Critical System Error: " . $e->getMessage()); }
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ujamaa Academy | Management Suite</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; transition: background 0.3s ease; }
        .tab-active { color: #4f46e5; border-bottom: 2px solid #4f46e5; }
        .glass { background: rgba(255, 255, 255, 0.8); backdrop-filter: blur(10px); }
    </style>
</head>
<body class="bg-[#f8fafc] text-slate-900 min-h-screen pb-20">

<div class="max-w-7xl mx-auto px-4 lg:px-10 pt-10">
    
    <!-- HEADER & TOGGLE -->
    <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-6 mb-10">
        <div>
            <h1 class="text-4xl font-800 tracking-tight">UJAMAA ACADEMY</h1>
            <p class="text-slate-500 font-medium">Internal Management & Athlete Tracking</p>
        </div>
        
        <div class="flex bg-white p-1.5 rounded-2xl shadow-sm border border-slate-100">
            <button onclick="switchTab('attendance')" id="btn-attendance" class="px-6 py-2 rounded-xl text-sm font-bold transition-all duration-200 bg-indigo-600 text-white">Training</button>
            <button onclick="switchTab('finance')" id="btn-finance" class="px-6 py-2 rounded-xl text-sm font-bold transition-all duration-200 text-slate-400 hover:text-slate-600">Finance</button>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-12 gap-8">
        
        <!-- SIDEBAR -->
        <div class="lg:col-span-4 space-y-6">
            <!-- Global Stats -->
            <div class="bg-slate-900 rounded-[2.5rem] p-8 text-white shadow-2xl relative overflow-hidden">
                <div class="relative z-10">
                    <p class="text-slate-400 text-xs font-bold uppercase tracking-widest mb-1">Total Athletes</p>
                    <h2 class="text-5xl font-800 mb-6"><?= count($members) ?></h2>
                    <div class="flex gap-4">
                        <div class="flex-1 bg-white/10 p-4 rounded-2xl">
                            <p class="text-[10px] text-slate-300 font-bold uppercase">Due Today</p>
                            <p class="text-xl font-800"><?= count($due_today) ?></p>
                        </div>
                        <div class="flex-1 bg-white/10 p-4 rounded-2xl">
                            <p class="text-[10px] text-slate-300 font-bold uppercase">Present</p>
                            <p class="text-xl font-800"><?= count($attended_ids) ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Quick Action Form -->
            <div class="bg-white rounded-[2.5rem] p-8 shadow-sm border border-slate-100">
                <h3 class="font-800 text-lg mb-6">New Athlete</h3>
                <form method="POST" class="space-y-3">
                    <input name="name" placeholder="Full Name" class="w-full p-4 bg-slate-50 rounded-2xl border-none text-sm outline-none ring-1 ring-slate-100 focus:ring-indigo-600 transition" required>
                    <button name="add_member" class="w-full bg-indigo-600 text-white py-4 rounded-2xl font-bold hover:bg-indigo-700 transition shadow-lg shadow-indigo-100">Register Athlete</button>
                </form>
            </div>
        </div>

        <!-- MAIN CONTENT AREA -->
        <div class="lg:col-span-8 space-y-6">
            
            <!-- ATTENDANCE VIEW -->
            <div id="view-attendance" class="space-y-6">
                <div class="bg-white rounded-[2.5rem] p-8 shadow-sm border border-slate-100">
                    <div class="flex justify-between items-center mb-8">
                        <h2 class="text-2xl font-800">Training Registry</h2>
                        <select onchange="window.location.href='?session='+this.value" class="bg-slate-50 border-none rounded-xl px-4 py-2 text-sm font-bold ring-1 ring-slate-100">
                            <?php foreach($sessions as $s): ?>
                                <option value="<?= $s['id'] ?>" <?= $current_sid == $s['id'] ? 'selected' : '' ?>><?= $s['name'] ?> (<?= $s['date'] ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <input type="text" onkeyup="filterList('aTable', this.value)" placeholder="Search athletes..." class="w-full p-4 mb-6 bg-slate-50 rounded-2xl border-none text-sm ring-1 ring-slate-100 outline-none">

                    <div class="overflow-x-auto">
                        <table class="w-full text-left">
                            <tbody id="aTable">
                                <?php foreach($members as $m): ?>
                                <tr class="item-row border-b border-slate-50" data-search="<?= strtolower($m['full_name']) ?>">
                                    <td class="py-4 font-bold text-slate-700"><?= $m['full_name'] ?></td>
                                    <td class="py-4 text-right space-x-2">
                                        <?php if(in_array($m['id'], $attended_ids)): ?>
                                            <span class="text-[10px] font-900 bg-emerald-100 text-emerald-600 px-3 py-1 rounded-full uppercase">Present</span>
                                            <a href="?action=unmark&mid=<?= $m['id'] ?>&sid=<?= $current_sid ?>" class="text-xs text-slate-300 hover:text-red-500">Undo</a>
                                        <?php else: ?>
                                            <form method="POST" class="inline">
                                                <input type="hidden" name="session_id" value="<?= $current_sid ?>">
                                                <input type="hidden" name="member_id" value="<?= $m['id'] ?>">
                                                <button name="mark_attendance" class="text-indigo-600 font-bold text-xs hover:bg-indigo-50 px-4 py-2 rounded-xl transition">Mark Presence</button>
                                            </form>
                                        <?php endif; ?>
                                        <button onclick="openPaymentModal('<?= $m['id'] ?>')" class="p-2 text-slate-300 hover:text-emerald-500"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg></button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- FINANCE VIEW (Hidden by default) -->
            <div id="view-finance" class="hidden space-y-6">
                <div class="bg-white rounded-[2.5rem] p-8 shadow-sm border border-red-100">
                    <h2 class="text-2xl font-800 mb-2 text-red-600">Pending Collections</h2>
                    <p class="text-slate-400 text-sm mb-8">These payments are due today or overdue.</p>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <?php foreach($due_today as $pay): ?>
                        <div class="flex items-center justify-between p-5 bg-red-50/50 rounded-[1.5rem] border border-red-100">
                            <div>
                                <p class="font-bold text-slate-800"><?= $pay['full_name'] ?></p>
                                <p class="text-xs font-bold text-red-500 italic">Due: <?= $pay['due_date'] ?></p>
                            </div>
                            <div class="text-right">
                                <p class="text-sm font-800 mb-2">RWF <?= number_format($pay['amount']) ?></p>
                                <a href="?action=mark_paid&pid=<?= $pay['id'] ?>" class="bg-slate-900 text-white text-[10px] font-bold px-4 py-2 rounded-lg hover:bg-black transition">Settle</a>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <?php if(empty($due_today)) echo '<p class="col-span-2 text-slate-400 text-center py-10 italic">No collections due today.</p>'; ?>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<!-- PAYMENT MODAL -->
<div id="payModal" class="hidden fixed inset-0 bg-slate-900/40 backdrop-blur-sm z-50 flex items-center justify-center p-4">
    <div class="bg-white w-full max-w-md rounded-[2.5rem] p-8 shadow-2xl">
        <h3 class="text-2xl font-800 mb-6">Request Payment</h3>
        <form method="POST" class="space-y-4">
            <input type="hidden" name="member_id" id="modal_mid">
            <input type="number" name="amount" placeholder="Amount (RWF)" class="w-full p-4 bg-slate-50 rounded-2xl border-none ring-1 ring-slate-100 outline-none" required>
            <input type="date" name="due_date" value="<?= date('Y-m-d') ?>" class="w-full p-4 bg-slate-50 rounded-2xl border-none ring-1 ring-slate-100 outline-none" required>
            <div class="flex gap-2 pt-4">
                <button type="button" onclick="closeModal()" class="flex-1 py-4 font-bold text-slate-400">Cancel</button>
                <button type="submit" name="set_payment" class="flex-1 bg-indigo-600 text-white py-4 rounded-2xl font-bold shadow-lg shadow-indigo-100">Set Due Date</button>
            </div>
        </form>
    </div>
</div>

<script>
    function switchTab(view) {
        const vAtt = document.getElementById('view-attendance');
        const vFin = document.getElementById('view-finance');
        const bAtt = document.getElementById('btn-attendance');
        const bFin = document.getElementById('btn-finance');

        if (view === 'attendance') {
            vAtt.classList.remove('hidden'); vFin.classList.add('hidden');
            bAtt.className = 'px-6 py-2 rounded-xl text-sm font-bold bg-indigo-600 text-white';
            bFin.className = 'px-6 py-2 rounded-xl text-sm font-bold text-slate-400';
        } else {
            vFin.classList.remove('hidden'); vAtt.classList.add('hidden');
            bFin.className = 'px-6 py-2 rounded-xl text-sm font-bold bg-indigo-600 text-white';
            bAtt.className = 'px-6 py-2 rounded-xl text-sm font-bold text-slate-400';
        }
    }

    function filterList(tableId, query) {
        const q = query.toLowerCase();
        document.querySelectorAll(`#${tableId} tr`).forEach(row => {
            row.style.display = row.getAttribute('data-search').includes(q) ? '' : 'none';
        });
    }

    function openPaymentModal(id) {
        document.getElementById('modal_mid').value = id;
        document.getElementById('payModal').classList.remove('hidden');
    }

    function closeModal() {
        document.getElementById('payModal').classList.add('hidden');
    }
</script>

</body>
</html>
