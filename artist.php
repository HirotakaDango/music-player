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
  }

  $songList[] = array(
    'index' => $index,
    'songName' => $songName,
    'artist' => $artist,
    'album' => $album
  );
}

// Get the artist name from the query parameter
$artistName = $_GET['name'] ?? '';

// Filter the song list based on the artist name
$filteredSongs = array_filter($songList, function($song) use ($artistName) {
  return $song['artist'] === $artistName;
});
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
  <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.3/font/bootstrap-icons.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-GLhlTQ8iRABdZLl6O3oVMWSktQOp6b7In1Zl3/Jr59b6EGGoI1aFkw7cmDA6j6gD" crossorigin="anonymous">
    <title>Music Player</title>
  </head>

  <body>
    <?php include('header.php'); ?>
    <div class="container-fluid">
      <h5 class="text-start fw-semibold"><i class="bi bi-people-fill"></i> Artist: <?php echo $artistName; ?></h5>
      <table class="table table-borderless">
        <thead>
          <tr>
            <th>Song</th>
            <th>Artist</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($filteredSongs as $song): ?>
            <tr>
              <td><a class="text-decoration-none music text-start w-100 text-white btn fw-semibold" href="music.php?id=<?php echo $song['index']; ?>"><?php echo $song['songName']; ?></a></td>
              <td><a href="#" class="btn border-0 disabled text-white fw-semibold"><?php echo $song['artist']; ?></a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <br><br>
  </body>
</html>