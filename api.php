<?php
// ================= api.php - VERSIÓN ESTABLE =================
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$archivoDatos = 'datos_nerume.json';
$directorioUploads = 'uploads/';
$tamanoMaximoArchivo = 40 * 1024 * 1024;

// Crear directorios
foreach (['', 'imagens/', 'videos/', 'audios/'] as $carpeta) {
    $ruta = $directorioUploads . $carpeta;
    if (!file_exists($ruta)) mkdir($ruta, 0777, true);
}

function leerDatos() {
    global $archivoDatos;
    if (!file_exists($archivoDatos)) {
        $estructura = [
            'version' => 48,
            'ultima_modificacion' => date('c'),
            'server_time' => time(),
            'proyectos' => [],
            'usuarios' => [],
            'comentarios' => [],
            'notificaciones' => [],
            'dms' => [],
            'dispositivos' => [],
            'dispositivos_baneados' => [],
            'cartas_magicas' => []
        ];
        file_put_contents($archivoDatos, json_encode($estructura, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        return $estructura;
    }
    $contenido = file_get_contents($archivoDatos);
    $datos = json_decode($contenido, true) ?? [];
    
    // Asegurar estructuras mínimas
    if (!isset($datos['proyectos'])) $datos['proyectos'] = [];
if (!isset($datos['usuarios'])) $datos['usuarios'] = [];
if (!isset($datos['comentarios'])) $datos['comentarios'] = [];
if (!isset($datos['notificaciones'])) $datos['notificaciones'] = [];
if (!isset($datos['dms'])) $datos['dms'] = [];
if (!isset($datos['soporte'])) $datos['soporte'] = [];
    
    // Asegurar campos en usuarios
   // Asegurar campos en usuarios
foreach ($datos['usuarios'] as &$u) {
    if (!isset($u['biografia'])) $u['biografia'] = '';
    if (!isset($u['lema'])) $u['lema'] = '';
    if (!isset($u['amigos'])) $u['amigos'] = [];
    if (!isset($u['solicitudes_pendientes'])) $u['solicitudes_pendientes'] = [];
    if (!isset($u['bloqueados'])) $u['bloqueados'] = [];  // <-- AGREGAR ESTA LÍNEA
}
    
    return $datos;
}

function escribirDatos($datos) {
    global $archivoDatos;
    $datos['ultima_modificacion'] = date('c');
    $datos['server_time'] = time();
    return file_put_contents($archivoDatos, json_encode($datos, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

// ================= OBTENER DATOS =================
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['accion']) && $_GET['accion'] === 'obtener_todo') {
    $datos = leerDatos();
    // Ocultar contraseñas
    foreach ($datos['usuarios'] as &$u) {
        unset($u['password']);
    }
    echo json_encode($datos);
    exit;
}

// ================= NUEVO MENSAJE =================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['accion']) && $_GET['accion'] === 'nuevo_mensaje') {
    $entrada = json_decode(file_get_contents('php://input'), true);
    $autor = $entrada['autor'] ?? '';
    $autorId = $entrada['autorId'] ?? '';
    $contenido = trim($entrada['contenido'] ?? '');
    $canal = $entrada['canal'] ?? 'general';
    
    if (!$autor || !$contenido) {
        echo json_encode(['exito' => false, 'error' => 'DATOS_INCOMPLETOS']);
        exit;
    }
    
    $datos = leerDatos();
    if (!isset($datos['comentarios'])) $datos['comentarios'] = [];
    
    $mensaje = [
        'id' => 'msg_' . time() . '_' . uniqid(),
        'autor' => $autor,
        'autorId' => $autorId,
        'contenido' => htmlspecialchars($contenido),
        'fecha' => date('c'),
        'canal' => $canal,
        'tipo' => 'canal'
    ];
    
    $datos['comentarios'][] = $mensaje;
    if (count($datos['comentarios']) > 500) {
        $datos['comentarios'] = array_slice($datos['comentarios'], -500);
    }
    
    escribirDatos($datos);
    echo json_encode(['exito' => true, 'mensaje' => $mensaje]);
    exit;
}

// ================= GUARDAR PROYECTO =================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['accion']) && $_GET['accion'] === 'guardar_proyecto') {
    $entrada = json_decode(file_get_contents('php://input'), true);
    $proyecto = $entrada['proyecto'] ?? null;
    $userId = $entrada['userId'] ?? '';
    
    if (!$proyecto || !isset($proyecto['id']) || !$userId) {
        echo json_encode(['exito' => false, 'error' => 'DATOS_INVALIDOS']);
        exit;
    }
    
    $datos = leerDatos();
    if (!isset($datos['proyectos'])) $datos['proyectos'] = [];
    
    if (!isset($proyecto['likes'])) $proyecto['likes'] = 0;
    if (!isset($proyecto['usuariosLike'])) $proyecto['usuariosLike'] = [];
    
    $indiceExistente = -1;
    foreach ($datos['proyectos'] as $idx => $p) {
        if ($p['id'] === $proyecto['id']) {
            $indiceExistente = $idx;
            break;
        }
    }
    
    $proyecto['ultimaModificacion'] = date('c');
    
    if ($indiceExistente !== -1) {
        $datos['proyectos'][$indiceExistente] = $proyecto;
    } else {
        $datos['proyectos'][] = $proyecto;
    }
    
    escribirDatos($datos);
    echo json_encode(['exito' => true]);
    exit;
}

// ================= SUBIR ARCHIVO =================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['archivo'])) {
    $archivo = $_FILES['archivo'];
    $userId = $_POST['userId'] ?? '';
    
    if (!$userId) {
        echo json_encode(['exito' => false, 'error' => 'USUARIO_NO_IDENTIFICADO']);
        exit;
    }
    
    if ($archivo['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['exito' => false, 'error' => 'ERROR_SUBIDA']);
        exit;
    }
    
    if ($archivo['size'] > $tamanoMaximoArchivo) {
        echo json_encode(['exito' => false, 'error' => 'ARCHIVO_DEMASIADO_GRANDE']);
        exit;
    }
    
    $extension = strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION));
    $tiposImagen = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $tiposVideo = ['mp4', 'webm', 'ogg'];
    $tiposAudio = ['mp3', 'wav', 'ogg', 'm4a'];
    
    $carpeta = '';
    if (in_array($extension, $tiposImagen)) $carpeta = 'imagens/';
    elseif (in_array($extension, $tiposVideo)) $carpeta = 'videos/';
    elseif (in_array($extension, $tiposAudio)) $carpeta = 'audios/';
    else {
        echo json_encode(['exito' => false, 'error' => 'TIPO_NO_PERMITIDO']);
        exit;
    }
    
    $userDir = $directorioUploads . $carpeta . $userId . '/';
    if (!file_exists($userDir)) mkdir($userDir, 0777, true);
    
    $nombreArchivo = time() . '_' . uniqid() . '.' . $extension;
    $rutaCompleta = $userDir . $nombreArchivo;
    
    if (move_uploaded_file($archivo['tmp_name'], $rutaCompleta)) {
        $url = '/uploads/' . $carpeta . $userId . '/' . $nombreArchivo;
        echo json_encode(['exito' => true, 'url' => $url]);
    } else {
        echo json_encode(['exito' => false, 'error' => 'NO_SE_PUDO_GUARDAR']);
    }
    exit;
}

