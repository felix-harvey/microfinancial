<?php
// SETTINGS
$url = 'https://www.finance.microfinancial-1.com/api/receive_request.php'; 

// DITO KA MAG-INPUT PARA SA DEMO
$department_name = "HR Payroll"; // Siguraduhin na may budget ito sa DB mo!
$amount_to_request = 25000.00;

// ANG DATA NA IPAPADALA NILA
$data = [
    "client_id"    => "HR-SYSTEM", // Ang kanilang ID card
    "requested_by" => "HR Manager (Jane Doe)",
    "department"   => $department_name,
    "description"  => "Emergency Purchase of Ink",
    "amount"       => $amount_to_request
];

// --- SENDING CODE ---
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

$response = curl_exec($ch);
curl_close($ch);

// VISUAL OUTPUT
echo "<h1>External System Simulation (HR Dept)</h1>";
echo "<p>Sending request for: <b>$department_name</b></p>";
echo "<p>Amount: <b>" . number_format($amount_to_request, 2) . "</b></p>";
echo "<hr>";
echo "<h3>Status from Finance API:</h3>";
echo "<pre style='background:#f4f4f4; padding:15px; border-left:5px solid green;'>";
print_r(json_decode($response, true));
echo "</pre>";
?>