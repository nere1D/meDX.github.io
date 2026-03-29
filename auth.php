<?php
// ================= auth.php - LOGIN Y REGISTRO =================
// Versión 3.0 - CON REGISTRO AUTOMÁTICO DE DISPOSITIVOS
// ============================================================================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// En producción, descomentar estas líneas
// ini_set('display_errors', 0);
// error_reporting(0);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$archivoDatos = __DIR__ . '/datos_nerume.json';

// ================= FUNCIÓN PARA OBTENER IP REAL =================
function obtenerIPReal() {
    $ip = '';
    
    if (isset($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        $ip = $_SERVER['HTTP_CF_CONNECTING_IP'];
    } elseif (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ip = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
    } elseif (isset($_SERVER['HTTP_CLIENT_IP'])) {
        $ip = $_SERVER['HTTP_CLIENT_IP'];
    } else {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
    
    return trim($ip);
}

// ================= FUNCIÓN PARA VERIFICAR DISPOSITIVO BANEADO =================
function verificarDispositivoBaneado($deviceId, $datos) {
    if (!$deviceId) return null;
    
    $baneados = $datos['dispositivos_baneados'] ?? [];
    foreach ($baneados as $b) {
        if ($b['deviceId'] === $deviceId) {
            return $b;
        }
    }
    return null;
}

// ================= FUNCIÓN PARA REGISTRAR/ACTUALIZAR DISPOSITIVO =================
function registrarDispositivo($datos, $deviceId, $userId, $userAgent = null) {
    if (!$deviceId || !$userId) return $datos;
    
    if (!isset($datos['dispositivos'])) {
        $datos['dispositivos'] = [];
    }
    
    $userAgent = $userAgent ?? ($_SERVER['HTTP_USER_AGENT'] ?? 'Desconocido');
    $ipActual = obtenerIPReal();
    
    $encontrado = false;
    foreach ($datos['dispositivos'] as &$d) {
        if ($d['deviceId'] === $deviceId) {
            $d['userId'] = $userId;
            $d['ultimo_acceso'] = date('c');
            $d['ultima_ip'] = $ipActual;
            $d['userAgent'] = $userAgent;
            $encontrado = true;
            break;
        }
    }
    
    if (!$encontrado) {
        $datos['dispositivos'][] = [
            'deviceId' => $deviceId,
            'userId' => $userId,
            'userAgent' => $userAgent,
            'primer_acceso' => date('c'),
            'ultimo_acceso' => date('c'),
            'ultima_ip' => $ipActual,
            'veces_accedido' => 1
        ];
    } else {
        // Incrementar contador de accesos
        foreach ($datos['dispositivos'] as &$d) {
            if ($d['deviceId'] === $deviceId) {
                $d['veces_accedido'] = ($d['veces_accedido'] ?? 0) + 1;
                break;
            }
        }
    }
    
    return $datos;
}

// ================= FUNCIONES DE DATOS =================
function leerDatos() {
    global $archivoDatos;
    if (!file_exists($archivoDatos)) {
        $estructuraInicial = [
            'version' => 48,
            'ultima_modificacion' => date('c'),
            'server_time' => time(),
            'usuarios' => [],
            'proyectos' => [],
            'comentarios' => [],
            'notificaciones' => [],      // <-- NUEVO
            'dms' => [],                  // <-- NUEVO
            'dispositivos' => [],
            'dispositivos_baneados' => [],
            'cartas_magicas' => []
        ];
        file_put_contents($archivoDatos, json_encode($estructuraInicial, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        return $estructuraInicial;
    }
    $contenido = file_get_contents($archivoDatos);
    $datos = json_decode($contenido, true);
    
    // Asegurar que existan las nuevas estructuras (para versiones anteriores)
    if (!isset($datos['notificaciones'])) $datos['notificaciones'] = [];
    if (!isset($datos['dms'])) $datos['dms'] = [];
    if (!isset($datos['dispositivos'])) $datos['dispositivos'] = [];
    if (!isset($datos['dispositivos_baneados'])) $datos['dispositivos_baneados'] = [];
    
    return $datos;
}

function guardarDatos($datos) {
    global $archivoDatos;
    $datos['ultima_modificacion'] = date('c');
    $datos['server_time'] = time();
    return file_put_contents($archivoDatos, json_encode($datos, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

// ================= PROCESAR SOLICITUD =================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    $accion = $data['accion'] ?? '';
    $nombre = trim($data['nombre'] ?? '');
    $password = $data['password'] ?? '';
    $avatar = $data['avatar'] ?? '🐙';
    $color = $data['color'] ?? '#ff6600';
    $deviceId = $data['deviceId'] ?? '';
    $userAgent = $data['userAgent'] ?? $_SERVER['HTTP_USER_AGENT'] ?? 'Desconocido';
    
    if (!$nombre) {
        echo json_encode(['exito' => false, 'error' => 'NOMBRE_REQUERIDO']);
        exit;
    }
    
    $datos = leerDatos();
    
    // ================= VERIFICAR DISPOSITIVO BANEADO =================
    if ($deviceId) {
        $dispositivoBaneado = verificarDispositivoBaneado($deviceId, $datos);
        if ($dispositivoBaneado !== null) {
            http_response_code(403);
            echo json_encode([
                'exito' => false,
                'error' => 'DISPOSITIVO_BANEADO',
                'motivo' => $dispositivoBaneado['motivo'],
                'fecha_ban' => $dispositivoBaneado['fecha_ban']
            ]);
            exit();
        }
    }
    
    // ================= LOGIN =================
    if ($accion === 'login') {
        $usuarioEncontrado = null;
        foreach ($datos['usuarios'] as $u) {
            if (strtolower($u['nombre']) === strtolower($nombre)) {
                $usuarioEncontrado = $u;
                break;
            }
        }
        
        if (!$usuarioEncontrado) {
            echo json_encode(['exito' => false, 'error' => 'USUARIO_NO_EXISTE']);
            exit;
        }
        
        // Verificar si el usuario está baneado
        if (!empty($usuarioEncontrado['baneado'])) {
            $motivo = $usuarioEncontrado['motivo_ban'] ?? 'Sin motivo';
            echo json_encode([
                'exito' => false,
                'error' => 'USUARIO_BANEADO',
                'motivo' => $motivo,
                'fecha' => $usuarioEncontrado['fecha_ban'] ?? date('c')
            ]);
            exit;
        }
        
        // Verificar contraseña
        if (password_verify($password, $usuarioEncontrado['password'])) {
            // Actualizar estado de conexión
            foreach ($datos['usuarios'] as &$u) {
                if ($u['id'] === $usuarioEncontrado['id']) {
                    $u['conectado'] = true;
                    $u['ultimo_ping'] = time();
                    break;
                }
            }
            
            // **REGISTRAR DISPOSITIVO DEL USUARIO**
            $datos = registrarDispositivo($datos, $deviceId, $usuarioEncontrado['id'], $userAgent);
            
            guardarDatos($datos);
            
            unset($usuarioEncontrado['password']);
            echo json_encode(['exito' => true, 'usuario' => $usuarioEncontrado]);
        } else {
            echo json_encode(['exito' => false, 'error' => 'CONTRASEÑA_INCORRECTA']);
        }
        exit;
    }
    
    // ================= REGISTRO =================
    if ($accion === 'registro') {
        // Verificar si el usuario ya existe
        foreach ($datos['usuarios'] as $u) {
            if (strtolower($u['nombre']) === strtolower($nombre)) {
                echo json_encode(['exito' => false, 'error' => 'USUARIO_YA_EXISTE']);
                exit;
            }
        }
        
        // Validar nombre
        if (strlen($nombre) < 3 || strlen($nombre) > 20) {
            echo json_encode(['exito' => false, 'error' => 'NOMBRE_INVALIDO_LONGITUD']);
            exit;
        }
        
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $nombre)) {
            echo json_encode(['exito' => false, 'error' => 'NOMBRE_INVALIDO_CARACTERES']);
            exit;
        }
        
        // Palabras prohibidas
        $palabrasProhibidas = ['admin', 'root', 'system', 'moderador', 'soporte', 'nerume', 'sudo'];
        if (in_array(strtolower($nombre), $palabrasProhibidas)) {
            echo json_encode(['exito' => false, 'error' => 'NOMBRE_NO_DISPONIBLE']);
            exit;
        }
        
        // Validar contraseña
        if (strlen($password) < 3) {
            echo json_encode(['exito' => false, 'error' => 'CONTRASEÑA_DEMASIADO_CORTA']);
            exit;
        }
        
        $userId = 'user_' . time() . '_' . uniqid();
        $esAdmin = (strtolower($nombre) === 'nere1d');
        
        if ($esAdmin) {
            $userId = 'user_1771306151_6993fca73095e_3470';
        }
        
$nuevoUsuario = [
    'id' => $userId,
    'nombre' => $nombre,
    'password' => password_hash($password, PASSWORD_DEFAULT),
    'avatar' => $avatar,
    'color' => $color,
    'fechaRegistro' => date('c'),
    'biografia' => '',                    // <-- NUEVO
    'lema' => '',                         // <-- NUEVO (en lugar de banner)
    'proyectos' => $esAdmin ? ['proyecto_bienvenida_nerume'] : [],
    'amigos' => [],                       // <-- NUEVO
    'solicitudes_pendientes' => [],       // <-- NUEVO
    'conectado' => true,
    'ultimo_ping' => time(),
    'es_admin' => $esAdmin,
    'es_sudo' => false,
    'baneado' => false
];
        
        $datos['usuarios'][] = $nuevoUsuario;
        
        // **REGISTRAR DISPOSITIVO DEL NUEVO USUARIO**
        $datos = registrarDispositivo($datos, $deviceId, $userId, $userAgent);
        
        guardarDatos($datos);
        
        unset($nuevoUsuario['password']);
        echo json_encode(['exito' => true, 'usuario' => $nuevoUsuario]);
        exit;
    }
    
    echo json_encode(['exito' => false, 'error' => 'ACCION_INVALIDA']);
}
?>