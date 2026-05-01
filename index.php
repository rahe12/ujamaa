<?php
/**
 * UJAMAA ACADEMY - ENTERPRISE EDITION V3
 * Features: Full CRUD (Athletes/Sessions), Financial Ledger, Advanced Reporting
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);

$databaseUrl = getenv("DATABASE_URL");
if (!$databaseUrl) die("Database configuration missing.");
$url = parse_url($databaseUrl);
$dsn = "pgsql:host={$url['host']};port=" . ($url['port'] ?? 5432) . ";dbname=" . ltrim($url['path'], '/') . ";sslmode=require";

try {
    $pdo = new PDO($dsn, $url['user'], $url['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

    // --- CONTROLLER: CRUD & POST ACTIONS ---
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Create/Update Athlete
        if (isset($_POST['save_member'])) {
            if (!empty($_POST['member_id'])) {
                $pdo->prepare("UPDATE members SET full_name = ? WHERE id = ?")->execute([trim($_POST['name']), $_POST['member_id']]);
            } else {
                $pdo->prepare("INSERT INTO members(full_name) VALUES (?) ON CONFLICT DO NOTHING")->execute([trim($_POST['name'])]);
            }
        }
        // Create/Update Session
        if (isset($_POST['save_session'])) {
            if (!empty($_POST['session_id'])) {
                $pdo->prepare("UPDATE sessions SET name = ?, date = ? WHERE id = ?")->execute([trim($_POST['session_name']), $_POST['session_date'], $_POST['session_id']]);
            } else {
                $pdo->prepare("INSERT INTO sessions(name, date) VALUES (?, ?)")->execute([trim($_POST['session_name']), $_POST['session_date']]);
            }
        }
        // Attendance & Payments
        if (isset($_POST['mark_attendance'])) $pdo->prepare("INSERT INTO attendance(session_id, member_id) VALUES (?, ?)")->execute([$_POST['session_id'], $_POST['member_id']]);
        if (isset($_POST['set_payment'])) $pdo->prepare("INSERT INTO payments (member_id, amount, due_date) VALUES (?, ?, ?)")->execute([$_POST['member_id'], $_POST['amount'], $_POST['due_date']]);
        
        header("Location: " . $_SERVER['REQUEST_URI']); exit;
    }

    // --- CONTROLLER: DELETE ACTIONS ---
    if (isset($_GET['delete_type']) && isset($_GET['id'])) {
        if ($_GET['delete_type'] === 'member') $pdo->prepare("DELETE FROM members WHERE id = ?")->execute([$_GET['id']]);
        if ($_GET['delete_type'] === 'session') $pdo->prepare("DELETE FROM sessions WHERE id = ?")->execute([$_GET['id']]);
        header("Location: index.php"); exit;
    }

    if (isset($_GET['action']) && $_GET['action'] === 'mark_paid') {
        $pdo->prepare("UPDATE payments SET status = 'paid', paid_at = NOW() WHERE id = ?")->execute([$_GET['pid']]);
        header("Location: index.php"); exit;
    }

    // --- DATA LOADING ---
    $today = date('Y-m-d');
    $sessions = $pdo->query("SELECT * FROM sessions ORDER BY date DESC, id DESC")->fetchAll();
    $current_sid = $_GET['session'] ?? ($sessions[0]['id'] ?? null);
    $members = $pdo->query("SELECT * FROM members ORDER BY full_name ASC")->fetchAll();
    
    // Attendance Stats for Current Session
    $attended_ids = [];
    if ($current_sid) {
        $stmt = $pdo->prepare("SELECT member_id FROM attendance WHERE session_id = ?");
        $stmt->execute([$current_sid]);
        $attended_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    // Payment Stats
    $due_today = $pdo->query("SELECT p.*, m.full_name FROM payments p JOIN members m ON p.member_id = m.id WHERE p.due_date <= '$today' AND p.status = 'unpaid'")->fetchAll();

} catch (Exception $e) { die("Error: " . $e->getMessage()); }
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ujamaa Academy Pro</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; background: #fcfdfe; }
        .active-tab { background: #4f46e5; color: white; box-shadow: 0 10px 15px -3px rgba(79, 70, 229, 0.2); }
    </style>
</head>
<body class="p-4 lg:p-10">

<div class="max-w-7xl mx-auto grid grid-cols-1 lg:grid-cols-12 gap-8">
    
    <!-- LEFT SIDEBAR: NAVIGATION & SUMMARY -->
    <div class="lg:col-span-4 space-y-6">
        <div class="bg-slate-900 rounded-[2rem] p-8 text-white shadow-2xl relative overflow-hidden">
            <h1 class="text-3xl font-800 tracking-tighter mb-2">UJAMAA</h1>
            <p class="text-slate-400 text-xs font-bold uppercase mb-8">Management System</p>
            
            <nav class="space-y-2">
                <button onclick="showSection('training')" id="nav-training" class="w-full text-left px-6 py-4 rounded-2xl font-bold transition-all active-tab">Training Mode</button>
                <button onclick="showSection('finance')" id="nav-finance" class="w-full text-left px-6 py-4 rounded-2xl font-bold transition-all text-slate-400 hover:text-white">Finance Mode</button>
                <button onclick="showSection('reports')" id="nav-reports" class="w-full text-left px-6 py-4 rounded-2xl font-bold transition-all text-slate-400 hover:text-white">Full Reports</button>
            </nav>
        </div>

        <!-- Quick Create Athlete Card -->
        <div class="bg-white rounded-[2rem] p-8 border border-slate-100 shadow-sm">
            <h3 class="font-800 text-lg mb-4" id="member-form-title">Add New Athlete</h3>
            <form method="POST" class="space-y-3">
                <input type="hidden" name="member_id" id="form_member_id">
                <input name="name" id="form_member_name" placeholder="Full Name" class="w-full p-4 bg-slate-50 rounded-2xl border-none text-sm ring-1 ring-slate-100 outline-none" required>
                <button name="save_member" class="w-full bg-indigo-600 text-white py-4 rounded-2xl font-bold shadow-lg shadow-indigo-50">Save Athlete</button>
                <button type="button" onclick="resetMemberForm()" class="w-full text-xs text-slate-400 font-bold hidden" id="cancel-edit-btn">Cancel Edit</button>
            </form>
        </div>
    </div>

    <!-- MAIN DASHBOARD AREA -->
    <div class="lg:col-span-8 space-y-6">
        
        <!-- SECTION: TRAINING REGISTRY -->
        <div id="section-training" class="section-content space-y-6">
            <div class="bg-white rounded-[2rem] p-8 border border-slate-100 shadow-sm">
                <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4 mb-8">
                    <div>
                        <h2 class="text-2xl font-800">Training Registry</h2>
                        <p class="text-slate-400 text-sm">Select session to manage attendance</p>
                    </div>
                    <div class="flex gap-2">
                        <select onchange="window.location.href='?session='+this.value" class="bg-slate-50 border-none rounded-xl px-4 py-2 text-sm font-bold ring-1 ring-slate-100">
                            <?php foreach($sessions as $s): ?>
                                <option value="<?= $s['id'] ?>" <?= $current_sid == $s['id'] ? 'selected' : '' ?>><?= $s['name'] ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button onclick="openSessionModal()" class="bg-slate-900 text-white p-2 px-4 rounded-xl text-xs font-bold">New Session</button>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full">
                        <tbody class="divide-y divide-slate-50">
                            <?php foreach($members as $m): ?>
                            <tr class="group hover:bg-slate-50/50 transition">
                                <td class="py-5 font-bold text-slate-700"><?= $m['full_name'] ?></td>
                                <td class="py-5 text-right flex items-center justify-end gap-3">
                                    <?php if(in_array($m['id'], $attended_ids)): ?>
                                        <span class="text-[10px] bg-emerald-100 text-emerald-600 px-3 py-1 rounded-full font-900">PRESENT</span>
                                    <?php else: ?>
                                        <form method="POST" class="inline">
                                            <input type="hidden" name="session_id" value="<?= $current_sid ?>">
                                            <input type="hidden" name="member_id" value="<?= $m['id'] ?>">
                                            <button name="mark_attendance" class="text-indigo-600 font-bold text-xs">Mark</button>
                                        </form>
                                    <?php endif; ?>
                                    
                                    <!-- CRUD TOOLS -->
                                    <button onclick="editMember('<?= $m['id'] ?>', '<?= addslashes($m['full_name']) ?>')" class="text-slate-300 hover:text-indigo-600"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"></path></svg></button>
                                    <a href="?delete_type=member&id=<?= $m['id'] ?>" onclick="return confirm('Delete athlete?')" class="text-slate-300 hover:text-red-500"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg></a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- SECTION: FINANCE -->
        <div id="section-finance" class="section-content hidden space-y-6">
            <div class="bg-white rounded-[2rem] p-8 border border-red-100 shadow-sm">
                <h2 class="text-2xl font-800 text-slate-900 mb-6">Unpaid Collections</h2>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <?php foreach($due_today as $p): ?>
                        <div class="bg-red-50/50 p-6 rounded-2xl border border-red-100 flex justify-between items-center">
                            <div>
                                <p class="font-bold text-slate-800"><?= $p['full_name'] ?></p>
                                <p class="text-xs font-bold text-red-500">RWF <?= number_format($p['amount']) ?></p>
                            </div>
                            <a href="?action=mark_paid&pid=<?= $p['id'] ?>" class="bg-slate-900 text-white text-[10px] font-bold px-4 py-2 rounded-lg">Confirm Cash</a>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- SECTION: REPORTS (NEW) -->
        <div id="section-reports" class="section-content hidden space-y-6">
            <div class="bg-white rounded-[2rem] p-8 border border-slate-100 shadow-sm">
                <h2 class="text-2xl font-800 mb-6">Session Management</h2>
                <div class="overflow-x-auto">
                    <table class="w-full text-left">
                        <thead>
                            <tr class="text-[10px] text-slate-400 font-bold uppercase tracking-widest border-b border-slate-50">
                                <th class="pb-4">Session Title</th>
                                <th class="pb-4">Date</th>
                                <th class="pb-4 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($sessions as $s): ?>
                            <tr class="border-b border-slate-50/50">
                                <td class="py-4 font-bold text-slate-700"><?= $s['name'] ?></td>
                                <td class="py-4 text-sm text-slate-500"><?= $s['date'] ?></td>
                                <td class="py-4 text-right space-x-3">
                                    <button onclick="editSession('<?= $s['id'] ?>', '<?= addslashes($s['name']) ?>', '<?= $s['date'] ?>')" class="text-indigo-600 text-xs font-bold">Edit</button>
                                    <a href="?delete_type=session&id=<?= $s['id'] ?>" onclick="return confirm('Delete this session?')" class="text-red-400 text-xs font-bold">Delete</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>
</div>

<!-- MODAL: SESSION CRUD -->
<div id="sessionModal" class="hidden fixed inset-0 bg-slate-900/40 backdrop-blur-sm z-50 flex items-center justify-center p-4">
    <div class="bg-white w-full max-w-md rounded-[2.5rem] p-8 shadow-2xl">
        <h3 class="text-2xl font-800 mb-6" id="session-modal-title">New Training Session</h3>
        <form method="POST" class="space-y-4">
            <input type="hidden" name="session_id" id="modal_session_id">
            <input name="session_name" id="modal_session_name" placeholder="Session Title (e.g., Morning Drill)" class="w-full p-4 bg-slate-50 rounded-2xl border-none ring-1 ring-slate-100 outline-none" required>
            <input type="date" name="session_date" id="modal_session_date" value="<?= date('Y-m-d') ?>" class="w-full p-4 bg-slate-50 rounded-2xl border-none ring-1 ring-slate-100 outline-none" required>
            <div class="flex gap-2 pt-4">
                <button type="button" onclick="closeSessionModal()" class="flex-1 py-4 font-bold text-slate-400">Cancel</button>
                <button type="submit" name="save_session" class="flex-1 bg-indigo-600 text-white py-4 rounded-2xl font-bold">Save Session</button>
            </div>
        </form>
    </div>
</div>

<script>
    // VIEW TOGGLE LOGIC
    function showSection(id) {
        document.querySelectorAll('.section-content').forEach(s => s.classList.add('hidden'));
        document.getElementById('section-' + id).classList.remove('hidden');
        
        document.querySelectorAll('nav button').forEach(b => b.classList.remove('active-tab', 'text-white'));
        document.querySelectorAll('nav button').forEach(b => b.classList.add('text-slate-400'));
        
        const activeBtn = document.getElementById('nav-' + id);
        activeBtn.classList.add('active-tab', 'text-white');
        activeBtn.classList.remove('text-slate-400');
    }

    // ATHLETE CRUD LOGIC
    function editMember(id, name) {
        document.getElementById('form_member_id').value = id;
        document.getElementById('form_member_name').value = name;
        document.getElementById('member-form-title').innerText = "Edit Athlete";
        document.getElementById('cancel-edit-btn').classList.remove('hidden');
    }

    function resetMemberForm() {
        document.getElementById('form_member_id').value = "";
        document.getElementById('form_member_name').value = "";
        document.getElementById('member-form-title').innerText = "Add New Athlete";
        document.getElementById('cancel-edit-btn').classList.add('hidden');
    }

    // SESSION CRUD LOGIC
    function openSessionModal() {
        document.getElementById('modal_session_id').value = "";
        document.getElementById('modal_session_name').value = "";
        document.getElementById('session-modal-title').innerText = "New Training Session";
        document.getElementById('sessionModal').classList.remove('hidden');
    }

    function editSession(id, name, date) {
        document.getElementById('modal_session_id').value = id;
        document.getElementById('modal_session_name').value = name;
        document.getElementById('modal_session_date').value = date;
        document.getElementById('session-modal-title').innerText = "Edit Session";
        document.getElementById('sessionModal').classList.remove('hidden');
    }

    function closeSessionModal() {
        document.getElementById('sessionModal').classList.add('hidden');
    }
</script>
</body>
</html>
