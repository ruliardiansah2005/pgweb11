<?php
require_once __DIR__ . '/includes/database.php';

header('X-Content-Type-Options: nosniff');

function respondWithRasterJson($statusCode, $payload)
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function rasterStorageDirectory()
{
    $directory = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'gis_rasters';
    if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
        throw new RuntimeException('Folder penyimpanan GeoTIFF tidak dapat dibuat.');
    }
    return $directory;
}

try {
    $connection = dbConnection();
    $connection->query(
        'CREATE TABLE IF NOT EXISTS `gis_rasters` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name` VARCHAR(120) NOT NULL,
            `stored_name` CHAR(36) NOT NULL,
            `original_name` VARCHAR(255) NOT NULL,
            `file_size` INT UNSIGNED NOT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `stored_name` (`stored_name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    $method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';
    $action = isset($_GET['action']) && is_string($_GET['action']) ? $_GET['action'] : '';
    if ($method === 'GET' && $action === 'file') {
        $id = isset($_GET['id']) ? filter_var($_GET['id'], FILTER_VALIDATE_INT) : false;
        if ($id === false || $id < 1) {
            respondWithRasterJson(400, ['error' => 'ID raster tidak valid.']);
        }
        $result = executeStatement(
            $connection,
            'SELECT `stored_name`, `original_name`, `file_size` FROM `gis_rasters` WHERE `id` = ?',
            [(string) $id]
        )->get_result();
        $raster = $result->fetch_assoc();
        if (!$raster) {
            respondWithRasterJson(404, ['error' => 'GeoTIFF tidak ditemukan.']);
        }
        $path = rasterStorageDirectory() . DIRECTORY_SEPARATOR . $raster['stored_name'];
        if (!is_file($path)) {
            respondWithRasterJson(404, ['error' => 'File GeoTIFF tidak tersedia di penyimpanan.']);
        }
        header('Content-Type: image/tiff');
        header('Content-Length: ' . (int) $raster['file_size']);
        header('Content-Disposition: inline; filename="raster-' . (int) $id . '.tif"');
        readfile($path);
        exit;
    }

    if ($method === 'GET') {
        $result = $connection->query(
            'SELECT `id`, `name`, `original_name`, `file_size`, `created_at`
            FROM `gis_rasters` ORDER BY `id` DESC LIMIT 100'
        );
        $rasters = [];
        while ($row = $result->fetch_assoc()) {
            $rasters[] = [
                'id' => (int) $row['id'],
                'name' => $row['name'],
                'original_name' => $row['original_name'],
                'file_size' => (int) $row['file_size'],
                'created_at' => $row['created_at'],
            ];
        }
        respondWithRasterJson(200, ['rasters' => $rasters]);
    }

    if ($method !== 'POST') {
        header('Allow: GET, POST');
        respondWithRasterJson(405, ['error' => 'Metode permintaan tidak diizinkan.']);
    }

    if ($action === 'delete') {
        $rawBody = file_get_contents('php://input');
        $payload = $rawBody === false ? null : json_decode($rawBody, true);
        $id = is_array($payload) && isset($payload['id'])
            ? filter_var($payload['id'], FILTER_VALIDATE_INT)
            : false;
        if ($id === false || $id < 1) {
            respondWithRasterJson(400, ['error' => 'ID raster tidak valid.']);
        }
        $result = executeStatement(
            $connection,
            'SELECT `stored_name` FROM `gis_rasters` WHERE `id` = ?',
            [(string) $id]
        )->get_result();
        $raster = $result->fetch_assoc();
        if (!$raster) {
            respondWithRasterJson(404, ['error' => 'GeoTIFF tidak ditemukan.']);
        }
        $path = rasterStorageDirectory() . DIRECTORY_SEPARATOR . $raster['stored_name'];
        if (is_file($path) && !unlink($path)) {
            respondWithRasterJson(500, ['error' => 'File GeoTIFF tidak dapat dihapus.']);
        }
        executeStatement($connection, 'DELETE FROM `gis_rasters` WHERE `id` = ?', [(string) $id]);
        respondWithRasterJson(200, ['message' => 'GeoTIFF berhasil dihapus.']);
    }

    if ($action !== 'upload') {
        respondWithRasterJson(400, ['error' => 'Aksi GeoTIFF tidak valid.']);
    }
    $name = isset($_GET['name']) && is_string($_GET['name']) ? trim($_GET['name']) : '';
    $originalName = isset($_GET['filename']) && is_string($_GET['filename'])
        ? basename(str_replace('\\', '/', $_GET['filename']))
        : 'raster.tif';
    $originalName = substr($originalName, 0, 255);
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if ($name === '' || strlen($name) > 120 || !in_array($extension, ['tif', 'tiff'], true)) {
        respondWithRasterJson(400, ['error' => 'Nama layer dan file GeoTIFF (.tif/.tiff) yang valid wajib diisi.']);
    }

    $maximumSize = 25 * 1024 * 1024;
    $contentLength = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
    if ($contentLength < 1 || $contentLength > $maximumSize) {
        respondWithRasterJson(413, ['error' => 'Ukuran GeoTIFF harus lebih dari 0 dan maksimal 25 MB.']);
    }
    $directory = rasterStorageDirectory();
    $storedName = bin2hex(random_bytes(16)) . '.tif';
    $targetPath = $directory . DIRECTORY_SEPARATOR . $storedName;
    $target = fopen($targetPath, 'xb');
    $input = fopen('php://input', 'rb');
    if ($target === false || $input === false) {
        if (is_resource($target)) {
            fclose($target);
        }
        if (is_resource($input)) {
            fclose($input);
        }
        if (is_file($targetPath)) {
            unlink($targetPath);
        }
        respondWithRasterJson(500, ['error' => 'Aliran file GeoTIFF tidak dapat dibuka.']);
    }

    $totalBytes = 0;
    $writeFailed = false;
    while (!feof($input)) {
        $chunk = fread($input, 8192);
        if ($chunk === false) {
            $writeFailed = true;
            break;
        }
        $totalBytes += strlen($chunk);
        if ($totalBytes > $maximumSize || fwrite($target, $chunk) !== strlen($chunk)) {
            $writeFailed = true;
            break;
        }
    }
    fclose($input);
    fclose($target);
    if ($writeFailed || $totalBytes !== $contentLength || $totalBytes < 1) {
        unlink($targetPath);
        respondWithRasterJson(400, ['error' => 'File GeoTIFF tidak lengkap atau ukurannya melebihi 25 MB.']);
    }
    $header = file_get_contents($targetPath, false, null, 0, 4);
    if ($header === false || !in_array($header, ["II*\0", "MM\0*", "II+\0", "MM\0+"], true)) {
        unlink($targetPath);
        respondWithRasterJson(400, ['error' => 'Berkas yang diunggah bukan file TIFF yang valid.']);
    }

    try {
        executeStatement(
            $connection,
            'INSERT INTO `gis_rasters` (`name`, `stored_name`, `original_name`, `file_size`) VALUES (?, ?, ?, ?)',
            [$name, $storedName, $originalName, (string) $totalBytes]
        );
    } catch (Throwable $exception) {
        unlink($targetPath);
        throw $exception;
    }
    respondWithRasterJson(201, [
        'raster' => ['id' => (int) $connection->insert_id, 'name' => $name, 'file_size' => $totalBytes],
        'message' => 'GeoTIFF berhasil disimpan.',
    ]);
} catch (Throwable $exception) {
    respondWithRasterJson(500, ['error' => 'Kesalahan penyimpanan GeoTIFF: ' . $exception->getMessage()]);
}
