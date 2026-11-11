# Gestión de reparaciones — Plugin para GLPI 11

HH es un **plugin nativo de GLPI 11**. La experiencia se trabaja dentro de la pestaña del activo, sin iniciar otra sesión ni buscar nuevamente el equipo.

## Integración con activos

- Añade **Gestión de activos y reparaciones** a todos los tipos registrados en `$CFG_GLPI['asset_types']`, incluidos los activos personalizados de Asset Definitions de GLPI 11.
- Conserva el flujo especializado de garantía, contratos, cotizaciones y puntos exclusivamente para **Teléfonos cuyo tipo sea HANDHELD**; Computadoras y demás activos usan el módulo general sin puntos.
- Incorpora un flujo general de reparación sin esquema de puntos para el resto de activos.
- Mantiene los datos operativos en tablas propias; los cambios de estado se realizan mediante la API de objetos de GLPI para conservar el historial nativo.

## Estado actual — versión 0.5.24

La pestaña nativa consulta en modo lectura los datos de activos, compra, garantía y contratos asociados para **Handhelds** (Phone con tipo HANDHELD), utilizando la API de consultas de GLPI y sus relaciones `FKEY`. El tipo del contrato vigente es el valor que se muestra en **Tipo / información de garantía** y prevalece sobre la garantía propia del equipo para determinar la cobertura. La fecha de inicio de la garantía propia toma `warranty_date` y, si no está informada, usa `buy_date`.

Reglas de cobertura:
- **Platinum:** cubre todo tipo de daño; objetivo de atención de 2 días.
- **Gold:** excluye la placa principal; objetivo de atención de 5 días.
- **Inveligent:** cobertura mediante puntos.
- Sin contrato vigente, se evalúa la garantía propia del equipo registrada en GLPI; si tampoco está vigente, se muestra sin cobertura.

La versión 0.5.16 establece por defecto el tipo interno HAND HELD al abrir el dashboard con Teléfono, reconociendo variantes de escritura como HANDHELD o HAND HELD. Añade un análisis de antigüedad basado en la fecha de compra registrada en GLPI, muestra la edad por activo y un KPI de equipos con cinco años o más para revisión de renovación; no declara automáticamente obsoleto ningún equipo. La versión 0.5.4 corrigió el filtrado dependiente del dashboard: la vista inicial selecciona Teléfono (Phone) y HANDHELD, las listas de tipo interno y modelo contienen valores únicos sin mezclar clases GLPI, y los modelos se limitan al tipo interno seleccionado. Al cambiar el tipo GLPI, se recalculan los subtipos y modelos y se descartan selecciones incompatibles. También añade el indicador de equipos actualmente en reparación, identificados por guía, envío y recepción confirmada por el proveedor, sin fecha de retorno a Songa y con ticket no resuelto/cerrado. El conteo es por activo único. También corrige los filtros dependientes para que tipo interno y modelo se limiten al tipo de elemento GLPI seleccionado. La versión 0.5.2 incorporó el historial de reparaciones con retorno registrado, repuestos y comparación mensual de compras y consumos. La versión 0.5.1 incorporó los filtros de tipo de elemento, tipo interno y modelo. Descubre los tipos de activos habilitados en GLPI mediante la configuración `asset_types`, incluyendo clases de Asset Definitions de GLPI 11, y cruza el inventario con cotizaciones aprobadas y movimientos de puntos HH. El dashboard muestra inventario, activos con reparaciones aprobadas, puntos históricos y consumo mensual. Incluye filtros separados para tipo de elemento GLPI (por ejemplo, Teléfono), tipo interno del activo (por ejemplo, Handheld) y modelo (por ejemplo, CK65 o EDA61K), leyendo los campos nativos de tipo/modelo de cada clase de activo cuando están disponibles. El dashboard mantiene las consultas de inventario en modo lectura; el flujo general de reparaciones actualiza el estado del activo mediante la API de GLPI y registra cada transición.


## Estructura

- `setup.php`: metadatos, compatibilidad GLPI 11 y registro de pestaña.
- `hook.php`: tablas del plugin y migración aditiva del catálogo maestro, compatibilidad por tipo/modelo y cabecera/detalle de cotizaciones.
- `src/Dashboard.php`: entrada del plugin en el menú.
- `src/RepairTab.php`: experiencia visual dentro de la ficha de activo.
- `src/Reports.php`: consultas de inventario, reparaciones aprobadas y movimientos de puntos.
- `src/RepairAnalytics.php`: análisis de reparaciones aprobadas con retorno registrado y detalle de repuestos.
- `front/reports.php`: dashboard gerencial e inventario completo.
- `front/index.php`: pantalla de entrada del plugin.

