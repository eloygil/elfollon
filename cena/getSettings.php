<?php
$day_event = "10";
#$day_event = "26";
$month_event = "8";
#$month_event = "7";
$year_event = "2024";
#$year_event = "2025";
#$hour_event = 21;  # CEST time (España Peninsular)
$hour_event = 4;
$minute_event = 46;
#$minute_event = 30; # original

function getLimitMinutes() {
  # This is the amount of time before the event starts when the DB stops
  # accepting changes so seats can be assigned
  return 15;
}

function getEventDay() {
  global $day_event;
  return $day_event;
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
  return "IES Ega, San Adrián";
}

function getPrintableEventTime() {
  # Returns the Spanish CEST time value.
  # For UTC unix time use getEventTime() instead.
  global $hour_event, $minute_event;
  return getPadding($hour_event, 2) . ":" . getPadding($minute_event, 2);
}

function getEventTime() {
  # This event always happens in summer. For simplicity, we assume a fixed
  # two hour difference between UTC and CEST around dinner time.
  # ONE-OFF ERRORS ARE EXPECTED IF TESTING DURING WINTER/SPRING MONTHS!
  global $hour_event, $minute_event;
  $hour_utc = $hour_event - 2;
  return getPadding($hour_utc, 2) . ":" . getPadding($minute_event, 2);
}

function getMinutesBeforeTime($base_time, $minutes) {
  return date("H:i", strtotime($base_time) - $minutes * 60);
}

function isFrozen() {
  $limit_date = getEventDay() . "-" . getEventMonthNumber(1) . "-" . getEventYear() . " " . getMinutesBeforeTime(getEventTime(), getLimitMinutes());
  return strtotime("now") > strtotime($limit_date);
}

?>
