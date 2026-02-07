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

try {
    // Database connection
    $database = new Database();
    $db = $database->getConnection();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    http_response_code(500);
    echo "Database connection error.";
    exit;
}

// Authentication check
if (empty($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$user_id = (int)$_SESSION['user_id'];

// Load current user
$u = $db->prepare("SELECT id, name, username, role FROM users WHERE id = ?");
$u->execute([$user_id]);
$user = $u->fetch();
if (!$user) {
    header("Location: index.php");
    exit;
}

// Function to add a notification when payment is received
function addPaymentNotification($payment_id, $contact_name, $amount) {
    $notification = [
        'id' => uniqid(),
        'type' => 'payment',
        'message' => "Payment received from {$contact_name}: {$payment_id} - " . formatNumber($amount, true),
        'timestamp' => time(),
        'link' => 'payment_entry.php',
        'read' => false
    ];
    
    // Add to beginning of array (newest first)
    array_unshift($_SESSION['ap_ar_notifications'], $notification);
    
    // Keep only last 10 notifications
    $_SESSION['ap_ar_notifications'] = array_slice($_SESSION['ap_ar_notifications'], 0, 10);
}

// Function to add a notification when payment is made
function addPaymentMadeNotification($payment_id, $vendor_name, $amount) {
    $notification = [
        'id' => uniqid(),
        'type' => 'payment',
        'message' => "Payment made to {$vendor_name}: {$payment_id} - " . formatNumber($amount, true),
        'timestamp' => time(),
        'link' => 'payment_entry.php',
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
        // Check if 'read' key exists and is false, or if it doesn't exist (treat as unread)
        if (!isset($notification['read']) || $notification['read'] === false) {
            $unread_count++;
        }
    }
    return $unread_count;
}

// Function to get notifications - AP/AR only (session-based)
function getNotifications(): array {
    return $_SESSION['ap_ar_notifications'];
}

// Function to format numbers with dots if hidden
function formatNumber($number, $show_numbers = false) {
    if ($show_numbers) {
        return '₱' . number_format((float)$number, 2);
    } else {
        return '₱ ••••••••';
    }
}

// Get payments data with filters
function getPayments(PDO $db, ?string $type = null, ?string $status = null, ?string $start_date = null, ?string $end_date = null): array {
    $sql = "SELECT p.*, bc.name as contact_name, bc.contact_person, i.invoice_number, i.amount as invoice_amount,
                   i.type as invoice_type
            FROM payments p
            LEFT JOIN business_contacts bc ON p.contact_id = bc.id
            LEFT JOIN invoices i ON p.invoice_id = i.id
            WHERE 1=1";
    
    $params = [];
    
    if ($type) {
        $sql .= " AND p.type = ?";
        $params[] = $type;
    }
    
    if ($status) {
        $sql .= " AND p.status = ?";
        $params[] = $status;
    }
    
    if ($start_date) {
        $sql .= " AND p.payment_date >= ?";
        $params[] = $start_date;
    }
    
    if ($end_date) {
        $sql .= " AND p.payment_date <= ?";
        $params[] = $end_date;
    }
    
    $sql .= " ORDER BY p.payment_date DESC, p.id DESC";
    
    try {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Error fetching payments: " . $e->getMessage());
        return [];
    }
}

// Get outstanding invoices for payment
function getOutstandingInvoices(PDO $db, ?string $type = null): array {
    $sql = "SELECT i.*, bc.name as contact_name, bc.contact_person,
                   (i.amount - COALESCE(SUM(p.amount), 0)) as outstanding_balance
            FROM invoices i
            LEFT JOIN business_contacts bc ON i.contact_id = bc.id
            LEFT JOIN payments p ON i.id = p.invoice_id AND p.status = 'Completed'
            WHERE i.status != 'Paid' AND i.status != 'Cancelled'";
    
    $params = [];
    
    if ($type) {
        $sql .= " AND i.type = ?";
        $params[] = $type;
    }
    
    $sql .= " GROUP BY i.id, i.invoice_number, i.contact_id, i.type, i.issue_date, i.due_date, 
                     i.amount, i.status, i.description, i.created_at, bc.name, bc.contact_person
              HAVING outstanding_balance > 0
              ORDER BY i.due_date ASC";
    
    try {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Error fetching outstanding invoices: " . $e->getMessage());
        return [];
    }
}

// Get business contacts for dropdown
function getBusinessContacts(PDO $db, ?string $type = null): array {
    $sql = "SELECT id, name, contact_person, type 
            FROM business_contacts 
            WHERE status = 'Active'";
    
    $params = [];
    if ($type) {
        $sql .= " AND type = ?";
        $params[] = $type;
    }
    
    $sql .= " ORDER BY name ASC";
    
    try {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Error fetching business contacts: " . $e->getMessage());
        return [];
    }
}

// Generate unique payment ID
function generatePaymentId(PDO $db, string $type): string {
    $prefix = $type === 'Receive' ? 'PMT' : 'V-PMT';
    $year = date('Y');
    
    try {
        // Get the latest payment ID for this type and year
        $sql = "SELECT payment_id FROM payments WHERE payment_id LIKE ? ORDER BY id DESC LIMIT 1";
        $stmt = $db->prepare($sql);
        $stmt->execute([$prefix . '-' . $year . '-%']);
        $lastPayment = $stmt->fetch();
        
        if ($lastPayment) {
            $lastNumber = intval(substr($lastPayment['payment_id'], -3));
            $newNumber = str_pad($lastNumber + 1, 3, '0', STR_PAD_LEFT);
        } else {
            $newNumber = '001';
        }
        
        return $prefix . '-' . $year . '-' . $newNumber;
    } catch (PDOException $e) {
        error_log("Error generating payment ID: " . $e->getMessage());
        // Fallback method
        $timestamp = time();
        return $prefix . '-' . $year . '-' . substr($timestamp, -6);
    }
}

// Generate automatic OR Number
function generateOrNumber(PDO $db): string {
    $year = date('Y');
    
    try {
        // Get the latest OR number for this year
        $sql = "SELECT reference_number FROM payments 
                WHERE reference_number LIKE ? 
                ORDER BY CAST(SUBSTRING(reference_number, 5) AS UNSIGNED) DESC 
                LIMIT 1";
        $stmt = $db->prepare($sql);
        $stmt->execute(['OR-' . $year . '-%']);
        $lastOr = $stmt->fetch();
        
        if ($lastOr) {
            $lastNumber = intval(substr($lastOr['reference_number'], 8)); // OR-YYYY-XXXXX
            $newNumber = $lastNumber + 1;
        } else {
            $newNumber = 1;
        }
        
        // Format as OR-YYYY-XXXXX (5 digits)
        return 'OR-' . $year . '-' . str_pad((string)$newNumber, 5, '0', STR_PAD_LEFT);
    } catch (PDOException $e) {
        error_log("Error generating OR number: " . $e->getMessage());
        // Fallback method
        $timestamp = time();
        return 'OR-' . $year . '-' . substr($timestamp, -5);
    }
}

// Get invoice details for payment
function getInvoiceDetails(PDO $db, int $invoice_id): array {
    try {
        $sql = "SELECT i.*, bc.name as contact_name, bc.contact_person, bc.id as contact_id,
                       (i.amount - COALESCE(SUM(p.amount), 0)) as outstanding_balance
                FROM invoices i
                LEFT JOIN business_contacts bc ON i.contact_id = bc.id
                LEFT JOIN payments p ON i.id = p.invoice_id AND p.status = 'Completed'
                WHERE i.id = ?
                GROUP BY i.id";
        $stmt = $db->prepare($sql);
        $stmt->execute([$invoice_id]);
        return $stmt->fetch() ?: [];
    } catch (PDOException $e) {
        error_log("Error fetching invoice details: " . $e->getMessage());
        return [];
    }
}

// Get outstanding invoices for a specific contact
function getOutstandingInvoicesByContact(PDO $db, int $contact_id, ?string $type = null): array {
    $sql = "SELECT i.*, bc.name as contact_name, bc.contact_person,
                   (i.amount - COALESCE(SUM(p.amount), 0)) as outstanding_balance
            FROM invoices i
            LEFT JOIN business_contacts bc ON i.contact_id = bc.id
            LEFT JOIN payments p ON i.id = p.invoice_id AND p.status = 'Completed'
            WHERE i.contact_id = ? AND i.status != 'Paid' AND i.status != 'Cancelled'";
    
    $params = [$contact_id];
    
    if ($type) {
        $sql .= " AND i.type = ?";
        $params[] = $type;
    }
    
    $sql .= " GROUP BY i.id, i.invoice_number, i.contact_id, i.type, i.issue_date, i.due_date, 
                     i.amount, i.status, i.description, i.created_at, bc.name, bc.contact_person
              HAVING outstanding_balance > 0
              ORDER BY i.due_date ASC";
    
    try {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Error fetching outstanding invoices by contact: " . $e->getMessage());
        return [];
    }
}

// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // CSRF protection
    if (empty($_SESSION['csrf_token']) || empty($_POST['csrf_token']) || 
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['error'] = "Security validation failed";
        header("Location: payment_entry.php");
        exit;
    }
    
    if ($action === 'add_payment') {
        $payment_id = $_POST['payment_id'] ?? '';
        $contact_id = $_POST['contact_id'] ?? '';
        $invoice_id = $_POST['invoice_id'] ?? '';
        $payment_date = $_POST['payment_date'] ?? '';
        $amount = $_POST['amount'] ?? '';
        $payment_method = $_POST['payment_method'] ?? '';
        $type = $_POST['type'] ?? '';
        $reference_number = $_POST['reference_number'] ?? '';
        $notes = $_POST['notes'] ?? '';
        
        // Validate required fields
        if (empty($payment_id) || empty($contact_id) || empty($payment_date) || empty($amount) || empty($payment_method) || empty($type)) {
            $_SESSION['error'] = "Please fill in all required fields";
            header("Location: payment_entry.php");
            exit;
        }
        
        // Validate amount
        if (!is_numeric($amount) || $amount <= 0) {
            $_SESSION['error'] = "Please enter a valid payment amount";
            header("Location: payment_entry.php");
            exit;
        }
        
        // Generate OR Number if not provided
        if (empty($reference_number)) {
            $reference_number = generateOrNumber($db);
        }
        
        try {
            // Check if payment ID already exists
            $checkSql = "SELECT id FROM payments WHERE payment_id = ?";
            $checkStmt = $db->prepare($checkSql);
            $checkStmt->execute([$payment_id]);
            
            if ($checkStmt->fetch()) {
                $_SESSION['error'] = "Payment ID already exists. Please try again.";
                header("Location: payment_entry.php");
                exit;
            }
            
            // Get contact details for notification
            $contactStmt = $db->prepare("SELECT name, type FROM business_contacts WHERE id = ?");
            $contactStmt->execute([$contact_id]);
            $contact = $contactStmt->fetch();
            
            // Determine if this is a collection payment (Receive type)
            $isCollectionPayment = ($type === 'Receive');
            
            // Check if notes column exists
            $checkColumns = $db->query("SHOW COLUMNS FROM payments LIKE 'notes'")->fetch();
            if ($checkColumns) {
                // Insert without created_by column
                $sql = "INSERT INTO payments (payment_id, contact_id, invoice_id, payment_date, amount, 
                        payment_method, type, reference_number, notes, status) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Completed')";
                $stmt = $db->prepare($sql);
                $stmt->execute([
                    $payment_id, 
                    $contact_id, 
                    !empty($invoice_id) ? $invoice_id : null, 
                    $payment_date, 
                    $amount, 
                    $payment_method, 
                    $type, 
                    trim($reference_number), // Trim the OR Number
                    $notes
                ]);
            } else {
                // Insert without notes and created_by columns
                $sql = "INSERT INTO payments (payment_id, contact_id, invoice_id, payment_date, amount, 
                        payment_method, type, reference_number, status) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Completed')";
                $stmt = $db->prepare($sql);
                $stmt->execute([
                    $payment_id, 
                    $contact_id, 
                    !empty($invoice_id) ? $invoice_id : null, 
                    $payment_date, 
                    $amount, 
                    $payment_method, 
                    $type, 
                    trim($reference_number) // Trim the OR Number
                ]);
            }
            
            $new_payment_id = $db->lastInsertId();
            
            // Create notification for payments
            if ($type === 'Receive') {
                addPaymentNotification($payment_id, $contact['name'] ?? 'customer', $amount);
            } else {
                addPaymentMadeNotification($payment_id, $contact['name'] ?? 'vendor', $amount);
            }
            
            // Update invoice status if fully paid and invoice_id is provided
            if (!empty($invoice_id)) {
                $invoice_sql = "SELECT i.amount, COALESCE(SUM(p.amount), 0) as total_paid
                               FROM invoices i
                               LEFT JOIN payments p ON i.id = p.invoice_id AND p.status = 'Completed'
                               WHERE i.id = ?
                               GROUP BY i.id";
                $invoice_stmt = $db->prepare($invoice_sql);
                $invoice_stmt->execute([$invoice_id]);
                $invoice_data = $invoice_stmt->fetch();
                
                if ($invoice_data && ($invoice_data['total_paid'] + $amount) >= $invoice_data['amount']) {
                    $update_sql = "UPDATE invoices SET status = 'Paid' WHERE id = ?";
                    $update_stmt = $db->prepare($update_sql);
                    $update_stmt->execute([$invoice_id]);
                } else {
                    // Update to partially paid if there's some payment but not full
                    $update_sql = "UPDATE invoices SET status = 'Pending' WHERE id = ?";
                    $update_stmt = $db->prepare($update_sql);
                    $update_stmt->execute([$invoice_id]);
                }
            }
            
            $_SESSION['success'] = "Payment recorded successfully! OR Number: " . $reference_number . 
                ($isCollectionPayment ? " The payment has been added to collections." : "");
            
            header("Location: payment_entry.php");
            exit;
        } catch (PDOException $e) {
            error_log("Payment recording error: " . $e->getMessage());
            $_SESSION['error'] = "Error recording payment: " . $e->getMessage();
            header("Location: payment_entry.php");
            exit;
        }
    }
    
    // Handle delete payment
    if ($action === 'delete_payment') {
        $payment_id = $_POST['payment_id'] ?? '';
        
        if (!empty($payment_id)) {
            try {
                // Get payment details before deletion for invoice status update
                $payment_sql = "SELECT invoice_id, amount FROM payments WHERE payment_id = ?";
                $payment_stmt = $db->prepare($payment_sql);
                $payment_stmt->execute([$payment_id]);
                $payment_data = $payment_stmt->fetch();
                
                // Delete the payment
                $delete_sql = "DELETE FROM payments WHERE payment_id = ?";
                $delete_stmt = $db->prepare($delete_sql);
                $delete_stmt->execute([$payment_id]);
                
                // Update invoice status if it was linked
                if ($payment_data && $payment_data['invoice_id']) {
                    $invoice_sql = "SELECT i.amount, COALESCE(SUM(p.amount), 0) as total_paid
                                   FROM invoices i
                                   LEFT JOIN payments p ON i.id = p.invoice_id AND p.status = 'Completed'
                                   WHERE i.id = ?
                                   GROUP BY i.id";
                    $invoice_stmt = $db->prepare($invoice_sql);
                    $invoice_stmt->execute([$payment_data['invoice_id']]);
                    $invoice_data = $invoice_stmt->fetch();
                    
                    if ($invoice_data && $invoice_data['total_paid'] < $invoice_data['amount']) {
                        $update_sql = "UPDATE invoices SET status = 'Pending' WHERE id = ?";
                        $update_stmt = $db->prepare($update_sql);
                        $update_stmt->execute([$payment_data['invoice_id']]);
                    }
                }
                
                $_SESSION['success'] = "Payment deleted successfully!";
                header("Location: payment_entry.php");
                exit;
            } catch (PDOException $e) {
                error_log("Payment deletion error: " . $e->getMessage());
                $_SESSION['error'] = "Error deleting payment: " . $e->getMessage();
                header("Location: payment_entry.php");
                exit;
            }
        }
    }
}

