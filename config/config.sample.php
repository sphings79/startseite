<?php
// Copy to config/config.php. Every key can also be set via STARTSEITE_* environment variables
// (STARTSEITE_DATA_DIR, STARTSEITE_BASE_PATH, STARTSEITE_TRUSTED_PROXIES, STARTSEITE_CHROMIUM_BIN,
// STARTSEITE_CHROMIUM_FLAGS, STARTSEITE_BLOCK_PRIVATE, STARTSEITE_SESSION_DAYS), which take precedence.
return [
    // Database, uploaded images and the secret key live here. Must be writable by the web server and the worker, and outside the web root.
    'data_dir' => __DIR__ . '/../data',

    // null = detect from the URL the app is served under ('' for a domain root, '/startseite' for a subfolder).
    'base_path' => null,

    // Reverse proxies whose X-Forwarded-For / -Proto / -Host headers are trusted.
    'trusted_proxies' => [],

    // Screenshots: path to Chromium (empty = auto-detect) and extra flags, e.g. ['--no-sandbox'] inside containers.
    'chromium_bin' => '',
    'chromium_flags' => [],

    // true: the server refuses to fetch logos/screenshots/images from LAN or loopback addresses (SSRF protection).
    // Set to false at home if your bookmarks point to internal services.
    'block_private_targets' => true,

    // Login stays valid this many days after the last visit.
    'session_days' => 365,

    'worker_poll_seconds' => 2,
];
