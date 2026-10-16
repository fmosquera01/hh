# HH — Plugin de gestión de reparaciones para GLPI 11

HH se desarrolla como un **plugin nativo de GLPI 11** para funcionar dentro de `https://soporte.songa.com`.

## Ventajas de esta arquitectura

- Utiliza la sesión ya autenticada de GLPI; no implementa otro login, LDAP ni OAuth entre aplicaciones.
- La autenticación continúa siendo responsabilidad de GLPI y de su configuración corporativa de Active Directory/LDAP.
- Guarda la información propia de HH en tablas con prefijo `glpi_plugin_hh_`, sin alterar las tablas nativas de GLPI.
- El inventario se integrará en modo de lectura una vez validado el modelo real de GLPI. Este esqueleto no contiene consultas de inventario ni actualizaciones de activos.
- La desinstalación del plugin no elimina los datos empresariales, para evitar pérdida accidental de cotizaciones e historial.

## Estructura

- `setup.php`: metadatos, compatibilidad GLPI 11 y registro del menú.
- `hook.php`: instalación mediante la clase `Migration` de GLPI.
- `src/Dashboard.php`: entrada del plugin en el menú.
- `front/index.php`: pantalla inicial protegida por la sesión GLPI.
- Tablas iniciales:
  - `glpi_plugin_hh_pointvalues`: valor del punto y vigencias.
  - `glpi_plugin_hh_quotes`: cotización y copia del valor monetario aplicado.
  - `glpi_plugin_hh_quotedetails`: puntos y valor por línea.
  - `glpi_plugin_hh_quotehistory`: historial de estados y usuarios.

## Instalación en un entorno de pruebas

1. Realizar un respaldo de la base de datos y de los archivos de GLPI.
2. Copiar el repositorio completo a `<GLPI_ROOT>/plugins/hh`. El nombre del directorio debe ser exactamente `hh`.
3. Confirmar que el usuario del servidor web puede leer los archivos.
4. Entrar a GLPI con un usuario administrador y abrir **Configuración > Plugins**.
5. Instalar y activar **HH - Gestión de reparaciones**.
6. Abrir el menú **Plugins > HH - Gestión de reparaciones**.

No instalar inicialmente en producción sin probar primero la instalación y la activación en una instancia de pruebas. La instalación crea solo las tablas propias del plugin. No se crean datos de ejemplo ni se modifica el inventario.

## Valor del punto e historial de precios

El diseño almacena cada valor por punto con fecha de inicio y fin de vigencia, moneda, motivo y usuario responsable. Cada cotización y cada detalle conserva una copia del valor monetario aplicado y del importe calculado. Por eso, cambiar el valor vigente no debe recalcular ni alterar cotizaciones anteriores.

El valor real inicial **no está precargado**. La pantalla de administración para registrar y cambiar el valor se implementará después de validar el plugin instalado.

## Seguridad y próximos pasos

- El plugin no administra contraseñas ni inicia sesiones propias: usa el control de acceso de GLPI.
- Antes de producción deben definirse permisos específicos por perfil para técnicos, aprobadores y administradores, y comprobarse en todas las páginas y acciones.
- En GLPI 11, las operaciones de base de datos y migraciones deben usar las APIs del framework; no se deben emitir consultas SQL directas para leer o modificar inventario.
- No hay consultas de inventario implementadas. La integración se realizará cuando se confirme el tipo de activo, los campos necesarios y las asociaciones reales.
- La compatibilidad de código debe verificarse en una instancia de prueba con la versión exacta de GLPI 11 instalada en Songa.

## Referencia oficial

La estructura y los requisitos del plugin siguen la documentación de desarrollo de GLPI:
https://glpi-developer-documentation.readthedocs.io/en/master/plugins/