// Handle AJAX request for invoice details
if (isset($_GET['get_invoice_details']) && isset($_GET['invoice_id'])) {
    $invoice_id = (int)$_GET['invoice_id'];
    $invoice_details = getInvoiceDetails($db, $invoice_id);
    header('Content-Type: application/json');
    echo json_encode($invoice_details);
    exit;
}

// Handle AJAX request for contact invoices
if (isset($_GET['get_contact_invoices']) && isset($_GET['contact_id'])) {
    $contact_id = (int)$_GET['contact_id'];
    $type = $_GET['type'] ?? null;
    $invoices = getOutstandingInvoicesByContact($db, $contact_id, $type);
    header('Content-Type: application/json');
    echo json_encode($invoices);
    exit;
}

// Handle AJAX request for generating OR number
if (isset($_GET['generate_or_number'])) {
    $or_number = generateOrNumber($db);
    header('Content-Type: application/json');
    echo json_encode(['or_number' => $or_number]);
    exit;
}

// Handle invoice-based payment redirect
if (isset($_GET['invoice_id'])) {
    $invoice_id = (int)$_GET['invoice_id'];
    $invoice_details = getInvoiceDetails($db, $invoice_id);
    
    if ($invoice_details) {
        // Pre-fill the payment form with invoice data
        echo "<script>
            document.addEventListener('DOMContentLoaded', function() {
                setTimeout(function() {
                    openPaymentModal();
                    // Pre-fill form with invoice data
                    document.getElementById('payment-type').value = '" . ($invoice_details['type'] === 'Receivable' ? 'Receive' : 'Make') . "';
                    updatePaymentNumber();
                    document.getElementById('contact-id').value = '" . $invoice_details['contact_id'] . "';
                    document.getElementById('selected-invoice-id').value = '" . $invoice_id . "';
                    document.getElementById('payment-amount').value = '" . $invoice_details['outstanding_balance'] . "';
                    
                    // Hide invoice selection since we're auto-selecting
                    document.getElementById('invoice-selection').style.display = 'none';
                }, 500);
            });
        </script>";
    }
}

