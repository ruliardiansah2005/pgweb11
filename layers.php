<?php
require_once __DIR__ . '/includes/database.php';

header('Content-Type: application/json; charset=utf-8');

function respondWithJson($statusCode, $payload)
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $connection = dbConnection();
    $connection->query(
        'CREATE TABLE IF NOT EXISTS `gis_layers` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name` VARCHAR(120) NOT NULL,
            `geojson` LONGTEXT NOT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    $method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';
    if ($method === 'GET') {
        $result = $connection->query('SELECT `id`, `name`, `geojson` FROM `gis_layers` ORDER BY `id` ASC');
        $layers = [];
        while ($row = $result->fetch_assoc()) {
            $geojson = json_decode($row['geojson'], true);
            if (!is_array($geojson)) {
                throw new RuntimeException('Data GeoJSON pada layer ' . $row['name'] . ' rusak.');
            }
            $layers[] = [
                'id' => (int) $row['id'],
                'name' => $row['name'],
                'geojson' => $geojson,
            ];
        }
        respondWithJson(200, ['layers' => $layers]);
    }

    if ($method !== 'POST') {
        header('Allow: GET, POST');
        respondWithJson(405, ['error' => 'Metode permintaan tidak diizinkan.']);
    }

    $rawBody = file_get_contents('php://input');
    if ($rawBody === false || strlen($rawBody) > 5 * 1024 * 1024) {
        respondWithJson(413, ['error' => 'Ukuran data hasil impor maksimal 5 MB.']);
    }
    $payload = json_decode($rawBody, true);
    if (!is_array($payload)) {
        respondWithJson(400, ['error' => 'Permintaan JSON tidak valid.']);
    }

    if (isset($payload['action']) && $payload['action'] === 'delete') {
        $layerId = isset($payload['id']) ? filter_var($payload['id'], FILTER_VALIDATE_INT) : false;
        if ($layerId === false || $layerId < 1) {
            respondWithJson(400, ['error' => 'ID layer tidak valid.']);
        }
        $statement = executeStatement($connection, 'DELETE FROM `gis_layers` WHERE `id` = ?', [(string) $layerId]);
        if ($statement->affected_rows !== 1) {
            respondWithJson(404, ['error' => 'Layer tidak ditemukan.']);
        }
        respondWithJson(200, ['message' => 'Layer berhasil dihapus.']);
    }

    $name = isset($payload['name']) && is_string($payload['name']) ? trim($payload['name']) : '';
    $geojson = isset($payload['geojson']) && is_array($payload['geojson']) ? $payload['geojson'] : null;
    if ($name === '' || strlen($name) > 120 || $geojson === null) {
        respondWithJson(400, ['error' => 'Nama layer (maksimal 120 karakter) dan GeoJSON wajib diisi.']);
    }
    if (!isset($geojson['type']) || $geojson['type'] !== 'FeatureCollection'
        || !isset($geojson['features']) || !is_array($geojson['features'])) {
        respondWithJson(400, ['error' => 'Data harus berupa GeoJSON FeatureCollection yang valid.']);
    }
    if (!$geojson['features']) {
        respondWithJson(400, ['error' => 'Data yang diimpor tidak berisi fitur.']);
    }

    $encodedGeoJson = json_encode($geojson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($encodedGeoJson === false) {
        respondWithJson(400, ['error' => 'GeoJSON tidak dapat diproses.']);
    }
    if (strlen($encodedGeoJson) > 5 * 1024 * 1024) {
        respondWithJson(413, ['error' => 'Ukuran GeoJSON maksimal 5 MB.']);
    }

    $statement = executeStatement(
        $connection,
        'INSERT INTO `gis_layers` (`name`, `geojson`) VALUES (?, ?)',
        [$name, $encodedGeoJson]
    );
    respondWithJson(201, ['id' => (int) $connection->insert_id, 'message' => 'Layer berhasil disimpan.']);
} catch (Throwable $exception) {
    respondWithJson(500, ['error' => 'Kesalahan penyimpanan layer: ' . $exception->getMessage()]);
}
