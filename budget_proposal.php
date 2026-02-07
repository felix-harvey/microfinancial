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

// Handle AJAX toggle hide numbers
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_hide_numbers']) && isset($_POST['ajax'])) {
    $_SESSION['hide_numbers'] = !$_SESSION['hide_numbers'];
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'hide_numbers' => $_SESSION['hide_numbers']]);
    exit;
}

// Handle regular POST toggle (non-AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_hide_numbers']) && !isset($_POST['ajax'])) {
    $_SESSION['hide_numbers'] = !$_SESSION['hide_numbers'];
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
$budget_proposals = [];
$departments = [];
$fiscal_years = [];
$categories = [];
$notifications = [];

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['create_proposal'])) {
            handleCreateProposal($db, $user_id);
        } elseif (isset($_POST['update_proposal'])) {
            handleUpdateProposal($db, $user_id);
        } elseif (isset($_POST['delete_proposal'])) {
            handleDeleteProposal($db, $user_id);
        }
    } catch (Exception $e) {
        error_log("Budget proposal error: " . $e->getMessage());
        $_SESSION['error_message'] = "Error: " . $e->getMessage();
    }
}

// Load data for dropdowns and listings
loadData($db, $user_id, $budget_proposals, $departments, $fiscal_years, $categories);

// Handle logout
if (isset($_GET['logout']) && $_GET['logout'] === 'true') {
    $_SESSION = [];
    session_destroy();
    header("Location: index.php");
    exit;
}

// ========== HELPER FUNCTIONS ==========

function safe_output($value, $default = '') {
    if ($value === null) {
        return $default;
    }
    return htmlspecialchars((string)$value);
}

function safe_number_format($value, $decimals = 2, $default = '0.00') {
    if ($value === null || $value === '') {
        return $default;
    }
    
    $float_value = (float)$value;
    
    if (!is_numeric($float_value)) {
        return $default;
    }
    
    if (isset($_SESSION['hide_numbers']) && $_SESSION['hide_numbers']) {
        return str_repeat('•', 6); // Changed from asterisk to dot
    }
    
    return number_format($float_value, $decimals);
}

function format_amount($value, $decimals = 2) {
    if (!isset($_SESSION['hide_numbers']) || !$_SESSION['hide_numbers']) {
        return '₱' . number_format((float)$value, $decimals);
    } else {
        return '₱' . str_repeat('•', 6); // Changed from asterisk to dot
    }
}

