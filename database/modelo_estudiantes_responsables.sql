-- Modelo familiar para alumnos, padres y apoderados.
-- Se relaciona con la tabla existente alumnos(id).

CREATE TABLE IF NOT EXISTS responsables (
    id SERIAL PRIMARY KEY,
    dni VARCHAR(8) NOT NULL UNIQUE,
    nombres VARCHAR(100) NOT NULL,
    apellido_paterno VARCHAR(100) NOT NULL,
    apellido_materno VARCHAR(100),
    direccion TEXT,
    celular VARCHAR(20),
    correo VARCHAR(150),
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS alumno_responsable (
    id SERIAL PRIMARY KEY,
    alumno_id INTEGER NOT NULL REFERENCES alumnos(id) ON DELETE CASCADE,
    responsable_id INTEGER NOT NULL REFERENCES responsables(id) ON DELETE CASCADE,
    parentesco VARCHAR(60) NOT NULL,
    tipo VARCHAR(20) NOT NULL CHECK (tipo IN ('padre', 'madre', 'otro')),
    es_apoderado BOOLEAN NOT NULL DEFAULT FALSE,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (alumno_id, responsable_id)
);

CREATE INDEX IF NOT EXISTS alumno_responsable_alumno_tipo_idx
    ON alumno_responsable (alumno_id, tipo);

-- Garantiza que cada alumno tenga como máximo un apoderado.
CREATE UNIQUE INDEX IF NOT EXISTS alumno_unico_padre_idx
    ON alumno_responsable (alumno_id)
    WHERE tipo = 'padre';

CREATE UNIQUE INDEX IF NOT EXISTS alumno_unica_madre_idx
    ON alumno_responsable (alumno_id)
    WHERE tipo = 'madre';

-- Garantiza que cada alumno tenga como máximo un apoderado.
CREATE UNIQUE INDEX IF NOT EXISTS alumno_unico_apoderado_idx
    ON alumno_responsable (alumno_id)
    WHERE es_apoderado = TRUE;
