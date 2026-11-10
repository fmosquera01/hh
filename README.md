# Gestión de reparaciones — Plugin para GLPI 11

HH es un **plugin nativo de GLPI 11**. La experiencia se trabaja dentro de la pestaña del activo, sin iniciar otra sesión ni buscar nuevamente el equipo.

## Integración con activos

- Añade **Gestión de reparaciones** a las fichas de **Computadoras** y **Teléfonos**.
- Recibe automáticamente tipo e ID del activo desde GLPI.
- Muestra datos de contexto del activo disponibles en la ficha.
- No modifica tablas nativas de GLPI.

## Estado actual — versión 0.5.9

La pestaña nativa consulta en modo lectura los datos de activos, compra, garantía y contratos asociados para **Computadoras** y **Teléfonos**, utilizando la API de consultas de GLPI y sus relaciones `FKEY`. El tipo del contrato vigente es el valor que se muestra en **Tipo / información de garantía** y prevalece sobre la garantía propia del equipo para determinar la cobertura. La fecha de inicio de la garantía propia toma `warranty_date` y, si no está informada, usa `buy_date`.

Reglas de cobertura:
- **Platinum:** cubre todo tipo de daño; objetivo de atención de 2 días.
- **Gold:** excluye la placa principal; objetivo de atención de 5 días.
- **Inveligent:** cobertura mediante puntos.
- Sin contrato vigente, se evalúa la garantía propia del equipo registrada en GLPI; si tampoco está vigente, se muestra sin cobertura.

La versión 0.5.9 establece por defecto el tipo interno HAND HELD al abrir el dashboard con Teléfono, reconociendo variantes de escritura como HANDHELD o HAND HELD. Añade un análisis de antigüedad basado en la fecha de compra registrada en GLPI, muestra la edad por activo y un KPI de equipos con cinco años o más para revisión de renovación; no declara automáticamente obsoleto ningún equipo. La versión 0.5.4 corrigió el filtrado dependiente del dashboard: la vista inicial selecciona Teléfono (Phone) y HANDHELD, las listas de tipo interno y modelo contienen valores únicos sin mezclar clases GLPI, y los modelos se limitan al tipo interno seleccionado. Al cambiar el tipo GLPI, se recalculan los subtipos y modelos y se descartan selecciones incompatibles. También añade el indicador de equipos actualmente en reparación, identificados por guía, envío y recepción confirmada por el proveedor, sin fecha de retorno a Songa y con ticket no resuelto/cerrado. El conteo es por activo único. También corrige los filtros dependientes para que tipo interno y modelo se limiten al tipo de elemento GLPI seleccionado. La versión 0.5.2 incorporó el historial de reparaciones con retorno registrado, repuestos y comparación mensual de compras y consumos. La versión 0.5.1 incorporó los filtros de tipo de elemento, tipo interno y modelo. Descubre los tipos de activos habilitados en GLPI mediante la configuración `asset_types`, incluyendo clases de Asset Definitions de GLPI 11, y cruza el inventario con cotizaciones aprobadas y movimientos de puntos HH. El dashboard muestra inventario, activos con reparaciones aprobadas, puntos históricos y consumo mensual. Incluye filtros separados para tipo de elemento GLPI (por ejemplo, Teléfono), tipo interno del activo (por ejemplo, Handheld) y modelo (por ejemplo, CK65 o EDA61K), leyendo los campos nativos de tipo/modelo de cada clase de activo cuando están disponibles. El módulo no modifica los activos ni los registros de reparaciones.


## Estructura

- `setup.php`: metadatos, compatibilidad GLPI 11 y registro de pestaña.
- `hook.php`: tablas del plugin y migración aditiva del catálogo maestro, compatibilidad por tipo/modelo y cabecera/detalle de cotizaciones.
- `src/Dashboard.php`: entrada del plugin en el menú.
- `src/RepairTab.php`: experiencia visual dentro de la ficha de activo.
- `src/Reports.php`: consultas de inventario, reparaciones aprobadas y movimientos de puntos.
- `src/RepairAnalytics.php`: análisis de reparaciones aprobadas con retorno registrado y detalle de repuestos.
- `front/reports.php`: dashboard gerencial e inventario completo.
- `front/index.php`: pantalla de entrada del plugin.

