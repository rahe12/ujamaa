<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

$databaseUrl = getenv("DATABASE_URL");

if (!$databaseUrl) {
    die("DATABASE_URL not set");
}

$url = parse_url($databaseUrl);

$dsn = "pgsql:host={$url['host']};port={$url['port']};dbname=" . ltrim($url['path'], "/") . ";sslmode=require";

$pdo = new PDO($dsn, $url['user'], $url['pass'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
]);

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

$members = $pdo->query("SELECT * FROM members ORDER BY full_name")->fetchAll();

$attended = $pdo->query("SELECT member_id FROM attendance WHERE session_id = $session_id")->fetchAll(PDO::FETCH_COLUMN);
?>

<h1>Ujamaa Attendance</h1>

<form method="POST">
<input name="name" placeholder="Full name">
<button name="add_member">Add</button>
</form>

<form method="POST">
<input name="session" placeholder="Session name">
<button name="create_session">Create Session</button>
</form>

<h2>Members</h2>
<?php foreach ($members as $m): ?>
<div>
<?= $m['full_name'] ?>
<?php if (!in_array($m['id'], $attended)): ?>
<form method="POST" style="display:inline">
<input type="hidden" name="member_id" value="<?= $m['id'] ?>">
<button name="mark">Mark Present</button>
</form>
<?php else: ?>
✔ Present
<?php endif; ?>
</div>
<?php endforeach; ?>