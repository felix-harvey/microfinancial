<?php
declare(strict_types=1);
session_start();

// Check if user is logged in
if (empty($_SESSION['user_id'] ?? null)) {
    header("Location: index.php");
    exit;
}

try {
    require_once __DIR__ . '/database.php';
    $database = new Database();
    $db = $database->getConnection();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // Get filter parameters
    $fiscal_year = $_GET['fiscal_year'] ?? date('Y');
    $department = $_GET['department'] ?? '';
    $period = $_GET['period'] ?? 'year';

    // Get user info for report
    $user_id = (int)$_SESSION['user_id'];
    $user_stmt = $db->prepare("SELECT name FROM users WHERE id = ?");
    $user_stmt->execute([$user_id]);
    $user = $user_stmt->fetch();

    // SAME DATA FETCHING LOGIC AS YOUR MAIN PAGE
    $budget_sql = "
        SELECT 
            d.id as dept_id,
            CASE 
                WHEN d.name LIKE '%Logistics%' THEN 'Logistics'
                WHEN d.name LIKE '%Core%' OR d.name LIKE '%Finance%' THEN 'Core Budget'
                WHEN d.name LIKE '%HR%' OR d.name LIKE '%Payroll%' THEN 'HR Payroll'
                ELSE d.name 
            END as normalized_name,
            COALESCE(SUM(bp.total_amount), 0) as total_budget,
            COALESCE(SUM(bp.remaining_amount), 0) as remaining_budget,
            COUNT(DISTINCT bp.id) as proposal_count
        FROM budget_proposals bp
        LEFT JOIN departments d ON bp.department = d.id
        WHERE YEAR(bp.created_at) = ?
        AND bp.status = 'Approved'
    ";

    $budget_year = $fiscal_year;
    if (strpos($fiscal_year, '-') !== false) {
        $parts = explode('-', $fiscal_year);
        $budget_year = $parts[0];
    }

    if (!empty($department)) {
        $budget_sql .= " AND bp.department = ?";
    }

    $budget_sql .= " GROUP BY normalized_name ORDER BY total_budget DESC";
    
    $budget_stmt = $db->prepare($budget_sql);
    $budget_params = [$budget_year];
    if (!empty($department)) $budget_params[] = $department;
    $budget_stmt->execute($budget_params);
    $department_budgets = $budget_stmt->fetchAll();

    // Get actual expenses
    $actual_sql = "
        SELECT 
            CASE 
                WHEN dr.department = '1' OR dr.department LIKE '%HR%' OR dr.department LIKE '%Payroll%' THEN 'HR Payroll'
                WHEN dr.department = '2' OR dr.department LIKE '%Core%' OR d.name LIKE '%Finance%' THEN 'Core Budget'
                WHEN dr.department IN ('9', '10') OR dr.department LIKE '%Logistics%' OR d.name LIKE '%Logistics%' THEN 'Logistics'
                WHEN dr.department = '4' OR dr.department LIKE '%Operations%' OR d.name LIKE '%Operations%' THEN 'Operations'
                ELSE COALESCE(d.name, dr.department) 
            END as normalized_dept_name,
            
            COALESCE(SUM(dr.amount), 0) as total_expenses,
            COUNT(DISTINCT dr.id) as transaction_count
        FROM disbursement_requests dr
        LEFT JOIN departments d ON dr.department = d.id
        WHERE YEAR(dr.date_requested) = ?
        AND dr.status = 'Approved'
    ";
    
    $actual_params = [$budget_year];
    if (!empty($department)) {
        $actual_sql .= " AND (dr.department = ? OR d.id = ?)";
        $actual_params[] = $department;
        $actual_params[] = $department;
    }
    
    $actual_sql .= " GROUP BY normalized_dept_name";
    
    $actual_stmt = $db->prepare($actual_sql);
    $actual_stmt->execute($actual_params);
    $department_actuals = $actual_stmt->fetchAll();

    // Consolidate data
    $final_data_map = [];

    // Process budgets
    foreach ($department_budgets as $budget) {
        $key_name = $budget['normalized_name'] ?? 'Unknown';
        
        $annual_budget = (float)($budget['total_budget'] ?? 0);
        $total_budget = $annual_budget;
        if ($period === 'quarter') $total_budget /= 4;
        elseif ($period === 'month') $total_budget /= 12;

        if (!isset($final_data_map[$key_name])) {
            $final_data_map[$key_name] = [
                'department' => $key_name,
                'total_budget' => 0,
                'total_expenses' => 0,
                'transaction_count' => 0,
                'proposal_count' => 0,
                'remaining_budget' => 0
            ];
        }
        
        $final_data_map[$key_name]['total_budget'] += $total_budget;
        $final_data_map[$key_name]['remaining_budget'] += (float)$budget['remaining_budget'];
        $final_data_map[$key_name]['proposal_count'] += $budget['proposal_count'];
    }

    // Process actuals
    foreach ($department_actuals as $actual) {
        $key_name = $actual['normalized_dept_name'];
        
        if (!isset($final_data_map[$key_name])) {
            $final_data_map[$key_name] = [
                'department' => $key_name,
                'total_budget' => 0,
                'total_expenses' => 0,
                'transaction_count' => 0,
                'proposal_count' => 0,
                'remaining_budget' => 0
            ];
        }
        
        $final_data_map[$key_name]['total_expenses'] += (float)$actual['total_expenses'];
        $final_data_map[$key_name]['transaction_count'] += (int)$actual['transaction_count'];
    }

    // Calculate variance
    $export_data = [];
    foreach ($final_data_map as $item) {
        $variance = $item['total_budget'] - $item['total_expenses'];
        $variance_percentage = ($item['total_budget'] > 0) ? ($variance / $item['total_budget'] * 100) : 0;
        $utilization = ($item['total_budget'] > 0) ? ($item['total_expenses'] / $item['total_budget'] * 100) : 0;
        
        // Determine status
        if ($utilization > 90) {
            $status = 'High Utilization';
        } elseif ($utilization > 70) {
            $status = 'Medium Utilization';
        } else {
            $status = 'Low Utilization';
        }
        
        if ($variance < 0) {
            $status .= ' (Over Budget)';
        } elseif ($variance > 0) {
            $status .= ' (Under Budget)';
        } else {
            $status .= ' (On Budget)';
        }
        
        $export_data[] = [
            'Department' => $item['department'],
            'Budget' => number_format($item['total_budget'], 2),
            'Actual Expenses' => number_format($item['total_expenses'], 2),
            'Variance' => number_format($variance, 2),
            'Variance %' => number_format(abs($variance_percentage), 2),
            'Utilization %' => number_format($utilization, 2),
            'Remaining Budget' => number_format($item['remaining_budget'], 2),
            'Proposal Count' => $item['proposal_count'],
            'Transaction Count' => $item['transaction_count'],
            'Status' => $status
        ];
    }

    // Calculate totals
    $total_budget = array_sum(array_column($final_data_map, 'total_budget'));
    $total_expenses = array_sum(array_column($final_data_map, 'total_expenses'));
    $total_variance = $total_budget - $total_expenses;
    $total_utilization = ($total_budget > 0) ? ($total_expenses / $total_budget * 100) : 0;

    // Create CSV content
    $output = fopen('php://output', 'w');
    
    // Set headers
    $filename = "Budget_vs_Actual_Report_FY{$fiscal_year}_" . date('Y-m-d_H-i-s') . ".csv";
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    // Output BOM for UTF-8
    echo "\xEF\xBB\xBF";
    
    // Report header
    fputcsv($output, ["BUDGET VS ACTUAL ANALYSIS REPORT"]);
    fputcsv($output, []);
    fputcsv($output, ["Report Period:", "Fiscal Year {$fiscal_year} ({$period})"]);
    fputcsv($output, ["Generated By:", $user['name'] ?? 'System']);
    fputcsv($output, ["Generated On:", date('Y-m-d H:i:s')]);
    fputcsv($output, []);
    
    // Data headers
    fputcsv($output, [
        'Department',
        'Budget (₱)',
        'Actual Expenses (₱)',
        'Variance (₱)',
        'Variance %',
        'Utilization %',
        'Remaining Budget (₱)',
        'No. of Proposals',
        'No. of Transactions',
        'Status'
    ]);
    
    // Data rows
    foreach ($export_data as $row) {
        fputcsv($output, [
            $row['Department'],
            '₱' . $row['Budget'],
            '₱' . $row['Actual Expenses'],
            '₱' . $row['Variance'],
            $row['Variance %'] . '%',
            $row['Utilization %'] . '%',
            '₱' . $row['Remaining Budget'],
            $row['Proposal Count'],
            $row['Transaction Count'],
            $row['Status']
        ]);
    }
    
    fputcsv($output, []);
    fputcsv($output, ["SUMMARY"]);
    fputcsv($output, ["Total Budget:", '₱' . number_format($total_budget, 2)]);
    fputcsv($output, ["Total Expenses:", '₱' . number_format($total_expenses, 2)]);
    fputcsv($output, ["Net Variance:", '₱' . number_format($total_variance, 2)]);
    fputcsv($output, ["Overall Utilization:", number_format($total_utilization, 2) . '%']);
    
    fclose($output);
    exit;
    
} catch (Exception $e) {
    error_log("CSV export error: " . $e->getMessage());
    
    // Return error response
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'Failed to generate report. Please try again.'
    ]);
    exit;
}