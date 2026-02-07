<?php
declare(strict_types=1);
session_start();

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', "1");

// Check if user is logged in
if (empty($_SESSION['user_id'] ?? null)) {
    header("Location: index.php");
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$user_role = $_SESSION['user_role'] ?? 'user';

// Initialize hide numbers session variable
if (!isset($_SESSION['hide_numbers'])) {
    $_SESSION['hide_numbers'] = false;
}

// Handle hide numbers toggle via POST
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

// Initialize data arrays with default values
$budget_summary = [
    'total_proposals' => 0,
    'approved_proposals' => 0,
    'rejected_proposals' => 0,
    'pending_proposals' => 0,
    'total_approved_budget' => 0,
    'avg_approved_budget' => 0,
    'max_approved_budget' => 0,
    'min_approved_budget' => 0
];
$department_reports = [];
$category_reports = [];
$approval_metrics = [];
$monthly_trends = [];
$fiscal_years = [];
$departments = [];

// Handle report filtering - sanitize inputs
$report_type = $_GET['report_type'] ?? 'summary';
$fiscal_year = isset($_GET['fiscal_year']) ? (int)$_GET['fiscal_year'] : (int)date('Y');
$department = $_GET['department'] ?? '';

try {
    // Budget Summary Report
    $summary_stmt = $db->prepare("
        SELECT 
            COUNT(*) as total_proposals,
            SUM(CASE WHEN status = 'Approved' THEN 1 ELSE 0 END) as approved_proposals,
            SUM(CASE WHEN status = 'Rejected' THEN 1 ELSE 0 END) as rejected_proposals,
            SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) as pending_proposals,
            COALESCE(SUM(CASE WHEN status = 'Approved' THEN total_amount ELSE 0 END), 0) as total_approved_budget,
            COALESCE(AVG(CASE WHEN status = 'Approved' THEN total_amount ELSE NULL END), 0) as avg_approved_budget,
            COALESCE(MAX(CASE WHEN status = 'Approved' THEN total_amount ELSE 0 END), 0) as max_approved_budget,
            COALESCE(MIN(CASE WHEN status = 'Approved' AND total_amount > 0 THEN total_amount ELSE NULL END), 0) as min_approved_budget
        FROM budget_proposals 
        WHERE fiscal_year = ?
    ");
    $summary_stmt->execute([$fiscal_year]);
    $budget_summary_result = $summary_stmt->fetch();
    if ($budget_summary_result) {
        $budget_summary = array_merge($budget_summary, $budget_summary_result);
    }

    // Department-wise Reports
    $dept_stmt = $db->prepare("
        SELECT 
            d.name as department_name,
            COUNT(bp.id) as proposal_count,
            SUM(CASE WHEN bp.status = 'Approved' THEN 1 ELSE 0 END) as approved_count,
            COALESCE(SUM(CASE WHEN bp.status = 'Approved' THEN bp.total_amount ELSE 0 END), 0) as approved_budget,
            SUM(CASE WHEN bp.status = 'Rejected' THEN 1 ELSE 0 END) as rejected_count,
            COALESCE(AVG(CASE WHEN bp.status = 'Approved' THEN bp.total_amount ELSE NULL END), 0) as avg_budget,
            CASE 
                WHEN (SELECT COALESCE(SUM(total_amount), 0) FROM budget_proposals WHERE status = 'Approved' AND fiscal_year = ?) > 0 
                THEN (COALESCE(SUM(CASE WHEN bp.status = 'Approved' THEN bp.total_amount ELSE 0 END), 0) / 
                     (SELECT SUM(total_amount) FROM budget_proposals WHERE status = 'Approved' AND fiscal_year = ?)) * 100 
                ELSE 0 
            END as budget_percentage
        FROM departments d
        LEFT JOIN budget_proposals bp ON d.id = bp.department AND bp.fiscal_year = ?
        WHERE d.status = 'Active'
        GROUP BY d.id, d.name
        ORDER BY approved_budget DESC
    ");
    $dept_stmt->execute([$fiscal_year, $fiscal_year, $fiscal_year]);
    $department_reports = $dept_stmt->fetchAll();

    // Category-wise Analysis
    $cat_stmt = $db->prepare("
        SELECT 
            bc.name as category_name,
            bc.type as category_type,
            COUNT(bi.id) as item_count,
            COALESCE(SUM(bi.total_cost), 0) as total_budget,
            COALESCE(AVG(bi.total_cost), 0) as avg_cost,
            COALESCE(MAX(bi.total_cost), 0) as max_cost,
            CASE 
                WHEN (SELECT COALESCE(SUM(total_cost), 0) FROM budget_items bi2 
                      JOIN budget_proposals bp2 ON bi2.proposal_id = bp2.id 
                      WHERE bp2.fiscal_year = ? AND bp2.status = 'Approved') > 0
                THEN (COALESCE(SUM(bi.total_cost), 0) / 
                     (SELECT SUM(total_cost) FROM budget_items bi2 
                      JOIN budget_proposals bp2 ON bi2.proposal_id = bp2.id 
                      WHERE bp2.fiscal_year = ? AND bp2.status = 'Approved')) * 100 
                ELSE 0 
            END as percentage
        FROM budget_categories bc
        LEFT JOIN budget_items bi ON bc.name = bi.category
        LEFT JOIN budget_proposals bp ON bi.proposal_id = bp.id AND bp.fiscal_year = ? AND bp.status = 'Approved'
        GROUP BY bc.name, bc.type
        HAVING total_budget > 0
        ORDER BY total_budget DESC
    ");
    $cat_stmt->execute([$fiscal_year, $fiscal_year, $fiscal_year]);
    $category_reports_raw = $cat_stmt->fetchAll();
    
    // Convert percentage values to float to prevent type errors
    $category_reports = array_map(function($category) {
        $category['percentage'] = (float)$category['percentage'];
        $category['total_budget'] = (float)$category['total_budget'];
        $category['avg_cost'] = (float)$category['avg_cost'];
        $category['max_cost'] = (float)$category['max_cost'];
        $category['item_count'] = (int)$category['item_count'];
        return $category;
    }, $category_reports_raw);

    // Approval Metrics
    $approval_stmt = $db->prepare("
        SELECT 
            COALESCE(approver_role, 'Unknown') as approver_role,
            COUNT(*) as decision_count,
            SUM(CASE WHEN action = 'Approved' THEN 1 ELSE 0 END) as approved_count,
            SUM(CASE WHEN action = 'Rejected' THEN 1 ELSE 0 END) as rejected_count,
            SUM(CASE WHEN action = 'Revision Requested' THEN 1 ELSE 0 END) as revision_count,
            COALESCE(AVG(TIMESTAMPDIFF(HOUR, bp.submitted_date, wa.approved_at)), 0) as avg_approval_time_hours
        FROM workflow_approvals wa
        JOIN budget_proposals bp ON wa.proposal_id = bp.id
        LEFT JOIN workflow_steps ws ON wa.step_completed = ws.step_order AND bp.department = ws.department
        WHERE YEAR(bp.submitted_date) = ?
        GROUP BY approver_role
        ORDER BY decision_count DESC
    ");
    $approval_stmt->execute([$fiscal_year]);
    $approval_metrics = $approval_stmt->fetchAll();

    // Monthly Budget Trends
    $monthly_stmt = $db->prepare("
        SELECT 
            MONTH(submitted_date) as month,
            YEAR(submitted_date) as year,
            COUNT(*) as proposal_count,
            COALESCE(SUM(total_amount), 0) as total_budget,
            COALESCE(AVG(total_amount), 0) as avg_budget
        FROM budget_proposals 
        WHERE YEAR(submitted_date) = ?
        AND status = 'Approved'
        GROUP BY YEAR(submitted_date), MONTH(submitted_date)
        ORDER BY year, month
    ");
    $monthly_stmt->execute([$fiscal_year]);
    $monthly_trends = $monthly_stmt->fetchAll();

    // Get fiscal years for dropdown
    $years_stmt = $db->query("
        SELECT DISTINCT fiscal_year 
        FROM budget_proposals 
        WHERE fiscal_year IS NOT NULL 
        AND fiscal_year != ''
        ORDER BY fiscal_year DESC
    ");
    $fiscal_years = $years_stmt ? $years_stmt->fetchAll() : [];

    // If no fiscal years found in database, create default options
    if (empty($fiscal_years)) {
        $fiscal_years = [
            ['fiscal_year' => date('Y')],
            ['fiscal_year' => date('Y')-1]
        ];
    }

    // Get departments for dropdown
    $dept_dropdown_stmt = $db->query("SELECT id, name FROM departments WHERE status = 'Active' ORDER BY name");
    $departments = $dept_dropdown_stmt ? $dept_dropdown_stmt->fetchAll() : [];

} catch (Exception $e) {
    error_log("Budget reports data load error: " . $e->getMessage());
    // Ensure arrays are initialized even on error
    $budget_summary = $budget_summary ?? [];
    $department_reports = $department_reports ?? [];
    $category_reports = $category_reports ?? [];
    $approval_metrics = $approval_metrics ?? [];
    $monthly_trends = $monthly_trends ?? [];
    
    // Create default fiscal years on error
    $fiscal_years = [
        ['fiscal_year' => date('Y')],
        ['fiscal_year' => date('Y')-1]
    ];
    
    $departments = $departments ?? [];
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
    if ($value === null || $value === '') {
        return $default;
    }
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

// Safe number format function to prevent type errors
function safeNumberFormat($value, $decimals = 2) {
    if (!is_numeric($value)) {
        $value = 0;
    }
    return number_format((float)$value, $decimals);
}

// Function to mask numbers with dots (•)
function maskNumber($number, $masked = null) {
    global $_SESSION;
    if ($masked === null) {
        $masked = $_SESSION['hide_numbers'] ?? false;
    }
    
    if (!$masked) {
        // Ensure the input is a valid number before formatting
        $floatValue = (float)$number;
        if (is_numeric($number) || is_float($floatValue) || is_int($floatValue)) {
            return safeNumberFormat($floatValue, 2);
        } else {
            return safeNumberFormat(0, 2);
        }
    }
    
    $numberStr = (string)$number;
    $parts = explode('.', $numberStr);
    $integerPart = $parts[0];
    
    // Mask the integer part with dots (•)
    return str_repeat('•', max(3, strlen($integerPart)));
}

// Format amount with PHP symbol
function formatAmount($amount) {
    global $_SESSION;
    $masked = $_SESSION['hide_numbers'] ?? false;
    
    if ($masked) {
        $numberStr = (string)$amount;
        $parts = explode('.', $numberStr);
        $integerPart = $parts[0];
        return '₱' . str_repeat('•', max(3, strlen($integerPart)));
    }
    
    // Ensure $amount is a valid number before formatting
    $floatAmount = (float)$amount;
    if (is_numeric($amount) || is_float($floatAmount) || is_int($floatAmount)) {
        return '₱' . safeNumberFormat($floatAmount, 2);
    } else {
        return '₱' . safeNumberFormat(0, 2);
    }
}

// Function to mask percentage with dots
function maskPercentage($value) {
    global $_SESSION;
    $masked = $_SESSION['hide_numbers'] ?? false;
    
    if ($masked) {
        return '•••••%';
    }
    return safeNumberFormat((float)$value, 1) . '%';
}

// Function to mask hours with dots
function maskHours($value) {
    global $_SESSION;
    $masked = $_SESSION['hide_numbers'] ?? false;
    
    if ($masked) {
        return '••••• hours';
    }
    return safeNumberFormat((float)$value, 1) . ' hours';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Budget Reports | Financial Dashboard</title>

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
        
        .variance-positive {
            background-color: rgba(34, 197, 94, 0.1);
            color: #16A34A;
        }
        
        .variance-negative {
            background-color: rgba(239, 68, 68, 0.1);
            color: #DC2626;
        }
        
        .budget-actual-row {
            transition: background-color 0.2s;
        }
        
        .budget-actual-row:hover {
            background-color: #f9fafb;
        }
        
        .amount-masked {
            font-family: monospace;
            letter-spacing: 2px;
        }
        
        .report-filter {
            background: white;
            border-radius: 0.75rem;
            padding: 1.5rem;
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
            border: 1px solid #e5e7eb;
        }
        
        .form-group {
            margin-bottom: 1rem;
        }
        
        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: #374151;
        }
        
        .form-input, .form-select, .form-textarea {
            width: 100%;
            padding: 0.625rem;
            border: 1px solid #d1d5db;
            border-radius: 0.5rem;
            font-size: 0.875rem;
        }
        
        .form-input:focus, .form-select:focus, .form-textarea:focus {
            outline: none;
            border-color: #059669;
            box-shadow: 0 0 0 3px rgba(5, 150, 105, 0.1);
        }
        
        .data-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
        }
        
        .data-table th {
            padding: 1rem;
            text-align: left;
            background-color: #f9fafb;
            font-weight: 600;
            color: #374151;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .data-table td {
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid #f3f4f6;
        }
        
        .data-table tr:last-child td {
            border-bottom: none;
        }
        
        .tab-container {
            display: flex;
            border-bottom: 1px solid #e5e7eb;
            margin-bottom: 1rem;
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
        
        .comparison-positive {
            color: #10B981;
        }
        
        .comparison-negative {
            color: #EF4444;
        }
        
        .masked-dots {
            font-family: monospace;
            letter-spacing: 2px;
            color: #6b7280;
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
                            <a href="approval_workflow.php" class="block px-3 py-1.5 rounded-lg text-xs text-gray-600 hover:bg-green-50 hover:text-brand-primary transition-all duration-200 hover:translate-x-1">
                                Approval Workflow
                            </a>
                            <a href="budget_vs_actual.php" class="block px-3 py-1.5 rounded-lg text-xs text-gray-600 hover:bg-green-50 hover:text-brand-primary transition-all duration-200 hover:translate-x-1">
                                Budget vs Actual
                            </a>
                            <a href="budget_reports.php" class="block px-3 py-1.5 rounded-lg text-xs bg-emerald-50 text-brand-primary font-medium border border-emerald-100 hover:bg-emerald-100 hover:border-emerald-200 transition-all duration-200 hover:translate-x-1">
                                <span class="flex items-center justify-between">
                                    Budget Reports
                                    <span class="inline-flex w-1.5 h-1.5 rounded-full bg-emerald-400"></span>
                                </span>
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
                        Budget Reports
                    </h1>
                    <p class="text-xs text-gray-500">
                        Welcome Back, <?php echo htmlspecialchars($user['name']); ?>!
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
                <form method="POST" id="hide-numbers-form" class="inline">
                    <input type="hidden" name="toggle_hide_numbers" value="1">
                    <button type="submit" class="w-10 h-10 rounded-xl hover:bg-gray-100 active:bg-gray-200 transition flex items-center justify-center relative"
                            title="<?= $_SESSION['hide_numbers'] ? 'Show Numbers' : 'Hide Numbers' ?>">
                        <i class='<?= $_SESSION['hide_numbers'] ? 'fa-solid fa-eye' : 'fa-solid fa-eye-slash'; ?> text-gray-600'></i>
                    </button>
                </form>

                <!-- Notifications -->
                <button id="notification-btn" class="w-10 h-10 rounded-xl hover:bg-gray-100 active:bg-gray-200 transition flex items-center justify-center relative">
                    <i class="fa-solid fa-bell text-gray-600"></i>
                    <span class="notification-badge"></span>
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
            <!-- Dashboard View -->
            <div class="space-y-6">
                <!-- Success/Error Messages -->
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

                <!-- Report Filters -->
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                    <h3 class="text-lg font-bold text-gray-800 mb-4">Report Parameters</h3>
                    <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4">
                        <div class="form-group">
                            <label class="form-label">Report Type</label>
                            <select name="report_type" class="form-select">
                                <option value="summary" <?= $report_type === 'summary' ? 'selected' : '' ?>>Summary Report</option>
                                <option value="department" <?= $report_type === 'department' ? 'selected' : '' ?>>Department Analysis</option>
                                <option value="category" <?= $report_type === 'category' ? 'selected' : '' ?>>Category Analysis</option>
                                <option value="approval" <?= $report_type === 'approval' ? 'selected' : '' ?>>Approval Metrics</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Fiscal Year</label>
                            <select name="fiscal_year" class="form-select">
                                <?php if (empty($fiscal_years)): ?>
                                    <!-- Fallback if no fiscal years found -->
                                    <option value="<?= date('Y') ?>" selected><?= date('Y') ?></option>
                                    <option value="<?= date('Y')-1 ?>"><?= date('Y')-1 ?></option>
                                <?php else: ?>
                                    <?php foreach ($fiscal_years as $year): ?>
                                        <option value="<?= safe_output($year['fiscal_year']) ?>" 
                                            <?= $fiscal_year == $year['fiscal_year'] ? 'selected' : '' ?>>
                                            <?= safe_output($year['fiscal_year']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Department</label>
                            <select name="department" class="form-select">
                                <option value="">All Departments</option>
                                <?php foreach ($departments as $dept): ?>
                                    <option value="<?= safe_output($dept['id']) ?>" <?= $department == $dept['id'] ? 'selected' : '' ?>>
                                        <?= safe_output($dept['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group flex items-end">
                            <button type="submit" class="w-full px-4 py-3 bg-brand-primary text-white rounded-lg hover:bg-brand-primary-hover transition flex items-center justify-center">
                                <i class="fa-solid fa-filter mr-2"></i>Generate Report
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Summary Report -->
                <?php if ($report_type === 'summary'): ?>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                        <?php 
                        $summary_stats = [
                            ['icon' => 'bx-file', 'label' => 'Total Proposals', 'value' => $budget_summary['total_proposals'], 'color' => 'green', 'stat' => 'proposals', 'type' => 'count'],
                            ['icon' => 'bx-check-circle', 'label' => 'Approved Proposals', 'value' => $budget_summary['approved_proposals'] ?? 0, 'color' => 'green', 'stat' => 'approved', 'type' => 'count'],
                            ['icon' => 'bx-money', 'label' => 'Total Approved Budget', 'value' => $budget_summary['total_approved_budget'] ?? 0, 'color' => 'blue', 'stat' => 'budget', 'type' => 'amount'],
                            ['icon' => 'bx-calculator', 'label' => 'Average Budget', 'value' => $budget_summary['avg_approved_budget'] ?? 0, 'color' => 'purple', 'stat' => 'avg', 'type' => 'amount']
                        ];
                        
                        foreach($summary_stats as $stat): 
                            $bgColors = [
                                'green' => 'bg-green-100',
                                'red' => 'bg-red-100',
                                'yellow' => 'bg-yellow-100',
                                'blue' => 'bg-blue-100',
                                'purple' => 'bg-purple-100'
                            ];
                            $textColors = [
                                'green' => 'text-green-600',
                                'red' => 'text-red-600',
                                'yellow' => 'text-yellow-600',
                                'blue' => 'text-blue-600',
                                'purple' => 'text-purple-600'
                            ];
                            
                            // Determine display value based on type
                            if ($stat['type'] === 'count') {
                                $actualValue = safeNumberFormat($stat['value'], 0);
                                $displayValue = $actualValue; // Hindi nagdi-display ng dots para sa counts
                            } else {
                                $actualValue = formatAmount($stat['value']);
                                $displayValue = $_SESSION['hide_numbers'] ? '₱••••••' : $actualValue;
                            }
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
                                               data-value="<?php echo $actualValue; ?>"
                                               data-stat="<?php echo $stat['stat']; ?>"
                                               data-type="<?php echo $stat['type']; ?>">
                                                <?php echo $displayValue; ?>
                                            </p>
                                        </div>
                                        <?php if ($stat['type'] === 'amount'): // Hide button for amounts only ?>
                                        <button class="stat-toggle text-gray-400 hover:text-brand-primary transition"
                                                data-stat="<?php echo $stat['stat']; ?>"
                                                title="<?= $_SESSION['hide_numbers'] ? 'Show Amount' : 'Hide Amount' ?>">
                                            <i class="<?= $_SESSION['hide_numbers'] ? 'fa-solid fa-eye' : 'fa-solid fa-eye-slash'; ?>"></i>
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                            <h3 class="text-lg font-bold text-gray-800 mb-6">Proposal Status Distribution</h3>
                            <div class="chart-container">
                                <canvas id="statusChart"></canvas>
                            </div>
                        </div>
                        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                            <h3 class="text-lg font-bold text-gray-800 mb-6">Monthly Budget Trends</h3>
                            <div class="chart-container">
                                <canvas id="monthlyTrendChart"></canvas>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                        <div class="p-6 border-b border-gray-100">
                            <div class="flex justify-between items-center">
                                <div class="flex items-center gap-3">
                                    <h3 class="text-lg font-bold text-gray-800">Budget Performance Summary</h3>
                                    <button id="summary-visibility-toggle" class="text-gray-500 hover:text-brand-primary transition"
                                            title="<?= $_SESSION['hide_numbers'] ? 'Show Amounts' : 'Hide Amounts' ?>">
                                        <i class="<?= $_SESSION['hide_numbers'] ? 'fa-solid fa-eye' : 'fa-solid fa-eye-slash'; ?>"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Metric</th>
                                        <th>Count</th>
                                        <th>Amount</th>
                                        <th>Percentage</th>
                                        <th>Trend</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $total_proposals = $budget_summary['total_proposals'];
                                    $approved_rate = $total_proposals > 0 ? ($budget_summary['approved_proposals'] / $total_proposals * 100) : 0;
                                    $rejected_rate = $total_proposals > 0 ? ($budget_summary['rejected_proposals'] / $total_proposals * 100) : 0;
                                    $pending_rate = $total_proposals > 0 ? ($budget_summary['pending_proposals'] / $total_proposals * 100) : 0;
                                    ?>
                                    <!-- Total Proposals -->
                                    <tr class="budget-actual-row">
                                        <td class="font-medium">Total Proposals</td>
                                        <td><?= $budget_summary['total_proposals'] ?></td> <!-- No dots for count -->
                                        <td>-</td>
                                        <td>100%</td>
                                        <td class="comparison-positive">Base</td>
                                    </tr>
                                    
                                    <!-- Approved Proposals -->
                                    <tr class="budget-actual-row">
                                        <td class="font-medium">Approved Proposals</td>
                                        <td><?= $budget_summary['approved_proposals'] ?? 0 ?></td> <!-- No dots for count -->
                                        <td class="amount-masked summary-amount" data-value="<?= formatAmount($budget_summary['total_approved_budget'] ?? 0) ?>">
                                            <?= $_SESSION['hide_numbers'] ? '₱••••••' : formatAmount($budget_summary['total_approved_budget'] ?? 0) ?>
                                        </td>
                                        <td><?= maskPercentage($approved_rate) ?></td>
                                        <td class="comparison-positive">
                                            <span class="amount-masked summary-amount" data-value="<?= formatAmount($budget_summary['avg_approved_budget'] ?? 0) ?> avg">
                                                <?= $_SESSION['hide_numbers'] ? '₱•••••• avg' : formatAmount($budget_summary['avg_approved_budget'] ?? 0) . ' avg' ?>
                                            </span>
                                        </td>
                                    </tr>

                                    <!-- Rejected Proposals -->
                                    <tr class="budget-actual-row">
                                        <td class="font-medium">Rejected Proposals</td>
                                        <td><?= $budget_summary['rejected_proposals'] ?? 0 ?></td> <!-- No dots for count -->
                                        <td>-</td>
                                        <td><?= maskPercentage($rejected_rate) ?></td>
                                        <td class="comparison-negative">Rejected</td>
                                    </tr>

                                    <!-- Pending Proposals -->
                                    <tr class="budget-actual-row">
                                        <td class="font-medium">Pending Proposals</td>
                                        <td><?= $budget_summary['pending_proposals'] ?? 0 ?></td> <!-- No dots for count -->
                                        <td>-</td>
                                        <td><?= maskPercentage($pending_rate) ?></td>
                                        <td class="comparison-negative">Pending</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                <!-- Department Analysis -->
                <?php elseif ($report_type === 'department'): ?>
                    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 mb-6">
                        <h3 class="text-lg font-bold text-gray-800 mb-6">Department Budget Analysis</h3>
                        <div class="chart-container">
                            <canvas id="departmentChart"></canvas>
                        </div>
                    </div>

                    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                        <div class="p-6 border-b border-gray-100">
                            <div class="flex justify-between items-center">
                                <div class="flex items-center gap-3">
                                    <h3 class="text-lg font-bold text-gray-800">Department Performance Details</h3>
                                    <button id="dept-visibility-toggle" class="text-gray-500 hover:text-brand-primary transition"
                                            title="<?= $_SESSION['hide_numbers'] ? 'Show Amounts' : 'Hide Amounts' ?>">
                                        <i class="<?= $_SESSION['hide_numbers'] ? 'fa-solid fa-eye' : 'fa-solid fa-eye-slash'; ?>"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Department</th>
                                        <th>Total Proposals</th>
                                        <th>Approved</th>
                                        <th>Rejected</th>
                                        <th>Approved Budget</th>
                                        <th>Average Budget</th>
                                        <th>Budget Share</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($department_reports)): ?>
                                        <tr>
                                            <td colspan="7" class="text-center py-4 text-gray-500">
                                                <i class='bx bx-folder-open text-3xl mb-2 text-gray-300'></i>
                                                <div>No department data available for the selected criteria.</div>
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($department_reports as $dept): ?>
                                            <tr class="budget-actual-row">
                                                <td class="font-medium"><?= safe_output($dept['department_name'] ?? 'Unknown') ?></td>
                                                <td>
                                                    <?= $dept['proposal_count'] ?? 0 ?> <!-- No dots for count -->
                                                </td>
                                                <td class="comparison-positive">
                                                    <?= $dept['approved_count'] ?? 0 ?> <!-- No dots for count -->
                                                </td>
                                                <td class="comparison-negative">
                                                    <?= $dept['rejected_count'] ?? 0 ?> <!-- No dots for count -->
                                                </td>
                                                <td class="font-semibold amount-masked dept-amount" data-value="<?= formatAmount($dept['approved_budget'] ?? 0) ?>">
                                                    <?= $_SESSION['hide_numbers'] ? '₱••••••' : formatAmount($dept['approved_budget'] ?? 0) ?>
                                                </td>
                                                <td class="amount-masked dept-amount" data-value="<?= formatAmount($dept['avg_budget'] ?? 0) ?>">
                                                    <?= $_SESSION['hide_numbers'] ? '₱••••••' : formatAmount($dept['avg_budget'] ?? 0) ?>
                                                </td>
                                                <td>
                                                    <div class="flex items-center">
                                                        <div class="w-20 bg-gray-200 rounded-full h-2 mr-2">
                                                            <div class="h-2 rounded-full bg-brand-primary" style="width: <?= $dept['budget_percentage'] ?? 0 ?>%"></div>
                                                        </div>
                                                        <span class="text-sm"><?= maskPercentage($dept['budget_percentage'] ?? 0) ?></span>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                <!-- Category Analysis -->
                <?php elseif ($report_type === 'category'): ?>
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                            <h3 class="text-lg font-bold text-gray-800 mb-6">Budget by Category Type</h3>
                            <div class="chart-container">
                                <canvas id="categoryTypeChart"></canvas>
                            </div>
                        </div>
                        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                            <h3 class="text-lg font-bold text-gray-800 mb-6">Top Budget Categories</h3>
                            <div class="chart-container">
                                <canvas id="topCategoriesChart"></canvas>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                        <div class="p-6 border-b border-gray-100">
                            <div class="flex justify-between items-center">
                                <div class="flex items-center gap-3">
                                    <h3 class="text-lg font-bold text-gray-800">Category Budget Details</h3>
                                    <button id="category-visibility-toggle" class="text-gray-500 hover:text-brand-primary transition"
                                            title="<?= $_SESSION['hide_numbers'] ? 'Show Amounts' : 'Hide Amounts' ?>">
                                        <i class="<?= $_SESSION['hide_numbers'] ? 'fa-solid fa-eye' : 'fa-solid fa-eye-slash'; ?>"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Category</th>
                                        <th>Type</th>
                                        <th>Item Count</th>
                                        <th>Total Budget</th>
                                        <th>Average Cost</th>
                                        <th>Maximum Cost</th>
                                        <th>Budget Percentage</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($category_reports)): ?>
                                        <tr>
                                            <td colspan="7" class="text-center py-4 text-gray-500">
                                                <i class='bx bx-folder-open text-3xl mb-2 text-gray-300'></i>
                                                <div>No category data available for the selected criteria.</div>
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($category_reports as $category): ?>
                                            <tr class="budget-actual-row">
                                                <td class="font-medium"><?= safe_output($category['category_name']) ?></td>
                                                <td>
                                                    <span class="status-badge <?= $category['category_type'] === 'Revenue' ? 'variance-positive' : 'variance-negative' ?>">
                                                        <?= safe_output($category['category_type']) ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?= $category['item_count'] ?> <!-- No dots for count -->
                                                </td>
                                                <td class="font-semibold amount-masked category-amount" data-value="<?= formatAmount($category['total_budget'] ?? 0) ?>">
                                                    <?= $_SESSION['hide_numbers'] ? '₱••••••' : formatAmount($category['total_budget'] ?? 0) ?>
                                                </td>
                                                <td class="amount-masked category-amount" data-value="<?= formatAmount($category['avg_cost'] ?? 0) ?>">
                                                    <?= $_SESSION['hide_numbers'] ? '₱••••••' : formatAmount($category['avg_cost'] ?? 0) ?>
                                                </td>
                                                <td class="amount-masked category-amount" data-value="<?= formatAmount($category['max_cost'] ?? 0) ?>">
                                                    <?= $_SESSION['hide_numbers'] ? '₱••••••' : formatAmount($category['max_cost'] ?? 0) ?>
                                                </td>
                                                <td><?= maskPercentage($category['percentage']) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                <!-- Approval Metrics -->
                <?php elseif ($report_type === 'approval'): ?>
                    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 mb-6">
                        <h3 class="text-lg font-bold text-gray-800 mb-6">Approval Workflow Performance</h3>
                        <div class="chart-container">
                            <canvas id="approvalMetricsChart"></canvas>
                        </div>
                    </div>

                    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                        <div class="p-6 border-b border-gray-100">
                            <div class="flex justify-between items-center">
                                <div class="flex items-center gap-3">
                                    <h3 class="text-lg font-bold text-gray-800">Approval Decision Details</h3>
                                    <button id="approval-visibility-toggle" class="text-gray-500 hover:text-brand-primary transition"
                                            title="<?= $_SESSION['hide_numbers'] ? 'Show Values' : 'Hide Values' ?>">
                                        <i class="<?= $_SESSION['hide_numbers'] ? 'fa-solid fa-eye' : 'fa-solid fa-eye-slash'; ?>"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Approver Role</th>
                                        <th>Total Decisions</th>
                                        <th>Approved</th>
                                        <th>Rejected</th>
                                        <th>Revision Requested</th>
                                        <th>Approval Rate</th>
                                        <th>Avg. Decision Time</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($approval_metrics)): ?>
                                        <tr>
                                            <td colspan="7" class="text-center py-4 text-gray-500">
                                                <i class='bx bx-folder-open text-3xl mb-2 text-gray-300'></i>
                                                <div>No approval metrics available for the selected criteria.</div>
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($approval_metrics as $metric): 
                                            $approval_rate = $metric['decision_count'] > 0 ? ($metric['approved_count'] / $metric['decision_count'] * 100) : 0;
                                        ?>
                                            <tr class="budget-actual-row">
                                                <td class="font-medium"><?= safe_output($metric['approver_role']) ?></td>
                                                <td>
                                                    <?= $metric['decision_count'] ?> <!-- No dots for count -->
                                                </td>
                                                <td class="comparison-positive">
                                                    <?= $metric['approved_count'] ?> <!-- No dots for count -->
                                                </td>
                                                <td class="comparison-negative">
                                                    <?= $metric['rejected_count'] ?> <!-- No dots for count -->
                                                </td>
                                                <td>
                                                    <?= $metric['revision_count'] ?> <!-- No dots for count -->
                                                </td>
                                                <td>
                                                    <div class="flex items-center">
                                                        <div class="w-16 bg-gray-200 rounded-full h-2 mr-2">
                                                            <div class="h-2 rounded-full bg-brand-primary" style="width: <?= $approval_rate ?>%"></div>
                                                        </div>
                                                        <span class="text-sm"><?= maskPercentage($approval_rate) ?></span>
                                                    </div>
                                                </td>
                                                <td><?= maskHours($metric['avg_approval_time_hours']) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Report Actions -->
                <div class="flex justify-end space-x-2 mt-6">
                    <button class="px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 transition flex items-center gap-2" onclick="window.print()">
                        <i class="fas fa-print"></i> Print Report
                    </button>
                    
                    <button class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition flex items-center gap-2" onclick="exportToExcel()">
                        <i class="fas fa-file-excel"></i> Export Excel
                    </button>
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
                <div class="text-center text-gray-500 py-4">Loading notifications...</div>
            </div>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        console.log('DOM loaded - initializing all functionality');
        
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

        // Handle hide numbers form submission with AJAX
        const hideNumbersForm = document.getElementById('hide-numbers-form');
        if (hideNumbersForm) {
            hideNumbersForm.addEventListener('submit', function(e) {
                e.preventDefault();
                
                const formData = new FormData(this);
                formData.append('ajax', '1');
                
                const button = this.querySelector('button[type="submit"]');
                const originalHtml = button.innerHTML;
                button.innerHTML = '<i class="fas fa-spinner fa-spin text-xl"></i>';
                button.disabled = true;
                
                fetch('', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const icon = button.querySelector('i');
                        if (data.hide_numbers) {
                            icon.className = 'fa-solid fa-eye text-xl';
                            button.title = 'Show Numbers';
                        } else {
                            icon.className = 'fa-solid fa-eye-slash text-xl';
                            button.title = 'Hide Numbers';
                        }
                        window.location.reload();
                    } else {
                        alert('Error toggling number visibility');
                        button.innerHTML = originalHtml;
                        button.disabled = false;
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error toggling number visibility');
                    button.innerHTML = originalHtml;
                    button.disabled = false;
                });
            });
        }

        // Initialize charts based on report type
        const reportType = '<?= $report_type ?>';
        initializeCharts(reportType);
        
        // Initialize table visibility
        initializeTableVisibility();
        
        // Initialize stats visibility
        initializeStatsVisibility();

        // Common features
        initializeCommonFeatures();
    });

    function initializeCharts(reportType) {
        switch(reportType) {
            case 'summary':
                initializeSummaryCharts();
                break;
            case 'department':
                initializeDepartmentCharts();
                break;
            case 'category':
                initializeCategoryCharts();
                break;
            case 'approval':
                initializeApprovalCharts();
                break;
        }
    }

    function initializeSummaryCharts() {
        // Status Distribution Chart
        const statusCtx = document.getElementById('statusChart');
        if (statusCtx) {
            const statusData = {
                approved: <?= $budget_summary['approved_proposals'] ?? 0 ?>,
                rejected: <?= $budget_summary['rejected_proposals'] ?? 0 ?>,
                pending: <?= $budget_summary['pending_proposals'] ?? 0 ?>
            };
            
            // Only create chart if we have data
            if (statusData.approved > 0 || statusData.rejected > 0 || statusData.pending > 0) {
                new Chart(statusCtx.getContext('2d'), {
                    type: 'doughnut',
                    data: {
                        labels: ['Approved', 'Rejected', 'Pending'],
                        datasets: [{
                            data: [statusData.approved, statusData.rejected, statusData.pending],
                            backgroundColor: ['#10B981', '#EF4444', '#F59E0B'],
                            borderWidth: 2,
                            borderColor: '#ffffff'
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'bottom'
                            }
                        }
                    }
                });
            }
        }

        // Monthly Trend Chart
        const monthlyCtx = document.getElementById('monthlyTrendChart');
        if (monthlyCtx) {
            const monthlyData = <?php echo json_encode($monthly_trends); ?>;
            
            const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
            const monthlyBudgets = new Array(12).fill(0);
            const monthlyCounts = new Array(12).fill(0);
            
            monthlyData.forEach(item => {
                const monthIndex = item.month - 1;
                monthlyBudgets[monthIndex] = parseFloat(item.total_budget) || 0;
                monthlyCounts[monthIndex] = parseInt(item.proposal_count) || 0;
            });
            
            new Chart(monthlyCtx.getContext('2d'), {
                type: 'line',
                data: {
                    labels: months,
                    datasets: [
                        {
                            label: 'Budget Amount (₱)',
                            data: monthlyBudgets,
                            borderColor: '#059669',
                            backgroundColor: 'rgba(5, 150, 105, 0.1)',
                            borderWidth: 2,
                            fill: true,
                            yAxisID: 'y'
                        },
                        {
                            label: 'Proposal Count',
                            data: monthlyCounts,
                            borderColor: '#3B82F6',
                            backgroundColor: 'rgba(59, 130, 246, 0.1)',
                            borderWidth: 2,
                            fill: true,
                            yAxisID: 'y1'
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            type: 'linear',
                            display: true,
                            position: 'left',
                            ticks: {
                                callback: function(value) {
                                    return '₱' + value.toLocaleString();
                                }
                            }
                        },
                        y1: {
                            type: 'linear',
                            display: true,
                            position: 'right',
                            grid: {
                                drawOnChartArea: false
                            }
                        }
                    }
                }
            });
        }
    }

    function initializeDepartmentCharts() {
        const deptCtx = document.getElementById('departmentChart');
        if (deptCtx) {
            const departments = <?php echo json_encode(array_column($department_reports, 'department_name')); ?>;
            const budgets = <?php echo json_encode(array_column($department_reports, 'approved_budget')); ?>;

            // Convert to numbers
            const numericBudgets = budgets.map(v => parseFloat(v) || 0);

            new Chart(deptCtx.getContext('2d'), {
                type: 'bar',
                data: {
                    labels: departments,
                    datasets: [{
                        label: 'Approved Budget (₱)',
                        data: numericBudgets,
                        backgroundColor: '#059669',
                        borderColor: '#047857',
                        borderWidth: 1,
                        borderRadius: 6,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                callback: function(value) {
                                    return '₱' + value.toLocaleString();
                                }
                            }
                        }
                    }
                }
            });
        }
    }

    function initializeCategoryCharts() {
        // Category Type Chart
        const typeCtx = document.getElementById('categoryTypeChart');
        if (typeCtx) {
            const categories = <?php echo json_encode($category_reports); ?>;
            
            const revenueTotal = categories.filter(c => c.category_type === 'Revenue')
                                         .reduce((sum, c) => sum + parseFloat(c.total_budget || 0), 0);
            const expenseTotal = categories.filter(c => c.category_type === 'Expense')
                                         .reduce((sum, c) => sum + parseFloat(c.total_budget || 0), 0);
            
            if (revenueTotal > 0 || expenseTotal > 0) {
                new Chart(typeCtx.getContext('2d'), {
                    type: 'pie',
                    data: {
                        labels: ['Revenue', 'Expense'],
                        datasets: [{
                            data: [revenueTotal, expenseTotal],
                            backgroundColor: ['#10B981', '#EF4444'],
                            borderWidth: 2,
                            borderColor: '#ffffff'
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'bottom'
                            }
                        }
                    }
                });
            }
        }

        // Top Categories Chart
        const topCtx = document.getElementById('topCategoriesChart');
        if (topCtx) {
            const categories = <?php echo json_encode($category_reports); ?>;
            const topCategories = categories.slice(0, 8); // Top 8 categories
            const categoryNames = topCategories.map(c => c.category_name);
            const categoryBudgets = topCategories.map(c => parseFloat(c.total_budget) || 0);
            
            if (categoryNames.length > 0) {
                new Chart(topCtx.getContext('2d'), {
                    type: 'bar',
                    data: {
                        labels: categoryNames,
                        datasets: [{
                            label: 'Budget (₱)',
                            data: categoryBudgets,
                            backgroundColor: categoryNames.map((_, i) => 
                                i % 2 === 0 ? '#059669' : '#3B82F6'
                            ),
                            borderWidth: 1
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        indexAxis: 'y',
                        scales: {
                            x: {
                                beginAtZero: true,
                                ticks: {
                                    callback: function(value) {
                                        return '₱' + value.toLocaleString();
                                    }
                                }
                            }
                        }
                    }
                });
            }
        }
    }

    function initializeApprovalCharts() {
        const approvalCtx = document.getElementById('approvalMetricsChart');
        if (approvalCtx) {
            const metrics = <?php echo json_encode($approval_metrics); ?>;

            const roles = metrics.map(m => m.approver_role);
            const approved = metrics.map(m => parseInt(m.approved_count) || 0);
            const rejected = metrics.map(m => parseInt(m.rejected_count) || 0);
            const revisions = metrics.map(m => parseInt(m.revision_count) || 0);
            
            if (roles.length > 0) {
                new Chart(approvalCtx.getContext('2d'), {
                    type: 'bar',
                    data: {
                        labels: roles,
                        datasets: [
                            {
                                label: 'Approved',
                                data: approved,
                                backgroundColor: '#10B981'
                            },
                            {
                                label: 'Rejected',
                                data: rejected,
                                backgroundColor: '#EF4444'
                            },
                            {
                                label: 'Revision Requested',
                                data: revisions,
                                backgroundColor: '#F59E0B'
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            y: {
                                beginAtZero: true,
                                stacked: true
                            },
                            x: {
                                stacked: true
                            }
                        }
                    }
                });
            }
        }
    }

    function initializeTableVisibility() {
        const reportType = '<?= $report_type ?>';
        let toggleBtnId, cellClass;
        
        switch(reportType) {
            case 'summary':
                toggleBtnId = 'summary-visibility-toggle';
                cellClass = 'summary-amount';
                break;
            case 'department':
                toggleBtnId = 'dept-visibility-toggle';
                cellClass = 'dept-amount';
                break;
            case 'category':
                toggleBtnId = 'category-visibility-toggle';
                cellClass = 'category-amount';
                break;
            case 'approval':
                toggleBtnId = 'approval-visibility-toggle';
                cellClass = 'approval-amount';
                break;
        }
        
        const tableToggleBtn = document.getElementById(toggleBtnId);
        const tableAmountCells = document.querySelectorAll('.' + cellClass);
        
        if (tableToggleBtn && tableAmountCells.length > 0) {
            const isHidden = <?= json_encode($_SESSION['hide_numbers'] ?? false) ?>;
            
            // Set initial state based on PHP session
            const icon = tableToggleBtn.querySelector('i');
            if (isHidden) {
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
                tableToggleBtn.title = "Show Amounts";
                // Values are already masked by PHP
            } else {
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
                tableToggleBtn.title = "Hide Amounts";
                // Values are already shown by PHP
            }
            
            // Add click event - will reload page to toggle
            tableToggleBtn.addEventListener('click', function() {
                // Submit the hide numbers form to toggle
                const form = document.getElementById('hide-numbers-form');
                if (form) {
                    form.requestSubmit();
                }
            });
        }
    }

    function initializeStatsVisibility() {
        // Individual stat toggles - only for amounts, not for counts
        const statToggles = document.querySelectorAll('.stat-toggle');
        statToggles.forEach(toggle => {
            // Check if this stat is an amount type (not count)
            const statType = toggle.getAttribute('data-stat');
            const statElement = document.querySelector(`.stat-value[data-stat="${statType}"]`);
            if (statElement && statElement.getAttribute('data-type') === 'amount') {
                // Set initial state based on PHP session
                const isHidden = <?= json_encode($_SESSION['hide_numbers'] ?? false) ?>;
                const icon = toggle.querySelector('i');
                
                if (isHidden) {
                    icon.classList.remove('fa-eye-slash');
                    icon.classList.add('fa-eye');
                    toggle.title = "Show Amount";
                } else {
                    icon.classList.remove('fa-eye');
                    icon.classList.add('fa-eye-slash');
                    toggle.title = "Hide Amount";
                }
                
                toggle.addEventListener('click', function() {
                    // Submit the hide numbers form to toggle
                    const form = document.getElementById('hide-numbers-form');
                    if (form) {
                        form.requestSubmit();
                    }
                });
            } else {
                // Hide the toggle button for count type stats
                toggle.style.display = 'none';
            }
        });
    }

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
                
                // Load notifications
                const notificationList = document.getElementById('notification-modal-list');
                if (notificationList) {
                    notificationList.innerHTML = `
                        <div class="space-y-4">
                            <div class="flex items-start gap-3 p-4 bg-gray-50 rounded-lg">
                                <div class="w-10 h-10 rounded-lg bg-blue-100 flex items-center justify-center">
                                    <i class='bx bx-bell-ring text-blue-500'></i>
                                </div>
                                <div>
                                    <div class="font-medium text-gray-800 text-sm">Budget Reports</div>
                                    <div class="text-xs text-gray-600 mt-1">Your budget reports for FY <?= $fiscal_year ?> are ready for review.</div>
                                    <div class="text-xs text-gray-400 mt-2">Just now</div>
                                </div>
                            </div>
                            <div class="flex items-start gap-3 p-4 rounded-lg">
                                <div class="w-10 h-10 rounded-lg bg-green-100 flex items-center justify-center">
                                    <i class='bx bx-check text-green-500'></i>
                                </div>
                                <div>
                                    <div class="font-medium text-gray-800 text-sm">Report Generated</div>
                                    <div class="text-xs text-gray-600 mt-1">Budget analysis report has been successfully generated.</div>
                                    <div class="text-xs text-gray-400 mt-2">5 minutes ago</div>
                                </div>
                            </div>
                        </div>
                    `;
                }
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
    
    function exportToPDF() {
    // Show loading indicator
    const exportBtn = event.target.closest('button') || event.target;
    const originalHtml = exportBtn.innerHTML;
    exportBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Exporting...';
    exportBtn.disabled = true;
    
    // Get current filter values
    const reportType = '<?= $report_type ?>';
    const fiscalYear = '<?= $fiscal_year ?>';
    const department = '<?= $department ?>';
    
    // Build URL with current filters
    const params = new URLSearchParams({
        report_type: reportType,
        fiscal_year: fiscalYear,
        department: department
    });
    
    // Create download link
    const downloadLink = document.createElement('a');
    downloadLink.style.display = 'none';
    downloadLink.href = `export_budget_reports_pdf.php?${params.toString()}`;
    
    document.body.appendChild(downloadLink);
    downloadLink.click();
    document.body.removeChild(downloadLink);
    
    // Reset button after delay
    setTimeout(() => {
        exportBtn.innerHTML = originalHtml;
        exportBtn.disabled = false;
        
        // Show success message
        showNotification('PDF file download started!', 'success');
    }, 1500);
}

function exportToExcel() {
    // Show loading indicator
    const exportBtn = event.target.closest('button') || event.target;
    const originalHtml = exportBtn.innerHTML;
    exportBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Exporting...';
    exportBtn.disabled = true;
    
    // Get current filter values
    const reportType = '<?= $report_type ?>';
    const fiscalYear = '<?= $fiscal_year ?>';
    const department = '<?= $department ?>';
    
    // Build URL with current filters
    const params = new URLSearchParams({
        report_type: reportType,
        fiscal_year: fiscalYear,
        department: department
    });
    
    // Create download link
    const downloadLink = document.createElement('a');
    downloadLink.style.display = 'none';
    downloadLink.href = `export_budget_reports.php?${params.toString()}`;
    
    document.body.appendChild(downloadLink);
    downloadLink.click();
    document.body.removeChild(downloadLink);
    
    // Reset button after delay
    setTimeout(() => {
        exportBtn.innerHTML = originalHtml;
        exportBtn.disabled = false;
        
        // Show success message
        showNotification('CSV/Excel file download started!', 'success');
    }, 1500);
}
    
    // Helper function for notifications
    function showNotification(message, type = 'info') {
        const notification = document.createElement('div');
        notification.className = `fixed top-4 right-4 z-50 px-6 py-3 rounded-lg shadow-lg text-white font-medium ${
            type === 'success' ? 'bg-green-500' : 
            type === 'error' ? 'bg-red-500' : 'bg-blue-500'
        }`;
        notification.textContent = message;
        notification.style.animation = 'slideIn 0.3s ease';
        
        document.body.appendChild(notification);
        
        // Auto-remove after 3 seconds
        setTimeout(() => {
            notification.style.animation = 'slideOut 0.3s ease';
            setTimeout(() => {
                document.body.removeChild(notification);
            }, 300);
        }, 3000);
    }
    
    // Add CSS animations for notifications
    if (!document.querySelector('#notification-animations')) {
        const style = document.createElement('style');
        style.id = 'notification-animations';
        style.textContent = `
            @keyframes slideIn {
                from {
                    transform: translateX(100%);
                    opacity: 0;
                }
                to {
                    transform: translateX(0);
                    opacity: 1;
                }
            }
            
            @keyframes slideOut {
                from {
                    transform: translateX(0);
                    opacity: 1;
                }
                to {
                    transform: translateX(100%);
                    opacity: 0;
                }
            }
        `;
        document.head.appendChild(style);
    }
    </script>
</body>
</html>