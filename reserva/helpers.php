<?php

function debugPrint($text) {
  global $DEBUG;
  if ($DEBUG) { echo "DEBUG - " . $text; }
}

function printVariable($name) {
  echo "<br>" . $name . ": ";
  var_dump("$" . $name);
}
?>
