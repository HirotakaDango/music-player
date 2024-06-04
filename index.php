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
$itemsPerPage = 100;
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
    <div class="container mt-4">
      <header class="d-flex flex-wrap justify-content-center py-3 mb-4">
        <a href="/" class="d-flex align-items-center mb-3 mb-md-0 me-md-auto link-body-emphasis text-decoration-none">
          <h1 class="text-center fw-bold"><i class="bi bi-play-circle-fill"></i> Music Library</h1>
        </a>

        <ul class="nav nav-pills">
          <li class="nav-item"><a href="/" class="nav-link text-white fw-semibold <?php if(basename($_SERVER['PHP_SELF']) == 'index.php' && (!isset($_GET['artist']) && !isset($_GET['album']) && !isset($_GET['artists']) && !isset($_GET['albums']))) echo 'active' ?>"><i class="bi bi-house-fill"></i> Home</a></li>
          <li class="nav-item"><a href="?artists=all&page=<?php echo isset($_GET['page']) ? $_GET['page'] : 1; ?>" class="nav-link text-white fw-semibold <?php if(isset($_GET['artists']) && $_GET['artists'] === 'all') echo 'active' ?>"><i class="bi bi-people-fill"></i> Artists</a></li>
          <li class="nav-item"><a href="?albums=all&page=<?php echo isset($_GET['page']) ? $_GET['page'] : 1; ?>" class="nav-link text-white fw-semibold <?php if(isset($_GET['albums']) && $_GET['albums'] === 'all') echo 'active' ?>"><i class="bi bi-disc-fill"></i> Albums</a></li>
        </ul>
      </header>
    </div>
    <div class="container mb-5">
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
        <h6 class="fw-bold mb-5">
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
            <a class="hide-scrollbar link-body-emphasis text-decoration-none music text-start w-100 text-white btn fw-bold border-0" href="play.php?artist=<?php echo urlencode($song['artist']); ?>&album=<?php echo urlencode($song['album']); ?>&title=<?php echo urlencode($song['songName']); ?>&page=<?php echo isset($_GET['page']) ? $_GET['page'] : 1; ?>" style="overflow-x: auto; white-space: nowrap;">
              <?php echo $song['songName']; ?><br>
              <small class="text-muted"><?php echo $song['artist']; ?> - <?php echo $song['album']; ?></small><br>
              <small class="text-muted">Playtime : <?php echo $song['duration']; ?></small>
            </a>
            <div class="dropdown dropdown-menu-end">
              <button class="text-decoration-none text-white btn fw-bold border-0" type="button" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-three-dots-vertical"></i></button>
              <ul class="dropdown-menu rounded-4">
                <li><a class="dropdown-item fw-medium" href="?artist=<?php echo urlencode($song['artist']); ?>"><i class="bi bi-person-fill"></i> show artist</a></li>
                <li><a class="dropdown-item fw-medium" href="?album=<?php echo urlencode($song['album']); ?>"><i class="bi bi-cloud-arrow-down-fill"></i> show album</a></li>
                <li><a class="dropdown-item fw-medium" href="<?php echo $song['file']; ?>" download><i class="bi bi-disc-fill"></i> download</a></li>
              </ul>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
    <div class="container mb-5">
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
  </body>
</html>