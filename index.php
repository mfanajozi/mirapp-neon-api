<?php
// index.php on Render
header('Content-Type: application/json');

// --- Authentication ---
$auth_token = $_GET['token'] ?? $_POST['token'] ?? '';
$expected_token = getenv('CRON_SECRET');
if (!$expected_token || $auth_token !== $expected_token) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// --- Database connection (same as before) ---
$db_host = getenv('PGHOST');
$db_port = getenv('PGPORT') ?: '5432';
$db_name = getenv('PGDATABASE');
$db_user = getenv('PGUSER');
$db_pass = getenv('PGPASSWORD');

if (!$db_host || !$db_name || !$db_user || !$db_pass) {
    http_response_code(500);
    echo json_encode(['error' => 'Database configuration incomplete']);
    exit;
}

$action = $_GET['action'] ?? '';

try {
    $pdo = new PDO(
        "pgsql:host=$db_host;port=$db_port;dbname=$db_name",
        $db_user,
        $db_pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    if ($action === 'fetch_unprocessed') {
        // Begin transaction to ensure we don't lose data if something fails
        $pdo->beginTransaction();

        // Fetch unprocessed reports
        $stmt = $pdo->prepare("SELECT * FROM reports WHERE processed = 0 ORDER BY id LIMIT 1000"); // limit for safety
        $stmt->execute();
        $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Mark them as processed
        if (!empty($reports)) {
            $ids = array_column($reports, 'id');
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $updateStmt = $pdo->prepare("UPDATE reports SET processed = 1 WHERE id IN ($placeholders)");
            $updateStmt->execute($ids);
        }

        $pdo->commit();

        echo json_encode([
            'status' => 'success',
            'count' => count($reports),
            'reports' => $reports
        ]);

    } elseif ($action === 'test') {
        // Keep your test endpoint
        $stmt = $pdo->query('SELECT 1 as test');
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode(['status' => 'ok', 'test' => $result['test']]);

    } else {
        echo json_encode(['error' => 'Invalid action']);
    }

} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['error' => 'Database error', 'details' => $e->getMessage()]);
}