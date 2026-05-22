<?php
header('Content-Type: application/json');

// --- Authentication ---
$auth_token = $_GET['token'] ?? $_POST['token'] ?? '';
$expected_token = getenv('CRON_SECRET');
if (!$expected_token || $auth_token !== $expected_token) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// --- Database connection ---
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
        $pdo->beginTransaction();

        error_log("fetch_unprocessed: Starting transaction");
        error_log("fetch_unprocessed: SQL = SELECT * FROM reports WHERE processed = 0 ORDER BY id LIMIT 5000");

        // ... after $stmt->execute()
        error_log("fetch_unprocessed: Fetched " . count($reports) . " rows");
        error_log("fetch_unprocessed: IDs = " . json_encode(array_column($reports, 'id')));

        // ... after $updateStmt->execute()
        error_log("fetch_unprocessed: Updated " . ($updateStmt->rowCount() ?? 0) . " rows");

        // Fetch unprocessed reports
        $stmt = $pdo->prepare("SELECT * FROM reports WHERE processed = 0 ORDER BY id LIMIT 5000");
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

        // Transform each report to match MySQL 'incidents' table columns
        $transformed = [];
        foreach ($reports as $row) {
            // Parse latitude/longitude from geo_location
            $lat = null;
            $lng = null;
            if (!empty($row['geo_location'])) {
                $geo = trim($row['geo_location']);
                // Try common formats: "lat,lng" , "lat lng" , "POINT(lng lat)"
                if (preg_match('/^([-+]?\d+(?:\.\d+)?)[, ]+([-+]?\d+(?:\.\d+)?)$/', $geo, $matches)) {
                    $lat = $matches[1];
                    $lng = $matches[2];
                } elseif (preg_match('/^POINT\(([-+]?\d+(?:\.\d+)?) ([-+]?\d+(?:\.\d+)?)\)$/i', $geo, $matches)) {
                    // POINT(lng lat) -> swap to lat,lng
                    $lng = $matches[1];
                    $lat = $matches[2];
                } else {
                    // Fallback: if it's a JSON object or other, try to decode
                    $geoData = json_decode($geo, true);
                    if (isset($geoData['lat']) && isset($geoData['lng'])) {
                        $lat = $geoData['lat'];
                        $lng = $geoData['lng'];
                    } elseif (isset($geoData['latitude']) && isset($geoData['longitude'])) {
                        $lat = $geoData['latitude'];
                        $lng = $geoData['longitude'];
                    }
                }
            }

            // Build row for MySQL incidents
            $transformed[] = [
                'content'          => $row['content'] ?? '',
                'location_text'    => $row['location'] ?? '',
                'geo_lat'          => $lat,
                'geo_lng'          => $lng,
                'report_date'      => $row['report_date'] ?? (substr($row['created_at'] ?? '', 0, 10)),
                'report_time'      => $row['report_time'] ?? (substr($row['created_at'] ?? '', 11, 8)),
                'upload_timestamp' => $row['upload_timestamp'] ?? $row['created_at'] ?? null,
                'user_id'          => $row['user_id'] ?? null,
                'is_anonymous'     => $row['is_anonymous'] ?? false,
                'source'           => 'Neon'   // optional, your sync.php can use this
            ];
        }

        echo json_encode([
            'status'  => 'success',
            'count'   => count($transformed),
            'reports' => $transformed
        ]);
    }
    elseif ($action === 'delete_old_reports') {
        $cutoff = date('Y-m-d H:i:s', strtotime('-30 days'));
        $stmt = $pdo->prepare("DELETE FROM reports WHERE created_at < :cutoff");
        $stmt->execute([':cutoff' => $cutoff]);
        $deletedCount = $stmt->rowCount();

        echo json_encode([
            'status' => 'success',
            'deleted_count' => $deletedCount,
            'cutoff_date' => $cutoff
        ]);
    }
    elseif ($action === 'test') {
        $stmt = $pdo->query('SELECT 1 as test');
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode(['status' => 'ok', 'test' => $result['test']]);
    }
    else {
        echo json_encode(['error' => 'Invalid action']);
    }
} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['error' => 'Database error', 'details' => $e->getMessage()]);
}