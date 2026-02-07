<?php
$url = 'https://finance.microfinancial-1.com/api/disbursement_api.php';

$data = [
    "department"        => "HR Payroll",
    "amount"            => 50000.00,
    "description"       => "Payroll Batch 1",
    "requested_by_name" => "HR Manager",
    
    // ITO ANG BAGONG FIELD
    "batch_reference"   => "DISB-MKZCCMOS-20260201" 
];

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_POSTREDIR, 3);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

$response = curl_exec($ch);
curl_close($ch);

echo "<pre>";
print_r(json_decode($response, true));
echo "</pre>";
?>