<?php
require_once __DIR__ . '/includes/database.php';

function surveyRespond($statusCode, $payload)
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function surveyConfigTable($connection)
{
    $connection->query(
        "CREATE TABLE IF NOT EXISTS `gis_ona_config` (
            `id` TINYINT UNSIGNED NOT NULL,
            `server_url` VARCHAR(255) NOT NULL,
            `form_pk` VARCHAR(80) NOT NULL,
            `form_id` VARCHAR(120) NOT NULL,
            `project_pk` VARCHAR(80) NOT NULL DEFAULT '',
            `form_title` VARCHAR(255) NOT NULL,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $column = $connection->query("SHOW COLUMNS FROM `gis_ona_config` LIKE 'project_pk'");
    if ($column->num_rows === 0) {
        $connection->query(
            "ALTER TABLE `gis_ona_config` ADD COLUMN `project_pk` VARCHAR(80) NOT NULL DEFAULT '' AFTER `form_id`"
        );
    }
}

function surveyAdminPasswordHash()
{
    $hash = getenv('APP_ADMIN_PASSWORD_HASH');
    return is_string($hash) ? trim($hash) : '';
}

function surveyOnaToken()
{
    $token = getenv('ONA_API_TOKEN');
    return is_string($token) ? trim($token) : '';
}

function validateOnaServer($server)
{
    $server = is_string($server) ? rtrim(trim($server), '/') : '';
    $parts = $server !== '' ? parse_url($server) : false;
    if (!$parts || !isset($parts['scheme'], $parts['host'])
        || strtolower($parts['scheme']) !== 'https'
        || strtolower($parts['host']) !== 'api.ona.io'
        || isset($parts['user']) || isset($parts['pass'])
        || isset($parts['port']) || isset($parts['query']) || isset($parts['fragment'])
        || (isset($parts['path']) && $parts['path'] !== '')) {
        throw new InvalidArgumentException('Server harus https://api.ona.io tanpa path tambahan.');
    }
    return 'https://api.ona.io';
}

function surveyEnsureCsrfToken()
{
    if (empty($_SESSION['survey_csrf'])) {
        $_SESSION['survey_csrf'] = bin2hex(random_bytes(24));
    }
    return $_SESSION['survey_csrf'];
}

function surveyRequireAdmin()
{
    if (empty($_SESSION['ona_admin'])) {
        surveyRespond(401, ['error' => 'Login admin diperlukan.']);
    }
}

function surveyRequireCsrf()
{
    $provided = isset($_SERVER['HTTP_X_CSRF_TOKEN']) ? (string) $_SERVER['HTTP_X_CSRF_TOKEN'] : '';
    if ($provided === '' || !hash_equals(surveyEnsureCsrfToken(), $provided)) {
        surveyRespond(403, ['error' => 'Token keamanan tidak valid. Muat ulang halaman dan coba lagi.']);
    }
}

function surveyReadJsonBody($maximumBytes = 65536)
{
    $body = file_get_contents('php://input');
    if ($body === false || strlen($body) > $maximumBytes) {
        surveyRespond(413, ['error' => 'Permintaan terlalu besar.']);
    }
    $payload = json_decode($body, true);
    if (!is_array($payload)) {
        surveyRespond(400, ['error' => 'Data JSON tidak valid.']);
    }
    return $payload;
}

function surveyOnaRequest($server, $path, $method = 'GET', $postFields = null, $contentType = null)
{
    if (surveyOnaToken() === '') {
        throw new RuntimeException('Token ONA belum dikonfigurasi pada server.');
    }
    if (strpos($path, 'https://') === 0) {
        $parts = parse_url($path);
        if (!$parts || strtolower(isset($parts['host']) ? $parts['host'] : '') !== 'api.ona.io'
            || strtolower(isset($parts['scheme']) ? $parts['scheme'] : '') !== 'https') {
            throw new RuntimeException('Alamat endpoint ONA tidak valid.');
        }
        $url = $path;
    } else {
        if ($path === '' || $path[0] !== '/' || strpos($path, '//') === 0) {
            throw new RuntimeException('Path endpoint ONA tidak valid.');
        }
        $url = $server . $path;
    }

    $headers = [
        'Authorization: Token ' . surveyOnaToken(),
        'Accept: application/json, application/xml, text/xml',
    ];
    if ($contentType !== null) {
        $headers[] = 'Content-Type: ' . $contentType;
    }
    $handle = curl_init($url);
    if ($handle === false) {
        throw new RuntimeException('Klien koneksi ONA tidak dapat dibuat.');
    }
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 35,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CUSTOMREQUEST => $method,
    ]);
    if ($postFields !== null) {
        curl_setopt($handle, CURLOPT_POSTFIELDS, $postFields);
    }
    $body = curl_exec($handle);
    $error = curl_error($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
    curl_close($handle);
    if ($body === false) {
        throw new RuntimeException('Tidak dapat terhubung ke ONA: ' . $error);
    }
    return ['status' => $status, 'body' => (string) $body];
}

function surveyOnaForms($server)
{
    $response = surveyOnaRequest($server, '/api/v1/forms.json?page=1&page_size=1000');
    if ($response['status'] < 200 || $response['status'] >= 300) {
        throw new RuntimeException('ONA menolak permintaan daftar formulir (HTTP ' . $response['status'] . ').');
    }
    $decoded = json_decode($response['body'], true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Respons daftar formulir ONA bukan JSON yang valid.');
    }
    $forms = isset($decoded['results']) && is_array($decoded['results'])
        ? $decoded['results']
        : $decoded;
    $normalized = [];
    foreach ($forms as $form) {
        if (!is_array($form)) {
            continue;
        }
        $formPk = isset($form['id']) ? (string) $form['id'] : '';
        $formId = isset($form['id_string']) ? (string) $form['id_string']
            : (isset($form['formid']) ? (string) $form['formid'] : '');
        $title = isset($form['title']) ? (string) $form['title']
            : (isset($form['name']) ? (string) $form['name'] : $formId);
        $projectId = isset($form['project_id']) ? (string) $form['project_id']
            : (isset($form['project']) && is_array($form['project']) && isset($form['project']['id'])
                ? (string) $form['project']['id']
                : (isset($form['project']) && is_numeric($form['project']) ? (string) $form['project'] : ''));
        if ($formPk === '' || $formId === '') {
            continue;
        }
        $normalized[] = [
            'form_pk' => $formPk,
            'form_id' => $formId,
            'title' => $title !== '' ? $title : $formId,
            'project_id' => $projectId,
        ];
    }
    return $normalized;
}

