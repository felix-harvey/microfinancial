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

// Get chart of accounts for filter
function getChartOfAccounts(PDO $db): array {
    $sql = "SELECT id, account_code, account_name, account_type 
            FROM chart_of_accounts 
            WHERE status = 'Active'
            ORDER BY account_type, account_code";
    return $db->query($sql)->fetchAll();
}

// Get ledger data with filters - UPDATED WITH NULL SAFETY
function getLedgerData(PDO $db, ?int $account_id = null, ?string $start_date = null, ?string $end_date = null): array {
    $sql = "SELECT 
                jel.id,
                COALESCE(je.entry_date, '') as entry_date,
                COALESCE(je.entry_id, '') as entry_id,
                COALESCE(je.description, '') as description,
                COALESCE(coa.account_code, '') as account_code,
                COALESCE(coa.account_name, '') as account_name,
                COALESCE(coa.account_type, '') as account_type,
                COALESCE(jel.debit, 0) as debit,
                COALESCE(jel.credit, 0) as credit,
                (SELECT COALESCE(SUM(
                    CASE 
                        WHEN coa.account_type IN ('Asset', 'Expense') THEN jel2.debit - jel2.credit
                        ELSE jel2.credit - jel2.debit 
                    END
                ), 0) 
                FROM journal_entry_lines jel2 
                JOIN journal_entries je2 ON jel2.journal_entry_id = je2.id 
                WHERE jel2.account_id = coa.id 
                AND je2.entry_date <= je.entry_date
                AND (je2.entry_date < je.entry_date OR jel2.id <= jel.id)
                ) as running_balance
            FROM journal_entry_lines jel
            JOIN journal_entries je ON jel.journal_entry_id = je.id
            JOIN chart_of_accounts coa ON jel.account_id = coa.id
            WHERE je.status = 'Posted'";
    
    $params = [];
    
    if ($account_id) {
        $sql .= " AND coa.id = ?";
        $params[] = $account_id;
    }
    
    if ($start_date) {
        $sql .= " AND je.entry_date >= ?";
        $params[] = $start_date;
    }
    
    if ($end_date) {
        $sql .= " AND je.entry_date <= ?";
        $params[] = $end_date;
    }
    
    $sql .= " ORDER BY coa.account_code, je.entry_date, je.id, jel.id";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $result = $stmt->fetchAll();
    
    // Ensure all values are properly set
    foreach ($result as &$row) {
        $row['entry_date'] = $row['entry_date'] ?? '';
        $row['entry_id'] = $row['entry_id'] ?? '';
        $row['description'] = $row['description'] ?? '';
        $row['account_code'] = $row['account_code'] ?? '';
        $row['account_name'] = $row['account_name'] ?? '';
        $row['account_type'] = $row['account_type'] ?? '';
        $row['debit'] = $row['debit'] ?? 0;
        $row['credit'] = $row['credit'] ?? 0;
        $row['running_balance'] = $row['running_balance'] ?? 0;
    }
    
    return $result;
}

