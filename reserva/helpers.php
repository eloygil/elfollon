<?php

function debugPrint($text) {
  global $DEBUG;
  if ($DEBUG) { echo "DEBUG - " . $text; }
}

function printVariable($name) {
  echo "<br>" . $name . ": ";
  var_dump("$" . $name);
}

function getProtocol() {
  # Cloudflare makes this a little bit more complicated
  if (isset($_SERVER['HTTPS']) &&
      ($_SERVER['HTTPS'] == 'on' || $_SERVER['HTTPS'] == 1) ||
      isset($_SERVER['HTTP_X_FORWARDED_PROTO']) &&
      $_SERVER['HTTP_X_FORWARDED_PROTO'] == 'https') {
    return 'https';
  }
  return 'http';
}

function getClientIP() {
    if (isset($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        return $_SERVER['HTTP_CF_CONNECTING_IP'];
    }

    // Optional fallback to REMOTE_ADDR
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

?>
