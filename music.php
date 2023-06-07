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
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.3/font/bootstrap-icons.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-GLhlTQ8iRABdZLl6O3oVMWSktQOp6b7In1Zl3/Jr59b6EGGoI1aFkw7cmDA6j6gD" crossorigin="anonymous">
    <link rel="stylesheet" href="plyr.css">
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
        <div class="w-100 bg-dark fixed-bottom border-2 border-top">
          <div class="d-flex justify-content-between align-items-center">
            <div class="w-100">
              <audio id="player" controls>
                <source src="<?= $file ?>" type="audio/mpeg">
                Your browser does not support the audio element.
              </audio>
            </div>
            <div class="btn-group">
              <a href="music.php?id=<?= $previousId ?>" class="btn float-end fw-bold mt-1" style="color: #4A5464;"><i class="bi bi-skip-start-circle fs-3"></i></a>
              <a href="music.php?id=<?= $nextId ?>" class="btn float-end fw-bold mt-1" style="color: #4A5464;"><i class="bi bi-skip-end-circle fs-3"></i></a>
            </div>
          </div>
        </div>
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
    <br><br>
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

    async function transcodeAudio(file, desiredBitrate) {
      const ffmpeg = createFFmpeg({ log: true });

      await ffmpeg.load();

      const inputPath = `/input/${file.name}`;
      const outputPath = `/output/${file.name}`;

      ffmpeg.FS('writeFile', inputPath, await fetchFile(file));

      await ffmpeg.run('-i', inputPath, '-b:a', desiredBitrate, outputPath);

      const transcodedData = ffmpeg.FS('readFile', outputPath);
      const transcodedBlob = new Blob([transcodedData.buffer], { type: 'audio/mpeg' });

      // Handle the transcoded audio data (e.g., play it in the browser or offer it as a download)
      const transcodedUrl = URL.createObjectURL(transcodedBlob);
      player.source = { type: 'audio', sources: [{ src: transcodedUrl, type: 'audio/mpeg' }] };

      ffmpeg.FS('unlink', inputPath);
      ffmpeg.FS('unlink', outputPath);
    }

    async function fetchFile(file) {
      return new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onload = () => resolve(new Uint8Array(reader.result));
        reader.onerror = reject;
        reader.readAsArrayBuffer(file);
      });
    }

    async function handleTranscode(event) {
      const fileInput = event.target;
      const file = fileInput.files[0];
      const desiredBitrate = '128k'; // Specify the desired bitrate for transcoding here

      if (file) {
        await transcodeAudio(file, desiredBitrate);
      }
    }

    const inputElement = document.createElement('input');
    inputElement.type = 'file';
    inputElement.accept = 'audio/mpeg';
    inputElement.onchange = handleTranscode;
    inputElement.hidden = true;
    document.body.appendChild(inputElement);

    function openFilePicker() {
      inputElement.click();
    }
  </script> 
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" integrity="sha384-geWF76RCwLtnZ8qwWowPQNguL3RmwHVBC9FhGdlKrxdiJJigb/j/68SIy3Te4Bkz" crossorigin="anonymous"></script>
  </body>
</html> 