// Get account summary
function getAccountSummary(PDO $db, ?int $account_id = null, ?string $start_date = null, ?string $end_date = null): array {
    $sql = "SELECT 
                coa.id,
                coa.account_code,
                coa.account_name,
                coa.account_type,
                COALESCE(SUM(jel.debit), 0) as total_debit,
                COALESCE(SUM(jel.credit), 0) as total_credit,
                COALESCE(SUM(jel.debit - jel.credit), 0) as net_balance
            FROM chart_of_accounts coa
            LEFT JOIN journal_entry_lines jel ON coa.id = jel.account_id
            LEFT JOIN journal_entries je ON jel.journal_entry_id = je.id AND je.status = 'Posted'
            WHERE coa.status = 'Active'";
    
    $params = [];
    
    if ($account_id) {
        $sql .= " AND coa.id = ?";
        $params[] = $account_id;
    }
    
    if ($start_date) {
        $sql .= " AND je.entry_date >= ?";
        $params[] = $start_date;
    }
    
    if ($end_date) {
        $sql .= " AND je.entry_date <= ?";
        $params[] = $end_date;
    }
    
    $sql .= " GROUP BY coa.id, coa.account_code, coa.account_name, coa.account_type
              ORDER BY coa.account_type, coa.account_code";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

// Get filter values from request and properly handle types
$account_id = isset($_GET['account_id']) && $_GET['account_id'] !== '' ? (int)$_GET['account_id'] : null;
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-t');

$chart_accounts = getChartOfAccounts($db);
$ledger_data = getLedgerData($db, $account_id, $start_date, $end_date);
$account_summary = getAccountSummary($db, $account_id, $start_date, $end_date);

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
    <title>Ledger Table - Financial Dashboard</title>

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

        /* Ledger specific styles */
        .debit-amount {
            color: #10B981;
            font-weight: 600;
        }
        
        .credit-amount {
            color: #EF4444;
            font-weight: 600;
        }
        
        .balance-positive {
            color: #10B981;
            font-weight: 600;
        }
        
        .balance-negative {
            color: #EF4444;
            font-weight: 600;
        }
        
        .account-header {
            background-color: #f8fafc !important;
            font-weight: 600;
            border-top: 2px solid #e2e8f0;
        }
        
        .account-total {
            background-color: #f1f5f9 !important;
            font-weight: 600;
            border-top: 2px solid #cbd5e1;
        }
        
        .running-balance {
            font-family: 'Courier New', monospace;
        }

        .export-btn {
            background-color: #F0FDF4;
            color: #047857;
            border: 1px solid #047857;
            padding: 0.5rem 1rem;
            border-radius: 0.5rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .export-btn:hover {
            background-color: #047857;
            color: white;
        }
        
        .print-btn {
            background-color: #F0F9FF;
            color: #0369A1;
            border: 1px solid #0369A1;
            padding: 0.5rem 1rem;
            border-radius: 0.5rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .print-btn:hover {
            background-color: #0369A1;
            color: white;
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
                            <a href="journal_entry.php" class="block px-3 py-1.5 rounded-lg text-xs text-gray-600 hover:bg-green-50 hover:text-brand-primary transition-all duration-200 hover:translate-x-1">
                                Journal Entry
                            </a>
                            <a href="ledger_table.php" class="block px-3 py-1.5 rounded-lg text-xs bg-emerald-50 text-brand-primary font-medium border border-emerald-100 hover:bg-emerald-100 hover:border-emerald-200 transition-all duration-200 hover:translate-x-1">
                                <span class="flex items-center justify-between">
                                    Ledger Table
                                    <span class="inline-flex w-1.5 h-1.5 rounded-full bg-emerald-400"></span>
                                </span>
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
                        Ledger Table
                    </h1>
                    <p class="text-xs text-gray-500">
                        View account transactions and running balances
                    </p>
                </div>
            </div>

            <div class="flex items-center gap-3 sm:gap-5">
                <!-- Real-time Clock -->
                <span id="real-time-clock"
                    class="text-xs font-bold text-gray-700 bg-gray-50 px-3 py-2 rounded-lg border border-gray-200">
                    --:--:--
                </span>

                <!-- Single Visibility Toggle for ALL AMOUNTS in Ledger Table -->
                <button id="amount-visibility-toggle" class="w-10 h-10 rounded-xl hover:bg-gray-100 active:bg-gray-200 transition flex items-center justify-center relative"
                        title="Toggle Amount Visibility">
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
            <!-- Filter Section -->
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 mb-6">
                <h3 class="text-lg font-bold text-gray-800 mb-4">Filter Ledger</h3>
                <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4">
                    <div class="form-group">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Account</label>
                        <select name="account_id" class="form-input">
                            <option value="">All Accounts</option>
                            <?php foreach ($chart_accounts as $account): ?>
                            <option value="<?php echo $account['id']; ?>" <?php echo ($account_id == $account['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($account['account_code'] . ' - ' . $account['account_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Start Date</label>
                        <input type="date" name="start_date" class="form-input" value="<?php echo htmlspecialchars($start_date); ?>">
                    </div>
                    <div class="form-group">
                        <label class="block text-sm font-medium text-gray-700 mb-2">End Date</label>
                        <input type="date" name="end_date" class="form-input" value="<?php echo htmlspecialchars($end_date); ?>">
                    </div>
                    <div class="form-group flex items-end space-x-2">
                        <button type="submit" class="flex-1 px-4 py-2 bg-brand-primary text-white rounded-lg hover:bg-brand-primary-hover transition flex items-center justify-center">
                            <i class='bx bx-filter-alt mr-2'></i>Apply
                        </button>
                        <a href="ledger_table.php" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition flex items-center justify-center">
                            <i class='bx bx-reset mr-2'></i>Reset
                        </a>
                    </div>
                </form>
            </div>

            <!-- Account Summary -->
            <div id="account-summary" class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden mb-6">
                <div class="p-6 border-b border-gray-100">
                    <div class="flex justify-between items-center">
                        <div class="flex items-center gap-3">
                            <h3 class="text-lg font-bold text-gray-800">Account Summary</h3>
                        </div>
                        <div class="flex space-x-2">
                            <button class="export-btn flex items-center gap-2" id="export-summary-btn">
                                <i class='bx bx-download'></i>Export
                            </button>
                            <button class="print-btn flex items-center gap-2" onclick="window.print()">
                                <i class='bx bx-printer'></i>Print
                            </button>
                        </div>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="text-left p-4 text-sm font-medium text-gray-500">Account Code</th>
                                <th class="text-left p-4 text-sm font-medium text-gray-500">Account Name</th>
                                <th class="text-left p-4 text-sm font-medium text-gray-500">Account Type</th>
                                <th class="text-left p-4 text-sm font-medium text-gray-500">Total Debit</th>
                                <th class="text-left p-4 text-sm font-medium text-gray-500">Total Credit</th>
                                <th class="text-left p-4 text-sm font-medium text-gray-500">Net Balance</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($account_summary) > 0): ?>
                                <?php 
                                $grand_total_debit = 0;
                                $grand_total_credit = 0;
                                $grand_net_balance = 0;
                                ?>
                                <?php foreach ($account_summary as $account): 
                                    $grand_total_debit += (float)$account['total_debit'];
                                    $grand_total_credit += (float)$account['total_credit'];
                                    $grand_net_balance += (float)$account['net_balance'];
                                ?>
                                <tr class="transaction-row">
                                    <td class="p-4 font-mono font-medium text-gray-800"><?php echo htmlspecialchars($account['account_code']); ?></td>
                                    <td class="p-4 text-gray-600"><?php echo htmlspecialchars($account['account_name']); ?></td>
                                    <td class="p-4">
                                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium 
                                            <?php echo $account['account_type'] === 'Asset' ? 'bg-blue-100 text-blue-800' : 
                                                   ($account['account_type'] === 'Liability' ? 'bg-red-100 text-red-800' : 
                                                   ($account['account_type'] === 'Equity' ? 'bg-purple-100 text-purple-800' : 
                                                   ($account['account_type'] === 'Revenue' ? 'bg-green-100 text-green-800' : 
                                                   'bg-yellow-100 text-yellow-800'))); ?>">
                                            <?php echo htmlspecialchars($account['account_type']); ?>
                                        </span>
                                    </td>
                                    <td class="p-4 debit-amount">
                                        <span class="amount-value hidden-amount" 
                                              data-value="₱<?php echo number_format((float)$account['total_debit'], 2); ?>">
                                            ••••••••
                                        </span>
                                    </td>
                                    <td class="p-4 credit-amount">
                                        <span class="amount-value hidden-amount" 
                                              data-value="₱<?php echo number_format((float)$account['total_credit'], 2); ?>">
                                            ••••••••
                                        </span>
                                    </td>
                                    <td class="p-4 <?php echo (float)$account['net_balance'] >= 0 ? 'balance-positive' : 'balance-negative'; ?>">
                                        <span class="amount-value hidden-amount" 
                                              data-value="₱<?php echo number_format((float)$account['net_balance'], 2); ?>">
                                            ••••••••
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <!-- Grand Total Row -->
                                <tr class="bg-gray-50">
                                    <td colspan="3" class="p-4 font-bold text-gray-800">GRAND TOTAL</td>
                                    <td class="p-4 debit-amount font-bold">
                                        <span class="amount-value hidden-amount" 
                                              data-value="₱<?php echo number_format($grand_total_debit, 2); ?>">
                                            ••••••••
                                        </span>
                                    </td>
                                    <td class="p-4 credit-amount font-bold">
                                        <span class="amount-value hidden-amount" 
                                              data-value="₱<?php echo number_format($grand_total_credit, 2); ?>">
                                            ••••••••
                                        </span>
                                    </td>
                                    <td class="p-4 <?php echo $grand_net_balance >= 0 ? 'balance-positive' : 'balance-negative'; ?> font-bold">
                                        <span class="amount-value hidden-amount" 
                                              data-value="₱<?php echo number_format($grand_net_balance, 2); ?>">
                                            ••••••••
                                        </span>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="text-center p-8 text-gray-500">
                                        <i class='bx bx-folder-open text-3xl mb-2 text-gray-300'></i>
                                        <div>No ledger data found for the selected filters.</div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Detailed Ledger Table -->
            <div id="detailed-ledger" class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                <div class="p-6 border-b border-gray-100">
                    <div class="flex justify-between items-center">
                        <div class="flex items-center gap-3">
                            <h3 class="text-lg font-bold text-gray-800">Detailed Ledger Transactions</h3>
                        </div>
                        <div class="text-sm text-gray-500">
                            <?php 
                            $total_accounts = count($chart_accounts);
                            $accounts_with_transactions = 0;
                            if (!empty($ledger_data)) {
                                $account_codes = array_filter(array_column($ledger_data, 'account_code'));
                                $accounts_with_transactions = count(array_unique($account_codes));
                            }
                            echo "$accounts_with_transactions accounts with transactions out of $total_accounts total accounts";
                            ?>
                        </div>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="text-left p-4 text-sm font-medium text-gray-500">Date</th>
                                <th class="text-left p-4 text-sm font-medium text-gray-500">Entry ID</th>
                                <th class="text-left p-4 text-sm font-medium text-gray-500">Account</th>
                                <th class="text-left p-4 text-sm font-medium text-gray-500">Description</th>
                                <th class="text-left p-4 text-sm font-medium text-gray-500">Debit</th>
                                <th class="text-left p-4 text-sm font-medium text-gray-500">Credit</th>
                                <th class="text-left p-4 text-sm font-medium text-gray-500">Running Balance</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($chart_accounts) > 0): ?>
                                <?php 
                                $current_account = '';
                                $account_running_total = 0;
                                ?>
                                
                                <?php foreach ($chart_accounts as $account): ?>
                                    <?php 
                                    $account_transactions = [];
                                    if (!empty($ledger_data)) {
                                        $account_transactions = array_filter($ledger_data, function($t) use ($account) {
                                            return isset($t['account_code']) && $t['account_code'] === $account['account_code'];
                                        });
                                    }
                                    ?>
                                    
                                    <!-- Account Header -->
                                    <tr class="bg-gray-50">
                                        <td colspan="7" class="p-4 font-bold">
                                            <div class="flex justify-between items-center">
                                                <div>
                                                    <?php echo htmlspecialchars($account['account_code'] . ' - ' . $account['account_name']); ?>
                                                    <span class="text-sm font-normal text-gray-600 ml-2">
                                                        (<?php echo htmlspecialchars($account['account_type']); ?>)
                                                    </span>
                                                </div>
                                                <div class="text-sm font-normal text-gray-600">
                                                    <?php echo count($account_transactions); ?> transaction(s)
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                    
                                    <?php if (count($account_transactions) > 0): ?>
                                        <?php 
                                        $account_running_total = 0;
                                        $account_debit_total = 0;
                                        $account_credit_total = 0;
                                        ?>
                                        
                                        <?php foreach ($account_transactions as $index => $transaction): ?>
                                            <?php 
                                            $debit = isset($transaction['debit']) ? (float)$transaction['debit'] : 0;
                                            $credit = isset($transaction['credit']) ? (float)$transaction['credit'] : 0;
                                            $account_running_total += $debit - $credit;
                                            $account_debit_total += $debit;
                                            $account_credit_total += $credit;
                                            ?>
                                            <tr class="transaction-row">
                                                <td class="p-4 text-gray-600"><?php echo isset($transaction['entry_date']) ? htmlspecialchars($transaction['entry_date']) : ''; ?></td>
                                                <td class="p-4 font-mono text-gray-800"><?php echo isset($transaction['entry_id']) ? htmlspecialchars($transaction['entry_id']) : ''; ?></td>
                                                <td class="p-4 font-mono text-gray-800"><?php echo isset($transaction['account_code']) ? htmlspecialchars($transaction['account_code']) : ''; ?></td>
                                                <td class="p-4 text-gray-600 max-w-xs"><?php echo isset($transaction['description']) ? htmlspecialchars($transaction['description']) : ''; ?></td>
                                                <td class="p-4 debit-amount">
                                                    <?php if ($debit > 0): ?>
                                                    <span class="amount-value hidden-amount" 
                                                          data-value="₱<?php echo number_format($debit, 2); ?>">
                                                        ••••••••
                                                    </span>
                                                    <?php else: ?>
                                                    <span class="text-gray-400">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="p-4 credit-amount">
                                                    <?php if ($credit > 0): ?>
                                                    <span class="amount-value hidden-amount" 
                                                          data-value="₱<?php echo number_format($credit, 2); ?>">
                                                        ••••••••
                                                    </span>
                                                    <?php else: ?>
                                                    <span class="text-gray-400">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="p-4 running-balance <?php echo $account_running_total >= 0 ? 'balance-positive' : 'balance-negative'; ?>">
                                                    <span class="amount-value hidden-amount" 
                                                          data-value="₱<?php echo number_format($account_running_total, 2); ?>">
                                                        ••••••••
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                        
                                        <!-- Account Total -->
                                        <tr class="bg-gray-50">
                                            <td colspan="4" class="p-4 font-bold text-right">Account Total for <?php echo htmlspecialchars($account['account_code']); ?>:</td>
                                            <td class="p-4 debit-amount font-bold">
                                                <span class="amount-value hidden-amount" 
                                                      data-value="₱<?php echo number_format($account_debit_total, 2); ?>">
                                                    ••••••••
                                                </span>
                                            </td>
                                            <td class="p-4 credit-amount font-bold">
                                                <span class="amount-value hidden-amount" 
                                                      data-value="₱<?php echo number_format($account_credit_total, 2); ?>">
                                                    ••••••••
                                                </span>
                                            </td>
                                            <td class="p-4 running-balance font-bold <?php echo $account_running_total >= 0 ? 'balance-positive' : 'balance-negative'; ?>">
                                                <span class="amount-value hidden-amount" 
                                                      data-value="₱<?php echo number_format($account_running_total, 2); ?>">
                                                    ••••••••
                                                </span>
                                            </td>
                                        </tr>
                                        
                                    <?php else: ?>
                                        <!-- No Transactions Message -->
                                        <tr class="transaction-row">
                                            <td colspan="7" class="text-center p-6 text-gray-500">
                                                <div class="flex flex-col items-center">
                                                    <i class='bx bx-file-blank text-4xl text-gray-300 mb-2'></i>
                                                    <p class="font-medium">No transactions found</p>
                                                    <p class="text-sm">This account has no transactions in the selected period</p>
                                                </div>
                                            </td>
                                        </tr>
                                        
                                        <!-- Empty Account Total -->
                                        <tr class="bg-gray-50">
                                            <td colspan="4" class="p-4 font-bold text-right">Account Total for <?php echo htmlspecialchars($account['account_code']); ?>:</td>
                                            <td class="p-4 debit-amount font-bold">
                                                <span class="amount-value hidden-amount" data-value="₱0.00">
                                                    ••••••••
                                                </span>
                                            </td>
                                            <td class="p-4 credit-amount font-bold">
                                                <span class="amount-value hidden-amount" data-value="₱0.00">
                                                    ••••••••
                                                </span>
                                            </td>
                                            <td class="p-4 running-balance font-bold balance-positive">
                                                <span class="amount-value hidden-amount" data-value="₱0.00">
                                                    ••••••••
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                    
                                    <!-- Spacer between accounts -->
                                    <tr>
                                        <td colspan="7" class="py-2 bg-white"></td>
                                    </tr>
                                    
                                <?php endforeach; ?>
                                
                            <?php else: ?>
                                <tr class="transaction-row">
                                    <td colspan="7" class="text-center p-8 text-gray-500">
                                        <div class="flex flex-col items-center">
                                            <i class='bx bx-wallet-alt text-5xl text-gray-300 mb-4'></i>
                                            <p class="text-lg font-medium mb-2">No Accounts Found</p>
                                            <p class="text-sm">No chart of accounts have been created yet.</p>
                                            <a href="chart_of_accounts.php" class="px-4 py-2 bg-brand-primary text-white rounded-lg hover:bg-brand-primary-hover transition mt-4 inline-flex items-center gap-2">
                                                <i class='bx bx-plus'></i>Create Your First Account
                                            </a>
                                        </div>
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

    <script>
    // SINGLE VISIBILITY STATE FOR ALL AMOUNTS IN LEDGER TABLE PAGE
    let allAmountsVisible = false;

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

        // SINGLE VISIBILITY TOGGLE FOR ALL AMOUNTS
        const amountVisibilityToggle = document.getElementById('amount-visibility-toggle');
        
        function toggleAllAmounts() {
            allAmountsVisible = !allAmountsVisible;
            
            // Toggle ALL amount values on the page (both summary and detailed)
            const allAmountElements = document.querySelectorAll('.amount-value');
            allAmountElements.forEach(span => {
                if (allAmountsVisible) {
                    const actualAmount = span.getAttribute('data-value');
                    span.textContent = actualAmount;
                    span.classList.remove('hidden-amount');
                } else {
                    span.textContent = '••••••••';
                    span.classList.add('hidden-amount');
                }
            });
            
            // Update toggle icon in header
            const icon = amountVisibilityToggle.querySelector('i');
            if (allAmountsVisible) {
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
                amountVisibilityToggle.setAttribute('title', 'Hide Amounts');
            } else {
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
                amountVisibilityToggle.setAttribute('title', 'Show Amounts');
            }
        }
        
        // Initialize all amounts as hidden
        function initializeAmountVisibility() {
            const allAmountElements = document.querySelectorAll('.amount-value');
            allAmountElements.forEach(span => {
                span.textContent = '••••••••';
                span.classList.add('hidden-amount');
            });
        }

        // Add click event to the single toggle button
        if (amountVisibilityToggle) {
            amountVisibilityToggle.addEventListener('click', toggleAllAmounts);
        }

        // Export functionality
        const exportBtn = document.getElementById('export-summary-btn');
        if (exportBtn) {
            exportBtn.addEventListener('click', function() {
                // Show loading state
                const originalText = this.innerHTML;
                this.innerHTML = '<div class="spinner"></div>Exporting...';
                this.disabled = true;
                
                // Temporarily show all amounts for export
                const allAmounts = document.querySelectorAll('.amount-value');
                const originalStates = [];
                allAmounts.forEach(span => {
                    originalStates.push({
                        element: span,
                        wasHidden: span.classList.contains('hidden-amount')
                    });
                    if (span.classList.contains('hidden-amount')) {
                        const actualAmount = span.getAttribute('data-value');
                        span.textContent = actualAmount;
                        span.classList.remove('hidden-amount');
                    }
                });
                
                // Simulate export process
                setTimeout(() => {
                    // Create CSV content
                    let csvContent = "Ledger Report\n\n";
                    csvContent += "Account Summary\n";
                    csvContent += "Account Code,Account Name,Account Type,Total Debit,Total Credit,Net Balance\n";
                    
                    // Add account summary data
                    <?php foreach ($account_summary as $account): ?>
                    csvContent += "<?php echo $account['account_code']; ?>,<?php echo addslashes($account['account_name']); ?>,<?php echo $account['account_type']; ?>,<?php echo $account['total_debit']; ?>,<?php echo $account['total_credit']; ?>,<?php echo $account['net_balance']; ?>\n";
                    <?php endforeach; ?>
                    
                    csvContent += "\nDetailed Transactions\n";
                    csvContent += "Date,Entry ID,Account,Description,Debit,Credit,Running Balance\n";
                    
                    // Add transaction data
                    <?php 
                    $current_account = '';
                    $account_running_total = 0;
                    foreach ($ledger_data as $index => $transaction): 
                        if ($current_account !== $transaction['account_code']) {
                            $current_account = $transaction['account_code'];
                            $account_running_total = 0;
                        }
                        $account_running_total += (float)$transaction['debit'] - (float)$transaction['credit'];
                    ?>
                    csvContent += "<?php echo $transaction['entry_date']; ?>,<?php echo $transaction['entry_id']; ?>,<?php echo $transaction['account_code']; ?>,<?php echo addslashes($transaction['description']); ?>,<?php echo $transaction['debit']; ?>,<?php echo $transaction['credit']; ?>,<?php echo $account_running_total; ?>\n";
                    <?php endforeach; ?>
                    
                    // Create and download CSV file
                    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
                    const link = document.createElement('a');
                    const url = URL.createObjectURL(blob);
                    link.setAttribute('href', url);
                    link.setAttribute('download', 'ledger_report_<?php echo date('Y-m-d'); ?>.csv');
                    link.style.visibility = 'hidden';
                    document.body.appendChild(link);
                    link.click();
                    document.body.removeChild(link);
                    
                    // Restore original amount visibility states
                    originalStates.forEach(state => {
                        if (state.wasHidden) {
                            state.element.textContent = '••••••••';
                            state.element.classList.add('hidden-amount');
                        }
                    });
                    
                    // Restore button state
                    this.innerHTML = originalText;
                    this.disabled = false;
                    
                    // Show success message
                    alert('Ledger report exported successfully!');
                }, 1000);
            });
        }

        // Auto-apply date filters to current month if not set
        const startDateInput = document.querySelector('input[name="start_date"]');
        const endDateInput = document.querySelector('input[name="end_date"]');
        
        if (startDateInput && !startDateInput.value) {
            const firstDay = new Date();
            firstDay.setDate(1);
            startDateInput.value = firstDay.toISOString().split('T')[0];
        }
        
        if (endDateInput && !endDateInput.value) {
            const lastDay = new Date();
            lastDay.setMonth(lastDay.getMonth() + 1);
            lastDay.setDate(0);
            endDateInput.value = lastDay.toISOString().split('T')[0];
        }

        // Add print styles
        const style = document.createElement('style');
        style.textContent = `
            @media print {
                #sidebar, #mobile-menu-btn, #profile-btn, #notification-btn, #amount-visibility-toggle, #real-time-clock,
                .export-btn, .print-btn, .bg-gray-bg, .bg-brand-background-main {
                    display: none !important;
                }
                #main-content {
                    margin-left: 0 !important;
                    width: 100% !important;
                }
                .rounded-2xl, .rounded-xl {
                    border-radius: 0 !important;
                    border: 1px solid #e5e7eb !important;
                }
                .shadow-sm {
                    box-shadow: none !important;
                }
                body {
                    background-color: white !important;
                }
                table {
                    break-inside: avoid;
                }
                /* Always show amounts when printing */
                .hidden-amount {
                    display: inline !important;
                }
                .amount-value.hidden-amount {
                    content: attr(data-value) !important;
                    display: inline !important;
                    visibility: visible !important;
                }
                /* Show actual amounts when printing */
                .hidden-amount::before {
                    content: attr(data-value) !important;
                    visibility: visible !important;
                }
            }
        `;
        document.head.appendChild(style);
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