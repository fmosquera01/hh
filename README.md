# HH — Gestión de reparaciones

Aplicación web interna para gestionar cotizaciones y reparaciones de dispositivos inventariados en GLPI 11.

## Estado del esqueleto

Incluye inicio de sesión centralizado mediante GLPI 11 OAuth 2.0 Authorization Code, sesiones PHP endurecidas, protección CSRF, pantalla inicial y esquema inicial de base de datos HH independiente. No implementa todavía consultas de inventario ni escrituras en GLPI.

## Requisitos

- Debian con Apache y PHP 8.2+ (PHP 8.3 recomendado).
- Extensiones PHP: `curl`, `pdo`, `pdo_mysql`, `mbstring`, `openssl`.
- MariaDB para la futura base de datos independiente de HH.
- HTTPS válido para HH y GLPI; la verificación TLS está habilitada.
- GLPI 11 con OAuth/API v2 habilitada.

## Configurar el cliente OAuth en GLPI

1. En GLPI, abrir **Configuración > Clientes OAuth** (el nombre puede variar según idioma/perfil) y crear un cliente para HH.
2. Habilitar el flujo **Authorization Code**.
3. Autorizar los scopes `api`, `user` y `email`.
4. Registrar como URI de retorno la dirección exacta `https://TU-HOST-HH/glpi-callback.php`. Debe coincidir con `HH_BASE_URL` y no incluir una barra final.
5. Guardar el Client ID y Client Secret. El secreto debe permanecer fuera del repositorio.
6. Confirmar que el cliente tiene permitido el acceso desde la IP de HH si se configuraron restricciones de IP en GLPI.

## Configuración en Debian

1. Copiar `.env.example` a un archivo protegido fuera del directorio público, por ejemplo `/etc/hh/hh.env`.
2. Completar `HH_BASE_URL` con la URL real de HH, y `GLPI_OAUTH_CLIENT_ID` y `GLPI_OAUTH_CLIENT_SECRET` con los valores del cliente OAuth.
3. Cargar las variables en el entorno de Apache/PHP-FPM desde un archivo protegido, propiedad de `root` y legible por el usuario del servicio web. El proyecto no carga automáticamente archivos `.env`.
4. Configurar Apache con `DocumentRoot /var/www/hh/public`; no publicar la raíz del repositorio.
5. Instalar las extensiones PHP requeridas, incluyendo `php-curl`, y verificar que el sistema confíe en los certificados TLS de HH y GLPI.
6. Configurar `HH_ENV=production` y `HH_COOKIE_SECURE=1`. La cookie segura requiere HTTPS.

## Flujo de autenticación

- HH redirige a GLPI en `/api.php/authorize`.
- GLPI autentica al usuario con el mecanismo corporativo que ya tenga configurado (por ejemplo, Active Directory/LDAP).
- GLPI devuelve un código a `glpi-callback.php`; HH valida el parámetro `state`, intercambia el código por un token y consulta la identidad mediante la API.
- HH crea su propia sesión segura. No recibe ni almacena la contraseña de AD y no comparte directamente cookies/sesiones PHP con GLPI.

El cliente OAuth se configura una sola vez. No se necesita duplicar en HH los parámetros de conexión LDAP.

## Valor del punto e historial monetario

El archivo `database/schema.sql` define:

- `hh_valores_punto`: historial de valores por punto con fechas de vigencia, moneda, motivo y usuario que registró el cambio.
- `hh_cotizaciones.valor_punto_aplicado` y `total_monetario`: copia del valor utilizado y el total calculado al emitir la cotización.
- `hh_cotizacion_detalles`: conserva puntos por línea y el valor monetario aplicado.

No se inserta un valor ficticio. En la siguiente etapa se construirá una pantalla administrativa para cambiar el valor, cerrar la vigencia anterior y crear una nueva. Las cotizaciones antiguas conservarán el valor y el precio originales; el nuevo valor solo aplicará a cotizaciones posteriores.

## Seguridad y límites actuales

- Cookies de sesión `HttpOnly`, `SameSite=Lax`, modo estricto, solo cookies, regeneración del ID y vencimiento por inactividad.
- Token CSRF en formularios POST.
- Parámetro OAuth `state` aleatorio y validado en el callback.
- Verificación TLS de cURL activada; no se desactivan la validación del certificado ni la del hostname.
- No se implementa aún autorización por grupos de GLPI/AD ni roles de aprobación; esto debe definirse antes de habilitar aprobaciones.
- El flujo OAuth debe probarse con el cliente configurado en el GLPI real antes de producción.
- No se han creado consultas de inventario GLPI ni conexiones directas a su base de datos.

## Despliegue Apache

Apuntar el `DocumentRoot` a `/var/www/hh/public`, bloquear el acceso a `app/`, `database/` y a los archivos de configuración. Configurar HTTPS y permisos mínimos para el archivo de variables de entorno.

## Próximos pasos

1. Registrar y validar el cliente OAuth de HH en GLPI.
2. Probar el inicio de sesión en un entorno controlado.
3. Definir los roles y grupos corporativos autorizados.
4. Crear la conexión PDO a la base independiente HH y una interfaz de administración para el valor del punto.
5. Recibir y validar el esquema real de inventario GLPI 11 antes de implementar consultas de lectura.
