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

// Handle form submissions
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
}

// Load current user
$u = $db->prepare("SELECT id, name, username, role FROM users WHERE id = ?");
$u->execute([$user_id]);
$user = $u->fetch();
if (!$user) {
    header("Location: index.php");
    exit;
}

// Load notifications from database function
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

// New function to add notification to database
function addNotificationToDatabase(PDO $db, int $user_id, string $type, string $title, string $message): void {
    $stmt = $db->prepare("INSERT INTO user_notifications (user_id, notification_type, title, message, created_at) VALUES (?, ?, ?, ?, NOW())");
    $stmt->execute([$user_id, $type, $title, $message]);
}

// Get disbursement reports data
function getDisbursementStats(PDO $db): array {
    // Total disbursements this month
    $monthTotal = $db->query("SELECT COALESCE(SUM(amount), 0) as total 
                             FROM disbursement_requests 
                             WHERE MONTH(date_requested) = MONTH(CURDATE()) 
                             AND YEAR(date_requested) = YEAR(CURDATE())")->fetch()['total'];
    
    // Pending approvals count
    $pendingCount = $db->query("SELECT COUNT(*) as count 
                               FROM disbursement_requests 
                               WHERE status = 'Pending'")->fetch()['count'];
    
    // Approved this month
    $approvedMonth = $db->query("SELECT COALESCE(SUM(amount), 0) as total 
                                FROM disbursement_requests 
                                WHERE status = 'Approved' 
                                AND MONTH(date_approved) = MONTH(CURDATE()) 
                                AND YEAR(date_approved) = YEAR(CURDATE())")->fetch()['total'];
    
    // Rejected this month
    $rejectedMonth = $db->query("SELECT COALESCE(SUM(amount), 0) as total 
                                FROM disbursement_requests 
                                WHERE status = 'Rejected' 
                                AND MONTH(date_approved) = MONTH(CURDATE()) 
                                AND YEAR(date_approved) = YEAR(CURDATE())")->fetch()['total'];
    
    return [
        'month_total' => (float)$monthTotal,
        'pending_count' => (int)$pendingCount,
        'approved_month' => (float)$approvedMonth,
        'rejected_month' => (float)$rejectedMonth
    ];
}

function getDisbursementByDepartment(PDO $db): array {
    $sql = "SELECT department, 
                   COUNT(*) as request_count, 
                   COALESCE(SUM(amount), 0) as total_amount,
                   COALESCE(SUM(CASE WHEN status = 'Approved' THEN amount ELSE 0 END), 0) as approved_amount,
                   COALESCE(SUM(CASE WHEN status = 'Pending' THEN amount ELSE 0 END), 0) as pending_amount,
                   COALESCE(SUM(CASE WHEN status = 'Rejected' THEN amount ELSE 0 END), 0) as rejected_amount
            FROM disbursement_requests 
            WHERE date_requested >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            GROUP BY department 
            ORDER BY total_amount DESC";
    return $db->query($sql)->fetchAll();
}

function getMonthlyTrends(PDO $db): array {
    $sql = "SELECT 
                DATE_FORMAT(date_requested, '%Y-%m') as month,
                COUNT(*) as request_count,
                COALESCE(SUM(amount), 0) as total_amount,
                COALESCE(SUM(CASE WHEN status = 'Approved' THEN amount ELSE 0 END), 0) as approved_amount,
                COALESCE(SUM(CASE WHEN status = 'Pending' THEN amount ELSE 0 END), 0) as pending_amount
            FROM disbursement_requests 
            WHERE date_requested >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
            GROUP BY DATE_FORMAT(date_requested, '%Y-%m')
            ORDER BY month DESC
            LIMIT 6";
    return $db->query($sql)->fetchAll();
}

// Load notifications from database
$notifications = loadNotificationsFromDatabase($db, $user_id);
$stats = getDisbursementStats($db);
$department_data = getDisbursementByDepartment($db);
$monthly_trends = getMonthlyTrends($db);

// Calculate unread notification count
$unreadCount = 0;
foreach($notifications as $n) { if(!$n['read']) $unreadCount++; }

// Check if all notifications are read
$allNotificationsRead = true;
foreach ($notifications as $notification) {
    if (!$notification['read']) {
        $allNotificationsRead = false;
        break;
    }
}

// Handle export requests
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=disbursement_reports_' . date('Y-m-d') . '.csv');
    
    $output = fopen('php://output', 'w');
    
    // Add headers
    fputcsv($output, ['Department Disbursement Report - Generated on ' . date('Y-m-d H:i:s')]);
    fputcsv($output, []); // Empty row
    
    // Column headers
    fputcsv($output, ['Department', 'Total Requests', 'Total Amount', 'Approved Amount', 'Pending Amount', 'Rejected Amount', 'Approval Rate (%)']);
    
    // Data rows
    foreach ($department_data as $dept) {
        $approval_rate = $dept['total_amount'] > 0 ? ($dept['approved_amount'] / $dept['total_amount']) * 100 : 0;
        fputcsv($output, [
            $dept['department'],
            $dept['request_count'],
            number_format((float)$dept['total_amount'], 2),
            number_format((float)$dept['approved_amount'], 2),
            number_format((float)$dept['pending_amount'], 2),
            number_format((float)$dept['rejected_amount'], 2),
            number_format($approval_rate, 2)
        ]);
    }
    
    fputcsv($output, []); // Empty row
    fputcsv($output, ['Summary Statistics']);
    fputcsv($output, ['Total This Month', '₱' . number_format((float)$stats['month_total'], 2)]);
    fputcsv($output, ['Pending Approvals', $stats['pending_count']]);
    fputcsv($output, ['Approved This Month', '₱' . number_format((float)$stats['approved_month'], 2)]);
    fputcsv($output, ['Rejected This Month', '₱' . number_format((float)$stats['rejected_month'], 2)]);
    
    fclose($output);
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Disbursement Reports - Financial Dashboard</title>
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
    <!-- Include jsPDF and html2canvas for PDF export -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
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
        
        .action-btn.export {
            background-color: #F0FDF4;
            color: #047857;
            border-color: #047857;
        }
        
        .action-btn.export:hover {
            background-color: #047857;
            color: white;
        }
        
        .action-btn.print {
            background-color: #F0F9FF;
            color: #0369A1;
            border-color: #0369A1;
        }
        
        .action-btn.print:hover {
            background-color: #0369A1;
            color: white;
        }
        
        .progress-bar {
            width: 100%;
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
        
        .amount-cell {
            display: flex;
            align-items: center;
        }
        
        @media print {
            body * {
                visibility: hidden;
            }
            #print-section, #print-section * {
                visibility: visible;
            }
            #print-section {
                position: absolute;
                left: 0;
                top: 0;
                width: 100%;
            }
            .no-print {
                display: none !important;
            }
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
                                <a href="disbursement_request.php" class="block px-3 py-1.5 rounded-lg text-xs text-gray-600 hover:bg-emerald-50 hover:text-brand-primary transition-all duration-200 hover:translate-x-1">
                                    Disbursement History
                                </a>
                                <a href="pending_disbursements.php" class="block px-3 py-1.5 rounded-lg text-xs text-gray-600 hover:bg-emerald-50 hover:text-brand-primary transition-all duration-200 hover:translate-x-1">
                                    Pending Disbursements
                                </a>
                                <a href="disbursement_reports.php" class="block px-3 py-1.5 rounded-lg text-xs bg-emerald-50 text-brand-primary font-medium border border-emerald-100 hover:bg-emerald-100 hover:border-emerald-200 transition-all duration-200 hover:translate-x-1">
                                    <span class="flex items-center justify-between">
                                        Disbursement Reports
                                        <span class="inline-flex w-1.5 h-1.5 rounded-full bg-emerald-400"></span>
                                    </span>
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

                <!-- Footer section - Compact design -->
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
                    <h1 class="text-lg font-bold text-gray-800">Disbursement Reports</h1>
                    <div class="text-xs text-gray-500">Analytics and insights for disbursement management</div>
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
            <!-- Print Section -->
            <div id="print-section">
                <!-- Stats Overview -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
                    <div class="stat-card rounded-xl p-5">
                        <div class="flex items-center justify-between mb-3">
                            <div class="text-sm font-medium text-gray-500">Total This Month</div>
                            <div class="w-10 h-10 rounded-lg bg-emerald-50 flex items-center justify-center">
                                <i class='bx bx-money text-emerald-600'></i>
                            </div>
                        </div>
                        <div class="text-lg font-semibold text-emerald-600 amount-display hidden-amount" data-original="₱ <?php echo number_format((float)$stats['month_total'], 2); ?>">
                            ₱ ••••••••
                        </div>
                        <div class="text-xs text-gray-400 mt-2">Monthly disbursement total</div>
                    </div>

                    <div class="stat-card rounded-xl p-5">
                        <div class="flex items-center justify-between mb-3">
                            <div class="text-sm font-medium text-gray-500">Pending Approvals</div>
                            <div class="w-10 h-10 rounded-lg bg-amber-50 flex items-center justify-center">
                                <i class='bx bx-time-five text-amber-600'></i>
                            </div>
                        </div>
                        <div class="text-2xl font-bold text-gray-800"><?php echo $stats['pending_count']; ?></div>
                        <div class="text-xs text-gray-400 mt-2">Awaiting approval</div>
                    </div>

                    <div class="stat-card rounded-xl p-5">
                        <div class="flex items-center justify-between mb-3">
                            <div class="text-sm font-medium text-gray-500">Approved This Month</div>
                            <div class="w-10 h-10 rounded-lg bg-green-50 flex items-center justify-center">
                                <i class='bx bx-check-circle text-green-600'></i>
                            </div>
                        </div>
                        <div class="text-lg font-semibold text-green-600 amount-display hidden-amount" data-original="₱ <?php echo number_format((float)$stats['approved_month'], 2); ?>">
                            ₱ ••••••••
                        </div>
                        <div class="text-xs text-gray-400 mt-2">Approved disbursements</div>
                    </div>

                    <div class="stat-card rounded-xl p-5">
                        <div class="flex items-center justify-between mb-3">
                            <div class="text-sm font-medium text-gray-500">Rejected This Month</div>
                            <div class="w-10 h-10 rounded-lg bg-red-50 flex items-center justify-center">
                                <i class='bx bx-x-circle text-red-600'></i>
                            </div>
                        </div>
                        <div class="text-lg font-semibold text-red-600 amount-display hidden-amount" data-original="₱ <?php echo number_format((float)$stats['rejected_month'], 2); ?>">
                            ₱ ••••••••
                        </div>
                        <div class="text-xs text-gray-400 mt-2">Rejected requests</div>
                    </div>
                </div>

                <!-- Charts Section -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-6">
                    <!-- Department Distribution -->
                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                        <div class="px-6 py-4 border-b border-gray-200">
                            <div class="flex items-center justify-between">
                                <h3 class="text-lg font-bold text-gray-800">Disbursement by Department</h3>
                                <button class="text-xs text-brand-primary hover:text-brand-primary-hover font-medium transition">
                                    <i class='bx bx-refresh text-xl'></i>
                                </button>
                            </div>
                        </div>
                        <div class="p-6 space-y-4">
                            <?php 
                            $total_amount = array_sum(array_column($department_data, 'total_amount'));
                            foreach ($department_data as $dept): 
                                $percentage = $total_amount > 0 ? ($dept['total_amount'] / $total_amount) * 100 : 0;
                            ?>
                            <div>
                                <div class="flex justify-between mb-1">
                                    <span class="text-sm font-medium"><?php echo htmlspecialchars($dept['department']); ?></span>
                                    <div class="amount-cell">
                                        <span class="amount-value hidden-amount text-sm text-gray-500" data-value="₱<?php echo number_format((float)$dept['total_amount'], 2); ?>">
                                            ********
                                        </span>
                                    </div>
                                </div>
                                <div class="progress-bar">
                                    <div class="progress-fill bg-brand-primary" style="width: <?php echo $percentage; ?>%"></div>
                                </div>
                                <div class="flex justify-between text-xs text-gray-500 mt-1">
                                    <span><?php echo $dept['request_count']; ?> requests</span>
                                    <span><?php echo number_format($percentage, 1); ?>%</span>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Monthly Trends Chart -->
                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                        <div class="px-6 py-4 border-b border-gray-200">
                            <div class="flex items-center justify-between">
                                <h3 class="text-lg font-bold text-gray-800">Monthly Trends</h3>
                                <button class="text-xs text-brand-primary hover:text-brand-primary-hover font-medium transition">
                                    <i class='bx bx-refresh text-xl'></i>
                                </button>
                            </div>
                        </div>
                        <div class="p-6 h-64">
                            <canvas id="monthlyTrendsChart"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Detailed Department Report -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden mb-6">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <div class="flex items-center justify-between">
                            <h3 class="text-lg font-bold text-gray-800">Department Disbursement Details</h3>
                            <div class="flex space-x-3 no-print">
                                <button class="action-btn export" id="export-csv-btn">
                                    <i class='bx bx-download mr-1'></i>Export CSV
                                </button>
                                <button class="action-btn print" id="print-report-btn">
                                    <i class='bx bx-printer mr-1'></i>Print
                                </button>
                                <button class="action-btn export" id="export-pdf-btn">
                                    <i class='bx bx-file mr-1'></i>Export PDF
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead>
                                <tr class="bg-gray-50 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    <th class="px-6 py-3">Department</th>
                                    <th class="px-6 py-3">Total Requests</th>
                                    <th class="px-6 py-3">Total Amount</th>
                                    <th class="px-6 py-3">Approved</th>
                                    <th class="px-6 py-3">Pending</th>
                                    <th class="px-6 py-3">Rejected</th>
                                    <th class="px-6 py-3">Approval Rate</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                <?php if (count($department_data) > 0): ?>
                                    <?php foreach ($department_data as $dept): 
                                        $approval_rate = $dept['total_amount'] > 0 ? ($dept['approved_amount'] / $dept['total_amount']) * 100 : 0;
                                    ?>
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?php echo htmlspecialchars($dept['department']); ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo $dept['request_count']; ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold text-gray-900">
                                            <div class="amount-cell">
                                                <span class="amount-value hidden-amount" data-value="₱<?php echo number_format((float)$dept['total_amount'], 2); ?>">
                                                    ********
                                                </span>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-green-600">
                                            <div class="amount-cell">
                                                <span class="amount-value hidden-amount" data-value="₱<?php echo number_format((float)$dept['approved_amount'], 2); ?>">
                                                    ********
                                                </span>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-yellow-600">
                                            <div class="amount-cell">
                                                <span class="amount-value hidden-amount" data-value="₱<?php echo number_format((float)$dept['pending_amount'], 2); ?>">
                                                    ********
                                                </span>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-red-600">
                                            <div class="amount-cell">
                                                <span class="amount-value hidden-amount" data-value="₱<?php echo number_format((float)$dept['rejected_amount'], 2); ?>">
                                                    ********
                                                </span>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm">
                                            <span class="font-medium <?php echo $approval_rate >= 70 ? 'text-green-600' : ($approval_rate >= 50 ? 'text-yellow-600' : 'text-red-600'); ?>">
                                                <?php echo number_format($approval_rate, 1); ?>%
                                            </span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="7" class="px-6 py-8 text-center text-gray-500">
                                            <div class="flex flex-col items-center justify-center">
                                                <i class='bx bx-pie-chart-alt text-4xl text-gray-300 mb-2'></i>
                                                <div class="text-lg font-medium text-gray-400">No disbursement data found</div>
                                                <div class="text-sm text-gray-400 mt-1">No department data for the last 30 days</div>
                                            </div>
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

        // Function to hide all amounts
        function hideAmounts() {
            // Handle amount-display elements (stat cards)
            const amountDisplays = document.querySelectorAll('.amount-display');
            amountDisplays.forEach(display => {
                const originalText = display.getAttribute('data-original');
                if (originalText && !display.classList.contains('hidden-amount')) {
                    if (originalText.includes('₱')) {
                        const amount = originalText.replace('₱ ', '');
                        const hiddenAmount = '₱ ' + '•'.repeat(Math.max(amount.length, 8));
                        display.textContent = hiddenAmount;
                    }
                }
                display.classList.add('hidden-amount');
            });
            
            // Handle amount-value elements (progress bars and tables)
            const amountValues = document.querySelectorAll('.amount-value');
            amountValues.forEach(display => {
                const originalText = display.getAttribute('data-value');
                if (originalText && !display.classList.contains('hidden-amount')) {
                    if (originalText.includes('₱')) {
                        const amount = originalText.replace('₱', '');
                        const hiddenAmount = '₱' + '•'.repeat(Math.max(amount.length, 8));
                        display.textContent = hiddenAmount;
                    } else {
                        const hiddenAmount = '•'.repeat(originalText.length);
                        display.textContent = hiddenAmount;
                    }
                }
                display.classList.add('hidden-amount');
            });
            
            hideAmountsBtn.innerHTML = '<i class="bx bx-show text-xl text-gray-600"></i>';
            hideAmountsBtn.title = "Show amounts";
            amountsHidden = true;
        }

        // Function to show all amounts
        function showAmounts() {
            // Handle amount-display elements
            const amountDisplays = document.querySelectorAll('.amount-display');
            amountDisplays.forEach(display => {
                const originalText = display.getAttribute('data-original');
                if (originalText) {
                    display.textContent = originalText;
                }
                display.classList.remove('hidden-amount');
            });
            
            // Handle amount-value elements
            const amountValues = document.querySelectorAll('.amount-value');
            amountValues.forEach(display => {
                const originalText = display.getAttribute('data-value');
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
            // Hide amounts on page load
            hideAmounts();
            
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

        // Monthly Trends Chart
        const trendsCtx = document.getElementById('monthlyTrendsChart').getContext('2d');
        const monthlyTrendsChart = new Chart(trendsCtx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode(array_column($monthly_trends, 'month')); ?>,
                datasets: [
                    {
                        label: 'Total Amount',
                        data: <?php echo json_encode(array_column($monthly_trends, 'total_amount')); ?>,
                        backgroundColor: '#059669',
                        borderRadius: 6,
                    },
                    {
                        label: 'Approved',
                        data: <?php echo json_encode(array_column($monthly_trends, 'approved_amount')); ?>,
                        backgroundColor: '#34D399',
                        borderRadius: 6,
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: {
                            drawBorder: false,
                        },
                        ticks: {
                            callback: function(value) {
                                return '₱' + (value/1000).toFixed(0) + 'K';
                            }
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        }
                    }
                },
                plugins: {
                    legend: {
                        position: 'top',
                    }
                }
            }
        });

        // Export and Print Functionality
        const exportCsvBtn = document.getElementById('export-csv-btn');
        const printReportBtn = document.getElementById('print-report-btn');
        const exportPdfBtn = document.getElementById('export-pdf-btn');

        // CSV Export
        if (exportCsvBtn) {
            exportCsvBtn.addEventListener('click', function() {
                window.location.href = '?export=csv';
            });
        }

        // Print Functionality
        if (printReportBtn) {
            printReportBtn.addEventListener('click', function() {
                window.print();
            });
        }

        // PDF Export using jsPDF
        if (exportPdfBtn) {
            exportPdfBtn.addEventListener('click', function() {
                exportToPDF();
            });
        }

        // PDF Export Function
        function exportToPDF() {
            // Show loading indicator
            const originalText = exportPdfBtn.innerHTML;
            exportPdfBtn.innerHTML = '<div class="spinner"></div>Generating PDF...';
            exportPdfBtn.disabled = true;

            // Use html2canvas and jsPDF to generate PDF
            const { jsPDF } = window.jspdf;
            
            html2canvas(document.getElementById('print-section'), {
                scale: 2,
                useCORS: true,
                logging: false
            }).then(canvas => {
                const imgData = canvas.toDataURL('image/png');
                const pdf = new jsPDF('p', 'mm', 'a4');
                const imgWidth = 210; // A4 width in mm
                const pageHeight = 295; // A4 height in mm
                const imgHeight = canvas.height * imgWidth / canvas.width;
                let heightLeft = imgHeight;
                let position = 0;

                pdf.addImage(imgData, 'PNG', 0, position, imgWidth, imgHeight);
                heightLeft -= pageHeight;

                while (heightLeft >= 0) {
                    position = heightLeft - imgHeight;
                    pdf.addPage();
                    pdf.addImage(imgData, 'PNG', 0, position, imgWidth, imgHeight);
                    heightLeft -= pageHeight;
                }

                pdf.save('disbursement_report_<?php echo date('Y-m-d'); ?>.pdf');
                
                // Restore button state
                exportPdfBtn.innerHTML = originalText;
                exportPdfBtn.disabled = false;
            }).catch(error => {
                console.error('Error generating PDF:', error);
                alert('Error generating PDF. Please try again.');
                exportPdfBtn.innerHTML = originalText;
                exportPdfBtn.disabled = false;
            });
        }
    </script>
</body>
</html>