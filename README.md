# Gestión de reparaciones — Plugin para GLPI 11

HH es un **plugin nativo de GLPI 11**. La experiencia se trabaja dentro de la pestaña del activo, sin iniciar otra sesión ni buscar nuevamente el equipo.

## Integración con activos

- Añade **Gestión de reparaciones** a las fichas de **Computadoras** y **Teléfonos**.
- Recibe automáticamente tipo e ID del activo desde GLPI.
- Muestra datos de contexto del activo disponibles en la ficha.
- No modifica tablas nativas de GLPI.

## Estado actual — versión 0.3.1

La pestaña nativa consulta en modo lectura los datos de activos, compra, garantía y contratos asociados para **Computadoras** y **Teléfonos**. El tipo del contrato vigente es el valor que se muestra en **Tipo / información de garantía** y prevalece sobre la garantía propia del equipo para determinar la cobertura.

Reglas de cobertura:
- **Platinum:** cubre todo tipo de daño; objetivo de atención de 2 días.
- **Gold:** excluye la placa principal; objetivo de atención de 5 días.
- **Inveligent:** cobertura mediante puntos.
- Sin contrato vigente, se evalúa la garantía propia del equipo registrada en GLPI; si tampoco está vigente, se muestra sin cobertura.

La versión 0.3.1 mantiene la consulta en modo lectura. No crea ni modifica datos nativos de GLPI y todavía no habilita el guardado de cotizaciones, puntos ni historial.


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
