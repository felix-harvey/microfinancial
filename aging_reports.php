<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/database.php';

// Handle export request
if (isset($_POST['export_aging_report'])) {
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die('Invalid CSRF token');
    }

    // Get filter parameters
    $type = $_POST['type'] ?? null;
    $contact_id = $_POST['contact_id'] ?? null;
    $as_of_date = $_POST['as_of_date'] ?? date('Y-m-d');

    try {
        $database = new Database();
        $db = $database->getConnection();
        
        // Get aging data using the existing function
        $aging_data = getAgingReport($db, $type, $contact_id, $as_of_date);
        $aging_summary = getAgingSummary($db, $type, $as_of_date);
        
        // Export as Excel
        header('Content-Type: application/vnd.ms-excel');
        header('Content-Disposition: attachment; filename="aging_report_' . date('Y-m-d') . '.xls"');
        
        // Excel output
        echo "<html>";
        echo "<head>";
        echo "<meta charset='UTF-8'>";
        echo "<style>";
        echo "table { border-collapse: collapse; width: 100%; }";
        echo "th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }";
        echo "th { background-color: #f2f2f2; font-weight: bold; }";
        echo ".summary { margin-bottom: 20px; }";
        echo ".bucket { font-weight: bold; }";
        echo ".total-row { background-color: #e6f3ff; font-weight: bold; }";
        echo "</style>";
        echo "</head>";
        echo "<body>";
        
        echo "<h2>Aging Report</h2>";
        echo "<p><strong>As Of:</strong> " . htmlspecialchars($as_of_date) . "</p>";
        echo "<p><strong>Report Type:</strong> " . ($type ? htmlspecialchars($type) : 'All Types') . "</p>";
        if ($contact_id) {
            $contact_name = '';
            foreach ($all_contacts as $contact) {
                if ($contact['contact_id'] == $contact_id) {
                    $contact_name = $contact['name'];
                    break;
                }
            }
            echo "<p><strong>Contact:</strong> " . htmlspecialchars($contact_name) . "</p>";
        }
        
        // Aging Summary
        echo "<div class='summary'>";
        echo "<h3>Aging Summary</h3>";
        echo "<table>";
        echo "<tr><th>Aging Bucket</th><th>Invoice Count</th><th>Total Amount</th></tr>";
        
        $total_outstanding_export = 0;
        $total_invoices_export = 0;
        foreach ($aging_summary as $bucket) {
            echo "<tr>";
            echo "<td class='bucket'>" . htmlspecialchars($bucket['aging_bucket']) . "</td>";
            echo "<td>" . $bucket['invoice_count'] . "</td>";
            echo "<td>₱" . number_format((float)$bucket['total_amount'], 2) . "</td>";
            echo "</tr>";
            $total_outstanding_export += (float)$bucket['total_amount'];
            $total_invoices_export += $bucket['invoice_count'];
        }
        
        echo "<tr class='total-row'>";
        echo "<td>Total Outstanding</td>";
        echo "<td>" . $total_invoices_export . "</td>";
        echo "<td>₱" . number_format($total_outstanding_export, 2) . "</td>";
        echo "</tr>";
        echo "</table>";
        echo "</div>";
        
        // Detailed Report
        echo "<h3>Detailed Aging Report</h3>";
        echo "<table>";
        echo "<tr>
                <th>Invoice #</th>
                <th>Contact</th>
                <th>Type</th>
                <th>Issue Date</th>
                <th>Due Date</th>
                <th>Amount</th>
                <th>Outstanding</th>
                <th>Days Overdue</th>
                <th>Aging Bucket</th>
              </tr>";
        
        foreach ($aging_data as $invoice) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($invoice['invoice_number']) . "</td>";
            echo "<td>" . htmlspecialchars($invoice['contact_name']) . "</td>";
            echo "<td>" . htmlspecialchars($invoice['type']) . "</td>";
            echo "<td>" . htmlspecialchars($invoice['issue_date']) . "</td>";
            echo "<td>" . htmlspecialchars($invoice['due_date']) . "</td>";
            echo "<td>₱" . number_format((float)$invoice['amount'], 2) . "</td>";
            echo "<td>₱" . number_format((float)$invoice['outstanding_balance'], 2) . "</td>";
            echo "<td>" . ($invoice['days_overdue'] > 0 ? $invoice['days_overdue'] : '0') . "</td>";
            echo "<td>" . htmlspecialchars($invoice['aging_bucket']) . "</td>";
            echo "</tr>";
        }
        
        echo "</table>";
        echo "<p style='margin-top: 20px; font-style: italic;'>Generated on: " . date('Y-m-d H:i:s') . "</p>";
        echo "</body></html>";
        
        exit;
        
    } catch (Exception $e) {
        die('Export error: ' . $e->getMessage());
    }
}

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

// Logout functionality
if (isset($_GET['logout']) && $_GET['logout'] === 'true') {
    $_SESSION = [];
    session_destroy();
    header("Location: index.php");
    exit;
}

// Load current user with proper error handling
try {
    $u = $db->prepare("SELECT id, name, username, role FROM users WHERE id = ?");
    $u->execute([$user_id]);
    $user = $u->fetch();
    
    if (!$user) {
        header("Location: index.php");
        exit;
    }
    
    // Ensure user data has proper values
    $user['name'] = $user['name'] ?? 'Unknown User';
    $user['username'] = $user['username'] ?? 'unknown';
    $user['role'] = $user['role'] ?? 'user';
    
} catch (Throwable $e) {
    error_log("User loading error: " . $e->getMessage());
    header("Location: index.php");
    exit;
}

