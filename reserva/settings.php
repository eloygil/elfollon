<?php
$config_db = $conn->query("SELECT * FROM `reserva_config` LIMIT 1")->fetch_row();
# Next dinner: 26/07/2025 21:30 CEST time (España Peninsular)
[$year_event, $month_event, $day_event, $hour_event, $minute_event] = getFromConfigDb('fecha');
$hashSize = 40;

function getFromConfigDb($value) {
  # nombre | fecha | limite_min | ubicacion
  global $config_db;
  switch($value) {
    case 'nombre':
      return $config_db[0];
    case 'fecha':
      if (is_null($config_db[1])) {
        $config_db[1] = date("Y-m-d H:i:s");
      }
      return explode(" ", str_replace('-', ' ', str_replace(':', ' ', $config_db[1])));
    case 'limite_min':
      return $config_db[2];
    case 'ubicacion':
      return $config_db[3];
  }
}

function getLimitMinutes() {
  # This is the amount of time before the event starts when the DB stops
  # accepting changes so seats can be assigned
  return getFromConfigDb('limite_min');
}

function getEventDay() {
  global $day_event;
  return $day_event;
}

function getEventName() {
  return getFromConfigDb('nombre');
}

function getPadding($number, $padding) {
  return str_pad((string)$number, $padding, "0", STR_PAD_LEFT);
}

function getEventMonthNumber($padding=null) {
  global $month_event;
  if ($padding) {
    return getPadding($month_event, 2, "0", STR_PAD_LEFT);
  }
  return $month_event;
}

function getEventMonthText() {
  $months = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio',
	     'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
  return $months[getEventMonthNumber()-1];
}

function getEventYear() {
  global $year_event;
  return $year_event;
}

function getEventLocation() {
  return getFromConfigDb('ubicacion');
}

function getPrintableEventTime() {
  # Returns the Spanish CEST time value.
  # For UTC unix time use getEventTime() instead.
  global $hour_event, $minute_event;
  return getPadding($hour_event, 2) . ":" . getPadding($minute_event, 2);
}

function getEventTime() {
  # We assume local time stored in DB is ok
  global $hour_event, $minute_event;
  return getPadding($hour_event, 2) . ":" . getPadding($minute_event, 2);
}

function getMinutesBeforeTime($base_time, $minutes) {
  return date("H:i", strtotime($base_time) - $minutes * 60);
}

function isFrozen() {
  # Our DB has the event date in 'Europe/Madrid' timezone.
  #$limit_date = getEventDay() . "-" . getEventMonthNumber(1) . "-" . getEventYear() . " " . getMinutesBeforeTime(getEventTime(), getLimitMinutes());
  $server_tz = new DateTimeZone('UTC');
  $event_tz = new DateTimeZone('Europe/Madrid');
  $nowTime = new DateTime('now', $server_tz);
  $eventLimit = new DateTime(getEventTime(), $event_tz);
  printVariable("eventLimit");
  $event_offset = $eventLimit->getOffset() / 3600;
  $server_offset = $nowTime->getOffset() / 3600;
  var_dump(getLimitMinutes());
  $eventLimit->modify('-' . getLimitMinutes() . ' minutes');
  $diff = $event_offset - $server_offset;
  $eventLimit->modify('-' . $diff . ' hours');
  echo "<br>Offset (event - server) DIFF: ";
  var_dump($diff);
  echo "<br>Now: ";
  var_dump($nowTime);
  echo "<br>Limit: ";
  var_dump($eventLimit);
  echo "<br>diff:";
  var_dump($nowTime >= $eventLimit);
  return $nowTime >= $eventLimit;
}

?>
