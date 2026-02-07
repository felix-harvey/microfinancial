<?php
// SETTINGS
$url = 'https://finance.microfinancial-1.com/api/budget_api.php'; 

// ==========================================
// TEST 1: CREATE NEW PROPOSAL (POST)
// ==========================================
$newData = [
    "title"         => "IT Equipment Upgrade 2026",
    "department_id" => 2,            // Siguraduhin na may Dept ID 2 ka sa database
    "fiscal_year"   => "2026",
    "amount"        => 500000.00,
    "user_id"       => 10            // Siguraduhin na may User ID 10 ka
];

echo "<h2>1. Creating New Budget Proposal...</h2>";
$response = sendRequest($url, 'POST', $newData);
print_r($response);

// ==========================================
// TEST 2: GET ALL PROPOSALS (GET)
// ==========================================
echo "<h2>2. Fetching All Proposals...</h2>";
$response = sendRequest($url, 'GET');
// Ipakita lang ang first 2 items para hindi mahaba
$data = json_decode($response, true);
echo "<pre>" . json_encode(array_slice($data, 0, 2), JSON_PRETTY_PRINT) . "</pre>";


// ==========================================
// TEST 3: APPROVE A PROPOSAL (PUT)
// ==========================================
// (Gamitin natin yung ID 165 galing sa sample data mo)
$updateData = [
    "id"     => 165,
    "status" => "Approved"
];

echo "<h2>3. Approving Proposal ID #165...</h2>";
$response = sendRequest($url, 'PUT', $updateData);
print_r($response);


// --- HELPER FUNCTION (CURL) ---
function sendRequest($url, $method, $data = []) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    
    if ($method !== 'GET') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    }

    $result = curl_exec($ch);
    curl_close($ch);
    return $result;
}
?>