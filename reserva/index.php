<?php
session_start();
$DEBUG = False;

include('../../php-require/phpqrcode.php');
require('../../php-require/mysql-elfollon.php');
require('helpers.php');
require('settings.php');
?>

<!DOCTYPE html>
<html lang="es">
<head>
<title>Reserva tu asiento en la <?php echo getEventName(); ?> - Peña "El Follón" <?php echo getEventYear(); ?></title>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
<link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
<link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
<link rel="manifest" href="/site.webmanifest">
<link rel="stylesheet" href="css/main.css">

<?php
$BASE_URL = getProtocol() . "://" . $_SERVER['SERVER_NAME'];
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

function getGroupMembers($conn, $gid) {
  if (!$gid) { return []; };
  $stmt = $conn->prepare("SELECT label FROM invitaciones WHERE gid=?");
  $stmt->bind_param("s", $gid);
  $stmt->execute();
  $result = $stmt->get_result();
  $members = [];
  while ($row = $result->fetch_assoc()) {
    $members[] = $row['label'];
  }
  sort($members);
  return $members;
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
  $date_event = getEventYear() . "-" . getEventMonthNumber() . "-" . getEventDay();
  return date("Y-m-d") == $date_event;
}

function getScanInstructions() {
  echo '<div class="scan-instructions"><img src="img/escanear-qr.gif" alt="Instrucciones para escanear QR" class="scan-gif"></div>';
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
    echo "<div class='error-message'>ERROR: Inténtelo de nuevo más adelante.</div>";
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
  return "";
}

function getIsUserInDatabase($conn, $uid) {
  $stmt = $conn->prepare("SELECT uid FROM invitaciones WHERE uid=?");
  $stmt->bind_param("s", $uid);
  $stmt->execute();
  $result = $stmt->get_result();
  return $result->fetch_row()[0] === $uid;
}

function isMasterHash($uid) {
  $masterHash = hash('sha1', 'El-Follon-Admin-' . getAdminSecret());
  return $uid === $masterHash;
}

