<?php
$url = 'https://finance.microfinancial-1.com/api/users_api.php';

// DATA: Pansinin mo na 'name' na ang gamit, hindi na hiwalay na first/last name
$data = [
    "username" => "test_api_user2",
    "name"     => "API Tester Juan", // Isang buo na
    "email"    => "juan@test.com",
    "role"     => "staff"            // 'role' na, hindi 'user_type'
];

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "<h3>API Create User Test</h3>";
echo "Status: $httpCode <br>";
echo "Response: <br>";
echo "<pre>";
print_r(json_decode($response, true));
echo "</pre>";
?>