// ================= GUARDAR COMENTARIO =================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['accion']) && $_GET['accion'] === 'guardar_comentario') {
    $entrada = json_decode(file_get_contents('php://input'), true);
    $comentario = $entrada['comentario'] ?? null;
    $userId = $entrada['userId'] ?? '';
    
    if (!$comentario || !isset($comentario['id']) || !$userId) {
        echo json_encode(['exito' => false, 'error' => 'DATOS_INVALIDOS']);
        exit;
    }
    
    $datos = leerDatos();
    if (!isset($datos['comentarios'])) $datos['comentarios'] = [];
    
    if (!isset($comentario['likes'])) $comentario['likes'] = 0;
    if (!isset($comentario['usuariosLike'])) $comentario['usuariosLike'] = [];
    
    $indiceExistente = -1;
    foreach ($datos['comentarios'] as $idx => $c) {
        if ($c['id'] === $comentario['id']) {
            $indiceExistente = $idx;
            break;
        }
    }
    
    if ($indiceExistente !== -1) {
        $datos['comentarios'][$indiceExistente] = $comentario;
    } else {
        $datos['comentarios'][] = $comentario;
    }
    
    if (count($datos['comentarios']) > 1000) {
        $datos['comentarios'] = array_slice($datos['comentarios'], -1000);
    }
    
    escribirDatos($datos);
    echo json_encode(['exito' => true]);
    exit;
}

// ================= ELIMINAR COMENTARIO =================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['accion']) && $_GET['accion'] === 'eliminar_comentario') {
    $entrada = json_decode(file_get_contents('php://input'), true);
    $comentarioId = $entrada['comentarioId'] ?? '';
    $userId = $entrada['userId'] ?? '';
    
    if (!$comentarioId || !$userId) {
        echo json_encode(['exito' => false, 'error' => 'DATOS_INVALIDOS']);
        exit;
    }
    
    $datos = leerDatos();
    
    $comentario = null;
    foreach ($datos['comentarios'] as $c) {
        if ($c['id'] === $comentarioId) {
            $comentario = $c;
            break;
        }
    }
    
    if (!$comentario) {
        echo json_encode(['exito' => false, 'error' => 'COMENTARIO_NO_ENCONTRADO']);
        exit;
    }
    
    $esAdmin = false;
    foreach ($datos['usuarios'] as $u) {
        if ($u['id'] === $userId && ($u['nombre'] === 'nere1d' || ($u['es_sudo'] ?? false))) {
            $esAdmin = true;
            break;
        }
    }
    
    $puedeEliminar = ($comentario['autorId'] === $userId) || $esAdmin;
    
    if (!$puedeEliminar) {
        echo json_encode(['exito' => false, 'error' => 'SIN_PERMISO']);
        exit;
    }
    
    $datos['comentarios'] = array_values(array_filter($datos['comentarios'], function($c) use ($comentarioId) {
        return $c['id'] !== $comentarioId;
    }));
    
    escribirDatos($datos);
    echo json_encode(['exito' => true]);
    exit;
}

// ================= PING =================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['accion']) && $_GET['accion'] === 'ping') {
    $entrada = json_decode(file_get_contents('php://input'), true);
    $userId = $entrada['userId'] ?? '';
    
    if (!$userId) {
        echo json_encode(['exito' => false]);
        exit;
    }
    
    $datos = leerDatos();
    foreach ($datos['usuarios'] as &$u) {
        if ($u['id'] === $userId) {
            $u['conectado'] = true;
            $u['ultimo_ping'] = time();
            break;
        }
    }
    
    escribirDatos($datos);
    echo json_encode(['exito' => true]);
    exit;
}
// ================= ACTUALIZAR PERFIL (biografía y lema) =================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['accion']) && $_GET['accion'] === 'actualizar_perfil') {
    $input = json_decode(file_get_contents('php://input'), true);
    $usuarioId = $input['usuarioId'] ?? '';
    $biografia = $input['biografia'] ?? '';
    $lema = $input['lema'] ?? '';
    
    if (!$usuarioId) {
        echo json_encode(['exito' => false, 'error' => 'USUARIO_NO_ESPECIFICADO']);
        exit;
    }
    
    $datos = leerDatos();
    
    $usuarioEncontrado = false;
    foreach ($datos['usuarios'] as &$u) {
        if ($u['id'] === $usuarioId) {
            $u['biografia'] = substr($biografia, 0, 500);
            $u['lema'] = substr($lema, 0, 60);
            $usuarioEncontrado = true;
            break;
        }
    }
    
    if (!$usuarioEncontrado) {
        echo json_encode(['exito' => false, 'error' => 'USUARIO_NO_ENCONTRADO']);
        exit;
    }
    
    if (escribirDatos($datos)) {
        echo json_encode(['exito' => true]);
    } else {
        echo json_encode(['exito' => false, 'error' => 'ERROR_AL_GUARDAR']);
    }
    exit;
}

// ================= ACTUALIZAR AVATAR =================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['accion']) && $_GET['accion'] === 'actualizar_avatar') {
    $input = json_decode(file_get_contents('php://input'), true);
    $usuarioId = $input['usuarioId'] ?? '';
    $avatar = $input['avatar'] ?? '';
    
    if (!$usuarioId || !$avatar) {
        echo json_encode(['exito' => false, 'error' => 'DATOS_INCOMPLETOS']);
        exit;
    }
    
    $datos = leerDatos();
    
    foreach ($datos['usuarios'] as &$u) {
        if ($u['id'] === $usuarioId) {
            $u['avatar'] = $avatar;
            break;
        }
    }
    
    if (escribirDatos($datos)) {
        echo json_encode(['exito' => true]);
    } else {
        echo json_encode(['exito' => false, 'error' => 'ERROR_AL_GUARDAR']);
    }
    exit;
}

