<?php  
/** * UJAMAA ACADEMY - ENTERPRISE EDITION V5.9  
 * Full Suite: Registry Marking + Comprehensive Reporting Intelligence
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

// 2. THE REPORTING ENGINE (Unified Logic)
if (isset($_GET['export_type'])) {  
    try {  
        $pdo = get_db_connection();
        $type = $_GET['export_type'];

        // REPORT 1: Single Session Snapshot (Full list of who attended and who didn't)
        if ($type === 'single') {
            $sid = $_GET['sid'];
            $s_stmt = $pdo->prepare("SELECT name, date FROM sessions WHERE id = ?");
            $s_stmt->execute([$sid]); $s = $s_stmt->fetch();

            header('Content-Type: text/csv; charset=utf-8');  
            header('Content-Disposition: attachment; filename=Ujamaa_Session_Report.csv');  
            $output = fopen('php://output', 'w');  
            fputcsv($output, ["SESSION SUMMARY: {$s['name']} ({$s['date']})"]);
            fputcsv($output, ['Athlete Name', 'Attendance Status']);

            $stmt = $pdo->prepare("
                SELECT m.full_name, 
                CASE WHEN a.id IS NOT NULL THEN 'PRESENT' ELSE 'ABSENT' END as status
                FROM members m
                LEFT JOIN attendance a ON a.member_id = m.id AND a.session_id = ?
                ORDER BY m.full_name ASC
            ");
            $stmt->execute([$sid]);
            while ($row = $stmt->fetch()) { fputcsv($output, $row); }
            exit;
        }

        // REPORT 2: Consistency Comparison (Comparing Session A to Session B)
        if ($type === 'compare') {
            header('Content-Type: text/csv; charset=utf-8');  
            header('Content-Disposition: attachment; filename=Ujamaa_Comparison.csv');  
            $output = fopen('php://output', 'w');  
            fputcsv($output, ['Athlete Name', 'Status (Session A)', 'Status (Session B)', 'Insight']);

            $stmt = $pdo->prepare("
                SELECT m.full_name, 
                CASE WHEN a1.id IS NOT NULL THEN 'PRESENT' ELSE 'ABSENT' END as sA,
                CASE WHEN a2.id IS NOT NULL THEN 'PRESENT' ELSE 'ABSENT' END as sB
                FROM members m
                LEFT JOIN attendance a1 ON a1.member_id = m.id AND a1.session_id = ?
                LEFT JOIN attendance a2 ON a2.member_id = m.id AND a2.session_id = ?
                ORDER BY m.full_name ASC
            ");
            $stmt->execute([$_GET['sA'], $_GET['sB']]);
            while ($row = $stmt->fetch()) {
                $insight = ($row['sa'] === $row['sb']) ? "Consistent" : "Changed";
                fputcsv($output, [$row['full_name'], $row['sa'], $row['sb'], $insight]);
            }
            exit;
        }
    } catch (Exception $e) { die("Export Error: " . $e->getMessage()); }  
}

// 3. MAIN CONTROLLER (Marking Logic)
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
} catch (Exception $e) { die("System Error: " . $e->getMessage()); }  
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Ujamaa Academy v5.9</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;700;900&display=swap" rel="stylesheet">
    <style> body { font-family: 'Inter', sans-serif; } </style>
</head>
<body class="bg-slate-50 antialiased">

<div class="flex flex-col lg:flex-row min-h-screen">
    <aside class="w-full lg:w-80 bg-slate-900 p-8 text-white">
        <div class="mb-10">
            <h1 class="text-3xl font-black tracking-tighter italic">UJAMAA<span class="text-indigo-500">.</span></h1>
            <p class="text-[10px] uppercase text-slate-500 font-bold tracking-widest">Enterprise v5.9</p>
        </div>

        <nav class="space-y-3">
            <button onclick="toggleTab('marking')" class="w-full text-left p-4 rounded-2xl font-bold transition hover:bg-slate-800 text-indigo-400" id="btn-marking">Mark Attendance</button>
            <button onclick="toggleTab('reports')" class="w-full text-left p-4 rounded-2xl font-bold transition hover:bg-slate-800 text-slate-400" id="btn-reports">Report Center</button>
        </nav>
    </aside>

    <main class="flex-1 p-6 lg:p-12">
        
        <div id="tab-marking">
            <header class="flex justify-between items-center mb-10 bg-white p-6 rounded-3xl shadow-sm border">
                <div>
                    <h2 class="text-2xl font-black"><?= $active_s ? htmlspecialchars($active_s['name']) : 'Select a Session' ?></h2>
                    <p class="text-slate-400 text-xs font-bold uppercase tracking-widest"><?= $active_s ? $active_s['date'] : 'No date selected' ?></p>
                </div>
                <div class="flex gap-3">
                    <select onchange="location.href='?session='+this.value" class="bg-slate-100 border-none rounded-xl px-4 py-2 font-bold text-sm">
                        <?php foreach($sessions as $s): ?>
                            <option value="<?= $s['id'] ?>" <?= $current_sid == $s['id'] ? 'selected' : '' ?>><?= $s['date'] ?> | <?= htmlspecialchars($s['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button onclick="document.getElementById('modal-session').classList.remove('hidden')" class="bg-indigo-600 text-white w-10 h-10 rounded-xl font-bold">+</button>
                </div>
            </header>

            <div class="bg-white rounded-[2.5rem] shadow-sm border overflow-hidden">
                <table class="w-full">
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach($members as $m): $isPresent = in_array($m['id'], $attended_ids); ?>
                        <tr class="hover:bg-slate-50 transition">
                            <td class="px-10 py-5 font-bold text-slate-800"><?= htmlspecialchars($m['full_name']) ?></td>
                            <td class="px-10 py-5 text-right">
                                <?php if($isPresent): ?>
                                    <a href="?action=unmark&mid=<?= $m['id'] ?>&sid=<?= $current_sid ?>" class="bg-emerald-500 text-white px-8 py-3 rounded-2xl text-[10px] font-black uppercase shadow-lg shadow-emerald-100">Checked In</a>
                                <?php else: ?>
                                    <form method="POST">
                                        <input type="hidden" name="sid" value="<?= $current_sid ?>">
                                        <input type="hidden" name="mid" value="<?= $m['id'] ?>">
                                        <button name="mark" class="border-2 border-slate-200 text-slate-400 hover:border-indigo-600 hover:text-indigo-600 px-8 py-3 rounded-2xl text-[10px] font-black uppercase transition-all">Mark Present</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div id="tab-reports" class="hidden space-y-10">
            <h2 class="text-4xl font-black">Report Center</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                
                <div class="bg-white p-10 rounded-[3rem] border shadow-sm">
                    <h3 class="text-xl font-black mb-4 italic uppercase text-indigo-600">Session Snapshot</h3>
                    <p class="text-slate-500 text-sm mb-8">Export a list of all athletes with their status (Present/Absent) for one session.</p>
                    <form method="GET" class="space-y-4">
                        <input type="hidden" name="export_type" value="single">
                        <select name="sid" class="w-full p-4 bg-slate-50 border rounded-2xl font-bold text-slate-700">
                            <?php foreach($sessions as $s): ?><option value="<?= $s['id'] ?>"><?= $s['date'] ?> - <?= $s['name'] ?></option><?php endforeach; ?>
                        </select>
                        <button type="submit" class="w-full bg-slate-900 text-white p-4 rounded-2xl font-black text-xs uppercase tracking-widest">Download Full Session Report</button>
                    </form>
                </div>

                <div class="bg-indigo-900 p-10 rounded-[3rem] text-white shadow-2xl relative overflow-hidden">
                    <h3 class="text-xl font-black mb-4 uppercase text-indigo-300">Consistency Analysis</h3>
                    <p class="text-indigo-200 text-sm mb-8">Compare attendance records between two specific dates.</p>
                    <form method="GET" class="space-y-4">
                        <input type="hidden" name="export_type" value="compare">
                        <select name="sA" class="w-full p-4 bg-indigo-950 border-none rounded-2xl text-xs text-white" required>
                            <option value="">Baseline Session...</option>
                            <?php foreach($sessions as $s): ?><option value="<?= $s['id'] ?>"><?= $s['date'] ?> - <?= $s['name'] ?></option><?php endforeach; ?>
                        </select>
                        <select name="sB" class="w-full p-4 bg-indigo-950 border-none rounded-2xl text-xs text-white" required>
                            <option value="">Comparison Session...</option>
                            <?php foreach($sessions as $s): ?><option value="<?= $s['id'] ?>"><?= $s['date'] ?> - <?= $s['name'] ?></option><?php endforeach; ?>
                        </select>
                        <button type="submit" class="w-full bg-amber-400 text-amber-950 p-4 rounded-2xl font-black text-xs uppercase tracking-widest hover:scale-[1.02] transition">Analyze & Export</button>
                    </form>
                </div>

            </div>
        </div>
    </main>
</div>

<div id="modal-session" class="hidden fixed inset-0 bg-slate-900/90 backdrop-blur-sm flex items-center justify-center p-6 z-50">
    <div class="bg-white p-10 rounded-[3rem] w-full max-w-md shadow-2xl">
        <h3 class="text-2xl font-black mb-6">Start New Session</h3>
        <form method="POST" class="space-y-4">
            <input name="s_name" placeholder="Session Title (e.g. Afternoon Drill)" class="w-full p-4 bg-slate-50 border rounded-2xl outline-none" required>
            <input type="date" name="s_date" value="<?= date('Y-m-d') ?>" class="w-full p-4 bg-slate-50 border rounded-2xl">
            <button name="save_session" class="w-full bg-indigo-600 text-white p-5 rounded-2xl font-black uppercase text-sm tracking-widest shadow-lg">Initialize</button>
            <button type="button" onclick="document.getElementById('modal-session').classList.add('hidden')" class="w-full p-4 text-slate-400 font-bold">Cancel</button>
        </form>
    </div>
</div>

<script>
    function toggleTab(id) {
        document.getElementById('tab-marking').classList.add('hidden');
        document.getElementById('tab-reports').classList.add('hidden');
        document.getElementById('tab-' + id).classList.remove('hidden');

        document.getElementById('btn-marking').className = "w-full text-left p-4 rounded-2xl font-bold transition " + (id === 'marking' ? "bg-indigo-600 text-white" : "text-slate-400 hover:bg-slate-800");
        document.getElementById('btn-reports').className = "w-full text-left p-4 rounded-2xl font-bold transition " + (id === 'reports' ? "bg-indigo-600 text-white" : "text-slate-400 hover:bg-slate-800");
    }
</script>
</body>
</html>
