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
$report_data = [];
$collection_summary = [];
$payment_methods = [];
$top_performers = [];
$aging_analysis = [];

// Handle report filtering
$start_date = $_GET['start_date'] ?? date('Y-m-01'); // First day of current month
$end_date = $_GET['end_date'] ?? date('Y-m-t'); // Last day of current month
$report_type = $_GET['report_type'] ?? 'summary';

try {
    // Collection Summary Report
    $summary_stmt = $db->prepare("
        SELECT 
            COUNT(*) as total_payments,
            COALESCE(SUM(amount), 0) as total_collected,
            COALESCE(AVG(amount), 0) as average_payment,
            COALESCE(MAX(amount), 0) as largest_payment,
            COALESCE(MIN(amount), 0) as smallest_payment,
            COUNT(DISTINCT contact_id) as unique_customers,
            COALESCE(SUM(CASE WHEN payment_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) THEN amount ELSE 0 END), 0) as weekly_collection,
            COALESCE(SUM(CASE WHEN payment_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN amount ELSE 0 END), 0) as monthly_collection
        FROM payments 
        WHERE status = 'Completed' 
        AND type = 'Receive'
        AND payment_date BETWEEN ? AND ?
    ");
    $summary_stmt->execute([$start_date, $end_date]);
    $collection_summary = $summary_stmt->fetch();

    // Payment Methods Breakdown
    $methods_stmt = $db->prepare("
        SELECT 
            payment_method,
            COUNT(*) as payment_count,
            COALESCE(SUM(amount), 0) as total_amount,
            COALESCE(AVG(amount), 0) as average_amount
        FROM payments 
        WHERE status = 'Completed' 
        AND type = 'Receive'
        AND payment_date BETWEEN ? AND ?
        GROUP BY payment_method 
        ORDER BY total_amount DESC
    ");
    $methods_stmt->execute([$start_date, $end_date]);
    $payment_methods = $methods_stmt->fetchAll();

    // Top Performing Customers
    $top_customers_stmt = $db->prepare("
        SELECT 
            c.name as customer_name,
            COUNT(p.id) as payment_count,
            COALESCE(SUM(p.amount), 0) as total_paid,
            COALESCE(AVG(p.amount), 0) as average_payment,
            MAX(p.payment_date) as last_payment_date
        FROM payments p 
        JOIN business_contacts c ON p.contact_id = c.contact_id 
        WHERE p.status = 'Completed' 
        AND p.type = 'Receive'
        AND p.payment_date BETWEEN ? AND ?
        GROUP BY c.contact_id, c.name 
        ORDER BY total_paid DESC 
        LIMIT 10
    ");
    $top_customers_stmt->execute([$start_date, $end_date]);
    $top_performers = $top_customers_stmt->fetchAll();

    // Aging Analysis
    $aging_stmt = $db->query("
        SELECT 
            COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(), due_date) <= 30 THEN amount ELSE 0 END), 0) as current_0_30,
            COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(), due_date) BETWEEN 31 AND 60 THEN amount ELSE 0 END), 0) as overdue_31_60,
            COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(), due_date) BETWEEN 61 AND 90 THEN amount ELSE 0 END), 0) as overdue_61_90,
            COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(), due_date) > 90 THEN amount ELSE 0 END), 0) as overdue_90_plus,
            COUNT(*) as total_invoices,
            COALESCE(SUM(amount), 0) as total_outstanding
        FROM invoices 
        WHERE status IN ('Pending', 'Overdue')
    ");
    $aging_analysis = $aging_stmt->fetch();

    // Daily Collection Trend (for charts)
    $daily_trend_stmt = $db->prepare("
        SELECT 
            DATE(payment_date) as collection_date,
            COUNT(*) as payment_count,
            COALESCE(SUM(amount), 0) as daily_total
        FROM payments 
        WHERE status = 'Completed' 
        AND type = 'Receive'
        AND payment_date BETWEEN ? AND ?
        GROUP BY DATE(payment_date)
        ORDER BY collection_date
    ");
    $daily_trend_stmt->execute([$start_date, $end_date]);
    $daily_trend = $daily_trend_stmt->fetchAll();

    // Monthly Comparison
    $monthly_comparison_stmt = $db->query("
        SELECT 
            DATE_FORMAT(payment_date, '%Y-%m') as month_year,
            COUNT(*) as payment_count,
            COALESCE(SUM(amount), 0) as monthly_total
        FROM payments 
        WHERE status = 'Completed' 
        AND type = 'Receive'
        AND payment_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
        GROUP BY DATE_FORMAT(payment_date, '%Y-%m')
        ORDER BY month_year
    ");
    $monthly_comparison = $monthly_comparison_stmt->fetchAll();

} catch (Exception $e) {
    error_log("Data fetch error: " . $e->getMessage());
    // Use empty arrays if database fetch fails
}

// Function to get notifications
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

// Fetch notifications
$notifications = getNotifications($db, $user_id);
$unread_notifications = array_filter($notifications, fn($n) => empty($n['is_read']));

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

// Function to mask numbers with asterisks
function maskNumber($number, $masked = true) {
    if (!$masked) {
        $number = (float)$number;
        return number_format($number, 2);
    }
    
    $numberStr = (string)$number;
    $parts = explode('.', $numberStr);
    $integerPart = $parts[0];
    
    // Mask the integer part
    return str_repeat('*', strlen($integerPart));
}

// Add session variable to track number visibility
if (!isset($_SESSION['show_numbers'])) {
    $_SESSION['show_numbers'] = false;
}

// Toggle number visibility
if (isset($_GET['toggle_numbers'])) {
    $_SESSION['show_numbers'] = !$_SESSION['show_numbers'];
    header("Location: " . str_replace("?toggle_numbers=1", "", $_SERVER['REQUEST_URI']));
    exit;
}

// Function to format numbers with asterisks if hidden
function formatNumber($number, $show_numbers = false) {
    // Ensure the input is treated as float
    $number = (float)$number;
    
    if ($show_numbers) {
        return '₱' . number_format($number, 2);
    } else {
        return '₱' . str_repeat('*', max(6, min(12, strlen(number_format($number, 2)))));
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no" />
    <title>Collection Reports | Financial Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
    <style>
        * {
            box-sizing: border-box;
        }
        
        html, body {
            font-size: 16px;
            overflow-x: hidden;
        }
        
        /* Fix for iOS zoom */
        input, select, textarea {
            font-size: 16px !important;
        }
        
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
        
        /* Better scaling for laptop screens */
        @media (min-width: 768px) and (max-width: 1440px) {
            .dashboard-container {
                zoom: 0.95;
            }
            
            .stat-card {
                zoom: 1;
            }
        }
        
        /* Mobile specific fixes */
        @media (max-width: 768px) {
            html {
                font-size: 14px;
            }
            
            .chart-container {
                height: 250px;
            }
            
            .modal-content {
                width: 95%;
                margin: 10% auto;
                padding: 1rem;
            }
        }
    </style>
</head>
<body class="bg-brand-background-main min-h-screen dashboard-container">

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
                <img src="assets/images/logo.png" alt="Financial Dashboard Logo" class="w-10 h-10">
                <div class="leading-tight">
                    <div class="font-bold text-gray-800 group-hover:text-brand-primary transition-colors">
                        Financial Dashboard
                    </div>
                    <div class="text-[11px] text-gray-500 font-semibold uppercase group-hover:text-brand-primary transition-colors">
                        Microfinancial System
                    </div>
                </div>
            </a>
        </div>

        <!-- Sidebar content - Optimized for laptop screens -->
        <div class="px-4 py-4 overflow-y-auto h-[calc(100vh-4rem)] max-h-[calc(100vh-4rem)] custom-scrollbar">
            <!-- Added wrapper div for better control -->
            <div class="space-y-4">
                <div class="text-xs font-bold text-gray-400 tracking-wider px-2">MAIN MENU</div>

                <a href="dashboard8.php"
                    class="flex items-center justify-between px-4 py-3 rounded-xl text-gray-700 hover:bg-green-50 hover:text-brand-primary transition-all duration-200 hover:translate-x-1 active:translate-x-0 active:scale-[0.99] font-semibold">
                    <span class="flex items-center gap-3">
                        <span class="inline-flex w-8 h-8 rounded-lg bg-emerald-50 items-center justify-center">
                            <i class='bx bx-home text-brand-primary text-sm'></i>
                        </span>
                        Financial Dashboard
                    </span>
                </a>

                <!-- All dropdown sections with optimized spacing -->
                <div class="space-y-4">
                    <!-- DISBURSEMENT DROPDOWN -->
                    <div>
                        <div class="text-xs font-bold text-gray-400 tracking-wider px-2">DISBURSEMENT</div>
                        <button id="disbursement-menu-btn"
                            class="mt-2 w-full flex items-center justify-between px-4 py-2.5 rounded-xl
                                   text-gray-700 hover:bg-green-50 hover:text-brand-primary
                                   transition-all duration-200 hover:translate-x-1 active:translate-x-0 active:scale-[0.99] font-semibold text-sm">
                            <span class="flex items-center gap-3">
                                <span class="inline-flex w-8 h-8 rounded-lg bg-emerald-50 items-center justify-center">
                                    <i class='bx bx-money text-brand-primary text-sm'></i>
                                </span>
                                Disbursement
                            </span>
                            <svg id="disbursement-arrow" class="w-4 h-4 text-emerald-400 transition-transform duration-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                            </svg>
                        </button>
                        <div id="disbursement-submenu" class="submenu mt-1">
                            <div class="pl-4 pr-2 py-1.5 space-y-1 border-l-2 border-gray-100 ml-5">
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
                            class="mt-2 w-full flex items-center justify-between px-4 py-2.5 rounded-xl
                                   text-gray-700 hover:bg-green-50 hover:text-brand-primary
                                   transition-all duration-200 hover:translate-x-1 active:translate-x-0 active:scale-[0.99] font-semibold text-sm">
                            <span class="flex items-center gap-3">
                                <span class="inline-flex w-8 h-8 rounded-lg bg-emerald-50 items-center justify-center">
                                    <i class='bx bx-book text-brand-primary text-sm'></i>
                                </span>
                                General Ledger
                            </span>
                            <svg id="ledger-arrow" class="w-4 h-4 text-emerald-400 transition-transform duration-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                            </svg>
                        </button>
                        <div id="ledger-submenu" class="submenu mt-1">
                            <div class="pl-4 pr-2 py-1.5 space-y-1 border-l-2 border-gray-100 ml-5">
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
                            class="mt-2 w-full flex items-center justify-between px-4 py-2.5 rounded-xl
                                   text-gray-700 hover:bg-green-50 hover:text-brand-primary
                                   transition-all duration-200 hover:translate-x-1 active:translate-x-0 active:scale-[0.99] font-semibold text-sm">
                            <span class="flex items-center gap-3">
                                <span class="inline-flex w-8 h-8 rounded-lg bg-emerald-50 items-center justify-center">
                                    <i class='bx bx-receipt text-brand-primary text-sm'></i>
                                </span>
                                AP/AR
                            </span>
                            <svg id="ap-ar-arrow" class="w-4 h-4 text-emerald-400 transition-transform duration-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                            </svg>
                        </button>
                        <div id="ap-ar-submenu" class="submenu mt-1">
                            <div class="pl-4 pr-2 py-1.5 space-y-1 border-l-2 border-gray-100 ml-5">
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
                            class="mt-2 w-full flex items-center justify-between px-4 py-2.5 rounded-xl
                                   text-gray-700 hover:bg-green-50 hover:text-brand-primary
                                   transition-all duration-200 hover:translate-x-1 active:translate-x-0 active:scale-[0.99] font-semibold text-sm">
                            <span class="flex items-center gap-3">
                                <span class="inline-flex w-8 h-8 rounded-lg bg-emerald-50 items-center justify-center">
                                    <i class='bx bx-collection text-brand-primary text-sm'></i>
                                </span>
                                Collection
                            </span>
                            <svg id="collection-arrow" class="w-4 h-4 text-emerald-400 transition-transform duration-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                            </svg>
                        </button>
                        <div id="collection-submenu" class="submenu active mt-1">
                            <div class="pl-4 pr-2 py-1.5 space-y-1 border-l-2 border-gray-100 ml-5">
                                <a href="payment_entry_collection.php" class="block px-3 py-1.5 rounded-lg text-xs text-gray-600 hover:bg-green-50 hover:text-brand-primary transition-all duration-200 hover:translate-x-1">Payment Entry</a>
                                <a href="receipt_generation.php" class="block px-3 py-1.5 rounded-lg text-xs text-gray-600 hover:bg-green-50 hover:text-brand-primary transition-all duration-200 hover:translate-x-1">Receipt Generation</a>
                                <a href="collection_dashboard.php" class="block px-3 py-1.5 rounded-lg text-xs text-gray-600 hover:bg-green-50 hover:text-brand-primary transition-all duration-200 hover:translate-x-1">Collection Dashboard</a>
                                <a href="outstanding_balances.php" class="block px-3 py-1.5 rounded-lg text-xs text-gray-600 hover:bg-green-50 hover:text-brand-primary transition-all duration-200 hover:translate-x-1">Outstanding Balances</a>
                                <a href="collection_reports.php" class="block px-3 py-1.5 rounded-lg text-xs text-brand-primary hover:bg-green-50 hover:text-brand-primary transition-all duration-200 hover:translate-x-1 font-semibold">Collection Reports</a>
                            </div>
                        </div>
                    </div>

                    <!-- BUDGET DROPDOWN -->
                    <div>
                        <div class="text-xs font-bold text-gray-400 tracking-wider px-2">BUDGET MANAGEMENT</div>
                        <button id="budget-menu-btn"
                            class="mt-2 w-full flex items-center justify-between px-4 py-2.5 rounded-xl
                                   text-gray-700 hover:bg-green-50 hover:text-brand-primary
                                   transition-all duration-200 hover:translate-x-1 active:translate-x-0 active:scale-[0.99] font-semibold text-sm">
                            <span class="flex items-center gap-3">
                                <span class="inline-flex w-8 h-8 rounded-lg bg-emerald-50 items-center justify-center">
                                    <i class='bx bx-pie-chart-alt text-brand-primary text-sm'></i>
                                </span>
                                Budget Management
                            </span>
                            <svg id="budget-arrow" class="w-4 h-4 text-emerald-400 transition-transform duration-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                            </svg>
                        </button>
                        <div id="budget-submenu" class="submenu mt-1">
                            <div class="pl-4 pr-2 py-1.5 space-y-1 border-l-2 border-gray-100 ml-5">
                                <a href="budget_proposal.php" class="block px-3 py-1.5 rounded-lg text-xs text-gray-600 hover:bg-green-50 hover:text-brand-primary transition-all duration-200 hover:translate-x-1">Budget Proposal</a>
                                <a href="approval_workflow.php" class="block px-3 py-1.5 rounded-lg text-xs text-gray-600 hover:bg-green-50 hover:text-brand-primary transition-all duration-200 hover:translate-x-1">Approval Workflow</a>
                                <a href="budget_vs_actual.php" class="block px-3 py-1.5 rounded-lg text-xs text-gray-600 hover:bg-green-50 hover:text-brand-primary transition-all duration-200 hover:translate-x-1">Budget vs Actual</a>
                                <a href="budget_reports.php" class="block px-3 py-1.5 rounded-lg text-xs text-gray-600 hover:bg-green-50 hover:text-brand-primary transition-all duration-200 hover:translate-x-1">Budget Reports</a>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Footer - Moved to bottom but within container -->
                <div class="pt-4 mt-4 border-t border-gray-100">
                    <div class="flex items-center gap-2 text-xs font-bold text-emerald-600">
                        <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
                        SYSTEM ONLINE
                    </div>
                    <div class="text-[10px] text-gray-400 mt-2 leading-snug">
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

    <div class="flex items-center gap-3 flex-1 min-w-0">
        <button id="mobile-menu-btn"
            class="md:hidden w-10 h-10 rounded-xl hover:bg-gray-100 active:bg-gray-200 transition flex items-center justify-center flex-shrink-0">
            ☰
        </button>
        <div class="min-w-0">
            <h1 class="text-base sm:text-lg font-bold text-gray-800 truncate">
                Collection Reports
            </h1>
            <p class="text-xs text-gray-500 truncate">
                Welcome Back, <?php echo htmlspecialchars($user['name']); ?>!
            </p>
        </div>
    </div>

    <div class="flex items-center gap-2 sm:gap-3 lg:gap-4 flex-shrink-0">
        <!-- Real-time Clock - Hide on mobile, show on tablet+ -->
        <span id="real-time-clock"
            class="hidden sm:inline-flex text-xs font-bold text-gray-700 bg-gray-50 px-3 py-2 rounded-lg border border-gray-200 whitespace-nowrap">
            --:--:--
        </span>

        <!-- Visibility Toggle -->
        <a href="?toggle_numbers=1" class="w-10 h-10 rounded-xl hover:bg-gray-100 active:bg-gray-200 transition flex items-center justify-center relative flex-shrink-0"
                title="<?php echo $_SESSION['show_numbers'] ? 'Hide Numbers' : 'Show Numbers'; ?>">
            <i class="fa-solid <?php echo $_SESSION['show_numbers'] ? 'fa-eye' : 'fa-eye-slash'; ?> text-gray-600 text-sm"></i>
        </a>

        <!-- Notifications -->
        <button id="notification-btn" class="w-10 h-10 rounded-xl hover:bg-gray-100 active:bg-gray-200 transition flex items-center justify-center relative flex-shrink-0">
            <i class="fa-solid fa-bell text-gray-600 text-sm"></i>
            <?php if(count($unread_notifications) > 0): ?>
                <span class="notification-badge"></span>
            <?php endif; ?>
        </button>

        <!-- Divider - Hide on mobile, show on tablet+ -->
        <div class="h-8 w-px bg-gray-200 hidden sm:block"></div>

        <!-- User Profile Dropdown -->
        <div class="relative">
            <button id="user-menu-button"
                class="flex items-center gap-2 focus:outline-none group rounded-xl px-2 py-2
                       hover:bg-gray-100 active:bg-gray-200 transition">
                <div class="w-8 h-8 sm:w-9 sm:h-9 md:w-10 md:h-10 rounded-full bg-white shadow group-hover:shadow-md transition-shadow overflow-hidden flex items-center justify-center border border-gray-100 flex-shrink-0">
                    <div class="w-full h-full flex items-center justify-center font-bold text-brand-primary bg-emerald-50 text-sm sm:text-base">
                        <?php echo strtoupper(substr($user['name'], 0, 1)); ?>
                    </div>
                </div>
                <div class="hidden md:flex flex-col items-start text-left">
                    <span class="text-xs sm:text-sm font-bold text-gray-700 group-hover:text-brand-primary transition-colors truncate max-w-[120px]">
                        <?php echo htmlspecialchars($user['name']); ?>
                    </span>
                    <span class="text-[10px] text-gray-500 font-medium uppercase group-hover:text-brand-primary transition-colors truncate max-w-[120px]">
                        <?php echo ucfirst(htmlspecialchars($user['role'])); ?>
                    </span>
                </div>
                <svg class="hidden md:block w-4 h-4 text-gray-400 group-hover:text-brand-primary transition-colors flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
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
            <!-- Report Filters -->
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 mb-6">
                <h3 class="text-lg font-bold text-gray-800 mb-4">Report Parameters</h3>
                <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Start Date</label>
                        <input type="date" name="start_date" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-brand-primary focus:border-transparent" 
                               value="<?= $start_date ?>" required>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">End Date</label>
                        <input type="date" name="end_date" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-brand-primary focus:border-transparent" 
                               value="<?= $end_date ?>" required>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Report Type</label>
                        <select name="report_type" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-brand-primary focus:border-transparent">
                            <option value="summary" <?= $report_type === 'summary' ? 'selected' : '' ?>>Summary Report</option>
                            <option value="detailed" <?= $report_type === 'detailed' ? 'selected' : '' ?>>Detailed Report</option>
                            <option value="aging" <?= $report_type === 'aging' ? 'selected' : '' ?>>Aging Analysis</option>
                        </select>
                    </div>
                    <div class="flex items-end">
                        <button type="submit" class="w-full px-4 py-2 bg-brand-primary text-white rounded-lg hover:bg-brand-primary-hover transition font-medium flex items-center justify-center gap-2">
                            <i class="fa-solid fa-filter"></i> Generate Report
                        </button>
                    </div>
                </form>
            </div>

            <!-- Report Period Summary -->
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 mb-6">
                <div class="flex justify-between items-center">
                    <div>
                        <h3 class="text-lg font-bold text-gray-800">Report Period: <?= date('F j, Y', strtotime($start_date)) ?> - <?= date('F j, Y', strtotime($end_date)) ?></h3>
                        <p class="text-sm text-gray-500">Collection performance analysis</p>
                    </div>
                    <div class="flex space-x-3">
                        <button onclick="printReport()" class="px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 transition flex items-center gap-2 text-sm">
                            <i class="fas fa-print"></i> Print
                        </button>
                        <button onclick="exportToPDF()" class="px-4 py-2 bg-brand-primary text-white rounded-lg hover:bg-brand-primary-hover transition flex items-center gap-2 text-sm">
                            <i class="fas fa-file-pdf"></i> Export PDF
                        </button>
                        <button onclick="exportToExcel()" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition flex items-center gap-2 text-sm">
                            <i class="fas fa-file-excel"></i> Export Excel
                        </button>
                    </div>
                </div>
            </div>

            <!-- Tabs for different report views -->
            <div class="flex border-b border-gray-200 mb-6">
                <button class="tab-btn px-4 py-2 font-medium text-gray-600 hover:text-brand-primary border-b-2 border-transparent hover:border-brand-primary transition-all duration-200 active" data-tab="summary">
                    Summary
                </button>
                <button class="tab-btn px-4 py-2 font-medium text-gray-600 hover:text-brand-primary border-b-2 border-transparent hover:border-brand-primary transition-all duration-200" data-tab="performance">
                    Performance
                </button>
                <button class="tab-btn px-4 py-2 font-medium text-gray-600 hover:text-brand-primary border-b-2 border-transparent hover:border-brand-primary transition-all duration-200" data-tab="aging">
                    Aging Analysis
                </button>
                <button class="tab-btn px-4 py-2 font-medium text-gray-600 hover:text-brand-primary border-b-2 border-transparent hover:border-brand-primary transition-all duration-200" data-tab="methods">
                    Payment Methods
                </button>
            </div>

            <!-- Summary Tab -->
            <div class="tab-content active" id="summary-tab">
                <!-- Key Metrics -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-6">
                    <?php 
                    $summary_stats = [
                        ['icon' => 'bx-money', 'label' => 'Total Collected', 'value' => (float)($collection_summary['total_collected'] ?? 0), 'color' => 'green', 'stat' => 'collected'],
                        ['icon' => 'bx-credit-card', 'label' => 'Total Payments', 'value' => (int)($collection_summary['total_payments'] ?? 0), 'color' => 'blue', 'stat' => 'payments'],
                        ['icon' => 'bx-group', 'label' => 'Active Customers', 'value' => (int)($collection_summary['unique_customers'] ?? 0), 'color' => 'purple', 'stat' => 'customers'],
                        ['icon' => 'bx-trending-up', 'label' => 'Average Payment', 'value' => (float)($collection_summary['average_payment'] ?? 0), 'color' => 'yellow', 'stat' => 'average']
                    ];
                    
                    foreach($summary_stats as $stat): 
                        $bgColors = [
                            'green' => 'bg-green-100',
                            'blue' => 'bg-blue-100',
                            'purple' => 'bg-purple-100',
                            'yellow' => 'bg-yellow-100',
                            'red' => 'bg-red-100'
                        ];
                        $textColors = [
                            'green' => 'text-green-600',
                            'blue' => 'text-blue-600',
                            'purple' => 'text-purple-600',
                            'yellow' => 'text-yellow-600',
                            'red' => 'text-red-600'
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
                                           data-value="<?php 
                                               if (in_array($stat['stat'], ['collected', 'average'])) {
                                                   echo '₱' . number_format((float)$stat['value'], 2);
                                               } else {
                                                   echo (int)$stat['value'];
                                               }
                                           ?>"
                                           data-stat="<?php echo $stat['stat']; ?>">
                                            <?php 
                                                if (in_array($stat['stat'], ['collected', 'average'])) {
                                                    echo $_SESSION['show_numbers'] ? '₱' . number_format((float)$stat['value'], 2) : '••••••••';
                                                } else {
                                                    echo (int)$stat['value'];
                                                }
                                            ?>
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Charts Row -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                    <!-- Collection Trend Chart -->
                    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                        <h3 class="text-lg font-bold text-gray-800 mb-6">Collection Trend</h3>
                        <div class="chart-container">
                            <canvas id="collectionTrendChart"></canvas>
                        </div>
                    </div>

                    <!-- Payment Methods Chart -->
                    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                        <h3 class="text-lg font-bold text-gray-800 mb-6">Payment Methods Distribution</h3>
                        <div class="chart-container">
                            <canvas id="paymentMethodsChart"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Monthly Comparison -->
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                    <h3 class="text-lg font-bold text-gray-800 mb-6">Monthly Collection Comparison (Last 6 Months)</h3>
                    <div class="chart-container">
                        <canvas id="monthlyComparisonChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Performance Tab -->
            <div class="tab-content" id="performance-tab">
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                    <div class="p-6 border-b border-gray-100">
                        <h3 class="text-lg font-bold text-gray-800">Top Performing Customers</h3>
                        <p class="text-sm text-gray-500">Top 10 customers by total payments</p>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="text-left p-4 text-sm font-medium text-gray-500">Customer</th>
                                    <th class="text-left p-4 text-sm font-medium text-gray-500">Total Paid</th>
                                    <th class="text-left p-4 text-sm font-medium text-gray-500">Payment Count</th>
                                    <th class="text-left p-4 text-sm font-medium text-gray-500">Average Payment</th>
                                    <th class="text-left p-4 text-sm font-medium text-gray-500">Last Payment</th>
                                    <th class="text-left p-4 text-sm font-medium text-gray-500">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($top_performers)): ?>
                                    <tr>
                                        <td colspan="6" class="p-8 text-center text-gray-500">
                                            <i class='bx bx-folder-open text-3xl mb-2 text-gray-300'></i>
                                            <div>No payment data available</div>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($top_performers as $customer): ?>
                                        <tr class="transaction-row">
                                            <td class="p-4">
                                                <div class="font-medium text-gray-800"><?= safe_output($customer['customer_name']) ?></div>
                                            </td>
                                            <td class="p-4 font-bold text-green-600 <?php echo !$_SESSION['show_numbers'] ? 'hidden-amount' : ''; ?>">
                                                <?php 
                                                    $total_paid = (float)($customer['total_paid'] ?? 0);
                                                    echo $_SESSION['show_numbers'] ? '₱' . number_format($total_paid, 2) : '••••••••';
                                                ?>
                                            </td>
                                            <td class="p-4">
                                                <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-blue-50 text-blue-700">
                                                    <?= (int)($customer['payment_count'] ?? 0) ?> payments
                                                </span>
                                            </td>
                                            <td class="p-4 text-gray-600 <?php echo !$_SESSION['show_numbers'] ? 'hidden-amount' : ''; ?>">
                                                <?php 
                                                    $average_payment = (float)($customer['average_payment'] ?? 0);
                                                    echo $_SESSION['show_numbers'] ? '₱' . number_format($average_payment, 2) : '••••••••';
                                                ?>
                                            </td>
                                            <td class="p-4 text-gray-600">
                                                <?= date('M j, Y', strtotime($customer['last_payment_date'] ?? 'now')) ?>
                                            </td>
                                            <td class="p-4">
                                                <a href="#" class="px-3 py-1.5 text-sm bg-brand-primary text-white rounded-lg hover:bg-brand-primary-hover transition inline-flex items-center">
                                                    <i class='bx bx-show mr-1'></i> Details
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Aging Analysis Tab -->
            <div class="tab-content" id="aging-tab">
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <!-- Aging Summary -->
                    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                        <h3 class="text-lg font-bold text-gray-800 mb-6">Aging Analysis Summary</h3>
                        <div class="space-y-6">
                            <?php
                            $aging_items = [
                                ['label' => 'Current (0-30 days)', 'value' => (float)($aging_analysis['current_0_30'] ?? 0), 'color' => 'bg-green-500', 'text' => 'text-green-600'],
                                ['label' => '31-60 Days', 'value' => (float)($aging_analysis['overdue_31_60'] ?? 0), 'color' => 'bg-yellow-500', 'text' => 'text-yellow-600'],
                                ['label' => '61-90 Days', 'value' => (float)($aging_analysis['overdue_61_90'] ?? 0), 'color' => 'bg-orange-500', 'text' => 'text-orange-600'],
                                ['label' => '90+ Days', 'value' => (float)($aging_analysis['overdue_90_plus'] ?? 0), 'color' => 'bg-red-500', 'text' => 'text-red-600']
                            ];
                            $total_outstanding_value = (float)($aging_analysis['total_outstanding'] ?? 0);
                            ?>
                            <?php foreach($aging_items as $item): 
                                $item_value = (float)($item['value'] ?? 0);
                                $percentage = $total_outstanding_value > 0 ? ($item_value / $total_outstanding_value * 100) : 0;
                            ?>
                                <div>
                                    <div class="flex justify-between text-sm mb-2">
                                        <span class="font-semibold <?php echo $item['text']; ?>"><?php echo $item['label']; ?></span>
                                        <span class="font-bold <?php echo !$_SESSION['show_numbers'] ? 'hidden-amount' : ''; ?> <?php echo $item['text']; ?>">
                                            <?php echo $_SESSION['show_numbers'] ? '₱' . number_format($item_value, 2) : '••••••••'; ?>
                                        </span>
                                    </div>
                                    <div class="w-full h-2 bg-gray-200 rounded-full overflow-hidden">
                                        <div class="h-full <?php echo $item['color']; ?> rounded-full" 
                                             style="width: <?= $percentage ?>%">
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="mt-6 p-4 bg-gray-50 rounded-xl">
                            <div class="flex justify-between items-center">
                                <span class="font-bold text-gray-800">Total Outstanding:</span>
                                <span class="text-xl font-bold text-red-600 <?php echo !$_SESSION['show_numbers'] ? 'hidden-amount' : ''; ?>">
                                    <?php echo $_SESSION['show_numbers'] ? '₱' . number_format($total_outstanding_value, 2) : '••••••••'; ?>
                                </span>
                            </div>
                            <div class="flex justify-between items-center mt-2">
                                <span class="text-sm text-gray-600">Total Invoices:</span>
                                <span class="text-sm font-semibold"><?= (int)($aging_analysis['total_invoices'] ?? 0) ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- Aging Chart -->
                    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                        <h3 class="text-lg font-bold text-gray-800 mb-6">Aging Distribution</h3>
                        <div class="chart-container">
                            <canvas id="agingChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Payment Methods Tab -->
            <div class="tab-content" id="methods-tab">
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                    <div class="p-6 border-b border-gray-100">
                        <h3 class="text-lg font-bold text-gray-800">Payment Methods Analysis</h3>
                        <p class="text-sm text-gray-500">Breakdown of payment methods used</p>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="text-left p-4 text-sm font-medium text-gray-500">Payment Method</th>
                                    <th class="text-left p-4 text-sm font-medium text-gray-500">Payment Count</th>
                                    <th class="text-left p-4 text-sm font-medium text-gray-500">Total Amount</th>
                                    <th class="text-left p-4 text-sm font-medium text-gray-500">Average Amount</th>
                                    <th class="text-left p-4 text-sm font-medium text-gray-500">Percentage</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($payment_methods)): ?>
                                    <tr>
                                        <td colspan="5" class="p-8 text-center text-gray-500">
                                            <i class='bx bx-folder-open text-3xl mb-2 text-gray-300'></i>
                                            <div>No payment method data available</div>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php 
                                    $total_amount = (float)($collection_summary['total_collected'] ?? 1); // Avoid division by zero
                                    foreach ($payment_methods as $method): 
                                        $method_total = (float)($method['total_amount'] ?? 0);
                                        $percentage = ($total_amount > 0) ? ($method_total / $total_amount * 100) : 0;
                                    ?>
                                        <tr class="transaction-row">
                                            <td class="p-4 font-medium text-gray-800"><?= safe_output($method['payment_method']) ?></td>
                                            <td class="p-4">
                                                <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-blue-50 text-blue-700">
                                                    <?= (int)($method['payment_count'] ?? 0) ?>
                                                </span>
                                            </td>
                                            <td class="p-4 font-bold text-gray-800 <?php echo !$_SESSION['show_numbers'] ? 'hidden-amount' : ''; ?>">
                                                <?php 
                                                    echo $_SESSION['show_numbers'] ? '₱' . number_format($method_total, 2) : '••••••••';
                                                ?>
                                            </td>
                                            <td class="p-4 text-gray-600 <?php echo !$_SESSION['show_numbers'] ? 'hidden-amount' : ''; ?>">
                                                <?php 
                                                    $average_amount = (float)($method['average_amount'] ?? 0);
                                                    echo $_SESSION['show_numbers'] ? '₱' . number_format($average_amount, 2) : '••••••••';
                                                ?>
                                            </td>
                                            <td class="p-4">
                                                <div class="flex items-center">
                                                    <div class="w-24 bg-gray-200 rounded-full h-2 mr-3">
                                                        <div class="bg-brand-primary h-2 rounded-full" style="width: <?= $percentage ?>%"></div>
                                                    </div>
                                                    <span class="text-sm font-medium"><?= number_format($percentage, 1) ?>%</span>
                                                </div>
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
                        <div class="flex items-start gap-3 p-4 rounded-lg <?= empty($notification['is_read']) ? 'bg-blue-50' : 'bg-gray-50' ?>">
                            <div class="w-10 h-10 rounded-lg <?= empty($notification['is_read']) ? 'bg-blue-100' : 'bg-gray-100' ?> flex items-center justify-center flex-shrink-0">
                                <i class='bx <?= empty($notification['is_read']) ? 'bx-bell-ring' : 'bx-bell' ?> <?= empty($notification['is_read']) ? 'text-blue-500' : 'text-gray-500' ?>'></i>
                            </div>
                            <div class="flex-1">
                                <div class="flex justify-between items-start">
                                    <div class="font-medium text-gray-800 text-sm"><?= htmlspecialchars($notification['title'] ?? 'Notification') ?></div>
                                    <?php if (empty($notification['is_read'])): ?>
                                        <span class="w-2 h-2 rounded-full bg-blue-500 mt-1 flex-shrink-0"></span>
                                    <?php endif; ?>
                                </div>
                                <div class="text-xs text-gray-600 mt-1"><?= htmlspecialchars($notification['message'] ?? 'Notification message') ?></div>
                                <div class="text-xs text-gray-400 mt-2"><?= date('M d, Y H:i', strtotime($notification['created_at'] ?? 'now')) ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Prevent pinch zoom on mobile
    document.addEventListener('touchmove', function(e) {
        if (e.scale !== 1) e.preventDefault();
    }, { passive: false });
    
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
            sidebar.classList.toggle('active');
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
    const logoutBtn = document.querySelector('a[href*="logout=true"]');
    if (logoutBtn) {
        logoutBtn.addEventListener('click', function(e) {
            e.preventDefault();
            if (confirm('Are you sure you want to logout?')) {
                window.location.href = this.getAttribute('href');
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
    const tabButtons = document.querySelectorAll('.tab-btn');
    const tabContents = document.querySelectorAll('.tab-content');
    
    tabButtons.forEach(button => {
        button.addEventListener('click', function() {
            const tabId = this.getAttribute('data-tab');
            
            // Remove active class from all tabs and contents
            tabButtons.forEach(tab => tab.classList.remove('active'));
            tabContents.forEach(content => content.classList.remove('active'));
            
            // Add active class to clicked tab and corresponding content
            this.classList.add('active');
            document.getElementById(`${tabId}-tab`).classList.add('active');
        });
    });
    
    // Initialize charts
    initializeCharts();
});

function initializeCharts() {
    // Collection Trend Chart
    const trendCanvas = document.getElementById('collectionTrendChart');
    if (trendCanvas) {
        const trendCtx = trendCanvas.getContext('2d');
        const dailyTrendData = <?php 
            if (isset($daily_trend) && is_array($daily_trend)) {
                echo json_encode($daily_trend);
            } else {
                echo '[]';
            }
        ?>;
        
        // Prepare chart data
        const trendLabels = dailyTrendData.map(item => item.collection_date || '');
        const trendData = dailyTrendData.map(item => parseFloat(item.daily_total) || 0);
        
        new Chart(trendCtx, {
            type: 'line',
            data: {
                labels: trendLabels,
                datasets: [{
                    label: 'Daily Collection (₱)',
                    data: trendData,
                    borderColor: '#059669',
                    backgroundColor: 'rgba(5, 150, 105, 0.1)',
                    borderWidth: 2,
                    fill: true,
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: {
                            drawBorder: false
                        },
                        ticks: {
                            callback: function(value) {
                                return '₱' + value.toLocaleString();
                            }
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        }
                    }
                }
            }
        });
    }

    // Payment Methods Chart
    const methodsCanvas = document.getElementById('paymentMethodsChart');
    if (methodsCanvas) {
        const methodsCtx = methodsCanvas.getContext('2d');
        const paymentMethodsData = <?php 
            if (isset($payment_methods) && is_array($payment_methods)) {
                echo json_encode($payment_methods);
            } else {
                echo '[]';
            }
        ?>;
        
        // Prepare chart data
        const methodLabels = paymentMethodsData.map(item => item.payment_method || 'Unknown');
        const methodData = paymentMethodsData.map(item => parseFloat(item.total_amount) || 0);
        
        new Chart(methodsCtx, {
            type: 'doughnut',
            data: {
                labels: methodLabels,
                datasets: [{
                    data: methodData,
                    backgroundColor: [
                        '#059669',
                        '#10B981',
                        '#34D399',
                        '#6EE7B7',
                        '#A7F3D0',
                        '#D1FAE5'
                    ],
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

    // Monthly Comparison Chart
    const monthlyCanvas = document.getElementById('monthlyComparisonChart');
    if (monthlyCanvas) {
        const monthlyCtx = monthlyCanvas.getContext('2d');
        const monthlyComparisonData = <?php 
            if (isset($monthly_comparison) && is_array($monthly_comparison)) {
                echo json_encode($monthly_comparison);
            } else {
                echo '[]';
            }
        ?>;
        
        // Prepare chart data
        const monthlyLabels = monthlyComparisonData.map(item => item.month_year || '');
        const monthlyData = monthlyComparisonData.map(item => parseFloat(item.monthly_total) || 0);
        
        new Chart(monthlyCtx, {
            type: 'bar',
            data: {
                labels: monthlyLabels,
                datasets: [{
                    label: 'Monthly Collection (₱)',
                    data: monthlyData,
                    backgroundColor: '#059669',
                    borderColor: '#047857',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: {
                            drawBorder: false
                        },
                        ticks: {
                            callback: function(value) {
                                return '₱' + value.toLocaleString();
                            }
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        }
                    }
                }
            }
        });
    }

    // Aging Chart
    const agingCanvas = document.getElementById('agingChart');
    if (agingCanvas) {
        const agingCtx = agingCanvas.getContext('2d');
        const agingData = <?php 
            if (isset($aging_analysis)) {
                echo json_encode($aging_analysis);
            } else {
                echo '{"current_0_30":0,"overdue_31_60":0,"overdue_61_90":0,"overdue_90_plus":0}';
            }
        ?>;
        
        new Chart(agingCtx, {
            type: 'bar',
            data: {
                labels: ['Current (0-30)', '31-60 Days', '61-90 Days', '90+ Days'],
                datasets: [{
                    label: 'Amount (₱)',
                    data: [
                        parseFloat(agingData.current_0_30) || 0,
                        parseFloat(agingData.overdue_31_60) || 0,
                        parseFloat(agingData.overdue_61_90) || 0,
                        parseFloat(agingData.overdue_90_plus) || 0
                    ],
                    backgroundColor: [
                        '#10B981',
                        '#F59E0B',
                        '#F97316',
                        '#EF4444'
                    ],
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: {
                            drawBorder: false
                        },
                        ticks: {
                            callback: function(value) {
                                return '₱' + value.toLocaleString();
                            }
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        }
                    }
                }
            }
        });
    }
}

// Export functions
function printReport() {
    window.print();
}

function exportToPDF() {
    alert('PDF export functionality would be implemented here');
    // In a real implementation, this would generate and download a PDF report
}

function exportToExcel() {
    alert('Excel export functionality would be implemented here');
    // In a real implementation, this would generate and download an Excel report
}
</script>
</body>
</html>