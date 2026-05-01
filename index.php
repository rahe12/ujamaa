<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

$databaseUrl = getenv("DATABASE_URL");
if (!$databaseUrl) die("DATABASE_URL not set");

$url = parse_url($databaseUrl);
$host = $url['host'] ?? 'localhost';
$port = $url['port'] ?? 5432;
$user = $url['user'] ?? '';
$pass = $url['pass'] ?? '';
$dbName = ltrim($url['path'] ?? '', '/');

$dsn = "pgsql:host=$host;port=$port;dbname=$dbName;sslmode=require";

try {
    $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

    // Ensure Tables Exist
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS members (id SERIAL PRIMARY KEY, full_name TEXT UNIQUE);
        CREATE TABLE IF NOT EXISTS sessions (id SERIAL PRIMARY KEY, name TEXT, date DATE DEFAULT CURRENT_DATE);
        CREATE TABLE IF NOT EXISTS attendance (id SERIAL PRIMARY KEY, session_id INT, member_id INT, UNIQUE(session_id, member_id));
    ");

    // Action: Add Member
    if (isset($_POST['add_member']) && !empty(trim($_POST['name']))) {
        $stmt = $pdo->prepare("INSERT INTO members(full_name) VALUES (?) ON CONFLICT DO NOTHING");
        $stmt->execute([trim($_POST['name'])]);
        header("Location: " . $_SERVER['PHP_SELF'] . (isset($_GET['session']) ? "?session=".$_GET['session'] : ""));
        exit;
    }

    // Action: Create Session
    if (isset($_POST['create_session']) && !empty(trim($_POST['session']))) {
        $stmt = $pdo->prepare("INSERT INTO sessions(name) VALUES (?)");
        $stmt->execute([trim($_POST['session'])]);
        $newId = $pdo->lastInsertId();
        header("Location: ?session=" . $newId);
        exit;
    }

    $current_session_id = $_GET['session'] ?? null;
    if (!$current_session_id) {
        $latest = $pdo->query("SELECT id FROM sessions ORDER BY id DESC LIMIT 1")->fetch();
        $current_session_id = $latest['id'] ?? null;
    }

    // Action: Mark Attendance
    if (isset($_POST['mark']) && $current_session_id) {
        $stmt = $pdo->prepare("INSERT INTO attendance(session_id, member_id) VALUES (?, ?) ON CONFLICT DO NOTHING");
        $stmt->execute([$current_session_id, $_POST['member_id']]);
    }

    $members = $pdo->query("SELECT * FROM members ORDER BY full_name ASC")->fetchAll();
    $sessions = $pdo->query("SELECT * FROM sessions ORDER BY id DESC")->fetchAll();
    
    $attended = [];
    if ($current_session_id) {
        $stmt = $pdo->prepare("SELECT member_id FROM attendance WHERE session_id = ?");
        $stmt->execute([$current_session_id]);
        $attended = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ujamaa Academy | Attendance</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --brand: #2563eb;
            --brand-light: #dbeafe;
            --text-main: #0f172a;
            --text-muted: #64748b;
            --bg-page: #f1f5f9;
            --white: #ffffff;
            --success: #10b981;
        }

        * { box-sizing: border-box; }
        body { 
            font-family: 'Plus Jakarta Sans', sans-serif; 
            background-color: var(--bg-page); 
            color: var(--text-main);
            margin: 0; padding: 20px;
            display: flex; justify-content: center;
        }

        .app-card { 
            width: 100%; max-width: 480px; 
            background: var(--white);
            border-radius: 24px;
            box-shadow: 0 20px 25px -5px rgba(0,0,0,0.05);
            overflow: hidden;
        }

        .header { background: var(--brand); color: white; padding: 32px 24px; text-align: center; }
        .header h1 { margin: 0; font-size: 1.5rem; font-weight: 800; letter-spacing: -0.5px; }
        .header p { margin: 8px 0 0; opacity: 0.8; font-size: 0.9rem; }

        .content { padding: 24px; }

        .form-section { margin-bottom: 24px; }
        label { display: block; font-size: 0.8rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-muted); margin-bottom: 8px; }

        .input-row { display: flex; gap: 8px; margin-bottom: 12px; }
        input, select {
            flex: 1; padding: 12px 16px; border: 2px solid #e2e8f0; border-radius: 12px;
            font-family: inherit; font-size: 0.95rem; transition: all 0.2s;
        }
        input:focus { border-color: var(--brand); outline: none; background: #fff; }

        .btn {
            background: var(--brand); color: white; border: none; padding: 12px 20px;
            border-radius: 12px; font-weight: 700; cursor: pointer; transition: transform 0.1s, background 0.2s;
        }
        .btn:active { transform: scale(0.96); }
        .btn-secondary { background: var(--brand-light); color: var(--brand); }

        /* Search Styles */
        .search-container { position: sticky; top: 0; background: white; z-index: 10; padding-bottom: 10px; border-bottom: 1px solid #f1f5f9; }
        #memberSearch { width: 100%; background: #f8fafc; border-color: transparent; }
        #memberSearch:focus { border-color: var(--brand-light); background: white; }

        .member-list { margin-top: 16px; max-height: 400px; overflow-y: auto; padding-right: 4px; }
        .member-item {
            display: flex; justify-content: space-between; align-items: center;
            padding: 14px; border-radius: 14px; transition: background 0.2s;
            margin-bottom: 4px;
        }
        .member-item:hover { background: #f8fafc; }
        .member-name { font-weight: 600; font-size: 0.95rem; }

        .badge-present { color: var(--success); font-weight: 800; font-size: 0.85rem; display: flex; align-items: center; gap: 4px; }
        .btn-mark { background: #f1f5f9; color: var(--text-main); font-size: 0.8rem; padding: 8px 12px; }
        
        /* Custom Scrollbar */
        .member-list::-webkit-scrollbar { width: 4px; }
        .member-list::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
    </style>
</head>
<body>

<div class="app-card">
    <div class="header">
        <h1>UJAMAA ACADEMY</h1>
        <p>Attendance Tracking System</p>
    </div>

    <div class="content">
        <!-- Session Pick -->
        <div class="form-section">
            <label>Select Training Session</label>
            <select onchange="window.location.href='?session='+this.value" style="width: 100%;">
                <?php foreach ($sessions as $s): ?>
                    <option value="<?= $s['id'] ?>" <?= $current_session_id == $s['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($s['name']) ?> (<?= date('M d', strtotime($s['date'])) ?>)
                    </option>
                <?php endforeach; ?>
                <?php if (!$sessions): ?><option>No sessions found</option><?php endif; ?>
            </select>
        </div>

        <!-- Add Member -->
        <div class="form-section">
            <label>Add New Member</label>
            <form method="POST" class="input-row">
                <input name="name" placeholder="Enter athlete name..." required>
                <button name="add_member" class="btn">Add</button>
            </form>
        </div>

        <!-- Member List with Search -->
        <div class="member-list-container">
            <div class="search-container">
                <label>Attendance List</label>
                <input type="text" id="memberSearch" placeholder="Search members by name..." onkeyup="filterMembers()">
            </div>

            <div class="member-list" id="memberList">
                <?php foreach ($members as $m): ?>
                    <div class="member-item" data-name="<?= strtolower(htmlspecialchars($m['full_name'])) ?>">
                        <span class="member-name"><?= htmlspecialchars($m['full_name']) ?></span>
                        
                        <?php if ($current_session_id): ?>
                            <?php if (!in_array($m['id'], $attended)): ?>
                                <form method="POST">
                                    <input type="hidden" name="member_id" value="<?= $m['id'] ?>">
                                    <button name="mark" class="btn btn-mark">Mark Present</button>
                                </form>
                            <?php else: ?>
                                <span class="badge-present">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
                                    PRESENT
                                </span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Create Session (Hidden in a small sub-section) -->
        <div class="form-section" style="margin-top: 32px; border-top: 1px dashed #e2e8f0; padding-top: 20px;">
            <label>Administrative</label>
            <form method="POST" class="input-row">
                <input name="session" placeholder="New session (e.g. U16 Practice)">
                <button name="create_session" class="btn btn-secondary">New Session</button>
            </form>
        </div>
    </div>
</div>

<script>
function filterMembers() {
    const input = document.getElementById('memberSearch');
    const filter = input.value.toLowerCase();
    const list = document.getElementById('memberList');
    const items = list.getElementsByClassName('member-item');

    for (let i = 0; i < items.length; i++) {
        let name = items[i].getAttribute('data-name');
        if (name.indexOf(filter) > -1) {
            items[i].style.display = "";
        } else {
            items[i].style.display = "none";
        }
    }
}
</script>

</body>
</html>
