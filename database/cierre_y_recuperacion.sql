-- Migración: Soporte para Cierre Académico y Recuperación Pedagógica (Vacacional)
BEGIN;

-- 1. Actualizar el check constraint de estado_academico en estudiante para incluir 'Recuperación'
ALTER TABLE estudiante DROP CONSTRAINT IF EXISTS estudiante_estado_academico_check;
ALTER TABLE estudiante
    ADD CONSTRAINT estudiante_estado_academico_check
    CHECK (estado_academico IN ('Activo', 'Retirado', 'Trasladado', 'Promoción', 'Promovido', 'Repitente', 'Recuperación'));

-- 2. Tabla para registrar el Programa de Recuperación Pedagógica (Vacacional / Enero-Febrero)
CREATE TABLE IF NOT EXISTS recuperacion_academica (
    id BIGSERIAL PRIMARY KEY,
    matricula_id BIGINT NOT NULL REFERENCES matricula(id) ON DELETE CASCADE,
    curso_id BIGINT NOT NULL REFERENCES curso(id) ON DELETE RESTRICT,
    nota_final_regular VARCHAR(10) NOT NULL, -- Nota con la que fue a recuperación (ej: C o B)
    nota_recuperacion VARCHAR(10) CHECK (nota_recuperacion IN ('AD', 'A', 'B', 'C')), -- Nota obtenida en vacacional
    estado VARCHAR(20) NOT NULL DEFAULT 'Pendiente' CHECK (estado IN ('Pendiente', 'Aprobado', 'Desaprobado')),
    observacion VARCHAR(250),
    evaluado_por BIGINT REFERENCES usuario(id) ON DELETE SET NULL,
    creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (matricula_id, curso_id)
);

CREATE INDEX IF NOT EXISTS idx_recuperacion_matricula ON recuperacion_academica(matricula_id);
CREATE INDEX IF NOT EXISTS idx_recuperacion_estado ON recuperacion_academica(estado);

-- 3. Tabla para registrar Actas de Cierre Anual por Aula
CREATE TABLE IF NOT EXISTS cierre_anual (
    id BIGSERIAL PRIMARY KEY,
    anio_id BIGINT NOT NULL REFERENCES anio(id) ON DELETE RESTRICT,
    grado_seccion_id BIGINT NOT NULL REFERENCES grado_seccion(id) ON DELETE RESTRICT,
    fecha_cierre TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    cerrado_por BIGINT REFERENCES usuario(id) ON DELETE SET NULL,
    total_evaluados INT NOT NULL DEFAULT 0,
    total_promovidos INT NOT NULL DEFAULT 0,
    total_recuperacion INT NOT NULL DEFAULT 0,
    total_repitentes INT NOT NULL DEFAULT 0,
    total_promocion INT NOT NULL DEFAULT 0,
    observaciones TEXT,
    UNIQUE (anio_id, grado_seccion_id)
);

COMMIT;
