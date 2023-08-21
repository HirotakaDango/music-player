<?php
require_once 'getID3/getid3/getid3.php';
$musicDir = 'music/';
$musicFiles = glob($musicDir . '*.mp3');

$getID3 = new getID3();

$albumList = array();
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

  $albumList[] = array(
    'index' => $index,
    'songName' => $songName,
    'artist' => $artist,
    'album' => $album
  );
}

// Get unique albums from the song list
$albums = array_unique(array_column($albumList, 'album'));

// Sort the albums alphabetically
sort($albums, SORT_LOCALE_STRING);

// Create an array to hold the albums sorted by category
$albumsByCategory = array();

// Group albums by the first letter of their name
foreach ($albums as $album) {
  $firstLetter = mb_strtoupper(mb_substr($album, 0, 1));

  if (!isset($albumsByCategory[$firstLetter])) {
    $albumsByCategory[$firstLetter] = array();
  }

  $albumsByCategory[$firstLetter][] = $album;
}

// Sort the categories alphabetically
ksort($albumsByCategory, SORT_LOCALE_STRING);
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
  <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php include('bootstrapcss.php'); ?>
    <title>All Albums</title>
  </head>
  <body>
    <?php include('header.php'); ?>
    <div class="container-fluid">
      <div class="input-group mb-3 mt-3">
        <input type="text" class="form-control me-2 ms-2 fw-semibold" placeholder="Search album" id="search-input">
      </div>
      <?php foreach ($albumsByCategory as $category => $categoryAlbums): ?>
        <h5 class="text-start fw-semibold">Category <?php echo $category; ?></h5>
        <div class="row">
          <?php foreach ($categoryAlbums as $album): ?>
            <div class="col-md-3 col-sm-6">
              <a class="opacity-75 music btn tag-button btn-outline-light mb-2 fw-bold text-start w-100" href="album.php?album=<?php echo urlencode($album); ?>"><?php echo $album; ?></a>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
    </div>
    <br><br>
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
    <?php include('bootstrapjs.php'); ?>
  </body>
</html>
