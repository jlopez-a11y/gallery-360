<?php
session_start();

// Configuración
$sharesFile = 'shares.json'; 
$projectsFile = 'projects.json'; 
$baseDir = 'uploads/'; 
$token = $_GET['token'] ?? ''; 
$error = '';

// 1. Validaciones de Seguridad
if (!$token) die("Enlace inválido.");

$shares = file_exists($sharesFile) ? json_decode(file_get_contents($sharesFile), true) : [];
if (!isset($shares[$token])) die("Este enlace no existe o ha sido eliminado.");

$shareData = $shares[$token];

// Verificar expiración
if ($shareData['expires'] !== null && time() > $shareData['expires']) {
    unset($shares[$token]);
    file_put_contents($sharesFile, json_encode($shares));
    die("Este enlace ha expirado.");
}

$projectName = $shareData['folder'];
$rutaProyecto = $baseDir . $projectName . '/';

// 2. Cargar metadatos del proyecto
$projectMeta = [];
if (file_exists($projectsFile)) {
    $allProjects = json_decode(file_get_contents($projectsFile), true);
    $projectMeta = $allProjects[$projectName] ?? [];
}

// 3. Autenticación de Invitado
$sessionKey = 'share_auth_' . $token; 

// Logout con redirección limpia
if (isset($_GET['logout'])) {
    unset($_SESSION[$sessionKey]);
    header("Location: share.php?token=" . $token);
    exit;
}

// Procesar Login (Patrón PRG para evitar reenvío)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $passInput = $_POST['password'] ?? '';
    if (password_verify($passInput, $shareData['hash'])) {
        $_SESSION[$sessionKey] = true;
        // Redirección inmediata para limpiar el POST
        header("Location: share.php?token=" . $token);
        exit;
    } else {
        $error = "Contraseña incorrecta.";
    }
}

// 4. Formulario de Login (Si no está autorizado)
if (!isset($_SESSION[$sessionKey]) || $_SESSION[$sessionKey] !== true):
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acceso Protegido</title>
    <link rel="icon" type="image/jpeg" href="favicon.jpeg">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>body{font-family:'Inter',sans-serif}</style>
</head>
<body class="bg-slate-900 flex items-center justify-center h-screen text-slate-200 p-4">
    <div class="w-full max-w-md bg-slate-800 rounded-lg border border-slate-700 p-8 md:p-10 shadow-2xl">
        <div class="text-center mb-8">
            <?php if (!empty($shareData['logo']) && file_exists($shareData['logo'])): ?>
                <div class="flex justify-center mb-6">
                    <img src="<?php echo htmlspecialchars($shareData['logo']); ?>" alt="Logo Cliente" class="max-h-24 max-w-[200px] object-contain drop-shadow-lg">
                </div>
            <?php else: ?>
                <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-slate-700 mb-6 shadow-inner">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8 text-blue-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" /></svg>
                </div>
            <?php endif; ?>
            <h2 class="text-2xl font-bold text-white tracking-tight">Proyecto Privado</h2>
            <p class="text-sm text-slate-400 mt-2">Accediendo a: <br><span class="text-blue-400 font-semibold text-base"><?php echo htmlspecialchars(basename($projectName)); ?></span></p>
        </div>
        <?php if ($error): ?>
            <div class="mb-6 p-4 bg-red-900/30 border border-red-500/30 text-red-200 text-sm rounded-md flex items-center gap-3 shadow-sm">
                <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                <?php echo $error; ?>
            </div>
        <?php endif; ?>
        <form method="POST" class="space-y-5">
            <div>
                <label class="block mb-2 text-sm font-medium text-slate-300 uppercase tracking-wider">Contraseña de Acceso</label>
                <input type="password" name="password" required autofocus placeholder="••••••••" 
                    class="w-full rounded-md border border-slate-600 bg-slate-900/50 py-3 px-4 text-white outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500 transition placeholder-slate-600 shadow-inner text-lg tracking-widest">
            </div>
            <button class="w-full rounded-md bg-blue-600 py-3.5 font-bold text-white hover:bg-blue-500 transition shadow-lg shadow-blue-600/20 text-sm uppercase tracking-wide">Ingresar</button>
        </form>
    </div>
</body>
</html>
<?php 
exit; 
endif; 

