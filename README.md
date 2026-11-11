# Gestión de reparaciones — Plugin para GLPI 11

HH es un **plugin nativo de GLPI 11**. La experiencia se trabaja dentro de la pestaña del activo, sin iniciar otra sesión ni buscar nuevamente el equipo.

## Integración con activos

- Añade **Gestión de activos y reparaciones** a todos los tipos registrados en `$CFG_GLPI['asset_types']`, incluidos los activos personalizados de Asset Definitions de GLPI 11.
- Conserva el flujo especializado de garantía, contratos, cotizaciones y puntos exclusivamente para **Teléfonos cuyo tipo sea HANDHELD**; Computadoras y demás activos usan el módulo general sin puntos.
- Incorpora un flujo general de reparación sin esquema de puntos para el resto de activos.
- Mantiene los datos operativos en tablas propias; los cambios de estado se realizan mediante la API de objetos de GLPI para conservar el historial nativo.

## Estado actual — versión 0.5.30

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
- `front/general_quotes.php`: cotizaciones económicas flexibles para activos que no son Handheld.
- `src/Reports.php`: consultas de inventario, reparaciones aprobadas y movimientos de puntos.
- `src/RepairAnalytics.php`: análisis de reparaciones aprobadas con retorno registrado y detalle de repuestos.
- `front/reports.php`: dashboard gerencial e inventario completo.
- `front/index.php`: pantalla de entrada del plugin.

Las tablas existentes con prefijo `glpi_plugin_hh_` se conservan. La estructura contempla el catálogo maestro de ítems (`glpi_plugin_hh_items`), asignación por tipo/modelo (`glpi_plugin_hh_itemtypes`), cotizaciones especializadas HH (`glpi_plugin_hh_quotes` y `glpi_plugin_hh_quotedetails`) y cotizaciones generales independientes (`glpi_plugin_hh_general_quotes`, `glpi_plugin_hh_general_quotelines`, `glpi_plugin_hh_general_quotehistory`). Las migraciones agregan columnas faltantes sin borrar los datos existentes. La interfaz especializada de HH conserva su flujo propio; el módulo general de cotizaciones se habilita para el resto de activos en 0.5.24.

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
- Las alertas de reparación recurrente comparan la fecha de último retorno confirmado con una nueva cotización; el período inicial es de 60 días y se puede configurar.
- Las cotizaciones HH y generales muestran una advertencia cuando alcanzan el porcentaje configurado del valor de compra (40 % inicial). La advertencia no bloquea la cotización.
- La antigüedad para revisar renovación usa un umbral configurable (5 años iniciales); es un indicador y no una orden de reemplazo.


## Reportes gerenciales 0.5.16

Se amplía el dashboard con análisis visual de antigüedad del inventario, estado de garantía, modelos con más reparaciones, activos con mayor historial y alertas para priorizar renovación. El inventario y el detalle de repuestos se muestran bajo demanda para mantener limpia la vista ejecutiva. Las tablas incluyen desplazamiento horizontal táctil y una barra superior sincronizada. La impresión/PDF se aísla al contenido del informe, sin ocultar el contenedor principal de GLPI.


## Cotizaciones generales sin IVA y vista integrada (0.5.27)
- Las cotizaciones generales se registran sin IVA; los impuestos se calculan en el proceso posterior de compra/facturación. Las columnas históricas se mantienen por compatibilidad y las nuevas cotizaciones guardan impuesto cero.
- Se retiró de la ficha genérica el bloque de seguimiento operativo duplicado; el seguimiento permanece en el historial nativo de GLPI.
- Los iframes de cotizaciones e historial ajustan su altura al contenido y desactivan el desplazamiento interno, dejando un único desplazamiento vertical en la ficha del activo.

## Presentación e IVA de cotizaciones generales (0.5.25)

- La pantalla de cotizaciones generales utiliza una presentación autónoma y adaptable, con identidad visual azul Songa, tarjetas, estados diferenciados, resumen económico y formularios compactos. No depende de que el iframe cargue el CSS del shell de GLPI.
- La tasa de IVA se administra en Gestión de activos y reparaciones → Configuración de IVA. El valor inicial es 15 % y requiere el permiso de administración del valor de puntos (POINT_VALUE).
- El IVA se calcula en el servidor sobre la base imponible: subtotal menos descuento más transporte gravado. El formulario muestra una previsualización inmediata, pero el cálculo definitivo siempre se valida en el servidor.
- Cada cotización guarda la tasa aplicada (iva_rate_snapshot) y el importe del IVA; los cambios futuros de la configuración no modifican cotizaciones históricas.
- El detalle económico presenta subtotal, descuento, transporte, IVA y total.

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
- El único cambio automático de estado realizado por HH es la recepción: al asociar el activo a un ticket cuyo origen sea **FormularioReparacion**, pasa a **REPARACIÓN Sistemas**.
- El envío a proveedor, el regreso a Songa y cualquier cambio posterior de estado se realizan manualmente en GLPI, fuera de la automatización de HH.
- HH registra el cambio automático de recepción en `glpi_plugin_hh_asset_history` y activa el historial nativo de GLPI.
- El dashboard añade indicadores globales de **REPARACIÓN Sistemas**, **REPARACIÓN Proveedor**, **LIBRE** (backup), **DAÑADO** y **Asignados**. Los cinco indicadores se calculan sobre el inventario completo visible para el usuario, independientemente de los filtros de HH.
- La migración agrega únicamente tablas propias del plugin y conserva las cotizaciones, puntos, catálogos y datos históricos existentes.

