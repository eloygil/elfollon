<?php
session_start();
$DEBUG = False;

include('../../php-require/phpqrcode.php');
require('../../php-require/mysql-elfollon.php');
require('helpers.php');
require('settings.php');
?>

<html>
<head>
<title><?php echo getEventName(); ?> - Peña "El Follón" <?php echo getEventYear(); ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
<link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
<link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
<link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
<link rel="manifest" href="/site.webmanifest">
<link rel="stylesheet" href="css/main.css">

<?php
$BASE_URL = "https://" . $_SERVER['SERVER_NAME'];
function getAssignedGroup($conn) {
  $stmt = $conn->prepare("SELECT gid FROM invitaciones WHERE uid=?");
  $stmt->bind_param("s", $_SESSION["uid"]);
  $result = $stmt->execute();
  $result = $stmt->get_result();
  if ($result->num_rows > 0) {
    return $result->fetch_row()[0];
  }
  return NULL;
}

function getGroupNumber($conn, $gid) {
  $stmt = $conn->prepare("SELECT id FROM grupos WHERE gid=?");
  $stmt->bind_param("s", $gid);
  $stmt->execute();
  $result = $stmt->get_result();
  return $result->fetch_row()[0];
}

function getGroupSize($conn, $gid) {
  $stmt = $conn->prepare("SELECT COUNT(*) FROM `invitaciones` WHERE gid=?");
  $stmt->bind_param("s", $gid);
  $stmt->execute();
  $result = $stmt->get_result();
  return $result->fetch_row()[0];
}

function getGroupTable($conn, $gid) {
  $stmt = $conn->prepare("SELECT mesa FROM `grupos` WHERE gid=?");
  $stmt->bind_param("s", $gid);
  $stmt->execute();
  $result = $stmt->get_result();
  $table = $result->fetch_row()[0];
  return $table;
}

function getGroupTableSeats($conn, $gid) {
  $stmt = $conn->prepare("SELECT asiento FROM `grupos` WHERE gid=?");
  $stmt->bind_param("s", $gid);
  $stmt->execute();
  $result = $stmt->get_result();
  $first_seat = $result->fetch_row()[0];
  if ($first_seat == null) {
    return null;
  }
  $seat_n = getGroupSize($conn, $gid);
  return range($first_seat, $first_seat + ($seat_n - 1));
}

function getGroupNewId($conn) {
  $max = $conn->query("SELECT MAX(id) FROM `grupos`")->fetch_row()[0];
  $count = $conn->query("SELECT COUNT(*) FROM `grupos`")->fetch_row()[0];
  if ($max == $count) {
    return $max + 1;
  } else {
    $result = $conn->query("SELECT id FROM `grupos` ORDER BY id ASC");
    $idx = 1;
    while ($id = $result->fetch_row()) {
      if ($idx != $id[0]) {
        return $idx;
      }
      $idx = $idx + 1;
    }
    return $idx;
  }
}

function getEventIsToday() {
  return mktime(0,0,0,getEventDay(),getEventMonthNumber(),getEventYear()) < strtotime('now');
}

function getScanInstructions() {
  # TODO: Add animated GIF explaining how to scan a QR code can be added here
  # e.g.: <div class="scan-instructions"><img src="img/scan.gif"></div><br>
}

function setGroup($conn, $gid) {
  $stmt = $conn->prepare("UPDATE invitaciones SET gid=? WHERE uid=?");
  $stmt->bind_param("ss", $gid, $_SESSION["uid"]);
  $result = $stmt->execute();
  $conn->commit();
}

function createGroup($conn) {
  $new_gid = hash('sha1', 'El-Follon-' . $_SESSION["uid"] . '-' . date("Y-m-d H:m:s"));
  $conn->begin_transaction();
  try {
    $id = getGroupNewId($conn);
    # Insert new group
    $stmt = $conn->prepare("INSERT INTO grupos (gid, id) VALUES (?,?)");
    $stmt->bind_param("ss", $new_gid, $id);
    $stmt->execute();
    # Update invitations accordingly
    $stmt = $conn->prepare("UPDATE invitaciones SET gid=? WHERE uid=?");
    $stmt->bind_param("ss", $new_gid, $_SESSION["uid"]);
    $stmt->execute();
    $conn->commit();
  } catch (mysqli_sql_exception $exception) {
    $conn->rollback();
    echo "<b style=\"color: red;\">ERROR</b>: Inténtelo de nuevo más adelante.";
    throw $exception;
    exit(1);
  }
}

