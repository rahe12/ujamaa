<?php  
/** * UJAMAA ACADEMY - ENTERPRISE EDITION V5.0.1  
 * Final Refactor: Syntax Error Fixed & Logic Validated
 */  

error_reporting(E_ALL);
ini_set('display_errors', 1);

// 1. DATABASE CONNECTION BOILERPLATE
function get_db_connection() {
    $databaseUrl = getenv("DATABASE_URL");  
    $url = parse_url($databaseUrl);  
    $dsn = "pgsql:host={$url['host']};port=" . ($url['port'] ?? 5432) . ";dbname=" . ltrim($url['path'], '/') . ";sslmode=require";  
    return new PDO($dsn, $url['user'], $url['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
}

// 2. EXPORT ENGINE
if (isset($_GET['export_type'])) {  
    try {  
        $pdo = get_db_connection();
        $date = $_GET['export_date'] ?? date('Y-m-d');  
        header('Content-Type: text/csv; charset=utf-8');  
        header('Content-Disposition: attachment; filename=Ujamaa_Report_' . $date . '.csv');  
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
        while ($row = $stmt->fetch()) fputcsv($output, $row);  
        exit;  
    } catch (Exception $e) { die("Export Error"); }  
}  

// 3. MAIN CONTROLLER
try {  
    $pdo = get_db_connection();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {  
        if (isset($_POST['save_member'])) {  
            if (!empty($_POST['member_id'])) {  
                $pdo->prepare("UPDATE members SET full_name = ? WHERE id = ?")->execute([trim($_POST['name']), $_POST['member_id']]);  
            } else {  
                $pdo->prepare("INSERT INTO members(full_name) VALUES (?) ON CONFLICT DO NOTHING")->execute([trim($_POST['name'])]);  
            }  
        }  
        if (isset($_POST['save_session'])) {  
            $pdo->prepare("INSERT INTO sessions(name, date) VALUES (?, ?)")->execute([trim($_POST['s_name']), $_POST['s_date']]);  
        }  
        if (isset($_POST['mark'])) {  
            $pdo->prepare("INSERT INTO attendance(session_id, member_id) VALUES (?, ?) ON CONFLICT DO NOTHING")->execute([$_POST['sid'], $_POST['mid']]);  
        }  
        if (isset($_POST['set_payment'])) {  
            $pdo->prepare("INSERT INTO payments (member_id, amount, due_date, status) VALUES (?, ?, ?, 'unpaid') ON CONFLICT (member_id) DO UPDATE SET amount = EXCLUDED.amount, due_date = EXCLUDED.due_date, status = 'unpaid'")->execute([$_POST['mid'], $_POST['amount'], $_POST['due_date']]);  
        }  
        if (isset($_POST['pay_now'])) {  
            $pdo->prepare("UPDATE payments SET status = 'paid', paid_at = NOW() WHERE member_id = ?")->execute([$_POST['mid']]);  
        }  
        header("Location: index.php" . (isset($_POST['sid']) ? "?session=" . $_POST['sid'] : ""));  
        exit;  
    }  

    if (isset($_GET['action'])) {  
        if ($_GET['action'] === 'del_m') $pdo->prepare("DELETE FROM members WHERE id = ?")->execute([$_GET['id']]);  
        if ($_GET['action'] === 'del_s') $pdo->prepare("DELETE FROM sessions WHERE id = ?")->execute([$_GET['id']]);  
        if ($_GET['action'] === 'unmark') $pdo->prepare("DELETE FROM attendance WHERE member_id = ? AND session_id = ?")->execute([$_GET['mid'], $_GET['sid']]);  
        header("Location: index.php"); exit;  
    }  

    $members = $pdo->query("SELECT m.*, p.amount, p.due_date, p.status, CASE WHEN p.status = 'unpaid' AND p.due_date <= CURRENT_DATE THEN 1 ELSE 0 END as is_overdue FROM members m LEFT JOIN payments p ON m.id = p.member_id ORDER BY is_overdue DESC, m.full_name ASC")->fetchAll();  
    $sessions = $pdo->query("SELECT * FROM sessions ORDER BY date DESC, id DESC")->fetchAll();  
    $current_sid = $_GET['session'] ?? ($sessions[0]['id'] ?? null);  

    // --- SYNTAX FIX APPLIED HERE ---
    $attended_ids = [];
    if ($current_sid) {
        $stmt = $pdo->prepare("SELECT member_id FROM attendance WHERE session_id = ?");
        $stmt->execute([$current_sid]);
        $attended_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    $present_members = []; $absent_members = [];  
    foreach ($members as $m) { if (in_array($m['id'], $attended_ids)) $present_members[] = $m; else $absent_members[] = $m; }  

    $total_members = count($members);  
    $total_present = count($present_members);  
    $attendance_rate = $total_members > 0 ? round(($total_present / $total_members) * 100) : 0;  

} catch (Exception $e) { die("System Error: " . $e->getMessage()); }  
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ujamaa Hub | Enterprise v5.0</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f1f5f9; }
        .glass-card { background: rgba(255, 255, 255, 0.9); backdrop-filter: blur(10px); }
        .sidebar-item-active { background: #4f46e5; color: white; box-shadow: 0 10px 15px -3px rgba(79, 70, 229, 0.4); }
        .custom-scrollbar::-webkit-scrollbar { width: 6px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: #f1f5f9; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
        @keyframes pulse-red { 0%, 100% { opacity: 1; } 50% { opacity: 0.5; } }
        .animate-pulse-red { animation: pulse-red 2s cubic-bezier(0.4, 0, 0.6, 1) infinite; }
    </style>
</head>
<body class="antialiased text-slate-900">

<div class="flex flex-col lg:flex-row min-h-screen">
    
    <aside class="w-full lg:w-72 bg-slate-900 p-6 flex flex-col">
        <div class="flex items-center gap-3 mb-10 px-2 text-white">
            <div class="w-10 h-10 bg-indigo-500 rounded-xl flex items-center justify-center font-black">U</div>
            <div>
                <h1 class="font-bold text-xl tracking-tight">UJAMAA</h1>
                <p class="text-slate-500 text-[10px] font-bold uppercase tracking-widest">Enterprise Edition</p>
            </div>
        </div>

        <nav class="space-y-1 flex-1">
            <button onclick="toggleView('registry')" id="btn-registry" class="w-full flex items-center gap-3 px-4 py-3 rounded-xl font-semibold transition-all sidebar-item-active">Registry</button>
            <button onclick="toggleView('sessions')" id="btn-sessions" class="w-full flex items-center gap-3 px-4 py-3 rounded-xl font-semibold transition-all text-slate-400 hover:bg-slate-800 hover:text-white">Sessions</button>
            <button onclick="toggleView('export')" id="btn-export" class="w-full flex items-center gap-3 px-4 py-3 rounded-xl font-semibold transition-all text-slate-400 hover:bg-slate-800 hover:text-white">Reports</button>
        </nav>

        <div class="mt-10 bg-slate-800/50 rounded-2xl p-5 border border-slate-700/50">
            <h3 class="text-white text-xs font-bold uppercase mb-4" id="m-title">Add New Athlete</h3>
            <form method="POST" class="space-y-3">
                <input type="hidden" name="member_id" id="m_id">
                <input name="name" id="m_name" placeholder="Full Name" class="w-full bg-slate-900 border border-slate-700 rounded-lg p-3 text-sm text-white outline-none focus:ring-2 focus:ring-indigo-500" required>
                <button name="save_member" class="w-full bg-indigo-600 hover:bg-indigo-500 text-white py-2.5 rounded-lg font-bold text-sm transition-colors">Save Athlete</button>
            </form>
        </div>
    </aside>

    <main class="flex-1 p-4 lg:p-8 overflow-y-auto">
        <header class="mb-8 flex flex-col md:flex-row md:items-center justify-between gap-6">
            <div>
                <h2 class="text-3xl font-bold text-slate-900 tracking-tight">Management Dashboard</h2>
                <p class="text-slate-500 font-medium">Ujamaa Academy Athlete Registry</p>
            </div>
            
            <div class="flex items-center gap-3 bg-white p-2 rounded-2xl shadow-sm border border-slate-200">
                <span class="pl-4 text-xs font-bold text-slate-400 uppercase">Active Session</span>
                <select onchange="window.location.href='?session=' + this.value" class="bg-slate-100 border-none rounded-xl px-4 py-2.5 text-sm font-bold text-slate-700 outline-none cursor-pointer">
                    <?php foreach($sessions as $s): ?>
                        <option value="<?= $s['id'] ?>" <?= $current_sid == $s['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($s['name']) ?> (<?= $s['date'] ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </header>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
            <div class="bg-white p-6 rounded-[2rem] border border-slate-200 shadow-sm">
                <p class="text-slate-400 text-[10px] font-bold uppercase tracking-widest mb-1">Athletes</p>
                <h4 class="text-3xl font-bold text-slate-900"><?= $total_members ?></h4>
            </div>
            <div class="bg-emerald-500 p-6 rounded-[2rem] shadow-lg shadow-emerald-200 text-white">
                <p class="opacity-80 text-[10px] font-bold uppercase tracking-widest mb-1">Present</p>
                <h4 class="text-3xl font-bold"><?= $total_present ?></h4>
            </div>
            <div class="bg-white p-6 rounded-[2rem] border border-slate-200 shadow-sm">
                <p class="text-slate-400 text-[10px] font-bold uppercase tracking-widest mb-1">Absentees</p>
                <h4 class="text-3xl font-bold text-red-500"><?= count($absent_members) ?></h4>
            </div>
            <div class="bg-indigo-600 p-6 rounded-[2rem] shadow-lg shadow-indigo-200 text-white">
                <p class="opacity-80 text-[10px] font-bold uppercase tracking-widest mb-1">Retention</p>
                <h4 class="text-3xl font-bold"><?= $attendance_rate ?>%</h4>
            </div>
        </div>

        <div class="space-y-6">
            <div id="view-registry" class="panel">
                <div class="bg-white rounded-[2.5rem] border border-slate-200 shadow-sm overflow-hidden">
                    <div class="p-6 border-b border-slate-100">
                        <div class="relative w-full md:w-96">
                            <input type="text" id="searchInput" onkeyup="searchAthletes()" placeholder="Search athletes..." class="w-full bg-slate-50 border border-slate-200 rounded-xl py-3 pl-10 pr-4 text-sm outline-none">
                            <svg class="w-4 h-4 absolute left-3.5 top-3.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                        </div>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left">
                            <thead class="bg-slate-50 text-slate-500 text-[10px] font-bold uppercase tracking-wider">
                                <tr><th class="px-8 py-4">Athlete</th><th class="px-8 py-4 text-center">Attendance</th><th class="px-8 py-4 text-right">Actions</th></tr>
                            </thead>
                            <tbody id="athlete-rows" class="divide-y divide-slate-50">
                                <?php foreach($members as $m): ?>
                                <tr class="athlete-row hover:bg-slate-50/80">
                                    <td class="px-8 py-5">
                                        <div class="font-bold text-slate-800 athlete-name"><?= htmlspecialchars($m['full_name']) ?></div>
                                        <div class="mt-1">
                                            <?php if($m['is_overdue']): ?>
                                                <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-red-100 text-red-600 animate-pulse-red uppercase">Overdue</span>
                                            <?php elseif($m['status'] === 'paid'): ?>
                                                <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-emerald-100 text-emerald-600 uppercase">Paid</span>
                                            <?php else: ?>
                                                <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-slate-100 text-slate-400 uppercase">Pending</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="px-8 py-5 text-center">
                                        <?php if(in_array($m['id'], $attended_ids)): ?>
                                            <a href="?action=unmark&mid=<?= $m['id'] ?>&sid=<?= $current_sid ?>" class="bg-emerald-500 text-white px-4 py-1 rounded-full text-[10px] font-bold uppercase tracking-tight">Present</a>
                                        <?php else: ?>
                                            <form method="POST" class="inline">
                                                <input type="hidden" name="sid" value="<?= $current_sid ?>">
                                                <input type="hidden" name="mid" value="<?= $m['id'] ?>">
                                                <button name="mark" class="text-indigo-600 font-bold text-xs hover:underline">Mark Presence</button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-8 py-5 text-right space-x-3">
                                        <button onclick="openPaymentModal(<?= $m['id'] ?>, '<?= addslashes($m['full_name']) ?>')" class="text-slate-400 hover:text-slate-900 font-bold text-xs">Due</button>
                                        <button onclick="editM('<?= $m['id'] ?>','<?= addslashes($m['full_name']) ?>')" class="text-indigo-500 hover:text-indigo-700 font-bold text-xs">Edit</button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div id="view-sessions" class="panel hidden">
                <div class="bg-white rounded-[2.5rem] p-8 border border-slate-200">
                    <form method="POST" class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-8">
                        <input name="s_name" placeholder="Session Name" class="p-4 bg-slate-50 rounded-2xl border outline-none" required>
                        <input type="date" name="s_date" value="<?= date('Y-m-d') ?>" class="p-4 bg-slate-50 rounded-2xl border outline-none">
                        <button name="save_session" class="bg-indigo-600 text-white rounded-2xl font-bold">New Session</button>
                    </form>
                    <?php foreach($sessions as $s): ?>
                        <div class="flex justify-between items-center p-4 border-b">
                            <span class="font-bold"><?= htmlspecialchars($s['name']) ?> (<?= $s['date'] ?>)</span>
                            <a href="?action=del_s&id=<?= $s['id'] ?>" class="text-red-500 text-xs font-bold uppercase">Delete</a>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div id="view-export" class="panel hidden">
                <div class="bg-slate-900 rounded-[2.5rem] p-10 text-white relative overflow-hidden">
                    <h3 class="text-2xl font-bold mb-4">Export Data</h3>
                    <form method="GET" class="flex flex-col md:flex-row gap-4 relative z-10">
                        <input type="date" name="export_date" value="<?= date('Y-m-d') ?>" class="bg-slate-800 rounded-2xl p-4 outline-none">
                        <button type="submit" name="export_type" value="all" class="bg-indigo-500 px-8 py-4 rounded-2xl font-bold uppercase tracking-widest">Generate CSV</button>
                    </form>
                    <div class="absolute -right-10 -bottom-10 w-40 h-40 bg-indigo-500/10 rounded-full blur-3xl"></div>
                </div>
            </div>
        </div>
    </main>
</div>

<div id="pay-modal" class="hidden fixed inset-0 bg-slate-900/80 backdrop-blur-sm flex items-center justify-center p-4 z-50">
    <div class="bg-white rounded-[2.5rem] p-10 w-full max-w-md shadow-2xl">
        <h3 class="text-xl font-bold mb-1">Update Due</h3>
        <p id="pay-athlete-name" class="text-indigo-600 font-bold mb-6"></p>
        <form method="POST" class="space-y-4">
            <input type="hidden" name="mid" id="pay_mid">
            <input type="number" name="amount" placeholder="Amount ($)" class="w-full p-4 bg-slate-50 rounded-2xl border outline-none" required>
            <input type="date" name="due_date" class="w-full p-4 bg-slate-50 rounded-2xl border outline-none" required>
            <button name="set_payment" class="w-full bg-indigo-600 text-white py-4 rounded-2xl font-bold shadow-lg">Save Deadline</button>
            <button type="button" onclick="closeModal()" class="w-full text-slate-400 font-bold text-xs uppercase text-center mt-2">Cancel</button>
        </form>
    </div>
</div>

<script>
    function toggleView(v) {
        document.querySelectorAll('.panel').forEach(p => p.classList.add('hidden'));
        document.getElementById('view-' + v).classList.remove('hidden');
        document.querySelectorAll('nav button').forEach(b => b.classList.remove('sidebar-item-active'));
        document.getElementById('btn-' + v).classList.add('sidebar-item-active');
    }

    function searchAthletes() {
        const input = document.getElementById("searchInput").value.toLowerCase().trim();
        const rows = document.querySelectorAll("#athlete-rows .athlete-row");
        rows.forEach(row => {
            const name = row.querySelector(".athlete-name").innerText.toLowerCase();
            row.style.display = name.includes(input) ? "" : "none";
        });
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
        document.getElementById('m-title').innerText = "Edit Athlete Profile";
    }
</script>
</body>
</html>
