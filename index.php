<?php  
/** * UJAMAA ACADEMY - ENTERPRISE EDITION V6.2  
 * Full CRUD: Edit/Delete Athletes, Quick Mark, & Reporting Intelligence
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
        if ($_GET['export_type'] === 'single') {
            $sid = $_GET['sid'];
            $s_stmt = $pdo->prepare("SELECT name, date FROM sessions WHERE id = ?");
            $s_stmt->execute([$sid]); $s = $s_stmt->fetch();
            header('Content-Type: text/csv'); header('Content-Disposition: attachment; filename=Session_Report.csv');  
            $output = fopen('php://output', 'w');  
            fputcsv($output, ["SESSION: {$s['name']} ({$s['date']})"]);
            fputcsv($output, ['Athlete Name', 'Status']);
            $stmt = $pdo->prepare("SELECT m.full_name, CASE WHEN a.id IS NOT NULL THEN 'PRESENT' ELSE 'ABSENT' END FROM members m LEFT JOIN attendance a ON a.member_id = m.id AND a.session_id = ? ORDER BY m.full_name ASC");
            $stmt->execute([$sid]);
            while ($row = $stmt->fetch()) { fputcsv($output, $row); }
            exit;
        }
    } catch (Exception $e) { die("Export Error"); }  
}

// 3. MAIN CONTROLLER
try {  
    $pdo = get_db_connection();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {  
        // Create Athlete
        if (isset($_POST['save_athlete'])) {
            $pdo->prepare("INSERT INTO members(full_name) VALUES (?) ON CONFLICT DO NOTHING")->execute([trim($_POST['full_name'])]);
        }
        // Update Athlete
        if (isset($_POST['update_athlete'])) {
            $pdo->prepare("UPDATE members SET full_name = ? WHERE id = ?")->execute([trim($_POST['full_name']), $_POST['mid']]);
        }
        // Delete Athlete
        if (isset($_POST['delete_athlete'])) {
            $pdo->prepare("DELETE FROM members WHERE id = ?")->execute([$_POST['mid']]);
        }
        // Create Session
        if (isset($_POST['save_session'])) { 
            $pdo->prepare("INSERT INTO sessions(name, date) VALUES (?, ?)")->execute([trim($_POST['s_name']), $_POST['s_date']]); 
        }  
        // Mark Attendance
        if (isset($_POST['mark'])) { 
            $pdo->prepare("INSERT INTO attendance(session_id, member_id) VALUES (?, ?) ON CONFLICT DO NOTHING")->execute([$_POST['sid'], $_POST['mid']]); 
        }  
        header("Location: index.php?session=" . ($_POST['sid'] ?? '')); exit;  
    }  

    if (isset($_GET['action']) && $_GET['action'] === 'unmark') {  
        $pdo->prepare("DELETE FROM attendance WHERE member_id = ? AND session_id = ?")->execute([$_GET['mid'], $_GET['sid']]);  
        header("Location: index.php?session=" . $_GET['sid']); exit;  
    }

    $sessions = $pdo->query("SELECT * FROM sessions ORDER BY date DESC, id DESC LIMIT 50")->fetchAll();  
    $current_sid = $_GET['session'] ?? ($sessions[0]['id'] ?? null);
    $active_s = null; foreach($sessions as $s) if($s['id'] == $current_sid) $active_s = $s;
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
    <title>Ujamaa Academy v6.2</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;700;900&display=swap" rel="stylesheet">
    <style> body { font-family: 'Inter', sans-serif; background: #f8fafc; } .modal { transition: opacity 0.25s ease; } </style>
</head>
<body class="antialiased">

<div class="flex flex-col lg:flex-row min-h-screen">
    <aside class="w-full lg:w-80 bg-slate-900 p-8 text-white flex flex-col">
        <div class="mb-10 text-center lg:text-left">
            <h1 class="text-3xl font-black italic tracking-tighter">UJAMAA<span class="text-indigo-500">.</span></h1>
            <p class="text-[10px] uppercase text-slate-500 font-bold tracking-widest">Admin v6.2</p>
        </div>
        
        <nav class="space-y-3 mb-10">
            <button onclick="showTab('marking')" id="nav-marking" class="w-full text-left p-4 rounded-2xl font-bold bg-indigo-600 shadow-lg">Attendance Hub</button>
            <button onclick="showTab('reports')" id="nav-reports" class="w-full text-left p-4 rounded-2xl font-bold text-slate-400 hover:bg-slate-800 transition">Report Center</button>
        </nav>

        <div class="mt-auto bg-slate-800/50 p-6 rounded-3xl border border-slate-700/50">
            <h3 class="text-xs font-black uppercase text-indigo-400 mb-4 tracking-widest">Quick Register</h3>
            <form method="POST" class="space-y-3">
                <input name="full_name" placeholder="Name & Surname" class="w-full bg-slate-900 border border-slate-700 p-3 rounded-xl text-sm outline-none" required>
                <input type="hidden" name="sid" value="<?= $current_sid ?>">
                <button name="save_athlete" class="w-full bg-white text-slate-900 py-3 rounded-xl font-black text-[10px] uppercase tracking-widest hover:bg-indigo-50 transition">Register</button>
            </form>
        </div>
    </aside>

    <main class="flex-1 p-6 lg:p-12">
        <div id="tab-marking">
            <header class="flex flex-col md:flex-row justify-between items-start md:items-center mb-10 gap-6">
                <div>
                    <h2 class="text-3xl font-black text-slate-900"><?= $active_s ? htmlspecialchars($active_s['name']) : 'Select Session' ?></h2>
                    <p class="text-slate-400 font-bold"><?= $active_s ? $active_s['date'] : 'No Session Selected' ?></p>
                </div>
                <div class="flex items-center gap-3 w-full md:w-auto">
                    <select onchange="location.href='?session='+this.value" class="flex-1 md:flex-none bg-white border rounded-xl px-4 py-3 font-bold text-sm shadow-sm">
                        <?php foreach($sessions as $s): ?>
                            <option value="<?= $s['id'] ?>" <?= $current_sid == $s['id'] ? 'selected' : '' ?>><?= $s['date'] ?> | <?= htmlspecialchars($s['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button onclick="document.getElementById('modal-s').classList.remove('hidden')" class="bg-indigo-600 text-white w-12 h-12 rounded-xl font-bold">+</button>
                </div>
            </header>

            <div class="mb-6"><input type="text" id="athleteSearch" onkeyup="doSearch()" placeholder="Search athlete..." class="w-full p-5 bg-white border-2 border-slate-100 rounded-3xl text-lg font-medium outline-none shadow-sm"></div>

            <div class="bg-white rounded-[2.5rem] shadow-sm border border-slate-200 overflow-hidden">
                <div class="max-h-[600px] overflow-y-auto" id="athlete-registry">
                    <?php foreach($members as $m): $isPresent = in_array($m['id'], $attended_ids); ?>
                    <div class="athlete-row flex items-center justify-between px-8 py-5 border-b border-slate-50 hover:bg-slate-50 transition">
                        <div class="flex flex-col">
                            <span class="font-bold text-lg text-slate-800 athlete-name"><?= htmlspecialchars($m['full_name']) ?></span>
                            <div class="flex gap-4 mt-1">
                                <button onclick="openEditModal(<?= $m['id'] ?>, '<?= addslashes($m['full_name']) ?>')" class="text-[10px] font-black uppercase text-slate-400 hover:text-indigo-600 transition">Edit</button>
                                <button onclick="openDeleteModal(<?= $m['id'] ?>, '<?= addslashes($m['full_name']) ?>')" class="text-[10px] font-black uppercase text-slate-400 hover:text-red-600 transition">Delete</button>
                            </div>
                        </div>
                        <div>
                            <?php if($isPresent): ?>
                                <a href="?action=unmark&mid=<?= $m['id'] ?>&sid=<?= $current_sid ?>" class="bg-emerald-500 text-white px-8 py-3 rounded-2xl text-[10px] font-black uppercase shadow-lg shadow-emerald-100">Present</a>
                            <?php else: ?>
                                <form method="POST"><input type="hidden" name="sid" value="<?= $current_sid ?>"><input type="hidden" name="mid" value="<?= $m['id'] ?>"><button name="mark" class="border-2 border-slate-200 text-slate-400 px-8 py-3 rounded-2xl text-[10px] font-black uppercase hover:border-indigo-600 hover:text-indigo-600">Mark</button></form>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div id="tab-reports" class="hidden"><h2 class="text-4xl font-black mb-8">Reports</h2><div class="bg-white p-10 rounded-[3rem] border"><form method="GET"><input type="hidden" name="export_type" value="single"><select name="sid" class="w-full p-4 bg-slate-50 border rounded-2xl mb-4"><?php foreach($sessions as $s): ?><option value="<?= $s['id'] ?>"><?= $s['date'] ?> - <?= $s['name'] ?></option><?php endforeach; ?></select><button class="w-full bg-slate-900 text-white p-4 rounded-2xl font-black uppercase text-xs">Export CSV</button></form></div></div>
    </main>
</div>

<div id="modal-edit" class="hidden fixed inset-0 bg-slate-900/90 backdrop-blur flex items-center justify-center p-6 z-50">
    <div class="bg-white p-10 rounded-[3rem] w-full max-w-md shadow-2xl">
        <h3 class="text-2xl font-black mb-6">Edit Athlete</h3>
        <form method="POST" class="space-y-4">
            <input type="hidden" name="mid" id="edit-mid">
            <input type="hidden" name="sid" value="<?= $current_sid ?>">
            <input name="full_name" id="edit-name" class="w-full p-4 bg-slate-50 border rounded-2xl outline-none" required>
            <button name="update_athlete" class="w-full bg-indigo-600 text-white p-5 rounded-2xl font-black uppercase text-sm">Save Changes</button>
            <button type="button" onclick="document.getElementById('modal-edit').classList.add('hidden')" class="w-full p-4 text-slate-400 font-bold">Cancel</button>
        </form>
    </div>
</div>

<div id="modal-delete" class="hidden fixed inset-0 bg-slate-900/90 backdrop-blur flex items-center justify-center p-6 z-50">
    <div class="bg-white p-10 rounded-[3rem] w-full max-w-md shadow-2xl">
        <h3 class="text-2xl font-black mb-2 text-red-600">Delete Athlete?</h3>
        <p class="text-slate-500 mb-6 text-sm">Are you sure you want to delete <span id="delete-name" class="font-bold text-slate-900"></span>? This will wipe all their attendance history.</p>
        <form method="POST" class="space-y-4">
            <input type="hidden" name="mid" id="delete-mid">
            <input type="hidden" name="sid" value="<?= $current_sid ?>">
            <button name="delete_athlete" class="w-full bg-red-600 text-white p-5 rounded-2xl font-black uppercase text-sm">Yes, Permanently Delete</button>
            <button type="button" onclick="document.getElementById('modal-delete').classList.add('hidden')" class="w-full p-4 text-slate-400 font-bold">Cancel</button>
        </form>
    </div>
</div>

<div id="modal-s" class="hidden fixed inset-0 bg-slate-900/90 flex items-center justify-center p-6 z-50"><div class="bg-white p-10 rounded-[3rem] w-full max-w-md"><h3 class="text-2xl font-black mb-6">Create Session</h3><form method="POST" class="space-y-4"><input name="s_name" placeholder="Session Title" class="w-full p-4 bg-slate-50 border rounded-2xl outline-none" required><input type="date" name="s_date" value="<?= date('Y-m-d') ?>" class="w-full p-4 bg-slate-50 border rounded-2xl"><button name="save_session" class="w-full bg-indigo-600 text-white p-5 rounded-2xl font-black uppercase text-sm">Start</button><button type="button" onclick="document.getElementById('modal-s').classList.add('hidden')" class="w-full p-4 text-slate-400 font-bold">Cancel</button></form></div></div>

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
            row.style.display = row.querySelector(".athlete-name").innerText.toLowerCase().includes(query) ? "flex" : "none";
        });
    }
    function openEditModal(id, name) {
        document.getElementById('edit-mid').value = id;
        document.getElementById('edit-name').value = name;
        document.getElementById('modal-edit').classList.remove('hidden');
    }
    function openDeleteModal(id, name) {
        document.getElementById('delete-mid').value = id;
        document.getElementById('delete-name').innerText = name;
        document.getElementById('modal-delete').classList.remove('hidden');
    }
</script>
</body>
</html>
