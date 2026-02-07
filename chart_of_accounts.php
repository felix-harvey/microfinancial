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

// Mark notification as read - WITH CSRF VALIDATION
if (isset($_POST['action']) && $_POST['action'] === 'mark_notification_read' && isset($_POST['notification_id'])) {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        http_response_code(403);
        exit('Invalid CSRF token');
    }
    
    $notification_id = (int)$_POST['notification_id'];
    $stmt = $db->prepare("UPDATE user_notifications SET is_read = 1 WHERE id = ?");
    $stmt->execute([$notification_id]);
    exit;
}

// Mark all notifications as read - WITH CSRF VALIDATION
if (isset($_POST['action']) && $_POST['action'] === 'mark_all_read') {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        http_response_code(403);
        exit('Invalid CSRF token');
    }
    
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
        if (isset($_POST['action'])) {
            try {
                switch ($_POST['action']) {
                    case 'add_account':
    $account_code = trim($_POST['account_code'] ?? '');
    $account_name = trim($_POST['account_name'] ?? '');
    $account_type = $_POST['account_type'] ?? '';
    $balance = floatval($_POST['balance'] ?? 0);
    $status = $_POST['status'] ?? 'Active';
    
    // Validation
    if (empty($account_code) || empty($account_name) || empty($account_type)) {
        $error_message = "Please fill in all required fields.";
    } elseif (!preg_match('/^[A-Z0-9\-_]+$/', $account_code)) {
        $error_message = "Account code can only contain uppercase letters, numbers, hyphens, and underscores.";
    } else {
        // Check for duplicate account code
        $check_stmt = $db->prepare("SELECT COUNT(*) FROM chart_of_accounts WHERE account_code = ?");
        $check_stmt->execute([$account_code]);
        if ($check_stmt->fetchColumn() > 0) {
            $error_message = "Account code already exists. Please use a unique code.";
        } else {
            $stmt = $db->prepare("INSERT INTO chart_of_accounts (account_code, account_name, account_type, balance, status) 
                                 VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$account_code, $account_name, $account_type, $balance, $status]);
            
            $success_message = "Account added successfully!";
            
            // Create notification for new account
            try {
                $notification_msg = "New account created: " . $account_name . " (" . $account_code . ")";
                $notif_stmt = $db->prepare("INSERT INTO user_notifications (user_id, title, message, notification_type) VALUES (?, 'New Account Created', ?, 'success')");
                $notif_stmt->execute([$user_id, $notification_msg]);
            } catch (Exception $e) {
                error_log("Notification creation failed: " . $e->getMessage());
            }
        }
    }
    break;
                        
                    case 'edit_account':
    $account_id = (int)($_POST['account_id'] ?? 0);
    $account_code = trim($_POST['account_code'] ?? '');
    $account_name = trim($_POST['account_name'] ?? '');
    $account_type = $_POST['account_type'] ?? '';
    $balance = floatval($_POST['balance'] ?? 0);
    $status = $_POST['status'] ?? 'Active';
    
    // Validation
    if ($account_id <= 0) {
        $error_message = "Invalid account ID.";
    } elseif (empty($account_code) || empty($account_name) || empty($account_type)) {
        $error_message = "Please fill in all required fields.";
    } elseif (!preg_match('/^[A-Z0-9\-_]+$/', $account_code)) {
        $error_message = "Account code can only contain uppercase letters, numbers, hyphens, and underscores.";
    } else {
        // Check for duplicate account code (excluding current account)
        $check_stmt = $db->prepare("SELECT COUNT(*) FROM chart_of_accounts WHERE account_code = ? AND id != ?");
        $check_stmt->execute([$account_code, $account_id]);
        if ($check_stmt->fetchColumn() > 0) {
            $error_message = "Account code already exists. Please use a unique code.";
        } else {
            $stmt = $db->prepare("UPDATE chart_of_accounts 
                                 SET account_code = ?, account_name = ?, account_type = ?, balance = ?, status = ? 
                                 WHERE id = ?");
            $stmt->execute([$account_code, $account_name, $account_type, $balance, $status, $account_id]);
            
            $success_message = "Account updated successfully!";
            
            // Create notification for account update
            try {
                $notification_msg = "Account updated: " . $account_name . " (" . $account_code . ")";
                $notif_stmt = $db->prepare("INSERT INTO user_notifications (user_id, title, message, notification_type) VALUES (?, 'Account Updated', ?, 'info')");
                $notif_stmt->execute([$user_id, $notification_msg]);
            } catch (Exception $e) {
                error_log("Notification creation failed: " . $e->getMessage());
            }
        }
    }
    break;
                        
                    case 'delete_account':
    $account_id = (int)($_POST['account_id'] ?? 0);
    if ($account_id > 0) {
        // Get account details for notification
        $account_stmt = $db->prepare("SELECT account_code, account_name FROM chart_of_accounts WHERE id = ?");
        $account_stmt->execute([$account_id]);
        $account = $account_stmt->fetch();
        
        // Check if account has transactions
        $check_stmt = $db->prepare("SELECT COUNT(*) FROM journal_entry_lines WHERE account_id = ?");
        $check_stmt->execute([$account_id]);
        $transaction_count = $check_stmt->fetchColumn();
        
        if ($transaction_count > 0) {
            $error_message = "Cannot delete account that has transactions. Deactivate it instead.";
        } else {
            $stmt = $db->prepare("DELETE FROM chart_of_accounts WHERE id = ?");
            $stmt->execute([$account_id]);
            
            $success_message = "Account deleted successfully!";
            
            // Create notification for account deletion
            try {
                if ($account) {
                    $notification_msg = "Account deleted: " . $account['account_name'] . " (" . $account['account_code'] . ")";
                    $notif_stmt = $db->prepare("INSERT INTO user_notifications (user_id, title, message, notification_type) VALUES (?, 'Account Deleted', ?, 'warning')");
                    $notif_stmt->execute([$user_id, $notification_msg]);
                }
            } catch (Exception $e) {
                error_log("Notification creation failed: " . $e->getMessage());
            }
        }
    }
    break;
                        
                    case 'toggle_status':
                        $account_id = (int)($_POST['account_id'] ?? 0);
                        if ($account_id > 0) {
                            // Get current status and account details
                            $stmt = $db->prepare("SELECT status, account_code, account_name FROM chart_of_accounts WHERE id = ?");
                            $stmt->execute([$account_id]);
                            $account_data = $stmt->fetch();
                            $current_status = $account_data['status'];
                            
                            $new_status = $current_status === 'Active' ? 'Inactive' : 'Active';
                            
                            $stmt = $db->prepare("UPDATE chart_of_accounts SET status = ? WHERE id = ?");
                            $stmt->execute([$new_status, $account_id]);
                            
                            // Create notification for status change
                            $notification_msg = "Account " . $account_data['account_name'] . " (" . $account_data['account_code'] . ") status changed to " . $new_status;
                            $notif_stmt = $db->prepare("INSERT INTO user_notifications (user_id, title, message, notification_type) VALUES (?, 'Account Status Changed', ?, 'info')");
                            $notif_stmt->execute([$user_id, $notification_msg]);
                            
                            $success_message = "Account status updated successfully!";
                        }
                        break;
                        
                    case 'refresh_balances':
    $db->beginTransaction();
    
    // Reset all balances to zero
    $reset_stmt = $db->prepare("UPDATE chart_of_accounts SET balance = 0");
    $reset_stmt->execute();
    
    // Calculate balances from journal entries
    $balance_stmt = $db->prepare("
    UPDATE chart_of_accounts coa
    SET balance = (
        SELECT COALESCE(SUM(
            CASE 
                WHEN coa.account_type IN ('Asset', 'Expense') THEN jel.debit - jel.credit
                ELSE jel.credit - jel.debit 
            END
        ), 0)
        FROM journal_entry_lines jel
        JOIN journal_entries je ON jel.journal_entry_id = je.id
        WHERE jel.account_id = coa.id AND je.status = 'Posted'
    )
");
    $balance_stmt->execute();
    
    $db->commit();
    
    // Create notification for balance refresh
    $notif_stmt = $db->prepare("INSERT INTO user_notifications (user_id, title, message, notification_type) VALUES (?, 'Balances Refreshed', 'All account balances have been updated from transactions', 'success')");
    $notif_stmt->execute([$user_id]);
    
    $success_message = "Account balances refreshed successfully!";
    break;
                }
            } catch (PDOException $e) {
                if (isset($db) && $db->inTransaction()) {
                    $db->rollBack();
                }
                error_log("Database error: " . $e->getMessage());
                $error_message = "A database error occurred. Please try again.";
            } catch (Exception $e) {
                if (isset($db) && $db->inTransaction()) {
                    $db->rollBack();
                }
                error_log("General error: " . $e->getMessage());
                $error_message = "An error occurred. Please try again.";
            }
        }
    }
}

