<?php
/**
 * INTEGRATED ACADEMY MANAGEMENT SYSTEM (AMS)
 * Single PHP File - Complete Sports Club Management Platform
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

        ALTER TABLE public.attendance ADD COLUMN IF NOT EXISTS status VARCHAR(20) DEFAULT 'present';
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
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
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
        INSERT INTO attendance (session_id, member_id, status) VALUES (?, ?, ?)
        ON CONFLICT (session_id, member_id) DO UPDATE SET status = EXCLUDED.status
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
        VALUES (?, ?, ?, ?, ?)
        ON CONFLICT (member_id, period) DO UPDATE SET expected_amount = EXCLUDED.expected_amount, paid_amount = EXCLUDED.paid_amount, due_date = EXCLUDED.due_date
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
        'total_members'  => $members['count'] ?? 0,
        'active_members' => $active['count'] ?? 0,
        'revenue'        => $financial['revenue'] ?? 0,
        'outstanding'    => $financial['outstanding'] ?? 0
    ];
}

function get_monthly_summary($pdo, $period) {
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

    $stmt = $pdo->prepare("
        SELECT
            COUNT(DISTINCT att.session_id) as total_sessions,
            COUNT(att.id)                  as total_records,
            SUM(CASE WHEN att.status = 'present' THEN 1 ELSE 0 END) as present_count,
            SUM(CASE WHEN att.status = 'absent'  THEN 1 ELSE 0 END) as absent_count,
            SUM(CASE WHEN att.status = 'late'    THEN 1 ELSE 0 END) as late_count
        FROM attendance att
        WHERE att.session_id IN (
            SELECT id FROM sessions WHERE TO_CHAR(date, 'YYYY-MM') = ?
        )
    ");
    $stmt->execute([$period]);
    $att = $stmt->fetch();

    if (!$att || (int)($att['total_records'] ?? 0) === 0) {
        $s2 = $pdo->prepare("SELECT COUNT(*) as c FROM sessions WHERE TO_CHAR(date,'YYYY-MM') = ?");
        $s2->execute([$period]);
        $sc = $s2->fetch();
        $att = [
            'total_sessions' => $sc['c'] ?? 0,
            'total_records'  => 0,
            'present_count'  => 0,
            'absent_count'   => 0,
            'late_count'     => 0,
        ];
    }

    $stmt = $pdo->prepare("
        SELECT m.full_name,
            COALESCE(agg.sessions_attended, 0) as sessions_attended,
            COALESCE(agg.present, 0) as present,
            COALESCE(agg.absent,  0) as absent,
            COALESCE(agg.late,    0) as late
        FROM members m
        LEFT JOIN (
            SELECT att.member_id,
                COUNT(att.id) as sessions_attended,
                SUM(CASE WHEN att.status = 'present' THEN 1 ELSE 0 END) as present,
                SUM(CASE WHEN att.status = 'absent'  THEN 1 ELSE 0 END) as absent,
                SUM(CASE WHEN att.status = 'late'    THEN 1 ELSE 0 END) as late
            FROM attendance att
            WHERE att.session_id IN (
                SELECT id FROM sessions WHERE TO_CHAR(date, 'YYYY-MM') = ?
            )
            GROUP BY att.member_id
        ) agg ON agg.member_id = m.id
        WHERE m.is_active = TRUE
        ORDER BY COALESCE(agg.present, 0) DESC, COALESCE(agg.sessions_attended, 0) DESC
        LIMIT 10
    ");
    $stmt->execute([$period]);
    $top_attenders = $stmt->fetchAll();

    return [
        'payment'        => $pay,
        'attendance'     => $att,
        'top_attenders'  => $top_attenders,
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
                'full_name'   => $_POST['full_name'] ?? '',
                'phone'       => $_POST['phone'] ?? '',
                'monthly_fee' => $_POST['monthly_fee'] ?? 0,
                'due_day'     => $_POST['due_day'] ?? 5
            ]);
            redirect_app(current_period(), 'members', 'Member created successfully!');
        }

        if ($action === 'update_member') {
            update_member($pdo, $_POST['id'] ?? 0, [
                'full_name'   => $_POST['full_name'] ?? '',
                'phone'       => $_POST['phone'] ?? '',
                'monthly_fee' => $_POST['monthly_fee'] ?? 0,
                'due_day'     => $_POST['due_day'] ?? 5
            ]);
            redirect_app(current_period(), 'members', 'Member updated successfully!');
        }

        if ($action === 'deactivate_member') {
            deactivate_member($pdo, $_POST['id'] ?? 0);
            redirect_app(current_period(), 'members', 'Member deactivated!');
        }

        if ($action === 'create_session') {
            create_session($pdo, $_POST['name'] ?? '', $_POST['date'] ?? date('Y-m-d'));
            redirect_app(current_period(), 'attendance', 'Session created!');
        }

        if ($action === 'delete_session') {
            delete_session($pdo, $_POST['id'] ?? 0);
            redirect_app(current_period(), 'attendance', 'Session deleted!');
        }

        if ($action === 'log_attendance') {
            log_attendance($pdo, $_POST['session_id'] ?? 0, $_POST['member_id'] ?? 0, $_POST['status'] ?? 'present');
            redirect_app(current_period(), 'attendance', 'Attendance recorded!');
        }

        if ($action === 'record_payment') {
            record_payment($pdo, $_POST['member_id'] ?? 0, $_POST['amount'] ?? 0, current_period());
            redirect_app(current_period(), 'payments', 'Payment recorded!');
        }

        if ($action === 'export_csv') {
            $export_type = $_POST['export_type'] ?? '';
            $period      = valid_period($_POST['period'] ?? current_period());

            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $export_type . '_' . $period . '.csv"');
            $output = fopen('php://output', 'w');

            // ── 1. Attendance Matrix (member × session) ──────────────────────
            if ($export_type === 'attendance_report') {
                $selected_ids = array_filter(array_map('intval', $_POST['session_ids'] ?? []));

                if (count($selected_ids) > 0) {
                    $placeholders = implode(',', array_fill(0, count($selected_ids), '?'));
                    $stmt_sessions = $pdo->prepare("SELECT * FROM sessions WHERE id IN ($placeholders) ORDER BY date ASC");
                    $stmt_sessions->execute($selected_ids);
                } else {
                    $stmt_sessions = $pdo->prepare("SELECT * FROM sessions WHERE TO_CHAR(date,'YYYY-MM') = ? ORDER BY date ASC");
                    $stmt_sessions->execute([$period]);
                }
                $export_sessions  = $stmt_sessions->fetchAll();
                $session_ids_used = array_column($export_sessions, 'id');

                $header = ['Member'];
                foreach ($export_sessions as $es) {
                    $header[] = $es['name'] . ' (' . $es['date'] . ')';
                }
                $header[] = 'Total Present';
                $header[] = 'Total Absent';
                $header[] = 'Total Late';
                $header[] = 'Attendance Rate (%)';
                fputcsv($output, $header);

                $att_map = [];
                if (count($session_ids_used) > 0) {
                    $ph       = implode(',', array_fill(0, count($session_ids_used), '?'));
                    $stmt_att = $pdo->prepare("SELECT * FROM attendance WHERE session_id IN ($ph)");
                    $stmt_att->execute($session_ids_used);
                    foreach ($stmt_att->fetchAll() as $a) {
                        $att_map[$a['session_id']][$a['member_id']] = $a['status'] ?? 'present';
                    }
                }

                foreach (get_active_members($pdo) as $m) {
                    $row    = [$m['full_name']];
                    $totals = ['present' => 0, 'absent' => 0, 'late' => 0];
                    foreach ($export_sessions as $es) {
                        $status = $att_map[$es['id']][$m['id']] ?? 'not recorded';
                        $row[]  = $status;
                        if ($status === 'present') $totals['present']++;
                        elseif ($status === 'absent') $totals['absent']++;
                        elseif ($status === 'late') $totals['late']++;
                    }
                    $row[] = $totals['present'];
                    $row[] = $totals['absent'];
                    $row[] = $totals['late'];
                    $rate  = count($export_sessions) > 0
                        ? round(($totals['present'] / count($export_sessions)) * 100)
                        : 0;
                    $row[] = $rate . '%';
                    fputcsv($output, $row);
                }

                // Footer: session totals
                $footer = ['SESSION TOTALS'];
                $grand = ['present'=>0,'absent'=>0,'late'=>0];
                foreach ($export_sessions as $es) {
                    $p = $a = $l = 0;
                    foreach (get_active_members($pdo) as $m) {
                        $s = $att_map[$es['id']][$m['id']] ?? null;
                        if ($s === 'present') { $p++; $grand['present']++; }
                        elseif ($s === 'absent') { $a++; $grand['absent']++; }
                        elseif ($s === 'late') { $l++; $grand['late']++; }
                    }
                    $footer[] = "P:$p A:$a L:$l";
                }
                $footer[] = $grand['present'];
                $footer[] = $grand['absent'];
                $footer[] = $grand['late'];
                $footer[] = '';
                fputcsv($output, $footer);
            }

            // ── 1b. Filtered Attendance (present / absent / late only) ────────
            elseif ($export_type === 'attendance_filtered') {
                $filter_status = $_POST['filter_status'] ?? 'present'; // present|absent|late|all
                $selected_ids  = array_filter(array_map('intval', $_POST['session_ids'] ?? []));

                if (count($selected_ids) > 0) {
                    $ph2 = implode(',', array_fill(0, count($selected_ids), '?'));
                    $stmt_sessions = $pdo->prepare("SELECT * FROM sessions WHERE id IN ($ph2) ORDER BY date ASC");
                    $stmt_sessions->execute($selected_ids);
                } else {
                    $stmt_sessions = $pdo->prepare("SELECT * FROM sessions WHERE TO_CHAR(date,'YYYY-MM') = ? ORDER BY date ASC");
                    $stmt_sessions->execute([$period]);
                }
                $export_sessions  = $stmt_sessions->fetchAll();
                $session_ids_used = array_column($export_sessions, 'id');

                $att_map = [];
                if (count($session_ids_used) > 0) {
                    $ph3      = implode(',', array_fill(0, count($session_ids_used), '?'));
                    $stmt_att = $pdo->prepare("SELECT * FROM attendance WHERE session_id IN ($ph3)");
                    $stmt_att->execute($session_ids_used);
                    foreach ($stmt_att->fetchAll() as $a) {
                        $att_map[$a['session_id']][$a['member_id']] = $a['status'];
                    }
                }

                $status_label = strtoupper($filter_status);
                fputcsv($output, [$status_label . ' Members Report — Period: ' . $period]);
                fputcsv($output, ['Generated: ' . date('Y-m-d H:i:s')]);
                fputcsv($output, ['Filter: ' . $status_label . ' only']);
                fputcsv($output, []);

                if ($filter_status === 'all') {
                    // All statuses — one row per member per session they have a record
                    fputcsv($output, ['Member', 'Session', 'Date', 'Status']);
                    foreach (get_active_members($pdo) as $m) {
                        foreach ($export_sessions as $es) {
                            $s = $att_map[$es['id']][$m['id']] ?? null;
                            if ($s !== null) {
                                fputcsv($output, [$m['full_name'], $es['name'], $es['date'], $s]);
                            }
                        }
                    }
                } else {
                    // Filtered: only rows matching the chosen status
                    fputcsv($output, ['Member', 'Session', 'Date', 'Status']);
                    $count_rows = 0;
                    foreach (get_active_members($pdo) as $m) {
                        foreach ($export_sessions as $es) {
                            $s = $att_map[$es['id']][$m['id']] ?? null;
                            if ($s === $filter_status) {
                                fputcsv($output, [$m['full_name'], $es['name'], $es['date'], $s]);
                                $count_rows++;
                            }
                        }
                    }
                    fputcsv($output, []);
                    fputcsv($output, ['Total ' . $status_label . ' records:', $count_rows]);
                }
            }

            // ── 2. Member Attendance Summary ─────────────────────────────────
            elseif ($export_type === 'member_summary') {
                $stmt_sessions = $pdo->prepare("SELECT * FROM sessions WHERE TO_CHAR(date,'YYYY-MM') = ? ORDER BY date ASC");
                $stmt_sessions->execute([$period]);
                $all_sessions    = $stmt_sessions->fetchAll();
                $session_ids_all = array_column($all_sessions, 'id');
                $total_sessions  = count($all_sessions);

                $att_map = [];
                if ($total_sessions > 0) {
                    $ph       = implode(',', array_fill(0, $total_sessions, '?'));
                    $stmt_att = $pdo->prepare("SELECT * FROM attendance WHERE session_id IN ($ph)");
                    $stmt_att->execute($session_ids_all);
                    foreach ($stmt_att->fetchAll() as $a) {
                        $att_map[$a['session_id']][$a['member_id']] = $a['status'];
                    }
                }

                fputcsv($output, ['Member Attendance Summary — Period: ' . $period]);
                fputcsv($output, ['Generated: ' . date('Y-m-d H:i:s')]);
                fputcsv($output, []);
                fputcsv($output, ['#', 'Member', 'Present', 'Absent', 'Late', 'Not Recorded', 'Total Sessions', 'Attendance Rate (%)']);

                $idx = 1;
                foreach (get_active_members($pdo) as $m) {
                    $p = $ab = $l = 0;
                    foreach ($session_ids_all as $sid) {
                        $s = $att_map[$sid][$m['id']] ?? null;
                        if ($s === 'present') $p++;
                        elseif ($s === 'absent') $ab++;
                        elseif ($s === 'late') $l++;
                    }
                    $not_recorded = $total_sessions - $p - $ab - $l;
                    $rate = $total_sessions > 0 ? round(($p / $total_sessions) * 100) : 0;
                    fputcsv($output, [$idx++, $m['full_name'], $p, $ab, $l, $not_recorded, $total_sessions, $rate . '%']);
                }

                // Grand totals row
                fputcsv($output, []);
                $gp = $ga = $gl = 0;
                foreach (get_active_members($pdo) as $m) {
                    foreach ($session_ids_all as $sid) {
                        $s = $att_map[$sid][$m['id']] ?? null;
                        if ($s === 'present') $gp++;
                        elseif ($s === 'absent') $ga++;
                        elseif ($s === 'late') $gl++;
                    }
                }
                fputcsv($output, ['', 'TOTAL', $gp, $ga, $gl, '', '', '']);
            }

            // ── 3. Payments / Billing Report ─────────────────────────────────
            elseif ($export_type === 'payments_report') {
                fputcsv($output, ['Payments Report — Period: ' . $period]);
                fputcsv($output, ['Generated: ' . date('Y-m-d H:i:s')]);
                fputcsv($output, []);
                fputcsv($output, ['Member', 'Expected (USD)', 'Paid (USD)', 'Remaining (USD)', 'Due Date', 'Status', 'Overdue Days']);

                $total_exp = $total_paid = $total_rem = 0;
                foreach (billing_rows($pdo, $period) as $row) {
                    fputcsv($output, [
                        $row['full_name'],
                        number_format($row['effective_expected'], 2),
                        number_format($row['effective_paid'],     2),
                        number_format($row['effective_remaining'],2),
                        $row['effective_due_date'],
                        $row['effective_status'],
                        $row['overdue_days'] > 0 ? $row['overdue_days'] . ' days' : '—',
                    ]);
                    $total_exp  += $row['effective_expected'];
                    $total_paid += $row['effective_paid'];
                    $total_rem  += $row['effective_remaining'];
                }

                fputcsv($output, []);
                fputcsv($output, ['TOTALS', number_format($total_exp,2), number_format($total_paid,2), number_format($total_rem,2), '', '', '']);
            }

            fclose($output);
            exit;
        }

    } catch (Exception $e) {
        $message      = 'Error: ' . $e->getMessage();
        $message_type = 'error';
    }
}

// ============ GET REQUEST PARAMETERS ============

$period      = valid_period($_GET['period'] ?? current_period());
$active_view = valid_view($_GET['view'] ?? 'dashboard');
if (isset($_GET['msg'])) $message = $_GET['msg'];

$active_members_json = js(array_values(array_map(function($m) {
    return ['id' => $m['id'], 'name' => $m['full_name']];
}, get_active_members($pdo))));

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

        .shell { display: flex; min-height: 100vh; }

        /* ── SIDEBAR ── */
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

        .sidebar-nav { flex: 1; padding: 12px 10px; overflow-y: auto; }

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

        .nav-item:hover { background: var(--surface-2); color: var(--text-1); }
        .nav-item.active { background: var(--accent-dim); color: var(--accent); font-weight: 600; }

        .nav-icon { width: 18px; height: 18px; flex-shrink: 0; opacity: .7; }
        .nav-item.active .nav-icon { opacity: 1; }

        .sidebar-footer { padding: 14px 20px; border-top: 1px solid var(--border); }

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

        @keyframes pulse { 0%,100%{opacity:1}50%{opacity:.4} }

        /* ── MAIN ── */
        .main { margin-left: var(--sidebar-w); flex: 1; display: flex; flex-direction: column; min-height: 100vh; }

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

        .topbar-left { display: flex; align-items: center; gap: 12px; }

        .menu-toggle {
            display: none;
            background: none;
            border: none;
            color: var(--text-2);
            cursor: pointer;
            padding: 6px;
            border-radius: var(--radius-sm);
        }

        .page-title { font-family: 'Syne', sans-serif; font-weight: 700; font-size: 18px; color: var(--text-1); }
        .topbar-right { display: flex; align-items: center; gap: 10px; }

        .avatar {
            width: 34px; height: 34px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--accent), #8b5cf6);
            display: flex; align-items: center; justify-content: center;
            font-weight: 700; font-size: 13px; color: #fff; cursor: pointer;
        }

        .content { flex: 1; padding: 28px; max-width: 1200px; width: 100%; }

        /* ── ALERTS ── */
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

        @keyframes slideDown { from{opacity:0;transform:translateY(-8px)} to{opacity:1;transform:translateY(0)} }

        .alert-success { background: var(--green-dim); border: 1px solid rgba(45,212,160,.3); color: var(--green); }
        .alert-error   { background: var(--red-dim);   border: 1px solid rgba(248,113,113,.3); color: var(--red); }

        /* ── SECTION HEADER ── */
        .section-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            margin-bottom: 24px;
            gap: 16px;
            flex-wrap: wrap;
        }

        .section-title { font-family: 'Syne', sans-serif; font-weight: 700; font-size: 22px; color: var(--text-1); }
        .section-subtitle { font-size: 13px; color: var(--text-3); margin-top: 2px; }

        /* ── STAT CARDS ── */
        .stats-grid { display: grid; grid-template-columns: repeat(4,1fr); gap: 16px; margin-bottom: 28px; }

        .stat-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 20px 22px;
            position: relative;
            overflow: hidden;
            transition: var(--transition);
        }

        .stat-card:hover { border-color: var(--border-hover); transform: translateY(-1px); }
        .stat-card::before { content:''; position:absolute; top:0; left:0; right:0; height:3px; }
        .stat-card.blue::before  { background: var(--accent); }
        .stat-card.green::before { background: var(--green); }
        .stat-card.amber::before { background: var(--amber); }
        .stat-card.red::before   { background: var(--red); }

        .stat-icon { width:38px; height:38px; border-radius:var(--radius-sm); display:flex; align-items:center; justify-content:center; font-size:18px; margin-bottom:14px; }
        .stat-icon.blue  { background: var(--accent-dim); }
        .stat-icon.green { background: var(--green-dim); }
        .stat-icon.amber { background: var(--amber-dim); }
        .stat-icon.red   { background: var(--red-dim); }

        .stat-value { font-family:'Syne',sans-serif; font-weight:800; font-size:28px; color:var(--text-1); line-height:1; margin-bottom:6px; }
        .stat-label { font-size:12px; color:var(--text-3); font-weight:500; letter-spacing:.02em; text-transform:uppercase; }

        /* ── CARD ── */
        .card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-lg); overflow: hidden; }
        .card + .card { margin-top: 20px; }

        .card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 18px 22px;
            border-bottom: 1px solid var(--border);
        }

        .card-title { font-family:'Syne',sans-serif; font-weight:700; font-size:15px; color:var(--text-1); display:flex; align-items:center; gap:8px; }
        .card-body { padding: 22px; }

        /* ── FORMS ── */
        .form-grid { display: grid; grid-template-columns: repeat(2,1fr); gap: 16px; }
        .form-grid.cols-3 { grid-template-columns: repeat(3,1fr); }
        .form-group { display: flex; flex-direction: column; gap: 6px; }
        .form-group.full { grid-column: 1/-1; }

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

        .form-actions { display: flex; gap: 10px; margin-top: 20px; flex-wrap: wrap; }

        /* ── BUTTONS ── */
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

        .btn-primary { background: var(--accent); color: #fff; box-shadow: 0 1px 8px var(--accent-glow); }
        .btn-primary:hover { background: #6b93ff; box-shadow: 0 2px 14px var(--accent-glow); }

        .btn-ghost { background: var(--surface-2); color: var(--text-2); border: 1px solid var(--border); }
        .btn-ghost:hover { background: var(--border); color: var(--text-1); }

        .btn-danger { background: var(--red-dim); color: var(--red); border: 1px solid rgba(248,113,113,.25); }
        .btn-danger:hover { background: rgba(248,113,113,.22); }

        .btn-sm { padding: 6px 12px; font-size: 12px; }

        /* ── TABLE ── */
        .table-wrap { overflow-x: auto; }

        table { width: 100%; border-collapse: collapse; font-size: 13.5px; }
        thead tr { border-bottom: 1px solid var(--border); }

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

        td { padding: 13px 16px; color: var(--text-2); border-bottom: 1px solid var(--border); vertical-align: middle; }
        tbody tr:last-child td { border-bottom: none; }
        tbody tr { transition: background .12s; }
        tbody tr:hover { background: var(--surface-2); }
        td.name-cell { color: var(--text-1); font-weight: 500; }
        td .mono { font-family: 'Courier New', monospace; font-size: 13px; }

        /* ── BADGES ── */
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

        .badge::before { content:''; width:5px; height:5px; border-radius:50%; }
        .badge-green  { background:var(--green-dim); color:var(--green); }
        .badge-green::before  { background:var(--green); }
        .badge-red    { background:var(--red-dim);   color:var(--red); }
        .badge-red::before    { background:var(--red); }
        .badge-amber  { background:var(--amber-dim); color:var(--amber); }
        .badge-amber::before  { background:var(--amber); }
        .badge-blue   { background:var(--accent-dim);color:var(--accent); }
        .badge-blue::before   { background:var(--accent); }
        .badge-gray   { background:var(--surface-2); color:var(--text-3); }
        .badge-gray::before   { background:var(--text-3); }

        /* ── MISC ── */
        .divider { height:1px; background:var(--border); margin:20px 0; }

        .empty-state { text-align:center; padding:48px 24px; color:var(--text-3); }
        .empty-icon  { font-size:36px; margin-bottom:12px; opacity:.5; }
        .empty-state p { font-size:14px; }

        .two-col { display: grid; grid-template-columns: 380px 1fr; gap: 20px; align-items: start; }

        /* ── SESSION CHECKBOX LIST ── */
        .session-list { display:flex; flex-direction:column; gap:8px; max-height:220px; overflow-y:auto; padding-right:4px; }

        .session-check-label {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 12px;
            background: var(--surface-2);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            cursor: pointer;
            transition: var(--transition);
        }

        .session-check-label:hover { border-color: var(--accent); background: var(--accent-dim); }
        .session-check-label input[type="checkbox"] { width:15px; height:15px; accent-color:var(--accent); flex-shrink:0; }

        /* ── MEMBER SEARCH WIDGET ── */
        .member-search-wrap { position: relative; }
        .member-search-input-wrap { position: relative; }
        .member-search-input-wrap svg { position: absolute; left: 11px; top: 50%; transform: translateY(-50%); pointer-events: none; color: var(--text-3); }
        .member-search-input-wrap input { padding-left: 34px; }

        .member-selected-chip {
            display: flex;
            align-items: center;
            gap: 8px;
            background: var(--accent-dim);
            border: 1px solid rgba(79,125,255,.35);
            border-radius: var(--radius-sm);
            padding: 8px 12px;
            margin-top: 6px;
            font-size: 13px;
            color: var(--accent);
            font-weight: 600;
        }

        .member-selected-chip button {
            background: none;
            border: none;
            color: var(--accent);
            cursor: pointer;
            font-size: 15px;
            line-height: 1;
            padding: 0 0 0 4px;
            margin-left: auto;
            opacity: .7;
            transition: opacity .15s;
        }

        .member-selected-chip button:hover { opacity: 1; }

        .member-dropdown {
            position: absolute;
            top: calc(100% + 4px);
            left: 0; right: 0;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            box-shadow: 0 8px 32px rgba(0,0,0,.4);
            z-index: 300;
            max-height: 220px;
            overflow-y: auto;
            display: none;
        }

        .member-dropdown.open { display: block; }

        .member-dropdown-item {
            padding: 10px 14px;
            font-size: 13px;
            color: var(--text-2);
            cursor: pointer;
            transition: background .12s;
            border-bottom: 1px solid var(--border);
        }

        .member-dropdown-item:last-child { border-bottom: none; }
        .member-dropdown-item:hover,
        .member-dropdown-item.highlighted { background: var(--accent-dim); color: var(--accent); }

        .member-dropdown-empty { padding: 14px; font-size: 13px; color: var(--text-3); text-align: center; }

        /* ── OVERLAY / MOBILE ── */
        .overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.6); z-index:150; }

        /* ── ATTENDANCE REPORT ENHANCED STYLES ── */

        /* Tab switcher */
        .report-tabs {
            display: flex;
            gap: 4px;
            background: var(--surface-2);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            padding: 4px;
            width: fit-content;
            margin-bottom: 20px;
        }

        .report-tab {
            padding: 7px 18px;
            border-radius: 4px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            border: none;
            background: none;
            color: var(--text-3);
            transition: var(--transition);
            font-family: 'DM Sans', sans-serif;
        }

        .report-tab.active {
            background: var(--accent);
            color: #fff;
            box-shadow: 0 1px 6px var(--accent-glow);
        }

        .report-tab:not(.active):hover { color: var(--text-1); background: var(--border); }

        /* Mini attendance dot grid */
        .att-dot-grid {
            display: flex;
            gap: 4px;
            flex-wrap: wrap;
            align-items: center;
        }

        .att-dot {
            width: 20px;
            height: 20px;
            border-radius: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 9px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0;
            cursor: default;
            position: relative;
            flex-shrink: 0;
        }

        .att-dot.present { background: var(--green-dim); color: var(--green); border: 1px solid rgba(45,212,160,.3); }
        .att-dot.absent  { background: var(--red-dim);   color: var(--red);   border: 1px solid rgba(248,113,113,.3); }
        .att-dot.late    { background: var(--amber-dim); color: var(--amber); border: 1px solid rgba(245,158,11,.3); }
        .att-dot.none    { background: var(--surface-2); color: var(--text-3); border: 1px solid var(--border); }

        /* Progress bar inside table */
        .inline-bar-wrap {
            display: flex;
            align-items: center;
            gap: 8px;
            min-width: 100px;
        }

        .inline-bar {
            flex: 1;
            height: 6px;
            background: var(--surface-2);
            border-radius: 100px;
            overflow: hidden;
        }

        .inline-bar-fill {
            height: 100%;
            border-radius: 100px;
            transition: width .5s ease;
        }

        /* Attendance heatmap grid — full member × session matrix */
        .matrix-wrap {
            overflow-x: auto;
            border-radius: var(--radius);
            border: 1px solid var(--border);
        }

        .matrix-table {
            border-collapse: collapse;
            width: 100%;
            font-size: 12px;
        }

        .matrix-table th {
            background: var(--surface-2);
            padding: 8px 10px;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: .06em;
            text-transform: uppercase;
            color: var(--text-3);
            white-space: nowrap;
            border-bottom: 1px solid var(--border);
            border-right: 1px solid var(--border);
        }

        .matrix-table th:last-child { border-right: none; }

        .matrix-table td {
            padding: 10px 10px;
            border-bottom: 1px solid var(--border);
            border-right: 1px solid var(--border);
            vertical-align: middle;
        }

        .matrix-table td:last-child { border-right: none; }
        .matrix-table tbody tr:last-child td { border-bottom: none; }
        .matrix-table tbody tr:hover td { background: rgba(79,125,255,.04); }

        .matrix-table td.member-name-col {
            color: var(--text-1);
            font-weight: 600;
            font-size: 13px;
            white-space: nowrap;
            position: sticky;
            left: 0;
            background: var(--surface);
            z-index: 2;
            border-right: 2px solid var(--border-hover);
        }

        .matrix-table th.session-header {
            writing-mode: horizontal-tb;
            min-width: 100px;
        }

        .matrix-cell {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .matrix-pill {
            padding: 3px 10px;
            border-radius: 100px;
            font-size: 10px;
            font-weight: 800;
            letter-spacing: .05em;
            text-transform: uppercase;
        }

        .matrix-pill.present { background: var(--green-dim); color: var(--green); border: 1px solid rgba(45,212,160,.25); }
        .matrix-pill.absent  { background: var(--red-dim);   color: var(--red);   border: 1px solid rgba(248,113,113,.25); }
        .matrix-pill.late    { background: var(--amber-dim); color: var(--amber); border: 1px solid rgba(245,158,11,.25); }
        .matrix-pill.none    { color: var(--text-3); font-size: 14px; font-weight: 400; }

        /* Summary totals row */
        .matrix-table tfoot td {
            background: var(--surface-2);
            font-weight: 700;
            color: var(--text-2);
            border-top: 2px solid var(--border-hover);
            padding: 10px;
        }

        .matrix-table tfoot td.member-name-col {
            background: var(--surface-2);
        }

        /* Filter row */
        .report-filter-row {
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
            margin-bottom: 16px;
        }

        .filter-chip {
            padding: 5px 14px;
            border-radius: 100px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            border: 1px solid var(--border);
            background: var(--surface-2);
            color: var(--text-3);
            transition: var(--transition);
            font-family: 'DM Sans', sans-serif;
        }

        .filter-chip:hover { border-color: var(--border-hover); color: var(--text-2); }
        .filter-chip.active { border-color: var(--accent); background: var(--accent-dim); color: var(--accent); }
        .filter-chip.green-chip.active  { border-color: var(--green); background: var(--green-dim); color: var(--green); }
        .filter-chip.red-chip.active    { border-color: var(--red);   background: var(--red-dim);   color: var(--red); }
        .filter-chip.amber-chip.active  { border-color: var(--amber); background: var(--amber-dim); color: var(--amber); }

        /* Summary panel above matrix */
        .summary-strips {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            margin-bottom: 18px;
        }

        .summary-strip {
            border-radius: var(--radius);
            padding: 14px 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .summary-strip.green { background: var(--green-dim); border: 1px solid rgba(45,212,160,.2); }
        .summary-strip.red   { background: var(--red-dim);   border: 1px solid rgba(248,113,113,.2); }
        .summary-strip.amber { background: var(--amber-dim); border: 1px solid rgba(245,158,11,.2); }

        .strip-label { font-size: 11px; text-transform: uppercase; letter-spacing: .06em; font-weight: 600; }
        .strip-label.green { color: var(--green); }
        .strip-label.red   { color: var(--red); }
        .strip-label.amber { color: var(--amber); }

        .strip-count { font-family: 'Syne', sans-serif; font-weight: 800; font-size: 26px; }
        .strip-count.green { color: var(--green); }
        .strip-count.red   { color: var(--red); }
        .strip-count.amber { color: var(--amber); }

        /* Search inside report */
        .report-search-wrap {
            position: relative;
            flex: 1;
            max-width: 260px;
        }

        .report-search-wrap svg {
            position: absolute;
            left: 10px;
            top: 50%;
            transform: translateY(-50%);
            pointer-events: none;
            color: var(--text-3);
        }

        .report-search-wrap input {
            padding: 7px 12px 7px 32px;
            font-size: 13px;
            margin: 0;
        }

        /* ── EXPORT BUTTON CARDS ── */
        .export-btn-card {
            display: flex;
            align-items: center;
            gap: 10px;
            width: 100%;
            padding: 12px 14px;
            background: var(--surface-2);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            cursor: pointer;
            font-family: 'DM Sans', sans-serif;
            text-align: left;
            transition: var(--transition);
        }

        .export-btn-card:hover {
            background: var(--bg);
            border-width: 2px;
            transform: translateY(-1px);
            box-shadow: 0 4px 14px rgba(0,0,0,.25);
        }

        .export-btn-card:active { transform: scale(.97); }

        @media (max-width:1024px) {
            .stats-grid  { grid-template-columns: repeat(2,1fr); }
            .two-col     { grid-template-columns: 1fr; }
            .form-grid   { grid-template-columns: 1fr; }
            .form-grid.cols-3 { grid-template-columns: 1fr 1fr; }
            .report-row  { grid-template-columns: 1fr !important; }
            .summary-strips { grid-template-columns: 1fr 1fr 1fr; }
        }

        @media (max-width:768px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.open { transform: translateX(0); }
            .overlay.active { display: block; }
            .menu-toggle { display: flex; }
            .main { margin-left: 0; }
            .content { padding: 16px; }
            .topbar { padding: 0 16px; }
            .stats-grid { grid-template-columns: 1fr 1fr; gap: 12px; }
            .stat-value { font-size: 22px; }
            .summary-strips { grid-template-columns: 1fr; }
            .report-filter-row { gap: 6px; }
        }

        @media (max-width:480px) {
            .stats-grid { grid-template-columns: 1fr; }
            .form-grid.cols-3 { grid-template-columns: 1fr; }
            .summary-strips { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

<div class="overlay" id="overlay" onclick="closeSidebar()"></div>

<!-- ── SIDEBAR ── -->
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
           class="nav-item <?php echo $active_view==='dashboard'?'active':''; ?>">
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
           class="nav-item <?php echo $active_view==='members'?'active':''; ?>">
            <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" stroke-width="2" stroke-linecap="round"/>
                <circle cx="9" cy="7" r="4" stroke-width="2"/>
                <path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75" stroke-width="2" stroke-linecap="round"/>
            </svg>
            Members
        </a>

        <a href="?view=attendance&period=<?php echo h($period); ?>"
           class="nav-item <?php echo $active_view==='attendance'?'active':''; ?>">
            <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path d="M9 11l3 3L22 4" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                <path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11" stroke-width="2" stroke-linecap="round"/>
            </svg>
            Attendance
        </a>

        <a href="?view=payments&period=<?php echo h($period); ?>"
           class="nav-item <?php echo $active_view==='payments'?'active':''; ?>">
            <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <rect x="1" y="4" width="22" height="16" rx="2" stroke-width="2"/>
                <path d="M1 10h22" stroke-width="2"/>
            </svg>
            Payments
        </a>

        <div class="nav-label">Analytics</div>
        <a href="?view=reports&period=<?php echo h($period); ?>"
           class="nav-item <?php echo $active_view==='reports'?'active':''; ?>">
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

<!-- ── MAIN ── -->
<div class="main">

    <div class="topbar">
        <div class="topbar-left">
            <button class="menu-toggle" onclick="toggleSidebar()" aria-label="Menu">
                <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-width="2" stroke-linecap="round" d="M4 6h16M4 12h16M4 18h16"/>
                </svg>
            </button>
            <span class="page-title">
                <?php
                $titles = ['dashboard'=>'Dashboard','members'=>'Members','attendance'=>'Attendance','payments'=>'Payments','reports'=>'Reports'];
                echo h($titles[$active_view] ?? 'Dashboard');
                ?>
            </span>
        </div>
        <div class="topbar-right">
            <div class="avatar" title="Admin">A</div>
        </div>
    </div>

    <div class="content">

        <?php if ($message): ?>
            <div class="alert <?php echo $message_type==='error'?'alert-error':'alert-success'; ?>" id="alert-msg">
                <?php echo $message_type==='error'?'⚠️':'✅'; ?>
                <?php echo h($message); ?>
            </div>
        <?php endif; ?>

        <!-- ═══════════ DASHBOARD ═══════════ -->
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

        <!-- ═══════════ MEMBERS ═══════════ -->
        <?php elseif ($active_view === 'members'): ?>

            <div class="section-header">
                <div>
                    <div class="section-title">Members</div>
                    <div class="section-subtitle">Manage academy membership roster</div>
                </div>
            </div>

            <div class="two-col">
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
                                            <span class="badge <?php echo $member['is_active']?'badge-green':'badge-gray'; ?>">
                                                <?php echo $member['is_active']?'Active':'Inactive'; ?>
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

        <!-- ═══════════ ATTENDANCE ═══════════ -->
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
                                <form method="POST" id="attForm">
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
                                        <input type="hidden" name="member_id" id="attMemberId">
                                        <div class="member-search-wrap" id="memberSearchWrap">
                                            <div class="member-search-input-wrap">
                                                <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <circle cx="11" cy="11" r="8" stroke-width="2"/>
                                                    <path d="M21 21l-4.35-4.35" stroke-width="2" stroke-linecap="round"/>
                                                </svg>
                                                <input
                                                    type="text"
                                                    id="attMemberSearch"
                                                    placeholder="Search by name…"
                                                    autocomplete="off"
                                                    oninput="attSearchInput(this)"
                                                    onfocus="attSearchFocus()"
                                                    onkeydown="attSearchKeydown(event)"
                                                >
                                            </div>
                                            <div class="member-dropdown" id="attMemberDropdown" role="listbox"></div>
                                            <div class="member-selected-chip" id="attSelectedChip" style="display:none;">
                                                <svg width="13" height="13" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" stroke-width="2" stroke-linecap="round"/>
                                                    <circle cx="9" cy="7" r="4" stroke-width="2"/>
                                                </svg>
                                                <span id="attSelectedName"></span>
                                                <button type="button" onclick="attClearMember()" title="Clear selection">✕</button>
                                            </div>
                                        </div>
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
                                        <button type="submit" class="btn btn-primary" id="attSubmitBtn">Record Attendance</button>
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

        <!-- ═══════════ PAYMENTS ═══════════ -->
        <?php elseif ($active_view === 'payments'): ?>

            <div class="section-header">
                <div>
                    <div class="section-title">Payments</div>
                    <div class="section-subtitle">Monthly billing and payment tracking — <?php echo h($period); ?></div>
                </div>
            </div>

            <div class="two-col">
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

        <!-- ═══════════ REPORTS ═══════════ (ENHANCED) ═══════════ -->
        <?php elseif ($active_view === 'reports'): ?>

            <?php
                $stats   = get_dashboard_stats($pdo, $period);
                $summary = get_monthly_summary($pdo, $period);
                $att     = $summary['attendance'];

                $total_records = (int)($att['total_records'] ?? 0);
                $safe_total    = max(1, $total_records);
                $pct_present   = $total_records > 0 ? round((($att['present_count'] ?? 0) / $safe_total) * 100) : 0;
                $pct_absent    = $total_records > 0 ? round((($att['absent_count']  ?? 0) / $safe_total) * 100) : 0;
                $pct_late      = $total_records > 0 ? round((($att['late_count']    ?? 0) / $safe_total) * 100) : 0;

                // All sessions for this period
                $stmt = $pdo->prepare("SELECT * FROM sessions WHERE TO_CHAR(date,'YYYY-MM') = ? ORDER BY date ASC");
                $stmt->execute([$period]);
                $period_sessions = $stmt->fetchAll();
                $session_ids_period = array_column($period_sessions, 'id');

                // ── Full member × session attendance matrix ──
                $active_members_list = get_active_members($pdo);

                // Build att_map[session_id][member_id] = status
                $att_map = [];
                if (count($session_ids_period) > 0) {
                    $ph = implode(',', array_fill(0, count($session_ids_period), '?'));
                    $stmt2 = $pdo->prepare("SELECT session_id, member_id, status FROM attendance WHERE session_id IN ($ph)");
                    $stmt2->execute($session_ids_period);
                    foreach ($stmt2->fetchAll() as $a) {
                        $att_map[$a['session_id']][$a['member_id']] = $a['status'];
                    }
                }

                // Per-member totals
                $member_totals = []; // [member_id] => [present, absent, late, total]
                foreach ($active_members_list as $m) {
                    $p = $ab = $l = 0;
                    foreach ($session_ids_period as $sid) {
                        $s = $att_map[$sid][$m['id']] ?? null;
                        if ($s === 'present') $p++;
                        elseif ($s === 'absent') $ab++;
                        elseif ($s === 'late') $l++;
                    }
                    $member_totals[$m['id']] = ['present'=>$p,'absent'=>$ab,'late'=>$l,'total'=>$p+$ab+$l];
                }

                // Per-session totals
                $session_totals = []; // [session_id] => [present, absent, late]
                foreach ($session_ids_period as $sid) {
                    $p = $ab = $l = 0;
                    foreach ($active_members_list as $m) {
                        $s = $att_map[$sid][$m['id']] ?? null;
                        if ($s === 'present') $p++;
                        elseif ($s === 'absent') $ab++;
                        elseif ($s === 'late') $l++;
                    }
                    $session_totals[$sid] = ['present'=>$p,'absent'=>$ab,'late'=>$l];
                }

                // Latest session for the existing "Latest Session" card
                $period_sessions_desc = array_reverse($period_sessions);
                $latest_session = count($period_sessions_desc) > 0 ? $period_sessions_desc[0] : null;
                $latest_attendance = [];
                if ($latest_session) {
                    $stmt3 = $pdo->prepare("
                        SELECT m.full_name, a.status
                        FROM members m
                        LEFT JOIN attendance a ON a.member_id = m.id AND a.session_id = ?
                        WHERE m.is_active = TRUE
                        ORDER BY m.full_name ASC
                    ");
                    $stmt3->execute([$latest_session['id']]);
                    $latest_attendance = $stmt3->fetchAll();
                }
            ?>

            <div class="section-header">
                <div>
                    <div class="section-title">Reports</div>
                    <div class="section-subtitle">Attendance analytics for <strong><?php echo h($period); ?></strong></div>
                </div>
            </div>

            <!-- KPI row -->
            <div class="stats-grid" style="grid-template-columns:repeat(3,1fr);margin-bottom:20px;">
                <div class="stat-card blue">
                    <div class="stat-icon blue">👥</div>
                    <div class="stat-value"><?php echo $stats['active_members']; ?></div>
                    <div class="stat-label">Active Members</div>
                </div>
                <div class="stat-card green">
                    <div class="stat-icon green">📅</div>
                    <div class="stat-value"><?php echo count($period_sessions); ?></div>
                    <div class="stat-label">Sessions Held</div>
                </div>
                <div class="stat-card amber">
                    <div class="stat-icon amber">✅</div>
                    <div class="stat-value"><?php echo (int)($att['present_count'] ?? 0); ?></div>
                    <div class="stat-label">Total Presences</div>
                </div>
            </div>

            <!-- ── MAIN ATTENDANCE REPORT CARD (NEW) ── -->
            <div class="card" style="margin-bottom:20px;">
                <div class="card-header">
                    <div class="card-title">
                        <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path d="M9 11l3 3L22 4" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                        Attendance Report
                    </div>
                    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                        <span class="badge badge-blue"><?php echo h($period); ?></span>
                        <!-- Tab switcher -->
                        <div class="report-tabs" id="reportTabs">
                            <button class="report-tab active" onclick="switchTab('matrix', this)">Member × Session</button>
                            <button class="report-tab" onclick="switchTab('summary', this)">Summary</button>
                        </div>
                    </div>
                </div>

                <div class="card-body">

                    <?php if (count($period_sessions) === 0): ?>
                        <div class="empty-state">
                            <div class="empty-icon">📅</div>
                            <p>No sessions recorded for this period. Create sessions in the Attendance tab to see data here.</p>
                        </div>
                    <?php elseif (count($active_members_list) === 0): ?>
                        <div class="empty-state">
                            <div class="empty-icon">👥</div>
                            <p>No active members found. Add members first.</p>
                        </div>
                    <?php else: ?>

                        <!-- Summary strips (always visible) -->
                        <div class="summary-strips">
                            <div class="summary-strip green">
                                <div>
                                    <div class="strip-label green">Present</div>
                                    <div style="font-size:11px;color:var(--text-3);margin-top:2px;"><?php echo $pct_present; ?>% of records</div>
                                </div>
                                <div class="strip-count green"><?php echo (int)($att['present_count'] ?? 0); ?></div>
                            </div>
                            <div class="summary-strip red">
                                <div>
                                    <div class="strip-label red">Absent</div>
                                    <div style="font-size:11px;color:var(--text-3);margin-top:2px;"><?php echo $pct_absent; ?>% of records</div>
                                </div>
                                <div class="strip-count red"><?php echo (int)($att['absent_count'] ?? 0); ?></div>
                            </div>
                            <div class="summary-strip amber">
                                <div>
                                    <div class="strip-label amber">Late</div>
                                    <div style="font-size:11px;color:var(--text-3);margin-top:2px;"><?php echo $pct_late; ?>% of records</div>
                                </div>
                                <div class="strip-count amber"><?php echo (int)($att['late_count'] ?? 0); ?></div>
                            </div>
                        </div>

                        <!-- ── TAB: Member × Session Matrix ── -->
                        <div id="tab-matrix">
                            <div class="report-filter-row">
                                <div class="report-search-wrap">
                                    <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <circle cx="11" cy="11" r="8" stroke-width="2"/>
                                        <path d="M21 21l-4.35-4.35" stroke-width="2" stroke-linecap="round"/>
                                    </svg>
                                    <input type="text" id="matrixSearch" placeholder="Filter members…" oninput="filterMatrix()">
                                </div>
                                <span style="font-size:12px;color:var(--text-3);">Highlight:</span>
                                <button class="filter-chip active" id="chip-all"     onclick="setHighlight('all',this)">All</button>
                                <button class="filter-chip green-chip" id="chip-present" onclick="setHighlight('present',this)">Present only</button>
                                <button class="filter-chip red-chip"   id="chip-absent"  onclick="setHighlight('absent',this)">Absent only</button>
                                <button class="filter-chip amber-chip" id="chip-late"    onclick="setHighlight('late',this)">Late only</button>
                            </div>

                            <div class="matrix-wrap">
                                <table class="matrix-table" id="matrixTable">
                                    <thead>
                                        <tr>
                                            <th class="session-header" style="min-width:160px;position:sticky;left:0;z-index:3;background:var(--surface-2);">Member</th>
                                            <?php foreach ($period_sessions as $s): ?>
                                                <th class="session-header">
                                                    <div style="font-weight:700;color:var(--text-2);"><?php echo h($s['name']); ?></div>
                                                    <div style="font-size:10px;color:var(--text-3);font-weight:400;margin-top:2px;"><?php echo h($s['date']); ?></div>
                                                </th>
                                            <?php endforeach; ?>
                                            <th style="text-align:center;background:var(--surface-2);">✅ P</th>
                                            <th style="text-align:center;background:var(--surface-2);">❌ A</th>
                                            <th style="text-align:center;background:var(--surface-2);">⏰ L</th>
                                            <th style="text-align:center;background:var(--surface-2);">Rate</th>
                                        </tr>
                                    </thead>
                                    <tbody id="matrixBody">
                                        <?php foreach ($active_members_list as $m):
                                            $mt = $member_totals[$m['id']];
                                            $att_total = max(1, count($period_sessions));
                                            $rate = count($period_sessions) > 0 ? round(($mt['present'] / $att_total) * 100) : 0;
                                        ?>
                                        <tr data-name="<?php echo strtolower(h($m['full_name'])); ?>">
                                            <td class="member-name-col"><?php echo h($m['full_name']); ?></td>
                                            <?php foreach ($period_sessions as $s):
                                                $status = $att_map[$s['id']][$m['id']] ?? null;
                                                $pill_class = $status ? $status : 'none';
                                                $pill_label = $status ? strtoupper(substr($status, 0, 1)) : '—';
                                                $full_label = $status ?? 'Not recorded';
                                            ?>
                                            <td style="text-align:center;" data-status="<?php echo h($status ?? 'none'); ?>">
                                                <div class="matrix-cell">
                                                    <span class="matrix-pill <?php echo $pill_class; ?>" title="<?php echo h(ucfirst($full_label)); ?>">
                                                        <?php echo $status ? ucfirst($status) : '—'; ?>
                                                    </span>
                                                </div>
                                            </td>
                                            <?php endforeach; ?>
                                            <td style="text-align:center;font-weight:700;color:var(--green);"><?php echo $mt['present']; ?></td>
                                            <td style="text-align:center;font-weight:700;color:var(--red);"><?php echo $mt['absent']; ?></td>
                                            <td style="text-align:center;font-weight:700;color:var(--amber);"><?php echo $mt['late']; ?></td>
                                            <td style="min-width:110px;">
                                                <div class="inline-bar-wrap">
                                                    <div class="inline-bar">
                                                        <div class="inline-bar-fill" style="width:<?php echo $rate; ?>%;background:<?php echo $rate>=80?'var(--green)':($rate>=50?'var(--amber)':'var(--red)')?>;"></div>
                                                    </div>
                                                    <span style="font-size:11px;font-weight:700;color:var(--text-2);min-width:32px;text-align:right;"><?php echo $rate; ?>%</span>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot>
                                        <tr>
                                            <td class="member-name-col" style="font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:var(--text-3);">Session Total</td>
                                            <?php foreach ($period_sessions as $s):
                                                $st = $session_totals[$s['id']] ?? ['present'=>0,'absent'=>0,'late'=>0];
                                            ?>
                                            <td style="text-align:center;">
                                                <div style="display:flex;flex-direction:column;gap:2px;align-items:center;">
                                                    <span style="font-size:11px;color:var(--green);font-weight:700;">✅ <?php echo $st['present']; ?></span>
                                                    <span style="font-size:11px;color:var(--red);font-weight:700;">❌ <?php echo $st['absent']; ?></span>
                                                    <?php if ($st['late'] > 0): ?>
                                                    <span style="font-size:11px;color:var(--amber);font-weight:700;">⏰ <?php echo $st['late']; ?></span>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <?php endforeach; ?>
                                            <td style="text-align:center;color:var(--green);font-weight:800;"><?php echo (int)($att['present_count']??0); ?></td>
                                            <td style="text-align:center;color:var(--red);font-weight:800;"><?php echo (int)($att['absent_count']??0); ?></td>
                                            <td style="text-align:center;color:var(--amber);font-weight:800;"><?php echo (int)($att['late_count']??0); ?></td>
                                            <td></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>

                        <!-- ── TAB: Summary (per-member breakdown) ── -->
                        <div id="tab-summary" style="display:none;">
                            <div class="report-filter-row">
                                <div class="report-search-wrap">
                                    <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <circle cx="11" cy="11" r="8" stroke-width="2"/>
                                        <path d="M21 21l-4.35-4.35" stroke-width="2" stroke-linecap="round"/>
                                    </svg>
                                    <input type="text" id="summarySearch" placeholder="Filter members…" oninput="filterSummary()">
                                </div>
                                <span style="font-size:12px;color:var(--text-3);">Sort by:</span>
                                <button class="filter-chip active" id="sort-present" onclick="sortSummary('present',this)">Most Present</button>
                                <button class="filter-chip red-chip" id="sort-absent" onclick="sortSummary('absent',this)">Most Absent</button>
                                <button class="filter-chip amber-chip" id="sort-late" onclick="sortSummary('late',this)">Most Late</button>
                                <button class="filter-chip" id="sort-name" onclick="sortSummary('name',this)">A–Z</button>
                            </div>

                            <div class="table-wrap">
                                <table id="summaryTable">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>Member</th>
                                            <th>Present</th>
                                            <th>Absent</th>
                                            <th>Late</th>
                                            <th>Sessions</th>
                                            <th>Attendance Rate</th>
                                            <th>Last 5 Sessions</th>
                                        </tr>
                                    </thead>
                                    <tbody id="summaryBody">
                                        <?php
                                        // Last 5 sessions (most recent)
                                        $last5 = array_slice(array_reverse($period_sessions), 0, 5);
                                        foreach ($active_members_list as $idx => $m):
                                            $mt   = $member_totals[$m['id']];
                                            $rate = count($period_sessions) > 0 ? round(($mt['present'] / count($period_sessions)) * 100) : 0;
                                            $rate_color = $rate >= 80 ? 'var(--green)' : ($rate >= 50 ? 'var(--amber)' : 'var(--red)');
                                        ?>
                                        <tr data-present="<?php echo $mt['present']; ?>"
                                            data-absent="<?php echo $mt['absent']; ?>"
                                            data-late="<?php echo $mt['late']; ?>"
                                            data-name="<?php echo strtolower(h($m['full_name'])); ?>"
                                            data-rate="<?php echo $rate; ?>">
                                            <td style="color:var(--text-3);font-size:12px;" class="rank-cell"><?php echo $idx+1; ?></td>
                                            <td class="name-cell"><?php echo h($m['full_name']); ?></td>
                                            <td>
                                                <div style="display:flex;align-items:center;gap:8px;">
                                                    <span style="font-weight:800;font-size:15px;color:var(--green);"><?php echo $mt['present']; ?></span>
                                                    <span style="font-size:11px;color:var(--text-3);">/ <?php echo count($period_sessions); ?></span>
                                                </div>
                                            </td>
                                            <td>
                                                <div style="display:flex;align-items:center;gap:6px;">
                                                    <?php if ($mt['absent'] > 0): ?>
                                                        <span style="font-weight:800;font-size:15px;color:var(--red);"><?php echo $mt['absent']; ?></span>
                                                    <?php else: ?>
                                                        <span style="color:var(--text-3);">0</span>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <?php if ($mt['late'] > 0): ?>
                                                    <span style="font-weight:800;font-size:15px;color:var(--amber);"><?php echo $mt['late']; ?></span>
                                                <?php else: ?>
                                                    <span style="color:var(--text-3);">0</span>
                                                <?php endif; ?>
                                            </td>
                                            <td style="color:var(--text-2);"><?php echo $mt['total']; ?></td>
                                            <td style="min-width:130px;">
                                                <div class="inline-bar-wrap">
                                                    <div class="inline-bar">
                                                        <div class="inline-bar-fill" style="width:<?php echo $rate; ?>%;background:<?php echo $rate_color; ?>;"></div>
                                                    </div>
                                                    <span style="font-size:12px;font-weight:700;color:<?php echo $rate_color; ?>;min-width:36px;text-align:right;"><?php echo $rate; ?>%</span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="att-dot-grid">
                                                    <?php
                                                    // Show last 5 sessions as coloured dots
                                                    if (count($last5) === 0) {
                                                        echo '<span style="color:var(--text-3);font-size:11px;">No sessions</span>';
                                                    } else {
                                                        foreach ($last5 as $ls):
                                                            $ds = $att_map[$ls['id']][$m['id']] ?? null;
                                                            $dc = $ds ?? 'none';
                                                            $dl = $ds ? strtoupper(substr($ds,0,1)) : '—';
                                                    ?>
                                                        <div class="att-dot <?php echo $dc; ?>" title="<?php echo h($ls['name'].' ('.$ls['date'].')').': '.h(ucfirst($ds ?? 'Not recorded')); ?>">
                                                            <?php echo $dl; ?>
                                                        </div>
                                                    <?php endforeach; } ?>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <!-- Progress bars for Present / Absent / Late -->
                            <div style="margin-top:24px;padding-top:20px;border-top:1px solid var(--border);">
                                <div style="font-family:'Syne',sans-serif;font-weight:700;font-size:13px;color:var(--text-1);margin-bottom:16px;">Period Breakdown</div>
                                <?php foreach ([
                                    ['Present', $pct_present, 'var(--green)', (int)($att['present_count']??0)],
                                    ['Absent',  $pct_absent,  'var(--red)',   (int)($att['absent_count']??0)],
                                    ['Late',    $pct_late,    'var(--amber)', (int)($att['late_count']??0)],
                                ] as [$label, $pct, $color, $count]): ?>
                                <div style="margin-bottom:14px;">
                                    <div style="display:flex;justify-content:space-between;margin-bottom:6px;align-items:center;">
                                        <span style="font-size:13px;color:var(--text-2);font-weight:500;"><?php echo $label; ?></span>
                                        <div style="display:flex;align-items:center;gap:8px;">
                                            <span style="font-size:13px;font-weight:700;color:<?php echo $color; ?>;"><?php echo $count; ?> records</span>
                                            <span style="font-size:11px;color:var(--text-3);"><?php echo $pct; ?>%</span>
                                        </div>
                                    </div>
                                    <div style="height:8px;background:var(--surface-2);border-radius:100px;overflow:hidden;">
                                        <div style="height:100%;width:<?php echo $pct; ?>%;background:<?php echo $color; ?>;border-radius:100px;transition:width .6s ease;"></div>
                                    </div>
                                </div>
                                <?php endforeach; ?>

                                <div style="margin-top:14px;padding-top:12px;border-top:1px solid var(--border);display:flex;justify-content:space-between;font-size:12px;">
                                    <span style="color:var(--text-3);">Total attendance records this period</span>
                                    <span style="font-weight:700;color:var(--text-1);"><?php echo $total_records; ?></span>
                                </div>
                            </div>
                        </div>

                    <?php endif; ?>
                </div>
            </div>

            <!-- Export + Latest Session row (unchanged) -->
            <div class="report-row" style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px;">

                <div class="card">
                    <div class="card-header">
                        <div class="card-title">📋 Export Attendance</div>
                    </div>
                    <div class="card-body">
                        <?php if (count($period_sessions) > 0): ?>

                        <!-- Step 1: pick sessions -->
                        <div style="margin-bottom:16px;">
                            <div style="font-size:11px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:var(--text-3);margin-bottom:8px;">① Select Sessions</div>
                            <label style="display:flex;align-items:center;gap:8px;margin-bottom:8px;cursor:pointer;">
                                <input type="checkbox" id="selectAll" onchange="toggleAll(this)"
                                       style="width:15px;height:15px;accent-color:var(--accent);">
                                <span style="font-size:12px;font-weight:600;color:var(--text-2);">Select All</span>
                            </label>
                            <div class="session-list">
                                <?php foreach ($period_sessions as $s): ?>
                                <label class="session-check-label">
                                    <input type="checkbox" name="session_ids_export[]"
                                           value="<?php echo $s['id']; ?>"
                                           class="session-check">
                                    <div>
                                        <div style="font-size:13px;font-weight:600;color:var(--text-1);"><?php echo h($s['name']); ?></div>
                                        <div style="font-size:11px;color:var(--text-3);"><?php echo h($s['date']); ?></div>
                                    </div>
                                </label>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- Step 2: pick report type -->
                        <div style="margin-bottom:16px;">
                            <div style="font-size:11px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:var(--text-3);margin-bottom:8px;">② Choose Report Type</div>
                            <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">

                                <!-- Full Matrix -->
                                <form method="POST" class="export-form">
                                    <input type="hidden" name="action" value="export_csv">
                                    <input type="hidden" name="export_type" value="attendance_report">
                                    <input type="hidden" name="period" value="<?php echo h($period); ?>">
                                    <div class="session-ids-placeholder"></div>
                                    <button type="submit" class="export-btn-card" style="border-color:var(--accent);color:var(--accent);">
                                        <span style="font-size:20px;">📊</span>
                                        <div>
                                            <div style="font-weight:700;font-size:12px;">Full Matrix</div>
                                            <div style="font-size:10px;opacity:.7;">All statuses · grid view</div>
                                        </div>
                                    </button>
                                </form>

                                <!-- Present Only -->
                                <form method="POST" class="export-form">
                                    <input type="hidden" name="action" value="export_csv">
                                    <input type="hidden" name="export_type" value="attendance_filtered">
                                    <input type="hidden" name="filter_status" value="present">
                                    <input type="hidden" name="period" value="<?php echo h($period); ?>">
                                    <div class="session-ids-placeholder"></div>
                                    <button type="submit" class="export-btn-card" style="border-color:var(--green);color:var(--green);">
                                        <span style="font-size:20px;">✅</span>
                                        <div>
                                            <div style="font-weight:700;font-size:12px;">Present Only</div>
                                            <div style="font-size:10px;opacity:.7;">Who attended</div>
                                        </div>
                                    </button>
                                </form>

                                <!-- Absent Only -->
                                <form method="POST" class="export-form">
                                    <input type="hidden" name="action" value="export_csv">
                                    <input type="hidden" name="export_type" value="attendance_filtered">
                                    <input type="hidden" name="filter_status" value="absent">
                                    <input type="hidden" name="period" value="<?php echo h($period); ?>">
                                    <div class="session-ids-placeholder"></div>
                                    <button type="submit" class="export-btn-card" style="border-color:var(--red);color:var(--red);">
                                        <span style="font-size:20px;">❌</span>
                                        <div>
                                            <div style="font-weight:700;font-size:12px;">Absent Only</div>
                                            <div style="font-size:10px;opacity:.7;">Who missed sessions</div>
                                        </div>
                                    </button>
                                </form>

                                <!-- Late Only -->
                                <form method="POST" class="export-form">
                                    <input type="hidden" name="action" value="export_csv">
                                    <input type="hidden" name="export_type" value="attendance_filtered">
                                    <input type="hidden" name="filter_status" value="late">
                                    <input type="hidden" name="period" value="<?php echo h($period); ?>">
                                    <div class="session-ids-placeholder"></div>
                                    <button type="submit" class="export-btn-card" style="border-color:var(--amber);color:var(--amber);">
                                        <span style="font-size:20px;">⏰</span>
                                        <div>
                                            <div style="font-weight:700;font-size:12px;">Late Only</div>
                                            <div style="font-size:10px;opacity:.7;">Who arrived late</div>
                                        </div>
                                    </button>
                                </form>

                            </div>
                        </div>

                        <?php else: ?>
                            <div class="empty-state"><div class="empty-icon">📅</div><p>No sessions this period.</p></div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <div class="card-title">
                            🕐 Latest Session
                            <?php if ($latest_session): ?>
                                <span style="font-size:11px;font-weight:400;color:var(--text-3);margin-left:4px;">
                                    <?php echo h($latest_session['name']); ?> · <?php echo h($latest_session['date']); ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if ($latest_session && count($latest_attendance) > 0): ?>
                        <?php
                            $lp = count(array_filter($latest_attendance, fn($r) => $r['status'] === 'present'));
                            $la = count(array_filter($latest_attendance, fn($r) => $r['status'] === 'absent'));
                            $ll = count(array_filter($latest_attendance, fn($r) => $r['status'] === 'late'));
                            $lu = count(array_filter($latest_attendance, fn($r) => $r['status'] === null));
                        ?>
                        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;padding:14px 16px;border-bottom:1px solid var(--border);">
                            <div style="text-align:center;">
                                <div style="font-size:22px;font-weight:800;color:var(--green);font-family:'Syne',sans-serif;"><?php echo $lp; ?></div>
                                <div style="font-size:10px;color:var(--text-3);text-transform:uppercase;margin-top:2px;">Present</div>
                            </div>
                            <div style="text-align:center;">
                                <div style="font-size:22px;font-weight:800;color:var(--red);font-family:'Syne',sans-serif;"><?php echo $la; ?></div>
                                <div style="font-size:10px;color:var(--text-3);text-transform:uppercase;margin-top:2px;">Absent</div>
                            </div>
                            <div style="text-align:center;">
                                <div style="font-size:22px;font-weight:800;color:var(--amber);font-family:'Syne',sans-serif;"><?php echo $ll + $lu; ?></div>
                                <div style="font-size:10px;color:var(--text-3);text-transform:uppercase;margin-top:2px;">Late / —</div>
                            </div>
                        </div>
                        <div class="table-wrap">
                            <table>
                                <thead><tr><th>Member</th><th>Status</th></tr></thead>
                                <tbody>
                                    <?php foreach ($latest_attendance as $row): ?>
                                    <tr>
                                        <td class="name-cell"><?php echo h($row['full_name']); ?></td>
                                        <td>
                                            <?php
                                                $s  = $row['status'] ?? null;
                                                $bc = $s === 'present' ? 'badge-green' : ($s === 'absent' ? 'badge-red' : ($s === 'late' ? 'badge-amber' : 'badge-gray'));
                                            ?>
                                            <span class="badge <?php echo $bc; ?>"><?php echo h($s ?? '—'); ?></span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="empty-state"><div class="empty-icon">📅</div><p>No sessions recorded yet for this period.</p></div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Top attenders (unchanged) -->
            <div class="card">
                <div class="card-header">
                    <div class="card-title">🏅 Top Attenders</div>
                    <span class="badge badge-green">this period</span>
                </div>
                <div class="table-wrap">
                    <?php if (count($summary['top_attenders']) > 0): ?>
                    <table>
                        <thead>
                            <tr><th>#</th><th>Member</th><th>Present</th><th>Absent</th><th>Late</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($summary['top_attenders'] as $i => $row): ?>
                            <tr>
                                <td style="color:var(--text-3);font-size:12px;"><?php echo $i+1; ?></td>
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

        <?php endif; ?>
    </div><!-- /content -->
</div><!-- /main -->

<script>
    // ── Active members data for the search widget ──
    const ATT_MEMBERS = <?php echo $active_members_json; ?>;

    // ── Auto-dismiss alert ──
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

    // ── Member search filter (Members table) ──
    function filterMembers() {
        const q = document.getElementById('memberSearch').value.toLowerCase().trim();
        const rows = document.querySelectorAll('#membersTable tbody tr');
        let visible = 0;
        rows.forEach(row => {
            const show = !q || row.textContent.toLowerCase().includes(q);
            row.style.display = show ? '' : 'none';
            if (show) visible++;
        });
        const badge = document.getElementById('memberCount');
        if (badge) badge.textContent = visible + ' ' + (q ? 'found' : 'total');
    }

    // ── Select-all sessions checkboxes ──
    function toggleAll(cb) {
        document.querySelectorAll('.session-check').forEach(c => c.checked = cb.checked);
    }

    document.addEventListener('change', function(e) {
        if (e.target.classList.contains('session-check')) {
            const all  = document.querySelectorAll('.session-check');
            const chkd = document.querySelectorAll('.session-check:checked');
            const sa   = document.getElementById('selectAll');
            if (sa) sa.checked = all.length === chkd.length;
        }
    });

    // ── Mobile sidebar ──
    function toggleSidebar() {
        document.getElementById('sidebar').classList.toggle('open');
        document.getElementById('overlay').classList.toggle('active');
    }

    function closeSidebar() {
        document.getElementById('sidebar').classList.remove('open');
        document.getElementById('overlay').classList.remove('active');
    }

    // ── Attendance member search widget ──
    (function () {
        const searchEl   = document.getElementById('attMemberSearch');
        const dropEl     = document.getElementById('attMemberDropdown');
        const hiddenEl   = document.getElementById('attMemberId');
        const chipEl     = document.getElementById('attSelectedChip');
        const chipName   = document.getElementById('attSelectedName');

        if (!searchEl) return;

        let filtered = [];
        let highlightIdx = -1;

        function renderDropdown(items) {
            filtered = items;
            highlightIdx = -1;
            if (items.length === 0) {
                dropEl.innerHTML = '<div class="member-dropdown-empty">No members found</div>';
            } else {
                dropEl.innerHTML = items.map((m, i) =>
                    `<div class="member-dropdown-item" data-id="${m.id}" data-idx="${i}">${escHtml(m.name)}</div>`
                ).join('');
                dropEl.querySelectorAll('.member-dropdown-item').forEach(el => {
                    el.addEventListener('mousedown', function(e) {
                        e.preventDefault();
                        selectMember(parseInt(this.dataset.id), this.textContent);
                    });
                });
            }
            dropEl.classList.add('open');
        }

        function closeDropdown() {
            dropEl.classList.remove('open');
            highlightIdx = -1;
        }

        function selectMember(id, name) {
            hiddenEl.value = id;
            chipName.textContent = name;
            chipEl.style.display = 'flex';
            searchEl.style.display = 'none';
            closeDropdown();
        }

        window.attClearMember = function () {
            hiddenEl.value = '';
            chipEl.style.display = 'none';
            searchEl.style.display = '';
            searchEl.value = '';
            searchEl.focus();
        };

        window.attSearchInput = function (el) {
            const q = el.value.trim().toLowerCase();
            if (!q) { closeDropdown(); return; }
            const matches = ATT_MEMBERS.filter(m => m.name.toLowerCase().includes(q));
            renderDropdown(matches);
        };

        window.attSearchFocus = function () {
            const q = searchEl.value.trim().toLowerCase();
            if (q) {
                const matches = ATT_MEMBERS.filter(m => m.name.toLowerCase().includes(q));
                renderDropdown(matches);
            }
        };

        window.attSearchKeydown = function (e) {
            const items = dropEl.querySelectorAll('.member-dropdown-item');
            if (!dropEl.classList.contains('open') || items.length === 0) return;

            if (e.key === 'ArrowDown') {
                e.preventDefault();
                highlightIdx = Math.min(highlightIdx + 1, items.length - 1);
                updateHighlight(items);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                highlightIdx = Math.max(highlightIdx - 1, 0);
                updateHighlight(items);
            } else if (e.key === 'Enter') {
                e.preventDefault();
                if (highlightIdx >= 0 && items[highlightIdx]) {
                    const el = items[highlightIdx];
                    selectMember(parseInt(el.dataset.id), el.textContent);
                } else if (filtered.length === 1) {
                    selectMember(filtered[0].id, filtered[0].name);
                }
            } else if (e.key === 'Escape') {
                closeDropdown();
            }
        };

        function updateHighlight(items) {
            items.forEach((el, i) => el.classList.toggle('highlighted', i === highlightIdx));
            if (highlightIdx >= 0) items[highlightIdx].scrollIntoView({ block: 'nearest' });
        }

        document.addEventListener('click', function(e) {
            const wrap = document.getElementById('memberSearchWrap');
            if (wrap && !wrap.contains(e.target)) closeDropdown();
        });

        const form = document.getElementById('attForm');
        if (form) {
            form.addEventListener('submit', function(e) {
                if (!hiddenEl.value) {
                    e.preventDefault();
                    searchEl.focus();
                    searchEl.style.borderColor = 'var(--red)';
                    searchEl.style.boxShadow = '0 0 0 3px var(--red-dim)';
                    setTimeout(() => {
                        searchEl.style.borderColor = '';
                        searchEl.style.boxShadow = '';
                    }, 2000);
                }
            });
        }

        function escHtml(str) {
            return str.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
        }
    })();

    // ── Export: sync session checkboxes into every export form ──
    (function () {
        // When any .session-check changes, mirror into all .export-form placeholders
        function syncExportForms() {
            const checked = Array.from(document.querySelectorAll('.session-check:checked'))
                                 .map(cb => cb.value);

            document.querySelectorAll('.export-form').forEach(form => {
                const placeholder = form.querySelector('.session-ids-placeholder');
                if (!placeholder) return;
                placeholder.innerHTML = '';
                checked.forEach(id => {
                    const inp = document.createElement('input');
                    inp.type  = 'hidden';
                    inp.name  = 'session_ids[]';
                    inp.value = id;
                    placeholder.appendChild(inp);
                });
            });
        }

        document.addEventListener('change', function (e) {
            if (e.target.classList.contains('session-check') || e.target.id === 'selectAll') {
                syncExportForms();
            }
        });

        // Guard: warn if no session selected on submit
        document.addEventListener('submit', function (e) {
            if (!e.target.classList.contains('export-form')) return;
            const checked = document.querySelectorAll('.session-check:checked');
            if (checked.length === 0) {
                e.preventDefault();
                alert('Please select at least one session before downloading.');
            }
        });
    })();
    function switchTab(tab, btn) {
        // Toggle tab content
        const matrix  = document.getElementById('tab-matrix');
        const summary = document.getElementById('tab-summary');
        if (!matrix || !summary) return;

        if (tab === 'matrix') {
            matrix.style.display  = '';
            summary.style.display = 'none';
        } else {
            matrix.style.display  = 'none';
            summary.style.display = '';
        }

        // Toggle button active state
        document.querySelectorAll('.report-tab').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
    }

    // ── REPORTS: Matrix highlight filter ──
    let currentHighlight = 'all';

    function setHighlight(mode, btn) {
        currentHighlight = mode;
        document.querySelectorAll('#chip-all,#chip-present,#chip-absent,#chip-late').forEach(c => c.classList.remove('active'));
        btn.classList.add('active');
        applyMatrixHighlight();
    }

    function applyMatrixHighlight() {
        const cells = document.querySelectorAll('#matrixBody td[data-status]');
        cells.forEach(cell => {
            const status = cell.getAttribute('data-status');
            if (currentHighlight === 'all') {
                cell.style.opacity = '1';
            } else {
                cell.style.opacity = (status === currentHighlight) ? '1' : '0.15';
            }
        });
    }

    // ── REPORTS: Matrix member search ──
    function filterMatrix() {
        const q = document.getElementById('matrixSearch').value.toLowerCase().trim();
        document.querySelectorAll('#matrixBody tr').forEach(row => {
            const name = row.getAttribute('data-name') || '';
            row.style.display = !q || name.includes(q) ? '' : 'none';
        });
    }

    // ── REPORTS: Summary search ──
    function filterSummary() {
        const q = document.getElementById('summarySearch').value.toLowerCase().trim();
        document.querySelectorAll('#summaryBody tr').forEach(row => {
            const name = row.getAttribute('data-name') || '';
            row.style.display = !q || name.includes(q) ? '' : 'none';
        });
    }

    // ── REPORTS: Summary sort ──
    let currentSort = 'present';

    function sortSummary(key, btn) {
        currentSort = key;
        document.querySelectorAll('#sort-present,#sort-absent,#sort-late,#sort-name').forEach(c => c.classList.remove('active'));
        btn.classList.add('active');

        const tbody = document.getElementById('summaryBody');
        if (!tbody) return;
        const rows = Array.from(tbody.querySelectorAll('tr'));

        rows.sort((a, b) => {
            if (key === 'name') {
                return (a.getAttribute('data-name') || '').localeCompare(b.getAttribute('data-name') || '');
            }
            const va = parseInt(a.getAttribute('data-' + key) || '0');
            const vb = parseInt(b.getAttribute('data-' + key) || '0');
            return vb - va; // descending
        });

        // Re-number ranks
        rows.forEach((row, i) => {
            const rc = row.querySelector('.rank-cell');
            if (rc) rc.textContent = i + 1;
            tbody.appendChild(row);
        });
    }
</script>
</body>
</html>
