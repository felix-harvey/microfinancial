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
$collection_stats = [];
$recent_payments = [];
$overdue_invoices = [];
$aging_summary = [];
$top_customers = [];

// Fetch collection statistics
try {
    // Overall collection stats
    $stats_stmt = $db->query("
        SELECT 
            COUNT(*) as total_payments,
            COALESCE(SUM(amount), 0) as total_collected,
            COALESCE(AVG(amount), 0) as average_payment,
            COALESCE(MAX(amount), 0) as largest_payment,
            COUNT(DISTINCT contact_id) as unique_customers
        FROM payments 
        WHERE status = 'Completed' AND type = 'Receive'
        AND payment_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    ");
    $collection_stats = $stats_stmt->fetch();
    
    // Recent payments
    $recent_payments_stmt = $db->query("
        SELECT p.*, c.name as contact_name, i.invoice_number 
        FROM payments p 
        LEFT JOIN business_contacts c ON p.contact_id = c.id 
        LEFT JOIN invoices i ON p.invoice_id = i.id 
        WHERE p.status = 'Completed' AND p.type = 'Receive'
        ORDER BY p.payment_date DESC 
        LIMIT 10
    ");
    $recent_payments = $recent_payments_stmt->fetchAll();
    
    // Overdue invoices
    $overdue_stmt = $db->query("
        SELECT i.*, c.name as contact_name, 
               DATEDIFF(CURDATE(), i.due_date) as days_overdue
        FROM invoices i 
        JOIN business_contacts c ON i.contact_id = c.id 
        WHERE i.status IN ('Overdue', 'Pending') 
        AND i.due_date < CURDATE()
        ORDER BY i.due_date ASC 
        LIMIT 10
    ");
    $overdue_invoices = $overdue_stmt->fetchAll();
    
    // Aging summary
    $aging_stmt = $db->query("
        SELECT 
            COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(), due_date) <= 30 THEN amount ELSE 0 END), 0) as current_0_30,
            COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(), due_date) BETWEEN 31 AND 60 THEN amount ELSE 0 END), 0) as overdue_31_60,
            COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(), due_date) BETWEEN 61 AND 90 THEN amount ELSE 0 END), 0) as overdue_61_90,
            COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(), due_date) > 90 THEN amount ELSE 0 END), 0) as overdue_90_plus
        FROM invoices 
        WHERE status IN ('Pending', 'Overdue')
    ");
    $aging_summary = $aging_stmt->fetch();
    
    // Top customers by payment amount
    $top_customers_stmt = $db->query("
        SELECT c.name, COALESCE(SUM(p.amount), 0) as total_paid, COUNT(p.id) as payment_count
        FROM payments p 
        JOIN business_contacts c ON p.contact_id = c.id 
        WHERE p.status = 'Completed' AND p.type = 'Receive'
        AND p.payment_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        GROUP BY c.id, c.name 
        ORDER BY total_paid DESC 
        LIMIT 5
    ");
    $top_customers = $top_customers_stmt->fetchAll();
    
} catch (Exception $e) {
    error_log("Data fetch error: " . $e->getMessage());
    // Use empty arrays if database fetch fails
}

