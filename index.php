<?php
require_once __DIR__ . '/includes/database.php';

$columns = [];
$primaryColumns = [];
$imageColumn = null;
$records = [];
$mapPoints = [];
$error = '';
$message = isset($_GET['message']) ? (string) $_GET['message'] : '';
$latitudeColumn = null;
$longitudeColumn = null;
$sequentialIdColumn = null;

try {
    $connection = dbConnection();
    $columns = getTableColumns($connection);
    if (!$columns) {
        throw new RuntimeException('Tabel tidak memiliki kolom yang dapat dibaca.');
    }

    $primaryColumns = getPrimaryColumns($columns);
    $imageColumn = getImageColumn($columns);
    $columnNames = [];
    foreach ($columns as $column) {
        if ($column['Key'] === 'PRI' && stripos($column['Extra'], 'auto_increment') !== false) {
            $sequentialIdColumn = $column['Field'];
        }
        $normalized = strtolower(preg_replace('/[^a-z0-9]/i', '', $column['Field']));
        $columnNames[$normalized] = $column['Field'];
    }

    foreach (['latitude', 'lat', 'lintang'] as $candidate) {
        if (isset($columnNames[$candidate])) {
            $latitudeColumn = $columnNames[$candidate];
            break;
        }
    }
    foreach (['longitude', 'longtitude', 'long', 'lon', 'lng', 'bujur'] as $candidate) {
        if (isset($columnNames[$candidate])) {
            $longitudeColumn = $columnNames[$candidate];
            break;
        }
    }

    $orderBy = [];
    foreach ($primaryColumns as $primaryColumn) {
        $orderBy[] = quoteIdentifier($primaryColumn) . ' ASC';
    }
    $records = $connection->query(
        'SELECT * FROM ' . quoteIdentifier(DB_TABLE) . ' ORDER BY ' . implode(', ', $orderBy)
    )->fetch_all(MYSQLI_ASSOC);

    $recordNumber = 0;
    if ($latitudeColumn && $longitudeColumn) {
        foreach ($records as $record) {
            $recordNumber++;
            $latitude = $record[$latitudeColumn];
            $longitude = $record[$longitudeColumn];
            if (!is_numeric($latitude) || !is_numeric($longitude)
                || (float) $latitude < -90 || (float) $latitude > 90
                || (float) $longitude < -180 || (float) $longitude > 180) {
                continue;
            }

            $label = isset($record['Nama']) && $record['Nama'] !== ''
                ? (string) $record['Nama']
                : 'Titik ' . (count($mapPoints) + 1);
            $popup = [];
            foreach ($columns as $column) {
                $field = $column['Field'];
                if (($imageColumn && $field === $imageColumn['Field'])
                    || ($field !== $latitudeColumn && $field !== $longitudeColumn
                    && (!isset($record[$field]) || $record[$field] === ''))) {
                    continue;
                }
                $popup[] = [
                    'label' => getColumnLabel($field),
                    'value' => $field === $sequentialIdColumn
                        ? (string) $recordNumber
                        : (isset($record[$field]) ? (string) $record[$field] : ''),
                ];
            }
            $mapPoints[] = [
                'latitude' => (float) $latitude,
                'longitude' => (float) $longitude,
                'label' => $label,
                'popup' => $popup,
                'imageUrl' => $imageColumn
                    ? getImageDisplayUrl($record[$imageColumn['Field']])
                    : '',
            ];
        }
    }
} catch (Throwable $exception) {
    $error = $exception->getMessage();
}

