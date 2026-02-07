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
$budget_vs_actual_data = [];
$variance_analysis = [];
$monthly_trend = [];
$fiscal_years = [];
$departments = [];

// Handle report filtering - sanitize inputs
$fiscal_year = isset($_GET['fiscal_year']) ? $_GET['fiscal_year'] : date('Y');
$department = isset($_GET['department']) ? trim($_GET['department']) : '';
$period = $_GET['period'] ?? 'year';

// Handle period filtering
$period_start = '';
$period_end = '';
$fiscal_year_data = null;

// Get fiscal years for dropdown from fiscal_years table
try {
    $years_stmt = $db->query("
        SELECT id, fiscal_year, start_date, end_date 
        FROM fiscal_years 
        WHERE status = 'active'
        ORDER BY start_date DESC
    ");
    $fiscal_years = $years_stmt ? $years_stmt->fetchAll() : [];
    
    // If no fiscal years in database, get current year
    if (empty($fiscal_years)) {
        $current_year = date('Y');
        $fiscal_years = [['fiscal_year' => $current_year, 'start_date' => "{$current_year}-01-01", 'end_date' => "{$current_year}-12-31"]];
    }
    
    // Get selected fiscal year data
    $selected_fy_stmt = $db->prepare("
        SELECT start_date, end_date 
        FROM fiscal_years 
        WHERE fiscal_year = ? 
        AND status = 'active' 
        LIMIT 1
    ");
    $selected_fy_stmt->execute([$fiscal_year]);
    $fiscal_year_data = $selected_fy_stmt->fetch();
    
    if (!$fiscal_year_data) {
        // If selected fiscal year not found, use the first one
        $fiscal_year = $fiscal_years[0]['fiscal_year'];
        $fiscal_year_data = [
            'start_date' => $fiscal_years[0]['start_date'] ?? date("Y-01-01"),
            'end_date' => $fiscal_years[0]['end_date'] ?? date("Y-12-31")
        ];
    }
} catch (Exception $e) {
    error_log("Fiscal year query error: " . $e->getMessage());
    $current_year = date('Y');
    $fiscal_year_data = [
        'start_date' => "{$current_year}-01-01",
        'end_date' => "{$current_year}-12-31"
    ];
}

// Calculate period dates based on selected period
$fiscal_start = new DateTime($fiscal_year_data['start_date']);
$fiscal_end = new DateTime($fiscal_year_data['end_date']);

switch ($period) {
    case 'quarter':
        $current_month = date('n');
        $current_quarter = ceil($current_month / 3);
        $quarter_start_month = (($current_quarter - 1) * 3) + 1;
        $quarter_end_month = $quarter_start_month + 2;
        
        $period_start = date("Y-m-d", strtotime("{$fiscal_year}-{$quarter_start_month}-01"));
        $period_end = date("Y-m-t", strtotime("{$fiscal_year}-{$quarter_end_month}-01"));
        break;
        
    case 'month':
        $current_month = date('n');
        $period_start = date("Y-m-d", strtotime("{$fiscal_year}-{$current_month}-01"));
        $period_end = date("Y-m-t", strtotime("{$fiscal_year}-{$current_month}-01"));
        break;
        
    default: // 'year'
        $period_start = $fiscal_start->format('Y-m-d');
        $period_end = $fiscal_end->format('Y-m-d');
        break;
}

try {
    // =========================================================
    // 1. GET ALL BUDGETS (Grouped by Department ID & Name)
    // =========================================================
    // Ito ang mag-aayos sa 'Logistics' na hiwalay sa proposal pero dapat isa lang sa chart
    $budget_sql = "
        SELECT 
            d.id as dept_id,
            CASE 
                WHEN d.name LIKE '%Logistics%' THEN 'Logistics'
                WHEN d.name LIKE '%Core%' OR d.name LIKE '%Finance%' THEN 'Core Budget'
                WHEN d.name LIKE '%HR%' OR d.name LIKE '%Payroll%' THEN 'HR Payroll'
                ELSE d.name 
            END as normalized_name,
            COALESCE(SUM(bp.total_amount), 0) as total_budget,
            COALESCE(SUM(bp.remaining_amount), 0) as remaining_budget,
            COUNT(DISTINCT bp.id) as proposal_count
        FROM budget_proposals bp
        LEFT JOIN departments d ON bp.department = d.id
        WHERE YEAR(bp.created_at) = ?
        AND bp.status = 'Approved'
    ";

    // Extract year logic
    $budget_year = $fiscal_year;
    if (strpos($fiscal_year, '-') !== false) {
        $parts = explode('-', $fiscal_year);
        $budget_year = $parts[0];
    }

    if (!empty($department)) {
        $budget_sql .= " AND bp.department = ?";
    }

    // Group by normalized name para mag-merge ang Logistics 1 at 2
    $budget_sql .= " GROUP BY normalized_name ORDER BY total_budget DESC";
    
    $budget_stmt = $db->prepare($budget_sql);
    $budget_params = [$budget_year];
    if (!empty($department)) $budget_params[] = $department;
    $budget_stmt->execute($budget_params);
    $department_budgets = $budget_stmt->fetchAll();

    // =========================================================
    // 2. GET ACTUAL EXPENSES (Strict Normalization)
    // =========================================================
    // Ito ang solusyon sa "Doble HR Payroll"
    $actual_sql = "
        SELECT 
            CASE 
                -- Force convert ID to Name
                WHEN dr.department = '1' OR dr.department LIKE '%HR%' OR dr.department LIKE '%Payroll%' THEN 'HR Payroll'
                WHEN dr.department = '2' OR dr.department LIKE '%Core%' OR dr.department LIKE '%Finance%' THEN 'Core Budget'
                WHEN dr.department IN ('9', '10') OR dr.department LIKE '%Logistics%' THEN 'Logistics'
                WHEN dr.department = '4' OR dr.department LIKE '%Operations%' THEN 'Operations'
                ELSE COALESCE(d.name, dr.department) 
            END as normalized_dept_name,
            
            COALESCE(SUM(dr.amount), 0) as total_expenses,
            COUNT(DISTINCT dr.id) as transaction_count
        FROM disbursement_requests dr
        LEFT JOIN departments d ON dr.department = d.id
        WHERE dr.date_requested BETWEEN ? AND ?
        AND dr.status = 'Approved'
    ";
    
    $actual_params = [$period_start, $period_end];
    if (!empty($department)) {
        $actual_sql .= " AND (dr.department = ? OR d.id = ?)";
        $actual_params[] = $department;
        $actual_params[] = $department;
    }
    
    $actual_sql .= " GROUP BY normalized_dept_name";
    
    $actual_stmt = $db->prepare($actual_sql);
    $actual_stmt->execute($actual_params);
    $department_actuals = $actual_stmt->fetchAll();

    // =========================================================
    // 3. CONSOLIDATE DATA (Merge Budget & Actuals)
    // =========================================================
    
    $final_data_map = [];

    // A. Ipasok ang Budgets
    foreach ($department_budgets as $budget) {
        $key_name = $budget['normalized_name'] ?? 'Unknown';
        
        // Prorate Budget based on period selection
        $annual_budget = (float)($budget['total_budget'] ?? 0);
        $total_budget = $annual_budget; 
        if ($period === 'quarter') $total_budget /= 4;
        elseif ($period === 'month') $total_budget /= 12;

        if (!isset($final_data_map[$key_name])) {
            $final_data_map[$key_name] = [
                'department' => $key_name,
                'total_budget' => 0,
                'total_expenses' => 0,
                'transaction_count' => 0,
                'proposal_count' => 0,
                'remaining_budget' => 0,
                'spent_budget' => 0
            ];
        }
        
        $final_data_map[$key_name]['total_budget'] += $total_budget;
        $final_data_map[$key_name]['remaining_budget'] += (float)$budget['remaining_budget'];
        $final_data_map[$key_name]['proposal_count'] += $budget['proposal_count'];
    }

    // B. Ipasok ang Actuals (Matched by Normalized Name)
    foreach ($department_actuals as $actual) {
        $key_name = $actual['normalized_dept_name'];
        
        if (!isset($final_data_map[$key_name])) {
            $final_data_map[$key_name] = [
                'department' => $key_name,
                'total_budget' => 0,
                'total_expenses' => 0,
                'transaction_count' => 0,
                'proposal_count' => 0,
                'remaining_budget' => 0,
                'spent_budget' => 0
            ];
        }
        
        $final_data_map[$key_name]['total_expenses'] += (float)$actual['total_expenses'];
        $final_data_map[$key_name]['transaction_count'] += (int)$actual['transaction_count'];
    }

    // C. Final Calculation (Variance)
    $budget_vs_actual_data = [];
    foreach ($final_data_map as $item) {
        $variance = $item['total_budget'] - $item['total_expenses'];
        $variance_percentage = ($item['total_budget'] > 0) ? ($variance / $item['total_budget'] * 100) : 0;
        
        $item['variance'] = $variance;
        $item['variance_percentage'] = $variance_percentage;
        // Recalculate spent budget based on actual expenses for display consistency
        $item['spent_budget'] = $item['total_expenses']; 
        
        $budget_vs_actual_data[] = $item;
    }

    // =========================================================
    // 4. VARIANCE ANALYSIS & TRENDS
    // =========================================================
    
    // Sort for Top Variances Table
    usort($budget_vs_actual_data, function($a, $b) {
        return abs($b['variance']) <=> abs($a['variance']);
    });
    
    $variance_analysis = [];
    foreach (array_slice($budget_vs_actual_data, 0, 10) as $row) {
        $variance_analysis[] = [
            'proposal_title' => $row['department'], // Use Dept Name
            'department_name' => $row['department'],
            'budget_amount' => $row['total_budget'],
            'actual_expenses' => $row['total_expenses'],
            'variance' => $row['variance'],
            'variance_percentage' => $row['variance_percentage']
        ];
    }

    // Monthly Trend Query (Standard)
    $monthly_trend_sql = "
        SELECT 
            MONTH(dr.date_requested) as month,
            YEAR(dr.date_requested) as year,
            COALESCE(SUM(dr.amount), 0) as monthly_expenses,
            COUNT(DISTINCT dr.id) as transaction_count
        FROM disbursement_requests dr
        WHERE dr.date_requested BETWEEN ? AND ?
        AND dr.status = 'Approved'
        GROUP BY YEAR(dr.date_requested), MONTH(dr.date_requested)
        ORDER BY year, month
    ";
    
    $monthly_trend_stmt = $db->prepare($monthly_trend_sql);
    $monthly_trend_stmt->execute([$period_start, $period_end]);
    $monthly_trend = $monthly_trend_stmt->fetchAll();

    // Dropdown Data
    $dept_stmt = $db->query("SELECT id, name FROM departments WHERE status = 'Active' ORDER BY name");
    $departments = $dept_stmt ? $dept_stmt->fetchAll() : [];

    // Cast numbers to float/int to prevent JSON errors
    foreach ($monthly_trend as &$month) {
        $month['month'] = (int)$month['month'];
        $month['monthly_expenses'] = (float)$month['monthly_expenses'];
    }

} catch (Exception $e) {
    error_log("Budget vs Actual data load error: " . $e->getMessage());
    $budget_vs_actual_data = [];
    $variance_analysis = [];
    $monthly_trend = [];
    $departments = [];
}

// Handle logout
if (isset($_GET['logout']) && $_GET['logout'] === 'true') {
    $_SESSION = [];
    session_destroy();
    header("Location: index.php");
    exit;
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

// Safe output function
function safe_output($value, $default = '') {
    if ($value === null || $value === '') {
        return $default;
    }
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

// Function to mask numbers with dots
function maskNumber($number, $masked = true) {
    global $_SESSION;
    $masked = $_SESSION['hide_numbers'] ?? false;
    
    if (!$masked) {
        return number_format((float)$number, 2);
    }
    
    $numberStr = (string)$number;
    $parts = explode('.', $numberStr);
    $integerPart = $parts[0];
    
    // Mask the integer part with dots
    return str_repeat('•', strlen($integerPart));
}

// Format amount with PHP symbol
function formatAmount($amount) {
    global $_SESSION;
    $masked = $_SESSION['hide_numbers'] ?? false;
    
    if ($masked) {
        $numberStr = (string)$amount;
        $parts = explode('.', $numberStr);
        $integerPart = $parts[0];
        return '₱' . str_repeat('•', strlen($integerPart));
    }
    
    return '₱' . number_format((float)$amount, 2);
}

// Calculate summary statistics
$total_budget = array_sum(array_column($budget_vs_actual_data, 'total_budget'));
$total_expenses = array_sum(array_column($budget_vs_actual_data, 'total_expenses'));
$net_variance = $total_budget - $total_expenses;
$utilization_rate = $total_budget > 0 ? ($total_expenses / $total_budget * 100) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Budget vs Actual Analysis | Financial Dashboard</title>

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
        
        .variance-neutral {
            background-color: rgba(156, 163, 175, 0.1);
            color: #6B7280;
        }
        
        .budget-actual-row {
            transition: background-color 0.2s;
        }
        
        .budget-actual-row:hover {
            background-color: #f9fafb;
        }
        
        .variance-indicator {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 0.5rem;
        }
        
        .variance-good {
            background-color: #10B981;
        }
        
        .variance-warning {
            background-color: #F59E0B;
        }
        
        .variance-critical {
            background-color: #EF4444;
        }
        
        .utilization-high {
            background: linear-gradient(135deg, #10B981 0%, #059669 100%);
            color: white;
        }
        
        .utilization-medium {
            background: linear-gradient(135deg, #F59E0B 0%, #D97706 100%);
            color: white;
        }
        
        .utilization-low {
            background: linear-gradient(135deg, #EF4444 0%, #DC2626 100%);
            color: white;
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
                            <a href="budget_vs_actual.php" class="block px-3 py-1.5 rounded-lg text-xs bg-emerald-50 text-brand-primary font-medium border border-emerald-100 hover:bg-emerald-100 hover:border-emerald-200 transition-all duration-200 hover:translate-x-1">
                                <span class="flex items-center justify-between">
                                    Budget vs Actual
                                    <span class="inline-flex w-1.5 h-1.5 rounded-full bg-emerald-400"></span>
                                </span>
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
                        Budget vs Actual Analysis
                    </h1>
                    <p class="text-xs text-gray-500">
                        Welcome Back, <?php echo htmlspecialchars($user['name']); ?>!
                    </p>
                </div>
            </div>

            <div class="flex items-center gap-3 sm:gap=5">
                <!-- Real-time Clock -->
                <span id="real-time-clock"
                    class="text-xs font-bold text-gray-700 bg-gray-50 px-3 py-2 rounded-lg border border-gray-200">
                    --:--:--
                </span>

                <!-- Visibility Toggle -->
                <button id="visibility-toggle" class="w-10 h-10 rounded-xl hover:bg-gray-100 active:bg-gray-200 transition flex items-center justify-center relative"
                        title="Toggle Amount Visibility">
                    <i class="fa-solid fa-eye-slash text-gray-600"></i>
                </button>

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
                            <label class="form-label">Fiscal Year</label>
                            <select name="fiscal_year" class="form-select" required>
                                <?php if (empty($fiscal_years)): ?>
                                    <option value="<?= date('Y') ?>">FY <?= date('Y') ?></option>
                                <?php else: ?>
                                    <?php foreach ($fiscal_years as $year): ?>
                                        <option value="<?= safe_output($year['fiscal_year']) ?>" 
                                            <?= $fiscal_year == $year['fiscal_year'] ? 'selected' : '' ?>>
                                            FY <?= safe_output($year['fiscal_year']) ?>
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
                                    <option value="<?= safe_output($dept['id']) ?>" 
                                        <?= (isset($_GET['department']) && $_GET['department'] == $dept['id']) ? 'selected' : '' ?>>
                                        <?= safe_output($dept['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Period</label>
                            <select name="period" class="form-select" id="period-select">
                                <option value="year" <?= $period === 'year' ? 'selected' : '' ?>>Full Fiscal Year</option>
                                <option value="quarter" <?= $period === 'quarter' ? 'selected' : '' ?>>Current Quarter</option>
                                <option value="month" <?= $period === 'month' ? 'selected' : '' ?>>Current Month</option>
                            </select>
                        </div>
                        
                        <div class="form-group flex items-end">
                            <button type="submit" class="w-full px-4 py-3 bg-brand-primary text-white rounded-lg hover:bg-brand-primary-hover transition flex items-center justify-center">
                                <i class="fa-solid fa-filter mr-2"></i>Generate Report
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Stats Overview -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                    <?php 
                    $stats = [
                        ['icon' => 'bx-money', 'label' => 'Total Budget', 'value' => $total_budget, 'color' => 'green', 'stat' => 'budget'],
                        ['icon' => 'bx-credit-card', 'label' => 'Actual Expenses', 'value' => $total_expenses, 'color' => 'blue', 'stat' => 'expenses'],
                        ['icon' => 'bx-wallet', 'label' => 'Net Variance', 'value' => $net_variance, 'color' => $net_variance >= 0 ? 'green' : 'red', 'stat' => 'variance'],
                        ['icon' => 'bx-pie-chart', 'label' => 'Utilization Rate', 'value' => $utilization_rate, 'color' => 'yellow', 'stat' => 'utilization']
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
                        
                        $displayValue = $stat['stat'] === 'utilization' ? 
                            number_format($stat['value'], 1) . '%' : 
                            formatAmount($stat['value']);
                        $actualValue = $stat['stat'] === 'utilization' ? 
                            number_format($stat['value'], 1) . '%' : 
                            '₱' . number_format($stat['value'], 2);
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
                                           data-stat="<?php echo $stat['stat']; ?>">
                                            <?php if($_SESSION['hide_numbers'] ?? false): ?>
                                                <!-- Show dots for hidden amounts -->
                                                <?php if($stat['stat'] === 'utilization'): ?>
                                                    ••••%
                                                <?php else: ?>
                                                    ₱••••••
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <?php echo $displayValue; ?>
                                            <?php endif; ?>
                                        </p>
                                    </div>
                                    <button class="stat-toggle text-gray-400 hover:text-brand-primary transition"
                                            data-stat="<?php echo $stat['stat']; ?>">
                                        <i class="fa-solid fa-eye-slash"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Charts Section -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <!-- Budget vs Actual Chart -->
                    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                        <div class="flex justify-between items-center mb-6">
                            <h3 class="text-lg font-bold text-gray-800">Budget vs Actual by Department</h3>
                            <div class="flex items-center gap-4">
                                <div class="flex items-center gap-2">
                                    <div class="w-3 h-3 rounded-full bg-brand-primary"></div>
                                    <span class="text-sm text-gray-500">Budget</span>
                                </div>
                                <div class="flex items-center gap-2">
                                    <div class="w-3 h-3 rounded-full bg-blue-500"></div>
                                    <span class="text-sm text-gray-500">Actual</span>
                                </div>
                            </div>
                        </div>
                        <div class="chart-container">
                            <canvas id="budgetActualChart"></canvas>
                        </div>
                    </div>

                    <!-- Variance Analysis Chart -->
                    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                        <div class="flex justify-between items-center mb-6">
                            <h3 class="text-lg font-bold text-gray-800">Variance Analysis</h3>
                            <button id="refresh-chart" class="text-brand-primary hover:text-brand-primary-hover transition">
                                <i class='bx bx-refresh text-xl'></i>
                            </button>
                        </div>
                        <div class="chart-container">
                            <canvas id="varianceChart"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Monthly Trend Chart -->
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                    <h3 class="text-lg font-bold text-gray-800 mb-6">Monthly Budget vs Actual Trend</h3>
                    <div class="chart-container">
                        <canvas id="monthlyTrendChart"></canvas>
                    </div>
                </div>

                <!-- Detailed Budget vs Actual Table -->
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                    <div class="p-6 border-b border-gray-100">
                        <div class="flex justify-between items-center">
                            <div class="flex items-center gap-3">
                                <h3 class="text-lg font-bold text-gray-800">Detailed Budget vs Actual Analysis</h3>
                                <button id="table-visibility-toggle" class="text-gray-500 hover:text-brand-primary transition">
                                    <i class="fa-solid fa-eye-slash"></i>
                                </button>
                            </div>
                            <div class="flex space-x-2">
                                <button class="px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 transition flex items-center gap-2" onclick="window.print()">
                                    <i class="fas fa-print"></i> Print
                                </button>
                                <button class="px-4 py-2 bg-brand-primary text-white rounded-lg hover:bg-brand-primary-hover transition flex items-center gap-2" onclick="exportToExcel()">
                                    <i class="fas fa-file-excel"></i> Export
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Department</th>
                                    <th>Budget (₱)</th>
                                    <th>Actual (₱)</th>
                                    <th>Variance (₱)</th>
                                    <th>Variance %</th>
                                    <th>Utilization</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($budget_vs_actual_data)): ?>
                                    <tr>
                                        <td colspan="7" class="text-center py-4 text-gray-500">
                                            <i class='bx bx-folder-open text-3xl mb-2 text-gray-300'></i>
                                            <div>No budget vs actual data available for the selected criteria.</div>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($budget_vs_actual_data as $data): 
                                        $variance_class = $data['variance'] > 0 ? 'variance-positive' : 
                                                         ($data['variance'] < 0 ? 'variance-negative' : 'variance-neutral');
                                        $utilization = $data['total_budget'] > 0 ? ($data['total_expenses'] / $data['total_budget'] * 100) : 0;
                                        $status_indicator = $utilization > 90 ? 'variance-critical' : 
                                                           ($utilization > 70 ? 'variance-warning' : 'variance-good');
                                    ?>
                                        <tr class="budget-actual-row">
                                            <td class="font-medium"><?= safe_output($data['department']) ?></td>
                                            <td class="font-semibold amount-masked table-amount" 
                                                data-value="₱<?= number_format($data['total_budget'], 2) ?>">
                                                <?php if($_SESSION['hide_numbers'] ?? false): ?>
                                                    ₱••••••
                                                <?php else: ?>
                                                    ₱<?= number_format($data['total_budget'], 2) ?>
                                                <?php endif; ?>
                                            </td>
                                            <td class="amount-masked table-amount" 
                                                data-value="₱<?= number_format($data['total_expenses'], 2) ?>">
                                                <?php if($_SESSION['hide_numbers'] ?? false): ?>
                                                    ₱••••••
                                                <?php else: ?>
                                                    ₱<?= number_format($data['total_expenses'], 2) ?>
                                                <?php endif; ?>
                                            </td>
                                            <td class="<?= $variance_class ?> font-semibold amount-masked table-amount" 
                                                data-value="₱<?= number_format(abs($data['variance']), 2) ?>">
                                                <?php if($_SESSION['hide_numbers'] ?? false): ?>
                                                    ₱••••••
                                                <?php else: ?>
                                                    ₱<?= number_format(abs($data['variance']), 2) ?>
                                                <?php endif; ?>
                                                <?= $data['variance'] > 0 ? 'Under' : ($data['variance'] < 0 ? 'Over' : 'On') ?>
                                            </td>
                                            <td class="<?= $variance_class ?>">
                                                <?= number_format(abs($data['variance_percentage']), 1) ?>%
                                            </td>
                                            <td>
                                                <div class="flex items-center">
                                                    <div class="w-24 bg-gray-200 rounded-full h-2 mr-2">
                                                        <div class="h-2 rounded-full <?= 
                                                            $utilization > 90 ? 'bg-red-500' : 
                                                            ($utilization > 70 ? 'bg-yellow-500' : 'bg-green-500')
                                                        ?>" style="width: <?= min($utilization, 100) ?>%"></div>
                                                    </div>
                                                    <span class="text-sm"><?= number_format($utilization, 1) ?>%</span>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="variance-indicator <?= $status_indicator ?>"></span>
                                                <span class="text-sm">
                                                    <?= $utilization > 90 ? 'High Utilization' : 
                                                         ($utilization > 70 ? 'Medium Utilization' : 'Low Utilization') ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Variance Analysis Section -->
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                    <div class="p-6 border-b border-gray-100">
                        <h3 class="text-lg font-bold text-gray-800">Top Variances</h3>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Proposal</th>
                                    <th>Department</th>
                                    <th>Variance (₱)</th>
                                    <th>Variance %</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($variance_analysis)): ?>
                                    <tr>
                                        <td colspan="4" class="text-center py-4 text-gray-500">
                                            <i class='bx bx-folder-open text-3xl mb-2 text-gray-300'></i>
                                            <div>No significant variances found.</div>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($variance_analysis as $variance): 
                                        $variance_class = $variance['variance'] > 0 ? 'variance-positive' : 'variance-negative';
                                    ?>
                                        <tr class="budget-actual-row">
                                            <td class="font-medium"><?= safe_output($variance['proposal_title']) ?></td>
                                            <td><?= safe_output($variance['department_name']) ?></td>
                                            <td class="<?= $variance_class ?> font-semibold amount-masked table-amount" 
                                                data-value="₱<?= number_format(abs($variance['variance']), 2) ?>">
                                                <?php if($_SESSION['hide_numbers'] ?? false): ?>
                                                    ₱••••••
                                                <?php else: ?>
                                                    ₱<?= number_format(abs($variance['variance']), 2) ?>
                                                <?php endif; ?>
                                            </td>
                                            <td class="<?= $variance_class ?>">
                                                <?= number_format(abs($variance['variance_percentage']), 1) ?>%
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
            const displayHours = hours % 12 || 12; // Convert 0 to 12
            
            const timeString = `${displayHours}:${minutes}:${seconds} ${ampm}`;
            const clockElement = document.getElementById('real-time-clock');
            if (clockElement) {
                clockElement.textContent = timeString;
            }
        }
        setInterval(updateClock, 1000);
        updateClock();

        // Initialize charts
        initializeCharts();
        
        // Initialize table visibility
        initializeTableVisibility();
        
        // Initialize stats visibility
        initializeStatsVisibility();

        // Common features
        initializeCommonFeatures();
    });

    function initializeCharts() {
        // Budget vs Actual Comparison Chart
        const budgetActualCtx = document.getElementById('budgetActualChart')?.getContext('2d');
        if (budgetActualCtx) {
            const departments = <?php echo json_encode(array_column($budget_vs_actual_data, 'department')); ?>;
            const budgets = <?php echo json_encode(array_column($budget_vs_actual_data, 'total_budget')); ?>;
            const actuals = <?php echo json_encode(array_column($budget_vs_actual_data, 'total_expenses')); ?>;
            
            const numericBudgets = budgets.map(v => parseFloat(v) || 0);
            const numericActuals = actuals.map(v => parseFloat(v) || 0);
            
            new Chart(budgetActualCtx, {
                type: 'bar',
                data: {
                    labels: departments,
                    datasets: [
                        {
                            label: 'Budget',
                            data: numericBudgets,
                            backgroundColor: '#059669',
                            borderColor: '#047857',
                            borderWidth: 1,
                            borderRadius: 6,
                        },
                        {
                            label: 'Actual',
                            data: numericActuals,
                            backgroundColor: '#3B82F6',
                            borderColor: '#2563EB',
                            borderWidth: 1,
                            borderRadius: 6,
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: { 
                                drawBorder: false,
                                color: 'rgba(0,0,0,0.05)'
                            },
                            ticks: { 
                                callback: function(value) { 
                                    return '₱' + (value/1000).toFixed(0) + 'K'; 
                                },
                                font: { family: 'system-ui' }
                            }
                        },
                        x: { 
                            grid: { display: false }
                        }
                    },
                    plugins: { 
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: 'white',
                            titleColor: '#1F2937',
                            bodyColor: '#4B5563',
                            borderColor: '#D1FAE5',
                            borderWidth: 1,
                            padding: 12,
                            boxPadding: 6,
                            callbacks: {
                                label: function(context) {
                                    return `₱${context.parsed.y.toLocaleString()}`;
                                }
                            }
                        }
                    }
                }
            });
        }

        // Variance Chart
        const varianceCtx = document.getElementById('varianceChart')?.getContext('2d');
        if (varianceCtx) {
            const departments = <?php echo json_encode(array_column($budget_vs_actual_data, 'department')); ?>;
            const variances = <?php echo json_encode(array_column($budget_vs_actual_data, 'variance')); ?>;
            
            const numericVariances = variances.map(v => parseFloat(v) || 0);
            
            new Chart(varianceCtx, {
                type: 'bar',
                data: {
                    labels: departments,
                    datasets: [{
                        label: 'Variance',
                        data: numericVariances,
                        backgroundColor: numericVariances.map(v => v >= 0 ? '#10B981' : '#EF4444'),
                        borderColor: numericVariances.map(v => v >= 0 ? '#059669' : '#DC2626'),
                        borderWidth: 1,
                        borderRadius: 6,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            grid: { 
                                drawBorder: false,
                                color: 'rgba(0,0,0,0.05)'
                            },
                            ticks: { 
                                callback: function(value) { 
                                    return '₱' + (value/1000).toFixed(0) + 'K'; 
                                },
                                font: { family: 'system-ui' }
                            }
                        },
                        x: { 
                            grid: { display: false }
                        }
                    },
                    plugins: { 
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: 'white',
                            titleColor: '#1F2937',
                            bodyColor: '#4B5563',
                            borderColor: '#D1FAE5',
                            borderWidth: 1,
                            padding: 12,
                            boxPadding: 6,
                            callbacks: {
                                label: function(context) {
                                    const value = context.parsed.y;
                                    const sign = value >= 0 ? '+' : '';
                                    return `${sign}₱${Math.abs(value).toLocaleString()}`;
                                }
                            }
                        }
                    }
                }
            });
        }

        // Monthly Trend Chart
        const monthlyCtx = document.getElementById('monthlyTrendChart')?.getContext('2d');
        if (monthlyCtx) {
            const monthlyData = <?php echo json_encode($monthly_trend); ?>;
            
            const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
            const monthlyExpenses = new Array(12).fill(0);
            
            monthlyData.forEach(item => {
                const monthIndex = item.month - 1;
                monthlyExpenses[monthIndex] = parseFloat(item.monthly_expenses) || 0;
            });
            
            new Chart(monthlyCtx, {
                type: 'line',
                data: {
                    labels: months,
                    datasets: [
                        {
                            label: 'Monthly Expenses',
                            data: monthlyExpenses,
                            borderColor: '#EF4444',
                            backgroundColor: 'rgba(239, 68, 68, 0.1)',
                            borderWidth: 2,
                            fill: true,
                            tension: 0.4
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: { 
                                drawBorder: false,
                                color: 'rgba(0,0,0,0.05)'
                            },
                            ticks: { 
                                callback: function(value) { 
                                    return '₱' + (value/1000).toFixed(0) + 'K'; 
                                },
                                font: { family: 'system-ui' }
                            }
                        },
                        x: { 
                            grid: { display: false }
                        }
                    },
                    plugins: { 
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: 'white',
                            titleColor: '#1F2937',
                            bodyColor: '#4B5563',
                            borderColor: '#D1FAE5',
                            borderWidth: 1,
                            padding: 12,
                            boxPadding: 6,
                            callbacks: {
                                label: function(context) {
                                    return `₱${context.parsed.y.toLocaleString()}`;
                                }
                            }
                        }
                    }
                }
            });
        }
        
        // Refresh chart button
        const refreshBtn = document.getElementById('refresh-chart');
        if (refreshBtn) {
            refreshBtn.addEventListener('click', function() {
                this.innerHTML = '<div class="spinner"></div>';
                setTimeout(() => {
                    this.innerHTML = '<i class="bx bx-refresh text-xl"></i>';
                    // Here you could reload the chart data via AJAX
                }, 1000);
            });
        }
    }

    function initializeTableVisibility() {
        const tableToggleBtn = document.getElementById('table-visibility-toggle');
        const tableAmountCells = document.querySelectorAll('.table-amount');
        
        if (tableToggleBtn && tableAmountCells.length > 0) {
            const savedVisibility = localStorage.getItem('tableVisible') === 'true';
            
            // Set initial state based on PHP session
            const isHiddenByPHP = <?php echo json_encode($_SESSION['hide_numbers'] ?? false); ?>;
            const initialVisibility = savedVisibility && !isHiddenByPHP;
            
            const icon = tableToggleBtn.querySelector('i');
            if (initialVisibility) {
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
                tableToggleBtn.title = "Hide Amounts";
                tableAmountCells.forEach(cell => {
                    const actualValue = cell.getAttribute('data-value');
                    cell.textContent = actualValue;
                    cell.classList.remove('hidden-amount');
                });
            } else {
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
                tableToggleBtn.title = "Show Amounts";
                tableAmountCells.forEach(cell => {
                    // Use dots instead of asterisks
                    const actualValue = cell.getAttribute('data-value');
                    if (actualValue.includes('₱')) {
                        cell.textContent = '₱••••••';
                    } else {
                        cell.textContent = '••••••';
                    }
                    cell.classList.add('hidden-amount');
                });
            }
            
            // Add click event
            tableToggleBtn.addEventListener('click', function() {
                const current = localStorage.getItem('tableVisible') === 'true';
                const newState = !current;
                localStorage.setItem('tableVisible', newState);
                
                const icon = this.querySelector('i');
                if (newState) {
                    icon.classList.remove('fa-eye-slash');
                    icon.classList.add('fa-eye');
                    this.title = "Hide Amounts";
                    tableAmountCells.forEach(cell => {
                        const actualValue = cell.getAttribute('data-value');
                        cell.textContent = actualValue;
                        cell.classList.remove('hidden-amount');
                    });
                } else {
                    icon.classList.remove('fa-eye');
                    icon.classList.add('fa-eye-slash');
                    this.title = "Show Amounts";
                    tableAmountCells.forEach(cell => {
                        // Use dots instead of asterisks
                        const actualValue = cell.getAttribute('data-value');
                        if (actualValue.includes('₱')) {
                            cell.textContent = '₱••••••';
                        } else {
                            cell.textContent = '••••••';
                        }
                        cell.classList.add('hidden-amount');
                    });
                }
            });
        }
    }

    function initializeStatsVisibility() {
        // Main visibility toggle
        const visibilityToggle = document.getElementById('visibility-toggle');
        if (visibilityToggle) {
            // Set initial state based on PHP session
            const isHiddenByPHP = <?php echo json_encode($_SESSION['hide_numbers'] ?? false); ?>;
            const allStatsVisible = checkAllStatsVisible() && !isHiddenByPHP;
            
            const icon = visibilityToggle.querySelector('i');
            if (allStatsVisible) {
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
                visibilityToggle.title = "Hide All Amounts";
            } else {
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
                visibilityToggle.title = "Show All Amounts";
            }
            
            visibilityToggle.addEventListener('click', function() {
                // Toggle all stat values
                const statToggles = document.querySelectorAll('.stat-toggle');
                const allStatsVisible = checkAllStatsVisible();
                const newState = !allStatsVisible;
                
                statToggles.forEach(toggle => {
                    const statType = toggle.getAttribute('data-stat');
                    toggleStat(statType, newState);
                    localStorage.setItem(`stat_${statType}_visible`, newState);
                });
                
                // Update main toggle icon
                const icon = this.querySelector('i');
                if (newState) {
                    icon.classList.remove('fa-eye-slash');
                    icon.classList.add('fa-eye');
                    this.title = "Hide All Amounts";
                } else {
                    icon.classList.remove('fa-eye');
                    icon.classList.add('fa-eye-slash');
                    this.title = "Show All Amounts";
                }
            });
        }
        
        // Individual stat toggles
        const statToggles = document.querySelectorAll('.stat-toggle');
        statToggles.forEach(toggle => {
            toggle.addEventListener('click', function() {
                const statType = this.getAttribute('data-stat');
                const currentState = localStorage.getItem(`stat_${statType}_visible`) === 'true';
                const newState = !currentState;
                
                toggleStat(statType, newState);
                localStorage.setItem(`stat_${statType}_visible`, newState);
                
                // Update main toggle state
                updateMainToggleState();
            });
            
            // Initialize individual stat states based on PHP session
            const statType = toggle.getAttribute('data-stat');
            const savedState = localStorage.getItem(`stat_${statType}_visible`);
            const isHiddenByPHP = <?php echo json_encode($_SESSION['hide_numbers'] ?? false); ?>;
            
            if (savedState !== null && !isHiddenByPHP) {
                toggleStat(statType, savedState === 'true');
            } else if (isHiddenByPHP) {
                toggleStat(statType, false);
            }
        });
        
        // Initialize main toggle state
        updateMainToggleState();
    }
    
    function toggleStat(statType, show) {
        const statElements = document.querySelectorAll(`.stat-value[data-stat="${statType}"]`);
        statElements.forEach(element => {
            const actualValue = element.getAttribute('data-value');
            if (show) {
                element.textContent = actualValue;
                element.classList.remove('hidden-amount');
            } else {
                // Use dots instead of asterisks
                if (statType === 'utilization') {
                    element.textContent = '••••%';
                } else {
                    element.textContent = '₱••••••';
                }
                element.classList.add('hidden-amount');
            }
        });
        
        // Update toggle icon
        const toggleBtn = document.querySelector(`.stat-toggle[data-stat="${statType}"]`);
        if (toggleBtn) {
            const icon = toggleBtn.querySelector('i');
            if (show) {
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
                toggleBtn.title = "Hide Amount";
            } else {
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
                toggleBtn.title = "Show Amount";
            }
        }
    }
    
    function checkAllStatsVisible() {
        const statTypes = ['budget', 'expenses', 'variance', 'utilization'];
        return statTypes.every(type => localStorage.getItem(`stat_${type}_visible`) === 'true');
    }
    
    function updateMainToggleState() {
        const visibilityToggle = document.getElementById('visibility-toggle');
        if (!visibilityToggle) return;
        
        const allVisible = checkAllStatsVisible();
        const icon = visibilityToggle.querySelector('i');
        
        if (allVisible) {
            icon.classList.remove('fa-eye-slash');
            icon.classList.add('fa-eye');
            visibilityToggle.title = "Hide All Amounts";
        } else {
            icon.classList.remove('fa-eye');
            icon.classList.add('fa-eye-slash');
            visibilityToggle.title = "Show All Amounts";
        }
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
                                    <div class="font-medium text-gray-800 text-sm">Budget vs Actual Report</div>
                                    <div class="text-xs text-gray-600 mt-1">Your budget vs actual report for ${new Date().getFullYear()} is ready for review.</div>
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
    
    function exportToExcel() {
    // Show loading indicator
    const exportBtn = event.target.closest('button') || event.target;
    const originalHtml = exportBtn.innerHTML;
    exportBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Exporting...';
    exportBtn.disabled = true;
    
    // Get current filter values
    const fiscalYear = document.querySelector('select[name="fiscal_year"]').value;
    const department = document.querySelector('select[name="department"]').value;
    const period = document.querySelector('select[name="period"]').value;
    
    // Build URL with current filters
    const params = new URLSearchParams({
        fiscal_year: fiscalYear,
        department: department,
        period: period
    });
    
    // Create download link
    const downloadLink = document.createElement('a');
    downloadLink.style.display = 'none';
    downloadLink.href = `export_budget_vs_actual.php?${params.toString()}`;
    
    document.body.appendChild(downloadLink);
    downloadLink.click();
    document.body.removeChild(downloadLink);
    
    // Reset button after delay
    setTimeout(() => {
        exportBtn.innerHTML = originalHtml;
        exportBtn.disabled = false;
        
        // Show success message
        showNotification('CSV file download started!', 'success');
    }, 1500);
}

// Helper function for notifications (idagdag ito sa existing code)
function showNotification(message, type = 'info') {
    // Check if notification already exists
    const existingNotif = document.querySelector('.custom-notification');
    if (existingNotif) {
        existingNotif.remove();
    }
    
    const notification = document.createElement('div');
    notification.className = `custom-notification fixed top-4 right-4 z-50 px-6 py-3 rounded-lg shadow-lg text-white font-medium transition-all duration-300 transform translate-x-0 ${
        type === 'success' ? 'bg-green-500' : 
        type === 'error' ? 'bg-red-500' : 'bg-blue-500'
    }`;
    notification.textContent = message;
    notification.style.maxWidth = '300px';
    
    document.body.appendChild(notification);
    
    // Auto-remove after 3 seconds
    setTimeout(() => {
        notification.style.transform = 'translateX(100%)';
        notification.style.opacity = '0';
        setTimeout(() => {
            if (notification.parentNode) {
                notification.parentNode.removeChild(notification);
            }
        }, 300);
    }, 3000);
}

// Optional: Add CSS for notification animation
if (!document.querySelector('#notification-styles')) {
    const style = document.createElement('style');
    style.id = 'notification-styles';
    style.textContent = `
        .custom-notification {
            animation: slideInRight 0.3s ease-out;
        }
        
        @keyframes slideInRight {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }
    `;
    document.head.appendChild(style);
}
    </script>
</body>
</html>