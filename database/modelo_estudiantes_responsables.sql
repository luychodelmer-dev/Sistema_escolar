-- Modelo familiar para estudiantes, padres y apoderados.
-- Se relaciona con la tabla existente estudiante(id).

CREATE TABLE IF NOT EXISTS padre (
    id SERIAL PRIMARY KEY,
    dni VARCHAR(8) NOT NULL UNIQUE,
    nombres VARCHAR(50) NOT NULL,
    apellido_paterno VARCHAR(50) NOT NULL,
    apellido_materno VARCHAR(50),
    direccion TEXT,
    celular VARCHAR(20),
    correo VARCHAR(150),
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS estudiante_padre (
    id SERIAL PRIMARY KEY,
    estudiante_id INTEGER NOT NULL REFERENCES estudiante(id) ON DELETE CASCADE,
    padre_id INTEGER NOT NULL REFERENCES padre(id) ON DELETE CASCADE,
    parentesco VARCHAR(60) NOT NULL,
    tipo VARCHAR(20) NOT NULL CHECK (tipo IN ('padre', 'madre', 'otro')),
    es_apoderado BOOLEAN NOT NULL DEFAULT FALSE,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (estudiante_id, padre_id)
);

CREATE INDEX IF NOT EXISTS estudiante_padre_estudiante_tipo_idx
    ON estudiante_padre (estudiante_id, tipo);

-- Garantiza que cada estudiante tenga como máximo un apoderado.
CREATE UNIQUE INDEX IF NOT EXISTS estudiante_unico_padre_idx
    ON estudiante_padre (estudiante_id)
    WHERE tipo = 'padre';

CREATE UNIQUE INDEX IF NOT EXISTS estudiante_unica_madre_idx
    ON estudiante_padre (estudiante_id)
    WHERE tipo = 'madre';

-- Garantiza que cada estudiante tenga como máximo un apoderado.
CREATE UNIQUE INDEX IF NOT EXISTS estudiante_unico_apoderado_idx
    ON estudiante_padre (estudiante_id)
    WHERE es_apoderado = TRUE;



