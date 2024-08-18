<?php
include('../../php-require/phpqrcode.php');
require('getSettings.php');
require('../../php-require/mysql-elfollon.php');
?>

<html>
<head>
<title>Cena Peña El Follón <?php echo getEventYear(); ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
<style>
.text-responsive {
  font-size: calc(100% + 1vw + 1vh);
}
.btn-whatsapp {
  background-color: #25d366;
  opacity: .95;
  min-width: 120px;
  box-sizing: border-box;
  transition: opacity 0.2s ease-in, top 0.2s ease-in;
  border: none;
  cursor: pointer;
  position: relative;
  text-align: center;
  white-space: nowrap;
}
.btn-whatsapp-label {
  color: #fff;
}
h1 {
  text-align: center;
}
</style>
</head>
<body>
<h1>Cena Peña "El Follón"</h1>
<div class="container">
  <div class="row">
    <div class="col-xs-12">
<b>Fecha</b>: <?php echo getEventDay(); ?> de <?php echo getEventMonthText(); ?> de <?php echo getEventYear(); ?> (<?php echo getPrintableEventTime(); ?>h.)<br>
<b>Lugar</b>: <?php echo getEventLocation(); ?><br>

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

function getGroupTable($conn, $gid) {
  $result = $conn->query("SELECT mesa FROM `cena_grupos` WHERE gid = '" . $gid . "'");
  $table = $result->fetch_row()[0];
  return $table;
}

function getGroupTableSeats($conn, $gid) {
  $seat_n = getGroupSize($conn, $gid);
  $result = $conn->query("SELECT asiento FROM `cena_grupos` WHERE gid = '" . $gid . "'");
  $first_seat = $result->fetch_row()[0];
  if ($first_seat == null) {
    return null;
  }
  return range($first_seat, $first_seat + ($seat_n - 1));
}

function getGroupNewId($conn) {
  $max = $conn->query("SELECT MAX(id) FROM `cena_grupos`")->fetch_row()[0];
  $count  = $conn->query("SELECT COUNT(*) FROM `cena_grupos`")->fetch_row()[0];
  if ($max == $count) {
    return $max + 1;
  } else {
    $result = $conn->query("SELECT id FROM `cena_grupos` ORDER BY id ASC");
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

function setGroup($conn, $gid) {
  $conn->query("UPDATE cena_invitaciones SET gid = '" . $gid . "' WHERE uid LIKE '%" . $_SESSION["uid"] . "%'");
}

function createGroup($conn) {
  $new_gid = hash('sha1', 'Cena-El-Follon-' . $_SESSION["uid"] . '-' . date("Y-m-d H:m:s"));
  $conn->begin_transaction();
  try {
    $id = getGroupNewId($conn);
    $conn->query("INSERT INTO cena_grupos (gid, id) VALUES ('" . $new_gid . "', " . $id . ")");
    $conn->query("UPDATE cena_invitaciones SET gid = '" . $new_gid . "' WHERE uid LIKE '%" . $_SESSION["uid"] . "%'");
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
  echo "<b>Invitación</b>: 0x" . substr($uid, -6) . "<br>";
}

if (!isset($_SESSION["uid"]) and isset($join_gid)) {
  echo "Escanea el QR de la invitación con la cámara de tu móvil antes de utilizar el enlace para unirte a un grupo.<br>";
  echo "Recuerda que debes usar el mismo dispositivo, navegador y sesión (evita usar el modo incógnito)";
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

$gid = getAssignedGroup($conn);
$gnum = getGroupNumber($conn, $gid);
$nmm = getGroupSize($conn, $gid);
$gt = getGroupTable($conn, $gid);
$gts = getGroupTableSeats($conn, $gid);
if (!isFrozen()) {
  echo "Ya no hace falta que esperes en la puerta para poder coger sitio. Si quieres, podrás ir a la cena acompañando a la charanga.<br>";
  echo "Este sistema de reservas permite a los socios unirse a grupos utilizando el QR de su invitación.<br>";
  echo "A la hora de la cena, cada grupo tendrá un lugar asignado en una mesa, sin necesidad de llegar pronto. Además, así ayudas a que no se reserven sitios de más y que finalmente no se utilicen.<br>";
  echo "Una vez eres parte de un grupo sólo tienes que esperar. Podrás ver aquí los asientos que se os han asignado justo antes de la cena.<br>";
} elseif ($gid == null) {
  echo "No formas parte de ningún grupo de reserva y el plazo está ya cerrado.<br>";
  echo "Por favor, dirígete hacia las mesas destinadas a los socios que acuden sin reserva, allí podréis sentaros libremente como en años anteriores.";
  exit(0);
} elseif ($gt != null and $gts != null) {
  echo "<b>Grupo</b>: #" . $gnum . "<br>";
  echo "<b>Mesa</b>: " . $gt . "<br>";
  echo "<b>Asiento"; if ($nmm > 1) { echo "s"; }echo "</b>: " . $gts[0];
  if ($nmm > 1) { echo "-" . $gts[$nmm-1]; }
  exit(0);
} else {
  echo "Las reservas están siendo asignadas, vuelve a comprobarlo escaneando el QR de tu invitación más adelante.";
  exit(0);
}

if ($gid) {
  echo "Eres parte del <b>GRUPO #" . $gnum . "</b>";
  if (!isFrozen()) {
    echo " <a href=\"" . $BASE_URL . "/?abandonar\" class=\"btn btn-danger\">Abandonar grupo</a><br>";
  }
  if ($nmm > 1) {
    echo "En este momento, en el grupo sois " . getGroupSize($conn, $gid) . " personas en total.<br>";
  } else {
    echo "Eres el único miembro de este grupo.<br>";
  }
  $url = $BASE_URL . "/?unirse=" . $gid;
  echo "Puedes invitar a tu grupo a otros socios que ya hayan escaneado su QR compartiendo con ellos un enlace:<br>";
  #echo "<a href=\"" . $url . "\"><div id=\"TextoACopiar\">" . $url . "</div></a> ";
  ?>
  <button id="BotonCopiar" class="btn btn-primary" onclick="copyOnClick()">Copiar enlace</button>
  <script type="text/javascript">
    function copyOnClick() {
      var codigoACopiar = document.getElementById('TextoACopiar');
      navigator.clipboard.writeText(codigoACopiar.innerHTML)
    }
  </script>
  <?php
  include("getWhatsapp.php");
} else {
  echo "Actualmente no formas parte de ningún grupo.<br> Ahora que has escaneado tu invitación, puedes unirte a uno existente a través de un enlace o crear uno nuevo. ";
  echo "<a href=\"" . $BASE_URL . "/?crear\" class=\"btn btn-primary\">Crear nuevo grupo</a><br>";
}
?>
Los socios pueden unirse a grupos hasta <?php echo getLimitMinutes(); ?> minutos antes del comienzo de la cena, momento en el que se asignarán los asientos.<br>
A partir de las <?php echo getMinutesBeforeTime(getPrintableEventTime(), getLimitMinutes()); ?>h escanea de nuevo tu QR para ver aquí la asignación final.
    </div>
  </div>
</div>
</body>
</html>
<?php $conn->close(); ?>