function loadAllGroupsCSS() {
  $cssPath = "css/groups/";
  $groupFiles = glob($cssPath . "*.css");
  foreach ($groupFiles as $file) {
    echo "<link href=\"" . $file . "\" rel=\"stylesheet\">";
  }
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

$isMaster = false;
if (isset($uid) && isMasterHash($uid)) {
  $isMaster = true;
  $_SESSION["master"] = true;
} elseif (isset($uid) && getIsUserInDatabase($conn, $uid)) {
  debugPrint("Saving into SESSION uid: " . $uid);
  $_SESSION["uid"] = $uid;
} else {
  debugPrint("Unsetting uid.");
  unset($uid);
}

if (!isset($uid) && isset($_SESSION["uid"])) {
  $uid = $_SESSION["uid"];
}

$gid = getAssignedGroup($conn);
if (!is_null($gid) && isFrozen()) {
  echo "<link href=\"css/groups/" . $gid . ".css\" rel=\"stylesheet\">";
}

// For master view, load all group CSS files when time is frozen
if ($isMaster && isFrozen()) {
  loadAllGroupsCSS();
}

$gnum = getGroupNumber($conn, $gid);
$gmem = getGroupMembers($conn, $gid);
$nmm = getGroupSize($conn, $gid);
$gt = getGroupTable($conn, $gid);
$gts = getGroupTableSeats($conn, $gid);
?>
</head>
<body>
  <div class="header-container">
    <img src="img/logo.png" alt="Logo El Follón" class="logo">
    <h1><?php echo getEventName(); ?></h1>
    <img src="img/logo.png" alt="Logo El Follón" class="logo">
  </div>
  
  <div class="container">
    <div class="info-card">
      <div class="info-row">
        <span class="info-label">Fecha:</span>
        <span class="info-value"><?php echo getPrintableEventDay(); ?> de <?php echo getEventMonthText(); ?> de <?php echo getEventYear(); ?> (<?php echo getPrintableEventTime(); ?>h.)</span>
      </div>
      <div class="info-row">
        <span class="info-label">Lugar:</span>
        <span class="info-value"><?php echo getEventLocation(); ?></span>
      </div>

<?php
if ($isMaster) {
  echo "<div class='info-row'><span class='info-label'>Modo:</span><span class='info-value'>Administrador (vista general)</span></div>";
} elseif (!isset($uid)) {
  echo "<div class='error-message'>ERROR: Invitación no válida.</div>";
  if (!isset($join_gid)) {
    echo "<p>Por favor, primero <b>escanea el código QR</b> de la invitación para identificarte.</p>";
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
  echo "<div class='info-row'><span class='info-label'>Invitación:</span><span class='info-value'>" . $tag . "</span></div>";
}

debugPrint("<b>uid</b>: " . $uid . "<br>");
debugPrint("<b>_SESSION['uid']</b>: " . $_SESSION["uid"] . "<br>");
if (isset($join_gid)) { debugPrint("<b>join_gid</b>: " . $join_gid . "<br>"); }

if (!isset($_SESSION["uid"]) && !$isMaster && isset($join_gid)) {
  echo "<p>Escanea el QR de la invitación con la cámara de tu móvil <b>antes</b> de utilizar el enlace para unirte a un grupo.</p>";
  getScanInstructions();
  echo "<p>Recuerda que debes usar el mismo dispositivo, navegador y sesión. Evita el modo privado/incógnito.</p>";
  exit(1);
} elseif (isset($_SESSION["uid"]) && isset($join_gid)) {
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

if ((isset($_GET['crear'])) && (!isFrozen())) {
  if (is_null(getAssignedGroup($conn))) {
     createGroup($conn);
  }
  header('Location: /');
}

if ($gid && !$isMaster) {
  echo "<div class='info-row'><span class='info-label'>Grupo:</span><span class='info-value'>#" . $gnum . " (" . $nmm . " miembro" . getPlural($nmm) . ")</span></div>";
if (getGroupSize($conn, $gid) > 1) {
  ?>
  <div class="info-row members-dropdown-container">
    <div class="dropdown-container">
      <button class="dropdown-button" onclick="toggleDropdown()">
	<span>Ver miembros</span>
        <span class="dropdown-icon" id="dropdown-icon">+</span>
      </button>
      
      <div class="dropdown-content" id="dropdown-content">
        <?php if (empty($gmem) || count(array_filter($gmem, function($item) { return $item !== null; })) === 0): ?>
          <div class="no-items">No hay miembros disponibles</div>
        <?php else: ?>
          <ul class="dropdown-list">
            <?php foreach ($gmem as $member): ?>
              <?php if ($member !== null): ?>
                <li class="dropdown-item"><?php echo "#" . htmlspecialchars($member); ?></li>
              <?php endif; ?>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </div>
  </div>
  
  <script>
    function toggleDropdown() {
      const content = document.getElementById('dropdown-content');
      const icon = document.getElementById('dropdown-icon');
      
      if (content.style.maxHeight) {
        // Cerrar el desplegable
        content.style.maxHeight = null;
        icon.textContent = '+';
      } else {
        // Abrir el desplegable
        content.style.maxHeight = content.scrollHeight + "px";
        icon.textContent = '-';
      }
    }
  </script>
  <?php
}
}

if ($isMaster && isFrozen()) {
  echo "<div class='divider'></div>";
  echo "<div class='info-row'><span class='info-label'>Estado:</span><span class='info-value'>Vista completa de asientos asignados</span></div>";
} elseif (isFrozen() && !is_null($gt) && !is_null($gts)) {
  echo "<div class='info-row'><span class='info-label'>Mesa:</span><span class='info-value'>" . $gt . "</span></div>";
  echo "<div class='info-row'><span class='info-label'>Asiento" . getPlural($nmm) . ":</span><span class='info-value'>" . $gts[0];
  if ($nmm > 1) { echo "-" . $gts[$nmm-1]; }
  echo "</span></div>";
}

if (!$isMaster && !isFrozen()) {
  echo "<div class='divider'></div>";
  echo "<p>A la hora de empezar cada grupo tendrá un lugar asignado en una mesa.</p>";
  if (getEventIsToday()) {
    echo "<p>A partir de las <b>" . getMinutesBeforeTime(getPrintableEventTime(), getLimitMinutes()) . "h</b> escanea de nuevo tu QR para ver vuestros asientos.</p>";
  } else {
    echo "<p>Los grupos serán definitivos <b>" . getLimitMinutes() . " minutos</b> antes del comienzo.</p>";
  }
} elseif (!$isMaster && is_null($gid)) {
  echo "<div class='divider'></div>";
  echo "<p>No formas parte de ningún grupo de reserva y el plazo está ya cerrado.</p>";
  echo "<p>Por favor, dirígete hacia las mesas destinadas a los socios que acuden sin reserva, allí podréis sentaros libremente como en años anteriores.</p>";
  exit(0);
} elseif (($isMaster && isFrozen()) || (!is_null($gt) && !is_null($gts))) {
  echo "<div class='divider'></div>";
  echo "<div class='distribucion'>";
  
  if ($isMaster) {
    echo "<div class='distribucion-title'>Distribución general de mesas y asientos</div>";
  } else {
    echo "<div class='distribucion-title'>Distribución de mesas y asientos</div>";
  }
  
  // Nueva leyenda para asientos en rojo (movida arriba)
  echo "<div class='asientos-leyenda'><span class='asiento-ejemplo'></span> ";
  echo $isMaster ? "Todos los asientos asignados" : "Tus asientos asignados";
  echo "</div>";
  
  // Añadir contenedor con indicador de scroll
  echo "<div class='mesa-container' id='mesasContainer'>";
  include("mapa.html");
  echo "</div>";
  echo "<div class='scroll-indicator' id='scrollIndicator'>Desliza ⟷</div>";
  
  echo "</div>";
  
  // Script para mostrar indicador de scroll solo cuando sea necesario
  echo "<script>
  document.addEventListener('DOMContentLoaded', function() {
    const container = document.getElementById('mesasContainer');
    const indicator = document.getElementById('scrollIndicator');
    
    function checkScroll() {
      if (container.scrollWidth > container.clientWidth) {
        indicator.style.opacity = '1';
      } else {
        indicator.style.opacity = '0';
        container.classList.add('no-scroll');
      }
    }
    
    checkScroll();
    window.addEventListener('resize', checkScroll);
    
    container.addEventListener('scroll', function() {
      // Si está cerca del final, ocultar indicador
      if (container.scrollLeft + container.clientWidth >= container.scrollWidth - 20) {
        indicator.style.opacity = '0';
      } else {
        indicator.style.opacity = '1';
      }
    });
  });
  </script>";
} else {
  echo "<div class='divider'></div>";
  echo "<p>Las reservas están siendo asignadas, vuelve a comprobarlo escaneando el QR de tu invitación más adelante.</p>";
  exit(0);
}

if ($gid && !isFrozen() && !$isMaster) {
  $url = $BASE_URL . "/?unirse=" . $gid;
  ?>
  <div class="divider"></div>
  <p>Puedes invitar al <b>GRUPO #<?php echo $gnum; ?></b> a otros:</p>

  <div class="button-container">
    <div id="TextoACopiar" hidden><?php echo $url; ?></div>
    <button id="BotonCopiar" class="btn btn-primary" onclick="copyOnClick()">Copiar enlace</button>
    
    <a href="whatsapp://send?text=<?php echo urlencode('Escanea el QR de tu invitación y luego únete a mi grupo desde aquí: ' . $url); ?>" class="btn btn-whatsapp">
      <img alt="Compartir en WhatsApp" src="img/whatsapp.svg">
      <span class="btn-whatsapp-label">Compartir</span>
    </a>
    
    <a href="<?php echo $BASE_URL; ?>/?abandonar" class="btn btn-danger">Abandonar grupo</a>
  </div>
  
  <script>
    function copyOnClick() {
      var codigoACopiar = document.getElementById('TextoACopiar').innerText;
      var boton = document.getElementById('BotonCopiar');
      var textoOriginal = boton.innerText;

      navigator.clipboard.writeText(codigoACopiar).then(() => {
        boton.innerText = "¡Copiado!";
        boton.classList.add('copiado');

        // Mostrar un popup en iOS si no se ve feedback
        if (navigator.userAgent.includes("Safari") && !navigator.userAgent.includes("Chrome")) {
          alert("Enlace copiado al portapapeles.");
        }

        setTimeout(() => {
          boton.innerText = textoOriginal;
          boton.classList.remove('copiado');
        }, 1500);
      }).catch(err => {
        console.error("Error al copiar: ", err);
        alert("Hubo un problema al copiar el enlace.");
      });
    }
  </script>
<?php } elseif (!$isMaster && !$gid && !isFrozen()) { ?>
  <div class="divider"></div>
  <p><b>No formas parte de ningún grupo</b>, puedes unirte a uno existente a través de un enlace o crear uno nuevo e invitar a otros.</p>
  <div class="button-container">
    <a href="<?php echo $BASE_URL; ?>/?crear" class="btn btn-primary">Crear nuevo grupo</a>
  </div>
<?php } ?>
    </div>
  </div>
</body>
</html>
<?php $conn->close(); ?>
