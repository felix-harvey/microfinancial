<?php
declare(strict_types=1);
session_start();

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', "1");

// Check if user is logged in
if (empty($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$user_role = $_SESSION['user_role'] ?? 'user';

// Initialize hide numbers session variable
if (!isset($_SESSION['hide_numbers'])) {
    $_SESSION['hide_numbers'] = false;
}

// Toggle hide numbers state
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_hide_numbers'])) {
    $_SESSION['hide_numbers'] = !$_SESSION['hide_numbers'];
    
    if (isset($_POST['ajax'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'hide_numbers' => $_SESSION['hide_numbers']]);
        exit;
    }
    
    header("Location: " . $_SERVER['HTTP_REFERER']);
    exit;
}

try {
    require_once __DIR__ . '/database.php';
    $database = new Database();
    $db = $database->getConnection();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    
    // Load current user
    $u = $db->prepare("SELECT id, name, username, role FROM users WHERE id = ?");
    $u->execute([$user_id]);
    $user = $u->fetch();
    if (!$user) {
        header("Location: index.php");
        exit;
    }
} catch (Exception $e) {
    error_log("Database error: " . $e->getMessage());
    http_response_code(500);
    echo "Database connection error.";
    exit;
}

// Initialize data arrays
$pending_approvals = [];
$my_approvals = [];
$approval_history = [];

// Handle marking notifications as read
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_notification_read'])) {
    try {
        $notification_id = (int)$_POST['notification_id'];
        
        $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND (user_id = ? OR user_id IS NULL)");
        $stmt->execute([$notification_id, $user_id]);
        
        if (isset($_POST['ajax'])) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true]);
            exit;
        }
        
        $_SESSION['success_message'] = "Notification marked as read!";
        header("Location: " . $_SERVER['HTTP_REFERER']);
        exit;
        
    } catch (Exception $e) {
        error_log("Mark notification read error: " . $e->getMessage());
        if (isset($_POST['ajax'])) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            exit;
        }
        $_SESSION['error_message'] = "Error marking notification as read: " . $e->getMessage();
    }
}

// Handle marking all notifications as read
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_all_notifications_read'])) {
    try {
        $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE (user_id = ? OR user_id IS NULL) AND (is_read = 0 OR is_read IS NULL)");
        $stmt->execute([$user_id]);
        
        if (isset($_POST['ajax'])) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true]);
            exit;
        }
        
        $_SESSION['success_message'] = "All notifications marked as read!";
        header("Location: " . $_SERVER['HTTP_REFERER']);
        exit;
        
    } catch (Exception $e) {
        error_log("Mark all notifications read error: " . $e->getMessage());
        if (isset($_POST['ajax'])) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            exit;
        }
        $_SESSION['error_message'] = "Error marking notifications as read: " . $e->getMessage();
    }
}

// Handle approval actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['approve_proposal']) || isset($_POST['reject_proposal'])) {
            $proposal_id = (int)$_POST['proposal_id'];
            $comments = trim($_POST['comments'] ?? '');
            $action = isset($_POST['approve_proposal']) ? 'Approved' : 'Rejected';
            
            // First, verify the proposal exists and is in pending status
            $verify_stmt = $db->prepare("
                SELECT id, title, submitted_by, status 
                FROM budget_proposals 
                WHERE id = ? AND status = 'Pending'
            ");
            $verify_stmt->execute([$proposal_id]);
            $proposal = $verify_stmt->fetch();
            
            if (!$proposal) {
                throw new Exception("Proposal not found or not in submitted status");
            }
            
            // Record approval/rejection
            $stmt = $db->prepare("
                INSERT INTO workflow_approvals 
                (proposal_id, approver_id, action, comments, step_completed) 
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$proposal_id, $user_id, $action, $comments, 1]); // Set step_completed to 1
            
            // Update proposal status
            $new_status = $action === 'Approved' ? 'Approved' : 'Rejected';
            $date_field = $action === 'Approved' ? 'approved_date' : 'rejected_date';
            
            $update_stmt = $db->prepare("
                UPDATE budget_proposals 
                SET status = ?, $date_field = NOW(), updated_at = NOW()
                WHERE id = ?
            ");
            $update_stmt->execute([$new_status, $proposal_id]);
            
            // Create notification for submitter
            $message = $action === 'Approved' 
                ? "Your budget proposal '{$proposal['title']}' has been approved!" 
                : "Your budget proposal '{$proposal['title']}' has been rejected." . ($comments ? " Comments: $comments" : "");
            
            $notify_stmt = $db->prepare("
                INSERT INTO notifications (user_id, message, type) 
                VALUES (?, ?, ?)
            ");
            $notify_type = $action === 'Approved' ? 'success' : 'warning';
            $notify_stmt->execute([$proposal['submitted_by'], $message, $notify_type]);
            
            $_SESSION['success_message'] = "Proposal " . strtolower($action) . " successfully!";
        }
        
    } catch (Exception $e) {
        error_log("Approval workflow error: " . $e->getMessage());
        $_SESSION['error_message'] = "Error processing request: " . $e->getMessage();
    }
    
    // Redirect to prevent form resubmission
    header("Location: approval_workflow.php");
    exit;
}

