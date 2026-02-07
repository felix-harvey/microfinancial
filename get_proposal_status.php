<?php
// get_proposal_status.php
declare(strict_types=1);
session_start();

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo "Unauthorized";
    exit;
}

$proposal_id = (int)($_GET['proposal_id'] ?? 0);

try {
    require_once __DIR__ . '/database.php';
    $database = new Database();
    $db = $database->getConnection();
    
    // Get proposal details
    $stmt = $db->prepare("
        SELECT 
            bp.*,
            u.name as submitter_name,
            d.name as department_name,
            (SELECT COUNT(*) FROM workflow_approvals WHERE proposal_id = bp.id) as approval_count
        FROM budget_proposals bp
        JOIN users u ON bp.submitted_by = u.id
        JOIN departments d ON bp.department = d.id
        WHERE bp.id = ?
    ");
    $stmt->execute([$proposal_id]);
    $proposal = $stmt->fetch();
    
    if (!$proposal) {
        echo "<div class='text-red-600'>Proposal not found</div>";
        exit;
    }
    
    // Get approval history
    $approval_stmt = $db->prepare("
        SELECT wa.*, u.name as approver_name
        FROM workflow_approvals wa
        JOIN users u ON wa.approver_id = u.id
        WHERE wa.proposal_id = ?
        ORDER BY wa.approved_at DESC
    ");
    $approval_stmt->execute([$proposal_id]);
    $approvals = $approval_stmt->fetchAll();
    
    ?>
    <div class="space-y-4">
        <div>
            <h3 class="font-semibold"><?= htmlspecialchars($proposal['title']) ?></h3>
            <p class="text-sm text-gray-600">Department: <?= htmlspecialchars($proposal['department_name']) ?></p>
            <p class="text-sm text-gray-600">Submitted: <?= date('M j, Y', strtotime($proposal['submitted_date'])) ?></p>
        </div>
        
        <div>
            <span class="status-badge status-<?= strtolower($proposal['status']) ?>">
                Status: <?= htmlspecialchars($proposal['status']) ?>
            </span>
        </div>
        
        <?php if (!empty($approvals)): ?>
            <div>
                <h4 class="font-medium mb-2">Approval History:</h4>
                <div class="space-y-2">
                    <?php foreach ($approvals as $approval): ?>
                        <div class="p-3 border rounded">
                            <div class="flex justify-between">
                                <span class="font-medium"><?= htmlspecialchars($approval['approver_name']) ?></span>
                                <span class="status-badge status-<?= strtolower($approval['action']) ?>">
                                    <?= htmlspecialchars($approval['action']) ?>
                                </span>
                            </div>
                            <p class="text-sm text-gray-600 mt-1">
                                <?= date('M j, Y g:i A', strtotime($approval['approved_at'])) ?>
                            </p>
                            <?php if ($approval['comments']): ?>
                                <p class="text-sm mt-2"><?= htmlspecialchars($approval['comments']) ?></p>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php else: ?>
            <p class="text-gray-500">No approval actions yet.</p>
        <?php endif; ?>
    </div>
    <?php
    
} catch (Exception $e) {
    error_log("Status error: " . $e->getMessage());
    echo "<div class='text-red-600'>Error loading status</div>";
}