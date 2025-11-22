<?php
session_start();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pinIngresado = $_POST['pin'] ?? '';
    if ($pinIngresado === 'Galeria360') {
        $_SESSION['usuario_autorizado'] = true;
        header('Location: galeria.php');
        exit;
    } else {
        $error = 'PIN de acceso incorrecto.';
    }
}

if (isset($_SESSION['usuario_autorizado']) && $_SESSION['usuario_autorizado'] === true) {
    header('Location: galeria.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iniciar Sesión | Gestor 360</title>
    <link rel="icon" type="image/jpeg" href="favicon.jpeg">
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Fuente Inter similar a Satoshi usada en TailAdmin -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
    </style>
</head>
<body class="bg-slate-100 flex items-center justify-center h-screen">

    <div class="w-full max-w-[1000px] bg-white rounded-sm shadow-default grid grid-cols-1 lg:grid-cols-2 overflow-hidden shadow-lg">
        
        <!-- Lado Izquierdo: Branding -->
        <div class="hidden lg:flex flex-col justify-center items-center bg-blue-600 text-white p-12">
            <div class="mb-6 bg-white/20 p-4 rounded-full">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-12 w-12" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" />
                </svg>
            </div>
            <h2 class="text-3xl font-bold mb-2">Gestor 360°</h2>
            <p class="text-blue-100 text-center">Plataforma segura para gestión de recorridos virtuales y fotografía panorámica.</p>
        </div>

        <!-- Lado Derecho: Formulario -->
        <div class="w-full p-8 sm:p-12 flex flex-col justify-center">
            <div class="mb-6">
                <h3 class="text-2xl font-bold text-slate-800 mb-1">Bienvenido</h3>
                <p class="text-slate-500 text-sm">Ingresa tu PIN de seguridad para continuar.</p>
            </div>

            <?php if ($error): ?>
                <div class="mb-4 p-4 bg-red-50 border-l-4 border-red-500 text-red-700 text-sm">
                    <p class="font-bold">Error</p>
                    <p><?php echo $error; ?></p>
                </div>
            <?php endif; ?>

            <form method="POST" action="">
                <div class="mb-4">
                    <label class="mb-2.5 block font-medium text-slate-700">PIN de Seguridad</label>
                    <div class="relative">
                        <input type="password" name="pin" placeholder="••••••••" required autofocus
                            class="w-full rounded-lg border border-slate-300 bg-transparent py-4 pl-6 pr-10 text-slate-700 outline-none focus:border-blue-600 focus:ring-1 focus:ring-blue-600 transition" />
                        
                        <span class="absolute right-4 top-4 text-slate-400">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                            </svg>
                        </span>
                    </div>
                </div>

                <div class="mb-5">
                    <input type="submit" value="Ingresar al Panel" 
                        class="w-full cursor-pointer rounded-lg border border-blue-600 bg-blue-600 p-4 text-white transition hover:bg-opacity-90 font-medium" />
                </div>
            </form>
        </div>
    </div>
</body>
</html>
