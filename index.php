<?php
/**
 * UJAMAA ACADEMY - ATTENDANCE & REPORTING SYSTEM
 * Lead Dev: Senior Architect Refactor
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);

// --- DATABASE CONFIGURATION ---
$databaseUrl = getenv("DATABASE_URL");
if (!$databaseUrl) die("CRITICAL: DATABASE_URL environment variable is missing.");

$url = parse_url($databaseUrl);
$host = $url['host'] ?? 'localhost';
$port = $url['port'] ?? 5432;
$user = $url['user'] ?? '';
$pass = $url['pass'] ?? '';
$dbName = ltrim($url['path'] ?? '', '/');

$dsn = "pgsql:host=$host;port=$port;dbname=$dbName;sslmode=require";

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    // Initial Schema Setup
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS members (id SERIAL PRIMARY KEY, full_name TEXT UNIQUE, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP);
        CREATE TABLE IF NOT EXISTS sessions (id SERIAL PRIMARY KEY, name TEXT, date DATE DEFAULT CURRENT_DATE);
        CREATE TABLE IF NOT EXISTS attendance (id SERIAL PRIMARY KEY, session_id INT REFERENCES sessions(id), member_id INT REFERENCES members(id), marked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE(session_id, member_id));
    ");

    // --- REPORT GENERATION LOGIC (CSV EXPORT) ---
    if (isset($_GET['export'])) {
        $type = $_GET['export'];
        $filename = "ujamaa_report_" . date('Y-m-d');
        
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
        
        $output = fopen('php://output', 'w');
        
        if ($type === 'session' && isset($_GET['session'])) {
            // Report for one specific session
            fputcsv($output, ['Member Name', 'Session', 'Date', 'Status']);
            $stmt = $pdo->prepare("
                SELECT m.full_name, s.name as session_name, s.date, 
                CASE WHEN a.id IS NOT NULL THEN 'Present' ELSE 'Absent' END as status
                FROM members m
                CROSS JOIN sessions s
                LEFT JOIN attendance a ON a.member_id = m.id AND a.session_id = s.id
                WHERE s.id = ?
                ORDER BY m.full_name ASC
            ");
            $stmt->execute([$_GET['session']]);
        } else {
            // Daily Master Report (All sessions today)
            fputcsv($output, ['Date', 'Session', 'Member Name', 'Status']);
            $stmt = $pdo->prepare("
                SELECT s.date, s.name as session_name, m.full_name,
                CASE WHEN a.id IS NOT NULL THEN 'Present' ELSE 'Absent' END as status
                FROM sessions s
                CROSS JOIN members m
                LEFT JOIN attendance a ON a.member_id = m.id AND a.session_id = s.id
                WHERE s.date = CURRENT_DATE
                ORDER BY s.id, m.full_name
            ");
            $stmt->execute();
        }

        while ($row = $stmt->fetch()) fputcsv($output, $row);
        fclose($output);
        exit;
    }

    // --- DATA MUTATIONS ---
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['add_member']) && !empty(trim($_POST['name']))) {
            $stmt = $pdo->prepare("INSERT INTO members(full_name) VALUES (?) ON CONFLICT DO NOTHING");
            $stmt->execute([trim($_POST['name'])]);
        }
        if (isset($_POST['create_session']) && !empty(trim($_POST['session']))) {
            $stmt = $pdo->prepare("INSERT INTO sessions(name) VALUES (?)");
            $stmt->execute([trim($_POST['session'])]);
        }
        if (isset($_POST['mark'])) {
            $stmt = $pdo->prepare("INSERT INTO attendance(session_id, member_id) VALUES (?, ?) ON CONFLICT DO NOTHING");
            $stmt->execute([$_POST['session_id'], $_POST['member_id']]);
        }
        // Redirect to prevent form resubmission
        header("Location: " . $_SERVER['REQUEST_URI']);
        exit;
    }

    // --- DATA RETRIEVAL ---
    $current_session_id = $_GET['session'] ?? null;
    $sessions = $pdo->query("SELECT * FROM sessions ORDER BY date DESC, id DESC")->fetchAll();
    if (!$current_session_id && !empty($sessions)) $current_session_id = $sessions[0]['id'];

    $members = $pdo->query("SELECT * FROM members ORDER BY full_name ASC")->fetchAll();
    
    $attended_ids = [];
    if ($current_session_id) {
        $stmt = $pdo->prepare("SELECT member_id FROM attendance WHERE session_id = ?");
        $stmt->execute([$current_session_id]);
        $attended_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

} catch (Exception $e) {
    die("Runtime Error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ujamaa Admin | Intelligence</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; background: #f8fafc; }
        .glass { background: rgba(255, 255, 255, 0.9); backdrop-filter: blur(10px); }
        .custom-scroll::-webkit-scrollbar { width: 5px; }
        .custom-scroll::-webkit-scrollbar-thumb { background: #e2e8f0; border-radius: 10px; }
    </style>
</head>
<body class="p-4 md:p-8">

    <div class="max-w-6xl mx-auto grid grid-cols-1 lg:grid-cols-12 gap-8">
        
        <!-- SIDEBAR / CONTROLS -->
        <div class="lg:col-span-4 space-y-6">
            <div class="bg-blue-600 rounded-3xl p-6 text-white shadow-xl shadow-blue-200">
                <h1 class="text-2xl font-800 tracking-tight">UJAMAA ACADEMY</h1>
                <p class="text-blue-100 text-sm mt-1">Intelligence Dashboard</p>
                
                <div class="mt-8 space-y-2">
                    <label class="text-[10px] font-bold uppercase tracking-widest text-blue-200">Export Reports</label>
                    <div class="grid grid-cols-2 gap-2">
                        <a href="?export=session&session=<?= $current_session_id ?>" class="bg-white/10 hover:bg-white/20 text-center py-3 rounded-xl text-xs font-bold transition">Session CSV</a>
                        <a href="?export=daily" class="bg-white/10 hover:bg-white/20 text-center py-3 rounded-xl text-xs font-bold transition">Daily Master</a>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-3xl p-6 shadow-sm border border-slate-100">
                <label class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-4 block">New Entry</label>
                <form method="POST" class="space-y-3">
                    <input name="name" placeholder="Athlete Full Name" class="w-full px-4 py-3 rounded-xl border border-slate-200 focus:ring-2 focus:ring-blue-500 outline-none text-sm" required>
                    <button name="add_member" class="w-full bg-slate-900 text-white py-3 rounded-xl font-bold text-sm hover:bg-black transition">Register Athlete</button>
                </form>

                <div class="my-6 border-t border-slate-100"></div>

                <form method="POST" class="space-y-3">
                    <input name="session" placeholder="Session Title (e.g. Morning Drill)" class="w-full px-4 py-3 rounded-xl border border-slate-200 focus:ring-2 focus:ring-blue-500 outline-none text-sm" required>
                    <button name="create_session" class="w-full bg-blue-50 text-blue-600 py-3 rounded-xl font-bold text-sm hover:bg-blue-100 transition">Open New Session</button>
                </form>
            </div>
        </div>

        <!-- MAIN CONTENT -->
        <div class="lg:col-span-8 space-y-6">
            
            <div class="bg-white rounded-3xl p-6 shadow-sm border border-slate-100">
                <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-6">
                    <div>
                        <h2 class="text-xl font-bold text-slate-800">Attendance Registry</h2>
                        <p class="text-slate-500 text-sm">Managing <?= count($members) ?> athletes</p>
                    </div>
                    <select onchange="window.location.href='?session='+this.value" class="bg-slate-50 border-none rounded-xl px-4 py-2 text-sm font-semibold text-slate-700 outline-none ring-1 ring-slate-200">
                        <?php foreach($sessions as $s): ?>
                            <option value="<?= $s['id'] ?>" <?= $current_session_id == $s['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($s['name']) ?> (<?= $s['date'] ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Search UI -->
                <div class="relative mb-4">
                    <span class="absolute inset-y-0 left-4 flex items-center text-slate-400">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                    </span>
                    <input id="searchBar" onkeyup="searchList()" placeholder="Quick search athlete..." class="w-full pl-12 pr-4 py-3 bg-slate-50 border-none rounded-2xl text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                </div>

                <div class="custom-scroll overflow-y-auto max-h-[500px] pr-2">
                    <table class="w-full text-left">
                        <thead>
                            <tr class="text-[10px] text-slate-400 uppercase tracking-widest">
                                <th class="pb-4 font-bold">Athlete</th>
                                <th class="pb-4 font-bold text-right">Action</th>
                            </tr>
                        </thead>
                        <tbody id="memberTable">
                            <?php foreach($members as $m): ?>
                            <tr class="member-row border-t border-slate-50" data-name="<?= strtolower(htmlspecialchars($m['full_name'])) ?>">
                                <td class="py-4 font-semibold text-slate-700"><?= htmlspecialchars($m['full_name']) ?></td>
                                <td class="py-4 text-right">
                                    <?php if(in_array($m['id'], $attended_ids)): ?>
                                        <span class="text-emerald-500 font-bold text-xs bg-emerald-50 px-3 py-1 rounded-full">✔ PRESENT</span>
                                    <?php else: ?>
                                        <form method="POST">
                                            <input type="hidden" name="member_id" value="<?= $m['id'] ?>">
                                            <input type="hidden" name="session_id" value="<?= $current_session_id ?>">
                                            <button name="mark" class="text-blue-600 font-bold text-xs hover:underline">MARK PRESENT</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>

    <script>
        function searchList() {
            let input = document.getElementById('searchBar').value.toLowerCase();
            let rows = document.querySelectorAll('.member-row');
            rows.forEach(row => {
                let name = row.getAttribute('data-name');
                row.style.display = name.includes(input) ? '' : 'none';
            });
        }
    </script>
</body>
</html>
