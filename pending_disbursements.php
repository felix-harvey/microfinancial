<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/database.php';

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

// Load current user
$u = $db->prepare("SELECT id, name, username, role FROM users WHERE id = ?");
$u->execute([$user_id]);
$user = $u->fetch();
if (!$user) {
    header("Location: index.php");
    exit;
}

// --- Handle AJAX Actions ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handle mark all as read
    if (isset($_POST['action']) && $_POST['action'] === 'mark_all_read') {
        try {
            $stmt = $db->prepare("UPDATE user_notifications SET is_read = TRUE WHERE user_id = ?");
            $stmt->execute([$user_id]);
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }
    
    // Handle approve disbursement
    if (isset($_POST['action']) && $_POST['action'] === 'approve_disbursement') {
        approveDisbursement($db, $user_id);
    }
    
    // Handle reject disbursement
    if (isset($_POST['action']) && $_POST['action'] === 'reject_disbursement') {
        rejectDisbursement($db, $user_id);
    }
}

// Handle GET actions (for backward compatibility)
if (isset($_GET['action']) && isset($_GET['request_id'])) {
    if ($_GET['action'] === 'approve') {
        $request_id = $_GET['request_id'];
        $stmt = $db->prepare("UPDATE disbursement_requests SET status = 'Approved', date_approved = NOW(), approved_by = ? WHERE request_id = ?");
        $stmt->execute([$user_id, $request_id]);
        $_SESSION['success'] = "Disbursement request approved successfully!";
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    } elseif ($_GET['action'] === 'reject') {
        $request_id = $_GET['request_id'];
        $stmt = $db->prepare("UPDATE disbursement_requests SET status = 'Rejected', date_approved = NOW(), approved_by = ? WHERE request_id = ?");
        $stmt->execute([$user_id, $request_id]);
        $_SESSION['success'] = "Disbursement request rejected successfully!";
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }
}

// Load notifications from database
function loadNotificationsFromDatabase(PDO $db, int $user_id): array {
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
            FOREIGN KEY (user_id) REFERENCES users(id)
        )");
    }
    
    // Load notifications from database
    $stmt = $db->prepare("SELECT * FROM user_notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 10");
    $stmt->execute([$user_id]);
    $dbNotifications = $stmt->fetchAll();
    
    // Convert to the format expected by the frontend
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

// Helper function to format time ago
function getTimeAgo(string $datetime): string {
    $time = strtotime($datetime);
    $timeDiff = time() - $time;
    
    if ($timeDiff < 60) {
        return 'Just now';
    } elseif ($timeDiff < 3600) {
        $minutes = floor($timeDiff / 60);
        return $minutes . ' mins ago';
    } elseif ($timeDiff < 86400) {
        $hours = floor($timeDiff / 3600);
        return $hours . ' hours ago';
    } else {
        $days = floor($timeDiff / 86400);
        return $days . ' days ago';
    }
}