Las tablas existentes con prefijo `glpi_plugin_hh_` se conservan. La estructura ahora contempla catálogo maestro de ítems (`glpi_plugin_hh_items`), asignación por tipo/modelo (`glpi_plugin_hh_itemtypes`), cabecera (`glpi_plugin_hh_quotes`) y detalle (`glpi_plugin_hh_quotedetails`). Las migraciones agregan columnas faltantes sin borrar los datos existentes. La interfaz todavía no guarda cotizaciones.

## Reportes gerenciales (0.5.9)

La versión 0.5.9 mejora el desplazamiento horizontal del inventario y los repuestos: añade manejo explícito de gestos laterales en pantallas táctiles, soporte de rueda horizontal de touchpad y mantiene sincronizada la barra superior. El CSV protege los campos de texto frente a fórmulas de hoja de cálculo. La impresión/PDF conserva el contenido del dashboard y las tablas se pueden desplegar bajo demanda.


- El acceso se controla con el permiso independiente **Consultar reportes gerenciales** en la matriz del perfil HH. No se concede automáticamente para evitar exponer información gerencial a perfiles que solo tenían lectura operativa.
- El inventario se obtiene de los tipos registrados en `$CFG_GLPI['asset_types']`; los activos personalizados se filtran por su definición para evitar duplicados en la tabla compartida de GLPI 11.
- En activos nativos, los filtros de tipo interno y modelo usan sus campos GLPI correspondientes (por ejemplo, `phonetypes_id` y `phonemodels_id` para Teléfonos). Las opciones se construyen desde el inventario ya limitado al `itemtype` seleccionado; el tipo interno y el modelo se muestran una sola vez por ID y sin prefijos de otras clases. La vista inicial usa `Phone` + `HANDHELD`; al cambiar la clase, se recalculan las listas y se descartan selecciones incompatibles.
- El KPI **Equipos actualmente en reparación** cuenta activos únicos con ticket GLPI abierto, guía de salida, fecha de envío y fecha de recepción por el proveedor, siempre que la fecha de retorno a Songa siga vacía. Los tickets resueltos o cerrados no se cuentan. La cifra respeta los filtros de inventario aplicados.
- Las cotizaciones `approved` se contabilizan como aprobadas, pero solo se consideran reparaciones con retorno confirmado cuando `returned_at` contiene una fecha válida.
- El detalle de repuestos instalados se toma de `glpi_plugin_hh_quotedetails` y solo incluye líneas de categoría `part` de cotizaciones aprobadas con retorno registrado. Los borradores, rechazados y aprobados sin retorno no se presentan como piezas instaladas.
- El comparativo mensual separa compras y consumos registrados en `glpi_plugin_hh_pointmovements`, calcula el neto mensual y muestra la variación del consumo frente al mes anterior.
- El análisis inicial de antigüedad está disponible; quedan pendientes la política configurable de renovación, exportación XLSX nativa y generación PDF en servidor.

## Instalar en entorno de pruebas

1. Copiar/actualizar el repositorio completo en `<GLPI_ROOT>/plugins/hh`.
2. Confirmar que el directorio se llame exactamente `hh`.
3. En **Configuración > Plugins**, verificar que esté activo.
4. Abrir una Computadora o un Teléfono y entrar a **Gestión de reparaciones**.
5. Validar la distribución en GLPI 11.0.9 y compartir capturas/comentarios para ajustar el diseño.

No se requiere modificar archivos del núcleo de GLPI. Validar en la instancia de pruebas antes de pasar a producción.

## Reglas de negocio consideradas en el diseño

- Cada cotización conservará una copia del valor por punto aplicado para no alterar importes históricos.
- La evaluación futura advertirá si existe una reparación repetida dentro de 60 días.
- La evaluación futura comparará el importe de reparación con el 40% del valor de compra del activo.
- Las reglas se deben confirmar y configurar antes de implementar los cálculos funcionales.


## Reportes gerenciales 0.5.9

Se amplía el dashboard con análisis visual de antigüedad del inventario, estado de garantía, modelos con más reparaciones, activos con mayor historial y alertas para priorizar renovación. El inventario y el detalle de repuestos se muestran bajo demanda para mantener limpia la vista ejecutiva. Las tablas incluyen desplazamiento horizontal táctil y una barra superior sincronizada. La impresión/PDF se aísla al contenido del informe, sin ocultar el contenedor principal de GLPI.
