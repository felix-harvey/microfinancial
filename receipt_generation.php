<?php
declare(strict_types=1);

// Enable error reporting
ini_set('display_errors', "1");
ini_set('display_startup_errors', "1");
error_reporting(E_ALL);

session_start();

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
$receipts = [];
$payments = [];
$customers = [];

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        if ($_POST['action'] === 'generate_receipt') {
            // Validate required fields
            $required = ['payment_id'];
            foreach ($required as $field) {
                if (empty($_POST[$field])) {
                    throw new Exception("Missing required field: $field");
                }
            }
            
            // Get payment details
            $payment_stmt = $db->prepare("
                SELECT p.*, c.name as contact_name, c.email, c.phone
                FROM payments p 
                LEFT JOIN business_contacts c ON p.contact_id = c.id 
                WHERE p.id = ?
            ");
            $payment_stmt->execute([$_POST['payment_id']]);
            $payment = $payment_stmt->fetch();
            
            if (!$payment) {
                throw new Exception("Payment not found");
            }
            
            // Generate receipt number
            $receipt_number = "RCP-" . date('Ymd') . "-" . str_pad((string)$payment['id'], 4, '0', STR_PAD_LEFT);
            
            // Check if receipts table exists, if not create it
            try {
                $check_table = $db->query("SELECT 1 FROM receipts LIMIT 1");
            } catch (Exception $e) {
                // Create receipts table if it doesn't exist
                $create_table = $db->exec("
                    CREATE TABLE receipts (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        receipt_number VARCHAR(50) NOT NULL UNIQUE,
                        payment_id INT NOT NULL,
                        contact_id INT NOT NULL,
                        receipt_date DATE NOT NULL,
                        amount DECIMAL(10,2) NOT NULL,
                        payment_method VARCHAR(50) NOT NULL,
                        reference_number VARCHAR(100),
                        notes TEXT,
                        created_by INT NOT NULL,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                    )
                ");
            }
            
            // Insert receipt into database
            $stmt = $db->prepare("
                INSERT INTO receipts (receipt_number, payment_id, contact_id, receipt_date, amount, 
                                     payment_method, reference_number, notes, created_by) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $receipt_date = $_POST['receipt_date'] ?? date('Y-m-d');
            $notes = $_POST['notes'] ?? '';
            
            $stmt->execute([
                $receipt_number,
                $_POST['payment_id'],
                $payment['contact_id'],
                $receipt_date,
                $payment['amount'],
                $payment['payment_method'],
                $payment['reference_number'] ?? null,
                $notes,
                $user_id
            ]);
            
            $receipt_id = $db->lastInsertId();
            
            $_SESSION['success_message'] = "Receipt generated successfully!";
            $_SESSION['generated_receipt_id'] = $receipt_id;
            
        } elseif ($_POST['action'] === 'delete_receipt') {
            // Delete receipt logic
            $receipt_id = $_POST['receipt_id'];
            
            // Get receipt details for confirmation message
            $getReceipt = $db->prepare("
                SELECT r.receipt_number 
                FROM receipts r 
                WHERE r.id = ?
            ");
            $getReceipt->execute([$receipt_id]);
            $receipt = $getReceipt->fetch();
            
            if ($receipt) {
                // Delete the receipt
                $delete_stmt = $db->prepare("DELETE FROM receipts WHERE id = ?");
                $delete_stmt->execute([$receipt_id]);
                
                $_SESSION['success_message'] = "Receipt " . $receipt['receipt_number'] . " has been deleted successfully!";
            } else {
                $_SESSION['error_message'] = "Receipt not found";
            }
        }
    } catch (Exception $e) {
        $_SESSION['error_message'] = "Error: " . $e->getMessage();
    }
    
    header("Location: receipt_generation.php");
    exit;
}

// Handle receipt print
if (isset($_GET['print']) && is_numeric($_GET['print'])) {
    $receipt_id = (int)$_GET['print'];
    
    try {
        // Get receipt details
        $receipt_stmt = $db->prepare("
            SELECT r.*, c.name as contact_name, c.email, c.phone, 
                   p.payment_date
            FROM receipts r 
            JOIN payments p ON r.payment_id = p.id 
            JOIN business_contacts c ON r.contact_id = c.id 
            WHERE r.id = ?
        ");
        $receipt_stmt->execute([$receipt_id]);
        $receipt = $receipt_stmt->fetch();
        
        if ($receipt) {
            // HTML receipt for printing
            echo '<!DOCTYPE html>
            <html>
            <head>
                <title>Receipt ' . $receipt['receipt_number'] . '</title>
                <style>
                    body { font-family: Arial, sans-serif; margin: 40px; }
                    .header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #333; padding-bottom: 20px; }
                    .section { margin-bottom: 20px; }
                    .label { font-weight: bold; }
                    table { width: 100%; border-collapse: collapse; margin: 20px 0; }
                    th, td { border: 1px solid #ddd; padding: 12px; text-align: left; }
                    th { background-color: #f5f5f5; }
                    .total { font-weight: bold; font-size: 18px; margin-top: 20px; }
                    .footer { margin-top: 40px; text-align: center; color: #666; }
                    @media print {
                        body { margin: 20px; }
                        .no-print { display: none; }
                    }
                </style>
            </head>
            <body>
                <div class="header">
                    <h1>PAYMENT RECEIPT</h1>
                    <div style="font-size: 18px; font-weight: bold;">Receipt No: ' . $receipt['receipt_number'] . '</div>
                </div>

                <div class="section">
                    <div><span class="label">Date:</span> ' . date('F j, Y', strtotime($receipt['receipt_date'])) . '</div>
                </div>

                <div class="section">
                    <div class="label">Received From:</div>
                    <div>' . htmlspecialchars($receipt['contact_name']) . '</div>
                    <div>' . htmlspecialchars($receipt['email'] ?? '') . '</div>
                    <div>' . htmlspecialchars($receipt['phone'] ?? '') . '</div>
                </div>
                            
                <table>
                    <thead>
                        <tr>
                            <th>Description</th>
                            <th>Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>Payment Receipt</td>
                            <td>₱' . number_format((float)$receipt['amount'], 2) . '</td>
                        </tr>
                    </tbody>
                </table>
                
                <div class="section">
                    <div class="total">Total Amount: ₱' . number_format((float)$receipt['amount'], 2) . '</div>
                    <div><span class="label">Payment Method:</span> ' . $receipt['payment_method'] . '</div>
                    <div><span class="label">OR No:</span> ' . ($receipt['reference_number'] ?? 'N/A') . '</div>
                </div>';
            
            if (!empty($receipt['notes'])) {
                echo '<div class="section">
                    <div class="label">Notes:</div>
                    <div>' . htmlspecialchars($receipt['notes']) . '</div>
                </div>';
            }
            
            echo '
                <div class="footer">
                    <p>Thank you for your payment!</p>
                    <p>Generated on ' . date('F j, Y g:i A') . '</p>
                </div>
                <div class="no-print" style="text-align: center; margin-top: 20px;">
                    <button onclick="window.print()" style="padding: 10px 20px; background: #2f855A; color: white; border: none; border-radius: 5px; cursor: pointer;">Print Receipt</button>
                    <button onclick="window.close()" style="padding: 10px 20px; background: #6b7280; color: white; border: none; border-radius: 5px; cursor: pointer; margin-left: 10px;">Close</button>
                </div>
                <script>
                    window.onload = function() {
                        window.print();
                    };
                </script>
            </body>
            </html>';
            exit;
        } else {
            $_SESSION['error_message'] = "Receipt not found";
            header("Location: receipt_generation.php");
            exit;
        }
    } catch (Exception $e) {
        error_log("Receipt print error: " . $e->getMessage());
        $_SESSION['error_message'] = "Error generating receipt: " . $e->getMessage();
        header("Location: receipt_generation.php");
        exit;
    }
}

// Get messages
$success_message = $_SESSION['success_message'] ?? '';
$error_message = $_SESSION['error_message'] ?? '';
$generated_receipt_id = $_SESSION['generated_receipt_id'] ?? null;
unset($_SESSION['success_message'], $_SESSION['error_message'], $_SESSION['generated_receipt_id']);

// Fetch data from database
try {
    // Check if receipts table exists
    try {
        $receipts_stmt = $db->query("
            SELECT r.*, c.name as contact_name, p.payment_date
            FROM receipts r 
            JOIN payments p ON r.payment_id = p.id 
            JOIN business_contacts c ON r.contact_id = c.id 
            ORDER BY r.receipt_date DESC, r.id DESC
        ");
        $receipts = $receipts_stmt->fetchAll();
    } catch (Exception $e) {
        // If receipts table doesn't exist, use empty array
        $receipts = [];
    }
    
    // Fetch completed payments without receipts
    try {
        // First check if receipts table exists
        $check_receipts = $db->query("SHOW TABLES LIKE 'receipts'");
        $receipts_table_exists = $check_receipts->rowCount() > 0;
        
        if ($receipts_table_exists) {
            $payments_stmt = $db->query("
                SELECT p.*, c.name as contact_name
                FROM payments p 
                LEFT JOIN business_contacts c ON p.contact_id = c.id 
                WHERE p.status = 'Completed' 
                AND NOT EXISTS (
                    SELECT 1 FROM receipts r WHERE r.payment_id = p.id
                )
                ORDER BY p.payment_date DESC
            ");
        } else {
            // If receipts table doesn't exist, get all completed payments
            $payments_stmt = $db->query("
                SELECT p.*, c.name as contact_name
                FROM payments p 
                LEFT JOIN business_contacts c ON p.contact_id = c.id 
                WHERE p.status = 'Completed'
                ORDER BY p.payment_date DESC
            ");
        }
        $payments = $payments_stmt->fetchAll();
    } catch (Exception $e) {
        error_log("Payments fetch error: " . $e->getMessage());
        $payments = [];
    }
    
    // Fetch customers
    try {
        $customers_stmt = $db->query("SELECT id, name FROM business_contacts WHERE type = 'Customer' AND status = 'Active' ORDER BY name");
        $customers = $customers_stmt->fetchAll();
    } catch (Exception $e) {
        error_log("Customers fetch error: " . $e->getMessage());
        $customers = [];
    }
    
} catch (Exception $e) {
    error_log("Data fetch error: " . $e->getMessage());
    // Use empty arrays if database fetch fails
    $receipts = [];
    $payments = [];
    $customers = [];
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
    <title>Receipt Generation - Financial Dashboard</title>

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
            color: #d1d5db;
            background: #f3f4f6;
            border-radius: 4px;
            padding: 2px 6px;
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
        
        .status-cancelled {
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
        
        .payment-method-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.25rem 0.5rem;
            border-radius: 0.375rem;
            font-size: 0.75rem;
            font-weight: 500;
        }
        
        .method-cash { background-color: #dcfce7; color: #166534; }
        .method-check { background-color: #fef3c7; color: #92400e; }
        .method-transfer { background-color: #dbeafe; color: #1e40af; }
        .method-card { background-color: #f3e8ff; color: #7c3aed; }
        
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
        
        /* Visibility toggle styles */
        .hidden-stat {
            font-family: monospace;
            letter-spacing: 3px;
            color: #d1d5db;
            background: #f3f4f6;
            border-radius: 4px;
            padding: 2px 6px;
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
                                <a href="receipt_generation.php" class="block px-3 py-1.5 rounded-lg text-xs bg-emerald-50 text-brand-primary font-medium border border-emerald-100 hover:bg-emerald-100 hover:border-emerald-200 transition-all duration-200 hover:translate-x-1">
                                    <span class="flex items-center justify-between">
                                        Receipt Generation
                                        <span class="inline-flex w-1.5 h-1.5 rounded-full bg-emerald-400"></span>
                                    </span>
                                </a>
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
                        Receipt Generation
                    </h1>
                    <p class="text-xs text-gray-500 truncate">
                        Generate and manage payment receipts
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
            <!-- Success Messages -->
            <?php if (!empty($success_message)): ?>
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-6">
                    <?php echo htmlspecialchars($success_message); ?>
                </div>
            <?php endif; ?>

            <!-- Error Messages -->
            <?php if (!empty($error_message)): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-6">
                    <?php echo htmlspecialchars($error_message); ?>
                </div>
            <?php endif; ?>

            <!-- Summary Cards -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-6">
                <?php 
                // Calculate stats
                $total_receipts = count($receipts);
                $total_receipt_amount = array_sum(array_column($receipts, 'amount'));
                $total_payments = count($payments);
                $total_payment_amount = array_sum(array_column($payments, 'amount'));
                
                $stats = [
                    ['icon' => 'bx-receipt', 'label' => 'Total Receipts', 'value' => $total_receipts, 'color' => 'green', 'stat' => 'receipts', 'is_amount' => false],
                    ['icon' => 'bx-money', 'label' => 'Receipts Amount', 'value' => $total_receipt_amount, 'color' => 'blue', 'stat' => 'receipts_amount', 'is_amount' => true],
                    ['icon' => 'bx-credit-card', 'label' => 'Pending Receipts', 'value' => $total_payments, 'color' => 'yellow', 'stat' => 'pending', 'is_amount' => false],
                    ['icon' => 'bx-wallet', 'label' => 'Pending Amount', 'value' => $total_payment_amount, 'color' => 'purple', 'stat' => 'pending_amount', 'is_amount' => true]
                ];
                
                foreach($stats as $stat): 
                    $bgColors = [
                        'green' => 'bg-green-100',
                        'blue' => 'bg-blue-100',
                        'yellow' => 'bg-yellow-100',
                        'purple' => 'bg-purple-100'
                    ];
                    $textColors = [
                        'green' => 'text-green-600',
                        'blue' => 'text-blue-600',
                        'yellow' => 'text-yellow-600',
                        'purple' => 'text-purple-600'
                    ];
                    
                    $displayValue = $stat['is_amount'] ? '₱' . number_format($stat['value'], 2) : $stat['value'];
                    $hiddenValue = $stat['is_amount'] ? '••••••••' : '••••';
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
                                       data-stat="<?php echo $stat['stat']; ?>"
                                       data-is-amount="<?php echo $stat['is_amount'] ? 'true' : 'false'; ?>">
                                        <?php echo $stat['is_amount'] ? $hiddenValue : $stat['value']; ?>
                                    </p>
                                </div>
                                <?php if ($stat['is_amount']): ?>
                                <button class="stat-toggle text-gray-400 hover:text-brand-primary transition"
                                        data-stat="<?php echo $stat['stat']; ?>">
                                    <i class="fa-solid fa-eye-slash"></i>
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Combined View: Receipts and Available Payments -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                <!-- Recent Receipts Section -->
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                    <div class="p-6 border-b border-gray-100">
                        <div class="flex justify-between items-center">
                            <div class="flex items-center gap-3">
                                <h3 class="text-lg font-bold text-gray-800">Recent Receipts</h3>
                                <span class="bg-gray-100 text-gray-600 text-xs font-medium px-2.5 py-0.5 rounded-full">
                                    <?php echo count($receipts); ?> receipts
                                </span>
                            </div>
                            <button class="text-brand-primary hover:text-brand-primary-hover text-sm font-medium" onclick="printAllReceipts()">
                                <i class='bx bx-printer mr-1'></i> Print All
                            </button>
                        </div>
                        <p class="text-sm text-gray-500 mt-1">Generated payment receipts</p>
                    </div>
                    <div class="overflow-x-auto max-h-[500px]">
                        <?php if (empty($receipts)): ?>
                            <div class="p-8 text-center text-gray-500">
                                <i class='bx bx-receipt text-3xl mb-2 text-gray-300'></i>
                                <div>No receipts found</div>
                                <div class="text-sm mt-1">Generate receipts from available payments</div>
                            </div>
                        <?php else: ?>
                            <table class="w-full">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="text-left p-4 text-sm font-medium text-gray-500">Receipt No</th>
                                        <th class="text-left p-4 text-sm font-medium text-gray-500">Date</th>
                                        <th class="text-left p-4 text-sm font-medium text-gray-500">Customer</th>
                                        <th class="text-left p-4 text-sm font-medium text-gray-500">Amount</th>
                                        <th class="text-left p-4 text-sm font-medium text-gray-500">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($receipts as $receipt): ?>
                                    <tr class="transaction-row">
                                        <td class="p-4 font-mono font-medium"><?php echo htmlspecialchars($receipt['receipt_number']); ?></td>
                                        <td class="p-4 text-gray-600"><?php echo date('M j, Y', strtotime($receipt['receipt_date'])); ?></td>
                                        <td class="p-4">
                                            <div class="font-medium"><?php echo htmlspecialchars($receipt['contact_name']); ?></div>
                                        </td>
                                        <td class="p-4 font-medium text-gray-800 receipt-amount">
                                            <span class="amount-value">₱<?php echo number_format((float)$receipt['amount'], 2); ?></span>
                                        </td>
                                        <td class="p-4">
                                            <div class="flex flex-wrap gap-2">
                                                <a href="receipt_generation.php?print=<?php echo $receipt['id']; ?>" target="_blank" class="px-3 py-1 text-sm bg-blue-100 text-blue-700 rounded-lg hover:bg-blue-200 transition flex items-center gap-1">
                                                    <i class='bx bx-printer'></i> Print
                                                </a>
                                                <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this receipt? This action cannot be undone.');">
                                                    <input type="hidden" name="action" value="delete_receipt">
                                                    <input type="hidden" name="receipt_id" value="<?php echo $receipt['id']; ?>">
                                                    <button type="submit" class="px-3 py-1 text-sm bg-red-100 text-red-700 rounded-lg hover:bg-red-200 transition flex items-center gap-1">
                                                        <i class='bx bx-trash'></i> Delete
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Available Payments Section -->
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                    <div class="p-6 border-b border-gray-100">
                        <div class="flex justify-between items-center">
                            <div class="flex items-center gap-3">
                                <h3 class="text-lg font-bold text-gray-800">Available Payments</h3>
                                <span class="bg-gray-100 text-gray-600 text-xs font-medium px-2.5 py-0.5 rounded-full">
                                    <?php echo count($payments); ?> payments
                                </span>
                            </div>
                            <button id="generate-receipt-btn" class="px-4 py-2 bg-brand-primary text-white rounded-lg hover:bg-brand-primary-hover transition flex items-center gap-2">
                                <i class='bx bx-plus'></i> Generate Receipt
                            </button>
                        </div>
                        <p class="text-sm text-gray-500 mt-1">Payments ready for receipt generation</p>
                    </div>
                    <div class="overflow-x-auto max-h-[500px]">
                        <?php if (empty($payments)): ?>
                            <div class="p-8 text-center text-gray-500">
                                <i class='bx bx-check-circle text-3xl mb-2 text-gray-300'></i>
                                <div>No payments available for receipt generation</div>
                                <div class="text-sm mt-1">All completed payments already have receipts</div>
                            </div>
                        <?php else: ?>
                            <table class="w-full">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="text-left p-4 text-sm font-medium text-gray-500">Payment</th>
                                        <th class="text-left p-4 text-sm font-medium text-gray-500">Date</th>
                                        <th class="text-left p-4 text-sm font-medium text-gray-500">Customer</th>
                                        <th class="text-left p-4 text-sm font-medium text-gray-500">Amount</th>
                                        <th class="text-left p-4 text-sm font-medium text-gray-500">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($payments as $payment): ?>
                                    <tr class="transaction-row">
                                        <td class="p-4 font-medium">#<?php echo $payment['id']; ?></td>
                                        <td class="p-4 text-gray-600"><?php echo date('M j, Y', strtotime($payment['payment_date'])); ?></td>
                                        <td class="p-4">
                                            <div class="font-medium"><?php echo htmlspecialchars($payment['contact_name']); ?></div>
                                        </td>
                                        <td class="p-4 font-medium text-gray-800 payment-amount">
                                            <span class="amount-value">₱<?php echo number_format((float)$payment['amount'], 2); ?></span>
                                        </td>
                                        <td class="p-4">
                                            <button class="px-3 py-1 text-sm bg-green-100 text-green-700 rounded-lg hover:bg-green-200 transition flex items-center gap-1 generate-payment-btn"
                                                    data-payment-id="<?php echo $payment['id']; ?>"
                                                    data-contact="<?php echo htmlspecialchars($payment['contact_name']); ?>"
                                                    data-amount="<?php echo $payment['amount']; ?>"
                                                    data-method="<?php echo htmlspecialchars($payment['payment_method']); ?>"
                                                    data-reference="<?php echo htmlspecialchars($payment['reference_number'] ?? ''); ?>">
                                                <i class='bx bx-receipt'></i> Generate
                                            </button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Success Message Section (if receipt was just generated) -->
            <?php if ($generated_receipt_id): ?>
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 mb-6 border-l-4 border-l-green-500">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-full bg-green-100 flex items-center justify-center">
                                <i class='bx bx-check text-green-600 text-xl'></i>
                            </div>
                            <div>
                                <h3 class="text-lg font-semibold text-gray-800">Receipt Generated Successfully!</h3>
                                <p class="text-gray-600">Your receipt has been generated. You can download or send it to the customer.</p>
                            </div>
                        </div>
                        <div class="flex space-x-2">
                            <a href="receipt_generation.php?print=<?php echo $generated_receipt_id; ?>" target="_blank" class="px-4 py-2 bg-brand-primary text-white rounded-lg hover:bg-brand-primary-hover transition flex items-center gap-2">
                                <i class='bx bx-printer'></i> Print Receipt
                            </a>
                            <a href="receipt_generation.php" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition flex items-center gap-2">
                                <i class='bx bx-list-ul'></i> View All
                            </a>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <!-- Generate Receipt Modal -->
    <div id="generate-receipt-modal" class="modal">
        <div class="modal-content">
            <div class="flex justify-between items-center mb-6">
                <h2 class="text-xl font-bold text-gray-800">Generate Receipt</h2>
                <button class="close-modal text-gray-400 hover:text-gray-600 text-2xl">&times;</button>
            </div>
            
            <form id="generate-receipt-form" method="POST">
                <input type="hidden" name="action" value="generate_receipt">
                <input type="hidden" name="payment_id" id="selected-payment-id">
                
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Payment</label>
                    <select id="payment-select" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-brand-primary focus:border-transparent" required>
                        <option value="">Select Payment</option>
                        <?php foreach ($payments as $payment): ?>
                            <option value="<?php echo $payment['id']; ?>" 
                                    data-contact="<?php echo htmlspecialchars($payment['contact_name']); ?>"
                                    data-amount="<?php echo floatval($payment['amount']); ?>"
                                    data-method="<?php echo htmlspecialchars($payment['payment_method']); ?>"
                                    data-reference="<?php echo htmlspecialchars($payment['reference_number'] ?? ''); ?>">
                                Payment #<?php echo $payment['id']; ?> - <?php echo htmlspecialchars($payment['contact_name']); ?> - ₱<?php echo number_format((float)$payment['amount'], 2); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Receipt Date</label>
                        <input type="date" name="receipt_date" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-brand-primary focus:border-transparent" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Amount</label>
                        <input type="text" id="preview-amount-input" class="w-full px-4 py-2 border border-gray-300 rounded-lg bg-gray-50" readonly>
                    </div>
                </div>
                
                <div class="mb-6">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Notes</label>
                    <textarea name="notes" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-brand-primary focus:border-transparent" rows="3" placeholder="Optional notes for the receipt"></textarea>
                </div>
                
                <!-- Preview Section -->
                <div id="receipt-preview-section" class="mt-6 p-4 border border-gray-200 rounded-lg hidden">
                    <h3 class="font-bold mb-3 text-gray-700">Receipt Preview</h3>
                    <div class="receipt-preview bg-white p-4 rounded border">
                        <div class="receipt-header text-center border-b-2 border-green-600 pb-3 mb-4">
                            <h1 class="text-2xl font-bold text-gray-800">PAYMENT RECEIPT</h1>
                            <div id="preview-receipt-number" class="font-semibold text-gray-600 mt-1">Receipt No: RCP-<?php echo date('Ymd'); ?>-XXXX</div>
                        </div>
                        <div class="receipt-details space-y-2">
                            <div><span class="font-semibold">Date:</span> <span id="preview-date"><?php echo date('F j, Y'); ?></span></div>
                            <div><span class="font-semibold">Received From:</span> <span id="preview-contact">-</span></div>
                        </div>
                        <table class="receipt-table w-full mt-4">
                            <thead>
                                <tr>
                                    <th class="bg-gray-50 p-2">Description</th>
                                    <th class="bg-gray-50 p-2">Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td class="p-2 border">Payment Receipt</td>
                                    <td class="p-2 border" id="preview-amount-display">₱0.00</td>
                                </tr>
                            </tbody>
                        </table>
                        <div class="receipt-details mt-4 space-y-1">
                            <div class="font-semibold text-lg">Total Amount: <span id="preview-total">₱0.00</span></div>
                            <div><span class="font-semibold">Payment Method:</span> <span id="preview-method">-</span></div>
                            <div><span class="font-semibold">OR No:</span> <span id="preview-reference">-</span></div>
                        </div>
                        <div class="receipt-footer mt-6 pt-4 border-t text-center text-gray-500">
                            <p>Thank you for your payment!</p>
                        </div>
                    </div>
                </div>
                
                <div class="flex space-x-4 mt-8">
                    <button type="button" class="flex-1 px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition close-modal">Cancel</button>
                    <button type="submit" class="flex-1 px-4 py-2 bg-brand-primary text-white rounded-lg hover:bg-brand-primary-hover transition">Generate Receipt</button>
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

        // Initialize visibility toggles
        initializeVisibilityToggles();
        
        // Initialize common features
        initializeCommonFeatures();
        
        // Initialize receipt generation functionality
        initializeReceiptGeneration();
    });

    function initializeVisibilityToggles() {
        // Main visibility toggle button
        const visibilityToggle = document.getElementById('visibility-toggle');
        if (visibilityToggle) {
            visibilityToggle.addEventListener('click', function() {
                const isHidden = localStorage.getItem('amountsVisible') !== 'false';
                const newState = !isHidden;
                
                // Update all amount displays
                updateAllAmountDisplays(newState);
                
                // Save state
                localStorage.setItem('amountsVisible', newState);
                
                // Update toggle icon
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
            
            // Initialize main toggle state
            const amountsVisible = localStorage.getItem('amountsVisible') !== 'false';
            const icon = visibilityToggle.querySelector('i');
            if (amountsVisible) {
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
                visibilityToggle.title = "Hide Amounts";
            } else {
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
                visibilityToggle.title = "Show Amounts";
            }
        }
        
        // Individual stat toggles (for amount stats only)
        const statToggles = document.querySelectorAll('.stat-toggle');
        statToggles.forEach(toggle => {
            toggle.addEventListener('click', function() {
                const statType = this.getAttribute('data-stat');
                const statElement = document.querySelector(`.stat-value[data-stat="${statType}"]`);
                const isAmount = statElement.getAttribute('data-is-amount') === 'true';
                
                if (isAmount) {
                    const isHidden = statElement.classList.contains('hidden-stat');
                    const newState = !isHidden;
                    
                    toggleStat(statType, newState);
                    localStorage.setItem(`stat_${statType}_visible`, newState);
                    
                    // Update main toggle state
                    updateMainToggleState();
                }
            });
            
            // Initialize individual stat states
            const statType = toggle.getAttribute('data-stat');
            const savedState = localStorage.getItem(`stat_${statType}_visible`);
            if (savedState !== null) {
                const statElement = document.querySelector(`.stat-value[data-stat="${statType}"]`);
                const isAmount = statElement.getAttribute('data-is-amount') === 'true';
                if (isAmount) {
                    toggleStat(statType, savedState === 'true');
                }
            }
        });
        
        // Initialize all amount displays
        const amountsVisible = localStorage.getItem('amountsVisible') !== 'false';
        updateAllAmountDisplays(amountsVisible);
        
        // Initialize individual stat toggles based on main state
        if (amountsVisible) {
            statToggles.forEach(toggle => {
                const statType = toggle.getAttribute('data-stat');
                const statElement = document.querySelector(`.stat-value[data-stat="${statType}"]`);
                const isAmount = statElement.getAttribute('data-is-amount') === 'true';
                
                if (isAmount) {
                    const icon = toggle.querySelector('i');
                    icon.classList.remove('fa-eye-slash');
                    icon.classList.add('fa-eye');
                    toggle.title = "Hide Amount";
                }
            });
        }
    }
    
    function updateAllAmountDisplays(show) {
        // Update stat cards
        const statElements = document.querySelectorAll('.stat-value');
        statElements.forEach(element => {
            const isAmount = element.getAttribute('data-is-amount') === 'true';
            if (isAmount) {
                if (show) {
                    element.textContent = element.getAttribute('data-value');
                    element.classList.remove('hidden-stat');
                } else {
                    element.textContent = '••••••••';
                    element.classList.add('hidden-stat');
                }
            }
        });
        
        // Update table amounts
        const amountValues = document.querySelectorAll('.amount-value');
        amountValues.forEach(element => {
            const originalText = element.textContent;
            const parent = element.closest('.receipt-amount, .payment-amount');
            
            if (parent) {
                parent.setAttribute('data-original', originalText);
                
                if (show) {
                    element.textContent = originalText;
                    parent.classList.remove('hidden-amount');
                } else {
                    element.textContent = '••••••••';
                    parent.classList.add('hidden-amount');
                }
            }
        });
        
        // Update individual stat toggle icons
        const statToggles = document.querySelectorAll('.stat-toggle');
        statToggles.forEach(toggle => {
            const statType = toggle.getAttribute('data-stat');
            const statElement = document.querySelector(`.stat-value[data-stat="${statType}"]`);
            const isAmount = statElement.getAttribute('data-is-amount') === 'true';
            
            if (isAmount) {
                const icon = toggle.querySelector('i');
                if (show) {
                    icon.classList.remove('fa-eye-slash');
                    icon.classList.add('fa-eye');
                    toggle.title = "Hide Amount";
                } else {
                    icon.classList.remove('fa-eye');
                    icon.classList.add('fa-eye-slash');
                    toggle.title = "Show Amount";
                }
            }
        });
    }
    
    function toggleStat(statType, show) {
        const statElements = document.querySelectorAll(`.stat-value[data-stat="${statType}"]`);
        statElements.forEach(element => {
            const isAmount = element.getAttribute('data-is-amount') === 'true';
            if (isAmount) {
                if (show) {
                    element.textContent = element.getAttribute('data-value');
                    element.classList.remove('hidden-stat');
                } else {
                    element.textContent = '••••••••';
                    element.classList.add('hidden-stat');
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
                toggleBtn.title = "Hide Amount";
            } else {
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
                toggleBtn.title = "Show Amount";
            }
        }
    }
    
    function updateMainToggleState() {
        const visibilityToggle = document.getElementById('visibility-toggle');
        if (!visibilityToggle) return;
        
        // Check if all amount stats are visible
        const statToggles = document.querySelectorAll('.stat-toggle');
        let allVisible = true;
        
        statToggles.forEach(toggle => {
            const statType = toggle.getAttribute('data-stat');
            const statElement = document.querySelector(`.stat-value[data-stat="${statType}"]`);
            const isAmount = statElement.getAttribute('data-is-amount') === 'true';
            
            if (isAmount) {
                if (statElement.classList.contains('hidden-stat')) {
                    allVisible = false;
                }
            }
        });
        
        const icon = visibilityToggle.querySelector('i');
        
        if (allVisible) {
            icon.classList.remove('fa-eye-slash');
            icon.classList.add('fa-eye');
            visibilityToggle.title = "Hide All Amounts";
            localStorage.setItem('amountsVisible', true);
        } else {
            icon.classList.remove('fa-eye');
            icon.classList.add('fa-eye-slash');
            visibilityToggle.title = "Show All Amounts";
            localStorage.setItem('amountsVisible', false);
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
        const generateReceiptBtn = document.getElementById('generate-receipt-btn');
        const generateReceiptModal = document.getElementById('generate-receipt-modal');
        const closeModalBtns = document.querySelectorAll('.close-modal');
        
        if (generateReceiptBtn && generateReceiptModal) {
            generateReceiptBtn.addEventListener('click', function() {
                generateReceiptModal.style.display = 'block';
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

    function initializeReceiptGeneration() {
        // Generate receipt from payment table
        document.querySelectorAll('.generate-payment-btn').forEach(button => {
            button.addEventListener('click', function() {
                const paymentId = this.getAttribute('data-payment-id');
                const contact = this.getAttribute('data-contact');
                const amount = this.getAttribute('data-amount');
                const method = this.getAttribute('data-method');
                const reference = this.getAttribute('data-reference');
                
                // Set the payment in the select
                const paymentSelect = document.getElementById('payment-select');
                const previewSection = document.getElementById('receipt-preview-section');
                
                if (paymentSelect) {
                    paymentSelect.value = paymentId;
                    document.getElementById('selected-payment-id').value = paymentId;
                    
                    // Update preview
                    previewSection.classList.remove('hidden');
                    document.getElementById('preview-contact').textContent = contact;
                    document.getElementById('preview-amount-input').value = '₱' + parseFloat(amount).toFixed(2);
                    document.getElementById('preview-amount-display').textContent = '₱' + parseFloat(amount).toFixed(2);
                    document.getElementById('preview-total').textContent = '₱' + parseFloat(amount).toFixed(2);
                    document.getElementById('preview-method').textContent = method;
                    document.getElementById('preview-reference').textContent = reference || 'N/A';
                    
                    // Update receipt date preview
                    const receiptDateInput = document.querySelector('input[name="receipt_date"]');
                    if (receiptDateInput && receiptDateInput.value) {
                        const receiptDate = new Date(receiptDateInput.value);
                        document.getElementById('preview-date').textContent = receiptDate.toLocaleDateString('en-US', { 
                            year: 'numeric', 
                            month: 'long', 
                            day: 'numeric' 
                        });
                    }
                    
                    // Open the modal
                    const modal = document.getElementById('generate-receipt-modal');
                    modal.style.display = 'block';
                    document.body.style.overflow = 'hidden';
                }
            });
        });
        
        // Update receipt preview when payment is selected
        const paymentSelect = document.getElementById('payment-select');
        if (paymentSelect) {
            paymentSelect.addEventListener('change', function() {
                const selectedOption = this.options[this.selectedIndex];
                const previewSection = document.getElementById('receipt-preview-section');
                
                if (this.value && selectedOption) {
                    document.getElementById('selected-payment-id').value = this.value;
                    
                    // Show preview section
                    previewSection.classList.remove('hidden');
                    
                    // Update preview content
                    const contact = selectedOption.getAttribute('data-contact') || '-';
                    const amount = parseFloat(selectedOption.getAttribute('data-amount') || 0);
                    const method = selectedOption.getAttribute('data-method') || '-';
                    const reference = selectedOption.getAttribute('data-reference') || 'N/A';
                    
                    document.getElementById('preview-contact').textContent = contact;
                    document.getElementById('preview-amount-input').value = '₱' + amount.toFixed(2);
                    document.getElementById('preview-amount-display').textContent = '₱' + amount.toFixed(2);
                    document.getElementById('preview-total').textContent = '₱' + amount.toFixed(2);
                    document.getElementById('preview-method').textContent = method;
                    document.getElementById('preview-reference').textContent = reference;
                    
                    // Update receipt date preview
                    const receiptDateInput = document.querySelector('input[name="receipt_date"]');
                    if (receiptDateInput && receiptDateInput.value) {
                        const receiptDate = new Date(receiptDateInput.value);
                        document.getElementById('preview-date').textContent = receiptDate.toLocaleDateString('en-US', { 
                            year: 'numeric', 
                            month: 'long', 
                            day: 'numeric' 
                        });
                    }
                } else {
                    // Hide preview section if no payment selected
                    previewSection.classList.add('hidden');
                }
            });
        }
        
        // Update receipt date preview when date changes
        const receiptDateInput = document.querySelector('input[name="receipt_date"]');
        if (receiptDateInput) {
            receiptDateInput.addEventListener('change', function() {
                if (paymentSelect && paymentSelect.value) {
                    paymentSelect.dispatchEvent(new Event('change'));
                }
            });
        }

        // Form submission handling
        const generateReceiptForm = document.getElementById('generate-receipt-form');
        if (generateReceiptForm) {
            generateReceiptForm.addEventListener('submit', function(e) {
                const submitBtn = this.querySelector('button[type="submit"]');
                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = '<div class="spinner"></div>Generating...';
                }
                // Form will submit normally
            });
        }
    }

    function printAllReceipts() {
        if (confirm('This will open each receipt in a new tab for printing. Continue?')) {
            // Get all receipt IDs
            const receiptLinks = document.querySelectorAll('a[href*="print="]');
            receiptLinks.forEach(link => {
                const receiptId = link.href.split('print=')[1];
                window.open(`receipt_generation.php?print=${receiptId}`, '_blank');
            });
        }
    }
    </script>
</body>
</html>