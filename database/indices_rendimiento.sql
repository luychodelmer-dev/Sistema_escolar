-- Índices de rendimiento para consultas frecuentes en PostgreSQL / Neon
CREATE INDEX IF NOT EXISTS idx_usuario_correo_lower ON usuario (LOWER(TRIM(correo)));
CREATE INDEX IF NOT EXISTS idx_matricula_estado ON matricula (estado);
CREATE INDEX IF NOT EXISTS idx_matricula_estudiante_id ON matricula (estudiante_id);
CREATE INDEX IF NOT EXISTS idx_pago_fecha_id ON pago (fecha_pago DESC, id DESC);
CREATE INDEX IF NOT EXISTS idx_pago_matricula_id ON pago (matricula_id);
CREATE INDEX IF NOT EXISTS idx_cuenta_pago_saldo_pos ON cuenta_pago (id) WHERE saldo_restante > 0;
CREATE INDEX IF NOT EXISTS idx_cuenta_pago_responsable_id ON cuenta_pago (responsable_id);
CREATE INDEX IF NOT EXISTS idx_estudiante_responsable_resp_id ON estudiante_responsable (responsable_id);
CREATE INDEX IF NOT EXISTS idx_auditoria_creado_en ON auditoria_movimiento (creado_en DESC, id DESC);
