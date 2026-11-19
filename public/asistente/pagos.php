<?php
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/header.php';

require_auth(['asistente', 'director', 'hiperadmin', 'administrador']);

$pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS pago (
    id SERIAL PRIMARY KEY,
    responsable VARCHAR(150) NOT NULL,
    concepto VARCHAR(80) NOT NULL,
    monto NUMERIC(10, 2) NOT NULL,
    fecha_pago DATE NOT NULL,
    estado VARCHAR(20) NOT NULL DEFAULT 'Pagado',
    observacion VARCHAR(250),
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)
SQL);
$pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS concepto_pago (
    id BIGSERIAL PRIMARY KEY,
    descripcion VARCHAR(80) NOT NULL UNIQUE,
    activo BOOLEAN NOT NULL DEFAULT TRUE,
    monto_default NUMERIC(10, 2) CHECK (monto_default >= 0),
    monto_total NUMERIC(10, 2) CHECK (monto_total >= 0),
    tipo_cobro VARCHAR(20) NOT NULL DEFAULT 'estudiante'
        CHECK (tipo_cobro IN ('estudiante', 'familia')),
    creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
)
SQL);
$pdo->exec('ALTER TABLE concepto_pago ADD COLUMN IF NOT EXISTS monto_total NUMERIC(10, 2)');
$pdo->exec('ALTER TABLE concepto_pago ADD COLUMN IF NOT EXISTS monto_default NUMERIC(10, 2)');
$pdo->exec("ALTER TABLE concepto_pago ADD COLUMN IF NOT EXISTS tipo_cobro VARCHAR(20) NOT NULL DEFAULT 'estudiante'");
$pdo->exec(<<<'SQL'
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'concepto_pago_tipo_cobro_check') THEN
        ALTER TABLE concepto_pago
            ADD CONSTRAINT concepto_pago_tipo_cobro_check
            CHECK (tipo_cobro IN ('estudiante', 'familia'));
    END IF;
END $$
SQL);
$pdo->exec('UPDATE concepto_pago SET monto_default = monto_total WHERE monto_default IS NULL AND monto_total IS NOT NULL');
$pdo->exec('ALTER TABLE pago ADD COLUMN IF NOT EXISTS responsable_id BIGINT REFERENCES responsable(id) ON DELETE SET NULL');
$pdo->exec('ALTER TABLE pago ADD COLUMN IF NOT EXISTS concepto_id BIGINT REFERENCES concepto_pago(id) ON DELETE SET NULL');
$pdo->exec('ALTER TABLE pago ADD COLUMN IF NOT EXISTS matricula_id BIGINT REFERENCES matricula(id) ON DELETE RESTRICT');
$pdo->exec('ALTER TABLE pago ADD COLUMN IF NOT EXISTS monto_total NUMERIC(10, 2)');
$pdo->exec('ALTER TABLE pago ADD COLUMN IF NOT EXISTS monto_pagado NUMERIC(10, 2) NOT NULL DEFAULT 0');
$pdo->exec('ALTER TABLE pago ADD COLUMN IF NOT EXISTS saldo_restante NUMERIC(10, 2) NOT NULL DEFAULT 0');
$pdo->exec('ALTER TABLE pago ADD COLUMN IF NOT EXISTS abono_hoy NUMERIC(10, 2) NOT NULL DEFAULT 0');
$pdo->exec('ALTER TABLE pago ADD COLUMN IF NOT EXISTS entregado_por VARCHAR(150)');
$pdo->exec('UPDATE pago SET monto_total = monto, monto_pagado = monto, saldo_restante = 0 WHERE monto_total IS NULL AND monto_pagado = 0');
$pdo->exec('UPDATE pago SET abono_hoy = monto WHERE abono_hoy = 0 AND monto > 0');
$pdo->exec('ALTER TABLE matricula ADD COLUMN IF NOT EXISTS apoderado_principal_id BIGINT');
$pdo->exec(<<<'SQL'
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'matricula_apoderado_principal_id_fkey') THEN
        ALTER TABLE matricula ADD CONSTRAINT matricula_apoderado_principal_id_fkey
            FOREIGN KEY (apoderado_principal_id) REFERENCES responsable(id) ON DELETE SET NULL;
    END IF;
END $$
SQL);
$pdo->exec(<<<'SQL'
UPDATE matricula m
SET apoderado_principal_id = (
    SELECT ar.responsable_id FROM estudiante_responsable ar
    WHERE ar.estudiante_id = m.estudiante_id
    ORDER BY ar.es_apoderado DESC,
             CASE ar.tipo WHEN 'padre' THEN 1 WHEN 'madre' THEN 2 ELSE 3 END,
             ar.responsable_id
    LIMIT 1
)
WHERE m.apoderado_principal_id IS NULL AND m.estudiante_id IS NOT NULL
SQL);
$pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS cuenta_pago (
    id BIGSERIAL PRIMARY KEY,
    matricula_id BIGINT REFERENCES matricula(id) ON DELETE CASCADE,
    responsable_id BIGINT REFERENCES responsable(id) ON DELETE SET NULL,
    concepto_id BIGINT NOT NULL REFERENCES concepto_pago(id) ON DELETE RESTRICT,
    monto_total NUMERIC(10, 2) NOT NULL CHECK (monto_total > 0),
    monto_pagado NUMERIC(10, 2) NOT NULL DEFAULT 0 CHECK (monto_pagado >= 0),
    saldo_restante NUMERIC(10, 2) NOT NULL CHECK (saldo_restante >= 0),
    actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
)
SQL);
$pdo->exec('ALTER TABLE cuenta_pago ADD COLUMN IF NOT EXISTS matricula_id BIGINT REFERENCES matricula(id) ON DELETE CASCADE');
$pdo->exec('ALTER TABLE cuenta_pago ALTER COLUMN responsable_id DROP NOT NULL');
$pdo->exec('ALTER TABLE cuenta_pago DROP CONSTRAINT IF EXISTS cuenta_pago_responsable_id_concepto_id_key');
$pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS cuenta_pago_matricula_concepto_unique ON cuenta_pago (matricula_id, concepto_id) WHERE matricula_id IS NOT NULL');
$pdo->exec('ALTER TABLE pago ADD COLUMN IF NOT EXISTS cuenta_pago_id BIGINT REFERENCES cuenta_pago(id) ON DELETE SET NULL');

$pdo->exec("UPDATE concepto_pago SET activo = FALSE WHERE LOWER(descripcion) = 'qaliwarma'");

