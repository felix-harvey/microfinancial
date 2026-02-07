<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/database.php';

// Initialize session notifications if not set
if (!isset($_SESSION['ap_ar_notifications'])) {
    $_SESSION['ap_ar_notifications'] = [];
}

// Handle mark as read notifications request
if (isset($_GET['mark_notifications_read'])) {
    // Mark all notifications as read
    foreach ($_SESSION['ap_ar_notifications'] as &$notification) {
        $notification['read'] = true;
    }
    echo json_encode(['success' => true]);
    exit;
}

// Enhanced error handling
error_reporting(E_ALL);
ini_set('display_errors', '1');



try {
    $database = new Database();
    $db = $database->getConnection();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log("Database connection error: " . $e->getMessage());
    http_response_code(500);
    echo "Database connection error. Please try again later.";
    exit;
}

// Enhanced authentication check
if (empty($_SESSION['user_id']) || !is_numeric($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$user_id = (int)$_SESSION['user_id'];

// Enhanced logout functionality
if (isset($_GET['logout']) && $_GET['logout'] === 'true') {
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
    header("Location: index.php");
    exit;
}

// Enhanced user loading with better error handling
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

// Function to add a notification when contact is created
function addContactNotification($contact_name, $contact_type) {
    $notification = [
        'id' => uniqid(),
        'type' => 'contact',
        'message' => "New {$contact_type} contact added: {$contact_name}",
        'timestamp' => time(),
        'link' => 'vendors_customers.php',
        'read' => false
    ];
    
    // Add to beginning of array (newest first)
    array_unshift($_SESSION['ap_ar_notifications'], $notification);
    
    // Keep only last 10 notifications
    $_SESSION['ap_ar_notifications'] = array_slice($_SESSION['ap_ar_notifications'], 0, 10);
}

// Function to get unread notification count - AP/AR only (session-based)
function getUnreadNotificationCount(): int {
    $unread_count = 0;
    foreach ($_SESSION['ap_ar_notifications'] as $notification) {
        if (!$notification['read']) {
            $unread_count++;
        }
    }
    return $unread_count;
}

// Function to get notifications - AP/AR only (session-based)
function getNotifications(): array {
    return $_SESSION['ap_ar_notifications'];
}

// Function to mark all notifications as read
function markAllNotificationsAsRead() {
    foreach ($_SESSION['ap_ar_notifications'] as &$notification) {
        $notification['read'] = true;
    }
}

// Function to format numbers with dots if hidden
function formatNumber($number, $show_numbers = false) {
    if ($show_numbers) {
        return '₱' . number_format((float)$number, 2);
    } else {
        return '₱ ••••••••';
    }
}

// FIXED: Vendor data based on INVOICES table only (Compatible with your Automation)
function getVendors(PDO $db): array {
    $sql = "SELECT 
                bc.*,
                -- 1. Net Balance (Utang natin): Total Invoices - Total Paid
                -- Negative result means we owe money
                (
                    SELECT COALESCE(SUM(paid_amount), 0) - COALESCE(SUM(amount), 0)
                    FROM invoices 
                    WHERE contact_id = bc.id AND type = 'Payable'
                ) as net_balance,
                
                -- 2. Outstanding Balance: Sum of unpaid portion
                (
                    SELECT COALESCE(SUM(amount - paid_amount), 0)
                    FROM invoices 
                    WHERE contact_id = bc.id AND type = 'Payable' AND status != 'Paid'
                ) as outstanding_balance,
                
                -- 3. Total Invoices Count
                (SELECT COUNT(*) FROM invoices WHERE contact_id = bc.id AND type = 'Payable') as total_invoices,
                
                -- 4. Total Paid Amount (Galing sa Invoice paid_amount, hindi sa payments table)
                (SELECT COALESCE(SUM(paid_amount), 0) FROM invoices WHERE contact_id = bc.id AND type = 'Payable') as total_payments,
                
                -- 5. Payment Count (Bilang ng invoices na may bayad na)
                (SELECT COUNT(*) FROM invoices WHERE contact_id = bc.id AND type = 'Payable' AND paid_amount > 0) as payment_count

            FROM business_contacts bc
            WHERE bc.status = 'Active' AND bc.type = 'Vendor'
            ORDER BY bc.name";
    
    try {
        return $db->query($sql)->fetchAll();
    } catch (PDOException $e) {
        error_log("Error fetching vendors: " . $e->getMessage());
        return [];
    }
}

// FIXED: Customer data based on INVOICES table only (Compatible with Lending Automation)
function getCustomers(PDO $db): array {
    $sql = "SELECT 
                bc.*,
                -- 1. Net Balance (Utang nila satin): Total Invoiced - Total Paid
                -- Positive result means they owe us money
                (
                    SELECT COALESCE(SUM(amount), 0) - COALESCE(SUM(paid_amount), 0)
                    FROM invoices 
                    WHERE contact_id = bc.id AND type = 'Receivable'
                ) as net_balance,
                
                -- 2. Outstanding Balance
                (
                    SELECT COALESCE(SUM(amount - paid_amount), 0)
                    FROM invoices 
                    WHERE contact_id = bc.id AND type = 'Receivable' AND status != 'Paid'
                ) as outstanding_balance,
                
                -- 3. Total Invoices/Loans Count
                (SELECT COUNT(*) FROM invoices WHERE contact_id = bc.id AND type = 'Receivable') as total_invoices,
                
                -- 4. Total Collected (Paid Amount)
                (SELECT COALESCE(SUM(paid_amount), 0) FROM invoices WHERE contact_id = bc.id AND type = 'Receivable') as total_payments,
                
                -- 5. Collection Count
                (SELECT COUNT(*) FROM invoices WHERE contact_id = bc.id AND type = 'Receivable' AND paid_amount > 0) as payment_count

            FROM business_contacts bc
            WHERE bc.status = 'Active' AND bc.type = 'Customer'
            ORDER BY bc.name";
    
    try {
        return $db->query($sql)->fetchAll();
    } catch (PDOException $e) {
        error_log("Error fetching customers: " . $e->getMessage());
        return [];
    }
}

// Enhanced ID generation function
function generateContactId(PDO $db, string $type): string {
    $prefix = $type === 'Vendor' ? 'AP-' : 'AR-';
    
    try {
        // Get the highest existing number for better sequence
        $sql = "SELECT MAX(CAST(SUBSTRING(contact_id, 4) AS UNSIGNED)) as max_num 
                FROM business_contacts 
                WHERE type = ? AND contact_id LIKE ?";
        $stmt = $db->prepare($sql);
        $stmt->execute([$type, $prefix . '%']);
        $result = $stmt->fetch();
        
        $next_num = ($result['max_num'] ?? 0) + 1;
        return $prefix . str_pad((string)$next_num, 3, '0', STR_PAD_LEFT);
    } catch (PDOException $e) {
        error_log("Error generating contact ID: " . $e->getMessage());
        // Fallback to count-based ID with prepared statement
        $count_sql = "SELECT COUNT(*) as count FROM business_contacts WHERE type = ?";
        $count_stmt = $db->prepare($count_sql);
        $count_stmt->execute([$type]);
        $count = $count_stmt->fetch()['count'];
        $next_num = (int)$count + 1;
        return $prefix . str_pad((string)$next_num, 3, '0', STR_PAD_LEFT);
    }
}

// Enhanced input validation function
function validateContactInput(array $data): array {
    $errors = [];
    
    $name = trim($data['company_name'] ?? '');
    $contact_person = trim($data['contact_person'] ?? '');
    $email = trim($data['email'] ?? '');
    $phone = trim($data['phone'] ?? '');
    
    if (empty($name) || strlen($name) < 2) {
        $errors[] = "Company name must be at least 2 characters long";
    }
    
    if (empty($contact_person) || strlen($contact_person) < 2) {
        $errors[] = "Contact person must be at least 2 characters long";
    }
    
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Valid email address is required";
    }
    
    if (!empty($phone) && !preg_match('/^[\d\s\-\+\(\)]{10,}$/', $phone)) {
        $errors[] = "Phone number format is invalid";
    }
    
    return $errors;
}

// Generate CSRF token for security
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Enhanced form submission handling
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // CSRF protection
    if (empty($_SESSION['csrf_token']) || empty($_POST['csrf_token']) || 
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['error'] = "Security validation failed";
        header("Location: vendors_customers.php");
        exit;
    }
    
    if ($action === 'add_vendor' || $action === 'update_vendor') {
        $vendor_id = (int)($_POST['vendor_id'] ?? 0);
        $name = trim($_POST['company_name'] ?? '');
        $contact_person = trim($_POST['contact_person'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        
        // Enhanced validation
        $validation_errors = validateContactInput($_POST);
        if (!empty($validation_errors)) {
            $_SESSION['error'] = implode("<br>", $validation_errors);
            header("Location: vendors_customers.php");
            exit;
        }
        
        try {
            if ($action === 'add_vendor') {
                $contact_id = generateContactId($db, 'Vendor');
                
                $sql = "INSERT INTO business_contacts (contact_id, name, contact_person, email, phone, type, status) 
                        VALUES (?, ?, ?, ?, ?, 'Vendor', 'Active')";
                $stmt = $db->prepare($sql);
                $stmt->execute([$contact_id, $name, $contact_person, $email, $phone]);
                
                // Add notification for new AP contact
                addContactNotification($name, 'Accounts Payable');
                
                $_SESSION['success'] = "Accounts Payable contact added successfully!";
                
            } else { // update_vendor
                if ($vendor_id === 0) {
                    throw new Exception("Invalid contact ID");
                }
                
                $sql = "UPDATE business_contacts SET name = ?, contact_person = ?, email = ?, phone = ? 
                        WHERE id = ? AND type = 'Vendor'";
                $stmt = $db->prepare($sql);
                $stmt->execute([$name, $contact_person, $email, $phone, $vendor_id]);
                
                $_SESSION['success'] = "Accounts Payable contact updated successfully!";
            }
            
            header("Location: vendors_customers.php");
            exit;
            
        } catch (PDOException $e) {
            error_log("Vendor operation error: " . $e->getMessage());
            $_SESSION['error'] = "Error processing contact: " . $e->getMessage();
            header("Location: vendors_customers.php");
            exit;
        } catch (Exception $e) {
            error_log("Vendor validation error: " . $e->getMessage());
            $_SESSION['error'] = $e->getMessage();
            header("Location: vendors_customers.php");
            exit;
        }
    }
    
    if ($action === 'add_customer' || $action === 'update_customer') {
        $customer_id = (int)($_POST['customer_id'] ?? 0);
        $name = trim($_POST['company_name'] ?? '');
        $contact_person = trim($_POST['contact_person'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        
        // Enhanced validation
        $validation_errors = validateContactInput($_POST);
        if (!empty($validation_errors)) {
            $_SESSION['error'] = implode("<br>", $validation_errors);
            header("Location: vendors_customers.php");
            exit;
        }
        
        try {
            if ($action === 'add_customer') {
                $contact_id = generateContactId($db, 'Customer');
                
                $sql = "INSERT INTO business_contacts (contact_id, name, contact_person, email, phone, type, status) 
                        VALUES (?, ?, ?, ?, ?, 'Customer', 'Active')";
                $stmt = $db->prepare($sql);
                $stmt->execute([$contact_id, $name, $contact_person, $email, $phone]);
                
                // Add notification for new AR contact
                addContactNotification($name, 'Accounts Receivable');
                
                $_SESSION['success'] = "Accounts Receivable contact added successfully!";
                
            } else { // update_customer
                if ($customer_id === 0) {
                    throw new Exception("Invalid contact ID");
                }
                
                $sql = "UPDATE business_contacts SET name = ?, contact_person = ?, email = ?, phone = ? 
                        WHERE id = ? AND type = 'Customer'";
                $stmt = $db->prepare($sql);
                $stmt->execute([$name, $contact_person, $email, $phone, $customer_id]);
                
                $_SESSION['success'] = "Accounts Receivable contact updated successfully!";
            }
            
            header("Location: vendors_customers.php");
            exit;
            
        } catch (PDOException $e) {
            error_log("Customer operation error: " . $e->getMessage());
            $_SESSION['error'] = "Error processing contact: " . $e->getMessage();
            header("Location: vendors_customers.php");
            exit;
        } catch (Exception $e) {
            error_log("Customer validation error: " . $e->getMessage());
            $_SESSION['error'] = $e->getMessage();
            header("Location: vendors_customers.php");
            exit;
        }
    }
    
    if ($action === 'delete_contact') {
        $contact_id = (int)($_POST['contact_id'] ?? 0);
        $contact_type = $_POST['contact_type'] ?? '';
        
        if ($contact_id === 0 || !in_array($contact_type, ['Vendor', 'Customer'])) {
            $_SESSION['error'] = "Invalid contact data";
            header("Location: vendors_customers.php");
            exit;
        }
        
        try {
            // Check if contact has outstanding invoices before deletion
            $check_sql = "SELECT COUNT(*) as invoice_count FROM invoices 
                         WHERE contact_id = ? AND status != 'Paid'";
            $check_stmt = $db->prepare($check_sql);
            $check_stmt->execute([$contact_id]);
            $result = $check_stmt->fetch();
            
            if ($result['invoice_count'] > 0) {
                $_SESSION['error'] = "Cannot delete contact with outstanding invoices. Please resolve invoices first.";
                header("Location: vendors_customers.php");
                exit;
            }
            
            $sql = "UPDATE business_contacts SET status = 'Inactive' WHERE id = ?";
            $stmt = $db->prepare($sql);
            $stmt->execute([$contact_id]);
            
            $success_message = $contact_type === 'Vendor' ? 'Accounts Payable contact deleted successfully!' : 'Accounts Receivable contact deleted successfully!';
            $_SESSION['success'] = $success_message;
            header("Location: vendors_customers.php");
            exit;
            
        } catch (PDOException $e) {
            error_log("Contact deletion error: " . $e->getMessage());
            $_SESSION['error'] = "Error deleting contact: " . $e->getMessage();
            header("Location: vendors_customers.php");
            exit;
        }
    }
}

$vendors = getVendors($db);
$customers = getCustomers($db);

// FIXED: Calculate total balances with CORRECTED logic
$total_ap_balance = 0;
$total_ar_balance = 0;

foreach ($vendors as $vendor) {
    // AP: Should be NEGATIVE (you owe vendors)
    $total_ap_balance += (float)$vendor['net_balance'];
}

foreach ($customers as $customer) {
    // AR: Should be POSITIVE (customers owe you) - use absolute value for display
    $total_ar_balance += abs((float)$customer['net_balance']);
}

// Get notifications
$notification_count = getUnreadNotificationCount();
$notifications = getNotifications();

// Check for success/error messages from session
$success_message = $_SESSION['success'] ?? '';
$error_message = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);

// Load notifications from database (like dashboard8.php)
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

$dashboard_notifications = loadNotificationsFromDatabase($db, $user_id);
$unreadCount = 0;
foreach($dashboard_notifications as $n) { if(!$n['read']) $unreadCount++; }
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Accounts Payable & Receivable - Financial Dashboard</title>

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
        
        .ap-balance-amount, .ar-balance-amount, .table-amount-value {
            transition: all 0.3s ease;
        }
        
        /* Para sa table amounts */
        .table-amount-value.hidden-amount {
            font-size: 0.95em;
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

        .action-btn.record {
            background-color: #EFF6FF;
            color: #1D4ED8;
            border-color: #1D4ED8;
        }
        
        .action-btn.record:hover {
            background-color: #1D4ED8;
            color: white;
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
                            <a href="vendors_customers.php" class="block px-3 py-1.5 rounded-lg text-xs bg-emerald-50 text-brand-primary font-medium border border-emerald-100 hover:bg-emerald-100 hover:border-emerald-200 transition-all duration-200 hover:translate-x-1">
                                <span class="flex items-center justify-between">
                                    Payable/Receivable
                                    <span class="inline-flex w-1.5 h-1.5 rounded-full bg-emerald-400"></span>
                                </span>
                            </a>
                            <a href="invoices.php" class="block px-3 py-1.5 rounded-lg text-xs text-gray-600 hover:bg-green-50 hover:text-brand-primary transition-all duration-200 hover:translate-x-1">
                                Invoices
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
                        Accounts Payable & Receivable
                    </h1>
                    <p class="text-xs text-gray-500">
                        Manage accounts payable and accounts receivable contacts
                    </p>
                </div>
            </div>

            <div class="flex items-center gap-3 sm:gap-5">
                <!-- Real-time Clock -->
                <span id="real-time-clock"
                    class="text-xs font-bold text-gray-700 bg-gray-50 px-3 py-2 rounded-lg border border-gray-200">
                    --:--:--
                </span>

                <!-- SINGLE VISIBILITY TOGGLE FOR ALL AMOUNTS IN AP/AR PAGE -->
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
                    <div class="stat-card rounded-xl p-6">
                        <div class="flex items-center gap-4">
                            <div class="p-3 rounded-lg bg-blue-100">
                                <i class='bx bx-building text-blue-600 text-2xl'></i>
                            </div>
                            <div class="flex-1">
                                <div class="flex justify-between items-start">
                                    <div>
                                        <p class="text-sm text-gray-500">Total AP</p>
                                        <p class="text-2xl font-bold text-gray-800">
                                            <?php echo count($vendors); ?>
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="stat-card rounded-xl p-6">
                        <div class="flex items-center gap-4">
                            <div class="p-3 rounded-lg bg-purple-100">
                                <i class='bx bx-group text-purple-600 text-2xl'></i>
                            </div>
                            <div class="flex-1">
                                <div class="flex justify-between items-start">
                                    <div>
                                        <p class="text-sm text-gray-500">Total AR</p>
                                        <p class="text-2xl font-bold text-gray-800">
                                            <?php echo count($customers); ?>
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="stat-card rounded-xl p-6">
                        <div class="flex items-center gap-4">
                            <div class="p-3 rounded-lg bg-blue-100">
                                <i class='bx bx-credit-card text-blue-600 text-2xl'></i>
                            </div>
                            <div class="flex-1">
                                <div class="flex justify-between items-start">
                                    <div>
                                        <p class="text-sm text-gray-500">AP Balance</p>
                                        <p class="text-2xl font-bold text-gray-800 ap-balance-amount" 
                                           data-value="₱<?php echo number_format(abs($total_ap_balance), 2); ?>">
                                            ₱ ••••••••
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="stat-card rounded-xl p-6">
                        <div class="flex items-center gap-4">
                            <div class="p-3 rounded-lg bg-green-100">
                                <i class='bx bx-money text-green-600 text-2xl'></i>
                            </div>
                            <div class="flex-1">
                                <div class="flex justify-between items-start">
                                    <div>
                                        <p class="text-sm text-gray-500">AR Balance</p>
                                        <p class="text-2xl font-bold text-gray-800 ar-balance-amount" 
                                           data-value="₱<?php echo number_format($total_ar_balance, 2); ?>">
                                            ₱ ••••••••
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Search Section -->
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                        <div class="flex-1">
                            <div class="relative">
                                <input type="text" id="search-contacts" placeholder="Search contacts by company name, contact person, email, or ID..." 
                                       class="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-brand-primary focus:border-transparent">
                                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                    <i class='bx bx-search text-gray-400'></i>
                                </div>
                            </div>
                            
                        </div>
                        <div class="flex space-x-2">
                            <button id="clear-search" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition flex items-center gap-2">
                                <i class='bx bx-reset'></i>Clear
                            </button>
                            <div class="relative">
                                <select id="filter-balance" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-brand-primary focus:border-transparent">
                                    <option value="">All Balances</option>
                                    <option value="positive">Positive Balance</option>
                                    <option value="negative">Negative Balance</option>
                                    <option value="zero">Zero Balance</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div id="search-results-info" class="mt-3 text-sm text-gray-600 hidden">
                        <span id="results-count">0</span> contacts found
                    </div>
                </div>

                <!-- Tabs Container -->
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                    <div class="border-b border-gray-100">
                        <div class="flex">
                            <button class="tab-button px-6 py-4 font-medium text-gray-600 hover:text-brand-primary border-b-2 border-transparent hover:border-brand-primary transition active border-brand-primary text-brand-primary" data-tab="vendors">
                                Accounts Payable
                            </button>
                            <button class="tab-button px-6 py-4 font-medium text-gray-600 hover:text-brand-primary border-b-2 border-transparent hover:border-brand-primary transition" data-tab="customers">
                                Accounts Receivable
                            </button>
                        </div>
                    </div>

                    <div class="p-6">
                        <!-- Accounts Payable Tab -->
                        <div class="tab-content active" id="vendors-content">
                            <div class="flex justify-between items-center mb-6">
                                <h3 class="text-lg font-bold text-gray-800">Accounts Payable Management</h3>
                                <button class="px-4 py-2 bg-brand-primary text-white rounded-lg hover:bg-brand-primary-hover transition flex items-center gap-2" onclick="openVendorModal()">
                                    <i class='bx bx-plus'></i>Add AP Contact
                                </button>
                            </div>
                            
                            <div class="overflow-x-auto">
                                <table class="w-full">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th class="text-left p-4 text-sm font-medium text-gray-500">Contact ID</th>
                                            <th class="text-left p-4 text-sm font-medium text-gray-500">Company Name</th>
                                            <th class="text-left p-4 text-sm font-medium text-gray-500">Contact Person</th>
                                            <th class="text-left p-4 text-sm font-medium text-gray-500">Email</th>
                                            <th class="text-left p-4 text-sm font-medium text-gray-500">Phone</th>
                                            <th class="text-left p-4 text-sm font-medium text-gray-500">Net Balance</th>
                                            <th class="text-left p-4 text-sm font-medium text-gray-500">Total Payments</th>
                                            <th class="text-left p-4 text-sm font-medium text-gray-500">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody id="vendors-table-body">
                                        <?php if (count($vendors) > 0): ?>
                                            <?php foreach ($vendors as $vendor): ?>
                                            <tr class="transaction-row">
                                                <td class="p-4 font-mono font-medium"><?php echo htmlspecialchars($vendor['contact_id']); ?></td>
                                                <td class="p-4 font-medium"><?php echo htmlspecialchars($vendor['name']); ?></td>
                                                <td class="p-4"><?php echo htmlspecialchars($vendor['contact_person']); ?></td>
                                                <td class="p-4"><?php echo htmlspecialchars($vendor['email']); ?></td>
                                                <td class="p-4"><?php echo htmlspecialchars($vendor['phone']); ?></td>
                                                <td class="p-4 font-medium <?php echo (float)$vendor['net_balance'] < 0 ? 'text-red-600' : 'text-green-600'; ?>">
                                                    <span class="amount-value" data-value="₱<?php echo number_format(abs((float)$vendor['net_balance']), 2); ?>">
                                                        ₱ ••••••••
                                                    </span>
                                                </td>
                                                <td class="p-4">
                                                    <span class="amount-value" data-value="₱<?php echo number_format((float)$vendor['total_payments'], 2); ?>">
                                                        ₱ ••••••••
                                                    </span>
                                                    <div class="text-xs text-gray-500"><?php echo $vendor['payment_count']; ?> payments</div>
                                                </td>
                                                <td class="p-4">
                                                    <div class="flex flex-wrap gap-2">
                                                        <button class="action-btn record" title="Record Invoice" onclick="recordInvoiceForContact(<?php echo $vendor['id']; ?>, 'Vendor')">
                                                            <i class='bx bx-receipt mr-1'></i>Record
                                                        </button>
                                                        <button class="action-btn edit" title="Edit Contact" onclick="editVendor(<?php echo $vendor['id']; ?>)">
                                                            <i class='bx bx-edit mr-1'></i>Edit
                                                        </button>
                                                        <button class="action-btn delete" title="Delete Contact" onclick="deleteVendor(<?php echo $vendor['id']; ?>, '<?php echo htmlspecialchars($vendor['name']); ?>')">
                                                            <i class='bx bx-trash mr-1'></i>Delete
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="8" class="text-center py-8 text-gray-500">
                                                    <i class='bx bx-folder-open text-3xl mb-2 text-gray-300'></i>
                                                    <div>No accounts payable contacts found.</div>
                                                    <button class="text-brand-primary hover:underline mt-2" onclick="openVendorModal()">Add your first AP contact</button>
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- Accounts Receivable Tab -->
                        <div class="tab-content hidden" id="customers-content">
                            <div class="flex justify-between items-center mb-6">
                                <h3 class="text-lg font-bold text-gray-800">Accounts Receivable Management</h3>
                                <button class="px-4 py-2 bg-brand-primary text-white rounded-lg hover:bg-brand-primary-hover transition flex items-center gap-2" onclick="openCustomerModal()">
                                    <i class='bx bx-plus'></i>Add AR Contact
                                </button>
                            </div>
                            
                            <div class="overflow-x-auto">
                                <table class="w-full">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th class="text-left p-4 text-sm font-medium text-gray-500">Contact ID</th>
                                            <th class="text-left p-4 text-sm font-medium text-gray-500">Company Name</th>
                                            <th class="text-left p-4 text-sm font-medium text-gray-500">Contact Person</th>
                                            <th class="text-left p-4 text-sm font-medium text-gray-500">Email</th>
                                            <th class="text-left p-4 text-sm font-medium text-gray-500">Phone</th>
                                            <th class="text-left p-4 text-sm font-medium text-gray-500">Net Balance</th>
                                            <th class="text-left p-4 text-sm font-medium text-gray-500">Total Payments</th>
                                            <th class="text-left p-4 text-sm font-medium text-gray-500">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody id="customers-table-body">
                                        <?php if (count($customers) > 0): ?>
                                            <?php foreach ($customers as $customer): ?>
                                            <tr class="transaction-row">
                                                <td class="p-4 font-mono font-medium"><?php echo htmlspecialchars($customer['contact_id']); ?></td>
                                                <td class="p-4 font-medium"><?php echo htmlspecialchars($customer['name']); ?></td>
                                                <td class="p-4"><?php echo htmlspecialchars($customer['contact_person']); ?></td>
                                                <td class="p-4"><?php echo htmlspecialchars($customer['email']); ?></td>
                                                <td class="p-4"><?php echo htmlspecialchars($customer['phone']); ?></td>
                                                <td class="p-4 font-medium <?php echo (float)$customer['net_balance'] < 0 ? 'text-red-600' : 'text-green-600'; ?>">
                                                    <span class="amount-value" data-value="₱<?php echo number_format(abs((float)$customer['net_balance']), 2); ?>">
                                                        ₱ ••••••••
                                                    </span>
                                                </td>
                                                <td class="p-4">
                                                    <span class="amount-value" data-value="₱<?php echo number_format((float)$customer['total_payments'], 2); ?>">
                                                        ₱ ••••••••
                                                    </span>
                                                    <div class="text-xs text-gray-500"><?php echo $customer['payment_count']; ?> payments</div>
                                                </td>
                                                <td class="p-4">
                                                    <div class="flex flex-wrap gap-2">
                                                        <button class="action-btn record" title="Record Invoice" onclick="recordInvoiceForContact(<?php echo $customer['id']; ?>, 'Customer')">
                                                            <i class='bx bx-receipt mr-1'></i>Record
                                                        </button>
                                                        <button class="action-btn edit" title="Edit Contact" onclick="editCustomer(<?php echo $customer['id']; ?>)">
                                                            <i class='bx bx-edit mr-1'></i>Edit
                                                        </button>
                                                        <button class="action-btn delete" title="Delete Contact" onclick="deleteCustomer(<?php echo $customer['id']; ?>, '<?php echo htmlspecialchars($customer['name']); ?>')">
                                                            <i class='bx bx-trash mr-1'></i>Delete
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="8" class="text-center py-8 text-gray-500">
                                                    <i class='bx bx-folder-open text-3xl mb-2 text-gray-300'></i>
                                                    <div>No accounts receivable contacts found.</div>
                                                    <button class="text-brand-primary hover:underline mt-2" onclick="openCustomerModal()">Add your first AR contact</button>
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                        <h3 class="text-lg font-bold text-gray-800 mb-4">Quick Actions</h3>
                        <div class="space-y-3">
                            <button class="w-full px-4 py-3 bg-brand-primary text-white rounded-lg hover:bg-brand-primary-hover transition flex items-center justify-between" onclick="openVendorModal()">
                                <span class="flex items-center gap-3">
                                    <i class='bx bx-plus'></i>
                                    Add Accounts Payable Contact
                                </span>
                                <i class='bx bx-chevron-right'></i>
                            </button>
                            <button class="w-full px-4 py-3 bg-brand-primary text-white rounded-lg hover:bg-brand-primary-hover transition flex items-center justify-between" onclick="openCustomerModal()">
                                <span class="flex items-center gap-3">
                                    <i class='bx bx-plus'></i>
                                    Add Accounts Receivable Contact
                                </span>
                                <i class='bx bx-chevron-right'></i>
                            </button>
                            <button class="w-full px-4 py-3 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition flex items-center justify-between" onclick="window.location.href='payment_entry.php'">
                                <span class="flex items-center gap-3">
                                    <i class='bx bx-credit-card'></i>
                                    Record Payment
                                </span>
                                <i class='bx bx-chevron-right'></i>
                            </button>
                            <button class="w-full px-4 py-3 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition flex items-center justify-between" onclick="window.location.href='invoices.php'">
                                <span class="flex items-center gap-3">
                                    <i class='bx bx-receipt'></i>
                                    Manage Invoices
                                </span>
                                <i class='bx bx-chevron-right'></i>
                            </button>
                        </div>
                    </div>
                    
                    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                        <h3 class="text-lg font-bold text-gray-800 mb-4">Recent Activity</h3>
                        <div class="space-y-3">
                            <?php
                            // Get recent activity
                            $recent_vendors = array_slice($vendors, 0, 2);
                            $recent_customers = array_slice($customers, 0, 2);
                            ?>
                            
                            <?php foreach ($recent_vendors as $vendor): ?>
                            <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                                <div>
                                    <div class="font-medium text-sm">New AP contact added</div>
                                    <div class="text-xs text-gray-500"><?php echo htmlspecialchars($vendor['name']); ?></div>
                                </div>
                                <div class="text-xs text-gray-500">Recently</div>
                            </div>
                            <?php endforeach; ?>
                            
                            <?php foreach ($recent_customers as $customer): ?>
                            <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                                <div>
                                    <div class="font-medium text-sm">New AR contact added</div>
                                    <div class="text-xs text-gray-500"><?php echo htmlspecialchars($customer['name']); ?></div>
                                </div>
                                <div class="text-xs text-gray-500">Recently</div>
                            </div>
                            <?php endforeach; ?>
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

    <!-- Modal for Add/Edit Accounts Payable -->
    <div id="vendor-modal" class="modal">
        <div class="modal-content">
            <div class="flex justify-between items-center mb-6">
                <h2 class="text-xl font-bold text-gray-800" id="vendor-modal-title">Add New Accounts Payable Contact</h2>
                <button class="close-modal text-gray-400 hover:text-gray-600 text-2xl">&times;</button>
            </div>
            <form id="vendor-form" method="POST">
                <input type="hidden" name="action" id="vendor-action" value="add_vendor">
                <input type="hidden" name="vendor_id" id="vendor-id">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div class="form-group">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Company Name *</label>
                        <input type="text" name="company_name" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-brand-primary focus:border-transparent" required minlength="2">
                    </div>
                    <div class="form-group">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Contact Person *</label>
                        <input type="text" name="contact_person" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-brand-primary focus:border-transparent" required minlength="2">
                    </div>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div class="form-group">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Email *</label>
                        <input type="email" name="email" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-brand-primary focus:border-transparent" required>
                    </div>
                    <div class="form-group">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Phone</label>
                        <input type="tel" name="phone" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-brand-primary focus:border-transparent" pattern="[\d\s\-\+\(\)]{10,}">
                    </div>
                </div>
                
                <div class="flex gap-3 pt-4">
                    <button type="button" class="flex-1 px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition close-modal">Cancel</button>
                    <button type="submit" class="flex-1 px-4 py-2 bg-brand-primary text-white rounded-lg hover:bg-brand-primary-hover transition">Save Contact</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Modal for Add/Edit Accounts Receivable -->
    <div id="customer-modal" class="modal">
        <div class="modal-content">
            <div class="flex justify-between items-center mb-6">
                <h2 class="text-xl font-bold text-gray-800" id="customer-modal-title">Add New Accounts Receivable Contact</h2>
                <button class="close-modal text-gray-400 hover:text-gray-600 text-2xl">&times;</button>
            </div>
            <form id="customer-form" method="POST">
                <input type="hidden" name="action" id="customer-action" value="add_customer">
                <input type="hidden" name="customer_id" id="customer-id">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div class="form-group">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Company Name *</label>
                        <input type="text" name="company_name" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-brand-primary focus:border-transparent" required minlength="2">
                    </div>
                    <div class="form-group">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Contact Person *</label>
                        <input type="text" name="contact_person" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-brand-primary focus:border-transparent" required minlength="2">
                    </div>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div class="form-group">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Email *</label>
                        <input type="email" name="email" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-brand-primary focus:border-transparent" required>
                    </div>
                    <div class="form-group">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Phone</label>
                        <input type="tel" name="phone" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-brand-primary focus:border-transparent" pattern="[\d\s\-\+\(\)]{10,}">
                    </div>
                </div>
                
                <div class="flex gap-3 pt-4">
                    <button type="button" class="flex-1 px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition close-modal">Cancel</button>
                    <button type="submit" class="flex-1 px-4 py-2 bg-brand-primary text-white rounded-lg hover:bg-brand-primary-hover transition">Save Contact</button>
                </div>
            </form>
        </div>
    </div>

    <script>
    // SINGLE VISIBILITY STATE FOR ALL AMOUNTS IN AP/AR PAGE
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
        initializeTabs();
        initializeSearch();
        
        // Initialize all amounts as hidden on page load
        initializeAmountVisibility();

        // SINGLE VISIBILITY TOGGLE FOR ALL AMOUNTS
        const amountVisibilityToggle = document.getElementById('amount-visibility-toggle');
        
        function toggleAllAmounts() {
            allAmountsVisible = !allAmountsVisible;
            
            // Toggle ALL amount values on the page (AP/AR balances and table amounts)
            const allAmountElements = document.querySelectorAll('.ap-balance-amount, .ar-balance-amount, .amount-value');
            allAmountElements.forEach(span => {
                if (allAmountsVisible) {
                    const actualAmount = span.getAttribute('data-value');
                    span.textContent = actualAmount;
                    span.classList.remove('hidden-amount');
                } else {
                    span.textContent = '₱ ••••••••';
                    span.classList.add('hidden-amount');
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
        
        // Initialize all amounts as hidden
        function initializeAmountVisibility() {
            const allAmountElements = document.querySelectorAll('.ap-balance-amount, .ar-balance-amount, .amount-value');
            allAmountElements.forEach(span => {
                span.textContent = '₱ ••••••••';
                span.classList.add('hidden-amount');
            });
        }

        // Add click event to the single toggle button
        if (amountVisibilityToggle) {
            amountVisibilityToggle.addEventListener('click', toggleAllAmounts);
        }
    });

    function initializeTabs() {
        const tabButtons = document.querySelectorAll('.tab-button');
        const tabContents = document.querySelectorAll('.tab-content');
        
        tabButtons.forEach(button => {
            button.addEventListener('click', function() {
                const tabId = this.getAttribute('data-tab');
                
                // Remove active class from all tabs
                tabButtons.forEach(btn => {
                    btn.classList.remove('border-brand-primary', 'text-brand-primary');
                    btn.classList.add('border-transparent', 'text-gray-600');
                });
                
                // Hide all tab contents
                tabContents.forEach(content => {
                    content.classList.remove('active');
                    content.classList.add('hidden');
                });
                
                // Add active class to clicked tab
                this.classList.add('border-brand-primary', 'text-brand-primary');
                this.classList.remove('border-transparent', 'text-gray-600');
                
                // Show corresponding tab content
                const tabContent = document.getElementById(`${tabId}-content`);
                if (tabContent) {
                    tabContent.classList.remove('hidden');
                    tabContent.classList.add('active');
                }
                
                // Re-run search for the active tab
                setTimeout(performSearch, 100);
            });
        });
    }

    function initializeSearch() {
        const searchInput = document.getElementById('search-contacts');
        const clearSearchBtn = document.getElementById('clear-search');
        const filterBalance = document.getElementById('filter-balance');
        const searchResultsInfo = document.getElementById('search-results-info');
        const resultsCount = document.getElementById('results-count');
        
        if (!searchInput) return;
        
        function performSearch() {
            const searchTerm = searchInput.value.toLowerCase().trim();
            const selectedBalance = filterBalance.value;
            
            const activeTab = document.querySelector('.tab-button.border-brand-primary').getAttribute('data-tab');
            const tableBody = document.querySelector(`#${activeTab}-content .transaction-row` ? `#${activeTab}-content table tbody` : null);
            
            if (!tableBody) return;
            
            const rows = tableBody.querySelectorAll('tr');
            let visibleRows = 0;
            
            rows.forEach(row => {
                // Skip empty state rows
                if (row.cells.length <= 1) return;
                
                const cells = row.cells;
                const contactId = cells[0].textContent.toLowerCase();
                const companyName = cells[1].textContent.toLowerCase();
                const contactPerson = cells[2].textContent.toLowerCase();
                const email = cells[3].textContent.toLowerCase();
                const balanceCell = cells[5];
                const balanceText = balanceCell.textContent;
                
                // Text search
                const matchesSearch = !searchTerm || 
                                     contactId.includes(searchTerm) || 
                                     companyName.includes(searchTerm) || 
                                     contactPerson.includes(searchTerm) || 
                                     email.includes(searchTerm);
                
                // Balance filter
                let matchesBalance = true;
                if (selectedBalance) {
                    const balanceValue = parseFloat(balanceText.replace(/[^\d.-]/g, ''));
                    if (selectedBalance === 'positive' && balanceValue <= 0) matchesBalance = false;
                    if (selectedBalance === 'negative' && balanceValue >= 0) matchesBalance = false;
                    if (selectedBalance === 'zero' && balanceValue !== 0) matchesBalance = false;
                }
                
                const isVisible = matchesSearch && matchesBalance;
                row.style.display = isVisible ? '' : 'none';
                
                if (isVisible) {
                    visibleRows++;
                }
            });
            
            // Update results info
            if (searchTerm || selectedBalance) {
                searchResultsInfo.classList.remove('hidden');
                resultsCount.textContent = visibleRows;
            } else {
                searchResultsInfo.classList.add('hidden');
            }
        }
        
        // Event listeners
        searchInput.addEventListener('input', performSearch);
        filterBalance.addEventListener('change', performSearch);
        
        clearSearchBtn.addEventListener('click', function() {
            searchInput.value = '';
            filterBalance.value = '';
            performSearch();
            searchInput.focus();
        });
        
        // Add keyboard shortcut
        searchInput.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.key === 'k') {
                e.preventDefault();
                this.focus();
            }
        });
        
        // Initial search to set up the state
        performSearch();
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

    // Accounts Payable functions
    function openVendorModal(vendorId) {
        if (vendorId === undefined) vendorId = null;
        const modal = document.getElementById('vendor-modal');
        const title = document.getElementById('vendor-modal-title');
        const action = document.getElementById('vendor-action');
        const vendorIdInput = document.getElementById('vendor-id');
        
        if (vendorId) {
            title.textContent = 'Edit Accounts Payable Contact';
            action.value = 'update_vendor';
            vendorIdInput.value = vendorId;
        } else {
            title.textContent = 'Add New Accounts Payable Contact';
            action.value = 'add_vendor';
            vendorIdInput.value = '';
            document.getElementById('vendor-form').reset();
        }
        
        modal.style.display = 'block';
    }

    function editVendor(vendorId) {
        openVendorModal(vendorId);
    }

    function deleteVendor(vendorId, vendorName) {
        if (confirm('Are you sure you want to delete accounts payable contact "' + vendorName + '"? This action cannot be undone.')) {
            // Create a form and submit it to delete the vendor
            const form = document.createElement('form');
            form.method = 'POST';
            form.style.display = 'none';
            
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'delete_contact';
            form.appendChild(actionInput);
            
            const contactIdInput = document.createElement('input');
            contactIdInput.type = 'hidden';
            contactIdInput.name = 'contact_id';
            contactIdInput.value = vendorId;
            form.appendChild(contactIdInput);
            
            const contactTypeInput = document.createElement('input');
            contactTypeInput.type = 'hidden';
            contactTypeInput.name = 'contact_type';
            contactTypeInput.value = 'Vendor';
            form.appendChild(contactTypeInput);
            
            const csrfInput = document.createElement('input');
            csrfInput.type = 'hidden';
            csrfInput.name = 'csrf_token';
            csrfInput.value = document.querySelector('input[name="csrf_token"]').value;
            form.appendChild(csrfInput);
            
            document.body.appendChild(form);
            form.submit();
        }
    }

    // Accounts Receivable functions
    function openCustomerModal(customerId) {
        if (customerId === undefined) customerId = null;
        const modal = document.getElementById('customer-modal');
        const title = document.getElementById('customer-modal-title');
        const action = document.getElementById('customer-action');
        const customerIdInput = document.getElementById('customer-id');
        
        if (customerId) {
            title.textContent = 'Edit Accounts Receivable Contact';
            action.value = 'update_customer';
            customerIdInput.value = customerId;
        } else {
            title.textContent = 'Add New Accounts Receivable Contact';
            action.value = 'add_customer';
            customerIdInput.value = '';
            document.getElementById('customer-form').reset();
        }
        
        modal.style.display = 'block';
    }

    function editCustomer(customerId) {
        openCustomerModal(customerId);
    }

    function deleteCustomer(customerId, customerName) {
        if (confirm('Are you sure you want to delete accounts receivable contact "' + customerName + '"? This action cannot be undone.')) {
            // Create a form and submit it to delete the customer
            const form = document.createElement('form');
            form.method = 'POST';
            form.style.display = 'none';
            
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'delete_contact';
            form.appendChild(actionInput);
            
            const contactIdInput = document.createElement('input');
            contactIdInput.type = 'hidden';
            contactIdInput.name = 'contact_id';
            contactIdInput.value = customerId;
            form.appendChild(contactIdInput);
            
            const contactTypeInput = document.createElement('input');
            contactTypeInput.type = 'hidden';
            contactTypeInput.name = 'contact_type';
            contactTypeInput.value = 'Customer';
            form.appendChild(contactTypeInput);
            
            const csrfInput = document.createElement('input');
            csrfInput.type = 'hidden';
            csrfInput.name = 'csrf_token';
            csrfInput.value = document.querySelector('input[name="csrf_token"]').value;
            form.appendChild(csrfInput);
            
            document.body.appendChild(form);
            form.submit();
        }
    }

    // Record invoice for specific contact
    function recordInvoiceForContact(contactId, contactType) {
        // Redirect to invoices page with pre-selected contact and type
        const invoiceType = contactType === 'Vendor' ? 'Payable' : 'Receivable';
        window.location.href = 'invoices.php?contact_id=' + contactId + '&type=' + invoiceType + '&from_contact=1';
    }
    </script>
</body>
</html>