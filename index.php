<?php
// index.php – Bridge between cPanel and Neon PostgreSQL

// Use getenv() - it's more reliable in container environments
$db_host = getenv('PGHOST');
$db_port = getenv('PGPORT') ?: '5432';
$db_name = getenv('PGDATABASE');
$db_user = getenv('PGUSER');
$db_pass = getenv('PGPASSWORD');

// Add validation to catch missing credentials early
if (!$db_host || !$db_name || !$db_user || !$db_pass) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'error' => 'Database configuration incomplete',
        'missing' => [
            'host' => empty($db_host),
            'database' => empty($db_name),
            'user' => empty($db_user),
            'password' => empty($db_pass)
        ]
    ]);
    exit;
}

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
        $stmt = $pdo->query('SELECT 1 as test');
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode(['status' => 'ok', 'test' => $result['test']]);
    } else {
        echo json_encode(['error' => 'Invalid or missing action parameter']);
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed', 'details' => $e->getMessage()]);
}