Las tablas existentes con prefijo `glpi_plugin_hh_` se conservan. La estructura ahora contempla catálogo maestro de ítems (`glpi_plugin_hh_items`), asignación por tipo/modelo (`glpi_plugin_hh_itemtypes`), cabecera (`glpi_plugin_hh_quotes`) y detalle (`glpi_plugin_hh_quotedetails`). Las migraciones agregan columnas faltantes sin borrar los datos existentes. La interfaz especializada de HH conserva su flujo propio; el módulo general de cotizaciones se habilita para el resto de activos en 0.5.24.

## Reportes gerenciales (0.5.23)

La versión 0.5.16 mejora el desplazamiento horizontal del inventario y los repuestos: añade manejo explícito de gestos laterales en pantallas táctiles, soporte de rueda horizontal de touchpad y mantiene sincronizada la barra superior. El CSV protege los campos de texto frente a fórmulas de hoja de cálculo. La impresión/PDF conserva el contenido del dashboard y las tablas se pueden desplegar bajo demanda. Además, el análisis de reparaciones y sus fechas de retorno se consulta por lotes, reduciendo las consultas repetidas por cotización; las consultas de activos, compras, cotizaciones y tickets se limitan a los elementos visibles del inventario; los nombres de fabricantes, tipos y modelos se almacenan en caché durante la carga del informe. Las consultas directas de inventario respetan las entidades activas del usuario en GLPI. Se añadió una validación automática de sintaxis PHP para las ramas main y las solicitudes de cambio (PHP 8.2 y 8.3).


- La aprobación/rechazo de cotizaciones, el movimiento de puntos y el registro de historial se guardan dentro de una transacción de base de datos; si falla cualquiera de esas operaciones, se revierte la decisión completa. La tabla de movimientos conserva además la restricción única por cotización y tipo de movimiento para impedir consumos duplicados.
- El acceso se controla con el permiso independiente **Consultar reportes gerenciales** en la matriz del perfil HH. No se concede automáticamente para evitar exponer información gerencial a perfiles que solo tenían lectura operativa.
- El inventario se obtiene de los tipos registrados en `$CFG_GLPI['asset_types']`; los activos personalizados se filtran por su definición para evitar duplicados en la tabla compartida de GLPI 11.
- En activos nativos, los filtros de tipo interno y modelo usan sus campos GLPI correspondientes (por ejemplo, `phonetypes_id` y `phonemodels_id` para Teléfonos). Las opciones se construyen desde el inventario ya limitado al `itemtype` seleccionado; el tipo interno y el modelo se muestran una sola vez por ID y sin prefijos de otras clases. La vista inicial usa `Phone` + `HANDHELD`; al cambiar la clase, se recalculan las listas y se descartan selecciones incompatibles.
- El KPI **Equipos actualmente en reparación** cuenta activos únicos con ticket GLPI abierto, guía de salida, fecha de envío y fecha de recepción por el proveedor, siempre que la fecha de retorno a Songa siga vacía. Los tickets resueltos o cerrados no se cuentan. La cifra respeta los filtros de inventario aplicados.
- Las cotizaciones `approved` se contabilizan como aprobadas, pero solo se consideran reparaciones con retorno confirmado cuando `returned_at` contiene una fecha válida.
- El detalle de repuestos instalados se toma de `glpi_plugin_hh_quotedetails` y solo incluye líneas de categoría `part` de cotizaciones aprobadas con retorno registrado. Los borradores, rechazados y aprobados sin retorno no se presentan como piezas instaladas.
- El comparativo mensual separa compras y consumos registrados en `glpi_plugin_hh_pointmovements`, calcula el neto mensual y muestra la variación del consumo frente al mes anterior.
- El análisis inicial de antigüedad está disponible; quedan pendientes la política configurable de renovación, exportación XLSX nativa con hojas de inventario, resumen mensual, repuestos y filtros aplicados; generación PDF en servidor sigue pendiente. Las consultas de cotizaciones e historial filtran los activos por las entidades activas y los permisos de lectura nativos del usuario; los accesos directos por ID validan que la cotización pertenezca al activo visible.

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


## Reportes gerenciales 0.5.16

Se amplía el dashboard con análisis visual de antigüedad del inventario, estado de garantía, modelos con más reparaciones, activos con mayor historial y alertas para priorizar renovación. El inventario y el detalle de repuestos se muestran bajo demanda para mantener limpia la vista ejecutiva. Las tablas incluyen desplazamiento horizontal táctil y una barra superior sincronizada. La impresión/PDF se aísla al contenido del informe, sin ocultar el contenedor principal de GLPI.


## Cotizaciones generales para activos que no son Handheld (0.5.24)