// Handle logout
if (isset($_GET['logout']) && $_GET['logout'] === 'true') {
    $_SESSION = [];
    session_destroy();
    header("Location: index.php");
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no" />
    <title>Collection Dashboard - Financial Dashboard</title>

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
        
        .status-completed {
            background-color: #D1FAE5;
            color: #059669;
        }
        
        .status-processing {
            background-color: #FEF3C7;
            color: #D97706;
        }
        
        .status-pending {
            background-color: #FEF3C7;
            color: #D97706;
        }
        
        .status-overdue {
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
        
        .chart-container {
            height: 320px;
            position: relative;
        }
        
        .progress-bar {
            width: 100%;
            height: 8px;
            background-color: #e5e7eb;
            border-radius: 4px;
            overflow: hidden;
            margin-top: 4px;
        }
        
        .progress-fill {
            height: 100%;
            border-radius: 4px;
            transition: width 0.3s ease;
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
                                   bg-emerald-50 text-brand-primary border border-emerald-100
                                   transition-all duration-200 hover:translate-x-1 active:translate-x-0 active:scale-[0.99] font-semibold text-sm">
                            <span class="flex items-center gap-3">
                                <span class="inline-flex w-8 h-8 rounded-lg bg-emerald-100 items-center justify-center">
                                    <i class='bx bx-collection text-emerald-600 text-sm'></i>
                                </span>
                                Collection
                            </span>
                            <svg id="collection-arrow" class="w-4 h-4 text-emerald-400 transition-transform duration-300 rotate-180" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                            </svg>
                        </button>
                        <div id="collection-submenu" class="submenu mt-1 active">
                            <div class="pl-4 pr-2 py-1.5 space-y-1 border-l-2 border-emerald-200 ml-5">
                                <a href="payment_entry_collection.php" class="block px-3 py-1.5 rounded-lg text-xs text-gray-600 hover:bg-green-50 hover:text-brand-primary transition-all duration-200 hover:translate-x-1">Payment Entry</a>
                                <a href="receipt_generation.php" class="block px-3 py-1.5 rounded-lg text-xs text-gray-600 hover:bg-green-50 hover:text-brand-primary transition-all duration-200 hover:translate-x-1">Receipt Generation</a>
                                <a href="collection_dashboard.php" class="block px-3 py-1.5 rounded-lg text-xs bg-emerald-50 text-brand-primary font-medium border border-emerald-100 hover:bg-emerald-100 hover:border-emerald-200 transition-all duration-200 hover:translate-x-1">
                                    <span class="flex items-center justify-between">
                                        Collection Dashboard
                                        <span class="inline-flex w-1.5 h-1.5 rounded-full bg-emerald-400"></span>
                                    </span>
                                </a>
                                <a href="outstanding_balances.php" class="block px-3 py-1.5 rounded-lg text-xs text-gray-600 hover:bg-green-50 hover:text-brand-primary transition-all duration-200 hover:translate-x-1">Outstanding Balances</a>
                                <a href="collection_reports.php" class="block px-3 py-1.5 rounded-lg text-xs text-gray-600 hover:bg-green-50 hover:text-brand-primary transition-all duration-200 hover:translate-x-1">Collection Reports</a>
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
                        Collection Dashboard
                    </h1>
                    <p class="text-xs text-gray-500 truncate">
                        Monitor collection performance and metrics
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
                <button id="visibility-toggle" class="w-10 h-10 rounded-xl hover:bg-gray-100 active:bg-gray-200 transition flex items-center justify-center relative flex-shrink-0"
                        title="Toggle Amount Visibility">
                    <i class="fa-solid fa-eye-slash text-gray-600 text-sm"></i>
                </button>

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
                        <a href="#" id="dropdown-logout-btn" class="block px-4 py-3 text-sm text-red-600 hover:bg-red-50 transition">
                            <i class='bx bx-log-out mr-2'></i> Logout
                        </a>
                    </div>
                </div>
            </div>
        </header>

        <main id="main-content" class="p-4 sm:p-6">
            <!-- Summary Cards -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-6">
                <?php 
                // Calculate stats with proper casting
                $total_collected = (float)($collection_stats['total_collected'] ?? 0);
                $unique_customers = (int)($collection_stats['unique_customers'] ?? 0);
                $total_payments = (int)($collection_stats['total_payments'] ?? 0);
                $average_payment = (float)($collection_stats['average_payment'] ?? 0);
                
                $stats = [
                    ['icon' => 'bx-money', 'label' => 'Total Collected (30 days)', 'value' => $total_collected, 'color' => 'green', 'stat' => 'total_collected'],
                    ['icon' => 'bx-user-check', 'label' => 'Active Customers', 'value' => $unique_customers, 'color' => 'blue', 'stat' => 'customers'],
                    ['icon' => 'bx-receipt', 'label' => 'Total Payments', 'value' => $total_payments, 'color' => 'purple', 'stat' => 'payments'],
                    ['icon' => 'bx-trending-up', 'label' => 'Average Payment', 'value' => $average_payment, 'color' => 'yellow', 'stat' => 'average_payment']
                ];
                
                foreach($stats as $stat): 
                    $bgColors = [
                        'green' => 'bg-green-100',
                        'blue' => 'bg-blue-100',
                        'purple' => 'bg-purple-100',
                        'yellow' => 'bg-yellow-100'
                    ];
                    $textColors = [
                        'green' => 'text-green-600',
                        'blue' => 'text-blue-600',
                        'purple' => 'text-purple-600',
                        'yellow' => 'text-yellow-600'
                    ];
                    
                    // Format the value for display
                    $displayValue = '';
                    if ($stat['stat'] === 'customers' || $stat['stat'] === 'payments') {
                        $displayValue = (int)$stat['value'];
                    } else {
                        $displayValue = '₱' . number_format((float)$stat['value'], 2);
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
                                       data-value="<?php echo $stat['stat'] === 'customers' || $stat['stat'] === 'payments' ? (int)$stat['value'] : '₱' . number_format((float)$stat['value'], 2); ?>"
                                       data-stat="<?php echo $stat['stat']; ?>">
                                        <?php echo $stat['stat'] === 'customers' || $stat['stat'] === 'payments' ? (int)$stat['value'] : '••••••••'; ?>
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

            <!-- Charts and Detailed Metrics -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                <!-- Collection Trend Chart -->
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                    <div class="flex justify-between items-center mb-6">
                        <h3 class="text-lg font-bold text-gray-800">Collection Trend (Last 30 Days)</h3>
                        <button id="refresh-chart" class="text-brand-primary hover:text-brand-primary-hover transition">
                            <i class='bx bx-refresh text-xl'></i>
                        </button>
                    </div>
                    <div class="chart-container">
                        <canvas id="collectionTrendChart"></canvas>
                    </div>
                </div>
                
                <!-- Aging Summary -->
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                    <div class="flex justify-between items-center mb-6">
                        <h3 class="text-lg font-bold text-gray-800">Aging Summary</h3>
                        <div class="flex items-center gap-3">
                            <button id="aging-visibility-toggle" class="text-gray-500 hover:text-brand-primary transition" title="Toggle Amounts">
                                <i class="fa-solid fa-eye-slash"></i>
                            </button>
                        </div>
                    </div>
                    <div class="space-y-6">
                        <?php 
                        $aging_items = [
                            ['label' => 'Current (0-30 days)', 'amount' => (float)($aging_summary['current_0_30'] ?? 0), 'color' => 'bg-green-500', 'percentage' => 60],
                            ['label' => '31-60 Days', 'amount' => (float)($aging_summary['overdue_31_60'] ?? 0), 'color' => 'bg-yellow-500', 'percentage' => 25],
                            ['label' => '61-90 Days', 'amount' => (float)($aging_summary['overdue_61_90'] ?? 0), 'color' => 'bg-orange-500', 'percentage' => 10],
                            ['label' => '90+ Days', 'amount' => (float)($aging_summary['overdue_90_plus'] ?? 0), 'color' => 'bg-red-500', 'percentage' => 5]
                        ];
                        
                        foreach($aging_items as $item): 
                        ?>
                        <div>
                            <div class="flex justify-between text-sm mb-1">
                                <span class="font-medium"><?php echo $item['label']; ?></span>
                                <span class="font-semibold aging-amount" data-amount="<?php echo $item['amount']; ?>">
                                    ₱<?php echo number_format($item['amount'], 2); ?>
                                </span>
                            </div>
                            <div class="progress-bar">
                                <div class="progress-fill <?php echo $item['color']; ?>" style="width: <?php echo $item['percentage']; ?>%"></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Recent Activity Section -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                <!-- Recent Payments -->
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                    <div class="p-6 border-b border-gray-100">
                        <div class="flex justify-between items-center">
                            <div class="flex items-center gap-3">
                                <h3 class="text-lg font-bold text-gray-800">Recent Payments</h3>
                                <span class="bg-gray-100 text-gray-600 text-xs font-medium px-2.5 py-0.5 rounded-full">
                                    <?php echo count($recent_payments); ?> payments
                                </span>
                            </div>
                            <a href="payment_entry_collection.php" class="text-brand-primary hover:text-brand-primary-hover text-sm font-medium flex items-center gap-1">
                                View All <i class='bx bx-chevron-right'></i>
                            </a>
                        </div>
                        <p class="text-sm text-gray-500 mt-1">Recently completed collections</p>
                    </div>
                    <div class="overflow-x-auto">
                        <?php if (empty($recent_payments)): ?>
                            <div class="p-8 text-center text-gray-500">
                                <i class='bx bx-check-circle text-3xl mb-2 text-gray-300'></i>
                                <div>No recent payments</div>
                                <div class="text-sm mt-1">All payments will appear here</div>
                            </div>
                        <?php else: ?>
                            <table class="w-full">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="text-left p-4 text-sm font-medium text-gray-500">Customer</th>
                                        <th class="text-left p-4 text-sm font-medium text-gray-500">Amount</th>
                                        <th class="text-left p-4 text-sm font-medium text-gray-500">Date</th>
                                        <th class="text-left p-4 text-sm font-medium text-gray-500">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recent_payments as $payment): ?>
                                    <tr class="transaction-row">
                                        <td class="p-4">
                                            <div class="font-medium"><?php echo htmlspecialchars($payment['contact_name'] ?? 'N/A'); ?></div>
                                            <?php if ($payment['invoice_number']): ?>
                                                <div class="text-xs text-gray-500">Invoice: <?php echo htmlspecialchars($payment['invoice_number']); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="p-4 font-medium text-gray-800 recent-payment-amount hidden-amount">
                                            ₱<?php echo number_format((float)($payment['amount'] ?? 0), 2); ?>
                                        </td>
                                        <td class="p-4 text-gray-600"><?php echo date('M j, Y', strtotime($payment['payment_date'] ?? 'now')); ?></td>
                                        <td class="p-4">
                                            <span class="status-badge status-completed">Completed</span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Overdue Invoices -->
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                    <div class="p-6 border-b border-gray-100">
                        <div class="flex justify-between items-center">
                            <div class="flex items-center gap-3">
                                <h3 class="text-lg font-bold text-gray-800">Overdue Invoices</h3>
                                <span class="bg-gray-100 text-gray-600 text-xs font-medium px-2.5 py-0.5 rounded-full">
                                    <?php echo count($overdue_invoices); ?> invoices
                                </span>
                            </div>
                            <a href="outstanding_balances.php" class="text-brand-primary hover:text-brand-primary-hover text-sm font-medium flex items-center gap-1">
                                View All <i class='bx bx-chevron-right'></i>
                            </a>
                        </div>
                        <p class="text-sm text-gray-500 mt-1">Invoices requiring immediate attention</p>
                    </div>
                    <div class="overflow-x-auto">
                        <?php if (empty($overdue_invoices)): ?>
                            <div class="p-8 text-center text-gray-500">
                                <i class='bx bx-check-circle text-3xl mb-2 text-green-500'></i>
                                <div>No overdue invoices</div>
                                <div class="text-sm mt-1">All invoices are up to date</div>
                            </div>
                        <?php else: ?>
                            <table class="w-full">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="text-left p-4 text-sm font-medium text-gray-500">Customer</th>
                                        <th class="text-left p-4 text-sm font-medium text-gray-500">Amount</th>
                                        <th class="text-left p-4 text-sm font-medium text-gray-500">Due Date</th>
                                        <th class="text-left p-4 text-sm font-medium text-gray-500">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($overdue_invoices as $invoice): ?>
                                    <tr class="transaction-row">
                                        <td class="p-4 font-medium"><?php echo htmlspecialchars($invoice['contact_name'] ?? 'N/A'); ?></td>
                                        <td class="p-4 font-medium text-gray-800 overdue-amount hidden-amount">
                                            ₱<?php echo number_format((float)($invoice['amount'] ?? 0), 2); ?>
                                        </td>
                                        <td class="p-4 text-red-600"><?php echo date('M j, Y', strtotime($invoice['due_date'] ?? 'now')); ?></td>
                                        <td class="p-4">
                                            <span class="status-badge status-overdue">
                                                <?php echo (int)($invoice['days_overdue'] ?? 0); ?> days
                                            </span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Top Customers -->
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                <div class="p-6 border-b border-gray-100">
                    <div class="flex justify-between items-center">
                        <div class="flex items-center gap-3">
                            <h3 class="text-lg font-bold text-gray-800">Top Customers (Last 30 Days)</h3>
                            <span class="bg-gray-100 text-gray-600 text-xs font-medium px-2.5 py-0.5 rounded-full">
                                <?php echo count($top_customers); ?> customers
                            </span>
                        </div>
                        <button id="customers-visibility-toggle" class="text-gray-500 hover:text-brand-primary transition" title="Toggle Amounts">
                            <i class="fa-solid fa-eye-slash"></i>
                        </button>
                    </div>
                    <p class="text-sm text-gray-500 mt-1">Highest paying customers by total amount</p>
                </div>
                <div class="overflow-x-auto">
                    <?php if (empty($top_customers)): ?>
                        <div class="p-8 text-center text-gray-500">
                            <i class='bx bx-user text-3xl mb-2 text-gray-300'></i>
                            <div>No customer data available</div>
                            <div class="text-sm mt-1">Customer data will appear here</div>
                        </div>
                    <?php else: ?>
                        <table class="w-full">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="text-left p-4 text-sm font-medium text-gray-500">Customer</th>
                                    <th class="text-left p-4 text-sm font-medium text-gray-500">Total Paid</th>
                                    <th class="text-left p-4 text-sm font-medium text-gray-500">Payment Count</th>
                                    <th class="text-left p-4 text-sm font-medium text-gray-500">Average Payment</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($top_customers as $customer): 
                                    $total_paid = (float)($customer['total_paid'] ?? 0);
                                    $payment_count = (int)($customer['payment_count'] ?? 0);
                                    $average = $payment_count > 0 ? $total_paid / $payment_count : 0;
                                ?>
                                <tr class="transaction-row">
                                    <td class="p-4 font-medium"><?php echo htmlspecialchars($customer['name'] ?? ''); ?></td>
                                    <td class="p-4 font-medium text-gray-800 customer-total hidden-amount">
                                        ₱<?php echo number_format($total_paid, 2); ?>
                                    </td>
                                    <td class="p-4 text-gray-600"><?php echo $payment_count; ?></td>
                                    <td class="p-4 text-gray-600 customer-average hidden-amount">
                                        ₱<?php echo number_format($average, 2); ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </main>
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

        // Initialize visibility toggles
        initializeVisibilityToggles();
        
        // Initialize common features
        initializeCommonFeatures();
        
        // Initialize charts
        initializeCharts();
    });

    function initializeVisibilityToggles() {
        // Main visibility toggle
        const visibilityToggle = document.getElementById('visibility-toggle');
        if (visibilityToggle) {
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
                
                // Toggle table amounts
                const tableAmounts = document.querySelectorAll('.recent-payment-amount, .overdue-amount, .customer-total, .customer-average, .aging-amount');
                tableAmounts.forEach(amount => {
                    if (newState) {
                        amount.classList.remove('hidden-amount');
                    } else {
                        amount.classList.add('hidden-amount');
                    }
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
                
                // Update secondary toggle icons
                const agingToggle = document.getElementById('aging-visibility-toggle');
                const customersToggle = document.getElementById('customers-visibility-toggle');
                
                if (agingToggle) {
                    const agingIcon = agingToggle.querySelector('i');
                    if (newState) {
                        agingIcon.classList.remove('fa-eye-slash');
                        agingIcon.classList.add('fa-eye');
                        agingToggle.title = "Hide Amounts";
                    } else {
                        agingIcon.classList.remove('fa-eye');
                        agingIcon.classList.add('fa-eye-slash');
                        agingToggle.title = "Show Amounts";
                    }
                }
                
                if (customersToggle) {
                    const customersIcon = customersToggle.querySelector('i');
                    if (newState) {
                        customersIcon.classList.remove('fa-eye-slash');
                        customersIcon.classList.add('fa-eye');
                        customersToggle.title = "Hide Amounts";
                    } else {
                        customersIcon.classList.remove('fa-eye');
                        customersIcon.classList.add('fa-eye-slash');
                        customersToggle.title = "Show Amounts";
                    }
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
            
            // Initialize individual stat states
            const statType = toggle.getAttribute('data-stat');
            const savedState = localStorage.getItem(`stat_${statType}_visible`);
            if (savedState !== null) {
                toggleStat(statType, savedState === 'true');
            }
        });
        
        // Aging visibility toggle
        const agingToggle = document.getElementById('aging-visibility-toggle');
        if (agingToggle) {
            agingToggle.addEventListener('click', function() {
                const amounts = document.querySelectorAll('.aging-amount');
                const currentState = amounts[0]?.classList.contains('hidden-amount') !== false;
                const newState = !currentState;
                
                amounts.forEach(amount => {
                    if (newState) {
                        amount.classList.remove('hidden-amount');
                    } else {
                        amount.classList.add('hidden-amount');
                    }
                });
                
                const icon = this.querySelector('i');
                if (newState) {
                    icon.classList.remove('fa-eye-slash');
                    icon.classList.add('fa-eye');
                    this.title = "Hide Amounts";
                } else {
                    icon.classList.remove('fa-eye');
                    icon.classList.add('fa-eye-slash');
                    this.title = "Show Amounts";
                }
            });
        }
        
        // Customers visibility toggle
        const customersToggle = document.getElementById('customers-visibility-toggle');
        if (customersToggle) {
            customersToggle.addEventListener('click', function() {
                const amounts = document.querySelectorAll('.customer-total, .customer-average');
                const currentState = amounts[0]?.classList.contains('hidden-amount') !== false;
                const newState = !currentState;
                
                amounts.forEach(amount => {
                    if (newState) {
                        amount.classList.remove('hidden-amount');
                    } else {
                        amount.classList.add('hidden-amount');
                    }
                });
                
                const icon = this.querySelector('i');
                if (newState) {
                    icon.classList.remove('fa-eye-slash');
                    icon.classList.add('fa-eye');
                    this.title = "Hide Amounts";
                } else {
                    icon.classList.remove('fa-eye');
                    icon.classList.add('fa-eye-slash');
                    this.title = "Show Amounts";
                }
            });
        }
        
        // Initialize main toggle state
        updateMainToggleState();
        
        // Initialize table amount visibility
        const amountsVisible = localStorage.getItem(`amounts_visible`) === 'true';
        const tableAmounts = document.querySelectorAll('.recent-payment-amount, .overdue-amount, .customer-total, .customer-average, .aging-amount');
        tableAmounts.forEach(amount => {
            if (amountsVisible) {
                amount.classList.remove('hidden-amount');
            } else {
                amount.classList.add('hidden-amount');
            }
        });
        
        // Set initial icons for secondary toggles
        if (agingToggle && customersToggle) {
            const agingIcon = agingToggle.querySelector('i');
            const customersIcon = customersToggle.querySelector('i');
            
            if (amountsVisible) {
                agingIcon.classList.remove('fa-eye-slash');
                agingIcon.classList.add('fa-eye');
                customersIcon.classList.remove('fa-eye-slash');
                customersIcon.classList.add('fa-eye');
            } else {
                agingIcon.classList.remove('fa-eye');
                agingIcon.classList.add('fa-eye-slash');
                customersIcon.classList.remove('fa-eye');
                customersIcon.classList.add('fa-eye-slash');
            }
        }
    }
    
    function toggleStat(statType, show) {
        const statElements = document.querySelectorAll(`.stat-value[data-stat="${statType}"]`);
        statElements.forEach(element => {
            const actualValue = element.getAttribute('data-value');
            if (show) {
                element.textContent = actualValue;
                if (!actualValue.includes('₱')) {
                    element.classList.remove('hidden-amount');
                }
            } else {
                if (statType === 'customers' || statType === 'payments') {
                    element.textContent = '••••';
                } else {
                    element.textContent = '••••••••';
                    element.classList.add('hidden-amount');
                }
            }
        });
        
        // Update toggle icon
        const toggleBtn = document.querySelector(`.stat-toggle[data-stat="${statType}"]`);
        if (toggleBtn) {
            const icon = toggleBtn.querySelector('i');
            if (show) {
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
                toggleBtn.title = "Hide";
            } else {
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
                toggleBtn.title = "Show";
            }
        }
    }
    
    function checkAllStatsVisible() {
        const statTypes = ['total_collected', 'customers', 'payments', 'average_payment'];
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
        
        // Refresh chart button
        const refreshChartBtn = document.getElementById('refresh-chart');
        if (refreshChartBtn) {
            refreshChartBtn.addEventListener('click', function() {
                this.innerHTML = '<div class="spinner"></div>';
                setTimeout(() => {
                    this.innerHTML = '<i class="bx bx-refresh text-xl"></i>';
                    // Here you could reload the chart data via AJAX
                }, 1000);
            });
        }
    }

    function initializeCharts() {
        // Collection Trend Chart
        const trendCtx = document.getElementById('collectionTrendChart');
        if (trendCtx) {
            new Chart(trendCtx, {
                type: 'line',
                data: {
                    labels: ['Week 1', 'Week 2', 'Week 3', 'Week 4'],
                    datasets: [{
                        label: 'Collections (₱)',
                        data: [25000, 32000, 28000, 41000],
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
                        },
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
                    },
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
                                }
                            }
                        },
                        x: { 
                            grid: { display: false }
                        }
                    }
                }
            });
        }
    }
    </script>
</body>
</html>