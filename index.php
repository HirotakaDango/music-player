<?php
if (isset($_GET['pwa'])) {
  if ($_GET['pwa'] == 'manifest') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
      "name" => "PHP Music Player",
      "short_name" => "Music",
      "start_url" => ".",
      "display" => "standalone",
      "background_color" => "#030303",
      "theme_color" => "#121212",
      "description" => "A simple, fast music player.",
      "icons" => [
        [
          "src" => "https://icons.getbootstrap.com/assets/icons/vinyl-fill.svg",
          "sizes" => "192x192",
          "type" => "image/svg+xml",
          "purpose" => "any maskable"
        ],
        [
          "src" => "https://icons.getbootstrap.com/assets/icons/vinyl-fill.svg",
          "sizes" => "512x512",
          "type" => "image/svg+xml",
          "purpose" => "any maskable"
        ]
      ]
    ]);
    exit;
  }
  if ($_GET['pwa'] == 'sw') {
    header('Content-Type: application/javascript; charset=utf-8');
    echo <<<SW
    const CACHE_NAME = 'php-music-cache-v2';
    const URLS_TO_CACHE = [
      './',
      'https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css',
      'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css',
      'https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js'
    ];

    self.addEventListener('install', event => {
      event.waitUntil(caches.open(CACHE_NAME).then(cache => cache.addAll(URLS_TO_CACHE)));
    });

    self.addEventListener('fetch', event => {
      const url = new URL(event.request.url);
      if (url.search.includes('action=get_stream')) {
        event.respondWith(fetch(event.request));
        return;
      }
      event.respondWith(
        caches.match(event.request).then(response => {
          if (response) return response;
          return fetch(event.request).then(networkResponse => {
            if (url.search.includes('action=get_')) {
               const cacheableResponse = networkResponse.clone();
               caches.open(CACHE_NAME).then(cache => cache.put(event.request, cacheableResponse));
            }
            return networkResponse;
          });
        })
      );
    });

    self.addEventListener('activate', event => {
      const cacheWhitelist = [CACHE_NAME];
      event.waitUntil(
        caches.keys().then(cacheNames => {
          return Promise.all(
            cacheNames.map(cacheName => {
              if (cacheWhitelist.indexOf(cacheName) === -1) {
                return caches.delete(cacheName);
              }
            })
          );
        })
      );
    });
    SW;
    exit;
  }
}

header('Content-Type: text/html; charset=utf-8');
session_start();
set_time_limit(0);

define('MUSIC_DIR', __DIR__);
define('DB_FILE', __DIR__ . '/music.db');
define('PAGE_SIZE', 25);

if (file_exists(__DIR__ . '/getid3/getid3.php')) {
  require_once __DIR__ . '/getid3/getid3.php';
}

function get_db() {
  try {
    $db = new PDO('sqlite:' . DB_FILE);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    return $db;
  } catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
  }
}

