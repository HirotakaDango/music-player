<?php
require_once 'getID3/getid3/getid3.php';
$musicDir = 'music/';
$musicFiles = glob($musicDir . '*.mp3');

$getID3 = new getID3();

$songList = array();
foreach ($musicFiles as $index => $file) {
  $path_parts = pathinfo($file);
  $songName = 'Unknown';
  $artist = 'Unknown';
  $album = 'Unknown';
  $duration = 'Unknown';

  $tags = $getID3->analyze($file);

  if (isset($tags['tags']['id3v2'])) {
    $id3v2Tags = $tags['tags']['id3v2'];

    if (isset($id3v2Tags['title'][0])) {
      $songName = $id3v2Tags['title'][0];
    }

    if (isset($id3v2Tags['artist'][0])) {
      $artist = $id3v2Tags['artist'][0];
    }

    if (isset($id3v2Tags['album'][0])) {
      $album = $id3v2Tags['album'][0];
    }

    if (isset($tags['playtime_string'])) {
      $duration = $tags['playtime_string'];
    }
  }

  $songList[] = array(
    'index' => $index,
    'songName' => $songName,
    'artist' => $artist,
    'album' => $album,
    'duration' => $duration,
    'file' => $file
  );
}

// Search filter
if (isset($_GET['q'])) {
  $searchQuery = strtolower($_GET['q']);
  $songList = array_filter($songList, function($song) use ($searchQuery) {
    return strpos(strtolower($song['artist']), $searchQuery) !== false || strpos(strtolower($song['album']), $searchQuery) !== false;
  });
}

// Filter songs by artist
if (isset($_GET['artist'])) {
  $artist = $_GET['artist'];
  $songList = array_filter($songList, function($song) use ($artist) {
    return $song['artist'] === $artist;
  });
}

// Filter songs by album
if (isset($_GET['album'])) {
  $album = $_GET['album'];
  $songList = array_filter($songList, function($song) use ($album) {
    return $song['album'] === $album;
  });
}

// Get all unique albums
$albums = [];
if (isset($_GET['albums']) && $_GET['albums'] === 'all') {
  $albums = array_unique(array_column($songList, 'album'));
}

// Get all unique artists
$artists = [];
if (isset($_GET['artists']) && $_GET['artists'] === 'all') {
  $artists = array_unique(array_column($songList, 'artist'));
}

// Sort the song list by name ASC for albums=all and artists=all
if (isset($_GET['albums']) && $_GET['albums'] === 'all') {
  sort($albums);
}
if (isset($_GET['artists']) && $_GET['artists'] === 'all') {
  sort($artists);
}

// Sort the song list by artist, album, and title
usort($songList, function($a, $b) {
  $artistCompare = strcmp($a['artist'], $b['artist']);
  if ($artistCompare !== 0) {
    return $artistCompare;
  }

  $albumCompare = strcmp($a['album'], $b['album']);
  if ($albumCompare !== 0) {
    return $albumCompare;
  }

  return strcmp($a['songName'], $b['songName']);
});

