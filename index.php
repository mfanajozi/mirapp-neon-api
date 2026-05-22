<?php
// index.php – Bridge between cPanel and Neon PostgreSQL

// Load environment variables (Render injects them automatically, but for local dev you can use getenv)
$db_host = getenv('PGHOST') ?: 'your_neon_host';
$db_port = getenv('PGPORT') ?: '5432';
$db_name = getenv('PGDATABASE') ?: 'neondb';
$db_user = getenv('PGUSER') ?: 'your_user';
$db_pass = getenv('PGPASSWORD') ?: 'your_password';

$action = $_GET['action'] ?? '';

header('Content-Type: application/json');

try {
    $pdo = new PDO(
        "pgsql:host=$db_host;port=$db_port;dbname=$db_name",
        $db_user,
        $db_pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    if ($action === 'test') {
        // Simple test query
        $stmt = $pdo->query('SELECT 1 as test');
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode(['status' => 'ok', 'test' => $result['test']]);
    } 
    elseif ($action === 'your_custom_action') {
        // Replace with your real data fetching logic
        // Example: $stmt = $pdo->query('SELECT * FROM your_table');
        // echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        echo json_encode(['message' => 'Custom action not implemented yet']);
    }
    else {
        echo json_encode(['error' => 'Invalid or missing action parameter']);
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed', 'details' => $e->getMessage()]);
}