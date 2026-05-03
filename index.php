<?php  
/** * UJAMAA ACADEMY - ENTERPRISE EDITION V6.0  
 * Unified Registry: Real-Time Search + Deep Intelligence Reports
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

// 2. REPORTING ENGINE
if (isset($_GET['export_type'])) {  
    try {  
        $pdo = get_db_connection();
        $type = $_GET['export_type'];

        if ($type === 'single') {
            $sid = $_GET['sid'];
            $s_stmt = $pdo->prepare("SELECT name, date FROM sessions WHERE id = ?");
            $s_stmt->execute([$sid]); $s = $s_stmt->fetch();

            header('Content-Type: text/csv');  
            header('Content-Disposition: attachment; filename=Session_Report.csv');  
            $output = fopen('php://output', 'w');  
            fputcsv($output, ["SESSION: {$s['name']} ({$s['date']})"]);
            fputcsv($output, ['Athlete Name', 'Status']);

            $stmt = $pdo->prepare("SELECT m.full_name, CASE WHEN a.id IS NOT NULL THEN 'PRESENT' ELSE 'ABSENT' END FROM members m LEFT JOIN attendance a ON a.member_id = m.id AND a.session_id = ? ORDER BY m.full_name ASC");
            $stmt->execute([$sid]);
            while ($row = $stmt->fetch()) { fputcsv($output, $row); }
            exit;
        }

        if ($type === 'compare') {
            header('Content-Type: text/csv');  
            header('Content-Disposition: attachment; filename=Comparison_Report.csv');  
            $output = fopen('php://output', 'w');  
            fputcsv($output, ['Athlete Name', 'Session A', 'Session B', 'Insight']);
            $stmt = $pdo->prepare("SELECT m.full_name, CASE WHEN a1.id IS NOT NULL THEN 'PRESENT' ELSE 'ABSENT' END as sA, CASE WHEN a2.id IS NOT NULL THEN 'PRESENT' ELSE 'ABSENT' END as sB FROM members m LEFT JOIN attendance a1 ON a1.member_id = m.id AND a1.session_id = ? LEFT JOIN attendance a2 ON a2.member_id = m.id AND a2.session_id = ? ORDER BY m.full_name ASC");
            $stmt->execute([$_GET['sA'], $_GET['sB']]);
            while ($row = $stmt->fetch()) { fputcsv($output, [$row['full_name'], $row['sa'], $row['sb'], ($row['sa']===$row['sb']?'Stable':'Changed')]); }
            exit;
        }
    } catch (Exception $e) { die("Export Error"); }  
}

// 3. MAIN CONTROLLER
try {  
    $pdo = get_db_connection();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {  
        if (isset($_POST['save_session'])) { 
            $pdo->prepare("INSERT INTO sessions(name, date) VALUES (?, ?)")->execute([trim($_POST['s_name']), $_POST['s_date']]); 
        }  
        if (isset($_POST['mark'])) { 
            $pdo->prepare("INSERT INTO attendance(session_id, member_id) VALUES (?, ?) ON CONFLICT DO NOTHING")->execute([$_POST['sid'], $_POST['mid']]); 
        }  
        header("Location: index.php?session=" . ($_POST['sid'] ?? '')); exit;  
    }  

    if (isset($_GET['action']) && $_GET['action'] === 'unmark') {  
        $pdo->prepare("DELETE FROM attendance WHERE member_id = ? AND session_id = ?")->execute([$_GET['mid'], $_GET['sid']]);  
        header("Location: index.php?session=" . $_GET['sid']); exit;  
    }

    $sessions = $pdo->query("SELECT * FROM sessions ORDER BY date DESC, id DESC")->fetchAll();  
    $current_sid = $_GET['session'] ?? ($sessions[0]['id'] ?? null);
    $active_s = null;
    foreach($sessions as $s) if($s['id'] == $current_sid) $active_s = $s;

    $members = $pdo->query("SELECT * FROM members ORDER BY full_name ASC")->fetchAll();  
    $attended_ids = [];
    if ($current_sid) {
        $stmt = $pdo->prepare("SELECT member_id FROM attendance WHERE session_id = ?");
        $stmt->execute([$current_sid]);
        $attended_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
} catch (Exception $e) { die("System Error"); }  
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ujamaa Academy v6.0</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;700;900&display=swap" rel="stylesheet">
    <style> body { font-family: 'Inter', sans-serif; } </style>
</head>
<body class="bg-slate-50">

<div class="flex flex-col lg:flex-row min-h-screen">
    <aside class="w-full lg:w-80 bg-slate-900 p-8 text-white flex flex-col">
        <div class="mb-10 text-center lg:text-left">
            <h1 class="text-3xl font-black italic tracking-tighter">UJAMAA<span class="text-indigo-500">.</span></h1>
            <p class="text-[10px] uppercase text-slate-500 font-bold">Enterprise v6.0</p>
        </div>
        <nav class="space-y-3 flex-1">
            <button onclick="showTab('marking')" id="nav-marking" class="w-full text-left p-4 rounded-2xl font-bold bg-indigo-600 shadow-lg">Attendance Hub</button>
            <button onclick="showTab('reports')" id="nav-reports" class="w-full text-left p-4 rounded-2xl font-bold text-slate-400 hover:bg-slate-800 transition">Report Center</button>
        </nav>
    </aside>

    <main class="flex-1 p-6 lg:p-12">
        
        <div id="tab-marking">
            <header class="flex flex-col md:flex-row justify-between items-start md:items-center mb-10 gap-6">
                <div>
                    <h2 class="text-3xl font-black"><?= $active_s ? htmlspecialchars($active_s['name']) : 'Select Session' ?></h2>
                    <p class="text-slate-400 font-bold"><?= $active_s ? $active_s['date'] : '---' ?></p>
                </div>
                <div class="flex items-center gap-3 w-full md:w-auto">
                    <select onchange="location.href='?session='+this.value" class="flex-1 md:flex-none bg-white border rounded-xl px-4 py-3 font-bold text-sm shadow-sm">
                        <?php foreach($sessions as $s): ?>
                            <option value="<?= $s['id'] ?>" <?= $current_sid == $s['id'] ? 'selected' : '' ?>><?= $s['date'] ?> | <?= htmlspecialchars($s['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button onclick="document.getElementById('modal-s').classList.remove('hidden')" class="bg-indigo-600 text-white w-12 h-12 rounded-xl font-bold shadow-lg">+</button>
                </div>
            </header>

            <div class="mb-6 relative group">
                <div class="absolute inset-y-0 left-0 pl-5 flex items-center pointer-events-none">
                    <span class="text-slate-400 font-bold">🔍</span>
                </div>
                <input type="text" id="athleteSearch" onkeyup="doSearch()" placeholder="Search athlete name..." 
                    class="w-full p-5 pl-14 bg-white border border-slate-200 rounded-[2rem] text-lg font-medium outline-none focus:ring-4 ring-indigo-500/10 shadow-sm transition-all">
            </div>

            <div class="bg-white rounded-[2.5rem] shadow-sm border border-slate-200 overflow-hidden">
                <div class="max-h-[600px] overflow-y-auto" id="athlete-registry">
                    <?php foreach($members as $m): $isPresent = in_array($m['id'], $attended_ids); ?>
                    <div class="athlete-row flex items-center justify-between px-8 py-5 border-b border-slate-50 last:border-0 hover:bg-slate-50 transition">
                        <span class="font-bold text-lg text-slate-800 athlete-name"><?= htmlspecialchars($m['full_name']) ?></span>
                        <div class="flex items-center gap-4">
                            <?php if($isPresent): ?>
                                <a href="?action=unmark&mid=<?= $m['id'] ?>&sid=<?= $current_sid ?>" class="bg-emerald-500 text-white px-8 py-3 rounded-2xl text-[10px] font-black uppercase shadow-lg shadow-emerald-100">Checked In</a>
                            <?php else: ?>
                                <form method="POST">
                                    <input type="hidden" name="sid" value="<?= $current_sid ?>">
                                    <input type="hidden" name="mid" value="<?= $m['id'] ?>">
                                    <button name="mark" class="border-2 border-slate-200 text-slate-400 px-8 py-3 rounded-2xl text-[10px] font-black uppercase hover:border-indigo-600 hover:text-indigo-600 transition-all">Mark Present</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div id="tab-reports" class="hidden space-y-10">
            <h2 class="text-4xl font-black">Intelligence Center</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                <div class="bg-white p-10 rounded-[3rem] border shadow-sm">
                    <h3 class="text-xl font-black mb-2 italic uppercase text-indigo-600">Full Session Snapshot</h3>
                    <p class="text-slate-500 text-sm mb-8">Download a CSV of all athletes (Present/Absent) for one session.</p>
                    <form method="GET" class="space-y-4">
                        <input type="hidden" name="export_type" value="single">
                        <select name="sid" class="w-full p-4 bg-slate-50 border rounded-2xl font-bold">
                            <?php foreach($sessions as $s): ?><option value="<?= $s['id'] ?>"><?= $s['date'] ?> - <?= $s['name'] ?></option><?php endforeach; ?>
                        </select>
                        <button type="submit" class="w-full bg-slate-900 text-white p-4 rounded-2xl font-black text-xs uppercase tracking-widest shadow-xl">Export CSV</button>
                    </form>
                </div>

                <div class="bg-indigo-900 p-10 rounded-[3rem] text-white shadow-2xl relative overflow-hidden">
                    <h3 class="text-xl font-black mb-2 uppercase text-indigo-300">Consistency Comparison</h3>
                    <p class="text-indigo-200 text-sm mb-8">Analyze changes in attendance between two specific sessions.</p>
                    <form method="GET" class="space-y-4">
                        <input type="hidden" name="export_type" value="compare">
                        <select name="sA" class="w-full p-4 bg-indigo-950 border-none rounded-2xl text-xs text-white" required>
                            <option value="">Baseline Session...</option>
                            <?php foreach($sessions as $s): ?><option value="<?= $s['id'] ?>"><?= $s['date'] ?> - <?= $s['name'] ?></option><?php endforeach; ?>
                        </select>
                        <select name="sB" class="w-full p-4 bg-indigo-950 border-none rounded-2xl text-xs text-white" required>
                            <option value="">Target Session...</option>
                            <?php foreach($sessions as $s): ?><option value="<?= $s['id'] ?>"><?= $s['date'] ?> - <?= $s['name'] ?></option><?php endforeach; ?>
                        </select>
                        <button type="submit" class="w-full bg-amber-400 text-amber-950 p-4 rounded-2xl font-black text-xs uppercase tracking-widest shadow-xl">Run Analysis</button>
                    </form>
                </div>
            </div>
        </div>
    </main>
</div>

<div id="modal-s" class="hidden fixed inset-0 bg-slate-900/90 backdrop-blur-sm flex items-center justify-center p-6 z-50">
    <div class="bg-white p-10 rounded-[3rem] w-full max-w-md">
        <h3 class="text-2xl font-black mb-6">Create New Session</h3>
        <form method="POST" class="space-y-4">
            <input name="s_name" placeholder="Session Title" class="w-full p-4 bg-slate-50 border rounded-2xl outline-none" required>
            <input type="date" name="s_date" value="<?= date('Y-m-d') ?>" class="w-full p-4 bg-slate-50 border rounded-2xl">
            <button name="save_session" class="w-full bg-indigo-600 text-white p-5 rounded-2xl font-black uppercase text-sm shadow-lg">Start Session</button>
            <button type="button" onclick="document.getElementById('modal-s').classList.add('hidden')" class="w-full p-4 text-slate-400 font-bold">Cancel</button>
        </form>
    </div>
</div>

<script>
    function showTab(id) {
        document.getElementById('tab-marking').classList.add('hidden');
        document.getElementById('tab-reports').classList.add('hidden');
        document.getElementById('tab-' + id).classList.remove('hidden');

        document.getElementById('nav-marking').className = "w-full text-left p-4 rounded-2xl font-bold transition " + (id === 'marking' ? "bg-indigo-600 text-white shadow-lg" : "text-slate-400 hover:bg-slate-800");
        document.getElementById('nav-reports').className = "w-full text-left p-4 rounded-2xl font-bold transition " + (id === 'reports' ? "bg-indigo-600 text-white shadow-lg" : "text-slate-400 hover:bg-slate-800");
    }

    function doSearch() {
        const query = document.getElementById("athleteSearch").value.toLowerCase();
        document.querySelectorAll(".athlete-row").forEach(row => {
            const name = row.querySelector(".athlete-name").innerText.toLowerCase();
            row.style.display = name.includes(query) ? "flex" : "none";
        });
    }
</script>
</body>
</html>