function surveyReadOnaFormList($server)
{
    $response = surveyOnaRequest($server, '/formList');
    if ($response['status'] < 200 || $response['status'] >= 300) {
        throw new RuntimeException('ONA tidak dapat menampilkan daftar OpenRosa (HTTP ' . $response['status'] . ').');
    }
    $document = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $loaded = $document->loadXML($response['body'], LIBXML_NONET | LIBXML_NOBLANKS);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    if (!$loaded) {
        throw new RuntimeException('Respons daftar OpenRosa dari ONA tidak berisi XML yang valid.');
    }
    $forms = [];
    foreach ($document->getElementsByTagName('*') as $node) {
        if (strtolower($node->localName) !== 'xform') {
            continue;
        }
        $formId = trim($node->getAttribute('formID'));
        $downloadUrl = trim($node->getAttribute('downloadUrl'));
        foreach ($node->childNodes as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }
            if (strtolower($child->localName) === 'formid' && $formId === '') {
                $formId = trim($child->textContent);
            } elseif (strtolower($child->localName) === 'downloadurl' && $downloadUrl === '') {
                $downloadUrl = trim($child->textContent);
            }
        }
        if ($formId !== '' && $downloadUrl !== '') {
            $forms[$formId] = $downloadUrl;
        }
    }
    return $forms;
}

function surveyGetConfiguredForm($connection)
{
    surveyConfigTable($connection);
    $result = $connection->query(
        'SELECT `server_url`, `form_pk`, `form_id`, `project_pk`, `form_title`
        FROM `gis_ona_config` WHERE `id` = 1'
    );
    $config = $result->fetch_assoc();
    return $config ?: null;
}

function surveyLoadFormDefinition($config)
{
    $server = validateOnaServer($config['server_url']);
    $formList = surveyReadOnaFormList($server);
    if (!isset($formList[$config['form_id']])) {
        throw new RuntimeException('Formulir aktif tidak ditemukan pada daftar OpenRosa ONA.');
    }
    $downloadUrl = $formList[$config['form_id']];
    if (strpos($downloadUrl, '/') === 0) {
        $downloadUrl = $server . $downloadUrl;
    }
    $downloadParts = parse_url($downloadUrl);
    if (!$downloadParts || strtolower(isset($downloadParts['scheme']) ? $downloadParts['scheme'] : '') !== 'https'
        || strtolower(isset($downloadParts['host']) ? $downloadParts['host'] : '') !== 'api.ona.io') {
        throw new RuntimeException('URL definisi formulir ONA tidak aman.');
    }
    $formResponse = surveyOnaRequest($server, $downloadUrl);
    if ($formResponse['status'] < 200 || $formResponse['status'] >= 300) {
        throw new RuntimeException('Definisi formulir ONA gagal diunduh (HTTP ' . $formResponse['status'] . ').');
    }
    return surveyParseXForm($formResponse['body'], $config);
}

function surveyDirectChild($node, $localName)
{
    foreach ($node->childNodes as $child) {
        if ($child instanceof DOMElement && strtolower($child->localName) === strtolower($localName)) {
            return $child;
        }
    }
    return null;
}

function surveyElementLabel($element, $document)
{
    $label = surveyDirectChild($element, 'label');
    if (!$label) {
        return '';
    }
    $reference = $label->getAttribute('ref');
    if ($reference !== '' && preg_match("/itext\\(['\"]([^'\"]+)['\"]\\)/", $reference, $matches)) {
        foreach ($document->getElementsByTagName('*') as $textNode) {
            if (strtolower($textNode->localName) === 'text' && $textNode->getAttribute('id') === $matches[1]) {
                foreach ($textNode->getElementsByTagName('*') as $valueNode) {
                    if (strtolower($valueNode->localName) === 'value' && trim($valueNode->textContent) !== '') {
                        return trim($valueNode->textContent);
                    }
                }
            }
        }
    }
    return trim($label->textContent);
}

function surveyFieldPath($reference, $rootName)
{
    $reference = preg_replace('/\\[[^\\]]+\\]/', '', trim((string) $reference));
    $segments = array_values(array_filter(explode('/', trim($reference, '/')), 'strlen'));
    if (!$segments || in_array('..', $segments, true)) {
        return [];
    }
    if (strtolower($segments[0]) === strtolower($rootName)) {
        array_shift($segments);
    }
    foreach ($segments as $segment) {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_.-]*$/', $segment)) {
            return [];
        }
    }
    return $segments;
}

