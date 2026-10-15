# HH — Gestión de reparaciones

Aplicación web interna para gestionar cotizaciones y reparaciones de dispositivos inventariados en GLPI 11.

## Estado inicial

Este repositorio contiene el esqueleto de autenticación LDAP/Active Directory, sesiones PHP seguras y una pantalla inicial. Todavía no implementa consultas de inventario ni escrituras en GLPI.

## Requisitos

- Debian con Apache y PHP 8.2+ (PHP 8.3 recomendado).
- Extensiones PHP: `ldap`, `pdo`, `pdo_mysql`, `mbstring`, `openssl`.
- MariaDB para la futura base de datos independiente de HH.
- Acceso a Active Directory mediante LDAPS (`ldaps://`) o LDAP con StartTLS validado por certificados.
- HTTPS habilitado en Apache.

## Configuración

1. Copiar `.env.example` a un archivo de configuración fuera del directorio público, por ejemplo `/etc/hh/hh.env`.
2. Completar los valores de LDAP/AD. No guardar contraseñas ni secretos en Git.
3. Configurar Apache para que el `DocumentRoot` sea el directorio `public/`.
4. Configurar el servicio PHP/Apache para cargar las variables de entorno desde un archivo protegido, propiedad de `root` y legible por el usuario del servicio web. El proyecto no carga automáticamente archivos `.env`.
5. Instalar las extensiones requeridas y verificar que el certificado TLS de AD sea de confianza para el sistema operativo.
6. Habilitar HTTPS antes de iniciar sesión en producción.

## Variables LDAP

- `HH_LDAP_URI`: URI LDAPS, por ejemplo `ldaps://dc01.example.local:636`.
- `HH_LDAP_BASE_DN`: base de búsqueda, por ejemplo `DC=example,DC=local`.
- `HH_LDAP_BIND_DN` y `HH_LDAP_BIND_PASSWORD`: cuenta de servicio de solo lectura para buscar usuarios. Mantener el secreto fuera de Git.
- `HH_LDAP_USER_FILTER`: filtro LDAP para localizar usuarios. El marcador `{username}` se reemplaza por el nombre escapado.
- `HH_LDAP_USERNAME_ATTRIBUTE`: atributo de inicio de sesión, normalmente `sAMAccountName` en Active Directory.
- `HH_LDAP_DISPLAY_ATTRIBUTE`: atributo para mostrar el nombre.
- `HH_LDAP_EMAIL_ATTRIBUTE`: atributo de correo, opcional.

La autenticación realiza una búsqueda con la cuenta de servicio y luego valida la contraseña del usuario con un bind separado. Las contraseñas nunca se almacenan. La cuenta de servicio debe tener permisos mínimos de lectura.

## Seguridad

- Sesión PHP con `HttpOnly`, `SameSite=Lax`, regeneración del ID y expiración por inactividad.
- Cookies `Secure` activadas en producción; requiere HTTPS.
- Protección CSRF en formularios POST.
- Escape de salida HTML y consultas LDAP con filtros escapados.
- No se desactiva la validación de certificados TLS.
- La autenticación LDAP por sí sola no implementa todavía autorización por grupos; agregarla antes de habilitar operaciones de aprobación.

## Despliegue Apache

Ejemplo conceptual: el `DocumentRoot` debe apuntar a `/var/www/hh/public`, nunca a la raíz del repositorio. Bloquear el acceso web a `app/`, `config/` y archivos de entorno. Configurar `HH_ENV=production` y `HH_COOKIE_SECURE=1` en el entorno del servicio.

## Siguiente etapa

Integrar la conexión PDO a la base independiente HH y la consulta de dispositivos de GLPI que proporcione el administrador. No se inventan tablas ni consultas de inventario en este esqueleto.