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
        // Only run if table exists
        try {
            $stmt = $db->prepare("UPDATE user_notifications SET is_read = TRUE WHERE user_id = ?");
            $stmt->execute([$user_id]);
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }
}

// Load notifications from database
function loadNotificationsFromDatabase(PDO $db, int $user_id): array {
    // Check if user_notifications table exists, if not create it
    try {
        $db->query("SELECT 1 FROM user_notifications LIMIT 1");
    } catch (PDOException $e) {
        // Table doesn't exist, create it
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
        
        // Insert default notifications for this user
        $defaultNotifications = [
            ['success', 'Request Approved', 'Your disbursement request DISB-20250128-0001 has been approved.'],
            ['warning', 'Pending Review', 'New disbursement request requires your approval.'],
            ['info', 'System Update', 'New features added to the disbursement module.']
        ];
        
        $insertStmt = $db->prepare("INSERT INTO user_notifications (user_id, notification_type, title, message, is_read, created_at) VALUES (?, ?, ?, ?, ?, NOW() - INTERVAL ? DAY)");
        
        $timeIntervals = [0, 0, 1, 2]; // Time offsets for the default notifications
        
        foreach ($defaultNotifications as $index => $notification) {
            $isRead = ($index === 3); // Last one is read by default
            $insertStmt->execute([
                $user_id, 
                $notification[0], 
                $notification[1], 
                $notification[2], 
                $isRead,
                $timeIntervals[$index]
            ]);
        }
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

// Get disbursement requests data (SHOW ALL HISTORY for Finance View)
function getDisbursementRequests(PDO $db, int $user_id): array {
    // UPDATED: Tinanggal ang "WHERE dr.requested_by = ?"
    // Ngayon, ipapakita na nito LAHAT ng Approved/Rejected requests, pati galing sa API (ID 0)
    
    $sql = "SELECT dr.*, 
                   COALESCE(u.name, 'System/API') AS user_name, 
                   u2.name AS approved_by_name, 
                   bp.title as budget_title,
                   c.name as contact_name, c.type as contact_type
            FROM disbursement_requests dr
            LEFT JOIN users u ON dr.requested_by = u.id
            LEFT JOIN users u2 ON dr.approved_by = u2.id
            LEFT JOIN budget_proposals bp ON dr.budget_proposal_id = bp.id
            LEFT JOIN business_contacts c ON dr.contact_id = c.id
            WHERE dr.status IN ('Approved', 'Rejected', 'Released', 'Completed')
            ORDER BY dr.date_requested DESC, dr.id DESC";
            
    $stmt = $db->prepare($sql);
    $stmt->execute(); // Wala nang parameter na user_id
    return $stmt->fetchAll();
}

// Get statistics for the dashboard (GLOBAL STATS)
function getDisbursementStats(PDO $db, int $user_id): array {
    $stats = [
        'total_requests' => 0,
        'total_amount' => 0,
        'approved_count' => 0,
        'approved_amount' => 0,
        'rejected_count' => 0,
        'rejected_amount' => 0,
        'this_month_count' => 0,
        'this_month_amount' => 0
    ];
    
    try {
        // UPDATED: Lahat ng queries dito ay wala nang "WHERE requested_by = ?"
        
        // Total requests and amount
        $stmt = $db->prepare("SELECT COUNT(*) as count, COALESCE(SUM(amount), 0) as total FROM disbursement_requests WHERE status IN ('Approved', 'Rejected', 'Released', 'Completed')");
        $stmt->execute();
        $total = $stmt->fetch();
        $stats['total_requests'] = (int)($total['count'] ?? 0);
        $stats['total_amount'] = (float)($total['total'] ?? 0);
        
        // Approved stats
        $stmt = $db->prepare("SELECT COUNT(*) as count, COALESCE(SUM(amount), 0) as total FROM disbursement_requests WHERE status IN ('Approved', 'Released', 'Completed')");
        $stmt->execute();
        $approved = $stmt->fetch();
        $stats['approved_count'] = (int)($approved['count'] ?? 0);
        $stats['approved_amount'] = (float)($approved['total'] ?? 0);
        
        // Rejected stats
        $stmt = $db->prepare("SELECT COUNT(*) as count, COALESCE(SUM(amount), 0) as total FROM disbursement_requests WHERE status = 'Rejected'");
        $stmt->execute();
        $rejected = $stmt->fetch();
        $stats['rejected_count'] = (int)($rejected['count'] ?? 0);
        $stats['rejected_amount'] = (float)($rejected['total'] ?? 0);
        
        // This month stats
        $stmt = $db->prepare("SELECT COUNT(*) as count, COALESCE(SUM(amount), 0) as total FROM disbursement_requests WHERE status IN ('Approved', 'Rejected', 'Released', 'Completed') AND MONTH(date_requested) = MONTH(CURRENT_DATE()) AND YEAR(date_requested) = YEAR(CURRENT_DATE())");
        $stmt->execute();
        $this_month = $stmt->fetch();
        $stats['this_month_count'] = (int)($this_month['count'] ?? 0);
        $stats['this_month_amount'] = (float)($this_month['total'] ?? 0);
        
    } catch (Exception $e) {
        error_log("Error getting disbursement stats: " . $e->getMessage());
    }
    
    return $stats;
}

// Load notifications
$notifications = loadNotificationsFromDatabase($db, $user_id);

// Load disbursement requests
$disbursement_requests = getDisbursementRequests($db, $user_id);

// Load disbursement stats
$disbursement_stats = getDisbursementStats($db, $user_id);

// Count Unread
$unreadCount = 0;
foreach($notifications as $n) { if(!$n['read']) $unreadCount++; }

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Disbursement History - Financial Dashboard</title>

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
            <!-- Wrapper div for better spacing control -->
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

                <!-- All dropdown sections in a container -->
                <div class="space-y-3">
                    <!-- DISBURSEMENT DROPDOWN -->
                    <div>
                        <div class="text-xs font-bold text-gray-400 tracking-wider px-2">DISBURSEMENT</div>
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
                                <a href="disbursement_request.php" class="block px-3 py-1.5 rounded-lg text-xs bg-emerald-50 text-brand-primary font-medium border border-emerald-100 hover:bg-emerald-100 hover:border-emerald-200 transition-all duration-200 hover:translate-x-1">
                                    <span class="flex items-center justify-between">
                                        Disbursement History
                                        <span class="inline-flex w-1.5 h-1.5 rounded-full bg-emerald-400"></span>
                                    </span>
                                </a>
                                <a href="pending_disbursements.php" class="block px-3 py-1.5 rounded-lg text-xs text-gray-600 hover:bg-emerald-50 hover:text-brand-primary transition-all duration-200 hover:translate-x-1">
                                    Pending Disbursements
                                </a>
                                <a href="disbursement_reports.php" class="block px-3 py-1.5 rounded-lg text-xs text-gray-600 hover:bg-emerald-50 hover:text-brand-primary transition-all duration-200 hover:translate-x-1">
                                    Disbursement Reports
                                </a>
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
                        Disbursement History
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
                <button id="visibility-toggle" class="w-10 h-10 rounded-xl hover:bg-gray-100 active:bg-gray-200 transition flex items-center justify-center relative"
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
            <!-- Main Content -->
            <div class="space-y-6">
                <!-- Header -->
                <div class="flex justify-between items-center mb-6">
                    <div>
                        <h2 class="text-2xl font-bold text-gray-800">Disbursement History</h2>
                        <p class="text-gray-600 text-sm">View your approved and rejected disbursement requests</p>
                    </div>
                    <div class="text-sm text-gray-500">
                        Total: <?php echo $disbursement_stats['total_requests']; ?> requests
                    </div>
                </div>

                <!-- Stats Cards -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                    <div class="stat-card rounded-xl p-6">
                        <div class="flex items-center gap-4">
                            <div class="p-3 rounded-lg bg-green-100">
                                <i class='bx bx-money text-green-600 text-2xl'></i>
                            </div>
                            <div class="flex-1">
                                <div class="flex justify-between items-start">
                                    <div>
                                        <p class="text-sm text-gray-500">Total Requests</p>
                                        <p class="text-2xl font-bold text-gray-800">
    <?php echo $disbursement_stats['total_requests']; ?>
</p>
                                        <p class="text-xs text-gray-400 mt-1">
                                            <span class="amount-value" data-value="₱<?php echo number_format($disbursement_stats['total_amount'], 2); ?>">
                                                ₱<?php echo number_format($disbursement_stats['total_amount'], 2); ?>
                                            </span>
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="stat-card rounded-xl p-6">
                        <div class="flex items-center gap-4">
                            <div class="p-3 rounded-lg bg-green-100">
                                <i class='bx bx-check-circle text-green-600 text-2xl'></i>
                            </div>
                            <div class="flex-1">
                                <div class="flex justify-between items-start">
                                    <div>
                                        <p class="text-sm text-gray-500">Approved</p>
                                        <p class="text-2xl font-bold text-gray-800">
                        <?php echo $disbursement_stats['approved_count']; ?>
                    </p>
                    <p class="text-xs text-gray-400 mt-1">
                        <span class="amount-value" data-value="₱<?php echo number_format($disbursement_stats['approved_amount'], 2); ?>">
                            ₱<?php echo number_format($disbursement_stats['approved_amount'], 2); ?>
                        </span>
                    </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="stat-card rounded-xl p-6">
                        <div class="flex items-center gap-4">
                            <div class="p-3 rounded-lg bg-red-100">
                                <i class='bx bx-x-circle text-red-600 text-2xl'></i>
                            </div>
                            <div class="flex-1">
                                <div class="flex justify-between items-start">
                                    <div>
                                        <p class="text-sm text-gray-500">Rejected</p>
                    <p class="text-2xl font-bold text-gray-800">
                        <?php echo $disbursement_stats['rejected_count']; ?>
                    </p>
                    <p class="text-xs text-gray-400 mt-1">
                        <span class="amount-value" data-value="₱<?php echo number_format($disbursement_stats['rejected_amount'], 2); ?>">
                            ₱<?php echo number_format($disbursement_stats['rejected_amount'], 2); ?>
                        </span>
                    </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="stat-card rounded-xl p-6">
                        <div class="flex items-center gap-4">
                            <div class="p-3 rounded-lg bg-blue-100">
                                <i class='bx bx-calendar text-blue-600 text-2xl'></i>
                            </div>
                            <div class="flex-1">
                                <div class="flex justify-between items-start">
                                    <div>
                                        <p class="text-sm text-gray-500">This Month</p>
                    <p class="text-2xl font-bold text-gray-800">
                        <?php echo $disbursement_stats['this_month_count']; ?>
                    </p>
                    <p class="text-xs text-gray-400 mt-1">
                        <span class="amount-value" data-value="₱<?php echo number_format($disbursement_stats['this_month_amount'], 2); ?>">
                            ₱<?php echo number_format($disbursement_stats['this_month_amount'], 2); ?>
                        </span>
                    </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Disbursement History Table -->
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                    <div class="p-6 border-b border-gray-100">
                        <div class="flex justify-between items-center">
                            <div class="flex items-center gap-3">
                                <h3 class="text-lg font-bold text-gray-800">Disbursement History</h3>
                                <button id="table-visibility-toggle" class="text-gray-500 hover:text-brand-primary transition">
                                    <i class="fa-solid fa-eye-slash"></i>
                                </button>
                            </div>
                            <span class="text-sm text-gray-500">
                                Showing <?php echo count($disbursement_requests); ?> requests
                            </span>
                        </div>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="text-left p-4 text-sm font-medium text-gray-500">Request ID</th>
                                    <th class="text-left p-4 text-sm font-medium text-gray-500">Department</th>
                                    <th class="text-left p-4 text-sm font-medium text-gray-500">Description</th>
                                    <th class="text-left p-4 text-sm font-medium text-gray-500">Contact/Payee</th>
                                    <th class="text-left p-4 text-sm font-medium text-gray-500">Amount</th>
                                    <th class="text-left p-4 text-sm font-medium text-gray-500">Date Requested</th>
                                    <th class="text-left p-4 text-sm font-medium text-gray-500">Status</th>
                                    <th class="text-left p-4 text-sm font-medium text-gray-500">Approved By</th>
                                </tr>
                            </thead>
                            <tbody id="transactions-table-body">
                                <?php if (count($disbursement_requests) > 0): ?>
                                    <?php foreach ($disbursement_requests as $request): ?>
                                    <tr class="transaction-row">
                                        <td class="p-4 font-medium text-gray-800"><?php echo htmlspecialchars($request['request_id']); ?></td>
                                        <td class="p-4"><?php echo htmlspecialchars($request['department']); ?></td>
                                        <td class="p-4 max-w-xs">
                                            <?php echo htmlspecialchars($request['description']); ?>
                                            <?php if (!empty($request['budget_title'])): ?>
                                                <span class="budget-badge" title="<?php echo htmlspecialchars($request['budget_title']); ?>">
                                                    <i class="fa-solid fa-piggy-bank mr-1"></i>Budget
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="p-4">
                                            <?php if (!empty($request['contact_name'])): ?>
                                                <span class="contact-badge" title="<?php echo htmlspecialchars($request['contact_name'] . ' (' . $request['contact_type'] . ')'); ?>">
                                                    <i class="fa-solid fa-user mr-1"></i><?php echo htmlspecialchars($request['contact_name']); ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-gray-400 text-sm">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="p-4 font-medium text-gray-800">
    <div class="amount-cell">
        <span class="amount-value hidden-amount" data-value="₱<?php echo number_format((float)($request['amount'] ?? 0), 2); ?>">
            ••••••••
        </span>
        <button class="visibility-toggle ml-2 text-gray-400 hover:text-brand-primary transition" onclick="toggleAmountVisibility(this)">
            <i class="fa-solid fa-eye-slash"></i>
        </button>
    </div>
</td>
                                        <td class="p-4 text-gray-600"><?php echo date('M j, Y', strtotime($request['date_requested'])); ?></td>
                                        <td class="p-4">
                                            <span class="status-badge <?php echo $request['status'] === 'Approved' ? 'status-approved' : 'status-rejected'; ?>">
                                                <?php echo htmlspecialchars($request['status']); ?>
                                            </span>
                                        </td>
                                        <td class="p-4">
                                            <?php if (!empty($request['approved_by_name'])): ?>
                                                <?php echo htmlspecialchars($request['approved_by_name']); ?>
                                            <?php else: ?>
                                                <span class="text-gray-400 text-sm">-</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="8" class="p-8 text-center text-gray-500">
                                            <i class='bx bx-folder-open text-3xl mb-2 text-gray-300'></i>
                                            <div>No disbursement history found</div>
                                        </td>
                                    </tr>
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
                <!-- Notifications and Mark All button will be loaded here -->
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

        // Initialize common features
        initializeCommonFeatures();
        
        // Load notifications
        loadNotifications();
        
        // Initialize all amounts as hidden
const amountSpans = document.querySelectorAll('.amount-value');
amountSpans.forEach(span => {
    if (!span.classList.contains('hidden-amount')) {
        span.textContent = '••••••••'; // Changed here
        span.classList.add('hidden-amount');
    }
});
        
        // Table visibility toggle
const tableVisibilityToggle = document.getElementById('table-visibility-toggle');
if (tableVisibilityToggle) {
    tableVisibilityToggle.addEventListener('click', function() {
        const icon = this.querySelector('i');
        const amountCells = document.querySelectorAll('#transactions-table-body .amount-value');
        
        if (amountCells.length > 0) {
            const isHidden = amountCells[0].classList.contains('hidden-amount');
            
            amountCells.forEach(cell => {
                if (isHidden) {
                    const actualValue = cell.getAttribute('data-value');
                    cell.textContent = actualValue;
                    cell.classList.remove('hidden-amount');
                } else {
                    cell.textContent = '••••••••'; // Changed here
                    cell.classList.add('hidden-amount');
                }
            });
            
            // Update icon
            if (isHidden) {
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
                this.title = "Hide Amounts";
            } else {
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
                this.title = "Show Amounts";
            }
        }
    });
}
    });

    function toggleAmountVisibility(button) {
    const amountSpan = button.parentElement.querySelector('.amount-value');
    const icon = button.querySelector('i');
    
    if (amountSpan.classList.contains('hidden-amount')) {
        // Show amount
        const actualAmount = amountSpan.getAttribute('data-value');
        amountSpan.textContent = actualAmount;
        amountSpan.classList.remove('hidden-amount');
        icon.classList.remove('fa-eye-slash');
        icon.classList.add('fa-eye');
    } else {
        // Hide amount
        amountSpan.textContent = '••••••••'; // Changed here
        amountSpan.classList.add('hidden-amount');
        icon.classList.remove('fa-eye');
        icon.classList.add('fa-eye-slash');
    }
}

    function loadNotifications() {
        const notifications = <?php echo json_encode($notifications); ?>;
        const modalNotificationsList = document.getElementById('notification-modal-list');
        
        function renderNotifications(container, notifications, isModal = false) {
            if (!container) return;
            
            container.innerHTML = '';
            
            if (!notifications || notifications.length === 0) {
                container.innerHTML = `
                    <div class="text-center py-8 text-gray-500">
                        <i class='bx bx-bell text-3xl mb-2 text-gray-300'></i>
                        <div>No notifications</div>
                    </div>
                `;
                return;
            }
            
            // Add Mark All as Read button for modal
            if (isModal) {
                const unreadCount = notifications.filter(n => !n.read).length;
                if (unreadCount > 0) {
                    const markAllBtn = document.createElement('div');
                    markAllBtn.className = 'flex justify-end mb-4';
                    markAllBtn.innerHTML = `
                        <button id="mark-all-read-btn" 
                                class="px-4 py-2 bg-brand-primary text-white rounded-lg hover:bg-brand-primary-hover transition text-sm font-medium">
                            <i class='bx bx-check-double mr-2'></i>
                            Mark All as Read (${unreadCount})
                        </button>
                    `;
                    container.appendChild(markAllBtn);
                }
            }
            
            // Show all notifications in modal
            const displayNotifications = isModal ? notifications : notifications.slice(0, 3);
            
            displayNotifications.forEach(notification => {
                const notificationEl = document.createElement('div');
                notificationEl.className = `flex items-start gap-3 p-4 rounded-lg ${notification.read ? 'bg-gray-50' : 'bg-blue-50'} hover:bg-gray-100 transition`;
                notificationEl.innerHTML = `
                    <div class="w-10 h-10 rounded-lg ${notification.read ? 'bg-gray-100' : 'bg-blue-100'} flex items-center justify-center flex-shrink-0">
                        <i class='bx ${notification.read ? 'bx-bell' : 'bx-bell-ring'} ${notification.read ? 'text-gray-500' : 'text-blue-500'}'></i>
                    </div>
                    <div class="flex-1">
                        <div class="flex justify-between items-start">
                            <div class="font-medium text-gray-800 text-sm">${notification.title || 'Notification'}</div>
                            ${!notification.read ? '<span class="w-2 h-2 rounded-full bg-blue-500 mt-1 flex-shrink-0"></span>' : ''}
                        </div>
                        <div class="text-xs text-gray-600 mt-1">${notification.message || 'Notification message'}</div>
                        <div class="text-xs text-gray-400 mt-2">${notification.time || 'Recently'}</div>
                    </div>
                `;
                container.appendChild(notificationEl);
            });
            
            // Add event listener for mark all as read button
            if (isModal) {
                const markAllBtn = document.getElementById('mark-all-read-btn');
                if (markAllBtn) {
                    markAllBtn.addEventListener('click', markAllAsRead);
                }
            }
        }
        
        if (modalNotificationsList) {
            renderNotifications(modalNotificationsList, notifications, true);
        }
    }

    async function markAllAsRead() {
        const btn = document.getElementById('mark-all-read-btn');
        if (!btn) return;
        
        // Save original text
        const originalText = btn.innerHTML;
        
        // Show loading state
        btn.innerHTML = '<div class="spinner mx-auto" style="width: 16px; height: 16px;"></div>';
        btn.disabled = true;
        
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
                // Update UI
                const notificationBadge = document.querySelector('.notification-badge');
                if (notificationBadge) {
                    notificationBadge.remove();
                }
                
                // Reload notifications
                setTimeout(() => {
                    // Refresh page to get updated notifications
                    window.location.reload();
                }, 500);
                
                // Show success message
                btn.innerHTML = '<i class="bx bx-check mr-2"></i>All marked as read';
                btn.classList.remove('bg-brand-primary');
                btn.classList.add('bg-green-500');
                
                setTimeout(() => {
                    btn.innerHTML = originalText;
                    btn.classList.remove('bg-green-500');
                    btn.classList.add('bg-brand-primary');
                    btn.disabled = false;
                }, 2000);
                
            } else {
                throw new Error(result.error || 'Failed to mark as read');
            }
        } catch (error) {
            console.error('Error marking notifications as read:', error);
            btn.innerHTML = '<i class="bx bx-error mr-2"></i>Error';
            btn.classList.remove('bg-brand-primary');
            btn.classList.add('bg-red-500');
            
            setTimeout(() => {
                btn.innerHTML = originalText;
                btn.classList.remove('bg-red-500');
                btn.classList.add('bg-brand-primary');
                btn.disabled = false;
            }, 2000);
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
        const icon = this.querySelector('i');
        const allAmountElements = document.querySelectorAll('.amount-value');
        
        if (allAmountElements.length > 0) {
            const isHidden = allAmountElements[0].classList.contains('hidden-amount');
            
            allAmountElements.forEach(element => {
                if (isHidden) {
                    const actualValue = element.getAttribute('data-value');
                    element.textContent = actualValue;
                    element.classList.remove('hidden-amount');
                } else {
                    element.textContent = '••••••••'; // Changed here
                    element.classList.add('hidden-amount');
                }
            });
            
            // Update icon
            if (isHidden) {
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
                this.title = "Hide All Amounts";
            } else {
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
                this.title = "Show All Amounts";
            }
        }
    });
}
    }
    </script>
</body>
</html>