// 5. PREPARAR DATOS DE LA LISTA (PLAYLIST)
$archivosRaw = glob($rutaProyecto . "*.{jpg,jpeg,png,mp4,JPG,JPEG,PNG,MP4}", GLOB_BRACE);
$urlBaseRelativa = $rutaProyecto;

$playlist = [];
if ($archivosRaw) {
    foreach ($archivosRaw as $archivo) {
        $nombreReal = basename($archivo);
        
        // Obtener descripción
        $desc = isset($projectMeta['files'][$nombreReal]) ? $projectMeta['files'][$nombreReal] : null;
        if (!$desc) $desc = preg_replace('/^(flat_)?\d+_\d+_/', '', $nombreReal);
        
        $esVideo = (strtolower(pathinfo($nombreReal, PATHINFO_EXTENSION)) === 'mp4');
        $esPlana = (strpos($nombreReal, 'flat_') === 0);
        
        $playlist[] = [
            'url' => $urlBaseRelativa . $nombreReal,
            'titulo' => $desc,
            'tipo' => $esPlana ? 'flat' : ($esVideo ? 'video' : '360')
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(basename($projectName)); ?> | Visor 360</title>
    <link rel="icon" type="image/jpeg" href="favicon.jpeg">
    <script src="https://cdn.tailwindcss.com"></script>
    
    <!-- Librerías Visor -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/pannellum@2.5.6/build/pannellum.css"/>
    <link href="https://vjs.zencdn.net/7.20.3/video-js.css" rel="stylesheet" />
    <script src="https://vjs.zencdn.net/7.20.3/video.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/pannellum@2.5.6/build/pannellum.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/pannellum@2.5.6/build/videojs-pannellum-plugin.js"></script>
    
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
        .video-js, .pnlm-container { width: 100%; height: 100%; }
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: #0f172a; }
        ::-webkit-scrollbar-thumb { background: #334155; border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: #475569; }
        
        /* Visor Overlay Fullscreen */
        #viewer-modal { opacity: 0; visibility: hidden; transition: all 0.3s ease; user-select: none; -webkit-user-select: none; }
        #viewer-modal.active { opacity: 1; visibility: visible; }
        
        /* Botones Navegación */
        .nav-btn {
            position: absolute; top: 50%; transform: translateY(-50%);
            background: rgba(0,0,0,0.4); color: white; border-radius: 50%;
            width: 50px; height: 50px; display: flex; align-items: center; justify-content: center;
            cursor: pointer; transition: all 0.2s; border: 1px solid rgba(255,255,255,0.1);
            z-index: 60; user-select: none; -webkit-user-select: none;
        }
        .nav-btn:hover { background: rgba(0,0,0,0.8); scale: 1.1; }
        .nav-btn.left { left: 20px; }
        .nav-btn.right { right: 20px; }
        
        /* Ajustes móviles */
        @media (max-width: 768px) {
            .nav-btn { width: 40px; height: 40px; }
            .nav-btn.left { left: 10px; }
            .nav-btn.right { right: 10px; }
        }
    </style>
</head>
<body class="bg-black h-screen flex flex-col overflow-hidden text-slate-300 relative">
    
    <!-- HEADER SUPERIOR -->
    <header class="bg-slate-900 border-b border-slate-800 h-16 md:h-20 shrink-0 flex items-center justify-between px-4 md:px-6 shadow-lg z-40 relative">
        <div class="flex items-center gap-4 overflow-hidden">
            <?php if (!empty($shareData['logo']) && file_exists($shareData['logo'])): ?>
                 <img src="<?php echo htmlspecialchars($shareData['logo']); ?>" alt="Logo" class="h-8 md:h-12 object-contain drop-shadow-md bg-white/5 rounded px-1">
            <?php else: ?>
                <div class="bg-slate-800 p-2 rounded text-blue-500">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
                    </svg>
                </div>
            <?php endif; ?>
            
            <div class="flex flex-col overflow-hidden">
                <h1 class="font-bold text-white text-sm md:text-xl truncate leading-tight"><?php echo htmlspecialchars(basename($projectName)); ?></h1>
                <p class="text-[10px] md:text-xs text-slate-500 font-medium uppercase tracking-wide hidden sm:block">Galería Interactiva</p>
            </div>
        </div>

        <a href="?token=<?php echo $token; ?>&logout=1" class="flex items-center gap-2 bg-red-600/90 hover:bg-red-500 text-white px-3 py-1.5 md:px-4 md:py-2 rounded-md shadow transition text-xs md:text-sm font-medium" title="Cerrar Sesión">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" /></svg>
            <span class="hidden md:inline">Salir</span>
        </a>
    </header>

    <!-- ÁREA DE CONTENIDO (GRID COMPLETO) -->
    <main class="flex-1 overflow-y-auto bg-slate-900 p-4 md:p-6">
        <?php if (empty($playlist)): ?>
            <div class="h-full flex flex-col items-center justify-center text-slate-500">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-16 w-16 mb-4 opacity-20" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M5 19a2 2 0 01-2-2V7a2 2 0 012-2h4l2 2h4a2 2 0 012 2v1M5 19h14a2 2 0 002-2v-5a2 2 0 00-2-2H9a2 2 0 00-2 2v5a2 2 0 01-2 2z" /></svg>
                <p>Esta carpeta está vacía</p>
            </div>
        <?php else: ?>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4 md:gap-6">
                <?php foreach($playlist as $index => $item): ?>
                    <div onclick="abrirVisor(<?php echo $index; ?>)" 
                         class="group relative aspect-[4/3] rounded-lg overflow-hidden bg-slate-800 border border-slate-700 cursor-pointer hover:shadow-xl hover:shadow-blue-500/10 hover:border-blue-500/50 transition-all duration-300 transform hover:-translate-y-1">
                        
                        <!-- Miniatura -->
                        <?php if($item['tipo'] === 'video'): ?>
                            <div class="absolute inset-0 flex items-center justify-center bg-black/40 z-10">
                                <div class="bg-white/20 backdrop-blur-sm p-3 rounded-full border border-white/30 group-hover:scale-110 transition-transform">
                                    <svg class="w-8 h-8 text-white drop-shadow-md" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM9.555 7.168A1 1 0 008 8v4a1 1 0 001.555.832l3-2a1 1 0 000-1.664l-3-2z" clip-rule="evenodd"></path></svg>
                                </div>
                            </div>
                            <div class="w-full h-full bg-slate-700"></div>
                        <?php elseif($item['tipo'] === 'flat'): ?>
                            <img src="<?php echo $item['url']; ?>" class="w-full h-full object-cover opacity-90 group-hover:opacity-100 transition duration-500 group-hover:scale-105">
                        <?php else: ?>
                            <img src="<?php echo $item['url']; ?>" class="w-full h-full object-cover opacity-80 group-hover:opacity-100 transition duration-500 group-hover:scale-105">
                            <div class="absolute top-2 right-2 bg-black/50 backdrop-blur px-2 py-1 rounded text-[10px] font-bold text-white flex items-center gap-1 border border-white/10">
                                <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" /></svg>
                                360°
                            </div>
                        <?php endif; ?>
                        
                        <!-- Etiqueta Tipo y Nombre -->
                        <div class="absolute bottom-0 left-0 right-0 bg-gradient-to-t from-black via-black/80 to-transparent p-4 pt-10 translate-y-1 group-hover:translate-y-0 transition-transform">
                            <p class="text-white font-medium truncate text-sm mb-1"><?php echo htmlspecialchars($item['titulo']); ?></p>
                            <span class="inline-block px-2 py-0.5 rounded text-[10px] font-bold uppercase tracking-wider border <?php echo $item['tipo'] === 'flat' ? 'bg-blue-900/60 text-blue-100 border-blue-500/30' : ($item['tipo'] === 'video' ? 'bg-purple-900/60 text-purple-100 border-purple-500/30' : 'bg-cyan-900/60 text-cyan-100 border-cyan-500/30'); ?>">
                                <?php echo $item['tipo'] === 'flat' ? 'Imagen Plana' : ($item['tipo'] === 'video' ? 'Video 360' : 'Tour 360'); ?>
                            </span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </main>

    <!-- VISOR OVERLAY FULLSCREEN -->
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

    <script>
        // Playlist Injection
        const playlist = <?php echo json_encode($playlist); ?>;
        let currentIndex = 0;
        
        let viewer = null; 
        let videoPlayer = null;

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

        // Manejar teclado
        document.addEventListener('keydown', function(e) {
            if (!document.getElementById('viewer-modal').classList.contains('active')) return;
            if (e.key === 'ArrowRight') nextSlide();
            if (e.key === 'ArrowLeft') prevSlide();
            if (e.key === 'Escape') closeViewer();
        });

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
    </script>
</body>
</html>
