<?php
// SETTINGS
$url = 'https://finance.microfinancial-1.com/api/receive_request.php'; 

// DATA NA IPAPADALA
$data = [
    "requested_by" => "Logistics System", // Pinalitan ko para maiba naman
    "department"   => "HR Payroll",       // Siguraduhin na may budget ito sa DB mo
    "description"  => "Purchase of Office Supplies",
    "amount"       => 5000.00
];

// --- CURL SETUP (SIMPLE VERSION) ---

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

// Standard Header lang (Wala nang API Key / Client ID)
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json'
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

if(curl_errno($ch)){
    echo 'Curl error: ' . curl_error($ch);
}
curl_close($ch);

// RESULTA
echo "<h1>API Test Result (No Security Check)</h1>";
echo "Target URL: $url <br>";
echo "Status Code: <b>" . $httpCode . "</b> (201 = Success)<br><br>";
echo "Reply ng System:<br>";
echo "<pre style='background:#f4f4f4; padding:10px; border:1px solid #ccc;'>";
print_r(json_decode($response, true));
echo "</pre>";
?>