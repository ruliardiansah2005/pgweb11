<?php
require_once __DIR__ . '/includes/database.php';

header('Content-Type: application/json; charset=utf-8');

function respondWithWaypointJson($statusCode, $payload)
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $connection = dbConnection();
    $connection->query(
        'CREATE TABLE IF NOT EXISTS `gis_waypoints` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name` VARCHAR(120) NOT NULL,
            `notes` TEXT NOT NULL,
            `latitude` DOUBLE NOT NULL,
            `longitude` DOUBLE NOT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `created_at` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    $method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';
    if ($method === 'GET') {
        $result = $connection->query(
            'SELECT `id`, `name`, `notes`, `latitude`, `longitude`, `created_at`
            FROM `gis_waypoints` ORDER BY `id` DESC LIMIT 1000'
        );
        $waypoints = [];
        while ($row = $result->fetch_assoc()) {
            $waypoints[] = [
                'id' => (int) $row['id'],
                'name' => $row['name'],
                'notes' => $row['notes'],
                'latitude' => (float) $row['latitude'],
                'longitude' => (float) $row['longitude'],
                'created_at' => $row['created_at'],
            ];
        }
        respondWithWaypointJson(200, ['waypoints' => $waypoints]);
    }

    if ($method !== 'POST') {
        header('Allow: GET, POST');
        respondWithWaypointJson(405, ['error' => 'Metode permintaan tidak diizinkan.']);
    }

    $rawBody = file_get_contents('php://input');
    if ($rawBody === false || strlen($rawBody) > 16384) {
        respondWithWaypointJson(413, ['error' => 'Ukuran permintaan waypoint terlalu besar.']);
    }
    $payload = json_decode($rawBody, true);
    if (!is_array($payload)) {
        respondWithWaypointJson(400, ['error' => 'Data waypoint tidak valid.']);
    }

    if (isset($payload['action']) && $payload['action'] === 'delete') {
        $id = isset($payload['id']) ? filter_var($payload['id'], FILTER_VALIDATE_INT) : false;
        if ($id === false || $id < 1) {
            respondWithWaypointJson(400, ['error' => 'ID waypoint tidak valid.']);
        }
        $statement = executeStatement($connection, 'DELETE FROM `gis_waypoints` WHERE `id` = ?', [(string) $id]);
        if ($statement->affected_rows !== 1) {
            respondWithWaypointJson(404, ['error' => 'Waypoint tidak ditemukan.']);
        }
        respondWithWaypointJson(200, ['message' => 'Waypoint berhasil dihapus.']);
    }

    $name = isset($payload['name']) && is_string($payload['name']) ? trim($payload['name']) : '';
    $notes = isset($payload['notes']) && is_string($payload['notes']) ? trim($payload['notes']) : '';
    $latitude = isset($payload['latitude']) && is_numeric($payload['latitude']) ? (float) $payload['latitude'] : null;
    $longitude = isset($payload['longitude']) && is_numeric($payload['longitude']) ? (float) $payload['longitude'] : null;
    if ($name === '' || strlen($name) > 120 || strlen($notes) > 1000
        || $latitude === null || $latitude < -90 || $latitude > 90
        || $longitude === null || $longitude < -180 || $longitude > 180) {
        respondWithWaypointJson(400, ['error' => 'Nama maksimal 120 karakter, catatan maksimal 1000 karakter, dan koordinat harus valid.']);
    }

    executeStatement(
        $connection,
        'INSERT INTO `gis_waypoints` (`name`, `notes`, `latitude`, `longitude`) VALUES (?, ?, ?, ?)',
        [$name, $notes, (string) $latitude, (string) $longitude]
    );
    respondWithWaypointJson(201, [
        'waypoint' => [
            'id' => (int) $connection->insert_id,
            'name' => $name,
            'notes' => $notes,
            'latitude' => $latitude,
            'longitude' => $longitude,
        ],
        'message' => 'Waypoint berhasil disimpan.',
    ]);
} catch (Throwable $exception) {
    respondWithWaypointJson(500, ['error' => 'Kesalahan penyimpanan waypoint: ' . $exception->getMessage()]);
}