function surveyParseXForm($xml, $config)
{
    $document = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    if (!$loaded) {
        throw new RuntimeException('Definisi formulir ONA bukan XML yang valid.');
    }

    $instance = $document->getElementsByTagName('*');
    $root = null;
    foreach ($instance as $node) {
        if (strtolower($node->localName) === 'instance') {
            foreach ($node->childNodes as $child) {
                if ($child instanceof DOMElement) {
                    $root = $child;
                    break;
                }
            }
            break;
        }
    }
    if (!$root) {
        throw new RuntimeException('Formulir ONA tidak memiliki elemen data utama.');
    }
    $rootName = $root->localName;
    $version = $root->getAttribute('version');
    $bindTypes = [];
    foreach ($document->getElementsByTagName('*') as $node) {
        if (strtolower($node->localName) === 'bind') {
            $nodeset = $node->getAttribute('nodeset');
            if ($nodeset === '') {
                $nodeset = $node->getAttribute('ref');
            }
            if ($nodeset !== '') {
                $bindTypes[$nodeset] = [
                    'type' => strtolower($node->getAttribute('type')),
                    'required' => in_array(strtolower($node->getAttribute('required')), ['true()', 'true', '1'], true),
                ];
            }
        }
    }

    $fields = [];
    foreach ($document->getElementsByTagName('*') as $element) {
        $elementType = strtolower($element->localName);
        if (!in_array($elementType, ['input', 'select1', 'select', 'upload', 'range'], true)) {
            continue;
        }
        for ($parent = $element->parentNode; $parent instanceof DOMElement; $parent = $parent->parentNode) {
            if (strtolower($parent->localName) === 'repeat') {
                throw new RuntimeException('Form ini memakai pertanyaan berulang. Form berulang belum didukung pada pengisi survei Web GIS.');
            }
        }
        $reference = $element->getAttribute('ref');
        if ($reference === '') {
            $reference = $element->getAttribute('nodeset');
        }
        $path = surveyFieldPath($reference, $rootName);
        if (!$path || strtolower(end($path)) === 'instanceid') {
            continue;
        }
        $bind = isset($bindTypes[$reference]) ? $bindTypes[$reference] : ['type' => '', 'required' => false];
        $bindType = $bind['type'];
        $type = 'text';
        if ($elementType === 'select' || $elementType === 'select1') {
            $type = $elementType === 'select' ? 'select_multiple' : 'select_one';
        } elseif ($elementType === 'upload' || $bindType === 'binary') {
            $type = 'image';
        } elseif ($elementType === 'range' || $bindType === 'decimal' || $bindType === 'int' || $bindType === 'long') {
            $type = in_array($bindType, ['int', 'long'], true) ? 'integer' : 'number';
        } elseif (in_array($bindType, ['date', 'datetime', 'time', 'geopoint'], true)) {
            $type = $bindType;
        }
        $choices = [];
        foreach ($element->getElementsByTagName('*') as $item) {
            if (strtolower($item->localName) !== 'item') {
                continue;
            }
            $value = surveyDirectChild($item, 'value');
            if ($value) {
                $choiceValue = trim($value->textContent);
                $choiceLabel = surveyElementLabel($item, $document);
                $choices[] = [
                    'value' => $choiceValue,
                    'label' => $choiceLabel !== '' ? $choiceLabel : $choiceValue,
                ];
            }
        }
        if (in_array($type, ['select_one', 'select_multiple'], true) && !$choices) {
            throw new RuntimeException('Form ini memakai pilihan dinamis yang tidak dapat dimuat otomatis.');
        }
        if ($type === 'image' && $element->getAttribute('mediatype') !== ''
            && stripos($element->getAttribute('mediatype'), 'image/') !== 0) {
            throw new RuntimeException('Form ini meminta lampiran selain gambar, yang belum didukung.');
        }
        $label = surveyElementLabel($element, $document);
        $fieldKey = 'field_' . count($fields);
        $fields[] = [
            'key' => $fieldKey,
            'path' => $path,
            'name' => end($path),
            'label' => $label !== '' ? $label : end($path),
            'type' => $type,
            'required' => $bind['required']
                || in_array(strtolower($element->getAttribute('required')), ['true()', 'true', '1'], true),
            'choices' => $choices,
            'appearance' => $element->getAttribute('appearance'),
            'media_type' => $element->getAttribute('mediatype'),
        ];
    }
    if (!$fields) {
        throw new RuntimeException('Tidak ada pertanyaan yang didukung pada formulir ini.');
    }
    return [
        'form_id' => $config['form_id'],
        'title' => $config['form_title'],
        'root_name' => $rootName,
        'version' => $version,
        'fields' => $fields,
    ];
}

function surveyChildElement($parent, $name)
{
    foreach ($parent->childNodes as $child) {
        if ($child instanceof DOMElement && $child->nodeName === $name) {
            return $child;
        }
    }
    $element = $parent->ownerDocument->createElement($name);
    $parent->appendChild($element);
    return $element;
}

function surveyFileEntry($files, $key)
{
    if (!isset($files['attachments']['name'][$key])) {
        return null;
    }
    return [
        'name' => $files['attachments']['name'][$key],
        'type' => $files['attachments']['type'][$key],
        'tmp_name' => $files['attachments']['tmp_name'][$key],
        'error' => $files['attachments']['error'][$key],
        'size' => $files['attachments']['size'][$key],
    ];
}

