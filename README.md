# PHP Music Player

A simple, fast, and modern self-hosted music player built in PHP, with a clean UI, SQLite backend, and PWA (Progressive Web App) features. Scan your music collection, play songs in your browser, organize favorites, and install as a mobile/desktop app.

![screenshot 1](https://github.com/user-attachments/assets/95573c62-7bf9-4ea5-9bf2-0fbea11a427c)
![screenshot 2](https://github.com/user-attachments/assets/ccb66c16-0679-49dc-ac34-1ea0513e98d5)

## Features

- 🎵 **Scan Local Music**: Recursively scans your directory for `mp3`, `m4a`, `flac`, `ogg`, and `wav` files.
- 🏷️ **Automatic Metadata**: Uses [getID3](https://github.com/JamesHeinrich/getID3) to extract artist, album, year, and cover images.
- 📚 **Library Management**: Browse by songs, artists, albums, or favorites. Search instantly.
- ❤️ **Favorites**: Mark/unmark songs as favorites (stored locally in your browser, import/export supported).
- 🔊 **Player**: Supports play, pause, next/prev, repeat, shuffle, seeking, and displays cover art.
- 🖼️ **Album Art**: Displays embedded images as `.webp` (fallback to SVG icon if missing).
- 📱 **Responsive UI**: Works great on desktop and mobile.
- ⚡ **PWA**: Install as an app on your phone or desktop. Works offline (caches assets & some API).
- 🚀 **No Database Setup**: Uses SQLite, auto-initialized on first run.

## Demo

_No public demo yet – deploy locally to try!_

## Requirements

- PHP 7.4+ with `pdo_sqlite`, `gd`, and `mbstring` extensions.
- [getID3 library](https://github.com/JamesHeinrich/getID3) (just extract to a `getid3` folder).
- A folder full of music files!

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

5. **Run with your favorite PHP server:**

    - Built-in server (for testing):
      ```bash
      php -S localhost:8000
      ```
    - Or use with Apache/Nginx as a normal PHP site.

6. **Open in browser:**

    - Go to [http://localhost:8000](http://localhost:8000)
    - Click "Scan Library" to index your music.

## Usage

- **Scan Library**: Click the "Scan Library" button in the sidebar to index or refresh your library.
- **Browse**: Use sidebar to view all songs, favorites, albums, or artists.
- **Search**: Use the search bar to instantly find songs, albums, or artists.
- **Play Music**: Click a song to play, or use the player controls at the bottom.
- **Favorites**: Click the heart icon to add/remove from favorites. Import/export using sidebar.
- **PWA**: Click "Install App" in the sidebar (appears if your browser supports PWAs).

## How does it work?

- **index.php** acts as both the backend API (responding to `?action=...`) and serves the single-page frontend.
- Scanning uses getID3 to extract metadata and album art, storing info in `music.db` (SQLite).
- Album art is extracted, resized, and converted to `.webp` for efficiency.
- All playback is done in-browser via JavaScript and HTML5 `<audio>`.
- PWA features are powered by manifest and a service worker (`?pwa=manifest` and `?pwa=sw`).

## Customization

- To change colors, edit the CSS variables in `<style>` of `index.php`.
- To add more audio formats, adjust the regex in `scan_music_directory()` in `index.php`.
- To support remote music sources, some backend refactoring would be needed.

## Security

- **Warning:** This is intended for personal use on your own server or LAN.
- No authentication or user system is included.
- Do **NOT** expose this directly to the public Internet without additional security.

## Troubleshooting

- **Scan errors**: Ensure `getid3/` exists and is accessible, and PHP has permission to read music files and write `music.db`.
- **Missing album art**: Some files may lack embedded images, default SVG will show.
- **Playback issues**: Browser support for some formats (like FLAC) may be limited.
