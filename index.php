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

    // Database Init
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS members (id SERIAL PRIMARY KEY, full_name TEXT UNIQUE);
        CREATE TABLE IF NOT EXISTS sessions (id SERIAL PRIMARY KEY, name TEXT, date DATE DEFAULT CURRENT_DATE);
        CREATE TABLE IF NOT EXISTS attendance (id SERIAL PRIMARY KEY, session_id INT, member_id INT, UNIQUE(session_id, member_id));
    ");

    // Logic
    if ($_POST['add_member'] ?? false) {
        $stmt = $pdo->prepare("INSERT INTO members(full_name) VALUES (?) ON CONFLICT DO NOTHING");
        $stmt->execute([$_POST['name']]);
    }

    if ($_POST['create_session'] ?? false) {
        $stmt = $pdo->prepare("INSERT INTO sessions(name) VALUES (?)");
        $stmt->execute([$_POST['session']]);
    }

    $current_session_id = $_GET['session'] ?? null;
    if (!$current_session_id) {
        $latest = $pdo->query("SELECT id FROM sessions ORDER BY id DESC LIMIT 1")->fetch();
        $current_session_id = $latest['id'] ?? null;
    }

    if (($_POST['mark'] ?? false) && $current_session_id) {
        $stmt = $pdo->prepare("INSERT INTO attendance(session_id, member_id) VALUES (?, ?) ON CONFLICT DO NOTHING");
        $stmt->execute([$current_session_id, $_POST['member_id']]);
    }

    $members = $pdo->query("SELECT * FROM members ORDER BY full_name")->fetchAll();
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
    <title>Ujamaa Attendance</title>
    <style>
        :root {
            --primary: #6366f1;
            --primary-hover: #4f46e5;
            --bg: #f8fafc;
            --card: #ffffff;
            --text: #1e293b;
            --success: #22c55e;
        }

        body { 
            font-family: 'Inter', system-ui, sans-serif; 
            background-color: var(--bg); 
            color: var(--text);
            margin: 0;
            padding: 20px;
            display: flex;
            justify-content: center;
        }

        .container { 
            width: 100%; 
            max-width: 500px; 
            background: var(--card);
            padding: 2rem;
            border-radius: 16px;
            box-shadow: 0 10px 25px -5px rgba(0,0,0,0.1);
        }

        h1 { font-size: 1.5rem; font-weight: 800; text-align: center; margin-bottom: 2rem; color: var(--primary); }
        
        .section { margin-bottom: 2rem; }
        
        label { display: block; font-size: 0.875rem; font-weight: 600; margin-bottom: 0.5rem; }

        .input-group { display: flex; gap: 8px; margin-bottom: 1rem; }

        input, select {
            flex: 1;
            padding: 10px 14px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            outline: none;
            transition: border 0.2s;
        }

        input:focus { border-color: var(--primary); }

        button {
            background: var(--primary);
            color: white;
            border: none;
            padding: 10px 16px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s;
        }

        button:hover { background: var(--primary-hover); }

        .member-list { border-top: 1px solid #f1f5f9; padding-top: 1rem; }

        .member-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid #f1f5f9;
        }

        .status-badge {
            font-size: 0.875rem;
            color: var(--success);
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .btn-mark {
            background: #f1f5f9;
            color: var(--text);
            font-size: 0.75rem;
        }

        .btn-mark:hover { background: #e2e8f0; }

        .empty-state { text-align: center; color: #94a3b8; font-size: 0.9rem; margin-top: 2rem; }
    </style>
</head>
<body>

<div class="container">
    <h1>Ujamaa Attendance</h1>

    <!-- Session Management -->
    <div class="section">
        <label>Active Session</label>
        <div class="input-group">
            <select onchange="window.location.href='?session='+this.value">
                <?php if (!$sessions): ?>
                    <option>No sessions created</option>
                <?php endif; ?>
                <?php foreach ($sessions as $s): ?>
                    <option value="<?= $s['id'] ?>" <?= $current_session_id == $s['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($s['name']) ?> (<?= $s['date'] ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <form method="POST" class="input-group">
            <input name="session" placeholder="New session name..." required>
            <button name="create_session">Create</button>
        </form>
    </div>

    <!-- Member Management -->
    <div class="section">
        <label>Add New Member</label>
        <form method="POST" class="input-group">
            <input name="name" placeholder="Full name..." required>
            <button name="add_member">Add</button>
        </form>
    </div>

    <!-- Attendance List -->
    <div class="member-list">
        <h3>Members</h3>
        <?php if (!$members): ?>
            <p class="empty-state">No members added yet.</p>
        <?php endif; ?>

        <?php foreach ($members as $m): ?>
            <div class="member-row">
                <span><?= htmlspecialchars($m['full_name']) ?></span>
                
                <?php if ($current_session_id): ?>
                    <?php if (!in_array($m['id'], $attended)): ?>
                        <form method="POST">
                            <input type="hidden" name="member_id" value="<?= $m['id'] ?>">
                            <button name="mark" class="btn-mark">Mark Present</button>
                        </form>
                    <?php else: ?>
                        <span class="status-badge">✔ Present</span>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
</div>

</body>
</html>