function surveyBuildSubmissionXml($form, $answers, $files, &$attachmentPaths)
{
    $document = new DOMDocument('1.0', 'UTF-8');
    $document->formatOutput = false;
    $root = $document->createElement($form['root_name']);
    $root->setAttribute('id', $form['form_id']);
    if ($form['version'] !== '') {
        $root->setAttribute('version', $form['version']);
    }
    $document->appendChild($root);
    $meta = surveyChildElement($root, 'meta');
    $instanceId = $document->createElement('instanceID', 'uuid:' . bin2hex(random_bytes(16)));
    $meta->appendChild($instanceId);
    $allowedKeys = [];

    foreach ($form['fields'] as $field) {
        $key = $field['key'];
        $allowedKeys[$key] = true;
        $file = $field['type'] === 'image' ? surveyFileEntry($files, $key) : null;
        if ($field['type'] === 'image') {
            $value = '';
            if ($file && $file['error'] !== UPLOAD_ERR_NO_FILE) {
                if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] > 5 * 1024 * 1024
                    || !is_uploaded_file($file['tmp_name'])) {
                    throw new InvalidArgumentException('Foto untuk "' . $field['label'] . '" tidak valid atau melebihi 5 MB.');
                }
                $fileInfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = $fileInfo->file($file['tmp_name']);
                if (!is_string($mime) || strpos($mime, 'image/') !== 0) {
                    throw new InvalidArgumentException('Lampiran "' . $field['label'] . '" harus berupa file gambar.');
                }
                $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                if (!preg_match('/^[a-z0-9]{1,8}$/', $extension)) {
                    $extension = 'jpg';
                }
                $safeFilename = $field['key'] . '_' . bin2hex(random_bytes(6)) . '.' . $extension;
                $temporaryPath = tempnam(sys_get_temp_dir(), 'ona_media_');
                if ($temporaryPath === false || !move_uploaded_file($file['tmp_name'], $temporaryPath)) {
                    throw new RuntimeException('Lampiran "' . $field['label'] . '" tidak dapat diproses.');
                }
                $attachmentPaths[] = ['path' => $temporaryPath, 'name' => $safeFilename, 'mime' => $mime];
                $value = $safeFilename;
            }
        } else {
            $value = isset($answers[$key]) ? $answers[$key] : '';
        }
        $isEmpty = is_array($value) ? !$value : trim((string) $value) === '';
        if ($field['required'] && $isEmpty) {
            throw new InvalidArgumentException('Pertanyaan "' . $field['label'] . '" wajib diisi.');
        }
        if ($isEmpty) {
            continue;
        }
        if (!is_array($value) && !is_string($value) && !is_numeric($value)) {
            throw new InvalidArgumentException('Nilai untuk "' . $field['label'] . '" tidak valid.');
        }
        if ($field['type'] === 'select_one' || $field['type'] === 'select_multiple') {
            $values = is_array($value) ? $value : [$value];
            $choiceValues = array_column($field['choices'], 'value');
            foreach ($values as $choice) {
                if (!is_string($choice) || !in_array($choice, $choiceValues, true)) {
                    throw new InvalidArgumentException('Pilihan untuk "' . $field['label'] . '" tidak valid.');
                }
            }
            $value = implode(' ', $values);
        } elseif ($field['type'] === 'integer' && filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new InvalidArgumentException('Nilai "' . $field['label'] . '" harus bilangan bulat.');
        } elseif ($field['type'] === 'number' && !is_numeric($value)) {
            throw new InvalidArgumentException('Nilai "' . $field['label'] . '" harus angka.');
        } elseif ($field['type'] === 'date'
            && !preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', (string) $value)) {
            throw new InvalidArgumentException('Tanggal "' . $field['label'] . '" tidak valid.');
        } elseif ($field['type'] === 'geopoint') {
            $coordinates = preg_split('/\\s+/', trim((string) $value));
            if (count($coordinates) < 2 || count($coordinates) > 4
                || !is_numeric($coordinates[0]) || !is_numeric($coordinates[1])
                || (float) $coordinates[0] < -90 || (float) $coordinates[0] > 90
                || (float) $coordinates[1] < -180 || (float) $coordinates[1] > 180) {
                throw new InvalidArgumentException('Koordinat "' . $field['label'] . '" tidak valid.');
            }
        }
        $parent = $root;
        foreach ($field['path'] as $segmentIndex => $segment) {
            $element = surveyChildElement($parent, $segment);
            if ($segmentIndex === count($field['path']) - 1) {
                if (is_array($value)) {
                    $value = implode(' ', $value);
                }
                $element->appendChild($document->createTextNode((string) $value));
            }
            $parent = $element;
        }
    }

    foreach ($answers as $key => $unusedValue) {
        if (!isset($allowedKeys[$key])) {
            throw new InvalidArgumentException('Formulir berisi kolom yang tidak dikenali. Muat ulang formulir.');
        }
    }
    return $document->saveXML();
}

function surveySubmitToOna($config, $form, $answers, $files)
{
    $attachmentPaths = [];
    try {
        $xml = surveyBuildSubmissionXml($form, $answers, $files, $attachmentPaths);
        $xmlPath = tempnam(sys_get_temp_dir(), 'ona_xml_');
        if ($xmlPath === false || file_put_contents($xmlPath, $xml) === false) {
            throw new RuntimeException('Berkas XML submission tidak dapat dibuat.');
        }
        $postFields = [
            'xml_submission_file' => new CURLFile($xmlPath, 'text/xml', 'submission.xml'),
        ];
        foreach ($attachmentPaths as $attachment) {
            $postFields[$attachment['name']] = new CURLFile(
                $attachment['path'],
                $attachment['mime'],
                $attachment['name']
            );
        }
        $response = surveyOnaRequest(
            validateOnaServer($config['server_url']),
            '/api/v1/submissions',
            'POST',
            $postFields
        );
        $projectId = isset($config['project_pk']) && ctype_digit((string) $config['project_pk'])
            ? (int) $config['project_pk']
            : 0;
        if ($response['status'] === 404 && $projectId > 0) {
            $projectFields = [
                'xml_submission_file' => new CURLFile($xmlPath, 'text/xml', 'submission.xml'),
            ];
            foreach ($attachmentPaths as $attachment) {
                $projectFields[$attachment['name']] = new CURLFile(
                    $attachment['path'],
                    $attachment['mime'],
                    $attachment['name']
                );
            }
            $response = surveyOnaRequest(
                validateOnaServer($config['server_url']),
                '/projects/' . $projectId . '/submission',
                'POST',
                $projectFields
            );
        }
        unlink($xmlPath);
        if (!in_array($response['status'], [201, 202], true)) {
            $detail = trim(strip_tags($response['body']));
            if (strlen($detail) > 400) {
                $detail = substr($detail, 0, 400) . '...';
            }
            throw new RuntimeException('ONA tidak menerima submission (HTTP ' . $response['status'] . ').'
                . ($detail !== '' ? ' ' . $detail : ''));
        }
        return ['message' => 'Survei berhasil dikirim ke ONA.', 'status' => $response['status']];
    } finally {
        foreach ($attachmentPaths as $attachment) {
            if (is_file($attachment['path'])) {
                unlink($attachment['path']);
            }
        }
        if (isset($xmlPath) && is_file($xmlPath)) {
            unlink($xmlPath);
        }
    }
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    session_start();
}

