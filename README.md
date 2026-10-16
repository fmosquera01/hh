# Gestión de reparaciones — Plugin para GLPI 11

HH es un **plugin nativo de GLPI 11** para funcionar dentro de la instancia de Songa.

## Integración con activos

- Añade la pestaña **Gestión de reparaciones** a las fichas de **Computadoras** y **Teléfonos**.
- La pestaña recibe automáticamente el tipo y el ID del activo desde GLPI; no hay que buscar ni seleccionar de nuevo el equipo.
- Muestra el nombre, número de serie y número de inventario disponibles en la ficha del activo.
- Utiliza la sesión autenticada de GLPI; no implementa otro inicio de sesión.
- Conserva los datos propios de HH en tablas con prefijo `glpi_plugin_hh_`, sin alterar las tablas nativas.

## Estado actual

La versión 0.2.0 incorpora la pestaña contextual y la visualización básica del activo. **Todavía no implementa** el registro de cotizaciones, repuestos, puntos, aprobaciones, garantías ni historial de reparaciones. La pantalla lo indica expresamente; no se crean cotizaciones ficticias.

## Estructura

- `setup.php`: metadatos, compatibilidad GLPI 11 y registro de pestaña.
- `hook.php`: creación de las tablas propias mediante la clase Migration.
- `src/Dashboard.php`: entrada del plugin en el menú.
- `src/RepairTab.php`: pestaña que GLPI incorpora a las fichas de activos.
- `front/index.php`: pantalla inicial que puede conservar el contexto del activo.
- Tablas iniciales:
  - `glpi_plugin_hh_pointvalues`: valor del punto y vigencias.
  - `glpi_plugin_hh_quotes`: cotización y copia del valor monetario aplicado.
  - `glpi_plugin_hh_quotedetails`: puntos y valor por línea.
  - `glpi_plugin_hh_quotehistory`: historial de estados y usuarios.

## Instalación en entorno de pruebas

1. Copiar el repositorio completo a `<GLPI_ROOT>/plugins/hh`; el directorio debe llamarse exactamente `hh`.
2. Confirmar que el servidor web pueda leer los archivos.
3. En **Configuración > Plugins**, actualizar/activar el plugin si ya estaba instalado.
4. Abrir un activo tipo **Computadora** o **Teléfono** y verificar la pestaña **Gestión de reparaciones**.
5. Si la pestaña no aparece, revisar los registros de GLPI/PHP y confirmar que el plugin esté activo.

No se requiere modificar archivos del núcleo de GLPI ni recrear las tablas para este cambio. Validar primero en la instancia de pruebas GLPI 11.0.9.

## Diseño de datos

El diseño conserva el valor monetario aplicado a cada cotización y detalle para que cambios futuros en el valor por punto no alteren cotizaciones anteriores. El valor real inicial no está precargado y aún falta la pantalla de administración para configurarlo.

## Referencia oficial

La integración de pestañas sigue la documentación de desarrollo de GLPI:
https://glpi-developer-documentation.readthedocs.io/en/master/plugins/tips.html
