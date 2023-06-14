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
    <title><?php echo $selectedAlbum; ?></title>
  </head>

  <body>
    <?php include('header.php'); ?>
    <div class="container-fluid">
      <?php if ($selectedAlbum !== ''): ?>
        <h5 class="text-start fw-semibold"><i class="bi bi-people-fill"></i> Album: <?php echo $selectedAlbum; ?> <button class="btn text-white" onclick="shareAlbum()"><i class="bi bi-share-fill"></i></button></h5>
      <?php endif; ?> 
      <?php foreach ($songList as $song): ?>
        <?php if ($selectedAlbum === '' || $selectedAlbum === $song['album']): ?>
          <div class="d-flex justify-content-between align-items-center border-bottom">
            <a class="text-decoration-none music text-start w-100 text-white btn fw-bold" href="music.php?id=<?php echo $song['index']; ?>">
              <?php echo $song['songName']; ?>
              <br>
              <small class="text-muted"><?php echo $song['artist']; ?></small>
            </a>
            <div class="dropdown dropdown-menu-end">
              <button class="text-decoration-none text-white btn fw-bold" type="button" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-three-dots-vertical"></i></button>
              <ul class="dropdown-menu">
                <li><button class="dropdown-item fw-semibold" onclick="sharePage('<?php echo $song['index']; ?>', '<?php echo $song['songName']; ?>')"><i class="bi bi-share-fill"></i> share</button></li>
                <li><a class="dropdown-item fw-semibold" href="artist.php?name=<?php echo $song['artist']; ?>"><i class="bi bi-person-fill"></i> show artist</a></li>
              </ul>
            </div>
          </div>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>
    <div style="margin-bottom: 250px;"></div>
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
    <script>
      function sharePage(musicId, songName) {
        if (navigator.share) {
          const shareUrl = window.location.origin + '/music.php?id=' + musicId;
          navigator.share({
            title: songName,
            url: shareUrl
          }).then(() => {
            console.log('Page shared successfully.');
          }).catch((error) => {
            console.error('Error sharing page:', error);
          });
        } else {
          console.log('Web Share API not supported.');
        }
      }
    </script>
    <script>
      function shareAlbum() {
        if (navigator.share) {
          navigator.share({
            title: document.title,
            url: window.location.href
          }).then(() => {
            console.log('Page shared successfully.');
          }).catch((error) => {
            console.error('Error sharing page:', error);
          });
        } else {
          console.log('Web Share API not supported.');
        }
      }
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" integrity="sha384-geWF76RCwLtnZ8qwWowPQNguL3RmwHVBC9FhGdlKrxdiJJigb/j/68SIy3Te4Bkz" crossorigin="anonymous"></script>
  </body>
</html>