<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/database.php';

// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

try {
    // Database connection
    $database = new Database();
    $db = $database->getConnection();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    http_response_code(500);
    echo "Database connection error.";
    exit;
}

// Authentication check
if (empty($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$user_id = (int)$_SESSION['user_id'];

// Logout functionality
if (isset($_GET['logout']) && $_GET['logout'] === 'true') {
    $_SESSION = [];
    session_destroy();
    header("Location: index.php");
    exit;
}

// Load current user
$u = $db->prepare("SELECT id, name, username, role FROM users WHERE id = ?");
$u->execute([$user_id]);
$user = $u->fetch();
if (!$user) {
    header("Location: index.php");
    exit;
}

// Get notifications for the user
function getNotifications(PDO $db, int $user_id): array {
    try {
        $sql = "SELECT * FROM user_notifications 
                WHERE user_id = ? OR user_id IS NULL 
                ORDER BY created_at DESC 
                LIMIT 10";
        $stmt = $db->prepare($sql);
        $stmt->execute([$user_id]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Error fetching notifications: " . $e->getMessage());
        return [];
    }
}

// Mark notification as read
if (isset($_POST['action']) && $_POST['action'] === 'mark_notification_read' && isset($_POST['notification_id'])) {
    $notification_id = (int)$_POST['notification_id'];
    $stmt = $db->prepare("UPDATE user_notifications SET is_read = 1 WHERE id = ?");
    $stmt->execute([$notification_id]);
    exit;
}

// Mark all notifications as read
if (isset($_POST['action']) && $_POST['action'] === 'mark_all_read') {
    $stmt = $db->prepare("UPDATE user_notifications SET is_read = 1 WHERE user_id = ? OR user_id IS NULL");
    $stmt->execute([$user_id]);
    exit;
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF protection
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error_message = "Invalid CSRF token.";
    } else {
        if (isset($_POST['entry_date']) && isset($_POST['entry_id']) && isset($_POST['description'])) {
            try {
                $db->beginTransaction();
                
                // Enhanced validation
                $entry_date = $_POST['entry_date'];
                $entry_id = trim($_POST['entry_id']);
                $description = trim($_POST['description']);
                
                // Validate entry date (not future date)
                if (strtotime($entry_date) > time()) {
                    throw new Exception("Entry date cannot be in the future.");
                }
                
                // Validate entry ID format (alphanumeric, dashes, underscores)
                if (!preg_match('/^[A-Za-z0-9\-_]+$/', $entry_id)) {
                    throw new Exception("Entry ID can only contain letters, numbers, hyphens, and underscores.");
                }
                
                // Check for duplicate entry ID
                $check_stmt = $db->prepare("SELECT COUNT(*) FROM journal_entries WHERE entry_id = ?");
                $check_stmt->execute([$entry_id]);
                if ($check_stmt->fetchColumn() > 0) {
                    throw new Exception("Entry ID already exists. Please use a unique reference number.");
                }
                
                // Validate description length
                if (strlen($description) < 5) {
                    throw new Exception("Description must be at least 5 characters long.");
                }
                
                // Insert journal entry - always posted directly
                $stmt = $db->prepare("INSERT INTO journal_entries (entry_id, entry_date, description, status, created_by) 
                                     VALUES (?, ?, ?, 'Posted', ?)");
                $stmt->execute([$entry_id, $entry_date, $description, $user_id]);
                $journal_entry_id = $db->lastInsertId();
                
                // Insert journal entry lines
                $accounts = $_POST['accounts'] ?? [];
                $debits = $_POST['debits'] ?? [];
                $credits = $_POST['credits'] ?? [];
                
                $total_debit = 0;
                $total_credit = 0;
                $valid_lines = 0;
                
                for ($i = 0; $i < count($accounts); $i++) {
                    if (!empty($accounts[$i])) {
                        $account_id = $accounts[$i];
                        $debit = floatval($debits[$i] ?? 0);
                        $credit = floatval($credits[$i] ?? 0);
                        
                        // Validate at least one non-zero amount per line
                        if ($debit > 0 || $credit > 0) {
                            valid_lines;
                        }
                        
                        $total_debit += $debit;
                        $total_credit += $credit;
                        
                        $stmt = $db->prepare("INSERT INTO journal_entry_lines (journal_entry_id, account_id, debit, credit) 
                                             VALUES (?, ?, ?, ?)");
                        $stmt->execute([$journal_entry_id, $account_id, $debit, $credit]);
                    }
                }
                
                // Validate exactly one entry line with non-zero amounts
                if ($valid_lines < 1) {
                    throw new Exception("Journal entry must have at least one valid line with non-zero amounts.");
                }
                
                // Check if balanced
                if (abs($total_debit - $total_credit) > 0.01) {
                    throw new Exception("Journal entry is not balanced. Debits: $total_debit, Credits: $total_credit");
                }
                
                // Create notification for new journal entry
                try {
                    $notification_msg = "New journal entry created: " . $entry_id . " - " . $description;
                    $notif_stmt = $db->prepare("INSERT INTO user_notifications (user_id, title, message, notification_type) VALUES (?, 'Journal Entry Created', ?, 'success')");
                    $notif_stmt->execute([$user_id, $notification_msg]);
                } catch (Exception $e) {
                    error_log("Notification creation failed: " . $e->getMessage());
                }
                
                $db->commit();
                $success_message = "Journal entry created successfully!";
                
            } catch (Exception $e) {
                $db->rollBack();
                $error_message = "Error creating journal entry: " . $e->getMessage();
            }
        }
    }
}

// Get journal entries data - only show Posted entries
function getJournalEntries(PDO $db): array {
    $sql = "SELECT je.* 
            FROM journal_entries je
            WHERE je.status = 'Posted'
            ORDER BY je.entry_date DESC, je.id DESC";
    return $db->query($sql)->fetchAll();
}

// Get chart of accounts for dropdown
function getChartOfAccounts(PDO $db): array {
    $sql = "SELECT id, account_code, account_name, account_type 
            FROM chart_of_accounts 
            WHERE status = 'Active'
            ORDER BY account_type, account_code";
    return $db->query($sql)->fetchAll();
}

$journal_entries = getJournalEntries($db);
$chart_accounts = getChartOfAccounts($db);

// Get journal entry lines for a specific entry
function getJournalEntryLines(PDO $db, $entry_id): array {
    $entry_id = (int)$entry_id; // Ensure it's an integer
    $sql = "SELECT jel.*, coa.account_code, coa.account_name, coa.account_type
            FROM journal_entry_lines jel
            LEFT JOIN chart_of_accounts coa ON jel.account_id = coa.id
            WHERE jel.journal_entry_id = ?
            ORDER BY jel.id";
    $stmt = $db->prepare($sql);
    $stmt->execute([$entry_id]);
    $result = $stmt->fetchAll();
    return $result ?: []; // Return empty array if no results
}

// Get financial summary for dashboard
function getFinancialSummary(PDO $db): array {
    $current_month = date('Y-m');
    $sql = "SELECT 
                coa.account_type,
                COALESCE(SUM(
                    CASE 
                        WHEN coa.account_type = 'Revenue' THEN jel.credit - jel.debit
                        WHEN coa.account_type = 'Expense' THEN jel.debit - jel.credit
                        ELSE 0 
                    END
                ), 0) as amount
            FROM chart_of_accounts coa
            LEFT JOIN journal_entry_lines jel ON coa.id = jel.account_id
            LEFT JOIN journal_entries je ON jel.journal_entry_id = je.id 
                AND je.status = 'Posted'
                AND DATE_FORMAT(je.entry_date, '%Y-%m') = ?
            WHERE coa.account_type IN ('Revenue', 'Expense')
            GROUP BY coa.account_type";
    
    $stmt = $db->prepare($sql);
    $stmt->execute([$current_month]);
    return $stmt->fetchAll();
}

// Call this function and add to your existing data
$financial_summary = getFinancialSummary($db);

// Get notifications
$notifications = getNotifications($db, $user_id);
$unread_notifications = array_filter($notifications, function($notification) {
    return !$notification['is_read'];
});
$unread_count = count($unread_notifications);

// Get dashboard stats for header
function getDashboardStats(PDO $db): array {
    $revStmt = $db->query("SELECT COALESCE(SUM(paid_amount), 0) as total FROM invoices WHERE type = 'Receivable'");
    $rev = $revStmt->fetch()['total'] ?? 0;
    
    $billStmt = $db->query("SELECT COALESCE(SUM(paid_amount), 0) as total FROM invoices WHERE type = 'Payable'");
    $bills = $billStmt->fetch()['total'] ?? 0;

    $hrStmt = $db->query("SELECT COALESCE(SUM(amount), 0) as total FROM disbursement_requests WHERE department = 'HR Payroll' AND status = 'Approved'");
    $payroll = $hrStmt->fetch()['total'] ?? 0;

    $totalExpenses = $bills + $payroll;
    $cashFlow = (float)$rev - (float)$totalExpenses;
    
    try {
        $stmt = $db->query("SELECT COALESCE(SUM(amount - paid_amount), 0) as total FROM invoices WHERE due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND status != 'Paid' AND type='Payable'");
        $upcoming = $stmt->fetch()['total'] ?? 0;
    } catch (Exception $e) { $upcoming = 0; }

    return [
        'total_income'      => (float)$rev,
        'total_expenses'    => (float)$totalExpenses,
        'cash_flow'         => (float)$cashFlow,
        'upcoming_payments' => (float)$upcoming,
    ];
}

$dashboard_stats = getDashboardStats($db);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Journal Entry - Financial Dashboard</title>

    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        "brand-primary": "#059669",
                        "brand-primary-hover": "#047857",
                        "brand-background-main": "#F0FDF4",
                        "brand-border": "#D1FAE5",
                        "brand-text-primary": "#1F2937",
                        "brand-text-secondary": "#4B5563",
                        "brand-green": "#059669",
                        "brand-green-light": "#D1FAE5",
                        "brand-red": "#EF4444",
                        "brand-yellow": "#F59E0B",
                        "brand-blue": "#3B82F6",
                    }
                }
            }
        }
    </script>

    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer" />
    
    <style>
        .custom-scrollbar {
            scrollbar-width: thin;
            scrollbar-color: #D1FAE5 transparent;
        }
        .custom-scrollbar::-webkit-scrollbar {
            width: 4px;
        }
        .custom-scrollbar::-webkit-scrollbar-track {
            background: transparent;
        }
        .custom-scrollbar::-webkit-scrollbar-thumb {
            background-color: #D1FAE5;
            border-radius: 20px;
        }
        
        .submenu {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease-out;
        }
        
        .submenu.active {
            max-height: 500px;
        }
        
        .dropdown-panel {
            opacity: 0;
            transform: translateY(10px) scale(0.95);
            pointer-events: none;
            transition: all 0.2s ease;
        }
        
        .dropdown-panel.active {
            opacity: 1;
            transform: translateY(0) scale(1);
            pointer-events: auto;
        }
        
        .notification-badge {
            position: absolute;
            top: 2px;
            right: 2px;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background-color: #EF4444;
            border: 2px solid white;
        }
        
        .stat-card {
            transition: all 0.3s ease;
            border: 1px solid #D1FAE5;
            background: white;
        }
        
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1);
            border-color: #34D399;
        }
        
        .hidden-amount {
            letter-spacing: 3px;
            font-family: monospace;
            user-select: none;
        }
        
        .transaction-row {
            transition: all 0.2s ease;
            border-bottom: 1px solid #F3F4F6;
        }
        
        .transaction-row:hover {
            background-color: #F9FAFB;
        }
        
        .status-badge {
            padding: 0.25rem 0.75rem;
            font-size: 0.75rem;
            border-radius: 9999px;
            font-weight: 500;
        }
        
        .status-approved {
            background-color: #D1FAE5;
            color: #059669;
        }
        
        .status-pending {
            background-color: #FEF3C7;
            color: #D97706;
        }
        
        .status-rejected {
            background-color: #FEE2E2;
            color: #DC2626;
        }
        
        .spinner {
            border: 3px solid #f3f3f3;
            border-top: 3px solid #059669;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            overflow-y: auto;
        }
        
        .modal-content {
            background-color: white;
            margin: 5% auto;
            padding: 2rem;
            border-radius: 1rem;
            width: 90%;
            max-width: 800px;
            max-height: 80vh;
            overflow-y: auto;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
        }
        
        .sidebar-overlay {
            transition: opacity 0.3s ease;
        }
        
        .sidebar-overlay.active {
            opacity: 1;
            pointer-events: auto;
        }
        
        #sidebar {
            transition: transform 0.3s ease;
        }
        
        #sidebar.active {
            transform: translateX(0);
        }
        
        .alert {
            padding: 1rem;
            border-radius: 0.375rem;
            margin-bottom: 1rem;
        }
        
        .alert-success {
            background-color: #D1FAE5;
            color: #047857;
            border: 1px solid #A7F3D0;
        }
        
        .alert-error {
            background-color: #FEE2E2;
            color: #DC2626;
            border: 1px solid #FECACA;
        }

        .form-group {
            margin-bottom: 1rem;
        }
        
        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
        }
        
        .form-input {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid #d1d5db;
            border-radius: 0.5rem;
            font-size: 0.875rem;
        }
        
        .form-input:focus {
            outline: none;
            border-color: #059669;
            box-shadow: 0 0 0 3px rgba(5, 150, 105, 0.2);
        }

        .validation-error {
            color: #EF4444;
            font-size: 0.75rem;
            margin-top: 0.25rem;
        }

        .form-input.error {
            border-color: #EF4444;
            box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.2);
        }

        .required-field::after {
            content: " *";
            color: #EF4444;
        }

        /* Journal entry specific styles */
        .entry-line {
            border: 1px solid #e5e7eb;
            border-radius: 0.5rem;
            padding: 1rem;
            margin-bottom: 1rem;
            background-color: #f9fafb;
        }
        
        .debit-amount {
            color: #10B981;
            font-weight: 600;
        }
        
        .credit-amount {
            color: #EF4444;
            font-weight: 600;
        }
        
        .balance-check {
            padding: 1rem;
            border-radius: 0.5rem;
            font-weight: 600;
        }
        
        .balance-balanced {
            background-color: #D1FAE5;
            color: #065F46;
        }
        
        .balance-unbalanced {
            background-color: #FEE2E2;
            color: #DC2626;
        }

        .debit-input:focus {
            border-color: #10B981;
            box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.2);
        }
        
        .credit-input:focus {
            border-color: #EF4444;
            box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.2);
        }
        
        .amount-toggle-btn {
            margin-top: 1rem;
            padding: 0.5rem 1rem;
            background-color: #f3f4f6;
            border-radius: 0.375rem;
            font-size: 0.875rem;
            color: #4b5563;
        }
        
        .amount-toggle-btn:hover {
            background-color: #e5e7eb;
        }
        
        .amount-value {
            transition: all 0.3s ease;
        }
    </style>