$action = isset($_GET['action']) && is_string($_GET['action']) ? $_GET['action'] : '';
if ($action !== '') {
    try {
        $connection = dbConnection();
        surveyConfigTable($connection);

        if ($action === 'state' && $_SERVER['REQUEST_METHOD'] === 'GET') {
            $config = surveyGetConfiguredForm($connection);
            surveyRespond(200, [
                'configured' => $config !== null,
                'admin_authenticated' => !empty($_SESSION['ona_admin']),
                'admin_available' => surveyAdminPasswordHash() !== '' && surveyOnaToken() !== '',
                'csrf_token' => surveyEnsureCsrfToken(),
                'config' => $config ? [
                    'server_url' => $config['server_url'],
                    'form_id' => $config['form_id'],
                    'title' => $config['form_title'],
                ] : null,
            ]);
        }

        if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $payload = surveyReadJsonBody();
            $hash = surveyAdminPasswordHash();
            if ($hash === '' || surveyOnaToken() === '') {
                surveyRespond(503, ['error' => 'Admin ONA belum dikonfigurasi. Tetapkan APP_ADMIN_PASSWORD_HASH dan ONA_API_TOKEN pada server.']);
            }
            if (isset($_SESSION['ona_login_last']) && time() - $_SESSION['ona_login_last'] >= 300) {
                unset($_SESSION['ona_login_attempts'], $_SESSION['ona_login_last']);
            }
            if (isset($_SESSION['ona_login_attempts']) && $_SESSION['ona_login_attempts'] >= 5) {
                surveyRespond(429, ['error' => 'Terlalu banyak percobaan login. Tunggu beberapa menit sebelum mencoba lagi.']);
            }
            $password = isset($payload['password']) && is_string($payload['password']) ? $payload['password'] : '';
            if ($password === '' || !password_verify($password, $hash)) {
                $_SESSION['ona_login_attempts'] = isset($_SESSION['ona_login_attempts'])
                    ? $_SESSION['ona_login_attempts'] + 1
                    : 1;
                $_SESSION['ona_login_last'] = time();
                surveyRespond(401, ['error' => 'Kata sandi admin salah.']);
            }
            session_regenerate_id(true);
            $_SESSION['ona_admin'] = true;
            unset($_SESSION['ona_login_attempts'], $_SESSION['ona_login_last']);
            $_SESSION['survey_csrf'] = bin2hex(random_bytes(24));
            surveyRespond(200, ['message' => 'Login admin berhasil.', 'csrf_token' => $_SESSION['survey_csrf']]);
        }

        if ($action === 'logout' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            surveyRequireAdmin();
            surveyRequireCsrf();
            unset($_SESSION['ona_admin']);
            $_SESSION['survey_csrf'] = bin2hex(random_bytes(24));
            surveyRespond(200, ['message' => 'Logout berhasil.']);
        }

        if ($action === 'forms' && $_SERVER['REQUEST_METHOD'] === 'GET') {
            surveyRequireAdmin();
            $server = validateOnaServer(isset($_GET['server_url']) ? $_GET['server_url'] : 'https://api.ona.io');
            surveyRespond(200, ['forms' => surveyOnaForms($server)]);
        }

        if ($action === 'configure' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            surveyRequireAdmin();
            surveyRequireCsrf();
            $payload = surveyReadJsonBody();
            $server = validateOnaServer(isset($payload['server_url']) ? $payload['server_url'] : '');
            $formId = isset($payload['form_id']) && is_string($payload['form_id']) ? trim($payload['form_id']) : '';
            if ($formId === '' || strlen($formId) > 120) {
                surveyRespond(400, ['error' => 'Formulir ONA harus dipilih.']);
            }
            $selectedForm = null;
            foreach (surveyOnaForms($server) as $form) {
                if ($form['form_id'] === $formId) {
                    $selectedForm = $form;
                    break;
                }
            }
            if (!$selectedForm) {
                surveyRespond(404, ['error' => 'Formulir tersebut tidak ditemukan pada akun ONA.']);
            }
            executeStatement(
                $connection,
                'INSERT INTO `gis_ona_config` (`id`, `server_url`, `form_pk`, `form_id`, `project_pk`, `form_title`)
                VALUES (1, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE `server_url` = VALUES(`server_url`),
                    `form_pk` = VALUES(`form_pk`), `form_id` = VALUES(`form_id`),
                    `project_pk` = VALUES(`project_pk`), `form_title` = VALUES(`form_title`)',
                [
                    $server,
                    $selectedForm['form_pk'],
                    $selectedForm['form_id'],
                    $selectedForm['project_id'],
                    $selectedForm['title'],
                ]
            );
            surveyRespond(200, ['message' => 'Formulir survei aktif berhasil diperbarui.']);
        }

        if ($action === 'form' && $_SERVER['REQUEST_METHOD'] === 'GET') {
            $config = surveyGetConfiguredForm($connection);
            if (!$config) {
                surveyRespond(404, ['error' => 'Admin belum memilih formulir survei aktif.']);
            }
            surveyRespond(200, ['form' => surveyLoadFormDefinition($config)]);
        }

        if ($action === 'submit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            surveyRequireCsrf();
            $config = surveyGetConfiguredForm($connection);
            if (!$config) {
                surveyRespond(404, ['error' => 'Admin belum memilih formulir survei aktif.']);
            }
            $answersJson = isset($_POST['answers_json']) && is_string($_POST['answers_json'])
                ? $_POST['answers_json']
                : '';
            if (strlen($answersJson) > 65536) {
                surveyRespond(413, ['error' => 'Jawaban survei terlalu besar.']);
            }
            $answers = json_decode($answersJson, true);
            if (!is_array($answers)) {
                surveyRespond(400, ['error' => 'Jawaban survei tidak valid.']);
            }
            $form = surveyLoadFormDefinition($config);
            surveyRespond(201, surveySubmitToOna($config, $form, $answers, $_FILES));
        }

        header('Allow: GET, POST');
        surveyRespond(405, ['error' => 'Aksi survei atau metode permintaan tidak valid.']);
    } catch (InvalidArgumentException $exception) {
        surveyRespond(400, ['error' => $exception->getMessage()]);
    } catch (Throwable $exception) {
        $message = $exception->getMessage();
        $token = surveyOnaToken();
        if ($token !== '') {
            $message = str_replace($token, '[redacted]', $message);
        }
        surveyRespond(502, ['error' => $message]);
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Survei Lapangan ONA - Web GIS</title>
    <style>
        :root { font-family: Arial, sans-serif; color: #172033; background: #f3f6fa; }
        * { box-sizing: border-box; }
        [hidden] { display: none !important; }
        body { margin: 0; }
        header { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; padding: 14px max(16px, calc((100% - 1040px) / 2)); background: #fff; border-bottom: 1px solid #e2e8f0; }
        header strong { font-size: 17px; }
        main { width: min(1040px, calc(100% - 28px)); margin: 20px auto; }
        .card { margin-bottom: 16px; padding: 18px; border: 1px solid #dbe3ee; border-radius: 12px; background: #fff; box-shadow: 0 4px 14px #0f172a0a; }
        h1, h2, h3 { margin-top: 0; }
        h1 { font-size: 23px; }
        h2 { font-size: 18px; }
        p { color: #475569; line-height: 1.5; }
        .button { display: inline-flex; align-items: center; justify-content: center; min-height: 38px; padding: 8px 12px; border: 0; border-radius: 7px; background: #e2e8f0; color: #1e293b; font: inherit; font-weight: 600; text-decoration: none; cursor: pointer; }
        .button.primary { background: #2563eb; color: #fff; }
        .button.danger { background: #fee2e2; color: #991b1b; }
        .button:disabled { opacity: .6; cursor: wait; }
        .status { min-height: 20px; margin: 10px 0 0; color: #475569; font-size: 14px; }
        .status.error { color: #b91c1c; }
        .status.success { color: #166534; }
        .field-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; }
        .field { display: grid; align-content: start; gap: 6px; margin: 12px 0; }
        .field > span, .field > label { font-weight: 600; font-size: 14px; }
        .field input, .field select, .field textarea { width: 100%; min-height: 40px; padding: 9px 10px; border: 1px solid #cbd5e1; border-radius: 7px; color: #172033; font: inherit; }
        .field textarea { min-height: 90px; resize: vertical; }
        .admin-grid { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); align-items: end; gap: 10px; }
        .choice-list { display: grid; gap: 6px; padding: 8px 0; }
        .choice-list label { display: flex; align-items: center; gap: 8px; font-size: 14px; }
        .choice-list input { width: auto; min-height: auto; }
        .actions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 14px; }
        .field-help { margin: 0; font-size: 12px; }
        .muted { color: #64748b; font-size: 13px; }
        #admin-panel[hidden], #survey-card[hidden] { display: none; }
        #qr-reader { width: min(360px, 100%); margin-top: 10px; }
        #qr-code { margin-top: 12px; }
        @media (max-width: 650px) {
            .field-grid, .admin-grid { grid-template-columns: 1fr; }
            header { padding: 12px 14px; }
            .card { padding: 14px; }
        }
    </style>
</head>
<body>
<header>
    <strong>Survei Lapangan ONA</strong>
    <a class="button" href="index.php">Kembali ke Peta</a>
</header>
<main>
    <section class="card" id="survey-card">
        <h1 id="survey-title">Formulir Survei</h1>
        <p id="survey-intro">Memuat formulir aktif dari ONA...</p>
        <form id="survey-form" hidden>
            <div class="field-grid" id="survey-fields"></div>
            <div class="actions">
                <button class="button primary" id="submit-survey" type="submit">Kirim ke ONA</button>
                <button class="button" id="reload-form" type="button">Muat ulang formulir</button>
            </div>
        </form>
        <p class="status" id="survey-status" role="status" aria-live="polite"></p>
    </section>

    <section class="card">
        <h2>Admin ONA</h2>
        <p id="admin-setup-help" class="muted">Admin diperlukan untuk memilih formulir ONA. Token ONA tidak pernah dikirim ke browser atau QR.</p>
        <form id="admin-login-form" class="admin-grid">
            <label class="field" for="admin-password">Kata sandi admin
                <input id="admin-password" type="password" autocomplete="current-password" required>
            </label>
            <div class="field"><button class="button primary" type="submit">Login admin</button></div>
        </form>
        <p class="status" id="admin-login-status" role="status" aria-live="polite"></p>
        <section id="admin-panel" hidden>
            <div class="admin-grid">
                <label class="field" for="ona-server">Server ONA
                    <input id="ona-server" type="url" value="https://api.ona.io" readonly>
                </label>
                <label class="field" for="ona-form-select">Formulir yang diaktifkan
                    <select id="ona-form-select"><option value="">Muat daftar formulir...</option></select>
                </label>
            </div>
            <div class="actions">
                <button class="button" id="load-ona-forms" type="button">Muat daftar formulir</button>
                <button class="button primary" id="save-ona-form" type="button">Aktifkan formulir</button>
                <button class="button" id="scan-config" type="button">Pindai QR konfigurasi</button>
                <button class="button" id="logout-admin" type="button">Logout</button>
            </div>
            <div id="qr-reader" hidden></div>
            <div id="qr-code" aria-label="QR konfigurasi server dan form"></div>
            <p class="field-help">QR hanya berisi server ONA dan ID form, tidak pernah berisi token atau kata sandi.</p>
            <p class="status" id="admin-status" role="status" aria-live="polite"></p>
        </section>
    </section>
</main>
<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<script src="https://unpkg.com/qrcodejs@1.0.0/qrcode.min.js"></script>
<script>
    (function () {
        'use strict';
        var state = null;
        var csrfToken = '';
        var activeForm = null;
        var adminLoginStatus = document.getElementById('admin-login-status');
        var adminStatus = document.getElementById('admin-status');
        var surveyStatus = document.getElementById('survey-status');

        function setStatus(element, message, kind) {
            element.textContent = message;
            element.className = 'status' + (kind ? ' ' + kind : '');
        }
        async function api(url, options) {
            var response = await fetch(url, options || {});
            var payload;
            try {
                payload = await response.json();
            } catch (error) {
                throw new Error('Respons server survei tidak valid.');
            }
            if (!response.ok) {
                throw new Error(payload.error || 'Permintaan survei gagal.');
            }
            return payload;
        }
        function renderState() {
            document.getElementById('admin-login-form').hidden = state.admin_authenticated;
            document.getElementById('admin-panel').hidden = !state.admin_authenticated;
            document.getElementById('admin-setup-help').textContent = state.admin_available
                ? 'Login admin diperlukan untuk memilih form survei. Token hanya digunakan oleh server.'
                : 'Admin belum siap. Atur APP_ADMIN_PASSWORD_HASH (hash password) dan ONA_API_TOKEN sebagai SetEnv di konfigurasi Apache, lalu restart Apache. Jangan masukkan token ke halaman atau QR.';
            document.getElementById('admin-login-form').hidden = !state.admin_available || state.admin_authenticated;
            if (state.configured) {
                document.getElementById('survey-title').textContent = state.config.title;
                document.getElementById('survey-intro').textContent = 'Form mengikuti definisi yang sedang aktif di server ONA.';
            } else {
                document.getElementById('survey-title').textContent = 'Formulir Survei';
                document.getElementById('survey-intro').textContent = 'Belum ada form aktif. Hubungi admin untuk memilih form ONA.';
            }
            if (state.admin_authenticated && state.config) {
                document.getElementById('ona-server').value = state.config.server_url;
                loadForms(state.config.form_id);
                renderQr(state.config);
            }
        }
        async function loadState() {
            state = await api('survey.php?action=state');
            csrfToken = state.csrf_token;
            renderState();
            if (state.configured) {
                await loadActiveForm();
            }
        }
        async function loadForms(selectedId) {
            try {
                var server = document.getElementById('ona-server').value;
                var payload = await api('survey.php?action=forms&server_url=' + encodeURIComponent(server));
                var select = document.getElementById('ona-form-select');
                select.replaceChildren();
                if (!payload.forms.length) {
                    select.add(new Option('Tidak ada form tersedia', ''));
                    return;
                }
                payload.forms.forEach(function (form) {
                    var option = new Option(form.title + ' (' + form.form_id + ')', form.form_id);
                    select.add(option);
                });
                if (selectedId) {
                    select.value = selectedId;
                }
                setStatus(adminStatus, payload.forms.length + ' form berhasil dimuat dari ONA.', 'success');
            } catch (error) {
                setStatus(adminStatus, error.message, 'error');
            }
        }
        function renderQr(config) {
            var target = document.getElementById('qr-code');
            target.replaceChildren();
            if (typeof window.QRCode !== 'function') {
                setStatus(adminStatus, 'Pustaka QR gagal dimuat.', 'error');
                return;
            }
            new window.QRCode(target, {
                text: JSON.stringify({server_url: config.server_url, form_id: config.form_id}),
                width: 180,
                height: 180,
                correctLevel: window.QRCode.CorrectLevel.M
            });
        }
        function renderField(field) {
            var wrapper = document.createElement('label');
            wrapper.className = 'field';
            wrapper.htmlFor = 'answer-' + field.key;
            var label = document.createElement('span');
            label.textContent = field.label + (field.required ? ' *' : '');
            wrapper.appendChild(label);

            if (field.type === 'select_one' || field.type === 'select_multiple') {
                var list = document.createElement('div');
                list.className = 'choice-list';
                field.choices.forEach(function (choice, index) {
                    var choiceLabel = document.createElement('label');
                    var input = document.createElement('input');
                    input.type = field.type === 'select_one' ? 'radio' : 'checkbox';
                    input.name = 'answer-' + field.key;
                    input.value = choice.value;
                    input.required = field.required && field.type === 'select_one' && index === 0;
                    input.dataset.fieldKey = field.key;
                    choiceLabel.appendChild(input);
                    choiceLabel.appendChild(document.createTextNode(choice.label));
                    list.appendChild(choiceLabel);
                });
                wrapper.appendChild(list);
            } else if (field.type === 'image') {
                var fileInput = document.createElement('input');
                fileInput.type = 'file';
                fileInput.accept = 'image/*';
                fileInput.capture = 'environment';
                fileInput.required = field.required;
                fileInput.id = 'answer-' + field.key;
                fileInput.dataset.fieldKey = field.key;
                wrapper.appendChild(fileInput);
                var help = document.createElement('span');
                help.className = 'field-help';
                help.textContent = 'Foto maksimal 5 MB.';
                wrapper.appendChild(help);
            } else if (field.type === 'geopoint') {
                var geoInput = document.createElement('input');
                geoInput.type = 'text';
                geoInput.readOnly = true;
                geoInput.placeholder = 'Tekan Ambil lokasi';
                geoInput.id = 'answer-' + field.key;
                geoInput.dataset.fieldKey = field.key;
                geoInput.required = field.required;
                wrapper.appendChild(geoInput);
                var locate = document.createElement('button');
                locate.type = 'button';
                locate.className = 'button';
                locate.textContent = 'Ambil lokasi GPS';
                locate.addEventListener('click', function () {
                    if (!navigator.geolocation) {
                        setStatus(surveyStatus, 'Perangkat ini tidak mendukung GPS/geolocation.', 'error');
                        return;
                    }
                    locate.disabled = true;
                    navigator.geolocation.getCurrentPosition(function (position) {
                        geoInput.value = [
                            position.coords.latitude,
                            position.coords.longitude,
                            position.coords.altitude || 0,
                            position.coords.accuracy || 0
                        ].join(' ');
                        locate.disabled = false;
                    }, function (error) {
                        locate.disabled = false;
                        setStatus(surveyStatus, 'Lokasi GPS gagal: ' + error.message, 'error');
                    }, {enableHighAccuracy: true, timeout: 20000, maximumAge: 0});
                });
                wrapper.appendChild(locate);
            } else {
                var input = field.appearance.indexOf('multiline') !== -1
                    ? document.createElement('textarea')
                    : document.createElement('input');
                input.id = 'answer-' + field.key;
                input.dataset.fieldKey = field.key;
                input.required = field.required;
                if (input.tagName === 'TEXTAREA') {
                    input.rows = 3;
                } else if (field.type === 'integer') input.type = 'number', input.step = '1';
                else if (field.type === 'number') input.type = 'number', input.step = 'any';
                else if (field.type === 'date' || field.type === 'time') input.type = field.type;
                else if (field.type === 'datetime') input.type = 'datetime-local';
                else input.type = 'text';
                wrapper.appendChild(input);
            }
            return wrapper;
        }
        async function loadActiveForm() {
            var intro = document.getElementById('survey-intro');
            var formElement = document.getElementById('survey-form');
            formElement.hidden = true;
            setStatus(surveyStatus, 'Mengambil definisi form dari ONA...', '');
            try {
                var payload = await api('survey.php?action=form');
                activeForm = payload.form;
                document.getElementById('survey-title').textContent = activeForm.title;
                intro.textContent = 'Form ini menggunakan pertanyaan dari XForm yang aktif di ONA. Jawaban dan foto dikirim langsung ke ONA.';
                var fields = document.getElementById('survey-fields');
                fields.replaceChildren();
                activeForm.fields.forEach(function (field) {
                    fields.appendChild(renderField(field));
                });
                formElement.hidden = false;
                setStatus(surveyStatus, activeForm.fields.length + ' pertanyaan siap diisi.', 'success');
            } catch (error) {
                intro.textContent = 'Formulir belum dapat dimuat. Periksa koneksi ONA atau hubungi admin.';
                setStatus(surveyStatus, error.message, 'error');
            }
        }
        document.getElementById('admin-login-form').addEventListener('submit', async function (event) {
            event.preventDefault();
            setStatus(adminLoginStatus, 'Memeriksa kata sandi admin...', '');
            try {
                var payload = await api('survey.php?action=login', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({password: document.getElementById('admin-password').value})
                });
                csrfToken = payload.csrf_token;
                document.getElementById('admin-password').value = '';
                setStatus(adminLoginStatus, payload.message, 'success');
                await loadState();
            } catch (error) {
                setStatus(adminLoginStatus, error.message, 'error');
            }
        });
        document.getElementById('load-ona-forms').addEventListener('click', function () {
            loadForms(document.getElementById('ona-form-select').value);
        });
        document.getElementById('save-ona-form').addEventListener('click', async function () {
            var formId = document.getElementById('ona-form-select').value;
            if (!formId) {
                setStatus(adminStatus, 'Pilih formulir ONA terlebih dahulu.', 'error');
                return;
            }
            try {
                var payload = await api('survey.php?action=configure', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken},
                    body: JSON.stringify({
                        server_url: document.getElementById('ona-server').value,
                        form_id: formId
                    })
                });
                setStatus(adminStatus, payload.message, 'success');
                await loadState();
            } catch (error) {
                setStatus(adminStatus, error.message, 'error');
            }
        });
        document.getElementById('logout-admin').addEventListener('click', async function () {
            try {
                await api('survey.php?action=logout', {
                    method: 'POST',
                    headers: {'X-CSRF-Token': csrfToken}
                });
                await loadState();
            } catch (error) {
                setStatus(adminStatus, error.message, 'error');
            }
        });
        document.getElementById('scan-config').addEventListener('click', async function () {
            var reader = document.getElementById('qr-reader');
            if (typeof window.Html5Qrcode !== 'function') {
                setStatus(adminStatus, 'Pustaka pemindai QR gagal dimuat.', 'error');
                return;
            }
            reader.hidden = false;
            var scanner = new window.Html5Qrcode('qr-reader');
            try {
                await scanner.start({facingMode: 'environment'}, {fps: 10, qrbox: 230}, async function (text) {
                    try {
                        var scanned = JSON.parse(text);
                        var serverUrl = new URL(scanned.server_url).origin;
                        if (serverUrl !== 'https://api.ona.io' || typeof scanned.form_id !== 'string') {
                            throw new Error('QR harus berisi server https://api.ona.io dan ID formulir.');
                        }
                        await scanner.stop();
                        reader.hidden = true;
                        document.getElementById('ona-server').value = serverUrl;
                        await loadForms(scanned.form_id);
                        document.getElementById('ona-form-select').value = scanned.form_id;
                        setStatus(adminStatus, 'Konfigurasi QR dibaca. Verifikasi form lalu aktifkan.', 'success');
                    } catch (error) {
                        setStatus(adminStatus, 'Isi QR tidak sesuai format konfigurasi ONA.', 'error');
                    }
                });
            } catch (error) {
                reader.hidden = true;
                setStatus(adminStatus, 'Kamera QR gagal dimulai: ' + error.message, 'error');
            }
        });
        document.getElementById('reload-form').addEventListener('click', loadActiveForm);
        document.getElementById('survey-form').addEventListener('submit', async function (event) {
            event.preventDefault();
            if (!activeForm) return;
            var submitButton = document.getElementById('submit-survey');
            var formData = new FormData();
            var answers = {};
            var oversizedPhoto = activeForm.fields.some(function (field) {
                if (field.type !== 'image') return false;
                var input = document.getElementById('answer-' + field.key);
                return input.files[0] && input.files[0].size > 5 * 1024 * 1024;
            });
            if (oversizedPhoto) {
                setStatus(surveyStatus, 'Setiap foto harus berukuran maksimal 5 MB.', 'error');
                return;
            }
            activeForm.fields.forEach(function (field) {
                if (field.type === 'image') {
                    var fileInput = document.getElementById('answer-' + field.key);
                    if (fileInput.files[0]) {
                        formData.append('attachments[' + field.key + ']', fileInput.files[0]);
                    }
                } else if (field.type === 'select_multiple') {
                    answers[field.key] = Array.from(document.querySelectorAll('input[name="answer-' + CSS.escape(field.key) + '"]:checked'))
                        .map(function (input) { return input.value; });
                } else if (field.type === 'select_one') {
                    var selected = document.querySelector('input[name="answer-' + CSS.escape(field.key) + '"]:checked');
                    answers[field.key] = selected ? selected.value : '';
                } else {
                    answers[field.key] = document.getElementById('answer-' + field.key).value;
                }
            });
            formData.append('answers_json', JSON.stringify(answers));
            submitButton.disabled = true;
            setStatus(surveyStatus, 'Mengirim data ke ONA...', '');
            try {
                var payload = await api('survey.php?action=submit', {
                    method: 'POST',
                    headers: {'X-CSRF-Token': csrfToken},
                    body: formData
                });
                document.getElementById('survey-form').reset();
                setStatus(surveyStatus, payload.message, 'success');
            } catch (error) {
                setStatus(surveyStatus, error.message, 'error');
            } finally {
                submitButton.disabled = false;
            }
        });
        loadState().catch(function (error) {
            setStatus(surveyStatus, error.message, 'error');
        });
    }());
</script>
</body>
</html>
