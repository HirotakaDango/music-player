# PHP Music Player

A simple, fast, and modern self-hosted music player built in PHP, with a clean UI, SQLite backend, and PWA (Progressive Web App) features. Scan your music collection, play songs in your browser, organize your library, and now also enjoy features like user accounts and uploading your own songs!

![Screenshot](https://github.com/user-attachments/assets/9878068a-7ea0-4630-bdda-dff7c72e76f3)
![Screenshot](https://github.com/user-attachments/assets/5f8c05ae-abc7-484b-a805-2323311cab6a)

## Features

- 🎵 **Scan Local Music**: Recursively scans your directory for `mp3`, `m4a`, `flac`, `ogg`, and `wav` files.
- 🏷️ **Automatic Metadata**: Uses [getID3](https://github.com/JamesHeinrich/getID3) to extract artist, album, year, genre, and cover images.
- 📚 **Library Management**: Browse by songs, artists, albums, genres, or favorites. Search instantly.
- ❤️ **Favorites**: Mark/unmark songs as favorites (with custom order, stored per user). Import/export supported.
- 🔊 **Player**: Supports play, pause, next/prev, repeat, shuffle, seeking, and displays cover art.
- 🖼️ **Album Art**: Displays embedded images as `.webp` (fallback to SVG icon if missing).
- 📱 **Responsive UI**: Works great on desktop and mobile.
- ⚡ **PWA**: Install as an app on your phone or desktop. Works offline (caches assets & some API).
- 🚀 **No Database Setup**: Uses SQLite, auto-initialized on first run.
- 👤 **User Accounts**: Register/login, each user can upload their own music, manage their own favorites, and delete/edit their own uploads.
- ☁️ **Upload Music**: Users can upload new songs (with genre support and embedded metadata extraction).
- 🏷️ **Edit Genre**: Song genre can be edited directly from the context menu.
- 🗑️ **Delete Songs**: Users can delete their own uploaded songs from the UI or context menu.
- ⬇️ **Download Songs**: Download your uploaded music directly from the context menu.
- 🔐 **Session Security**: All write actions require login.

## Demo

[Try it here.](http://phpmusic.rf.gd/)

## Requirements

- PHP 7.4+ with `pdo_sqlite`, `gd`, and `mbstring` extensions.
- [getID3 library](https://github.com/JamesHeinrich/getID3) (just extract to a `getid3` folder).
- A folder full of music files!

## How to Activate SQLite in XAMPP/LAMPP

If you are using **XAMPP** or **LAMPP** and encounter issues with SQLite, you may need to enable the SQLite extension:

### For XAMPP (Windows/macOS/Linux)

1. Open your `php.ini` file.  
   - Usually found in `xampp/php/php.ini`.

2. Search for the following lines and ensure they are **not** commented (remove the leading semicolon `;` if present):

    ```
    extension=pdo_sqlite
    extension=sqlite3
    ```

3. Save the `php.ini` file.

4. **Restart Apache** using the XAMPP control panel for changes to take effect.

### For LAMPP (Linux)

1. Open your `php.ini` file located at `/opt/lampp/etc/php.ini`.

2. Find and ensure these lines are enabled (no leading semicolon):

    ```
    extension=pdo_sqlite
    extension=sqlite3
    ```

3. Save the file.

4. Restart Apache:

    ```bash
    sudo /opt/lampp/lampp restart
    ```

### Verify SQLite is enabled

- Create a `phpinfo.php` file with:
    ```php
    <?php phpinfo(); ?>
    ```
- Open it in your browser and search for "sqlite" or "PDO drivers". You should see `sqlite3` and `pdo_sqlite` enabled.

---

## Installation

1. **Clone the repo:**

    ```bash
    git clone https://github.com/HirotakaDango/music-player.git
    cd music-player
    ```

2. **Download getID3:**

    - [Download latest getID3](https://github.com/JamesHeinrich/getID3/releases)
    - Extract it as a `getid3` folder inside the project root:
      ```
      music-player/
        index.php
        getid3/
          getid3.php
          ...
      ```

3. **Place music files:**

    - Put your music files in the root folder or any subfolder below (the player recursively scans).

4. **Set permissions (if needed):**

    - The PHP process must be able to write to `music.db` in the project directory.
    - For uploads, make sure the `uploads/` folder (auto-created) is writable by PHP.

5. **Run with your favorite PHP server:**

    - Built-in server (for testing):
      ```bash
      php -S localhost:8000
      ```
    - Or use with Apache/Nginx as a normal PHP site.

6. **Open in browser:**

    - Go to [http://localhost:8000](http://localhost:8000)
    - Register a user account to unlock uploading and library scanning.
    - Click "Scan Library" to index your music.

## Usage

- **Register/Login**: Create a user account to access full features (upload, scan, delete, edit, favorites).
- **Scan Library**: Click the "Scan Library" button in the sidebar to index or refresh your library (scans all music except uploads).
- **Browse**: Use sidebar to view all songs, favorites, albums, artists, or genres.
- **Search**: Use the search bar to instantly find songs, albums, or artists.
- **Play Music**: Click a song to play, or use the player controls at the bottom.
- **Favorites**: Click the heart icon to add/remove from favorites. Drag to reorder in "Favorites" view.
- **Edit Genre**: Right-click (or tap "..." on mobile) a song and choose "Edit Genre".
- **Upload Music**: Click "Upload Song" in the sidebar. You may upload multiple files; genre is auto-detected but can be overridden.
- **Delete/Download**: Use the context menu on your own uploads to delete or download songs.
- **PWA**: Click "Install App" in the sidebar (appears if your browser supports PWAs).

## How does it work?

- **index.php** acts as both the backend API (responding to `?action=...`) and serves the single-page frontend.
- User authentication is session-based (server-side sessions).
- Scanning uses getID3 to extract metadata and album art, storing info in `music.db` (SQLite).
- Album art is extracted, resized, and converted to `.webp` for efficiency.
- All playback is done in-browser via JavaScript and HTML5 `<audio>`.
- PWA features are powered by a manifest and a service worker (`?pwa=manifest` and `?pwa=sw`).
- Uploads are stored in `/uploads/{artist}/` and only accessible to the uploading user or the admin ("Music Library" user).
- Only the uploading user (or admin) can edit genre, delete, or download their uploads.

## Customization

- To change colors, edit the CSS variables in `<style>` of `index.php`.
- To add more audio formats, adjust the regex in `scan_music_directory()` in `index.php`.
- To support remote music sources, some backend refactoring would be needed.
- For public access, add authentication and security features (see below).

## Security

- **Warning:** This is intended for personal use on your own server or LAN.
- No public authentication or user system is included by default.
- Do **NOT** expose this directly to the public Internet without additional security.
- Each user has their own uploads, favorites, and permissions. Only their own uploads can be deleted/edited/downloaded.

## Troubleshooting

- **Scan errors**: Ensure `getid3/` exists and is accessible, and PHP has permission to read music files and write `music.db`.
- **Upload errors**: Make sure the `uploads/` directory is writeable and that your PHP settings allow large enough file uploads (`upload_max_filesize`, `post_max_size`).
- **Missing album art**: Some files may lack embedded images, default SVG will show.
- **Playback issues**: Browser support for some formats (like FLAC) may be limited.
- **Genre not showing**: Use "Edit Genre" from the context menu to update.

---

Enjoy your self-hosted PHP music player!  
Contributions and feedback welcome.

```