// ================= OBTENER PERFIL PÚBLICO =================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['accion']) && $_GET['accion'] === 'obtener_perfil_publico') {
    $input = json_decode(file_get_contents('php://input'), true);
    $usuarioId = $input['usuarioId'] ?? '';
    $miId = $input['miId'] ?? '';
    
    if (!$usuarioId) {
        echo json_encode(['exito' => false, 'error' => 'USUARIO_NO_ESPECIFICADO']);
        exit;
    }
    
    $datos = leerDatos();
    
    $usuario = null;
    foreach ($datos['usuarios'] as $u) {
        if ($u['id'] === $usuarioId) {
            $usuario = $u;
            break;
        }
    }
    
    if (!$usuario) {
        echo json_encode(['exito' => false, 'error' => 'USUARIO_NO_ENCONTRADO']);
        exit;
    }
    
    // Contar proyectos
    $proyectosCount = count($usuario['proyectos'] ?? []);
    
    // Contar likes recibidos
    $likesRecibidos = 0;
    foreach ($datos['proyectos'] as $p) {
        if (in_array($p['id'], $usuario['proyectos'] ?? [])) {
            $likesRecibidos += $p['likes'] ?? 0;
        }
    }
    
    // Verificar relación con el usuario actual
    $esAmigo = false;
    $solicitudPendiente = false;
    
    if ($miId && $miId !== $usuarioId) {
        $amigos = $usuario['amigos'] ?? [];
        $esAmigo = in_array($miId, $amigos);
        
        $pendientes = $usuario['solicitudes_pendientes'] ?? [];
        $solicitudPendiente = in_array($miId, $pendientes);
        
        // También verificar si el usuario actual tiene solicitud pendiente
        if (!$solicitudPendiente && $miId) {
            foreach ($datos['usuarios'] as $u) {
                if ($u['id'] === $miId) {
                    $misPendientes = $u['solicitudes_pendientes'] ?? [];
                    $solicitudPendiente = in_array($usuarioId, $misPendientes);
                    break;
                }
            }
        }
    }
    
    echo json_encode([
        'exito' => true,
        'perfil' => [
            'id' => $usuario['id'],
            'nombre' => $usuario['nombre'],
            'avatar' => $usuario['avatar'] ?? '👤',
            'biografia' => $usuario['biografia'] ?? '',
            'lema' => $usuario['lema'] ?? '',
            'fechaRegistro' => $usuario['fechaRegistro'],
            'proyectosCount' => $proyectosCount,
            'likesRecibidos' => $likesRecibidos,
            'amigosCount' => count($usuario['amigos'] ?? []),
            'esAmigo' => $esAmigo,
            'solicitudPendiente' => $solicitudPendiente
        ]
    ]);
    exit;
}


// ================= ENVIAR SOLICITUD DE AMISTAD =================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['accion']) && $_GET['accion'] === 'enviar_solicitud') {
    $input = json_decode(file_get_contents('php://input'), true);
    $emisorId = $input['emisorId'] ?? '';
    $receptorId = $input['receptorId'] ?? '';
    
    if (!$emisorId || !$receptorId) {
        echo json_encode(['exito' => false, 'error' => 'DATOS_INCOMPLETOS']);
        exit;
    }
    
    if ($emisorId === $receptorId) {
        echo json_encode(['exito' => false, 'error' => 'NO_PUEDES_AGREGARTE_A_TI_MISMO']);
        exit;
    }
    
    $datos = leerDatos();
    
    // Buscar receptor
    $receptorIndex = null;
    foreach ($datos['usuarios'] as $i => $u) {
        if ($u['id'] === $receptorId) {
            $receptorIndex = $i;
            break;
        }
    }
    
    if ($receptorIndex === null) {
        echo json_encode(['exito' => false, 'error' => 'RECEPTOR_NO_ENCONTRADO']);
        exit;
    }
    
    // Verificar si ya son amigos
    $amigos = $datos['usuarios'][$receptorIndex]['amigos'] ?? [];
    if (in_array($emisorId, $amigos)) {
        echo json_encode(['exito' => false, 'error' => 'YA_SON_AMIGOS']);
        exit;
    }
    
    // Verificar si ya hay solicitud pendiente
    $pendientes = $datos['usuarios'][$receptorIndex]['solicitudes_pendientes'] ?? [];
    if (in_array($emisorId, $pendientes)) {
        echo json_encode(['exito' => false, 'error' => 'SOLICITUD_YA_ENVIADA']);
        exit;
    }
    
    // Agregar solicitud
    if (!isset($datos['usuarios'][$receptorIndex]['solicitudes_pendientes'])) {
        $datos['usuarios'][$receptorIndex]['solicitudes_pendientes'] = [];
    }
    $datos['usuarios'][$receptorIndex]['solicitudes_pendientes'][] = $emisorId;
    
    // Crear notificación
    $emisorNombre = '';
    foreach ($datos['usuarios'] as $u) {
        if ($u['id'] === $emisorId) {
            $emisorNombre = $u['nombre'];
            break;
        }
    }
    
    if (!isset($datos['notificaciones'])) $datos['notificaciones'] = [];
    $datos['notificaciones'][] = [
        'id' => 'notif_' . time() . '_' . uniqid(),
        'usuarioId' => $receptorId,
        'tipo' => 'solicitud',
        'mensaje' => "@{$emisorNombre} te ha enviado una solicitud de amistad",
        'url' => "/perfil/{$emisorNombre}",
        'leida' => false,
        'fecha' => date('c')
    ];
    
    if (escribirDatos($datos)) {
        echo json_encode(['exito' => true]);
    } else {
        echo json_encode(['exito' => false, 'error' => 'ERROR_AL_GUARDAR']);
    }
    exit;
}