function leaveGroup($conn) {
  $gid = getAssignedGroup($conn);
  if (getGroupSize($conn, $gid) == 1) {
    # Eliminar grupo (foreign key cascading is enabled, no need to explicitly set to NULL)
    $stmt = $conn->prepare("DELETE FROM grupos WHERE gid=?");
    $stmt->bind_param("s", $gid);
  } else {
    # Eliminar asignación
    $stmt = $conn->prepare("UPDATE invitaciones SET gid=NULL WHERE uid=?");
    $stmt->bind_param("s", $_SESSION["uid"]);
  }
  $stmt->execute();
  $conn->commit();
}

function getPlural($n) {
  if ($n > 1) { return "s"; }
}

function getIsUserInDatabase($conn, $uid) {
  $stmt = $conn->prepare("SELECT uid FROM invitaciones WHERE uid=?");
  $stmt->bind_param("s", $uid);
  $stmt->execute();
  $result = $stmt->get_result();
  return $result->fetch_row()[0] === $uid;
}


# Sanitize inputs and guarantee valid data is received
$uid = preg_replace('/[^-a-zA-Z0-9_]/', '', filter_input(INPUT_GET, 'invitacion', FILTER_SANITIZE_URL));
if (strlen($uid) != $hashSize) {
  debugPrint("Wrong invitation hash length, ignoring<br>");
  unset($uid);
}
$join_gid = preg_replace('/[^-a-zA-Z0-9_]/', '', filter_input(INPUT_GET, 'unirse', FILTER_SANITIZE_URL));
if (strlen($join_gid) != $hashSize) {
  debugPrint("Wrong group hash length, ignoring<br>");
  unset($join_gid);
}

if (isset($uid) and getIsUserInDatabase($conn, $uid)) {
  debugPrint("Saving into SESSION uid: " . $uid);
  $_SESSION["uid"] = $uid;
} else {
  debugPrint("Unsetting uid.");
  unset($uid);
}
if (!isset($uid) and isset($_SESSION["uid"])) {
  $uid = $_SESSION["uid"];
}

$gid = getAssignedGroup($conn);
if (!is_null($gid) and isFrozen()) {
    echo "<link href=\"css/groups/" . $gid . ".css\" rel=\"stylesheet\">";
}
$gnum = getGroupNumber($conn, $gid);
$nmm = getGroupSize($conn, $gid);
$gt = getGroupTable($conn, $gid);
$gts = getGroupTableSeats($conn, $gid);
?>
</head>
<body>
<div class="header-container">
    <img src="img/logo.png" alt="Left Logo" class="logo">
    <h1><?php echo getEventName(); ?></h1>
    <img src="img/logo.png" alt="Right Logo" class="logo">
</div>
<div class="container">
  <div class="row">
    <div class="col-xs-12">
<b>Fecha</b>: <?php echo getEventDay(); ?> de <?php echo getEventMonthText(); ?> de <?php echo getEventYear(); ?> (<?php echo getPrintableEventTime(); ?>h.)<br>
<b>Lugar</b>: <?php echo getEventLocation(); ?><br>

<?php
if (!$uid) {
  echo "<b style=\"color: red;\">ERROR</b>: Invitación no válida.<br>";
  if (!isset($join_gid)) {
    echo "Por favor, primero <b>escanea el código QR</b> de la invitación para identificarte.<br>";
    getScanInstructions();
    session_destroy();
    exit(1);
  }
} else {
  $stmt = $conn->prepare("UPDATE invitaciones SET last_access = NOW() WHERE uid=?");
  $stmt->bind_param("s", $uid);
  $stmt->execute();
  $stmt = $conn->prepare("SELECT label FROM invitaciones WHERE uid=?");
  $stmt->bind_param("s", $uid);
  $stmt->execute();
  $label = $stmt->get_result()->fetch_row()[0];
  $tag = ($label ? (is_numeric($label) ? "#" . $label : $label) : substr($uid, -6));
  echo "<b>Invitación</b>: " . $tag . "<br>";
}

