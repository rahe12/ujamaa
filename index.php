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
        $url = parse_url($databaseUrl);
        $host = $url['host'] ?? 'localhost';
        $port = $url['port'] ?? 5432;
        $dbname = ltrim($url['path'], '/');
        $user = $url['user'] ?? 'postgres';
        $pass = $url['pass'] ?? '';
        
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
    $stmt = $pdo->prepare("
        INSERT INTO members (full_name, phone, monthly_fee, due_day, default_monthly_fee, default_due_day, next_due_date, balance_remaining)
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
    $stmt = $pdo->prepare("
        INSERT INTO attendance (session_id, member_id, status) VALUES ($1, $2, $3)
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
    $stmt = $pdo->prepare("
        INSERT INTO monthly_bills (member_id, period, expected_amount, paid_amount, due_date)
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

function get_monthly_summary($pdo, $period) {
    // Payment summary
    $stmt = $pdo->prepare("
        SELECT
            COUNT(*) as total_bills,
            SUM(CASE WHEN (COALESCE(paid_amount,0) >= expected_amount AND expected_amount > 0) THEN 1 ELSE 0 END) as paid_count,
            SUM(CASE WHEN (paid_amount > 0 AND paid_amount < expected_amount) THEN 1 ELSE 0 END) as partial_count,
            SUM(CASE WHEN (COALESCE(paid_amount,0) = 0 AND expected_amount > 0) THEN 1 ELSE 0 END) as unpaid_count,
            SUM(expected_amount) as total_expected,
            SUM(COALESCE(paid_amount,0)) as total_paid,
            SUM(expected_amount - COALESCE(paid_amount,0)) as total_remaining
        FROM monthly_bills WHERE period = ?
    ");
    $stmt->execute([$period]);
    $pay = $stmt->fetch();

    // Attendance summary for sessions in this period
    $stmt = $pdo->prepare("
        SELECT
            COUNT(DISTINCT s.id) as total_sessions,
            COUNT(a.id) as total_records,
            SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present_count,
            SUM(CASE WHEN a.status = 'absent'  THEN 1 ELSE 0 END) as absent_count,
            SUM(CASE WHEN a.status = 'late'    THEN 1 ELSE 0 END) as late_count
        FROM sessions s
        LEFT JOIN attendance a ON a.session_id = s.id
        WHERE TO_CHAR(s.date, 'YYYY-MM') = ?
    ");
    $stmt->execute([$period]);
    $att = $stmt->fetch();

    // Top attenders this period
    $stmt = $pdo->prepare("
        SELECT m.full_name,
            COUNT(a.id) as sessions_attended,
            SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present,
            SUM(CASE WHEN a.status = 'absent'  THEN 1 ELSE 0 END) as absent,
            SUM(CASE WHEN a.status = 'late'    THEN 1 ELSE 0 END) as late
        FROM members m
        LEFT JOIN attendance a ON a.member_id = m.id
        LEFT JOIN sessions s ON s.id = a.session_id AND TO_CHAR(s.date, 'YYYY-MM') = ?
        WHERE m.is_active = TRUE
        GROUP BY m.id, m.full_name
        ORDER BY present DESC, sessions_attended DESC
        LIMIT 10
    ");
    $stmt->execute([$period]);
    $top_attenders = $stmt->fetchAll();

    // Members with unpaid/partial bills
    $stmt = $pdo->prepare("
        SELECT m.full_name, m.phone,
            b.expected_amount, b.paid_amount,
            (b.expected_amount - COALESCE(b.paid_amount,0)) as remaining,
            b.due_date
        FROM monthly_bills b
        JOIN members m ON m.id = b.member_id
        WHERE b.period = ? AND b.expected_amount > 0
          AND COALESCE(b.paid_amount,0) < b.expected_amount
        ORDER BY remaining DESC
        LIMIT 10
    ");
    $stmt->execute([$period]);
    $unpaid_members = $stmt->fetchAll();

    return [
        'payment'       => $pay,
        'attendance'    => $att,
        'top_attenders' => $top_attenders,
        'unpaid_members'=> $unpaid_members,
    ];
}

// ============ HANDLE FORM SUBMISSIONS ============

$message = '';
$message_type = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = $_POST['action'] ?? '';

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

        if ($action === 'log_attendance') {
            log_attendance($pdo, $_POST['session_id'] ?? 0, $_POST['member_id'] ?? 0, $_POST['status'] ?? 'present');
            $message = 'Attendance recorded!';
            redirect_app(current_period(), 'attendance', $message);
        }

        if ($action === 'record_payment') {
            record_payment($pdo, $_POST['member_id'] ?? 0, $_POST['amount'] ?? 0, current_period());
            $message = 'Payment recorded!';
            redirect_app(current_period(), 'payments', $message);
        }

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
    <title>AMS — Academy Management System</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;1,9..40,300&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

        :root {
            --bg:          #0f1117;
            --surface:     #181c27;
            --surface-2:   #1f2333;
            --border:      #272d42;
            --border-hover:#3a4261;

            --accent:      #4f7dff;
            --accent-dim:  rgba(79,125,255,.12);
            --accent-glow: rgba(79,125,255,.35);

            --green:       #2dd4a0;
            --green-dim:   rgba(45,212,160,.12);
            --amber:       #f59e0b;
            --amber-dim:   rgba(245,158,11,.12);
            --red:         #f87171;
            --red-dim:     rgba(248,113,113,.12);

            --text-1:      #e8ecf4;
            --text-2:      #8b92a8;
            --text-3:      #555e78;

            --radius-sm:   6px;
            --radius:      10px;
            --radius-lg:   16px;

            --sidebar-w:   240px;
            --header-h:    64px;

            --transition:  all .18s cubic-bezier(.4,0,.2,1);
        }

        html, body {
            background: var(--bg);
            color: var(--text-1);
            font-family: 'DM Sans', sans-serif;
            font-size: 15px;
            line-height: 1.6;
            min-height: 100vh;
            -webkit-font-smoothing: antialiased;
        }

        /* ──────────── LAYOUT SHELL ──────────── */
        .shell {
            display: flex;
            min-height: 100vh;
        }

        /* ──────────── SIDEBAR ──────────── */
        .sidebar {
            width: var(--sidebar-w);
            background: var(--surface);
            border-right: 1px solid var(--border);
            display: flex;
            flex-direction: column;
            position: fixed;
            top: 0; left: 0;
            height: 100vh;
            z-index: 200;
            transition: transform .25s ease;
        }

        .sidebar-brand {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 20px 20px 16px;
            border-bottom: 1px solid var(--border);
        }

        .brand-icon {
            width: 36px; height: 36px;
            background: var(--accent);
            border-radius: var(--radius-sm);
            display: flex; align-items: center; justify-content: center;
            font-size: 18px;
            flex-shrink: 0;
            box-shadow: 0 0 16px var(--accent-glow);
        }

        .brand-text {
            font-family: 'Syne', sans-serif;
            font-weight: 800;
            font-size: 16px;
            letter-spacing: .02em;
            color: var(--text-1);
            line-height: 1.2;
        }

        .brand-sub {
            font-size: 10px;
            font-weight: 400;
            color: var(--text-3);
            letter-spacing: .06em;
            text-transform: uppercase;
        }

        .sidebar-nav {
            flex: 1;
            padding: 12px 10px;
            overflow-y: auto;
        }

        .nav-label {
            font-size: 10px;
            font-weight: 600;
            letter-spacing: .1em;
            text-transform: uppercase;
            color: var(--text-3);
            padding: 8px 10px 4px;
            margin-top: 6px;
        }

        .nav-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 9px 12px;
            border-radius: var(--radius-sm);
            color: var(--text-2);
            text-decoration: none;
            font-weight: 500;
            font-size: 14px;
            cursor: pointer;
            border: none;
            background: none;
            width: 100%;
            text-align: left;
            transition: var(--transition);
            margin-bottom: 2px;
        }

        .nav-item:hover {
            background: var(--surface-2);
            color: var(--text-1);
        }

        .nav-item.active {
            background: var(--accent-dim);
            color: var(--accent);
            font-weight: 600;
        }

        .nav-icon {
            width: 18px;
            height: 18px;
            flex-shrink: 0;
            opacity: .7;
        }

        .nav-item.active .nav-icon { opacity: 1; }

        .sidebar-footer {
            padding: 14px 20px;
            border-top: 1px solid var(--border);
        }

        .period-badge {
            display: flex;
            align-items: center;
            gap: 8px;
            background: var(--surface-2);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            padding: 8px 12px;
            font-size: 12px;
            color: var(--text-2);
        }

        .period-dot {
            width: 7px; height: 7px;
            border-radius: 50%;
            background: var(--green);
            box-shadow: 0 0 6px var(--green);
            flex-shrink: 0;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0%,100% { opacity: 1; }
            50% { opacity: .4; }
        }

        /* ──────────── MAIN AREA ──────────── */
        .main {
            margin-left: var(--sidebar-w);
            flex: 1;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }

        /* ──────────── TOPBAR ──────────── */
        .topbar {
            height: var(--header-h);
            background: var(--surface);
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 28px;
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .topbar-left {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .menu-toggle {
            display: none;
            background: none;
            border: none;
            color: var(--text-2);
            cursor: pointer;
            padding: 6px;
            border-radius: var(--radius-sm);
        }

        .page-title {
            font-family: 'Syne', sans-serif;
            font-weight: 700;
            font-size: 18px;
            color: var(--text-1);
        }

        .topbar-right {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .avatar {
            width: 34px; height: 34px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--accent), #8b5cf6);
            display: flex; align-items: center; justify-content: center;
            font-weight: 700;
            font-size: 13px;
            color: #fff;
            cursor: pointer;
        }

        /* ──────────── CONTENT ──────────── */
        .content {
            flex: 1;
            padding: 28px;
            max-width: 1200px;
            width: 100%;
        }

        /* ──────────── ALERT MESSAGES ──────────── */
        .alert {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 18px;
            border-radius: var(--radius);
            margin-bottom: 24px;
            font-size: 14px;
            font-weight: 500;
            animation: slideDown .3s ease;
        }

        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-8px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        .alert-success {
            background: var(--green-dim);
            border: 1px solid rgba(45,212,160,.3);
            color: var(--green);
        }

        .alert-error {
            background: var(--red-dim);
            border: 1px solid rgba(248,113,113,.3);
            color: var(--red);
        }

        /* ──────────── SECTION HEADER ──────────── */
        .section-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            margin-bottom: 24px;
            gap: 16px;
            flex-wrap: wrap;
        }

        .section-title {
            font-family: 'Syne', sans-serif;
            font-weight: 700;
            font-size: 22px;
            color: var(--text-1);
        }

        .section-subtitle {
            font-size: 13px;
            color: var(--text-3);
            margin-top: 2px;
        }

        /* ──────────── STAT CARDS ──────────── */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            margin-bottom: 28px;
        }

        .stat-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 20px 22px;
            position: relative;
            overflow: hidden;
            transition: var(--transition);
        }

        .stat-card:hover {
            border-color: var(--border-hover);
            transform: translateY(-1px);
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 3px;
        }

        .stat-card.blue::before  { background: var(--accent); }
        .stat-card.green::before { background: var(--green); }
        .stat-card.amber::before { background: var(--amber); }
        .stat-card.red::before   { background: var(--red); }

        .stat-icon {
            width: 38px; height: 38px;
            border-radius: var(--radius-sm);
            display: flex; align-items: center; justify-content: center;
            font-size: 18px;
            margin-bottom: 14px;
        }

        .stat-icon.blue  { background: var(--accent-dim); }
        .stat-icon.green { background: var(--green-dim); }
        .stat-icon.amber { background: var(--amber-dim); }
        .stat-icon.red   { background: var(--red-dim); }

        .stat-value {
            font-family: 'Syne', sans-serif;
            font-weight: 800;
            font-size: 28px;
            color: var(--text-1);
            line-height: 1;
            margin-bottom: 6px;
        }

        .stat-label {
            font-size: 12px;
            color: var(--text-3);
            font-weight: 500;
            letter-spacing: .02em;
            text-transform: uppercase;
        }

        /* ──────────── CARD ──────────── */
        .card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            overflow: hidden;
        }

        .card + .card { margin-top: 20px; }

        .card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 18px 22px;
            border-bottom: 1px solid var(--border);
        }

        .card-title {
            font-family: 'Syne', sans-serif;
            font-weight: 700;
            font-size: 15px;
            color: var(--text-1);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .card-body {
            padding: 22px;
        }

        /* ──────────── FORM ELEMENTS ──────────── */
        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 16px;
        }

        .form-grid.cols-3 {
            grid-template-columns: repeat(3, 1fr);
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .form-group.full { grid-column: 1 / -1; }

        label {
            font-size: 12px;
            font-weight: 600;
            letter-spacing: .04em;
            text-transform: uppercase;
            color: var(--text-3);
        }

        input[type="text"],
        input[type="tel"],
        input[type="number"],
        input[type="date"],
        input[type="email"],
        select,
        textarea {
            background: var(--surface-2);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            color: var(--text-1);
            font-family: 'DM Sans', sans-serif;
            font-size: 14px;
            padding: 10px 14px;
            width: 100%;
            transition: var(--transition);
            outline: none;
            appearance: none;
        }

        input:focus, select:focus, textarea:focus {
            border-color: var(--accent);
            box-shadow: 0 0 0 3px var(--accent-dim);
        }

        input::placeholder { color: var(--text-3); }

        select {
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%238b92a8' d='M6 8L1 3h10z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 12px center;
            padding-right: 36px;
        }

        .form-actions {
            display: flex;
            gap: 10px;
            margin-top: 20px;
            flex-wrap: wrap;
        }

        /* ──────────── BUTTONS ──────────── */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 9px 18px;
            border-radius: var(--radius-sm);
            font-family: 'DM Sans', sans-serif;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            border: none;
            text-decoration: none;
            transition: var(--transition);
            white-space: nowrap;
        }

        .btn:active { transform: scale(.97); }

        .btn-primary {
            background: var(--accent);
            color: #fff;
            box-shadow: 0 1px 8px var(--accent-glow);
        }

        .btn-primary:hover {
            background: #6b93ff;
            box-shadow: 0 2px 14px var(--accent-glow);
        }

        .btn-ghost {
            background: var(--surface-2);
            color: var(--text-2);
            border: 1px solid var(--border);
        }

        .btn-ghost:hover {
            background: var(--border);
            color: var(--text-1);
        }

        .btn-danger {
            background: var(--red-dim);
            color: var(--red);
            border: 1px solid rgba(248,113,113,.25);
        }

        .btn-danger:hover {
            background: rgba(248,113,113,.22);
        }

        .btn-sm {
            padding: 6px 12px;
            font-size: 12px;
        }

        /* ──────────── TABLE ──────────── */
        .table-wrap {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13.5px;
        }

        thead tr {
            border-bottom: 1px solid var(--border);
        }

        th {
            padding: 10px 16px;
            text-align: left;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: .07em;
            text-transform: uppercase;
            color: var(--text-3);
            white-space: nowrap;
        }

        td {
            padding: 13px 16px;
            color: var(--text-2);
            border-bottom: 1px solid var(--border);
            vertical-align: middle;
        }

        tbody tr:last-child td { border-bottom: none; }

        tbody tr {
            transition: background .12s;
        }

        tbody tr:hover {
            background: var(--surface-2);
        }

        td.name-cell {
            color: var(--text-1);
            font-weight: 500;
        }

        td .mono {
            font-family: 'Courier New', monospace;
            font-size: 13px;
        }

        /* ──────────── BADGES ──────────── */
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 3px 10px;
            border-radius: 100px;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: .04em;
            text-transform: uppercase;
        }

        .badge::before {
            content: '';
            width: 5px; height: 5px;
            border-radius: 50%;
        }

        .badge-green  { background: var(--green-dim); color: var(--green); }
        .badge-green::before  { background: var(--green); }

        .badge-red    { background: var(--red-dim);   color: var(--red); }
        .badge-red::before    { background: var(--red); }

        .badge-amber  { background: var(--amber-dim); color: var(--amber); }
        .badge-amber::before  { background: var(--amber); }

        .badge-blue   { background: var(--accent-dim);color: var(--accent); }
        .badge-blue::before   { background: var(--accent); }

        .badge-gray   { background: var(--surface-2); color: var(--text-3); }
        .badge-gray::before   { background: var(--text-3); }

        /* ──────────── DIVIDER ──────────── */
        .divider {
            height: 1px;
            background: var(--border);
            margin: 20px 0;
        }

        /* ──────────── EMPTY STATE ──────────── */
        .empty-state {
            text-align: center;
            padding: 48px 24px;
            color: var(--text-3);
        }

        .empty-icon {
            font-size: 36px;
            margin-bottom: 12px;
            opacity: .5;
        }

        .empty-state p { font-size: 14px; }

        /* ──────────── LAYOUT SPLIT ──────────── */
        .two-col {
            display: grid;
            grid-template-columns: 380px 1fr;
            gap: 20px;
            align-items: start;
        }

        /* ──────────── SUMMARY TABLE ──────────── */
        .summary-table td:first-child {
            color: var(--text-3);
            font-size: 12px;
            font-weight: 600;
            letter-spacing: .04em;
            text-transform: uppercase;
            width: 55%;
        }

        .summary-table td:last-child {
            color: var(--text-1);
            font-weight: 600;
            font-size: 15px;
        }

        /* ──────────── EXPORT BUTTONS ──────────── */
        .export-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
        }

        .export-card {
            background: var(--surface-2);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 18px;
            display: flex;
            flex-direction: column;
            gap: 10px;
            transition: var(--transition);
        }

        .export-card:hover {
            border-color: var(--accent);
            background: var(--accent-dim);
        }

        .export-card-title {
            font-weight: 600;
            font-size: 14px;
            color: var(--text-1);
        }

        .export-card-desc {
            font-size: 12px;
            color: var(--text-3);
            line-height: 1.5;
        }

        /* ──────────── OVERLAY / MOBILE ──────────── */
        .overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,.6);
            z-index: 150;
        }

        /* ──────────── RESPONSIVE ──────────── */
        @media (max-width: 1024px) {
            .stats-grid  { grid-template-columns: repeat(2, 1fr); }
            .two-col     { grid-template-columns: 1fr; }
            .form-grid   { grid-template-columns: 1fr; }
            .form-grid.cols-3 { grid-template-columns: 1fr 1fr; }
            .report-row  { grid-template-columns: 1fr !important; }
        }

        @media (max-width: 768px) {
            :root { --sidebar-w: 240px; }

            .sidebar {
                transform: translateX(-100%);
            }

            .sidebar.open {
                transform: translateX(0);
            }

            .overlay.active { display: block; }

            .menu-toggle { display: flex; }

            .main { margin-left: 0; }

            .content { padding: 16px; }

            .topbar { padding: 0 16px; }

            .stats-grid { grid-template-columns: 1fr 1fr; gap: 12px; }

            .stat-value { font-size: 22px; }

            .export-grid { grid-template-columns: 1fr; }
        }

        @media (max-width: 480px) {
            .stats-grid { grid-template-columns: 1fr; }
            .form-grid.cols-3 { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

<div class="overlay" id="overlay" onclick="closeSidebar()"></div>

<!-- ──────────── SIDEBAR ──────────── -->
<div class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <div class="brand-icon">🏟️</div>
        <div>
            <div class="brand-text">AMS</div>
            <div class="brand-sub">Academy Portal</div>
        </div>
    </div>

    <nav class="sidebar-nav">
        <div class="nav-label">Main</div>
        <a href="?view=dashboard&period=<?php echo h($period); ?>"
           class="nav-item <?php echo $active_view === 'dashboard' ? 'active' : ''; ?>">
            <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <rect x="3" y="3" width="7" height="7" rx="1" stroke-width="2"/>
                <rect x="14" y="3" width="7" height="7" rx="1" stroke-width="2"/>
                <rect x="3" y="14" width="7" height="7" rx="1" stroke-width="2"/>
                <rect x="14" y="14" width="7" height="7" rx="1" stroke-width="2"/>
            </svg>
            Dashboard
        </a>

        <div class="nav-label">Management</div>
        <a href="?view=members&period=<?php echo h($period); ?>"
           class="nav-item <?php echo $active_view === 'members' ? 'active' : ''; ?>">
            <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" stroke-width="2" stroke-linecap="round"/>
                <circle cx="9" cy="7" r="4" stroke-width="2"/>
                <path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75" stroke-width="2" stroke-linecap="round"/>
            </svg>
            Members
        </a>

        <a href="?view=attendance&period=<?php echo h($period); ?>"
           class="nav-item <?php echo $active_view === 'attendance' ? 'active' : ''; ?>">
            <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path d="M9 11l3 3L22 4" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                <path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11" stroke-width="2" stroke-linecap="round"/>
            </svg>
            Attendance
        </a>

        <a href="?view=payments&period=<?php echo h($period); ?>"
           class="nav-item <?php echo $active_view === 'payments' ? 'active' : ''; ?>">
            <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <rect x="1" y="4" width="22" height="16" rx="2" stroke-width="2"/>
                <path d="M1 10h22" stroke-width="2"/>
            </svg>
            Payments
        </a>

        <div class="nav-label">Analytics</div>
        <a href="?view=reports&period=<?php echo h($period); ?>"
           class="nav-item <?php echo $active_view === 'reports' ? 'active' : ''; ?>">
            <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path d="M18 20V10M12 20V4M6 20v-6" stroke-width="2" stroke-linecap="round"/>
            </svg>
            Reports
        </a>
    </nav>

    <div class="sidebar-footer">
        <div class="period-badge">
            <div class="period-dot"></div>
            <div>
                <div style="font-size:11px;font-weight:600;color:var(--text-2);">Current Period</div>
                <div style="font-size:13px;font-weight:700;color:var(--text-1);"><?php echo h($period); ?></div>
            </div>
        </div>
    </div>
</div>

<!-- ──────────── MAIN ──────────── -->
<div class="main">

    <!-- TOPBAR -->
    <div class="topbar">
        <div class="topbar-left">
            <button class="menu-toggle" onclick="toggleSidebar()" aria-label="Menu">
                <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-width="2" stroke-linecap="round" d="M4 6h16M4 12h16M4 18h16"/>
                </svg>
            </button>
            <span class="page-title">
                <?php
                $titles = [
                    'dashboard'  => 'Dashboard',
                    'members'    => 'Members',
                    'attendance' => 'Attendance',
                    'payments'   => 'Payments',
                    'reports'    => 'Reports',
                ];
                echo h($titles[$active_view] ?? 'Dashboard');
                ?>
            </span>
        </div>
        <div class="topbar-right">
            <div class="avatar" title="Admin">A</div>
        </div>
    </div>

    <!-- CONTENT -->
    <div class="content">

        <?php if ($message): ?>
            <div class="alert <?php echo $message_type === 'error' ? 'alert-error' : 'alert-success'; ?>" id="alert-msg">
                <?php echo $message_type === 'error' ? '⚠️' : '✅'; ?>
                <?php echo h($message); ?>
            </div>
        <?php endif; ?>

        <!-- ═══════════════ DASHBOARD ═══════════════ -->
        <?php if ($active_view === 'dashboard'): ?>
            <?php $stats = get_dashboard_stats($pdo, $period); ?>

            <div class="section-header">
                <div>
                    <div class="section-title">Overview</div>
                    <div class="section-subtitle">Period: <?php echo h($period); ?></div>
                </div>
            </div>

            <div class="stats-grid">
                <div class="stat-card blue">
                    <div class="stat-icon blue">👥</div>
                    <div class="stat-value"><?php echo $stats['total_members']; ?></div>
                    <div class="stat-label">Total Members</div>
                </div>
                <div class="stat-card green">
                    <div class="stat-icon green">✅</div>
                    <div class="stat-value"><?php echo $stats['active_members']; ?></div>
                    <div class="stat-label">Active Members</div>
                </div>
                <div class="stat-card amber">
                    <div class="stat-icon amber">💰</div>
                    <div class="stat-value">$<?php echo money($stats['revenue']); ?></div>
                    <div class="stat-label">Revenue Collected</div>
                </div>
                <div class="stat-card red">
                    <div class="stat-icon red">📋</div>
                    <div class="stat-value">$<?php echo money($stats['outstanding']); ?></div>
                    <div class="stat-label">Outstanding Balance</div>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <div class="card-title">
                        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <circle cx="12" cy="12" r="10" stroke-width="2"/>
                            <path d="M12 6v6l4 2" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                        Recent Activity
                    </div>
                </div>
                <div class="card-body">
                    <div class="empty-state">
                        <div class="empty-icon">🚀</div>
                        <p>System is live. Use the navigation to manage members, sessions, attendance, and payments.</p>
                    </div>
                </div>
            </div>

        <!-- ═══════════════ MEMBERS ═══════════════ -->
        <?php elseif ($active_view === 'members'): ?>

            <div class="section-header">
                <div>
                    <div class="section-title">Members</div>
                    <div class="section-subtitle">Manage academy membership roster</div>
                </div>
            </div>

            <div class="two-col">
                <!-- Form -->
                <div class="card">
                    <div class="card-header">
                        <div class="card-title">
                            <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path d="M12 5v14M5 12h14" stroke-width="2.5" stroke-linecap="round"/>
                            </svg>
                            Add New Member
                        </div>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <input type="hidden" name="action" value="create_member">
                            <div class="form-grid">
                                <div class="form-group full">
                                    <label>Full Name *</label>
                                    <input type="text" name="full_name" placeholder="e.g. John Doe" required>
                                </div>
                                <div class="form-group full">
                                    <label>Phone Number</label>
                                    <input type="tel" name="phone" placeholder="+1 555 000 0000">
                                </div>
                                <div class="form-group">
                                    <label>Monthly Fee *</label>
                                    <input type="number" name="monthly_fee" step="0.01" placeholder="0.00" required>
                                </div>
                                <div class="form-group">
                                    <label>Due Day (1–31) *</label>
                                    <input type="number" name="due_day" min="1" max="31" value="5" required>
                                </div>
                            </div>
                            <div class="form-actions">
                                <button type="submit" class="btn btn-primary">Create Member</button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Table -->
                <div class="card">
                    <div class="card-header" style="flex-wrap:wrap;gap:10px;">
                        <div class="card-title">
                            <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" stroke-width="2" stroke-linecap="round"/>
                                <circle cx="9" cy="7" r="4" stroke-width="2"/>
                            </svg>
                            All Members
                        </div>
                        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                            <div style="position:relative;">
                                <svg style="position:absolute;left:10px;top:50%;transform:translateY(-50%);pointer-events:none;color:var(--text-3);"
                                     width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <circle cx="11" cy="11" r="8" stroke-width="2"/>
                                    <path d="M21 21l-4.35-4.35" stroke-width="2" stroke-linecap="round"/>
                                </svg>
                                <input type="text" id="memberSearch" placeholder="Search members…"
                                       style="padding:7px 12px 7px 32px;width:200px;margin:0;font-size:13px;"
                                       oninput="filterMembers()">
                            </div>
                            <span class="badge badge-blue" id="memberCount"><?php echo count(get_all_members($pdo)); ?> total</span>
                        </div>
                    </div>
                    <div class="table-wrap">
                        <table id="membersTable">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Phone</th>
                                    <th>Fee / mo</th>
                                    <th>Balance</th>
                                    <th>Status</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (get_all_members($pdo) as $member): ?>
                                    <tr>
                                        <td class="name-cell"><?php echo h($member['full_name']); ?></td>
                                        <td><?php echo h($member['phone'] ?? '—'); ?></td>
                                        <td class="mono">$<?php echo money($member['monthly_fee']); ?></td>
                                        <td class="mono">$<?php echo money($member['balance_remaining']); ?></td>
                                        <td>
                                            <span class="badge <?php echo $member['is_active'] ? 'badge-green' : 'badge-gray'; ?>">
                                                <?php echo $member['is_active'] ? 'Active' : 'Inactive'; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($member['is_active']): ?>
                                                <form method="POST" style="margin:0;">
                                                    <input type="hidden" name="action" value="deactivate_member">
                                                    <input type="hidden" name="id" value="<?php echo $member['id']; ?>">
                                                    <button type="submit" class="btn btn-danger btn-sm">Deactivate</button>
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

        <!-- ═══════════════ ATTENDANCE ═══════════════ -->
        <?php elseif ($active_view === 'attendance'): ?>

            <div class="section-header">
                <div>
                    <div class="section-title">Attendance</div>
                    <div class="section-subtitle">Track member sessions and participation</div>
                </div>
            </div>

            <?php $sessions = get_all_sessions($pdo); ?>

            <div class="two-col">
                <div style="display:flex;flex-direction:column;gap:20px;">

                    <!-- Create Session -->
                    <div class="card">
                        <div class="card-header">
                            <div class="card-title">
                                <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <rect x="3" y="4" width="18" height="18" rx="2" stroke-width="2"/>
                                    <path d="M16 2v4M8 2v4M3 10h18" stroke-width="2" stroke-linecap="round"/>
                                </svg>
                                New Session
                            </div>
                        </div>
                        <div class="card-body">
                            <form method="POST">
                                <input type="hidden" name="action" value="create_session">
                                <div class="form-group" style="margin-bottom:14px;">
                                    <label>Session Name *</label>
                                    <input type="text" name="name" placeholder="e.g. Morning Training" required>
                                </div>
                                <div class="form-group">
                                    <label>Date *</label>
                                    <input type="date" name="date" value="<?php echo date('Y-m-d'); ?>" required>
                                </div>
                                <div class="form-actions">
                                    <button type="submit" class="btn btn-primary">Create Session</button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Log Attendance -->
                    <div class="card">
                        <div class="card-header">
                            <div class="card-title">
                                <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path d="M9 11l3 3L22 4" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                    <path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11" stroke-width="2" stroke-linecap="round"/>
                                </svg>
                                Log Attendance
                            </div>
                        </div>
                        <div class="card-body">
                            <?php if (count($sessions) > 0): ?>
                                <form method="POST">
                                    <input type="hidden" name="action" value="log_attendance">
                                    <div class="form-group" style="margin-bottom:14px;">
                                        <label>Session *</label>
                                        <select name="session_id" required>
                                            <option value="">— Choose Session —</option>
                                            <?php foreach ($sessions as $s): ?>
                                                <option value="<?php echo $s['id']; ?>">
                                                    <?php echo h($s['name']); ?> · <?php echo h($s['date']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="form-group" style="margin-bottom:14px;">
                                        <label>Member *</label>
                                        <select name="member_id" required>
                                            <option value="">— Choose Member —</option>
                                            <?php foreach (get_active_members($pdo) as $m): ?>
                                                <option value="<?php echo $m['id']; ?>"><?php echo h($m['full_name']); ?></option>
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
                                    <div class="form-actions">
                                        <button type="submit" class="btn btn-primary">Record Attendance</button>
                                    </div>
                                </form>
                            <?php else: ?>
                                <div class="empty-state" style="padding:24px 0;">
                                    <div class="empty-icon">📅</div>
                                    <p>Create a session first to log attendance.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Sessions Table -->
                <div class="card">
                    <div class="card-header">
                        <div class="card-title">
                            <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01" stroke-width="2" stroke-linecap="round"/>
                            </svg>
                            Sessions
                        </div>
                        <span class="badge badge-blue"><?php echo count($sessions); ?> sessions</span>
                    </div>
                    <div class="table-wrap">
                        <?php if (count($sessions) > 0): ?>
                            <table>
                                <thead>
                                    <tr>
                                        <th>Session</th>
                                        <th>Date</th>
                                        <th>Attendees</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($sessions as $s): ?>
                                        <?php $cnt = count(get_attendance_by_session($pdo, $s['id'])); ?>
                                        <tr>
                                            <td class="name-cell"><?php echo h($s['name']); ?></td>
                                            <td><?php echo h($s['date']); ?></td>
                                            <td><span class="badge badge-blue"><?php echo $cnt; ?></span></td>
                                            <td>
                                                <form method="POST" style="margin:0;">
                                                    <input type="hidden" name="action" value="delete_session">
                                                    <input type="hidden" name="id" value="<?php echo $s['id']; ?>">
                                                    <button type="submit" class="btn btn-danger btn-sm"
                                                            onclick="return confirm('Delete this session and all its attendance records?')">
                                                        Delete
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <div class="empty-state">
                                <div class="empty-icon">📅</div>
                                <p>No sessions yet. Create one to get started.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

        <!-- ═══════════════ PAYMENTS ═══════════════ -->
        <?php elseif ($active_view === 'payments'): ?>

            <div class="section-header">
                <div>
                    <div class="section-title">Payments</div>
                    <div class="section-subtitle">Monthly billing and payment tracking — <?php echo h($period); ?></div>
                </div>
            </div>

            <div class="two-col">
                <!-- Record Payment Form -->
                <div class="card">
                    <div class="card-header">
                        <div class="card-title">
                            <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path d="M12 5v14M5 12h14" stroke-width="2.5" stroke-linecap="round"/>
                            </svg>
                            Record Payment
                        </div>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <input type="hidden" name="action" value="record_payment">
                            <div class="form-group" style="margin-bottom:14px;">
                                <label>Member *</label>
                                <select name="member_id" required>
                                    <option value="">— Choose Member —</option>
                                    <?php foreach (get_active_members($pdo) as $m): ?>
                                        <option value="<?php echo $m['id']; ?>">
                                            <?php echo h($m['full_name']); ?> · Balance: $<?php echo money($m['balance_remaining']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Amount Paid *</label>
                                <input type="number" name="amount" step="0.01" placeholder="0.00" required>
                            </div>
                            <div class="form-actions">
                                <button type="submit" class="btn btn-primary">Record Payment</button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Billing Table -->
                <div class="card">
                    <div class="card-header">
                        <div class="card-title">
                            <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <rect x="1" y="4" width="22" height="16" rx="2" stroke-width="2"/>
                                <path d="M1 10h22" stroke-width="2"/>
                            </svg>
                            Monthly Billing
                        </div>
                        <span class="badge badge-blue"><?php echo h($period); ?></span>
                    </div>
                    <div class="table-wrap">
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
                                    <?php
                                        $badge = 'badge-gray';
                                        if ($row['effective_status'] === 'PAID')    $badge = 'badge-green';
                                        if ($row['effective_status'] === 'PARTIAL') $badge = 'badge-amber';
                                        if ($row['effective_status'] === 'UNPAID')  $badge = 'badge-red';
                                    ?>
                                    <tr>
                                        <td class="name-cell"><?php echo h($row['full_name']); ?></td>
                                        <td class="mono">$<?php echo money($row['effective_expected']); ?></td>
                                        <td class="mono">$<?php echo money($row['effective_paid']); ?></td>
                                        <td class="mono">$<?php echo money($row['effective_remaining']); ?></td>
                                        <td><?php echo h($row['effective_due_date']); ?></td>
                                        <td><span class="badge <?php echo $badge; ?>"><?php echo h($row['effective_status']); ?></span></td>
                                        <td>
                                            <?php if ($row['overdue_days'] > 0): ?>
                                                <span class="badge badge-red"><?php echo $row['overdue_days']; ?>d</span>
                                            <?php else: ?>
                                                <span style="color:var(--text-3);">—</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        <!-- ═══════════════ REPORTS ═══════════════ -->
        <?php elseif ($active_view === 'reports'): ?>

            <?php
                $stats   = get_dashboard_stats($pdo, $period);
                $summary = get_monthly_summary($pdo, $period);
                $pay     = $summary['payment'];
                $att     = $summary['attendance'];

                $total_records = max(1, (int)($att['total_records'] ?? 1));
                $pct_present = $total_records > 0 ? round((($att['present_count'] ?? 0) / $total_records) * 100) : 0;
                $pct_absent  = $total_records > 0 ? round((($att['absent_count']  ?? 0) / $total_records) * 100) : 0;
                $pct_late    = $total_records > 0 ? round((($att['late_count']    ?? 0) / $total_records) * 100) : 0;

                $total_bills = max(1, (int)($pay['total_bills'] ?? 1));
                $pct_paid    = $total_bills > 0 ? round((($pay['paid_count']    ?? 0) / $total_bills) * 100) : 0;
                $pct_partial = $total_bills > 0 ? round((($pay['partial_count'] ?? 0) / $total_bills) * 100) : 0;
                $pct_unpaid  = $total_bills > 0 ? round((($pay['unpaid_count']  ?? 0) / $total_bills) * 100) : 0;

                $collection_rate = ((float)($pay['total_expected'] ?? 0)) > 0
                    ? round(((float)($pay['total_paid'] ?? 0) / (float)$pay['total_expected']) * 100, 1)
                    : 0;
            ?>

            <div class="section-header">
                <div>
                    <div class="section-title">Monthly Report</div>
                    <div class="section-subtitle">Full overview for period <strong><?php echo h($period); ?></strong></div>
                </div>
                <div style="display:flex;gap:10px;flex-wrap:wrap;">
                    <form method="POST" style="margin:0;">
                        <input type="hidden" name="action" value="export_csv">
                        <input type="hidden" name="export_type" value="payment_report">
                        <input type="hidden" name="period" value="<?php echo h($period); ?>">
                        <button type="submit" class="btn btn-ghost btn-sm">📊 Export Payments</button>
                    </form>
                    <form method="POST" style="margin:0;">
                        <input type="hidden" name="action" value="export_csv">
                        <input type="hidden" name="export_type" value="attendance_report">
                        <input type="hidden" name="period" value="<?php echo h($period); ?>">
                        <button type="submit" class="btn btn-ghost btn-sm">📋 Export Attendance</button>
                    </form>
                </div>
            </div>

            <!-- ── Top KPIs ── -->
            <div class="stats-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:20px;">
                <div class="stat-card blue">
                    <div class="stat-icon blue">👥</div>
                    <div class="stat-value"><?php echo $stats['active_members']; ?></div>
                    <div class="stat-label">Active Members</div>
                </div>
                <div class="stat-card green">
                    <div class="stat-icon green">📅</div>
                    <div class="stat-value"><?php echo (int)($att['total_sessions'] ?? 0); ?></div>
                    <div class="stat-label">Sessions Held</div>
                </div>
                <div class="stat-card amber">
                    <div class="stat-icon amber">💰</div>
                    <div class="stat-value">$<?php echo money($pay['total_paid'] ?? 0); ?></div>
                    <div class="stat-label">Collected</div>
                </div>
                <div class="stat-card red">
                    <div class="stat-icon red">⏳</div>
                    <div class="stat-value"><?php echo $collection_rate; ?>%</div>
                    <div class="stat-label">Collection Rate</div>
                </div>
            </div>

            <!-- ── Two summary cards side by side ── -->
            <div class="report-row" style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px;">

                <!-- Attendance Summary -->
                <div class="card">
                    <div class="card-header">
                        <div class="card-title">
                            <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path d="M9 11l3 3L22 4" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                <path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11" stroke-width="2" stroke-linecap="round"/>
                            </svg>
                            Attendance Overview
                        </div>
                        <span class="badge badge-blue"><?php echo h($period); ?></span>
                    </div>
                    <div class="card-body">
                        <!-- Numbers row -->
                        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:20px;">
                            <div style="text-align:center;background:var(--green-dim);border:1px solid rgba(45,212,160,.2);border-radius:var(--radius);padding:14px 10px;">
                                <div style="font-family:'Syne',sans-serif;font-size:24px;font-weight:800;color:var(--green);"><?php echo (int)($att['present_count'] ?? 0); ?></div>
                                <div style="font-size:11px;color:var(--text-3);text-transform:uppercase;letter-spacing:.05em;margin-top:4px;">Present</div>
                            </div>
                            <div style="text-align:center;background:var(--red-dim);border:1px solid rgba(248,113,113,.2);border-radius:var(--radius);padding:14px 10px;">
                                <div style="font-family:'Syne',sans-serif;font-size:24px;font-weight:800;color:var(--red);"><?php echo (int)($att['absent_count'] ?? 0); ?></div>
                                <div style="font-size:11px;color:var(--text-3);text-transform:uppercase;letter-spacing:.05em;margin-top:4px;">Absent</div>
                            </div>
                            <div style="text-align:center;background:var(--amber-dim);border:1px solid rgba(245,158,11,.2);border-radius:var(--radius);padding:14px 10px;">
                                <div style="font-family:'Syne',sans-serif;font-size:24px;font-weight:800;color:var(--amber);"><?php echo (int)($att['late_count'] ?? 0); ?></div>
                                <div style="font-size:11px;color:var(--text-3);text-transform:uppercase;letter-spacing:.05em;margin-top:4px;">Late</div>
                            </div>
                        </div>

                        <!-- Progress bars -->
                        <?php foreach ([
                            ['Present', $pct_present, 'var(--green)'],
                            ['Absent',  $pct_absent,  'var(--red)'],
                            ['Late',    $pct_late,    'var(--amber)'],
                        ] as [$label, $pct, $color]): ?>
                        <div style="margin-bottom:12px;">
                            <div style="display:flex;justify-content:space-between;margin-bottom:5px;">
                                <span style="font-size:12px;color:var(--text-3);"><?php echo $label; ?></span>
                                <span style="font-size:12px;font-weight:700;color:var(--text-2);"><?php echo $pct; ?>%</span>
                            </div>
                            <div style="height:6px;background:var(--surface-2);border-radius:100px;overflow:hidden;">
                                <div style="height:100%;width:<?php echo $pct; ?>%;background:<?php echo $color; ?>;border-radius:100px;transition:width .6s ease;"></div>
                            </div>
                        </div>
                        <?php endforeach; ?>

                        <div style="margin-top:16px;padding-top:14px;border-top:1px solid var(--border);display:flex;justify-content:space-between;">
                            <span style="font-size:12px;color:var(--text-3);">Total attendance records</span>
                            <span style="font-size:13px;font-weight:700;color:var(--text-1);"><?php echo (int)($att['total_records'] ?? 0); ?></span>
                        </div>
                    </div>
                </div>

                <!-- Payment Summary -->
                <div class="card">
                    <div class="card-header">
                        <div class="card-title">
                            <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <rect x="1" y="4" width="22" height="16" rx="2" stroke-width="2"/>
                                <path d="M1 10h22" stroke-width="2"/>
                            </svg>
                            Payment Overview
                        </div>
                        <span class="badge badge-blue"><?php echo h($period); ?></span>
                    </div>
                    <div class="card-body">
                        <!-- Numbers row -->
                        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:20px;">
                            <div style="text-align:center;background:var(--green-dim);border:1px solid rgba(45,212,160,.2);border-radius:var(--radius);padding:14px 10px;">
                                <div style="font-family:'Syne',sans-serif;font-size:24px;font-weight:800;color:var(--green);"><?php echo (int)($pay['paid_count'] ?? 0); ?></div>
                                <div style="font-size:11px;color:var(--text-3);text-transform:uppercase;letter-spacing:.05em;margin-top:4px;">Paid</div>
                            </div>
                            <div style="text-align:center;background:var(--amber-dim);border:1px solid rgba(245,158,11,.2);border-radius:var(--radius);padding:14px 10px;">
                                <div style="font-family:'Syne',sans-serif;font-size:24px;font-weight:800;color:var(--amber);"><?php echo (int)($pay['partial_count'] ?? 0); ?></div>
                                <div style="font-size:11px;color:var(--text-3);text-transform:uppercase;letter-spacing:.05em;margin-top:4px;">Partial</div>
                            </div>
                            <div style="text-align:center;background:var(--red-dim);border:1px solid rgba(248,113,113,.2);border-radius:var(--border));border-radius:var(--radius);padding:14px 10px;">
                                <div style="font-family:'Syne',sans-serif;font-size:24px;font-weight:800;color:var(--red);"><?php echo (int)($pay['unpaid_count'] ?? 0); ?></div>
                                <div style="font-size:11px;color:var(--text-3);text-transform:uppercase;letter-spacing:.05em;margin-top:4px;">Unpaid</div>
                            </div>
                        </div>

                        <!-- Progress bars -->
                        <?php foreach ([
                            ['Paid',    $pct_paid,    'var(--green)'],
                            ['Partial', $pct_partial, 'var(--amber)'],
                            ['Unpaid',  $pct_unpaid,  'var(--red)'],
                        ] as [$label, $pct, $color]): ?>
                        <div style="margin-bottom:12px;">
                            <div style="display:flex;justify-content:space-between;margin-bottom:5px;">
                                <span style="font-size:12px;color:var(--text-3);"><?php echo $label; ?></span>
                                <span style="font-size:12px;font-weight:700;color:var(--text-2);"><?php echo $pct; ?>%</span>
                            </div>
                            <div style="height:6px;background:var(--surface-2);border-radius:100px;overflow:hidden;">
                                <div style="height:100%;width:<?php echo $pct; ?>%;background:<?php echo $color; ?>;border-radius:100px;transition:width .6s ease;"></div>
                            </div>
                        </div>
                        <?php endforeach; ?>

                        <div style="margin-top:16px;padding-top:14px;border-top:1px solid var(--border);">
                            <div style="display:flex;justify-content:space-between;margin-bottom:6px;">
                                <span style="font-size:12px;color:var(--text-3);">Total expected</span>
                                <span style="font-size:13px;font-weight:700;color:var(--text-1);">$<?php echo money($pay['total_expected'] ?? 0); ?></span>
                            </div>
                            <div style="display:flex;justify-content:space-between;margin-bottom:6px;">
                                <span style="font-size:12px;color:var(--text-3);">Total collected</span>
                                <span style="font-size:13px;font-weight:700;color:var(--green);">$<?php echo money($pay['total_paid'] ?? 0); ?></span>
                            </div>
                            <div style="display:flex;justify-content:space-between;">
                                <span style="font-size:12px;color:var(--text-3);">Still outstanding</span>
                                <span style="font-size:13px;font-weight:700;color:var(--red);">$<?php echo money($pay['total_remaining'] ?? 0); ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── Bottom detail tables ── -->
            <div class="report-row" style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">

                <!-- Top Attenders -->
                <div class="card">
                    <div class="card-header">
                        <div class="card-title">🏅 Top Attenders</div>
                        <span class="badge badge-green">this period</span>
                    </div>
                    <div class="table-wrap">
                        <?php if (count($summary['top_attenders']) > 0): ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Member</th>
                                    <th>Present</th>
                                    <th>Absent</th>
                                    <th>Late</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($summary['top_attenders'] as $i => $row): ?>
                                <tr>
                                    <td style="color:var(--text-3);font-size:12px;"><?php echo $i + 1; ?></td>
                                    <td class="name-cell"><?php echo h($row['full_name']); ?></td>
                                    <td><span class="badge badge-green"><?php echo (int)$row['present']; ?></span></td>
                                    <td><span class="badge badge-red"><?php echo (int)$row['absent']; ?></span></td>
                                    <td><span class="badge badge-amber"><?php echo (int)$row['late']; ?></span></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php else: ?>
                            <div class="empty-state"><div class="empty-icon">📅</div><p>No attendance data for this period.</p></div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Outstanding Payments -->
                <div class="card">
                    <div class="card-header">
                        <div class="card-title">⚠️ Unpaid / Partial</div>
                        <span class="badge badge-red">needs attention</span>
                    </div>
                    <div class="table-wrap">
                        <?php if (count($summary['unpaid_members']) > 0): ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>Member</th>
                                    <th>Expected</th>
                                    <th>Paid</th>
                                    <th>Remaining</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($summary['unpaid_members'] as $row): ?>
                                <tr>
                                    <td class="name-cell"><?php echo h($row['full_name']); ?></td>
                                    <td class="mono">$<?php echo money($row['expected_amount']); ?></td>
                                    <td class="mono">$<?php echo money($row['paid_amount'] ?? 0); ?></td>
                                    <td><span class="badge badge-red">$<?php echo money($row['remaining']); ?></span></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php else: ?>
                            <div class="empty-state"><div class="empty-icon">🎉</div><p>All bills are settled for this period!</p></div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

        <?php endif; ?>
    </div><!-- /content -->
</div><!-- /main -->

<script>
    // Auto-dismiss alert
    (function() {
        const a = document.getElementById('alert-msg');
        if (a) {
            setTimeout(() => {
                a.style.transition = 'opacity .4s';
                a.style.opacity = '0';
                setTimeout(() => a.remove(), 400);
            }, 5000);
        }
    })();

    // Member search filter
    function filterMembers() {
        const q = document.getElementById('memberSearch').value.toLowerCase().trim();
        const rows = document.querySelectorAll('#membersTable tbody tr');
        let visible = 0;
        rows.forEach(row => {
            const text = row.textContent.toLowerCase();
            const show = !q || text.includes(q);
            row.style.display = show ? '' : 'none';
            if (show) visible++;
        });
        const badge = document.getElementById('memberCount');
        if (badge) badge.textContent = visible + ' ' + (q ? 'found' : 'total');
    }

    // Mobile sidebar
    function toggleSidebar() {
        document.getElementById('sidebar').classList.toggle('open');
        document.getElementById('overlay').classList.toggle('active');
    }

    function closeSidebar() {
        document.getElementById('sidebar').classList.remove('open');
        document.getElementById('overlay').classList.remove('active');
    }
</script>
</body>
</html>
