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
    'duration' => $duration
  );
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

// Count the music files
$musicCount = count($songList);
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
  <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php include('bootstrapcss.php'); ?>
    <link rel="stylesheet" href="style.css">
    <title>Music Player</title>
  </head>
  <body>
    <div class="container my-4">
      <a href="/" class="text-white text-decoration-none link-body-emphasis">
        <h1 class="text-center fw-bold"><i class="bi bi-play-circle-fill"></i> Music Library</h1>
      </a>
    </div>
    <div class="container mb-5">
      <?php foreach ($songList as $song): ?>
        <div class="d-flex justify-content-between align-items-center rounded-4 bg-dark-subtle bg-opacity-10 my-2">
          <a class="hide-scrollbar link-body-emphasis text-decoration-none music text-start w-100 text-white btn fw-bold border-0" href="play.php?artist=<?php echo urlencode($song['artist']); ?>&album=<?php echo urlencode($song['album']); ?>&title=<?php echo urlencode($song['songName']); ?>" style="overflow-x: auto; white-space: nowrap;">
            <?php echo $song['songName']; ?><br>
            <small class="text-muted"><?php echo $song['artist']; ?> - <?php echo $song['album']; ?></small><br>
            <small class="text-muted">Playtime : <?php echo $song['duration']; ?></small>
          </a>
          <div class="dropdown dropdown-menu-end">
            <button class="text-decoration-none text-white btn fw-bold border-0" type="button" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-three-dots-vertical"></i></button>
            <ul class="dropdown-menu rounded-4">
              <li><a class="dropdown-item fw-medium" href="<?php echo $song['file']; ?>" download><i class="bi bi-cloud-arrow-down-fill"></i> download</a></li>
            </ul>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </body>
</html>
