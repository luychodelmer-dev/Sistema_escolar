<?php
declare(strict_types=1);

/**
 * Migración para el nuevo Sistema de Permisos Granulares
 */
require_once __DIR__ . '/../app/bootstrap.php';

global $pdo;
if (!isset($pdo) || !$pdo instanceof PDO) {
    echo "ERROR: No hay conexión a la base de datos.\n";
    exit(1);
}

echo "Iniciando migración de permisos granulares...\n";

try {
    $pdo->beginTransaction();

    // 1. Agregar columna categoria/modulo a la tabla permisos si no existe
    $pdo->exec("
        ALTER TABLE permisos ADD COLUMN IF NOT EXISTS modulo VARCHAR(50) DEFAULT 'general';
        ALTER TABLE permisos ADD COLUMN IF NOT EXISTS icono VARCHAR(50) DEFAULT 'bi-shield-check';
        ALTER TABLE permisos ADD COLUMN IF NOT EXISTS orden INT DEFAULT 10;
    ");

    // 2. Insertar / Actualizar catálogo de permisos con módulos e iconos
    $permisosData = [
        // Módulo Estudiantes y Familias
        [
            'codigo' => 'p_estudiantes',
            'nombre' => 'Gestionar estudiantes',
            'descripcion' => 'Registrar alumnos, modificar datos personales y consultar expedientes 360°.',
            'modulo' => 'estudiantes',
            'icono' => 'bi-person-badge',
            'orden' => 1,
        ],
        [
            'codigo' => 'p_padres',
            'nombre' => 'Familias y apoderados',
            'descripcion' => 'Registrar padres/tutores, designar apoderado único y controlar tarjeta APAFA.',
            'modulo' => 'estudiantes',
            'icono' => 'bi-people',
            'orden' => 2,
        ],
        [
            'codigo' => 'p_editar',
            'nombre' => 'Editar responsables (Compatibilidad)',
            'descripcion' => 'Permiso heredado para crear y editar datos de padres y apoderados.',
            'modulo' => 'estudiantes',
            'icono' => 'bi-person-gear',
            'orden' => 3,
        ],

        // Módulo Matrículas y Aulas
        [
            'codigo' => 'p_matricular',
            'nombre' => 'Gestionar matrículas',
            'descripcion' => 'Formalizar matrícula escolar por año lectivo, asignar aula y emitir FUM/Nóminas.',
            'modulo' => 'matriculas',
            'icono' => 'bi-journal-check',
            'orden' => 4,
        ],
        [
            'codigo' => 'p_cursos_periodos',
            'nombre' => 'Cursos, periodos y aulas',
            'descripcion' => 'Configurar asignaturas escolares, periodos académicos y secciones.',
            'modulo' => 'matriculas',
            'icono' => 'bi-calendar3-range',
            'orden' => 5,
        ],

        // Módulo Evaluaciones y Calificaciones
        [
            'codigo' => 'p_calificar',
            'nombre' => 'Registrar calificaciones',
            'descripcion' => 'Ingresar y actualizar notas literales oficiales (AD, A, B, C) por periodo lectivo.',
            'modulo' => 'calificaciones',
            'icono' => 'bi-card-checklist',
            'orden' => 6,
        ],
        [
            'codigo' => 'p_cierre_academico',
            'nombre' => 'Cierre académico anual',
            'descripcion' => 'Gestionar promociones de grado, repitencias y clausura del año escolar.',
            'modulo' => 'calificaciones',
            'icono' => 'bi-award',
            'orden' => 7,
        ],

        // Módulo Caja y Finanzas
        [
            'codigo' => 'p_cobrar',
            'nombre' => 'Caja y cobranzas',
            'descripcion' => 'Registrar pagos, cuotas de APAFA, calcular saldos y emitir recibos oficiales.',
            'modulo' => 'pagos',
            'icono' => 'bi-cash-coin',
            'orden' => 8,
        ],
        [
            'codigo' => 'p_reportes_caja',
            'nombre' => 'Reportes y arqueo de caja',
            'descripcion' => 'Consultar balance consolidado de caja, liquidación diaria y reporte de deudas.',
            'modulo' => 'pagos',
            'icono' => 'bi-file-earmark-spreadsheet',
            'orden' => 9,
        ],

        // Módulo Auditoría y Seguridad
        [
            'codigo' => 'p_auditoria',
            'nombre' => 'Consultar auditoría',
            'descripcion' => 'Monitorear la bitácora de movimientos históricos y trazabilidad de seguridad.',
            'modulo' => 'auditoria',
            'icono' => 'bi-shield-shaded',
            'orden' => 10,
        ],
    ];

    $stmtUpsert = $pdo->prepare("
        INSERT INTO permisos (codigo, nombre, descripcion, modulo, icono, orden, activo)
        VALUES (:codigo, :nombre, :descripcion, :modulo, :icono, :orden, TRUE)
        ON CONFLICT (codigo) DO UPDATE
        SET nombre = EXCLUDED.nombre,
            descripcion = EXCLUDED.descripcion,
            modulo = EXCLUDED.modulo,
            icono = EXCLUDED.icono,
            orden = EXCLUDED.orden,
            activo = TRUE
    ");

    foreach ($permisosData as $p) {
        $stmtUpsert->execute($p);
    }

    // 3. Sincronizar permisos heredados para usuarios existentes
    // Si tenía p_matricular -> conceder p_estudiantes, p_cursos_periodos, p_calificar
    $pdo->exec("
        INSERT INTO usuario_permisos (usuario_id, permiso_id, otorgado_por, creado_en)
        SELECT up.usuario_id, nuevo_p.id, up.otorgado_por, CURRENT_TIMESTAMP
        FROM usuario_permisos up
        JOIN permisos p_ant ON p_ant.id = up.permiso_id
        CROSS JOIN permisos nuevo_p
        WHERE p_ant.codigo = 'p_matricular'
          AND nuevo_p.codigo IN ('p_estudiantes', 'p_cursos_periodos', 'p_calificar')
        ON CONFLICT (usuario_id, permiso_id) DO NOTHING;
    ");

    // Si tenía p_editar -> conceder p_padres
    $pdo->exec("
        INSERT INTO usuario_permisos (usuario_id, permiso_id, otorgado_por, creado_en)
        SELECT up.usuario_id, nuevo_p.id, up.otorgado_por, CURRENT_TIMESTAMP
        FROM usuario_permisos up
        JOIN permisos p_ant ON p_ant.id = up.permiso_id
        CROSS JOIN permisos nuevo_p
        WHERE p_ant.codigo = 'p_editar'
          AND nuevo_p.codigo = 'p_padres'
        ON CONFLICT (usuario_id, permiso_id) DO NOTHING;
    ");

    // Si tenía p_cobrar -> conceder p_reportes_caja
    $pdo->exec("
        INSERT INTO usuario_permisos (usuario_id, permiso_id, otorgado_por, creado_en)
        SELECT up.usuario_id, nuevo_p.id, up.otorgado_por, CURRENT_TIMESTAMP
        FROM usuario_permisos up
        JOIN permisos p_ant ON p_ant.id = up.permiso_id
        CROSS JOIN permisos nuevo_p
        WHERE p_ant.codigo = 'p_cobrar'
          AND nuevo_p.codigo = 'p_reportes_caja'
        ON CONFLICT (usuario_id, permiso_id) DO NOTHING;
    ");

    $pdo->commit();
    echo "¡Migración completada con éxito! Se registraron " . count($permisosData) . " permisos granulares.\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "ERROR en la migración: " . $e->getMessage() . "\n";
    exit(1);
}
