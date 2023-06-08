    <div class="container">
      <header class="d-flex flex-wrap justify-content-center py-3 mb-4">
        <a href="/" class="d-flex align-items-center mb-3 mb-md-0 me-md-auto link-body-emphasis text-decoration-none">
          <h1 class="text-center fw-bold"><i class="bi bi-play-circle-fill"></i> Music Library</h1>
        </a>

        <ul class="nav nav-pills">
          <li class="nav-item"><a href="index.php" class="nav-link text-white fw-semibold <?php if(basename($_SERVER['PHP_SELF']) == 'index.php') echo 'active' ?>"><i class="bi bi-house-fill"></i> Home</a></li>
          <li class="nav-item"><a href="allartist.php" class="nav-link text-white fw-semibold <?php if(basename($_SERVER['PHP_SELF']) == 'allartist.php') echo 'active' ?>"><i class="bi bi-people-fill"></i> Artist</a></li>
          <li class="nav-item"><a href="allalbum.php" class="nav-link text-white fw-semibold <?php if(basename($_SERVER['PHP_SELF']) == 'allalbum.php') echo 'active' ?>"><i class="bi bi-disc-fill"></i> Album</a></li>
        </ul>
      </header>
    </div>