// ================= RESPONDER SOLICITUD DE AMISTAD =================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['accion']) && $_GET['accion'] === 'responder_solicitud') {
    $input = json_decode(file_get_contents('php://input'), true);
    $usuarioId = $input['usuarioId'] ?? '';
    $solicitanteId = $input['solicitanteId'] ?? '';
    $accion = $input['accion'] ?? '';
    
    if (!$usuarioId || !$solicitanteId || !in_array($accion, ['aceptar', 'rechazar'])) {
        echo json_encode(['exito' => false, 'error' => 'DATOS_INVALIDOS']);
        exit;
    }
    
    $datos = leerDatos();
    
    $usuarioIndex = null;
    foreach ($datos['usuarios'] as $i => $u) {
        if ($u['id'] === $usuarioId) {
            $usuarioIndex = $i;
            break;
        }
    }
    
    if ($usuarioIndex === null) {
        echo json_encode(['exito' => false, 'error' => 'USUARIO_NO_ENCONTRADO']);
        exit;
    }
    
    // Eliminar de solicitudes pendientes
    $pendientes = $datos['usuarios'][$usuarioIndex]['solicitudes_pendientes'] ?? [];
    $datos['usuarios'][$usuarioIndex]['solicitudes_pendientes'] = array_values(array_filter($pendientes, function($id) use ($solicitanteId) {
        return $id !== $solicitanteId;
    }));
    
    if ($accion === 'aceptar') {
        // Agregar a amigos mutuamente
        if (!isset($datos['usuarios'][$usuarioIndex]['amigos'])) {
            $datos['usuarios'][$usuarioIndex]['amigos'] = [];
        }
        $datos['usuarios'][$usuarioIndex]['amigos'][] = $solicitanteId;
        
        // Agregar al solicitante
        foreach ($datos['usuarios'] as $i => $u) {
            if ($u['id'] === $solicitanteId) {
                if (!isset($datos['usuarios'][$i]['amigos'])) {
                    $datos['usuarios'][$i]['amigos'] = [];
                }
                $datos['usuarios'][$i]['amigos'][] = $usuarioId;
                break;
            }
        }
        
        // Notificación de aceptación
        $usuarioNombre = $datos['usuarios'][$usuarioIndex]['nombre'];
        if (!isset($datos['notificaciones'])) $datos['notificaciones'] = [];
        $datos['notificaciones'][] = [
            'id' => 'notif_' . time() . '_' . uniqid(),
            'usuarioId' => $solicitanteId,
            'tipo' => 'solicitud',
            'mensaje' => "@{$usuarioNombre} ha aceptado tu solicitud de amistad",
            'url' => "/perfil/{$usuarioNombre}",
            'leida' => false,
            'fecha' => date('c')
        ];
    }
    
    if (escribirDatos($datos)) {
        echo json_encode(['exito' => true]);
    } else {
        echo json_encode(['exito' => false, 'error' => 'ERROR_AL_GUARDAR']);
    }
    exit;
}

// ================= OBTENER AMIGOS Y SOLICITUDES =================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['accion']) && $_GET['accion'] === 'obtener_amigos') {
    $input = json_decode(file_get_contents('php://input'), true);
    $usuarioId = $input['usuarioId'] ?? '';
    
    if (!$usuarioId) {
        echo json_encode(['exito' => false, 'error' => 'USUARIO_NO_ESPECIFICADO']);
        exit;
    }
    
    $datos = leerDatos();
    
    $usuario = null;
    foreach ($datos['usuarios'] as $u) {
        if ($u['id'] === $usuarioId) {
            $usuario = $u;
            break;
        }
    }
    
    if (!$usuario) {
        echo json_encode(['exito' => false, 'error' => 'USUARIO_NO_ENCONTRADO']);
        exit;
    }
    
    $amigosIds = $usuario['amigos'] ?? [];
    $amigos = [];
    foreach ($datos['usuarios'] as $u) {
        if (in_array($u['id'], $amigosIds)) {
            $amigos[] = [
                'id' => $u['id'],
                'nombre' => $u['nombre'],
                'avatar' => $u['avatar'] ?? '👤',
                'conectado' => $u['conectado'] ?? false
            ];
        }
    }
    
    $solicitudesIds = $usuario['solicitudes_pendientes'] ?? [];
    $solicitudes = [];
    foreach ($datos['usuarios'] as $u) {
        if (in_array($u['id'], $solicitudesIds)) {
            $solicitudes[] = [
                'id' => $u['id'],
                'nombre' => $u['nombre'],
                'avatar' => $u['avatar'] ?? '👤'
            ];
        }
    }
    
    echo json_encode(['exito' => true, 'amigos' => $amigos, 'solicitudes' => $solicitudes]);
    exit;
}

// ================= OBTENER NOTIFICACIONES =================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['accion']) && $_GET['accion'] === 'obtener_notificaciones') {
    $input = json_decode(file_get_contents('php://input'), true);
    $usuarioId = $input['usuarioId'] ?? '';
    
    if (!$usuarioId) {
        echo json_encode(['exito' => false, 'error' => 'USUARIO_NO_ESPECIFICADO']);
        exit;
    }
    
    $datos = leerDatos();
    
    $notificaciones = array_filter($datos['notificaciones'] ?? [], function($n) use ($usuarioId) {
        return $n['usuarioId'] === $usuarioId;
    });
    
    usort($notificaciones, function($a, $b) {
        return strtotime($b['fecha']) - strtotime($a['fecha']);
    });
    
    echo json_encode(['exito' => true, 'notificaciones' => array_values($notificaciones)]);
    exit;
}

// ================= MARCAR NOTIFICACIÓN COMO LEÍDA =================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['accion']) && $_GET['accion'] === 'marcar_notificacion_leida') {
    $input = json_decode(file_get_contents('php://input'), true);
    $notificacionId = $input['notificacionId'] ?? '';
    $usuarioId = $input['usuarioId'] ?? '';
    
    if (!$notificacionId || !$usuarioId) {
        echo json_encode(['exito' => false, 'error' => 'DATOS_INCOMPLETOS']);
        exit;
    }
    
    $datos = leerDatos();
    
    foreach ($datos['notificaciones'] as &$n) {
        if ($n['id'] === $notificacionId && $n['usuarioId'] === $usuarioId) {
            $n['leida'] = true;
            break;
        }
    }
    
    if (escribirDatos($datos)) {
        echo json_encode(['exito' => true]);
    } else {
        echo json_encode(['exito' => false, 'error' => 'ERROR_AL_GUARDAR']);
    }
    exit;
}

