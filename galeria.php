<?php
// --- 1. CONFIGURACIÓN Y SEGURIDAD ---
session_start();

// Verificar Sesión
if (isset($_GET['logout'])) { 
    session_destroy(); 
    header('Location: index.php'); 
    exit; 
}
if (!isset($_SESSION['usuario_autorizado']) || $_SESSION['usuario_autorizado'] !== true) { 
    header('Location: index.php'); 
    exit; 
}

// Directorios y Archivos
$baseDir = 'uploads/';
$sharesFile = 'shares.json';
$projectsFile = 'projects.json'; 
$logosDir = 'uploads/logos/';

// Ajustes PHP
@ini_set('max_execution_time', 600); // 10 minutos para ZIPs grandes
@ini_set('post_max_size', '500M');
@ini_set('upload_max_filesize', '500M');

// Inicializar
if (!file_exists($baseDir)) mkdir($baseDir, 0777, true);
if (!file_exists($logosDir)) mkdir($logosDir, 0777, true);

// --- 2. FUNCIONES AUXILIARES ---

// Borrado Recursivo
function deleteDirectory($dir) {
    if (!file_exists($dir)) return true;
    if (!is_dir($dir)) return unlink($dir);
    foreach (scandir($dir) as $item) {
        if ($item == '.' || $item == '..') continue;
        if (!deleteDirectory($dir . DIRECTORY_SEPARATOR . $item)) return false;
    }
    return rmdir($dir);
}

// Gestión de Metadatos (JSON)
function getProjectMeta($folder) {
    global $projectsFile;
    $data = file_exists($projectsFile) ? json_decode(file_get_contents($projectsFile), true) : [];
    if (!is_array($data)) $data = [];
    return $data[$folder] ?? [];
}

function saveProjectMeta($folder, $newData) {
    global $projectsFile;
    $data = file_exists($projectsFile) ? json_decode(file_get_contents($projectsFile), true) : [];
    if (!is_array($data)) $data = [];
    
    if (!isset($data[$folder])) $data[$folder] = [];
    
    // Merge especial para descripciones de archivos
    if (isset($newData['files'])) {
        if (!isset($data[$folder]['files'])) $data[$folder]['files'] = [];
        $data[$folder]['files'] = array_merge($data[$folder]['files'], $newData['files']);
        unset($newData['files']);
    }
    
    $data[$folder] = array_merge($data[$folder], $newData);
    file_put_contents($projectsFile, json_encode($data, JSON_PRETTY_PRINT));
}

function calcularExpiracion($duracion) {
    $segundosDia = 86400;
    switch ($duracion) {
        case '1h': return time() + 3600;
        case '24h': return time() + $segundosDia;
        case '7d': return time() + (7 * $segundosDia);
        case '30d': return time() + (30 * $segundosDia);
        case '1y': return time() + (365 * $segundosDia);
        case 'forever': return null;
        default: return null;
    }
}

// --- 3. NAVEGACIÓN ---
$carpetaActual = isset($_GET['folder']) ? $_GET['folder'] : '';
// Sanitización básica
$carpetaActual = str_replace(['..', '\\', '/logos'], ['', '/', ''], $carpetaActual);
$carpetaActual = trim($carpetaActual, '/');
$rutaActual = $baseDir . ($carpetaActual ? $carpetaActual . '/' : '');

if (!is_dir($rutaActual)) { $carpetaActual = ''; $rutaActual = $baseDir; }

$carpetaPadre = '';
if ($carpetaActual) {
    $parts = explode('/', $carpetaActual);
    array_pop($parts);
    $carpetaPadre = implode('/', $parts);
}

// Cargar datos del proyecto actual
$currentProjectMeta = $carpetaActual ? getProjectMeta($carpetaActual) : [];

// --- 4. RECUPERAR MENSAJES DE SESIÓN (FLASH) ---
$mensaje = $_SESSION['flash_msg'] ?? '';
$tipoMensaje = $_SESSION['flash_type'] ?? '';
$nuevoLink = $_SESSION['flash_link'] ?? '';
unset($_SESSION['flash_msg'], $_SESSION['flash_type'], $_SESSION['flash_link']);

// --- 5. LÓGICA DE BLOQUEO DE CARPETA ---
$isLocked = false;
if ($carpetaActual && !empty($currentProjectMeta['folder_password'])) {
    if (!isset($_SESSION['folder_unlocks'][$carpetaActual]) || $_SESSION['folder_unlocks'][$carpetaActual] !== true) {
        $isLocked = true;
    }
}

