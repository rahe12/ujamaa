<?php  
/** * UJAMAA ACADEMY - APEX EDITION V7.5
 * Full CRUD, Search, Consistency, and Advanced Multi-Session Intelligence
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

// 2. ADVANCED REPORTING ENGINE
if (isset($_GET['export_type'])) {  
    try {  
        $pdo = get_db_connection();
        $type = $_GET['export_type'];

        // A. FILTERED STATUS (PRESENT OR ABSENT ONLY)
        if ($type === 'filtered_status') {
            $sid = $_GET['sid'];
            $status_filter = $_GET['status']; // 'PRESENT' or 'ABSENT'
            header('Content-Type: text/csv'); header("Content-Disposition: attachment; filename=Session_{$status_filter}_List.csv");  
            $output = fopen('php://output', 'w');  
            fputcsv($output, ["LIST OF ATHLETES: $status_filter"]);
            
            $query = ($status_filter === 'PRESENT') 
                ? "SELECT m.full_name FROM members m JOIN attendance a ON a.member_id = m.id WHERE a.session_id = ? ORDER BY m.full_name ASC"
                : "SELECT m.full_name FROM members m WHERE m.id NOT IN (SELECT member_id FROM attendance WHERE session_id = ?) ORDER BY m.full_name ASC";
            
            $stmt = $pdo->prepare($query); $stmt->execute([$sid]);
            while ($row = $stmt->fetch()) { fputcsv($output, $row); }
            exit;
        }

        // B. UNIQUE ATTENDEES (AT LEAST ONCE - NO DUPLICATES)
        if ($type === 'unique_attendees') {
            $session_ids = $_GET['sids'] ?? [];
            if(empty($session_ids)) die("Select at least one session");
            $placeholders = implode(',', array_fill(0, count($session_ids), '?'));
            
            header('Content-Type: text/csv'); header('Content-Disposition: attachment; filename=Unique_Attendees.csv');  
            $output = fopen('php://output', 'w');  
            fputcsv($output, ["Athletes who attended at least one of the selected sessions"]);
            
            $stmt = $pdo->prepare("SELECT DISTINCT m.full_name FROM members m JOIN attendance a ON a.member_id = m.id WHERE a.session_id IN ($placeholders) ORDER BY m.full_name ASC");
            $stmt->execute($session_ids);
            while ($row = $stmt->fetch()) { fputcsv($output, $row); }
            exit;
        }

        // C. MASTER MULTI-SESSION LIST (EVERYONE INVOLVED)
        if ($type === 'master_list') {
            $session_ids = $_GET['sids'] ?? [];
            if(empty($session_ids)) die("Select sessions");
            $placeholders = implode(',', array_fill(0, count($session_ids), '?'));
            
            header('Content-Type: text/csv'); header('Content-Disposition: attachment; filename=Master_Attendance_List.csv');  
            $output = fopen('php://output', 'w');  
            fputcsv($output, ['Athlete Name', 'Total Sessions Attended']);
            
            $stmt = $pdo->prepare("SELECT m.full_name, COUNT(a.id) as total FROM members m JOIN attendance a ON a.member_id = m.id WHERE a.session_id IN ($placeholders) GROUP BY m.full_name ORDER BY total DESC, m.full_name ASC");
            $stmt->execute($session_ids);
            while ($row = $stmt->fetch()) { fputcsv($output, $row); }
            exit;
        }
        
        // D. CONSISTENCY (PRE-EXISTING)
        if ($type === 'consistency') {
            header('Content-Type: text/csv'); header('Content-Disposition: attachment; filename=Consistency.csv');  
            $output = fopen('php://output', 'w');  
            fputcsv($output, ['Athlete', 'Session A', 'Session B', 'Logic']);
            $stmt = $pdo->prepare("SELECT m.full_name, CASE WHEN a1.id IS NOT NULL THEN 'PRESENT' ELSE 'ABSENT' END as sA, CASE WHEN a2.id IS NOT NULL THEN 'PRESENT' ELSE 'ABSENT' END as sB FROM members m LEFT JOIN attendance a1 ON a1.member_id = m.id AND a1.session_id = ? LEFT JOIN attendance a2 ON a2.member_id = m.id AND a2.session_id = ? ORDER BY m.full_name ASC");
            $stmt->execute([$_GET['sidA'], $_GET['sidB']]);
            while ($row = $stmt->fetch()) { fputcsv($output, $row); }
            exit;
        }

    } catch (Exception $e) { die("Export Error: " . $e->getMessage()); }  
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
} catch (Exception $e) { die("System Error"); }  
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ujamaa Academy | Apex v7.5</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;800&display=swap" rel="stylesheet">
    <style> body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: #f8fafc; } </style>
</head>
<body class="antialiased">

<div class="flex flex-col lg:flex-row min-h-screen">
    <aside class="w-full lg:w-80 bg-slate-900 p-8 text-white flex flex-col">
        <div class="mb-12"><h1 class="text-3xl font-extrabold italic italic">UJAMAA<span class="text-indigo-400">.</span></h1></div>
        <nav class="space-y-4 flex-1">
            <button onclick="view('marking')" id="n-marking" class="w-full text-left p-4 rounded-2xl font-bold bg-indigo-600 shadow-xl">Registry Hub</button>
            <button onclick="view('intel')" id="n-intel" class="w-full text-left p-4 rounded-2xl font-bold text-slate-400 hover:bg-slate-800 transition">Intelligence</button>
        </nav>
        <div class="bg-white/5 p-6 rounded-[2rem] border border-white/10 mt-10">
            <h3 class="text-[10px] font-black uppercase text-indigo-400 mb-3 tracking-widest">New Recruit</h3>
            <form method="POST" class="space-y-3">
                <input name="full_name" placeholder="Full Name" class="w-full bg-slate-950 border-none p-3 rounded-xl text-sm" required>
                <button name="save_athlete" class="w-full bg-white text-slate-900 py-3 rounded-xl font-black text-[10px] uppercase">Add Athlete</button>
            </form>
        </div>
    </aside>

    <main class="flex-1 p-6 lg:p-12 overflow-y-auto">
        
        <div id="v-marking" class="max-w-5xl mx-auto">
            <header class="flex justify-between items-center mb-10">
                <div><h2 class="text-3xl font-black text-slate-900"><?= $active_s ? htmlspecialchars($active_s['name']) : 'Dashboard' ?></h2><p class="text-slate-400 font-bold"><?= $active_s ? $active_s['date'] : '' ?></p></div>
                <div class="flex gap-2">
                    <select onchange="location.href='?session='+this.value" class="bg-white border-none rounded-xl px-4 py-3 font-bold text-sm shadow-sm ring-1 ring-slate-200">
                        <?php foreach($sessions as $s): ?><option value="<?= $s['id'] ?>" <?= $current_sid == $s['id'] ? 'selected' : '' ?>><?= $s['date'] ?> - <?= $s['name'] ?></option><?php endforeach; ?>
                    </select>
                    <button onclick="openModal('m-session')" class="bg-indigo-600 text-white w-12 h-12 rounded-xl font-bold shadow-lg">+</button>
                </div>
            </header>

            <input type="text" id="aSearch" onkeyup="search()" placeholder="Search athlete..." class="w-full p-5 bg-white rounded-3xl mb-8 shadow-sm ring-1 ring-slate-200 outline-none focus:ring-2 focus:ring-indigo-500">

            <div class="bg-white rounded-[2.5rem] shadow-sm ring-1 ring-slate-200 overflow-hidden">
                <?php foreach($members as $m): $isP = in_array($m['id'], $attended_ids); ?>
                <div class="a-row flex items-center justify-between px-10 py-5 border-b border-slate-50 last:border-0">
                    <div>
                        <span class="font-extrabold text-lg text-slate-800 a-name"><?= htmlspecialchars($m['full_name']) ?></span>
                        <div class="flex gap-3 mt-1">
                            <button onclick="editMember(<?= $m['id'] ?>, '<?= addslashes($m['full_name']) ?>')" class="text-[9px] font-black uppercase text-slate-400 hover:text-indigo-600">Edit</button>
                            <button onclick="deleteMember(<?= $m['id'] ?>, '<?= addslashes($m['full_name']) ?>')" class="text-[9px] font-black uppercase text-slate-400 hover:text-red-500">Delete</button>
                        </div>
                    </div>
                    <?php if($isP): ?>
                        <a href="?action=unmark&mid=<?= $m['id'] ?>&sid=<?= $current_sid ?>" class="bg-emerald-500 text-white px-8 py-3 rounded-2xl text-[10px] font-black uppercase shadow-lg shadow-emerald-100">Present</a>
                    <?php else: ?>
                        <form method="POST"><input type="hidden" name="sid" value="<?= $current_sid ?>"><input type="hidden" name="mid" value="<?= $m['id'] ?>"><button name="mark" class="border-2 border-slate-100 text-slate-400 px-8 py-3 rounded-2xl text-[10px] font-black uppercase hover:border-indigo-600">Mark</button></form>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div id="v-intel" class="hidden max-w-5xl mx-auto space-y-8">
            <h2 class="text-4xl font-black text-slate-900">Intelligence</h2>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div class="bg-white p-8 rounded-[2.5rem] shadow-sm border border-slate-100">
                    <h3 class="font-black text-indigo-600 uppercase text-xs tracking-widest mb-4">Strict Status Filter</h3>
                    <form method="GET" class="space-y-3">
                        <input type="hidden" name="export_type" value="filtered_status">
                        <select name="sid" class="w-full p-3 bg-slate-50 rounded-xl font-bold border-none ring-1 ring-slate-100">
                            <?php foreach($sessions as $s): ?><option value="<?= $s['id'] ?>"><?= $s['date'] ?> - <?= $s['name'] ?></option><?php endforeach; ?>
                        </select>
                        <select name="status" class="w-full p-3 bg-slate-50 rounded-xl font-bold border-none ring-1 ring-slate-100">
                            <option value="PRESENT">Show Only Present</option>
                            <option value="ABSENT">Show Only Absent</option>
                        </select>
                        <button class="w-full bg-slate-900 text-white p-4 rounded-xl font-black uppercase text-[10px]">Generate List</button>
                    </form>
                </div>

                <div class="bg-indigo-900 p-8 rounded-[2.5rem] text-white">
                    <h3 class="font-black text-indigo-300 uppercase text-xs tracking-widest mb-4">Consistency Comparison</h3>
                    <form method="GET" class="space-y-3">
                        <input type="hidden" name="export_type" value="consistency">
                        <div class="flex gap-2">
                            <select name="sidA" class="w-1/2 p-3 bg-white/10 rounded-xl text-xs border-none" required><option value="">Session A</option><?php foreach($sessions as $s): ?><option value="<?= $s['id'] ?>"><?= $s['date'] ?></option><?php endforeach; ?></select>
                            <select name="sidB" class="w-1/2 p-3 bg-white/10 rounded-xl text-xs border-none" required><option value="">Session B</option><?php foreach($sessions as $s): ?><option value="<?= $s['id'] ?>"><?= $s['date'] ?></option><?php endforeach; ?></select>
                        </div>
                        <button class="w-full bg-amber-400 text-amber-950 p-4 rounded-xl font-black uppercase text-[10px]">Analyze</button>
                    </form>
                </div>
            </div>

            <div class="bg-white p-10 rounded-[3rem] shadow-sm border border-slate-100">
                <h3 class="font-black text-slate-800 uppercase text-xs tracking-widest mb-6 text-center">Multi-Session Global Reports</h3>
                <form method="GET" id="multiForm">
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8 max-h-48 overflow-y-auto p-4 bg-slate-50 rounded-3xl">
                        <?php foreach($sessions as $s): ?>
                        <label class="flex items-center gap-2 bg-white p-3 rounded-xl border border-slate-200 cursor-pointer hover:border-indigo-500 transition">
                            <input type="checkbox" name="sids[]" value="<?= $s['id'] ?>" class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                            <span class="text-[10px] font-bold text-slate-600"><?= $s['date'] ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <div class="flex flex-col md:flex-row gap-4">
                        <button type="submit" name="export_type" value="unique_attendees" class="flex-1 bg-indigo-600 text-white p-5 rounded-2xl font-black uppercase text-[10px] shadow-lg shadow-indigo-100">Download Unique Attendee List (No Dups)</button>
                        <button type="submit" name="export_type" value="master_list" class="flex-1 bg-slate-900 text-white p-5 rounded-2xl font-black uppercase text-[10px] shadow-lg">Download Master Frequency List</button>
                    </div>
                </form>
            </div>
        </div>
    </main>
</div>

<div id="m-edit" class="hidden fixed inset-0 bg-slate-900/90 backdrop-blur-sm flex items-center justify-center p-6 z-50">
    <div class="bg-white p-8 rounded-[2.5rem] w-full max-w-md shadow-2xl">
        <h3 class="text-xl font-black mb-6">Modify Athlete</h3>
        <form method="POST" class="space-y-4">
            <input type="hidden" name="mid" id="e-mid">
            <input name="full_name" id="e-name" class="w-full p-4 bg-slate-50 rounded-xl outline-none border border-slate-100" required>
            <button name="update_athlete" class="w-full bg-indigo-600 text-white p-4 rounded-xl font-black uppercase text-xs">Save Update</button>
            <button type="button" onclick="closeModal('m-edit')" class="w-full text-slate-400 font-bold p-2">Cancel</button>
        </form>
    </div>
</div>

<div id="m-delete" class="hidden fixed inset-0 bg-slate-900/90 flex items-center justify-center p-6 z-50">
    <div class="bg-white p-8 rounded-[2.5rem] w-full max-w-md text-center">
        <h3 class="text-xl font-black mb-2 text-red-600">Delete Member?</h3>
        <p class="text-slate-500 mb-6 text-sm">Are you sure? This action is permanent.</p>
        <form method="POST">
            <input type="hidden" name="mid" id="d-mid">
            <button name="delete_athlete" class="w-full bg-red-600 text-white p-4 rounded-xl font-black uppercase text-xs">Yes, Delete</button>
            <button type="button" onclick="closeModal('m-delete')" class="w-full text-slate-400 font-bold p-2">Cancel</button>
        </form>
    </div>
</div>

<div id="m-session" class="hidden fixed inset-0 bg-slate-900/90 flex items-center justify-center p-6 z-50">
    <div class="bg-white p-8 rounded-[2.5rem] w-full max-w-md">
        <h3 class="text-xl font-black mb-6">New Session</h3>
        <form method="POST" class="space-y-4">
            <input name="s_name" placeholder="Session Title" class="w-full p-4 bg-slate-50 rounded-xl border-none ring-1 ring-slate-100" required>
            <input type="date" name="s_date" value="<?= date('Y-m-d') ?>" class="w-full p-4 bg-slate-50 rounded-xl border-none ring-1 ring-slate-100">
            <button name="save_session" class="w-full bg-indigo-600 text-white p-4 rounded-xl font-black uppercase text-xs">Create Session</button>
            <button type="button" onclick="closeModal('m-session')" class="w-full text-slate-400 font-bold p-2">Close</button>
        </form>
    </div>
</div>

<script>
    function view(id) {
        document.getElementById('v-marking').classList.toggle('hidden', id !== 'marking');
        document.getElementById('v-intel').classList.toggle('hidden', id !== 'intel');
        document.getElementById('n-marking').className = "w-full text-left p-4 rounded-2xl font-bold " + (id === 'marking' ? "bg-indigo-600 shadow-xl" : "text-slate-400 hover:bg-slate-800");
        document.getElementById('n-intel').className = "w-full text-left p-4 rounded-2xl font-bold " + (id === 'intel' ? "bg-indigo-600 shadow-xl" : "text-slate-400 hover:bg-slate-800");
    }
    function search() {
        const q = document.getElementById("aSearch").value.toLowerCase();
        document.querySelectorAll(".a-row").forEach(row => {
            row.style.display = row.querySelector(".a-name").innerText.toLowerCase().includes(q) ? "flex" : "none";
        });
    }
    function editMember(id, name) { document.getElementById('e-mid').value = id; document.getElementById('e-name').value = name; openModal('m-edit'); }
    function deleteMember(id, name) { document.getElementById('d-mid').value = id; openModal('m-delete'); }
    function openModal(id) { document.getElementById(id).classList.remove('hidden'); }
    function closeModal(id) { document.getElementById(id).classList.add('hidden'); }
</script>
</body>
</html>