// Pagination settings
$itemsPerPage = 20;
$totalSongs = count($songList);
$totalPages = ceil($totalSongs / $itemsPerPage);
$currentPage = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$currentPage = max(1, min($totalPages, $currentPage));
$offset = ($currentPage - 1) * $itemsPerPage;
$songsToShow = array_slice($songList, $offset, $itemsPerPage);
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
  <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php include('bootstrapcss.php'); ?>
    <title>Music Player</title>
  </head>
  <body>
    <nav class="navbar navbar-expand-lg fixed-top bg-body-tertiary">
      <div class="container-fluid">
        <a class="navbar-brand fw-bold" href="/">Music</a>
        <button class="navbar-toggler border-0 d-md-none" type="button" data-bs-toggle="collapse" data-bs-target="#navbarSupportedContent" aria-controls="navbarSupportedContent" aria-expanded="false" aria-label="Toggle navigation">
          <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarSupportedContent">
          <ul class="navbar-nav ms-auto mb-2 mb-lg-0 d-md-none">
            <li class="nav-item">
              <a class="nav-link <?php if(basename($_SERVER['PHP_SELF']) == 'index.php' && (!isset($_GET['artist']) && !isset($_GET['album']) && !isset($_GET['artists']) && !isset($_GET['albums']))) echo 'active' ?>" href="/">Home</a>
            </li>
            <li class="nav-item">
              <a class="nav-link <?php if(isset($_GET['artists']) && $_GET['artists'] === 'all') echo 'active' ?>" href="#">Artists</a>
            </li>
            <li class="nav-item">
              <a class="nav-link <?php if(isset($_GET['albums']) && $_GET['albums'] === 'all') echo 'active' ?>" href="#">Albums</a>
            </li>
            <form class="input-group mt-3" role="search" action="/" name="q">
              <input class="form-control bg-dark-subtle border-0 focus-ring focus-ring-dark rounded-start-5" type="search" placeholder="Search" aria-label="Search" value="<?php echo isset($_GET['q']) ? $_GET['q'] : ''; ?>">
              <button class="btn bg-dark-subtle rounded-end-5" type="submit"><i class="bi bi-search"></i></button>
            </form>
          </ul>
        </div>
      </div>
    </nav>
    <div class="container-fluid">
      <div class="row g-3">
        <div class="col-md-3 d-none d-md-block">
          <form class="input-group mt-5 pt-4" role="search" action="/" name="q">
            <input class="form-control bg-dark-subtle border-0 focus-ring focus-ring-dark rounded-start-5" type="search" placeholder="Search" aria-label="Search" value="<?php echo isset($_GET['q']) ? $_GET['q'] : ''; ?>">
            <button class="btn bg-dark-subtle rounded-end-5" type="submit"><i class="bi bi-search"></i></button>
          </form>
          <div class="btn-group-vertical gap-2 w-100 mt-2">
            <a class="btn border-0 text-start p-3 fw-bold rounded-4 <?php if(basename($_SERVER['PHP_SELF']) == 'index.php' && (!isset($_GET['artist']) && !isset($_GET['album']) && !isset($_GET['artists']) && !isset($_GET['albums']))) echo 'bg-dark-subtle' ?>" href="/"><i class="bi bi-house-fill"></i> Home</a>
            <a class="btn border-0 text-start p-3 fw-bold rounded-4 <?php if(isset($_GET['artists']) && $_GET['artists'] === 'all') echo 'bg-dark-subtle' ?>" href="?artists=all&page=<?php echo isset($_GET['page']) ? $_GET['page'] : 1; ?>"><i class="bi bi-people-fill"></i> Artists</a>
            <a class="btn border-0 text-start p-3 fw-bold rounded-4 <?php if(isset($_GET['albums']) && $_GET['albums'] === 'all') echo 'bg-dark-subtle' ?>" href="?albums=all&page=<?php echo isset($_GET['page']) ? $_GET['page'] : 1; ?>"><i class="bi bi-disc-fill"></i> Albums</a>
          </div>
        </div>
        <div class="col-md-9 overflow-auto vh-100">
        <div class="mb-5">
          <?php if (isset($_GET['albums']) && $_GET['albums'] === 'all'): ?>
            <h2 class="fw-bold mb-5">Albums</h2>
            <ul>
              <?php foreach ($albums as $album): ?>
                <li class="my-3"><a class="fw-bold text-white text-decoration-none link-body-emphasis" href="?album=<?php echo urlencode($album); ?>"><?php echo $album; ?></a></li>
              <?php endforeach; ?>
            </ul>
          <?php elseif (isset($_GET['artists']) && $_GET['artists'] === 'all'): ?>
            <h2 class="fw-bold mb-5">Artists</h2>
            <ul>
              <?php foreach ($artists as $artist): ?>
                <li class="my-3"><a class="fw-bold text-white text-decoration-none link-body-emphasis" href="?artist=<?php echo urlencode($artist); ?>"><?php echo $artist; ?></a></li>
              <?php endforeach; ?>
            </ul>
          <?php else: ?>
            <h6 class="fw-bold mt-5 pt-4 mb-3">
              Showing <?php echo count($songList); ?> songs
              <?php if (isset($_GET['artist'])): ?>
                by <?php echo htmlspecialchars($_GET['artist']); ?>
              <?php endif; ?>
              <?php if (isset($_GET['album'])): ?>
                in album: <?php echo htmlspecialchars($_GET['album']); ?>
              <?php endif; ?>
            </h6>
            <?php foreach ($songsToShow as $song): ?>
              <div class="d-flex justify-content-between align-items-center rounded-4 bg-dark-subtle bg-opacity-10 my-2">
                <a class="hide-scrollbar link-body-emphasis text-decoration-none music text-start w-100 text-white btn fw-bold border-0" 
                  href="play.php?artist=<?php echo urlencode($song['artist']); ?>&album=<?php echo urlencode($song['album']); ?>&title=<?php echo urlencode($song['songName']); ?>&back=<?php echo urlencode('http' . (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 's' : '') . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']); ?>" 
                  style="overflow-x: auto; white-space: nowrap;">
                  <?php echo $song['songName']; ?><br>
                  <small class="text-muted"><?php echo $song['artist']; ?> - <?php echo $song['album']; ?></small><br>
                  <small class="text-muted">Playtime : <?php echo $song['duration']; ?></small>
                </a>
                <div class="dropdown dropdown-menu-end">
                  <button class="text-decoration-none text-white btn fw-bold border-0" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="bi bi-three-dots-vertical"></i>
                  </button>
                  <ul class="dropdown-menu rounded-4">
                    <li><a class="dropdown-item fw-medium" href="?artist=<?php echo urlencode($song['artist']); ?>"><i class="bi bi-person-fill"></i> Show artist</a></li>
                    <li><a class="dropdown-item fw-medium" href="?album=<?php echo urlencode($song['album']); ?>"><i class="bi bi-cloud-arrow-down-fill"></i> Show album</a></li>
                    <li><a class="dropdown-item fw-medium" href="#" data-bs-toggle="modal" data-bs-target="#shareSong<?php echo $song['index']; ?>"><i class="bi bi-share-fill"></i> Share</a></li>
                    <li><a class="dropdown-item fw-medium" href="<?php echo $song['file']; ?>" download><i class="bi bi-disc-fill"></i> Download</a></li>
                  </ul>
                </div>
                <?php
                  $domain = $_SERVER['HTTP_HOST'];
                  $songId = $song['index'];
                  $url = "http://$domain/play.php?artist=" . urlencode($song['artist']) . "&album=" . urlencode($song['album']) . "&title=" . urlencode($song['songName']);
                ?>
                <div class="modal fade" id="shareSong<?php echo $songId; ?>" tabindex="-1" aria-labelledby="exampleModalLabel" aria-hidden="true">
                  <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content bg-transparent border-0 rounded-0">
                      <div class="card rounded-4 p-4">
                        <p class="text-start fw-bold">Share to:</p>
                        <div class="btn-group w-100 mb-2" role="group" aria-label="Share Buttons">
                          <!-- Twitter -->
                          <a class="btn rounded-start-4" href="https://twitter.com/intent/tweet?url=<?php echo $url; ?>" target="_blank" rel="noopener noreferrer">
                            <i class="bi bi-twitter"></i>
                          </a>
                          <!-- Line -->
                          <a class="btn" href="https://social-plugins.line.me/lineit/share?url=<?php echo $url; ?>" target="_blank" rel="noopener noreferrer">
                            <i class="bi bi-line"></i>
                          </a>
                          <!-- Email -->
                          <a class="btn" href="mailto:?body=<?php echo $url; ?>">
                            <i class="bi bi-envelope-fill"></i>
                          </a>
                          <!-- Reddit -->
                          <a class="btn" href="https://www.reddit.com/submit?url=<?php echo $url; ?>" target="_blank" rel="noopener noreferrer">
                            <i class="bi bi-reddit"></i>
                          </a>
                          <!-- Instagram -->
                          <a class="btn" href="https://www.instagram.com/?url=<?php echo $url; ?>" target="_blank" rel="noopener noreferrer">
                            <i class="bi bi-instagram"></i>
                          </a>
                          <!-- Facebook -->
                          <a class="btn rounded-end-4" href="https://www.facebook.com/sharer/sharer.php?u=<?php echo $url; ?>" target="_blank" rel="noopener noreferrer">
                            <i class="bi bi-facebook"></i>
                          </a>
                        </div>
                        <div class="btn-group w-100 mb-2" role="group" aria-label="Share Buttons">
                          <!-- WhatsApp -->
                          <a class="btn rounded-start-4" href="https://wa.me/?text=<?php echo $url; ?>" target="_blank" rel="noopener noreferrer">
                            <i class="bi bi-whatsapp"></i>
                          </a>
                          <!-- Pinterest -->
                          <a class="btn" href="https://pinterest.com/pin/create/button/?url=<?php echo $url; ?>" target="_blank" rel="noopener noreferrer">
                            <i class="bi bi-pinterest"></i>
                          </a>
                          <!-- LinkedIn -->
                          <a class="btn" href="https://www.linkedin.com/shareArticle?url=<?php echo $url; ?>" target="_blank" rel="noopener noreferrer">
                            <i class="bi bi-linkedin"></i>
                          </a>
                          <!-- Messenger -->
                          <a class="btn" href="https://www.facebook.com/dialog/send?link=<?php echo $url; ?>&app_id=YOUR_FACEBOOK_APP_ID" target="_blank" rel="noopener noreferrer">
                            <i class="bi bi-messenger"></i>
                          </a>
                          <!-- Telegram -->
                          <a class="btn" href="https://telegram.me/share/url?url=<?php echo $url; ?>" target="_blank" rel="noopener noreferrer">
                            <i class="bi bi-telegram"></i>
                          </a>
                          <!-- Snapchat -->
                          <a class="btn rounded-end-4" href="https://www.snapchat.com/share?url=<?php echo $url; ?>" target="_blank" rel="noopener noreferrer">
                            <i class="bi bi-snapchat"></i>
                          </a>
                        </div>
                        <div class="input-group">
                          <input type="text" id="urlInput<?php echo $songId; ?>" value="<?php echo $url; ?>" class="form-control border-2 fw-bold" readonly>
                          <button class="btn btn-secondary opacity-50 fw-bold" onclick="copyToClipboard('<?php echo $songId; ?>')">
                            <i class="bi bi-clipboard-fill"></i>
                          </button>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
                <script>
                  function copyToClipboard(id) {
                    var urlInput = document.getElementById('urlInput' + id);
                    urlInput.select();
                    urlInput.setSelectionRange(0, 99999); // For mobile devices
                    document.execCommand('copy');
                  }
                </script>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
        <div class="pb-5">
          <?php if (!isset($_GET['albums']) && !isset($_GET['artists'])): ?>
            <nav aria-label="Page navigation">
              <ul class="pagination justify-content-center">
                <li class="page-item <?php if($currentPage <= 1) echo 'disabled'; ?>">
                  <a class="page-link text-white" href="?<?php echo http_build_query(array_merge($_GET, ['page' => 1])); ?>"><i class="bi bi-chevron-double-left text-stroke"></i></a>
                </li>
                <li class="page-item <?php if($currentPage <= 1) echo 'disabled'; ?>">
                  <a class="page-link text-white" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $currentPage - 1])); ?>"><i class="bi bi-chevron-left text-stroke"></i></a>
                </li>
                <?php for($page = max(1, $currentPage - 2); $page <= min($totalPages, $currentPage + 2); $page++): ?>
                  <li class="page-item fw-bold <?php if($page == $currentPage) echo 'active'; ?>"><a class="page-link text-white" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page])); ?>"><?php echo $page; ?></a></li>
                <?php endfor; ?>
                <li class="page-item <?php if($currentPage >= $totalPages) echo 'disabled'; ?>">
                  <a class="page-link text-white" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $currentPage + 1])); ?>"><i class="bi bi-chevron-right text-stroke"></i></a>
                </li>
                <li class="page-item <?php if($currentPage >= $totalPages) echo 'disabled'; ?>">
                  <a class="page-link text-white" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $totalPages])); ?>"><i class="bi bi-chevron-double-right text-stroke"></i></a>
                </li>
              </ul>
            </nav>
          <?php endif; ?>
        </div>
        </div>
      </div>
    </div>
  </body>
</html>