// --- 6. PROCESAMIENTO DE FORMULARIOS (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    $msg = ''; $type = ''; $link = '';

    // A. DESCARGAR ZIP (Acción directa, no requiere PRG si sale bien)
    if (isset($_POST['download_zip']) && $carpetaActual && !$isLocked) {
        $zipname = 'proyecto_' . preg_replace('/[^a-z0-9]/i', '_', basename($carpetaActual)) . '.zip';
        $zip = new ZipArchive;
        $tmp_file = tempnam(sys_get_temp_dir(), 'zip');
        
        if ($zip->open($tmp_file, ZipArchive::CREATE) === TRUE) {
            $files = glob($rutaActual . '*');
            $count = 0;
            foreach ($files as $file) {
                if (is_file($file)) {
                    $zip->addFile($file, basename($file));
                    $count++;
                }
            }
            $zip->close();
            
            if ($count > 0) {
                header('Content-Type: application/zip');
                header('Content-disposition: attachment; filename='.$zipname);
                header('Content-Length: ' . filesize($tmp_file));
                readfile($tmp_file);
                unlink($tmp_file);
                exit; 
            } else {
                $msg = "La carpeta está vacía."; $type = "error";
            }
        } else {
            $msg = "Error al crear ZIP."; $type = "error";
        }
    }

    // B. DESBLOQUEAR CARPETA
    if (isset($_POST['unlock_folder'])) {
        $passAttempt = $_POST['unlock_pass'];
        if (password_verify($passAttempt, $currentProjectMeta['folder_password'])) {
            $_SESSION['folder_unlocks'][$carpetaActual] = true;
            $isLocked = false; 
            $msg = "Carpeta desbloqueada."; $type = "success";
        } else {
            $msg = "Contraseña incorrecta."; $type = "error";
        }
    }

    // ACCIONES QUE REQUIEREN CARPETA DESBLOQUEADA
    if (!$isLocked && empty($msg)) {
        
        // C. CONFIGURACIÓN PROYECTO
        if (isset($_POST['configurar_proyecto']) && $carpetaActual) {
            $updateData = [];
            // Subir Logo
            if (isset($_FILES['project_logo_upload']) && $_FILES['project_logo_upload']['error'] === UPLOAD_ERR_OK) {
                $tmpName = $_FILES['project_logo_upload']['tmp_name'];
                $ext = strtolower(pathinfo($_FILES['project_logo_upload']['name'], PATHINFO_EXTENSION));
                if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
                    $newLogoName = 'proj_' . md5($carpetaActual) . '_' . time() . '.' . $ext;
                    $dest = $logosDir . $newLogoName;
                    if (move_uploaded_file($tmpName, $dest)) $updateData['logo'] = $dest;
                }
            }
            // Set Password
            if (isset($_POST['folder_password_set'])) {
                $newPass = $_POST['folder_password_set'];
                if (!empty($newPass)) {
                    $updateData['folder_password'] = password_hash($newPass, PASSWORD_DEFAULT);
                    $_SESSION['folder_unlocks'][$carpetaActual] = true;
                } elseif (isset($_POST['remove_password']) && $_POST['remove_password'] == '1') {
                    $updateData['folder_password'] = null;
                }
            }
            if (!empty($updateData)) {
                saveProjectMeta($carpetaActual, $updateData);
                $msg = "Configuración guardada."; $type = "success";
            }
        }

        // D. COMPARTIR LINK
        if (isset($_POST['compartir_proyecto']) && $carpetaActual) {
            $passCompartir = $_POST['share_pass'];
            $duracion = $_POST['share_duration'];
            $expiresAt = calcularExpiracion($duracion);
            $finalLogoPath = $currentProjectMeta['logo'] ?? null;

            $token = bin2hex(random_bytes(16));
            $allShares = file_exists($sharesFile) ? json_decode(file_get_contents($sharesFile), true) : [];
            
            $allShares[$token] = [
                'folder' => $carpetaActual,
                'hash' => password_hash($passCompartir, PASSWORD_DEFAULT),
                'expires' => $expiresAt,
                'created' => time(),
                'logo' => $finalLogoPath
            ];
            
            file_put_contents($sharesFile, json_encode($allShares, JSON_PRETTY_PRINT));
            
            $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
            $host = $_SERVER['HTTP_HOST'];
            $path = dirname($_SERVER['PHP_SELF']);
            $link = "$protocol://$host$path/share.php?token=$token";
            $msg = "Enlace generado."; $type = "success";
        }

        // E. ELIMINAR LINK
        if (isset($_POST['eliminar_link'])) {
            $tokenDel = $_POST['token_link'];
            $allShares = file_exists($sharesFile) ? json_decode(file_get_contents($sharesFile), true) : [];
            if (isset($allShares[$tokenDel])) {
                unset($allShares[$tokenDel]);
                file_put_contents($sharesFile, json_encode($allShares, JSON_PRETTY_PRINT));
                $msg = "Enlace eliminado."; $type = "success";
            }
        }

        // F. EDITAR LINK
        if (isset($_POST['editar_link_action'])) {
            $tokenEdit = $_POST['token_link'];
            $newPass = $_POST['new_pass'];
            $newDuration = $_POST['new_duration'];
            $allShares = file_exists($sharesFile) ? json_decode(file_get_contents($sharesFile), true) : [];

            if (isset($allShares[$tokenEdit])) {
                if (!empty($newPass)) $allShares[$tokenEdit]['hash'] = password_hash($newPass, PASSWORD_DEFAULT);
                if ($newDuration !== 'no_change') $allShares[$tokenEdit]['expires'] = calcularExpiracion($newDuration);
                file_put_contents($sharesFile, json_encode($allShares, JSON_PRETTY_PRINT));
                $msg = "Enlace actualizado."; $type = "success";
            }
        }

        // G. CREAR CARPETA
        if (isset($_POST['crear_carpeta'])) {
            $nombreNuevaCarpeta = trim($_POST['nombre_carpeta']);
            $nombreLimpio = preg_replace('/[^A-Za-z0-9 _-]/', '', $nombreNuevaCarpeta);
            if (!empty($nombreLimpio)) {
                $rutaNueva = $rutaActual . $nombreLimpio;
                if (!file_exists($rutaNueva) && mkdir($rutaNueva, 0777, true)) {
                    $msg = "Carpeta creada."; $type = "success";
                } else { $msg = "Error: La carpeta ya existe."; $type = "error"; }
            }
        }

        // H. SUBIR ARCHIVOS
        if (isset($_FILES['imagen360'])) {
            $archivos = $_FILES['imagen360'];
            $esPlana = isset($_POST['is_flat']) && $_POST['is_flat'] == '1';
            $total = count($archivos['name']);
            $subidos = 0;
            
            for ($i = 0; $i < $total; $i++) {
                if ($archivos['error'][$i] !== UPLOAD_ERR_OK) continue;
                $nombreOriginal = basename($archivos['name'][$i]);
                
                // Prefijo flat_ antes del timestamp para correcta detección
                $prefijo = ($esPlana && strpos($nombreOriginal, 'flat_') !== 0) ? 'flat_' : '';
                $nombreFinal = $prefijo . time() . '_' . $i . '_' . $nombreOriginal;
                $dest = $rutaActual . $nombreFinal;
                
                $ext = strtolower(pathinfo($dest, PATHINFO_EXTENSION));
                if (in_array($ext, ['jpg', 'jpeg', 'png', 'mp4'])) {
                    if (move_uploaded_file($archivos['tmp_name'][$i], $dest)) $subidos++;
                }
            }
            $msg = $subidos > 0 ? "Subidos $subidos archivos." : "Error en la subida.";
            $type = $subidos > 0 ? "success" : "error";
        }

        // I. ACCIONES ITEM (Renombrar, Eliminar, Cambiar Tipo)
        if (isset($_POST['accion_item'])) {
            $item = $_POST['nombre_viejo'];
            $path = $rutaActual . $item;
            $accion = $_POST['accion_item'];

            if ($accion === 'eliminar' && file_exists($path)) {
                $res = is_dir($path) ? deleteDirectory($path) : unlink($path);
                $msg = $res ? "Eliminado correctamente." : "Error al eliminar."; 
                $type = $res ? "success" : "error";
            } 
            elseif ($accion === 'renombrar') {
                // Si es carpeta, renombramos físicamente
                if (is_dir($path)) {
                    $new = preg_replace('/[^A-Za-z0-9 _\-\.]/', '', $_POST['nombre_nuevo']);
                    if ($new && rename($path, $rutaActual . $new)) { 
                        $msg = "Carpeta renombrada."; $type = "success"; 
                    }
                } else {
                    // Si es archivo, guardamos descripción en JSON
                    $nuevaDesc = $_POST['nombre_nuevo'];
                    saveProjectMeta($carpetaActual, ['files' => [$item => $nuevaDesc]]);
                    $msg = "Descripción actualizada."; $type = "success";
                }
            } 
            elseif ($accion === 'cambiar_tipo' && file_exists($path) && !is_dir($path)) {
                // Detectar estado actual
                $esPlanaCurrently = (strpos($item, 'flat_') === 0);
                
                // Calcular nuevo nombre físico
                if ($esPlanaCurrently) {
                    $nuevoNombre = substr($item, 5); // Quitar 'flat_'
                } else {
                    $nuevoNombre = 'flat_' . $item; // Agregar 'flat_'
                }
                
                // Obtener descripción actual para migrarla
                $desc = $currentProjectMeta['files'][$item] ?? null;
                
                if (rename($path, $rutaActual . $nuevoNombre)) {
                    // Migrar descripción a la nueva clave
                    if ($desc) {
                        $metaFiles = $currentProjectMeta['files'];
                        unset($metaFiles[$item]);
                        $metaFiles[$nuevoNombre] = $desc;
                        saveProjectMeta($carpetaActual, ['files' => $metaFiles]);
                    }
                    $msg = "Tipo de imagen cambiado."; $type = "success";
                }
            }
        }
    }

    // --- REDIRECCIÓN PRG (POST-REDIRECT-GET) ---
    if ($msg) {
        $_SESSION['flash_msg'] = $msg;
        $_SESSION['flash_type'] = $type;
        if ($link) $_SESSION['flash_link'] = $link;
    }
    
    $queryString = $carpetaActual ? '?folder=' . urlencode($carpetaActual) : '';
    header("Location: " . $_SERVER['PHP_SELF'] . $queryString);
    exit;
}

