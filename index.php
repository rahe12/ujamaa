<?php
/**
 * UJAMAA ACADEMY - ENTERPRISE EDITION
 * Features: Advanced Reporting, CRUD, Analytics
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);

$databaseUrl = getenv("DATABASE_URL");
if (!$databaseUrl) die("Database configuration missing.");

$url = parse_url($databaseUrl);
$dsn = "pgsql:host={$url['host']};port=" . ($url['port'] ?? 5432) . ";dbname=" . ltrim($url['path'], '/') . ";sslmode=require";

try {
    $pdo = new PDO($dsn, $url['user'], $url['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

    // --- ADMINISTRATIVE ACTIONS (DELETE/EDIT) ---
    if (isset($_GET['action'])) {
        if ($_GET['action'] === 'del_member' && isset($_GET['id'])) {
            $pdo->prepare("DELETE FROM attendance WHERE member_id = ?")->execute([$_GET['id']]);
            $pdo->prepare("DELETE FROM members WHERE id = ?")->execute([$_GET['id']]);
        }
        if ($_GET['action'] === 'unmark' && isset($_GET['mid']) && isset($_GET['sid'])) {
            $pdo->prepare("DELETE FROM attendance WHERE member_id = ? AND session_id = ?")->execute([$_GET['mid'], $_GET['sid']]);
        }
        header("Location: " . strtok($_SERVER["REQUEST_URI"], '?') . (isset($_GET['session']) ? "?session=".$_GET['session'] : ""));
        exit;
    }

    // --- EXPORT ENGINE ---
    if (isset($_GET['export_type'])) {
        $type = $_GET['export_type'];
        $date = $_GET['export_date'] ?? date('Y-m-d');
        $filename = "Ujamaa_Report_{$type}_{$date}.csv";

        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $out = fopen('php://output', 'w');

        if ($type === 'daily') {
            fputcsv($out, ['Report Date', 'Session Name', 'Athlete Name', 'Status']);
            $stmt = $pdo->prepare("SELECT s.date, s.name as sname, m.full_name, CASE WHEN a.id IS NOT NULL THEN 'PRESENT' ELSE 'ABSENT' END as status 
                                   FROM members m CROSS JOIN sessions s LEFT JOIN attendance a ON a.member_id = m.id AND a.session_id = s.id 
                                   WHERE s.date = ? ORDER BY s.id, m.full_name");
            $stmt->execute([$date]);
        } else {
            fputcsv($out, ['Athlete Name', 'Session', 'Date', 'Status']);
            $stmt = $pdo->prepare("SELECT m.full_name, s.name, s.date, CASE WHEN a.id IS NOT NULL THEN 'PRESENT' ELSE 'ABSENT' END as status 
                                   FROM members m CROSS JOIN sessions s LEFT JOIN attendance a ON a.member_id = m.id AND a.session_id = s.id 
                                   WHERE s.id = ?");
            $stmt->execute([$_GET['export_sid']]);
        }
        while ($row = $stmt->fetch()) fputcsv($out, $row);
        fclose($out); exit;
    }

    // --- POST HANDLERS ---
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['add_member'])) $pdo->prepare("INSERT INTO members(full_name) VALUES (?) ON CONFLICT DO NOTHING")->execute([trim($_POST['name'])]);
        if (isset($_POST['create_session'])) $pdo->prepare("INSERT INTO sessions(name, date) VALUES (?, ?)")->execute([trim($_POST['session']), $_POST['sdate']]);
        if (isset($_POST['mark'])) $pdo->prepare("INSERT INTO attendance(session_id, member_id) VALUES (?, ?)")->execute([$_POST['session_id'], $_POST['member_id']]);
        header("Location: " . $_SERVER['REQUEST_URI']); exit;
    }

    // --- DATA LOADING ---
    $sessions = $pdo->query("SELECT * FROM sessions ORDER BY date DESC, id DESC")->fetchAll();
    $current_sid = $_GET['session'] ?? ($sessions[0]['id'] ?? null);
    $members = $pdo->query("SELECT * FROM members ORDER BY full_name ASC")->fetchAll();
    
    $attended_ids = [];
    if ($current_sid) {
        $stmt = $pdo->prepare("SELECT member_id FROM attendance WHERE session_id = ?");
        $stmt->execute([$current_sid]);
        $attended_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    // --- STATS CALCULATION ---
    $total_m = count($members);
    $present_m = count($attended_ids);
    $perc = $total_m > 0 ? round(($present_m / $total_m) * 100) : 0;

} catch (Exception $e) { die("System Error: " . $e->getMessage()); }
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ujamaa Academy | Pro Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>body { font-family: 'Plus Jakarta Sans', sans-serif; }</style>
</head>
<body class="bg-[#fcfdfe] p-4 lg:p-10 text-slate-900">

<div class="max-w-7xl mx-auto grid grid-cols-1 lg:grid-cols-12 gap-8">
    
    <!-- LEFT SIDEBAR: DASHBOARD & CONTROLS -->
    <div class="lg:col-span-4 space-y-6">
        <div class="bg-indigo-700 rounded-[2rem] p-8 text-white shadow-2xl shadow-indigo-200 relative overflow-hidden">
            <div class="relative z-10">
                <h1 class="text-3xl font-800">UJAMAA</h1>
                <p class="text-indigo-200 text-sm mb-8">Performance & Attendance</p>
                
                <div class="grid grid-cols-2 gap-4">
                    <div class="bg-white/10 p-4 rounded-2xl">
                        <p class="text-[10px] uppercase font-bold text-indigo-200">Attendance</p>
                        <p class="text-2xl font-800"><?= $perc ?>%</p>
                    </div>
                    <div class="bg-white/10 p-4 rounded-2xl">
                        <p class="text-[10px] uppercase font-bold text-indigo-200">Athletes</p>
                        <p class="text-2xl font-800"><?= $total_m ?></p>
                    </div>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-[2rem] p-8 border border-slate-100 shadow-sm">
            <h3 class="font-800 text-lg mb-6">Reporting Center</h3>
            <form action="" method="GET" class="space-y-4">
                <div>
                    <label class="text-xs font-bold text-slate-400 uppercase">Daily Master Report</label>
                    <div class="flex gap-2 mt-2">
                        <input type="date" name="export_date" value="<?= date('Y-m-d') ?>" class="flex-1 bg-slate-50 border-none rounded-xl text-sm p-3">
                        <button name="export_type" value="daily" class="bg-slate-900 text-white px-4 rounded-xl text-xs font-bold">Get</button>
                    </div>
                </div>
            </form>
        </div>

        <div class="bg-white rounded-[2rem] p-8 border border-slate-100 shadow-sm">
            <h3 class="font-800 text-lg mb-4">Quick Add</h3>
            <form method="POST" class="space-y-3">
                <input name="name" placeholder="Athlete Name" class="w-full p-4 bg-slate-50 rounded-2xl border-none text-sm outline-none ring-1 ring-slate-100 focus:ring-indigo-500" required>
                <button name="add_member" class="w-full bg-indigo-600 text-white py-4 rounded-2xl font-bold shadow-lg shadow-indigo-100 hover:bg-indigo-700 transition">Register Athlete</button>
            </form>
        </div>
    </div>

    <!-- MAIN PANEL: REGISTRY -->
    <div class="lg:col-span-8 space-y-6">
        <div class="bg-white rounded-[2rem] p-8 border border-slate-100 shadow-sm">
            <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4 mb-10">
                <div>
                    <h2 class="text-2xl font-800">Registry</h2>
                    <p class="text-slate-400 text-sm">Session Management</p>
                </div>
                <div class="flex gap-2">
                    <select onchange="window.location.href='?session='+this.value" class="bg-slate-50 border-none rounded-xl px-5 py-3 text-sm font-bold text-slate-700 ring-1 ring-slate-100">
                        <?php foreach($sessions as $s): ?>
                            <option value="<?= $s['id'] ?>" <?= $current_sid == $s['id'] ? 'selected' : '' ?>><?= htmlspecialchars($s['name']) ?> (<?= $s['date'] ?>)</option>
                        <?php endforeach; ?>
                    </select>
                    <a href="?export_type=session&export_sid=<?= $current_sid ?>" class="bg-emerald-500 text-white px-5 py-3 rounded-xl text-xs font-bold flex items-center">Export</a>
                </div>
            </div>

            <input id="qSearch" onkeyup="doSearch()" placeholder="Search athletes..." class="w-full p-4 mb-6 bg-slate-50 border-none rounded-2xl text-sm ring-1 ring-slate-100 focus:ring-indigo-500 outline-none">

            <div class="overflow-x-auto">
                <table class="w-full text-left">
                    <thead>
                        <tr class="text-[10px] text-slate-400 uppercase font-800 tracking-widest border-b border-slate-50">
                            <th class="pb-4">Athlete Name</th>
                            <th class="pb-4">Status</th>
                            <th class="pb-4 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="aTable">
                        <?php foreach($members as $m): ?>
                        <tr class="t-row border-b border-slate-50/50 hover:bg-slate-50/50 transition" data-name="<?= strtolower(htmlspecialchars($m['full_name'])) ?>">
                            <td class="py-5 font-bold text-slate-700"><?= htmlspecialchars($m['full_name']) ?></td>
                            <td class="py-5">
                                <?php if(in_array($m['id'], $attended_ids)): ?>
                                    <span class="text-[10px] font-900 bg-emerald-100 text-emerald-600 px-3 py-1 rounded-full">PRESENT</span>
                                    <a href="?action=unmark&mid=<?= $m['id'] ?>&sid=<?= $current_sid ?>" class="text-[10px] text-slate-300 ml-2 hover:text-red-400 underline">Undo</a>
                                <?php else: ?>
                                    <span class="text-[10px] font-900 bg-slate-100 text-slate-400 px-3 py-1 rounded-full">ABSENT</span>
                                <?php endif; ?>
                            </td>
                            <td class="py-5 text-right">
                                <?php if(!in_array($m['id'], $attended_ids)): ?>
                                <form method="POST" class="inline">
                                    <input type="hidden" name="member_id" value="<?= $m['id'] ?>">
                                    <input type="hidden" name="session_id" value="<?= $current_sid ?>">
                                    <button name="mark" class="text-indigo-600 font-bold text-xs hover:bg-indigo-50 px-3 py-2 rounded-lg transition">Mark Present</button>
                                </form>
                                <?php endif; ?>
                                <a href="?action=del_member&id=<?= $m['id'] ?>" onclick="return confirm('Delete athlete?')" class="text-slate-300 hover:text-red-500 ml-4"><svg class="w-4 h-4 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg></a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- NEW SESSION FORM -->
        <div class="bg-white rounded-[2rem] p-8 border border-slate-100 shadow-sm">
            <h3 class="font-800 text-lg mb-4">Create Training Session</h3>
            <form method="POST" class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <input name="session" placeholder="Session Title" class="col-span-1 md:col-span-1 p-4 bg-slate-50 rounded-2xl border-none text-sm ring-1 ring-slate-100 outline-none" required>
                <input type="date" name="sdate" value="<?= date('Y-m-d') ?>" class="p-4 bg-slate-50 rounded-2xl border-none text-sm ring-1 ring-slate-100 outline-none">
                <button name="create_session" class="bg-slate-900 text-white rounded-2xl font-bold">Open Session</button>
            </form>
        </div>
    </div>
</div>

<script>
    function doSearch() {
        const q = document.getElementById('qSearch').value.toLowerCase();
        document.querySelectorAll('.t-row').forEach(row => {
            row.style.display = row.getAttribute('data-name').includes(q) ? '' : 'none';
        });
    }
</script>
</body>
</html>