- El esquema de puntos y el flujo especializado permanecen exclusivos de los activos Phone cuyo tipo GLPI es HANDHELD.
- Computadoras, monitores, impresoras, cámaras, NVR, balanzas, activos personalizados y teléfonos que no sean HANDHELD utilizan cotizaciones generales en moneda, sin catálogo obligatorio ni puntos.
- Desde la ficha del activo se pueden registrar cotizaciones aunque no exista ticket asociado ni el activo esté en estado REPARACIÓN Sistemas. El ticket y los estados continúan controlando el proceso operativo, no bloquean el registro de una oferta.
- Cada cotización registra proveedor (catálogo GLPI o texto libre), número y fechas, vigencia, moneda, descripción, modalidad global o detalle libre, importes, observaciones y documento adjunto PDF/JPG/PNG.
- La modalidad global conserva el total informado por el proveedor sin inventar un desglose. La modalidad detallada admite conceptos y cantidades libres; los precios por línea son opcionales cuando el proveedor no los informa.
- Los borradores pueden editarse; luego se envían a aprobación, se aprueban o rechazan y se puede seleccionar una oferta aprobada indicando el motivo. Las decisiones quedan en historial.
- Las cotizaciones generales se almacenan en tablas independientes y no alteran las tablas de puntos, catálogo ni cotizaciones HH.

## Flujo general de activos y reparaciones (0.5.18)

- La pestaña de Gestión de reparaciones se registra dinámicamente para todos los tipos de activo de `$CFG_GLPI['asset_types']`, incluyendo clases nativas y clases creadas mediante Asset Definitions.
- El módulo general no utiliza puntos. El esquema de puntos, contratos, garantías, cotizaciones y catálogo HH continúa reservado exclusivamente a Handhelds.
- La recepción del activo se detecta en el hook síncrono `ITEM_ADD` de `Ticket_Item`. El activo asociado al ticket cambia inmediatamente a **REPARACIÓN Sistemas**, sin Cron ni tarea programada, únicamente cuando el ticket tiene el origen de solicitud configurado como **FormularioReparacion** (`requesttypes_id`).
- Para impedir que tickets ordinarios cambien estados por accidente, el hook solo actúa cuando el origen de solicitud del ticket coincide con **FormularioReparacion**, seleccionado en **Gestión de activos y reparaciones > Configurar flujo**. Asigne ese origen al formulario de recepción del catálogo de servicios.
- Al enviar el equipo a proveedor, el técnico utiliza **Enviar a proveedor** y el estado cambia a **REPARACIÓN Proveedor**. Al recibirlo de vuelta, utiliza **Regresó del proveedor** para devolverlo a **REPARACIÓN Sistemas**.
- Al finalizar, **Finalizar reparación** restaura el estado que el activo tenía antes de la recepción. La asignación del usuario no se modifica.
- Cada transición se registra en `glpi_plugin_hh_asset_history` y se activa el historial nativo de GLPI en el cambio de estado.
- El dashboard añade indicadores globales de **REPARACIÓN Sistemas**, **REPARACIÓN Proveedor**, **LIBRE** (backup), **DAÑADO** y **Asignados**. Los cinco indicadores se calculan sobre el inventario completo visible para el usuario, independientemente de los filtros de HH.
- La migración agrega únicamente tablas propias del plugin y conserva las cotizaciones, puntos, catálogos y datos históricos existentes.

### Configuración y prueba

1. En GLPI, cree o identifique el origen de solicitud **FormularioReparacion** en **Configuración > Desplegables > Origen de la solicitud**.
2. En el formulario del catálogo de servicios, configure **FormularioReparacion** como origen de la solicitud del ticket creado.
3. En **Plugins > Gestión de activos y reparaciones > Configurar flujo**, seleccione **FormularioReparacion** y active el cambio automático.
4. Compruebe que existan los estados exactos **REPARACIÓN Sistemas** y **REPARACIÓN Proveedor**. Para los indicadores también se reconocen **LIBRE** y **DAÑADO**.
5. Cree un ticket desde el formulario, seleccione un activo y verifique que su estado cambie antes de finalizar la respuesta de creación del ticket.
6. Cree un ticket ordinario con otra categoría y confirme que el estado del activo no cambie.
7. Pruebe el envío a proveedor, recepción desde proveedor y finalización. El último paso debe restaurar el estado previo a la recepción.

La versión 0.5.18 ajusta la identificación del formulario de recepción para utilizar el origen de solicitud `FormularioReparacion` en lugar de una categoría ITIL. El flujo general debe validarse en una instancia de pruebas antes de producción. No ejecutar pruebas destructivas ni cambiar estados de activos productivos.
