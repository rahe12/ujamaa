<?php  
/** * UJAMAA ACADEMY - APEX EDITION V7.0
 * Unified Logic: Attendance Marking, Intelligence Reporting, & Member Management
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

// 2. REPORTING ENGINE (Consistency & Snapshots)
if (isset($_GET['export_type'])) {  
    try {  
        $pdo = get_db_connection();
        $type = $_GET['export_type'];

        // SNAPSHOT REPORT (Single Session)
        if ($type === 'snapshot') {
            $sid = $_GET['sid'];
            $s_stmt = $pdo->prepare("SELECT name, date FROM sessions WHERE id = ?");
            $s_stmt->execute([$sid]); $s = $s_stmt->fetch();
            
            header('Content-Type: text/csv'); 
            header('Content-Disposition: attachment; filename=Session_Snapshot.csv');  
            $output = fopen('php://output', 'w');  
            fputcsv($output, ["SESSION SNAPSHOT: {$s['name']} ({$s['date']})"]);
            fputcsv($output, ['Athlete Name', 'Status']);

            $stmt = $pdo->prepare("SELECT m.full_name, CASE WHEN a.id IS NOT NULL THEN 'PRESENT' ELSE 'ABSENT' END FROM members m LEFT JOIN attendance a ON a.member_id = m.id AND a.session_id = ? ORDER BY m.full_name ASC");
            $stmt->execute([$sid]);
            while ($row = $stmt->fetch()) { fputcsv($output, $row); }
            exit;
        }

        // CONSISTENCY REPORT (Cross-Session Comparison)
        if ($type === 'consistency') {
            $sidA = $_GET['sidA']; $sidB = $_GET['sidB'];
            header('Content-Type: text/csv'); header('Content-Disposition: attachment; filename=Consistency_Analysis.csv');  
            $output = fopen('php://output', 'w');  
            fputcsv($output, ['Athlete Name', 'Session A Status', 'Session B Status', 'Attendance Logic']);

            $stmt = $pdo->prepare("
                SELECT m.full_name, 
                CASE WHEN a1.id IS NOT NULL THEN 'PRESENT' ELSE 'ABSENT' END as sA,
                CASE WHEN a2.id IS NOT NULL THEN 'PRESENT' ELSE 'ABSENT' END as sB
                FROM members m
                LEFT JOIN attendance a1 ON a1.member_id = m.id AND a1.session_id = ?
                LEFT JOIN attendance a2 ON a2.member_id = m.id AND a2.session_id = ?
                ORDER BY m.full_name ASC
            ");
            $stmt->execute([$sidA, $sidB]);
            while ($row = $stmt->fetch()) {
                $logic = "Change";
                if($row['sa'] === 'PRESENT' && $row['sb'] === 'PRESENT') $logic = "Consistent (Attended Both)";
                elseif($row['sa'] === 'ABSENT' && $row['sb'] === 'ABSENT') $logic = "Inactive (Missed Both)";
                elseif($row['sa'] === 'PRESENT') $logic = "Drop-off (Missing in B)";
                else $logic = "New Gain (Joined in B)";
                
                fputcsv($output, [$row['full_name'], $row['sa'], $row['sb'], $logic]);
            }
            exit;
        }
    } catch (Exception $e) { die("Export Error"); }  
}

// 3. DATA CONTROLLER
try {  
    $pdo = get_db_connection();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {  
        if (isset($_POST['save_athlete'])) $pdo->prepare("INSERT INTO members(full_name) VALUES (?)")->execute([trim($_POST['full_name'])]);
        if (isset($_POST['update_athlete'])) $pdo->prepare("UPDATE members SET full_name = ? WHERE id = ?")->execute([trim($_POST['full_name']), $_POST['mid']]);
        if (isset($_POST['delete_athlete'])) $pdo->prepare("DELETE FROM members WHERE id = ?")->execute([$_POST['mid']]);
        if (isset($_POST['save_session'])) $pdo->prepare("INSERT INTO sessions(name, date) VALUES (?, ?)")->execute([trim($_POST['s_name']), $_POST['s_date']]); 
        if (isset($_POST['mark'])) $pdo->prepare("INSERT INTO attendance(session_id, member_id) VALUES (?, ?) ON CONFLICT DO NOTHING")->execute([$_POST['sid'], $_POST['mid']]); 
        header("Location: index.php?session=" . ($_POST['sid'] ?? '')); exit;  
    }  
    if (isset($_GET['action']) && $_GET['action'] === 'unmark') {  
        $pdo->prepare("DELETE FROM attendance WHERE member_id = ? AND session_id = ?")->execute([$_GET['mid'], $_GET['sid']]);  
        header("Location: index.php?session=" . $_GET['sid']); exit;  
    }
    $sessions = $pdo->query("SELECT * FROM sessions ORDER BY date DESC, id DESC LIMIT 100")->fetchAll();  
    $current_sid = $_GET['session'] ?? ($sessions[0]['id'] ?? null);
    $active_s = null; foreach($sessions as $s) if($s['id'] == $current_sid) $active_s = $s;
    $members = $pdo->query("SELECT * FROM members ORDER BY full_name ASC")->fetchAll();  
    $attended_ids = $current_sid ? $pdo->query("SELECT member_id FROM attendance WHERE session_id = $current_sid")->fetchAll(PDO::FETCH_COLUMN) : [];
} catch (Exception $e) { die("System Error: " . $e->getMessage()); }  
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ujamaa Academy | Apex v7.0</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;800&display=swap" rel="stylesheet">
    <style> 
        body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: #f3f4f6; } 
        .glass-sidebar { background: linear-gradient(180deg, #0f172a 0%, #1e293b 100%); }
        .card { transition: transform 0.2s ease, box-shadow 0.2s ease; }
        .card:hover { transform: translateY(-2px); box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1); }
    </style>
</head>
<body class="antialiased text-slate-900">

<div class="flex flex-col lg:flex-row min-h-screen">
    <aside class="w-full lg:w-80 glass-sidebar p-8 text-white flex flex-col">
        <div class="mb-12">
            <h1 class="text-3xl font-extrabold tracking-tighter italic">UJAMAA<span class="text-indigo-400">.</span></h1>
            <span class="text-[10px] bg-indigo-500/20 text-indigo-300 px-2 py-1 rounded font-bold uppercase tracking-widest">Enterprise v7.0</span>
        </div>
        
        <nav class="space-y-4 mb-12">
            <button onclick="view('marking')" id="n-marking" class="w-full flex items-center gap-3 p-4 rounded-2xl font-bold bg-indigo-600 shadow-xl transition-all">Registry</button>
            <button onclick="view('intelligence')" id="n-intelligence" class="w-full flex items-center gap-3 p-4 rounded-2xl font-bold text-slate-400 hover:bg-slate-800 transition-all">Intelligence</button>
        </nav>

        <div class="mt-auto bg-white/5 p-6 rounded-[2rem] border border-white/10">
            <h3 class="text-xs font-extrabold uppercase text-indigo-400 mb-4 tracking-widest">New Athlete</h3>
            <form method="POST" class="space-y-3">
                <input name="full_name" placeholder="Full Name" class="w-full bg-slate-950/50 border border-white/10 p-4 rounded-xl text-sm outline-none focus:ring-2 ring-indigo-500" required>
                <input type="hidden" name="sid" value="<?= $current_sid ?>">
                <button name="save_athlete" class="w-full bg-white text-slate-900 py-3 rounded-xl font-extrabold text-[10px] uppercase tracking-widest hover:bg-indigo-50 transition">Register</button>
            </form>
        </div>
    </aside>

    <main class="flex-1 p-6 lg:p-12 overflow-y-auto h-screen">
        
        <div id="v-marking" class="max-w-5xl mx-auto">
            <header class="flex flex-col md:flex-row justify-between items-start md:items-center mb-10 gap-6">
                <div>
                    <h2 class="text-4xl font-extrabold text-slate-900 tracking-tight"><?= $active_s ? htmlspecialchars($active_s['name']) : 'Dashboard' ?></h2>
                    <p class="text-slate-400 font-semibold"><?= $active_s ? date('D, M jS Y', strtotime($active_s['date'])) : 'Create or select a session' ?></p>
                </div>
                <div class="flex items-center gap-3 w-full md:w-auto">
                    <select onchange="location.href='?session='+this.value" class="flex-1 md:flex-none bg-white border-none rounded-2xl px-6 py-4 font-bold text-sm shadow-sm ring-1 ring-slate-200">
                        <?php foreach($sessions as $s): ?>
                            <option value="<?= $s['id'] ?>" <?= $current_sid == $s['id'] ? 'selected' : '' ?>><?= $s['date'] ?> — <?= htmlspecialchars($s['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button onclick="openModal('m-session')" class="bg-indigo-600 text-white w-14 h-14 rounded-2xl font-bold shadow-lg hover:rotate-90 transition-all">+</button>
                </div>
            </header>

            <div class="relative mb-8">
                <input type="text" id="aSearch" onkeyup="search()" placeholder="Filter registry by name..." class="w-full p-6 bg-white border-none rounded-3xl text-lg font-medium shadow-sm ring-1 ring-slate-200 focus:ring-2 focus:ring-indigo-500 outline-none transition-all">
            </div>

            <div class="bg-white rounded-[2.5rem] shadow-sm ring-1 ring-slate-200 overflow-hidden">
                <div class="max-h-[70vh] overflow-y-auto">
                    <?php foreach($members as $m): $isP = in_array($m['id'], $attended_ids); ?>
                    <div class="a-row flex items-center justify-between px-10 py-6 border-b border-slate-50 last:border-0 hover:bg-slate-50 transition-all">
                        <div class="flex flex-col">
                            <span class="font-extrabold text-xl text-slate-800 a-name"><?= htmlspecialchars($m['full_name']) ?></span>
                            <div class="flex gap-4 mt-1">
                                <button onclick="editMember(<?= $m['id'] ?>, '<?= addslashes($m['full_name']) ?>')" class="text-[10px] font-extrabold uppercase text-slate-400 hover:text-indigo-600 transition">Modify</button>
                                <button onclick="deleteMember(<?= $m['id'] ?>, '<?= addslashes($m['full_name']) ?>')" class="text-[10px] font-extrabold uppercase text-slate-400 hover:text-red-500 transition">Remove</button>
                            </div>
                        </div>
                        <div>
                            <?php if($isP): ?>
                                <a href="?action=unmark&mid=<?= $m['id'] ?>&sid=<?= $current_sid ?>" class="bg-emerald-500 text-white px-10 py-4 rounded-2xl text-xs font-black uppercase shadow-lg shadow-emerald-100">Present</a>
                            <?php else: ?>
                                <form method="POST"><input type="hidden" name="sid" value="<?= $current_sid ?>"><input type="hidden" name="mid" value="<?= $m['id'] ?>"><button name="mark" class="border-2 border-slate-100 text-slate-400 px-10 py-4 rounded-2xl text-xs font-black uppercase hover:border-indigo-600 hover:text-indigo-600 transition-all">Mark Present</button></form>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div id="v-intelligence" class="hidden max-w-5xl mx-auto space-y-10">
            <h2 class="text-4xl font-extrabold text-slate-900">Intelligence Engine</h2>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                <div class="card bg-white p-10 rounded-[3rem] ring-1 ring-slate-200">
                    <h3 class="text-2xl font-extrabold mb-2 text-indigo-600">Session Snapshot</h3>
                    <p class="text-slate-500 text-sm mb-8 font-medium">Export every athlete's status (Present or Absent) for a single training session.</p>
                    <form method="GET" class="space-y-4">
                        <input type="hidden" name="export_type" value="snapshot">
                        <select name="sid" class="w-full p-4 bg-slate-50 border-none rounded-2xl font-bold ring-1 ring-slate-100">
                            <?php foreach($sessions as $s): ?><option value="<?= $s['id'] ?>"><?= $s['date'] ?> — <?= htmlspecialchars($s['name']) ?></option><?php endforeach; ?>
                        </select>
                        <button type="submit" class="w-full bg-slate-900 text-white p-5 rounded-2xl font-extrabold text-xs uppercase tracking-widest shadow-xl">Download CSV Snapshot</button>
                    </form>
                </div>

                <div class="card bg-indigo-900 p-10 rounded-[3rem] text-white shadow-2xl relative overflow-hidden">
                    <h3 class="text-2xl font-extrabold mb-2 text-indigo-300">Consistency Analytics</h3>
                    <p class="text-indigo-100/60 text-sm mb-8 font-medium">Compare Session A to Session B to identify retention, drop-offs, and new recruits.</p>
                    <form method="GET" class="space-y-4 relative z-10">
                        <input type="hidden" name="export_type" value="consistency">
                        <div class="grid grid-cols-2 gap-4">
                            <select name="sidA" class="w-full p-4 bg-indigo-950 border-none rounded-2xl text-xs text-white outline-none" required>
                                <option value="">Session A...</option>
                                <?php foreach($sessions as $s): ?><option value="<?= $s['id'] ?>"><?= $s['date'] ?> - <?= $s['name'] ?></option><?php endforeach; ?>
                            </select>
                            <select name="sidB" class="w-full p-4 bg-indigo-950 border-none rounded-2xl text-xs text-white outline-none" required>
                                <option value="">Session B...</option>
                                <?php foreach($sessions as $s): ?><option value="<?= $s['id'] ?>"><?= $s['date'] ?> - <?= $s['name'] ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" class="w-full bg-amber-400 text-amber-950 p-5 rounded-2xl font-extrabold text-xs uppercase tracking-widest shadow-xl hover:scale-[1.02] transition">Analyze & Export</button>
                    </form>
                    <div class="absolute -right-20 -bottom-20 w-64 h-64 bg-indigo-400/20 rounded-full blur-3xl"></div>
                </div>
            </div>
        </div>
    </main>
</div>

<div id="m-edit" class="hidden fixed inset-0 bg-slate-950/80 backdrop-blur-sm flex items-center justify-center p-6 z-50">
    <div class="bg-white p-10 rounded-[3rem] w-full max-w-md">
        <h3 class="text-2xl font-extrabold mb-6">Modify Athlete</h3>
        <form method="POST" class="space-y-4">
            <input type="hidden" name="mid" id="e-mid">
            <input type="hidden" name="sid" value="<?= $current_sid ?>">
            <input name="full_name" id="e-name" class="w-full p-5 bg-slate-50 rounded-2xl outline-none ring-1 ring-slate-100 focus:ring-2 ring-indigo-500" required>
            <button name="update_athlete" class="w-full bg-indigo-600 text-white p-5 rounded-2xl font-extrabold uppercase text-xs">Save Update</button>
            <button type="button" onclick="closeModal('m-edit')" class="w-full p-4 text-slate-400 font-bold">Cancel</button>
        </form>
    </div>
</div>

<div id="m-delete" class="hidden fixed inset-0 bg-slate-950/80 backdrop-blur-sm flex items-center justify-center p-6 z-50">
    <div class="bg-white p-10 rounded-[3rem] w-full max-w-md text-center">
        <h3 class="text-2xl font-extrabold mb-2 text-red-600">Wipe Member?</h3>
        <p class="text-slate-500 mb-8">Permanently remove <span id="d-name" class="font-bold text-slate-900"></span>? This will clear all their attendance records.</p>
        <form method="POST" class="space-y-4">
            <input type="hidden" name="mid" id="d-mid">
            <input type="hidden" name="sid" value="<?= $current_sid ?>">
            <button name="delete_athlete" class="w-full bg-red-600 text-white p-5 rounded-2xl font-extrabold uppercase text-xs">Confirm Delete</button>
            <button type="button" onclick="closeModal('m-delete')" class="w-full p-4 text-slate-400 font-bold">Close</button>
        </form>
    </div>
</div>

<div id="m-session" class="hidden fixed inset-0 bg-slate-950/80 backdrop-blur-sm flex items-center justify-center p-6 z-50">
    <div class="bg-white p-10 rounded-[3rem] w-full max-w-md">
        <h3 class="text-2xl font-extrabold mb-6">Initialize Session</h3>
        <form method="POST" class="space-y-4">
            <input name="s_name" placeholder="Title (e.g. Squad Prep)" class="w-full p-5 bg-slate-50 rounded-2xl outline-none" required>
            <input type="date" name="s_date" value="<?= date('Y-m-d') ?>" class="w-full p-5 bg-slate-50 rounded-2xl">
            <button name="save_session" class="w-full bg-indigo-600 text-white p-5 rounded-2xl font-extrabold uppercase text-xs">Start Session</button>
            <button type="button" onclick="closeModal('m-session')" class="w-full p-4 text-slate-400 font-bold">Cancel</button>
        </form>
    </div>
</div>

<script>
    function view(id) {
        document.getElementById('v-marking').classList.toggle('hidden', id !== 'marking');
        document.getElementById('v-intelligence').classList.toggle('hidden', id !== 'intelligence');
        document.getElementById('n-marking').className = "w-full p-4 rounded-2xl font-bold transition-all " + (id === 'marking' ? "bg-indigo-600 shadow-xl" : "text-slate-400 hover:bg-slate-800");
        document.getElementById('n-intelligence').className = "w-full p-4 rounded-2xl font-bold transition-all " + (id === 'intelligence' ? "bg-indigo-600 shadow-xl" : "text-slate-400 hover:bg-slate-800");
    }
    function search() {
        const q = document.getElementById("aSearch").value.toLowerCase();
        document.querySelectorAll(".a-row").forEach(row => {
            row.style.display = row.querySelector(".a-name").innerText.toLowerCase().includes(q) ? "flex" : "none";
        });
    }
    function editMember(id, name) {
        document.getElementById('e-mid').value = id;
        document.getElementById('e-name').value = name;
        openModal('m-edit');
    }
    function deleteMember(id, name) {
        document.getElementById('d-mid').value = id;
        document.getElementById('d-name').innerText = name;
        openModal('m-delete');
    }
    function openModal(id) { document.getElementById(id).classList.remove('hidden'); }
    function closeModal(id) { document.getElementById(id).classList.add('hidden'); }
</script>
</body>
</html>