// Load data based on user role and permissions
try {
    // Handle proposal filtering
    $filter_proposal_id = $_GET['proposal_id'] ?? null;
    
    // Get proposals pending approval - CORRECTED STATUS AND DATE COLUMN
    $pending_stmt = $db->prepare("
        SELECT 
            bp.*, 
            u.name as submitter_name,
            d.name as department_name,
            bp.total_amount,
            bp.created_at as submitted_date, 
            DATEDIFF(CURDATE(), bp.created_at) as days_pending
        FROM budget_proposals bp
        JOIN users u ON bp.submitted_by = u.id
        JOIN departments d ON bp.department = d.id
        WHERE bp.status = 'Pending' 
        ORDER BY bp.created_at DESC
    ");
    $pending_stmt->execute();
    $pending_approvals = $pending_stmt->fetchAll();
    
    // Apply proposal filter if specified
    if ($filter_proposal_id) {
        $pending_approvals = array_filter($pending_approvals, function($proposal) use ($filter_proposal_id) {
            return $proposal['id'] == $filter_proposal_id;
        });
    }

    // Get approvals made by current user - CORRECTED QUERY (using bp.total_amount directly)
    try {
        $my_approvals_stmt = $db->prepare("
            SELECT 
                bp.id, bp.title, bp.submitted_date, bp.status, bp.total_amount,
                u.name as submitter_name, 
                d.name as department_name,
                wa.action, wa.comments, wa.approved_at
            FROM workflow_approvals wa
            JOIN budget_proposals bp ON wa.proposal_id = bp.id
            JOIN users u ON bp.submitted_by = u.id
            JOIN departments d ON bp.department = d.id
            WHERE wa.approver_id = ?
            ORDER BY wa.approved_at DESC
            LIMIT 20
        ");
        $my_approvals_stmt->execute([$user_id]);
        $my_approvals = $my_approvals_stmt->fetchAll();
    } catch (Exception $e) {
        error_log("My approvals query error: " . $e->getMessage());
        $my_approvals = [];
    }

    // Get approval history - CORRECTED QUERY (using bp.total_amount directly)
    try {
        $history_stmt = $db->prepare("
            SELECT 
                bp.id, bp.title, bp.submitted_date, bp.status, bp.updated_at, bp.total_amount,
                u.name as submitter_name, 
                d.name as department_name
            FROM budget_proposals bp
            JOIN users u ON bp.submitted_by = u.id
            JOIN departments d ON bp.department = d.id
            WHERE bp.status IN ('Approved', 'Rejected')
            AND (bp.submitted_by = ? OR EXISTS (
                SELECT 1 FROM workflow_approvals wa2 
                WHERE wa2.proposal_id = bp.id AND wa2.approver_id = ?
            ))
            ORDER BY bp.updated_at DESC
            LIMIT 50
        ");
        $history_stmt->execute([$user_id, $user_id]);
        $approval_history = $history_stmt->fetchAll();
    } catch (Exception $e) {
        error_log("History query error: " . $e->getMessage());
        $approval_history = [];
    }

} catch (Exception $e) {
    error_log("Data load error: " . $e->getMessage());
    $_SESSION['error_message'] = "Error loading approval data: " . $e->getMessage();
}

// Handle logout
if (isset($_GET['logout']) && $_GET['logout'] === 'true') {
    $_SESSION = [];
    session_destroy();
    header("Location: index.php");
    exit;
}

// Safe output function
function safe_output($value, $default = '') {
    if ($value === null) {
        return $default;
    }
    return htmlspecialchars((string)$value);
}

// Safe number format function with hide numbers support
function safe_number_format($value, $decimals = 2, $default = '0.00') {
    if ($value === null || $value === '') {
        return $default;
    }
    
    // Ensure the value is a float
    $float_value = (float)$value;
    
    // Check if it's a valid number
    if (!is_numeric($float_value)) {
        return $default;
    }
    
    // Check if we should hide numbers
    if (isset($_SESSION['hide_numbers']) && $_SESSION['hide_numbers']) {
        return str_repeat('•', 6); // Changed from asterisk to dot
    }
    
    return number_format($float_value, $decimals);
}

// Function to format amounts with hide/show capability
function format_amount($value, $decimals = 2) {
    if (!isset($_SESSION['hide_numbers']) || !$_SESSION['hide_numbers']) {
        return '₱' . number_format((float)$value, $decimals);
    } else {
        return '₱' . str_repeat('•', 6); // Changed from asterisk to dot
    }
}

// Function to get notifications
function getNotifications(PDO $db, int $user_id): array {
    try {
        $stmt = $db->prepare("
            SELECT * FROM notifications 
            WHERE (user_id = ? OR user_id IS NULL)
            ORDER BY created_at DESC 
            LIMIT 20
        ");
        $stmt->execute([$user_id]);
        return $stmt->fetchAll();
    } catch (Exception $e) {
        error_log("Notifications error: " . $e->getMessage());
        return [];
    }
}

// Fetch notifications
$notifications = getNotifications($db, $user_id);
$unread_notifications = array_filter($notifications, fn($n) => empty($n['is_read']));
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Approval Workflow - Financial Dashboard</title>

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
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
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
        
        .status-revision {
            background-color: #EFF6FF;
            color: #3B82F6;
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
            max-width: 600px;
            max-height: 80vh;
            overflow-y: auto;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
        }
        
        .chart-container {
            height: 320px;
            position: relative;
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
        
        .modal-actions {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 1rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .sidebar-item.active {
            background-color: rgba(5, 150, 105, 0.1);
        }
        
        .action-btn {
            padding: 0.4rem 0.8rem;
            border-radius: 0.375rem;
            font-size: 0.75rem;
            margin-right: 0.25rem;
            cursor: pointer;
            border: 1px solid;
            background: white;
            transition: all 0.2s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
        }
        
        .action-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .action-btn.approve {
            background-color: #D1FAE5;
            color: #059669;
            border-color: #059669;
        }
        
        .action-btn.approve:hover {
            background-color: #A7F3D0;
            color: #047857;
        }
        
        .action-btn.reject {
            background-color: #FEE2E2;
            color: #DC2626;
            border-color: #DC2626;
        }
        
        .action-btn.reject:hover {
            background-color: #FECACA;
            color: #B91C1C;
        }
        
        .priority-high {
            border-left: 4px solid #EF4444;
        }
        
        .priority-medium {
            border-left: 4px solid #F59E0B;
        }
        
        .priority-low {
            border-left: 4px solid #10B981;
        }
        
        .filter-notice {
            background-color: #EFF6FF;
            border: 1px solid #3B82F6;
            border-radius: 0.5rem;
            padding: 1rem;
            margin-bottom: 1rem;
        }
        
        .notification-item {
            transition: background-color 0.2s;
        }
        
        .notification-item:hover {
            background-color: #f8fafc;
        }
        
        .mark-read-btn {
            background: transparent;
            border: 1px solid #d1d5db;
            border-radius: 0.375rem;
            padding: 0.25rem 0.5rem;
            font-size: 0.75rem;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .mark-read-btn:hover {
            background-color: #10B981;
            color: white;
            border-color: #10B981;
        }
        
        .tab {
            padding: 0.5rem 1rem;
            cursor: pointer;
            border-bottom: 2px solid transparent;
            color: #6b7280;
            transition: all 0.2s;
        }
        
        .tab:hover {
            color: #059669;
        }
        
        .tab.active {
            border-bottom-color: #059669;
            color: #059669;
            font-weight: 500;
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
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
                               text-gray-700 hover:bg-green-50 hover:text-brand-primary
                               transition-all duration-200 hover:translate-x-1 active:translate-x-0 active:scale-[0.99] font-semibold text-sm">
                        <span class="flex items-center gap-3">
                            <span class="inline-flex w-7 h-7 rounded-lg bg-emerald-50 items-center justify-center">
                                <i class='bx bx-book text-brand-primary text-xs'></i>
                            </span>
                            General Ledger
                        </span>
                        <svg id="ledger-arrow" class="w-3.5 h-3.5 text-emerald-400 transition-transform duration-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </button>
                    <div id="ledger-submenu" class="submenu mt-1">
                        <div class="pl-3 pr-2 py-1.5 space-y-1 border-l-2 border-gray-100 ml-5">
                            <a href="chart_of_accounts.php" class="block px-3 py-1.5 rounded-lg text-xs text-gray-600 hover:bg-green-50 hover:text-brand-primary transition-all duration-200 hover:translate-x-1">Chart of Accounts</a>
                            <a href="journal_entry.php" class="block px-3 py-1.5 rounded-lg text-xs text-gray-600 hover:bg-green-50 hover:text-brand-primary transition-all duration-200 hover:translate-x-1">Journal Entry</a>
                            <a href="ledger_table.php" class="block px-3 py-1.5 rounded-lg text-xs text-gray-600 hover:bg-green-50 hover:text-brand-primary transition-all duration-200 hover:translate-x-1">Ledger Table</a>
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
                               bg-emerald-50 text-brand-primary border border-emerald-100
                               transition-all duration-200 hover:translate-x-1 active:translate-x-0 active:scale-[0.99] font-semibold text-sm">
                        <span class="flex items-center gap-3">
                            <span class="inline-flex w-7 h-7 rounded-lg bg-emerald-100 items-center justify-center">
                                <i class='bx bx-pie-chart-alt text-emerald-600 text-xs'></i>
                            </span>
                            Budget Management
                        </span>
                        <svg id="budget-arrow" class="w-3.5 h-3.5 text-emerald-400 transition-transform duration-300 rotate-180" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </button>
                    <div id="budget-submenu" class="submenu mt-1 active">
                        <div class="pl-3 pr-2 py-1.5 space-y-1 border-l-2 border-emerald-200 ml-5">
                            <a href="budget_proposal.php" class="block px-3 py-1.5 rounded-lg text-xs text-gray-600 hover:bg-green-50 hover:text-brand-primary transition-all duration-200 hover:translate-x-1">
                                Budget Proposal
                            </a>
                            <a href="approval_workflow.php" class="block px-3 py-1.5 rounded-lg text-xs bg-emerald-50 text-brand-primary font-medium border border-emerald-100 hover:bg-emerald-100 hover:border-emerald-200 transition-all duration-200 hover:translate-x-1">
                                <span class="flex items-center justify-between">
                                    Approval Workflow
                                    <span class="inline-flex w-1.5 h-1.5 rounded-full bg-emerald-400"></span>
                                </span>
                            </a>
                            <a href="budget_vs_actual.php" class="block px-3 py-1.5 rounded-lg text-xs text-gray-600 hover:bg-green-50 hover:text-brand-primary transition-all duration-200 hover:translate-x-1">
                                Budget vs Actual
                            </a>
                            <a href="budget_reports.php" class="block px-3 py-1.5 rounded-lg text-xs text-gray-600 hover:bg-green-50 hover:text-brand-primary transition-all duration-200 hover:translate-x-1">
                                Budget Reports
                            </a>
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
                        Approval Workflow
                    </h1>
                    <p class="text-xs text-gray-500">
                        Manage and track budget proposal approvals
                    </p>
                </div>
            </div>

            <div class="flex items-center gap-3 sm:gap-5">
                <!-- Real-time Clock -->
                <span id="real-time-clock"
                    class="text-xs font-bold text-gray-700 bg-gray-50 px-3 py-2 rounded-lg border border-gray-200">
                    --:--:--
                </span>

                <!-- Visibility Toggle -->
                <button id="visibility-toggle" 
                        class="w-10 h-10 rounded-xl hover:bg-gray-100 active:bg-gray-200 transition flex items-center justify-center relative"
                        title="<?php echo $_SESSION['hide_numbers'] ? 'Show Numbers' : 'Hide Numbers'; ?>">
                    <i class='bx <?php echo $_SESSION['hide_numbers'] ? 'bx-show' : 'bx-hide'; ?> text-gray-600'></i>
                </button>

                <!-- Notifications -->
                <button id="notification-btn" class="w-10 h-10 rounded-xl hover:bg-gray-100 active:bg-gray-200 transition flex items-center justify-center relative">
                    <i class="fa-solid fa-bell text-gray-600"></i>
                    <?php if(count($unread_notifications) > 0): ?>
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
                        <a href="?logout=true" class="block px-4 py-3 text-sm text-red-600 hover:bg-red-50 transition">
                            <i class='bx bx-log-out mr-2'></i> Logout
                        </a>
                    </div>
                </div>
            </div>
        </header>

        <main id="main-content" class="p-4 sm:p-6">
            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">
                    <?= safe_output($_SESSION['success_message']) ?>
                    <?php unset($_SESSION['success_message']); ?>
                </div>
            <?php endif; ?>
            
            <?php if (isset($_SESSION['error_message'])): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
                    <?= safe_output($_SESSION['error_message']) ?>
                    <?php unset($_SESSION['error_message']); ?>
                </div>
            <?php endif; ?>

            <!-- Filter Notice -->
            <?php if (isset($_GET['proposal_id'])): ?>
                <div class="filter-notice mb-4">
                    <div class="flex items-center">
                        <i class="fas fa-filter text-blue-500 mr-2"></i>
                        <span class="text-blue-700">
                            Showing results for Proposal ID: <strong><?= safe_output($_GET['proposal_id']) ?></strong>
                            <a href="approval_workflow.php" class="ml-2 text-blue-500 hover:text-blue-700 underline">Show all proposals</a>
                        </span>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Stats Overview -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-6">
                <?php 
                $approved_by_me = count(array_filter($my_approvals, fn($a) => $a['action'] === 'Approved'));
                $rejected_by_me = count(array_filter($my_approvals, fn($a) => $a['action'] === 'Rejected'));
                $total_approved = count(array_filter($approval_history, fn($h) => $h['status'] === 'Approved'));
                
                $stats = [
                    ['icon' => 'bx-time-five', 'label' => 'Pending My Approval', 'value' => count($pending_approvals), 'color' => 'yellow', 'stat' => 'pending'],
                    ['icon' => 'bx-check-circle', 'label' => 'My Decisions', 'value' => count($my_approvals), 'color' => 'blue', 'stat' => 'decisions'],
                    ['icon' => 'bx-like', 'label' => 'Approved by Me', 'value' => $approved_by_me, 'color' => 'green', 'stat' => 'approved'],
                    ['icon' => 'bx-chart', 'label' => 'Total Approved', 'value' => $total_approved, 'color' => 'green', 'stat' => 'total_approved']
                ];
                
                foreach($stats as $stat): 
                    $bgColors = [
                        'green' => 'bg-green-100',
                        'red' => 'bg-red-100',
                        'yellow' => 'bg-yellow-100',
                        'blue' => 'bg-blue-100'
                    ];
                    $textColors = [
                        'green' => 'text-green-600',
                        'red' => 'text-red-600',
                        'yellow' => 'text-yellow-600',
                        'blue' => 'text-blue-600'
                    ];
                ?>
                <div class="stat-card rounded-xl p-6">
                    <div class="flex items-center gap-4">
                        <div class="p-3 rounded-lg <?php echo $bgColors[$stat['color']]; ?>">
                            <i class='bx <?php echo $stat['icon']; ?> <?php echo $textColors[$stat['color']]; ?> text-2xl'></i>
                        </div>
                        <div class="flex-1">
                            <div class="flex justify-between items-start">
                                <div>
                                    <p class="text-sm text-gray-500"><?php echo $stat['label']; ?></p>
                                    <p class="text-2xl font-bold text-gray-800 stat-value" 
                                       data-value="<?php echo number_format($stat['value']); ?>"
                                       data-stat="<?php echo $stat['stat']; ?>">
                                        <?php echo number_format($stat['value']); ?>
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Main Content -->
            <div class="space-y-6">
                <!-- Tabs for different views -->
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                    <div class="border-b border-gray-100 pb-4 mb-6">
                        <div class="flex space-x-6">
                            <button class="tab active" data-tab="pending">
                                <i class="bx bx-time-five mr-2"></i>Pending Approval
                                <?php if(count($pending_approvals) > 0): ?>
                                    <span class="ml-2 bg-yellow-100 text-yellow-800 text-xs font-medium px-2 py-0.5 rounded-full">
                                        <?php echo count($pending_approvals); ?>
                                    </span>
                                <?php endif; ?>
                            </button>
                            <button class="tab" data-tab="my-approvals">
                                <i class="bx bx-check-circle mr-2"></i>My Decisions
                            </button>
                            <button class="tab" data-tab="history">
                                <i class="bx bx-history mr-2"></i>Approval History
                            </button>
                        </div>
                    </div>

                    <!-- Pending Approval Tab -->
                    <div class="tab-content active" id="pending-tab">
                        <h3 class="text-lg font-bold text-gray-800 mb-4">Proposals Pending My Approval</h3>
                        <?php if (empty($pending_approvals)): ?>
                            <div class="text-center py-8 text-gray-500">
                                <i class='bx bx-folder-open text-3xl mb-2 text-gray-300'></i>
                                <div class="text-lg">No proposals pending your approval</div>
                                <p class="text-sm">All caught up! New proposals will appear here when they reach your approval stage.</p>
                                <?php if (isset($_GET['proposal_id'])): ?>
                                    <p class="text-sm mt-2">
                                        The proposal you're looking for might have been approved, rejected, or is awaiting approval from someone else.
                                    </p>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="overflow-x-auto">
                                <table class="w-full">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th class="text-left p-4 text-sm font-medium text-gray-500">Proposal Title</th>
                                            <th class="text-left p-4 text-sm font-medium text-gray-500">Department</th>
                                            <th class="text-left p-4 text-sm font-medium text-gray-500">Submitter</th>
                                            <th class="text-left p-4 text-sm font-medium text-gray-500">Amount</th>
                                            <th class="text-left p-4 text-sm font-medium text-gray-500">Days Pending</th>
                                            <th class="text-left p-4 text-sm font-medium text-gray-500">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($pending_approvals as $proposal): 
                                            $days_pending = $proposal['days_pending'] ?? floor((time() - strtotime($proposal['submitted_date'])) / (60 * 60 * 24));
                                            $priority = $days_pending > 7 ? 'high' : ($days_pending > 3 ? 'medium' : 'low');
                                        ?>
                                            <tr class="transaction-row priority-<?= $priority ?>">
                                                <td class="p-4">
                                                    <div class="font-medium text-gray-800"><?= safe_output($proposal['title']) ?></div>
                                                </td>
                                                <td class="p-4 text-gray-600"><?= safe_output($proposal['department_name']) ?></td>
                                                <td class="p-4 text-gray-600"><?= safe_output($proposal['submitter_name']) ?></td>
                                                <td class="p-4 font-medium text-gray-800 <?= $_SESSION['hide_numbers'] ? 'hidden-amount' : '' ?>">
                                                    <?= format_amount((float)$proposal['total_amount'], 2) ?>
                                                </td>
                                                <td class="p-4">
                                                    <span class="<?= $days_pending > 7 ? 'text-red-600 font-semibold' : ($days_pending > 3 ? 'text-orange-600' : 'text-gray-600') ?>">
                                                        <?= $days_pending ?> days
                                                    </span>
                                                </td>
                                                <td class="p-4">
                                                    <button class="action-btn approve" onclick="openApprovalModal(<?= $proposal['id'] ?>, 'approve')">
                                                        <i class="fa-solid fa-check mr-1"></i>Approve
                                                    </button>
                                                    <button class="action-btn reject" onclick="openApprovalModal(<?= $proposal['id'] ?>, 'reject')">
                                                        <i class="fa-solid fa-times mr-1"></i>Reject
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- My Approvals Tab -->
                    <div class="tab-content" id="my-approvals-tab">
                        <h3 class="text-lg font-bold text-gray-800 mb-4">My Approval Decisions</h3>
                        <?php if (empty($my_approvals)): ?>
                            <div class="text-center py-8 text-gray-500">
                                <i class='bx bx-folder-open text-3xl mb-2 text-gray-300'></i>
                                <div class="text-lg">No approval decisions made yet</div>
                                <p class="text-sm">Your approval decisions will appear here once you start reviewing proposals.</p>
                            </div>
                        <?php else: ?>
                            <div class="overflow-x-auto">
                                <table class="w-full">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th class="text-left p-4 text-sm font-medium text-gray-500">Proposal Title</th>
                                            <th class="text-left p-4 text-sm font-medium text-gray-500">Department</th>
                                            <th class="text-left p-4 text-sm font-medium text-gray-500">Submitter</th>
                                            <th class="text-left p-4 text-sm font-medium text-gray-500">Amount</th>
                                            <th class="text-left p-4 text-sm font-medium text-gray-500">My Action</th>
                                            <th class="text-left p-4 text-sm font-medium text-gray-500">Date</th>
                                            <th class="text-left p-4 text-sm font-medium text-gray-500">Comments</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($my_approvals as $approval): ?>
                                            <tr class="transaction-row">
                                                <td class="p-4">
                                                    <div class="font-medium text-gray-800"><?= safe_output($approval['title']) ?></div>
                                                </td>
                                                <td class="p-4 text-gray-600"><?= safe_output($approval['department_name']) ?></td>
                                                <td class="p-4 text-gray-600"><?= safe_output($approval['submitter_name']) ?></td>
                                                <td class="p-4 font-medium text-gray-800 <?= $_SESSION['hide_numbers'] ? 'hidden-amount' : '' ?>">
                                                    <?= format_amount((float)$approval['total_amount'], 2) ?>
                                                </td>
                                                <td class="p-4">
                                                    <span class="status-badge status-<?= strtolower($approval['action']) ?>">
                                                        <?= safe_output($approval['action']) ?>
                                                    </span>
                                                </td>
                                                <td class="p-4 text-gray-600"><?= date('M j, Y g:i A', strtotime($approval['approved_at'])) ?></td>
                                                <td class="p-4 text-gray-600 text-sm max-w-xs truncate"><?= safe_output($approval['comments']) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Approval History Tab -->
                    <div class="tab-content" id="history-tab">
                        <h3 class="text-lg font-bold text-gray-800 mb-4">Approval History</h3>
                        <?php if (empty($approval_history)): ?>
                            <div class="text-center py-8 text-gray-500">
                                <i class='bx bx-folder-open text-3xl mb-2 text-gray-300'></i>
                                <div class="text-lg">No approval history found</div>
                                <p class="text-sm">Approval history will appear here once proposals go through the workflow.</p>
                            </div>
                        <?php else: ?>
                            <div class="overflow-x-auto">
                                <table class="w-full">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th class="text-left p-4 text-sm font-medium text-gray-500">Proposal Title</th>
                                            <th class="text-left p-4 text-sm font-medium text-gray-500">Department</th>
                                            <th class="text-left p-4 text-sm font-medium text-gray-500">Submitter</th>
                                            <th class="text-left p-4 text-sm font-medium text-gray-500">Amount</th>
                                            <th class="text-left p-4 text-sm font-medium text-gray-500">Final Status</th>
                                            <th class="text-left p-4 text-sm font-medium text-gray-500">Completed Date</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($approval_history as $history): ?>
                                            <tr class="transaction-row">
                                                <td class="p-4">
                                                    <div class="font-medium text-gray-800"><?= safe_output($history['title']) ?></div>
                                                </td>
                                                <td class="p-4 text-gray-600"><?= safe_output($history['department_name']) ?></td>
                                                <td class="p-4 text-gray-600"><?= safe_output($history['submitter_name']) ?></td>
                                                <td class="p-4 font-medium text-gray-800 <?= $_SESSION['hide_numbers'] ? 'hidden-amount' : '' ?>">
                                                    <?= format_amount((float)$history['total_amount'], 2) ?>
                                                </td>
                                                <td class="p-4">
                                                    <span class="status-badge status-<?= strtolower($history['status']) ?>">
                                                        <?= safe_output($history['status']) ?>
                                                    </span>
                                                </td>
                                                <td class="p-4 text-gray-600"><?= date('M j, Y', strtotime($history['updated_at'])) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
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
                <?php if (empty($notifications)): ?>
                    <div class="text-center py-8 text-gray-500">
                        <i class='bx bx-bell text-3xl mb-2 text-gray-300'></i>
                        <div>No notifications</div>
                    </div>
                <?php else: ?>
                    <?php 
                    $unreadNotifications = array_filter($notifications, fn($n) => empty($n['is_read']));
                    $readNotifications = array_filter($notifications, fn($n) => !empty($n['is_read']));
                    ?>
                    
                    <?php if (count($unreadNotifications) > 0): ?>
                        <div class="space-y-4">
                            <div class="flex justify-between items-center">
                                <h3 class="font-semibold text-gray-700">Unread</h3>
                                <button id="mark-all-read-btn" class="px-3 py-1 bg-brand-primary text-white text-sm rounded-lg hover:bg-brand-primary-hover transition">
                                    <i class="fa-solid fa-check-double mr-1"></i>Mark All as Read
                                </button>
                            </div>
                            <?php foreach ($unreadNotifications as $notification): ?>
                                <div class="notification-item flex items-start gap-3 p-4 rounded-lg bg-blue-50 hover:bg-blue-100 transition">
                                    <div class="w-10 h-10 rounded-lg bg-blue-100 flex items-center justify-center flex-shrink-0">
                                        <i class='bx bx-bell-ring text-blue-500'></i>
                                    </div>
                                    <div class="flex-1">
                                        <div class="flex justify-between items-start">
                                            <div class="font-medium text-gray-800 text-sm"><?= safe_output($notification['message'] ?? 'Notification') ?></div>
                                            <span class="w-2 h-2 rounded-full bg-blue-500 mt-1 flex-shrink-0"></span>
                                        </div>
                                        <div class="text-xs text-gray-500 mt-2"><?= date('M j, Y', strtotime($notification['created_at'])) ?></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (count($readNotifications) > 0): ?>
                        <div class="space-y-4 mt-6">
                            <h3 class="font-semibold text-gray-700">Read</h3>
                            <?php foreach ($readNotifications as $notification): ?>
                                <div class="notification-item flex items-start gap-3 p-4 rounded-lg bg-gray-50 hover:bg-gray-100 transition">
                                    <div class="w-10 h-10 rounded-lg bg-gray-100 flex items-center justify-center flex-shrink-0">
                                        <i class='bx bx-bell text-gray-500'></i>
                                    </div>
                                    <div class="flex-1">
                                        <div class="font-medium text-gray-800 text-sm"><?= safe_output($notification['message'] ?? 'Notification') ?></div>
                                        <div class="text-xs text-gray-500 mt-2"><?= date('M j, Y', strtotime($notification['created_at'])) ?></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Approval Action Modal -->
    <div id="approval-modal" class="modal">
        <div class="modal-content">
            <div class="flex justify-between items-center mb-6">
                <h2 class="text-xl font-bold text-gray-800" id="approval-modal-title">Approve Proposal</h2>
                <button class="close-modal text-gray-400 hover:text-gray-600 text-2xl">&times;</button>
            </div>
            <form method="POST" id="approval-form">
                <input type="hidden" name="proposal_id" id="modal-proposal-id">
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Comments</label>
                        <textarea name="comments" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-brand-primary focus:border-transparent" 
                                  rows="4" placeholder="Enter your comments or feedback..."></textarea>
                    </div>
                    <div class="flex gap-3 pt-4">
                        <button type="button" class="flex-1 px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition close-modal">Cancel</button>
                        <button type="submit" name="approve_proposal" class="flex-1 px-4 py-2 bg-brand-primary text-white rounded-lg hover:bg-brand-primary-hover transition" id="approve-btn" style="display: none;">
                            <i class="fa-solid fa-check mr-2"></i>Approve
                        </button>
                        <button type="submit" name="reject_proposal" class="flex-1 px-4 py-2 bg-red-500 text-white rounded-lg hover:bg-red-600 transition" id="reject-btn" style="display: none;">
                            <i class="fa-solid fa-times mr-2"></i>Reject
                        </button>
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
            const displayHours = hours % 12 || 12; // Convert 0 to 12
            
            const timeString = `${displayHours}:${minutes}:${seconds} ${ampm}`;
            const clockElement = document.getElementById('real-time-clock');
            if (clockElement) {
                clockElement.textContent = timeString;
            }
        }
        setInterval(updateClock, 1000);
        updateClock();

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
        
        // Tab functionality
        const tabs = document.querySelectorAll('.tab');
        tabs.forEach(tab => {
            tab.addEventListener('click', function() {
                const tabId = this.getAttribute('data-tab');
                
                // Remove active class from all tabs and tab contents
                tabs.forEach(t => t.classList.remove('active'));
                document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));
                
                // Add active class to clicked tab and corresponding content
                this.classList.add('active');
                const tabContent = document.getElementById(`${tabId}-tab`);
                if (tabContent) {
                    tabContent.classList.add('active');
                }
            });
        });
        
        // Hide numbers toggle functionality
        const visibilityToggle = document.getElementById('visibility-toggle');
        if (visibilityToggle) {
            visibilityToggle.addEventListener('click', function() {
                // Create form data
                const formData = new FormData();
                formData.append('toggle_hide_numbers', '1');
                formData.append('ajax', '1');
                
                // Show loading state
                const icon = this.querySelector('i');
                const originalClass = icon.className;
                const originalTitle = this.title;
                
                icon.className = 'fas fa-spinner fa-spin text-gray-600';
                this.disabled = true;
                
                // Send AJAX request
                fetch('', {
                    method: 'POST',
                    body: formData
                })
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.success) {
                        // Update icon and title
                        if (data.hide_numbers) {
                            icon.className = 'bx bx-show text-gray-600';
                            this.title = 'Show Numbers';
                        } else {
                            icon.className = 'bx bx-hide text-gray-600';
                            this.title = 'Hide Numbers';
                        }
                        
                        // Reload page to update all amounts
                        setTimeout(() => {
                            window.location.reload();
                        }, 300);
                    } else {
                        throw new Error('Toggle failed');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error toggling number visibility. Please try again.');
                    icon.className = originalClass;
                    this.disabled = false;
                });
            });
        }
        
        // Approval modal functionality
        window.openApprovalModal = function(proposalId, action) {
            const modal = document.getElementById('approval-modal');
            const title = document.getElementById('approval-modal-title');
            const proposalIdInput = document.getElementById('modal-proposal-id');
            const approveBtn = document.getElementById('approve-btn');
            const rejectBtn = document.getElementById('reject-btn');
            
            // Hide both buttons first
            approveBtn.style.display = 'none';
            rejectBtn.style.display = 'none';
            
            // Set proposal ID
            proposalIdInput.value = proposalId;
            
            // Show appropriate button and set title
            if (action === 'approve') {
                title.textContent = 'Approve Proposal';
                approveBtn.style.display = 'block';
            } else if (action === 'reject') {
                title.textContent = 'Reject Proposal';
                rejectBtn.style.display = 'block';
            }
            
            modal.style.display = 'block';
            document.body.style.overflow = 'hidden';
        };
        
        // Mark all as read functionality
        const markAllReadBtn = document.getElementById('mark-all-read-btn');
        if (markAllReadBtn) {
            markAllReadBtn.addEventListener('click', function() {
                const formData = new FormData();
                formData.append('mark_all_notifications_read', '1');
                formData.append('ajax', '1');
                
                const originalHtml = this.innerHTML;
                this.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i>Processing...';
                this.disabled = true;
                
                fetch('', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Reload page to update notifications
                        window.location.reload();
                    } else {
                        alert('Error marking all notifications as read');
                        this.innerHTML = originalHtml;
                        this.disabled = false;
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error marking all notifications as read');
                    this.innerHTML = originalHtml;
                    this.disabled = false;
                });
            });
        }
    });
    </script>
</body>
</html>