function init_db($db) {
  $db->exec("
    CREATE TABLE IF NOT EXISTS music (
      id INTEGER PRIMARY KEY,
      file TEXT UNIQUE,
      title TEXT,
      artist TEXT,
      album TEXT,
      year INTEGER,
      duration INTEGER,
      image BLOB
    );
  ");
  $db->exec("CREATE INDEX IF NOT EXISTS artist_idx ON music(artist);");
  $db->exec("CREATE INDEX IF NOT EXISTS album_idx ON music(album);");
  $db->exec("CREATE INDEX IF NOT EXISTS year_idx ON music(year);");
}

function process_image_to_webp($imageData) {
  if (!$imageData || !function_exists('imagecreatefromstring') || !function_exists('imagewebp')) {
    return null;
  }
  $sourceImage = @imagecreatefromstring($imageData);
  if (!$sourceImage) { return null; }
  $originalWidth = imagesx($sourceImage);
  $originalHeight = imagesy($sourceImage);
  $maxWidth = 500; $maxHeight = 500;
  $newWidth = $originalWidth; $newHeight = $originalHeight;
  if ($originalWidth > $maxWidth || $originalHeight > $maxHeight) {
    $ratio = $originalWidth / $originalHeight;
    if ($ratio > 1) {
      $newWidth = $maxWidth; $newHeight = $maxWidth / $ratio;
    } else {
      $newHeight = $maxHeight; $newWidth = $maxHeight * $ratio;
    }
  }
  $resizedImage = imagecreatetruecolor((int)$newWidth, (int)$newHeight);
  imagealphablending($resizedImage, false);
  imagesavealpha($resizedImage, true);
  imagecopyresampled($resizedImage, $sourceImage, 0, 0, 0, 0, (int)$newWidth, (int)$newHeight, $originalWidth, $originalHeight);
  ob_start();
  imagewebp($resizedImage, null, 75);
  $webpData = ob_get_clean();
  imagedestroy($sourceImage);
  imagedestroy($resizedImage);
  return $webpData;
}

if (isset($_GET['action'])) {
  $action = $_GET['action'];
  $db = get_db();
  init_db($db);
  header('Content-Type: application/json; charset=utf-8');

  $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
  $offset = ($page - 1) * PAGE_SIZE;
  $limit_clause = " LIMIT " . PAGE_SIZE . " OFFSET " . $offset;

  switch ($action) {
    case 'scan':
      echo json_encode(['status' => 'starting']);
      session_write_close();
      ob_flush(); flush();
      scan_music_directory($db);
      break;

    case 'scan_status':
      echo json_encode(['status' => $_SESSION['scan_status'] ?? 'idle', 'message' => $_SESSION['scan_message'] ?? '']);
      break;

    case 'get_songs':
      $sort_key = $_GET['sort'] ?? 'artist_asc';
      $sort_map = [
        'artist_asc' => 'ORDER BY artist ASC, album ASC, title ASC',
        'title_asc' => 'ORDER BY title ASC',
        'album_asc' => 'ORDER BY album ASC, title ASC',
        'year_desc' => 'ORDER BY year DESC, album ASC, title ASC',
        'year_asc' => 'ORDER BY year ASC, album ASC, title ASC'
      ];
      $order_by = $sort_map[$sort_key] ?? $sort_map['artist_asc'];
      $stmt = $db->query("SELECT id, title, artist, album, duration FROM music " . $order_by . $limit_clause);
      echo json_encode($stmt->fetchAll());
      break;

    case 'get_favorites':
      $post_data = json_decode(file_get_contents('php://input'), true);
      $ids = $post_data['ids'] ?? [];
      if (empty($ids)) {
        echo json_encode([]);
        exit;
      }
      $placeholders = implode(',', array_fill(0, count($ids), '?'));
      $stmt = $db->prepare("SELECT id, title, artist, album, duration FROM music WHERE id IN ($placeholders) " . $limit_clause);
      $stmt->execute($ids);
      echo json_encode($stmt->fetchAll());
      break;

    case 'get_view_ids':
      $post_data = json_decode(file_get_contents('php://input'), true);
      $view_type = $post_data['view_type'] ?? '';
      $param = $post_data['param'] ?? '';
      $sort = $post_data['sort'] ?? '';
      $ids = $post_data['ids'] ?? [];

      $sql = "SELECT id FROM music ";
      $conditions = "";
      $params = [];
      $default_sort = 'artist_asc';

      switch ($view_type) {
        case 'songs': break;
        case 'favorites':
          if (empty($ids)) { echo json_encode([]); exit; }
          $placeholders = implode(',', array_fill(0, count($ids), '?'));
          $conditions = "WHERE id IN ($placeholders)";
          $params = $ids;
          break;
        case 'artist_songs':
          $conditions = "WHERE artist = ?";
          $params[] = $param;
          $default_sort = 'album_asc';
          break;
        case 'search':
          $conditions = "WHERE title LIKE ? OR artist LIKE ? OR album LIKE ?";
          $query_param = '%' . $param . '%';
          $params = [$query_param, $query_param, $query_param];
          break;
        default:
          echo json_encode([]); exit;
      }

      $sort_map = [
        'artist_asc' => 'ORDER BY artist ASC, album ASC, title ASC',
        'title_asc' => 'ORDER BY title ASC',
        'album_asc' => 'ORDER BY album ASC, title ASC',
        'year_desc' => 'ORDER BY year DESC, album ASC, title ASC',
        'year_asc' => 'ORDER BY year ASC, album ASC, title ASC'
      ];
      $order_by = $sort_map[$sort] ?? $sort_map[$default_sort];

      $stmt = $db->prepare($sql . $conditions . " " . $order_by);
      $stmt->execute($params);
      echo json_encode($stmt->fetchAll(PDO::FETCH_COLUMN));
      exit;

    case 'get_artists':
      $stmt = $db->query("SELECT DISTINCT artist FROM music WHERE artist != '' AND artist IS NOT NULL ORDER BY artist " . $limit_clause);
      echo json_encode($stmt->fetchAll(PDO::FETCH_COLUMN));
      break;

    case 'get_albums':
      $stmt = $db->query("SELECT album, artist, id FROM (SELECT album, artist, id, ROW_NUMBER() OVER (PARTITION BY album ORDER BY RANDOM()) as rn FROM music WHERE album != '' AND album IS NOT NULL) WHERE rn = 1 ORDER BY album" . $limit_clause);
      echo json_encode($stmt->fetchAll());
      break;

    case 'get_by_artist':
      $artist = $_GET['name'] ?? '';
      $sort_key = $_GET['sort'] ?? 'album_asc';
      $sort_map = [
        'album_asc' => 'ORDER BY album ASC, title ASC',
        'title_asc' => 'ORDER BY title ASC',
        'year_desc' => 'ORDER BY year DESC, album ASC, title ASC',
        'year_asc' => 'ORDER BY year ASC, album ASC, title ASC'
      ];
      $order_by = $sort_map[$sort_key] ?? $sort_map['album_asc'];
      $stmt = $db->prepare("SELECT id, title, artist, album, duration FROM music WHERE artist = ? " . $order_by . $limit_clause);
      $stmt->execute([$artist]);
      echo json_encode($stmt->fetchAll());
      break;

    case 'get_by_album':
      $album = $_GET['name'] ?? '';
      $stmt = $db->prepare("SELECT id, title, artist, album, duration FROM music WHERE album = ? ORDER BY id " . $limit_clause);
      $stmt->execute([$album]);
      echo json_encode($stmt->fetchAll());
      break;

    case 'search':
      $query = '%' . ($_GET['q'] ?? '') . '%';
      $stmt = $db->prepare("SELECT id, title, artist, album, duration FROM music WHERE title LIKE ? OR artist LIKE ? OR album LIKE ? " . $limit_clause);
      $stmt->execute([$query, $query, $query]);
      echo json_encode($stmt->fetchAll());
      break;

    case 'get_song_data':
      $id = intval($_GET['id'] ?? 0);
      $stmt = $db->prepare("SELECT id, file, title, artist, album, duration FROM music WHERE id = ?");
      $stmt->execute([$id]);
      $song = $stmt->fetch();
      if ($song) {
        $song['stream_url'] = '?action=get_stream&id=' . $song['id'];
        $song['image_url'] = '?action=get_image&id=' . $song['id'];
      }
      echo json_encode($song);
      break;

    case 'get_stream':
      header_remove('Content-Type');
      $id = intval($_GET['id'] ?? 0);
      $stmt = $db->prepare("SELECT file FROM music WHERE id = ?");
      $stmt->execute([$id]);
      $file_path = $stmt->fetchColumn();
      if ($file_path && file_exists($file_path)) {
        header('Content-Type: audio/mpeg');
        header('Content-Length: ' . filesize($file_path));
        header('Accept-Ranges: bytes');
        readfile($file_path);
      } else {
        http_response_code(404);
      }
      break;

    case 'get_image':
      header_remove('Content-Type');
      $id = intval($_GET['id'] ?? 0);
      $stmt = $db->prepare("SELECT image FROM music WHERE id = ?");
      $stmt->execute([$id]);
      $stmt->bindColumn(1, $image_data, PDO::PARAM_LOB);
      $result = $stmt->fetch(PDO::FETCH_BOUND);
      if ($result && $image_data) {
        header('Content-Type: image/webp');
        fpassthru($image_data);
      } else {
        header('Content-Type: image/svg+xml');
        echo '<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100" fill="#404040" class="bi bi-music-note" viewBox="0 0 16 16"><path d="M9 13c0 1.105-1.12 2-2.5 2S4 14.105 4 13s1.12-2 2.5-2 2.5.895 2.5 2"/><path fill-rule="evenodd" d="M9 3v10H8V3h1z"/><path d="M8 2.82a1 1 0 0 1 .804-.98l3-.6A1 1 0 0 1 13 2.22V4L8 5V2.82z"/></svg>';
      }
      break;
  }
  exit;
}

function scan_music_directory($db) {
  if (!class_exists('getID3')) {
    $_SESSION['scan_status'] = 'error';
    $_SESSION['scan_message'] = 'getID3 library not found.';
    return;
  }
  $_SESSION['scan_status'] = 'scanning';
  $_SESSION['scan_message'] = 'Starting scan...';
  session_write_close();
  $getID3 = new getID3;
  $directory = new RecursiveDirectoryIterator(MUSIC_DIR, FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS);
  $iterator = new RecursiveIteratorIterator($directory);
  $musicFiles = new RegexIterator($iterator, '/\.(mp3|m4a|flac|ogg|wav)$/i');
  $files_to_process = iterator_to_array($musicFiles);
  $total = count($files_to_process);
  $stmt = $db->prepare("INSERT OR REPLACE INTO music (id, file, title, artist, album, year, duration, image) VALUES ((SELECT id FROM music WHERE file = ?), ?, ?, ?, ?, ?, ?, ?)");
  $count = 0;
  foreach ($files_to_process as $file) {
    session_start();
    $filePath = $file->getPathname();
    $_SESSION['scan_message'] = "Processing " . ($count + 1) . " of $total: " . basename($filePath);
    session_write_close();
    try {
      $info = $getID3->analyze($filePath);
      getid3_lib::CopyTagsToComments($info);
      $title = $info['comments_html']['title'][0] ?? pathinfo($filePath, PATHINFO_FILENAME);
      $artist = $info['comments_html']['artist'][0] ?? 'Unknown Artist';
      $album = $info['comments_html']['album'][0] ?? 'Unknown Album';
      $year = (int)($info['comments_html']['year'][0] ?? 0);
      $duration = (int)($info['playtime_seconds'] ?? 0);
      $raw_image_data = isset($info['comments']['picture'][0]['data']) ? $info['comments']['picture'][0]['data'] : null;
      $webp_image_data = process_image_to_webp($raw_image_data);
      $db->beginTransaction();
      $stmt->execute([
        $filePath, $filePath,
        trim(html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8')),
        trim(html_entity_decode($artist, ENT_QUOTES | ENT_HTML5, 'UTF-8')),
        trim(html_entity_decode($album, ENT_QUOTES | ENT_HTML5, 'UTF-8')),
        $year, $duration, $webp_image_data
      ]);
      $db->commit();
    } catch (Exception $e) {
      if ($db->inTransaction()) { $db->rollBack(); }
    }
    $count++;
  }
  session_start();
  $_SESSION['scan_status'] = 'finished';
  $_SESSION['scan_message'] = "Scan complete. Processed $count files.";
  session_write_close();
}
?>
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PHP Music Player</title>
    <link rel="icon" type="image/svg+xml" href="https://icons.getbootstrap.com/assets/icons/code-slash.svg" />
    <meta name="theme-color" content="#121212"/>
    <link rel="manifest" href="?pwa=manifest">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
    <style>
      :root {
        --ytm-bg: #030303;
        --ytm-surface: #121212;
        --ytm-surface-2: #282828;
        --ytm-primary-text: #ffffff;
        --ytm-secondary-text: #aaaaaa;
        --ytm-accent: #ff0000;
        --header-height-mobile: 64px;
      }
      html, body {
        height: 100%;
      }
      body {
        background-color: var(--ytm-bg);
        color: var(--ytm-primary-text);
        font-family: 'Roboto', sans-serif;
        margin: 0;
      }
      body.player-visible {
        padding-bottom: 90px;
      }
      ::-webkit-scrollbar { width: 8px; }
      ::-webkit-scrollbar-track { background: var(--ytm-surface); }
      ::-webkit-scrollbar-thumb { background: var(--ytm-surface-2); border-radius: 4px;}
      ::-webkit-scrollbar-thumb:hover { background: #555; }
      .app-container {
        display: flex;
        height: 100%;
      }
      .sidebar {
        width: 240px;
        background-color: var(--ytm-bg);
        display: flex;
        flex-direction: column;
        flex-shrink: 0;
        z-index: 1045;
      }
      .main-content {
        flex-grow: 1;
        display: flex;
        flex-direction: column;
        overflow-y: auto;
      }
      .content-area-wrapper {
        padding: 1.5rem 2rem;
        flex-grow: 1;
      }
      @media (min-width: 768px) {
        .sidebar {
          padding: 1.5rem 0;
        }
        .sidebar .offcanvas-header {
          display: none;
        }
        .sidebar .offcanvas-body {
          padding: 0 !important;
        }
      }
      .offcanvas-body .nav-link {
        padding: 0.75rem 1.5rem;
      }
      .sidebar .logo {
        font-size: 1.5rem;
        font-weight: 700;
        padding: 0 1.5rem 1.5rem 1.5rem;
      }
      .sidebar .logo span {
        color: var(--ytm-accent);
      }
      .nav-link {
        color: var(--ytm-secondary-text);
        display: flex;
        align-items: center;
        font-weight: 500;
        border-left: 3px solid transparent;
        gap: 1rem;
        text-decoration: none;
      }
      .nav-link:hover, .nav-link.active {
        background-color: var(--ytm-surface);
        color: var(--ytm-primary-text);
      }
      .nav-link.active {
        border-left-color: var(--ytm-accent);
      }
      .nav-link .bi {
        font-size: 1.25rem;
        width: 24px;
        text-align: center;
      }
      .scan-status {
        margin-top: auto;
        padding: 1rem 1.5rem;
        font-size: 0.8rem;
        color: var(--ytm-secondary-text);
      }
      .scan-status .progress {
        height: 4px;
        margin-top: 5px;
      }
      .offcanvas {
        background-color: var(--ytm-bg);
        color: var(--ytm-primary-text);
      }
      .offcanvas .offcanvas-header {
        padding: 0.75rem 1.5rem;
      }
      .page-header {
        padding: 1.5rem 2rem 0rem 2rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
      }
      .header-controls {
        display: flex;
        gap: 1rem;
        align-items: center;
      }
      #sort-controls {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        margin-left: auto;
      }
      #sort-select {
        background-color: var(--ytm-surface-2);
        color: var(--ytm-primary-text);
        border: 1px solid #404040;
        border-radius: 4px;
        padding: 0.25rem 0.5rem;
      }
      .search-input-wrapper {
        position: relative;
      }
      .search-input-wrapper input {
        background-color: var(--ytm-surface-2);
        border: 1px solid #404040;
        border-radius: 50px;
        color: var(--ytm-primary-text);
        padding: 0.5rem 1rem 0.5rem 2.5rem;
        width: 100%;
        height: 40px;
      }
      .search-input-wrapper input::placeholder {
        color: var(--ytm-secondary-text);
      }
      .search-input-wrapper .bi-search {
        position: absolute;
        left: 1rem;
        top: 50%;
        transform: translateY(-50%);
        color: var(--ytm-secondary-text);
      }
      .content-title {
        font-size: 2rem;
        font-weight: 700;
        margin-bottom: 1.5rem;
      }
      .song-list-header, .song-item {
        display: grid;
        grid-template-columns: 40px minmax(0, 4fr) minmax(0, 3fr) minmax(0, 3fr) 80px 40px;
        align-items: center;
        gap: 1rem;
        padding: 0.5rem 1rem;
        font-size: 0.9rem;
        color: var(--ytm-secondary-text);
        border-bottom: 1px solid var(--ytm-surface-2);
      }
      .song-list-header {
        font-weight: 500;
      }
      .song-item {
        cursor: pointer;
        border-radius: 4px;
      }
      .song-item:hover {
        background-color: var(--ytm-surface-2);
      }
      .song-item .song-title,
      .song-item .song-artist,
      .song-item .song-album,
      .song-artist-name {
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
      }
      .song-item .song-title {
        color: var(--ytm-primary-text);
        font-weight: 500;
      }
      .song-item .song-thumb {
        width: 40px;
        height: 40px;
        object-fit: cover;
        border-radius: 4px;
        background-color: var(--ytm-surface);
      }
      .song-item .song-more {
        justify-self: end;
        position: relative;
      }
      .song-item .more-btn {
        background: none;
        border: none;
        color: var(--ytm-secondary-text);
        padding: 5px;
        cursor: pointer;
        border-radius: 50%;
      }
      .song-item:hover .more-btn {
        color: var(--ytm-primary-text);
      }
      .context-menu {
        display: none;
        position: fixed;
        background-color: var(--ytm-surface-2);
        border-radius: 4px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.5);
        z-index: 1050;
        list-style: none;
        padding: 0.5rem 0;
        min-width: 220px;
      }
      .context-menu-item {
        padding: 0.75rem 1.25rem;
        color: var(--ytm-primary-text);
        cursor: pointer;
        display: flex;
        align-items: center;
        gap: 0.75rem;
      }
      .context-menu-item:hover {
        background-color: #404040;
      }
      .context-menu-item .bi {
        font-size: 1.1rem;
      }
      .player-bar {
        position: fixed;
        bottom: 0;
        left: 0;
        right: 0;
        height: 90px;
        background-color: var(--ytm-bg);
        border-top: 1px solid var(--ytm-surface-2);
        display: grid;
        grid-template-columns: minmax(200px, 1fr) 2fr minmax(200px, 1fr);
        align-items: center;
        gap: 1.5rem;
        padding: 0 1.5rem;
        z-index: 1000;
      }
      .player-bar .track-info {
        display: flex;
        align-items: center;
        gap: 1rem;
        cursor: pointer;
        min-width: 0;
      }
      .player-bar .track-info-art {
        width: 56px;
        height: 56px;
        object-fit: cover;
        border-radius: 4px;
        flex-shrink: 0;
      }
      .player-bar .track-info-text {
        overflow: hidden;
      }
      .player-bar .track-info-text .title,
      .player-bar .track-info-text .artist {
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
      }
      .player-bar .track-info-text .title {
        font-weight: 500;
      }
      .player-bar .track-info-text .artist {
        color: var(--ytm-secondary-text);
        font-size: 0.875rem;
      }
      .player-bar .player-controls {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        min-width: 0;
      }
      .player-bar .player-buttons {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 1.5rem;
        width: 100%;
      }
      .player-btn {
        background: none;
        border: none;
        color: var(--ytm-secondary-text);
        padding: 0;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        transition: color 0.2s;
      }
      .player-btn:hover {
        color: var(--ytm-primary-text);
      }
      .player-btn.play-btn {
        color: var(--ytm-primary-text);
        background-color: var(--ytm-surface);
        width: 40px;
        height: 40px;
        border-radius: 50%;
        transition: transform 0.1s, background-color 0.2s;
      }
      .player-btn.play-btn:hover {
        transform: scale(1.1);
        background-color: #383838;
      }
      .player-btn .bi {
        font-size: 1.25rem;
      }
      .player-btn.play-btn .bi {
        font-size: 1.75rem;
      }
      .player-btn.active {
        color: var(--ytm-accent);
      }
      .player-bar .playback-bar {
        width: 100%;
        display: flex;
        align-items: center;
        gap: 0.75rem;
        margin-top: 8px;
      }
      .playback-bar .time {
        font-size: 0.75rem;
        color: var(--ytm-secondary-text);
        flex-shrink: 0;
      }
      .progress-bar-container {
        flex-grow: 1;
        height: 4px;
        border-radius: 2px;
        cursor: pointer;
        padding: 5px 0;
        position: relative;
      }
      .progress-bar-bg {
        height: 4px;
        background-color: #404040;
        border-radius: 2px;
        position: absolute;
        top: 5px;
        left: 0;
        right: 0;
        pointer-events: none;
      }
      .progress-bar-fg {
        height: 4px;
        background-color: var(--ytm-primary-text);
        border-radius: 2px;
        width: 0%;
        position: relative;
      }
      .progress-bar-container:hover .progress-bar-fg {
        background-color: var(--ytm-accent);
      }
      .progress-bar-container:hover .progress-bar-fg::after {
        content: '';
        position: absolute;
        right: -6px;
        top: -4px;
        width: 12px;
        height: 12px;
        border-radius: 50%;
        background-color: var(--ytm-primary-text);
      }
      .player-bar .extra-controls {
        display: flex;
        justify-content: flex-end;
        align-items: center;
        gap: 1rem;
      }
      @media (max-width: 767.98px) {
        body.player-visible {
          padding-bottom: 130px;
        }
        .main-content {
          padding-top: var(--header-height-mobile);
        }
        .content-area-wrapper {
          padding: 1rem;
        }
        .mobile-header {
          position: fixed;
          top: 0; left: 0; right: 0;
          height: var(--header-height-mobile);
          background-color: var(--ytm-bg);
          border-bottom: 1px solid var(--ytm-surface-2);
          z-index: 1000;
          display: flex;
          align-items: center;
          padding: 0 1rem;
          gap: 0.5rem;
        }
        .header-btn {
          background: none; border: none; color: var(--ytm-primary-text);
          font-size: 1.5rem; padding: 0.5rem;
        }
        .page-header {
          padding: 1rem 1rem 0 1rem;
          display: flex;
          flex-wrap: wrap;
        }
        #sort-controls {
          margin-left: 0;
          margin-top: 0.5rem;
          width: 100%;
          justify-content: flex-end;
        }
        .content-title {
          font-size: 1.75rem;
        }
        .player-bar {
          grid-template-columns: 1fr;
          display: flex;
          flex-direction: column;
          height: 130px;
          padding: 0.5rem 1rem;
          margin-bottom: 1rem;
          gap: 0;
        }
        .player-bar .track-info { order: 1; width: 100%;}
        .player-bar .player-controls { display: contents; }
        .player-bar .playback-bar { order: 2; width: 100%; margin-top: 8px; }
        .player-bar .player-buttons-mobile {
          order: 3;
          width: 100%;
          display: flex;
          justify-content: space-between;
          align-items: center;
          margin-top: 4px;
          margin-bottom: 8px;
        }
        .player-bar .player-buttons { display: none; }
        .player-bar .extra-controls { display: none; }
        .player-bar .track-info-art { width: 48px; height: 48px; }
        .player-btn.play-btn { width: 52px; height: 52px; }
        .player-btn .bi { font-size: 1.5rem; }
        .player-btn.play-btn .bi { font-size: 2.25rem; }
        .song-list-header {
          display: none;
        }
        .song-item {
          grid-template-columns: 40px minmax(0, 1fr) 40px;
          grid-template-rows: auto auto;
          padding: 0.75rem 0.5rem;
        }
        .song-item .song-album, .song-item .song-artist, .song-item .song-duration {
          display: none;
        }
        .song-item .song-thumb {
          grid-row: 1 / span 2;
        }
        .song-item .song-title-wrapper {
          grid-column: 2; grid-row: 1;
        }
        .song-item .song-artist-mobile {
          display: flex !important;
          justify-content: space-between;
          align-items: center;
          grid-column: 2;
          grid-row: 2;
          font-size: 0.8rem;
          color: var(--ytm-secondary-text);
          gap: 1rem;
        }
        .song-duration-mobile {
          display: block !important;
          flex-shrink: 0;
        }
        .song-item .song-more {
          grid-column: 3;
          grid-row: 1 / span 2;
        }
      }
      .loader {
        text-align: center;
        padding: 3rem;
        font-size: 1.2rem;
        color: var(--ytm-secondary-text);
      }
    </style>
  </head>
  <body>
    <div class="app-container">
      <nav class="sidebar offcanvas-md offcanvas-start" tabindex="-1" id="main-nav-offcanvas">
        <div class="offcanvas-header">
          <div class="logo">PHP<span>Music</span></div>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" data-bs-target="#main-nav-offcanvas" aria-label="Close"></button>
        </div>
        <div class="offcanvas-body d-flex flex-column">
          <div class="logo d-none d-md-block">PHP<span>Music</span></div>
          <a href="#" class="nav-link active" data-view="songs">
            <i class="bi bi-music-note-list"></i>
            <span>All Songs</span>
          </a>
          <a href="#" class="nav-link" data-view="favorites">
            <i class="bi bi-heart-fill"></i>
            <span>Favorites</span>
          </a>
          <a href="#" class="nav-link" data-view="albums">
            <i class="bi bi-disc-fill"></i>
            <span>Albums</span>
          </a>
          <a href="#" class="nav-link" data-view="artists">
            <i class="bi bi-people-fill"></i>
            <span>Artists</span>
          </a>
          <a href="#" class="nav-link" id="scan-btn">
            <i class="bi bi-arrow-repeat"></i>
            <span>Scan Library</span>
          </a>
          <a href="#" class="nav-link d-none" id="install-pwa-btn">
            <i class="bi bi-cloud-arrow-down-fill"></i>
            <span>Install App</span>
          </a>
          <hr class="text-secondary">
          <a href="#" class="nav-link" id="import-btn">
            <i class="bi bi-box-arrow-in-down"></i>
            <span>Import Favorites</span>
          </a>
          <a href="#" class="nav-link" id="export-btn">
            <i class="bi bi-box-arrow-up"></i>
            <span>Export Favorites</span>
          </a>
          <input type="file" id="import-file-input" class="d-none" accept=".json">
          <div class="scan-status">
            <p id="scan-status-text" class="badge bg-primary fw-bold">Ready.</p>
            <div class="progress d-none" id="scan-progress-bar-container">
              <div class="progress-bar progress-bar-striped progress-bar-animated bg-danger" id="scan-progress-bar" role="progressbar" style="width: 100%"></div>
            </div>
          </div>
        </div>
      </nav>
      <main class="main-content" id="main-content">
        <div class="mobile-header d-md-none">
          <button class="header-btn" type="button" data-bs-toggle="offcanvas" data-bs-target="#main-nav-offcanvas" aria-controls="main-nav-offcanvas">
            <i class="bi bi-list"></i>
          </button>
          <div class="search-input-wrapper flex-grow-1">
            <i class="bi bi-search"></i>
            <input type="text" class="form-control" id="search-input-mobile" placeholder="Search your music">
          </div>
        </div>
        <div class="page-header">
          <h1 id="content-title" class="content-title">Home</h1>
          <div class="header-controls d-none d-md-flex">
            <div class="search-bar">
              <div class="search-input-wrapper">
                <i class="bi bi-search"></i>
                <input type="text" class="form-control" id="search-input-desktop" placeholder="Search songs, albums, artists">
              </div>
            </div>
            <div id="sort-controls" class="d-none">
              <label for="sort-select" class="text-secondary small">Sort by</label>
              <select id="sort-select" class="form-select form-select-sm" style="width: auto;"></select>
            </div>
          </div>
        </div>
        <div id="content-area" class="content-area-wrapper"></div>
        <div id="infinite-scroll-loader" class="loader d-none">Loading more...</div>
      </main>
    </div>
    <div class="player-bar d-none" id="player-bar">
      <div class="track-info d-none d-md-flex">
        <img src="" alt="Album Art" class="track-info-art" id="player-art-desktop">
        <div class="track-info-text">
          <div class="title" id="player-title-desktop">Song Title</div>
          <div class="artist" id="player-artist-desktop">Artist Name</div>
        </div>
      </div>
      <div class="player-controls">
        <div class="track-info d-md-none">
          <img src="" alt="Album Art" class="track-info-art" id="player-art-mobile">
          <div class="track-info-text">
            <div class="title" id="player-title-mobile">Song Title</div>
            <div class="artist" id="player-artist-mobile">Artist Name</div>
          </div>
        </div>
        <div class="playback-bar">
          <span class="time" id="current-time">0:00</span>
          <div class="progress-bar-container" id="progress-container">
            <div class="progress-bar-bg"></div>
            <div class="progress-bar-fg" id="progress-bar"></div>
          </div>
          <span class="time" id="time-left">0:00</span>
        </div>
        <div class="player-buttons d-none d-md-flex">
          <button class="player-btn" id="shuffle-btn-desktop" title="Shuffle"></button>
          <button class="player-btn" id="prev-btn-desktop" title="Previous"></button>
          <button class="player-btn play-btn" id="play-pause-btn-desktop" title="Play"></button>
          <button class="player-btn" id="next-btn-desktop" title="Next"></button>
          <button class="player-btn" id="repeat-btn-desktop" title="Repeat"></button>
        </div>
         <div class="player-buttons-mobile d-md-none">
          <button class="player-btn" id="shuffle-btn-mobile" title="Shuffle"></button>
          <button class="player-btn" id="prev-btn-mobile" title="Previous"></button>
          <button class="player-btn play-btn" id="play-pause-btn-mobile" title="Play"></button>
          <button class="player-btn" id="next-btn-mobile" title="Next"></button>
          <button class="player-btn" id="repeat-btn-mobile" title="Repeat"></button>
        </div>
      </div>
      <div class="extra-controls d-none d-md-flex">
         <button class="player-btn" id="favorite-btn-desktop" title="Favorite"></button>
      </div>
    </div>
    <ul class="context-menu" id="context-menu"></ul>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
      document.addEventListener('DOMContentLoaded', () => {
        'use strict';
        const mainContent = document.getElementById('main-content');
        const contentArea = document.getElementById('content-area');
        const contentTitle = document.getElementById('content-title');
        const searchInputDesktop = document.getElementById('search-input-desktop');
        const searchInputMobile = document.getElementById('search-input-mobile');
        const sortControls = document.getElementById('sort-controls');
        const sortSelect = document.getElementById('sort-select');
        const navLinks = document.querySelectorAll('.nav-link');
        const scanBtn = document.getElementById('scan-btn');
        const scanStatusText = document.getElementById('scan-status-text');
        const scanProgressBar = document.getElementById('scan-progress-bar-container');
        const contextMenu = document.getElementById('context-menu');
        const playerBar = document.getElementById('player-bar');
        const progressContainer = document.getElementById('progress-container');
        const progressBar = document.getElementById('progress-bar');
        const currentTimeEl = document.getElementById('current-time');
        const timeLeftEl = document.getElementById('time-left');
        const importBtn = document.getElementById('import-btn');
        const exportBtn = document.getElementById('export-btn');
        const importFileInput = document.getElementById('import-file-input');
        const infiniteScrollLoader = document.getElementById('infinite-scroll-loader');
        const installPwaBtn = document.getElementById('install-pwa-btn');

        const playerArtDesktop = document.getElementById('player-art-desktop');
        const playerTitleDesktop = document.getElementById('player-title-desktop');
        const playerArtistDesktop = document.getElementById('player-artist-desktop');
        const playerArtMobile = document.getElementById('player-art-mobile');
        const playerTitleMobile = document.getElementById('player-title-mobile');
        const playerArtistMobile = document.getElementById('player-artist-mobile');
        
        const playPauseBtnDesktop = document.getElementById('play-pause-btn-desktop');
        const playPauseBtnMobile = document.getElementById('play-pause-btn-mobile');
        const prevBtnDesktop = document.getElementById('prev-btn-desktop');
        const nextBtnDesktop = document.getElementById('next-btn-desktop');
        const shuffleBtnDesktop = document.getElementById('shuffle-btn-desktop');
        const repeatBtnDesktop = document.getElementById('repeat-btn-desktop');
        const favoriteBtnDesktop = document.getElementById('favorite-btn-desktop');
        
        const prevBtnMobile = document.getElementById('prev-btn-mobile');
        const nextBtnMobile = document.getElementById('next-btn-mobile');
        const shuffleBtnMobile = document.getElementById('shuffle-btn-mobile');
        const repeatBtnMobile = document.getElementById('repeat-btn-mobile');

        const audio = new Audio();
        let currentView = { type: 'songs', param: '', sort: 'artist_asc' };
        let favorites = [];
        let currentSong = null;
        let queue = [];
        let originalQueue = [];
        let queueIndex = -1;
        let isPlaying = false;
        let isShuffle = false;
        let repeatMode = 'none';
        let scanInterval;
        let deferredInstallPrompt = null;
        
        let currentPage = 1;
        let isLoadingMore = false;
        let allSongsLoaded = false;

        const ICONS = {
          play: `<i class="bi bi-play-fill"></i>`,
          pause: `<i class="bi bi-pause-fill"></i>`,
          repeat: `<i class="bi bi-repeat"></i>`,
          repeatOne: `<i class="bi bi-repeat-1"></i>`,
          shuffle: `<i class="bi bi-shuffle"></i>`,
          prev: `<i class="bi bi-skip-start-fill"></i>`,
          next: `<i class="bi bi-skip-end-fill"></i>`,
          heart: `<i class="bi bi-heart"></i>`,
          heartFill: `<i class="bi bi-heart-fill"></i>`
        };

        const formatTime = (seconds) => {
          if (isNaN(seconds)) return '0:00';
          const min = Math.floor(seconds / 60);
          const sec = Math.floor(seconds % 60).toString().padStart(2, '0');
          return `${min}:${sec}`;
        };

        const fetchData = async (url, options = {}) => {
          try {
            const response = await fetch(url, options);
            if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
            return await response.json();
          } catch (error) {
            console.error("Failed to load data from " + url, error);
            contentArea.innerHTML = `<div class="alert alert-danger mx-3">Failed to load data. Please check server logs and console.</div>`;
            return null;
          }
        };
        
        const showLoader = () => {
          contentArea.innerHTML = `<div class="loader">Loading...</div>`;
        };
        
        const updateContentTitle = (text) => {
          try {
            const decodedText = decodeURIComponent(text.replace(/\+/g, ' '));
            contentTitle.textContent = decodedText;
            document.title = decodedText + ' - PHP Music';
          } catch (e) {
            contentTitle.textContent = text;
            document.title = text + ' - PHP Music';
          }
        };

        const loadFavorites = () => {
          favorites = JSON.parse(localStorage.getItem('phpMusicFavorites')) || [];
        };
        const saveFavorites = () => {
          localStorage.setItem('phpMusicFavorites', JSON.stringify(favorites));
        };
        const isFavorite = (songId) => favorites.includes(songId);
        const toggleFavorite = (songId) => {
          if (isFavorite(songId)) {
            favorites = favorites.filter(id => id !== songId);
          } else {
            favorites.push(songId);
          }
          saveFavorites();
          updateFavoriteIcons(songId);
        };
        const updateFavoriteIcons = (songId) => {
          const isFav = isFavorite(songId);
          const icon = isFav ? ICONS.heartFill : ICONS.heart;
          if (currentSong && currentSong.id === songId) {
            favoriteBtnDesktop.innerHTML = icon;
            favoriteBtnDesktop.classList.toggle('active', isFav);
          }
        };
        
        const renderSongs = (songs, append = false) => {
          if (!append) contentArea.innerHTML = '';
          if (!songs || songs.length === 0) {
            if (!append) {
              contentArea.innerHTML = `<div class="text-center p-5 text-secondary">No songs found.</div>`;
            }
            allSongsLoaded = true;
            infiniteScrollLoader.classList.add('d-none');
            return;
          }

          let songList = contentArea.querySelector('.song-list');
          if (!songList) {
            songList = document.createElement('div');
            songList.className = 'song-list';
            songList.innerHTML = `<div class="song-list-header d-none d-md-grid">
              <div>#</div><div>Title</div><div>Artist</div><div>Album</div><div>Time</div><div></div>
            </div>`;
            contentArea.appendChild(songList);
          }

          const songsHTML = songs.map((song) => `
            <div class="song-item" data-song-id="${song.id}">
              <img src="?action=get_image&id=${song.id}" class="song-thumb" loading="lazy" alt="${song.title}">
              <div class="song-title-wrapper"><div class="song-title">${song.title}</div></div>
              <div class="song-artist" data-artist="${encodeURIComponent(song.artist)}">${song.artist}</div>
              <div class="song-album" data-album="${encodeURIComponent(song.album)}">${song.album}</div>
              <div class="song-duration d-none d-md-block">${formatTime(song.duration)}</div>
              <div class="song-more">
                <button class="more-btn" data-song-id="${song.id}" data-artist="${encodeURIComponent(song.artist)}" data-album="${encodeURIComponent(song.album)}">
                  <i class="bi bi-three-dots-vertical"></i>
                </button>
              </div>
              <div class="song-artist-mobile d-md-none">
                <span class="song-artist-name">${song.artist}</span>
                <span class="song-duration-mobile">${formatTime(song.duration)}</span>
              </div>
            </div>
          `).join('');
          
          songList.insertAdjacentHTML('beforeend', songsHTML);
        };

        const renderGrid = (items, type) => {
          if (!items || items.length === 0) {
            return `<div class="text-center p-5 text-secondary">No ${type}s found.</div>`;
          }
          return `<div class="row row-cols-2 row-cols-sm-3 row-cols-md-4 row-cols-lg-5 row-cols-xl-6 g-4">
            ${items.map(item => {
              const name = type === 'artists' ? item : item.album;
              const subtext = type === 'albums' ? item.artist : null;
              const imageId = type === 'albums' ? item.id : 0;
              const dataType = type === 'artists' ? 'artist' : 'album';
              return `<div class="col">
                <div class="card h-100 bg-transparent text-white border-0" data-${dataType}="${encodeURIComponent(name)}">
                  <img src="?action=get_image&id=${imageId}" class="card-img-top rounded" alt="${name}" style="aspect-ratio: 1/1; object-fit: cover; background-color: var(--ytm-surface-2);">
                  <div class="card-body px-0 py-2">
                    <h5 class="card-title fs-6 fw-normal text-truncate">${name}</h5>
                    ${subtext ? `<p class="card-text small text-secondary text-truncate">${subtext}</p>` : ''}
                  </div>
                </div>
              </div>`;
            }).join('')}
          </div>`;
        };
        
        const setupSortOptions = (viewType) => {
          sortControls.classList.add('d-none');
          let options = {};
          if (['songs', 'favorites', 'artist_songs'].includes(viewType)) {
            options = {
              'artist_asc': 'Artist', 'title_asc': 'Title', 'album_asc': 'Album',
              'year_desc': 'Year (Newest)', 'year_asc': 'Year (Oldest)',
            };
          }
          if (viewType === 'artist_songs') {
             delete options.artist_asc;
          }
          if (Object.keys(options).length > 0) {
            sortSelect.innerHTML = Object.entries(options)
              .map(([value, text]) => `<option value="${value}" ${currentView.sort === value ? 'selected' : ''}>${text}</option>`).join('');
            sortControls.classList.remove('d-none');
          }
        };

        const loadMoreSongs = async () => {
          if (isLoadingMore || allSongsLoaded) return;
          isLoadingMore = true;
          infiniteScrollLoader.classList.remove('d-none');
          
          currentPage++;
          let url;
          let options = {};
          const {type, param, sort} = currentView;

          switch (type) {
            case 'songs':
              url = `?action=get_songs&sort=${sort}&page=${currentPage}`;
              break;
            case 'favorites':
              url = `?action=get_favorites&page=${currentPage}`;
              options = { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({ ids: favorites }) };
              break;
            case 'artist_songs':
              url = `?action=get_by_artist&name=${encodeURIComponent(param)}&sort=${sort}&page=${currentPage}`;
              break;
            case 'search':
              url = `?action=search&q=${encodeURIComponent(param)}&page=${currentPage}`;
              break;
            default:
              isLoadingMore = false;
              infiniteScrollLoader.classList.add('d-none');
              return;
          }

          const data = await fetchData(url, options);
          if (data && data.length > 0) {
            renderSongs(data, true);
          } else {
            allSongsLoaded = true;
          }
          
          isLoadingMore = false;
          infiniteScrollLoader.classList.add('d-none');
        };

        const loadView = async (view, param = '', sort = 'artist_asc') => {
          mainContent.scrollTop = 0;
          currentPage = 1;
          allSongsLoaded = false;
          isLoadingMore = false;
          showLoader();
          
          currentView = { type: view, param, sort };
          setupSortOptions(view);

          let url, data, options = {};
          switch (view) {
            case 'songs':
              updateContentTitle('All Songs');
              url = `?action=get_songs&sort=${sort}&page=1`;
              data = await fetchData(url);
              break;
            case 'favorites':
              updateContentTitle('Favorites');
              if (favorites.length === 0) {
                data = [];
              } else {
                url = `?action=get_favorites&page=1`;
                options = { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({ ids: favorites }) };
                data = await fetchData(url, options);
              }
              break;
            case 'albums':
              updateContentTitle('Albums');
              data = await fetchData(`?action=get_albums`);
              break;
            case 'artists':
              updateContentTitle('Artists');
              data = await fetchData(`?action=get_artists`);
              break;
            case 'artist_songs':
              updateContentTitle(param);
              url = `?action=get_by_artist&name=${encodeURIComponent(param)}&sort=${sort}&page=1`;
              data = await fetchData(url);
              break;
            case 'album_songs':
              updateContentTitle(param);
              data = await fetchData(`?action=get_by_album&name=${encodeURIComponent(param)}&page=1`);
              break;
            case 'search':
              sortControls.classList.add('d-none');
              updateContentTitle(`Search: "${param}"`);
              url = `?action=search&q=${encodeURIComponent(param)}&page=1`;
              data = await fetchData(url);
              break;
          }

          if (['songs', 'artist_songs', 'album_songs', 'search', 'favorites'].includes(view)) {
             renderSongs(data, false);
          } else if (['albums', 'artists'].includes(view)) {
             contentArea.innerHTML = renderGrid(data, view);
          }
        };

        const playSongById = async (songId) => {
          const data = await fetchData(`?action=get_song_data&id=${songId}`);
          if (!data) return;
          currentSong = data;
          audio.src = currentSong.stream_url;
          audio.play().catch(e => console.error("Audio play failed:", e));
          isPlaying = true;
          updatePlayerUI();
          if ('mediaSession' in navigator) {
            navigator.mediaSession.metadata = new MediaMetadata({
              title: currentSong.title, artist: currentSong.artist, album: currentSong.album,
              artwork: [{ src: currentSong.image_url, sizes: '512x512', type: 'image/webp' }]
            });
          }
        };

        const updatePlayerUI = () => {
          if (!currentSong) return;
          if (playerBar.classList.contains('d-none')) {
            playerBar.classList.remove('d-none');
            document.body.classList.add('player-visible');
          }
          const imageUrl = `?action=get_image&id=${currentSong.id}`;
          [playerArtDesktop, playerArtMobile].forEach(el => el.src = imageUrl);
          [playerTitleDesktop, playerTitleMobile].forEach(el => el.textContent = currentSong.title);
          [playerArtistDesktop, playerArtistMobile].forEach(el => el.textContent = currentSong.artist);
          document.title = `${currentSong.title} • ${currentSong.artist}`;
          updatePlayPauseIcons();
          updateFavoriteIcons(currentSong.id);
        };

        const updatePlayPauseIcons = () => {
          const icon = isPlaying ? ICONS.pause : ICONS.play;
          [playPauseBtnDesktop, playPauseBtnMobile].forEach(btn => {
            btn.innerHTML = icon;
            btn.title = isPlaying ? "Pause" : "Play";
          });
        };
        
        const updateRepeatIcons = () => {
          let icon = ICONS.repeat, title = "Repeat Off";
          [repeatBtnDesktop, repeatBtnMobile].forEach(btn => btn.classList.remove('active'));
          if (repeatMode === 'one') {
            icon = ICONS.repeatOne; title = "Repeat One";
            [repeatBtnDesktop, repeatBtnMobile].forEach(btn => btn.classList.add('active'));
          } else if (repeatMode === 'all') {
            title = "Repeat All";
            [repeatBtnDesktop, repeatBtnMobile].forEach(btn => btn.classList.add('active'));
          }
          [repeatBtnDesktop, repeatBtnMobile].forEach(btn => {
            btn.innerHTML = icon; btn.title = title;
          });
        };
        
        const updateShuffleButtons = () => {
          [shuffleBtnDesktop, shuffleBtnMobile].forEach(btn => {
            btn.classList.toggle('active', isShuffle);
            btn.title = isShuffle ? "Shuffle On" : "Shuffle Off";
          });
        };

        const showContextMenu = (e, buttonEl) => {
          e.preventDefault(); e.stopPropagation();
          const songId = parseInt(buttonEl.dataset.songId);
          const { artist, album } = buttonEl.dataset;
          const favText = isFavorite(songId) ? "Remove from Favorites" : "Add to Favorites";
          const favIcon = isFavorite(songId) ? ICONS.heartFill : ICONS.heart;
          contextMenu.innerHTML = `
            <li class="context-menu-item" data-action="go_artist" data-name="${artist}"><i class="bi bi-person-fill"></i> Go to Artist</li>
            <li class="context-menu-item" data-action="go_album" data-name="${album}"><i class="bi bi-disc-fill"></i> Go to Album</li>
            <li class="context-menu-item" data-action="toggle_favorite" data-id="${songId}">${favIcon} ${favText}</li>
          `;
          contextMenu.style.display = 'block';
          const rect = buttonEl.getBoundingClientRect();
          const menuWidth = contextMenu.offsetWidth;
          const menuHeight = contextMenu.offsetHeight;
          let x = rect.left; let y = rect.bottom + 5;
          if (x + menuWidth > window.innerWidth) x = window.innerWidth - menuWidth - 5;
          if (y + menuHeight > window.innerHeight) y = rect.top - menuHeight - 5;
          contextMenu.style.left = `${x}px`; contextMenu.style.top = `${y}px`;
        };
        
        const togglePlayPause = () => {
          if (!currentSong) return;
          isPlaying = !isPlaying;
          isPlaying ? audio.play() : audio.pause();
          updatePlayPauseIcons();
        };

        const playNext = () => {
          if (queue.length === 0) return;
          queueIndex++;
          if (queueIndex >= queue.length) {
            if (repeatMode === 'all') {
              queueIndex = 0;
            } else {
              isPlaying = false; audio.pause();
              updatePlayPauseIcons();
              if (queue.length > 0) queueIndex = queue.length - 1;
              return;
            }
          }
          playSongById(queue[queueIndex]);
        };

        const playPrev = () => {
          if (audio.currentTime > 3 || queueIndex === 0) {
            audio.currentTime = 0;
          } else {
            if (queue.length === 0) return;
            queueIndex = Math.max(0, queueIndex - 1);
            playSongById(queue[queueIndex]);
          }
        };

        const toggleShuffle = () => {
          isShuffle = !isShuffle;
          if (queue.length > 0) {
            const currentSongId = queue[queueIndex];
            if (isShuffle) {
              queue = [...originalQueue];
              for (let i = queue.length - 1; i > 0; i--) {
                const j = Math.floor(Math.random() * (i + 1));
                [queue[i], queue[j]] = [queue[j], queue[i]];
              }
              const shuffledCurrentIndex = queue.findIndex(id => id === currentSongId);
              if (shuffledCurrentIndex > -1) {
                [queue[0], queue[shuffledCurrentIndex]] = [queue[shuffledCurrentIndex], queue[0]];
              }
            } else {
              queue = [...originalQueue];
            }
            queueIndex = queue.findIndex(id => id === currentSongId);
          }
          updateShuffleButtons();
        };

        const setQueueAndPlay = async (startId) => {
          const { type, param, sort } = currentView;
          const body = { view_type: type, param, sort, ids: type === 'favorites' ? favorites : [] };
          const allIds = await fetchData('?action=get_view_ids', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body)
          });

          if (!allIds || allIds.length === 0) {
            console.error("Could not fetch queue.");
            return;
          }

          originalQueue = allIds;
          queue = [...originalQueue];
          if (isShuffle) { toggleShuffle(); toggleShuffle(); }
          queueIndex = queue.findIndex(id => id === startId);
          if (queueIndex === -1) { return; }
          playSongById(startId);
        };

        navLinks.forEach(link => link.addEventListener('click', e => {
          e.preventDefault();
          const targetId = e.currentTarget.id;
          if (['scan-btn', 'import-btn', 'export-btn', 'install-pwa-btn'].includes(targetId)) return;
          navLinks.forEach(l => l.classList.remove('active'));
          e.currentTarget.classList.add('active');
          loadView(e.currentTarget.dataset.view);
          const offcanvasEl = document.getElementById('main-nav-offcanvas');
          if (window.innerWidth < 768 && offcanvasEl) {
            const offcanvas = bootstrap.Offcanvas.getInstance(offcanvasEl);
            if (offcanvas) offcanvas.hide();
          }
        }));

        [searchInputDesktop, searchInputMobile].forEach(input => {
          input.addEventListener('keyup', e => {
            if (e.key === 'Enter' && e.target.value.trim() !== '') {
              loadView('search', e.target.value.trim());
            }
          });
        });

        sortSelect.addEventListener('change', (e) => {
          loadView(currentView.type, currentView.param, e.target.value);
        });

        scanBtn.addEventListener('click', e => {
          e.preventDefault();
          if (scanBtn.classList.contains('scanning')) return;
          scanBtn.classList.add('scanning');
          scanStatusText.textContent = "Initializing scan...";
          scanProgressBar.classList.remove('d-none');
          fetch('?action=scan');
          scanInterval = setInterval(async () => {
            const data = await fetchData('?action=scan_status');
            if (data && (data.status === 'finished' || data.status === 'error')) {
              clearInterval(scanInterval);
              scanBtn.classList.remove('scanning');
              scanProgressBar.classList.add('d-none');
              scanStatusText.textContent = data.message;
              setTimeout(() => loadView('songs'), 1000);
            }
            if (data) scanStatusText.textContent = data.message;
          }, 2000);
        });
        
        [playPauseBtnDesktop, playPauseBtnMobile].forEach(btn => btn.addEventListener('click', togglePlayPause));
        [prevBtnDesktop, prevBtnMobile].forEach(btn => btn.addEventListener('click', playPrev));
        [nextBtnDesktop, nextBtnMobile].forEach(btn => btn.addEventListener('click', playNext));
        [shuffleBtnDesktop, shuffleBtnMobile].forEach(btn => btn.addEventListener('click', toggleShuffle));
        [repeatBtnDesktop, repeatBtnMobile].forEach(btn => btn.addEventListener('click', () => {
          repeatMode = (repeatMode === 'none') ? 'all' : (repeatMode === 'all') ? 'one' : 'none';
          updateRepeatIcons();
        }));
        [favoriteBtnDesktop].forEach(btn => btn.addEventListener('click', () => {
          if (currentSong) toggleFavorite(currentSong.id);
        }));

        contentArea.addEventListener('click', e => {
          const target = e.target;
          const moreBtn = target.closest('.more-btn');
          if (moreBtn) {
            showContextMenu(e, moreBtn);
            return;
          }
          const songArtistEl = target.closest('.song-artist');
          if (songArtistEl) {
            e.stopPropagation();
            loadView('artist_songs', decodeURIComponent(songArtistEl.dataset.artist));
            return;
          }
          const songAlbumEl = target.closest('.song-album');
          if (songAlbumEl) {
            e.stopPropagation();
            loadView('album_songs', decodeURIComponent(songAlbumEl.dataset.album));
            return;
          }
          const songItem = target.closest('.song-item');
          if (songItem) {
            const songId = parseInt(songItem.dataset.songId);
            setQueueAndPlay(songId);
          } else if (target.closest('[data-artist]')) {
            loadView('artist_songs', decodeURIComponent(target.closest('[data-artist]').dataset.artist));
          } else if (target.closest('[data-album]')) {
            loadView('album_songs', decodeURIComponent(target.closest('[data-album]').dataset.album));
          }
        });
        
        document.addEventListener('click', e => {
          if (!contextMenu.contains(e.target)) contextMenu.style.display = 'none';
        });

        contextMenu.addEventListener('click', e => {
          const item = e.target.closest('.context-menu-item');
          if (!item) return;
          const { action, name, id } = item.dataset;
          const decodedName = name ? decodeURIComponent(name) : '';
          if (action === 'go_artist') loadView('artist_songs', decodedName);
          else if (action === 'go_album') loadView('album_songs', decodedName);
          else if (action === 'toggle_favorite') toggleFavorite(parseInt(id));
          contextMenu.style.display = 'none';
        });

        audio.addEventListener('timeupdate', () => {
          const { currentTime, duration } = audio;
          if (!isFinite(duration)) return;
          const timeLeft = duration - currentTime;
          progressBar.style.width = `${(currentTime / duration) * 100}%`;
          currentTimeEl.textContent = formatTime(currentTime);
          timeLeftEl.textContent = '-' + formatTime(timeLeft);
        });

        audio.addEventListener('loadedmetadata', () => {
          const { duration } = audio;
          if (!isFinite(duration)) return;
          timeLeftEl.textContent = '-' + formatTime(duration);
        });

        audio.addEventListener('ended', () => (repeatMode === 'one') ? audio.play() : playNext());

        progressContainer.addEventListener('click', e => {
          if (!audio.duration || !isFinite(audio.duration)) return;
          const bounds = progressContainer.getBoundingClientRect();
          const percent = Math.max(0, Math.min(1, (e.clientX - bounds.left) / bounds.width));
          audio.currentTime = percent * audio.duration;
        });

        importBtn.addEventListener('click', (e) => {
          e.preventDefault();
          importFileInput.click();
        });
        exportBtn.addEventListener('click', (e) => {
          e.preventDefault();
          const dataStr = "data:text/json;charset=utf-8," + encodeURIComponent(JSON.stringify(favorites));
          const downloadAnchorNode = document.createElement('a');
          downloadAnchorNode.setAttribute("href", dataStr);
          downloadAnchorNode.setAttribute("download", "favorites.json");
          document.body.appendChild(downloadAnchorNode);
          downloadAnchorNode.click();
          downloadAnchorNode.remove();
        });
        importFileInput.addEventListener('change', (e) => {
          const file = e.target.files[0];
          if (!file) return;
          const reader = new FileReader();
          reader.onload = function(event) {
            try {
              const importedFavorites = JSON.parse(event.target.result);
              if (Array.isArray(importedFavorites) && importedFavorites.every(i => typeof i === 'number')) {
                favorites = importedFavorites;
                saveFavorites();
                alert('Favorites imported successfully!');
                loadView(currentView.type, currentView.param, currentView.sort);
              } else {
                alert('Invalid favorites file format.');
              }
            } catch (err) {
              alert('Error reading favorites file.');
            }
          };
          reader.readAsText(file);
          e.target.value = '';
        });
        
        mainContent.addEventListener('scroll', () => {
          if (mainContent.scrollTop + mainContent.clientHeight >= mainContent.scrollHeight - 200) {
            loadMoreSongs();
          }
        });

        window.addEventListener('beforeinstallprompt', (e) => {
          e.preventDefault();
          deferredInstallPrompt = e;
          installPwaBtn.classList.remove('d-none');
        });

        installPwaBtn.addEventListener('click', async (e) => {
          e.preventDefault();
          if (!deferredInstallPrompt) {
            return;
          }
          deferredInstallPrompt.prompt();
          const { outcome } = await deferredInstallPrompt.userChoice;
          deferredInstallPrompt = null;
          installPwaBtn.classList.add('d-none');
        });
        
        const init = () => {
          if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('?pwa=sw');
          }
          [prevBtnDesktop, prevBtnMobile].forEach(b => b.innerHTML = ICONS.prev);
          [nextBtnDesktop, nextBtnMobile].forEach(b => b.innerHTML = ICONS.next);
          [shuffleBtnDesktop, shuffleBtnMobile].forEach(b => b.innerHTML = ICONS.shuffle);
          favoriteBtnDesktop.innerHTML = ICONS.heart;
          loadFavorites();
          updatePlayPauseIcons();
          updateRepeatIcons();
          updateShuffleButtons();
          loadView('songs');
        };

        init();
      });
    </script>
  </body>
</html>