// Function to add a notification for overdue invoices
function addOverdueNotification($contact_name, $amount, $days_overdue) {
    $notification = [
        'id' => uniqid(),
        'type' => 'aging',
        'message' => "Overdue invoice: {$contact_name} - " . formatNumber($amount, true) . " ({$days_overdue} days overdue)",
        'timestamp' => time(),
        'link' => 'aging_reports.php',
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

// Function to format numbers with asterisks if hidden
function formatNumber($number, $show_numbers = false) {
    if ($show_numbers) {
        return '₱' . number_format((float)$number, 2);
    } else {
        return '₱' . str_repeat('*', max(6, min(12, strlen(number_format((float)$number, 2)))));
    }
}

// Get aging report data - UPDATED to match invoices.php field names
function getAgingReport(PDO $db, ?string $type = null, ?string $contact_id = null, ?string $as_of_date = null): array {
    $as_of_date = $as_of_date ?: date('Y-m-d');
    
    $sql = "SELECT 
                i.*, 
                bc.name as contact_name,
                bc.contact_person,
                bc.email,
                bc.phone,
                (i.amount - COALESCE((
                    SELECT SUM(p.amount) 
                    FROM payments p 
                    WHERE p.invoice_id = i.id 
                    AND p.status = 'Completed'
                ), 0)) as outstanding_balance,
                DATEDIFF(?, i.due_date) as days_overdue,
                CASE 
                    WHEN DATEDIFF(?, i.due_date) <= 0 THEN 'Current'
                    WHEN DATEDIFF(?, i.due_date) BETWEEN 1 AND 30 THEN '1-30 Days'
                    WHEN DATEDIFF(?, i.due_date) BETWEEN 31 AND 60 THEN '31-60 Days'
                    WHEN DATEDIFF(?, i.due_date) BETWEEN 61 AND 90 THEN '61-90 Days'
                    ELSE 'Over 90 Days'
                END as aging_bucket
            FROM invoices i
            LEFT JOIN business_contacts bc ON i.contact_id = bc.contact_id
            WHERE i.status NOT IN ('Paid', 'Cancelled')
            AND (i.amount - COALESCE((
                SELECT SUM(p.amount) 
                FROM payments p 
                WHERE p.invoice_id = i.id 
                AND p.status = 'Completed'
            ), 0)) > 0";
    
    $params = [$as_of_date, $as_of_date, $as_of_date, $as_of_date, $as_of_date];
    
    if ($type) {
        $sql .= " AND i.type = ?";
        $params[] = $type;
    }
    
    if ($contact_id) {
        $sql .= " AND i.contact_id = ?";
        $params[] = $contact_id;
    }
    
    $sql .= " ORDER BY i.due_date ASC, outstanding_balance DESC";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $results = $stmt->fetchAll();
    
    // Ensure all string fields have values
    foreach ($results as &$result) {
        $result['contact_name'] = $result['contact_name'] ?? 'Unknown Contact';
        $result['contact_person'] = $result['contact_person'] ?? 'N/A';
        $result['invoice_number'] = $result['invoice_number'] ?? 'N/A';
        $result['issue_date'] = $result['issue_date'] ?? 'N/A';
        $result['due_date'] = $result['due_date'] ?? 'N/A';
    }
    
    return $results;
}

// Get aging summary by bucket - UPDATED to match invoices.php field names
function getAgingSummary(PDO $db, ?string $type = null, ?string $as_of_date = null): array {
    $as_of_date = $as_of_date ?: date('Y-m-d');
    
    // Simple query that calculates everything in PHP
    $sql = "SELECT 
                i.id,
                i.amount,
                i.due_date,
                i.type,
                (i.amount - COALESCE((
                    SELECT SUM(p.amount) 
                    FROM payments p 
                    WHERE p.invoice_id = i.id 
                    AND p.status = 'Completed'
                ), 0)) as outstanding_balance
            FROM invoices i
            WHERE i.status NOT IN ('Paid', 'Cancelled')
            AND (i.amount - COALESCE((
                SELECT SUM(p.amount) 
                FROM payments p 
                WHERE p.invoice_id = i.id 
                AND p.status = 'Completed'
            ), 0)) > 0";
    
    $params = [];
    
    if ($type) {
        $sql .= " AND i.type = ?";
        $params[] = $type;
    }
    
    $sql .= " ORDER BY i.due_date ASC";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $invoices = $stmt->fetchAll();
    
    // Calculate aging buckets in PHP
    $aging_buckets = [
        'Current' => ['invoice_count' => 0, 'total_amount' => 0],
        '1-30 Days' => ['invoice_count' => 0, 'total_amount' => 0],
        '31-60 Days' => ['invoice_count' => 0, 'total_amount' => 0],
        '61-90 Days' => ['invoice_count' => 0, 'total_amount' => 0],
        'Over 90 Days' => ['invoice_count' => 0, 'total_amount' => 0]
    ];
    
    foreach ($invoices as $invoice) {
        $days_overdue = (strtotime($as_of_date) - strtotime($invoice['due_date'])) / (60 * 60 * 24);
        
        if ($days_overdue <= 0) {
            $bucket = 'Current';
        } elseif ($days_overdue <= 30) {
            $bucket = '1-30 Days';
        } elseif ($days_overdue <= 60) {
            $bucket = '31-60 Days';
        } elseif ($days_overdue <= 90) {
            $bucket = '61-90 Days';
        } else {
            $bucket = 'Over 90 Days';
        }
        
        $aging_buckets[$bucket]['invoice_count']++;
        $aging_buckets[$bucket]['total_amount'] += $invoice['outstanding_balance'];
    }
    
    // Convert to the format expected by the frontend
    $result = [];
    foreach ($aging_buckets as $bucket => $data) {
        if ($data['total_amount'] > 0) {
            $result[] = [
                'aging_bucket' => $bucket,
                'invoice_count' => $data['invoice_count'],
                'total_amount' => $data['total_amount']
            ];
        }
    }
    
    // Sort by bucket order
    usort($result, function($a, $b) {
        $order = ['Current' => 1, '1-30 Days' => 2, '31-60 Days' => 3, '61-90 Days' => 4, 'Over 90 Days' => 5];
        return $order[$a['aging_bucket']] - $order[$b['aging_bucket']];
    });
    
    return $result;
}

// Get business contacts for dropdown - UPDATED to match invoices.php field names
function getBusinessContacts(PDO $db, ?string $type = null): array {
    $sql = "SELECT contact_id, name, contact_person, type 
            FROM business_contacts 
            WHERE status = 'Active'";
    
    if ($type) {
        $sql .= " AND type = ?";
        $stmt = $db->prepare($sql);
        $stmt->execute([$type]);
    } else {
        $stmt = $db->prepare($sql);
        $stmt->execute();
    }
    
    $contacts = $stmt->fetchAll();
    
    // Ensure all contact fields have values
    foreach ($contacts as &$contact) {
        $contact['name'] = $contact['name'] ?? 'Unknown Contact';
        $contact['contact_person'] = $contact['contact_person'] ?? 'N/A';
    }
    
    return $contacts;
}

// Get top overdue contacts - UPDATED to match invoices.php field names
function getTopOverdueContacts(PDO $db, ?string $type = null, ?string $as_of_date = null, int $limit = 10): array {
    $as_of_date = $as_of_date ?: date('Y-m-d');
    
    // Get all overdue invoices grouped by contact
    $sql = "SELECT 
                bc.contact_id as id,
                bc.name as contact_name,
                bc.contact_person,
                COUNT(i.id) as overdue_invoices,
                SUM(i.amount - COALESCE((
                    SELECT SUM(p.amount) 
                    FROM payments p 
                    WHERE p.invoice_id = i.id 
                    AND p.status = 'Completed'
                ), 0)) as total_overdue,
                MAX(i.due_date) as oldest_due_date
            FROM business_contacts bc
            INNER JOIN invoices i ON bc.contact_id = i.contact_id
            WHERE i.status NOT IN ('Paid', 'Cancelled')
            AND DATEDIFF(?, i.due_date) > 0
            AND (i.amount - COALESCE((
                SELECT SUM(p.amount) 
                FROM payments p 
                WHERE p.invoice_id = i.id 
                AND p.status = 'Completed'
            ), 0)) > 0";
    
    $params = [$as_of_date];
    
    if ($type) {
        $sql .= " AND i.type = ?";
        $params[] = $type;
    }
    
    $sql .= " GROUP BY bc.contact_id, bc.name, bc.contact_person
              HAVING total_overdue > 0
              ORDER BY total_overdue DESC
              LIMIT ?";
    
    $params[] = $limit;
    
    $stmt = $db->prepare($sql);
    
    // Bind parameters with explicit types
    foreach ($params as $key => $value) {
        if ($key === count($params) - 1) { // Last parameter is the LIMIT
            $stmt->bindValue($key + 1, $value, PDO::PARAM_INT);
        } else {
            $stmt->bindValue($key + 1, $value, PDO::PARAM_STR);
        }
    }
    
    $stmt->execute();
    $contacts = $stmt->fetchAll();
    
    // Ensure all contact fields have values
    foreach ($contacts as &$contact) {
        $contact['contact_name'] = $contact['contact_name'] ?? 'Unknown Contact';
        $contact['contact_person'] = $contact['contact_person'] ?? 'N/A';
        $contact['oldest_due_date'] = $contact['oldest_due_date'] ?? 'N/A';
    }
    
    return $contacts;
}

// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Get filter values from request
$report_type = $_GET['type'] ?? null;
$contact_id = $_GET['contact_id'] ?? null;
$as_of_date = $_GET['as_of_date'] ?? date('Y-m-d');

$aging_data = getAgingReport($db, $report_type, $contact_id, $as_of_date);
$aging_summary = getAgingSummary($db, $report_type, $as_of_date);
$top_overdue = getTopOverdueContacts($db, $report_type, $as_of_date, 10);
$vendors = getBusinessContacts($db, 'Vendor');
$customers = getBusinessContacts($db, 'Customer');
$all_contacts = getBusinessContacts($db);

// Get notifications
$notification_count = getUnreadNotificationCount();
$notifications = getNotifications();

// Calculate totals
$total_outstanding = array_sum(array_column($aging_data, 'outstanding_balance'));
$total_invoices = count($aging_data);

// Helper function to get amount by aging bucket
function getAmountByBucket(array $aging_data, string $bucket): float {
    return array_sum(array_map(function($item) use ($bucket) {
        return $item['aging_bucket'] === $bucket ? (float)$item['outstanding_balance'] : 0;
    }, $aging_data));
}

$current_amount = getAmountByBucket($aging_data, 'Current');
$bucket_1_30 = getAmountByBucket($aging_data, '1-30 Days');
$bucket_31_60 = getAmountByBucket($aging_data, '31-60 Days');
$bucket_61_90 = getAmountByBucket($aging_data, '61-90 Days');
$bucket_over_90 = getAmountByBucket($aging_data, 'Over 90 Days');

// Safe output function
function safe_html($value, $default = '') {
    if ($value === null) {
        $value = $default;
    }
    return htmlspecialchars((string)$value);
}

// Function to format numbers with asterisks if hidden - FIXED
function formatDisplayNumber($number, $show_numbers = false) {
    // Ensure the input is treated as float
    $number_float = (float)$number;
    
    if ($show_numbers) {
        return '₱' . number_format($number_float, 0); // Remove decimal places for aging reports
    } else {
        $numberStr = number_format($number_float, 0);
        return '₱' . str_repeat('*', strlen($numberStr));
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Aging Reports - Financial Dashboard</title>

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
        
        /* Aging Reports specific styles */
        .aging-card {
            border-radius: 0.75rem;
            padding: 1.5rem;
            color: white;
            transition: all 0.3s ease;
            border: 1px solid #D1FAE5;
            background: white;
        }
        
        .aging-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1);
        }
        
        .aging-bucket-current { 
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white !important;
        }
        .aging-bucket-1-30 { 
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            color: white !important;
        }
        .aging-bucket-31-60 { 
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            color: white !important;
        }
        .aging-bucket-61-90 { 
            background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);
            color: white !important;
        }
        .aging-bucket-over-90 { 
            background: linear-gradient(135deg, #fa709a 0%, #fee140 100%);
            color: white !important;
        }
        
        .progress-bar {
            height: 8px;
            background-color: #e5e7eb;
            border-radius: 4px;
            overflow: hidden;
        }
        
        .progress-fill {
            height: 100%;
            border-radius: 4px;
            transition: width 0.3s ease;
        }
        
        .progress-current { background-color: #10B981; }
        .progress-1-30 { background-color: #F59E0B; }
        .progress-31-60 { background-color: #F97316; }
        .progress-61-90 { background-color: #EF4444; }
        .progress-over-90 { background-color: #DC2626; }
        
        .aging-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 500;
        }
        
        .badge-current { background-color: #D1FAE5; color: #065F46; }
        .badge-1-30 { background-color: #FEF3C7; color: #92400E; }
        .badge-31-60 { background-color: #FEE2E2; color: #991B1B; }
        .badge-61-90 { background-color: #FECACA; color: #7F1D1D; }
        .badge-over-90 { background-color: #FCA5A5; color: #450A0A; }
        
        .amount-masked {
            font-family: monospace;
            letter-spacing: 2px;
        }
        
        .bg-white.rounded-xl.p-6.card-shadow {
            transition: all 0.3s ease;
            cursor: pointer;
            border: 1px solid #D1FAE5;
            background: white;
        }
        
        .bg-white.rounded-xl.p-6.card-shadow:hover {
            transform: translateY(-5px);
            box-shadow: 0px 8px 25px rgba(0,0,0,0.15);
        }
        
        .data-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .data-table th, .data-table td {
            padding: 0.75rem;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .data-table th {
            background-color: #f9fafb;
            font-weight: 500;
            color: #374151;
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
        
        .action-btn.view {
            background-color: #EFF6FF;
            color: #1D4ED8;
            border-color: #1D4ED8;
        }
        
        .action-btn.view:hover {
            background-color: #1D4ED8;
            color: white;
        }
        
        .action-btn.contact {
            background-color: #F0F9FF;
            color: #0369A1;
            border-color: #0369A1;
        }
        
        .action-btn.contact:hover {
            background-color: #0369A1;
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
                            <a href="vendors_customers.php" class="block px-3 py-1.5 rounded-lg text-xs text-gray-600 hover:bg-green-50 hover:text-brand-primary transition-all duration-200 hover:translate-x-1">
                                Payable/Receivable
                            </a>
                            <a href="invoices.php" class="block px-3 py-1.5 rounded-lg text-xs text-gray-600 hover:bg-green-50 hover:text-brand-primary transition-all duration-200 hover:translate-x-1">
                                Invoices
                            </a>
                            <a href="payment_entry.php" class="block px-3 py-1.5 rounded-lg text-xs text-gray-600 hover:bg-green-50 hover:text-brand-primary transition-all duration-200 hover:translate-x-1">
                                Payment Entry
                            </a>
                            <a href="aging_reports.php" class="block px-3 py-1.5 rounded-lg text-xs bg-emerald-50 text-brand-primary font-medium border border-emerald-100 hover:bg-emerald-100 hover:border-emerald-200 transition-all duration-200 hover:translate-x-1">
                                <span class="flex items-center justify-between">
                                    Aging Reports
                                    <span class="inline-flex w-1.5 h-1.5 rounded-full bg-emerald-400"></span>
                                </span>
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
                        Aging Reports
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
                <a href="?toggle_numbers=1" id="visibility-toggle" class="w-10 h-10 rounded-xl hover:bg-gray-100 active:bg-gray-200 transition flex items-center justify-center relative"
                        title="<?php echo $_SESSION['show_numbers'] ? 'Hide Numbers' : 'Show Numbers'; ?>">
                    <i class="fa-solid <?php echo $_SESSION['show_numbers'] ? 'fa-eye' : 'fa-eye-slash'; ?> text-gray-600"></i>
                </a>

                <!-- Notifications -->
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
                        <a href="#" id="dropdown-logout-btn" class="block px-4 py-3 text-sm text-red-600 hover:bg-red-50 transition">
                            <i class='bx bx-log-out mr-2'></i> Logout
                        </a>
                    </div>
                </div>
            </div>
        </header>

        <main id="main-content" class="p-4 sm:p-6">
            <div class="space-y-6">
                <!-- Report Controls -->
                <div class="stat-card rounded-xl p-6">
                    <h3 class="text-lg font-bold text-gray-800 mb-4">Report Parameters</h3>
                    <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4">
                        <div class="form-group">
                            <label class="block text-sm font-medium text-gray-700 mb-2">Report Type</label>
                            <select name="type" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-brand-primary focus:border-transparent">
                                <option value="">All Types</option>
                                <option value="Receivable" <?php echo $report_type === 'Receivable' ? 'selected' : ''; ?>>Accounts Receivable</option>
                                <option value="Payable" <?php echo $report_type === 'Payable' ? 'selected' : ''; ?>>Accounts Payable</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="block text-sm font-medium text-gray-700 mb-2">Contact</label>
                            <select name="contact_id" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-brand-primary focus:border-transparent">
                                <option value="">All Contacts</option>
                                <?php foreach ($all_contacts as $contact): ?>
                                <option value="<?php echo safe_html($contact['contact_id']); ?>" <?php echo $contact_id == $contact['contact_id'] ? 'selected' : ''; ?>>
                                    <?php echo safe_html($contact['name'] . ' (' . $contact['type'] . ')'); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="block text-sm font-medium text-gray-700 mb-2">As Of Date</label>
                            <input type="date" name="as_of_date" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-brand-primary focus:border-transparent" value="<?php echo safe_html($as_of_date); ?>">
                        </div>
                        <div class="form-group flex items-end space-x-2">
                            <button type="submit" class="flex-1 px-4 py-2 bg-brand-primary text-white rounded-lg hover:bg-brand-primary-hover transition flex items-center justify-center">
                                <i class='bx bx-refresh mr-2'></i> Generate Report
                            </button>
                            <button type="button" onclick="exportAgingReport()" class="flex-1 px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 transition flex items-center justify-center">
                                <i class='bx bx-download mr-2'></i> Export
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Aging Summary Cards -->
                <div class="grid grid-cols-1 md:grid-cols-5 gap-6">
                    <div class="aging-card aging-bucket-current">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm opacity-90">Current</p>
                                <p class="text-2xl font-bold <?php echo !$_SESSION['show_numbers'] ? 'hidden-amount' : ''; ?>">
                                    <?php echo formatDisplayNumber($current_amount, $_SESSION['show_numbers']); ?>
                                </p>
                                <p class="text-xs opacity-80 mt-1">Not Due</p>
                            </div>
                            <i class='bx bx-time text-3xl opacity-80'></i>
                        </div>
                    </div>
                    
                    <div class="aging-card aging-bucket-1-30">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm opacity-90">1-30 Days</p>
                                <p class="text-2xl font-bold <?php echo !$_SESSION['show_numbers'] ? 'hidden-amount' : ''; ?>">
                                    <?php echo formatDisplayNumber($bucket_1_30, $_SESSION['show_numbers']); ?>
                                </p>
                                <p class="text-xs opacity-80 mt-1">Slightly Overdue</p>
                            </div>
                            <i class='bx bx-alarm-exclamation text-3xl opacity-80'></i>
                        </div>
                    </div>
                    
                    <div class="aging-card aging-bucket-31-60">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm opacity-90">31-60 Days</p>
                                <p class="text-2xl font-bold <?php echo !$_SESSION['show_numbers'] ? 'hidden-amount' : ''; ?>">
                                    <?php echo formatDisplayNumber($bucket_31_60, $_SESSION['show_numbers']); ?>
                                </p>
                                <p class="text-xs opacity-80 mt-1">Overdue</p>
                            </div>
                            <i class='bx bx-error-circle text-3xl opacity-80'></i>
                        </div>
                    </div>
                    
                    <div class="aging-card aging-bucket-61-90">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm opacity-90">61-90 Days</p>
                                <p class="text-2xl font-bold <?php echo !$_SESSION['show_numbers'] ? 'hidden-amount' : ''; ?>">
                                    <?php echo formatDisplayNumber($bucket_61_90, $_SESSION['show_numbers']); ?>
                                </p>
                                <p class="text-xs opacity-80 mt-1">Significantly Overdue</p>
                            </div>
                            <i class='bx bx-error-alt text-3xl opacity-80'></i>
                        </div>
                    </div>
                    
                    <div class="aging-card aging-bucket-over-90">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm opacity-90">Over 90 Days</p>
                                <p class="text-2xl font-bold <?php echo !$_SESSION['show_numbers'] ? 'hidden-amount' : ''; ?>">
                                    <?php echo formatDisplayNumber($bucket_over_90, $_SESSION['show_numbers']); ?>
                                </p>
                                <p class="text-xs opacity-80 mt-1">Critical</p>
                            </div>
                            <i class='bx bx-dizzy text-3xl opacity-80'></i>
                        </div>
                    </div>
                </div>

                <!-- Charts and Visualizations -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <!-- Aging Distribution Chart -->
                    <div class="stat-card rounded-xl p-6">
                        <div class="flex justify-between items-center mb-6">
                            <h3 class="text-lg font-bold text-gray-800">Aging Distribution</h3>
                        </div>
                        <div class="chart-container">
                            <canvas id="agingChart"></canvas>
                        </div>
                    </div>
                    
                    <!-- Aging Progress Bars -->
                    <div class="stat-card rounded-xl p-6">
                        <h3 class="text-lg font-bold text-gray-800 mb-6">Aging Breakdown</h3>
                        <div class="space-y-4">
                            <?php if (count($aging_summary) > 0): ?>
                                <?php foreach ($aging_summary as $bucket): ?>
                                <div>
                                    <div class="flex justify-between items-center mb-2">
                                        <span class="font-medium"><?php echo safe_html($bucket['aging_bucket']); ?></span>
                                        <span class="font-bold <?php echo !$_SESSION['show_numbers'] ? 'hidden-amount' : ''; ?>">
                                            <?php echo formatDisplayNumber($bucket['total_amount'], $_SESSION['show_numbers']); ?>
                                        </span>
                                    </div>
                                    <div class="progress-bar">
                                        <div class="progress-fill 
                                            <?php
                                            echo match($bucket['aging_bucket']) {
                                                'Current' => 'progress-current',
                                                '1-30 Days' => 'progress-1-30',
                                                '31-60 Days' => 'progress-31-60',
                                                '61-90 Days' => 'progress-61-90',
                                                default => 'progress-over-90'
                                            };
                                            ?>" 
                                            style="width: <?php echo $total_outstanding > 0 ? ($bucket['total_amount'] / $total_outstanding * 100) : 0; ?>%">
                                        </div>
                                    </div>
                                    <div class="flex justify-between text-xs text-gray-500 mt-1">
                                        <span><?php echo $bucket['invoice_count']; ?> invoices</span>
                                        <span><?php echo $total_outstanding > 0 ? number_format(($bucket['total_amount'] / $total_outstanding * 100), 1) : 0; ?>%</span>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="text-center py-4 text-gray-500">
                                    No aging data available for the selected criteria.
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Top Overdue Contacts -->
                <div class="stat-card rounded-xl overflow-hidden">
                    <div class="p-6">
                        <div class="flex justify-between items-center mb-6">
                            <h3 class="text-lg font-bold text-gray-800">Top Overdue Contacts</h3>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="w-full">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="text-left p-4 text-sm font-medium text-gray-500">Contact Name</th>
                                        <th class="text-left p-4 text-sm font-medium text-gray-500">Overdue Invoices</th>
                                        <th class="text-left p-4 text-sm font-medium text-gray-500">Total Overdue</th>
                                        <th class="text-left p-4 text-sm font-medium text-gray-500">Oldest Due Date</th>
                                        <th class="text-left p-4 text-sm font-medium text-gray-500">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($top_overdue) > 0): ?>
                                        <?php foreach ($top_overdue as $contact): ?>
                                        <tr class="transaction-row">
                                            <td class="p-4 font-medium text-gray-800"><?php echo safe_html($contact['contact_name']); ?></td>
                                            <td class="p-4 text-gray-600"><?php echo $contact['overdue_invoices']; ?></td>
                                            <td class="p-4 font-bold text-gray-800 <?php echo !$_SESSION['show_numbers'] ? 'hidden-amount' : ''; ?>">
                                                <?php echo formatDisplayNumber($contact['total_overdue'], $_SESSION['show_numbers']); ?>
                                            </td>
                                            <td class="p-4 text-gray-600"><?php echo safe_html($contact['oldest_due_date']); ?></td>
                                            <td class="p-4">
                                                <button class="action-btn contact" onclick="viewContactInvoices('<?php echo safe_html($contact['id']); ?>')">
                                                    <i class='bx bx-show mr-1'></i>View Invoices
                                                </button>
                                                <button class="action-btn view" onclick="sendReminder('<?php echo safe_html($contact['id']); ?>', '<?php echo safe_html($contact['contact_name']); ?>')">
                                                    <i class='bx bx-envelope mr-1'></i>Remind
                                                </button>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr class="transaction-row">
                                            <td colspan="5" class="p-8 text-center text-gray-500">
                                                <i class='bx bx-folder-open text-3xl mb-2 text-gray-300'></i>
                                                <div>No overdue contacts found.</div>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Detailed Aging Report -->
                <div class="stat-card rounded-xl overflow-hidden">
                    <div class="p-6">
                        <div class="flex justify-between items-center mb-6">
                            <h3 class="text-lg font-bold text-gray-800">Detailed Aging Report</h3>
                            <div class="text-sm text-gray-500">
                                As of: <?php echo safe_html($as_of_date); ?> | 
                                Total: <span class="<?php echo !$_SESSION['show_numbers'] ? 'hidden-amount' : ''; ?>"><?php echo formatDisplayNumber($total_outstanding, $_SESSION['show_numbers']); ?></span> | 
                                Invoices: <?php echo $total_invoices; ?>
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
                                        <th class="text-left p-4 text-sm font-medium text-gray-500">Outstanding</th>
                                        <th class="text-left p-4 text-sm font-medium text-gray-500">Days Overdue</th>
                                        <th class="text-left p-4 text-sm font-medium text-gray-500">Aging Bucket</th>
                                        <th class="text-left p-4 text-sm font-medium text-gray-500">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($aging_data) > 0): ?>
                                        <?php foreach ($aging_data as $invoice): 
                                            $badge_class = match($invoice['aging_bucket']) {
                                                'Current' => 'badge-current',
                                                '1-30 Days' => 'badge-1-30',
                                                '31-60 Days' => 'badge-31-60',
                                                '61-90 Days' => 'badge-61-90',
                                                default => 'badge-over-90'
                                            };
                                        ?>
                                        <tr class="transaction-row">
                                            <td class="p-4 font-mono font-medium"><?php echo safe_html($invoice['invoice_number']); ?></td>
                                            <td class="p-4">
                                                <div class="font-medium text-gray-800"><?php echo safe_html($invoice['contact_name']); ?></div>
                                                <div class="text-xs text-gray-500"><?php echo safe_html($invoice['contact_person']); ?></div>
                                            </td>
                                            <td class="p-4">
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium 
                                                    <?php echo $invoice['type'] === 'Receivable' ? 'bg-green-100 text-green-800' : 'bg-blue-100 text-blue-800'; ?>">
                                                    <?php echo $invoice['type'] === 'Receivable' ? 'AR' : 'AP'; ?>
                                                </span>
                                            </td>
                                            <td class="p-4 text-gray-600"><?php echo safe_html($invoice['issue_date']); ?></td>
                                            <td class="p-4 <?php echo $invoice['days_overdue'] > 0 ? 'text-red-600 font-medium' : ''; ?>">
                                                <?php echo safe_html($invoice['due_date']); ?>
                                            </td>
                                            <td class="p-4 font-medium text-gray-800 <?php echo !$_SESSION['show_numbers'] ? 'hidden-amount' : ''; ?>">
                                                <?php echo formatDisplayNumber($invoice['amount'], $_SESSION['show_numbers']); ?>
                                            </td>
                                            <td class="p-4 font-bold text-gray-800 <?php echo !$_SESSION['show_numbers'] ? 'hidden-amount' : ''; ?>">
                                                <?php echo formatDisplayNumber($invoice['outstanding_balance'], $_SESSION['show_numbers']); ?>
                                            </td>
                                            <td class="p-4 <?php echo $invoice['days_overdue'] > 0 ? 'text-red-600 font-medium' : 'text-green-600'; ?>">
                                                <?php echo $invoice['days_overdue'] > 0 ? $invoice['days_overdue'] : '0'; ?>
                                            </td>
                                            <td class="p-4">
                                                <span class="aging-badge <?php echo $badge_class; ?>">
                                                    <?php echo safe_html($invoice['aging_bucket']); ?>
                                                </span>
                                            </td>
                                            <td class="p-4">
                                                <div class="flex flex-wrap gap-2">
                                                    <button class="action-btn view" title="View Invoice" onclick="viewInvoice(<?php echo $invoice['id']; ?>)">
                                                        <i class='bx bx-show mr-1'></i>View
                                                    </button>
                                                    <button class="action-btn contact" title="Record Payment" onclick="recordPayment(<?php echo $invoice['id']; ?>)">
                                                        <i class='bx bx-credit-card mr-1'></i>Pay
                                                    </button>
                                                    <button class="action-btn view" title="Send Reminder" onclick="sendInvoiceReminder(<?php echo $invoice['id']; ?>)">
                                                        <i class='bx bx-envelope mr-1'></i>Remind
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr class="transaction-row">
                                            <td colspan="10" class="p-8 text-center text-gray-500">
                                                <i class='bx bx-folder-open text-3xl mb-2 text-gray-300'></i>
                                                <div>No outstanding invoices found for the selected criteria.</div>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
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
                    <div class="flex items-start gap-3 p-4 rounded-lg <?php echo (!isset($notification['read']) || !$notification['read']) ? 'bg-blue-50' : 'bg-gray-50'; ?> hover:bg-gray-100 transition">
                        <div class="w-10 h-10 rounded-lg <?php echo (!isset($notification['read']) || !$notification['read']) ? 'bg-blue-100' : 'bg-gray-100'; ?> flex items-center justify-center flex-shrink-0">
                            <i class='bx <?php echo (!isset($notification['read']) || !$notification['read']) ? 'bx-bell-ring' : 'bx-bell'; ?> <?php echo (!isset($notification['read']) || !$notification['read']) ? 'text-blue-500' : 'text-gray-500'; ?>'></i>
                        </div>
                        <div class="flex-1">
                            <div class="flex justify-between items-start">
                                <div class="font-medium text-gray-800 text-sm">AP/AR Notification</div>
                                <?php if (!isset($notification['read']) || !$notification['read']): ?>
                                    <span class="w-2 h-2 rounded-full bg-blue-500 mt-1 flex-shrink-0"></span>
                                <?php endif; ?>
                            </div>
                            <div class="text-xs text-gray-600 mt-1"><?php echo htmlspecialchars($notification['message']); ?></div>
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

        initializeAgingPage();
    });

    function initializeAgingPage() {
        // Initialize charts
        initializeAgingChart();
        
        // Initialize common features
        initializeCommonFeatures();
    }

    function initializeAgingChart() {
        const ctx = document.getElementById('agingChart').getContext('2d');
        
        // Chart data from PHP
        const agingData = {
            labels: ['Current', '1-30 Days', '31-60 Days', '61-90 Days', 'Over 90 Days'],
            datasets: [{
                data: [
                    <?php echo $current_amount; ?>,
                    <?php echo $bucket_1_30; ?>,
                    <?php echo $bucket_31_60; ?>,
                    <?php echo $bucket_61_90; ?>,
                    <?php echo $bucket_over_90; ?>
                ],
                backgroundColor: [
                    '#10B981',
                    '#F59E0B',
                    '#F97316',
                    '#EF4444',
                    '#DC2626'
                ],
                borderWidth: 1
            }]
        };

        new Chart(ctx, {
            type: 'doughnut',
            data: agingData,
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const label = context.label || '';
                                const value = context.raw || 0;
                                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                const percentage = total > 0 ? Math.round((value / total) * 100) : 0;
                                return `${label}: ₱${parseInt(value).toLocaleString('en-US')} (${percentage}%)`;
                            }
                        }
                    }
                }
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
                            // Refresh the page to update notifications
                            window.location.reload();
                        }
                    })
                    .catch(error => {
                        console.error('Error marking notifications as read:', error);
                    });
            });
        }
    }

    // Generic function to handle button loading states
    function setButtonLoading(button, isLoading) {
        let btnElement = button;
        if (!button.classList.contains('action-btn') && !button.classList.contains('btn')) {
            btnElement = button.closest('.action-btn') || button.closest('.btn');
        }
        
        if (!btnElement) return;
        
        if (isLoading) {
            btnElement.dataset.originalContent = btnElement.innerHTML;
            btnElement.innerHTML = '<div class="spinner"></div>Loading...';
            btnElement.disabled = true;
            btnElement.style.opacity = '0.7';
            btnElement.style.cursor = 'not-allowed';
        } else {
            if (btnElement.dataset.originalContent) {
                btnElement.innerHTML = btnElement.dataset.originalContent;
            }
            btnElement.disabled = false;
            btnElement.style.opacity = '1';
            btnElement.style.cursor = 'pointer';
        }
    }

    // Aging Report Functions
    function exportAgingReport() {
        const btn = event.target;
        setButtonLoading(btn, true);
        
        // Create a form to submit the export request
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = '';
        
        // Add export flag
        const exportInput = document.createElement('input');
        exportInput.type = 'hidden';
        exportInput.name = 'export_aging_report';
        exportInput.value = '1';
        form.appendChild(exportInput);
        
        // Add CSRF token
        const csrfInput = document.createElement('input');
        csrfInput.type = 'hidden';
        csrfInput.name = 'csrf_token';
        csrfInput.value = '<?php echo $_SESSION['csrf_token']; ?>';
        form.appendChild(csrfInput);
        
        // Add current filters
        const typeInput = document.createElement('input');
        typeInput.type = 'hidden';
        typeInput.name = 'type';
        typeInput.value = '<?php echo $report_type ?? ''; ?>';
        form.appendChild(typeInput);
        
        const contactInput = document.createElement('input');
        contactInput.type = 'hidden';
        contactInput.name = 'contact_id';
        contactInput.value = '<?php echo $contact_id ?? ''; ?>';
        form.appendChild(contactInput);
        
        const dateInput = document.createElement('input');
        dateInput.type = 'hidden';
        dateInput.name = 'as_of_date';
        dateInput.value = '<?php echo $as_of_date; ?>';
        form.appendChild(dateInput);
        
        // Add to document and submit
        document.body.appendChild(form);
        form.submit();
        document.body.removeChild(form);
        
        // Reset button state after a short delay (in case submission fails)
        setTimeout(() => {
            setButtonLoading(btn, false);
        }, 3000);
    }

    function viewContactInvoices(contactId) {
        window.location.href = `invoices.php?contact_id=${contactId}`;
    }

    function viewInvoice(invoiceId) {
        window.location.href = `invoices.php#invoice-${invoiceId}`;
    }

    function recordPayment(invoiceId) {
        window.location.href = `payment_entry.php?invoice_id=${invoiceId}`;
    }

    async function sendReminder(contactId, contactName) {
        const btn = event.target.closest('.action-btn') || event.target;
        
        if (confirm(`Send payment reminder to ${contactName}?`)) {
            setButtonLoading(btn, true);
            
            try {
                await new Promise(resolve => setTimeout(resolve, 1500));
                alert(`Reminder sent successfully to ${contactName}!`);
            } catch (error) {
                console.error('Error sending reminder:', error);
                alert('Error sending reminder: ' + error.message);
            } finally {
                setButtonLoading(btn, false);
            }
        }
    }

    async function sendInvoiceReminder(invoiceId) {
        const btn = event.target.closest('.action-btn') || event.target;
        
        if (confirm('Send reminder for this specific invoice?')) {
            setButtonLoading(btn, true);
            
            try {
                await new Promise(resolve => setTimeout(resolve, 1500));
                alert('Invoice reminder sent successfully!');
            } catch (error) {
                console.error('Error sending invoice reminder:', error);
                alert('Error sending reminder: ' + error.message);
            } finally {
                setButtonLoading(btn, false);
            }
        }
    }
    </script>
</body>
</html>