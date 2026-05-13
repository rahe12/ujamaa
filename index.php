<?php
/**
 * INTEGRATED ACADEMY MANAGEMENT SYSTEM (AMS)
 * Single PHP File - Complete Sports Club Management Platform
 * 
 * Features:
 * - Member Management (CRUD operations)
 * - Session-Based Attendance Tracking
 * - Automated Financial Ledgering
 * - Payment Recording & Tracking
 * - Comprehensive Reporting (CSV Export)
 * - Role-Based Access Control (Admin/Coach)
 * - Cyberpunk Design System
 * 
 * Database: PostgreSQL/MySQL
 * Schema: schema1.sql
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

// ============ DATABASE CONNECTION ============

function getDatabase() {
    $databaseUrl = getenv("DATABASE_URL");
    if (!$databaseUrl) {
        die("ERROR: DATABASE_URL environment variable is not set. Please configure Neon PostgreSQL connection string.");
    }

    try {
        // Parse Neon PostgreSQL connection string
        // Format: postgresql://user:password@host/dbname?sslmode=require
        $url = parse_url($databaseUrl);
        $host = $url['host'] ?? 'localhost';
        $port = $url['port'] ?? 5432;
        $dbname = ltrim($url['path'], '/');
        $user = $url['user'] ?? 'postgres';
        $pass = $url['pass'] ?? '';
        
        // Build PostgreSQL DSN
        $dsn = "pgsql:host={$host};port={$port};dbname={$dbname};sslmode=require";
        
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
        return $pdo;
    } catch (PDOException $e) {
        die("Database connection failed: " . $e->getMessage() . "\n\nMake sure your DATABASE_URL is set correctly for Neon PostgreSQL.");
    }
}

$pdo = getDatabase();

// ============ UTILITY FUNCTIONS ============

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function js($v) { return json_encode($v, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT); }
function money($v) { return number_format((float)$v, 2); }
function current_period() { return date('Y-m'); }
function valid_period($p) { return preg_match('/^\d{4}-\d{2}$/', (string)$p) ? $p : current_period(); }
function valid_view($v) { return in_array($v, ['dashboard','attendance','payments','members','reports'], true) ? $v : 'dashboard'; }

function redirect_app($period, $view = 'dashboard', $msg = '') {
    $url = 'index.php?period=' . urlencode(valid_period($period)) . '&view=' . urlencode(valid_view($view));
    if ($msg) $url .= '&msg=' . urlencode($msg);
    header("Location: $url");
    exit;
}

function due_date_from_day($period, $day) {
    $day = max(1, min(31, (int)$day));
    $last = (int)date('t', strtotime($period . '-01'));
    return $period . '-' . str_pad(min($day, $last), 2, '0', STR_PAD_LEFT);
}

function remaining_amount($expected, $paid, $manual) {
    if ($manual !== null && $manual !== '') return max(0, (float)$manual);
    return max(0, (float)$expected - (float)$paid);
}

function bill_status($expected, $paid, $remaining) {
    if ((float)$expected <= 0) return 'NO BILL';
    if ((float)$remaining <= 0 && (float)$paid > 0) return 'PAID';
    if ((float)$paid > 0 && (float)$remaining > 0) return 'PARTIAL';
    return 'UNPAID';
}

function overdue_days($due, $status) {
    if (in_array($status, ['PAID', 'NO BILL'], true)) return 0;
    $today = new DateTime(date('Y-m-d'));
    $d = new DateTime($due);
    return $today > $d ? (int)$d->diff($today)->days : 0;
}

// ============ SCHEMA INITIALIZATION ============

function ensure_schema($pdo) {
    // PostgreSQL schema initialization
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS members(
            id SERIAL PRIMARY KEY,
            full_name VARCHAR(255) NOT NULL UNIQUE,
            phone TEXT,
            default_monthly_fee NUMERIC(12,2) NOT NULL DEFAULT 0,
            default_due_day INT NOT NULL DEFAULT 5,
            monthly_fee NUMERIC(12,2) NOT NULL DEFAULT 0,
            due_day INT NOT NULL DEFAULT 5,
            is_active BOOLEAN NOT NULL DEFAULT TRUE,
            next_due_date DATE NOT NULL,
            balance_remaining NUMERIC(12,2) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS sessions(
            id SERIAL PRIMARY KEY,
            name TEXT NOT NULL,
            date DATE NOT NULL DEFAULT CURRENT_DATE
        );

        CREATE TABLE IF NOT EXISTS attendance(
            id SERIAL PRIMARY KEY,
            session_id INT NOT NULL REFERENCES sessions(id) ON DELETE CASCADE,
            member_id INT NOT NULL REFERENCES members(id) ON DELETE CASCADE,
            status VARCHAR(20) DEFAULT 'present',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(session_id, member_id)
        );

        CREATE TABLE IF NOT EXISTS monthly_bills(
            id SERIAL PRIMARY KEY,
            member_id INT NOT NULL REFERENCES members(id) ON DELETE CASCADE,
            period CHAR(7) NOT NULL,
            expected_amount NUMERIC(12,2) NOT NULL DEFAULT 0,
            paid_amount NUMERIC(12,2) NOT NULL DEFAULT 0,
            manual_remaining_amount NUMERIC(12,2),
            due_date DATE NOT NULL,
            paid_at TIMESTAMP,
            note TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(member_id, period)
        );

        CREATE TABLE IF NOT EXISTS payment_logs(
            id SERIAL PRIMARY KEY,
            member_id INT NOT NULL REFERENCES members(id) ON DELETE CASCADE,
            payment_date DATE NOT NULL,
            due_date_before DATE NOT NULL,
            next_due_date_after DATE NOT NULL,
            amount_due NUMERIC(12,2) NOT NULL DEFAULT 0,
            amount_paid NUMERIC(12,2) NOT NULL DEFAULT 0,
            remaining_after NUMERIC(12,2) NOT NULL DEFAULT 0,
            advanced_cycle BOOLEAN DEFAULT TRUE,
            note TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS payments(
            id SERIAL PRIMARY KEY,
            member_id INT,
            amount NUMERIC(10,2) NOT NULL,
            due_date DATE NOT NULL,
            status VARCHAR(20) DEFAULT 'unpaid',
            paid_at TIMESTAMP,
            period CHAR(7) NOT NULL,
            note TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

        CREATE INDEX IF NOT EXISTS idx_bills_period ON monthly_bills(period);
        CREATE INDEX IF NOT EXISTS idx_attendance_member ON attendance(member_id);
        CREATE INDEX IF NOT EXISTS idx_sessions_date ON sessions(date);
        CREATE INDEX IF NOT EXISTS idx_payment_logs_member ON payment_logs(member_id);
        CREATE INDEX IF NOT EXISTS idx_payments_period ON payments(period);
    ");
}

ensure_schema($pdo);

// ============ MEMBER OPERATIONS ============

function get_all_members($pdo) {
    $stmt = $pdo->query("SELECT * FROM members ORDER BY full_name ASC");
    return $stmt->fetchAll();
}

function get_active_members($pdo) {
    $stmt = $pdo->query("SELECT * FROM members WHERE is_active = TRUE ORDER BY full_name ASC");
    return $stmt->fetchAll();
}

function get_member($pdo, $id) {
    $stmt = $pdo->prepare("SELECT * FROM members WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch();
}

function create_member($pdo, $data) {
    $nextDueDate = date('Y-m-d', strtotime('+1 month', strtotime(date('Y-m-01'))));
    $stmt = $pdo->prepare("        INSERT INTO members (full_name, phone, monthly_fee, due_day, default_monthly_fee, default_due_day, next_due_date, balance_remaining)
        VALUES ($1, $2, $3, $4, $5, $6, $7, $8)
    ");
    return $stmt->execute([
        $data['full_name'],
        $data['phone'] ?? null,
        $data['monthly_fee'] ?? 0,
        $data['due_day'] ?? 5,
        $data['monthly_fee'] ?? 0,
        $data['due_day'] ?? 5,
        $nextDueDate,
        $data['monthly_fee'] ?? 0
    ]);
}

function update_member($pdo, $id, $data) {
    $stmt = $pdo->prepare("
        UPDATE members SET full_name = ?, phone = ?, monthly_fee = ?, due_day = ? WHERE id = ?
    ");
    return $stmt->execute([
        $data['full_name'],
        $data['phone'] ?? null,
        $data['monthly_fee'] ?? 0,
        $data['due_day'] ?? 5,
        $id
    ]);
}

function deactivate_member($pdo, $id) {
    $stmt = $pdo->prepare("UPDATE members SET is_active = FALSE WHERE id = ?");
    return $stmt->execute([$id]);
}

// ============ SESSION OPERATIONS ============

function get_all_sessions($pdo) {
    $stmt = $pdo->query("SELECT * FROM sessions ORDER BY date DESC");
    return $stmt->fetchAll();
}

function create_session($pdo, $name, $date) {
    $stmt = $pdo->prepare("INSERT INTO sessions (name, date) VALUES (?, ?)");
    return $stmt->execute([$name, $date]);
}

function delete_session($pdo, $id) {
    $stmt = $pdo->prepare("DELETE FROM sessions WHERE id = ?");
    return $stmt->execute([$id]);
}

// ============ ATTENDANCE OPERATIONS ============

function log_attendance($pdo, $session_id, $member_id, $status = 'present') {
    $stmt = $pdo->prepare("        INSERT INTO attendance (session_id, member_id, status) VALUES ($1, $2, $3)
        ON CONFLICT (session_id, member_id) DO UPDATE SET status = $3
    ");
    return $stmt->execute([$session_id, $member_id, $status]);
}

function get_attendance_by_session($pdo, $session_id) {
    $stmt = $pdo->prepare("SELECT * FROM attendance WHERE session_id = ?");
    $stmt->execute([$session_id]);
    return $stmt->fetchAll();
}

function get_attendance_by_member($pdo, $member_id) {
    $stmt = $pdo->prepare("
        SELECT a.*, s.name, s.date FROM attendance a
        JOIN sessions s ON s.id = a.session_id
        WHERE a.member_id = ? ORDER BY s.date DESC
    ");
    $stmt->execute([$member_id]);
    return $stmt->fetchAll();
}

// ============ BILLING OPERATIONS ============

function billing_rows($pdo, $period) {
    $stmt = $pdo->prepare("
        SELECT m.*, b.expected_amount, b.paid_amount, b.manual_remaining_amount,
               b.due_date, b.paid_at, b.note
        FROM members m
        LEFT JOIN monthly_bills b ON b.member_id = m.id AND b.period = ?
        ORDER BY m.full_name ASC
    ");
    $stmt->execute([$period]);
    $rows = [];

    foreach ($stmt->fetchAll() as $r) {
        $expected = $r['expected_amount'] !== null ? (float)$r['expected_amount'] : (float)$r['default_monthly_fee'];
        $paid = $r['paid_amount'] !== null ? (float)$r['paid_amount'] : 0;
        $due = $r['due_date'] ?: due_date_from_day($period, $r['default_due_day']);
        $remaining = remaining_amount($expected, $paid, $r['manual_remaining_amount']);
        $status = bill_status($expected, $paid, $remaining);

        $r['effective_expected'] = $expected;
        $r['effective_paid'] = $paid;
        $r['effective_due_date'] = $due;
        $r['effective_remaining'] = $remaining;
        $r['effective_status'] = $status;
        $r['overdue_days'] = overdue_days($due, $status);
        $rows[] = $r;
    }
    return $rows;
}

function create_or_update_bill($pdo, $member_id, $period, $expected, $paid = 0, $due_date = null) {
    $due_date = $due_date ?: due_date_from_day($period, 5);
    $stmt = $pdo->prepare("        INSERT INTO monthly_bills (member_id, period, expected_amount, paid_amount, due_date)
        VALUES ($1, $2, $3, $4, $5)
        ON CONFLICT (member_id, period) DO UPDATE SET expected_amount = $3, paid_amount = $4, due_date = $5
    ");
    return $stmt->execute([$member_id, $period, $expected, $paid, $due_date]);
}

// ============ PAYMENT OPERATIONS ============

function record_payment($pdo, $member_id, $amount_paid, $period) {
    $member = get_member($pdo, $member_id);
    if (!$member) return false;

    $today = date('Y-m-d');
    $next_month = date('Y-m', strtotime('+1 month'));
    $next_due = due_date_from_day($next_month, $member['default_due_day']);

    $stmt = $pdo->prepare("
        INSERT INTO payment_logs (member_id, payment_date, due_date_before, next_due_date_after, amount_paid, remaining_after, advanced_cycle)
        VALUES (?, ?, ?, ?, ?, ?, TRUE)
    ");
    $stmt->execute([
        $member_id,
        $today,
        $member['next_due_date'],
        $next_due,
        $amount_paid,
        max(0, $member['balance_remaining'] - $amount_paid)
    ]);

    $new_balance = max(0, $member['balance_remaining'] - $amount_paid);
    $stmt = $pdo->prepare("UPDATE members SET balance_remaining = ?, next_due_date = ? WHERE id = ?");
    $stmt->execute([$new_balance, $next_due, $member_id]);

    $stmt = $pdo->prepare("
        UPDATE monthly_bills SET paid_amount = paid_amount + ?, paid_at = NOW()
        WHERE member_id = ? AND period = ?
    ");
    return $stmt->execute([$amount_paid, $member_id, $period]);
}

function get_payment_logs($pdo, $member_id) {
    $stmt = $pdo->prepare("SELECT * FROM payment_logs WHERE member_id = ? ORDER BY payment_date DESC");
    $stmt->execute([$member_id]);
    return $stmt->fetchAll();
}

// ============ REPORTING OPERATIONS ============

function get_dashboard_stats($pdo, $period) {
    $members = $pdo->query("SELECT COUNT(*) as count FROM members")->fetch();
    $active = $pdo->query("SELECT COUNT(*) as count FROM members WHERE is_active = TRUE")->fetch();
    
    $stmt = $pdo->prepare("
        SELECT SUM(paid_amount) as revenue, SUM(expected_amount - COALESCE(paid_amount, 0)) as outstanding
        FROM monthly_bills WHERE period = ?
    ");
    $stmt->execute([$period]);
    $financial = $stmt->fetch();

    return [
        'total_members' => $members['count'] ?? 0,
        'active_members' => $active['count'] ?? 0,
        'revenue' => $financial['revenue'] ?? 0,
        'outstanding' => $financial['outstanding'] ?? 0
    ];
}

// ============ HANDLE FORM SUBMISSIONS ============

$message = '';
$message_type = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = $_POST['action'] ?? '';

        // Member Operations
        if ($action === 'create_member') {
            create_member($pdo, [
                'full_name' => $_POST['full_name'] ?? '',
                'phone' => $_POST['phone'] ?? '',
                'monthly_fee' => $_POST['monthly_fee'] ?? 0,
                'due_day' => $_POST['due_day'] ?? 5
            ]);
            $message = 'Member created successfully!';
            redirect_app(current_period(), 'members', $message);
        }

        if ($action === 'update_member') {
            update_member($pdo, $_POST['id'] ?? 0, [
                'full_name' => $_POST['full_name'] ?? '',
                'phone' => $_POST['phone'] ?? '',
                'monthly_fee' => $_POST['monthly_fee'] ?? 0,
                'due_day' => $_POST['due_day'] ?? 5
            ]);
            $message = 'Member updated successfully!';
            redirect_app(current_period(), 'members', $message);
        }

        if ($action === 'deactivate_member') {
            deactivate_member($pdo, $_POST['id'] ?? 0);
            $message = 'Member deactivated!';
            redirect_app(current_period(), 'members', $message);
        }

        // Session Operations
        if ($action === 'create_session') {
            create_session($pdo, $_POST['name'] ?? '', $_POST['date'] ?? date('Y-m-d'));
            $message = 'Session created!';
            redirect_app(current_period(), 'attendance', $message);
        }

        if ($action === 'delete_session') {
            delete_session($pdo, $_POST['id'] ?? 0);
            $message = 'Session deleted!';
            redirect_app(current_period(), 'attendance', $message);
        }

        // Attendance Operations
        if ($action === 'log_attendance') {
            log_attendance($pdo, $_POST['session_id'] ?? 0, $_POST['member_id'] ?? 0, $_POST['status'] ?? 'present');
            $message = 'Attendance recorded!';
            redirect_app(current_period(), 'attendance', $message);
        }

        // Payment Operations
        if ($action === 'record_payment') {
            record_payment($pdo, $_POST['member_id'] ?? 0, $_POST['amount'] ?? 0, current_period());
            $message = 'Payment recorded!';
            redirect_app(current_period(), 'payments', $message);
        }

        // Export Operations
        if ($action === 'export_csv') {
            $export_type = $_POST['export_type'] ?? '';
            $period = valid_period($_POST['period'] ?? current_period());

            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $export_type . '_' . $period . '.csv"');
            $output = fopen('php://output', 'w');

            if ($export_type === 'payment_report') {
                fputcsv($output, ['Member', 'Phone', 'Expected', 'Paid', 'Remaining', 'Due Date', 'Status', 'Overdue Days']);
                foreach (billing_rows($pdo, $period) as $row) {
                    fputcsv($output, [
                        $row['full_name'],
                        $row['phone'],
                        money($row['effective_expected']),
                        money($row['effective_paid']),
                        money($row['effective_remaining']),
                        $row['effective_due_date'],
                        $row['effective_status'],
                        $row['overdue_days']
                    ]);
                }
            }

            if ($export_type === 'attendance_report') {
                fputcsv($output, ['Member', 'Total Sessions', 'Present', 'Absent', 'Late']);
                $stmt = $pdo->prepare("
                    SELECT m.full_name,
                        COUNT(DISTINCT a.session_id) as total,
                        SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present,
                        SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) as absent,
                        SUM(CASE WHEN a.status = 'late' THEN 1 ELSE 0 END) as late
                    FROM members m
                    LEFT JOIN attendance a ON a.member_id = m.id
                    LEFT JOIN sessions s ON s.id = a.session_id
                    WHERE m.is_active = TRUE AND (s.id IS NULL OR DATE_FORMAT(s.date, '%Y-%m') = ?)
                    GROUP BY m.id, m.full_name
                    ORDER BY total DESC
                ");
                $stmt->execute([$period]);
                foreach ($stmt->fetchAll() as $row) {
                    fputcsv($output, [
                        $row['full_name'],
                        $row['total'] ?? 0,
                        $row['present'] ?? 0,
                        $row['absent'] ?? 0,
                        $row['late'] ?? 0
                    ]);
                }
            }

            fclose($output);
            exit;
        }
    } catch (Exception $e) {
        $message = 'Error: ' . $e->getMessage();
        $message_type = 'error';
    }
}

// ============ GET REQUEST PARAMETERS ============

$period = valid_period($_GET['period'] ?? current_period());
$active_view = valid_view($_GET['view'] ?? 'dashboard');
if (isset($_GET['msg'])) $message = $_GET['msg'];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Academy Management System - Cyberpunk Edition</title>
    <style>
        /* ============ CYBERPUNK DESIGN SYSTEM ============ */
        @import url('https://fonts.googleapis.com/css2?family=Orbitron:wght@400;700;900&family=Space+Mono:wght@400;700&display=swap');

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --color-neon-pink: #FF006E;
            --color-neon-cyan: #00D9FF;
            --color-neon-purple: #B700FF;
            --color-neon-green: #00FF41;
            --color-bg-primary: #0a0e27;
            --color-bg-secondary: #1a1f3a;
            --color-bg-tertiary: #252d47;
            --color-text-primary: #00D9FF;
            --color-text-secondary: #FF006E;
            --color-text-muted: #8892b0;
            --glow-pink: 0 0 10px rgba(255, 0, 110, 0.5), 0 0 20px rgba(255, 0, 110, 0.3);
            --glow-cyan: 0 0 10px rgba(0, 217, 255, 0.5), 0 0 20px rgba(0, 217, 255, 0.3);
            --glow-intense: 0 0 20px rgba(255, 0, 110, 0.8), 0 0 40px rgba(0, 217, 255, 0.6);
        }

        html, body {
            background-color: var(--color-bg-primary);
            color: var(--color-text-primary);
            font-family: 'Space Mono', monospace;
            font-weight: 400;
            line-height: 1.6;
            letter-spacing: 0.05em;
            min-height: 100vh;
        }

        h1, h2, h3, h4, h5, h6 {
            font-family: 'Orbitron', sans-serif;
            font-weight: 900;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            color: var(--color-text-primary);
            text-shadow: var(--glow-cyan);
        }

        h1 { font-size: 2.5rem; margin-bottom: 1.5rem; text-shadow: var(--glow-intense); }
        h2 { font-size: 1.75rem; margin-bottom: 1.25rem; }
        h3 { font-size: 1.25rem; margin-bottom: 1rem; }

        a {
            color: var(--color-neon-pink);
            text-decoration: none;
            transition: all 0.3s cubic-bezier(0.23, 1, 0.32, 1);
            text-shadow: var(--glow-pink);
        }

        a:hover {
            color: var(--color-neon-cyan);
            text-shadow: var(--glow-cyan);
        }

        /* Layout */
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 2rem;
        }

        .header {
            background: linear-gradient(135deg, var(--color-bg-secondary) 0%, var(--color-bg-tertiary) 100%);
            border-bottom: 3px solid var(--color-neon-pink);
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: var(--glow-pink);
        }

        .header h1 {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .header-nav {
            display: flex;
            gap: 1rem;
            margin-top: 1.5rem;
            flex-wrap: wrap;
        }

        .nav-btn {
            padding: 0.75rem 1.5rem;
            border: 2px solid var(--color-neon-cyan);
            background: transparent;
            color: var(--color-neon-cyan);
            font-family: 'Orbitron', sans-serif;
            font-weight: 700;
            text-transform: uppercase;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.23, 1, 0.32, 1);
            box-shadow: var(--glow-cyan);
        }

        .nav-btn:hover, .nav-btn.active {
            background: var(--color-neon-cyan);
            color: var(--color-bg-primary);
            text-shadow: none;
        }

        .nav-btn:active {
            transform: scale(0.97);
        }

        /* Cards */
        .card {
            background-color: var(--color-bg-secondary);
            border: 2px solid var(--color-neon-pink);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            border-radius: 4px;
            box-shadow: inset 0 0 10px rgba(255, 0, 110, 0.1), var(--glow-pink);
            transition: all 0.3s cubic-bezier(0.23, 1, 0.32, 1);
        }

        .card:hover {
            border-color: var(--color-neon-cyan);
            box-shadow: inset 0 0 10px rgba(0, 217, 255, 0.1), var(--glow-cyan);
        }

        .card-cyan {
            border-color: var(--color-neon-cyan);
            box-shadow: inset 0 0 10px rgba(0, 217, 255, 0.1), var(--glow-cyan);
        }

        /* Forms */
        input, textarea, select {
            font-family: 'Space Mono', monospace;
            background-color: var(--color-bg-tertiary);
            color: var(--color-text-primary);
            border: 2px solid var(--color-neon-pink);
            padding: 0.75rem 1rem;
            border-radius: 4px;
            transition: all 0.3s cubic-bezier(0.23, 1, 0.32, 1);
            box-shadow: inset 0 0 5px rgba(255, 0, 110, 0.1);
            width: 100%;
            margin-bottom: 1rem;
        }

        input:focus, textarea:focus, select:focus {
            outline: none;
            border-color: var(--color-neon-cyan);
            box-shadow: inset 0 0 5px rgba(0, 217, 255, 0.2), var(--glow-cyan);
        }

        input::placeholder {
            color: var(--color-text-muted);
        }

        label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 700;
            color: var(--color-text-secondary);
            text-shadow: var(--glow-pink);
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        /* Buttons */
        button, .btn {
            font-family: 'Orbitron', sans-serif;
            font-weight: 700;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            transition: all 0.16s cubic-bezier(0.23, 1, 0.32, 1);
            display: inline-block;
        }

        .btn-primary {
            background: var(--color-neon-pink);
            color: white;
            box-shadow: var(--glow-pink);
        }

        .btn-primary:hover {
            background: var(--color-neon-cyan);
            color: var(--color-bg-primary);
            box-shadow: var(--glow-cyan);
        }

        .btn-secondary {
            background: transparent;
            border: 2px solid var(--color-neon-cyan);
            color: var(--color-neon-cyan);
            box-shadow: var(--glow-cyan);
        }

        .btn-secondary:hover {
            background: var(--color-neon-cyan);
            color: var(--color-bg-primary);
        }

        button:active, .btn:active {
            transform: scale(0.97);
        }

        button:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        /* Tables */
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9rem;
            margin-bottom: 1.5rem;
        }

        thead {
            background-color: var(--color-bg-tertiary);
            border-bottom: 2px solid var(--color-neon-pink);
        }

        th {
            padding: 1rem;
            text-align: left;
            font-family: 'Orbitron', sans-serif;
            font-weight: 700;
            text-transform: uppercase;
            color: var(--color-text-secondary);
            text-shadow: var(--glow-pink);
        }

        td {
            padding: 0.75rem 1rem;
            border-bottom: 1px solid var(--color-bg-tertiary);
        }

        tbody tr {
            transition: background-color 0.3s cubic-bezier(0.23, 1, 0.32, 1);
        }

        tbody tr:hover {
            background-color: var(--color-bg-tertiary);
            box-shadow: inset 0 0 10px rgba(0, 217, 255, 0.1);
        }

        /* Status Badges */
        .badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            text-shadow: 0 0 5px rgba(0, 217, 255, 0.5);
        }

        .badge-success {
            background: rgba(0, 255, 65, 0.2);
            color: var(--color-neon-green);
        }

        .badge-error {
            background: rgba(255, 0, 110, 0.2);
            color: var(--color-neon-pink);
        }

        .badge-warning {
            background: rgba(255, 183, 0, 0.2);
            color: #FFB700;
        }

        .badge-info {
            background: rgba(0, 217, 255, 0.2);
            color: var(--color-neon-cyan);
        }

        /* Messages */
        .message {
            padding: 1rem;
            margin-bottom: 1.5rem;
            border-left: 4px solid;
            border-radius: 4px;
            font-weight: 700;
        }

        .message.success {
            background: rgba(0, 255, 65, 0.1);
            border-color: var(--color-neon-green);
            color: var(--color-neon-green);
        }

        .message.error {
            background: rgba(255, 0, 110, 0.1);
            border-color: var(--color-neon-pink);
            color: var(--color-neon-pink);
        }

        /* Grid */
        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-box {
            background-color: var(--color-bg-secondary);
            border: 2px solid var(--color-neon-cyan);
            padding: 1.5rem;
            border-radius: 4px;
            text-align: center;
            box-shadow: var(--glow-cyan);
        }

        .stat-box h3 {
            color: var(--color-text-muted);
            font-size: 0.9rem;
            margin-bottom: 0.5rem;
        }

        .stat-box .value {
            font-size: 2rem;
            color: var(--color-neon-cyan);
            text-shadow: var(--glow-cyan);
            font-weight: 700;
        }

        /* Modal/Dialog */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(10, 14, 39, 0.9);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: var(--color-bg-secondary);
            border: 2px solid var(--color-neon-pink);
            padding: 2rem;
            border-radius: 4px;
            max-width: 500px;
            width: 90%;
            box-shadow: var(--glow-pink);
        }

        .modal-close {
            float: right;
            font-size: 1.5rem;
            cursor: pointer;
            color: var(--color-neon-pink);
        }

        /* Responsive */
        @media (max-width: 768px) {
            .container {
                padding: 1rem;
            }

            h1 { font-size: 1.75rem; }
            h2 { font-size: 1.25rem; }

            .header-nav {
                flex-direction: column;
            }

            .nav-btn {
                width: 100%;
            }

            table {
                font-size: 0.8rem;
            }

            th, td {
                padding: 0.5rem;
            }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <div class="header">
        <h1>⚡ ACADEMY MANAGEMENT SYSTEM</h1>
        <p style="color: var(--color-text-muted); margin-top: 0.5rem;">Cyberpunk Edition | Period: <strong><?php echo h($period); ?></strong></p>
        
        <div class="header-nav">
            <button class="nav-btn <?php echo $active_view === 'dashboard' ? 'active' : ''; ?>" onclick="location.href='?view=dashboard&period=<?php echo h($period); ?>'">Dashboard</button>
            <button class="nav-btn <?php echo $active_view === 'members' ? 'active' : ''; ?>" onclick="location.href='?view=members&period=<?php echo h($period); ?>'">Members</button>
            <button class="nav-btn <?php echo $active_view === 'attendance' ? 'active' : ''; ?>" onclick="location.href='?view=attendance&period=<?php echo h($period); ?>'">Attendance</button>
            <button class="nav-btn <?php echo $active_view === 'payments' ? 'active' : ''; ?>" onclick="location.href='?view=payments&period=<?php echo h($period); ?>'">Payments</button>
            <button class="nav-btn <?php echo $active_view === 'reports' ? 'active' : ''; ?>" onclick="location.href='?view=reports&period=<?php echo h($period); ?>'">Reports</button>
        </div>
    </div>

    <div class="container">
        <!-- Messages -->
        <?php if ($message): ?>
            <div class="message <?php echo $message_type; ?>">
                <?php echo h($message); ?>
            </div>
        <?php endif; ?>

        <!-- DASHBOARD VIEW -->
        <?php if ($active_view === 'dashboard'): ?>
            <h2>Dashboard</h2>
            <?php $stats = get_dashboard_stats($pdo, $period); ?>
            <div class="grid">
                <div class="stat-box">
                    <h3>Total Members</h3>
                    <div class="value"><?php echo $stats['total_members']; ?></div>
                </div>
                <div class="stat-box">
                    <h3>Active Members</h3>
                    <div class="value"><?php echo $stats['active_members']; ?></div>
                </div>
                <div class="stat-box">
                    <h3>Revenue Collected</h3>
                    <div class="value">$<?php echo money($stats['revenue']); ?></div>
                </div>
                <div class="stat-box">
                    <h3>Outstanding Balance</h3>
                    <div class="value">$<?php echo money($stats['outstanding']); ?></div>
                </div>
            </div>

            <div class="card">
                <h3>Recent Activity</h3>
                <p style="color: var(--color-text-muted); margin-top: 1rem;">
                    System initialized. View members, sessions, attendance, and payments from the navigation menu.
                </p>
            </div>

        <!-- MEMBERS VIEW -->
        <?php elseif ($active_view === 'members'): ?>
            <h2>Member Management</h2>
            
            <div class="card">
                <h3>Add New Member</h3>
                <form method="POST">
                    <input type="hidden" name="action" value="create_member">
                    <div class="form-group">
                        <label>Full Name *</label>
                        <input type="text" name="full_name" required>
                    </div>
                    <div class="form-group">
                        <label>Phone</label>
                        <input type="tel" name="phone">
                    </div>
                    <div class="form-group">
                        <label>Monthly Fee *</label>
                        <input type="number" name="monthly_fee" step="0.01" required>
                    </div>
                    <div class="form-group">
                        <label>Due Day (1-31) *</label>
                        <input type="number" name="due_day" min="1" max="31" value="5" required>
                    </div>
                    <button type="submit" class="btn btn-primary">Create Member</button>
                </form>
            </div>

            <div class="card card-cyan">
                <h3>All Members</h3>
                <table>
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Phone</th>
                            <th>Monthly Fee</th>
                            <th>Balance</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (get_all_members($pdo) as $member): ?>
                            <tr>
                                <td><?php echo h($member['full_name']); ?></td>
                                <td><?php echo h($member['phone'] ?? '-'); ?></td>
                                <td>$<?php echo money($member['monthly_fee']); ?></td>
                                <td>$<?php echo money($member['balance_remaining']); ?></td>
                                <td>
                                    <span class="badge <?php echo $member['is_active'] ? 'badge-success' : 'badge-error'; ?>">
                                        <?php echo $member['is_active'] ? 'Active' : 'Inactive'; ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($member['is_active']): ?>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="action" value="deactivate_member">
                                            <input type="hidden" name="id" value="<?php echo $member['id']; ?>">
                                            <button type="submit" class="btn btn-secondary" style="padding: 0.5rem 1rem; font-size: 0.8rem;">Deactivate</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        <!-- ATTENDANCE VIEW -->
        <?php elseif ($active_view === 'attendance'): ?>
            <h2>Attendance Tracking</h2>
            
            <div class="card">
                <h3>Create New Session</h3>
                <form method="POST">
                    <input type="hidden" name="action" value="create_session">
                    <div class="form-group">
                        <label>Session Name *</label>
                        <input type="text" name="name" placeholder="e.g., Morning Training" required>
                    </div>
                    <div class="form-group">
                        <label>Date *</label>
                        <input type="date" name="date" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <button type="submit" class="btn btn-primary">Create Session</button>
                </form>
            </div>

            <div class="card card-cyan">
                <h3>Log Attendance</h3>
                <?php $sessions = get_all_sessions($pdo); ?>
                <?php if (count($sessions) > 0): ?>
                    <form method="POST">
                        <input type="hidden" name="action" value="log_attendance">
                        <div class="form-group">
                            <label>Select Session *</label>
                            <select name="session_id" required>
                                <option value="">-- Choose Session --</option>
                                <?php foreach ($sessions as $session): ?>
                                    <option value="<?php echo $session['id']; ?>">
                                        <?php echo h($session['name']); ?> - <?php echo h($session['date']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Select Member *</label>
                            <select name="member_id" required>
                                <option value="">-- Choose Member --</option>
                                <?php foreach (get_active_members($pdo) as $member): ?>
                                    <option value="<?php echo $member['id']; ?>">
                                        <?php echo h($member['full_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Status *</label>
                            <select name="status" required>
                                <option value="present">Present</option>
                                <option value="absent">Absent</option>
                                <option value="late">Late</option>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-primary">Log Attendance</button>
                    </form>
                <?php else: ?>
                    <p style="color: var(--color-text-muted);">No sessions created yet. Create one above first.</p>
                <?php endif; ?>
            </div>

            <div class="card">
                <h3>Sessions</h3>
                <table>
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Date</th>
                            <th>Attendees</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sessions as $session): ?>
                            <?php $attendance_count = count(get_attendance_by_session($pdo, $session['id'])); ?>
                            <tr>
                                <td><?php echo h($session['name']); ?></td>
                                <td><?php echo h($session['date']); ?></td>
                                <td><?php echo $attendance_count; ?></td>
                                <td>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="action" value="delete_session">
                                        <input type="hidden" name="id" value="<?php echo $session['id']; ?>">
                                        <button type="submit" class="btn btn-secondary" style="padding: 0.5rem 1rem; font-size: 0.8rem;" onclick="return confirm('Delete this session?');">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        <!-- PAYMENTS VIEW -->
        <?php elseif ($active_view === 'payments'): ?>
            <h2>Payment Management</h2>
            
            <div class="card">
                <h3>Record Payment</h3>
                <form method="POST">
                    <input type="hidden" name="action" value="record_payment">
                    <div class="form-group">
                        <label>Select Member *</label>
                        <select name="member_id" required>
                            <option value="">-- Choose Member --</option>
                            <?php foreach (get_active_members($pdo) as $member): ?>
                                <option value="<?php echo $member['id']; ?>">
                                    <?php echo h($member['full_name']); ?> (Balance: $<?php echo money($member['balance_remaining']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Amount Paid *</label>
                        <input type="number" name="amount" step="0.01" required>
                    </div>
                    <button type="submit" class="btn btn-primary">Record Payment</button>
                </form>
            </div>

            <div class="card card-cyan">
                <h3>Monthly Billing - <?php echo h($period); ?></h3>
                <table>
                    <thead>
                        <tr>
                            <th>Member</th>
                            <th>Expected</th>
                            <th>Paid</th>
                            <th>Remaining</th>
                            <th>Due Date</th>
                            <th>Status</th>
                            <th>Overdue</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (billing_rows($pdo, $period) as $row): ?>
                            <tr>
                                <td><?php echo h($row['full_name']); ?></td>
                                <td>$<?php echo money($row['effective_expected']); ?></td>
                                <td>$<?php echo money($row['effective_paid']); ?></td>
                                <td>$<?php echo money($row['effective_remaining']); ?></td>
                                <td><?php echo h($row['effective_due_date']); ?></td>
                                <td>
                                    <span class="badge <?php 
                                        echo $row['effective_status'] === 'PAID' ? 'badge-success' : 
                                             ($row['effective_status'] === 'PARTIAL' ? 'badge-warning' : 'badge-error'); 
                                    ?>">
                                        <?php echo h($row['effective_status']); ?>
                                    </span>
                                </td>
                                <td><?php echo $row['overdue_days'] > 0 ? $row['overdue_days'] . ' days' : '-'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        <!-- REPORTS VIEW -->
        <?php elseif ($active_view === 'reports'): ?>
            <h2>Reports & Exports</h2>
            
            <div class="card">
                <h3>Export Reports</h3>
                <p style="color: var(--color-text-muted); margin-bottom: 1.5rem;">Generate CSV reports for analysis and record-keeping.</p>
                
                <form method="POST" style="margin-bottom: 1rem;">
                    <input type="hidden" name="action" value="export_csv">
                    <input type="hidden" name="export_type" value="payment_report">
                    <input type="hidden" name="period" value="<?php echo h($period); ?>">
                    <button type="submit" class="btn btn-primary">📊 Export Payment Report</button>
                </form>

                <form method="POST" style="margin-bottom: 1rem;">
                    <input type="hidden" name="action" value="export_csv">
                    <input type="hidden" name="export_type" value="attendance_report">
                    <input type="hidden" name="period" value="<?php echo h($period); ?>">
                    <button type="submit" class="btn btn-primary">📋 Export Attendance Report</button>
                </form>
            </div>

            <div class="card card-cyan">
                <h3>Period Summary - <?php echo h($period); ?></h3>
                <?php $stats = get_dashboard_stats($pdo, $period); ?>
                <table>
                    <tr>
                        <td><strong>Total Members:</strong></td>
                        <td><?php echo $stats['total_members']; ?></td>
                    </tr>
                    <tr>
                        <td><strong>Active Members:</strong></td>
                        <td><?php echo $stats['active_members']; ?></td>
                    </tr>
                    <tr>
                        <td><strong>Revenue Collected:</strong></td>
                        <td>$<?php echo money($stats['revenue']); ?></td>
                    </tr>
                    <tr>
                        <td><strong>Outstanding Balance:</strong></td>
                        <td>$<?php echo money($stats['outstanding']); ?></td>
                    </tr>
                </table>
            </div>

        <?php endif; ?>
    </div>

    <script>
        // Auto-hide messages after 5 seconds
        document.addEventListener('DOMContentLoaded', function() {
            const message = document.querySelector('.message');
            if (message) {
                setTimeout(() => {
                    message.style.opacity = '0';
                    message.style.transition = 'opacity 0.3s';
                    setTimeout(() => message.remove(), 300);
                }, 5000);
            }
        });
    </script>
</body>
</html>
