<?php
include('../../php-require/phpqrcode.php');
require('getSettings.php');
require('../../php-require/mysql-elfollon.php');
$year = getEventYear();
$location = getEventLocation();
$hour = getEventTime();
?>

<html>
<head>
<title>Cena Peña El Follón <?php echo $year; ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
<style>
.text-responsive {
  font-size: calc(100% + 1vw + 1vh);
}
</style>
</head>
<body>
<div class="container">
  <div class="row">
    <div class="col-xs-12">
<h1>Cena Peña "El Follón"</h1>
<p>
<b>Fecha:</b> 26 de julio de <?php echo $year; ?><br>
<b>Lugar:</b> <?php echo $location; ?>
</p>

<?php
$BASE_URL = "https://" . $_SERVER['SERVER_NAME'];
function getAssignedGroup($conn) {
  $result = $conn->query("SELECT gid FROM cena_invitaciones WHERE uid LIKE '%" . $_SESSION["uid"] . "%'");
  if ($result->num_rows > 0) {
    return $result->fetch_row()[0];
  }
}

function getGroupNumber($conn, $gid) {
  $result = $conn->query("SELECT id FROM cena_grupos WHERE gid LIKE '%" . $gid . "%'");
  return $result->fetch_row()[0];
}

function getGroupSize($conn, $gid) {
  $result = $conn->query("SELECT COUNT(*) FROM `cena_invitaciones` WHERE gid = '" . $gid . "'");
  return $result->fetch_row()[0];
}

function setGroup($conn, $gid) {
  $conn->query("UPDATE cena_invitaciones SET gid = '" . $gid . "' WHERE uid LIKE '%" . $_SESSION["uid"] . "%'");
}

function createGroup($conn) {
  $new_gid = hash('sha1', 'Cena-El-Follon-' . $_SESSION["uid"] . '-' . date("Y-m-d H:m:s"));
  $conn->query("INSERT INTO cena_grupos (gid) VALUES ('" . $new_gid . "')");
  $conn->query("UPDATE cena_invitaciones SET gid = '" . $new_gid . "' WHERE uid LIKE '%" . $_SESSION["uid"] . "%'");
}

function leaveGroup($conn) {
  $gid = getAssignedGroup($conn);
  if (getGroupSize($conn, $gid) == 1) {
    # Eliminar grupo
    $conn->query("DELETE FROM cena_grupos WHERE gid LIKE '%" . $gid . "%'");
  } else {
    # Eliminar asignación
    $conn->query("UPDATE cena_invitaciones SET gid=NULL WHERE uid LIKE '%" . $_SESSION["uid"] . "%'");
  }
}

session_start();
$uid = filter_input(INPUT_GET, 'invitacion', FILTER_SANITIZE_URL);
$join_gid = filter_input(INPUT_GET, 'unirse', FILTER_SANITIZE_URL);
if (!isset($uid)) {
    $uid = $_SESSION["uid"];
}
$result = $conn->query("SELECT uid, gid FROM cena_invitaciones WHERE uid LIKE '%" . $uid . "%'");
if ($result->num_rows != 1) {
	echo "<b style=\"color: red;\">ERROR</b>: Invitación no válida.<br>";
  if (!isset($join_gid)) {
    echo "Por favor, escanea el código QR para identificarte.<br>";
    exit(1);
  }
} else {
  $_SESSION["uid"] = $uid;
  $conn->query("UPDATE cena_invitaciones SET last_access = NOW() WHERE uid LIKE '%" . $uid . "%'");
}

if (!isset($_SESSION["uid"]) and isset($join_gid)) {
  echo "Escanea el QR de la invitación con la cámara de tu móvil antes de utilizar el enlace para unirte a un grupo.<br>";
  echo "Recuerda que debes usar el mismo dispositivo, navegador y sesión (evita usar el modo incógnito)";
  exit(1);
} elseif (isset($_SESSION["uid"]) and isset($join_gid)) {
  if ($join_gid == getAssignedGroup($conn)) {
    leaveGroup($conn);
  }
  setGroup($conn, $join_gid);
}

if (isset($_GET['abandonar'])) {
  leaveGroup($conn);
}

if (isset($_GET['crear'])) {
  if (is_null(getAssignedGroup($conn))) {
     createGroup($conn);
  }
}
?>

¿Estás cansado de esperar en la puerta para asegurarte de que os podéis sentar todos juntos? ¿Echas de menos ir a la cena acompañando a la charanga?<br>
Gracias a este sistema de reservas cada socio puede usar su invitación para unirse a un grupo.<br>
A la hora de la cena, cada grupo tendrá un lugar asignado en una mesa, sin necesidad de hacer cola ni llegar pronto. Además, así ayudas a que no se reserven sitios de más y que finalmente no se utilicen.<br>
Una vez un socio forma parte de un grupo no tiene que hacer nada más, el mapa (con cada lugar asignado) aparecerá aquí justo antes de la cena.<br>
<?php
$gid = getAssignedGroup($conn);
if ($gid) {
  $gnum = getGroupNumber($conn, $gid);
  echo "Actualmente formas parte del <b>GRUPO #" . $gnum . "</b>. <a href=\"" . $BASE_URL . "/?abandonar\" class=\"btn btn-primary\">Abandonar grupo</a><br>";
  $nmm = getGroupSize($conn, $gid);
  if ($nmm > 1) {
    echo "En este momento, en el grupo sois " . getGroupSize($conn, $gid) . " personas en total.<br>";
  } else {
    echo "Eres el único miembro de este grupo.<br>";
  }
  $url = $BASE_URL . "/?unirse=" . $gid;
  echo "Puedes invitar a tu grupo a otros socios con invitación que ya hayan escaneado su QR previamente compartiendo con ellos el siguiente enlace: <a href=\"" . $url . "\">" . $url . "</a></br>";
} else {
  echo "No formas parte de ningún grupo. Puedes unirte a uno existente a través de un enlace o crear uno nuevo. ";
  echo "<a href=\"" . $BASE_URL . "/?crear\" class=\"btn btn-primary\">Crear nuevo grupo</a><br>";
}
?>
Los socios pueden unirse a grupos hasta 15 minutos antes del comienzo de la cena, momento en el que se asignarán los asientos.<br>

A partir de las <?php echo getMinutesBeforeEventTime(15); ?>h escanea de nuevo tu QR para ver aquí la asignación final.
    </div>
  </div>
</div>
</body>
</html>
