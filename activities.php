<?php
require_once __DIR__ . '/includes/database.php';

header('Content-Type: application/json; charset=utf-8');

function respondWithActivityJson($statusCode, $payload)
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function calculateTrackDistance($points)
{
    $distance = 0.0;
    for ($index = 1; $index < count($points); $index++) {
        $previous = $points[$index - 1];
        $current = $points[$index];
        $lat1 = deg2rad($previous['lat']);
        $lat2 = deg2rad($current['lat']);
        $deltaLat = $lat2 - $lat1;
        $deltaLng = deg2rad($current['lng'] - $previous['lng']);
        $a = sin($deltaLat / 2) * sin($deltaLat / 2)
            + cos($lat1) * cos($lat2) * sin($deltaLng / 2) * sin($deltaLng / 2);
        $a = max(0, min(1, $a));
        $distance += 6371000 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    return $distance;
}

try {
    $connection = dbConnection();
    $connection->query(
        'CREATE TABLE IF NOT EXISTS `gis_activities` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name` VARCHAR(120) NOT NULL,
            `activity_type` VARCHAR(30) NOT NULL,
            `distance_meters` DOUBLE NOT NULL,
            `duration_seconds` INT UNSIGNED NOT NULL,
            `route_geojson` LONGTEXT NOT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `created_at` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    $method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';
    if ($method === 'GET') {
        $result = $connection->query(
            'SELECT `id`, `name`, `activity_type`, `distance_meters`, `duration_seconds`, `route_geojson`, `created_at`
            FROM `gis_activities` ORDER BY `created_at` DESC, `id` DESC LIMIT 100'
        );
        $activities = [];
        while ($row = $result->fetch_assoc()) {
            $route = json_decode($row['route_geojson'], true);
            if (!is_array($route)) {
                throw new RuntimeException('Rute aktivitas ID ' . $row['id'] . ' rusak.');
            }
            $activities[] = [
                'id' => (int) $row['id'],
                'name' => $row['name'],
                'type' => $row['activity_type'],
                'distance_meters' => (float) $row['distance_meters'],
                'duration_seconds' => (int) $row['duration_seconds'],
                'route' => $route,
                'created_at' => $row['created_at'],
            ];
        }
        respondWithActivityJson(200, ['activities' => $activities]);
    }

    if ($method !== 'POST') {
        header('Allow: GET, POST');
        respondWithActivityJson(405, ['error' => 'Metode permintaan tidak diizinkan.']);
    }

    $rawBody = file_get_contents('php://input');
    if ($rawBody === false || strlen($rawBody) > 5 * 1024 * 1024) {
        respondWithActivityJson(413, ['error' => 'Ukuran data aktivitas maksimal 5 MB.']);
    }
    $payload = json_decode($rawBody, true);
    if (!is_array($payload)) {
        respondWithActivityJson(400, ['error' => 'Data aktivitas tidak valid.']);
    }

    $name = isset($payload['name']) && is_string($payload['name']) ? trim($payload['name']) : '';
    $type = isset($payload['type']) && is_string($payload['type']) ? $payload['type'] : '';
    $allowedTypes = ['Lari', 'Jalan kaki', 'Bersepeda'];
    $duration = isset($payload['duration_seconds']) && is_numeric($payload['duration_seconds'])
        ? (float) $payload['duration_seconds']
        : -1;
    $points = isset($payload['points']) && is_array($payload['points']) ? $payload['points'] : null;

    if ($name === '') {
        $name = $type . ' · ' . date('Y-m-d H:i');
    }
    if (strlen($name) > 120 || !in_array($type, $allowedTypes, true)) {
        respondWithActivityJson(400, ['error' => 'Nama aktivitas maksimal 120 karakter dan jenis aktivitas harus valid.']);
    }
    if ($duration < 1 || $duration > 86400) {
        respondWithActivityJson(400, ['error' => 'Durasi aktivitas harus lebih dari 0 dan maksimal 24 jam.']);
    }
    if ($points === null || count($points) < 2 || count($points) > 5000) {
        respondWithActivityJson(400, ['error' => 'Rute harus berisi 2 sampai 5000 titik GPS.']);
    }

    $validatedPoints = [];
    $previousTime = -1;
    foreach ($points as $point) {
        if (!is_array($point) || !isset($point['lat'], $point['lng'], $point['t'])
            || !is_numeric($point['lat']) || !is_numeric($point['lng']) || !is_numeric($point['t'])) {
            respondWithActivityJson(400, ['error' => 'Setiap titik rute harus memiliki latitude, longitude, dan waktu yang valid.']);
        }
        $latitude = (float) $point['lat'];
        $longitude = (float) $point['lng'];
        $time = (float) $point['t'];
        if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180
            || $time < 0 || $time > $duration || $time < $previousTime) {
            respondWithActivityJson(400, ['error' => 'Koordinat atau urutan waktu pada rute tidak valid.']);
        }
        $validatedPoints[] = ['lat' => $latitude, 'lng' => $longitude, 't' => $time];
        $previousTime = $time;
    }

    $distance = calculateTrackDistance($validatedPoints);
    if ($distance < 1 || $distance > 1000000) {
        respondWithActivityJson(400, ['error' => 'Jarak rute harus antara 1 meter dan 1000 kilometer.']);
    }
    $route = [
        'type' => 'Feature',
        'properties' => ['activity_type' => $type],
        'geometry' => [
            'type' => 'LineString',
            'coordinates' => array_map(function ($point) {
                return [$point['lng'], $point['lat']];
            }, $validatedPoints),
        ],
    ];
    $encodedRoute = json_encode($route, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($encodedRoute === false || strlen($encodedRoute) > 5 * 1024 * 1024) {
        respondWithActivityJson(413, ['error' => 'Data rute terlalu besar untuk disimpan.']);
    }

    executeStatement(
        $connection,
        'INSERT INTO `gis_activities` (`name`, `activity_type`, `distance_meters`, `duration_seconds`, `route_geojson`)
        VALUES (?, ?, ?, ?, ?)',
        [$name, $type, (string) $distance, (string) round($duration), $encodedRoute]
    );
    respondWithActivityJson(201, [
        'id' => (int) $connection->insert_id,
        'distance_meters' => $distance,
        'duration_seconds' => (int) round($duration),
        'message' => 'Aktivitas berhasil disimpan dan tampil untuk semua pengguna.',
    ]);
} catch (Throwable $exception) {
    respondWithActivityJson(500, ['error' => 'Kesalahan penyimpanan aktivitas: ' . $exception->getMessage()]);
}
