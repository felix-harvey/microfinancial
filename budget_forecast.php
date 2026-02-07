<?php
// FILE: budget_forecast.php
session_start();
require_once 'database.php';
require_once 'api/SimpleAI.php'; // Import the Brain

// Check Auth
if (empty($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

// 1. FETCH HISTORICAL DATA
try {
    $database = new Database();
    $db = $database->getConnection();
    
    // Kunin lang ang mga "Approved" requests at pagsamahin per day
    $stmt = $db->prepare("SELECT date_requested, SUM(amount) as total 
                          FROM disbursement_requests 
                          WHERE status = 'Approved' 
                          GROUP BY date_requested 
                          ORDER BY date_requested ASC");
    $stmt->execute();
    $history = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $dates = [];
    $amounts = [];

    foreach ($history as $row) {
        $dates[] = $row['date_requested'];
        $amounts[] = (float)$row['total'];
    }

    // 2. RUN AI PREDICTION
    $hasData = false;
    if (count($dates) >= 2) {
        // Predict next 30 days
        $forecast = SimpleAI::predict($dates, $amounts, 30); 
        
        if (isset($forecast['error'])) {
            $error = $forecast['error'];
        } else {
            $hasData = true;
            $labels = json_encode($forecast['labels']);
            $data = json_encode($forecast['data']);
            $total_forecast = number_format($forecast['forecast_total'], 2);
            $trend = $forecast['trend'];
        }
    } else {
        $error = "Not enough data. Please approve at least 2 requests on different dates to generate a forecast.";
    }

} catch (Exception $e) {
    $error = "System Error: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Predictive Budgeting - AI Forecast</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
</head>
<body class="bg-gray-50 min-h-screen p-4 md:p-8">

    <div class="max-w-7xl mx-auto">
        <div class="mb-8 flex flex-col md:flex-row md:items-center justify-between gap-4">
            <div>
                <a href="dashboard8.php" class="text-gray-500 hover:text-green-600 mb-2 inline-block">
                    <i class='bx bx-arrow-back'></i> Back to Dashboard
                </a>
                <h1 class="text-3xl font-bold text-gray-800 flex items-center gap-2">
                    <i class='bx bx-brain text-purple-600'></i>
                    Predictive Budgeting AI
                </h1>
                <p class="text-gray-500 mt-1">Cash flow forecasting using Time Series Analysis (Linear Regression)</p>
            </div>
            
            <?php if($hasData): ?>
            <div class="bg-white px-4 py-2 rounded-lg shadow-sm border border-gray-200 text-sm">
                <span class="text-gray-500">Algorithm:</span>
                <span class="font-bold text-purple-600">Least Squares Method</span>
            </div>
            <?php endif; ?>
        </div>

        <?php if(isset($error)): ?>
            <div class="bg-yellow-50 border-l-4 border-yellow-400 p-4 mb-6 rounded-r">
                <div class="flex">
                    <div class="flex-shrink-0">
                        <i class='bx bx-info-circle text-yellow-400 text-2xl'></i>
                    </div>
                    <div class="ml-3">
                        <p class="text-sm text-yellow-700">
                            <strong>Needs more data:</strong> <?php echo $error; ?>
                        </p>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if($hasData): ?>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
            <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-100 hover:shadow-md transition">
                <div class="flex justify-between items-start">
                    <div>
                        <p class="text-xs font-bold text-gray-400 uppercase tracking-wider">Forecasted Expense (30 Days)</p>
                        <h3 class="text-3xl font-bold text-gray-800 mt-2">₱ <?php echo $total_forecast; ?></h3>
                    </div>
                    <div class="p-3 bg-purple-50 rounded-lg">
                        <i class='bx bx-line-chart text-purple-600 text-xl'></i>
                    </div>
                </div>
                <p class="text-xs text-gray-400 mt-4">Estimated requirement for next month</p>
            </div>

            <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-100 hover:shadow-md transition">
                <div class="flex justify-between items-start">
                    <div>
                        <p class="text-xs font-bold text-gray-400 uppercase tracking-wider">Spending Trend</p>
                        <div class="mt-2 flex items-center gap-2">
                            <?php if($trend == 'increasing'): ?>
                                <span class="text-2xl font-bold text-red-500">Increasing 📈</span>
                            <?php else: ?>
                                <span class="text-2xl font-bold text-green-500">Decreasing 📉</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="p-3 bg-blue-50 rounded-lg">
                        <i class='bx bx-trending-up text-blue-600 text-xl'></i>
                    </div>
                </div>
                <p class="text-xs text-gray-400 mt-4">Based on historical approved requests</p>
            </div>

            <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-100 hover:shadow-md transition">
                <div class="flex justify-between items-start">
                    <div>
                        <p class="text-xs font-bold text-gray-400 uppercase tracking-wider">AI Confidence</p>
                        <h3 class="text-3xl font-bold text-gray-800 mt-2">Standard</h3>
                    </div>
                    <div class="p-3 bg-green-50 rounded-lg">
                        <i class='bx bx-chip text-green-600 text-xl'></i>
                    </div>
                </div>
                <p class="text-xs text-gray-400 mt-4">Using Linear Regression Model</p>
            </div>
        </div>

        <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-100">
            <h3 class="font-bold text-gray-800 mb-6">Cash Flow Projection</h3>
            <div class="relative h-96 w-full">
                <canvas id="forecastChart"></canvas>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <?php if($hasData): ?>
    <script>
        const ctx = document.getElementById('forecastChart').getContext('2d');
        const gradient = ctx.createLinearGradient(0, 0, 0, 400);
        gradient.addColorStop(0, 'rgba(147, 51, 234, 0.2)'); // Purple top
        gradient.addColorStop(1, 'rgba(147, 51, 234, 0)');   // Transparent bottom

        new Chart(ctx, {
            type: 'line',
            data: {
                labels: <?php echo $labels; ?>,
                datasets: [{
                    label: 'Predicted Disbursement',
                    data: <?php echo $data; ?>,
                    borderColor: '#9333ea', // Purple-600
                    backgroundColor: gradient,
                    borderWidth: 3,
                    pointBackgroundColor: '#ffffff',
                    pointBorderColor: '#9333ea',
                    pointRadius: 4,
                    pointHoverRadius: 6,
                    fill: true,
                    tension: 0.4 // Smooth curve
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        mode: 'index',
                        intersect: false,
                        backgroundColor: 'rgba(255, 255, 255, 0.9)',
                        titleColor: '#1f2937',
                        bodyColor: '#1f2937',
                        borderColor: '#e5e7eb',
                        borderWidth: 1,
                        padding: 10,
                        callbacks: {
                            label: function(context) {
                                let label = context.dataset.label || '';
                                if (label) {
                                    label += ': ';
                                }
                                if (context.parsed.y !== null) {
                                    label += '₱ ' + context.parsed.y.toLocaleString(undefined, {minimumFractionDigits: 2});
                                }
                                return label;
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: {
                            display: false
                        },
                        ticks: {
                            maxTicksLimit: 10
                        }
                    },
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: '#f3f4f6'
                        },
                        ticks: {
                            callback: function(value) {
                                return '₱ ' + value.toLocaleString();
                            }
                        }
                    }
                },
                interaction: {
                    mode: 'nearest',
                    axis: 'x',
                    intersect: false
                }
            }
        });
    </script>
    <?php endif; ?>
</body>
</html>