<?php
/**
 * UJAMAA ACADEMY - ENTERPRISE EDITION V4
 * PRO FEATURES: Export Engine, View Toggling, Full CRUD, Finance & Attendance
 */

// 1. EXPORT ENGINE (Must be at the very top)
if (isset($_GET['export_type'])) {
    $databaseUrl = getenv("DATABASE_URL");
    $url = parse_url($databaseUrl);
    $dsn = "pgsql:host={$url['host']};port=" . ($url['port'] ?? 5432) . ";dbname=" . ltrim($url['path'], '/') . ";sslmode=require";
    
    try {
        $pdo = new PDO($dsn, $url['user'], $url['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $type = $_GET['export_type'];
        $date = $_GET['export_date'] ?? date('Y-m-d');
        
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=Ujamaa_Report_' . $date . '.csv');
        $output = fopen('php://output', 'w');
        
        if ($type === 'daily') {
            fputcsv($output, ['Date', 'Session', 'Athlete Name', 'Status']);
            $stmt = $pdo->prepare("SELECT s.date, s.name as sname, m.full_name, CASE WHEN a.id IS NOT NULL THEN 'PRESENT' ELSE 'ABSENT' END as status 
                                   FROM members m CROSS JOIN sessions s LEFT JOIN attendance a ON a.member_id = m.id AND a.session_id = s.id 
                                   WHERE s.date = ? ORDER BY s.id, m.full_name");
            $stmt->execute([$date]);
        } else {
            fputcsv($output, ['Athlete Name', 'Session', 'Date', 'Status']);
            $stmt = $pdo->prepare("SELECT m.full_name, s.name, s.date, CASE WHEN a.id IS NOT NULL THEN 'PRESENT' ELSE 'ABSENT' END as status 
                                   FROM members m CROSS JOIN sessions s LEFT JOIN attendance a ON a.member_id = m.id AND a.session_id = s.id 
                                   WHERE s.id = ?");
            $stmt->execute([$_GET['sid']]);
        }
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) fputcsv($output, $row);
        fclose($output);
        exit; // Stop execution so no HTML is appended to the CSV
    } catch (Exception $e) { die("Export Failed: " . $e->getMessage()); }
}

// 2. MAIN LOGIC & DATABASE
$databaseUrl = getenv("DATABASE_URL");
$url = parse_url($databaseUrl);
$dsn = "pgsql:host={$url['host']};port=" . ($url['port'] ?? 5432) . ";dbname=" . ltrim($url['path'], '/') . ";sslmode=require";

try {
    $pdo = new PDO($dsn, $url['user'], $url['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

    // POST HANDLERS (CRUD)
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
        if (isset($_POST['mark'])) $pdo->prepare("INSERT INTO attendance(session_id, member_id) VALUES (?, ?)")->execute([$_POST['sid'], $_POST['mid']]);
        header("Location: " . $_SERVER['REQUEST_URI']); exit;
    }

    // ACTIONS (DELETE/UNDO/PAID)
    if (isset($_GET['action'])) {
        if ($_GET['action'] === 'del_m') $pdo->prepare("DELETE FROM members WHERE id = ?")->execute([$_GET['id']]);
        if ($_GET['action'] === 'del_s') $pdo->prepare("DELETE FROM sessions WHERE id = ?")->execute([$_GET['id']]);
        if ($_GET['action'] === 'unmark') $pdo->prepare("DELETE FROM attendance WHERE member_id = ? AND session_id = ?")->execute([$_GET['mid'], $_GET['sid']]);
        header("Location: index.php"); exit;
    }

    // DATA LOADING
    $sessions = $pdo->query("SELECT * FROM sessions ORDER BY date DESC, id DESC")->fetchAll();
    $current_sid = $_GET['session'] ?? ($sessions[0]['id'] ?? null);
    $members = $pdo->query("SELECT * FROM members ORDER BY full_name ASC")->fetchAll();
    $attended_ids = $current_sid ? $pdo->prepare("SELECT member_id FROM attendance WHERE session_id = ?"); 
    if($current_sid) { $attended_ids->execute([$current_sid]); $attended_ids = $attended_ids->fetchAll(PDO::FETCH_COLUMN); } else { $attended_ids = []; }

} catch (Exception $e) { die("System Error: " . $e->getMessage()); }
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ujamaa Academy | Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; background: #f8fafc; }
        .tab-active { background: #4f46e5 !important; color: white !important; }
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
                <button onclick="toggleView('training')" id="btn-training" class="w-full text-left px-6 py-4 rounded-2xl font-bold transition-all tab-active">Training Registry</button>
                <button onclick="toggleView('sessions')" id="btn-sessions" class="w-full text-left px-6 py-4 rounded-2xl font-bold transition-all text-slate-400 hover:text-white">Session Manager</button>
                <button onclick="toggleView('export')" id="btn-export" class="w-full text-left px-6 py-4 rounded-2xl font-bold transition-all text-slate-400 hover:text-white">Reports & Export</button>
            </nav>
        </div>

        <div class="bg-white rounded-[2.5rem] p-8 border border-slate-100 shadow-sm">
            <h3 class="font-800 text-lg mb-4" id="m-title">Add Athlete</h3>
            <form method="POST" class="space-y-3">
                <input type="hidden" name="member_id" id="m_id">
                <input name="name" id="m_name" placeholder="Full Name" class="w-full p-4 bg-slate-50 rounded-2xl border-none text-sm ring-1 ring-slate-100 outline-none focus:ring-indigo-500" required>
                <button name="save_member" class="w-full bg-indigo-600 text-white py-4 rounded-2xl font-bold shadow-lg shadow-indigo-50">Save Athlete</button>
            </form>
        </div>
    </div>

    <!-- MAIN PANELS -->
    <div class="lg:col-span-8">
        
        <!-- TRAINING PANEL -->
        <div id="view-training" class="panel space-y-6">
            <div class="bg-white rounded-[2.5rem] p-8 border border-slate-100 shadow-sm">
                <div class="flex justify-between items-center mb-8">
                    <h2 class="text-2xl font-800">Registry</h2>
                    <select onchange="window.location.href='?session='+this.value" class="bg-slate-50 border-none rounded-xl px-4 py-2 text-sm font-bold ring-1 ring-slate-100">
                        <?php foreach($sessions as $s): ?>
                            <option value="<?= $s['id'] ?>" <?= $current_sid == $s['id'] ? 'selected' : '' ?>><?= htmlspecialchars($s['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <input onkeyup="filter(this.value)" placeholder="Search athletes..." class="w-full p-4 mb-6 bg-slate-50 rounded-2xl border-none text-sm ring-1 ring-slate-100 outline-none">
                <div class="overflow-x-auto"><table class="w-full"><tbody id="rows">
                    <?php foreach($members as $m): ?>
                    <tr class="border-b border-slate-50 hover:bg-slate-50/50" data-name="<?= strtolower($m['full_name']) ?>">
                        <td class="py-4 font-bold text-slate-700"><?= htmlspecialchars($m['full_name']) ?></td>
                        <td class="py-4 text-right space-x-3">
                            <?php if(in_array($m['id'], $attended_ids)): ?>
                                <span class="text-[10px] font-900 bg-emerald-100 text-emerald-600 px-3 py-1 rounded-full uppercase">Present</span>
                                <a href="?action=unmark&mid=<?= $m['id'] ?>&sid=<?= $current_sid ?>" class="text-xs text-slate-300 hover:text-red-500">Undo</a>
                            <?php else: ?>
                                <form method="POST" class="inline">
                                    <input type="hidden" name="sid" value="<?= $current_sid ?>"><input type="hidden" name="mid" value="<?= $m['id'] ?>">
                                    <button name="mark" class="text-indigo-600 font-bold text-xs">Mark Presence</button>
                                </form>
                            <?php endif; ?>
                            <button onclick="editM('<?= $m['id'] ?>','<?= addslashes($m['full_name']) ?>')" class="text-slate-300 hover:text-indigo-500">Edit</button>
                            <a href="?action=del_m&id=<?= $m['id'] ?>" onclick="return confirm('Delete?')" class="text-slate-300 hover:text-red-500">Delete</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody></table></div>
            </div>
        </div>

        <!-- SESSION PANEL -->
        <div id="view-sessions" class="panel hidden space-y-6">
            <div class="bg-white rounded-[2.5rem] p-8 border border-slate-100 shadow-sm">
                <h2 class="text-2xl font-800 mb-6">New Training Session</h2>
                <form method="POST" class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-10">
                    <input name="s_name" placeholder="Morning Drill" class="p-4 bg-slate-50 rounded-2xl border-none text-sm ring-1 ring-slate-100 outline-none" required>
                    <input type="date" name="s_date" value="<?= date('Y-m-d') ?>" class="p-4 bg-slate-50 rounded-2xl border-none text-sm ring-1 ring-slate-100 outline-none">
                    <button name="save_session" class="bg-slate-900 text-white rounded-2xl font-bold">Create Session</button>
                </form>
                <div class="overflow-x-auto"><table class="w-full text-left">
                    <thead><tr class="text-[10px] text-slate-400 uppercase font-bold tracking-widest"><th class="pb-4">Name</th><th class="pb-4">Date</th><th class="pb-4 text-right">Action</th></tr></thead>
                    <tbody>
                        <?php foreach($sessions as $s): ?>
                        <tr class="border-b border-slate-50"><td class="py-4 font-bold"><?= $s['name'] ?></td><td class="py-4 text-sm"><?= $s['date'] ?></td>
                        <td class="py-4 text-right"><a href="?action=del_s&id=<?= $s['id'] ?>" class="text-red-400 font-bold text-xs">Delete</a></td></tr>
                        <?php endforeach; ?>
                    </tbody>
                </table></div>
            </div>
        </div>

        <!-- EXPORT PANEL -->
        <div id="view-export" class="panel hidden space-y-6">
            <div class="bg-white rounded-[2.5rem] p-8 border border-slate-100 shadow-sm">
                <h2 class="text-2xl font-800 mb-2">Generate Reports</h2>
                <p class="text-slate-400 text-sm mb-8">Export your academy data to Excel (CSV format).</p>
                <form method="GET" class="p-6 bg-slate-50 rounded-[2rem] flex flex-col md:flex-row gap-4 items-center">
                    <div class="flex-1 w-full"><label class="text-[10px] font-bold uppercase text-slate-400 ml-2">Select Date</label>
                    <input type="date" name="export_date" value="<?= date('Y-m-d') ?>" class="w-full p-4 bg-white rounded-2xl border-none mt-1 outline-none ring-1 ring-slate-100"></div>
                    <button type="submit" name="export_type" value="daily" class="w-full md:w-auto bg-indigo-600 text-white px-10 py-4 rounded-2xl font-bold shadow-lg shadow-indigo-100">Download Report</button>
                </form>
            </div>
        </div>

    </div>
</div>

<script>
    function toggleView(v) {
        document.querySelectorAll('.panel').forEach(p => p.classList.add('hidden'));
        document.getElementById('view-' + v).classList.remove('hidden');
        document.querySelectorAll('nav button').forEach(b => b.classList.remove('tab-active', 'text-white'));
        document.getElementById('btn-' + v).classList.add('tab-active', 'text-white');
    }
    function filter(q) {
        q = q.toLowerCase();
        document.querySelectorAll('#rows tr').forEach(r => { r.style.display = r.getAttribute('data-name').includes(q) ? '' : 'none'; });
    }
    function editM(id, name) {
        document.getElementById('m_id').value = id;
        document.getElementById('m_name').value = name;
        document.getElementById('m-title').innerText = "Edit Athlete";
    }
</script>
</body>
</html>
