<?php  
/** * UJAMAA ACADEMY - ENTERPRISE EDITION V5.4  
 * Workflow: Session-First Selection & Cross-Session Intelligence
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

// 2. ADVANCED EXPORT ENGINE
if (isset($_GET['export_type'])) {  
    try {  
        $pdo = get_db_connection();
        $type = $_GET['export_type'];

        if ($type === 'compare') {
            $s_stmt = $pdo->query("SELECT id, name, date FROM sessions ORDER BY date DESC, id DESC LIMIT 2");
            $recent_sessions = $s_stmt->fetchAll();
            if (count($recent_sessions) < 2) die("Error: Need 2 sessions for comparison.");

            $s1 = $recent_sessions[0]; $s2 = $recent_sessions[1];
            header('Content-Type: text/csv; charset=utf-8');  
            header('Content-Disposition: attachment; filename=Ujamaa_Consistency_Report.csv');  
            $output = fopen('php://output', 'w');  
            fputcsv($output, ["Report: {$s2['name']} vs {$s1['name']}"]);
            fputcsv($output, ['Athlete Name', 'Prev Status', 'Current Status', 'Consistency']);

            $stmt = $pdo->prepare("SELECT m.full_name, CASE WHEN a1.id IS NOT NULL THEN 'PRESENT' ELSE 'ABSENT' END as cur, CASE WHEN a2.id IS NOT NULL THEN 'PRESENT' ELSE 'ABSENT' END as prev FROM members m LEFT JOIN attendance a1 ON a1.member_id = m.id AND a1.session_id = ? LEFT JOIN attendance a2 ON a2.member_id = m.id AND a2.session_id = ? ORDER BY m.full_name ASC");
            $stmt->execute([$s1['id'], $s2['id']]);
            while ($row = $stmt->fetch()) {
                $cons = ($row['cur'] === $row['prev']) ? ($row['cur'] === 'PRESENT' ? 'Steady (Both)' : 'Absent Both') : 'Changed';
                fputcsv($output, [$row['full_name'], $row['prev'], $row['cur'], $cons]);
            }
            exit;
        } else {
            $date = $_GET['export_date'] ?? date('Y-m-d');  
            header('Content-Type: text/csv; charset=utf-8');  
            header('Content-Disposition: attachment; filename=Ujamaa_Report.csv');  
            $output = fopen('php://output', 'w');  
            fputcsv($output, ['Athlete Name', 'Status', 'Payment', 'Due Date']);  
            $stmt = $pdo->prepare("SELECT m.full_name, CASE WHEN a.id IS NOT NULL THEN 'PRESENT' ELSE 'ABSENT' END as att, COALESCE(p.status, 'No Record') as pay, p.due_date FROM members m CROSS JOIN sessions s LEFT JOIN attendance a ON a.member_id = m.id AND a.session_id = s.id LEFT JOIN payments p ON p.member_id = m.id WHERE s.date = ? ORDER BY m.full_name ASC");  
            $stmt->execute([$date]);  
            while ($row = $stmt->fetch()) { fputcsv($output, $row); }  
            exit;
        }
    } catch (Exception $e) { die("Export Error: " . $e->getMessage()); }  
}

// 3. MAIN CONTROLLER
try {  
    $pdo = get_db_connection();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {  
        if (isset($_POST['save_member'])) {  
            $pdo->prepare("INSERT INTO members(full_name) VALUES (?) ON CONFLICT DO NOTHING")->execute([trim($_POST['name'])]);  
        }  
        if (isset($_POST['save_session'])) {  
            $pdo->prepare("INSERT INTO sessions(name, date) VALUES (?, ?)")->execute([trim($_POST['s_name']), $_POST['s_date']]);  
        }  
        if (isset($_POST['mark'])) {  
            $pdo->prepare("INSERT INTO attendance(session_id, member_id) VALUES (?, ?) ON CONFLICT DO NOTHING")->execute([$_POST['sid'], $_POST['mid']]);  
        }  
        header("Location: index.php?session=" . ($_POST['sid'] ?? '')); exit;  
    }  

    if (isset($_GET['action'])) {  
        if ($_GET['action'] === 'unmark') $pdo->prepare("DELETE FROM attendance WHERE member_id = ? AND session_id = ?")->execute([$_GET['mid'], $_GET['sid']]);  
        header("Location: index.php?session=" . $_GET['sid']); exit;  
    }

    $sessions = $pdo->query("SELECT * FROM sessions ORDER BY date DESC, id DESC")->fetchAll();  
    $current_sid = $_GET['session'] ?? null;
    $active_session = null;
    foreach($sessions as $s) if($s['id'] == $current_sid) $active_session = $s;

    $members = $pdo->query("SELECT * FROM members ORDER BY full_name ASC")->fetchAll();  
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
    <title>Ujamaa Academy v5.4</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
</head>
<body class="bg-slate-50 font-['Inter'] antialiased text-slate-900">

<div class="flex flex-col lg:flex-row min-h-screen">
    <aside class="w-full lg:w-72 bg-slate-900 p-6 text-white flex flex-col">
        <div class="mb-10">
            <h1 class="font-bold text-xl tracking-tighter">UJAMAA <span class="text-indigo-400">ENTERPRISE</span></h1>
        </div>
        
        <nav class="space-y-2 flex-1">
            <button onclick="location.href='index.php'" class="w-full text-left p-3 rounded-xl hover:bg-slate-800 transition font-semibold <?= !$current_sid ? 'bg-indigo-600' : '' ?>">Select Session</button>
            <button onclick="toggleView('registry')" class="w-full text-left p-3 rounded-xl hover:bg-slate-800 transition font-semibold <?= $current_sid ? 'bg-slate-800' : 'opacity-50' ?>" <?= !$current_sid ? 'disabled' : '' ?>>Mark Attendance</button>
            <button onclick="toggleView('export')" class="w-full text-left p-3 rounded-xl hover:bg-slate-800 transition font-semibold">Reports</button>
        </nav>

        <div class="mt-10 p-4 bg-slate-800 rounded-2xl border border-slate-700">
            <h3 class="text-xs font-bold uppercase mb-3 text-indigo-300">Quick Add Athlete</h3>
            <form method="POST" class="space-y-2">
                <input name="name" placeholder="Full Name" class="w-full bg-slate-900 border border-slate-700 rounded-lg p-2 text-sm text-white outline-none" required>
                <button name="save_member" class="w-full bg-indigo-600 py-2 rounded-lg font-bold text-xs">Save</button>
            </form>
        </div>
    </aside>

    <main class="flex-1 p-6 lg:p-12">
        
        <header class="mb-8 flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
            <div>
                <h2 class="text-3xl font-black text-slate-900">
                    <?= $active_session ? htmlspecialchars($active_session['name']) : 'Choose a Session' ?>
                </h2>
                <p class="text-slate-500 font-medium"><?= $active_session ? 'Tracking Date: ' . $active_session['date'] : 'Select a session to begin marking attendance.' ?></p>
            </div>
            <div class="bg-white shadow-sm border p-2 rounded-2xl">
                <select onchange="window.location.href='?session=' + this.value" class="bg-slate-50 px-4 py-2 rounded-xl font-bold text-sm outline-none">
                    <option value="">-- Change Session --</option>
                    <?php foreach($sessions as $s): ?>
                        <option value="<?= $s['id'] ?>" <?= $current_sid == $s['id'] ? 'selected' : '' ?>><?= $s['date'] ?> | <?= htmlspecialchars($s['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </header>

        <?php if(!$current_sid): ?>
        <div id="view-selector" class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div class="bg-white p-8 rounded-[2.5rem] border-2 border-dashed border-slate-200 flex flex-col items-center justify-center text-center">
                <h3 class="text-xl font-bold mb-2">Create New Session</h3>
                <form method="POST" class="w-full space-y-4 max-w-xs mt-4">
                    <input name="s_name" placeholder="Session Name (e.g. Finals)" class="w-full p-4 bg-slate-50 border rounded-2xl outline-none focus:ring-2 ring-indigo-500" required>
                    <input type="date" name="s_date" value="<?= date('Y-m-d') ?>" class="w-full p-4 bg-slate-50 border rounded-2xl">
                    <button name="save_session" class="w-full bg-slate-900 text-white p-4 rounded-2xl font-bold">Start New Session</button>
                </form>
            </div>
            <div class="bg-white p-8 rounded-[2.5rem] border shadow-sm overflow-hidden">
                <h3 class="text-xl font-bold mb-6">Recent Sessions</h3>
                <div class="space-y-3">
                    <?php foreach(array_slice($sessions, 0, 5) as $s): ?>
                    <a href="?session=<?= $s['id'] ?>" class="flex items-center justify-between p-4 bg-slate-50 hover:bg-indigo-50 rounded-2xl transition border border-transparent hover:border-indigo-100">
                        <span class="font-bold"><?= htmlspecialchars($s['name']) ?></span>
                        <span class="text-xs text-slate-400 font-mono"><?= $s['date'] ?></span>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if($current_sid): ?>
        <div id="view-registry" class="panel">
            <div class="bg-white rounded-[2.5rem] border shadow-sm overflow-hidden">
                <div class="p-6 border-b flex justify-between items-center bg-slate-50/50">
                    <input type="text" id="searchInput" onkeyup="searchAthletes()" placeholder="Search athletes..." class="w-full md:w-80 p-3 bg-white border rounded-xl text-sm outline-none focus:ring-2 ring-indigo-500/20">
                    <div class="text-xs font-bold text-slate-400 uppercase tracking-widest px-4">Registry Control</div>
                </div>
                <table class="w-full text-left">
                    <tbody class="divide-y divide-slate-100" id="athlete-rows">
                        <?php foreach($members as $m): $isPresent = in_array($m['id'], $attended_ids); ?>
                        <tr class="athlete-row hover:bg-slate-50/80 transition">
                            <td class="px-8 py-5">
                                <p class="font-bold text-slate-800 athlete-name"><?= htmlspecialchars($m['full_name']) ?></p>
                            </td>
                            <td class="px-8 py-5 text-right">
                                <?php if($isPresent): ?>
                                    <a href="?action=unmark&mid=<?= $m['id'] ?>&sid=<?= $current_sid ?>" class="bg-emerald-100 text-emerald-700 px-6 py-2 rounded-full text-xs font-black uppercase">Present</a>
                                <?php else: ?>
                                    <form method="POST" class="inline">
                                        <input type="hidden" name="sid" value="<?= $current_sid ?>">
                                        <input type="hidden" name="mid" value="<?= $m['id'] ?>">
                                        <button name="mark" class="bg-white border-2 border-slate-200 text-slate-400 hover:border-indigo-500 hover:text-indigo-600 px-6 py-2 rounded-full text-xs font-black uppercase transition-all">Mark Present</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <div id="view-export" class="panel hidden">
            <div class="bg-indigo-900 rounded-[2.5rem] p-10 text-white shadow-xl relative overflow-hidden">
                <div class="relative z-10">
                    <h3 class="text-2xl font-bold mb-2">Intelligence Center</h3>
                    <p class="text-indigo-200 mb-8">Generate cross-session comparison or daily reports.</p>
                    <form method="GET" class="space-y-4">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <button type="submit" name="export_type" value="compare" class="bg-amber-400 text-amber-950 p-6 rounded-2xl font-black uppercase text-sm hover:bg-amber-300 transition">
                                ⚡ Generate Consistency Report (Last 2 Sessions)
                            </button>
                            <div class="bg-indigo-800/50 p-6 rounded-2xl border border-indigo-700/50">
                                <p class="text-xs font-bold text-indigo-300 mb-3 uppercase">Export by Date</p>
                                <input type="date" name="export_date" value="<?= date('Y-m-d') ?>" class="w-full bg-indigo-950 border-none rounded-xl p-3 mb-3 text-white">
                                <button type="submit" name="export_type" value="all" class="w-full bg-white text-indigo-900 p-3 rounded-xl font-bold text-xs uppercase">Download Daily CSV</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>

    </main>
</div>

<script>
    function toggleView(v) {
        document.querySelectorAll('.panel').forEach(p => p.classList.add('hidden'));
        const target = document.getElementById('view-' + v);
        if(target) target.classList.remove('hidden');
        if(document.getElementById('view-selector')) document.getElementById('view-selector').classList.add('hidden');
    }

    function searchAthletes() {
        const input = document.getElementById("searchInput").value.toLowerCase();
        document.querySelectorAll(".athlete-row").forEach(row => {
            const name = row.querySelector(".athlete-name").innerText.toLowerCase();
            row.style.display = name.includes(input) ? "" : "none";
        });
    }
</script>
</body>
</html>