// Handle export requests
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=chart_of_accounts_' . date('Y-m-d') . '.csv');
    
    $output = fopen('php://output', 'w');
    
    // Add headers
    fputcsv($output, ['Chart of Accounts - Generated on ' . date('Y-m-d H:i:s')]);
    fputcsv($output, []); // Empty row
    
    // Column headers
    fputcsv($output, ['Account Code', 'Account Name', 'Account Type', 'Balance', 'Status']);
    
    // Data rows
    $accounts = getChartOfAccounts($db);
    foreach ($accounts as $account) {
        fputcsv($output, [
            $account['account_code'],
            $account['account_name'],
            $account['account_type'],
            number_format((float)$account['balance'], 2),
            $account['status']
        ]);
    }
    
    fclose($output);
    exit;
}

// Get chart of accounts data with transaction counts
function getChartOfAccounts(PDO $db): array {
    $sql = "SELECT coa.*, 
                   COUNT(jel.id) as transaction_count,
                   COALESCE(SUM(jel.debit), 0) as total_debit,
                   COALESCE(SUM(jel.credit), 0) as total_credit
            FROM chart_of_accounts coa
            LEFT JOIN journal_entry_lines jel ON coa.id = jel.account_id
            LEFT JOIN journal_entries je ON jel.journal_entry_id = je.id AND je.status = 'Posted'
            GROUP BY coa.id
            ORDER BY coa.account_type, coa.account_code";
    return $db->query($sql)->fetchAll();
}