function approveDisbursement(PDO $db, int $user_id): void {
    $request_id = trim($_POST['request_id'] ?? '');
    
    if (empty($request_id)) {
        $_SESSION['error'] = "Invalid request ID.";
        return;
    }
    
    try {
        $db->beginTransaction();

        // 1. GET DATA (Isama ang 'request_details')
        $stmt = $db->prepare("SELECT amount, budget_proposal_id, department, external_reference, request_details FROM disbursement_requests WHERE request_id = ?");
        $stmt->execute([$request_id]);
        $request = $stmt->fetch();

        if (!$request) throw new Exception("Request not found.");

        $amount = $request['amount'];
        $budget_id = $request['budget_proposal_id'];
        $department = $request['department'];
        $batch_ref = $request['external_reference'];
        
        // Decode ang listahan ng employees
        $employee_list = !empty($request['request_details']) ? json_decode($request['request_details'], true) : [];

        // 2. DEDUCT BUDGET
        if (!empty($budget_id)) {
            $checkBudget = $db->prepare("SELECT remaining_amount FROM budget_proposals WHERE id = ?");
            $checkBudget->execute([$budget_id]);
            $budget = $checkBudget->fetch();

            if ($budget && $budget['remaining_amount'] < $amount) {
                $db->rollBack();
                $_SESSION['error'] = "Insufficient budget balance!";
                header("Location: " . $_SERVER['PHP_SELF']);
                exit;
            }

            $updateBudget = $db->prepare("UPDATE budget_proposals 
                                          SET remaining_amount = remaining_amount - ?, 
                                              spent_amount = spent_amount + ? 
                                          WHERE id = ?");
            $updateBudget->execute([$amount, $amount, $budget_id]);
        }

        // 3. UPDATE STATUS LOCALLY
        $stmt = $db->prepare("UPDATE disbursement_requests 
                              SET status = 'Approved', 
                                  date_approved = NOW(), 
                                  approved_by = ? 
                              WHERE request_id = ?");
        $stmt->execute([$user_id, $request_id]);
        
        $debit_account_id = 44; // Default: General Expenses (ID 44)
    $credit_account_id = 34; // Default: Cash on Hand (ID 34)

    // B. Logic para sa ibang Department (Optional)
    if ($department === 'HR Payroll') {
        $debit_account_id = 45; // Salaries and Wages (ID 45)
    } elseif ($department === 'Core Budget' && strpos($request['description'], 'Loan') !== false) {
        $debit_account_id = 35; // Loans Receivable (ID 35) - Kung Lending ito
    }

    // C. Create Journal Entry Header (Table: journal_entries)
    // Gumawa ng Unique Ref ID (Ex: JE-REQ-001)
    $je_ref = 'JE-' . uniqid(); 
    $je_desc = "Auto-generated: " . $request['description'];

    $jeStmt = $db->prepare("INSERT INTO journal_entries (entry_id, entry_date, description, status, created_by) 
                            VALUES (?, NOW(), ?, 'Posted', ?)");
    $jeStmt->execute([$je_ref, $je_desc, $user_id]);
    
    // Kunin ang ID ng kakagawa lang na Entry
    $je_id = $db->lastInsertId();

    // D. Insert Debit Line (Expense/Asset)
    // Tumaas ang Gastos (Debit)
    $lineStmt = $db->prepare("INSERT INTO journal_entry_lines (journal_entry_id, account_id, debit, credit) VALUES (?, ?, ?, 0)");
    $lineStmt->execute([$je_id, $debit_account_id, $amount]);

    // E. Insert Credit Line (Cash)
    // Nabawasan ang Pera (Credit)
    $lineStmt = $db->prepare("INSERT INTO journal_entry_lines (journal_entry_id, account_id, debit, credit) VALUES (?, ?, 0, ?)");
    $lineStmt->execute([$je_id, $credit_account_id, $amount]);
        
        $db->commit(); 

        // =========================================================
        // 4. HR PAYROLL CALLBACK (Strict Mode: No BATCH-ALL)
        // =========================================================
        if ($department === 'HR Payroll' && !empty($batch_ref)) {
            
            $results_payload = [];

            // A. ONLY PROCESS IF THERE ARE EMPLOYEES
            if (!empty($employee_list) && is_array($employee_list)) {
                foreach ($employee_list as $emp) {
                    $results_payload[] = [
                        "employee_id"       => $emp['employee_id'], // Ex: EMP-011
                        "status"            => "Paid",
                        "payment_reference" => $request_id,
                        "payment_date"      => date('Y-m-d H:i:s')
                    ];
                }
            } 

            // B. SEND ONLY IF PAYLOAD IS NOT EMPTY
            if (!empty($results_payload)) {
                $target_url = "https://hr4.microfinancial-1.com/api/payroll/disbursement/callback";
                
                $callbackData = [
                    "batch_reference" => $batch_ref,
                    "results" => $results_payload
                ];
                
                $jsonData = json_encode($callbackData);

                // SEND VIA CURL
                $ch = curl_init($target_url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
                curl_setopt($ch, CURLOPT_TIMEOUT, 10); 
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

                $response = curl_exec($ch);
                curl_close($ch);

                // Log success
                file_put_contents(__DIR__ . '/hr_api_debug.log', "STRICT MODE SENT: $jsonData | RESP: $response\n", FILE_APPEND);
            } else {
                // Log skip
                file_put_contents(__DIR__ . '/hr_api_debug.log', "SKIPPED: Batch $batch_ref has no employee list.\n", FILE_APPEND);
            }
        }
        // =========================================================

        addNotificationToDatabase($db, $user_id, 'success', 'Request Approved', "Approved $request_id.");
        $_SESSION['success'] = "Approved successfully!";
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;

    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        $_SESSION['error'] = "Error: " . $e->getMessage();
    }
}

// Function to reject disbursement
function rejectDisbursement(PDO $db, int $user_id): void {
    $request_id = trim($_POST['request_id'] ?? '');
    $rejection_reason = trim($_POST['rejection_reason'] ?? '');
    
    if (empty($request_id)) {
        $_SESSION['error'] = "Invalid request ID.";
        return;
    }
    
    try {
        // Check if rejection_reason column exists
        $checkColumn = $db->query("SHOW COLUMNS FROM disbursement_requests LIKE 'rejection_reason'")->fetch();
        if (!$checkColumn) {
            $db->exec("ALTER TABLE disbursement_requests ADD COLUMN rejection_reason TEXT NULL");
        }
        
        $stmt = $db->prepare("UPDATE disbursement_requests SET status = 'Rejected', date_approved = NOW(), approved_by = ?, rejection_reason = ? WHERE request_id = ?");
        $stmt->execute([$user_id, $rejection_reason, $request_id]);
        
        // Add notification
        addNotificationToDatabase($db, $user_id, 'warning', 'Request Rejected', "Disbursement request {$request_id} has been rejected.");
        
        $_SESSION['success'] = "Disbursement request rejected successfully!";
        
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    } catch (Exception $e) {
        $_SESSION['error'] = "Error rejecting disbursement: " . $e->getMessage();
    }
}

// New function to add notification to database
function addNotificationToDatabase(PDO $db, int $user_id, string $type, string $title, string $message): void {
    $stmt = $db->prepare("INSERT INTO user_notifications (user_id, notification_type, title, message, created_at) VALUES (?, ?, ?, ?, NOW())");
    $stmt->execute([$user_id, $type, $title, $message]);
}

// Get pending disbursements data
function getPendingDisbursements(PDO $db): array {
    $sql = "SELECT dr.*, u.name AS user_name, bp.title as budget_title,
                   c.name as contact_name, c.type as contact_type
            FROM disbursement_requests dr
            LEFT JOIN users u ON dr.requested_by = u.id
            LEFT JOIN budget_proposals bp ON dr.budget_proposal_id = bp.id
            LEFT JOIN business_contacts c ON dr.contact_id = c.id
            WHERE dr.status = 'Pending'
            ORDER BY dr.date_requested DESC, dr.id DESC";
    return $db->query($sql)->fetchAll();
}

// Get statistics for pending disbursements
function getPendingStats(PDO $db): array {
    $stats = [
        'total_requests' => 0,
        'total_amount' => 0,
        'hr_payroll_count' => 0,
        'hr_payroll_amount' => 0,
        'lending_count' => 0,
        'lending_amount' => 0,
        'core_budget_count' => 0,
        'core_budget_amount' => 0,
        'today_count' => 0,
        'today_amount' => 0
    ];
    
    try {
        // Total pending requests
        $stmt = $db->query("SELECT COUNT(*) as count, COALESCE(SUM(amount), 0) as total FROM disbursement_requests WHERE status = 'Pending'");
        $total = $stmt->fetch();
        $stats['total_requests'] = (int)($total['count'] ?? 0);
        $stats['total_amount'] = (float)($total['total'] ?? 0);
        
        // HR Payroll stats
        $stmt = $db->query("SELECT COUNT(*) as count, COALESCE(SUM(amount), 0) as total FROM disbursement_requests WHERE status = 'Pending' AND department = 'HR Payroll'");
        $hr = $stmt->fetch();
        $stats['hr_payroll_count'] = (int)($hr['count'] ?? 0);
        $stats['hr_payroll_amount'] = (float)($hr['total'] ?? 0);
        
        // Lending stats
        $stmt = $db->query("SELECT COUNT(*) as count, COALESCE(SUM(amount), 0) as total FROM disbursement_requests WHERE status = 'Pending' AND department = 'Lending'");
        $lending = $stmt->fetch();
        $stats['lending_count'] = (int)($lending['count'] ?? 0);
        $stats['lending_amount'] = (float)($lending['total'] ?? 0);
        
        // Core Budget stats
        $stmt = $db->query("SELECT COUNT(*) as count, COALESCE(SUM(amount), 0) as total FROM disbursement_requests WHERE status = 'Pending' AND department = 'Core Budget'");
        $core = $stmt->fetch();
        $stats['core_budget_count'] = (int)($core['count'] ?? 0);
        $stats['core_budget_amount'] = (float)($core['total'] ?? 0);
        
        // Today's requests
        $stmt = $db->query("SELECT COUNT(*) as count, COALESCE(SUM(amount), 0) as total FROM disbursement_requests WHERE status = 'Pending' AND DATE(date_requested) = CURDATE()");
        $today = $stmt->fetch();
        $stats['today_count'] = (int)($today['count'] ?? 0);
        $stats['today_amount'] = (float)($today['total'] ?? 0);
        
    } catch (Exception $e) {
        error_log("Error getting pending stats: " . $e->getMessage());
    }
    
    return $stats;
}

// Load notifications
$notifications = loadNotificationsFromDatabase($db, $user_id);

// Load pending disbursements
$pending_disbursements = getPendingDisbursements($db);

// Load pending stats
$pending_stats = getPendingStats($db);

// Count Unread
$unreadCount = 0;
foreach($notifications as $n) { if(!$n['read']) $unreadCount++; }

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Pending Disbursements - Financial Dashboard</title>

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
        
        .budget-badge {
            background-color: #8B5CF6;
            color: white;
            font-size: 0.7rem;
            padding: 0.2rem 0.5rem;
            border-radius: 0.25rem;
            margin-left: 0.5rem;
        }
        
        .contact-badge {
            background-color: #3B82F6;
            color: white;
            font-size: 0.7rem;
            padding: 0.2rem 0.5rem;
            border-radius: 0.25rem;
            margin-left: 0.5rem;
        }
        
        .action-btn {
            padding: 0.25rem 0.75rem;
            font-size: 0.75rem;
            border-radius: 0.375rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease-in-out;
            border: 1px solid;
            margin-right: 0.25rem;
        }
        
        .action-btn.approve {
            background-color: #F0FDF4;
            color: #047857;
            border-color: #047857;
        }
        
        .action-btn.approve:hover {
            background-color: #047857;
            color: white;
        }
        
        .action-btn.reject {
            background-color: #FEF2F2;
            color: #DC2626;
            border-color: #DC2626;
        }
        
        .action-btn.reject:hover {
            background-color: #DC2626;
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

        <!-- Sidebar content - Optimized height -->
        <div class="px-4 py-3 overflow-y-auto h-[calc(100vh-4rem)] max-h-[calc(100vh-4rem)] custom-scrollbar">
            <!-- Wrapper for better spacing -->
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

                <!-- All dropdown sections -->
                <div class="space-y-3">
                    <!-- DISBURSEMENT DROPDOWN -->
                    <div>
                        <div class="text-xs font-bold text-gray-400 tracking-wider px-2 mt-2">DISBURSEMENT</div>
                        <button id="disbursement-menu-btn"
                            class="mt-1 w-full flex items-center justify-between px-3 py-2 rounded-xl
                                   bg-emerald-50 text-brand-primary border border-emerald-100
                                   transition-all duration-200 hover:translate-x-1 active:translate-x-0 active:scale-[0.99] font-semibold text-sm">
                            <span class="flex items-center gap-3">
                                <span class="inline-flex w-7 h-7 rounded-lg bg-emerald-100 items-center justify-center">
                                    <i class='bx bx-money text-emerald-600 text-xs'></i>
                                </span>
                                Disbursement
                            </span>
                            <svg id="disbursement-arrow" class="w-3.5 h-3.5 text-emerald-400 transition-transform duration-300 rotate-180" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                            </svg>
                        </button>
                        <div id="disbursement-submenu" class="submenu mt-1 active">
                            <div class="pl-3 pr-2 py-1.5 space-y-1 border-l-2 border-emerald-200 ml-5">
                                <a href="disbursement_request.php" class="block px-3 py-1.5 rounded-lg text-xs text-gray-600 hover:bg-emerald-50 hover:text-brand-primary transition-all duration-200 hover:translate-x-1">
                                    Disbursement History
                                </a>
                                <a href="pending_disbursements.php" class="block px-3 py-1.5 rounded-lg text-xs bg-emerald-50 text-brand-primary font-medium border border-emerald-100 hover:bg-emerald-100 hover:border-emerald-200 transition-all duration-200 hover:translate-x-1">
                                    <span class="flex items-center justify-between">
                                        Pending Disbursements
                                        <span class="inline-flex w-1.5 h-1.5 rounded-full bg-emerald-400"></span>
                                    </span>
                                </a>
                                <a href="disbursement_reports.php" class="block px-3 py-1.5 rounded-lg text-xs text-gray-600 hover:bg-emerald-50 hover:text-brand-primary transition-all duration-200 hover:translate-x-1">
                                    Disbursement Reports
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- GENERAL LEDGER DROPDOWN -->
                    <div>
                        <div class="text-xs font-bold text-gray-400 tracking-wider px-2 mt-2">GENERAL LEDGER</div>
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
                        <div class="text-xs font-bold text-gray-400 tracking-wider px-2 mt-2">AP/AR</div>
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
                        <div class="text-xs font-bold text-gray-400 tracking-wider px-2 mt-2">COLLECTION</div>
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
                        <div class="text-xs font-bold text-gray-400 tracking-wider px-2 mt-2">BUDGET MANAGEMENT</div>
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
                    <h1 class="text-lg font-bold text-gray-800">Pending Disbursements</h1>
                    <div class="text-xs text-gray-500">Track and manage all pending disbursement requests</div>
                </div>
            </div>

            <!-- USER AREA -->
            <div class="flex items-center gap-3">
                
                <!-- HIDE AMOUNTS BUTTON (show icon by default) -->
                <button id="hide-amounts-btn"
                    class="w-10 h-10 rounded-xl hover:bg-gray-100 active:bg-gray-200 transition flex items-center justify-center"
                    title="Show amounts">
                    <i class='bx bx-show text-xl text-gray-600'></i>
                </button>
                
                <!-- NOTIFICATIONS -->
                <div class="relative">
                    <button id="notifications-btn"
                        class="w-10 h-10 rounded-xl hover:bg-gray-100 active:bg-gray-200 transition flex items-center justify-center relative">
                        <i class='bx bx-bell text-xl'></i>
                        <?php if ($unreadCount > 0): ?>
                            <span class="notification-badge"></span>
                        <?php endif; ?>
                    </button>
                    
                    <div id="notifications-panel"
                        class="dropdown-panel absolute top-full right-0 mt-2 w-80 sm:w-96 bg-white rounded-xl shadow-xl border border-gray-100 z-50">
                        <div class="p-4 border-b border-gray-100">
                            <div class="flex items-center justify-between">
                                <h3 class="font-bold text-gray-800">Notifications (<?php echo $unreadCount; ?>)</h3>
                                <?php if ($unreadCount > 0): ?>
                                    <button id="mark-all-read"
                                        class="text-xs text-brand-primary hover:text-brand-primary-hover font-medium transition">
                                        Mark all as read
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="max-h-[400px] overflow-y-auto custom-scrollbar">
                            <?php if (empty($notifications)): ?>
                                <div class="p-6 text-center text-gray-500">
                                    <i class='bx bx-bell-off text-3xl mb-2 opacity-50'></i>
                                    <div>No notifications yet</div>
                                </div>
                            <?php else: ?>
                                <?php foreach ($notifications as $index => $notif): ?>
                                    <div class="notification-item p-4 border-b border-gray-100 hover:bg-gray-50 transition 
                                                <?php echo !$notif['read'] ? 'bg-emerald-50/50' : ''; ?>"
                                         data-id="<?php echo $notif['db_id'] ?? $index; ?>">
                                        <div class="flex gap-3">
                                            <div class="flex-shrink-0">
                                                <?php if ($notif['type'] === 'success'): ?>
                                                    <div class="w-8 h-8 rounded-lg bg-emerald-100 flex items-center justify-center">
                                                        <i class='bx bx-check text-emerald-600'></i>
                                                    </div>
                                                <?php elseif ($notif['type'] === 'warning'): ?>
                                                    <div class="w-8 h-8 rounded-lg bg-yellow-100 flex items-center justify-center">
                                                        <i class='bx bx-error text-yellow-600'></i>
                                                    </div>
                                                <?php elseif ($notif['type'] === 'info'): ?>
                                                    <div class="w-8 h-8 rounded-lg bg-blue-100 flex items-center justify-center">
                                                        <i class='bx bx-info-circle text-blue-600'></i>
                                                    </div>
                                                <?php else: ?>
                                                    <div class="w-8 h-8 rounded-lg bg-gray-100 flex items-center justify-center">
                                                        <i class='bx bx-bell text-gray-600'></i>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <div class="flex-1 min-w-0">
                                                <div class="font-medium text-gray-900"><?php echo htmlspecialchars($notif['title']); ?></div>
                                                <p class="text-sm text-gray-600 mt-1"><?php echo htmlspecialchars($notif['message']); ?></p>
                                                <div class="text-xs text-gray-400 mt-2"><?php echo $notif['time']; ?></div>
                                            </div>
                                            <?php if (!$notif['read']): ?>
                                                <span class="w-2 h-2 rounded-full bg-brand-primary flex-shrink-0 mt-2"></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <div class="p-3 border-t border-gray-100 text-center">
                            <a href="notifications.php" class="text-sm text-brand-primary hover:text-brand-primary-hover font-medium transition">
                                View all notifications
                            </a>
                        </div>
                    </div>
                </div>

                <!-- USER DROPDOWN -->
                <div class="relative">
                    <button id="user-menu-btn"
                        class="flex items-center gap-2 px-3 py-2 rounded-xl hover:bg-gray-100 active:bg-gray-200 transition">
                        <div class="w-8 h-8 rounded-full bg-gradient-to-br from-brand-primary to-emerald-400 flex items-center justify-center text-white font-medium">
                            <?php echo strtoupper(substr($user['name'], 0, 1)); ?>
                        </div>
                        <div class="hidden md:block text-left">
                            <div class="text-sm font-medium text-gray-800"><?php echo htmlspecialchars($user['name']); ?></div>
                            <div class="text-xs text-gray-500 capitalize"><?php echo htmlspecialchars($user['role']); ?></div>
                        </div>
                        <i class='bx bx-chevron-down text-gray-400'></i>
                    </button>
                    
                    <div id="user-dropdown"
                        class="dropdown-panel absolute top-full right-0 mt-2 w-48 bg-white rounded-xl shadow-xl border border-gray-100 z-50">
                        <div class="py-2">
                            <a href="profile.php"
                                class="flex items-center gap-2 px-4 py-3 hover:bg-gray-50 transition text-gray-700">
                                <i class='bx bx-user'></i>
                                My Profile
                            </a>
                            <a href="settings.php"
                                class="flex items-center gap-2 px-4 py-3 hover:bg-gray-50 transition text-gray-700">
                                <i class='bx bx-cog'></i>
                                Settings
                            </a>
                            <div class="border-t border-gray-100 my-1"></div>
                            <a href="?logout=true"
                                class="flex items-center gap-2 px-4 py-3 hover:bg-red-50 hover:text-red-600 transition text-gray-700">
                                <i class='bx bx-log-out'></i>
                                Logout
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <!-- MAIN CONTENT -->
        <main class="p-4 sm:p-6">
            <?php if (isset($_SESSION['success'])): ?>
                <div class="mb-4 p-4 bg-emerald-50 border border-emerald-200 rounded-xl text-emerald-700">
                    <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
                </div>
            <?php endif; ?>
            
            <?php if (isset($_SESSION['error'])): ?>
                <div class="mb-4 p-4 bg-red-50 border border-red-200 rounded-xl text-red-700">
                    <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
                </div>
            <?php endif; ?>

            <!-- STATISTICS CARDS -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
                <div class="stat-card rounded-xl p-5">
                    <div class="flex items-center justify-between mb-3">
                        <div class="text-sm font-medium text-gray-500">Total Pending</div>
        <div class="w-10 h-10 rounded-lg bg-emerald-50 flex items-center justify-center">
            <i class='bx bx-time-five text-emerald-600'></i>
        </div>
    </div>
    <div class="text-2xl font-bold text-gray-800">
        <?php echo $pending_stats['total_requests']; ?>
    </div>
    <div class="text-lg font-semibold text-emerald-600 amount-display hidden-amount" data-original="₱ <?php echo number_format($pending_stats['total_amount'], 2); ?>">
        ₱ ••••••••
    </div>
    <div class="text-xs text-gray-400 mt-2">Awaiting approval</div>
</div>

                <div class="stat-card rounded-xl p-5">
                    <div class="flex items-center justify-between mb-3">
                        <div class="text-sm font-medium text-gray-500">Today's Requests</div>
        <div class="w-10 h-10 rounded-lg bg-blue-50 flex items-center justify-center">
            <i class='bx bx-calendar text-blue-600'></i>
        </div>
    </div>
    <div class="text-2xl font-bold text-gray-800">
        <?php echo $pending_stats['today_count']; ?>
    </div>
    <div class="text-lg font-semibold text-blue-600 amount-display hidden-amount" data-original="₱ <?php echo number_format($pending_stats['today_amount'], 2); ?>">
        ₱ ••••••••
    </div>
    <div class="text-xs text-gray-400 mt-2">Submitted today</div>
</div>

                <div class="stat-card rounded-xl p-5">
                    <div class="flex items-center justify-between mb-3">
                        <div class="text-sm font-medium text-gray-500">HR Payroll</div>
        <div class="w-10 h-10 rounded-lg bg-purple-50 flex items-center justify-center">
            <i class='bx bx-group text-purple-600'></i>
        </div>
    </div>
    <div class="text-2xl font-bold text-gray-800">
        <?php echo $pending_stats['hr_payroll_count']; ?>
    </div>
    <div class="text-lg font-semibold text-purple-600 amount-display hidden-amount" data-original="₱ <?php echo number_format($pending_stats['hr_payroll_amount'], 2); ?>">
        ₱ ••••••••
    </div>
    <div class="text-xs text-gray-400 mt-2">Salaries & benefits</div>
</div>

                <div class="stat-card rounded-xl p-5">
                    <div class="flex items-center justify-between mb-3">
                        <div class="text-sm font-medium text-gray-500">Lending</div>
        <div class="w-10 h-10 rounded-lg bg-amber-50 flex items-center justify-center">
            <i class='bx bx-credit-card text-amber-600'></i>
        </div>
    </div>
    <div class="text-2xl font-bold text-gray-800">
        <?php echo $pending_stats['lending_count']; ?>
    </div>
    <div class="text-lg font-semibold text-amber-600 amount-display hidden-amount" data-original="₱ <?php echo number_format($pending_stats['lending_amount'], 2); ?>">
        ₱ ••••••••
    </div>
    <div class="text-xs text-gray-400 mt-2">Loan disbursements</div>
</div>
            </div>

            <!-- PENDING REQUESTS TABLE -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-200">
    <div class="flex items-center justify-between">
        <h2 class="text-lg font-bold text-gray-800">Pending Disbursement Requests</h2>
        
        <div class="flex items-center gap-4">
            <div class="flex gap-2">
    <button onclick="importPayroll()" 
            class="flex items-center gap-2 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition shadow-sm">
        <i class='bx bx-user-check'></i>
        Import HR
    </button>

    <button onclick="importLogistics()" 
            class="flex items-center gap-2 px-4 py-2 bg-orange-500 text-white rounded-lg hover:bg-orange-600 transition shadow-sm">
        <i class='bx bx-truck'></i>
        Import Logistics
    </button>
</div>
            
            <div class="h-6 w-px bg-gray-200"></div>

            <div class="text-sm text-gray-500">
                <?php echo count($pending_disbursements); ?> requests pending
            </div>
        </div>
    </div>
</div>
                
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead>
                            <tr class="bg-gray-50 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                <th class="px-6 py-3">Request ID</th>
                                <th class="px-6 py-3">Requested By</th>
                                <th class="px-6 py-3">Date</th>
                                <th class="px-6 py-3">Department</th>
                                <th class="px-6 py-3">Amount</th>
                                <th class="px-6 py-3">Purpose</th>
                                <th class="px-6 py-3">Contact</th>
                                <th class="px-6 py-3">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <?php if (empty($pending_disbursements)): ?>
                                <tr>
                                    <td colspan="8" class="px-6 py-8 text-center text-gray-500">
                                        <div class="flex flex-col items-center justify-center">
                                            <i class='bx bx-check-circle text-4xl text-gray-300 mb-2'></i>
                                            <div class="text-lg font-medium text-gray-400">No pending disbursement requests</div>
                                            <div class="text-sm text-gray-400 mt-1">All requests have been processed</div>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($pending_disbursements as $request): ?>
    <tr class="transaction-row hover:bg-gray-50">
        <td class="px-6 py-4">
            <div class="font-medium text-gray-900">
                <?php echo htmlspecialchars((string)($request['request_id'] ?? '')); ?>
            </div>
            <?php if (!empty($request['budget_title'])): ?>
                <span class="budget-badge">
                    Budget: <?php echo htmlspecialchars((string)$request['budget_title']); ?>
                </span>
            <?php endif; ?>
        </td>

        <td class="px-6 py-4">
            <div class="font-medium text-gray-900">
                <?php 
                    // Kung may user_name (Internal), ipakita. 
                    // Kung wala (External API/NULL), tingnan ang remarks o ilagay 'External'.
                    $u_name = $request['user_name'] ?? 'External API';
                    echo htmlspecialchars((string)$u_name); 
                ?>
            </div>
        </td>

        <td class="px-6 py-4 text-sm text-gray-500">
            <?php 
                $date = $request['date_requested'] ?? 'now';
                echo date('M d, Y', strtotime($date)); 
            ?>
        </td>

        <td class="px-6 py-4">
            <span class="status-badge status-pending">
                <?php echo htmlspecialchars((string)($request['department'] ?? 'Unknown')); ?>
            </span>
        </td>

        <td class="px-6 py-4">
            <div class="font-bold text-gray-900 amount-display hidden-amount" 
                 data-original="₱ <?php echo number_format((float)($request['amount'] ?? 0), 2); ?>">
                ₱ ••••••••
            </div>
        </td>

        <td class="px-6 py-4">
            <div class="text-sm text-gray-900">
                <?php 
                    // Check description OR purpose OR empty
                    $purpose = $request['description'] ?? $request['purpose'] ?? 'No Description';
                    echo htmlspecialchars((string)$purpose); 
                ?>
            </div>
            <?php if (!empty($request['remarks'])): ?>
                <div class="text-xs text-gray-500 mt-1">
                    <?php echo htmlspecialchars((string)$request['remarks']); ?>
                </div>
            <?php endif; ?>
        </td>

        <td class="px-6 py-4">
            <?php if (!empty($request['contact_name'])): ?>
                <span class="contact-badge">
                    <?php 
                        $c_type = $request['contact_type'] ?? 'Contact';
                        $c_name = $request['contact_name'] ?? '';
                        echo htmlspecialchars((string)($c_type . ': ' . $c_name)); 
                    ?>
                </span>
            <?php else: ?>
                <span class="text-xs text-gray-400">No contact</span>
            <?php endif; ?>
        </td>

        <td class="px-6 py-4">
            <button onclick="showApproveModal('<?php echo htmlspecialchars((string)($request['request_id'] ?? '')); ?>')" 
                    class="action-btn approve">
                Approve
            </button>
            <button onclick="showRejectModal('<?php echo htmlspecialchars((string)($request['request_id'] ?? '')); ?>')" 
                    class="action-btn reject">
                Reject
            </button>
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

    <!-- APPROVE MODAL -->
    <div id="approveModal" class="modal">
        <div class="modal-content">
            <div class="modal-actions">
                <button onclick="closeApproveModal()" class="text-gray-400 hover:text-gray-600">
                    <i class='bx bx-x text-2xl'></i>
                </button>
            </div>
            <h3 class="text-xl font-bold text-gray-800 mb-4">Approve Disbursement Request</h3>
            <p class="text-gray-600 mb-6">Are you sure you want to approve this disbursement request?</p>
            <form id="approveForm" method="POST">
                <input type="hidden" name="action" value="approve_disbursement">
                <input type="hidden" id="approveRequestId" name="request_id">
                <div class="flex justify-end gap-3">
                    <button type="button" onclick="closeApproveModal()" 
                            class="px-4 py-2 text-gray-700 hover:text-gray-900 font-medium">
                        Cancel
                    </button>
                    <button type="submit" 
                            class="px-4 py-2 bg-brand-primary text-white rounded-lg hover:bg-brand-primary-hover font-medium">
                        Approve Request
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- REJECT MODAL -->
    <div id="rejectModal" class="modal">
        <div class="modal-content">
            <div class="modal-actions">
                <button onclick="closeRejectModal()" class="text-gray-400 hover:text-gray-600">
                    <i class='bx bx-x text-2xl'></i>
                </button>
            </div>
            <h3 class="text-xl font-bold text-gray-800 mb-4">Reject Disbursement Request</h3>
            <form id="rejectForm" method="POST">
                <input type="hidden" name="action" value="reject_disbursement">
                <input type="hidden" id="rejectRequestId" name="request_id">
                <div class="mb-6">
                    <label class="block text-gray-700 text-sm font-medium mb-2" for="rejection_reason">
                        Reason for Rejection
                    </label>
                    <textarea id="rejection_reason" name="rejection_reason" 
                              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-brand-primary focus:border-transparent"
                              rows="4" placeholder="Please provide a reason for rejecting this request..."></textarea>
                </div>
                <div class="flex justify-end gap-3">
                    <button type="button" onclick="closeRejectModal()" 
                            class="px-4 py-2 text-gray-700 hover:text-gray-900 font-medium">
                        Cancel
                    </button>
                    <button type="submit" 
                            class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 font-medium">
                        Reject Request
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Mobile menu toggle
        const mobileMenuBtn = document.getElementById('mobile-menu-btn');
        const sidebar = document.getElementById('sidebar');
        const sidebarOverlay = document.getElementById('sidebar-overlay');
        
        if (mobileMenuBtn && sidebar && sidebarOverlay) {
            mobileMenuBtn.addEventListener('click', () => {
                sidebar.classList.add('active');
                sidebarOverlay.classList.remove('hidden');
                setTimeout(() => sidebarOverlay.classList.add('active'), 10);
            });
            
            sidebarOverlay.addEventListener('click', () => {
                sidebar.classList.remove('active');
                sidebarOverlay.classList.remove('active');
                setTimeout(() => sidebarOverlay.classList.add('hidden'), 300);
            });
        }

        // Hide/Show amounts functionality
        const hideAmountsBtn = document.getElementById('hide-amounts-btn');
        let amountsHidden = true; // Set to true by default

        // Function to hide amounts
        function hideAmounts() {
            const amountDisplays = document.querySelectorAll('.amount-display');
            
            amountDisplays.forEach(display => {
                const originalText = display.getAttribute('data-original');
                if (originalText && !display.classList.contains('hidden-amount')) {
                    if (originalText.includes('₱')) {
                        // It's a monetary amount
                        const amount = originalText.replace('₱ ', '');
                        const hiddenAmount = '₱ ' + '•'.repeat(Math.max(amount.length, 8));
                        display.textContent = hiddenAmount;
                    } else {
                        // It's a count/number
                        const hiddenCount = '•'.repeat(originalText.length);
                        display.textContent = hiddenCount;
                    }
                }
                display.classList.add('hidden-amount');
            });
            
            hideAmountsBtn.innerHTML = '<i class="bx bx-show text-xl text-gray-600"></i>';
            hideAmountsBtn.title = "Show amounts";
            amountsHidden = true;
        }

        // Function to show amounts
        function showAmounts() {
            const amountDisplays = document.querySelectorAll('.amount-display');
            
            amountDisplays.forEach(display => {
                const originalText = display.getAttribute('data-original');
                if (originalText) {
                    display.textContent = originalText;
                }
                display.classList.remove('hidden-amount');
            });
            
            hideAmountsBtn.innerHTML = '<i class="bx bx-hide text-xl text-gray-600"></i>';
            hideAmountsBtn.title = "Hide amounts";
            amountsHidden = false;
        }

        // Initialize with amounts hidden by default
        if (hideAmountsBtn) {
            // Amounts are already hidden by the HTML classes
            // Set up toggle functionality
            hideAmountsBtn.addEventListener('click', () => {
                if (amountsHidden) {
                    showAmounts();
                } else {
                    hideAmounts();
                }
            });
        }

        // Dropdown toggles
        function toggleDropdown(buttonId, panelId) {
            const button = document.getElementById(buttonId);
            const panel = document.getElementById(panelId);
            
            if (button && panel) {
                button.addEventListener('click', (e) => {
                    e.stopPropagation();
                    panel.classList.toggle('active');
                    
                    // Close other dropdowns
                    document.querySelectorAll('.dropdown-panel').forEach(otherPanel => {
                        if (otherPanel !== panel && otherPanel.classList.contains('active')) {
                            otherPanel.classList.remove('active');
                        }
                    });
                });
            }
            
            // Close dropdown when clicking outside
            document.addEventListener('click', (e) => {
                if (panel && panel.classList.contains('active') && 
                    !panel.contains(e.target) && 
                    !button.contains(e.target)) {
                    panel.classList.remove('active');
                }
            });
        }

        // Initialize dropdowns
        toggleDropdown('notifications-btn', 'notifications-panel');
        toggleDropdown('user-menu-btn', 'user-dropdown');

        // Submenu toggles
        function setupSubmenu(buttonId, submenuId, arrowId) {
            const button = document.getElementById(buttonId);
            const submenu = document.getElementById(submenuId);
            const arrow = document.getElementById(arrowId);
            
            if (button && submenu && arrow) {
                button.addEventListener('click', () => {
                    submenu.classList.toggle('active');
                    arrow.style.transform = submenu.classList.contains('active') ? 'rotate(180deg)' : 'rotate(0)';
                });
            }
        }

        setupSubmenu('disbursement-menu-btn', 'disbursement-submenu', 'disbursement-arrow');
        setupSubmenu('ledger-menu-btn', 'ledger-submenu', 'ledger-arrow');
        setupSubmenu('ap-ar-menu-btn', 'ap-ar-submenu', 'ap-ar-arrow');
        setupSubmenu('collection-menu-btn', 'collection-submenu', 'collection-arrow');
        setupSubmenu('budget-menu-btn', 'budget-submenu', 'budget-arrow');

        // Mark all as read
        const markAllReadBtn = document.getElementById('mark-all-read');
        if (markAllReadBtn) {
            markAllReadBtn.addEventListener('click', async () => {
                try {
                    const response = await fetch('', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: 'action=mark_all_read'
                    });
                    
                    const result = await response.json();
                    if (result.success) {
                        // Reload page to reflect changes
                        location.reload();
                    }
                } catch (error) {
                    console.error('Error marking notifications as read:', error);
                }
            });
        }

        // Modal functions
        function showApproveModal(requestId) {
            document.getElementById('approveRequestId').value = requestId;
            document.getElementById('approveModal').style.display = 'block';
        }

        function closeApproveModal() {
            document.getElementById('approveModal').style.display = 'none';
        }

        function showRejectModal(requestId) {
            document.getElementById('rejectRequestId').value = requestId;
            document.getElementById('rejection_reason').value = '';
            document.getElementById('rejectModal').style.display = 'block';
        }

        function closeRejectModal() {
            document.getElementById('rejectModal').style.display = 'none';
        }

        // Close modals on outside click
        window.onclick = function(event) {
            const approveModal = document.getElementById('approveModal');
            const rejectModal = document.getElementById('rejectModal');
            
            if (event.target == approveModal) {
                closeApproveModal();
            }
            if (event.target == rejectModal) {
                closeRejectModal();
            }
        }

        // Form submissions
        document.getElementById('approveForm')?.addEventListener('submit', function(e) {
            e.preventDefault();
            this.submit();
        });

        document.getElementById('rejectForm')?.addEventListener('submit', function(e) {
            const reason = document.getElementById('rejection_reason').value.trim();
            if (!reason) {
                e.preventDefault();
                alert('Please provide a reason for rejection.');
                return;
            }
            this.submit();
        });

        // --- UPDATED: IMPORT PAYROLL FUNCTION ---
        async function importPayroll() {
            // 1. Setup UI
            const btn = document.querySelector("button[onclick='importPayroll()']");
            if (!btn) { console.error("Button not found!"); return; }
            
            const originalContent = btn.innerHTML;
            btn.innerHTML = "<i class='bx bx-loader-alt bx-spin'></i> Processing...";
            btn.disabled = true;
            btn.classList.add('opacity-75', 'cursor-not-allowed');

            try {
                console.log("Starting import..."); // Debug log

                // 2. FETCH REQUEST (Gamit ang root path /api/...)
                // Siguraduhin na ang file ay nasa public_html/api/import_hr_payroll.php
                const response = await fetch('/api/import_hr_payroll.php', {
                    method: 'GET',
                    headers: {
                        'Accept': 'application/json',
                        'Cache-Control': 'no-cache'
                    }
                });

                // 3. CHECK RESPONSE
                const text = await response.text(); // Kunin muna as text para makita kung HTML error
                console.log("Server Response:", text); // Tingnan sa Console (F12)

                let result;
                try {
                    result = JSON.parse(text); // Tsaka i-convert sa JSON
                } catch (e) {
                    throw new Error("Invalid Server Response: " + text.substring(0, 100) + "...");
                }

                // 4. HANDLE RESULT
                if (result.status === 'success') {
                    // Success Alert
                    const successHtml = `
                        <div id="success-toast" class="fixed top-4 right-4 bg-green-100 border border-green-400 text-green-700 px-6 py-4 rounded-lg z-50 shadow-xl flex items-center gap-3 animate-bounce">
                            <i class='bx bx-check-circle text-2xl'></i>
                            <div>
                                <strong class="font-bold block">Success!</strong>
                                <span class="text-sm">${result.message}</span>
                            </div>
                        </div>
                    `;
                    document.body.insertAdjacentHTML('beforeend', successHtml);
                    
                    // Reload after 2 seconds
                    setTimeout(() => location.reload(), 2000);

                } else if (result.status === 'warning') {
                    alert("⚠️ Notice: " + result.message);
                } else {
                    alert("❌ Server Error: " + (result.message || "Unknown error"));
                }

            } catch (error) {
                console.error("Import Error:", error);
                alert("❌ Connection Failed. Check Console (F12) for details.\n\nError: " + error.message);
            } finally {
                // Restore Button
                btn.innerHTML = originalContent;
                btn.disabled = false;
                btn.classList.remove('opacity-75', 'cursor-not-allowed');
            }
        }
        
        // Function para sa Logistics Import
async function importLogistics() {
    const btn = document.querySelector("button[onclick='importLogistics()']");
    const originalContent = btn.innerHTML;
    
    // Loading State
    btn.innerHTML = "<i class='bx bx-loader-alt bx-spin'></i> Connecting...";
    btn.disabled = true;

    try {
        // Tumawag sa bagong PHP file
        const response = await fetch('api/import_logistics.php');
        const result = await response.json();

        if (result.status === 'success') {
            alert("✅ " + result.message);
            location.reload(); 
        } else if (result.status === 'warning') {
            alert("⚠️ " + result.message);
        } else {
            alert("❌ Error: " + result.message);
        }
    } catch (error) {
        console.error(error);
        alert("❌ Connection Error: Unable to reach Logistics API.");
    } finally {
        // Reset Button
        btn.innerHTML = originalContent;
        btn.disabled = false;
    }
}
    </script> 
</body>
</html>