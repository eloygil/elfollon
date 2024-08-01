<?php
function getEventYear() {
  $year = date("Y");
  $date = "26-07-" . $year;
  if (strtotime("now") > strtotime($date)) {
    return $year + 1;
  }
  return $year;
}

function getEventLocation() {
  return "IES Ega, San Adrián";
}

function getEventTime() {
  return "21:30";
}

function getMinutesBeforeEventTime($minutes) {
  return date("H:i", strtotime(getEventTime()) - $minutes * 60);
}

?>
