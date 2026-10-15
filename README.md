# HH — Gestión de reparaciones

Aplicación web interna para gestionar cotizaciones y reparaciones de dispositivos inventariados en GLPI 11.

## Estado del esqueleto

Incluye autenticación Active Directory/LDAP, sesiones PHP endurecidas, protección CSRF, pantalla inicial y esquema inicial de base de datos independiente HH. No implementa todavía consultas de inventario ni escrituras en GLPI.

## Requisitos

- Debian con Apache y PHP 8.2+ (PHP 8.3 recomendado).
- Extensiones PHP: `ldap`, `pdo`, `pdo_mysql`, `mbstring`, `openssl`.
- MariaDB para la futura base de datos independiente de HH.
- Active Directory accesible mediante LDAPS o LDAP con StartTLS y validación de certificados.
- HTTPS habilitado en Apache antes de usar autenticación en producción.

## Configuración

1. Copiar `.env.example` a un archivo fuera del directorio público, por ejemplo `/etc/hh/hh.env`.
2. Completar los parámetros de LDAP/AD. No guardar secretos reales en Git.
3. Cargar las variables en el entorno de Apache/PHP-FPM desde un archivo protegido, propiedad de `root` y legible por el usuario del servicio web. El proyecto no carga automáticamente archivos `.env`.
4. Configurar Apache con `DocumentRoot /var/www/hh/public`; no publicar la raíz del repositorio.
5. Instalar las extensiones requeridas y verificar que el certificado TLS de AD sea de confianza para el sistema operativo.
6. Configurar `HH_ENV=production` y `HH_COOKIE_SECURE=1`. La cookie segura requiere HTTPS.

## Variables LDAP

- `HH_LDAP_URI`: URI LDAPS, por ejemplo `ldaps://dc01.example.local:636`.
- `HH_LDAP_BASE_DN`: base de búsqueda del dominio.
- `HH_LDAP_BIND_DN` y `HH_LDAP_BIND_PASSWORD`: cuenta de servicio de solo lectura para buscar usuarios.
- `HH_LDAP_USER_FILTER`: filtro LDAP con el marcador `{username}`.
- `HH_LDAP_USERNAME_ATTRIBUTE`: atributo de inicio de sesión, normalmente `sAMAccountName`.
- `HH_LDAP_DISPLAY_ATTRIBUTE` y `HH_LDAP_EMAIL_ATTRIBUTE`: atributos opcionales de presentación.

La aplicación busca al usuario con la cuenta de servicio y valida la contraseña con un bind separado. No almacena contraseñas. La cuenta de servicio debe tener permisos mínimos de lectura. No se desactiva la validación TLS.

## Valor del punto e historial monetario

El archivo `database/schema.sql` define:

- `hh_valores_punto`: historial de valores por punto con fechas de vigencia, moneda, motivo y usuario que registró el cambio.
- `hh_cotizaciones.valor_punto_aplicado` y `total_monetario`: copia del valor utilizado y el total calculado al emitir la cotización.
- `hh_cotizacion_detalles`: conserva puntos por línea y el valor monetario aplicado.

No se inserta un valor ficticio. En la siguiente etapa se construirá una pantalla administrativa para cambiar el valor, cerrar la vigencia anterior y crear una nueva. Las cotizaciones antiguas conservarán el valor y el precio originales; el nuevo valor solo aplicará a cotizaciones posteriores.

## Seguridad y límites actuales

- Cookies de sesión `HttpOnly`, `SameSite=Lax`, modo estricto, solo cookies, regeneración del ID y vencimiento por inactividad.
- Token CSRF en formularios POST.
- Filtros LDAP escapados y salida HTML codificada.
- No se implementa aún autorización por grupos de AD ni roles de aprobación; esto debe definirse antes de habilitar aprobaciones.
- La autenticación y el código deben probarse con el AD corporativo antes de producción.
- No se han creado consultas de inventario GLPI, ni se conecta HH a la base de datos de GLPI.

## Despliegue Apache

Apuntar el `DocumentRoot` a `/var/www/hh/public`, bloquear el acceso a `app/`, `database/` y a los archivos de configuración. Configurar HTTPS y permisos mínimos para el archivo de variables de entorno.

## Próximos pasos

1. Validar la autenticación en un entorno de pruebas con AD.
2. Definir los roles y grupos corporativos autorizados.
3. Crear la conexión PDO a la base independiente HH y una interfaz de administración para el valor del punto.
4. Recibir y validar el esquema real de inventario GLPI 11 antes de implementar consultas de lectura.
