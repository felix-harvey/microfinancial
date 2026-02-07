<?php
declare(strict_types=1);
session_start();

// Check if user is logged in
if (empty($_SESSION['user_id'] ?? null)) {
    header("Location: index.php");
    exit;
}

// Safe number format function
function safeNumberFormatExport($value, $decimals = 2) {
    if (!is_numeric($value)) {
        $value = 0;
    }
    return number_format((float)$value, $decimals);
}

try {
    require_once __DIR__ . '/database.php';
    $database = new Database();
    $db = $database->getConnection();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // Get filter parameters
    $report_type = $_GET['report_type'] ?? 'summary';
    $fiscal_year = isset($_GET['fiscal_year']) ? (int)$_GET['fiscal_year'] : (int)date('Y');
    $department = $_GET['department'] ?? '';

    // Get user info for report
    $user_id = (int)$_SESSION['user_id'];
    $user_stmt = $db->prepare("SELECT name FROM users WHERE id = ?");
    $user_stmt->execute([$user_id]);
    $user = $user_stmt->fetch();

    // Data fetching based on report type
    $report_data = [];
    $report_title = "";
    
    switch($report_type) {
        case 'summary':
            $summary_stmt = $db->prepare("
                SELECT 
                    COUNT(*) as total_proposals,
                    SUM(CASE WHEN status = 'Approved' THEN 1 ELSE 0 END) as approved_proposals,
                    SUM(CASE WHEN status = 'Rejected' THEN 1 ELSE 0 END) as rejected_proposals,
                    SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) as pending_proposals,
                    COALESCE(SUM(CASE WHEN status = 'Approved' THEN total_amount ELSE 0 END), 0) as total_approved_budget,
                    COALESCE(AVG(CASE WHEN status = 'Approved' THEN total_amount ELSE NULL END), 0) as avg_approved_budget,
                    COALESCE(MAX(CASE WHEN status = 'Approved' THEN total_amount ELSE 0 END), 0) as max_approved_budget,
                    COALESCE(MIN(CASE WHEN status = 'Approved' AND total_amount > 0 THEN total_amount ELSE NULL END), 0) as min_approved_budget
                FROM budget_proposals 
                WHERE fiscal_year = ?
            ");
            $summary_stmt->execute([$fiscal_year]);
            $budget_summary = $summary_stmt->fetch();
            
            // Ensure all values are numeric
            $total_proposals = (int)($budget_summary['total_proposals'] ?? 0);
            $approved_count = (int)($budget_summary['approved_proposals'] ?? 0);
            $rejected_count = (int)($budget_summary['rejected_proposals'] ?? 0);
            $pending_count = (int)($budget_summary['pending_proposals'] ?? 0);
            $total_approved_budget = (float)($budget_summary['total_approved_budget'] ?? 0);
            $avg_approved_budget = (float)($budget_summary['avg_approved_budget'] ?? 0);
            
            $approved_rate = $total_proposals > 0 ? ($approved_count / $total_proposals * 100) : 0;
            $rejected_rate = $total_proposals > 0 ? ($rejected_count / $total_proposals * 100) : 0;
            $pending_rate = $total_proposals > 0 ? ($pending_count / $total_proposals * 100) : 0;
            
            $report_data = [
                ['Metric', 'Count', 'Amount', 'Percentage', 'Trend'],
                ['Total Proposals', $total_proposals, '-', '100%', 'Base'],
                ['Approved Proposals', $approved_count, 
                 '₱' . safeNumberFormatExport($total_approved_budget, 2),
                 safeNumberFormatExport($approved_rate, 1) . '%',
                 '₱' . safeNumberFormatExport($avg_approved_budget, 2) . ' avg'],
                ['Rejected Proposals', $rejected_count, '-', 
                 safeNumberFormatExport($rejected_rate, 1) . '%', 'Rejected'],
                ['Pending Proposals', $pending_count, '-', 
                 safeNumberFormatExport($pending_rate, 1) . '%', 'Pending']
            ];
            $report_title = "Budget Summary Report - FY {$fiscal_year}";
            break;
            
        case 'department':
            $dept_stmt = $db->prepare("
                SELECT 
                    d.name as department_name,
                    COUNT(bp.id) as proposal_count,
                    SUM(CASE WHEN bp.status = 'Approved' THEN 1 ELSE 0 END) as approved_count,
                    COALESCE(SUM(CASE WHEN bp.status = 'Approved' THEN bp.total_amount ELSE 0 END), 0) as approved_budget,
                    SUM(CASE WHEN bp.status = 'Rejected' THEN 1 ELSE 0 END) as rejected_count,
                    COALESCE(AVG(CASE WHEN bp.status = 'Approved' THEN bp.total_amount ELSE NULL END), 0) as avg_budget,
                    CASE 
                        WHEN (SELECT COALESCE(SUM(total_amount), 0) FROM budget_proposals WHERE status = 'Approved' AND fiscal_year = ?) > 0 
                        THEN (COALESCE(SUM(CASE WHEN bp.status = 'Approved' THEN bp.total_amount ELSE 0 END), 0) / 
                             (SELECT SUM(total_amount) FROM budget_proposals WHERE status = 'Approved' AND fiscal_year = ?)) * 100 
                        ELSE 0 
                    END as budget_percentage
                FROM departments d
                LEFT JOIN budget_proposals bp ON d.id = bp.department AND bp.fiscal_year = ?
                WHERE d.status = 'Active'
                GROUP BY d.id, d.name
                ORDER BY approved_budget DESC
            ");
            $dept_stmt->execute([$fiscal_year, $fiscal_year, $fiscal_year]);
            $department_reports = $dept_stmt->fetchAll();
            
            $report_data = [
                ['Department', 'Total Proposals', 'Approved', 'Rejected', 'Approved Budget', 'Average Budget', 'Budget Share']
            ];
            
            foreach ($department_reports as $dept) {
                // Ensure all values are properly typed
                $dept_name = $dept['department_name'] ?? 'Unknown';
                $proposal_count = (int)($dept['proposal_count'] ?? 0);
                $approved_count = (int)($dept['approved_count'] ?? 0);
                $rejected_count = (int)($dept['rejected_count'] ?? 0);
                $approved_budget = (float)($dept['approved_budget'] ?? 0);
                $avg_budget = (float)($dept['avg_budget'] ?? 0);
                $budget_percentage = (float)($dept['budget_percentage'] ?? 0);
                
                $report_data[] = [
                    $dept_name,
                    $proposal_count,
                    $approved_count,
                    $rejected_count,
                    '₱' . safeNumberFormatExport($approved_budget, 2),
                    '₱' . safeNumberFormatExport($avg_budget, 2),
                    safeNumberFormatExport($budget_percentage, 1) . '%'
                ];
            }
            $report_title = "Department Budget Analysis - FY {$fiscal_year}";
            break;
            
        case 'category':
            $cat_stmt = $db->prepare("
                SELECT 
                    bc.name as category_name,
                    bc.type as category_type,
                    COUNT(bi.id) as item_count,
                    COALESCE(SUM(bi.total_cost), 0) as total_budget,
                    COALESCE(AVG(bi.total_cost), 0) as avg_cost,
                    COALESCE(MAX(bi.total_cost), 0) as max_cost,
                    CASE 
                        WHEN (SELECT COALESCE(SUM(total_cost), 0) FROM budget_items bi2 
                              JOIN budget_proposals bp2 ON bi2.proposal_id = bp2.id 
                              WHERE bp2.fiscal_year = ? AND bp2.status = 'Approved') > 0
                        THEN (COALESCE(SUM(bi.total_cost), 0) / 
                             (SELECT SUM(total_cost) FROM budget_items bi2 
                              JOIN budget_proposals bp2 ON bi2.proposal_id = bp2.id 
                              WHERE bp2.fiscal_year = ? AND bp2.status = 'Approved')) * 100 
                        ELSE 0 
                    END as percentage
                FROM budget_categories bc
                LEFT JOIN budget_items bi ON bc.name = bi.category
                LEFT JOIN budget_proposals bp ON bi.proposal_id = bp.id AND bp.fiscal_year = ? AND bp.status = 'Approved'
                GROUP BY bc.name, bc.type
                HAVING total_budget > 0
                ORDER BY total_budget DESC
            ");
            $cat_stmt->execute([$fiscal_year, $fiscal_year, $fiscal_year]);
            $category_reports = $cat_stmt->fetchAll();
            
            $report_data = [
                ['Category', 'Type', 'Item Count', 'Total Budget', 'Average Cost', 'Maximum Cost', 'Budget Percentage']
            ];
            
            foreach ($category_reports as $category) {
                // Ensure all values are properly typed
                $category_name = $category['category_name'] ?? 'Unknown';
                $category_type = $category['category_type'] ?? 'Unknown';
                $item_count = (int)($category['item_count'] ?? 0);
                $total_budget = (float)($category['total_budget'] ?? 0);
                $avg_cost = (float)($category['avg_cost'] ?? 0);
                $max_cost = (float)($category['max_cost'] ?? 0);
                $percentage = (float)($category['percentage'] ?? 0);
                
                $report_data[] = [
                    $category_name,
                    $category_type,
                    $item_count,
                    '₱' . safeNumberFormatExport($total_budget, 2),
                    '₱' . safeNumberFormatExport($avg_cost, 2),
                    '₱' . safeNumberFormatExport($max_cost, 2),
                    safeNumberFormatExport($percentage, 1) . '%'
                ];
            }
            $report_title = "Category Budget Analysis - FY {$fiscal_year}";
            break;
            
        case 'approval':
            $approval_stmt = $db->prepare("
                SELECT 
                    COALESCE(approver_role, 'Unknown') as approver_role,
                    COUNT(*) as decision_count,
                    SUM(CASE WHEN action = 'Approved' THEN 1 ELSE 0 END) as approved_count,
                    SUM(CASE WHEN action = 'Rejected' THEN 1 ELSE 0 END) as rejected_count,
                    SUM(CASE WHEN action = 'Revision Requested' THEN 1 ELSE 0 END) as revision_count,
                    COALESCE(AVG(TIMESTAMPDIFF(HOUR, bp.submitted_date, wa.approved_at)), 0) as avg_approval_time_hours
                FROM workflow_approvals wa
                JOIN budget_proposals bp ON wa.proposal_id = bp.id
                LEFT JOIN workflow_steps ws ON wa.step_completed = ws.step_order AND bp.department = ws.department
                WHERE YEAR(bp.submitted_date) = ?
                GROUP BY approver_role
                ORDER BY decision_count DESC
            ");
            $approval_stmt->execute([$fiscal_year]);
            $approval_metrics = $approval_stmt->fetchAll();
            
            $report_data = [
                ['Approver Role', 'Total Decisions', 'Approved', 'Rejected', 'Revision Requested', 'Approval Rate', 'Avg. Decision Time']
            ];
            
            foreach ($approval_metrics as $metric) {
                // Ensure all values are properly typed
                $approver_role = $metric['approver_role'] ?? 'Unknown';
                $decision_count = (int)($metric['decision_count'] ?? 0);
                $approved_count = (int)($metric['approved_count'] ?? 0);
                $rejected_count = (int)($metric['rejected_count'] ?? 0);
                $revision_count = (int)($metric['revision_count'] ?? 0);
                $avg_approval_time = (float)($metric['avg_approval_time_hours'] ?? 0);
                
                $approval_rate = $decision_count > 0 ? ($approved_count / $decision_count * 100) : 0;
                
                $report_data[] = [
                    $approver_role,
                    $decision_count,
                    $approved_count,
                    $rejected_count,
                    $revision_count,
                    safeNumberFormatExport($approval_rate, 1) . '%',
                    safeNumberFormatExport($avg_approval_time, 1) . ' hours'
                ];
            }
            $report_title = "Approval Metrics Report - FY {$fiscal_year}";
            break;
            
        default:
            $report_data = [
                ['Error', 'Message'],
                ['Invalid Report Type', 'The specified report type is not valid.']
            ];
            $report_title = "Error Report";
            break;
    }

    // Create CSV content
    $csv = "{$report_title}\r\n";
    $csv .= "Generated On: " . date('Y-m-d H:i:s') . "\r\n";
    $csv .= "Generated By: " . ($user['name'] ?? 'System') . "\r\n";
    $csv .= "Fiscal Year: {$fiscal_year}\r\n";
    
    if ($department) {
        $dept_name_stmt = $db->prepare("SELECT name FROM departments WHERE id = ?");
        $dept_name_stmt->execute([$department]);
        $dept_name_result = $dept_name_stmt->fetch();
        $dept_name = $dept_name_result['name'] ?? 'All';
        $csv .= "Department: {$dept_name}\r\n";
    }
    
    $csv .= "\r\n";
    
    // Add data rows with proper CSV escaping
    foreach ($report_data as $row) {
        $csv_row = [];
        foreach ($row as $cell) {
            // Convert to string and escape
            $cell_str = (string)$cell;
            $cell_str = str_replace('"', '""', $cell_str);
            
            // Wrap in quotes if contains comma, quote, or newline
            if (strpos($cell_str, ',') !== false || 
                strpos($cell_str, '"') !== false || 
                strpos($cell_str, "\n") !== false ||
                strpos($cell_str, "\r") !== false) {
                $cell_str = '"' . $cell_str . '"';
            }
            
            $csv_row[] = $cell_str;
        }
        $csv .= implode(',', $csv_row) . "\r\n";
    }

    // Output CSV
    $filename = preg_replace('/[^a-zA-Z0-9_-]/', '_', $report_title) . '_' . date('Y-m-d_H-i-s') . ".csv";
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
    
    // Output BOM for UTF-8
    echo "\xEF\xBB\xBF";
    echo $csv;
    exit;
    
} catch (Exception $e) {
    error_log("CSV export error: " . $e->getMessage());
    
    // Return error response
    header('Content-Type: text/plain; charset=utf-8');
    echo "Error generating report: " . htmlspecialchars($e->getMessage());
    exit;
}