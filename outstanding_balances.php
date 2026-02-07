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
$outstanding_balances = [];
$aging_summary = [];
$total_outstanding = 0;

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        if ($_POST['action'] === 'send_reminder') {
            // Send reminder logic
            $contact_id = $_POST['contact_id'];
            $getContact = $db->prepare("SELECT name, email FROM business_contacts WHERE contact_id = ?");
            $getContact->execute([$contact_id]);
            $contact = $getContact->fetch();
            
            $_SESSION['success_message'] = "Payment reminder sent to " . ($contact['name'] ?? 'customer') . "!";
            
        } elseif ($_POST['action'] === 'apply_payment') {
            // Apply payment to invoice
            $invoice_id = $_POST['invoice_id'];
            $amount = $_POST['amount'];
            
            // Update invoice status
            $updateInvoice = $db->prepare("UPDATE invoices SET status = 'Paid' WHERE id = ?");
            $updateInvoice->execute([$invoice_id]);
            
            $_SESSION['success_message'] = "Payment applied successfully!";
        }
    } catch (Exception $e) {
        $_SESSION['error_message'] = "Error: " . $e->getMessage();
    }
    header("Location: outstanding_balances.php");
    exit;
}

// Handle Excel Export
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    try {
        // Fetch data for export
        $export_stmt = $db->query("
            SELECT 
                bc.contact_id,
                bc.name as contact_name,
                bc.email,
                bc.phone,
                SUM(i.amount) as total_balance,
                COUNT(i.id) as invoice_count,
                SUM(CASE WHEN DATEDIFF(CURDATE(), i.due_date) <= 30 THEN i.amount ELSE 0 END) as current_0_30,
                SUM(CASE WHEN DATEDIFF(CURDATE(), i.due_date) BETWEEN 31 AND 60 THEN i.amount ELSE 0 END) as overdue_31_60,
                SUM(CASE WHEN DATEDIFF(CURDATE(), i.due_date) BETWEEN 61 AND 90 THEN i.amount ELSE 0 END) as overdue_61_90,
                SUM(CASE WHEN DATEDIFF(CURDATE(), i.due_date) > 90 THEN i.amount ELSE 0 END) as overdue_90_plus
            FROM business_contacts bc 
            JOIN invoices i ON bc.contact_id = i.contact_id 
            WHERE i.status IN ('Pending', 'Overdue')
            AND i.type = 'Receivable'
            GROUP BY bc.contact_id, bc.name, bc.email, bc.phone
            HAVING total_balance > 0
            ORDER BY total_balance DESC
        ");
        $export_data = $export_stmt->fetchAll();
        
        // Set headers for Excel download
        header('Content-Type: application/vnd.ms-excel');
        header('Content-Disposition: attachment; filename="outstanding_balances_' . date('Y-m-d') . '.xls"');
        
        // Excel header
        echo "Outstanding Balances Report\t\t\t\t\t\n";
        echo "Generated on: " . date('Y-m-d H:i:s') . "\t\t\t\t\t\n\n";
        
        // Column headers
        echo "Customer Name\tEmail\tPhone\tTotal Balance\tInvoice Count\tCurrent (0-30)\t31-60 Days\t61-90 Days\t90+ Days\n";
        
        // Data rows
        foreach ($export_data as $row) {
            echo $row['contact_name'] . "\t";
            echo $row['email'] . "\t";
            echo $row['phone'] . "\t";
            echo number_format((float)$row['total_balance'], 2) . "\t";
            echo $row['invoice_count'] . "\t";
            echo number_format((float)$row['current_0_30'], 2) . "\t";
            echo number_format((float)$row['overdue_31_60'], 2) . "\t";
            echo number_format((float)$row['overdue_61_90'], 2) . "\t";
            echo number_format((float)$row['overdue_90_plus'], 2) . "\n";
        }
        
        exit;
        
    } catch (Exception $e) {
        error_log("Export error: " . $e->getMessage());
        $_SESSION['error_message'] = "Error generating export file.";
        header("Location: outstanding_balances.php");
        exit;
    }
}

