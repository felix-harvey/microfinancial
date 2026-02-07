<?php
// FILE: simulate_dept_receiver.php
// Ito ang file na nasa server ng HR o Logistics
header("Content-Type: application/json");

// 1. Tanggapin ang Data galing sa Finance System
$input = json_decode(file_get_contents("php://input"), true);

if (!$input) {
    echo json_encode(["status" => "error", "message" => "No data received"]);
    exit;
}

// 2. I-process (Kunwari ina-update nila ang ledger nila)
$dept_name = $input['department'];
$amount = number_format($input['amount'], 2);
$status = $input['status'];
$ref_id = $input['transaction_id'];

// 3. Mag-log para makita mo kung gumana (Save to file for demo)
$log_message = "[$dept_name SYSTEM] Received Update: Ref #$ref_id is now $status. Amount: $amount\n";
file_put_contents("dept_receiver_log.txt", $log_message, FILE_APPEND);

// 4. Mag-reply sa Finance System
echo json_encode([
    "status" => "success",
    "message" => "Acknowledged. We have updated our local records."
]);
?>