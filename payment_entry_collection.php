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

// Initialize empty arrays for data
$payments = [];
$customers = [];

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        if ($_POST['action'] === 'create_payment') {
            // Validate required fields
            $required = ['contact_id', 'payment_date', 'amount', 'payment_method', 'reference_number'];
            foreach ($required as $field) {
                if (empty($_POST[$field])) {
                    throw new Exception("Missing required field: $field");
                }
            }
            
            // Validate amount
            $amount = floatval($_POST['amount']);
            if ($amount <= 0) {
                throw new Exception("Amount must be greater than 0");
            }
            
            // Validate OR Number
            if (empty(trim($_POST['reference_number']))) {
                throw new Exception("OR Number is required");
            }
            
            // Insert payment into database
            $stmt = $db->prepare("
                INSERT INTO payments (contact_id, payment_date, amount, payment_method, reference_number, status, type) 
                VALUES (?, ?, ?, ?, ?, 'Completed', 'Receive')
            ");
            
            $stmt->execute([
                (int)$_POST['contact_id'],
                $_POST['payment_date'],
                $amount,
                $_POST['payment_method'],
                trim($_POST['reference_number'])
            ]);
            
            $payment_id = $db->lastInsertId();
            
            // Create notification for payment
            $getContact = $db->prepare("SELECT name FROM business_contacts WHERE id = ?");
            $getContact->execute([(int)$_POST['contact_id']]);
            $contact = $getContact->fetch();
            
            $notification = $db->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)");
            $notification->execute([
                $user_id,
                "Payment received from " . ($contact['name'] ?? 'customer') . " - ₱" . number_format($amount, 2)
            ]);
            
            $_SESSION['success_message'] = "Payment recorded successfully! Payment ID: " . $payment_id;
            
        } elseif ($_POST['action'] === 'edit_payment') {
            // Edit payment logic
            if (!isset($_POST['payment_id'])) {
                throw new Exception("Payment ID is required");
            }
            
            $payment_id = (int)$_POST['payment_id'];
            $amount = floatval($_POST['amount']);
            $payment_date = $_POST['payment_date'];
            $payment_method = $_POST['payment_method'];
            $reference_number = $_POST['reference_number'] ?? null;
            
            if ($amount <= 0) {
                throw new Exception("Amount must be greater than 0");
            }
            
            // Validate OR Number for edit
            if (empty(trim($reference_number))) {
                throw new Exception("OR Number is required");
            }
            
            // Check if payment exists
            $checkPayment = $db->prepare("SELECT id FROM payments WHERE id = ?");
            $checkPayment->execute([$payment_id]);
            if (!$checkPayment->fetch()) {
                throw new Exception("Payment not found");
            }
            
            $stmt = $db->prepare("
                UPDATE payments 
                SET amount = ?, payment_date = ?, payment_method = ?, reference_number = ?
                WHERE id = ?
            ");
            
            $stmt->execute([
                $amount,
                $payment_date,
                $payment_method,
                trim($reference_number),
                $payment_id
            ]);
            
            $_SESSION['success_message'] = "Payment updated successfully!";
        }
    } catch (Exception $e) {
        $_SESSION['error_message'] = "Error: " . $e->getMessage();
    }
    header("Location: payment_entry_collection.php");
    exit;
}

// Handle GET actions (like delete)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action'])) {
    try {
        if ($_GET['action'] === 'delete_payment' && isset($_GET['id'])) {
            $payment_id = (int)$_GET['id'];
            
            // Check if payment exists
            $getPayment = $db->prepare("SELECT id FROM payments WHERE id = ?");
            $getPayment->execute([$payment_id]);
            $payment = $getPayment->fetch();
            
            if ($payment) {
                // Delete the payment
                $deletePayment = $db->prepare("DELETE FROM payments WHERE id = ?");
                $deletePayment->execute([$payment_id]);
                
                $_SESSION['success_message'] = "Payment deleted successfully!";
            } else {
                $_SESSION['error_message'] = "Payment not found";
            }
        } elseif ($_GET['action'] === 'mark_notification_read' && isset($_GET['notification_id'])) {
            $notification_id = (int)$_GET['notification_id'];
            $updateNotification = $db->prepare("UPDATE notifications SET is_read = 1 WHERE id = ?");
            $updateNotification->execute([$notification_id]);
            header("Location: payment_entry_collection.php");
            exit;
        }
    } catch (Exception $e) {
        $_SESSION['error_message'] = "Error: " . $e->getMessage();
    }
    header("Location: payment_entry_collection.php");
    exit;
}

