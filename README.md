# gallery-360
Es una aplicación web "Flat-file" (sin base de datos SQL) muy completa para la gestión y visualización de medios 360° (imágenes y videos), diseñada para fotógrafos, arquitectos o agentes inmobiliarios. 

**Contraseña inicial: Galeria360
**Sientete en la libertad de usar un sistema de autentificación mas robusto e implementar un archivo .htaccess en tu servidor para evitar accesos directamente a usuarios no loggeados.

# Gestor de Medios 360° (PHP Flat-file)

Una aplicación web completa y ligera para la gestión, visualización y entrega de medios 360° (imágenes y videos) y planos. Diseñada para fotógrafos, arquitectos y agentes inmobiliarios.

**Características Principales:**
- **Sin Base de Datos SQL:** Funciona completamente con sistema de archivos y JSON ("Flat-file").
- **Visor 360° Integrado:** Utiliza Pannellum para imágenes y Video.js para videos.
- **Gestión de Archivos:** Subida "Drag & Drop", creación de carpetas, renombrado y borrado.
- **Sistema de Entregas (Share):** Generación de enlaces públicos con caducidad automática y contraseña para clientes.
- **Seguridad:** Bloqueo de carpetas con contraseña y panel de administración protegido.

---

## 📂 Estructura del Proyecto

La aplicación consta de dos controladores principales:

### 1. Panel de Administración (`galeria.php`)
El centro de mando para el administrador.
- **Funciones:** Navegación de directorios, subida de archivos, configuración de proyectos (logos, passwords) y generación de ZIPs para descarga.
- **Detección de Tipos:** Distingue automáticamente entre imágenes 360° y planas mediante prefijos de archivo (`flat_`).
- **UX:** Interfaz reactiva con Tailwind CSS, notificaciones "Flash" y patrón PRG para evitar reenvíos de formularios.

### 2. Visor de Cliente (`share.php`)
Interfaz limpia y "White-label" para compartir proyectos.
- **Acceso Controlado:** Autenticación independiente mediante Token y Contraseña.
- **Auto-Expiración:** Los enlaces pueden configurarse para caducar (1h, 24h, 7 días, etc.).
- **Branding:** Muestra el logo específico del proyecto configurado en el admin.

---

## 🛠️ Arquitectura Técnica

- **Backend:** PHP Nativo (Compatible con versiones 7.4+ / 8.x).
- **Frontend:** Tailwind CSS (CDN), Vanilla JS.
- **Librerías:**
  - [Pannellum](https://pannellum.org/) (Visor 360).
  - [Video.js](https://videojs.com/) (Reproductor multimedia).
- **Almacenamiento de Datos (NoSQL):**
  - `uploads/`: Almacenamiento físico de medios.
  - `projects.json`: Metadatos de carpetas, bloqueos y descripciones.
  - `shares.json`: Registro de enlaces compartidos, hashes y expiraciones.

---

## 🚀 Instalación y Requisitos

1. **Servidor:** Cualquier servidor web con soporte PHP (Apache/Nginx).
2. **Permisos:** Asegúrate de dar permisos de escritura (`CHMOD 777` o `755` según el usuario del servidor) a:
   - La carpeta raíz (para crear `uploads/`).
   - Los archivos `.json` (se crearán automáticamente).
3. **Configuración PHP:** Se recomienda ajustar `post_max_size` y `upload_max_filesize` a 500M o más para archivos de video grandes.

---

## 🔒 Notas de Seguridad (Importante)

Dado que la aplicación utiliza archivos JSON para almacenar metadatos sensibles (hashes de contraseñas, tokens), es **crítico** protegerlos para evitar su descarga directa.

### Protección Recomendada
Crea un archivo `.htaccess` en el directorio raíz con el siguiente contenido:

```apache
<FilesMatch "\.json$">
    Order Allow,Deny
    Deny from all
</FilesMatch>
Options -Indexes
