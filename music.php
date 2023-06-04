<?php
require_once 'getID3/getid3/getid3.php';
$musicDir = 'music/';
$id = $_GET['id'];
$musicFiles = glob($musicDir . '*.mp3');

if (isset($musicFiles[$id])) {
  $file = $musicFiles[$id];
  $getID3 = new getID3();

  $fileInfo = $getID3->analyze($file);
  getid3_lib::CopyTagsToComments($fileInfo);

  $title = !empty($fileInfo['comments_html']['title']) ? implode(', ', $fileInfo['comments_html']['title']) : 'Unknown';
  $artist = !empty($fileInfo['comments_html']['artist']) ? implode(', ', $fileInfo['comments_html']['artist']) : 'Unknown';
  $album = !empty($fileInfo['comments_html']['album']) ? implode(', ', $fileInfo['comments_html']['album']) : 'Unknown';
  $duration = !empty($fileInfo['playtime_string']) ? $fileInfo['playtime_string'] : 'Unknown';

  $imageData = !empty($fileInfo['comments']['picture'][0]['data']) ? $fileInfo['comments']['picture'][0]['data'] : null;
  $imageMime = !empty($fileInfo['comments']['picture'][0]['image_mime']) ? $fileInfo['comments']['picture'][0]['image_mime'] : null;

  $previousId = $id > 0 ? $id - 1 : count($musicFiles) - 1;
  $nextId = $id < (count($musicFiles) - 1) ? $id + 1 : 0;
} else {
  echo '<p>Invalid music ID.</p>';
  exit();
}
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
  <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.3/font/bootstrap-icons.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-GLhlTQ8iRABdZLl6O3oVMWSktQOp6b7In1Zl3/Jr59b6EGGoI1aFkw7cmDA6j6gD" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.plyr.io/3.6.8/plyr.css">
    <title><?= $title ?></title>
  </head>
  <body>
    <div class="container-fluid">
      <a class="btn btn-sm btn-info text-white rounded-pill fw-bold mb-2 mt-2" href="index.php"><i class="bi bi-chevron-left"></i> back</a>
      <div class="row featurette">
        <div class="col-md-5 order-md-1 mb-5">

        <h2 class="text-center fw-bold display-5"><?= $title ?></h2>
        <p class="text-center fw-bold">
          <a class="text-decoration-none text-white" href="artist.php?name=<?php echo $artist; ?>"><?php echo $artist; ?></a> - 
          <a class="text-decoration-none text-white" href="album.php?album=<?php echo $album; ?>"><?php echo $album; ?></a>
        </p> 
        <?php if ($imageData && $imageMime): ?>
          <div class="text-center mb-2">
            <img src="data:<?= $imageMime ?>;base64,<?= base64_encode($imageData) ?>" alt="Song Image" class="img-fluid rounded shadow">
          </div>
        <?php else: ?>
          <div class="text-center mb-2">
            <img src="icon/bg.png" alt="Placeholder Image" class="img-fluid rounded shadow">
          </div>
        <?php endif; ?> 

        <div class="w-100 bg-white rounded">
          <div class="container-fluid">
            <audio id="player" controls>
              <source src="<?= $file ?>" type="audio/mpeg">
              Your browser does not support the audio element.
            </audio>
          </div>
        </div>
        <a href="music.php?id=<?= $previousId ?>" class="btn btn-info text-white float-start fw-bold mt-2"><i class="bi bi-arrow-left-circle-fill"></i> Prev</a>
        <a href="music.php?id=<?= $nextId ?>" class="btn btn-info text-white float-end fw-bold mt-2">Next <i class="bi bi-arrow-right-circle-fill"></i></a>
      </div>
      
      <div class="col-md-7 order-md-2">
        <h3 class="text-start fw-semibold"><i class="bi bi-music-note-list"></i> song list</h3>
        <table class="table table-borderless">
          <thead>
            <tr>
              <th>Song</th>
              <th>Artist</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($musicFiles as $index => $musicFile): ?>
              <?php
                $fileInfo = $getID3->analyze($musicFile);
                getid3_lib::CopyTagsToComments($fileInfo);
                $songName = !empty($fileInfo['comments_html']['title']) ? implode(', ', $fileInfo['comments_html']['title']) : 'Unknown';
                $songArtist = !empty($fileInfo['comments_html']['artist']) ? implode(', ', $fileInfo['comments_html']['artist']) : 'Unknown';
              ?>
              <tr>
                <td>
                  <?php if ($index == $id): ?>
                    <span class="text-decoration-none music text-start w-100 text-white btn fw-semibold"><?= $songName ?></span>
                  <?php else: ?>
                    <a class="text-decoration-none music text-start w-100 text-white btn fw-semibold" href="music.php?id=<?= $index ?>"><?= $songName ?></a>
                  <?php endif; ?>
                </td>
                <td>
                  <a class="text-decoration-none music text-start w-100 text-white btn fw-semibold" href="artist.php?name=<?php echo $songArtist; ?>"><?php echo $songArtist; ?></a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <script src="https://cdn.plyr.io/3.6.8/plyr.js"></script>
    <script>
      document.addEventListener('DOMContentLoaded', function () {
        const player = new Plyr('#player');

        // Autoplay the player when the page loads
        player.play();

        player.on('ended', function(event) {
          // Redirect to the next song URL
          window.location.href = 'music.php?id=<?= $nextId ?>';
        });
      });
    </script> 
  </body>
</html> 