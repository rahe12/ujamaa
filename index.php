<?php  
/** * UJAMAA ACADEMY - ENTERPRISE EDITION V5.2  
 * Feature Set: Full/Present/Absent Exports, Athlete Management, & Summaries
 */  

error_reporting(E_ALL);
ini_set('display_errors', 1);

// 1. DATABASE CONNECTION
function get_db_connection() {
    $databaseUrl = getenv("DATABASE_URL");  
    $url = parse_url($databaseUrl);  
    $dsn = "pgsql:host={$url['host']};port=" . ($url['port'] ?? 5432) . ";dbname=" . ltrim($url['path'], '/') . ";sslmode=require";  
    return new PDO($dsn, $url['user'], $url['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
}

// 2. ADVANCED EXPORT ENGINE (Full, Present, or Absent)
if (isset($_GET['export_type'])) {  
    try {  
        $pdo = get_db_connection();
        $date = $_GET['export_date'] ?? date('Y-m-d');  
        $type = $_GET['export_type']; // 'all', 'present', or 'absent'

        $filename = "Ujamaa_" . ucfirst($type) . "_Report_" . $date . ".csv";
        $filter = "";
        if ($type === 'present') $filter = "AND a.id IS NOT NULL";
        if ($type === 'absent') $filter = "AND a.id IS NULL";

        header('Content-Type: text/csv; charset=utf-8');  
        header('Content-Disposition: attachment; filename=' . $filename);  
  
        $output = fopen('php://output', 'w');  
        fputcsv($output, ['Athlete Name', 'Attendance Status', 'Payment Status', 'Due Date']);  
  
        $stmt = $pdo->prepare("  
            SELECT m.full_name,  
            CASE WHEN a.id IS NOT NULL THEN 'PRESENT' ELSE 'ABSENT' END as att,  
            COALESCE(p.status, 'No Record') as pay,  
            p.due_date  
            FROM members m  
            CROSS JOIN sessions s  
            LEFT JOIN attendance a ON a.member_id = m.id AND a.session_id = s.id  
            LEFT JOIN payments p ON p.member_id = m.id  
            WHERE s.date = ? $filter
            ORDER BY m.full_name ASC
        ");  
        $stmt->execute([$date]);  
  
        while ($row = $stmt->fetch()) { fputcsv($output, $row); }  
        exit;  
    } catch (Exception $e) { die("Export Error: " . $e->getMessage()); }  
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
        header("Location: index.php" . (isset($_POST['sid']) ? "?session=" . $_POST['sid'] : ""));  
        exit;  
    }  

    // ACTION HANDLERS (Delete Athlete, Delete Session, Unmark Attendance)
    if (isset($_GET['action'])) {  
        if ($_GET['action'] === 'del_m') $pdo->prepare("DELETE FROM members WHERE id = ?")->execute([$_GET['id']]);  
        if ($_GET['action'] === 'del_s') $pdo->prepare("DELETE FROM sessions WHERE id = ?")->execute([$_GET['id']]);  
        if ($_GET['action'] === 'unmark') $pdo->prepare("DELETE FROM attendance WHERE member_id = ? AND session_id = ?")->execute([$_GET['mid'], $_GET['sid']]);  
        header("Location: index.php"); exit;  
    }  

    $members = $pdo->query("SELECT m.*, p.status, p.due_date FROM members m LEFT JOIN payments p ON m.id = p.member_id ORDER BY m.full_name ASC")->fetchAll();  
    $sessions = $pdo->query("SELECT * FROM sessions ORDER BY date DESC, id DESC")->fetchAll();  
    $current_sid = $_GET['session'] ?? ($sessions[0]['id'] ?? null);  

    $attended_ids = [];
    if ($current_sid) {
        $stmt = $pdo->prepare("SELECT member_id FROM attendance WHERE session_id = ?");
        $stmt->execute([$current_sid]);
        $attended_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    $present_members = []; $absent_members = [];  
    foreach ($members as $m) { if (in_array($m['id'], $attended_ids)) $present_members[] = $m; else $absent_members[] = $m; }  

} catch (Exception $e) { die("System Error: " . $e->getMessage()); }  
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ujamaa Academy | Management v5.2</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f8fafc; }
        .sidebar-item-active { background: #4f46e5; color: white; }
    </style>
</head>
<body class="antialiased text-slate-900">

<div class="flex flex-col lg:flex-row min-h-screen">
    
    <aside class="w-full lg:w-72 bg-slate-900 p-6 flex flex-col">
        <div class="flex items-center gap-3 mb-10 text-white">
            <div class="w-10 h-10 bg-indigo-500 rounded-xl flex items-center justify-center font-black">U</div>
            <div>
                <h1 class="font-bold text-xl uppercase tracking-tighter">UJAMAA</h1>
                <p class="text-slate-500 text-[10px] font-bold uppercase tracking-widest">Enterprise Hub</p>
            </div>
        </div>

        <nav class="space-y-1 flex-1">
            <button onclick="toggleView('registry')" id="btn-registry" class="w-full text-left px-4 py-3 rounded-xl font-semibold transition-all sidebar-item-active">Registry</button>
            <button onclick="toggleView('sessions')" id="btn-sessions" class="w-full text-left px-4 py-3 rounded-xl font-semibold transition-all text-slate-400 hover:bg-slate-800 hover:text-white">Sessions</button>
            <button onclick="toggleView('export')" id="btn-export" class="w-full text-left px-4 py-3 rounded-xl font-semibold transition-all text-slate-400 hover:bg-slate-800 hover:text-white">Intelligence Reports</button>
        </nav>

        <div class="mt-10 bg-slate-800/50 rounded-2xl p-5 border border-slate-700/50">
            <h3 class="text-white text-xs font-bold uppercase mb-4" id="m-title">Athlete Management</h3>
            <form method="POST" class="space-y-3">
                <input type="hidden" name="member_id" id="m_id">
                <input name="name" id="m_name" placeholder="Full Name" class="w-full bg-slate-900 border border-slate-700 rounded-lg p-3 text-sm text-white outline-none focus:ring-1 focus:ring-indigo-500" required>
                <button name="save_member" class="w-full bg-indigo-600 text-white py-2.5 rounded-lg font-bold text-sm">Save Athlete</button>
            </form>
        </div>
    </aside>

    <main class="flex-1 p-6 lg:p-10 overflow-y-auto">
        <header class="mb-10 flex flex-col md:flex-row md:items-center justify-between gap-6">
            <h2 class="text-3xl font-bold text-slate-900">Ujamaa Dashboard</h2>
            <div class="bg-white p-2 rounded-2xl shadow-sm border border-slate-200">
                <select onchange="window.location.href='?session=' + this.value" class="bg-slate-100 rounded-xl px-4 py-2.5 text-sm font-bold text-slate-700 outline-none">
                    <?php foreach($sessions as $s): ?>
                        <option value="<?= $s['id'] ?>" <?= $current_sid == $s['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($s['name']) ?> (<?= $s['date'] ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </header>

        <div id="view-registry" class="panel">
            <div class="bg-white rounded-[2.5rem] border border-slate-200 shadow-sm overflow-hidden mb-10">
                <div class="p-6 border-b border-slate-100 flex justify-between items-center">
                    <input type="text" id="searchInput" onkeyup="searchAthletes()" placeholder="Search athletes..." class="w-full md:w-96 bg-slate-50 border rounded-xl py-3 px-4 text-sm outline-none">
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left">
                        <thead class="bg-slate-50 text-slate-500 text-[10px] font-bold uppercase tracking-wider">
                            <tr>
                                <th class="px-8 py-4">Athlete</th>
                                <th class="px-8 py-4 text-center">Status</th>
                                <th class="px-8 py-4 text-right">Control</th>
                            </tr>
                        </thead>
                        <tbody id="athlete-rows" class="divide-y divide-slate-50">
                            <?php foreach($members as $m): ?>
                            <tr class="athlete-row hover:bg-slate-50">
                                <td class="px-8 py-5 font-bold text-slate-800 athlete-name"><?= htmlspecialchars($m['full_name']) ?></td>
                                <td class="px-8 py-5 text-center">
                                    <?php if(in_array($m['id'], $attended_ids)): ?>
                                        <a href="?action=unmark&mid=<?= $m['id'] ?>&sid=<?= $current_sid ?>" class="bg-emerald-500 text-white px-4 py-1.5 rounded-full text-[10px] font-bold uppercase">Present</a>
                                    <?php else: ?>
                                        <form method="POST" class="inline">
                                            <input type="hidden" name="sid" value="<?= $current_sid ?>">
                                            <input type="hidden" name="mid" value="<?= $m['id'] ?>">
                                            <button name="mark" class="text-indigo-600 font-bold text-xs">Mark Presence</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                                <td class="px-8 py-5 text-right space-x-4">
                                    <button onclick="editM('<?= $m['id'] ?>','<?= addslashes($m['full_name']) ?>')" class="text-indigo-500 font-bold text-xs">Edit</button>
                                    <a href="?action=del_m&id=<?= $m['id'] ?>" onclick="return confirm('Confirm deletion?')" class="text-red-400 font-bold text-xs">Delete</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                <div class="bg-emerald-50 rounded-[2rem] p-8 border border-emerald-100 shadow-sm">
                    <h3 class="font-bold text-emerald-800 mb-6 flex items-center gap-2">● Present Athletes Summary</h3>
                    <div class="flex flex-wrap gap-2">
                        <?php foreach($present_members as $pm): ?>
                            <span class="bg-white px-3 py-2 rounded-xl text-xs font-bold text-emerald-700 shadow-sm ring-1 ring-emerald-100"><?= htmlspecialchars($pm['full_name']) ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="bg-slate-100 rounded-[2rem] p-8 border border-slate-200 shadow-sm">
                    <h3 class="font-bold text-slate-600 mb-6 flex items-center gap-2">○ Absent Athletes Summary</h3>
                    <div class="flex flex-wrap gap-2">
                        <?php foreach($absent_members as $am): ?>
                            <span class="bg-white px-3 py-2 rounded-xl text-xs font-bold text-slate-500 shadow-sm"><?= htmlspecialchars($am['full_name']) ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <div id="view-sessions" class="panel hidden">
            <div class="bg-white rounded-[2.5rem] p-10 border border-slate-200">
                <form method="POST" class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-8">
                    <input name="s_name" placeholder="E.g. Morning Practice" class="p-4 bg-slate-50 rounded-2xl border outline-none" required>
                    <input type="date" name="s_date" value="<?= date('Y-m-d') ?>" class="p-4 bg-slate-50 rounded-2xl border">
                    <button name="save_session" class="bg-slate-900 text-white rounded-2xl font-bold">Launch Session</button>
                </form>
                <?php foreach($sessions as $s): ?>
                    <div class="flex justify-between items-center p-5 border-b border-slate-50">
                        <div>
                            <span class="font-bold text-slate-800"><?= htmlspecialchars($s['name']) ?></span>
                            <span class="ml-3 text-slate-400 text-sm italic"><?= $s['date'] ?></span>
                        </div>
                        <a href="?action=del_s&id=<?= $s['id'] ?>" class="text-red-400 font-bold text-xs">Delete</a>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div id="view-export" class="panel hidden">
            <div class="bg-indigo-900 rounded-[2.5rem] p-12 text-white shadow-2xl relative overflow-hidden">
                <div class="relative z-10">
                    <h3 class="text-3xl font-bold mb-4">Export Intelligence Reports</h3>
                    <p class="text-indigo-200 mb-10 max-w-md">Generate data reports for specific attendance groups. Select a date and hit the desired category.</p>
                    <form method="GET" class="space-y-6">
                        <div class="flex flex-col md:flex-row gap-4">
                            <input type="date" name="export_date" value="<?= date('Y-m-d') ?>" 
                                   class="bg-indigo-950 border-none rounded-2xl p-5 text-white outline-none ring-1 ring-indigo-700">
                            <div class="flex flex-1 gap-2">
                                <button type="submit" name="export_type" value="all" class="flex-1 bg-white text-indigo-900 p-4 rounded-2xl font-bold uppercase text-xs">All Athletes</button>
                                <button type="submit" name="export_type" value="present" class="flex-1 bg-emerald-500 text-white p-4 rounded-2xl font-bold uppercase text-xs">Present Only</button>
                                <button type="submit" name="export_type" value="absent" class="flex-1 bg-red-500 text-white p-4 rounded-2xl font-bold uppercase text-xs">Absent Only</button>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="absolute -right-10 -bottom-10 w-64 h-64 bg-indigo-500/20 rounded-full blur-[100px]"></div>
            </div>
        </div>
    </main>
</div>

<script>
    function toggleView(v) {
        document.querySelectorAll('.panel').forEach(p => p.classList.add('hidden'));
        document.getElementById('view-' + v).classList.remove('hidden');
        document.querySelectorAll('nav button').forEach(b => b.classList.remove('sidebar-item-active'));
        document.querySelectorAll('nav button').forEach(b => b.classList.add('text-slate-400'));
        document.getElementById('btn-' + v).classList.add('sidebar-item-active');
        document.getElementById('btn-' + v).classList.remove('text-slate-400');
    }

    function searchAthletes() {
        const input = document.getElementById("searchInput").value.toLowerCase().trim();
        const rows = document.querySelectorAll("#athlete-rows .athlete-row");
        rows.forEach(row => {
            const name = row.querySelector(".athlete-name").innerText.toLowerCase();
            row.style.display = name.includes(input) ? "" : "none";
        });
    }

    function editM(id, name) {
        document.getElementById('m_id').value = id;
        document.getElementById('m_name').value = name;
        document.getElementById('m-title').innerText = "Update Athlete";
    }
</script>
</body>
</html>
