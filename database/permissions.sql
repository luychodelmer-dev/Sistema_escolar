CREATE TABLE IF NOT EXISTS permisos_usuario (
    id SERIAL PRIMARY KEY,
    usuario_id INTEGER NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE,
    permiso VARCHAR(50) NOT NULL,
    otorgado BOOLEAN NOT NULL DEFAULT FALSE,
    otorgado_por INTEGER REFERENCES usuarios(id) ON DELETE SET NULL,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (usuario_id, permiso)
);

INSERT INTO permisos_usuario (usuario_id, permiso, otorgado, otorgado_por)
SELECT id, permiso, FALSE, (SELECT id FROM usuarios WHERE rol = 'director' ORDER BY id LIMIT 1)
FROM usuarios
CROSS JOIN (VALUES ('p_editar'), ('p_matricular'), ('p_cobrar')) AS permisos(permiso)
ON CONFLICT (usuario_id, permiso) DO NOTHING;