// ================= ENVIAR MENSAJE DIRECTO (DM) =================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['accion']) && $_GET['accion'] === 'enviar_dm') {
    $input = json_decode(file_get_contents('php://input'), true);
    $emisorId = $input['emisorId'] ?? '';
    $receptorId = $input['receptorId'] ?? '';
    $contenido = trim($input['contenido'] ?? '');
    
    if (!$emisorId || !$receptorId || !$contenido) {
        echo json_encode(['exito' => false, 'error' => 'DATOS_INCOMPLETOS']);
        exit;
    }
    
    $datos = leerDatos();
    
    // Verificar que sean amigos
    $sonAmigos = false;
    $emisorNombre = '';
    $receptorNombre = '';
    
    foreach ($datos['usuarios'] as $u) {
        if ($u['id'] === $emisorId) {
            $emisorNombre = $u['nombre'];
            $amigos = $u['amigos'] ?? [];
            if (in_array($receptorId, $amigos)) {
                $sonAmigos = true;
            }
        }
        if ($u['id'] === $receptorId) {
            $receptorNombre = $u['nombre'];
        }
    }
    
    if (!$sonAmigos) {
        echo json_encode(['exito' => false, 'error' => 'NO_SON_AMIGOS']);
        exit;
    }
    
    // Buscar o crear DM
    $dmEncontrado = null;
    $dmIndex = null;
    
    if (!isset($datos['dms'])) $datos['dms'] = [];
    
    foreach ($datos['dms'] as $i => $d) {
        if (in_array($emisorId, $d['participantes']) && in_array($receptorId, $d['participantes'])) {
            $dmEncontrado = $d;
            $dmIndex = $i;
            break;
        }
    }
    
    $nuevoMensaje = [
        'id' => 'msg_' . time() . '_' . uniqid(),
        'autorId' => $emisorId,
        'contenido' => htmlspecialchars(substr($contenido, 0, 500)),
        'fecha' => date('c'),
        'leido' => false
    ];
    
    if ($dmEncontrado) {
        $datos['dms'][$dmIndex]['mensajes'][] = $nuevoMensaje;
        $datos['dms'][$dmIndex]['ultimo_mensaje'] = date('c');
    } else {
        $nuevoDM = [
            'id' => 'dm_' . time() . '_' . uniqid(),
            'participantes' => [$emisorId, $receptorId],
            'mensajes' => [$nuevoMensaje],
            'ultimo_mensaje' => date('c'),
            'actualizado' => date('c')
        ];
        $datos['dms'][] = $nuevoDM;
    }
    
    // Crear notificación para el receptor
    if (!isset($datos['notificaciones'])) $datos['notificaciones'] = [];
    $datos['notificaciones'][] = [
        'id' => 'notif_' . time() . '_' . uniqid(),
        'usuarioId' => $receptorId,
        'tipo' => 'dm',
        'mensaje' => "@{$emisorNombre} te ha enviado un mensaje privado",
        'url' => "/dm/{$emisorNombre}",
        'leida' => false,
        'fecha' => date('c')
    ];
    
    if (escribirDatos($datos)) {
        echo json_encode(['exito' => true]);
    } else {
        echo json_encode(['exito' => false, 'error' => 'ERROR_AL_GUARDAR']);
    }
    exit;
}

// ================= OBTENER MENSAJES DM =================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['accion']) && $_GET['accion'] === 'obtener_dm') {
    $input = json_decode(file_get_contents('php://input'), true);
    $usuarioId = $input['usuarioId'] ?? '';
    $otroUsuarioId = $input['otroUsuarioId'] ?? '';
    
    if (!$usuarioId || !$otroUsuarioId) {
        echo json_encode(['exito' => false, 'error' => 'DATOS_INCOMPLETOS']);
        exit;
    }
    
    $datos = leerDatos();
    
    // Buscar DM entre estos dos usuarios
    $dm = null;
    foreach ($datos['dms'] ?? [] as $d) {
        if (in_array($usuarioId, $d['participantes']) && in_array($otroUsuarioId, $d['participantes'])) {
            $dm = $d;
            break;
        }
    }
    
    if (!$dm) {
        echo json_encode(['exito' => true, 'mensajes' => []]);
        exit;
    }
    
    // Marcar mensajes como leídos
    foreach ($dm['mensajes'] as &$msg) {
        if ($msg['autorId'] !== $usuarioId && !$msg['leido']) {
            $msg['leido'] = true;
        }
    }
    
    // Guardar cambios
    foreach ($datos['dms'] as &$d) {
        if ($d['id'] === $dm['id']) {
            $d = $dm;
            break;
        }
    }
    escribirDatos($datos);
    
    echo json_encode(['exito' => true, 'mensajes' => $dm['mensajes']]);
    exit;
}


// ================= ELIMINAR AMIGO =================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['accion']) && $_GET['accion'] === 'eliminar_amigo') {
    $input = json_decode(file_get_contents('php://input'), true);
    $usuarioId = $input['usuarioId'] ?? '';
    $amigoId = $input['amigoId'] ?? '';
    
    if (!$usuarioId || !$amigoId) {
        echo json_encode(['exito' => false, 'error' => 'DATOS_INCOMPLETOS']);
        exit;
    }
    
    $datos = leerDatos();
    
    // Eliminar de ambos lados
    foreach ($datos['usuarios'] as &$u) {
        if ($u['id'] === $usuarioId) {
            $u['amigos'] = array_values(array_filter($u['amigos'] ?? [], function($id) use ($amigoId) {
                return $id !== $amigoId;
            }));
        }
        if ($u['id'] === $amigoId) {
            $u['amigos'] = array_values(array_filter($u['amigos'] ?? [], function($id) use ($usuarioId) {
                return $id !== $usuarioId;
            }));
        }
    }
    
    if (escribirDatos($datos)) {
        echo json_encode(['exito' => true]);
    } else {
        echo json_encode(['exito' => false, 'error' => 'ERROR_AL_GUARDAR']);
    }
    exit;
}


// ================= BUSCAR USUARIOS =================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['accion']) && $_GET['accion'] === 'buscar_usuarios') {
    $input = json_decode(file_get_contents('php://input'), true);
    $termino = $input['termino'] ?? '';
    $usuarioActualId = $input['usuarioActualId'] ?? '';
    
    if (!$termino) {
        echo json_encode(['exito' => true, 'usuarios' => []]);
        exit;
    }
    
    $datos = leerDatos();
    
    $resultados = [];
    foreach ($datos['usuarios'] as $u) {
        if (stripos($u['nombre'], $termino) !== false && $u['id'] !== $usuarioActualId && empty($u['baneado'])) {
            $resultados[] = [
                'id' => $u['id'],
                'nombre' => $u['nombre'],
                'avatar' => $u['avatar'] ?? '👤',
                'esAmigo' => in_array($u['id'], $u['amigos'] ?? []),
                'solicitudEnviada' => in_array($u['id'], $u['solicitudes_pendientes'] ?? [])
            ];
        }
    }
    
    echo json_encode(['exito' => true, 'usuarios' => array_slice($resultados, 0, 20)]);
    exit;
}

