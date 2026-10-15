-- Esquema inicial de HH. Ejecutar en la base de datos independiente de la aplicación.
-- No crea ni modifica tablas en la base de datos de GLPI.

CREATE TABLE IF NOT EXISTS hh_valores_punto (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    valor_por_punto DECIMAL(12,4) NOT NULL,
    moneda CHAR(3) NOT NULL DEFAULT 'USD',
    vigente_desde DATETIME NOT NULL,
    vigente_hasta DATETIME NULL,
    motivo VARCHAR(255) NULL,
    creado_por VARCHAR(128) NOT NULL,
    creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_hh_valor_punto_inicio (vigente_desde),
    KEY ix_hh_valor_punto_vigencia (vigente_desde, vigente_hasta),
    CONSTRAINT chk_hh_valor_punto_positivo CHECK (valor_por_punto > 0),
    CONSTRAINT chk_hh_valor_punto_fechas CHECK (vigente_hasta IS NULL OR vigente_hasta > vigente_desde)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hh_cotizaciones (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    numero VARCHAR(40) NOT NULL,
    equipo_glpi_id BIGINT UNSIGNED NULL COMMENT 'Identificador externo; no implica consulta a GLPI.',
    estado ENUM('borrador','pendiente','aprobada','rechazada') NOT NULL DEFAULT 'borrador',
    total_puntos DECIMAL(12,2) NOT NULL DEFAULT 0,
    valor_punto_aplicado DECIMAL(12,4) NOT NULL,
    moneda CHAR(3) NOT NULL DEFAULT 'USD',
    total_monetario DECIMAL(14,2) NOT NULL DEFAULT 0,
    creado_por VARCHAR(128) NOT NULL,
    creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_hh_cotizaciones_numero (numero),
    KEY ix_hh_cotizaciones_equipo (equipo_glpi_id),
    KEY ix_hh_cotizaciones_estado (estado),
    CONSTRAINT chk_hh_cotizaciones_puntos CHECK (total_puntos >= 0),
    CONSTRAINT chk_hh_cotizaciones_valor CHECK (valor_punto_aplicado > 0),
    CONSTRAINT chk_hh_cotizaciones_total CHECK (total_monetario >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hh_cotizacion_detalles (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    cotizacion_id BIGINT UNSIGNED NOT NULL,
    descripcion_snapshot VARCHAR(255) NOT NULL,
    cantidad DECIMAL(10,2) NOT NULL DEFAULT 1,
    puntos_unitarios DECIMAL(12,2) NOT NULL,
    subtotal_puntos DECIMAL(12,2) NOT NULL,
    valor_punto_aplicado DECIMAL(12,4) NOT NULL,
    subtotal_monetario DECIMAL(14,2) NOT NULL,
    creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_hh_detalles_cotizacion (cotizacion_id),
    CONSTRAINT fk_hh_detalles_cotizacion FOREIGN KEY (cotizacion_id)
        REFERENCES hh_cotizaciones (id) ON DELETE RESTRICT,
    CONSTRAINT chk_hh_detalle_cantidad CHECK (cantidad > 0),
    CONSTRAINT chk_hh_detalle_puntos CHECK (puntos_unitarios >= 0 AND subtotal_puntos >= 0),
    CONSTRAINT chk_hh_detalle_valor CHECK (valor_punto_aplicado > 0),
    CONSTRAINT chk_hh_detalle_total CHECK (subtotal_monetario >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- No se inserta un valor inicial: el responsable deberá definir el valor real del punto.
-- Al cambiarlo, la siguiente etapa cerrará la vigencia actual y creará una nueva fila.
-- Las cotizaciones guardan valor_punto_aplicado y total_monetario como snapshots inmutables.