// Get filter values from request
$payment_type = $_GET['type'] ?? null;
$payment_status = $_GET['status'] ?? null;
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-t');

$payments = getPayments($db, $payment_type, $payment_status, $start_date, $end_date);
$outstanding_invoices = getOutstandingInvoices($db);
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
    header("Location: payment_entry.php");
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Payment Entry - Financial Dashboard</title>

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
            border: 3px solid #f3f3f4;
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
                            <a href="invoices.php" class="block px-3 py-1.5 rounded-lg text-xs text-gray-600 hover:bg-green-50 hover:text-brand-primary transition-all duration-200 hover:translate-x-1">
                                Invoices
                            </a>
                            <a href="payment_entry.php" class="block px-3 py-1.5 rounded-lg text-xs bg-emerald-50 text-brand-primary font-medium border border-emerald-100 hover:bg-emerald-100 hover:border-emerald-200 transition-all duration-200 hover:translate-x-1">
                                <span class="flex items-center justify-between">
                                    Payment Entry
                                    <span class="inline-flex w-1.5 h-1.5 rounded-full bg-emerald-400"></span>
                                </span>
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
                        Payment Entry
                    </h1>
                    <p class="text-xs text-gray-500">
                        Record and manage customer/vendor payments
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
                <a href="?toggle_numbers=1" id="visibility-toggle" class="w-10 h-10 rounded-xl hover:bg-gray-100 active:bg-gray-200 transition flex items-center justify-center relative"
                        title="<?php echo $_SESSION['show_numbers'] ? 'Hide Numbers' : 'Show Numbers'; ?>">
                    <i class="<?php echo $_SESSION['show_numbers'] ? 'fa-solid fa-eye' : 'fa-solid fa-eye-slash'; ?> text-gray-600"></i>
                </a>

                <!-- AP/AR Notifications -->
                <button id="notification-btn" class="w-10 h-10 rounded-xl hover:bg-gray-100 active:bg-gray-200 transition flex items-center justify-center relative">
                    <i class="fa-solid fa-bell text-gray-600"></i>
                    <?php if($notification_count > 0): ?>
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
                $total_amount = 0;
                $receive_total = 0;
                $make_total = 0;
                $this_month = 0;
                
                foreach ($payments as $payment) {
                    $total_amount += (float)$payment['amount'];
                    if ($payment['type'] === 'Receive') {
                        $receive_total += (float)$payment['amount'];
                    } else {
                        $make_total += (float)$payment['amount'];
                    }
                    if (date('Y-m', strtotime($payment['payment_date'])) === date('Y-m')) {
                        $this_month += (float)$payment['amount'];
                    }
                }
                
                $stats = [
                    ['icon' => 'bx-credit-card', 'label' => 'Total Payments', 'value' => count($payments), 'color' => 'blue', 'formatted' => formatNumber(count($payments), $_SESSION['show_numbers'])],
                    ['icon' => 'bx-money', 'label' => 'Total Amount', 'value' => $total_amount, 'color' => 'green', 'formatted' => formatNumber($total_amount, $_SESSION['show_numbers'])],
                    ['icon' => 'bx-time', 'label' => 'Outstanding Invoices', 'value' => count($outstanding_invoices), 'color' => 'yellow', 'formatted' => formatNumber(count($outstanding_invoices), $_SESSION['show_numbers'])],
                    ['icon' => 'bx-calendar', 'label' => 'This Month', 'value' => $this_month, 'color' => 'purple', 'formatted' => formatNumber($this_month, $_SESSION['show_numbers'])]
                ];
                
                foreach($stats as $stat): 
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
                                    <p class="text-2xl font-bold text-gray-800 <?php echo !$_SESSION['show_numbers'] ? 'hidden-amount' : ''; ?>">
                                        <?php echo $stat['formatted']; ?>
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Filter Section -->
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 mb-6">
                <h3 class="text-lg font-bold text-gray-800 mb-4">Filter Payments</h3>
                <form method="GET" class="grid grid-cols-1 md:grid-cols-5 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Type</label>
                        <select name="type" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-brand-primary focus:border-transparent">
                            <option value="">All Types</option>
                            <option value="Receive" <?php echo $payment_type === 'Receive' ? 'selected' : ''; ?>>Receive Payment</option>
                            <option value="Make" <?php echo $payment_type === 'Make' ? 'selected' : ''; ?>>Make Payment</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Status</label>
                        <select name="status" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-brand-primary focus:border-transparent">
                            <option value="">All Status</option>
                            <option value="Completed" <?php echo $payment_status === 'Completed' ? 'selected' : ''; ?>>Completed</option>
                            <option value="Processing" <?php echo $payment_status === 'Processing' ? 'selected' : ''; ?>>Processing</option>
                            <option value="Scheduled" <?php echo $payment_status === 'Scheduled' ? 'selected' : ''; ?>>Scheduled</option>
                            <option value="Cancelled" <?php echo $payment_status === 'Cancelled' ? 'selected' : ''; ?>>Cancelled</option>
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
                        <button type="submit" class="px-4 py-2 bg-brand-primary text-white rounded-lg hover:bg-brand-primary-hover transition flex items-center gap-2 flex-1">
                            <i class='bx bx-filter-alt'></i> Apply
                        </button>
                        <a href="payment_entry.php" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition flex items-center gap-2">
                            <i class='bx bx-reset'></i> Reset
                        </a>
                    </div>
                </form>
            </div>

            <!-- Payments Table -->
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden mb-6">
                <div class="p-6 border-b border-gray-100">
                    <div class="flex justify-between items-center">
                        <div class="flex items-center gap-3">
                            <h3 class="text-lg font-bold text-gray-800">Payment Management</h3>
                        </div>
                        <button class="px-4 py-2 bg-brand-primary text-white rounded-lg hover:bg-brand-primary-hover transition flex items-center gap-2" onclick="openPaymentModal()">
                            <i class='bx bx-plus'></i> Record Payment
                        </button>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="text-left p-4 text-sm font-medium text-gray-500">Payment ID</th>
                                <th class="text-left p-4 text-sm font-medium text-gray-500">Contact</th>
                                <th class="text-left p-4 text-sm font-medium text-gray-500">Invoice</th>
                                <th class="text-left p-4 text-sm font-medium text-gray-500">Type</th>
                                <th class="text-left p-4 text-sm font-medium text-gray-500">Date</th>
                                <th class="text-left p-4 text-sm font-medium text-gray-500">Amount</th>
                                <th class="text-left p-4 text-sm font-medium text-gray-500">Method</th>
                                <th class="text-left p-4 text-sm font-medium text-gray-500">OR No</th>
                                <th class="text-left p-4 text-sm font-medium text-gray-500">Status</th>
                                <th class="text-left p-4 text-sm font-medium text-gray-500">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($payments) > 0): ?>
                                <?php foreach ($payments as $payment): ?>
                                <tr class="transaction-row">
                                    <td class="p-4 font-mono font-medium"><?php echo htmlspecialchars($payment['payment_id']); ?></td>
                                    <td class="p-4">
                                        <div class="font-medium"><?php echo htmlspecialchars($payment['contact_name'] ?? 'N/A'); ?></div>
                                        <div class="text-sm text-gray-500"><?php echo htmlspecialchars($payment['contact_person'] ?? 'N/A'); ?></div>
                                    </td>
                                    <td class="p-4">
                                        <?php if ($payment['invoice_number']): ?>
                                            <span class="font-mono text-sm"><?php echo htmlspecialchars($payment['invoice_number']); ?></span>
                                        <?php else: ?>
                                            <span class="text-gray-400 text-sm">No invoice</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="p-4">
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium 
                                            <?php echo $payment['type'] === 'Receive' ? 'bg-green-100 text-green-800' : 'bg-blue-100 text-blue-800'; ?>">
                                            <?php echo $payment['type'] === 'Receive' ? 'Receive' : 'Make'; ?>
                                        </span>
                                    </td>
                                    <td class="p-4 text-gray-600"><?php echo htmlspecialchars($payment['payment_date']); ?></td>
                                    <td class="p-4 font-medium text-gray-800 <?php echo !$_SESSION['show_numbers'] ? 'hidden-amount' : ''; ?>">
                                        <?php echo formatNumber((float)$payment['amount'], $_SESSION['show_numbers']); ?>
                                    </td>
                                    <td class="p-4">
                                        <?php
                                        $method_class = match($payment['payment_method']) {
                                            'Cash' => 'method-cash',
                                            'Check' => 'method-check',
                                            'Bank Transfer' => 'method-transfer',
                                            'Credit Card' => 'method-card',
                                            default => 'method-cash'
                                        };
                                        ?>
                                        <span class="payment-method-badge <?php echo $method_class; ?>">
                                            <?php echo htmlspecialchars($payment['payment_method']); ?>
                                        </span>
                                    </td>
                                    <td class="p-4 font-mono text-sm"><?php echo htmlspecialchars($payment['reference_number'] ?? '-'); ?></td>
                                    <td class="p-4">
                                        <?php
                                        $status_class = match($payment['status']) {
                                            'Completed' => 'status-completed',
                                            'Processing' => 'status-processing',
                                            'Scheduled' => 'status-pending',
                                            'Cancelled' => 'status-cancelled',
                                            default => 'status-pending'
                                        };
                                        ?>
                                        <span class="status-badge <?php echo $status_class; ?>">
                                            <?php echo htmlspecialchars($payment['status']); ?>
                                        </span>
                                    </td>
                                    <td class="p-4">
                                        <div class="flex flex-wrap gap-2">
                                            <button class="px-3 py-1 text-sm bg-blue-100 text-blue-700 rounded-lg hover:bg-blue-200 transition flex items-center gap-1 view-btn"
                                                    onclick="viewInvoice('<?php echo $payment['invoice_id']; ?>')">
                                                <i class='bx bx-show'></i> View
                                            </button>
                                            <button class="px-3 py-1 text-sm bg-red-100 text-red-700 rounded-lg hover:bg-red-200 transition flex items-center gap-1 delete-btn"
                                                    onclick="deletePayment('<?php echo $payment['payment_id']; ?>', '<?php echo htmlspecialchars($payment['payment_id']); ?>')">
                                                <i class='bx bx-trash'></i> Delete
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="10" class="p-8 text-center text-gray-500">
                                        <i class='bx bx-folder-open text-3xl mb-2 text-gray-300'></i>
                                        <div>No payments found. <button class="text-brand-primary hover:underline" onclick="openPaymentModal()">Record your first payment</button></div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Quick Stats -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- Payment Summary -->
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                    <h3 class="text-lg font-bold text-gray-800 mb-4">Payment Summary</h3>
                    <div class="space-y-4">
                        <div class="flex justify-between items-center p-3 bg-gray-50 rounded-lg">
                            <span>Total Payments Amount:</span>
                            <span class="font-bold <?php echo !$_SESSION['show_numbers'] ? 'hidden-amount' : ''; ?>">
                                <?php echo formatNumber($total_amount, $_SESSION['show_numbers']); ?>
                            </span>
                        </div>
                        <div class="flex justify-between items-center p-3 bg-green-50 rounded-lg">
                            <span>Receivable Payments:</span>
                            <span class="font-bold <?php echo !$_SESSION['show_numbers'] ? 'hidden-amount' : ''; ?>">
                                <?php echo formatNumber($receive_total, $_SESSION['show_numbers']); ?>
                            </span>
                        </div>
                        <div class="flex justify-between items-center p-3 bg-red-50 rounded-lg">
                            <span>Payable Payments:</span>
                            <span class="font-bold <?php echo !$_SESSION['show_numbers'] ? 'hidden-amount' : ''; ?>">
                                <?php echo formatNumber($make_total, $_SESSION['show_numbers']); ?>
                            </span>
                        </div>
                        <div class="flex justify-between items-center p-3 bg-yellow-50 rounded-lg">
                            <span>Outstanding Invoices:</span>
                            <span class="font-bold <?php echo !$_SESSION['show_numbers'] ? 'hidden-amount' : ''; ?>">
                                <?php echo formatNumber(count($outstanding_invoices), $_SESSION['show_numbers']); ?>
                            </span>
                        </div>
                    </div>
                </div>
                
                <!-- Quick Actions -->
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                    <h3 class="text-lg font-bold text-gray-800 mb-4">Quick Actions</h3>
                    <div class="space-y-3">
                        <button class="w-full px-4 py-3 bg-brand-primary text-white rounded-lg hover:bg-brand-primary-hover transition flex items-center gap-3 text-left" onclick="openPaymentModal()">
                            <i class='bx bx-plus'></i>
                            <div>
                                <div class="font-medium">Record New Payment</div>
                                <div class="text-sm opacity-80">Create a new payment entry</div>
                            </div>
                        </button>
                        <button class="w-full px-4 py-3 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition flex items-center gap-3 text-left">
                            <i class='bx bx-import'></i>
                            <div>
                                <div class="font-medium">Import Payments</div>
                                <div class="text-sm opacity-80">Import payments from CSV</div>
                            </div>
                        </button>
                        <button class="w-full px-4 py-3 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition flex items-center gap-3 text-left" onclick="window.location.href='invoices.php'">
                            <i class='bx bx-receipt'></i>
                            <div>
                                <div class="font-medium">View Invoices</div>
                                <div class="text-sm opacity-80">Manage outstanding invoices</div>
                            </div>
                        </button>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- AP/AR Notification Modal -->
    <div id="notification-modal" class="modal">
        <div class="modal-content">
            <div class="flex justify-between items-center mb-6">
                <h2 class="text-xl font-bold text-gray-800">AP/AR Notifications</h2>
                <button class="close-modal text-gray-400 hover:text-gray-600 text-2xl">&times;</button>
            </div>
            <div id="notification-modal-list" class="space-y-4">
                <?php if (count($notifications) > 0): ?>
                    <?php if ($notification_count > 0): ?>
                        <div class="flex justify-end mb-4">
                            <button id="mark-all-read-btn" 
                                    class="px-4 py-2 bg-brand-primary text-white rounded-lg hover:bg-brand-primary-hover transition text-sm font-medium">
                                <i class='bx bx-check-double mr-2'></i>
                                Mark All as Read (<?php echo $notification_count; ?>)
                            </button>
                        </div>
                    <?php endif; ?>
                    
                    <?php foreach ($notifications as $notification): ?>
                    <div class="flex items-start gap-3 p-4 rounded-lg <?php echo (!isset($notification['read']) || !$notification['read']) ? 'bg-blue-50' : 'bg-gray-50'; ?> hover:bg-gray-100 transition" onclick="window.location.href='<?php echo htmlspecialchars($notification['link']); ?>'">
                        <div class="w-10 h-10 rounded-lg <?php echo (!isset($notification['read']) || !$notification['read']) ? 'bg-blue-100' : 'bg-gray-100'; ?> flex items-center justify-center flex-shrink-0">
                            <i class='bx <?php echo (!isset($notification['read']) || !$notification['read']) ? 'bx-bell-ring' : 'bx-bell'; ?> <?php echo (!isset($notification['read']) || !$notification['read']) ? 'text-blue-500' : 'text-gray-500'; ?>'></i>
                        </div>
                        <div class="flex-1">
                            <div class="flex justify-between items-start">
                                <div class="font-medium text-gray-800 text-sm">Payment Notification</div>
                                <?php if (!isset($notification['read']) || !$notification['read']): ?>
                                    <span class="w-2 h-2 rounded-full bg-blue-500 mt-1 flex-shrink-0"></span>
                                <?php endif; ?>
                            </div>
                            <div class="text-sm text-gray-600 mt-1"><?php echo htmlspecialchars($notification['message']); ?></div>
                            <div class="text-xs text-gray-400 mt-2"><?php echo date('M j, Y g:i A', $notification['timestamp']); ?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="text-center py-8 text-gray-500">
                        <i class='bx bx-bell text-3xl mb-2 text-gray-300'></i>
                        <div>No AP/AR notifications</div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Add Payment Modal -->
    <div id="payment-modal" class="modal">
        <div class="modal-content">
            <div class="flex justify-between items-center mb-6">
                <h2 class="text-xl font-bold text-gray-800">Record Payment</h2>
                <button class="close-modal text-gray-400 hover:text-gray-600 text-2xl">&times;</button>
            </div>
            
            <!-- Error Message Display -->
            <?php if (!empty($error_message)): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
                    <?php echo htmlspecialchars($error_message); ?>
                </div>
            <?php endif; ?>
            
            <form id="payment-form" method="POST">
                <input type="hidden" name="action" id="payment-action" value="add_payment">
                <input type="hidden" name="payment_id" id="payment-id">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Payment ID *</label>
                        <input type="text" name="payment_id" id="payment-number" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-brand-primary focus:border-transparent" required readonly>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Payment Type *</label>
                        <select name="type" id="payment-type" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-brand-primary focus:border-transparent" required onchange="updatePaymentNumber()">
                            <option value="">Select Type</option>
                            <option value="Receive">Receive Payment (AR)</option>
                            <option value="Make">Make Payment (AP)</option>
                        </select>
                    </div>
                </div>
                
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Contact *</label>
                    <select name="contact_id" id="contact-id" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-brand-primary focus:border-transparent" required onchange="loadContactInvoices()">
                        <option value="">Select Contact</option>
                        <!-- Options will be populated dynamically -->
                    </select>
                </div>
                
                <!-- Invoice Selection -->
                <div id="invoice-selection" class="bg-gray-50 border border-gray-200 rounded-lg p-4 mb-4" style="display: none;">
                    <h4 class="font-medium mb-3 text-gray-800">Select Invoice to Pay</h4>
                    <div id="invoice-options">
                        <!-- Invoice options will be populated here -->
                    </div>
                    <div class="mt-3">
                        <label class="flex items-center">
                            <input type="checkbox" id="no-invoice" class="mr-2">
                            <span class="text-sm text-gray-600">Record payment without invoice</span>
                        </label>
                    </div>
                </div>
                
                <input type="hidden" name="invoice_id" id="selected-invoice-id">
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Payment Date *</label>
                        <input type="date" name="payment_date" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-brand-primary focus:border-transparent" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Amount *</label>
                        <input type="number" name="amount" id="payment-amount" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-brand-primary focus:border-transparent" step="0.01" min="0.01" required>
                    </div>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Payment Method *</label>
                        <select name="payment_method" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-brand-primary focus:border-transparent" required>
                            <option value="">Select Method</option>
                            <option value="Cash">Cash</option>
                            <option value="Check">Check</option>
                            <option value="Bank Transfer">Bank Transfer</option>
                            <option value="Credit Card">Credit Card</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">OR Number *</label>
                        <div class="flex space-x-2">
                            <input type="text" name="reference_number" id="or-number" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-brand-primary focus:border-transparent flex-1" required readonly>
                            <button type="button" onclick="generateOrNumber()" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition" title="Generate New OR Number">
                                <i class='bx bx-refresh'></i>
                            </button>
                        </div>
                        <p class="text-xs text-gray-500 mt-1">OR number is automatically generated</p>
                    </div>
                </div>
                
                <div class="mb-6">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Notes</label>
                    <textarea name="notes" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-brand-primary focus:border-transparent" rows="3" placeholder="Additional payment notes"></textarea>
                </div>
                
                <div id="status-field" class="mb-4" style="display: none;">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Status</label>
                    <select name="status" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-brand-primary focus:border-transparent">
                        <option value="Completed">Completed</option>
                        <option value="Processing">Processing</option>
                        <option value="Scheduled">Scheduled</option>
                        <option value="Cancelled">Cancelled</option>
                    </select>
                </div>
                
                <div class="flex space-x-4">
                    <button type="button" class="flex-1 px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition close-modal">Cancel</button>
                    <button type="submit" class="flex-1 px-4 py-2 bg-brand-primary text-white rounded-lg hover:bg-brand-primary-hover transition">Record Payment</button>
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

        // Notification functionality
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
        
        // Mark all as read functionality
        const markAllReadBtn = document.getElementById('mark-all-read-btn');
        if (markAllReadBtn) {
            markAllReadBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                
                // Make an AJAX call to mark notifications as read
                fetch('?mark_notifications_read=1')
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            // Update UI - remove unread styles and badge
                            const unreadItems = document.querySelectorAll('.bg-blue-50');
                            unreadItems.forEach(item => {
                                item.classList.remove('bg-blue-50');
                                item.classList.add('bg-gray-50');
                                const icon = item.querySelector('i');
                                if (icon) {
                                    icon.classList.remove('bx-bell-ring', 'text-blue-500');
                                    icon.classList.add('bx-bell', 'text-gray-500');
                                }
                            });
                            
                            // Remove blue dots
                            document.querySelectorAll('.bg-blue-500').forEach(dot => dot.remove());
                            
                            // Update badge count
                            const badge = document.querySelector('.notification-badge');
                            if (badge) {
                                badge.remove();
                            }
                            
                            // Hide the mark all read button
                            markAllReadBtn.style.display = 'none';
                        }
                    })
                    .catch(error => {
                        console.error('Error marking notifications as read:', error);
                    });
            });
        }

        // Form submission handling
        const paymentForm = document.getElementById('payment-form');
        if (paymentForm) {
            paymentForm.addEventListener('submit', function(e) {
                const submitBtn = this.querySelector('button[type="submit"]');
                const originalText = submitBtn.innerHTML;
                submitBtn.innerHTML = '<div class="spinner"></div>Saving...';
                submitBtn.disabled = true;
                
                // Allow form to submit normally
            });
        }

        // Initialize contact dropdowns and generate OR number
        updateContactOptions();
        generateOrNumber();
        
        // Initialize payment modal close buttons
        const paymentModal = document.getElementById('payment-modal');
        if (paymentModal) {
            const paymentCloseBtns = paymentModal.querySelectorAll('.close-modal');
            paymentCloseBtns.forEach(btn => {
                btn.addEventListener('click', function() {
                    paymentModal.style.display = 'none';
                    document.body.style.overflow = 'auto';
                });
            });
        }
    });

    // Payment functions
    function openPaymentModal(paymentId = null) {
        const modal = document.getElementById('payment-modal');
        
        if (modal) {
            // Reset form first
            const form = document.getElementById('payment-form');
            if (form) form.reset();
            
            // Generate payment ID and OR number
            updatePaymentNumber();
            generateOrNumber();
            
            // Reset invoice selection
            document.getElementById('invoice-selection').style.display = 'none';
            document.getElementById('selected-invoice-id').value = '';
            const noInvoiceCheckbox = document.getElementById('no-invoice');
            if (noInvoiceCheckbox) noInvoiceCheckbox.checked = false;
            
            // Update contact options
            const paymentType = document.getElementById('payment-type').value;
            if (!paymentType) {
                document.getElementById('payment-type').value = 'Receive'; // Default to Receive
                updatePaymentNumber();
                updateContactOptions('Receive');
            }
            
            // Set default date to today
            const today = new Date().toISOString().split('T')[0];
            const dateInputs = document.querySelectorAll('input[name="payment_date"]');
            dateInputs.forEach(input => {
                if (input) input.value = today;
            });
            
            // Show the modal
            modal.style.display = 'block';
            document.body.style.overflow = 'hidden';
        }
    }

    function updatePaymentNumber() {
        const type = document.getElementById('payment-type').value;
        const numberField = document.getElementById('payment-number');
        
        if (type) {
            // Generate a temporary payment number - in production this would come from the server
            const prefix = type === 'Receive' ? 'PMT' : 'V-PMT';
            const year = new Date().getFullYear();
            const timestamp = Date.now().toString().slice(-6);
            numberField.value = `${prefix}-${year}-${timestamp}`;
        } else {
            numberField.value = '';
        }
        
        // Update contact options based on type
        updateContactOptions(type);
    }

    // Generate OR Number function
    function generateOrNumber() {
        const orNumberField = document.getElementById('or-number');
        orNumberField.value = 'Generating...';
        
        fetch('?generate_or_number=1')
            .then(response => response.json())
            .then(data => {
                if (data.or_number) {
                    orNumberField.value = data.or_number;
                } else {
                    // Fallback if AJAX fails
                    const year = new Date().getFullYear();
                    const timestamp = Date.now().toString().slice(-5);
                    orNumberField.value = `OR-${year}-${timestamp}`;
                }
            })
            .catch(error => {
                console.error('Error generating OR number:', error);
                // Fallback if AJAX fails
                const year = new Date().getFullYear();
                const timestamp = Date.now().toString().slice(-5);
                orNumberField.value = `OR-${year}-${timestamp}`;
            });
    }

    function updateContactOptions(type = '') {
        const contactSelect = document.getElementById('contact-id');
        if (!contactSelect) return;
        
        // Save current value
        const currentValue = contactSelect.value;
        contactSelect.innerHTML = '<option value="">Select Contact</option>';
        
        <?php if (count($customers) > 0): ?>
        if (type === 'Receive' || !type) {
            <?php foreach ($customers as $customer): ?>
            const customerOption = document.createElement('option');
            customerOption.value = "<?php echo $customer['id']; ?>";
            customerOption.textContent = "<?php echo htmlspecialchars($customer['name']); ?>";
            contactSelect.appendChild(customerOption);
            <?php endforeach; ?>
        }
        <?php endif; ?>
        
        <?php if (count($vendors) > 0): ?>
        if (type === 'Make' || !type) {
            <?php foreach ($vendors as $vendor): ?>
            const vendorOption = document.createElement('option');
            vendorOption.value = "<?php echo $vendor['id']; ?>";
            vendorOption.textContent = "<?php echo htmlspecialchars($vendor['name']); ?>";
            contactSelect.appendChild(vendorOption);
            <?php endforeach; ?>
        }
        <?php endif; ?>
        
        // Restore previous value if still available
        if (currentValue) {
            contactSelect.value = currentValue;
        }
    }

    function loadContactInvoices() {
        const contactId = document.getElementById('contact-id').value;
        const paymentType = document.getElementById('payment-type').value;
        const invoiceSelection = document.getElementById('invoice-selection');
        const invoiceOptions = document.getElementById('invoice-options');
        const noInvoiceCheckbox = document.getElementById('no-invoice');
        
        if (contactId) {
            invoiceSelection.style.display = 'block';
            invoiceOptions.innerHTML = '<div class="text-center py-4"><div class="spinner"></div>Loading invoices...</div>';
            
            // Make AJAX call to fetch real invoices for the contact
            fetch(`?get_contact_invoices=1&contact_id=${contactId}&type=${paymentType === 'Receive' ? 'Receivable' : 'Payable'}`)
                .then(response => response.json())
                .then(invoices => {
                    renderInvoiceOptions(invoices);
                })
                .catch(error => {
                    console.error('Error loading invoices:', error);
                    invoiceOptions.innerHTML = '<div class="text-center py-4 text-gray-500">Error loading invoices</div>';
                });
        } else {
            invoiceSelection.style.display = 'none';
        }
        
        if (noInvoiceCheckbox) {
            noInvoiceCheckbox.addEventListener('change', function() {
                if (this.checked) {
                    document.getElementById('selected-invoice-id').value = '';
                    invoiceOptions.style.opacity = '0.5';
                    document.querySelectorAll('.invoice-option').forEach(option => {
                        option.style.pointerEvents = 'none';
                    });
                } else {
                    invoiceOptions.style.opacity = '1';
                    document.querySelectorAll('.invoice-option').forEach(option => {
                        option.style.pointerEvents = 'auto';
                    });
                }
            });
        }
    }

    function renderInvoiceOptions(invoices) {
        const invoiceOptions = document.getElementById('invoice-options');
        
        if (!invoices || invoices.length === 0) {
            invoiceOptions.innerHTML = '<div class="text-center py-4 text-gray-500">No outstanding invoices found for this contact.</div>';
            return;
        }
        
        let html = '';
        invoices.forEach(invoice => {
            const showNumbers = <?php echo $_SESSION['show_numbers'] ? 'true' : 'false'; ?>;
            html += `
                <div class="flex justify-between items-center p-3 border border-gray-200 rounded-lg mb-2 hover:bg-gray-50 cursor-pointer invoice-option" 
                      data-invoice-id="${invoice.id}" 
                      onclick="selectInvoice(this, ${invoice.id}, ${invoice.outstanding_balance})">
                    <div class="flex-1">
                        <div class="font-medium text-gray-800">${invoice.invoice_number || 'No invoice number'}</div>
                        <div class="text-sm text-gray-600">
                            Amount: ${formatNumber(invoice.amount, showNumbers)} | 
                            Outstanding: ${formatNumber(invoice.outstanding_balance, showNumbers)} | 
                            Due: ${invoice.due_date || 'N/A'}
                        </div>
                    </div>
                    <div class="flex items-center">
                        <input type="radio" name="invoice_radio" class="mr-2" id="invoice_${invoice.id}">
                    </div>
                </div>
            `;
        });
        
        invoiceOptions.innerHTML = html;
    }

    function selectInvoice(element, invoiceId, outstandingBalance) {
        document.getElementById('selected-invoice-id').value = invoiceId;
        document.getElementById('payment-amount').value = outstandingBalance;
        document.getElementById('payment-amount').max = outstandingBalance;
        
        // Update UI to show selected invoice
        document.querySelectorAll('.invoice-option').forEach(option => {
            option.classList.remove('border-brand-primary', 'bg-blue-50');
        });
        element.classList.add('border-brand-primary', 'bg-blue-50');
        element.querySelector('input[type="radio"]').checked = true;
    }

    function viewInvoice(invoiceId) {
        if (invoiceId && invoiceId !== 'null' && invoiceId !== '') {
            // Redirect to invoices.php with the specific invoice ID
            window.location.href = `invoices.php?view_invoice=${invoiceId}`;
        } else {
            // If no invoice is linked, redirect to general invoices page
            window.location.href = 'invoices.php';
        }
    }

    function deletePayment(paymentId, paymentNumber) {
        if (confirm(`Are you sure you want to delete payment "${paymentNumber}"? This action cannot be undone.`)) {
            // Create a form and submit it to delete the payment
            const form = document.createElement('form');
            form.method = 'POST';
            form.style.display = 'none';
            
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'delete_payment';
            form.appendChild(actionInput);
            
            const paymentIdInput = document.createElement('input');
            paymentIdInput.type = 'hidden';
            paymentIdInput.name = 'payment_id';
            paymentIdInput.value = paymentId;
            form.appendChild(paymentIdInput);

            const csrfInput = document.createElement('input');
            csrfInput.type = 'hidden';
            csrfInput.name = 'csrf_token';
            csrfInput.value = '<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>';
            form.appendChild(csrfInput);
            
            document.body.appendChild(form);
            form.submit();
        }
    }

    // Format number function for JavaScript - Pinalitan ng dots
    function formatNumber(number, showNumbers) {
        if (showNumbers) {
            return '₱' + parseFloat(number).toFixed(2).replace(/\d(?=(\d{3})+\.)/g, '$&,');
        } else {
            return '₱ ••••••••';
        }
    }
    </script>
</body>
</html>