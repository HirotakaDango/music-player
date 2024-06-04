<?php
require_once 'getID3/getid3/getid3.php';
$musicDir = 'music/';
$artist = isset($_GET['artist']) ? $_GET['artist'] : null;
$album = isset($_GET['album']) ? $_GET['album'] : null;
$title = isset($_GET['title']) ? $_GET['title'] : null;
$page = isset($_GET['page']) ? $_GET['page'] : null;
$musicFiles = glob($musicDir . '*.mp3');

$getID3 = new getID3();

$songList = array();
foreach ($musicFiles as $file) {
  $path_parts = pathinfo($file);
  $songName = 'Unknown';
  $artistName = 'Unknown';
  $albumName = 'Unknown';
  $duration = 'Unknown';

  $tags = $getID3->analyze($file);

  if (isset($tags['tags']['id3v2'])) {
    $id3v2Tags = $tags['tags']['id3v2'];

    if (isset($id3v2Tags['title'][0])) {
      $songName = $id3v2Tags['title'][0];
    }

    if (isset($id3v2Tags['artist'][0])) {
      $artistName = $id3v2Tags['artist'][0];
    }

    if (isset($id3v2Tags['album'][0])) {
      $albumName = $id3v2Tags['album'][0];
    }
  }

  if (isset($tags['playtime_string'])) {
    $duration = $tags['playtime_string'];
  }

  $songList[] = array(
    'file' => $file,
    'songName' => $songName,
    'artist' => $artistName,
    'album' => $albumName,
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

$currentSong = null;
$currentIndex = null;
foreach ($songList as $index => $song) {
  if (strcasecmp($song['artist'], $artist) == 0 && strcasecmp($song['album'], $album) == 0 && strcasecmp($song['songName'], $title) == 0) {
    $currentSong = $song;
    $currentIndex = $index;
    break;
  }
}

if ($currentSong) {
  $fileInfo = $getID3->analyze($currentSong['file']);
  getid3_lib::CopyTagsToComments($fileInfo);

  $title = !empty($fileInfo['comments_html']['title']) ? mb_convert_encoding(implode(', ', $fileInfo['comments_html']['title']), 'UTF-8', 'auto') : 'Unknown';
  $artist = !empty($fileInfo['comments_html']['artist']) ? mb_convert_encoding(implode(', ', $fileInfo['comments_html']['artist']), 'UTF-8', 'auto') : 'Unknown';
  $album = !empty($fileInfo['comments_html']['album']) ? mb_convert_encoding(implode(', ', $fileInfo['comments_html']['album']), 'UTF-8', 'auto') : 'Unknown';
  $duration = !empty($fileInfo['playtime_string']) ? $fileInfo['playtime_string'] : 'Unknown';

  $imageData = !empty($fileInfo['comments']['picture'][0]['data']) ? $fileInfo['comments']['picture'][0]['data'] : null;
  $imageMime = !empty($fileInfo['comments']['picture'][0]['image_mime']) ? $fileInfo['comments']['picture'][0]['image_mime'] : null;

  $previousIndex = $currentIndex > 0 ? $currentIndex - 1 : count($songList) - 1;
  $nextIndex = $currentIndex < (count($songList) - 1) ? $currentIndex + 1 : 0;

  $previousSong = $songList[$previousIndex];
  $nextSong = $songList[$nextIndex];

  $previousUrl = "play.php?artist=" . urlencode($previousSong['artist']) . "&album=" . urlencode($previousSong['album']) . "&title=" . urlencode($previousSong['songName']) . '&page=' . $page;
  $nextUrl = "play.php?artist=" . urlencode($nextSong['artist']) . "&album=" . urlencode($nextSong['album']) . "&title=" . urlencode($nextSong['songName']) . '&page=' . $page;
} else {
  echo '<p>Song not found.</p>';
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
    <title><?php echo $title; ?></title>
    <link rel="icon" type="image/png" href="data:<?php echo $imageMime; ?>;base64,<?php echo base64_encode($imageData); ?>">
    <meta property="og:image" content="data:<?php echo $imageMime; ?>;base64,<?php echo base64_encode($imageData); ?>"/>
    <meta property="og:title" content="<?php echo $title; ?>"/>
    <meta property="og:description" content="<?php echo $album; ?>"/>
    <meta property="og:type" content="website"/>
    <meta property="og:url" content="<?php echo 'http' . (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 's' : '') . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']; ?>">
    <script>
      const player = document.getElementById('player');
      let currentTrackId = <?= $currentIndex ?>; // Corrected variable name
      let isSeeking = false;
    
      navigator.mediaSession.setActionHandler('previoustrack', function() {
        const previousTrackUrl = '<?php echo $previousUrl; ?>';
        window.location.href = previousTrackUrl;
      });
    
      navigator.mediaSession.setActionHandler('nexttrack', function() {
        const nextTrackUrl = '<?php echo $nextUrl; ?>';
        window.location.href = nextTrackUrl;
      });
    
      // Set metadata for the currently playing media
      const setMediaMetadata = () => {
        console.log('Cover Path:', coverPath);
    
        navigator.mediaSession.metadata = new MediaMetadata({
          title: '<?= htmlspecialchars($title) ?>',
          artist: '<?= htmlspecialchars($artist) ?>',
          album: '<?= htmlspecialchars($album) ?>', // Corrected missing closing parenthesis
        });
      };
    </script>
    <style>
      /* For Webkit-based browsers */
      ::-webkit-scrollbar {
        width: 0;
        height: 0;
        border-radius: 10px;
      }

      ::-webkit-scrollbar-track {
        border-radius: 0;
      }

      ::-webkit-scrollbar-thumb {
        border-radius: 0;
      }
      
      .text-stroke {
        -webkit-text-stroke: 3px;
      }

      .text-shadow {
        text-shadow: 1px 1px 2px rgba(0, 0, 0, 0.4), 2px 2px 4px rgba(0, 0, 0, 0.3), 3px 3px 6px rgba(0, 0, 0, 0.2);
      }
      
      @media (max-width: 767px) {
        .fs-custom {
          font-size: 3.5em;
        }
      }
      
      @media (min-width: 768px) {
        .fs-custom {
          font-size: 3em;
        }
      }

      .fs-custom-2 {
        font-size: 1.3em;
      }

      .fs-custom-3 {
        font-size: 2.4em;
      }

      .custom-bg::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100vh;
        background: url('data:<?php echo $imageMime; ?>;base64,<?php echo base64_encode($imageData); ?>') center/cover no-repeat fixed;
        filter: blur(10px);
        z-index: -1;
      }
      
      #duration-slider::-webkit-slider-runnable-track {
        background-color: rgba(255, 255, 255, 0.3); /* Set the color to white */
      }

      #duration-slider::-webkit-slider-thumb {
        background-color: white;
      }

      .box-shadow::-webkit-slider-runnable-track {
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
      }

      .box-shadow::-webkit-slider-thumb {
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
      }
    </style>
  </head>
  <body>
    <div class="container-fluid">
      <a class="m-1 p-3 position-absolute start-0 top-0 btn border-0 link-body-emphasis text-shadow" href="/?page=<?php echo $page; ?>"><i class="bi bi-chevron-down fs-4 text-stroke"></i></a>
      <a class="m-1 p-3 position-absolute end-0 top-0 btn border-0 link-body-emphasis text-shadow d-md-none" href="#" data-bs-toggle="modal" data-bs-target="#shareLink"><i class="bi bi-share-fill fs-4"></i></a>
      <div class="container">
        <div class="d-flex justify-content-center align-items-center custom-bg vh-100">
          <div class="container p-4 bg-transparent rounded-5 w-100" style="max-width: 325px;">
            <div class="position-relative text-shadow">
              <div class="position-relative">
                <div class="text-center mb-3 ratio ratio-1x1">
                  <?php if ($imageData && $imageMime): ?>
                    <img src="data:<?php echo $imageMime; ?>;base64,<?php echo base64_encode($imageData); ?>" alt="Song Image" class="h-100 w-100 object-fit-cover rounded-4 shadow">
                  <?php endif; ?>
                </div>
                <button type="button" class="btn btn-sm btn-dark opacity-50 position-absolute top-0 start-0 m-2 rounded-1" data-bs-toggle="modal" data-bs-target="#songInfo">
                  <i class="bi bi-info-circle-fill"></i>
                </button>
                <h2 class="text-start text-white fw-bold" style="overflow-x: auto; white-space: nowrap;"><?php echo $title; ?></h2>
                <h6 class="text-start text-white fw-bold mb-4 overflow-auto text-nowrap"><a class="text-decoration-none text-white" href="/?artist=<?php echo $artist; ?>"><?php echo $artist; ?></a> - <a class="text-decoration-none text-white" href="/?album=<?php echo $album; ?>"><?php echo $album; ?></a></h6>
                </div>
              </div>
              <div id="music-player" class="w-100 mb-3 mt-4">
                <div class="d-flex justify-content-start align-items-center fw-medium text-white gap-2 text-shadow">
                  <span class="me-auto small" id="duration"></span>
                  <input type="range" class="w-100 form-range mx-auto box-shadow" id="duration-slider" value="0">
                  <span class="ms-auto small" id="duration-left"></span>
                </div>
                <audio id="player" class="d-none" controls>
                  <source src="<?php echo $currentSong['file']; ?>" type="audio/mpeg">
                  Your browser does not support the audio element.
                </audio>
              </div>
              <div class="btn-group w-100 d-flex justify-content-center align-items-center" style="gap: 0.7em;">
                <a class="btn border-0 link-body-emphasis w-25 text-white text-shadow text-start me-auto" href="<?php echo $previousUrl; ?>">
                  <i class="bi bi-skip-start-fill fs-custom-3"></i>
                </a>
                <button class="btn border-0 link-body-emphasis w-25 text-white text-shadow text-center mx-auto" id="playPauseButton" onclick="togglePlayPause()">
                  <i class="bi bi-play-circle-fill fs-custom"></i>
                </button>
                <a class="btn border-0 link-body-emphasis w-25 text-white text-shadow text-end ms-auto" href="<?php echo $nextUrl; ?>">
                  <i class="bi bi-skip-end-fill fs-custom-3"></i>
                </a>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
    <div class="modal fade" id="songInfo" tabindex="-1" aria-labelledby="exampleModalLabel" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow rounded-4">
          <div class="modal-header border-0">
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <div class="mb-2 row">
              <label for="title" class="col-4 col-form-label text-nowrap fw-medium">Title</label>
              <div class="col-8">
                <p class="form-control-plaintext fw-bold text-white" id="title"><?php echo $title; ?></p>
              </div>
            </div>
            <div class="mb-2 row">
              <label for="artist" class="col-4 col-form-label text-nowrap fw-medium">Artist</label>
              <div class="col-8">
                <p class="form-control-plaintext fw-bold text-white" id="artist"><a class="text-decoration-none text-white" href="/?artist=<?php echo $artist; ?>"><?php echo $artist; ?></a></p>
              </div>
            </div>
            <div class="mb-2 row">
              <label for="album" class="col-4 col-form-label text-nowrap fw-medium">Album</label>
              <div class="col-8">
                <p class="form-control-plaintext fw-bold text-white" id="album"><a class="text-decoration-none text-white" href="/?album=<?php echo $album; ?>"><?php echo $album; ?></a></p>
              </div>
            </div>
            <div class="mb-2 row">
              <label for="duration" class="col-4 col-form-label text-nowrap fw-medium">Duration</label>
              <div class="col-8">
                <p class="form-control-plaintext fw-bold text-white" id="duration"><?= $duration ?></p>
              </div>
            </div>
            <div class="mb-2 row">
              <label for="bitrate" class="col-4 col-form-label text-nowrap fw-medium">Bitrate</label>
              <div class="col-8">
                <p class="form-control-plaintext fw-bold text-white" id="bitrate"><?= $bitrate ?></p>
              </div>
            </div>
            <div class="mb-2 row">
              <label for="size" class="col-4 col-form-label text-nowrap fw-medium">Size</label>
              <div class="col-8">
                <p class="form-control-plaintext fw-bold text-white" id="size"><?= $size ?></p>
              </div>
            </div>
            <div class="mb-3 row">
              <label for="audioType" class="col-4 col-form-label text-nowrap fw-medium">Audio Type</label>
              <div class="col-8">
                <p class="form-control-plaintext fw-bold text-white" id="audioType"><?= $audioType ?></p>
              </div>
            </div>
            <a class="btn btn-primary fw-bold w-100" href="<?php echo $currentSong['file']; ?>" download>Download Song</a> 
          </div>
        </div>
      </div>
    </div>
    <div class="modal fade" id="shareLink" tabindex="-1" aria-labelledby="exampleModalLabel" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content bg-transparent border-0 rounded-0">
          <div class="card rounded-4 p-4">
            <p class="text-start fw-bold">share</p>
            <div class="btn-group w-100 mb-2" role="group" aria-label="Share Buttons">
              <!-- Twitter -->
              <a class="btn rounded-start-4" href="https://twitter.com/intent/tweet?url=<?php echo urlencode('http' . (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 's' : '') . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']); ?>" target="_blank" rel="noopener noreferrer">
                <i class="bi bi-twitter"></i>
              </a>
                                
              <!-- Line -->
              <a class="btn" href="https://social-plugins.line.me/lineit/share?url=<?php echo urlencode('http' . (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 's' : '') . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']); ?>" target="_blank" rel="noopener noreferrer">
                <i class="bi bi-line"></i>
              </a>
                                
              <!-- Email -->
              <a class="btn" href="mailto:?body=<?php echo urlencode('http' . (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 's' : '') . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']); ?>">
                <i class="bi bi-envelope-fill"></i>
              </a>
                                
              <!-- Reddit -->
              <a class="btn" href="https://www.reddit.com/submit?url=<?php echo urlencode('http' . (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 's' : '') . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']); ?>" target="_blank" rel="noopener noreferrer">
                <i class="bi bi-reddit"></i>
              </a>
                                
              <!-- Instagram -->
              <a class="btn" href="https://www.instagram.com/?url=<?php echo urlencode('http' . (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 's' : '') . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']); ?>" target="_blank" rel="noopener noreferrer">
                <i class="bi bi-instagram"></i>
              </a>
                                
              <!-- Facebook -->
              <a class="btn rounded-end-4" href="https://www.facebook.com/sharer/sharer.php?u=<?php echo urlencode('http' . (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 's' : '') . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']); ?>" target="_blank" rel="noopener noreferrer">
                <i class="bi bi-facebook"></i>
              </a>
            </div>
            <div class="btn-group w-100 mb-2" role="group" aria-label="Share Buttons">
              <!-- WhatsApp -->
              <a class="btn rounded-start-4" href="https://wa.me/?text=<?php echo urlencode('http' . (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 's' : '') . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']); ?>" target="_blank" rel="noopener noreferrer">
                <i class="bi bi-whatsapp"></i>
              </a>
    
              <!-- Pinterest -->
              <a class="btn" href="https://pinterest.com/pin/create/button/?url=<?php echo urlencode('http' . (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 's' : '') . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']); ?>" target="_blank" rel="noopener noreferrer">
                <i class="bi bi-pinterest"></i>
              </a>
    
              <!-- LinkedIn -->
              <a class="btn" href="https://www.linkedin.com/shareArticle?url=<?php echo urlencode('http' . (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 's' : '') . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']); ?>" target="_blank" rel="noopener noreferrer">
                <i class="bi bi-linkedin"></i>
              </a>
    
              <!-- Messenger -->
              <a class="btn" href="https://www.facebook.com/dialog/send?link=<?php echo urlencode('http' . (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 's' : '') . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']); ?>&app_id=YOUR_FACEBOOK_APP_ID" target="_blank" rel="noopener noreferrer">
                <i class="bi bi-messenger"></i>
              </a>
    
              <!-- Telegram -->
              <a class="btn" href="https://telegram.me/share/url?url=<?php echo urlencode('http' . (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 's' : '') . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']); ?>" target="_blank" rel="noopener noreferrer">
                <i class="bi bi-telegram"></i>
              </a>
    
              <!-- Snapchat -->
              <a class="btn rounded-end-4" href="https://www.snapchat.com/share?url=<?php echo urlencode('http' . (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 's' : '') . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']); ?>" target="_blank" rel="noopener noreferrer">
                <i class="bi bi-snapchat"></i>
              </a>
            </div>
            <div class="input-group">
              <input type="text" id="urlInput1" value="<?php echo 'http' . (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 's' : '') . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']; ?>" class="form-control border-2 fw-bold" readonly>
              <button class="btn btn-secondary opacity-50 fw-bold" onclick="copyToClipboard1()">
                <i class="bi bi-clipboard-fill"></i>
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>
    <script>
      document.addEventListener('DOMContentLoaded', function () {
        const audioPlayer = document.getElementById('player');
        const nextButton = document.querySelector('.btn[href*="Next"]');

        // Autoplay the player when the page loads
        audioPlayer.play();

        audioPlayer.addEventListener('ended', function(event) {
          // Redirect to the next song URL
          window.location.href = "<?php echo $nextUrl; ?>";
        });

        // Event listener for "Next" button
        if (nextButton) {
          nextButton.addEventListener('click', (event) => {
            event.preventDefault(); // Prevent the default navigation

            // Pause audio player
            audioPlayer.pause();

            const nextMusicUrl = nextButton.href;
            navigateToNextMusic(nextMusicUrl);
          });
        }

        // Function to navigate to the next music page
        function navigateToNextMusic(url) {
          window.location.href = url;
        }
      });

      const audioPlayer = document.getElementById('player');
      const durationSlider = document.getElementById('duration-slider');
      const durationLabel = document.getElementById('duration');
      const durationLeftLabel = document.getElementById('duration-left');
      const playPauseButton = document.getElementById('playPauseButton');

      function togglePlayPause() {
        if (audioPlayer.paused) {
          audioPlayer.play();
          playPauseButton.innerHTML = '<i class="bi bi-pause-circle-fill fs-custom"></i>';
        } else {
          audioPlayer.pause();
          playPauseButton.innerHTML = '<i class="bi bi-play-circle-fill fs-custom"></i>';
        }
      }

      audioPlayer.addEventListener('play', () => {
        playPauseButton.innerHTML = '<i class="bi bi-pause-circle-fill fs-custom"></i>';
      });

      audioPlayer.addEventListener('pause', () => {
        playPauseButton.innerHTML = '<i class="bi bi-play-circle-fill fs-custom"></i>';
      });

      function updateDurationLabels() {
        durationLabel.textContent = formatTime(audioPlayer.currentTime);
        durationLeftLabel.textContent = formatTime(audioPlayer.duration - audioPlayer.currentTime);
      }

      function formatTime(timeInSeconds) {
        const minutes = Math.floor(timeInSeconds / 60);
        const seconds = Math.floor(timeInSeconds % 60);
        return `${minutes}:${String(seconds).padStart(2, '0')}`;
      }

      function togglePlayPause() {
        if (audioPlayer.paused) {
          audioPlayer.play();
        } else {
          audioPlayer.pause();
        }
      }

      function setDefaultDurationLabels() {
        durationLabel.textContent = "0:00";
        durationLeftLabel.textContent = "0:00";
      }

      setDefaultDurationLabels(); // Set default values

      function getLocalStorageKey() {
        const album = "<?php echo $previousUrl; ?>";
        const id = "<?php echo $nextUrl; ?>";
        return `savedPlaytime_${album}_${id}`;
      }

      // Function to store the current playtime in localStorage
      function savePlaytime() {
        localStorage.setItem(getLocalStorageKey(), audioPlayer.currentTime);
      }

      // Function to retrieve and set the saved playtime
      function setSavedPlaytime() {
        const savedPlaytime = localStorage.getItem(getLocalStorageKey());
        if (savedPlaytime !== null) {
          audioPlayer.currentTime = parseFloat(savedPlaytime);
          updateDurationLabels();
        }
      }

      // Function to check if the song has ended and reset playtime
      function checkSongEnded() {
        if (audioPlayer.currentTime === audioPlayer.duration) {
          audioPlayer.currentTime = 0; // Reset playtime to the beginning
          savePlaytime(); // Save the updated playtime
          updateDurationLabels(); // Update duration labels
        }
      }

      // Add event listener to update playtime and save it to localStorage
      audioPlayer.addEventListener('timeupdate', () => {
        checkSongEnded(); // Check if the song has ended
        savePlaytime(); // Save the current playtime
        durationSlider.value = (audioPlayer.currentTime / audioPlayer.duration) * 100;
        updateDurationLabels();
      });

      // Add event listener to set the saved playtime when the page loads
      window.addEventListener('load', setSavedPlaytime);

      audioPlayer.addEventListener('loadedmetadata', () => {
        setDefaultDurationLabels(); // Reset default values
        durationLabel.textContent = formatTime(audioPlayer.duration);
      });

      durationSlider.addEventListener('input', () => {
        const seekTime = (durationSlider.value / 100) * audioPlayer.duration;
        audioPlayer.currentTime = seekTime;
        updateDurationLabels();
      });

      function copyToClipboard1() {
        var urlInput1 = document.getElementById('urlInput1');
        urlInput1.select();
        urlInput1.setSelectionRange(0, 99999); // For mobile devices

        document.execCommand('copy');
      }
    </script>
  </body>
</html>