debugPrint("<b>uid</b>: " . $uid . "<br>");
debugPrint("<b>_SESSION['uid']</b>: " . $_SESSION["uid"] . "<br>");
if (isset($join_gid)) { debugPrint("<b>join_gid</b>: " . $join_gid . "<br>"); }

if (!isset($_SESSION["uid"]) and isset($join_gid)) {
  echo "Escanea el QR de la invitación con la cámara de tu móvil <b>antes</b> de utilizar el enlace para unirte a un grupo.<br>";
  echo "Recuerda que debes usar el mismo dispositivo, navegador y sesión (evita usar el modo privado/incógnito)";
  getScanInstructions();
  exit(1);
} elseif (isset($_SESSION["uid"]) and isset($join_gid)) {
  if ($join_gid != getAssignedGroup($conn)) {
    leaveGroup($conn);
  }
  setGroup($conn, $join_gid);
  header('Location: /');
}

if (isset($_GET['abandonar'])) {
  if (!isFrozen()) {
    leaveGroup($conn);
  }
  header('Location: /');
}

if ((isset($_GET['crear'])) and (!isFrozen())) {
  if (is_null(getAssignedGroup($conn))) {
     createGroup($conn);
  }
  header('Location: /');
}

if ($gid) {
  echo "<b>Grupo</b>: #" . $gnum . " (" . $nmm . " miembro" . getPlural($nmm) . ")<br>";
}

if (!isFrozen()) {
  echo "<hr>";
  echo "A la hora de empezar cada grupo tendrá un lugar asignado en una mesa.<br>";
} elseif (is_null($gid)) {
  echo "No formas parte de ningún grupo de reserva y el plazo está ya cerrado.<br>";
  echo "Por favor, dirígete hacia las mesas destinadas a los socios que acuden sin reserva, allí podréis sentaros libremente como en años anteriores.";
  exit(0);
} elseif (!is_null($gt) and !is_null($gts)) {
  echo "<b>Mesa</b>: " . $gt . "&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;";
  echo "<b>Asiento" . getPlural($nmm) . "</b>: " . $gts[0];
  if ($nmm > 1) { echo "-" . $gts[$nmm-1]; }
  echo "<br><b>Distribución de mesas y asientos</b>:<br>";
  include("mapa.html");
  echo "Vuestros asientos están señalados en <b style=\"color: red;\">rojo</b>.";
  exit(0);
} else {
  echo "Las reservas están siendo asignadas, vuelve a comprobarlo escaneando el QR de tu invitación más adelante.";
  exit(0);
}

if ($gid) {
  $url = $BASE_URL . "/?unirse=" . $gid;
  echo "Invita a tu grupo a otros mediante un enlace:<br>";
  echo "<div class=\"social\">";
  echo "<a href=\"" . $url . "\"><div id=\"TextoACopiar\" hidden>" . $url . "</div></a> ";
  ?>
  <button id="BotonCopiar" class="btn btn-primary" onclick="copyOnClick()">Copiar enlace</button>
  <script type="text/javascript">
    function copyOnClick() {
      var codigoACopiar = document.getElementById('TextoACopiar');
      navigator.clipboard.writeText(codigoACopiar.innerHTML)
    }
  </script>
  <?php
  include("whatsapp.html");
  echo "<a href=\"" . $BASE_URL . "/?abandonar\" class=\"btn btn-danger\">Abandonar</a><br>";
  echo "</div>";
} else {
  echo "<b>No formas parte de ningún grupo</b>, puedes unirte a uno existente a través de un enlace o crear uno nuevo e invitar a otros.<br>";
  echo "<div class=\"social\"><a href=\"" . $BASE_URL . "/?crear\" class=\"btn btn-primary\">Crear nuevo grupo</a></div><br>";
}

echo "Los grupos serán definitivos " . getLimitMinutes() . " minutos antes del comienzo.<br>";
if (getEventIsToday()) {
  echo "A partir de las " . getMinutesBeforeTime(getPrintableEventTime(), getLimitMinutes()) . "h escanea de nuevo tu QR para ver vuestros asientos.";
}
?>
    </div>
  </div>
</div>
</body>
</html>
<?php $conn->close(); ?>
