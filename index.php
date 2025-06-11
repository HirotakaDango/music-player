<?php
// PWA (Progressive Web App) Handler
if (isset($_GET['pwa'])) {
  // Serve the Web App Manifest
  if ($_GET['pwa'] == 'manifest') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
      "name" => "PHP Music Player",
      "short_name" => "Music",
      "start_url" => ".",
      "display" => "standalone",
      "background_color" => "#030303",
      "theme_color" => "#121212",
      "description" => "A simple, fast music player with user accounts and uploads.",
      "icons" => [
        [
          "src" => "?action=get_app_icon",
          "sizes" => "any",
          "type" => "image/svg+xml",
          "purpose" => "any"
        ]
      ]
    ]);
    exit;
  }
  // Serve the Service Worker
  if ($_GET['pwa'] == 'sw') {
    header('Content-Type: application/javascript; charset=utf-8');
    // We use a Network-First, then Cache strategy for dynamic content.
    // For static assets, we use Cache-First. This ensures the app is fast but data is always fresh.
    echo <<<SW
    const CACHE_NAME = 'php-music-cache-v7';
    const STATIC_ASSETS = [
      './',
      'https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css',
      'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css',
      'https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js',
      'https://cdn.jsdelivr.net/npm/sortablejs@latest/Sortable.min.js'
    ];

    self.addEventListener('install', event => {
      event.waitUntil(caches.open(CACHE_NAME).then(cache => cache.addAll(STATIC_ASSETS)));
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

    self.addEventListener('fetch', event => {
      const url = new URL(event.request.url);
      const isApiCall = url.searchParams.has('action');
      const isPwaCall = url.searchParams.has('pwa');

      // Always go to the network for API calls and PWA files to ensure freshness.
      // This prevents issues with stale data for favorites, deletions, etc.
      if (isApiCall || isPwaCall) {
        event.respondWith(fetch(event.request));
        return;
      }
      
      // For all other requests (static assets), use a Cache-first strategy.
      event.respondWith(
        caches.match(event.request).then(response => {
          return response || fetch(event.request).then(networkResponse => {
            // Cache newly fetched static assets.
            if (networkResponse && networkResponse.ok) {
               const responseToCache = networkResponse.clone();
               caches.open(CACHE_NAME).then(cache => cache.put(event.request, responseToCache));
            }
            return networkResponse;
          });
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
    $db = new PDO('sqlite:' . DB_FILE, null, null, [PDO::ATTR_TIMEOUT => 30]);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    return $db;
  } catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
  }
}

function init_db($db) {
  $db->exec("PRAGMA journal_mode=WAL;");
  $db->exec("
    CREATE TABLE IF NOT EXISTS users (
      id INTEGER PRIMARY KEY,
      email TEXT UNIQUE,
      artist TEXT,
      password_hash TEXT
    );
  ");
  $db->exec("
    CREATE TABLE IF NOT EXISTS music (
      id INTEGER PRIMARY KEY,
      user_id INTEGER,
      file TEXT UNIQUE,
      title TEXT,
      artist TEXT,
      album TEXT,
      genre TEXT,
      year INTEGER,
      duration INTEGER,
      image BLOB,
      last_modified INTEGER,
      FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
    );
  ");
  $db->exec("
    CREATE TABLE IF NOT EXISTS favorites (
      user_id INTEGER NOT NULL,
      song_id INTEGER NOT NULL,
      sort_order INTEGER,
      PRIMARY KEY (user_id, song_id),
      FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
      FOREIGN KEY (song_id) REFERENCES music(id) ON DELETE CASCADE
    );
  ");
  $db->exec("CREATE INDEX IF NOT EXISTS music_artist_idx ON music(artist);");
  $db->exec("CREATE INDEX IF NOT EXISTS music_album_idx ON music(album);");
  $db->exec("CREATE INDEX IF NOT EXISTS music_genre_idx ON music(genre);");
  $db->exec("CREATE INDEX IF NOT EXISTS music_user_id_idx ON music(user_id);");
  $db->exec("CREATE INDEX IF NOT EXISTS fav_user_id_idx ON favorites(user_id);");

  $stmt = $db->query("SELECT id FROM users WHERE email = 'musiclibrary@mail.com'");
  if (!$stmt->fetch()) {
    $db->prepare("INSERT INTO users (email, artist, password_hash) VALUES (?, ?, ?)")
      ->execute(['musiclibrary@mail.com', 'Music Library', password_hash('musiclibrary', PASSWORD_DEFAULT)]);
  }
}

function sanitize_for_path($string) {
  $string = strtolower($string);
  $string = preg_replace('/[^a-z0-9]/', '', $string);
  return empty($string) ? 'unknown' : $string;
}

function get_upload_limit() {
  $max_upload = ini_get('upload_max_filesize');
  $max_post = ini_get('post_max_size');
  return "Max file size: " . min($max_upload, $max_post);
}

function process_image_to_webp($imageData) {
  if (!$imageData || !function_exists('imagecreatefromstring') || !function_exists('imagewebp')) {
    return null;
  }
  $sourceImage = @imagecreatefromstring($imageData);
  if (!$sourceImage) { return null; }

  $target_width = 250;
  $target_height = 250;

  $resizedImage = imagecreatetruecolor($target_width, $target_height);
  imagealphablending($resizedImage, false);
  imagesavealpha($resizedImage, true);
  imagecopyresampled($resizedImage, $sourceImage, 0, 0, 0, 0, $target_width, $target_height, imagesx($sourceImage), imagesy($sourceImage));

  ob_start();
  imagewebp($resizedImage, null, 75);
  $webpData = ob_get_clean();
  imagedestroy($sourceImage);
  imagedestroy($resizedImage);
  return $webpData;
}

// API Action Router
if (isset($_GET['action'])) {
  $action = $_GET['action'];
  $db = get_db();
  init_db($db);

  // Set headers to prevent caching of API responses, ensuring real-time updates.
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  header('Pragma: no-cache');
  header('Expires: 0');

  $user_id = $_SESSION['user_id'] ?? null;
  $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
  $offset = ($page - 1) * PAGE_SIZE;
  $limit_clause = " LIMIT " . PAGE_SIZE . " OFFSET " . $offset;

  switch ($action) {
    case 'get_app_icon':
      // Serve a scalable SVG icon directly. No Imagick or Base64 PNG needed.
      header_remove('Content-Type');
      header('Content-Type: image/svg+xml');
      $size = intval($_GET['size'] ?? 192); // Size is for reference, SVG is scalable
      echo '<svg xmlns="http://www.w3.org/2000/svg" width="'.$size.'" height="'.$size.'" fill="white" class="bi bi-boombox-fill" viewBox="0 0 16 16"><path d="M11.538 6.237a.5.5 0 0 0-.738.03l-1.36 2.04a.5.5 0 0 0 .37.823h2.72a.5.5 0 0 0 .37-.823l-1.359-2.04a.5.5 0 0 0-.363-.17z"/><path d="M16 8A8 8 0 1 1 0 8a8 8 0 0 1 16 0zM4.5 5.5a1 1 0 1 0 0-2 1 1 0 0 0 0 2m7 0a1 1 0 1 0 0-2 1 1 0 0 0 0 2M6 6.5a.5.5 0 0 0 .5.5h3a.5.5 0 0 0 0-1h-3a.5.5 0 0 0-.5.5m-1.5 6a.5.5 0 0 0 .5.5h5a.5.5 0 0 0 0-1h-5a.5.5 0 0 0-.5.5"/></svg>';
      exit;

    case 'get_session':
      if ($user_id) {
        $stmt = $db->prepare("SELECT id, email, artist FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch();
        if ($user) {
          echo json_encode(['status' => 'loggedin', 'user' => $user, 'upload_limit' => get_upload_limit()]);
        } else {
          session_destroy();
          echo json_encode(['status' => 'loggedout']);
        }
      } else {
        echo json_encode(['status' => 'loggedout']);
      }
      break;

    case 'register':
      $data = json_decode(file_get_contents('php://input'), true);
      $email = filter_var($data['email'], FILTER_VALIDATE_EMAIL);
      $artist = trim(htmlspecialchars($data['artist'], ENT_QUOTES, 'UTF-8'));
      $password = $data['password'];

      if (!$email || empty($artist) || strlen($password) < 6) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid data. Password needs 6+ characters.']);
        exit;
      }
      $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
      $stmt->execute([$email]);
      if ($stmt->fetch()) {
        http_response_code(409);
        echo json_encode(['status' => 'error', 'message' => 'Email already registered.']);
        exit;
      }

      $hash = password_hash($password, PASSWORD_DEFAULT);
      $stmt = $db->prepare("INSERT INTO users (email, artist, password_hash) VALUES (?, ?, ?)");
      $stmt->execute([$email, $artist, $hash]);
      echo json_encode(['status' => 'success', 'message' => 'Registration successful. Please log in.']);
      break;

    case 'login':
      $data = json_decode(file_get_contents('php://input'), true);
      $email = filter_var($data['email'], FILTER_VALIDATE_EMAIL);
      $password = $data['password'];

      if (!$email || empty($password)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Email and password are required.']);
        exit;
      }
      $stmt = $db->prepare("SELECT * FROM users WHERE email = ?");
      $stmt->execute([$email]);
      $user = $stmt->fetch();
      if ($user && password_verify($password, $user['password_hash'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_artist'] = $user['artist'];
        unset($user['password_hash']);
        echo json_encode(['status' => 'success', 'user' => $user, 'upload_limit' => get_upload_limit()]);
      } else {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Invalid credentials.']);
      }
      break;

    case 'logout':
      session_destroy();
      echo json_encode(['status' => 'success']);
      break;

    case 'change_password':
      if (!$user_id) { http_response_code(403); exit; }
      $data = json_decode(file_get_contents('php://input'), true);
      $new_password = $data['new_password'];
      if (strlen($new_password) < 6) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Password must be at least 6 characters.']);
        exit;
      }
      $hash = password_hash($new_password, PASSWORD_DEFAULT);
      $stmt = $db->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
      $stmt->execute([$hash, $user_id]);
      echo json_encode(['status' => 'success', 'message' => 'Password changed successfully.']);
      break;

    case 'scan':
      if (!$user_id) { http_response_code(403); exit; }
      echo json_encode(['status' => 'starting']);
      session_write_close();
      ob_flush(); flush();
      scan_music_directory($db);
      break;

    case 'scan_status':
      echo json_encode(['status' => $_SESSION['scan_status'] ?? 'idle', 'message' => $_SESSION['scan_message'] ?? '']);
      break;

    case 'upload_song':
      if (!$user_id) { http_response_code(403); exit; }
      if (!class_exists('getID3')) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'getID3 library is missing.']);
        exit;
      }
      if (isset($_FILES['song'])) {
        $file = $_FILES['song'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Upload error: ' . $file['error']]);
            exit;
        }
        $getID3 = new getID3;
        $info = $getID3->analyze($file['tmp_name']);
        getid3_lib::CopyTagsToComments($info);

        $artist = trim($info['comments']['artist'][0] ?? 'Unknown Artist');
        $artist_path = sanitize_for_path($artist);
        $upload_dir = MUSIC_DIR . '/uploads/' . $artist_path;
        if (!is_dir($upload_dir)) {
          mkdir($upload_dir, 0755, true);
        }

        $filename = preg_replace('/[^a-zA-Z0-9\._-]/', '', basename($file['name']));
        $filePath = $upload_dir . '/' . $filename;
        if (move_uploaded_file($file['tmp_name'], $filePath)) {
          $title = trim($info['comments']['title'][0] ?? pathinfo($filePath, PATHINFO_FILENAME));
          $album = trim($info['comments']['album'][0] ?? 'Unknown Album');
          $year = (int)($info['comments']['year'][0] ?? 0);
          $duration = (int)($info['playtime_seconds'] ?? 0);
          // FIX: Use genre from file first, then from form, then default.
          $genre = trim($info['comments']['genre'][0] ?? '') ?: trim($_POST['genre'] ?? '') ?: 'Uploaded';
          $raw_image_data = isset($info['comments']['picture'][0]['data']) ? $info['comments']['picture'][0]['data'] : null;
          $webp_image_data = process_image_to_webp($raw_image_data);

          $stmt = $db->prepare("INSERT INTO music (user_id, file, title, artist, album, genre, year, duration, image, last_modified) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
          $stmt->execute([$user_id, $filePath, $title, $artist, $album, $genre, $year, $duration, $webp_image_data, time()]);

          echo json_encode(['status' => 'success', 'message' => 'File ' . $filename . ' uploaded.']);
        } else {
          http_response_code(500);
          echo json_encode(['status' => 'error', 'message' => 'Failed to move uploaded file.']);
        }
      }
      break;

    case 'delete_song':
      if (!$user_id) { http_response_code(403); exit; }
      $data = json_decode(file_get_contents('php://input'), true);
      $song_id = intval($data['id']);

      $stmt = $db->prepare("SELECT file, user_id FROM music WHERE id = ?");
      $stmt->execute([$song_id]);
      $song = $stmt->fetch();
      
      if ($song && ($song['user_id'] == $user_id || $_SESSION['user_artist'] == 'Music Library')) {
        $db->prepare("DELETE FROM music WHERE id = ?")->execute([$song_id]);
        if ($song['file'] && file_exists($song['file']) && strpos(realpath($song['file']), realpath(MUSIC_DIR . '/uploads')) === 0) {
          @unlink($song['file']);
        }
        echo json_encode(['status' => 'success', 'message' => 'Song deleted.']);
      } else {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'You do not have permission.']);
      }
      break;

    case 'download_song':
      if (!$user_id) { http_response_code(403); exit; }
      $song_id = intval($_GET['id'] ?? 0);
      $stmt = $db->prepare("SELECT file FROM music WHERE id = ?");
      $stmt->execute([$song_id]);
      $song = $stmt->fetch();

      if ($song && file_exists($song['file'])) {
        header_remove('Content-Type');
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        header('Content-Type: ' . finfo_file($finfo, $song['file']));
        finfo_close($finfo);
        header('Content-Length: ' . filesize($song['file']));
        header('Content-Disposition: attachment; filename="' . basename($song['file']) . '"');
        ob_clean();
        flush();
        readfile($song['file']);
        exit;
      } else {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'File not found.']);
      }
      break;

    case 'edit_genre':
      if (!$user_id) { http_response_code(403); exit; }
      $data = json_decode(file_get_contents('php://input'), true);
      $song_id = intval($data['id']);
      $new_genre = trim(htmlspecialchars($data['genre'] ?? '', ENT_QUOTES, 'UTF-8'));

      if (empty($new_genre)) {
          http_response_code(400);
          echo json_encode(['status' => 'error', 'message' => 'Genre cannot be empty.']);
          exit;
      }

      $stmt = $db->prepare("SELECT user_id FROM music WHERE id = ?");
      $stmt->execute([$song_id]);
      $song = $stmt->fetch();

      if ($song && ($song['user_id'] == $user_id || $_SESSION['user_artist'] == 'Music Library')) {
          $stmt = $db->prepare("UPDATE music SET genre = ? WHERE id = ?");
          $stmt->execute([$new_genre, $song_id]);
          echo json_encode(['status' => 'success', 'message' => 'Genre updated successfully.']);
      } else {
          http_response_code(403);
          echo json_encode(['status' => 'error', 'message' => 'You do not have permission to edit this song.']);
      }
      break;
    
    case 'get_songs':
      $sort_key = $_GET['sort'] ?? 'artist_asc';
      $sort_map = [
        'artist_asc' => 'ORDER BY artist COLLATE NOCASE ASC, album COLLATE NOCASE ASC, title COLLATE NOCASE ASC',
        'title_asc' => 'ORDER BY title COLLATE NOCASE ASC',
        'album_asc' => 'ORDER BY album COLLATE NOCASE ASC, title COLLATE NOCASE ASC',
        'year_desc' => 'ORDER BY year DESC, album COLLATE NOCASE ASC, title COLLATE NOCASE ASC',
        'year_asc' => 'ORDER BY year ASC, album COLLATE NOCASE ASC, title COLLATE NOCASE ASC'
      ];
      $order_by = $sort_map[$sort_key] ?? $sort_map['artist_asc'];

      $where_clauses = [];
      $params = [];
      if (!empty($_GET['artist'])) {
        $where_clauses[] = 'artist = ?';
        $params[] = $_GET['artist'];
      }
      if (!empty($_GET['album'])) {
        $where_clauses[] = 'album = ?';
        $params[] = $_GET['album'];
      }
      if (!empty($_GET['genre'])) {
        $where_clauses[] = 'genre = ?';
        $params[] = $_GET['genre'];
      }
      $where_sql = count($where_clauses) > 0 ? 'WHERE ' . implode(' AND ', $where_clauses) : '';
      
      $stmt = $db->prepare("SELECT id, title, artist, album, duration, user_id FROM music " . $where_sql . " " . $order_by . $limit_clause);
      $stmt->execute($params);
      echo json_encode($stmt->fetchAll());
      break;

    case 'get_profile_songs':
      if (!$user_id) { echo json_encode([]); exit; }
      $sort_key = $_GET['sort'] ?? 'artist_asc';
      $sort_map = [
        'artist_asc' => 'ORDER BY artist COLLATE NOCASE ASC, album COLLATE NOCASE ASC, title COLLATE NOCASE ASC',
        'title_asc' => 'ORDER BY title COLLATE NOCASE ASC',
        'album_asc' => 'ORDER BY album COLLATE NOCASE ASC, title COLLATE NOCASE ASC',
        'year_desc' => 'ORDER BY year DESC, album COLLATE NOCASE ASC, title COLLATE NOCASE ASC',
        'year_asc' => 'ORDER BY year ASC, album COLLATE NOCASE ASC, title COLLATE NOCASE ASC'
      ];
      $order_by = $sort_map[$sort_key] ?? $sort_map['artist_asc'];
      $stmt = $db->prepare("SELECT id, title, artist, album, duration, user_id FROM music WHERE user_id = ? " . $order_by . $limit_clause);
      $stmt->execute([$user_id]);
      echo json_encode($stmt->fetchAll());
      break;

    case 'get_favorites':
      if (!$user_id) { echo json_encode([]); exit; }
      $sort_key = $_GET['sort'] ?? 'manual_order';
      $sort_map = [
        'manual_order' => 'ORDER BY f.sort_order ASC',
        'artist_asc' => 'ORDER BY m.artist COLLATE NOCASE ASC, m.album COLLATE NOCASE ASC, m.title COLLATE NOCASE ASC',
        'title_asc' => 'ORDER BY m.title COLLATE NOCASE ASC',
        'album_asc' => 'ORDER BY m.album COLLATE NOCASE ASC, m.title COLLATE NOCASE ASC',
        'year_desc' => 'ORDER BY m.year DESC, m.album COLLATE NOCASE ASC, m.title COLLATE NOCASE ASC',
        'year_asc' => 'ORDER BY m.year ASC, m.album COLLATE NOCASE ASC, m.title COLLATE NOCASE ASC',
      ];
      $order_by = $sort_map[$sort_key] ?? $sort_map['manual_order'];
      $stmt = $db->prepare("SELECT m.id, m.title, m.artist, m.album, m.duration, m.user_id FROM music m JOIN favorites f ON m.id = f.song_id WHERE f.user_id = ? " . $order_by . $limit_clause);
      $stmt->execute([$user_id]);
      echo json_encode($stmt->fetchAll());
      break;
    
    case 'toggle_favorite':
      if (!$user_id) { http_response_code(403); exit; }
      $data = json_decode(file_get_contents('php://input'), true);
      $song_id = intval($data['id']);
      $stmt = $db->prepare("SELECT song_id FROM favorites WHERE user_id = ? AND song_id = ?");
      $stmt->execute([$user_id, $song_id]);
      if ($stmt->fetch()) {
        $db->prepare("DELETE FROM favorites WHERE user_id = ? AND song_id = ?")->execute([$user_id, $song_id]);
        echo json_encode(['status' => 'removed', 'is_favorite' => false]);
      } else {
        $stmt = $db->prepare("SELECT MAX(sort_order) as max_order FROM favorites WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $max_order = $stmt->fetchColumn() ?? 0;
        $db->prepare("INSERT INTO favorites (user_id, song_id, sort_order) VALUES (?, ?, ?)")->execute([$user_id, $song_id, $max_order + 1]);
        echo json_encode(['status' => 'added', 'is_favorite' => true]);
      }
      break;

    case 'update_favorite_order':
      if (!$user_id) { http_response_code(403); exit; }
      $data = json_decode(file_get_contents('php://input'), true);
      $ordered_ids = $data['ids'];
      $db->beginTransaction();
      try {
        foreach ($ordered_ids as $index => $song_id) {
          $db->prepare("UPDATE favorites SET sort_order = ? WHERE user_id = ? AND song_id = ?")
             ->execute([$index, $user_id, $song_id]);
        }
        $db->commit();
        echo json_encode(['status' => 'success']);
      } catch (Exception $e) {
        $db->rollBack();
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Failed to update order.']);
      }
      break;

    case 'get_view_ids':
      $post_data = json_decode(file_get_contents('php://input'), true);
      $view_type = $post_data['view_type'] ?? '';
      $param = $post_data['param'] ?? '';
      $sort = $post_data['sort'] ?? '';

      // FIX: Decode param for views where it's URL encoded from a data attribute.
      if (in_array($view_type, ['artist_songs', 'album_songs', 'genre_songs'])) {
        $param = urldecode($param);
      }

      $sql = "SELECT m.id FROM music m ";
      $conditions = "";
      $params = [];
      $default_sort = 'artist_asc';

      switch ($view_type) {
        case 'songs': break;
        case 'profile_songs':
          if (!$user_id) { echo json_encode([]); exit; }
          $conditions = "WHERE m.user_id = ?";
          $params[] = $user_id;
          break;
        case 'favorites':
          if (!$user_id) { echo json_encode([]); exit; }
          $sql = "SELECT m.id FROM music m JOIN favorites f ON m.id = f.song_id ";
          $conditions = "WHERE f.user_id = ?";
          $params[] = $user_id;
          $default_sort = 'manual_order';
          break;
        case 'artist_songs':
          $conditions = "WHERE m.artist = ?";
          $params[] = $param;
          $default_sort = 'album_asc';
          break;
        case 'album_songs':
          $conditions = "WHERE m.album = ?";
          $params[] = $param;
          $default_sort = 'title_asc';
          break;
        case 'genre_songs':
          $conditions = "WHERE m.genre = ?";
          $params[] = $param;
          $default_sort = 'artist_asc';
          break;
        case 'search':
          $conditions = "WHERE m.title LIKE ? OR m.artist LIKE ? OR m.album LIKE ?";
          $query_param = '%' . $param . '%';
          $params = [$query_param, $query_param, $query_param];
          break;
        default:
          echo json_encode([]); exit;
      }
      $sort_map = [
        'manual_order' => 'ORDER BY f.sort_order ASC',
        'artist_asc' => 'ORDER BY m.artist COLLATE NOCASE ASC, m.album COLLATE NOCASE ASC, m.title COLLATE NOCASE ASC',
        'title_asc' => 'ORDER BY m.title COLLATE NOCASE ASC',
        'album_asc' => 'ORDER BY m.album COLLATE NOCASE ASC, m.title COLLATE NOCASE ASC',
        'year_desc' => 'ORDER BY m.year DESC, m.album COLLATE NOCASE ASC, m.title COLLATE NOCASE ASC',
        'year_asc' => 'ORDER BY m.year ASC, m.album COLLATE NOCASE ASC, m.title COLLATE NOCASE ASC',
      ];
      $order_by = $sort_map[$sort] ?? $sort_map[$default_sort];
      
      $stmt = $db->prepare($sql . $conditions . " " . $order_by);
      $stmt->execute($params);
      echo json_encode($stmt->fetchAll(PDO::FETCH_COLUMN));
      exit;

    case 'get_artists':
      $stmt = $db->query("SELECT DISTINCT artist FROM music WHERE artist != '' AND artist IS NOT NULL ORDER BY artist COLLATE NOCASE " . $limit_clause);
      echo json_encode($stmt->fetchAll(PDO::FETCH_COLUMN));
      break;

    case 'get_albums':
      $stmt = $db->query("SELECT album, artist, MAX(id) as id FROM music WHERE album != '' AND album IS NOT NULL GROUP BY album ORDER BY album COLLATE NOCASE " . $limit_clause);
      echo json_encode($stmt->fetchAll());
      break;
    
    case 'get_genres':
      $stmt = $db->query("SELECT DISTINCT genre FROM music WHERE genre != '' AND genre IS NOT NULL ORDER BY genre COLLATE NOCASE " . $limit_clause);
      echo json_encode($stmt->fetchAll(PDO::FETCH_COLUMN));
      break;

    case 'get_view_details':
      $type = $_GET['type'] ?? '';
      $name = $_GET['name'] ?? '';
      if (empty($type) || empty($name) || !in_array($type, ['artist', 'album', 'genre'])) {
        http_response_code(400); exit;
      }
      $field = $type;
      $stmt = $db->prepare("SELECT COUNT(*) as song_count, SUM(duration) as total_duration, MAX(id) as image_id FROM music WHERE {$field} = ?");
      $stmt->execute([$name]);
      $details = $stmt->fetch();
      $details['name'] = $name;
      $details['image_url'] = '?action=get_image&id=' . ($details['image_id'] ?? 0);
      echo json_encode($details);
      break;

    case 'search':
      $query = '%' . ($_GET['q'] ?? '') . '%';
      $order_by = 'ORDER BY artist COLLATE NOCASE ASC, album COLLATE NOCASE ASC, title COLLATE NOCASE ASC';
      $stmt = $db->prepare("SELECT id, title, artist, album, duration, user_id FROM music WHERE title LIKE ? OR artist LIKE ? OR album LIKE ? " . $order_by . " " . $limit_clause);
      $stmt->execute([$query, $query, $query]);
      echo json_encode($stmt->fetchAll());
      break;

    case 'get_song_data':
      $id = intval($_GET['id'] ?? 0);
      // FIX: Added m.genre to the returned data for the edit feature.
      $stmt = $db->prepare("SELECT m.id, m.file, m.title, m.artist, m.album, m.genre, m.duration, m.user_id, CASE WHEN f.song_id IS NOT NULL THEN 1 ELSE 0 END AS is_favorite FROM music m LEFT JOIN favorites f ON m.id = f.song_id AND f.user_id = ? WHERE m.id = ?");
      $stmt->execute([$user_id, $id]);
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
        $filesize = filesize($file_path);
        header('Content-Type: audio/mpeg');
        header('Accept-Ranges: bytes');
        
        if (isset($_SERVER['HTTP_RANGE'])) {
          $range = $_SERVER['HTTP_RANGE'];
          $range = str_replace('bytes=', '', $range);
          list($start, $end) = explode('-', $range, 2);
          $start = intval($start);
          if (!$end) {
            $end = $filesize - 1;
          } else {
            $end = intval($end);
          }
          $length = $end - $start + 1;
          
          header('HTTP/1.1 206 Partial Content');
          header('Content-Length: ' . $length);
          header("Content-Range: bytes $start-$end/$filesize");

          $f = @fopen($file_path, 'rb');
          if ($f) {
            fseek($f, $start);
            echo fread($f, $length);
            fclose($f);
          }
        } else {
          header('Content-Length: ' . $filesize);
          readfile($file_path);
        }
      } else {
        http_response_code(404);
      }
      break;

    case 'get_image':
      header_remove('Content-Type');
      $id = intval($_GET['id'] ?? 0);
      $stmt = $db->prepare("SELECT image FROM music WHERE id = ?");
      $stmt->execute([$id]);
      $image_data = $stmt->fetchColumn();
      if ($image_data) {
        header('Content-Type: image/webp');
        echo $image_data;
      } else {
        header('Content-Type: image/svg+xml');
        echo '<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100" fill="#404040" class="bi bi-music-note" viewBox="0 0 16 16"><path d="M9 13c0 1.105-1.12 2-2.5 2S4 14.105 4 13s1.12-2 2.5-2 2.5.895 2.5 2"/><path fill-rule="evenodd" d="M9 3v10H8V3h1z"/><path d="M8 2.82a1 1 0 0 1 .804-.98l3-.6A1 1 0 0 1 13 2.22V4L8 5V2.82z"/></svg>';
      }
      break;
  }
  exit;
}

/**
 * SCANNER FIX: New recursive scanner using basic `scandir`.
 * This is highly compatible with restricted shared hosting environments (like InfinityFree)
 * that may disable `RecursiveDirectoryIterator` or have `open_basedir` restrictions.
 * It also explicitly skips the 'uploads' directory for efficiency.
 *
 * @param string $dir The directory to scan.
 * @param array &$results The array to store results in (by reference).
 * @param string $uploads_path The full, real path to the user uploads directory to skip.
 * @return void
 */
function get_music_files_recursive($dir, &$results, $uploads_path) {
  if (!is_readable($dir)) { return; }
  
  $items = scandir($dir);
  foreach ($items as $item) {
    if ($item === '.' || $item === '..') {
      continue;
    }
    
    $path = $dir . DIRECTORY_SEPARATOR . $item;
    
    // Explicitly skip the entire uploads directory
    if ($path === $uploads_path) {
      continue;
    }
    
    if (is_dir($path)) {
      get_music_files_recursive($path, $results, $uploads_path);
    } elseif (preg_match('/\.(mp3|m4a|flac|ogg|wav)$/i', $path)) {
      $results[$path] = filemtime($path);
    }
  }
}

function scan_music_directory($db) {
  if (!class_exists('getID3')) {
    $_SESSION['scan_status'] = 'error'; $_SESSION['scan_message'] = 'getID3 library not found.'; return;
  }
  $_SESSION['scan_status'] = 'scanning'; $_SESSION['scan_message'] = 'Starting scan...'; session_write_close();

  $stmt = $db->query("SELECT id FROM users WHERE email = 'musiclibrary@mail.com'");
  $library_user_id = $stmt->fetchColumn();
  if (!$library_user_id) {
    session_start();
    $_SESSION['scan_status'] = 'error'; $_SESSION['scan_message'] = 'Music Library user not found.';
    session_write_close();
    return;
  }

  $stmt = $db->query("SELECT file, last_modified FROM music WHERE user_id = " . $library_user_id);
  $db_files = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

  // Use the new, more compatible recursive scanner.
  $disk_files = [];
  $uploads_path = realpath(MUSIC_DIR . '/uploads');
  get_music_files_recursive(MUSIC_DIR, $disk_files, $uploads_path);

  $files_to_delete = array_diff_key($db_files, $disk_files);
  $files_to_add = array_diff_key($disk_files, $db_files);
  $files_to_check_for_update = array_intersect_key($disk_files, $db_files);
  
  if (!empty($files_to_delete)) {
    $db->beginTransaction();
    $delete_stmt = $db->prepare("DELETE FROM music WHERE file = ?");
    foreach (array_keys($files_to_delete) as $file_path) {
      $delete_stmt->execute([$file_path]);
    }
    $db->commit();
  }
  
  $files_to_process = $files_to_add;
  foreach ($files_to_check_for_update as $path => $mtime) {
    if ($mtime > $db_files[$path]) {
      $files_to_process[$path] = $mtime;
    }
  }

  if (empty($files_to_process)) {
    session_start(); $_SESSION['scan_status'] = 'finished'; $_SESSION['scan_message'] = 'Scan complete. No new or updated files found.'; session_write_close();
    return;
  }
  
  $getID3 = new getID3;
  $insert_stmt = $db->prepare("INSERT INTO music (user_id, file, title, artist, album, genre, year, duration, image, last_modified) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
  $update_stmt = $db->prepare("UPDATE music SET title=?, artist=?, album=?, genre=?, year=?, duration=?, image=?, last_modified=? WHERE file=?");
  
  $total = count($files_to_process); $count = 0;
  foreach ($files_to_process as $filePath => $mtime) {
    session_start(); $_SESSION['scan_message'] = "Processing " . ($count + 1) . " of $total: " . basename($filePath); session_write_close();
    $count++;
    
    try {
      $info = $getID3->analyze($filePath);
      getid3_lib::CopyTagsToComments($info);
      $title = trim($info['comments']['title'][0] ?? pathinfo($filePath, PATHINFO_FILENAME));
      $artist = trim($info['comments']['artist'][0] ?? 'Unknown Artist');
      $album = trim($info['comments']['album'][0] ?? 'Unknown Album');
      $genre = trim($info['comments']['genre'][0] ?? 'Unknown Genre');
      $year = (int)($info['comments']['year'][0] ?? 0);
      $duration = (int)($info['playtime_seconds'] ?? 0);
      $raw_image_data = isset($info['comments']['picture'][0]['data']) ? $info['comments']['picture'][0]['data'] : null;
      $webp_image_data = process_image_to_webp($raw_image_data);
      
      $db->beginTransaction();
      if (isset($files_to_add[$filePath])) {
        $insert_stmt->execute([$library_user_id, $filePath, $title, $artist, $album, $genre, $year, $duration, $webp_image_data, $mtime]);
      } else {
        $update_stmt->execute([$title, $artist, $album, $genre, $year, $duration, $webp_image_data, $mtime, $filePath]);
      }
      $db->commit();
    } catch (Exception $e) {
      if ($db->inTransaction()) { $db->rollBack(); }
    }
  }
  
  session_start(); $_SESSION['scan_status'] = 'finished'; $_SESSION['scan_message'] = "Scan complete. Processed $count files."; session_write_close();
}
?>
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PHP Music Player</title>
    <link rel="icon" type="image/svg+xml" href="?action=get_app_icon" />
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
      .view-details-header {
        display: flex;
        align-items: flex-end;
        gap: 1.5rem;
        margin-bottom: 2rem;
        padding: 1rem;
        background-color: var(--ytm-surface);
        border-radius: 8px;
      }
      .view-details-header-info {
        min-width: 0;
      }
      .view-details-header img {
        width: 150px;
        height: 150px;
        object-fit: cover;
        border-radius: 6px;
        flex-shrink: 0;
      }
      .view-details-header-info .type {
        font-size: 0.9rem;
        font-weight: 700;
        text-transform: uppercase;
        color: var(--ytm-secondary-text);
      }
      .view-details-header-info .name {
        font-size: 2.5rem;
        font-weight: 700;
        margin: 0.5rem 0;
      }
      .view-details-header-info .stats {
        font-size: 0.9rem;
        color: var(--ytm-secondary-text);
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
        gap: 1rem;
      }
      .header-controls {
        display: flex;
        gap: 1rem;
        align-items: center;
        margin-left: auto;
      }
      #sort-controls {
        display: flex;
        align-items: center;
        gap: 0.5rem;
      }
      #sort-select {
        background-color: var(--ytm-surface-2);
        color: var(--ytm-primary-text);
        border: 1px solid #404040;
        border-radius: 4px;
        padding: 0.25rem 0.5rem;
      }
      .search-bar.input-group {
        width: auto;
        min-width: 250px;
      }
      .search-bar.input-group .form-control {
        background-color: var(--ytm-surface-2);
        border: 1px solid #404040;
        border-right: none;
        color: var(--ytm-primary-text);
        border-radius: 50px 0 0 50px;
        height: 40px;
        box-shadow: none;
        padding-left: 1rem;
      }
      .search-bar.input-group .form-control:focus {
        border-color: #666;
        background-color: var(--ytm-surface-2);
        color: var(--ytm-primary-text);
      }
      .search-bar.input-group .form-control::placeholder {
        color: var(--ytm-secondary-text);
      }
      .search-bar.input-group .btn {
        background-color: var(--ytm-surface-2);
        border: 1px solid #404040;
        color: var(--ytm-secondary-text);
        border-radius: 0 50px 50px 0;
        z-index: 5;
      }
      .search-bar.input-group .btn:hover {
        background-color: #383838;
        color: var(--ytm-primary-text);
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
      .song-item.ghost {
        opacity: 0.4;
      }
      .song-item:hover {
        background-color: var(--ytm-surface-2);
      }
      .song-item .song-title,
      .song-item .song-artist,
      .song-item .song-album,
      .song-artist-name,
      .view-details-header-info .name {
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
      .modal-content {
        background-color: var(--ytm-surface);
        border: none;
        border-radius: 1rem;
      }
      .modal-header {
        border-bottom: 1px solid var(--ytm-surface-2);
      }
      .modal-footer {
        border-top: 1px solid var(--ytm-surface-2);
      }
      .form-control, .form-select {
        background-color: var(--ytm-surface-2);
        border: 1px solid #404040;
        color: var(--ytm-primary-text);
      }
      .form-control:focus, .form-select:focus {
        background-color: var(--ytm-surface-2);
        border-color: #666;
        color: var(--ytm-primary-text);
        box-shadow: none;
      }
      .form-control::placeholder {
        color: var(--ytm-secondary-text);
      }
      #upload-progress-area .progress {
        height: 10px;
      }
      body.logged-out .logged-in-only { display: none !important; }
      body.logged-in .logged-out-only { display: none !important; }
      .text-truncate-width {
        max-width: 600px;
      }
      @media (max-width: 767.98px) {
        .text-truncate-width {
          max-width: 250px;
        }
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
          flex-wrap: wrap;
        }
        .content-title {
          font-size: 1.75rem;
          margin-bottom: 0.5rem;
          width: 100%;
        }
        .header-controls {
          margin-left: 0;
          width: 100%;
          justify-content: flex-end;
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
        .view-details-header {
          flex-direction: column;
          align-items: center;
          text-align: center;
        }
        .view-details-header-info .name {
          font-size: 1.75rem;
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
  <body class="logged-out">
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
          <a href="#" class="nav-link" data-view="albums">
            <i class="bi bi-disc-fill"></i>
            <span>Albums</span>
          </a>
          <a href="#" class="nav-link" data-view="artists">
            <i class="bi bi-people-fill"></i>
            <span>Artists</span>
          </a>
          <a href="#" class="nav-link" data-view="genres">
            <i class="bi bi-tags-fill"></i>
            <span>Genres</span>
          </a>

          <a href="#" class="nav-link logged-in-only" data-view="profile_songs">
            <i class="bi bi-person-circle"></i>
            <span>My Music</span>
          </a>
          <a href="#" class="nav-link logged-in-only" data-view="favorites">
            <i class="bi bi-heart-fill"></i>
            <span>Favorites</span>
          </a>
          
          <hr class="text-secondary">
          
          <a href="#" class="nav-link logged-out-only" data-bs-toggle="modal" data-bs-target="#login-modal">
            <i class="bi bi-box-arrow-in-right"></i>
            <span>Login</span>
          </a>
          <a href="#" class="nav-link logged-out-only" data-bs-toggle="modal" data-bs-target="#register-modal">
            <i class="bi bi-person-plus-fill"></i>
            <span>Register</span>
          </a>
          
          <a href="#" class="nav-link logged-in-only" data-bs-toggle="modal" data-bs-target="#upload-modal">
            <i class="bi bi-cloud-upload-fill"></i>
            <span>Upload Song</span>
          </a>
          <a href="#" class="nav-link logged-in-only" id="scan-btn">
            <i class="bi bi-arrow-repeat"></i>
            <span>Scan Library</span>
          </a>
          <a href="#" class="nav-link logged-in-only" data-bs-toggle="modal" data-bs-target="#settings-modal">
            <i class="bi bi-gear-fill"></i>
            <span>Settings</span>
          </a>
          <a href="#" class="nav-link logged-in-only" id="logout-btn">
            <i class="bi bi-box-arrow-left"></i>
            <span>Logout</span>
          </a>

          <hr class="text-secondary">

          <a href="#" class="nav-link d-none" id="install-pwa-btn">
            <i class="bi bi-cloud-arrow-down-fill"></i>
            <span>Install App</span>
          </a>
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
          <div class="input-group search-bar flex-grow-1">
            <input type="text" class="form-control" id="search-input-mobile" placeholder="Search your music" aria-label="Search your music">
            <button class="btn" type="button" id="search-btn-mobile"><i class="bi bi-search"></i></button>
          </div>
        </div>
        <div class="page-header">
          <h1 id="content-title" class="content-title">Home</h1>
          <div class="header-controls">
            <div class="input-group search-bar d-none d-md-flex">
              <input type="text" class="form-control" id="search-input-desktop" placeholder="Search songs, albums, artists" aria-label="Search songs, albums, artists">
              <button class="btn" type="button" id="search-btn-desktop"><i class="bi bi-search"></i></button>
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
        <div class="player-buttons d-none d-md-flex mt-md-2">
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
         <button class="player-btn logged-in-only" id="favorite-btn-desktop" title="Favorite"></button>
      </div>
    </div>
    <ul class="context-menu" id="context-menu"></ul>

    <div class="modal fade" id="login-modal" tabindex="-1">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title">Login</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <form id="login-form">
              <div class="mb-3">
                <label for="login-email" class="form-label">Email address</label>
                <input type="email" class="form-control" id="login-email" required>
              </div>
              <div class="mb-3">
                <label for="login-password" class="form-label">Password</label>
                <input type="password" class="form-control" id="login-password" required>
              </div>
              <button type="submit" class="btn btn-danger w-100">Login</button>
            </form>
          </div>
        </div>
      </div>
    </div>
    <div class="modal fade" id="register-modal" tabindex="-1">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title">Register</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <form id="register-form">
              <div class="mb-3">
                <label for="register-email" class="form-label">Email address</label>
                <input type="email" class="form-control" id="register-email" required>
              </div>
              <div class="mb-3">
                <label for="register-artist" class="form-label">Artist/Display Name</label>
                <input type="text" class="form-control" id="register-artist" required>
              </div>
              <div class="mb-3">
                <label for="register-password" class="form-label">Password</label>
                <input type="password" class="form-control" id="register-password" required minlength="6">
              </div>
              <button type="submit" class="btn btn-danger w-100">Register</button>
            </form>
          </div>
        </div>
      </div>
    </div>
    <div class="modal fade" id="settings-modal" tabindex="-1">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title">Settings</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <h6>Change Password</h6>
            <form id="change-password-form">
              <div class="mb-3">
                <label for="new-password" class="form-label">New Password</label>
                <input type="password" class="form-control" id="new-password" required minlength="6">
              </div>
              <button type="submit" class="btn btn-danger w-100">Save Password</button>
            </form>
          </div>
        </div>
      </div>
    </div>
    <div class="modal fade" id="upload-modal" tabindex="-1">
      <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title">Upload Music</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <div class="mb-3">
              <label for="song-files" class="form-label">Select songs to upload</label>
              <input class="form-control" type="file" id="song-files" multiple accept="audio/*">
              <small class="form-text text-secondary" id="upload-limit-text"></small>
            </div>
            <div class="mb-3">
              <!-- FIX: Updated label to reflect correct genre logic -->
              <label for="song-genre" class="form-label">Custom Genre (only used if genre tag is missing from the file)</label>
              <input type="text" class="form-control" id="song-genre" placeholder="Pop, Rock, J-Pop">
            </div>
            <button id="start-upload-btn" class="btn btn-danger">Start Upload</button>
            <div id="upload-progress-area" class="mt-3"></div>
          </div>
        </div>
      </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@latest/Sortable.min.js"></script>
    <script>
      document.addEventListener('DOMContentLoaded', () => {
        'use strict';
        const mainContent = document.getElementById('main-content');
        const contentArea = document.getElementById('content-area');
        const contentTitle = document.getElementById('content-title');
        const searchInputDesktop = document.getElementById('search-input-desktop');
        const searchInputMobile = document.getElementById('search-input-mobile');
        const searchBtnDesktop = document.getElementById('search-btn-desktop');
        const searchBtnMobile = document.getElementById('search-btn-mobile');
        const sortControls = document.getElementById('sort-controls');
        const sortSelect = document.getElementById('sort-select');
        const allNavLinks = document.querySelectorAll('.sidebar .nav-link');
        const scanBtn = document.getElementById('scan-btn');
        const scanStatusText = document.getElementById('scan-status-text');
        const scanProgressBar = document.getElementById('scan-progress-bar-container');
        const contextMenu = document.getElementById('context-menu');
        const playerBar = document.getElementById('player-bar');
        const progressContainer = document.getElementById('progress-container');
        const progressBar = document.getElementById('progress-bar');
        const currentTimeEl = document.getElementById('current-time');
        const timeLeftEl = document.getElementById('time-left');
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
        let currentUser = null;
        let currentSong = null;
        let queue = [];
        let originalQueue = [];
        let queueIndex = -1;
        let isPlaying = false;
        let isShuffle = false;
        let repeatMode = 'none';
        let scanInterval;
        let deferredInstallPrompt = null;
        let sortable = null;
        
        let currentPage = 1;
        let isLoadingMore = false;
        let allContentloaded = false;

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
          if (isNaN(seconds) || seconds < 0) return '0:00';
          const min = Math.floor(seconds / 60);
          const sec = Math.floor(seconds % 60).toString().padStart(2, '0');
          return `${min}:${sec}`;
        };

        const fetchData = async (url, options = {}) => {
          try {
            // By default, we don't want to cache API calls on the client side
            // The service worker handles offline capability for static assets.
            options.cache = 'no-store';
            const response = await fetch(url, options);
            if (!response.ok) {
              const errorData = await response.json().catch(() => null);
              const message = errorData ? errorData.message : `HTTP error! status: ${response.status}`;
              throw new Error(message);
            }
            if (response.headers.get("content-type")?.includes("application/json")) {
              return await response.json();
            }
            return await response.text();
          } catch (error) {
            console.error("Fetch error for " + url, error);
            showToast(error.message, 'error');
            return null;
          }
        };

        const showToast = (message, type = 'info') => {
          const toastContainer = document.createElement('div');
          toastContainer.className = `toast-container position-fixed bottom-0 end-0 p-3`;
          toastContainer.style.zIndex = "1100";
          const toastEl = document.createElement('div');
          toastEl.className = `toast align-items-center text-white bg-${type === 'error' ? 'danger' : 'success'} border-0`;
          toastEl.setAttribute('role', 'alert');
          toastEl.setAttribute('aria-live', 'assertive');
          toastEl.setAttribute('aria-atomic', 'true');
          toastEl.innerHTML = `<div class="d-flex"><div class="toast-body">${message}</div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button></div>`;
          
          document.body.appendChild(toastContainer);
          toastContainer.appendChild(toastEl);
          
          const toast = new bootstrap.Toast(toastEl);
          toast.show();
          toastEl.addEventListener('hidden.bs.toast', () => toastContainer.remove());
        };
        
        const showLoader = (isInitial = true) => {
          if (isInitial) {
            contentArea.innerHTML = `<div class="loader">Loading...</div>`;
            contentTitle.classList.remove('d-none');
          } else {
            infiniteScrollLoader.classList.remove('d-none');
          }
        };

        const hideLoader = () => {
          infiniteScrollLoader.classList.add('d-none');
        };
        
        const updateContentTitle = (text, show = true) => {
          if (!show) {
            contentTitle.classList.add('d-none');
            return;
          }
          contentTitle.classList.remove('d-none');
          try {
            const decodedText = decodeURIComponent(text.replace(/\+/g, ' '));
            contentTitle.textContent = decodedText;
            document.title = decodedText + ' - PHP Music';
          } catch (e) {
            contentTitle.textContent = text;
            document.title = text + ' - PHP Music';
          }
        };
        
        const renderViewDetailsHeader = (details, type) => {
          const totalDurationFormatted = formatTime(details.total_duration);
          const headerHTML = `
            <div class="view-details-header">
              <img src="${details.image_url}" alt="${details.name}">
              <div class="view-details-header-info">
                <div class="type">${type}</div>
                <h2 class="name text-truncate text-truncate-width">${details.name}</h2>
                <div class="stats">${details.song_count} songs &bull; ${totalDurationFormatted}</div>
              </div>
            </div>`;
          contentArea.insertAdjacentHTML('afterbegin', headerHTML);
        };

        const renderSongs = (songs, append = false) => {
          if (!append) {
            if (!contentArea.querySelector('.view-details-header')) {
              contentArea.innerHTML = '';
            }
            if (sortable) sortable.destroy();
            sortable = null;
          }

          if (!songs || songs.length === 0) {
            if (!append) {
              contentArea.innerHTML += `<div class="text-center p-5 text-secondary">No songs found.</div>`;
            }
            allContentloaded = true;
            hideLoader();
            return;
          }

          let songList = contentArea.querySelector('.song-list');
          if (!songList) {
            songList = document.createElement('div');
            songList.className = 'song-list';
            const header = `<div class="song-list-header d-none d-md-grid">
              <div>#</div><div>Title</div><div>Artist</div><div>Album</div><div>Time</div><div></div>
            </div>`;
            contentArea.insertAdjacentHTML('beforeend', header);
            contentArea.appendChild(songList);
          }

          const songsHTML = songs.map((song) => {
            const safeTitle = song.title.replace(/'/g, "&apos;").replace(/"/g, "&quot;");
            return `
            <div class="song-item" data-song-id="${song.id}">
              <img src="?action=get_image&id=${song.id}" class="song-thumb" loading="lazy" alt="${safeTitle}">
              <div class="song-title-wrapper"><div class="song-title">${song.title}</div></div>
              <div class="song-artist" data-artist="${encodeURIComponent(song.artist)}">${song.artist}</div>
              <div class="song-album" data-album="${encodeURIComponent(song.album)}">${song.album}</div>
              <div class="song-duration d-none d-md-block">${formatTime(song.duration)}</div>
              <div class="song-more">
                <button class="more-btn" data-song-id="${song.id}" data-user-id="${song.user_id}" data-artist="${encodeURIComponent(song.artist)}" data-album="${encodeURIComponent(song.album)}">
                  <i class="bi bi-three-dots-vertical"></i>
                </button>
              </div>
              <div class="song-artist-mobile d-md-none">
                <span class="song-artist-name">${song.artist}</span>
                <span class="song-duration-mobile">${formatTime(song.duration)}</span>
              </div>
            </div>
          `}).join('');
          
          songList.insertAdjacentHTML('beforeend', songsHTML);

          if (currentView.type === 'favorites' && currentView.sort === 'manual_order') {
            sortable = Sortable.create(songList, {
              animation: 150,
              ghostClass: 'ghost',
              onEnd: async (evt) => {
                const songItems = Array.from(songList.querySelectorAll('.song-item'));
                const newOrderIds = songItems.map(item => item.dataset.songId);
                await fetchData('?action=update_favorite_order', {
                  method: 'POST',
                  headers: { 'Content-Type': 'application/json' },
                  body: JSON.stringify({ ids: newOrderIds })
                });
              }
            });
          }
        };

        const renderGrid = (items, type, append = false) => {
          if (!append) contentArea.innerHTML = '';
          if (!items || items.length === 0) {
            if (!append) {
              contentArea.innerHTML = `<div class="text-center p-5 text-secondary">No ${type}s found.</div>`;
            }
            allContentloaded = true;
            hideLoader();
            return;
          }

          let grid = contentArea.querySelector('.row');
          if (!grid) {
            grid = document.createElement('div');
            grid.className = 'row row-cols-2 row-cols-sm-3 row-cols-md-4 row-cols-lg-5 row-cols-xl-6 g-4';
            contentArea.appendChild(grid);
          }

          const itemsHTML = items.map(item => {
              const name = (typeof item === 'object') ? item.album : item;
              const subtext = (typeof item === 'object') ? item.artist : null;
              const imageId = (typeof item === 'object') ? item.id : null;
              const dataType = type.slice(0, -1);
              const icon = (type === 'artists') ? 'bi-person-fill' : (type === 'genres') ? 'bi-tag-fill' : '';
              
              if (type === 'albums') {
                return `<div class="col">
                  <div class="card h-100 bg-transparent text-white border-0" data-${dataType}="${encodeURIComponent(name)}" style="cursor: pointer;">
                    <img src="?action=get_image&id=${imageId}" class="card-img-top rounded" alt="${name}" style="aspect-ratio: 1/1; object-fit: cover; background-color: var(--ytm-surface-2);">
                    <div class="card-body px-0 py-2">
                      <h5 class="card-title fs-6 fw-normal text-truncate">${name}</h5>
                      ${subtext ? `<p class="card-text small text-secondary text-truncate">${subtext}</p>` : ''}
                    </div>
                  </div>
                </div>`;
              } else {
                return `<div class="col">
                  <div class="card h-100 bg-transparent text-white border-0" data-${dataType}="${encodeURIComponent(name)}" style="cursor: pointer;">
                    <div class="d-flex align-items-center justify-content-center rounded" style="aspect-ratio: 1/1; background-color: var(--ytm-surface-2);">
                      <i class="bi ${icon}" style="font-size: 4rem; color: var(--ytm-secondary-text);"></i>
                    </div>
                    <div class="card-body px-0 py-2">
                      <h5 class="card-title fs-6 fw-normal text-truncate">${name}</h5>
                    </div>
                  </div>
                </div>`;
              }
            }).join('');
          
          grid.insertAdjacentHTML('beforeend', itemsHTML);
        };
        
        const setupSortOptions = (viewType) => {
          sortControls.classList.add('d-none');
          if (['songs', 'favorites', 'artist_songs', 'album_songs', 'genre_songs', 'profile_songs', 'search'].includes(viewType)) {
            let options = {
              'artist_asc': 'Artist', 'title_asc': 'Title', 'album_asc': 'Album',
              'year_desc': 'Year (Newest)', 'year_asc': 'Year (Oldest)',
            };
            if (viewType === 'favorites') {
              options = { 'manual_order': 'My Order', ...options };
            }
            if (viewType === 'artist_songs') delete options.artist_asc;
            if (viewType === 'album_songs') {
              delete options.artist_asc;
              delete options.album_asc;
            }

            sortSelect.innerHTML = Object.entries(options)
              .map(([value, text]) => `<option value="${value}" ${currentView.sort === value ? 'selected' : ''}>${text}</option>`).join('');
            sortControls.classList.remove('d-none');
          }
        };

        const loadMoreContent = async () => {
          if (isLoadingMore || allContentloaded) return;
          isLoadingMore = true;
          showLoader(false);
          
          currentPage++;
          let url, data;
          const { type, param, sort } = currentView;
          
          switch(type) {
            case 'songs':
            case 'artist_songs':
            case 'album_songs':
            case 'genre_songs':
              let filter = type.endsWith('_songs') ? `&${type.split('_')[0]}=${encodeURIComponent(param)}` : '';
              url = `?action=get_songs&page=${currentPage}&sort=${sort}${filter}`;
              data = await fetchData(url);
              renderSongs(data, true);
              break;
            case 'profile_songs':
              url = `?action=get_profile_songs&page=${currentPage}&sort=${sort}`;
              data = await fetchData(url);
              renderSongs(data, true);
              break;
            case 'favorites':
              url = `?action=get_favorites&page=${currentPage}&sort=${sort}`;
              data = await fetchData(url);
              renderSongs(data, true);
              break;
            case 'search':
              url = `?action=search&q=${encodeURIComponent(param)}&page=${currentPage}`;
              data = await fetchData(url);
              renderSongs(data, true);
              break;
            case 'albums':
            case 'artists':
            case 'genres':
              url = `?action=get_${type}&page=${currentPage}`;
              data = await fetchData(url);
              renderGrid(data, type, true);
              break;
            default:
              allContentloaded = true;
          }
          
          if (!data || data.length < 25) {
            allContentloaded = true;
          }

          isLoadingMore = false;
          hideLoader();
        };

        const loadView = async (viewConfig) => {
          mainContent.scrollTop = 0;
          currentPage = 1;
          allContentloaded = false;
          isLoadingMore = false;
          showLoader();

          currentView = viewConfig;
          setupSortOptions(currentView.type);

          let data;
          let viewName = currentView.type.charAt(0).toUpperCase() + currentView.type.slice(1);
          
          switch (currentView.type) {
            case 'songs':
              updateContentTitle('All Songs');
              data = await fetchData(`?action=get_songs&sort=${currentView.sort}&page=1`);
              renderSongs(data, false);
              break;
            case 'profile_songs':
              updateContentTitle('My Music');
              data = await fetchData(`?action=get_profile_songs&sort=${currentView.sort}&page=1`);
              renderSongs(data, false);
              break;
            case 'favorites':
              updateContentTitle('Favorites');
              data = await fetchData(`?action=get_favorites&sort=${currentView.sort}&page=1`);
              renderSongs(data, false);
              break;
            case 'albums':
            case 'artists':
            case 'genres':
              updateContentTitle(viewName);
              data = await fetchData(`?action=get_${currentView.type}&page=1`);
              renderGrid(data, currentView.type, false);
              break;
            case 'artist_songs':
            case 'album_songs':
            case 'genre_songs':
              const type = currentView.type.split('_')[0];
              const name = decodeURIComponent(currentView.param);
              updateContentTitle(name, false);
              const details = await fetchData(`?action=get_view_details&type=${type}&name=${encodeURIComponent(name)}`);
              contentArea.innerHTML = '';
              if (details) renderViewDetailsHeader(details, type);
              data = await fetchData(`?action=get_songs&${type}=${currentView.param}&sort=${currentView.sort}&page=1`);
              renderSongs(data, false);
              break;
            case 'search':
              updateContentTitle(`Search: "${currentView.param}"`);
              data = await fetchData(`?action=search&q=${encodeURIComponent(currentView.param)}&page=1`);
              renderSongs(data, false);
              break;
          }

          if (!data || data.length < 25) {
            allContentloaded = true;
            hideLoader();
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
              artwork: [{ src: currentSong.image_url, sizes: '250x250', type: 'image/webp' }]
            });
            navigator.mediaSession.setActionHandler('play', togglePlayPause);
            navigator.mediaSession.setActionHandler('pause', togglePlayPause);
            navigator.mediaSession.setActionHandler('previoustrack', playPrev);
            navigator.mediaSession.setActionHandler('nexttrack', playNext);
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
          updateFavoriteIcons(currentSong.is_favorite);
        };

        const updatePlayPauseIcons = () => {
          const icon = isPlaying ? ICONS.pause : ICONS.play;
          [playPauseBtnDesktop, playPauseBtnMobile].forEach(btn => {
            btn.innerHTML = icon;
            btn.title = isPlaying ? "Pause" : "Play";
          });
          if ('mediaSession' in navigator) {
            navigator.mediaSession.playbackState = isPlaying ? "playing" : "paused";
          }
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
        
        const updateFavoriteIcons = (isFav) => {
          const icon = isFav ? ICONS.heartFill : ICONS.heart;
          favoriteBtnDesktop.innerHTML = icon;
          favoriteBtnDesktop.classList.toggle('active', isFav);
        };
        
        const toggleFavorite = async (songId) => {
          if (!currentUser) {
            showToast('Please log in to add favorites.', 'error');
            return;
          }
          const result = await fetchData('?action=toggle_favorite', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: songId })
          });
          if (result) {
            if (currentSong && currentSong.id === songId) {
              currentSong.is_favorite = result.is_favorite;
              updateFavoriteIcons(result.is_favorite);
            }
            if (currentView.type === 'favorites') {
              // A small delay to allow the toast to show before reloading the view
              setTimeout(() => loadView(currentView), 100);
            }
            showToast(result.status === 'added' ? 'Added to favorites' : 'Removed from favorites', 'success');
          }
        };

        const showContextMenu = async (e, buttonEl) => {
          e.preventDefault(); e.stopPropagation();
          const songId = parseInt(buttonEl.dataset.songId);
          const songUserId = parseInt(buttonEl.dataset.userId);
          const { artist, album } = buttonEl.dataset;
          
          let menuItems = `
            <li class="context-menu-item" data-action="go_artist" data-name="${artist}"><i class="bi bi-person-fill"></i> Go to Artist</li>
            <li class="context-menu-item" data-action="go_album" data-name="${album}"><i class="bi bi-disc-fill"></i> Go to Album</li>`;
          
          if (currentUser) {
            const songData = await fetchData(`?action=get_song_data&id=${songId}`);
            if (songData) {
              const favText = songData.is_favorite ? "Remove from Favorites" : "Add to Favorites";
              const favIcon = songData.is_favorite ? ICONS.heartFill : ICONS.heart;
              menuItems += `<li class="context-menu-item" data-action="toggle_favorite" data-id="${songId}">${favIcon} ${favText}</li>`;
              menuItems += `<li class="context-menu-item" data-action="download_song" data-id="${songId}"><i class="bi bi-download"></i> Download Song</li>`;
              if (currentUser.id === songUserId || currentUser.artist === 'Music Library') {
                // FIX: Added Edit Genre option
                menuItems += `<li class="context-menu-item" data-action="edit_genre" data-id="${songId}"><i class="bi bi-tag-fill"></i> Edit Genre</li>`;
                menuItems += `<li class="context-menu-item text-danger" data-action="delete_song" data-id="${songId}"><i class="bi bi-trash-fill"></i> Delete Song</li>`;
              }
            }
          }
          contextMenu.innerHTML = menuItems;
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
          if (queue.length > 0 && currentSong) {
            const currentSongId = currentSong.id;
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
          showToast(isShuffle ? 'Shuffle enabled' : 'Shuffle disabled', 'info');
        };

        // This function builds the correct playback queue based on the current view (All Songs, Album, Artist, etc.)
        // and starts playing the selected song.
        const setQueueAndPlay = async (startId) => {
          const allIds = await fetchData('?action=get_view_ids', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
              view_type: currentView.type,
              param: currentView.param,
              sort: currentView.sort,
            })
          });

          if (!allIds || allIds.length === 0) { return; }

          originalQueue = allIds;
          queue = [...originalQueue];
          if (isShuffle) { 
             isShuffle = false; toggleShuffle(); // Reshuffle the new queue
          }
          queueIndex = queue.findIndex(id => id === startId);
          if (queueIndex === -1) { return; }
          playSongById(startId);
        };

        allNavLinks.forEach(link => {
          if (link.id === 'logout-btn' || link.id === 'scan-btn' || link.getAttribute('data-bs-toggle') === 'modal' || link.id === 'install-pwa-btn') return;
          link.addEventListener('click', e => {
            e.preventDefault();
            const navLink = e.currentTarget;
            allNavLinks.forEach(l => l.classList.remove('active'));
            navLink.classList.add('active');
            
            const viewType = navLink.dataset.view;
            let sort = 'artist_asc';
            if (viewType === 'favorites') sort = 'manual_order';
            if (viewType === 'profile_songs') sort = 'title_asc';

            loadView({ type: viewType, param: '', sort: sort });
            const offcanvasEl = document.getElementById('main-nav-offcanvas');
            if (window.innerWidth < 768 && offcanvasEl) {
              const offcanvas = bootstrap.Offcanvas.getInstance(offcanvasEl);
              if (offcanvas) offcanvas.hide();
            }
          });
        });

        const performSearch = (query) => {
          if (query.trim() !== '') {
            loadView({ type: 'search', param: query.trim(), sort: 'artist_asc' });
          }
        };
        searchInputDesktop.addEventListener('keyup', (e) => { if (e.key === 'Enter') performSearch(e.target.value); });
        searchInputMobile.addEventListener('keyup', (e) => { if (e.key === 'Enter') performSearch(e.target.value); });
        searchBtnDesktop.addEventListener('click', () => performSearch(searchInputDesktop.value));
        searchBtnMobile.addEventListener('click', () => performSearch(searchInputMobile.value));

        sortSelect.addEventListener('change', (e) => {
          loadView({ ...currentView, sort: e.target.value });
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
              showToast(data.message, data.status === 'error' ? 'error' : 'success');
              if (currentView.type === 'songs') {
                loadView(currentView);
              }
            } else if (data) {
              scanStatusText.textContent = data.message;
            }
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
        favoriteBtnDesktop.addEventListener('click', () => {
          if (currentSong) toggleFavorite(currentSong.id);
        });

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
            loadView({ type: 'artist_songs', param: songArtistEl.dataset.artist, sort: 'album_asc' });
            return;
          }
          const songAlbumEl = target.closest('.song-album');
          if (songAlbumEl) {
            e.stopPropagation();
            loadView({ type: 'album_songs', param: songAlbumEl.dataset.album, sort: 'title_asc' });
            return;
          }
          const cardEl = target.closest('.card');
          if (cardEl) {
             let viewType, param, sort;
             if (cardEl.dataset.artist) {
               viewType = 'artist_songs';
               param = cardEl.dataset.artist;
               sort = 'album_asc';
             } else if (cardEl.dataset.album) {
               viewType = 'album_songs';
               param = cardEl.dataset.album;
               sort = 'title_asc';
             } else if (cardEl.dataset.genre) {
               viewType = 'genre_songs';
               param = cardEl.dataset.genre;
               sort = 'artist_asc';
             }
             if (viewType) {
               loadView({ type: viewType, param: param, sort: sort });
             }
             return;
          }
          const songItem = target.closest('.song-item');
          if (songItem) {
            const songId = parseInt(songItem.dataset.songId);
            setQueueAndPlay(songId);
          }
        });
        
        document.addEventListener('click', e => {
          if (!contextMenu.contains(e.target)) contextMenu.style.display = 'none';
        });

        contextMenu.addEventListener('click', async e => {
          const item = e.target.closest('.context-menu-item');
          if (!item) return;
          const { action, name, id } = item.dataset;
          contextMenu.style.display = 'none';

          switch (action) {
            case 'go_artist':
              loadView({ type: 'artist_songs', param: name, sort: 'album_asc' });
              break;
            case 'go_album':
              loadView({ type: 'album_songs', param: name, sort: 'title_asc' });
              break;
            case 'toggle_favorite':
              toggleFavorite(parseInt(id));
              break;
            case 'edit_genre':
              const songIdToEdit = parseInt(id);
              const songData = await fetchData(`?action=get_song_data&id=${songIdToEdit}`);
              if (!songData) break;
              const newGenre = prompt("Enter the new genre for this song:", songData.genre || "");
              if (newGenre !== null && newGenre.trim() !== '') {
                  const result = await fetchData('?action=edit_genre', {
                      method: 'POST',
                      headers: { 'Content-Type': 'application/json' },
                      body: JSON.stringify({ id: songIdToEdit, genre: newGenre.trim() })
                  });
                  if (result && result.status === 'success') {
                      showToast('Genre updated!', 'success');
                      loadView(currentView);
                  }
              }
              break;
            case 'download_song':
              window.location.href = `?action=download_song&id=${id}`;
              break;
            case 'delete_song':
              if (confirm('Are you sure you want to delete this song? This cannot be undone.')) {
                const result = await fetchData('?action=delete_song', {
                  method: 'POST',
                  headers: { 'Content-Type': 'application/json' },
                  body: JSON.stringify({ id: parseInt(id) })
                });
                if (result.status === 'success') {
                  showToast('Song deleted successfully.', 'success');
                  loadView(currentView);
                }
              }
              break;
          }
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
        
        mainContent.addEventListener('scroll', () => {
          if (mainContent.scrollTop + mainContent.clientHeight >= mainContent.scrollHeight - 300) {
            loadMoreContent();
          }
        });

        window.addEventListener('beforeinstallprompt', (e) => {
          e.preventDefault();
          deferredInstallPrompt = e;
          installPwaBtn.classList.remove('d-none');
        });

        installPwaBtn.addEventListener('click', async (e) => {
          e.preventDefault();
          if (!deferredInstallPrompt) return;
          deferredInstallPrompt.prompt();
          await deferredInstallPrompt.userChoice;
          deferredInstallPrompt = null;
          installPwaBtn.classList.add('d-none');
        });
        
        const loginForm = document.getElementById('login-form');
        const registerForm = document.getElementById('register-form');
        const changePwForm = document.getElementById('change-password-form');
        const logoutBtn = document.getElementById('logout-btn');

        loginForm.addEventListener('submit', async e => {
          e.preventDefault();
          const email = document.getElementById('login-email').value;
          const password = document.getElementById('login-password').value;
          const data = await fetchData('?action=login', {
            method: 'POST', headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ email, password })
          });
          if (data && data.status === 'success') {
            bootstrap.Modal.getInstance(document.getElementById('login-modal')).hide();
            loginForm.reset();
            showToast('Login successful!', 'success');
            await checkSession();
          }
        });

        registerForm.addEventListener('submit', async e => {
          e.preventDefault();
          const email = document.getElementById('register-email').value;
          const artist = document.getElementById('register-artist').value;
          const password = document.getElementById('register-password').value;
          const data = await fetchData('?action=register', {
            method: 'POST', headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ email, artist, password })
          });
          if (data && data.status === 'success') {
            bootstrap.Modal.getInstance(document.getElementById('register-modal')).hide();
            registerForm.reset();
            showToast(data.message, 'success');
          }
        });

        logoutBtn.addEventListener('click', async e => {
          e.preventDefault();
          await fetchData('?action=logout');
          currentUser = null;
          updateUIForAuthState();
          loadView({ type: 'songs', param: '', sort: 'artist_asc' });
        });

        changePwForm.addEventListener('submit', async e => {
          e.preventDefault();
          const new_password = document.getElementById('new-password').value;
          const data = await fetchData('?action=change_password', {
            method: 'POST', headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ new_password })
          });
          if(data && data.status === 'success') {
            bootstrap.Modal.getInstance(document.getElementById('settings-modal')).hide();
            changePwForm.reset();
            showToast(data.message, 'success');
          }
        });

        const uploadLimitText = document.getElementById('upload-limit-text');
        const songFilesInput = document.getElementById('song-files');
        const songGenreInput = document.getElementById('song-genre');
        const startUploadBtn = document.getElementById('start-upload-btn');
        const uploadProgressArea = document.getElementById('upload-progress-area');
        let filesToUpload = [];

        songFilesInput.addEventListener('change', () => { filesToUpload = Array.from(songFilesInput.files); });
        
        startUploadBtn.addEventListener('click', async () => {
          if (filesToUpload.length === 0) {
            showToast('Please select files to upload.', 'error'); return;
          }
          startUploadBtn.disabled = true;
          uploadProgressArea.innerHTML = '';

          for (let i = 0; i < filesToUpload.length; i++) {
            const file = filesToUpload[i];
            const progressId = `progress-${i}`;
            uploadProgressArea.innerHTML += `
              <div class="mb-2">
                <small>${file.name}</small>
                <div class="progress"><div id="${progressId}" class="progress-bar" role="progressbar" style="width: 0%">0%</div></div>
              </div>`;

            const formData = new FormData();
            formData.append('song', file);
            formData.append('genre', songGenreInput.value);

            const xhr = new XMLHttpRequest();
            xhr.open('POST', '?action=upload_song', true);
            xhr.upload.onprogress = (e) => {
              if (e.lengthComputable) {
                const percentComplete = Math.round((e.loaded / e.total) * 100);
                const progressBar = document.getElementById(progressId);
                progressBar.style.width = `${percentComplete}%`;
                progressBar.textContent = `${percentComplete}%`;
              }
            };
            
            await new Promise(resolve => {
              xhr.onload = () => {
                const progressBar = document.getElementById(progressId);
                if (xhr.status === 200) {
                  progressBar.classList.add('bg-success');
                } else {
                  progressBar.classList.add('bg-danger');
                  progressBar.textContent = 'Error';
                  try {
                    showToast(`Upload failed for ${file.name}: ${JSON.parse(xhr.responseText).message}`, 'error');
                  } catch(e) {
                     showToast(`Upload failed for ${file.name}: Server error.`, 'error');
                  }
                }
                resolve();
              };
              xhr.onerror = () => {
                document.getElementById(progressId).classList.add('bg-danger');
                document.getElementById(progressId).textContent = 'Error';
                showToast(`A network error occurred during upload of ${file.name}.`, 'error');
                resolve();
              };
              xhr.send(formData);
            });
          }
          startUploadBtn.disabled = false;
          showToast('All uploads complete.', 'success');
          loadView(currentView);
          filesToUpload = [];
          songFilesInput.value = '';
          songGenreInput.value = '';
        });

        function updateUIForAuthState() {
          document.body.classList.toggle('logged-in', !!currentUser);
          document.body.classList.toggle('logged-out', !currentUser);
        }

        async function checkSession() {
          const data = await fetchData('?action=get_session');
          if (data && data.status === 'loggedin') {
            currentUser = data.user;
            uploadLimitText.textContent = data.upload_limit;
          } else {
            currentUser = null;
          }
          updateUIForAuthState();
          loadView({ type: 'songs', param: '', sort: 'artist_asc' });
        }

        const init = () => {
          if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('?pwa=sw').catch(err => console.error('SW registration failed:', err));
          }
          [prevBtnDesktop, prevBtnMobile].forEach(b => b.innerHTML = ICONS.prev);
          [nextBtnDesktop, nextBtnMobile].forEach(b => b.innerHTML = ICONS.next);
          [shuffleBtnDesktop, shuffleBtnMobile].forEach(b => b.innerHTML = ICONS.shuffle);
          favoriteBtnDesktop.innerHTML = ICONS.heart;
          updatePlayPauseIcons();
          updateRepeatIcons();
          updateShuffleButtons();
          checkSession();
        };

        init();
      });
    </script>
  </body>
</html>