$error = '';
$mensaje = '';
$busqueda = trim($_GET['buscar'] ?? '');
$puedeCobrar = has_permission('p_cobrar');
$esDirector = is_director();
$tokenCsrf = $_SESSION['csrf_token'] ??= bin2hex(random_bytes(32));
$conceptos = $pdo->query('SELECT id, descripcion, monto_default, tipo_cobro FROM concepto_pago WHERE activo = TRUE ORDER BY descripcion')->fetchAll();
$conceptosAdmin = $pdo->query('SELECT id, descripcion, monto_default, tipo_cobro, activo FROM concepto_pago ORDER BY activo DESC, descripcion')->fetchAll();

if (isset($_GET['ajax']) && $_GET['ajax'] !== 'tabla') {
    header('Content-Type: application/json; charset=utf-8');
    $recordId = filter_var($_GET['id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($recordId === false) {
        http_response_code(400);
        exit('{}');
    }

    if ($_GET['ajax'] === 'apoderado') {
        $principalStmt = $pdo->prepare(
            "SELECT r.id, r.dni,
                    CONCAT_WS(' ', r.nombres, r.apellido_paterno, r.apellido_materno) AS nombre
             FROM matricula m
             LEFT JOIN LATERAL (
                 SELECT ar.responsable_id
                 FROM estudiante_responsable ar
                 WHERE ar.estudiante_id = m.estudiante_id
                 ORDER BY ar.es_apoderado DESC,
                          CASE ar.tipo WHEN 'padre' THEN 1 WHEN 'madre' THEN 2 ELSE 3 END
                 LIMIT 1
             ) fallback ON TRUE
             JOIN responsable r ON r.id = COALESCE(m.apoderado_principal_id, fallback.responsable_id)
             WHERE m.id = :id AND m.estado = 'Activa'"
        );
        $principalStmt->execute(['id' => $recordId]);
        $principal = $principalStmt->fetch();
        if (!$principal) {
            http_response_code(404);
            exit(json_encode(['error' => 'La matrícula no tiene apoderado principal.']));
        }
        echo json_encode($principal, JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($_GET['ajax'] === 'concepto') {
        $conceptoStmt = $pdo->prepare('SELECT id, monto_default FROM concepto_pago WHERE id = :id AND activo = TRUE');
        $conceptoStmt->execute(['id' => $recordId]);
        $concepto = $conceptoStmt->fetch();
        if (!$concepto || $concepto['monto_default'] === null) {
            http_response_code(422);
            exit(json_encode(['error' => 'Este concepto requiere una tarifa configurada.']));
        }
        echo json_encode($concepto);
        exit;
    }

    if ($_GET['ajax'] === 'saldo') {
        $conceptoId = filter_var($_GET['concepto_id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($conceptoId === false) {
            http_response_code(400);
            exit('{}');
        }
        $accountStmt = $pdo->prepare(
                'SELECT concepto.monto_default, cuenta.id AS cuenta_id,
                    cuenta.monto_total, cuenta.monto_pagado, cuenta.saldo_restante,
                    (cuenta.id IS NOT NULL AND cuenta.saldo_restante > 0) AS deuda_pendiente
             FROM concepto_pago AS concepto
             LEFT JOIN cuenta_pago AS cuenta
               ON cuenta.concepto_id = concepto.id AND cuenta.matricula_id = :matricula_id
             WHERE concepto.id = :concepto_id AND concepto.activo = TRUE'
        );
        $accountStmt->execute(['matricula_id' => $recordId, 'concepto_id' => $conceptoId]);
        $account = $accountStmt->fetch();
        if (!$account || $account['monto_default'] === null) {
            http_response_code(422);
            exit(json_encode(['error' => 'Este concepto no tiene una tarifa configurada.']));
        }
        $accountExists = $account['cuenta_id'] !== null;
        $deudaPendiente = $account['deuda_pendiente'] === true || $account['deuda_pendiente'] === 't' || $account['deuda_pendiente'] === 1 || $account['deuda_pendiente'] === '1';
        $balance = $accountExists ? (float) $account['saldo_restante'] : (float) $account['monto_default'];
        echo json_encode([
            'monto_total' => $account['monto_total'] ?? $account['monto_default'],
            'monto_pagado' => $account['monto_pagado'] ?? 0,
            'saldo_restante' => $balance,
            'cuenta_existente' => $accountExists,
            'deuda_pendiente' => $deudaPendiente,
        ]);
        exit;
    }

    http_response_code(400);
    exit('{}');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = $_POST['csrf_token'] ?? '';
    if (!is_string($submittedToken) || !hash_equals($tokenCsrf, $submittedToken)) {
        $error = 'Solicitud inválida o sesión expirada. Recarga la página e inténtalo nuevamente.';
    } else {
        $action = $_POST['action'] ?? '';
        if ($action === 'eliminar_pago') {
            if (!$esDirector) {
                http_response_code(403);
                exit('No tienes permiso para eliminar pagos. Acción exclusiva del usuario principal (Director).');
            }

            $pagoId = filter_var($_POST['pago_id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($pagoId === false) {
                $error = 'Selecciona un pago válido para eliminar.';
            } else {
                try {
                    $pdo->beginTransaction();

                    $pagoStmt = $pdo->prepare('SELECT * FROM pago WHERE id = :id FOR UPDATE');
                    $pagoStmt->execute(['id' => $pagoId]);
                    $pago = $pagoStmt->fetch();

                    if (!$pago) {
                        $pdo->rollBack();
                        $error = 'No se encontró el registro de pago o ya fue eliminado.';
                    } else {
                        $estudianteNombre = 'Sin estudiante';
                        if (!empty($pago['matricula_id'])) {
                            $studentStmt = $pdo->prepare(
                                "SELECT CONCAT_WS(' ', a.nombres, a.apellido_paterno, a.apellido_materno)
                                 FROM matricula m
                                 JOIN estudiante a ON a.id = m.estudiante_id
                                 WHERE m.id = :matricula_id"
                            );
                            $studentStmt->execute(['matricula_id' => $pago['matricula_id']]);
                            $estudianteNombre = $studentStmt->fetchColumn() ?: 'Sin estudiante';
                        }

                        $cuentaId = $pago['cuenta_pago_id'] ?? null;
                        if (!$cuentaId && !empty($pago['matricula_id']) && !empty($pago['concepto_id'])) {
                            $findCuenta = $pdo->prepare(
                                'SELECT id FROM cuenta_pago WHERE matricula_id = :matricula_id AND concepto_id = :concepto_id LIMIT 1'
                            );
                            $findCuenta->execute([
                                'matricula_id' => $pago['matricula_id'],
                                'concepto_id' => $pago['concepto_id'],
                            ]);
                            $cuentaId = $findCuenta->fetchColumn() ?: null;
                        }

                        if ($cuentaId) {
                            $cuentaStmt = $pdo->prepare('SELECT * FROM cuenta_pago WHERE id = :id FOR UPDATE');
                            $cuentaStmt->execute(['id' => $cuentaId]);
                            $cuenta = $cuentaStmt->fetch();

                            if ($cuenta) {
                                $otrosPagosStmt = $pdo->prepare(
                                    'SELECT id, COALESCE(abono_hoy, monto) AS abono
                                     FROM pago
                                     WHERE cuenta_pago_id = :cuenta_id AND id <> :pago_id'
                                );
                                $otrosPagosStmt->execute([
                                    'cuenta_id' => $cuentaId,
                                    'pago_id' => $pagoId,
                                ]);
                                $otrosPagos = $otrosPagosStmt->fetchAll();

                                if (count($otrosPagos) === 0) {
                                    $delPago = $pdo->prepare('DELETE FROM pago WHERE id = :id');
                                    $delPago->execute(['id' => $pagoId]);

                                    try {
                                        $delCuenta = $pdo->prepare('DELETE FROM cuenta_pago WHERE id = :id');
                                        $delCuenta->execute(['id' => $cuentaId]);
                                    } catch (Throwable $ignored) {
                                        $resetCuenta = $pdo->prepare('UPDATE cuenta_pago SET monto_pagado = 0, saldo_restante = monto_total, actualizado_en = CURRENT_TIMESTAMP WHERE id = :id');
                                        $resetCuenta->execute(['id' => $cuentaId]);
                                    }
                                } else {
                                    $totalCuentaCents = (int) round((float) $cuenta['monto_total'] * 100);
                                    $otrosAbonosCents = 0;
                                    foreach ($otrosPagos as $op) {
                                        $otrosAbonosCents += (int) round((float) $op['abono'] * 100);
                                    }

                                    $nuevoPagadoCents = $otrosAbonosCents;
                                    $nuevoSaldoCents = max(0, $totalCuentaCents - $nuevoPagadoCents);

                                    $nuevoPagado = number_format($nuevoPagadoCents / 100, 2, '.', '');
                                    $nuevoSaldo = number_format($nuevoSaldoCents / 100, 2, '.', '');
                                    $nuevoEstado = $nuevoSaldoCents === 0 ? 'Pagado' : 'Deuda';
                                    $nuevoEstadoPago = $nuevoSaldoCents === 0 ? 'pagado' : 'pendiente';

                                    $updCuenta = $pdo->prepare(
                                        'UPDATE cuenta_pago
                                         SET monto_pagado = :pagado,
                                             saldo_restante = :saldo,
                                             actualizado_en = CURRENT_TIMESTAMP
                                         WHERE id = :id'
                                    );
                                    $updCuenta->execute([
                                        'pagado' => $nuevoPagado,
                                        'saldo' => $nuevoSaldo,
                                        'id' => $cuentaId,
                                    ]);

                                    $updPagos = $pdo->prepare(
                                        'UPDATE pago
                                         SET monto_pagado = :pagado,
                                             saldo_restante = :saldo,
                                             estado = :estado,
                                             estado_pago = :estado_pago
                                         WHERE cuenta_pago_id = :cuenta_id AND id <> :pago_id'
                                    );
                                    $updPagos->execute([
                                        'pagado' => $nuevoPagado,
                                        'saldo' => $nuevoSaldo,
                                        'estado' => $nuevoEstado,
                                        'estado_pago' => $nuevoEstadoPago,
                                        'cuenta_id' => $cuentaId,
                                        'pago_id' => $pagoId,
                                    ]);

                                    $delPago = $pdo->prepare('DELETE FROM pago WHERE id = :id');
                                    $delPago->execute(['id' => $pagoId]);
                                }
                            } else {
                                $delPago = $pdo->prepare('DELETE FROM pago WHERE id = :id');
                                $delPago->execute(['id' => $pagoId]);
                            }
                        } else {
                            $delPago = $pdo->prepare('DELETE FROM pago WHERE id = :id');
                            $delPago->execute(['id' => $pagoId]);
                        }

                        $montoEliminado = number_format((float) ($pago['abono_hoy'] ?: $pago['monto']), 2, '.', '');
                        $conceptoNombre = $pago['concepto'] ?: 'Sin concepto';
                        $fechaPago = $pago['fecha_pago'] ?: '-';

                        registrar_auditoria(
                            'eliminar_pago',
                            "Eliminó el pago ID {$pagoId} de S/ {$montoEliminado} del estudiante {$estudianteNombre} (Concepto: {$conceptoNombre}, Fecha de pago: {$fechaPago})."
                        );

                        $pdo->commit();
                        header('Location: pagos.php?pago_eliminado=1');
                        exit;
                    }
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    error_log('Error al eliminar pago ID ' . $pagoId . ': ' . $e->getMessage());
                    $error = 'No se pudo eliminar el pago: ' . $e->getMessage();
                }
            }
    } elseif ($action === 'crear_concepto') {
        require_permission('p_cobrar');
        $descripcion = trim($_POST['descripcion'] ?? '');
        $montoConcepto = $_POST['monto_concepto'] ?? '';
        $tipoCobro = $_POST['tipo_cobro'] ?? '';
        if ($descripcion === '' || strlen($descripcion) > 80 || !is_numeric($montoConcepto) ||
            (float) $montoConcepto <= 0 || !in_array($tipoCobro, ['estudiante', 'familia'], true)) {
            $error = 'Ingresa un concepto y un monto total válido.';
        } else {
            $duplicateStmt = $pdo->prepare('SELECT 1 FROM concepto_pago WHERE LOWER(descripcion) = LOWER(:descripcion)');
            $duplicateStmt->execute(['descripcion' => $descripcion]);
            if ($duplicateStmt->fetchColumn()) {
                $error = 'Ese concepto ya existe.';
            } else {
                $insertConcept = $pdo->prepare('INSERT INTO concepto_pago (descripcion, monto_total, monto_default, tipo_cobro, activo) VALUES (:descripcion, :monto_total, :monto_default, :tipo_cobro, TRUE)');
                $formattedAmount = number_format((float) $montoConcepto, 2, '.', '');
                $insertConcept->execute(['descripcion' => $descripcion, 'monto_total' => $formattedAmount, 'monto_default' => $formattedAmount, 'tipo_cobro' => $tipoCobro]);
                header('Location: pagos.php?concepto_creado=1');
                exit;
            }
        }
    } elseif ($action === 'editar_concepto') {
        $conceptoId = filter_var($_POST['concepto_id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $descripcion = trim($_POST['descripcion'] ?? '');
        $montoConcepto = $_POST['monto_concepto'] ?? '';
        $tipoCobro = $_POST['tipo_cobro'] ?? '';
        if ($conceptoId === false || $descripcion === '' || strlen($descripcion) > 80 ||
            !is_numeric($montoConcepto) || (float) $montoConcepto <= 0 ||
            !in_array($tipoCobro, ['estudiante', 'familia'], true)) {
            $error = 'Ingresa un concepto y un monto total válido.';
        } else {
            $duplicateStmt = $pdo->prepare('SELECT 1 FROM concepto_pago WHERE LOWER(descripcion) = LOWER(:descripcion) AND id <> :id');
            $duplicateStmt->execute(['descripcion' => $descripcion, 'id' => $conceptoId]);
            if ($duplicateStmt->fetchColumn()) {
                $error = 'Ya existe otro concepto con ese nombre.';
            } else {
                $formattedAmount = number_format((float) $montoConcepto, 2, '.', '');
                $updateConcept = $pdo->prepare('UPDATE concepto_pago SET descripcion = :descripcion, monto_total = :monto_total, monto_default = :monto_default, tipo_cobro = :tipo_cobro WHERE id = :id');
                $updateConcept->execute([
                    'descripcion' => $descripcion,
                    'monto_total' => $formattedAmount,
                    'monto_default' => $formattedAmount,
                    'tipo_cobro' => $tipoCobro,
                    'id' => $conceptoId,
                ]);
                header('Location: pagos.php?concepto_actualizado=1');
                exit;
            }
        }
    } elseif ($action === 'eliminar_concepto') {
        if (!isset($_POST['id']) || !is_string($_POST['id']) || trim($_POST['id']) === '') {
            $error = 'Selecciona un concepto válido.';
        } else {
            $conceptoId = filter_var($_POST['id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($conceptoId === false) {
                $error = 'Selecciona un concepto válido.';
            } else {
                try {
                    $pdo->beginTransaction();
                    $conceptNameStmt = $pdo->prepare('SELECT descripcion FROM concepto_pago WHERE id = :id FOR UPDATE');
                    $conceptNameStmt->execute(['id' => $conceptoId]);
                    $conceptName = $conceptNameStmt->fetchColumn();
                    if ($conceptName === false) {
                        $pdo->rollBack();
                        $error = 'No se encontró el registro.';
                    } else {
                        $stmt = $pdo->prepare('DELETE FROM concepto_pago WHERE id = :id');
                        $stmt->execute(['id' => $conceptoId]);
                        if ($stmt->rowCount() > 0) {
                            registrar_auditoria('eliminar_concepto_pago', 'Eliminó el concepto de pago ' . $conceptName . ' (ID ' . $conceptoId . ').');
                            $pdo->commit();
                            header('Location: pagos.php?concepto_eliminado=1');
                            exit;
                        }
                        $pdo->rollBack();
                        $error = 'No se encontró el registro.';
                    }
                } catch (PDOException $exception) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    error_log('Error al eliminar concepto de pago ID ' . $conceptoId . ': ' . $exception->getMessage());
                    $error = $exception->getCode() === '23503'
                        ? 'No se puede eliminar: el concepto está en uso por pagos o deudas.'
                        : 'Ocurrió un error al eliminar el concepto de pago.';
                }
            }
        }
    } else {
        require_permission('p_cobrar');
        $matriculaId = filter_var($_POST['matricula_id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $conceptoId = filter_var($_POST['concepto_id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $abonoHoy = $_POST['abono_hoy'] ?? '';
        $entregadoPor = trim($_POST['entregado_por'] ?? '');
        $fechaPago = trim($_POST['fecha_pago'] ?? '');
        $observacion = trim($_POST['observacion'] ?? '');

        if ($matriculaId === false || $conceptoId === false) {
            $error = 'Selecciona un estudiante matriculado y un concepto válido.';
        } elseif (!is_numeric($abonoHoy) || (float) $abonoHoy <= 0 || $entregadoPor === '' || strlen($entregadoPor) > 150 || $fechaPago === '') {
            $error = 'Ingresa el abono de hoy, quién entrega el dinero y la fecha.';
        } else {
            try {
                $enrollmentStmt = $pdo->prepare(
                    "SELECT m.id, m.estudiante_id, COALESCE(m.apoderado_principal_id, fallback.responsable_id) AS apoderado_id,
                            CONCAT_WS(' ', a.nombres, a.apellido_paterno, a.apellido_materno) AS estudiante,
                            r.nombres, r.apellido_paterno, r.apellido_materno
                     FROM matricula m
                     JOIN estudiante a ON a.id = m.estudiante_id
                     LEFT JOIN LATERAL (
                         SELECT ar.responsable_id FROM estudiante_responsable ar
                         WHERE ar.estudiante_id = m.estudiante_id
                         ORDER BY ar.es_apoderado DESC,
                                  CASE ar.tipo WHEN 'padre' THEN 1 WHEN 'madre' THEN 2 ELSE 3 END
                         LIMIT 1
                     ) fallback ON TRUE
                     JOIN responsable r ON r.id = COALESCE(m.apoderado_principal_id, fallback.responsable_id)
                     WHERE m.id = :id AND m.estado = 'Activa'"
                );
                $enrollmentStmt->execute(['id' => $matriculaId]);
                $matricula = $enrollmentStmt->fetch();
                $conceptoStmt = $pdo->prepare('SELECT id, descripcion, monto_default, tipo_cobro FROM concepto_pago WHERE id = :id AND activo = TRUE');
                $conceptoStmt->execute(['id' => $conceptoId]);
                $concepto = $conceptoStmt->fetch();
                if (!$matricula || !$concepto || $concepto['monto_default'] === null || (float) $concepto['monto_default'] <= 0) {
                    throw new DomainException('Estudiante, apoderado principal o tarifa del concepto no válidos.');
                }

                $pdo->beginTransaction();
                if ($concepto['tipo_cobro'] === 'familia') {
                    $familyLock = $pdo->prepare('SELECT id FROM responsable WHERE id = :responsable_id FOR UPDATE');
                    $familyLock->execute(['responsable_id' => $matricula['apoderado_id']]);
                }
                $totalInicial = (float) $concepto['monto_default'];
                $totalInicialCents = (int) round($totalInicial * 100);
                if ($totalInicialCents <= 0) {
                    throw new DomainException('El total de la caja debe ser mayor a S/ 0.00.');
                }
                $totalInicial = number_format($totalInicialCents / 100, 2, '.', '');
                if ($concepto['tipo_cobro'] === 'familia') {
                    $accountStmt = $pdo->prepare(
                        'SELECT cuenta.*
                         FROM cuenta_pago cuenta
                         JOIN matricula deuda_matricula ON deuda_matricula.id = cuenta.matricula_id
                         WHERE cuenta.responsable_id = :responsable_id
                           AND cuenta.concepto_id = :concepto_id
                           AND deuda_matricula.anio_id = (
                               SELECT anio_id FROM matricula WHERE id = :matricula_id
                           )
                         ORDER BY cuenta.id
                         LIMIT 1
                         FOR UPDATE OF cuenta'
                    );
                    $accountStmt->execute([
                        'responsable_id' => $matricula['apoderado_id'],
                        'concepto_id' => $conceptoId,
                        'matricula_id' => $matriculaId,
                    ]);
                    $cuenta = $accountStmt->fetch();
                } else {
                    $accountStmt = $pdo->prepare(
                        'SELECT * FROM cuenta_pago
                         WHERE matricula_id = :matricula_id AND concepto_id = :concepto_id
                         FOR UPDATE'
                    );
                    $accountStmt->execute(['matricula_id' => $matriculaId, 'concepto_id' => $conceptoId]);
                    $cuenta = $accountStmt->fetch();
                }
                if (!$cuenta) {
                    $openAccount = $pdo->prepare(
                        'INSERT INTO cuenta_pago (matricula_id, responsable_id, concepto_id, monto_total, monto_pagado, saldo_restante)
                         VALUES (:matricula_id, :responsable_id, :concepto_id, :monto_total, 0, :saldo_inicial)
                         ON CONFLICT (matricula_id, concepto_id) WHERE matricula_id IS NOT NULL DO NOTHING'
                    );
                    $openAccount->execute([
                        'matricula_id' => $matriculaId,
                        'responsable_id' => $matricula['apoderado_id'],
                        'concepto_id' => $conceptoId,
                        'monto_total' => $totalInicial,
                        'saldo_inicial' => $totalInicial,
                    ]);
                    $accountStmt = $pdo->prepare(
                        'SELECT * FROM cuenta_pago
                         WHERE matricula_id = :matricula_id AND concepto_id = :concepto_id
                         FOR UPDATE'
                    );
                    $accountStmt->execute(['matricula_id' => $matriculaId, 'concepto_id' => $conceptoId]);
                    $cuenta = $accountStmt->fetch();
                }
                if (!$cuenta) {
                    throw new RuntimeException('No se pudo abrir o recuperar la cuenta de pago.');
                }

                $totalCents = (int) round((float) $cuenta['monto_total'] * 100);
                $paidCents = (int) round((float) $cuenta['monto_pagado'] * 100);
                $balanceCents = (int) round((float) $cuenta['saldo_restante'] * 100);
                $installmentCents = (int) round((float) $abonoHoy * 100);
                if ($installmentCents <= 0) {
                    throw new DomainException('El abono debe ser de al menos S/ 0.01.');
                }
                if ($installmentCents > $balanceCents) {
                    throw new DomainException('El abono supera el saldo pendiente de este concepto.');
                }

                $newPaidCents = $paidCents + $installmentCents;
                $newBalanceCents = $balanceCents - $installmentCents;
                $newPaid = number_format($newPaidCents / 100, 2, '.', '');
                $newBalance = number_format($newBalanceCents / 100, 2, '.', '');
                $updateAccount = $pdo->prepare('UPDATE cuenta_pago SET monto_pagado = :pagado, saldo_restante = :saldo, actualizado_en = CURRENT_TIMESTAMP WHERE id = :id');
                $updateAccount->execute(['pagado' => $newPaid, 'saldo' => $newBalance, 'id' => $cuenta['id']]);

                $nombreResponsable = trim($matricula['nombres'] . ' ' . $matricula['apellido_paterno'] . ' ' . ($matricula['apellido_materno'] ?? ''));
                $estado = $newBalanceCents === 0 ? 'Pagado' : 'Deuda';
                $insertPayment = $pdo->prepare(
                    'INSERT INTO pago (matricula_id, responsable_id, concepto_id, cuenta_pago_id, responsable, concepto,
                                      monto, monto_total, monto_pagado, saldo_restante, abono_hoy, entregado_por,
                                      fecha_pago, estado, estado_pago, observacion)
                     VALUES (:matricula_id, :responsable_id, :concepto_id, :cuenta_pago_id, :responsable, :concepto,
                             :monto, :monto_total, :monto_pagado, :saldo_restante, :abono_hoy, :entregado_por,
                             :fecha_pago, :estado, :estado_pago, :observacion)'
                );
                $insertPayment->execute([
                    'matricula_id' => $cuenta['matricula_id'],
                    'responsable_id' => $cuenta['responsable_id'] ?? $matricula['apoderado_id'],
                    'concepto_id' => $conceptoId,
                    'cuenta_pago_id' => $cuenta['id'],
                    'responsable' => $nombreResponsable,
                    'concepto' => $concepto['descripcion'],
                    'monto' => number_format($installmentCents / 100, 2, '.', ''),
                    'monto_total' => number_format($totalCents / 100, 2, '.', ''),
                    'monto_pagado' => $newPaid,
                    'saldo_restante' => $newBalance,
                    'abono_hoy' => number_format($installmentCents / 100, 2, '.', ''),
                    'entregado_por' => $entregadoPor,
                    'fecha_pago' => $fechaPago,
                    'estado' => $estado,
                    'estado_pago' => $newBalanceCents === 0 ? 'pagado' : 'pendiente',
                    'observacion' => $observacion,
                ]);
                if ($newBalanceCents === 0) {
                    $completePayments = $pdo->prepare(
                        "UPDATE pago SET estado = 'Pagado', estado_pago = 'pagado'
                         WHERE cuenta_pago_id = :cuenta_pago_id"
                    );
                    $completePayments->execute(['cuenta_pago_id' => $cuenta['id']]);
                }
                registrar_auditoria('crear_pago', 'Abono estudiante ' . $matricula['estudiante'] . ', apoderado ' . $nombreResponsable . ', concepto ' . $concepto['descripcion'] . ': S/ ' . number_format($installmentCents / 100, 2, '.', '') . '. Saldo: S/ ' . $newBalance . '.');
                $pdo->commit();
                header('Location: pagos.php?guardado=1');
                exit;
            } catch (DomainException $exception) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error = $exception->getMessage();
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('Error al registrar pago: ' . $exception->getMessage());
                $error = 'No se pudo guardar el pago.';
            }
        }
    }
}
}

if (isset($_GET['guardado'])) {
    $mensaje = 'Pago registrado correctamente.';
} elseif (isset($_GET['concepto_creado'])) {
    $mensaje = 'Concepto agregado correctamente.';
} elseif (isset($_GET['concepto_actualizado'])) {
    $mensaje = 'Concepto actualizado correctamente.';
} elseif (isset($_GET['concepto_eliminado'])) {
    $mensaje = 'Concepto eliminado correctamente.';
} elseif (isset($_GET['pago_eliminado'])) {
    $mensaje = 'Pago eliminado correctamente y saldo actualizado.';
}

$sql = <<<'SQL'
SELECT p.*, COALESCE(cuenta.monto_total, p.monto_total, p.monto) AS total_cuenta,
    COALESCE(cuenta.monto_pagado, p.monto_pagado, p.monto) AS acumulado_cuenta,
    COALESCE(cuenta.saldo_restante, p.saldo_restante, 0) AS saldo_cuenta,
    CONCAT_WS(' ', a.nombres, a.apellido_paterno, a.apellido_materno) AS estudiante_nombre,
    COALESCE(CONCAT_WS(' ', r.nombres, r.apellido_paterno, r.apellido_materno), p.responsable) AS apoderado_nombre
FROM pago p
LEFT JOIN matricula m ON m.id = p.matricula_id
LEFT JOIN estudiante a ON a.id = m.estudiante_id
LEFT JOIN responsable r ON r.id = COALESCE(p.responsable_id, m.apoderado_principal_id)
LEFT JOIN cuenta_pago cuenta ON cuenta.id = p.cuenta_pago_id
SQL;
$params = [];
if ($busqueda !== '') {
    $sql .= ' WHERE COALESCE(a.nombres, \'\') ILIKE :estudiante_busqueda OR p.responsable ILIKE :responsable_busqueda OR p.concepto ILIKE :concepto_busqueda OR p.entregado_por ILIKE :entregado_busqueda';
    $like = "%$busqueda%";
    $params = ['estudiante_busqueda' => $like, 'responsable_busqueda' => $like, 'concepto_busqueda' => $like, 'entregado_busqueda' => $like];
}
$sql .= ' ORDER BY p.fecha_pago DESC, p.id DESC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$pagos = $stmt->fetchAll();
$renderPagoRows = static function (array $rows) use ($puedeCobrar, $esDirector, $tokenCsrf): void {
    if (!$rows) {
        echo '<tr><td colspan="10" class="text-center py-5">
            <div class="py-4">
                <i class="bi bi-wallet2 text-muted" style="font-size: 2.2rem;"></i>
                <h5 class="mt-3 text-secondary fw-semibold">No se encontraron pagos registrados</h5>
                <p class="text-muted small mb-0">Intenta con otro término de búsqueda por estudiante, apoderado o concepto.</p>
            </div>
        </td></tr>';
        return;
    }
    $secuencia = 1;
    foreach ($rows as $pago) {
        $total = (float) $pago['total_cuenta'];
        $pagado = (float) $pago['acumulado_cuenta'];
        $deuda = strtolower(trim((string) ($pago['estado'] ?? ''))) === 'deuda' || $pagado < $total;
        echo '<tr><td class="text-center"><span class="badge-secuencia">#' . $secuencia++ . '</span></td>';
        echo '<td><span class="fw-bold text-dark" style="font-size: 1.02rem;"><i class="bi bi-mortarboard text-secondary me-1" style="font-size: 0.95rem;"></i>' . htmlspecialchars($pago['estudiante_nombre'] ?: '-') . '</span></td>';
        echo '<td><span class="text-secondary" style="font-size: 0.95rem;">' . htmlspecialchars($pago['apoderado_nombre'] ?: '-') . '</span></td>';
        echo '<td><span class="badge bg-light text-dark border px-2 py-1" style="font-size: 0.85rem;"><i class="bi bi-tag me-1"></i>' . htmlspecialchars($pago['concepto']) . '</span></td>';
        echo '<td><span class="fw-bold text-dark" style="font-size: 0.98rem;">S/ ' . number_format($total, 2) . '</span></td>';
        echo '<td><span class="badge bg-success-subtle text-success border border-success-subtle fw-bold px-2 py-1" style="font-size: 0.88rem;"><i class="bi bi-wallet-fill me-1"></i>S/ ' . number_format((float) ($pago['abono_hoy'] ?: $pago['monto']), 2) . '</span></td>';
        if ((float) $pago['saldo_cuenta'] > 0) {
            echo '<td><span class="badge bg-danger-subtle text-danger border border-danger-subtle px-2 py-1" style="font-size: 0.88rem;">S/ ' . number_format((float) $pago['saldo_cuenta'], 2) . '</span></td>';
        } else {
            echo '<td><span class="badge bg-light text-muted border px-2 py-1" style="font-size: 0.85rem;"><i class="bi bi-check2"></i> S/ 0.00</span></td>';
        }
        echo '<td><span class="text-muted" style="font-size: 0.92rem;">' . htmlspecialchars($pago['entregado_por'] ?: '-') . '</span></td>';
        echo '<td><span class="text-muted" style="font-size: 0.92rem;"><i class="bi bi-calendar-event me-1"></i>' . htmlspecialchars($pago['fecha_pago']) . '</span></td><td>';
        
        $hasAction = false;
        echo '<div class="d-flex align-items-center gap-1 flex-wrap">';
        echo '<a href="recibo_pago.php?id=' . (int) $pago['id'] . '" target="_blank" rel="opener" class="btn btn-sm btn-outline-primary d-inline-flex align-items-center gap-1" title="Imprimir Recibo de Caja" style="font-size: 0.84rem; padding: 0.25rem 0.5rem;"><i class="bi bi-printer"></i> Recibo</a>';
        if ($puedeCobrar && $deuda && $pago['matricula_id'] && $pago['concepto_id']) {
            echo '<button type="button" class="btn btn-sm btn-outline-warning complete-payment d-inline-flex align-items-center gap-1" data-matricula-id="' . (int) $pago['matricula_id'] . '" data-concepto-id="' . (int) $pago['concepto_id'] . '" data-estudiante="' . htmlspecialchars($pago['estudiante_nombre'], ENT_QUOTES) . '"><i class="bi bi-credit-card-2-front"></i> Completar</button>';
            $hasAction = true;
        }
        if ($esDirector) {
            echo '<form method="POST" class="d-inline" onsubmit="return confirm(\'¿Estás seguro de que deseas eliminar este pago? Esta acción recalculará los saldos y no se puede deshacer.\');">';
            echo '<input type="hidden" name="action" value="eliminar_pago">';
            echo '<input type="hidden" name="pago_id" value="' . (int) $pago['id'] . '">';
            echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($tokenCsrf, ENT_QUOTES) . '">';
            echo '<button type="submit" class="btn btn-sm btn-outline-danger d-inline-flex align-items-center gap-1" title="Eliminar este pago"><i class="bi bi-trash"></i></button>';
            echo '</form>';
            $hasAction = true;
        }
        if (!$hasAction) {
            echo '<span class="text-muted small">-</span>';
        }
        echo '</div>';
        echo '</td></tr>';
    }
};
if (($_GET['ajax'] ?? '') === 'tabla') {
    header('Content-Type: text/html; charset=utf-8');
    $renderPagoRows($pagos);
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Pagos - Sistema Escolar</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="../assets/css/app.css">
    <link rel="stylesheet" href="../assets/css/pagos.css">
</head>
<body>
<?php render_app_header(panel_home_path(), 'logout.php'); ?>
<main class="container-fluid px-3 px-md-4 px-xl-5 py-4">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1 small">
                    <li class="breadcrumb-item"><a href="<?= htmlspecialchars(panel_home_path()) ?>" class="text-decoration-none text-muted"><i class="bi bi-house-door"></i> Inicio</a></li>
                    <li class="breadcrumb-item active text-primary fw-semibold" aria-current="page">Control de Pagos</li>
                </ol>
            </nav>
            <h1 class="h3 fw-bold mb-1 text-dark d-flex align-items-center gap-2">
                Control de Pagos y Caja
                <span class="badge bg-primary-subtle text-primary border border-primary-subtle fs-6 fw-semibold">Gestión 2026</span>
            </h1>
            <p class="text-muted mb-0 small">Registra cuotas, aportes APAFA, portería, multas y otros conceptos escolares.</p>
        </div>
        <div class="d-flex align-items-center gap-2">
            <a href="<?= htmlspecialchars(panel_home_path()) ?>" class="btn btn-outline-secondary d-inline-flex align-items-center gap-2 px-3 py-2" style="border-radius: 10px;">
                <i class="bi bi-arrow-left"></i> Volver al panel
            </a>
        </div>
    </div>
    <?php if ($mensaje !== ''): ?><div class="alert alert-success d-flex align-items-center gap-2 mb-3 shadow-sm border-0"><i class="bi bi-check-circle-fill fs-5"></i> <?= htmlspecialchars($mensaje) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="alert alert-danger d-flex align-items-center gap-2 mb-3 shadow-sm border-0" role="alert"><i class="bi bi-exclamation-triangle-fill fs-5"></i> <?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($puedeCobrar): ?>
    <section class="form-card mb-4"><div class="p-4"><h2 class="h5 mb-3 fw-bold text-dark d-flex align-items-center gap-2"><i class="bi bi-cash-stack text-warning"></i> Registrar Abono o Pago</h2>
        <form id="payment-form" method="POST" class="row g-3">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($tokenCsrf, ENT_QUOTES) ?>">
            <input type="hidden" name="action" value="registrar_pago">
            <input type="hidden" id="matricula_id" name="matricula_id" value="<?= htmlspecialchars((string) ($_POST['matricula_id'] ?? '')) ?>">
            <div class="col-md-4 position-relative"><label class="form-label required" for="estudiante">Estudiante</label><input class="form-control" id="estudiante" autocomplete="off" aria-autocomplete="list" aria-controls="estudiante-sugerencias" placeholder="Buscar por DNI o nombres..." required><div id="estudiante-sugerencias" class="list-group position-absolute start-0 top-100 w-100 shadow d-none" style="z-index:1050"></div></div>
            <div class="col-md-4"><label class="form-label" for="apoderado_principal">Apoderado principal</label><input class="form-control" id="apoderado_principal" readonly placeholder="Se completa automáticamente al elegir estudiante"></div>
            <div class="col-md-4"><label class="form-label required" for="concepto_id">Concepto</label><select class="form-select" id="concepto_id" name="concepto_id" required><option value="">Seleccionar Concepto</option><?php foreach ($conceptos as $concepto): ?><option value="<?= (int) $concepto['id'] ?>" <?= $concepto['monto_default'] === null ? 'disabled' : '' ?>><?= htmlspecialchars($concepto['descripcion']) ?> · <?= $concepto['tipo_cobro'] === 'familia' ? 'Por Familia' : 'Por Estudiante' ?><?= $concepto['monto_default'] !== null ? ' · S/ ' . number_format((float) $concepto['monto_default'], 2) : ' · tarifa pendiente' ?></option><?php endforeach; ?></select></div>
            <div class="col-md-3"><label class="form-label required" for="monto_total">Total del concepto</label><input class="form-control" id="monto_total" type="number" readonly required></div>
            <div class="col-md-3"><label class="form-label required" for="abono_hoy">Abono de hoy (S/)</label><input class="form-control font-monospace fw-bold" id="abono_hoy" name="abono_hoy" type="number" min="0.01" step="0.01" placeholder="0.00" value="<?= htmlspecialchars($_POST['abono_hoy'] ?? '') ?>" required></div>
            <div class="col-md-3"><label class="form-label required" for="entregado_por">Entregado por</label><input class="form-control" id="entregado_por" name="entregado_por" maxlength="150" placeholder="Nombre de quien paga" value="<?= htmlspecialchars($_POST['entregado_por'] ?? '') ?>" required></div>
            <div class="col-md-3"><label class="form-label required" for="fecha_pago">Fecha</label><input class="form-control" id="fecha_pago" name="fecha_pago" type="date" value="<?= htmlspecialchars($_POST['fecha_pago'] ?? date('Y-m-d')) ?>" required></div>
            <div class="col-12"><div id="saldo-caja" class="alert alert-secondary py-2 mb-0 d-flex align-items-center gap-2"><i class="bi bi-info-circle"></i> <span>Selecciona estudiante y concepto para consultar el estado de cuenta y saldos.</span></div></div>
            <div class="col-12"><label class="form-label" for="observacion">Observación</label><input class="form-control" id="observacion" name="observacion" maxlength="250" placeholder="Detalle adicional, número de comprobante, etc." value="<?= htmlspecialchars($_POST['observacion'] ?? '') ?>"></div>
            <div class="col-12 pt-2"><button class="btn btn-warning d-inline-flex align-items-center gap-2 px-4 shadow-sm fw-semibold" style="border-radius: 10px;" type="submit"><i class="bi bi-wallet2"></i> Guardar abono</button></div>
        </form>
    </div></section>
    <div class="mb-4">
        <button class="btn btn-outline-primary d-inline-flex align-items-center gap-1 px-3 py-1.5 fw-semibold small" id="toggle-concept-form" type="button" aria-expanded="false" style="border-radius: 8px;">
            <i class="bi bi-plus-circle"></i> Añadir concepto de pago
        </button>
    </div>
    <section id="concept-form" class="form-card mb-4 d-none"><div class="p-4"><h2 class="h6 fw-bold text-dark mb-3 d-flex align-items-center gap-2"><i class="bi bi-plus-circle-fill text-primary"></i> Registrar Nuevo Concepto</h2><form method="POST" class="row g-2"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($tokenCsrf, ENT_QUOTES) ?>"><input type="hidden" name="action" value="crear_concepto"><div class="col-md-4"><label class="form-label" for="descripcion">Concepto</label><input class="form-control" id="descripcion" name="descripcion" maxlength="80" placeholder="Ej. Materiales, Olimpiadas..." required></div><div class="col-md-3"><label class="form-label" for="monto_concepto">Monto total (S/)</label><input class="form-control font-monospace" id="monto_concepto" name="monto_concepto" type="number" min="0.01" step="0.01" placeholder="0.00" required></div><div class="col-md-3"><label class="form-label" for="tipo_cobro">Tipo de cobro</label><select class="form-select" id="tipo_cobro" name="tipo_cobro" required><option value="estudiante">Por Estudiante</option><option value="familia">Por Familia</option></select></div><div class="col-md-2 d-flex align-items-end"><button class="btn btn-primary w-100 d-inline-flex align-items-center justify-content-center gap-1" type="submit"><i class="bi bi-check-lg"></i> Guardar</button></div></form></div></section>
    <section class="table-card p-4 mb-4"><div class="d-flex justify-content-between align-items-center mb-3"><h2 class="h5 mb-0 fw-bold text-dark d-flex align-items-center gap-2"><i class="bi bi-tags-fill text-primary"></i> Conceptos de Cobro</h2><span class="badge bg-light text-secondary border"><?= count($conceptosAdmin) ?> conceptos</span></div><div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead class="table-dark"><tr><th>Nombre</th><th>Monto predeterminado</th><th>Tipo de cobro</th><th>Estado</th><th class="text-end">Acciones</th></tr></thead><tbody>
        <?php foreach ($conceptosAdmin as $conceptoAdmin): ?><tr><td><span class="fw-semibold text-dark"><?= htmlspecialchars($conceptoAdmin['descripcion']) ?></span></td><td><?= $conceptoAdmin['monto_default'] !== null ? '<span class="badge bg-success-subtle text-success border border-success-subtle fw-bold">S/ ' . number_format((float) $conceptoAdmin['monto_default'], 2) . '</span>' : '<span class="badge bg-light text-muted border">Sin tarifa</span>' ?></td><td><?= $conceptoAdmin['tipo_cobro'] === 'familia' ? '<span class="badge bg-info-subtle text-info-emphasis border border-info-subtle">Por Familia</span>' : '<span class="badge bg-light text-secondary border">Por Estudiante</span>' ?></td><td><?= $conceptoAdmin['activo'] ? '<span class="badge bg-success-subtle text-success border border-success-subtle">Activo</span>' : '<span class="badge bg-light text-muted border">Inactivo</span>' ?></td><td class="text-end"><?php if (has_permission('p_cobrar')): ?><details class="d-inline-block text-start"><summary class="btn btn-outline-primary btn-sm d-inline-flex align-items-center gap-1"><i class="bi bi-pencil-square"></i> Editar</summary><form method="POST" class="row g-2 border rounded p-3 mt-2 shadow-sm bg-light"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($tokenCsrf, ENT_QUOTES) ?>"><input type="hidden" name="action" value="editar_concepto"><input type="hidden" name="concepto_id" value="<?= (int) $conceptoAdmin['id'] ?>"><div class="col-md-4"><label class="form-label small fw-semibold">Nombre</label><input class="form-control form-control-sm" name="descripcion" maxlength="80" value="<?= htmlspecialchars($conceptoAdmin['descripcion']) ?>" required></div><div class="col-md-3"><label class="form-label small fw-semibold">Monto (S/)</label><input class="form-control form-control-sm" name="monto_concepto" type="number" min="0.01" step="0.01" value="<?= htmlspecialchars((string) ($conceptoAdmin['monto_default'] ?? '')) ?>" required></div><div class="col-md-3"><label class="form-label small fw-semibold">Tipo de cobro</label><select class="form-select form-select-sm" name="tipo_cobro" required><option value="estudiante" <?= $conceptoAdmin['tipo_cobro'] === 'estudiante' ? 'selected' : '' ?>>Por Estudiante</option><option value="familia" <?= $conceptoAdmin['tipo_cobro'] === 'familia' ? 'selected' : '' ?>>Por Familia</option></select></div><div class="col-md-2 d-flex align-items-end"><button class="btn btn-primary btn-sm w-100" type="submit">Guardar</button></div></form></details><?php endif; ?><?php if (has_permission('p_cobrar')): ?><form method="POST" class="d-inline ms-1" onsubmit="return confirm('¿Eliminar este concepto?')"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($tokenCsrf, ENT_QUOTES) ?>"><input type="hidden" name="action" value="eliminar_concepto"><input type="hidden" name="id" value="<?= (int) $conceptoAdmin['id'] ?>"><button class="btn btn-outline-danger btn-sm" type="submit" title="Eliminar"><i class="bi bi-trash"></i></button></form><?php endif; ?></td></tr><?php endforeach; ?>
        <?php if (!$conceptosAdmin): ?><tr><td colspan="5" class="text-center text-muted py-3">No hay conceptos registrados.</td></tr><?php endif; ?>
    </tbody></table></div></section>
    <?php endif; ?>
    <section class="table-card p-4">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <h3 class="h5 mb-0 fw-bold text-dark d-flex align-items-center gap-2"><i class="bi bi-receipt-cutoff text-primary"></i> Historial de Pagos</h3>
            <div class="d-flex align-items-center gap-2">
                <a href="reporte_pagos.php" class="btn btn-outline-primary d-inline-flex align-items-center gap-1.5 px-3 py-1.5 fw-semibold shadow-sm" style="border-radius: 9px; font-size: 0.88rem;">
                    <i class="bi bi-cash-stack"></i> Reporte de Caja
                </a>
                <span class="badge bg-primary px-3 py-2 fs-6" id="pagos-count-badge"><?= count($pagos) ?> pagos</span>
            </div>
        </div>
        <form method="GET" class="row g-2 mb-3" data-live-filter data-live-target="#pagos-rows" data-live-count-target="#pagos-count-badge" data-live-count-singular="pago" data-live-count-plural="pagos">
            <div class="col-sm-9">
                <div class="input-group">
                    <span class="input-group-text bg-white border-end-0 text-muted"><i class="bi bi-search"></i></span>
                    <input class="form-control border-start-0 ps-0" id="buscar" name="buscar" value="<?= htmlspecialchars($busqueda) ?>" placeholder="Buscar estudiante, apoderado, concepto o entregado por...">
                </div>
            </div>
            <div class="col-sm-3">
                <button class="btn btn-outline-primary w-100 d-inline-flex align-items-center justify-content-center gap-1" type="submit">
                    <i class="bi bi-search"></i> Buscar
                </button>
            </div>
        </form>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-dark">
                    <tr>
                        <th style="width: 65px;" class="text-center">#</th>
                        <th style="min-width: 210px;">Estudiante</th>
                        <th style="min-width: 200px;">Apoderado</th>
                        <th style="min-width: 140px;">Concepto</th>
                        <th style="min-width: 100px;">Total</th>
                        <th style="min-width: 110px;">Abono hoy</th>
                        <th style="min-width: 100px;">Saldo</th>
                        <th style="min-width: 140px;">Entregado por</th>
                        <th style="min-width: 115px;">Fecha</th>
                        <th style="min-width: 175px;">Acciones</th>
                    </tr>
                </thead>
                <tbody id="pagos-rows"><?php $renderPagoRows($pagos); ?></tbody>
            </table>
        </div>
    </section>
</main>
<script src="../assets/js/pagos.js" defer></script>
</body>
</html>