// Get messages
$success_message = $_SESSION['success_message'] ?? '';
$error_message = $_SESSION['error_message'] ?? '';
unset($_SESSION['success_message'], $_SESSION['error_message']);

// Fetch data from database
try {
    // Fetch payments
    $payments_stmt = $db->query("
        SELECT p.*, bc.name as contact_name
        FROM payments p 
        LEFT JOIN business_contacts bc ON p.contact_id = bc.id 
        WHERE p.type = 'Receive' 
        ORDER BY p.payment_date DESC, p.created_at DESC
        LIMIT 100
    ");
    $payments = $payments_stmt->fetchAll();
    
    // Fetch customers
    $customers_stmt = $db->query("SELECT id, name FROM business_contacts WHERE type = 'Customer' AND status = 'Active' ORDER BY name");
    $customers = $customers_stmt->fetchAll();
    
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

// Calculate totals for stats
$total_collected = array_sum(array_map(function($payment) {
    return (float)($payment['amount'] ?? 0);
}, $payments));
$total_customers = count($customers);
$total_payments = count($payments);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no" />
    <title>Payment Entry - Collection | Financial Dashboard</title>

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
        
        .status-completed {
            background-color: #D1FAE5;
            color: #059669;
        }
        
        .status-processing {
            background-color: #DBEAFE;
            color: #2563EB;
        }
        
        .status-failed {
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
            
            .modal-content {
                width: 95%;
                margin: 10% auto;
                padding: 1rem;
            }
        }
        
        .action-btn {
            padding: 0.5rem 0.75rem;
            border-radius: 0.375rem;
            font-size: 0.75rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease-in-out;
            border: 1px solid;
            margin-right: 0.5rem;
            margin-bottom: 0.25rem;
            text-decoration: none;
            display: inline-block;
        }

        .action-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .action-btn.edit {
            background-color: #F0FDF4;
            color: #047857;
            border-color: #047857;
        }

        .action-btn.edit:hover {
            background-color: #047857;
            color: white;
        }

        .action-btn.delete {
            background-color: #FEF2F2;
            color: #DC2626;
            border-color: #DC2626;
        }

        .action-btn.delete:hover {
            background-color: #DC2626;
            color: white;
        }
        
        .action-btn.receipt {
            background-color: #FEF3C7;
            color: #D97706;
            border-color: #D97706;
        }

        .action-btn.receipt:hover {
            background-color: #D97706;
            color: white;
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
                    class="flex items-center justify-between px-4 py-3 rounded-xl text-gray-700 hover:bg-green-50 hover:text-brand-primary
                           transition-all duration-200 active:scale-[0.99]">
                    <span class="flex items-center gap-3 font-semibold">
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
                        <div id="collection-submenu" class="submenu mt-1 active">
                            <div class="pl-4 pr-2 py-1.5 space-y-1 border-l-2 border-gray-100 ml-5">
                                <a href="payment_entry_collection.php" class="block px-3 py-1.5 rounded-lg text-xs text-gray-600 hover:bg-green-50 hover:text-brand-primary transition-all duration-200 hover:translate-x-1 bg-green-50 text-brand-primary">Payment Entry</a>
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
                        Payment Entry - Collection
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
                <button id="visibility-toggle" class="w-10 h-10 rounded-xl hover:bg-gray-100 active:bg-gray-200 transition flex items-center justify-center relative flex-shrink-0"
                        title="Toggle Amount Visibility">
                    <i class="fa-solid fa-eye-slash text-gray-600 text-sm"></i>
                </button>

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
                        <a href="#" id="dropdown-logout-btn" class="block px-4 py-3 text-sm text-red-600 hover:bg-red-50 transition">
                            <i class='bx bx-log-out mr-2'></i> Logout
                        </a>
                    </div>
                </div>
            </div>
        </header>

        <main id="main-content" class="p-4 sm:p-6">
            <!-- Modal for Receive Payment -->
            <div id="receive-payment-modal" class="modal">
                <div class="modal-content">
                    <div class="flex justify-between items-center mb-6">
                        <h2 class="text-xl font-bold text-gray-800">Receive Payment</h2>
                        <button class="close-modal text-gray-400 hover:text-gray-600 text-2xl">&times;</button>
                    </div>
                    <form id="receive-payment-form" method="POST">
                        <input type="hidden" name="action" value="create_payment">
                        
                        <div class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Customer *</label>
                                <select name="contact_id" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-brand-primary focus:border-transparent" required>
                                    <option value="">Select Customer</option>
                                    <?php foreach ($customers as $customer): ?>
                                        <option value="<?= (int)$customer['id'] ?>"><?= htmlspecialchars($customer['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Payment Date *</label>
                                <input type="date" name="payment_date" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-brand-primary focus:border-transparent" value="<?= date('Y-m-d') ?>" required>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Amount *</label>
                                <input type="number" name="amount" id="amount-input" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-brand-primary focus:border-transparent" step="0.01" min="0.01" required>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Payment Method *</label>
                                <select name="payment_method" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-brand-primary focus:border-transparent" required>
                                    <option value="Cash">Cash</option>
                                    <option value="Check">Check</option>
                                    <option value="Bank Transfer">Bank Transfer</option>
                                    <option value="Credit Card">Credit Card</option>
                                </select>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">OR Number *</label>
                                <input type="text" name="reference_number" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-brand-primary focus:border-transparent" placeholder="Enter Official Receipt Number" required>
                            </div>
                        </div>
                        
                        <div class="flex space-x-4 mt-6">
                            <button type="button" class="flex-1 px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition close-modal">Cancel</button>
                            <button type="submit" class="flex-1 px-4 py-2 bg-brand-primary text-white rounded-lg hover:bg-brand-primary-hover transition">Record Payment</button>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Modal for Edit Payment -->
            <div id="edit-payment-modal" class="modal">
                <div class="modal-content">
                    <div class="flex justify-between items-center mb-6">
                        <h2 class="text-xl font-bold text-gray-800">Edit Payment</h2>
                        <button class="close-modal text-gray-400 hover:text-gray-600 text-2xl">&times;</button>
                    </div>
                    <form id="edit-payment-form" method="POST">
                        <input type="hidden" name="action" value="edit_payment">
                        <input type="hidden" name="payment_id" id="edit-payment-id">
                        
                        <div class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Customer</label>
                                <input type="text" id="edit-customer-name" class="w-full px-3 py-2 border border-gray-300 rounded-lg bg-gray-50" readonly>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Payment Date *</label>
                                <input type="date" name="payment_date" id="edit-payment-date" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-brand-primary focus:border-transparent" required>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Amount *</label>
                                <input type="number" name="amount" id="edit-amount" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-brand-primary focus:border-transparent" step="0.01" min="0.01" required>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Payment Method *</label>
                                <select name="payment_method" id="edit-payment-method" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-brand-primary focus:border-transparent" required>
                                    <option value="Cash">Cash</option>
                                    <option value="Check">Check</option>
                                    <option value="Bank Transfer">Bank Transfer</option>
                                    <option value="Credit Card">Credit Card</option>
                                </select>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">OR Number *</label>
                                <input type="text" name="reference_number" id="edit-reference-number" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-brand-primary focus:border-transparent" placeholder="Enter Official Receipt Number" required>
                            </div>
                        </div>
                        
                        <div class="flex space-x-4 mt-6">
                            <button type="button" class="flex-1 px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition close-modal">Cancel</button>
                            <button type="submit" class="flex-1 px-4 py-2 bg-brand-primary text-white rounded-lg hover:bg-brand-primary-hover transition">Update Payment</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Notification Modal -->
            <div id="notification-modal" class="modal">
                <div class="modal-content">
                    <div class="flex justify-between items-center mb-6">
                        <h2 class="text-xl font-bold text-gray-800">Notifications</h2>
                        <button class="close-modal text-gray-400 hover:text-gray-600 text-2xl">&times;</button>
                    </div>
                    <div id="notification-list" class="space-y-4">
                        <?php if (empty($notifications)): ?>
                            <div class="text-center text-gray-500 py-4">No new notifications</div>
                        <?php else: ?>
                            <?php foreach ($notifications as $notification): ?>
                                <div class="p-4 rounded-lg bg-gray-50 hover:bg-gray-100 transition">
                                    <div class="font-medium text-gray-800"><?= htmlspecialchars($notification['message'] ?? 'Notification') ?></div>
                                    <div class="flex justify-between items-center mt-2">
                                        <div class="text-sm text-gray-500"><?= date('Y-m-d H:i', strtotime($notification['created_at'])) ?></div>
                                        <a href="?action=mark_notification_read&notification_id=<?= (int)$notification['id'] ?>" class="text-xs text-blue-500 hover:text-blue-700">Mark as read</a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php if ($success_message): ?>
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-lg mb-6">
                    <?= htmlspecialchars($success_message) ?>
                </div>
            <?php endif; ?>
            
            <?php if ($error_message): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg mb-6">
                    <?= htmlspecialchars($error_message) ?>
                </div>
            <?php endif; ?>
            
            <!-- Quick Stats -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
                <div class="stat-card rounded-xl p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-lg bg-green-100 mr-4">
                            <i class='bx bx-money text-green-600 text-2xl'></i>
                        </div>
                        <div>
                            <p class="text-sm text-gray-500">Total Collected</p>
                            <p class="text-2xl font-bold text-gray-800 stat-value" 
                               data-value="₱<?php echo number_format($total_collected, 2); ?>"
                               data-stat="collected">
                                ••••••••
                            </p>
                        </div>
                    </div>
                </div>
                <div class="stat-card rounded-xl p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-lg bg-blue-100 mr-4">
                            <i class='bx bx-group text-blue-600 text-2xl'></i>
                        </div>
                        <div>
                            <p class="text-sm text-gray-500">Total Customers</p>
                            <p class="text-2xl font-bold text-gray-800"><?= $total_customers ?></p>
                        </div>
                    </div>
                </div>
                <div class="stat-card rounded-xl p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-lg bg-yellow-100 mr-4">
                            <i class='bx bx-credit-card text-yellow-600 text-2xl'></i>
                        </div>
                        <div>
                            <p class="text-sm text-gray-500">Total Payments</p>
                            <p class="text-2xl font-bold text-gray-800"><?= $total_payments ?></p>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Header with Create Button -->
            <div class="flex justify-between items-center mb-6">
                <div>
                    <h2 class="text-2xl font-bold text-gray-800">Payment Collection</h2>
                    <p class="text-gray-500 text-sm">Manage customer payments and collections</p>
                </div>
                <button id="receive-payment-btn" class="px-4 py-2 bg-brand-primary text-white rounded-lg hover:bg-brand-primary-hover transition flex items-center gap-2">
                    <i class='bx bx-plus'></i> Receive Payment
                </button>
            </div>
            
            <!-- Payment Records -->
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden mb-6">
                <div class="p-6 border-b border-gray-100">
                    <div class="flex justify-between items-center">
                        <h3 class="text-lg font-bold text-gray-800">Payment Records</h3>
                        <span class="text-sm text-gray-500">
                            Showing <?php echo count($payments); ?> payments
                        </span>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="text-left p-4 text-sm font-medium text-gray-500">Payment ID</th>
                                <th class="text-left p-4 text-sm font-medium text-gray-500">Customer</th>
                                <th class="text-left p-4 text-sm font-medium text-gray-500">Date</th>
                                <th class="text-left p-4 text-sm font-medium text-gray-500">Amount</th>
                                <th class="text-left p-4 text-sm font-medium text-gray-500">Method</th>
                                <th class="text-left p-4 text-sm font-medium text-gray-500">OR No</th>
                                <th class="text-left p-4 text-sm font-medium text-gray-500">Status</th>
                                <th class="text-left p-4 text-sm font-medium text-gray-500">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="payments-table-body">
                            <?php if (empty($payments)): ?>
                                <tr>
                                    <td colspan="8" class="p-8 text-center text-gray-500">
                                        <i class='bx bx-folder-open text-3xl mb-2 text-gray-300'></i>
                                        <div>No payment records found</div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($payments as $payment): ?>
                                    <tr class="transaction-row">
                                        <td class="p-4 font-medium text-gray-800">#<?= (int)($payment['id'] ?? 0) ?></td>
                                        <td class="p-4"><?= htmlspecialchars($payment['contact_name'] ?? 'N/A') ?></td>
                                        <td class="p-4 text-gray-600"><?= htmlspecialchars($payment['payment_date'] ?? 'N/A') ?></td>
                                        <td class="p-4 font-medium text-gray-800 payment-amount" 
                                            data-value="₱<?php echo number_format($payment['amount'] ?? 0, 2); ?>">
                                            ••••••••
                                        </td>
                                        <td class="p-4"><?= htmlspecialchars($payment['payment_method'] ?? 'N/A') ?></td>
                                        <td class="p-4"><?= htmlspecialchars($payment['reference_number'] ?? '-') ?></td>
                                        <td class="p-4">
                                            <?php
                                            $status = $payment['status'] ?? 'Completed';
                                            $statusClass = match($status) {
                                                'Completed' => 'status-completed',
                                                'Processing' => 'status-processing',
                                                'Failed' => 'status-failed',
                                                'Pending' => 'status-pending',
                                                default => 'status-pending'
                                            };
                                            ?>
                                            <span class="status-badge <?= $statusClass ?>"><?= htmlspecialchars($status) ?></span>
                                        </td>
                                        <td class="p-4">
                                            <div class="flex flex-wrap gap-2">
                                                <button class="action-btn edit edit-payment-btn" 
                                                        title="Edit Payment" 
                                                        data-payment-id="<?= (int)($payment['id'] ?? 0) ?>"
                                                        data-customer-name="<?= htmlspecialchars($payment['contact_name'] ?? '') ?>"
                                                        data-payment-date="<?= htmlspecialchars($payment['payment_date'] ?? '') ?>"
                                                        data-amount="<?= (float)($payment['amount'] ?? 0) ?>"
                                                        data-payment-method="<?= htmlspecialchars($payment['payment_method'] ?? '') ?>"
                                                        data-reference-number="<?= htmlspecialchars($payment['reference_number'] ?? '') ?>">
                                                    <i class='bx bx-edit mr-1'></i>Edit
                                                </button>
                                                <a href="receipt_generation.php?payment_id=<?= (int)($payment['id'] ?? 0) ?>" class="action-btn receipt" title="Generate Receipt">
                                                    <i class='bx bx-receipt mr-1'></i>Receipt
                                                </a>
                                                <button class="action-btn delete delete-payment-btn" 
                                                        title="Delete Payment" 
                                                        data-payment-id="<?= (int)($payment['id'] ?? 0) ?>"
                                                        data-payment-number="#<?= (int)($payment['id'] ?? 0) ?>">
                                                    <i class='bx bx-trash mr-1'></i>Delete
                                                </button>
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

    // Initialize all features
    initializeAllVisibility();
    initializeCommonFeatures();
    initializePaymentFeatures();
});

function initializeAllVisibility() {
    // Initialize payment amounts visibility
    const savedPaymentsVisible = localStorage.getItem('paymentsVisible');
    const paymentsVisible = savedPaymentsVisible === null ? false : savedPaymentsVisible === 'true';
    
    // Update payment amounts visibility
    updatePaymentAmountsVisibility(paymentsVisible);
    
    // Initialize stat visibility
    const statElements = document.querySelectorAll('.stat-value');
    statElements.forEach(element => {
        const statType = element.getAttribute('data-stat');
        const savedState = localStorage.getItem(`stat_${statType}_visible`);
        const isVisible = savedState === null ? false : savedState === 'true';
        
        const actualValue = element.getAttribute('data-value');
        if (isVisible) {
            element.textContent = actualValue;
            element.classList.remove('hidden-amount');
        } else {
            element.textContent = '••••••••';
            element.classList.add('hidden-amount');
        }
    });
    
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
            element.textContent = '••••••••';
            element.classList.add('hidden-amount');
        }
    });
    
    localStorage.setItem(`stat_${statType}_visible`, show);
}