// --- 7. CARGAR CONTENIDO ---
$carpetas = [];
$archivos = [];
$projectShares = [];
$playlist = []; 

if (!$isLocked) {
    // Carpetas (Filtrando 'logos')
    $carpetas = glob($rutaActual . '*', GLOB_ONLYDIR);
    $carpetas = array_filter($carpetas, function($dir) { return basename($dir) !== 'logos'; });
    
    // Archivos
    $archivos = glob($rutaActual . "*.{jpg,jpeg,png,mp4,JPG,JPEG,PNG,MP4}", GLOB_BRACE);
    
    // Shares del proyecto
    $allShares = file_exists($sharesFile) ? json_decode(file_get_contents($sharesFile), true) : [];
    foreach ($allShares as $token => $data) {
        if (isset($data['folder']) && $data['folder'] === $carpetaActual) {
            $projectShares[$token] = $data;
        }
    }

    // Construir Playlist para el Visor
    $urlBaseRelativa = $baseDir . ($carpetaActual ? $carpetaActual . '/' : '');
    if ($archivos) {
        foreach ($archivos as $archivo) {
            $nombreReal = basename($archivo);
            
            // Descripción
            $desc = isset($currentProjectMeta['files'][$nombreReal]) ? $currentProjectMeta['files'][$nombreReal] : null;
            if (!$desc) $desc = preg_replace('/^(flat_)?\d+_\d+_/', '', $nombreReal); // Limpieza estética si no hay desc
            
            $esVideo = (strtolower(pathinfo($nombreReal, PATHINFO_EXTENSION)) === 'mp4');
            $esPlana = (strpos($nombreReal, 'flat_') === 0);

            $playlist[] = [
                'url' => $urlBaseRelativa . $nombreReal,
                'titulo' => $desc,
                'tipo' => $esPlana ? 'flat' : ($esVideo ? 'video' : '360'),
                'nombreReal' => $nombreReal
            ];
        }
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard | Gestor 360°</title>
    <link rel="icon" type="image/jpeg" href="favicon.jpeg">
    
    <!-- Scripts y Estilos -->
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/pannellum@2.5.6/build/pannellum.css"/>
    <link href="https://vjs.zencdn.net/7.20.3/video-js.css" rel="stylesheet" />
    <script src="https://vjs.zencdn.net/7.20.3/video.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/pannellum@2.5.6/build/pannellum.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/pannellum@2.5.6/build/videojs-pannellum-plugin.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <style>
        body { font-family: 'Inter', sans-serif; }
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: #f1f5f9; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
        
        .video-js, .pnlm-container { width: 100%; height: 100%; border-radius: 0.25rem; }
        
        /* Modals */
        .modal { opacity: 0; visibility: hidden; transition: all 0.3s ease-in-out; z-index: 50; }
        .modal.active { opacity: 1; visibility: visible; }
        .modal.active .modal-content { transform: scale(1); }
        .modal-content { transform: scale(0.95); transition: all 0.3s ease-in-out; }
        
        #drop-overlay { background-color: rgba(59, 130, 246, 0.9); backdrop-filter: blur(4px); }
        
        /* Visor Overlay */
        #viewer-modal { opacity: 0; visibility: hidden; transition: all 0.3s ease; user-select: none; -webkit-user-select: none; }
        #viewer-modal.active { opacity: 1; visibility: visible; }

        /* Botones Navegación */
        .nav-btn {
            position: absolute; top: 50%; transform: translateY(-50%);
            background: rgba(0,0,0,0.4); color: white; border-radius: 50%;
            width: 50px; height: 50px; display: flex; align-items: center; justify-content: center;
            cursor: pointer; transition: all 0.2s; border: 1px solid rgba(255,255,255,0.1);
            z-index: 60;
        }
        .nav-btn:hover { background: rgba(0,0,0,0.8); scale: 1.1; }
        .nav-btn.left { left: 20px; }
        .nav-btn.right { right: 20px; }

        @media (max-width: 1023px) {
            #mobile-sidebar { transition: transform 0.3s ease-in-out; }
            #mobile-sidebar.open { transform: translateX(0); }
            #mobile-sidebar.closed { transform: translateX(-100%); }
            #mobile-overlay { transition: opacity 0.3s ease-in-out; }
            .nav-btn { width: 40px; height: 40px; }
            .nav-btn.left { left: 10px; }
            .nav-btn.right { right: 10px; }
        }
    </style>
</head>
<body class="bg-slate-100 text-slate-600 font-normal text-base h-screen overflow-hidden flex relative">

    <!-- OVERLAYS -->
    <div id="drop-overlay" class="hidden fixed inset-0 z-[9999] flex flex-col items-center justify-center text-white transition-opacity duration-300 pointer-events-none">
        <div class="border-4 border-dashed border-white rounded-xl p-12 flex flex-col items-center animate-pulse bg-blue-500/50 backdrop-blur-sm pointer-events-auto">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-24 w-24 mb-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" /></svg>
            <h2 class="text-3xl font-bold">Suelta archivos aquí</h2>
            <p class="mt-2" id="drop-mode-text">Se subirán como 360° por defecto</p>
        </div>
    </div>

    <div id="loading-overlay" class="hidden fixed inset-0 z-[10000] bg-slate-900/80 flex flex-col items-center justify-center text-white backdrop-blur-sm">
        <svg class="animate-spin h-12 w-12 text-blue-500 mb-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
        <h3 class="text-xl font-bold">Procesando...</h3>
    </div>

    <div id="copy-toast" class="fixed bottom-5 right-5 z-[9999] bg-slate-800 text-white px-6 py-3 rounded-lg shadow-xl transform translate-y-20 opacity-0 transition-all duration-300 flex items-center gap-3 pointer-events-none border border-slate-700">
        <div class="bg-green-500 rounded-full p-1"><svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7" /></svg></div>
        <span class="font-medium text-sm" id="toast-message">Operación Exitosa</span>
    </div>
    
    <!-- VISOR OVERLAY FULLSCREEN (Admin) -->
    <div id="viewer-modal" class="fixed inset-0 z-[100] bg-black/95 flex flex-col select-none">
        <!-- Toolbar -->
        <div class="flex items-center justify-between p-4 bg-black/50 backdrop-blur-md absolute top-0 left-0 right-0 z-40 pointer-events-none select-none">
            <div class="pointer-events-auto">
                <h3 id="visor-titulo" class="text-white font-bold truncate max-w-[200px] sm:max-w-md text-sm sm:text-base drop-shadow-md">Visor</h3>
                <p id="visor-contador" class="text-xs text-gray-400"></p>
            </div>
            <div class="flex items-center gap-3 pointer-events-auto">
                 <a id="btn-descargar" href="#" download class="flex items-center gap-2 text-white hover:text-blue-300 bg-white/10 hover:bg-white/20 px-3 py-1.5 rounded-full transition text-xs sm:text-sm border border-white/10 backdrop-blur-sm">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" /></svg>
                    <span class="hidden sm:inline">Descargar</span>
                </a>
                <button onclick="closeViewer()" class="text-white hover:text-red-400 p-2 rounded-full hover:bg-white/10 transition">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 sm:h-8 sm:w-8" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                </button>
            </div>
        </div>

        <!-- Botones Navegación -->
        <div class="nav-btn left select-none" onclick="prevSlide()">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" /></svg>
        </div>
        <div class="nav-btn right select-none" onclick="nextSlide()">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" /></svg>
        </div>

        <!-- Container -->
        <div id="visor-container" class="flex-1 w-full h-full relative"></div>
    </div>

    <div id="mobile-overlay" onclick="toggleMobileMenu()" class="fixed inset-0 bg-black/50 z-30 hidden opacity-0 lg:hidden"></div>

    <!-- SIDEBAR -->
    <aside id="mobile-sidebar" class="fixed inset-y-0 left-0 z-40 w-72 bg-slate-800 text-white flex flex-col h-full shadow-xl transform -translate-x-full lg:translate-x-0 lg:static lg:flex closed lg:open">
        <div class="flex items-center justify-between px-6 py-6 border-b border-slate-700 bg-slate-900/50">
            <div class="flex items-center gap-3">
                <div class="bg-blue-600 p-2 rounded-lg shadow-lg shadow-blue-500/20"><svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" /></svg></div>
                <span class="text-lg font-bold tracking-wide">GESTOR 360°</span>
            </div>
            <button onclick="toggleMobileMenu()" class="lg:hidden text-slate-400 hover:text-white">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
            </button>
        </div>
        <div class="flex-1 overflow-y-auto py-6 px-4 space-y-8">
            <div>
                <h3 class="mb-4 ml-2 text-xs font-bold text-slate-400 uppercase tracking-wider">Acciones</h3>
                <div class="space-y-3">
                    <!-- Botón Nueva Carpeta -->
                    <button onclick="openModal('modal-carpeta'); toggleMobileMenu()" class="w-full flex items-center gap-3 px-4 py-3 rounded-lg bg-slate-700 hover:bg-slate-600 transition text-left group shadow-md border border-slate-600/50">
                        <div class="bg-blue-500/20 p-2 rounded text-blue-400"><svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 13h6m-3-3v6m-9 1V7a2 2 0 012-2h6l2 2h6a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2z" /></svg></div><span class="text-sm font-medium">Nueva Carpeta</span>
                    </button>
                    
                    <?php if($carpetaActual && !$isLocked): ?>
                        <!-- Botón Subir -->
                        <button onclick="openModal('modal-subir'); toggleMobileMenu()" class="w-full flex items-center gap-3 px-4 py-3 rounded-lg bg-slate-700 hover:bg-slate-600 transition text-left group shadow-md border border-slate-600/50">
                            <div class="bg-green-500/20 p-2 rounded text-green-400"><svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" /></svg></div><div><span class="block text-sm font-medium">Subir Archivos</span></div>
                        </button>
                        
                        <!-- Botón Descargar ZIP -->
                        <form method="POST" id="downloadZipForm">
                            <input type="hidden" name="download_zip" value="1">
                            <button type="submit" class="w-full flex items-center gap-3 px-4 py-3 rounded-lg bg-slate-700 hover:bg-slate-600 transition text-left group shadow-md border border-slate-600/50">
                                <div class="bg-purple-500/20 p-2 rounded text-purple-400"><svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" /></svg></div>
                                <div><span class="block text-sm font-medium">Descargar Todo</span><span class="block text-[10px] text-slate-400">Como ZIP</span></div>
                            </button>
                        </form>
                        
                        <!-- Botón Configurar -->
                        <button onclick="openModal('modal-config'); toggleMobileMenu()" class="w-full flex items-center gap-3 px-4 py-3 rounded-lg bg-slate-700 hover:bg-slate-600 transition text-left group shadow-md border border-slate-600/50">
                            <div class="bg-yellow-500/20 p-2 rounded text-yellow-400"><svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /></svg></div><div><span class="block text-sm font-medium">Configurar</span></div>
                        </button>
                        
                        <!-- Botón Compartir -->
                        <button onclick="openModal('modal-compartir'); toggleMobileMenu()" class="w-full flex items-center gap-3 px-4 py-3 rounded-lg bg-indigo-600 hover:bg-indigo-500 transition text-left group shadow-lg">
                            <div class="bg-white/20 p-2 rounded text-white"><svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z" /></svg></div><span class="text-sm font-bold">Gestión Enlaces</span>
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </aside>

    <div class="relative flex flex-1 flex-col overflow-y-auto overflow-x-hidden">
        <header class="sticky top-0 z-30 flex w-full bg-white drop-shadow-sm border-b border-slate-200">
            <div class="flex flex-grow items-center justify-between py-4 px-4 md:px-6">
                <div class="flex items-center gap-2">
                    <button onclick="toggleMobileMenu()" class="lg:hidden text-slate-500 hover:text-blue-600 mr-2"><svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" /></svg></button>
                    <h1 class="text-lg font-bold text-slate-800 hidden sm:block">Explorador</h1>
                    <div class="h-6 w-px bg-slate-300 hidden sm:block mx-2"></div>
                    <nav class="text-sm text-slate-600 flex items-center gap-2 overflow-x-auto whitespace-nowrap max-w-[200px] sm:max-w-none">
                        <a class="hover:text-blue-600 font-medium" href="galeria.php">Inicio</a>
                        <?php if($carpetaActual): 
                            $parts = explode('/', $carpetaActual); $acumulado = '';
                            foreach($parts as $part): $acumulado .= ($acumulado?'/':'').$part; ?>
                            <span class="text-slate-400">/</span>
                            <a class="hover:text-blue-600 font-medium text-slate-800" href="?folder=<?php echo urlencode($acumulado); ?>"><?php echo htmlspecialchars($part); ?></a>
                        <?php endforeach; endif; ?>
                    </nav>
                </div>
                <a href="?logout=true" class="text-sm font-medium text-red-600 bg-red-50 hover:bg-red-100 px-3 py-2 rounded-full transition flex items-center gap-2"><svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" /></svg><span class="hidden sm:inline">Salir</span></a>
            </div>
        </header>

        <main class="bg-slate-100 p-4 md:p-6 h-full flex flex-col">
            <!-- ALERTA PRG (TOAST) -->
            <?php if ($mensaje): ?>
                <div id="server-toast" class="mb-6 rounded-lg border-l-4 <?php echo $tipoMensaje=='success'?'border-green-500 bg-green-50 text-green-700':'border-red-500 bg-red-50 text-red-700'; ?> p-4 shadow-sm flex justify-between items-center">
                    <div><p class="font-bold"><?php echo $tipoMensaje=='success'?'¡Operación Exitosa!':'Error'; ?></p><p class="text-sm"><?php echo $mensaje; ?></p></div>
                    <button onclick="this.parentElement.remove()" class="text-slate-400 hover:text-slate-600">✕</button>
                </div>
                <script>setTimeout(() => { const el = document.getElementById('server-toast'); if(el) el.remove(); }, 4000);</script>
            <?php endif; ?>

            <?php if ($nuevoLink): ?>
                <div class="mb-6 rounded-lg bg-white border border-blue-200 p-6 shadow-md">
                    <p class="text-sm font-bold text-blue-800 mb-2 flex items-center gap-2"><svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1" /></svg>Enlace Generado</p>
                    <div class="flex gap-2"><input type="text" readonly value="<?php echo $nuevoLink; ?>" id="shareUrlInput" class="flex-1 rounded border border-slate-300 bg-slate-50 py-2 px-4 text-sm text-slate-600 outline-none"><button onclick="copyToClipboard(document.getElementById('shareUrlInput').value)" class="rounded bg-blue-600 px-6 py-2 text-sm font-bold text-white hover:bg-blue-700 transition">Copiar</button></div>
                </div>
            <?php endif; ?>

            <?php if($isLocked): ?>
                <div class="flex-1 flex flex-col items-center justify-center text-center p-8 bg-white rounded-lg shadow-sm border border-slate-200">
                    <div class="bg-red-50 p-4 rounded-full mb-4 text-red-500"><svg xmlns="http://www.w3.org/2000/svg" class="h-12 w-12" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" /></svg></div>
                    <h2 class="text-2xl font-bold text-slate-800 mb-2">Carpeta Protegida</h2>
                    <p class="text-slate-500 mb-6">Esta carpeta tiene una contraseña de seguridad adicional.</p>
                    <form action="" method="POST" class="flex gap-2 w-full max-w-xs">
                        <input type="hidden" name="unlock_folder" value="1">
                        <input type="password" name="unlock_pass" class="flex-1 rounded border border-slate-300 px-4 py-2 outline-none focus:border-blue-500" placeholder="Contraseña..." required autofocus>
                        <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded font-bold">Abrir</button>
                    </form>
                </div>
            <?php else: ?>
                <!-- GRID PRINCIPAL -->
                <div class="flex-1 flex flex-col">
                    <div class="col-span-12 bg-white rounded-lg border border-slate-200 shadow-sm flex flex-col h-full overflow-hidden">
                        <div class="border-b border-slate-100 py-4 px-6 bg-slate-50/50 flex justify-between items-center">
                            <h3 class="font-bold text-slate-700">Contenido</h3>
                            <span class="text-xs bg-slate-200 text-slate-600 px-2 py-1 rounded-full font-bold"><?php echo count($carpetas) + count($playlist); ?> items</span>
                        </div>
                        
                        <div class="p-4 overflow-y-auto custom-scrollbar flex-1 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4 content-start">
                            <!-- Botón Atrás -->
                            <?php if($carpetaActual): ?>
                                <a href="?folder=<?php echo urlencode($carpetaPadre); ?>" class="flex flex-col items-center justify-center gap-2 p-6 rounded-lg bg-slate-50 border border-dashed border-slate-300 text-slate-500 hover:bg-slate-100 hover:border-slate-400 transition group cursor-pointer h-40">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8 group-hover:-translate-y-1 transition" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 17l-5-5m0 0l5-5m-5 5h12" /></svg>
                                    <span class="font-medium text-sm">Subir Nivel</span>
                                </a>
                            <?php endif; ?>

                            <!-- Carpetas -->
                            <?php foreach($carpetas as $carpeta): $nombre = basename($carpeta); $rutaHija = $carpetaActual ? $carpetaActual . '/' . $nombre : $nombre; ?>
                                <div class="relative flex flex-col rounded-lg border border-slate-200 bg-white hover:shadow-md transition group overflow-hidden">
                                    <a href="?folder=<?php echo urlencode($rutaHija); ?>" class="flex-1 p-6 flex flex-col items-center justify-center text-center gap-3 h-40">
                                        <div class="text-blue-500 bg-blue-50 p-3 rounded-full"><svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M2 6a2 2 0 012-2h4l2 2h4a2 2 0 012 2v1H8a3 3 0 00-3 3v1.5a1.5 1.5 0 01-3 0V6z" clip-rule="evenodd" /><path d="M6 12a2 2 0 012-2h8a2 2 0 012 2v2a2 2 0 01-2 2H2h2a2 2 0 002-2v-2z" /></svg></div>
                                        <span class="font-medium text-slate-700 text-sm truncate w-full px-2"><?php echo $nombre; ?></span>
                                    </a>
                                    <div class="absolute top-2 right-2 flex gap-1 opacity-0 group-hover:opacity-100 transition-opacity bg-white/90 rounded shadow-sm p-1">
                                        <button onclick="openRenameModal('<?php echo $nombre; ?>', '', true)" class="p-1 text-yellow-600 hover:bg-yellow-50 rounded" title="Renombrar"><svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" /></svg></button>
                                        <button onclick="procesarItem('eliminar', '<?php echo $nombre; ?>', true)" class="p-1 text-red-600 hover:bg-red-50 rounded" title="Eliminar"><svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg></button>
                                    </div>
                                </div>
                            <?php endforeach; ?>

                            <!-- Archivos (Playlist) -->
                            <?php foreach($playlist as $index => $item): ?>
                                <div class="relative flex flex-col rounded-lg border border-slate-200 bg-white hover:shadow-md transition group overflow-hidden h-40">
                                    <!-- Área Clickable para ver en Overlay -->
                                    <div onclick="abrirVisor(<?php echo $index; ?>)" class="flex-1 cursor-pointer relative overflow-hidden bg-slate-100">
                                        <?php if($item['tipo'] === 'video'): ?>
                                            <div class="absolute inset-0 flex items-center justify-center bg-black/20 z-10"><svg class="w-12 h-12 text-white drop-shadow-lg opacity-80" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM9.555 7.168A1 1 0 008 8v4a1 1 0 001.555.832l3-2a1 1 0 000-1.664l-3-2z" clip-rule="evenodd"></path></svg></div>
                                            <div class="w-full h-full bg-slate-800 flex items-center justify-center text-slate-500 text-xs">VIDEO</div>
                                        <?php else: ?>
                                            <img src="<?php echo $item['url']; ?>" class="w-full h-full object-cover <?php echo $item['tipo'] === 'flat' ? 'object-center' : ''; ?> transition duration-500 group-hover:scale-105">
                                        <?php endif; ?>
                                        
                                        <div class="absolute bottom-0 left-0 right-0 bg-gradient-to-t from-black/70 to-transparent p-3 pt-10">
                                            <p class="text-white text-sm font-medium truncate"><?php echo htmlspecialchars($item['titulo']); ?></p>
                                            <span class="text-[10px] text-white/80 uppercase font-bold tracking-wider"><?php echo $item['tipo'] === 'flat' ? 'Plano' : ($item['tipo'] === 'video' ? 'Video' : '360°'); ?></span>
                                        </div>
                                    </div>

                                    <!-- Botones Acciones (Hover) -->
                                    <div class="absolute top-2 right-2 flex gap-1 opacity-0 group-hover:opacity-100 transition-opacity bg-white/90 rounded shadow-sm p-1 z-20">
                                        <?php if($item['tipo'] !== 'video'): ?>
                                            <button onclick="procesarItem('cambiar_tipo', '<?php echo $item['nombreReal']; ?>', false)" class="p-1.5 text-blue-600 hover:bg-blue-50 rounded" title="Cambiar Tipo">
                                                <?php if($item['tipo'] === 'flat'): ?><svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" /></svg><?php else: ?><svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" /></svg><?php endif; ?>
                                            </button>
                                        <?php endif; ?>
                                        <button onclick="openRenameModal('<?php echo $item['nombreReal']; ?>', '<?php echo addslashes($item['titulo']); ?>')" class="p-1.5 text-yellow-600 hover:bg-yellow-50 rounded" title="Editar Info"><svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" /></svg></button>
                                        <button onclick="procesarItem('eliminar', '<?php echo $item['nombreReal']; ?>', false)" class="p-1.5 text-red-600 hover:bg-red-50 rounded" title="Eliminar"><svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path fill-rule="evenodd" d="M9 2a1 1 0 00-.894.553L7.382 4H4a1 1 0 000 2v10a2 2 0 002 2h8a2 2 0 002-2V6a1 1 0 100-2h-3.382l-.724-1.447A1 1 0 0011 2H9zM7 8a1 1 0 012 0v6a1 1 0 11-2 0V8zm5-1a1 1 0 00-1 1v6a1 1 0 102 0V8a1 1 0 00-1-1z" clip-rule="evenodd" /></svg></button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <div id="modal-renombrar" class="modal fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm"><div class="modal-content bg-white w-full max-w-md rounded-xl shadow-2xl p-6 relative"><button onclick="closeModal('modal-renombrar')" class="absolute top-4 right-4 text-slate-400 hover:text-slate-600">✕</button><h3 class="text-xl font-bold text-slate-800 mb-4">Editar Descripción</h3><form action="" method="POST" id="formRenombrar"><input type="hidden" name="accion_item" value="renombrar"><input type="hidden" name="nombre_viejo" id="renameOldName"><div class="mb-4"><label class="block text-sm font-medium text-slate-600 mb-2">Nuevo Nombre / Descripción</label><input type="text" name="nombre_nuevo" id="renameNewName" required class="w-full rounded border border-slate-300 p-3 outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500"></div><div class="flex justify-end gap-3"><button type="button" onclick="closeModal('modal-renombrar')" class="px-4 py-2 rounded text-slate-600 hover:bg-slate-100">Cancelar</button><button type="submit" class="px-6 py-2 rounded bg-yellow-500 text-white font-bold hover:bg-yellow-600 shadow-lg">Guardar</button></div></form></div></div>

    <div id="modal-carpeta" class="modal fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm"><div class="modal-content bg-white w-full max-w-md rounded-xl shadow-2xl p-6 relative"><button onclick="closeModal('modal-carpeta')" class="absolute top-4 right-4 text-slate-400 hover:text-slate-600">✕</button><h3 class="text-xl font-bold text-slate-800 mb-4">Nueva Carpeta</h3><form action="" method="POST"><input type="hidden" name="crear_carpeta" value="1"><input type="text" name="nombre_carpeta" required class="w-full rounded border border-slate-300 p-3 mb-6" placeholder="Nombre Proyecto"><div class="flex justify-end gap-3"><button type="button" onclick="closeModal('modal-carpeta')" class="px-4 py-2 rounded text-slate-600 hover:bg-slate-100">Cancelar</button><button type="submit" class="px-6 py-2 rounded bg-blue-600 text-white font-bold hover:bg-blue-700">Crear</button></div></form></div></div>

    <div id="modal-subir" class="modal fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm"><div class="modal-content bg-white w-full max-w-md rounded-xl shadow-2xl p-6 relative"><button onclick="closeModal('modal-subir')" class="absolute top-4 right-4 text-slate-400 hover:text-slate-600">✕</button><h3 class="text-xl font-bold text-slate-800 mb-2">Subir Archivos</h3><p class="text-sm text-slate-500 mb-4">Destino: <strong><?php echo basename($carpetaActual); ?></strong></p><form action="" method="POST" enctype="multipart/form-data" id="uploadFormModal"><div class="mb-4 flex items-center gap-2"><input type="checkbox" name="is_flat" id="is_flat_check" value="1" class="w-4 h-4 text-blue-600 rounded"><label for="is_flat_check" class="text-sm text-slate-700 font-medium">Imagen Normal (Plana)</label></div><div class="border-2 border-dashed border-slate-300 rounded-lg p-8 text-center hover:border-blue-400 transition mb-6 relative group"><input type="file" name="imagen360[]" multiple required accept=".jpg,.jpeg,.png,.mp4" class="absolute inset-0 w-full h-full opacity-0 cursor-pointer z-10"><p class="text-sm text-slate-500">Arrastra o clic para seleccionar</p></div><div class="flex justify-end gap-3"><button type="button" onclick="closeModal('modal-subir')" class="px-4 py-2 rounded text-slate-600 hover:bg-slate-100">Cancelar</button><button type="button" onclick="submitUpload()" class="px-6 py-2 rounded bg-green-600 text-white font-bold hover:bg-green-700">Subir</button></div></form></div></div>

    <div id="modal-config" class="modal fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm"><div class="modal-content bg-white w-full max-w-md rounded-xl shadow-2xl p-6 relative"><button onclick="closeModal('modal-config')" class="absolute top-4 right-4 text-slate-400 hover:text-slate-600">✕</button><h3 class="text-xl font-bold text-slate-800 mb-4">Configuración</h3><form action="" method="POST" enctype="multipart/form-data"><input type="hidden" name="configurar_proyecto" value="1"><div class="mb-4"><label class="block text-sm font-bold text-slate-700 mb-2">Logo del Proyecto</label><?php if(!empty($currentProjectMeta['logo'])): ?><div class="mb-3 p-2 border rounded bg-slate-50 flex justify-center"><img src="<?php echo htmlspecialchars($currentProjectMeta['logo']); ?>" class="h-12 object-contain"></div><?php endif; ?><input type="file" name="project_logo_upload" accept=".jpg,.jpeg,.png,.webp" class="block w-full text-sm"></div><div class="mb-6 border-t border-slate-200 pt-4"><label class="block text-sm font-bold text-slate-700 mb-2 flex items-center gap-2"><svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-slate-500" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd" /></svg> Contraseña de Carpeta (Opcional)</label><input type="password" name="folder_password_set" class="w-full rounded border border-slate-300 p-2.5 text-sm" placeholder="Dejar vacío para no cambiar"><div class="mt-2 flex items-center gap-2"><input type="checkbox" name="remove_password" value="1" id="rm_pass" class="rounded text-blue-600"><label for="rm_pass" class="text-xs text-slate-500">Eliminar contraseña actual</label></div></div><div class="flex justify-end gap-3"><button type="button" onclick="closeModal('modal-config')" class="px-4 py-2 rounded text-slate-600 hover:bg-slate-100">Cancelar</button><button type="submit" class="px-6 py-2 rounded bg-blue-600 text-white font-bold hover:bg-blue-700">Guardar</button></div></form></div></div>

    <div id="modal-compartir" class="modal fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm"><div class="modal-content bg-white w-full max-w-lg rounded-xl shadow-2xl p-6 relative max-h-[90vh] overflow-y-auto custom-scrollbar"><button onclick="closeModal('modal-compartir')" class="absolute top-4 right-4 text-slate-400 hover:text-slate-600">✕</button><div class="flex items-center gap-3 mb-4"><div class="bg-indigo-100 p-2 rounded-full text-indigo-600"><svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z" /></svg></div><div><h3 class="text-xl font-bold text-slate-800">Generar Nuevo Link</h3></div></div><form action="" method="POST" class="mb-8"><input type="hidden" name="compartir_proyecto" value="1"><div class="grid grid-cols-2 gap-4 mb-4"><div><label class="block text-xs font-bold text-slate-600 uppercase mb-1">Contraseña</label><input type="text" name="share_pass" required class="w-full rounded border border-slate-300 p-2 text-sm" placeholder="Ej: Cliente2024"></div><div><label class="block text-xs font-bold text-slate-600 uppercase mb-1">Vigencia</label><select name="share_duration" class="w-full rounded border border-slate-300 p-2 text-sm bg-white"><option value="1h">1 Hora</option><option value="24h">24 Horas</option><option value="7d">7 Días</option><option value="30d">30 Días</option><option value="1y">1 Año</option><option value="forever">Permanente</option></select></div></div><button type="submit" class="w-full rounded bg-indigo-600 py-2 text-white font-bold hover:bg-indigo-700 shadow-lg text-sm">Crear Link</button></form><div class="border-t border-slate-200 pt-6"><h4 class="text-sm font-bold text-slate-700 mb-3 flex items-center gap-2">Enlaces Activos <span class="bg-slate-200 text-slate-600 px-2 py-0.5 rounded-full text-xs"><?php echo count($projectShares); ?></span></h4><?php if(empty($projectShares)): ?><p class="text-xs text-slate-400 text-center py-4">No hay enlaces activos.</p><?php else: ?><div class="space-y-3"><?php foreach($projectShares as $token => $data): $fullLink = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS']==='on'?"https":"http")."://{$_SERVER['HTTP_HOST']}".dirname($_SERVER['PHP_SELF'])."/share.php?token=$token"; $exp = $data['expires'] ? date('d/m/Y H:i', $data['expires']) : 'Nunca'; ?><div class="bg-slate-50 border border-slate-200 rounded-lg p-3 flex flex-col gap-2"><div class="flex justify-between items-start"><div class="text-xs text-slate-500 break-all pr-2 font-mono"><?php echo $fullLink; ?></div><button onclick="copyToClipboard('<?php echo $fullLink; ?>')" class="text-blue-600 hover:text-blue-800 text-xs font-bold whitespace-nowrap">Copiar</button></div><div class="flex justify-between items-center mt-1 border-t border-slate-200 pt-2"><div class="text-[10px] text-slate-400">Expira: <strong><?php echo $exp; ?></strong></div><div class="flex gap-2"><button onclick="openEditLink('<?php echo $token; ?>')" class="text-yellow-600 hover:text-yellow-800 p-1 rounded hover:bg-yellow-100"><svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path d="M13.586 3.586a2 2 0 112.828 2.828l-.793.793-2.828-2.828.793-.793zM11.379 5.793L3 14.172V17h2.828l8.38-8.379-2.83-2.828z" /></svg></button><form method="POST" onsubmit="return confirm('¿Eliminar?')" style="display:inline;"><input type="hidden" name="eliminar_link" value="1"><input type="hidden" name="token_link" value="<?php echo $token; ?>"><button type="submit" class="text-red-600 hover:text-red-800 p-1 rounded hover:bg-red-100"><svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M9 2a1 1 0 00-.894.553L7.382 4H4a1 1 0 000 2v10a2 2 0 002 2h8a2 2 0 002-2V6a1 1 0 100-2h-3.382l-.724-1.447A1 1 0 0011 2H9zM7 8a1 1 0 012 0v6a1 1 0 11-2 0V8zm5-1a1 1 0 00-1 1v6a1 1 0 102 0V8a1 1 0 00-1-1z" clip-rule="evenodd" /></svg></button></form></div></div></div><?php endforeach; ?></div><?php endif; ?></div></div></div>

    <div id="modal-editar-link" class="modal fixed inset-0 z-[60] flex items-center justify-center bg-black/50 backdrop-blur-sm"><div class="modal-content bg-white w-full max-w-sm rounded-xl shadow-2xl p-6 relative"><button onclick="closeModal('modal-editar-link')" class="absolute top-4 right-4 text-slate-400 hover:text-slate-600">✕</button><h3 class="text-lg font-bold text-slate-800 mb-4">Editar Enlace</h3><form action="" method="POST"><input type="hidden" name="editar_link_action" value="1"><input type="hidden" name="token_link" id="editTokenInput"><div class="mb-4"><label class="block text-xs font-bold text-slate-600 uppercase mb-1">Nueva Contraseña</label><input type="text" name="new_pass" class="w-full rounded border border-slate-300 p-2 text-sm" placeholder="Vacío para no cambiar"></div><div class="mb-6"><label class="block text-xs font-bold text-slate-600 uppercase mb-1">Nueva Vigencia</label><select name="new_duration" class="w-full rounded border border-slate-300 p-2 text-sm bg-white"><option value="no_change">No cambiar fecha</option><option value="1h">1 Hora</option><option value="24h">24 Horas</option><option value="7d">7 Días</option><option value="30d">30 Días</option><option value="1y">1 Año</option><option value="forever">Permanente</option></select></div><div class="flex justify-end gap-2"><button type="button" onclick="closeModal('modal-editar-link')" class="px-3 py-2 rounded text-slate-600 hover:bg-slate-100 text-sm">Cancelar</button><button type="submit" class="px-4 py-2 rounded bg-blue-600 text-white font-bold hover:bg-blue-700 text-sm">Guardar</button></div></form></div></div>

    <form id="actionForm" method="POST" style="display:none;">
        <input type="hidden" name="accion_item" id="formAccion">
        <input type="hidden" name="nombre_viejo" id="formNombreViejo">
        <input type="hidden" name="nombre_nuevo" id="formNombreNuevo">
    </form>

    <script>
        // Playlist Injection
        const playlist = <?php echo json_encode($playlist); ?>;
        let currentIndex = 0;
        let viewer = null; let videoPlayer = null;
        const dropOverlay = document.getElementById('drop-overlay');
        const loadingOverlay = document.getElementById('loading-overlay');
        let dragCounter = 0;

        // Unified Copy Function
        async function copyToClipboard(text) {
            try {
                await navigator.clipboard.writeText(text);
                showToast();
            } catch (err) {
                // Fallback
                const textArea = document.createElement("textarea");
                textArea.value = text;
                textArea.style.position = "fixed";
                textArea.style.left = "-9999px";
                document.body.appendChild(textArea);
                textArea.focus();
                textArea.select();
                try {
                    document.execCommand('copy');
                    showToast();
                } catch (e) {
                    alert("No se pudo copiar.");
                }
                document.body.removeChild(textArea);
            }
        }

        function showToast() {
            const t = document.getElementById('copy-toast');
            t.classList.remove('translate-y-20', 'opacity-0');
            setTimeout(() => t.classList.add('translate-y-20', 'opacity-0'), 3000);
        }

        // Visor Functions
        function abrirVisor(index) {
            if (index < 0 || index >= playlist.length) return;
            currentIndex = index;
            mostrarContenido();
            document.getElementById('viewer-modal').classList.add('active');
        }

        function closeViewer() {
            document.getElementById('viewer-modal').classList.remove('active');
            if (videoPlayer) videoPlayer.pause();
        }

        function nextSlide() {
            currentIndex = (currentIndex + 1) % playlist.length;
            mostrarContenido();
        }

        function prevSlide() {
            currentIndex = (currentIndex - 1 + playlist.length) % playlist.length;
            mostrarContenido();
        }

        function mostrarContenido() {
            const item = playlist[currentIndex];
            const container = document.getElementById('visor-container');
            const tituloEl = document.getElementById('visor-titulo');
            const contadorEl = document.getElementById('visor-contador');
            const btnDescargar = document.getElementById('btn-descargar');
            
            tituloEl.innerText = item.titulo;
            contadorEl.innerText = `${currentIndex + 1} / ${playlist.length}`;
            btnDescargar.href = item.url;
            container.innerHTML = ''; 
            
            if (viewer) { viewer.destroy(); viewer = null; }
            if (videoPlayer) { videoPlayer.dispose(); videoPlayer = null; }

            if (item.tipo === 'video') {
                let videoId = 'video-js-' + Date.now();
                let videoEl = document.createElement('video'); 
                videoEl.id = videoId; 
                videoEl.className = 'video-js vjs-default-skin vjs-big-play-centered';
                videoEl.controls = true; 
                videoEl.setAttribute('crossorigin', 'anonymous');
                videoEl.setAttribute('playsinline', 'true');
                
                let source = document.createElement('source'); 
                source.src = item.url; 
                source.type = 'video/mp4'; 
                videoEl.appendChild(source); 
                container.appendChild(videoEl);
                
                videoPlayer = videojs(videoId, { plugins: { pannellum: {} } });
                videoPlayer.play();

            } else if (item.tipo === 'flat') {
                const img = document.createElement('img');
                img.src = item.url;
                img.className = 'w-full h-full object-contain';
                container.appendChild(img);
            } else {
                let divEl = document.createElement('div'); 
                divEl.id = 'panorama'; 
                divEl.className = 'pnlm-container';
                divEl.style.width = '100%';
                divEl.style.height = '100%';
                container.appendChild(divEl);
                
                viewer = pannellum.viewer('panorama', {
                    "type": "equirectangular",
                    "panorama": item.url,
                    "autoLoad": true,
                    "autoRotate": -2,
                    "compass": true,
                    "showControls": true,
                    "backgroundColor": [0, 0, 0]
                });
            }
        }

        // Toggles & Modals
        function toggleMobileMenu() {
            const sidebar = document.getElementById('mobile-sidebar');
            const overlay = document.getElementById('mobile-overlay');
            if (sidebar.classList.contains('closed')) {
                sidebar.classList.remove('closed', '-translate-x-full');
                sidebar.classList.add('open', 'translate-x-0');
                overlay.classList.remove('hidden', 'opacity-0');
                overlay.classList.add('block', 'opacity-100');
            } else {
                sidebar.classList.add('closed', '-translate-x-full');
                sidebar.classList.remove('open', 'translate-x-0');
                overlay.classList.remove('block', 'opacity-100');
                overlay.classList.add('hidden', 'opacity-0');
            }
        }

        function openModal(id) { document.getElementById(id).classList.add('active'); }
        function closeModal(id) { document.getElementById(id).classList.remove('active'); }
        
        function openRenameModal(nombre, descActual) {
            document.getElementById('renameOldName').value = nombre;
            document.getElementById('renameNewName').value = descActual;
            openModal('modal-renombrar');
        }
        
        function openEditLink(token) { document.getElementById('editTokenInput').value = token; openModal('modal-editar-link'); }
        
        // Keyboard Nav
        document.addEventListener('keydown', function(e) {
            if (!document.getElementById('viewer-modal').classList.contains('active')) return;
            if (e.key === 'ArrowRight') nextSlide();
            if (e.key === 'ArrowLeft') prevSlide();
            if (e.key === 'Escape') closeViewer();
        });

        // Close modals on outside click
        document.querySelectorAll('.modal').forEach(m => { m.addEventListener('click', e => { if(e.target === m) closeModal(m.id); }); });

        // Drag & Drop
        window.addEventListener('dragenter', (e) => { e.preventDefault(); dragCounter++; <?php if ($carpetaActual): ?>dropOverlay.classList.remove('hidden');<?php endif; ?> });
        window.addEventListener('dragleave', (e) => { e.preventDefault(); dragCounter--; if (dragCounter === 0) dropOverlay.classList.add('hidden'); });
        window.addEventListener('dragover', (e) => { e.preventDefault(); });
        window.addEventListener('drop', (e) => {
            e.preventDefault(); dragCounter = 0; dropOverlay.classList.add('hidden');
            <?php if ($carpetaActual): ?>
            const files = e.dataTransfer.files; if (files.length > 0) handleFiles(files);
            <?php else: ?> alert('Entra a una carpeta para subir.'); <?php endif; ?>
        });

        function handleFiles(files) {
            loadingOverlay.classList.remove('hidden');
            const formData = new FormData();
            let count = 0;
            const isFlatCheckbox = document.getElementById('is_flat_check');
            if (isFlatCheckbox && isFlatCheckbox.checked) { formData.append('is_flat', '1'); }
            for (let i = 0; i < files.length; i++) {
                const ext = files[i].name.split('.').pop().toLowerCase();
                if (['jpg', 'jpeg', 'png', 'mp4'].includes(ext)) { formData.append('imagen360[]', files[i]); count++; }
            }
            if (count === 0) { loadingOverlay.classList.add('hidden'); alert('Solo JPG, PNG o MP4.'); return; }
            fetch(window.location.href, { method: 'POST', body: formData }).then(() => window.location.reload()).catch(() => { loadingOverlay.classList.add('hidden'); alert('Error.'); });
        }

        function submitUpload() {
            const fileInput = document.querySelector('#uploadFormModal input[type="file"]');
            if(fileInput.files.length === 0) { alert("Selecciona archivos"); return; }
            document.getElementById('loading-overlay').classList.remove('hidden');
            document.getElementById('uploadFormModal').submit();
        }

        function procesarItem(accion, nombre, esCarpeta, descActual) {
            if (accion === 'renombrar') {
                // Esta lógica se ha movido a openRenameModal
            } else {
                document.getElementById('formAccion').value = accion;
                document.getElementById('formNombreViejo').value = nombre;
                if (accion === 'eliminar') { if(confirm('¿Eliminar?')) document.getElementById('actionForm').submit(); }
                else if (accion === 'cambiar_tipo') { document.getElementById('actionForm').submit(); }
            }
        }
        
        <?php if($mensaje): ?> setTimeout(() => { const el = document.getElementById('server-toast'); if(el) el.remove(); }, 4000); <?php endif; ?>
    </script>
</body>
</html>
