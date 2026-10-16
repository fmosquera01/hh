# Gestión de reparaciones — Plugin para GLPI 11

HH es un **plugin nativo de GLPI 11**. La experiencia se trabaja dentro de la pestaña del activo, sin iniciar otra sesión ni buscar nuevamente el equipo.

## Integración con activos

- Añade **Gestión de reparaciones** a las fichas de **Computadoras** y **Teléfonos**.
- Recibe automáticamente tipo e ID del activo desde GLPI.
- Muestra datos de contexto del activo disponibles en la ficha.
- No modifica tablas nativas de GLPI.

## Estado actual — versión 0.3.0

La pestaña contiene una **maqueta visual integral** para validar la experiencia antes de conectar datos y persistencia:

- Resumen del activo y tarjetas de indicadores.
- Listado vacío de cotizaciones.
- Formulario visual para una cotización y sus detalles.
- Sección de lista maestra de repuestos, piezas y trabajos.
- Vista de parámetros para el valor por punto.
- Indicadores para reparación repetida en 60 días y umbral del 40% del valor de compra.
- Flujo visual de aprobación y sección de historial.

**Importante:** esta etapa es únicamente visual. Los controles de edición y guardado están deshabilitados y no se guardan cotizaciones ni ítems. No se muestran registros ficticios como si fueran reales. La lectura ampliada del inventario y las tablas definitivas se implementarán después de validar el diseño.

## Estructura

- `setup.php`: metadatos, compatibilidad GLPI 11 y registro de pestaña.
- `hook.php`: tablas iniciales del plugin creadas mediante Migration.
- `src/Dashboard.php`: entrada del plugin en el menú.
- `src/RepairTab.php`: experiencia visual dentro de la ficha de activo.
- `front/index.php`: pantalla de entrada del plugin.

Las tablas existentes con prefijo `glpi_plugin_hh_` se conservan. En la próxima etapa se revisará el modelo de datos antes de agregar tablas para el catálogo maestro y las cotizaciones.

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