// ================= OBTENER ESTADÍSTICAS DEL PERFIL =================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['accion']) && $_GET['accion'] === 'obtener_stats_perfil') {
    $input = json_decode(file_get_contents('php://input'), true);
    $usuarioId = $input['usuarioId'] ?? '';
    
    if (!$usuarioId) {
        echo json_encode(['exito' => false, 'error' => 'USUARIO_NO_ESPECIFICADO']);
        exit;
    }
    
    $datos = leerDatos();
    
    $usuario = null;
    foreach ($datos['usuarios'] as $u) {
        if ($u['id'] === $usuarioId) {
            $usuario = $u;
            break;
        }
    }
    
    if (!$usuario) {
        echo json_encode(['exito' => false, 'error' => 'USUARIO_NO_ENCONTRADO']);
        exit;
    }
    
    // Proyectos propios
    $proyectosPropios = array_filter($datos['proyectos'], function($p) use ($usuarioId) {
        return $p['dueñoId'] === $usuarioId;
    });
    
    // Likes recibidos
    $likesRecibidos = 0;
    foreach ($proyectosPropios as $p) {
        $likesRecibidos += $p['likes'] ?? 0;
    }
    
    // Comentarios recibidos en proyectos propios
    $comentariosRecibidos = 0;
    foreach ($datos['comentarios'] as $c) {
        foreach ($proyectosPropios as $p) {
            if (isset($c['proyectoId']) && $c['proyectoId'] === $p['id']) {
                $comentariosRecibidos++;
            }
        }
    }
    
    echo json_encode([
        'exito' => true,
        'stats' => [
            'proyectos' => count($proyectosPropios),
            'likes_recibidos' => $likesRecibidos,
            'comentarios_recibidos' => $comentariosRecibidos,
            'amigos' => count($usuario['amigos'] ?? []),
            'fecha_registro' => $usuario['fechaRegistro']
        ]
    ]);
    exit;
}

// ================= OBTENER LISTA DE CONVERSACIONES DM =================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['accion']) && $_GET['accion'] === 'obtener_conversaciones_dm') {
    $input = json_decode(file_get_contents('php://input'), true);
    $usuarioId = $input['usuarioId'] ?? '';
    
    if (!$usuarioId) {
        echo json_encode(['exito' => false, 'error' => 'USUARIO_NO_ESPECIFICADO']);
        exit;
    }
    
    $datos = leerDatos();
    
    $conversaciones = [];
    foreach ($datos['dms'] ?? [] as $dm) {
        if (in_array($usuarioId, $dm['participantes'])) {
            // Obtener el otro participante
            $otroId = null;
            foreach ($dm['participantes'] as $p) {
                if ($p !== $usuarioId) {
                    $otroId = $p;
                    break;
                }
            }
            
            // Buscar datos del otro usuario
            $otroUsuario = null;
            foreach ($datos['usuarios'] as $u) {
                if ($u['id'] === $otroId) {
                    $otroUsuario = $u;
                    break;
                }
            }
            
            // Contar mensajes no leídos
            $noLeidos = 0;
            foreach ($dm['mensajes'] as $msg) {
                if ($msg['autorId'] !== $usuarioId && !$msg['leido']) {
                    $noLeidos++;
                }
            }
            
            $conversaciones[] = [
                'id' => $dm['id'],
                'amigoId' => $otroId,
                'amigoNombre' => $otroUsuario['nombre'] ?? 'Usuario',
                'amigoAvatar' => $otroUsuario['avatar'] ?? '👤',
                'ultimo_mensaje' => end($dm['mensajes'])['contenido'] ?? '',
                'ultimo_mensaje_fecha' => end($dm['mensajes'])['fecha'] ?? '',
                'no_leidos' => $noLeidos,
                'conectado' => $otroUsuario['conectado'] ?? false
            ];
        }
    }
    
    // Ordenar por último mensaje más reciente
    usort($conversaciones, function($a, $b) {
        return strtotime($b['ultimo_mensaje_fecha']) - strtotime($a['ultimo_mensaje_fecha']);
    });
    
    echo json_encode(['exito' => true, 'conversaciones' => $conversaciones]);
    exit;
}

// ================= MARCAR DM COMO LEÍDOS =================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['accion']) && $_GET['accion'] === 'marcar_dm_leidos') {
    $input = json_decode(file_get_contents('php://input'), true);
    $usuarioId = $input['usuarioId'] ?? '';
    $amigoId = $input['amigoId'] ?? '';
    
    if (!$usuarioId || !$amigoId) {
        echo json_encode(['exito' => false, 'error' => 'DATOS_INCOMPLETOS']);
        exit;
    }
    
    $datos = leerDatos();
    
    foreach ($datos['dms'] as &$dm) {
        if (in_array($usuarioId, $dm['participantes']) && in_array($amigoId, $dm['participantes'])) {
            foreach ($dm['mensajes'] as &$msg) {
                if ($msg['autorId'] !== $usuarioId && !$msg['leido']) {
                    $msg['leido'] = true;
                }
            }
            break;
        }
    }
    
    if (escribirDatos($datos)) {
        echo json_encode(['exito' => true]);
    } else {
        echo json_encode(['exito' => false, 'error' => 'ERROR_AL_GUARDAR']);
    }
    exit;
}

// ================= CONTADOR DE NOTIFICACIONES NO LEÍDAS =================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['accion']) && $_GET['accion'] === 'contar_notificaciones') {
    $input = json_decode(file_get_contents('php://input'), true);
    $usuarioId = $input['usuarioId'] ?? '';
    
    if (!$usuarioId) {
        echo json_encode(['exito' => false, 'error' => 'USUARIO_NO_ESPECIFICADO']);
        exit;
    }
    
    $datos = leerDatos();
    
    $noLeidas = 0;
    foreach ($datos['notificaciones'] ?? [] as $n) {
        if ($n['usuarioId'] === $usuarioId && !$n['leida']) {
            $noLeidas++;
        }
    }
    
    echo json_encode(['exito' => true, 'no_leidas' => $noLeidas]);
    exit;
}