$chart_of_accounts = getChartOfAccounts($db);

// Get account type totals
function getAccountTypeTotals(PDO $db): array {
    $sql = "SELECT account_type, 
                   COUNT(*) as count,
                   COALESCE(SUM(balance), 0) as total_balance
            FROM chart_of_accounts 
            WHERE status = 'Active'
            GROUP BY account_type 
            ORDER BY account_type";
    return $db->query($sql)->fetchAll();
}

$account_totals = getAccountTypeTotals($db);

// Get account details for editing
$edit_account = null;
if (isset($_GET['edit_account']) && is_numeric($_GET['edit_account'])) {
    $account_id = (int)$_GET['edit_account'];
    $stmt = $db->prepare("SELECT * FROM chart_of_accounts WHERE id = ?");
    $stmt->execute([$account_id]);
    $edit_account = $stmt->fetch();
}

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
    <title>Chart of Accounts - Financial Dashboard</title>

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
            max-width: 500px;
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
        
        .account-type-asset {
            border-left: 4px solid #3B82F6;
        }
        
        .account-type-liability {
            border-left: 4px solid #EF4444;
        }
        
        .account-type-equity {
            border-left: 4px solid #8B5CF6;
        }
        
        .account-type-revenue {
            border-left: 4px solid #10B981;
        }
        
        .account-type-expense {
            border-left: 4px solid #F59E0B;
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

        .transaction-badge {
            background-color: #E5E7EB;
            color: #6B7280;
            font-size: 0.7rem;
            padding: 0.2rem 0.5rem;
            border-radius: 0.375rem;
            margin-left: 0.5rem;
        }

        @media print {
            body * {
                visibility: hidden;
            }
            
            #main-content, 
            #main-content * {
                visibility: visible;
            }
            
            #main-content {
                position: absolute !important;
                left: 0 !important;
                top: 0 !important;
                width: 100% !important;
                margin: 0 !important;
                padding: 0 !important;
            }
            
            .no-print,
            #sidebar,
            #mobile-menu-btn,
            .bg-primary-green, 
            button,
            .action-btn,
            .notification-container,
            #profile-btn {
                display: none !important;
            }
            
            table {
                width: 100% !important;
                border-collapse: collapse !important;
            }
            
            th, td {
                border: 1px solid #000 !important;
                padding: 8px !important;
            }
            
            th {
                background-color: #f0f0f0 !important;
                color: #000 !important;
            }
            
            .hidden-amount {
                display: none !important;
            }
        }

        /* FIXED: Stat card adjustments for better fitting */
        .stat-amount {
            font-size: 1.25rem !important;
            line-height: 1.2 !important;
            letter-spacing: -0.025em !important;
            font-weight: 700 !important;
        }
        
        .stat-card .text-2xl {
            font-size: 1.25rem !important;
        }
        
        .stat-card-compact {
            padding: 1rem !important;
        }
        
        .stat-icon-sm {
            width: 2.5rem !important;
            height: 2.5rem !important;
        }
        
        .stat-icon-sm i {
            font-size: 1.25rem !important;
        }
        
        .stat-count-sm {
            font-size: 0.75rem !important;
            margin-top: 0.25rem !important;
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
                            <a href="chart_of_accounts.php" class="block px-3 py-1.5 rounded-lg text-xs bg-emerald-50 text-brand-primary font-medium border border-emerald-100 hover:bg-emerald-100 hover:border-emerald-200 transition-all duration-200 hover:translate-x-1">
                                <span class="flex items-center justify-between">
                                    Chart of Accounts
                                    <span class="inline-flex w-1.5 h-1.5 rounded-full bg-emerald-400"></span>
                                </span>
                            </a>
                            <a href="journal_entry.php" class="block px-3 py-1.5 rounded-lg text-xs text-gray-600 hover:bg-green-50 hover:text-brand-primary transition-all duration-200 hover:translate-x-1">
                                Journal Entry
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
                        Chart of Accounts
                    </h1>
                    <p class="text-xs text-gray-500">
                        Manage your accounting chart of accounts
                    </p>
                </div>
            </div>

            <div class="flex items-center gap-3 sm:gap-5">
                <!-- Real-time Clock -->
                <span id="real-time-clock"
                    class="text-xs font-bold text-gray-700 bg-gray-50 px-3 py-2 rounded-lg border border-gray-200">
                    --:--:--
                </span>

                <!-- Visibility Toggle for STAT CARDS (header) - KEEP THIS -->
                <button id="visibility-toggle" class="w-10 h-10 rounded-xl hover:bg-gray-100 active:bg-gray-200 transition flex items-center justify-center relative"
                        title="Toggle Stat Card Amounts">
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

                        <!-- Account Type Summary - COMPACT VERSION -->
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-lg font-bold text-gray-800">Account Type Summary</h2>
                <!-- TANGGALIN ANG BUONG "Show amounts" DIV -->
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4 mb-8">
                <?php 
                $bgColors = [
                    'Asset' => 'bg-blue-100',
                    'Liability' => 'bg-red-100',
                    'Equity' => 'bg-purple-100',
                    'Revenue' => 'bg-green-100',
                    'Expense' => 'bg-yellow-100'
                ];
                $textColors = [
                    'Asset' => 'text-blue-600',
                    'Liability' => 'text-red-600',
                    'Equity' => 'text-purple-600',
                    'Revenue' => 'text-green-600',
                    'Expense' => 'text-yellow-600'
                ];
                
                foreach ($account_totals as $type): 
                ?>
                <div class="stat-card stat-card-compact rounded-xl p-4">
                    <div class="flex items-center gap-3">
                        <div class="p-2.5 rounded-lg stat-icon-sm <?php echo $bgColors[$type['account_type']] ?? 'bg-gray-100'; ?>">
                            <?php if ($type['account_type'] === 'Asset'): ?>
                                <i class='bx bx-wallet <?php echo $textColors[$type['account_type']] ?? 'text-gray-600'; ?> text-lg'></i>
                            <?php elseif ($type['account_type'] === 'Liability'): ?>
                                <i class='bx bx-credit-card <?php echo $textColors[$type['account_type']] ?? 'text-gray-600'; ?> text-lg'></i>
                            <?php elseif ($type['account_type'] === 'Equity'): ?>
                                <i class='bx bx-trending-up <?php echo $textColors[$type['account_type']] ?? 'text-gray-600'; ?> text-lg'></i>
                            <?php elseif ($type['account_type'] === 'Revenue'): ?>
                                <i class='bx bx-money <?php echo $textColors[$type['account_type']] ?? 'text-gray-600'; ?> text-lg'></i>
                            <?php else: ?>
                                <i class='bx bx-receipt <?php echo $textColors[$type['account_type']] ?? 'text-gray-600'; ?> text-lg'></i>
                            <?php endif; ?>
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="flex justify-between items-start">
                                <div class="truncate">
                                    <p class="text-xs text-gray-500 truncate"><?php echo htmlspecialchars($type['account_type']); ?></p>
                                    <p class="stat-amount text-gray-800 stat-value truncate stat-amount-value" 
                                       data-value="₱<?php echo number_format((float)$type['total_balance'], 2); ?>"
                                       data-stat="<?php echo strtolower($type['account_type']); ?>">
                                        ********
                                    </p>
                                </div>
                            </div>
                            <div class="text-xs text-gray-400 mt-1 stat-count-sm"><?php echo $type['count']; ?> accounts</div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Search Section -->
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 mb-6">
                <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                    <div class="flex-1">
                        <div class="relative">
                            <input type="text" id="search-accounts" placeholder="Search accounts by code, name, or type..." 
                                   class="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-brand-primary focus:border-transparent">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                <i class='bx bx-search text-gray-400'></i>
                            </div>
                        </div>
                    </div>
                    <div class="flex space-x-2">
                        <button id="clear-search" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition whitespace-nowrap">
                            <i class='bx bx-reset mr-2'></i>Clear
                        </button>
                        <div class="relative">
                            <select id="filter-type" class="form-input pr-8">
                                <option value="">All Types</option>
                                <option value="Asset">Asset</option>
                                <option value="Liability">Liability</option>
                                <option value="Equity">Equity</option>
                                <option value="Revenue">Revenue</option>
                                <option value="Expense">Expense</option>
                            </select>
                        </div>
                        <div class="relative">
                            <select id="filter-status" class="form-input pr-8">
                                <option value="">All Status</option>
                                <option value="Active">Active</option>
                                <option value="Inactive">Inactive</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div id="search-results-info" class="mt-3 text-sm text-gray-600 hidden">
                    <span id="results-count">0</span> accounts found
                </div>
            </div>

                        <!-- Chart of Accounts Content -->
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                <div class="p-6 border-b border-gray-100">
                    <div class="flex justify-between items-center">
                        <div class="flex items-center gap-3">
                            <h3 class="text-lg font-bold text-gray-800">Chart of Accounts</h3>
                            <!-- TANGGALIN ANG "Table Amounts" TEXT, ICON ONLY -->
                            <button id="table-amounts-toggle" class="text-gray-500 hover:text-brand-primary transition" title="Toggle Table Amounts">
                                <i class="fa-solid fa-eye-slash text-sm"></i>
                            </button>
                        </div>
                        <div class="flex space-x-3">
                            <button class="px-4 py-2 bg-brand-primary text-white rounded-lg hover:bg-brand-primary-hover transition flex items-center gap-2" onclick="document.getElementById('add-account-modal').style.display='block'">
                                <i class='bx bx-plus'></i> Add Account
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
                                <th class="text-left p-4 text-sm font-medium text-gray-500">Balance</th>
                                <th class="text-left p-4 text-sm font-medium text-gray-500">Status</th>
                                <th class="text-left p-4 text-sm font-medium text-gray-500">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($chart_of_accounts) > 0): ?>
                                <?php 
                                $current_type = '';
                                foreach ($chart_of_accounts as $account): 
                                    if ($current_type !== $account['account_type']) {
                                        $current_type = $account['account_type'];
                                ?>
                                <tr class="bg-gray-50">
                                    <td colspan="6" class="font-semibold text-gray-700 p-4">
                                        <?php echo htmlspecialchars($account['account_type']); ?> ACCOUNTS
                                    </td>
                                </tr>
                                <?php } ?>
                                <tr class="transaction-row account-type-<?php echo strtolower($account['account_type']); ?>">
                                    <td class="p-4">
                                        <div class="font-mono font-medium text-gray-800">
                                            <?php echo htmlspecialchars($account['account_code']); ?>
                                            <?php if ($account['transaction_count'] > 0): ?>
                                                <span class="transaction-badge" title="This account has transactions">
                                                    <?php echo $account['transaction_count']; ?> trans
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
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
                                    <td class="p-4 <?php echo (float)$account['balance'] >= 0 ? 'text-green-600' : 'text-red-600'; ?>">
                                        <span class="table-amount-value hidden-amount font-semibold" 
                                              data-value="₱<?php echo number_format((float)$account['balance'], 2); ?>">
                                            ********
                                        </span>
                                    </td>
                                    <td class="p-4">
                                        <span class="status-badge <?php echo $account['status'] === 'Active' ? 'status-approved' : 'status-rejected'; ?>">
                                            <?php echo htmlspecialchars($account['status']); ?>
                                        </span>
                                    </td>
                                    <td class="p-4">
    <div class="flex flex-wrap gap-2">
        <form method="POST" style="display: inline;">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            <input type="hidden" name="action" value="toggle_status">
            <input type="hidden" name="account_id" value="<?php echo $account['id']; ?>">
            <?php if ($account['status'] === 'Active'): ?>
            <button type="submit" class="px-3 py-1 text-sm bg-yellow-100 text-yellow-800 rounded-lg hover:bg-yellow-200 transition flex items-center gap-1">
                <i class='bx bx-power-off text-xs'></i> Deactivate
            </button>
            <?php else: ?>
            <button type="submit" class="px-3 py-1 text-sm bg-green-100 text-green-800 rounded-lg hover:bg-green-200 transition flex items-center gap-1">
                <i class='bx bx-check text-xs'></i> Activate
            </button>
            <?php endif; ?>
        </form>
        <!-- Edit button removed -->
    </div>
