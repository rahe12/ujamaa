<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

// 1. Get the Database URL from environment variables
$databaseUrl = getenv("DATABASE_URL");

if (!$databaseUrl) {
    die("DATABASE_URL not set");
}

// 2. Parse the URL and handle potential missing components
$url = parse_url($databaseUrl);

$host = $url['host'] ?? 'localhost';
$port = $url['port'] ?? 5432; // Default PostgreSQL port
$user = $url['user'] ?? '';
$pass = $url['pass'] ?? '';
$dbName = ltrim($url['path'] ?? '', '/');

// 3. Construct the DSN safely
$dsn = "pgsql:host=$host;port=$port;dbname=$dbName;sslmode=require";

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    // 4. Initialize Tables
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS members (
            id SERIAL PRIMARY KEY,
            full_name TEXT UNIQUE
        );
        CREATE TABLE IF NOT EXISTS sessions (
            id SERIAL PRIMARY KEY,
            name TEXT,
            date DATE DEFAULT CURRENT_DATE
        );
        CREATE TABLE IF NOT EXISTS attendance (
            id SERIAL PRIMARY KEY,
            session_id INT,
            member_id INT,
            UNIQUE(session_id, member_id)
        );
    ");

    // 5. Handle Form Submissions
    if ($_POST['add_member'] ?? false) {
        $stmt = $pdo->prepare("INSERT INTO members(full_name) VALUES (?) ON CONFLICT DO NOTHING");
        $stmt->execute([$_POST['name']]);
    }

    if ($_POST['create_session'] ?? false) {
        $stmt = $pdo->prepare("INSERT INTO sessions(name) VALUES (?)");
        $stmt->execute([$_POST['session']]);
    }

    $session_id = $_GET['session'] ?? 1;

    if ($_POST['mark'] ?? false) {
        $stmt = $pdo->prepare("INSERT INTO attendance(session_id, member_id) VALUES (?, ?) ON CONFLICT DO NOTHING");
        $stmt->execute([$session_id, $_POST['member_id']]);
    }

    // 6. Fetch Data for Display
    $members = $pdo->query("SELECT * FROM members ORDER BY full_name")->fetchAll();

    $stmt = $pdo->prepare("SELECT member_id FROM attendance WHERE session_id = ?");
    $stmt->execute([$session_id]);
    $attended = $stmt->fetchAll(PDO::FETCH_COLUMN);

} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Ujamaa Attendance</title>
    <style>
        body { font-family: sans-serif; line-height: 1.6; max-width: 600px; margin: 20px auto; }
        .member-row { border-bottom: 1px solid #eee; padding: 10px 0; display: flex; justify-content: space-between; }
        .present { color: green; font-weight: bold; }
    </style>
</head>
<body>

    <h1>Ujamaa Attendance</h1>

    <section>
        <h3>Add Member</h3>
        <form method="POST">
            <input name="name" placeholder="Full name" required>
            <button name="add_member">Add</button>
        </form>
    </section>

    <section>
        <h3>New Session</h3>
        <form method="POST">
            <input name="session" placeholder="Session name" required>
            <button name="create_session">Create Session</button>
        </form>
    </section>

    <hr>

    <h2>Members (Session ID: <?= htmlspecialchars($session_id) ?>)</h2>
    <?php foreach ($members as $m): ?>
        <div class="member-row">
            <span><?= htmlspecialchars($m['full_name']) ?></span>
            
            <?php if (!in_array($m['id'], $attended)): ?>
                <form method="POST" style="display:inline">
                    <input type="hidden" name="member_id" value="<?= $m['id'] ?>">
                    <button name="mark">Mark Present</button>
                </form>
            <?php else: ?>
                <span class="present">✔ Present</span>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>

</body>
</html>