// ================= SISTEMA DE BLOQUEOS =================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['accion']) && $_GET['accion'] === 'bloquear_usuario') {
    $input = json_decode(file_get_contents('php://input'), true);
    $usuarioId = $input['usuarioId'] ?? '';
    $bloqueadoId = $input['bloqueadoId'] ?? '';
    
    if (!$usuarioId || !$bloqueadoId) {
        echo json_encode(['exito' => false, 'error' => 'DATOS_INCOMPLETOS']);
        exit;
    }
    
    if ($usuarioId === $bloqueadoId) {
        echo json_encode(['exito' => false, 'error' => 'NO_PUEDES_BLOQUEARTE_A_TI_MISMO']);
        exit;
    }
    
    $datos = leerDatos();
    
    // Buscar usuario
    $usuarioIndex = null;
    foreach ($datos['usuarios'] as $i => $u) {
        if ($u['id'] === $usuarioId) {
            $usuarioIndex = $i;
            break;
        }
    }
    
    if ($usuarioIndex === null) {
        echo json_encode(['exito' => false, 'error' => 'USUARIO_NO_ENCONTRADO']);
        exit;
    }
    
    // Inicializar bloqueados si no existe
    if (!isset($datos['usuarios'][$usuarioIndex]['bloqueados'])) {
        $datos['usuarios'][$usuarioIndex]['bloqueados'] = [];
    }
    
    // Verificar si ya está bloqueado
    if (in_array($bloqueadoId, $datos['usuarios'][$usuarioIndex]['bloqueados'])) {
        echo json_encode(['exito' => false, 'error' => 'YA_BLOQUEADO']);
        exit;
    }
    
    $datos['usuarios'][$usuarioIndex]['bloqueados'][] = $bloqueadoId;
    
    if (escribirDatos($datos)) {
        echo json_encode(['exito' => true]);
    } else {
        echo json_encode(['exito' => false, 'error' => 'ERROR_AL_GUARDAR']);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['accion']) && $_GET['accion'] === 'desbloquear_usuario') {
    $input = json_decode(file_get_contents('php://input'), true);
    $usuarioId = $input['usuarioId'] ?? '';
    $bloqueadoId = $input['bloqueadoId'] ?? '';
    
    if (!$usuarioId || !$bloqueadoId) {
        echo json_encode(['exito' => false, 'error' => 'DATOS_INCOMPLETOS']);
        exit;
    }
    
    $datos = leerDatos();
    
    $usuarioIndex = null;
    foreach ($datos['usuarios'] as $i => $u) {
        if ($u['id'] === $usuarioId) {
            $usuarioIndex = $i;
            break;
        }
    }
    
    if ($usuarioIndex === null) {
        echo json_encode(['exito' => false, 'error' => 'USUARIO_NO_ENCONTRADO']);
        exit;
    }
    
    if (isset($datos['usuarios'][$usuarioIndex]['bloqueados'])) {
        $nuevosBloqueados = [];
        foreach ($datos['usuarios'][$usuarioIndex]['bloqueados'] as $id) {
            if ($id !== $bloqueadoId) {
                $nuevosBloqueados[] = $id;
            }
        }
        $datos['usuarios'][$usuarioIndex]['bloqueados'] = $nuevosBloqueados;
    }
    
    if (escribirDatos($datos)) {
        echo json_encode(['exito' => true]);
    } else {
        echo json_encode(['exito' => false, 'error' => 'ERROR_AL_GUARDAR']);
    }
    exit;
}