$mapPointsJson = json_encode(
    $mapPoints,
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
);
if ($mapPointsJson === false) {
    $mapPointsJson = '[]';
    $error = 'Data titik tidak dapat ditampilkan pada peta.';
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Peta Web GIS - Sebaran Fasilitas D.I. Yogyakarta</title>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet-draw@1.0.4/dist/leaflet.draw.css">
    <style>
        :root { font-family: Arial, sans-serif; color: #172033; background: #f3f6fa; }
        * { box-sizing: border-box; }
        body { margin: 0; }
        .topbar { position: relative; z-index: 2000; min-height: 72px; display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 10px 24px; background: #fff; border-bottom: 1px solid #e2e8f0; }
        .brand { display: flex; align-items: center; gap: 12px; min-width: 0; }
        .brand-logo { width: 54px; height: 54px; object-fit: contain; }
        .brand-title { min-width: 0; }
        .brand-title strong { display: block; font-size: 16px; }
        .brand-title span { display: block; margin-top: 4px; color: #64748b; font-size: 12px; }
        .toolbar { display: flex; align-items: center; justify-content: flex-end; gap: 8px; }
        .nav-items { display: flex; align-items: center; gap: 6px; }
        .button { display: inline-flex; align-items: center; justify-content: center; border: 0; border-radius: 7px; padding: 9px 13px; font-size: 14px; font-weight: 600; text-decoration: none; cursor: pointer; }
        .button[hidden], [hidden] { display: none !important; }
        .primary { background: #2563eb; color: #fff; }
        .secondary { background: #e2e8f0; color: #1e293b; }
        .danger { background: #fee2e2; color: #b91c1c; }
        .tool-active { background: #0f766e; color: #fff; }
        .toolbar .button { flex: 0 0 auto; padding: 8px 10px; font-size: 13px; white-space: nowrap; transition: background-color 150ms ease, color 150ms ease, box-shadow 150ms ease, transform 150ms ease; }
        .toolbar .button:hover { background: #dbeafe; color: #1d4ed8; box-shadow: 0 3px 10px #0f172a1a; transform: translateY(-1px); }
        .toolbar .button.primary:hover { background: #1d4ed8; color: #fff; }
        .toolbar .button.tool-active:hover { background: #115e59; color: #fff; }
        .toolbar .button:focus-visible { outline: 3px solid #93c5fd; outline-offset: 2px; }
        .menu-toggle { display: none; width: 42px; height: 42px; padding: 9px; border: 1px solid #cbd5e1; border-radius: 8px; background: #fff; color: #1e293b; cursor: pointer; }
        .menu-toggle:hover, .menu-toggle:focus-visible { background: #eff6ff; border-color: #93c5fd; }
        .menu-toggle:focus-visible { outline: 3px solid #93c5fd; outline-offset: 2px; }
        .menu-icon, .menu-icon::before, .menu-icon::after { display: block; width: 20px; height: 2px; border-radius: 2px; background: currentColor; }
        .menu-icon { position: relative; margin: auto; }
        .menu-icon::before, .menu-icon::after { position: absolute; left: 0; content: ""; }
        .menu-icon::before { top: -6px; }
        .menu-icon::after { top: 6px; }
        .topbar.is-collapsed .menu-toggle { display: inline-flex; align-items: center; justify-content: center; }
        .topbar.is-collapsed .toolbar { position: static; gap: 0; }
        .topbar.is-collapsed .nav-items { position: absolute; top: calc(100% + 8px); right: 12px; z-index: 2100; display: flex; flex-direction: column; align-items: stretch; gap: 5px; width: max-content; min-width: 190px; max-width: calc(100vw - 24px); padding: 8px; border: 1px solid #dbe3ee; border-radius: 10px; background: #fff; box-shadow: 0 12px 30px #0f172a26; opacity: 0; visibility: hidden; transform: translateY(-6px); transition: opacity 150ms ease, transform 150ms ease, visibility 150ms ease; }
        .topbar.is-collapsed.menu-open .nav-items { opacity: 1; visibility: visible; transform: translateY(0); }
        .topbar.is-collapsed .nav-items .button { justify-content: flex-start; width: 100%; min-height: 40px; padding: 9px 11px; }
        .leaflet-top, .leaflet-bottom { z-index: 800; }
        .notice { margin: 12px 20px 0; padding: 11px 14px; border-radius: 8px; }
        .message { background: #dcfce7; color: #166534; }
        .error { background: #fee2e2; color: #991b1b; overflow-wrap: anywhere; }
        .map-wrap { position: relative; margin: 16px 20px; overflow: hidden; border: 1px solid #dbe3ee; border-radius: 12px; background: #e2e8f0; box-shadow: 0 4px 14px #0f172a0a; }
        #map { width: 100%; height: min(72vh, 760px); min-height: 420px; }
        .map-status { position: absolute; z-index: 500; left: 12px; bottom: 12px; max-width: min(420px, calc(100% - 24px)); padding: 8px 11px; border-radius: 7px; background: #fff; box-shadow: 0 1px 5px #0f172a33; color: #475569; font-size: 12px; }
        .records { margin: 0 20px 24px; border: 1px solid #e2e8f0; border-radius: 12px; background: #fff; box-shadow: 0 4px 14px #0f172a0a; }
        .records summary { padding: 16px 20px; cursor: pointer; font-weight: 700; }
        .records-content { padding: 0 20px 20px; }
        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 10px 12px; border-bottom: 1px solid #e2e8f0; text-align: left; vertical-align: top; }
        th { background: #f8fafc; font-size: 13px; white-space: nowrap; }
        td { font-size: 14px; }
        .actions { white-space: nowrap; }
        .actions form { display: inline; }
        .actions .button { padding: 7px 10px; }
        .image-thumb { display: block; width: 100px; height: 68px; object-fit: cover; border: 1px solid #e2e8f0; border-radius: 6px; }
        .empty { color: #64748b; text-align: center; padding: 24px; }
        .leaflet-popup-content { min-width: 160px; }
        .popup-title { margin: 0 0 8px; font-size: 15px; }
        .popup-image { display: block; width: 100%; max-width: 260px; max-height: 180px; margin: 0 0 10px; border-radius: 6px; object-fit: cover; }
        .popup-row { margin: 4px 0; overflow-wrap: anywhere; }
        .popup-row strong { color: #475569; }
        .map-legend-control { max-width: calc(100vw - 36px); }
        .map-legend-toggle { display: block; width: 100%; min-height: 36px; padding: 8px 11px; border: 1px solid #cbd5e1; border-radius: 7px; background: #fff; color: #172033; font: inherit; font-size: 13px; font-weight: 600; text-align: left; cursor: pointer; }
        .map-legend-toggle:hover, .map-legend-toggle:focus-visible { background: #eff6ff; border-color: #93c5fd; }
        .map-legend-toggle:focus-visible { outline: 3px solid #93c5fd; outline-offset: 2px; }
        .map-legend { width: min(270px, calc(100vw - 60px)); max-height: min(calc(52vh - 120px), 460px); overflow-y: auto; margin-top: 6px; padding: 12px; border: 1px solid #dbe3ee; border-radius: 9px; background: #fff; box-shadow: 0 1px 5px #0f172a33; }
        .map-legend[hidden] { display: none; }
        .map-legend h2 { margin: 0 0 8px; font-size: 14px; }
        .legend-item { display: flex; align-items: center; gap: 8px; margin: 7px 0; color: #334155; font-size: 12px; overflow-wrap: anywhere; }
        .legend-swatch { flex: 0 0 16px; width: 16px; height: 12px; border: 2px solid #2563eb; background: #60a5fa66; }
        .legend-swatch.point { width: 12px; height: 12px; border: 0; border-radius: 50%; background: #2563eb; }
        .legend-swatch.route { height: 0; border: 0; border-top: 3px solid #dc2626; background: none; }
        .legend-swatch.measure { height: 0; border: 0; border-top: 3px dashed #0f766e; background: none; }
        .geoserver-legend-section { margin-top: 10px; padding-top: 8px; border-top: 1px solid #e2e8f0; }
        .geoserver-legend-section h3 { margin: 0 0 6px; font-size: 13px; overflow-wrap: anywhere; }
        .geoserver-legend-image { display: block; max-width: 100%; height: auto; }
        .geoserver-legend-error { margin: 0; color: #64748b; font-size: 12px; }
        .modal { width: min(520px, calc(100% - 28px)); max-height: min(80vh, 720px); padding: 0; border: 0; border-radius: 12px; box-shadow: 0 18px 60px #0f172a55; color: #172033; }
        .modal::backdrop { background: #0f172a80; }
        .modal-content { padding: 22px; }
        .modal h2 { margin: 0 0 8px; }
        .modal p { color: #475569; line-height: 1.55; }
        .modal-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 18px; }
        .import-form { display: grid; gap: 12px; margin-top: 16px; }
        .import-form label { display: grid; gap: 6px; font-size: 14px; font-weight: 600; }
        .import-form input { width: 100%; padding: 9px; border: 1px solid #cbd5e1; border-radius: 7px; }
        .import-help { margin: 0; font-size: 13px; }
        .geoserver-form input { width: 100%; padding: 9px; border: 1px solid #cbd5e1; border-radius: 7px; }
        .modal-status { min-height: 20px; margin: 0; font-size: 13px; }
        .modal-status.error-text { color: #b91c1c; }
        .modal-status.success-text { color: #166534; }
        .import-layer-list { max-height: 180px; overflow: auto; margin: 12px 0 0; padding: 0; list-style: none; }
        .import-layer-list li { display: flex; justify-content: space-between; align-items: center; gap: 10px; padding: 8px 0; border-top: 1px solid #e2e8f0; font-size: 13px; }
        .import-layer-list .button { padding: 6px 9px; font-size: 12px; }
        .waypoint-coordinates { margin: 0; color: #64748b; font-size: 13px; }
        .import-form textarea, .import-form select { width: 100%; padding: 9px; border: 1px solid #cbd5e1; border-radius: 7px; font: inherit; }
        .format-actions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 12px; }
        .profile-name { margin: 16px 0 4px; font-size: 19px; font-weight: 700; color: #172033 !important; }
        .profile-details { margin-top: 0; }
        .activity-panel { margin: 0 20px 20px; padding: 18px; border: 1px solid #dbe3ee; border-radius: 12px; background: #fff; box-shadow: 0 4px 14px #0f172a0a; }
        .activity-panel[hidden] { display: none; }
        .activity-panel h2, .activity-panel h3 { margin: 0 0 12px; }
        .activity-warning { margin: 0 0 14px; padding: 10px 12px; border-radius: 8px; background: #fff7ed; color: #9a3412; font-size: 13px; line-height: 1.5; }
        .activity-controls { display: flex; flex-wrap: wrap; align-items: end; gap: 10px; }
        .activity-field { display: grid; gap: 5px; font-size: 13px; font-weight: 600; }
        .activity-field input, .activity-field select { min-height: 38px; padding: 8px 10px; border: 1px solid #cbd5e1; border-radius: 7px; color: #172033; font: inherit; }
        .activity-field input { width: min(280px, 100%); }
        .activity-stats { display: grid; grid-template-columns: repeat(4, minmax(100px, 1fr)); gap: 10px; margin: 16px 0; }
        .activity-stat { padding: 12px; border-radius: 9px; background: #f8fafc; }
        .activity-stat span { display: block; color: #64748b; font-size: 12px; }
        .activity-stat strong { display: block; margin-top: 5px; font-size: 20px; font-variant-numeric: tabular-nums; }
        .activity-status { min-height: 20px; margin: 10px 0 0; color: #475569; font-size: 13px; }
        .activity-status.error-text { color: #b91c1c; }
        .activity-history { margin: 18px 0 0; padding: 0; list-style: none; }
        .activity-history li { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 11px 0; border-top: 1px solid #e2e8f0; }
        .activity-history strong, .activity-history span { display: block; }
        .activity-history span { margin-top: 4px; color: #64748b; font-size: 12px; }
        .activity-history .button { flex: 0 0 auto; padding: 7px 10px; font-size: 12px; }
        @media (max-width: 650px) {
            .topbar { padding: 10px 12px; }
            .brand-title span { max-width: 180px; }
            .notice { margin-right: 10px; margin-left: 10px; }
            .map-wrap { margin: 10px; }
            #map { height: 68vh; min-height: 360px; }
            .records { margin: 10px; }
            .activity-panel { margin: 10px; padding: 14px; }
            .activity-stats { grid-template-columns: repeat(2, minmax(90px, 1fr)); }
            .activity-controls { align-items: stretch; }
            .activity-field, .activity-field input, .activity-field select { width: 100%; }
            .records-content { padding: 0 12px 14px; }
        }
    </style>
</head>
<body>
<header class="topbar">
    <div class="brand">
        <img class="brand-logo" src="images/gis-globe-pin-map-logo.png" alt="Logo Web GIS">
        <div class="brand-title">
            <strong>Peta Web GIS</strong>
            <span>Sebaran Fasilitas D.I. Yogyakarta</span>
        </div>
    </div>
    <nav class="toolbar" aria-label="Menu peta">
        <button class="menu-toggle" id="menu-toggle" type="button" aria-label="Buka menu" aria-controls="nav-items" aria-expanded="false">
            <span class="menu-icon" aria-hidden="true"></span>
        </button>
        <div class="nav-items" id="nav-items">
            <a class="button primary" href="input/input.php">+ Tambah Data</a>
            <button class="button secondary" id="measure-tool" type="button">Ukur Jarak</button>
            <button class="button secondary" id="route-tool" type="button">Cari Rute</button>
            <button class="button secondary" id="tracking-tool" type="button">Tracking GPS</button>
            <button class="button secondary" id="waypoint-tool" type="button">Tambah Waypoint</button>
            <a class="button secondary" href="survey.php">Survei Lapangan</a>
            <button class="button secondary" id="import-tool" type="button">Impor Layer</button>
            <button class="button secondary" id="geoserver-tool" type="button">GeoServer WMS</button>
            <button class="button secondary" id="legend-tool" type="button" aria-expanded="false">Legenda</button>
            <button class="button secondary" id="profile-tool" type="button">Profil Pembuat</button>
        </div>
    </nav>
</header>

<?php if ($message !== ''): ?>
    <div class="notice message"><?php echo escapeHtml($message); ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
    <div class="notice error">Terjadi kesalahan: <?php echo escapeHtml($error); ?></div>
<?php endif; ?>

<main>
    <section class="map-wrap" aria-label="Peta interaktif">
        <div id="map"></div>
        <aside class="map-legend" id="map-legend" hidden>
            <h2>Legenda Peta</h2>
            <div class="legend-item"><span class="legend-swatch point"></span><span>Titik fasilitas dari database</span></div>
            <div class="legend-item"><span class="legend-swatch"></span><span>Poligon/persegi panjang gambar</span></div>
            <div class="legend-item"><span class="legend-swatch route"></span><span>Rute jalan hasil pencarian</span></div>
            <div class="legend-item"><span class="legend-swatch measure"></span><span>Garis ukur jarak</span></div>
            <div id="imported-legend"></div>
            <div id="geoserver-legend"></div>
        </aside>
        <div class="map-status" id="map-status" role="status" aria-live="polite">
            Memuat peta dan data titik...
        </div>
    </section>

    <section class="activity-panel" id="activity-panel" hidden aria-labelledby="activity-title">
        <h2 id="activity-title">Tracking &amp; Aktivitas</h2>
        <p class="activity-warning">Aktivitas yang disimpan dapat dilihat oleh semua pengguna situs. Tracking hanya aktif setelah Anda menekan Mulai dan memberikan izin lokasi. Pastikan Anda nyaman membagikan rute sebelum menyimpan.</p>
        <div class="activity-controls">
            <label class="activity-field" for="activity-name">Nama aktivitas
                <input id="activity-name" type="text" maxlength="120" placeholder="Contoh: Lari pagi">
            </label>
            <label class="activity-field" for="activity-type">Jenis
                <select id="activity-type">
                    <option value="Lari">Lari</option>
                    <option value="Jalan kaki">Jalan kaki</option>
                    <option value="Bersepeda">Bersepeda</option>
                </select>
            </label>
            <button class="button primary" id="start-tracking" type="button">Mulai</button>
            <button class="button secondary" id="pause-tracking" type="button" hidden>Jeda</button>
            <button class="button secondary" id="resume-tracking" type="button" hidden>Lanjutkan</button>
            <button class="button danger" id="finish-tracking" type="button" hidden>Selesai &amp; Simpan</button>
            <button class="button secondary" id="discard-tracking" type="button" hidden>Buang</button>
        </div>
        <div class="activity-stats" aria-label="Statistik aktivitas saat ini">
            <div class="activity-stat"><span>Jarak</span><strong id="track-distance">0 m</strong></div>
            <div class="activity-stat"><span>Durasi</span><strong id="track-duration">00:00:00</strong></div>
            <div class="activity-stat"><span id="track-pace-label">Pace</span><strong id="track-pace">—</strong></div>
            <div class="activity-stat"><span>Titik GPS</span><strong id="track-point-count">0</strong></div>
        </div>
        <p class="activity-status" id="tracking-status" role="status" aria-live="polite">Siap memulai. Perlu izin lokasi perangkat.</p>
        <h3>Aktivitas tersimpan (maks. 100 terbaru)</h3>
        <ul class="activity-history" id="activity-history"><li>Memuat aktivitas...</li></ul>
    </section>

    <details class="records">
        <summary>Daftar Data (<?php echo count($records); ?>)</summary>
        <div class="records-content">
            <?php if (!$error): ?>
                <?php if ($columns): ?>
                    <div class="table-wrap">
                        <table>
                            <thead>
                            <tr>
                                <?php foreach ($columns as $column): ?>
                                    <th><?php echo escapeHtml(getColumnLabel($column['Field'])); ?></th>
                                <?php endforeach; ?>
                                <th>Aksi</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php if (!$records): ?>
                                <tr><td class="empty" colspan="<?php echo count($columns) + 1; ?>">Belum ada data.</td></tr>
                            <?php else: ?>
                                <?php $displayId = 0; ?>
                                <?php foreach ($records as $record): ?>
                                    <?php
                                    $displayId++;
                                    $keyParameters = [];
                                    foreach ($primaryColumns as $primaryColumn) {
                                        $keyParameters['key[' . $primaryColumn . ']'] = $record[$primaryColumn];
                                    }
                                    $editUrl = 'edit/edit.php?' . http_build_query($keyParameters);
                                    ?>
                                    <tr>
                                        <?php foreach ($columns as $column): ?>
                                            <?php if ($imageColumn && $column['Field'] === $imageColumn['Field']): ?>
                                                <?php $imageUrl = getImageDisplayUrl($record[$column['Field']]); ?>
                                                <td>
                                                    <?php if ($imageUrl !== ''): ?>
                                                        <img class="image-thumb" src="<?php echo escapeHtml($imageUrl); ?>" alt="Gambar data" loading="lazy">
                                                    <?php elseif (!empty($record[$column['Field']])): ?>
                                                        Tautan gambar tidak valid
                                                    <?php else: ?>
                                                        Belum ada gambar
                                                    <?php endif; ?>
                                                </td>
                                            <?php elseif ($column['Field'] === $sequentialIdColumn): ?>
                                                <td><?php echo $displayId; ?></td>
                                            <?php else: ?>
                                                <td><?php echo escapeHtml($record[$column['Field']]); ?></td>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                        <td class="actions">
                                            <a class="button secondary" href="<?php echo escapeHtml($editUrl); ?>">Edit</a>
                                            <form action="delete.php" method="post" onsubmit="return confirm('Yakin ingin menghapus data ini?');">
                                                <input type="hidden" name="action" value="delete">
                                                <?php foreach ($primaryColumns as $primaryColumn): ?>
                                                    <input type="hidden" name="key[<?php echo escapeHtml($primaryColumn); ?>]" value="<?php echo escapeHtml($record[$primaryColumn]); ?>">
                                                <?php endforeach; ?>
                                                <button class="button danger" type="submit">Hapus</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <p>Daftar data tidak dapat dimuat.</p>
            <?php endif; ?>
        </div>
    </details>
</main>

<dialog class="modal" id="import-modal" aria-labelledby="import-title">
    <div class="modal-content">
        <h2 id="import-title">Impor Data Spasial</h2>
        <p class="import-help">Format yang didukung: GeoJSON, CSV, ZIP shapefile, KML, KMZ, GPX, dan GeoTIFF. Layer vektor disimpan di database (maksimal 5 MB setelah konversi); file GeoTIFF maksimum 25 MB juga disimpan di server.</p>
        <form class="import-form" id="import-form">
            <label for="layer-name">Nama layer
                <input id="layer-name" name="name" type="text" maxlength="120" required>
            </label>
            <label for="layer-file">Pilih file
                <input id="layer-file" name="file" type="file" accept=".geojson,.json,.csv,.zip,.kml,.kmz,.gpx,.tif,.tiff,application/geo+json,application/json,text/csv,application/zip,application/vnd.google-earth.kml+xml,application/gpx+xml,image/tiff" required>
            </label>
            <p class="modal-status" id="import-status" role="status" aria-live="polite"></p>
            <div class="modal-actions">
                <button class="button secondary" id="close-import" type="button">Tutup</button>
                <button class="button primary" id="save-import" type="submit">Impor dan Simpan</button>
            </div>
        </form>
        <h3>Layer tersimpan</h3>
        <ul class="import-layer-list" id="import-layer-list"><li>Memuat layer...</li></ul>
        <h3>GeoTIFF tersimpan</h3>
        <ul class="import-layer-list" id="raster-list"><li>Memuat raster...</li></ul>
        <div class="format-actions">
            <button class="button secondary" id="export-geojson" type="button">Ekspor data GeoJSON</button>
            <button class="button secondary" id="export-kml" type="button">Ekspor data KML</button>
            <button class="button secondary" id="export-kmz" type="button">Ekspor data KMZ</button>
            <button class="button secondary" id="export-gpx" type="button">Ekspor waypoint/rute GPX</button>
        </div>
    </div>
</dialog>

<dialog class="modal" id="geoserver-modal" aria-labelledby="geoserver-title">
    <div class="modal-content">
        <h2 id="geoserver-title">Tampilkan Layer GeoServer</h2>
        <p class="import-help">Layer GeoServer Jalan dan Wonosobo sudah tersedia otomatis. Anda juga dapat menambahkan WMS lain dengan URL layanan dan nama layer (workspace:nama_layer); tambahan disimpan di browser ini.</p>
        <form class="import-form geoserver-form" id="geoserver-form">
            <label for="geoserver-url">URL layanan WMS
                <input id="geoserver-url" type="url" maxlength="2048" placeholder="http://localhost:8080/geoserver/wisata/wms" required>
            </label>
            <label for="geoserver-layer-name">Nama layer (workspace:layer)
                <input id="geoserver-layer-name" type="text" maxlength="255" placeholder="wisata:fasilitas" required>
            </label>
            <label for="geoserver-display-name">Nama tampilan di peta
                <input id="geoserver-display-name" type="text" maxlength="120" placeholder="Fasilitas wisata" required>
            </label>
            <p class="modal-status" id="geoserver-status" role="status" aria-live="polite"></p>
            <div class="modal-actions">
                <button class="button secondary" id="close-geoserver" type="button">Tutup</button>
                <button class="button primary" type="submit">Tampilkan Layer</button>
            </div>
        </form>
        <h3>Layer GeoServer tersedia di peta ini</h3>
        <ul class="import-layer-list" id="geoserver-layer-list"><li>Belum ada layer GeoServer.</li></ul>
    </div>
</dialog>

<dialog class="modal" id="waypoint-modal" aria-labelledby="waypoint-title">
    <div class="modal-content">
        <h2 id="waypoint-title">Tambah Waypoint</h2>
        <p class="import-help">Pilih lokasi dengan klik pada peta atau ambil posisi GPS perangkat. Titik dapat diberi nama dan catatan serta disimpan agar tersedia pada kunjungan berikutnya.</p>
        <form class="import-form" id="waypoint-form">
            <p class="waypoint-coordinates" id="waypoint-coordinates">Pilih lokasi pada peta.</p>
            <button class="button secondary" id="waypoint-gps" type="button">Gunakan lokasi GPS saya</button>
            <label for="waypoint-name">Nama waypoint
                <input id="waypoint-name" type="text" maxlength="120" required>
            </label>
            <label for="waypoint-notes">Catatan
                <textarea id="waypoint-notes" maxlength="1000" rows="3"></textarea>
            </label>
            <p class="modal-status" id="waypoint-status" role="status" aria-live="polite"></p>
            <div class="modal-actions">
                <button class="button secondary" id="close-waypoint" type="button">Tutup</button>
                <button class="button primary" id="save-waypoint" type="submit">Simpan Waypoint</button>
            </div>
        </form>
        <h3>Waypoint tersimpan</h3>
        <ul class="import-layer-list" id="waypoint-list"><li>Memuat waypoint...</li></ul>
    </div>
</dialog>

<dialog class="modal" id="profile-modal" aria-labelledby="profile-title">
    <div class="modal-content">
        <h2 id="profile-title">Profil Pembuat</h2>
        <p class="profile-name">Ruli Ardiansah</p>
        <p class="profile-details">Program Studi Sarjana Terapan Sistem Informasi Geografis</p>
        <div class="modal-actions">
            <button class="button primary" id="close-profile" type="button">Tutup</button>
        </div>
    </div>
</dialog>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://unpkg.com/leaflet-draw@1.0.4/dist/leaflet.draw.js"></script>
<script src="https://unpkg.com/shpjs@latest/dist/shp.min.js"></script>
<script src="https://unpkg.com/@tmcw/togeojson@5.8.1/dist/togeojson.umd.js"></script>
<script src="https://unpkg.com/jszip@3.10.1/dist/jszip.min.js"></script>
<script src="https://unpkg.com/georaster@1.6.0/dist/georaster.browser.bundle.min.js"></script>
<script src="https://unpkg.com/georaster-layer-for-leaflet@3.10.0/dist/georaster-layer-for-leaflet.min.js"></script>
<script>
    (function () {
        'use strict';

        var points = <?php echo $mapPointsJson; ?>;
        var status = document.getElementById('map-status');
        var map = L.map('map').setView([-7.677567, 110.406899], 12);
        var imagery = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
            attribution: 'Tiles &copy; Esri',
            maxZoom: 19
        });
        var street = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; OpenStreetMap contributors',
            maxZoom: 19
        });
        imagery.addTo(map);
        var layerControl = L.control.layers({'Esri Imagery': imagery, 'OpenStreetMap': street}, null, {collapsed: true}).addTo(map);
        var legendControl = L.control({position: 'topright'});
        legendControl.onAdd = function () {
            var container = L.DomUtil.create('div', 'map-legend-control');
            var toggle = L.DomUtil.create('button', 'map-legend-toggle', container);
            toggle.type = 'button';
            toggle.id = 'map-legend-toggle';
            toggle.textContent = 'Legenda';
            toggle.setAttribute('aria-expanded', 'false');
            toggle.setAttribute('aria-controls', 'map-legend');
            container.appendChild(document.getElementById('map-legend'));
            L.DomEvent.disableClickPropagation(container);
            L.DomEvent.disableScrollPropagation(container);
            toggle.addEventListener('click', toggleMapLegend);
            return container;
        };
        legendControl.addTo(map);
        L.control.scale({maxWidth: 150, position: 'bottomright'}).addTo(map);
        map.createPane('geoserver-background').style.zIndex = '250';
        map.createPane('geoserver-overlay').style.zIndex = '450';

        var pointGroup = L.featureGroup().addTo(map);
        var waypointGroup = L.featureGroup().addTo(map);
        var waypointRecords = [];
        var rasterLayers = [];
        var pendingWaypointLocation = null;
        var waypointLayerList = document.getElementById('waypoint-list');
        var waypointModal = document.getElementById('waypoint-modal');
        var waypointStatus = document.getElementById('waypoint-status');
        function renderWaypoints() {
            waypointGroup.clearLayers();
            waypointLayerList.replaceChildren();
            if (!waypointRecords.length) {
                var empty = document.createElement('li');
                empty.textContent = 'Belum ada waypoint tersimpan.';
                waypointLayerList.appendChild(empty);
                return;
            }
            waypointRecords.forEach(function (waypoint) {
                var marker = L.marker([waypoint.latitude, waypoint.longitude]);
                var popup = document.createElement('div');
                var heading = document.createElement('strong');
                heading.textContent = waypoint.name;
                popup.appendChild(heading);
                if (waypoint.notes) {
                    var note = document.createElement('div');
                    note.className = 'popup-row';
                    note.textContent = waypoint.notes;
                    popup.appendChild(note);
                }
                marker.bindPopup(popup).addTo(waypointGroup);

                var row = document.createElement('li');
                var label = document.createElement('span');
                label.textContent = waypoint.name + ' · ' + Number(waypoint.latitude).toFixed(5)
                    + ', ' + Number(waypoint.longitude).toFixed(5);
                var remove = document.createElement('button');
                remove.type = 'button';
                remove.className = 'button danger';
                remove.textContent = 'Hapus';
                remove.addEventListener('click', function () {
                    if (!window.confirm('Hapus waypoint "' + waypoint.name + '"?')) {
                        return;
                    }
                    remove.disabled = true;
                    fetch('waypoints.php', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/json'},
                        body: JSON.stringify({action: 'delete', id: waypoint.id})
                    }).then(function (response) {
                        return response.json().then(function (payload) {
                            if (!response.ok) {
                                throw new Error(payload.error || 'Waypoint gagal dihapus.');
                            }
                            waypointRecords = waypointRecords.filter(function (item) {
                                return item.id !== waypoint.id;
                            });
                            renderWaypoints();
                            setStatus(payload.message, false);
                        });
                    }).catch(function (error) {
                        remove.disabled = false;
                        setStatus(error.message, true);
                    });
                });
                row.appendChild(label);
                row.appendChild(remove);
                waypointLayerList.appendChild(row);
            });
        }
        function loadWaypoints() {
            fetch('waypoints.php').then(function (response) {
                return response.json().then(function (payload) {
                    if (!response.ok || !Array.isArray(payload.waypoints)) {
                        throw new Error(payload.error || 'Daftar waypoint tidak valid.');
                    }
                    waypointRecords = payload.waypoints;
                    renderWaypoints();
                });
            }).catch(function (error) {
                waypointLayerList.replaceChildren();
                var failure = document.createElement('li');
                failure.textContent = 'Waypoint gagal dimuat: ' + error.message;
                waypointLayerList.appendChild(failure);
                setStatus(error.message, true);
            });
        }
        loadWaypoints();
        points.forEach(function (point) {
            var marker = L.marker([point.latitude, point.longitude]);
            var content = document.createElement('div');
            var heading = document.createElement('h3');
            heading.className = 'popup-title';
            heading.textContent = point.label;
            content.appendChild(heading);
            if (point.imageUrl) {
                var image = document.createElement('img');
                image.className = 'popup-image';
                image.src = point.imageUrl;
                image.alt = 'Foto ' + point.label;
                image.addEventListener('error', function () {
                    image.remove();
                });
                content.appendChild(image);
            }
            point.popup.forEach(function (item) {
                var row = document.createElement('div');
                var label = document.createElement('strong');
                label.textContent = item.label + ': ';
                row.className = 'popup-row';
                row.appendChild(label);
                row.appendChild(document.createTextNode(item.value));
                content.appendChild(row);
            });
            marker.bindPopup(content).addTo(pointGroup);
        });

        var importedLayers = [];
        var importedLegend = document.getElementById('imported-legend');
        var importedLayerList = document.getElementById('import-layer-list');
        var rasterList = document.getElementById('raster-list');
        var layerColors = ['#7c3aed', '#ea580c', '#0891b2', '#be123c', '#4d7c0f'];
        function downloadTextFile(filename, content, mimeType) {
            downloadBlobFile(filename, new Blob([content], {type: mimeType}));
        }
        function downloadBlobFile(filename, blob) {
            var link = document.createElement('a');
            var url = URL.createObjectURL(blob);
            link.href = url;
            link.download = filename;
            document.body.appendChild(link);
            link.click();
            link.remove();
            window.setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
        }
        async function exportFieldData(format) {
            if (!waypointRecords.length && !importedLayers.length
                && !(drawnItems && drawnItems.getLayers().length)
                && !(activityRecords && activityRecords.length)) {
                setStatus('Belum ada waypoint, layer, atau rute untuk diekspor.', true);
                return;
            }
            function xmlEscape(value) {
                return String(value).replace(/[&<>"']/g, function (character) {
                    return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&apos;'}[character];
                });
            }
            var features = [];
            importedLayers.forEach(function (item) {
                item.geojson.features.forEach(function (feature) {
                    var copy = JSON.parse(JSON.stringify(feature));
                    copy.properties = Object.assign({}, copy.properties || {}, {layer_name: item.name});
                    features.push(copy);
                });
            });
            waypointRecords.forEach(function (waypoint) {
                features.push({
                    type: 'Feature',
                    properties: {name: waypoint.name, notes: waypoint.notes, layer_name: 'Waypoints'},
                    geometry: {type: 'Point', coordinates: [waypoint.longitude, waypoint.latitude]}
                });
            });
            if (drawnItems) {
                drawnItems.toGeoJSON().features.forEach(function (feature) {
                    var copy = JSON.parse(JSON.stringify(feature));
                    copy.properties = Object.assign({}, copy.properties || {}, {layer_name: 'Objek gambar'});
                    features.push(copy);
                });
            }
            if (activityRecords) {
                activityRecords.forEach(function (activity) {
                    if (activity.route && activity.route.geometry) {
                        var copy = JSON.parse(JSON.stringify(activity.route));
                        copy.properties = Object.assign({}, copy.properties || {}, {
                            name: activity.name,
                            layer_name: 'Aktivitas ' + activity.type
                        });
                        features.push(copy);
                    }
                });
            }
            if (format === 'geojson') {
                var geojson = {
                    type: 'FeatureCollection',
                    features: features
                };
                downloadTextFile('field-data.geojson', JSON.stringify(geojson, null, 2), 'application/geo+json');
            } else if (format === 'kml' || format === 'kmz') {
                function kmlCoordinates(coordinates) {
                    return coordinates.map(function (coordinate) {
                        return xmlEscape(coordinate[0]) + ',' + xmlEscape(coordinate[1])
                            + ',' + xmlEscape(coordinate[2] || 0);
                    }).join(' ');
                }
                function kmlGeometry(geometry) {
                    if (!geometry) return '';
                    if (geometry.type === 'Point') {
                        return '<Point><coordinates>' + kmlCoordinates([geometry.coordinates]) + '</coordinates></Point>';
                    }
                    if (geometry.type === 'MultiPoint') {
                        return '<MultiGeometry>' + geometry.coordinates.map(function (point) {
                            return kmlGeometry({type: 'Point', coordinates: point});
                        }).join('') + '</MultiGeometry>';
                    }
                    if (geometry.type === 'LineString') {
                        return '<LineString><coordinates>' + kmlCoordinates(geometry.coordinates) + '</coordinates></LineString>';
                    }
                    if (geometry.type === 'MultiLineString') {
                        return '<MultiGeometry>' + geometry.coordinates.map(function (line) {
                            return kmlGeometry({type: 'LineString', coordinates: line});
                        }).join('') + '</MultiGeometry>';
                    }
                    if (geometry.type === 'Polygon') {
                        var rings = geometry.coordinates.map(function (ring, index) {
                            return '<' + (index === 0 ? 'outerBoundaryIs' : 'innerBoundaryIs')
                                + '><LinearRing><coordinates>' + kmlCoordinates(ring) + '</coordinates></LinearRing></'
                                + (index === 0 ? 'outerBoundaryIs' : 'innerBoundaryIs') + '>';
                        }).join('');
                        return '<Polygon>' + rings + '</Polygon>';
                    }
                    if (geometry.type === 'MultiPolygon') {
                        return '<MultiGeometry>' + geometry.coordinates.map(function (polygon) {
                            return kmlGeometry({type: 'Polygon', coordinates: polygon});
                        }).join('') + '</MultiGeometry>';
                    }
                    if (geometry.type === 'GeometryCollection') {
                        return '<MultiGeometry>' + geometry.geometries.map(kmlGeometry).join('') + '</MultiGeometry>';
                    }
                    return '';
                }
                var placemarks = features.map(function (feature) {
                    var properties = feature.properties || {};
                    var name = properties.name || properties.Name || properties.layer_name || 'Fitur';
                    var description = properties.notes || properties.description || '';
                    return '<Placemark><name>' + xmlEscape(name) + '</name><description>'
                        + xmlEscape(description) + '</description>' + kmlGeometry(feature.geometry) + '</Placemark>';
                }).join('');
                var kml = '<?xml version="1.0" encoding="UTF-8"?>'
                    + '<kml xmlns="http://www.opengis.net/kml/2.2"><Document>' + placemarks
                    + '</Document></kml>';
                if (format === 'kmz') {
                    if (!window.JSZip) {
                        setStatus('Pustaka kompresi KMZ gagal dimuat.', true);
                        return;
                    }
                    var archive = new window.JSZip();
                    archive.file('doc.kml', kml);
                    downloadBlobFile('field-data.kmz', await archive.generateAsync({type: 'blob'}));
                } else {
                    downloadTextFile('field-data.kml', kml, 'application/vnd.google-earth.kml+xml');
                }
            } else {
                var pointsXml = waypointRecords.map(function (waypoint) {
                    return '<wpt lat="' + Number(waypoint.latitude) + '" lon="' + Number(waypoint.longitude)
                        + '"><name>' + xmlEscape(waypoint.name) + '</name><desc>'
                        + xmlEscape(waypoint.notes || '') + '</desc></wpt>';
                }).join('');
                var tracksXml = features.map(function (feature) {
                    var geometry = feature.geometry;
                    if (!geometry || !['LineString', 'MultiLineString'].includes(geometry.type)) {
                        return '';
                    }
                    var flatCoordinates = geometry.type === 'LineString'
                        ? geometry.coordinates
                        : geometry.coordinates.reduce(function (all, line) { return all.concat(line); }, []);
                    var trackPoints = flatCoordinates.map(function (coordinate) {
                        return '<trkpt lat="' + xmlEscape(coordinate[1]) + '" lon="'
                            + xmlEscape(coordinate[0]) + '"></trkpt>';
                    }).join('');
                    var name = feature.properties && (feature.properties.name || feature.properties.layer_name)
                        ? feature.properties.name || feature.properties.layer_name
                        : 'Rute';
                    return '<trk><name>' + xmlEscape(name) + '</name><trkseg>'
                        + trackPoints + '</trkseg></trk>';
                }).join('');
                downloadTextFile('field-data.gpx', '<?xml version="1.0" encoding="UTF-8"?>'
                    + '<gpx version="1.1" creator="Web GIS" xmlns="http://www.topografix.com/GPX/1/1">'
                    + pointsXml + tracksXml + '</gpx>', 'application/gpx+xml');
            }
            setStatus('Data lapangan berhasil diekspor ke ' + format.toUpperCase() + '.', false);
        }
        ['geojson', 'kml', 'kmz', 'gpx'].forEach(function (format) {
            document.getElementById('export-' + format).addEventListener('click', function () {
                exportFieldData(format).catch(function (error) {
                    setStatus('Ekspor ' + format.toUpperCase() + ' gagal: ' + error.message, true);
                });
            });
        });
        function escapeHtml(value) {
            return String(value).replace(/[&<>"']/g, function (character) {
                return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[character];
            });
        }
        function normalizeGeoJson(data) {
            var features = [];
            function append(value) {
                if (!value || typeof value !== 'object') {
                    throw new Error('Struktur GeoJSON tidak dikenali.');
                }
                if (value.type === 'FeatureCollection' && Array.isArray(value.features)) {
                    features = features.concat(value.features);
                } else if (value.type === 'Feature' && value.geometry) {
                    features.push(value);
                } else if (typeof value.type === 'string' && value.coordinates) {
                    features.push({type: 'Feature', properties: {}, geometry: value});
                } else {
                    throw new Error('File harus berisi fitur GeoJSON yang valid.');
                }
            }
            if (Array.isArray(data)) {
                data.forEach(append);
            } else if (data && data.type) {
                append(data);
            } else if (data && typeof data === 'object') {
                Object.keys(data).forEach(function (key) { append(data[key]); });
            } else {
                throw new Error('Data spasial kosong atau tidak valid.');
            }
            if (!features.length) {
                throw new Error('Tidak ada fitur spasial yang dapat ditampilkan.');
            }
            return {type: 'FeatureCollection', features: features};
        }
        function addImportedLayer(layerInfo) {
            var color = layerColors[importedLayers.length % layerColors.length];
            var geoLayer = L.geoJSON(layerInfo.geojson, {
                style: function () {
                    return {color: color, weight: 2, fillColor: color, fillOpacity: 0.2};
                },
                pointToLayer: function (feature, latlng) {
                    return L.circleMarker(latlng, {
                        radius: 7, color: color, weight: 2, fillColor: color, fillOpacity: 0.8
                    });
                },
                onEachFeature: function (feature, featureLayer) {
                    var properties = feature.properties || {};
                    var content = document.createElement('div');
                    var heading = document.createElement('strong');
                    heading.textContent = layerInfo.name;
                    content.appendChild(heading);
                    Object.keys(properties).slice(0, 20).forEach(function (key) {
                        var row = document.createElement('div');
                        row.className = 'popup-row';
                        var label = document.createElement('strong');
                        label.textContent = key + ': ';
                        row.appendChild(label);
                        row.appendChild(document.createTextNode(String(properties[key] === null ? '' : properties[key])));
                        content.appendChild(row);
                    });
                    featureLayer.bindPopup(content);
                }
            });
            if (!geoLayer.getLayers().length) {
                throw new Error('Layer tidak memiliki geometri yang dapat ditampilkan.');
            }
            var item = {
                id: layerInfo.id,
                name: layerInfo.name,
                color: color,
                geojson: layerInfo.geojson,
                layer: geoLayer
            };
            importedLayers.push(item);
            layerControl.addOverlay(geoLayer, escapeHtml(item.name));
            geoLayer.addTo(map);
            renderImportedLayers();
        }
        function renderImportedLayers() {
            importedLayerList.replaceChildren();
            importedLegend.replaceChildren();
            if (!importedLayers.length) {
                var empty = document.createElement('li');
                empty.textContent = 'Belum ada layer impor tersimpan.';
                importedLayerList.appendChild(empty);
                return;
            }
            importedLayers.forEach(function (item) {
                var row = document.createElement('li');
                var name = document.createElement('span');
                name.textContent = item.name;
                var remove = document.createElement('button');
                remove.type = 'button';
                remove.className = 'button danger';
                remove.textContent = 'Hapus';
                remove.addEventListener('click', function () {
                    if (!window.confirm('Hapus layer "' + item.name + '" dari database?')) {
                        return;
                    }
                    remove.disabled = true;
                    fetch('layers.php', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/json'},
                        body: JSON.stringify({action: 'delete', id: item.id})
                    }).then(function (response) {
                        return response.json().then(function (payload) {
                            if (!response.ok) {
                                throw new Error(payload.error || 'Layer gagal dihapus.');
                            }
                            map.removeLayer(item.layer);
                            layerControl.removeLayer(item.layer);
                            importedLayers = importedLayers.filter(function (candidate) {
                                return candidate.id !== item.id;
                            });
                            renderImportedLayers();
                            setStatus(payload.message, false);
                        });
                    }).catch(function (error) {
                        remove.disabled = false;
                        setStatus(error.message, true);
                    });
                });
                row.appendChild(name);
                row.appendChild(remove);
                importedLayerList.appendChild(row);

                var legendRow = document.createElement('div');
                legendRow.className = 'legend-item';
                var swatch = document.createElement('span');
                swatch.className = 'legend-swatch';
                swatch.style.borderColor = item.color;
                swatch.style.backgroundColor = item.color + '33';
                var legendName = document.createElement('span');
                legendName.textContent = item.name;
                legendRow.appendChild(swatch);
                legendRow.appendChild(legendName);
                importedLegend.appendChild(legendRow);
            });
        }
        function loadImportedLayers() {
            fetch('layers.php').then(function (response) {
                return response.json().then(function (payload) {
                    if (!response.ok) {
                        throw new Error(payload.error || 'Layer tersimpan tidak dapat dimuat.');
                    }
                    if (!Array.isArray(payload.layers)) {
                        throw new Error('Respons daftar layer tidak valid.');
                    }
                    payload.layers.forEach(addImportedLayer);
                    renderImportedLayers();
                });
            }).catch(function (error) {
                importedLayerList.replaceChildren();
                var failure = document.createElement('li');
                failure.textContent = error.message;
                importedLayerList.appendChild(failure);
                setStatus('Layer database gagal dimuat: ' + error.message, true);
            });
        }
        renderImportedLayers();

        var drawnItems = new L.FeatureGroup().addTo(map);
        var storageKey = 'pgweb9-drawn-features';
        function setStatus(message, isError) {
            status.textContent = message;
            status.style.color = isError ? '#991b1b' : '#475569';
        }
        var geoserverStorageKey = 'pgweb9-geoserver-wms';
        var geoserverDefaultConfigs = [
            {
                url: 'http://localhost:8080/geoserver/pgweb_tes/wms',
                layer: 'pgweb_tes:jalan',
                name: 'Jalan'
            },
            {
                url: 'http://localhost:8080/geoserver/pgweb_tes/wms',
                layer: 'pgweb_tes:fixx',
                name: 'Wonosobo'
            }
        ];
        var geoserverLayers = [];
        var geoserverModal = document.getElementById('geoserver-modal');
        var geoserverForm = document.getElementById('geoserver-form');
        var geoserverStatus = document.getElementById('geoserver-status');
        var geoserverLayerList = document.getElementById('geoserver-layer-list');
        var geoserverLegend = document.getElementById('geoserver-legend');
        function setGeoServerStatus(message, isError) {
            geoserverStatus.textContent = message;
            geoserverStatus.classList.toggle('error-text', !!isError);
            geoserverStatus.classList.toggle('success-text', !isError);
        }
        function isValidGeoServerConfig(config) {
            if (!config || typeof config.url !== 'string' || typeof config.layer !== 'string'
                || typeof config.name !== 'string' || config.name.trim() === ''
                || config.layer.trim() === '' || config.name.length > 120 || config.layer.length > 255) {
                return false;
            }
            try {
                var url = new URL(config.url);
                return (url.protocol === 'http:' || url.protocol === 'https:')
                    && url.username === '' && url.password === '';
            } catch (error) {
                return false;
            }
        }
        function escapeLeafletLabel(value) {
            return String(value).replace(/[&<>"']/g, function (character) {
                return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[character];
            });
        }
        function createGeoServerLegendUrl(config) {
            var url = new URL(config.url);
            url.searchParams.set('service', 'WMS');
            url.searchParams.set('version', '1.1.1');
            url.searchParams.set('request', 'GetLegendGraphic');
            url.searchParams.set('format', 'image/png');
            url.searchParams.set('layer', config.layer);
            return url.toString();
        }
        function renderGeoServerLegend() {
            geoserverLegend.replaceChildren();
            geoserverLayers.forEach(function (item) {
                var section = document.createElement('section');
                section.className = 'geoserver-legend-section';
                var heading = document.createElement('h3');
                heading.textContent = item.name;
                var image = document.createElement('img');
                image.className = 'geoserver-legend-image';
                image.alt = 'Legenda ' + item.name + ' dari GeoServer';
                image.src = createGeoServerLegendUrl(item);
                image.addEventListener('error', function () {
                    var message = document.createElement('p');
                    message.className = 'geoserver-legend-error';
                    message.textContent = 'Legenda GeoServer untuk layer ini tidak tersedia.';
                    section.replaceChildren(heading, message);
                });
                section.appendChild(heading);
                section.appendChild(image);
                geoserverLegend.appendChild(section);
            });
        }
        function createGeoServerLayer(config) {
            var layer = L.tileLayer.wms(config.url, {
                layers: config.layer,
                format: 'image/png',
                transparent: true,
                version: '1.1.1',
                pane: config.layer === 'pgweb_tes:fixx'
                    ? 'geoserver-background'
                    : 'geoserver-overlay'
            });
            layer.on('tileerror', function () {
                setStatus('Layer GeoServer "' + config.name + '" gagal dimuat. Periksa URL WMS, nama layer, akses server, dan CRS.', true);
            });
            return layer;
        }
        function renderGeoServerLayerList() {
            geoserverLayerList.replaceChildren();
            renderGeoServerLegend();
            if (!geoserverLayers.length) {
                var empty = document.createElement('li');
                empty.textContent = 'Belum ada layer GeoServer tersimpan.';
                geoserverLayerList.appendChild(empty);
                return;
            }
            geoserverLayers.forEach(function (item) {
                var row = document.createElement('li');
                var label = document.createElement('span');
                label.textContent = item.name + ' · ' + item.layer;
                var remove = document.createElement('button');
                remove.type = 'button';
                remove.className = 'button danger';
                remove.textContent = 'Hapus';
                remove.addEventListener('click', function () {
                    if (map.hasLayer(item.leafletLayer)) {
                        map.removeLayer(item.leafletLayer);
                    }
                    layerControl.removeLayer(item.leafletLayer);
                    geoserverLayers = geoserverLayers.filter(function (candidate) {
                        return candidate !== item;
                    });
                    renderGeoServerLayerList();
                    persistGeoServerLayers();
                    setStatus('Layer GeoServer "' + item.name + '" dihapus dari peta.', false);
                });
                row.appendChild(label);
                row.appendChild(remove);
                geoserverLayerList.appendChild(row);
            });
        }
        function persistGeoServerLayers() {
            try {
                localStorage.setItem(geoserverStorageKey, JSON.stringify({
                    version: 1,
                    configs: geoserverLayers.map(function (item) {
                        return {url: item.url, layer: item.layer, name: item.name};
                    })
                }));
                return true;
            } catch (error) {
                setStatus('Layer tampil, tetapi pengaturan GeoServer gagal disimpan di browser: ' + error.message, true);
                return false;
            }
        }
        function addGeoServerLayer(config) {
            var duplicate = geoserverLayers.some(function (item) {
                return item.url === config.url && item.layer === config.layer;
            });
            if (duplicate) {
                throw new Error('Layer WMS ini sudah ditambahkan.');
            }
            var layer = createGeoServerLayer(config);
            var item = {
                url: config.url,
                layer: config.layer,
                name: config.name.trim(),
                leafletLayer: layer
            };
            geoserverLayers.push(item);
            layerControl.addOverlay(layer, escapeLeafletLabel(item.name));
            layer.addTo(map);
            renderGeoServerLayerList();
        }
        function loadGeoServerLayers() {
            var stored;
            try {
                stored = localStorage.getItem(geoserverStorageKey);
            } catch (error) {
                setStatus('Pengaturan GeoServer tidak dapat dibaca dari browser: ' + error.message, true);
                return;
            }
            var configs = geoserverDefaultConfigs.slice();
            if (stored) {
                var saved;
                try {
                    saved = JSON.parse(stored);
                } catch (error) {
                    setStatus('Pengaturan GeoServer tersimpan tidak valid dan tidak dapat dibaca.', true);
                    return;
                }
                if (Array.isArray(saved)) {
                    saved.forEach(function (config) {
                        if (isValidGeoServerConfig(config) && !configs.some(function (item) {
                            return item.url === config.url && item.layer === config.layer;
                        })) {
                            configs.push(config);
                        }
                    });
                } else if (saved && saved.version === 1 && Array.isArray(saved.configs)) {
                    configs = saved.configs;
                } else {
                    setStatus('Format pengaturan GeoServer tersimpan tidak valid.', true);
                    return;
                }
            }
            var invalidConfigFound = false;
            configs.forEach(function (config) {
                if (!isValidGeoServerConfig(config)) {
                    invalidConfigFound = true;
                    return;
                }
                try {
                    addGeoServerLayer(config);
                } catch (error) {
                    invalidConfigFound = true;
                }
            });
            if (invalidConfigFound) {
                setStatus('Sebagian pengaturan GeoServer tersimpan tidak valid atau duplikat; periksa daftar layer GeoServer.', true);
            }
            if (geoserverLayers.some(function (item) {
                return geoserverDefaultConfigs.some(function (config) {
                    return item.url === config.url && item.layer === config.layer;
                });
            })) {
                map.fitBounds([[-7.625, 109.625], [-7.125, 110.125]]);
            }
            if (!stored || Array.isArray(saved)) {
                persistGeoServerLayers();
            }
        }
        document.getElementById('geoserver-tool').addEventListener('click', function () {
            setGeoServerStatus('', false);
            geoserverModal.showModal();
        });
        document.getElementById('close-geoserver').addEventListener('click', function () {
            geoserverModal.close();
        });
        geoserverForm.addEventListener('submit', function (event) {
            event.preventDefault();
            var config = {
                url: document.getElementById('geoserver-url').value.trim(),
                layer: document.getElementById('geoserver-layer-name').value.trim(),
                name: document.getElementById('geoserver-display-name').value.trim()
            };
            if (!isValidGeoServerConfig(config)) {
                setGeoServerStatus('Masukkan URL WMS http:// atau https:// yang valid, nama layer, dan nama tampilan.', true);
                return;
            }
            try {
                addGeoServerLayer(config);
                var saved = persistGeoServerLayers();
                setGeoServerStatus(
                    saved
                        ? 'Layer berhasil ditambahkan ke peta dan akan dimuat lagi saat browser ini dibuka.'
                        : 'Layer sudah tampil di peta, tetapi tidak dapat disimpan untuk kunjungan berikutnya.',
                    !saved
                );
                setStatus('Memuat layer GeoServer "' + config.name + '"...', false);
            } catch (error) {
                setGeoServerStatus(error.message, true);
            }
        });
        loadGeoServerLayers();
        renderGeoServerLayerList();
        var activityPanel = document.getElementById('activity-panel');
        var activityStatus = document.getElementById('tracking-status');
        var activityHistory = document.getElementById('activity-history');
        var trackingLayer = L.featureGroup().addTo(map);
        var savedActivityLayer = null;
        var activityRecords = [];
        var trackPoints = [];
        var trackDistanceMeters = 0;
        var trackingState = 'idle';
        var watchId = null;
        var activeElapsedMilliseconds = 0;
        var activeSegmentStartedAt = null;
        var activeActivityType = 'Lari';
        var activeActivityName = '';
        var activityColors = ['#ea580c', '#7c3aed', '#0891b2', '#be123c', '#4d7c0f'];
        function setActivityStatus(message, isError) {
            activityStatus.textContent = message;
            activityStatus.classList.toggle('error-text', !!isError);
        }
        function formatDuration(seconds) {
            var total = Math.max(0, Math.floor(seconds));
            var hours = Math.floor(total / 3600);
            var minutes = Math.floor((total % 3600) / 60);
            var remainder = total % 60;
            return [hours, minutes, remainder].map(function (value) {
                return String(value).padStart(2, '0');
            }).join(':');
        }
        function getActiveElapsedMilliseconds() {
            return activeElapsedMilliseconds + (trackingState === 'recording' && activeSegmentStartedAt !== null
                ? Date.now() - activeSegmentStartedAt
                : 0);
        }
        function getTrackPace() {
            if (trackDistanceMeters < 10) {
                return '—';
            }
            var durationSeconds = getActiveElapsedMilliseconds() / 1000;
            if (activeActivityType === 'Bersepeda') {
                var speedKmh = trackDistanceMeters / durationSeconds * 3.6;
                return Number.isFinite(speedKmh) ? speedKmh.toFixed(1) + ' km/j' : '—';
            }
            var secondsPerKm = durationSeconds / (trackDistanceMeters / 1000);
            if (!Number.isFinite(secondsPerKm)) {
                return '—';
            }
            var minutes = Math.floor(secondsPerKm / 60);
            var seconds = Math.floor(secondsPerKm % 60);
            return minutes + ':' + String(seconds).padStart(2, '0') + ' /km';
        }
        function updateTrackStats() {
            document.getElementById('track-distance').textContent = formatDistance(trackDistanceMeters);
            var elapsedSeconds = getActiveElapsedMilliseconds() / 1000;
            var displaySeconds = trackingState === 'saving' || (trackingState === 'idle' && trackPoints.length >= 2)
                ? Math.ceil(elapsedSeconds)
                : Math.floor(elapsedSeconds);
            document.getElementById('track-duration').textContent = formatDuration(displaySeconds);
            document.getElementById('track-pace-label').textContent = activeActivityType === 'Bersepeda'
                ? 'Kecepatan rata-rata'
                : 'Pace';
            document.getElementById('track-pace').textContent = getTrackPace();
            document.getElementById('track-point-count').textContent = String(trackPoints.length);
        }
        function updateTrackingControls() {
            document.getElementById('start-tracking').hidden = trackingState !== 'idle';
            document.getElementById('pause-tracking').hidden = trackingState !== 'recording';
            document.getElementById('resume-tracking').hidden = trackingState !== 'paused';
            document.getElementById('finish-tracking').hidden = trackingState !== 'recording' && trackingState !== 'paused';
            document.getElementById('discard-tracking').hidden = trackingState !== 'recording' && trackingState !== 'paused';
            document.getElementById('finish-tracking').disabled = trackingState === 'saving';
            document.getElementById('activity-name').disabled = trackingState !== 'idle';
            document.getElementById('activity-type').disabled = trackingState !== 'idle';
        }
        function clearLocationWatch() {
            if (watchId !== null && navigator.geolocation) {
                navigator.geolocation.clearWatch(watchId);
                watchId = null;
            }
        }
        function pauseTrackingForGpsError(message) {
            if (trackingState === 'recording' && activeSegmentStartedAt !== null) {
                activeElapsedMilliseconds += Date.now() - activeSegmentStartedAt;
                activeSegmentStartedAt = null;
            }
            clearLocationWatch();
            trackingState = 'paused';
            updateTrackingControls();
            updateTrackStats();
            setActivityStatus(message, true);
        }
        function handleGpsPosition(position) {
            if (trackingState !== 'recording') {
                return;
            }
            if (position.coords.accuracy > 50) {
                setActivityStatus('Akurasi GPS ' + Math.round(position.coords.accuracy) + ' m; menunggu sinyal yang lebih baik.', false);
                return;
            }
            if (trackPoints.length >= 5000) {
                pauseTrackingForGpsError('Batas 5000 titik GPS tercapai. Selesaikan aktivitas untuk menyimpan rute ini.');
                return;
            }
            var current = L.latLng(position.coords.latitude, position.coords.longitude);
            var elapsedSeconds = Math.max(0, getActiveElapsedMilliseconds() / 1000);
            if (trackPoints.length) {
                var previous = trackPoints[trackPoints.length - 1];
                var previousLocation = L.latLng(previous.lat, previous.lng);
                var segmentDistance = previousLocation.distanceTo(current);
                if (segmentDistance < 2) {
                    updateTrackStats();
                    return;
                }
                trackDistanceMeters += segmentDistance;
            }
            trackPoints.push({lat: current.lat, lng: current.lng, t: elapsedSeconds});
            trackingLayer.clearLayers();
            L.polyline(trackPoints.map(function (point) {
                return [point.lat, point.lng];
            }), {color: '#ea580c', weight: 5, opacity: 0.9}).addTo(trackingLayer);
            if (trackPoints.length === 1) {
                map.setView(current, Math.max(map.getZoom(), 16));
                setActivityStatus('GPS terkunci. Bergerak untuk merekam jalur; aktivitas aktif.', false);
            } else {
                setActivityStatus('Merekam GPS · akurasi ' + Math.round(position.coords.accuracy) + ' m.', false);
            }
            updateTrackStats();
        }
        function startLocationWatch() {
            if (!navigator.geolocation) {
                trackingState = 'idle';
                updateTrackingControls();
                setActivityStatus('Browser ini tidak mendukung GPS/geolocation.', true);
                return;
            }
            activeSegmentStartedAt = Date.now();
            trackingState = 'recording';
            updateTrackingControls();
            setActivityStatus('Meminta akses lokasi. Pastikan izin lokasi perangkat diaktifkan.', false);
            watchId = navigator.geolocation.watchPosition(
                handleGpsPosition,
                function (error) {
                    var message = error.code === error.PERMISSION_DENIED
                        ? 'Izin lokasi ditolak. Izinkan akses lokasi lalu tekan Lanjutkan.'
                        : error.code === error.POSITION_UNAVAILABLE
                            ? 'Lokasi GPS tidak tersedia saat ini. Periksa layanan lokasi perangkat.'
                            : 'GPS tidak memberikan lokasi tepat waktu. Coba lanjutkan lagi.';
                    pauseTrackingForGpsError(message);
                },
                {enableHighAccuracy: true, maximumAge: 0, timeout: 20000}
            );
        }
        function renderActivityHistory() {
            activityHistory.replaceChildren();
            if (!activityRecords.length) {
                var empty = document.createElement('li');
                empty.textContent = 'Belum ada aktivitas tersimpan.';
                activityHistory.appendChild(empty);
                return;
            }
            activityRecords.forEach(function (activity) {
                var row = document.createElement('li');
                var description = document.createElement('div');
                var name = document.createElement('strong');
                name.textContent = activity.name;
                var details = document.createElement('span');
                var date = new Date(String(activity.created_at).replace(' ', 'T'));
                details.textContent = activity.type + ' · ' + formatDistance(activity.distance_meters)
                    + ' · ' + formatDuration(activity.duration_seconds)
                    + ' · ' + (Number.isNaN(date.getTime()) ? activity.created_at : date.toLocaleString());
                var pace = activity.distance_meters >= 10
                    ? activity.duration_seconds / (activity.distance_meters / 1000)
                    : 0;
                if (activity.type === 'Bersepeda' && activity.distance_meters > 0) {
                    details.textContent += ' · ' + (activity.distance_meters / activity.duration_seconds * 3.6).toFixed(1) + ' km/j';
                } else if (pace > 0 && activity.type !== 'Bersepeda') {
                    details.textContent += ' · ' + Math.floor(pace / 60) + ':' + String(Math.floor(pace % 60)).padStart(2, '0') + ' /km';
                }
                var show = document.createElement('button');
                show.type = 'button';
                show.className = 'button secondary';
                show.textContent = 'Tampilkan rute';
                show.addEventListener('click', function () {
                    if (savedActivityLayer) {
                        map.removeLayer(savedActivityLayer);
                    }
                    var color = activityColors[(activity.id - 1) % activityColors.length];
                    savedActivityLayer = L.geoJSON(activity.route, {
                        style: {color: color, weight: 5, opacity: 0.9}
                    }).addTo(map);
                    map.fitBounds(savedActivityLayer.getBounds().pad(0.12));
                });
                description.appendChild(name);
                description.appendChild(details);
                row.appendChild(description);
                row.appendChild(show);
                activityHistory.appendChild(row);
            });
        }
        function loadActivities() {
            fetch('activities.php').then(function (response) {
                return response.json().then(function (payload) {
                    if (!response.ok) {
                        throw new Error(payload.error || 'Riwayat aktivitas tidak dapat dimuat.');
                    }
                    if (!Array.isArray(payload.activities)) {
                        throw new Error('Daftar aktivitas dari server tidak valid.');
                    }
                    activityRecords = payload.activities;
                    renderActivityHistory();
                });
            }).catch(function (error) {
                activityHistory.replaceChildren();
                var failure = document.createElement('li');
                failure.textContent = 'Riwayat aktivitas gagal dimuat: ' + error.message;
                activityHistory.appendChild(failure);
            });
        }
        document.getElementById('activity-type').addEventListener('change', function () {
            activeActivityType = this.value;
            updateTrackStats();
        });
        document.getElementById('start-tracking').addEventListener('click', function () {
            trackPoints = [];
            trackDistanceMeters = 0;
            activeElapsedMilliseconds = 0;
            activeActivityType = document.getElementById('activity-type').value;
            activeActivityName = document.getElementById('activity-name').value.trim();
            trackingLayer.clearLayers();
            setToolMode(null);
            startLocationWatch();
            updateTrackStats();
        });
        document.getElementById('pause-tracking').addEventListener('click', function () {
            if (trackingState !== 'recording') {
                return;
            }
            activeElapsedMilliseconds += Date.now() - activeSegmentStartedAt;
            activeSegmentStartedAt = null;
            clearLocationWatch();
            trackingState = 'paused';
            updateTrackingControls();
            updateTrackStats();
            setActivityStatus('Tracking dijeda. Tekan Lanjutkan untuk merekam lagi.', false);
        });
        document.getElementById('resume-tracking').addEventListener('click', startLocationWatch);
        document.getElementById('discard-tracking').addEventListener('click', function () {
            if (!window.confirm('Buang aktivitas yang sedang direkam? Rute ini tidak akan disimpan.')) {
                return;
            }
            clearLocationWatch();
            trackingState = 'idle';
            trackPoints = [];
            trackDistanceMeters = 0;
            activeElapsedMilliseconds = 0;
            activeSegmentStartedAt = null;
            trackingLayer.clearLayers();
            updateTrackingControls();
            updateTrackStats();
            setActivityStatus('Aktivitas dibuang dan tidak disimpan.', false);
        });
        document.getElementById('finish-tracking').addEventListener('click', async function () {
            if (trackPoints.length < 2) {
                setActivityStatus('Belum cukup titik GPS untuk menyimpan rute. Bergeraklah sampai minimal dua titik terekam.', true);
                return;
            }
            if (trackingState === 'recording' && activeSegmentStartedAt !== null) {
                activeElapsedMilliseconds += Date.now() - activeSegmentStartedAt;
                activeSegmentStartedAt = null;
            }
            clearLocationWatch();
            trackingState = 'saving';
            updateTrackingControls();
            updateTrackStats();
            setActivityStatus('Menghitung statistik dan menyimpan aktivitas ke database...', false);
            try {
                var response = await fetch('activities.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({
                        name: activeActivityName,
                        type: activeActivityType,
                        duration_seconds: Math.max(1, Math.ceil(activeElapsedMilliseconds / 1000)),
                        points: trackPoints
                    })
                });
                var result = await response.json();
                if (!response.ok) {
                    throw new Error(result.error || 'Aktivitas gagal disimpan.');
                }
                trackingState = 'idle';
                updateTrackingControls();
                setActivityStatus(
                    result.message + ' Jarak ' + formatDistance(result.distance_meters)
                    + ' dalam ' + formatDuration(result.duration_seconds) + '.',
                    false
                );
                loadActivities();
            } catch (error) {
                trackingState = 'paused';
                updateTrackingControls();
                setActivityStatus('Penyimpanan gagal: ' + error.message + ' Rute masih tersimpan sementara di halaman; coba Selesai & Simpan lagi.', true);
            }
        });
        setInterval(function () {
            if (trackingState === 'recording') {
                updateTrackStats();
            }
        }, 1000);
        updateTrackingControls();
        updateTrackStats();
        renderActivityHistory();
        loadActivities();
        document.getElementById('tracking-tool').addEventListener('click', function () {
            activityPanel.hidden = false;
            activityPanel.scrollIntoView({behavior: 'smooth', block: 'start'});
            document.getElementById('start-tracking').focus({preventScroll: true});
        });
        function saveDrawings() {
            try {
                localStorage.setItem(storageKey, JSON.stringify(drawnItems.toGeoJSON()));
                setStatus('Titik: ' + points.length + ' · Poligon/objek tersimpan di browser ini.', false);
            } catch (error) {
                setStatus('Objek berhasil dibuat, tetapi tidak dapat disimpan di browser.', true);
            }
        }
        try {
            var savedFeatures = localStorage.getItem(storageKey);
            if (savedFeatures) {
                var savedGeoJson = JSON.parse(savedFeatures);
                if (savedGeoJson.type === 'FeatureCollection' && Array.isArray(savedGeoJson.features)) {
                    savedGeoJson.features.forEach(function (feature) {
                        L.geoJSON(feature).eachLayer(function (layer) {
                            drawnItems.addLayer(layer);
                        });
                    });
                } else {
                    throw new Error('Format data poligon tidak valid.');
                }
            }
        } catch (error) {
            setStatus('Data poligon tersimpan tidak dapat dibaca. Buat objek baru untuk menyimpannya.', true);
        }

        if (L.Control.Draw) {
            map.addControl(new L.Control.Draw({
                position: 'topleft',
                draw: {
                    polyline: false,
                    polygon: {allowIntersection: false, showArea: true},
                    rectangle: true,
                    circle: false,
                    circlemarker: false,
                    marker: false
                },
                edit: {featureGroup: drawnItems, remove: true}
            }));
            map.on(L.Draw.Event.CREATED, function (event) {
                drawnItems.addLayer(event.layer);
                saveDrawings();
            });
            map.on(L.Draw.Event.EDITED, saveDrawings);
            map.on(L.Draw.Event.DELETED, saveDrawings);
        } else {
            setStatus('Kontrol gambar poligon gagal dimuat. Periksa koneksi internet.', true);
        }

        var toolMode = null;
        var measurePoints = [];
        var measureGroup = L.featureGroup().addTo(map);
        var routeGroup = L.featureGroup().addTo(map);
        function setToolMode(mode) {
            toolMode = mode;
            document.getElementById('measure-tool').classList.toggle('tool-active', mode === 'measure');
            document.getElementById('route-tool').classList.toggle('tool-active', mode === 'route');
            document.getElementById('waypoint-tool').classList.toggle('tool-active', mode === 'waypoint');
            map.getContainer().style.cursor = mode ? 'crosshair' : '';
        }
        function formatDistance(meters) {
            return meters >= 1000
                ? (meters / 1000).toFixed(2) + ' km'
                : Math.round(meters) + ' m';
        }
        map.on('click', function (event) {
            if (toolMode === 'waypoint') {
                pendingWaypointLocation = event.latlng;
                document.getElementById('waypoint-coordinates').textContent = 'Koordinat: '
                    + event.latlng.lat.toFixed(6) + ', ' + event.latlng.lng.toFixed(6);
                document.getElementById('waypoint-name').value = '';
                document.getElementById('waypoint-notes').value = '';
                waypointStatus.textContent = '';
                setToolMode(null);
                waypointModal.showModal();
                document.getElementById('waypoint-name').focus();
                return;
            }
            if (toolMode === 'measure') {
                measurePoints.push(event.latlng);
                L.circleMarker(event.latlng, {
                    radius: 5, color: '#0f766e', fillColor: '#0f766e', fillOpacity: 1
                }).addTo(measureGroup);
                if (measurePoints.length === 2) {
                    var distance = map.distance(measurePoints[0], measurePoints[1]);
                    L.polyline(measurePoints, {color: '#0f766e', weight: 3, dashArray: '7 6'}).addTo(measureGroup);
                    setStatus('Jarak garis lurus: ' + formatDistance(distance) + '. Klik Ukur Jarak untuk mengukur ulang.', false);
                    setToolMode(null);
                } else {
                    setStatus('Pilih titik kedua untuk mengukur jarak.', false);
                }
                return;
            }
            if (toolMode === 'route') {
                if (!measurePoints.length || measurePoints.length >= 2) {
                    measurePoints = [];
                }
                measurePoints.push(event.latlng);
                L.circleMarker(event.latlng, {
                    radius: 7,
                    color: measurePoints.length === 1 ? '#15803d' : '#b91c1c',
                    fillColor: measurePoints.length === 1 ? '#22c55e' : '#ef4444',
                    fillOpacity: 1
                }).addTo(routeGroup);
                if (measurePoints.length === 1) {
                    setStatus('Titik awal dipilih. Klik tujuan untuk menghitung rute berkendara dengan OSRM.', false);
                    return;
                }

                var start = measurePoints[0];
                var destination = measurePoints[1];
                measurePoints = [];
                setToolMode(null);
                setStatus('Mencari rute berkendara terbaik...', false);
                var routeUrl = 'https://router.project-osrm.org/route/v1/driving/'
                    + start.lng + ',' + start.lat + ';' + destination.lng + ',' + destination.lat
                    + '?overview=full&geometries=geojson&steps=false';
                fetch(routeUrl).then(function (response) {
                    if (!response.ok) {
                        throw new Error('Layanan rute tidak merespons (HTTP ' + response.status + ').');
                    }
                    return response.json();
                }).then(function (result) {
                    if (result.code !== 'Ok' || !result.routes || !result.routes.length) {
                        throw new Error('Rute tidak ditemukan. Coba pilih titik lain.');
                    }
                    var route = result.routes[0];
                    L.geoJSON(route.geometry, {
                        style: {color: '#dc2626', weight: 5, opacity: 0.85},
                        attribution: 'Rute &copy; OpenStreetMap contributors via OSRM'
                    }).addTo(routeGroup);
                    map.fitBounds(routeGroup.getBounds().pad(0.12));
                    var durationMinutes = Math.round(route.duration / 60);
                    setStatus(
                        'Rute berkendara: ' + formatDistance(route.distance)
                        + ' · perkiraan ' + durationMinutes + ' menit (OSRM).',
                        false
                    );
                }).catch(function (error) {
                    setStatus('Pencarian rute gagal: ' + error.message, true);
                });
            }
        });

        document.getElementById('measure-tool').addEventListener('click', function () {
            measureGroup.clearLayers();
            measurePoints = [];
            setToolMode(toolMode === 'measure' ? null : 'measure');
            setStatus(toolMode === 'measure'
                ? 'Klik dua titik pada peta untuk mengukur jarak garis lurus.'
                : 'Pengukuran jarak dibatalkan.', false);
        });
        document.getElementById('route-tool').addEventListener('click', function () {
            measurePoints = [];
            routeGroup.clearLayers();
            var startRoute = toolMode !== 'route';
            setToolMode(startRoute ? 'route' : null);
            setStatus(startRoute
                ? 'Pilih titik awal rute dengan klik pada peta. OSRM publik menghitung rute berkendara tanpa data lalu lintas langsung.'
                : 'Pencarian rute dibatalkan.', false);
        });
        document.getElementById('waypoint-tool').addEventListener('click', function () {
            var startWaypoint = toolMode !== 'waypoint';
            setToolMode(startWaypoint ? 'waypoint' : null);
            setStatus(startWaypoint
                ? 'Klik lokasi pada peta untuk menambahkan waypoint.'
                : 'Penambahan waypoint dibatalkan.', false);
        });
        document.getElementById('close-waypoint').addEventListener('click', function () {
            waypointModal.close();
            pendingWaypointLocation = null;
        });
        document.getElementById('waypoint-gps').addEventListener('click', function () {
            if (!navigator.geolocation) {
                waypointStatus.textContent = 'Perangkat ini tidak mendukung GPS/geolocation.';
                waypointStatus.className = 'modal-status error-text';
                return;
            }
            var gpsButton = this;
            gpsButton.disabled = true;
            navigator.geolocation.getCurrentPosition(function (position) {
                pendingWaypointLocation = L.latLng(position.coords.latitude, position.coords.longitude);
                document.getElementById('waypoint-coordinates').textContent = 'Koordinat GPS: '
                    + pendingWaypointLocation.lat.toFixed(6) + ', ' + pendingWaypointLocation.lng.toFixed(6)
                    + ' · akurasi ' + Math.round(position.coords.accuracy) + ' m';
                waypointStatus.textContent = '';
                gpsButton.disabled = false;
            }, function (error) {
                waypointStatus.textContent = 'Lokasi GPS gagal: ' + error.message;
                waypointStatus.className = 'modal-status error-text';
                gpsButton.disabled = false;
            }, {enableHighAccuracy: true, timeout: 20000, maximumAge: 0});
        });
        document.getElementById('waypoint-form').addEventListener('submit', async function (event) {
            event.preventDefault();
            if (!pendingWaypointLocation) {
                waypointStatus.textContent = 'Pilih lokasi waypoint pada peta terlebih dahulu.';
                waypointStatus.className = 'modal-status error-text';
                return;
            }
            var saveButton = document.getElementById('save-waypoint');
            saveButton.disabled = true;
            waypointStatus.textContent = 'Menyimpan waypoint...';
            waypointStatus.className = 'modal-status';
            try {
                var response = await fetch('waypoints.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({
                        name: document.getElementById('waypoint-name').value.trim(),
                        notes: document.getElementById('waypoint-notes').value.trim(),
                        latitude: pendingWaypointLocation.lat,
                        longitude: pendingWaypointLocation.lng
                    })
                });
                var payload = await response.json();
                if (!response.ok || !payload.waypoint) {
                    throw new Error(payload.error || 'Waypoint gagal disimpan.');
                }
                waypointRecords.unshift(payload.waypoint);
                renderWaypoints();
                waypointModal.close();
                setStatus(payload.message + ' (' + payload.waypoint.name + ')', false);
                pendingWaypointLocation = null;
            } catch (error) {
                waypointStatus.textContent = error.message;
                waypointStatus.className = 'modal-status error-text';
            } finally {
                saveButton.disabled = false;
            }
        });

        function parseCsv(text) {
            var firstLine = text.split(/\r?\n/, 1)[0] || '';
            var delimiter = ',';
            var delimiterCounts = [',', ';', '\t'].map(function (candidate) {
                return {delimiter: candidate, count: firstLine.split(candidate).length - 1};
            }).sort(function (a, b) { return b.count - a.count; });
            if (delimiterCounts[0].count > 0) {
                delimiter = delimiterCounts[0].delimiter;
            }
            var rows = [];
            var row = [];
            var field = '';
            var quoted = false;
            for (var index = 0; index < text.length; index += 1) {
                var character = text[index];
                if (character === '"') {
                    if (quoted && text[index + 1] === '"') {
                        field += '"';
                        index += 1;
                    } else {
                        quoted = !quoted;
                    }
                } else if (character === delimiter && !quoted) {
                    row.push(field);
                    field = '';
                } else if ((character === '\n' || character === '\r') && !quoted) {
                    if (character === '\r' && text[index + 1] === '\n') {
                        index += 1;
                    }
                    row.push(field);
                    if (row.some(function (value) { return value.trim() !== ''; })) {
                        rows.push(row);
                    }
                    row = [];
                    field = '';
                } else {
                    field += character;
                }
            }
            row.push(field);
            if (row.some(function (value) { return value.trim() !== ''; })) {
                rows.push(row);
            }
            return rows;
        }
        function parseCsvGeoJson(text) {
            var rows = parseCsv(text);
            if (rows.length < 2) {
                throw new Error('CSV harus memiliki baris header dan setidaknya satu baris data.');
            }
            var headers = rows[0].map(function (header) { return header.replace(/^\uFEFF/, '').trim(); });
            var normalizedHeaders = headers.map(function (header) {
                return header.toLowerCase().replace(/[^a-z0-9]/g, '');
            });
            function findColumn(candidates) {
                for (var index = 0; index < candidates.length; index += 1) {
                    var found = normalizedHeaders.indexOf(candidates[index]);
                    if (found !== -1) {
                        return found;
                    }
                }
                return -1;
            }
            var latitudeIndex = findColumn(['latitude', 'lat', 'lintang', 'y']);
            var longitudeIndex = findColumn(['longitude', 'longtitude', 'long', 'lon', 'lng', 'bujur', 'x']);
            if (latitudeIndex === -1 || longitudeIndex === -1) {
                throw new Error('Kolom koordinat tidak ditemukan. Gunakan latitude/longitude, lat/lon, atau x/y.');
            }
            var features = [];
            rows.slice(1).forEach(function (values) {
                var rawLatitude = (values[latitudeIndex] || '').trim();
                var rawLongitude = (values[longitudeIndex] || '').trim();
                if (!rawLatitude || !rawLongitude) {
                    return;
                }
                var latitudeText = rawLatitude.replace(',', '.');
                var longitudeText = rawLongitude.replace(',', '.');
                var latitude = Number(latitudeText);
                var longitude = Number(longitudeText);
                if (!Number.isFinite(latitude) || !Number.isFinite(longitude)
                    || latitude < -90 || latitude > 90 || longitude < -180 || longitude > 180) {
                    return;
                }
                var properties = {};
                headers.forEach(function (header, columnIndex) {
                    properties[header || ('kolom_' + (columnIndex + 1))] = values[columnIndex] || '';
                });
                features.push({
                    type: 'Feature',
                    properties: properties,
                    geometry: {type: 'Point', coordinates: [longitude, latitude]}
                });
            });
            if (!features.length) {
                throw new Error('Tidak ada baris CSV dengan koordinat latitude/longitude yang valid.');
            }
            return {type: 'FeatureCollection', features: features};
        }
        function parseXmlGeoJson(xmlText, format) {
            if (!window.toGeoJSON) {
                throw new Error('Pustaka konversi KML/GPX gagal dimuat. Periksa koneksi internet.');
            }
            var documentXml = new DOMParser().parseFromString(xmlText, 'application/xml');
            if (documentXml.querySelector('parsererror')) {
                throw new Error('File KML/GPX tidak berisi XML yang valid.');
            }
            var parsed = format === 'gpx'
                ? window.toGeoJSON.gpx(documentXml)
                : window.toGeoJSON.kml(documentXml);
            return normalizeGeoJson(parsed);
        }
        async function readImportFile(file) {
            var extension = file.name.split('.').pop().toLowerCase();
            if (extension === 'csv') {
                return parseCsvGeoJson(await file.text());
            }
            if (extension === 'zip') {
                if (typeof window.shp !== 'function') {
                    throw new Error('Pustaka shapefile gagal dimuat. Periksa koneksi internet.');
                }
                return normalizeGeoJson(await window.shp(await file.arrayBuffer()));
            }
            if (extension === 'kml' || extension === 'gpx') {
                return parseXmlGeoJson(await file.text(), extension);
            }
            if (extension === 'kmz') {
                if (!window.JSZip) {
                    throw new Error('Pustaka KMZ gagal dimuat. Periksa koneksi internet.');
                }
                var archive = await window.JSZip.loadAsync(await file.arrayBuffer());
                var kmlFiles = Object.keys(archive.files).filter(function (name) {
                    return /\.kml$/i.test(name) && !archive.files[name].dir;
                });
                if (!kmlFiles.length) {
                    throw new Error('Arsip KMZ tidak berisi file KML.');
                }
                return parseXmlGeoJson(await archive.files[kmlFiles[0]].async('text'), 'kml');
            }
            if (extension === 'geojson' || extension === 'json') {
                return normalizeGeoJson(JSON.parse(await file.text()));
            }
            throw new Error('Format vektor tidak didukung. Pilih GeoJSON, CSV, ZIP shapefile, KML, KMZ, atau GPX.');
        }
        function parseGeoTiff(file) {
            var parseRaster = window.parseGeoraster || window.GeoRaster;
            if (typeof parseRaster !== 'function' || typeof window.GeoRasterLayer !== 'function') {
                throw new Error('Pustaka GeoTIFF gagal dimuat. Periksa koneksi internet.');
            }
            return parseRaster(file);
        }
        function formatFileSize(size) {
            return size >= 1024 * 1024
                ? (size / (1024 * 1024)).toFixed(1) + ' MB'
                : Math.max(1, Math.round(size / 1024)) + ' KB';
        }
        function renderRasters(rasters) {
            rasterList.replaceChildren();
            if (!rasters.length) {
                var empty = document.createElement('li');
                empty.textContent = 'Belum ada GeoTIFF tersimpan.';
                rasterList.appendChild(empty);
                return;
            }
            rasters.forEach(function (raster) {
                var row = document.createElement('li');
                var details = document.createElement('span');
                details.textContent = raster.name + ' · ' + formatFileSize(raster.file_size);
                var actions = document.createElement('span');
                var display = document.createElement('button');
                display.type = 'button';
                display.className = 'button secondary';
                display.textContent = raster.layer && map.hasLayer(raster.layer) ? 'Sembunyikan' : 'Tampilkan';
                display.addEventListener('click', async function () {
                    display.disabled = true;
                    try {
                        if (!raster.layer) {
                            display.textContent = 'Memuat...';
                            var response = await fetch('rasters.php?action=file&id=' + encodeURIComponent(raster.id));
                            if (!response.ok) {
                                var failure = await response.json();
                                throw new Error(failure.error || 'GeoTIFF tidak dapat dimuat.');
                            }
                            var georaster = await parseGeoTiff(await response.arrayBuffer());
                            raster.layer = new window.GeoRasterLayer({
                                georaster: georaster,
                                opacity: 0.75,
                                resolution: 256
                            });
                            layerControl.addOverlay(raster.layer, escapeHtml(raster.name) + ' (GeoTIFF)');
                        }
                        if (map.hasLayer(raster.layer)) {
                            map.removeLayer(raster.layer);
                        } else {
                            raster.layer.addTo(map);
                            var bounds = raster.layer.getBounds();
                            if (bounds && bounds.isValid()) {
                                map.fitBounds(bounds, {maxZoom: 16});
                            }
                        }
                        display.textContent = map.hasLayer(raster.layer) ? 'Sembunyikan' : 'Tampilkan';
                    } catch (error) {
                        display.textContent = 'Tampilkan';
                        setStatus('GeoTIFF gagal ditampilkan: ' + error.message, true);
                    } finally {
                        display.disabled = false;
                    }
                });
                var remove = document.createElement('button');
                remove.type = 'button';
                remove.className = 'button danger';
                remove.textContent = 'Hapus';
                remove.addEventListener('click', async function () {
                    if (!window.confirm('Hapus GeoTIFF "' + raster.name + '" dari penyimpanan?')) {
                        return;
                    }
                    remove.disabled = true;
                    try {
                        var response = await fetch('rasters.php?action=delete', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify({id: raster.id})
                        });
                        var result = await response.json();
                        if (!response.ok) {
                            throw new Error(result.error || 'GeoTIFF gagal dihapus.');
                        }
                        if (raster.layer) {
                            map.removeLayer(raster.layer);
                            layerControl.removeLayer(raster.layer);
                        }
                        loadRasters();
                        setStatus(result.message, false);
                    } catch (error) {
                        remove.disabled = false;
                        setStatus(error.message, true);
                    }
                });
                actions.appendChild(display);
                actions.appendChild(remove);
                row.appendChild(details);
                row.appendChild(actions);
                rasterList.appendChild(row);
            });
        }
        function loadRasters() {
            fetch('rasters.php').then(function (response) {
                return response.json().then(function (payload) {
                    if (!response.ok || !Array.isArray(payload.rasters)) {
                        throw new Error(payload.error || 'Daftar GeoTIFF tidak valid.');
                    }
                    var loadedById = {};
                    rasterLayers.forEach(function (raster) {
                        loadedById[raster.id] = raster;
                    });
                    rasterLayers = payload.rasters.map(function (raster) {
                        if (loadedById[raster.id]) {
                            loadedById[raster.id].file_size = raster.file_size;
                            return loadedById[raster.id];
                        }
                        return raster;
                    });
                    renderRasters(rasterLayers);
                });
            }).catch(function (error) {
                rasterList.replaceChildren();
                var failure = document.createElement('li');
                failure.textContent = 'GeoTIFF gagal dimuat: ' + error.message;
                rasterList.appendChild(failure);
            });
        }
        var importModal = document.getElementById('import-modal');
        var importForm = document.getElementById('import-form');
        var importStatus = document.getElementById('import-status');
        var saveImportButton = document.getElementById('save-import');
        document.getElementById('import-tool').addEventListener('click', function () {
            importModal.showModal();
        });
        document.getElementById('close-import').addEventListener('click', function () {
            importModal.close();
        });
        document.getElementById('layer-file').addEventListener('change', function (event) {
            var file = event.target.files && event.target.files[0];
            if (file && !document.getElementById('layer-name').value) {
                document.getElementById('layer-name').value = file.name.replace(/\.[^.]+$/, '');
            }
        });
        importForm.addEventListener('submit', async function (event) {
            event.preventDefault();
            var file = document.getElementById('layer-file').files[0];
            var name = document.getElementById('layer-name').value.trim();
            if (!file || !name) {
                importStatus.textContent = 'Nama layer dan file wajib dipilih.';
                importStatus.className = 'modal-status error-text';
                return;
            }
            var extension = file.name.split('.').pop().toLowerCase();
            var isRaster = extension === 'tif' || extension === 'tiff';
            var sizeLimit = isRaster ? 25 * 1024 * 1024 : 5 * 1024 * 1024;
            if (file.size > sizeLimit) {
                importStatus.textContent = isRaster
                    ? 'Ukuran GeoTIFF maksimal 25 MB.'
                    : 'Ukuran file maksimal 5 MB.';
                importStatus.className = 'modal-status error-text';
                return;
            }
            saveImportButton.disabled = true;
            importStatus.textContent = 'Membaca dan memvalidasi data...';
            importStatus.className = 'modal-status';
            try {
                if (isRaster) {
                    var georaster = await parseGeoTiff(file);
                    var rasterLayer = new window.GeoRasterLayer({
                        georaster: georaster,
                        opacity: 0.75,
                        resolution: 256
                    });
                    var rasterResponse = await fetch(
                        'rasters.php?action=upload&name=' + encodeURIComponent(name)
                        + '&filename=' + encodeURIComponent(file.name),
                        {
                            method: 'POST',
                            headers: {'Content-Type': 'application/octet-stream'},
                            body: file
                        }
                    );
                    var rasterResult = await rasterResponse.json();
                    if (!rasterResponse.ok || !rasterResult.raster) {
                        throw new Error(rasterResult.error || 'GeoTIFF gagal disimpan.');
                    }
                    var rasterInfo = Object.assign(rasterResult.raster, {layer: rasterLayer});
                    rasterLayers.unshift(rasterInfo);
                    layerControl.addOverlay(rasterLayer, escapeHtml(name) + ' (GeoTIFF)');
                    rasterLayer.addTo(map);
                    renderRasters(rasterLayers);
                    var rasterBounds = rasterLayer.getBounds();
                    if (rasterBounds && rasterBounds.isValid()) {
                        map.fitBounds(rasterBounds, {maxZoom: 16});
                    }
                    importStatus.textContent = rasterResult.message;
                    importStatus.className = 'modal-status success-text';
                    document.getElementById('layer-file').value = '';
                    document.getElementById('layer-name').value = '';
                    setStatus('GeoTIFF "' + name + '" berhasil disimpan dan ditampilkan pada peta.', false);
                    return;
                }
                var geojson = await readImportFile(file);
                var encoded = JSON.stringify(geojson);
                if (new Blob([encoded]).size > 5 * 1024 * 1024) {
                    throw new Error('Ukuran GeoJSON hasil konversi melebihi 5 MB.');
                }
                var response = await fetch('layers.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({name: name, geojson: geojson})
                });
                var result = await response.json();
                if (!response.ok) {
                    throw new Error(result.error || 'Layer gagal disimpan.');
                }
                addImportedLayer({
                    id: result.id,
                    name: name,
                    geojson: geojson
                });
                importStatus.textContent = result.message + ' (' + geojson.features.length + ' fitur).';
                importStatus.className = 'modal-status success-text';
                document.getElementById('layer-file').value = '';
                document.getElementById('layer-name').value = '';
                setStatus('Layer "' + name + '" berhasil disimpan dan ditampilkan pada peta.', false);
            } catch (error) {
                importStatus.textContent = error.message;
                importStatus.className = 'modal-status error-text';
            } finally {
                saveImportButton.disabled = false;
            }
        });
        loadImportedLayers();
        loadRasters();

        function toggleMapLegend() {
            var legend = document.getElementById('map-legend');
            var isExpanded = legend.hidden;
            legend.hidden = !isExpanded;
            document.getElementById('legend-tool').setAttribute('aria-expanded', String(isExpanded));
            document.getElementById('map-legend-toggle').setAttribute('aria-expanded', String(isExpanded));
        }
        document.getElementById('legend-tool').addEventListener('click', toggleMapLegend);
        var profileModal = document.getElementById('profile-modal');
        document.getElementById('profile-tool').addEventListener('click', function () {
            profileModal.showModal();
        });
        document.getElementById('close-profile').addEventListener('click', function () {
            profileModal.close();
        });

        var topbar = document.querySelector('.topbar');
        var menuToggle = document.getElementById('menu-toggle');
        var navItems = document.getElementById('nav-items');
        function setMenuOpen(isOpen) {
            topbar.classList.toggle('menu-open', isOpen);
            menuToggle.setAttribute('aria-expanded', String(isOpen));
            menuToggle.setAttribute('aria-label', isOpen ? 'Tutup menu' : 'Buka menu');
        }
        function updateResponsiveNav() {
            var originalStyle = navItems.getAttribute('style');
            var itemStyles = [];
            Array.prototype.forEach.call(navItems.children, function (item) {
                itemStyles.push(item.getAttribute('style'));
                item.style.width = 'auto';
                item.style.minWidth = 'max-content';
            });
            navItems.style.position = 'absolute';
            navItems.style.left = '-10000px';
            navItems.style.right = 'auto';
            navItems.style.top = '0';
            navItems.style.display = 'flex';
            navItems.style.flexDirection = 'row';
            navItems.style.width = 'max-content';
            navItems.style.maxWidth = 'none';
            navItems.style.visibility = 'hidden';
            var menuWidth = navItems.scrollWidth;
            if (originalStyle === null) {
                navItems.removeAttribute('style');
            } else {
                navItems.setAttribute('style', originalStyle);
            }
            Array.prototype.forEach.call(navItems.children, function (item, index) {
                if (itemStyles[index] === null) {
                    item.removeAttribute('style');
                } else {
                    item.setAttribute('style', itemStyles[index]);
                }
            });

            var styles = window.getComputedStyle(topbar);
            var horizontalPadding = parseFloat(styles.paddingLeft) + parseFloat(styles.paddingRight);
            var gap = parseFloat(styles.columnGap) || 0;
            var availableWidth = topbar.clientWidth - horizontalPadding - topbar.querySelector('.brand').getBoundingClientRect().width - gap;
            var shouldCollapse = menuWidth > availableWidth;
            if (topbar.classList.contains('is-collapsed') !== shouldCollapse) {
                topbar.classList.toggle('is-collapsed', shouldCollapse);
                setMenuOpen(false);
            }
        }
        menuToggle.addEventListener('click', function () {
            setMenuOpen(!topbar.classList.contains('menu-open'));
        });
        navItems.addEventListener('click', function (event) {
            if (event.target.closest('a, button')) {
                setMenuOpen(false);
            }
        });
        document.addEventListener('click', function (event) {
            if (topbar.classList.contains('menu-open') && !topbar.contains(event.target)) {
                setMenuOpen(false);
            }
        });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && topbar.classList.contains('menu-open')) {
                setMenuOpen(false);
                menuToggle.focus();
            }
        });
        window.addEventListener('resize', updateResponsiveNav);
        if (window.ResizeObserver) {
            new ResizeObserver(updateResponsiveNav).observe(topbar);
        }
        updateResponsiveNav();

        if (points.length > 0) {
            map.fitBounds(pointGroup.getBounds().pad(0.15), {maxZoom: 15});
            setStatus('Titik: ' + points.length + ' · Poligon/objek tersimpan di browser ini.', false);
        } else if (!<?php echo $latitudeColumn && $longitudeColumn ? 'true' : 'false'; ?>) {
            setStatus('Kolom koordinat latitude/longitude belum ditemukan. Tambahkan kedua kolom agar titik tampil.', true);
        } else {
            setStatus('Belum ada titik berkoordinat. Gunakan “Tambah Data” untuk memasukkan data.', false);
        }

    }());
</script>
</body>
</html>