function updateMainToggleState() {
    const visibilityToggle = document.getElementById('visibility-toggle');
    if (!visibilityToggle) return;
    
    // Check if ALL amounts are visible
    const statTypes = ['collected'];
    const allStatsVisible = statTypes.every(type => localStorage.getItem(`stat_${type}_visible`) === 'true');
    
    const paymentsVisible = localStorage.getItem('paymentsVisible') === 'true';
    const allVisible = allStatsVisible && paymentsVisible;
    
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

function updatePaymentAmountsVisibility(show) {
    const paymentAmounts = document.querySelectorAll('.payment-amount');
    paymentAmounts.forEach(element => {
        const actualValue = element.getAttribute('data-value');
        if (show) {
            element.textContent = actualValue;
            element.classList.remove('hidden-amount');
        } else {
            element.textContent = '••••••••';
            element.classList.add('hidden-amount');
        }
    });
    
    localStorage.setItem('paymentsVisible', show);
}

function updateAfterIndividualToggle() {
    // Small delay to ensure localStorage is updated
    setTimeout(updateMainToggleState, 50);
}

function initializePaymentFeatures() {
    // Receive Payment Modal
    const receivePaymentBtn = document.getElementById('receive-payment-btn');
    const receivePaymentModal = document.getElementById('receive-payment-modal');
    const editPaymentModal = document.getElementById('edit-payment-modal');
    const closeButtons = document.querySelectorAll('.close-modal');
    
    // Function to open modal
    function openModal(modal) {
        if (modal) {
            modal.style.display = 'block';
            document.body.style.overflow = 'hidden';
        }
    }
    
    // Function to close modal
    function closeModal(modal) {
        if (modal) {
            modal.style.display = 'none';
            document.body.style.overflow = 'auto';
        }
    }
    
    // Close all modals
    function closeAllModals() {
        if (receivePaymentModal) closeModal(receivePaymentModal);
        if (editPaymentModal) closeModal(editPaymentModal);
        const notificationModal = document.getElementById('notification-modal');
        if (notificationModal) closeModal(notificationModal);
    }
    
    // Receive Payment Modal
    if (receivePaymentBtn && receivePaymentModal) {
        receivePaymentBtn.addEventListener('click', function(e) {
            e.preventDefault();
            openModal(receivePaymentModal);
            // Reset form when opening modal
            const paymentForm = document.getElementById('receive-payment-form');
            if (paymentForm) {
                paymentForm.reset();
                // Set date to today
                const dateInput = paymentForm.querySelector('input[name="payment_date"]');
                if (dateInput) {
                    const today = new Date().toISOString().split('T')[0];
                    dateInput.value = today;
                }
            }
        });
    }
    
    // Edit Payment buttons
    document.querySelectorAll('.edit-payment-btn').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            const paymentId = this.getAttribute('data-payment-id');
            const customerName = this.getAttribute('data-customer-name');
            const paymentDate = this.getAttribute('data-payment-date');
            const amount = this.getAttribute('data-amount');
            const paymentMethod = this.getAttribute('data-payment-method');
            const referenceNumber = this.getAttribute('data-reference-number');
            
            // Populate the edit modal
            document.getElementById('edit-payment-id').value = paymentId;
            document.getElementById('edit-customer-name').value = customerName;
            document.getElementById('edit-payment-date').value = paymentDate;
            document.getElementById('edit-amount').value = amount;
            document.getElementById('edit-payment-method').value = paymentMethod;
            document.getElementById('edit-reference-number').value = referenceNumber === '-' ? '' : referenceNumber;
            
            // Open the edit modal
            openModal(editPaymentModal);
        });
    });
    
    // Delete Payment buttons
    document.querySelectorAll('.delete-payment-btn').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            const paymentId = this.getAttribute('data-payment-id');
            const paymentNumber = this.getAttribute('data-payment-number');
            
            if (confirm(`Are you sure you want to delete payment ${paymentNumber}? This action cannot be undone.`)) {
                window.location.href = `?action=delete_payment&id=${paymentId}`;
            }
        });
    });
    
    // Close buttons
    closeButtons.forEach(button => {
        button.addEventListener('click', function() {
            closeAllModals();
        });
    });
    
    // Close modals when clicking outside
    window.addEventListener('click', function(event) {
        if (event.target.classList.contains('modal')) {
            closeAllModals();
        }
    });

    // Form validation for payment form
    const paymentForm = document.getElementById('receive-payment-form');
    if (paymentForm) {
        paymentForm.addEventListener('submit', function(e) {
            const amountInput = document.getElementById('amount-input');
            const orNumberInput = document.querySelector('input[name="reference_number"]');
            
            if (amountInput) {
                const amount = parseFloat(amountInput.value);
                if (amount <= 0 || isNaN(amount)) {
                    e.preventDefault();
                    alert('Amount must be greater than 0');
                    amountInput.focus();
                    return false;
                }
            }
            
            if (orNumberInput && !orNumberInput.value.trim()) {
                e.preventDefault();
                alert('OR Number is required');
                orNumberInput.focus();
                return false;
            }
            
            return true;
        });
    }

    // Form validation for edit payment form
    const editPaymentForm = document.getElementById('edit-payment-form');
    if (editPaymentForm) {
        editPaymentForm.addEventListener('submit', function(e) {
            const amountInput = document.getElementById('edit-amount');
            const orNumberInput = document.getElementById('edit-reference-number');
            
            if (amountInput) {
                const amount = parseFloat(amountInput.value);
                if (amount <= 0 || isNaN(amount)) {
                    e.preventDefault();
                    alert('Amount must be greater than 0');
                    amountInput.focus();
                    return false;
                }
            }
            
            if (orNumberInput && !orNumberInput.value.trim()) {
                e.preventDefault();
                alert('OR Number is required');
                orNumberInput.focus();
                return false;
            }
            
            return true;
        });
    }
}

