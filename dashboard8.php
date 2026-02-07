<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/database.php';

try {
    // --- DB connection ---
    $database = new Database();
    $db = $database->getConnection();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

} catch (Throwable $e) {
    error_log("Database connection error: " . $e->getMessage());
    http_response_code(500);
    die("Database connection error. Please try again later.");
}

// --- Auth Guard ---
if (empty($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$user_id = (int)$_SESSION['user_id'];
if ($user_id <= 0) {
    header("Location: index.php");
    exit;
}

// --- Logout Logic ---
if (isset($_GET['logout']) && $_GET['logout'] === 'true') {
    $_SESSION = [];
    session_destroy();
    header("Location: index.php");
    exit;
}

// --- Load Current User ---
$u = $db->prepare("SELECT id, name, username, role FROM users WHERE id = ?");
$u->execute([$user_id]);
$user = $u->fetch();
if (!$user) {
    header("Location: index.php");
    exit;
}

// --- Add this block to handle Disbursement Creation ---
if (isset($_POST['action']) && $_POST['action'] === 'create_disbursement') {
    try {
        // Check if table exists (create if not - safety check)
        $db->query("SELECT 1 FROM disbursement_requests LIMIT 1");
        
        $stmt = $db->prepare("INSERT INTO disbursement_requests (requested_by, department, description, amount, status, date_requested) VALUES (?, ?, ?, ?, 'Pending', NOW())");
        
        // Assuming the 'requested_by' field in form is a name string. 
        // If you want to link to user ID, you might need to adjust logic, but this matches your form.
        $stmt->execute([
            $_POST['requested_by'], 
            $_POST['department'], 
            $_POST['description'], 
            $_POST['amount']
        ]);
        
        // Redirect to avoid resubmission
        header("Location: dashboard8.php?success=1");
        exit;
    } catch (Exception $e) {
        // Table likely doesn't exist or DB error
    }
}
// -----------------------------------------------------

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

// --- Functions ---

function loadNotificationsFromDatabase(PDO $db, int $user_id): array {
    // Check/Create table logic (Simplified for production speed, keeping your logic)
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
        // Insert defaults if table just created... (omitted for brevity, keeping your existing logic)
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

function getDashboardStats(PDO $db): array {
    // 1. Income (Galing sa Lending Collections)
    $revStmt = $db->query("SELECT COALESCE(SUM(paid_amount), 0) as total FROM invoices WHERE type = 'Receivable'");
    $rev = $revStmt->fetch()['total'] ?? 0;
    
    // 2. Expenses (Bills Payment + HR Payroll)
    // A. Kunin ang bayad sa Bills (Core Budget)
    $billStmt = $db->query("SELECT COALESCE(SUM(paid_amount), 0) as total FROM invoices WHERE type = 'Payable'");
    $bills = $billStmt->fetch()['total'] ?? 0;

    // B. Kunin ang bayad sa Sweldo (HR Payroll)
    try {
        // Try to join with departments table first
        $hrStmt = $db->query("
            SELECT COALESCE(SUM(dr.amount), 0) as total 
            FROM disbursement_requests dr
            LEFT JOIN departments d ON dr.department_id = d.id
            WHERE (d.department_name = 'HR Payroll' OR dr.department = 'HR Payroll') 
            AND dr.status = 'Approved'
        ");
    } catch (Exception $e) {
        // Fallback if departments table doesn't exist
        $hrStmt = $db->query("
            SELECT COALESCE(SUM(amount), 0) as total 
            FROM disbursement_requests 
            WHERE department = 'HR Payroll' 
            AND status = 'Approved'
        ");
    }
    $payroll = $hrStmt->fetch()['total'] ?? 0;

    // Total Expenses = Bills + Payroll
    $totalExpenses = $bills + $payroll;
    
    // 3. Cash Flow
    $cashFlow = (float)$rev - (float)$totalExpenses;
    
    // 4. Upcoming Payments (Bills na hindi pa bayad)
    try {
        $stmt = $db->query("SELECT COALESCE(SUM(amount - paid_amount), 0) as total FROM invoices WHERE due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND status != 'Paid' AND type='Payable'");
        $upcoming = $stmt->fetch()['total'] ?? 0;
    } catch (Exception $e) { $upcoming = 0; }

    return [
        'total_income'      => (float)$rev,
        'total_expenses'    => (float)$totalExpenses,
        'cash_flow'         => (float)$cashFlow,
        'upcoming_payments' => (float)$upcoming,
    ];
}

function getRecentTransactions(PDO $db, int $limit = 5): array {
    // Check tables exist to prevent crash
    $tables = [];
    foreach(['disbursement_requests', 'payments'] as $t) {
        try { $db->query("SELECT 1 FROM $t LIMIT 1"); $tables[] = $t; } catch(Exception $e){}
    }
    
    $parts = [];
    if(in_array('disbursement_requests', $tables)) {
        $parts[] = "SELECT 'Disbursement' as type, id, request_id as ref, description as name, date_requested as date, amount, status FROM disbursement_requests";
    }
    if(in_array('payments', $tables)) {
        $parts[] = "SELECT 'Payment' as type, id, payment_id as ref, 'Payment' as name, payment_date as date, amount, status FROM payments WHERE type='Receive'"; // Assuming 'Receive' is income
    }
    
    if(empty($parts)) return [];
    
    $sql = implode(" UNION ALL ", $parts) . " ORDER BY date DESC LIMIT " . $limit;
    return $db->query($sql)->fetchAll();
}

function getMonthlyChartData(PDO $db) {
    $incomeData = array_fill(0, 12, 0);
    $expenseData = array_fill(0, 12, 0);
    $labels = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    
    try {
        // 1. GET INCOME (Collections)
        $incSql = "SELECT MONTH(created_at) as m, SUM(paid_amount) as total 
                   FROM invoices 
                   WHERE type='Receivable' AND YEAR(created_at) = YEAR(CURDATE()) 
                   GROUP BY m";
        $incStmt = $db->query($incSql);
        while($row = $incStmt->fetch()) {
            $incomeData[$row['m'] - 1] = (float)$row['total'];
        }
        
        // 2. GET EXPENSES (Bills + Payroll)
        // A. Bills (Invoices)
        $billSql = "SELECT MONTH(created_at) as m, SUM(paid_amount) as total 
                   FROM invoices 
                   WHERE type='Payable' AND YEAR(created_at) = YEAR(CURDATE()) 
                   GROUP BY m";
        $billStmt = $db->query($billSql);
        while($row = $billStmt->fetch()) {
            $expenseData[$row['m'] - 1] += (float)$row['total']; // Use += para mag-add
        }

        // B. Payroll (Disbursement Requests)
        $hrSql = "SELECT MONTH(date_approved) as m, SUM(amount) as total 
                  FROM disbursement_requests 
                  WHERE department = 'HR Payroll' 
                  AND status = 'Approved' 
                  AND YEAR(date_approved) = YEAR(CURDATE()) 
                  GROUP BY m";
        $hrStmt = $db->query($hrSql);
        while($row = $hrStmt->fetch()) {
            $expenseData[$row['m'] - 1] += (float)$row['total']; // I-add sa existing expense
        }

    } catch (Exception $e) { }
    
    return ['labels' => $labels, 'income' => $incomeData, 'expense' => $expenseData];
}

function getBudgetDistribution(PDO $db) {
    $labels = [];
    $data = [];
    $colors = [
        '#059669', '#DC2626', '#2563EB', '#D97706', '#7C3AED',
        '#0891B2', '#DB2777', '#CA8A04', '#16A34A', '#9333EA'
    ];
    
    try {
        // Option 1: Group by department name from disbursement_requests
        $stmt = $db->query("
            SELECT 
                dr.department, 
                COALESCE(SUM(dr.amount), 0) as total,
                COALESCE(d.name, dr.department) as display_name
            FROM disbursement_requests dr
            LEFT JOIN departments d ON d.name = dr.department
            WHERE dr.status IN ('Approved', 'Pending', 'Completed') 
            GROUP BY dr.department
            ORDER BY total DESC
        ");
        
        while($row = $stmt->fetch()) {
            $labels[] = $row['display_name'];
            $data[] = (float)$row['total'];
        }
    } catch (Exception $e) {
        // Fallback if join fails
        try {
            $stmt = $db->query("
                SELECT department, COALESCE(SUM(amount), 0) as total 
                FROM disbursement_requests 
                WHERE status IN ('Approved', 'Pending', 'Completed') 
                GROUP BY department
                ORDER BY total DESC
            ");
            
            while($row = $stmt->fetch()) {
                $labels[] = $row['department'];
                $data[] = (float)$row['total'];
            }
        } catch (Exception $e2) {
            // Table missing
        }
    }
    
    // Default data if empty
    if (empty($data)) {
        return [
            'labels' => ['No Data'], 
            'data' => [1], 
            'colors' => ['#e5e7eb']
        ];
    }
    
    // Assign colors based on data length
    $assignedColors = [];
    for($i = 0; $i < count($data); $i++) {
        $assignedColors[] = $colors[$i % count($colors)];
    }
    
    return ['labels' => $labels, 'data' => $data, 'colors' => $assignedColors];
}

function getUpcomingDueDates(PDO $db) {
    try {
        // Check if table exists
        $db->query("SELECT 1 FROM invoices LIMIT 1");
        
        // Fetch pending payable invoices due in the future, sorted by closest date
        $stmt = $db->query("
            SELECT i.id, i.invoice_number, i.due_date, i.amount, c.name as vendor_name
            FROM invoices i
            LEFT JOIN business_contacts c ON i.contact_id = c.id
            WHERE i.type = 'Payable' 
            AND i.status = 'Pending' 
            AND i.due_date >= CURDATE()
            ORDER BY i.due_date ASC
            LIMIT 5
        ");
        
        $results = $stmt->fetchAll();
        
        // Format for the frontend
        $formatted = [];
        foreach($results as $row) {
            // Display "Vendor Name" or "Invoice #123" if vendor is missing
            $name = $row['vendor_name'] 
                ? $row['vendor_name'] . ' (#' . $row['invoice_number'] . ')' 
                : 'Invoice #' . $row['invoice_number'];
                
            $formatted[] = [
                'id' => $row['id'],
                'name' => $name,
                'date' => $row['due_date'],
                'amount' => (float)$row['amount']
            ];
        }
        return $formatted;
        
    } catch (Exception $e) {
        return []; // Return empty array if table doesn't exist yet
    }
}

// --- Execute Data Fetching ---
$dashboard_stats = getDashboardStats($db);
$show_all_transactions = isset($_GET['view']) && $_GET['view'] === 'all_transactions';
$recent_transactions = getRecentTransactions($db, $show_all_transactions ? 100 : 5);
$notifications = loadNotificationsFromDatabase($db, $user_id);
$chart_data = getMonthlyChartData($db); // Fetch data for charts
$budget_data = getBudgetDistribution($db);
$upcoming_due_dates = getUpcomingDueDates($db);

// Count Unread
$unreadCount = 0;
foreach($notifications as $n) { if(!$n['read']) $unreadCount++; }

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no" />
    <title><?php echo $show_all_transactions ? 'All Transactions - Financial Dashboard' : 'Financial Dashboard'; ?></title>

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
                    class="flex items-center justify-between px-4 py-3 rounded-xl bg-brand-primary text-white shadow
                           transition-all duration-200 active:scale-[0.99]">
                    <span class="flex items-center gap-3 font-semibold">
                        <span class="inline-flex w-8 h-8 rounded-lg bg-white/15 items-center justify-center">
                            <i class='bx bx-home text-white text-sm'></i>
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
                        <div id="collection-submenu" class="submenu mt-1">
                            <div class="pl-4 pr-2 py-1.5 space-y-1 border-l-2 border-gray-100 ml-5">
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
                <?php echo $show_all_transactions ? 'All Transactions' : 'Financials with Predictive Budgeting and Cash Flow Forecasting using Time Series Analysis'; ?>
            </h1>
            <p class="text-xs text-gray-500 truncate">
                <?php echo $show_all_transactions ? 'Complete transaction history' : 'Welcome Back, ' . htmlspecialchars($user['name']) . '!'; ?>
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
            <?php if($unreadCount > 0): ?>
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
            <?php if ($show_all_transactions): ?>
                <!-- All Transactions View -->
                <div class="space-y-6">
                    <!-- Header -->
                    <div class="flex justify-between items-center mb-6">
                        <div>
                            <h2 class="text-2xl font-bold text-gray-800">All Transactions</h2>
                            <p class="text-gray-600 text-sm">Complete transaction history - Total: <?php echo count($recent_transactions); ?> transactions</p>
                        </div>
                        <div class="flex space-x-3">
                            <button onclick="window.location.href='dashboard8.php'" 
                                    class="px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 transition flex items-center gap-2">
                                <i class="fas fa-arrow-left"></i> Back
                            </button>
                            <button id="print-transactions" class="px-4 py-2 bg-brand-primary text-white rounded-lg hover:bg-brand-primary-hover transition flex items-center gap-2">
                                <i class="fas fa-print"></i> Print
                            </button>
                        </div>
                    </div>

                    <!-- Transaction Summary -->
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                        <?php
                        $totalAmount = array_sum(array_column($recent_transactions, 'amount'));
                        $completed = array_filter($recent_transactions, function($t) {
                            return in_array($t['status'], ['Completed', 'Approved']);
                        });
                        $pending = array_filter($recent_transactions, function($t) {
                            return $t['status'] === 'Pending';
                        });
                        ?>
                        <div class="stat-card rounded-xl p-4">
                            <div class="text-sm text-gray-500">Total Transactions</div>
                            <div class="text-2xl font-bold text-brand-primary"><?php echo count($recent_transactions); ?></div>
                        </div>
                        <div class="stat-card rounded-xl p-4">
                            <div class="text-sm text-gray-500">Total Amount</div>
                            <div class="text-2xl font-bold text-green-600">
                                ₱<?php echo number_format($totalAmount, 2); ?>
                            </div>
                        </div>
                        <div class="stat-card rounded-xl p-4">
                            <div class="text-sm text-gray-500">Completed/Approved</div>
                            <div class="text-2xl font-bold text-blue-600"><?php echo count($completed); ?></div>
                        </div>
                        <div class="stat-card rounded-xl p-4">
                            <div class="text-sm text-gray-500">Pending</div>
                            <div class="text-2xl font-bold text-yellow-600"><?php echo count($pending); ?></div>
                        </div>
                    </div>

                    <!-- Transactions Table -->
                    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                        <div class="p-6 border-b border-gray-100">
                            <div class="flex justify-between items-center">
                                <div class="flex items-center gap-3">
                                    <h3 class="text-lg font-bold text-gray-800">All Transactions</h3>
                                    <button id="all-transactions-visibility-toggle" class="text-gray-500 hover:text-brand-primary transition">
                                        <i class="fa-solid fa-eye-slash"></i>
                                    </button>
                                </div>
                                <span class="text-sm text-gray-500">
                                    Showing <?php echo count($recent_transactions); ?> transactions
                                </span>
                            </div>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="w-full">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="text-left p-4 text-sm font-medium text-gray-500">Transaction</th>
                                        <th class="text-left p-4 text-sm font-medium text-gray-500">Type</th>
                                        <th class="text-left p-4 text-sm font-medium text-gray-500">Date</th>
                                        <th class="text-left p-4 text-sm font-medium text-gray-500">Amount</th>
                                        <th class="text-left p-4 text-sm font-medium text-gray-500">Status</th>
                                        <th class="text-left p-4 text-sm font-medium text-gray-500">Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="all-transactions-table-body">
                                    <!-- Transactions will be loaded via JavaScript -->
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <!-- Dashboard View -->
                <div class="space-y-6">
                    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-2">
                <div>
                    <h2 class="text-2xl font-bold text-gray-800">Financial Overview</h2>
                    <p class="text-gray-500 text-sm">Real-time financial status and projections</p>
                </div>
                
                <a href="budget_forecast.php" 
                   class="flex items-center gap-3 px-5 py-3 bg-gradient-to-r from-purple-600 to-indigo-600 text-white rounded-xl shadow-md hover:shadow-lg hover:scale-105 transition-all duration-200 group border border-purple-400/30 relative overflow-hidden">
                    
                    <div class="absolute inset-0 bg-white/10 opacity-0 group-hover:opacity-100 transition-opacity"></div>

                    <div class="p-1.5 bg-white/20 rounded-lg group-hover:rotate-12 transition-transform backdrop-blur-sm">
                        <i class='bx bx-brain text-xl'></i>
                    </div>
                    <div class="text-left">
                        <div class="text-[10px] uppercase font-bold text-purple-100 tracking-wider">New Feature</div>
                        <div class="font-bold leading-none text-sm">AI Budget Forecast</div>
                    </div>
                    <i class='bx bx-chevron-right text-xl opacity-70 group-hover:translate-x-1 transition-transform'></i>
                </a>
            </div>
                    <!-- Stats Overview -->
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                        <?php 
                        $stats = [
                            ['icon' => 'bx-money', 'label' => 'Total Income', 'value' => $dashboard_stats['total_income'], 'color' => 'green', 'stat' => 'income'],
                            ['icon' => 'bx-credit-card', 'label' => 'Total Expenses', 'value' => $dashboard_stats['total_expenses'], 'color' => 'red', 'stat' => 'expenses'],
                            ['icon' => 'bx-wallet', 'label' => 'Cash Flow', 'value' => $dashboard_stats['cash_flow'], 'color' => 'yellow', 'stat' => 'cashflow'],
                            ['icon' => 'bx-calendar', 'label' => 'Upcoming Payments', 'value' => $dashboard_stats['upcoming_payments'], 'color' => 'blue', 'stat' => 'payments']
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
   data-value="₱<?php echo number_format($stat['value'], 2); ?>"
   data-stat="<?php echo $stat['stat']; ?>">
    ••••••••
</p>
                                        </div>
                                        <button class="stat-toggle text-gray-400 hover:text-brand-primary transition"
                                                data-stat="<?php echo $stat['stat']; ?>">
                                            <i class="fa-solid fa-eye-slash"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Charts Section -->
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                        <!-- Income vs Expenses Chart -->
                        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                            <div class="flex justify-between items-center mb-6">
                                <h3 class="text-lg font-bold text-gray-800">Income vs Expenses</h3>
                                <div class="flex items-center gap-4">
                                    <div class="flex items-center gap-2">
                                        <div class="w-3 h-3 rounded-full bg-brand-primary"></div>
                                        <span class="text-sm text-gray-500">Income</span>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <div class="w-3 h-3 rounded-full bg-emerald-400"></div>
                                        <span class="text-sm text-gray-500">Expenses</span>
                                    </div>
                                </div>
                            </div>
                            <div class="chart-container">
                                <canvas id="incomeExpenseChart"></canvas>
                            </div>
                        </div>

                        <!-- Budget Distribution Chart -->
                        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                            <div class="flex justify-between items-center mb-6">
                                <h3 class="text-lg font-bold text-gray-800">Budget Distribution</h3>
                                <button id="refresh-budget-chart" class="text-brand-primary hover:text-brand-primary-hover transition">
                                    <i class='bx bx-refresh text-xl'></i>
                                </button>
                            </div>
                            <div class="chart-container">
                                <canvas id="budgetChart"></canvas>
                            </div>
                        </div>
                    </div>

                    <!-- Recent Transactions & Upcoming Payments -->
                    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                        <!-- Recent Transactions -->
                        <div class="lg:col-span-2 bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                            <div class="p-6 border-b border-gray-100">
                                <div class="flex justify-between items-center">
                                    <div class="flex items-center gap-3">
                                        <h3 class="text-lg font-bold text-gray-800">Recent Transactions</h3>
                                        
                                    </div>
                                    <button id="view-all-transactions" class="text-brand-primary hover:text-brand-primary-hover text-sm font-medium flex items-center gap-1">
                                        View all <i class='bx bx-chevron-right'></i>
                                    </button>
                                </div>
                            </div>
                            <div class="overflow-x-auto">
                                <table class="w-full">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th class="text-left p-4 text-sm font-medium text-gray-500">Transaction</th>
                                            <th class="text-left p-4 text-sm font-medium text-gray-500">Date</th>
                                            <th class="text-left p-4 text-sm font-medium text-gray-500">Amount</th>
                                            <th class="text-left p-4 text-sm font-medium text-gray-500">Status</th>
                                            <th class="text-left p-4 text-sm font-medium text-gray-500"></th>
                                        </tr>
                                    </thead>
                                    <tbody id="transactions-table-body">
                                        <!-- Transactions will be loaded via JavaScript -->
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- Upcoming Payments & Notifications -->
                        <div class="space-y-6">
                            <!-- Upcoming Payments -->
                            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                                <div class="flex justify-between items-center mb-6">
                                    <div class="flex items-center gap-3">
                                        <h3 class="text-lg font-bold text-gray-800">Upcoming Payments</h3>
                                        
                                    </div>
                                    <button id="view-all-due-dates" class="text-brand-primary hover:text-brand-primary-hover text-sm font-medium">
                                        View all
                                    </button>
                                </div>
                                <div class="space-y-4" id="due-dates-list">
                                    <!-- Due dates will be loaded via JavaScript -->
                                </div>
                            </div>

                            <!-- Recent Notifications -->
                            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                                <div class="flex justify-between items-center mb-6">
                                    <h3 class="text-lg font-bold text-gray-800">Recent Notifications</h3>
                                    <button id="view-all-notifications" class="text-brand-primary hover:text-brand-primary-hover text-sm font-medium">
                                        View all
                                    </button>
                                </div>
                                <div class="space-y-4" id="notifications-list">
                                    <!-- Notifications will be loaded via JavaScript -->
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
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

        const showAllTransactions = <?php echo $show_all_transactions ? 'true' : 'false'; ?>;
        
        if (showAllTransactions) {
            initializeAllTransactionsView();
        } else {
            initializeDashboardView();
        }

        initializeCommonFeatures();
        initializeAllVisibility(); // Initialize all visibility states
    });

    function initializeAllTransactionsView() {
        // Load all transactions
        const transactions = <?php echo json_encode($recent_transactions); ?>;
        const tableBody = document.getElementById('all-transactions-table-body');
        
        if (tableBody && transactions) {
            tableBody.innerHTML = '';
            
            if (transactions.length === 0) {
                tableBody.innerHTML = `
                    <tr>
                        <td colspan="6" class="p-8 text-center text-gray-500">
                            <i class='bx bx-folder-open text-3xl mb-2 text-gray-300'></i>
                            <div>No transactions found</div>
                        </td>
                    </tr>
                `;
                return;
            }
            
            const savedVisibility = localStorage.getItem('allTransactionsVisible') === 'true';
            const toggleBtn = document.getElementById('all-transactions-visibility-toggle');
            
            if (toggleBtn) {
                const icon = toggleBtn.querySelector('i');
                if (savedVisibility) {
                    icon.classList.remove('fa-eye-slash');
                    icon.classList.add('fa-eye');
                    toggleBtn.title = "Hide Amounts";
                } else {
                    icon.classList.remove('fa-eye');
                    icon.classList.add('fa-eye-slash');
                    toggleBtn.title = "Show Amounts";
                }
                
                toggleBtn.addEventListener('click', function() {
                    const current = localStorage.getItem('allTransactionsVisible') === 'true';
                    const newState = !current;
                    localStorage.setItem('allTransactionsVisible', newState);
                    
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
                    
                    // Update all amounts
                    const amountCells = tableBody.querySelectorAll('.transaction-amount');
                    amountCells.forEach(cell => {
                        const actualValue = cell.getAttribute('data-value');
                        if (newState) {
                            cell.textContent = actualValue;
                            cell.classList.remove('hidden-amount');
                        } else {
                            cell.textContent = '••••••••';
                            cell.classList.add('hidden-amount');
                        }
                    });
                });
            }
            
            transactions.forEach(transaction => {
                const statusClass = transaction.status === 'Completed' || transaction.status === 'Approved' ? 'status-approved' : 
                                  transaction.status === 'Rejected' ? 'status-rejected' : 'status-pending';
                const formattedAmount = `₱${parseFloat(transaction.amount || 0).toLocaleString(undefined, {minimumFractionDigits: 2})}`;
                const displayAmount = savedVisibility ? formattedAmount : '••••••••';
                const amountClass = savedVisibility ? '' : 'hidden-amount';
                
                const row = document.createElement('tr');
                row.className = 'transaction-row';
                row.innerHTML = `
                    <td class="p-4">
                        <div class="font-medium text-gray-800">${transaction.name || 'N/A'}</div>
                        <div class="text-xs text-gray-500">Ref: ${transaction.ref || 'N/A'}</div>
                    </td>
                    <td class="p-4">
                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-blue-50 text-blue-700">
                            ${transaction.type || 'Transaction'}
                        </span>
                    </td>
                    <td class="p-4 text-gray-600">${transaction.date || 'N/A'}</td>
                    <td class="p-4 font-medium text-gray-800 transaction-amount ${amountClass}" data-value="${formattedAmount}">
                        ${displayAmount}
                    </td>
                    <td class="p-4">
                        <span class="status-badge ${statusClass}">${transaction.status || 'Pending'}</span>
                    </td>
                    <td class="p-4">
                        <button class="px-3 py-1 text-sm bg-brand-primary text-white rounded-lg hover:bg-brand-primary-hover transition view-transaction-btn"
                                data-id="${transaction.id}" 
                                data-type="${transaction.type}"
                                data-status="${transaction.status}">
                            View
                        </button>
                    </td>
                `;
                tableBody.appendChild(row);
            });
            
            // Add event listeners to view buttons
            document.querySelectorAll('.view-transaction-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    const id = this.getAttribute('data-id');
                    const type = this.getAttribute('data-type');
                    const status = this.getAttribute('data-status');
                    viewTransactionDetails(id, type, status);
                });
            });
        }
        
        // Print button
        const printBtn = document.getElementById('print-transactions');
        if (printBtn) {
            printBtn.addEventListener('click', function() {
                window.print();
            });
        }
    }

    function initializeDashboardView() {
        // Initialize charts
        initializeCharts();
        
        // Load transactions
        loadTransactions();
        
        // Load due dates
        loadDueDates();
        
        // Load notifications
        loadNotifications();
        
        // View all transactions button
        const viewAllBtn = document.getElementById('view-all-transactions');
        if (viewAllBtn) {
            viewAllBtn.addEventListener('click', function() {
                window.location.href = 'dashboard8.php?view=all_transactions';
            });
        }
        
        // View all due dates button
        const viewAllDueBtn = document.getElementById('view-all-due-dates');
        if (viewAllDueBtn) {
            viewAllDueBtn.addEventListener('click', function() {
                window.location.href = 'aging_reports.php';
            });
        }
        
        // Refresh budget chart button
        const refreshBtn = document.getElementById('refresh-budget-chart');
        if (refreshBtn) {
            refreshBtn.addEventListener('click', function() {
                this.innerHTML = '<div class="spinner"></div>';
                setTimeout(() => {
                    this.innerHTML = '<i class="bx bx-refresh text-xl"></i>';
                    // Here you could reload the chart data via AJAX
                }, 1000);
            });
        }
    }

    function loadTransactions() {
        const transactions = <?php echo json_encode($recent_transactions); ?>;
        const tableBody = document.getElementById('transactions-table-body');
        
        if (tableBody && transactions) {
            tableBody.innerHTML = '';
            
            if (transactions.length === 0) {
                tableBody.innerHTML = `
                    <tr>
                        <td colspan="5" class="p-8 text-center text-gray-500">
                            <i class='bx bx-folder-open text-3xl mb-2 text-gray-300'></i>
                            <div>No recent transactions</div>
                        </td>
                    </tr>
                `;
                return;
            }
            
            const savedVisibility = localStorage.getItem('transactionsVisible') === 'true';
            const toggleBtn = document.getElementById('transactions-visibility-toggle');
            
            if (toggleBtn) {
                const icon = toggleBtn.querySelector('i');
                if (savedVisibility) {
                    icon.classList.remove('fa-eye-slash');
                    icon.classList.add('fa-eye');
                    toggleBtn.title = "Hide Amounts";
                } else {
                    icon.classList.remove('fa-eye');
                    icon.classList.add('fa-eye-slash');
                    toggleBtn.title = "Show Amounts";
                }
                
                toggleBtn.addEventListener('click', function() {
                    const current = localStorage.getItem('transactionsVisible') === 'true';
                    const newState = !current;
                    localStorage.setItem('transactionsVisible', newState);
                    
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
                    
                    // Update all amounts
                    const amountCells = tableBody.querySelectorAll('.transaction-amount');
                    amountCells.forEach(cell => {
                        const actualValue = cell.getAttribute('data-value');
                        if (newState) {
                            cell.textContent = actualValue;
                            cell.classList.remove('hidden-amount');
                        } else {
                            cell.textContent = '••••••••';
                            cell.classList.add('hidden-amount');
                        }
                    });
                    
                    updateAfterIndividualToggle(); // Update main toggle
                });
            }
            
            // Show only first 5 transactions for dashboard
            const displayTransactions = transactions.slice(0, 5);
            
            displayTransactions.forEach(transaction => {
                const statusClass = transaction.status === 'Completed' || transaction.status === 'Approved' ? 'status-approved' : 
                                  transaction.status === 'Rejected' ? 'status-rejected' : 'status-pending';
                const formattedAmount = `₱${parseFloat(transaction.amount || 0).toLocaleString(undefined, {minimumFractionDigits: 2})}`;
                const displayAmount = savedVisibility ? formattedAmount : '••••••••';
                const amountClass = savedVisibility ? '' : 'hidden-amount';
                
                const row = document.createElement('tr');
                row.className = 'transaction-row';
                row.innerHTML = `
                    <td class="p-4">
                        <div class="font-medium text-gray-800">${transaction.name || 'N/A'}</div>
                        <div class="text-xs text-gray-500">${transaction.type || 'Transaction'}</div>
                    </td>
                    <td class="p-4 text-gray-600">${transaction.date || 'N/A'}</td>
                    <td class="p-4 font-medium text-gray-800 transaction-amount ${amountClass}" data-value="${formattedAmount}">
                        ${displayAmount}
                    </td>
                    <td class="p-4">
                        <span class="status-badge ${statusClass}">${transaction.status || 'Pending'}</span>
                    </td>
                    <td class="p-4">
                        <button class="text-brand-primary hover:text-brand-primary-hover transition view-transaction-btn"
                                data-id="${transaction.id}" 
                                data-type="${transaction.type}"
                                data-status="${transaction.status}">
                            <i class='bx bx-show'></i>
                        </button>
                    </td>
                `;
                tableBody.appendChild(row);
            });
            
            // Add event listeners to view buttons
            document.querySelectorAll('.view-transaction-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    const id = this.getAttribute('data-id');
                    const type = this.getAttribute('data-type');
                    const status = this.getAttribute('data-status');
                    viewTransactionDetails(id, type, status);
                });
            });
        }
    }

    function loadDueDates() {
        const dueDates = <?php echo json_encode($upcoming_due_dates); ?>;
        const dueDatesList = document.getElementById('due-dates-list');
        
        if (dueDatesList) {
            dueDatesList.innerHTML = '';
            
            if (dueDates.length === 0) {
                dueDatesList.innerHTML = `
                    <div class="text-center py-8 text-gray-500">
                        <i class='bx bx-check-circle text-3xl mb-2 text-green-500'></i>
                        <div>No upcoming payments due</div>
                    </div>
                `;
                return;
            }
            
            const savedVisibility = localStorage.getItem('dueDatesVisible') === 'true';
            const toggleBtn = document.getElementById('due-dates-visibility-toggle');
            
            if (toggleBtn) {
                const icon = toggleBtn.querySelector('i');
                if (savedVisibility) {
                    icon.classList.remove('fa-eye-slash');
                    icon.classList.add('fa-eye');
                    toggleBtn.title = "Hide Amounts";
                } else {
                    icon.classList.remove('fa-eye');
                    icon.classList.add('fa-eye-slash');
                    toggleBtn.title = "Show Amounts";
                }
                
                toggleBtn.addEventListener('click', function() {
                    const current = localStorage.getItem('dueDatesVisible') === 'true';
                    const newState = !current;
                    localStorage.setItem('dueDatesVisible', newState);
                    
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
                    
                    // Reload due dates with new visibility
                    loadDueDates();
                    updateAfterIndividualToggle(); // Update main toggle
                });
            }
            
            dueDates.forEach(item => {
                const date = new Date(item.date);
                const dateStr = date.toLocaleDateString('en-US', { 
                    month: 'short', 
                    day: 'numeric', 
                    year: 'numeric' 
                });
                
                const formattedAmount = `₱${parseFloat(item.amount || 0).toLocaleString(undefined, {minimumFractionDigits: 2})}`;
                const displayAmount = savedVisibility ? formattedAmount : '••••••••';
                const amountClass = savedVisibility ? '' : 'hidden-amount';
                
                const dueDateEl = document.createElement('div');
                dueDateEl.className = 'flex items-center justify-between p-4 bg-gray-50 rounded-lg hover:bg-gray-100 transition';
                dueDateEl.innerHTML = `
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-lg bg-red-50 flex items-center justify-center">
                            <i class='bx bx-calendar text-red-500'></i>
                        </div>
                        <div>
                            <div class="font-medium text-gray-800 text-sm">${item.name}</div>
                            <div class="text-xs text-red-500">Due ${dateStr}</div>
                        </div>
                    </div>
                    <div class="font-bold text-gray-800 ${amountClass}">${displayAmount}</div>
                `;
                dueDatesList.appendChild(dueDateEl);
            });
        }
    }

    function loadNotifications() {
        const notifications = <?php echo json_encode($notifications); ?>;
        const notificationsList = document.getElementById('notifications-list');
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
            
            // Show all notifications in modal, or first 3 in dashboard
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
        
        renderNotifications(notificationsList, notifications, false);
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

    function initializeCharts() {
        // Income vs Expenses Chart
        const incomeExpenseCtx = document.getElementById('incomeExpenseChart');
        if (incomeExpenseCtx) {
            const chartData = <?php echo json_encode($chart_data); ?>;
            
            new Chart(incomeExpenseCtx, {
                type: 'bar',
                data: {
                    labels: chartData.labels,
                    datasets: [
                        {
                            label: 'Income',
                            data: chartData.income,
                            backgroundColor: '#059669',
                            borderRadius: 6,
                        },
                        {
                            label: 'Expenses',
                            data: chartData.expense,
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
                                color: 'rgba(0,0,0,0.05)'
                            },
                            ticks: { 
                                callback: function(value) { 
                                    return '₱' + (value/1000).toFixed(0) + 'K'; 
                                },
                                font: { family: 'system-ui' }
                            }
                        },
                        x: { 
                            grid: { display: false }
                        }
                    },
                    plugins: { 
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: 'white',
                            titleColor: '#1F2937',
                            bodyColor: '#4B5563',
                            borderColor: '#D1FAE5',
                            borderWidth: 1,
                            padding: 12,
                            boxPadding: 6,
                            callbacks: {
                                label: function(context) {
                                    return `₱${context.parsed.y.toLocaleString()}`;
                                }
                            }
                        }
                    }
                }
            });
        }
        
        // Budget Chart
        const budgetCtx = document.getElementById('budgetChart');
        if (budgetCtx) {
            const budgetData = <?php echo json_encode($budget_data); ?>;
            
            new Chart(budgetCtx, {
                type: 'doughnut',
                data: {
                    labels: budgetData.labels,
                    datasets: [{
                        data: budgetData.data,
                        backgroundColor: budgetData.colors,
                        borderWidth: 0,
                        hoverOffset: 12
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '75%',
                    plugins: {
                        legend: {
                            position: 'right',
                            labels: {
                                boxWidth: 12,
                                padding: 15,
                                usePointStyle: true,
                                pointStyle: 'circle',
                                font: { family: 'system-ui', size: 12 }
                            }
                        },
                        tooltip: {
                            backgroundColor: 'white',
                            titleColor: '#1F2937',
                            bodyColor: '#4B5563',
                            borderColor: '#D1FAE5',
                            borderWidth: 1,
                            padding: 12,
                            boxPadding: 6,
                            callbacks: {
                                label: function(context) {
                                    let label = context.label || '';
                                    if (label) {
                                        label += ': ';
                                    }
                                    if (context.parsed !== null) {
                                        label += '₱' + context.parsed.toLocaleString();
                                    }
                                    return label;
                                }
                            }
                        }
                    }
                }
            });
        }
    }

    function viewTransactionDetails(transactionId, transactionType, transactionStatus = '') {
        let redirectUrl = '';
        let params = `?id=${transactionId}`;
        
        if (transactionStatus) {
            params += `&status=${transactionStatus}`;
        }
        
        switch(transactionType) {
            case 'Disbursement':
                if (transactionStatus === 'Pending') {
                    redirectUrl = `pending_disbursements.php${params}`;
                } else if (transactionStatus === 'Approved') {
                    redirectUrl = `approved_disbursements.php${params}`;
                } else if (transactionStatus === 'Rejected') {
                    redirectUrl = `rejected_disbursements.php${params}`;
                } else {
                    redirectUrl = `disbursement_request.php${params}`;
                }
                break;
            case 'Payment':
                redirectUrl = `payment_entry.php${params}`;
                break;
            case 'Invoice':
                redirectUrl = `invoices.php${params}`;
                break;
            case 'Journal':
                redirectUrl = `journal_entry.php${params}`;
                break;
            case 'Budget':
                redirectUrl = `budget_proposal.php${params}`;
                break;
            default:
                redirectUrl = `dashboard8.php${params}`;
                break;
        }
        
        window.location.href = redirectUrl;
    }

    // HELPER FUNCTIONS FOR VISIBILITY TOGGLES
    function updateMainToggleState() {
        const visibilityToggle = document.getElementById('visibility-toggle');
        if (!visibilityToggle) return;
        
        // Check if ALL types are visible
        const statTypes = ['income', 'expenses', 'cashflow', 'payments'];
        const allStatsVisible = statTypes.every(type => localStorage.getItem(`stat_${type}_visible`) === 'true');
        
        let allVisible = allStatsVisible;
        
        // Only check transactions/due dates if not in "all transactions" view
        if (!<?php echo $show_all_transactions ? 'true' : 'false'; ?>) {
            allVisible = allStatsVisible && 
                        localStorage.getItem('transactionsVisible') === 'true' &&
                        localStorage.getItem('dueDatesVisible') === 'true';
        }
        
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

    function initializeAllVisibility() {
        // Initialize stat visibility
        const statToggles = document.querySelectorAll('.stat-toggle');
        statToggles.forEach(toggle => {
            const statType = toggle.getAttribute('data-stat');
            const savedState = localStorage.getItem(`stat_${statType}_visible`);
            if (savedState !== null) {
                toggleStat(statType, savedState === 'true');
            } else {
                // Default to hidden
                toggleStat(statType, false);
                localStorage.setItem(`stat_${statType}_visible`, 'false');
            }
        });
        
        // Initialize other toggles if not in all transactions view
        if (!<?php echo $show_all_transactions ? 'true' : 'false'; ?>) {
            // Transactions
            const savedTransVisible = localStorage.getItem('transactionsVisible');
            if (savedTransVisible === null) {
                localStorage.setItem('transactionsVisible', 'false');
            }
            
            // Due dates
            const savedDueVisible = localStorage.getItem('dueDatesVisible');
            if (savedDueVisible === null) {
                localStorage.setItem('dueDatesVisible', 'false');
            }
        }
        
        updateMainToggleState();
    }

    function updateAfterIndividualToggle() {
        // Small delay to ensure localStorage is updated
        setTimeout(updateMainToggleState, 50);
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
        
        // Main visibility toggle - UPDATED
        const visibilityToggle = document.getElementById('visibility-toggle');
        if (visibilityToggle) {
            visibilityToggle.addEventListener('click', function() {
                // Get current state by checking if ANY are visible
                const statTypes = ['income', 'expenses', 'cashflow', 'payments'];
                const currentState = statTypes.every(type => localStorage.getItem(`stat_${type}_visible`) === 'true');
                const newState = !currentState;
                
                // Toggle all stat values
                const statToggles = document.querySelectorAll('.stat-toggle');
                statToggles.forEach(toggle => {
                    const statType = toggle.getAttribute('data-stat');
                    toggleStat(statType, newState);
                    localStorage.setItem(`stat_${statType}_visible`, newState);
                });
                
                // Also toggle transactions if in dashboard view
                if (!<?php echo $show_all_transactions ? 'true' : 'false'; ?>) {
                    localStorage.setItem('transactionsVisible', newState);
                    loadTransactions(); // Reload with new visibility
                    
                    localStorage.setItem('dueDatesVisible', newState);
                    loadDueDates(); // Reload with new visibility
                }
                
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
        
        // Individual stat toggles - UPDATED
        const statToggles = document.querySelectorAll('.stat-toggle');
        statToggles.forEach(toggle => {
            toggle.addEventListener('click', function() {
                const statType = this.getAttribute('data-stat');
                const currentState = localStorage.getItem(`stat_${statType}_visible`) === 'true';
                const newState = !currentState;
                
                toggleStat(statType, newState);
                localStorage.setItem(`stat_${statType}_visible`, newState);
                
                // Update main toggle state
                updateAfterIndividualToggle();
            });
            
            // Initialize individual stat states
            const statType = toggle.getAttribute('data-stat');
            const savedState = localStorage.getItem(`stat_${statType}_visible`);
            if (savedState !== null) {
                toggleStat(statType, savedState === 'true');
            }
        });
        
        // View all notifications button
        const viewAllNotificationsBtn = document.getElementById('view-all-notifications');
        if (viewAllNotificationsBtn && notificationModal) {
            viewAllNotificationsBtn.addEventListener('click', function() {
                notificationModal.style.display = 'block';
                document.body.style.overflow = 'hidden';
            });
        }
        
        // Initialize main toggle state
        updateMainToggleState();
    }
    </script>
</body>
</html>