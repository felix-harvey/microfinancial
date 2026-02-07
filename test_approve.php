<?php
// FILE: test_approve.php
$url = 'https://finance.microfinancial-1.com/api/approve_disbursement.php';

// DATA NA GAGAYAHIN NG BUTTON MO
$data = [
    "pending_id" => "PEN-20260130-XYZ", // <--- PALITAN MO NG TOTOONG ID SA DB MO
    "admin_id"   => 1                   // ID mo bilang Admin
];

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

$response = curl_exec($ch);
curl_close($ch);

echo "<h1>Disbursement Approval Test</h1>";
echo "<pre>";
print_r(json_decode($response, true));
echo "</pre>";

// CHECK LOGS
echo "<hr><h3>External System Log Check:</h3>";
if(file_exists('dept_receiver_log.txt')) {
    echo nl2br(file_get_contents('dept_receiver_log.txt'));
} else {
    echo "No logs yet.";
}
?>