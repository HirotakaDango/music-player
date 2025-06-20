# PHP Music Player

A simple, fast, and modern self-hosted music player built in PHP, with a clean UI, SQLite backend, and full PWA (Progressive Web App) features. Scan your music collection, play songs in your browser, create playlists, and manage your collection with ease. Multiple users, uploads, admin panel, favorites, drag-and-drop, and more.

![1](https://github.com/user-attachments/assets/0376f90c-12a9-45bf-acc4-ef14e0fe9ff3)
![2](https://github.com/user-attachments/assets/c0b1a692-4504-4701-b921-d54fc7360b5c) 

## Features

- 🎵 **Scan Local Music**: Recursively scans your directory for `mp3`, `m4a`, `flac`, `ogg`, and `wav` files (excluding uploads).
- 🏷️ **Automatic Metadata**: Uses [getID3](https://github.com/JamesHeinrich/getID3) to extract artist, album, year, genre, and cover images.
- 📚 **Library Management**: Browse by songs, artists, albums, genres, or favorites. Instant search included.
- ❤️ **Favorites**: Mark/unmark songs as favorites. Drag to reorder in "Favorites" view. Import/export supported.
- 🔊 **Player**: Play, pause, next/prev, repeat, shuffle, seek, and cover art display. In-browser playback via HTML5 `<audio>`. Media Session API support.
- 🖼️ **Album Art**: Displays embedded images as `.webp` (SVG fallback if missing).
- 📱 **Responsive UI**: Mobile-optimized, fast, and touch-friendly.
- ⚡ **PWA Support**: Install as an app on your phone or desktop. Works offline (caches static assets & some API). Manifest & service worker included.
- 🚀 **No Database Setup**: Uses SQLite, auto-initialized on first run.
- 👤 **User Accounts**: Register/login. Each user can upload their own music, manage their own favorites and uploads.
- ☁️ **Upload Music**: Upload new songs (multi-file, genre auto-detected from file/tag or custom). Each user can upload up to 5 songs per day (daily limit, reset at midnight).
- 🏷️ **Edit Genre**: Change genre from the context menu (only on your own uploads or as admin).
- 🗑️ **Delete Songs**: Delete your own uploads from the UI/context menu.
- ⬇️ **Download Songs**: Download your uploads directly from the context menu.
- 🔐 **Session Security**: All write actions require login. Uploads require account verification by an admin.
- 🛠️ **Settings**: Change password and manage your account.
- 🏢 **Admin Panel**: Admin can verify/un-verify user accounts, view user stats, and manage verification for uploads.
- 🎶 **Playlists**: Create, manage, and reorder custom playlists for your favorite tracks.
- 🔄 **Drag-and-drop Ordering**: Reorder favorites and playlist songs by dragging.
- 🔗 **Shareable Views**: Share direct links to songs, albums, artists, and playlists with others.
- 🗂️ **Infinite Scroll and Pagination**: Large libraries load smoothly with infinite scroll support.
- 📑 **Metadata View**: View song metadata (title, artist, album, genre, year, duration) from the context menu.

## Demo

[Try it here.](http://phpmusic.rf.gd/)

## Requirements

- PHP 7.4+ with `pdo_sqlite`, `gd`, and `mbstring` extensions enabled.
- [getID3 library](https://github.com/JamesHeinrich/getID3) (extract to a `getid3` folder inside the project).
- A folder full of music files!

## How to Activate SQLite in XAMPP/LAMPP

If you are using **XAMPP** or **LAMPP** and encounter issues with SQLite:

### For XAMPP (Windows/macOS)

1. Open your `php.ini` file (usually found in `xampp/php/php.ini`).
2. Ensure these lines are **not** commented (remove the leading semicolon `;` if present):

    ```
    extension=pdo_sqlite
    extension=sqlite3
    ```

3. Save and restart Apache using the XAMPP control panel.

### For LAMPP (Linux)

1. Open `/opt/lampp/etc/php.ini`.
2. Ensure:

    ```
    extension=pdo_sqlite
    extension=sqlite3
    ```

3. Save and restart Apache:

    ```bash
    sudo /opt/lampp/lampp restart
    ```

### Verify SQLite is enabled

- Create a `phpinfo.php` file:

    ```php
    <?php phpinfo(); ?>
    ```
- Open in your browser and search for "sqlite" or "PDO drivers". You should see `sqlite3` and `pdo_sqlite` enabled.

---

## Installation

1. **Clone the repo:**

    ```bash
    git clone https://github.com/HirotakaDango/PHP-Music-Player.git
    cd PHP-Music-Player
    ```

2. **Download getID3:**

    - [Download latest getID3](https://github.com/JamesHeinrich/getID3/releases)
    - Extract as a `getid3` folder inside the project root:
      ```
      PHP-Music-Player/
        index.php
        getid3/
          getid3.php
          ...
      ```

3. **Place music files:**

    - Put your music files in the root folder or any subfolder (except `uploads/`).
    - The player recursively scans for supported audio files.

4. **Set permissions (if needed):**

    - PHP must be able to write to `music.db` in the project directory.
    - For uploads, ensure the `uploads/` folder (auto-created) is writable by PHP.

5. **Run with your favorite PHP server:**

    - Built-in server (for testing):
      ```bash
      php -S localhost:8000
      ```
    - Or use with Apache/Nginx as a standard PHP site.

6. **Open in browser:**

    - Go to [http://localhost:8000](http://localhost:8000)
    - Register a user account to unlock uploading and library scanning.
    - **IMPORTANT:** After registering, an admin must verify your account before you can upload music (see below).
    - Click "Scan Library" to index your music folder.

## Usage

- **Register/Login**: Create a user account for full features (upload, scan, delete, edit, favorites, playlists).
- **Account Verification**: After registering, your account must be verified by an admin before you can upload music. Unverified users can still scan, browse, and play music.
- **Scan Library**: Click "Scan Library" in the sidebar to index or refresh your library (scans all music except uploads).
- **Browse**: Use the sidebar to view all songs, favorites, albums, artists, genres, or your own uploads.
- **Playlists**: Create, edit, and drag-to-reorder your own custom playlists. Add/remove songs easily.
- **Search**: Use the search bar (desktop/mobile) to instantly find songs, albums, or artists.
- **Play Music**: Click a song to play, or use the player controls at the bottom.
- **Favorites**: Click the heart icon to add/remove from favorites. Drag to reorder in "Favorites" view.
- **Edit Genre**: Right-click (or tap "..." on mobile) a song and choose "Edit Genre" (your own uploads or as admin).
- **Upload Music**: Click "Upload Song". You can upload multiple files at once; genre is auto-detected but can be overridden. **Upload limit:** 5 songs per user per day (resets at midnight).
- **Delete/Download**: Use context menu on your uploads to delete or download.
- **Share**: Click the "Share" button on albums, artists, playlists, or songs to get a shareable link.
- **PWA**: Click "Install App" (sidebar) if your browser supports PWAs. Works offline for playback and browsing.
- **Infinite Scroll**: Large libraries auto-load more songs as you scroll.
- **Context Menus**: Right-click or tap "..." for per-song actions like share, add to playlist, edit genre, delete, etc.
- **Metadata View**: View song metadata (title, artist, album, genre, year, duration) via context menu.

### Admin Panel

- Go to `?access=admin` (e.g., `http://localhost:8000/?access=admin`)
- Default Admin Password: `admin`
- Admin can verify/un-verify user accounts, view user details, and manage verification status for uploads.
- Admin can also edit/delete any song.

## How does it work?

- **index.php** is both the backend API (`?action=...`) and the single-page frontend.
- User authentication is session-based (server-side PHP sessions).
- User uploads are separated—each user can only manage their own uploads.
- Scanning uses getID3 for metadata and album art, storing info in `music.db` (SQLite).
- Album art is extracted, resized, and converted to `.webp`.
- Playback via JavaScript and HTML5 `<audio>`, with Media Session API support.
- PWA support includes manifest and service worker (`?pwa=manifest`, `?pwa=sw`).
- Uploads are stored in `/uploads/{artist}/` and are only accessible to the uploader or admin.
- Only the uploading user (or admin) can edit genre, delete, or download their uploads.
- Playlists and favorites support drag-and-drop ordering.
- Shareable views use query parameters for albums, artists, playlists, or specific songs.

## Customization

- **Colors**: Edit CSS variables in `<style>` inside `index.php`.
- **Audio formats**: Adjust the regex in `scan_music_directory()` in `index.php` to add more formats.
- **Remote sources**: Would require backend refactoring.
- **Public/Internet use**: Add your own authentication and security features (see below).

## Security

- **Warning:** Intended for personal use on your own server or LAN.
- Do **NOT** expose this directly to the public Internet without additional security.
- Each user has their own uploads, favorites, and permissions. Only their own uploads can be deleted/edited/downloaded.
- Users must be verified by an admin before uploading music.
- All write actions and uploads require login and a verified account.
- Admin panel is protected by password.

## Troubleshooting

- **Scan errors**: Ensure `getid3/` exists and is accessible, and PHP can read music files and write `music.db`.
- **Upload errors**: Make sure the `uploads/` directory is writable and PHP settings allow large enough uploads (`upload_max_filesize`, `post_max_size`). Ensure your account is verified.
- **Missing album art**: Some files may lack embedded images (default SVG will show).
- **Playback issues**: Browser support for some formats (like FLAC) may be limited.
- **Genre not showing**: Use "Edit Genre" from the context menu to update.

---

Enjoy your self-hosted PHP music player!  
Contributions and feedback welcome.