</td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="text-center p-8 text-gray-500">
                                        <i class='bx bx-folder-open text-3xl mb-2 text-gray-300'></i>
                                        <div>No accounts found in the chart of accounts.</div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mt-8">
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 text-center cursor-pointer hover:bg-gray-50 transition-colors duration-200" id="export-chart-btn">
                    <i class='bx bx-export text-3xl text-brand-primary mb-3'></i>
                    <h3 class="font-medium mb-2">Export Chart</h3>
                    <p class="text-sm text-gray-500">Export accounts to Excel or PDF</p>
                </div>
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 text-center cursor-pointer hover:bg-gray-50 transition-colors duration-200" id="print-report-btn">
                    <i class='bx bx-printer text-3xl text-brand-primary mb-3'></i>
                    <h3 class="font-medium mb-2">Print Report</h3>
                    <p class="text-sm text-gray-500">Print chart of accounts</p>
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

    <!-- Add Account Modal -->
    <div id="add-account-modal" class="modal">
        <div class="modal-content">
            <div class="flex justify-between items-center mb-6">
                <h2 class="text-xl font-bold text-gray-800">Add New Account</h2>
                <button class="close-modal text-gray-400 hover:text-gray-600 text-2xl">&times;</button>
            </div>
            <form id="account-form" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="action" value="add_account">
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2 required-field">Account Code</label>
                        <input type="text" name="account_code" class="form-input" placeholder="e.g., 1001" required 
                               pattern="[A-Z0-9\-_]+" title="Uppercase letters, numbers, hyphens, and underscores only">
                        <div class="validation-error" id="account-code-error"></div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2 required-field">Account Name</label>
                        <input type="text" name="account_name" class="form-input" placeholder="e.g., Cash on Hand" required>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2 required-field">Account Type</label>
                        <select name="account_type" class="form-input" required>
                            <option value="">Select Account Type</option>
                            <option value="Asset">Asset</option>
                            <option value="Liability">Liability</option>
                            <option value="Equity">Equity</option>
                            <option value="Revenue">Revenue</option>
                            <option value="Expense">Expense</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Opening Balance</label>
                        <input type="number" name="balance" class="form-input" placeholder="0.00" step="0.01" value="0.00" min="0">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Status</label>
                        <select name="status" class="form-input" required>
                            <option value="Active">Active</option>
                            <option value="Inactive">Inactive</option>
                        </select>
                    </div>
                    <div class="flex gap-3 pt-4">
                        <button type="button" class="flex-1 px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition close-modal">Cancel</button>
                        <button type="submit" class="flex-1 px-4 py-2 bg-brand-primary text-white rounded-lg hover:bg-brand-primary-hover transition">Add Account</button>
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
        
        // Initialize search functionality
        initializeSearch();

        // SEPARATE VISIBILITY TOGGLES FOR STAT CARDS AND TABLE
        
        // 1. Stat Cards Visibility (sa taas - Asset, Liability, etc.)
        let statCardsVisible = false;
        const statCardsToggle = document.getElementById('stat-cards-toggle');
        const visibilityToggle = document.getElementById('visibility-toggle'); // yung nasa header
        
        function toggleStatCards() {
            statCardsVisible = !statCardsVisible;
            
            // Toggle stat card amounts
            const statValues = document.querySelectorAll('.stat-amount-value');
            statValues.forEach(span => {
                if (statCardsVisible) {
                    const actualAmount = span.getAttribute('data-value');
                    span.textContent = actualAmount;
                    span.classList.remove('hidden-amount');
                } else {
                    span.textContent = '••••••••';
                    span.classList.add('hidden-amount');
                }
            });
            
            // Update toggle icon
            const icon = statCardsToggle.querySelector('i');
            if (statCardsVisible) {
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
                statCardsToggle.querySelector('span').textContent = 'Stat Cards';
            } else {
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
                statCardsToggle.querySelector('span').textContent = 'Stat Cards';
            }
            
            // Also update header toggle icon
            const headerIcon = visibilityToggle.querySelector('i');
            if (statCardsVisible) {
                headerIcon.classList.remove('fa-eye-slash');
                headerIcon.classList.add('fa-eye');
            } else {
                headerIcon.classList.remove('fa-eye');
                headerIcon.classList.add('fa-eye-slash');
            }
        }
        
        // Initialize stat cards as hidden
        const statValues = document.querySelectorAll('.stat-amount-value');
        statValues.forEach(span => {
            span.textContent = '••••••••';
            span.classList.add('hidden-amount');
        });
        
        if (statCardsToggle) {
            statCardsToggle.addEventListener('click', toggleStatCards);
        }
        
        // Header visibility toggle also controls stat cards
        if (visibilityToggle) {
            visibilityToggle.addEventListener('click', toggleStatCards);
        }
        
        // 2. Table Amounts Visibility (sa baba - account balances sa table)
        let tableAmountsVisible = false;
        const tableAmountsToggle = document.getElementById('table-amounts-toggle');
        
        function toggleTableAmounts() {
            tableAmountsVisible = !tableAmountsVisible;
            
            // Toggle table amount values
            const tableAmounts = document.querySelectorAll('.table-amount-value');
            tableAmounts.forEach(span => {
                if (tableAmountsVisible) {
                    const actualAmount = span.getAttribute('data-value');
                    span.textContent = actualAmount;
                    span.classList.remove('hidden-amount');
                } else {
                    span.textContent = '••••••••';
                    span.classList.add('hidden-amount');
                }
            });
            
            // Update toggle icon
            const icon = tableAmountsToggle.querySelector('i');
            if (tableAmountsVisible) {
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
                tableAmountsToggle.querySelector('span').textContent = 'Table Amounts';
            } else {
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
                tableAmountsToggle.querySelector('span').textContent = 'Table Amounts';
            }
        }
        
        // Initialize table amounts as hidden
        const tableAmounts = document.querySelectorAll('.table-amount-value');
        tableAmounts.forEach(span => {
            span.textContent = '••••••••';
            span.classList.add('hidden-amount');
        });
        
        if (tableAmountsToggle) {
            tableAmountsToggle.addEventListener('click', toggleTableAmounts);
        }

        // Form validation
        function validateAccountForm(form) {
            let isValid = true;
            
            const errorElements = form.querySelectorAll('.validation-error');
            errorElements.forEach(el => el.textContent = '');
            
            const inputs = form.querySelectorAll('input, select');
            inputs.forEach(input => {
                input.classList.remove('error');
            });
            
            const accountCode = form.querySelector('input[name="account_code"]');
            if (accountCode) {
                const codeValue = accountCode.value.trim();
                if (!codeValue) {
                    showError(accountCode, 'Account code is required');
                    isValid = false;
                } else if (!/^[A-Z0-9\-_]+$/.test(codeValue)) {
                    showError(accountCode, 'Only uppercase letters, numbers, hyphens, and underscores allowed');
                    isValid = false;
                }
            }
            
            const accountName = form.querySelector('input[name="account_name"]');
            if (accountName && !accountName.value.trim()) {
                showError(accountName, 'Account name is required');
                isValid = false;
            }
            
            const accountType = form.querySelector('select[name="account_type"]');
            if (accountType && !accountType.value) {
                showError(accountType, 'Account type is required');
                isValid = false;
            }
            
            return isValid;
        }

        function showError(input, message) {
            input.classList.add('error');
            let errorDiv = input.parentNode.querySelector('.validation-error');
            if (!errorDiv) {
                errorDiv = document.createElement('div');
                errorDiv.className = 'validation-error';
                input.parentNode.appendChild(errorDiv);
            }
            errorDiv.textContent = message;
        }

        // Add form validation to account forms
        const accountForm = document.getElementById('account-form');
        const editAccountForm = document.getElementById('edit-account-form');
        
        if (accountForm) {
            accountForm.addEventListener('submit', function(e) {
                if (!validateAccountForm(this)) {
                    e.preventDefault();
                }
            });
        }
        
        if (editAccountForm) {
            editAccountForm.addEventListener('submit', function(e) {
                if (!validateAccountForm(this)) {
                    e.preventDefault();
                }
            });
        }

        // Export functionality
        const exportChartBtn = document.getElementById('export-chart-btn');
        if (exportChartBtn) {
            exportChartBtn.addEventListener('click', function() {
                if (confirm('Export chart of accounts as CSV?')) {
                    window.location.href = '?export=csv';
                }
            });
        }

        // Print functionality
        const printReportBtn = document.getElementById('print-report-btn');
        if (printReportBtn) {
            printReportBtn.addEventListener('click', function() {
                const originalTitle = document.title;
                document.title = 'Chart of Accounts Report - ' + new Date().toLocaleDateString();
                
                // Show all balances before printing (both stat cards and table)
                const statValues = document.querySelectorAll('.stat-amount-value');
                statValues.forEach(span => {
                    const actualAmount = span.getAttribute('data-value');
                    span.textContent = actualAmount;
                    span.classList.remove('hidden-amount');
                });
                
                const tableAmounts = document.querySelectorAll('.table-amount-value');
                tableAmounts.forEach(span => {
                    const actualAmount = span.getAttribute('data-value');
                    span.textContent = actualAmount;
                    span.classList.remove('hidden-amount');
                });
                
                const style = document.createElement('style');
                style.innerHTML = `
                    @media print {
                        body { margin: 0; padding: 20px; }
                        .no-print, #sidebar, #mobile-menu-btn, .bg-primary-green, 
                        button, .action-btn, .notification-container, 
                        #profile-btn, .grid.grid-cols-1.md\\\\:grid-cols-2.gap-6.mt-8 {
                            display: none !important;
                        }
                        #main-content {
                            width: 100% !important;
                            margin: 0 !important;
                            padding: 0 !important;
                        }
                        table { 
                            width: 100% !important; 
                            border-collapse: collapse !important;
                            font-size: 12px !important;
                        }
                        th, td { 
                            border: 1px solid #000 !important; 
                            padding: 6px !important;
                        }
                        th { 
                            background-color: #f5f5f5 !important; 
                            font-weight: bold !important;
                        }
                    }
                `;
                document.head.appendChild(style);
                
                window.print();
                
                // Restore balance visibility state after printing
                setTimeout(() => {
                    document.title = originalTitle;
                    style.remove();
                    
                    // Restore stat cards visibility
                    if (!statCardsVisible) {
                        statValues.forEach(span => {
                            span.textContent = '••••••••';
                            span.classList.add('hidden-amount');
                        });
                    }
                    
                    // Restore table amounts visibility
                    if (!tableAmountsVisible) {
                        tableAmounts.forEach(span => {
                            span.textContent = '••••••••';
                            span.classList.add('hidden-amount');
                        });
                    }
                }, 100);
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
    
    function initializeSearch() {
        const searchInput = document.getElementById('search-accounts');
        const clearSearchBtn = document.getElementById('clear-search');
        const filterType = document.getElementById('filter-type');
        const filterStatus = document.getElementById('filter-status');
        const searchResultsInfo = document.getElementById('search-results-info');
        const resultsCount = document.getElementById('results-count');
        
        if (!searchInput) return;
        
        function performSearch() {
            const searchTerm = searchInput.value.toLowerCase().trim();
            const selectedType = filterType.value;
            const selectedStatus = filterStatus.value;
            
            const tableBody = document.querySelector('tbody');
            const rows = tableBody.querySelectorAll('tr');
            let visibleRows = 0;
            let visibleAccounts = 0;
            
            rows.forEach(row => {
                // Skip account type header rows
                if (row.classList.contains('bg-gray-50')) {
                    let shouldShowHeader = false;
                    const nextRows = [];
                    let nextRow = row.nextElementSibling;
                    
                    // Check if any account in this category matches the filters
                    while (nextRow && !nextRow.classList.contains('bg-gray-50')) {
                        if (filterRow(nextRow, searchTerm, selectedType, selectedStatus)) {
                            shouldShowHeader = true;
                        }
                        nextRows.push(nextRow);
                        nextRow = nextRow.nextElementSibling;
                    }
                    
                    // Show/hide header based on whether any accounts in category are visible
                    row.style.display = shouldShowHeader ? '' : 'none';
                    
                    // Show/hide account rows in this category
                    nextRows.forEach(accRow => {
                        const isVisible = filterRow(accRow, searchTerm, selectedType, selectedStatus);
                        accRow.style.display = isVisible ? '' : 'none';
                        if (isVisible) {
                            visibleRows++;
                            visibleAccounts++;
                        }
                    });
                    
                    return;
                }
                
                // Handle regular account rows (though they should be handled above)
                if (!row.classList.contains('bg-gray-50')) {
                    const isVisible = filterRow(row, searchTerm, selectedType, selectedStatus);
                    row.style.display = isVisible ? '' : 'none';
                    if (isVisible) {
                        visibleRows++;
                        visibleAccounts++;
                    }
                }
            });
            
            // Update results info
            if (searchTerm || selectedType || selectedStatus) {
                searchResultsInfo.classList.remove('hidden');
                resultsCount.textContent = visibleAccounts;
            } else {
                searchResultsInfo.classList.add('hidden');
            }
        }
        
        function filterRow(row, searchTerm, selectedType, selectedStatus) {
            const cells = row.querySelectorAll('td');
            if (cells.length < 5) return false;
            
            const accountCode = cells[0].textContent.toLowerCase();
            const accountName = cells[1].textContent.toLowerCase();
            const accountType = cells[2].textContent.toLowerCase();
            const status = cells[4].textContent.toLowerCase();
            
            // Text search
            const matchesSearch = !searchTerm || 
                                 accountCode.includes(searchTerm) || 
                                 accountName.includes(searchTerm) || 
                                 accountType.includes(searchTerm);
            
            // Type filter
            const matchesType = !selectedType || 
                               cells[2].textContent === selectedType;
            
            // Status filter
            const matchesStatus = !selectedStatus || 
                                 cells[4].textContent === selectedStatus;
            
            return matchesSearch && matchesType && matchesStatus;
        }
        
        // Event listeners
        searchInput.addEventListener('input', performSearch);
        filterType.addEventListener('change', performSearch);
        filterStatus.addEventListener('change', performSearch);
        
        clearSearchBtn.addEventListener('click', function() {
            searchInput.value = '';
            filterType.value = '';
            filterStatus.value = '';
            performSearch();
            searchInput.focus();
        });
        
        // Add keyboard shortcut
        searchInput.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.key === 'k') {
                e.preventDefault();
                this.focus();
            }
        });
        
        // Add global keyboard shortcut
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.key === 'k') {
                e.preventDefault();
                searchInput.focus();
            }
        });
    }

    // Function to open edit modal with account data
    function openEditModal(id, code, name, type, balance, status) {
        document.getElementById('edit_account_id').value = id;
        document.getElementById('edit_account_code').value = code;
        document.getElementById('edit_account_name').value = name;
        document.getElementById('edit_account_type').value = type;
        document.getElementById('edit_balance').value = balance;
        document.getElementById('edit_status').value = status;
        
        document.getElementById('edit-account-modal').style.display = 'block';
    }
    </script>
</body>
</html>