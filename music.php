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

function formatBytes($bytes, $precision = 2) {
  $units = ['B', 'KB', 'MB', 'GB', 'TB'];
  $bytes = max($bytes, 0);
  $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
  $pow = min($pow, count($units) - 1);
  $bytes /= (1 << (10 * $pow));
  return round($bytes, $precision) . ' ' . $units[$pow];
}

$bitrate = !empty($fileInfo['audio']['bitrate']) ? round($fileInfo['audio']['bitrate'] / 1000) . 'kbps' : 'Unknown';
$size = !empty($fileInfo['filesize']) ? formatBytes($fileInfo['filesize']) : 'Unknown';
$audioType = !empty($fileInfo['fileformat']) ? $fileInfo['fileformat'] : 'Unknown';
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
  <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php include('bootstrapcss.php'); ?>
    <link rel="stylesheet" href="plyr.css">
    <title><?= $title ?></title>
  </head>
  <body>
    <div class="container-fluid">
      <div class="mb-3">
        <a class="btn text-white rounded-pill fw-bold position-absolute start-0 top-0 ms-2 mt-2" href="index.php">
          <i class="bi bi-chevron-down" style="-webkit-text-stroke: 2px white;"></i>
        </a>
        <button class="btn text-white rounded-pill fw-bold position-absolute end-0 top-0 me-2 mt-2" onclick="sharePage()">
          <i class="bi bi-share-fill" style="-webkit-text-stroke: 1px white;"></i>
        </button>
      </div>
      <div class="row featurette mt-5">
        <div class="col-md-5 order-md-1 mb-5">
          <h4 class="text-center fw-bold display-5"><?= $title ?></h4>
          <p class="text-center fw-bold">
            <a class="text-decoration-none text-white" href="artist.php?name=<?= $artist ?>"><?php echo $artist; ?></a> -
            <a class="text-decoration-none text-white" href="album.php?album=<?= $album ?>"><?php echo $album; ?></a>
          </p>
          <div class="position-relative">
            <?php if ($imageData && $imageMime): ?>
              <div class="text-center mb-2">
                <img src="data:<?= $imageMime ?>;base64,<?= base64_encode($imageData) ?>" alt="Song Image" class="img-fluid rounded shadow">
              </div>
            <?php else: ?>
              <div class="text-center mb-2">
                <img src="icon/bg.png" alt="Placeholder Image" class="img-fluid rounded shadow">
              </div>
            <?php endif; ?>
            <button type="button" class="btn btn-dark opacity-50 position-absolute top-0 start-0 mt-1 ms-1 rounded-1" data-bs-toggle="modal" data-bs-target="#songInfo">
              <i class="bi bi-info-circle-fill"></i>
            </button>
          </div>
          <div class="modal fade" id="songInfo" tabindex="-1" aria-labelledby="exampleModalLabel" aria-hidden="true">
            <div class="modal-dialog">
              <div class="modal-content">
                <div class="modal-header">
                  <h1 class="modal-title fs-5 fw-bold" id="exampleModalLabel"><?= $title ?></h1>
                  <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                  <div class="metadata">
                    <p class="fw-semibold text-start">Artist: <?= $artist ?></p>
                    <p class="fw-semibold text-start">Album: <?= $album ?></p>
                    <p class="fw-semibold text-start">Duration: <?= $duration ?></p>
                    <p class="fw-semibold text-start">Bitrate: <?= $bitrate ?></p>
                    <p class="fw-semibold text-start">Size: <?= $size ?></p>
                    <p class="fw-semibold text-start">Audio Type: <?= $audioType ?></p>
                    <p class="fw-semibold text-start">Image Type: <?= $imageMime ?></p>
                    <a class="btn btn-primary fw-semibold w-100" href="<?= $file ?>" download>Download Song</a>
                  </div>
                </div>
              </div>
            </div>
          </div>
          <div class="d-md-none d-lg-none mt-5">
            <div class="d-flex justify-content-center btn-group">
              <a href="music.php?id=<?= $previousId ?>" class="btn float-end text-white"><i class="bi bi-skip-start-circle display-1"></i></a>
              <button class="text-decoration-none btn text-white d-md-none d-lg-none" data-bs-toggle="modal" data-bs-target="#exampleModal"><i class="bi bi-music-note-list display-1"></i></button>
              <a href="music.php?id=<?= $nextId ?>" class="btn float-end text-white"><i class="bi bi-skip-end-circle display-1"></i></a>
            </div>
          </div>
          <div class="w-100 bg-dark fixed-bottom border-2 border-top">
            <div class="d-flex justify-content-between align-items-center">
              <div class="w-100">
                <audio id="player" controls>
                  <source src="<?= $file ?>" type="audio/mpeg">
                  Your browser does not support the audio element.
                </audio>
              </div>
              <div class="d-none d-md-block d-lg-block">
                <div class="btn-group">
                  <a href="music.php?id=<?= $previousId ?>" class="btn float-end fw-bold mt-1" style="color: #4A5464;"><i class="bi bi-skip-start-circle fs-3"></i></a>
                  <a href="music.php?id=<?= $nextId ?>" class="btn float-end fw-bold mt-1" style="color: #4A5464;"><i class="bi bi-skip-end-circle fs-3"></i></a>
                </div>
              </div>
            </div>
          </div>
        </div>
        <div class="col-md-7 order-md-2">
          <div class="modal fade d-md-none d-lg-none" id="exampleModal" tabindex="-1" aria-labelledby="exampleModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
              <div class="modal-content">
                <div class="modal-header">
                  <h1 class="modal-title fw-bold fs-5" id="exampleModalLabel"><i class="bi bi-music-note-list"></i> song list</h1>
                  <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                  <?php foreach ($musicFiles as $index => $musicFile): ?>
                    <?php
                      $fileInfo = $getID3->analyze($musicFile);
                      getid3_lib::CopyTagsToComments($fileInfo);
                      $songName = !empty($fileInfo['comments_html']['title']) ? implode(', ', $fileInfo['comments_html']['title']) : 'Unknown';
                      $songArtist = !empty($fileInfo['comments_html']['artist']) ? implode(', ', $fileInfo['comments_html']['artist']) : 'Unknown';
                      $songAlbum = !empty($fileInfo['comments_html']['album']) ? implode(', ', $fileInfo['comments_html']['album']) : 'Unknown';
                    ?>
                    <div class="d-flex justify-content-between align-items-center border-bottom">
                      <a class="text-decoration-none music text-start w-100 text-white btn fw-bold" href="music.php?id=<?= $index ?>">
                        <?= $songName ?><br>
                        <small class="text-muted"><?= $songArtist ?></small>
                      </a>
                      <div class="dropdown dropdown-menu-end">
                        <button class="text-decoration-none text-white btn fw-bold" type="button" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-three-dots-vertical"></i></button>
                        <ul class="dropdown-menu">
                          <li><button class="dropdown-item" onclick="sharePageS('<?= $index ?>', '<?= $songName ?>')"><i class="bi bi-share-fill"></i> share</button></li>
                          <li><a class="dropdown-item" href="artist.php?name=<?= $songArtist ?>"><i class="bi bi-person-fill"></i> show artist</a></li>
                          <li><a class="dropdown-item" href="album.php?album=<?= $songAlbum ?>"><i class="bi bi-disc-fill"></i> show album</a></li>
                        </ul>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
              </div>
            </div>
          </div> 
          <div class="d-none d-md-block d-lg-block">
            <h3 class="text-start fw-semibold"><i class="bi bi-music-note-list"></i> song list</h3>
            <?php foreach ($musicFiles as $index => $musicFile): ?>
              <?php
                $fileInfo = $getID3->analyze($musicFile);
                getid3_lib::CopyTagsToComments($fileInfo);
                $songName = !empty($fileInfo['comments_html']['title']) ? implode(', ', $fileInfo['comments_html']['title']) : 'Unknown';
                $songArtist = !empty($fileInfo['comments_html']['artist']) ? implode(', ', $fileInfo['comments_html']['artist']) : 'Unknown';
                $songAlbum = !empty($fileInfo['comments_html']['album']) ? implode(', ', $fileInfo['comments_html']['album']) : 'Unknown';
              ?>
              <div class="d-flex justify-content-between align-items-center border-bottom">
                <a class="text-decoration-none music text-start w-100 text-white btn fw-bold" href="music.php?id=<?= $index ?>">
                  <?= $songName ?><br>
                  <small class="text-muted"><?= $songArtist ?></small>
                </a>
                <div class="dropdown dropdown-menu-end">
                  <button class="text-decoration-none text-white btn fw-bold" type="button" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-three-dots-vertical"></i></button>
                  <ul class="dropdown-menu">
                    <li><button class="dropdown-item" onclick="sharePageS('<?= $index ?>', '<?= $songName ?>')"><i class="bi bi-share-fill"></i> share</button></li>
                    <li><a class="dropdown-item" href="artist.php?name=<?= $songArtist ?>"><i class="bi bi-person-fill"></i> show artist</a></li>
                    <li><a class="dropdown-item" href="album.php?album=<?= $songAlbum ?>"><i class="bi bi-disc-fill"></i> show album</a></li>
                  </ul>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>
    <div class="d-none d-md-block d-lg-block" style="margin-bottom: 250px;"></div>
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
    <script>
      function sharePage() {
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
    <script>
      function sharePageS(musicId, songName) {
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
    <script src="https://cdn.plyr.io/3.6.8/plyr.js"></script>
    <?php include('bootstrapjs.php'); ?>
  </body>
</html>