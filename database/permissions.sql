CREATE TABLE IF NOT EXISTS permiso_usuario (
    id SERIAL PRIMARY KEY,
    usuario_id INTEGER NOT NULL REFERENCES usuario(id) ON DELETE CASCADE,
    permiso VARCHAR(50) NOT NULL,
    otorgado BOOLEAN NOT NULL DEFAULT FALSE,
    otorgado_por INTEGER REFERENCES usuario(id) ON DELETE SET NULL,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (usuario_id, permiso)
);

CREATE TABLE IF NOT EXISTS permisos (
    id BIGSERIAL PRIMARY KEY,
    codigo VARCHAR(50) NOT NULL UNIQUE,
    nombre VARCHAR(100) NOT NULL,
    descripcion VARCHAR(255),
    modulo VARCHAR(50) DEFAULT 'general',
    icono VARCHAR(50) DEFAULT 'bi-shield-check',
    orden INT DEFAULT 10,
    activo BOOLEAN NOT NULL DEFAULT TRUE,
    creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS usuario_permisos (
    usuario_id BIGINT NOT NULL REFERENCES usuario(id) ON DELETE CASCADE,
    permiso_id BIGINT NOT NULL REFERENCES permisos(id) ON DELETE CASCADE,
    otorgado_por BIGINT REFERENCES usuario(id) ON DELETE SET NULL,
    creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (usuario_id, permiso_id)
);

INSERT INTO permisos (codigo, nombre, descripcion, modulo, icono, orden, activo) VALUES
    ('p_estudiantes', 'Gestionar estudiantes', 'Registrar alumnos, modificar datos personales y consultar expedientes 360°.', 'estudiantes', 'bi-person-badge', 1, TRUE),
    ('p_padres', 'Familias y apoderados', 'Registrar padres/tutores, designar apoderado único y controlar tarjeta APAFA.', 'estudiantes', 'bi-people', 2, TRUE),
    ('p_editar', 'Editar responsables (Compatibilidad)', 'Permiso heredado para crear y editar datos de padres y apoderados.', 'estudiantes', 'bi-person-gear', 3, TRUE),
    ('p_matricular', 'Gestionar matrículas', 'Formalizar matrícula escolar por año lectivo, asignar aula y emitir FUM/Nóminas.', 'matriculas', 'bi-journal-check', 4, TRUE),
    ('p_cursos_periodos', 'Cursos, periodos y aulas', 'Configurar asignaturas escolares, periodos académicos y secciones.', 'matriculas', 'bi-calendar3-range', 5, TRUE),
    ('p_calificar', 'Registrar calificaciones', 'Ingresar y actualizar notas literales oficiales (AD, A, B, C) por periodo lectivo.', 'calificaciones', 'bi-card-checklist', 6, TRUE),
    ('p_cierre_academico', 'Cierre académico anual', 'Gestionar promociones de grado, repitencias y clausura del año escolar.', 'calificaciones', 'bi-award', 7, TRUE),
    ('p_cobrar', 'Caja y cobranzas', 'Registrar pagos, cuotas de APAFA, calcular saldos y emitir recibos oficiales.', 'pagos', 'bi-cash-coin', 8, TRUE),
    ('p_reportes_caja', 'Reportes y arqueo de caja', 'Consultar balance consolidado de caja, liquidación diaria y reporte de deudas.', 'pagos', 'bi-file-earmark-spreadsheet', 9, TRUE),
    ('p_auditoria', 'Consultar auditoría', 'Monitorear la bitácora de movimientos históricos y trazabilidad de seguridad.', 'auditoria', 'bi-shield-shaded', 10, TRUE)
ON CONFLICT (codigo) DO UPDATE
SET nombre = EXCLUDED.nombre,
    descripcion = EXCLUDED.descripcion,
    modulo = EXCLUDED.modulo,
    icono = EXCLUDED.icono,
    orden = EXCLUDED.orden,
    activo = TRUE;

INSERT INTO usuario_permisos (usuario_id, permiso_id, otorgado_por, creado_en)
SELECT asignacion.usuario_id, permiso.id, asignacion.otorgado_por, asignacion.creado_en
FROM permiso_usuario AS asignacion
JOIN permisos AS permiso ON permiso.codigo = asignacion.permiso
WHERE asignacion.otorgado = TRUE
ON CONFLICT (usuario_id, permiso_id) DO NOTHING;