function markNotificationRead(notificationId) {
    window.location.href = `?action=mark_notification_read&notification_id=${notificationId}`;
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
    
    // Notification Modal
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
    
    // Main visibility toggle
    const visibilityToggle = document.getElementById('visibility-toggle');
    if (visibilityToggle) {
        visibilityToggle.addEventListener('click', function() {
            // Get current state
            const statTypes = ['collected'];
            const allStatsVisible = statTypes.every(type => localStorage.getItem(`stat_${type}_visible`) === 'true');
            const paymentsVisible = localStorage.getItem('paymentsVisible') === 'true';
            const currentState = allStatsVisible && paymentsVisible;
            const newState = !currentState;
            
            // Toggle all stat values
            statTypes.forEach(statType => {
                toggleStat(statType, newState);
            });
            
            // Toggle payments visibility
            updatePaymentAmountsVisibility(newState);
            
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
    const statElements = document.querySelectorAll('.stat-value');
    statElements.forEach(element => {
        // Add click event to the stat element itself
        element.addEventListener('click', function() {
            const statType = this.getAttribute('data-stat');
            const currentState = localStorage.getItem(`stat_${statType}_visible`) === 'true';
            const newState = !currentState;
            
            toggleStat(statType, newState);
            updateAfterIndividualToggle();
        });
    });
    
    // Initialize main toggle state
    updateMainToggleState();
}
</script>
</body>
</html>