// Fetch outstanding balances data
try {
    // Outstanding balances with aging - UPDATED to use business_contacts and invoices tables
    $balances_stmt = $db->query("
        SELECT 
            bc.contact_id,
            bc.name as contact_name,
            bc.email,
            bc.phone,
            SUM(i.amount) as total_balance,
            COUNT(i.id) as invoice_count,
            SUM(CASE WHEN DATEDIFF(CURDATE(), i.due_date) <= 30 THEN i.amount ELSE 0 END) as current_0_30,
            SUM(CASE WHEN DATEDIFF(CURDATE(), i.due_date) BETWEEN 31 AND 60 THEN i.amount ELSE 0 END) as overdue_31_60,
            SUM(CASE WHEN DATEDIFF(CURDATE(), i.due_date) BETWEEN 61 AND 90 THEN i.amount ELSE 0 END) as overdue_61_90,
            SUM(CASE WHEN DATEDIFF(CURDATE(), i.due_date) > 90 THEN i.amount ELSE 0 END) as overdue_90_plus,
            MAX(i.due_date) as latest_due_date,
            MIN(i.due_date) as oldest_due_date
        FROM business_contacts bc 
        JOIN invoices i ON bc.contact_id = i.contact_id 
        WHERE i.status IN ('Pending', 'Overdue')
        AND i.type = 'Receivable'  -- Only show customer invoices (Accounts Receivable)
        GROUP BY bc.contact_id, bc.name, bc.email, bc.phone
        HAVING total_balance > 0
        ORDER BY total_balance DESC
    ");
    $outstanding_balances = $balances_stmt->fetchAll();
    
    // Calculate total outstanding
    $total_outstanding = 0;
    foreach ($outstanding_balances as $customer) {
        $total_outstanding += (float)($customer['total_balance'] ?? 0);
    }
    
    // Aging summary - UPDATED to use invoices table
    $aging_stmt = $db->query("
        SELECT 
            SUM(CASE WHEN DATEDIFF(CURDATE(), due_date) <= 30 THEN amount ELSE 0 END) as current_0_30,
            SUM(CASE WHEN DATEDIFF(CURDATE(), due_date) BETWEEN 31 AND 60 THEN amount ELSE 0 END) as overdue_31_60,
            SUM(CASE WHEN DATEDIFF(CURDATE(), due_date) BETWEEN 61 AND 90 THEN amount ELSE 0 END) as overdue_61_90,
            SUM(CASE WHEN DATEDIFF(CURDATE(), due_date) > 90 THEN amount ELSE 0 END) as overdue_90_plus,
            COUNT(*) as total_invoices
        FROM invoices 
        WHERE status IN ('Pending', 'Overdue')
        AND type = 'Receivable'  -- Only customer invoices
    ");
    $aging_summary = $aging_stmt->fetch();
    
    // Detailed invoices for each customer - UPDATED to use invoices table
    foreach ($outstanding_balances as &$customer) {
        $invoices_stmt = $db->prepare("
            SELECT i.*, 
                   DATEDIFF(CURDATE(), i.due_date) as days_overdue,
                   (i.amount - COALESCE(SUM(p.amount), 0)) as outstanding_balance
            FROM invoices i 
            LEFT JOIN payments p ON i.id = p.invoice_id AND p.status = 'Completed'
            WHERE i.contact_id = ? 
            AND i.status IN ('Pending', 'Overdue')
            AND i.type = 'Receivable'
            GROUP BY i.id
            ORDER BY i.due_date ASC
        ");
        $invoices_stmt->execute([$customer['contact_id']]);
        $customer['invoices'] = $invoices_stmt->fetchAll();
    }
    
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

// Get messages
$success_message = $_SESSION['success_message'] ?? '';
$error_message = $_SESSION['error_message'] ?? '';
unset($_SESSION['success_message'], $_SESSION['error_message']);

// Function to mask numbers with asterisks
function maskNumber($number, $masked = true) {
    if (!$masked) {
        return number_format((float)$number, 0); // Remove decimal places
    }
    
    $numberStr = (string)$number;
    $parts = explode('.', $numberStr);
    $integerPart = $parts[0];
    
    // Mask the integer part
    return str_repeat('*', strlen($integerPart));
}

// Add session variable to track number visibility (consistent with invoices.php)
if (!isset($_SESSION['show_numbers'])) {
    $_SESSION['show_numbers'] = false;
}

// Toggle number visibility
if (isset($_GET['toggle_numbers'])) {
    $_SESSION['show_numbers'] = !$_SESSION['show_numbers'];
    header("Location: " . str_replace("?toggle_numbers=1", "", $_SERVER['REQUEST_URI']));
    exit;
}

// Function to format numbers with asterisks if hidden (consistent with invoices.php)
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
    <title>Outstanding Balances | Financial Dashboard</title>
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
                                <a href="outstanding_balances.php" class="block px-3 py-1.5 rounded-lg text-xs text-brand-primary hover:bg-green-50 hover:text-brand-primary transition-all duration-200 hover:translate-x-1 font-semibold">Outstanding Balances</a>
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
                Outstanding Balances
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
            <!-- Messages -->
            <?php if ($success_message): ?>
                <div class="mb-6 bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-lg">
                    <?= htmlspecialchars($success_message) ?>
                </div>
            <?php endif; ?>
            
            <?php if ($error_message): ?>
                <div class="mb-6 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg">
                    <?= htmlspecialchars($error_message) ?>
                </div>
            <?php endif; ?>
            
            <!-- Summary Cards -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-6">
                <?php 
                $stats = [
                    ['icon' => 'bx-error-circle', 'label' => 'Total Outstanding', 'value' => $total_outstanding, 'color' => 'red', 'stat' => 'outstanding'],
                    ['icon' => 'bx-time-five', 'label' => 'Overdue Invoices', 'value' => $aging_summary['total_invoices'] ?? 0, 'color' => 'yellow', 'stat' => 'overdue'],
                    ['icon' => 'bx-group', 'label' => 'Customers with Balances', 'value' => count($outstanding_balances), 'color' => 'blue', 'stat' => 'customers'],
                    ['icon' => 'bx-calendar-exclamation', 'label' => 'Average Days Overdue', 'value' => 0, 'color' => 'orange', 'stat' => 'days']
                ];
                
                // Calculate average days overdue
                $total_days = 0;
                $count = 0;
                foreach ($outstanding_balances as $customer) {
                    foreach ($customer['invoices'] as $invoice) {
                        if ($invoice['days_overdue'] > 0) {
                            $total_days += $invoice['days_overdue'];
                            $count++;
                        }
                    }
                }
                $stats[3]['value'] = $count > 0 ? round($total_days / $count) : 0;
                
                foreach($stats as $stat): 
                    $bgColors = [
                        'red' => 'bg-red-100',
                        'yellow' => 'bg-yellow-100',
                        'blue' => 'bg-blue-100',
                        'orange' => 'bg-orange-100',
                        'green' => 'bg-green-100'
                    ];
                    $textColors = [
                        'red' => 'text-red-600',
                        'yellow' => 'text-yellow-600',
                        'blue' => 'text-blue-600',
                        'orange' => 'text-orange-600',
                        'green' => 'text-green-600'
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
                                           $stat_value = (float)($stat['value'] ?? 0);
                                           if ($stat['stat'] === 'outstanding') {
                                               echo '₱' . number_format($stat_value, 2);
                                           } else {
                                               echo $stat_value;
                                           }
                                       ?>"
                                       data-stat="<?php echo $stat['stat']; ?>">
                                        <?php 
                                            if ($stat['stat'] === 'outstanding') {
                                                $stat_value = (float)($stat['value'] ?? 0);
                                                echo $_SESSION['show_numbers'] ? '₱' . number_format($stat_value, 2) : '••••••••';
                                            } else {
                                                echo $stat['value'];
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

            <!-- Aging Summary -->
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 mb-6">
                <div class="flex justify-between items-center mb-6">
                    <h3 class="text-lg font-bold text-gray-800">Aging Summary</h3>
                    <a href="?export=excel" class="px-4 py-2 bg-brand-primary text-white rounded-lg hover:bg-brand-primary-hover transition flex items-center gap-2 text-sm">
                        <i class='bx bx-export'></i> Export to Excel
                    </a>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                    <?php
                    $agingData = [
                        ['label' => 'Current (0-30 days)', 'value' => (float)($aging_summary['current_0_30'] ?? 0), 'color' => 'text-green-600', 'bg' => 'bg-green-50'],
                        ['label' => '31-60 Days', 'value' => (float)($aging_summary['overdue_31_60'] ?? 0), 'color' => 'text-yellow-600', 'bg' => 'bg-yellow-50'],
                        ['label' => '61-90 Days', 'value' => (float)($aging_summary['overdue_61_90'] ?? 0), 'color' => 'text-orange-600', 'bg' => 'bg-orange-50'],
                        ['label' => '90+ Days', 'value' => (float)($aging_summary['overdue_90_plus'] ?? 0), 'color' => 'text-red-600', 'bg' => 'bg-red-50']
                    ];
                    ?>
                    <?php foreach($agingData as $item): ?>
                    <div class="text-center p-6 border rounded-xl hover:shadow-md transition-shadow duration-200 <?php echo $item['bg']; ?>">
                        <p class="text-sm text-gray-600 mb-2"><?php echo $item['label']; ?></p>
                        <p class="text-2xl font-bold <?php echo $item['color']; ?> <?php echo !$_SESSION['show_numbers'] ? 'hidden-amount' : ''; ?>">
                            <?php 
                                $item_value = (float)($item['value'] ?? 0);
                                echo $_SESSION['show_numbers'] ? '₱' . number_format($item_value, 2) : '••••••••';
                            ?>
                        </p>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Outstanding Balances Table -->
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                <div class="p-6 border-b border-gray-100">
                    <div class="flex justify-between items-center">
                        <div>
                            <h3 class="text-lg font-bold text-gray-800">Customer Outstanding Balances</h3>
                            <p class="text-sm text-gray-500">Total: <?php echo count($outstanding_balances); ?> customers with outstanding balances</p>
                        </div>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="text-left p-4 text-sm font-medium text-gray-500">Customer</th>
                                <th class="text-left p-4 text-sm font-medium text-gray-500">Contact Info</th>
                                <th class="text-left p-4 text-sm font-medium text-gray-500">Total Balance</th>
                                <th class="text-left p-4 text-sm font-medium text-gray-500">Invoice Count</th>
                                <th class="text-left p-4 text-sm font-medium text-gray-500">Aging Breakdown</th>
                                <th class="text-left p-4 text-sm font-medium text-gray-500">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($outstanding_balances)): ?>
                                <tr>
                                    <td colspan="6" class="p-8 text-center text-gray-500">
                                        <i class='bx bx-folder-open text-3xl mb-2 text-gray-300'></i>
                                        <div>No outstanding balances found</div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($outstanding_balances as $customer): ?>
                                    <tr class="transaction-row collapse-toggle cursor-pointer" data-customer="<?= $customer['contact_id'] ?>">
                                        <td class="p-4">
                                            <div class="font-medium text-gray-800"><?= htmlspecialchars($customer['contact_name']) ?></div>
                                        </td>
                                        <td class="p-4">
                                            <div class="text-sm">
                                                <div class="text-gray-800"><?= htmlspecialchars($customer['email'] ?? 'N/A') ?></div>
                                                <div class="text-gray-500"><?= htmlspecialchars($customer['phone'] ?? 'N/A') ?></div>
                                            </div>
                                        </td>
                                        <td class="p-4 font-bold text-gray-800 <?php echo !$_SESSION['show_numbers'] ? 'hidden-amount' : ''; ?>">
                                            <?php 
                                                $total_balance = (float)($customer['total_balance'] ?? 0);
                                                echo $_SESSION['show_numbers'] ? '₱' . number_format($total_balance, 2) : '••••••••';
                                            ?>
                                        </td>
                                        <td class="p-4">
                                            <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-blue-50 text-blue-700">
                                                <?= $customer['invoice_count'] ?> invoices
                                            </span>
                                        </td>
                                        <td class="p-4">
                                            <div class="space-y-1">
                                                <div class="flex items-center text-xs">
                                                    <div class="w-2 h-2 rounded-full bg-green-500 mr-2"></div>
                                                    <span class="text-gray-600">0-30:</span>
                                                    <span class="ml-2 <?php echo !$_SESSION['show_numbers'] ? 'hidden-amount' : ''; ?>">
                                                        <?php 
                                                            $current_0_30 = (float)($customer['current_0_30'] ?? 0);
                                                            echo $_SESSION['show_numbers'] ? '₱' . number_format($current_0_30, 2) : '••••••';
                                                        ?>
                                                    </span>
                                                </div>
                                                <div class="flex items-center text-xs">
                                                    <div class="w-2 h-2 rounded-full bg-yellow-500 mr-2"></div>
                                                    <span class="text-gray-600">31-60:</span>
                                                    <span class="ml-2 <?php echo !$_SESSION['show_numbers'] ? 'hidden-amount' : ''; ?>">
                                                        <?php 
                                                            $overdue_31_60 = (float)($customer['overdue_31_60'] ?? 0);
                                                            echo $_SESSION['show_numbers'] ? '₱' . number_format($overdue_31_60, 2) : '••••••';
                                                        ?>
                                                    </span>
                                                </div>
                                                <div class="flex items-center text-xs">
                                                    <div class="w-2 h-2 rounded-full bg-orange-500 mr-2"></div>
                                                    <span class="text-gray-600">61-90:</span>
                                                    <span class="ml-2 <?php echo !$_SESSION['show_numbers'] ? 'hidden-amount' : ''; ?>">
                                                        <?php 
                                                            $overdue_61_90 = (float)($customer['overdue_61_90'] ?? 0);
                                                            echo $_SESSION['show_numbers'] ? '₱' . number_format($overdue_61_90, 2) : '••••••';
                                                        ?>
                                                    </span>
                                                </div>
                                                <div class="flex items-center text-xs">
                                                    <div class="w-2 h-2 rounded-full bg-red-500 mr-2"></div>
                                                    <span class="text-gray-600">90+:</span>
                                                    <span class="ml-2 <?php echo !$_SESSION['show_numbers'] ? 'hidden-amount' : ''; ?>">
                                                        <?php 
                                                            $overdue_90_plus = (float)($customer['overdue_90_plus'] ?? 0);
                                                            echo $_SESSION['show_numbers'] ? '₱' . number_format($overdue_90_plus, 2) : '••••••';
                                                        ?>
                                                    </span>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="p-4">
                                            <div class="flex space-x-2">
                                                <button class="px-3 py-1.5 text-sm bg-yellow-500 text-white rounded-lg hover:bg-yellow-600 transition send-reminder" 
                                                        data-contact-id="<?= $customer['contact_id'] ?>" 
                                                        data-contact-name="<?= htmlspecialchars($customer['contact_name']) ?>">
                                                    <i class='bx bx-bell mr-1'></i> Remind
                                                </button>
                                                <a href="invoices.php?contact_id=<?= $customer['contact_id'] ?>&type=Receivable&from_contact=1" 
                                                   class="px-3 py-1.5 text-sm bg-brand-primary text-white rounded-lg hover:bg-brand-primary-hover transition inline-flex items-center">
                                                    <i class='bx bx-show mr-1'></i> View
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                    <tr class="collapse-content" id="customer-<?= $customer['contact_id'] ?>">
                                        <td colspan="6" class="p-0">
                                            <div class="p-4 bg-gray-50 border-t border-gray-100">
                                                <h4 class="font-bold text-gray-800 mb-4">Outstanding Invoices for <?= htmlspecialchars($customer['contact_name']) ?></h4>
                                                <?php if (empty($customer['invoices'])): ?>
                                                    <p class="text-gray-500 text-center py-4">No outstanding invoices</p>
                                                <?php else: ?>
                                                    <div class="overflow-x-auto">
                                                        <table class="w-full">
                                                            <thead class="bg-gray-100">
                                                                <tr>
                                                                    <th class="text-left p-3 text-sm font-medium text-gray-500">Invoice #</th>
                                                                    <th class="text-left p-3 text-sm font-medium text-gray-500">Issue Date</th>
                                                                    <th class="text-left p-3 text-sm font-medium text-gray-500">Due Date</th>
                                                                    <th class="text-left p-3 text-sm font-medium text-gray-500">Amount</th>
                                                                    <th class="text-left p-3 text-sm font-medium text-gray-500">Outstanding</th>
                                                                    <th class="text-left p-3 text-sm font-medium text-gray-500">Days Overdue</th>
                                                                    <th class="text-left p-3 text-sm font-medium text-gray-500">Status</th>
                                                                    <th class="text-left p-3 text-sm font-medium text-gray-500">Actions</th>
                                                                </tr>
                                                            </thead>
                                                            <tbody>
                                                                <?php foreach ($customer['invoices'] as $invoice): ?>
                                                                    <tr class="border-b border-gray-100 hover:bg-gray-50">
                                                                        <td class="p-3"><?= htmlspecialchars($invoice['invoice_number']) ?></td>
                                                                        <td class="p-3 text-gray-600"><?= date('M d, Y', strtotime($invoice['issue_date'])) ?></td>
                                                                        <td class="p-3 text-gray-600"><?= date('M d, Y', strtotime($invoice['due_date'])) ?></td>
                                                                        <td class="p-3 font-medium <?php echo !$_SESSION['show_numbers'] ? 'hidden-amount' : ''; ?>">
                                                                            <?php 
                                                                                $invoice_amount = (float)($invoice['amount'] ?? 0);
                                                                                echo $_SESSION['show_numbers'] ? '₱' . number_format($invoice_amount, 2) : '••••••••';
                                                                            ?>
                                                                        </td>
                                                                        <td class="p-3 font-bold <?php echo !$_SESSION['show_numbers'] ? 'hidden-amount' : ''; ?>">
                                                                            <?php 
                                                                                $outstanding_balance = (float)($invoice['outstanding_balance'] ?? 0);
                                                                                echo $_SESSION['show_numbers'] ? '₱' . number_format($outstanding_balance, 2) : '••••••••';
                                                                            ?>
                                                                        </td>
                                                                        <td class="p-3">
                                                                            <?php if ($invoice['days_overdue'] > 0): ?>
                                                                                <span class="text-red-600 font-medium"><?= $invoice['days_overdue'] ?> days</span>
                                                                            <?php else: ?>
                                                                                <span class="text-green-600">On time</span>
                                                                            <?php endif; ?>
                                                                        </td>
                                                                        <td class="p-3">
                                                                            <span class="status-badge <?= $invoice['status'] === 'Overdue' ? 'status-rejected' : 'status-pending' ?>">
                                                                                <?= htmlspecialchars($invoice['status']) ?>
                                                                            </span>
                                        </td>
                                        <td class="p-3">
                                            <a href="invoices.php?contact_id=<?= $customer['contact_id'] ?>&type=Receivable&from_contact=1" 
                                               class="px-3 py-1 text-sm bg-brand-primary text-white rounded-lg hover:bg-brand-primary-hover transition inline-flex items-center">
                                                <i class='bx bx-show mr-1'></i> View
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </td>
    </tr>
<?php endforeach; ?>
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
    
    // Send reminder functionality
    document.querySelectorAll('.send-reminder').forEach(btn => {
        btn.addEventListener('click', function() {
            const contactId = this.getAttribute('data-contact-id');
            const contactName = this.getAttribute('data-contact-name');
            
            if (confirm(`Send payment reminder to ${contactName}?`)) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="send_reminder">
                    <input type="hidden" name="contact_id" value="${contactId}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        });
    });
    
    // Customer details toggle
    document.querySelectorAll('.collapse-toggle').forEach(toggle => {
        toggle.addEventListener('click', function() {
            const customerId = this.getAttribute('data-customer');
            const content = document.getElementById(`customer-${customerId}`);
            content.classList.toggle('active');
        });
    });
});
</script>
</body>
</html>