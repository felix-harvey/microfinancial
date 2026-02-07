<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/database.php';

// Generate CSRF token FIRST before any output
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Initialize session notifications safely
if (!isset($_SESSION['ap_ar_notifications']) || !is_array($_SESSION['ap_ar_notifications'])) {
    $_SESSION['ap_ar_notifications'] = [];
}

// Handle mark as read notifications request
if (isset($_GET['mark_notifications_read'])) {
    foreach ($_SESSION['ap_ar_notifications'] as &$notification) {
        $notification['read'] = true;
    }
    unset($notification); // Fix: clean up reference
    echo json_encode(['success' => true]);
    exit;
}

error_reporting(E_ALL);
ini_set('display_errors', '1');

// Database Connection with proper error handling
try {
    $database = new Database();
    $db = $database->getConnection();
    if (!$db instanceof PDO) {
        throw new Exception("Failed to get valid database connection.");
    }
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log("Database connection error: " . $e->getMessage());
    http_response_code(500);
    die("Database connection error. Please try again later.");
}

// Authentication Check
if (empty($_SESSION['user_id']) || !is_numeric($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}
$user_id = (int)$_SESSION['user_id'];

// User Loading with error handling
try {
    $u = $db->prepare("SELECT id, name, username, role FROM users WHERE id = ?");
    $u->execute([$user_id]);
    $user = $u->fetch();
    if (!$user) {
        header("Location: index.php");
        exit;
    }
} catch (PDOException $e) {
    error_log("User loading error: " . $e->getMessage());
    header("Location: index.php");
    exit;
}

/** * HELPER FUNCTIONS 
 */

function formatNumber($number, $show_numbers = false): string {
    return $show_numbers ? '₱' . number_format((float)$number, 2) : '₱ ••••••••';
}

function formatCount($count) {
    return $count; // Always show actual count, never hidden
}

function addInvoiceNotification($invoice_number, $invoice_type, $amount): void {
    $type_display = $invoice_type === 'Receivable' ? 'Accounts Receivable' : 'Accounts Payable';
    $notification = [
        'id' => uniqid(),
        'type' => 'invoice',
        'message' => "New {$type_display} invoice created: {$invoice_number} - " . formatNumber($amount, true),
        'timestamp' => time(),
        'link' => 'invoices.php',
        'read' => false
    ];
    array_unshift($_SESSION['ap_ar_notifications'], $notification);
    $_SESSION['ap_ar_notifications'] = array_slice($_SESSION['ap_ar_notifications'], 0, 10);
}

function addInvoiceUpdateNotification($invoice_number, $invoice_type, $status): void {
    $type_display = $invoice_type === 'Receivable' ? 'Accounts Receivable' : 'Accounts Payable';
    $notification = [
        'id' => uniqid(),
        'type' => 'invoice',
        'message' => "{$type_display} invoice updated: {$invoice_number} - Status changed to {$status}",
        'timestamp' => time(),
        'link' => 'invoices.php',
        'read' => false
    ];
    array_unshift($_SESSION['ap_ar_notifications'], $notification);
    $_SESSION['ap_ar_notifications'] = array_slice($_SESSION['ap_ar_notifications'], 0, 10);
}

function getUnreadNotificationCount(): int {
    $unread_count = 0;
    if (is_array($_SESSION['ap_ar_notifications'])) {
        foreach ($_SESSION['ap_ar_notifications'] as $notification) {
            if (!isset($notification['read']) || $notification['read'] === false) {
                $unread_count++;
            }
        }
    }
    return $unread_count;
}

function getNotifications(): array {
    return $_SESSION['ap_ar_notifications'] ?? [];
}

function getInvoices(PDO $db, $type = null, $status = null, $start_date = null, $end_date = null): array {
    // Cast to string to prevent TypeError if arrays are passed via GET
    $type = is_string($type) ? $type : null;
    $status = is_string($status) ? $status : null;
    
    $sql = "SELECT i.*, bc.name as contact_name, bc.contact_person, bc.email, bc.phone,
                   COALESCE(SUM(p.amount), 0) as amount_paid,
                   (i.amount - COALESCE(SUM(p.amount), 0)) as outstanding_balance
            FROM invoices i
            LEFT JOIN business_contacts bc ON i.contact_id = bc.contact_id
            LEFT JOIN payments p ON i.id = p.invoice_id AND p.status = 'Completed'
            WHERE 1=1";
    
    $params = [];
    if ($type) { $sql .= " AND i.type = ?"; $params[] = $type; }
    if ($status) { $sql .= " AND i.status = ?"; $params[] = $status; }
    if ($start_date) { $sql .= " AND i.issue_date >= ?"; $params[] = (string)$start_date; }
    if ($end_date) { $sql .= " AND i.issue_date <= ?"; $params[] = (string)$end_date; }
    
    $sql .= " GROUP BY i.id ORDER BY i.issue_date DESC, i.id DESC";
    
    try {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Error fetching invoices: " . $e->getMessage());
        return [];
    }
}

function getBusinessContacts(PDO $db, ?string $type = null): array {
    $sql = "SELECT contact_id, name, contact_person, type 
            FROM business_contacts 
            WHERE status = 'Active'";
    
    if ($type) {
        $sql .= " AND type = ?";
    }
    
    try {
        $stmt = $db->prepare($sql);
        $stmt->execute($type ? [$type] : []);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Error fetching business contacts: " . $e->getMessage());
        return [];
    }
}

function generateInvoiceNumber(PDO $db, string $type): string {
    $prefix = $type === 'Receivable' ? 'INV' : 'V-INV';
    $year = date('Y');
    
    try {
        $sql = "SELECT MAX(CAST(SUBSTRING_INDEX(invoice_number, '-', -1) AS UNSIGNED)) as max_num 
                FROM invoices 
                WHERE invoice_number LIKE ? AND YEAR(issue_date) = ?";
        $stmt = $db->prepare($sql);
        $stmt->execute([$prefix . '-' . $year . '-%', $year]);
        $result = $stmt->fetch();
        
        $next_num = ($result['max_num'] ?? 0) + 1;
        return $prefix . '-' . $year . '-' . str_pad((string)$next_num, 3, '0', STR_PAD_LEFT);
    } catch (PDOException $e) {
        error_log("Error generating invoice number: " . $e->getMessage());
        // Fallback
        $count = $db->query("SELECT COUNT(*) as count FROM invoices WHERE type = '$type' AND YEAR(issue_date) = '$year'")->fetch()['count'];
        $next_num = (int)$count + 1;
        return $prefix . '-' . $year . '-' . str_pad((string)$next_num, 3, '0', STR_PAD_LEFT);
    }
}

function getInvoiceDetails(PDO $db, int $invoice_id): array {
    try {
        $sql = "SELECT i.*, bc.name as contact_name, bc.type as contact_type
                FROM invoices i
                LEFT JOIN business_contacts bc ON i.contact_id = bc.contact_id
                WHERE i.id = ?";
        $stmt = $db->prepare($sql);
        $stmt->execute([$invoice_id]);
        return $stmt->fetch() ?: [];
    } catch (PDOException $e) {
        error_log("Error fetching invoice details: " . $e->getMessage());
        return [];
    }
}

function loadNotificationsFromDatabase(PDO $db, int $user_id): array {
    // Check/Create table logic
    try {
        $db->query("SELECT 1 FROM user_notifications LIMIT 1");
    } catch (PDOException $e) {
        $db->exec("CREATE TABLE IF NOT EXISTS user_notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT,
            notification_type VARCHAR(50),
            title VARCHAR(255),
            message TEXT,
            is_read BOOLEAN DEFAULT FALSE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )");
    }
    
    $stmt = $db->prepare("SELECT * FROM user_notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 10");
    $stmt->execute([$user_id]);
    $dbNotifications = $stmt->fetchAll();
    
    $notifications = [];
    foreach ($dbNotifications as $notification) {
        $notifications[] = [
            'type' => $notification['notification_type'],
            'title' => $notification['title'],
            'message' => $notification['message'],
            'time' => getTimeAgo($notification['created_at']),
            'read' => (bool)$notification['is_read'],
            'db_id' => $notification['id']
        ];
    }
    return $notifications;
}

function getTimeAgo(string $datetime): string {
    $time = strtotime($datetime);
    $timeDiff = time() - $time;
    if ($timeDiff < 60) return 'Just now';
    if ($timeDiff < 3600) return floor($timeDiff / 60) . ' mins ago';
    if ($timeDiff < 86400) return floor($timeDiff / 3600) . ' hours ago';
    return floor($timeDiff / 86400) . ' days ago';
}

/**
 * FORM HANDLING
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // CSRF Protection with string casting
    $session_token = (string)($_SESSION['csrf_token'] ?? '');
    $post_token = (string)($_POST['csrf_token'] ?? '');
    
    if (empty($session_token) || !hash_equals($session_token, $post_token)) {
        $_SESSION['error'] = "Security validation failed";
        header("Location: invoices.php");
        exit;
    }

    if ($action === 'delete_invoice') {
        $invoice_id = (int)($_POST['invoice_id'] ?? 0);
        try {
            $stmt = $db->prepare("DELETE FROM invoices WHERE id = ?");
            $stmt->execute([$invoice_id]);
            $_SESSION['success'] = "Invoice deleted successfully!";
        } catch (PDOException $e) {
            $_SESSION['error'] = "Delete failed: " . $e->getMessage();
        }
        header("Location: invoices.php");
        exit;
    }
    
    if ($action === 'add_invoice') {
        $invoice_number = trim($_POST['invoice_number'] ?? '');
        $contact_id = $_POST['contact_id'] ?? '';
        $type = $_POST['type'] ?? '';
        $issue_date = $_POST['issue_date'] ?? '';
        $due_date = $_POST['due_date'] ?? '';
        $amount = (float)($_POST['amount'] ?? 0);
        $description = trim($_POST['description'] ?? '');
        
        // Validation
        $errors = [];
        if (empty($invoice_number)) $errors[] = "Invoice number is required";
        if (empty($contact_id)) $errors[] = "Valid contact is required";
        if (!in_array($type, ['Receivable', 'Payable'])) $errors[] = "Invalid invoice type";
        if (empty($issue_date)) $errors[] = "Issue date is required";
        if (empty($due_date)) $errors[] = "Due date is required";
        if ($amount <= 0) $errors[] = "Amount must be greater than 0";
        
        if (!empty($errors)) {
            $_SESSION['error'] = implode("<br>", $errors);
            header("Location: invoices.php");
            exit;
        }
        
        try {
            $sql = "INSERT INTO invoices (invoice_number, contact_id, type, issue_date, due_date, amount, description, status) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'Pending')";
            $stmt = $db->prepare($sql);
            $stmt->execute([$invoice_number, $contact_id, $type, $issue_date, $due_date, $amount, $description]);
            
            // Add notification for new invoice
            addInvoiceNotification($invoice_number, $type, $amount);
            
            $_SESSION['success'] = "Invoice created successfully!";
            header("Location: invoices.php");
            exit;
        } catch (PDOException $e) {
            error_log("Error adding invoice: " . $e->getMessage());
            $_SESSION['error'] = "Error creating invoice: " . $e->getMessage();
            header("Location: invoices.php");
            exit;
        }
    }
    
    if ($action === 'update_invoice') {
        $invoice_id = (int)($_POST['invoice_id'] ?? 0);
        $invoice_number = trim($_POST['invoice_number'] ?? '');
        $contact_id = $_POST['contact_id'] ?? '';
        $type = $_POST['type'] ?? '';
        $issue_date = $_POST['issue_date'] ?? '';
        $due_date = $_POST['due_date'] ?? '';
        $amount = (float)($_POST['amount'] ?? 0);
        $description = trim($_POST['description'] ?? '');
        $status = $_POST['status'] ?? '';
        
        if ($invoice_id <= 0) {
            $_SESSION['error'] = "Invalid invoice ID";
            header("Location: invoices.php");
            exit;
        }
        
        try {
            // Get current invoice details to check if status changed
            $current_sql = "SELECT status, type FROM invoices WHERE id = ?";
            $current_stmt = $db->prepare($current_sql);
            $current_stmt->execute([$invoice_id]);
            $current_invoice = $current_stmt->fetch();
            
            $sql = "UPDATE invoices SET invoice_number = ?, contact_id = ?, type = ?, issue_date = ?, 
                    due_date = ?, amount = ?, description = ?, status = ? 
                    WHERE id = ?";
            $stmt = $db->prepare($sql);
            $stmt->execute([$invoice_number, $contact_id, $type, $issue_date, $due_date, $amount, $description, $status, $invoice_id]);
            
            // Add notification if status changed
            if ($current_invoice && $current_invoice['status'] !== $status) {
                addInvoiceUpdateNotification($invoice_number, $type, $status);
            }
            
            $_SESSION['success'] = "Invoice updated successfully!";
            header("Location: invoices.php");
            exit;
        } catch (PDOException $e) {
            error_log("Error updating invoice: " . $e->getMessage());
            $_SESSION['error'] = "Error updating invoice: " . $e->getMessage();
            header("Location: invoices.php");
            exit;
        }
    }
    
    if ($action === 'update_invoice_status') {
        $invoice_id = (int)($_POST['invoice_id'] ?? 0);
        $status = $_POST['status'] ?? '';
        
        if ($invoice_id <= 0) {
            $_SESSION['error'] = "Invalid invoice ID";
            header("Location: invoices.php");
            exit;
        }
        
        try {
            // Get current invoice details for notification
            $current_sql = "SELECT invoice_number, type FROM invoices WHERE id = ?";
            $current_stmt = $db->prepare($current_sql);
            $current_stmt->execute([$invoice_id]);
            $current_invoice = $current_stmt->fetch();
            
            $sql = "UPDATE invoices SET status = ? WHERE id = ?";
            $stmt = $db->prepare($sql);
            $stmt->execute([$status, $invoice_id]);
            
            // Add notification for status change
            if ($current_invoice) {
                addInvoiceUpdateNotification($current_invoice['invoice_number'], $current_invoice['type'], $status);
            }
            
            $_SESSION['success'] = "Invoice status updated successfully!";
            header("Location: invoices.php");
            exit;
        } catch (PDOException $e) {
            error_log("Error updating invoice status: " . $e->getMessage());
            $_SESSION['error'] = "Error updating invoice status: " . $e->getMessage();
            header("Location: invoices.php");
            exit;
        }
    }
}

// Handle AJAX request for invoice details
if (isset($_GET['get_invoice_details']) && isset($_GET['invoice_id'])) {
    $invoice_id = (int)$_GET['invoice_id'];
    $invoice = getInvoiceDetails($db, $invoice_id);
    header('Content-Type: application/json');
    echo json_encode($invoice);
    exit;
}

// Update overdue invoices automatically
try {
    $update_sql = "UPDATE invoices SET status = 'Overdue' 
                   WHERE due_date < CURDATE() 
                   AND status = 'Pending' 
                   AND status != 'Paid'";
    $db->exec($update_sql);
} catch (PDOException $e) {
    error_log("Error updating overdue invoices: " . $e->getMessage());
}

// Get filter values from request
$invoice_type = $_GET['type'] ?? null;
$invoice_status = $_GET['status'] ?? null;
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-t');

$invoices = getInvoices($db, $invoice_type, $invoice_status, $start_date, $end_date);
$vendors = getBusinessContacts($db, 'Vendor');
$customers = getBusinessContacts($db, 'Customer');
$all_contacts = getBusinessContacts($db);

// Get notifications
$notification_count = getUnreadNotificationCount();
$notifications = getNotifications();

// Check for success/error messages
$success_message = $_SESSION['success'] ?? '';
$error_message = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);

// Handle payment success redirect
if (isset($_GET['payment_success']) && isset($_GET['invoice_id'])) {
    $invoice_id = (int)$_GET['invoice_id'];
    $_SESSION['success'] = "Payment recorded successfully! Invoice status updated.";
    header("Location: invoices.php");
    exit;
}

$dashboard_notifications = loadNotificationsFromDatabase($db, $user_id);
$unreadCount = 0;
foreach($dashboard_notifications as $n) { if(!$n['read']) $unreadCount++; }
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Invoices - Financial Dashboard</title>

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
            max-width: 700px;
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
        
        .hidden-numbers {
            letter-spacing: 2px;
            font-family: monospace;
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

        .action-btn.pay {
            background-color: #F0F9FF;
            color: #0369A1;
            border-color: #0369A1;
        }
        
        .action-btn.pay:hover {
            background-color: #0369A1;
            color: white;
        }
        
        .overdue-invoice {
            background-color: #FEF2F2;
            border-left: 4px solid #EF4444;
        }
        
        .due-soon-invoice {
            background-color: #FFFBEB;
            border-left: 4px solid #F59E0B;
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
                               bg-emerald-50 text-brand-primary border border-emerald-100
                               transition-all duration-200 hover:translate-x-1 active:translate-x-0 active:scale-[0.99] font-semibold text-sm">
                        <span class="flex items-center gap-3">
                            <span class="inline-flex w-7 h-7 rounded-lg bg-emerald-100 items-center justify-center">
                                <i class='bx bx-receipt text-emerald-600 text-xs'></i>
                            </span>
                            AP/AR
                        </span>
                        <svg id="ap-ar-arrow" class="w-3.5 h-3.5 text-emerald-400 transition-transform duration-300 rotate-180" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </button>
                    <div id="ap-ar-submenu" class="submenu mt-1 active">
                        <div class="pl-3 pr-2 py-1.5 space-y-1 border-l-2 border-emerald-200 ml-5">
                            <a href="vendors_customers.php" class="block px-3 py-1.5 rounded-lg text-xs text-gray-600 hover:bg-green-50 hover:text-brand-primary transition-all duration-200 hover:translate-x-1">
                                Payable/Receivable
                            </a>
                            <a href="invoices.php" class="block px-3 py-1.5 rounded-lg text-xs bg-emerald-50 text-brand-primary font-medium border border-emerald-100 hover:bg-emerald-100 hover:border-emerald-200 transition-all duration-200 hover:translate-x-1">
                                <span class="flex items-center justify-between">
                                    Invoices
                                    <span class="inline-flex w-1.5 h-1.5 rounded-full bg-emerald-400"></span>
                                </span>
                            </a>
                            <a href="payment_entry.php" class="block px-3 py-1.5 rounded-lg text-xs text-gray-600 hover:bg-green-50 hover:text-brand-primary transition-all duration-200 hover:translate-x-1">
                                Payment Entry
                            </a>
                            <a href="aging_reports.php" class="block px-3 py-1.5 rounded-lg text-xs text-gray-600 hover:bg-green-50 hover:text-brand-primary transition-all duration-200 hover:translate-x-1">
                                Aging Reports
                            </a>
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
                        Invoices
                    </h1>
                    <p class="text-xs text-gray-500">
                        Manage accounts receivable and payable invoices
                    </p>
                </div>
            </div>

            <div class="flex items-center gap-3 sm:gap-5">
                <!-- Real-time Clock -->
                <span id="real-time-clock"
                    class="text-xs font-bold text-gray-700 bg-gray-50 px-3 py-2 rounded-lg border border-gray-200">
                    --:--:--
                </span>

                <!-- SINGLE VISIBILITY TOGGLE FOR ALL AMOUNTS -->
                <button id="amount-visibility-toggle" class="w-10 h-10 rounded-xl hover:bg-gray-100 active:bg-gray-200 transition flex items-center justify-center relative"
                        title="Toggle Amount Visibility">
                    <i class="fa-solid fa-eye-slash text-gray-600"></i>
                </button>

                <!-- Notifications -->
                <button id="notification-btn" class="w-10 h-10 rounded-xl hover:bg-gray-100 active:bg-gray-200 transition flex items-center justify-center relative">
                    <i class="fa-solid fa-bell text-gray-600"></i>
                    <?php if($unreadCount > 0): ?>
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
                                <?php echo strtoupper(substr($user['name'] ?? 'U', 0, 1)); ?>
                            </div>
                        </div>
                        <div class="hidden md:flex flex-col items-start text-left">
                            <span class="text-sm font-bold text-gray-700 group-hover:text-brand-primary transition-colors">
                                <?php echo htmlspecialchars($user['name'] ?? 'User'); ?>
                            </span>
                            <span class="text-[10px] text-gray-500 font-medium uppercase group-hover:text-brand-primary transition-colors">
                                <?php echo ucfirst(htmlspecialchars($user['role'] ?? 'User')); ?>
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
            <div class="space-y-6">
                <!-- Success and Error Messages -->
                <?php if (!empty($success_message)): ?>
                    <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-6">
                        <?php echo htmlspecialchars($success_message); ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($error_message)): ?>
                    <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-6">
                        <?php echo htmlspecialchars($error_message); ?>
                    </div>
                <?php endif; ?>

                <!-- Summary Cards -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                    <?php 
                    // Calculate invoice statistics
                    $total_invoices = count($invoices);
                    $outstanding_total = array_sum(array_column($invoices, 'outstanding_balance'));
                    $overdue_count = count(array_filter($invoices, fn($inv) => $inv['status'] === 'Overdue'));
                    $paid_this_month = array_sum(array_column($invoices, 'amount_paid'));
                    
                    $stats = [
                        ['icon' => 'bx-receipt', 'label' => 'Total Invoices', 'value' => $total_invoices, 'color' => 'blue', 'stat' => 'total_invoices', 'is_count' => true],
                        ['icon' => 'bx-time', 'label' => 'Outstanding', 'value' => $outstanding_total, 'color' => 'yellow', 'stat' => 'outstanding', 'is_count' => false],
                        ['icon' => 'bx-error-circle', 'label' => 'Overdue', 'value' => $overdue_count, 'color' => 'red', 'stat' => 'overdue', 'is_count' => true],
                        ['icon' => 'bx-check-circle', 'label' => 'Paid This Month', 'value' => $paid_this_month, 'color' => 'green', 'stat' => 'paid', 'is_count' => false]
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
                                           data-value="<?php echo $stat['is_count'] ? formatCount($stat['value']) : '₱' . number_format((float)$stat['value'], 2); ?>"
                                           data-stat="<?php echo $stat['stat']; ?>">
                                            <?php echo $stat['is_count'] ? formatCount($stat['value']) : '₱ ••••••••'; ?>
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Filter Section -->
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                    <h3 class="text-lg font-bold text-gray-800 mb-4">Filter Invoices</h3>
                    <form method="GET" class="grid grid-cols-1 md:grid-cols-5 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Type</label>
                            <select name="type" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-brand-primary focus:border-transparent">
                                <option value="">All Types</option>
                                <option value="Receivable" <?php echo $invoice_type === 'Receivable' ? 'selected' : ''; ?>>Accounts Receivable</option>
                                <option value="Payable" <?php echo $invoice_type === 'Payable' ? 'selected' : ''; ?>>Accounts Payable</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Status</label>
                            <select name="status" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-brand-primary focus:border-transparent">
                                <option value="">All Status</option>
                                <option value="Pending" <?php echo $invoice_status === 'Pending' ? 'selected' : ''; ?>>Pending</option>
                                <option value="Paid" <?php echo $invoice_status === 'Paid' ? 'selected' : ''; ?>>Paid</option>
                                <option value="Overdue" <?php echo $invoice_status === 'Overdue' ? 'selected' : ''; ?>>Overdue</option>
                                <option value="Cancelled" <?php echo $invoice_status === 'Cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Start Date</label>
                            <input type="date" name="start_date" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-brand-primary focus:border-transparent" value="<?php echo htmlspecialchars($start_date); ?>">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">End Date</label>
                            <input type="date" name="end_date" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-brand-primary focus:border-transparent" value="<?php echo htmlspecialchars($end_date); ?>">
                        </div>
                        <div class="flex items-end space-x-2">
                            <button type="submit" class="flex-1 px-4 py-2 bg-brand-primary text-white rounded-lg hover:bg-brand-primary-hover transition flex items-center gap-2">
                                <i class='bx bx-filter-alt'></i>Apply
                            </button>
                            <a href="invoices.php" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition flex items-center gap-2">
                                <i class='bx bx-reset'></i>Reset
                            </a>
                        </div>
                    </form>
                </div>

                <!-- Invoices Table -->
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                    <div class="p-6 border-b border-gray-100">
                        <div class="flex justify-between items-center">
                            <h3 class="text-lg font-bold text-gray-800">Invoice Management</h3>
                            <button class="px-4 py-2 bg-brand-primary text-white rounded-lg hover:bg-brand-primary-hover transition flex items-center gap-2" onclick="openInvoiceModal()">
                                <i class='bx bx-plus'></i>Create Invoice
                            </button>
                        </div>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="text-left p-4 text-sm font-medium text-gray-500">Invoice #</th>
                                    <th class="text-left p-4 text-sm font-medium text-gray-500">Contact</th>
                                    <th class="text-left p-4 text-sm font-medium text-gray-500">Type</th>
                                    <th class="text-left p-4 text-sm font-medium text-gray-500">Issue Date</th>
                                    <th class="text-left p-4 text-sm font-medium text-gray-500">Due Date</th>
                                    <th class="text-left p-4 text-sm font-medium text-gray-500">Amount</th>
                                    <th class="text-left p-4 text-sm font-medium text-gray-500">Paid</th>
                                    <th class="text-left p-4 text-sm font-medium text-gray-500">Outstanding</th>
                                    <th class="text-left p-4 text-sm font-medium text-gray-500">Status</th>
                                    <th class="text-left p-4 text-sm font-medium text-gray-500">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="invoices-table-body">
                                <?php if (count($invoices) > 0): ?>
                                    <?php foreach ($invoices as $invoice): 
                                        $is_overdue = strtotime($invoice['due_date']) < time() && $invoice['status'] === 'Pending';
                                        $row_class = $is_overdue ? 'overdue-invoice' : '';
                                    ?>
                                    <tr class="transaction-row <?php echo $row_class; ?>">
                                        <td class="p-4 font-mono font-medium"><?php echo htmlspecialchars($invoice['invoice_number']); ?></td>
                                        <td class="p-4">
                                            <div class="font-medium"><?php echo htmlspecialchars($invoice['contact_name'] ?? 'N/A'); ?></div>
                                            <div class="text-xs text-gray-500"><?php echo htmlspecialchars($invoice['contact_person'] ?? ''); ?></div>
                                        </td>
                                        <td class="p-4">
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium 
                                                <?php echo $invoice['type'] === 'Receivable' ? 'bg-green-100 text-green-800' : 'bg-blue-100 text-blue-800'; ?>">
                                                <?php echo $invoice['type'] === 'Receivable' ? 'AR' : 'AP'; ?>
                                            </span>
                                        </td>
                                        <td class="p-4 text-gray-600"><?php echo htmlspecialchars($invoice['issue_date']); ?></td>
                                        <td class="p-4">
                                            <div class="<?php echo $is_overdue ? 'text-red-600 font-medium' : ''; ?>">
                                                <?php echo htmlspecialchars($invoice['due_date']); ?>
                                            </div>
                                        </td>
                                        <td class="p-4 font-medium text-gray-800 amount-value" 
                                            data-value="₱<?php echo number_format((float)$invoice['amount'], 2); ?>">
                                            ₱ ••••••••
                                        </td>
                                        <td class="p-4 font-medium text-green-600 amount-value"
                                            data-value="₱<?php echo number_format((float)$invoice['amount_paid'], 2); ?>">
                                            ₱ ••••••••
                                        </td>
                                        <td class="p-4 font-medium text-red-600 amount-value"
                                            data-value="₱<?php echo number_format((float)$invoice['outstanding_balance'], 2); ?>">
                                            ₱ ••••••••
                                        </td>
                                        <td class="p-4">
                                            <?php
                                            $status_class = match($invoice['status']) {
                                                'Paid' => 'status-approved',
                                                'Overdue' => 'status-rejected',
                                                'Cancelled' => 'status-rejected',
                                                default => 'status-pending'
                                            };
                                            ?>
                                            <span class="status-badge <?php echo $status_class; ?>">
                                                <?php echo htmlspecialchars($invoice['status']); ?>
                                            </span>
                                        </td>
                                        <td class="p-4">
                                            <div class="flex flex-wrap gap-2">
                                                <button class="action-btn edit" title="Edit Invoice" onclick="editInvoice(<?php echo $invoice['id']; ?>)">
                                                    <i class='bx bx-edit mr-1'></i>Edit
                                                </button>
                                                <?php if ($invoice['outstanding_balance'] > 0 && $invoice['status'] !== 'Cancelled'): ?>
                                                <button class="action-btn pay" title="Record Payment" onclick="recordPayment(<?php echo $invoice['id']; ?>)">
                                                    <i class='bx bx-credit-card mr-1'></i>Pay
                                                </button>
                                                <?php endif; ?>
                                                <button class="action-btn delete" title="Delete Invoice" onclick="deleteInvoice(<?php echo $invoice['id']; ?>, '<?php echo htmlspecialchars($invoice['invoice_number']); ?>')">
                                                    <i class='bx bx-trash mr-1'></i>Delete
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="10" class="p-8 text-center text-gray-500">
                                            <i class='bx bx-folder-open text-3xl mb-2 text-gray-300'></i>
                                            <div>No invoices found.</div>
                                            <button class="text-brand-primary hover:underline mt-2" onclick="openInvoiceModal()">Create your first invoice</button>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Quick Stats & Actions -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                        <h3 class="text-lg font-bold text-gray-800 mb-4">Invoice Summary</h3>
                        <div class="space-y-3">
                            <div class="flex justify-between items-center">
                                <span class="text-gray-600">Total Invoices:</span>
                                <span class="font-bold text-gray-800">
                                    <?php echo formatCount($total_invoices); ?>
                                </span>
                            </div>
                            <div class="flex justify-between items-center">
                                <span class="text-gray-600">Total Amount Paid:</span>
                                <span class="font-bold text-green-600 amount-value"
                                      data-value="₱<?php echo number_format((float)$paid_this_month, 2); ?>">
                                    ₱ ••••••••
                                </span>
                            </div>
                            <div class="flex justify-between items-center">
                                <span class="text-gray-600">Total Outstanding:</span>
                                <span class="font-bold text-red-600 amount-value"
                                      data-value="₱<?php echo number_format((float)$outstanding_total, 2); ?>">
                                    ₱ ••••••••
                                </span>
                            </div>
                            <div class="flex justify-between items-center">
                                <span class="text-gray-600">Overdue Invoices:</span>
                                <span class="font-bold text-red-600">
                                    <?php echo formatCount($overdue_count); ?>
                                </span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                        <h3 class="text-lg font-bold text-gray-800 mb-4">Quick Actions</h3>
                        <div class="space-y-3">
                            <button class="w-full px-4 py-3 bg-brand-primary text-white rounded-lg hover:bg-brand-primary-hover transition flex items-center justify-between" onclick="openInvoiceModal()">
                                <span class="flex items-center gap-3">
                                    <i class='bx bx-plus'></i>
                                    Create New Invoice
                                </span>
                                <i class='bx bx-chevron-right'></i>
                            </button>
                            <button class="w-full px-4 py-3 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition flex items-center justify-between">
                                <span class="flex items-center gap-3">
                                    <i class='bx bx-import'></i>
                                    Import Invoices
                                </span>
                                <i class='bx bx-chevron-right'></i>
                            </button>
                            <button class="w-full px-4 py-3 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition flex items-center justify-between">
                                <span class="flex items-center gap-3">
                                    <i class='bx bx-export'></i>
                                    Export Invoices
                                </span>
                                <i class='bx bx-chevron-right'></i>
                            </button>
                            <button class="w-full px-4 py-3 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition flex items-center justify-between">
                                <span class="flex items-center gap-3">
                                    <i class='bx bx-envelope'></i>
                                    Send Reminders
                                </span>
                                <i class='bx bx-chevron-right'></i>
                            </button>
                        </div>
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
                <!-- Notifications will be loaded via JavaScript -->
            </div>
        </div>
    </div>

    <!-- Modal for Add/Edit Invoice -->
    <div id="invoice-modal" class="modal">
        <div class="modal-content">
            <div class="flex justify-between items-center mb-6">
                <h2 class="text-xl font-bold text-gray-800" id="invoice-modal-title">Create New Invoice</h2>
                <button class="close-modal text-gray-400 hover:text-gray-600 text-2xl">&times;</button>
            </div>
            <form id="invoice-form" method="POST">
                <input type="hidden" name="action" id="invoice-action" value="add_invoice">
                <input type="hidden" name="invoice_id" id="invoice-id">
                <input type="hidden" name="csrf_token" id="csrf-token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Invoice Number *</label>
                        <input type="text" name="invoice_number" id="invoice_number" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-brand-primary focus:border-transparent" required readonly>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Invoice Type *</label>
                        <select name="type" id="invoice-type" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-brand-primary focus:border-transparent" required onchange="updateInvoiceNumber()">
                            <option value="">Select Type</option>
                            <option value="Receivable">Accounts Receivable (Customer)</option>
                            <option value="Payable">Accounts Payable (Vendor)</option>
                        </select>
                    </div>
                </div>
                
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Contact *</label>
                    <select name="contact_id" id="contact-id" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-brand-primary focus:border-transparent" required>
                        <option value="">Select Contact</option>
                    </select>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Issue Date *</label>
                        <input type="date" name="issue_date" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-brand-primary focus:border-transparent" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Due Date *</label>
                        <input type="date" name="due_date" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-brand-primary focus:border-transparent" required>
                    </div>
                </div>
                
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Amount *</label>
                    <input type="number" name="amount" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-brand-primary focus:border-transparent" step="0.01" min="0" required>
                </div>
                
                <div class="mb-6">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Description</label>
                    <textarea name="description" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-brand-primary focus:border-transparent" rows="3" placeholder="Enter invoice description"></textarea>
                </div>
                
                <div id="status-field" class="mb-4" style="display: none;">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Status</label>
                    <select name="status" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-brand-primary focus:border-transparent">
                        <option value="Pending">Pending</option>
                        <option value="Paid">Paid</option>
                        <option value="Overdue">Overdue</option>
                        <option value="Cancelled">Cancelled</option>
                    </select>
                </div>
                
                <div class="flex gap-3 pt-4">
                    <button type="button" class="flex-1 px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition close-modal">Cancel</button>
                    <button type="submit" class="flex-1 px-4 py-2 bg-brand-primary text-white rounded-lg hover:bg-brand-primary-hover transition">Save Invoice</button>
                </div>
            </form>
        </div>
    </div>

    <script>
    // SINGLE VISIBILITY STATE FOR ALL AMOUNTS IN INVOICES PAGE
    let allAmountsVisible = false;

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

        // Initialize dashboard features
        initializeCommonFeatures();
        initializeAmountVisibility();
        
        // Set due date to 30 days from today by default
        const dueDateInput = document.querySelector('input[name="due_date"]');
        if (dueDateInput && !dueDateInput.value) {
            const today = new Date();
            const dueDate = new Date(today);
            dueDate.setDate(today.getDate() + 30);
            dueDateInput.value = dueDate.toISOString().split('T')[0];
        }
        
        // Pre-fill contact and type when coming from vendors_customers
        const urlParams = new URLSearchParams(window.location.search);
        const contactId = urlParams.get('contact_id');
        const type = urlParams.get('type');
        const fromContact = urlParams.get('from_contact');
        
        if (fromContact && contactId && type) {
            // Auto-open the invoice modal and pre-fill the form
            setTimeout(() => {
                openInvoiceModal();
                
                // Set the type and contact
                document.getElementById('invoice-type').value = type;
                updateInvoiceNumber();
                updateContactOptions(type);
                
                // Set the contact after a short delay to ensure options are loaded
                setTimeout(() => {
                    document.getElementById('contact-id').value = contactId;
                }, 500);
            }, 1000);
        }
    });

    // Initialize all amounts as hidden
    function initializeAmountVisibility() {
        const allAmountElements = document.querySelectorAll('.amount-value, .stat-value');
        allAmountElements.forEach(span => {
            const statType = span.getAttribute('data-stat');
            if (!statType || (statType !== 'total_invoices' && statType !== 'overdue')) {
                span.textContent = '₱ ••••••••';
                span.classList.add('hidden-amount');
            }
        });
    }

    // SINGLE VISIBILITY TOGGLE FOR ALL AMOUNTS
    const amountVisibilityToggle = document.getElementById('amount-visibility-toggle');
    
    function toggleAllAmounts() {
        allAmountsVisible = !allAmountsVisible;
        
        // Toggle ALL amount values on the page
        const allAmountElements = document.querySelectorAll('.amount-value, .stat-value');
        allAmountElements.forEach(span => {
            const statType = span.getAttribute('data-stat');
            // Always show counts, only toggle monetary amounts
            if (!statType || (statType !== 'total_invoices' && statType !== 'overdue')) {
                if (allAmountsVisible) {
                    const actualAmount = span.getAttribute('data-value');
                    span.textContent = actualAmount;
                    span.classList.remove('hidden-amount');
                } else {
                    span.textContent = '₱ ••••••••';
                    span.classList.add('hidden-amount');
                }
            }
        });
        
        // Update toggle icon in header
        const icon = amountVisibilityToggle.querySelector('i');
        if (allAmountsVisible) {
            icon.classList.remove('fa-eye-slash');
            icon.classList.add('fa-eye');
            amountVisibilityToggle.setAttribute('title', 'Hide Amounts');
        } else {
            icon.classList.remove('fa-eye');
            icon.classList.add('fa-eye-slash');
            amountVisibilityToggle.setAttribute('title', 'Show Amounts');
        }
    }
    
    // Add click event to the single toggle button
    if (amountVisibilityToggle) {
        amountVisibilityToggle.addEventListener('click', toggleAllAmounts);
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

    // Invoice functions
    function openInvoiceModal(invoiceId = null) {
        const modal = document.getElementById('invoice-modal');
        const title = document.getElementById('invoice-modal-title');
        const action = document.getElementById('invoice-action');
        const invoiceIdInput = document.getElementById('invoice-id');
        const statusField = document.getElementById('status-field');
        const invoiceForm = document.getElementById('invoice-form');
        
        if (invoiceId) {
            title.textContent = 'Edit Invoice';
            action.value = 'update_invoice';
            invoiceIdInput.value = invoiceId;
            statusField.style.display = 'block';
            
            // Reset form first
            invoiceForm.reset();
            
            // Load invoice data via AJAX
            fetch(`?get_invoice_details=1&invoice_id=${invoiceId}`)
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }
                    return response.json();
                })
                .then(invoice => {
                    if (invoice && invoice.id) {
                        document.getElementById('invoice_number').value = invoice.invoice_number || '';
                        document.getElementById('invoice-type').value = invoice.type || '';
                        document.querySelector('input[name="issue_date"]').value = invoice.issue_date || '';
                        document.querySelector('input[name="due_date"]').value = invoice.due_date || '';
                        document.querySelector('input[name="amount"]').value = invoice.amount || 0;
                        document.querySelector('textarea[name="description"]').value = invoice.description || '';
                        document.querySelector('select[name="status"]').value = invoice.status || 'Pending';
                        
                        updateContactOptions(invoice.type || '');
                        setTimeout(() => {
                            document.getElementById('contact-id').value = invoice.contact_id || '';
                        }, 100);
                    } else {
                        alert('Error: Invoice not found');
                        modal.style.display = 'none';
                    }
                })
                .catch(error => {
                    console.error('Error loading invoice details:', error);
                    alert('Error loading invoice details');
                    modal.style.display = 'none';
                });
        } else {
            title.textContent = 'Create New Invoice';
            action.value = 'add_invoice';
            invoiceIdInput.value = '';
            statusField.style.display = 'none';
            invoiceForm.reset();
            updateInvoiceNumber();
            
            // Set due date to 30 days from today
            const today = new Date();
            const dueDate = new Date(today);
            dueDate.setDate(today.getDate() + 30);
            document.querySelector('input[name="due_date"]').value = dueDate.toISOString().split('T')[0];
        }
        
        modal.style.display = 'block';
    }

    function updateInvoiceNumber() {
        const type = document.getElementById('invoice-type').value;
        const numberField = document.getElementById('invoice_number');
        
        if (type) {
            const prefix = type === 'Receivable' ? 'INV' : 'V-INV';
            const year = new Date().getFullYear();
            
            // Generate a random 3-digit number
            const random = Math.floor(Math.random() * 1000).toString().padStart(3, '0');
            numberField.value = `${prefix}-${year}-${random}`;
        } else {
            numberField.value = '';
        }
        
        updateContactOptions(type);
    }

    function updateContactOptions(type) {
        const contactSelect = document.getElementById('contact-id');
        contactSelect.innerHTML = '<option value="">Select Contact</option>';
        
        <?php if (count($customers) > 0): ?>
        if (type === 'Receivable') {
            <?php foreach ($customers as $customer): ?>
            contactSelect.innerHTML += `<option value="<?php echo $customer['contact_id']; ?>"><?php echo htmlspecialchars($customer['name'] . ' (' . $customer['contact_id'] . ')'); ?></option>`;
            <?php endforeach; ?>
        }
        <?php endif; ?>
        
        <?php if (count($vendors) > 0): ?>
        if (type === 'Payable') {
            <?php foreach ($vendors as $vendor): ?>
            contactSelect.innerHTML += `<option value="<?php echo $vendor['contact_id']; ?>"><?php echo htmlspecialchars($vendor['name'] . ' (' . $vendor['contact_id'] . ')'); ?></option>`;
            <?php endforeach; ?>
        }
        <?php endif; ?>
        
        <?php if (count($all_contacts) > 0): ?>
        if (!type) {
            <?php foreach ($all_contacts as $contact): ?>
            contactSelect.innerHTML += `<option value="<?php echo $contact['contact_id']; ?>"><?php echo htmlspecialchars($contact['name'] . ' (' . $contact['contact_id'] . ') - ' . $contact['type']); ?></option>`;
            <?php endforeach; ?>
        }
        <?php endif; ?>
    }

    function editInvoice(invoiceId) {
        openInvoiceModal(invoiceId);
    }

    function recordPayment(invoiceId) {
        // Redirect to payment entry page
        window.location.href = `payment_entry.php?invoice_id=${invoiceId}&from_invoice=1`;
    }

    function deleteInvoice(invoiceId, invoiceNumber) {
        if (confirm(`Are you sure you want to delete invoice "${invoiceNumber}"? This action cannot be undone.`)) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.style.display = 'none';
            
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'delete_invoice';
            form.appendChild(actionInput);
            
            const invoiceIdInput = document.createElement('input');
            invoiceIdInput.type = 'hidden';
            invoiceIdInput.name = 'invoice_id';
            invoiceIdInput.value = invoiceId;
            form.appendChild(invoiceIdInput);

            const csrfInput = document.createElement('input');
            csrfInput.type = 'hidden';
            csrfInput.name = 'csrf_token';
            csrfInput.value = document.getElementById('csrf-token').value;
            form.appendChild(csrfInput);
            
            document.body.appendChild(form);
            form.submit();
        }
    }
    
    // Add form submit handler for invoice form
    document.addEventListener('DOMContentLoaded', function() {
        const invoiceForm = document.getElementById('invoice-form');
        if (invoiceForm) {
            invoiceForm.addEventListener('submit', function(e) {
                e.preventDefault();
                
                const formData = new FormData(this);
                const action = formData.get('action');
                const invoiceId = formData.get('invoice_id');
                
                // Validate required fields
                const requiredFields = ['invoice_number', 'type', 'contact_id', 'issue_date', 'due_date', 'amount'];
                let isValid = true;
                let errorMessage = '';
                
                requiredFields.forEach(field => {
                    const value = formData.get(field);
                    if (!value || value.trim() === '') {
                        isValid = false;
                        errorMessage += `${field.replace('_', ' ')} is required\n`;
                    }
                });
                
                if (!isValid) {
                    alert(errorMessage);
                    return;
                }
                
                // Submit the form
                this.submit();
            });
        }
    });
    </script>
</body>
</html>