</head>
<body class="bg-brand-background-main min-h-screen">

    <!-- Overlay (mobile) -->
    <div id="sidebar-overlay" class="sidebar-overlay fixed inset-0 bg-black/30 hidden opacity-0 transition-opacity duration-300 z-40"></div>

    <!-- SIDEBAR -->
    <aside id="sidebar"
        class="fixed top-0 left-0 h-full w-72 bg-white border-r border-gray-100 shadow-sm z-50
               transform -translate-x-full md:translate-x-0 transition-transform duration-300">

        <div class="h-16 flex items-center px-4 border-b border-gray-100">
            <a href="dashboard8.php"
                class="flex items-center gap-3 w-full rounded-xl px-2 py-2
                       hover:bg-gray-100 active:bg-gray-200 transition group">
                <img src="assets/images/logo.png" alt="Financial Dashboard Logo" class="w-9 h-9">
                <div class="leading-tight">
                    <div class="font-bold text-gray-800 group-hover:text-brand-primary transition-colors text-sm">
                        Financial Dashboard
                    </div>
                    <div class="text-[10px] text-gray-500 font-semibold uppercase group-hover:text-brand-primary transition-colors">
                        Microfinancial System
                    </div>
                </div>
            </a>
        </div>

        <!-- Sidebar content - Optimized for laptop screens -->
        <div class="px-4 py-3 overflow-y-auto h-[calc(100vh-4rem)] max-h-[calc(100vh-4rem)] custom-scrollbar">
            <!-- Wrapper for consistent spacing -->
            <div class="space-y-3">
                <div class="text-xs font-bold text-gray-400 tracking-wider px-2">MAIN MENU</div>

                <a href="dashboard8.php"
                    class="flex items-center justify-between px-3 py-2.5 rounded-xl text-gray-700 hover:bg-green-50 hover:text-brand-primary transition-all duration-200 hover:translate-x-1 active:translate-x-0 active:scale-[0.99]">
                    <span class="flex items-center gap-3 font-semibold text-sm">
                        <span class="inline-flex w-7 h-7 rounded-lg bg-emerald-50 items-center justify-center">
                            <i class='bx bx-home text-brand-primary text-xs'></i>
                        </span>
                        Financial Dashboard
                    </span>
                </a>

                <!-- DISBURSEMENT DROPDOWN -->
                <div>
                    <div class="text-xs font-bold text-gray-400 tracking-wider px-2">DISBURSEMENT</div>
                    <button id="disbursement-menu-btn"
                        class="mt-1 w-full flex items-center justify-between px-3 py-2 rounded-xl
                               text-gray-700 hover:bg-green-50 hover:text-brand-primary
                               transition-all duration-200 hover:translate-x-1 active:translate-x-0 active:scale-[0.99] font-semibold text-sm">
                        <span class="flex items-center gap-3">
                            <span class="inline-flex w-7 h-7 rounded-lg bg-emerald-50 items-center justify-center">
                                <i class='bx bx-money text-brand-primary text-xs'></i>
                            </span>
                            Disbursement
                        </span>
                        <svg id="disbursement-arrow" class="w-3.5 h-3.5 text-emerald-400 transition-transform duration-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </button>
                    <div id="disbursement-submenu" class="submenu mt-1">
                        <div class="pl-3 pr-2 py-1.5 space-y-1 border-l-2 border-gray-100 ml-5">
                            <a href="disbursement_request.php" class="block px-3 py-1.5 rounded-lg text-xs text-gray-600 hover:bg-green-50 hover:text-brand-primary transition-all duration-200 hover:translate-x-1">Disbursement History</a>
                            <a href="pending_disbursements.php" class="block px-3 py-1.5 rounded-lg text-xs text-gray-600 hover:bg-green-50 hover:text-brand-primary transition-all duration-200 hover:translate-x-1">Pending Disbursements</a>
                            <a href="disbursement_reports.php" class="block px-3 py-1.5 rounded-lg text-xs text-gray-600 hover:bg-green-50 hover:text-brand-primary transition-all duration-200 hover:translate-x-1">Disbursement Reports</a>
                        </div>
                    </div>
                </div>

                <!-- GENERAL LEDGER DROPDOWN -->
                <div>
                    <div class="text-xs font-bold text-gray-400 tracking-wider px-2">GENERAL LEDGER</div>
                    <button id="ledger-menu-btn"
                        class="mt-1 w-full flex items-center justify-between px-3 py-2 rounded-xl
                               bg-emerald-50 text-brand-primary border border-emerald-100
                               transition-all duration-200 hover:translate-x-1 active:translate-x-0 active:scale-[0.99] font-semibold text-sm">
                        <span class="flex items-center gap-3">
                            <span class="inline-flex w-7 h-7 rounded-lg bg-emerald-100 items-center justify-center">
                                <i class='bx bx-book text-emerald-600 text-xs'></i>
                            </span>
                            General Ledger
                        </span>
                        <svg id="ledger-arrow" class="w-3.5 h-3.5 text-emerald-400 transition-transform duration-300 rotate-180" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </button>
                    <div id="ledger-submenu" class="submenu mt-1 active">
                        <div class="pl-3 pr-2 py-1.5 space-y-1 border-l-2 border-emerald-200 ml-5">
                            <a href="chart_of_accounts.php" class="block px-3 py-1.5 rounded-lg text-xs text-gray-600 hover:bg-green-50 hover:text-brand-primary transition-all duration-200 hover:translate-x-1">
                                Chart of Accounts
                            </a>
                            <a href="journal_entry.php" class="block px-3 py-1.5 rounded-lg text-xs bg-emerald-50 text-brand-primary font-medium border border-emerald-100 hover:bg-emerald-100 hover:border-emerald-200 transition-all duration-200 hover:translate-x-1">
                                <span class="flex items-center justify-between">
                                    Journal Entry
                                    <span class="inline-flex w-1.5 h-1.5 rounded-full bg-emerald-400"></span>
                                </span>
                            </a>
                            <a href="ledger_table.php" class="block px-3 py-1.5 rounded-lg text-xs text-gray-600 hover:bg-green-50 hover:text-brand-primary transition-all duration-200 hover:translate-x-1">
                                Ledger Table
                            </a>
                        </div>
                    </div>
                </div>

                <!-- AP/AR DROPDOWN -->
                <div>
                    <div class="text-xs font-bold text-gray-400 tracking-wider px-2">AP/AR</div>
                    <button id="ap-ar-menu-btn"
                        class="mt-1 w-full flex items-center justify-between px-3 py-2 rounded-xl
                               text-gray-700 hover:bg-green-50 hover:text-brand-primary
                               transition-all duration-200 hover:translate-x-1 active:translate-x-0 active:scale-[0.99] font-semibold text-sm">
                        <span class="flex items-center gap-3">
                            <span class="inline-flex w-7 h-7 rounded-lg bg-emerald-50 items-center justify-center">
                                <i class='bx bx-receipt text-brand-primary text-xs'></i>
                            </span>
                            AP/AR
                        </span>
                        <svg id="ap-ar-arrow" class="w-3.5 h-3.5 text-emerald-400 transition-transform duration-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </button>
                    <div id="ap-ar-submenu" class="submenu mt-1">
                        <div class="pl-3 pr-2 py-1.5 space-y-1 border-l-2 border-gray-100 ml-5">
                            <a href="vendors_customers.php" class="block px-3 py-1.5 rounded-lg text-xs text-gray-600 hover:bg-green-50 hover:text-brand-primary transition-all duration-200 hover:translate-x-1">Payable/Receivable</a>
                            <a href="invoices.php" class="block px-3 py-1.5 rounded-lg text-xs text-gray-600 hover:bg-green-50 hover:text-brand-primary transition-all duration-200 hover:translate-x-1">Invoices</a>
                            <a href="payment_entry.php" class="block px-3 py-1.5 rounded-lg text-xs text-gray-600 hover:bg-green-50 hover:text-brand-primary transition-all duration-200 hover:translate-x-1">Payment Entry</a>
                            <a href="aging_reports.php" class="block px-3 py-1.5 rounded-lg text-xs text-gray-600 hover:bg-green-50 hover:text-brand-primary transition-all duration-200 hover:translate-x-1">Aging Reports</a>
                        </div>
                    </div>
                </div>

                <!-- COLLECTION DROPDOWN -->
                <div>
                    <div class="text-xs font-bold text-gray-400 tracking-wider px-2">COLLECTION</div>
                    <button id="collection-menu-btn"
                        class="mt-1 w-full flex items-center justify-between px-3 py-2 rounded-xl
                               text-gray-700 hover:bg-green-50 hover:text-brand-primary
                               transition-all duration-200 hover:translate-x-1 active:translate-x-0 active:scale-[0.99] font-semibold text-sm">
                        <span class="flex items-center gap-3">
                            <span class="inline-flex w-7 h-7 rounded-lg bg-emerald-50 items-center justify-center">
                                <i class='bx bx-collection text-brand-primary text-xs'></i>
                            </span>
                            Collection
                        </span>
                        <svg id="collection-arrow" class="w-3.5 h-3.5 text-emerald-400 transition-transform duration-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </button>
                    <div id="collection-submenu" class="submenu mt-1">
                        <div class="pl-3 pr-2 py-1.5 space-y-1 border-l-2 border-gray-100 ml-5">
                            <a href="payment_entry_collection.php" class="block px-3 py-1.5 rounded-lg text-xs text-gray-600 hover:bg-green-50 hover:text-brand-primary transition-all duration-200 hover:translate-x-1">Payment Entry</a>
                            <a href="receipt_generation.php" class="block px-3 py-1.5 rounded-lg text-xs text-gray-600 hover:bg-green-50 hover:text-brand-primary transition-all duration-200 hover:translate-x-1">Receipt Generation</a>
                            <a href="collection_dashboard.php" class="block px-3 py-1.5 rounded-lg text-xs text-gray-600 hover:bg-green-50 hover:text-brand-primary transition-all duration-200 hover:translate-x-1">Collection Dashboard</a>
                            <a href="outstanding_balances.php" class="block px-3 py-1.5 rounded-lg text-xs text-gray-600 hover:bg-green-50 hover:text-brand-primary transition-all duration-200 hover:translate-x-1">Outstanding Balances</a>
                            <a href="collection_reports.php" class="block px-3 py-1.5 rounded-lg text-xs text-gray-600 hover:bg-green-50 hover:text-brand-primary transition-all duration-200 hover:translate-x-1">Collection Reports</a>
                        </div>
                    </div>
                </div>

                <!-- BUDGET DROPDOWN -->
                <div>
                    <div class="text-xs font-bold text-gray-400 tracking-wider px-2">BUDGET MANAGEMENT</div>
                    <button id="budget-menu-btn"
                        class="mt-1 w-full flex items-center justify-between px-3 py-2 rounded-xl
                               text-gray-700 hover:bg-green-50 hover:text-brand-primary
                               transition-all duration-200 hover:translate-x-1 active:translate-x-0 active:scale-[0.99] font-semibold text-sm">
                        <span class="flex items-center gap-3">
                            <span class="inline-flex w-7 h-7 rounded-lg bg-emerald-50 items-center justify-center">
                                <i class='bx bx-pie-chart-alt text-brand-primary text-xs'></i>
                            </span>
                            Budget Management
                        </span>
                        <svg id="budget-arrow" class="w-3.5 h-3.5 text-emerald-400 transition-transform duration-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </button>
                    <div id="budget-submenu" class="submenu mt-1">
                        <div class="pl-3 pr-2 py-1.5 space-y-1 border-l-2 border-gray-100 ml-5">
                            <a href="budget_proposal.php" class="block px-3 py-1.5 rounded-lg text-xs text-gray-600 hover:bg-green-50 hover:text-brand-primary transition-all duration-200 hover:translate-x-1">Budget Proposal</a>
                            <a href="approval_workflow.php" class="block px-3 py-1.5 rounded-lg text-xs text-gray-600 hover:bg-green-50 hover:text-brand-primary transition-all duration-200 hover:translate-x-1">Approval Workflow</a>
                            <a href="budget_vs_actual.php" class="block px-3 py-1.5 rounded-lg text-xs text-gray-600 hover:bg-green-50 hover:text-brand-primary transition-all duration-200 hover:translate-x-1">Budget vs Actual</a>
                            <a href="budget_reports.php" class="block px-3 py-1.5 rounded-lg text-xs text-gray-600 hover:bg-green-50 hover:text-brand-primary transition-all duration-200 hover:translate-x-1">Budget Reports</a>
                        </div>
                    </div>
                </div>

                <!-- Footer section -->
                <div class="pt-3 mt-3 border-t border-gray-100">
                    <div class="flex items-center gap-2 text-xs font-bold text-emerald-600">
                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                        SYSTEM ONLINE
                    </div>
                    <div class="text-[10px] text-gray-400 mt-1.5 leading-snug">
                        Microfinancial System © 2025<br/>
                        Predictive Budgeting & Cash Flow
                    </div>
                </div>
            </div>
        </div>
    </aside>

    <!-- MAIN WRAPPER -->
    <div class="md:pl-72">
        <!-- TOP HEADER -->
        <header class="h-16 bg-white flex items-center justify-between px-4 sm:px-6 relative
                       shadow-[0_2px_8px_rgba(0,0,0,0.06)]">
            
            <!-- BORDER COVER -->
            <div class="hidden md:block absolute left-0 top-0 h-16 w-[2px] bg-white"></div>

            <div class="flex items-center gap-3">
                <button id="mobile-menu-btn"
                    class="md:hidden w-10 h-10 rounded-xl hover:bg-gray-100 active:bg-gray-200 transition flex items-center justify-center">
                    ☰
                </button>
                <div>
                    <h1 class="text-lg font-bold text-gray-800">
                        Journal Entry
                    </h1>
                    <p class="text-xs text-gray-500">
                        Record and manage journal entries
                    </p>
                </div>
            </div>

            <div class="flex items-center gap-3 sm:gap-5">
                <!-- Real-time Clock -->
                <span id="real-time-clock"
                    class="text-xs font-bold text-gray-700 bg-gray-50 px-3 py-2 rounded-lg border border-gray-200">
                    --:--:--
                </span>

                <!-- Single Visibility Toggle Button for ALL Amounts -->
                <button id="amount-visibility-toggle" class="w-10 h-10 rounded-xl hover:bg-gray-100 active:bg-gray-200 transition flex items-center justify-center relative"
                        title="Toggle All Amounts Visibility">
                    <i class="fa-solid fa-eye-slash text-gray-600"></i>
                </button>

                <!-- Notifications -->
                <button id="notification-btn" class="w-10 h-10 rounded-xl hover:bg-gray-100 active:bg-gray-200 transition flex items-center justify-center relative">
                    <i class="fa-solid fa-bell text-gray-600"></i>
                    <?php if($unread_count > 0): ?>
                        <span class="notification-badge"></span>
                    <?php endif; ?>
                </button>

                <div class="h-8 w-px bg-gray-200 hidden sm:block"></div>

                <!-- User Profile Dropdown -->
                <div class="relative">
                    <button id="user-menu-button"
                        class="flex items-center gap-3 focus:outline-none group rounded-xl px-2 py-2
                               hover:bg-gray-100 active:bg-gray-200 transition">
                        <div class="w-10 h-10 rounded-full bg-white shadow group-hover:shadow-md transition-shadow overflow-hidden flex items-center justify-center border border-gray-100">
                            <div class="w-full h-full flex items-center justify-center font-bold text-brand-primary bg-emerald-50">
                                <?php echo strtoupper(substr($user['name'], 0, 1)); ?>
                            </div>
                        </div>
                        <div class="hidden md:flex flex-col items-start text-left">
                            <span class="text-sm font-bold text-gray-700 group-hover:text-brand-primary transition-colors">
                                <?php echo htmlspecialchars($user['name']); ?>
                            </span>
                            <span class="text-[10px] text-gray-500 font-medium uppercase group-hover:text-brand-primary transition-colors">
                                <?php echo ucfirst(htmlspecialchars($user['role'])); ?>
                            </span>
                        </div>
                        <svg class="w-4 h-4 text-gray-400 group-hover:text-brand-primary transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </button>

                    <div id="user-menu-dropdown"
                        class="dropdown-panel hidden absolute right-0 mt-3 w-56 bg-white rounded-xl shadow-lg border border-gray-100 z-50">
                        <a href="#" class="block px-4 py-3 text-sm text-gray-700 hover:bg-gray-50 transition">
                            <i class='bx bx-user mr-2'></i> Profile
                        </a>
                        <a href="#" class="block px-4 py-3 text-sm text-gray-700 hover:bg-gray-50 transition">
                            <i class='bx bx-cog mr-2'></i> Settings
                        </a>
                        <div class="h-px bg-gray-100"></div>
                        <a href="#" id="dropdown-logout-btn" class="block px-4 py-3 text-sm text-red-600 hover:bg-red-50 transition">
                            <i class='bx bx-log-out mr-2'></i> Logout
                        </a>
                    </div>
                </div>
            </div>
        </header>

        <main id="main-content" class="p-4 sm:p-6">
            <!-- Success/Error Messages -->
            <?php if (isset($success_message)): ?>
                <div class="alert alert-success mb-6">
                    <?php echo htmlspecialchars($success_message); ?>
                </div>
            <?php endif; ?>
            
            <?php if (isset($error_message)): ?>
                <div class="alert alert-error mb-6">
                    <?php echo htmlspecialchars($error_message); ?>
                </div>
            <?php endif; ?>

            <!-- Quick Stats -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
                <div class="stat-card rounded-xl p-6">
                    <div class="flex items-center gap-4">
                        <div class="p-3 rounded-lg bg-blue-100">
                            <i class='bx bx-book text-blue-600 text-2xl'></i>
                        </div>
                        <div class="flex-1">
                            <div class="flex justify-between items-start">
                                <div>
                                    <p class="text-sm text-gray-500">Total Entries</p>
                                    <p class="text-2xl font-bold text-gray-800">
                                        <?php echo count($journal_entries); ?>
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="stat-card rounded-xl p-6">
                    <div class="flex items-center gap-4">
                        <div class="p-3 rounded-lg bg-yellow-100">
                            <i class='bx bx-calendar text-yellow-600 text-2xl'></i>
                        </div>
                        <div class="flex-1">
                            <div class="flex justify-between items-start">
                                <div>
                                    <p class="text-sm text-gray-500">This Month</p>
                                    <p class="text-2xl font-bold text-gray-800">
                                        <?php 
                                        $current_month = date('Y-m');
                                        $month_count = count(array_filter($journal_entries, fn($entry) => 
                                            substr($entry['entry_date'], 0, 7) === $current_month
                                        ));
                                        echo $month_count;
                                        ?>
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="stat-card rounded-xl p-6">
                    <div class="flex items-center gap-4">
                        <div class="p-3 rounded-lg bg-green-100">
                            <i class='bx bx-money text-green-600 text-2xl'></i>
                        </div>
                        <div class="flex-1">
                            <div class="flex justify-between items-start">
                                <div>
                                    <p class="text-sm text-gray-500">Total Amount</p>
                                    <p class="text-2xl font-bold text-gray-800 amount-value" 
                                       data-value="₱<?php 
                                            $total_amount = 0;
                                            foreach ($journal_entries as $entry) {
                                                $entry_lines = getJournalEntryLines($db, $entry['id']);
                                                $total_amount += array_sum(array_column($entry_lines, 'debit'));
                                            }
                                            echo number_format($total_amount, 2);
                                        ?>">
                                        ••••••••
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Journal Entry Content -->
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden mb-8">
                <div class="p-6 border-b border-gray-100">
                    <div class="flex justify-between items-center">
                        <div class="flex items-center gap-3">
                            <h3 class="text-lg font-bold text-gray-800">Journal Entries</h3>
                        </div>
                        <button class="px-4 py-2 bg-brand-primary text-white rounded-lg hover:bg-brand-primary-hover transition flex items-center gap-2" onclick="document.getElementById('new-entry-modal').style.display='block'">
                            <i class='bx bx-plus'></i> New Entry
                        </button>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="text-left p-4 text-sm font-medium text-gray-500">Entry ID</th>
                                <th class="text-left p-4 text-sm font-medium text-gray-500">Date</th>
                                <th class="text-left p-4 text-sm font-medium text-gray-500">Description</th>
                                <th class="text-left p-4 text-sm font-medium text-gray-500">Debit Total</th>
                                <th class="text-left p-4 text-sm font-medium text-gray-500">Credit Total</th>
                                <th class="text-left p-4 text-sm font-medium text-gray-500">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($journal_entries) > 0): ?>
                                <?php foreach ($journal_entries as $entry): 
                                    $entry_lines = getJournalEntryLines($db, $entry['id']);
                                    $debit_total = array_sum(array_column($entry_lines, 'debit'));
                                    $credit_total = array_sum(array_column($entry_lines, 'credit'));
                                ?>
                                <tr class="transaction-row">
                                    <td class="p-4 font-mono font-medium text-gray-800">
                                        <?php echo htmlspecialchars($entry['entry_id']); ?>
                                    </td>
                                    <td class="p-4 text-gray-600"><?php echo htmlspecialchars($entry['entry_date']); ?></td>
                                    <td class="p-4 text-gray-600 max-w-xs"><?php echo htmlspecialchars($entry['description']); ?></td>
                                    <td class="p-4 debit-amount">
                                        <span class="amount-value hidden-amount font-semibold" 
                                              data-value="₱<?php echo number_format((float)$debit_total, 2); ?>">
                                            ••••••••
                                        </span>
                                    </td>
                                    <td class="p-4 credit-amount">
                                        <span class="amount-value hidden-amount font-semibold" 
                                              data-value="₱<?php echo number_format((float)$credit_total, 2); ?>">
                                            ••••••••
                                        </span>
                                    </td>
                                    <td class="p-4">
                                        <span class="status-badge status-approved">
                                            Posted
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="text-center p-8 text-gray-500">
                                        <i class='bx bx-folder-open text-3xl mb-2 text-gray-300'></i>
                                        <div>No journal entries found.</div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>

    <!-- Notification Modal -->
    <div id="notification-modal" class="modal">
        <div class="modal-content">
            <div class="flex justify-between items-center mb-6">
                <h2 class="text-xl font-bold text-gray-800">Notifications</h2>
                <button class="close-modal text-gray-400 hover:text-gray-600 text-2xl">&times;</button>
            </div>
            <div id="notification-modal-list" class="space-y-4">
                <!-- Notifications will be loaded here -->
            </div>
        </div>
    </div>

    <!-- New Journal Entry Modal -->
    <div id="new-entry-modal" class="modal">
        <div class="modal-content">
            <div class="flex justify-between items-center mb-6">
                <h2 class="text-xl font-bold text-gray-800">Create New Journal Entry</h2>
                <button class="close-modal text-gray-400 hover:text-gray-600 text-2xl">&times;</button>
            </div>
            <form id="journal-entry-form" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <div class="space-y-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2 required-field">Entry Date</label>
                            <input type="date" name="entry_date" class="form-input" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2 required-field">Reference Number</label>
                            <input type="text" name="entry_id" class="form-input" placeholder="JE-2025-001" required>
                        </div>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2 required-field">Description</label>
                        <textarea name="description" class="form-input" rows="3" placeholder="Enter journal entry description" required></textarea>
                    </div>
                    
                    <div>
                        <h3 class="font-bold text-lg mb-4">Entry Line</h3>
                        <div id="entry-lines-container">
                            <!-- Single entry line -->
                            <div class="entry-line">
                                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                    <div class="form-group">
                                        <label class="form-label required-field">Account</label>
                                        <select name="accounts[]" class="form-input" required>
                                            <option value="">Select Account</option>
                                            <?php foreach ($chart_accounts as $account): ?>
                                            <option value="<?php echo $account['id']; ?>">
                                                <?php echo htmlspecialchars($account['account_code'] . ' - ' . $account['account_name']); ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Debit</label>
                                        <input type="number" name="debits[]" class="form-input debit-input" placeholder="0.00" step="0.01" min="0" value="0">
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Credit</label>
                                        <input type="number" name="credits[]" class="form-input credit-input" placeholder="0.00" step="0.01" min="0" value="0">
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="flex justify-end items-center mt-4">
                            <div id="balance-check" class="balance-check balance-unbalanced">
                                Debit: ₱0.00 | Credit: ₱0.00 | Difference: ₱0.00
                            </div>
                        </div>
                    </div>
                    
                    <div class="flex gap-3 pt-4">
                        <button type="button" class="flex-1 px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition close-modal">Cancel</button>
                        <button type="submit" class="flex-1 px-4 py-2 bg-brand-primary text-white rounded-lg hover:bg-brand-primary-hover transition">Post Entry</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Real-time Clock (12-hour format with AM/PM)
        function updateClock() {
            const now = new Date();
            const hours = now.getHours();
            const minutes = now.getMinutes().toString().padStart(2, '0');
            const seconds = now.getSeconds().toString().padStart(2, '0');
            const ampm = hours >= 12 ? 'PM' : 'AM';
            const displayHours = hours % 12 || 12;
            
            const timeString = `${displayHours}:${minutes}:${seconds} ${ampm}`;
            const clockElement = document.getElementById('real-time-clock');
            if (clockElement) {
                clockElement.textContent = timeString;
            }
        }
        setInterval(updateClock, 1000);
        updateClock();

        // Initialize common features
        initializeCommonFeatures();

        // Initialize all amounts as hidden on page load
        initializeAmountVisibility();

        // Single visibility state for ALL amounts
        let allAmountsVisible = false;

        // SINGLE VISIBILITY TOGGLE FOR ALL AMOUNTS
        const amountVisibilityToggle = document.getElementById('amount-visibility-toggle');
        
        function toggleAllAmounts() {
            allAmountsVisible = !allAmountsVisible;
            
            // Toggle ALL amount values (stat cards AND table amounts)
            const allAmounts = document.querySelectorAll('.amount-value');
            allAmounts.forEach(span => {
                if (allAmountsVisible) {
                    const actualAmount = span.getAttribute('data-value');
                    span.textContent = actualAmount;
                    span.classList.remove('hidden-amount');
                } else {
                    span.textContent = '••••••••';
                    span.classList.add('hidden-amount');
                }
            });
            
            // Update toggle icon
            const icon = amountVisibilityToggle.querySelector('i');
            if (allAmountsVisible) {
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
                amountVisibilityToggle.title = "Hide All Amounts";
            } else {
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
                amountVisibilityToggle.title = "Show All Amounts";
            }
        }
        
        if (amountVisibilityToggle) {
            amountVisibilityToggle.addEventListener('click', toggleAllAmounts);
        }

        // Initialize amount visibility state
        function initializeAmountVisibility() {
            // Initialize ALL amounts as hidden
            const allAmounts = document.querySelectorAll('.amount-value');
            allAmounts.forEach(span => {
                span.textContent = '••••••••';
                span.classList.add('hidden-amount');
            });
        }

        // Update balance check
        function updateBalanceCheck() {
            let totalDebit = 0;
            let totalCredit = 0;
            
            // Get all debit and credit inputs
            const debitInputs = document.querySelectorAll('input[name="debits[]"]');
            const creditInputs = document.querySelectorAll('input[name="credits[]"]');
            
            // Calculate total debit
            debitInputs.forEach(input => {
                totalDebit += parseFloat(input.value) || 0;
            });
            
            // Calculate total credit
            creditInputs.forEach(input => {
                totalCredit += parseFloat(input.value) || 0;
            });
            
            const difference = Math.abs(totalDebit - totalCredit);
            const isBalanced = Math.abs(totalDebit - totalCredit) < 0.01;
            
            // Update balance check display
            const balanceCheck = document.getElementById('balance-check');
            if (balanceCheck) {
                balanceCheck.textContent = `Debit: ₱${totalDebit.toFixed(2)} | Credit: ₱${totalCredit.toFixed(2)} | Difference: ₱${difference.toFixed(2)}`;
                
                if (isBalanced) {
                    balanceCheck.className = 'balance-check balance-balanced';
                } else {
                    balanceCheck.className = 'balance-check balance-unbalanced';
                }
            }
        }

        // Initialize balance check for existing inputs
        const debitInputs = document.querySelectorAll('input[name="debits[]"]');
        const creditInputs = document.querySelectorAll('input[name="credits[]"]');
        
        debitInputs.forEach(input => {
            input.addEventListener('input', updateBalanceCheck);
        });
        
        creditInputs.forEach(input => {
            input.addEventListener('input', updateBalanceCheck);
        });
        
        // Initial balance check
        updateBalanceCheck();

        // Enhanced form validation
        function validateJournalEntry() {
            const entryId = document.querySelector('input[name="entry_id"]');
            const description = document.querySelector('textarea[name="description"]');
            const entryDate = document.querySelector('input[name="entry_date"]');
            
            // Clear previous errors
            clearValidationErrors();
            
            let isValid = true;
            
            // Entry ID validation
            if (entryId && !/^[A-Za-z0-9\-_]+$/.test(entryId.value)) {
                showValidationError(entryId, 'Only letters, numbers, hyphens, and underscores allowed');
                isValid = false;
            }
            
            // Description validation
            if (description && description.value.trim().length < 5) {
                showValidationError(description, 'Description must be at least 5 characters');
                isValid = false;
            }
            
            // Date validation (not future)
            if (entryDate && new Date(entryDate.value) > new Date()) {
                showValidationError(entryDate, 'Entry date cannot be in the future');
                isValid = false;
            }
            
            // Line validation - at least one line with non-zero amounts
            const accounts = document.querySelectorAll('select[name="accounts[]"]');
            let validLines = 0;
            
            accounts.forEach((account, index) => {
                const debit = parseFloat(document.querySelectorAll('input[name="debits[]"]')[index].value) || 0;
                const credit = parseFloat(document.querySelectorAll('input[name="credits[]"]')[index].value) || 0;
                
                if (account.value && (debit > 0 || credit > 0)) {
                    validLines++;
                }
            });
            
            if (validLines < 1) {
                alert('Journal entry must have at least one valid line with non-zero amounts.');
                isValid = false;
            }
            
            return isValid;
        }

        function showValidationError(element, message) {
            // Remove existing error
            const existingError = element.parentNode.querySelector('.validation-error');
            if (existingError) {
                existingError.remove();
            }
            
            // Add error styling
            element.classList.add('error');
            
            // Add error message
            const errorDiv = document.createElement('div');
            errorDiv.className = 'validation-error';
            errorDiv.textContent = message;
            element.parentNode.appendChild(errorDiv);
            
            // Focus on the problematic field
            element.focus();
        }

        function clearValidationErrors() {
            document.querySelectorAll('.form-input').forEach(input => {
                input.classList.remove('error');
            });
            document.querySelectorAll('.validation-error').forEach(error => {
                error.remove();
            });
        }

        function clearValidationError(element) {
            element.classList.remove('error');
            const existingError = element.parentNode.querySelector('.validation-error');
            if (existingError) {
                existingError.remove();
            }
        }

        // Real-time validation for key fields
        const entryIdInput = document.querySelector('input[name="entry_id"]');
        const descriptionInput = document.querySelector('textarea[name="description"]');
        
        if (entryIdInput) {
            entryIdInput.addEventListener('blur', function() {
                if (!/^[A-Za-z0-9\-_]+$/.test(this.value)) {
                    showValidationError(this, 'Only letters, numbers, hyphens, and underscores allowed');
                } else {
                    clearValidationError(this);
                }
            });
        }
        
        if (descriptionInput) {
            descriptionInput.addEventListener('blur', function() {
                if (this.value.trim().length < 5) {
                    showValidationError(this, 'Description must be at least 5 characters');
                } else {
                    clearValidationError(this);
                }
            });
        }

        // Journal entry form submission
        const journalEntryForm = document.getElementById('journal-entry-form');
        if (journalEntryForm) {
            journalEntryForm.addEventListener('submit', function(e) {
                if (!validateJournalEntry()) {
                    e.preventDefault();
                    return;
                }
                
                // Existing balance validation
                const totalDebit = Array.from(document.querySelectorAll('input[name="debits[]"]'))
                    .reduce((sum, input) => sum + (parseFloat(input.value) || 0), 0);
                const totalCredit = Array.from(document.querySelectorAll('input[name="credits[]"]'))
                    .reduce((sum, input) => sum + (parseFloat(input.value) || 0), 0);
                
                if (Math.abs(totalDebit - totalCredit) > 0.01) {
                    e.preventDefault();
                    alert('Journal entry must be balanced! Debits must equal credits.');
                    return;
                }
                
                // Show loading state
                const submitBtn = this.querySelector('button[type="submit"]');
                const originalText = submitBtn.innerHTML;
                submitBtn.innerHTML = '<div class="spinner"></div>Processing...';
                submitBtn.disabled = true;
            });
        }
    });

    function initializeCommonFeatures() {
        // Sidebar functionality
        const mobileMenuBtn = document.getElementById('mobile-menu-btn');
        const sidebar = document.getElementById('sidebar');
        const sidebarOverlay = document.getElementById('sidebar-overlay');
        
        if (mobileMenuBtn && sidebar && sidebarOverlay) {
            mobileMenuBtn.addEventListener('click', function() {
                sidebar.classList.toggle('-translate-x-full');
                sidebarOverlay.classList.toggle('hidden');
                setTimeout(() => {
                    sidebarOverlay.classList.toggle('opacity-0');
                }, 10);
            });
            
            sidebarOverlay.addEventListener('click', function() {
                sidebar.classList.add('-translate-x-full');
                sidebarOverlay.classList.add('hidden');
                sidebarOverlay.classList.add('opacity-0');
            });
        }
        
        // Sidebar submenus
        const menuButtons = document.querySelectorAll('[id$="menu-btn"]');
        menuButtons.forEach(button => {
            button.addEventListener('click', function() {
                const menuId = this.id.replace('menu-btn', 'submenu');
                const arrowId = this.id.replace('menu-btn', 'arrow');
                const submenu = document.getElementById(menuId);
                const arrow = document.getElementById(arrowId);
                
                if (submenu) {
                    submenu.classList.toggle('active');
                }
                if (arrow) {
                    arrow.classList.toggle('rotate-180');
                }
                
                // Close other submenus
                menuButtons.forEach(otherBtn => {
                    if (otherBtn !== this) {
                        const otherMenuId = otherBtn.id.replace('menu-btn', 'submenu');
                        const otherArrowId = otherBtn.id.replace('menu-btn', 'arrow');
                        const otherSubmenu = document.getElementById(otherMenuId);
                        const otherArrow = document.getElementById(otherArrowId);
                        
                        if (otherSubmenu) {
                            otherSubmenu.classList.remove('active');
                        }
                        if (otherArrow) {
                            otherArrow.classList.remove('rotate-180');
                        }
                    }
                });
            });
        });
        
        // User dropdown
        const userMenuButton = document.getElementById('user-menu-button');
        const userMenuDropdown = document.getElementById('user-menu-dropdown');
        
        if (userMenuButton && userMenuDropdown) {
            userMenuButton.addEventListener('click', function(e) {
                e.stopPropagation();
                userMenuDropdown.classList.toggle('hidden');
                userMenuDropdown.classList.toggle('active');
            });
            
            document.addEventListener('click', function(e) {
                if (!userMenuButton.contains(e.target) && !userMenuDropdown.contains(e.target)) {
                    userMenuDropdown.classList.add('hidden');
                    userMenuDropdown.classList.remove('active');
                }
            });
        }
        
        // Logout functionality
        const logoutBtn = document.getElementById('dropdown-logout-btn');
        if (logoutBtn) {
            logoutBtn.addEventListener('click', function(e) {
                e.preventDefault();
                if (confirm('Are you sure you want to logout?')) {
                    window.location.href = '?logout=true';
                }
            });
        }
        
        // Modal functionality
        const notificationBtn = document.getElementById('notification-btn');
        const notificationModal = document.getElementById('notification-modal');
        const closeModalBtns = document.querySelectorAll('.close-modal');
        
        if (notificationBtn && notificationModal) {
            notificationBtn.addEventListener('click', function() {
                notificationModal.style.display = 'block';
                document.body.style.overflow = 'hidden';
            });
        }
        
        closeModalBtns.forEach(btn => {
            btn.addEventListener('click', function() {
                const modal = this.closest('.modal');
                if (modal) {
                    modal.style.display = 'none';
                    document.body.style.overflow = 'auto';
                }
            });
        });
        
        // Close modals when clicking outside
        window.addEventListener('click', function(e) {
            if (e.target.classList.contains('modal')) {
                e.target.style.display = 'none';
                document.body.style.overflow = 'auto';
            }
        });
    }
    </script>
</body>
</html>