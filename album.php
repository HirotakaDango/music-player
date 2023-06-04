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

// Get the unique album names
$albumList = array_unique(array_column($songList, 'album'));

if (isset($_GET['album'])) {
  $selectedAlbum = $_GET['album'];
} else {
  $selectedAlbum = '';
}
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
  <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.3/font/bootstrap-icons.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-GLhlTQ8iRABdZLl6O3oVMWSktQOp6b7In1Zl3/Jr59b6EGGoI1aFkw7cmDA6j6gD" crossorigin="anonymous">
    <title><?php echo $song['album']; ?></title>
  </head>

  <body>
    <div class="container">
      <?php if ($selectedAlbum !== ''): ?>
        <h1 class="text-center fw-bold mt-3"><a class="text-decoration-none text-white" href="index.php"><i class="bi bi-play-circle-fill"></i> Music Library</a> - <?php echo $selectedAlbum; ?></h1>
      <?php endif; ?> 
      <div class="input-group mb-3 mt-3">
        <input type="text" class="form-control me-2 ms-2 fw-semibold" placeholder="Search song" id="search-input">
      </div>
      <table class="table table-borderless">
        <thead>
          <tr>
            <th>Song</th>
            <th>Artist</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($songList as $song): ?>
            <?php if ($selectedAlbum === '' || $selectedAlbum === $song['album']): ?>
              <tr>
                <td><a class="text-decoration-none music text-start w-100 text-white btn fw-semibold" href="music.php?id=<?php echo $song['index']; ?>"><?php echo $song['songName']; ?></a></td>
                <td><a class="text-decoration-none music text-start w-100 text-white btn fw-semibold" href="artist.php?name=<?php echo $song['artist']; ?>"><?php echo $song['artist']; ?></a></td>
              </tr>
            <?php endif; ?>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <script>
      // Get the search input element
      const searchInput = document.getElementById('search-input');

      // Get all the tag buttons
      const tagButtons = document.querySelectorAll('.music');

      // Add an event listener to the search input field
      searchInput.addEventListener('input', () => {
        const searchTerm = searchInput.value.toLowerCase();

        // Filter the tag buttons based on the search term
        tagButtons.forEach(button => {
          const tag = button.textContent.toLowerCase();

          if (tag.includes(searchTerm)) {
            button.style.display = 'inline-block';
          } else {
            button.style.display = 'none';
          }
        });
      });
    </script>
  </body>
</html>