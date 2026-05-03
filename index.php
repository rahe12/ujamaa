<?php  
/** * UJAMAA ACADEMY - ENTERPRISE EDITION V5.6  
 * Features: Single Session Snapshots, Manual Comparison, & Daily Reports
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

        // TOOL A: Single Session Snapshot (Present & Absent for ONE session)
        if ($type === 'single_session') {
            $sid = $_GET['target_session'];
            if (!$sid) die("Error: Select a session.");

            $s_meta = $pdo->prepare("SELECT name, date FROM sessions WHERE id = ?");
            $s_meta->execute([$sid]); $s = $s_meta->fetch();

            header('Content-Type: text/csv; charset=utf-8');  
            header('Content-Disposition: attachment; filename=Session_Snapshot_'.$s['date'].'.csv');  
            $output = fopen('php://output', 'w');  
            
            fputcsv($output, ["Session Snapshot Report"]);
            fputcsv($output, ["Session Name:", $s['name']]);
            fputcsv($output, ["Session Date:", $s['date']]);
            fputcsv($output, []);
            fputcsv($output, ['Athlete Name', 'Attendance Status']);

            $stmt = $pdo->prepare("
                SELECT m.full_name, 
                CASE WHEN a.id IS NOT NULL THEN 'PRESENT' ELSE 'ABSENT' END as att
                FROM members m
                LEFT JOIN attendance a ON a.member_id = m.id AND a.session_id = ?
                ORDER BY m.full_name ASC
            ");
            $stmt->execute([$sid]);
            while ($row = $stmt->fetch()) { fputcsv($output, $row); }
            exit;
        }

        // TOOL B: Cross-Session Comparison
        elseif ($type === 'compare_custom') {
            $sid1 = $_GET['session_a'];
            $sid2 = $_GET['session_b'];
            if (!$sid1 || !$sid2) die("Error: Select two sessions.");

            header('Content-Type: text/csv; charset=utf-8');  
            header('Content-Disposition: attachment; filename=Comparison_Report.csv');  
            $output = fopen('php://output', 'w');  
            fputcsv($output, ['Athlete Name', 'Status in A', 'Status in B', 'Insight']);

            $stmt = $pdo->prepare("
                SELECT m.full_name, 
                CASE WHEN a1.id IS NOT NULL THEN 'PRESENT' ELSE 'ABSENT' END as stat_a,
                CASE WHEN a2.id IS NOT NULL THEN 'PRESENT' ELSE 'ABSENT' END as stat_b
                FROM members m
                LEFT JOIN attendance a1 ON a1.member_id = m.id AND a1.session_id = ?
                LEFT JOIN attendance a2 ON a2.member_id = m.id AND a2.session_id = ?
                ORDER BY m.full_name ASC
            ");
            $stmt->execute([$sid1, $sid2]);

            while ($row = $stmt->fetch()) {
                $insight = "Inconsistent";
                if($row['stat_a'] === $row['stat_b']) $insight = ($row['stat_a'] === 'PRESENT' ? "Always Present" : "Always Absent");
                fputcsv($output, [$row['full_name'], $row['stat_a'], $row['stat_b'], $insight]);
            }
            exit;
        } 
        
        // TOOL C: Daily Standard Report
        else {
            $date = $_GET['export_date'] ?? date('Y-m-d');  
            header('Content-Type: text/csv; charset=utf-8');  
            header('Content-Disposition: attachment; filename=Daily_Report.csv');  
            $output = fopen('php://output', 'w');  
            fputcsv($output, ['Athlete Name', 'Status', 'Date']);  
            $stmt = $pdo->prepare("SELECT m.full_name, CASE WHEN a.id IS NOT NULL THEN 'PRESENT' ELSE 'ABSENT' END as att FROM members m CROSS JOIN sessions s LEFT JOIN attendance a ON a.member_id = m.id AND a.session_id = s.id WHERE s.date = ? ORDER BY m.full_name ASC");  
            $stmt->execute([$date]);  
            while ($row = $stmt->fetch()) { fputcsv($output, [$row['full_name'], $row['att'], $date]); }  
            exit;
        }
    } catch (Exception $e) { die("Export Error: " . $e->getMessage()); }  
}

// 3. MAIN CONTROLLER
try {  
    $pdo = get_db_connection();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {  
        if (isset($_POST['save_member'])) { $pdo->prepare("INSERT INTO members(full_name) VALUES (?) ON CONFLICT DO NOTHING")->execute([trim($_POST['name'])]); }  
        if (isset($_POST['save_session'])) { $pdo->prepare("INSERT INTO sessions(name, date) VALUES (?, ?)")->execute([trim($_POST['s_name']), $_POST['s_date']]); }  
        if (isset($_POST['mark'])) { $pdo->prepare("INSERT INTO attendance(session_id, member_id) VALUES (?, ?) ON CONFLICT DO NOTHING")->execute([$_POST['sid'], $_POST['mid']]); }  
        header("Location: index.php?session=" . ($_POST['sid'] ?? '')); exit;  
    }  

    if (isset($_GET['action']) && $_GET['action'] === 'unmark') {  
        $pdo->prepare("DELETE FROM attendance WHERE member_id = ? AND session_id = ?")->execute([$_GET['mid'], $_GET['sid']]);  
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
    <title>Ujamaa Academy v5.6</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style> body { font-family: 'Inter', sans-serif; background: #f8fafc; } </style>
</head>
<body class="antialiased">

<div class="flex flex-col lg:flex-row min-h-screen">
    <aside class="w-full lg:w-80 bg-slate-900 p-8 text-white">
        <div class="mb-12">
            <h1 class="text-2xl font-black italic">UJAMAA<span class="text-indigo-500">.</span></h1>
            <p class="text-[10px] uppercase text-slate-500 font-bold">Registry v5.6</p>
        </div>

        <nav class="space-y-3">
            <button onclick="window.location.href='index.php'" class="w-full text-left p-4 rounded-2xl transition hover:bg-slate-800 font-bold <?= !$current_sid ? 'bg-indigo-600' : 'text-slate-400' ?>">Dashboard</button>
            <button onclick="toggleView('export')" class="w-full text-left p-4 rounded-2xl transition hover:bg-slate-800 font-bold text-slate-400">Intelligence Center</button>
        </nav>
    </aside>

    <main class="flex-1 p-6 lg:p-12">
        
        <div id="view-main">
            <?php if(!$current_sid): ?>
                <div class="max-w-4xl mx-auto grid grid-cols-1 md:grid-cols-2 gap-8">
                    <div class="bg-white p-8 rounded-[3rem] shadow-sm border">
                        <h3 class="text-xl font-bold mb-6">Start New Session</h3>
                        <form method="POST" class="space-y-4">
                            <input name="s_name" placeholder="Session Title" class="w-full p-4 bg-slate-50 border rounded-2xl outline-none" required>
                            <input type="date" name="s_date" value="<?= date('Y-m-d') ?>" class="w-full p-4 bg-slate-50 border rounded-2xl">
                            <button name="save_session" class="w-full bg-slate-900 text-white p-4 rounded-2xl font-black uppercase text-xs">Initialize</button>
                        </form>
                    </div>
                    <div class="bg-white p-8 rounded-[3rem] shadow-sm border">
                        <h3 class="text-xl font-bold mb-6">Recent Records</h3>
                        <div class="space-y-2">
                            <?php foreach(array_slice($sessions, 0, 6) as $s): ?>
                                <a href="?session=<?= $s['id'] ?>" class="flex justify-between p-4 bg-slate-50 rounded-2xl hover:bg-indigo-50 transition">
                                    <span class="font-bold"><?= htmlspecialchars($s['name']) ?></span>
                                    <span class="text-[10px] text-slate-400"><?= $s['date'] ?></span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <header class="mb-10 flex justify-between items-center bg-white p-6 rounded-[2rem] border shadow-sm">
                    <h2 class="text-2xl font-black"><?= htmlspecialchars($active_session['name']) ?></h2>
                    <button onclick="window.location.href='index.php'" class="bg-slate-100 px-6 py-2 rounded-xl text-xs font-bold text-slate-500">Change Session</button>
                </header>

                <div class="bg-white rounded-[2.5rem] shadow-sm border overflow-hidden">
                    <div class="p-6 border-b bg-slate-50/50 flex justify-between">
                        <input type="text" id="searchInput" onkeyup="searchAthletes()" placeholder="Filter athletes..." class="p-3 bg-white border rounded-xl text-sm w-80 outline-none">
                        <p class="text-xs font-black text-slate-400 uppercase self-center">Present: <?= count($attended_ids) ?></p>
                    </div>
                    <table class="w-full">
                        <tbody class="divide-y divide-slate-100" id="athlete-rows">
                            <?php foreach($members as $m): $isPresent = in_array($m['id'], $attended_ids); ?>
                            <tr>
                                <td class="px-10 py-5 font-bold text-slate-800 athlete-name"><?= htmlspecialchars($m['full_name']) ?></td>
                                <td class="px-10 py-5 text-right">
                                    <?php if($isPresent): ?>
                                        <a href="?action=unmark&mid=<?= $m['id'] ?>&sid=<?= $current_sid ?>" class="bg-emerald-500 text-white px-8 py-2 rounded-full text-[10px] font-black uppercase">Present</a>
                                    <?php else: ?>
                                        <form method="POST"><input type="hidden" name="sid" value="<?= $current_sid ?>"><input type="hidden" name="mid" value="<?= $m['id'] ?>"><button name="mark" class="border-2 px-8 py-2 rounded-full text-[10px] font-black uppercase">Mark Present</button></form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <div id="view-export" class="hidden space-y-8">
            <h2 class="text-3xl font-black">Intelligence Center</h2>
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                
                <div class="bg-white p-8 rounded-[2.5rem] border shadow-sm">
                    <h3 class="text-lg font-black mb-4">Session Snapshot</h3>
                    <p class="text-slate-500 text-xs mb-6">Download Present/Absent report for one specific session.</p>
                    <form method="GET" class="space-y-4">
                        <input type="hidden" name="export_type" value="single_session">
                        <select name="target_session" class="w-full p-4 bg-slate-50 border rounded-2xl text-sm" required>
                            <option value="">Select Session...</option>
                            <?php foreach($sessions as $s): ?><option value="<?= $s['id'] ?>"><?= $s['date'] ?> - <?= $s['name'] ?></option><?php endforeach; ?>
                        </select>
                        <button type="submit" class="w-full bg-indigo-600 text-white p-4 rounded-2xl font-black text-[10px] uppercase">Download Snapshot</button>
                    </form>
                </div>

                <div class="bg-indigo-900 p-8 rounded-[2.5rem] text-white shadow-xl">
                    <h3 class="text-lg font-black mb-4">Consistency Report</h3>
                    <form method="GET" class="space-y-4">
                        <input type="hidden" name="export_type" value="compare_custom">
                        <select name="session_a" class="w-full p-4 bg-indigo-950 border-none rounded-2xl text-xs text-white" required><option value="">Baseline Session...</option><?php foreach($sessions as $s): ?><option value="<?= $s['id'] ?>"><?= $s['date'] ?> - <?= $s['name'] ?></option><?php endforeach; ?></select>
                        <select name="session_b" class="w-full p-4 bg-indigo-950 border-none rounded-2xl text-xs text-white" required><option value="">Comparison Session...</option><?php foreach($sessions as $s): ?><option value="<?= $s['id'] ?>"><?= $s['date'] ?> - <?= $s['name'] ?></option><?php endforeach; ?></select>
                        <button type="submit" class="w-full bg-amber-400 text-amber-950 p-4 rounded-2xl font-black text-[10px] uppercase">Compare Analytics</button>
                    </form>
                </div>

                <div class="bg-white p-8 rounded-[2.5rem] border shadow-sm">
                    <h3 class="text-lg font-black mb-4">Daily Export</h3>
                    <form method="GET" class="space-y-4">
                        <input type="hidden" name="export_type" value="daily">
                        <input type="date" name="export_date" value="<?= date('Y-m-d') ?>" class="w-full p-4 bg-slate-50 border rounded-2xl text-sm">
                        <button type="submit" class="w-full bg-slate-900 text-white p-4 rounded-2xl font-black text-[10px] uppercase">Download Daily CSV</button>
                    </form>
                </div>

            </div>
        </div>
    </main>
</div>

<script>
    function toggleView(v) {
        document.getElementById('view-main').classList.add('hidden');
        document.getElementById('view-export').classList.add('hidden');
        document.getElementById('view-' + v).classList.remove('hidden');
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