// ================= CANAL DE SOPORTE =================
// ================= SISTEMA DE SOPORTE CON TICKETS =================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['accion']) && $_GET['accion'] === 'enviar_soporte') {
    $input = json_decode(file_get_contents('php://input'), true);
    $autorId = $input['autorId'] ?? '';
    $autorNombre = $input['autorNombre'] ?? '';
    $contenido = trim($input['contenido'] ?? '');
    
    if (!$autorId || !$autorNombre || !$contenido) {
        echo json_encode(['exito' => false, 'error' => 'DATOS_INCOMPLETOS']);
        exit;
    }
    
    $datos = leerDatos();
    
    if (!isset($datos['tickets_soporte'])) {
        $datos['tickets_soporte'] = [];
    }
    
    // Buscar si ya existe un ticket abierto para este usuario
    $ticketExistente = null;
    $ticketIndex = null;
    
    foreach ($datos['tickets_soporte'] as $i => $t) {
        if ($t['usuarioId'] === $autorId && $t['estado'] === 'abierto') {
            $ticketExistente = $t;
            $ticketIndex = $i;
            break;
        }
    }
    
    $nuevoMensaje = [
        'id' => 'msg_' . time() . '_' . uniqid(),
        'autorId' => $autorId,
        'autorNombre' => $autorNombre,
        'contenido' => htmlspecialchars(substr($contenido, 0, 500)),
        'fecha' => date('c'),
        'leido' => false
    ];
    
    if ($ticketExistente) {
        // Agregar mensaje al ticket existente
        $datos['tickets_soporte'][$ticketIndex]['mensajes'][] = $nuevoMensaje;
        $datos['tickets_soporte'][$ticketIndex]['ultimo_mensaje'] = date('c');
        $datos['tickets_soporte'][$ticketIndex]['estado'] = 'abierto';
    } else {
        // Crear nuevo ticket
        $nuevoTicket = [
            'id' => 'ticket_' . time() . '_' . uniqid(),
            'usuarioId' => $autorId,
            'usuarioNombre' => $autorNombre,
            'asunto' => substr($contenido, 0, 50) . (strlen($contenido) > 50 ? '...' : ''),
            'estado' => 'abierto', // abierto, en_proceso, cerrado
            'fecha_creacion' => date('c'),
            'ultimo_mensaje' => date('c'),
            'mensajes' => [$nuevoMensaje]
        ];
        $datos['tickets_soporte'][] = $nuevoTicket;
    }
    
    // Limitar tickets si hay muchos
    if (count($datos['tickets_soporte']) > 200) {
        $datos['tickets_soporte'] = array_slice($datos['tickets_soporte'], -200);
    }
    
    if (escribirDatos($datos)) {
        echo json_encode(['exito' => true]);
    } else {
        echo json_encode(['exito' => false, 'error' => 'ERROR_AL_GUARDAR']);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['accion']) && $_GET['accion'] === 'obtener_soporte') {
    $input = json_decode(file_get_contents('php://input'), true);
    $adminId = $input['adminId'] ?? '';
    $ticketId = $input['ticketId'] ?? null; // Si se especifica, solo ese ticket
    
    if (!$adminId) {
        echo json_encode(['exito' => false, 'error' => 'NO_AUTORIZADO']);
        exit;
    }
    
    $datos = leerDatos();
    
    // Verificar que sea admin
    $esAdmin = false;
    foreach ($datos['usuarios'] as $u) {
        if ($u['id'] === $adminId && ($u['nombre'] === 'nere1d' || !empty($u['es_sudo']))) {
            $esAdmin = true;
            break;
        }
    }
    
    if (!$esAdmin) {
        echo json_encode(['exito' => false, 'error' => 'NO_AUTORIZADO']);
        exit;
    }
    
    $tickets = $datos['tickets_soporte'] ?? [];
    
    // Si se pide un ticket específico
    if ($ticketId) {
        foreach ($tickets as $ticket) {
            if ($ticket['id'] === $ticketId) {
                echo json_encode(['exito' => true, 'ticket' => $ticket]);
                exit;
            }
        }
        echo json_encode(['exito' => false, 'error' => 'TICKET_NO_ENCONTRADO']);
        exit;
    }
    
    // Ordenar tickets por último mensaje (más reciente primero)
    usort($tickets, function($a, $b) {
        return strtotime($b['ultimo_mensaje']) - strtotime($a['ultimo_mensaje']);
    });
    
    echo json_encode(['exito' => true, 'tickets' => $tickets]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['accion']) && $_GET['accion'] === 'marcar_soporte_leido') {
    $input = json_decode(file_get_contents('php://input'), true);
    $adminId = $input['adminId'] ?? '';
    $mensajeId = $input['mensajeId'] ?? '';
    
    if (!$adminId || !$mensajeId) {
        echo json_encode(['exito' => false, 'error' => 'DATOS_INCOMPLETOS']);
        exit;
    }
    
    $datos = leerDatos();
    
    // Verificar que sea admin
    $esAdmin = false;
    foreach ($datos['usuarios'] as $u) {
        if ($u['id'] === $adminId && ($u['nombre'] === 'nere1d' || !empty($u['es_sudo']))) {
            $esAdmin = true;
            break;
        }
    }
    
    if (!$esAdmin) {
        echo json_encode(['exito' => false, 'error' => 'NO_AUTORIZADO']);
        exit;
    }
    
    foreach ($datos['soporte'] as &$msg) {
        if ($msg['id'] === $mensajeId) {
            $msg['leido'] = true;
            break;
        }
    }
    
    if (escribirDatos($datos)) {
        echo json_encode(['exito' => true]);
    } else {
        echo json_encode(['exito' => false, 'error' => 'ERROR_AL_GUARDAR']);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['accion']) && $_GET['accion'] === 'responder_soporte') {
    $input = json_decode(file_get_contents('php://input'), true);
    $adminId = $input['adminId'] ?? '';
    $ticketId = $input['ticketId'] ?? '';
    $contenido = trim($input['contenido'] ?? '');
    
    if (!$adminId || !$ticketId || !$contenido) {
        echo json_encode(['exito' => false, 'error' => 'DATOS_INCOMPLETOS']);
        exit;
    }
    
    $datos = leerDatos();
    
    // Verificar que sea admin
    $esAdmin = false;
    $adminNombre = '';
    foreach ($datos['usuarios'] as $u) {
        if ($u['id'] === $adminId && ($u['nombre'] === 'nere1d' || !empty($u['es_sudo']))) {
            $esAdmin = true;
            $adminNombre = $u['nombre'];
            break;
        }
    }
    
    if (!$esAdmin) {
        echo json_encode(['exito' => false, 'error' => 'NO_AUTORIZADO']);
        exit;
    }
    
    // Buscar ticket
    $ticketIndex = null;
    $ticket = null;
    foreach ($datos['tickets_soporte'] as $i => $t) {
        if ($t['id'] === $ticketId) {
            $ticketIndex = $i;
            $ticket = $t;
            break;
        }
    }
    
    if (!$ticket) {
        echo json_encode(['exito' => false, 'error' => 'TICKET_NO_ENCONTRADO']);
        exit;
    }
    
    // Agregar respuesta del admin
    $respuesta = [
        'id' => 'msg_' . time() . '_' . uniqid(),
        'autorId' => $adminId,
        'autorNombre' => $adminNombre,
        'contenido' => htmlspecialchars(substr($contenido, 0, 500)),
        'fecha' => date('c'),
        'esAdmin' => true,
        'leido' => false
    ];
    
    $datos['tickets_soporte'][$ticketIndex]['mensajes'][] = $respuesta;
    $datos['tickets_soporte'][$ticketIndex]['ultimo_mensaje'] = date('c');
    $datos['tickets_soporte'][$ticketIndex]['estado'] = 'abierto';
    
    // Crear notificación para el usuario
    if (!isset($datos['notificaciones'])) $datos['notificaciones'] = [];
    $datos['notificaciones'][] = [
        'id' => 'notif_' . time() . '_' . uniqid(),
        'usuarioId' => $ticket['usuarioId'],
        'tipo' => 'soporte',
        'mensaje' => "📨 @{$adminNombre} ha respondido a tu ticket de soporte",
        'url' => "/soporte",
        'leida' => false,
        'fecha' => date('c')
    ];
    
    if (escribirDatos($datos)) {
        echo json_encode(['exito' => true]);
    } else {
        echo json_encode(['exito' => false, 'error' => 'ERROR_AL_GUARDAR']);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['accion']) && $_GET['accion'] === 'cerrar_ticket') {
    $input = json_decode(file_get_contents('php://input'), true);
    $adminId = $input['adminId'] ?? '';
    $ticketId = $input['ticketId'] ?? '';
    
    if (!$adminId || !$ticketId) {
        echo json_encode(['exito' => false, 'error' => 'DATOS_INCOMPLETOS']);
        exit;
    }
    
    $datos = leerDatos();
    
    // Verificar que sea admin
    $esAdmin = false;
    foreach ($datos['usuarios'] as $u) {
        if ($u['id'] === $adminId && ($u['nombre'] === 'nere1d' || !empty($u['es_sudo']))) {
            $esAdmin = true;
            break;
        }
    }
    
    if (!$esAdmin) {
        echo json_encode(['exito' => false, 'error' => 'NO_AUTORIZADO']);
        exit;
    }
    
    foreach ($datos['tickets_soporte'] as &$ticket) {
        if ($ticket['id'] === $ticketId) {
            $ticket['estado'] = 'cerrado';
            break;
        }
    }
    
    if (escribirDatos($datos)) {
        echo json_encode(['exito' => true]);
    } else {
        echo json_encode(['exito' => false, 'error' => 'ERROR_AL_GUARDAR']);
    }
    exit;
}
// ================= SI NINGÚN ENDPOINT COINCIDIÓ =================
http_response_code(404);
echo json_encode(['error' => 'ACCION_NO_ENCONTRADA']);

