### Configuración y prueba

1. En GLPI, cree o identifique el origen de solicitud **FormularioReparacion** en **Configuración > Desplegables > Origen de la solicitud**.
2. En el formulario del catálogo de servicios, configure **FormularioReparacion** como origen de la solicitud del ticket creado.
3. En **Plugins > Gestión de activos y reparaciones > Configurar flujo**, seleccione **FormularioReparacion** y active el cambio automático.
4. Compruebe que existan los estados exactos **REPARACIÓN Sistemas**, **REPARACIÓN Proveedor** y **LIBRE**. Para los indicadores también se reconoce **DAÑADO**.
5. Cree un ticket desde el formulario, seleccione un activo y verifique que su estado cambie antes de finalizar la respuesta de creación del ticket.
6. Cree un ticket ordinario con otra categoría y confirme que el estado del activo no cambie.
7. Compruebe que HH no cambie el estado automáticamente al editar o resolver el ticket ni al modificar manualmente el activo. El envío y regreso del proveedor se gestionan fuera de esta automatización.

La versión 0.5.18 ajusta la identificación del formulario de recepción para utilizar el origen de solicitud `FormularioReparacion` en lugar de una categoría ITIL. El flujo general debe validarse en una instancia de pruebas antes de producción. No ejecutar pruebas destructivas ni cambiar estados de activos productivos.


## 0.5.26
- Homologa la cabecera de Gestión de reparaciones para activos nativos y Asset Definitions, mostrando datos de compra, garantía, contrato y seguimiento cuando GLPI dispone de ellos.
- Añade las pestañas Cotizaciones en borrador e Historial para las cotizaciones generales de activos no HH.
- Exige seleccionar un proveedor previamente registrado en GLPI; elimina la captura manual de nombres de proveedor.


## Correcciones del flujo de recepción (0.5.29)

- Corrige el hook de GLPI 11 para escuchar la clase de relación real `Item_Ticket` (tabla `glpi_items_tickets`); antes estaba registrado con el nombre incorrecto `Ticket_Item`, por lo que no se ejecutaba al asociar el activo al ticket.
- Al confirmar el regreso del proveedor, el activo pasa directamente de **REPARACIÓN Proveedor** a **LIBRE**.
- La configuración valida que exista también el estado **LIBRE**, necesario para completar el retorno.


## Ajuste de alcance del flujo (0.5.30)

- HH automatiza exclusivamente el cambio a **REPARACIÓN Sistemas** cuando se crea un ticket desde el origen configurado y queda asociado el activo.
- Se retiraron de la ficha del activo los botones HH para enviar a proveedor, registrar el regreso y finalizar reparación. Esos cambios se gestionan manualmente en GLPI.
- El panel HH conserva la consulta del historial de recepción automática; no ejecuta transiciones posteriores.


## Política de alertas y renovación (0.5.31)

- La configuración de criterios permite ajustar los días para detectar una reparación recurrente (60 por defecto), el porcentaje del costo cotizado respecto del valor de compra (40 % por defecto) y la antigüedad para revisar renovación (5 años por defecto).
- Las alertas de reparación recurrente se muestran al preparar una nueva cotización, utilizando la última fecha de retorno confirmada en cotizaciones aprobadas y/o en los campos de recepción del ticket (Fields, contenedor de reparación).
- El porcentaje de costo se calcula en tiempo real en cotizaciones generales para cualquier clase de activo con valor de compra registrado en GLPI. En HH se mantiene el cálculo basado en el valor de los puntos.
- Los umbrales son avisos consultivos; no bloquean cotizaciones, no cambian estados de activos y no ejecutan reemplazos.


## Condiciones para cotizar (0.5.32)

- Las cotizaciones generales de activos que no son HH solo se pueden crear o editar como borrador cuando el activo está en **REPARACIÓN Proveedor** y existe un ticket asociado con **número de guía de salida** y **fecha de envío al proveedor**.
- Las cotizaciones HH requieren que el activo esté en **REPARACIÓN Proveedor** y que el ticket asociado tenga **número de guía**, **fecha de envío al proveedor** y **fecha de recepción por el proveedor**.
- Las condiciones se validan tanto en la interfaz como en el servidor al guardar. La lista de activos disponibles para cotizar HH también se limita a los que cumplen los requisitos.
- Si el activo deja de cumplir las condiciones, no se puede crear ni editar un borrador. El historial de cotizaciones existentes se conserva.