function getNotifications(PDO $db, int $user_id): array {
    try {
        $stmt = $db->prepare("
            SELECT * FROM notifications 
            WHERE (user_id = ? OR user_id IS NULL) 
            AND (is_read = 0 OR is_read IS NULL)
            ORDER BY created_at DESC 
            LIMIT 10
        ");
        $stmt->execute([$user_id]);
        return $stmt->fetchAll();
    } catch (Exception $e) {
        error_log("Notifications error: " . $e->getMessage());
        return [];
    }
}

function handleCreateProposal(PDO $db, $user_id): void {
    $required = ['title', 'department', 'fiscal_year', 'total_amount'];
    foreach ($required as $field) {
        if (empty($_POST[$field])) {
            throw new Exception("Missing required field: $field");
        }
    }
    
    $title = trim($_POST['title']);
    $department = (int)$_POST['department'];
    $fiscal_year = trim($_POST['fiscal_year']);
    $total_amount = (float)$_POST['total_amount'];
    
    if (empty($title)) {
        throw new Exception("Proposal title is required");
    }
    
    if ($total_amount <= 0) {
        throw new Exception("Total amount must be greater than 0");
    }
    
    // Check if already in transaction
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    
    // Start transaction
    $db->beginTransaction();
    
    try {
        // 1. Insert budget proposal with 'Pending' status
        $stmt = $db->prepare("
            INSERT INTO budget_proposals 
            (title, department, fiscal_year, submitted_by, status, total_amount, remaining_amount) 
            VALUES (?, ?, ?, ?, 'Pending', ?, ?)
        ");
        $stmt->execute([$title, $department, $fiscal_year, $user_id, $total_amount, $total_amount]);
        
        $db->commit();
        
        $_SESSION['success_message'] = "Budget proposal created successfully! It has been sent to the Approval Workflow.";
        
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw new Exception("Failed to create budget proposal: " . $e->getMessage());
    }
    
    header("Location: approval_workflow.php");
    exit;
}

function handleUpdateProposal(PDO $db, $user_id): void {
    if (empty($_POST['proposal_id'])) {
        throw new Exception("Proposal ID is required");
    }
    
    $proposal_id = (int)$_POST['proposal_id'];
    $title = trim($_POST['title']);
    
    $stmt = $db->prepare("
        UPDATE budget_proposals 
        SET title = ?, updated_at = NOW()
        WHERE id = ? AND submitted_by = ?
    ");
    
    $stmt->execute([$title, $proposal_id, $user_id]);
    
    $_SESSION['success_message'] = "Budget allocation updated successfully!";
    header("Location: budget_proposal.php");
    exit;
}

function handleDeleteProposal(PDO $db, $user_id): void {
    if (empty($_POST['proposal_id'])) {
        throw new Exception("Proposal ID is required");
    }
    
    $proposal_id = (int)$_POST['proposal_id'];
    
    // Verify the proposal belongs to the user
    $verify_stmt = $db->prepare("SELECT id FROM budget_proposals WHERE id = ? AND submitted_by = ?");
    $verify_stmt->execute([$proposal_id, $user_id]);
    $proposal = $verify_stmt->fetch();
    
    if (!$proposal) {
        throw new Exception("Proposal not found or you don't have permission to delete it");
    }
    
    // Check if already in transaction
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    
    // Start transaction
    $db->beginTransaction();
    
    try {
        // Check if there are disbursements linked to this budget
        $check_stmt = $db->prepare("SELECT COUNT(*) as count FROM disbursement_requests WHERE budget_proposal_id = ?");
        $check_stmt->execute([$proposal_id]);
        $result = $check_stmt->fetch();
        
        if ($result['count'] > 0) {
            throw new Exception("Cannot delete budget with existing disbursements. Please delete disbursements first.");
        }
        
        // Delete the Proposal itself
        $delete_stmt = $db->prepare("DELETE FROM budget_proposals WHERE id = ? AND submitted_by = ?");
        $delete_stmt->execute([$proposal_id, $user_id]);
        
        // Delete related budget allocations if table exists
        try {
            $delete_alloc = $db->prepare("DELETE FROM budget_allocations WHERE budget_proposal_id = ?");
            $delete_alloc->execute([$proposal_id]);
        } catch (Exception $e) {
            // Table might not exist, that's okay
        }
        
        $db->commit();
        
        $_SESSION['success_message'] = "Budget allocation deleted successfully!";
        
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw new Exception("Failed to delete allocation: " . $e->getMessage());
    }
    
    header("Location: budget_proposal.php");
    exit;
}

function loadData(PDO $db, $user_id, &$budget_proposals, &$departments, &$fiscal_years, &$categories): void {
    try {
        // Get budget proposals - show all approved (allocated) budgets
        $proposal_stmt = $db->prepare("
            SELECT bp.*, u.name as submitter_name, 
                   d.name as department_name,
                   bp.total_amount as allocated_amount,
                   bp.remaining_amount,
                   (bp.total_amount - bp.remaining_amount) as spent_amount,
                   (SELECT COUNT(*) FROM disbursement_requests WHERE budget_proposal_id = bp.id AND status = 'Approved') as disbursement_count,
                   (SELECT SUM(amount) FROM disbursement_requests WHERE budget_proposal_id = bp.id AND status = 'Approved') as total_disbursed
            FROM budget_proposals bp
            LEFT JOIN users u ON bp.submitted_by = u.id
            LEFT JOIN departments d ON bp.department = d.id
            WHERE bp.submitted_by = ?
            AND bp.status = 'Approved'
            GROUP BY bp.id
            ORDER BY bp.created_at DESC
        ");
        $proposal_stmt->execute([$user_id]);
        $budget_proposals = $proposal_stmt->fetchAll();

        // Get departments
        $dept_stmt = $db->query("SELECT id, name FROM departments WHERE status = 'Active' ORDER BY name");
        $departments = $dept_stmt->fetchAll();

        // Get fiscal years
        $year_stmt = $db->query("SELECT DISTINCT fiscal_year FROM fiscal_years WHERE status = 'Active' ORDER BY fiscal_year DESC");
        $fiscal_years = $year_stmt->fetchAll();
        if (empty($fiscal_years)) {
            $fiscal_years = [['fiscal_year' => date('Y')]];
        }

        // Budget categories table doesn't exist, initialize empty array
        $categories = [];

    } catch (Exception $e) {
        error_log("Data load error: " . $e->getMessage());
        $_SESSION['error_message'] = "Error loading data: " . $e->getMessage();
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
    <title>Budget Allocation Management - Financial Dashboard</title>

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
        
        .action-btn.edit {
            background-color: #FEF3C7;
            color: #D97706;
            border-color: #D97706;
        }
        
        .action-btn.edit:hover {
            background-color: #FDE68A;
            color: #B45309;
        }
        
        .action-btn.success {
            background-color: #D1FAE5;
            color: #059669;
            border-color: #059669;
        }
        
        .action-btn.success:hover {
            background-color: #A7F3D0;
            color: #047857;
        }
        
        .action-btn.view {
            background-color: #EFF6FF;
            color: #3B82F6;
            border-color: #3B82F6;
        }
        
        .action-btn.view:hover {
            background-color: #DBEAFE;
            color: #2563EB;
        }
        
        .action-btn.spend {
            background-color: #4F46E5;
            color: white;
            border-color: #4F46E5;
        }
        
        .action-btn.spend:hover {
            background-color: #4338CA;
            color: white;
        }
        
        .action-btn.danger {
            background-color: #FEE2E2;
            color: #DC2626;
            border-color: #DC2626;
        }
        
        .action-btn.danger:hover {
            background-color: #FECACA;
            color: #B91C1C;
        }
        
        .progress-bar {
            height: 8px;
            background-color: #e5e7eb;
            border-radius: 4px;
            overflow: hidden;
            margin-top: 4px;
        }
        
        .progress-fill {
            height: 100%;
            background-color: #10B981;
            border-radius: 4px;
        }
        
        .progress-fill.warning {
            background-color: #F59E0B;
        }
        
        .progress-fill.danger {
            background-color: #EF4444;
        }
        
        .budget-status-indicator {
            display: inline-flex;
            align-items: center;
            font-size: 0.75rem;
            padding: 0.2rem 0.5rem;
            border-radius: 0.25rem;
            margin-left: 0.5rem;
        }
        
        .budget-status-indicator.healthy {
            background-color: rgba(34, 197, 94, 0.1);
            color: #16A34A;
        }
        
        .budget-status-indicator.warning {
            background-color: rgba(245, 158, 11, 0.1);
            color: #D97706;
        }
        
        .budget-status-indicator.danger {
            background-color: rgba(239, 68, 68, 0.1);
            color: #DC2626;
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
                            <a href="budget_proposal.php" class="block px-3 py-1.5 rounded-lg text-xs bg-emerald-50 text-brand-primary font-medium border border-emerald-100 hover:bg-emerald-100 hover:border-emerald-200 transition-all duration-200 hover:translate-x-1">
                                <span class="flex items-center justify-between">
                                    Budget Proposal
                                    <span class="inline-flex w-1.5 h-1.5 rounded-full bg-emerald-400"></span>
                                </span>
                            </a>
                            <a href="approval_workflow.php" class="block px-3 py-1.5 rounded-lg text-xs text-gray-600 hover:bg-emerald-50 hover:text-brand-primary transition-all duration-200 hover:translate-x-1">
                                Approval Workflow
                            </a>
                            <a href="budget_vs_actual.php" class="block px-3 py-1.5 rounded-lg text-xs text-gray-600 hover:bg-emerald-50 hover:text-brand-primary transition-all duration-200 hover:translate-x-1">
                                Budget vs Actual
                            </a>
                            <a href="budget_reports.php" class="block px-3 py-1.5 rounded-lg text-xs text-gray-600 hover:bg-emerald-50 hover:text-brand-primary transition-all duration-200 hover:translate-x-1">
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
                        Budget Allocation Management
                    </h1>
                    <p class="text-xs text-gray-500">
                        Create and manage budget allocations for department spending
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

            <!-- Stats Overview -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-6">
                <?php 
                $total_allocated = array_sum(array_column($budget_proposals, 'allocated_amount'));
                $total_remaining = array_sum(array_column($budget_proposals, 'remaining_amount'));
                $total_spent = $total_allocated - $total_remaining;
                $usage_percentage = $total_allocated > 0 ? ($total_spent / $total_allocated) * 100 : 0;
                
                $stats = [
                    ['icon' => 'bx-money', 'label' => 'Total Allocations', 'value' => count($budget_proposals), 'color' => 'green', 'stat' => 'allocations'],
                    ['icon' => 'bx-wallet', 'label' => 'Total Allocated', 'value' => $total_allocated, 'color' => 'blue', 'stat' => 'allocated'],
                    ['icon' => 'bx-credit-card', 'label' => 'Total Spent', 'value' => $total_spent, 'color' => 'yellow', 'stat' => 'spent'],
                    ['icon' => 'bx-pie-chart-alt', 'label' => 'Total Available', 'value' => $total_remaining, 'color' => 'green', 'stat' => 'available']
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
                    
                    // Format value based on type
                    $displayValue = $stat['label'] === 'Total Allocations' 
                        ? number_format($stat['value'])
                        : format_amount($stat['value']);
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
                                       data-value="<?php echo $displayValue; ?>"
                                       data-stat="<?php echo $stat['stat']; ?>">
                                        <?php echo $displayValue; ?>
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
                <!-- Header -->
                <div class="flex justify-between items-center mb-6">
                    <div>
                        <h2 class="text-2xl font-bold text-gray-800">Budget Allocations</h2>
                        <p class="text-gray-600 text-sm">Manage and track budget allocations across departments</p>
                    </div>
                    <div class="flex space-x-3">
                        <button onclick="printProposals()" 
                                class="px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 transition flex items-center gap-2">
                            <i class="fas fa-print"></i> Print
                        </button>
                        <button onclick="exportToExcel()" 
                                class="px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 transition flex items-center gap-2">
                            <i class="fas fa-file-excel"></i> Export
                        </button>
                        <button onclick="openModal('create-proposal-modal')" 
                                class="px-4 py-2 bg-brand-primary text-white rounded-lg hover:bg-brand-primary-hover transition flex items-center gap-2">
                            <i class="fas fa-plus"></i> New Allocation
                        </button>
                    </div>
                </div>

                <!-- Budget Allocations Table -->
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                    <div class="p-6 border-b border-gray-100">
                        <div class="flex justify-between items-center">
                            <div class="flex items-center gap-3">
                                <h3 class="text-lg font-bold text-gray-800">Budget Allocations</h3>
                            </div>
                            <span class="text-sm text-gray-500">
                                Showing <?php echo count($budget_proposals); ?> allocations
                            </span>
                        </div>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="text-left p-4 text-sm font-medium text-gray-500">Title</th>
                                    <th class="text-left p-4 text-sm font-medium text-gray-500">Department</th>
                                    <th class="text-left p-4 text-sm font-medium text-gray-500">Fiscal Year</th>
                                    <th class="text-left p-4 text-sm font-medium text-gray-500">Status</th>
                                    <th class="text-left p-4 text-sm font-medium text-gray-500">Allocated Amount</th>
                                    <th class="text-left p-4 text-sm font-medium text-gray-500">Spent</th>
                                    <th class="text-left p-4 text-sm font-medium text-gray-500">Remaining</th>
                                    <th class="text-left p-4 text-sm font-medium text-gray-500">Usage</th>
                                    <th class="text-left p-4 text-sm font-medium text-gray-500">Disbursements</th>
                                    <th class="text-left p-4 text-sm font-medium text-gray-500">Created Date</th>
                                    <th class="text-left p-4 text-sm font-medium text-gray-500">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($budget_proposals)): ?>
                                    <tr>
                                        <td colspan="12" class="text-center p-8 text-gray-500">
                                            <i class='bx bx-folder-open text-3xl mb-2 text-gray-300'></i>
                                            <div>No budget allocations found. <button class="text-brand-primary hover:underline" onclick="openModal('create-proposal-modal')">Create your first budget allocation</button></div>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($budget_proposals as $proposal): ?>
                                        <?php 
                                        $allocated = (float)($proposal['allocated_amount'] ?? 0);
                                        $remaining = (float)($proposal['remaining_amount'] ?? $allocated);
                                        $spent = $allocated - $remaining;
                                        $disbursement_count = (int)($proposal['disbursement_count'] ?? 0);
                                        $total_disbursed = (float)($proposal['total_disbursed'] ?? 0);
                                        $usage_percentage = $allocated > 0 ? ($spent / $allocated) * 100 : 0;
                                        
                                        // Determine status
                                        if ($remaining <= 0) {
                                            $status_class = 'status-rejected';
                                            $status_text = 'Exhausted';
                                            $progress_class = 'danger';
                                            $status_indicator = 'danger';
                                        } elseif ($usage_percentage > 80) {
                                            $status_class = 'status-pending';
                                            $status_text = 'Almost Used';
                                            $progress_class = 'warning';
                                            $status_indicator = 'warning';
                                        } else {
                                            $status_class = 'status-approved';
                                            $status_text = 'Active';
                                            $progress_class = '';
                                            $status_indicator = 'healthy';
                                        }
                                        ?>
                                        <tr class="transaction-row">
                                            <td class="p-4">
                                                <div class="font-medium text-gray-800"><?= safe_output($proposal['title'] ?? 'Untitled Budget') ?></div>
                                                <?php if ($disbursement_count > 0): ?>
                                                    <div class="text-xs text-gray-500 mt-1">
                                                        <i class="fa-solid fa-money-bill-wave mr-1"></i>
                                                        <?= $disbursement_count ?> disbursement<?= $disbursement_count > 1 ? 's' : '' ?>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td class="p-4 text-gray-600"><?= safe_output($proposal['department_name'] ?? $proposal['department']) ?></td>
                                            <td class="p-4 text-gray-600"><?= safe_output($proposal['fiscal_year']) ?></td>
                                            <td class="p-4">
                                                <div class="flex items-center">
                                                    <span class="status-badge <?= $status_class ?>">
                                                        <?= $status_text ?>
                                                    </span>
                                                    <span class="budget-status-indicator <?= $status_indicator ?>">
                                                        <?= $remaining <= 0 ? '0%' : number_format($remaining / $allocated * 100, 1) . '%' ?>
                                                    </span>
                                                </div>
                                            </td>
                                            <td class="p-4 font-medium text-gray-800 <?= $_SESSION['hide_numbers'] ? 'hidden-amount' : '' ?>">
                                                <?= format_amount($allocated) ?>
                                            </td>
                                            <td class="p-4 <?= $_SESSION['hide_numbers'] ? 'hidden-amount' : '' ?>">
                                                <?= format_amount($spent) ?>
                                            </td>
                                            <td class="p-4 font-semibold <?= $_SESSION['hide_numbers'] ? 'hidden-amount' : '' ?>">
                                                <?= format_amount($remaining) ?>
                                            </td>
                                            <td class="p-4">
                                                <div class="text-sm <?= $_SESSION['hide_numbers'] ? 'hidden-amount' : '' ?>">
                                                    <?= $_SESSION['hide_numbers'] ? '•••' : number_format($usage_percentage, 1) ?>% <!-- Changed from asterisks to dots -->
                                                </div>
                                                <div class="progress-bar">
                                                    <div class="progress-fill <?= $progress_class ?>" style="width: <?= min($usage_percentage, 100) ?>%"></div>
                                                </div>
                                            </td>
                                            <td class="p-4">
                                                <div class="text-sm <?= $_SESSION['hide_numbers'] ? 'hidden-amount' : '' ?>">
                                                    <?= $disbursement_count ?>
                                                </div>
                                                <div class="text-xs text-gray-500">
                                                    Total: <?= format_amount($total_disbursed) ?>
                                                </div>
                                            </td>
                                            <td class="p-4 text-gray-600"><?= date('M j, Y', strtotime($proposal['created_at'])) ?></td>
                                            <td class="p-4">
                                                <?php if ($remaining > 0): ?>
                                                    <a href="disbursement_request.php?budget_id=<?= $proposal['id'] ?>&budget_title=<?= urlencode($proposal['title']) ?>&remaining=<?= $remaining ?>" 
                                                       class="action-btn spend">
                                                        <i class="fa-solid fa-money-bill-wave mr-1"></i>Spend
                                                    </a>
                                                <?php else: ?>
                                                    <button class="action-btn spend" disabled title="No remaining budget">
                                                        <i class="fa-solid fa-money-bill-wave mr-1"></i>Spend
                                                    </button>
                                                <?php endif; ?>
                                                <button class="action-btn edit" onclick="openEditModal(<?= $proposal['id'] ?>)">
                                                    <i class="fa-solid fa-edit mr-1"></i>Edit
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
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
                    <?php foreach ($notifications as $notification): ?>
                        <div class="flex items-start gap-3 p-4 rounded-lg <?= empty($notification['is_read']) ? 'bg-blue-50' : 'bg-gray-50' ?> hover:bg-gray-100 transition">
                            <div class="w-10 h-10 rounded-lg <?= empty($notification['is_read']) ? 'bg-blue-100' : 'bg-gray-100' ?> flex items-center justify-center flex-shrink-0">
                                <i class='bx <?= empty($notification['is_read']) ? 'bx-bell-ring' : 'bx-bell' ?> <?= empty($notification['is_read']) ? 'text-blue-500' : 'text-gray-500' ?>'></i>
                            </div>
                            <div class="flex-1">
                                <div class="flex justify-between items-start">
                                    <div class="font-medium text-gray-800 text-sm"><?= safe_output($notification['title'] ?? 'Notification') ?></div>
                                    <?= empty($notification['is_read']) ? '<span class="w-2 h-2 rounded-full bg-blue-500 mt-1 flex-shrink-0"></span>' : '' ?>
                                </div>
                                <div class="text-xs text-gray-600 mt-1"><?= safe_output($notification['message'] ?? 'Notification message') ?></div>
                                <div class="text-xs text-gray-400 mt-2"><?= date('M j, Y', strtotime($notification['created_at'])) ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Create Budget Allocation Modal -->
    <div id="create-proposal-modal" class="modal">
        <div class="modal-content">
            <div class="flex justify-between items-center mb-6">
                <h2 class="text-xl font-bold text-gray-800">Create New Budget Allocation</h2>
                <button class="close-modal text-gray-400 hover:text-gray-600 text-2xl">&times;</button>
            </div>
            <form method="POST" id="create-proposal-form">
                <input type="hidden" name="create_proposal" value="1">
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Budget Title*</label>
                        <input type="text" name="title" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-brand-primary focus:border-transparent" 
                               placeholder="e.g., Q3 Marketing Campaign Budget" required>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Department*</label>
                            <select name="department" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-brand-primary focus:border-transparent" required>
                                <option value="">Select Department</option>
                                <?php foreach ($departments as $dept): ?>
                                    <option value="<?= safe_output($dept['id']) ?>"><?= safe_output($dept['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Fiscal Year*</label>
                            <select name="fiscal_year" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-brand-primary focus:border-transparent" required>
                                <option value="">Select Fiscal Year</option>
                                <?php foreach ($fiscal_years as $year): ?>
                                    <option value="<?= safe_output($year['fiscal_year']) ?>"><?= safe_output($year['fiscal_year']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Total Amount (₱)*</label>
                        <input type="number" name="total_amount" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-brand-primary focus:border-transparent" 
                               step="0.01" min="0.01" placeholder="0.00" required>
                        <p class="text-sm text-gray-500 mt-1">This amount will be allocated for department spending</p>
                    </div>
                    <div class="flex gap-3 pt-4">
                        <button type="button" class="flex-1 px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition close-modal">Cancel</button>
                        <button type="submit" class="flex-1 px-4 py-2 bg-brand-primary text-white rounded-lg hover:bg-brand-primary-hover transition">Create Budget Allocation</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Budget Modal -->
    <div id="edit-proposal-modal" class="modal">
        <div class="modal-content">
            <div class="flex justify-between items-center mb-6">
                <h2 class="text-xl font-bold text-gray-800">Edit Budget Allocation</h2>
                <button class="close-modal text-gray-400 hover:text-gray-600 text-2xl">&times;</button>
            </div>
            <div id="edit-modal-content">
                <!-- Content will be loaded via JavaScript -->
            </div>
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
        
        // Open edit modal
        window.openEditModal = function(proposalId) {
            const modal = document.getElementById('edit-proposal-modal');
            const content = document.getElementById('edit-modal-content');
            
            content.innerHTML = `
                <div class="text-center py-8">
                    <div class="spinner mx-auto mb-4"></div>
                    <p>Loading budget details...</p>
                </div>
            `;
            
            modal.style.display = 'block';
            document.body.style.overflow = 'hidden';
            
            fetch(`budget_proposal_edit.php?proposal_id=${proposalId}`)
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }
                    return response.text();
                })
                .then(html => {
                    content.innerHTML = html;
                })
                .catch(error => {
                    console.error('Error loading edit form:', error);
                    content.innerHTML = `
                        <div class="text-center py-8 text-red-600">
                            <i class="fas fa-exclamation-triangle text-2xl mb-2"></i>
                            <p>Error loading budget details. Please try again.</p>
                        </div>
                    `;
                });
        };
        
        // Global functions
        window.openModal = function(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.style.display = 'block';
                document.body.style.overflow = 'hidden';
            }
        };
        
        window.closeModal = function(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.style.display = 'none';
                document.body.style.overflow = 'auto';
            }
        };
        
        window.printProposals = function() {
            window.print();
        };
        
        window.exportToExcel = function() {
            alert('Excel export functionality would be implemented here');
        };
        
        // Initialize all close modal buttons
        document.querySelectorAll('.close-modal').forEach(btn => {
            btn.addEventListener('click', function() {
                const modal = this.closest('.modal');
                if (modal) {
                    modal.style.display = 'none';
                    document.body.style.overflow = 'auto';
                }
            });
        });
    });
    </script>
</body>
</html>