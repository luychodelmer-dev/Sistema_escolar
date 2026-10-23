<?php
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/header.php';

require_auth(['asistente', 'director']);

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
    require_permission('p_cobrar');
    $action = $_POST['action'] ?? '';
    if ($action === 'crear_concepto') {
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

if (isset($_GET['guardado'])) {
    $mensaje = 'Pago registrado correctamente.';
} elseif (isset($_GET['concepto_creado'])) {
    $mensaje = 'Concepto agregado correctamente.';
} elseif (isset($_GET['concepto_actualizado'])) {
    $mensaje = 'Concepto actualizado correctamente.';
} elseif (isset($_GET['concepto_eliminado'])) {
    $mensaje = 'Concepto eliminado correctamente.';
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
$renderPagoRows = static function (array $rows) use ($puedeCobrar): void {
    if (!$rows) {
        echo '<tr><td colspan="9" class="text-center text-muted py-4">No hay pagos para mostrar.</td></tr>';
        return;
    }
    foreach ($rows as $pago) {
        $total = (float) $pago['total_cuenta'];
        $pagado = (float) $pago['acumulado_cuenta'];
        $deuda = strtolower(trim((string) ($pago['estado'] ?? ''))) === 'deuda' || $pagado < $total;
        echo '<tr><td>' . htmlspecialchars($pago['estudiante_nombre'] ?: '-') . '</td>';
        echo '<td>' . htmlspecialchars($pago['apoderado_nombre'] ?: '-') . '</td><td>' . htmlspecialchars($pago['concepto']) . '</td>';
        echo '<td>S/ ' . number_format($total, 2) . '</td><td>S/ ' . number_format((float) ($pago['abono_hoy'] ?: $pago['monto']), 2) . '</td>';
        echo '<td>S/ ' . number_format((float) $pago['saldo_cuenta'], 2) . '</td><td>' . htmlspecialchars($pago['entregado_por'] ?: '-') . '</td>';
        echo '<td>' . htmlspecialchars($pago['fecha_pago']) . '</td><td>';
        if ($puedeCobrar && $deuda && $pago['matricula_id'] && $pago['concepto_id']) {
            echo '<button type="button" class="btn btn-sm btn-outline-warning complete-payment" data-matricula-id="' . (int) $pago['matricula_id'] . '" data-concepto-id="' . (int) $pago['concepto_id'] . '" data-estudiante="' . htmlspecialchars($pago['estudiante_nombre'], ENT_QUOTES) . '">Completar pago</button>';
        } else {
            echo '<span class="text-muted">-</span>';
        }
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
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>body { background: #f4f7fb; color: #172b4d; } .page-header { background: #172b4d; color: #fff; } .card { border: 0; border-radius: 12px; } .required::after { content: ' *'; color: #dc3545; } .institution-brand { display: flex; align-items: center; gap: .6rem; white-space: nowrap; } .institution-brand img { width: 42px; height: 42px; object-fit: contain; border-radius: 50%; background: #fff; } .institution-brand span { font-size: 1.1rem; white-space: nowrap; } .header-user-photo { width: 52px; height: 52px; object-fit: cover; border: 2px solid rgba(255, 255, 255, .85); border-radius: 50%; } @media (max-width: 768px) { .institution-brand span { font-size: .95rem; } }</style>
</head>
<body>
<?php render_app_header(panel_home_path(), 'logout.php'); ?>
<main class="container py-4">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4"><div><h1 class="h3 mb-1">Control de pagos</h1><p class="text-muted mb-0">Registra cuotas, aportes y otros pagos escolares.</p></div><a href="<?= htmlspecialchars(panel_home_path()) ?>" class="btn btn-outline-primary">Volver al panel</a></div>
    <?php if ($mensaje !== ''): ?><div class="alert alert-success"><?= htmlspecialchars($mensaje) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="alert alert-danger" role="alert"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($puedeCobrar): ?>
    <section class="card shadow-sm mb-3"><div class="card-body p-4"><h2 class="h5 mb-3">Nuevo pago</h2>
        <form id="payment-form" method="POST" class="row g-3">
            <input type="hidden" name="action" value="registrar_pago">
            <input type="hidden" id="matricula_id" name="matricula_id" value="<?= htmlspecialchars((string) ($_POST['matricula_id'] ?? '')) ?>">
            <div class="col-md-4 position-relative"><label class="form-label required" for="estudiante">Estudiante</label><input class="form-control" id="estudiante" autocomplete="off" aria-autocomplete="list" aria-controls="estudiante-sugerencias" required><div id="estudiante-sugerencias" class="list-group position-absolute start-0 top-100 w-100 shadow d-none" style="z-index:1050"></div></div>
            <div class="col-md-4"><label class="form-label" for="apoderado_principal">Apoderado principal</label><input class="form-control" id="apoderado_principal" readonly placeholder="Se completa al elegir estudiante"></div>
            <div class="col-md-4"><label class="form-label required" for="concepto_id">Concepto</label><select class="form-select" id="concepto_id" name="concepto_id" required><option value="">Seleccionar</option><?php foreach ($conceptos as $concepto): ?><option value="<?= (int) $concepto['id'] ?>" <?= $concepto['monto_default'] === null ? 'disabled' : '' ?>><?= htmlspecialchars($concepto['descripcion']) ?> · <?= $concepto['tipo_cobro'] === 'familia' ? 'Por Familia' : 'Por Estudiante' ?><?= $concepto['monto_default'] !== null ? ' · S/ ' . number_format((float) $concepto['monto_default'], 2) : ' · tarifa pendiente' ?></option><?php endforeach; ?></select></div>
            <div class="col-md-3"><label class="form-label required" for="monto_total">Total del concepto</label><input class="form-control" id="monto_total" type="number" readonly required></div>
            <div class="col-md-3"><label class="form-label required" for="abono_hoy">Abono de hoy</label><input class="form-control" id="abono_hoy" name="abono_hoy" type="number" min="0.01" step="0.01" value="<?= htmlspecialchars($_POST['abono_hoy'] ?? '') ?>" required></div>
            <div class="col-md-3"><label class="form-label required" for="entregado_por">Entregado por</label><input class="form-control" id="entregado_por" name="entregado_por" maxlength="150" value="<?= htmlspecialchars($_POST['entregado_por'] ?? '') ?>" required></div>
            <div class="col-md-3"><label class="form-label required" for="fecha_pago">Fecha</label><input class="form-control" id="fecha_pago" name="fecha_pago" type="date" value="<?= htmlspecialchars($_POST['fecha_pago'] ?? date('Y-m-d')) ?>" required></div>
            <div class="col-12"><div id="saldo-caja" class="alert alert-secondary py-2 mb-0">Selecciona estudiante y concepto para consultar su cuenta.</div></div>
            <div class="col-12"><label class="form-label" for="observacion">Observación</label><input class="form-control" id="observacion" name="observacion" maxlength="250" value="<?= htmlspecialchars($_POST['observacion'] ?? '') ?>"></div>
            <div class="col-12"><button class="btn btn-warning" type="submit">Guardar abono</button></div>
        </form>
    </div></section>
    <button class="btn btn-outline-primary btn-sm mb-4" id="toggle-concept-form" type="button" aria-expanded="false">+ Añadir concepto de pago</button>
    <section id="concept-form" class="card shadow-sm mb-4 d-none"><div class="card-body"><h2 class="h6">Nuevo concepto</h2><form method="POST" class="row g-2"><input type="hidden" name="action" value="crear_concepto"><div class="col-md-4"><label class="form-label" for="descripcion">Concepto</label><input class="form-control" id="descripcion" name="descripcion" maxlength="80" required></div><div class="col-md-3"><label class="form-label" for="monto_concepto">Monto total (S/)</label><input class="form-control" id="monto_concepto" name="monto_concepto" type="number" min="0.01" step="0.01" required></div><div class="col-md-3"><label class="form-label" for="tipo_cobro">Tipo de cobro</label><select class="form-select" id="tipo_cobro" name="tipo_cobro" required><option value="estudiante">Por Estudiante</option><option value="familia">Por Familia</option></select></div><div class="col-md-2 d-flex align-items-end"><button class="btn btn-primary w-100" type="submit">Guardar concepto</button></div></form></div></section>
    <section class="card shadow-sm mb-4"><div class="card-body p-4"><h2 class="h5 mb-3">Conceptos de pago</h2><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Nombre</th><th>Monto predeterminado</th><th>Tipo de cobro</th><th>Estado</th><th>Acciones</th></tr></thead><tbody>
        <?php foreach ($conceptosAdmin as $conceptoAdmin): ?><tr><td><?= htmlspecialchars($conceptoAdmin['descripcion']) ?></td><td><?= $conceptoAdmin['monto_default'] !== null ? 'S/ ' . number_format((float) $conceptoAdmin['monto_default'], 2) : 'Sin tarifa' ?></td><td><?= $conceptoAdmin['tipo_cobro'] === 'familia' ? 'Por Familia' : 'Por Estudiante' ?></td><td><?= $conceptoAdmin['activo'] ? 'Activo' : 'Inactivo' ?></td><td><details><summary class="btn btn-outline-primary btn-sm">Editar</summary><form method="POST" class="row g-2 border rounded p-3 mt-2"><input type="hidden" name="action" value="editar_concepto"><input type="hidden" name="concepto_id" value="<?= (int) $conceptoAdmin['id'] ?>"><div class="col-md-4"><label class="form-label">Nombre</label><input class="form-control" name="descripcion" maxlength="80" value="<?= htmlspecialchars($conceptoAdmin['descripcion']) ?>" required></div><div class="col-md-3"><label class="form-label">Monto (S/)</label><input class="form-control" name="monto_concepto" type="number" min="0.01" step="0.01" value="<?= htmlspecialchars((string) ($conceptoAdmin['monto_default'] ?? '')) ?>" required></div><div class="col-md-3"><label class="form-label">Tipo de cobro</label><select class="form-select" name="tipo_cobro" required><option value="estudiante" <?= $conceptoAdmin['tipo_cobro'] === 'estudiante' ? 'selected' : '' ?>>Por Estudiante</option><option value="familia" <?= $conceptoAdmin['tipo_cobro'] === 'familia' ? 'selected' : '' ?>>Por Familia</option></select></div><div class="col-md-2 d-flex align-items-end"><button class="btn btn-primary w-100" type="submit">Guardar</button></div></form></details><form method="POST" class="d-inline" onsubmit="return confirm('¿Eliminar este concepto?')"><input type="hidden" name="action" value="eliminar_concepto"><input type="hidden" name="id" value="<?= (int) $conceptoAdmin['id'] ?>"><button class="btn btn-outline-danger btn-sm mt-2" type="submit">Eliminar</button></form></td></tr><?php endforeach; ?>
        <?php if (!$conceptosAdmin): ?><tr><td colspan="5" class="text-center text-muted">No hay conceptos registrados.</td></tr><?php endif; ?>
    </tbody></table></div></div></section>
    <?php endif; ?>
    <section class="card shadow-sm"><div class="card-body p-4"><form method="GET" class="row g-2 mb-3" data-live-filter data-live-target="#pagos-rows"><div class="col-sm-9"><label class="visually-hidden" for="buscar">Buscar pago</label><input class="form-control" id="buscar" name="buscar" value="<?= htmlspecialchars($busqueda) ?>" placeholder="Buscar estudiante, apoderado, concepto o entregado por"></div><div class="col-sm-3"><button class="btn btn-outline-primary w-100" type="submit">Buscar</button></div></form><div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead><tr><th>Estudiante</th><th>Apoderado</th><th>Concepto</th><th>Total</th><th>Abono hoy</th><th>Saldo</th><th>Entregado por</th><th>Fecha</th><th>Acciones</th></tr></thead><tbody id="pagos-rows"><?php $renderPagoRows($pagos); ?></tbody></table></div></div></section>
</main>
<script>
const estudianteInput = document.getElementById('estudiante');
const matriculaInput = document.getElementById('matricula_id');
const estudianteSugerencias = document.getElementById('estudiante-sugerencias');
const apoderadoInput = document.getElementById('apoderado_principal');
const conceptoSelect = document.getElementById('concepto_id');
const totalInput = document.getElementById('monto_total');
const abonoInput = document.getElementById('abono_hoy');
const saldoCaja = document.getElementById('saldo-caja');
const toggleConceptForm = document.getElementById('toggle-concept-form');
const conceptForm = document.getElementById('concept-form');
let temporizadorBusquedaEstudiante;
let solicitudEstudiantes;
let saldoDisponible = 0;
let cuentaExiste = false;
let deudaPendiente = false;

function actualizarSaldo() {
    const abono = Number(abonoInput.value || 0);
    if (!deudaPendiente && cuentaExiste) {
        saldoCaja.textContent = 'Este concepto ya está completamente pagado.';
    } else {
        const label = deudaPendiente ? 'Saldo restante' : 'Monto total por pagar';
        saldoCaja.textContent = `${label}: S/ ${saldoDisponible.toFixed(2)} · Luego del abono: S/ ${Math.max(0, saldoDisponible - abono).toFixed(2)}`;
    }
    abonoInput.max = saldoDisponible.toFixed(2);
    abonoInput.disabled = cuentaExiste && saldoDisponible === 0;
}

async function cargarApoderadoYSaldo() {
    if (!matriculaInput.value) return;
    const response = await fetch(`pagos.php?ajax=apoderado&id=${encodeURIComponent(matriculaInput.value)}`);
    if (!response.ok) throw new Error('La matrícula no tiene apoderado principal.');
    const apoderado = await response.json();
    apoderadoInput.value = `${apoderado.nombre} · DNI ${apoderado.dni}`;
    if (conceptoSelect.value) await cargarSaldo();
}

async function cargarConcepto() {
    totalInput.value = '';
    saldoDisponible = 0;
    cuentaExiste = false;
    deudaPendiente = false;
    abonoInput.disabled = false;
    if (!conceptoSelect.value) {
        saldoCaja.textContent = 'Selecciona estudiante y concepto para consultar su cuenta.';
        return;
    }
    const response = await fetch(`pagos.php?ajax=concepto&id=${encodeURIComponent(conceptoSelect.value)}`);
    const concepto = await response.json();
    if (!response.ok) throw new Error(concepto.error || 'Tarifa no disponible.');
    totalInput.value = concepto.monto_default;
    totalInput.readOnly = true;
    if (matriculaInput.value) await cargarSaldo();
}

async function cargarSaldo() {
    const query = new URLSearchParams({ ajax: 'saldo', id: matriculaInput.value, concepto_id: conceptoSelect.value });
    const response = await fetch(`pagos.php?${query}`);
    if (!response.ok) throw new Error('No se pudo consultar el saldo de esta matrícula.');
    const cuenta = await response.json();
    totalInput.value = cuenta.monto_total;
    saldoDisponible = Number(cuenta.saldo_restante || 0);
    cuentaExiste = cuenta.cuenta_existente;
    deudaPendiente = cuenta.deuda_pendiente;
    abonoInput.max = saldoDisponible.toFixed(2);
    if (!abonoInput.value || Number(abonoInput.value) > saldoDisponible) {
        abonoInput.value = saldoDisponible.toFixed(2);
    }
    actualizarSaldo();
}

document.querySelectorAll('.complete-payment').forEach((button) => {
    button.addEventListener('click', async () => {
        estudianteInput.value = button.dataset.estudiante;
        matriculaInput.value = button.dataset.matriculaId;
        conceptoSelect.value = button.dataset.conceptoId;
        abonoInput.value = '';
        saldoCaja.textContent = 'Consultando saldo pendiente…';
        try {
            await cargarApoderadoYSaldo();
            if (!deudaPendiente || saldoDisponible <= 0) {
                throw new Error('Esta deuda ya fue pagada o no tiene saldo pendiente.');
            }
            abonoInput.value = saldoDisponible.toFixed(2);
            actualizarSaldo();
            document.getElementById('payment-form').scrollIntoView({ behavior: 'smooth', block: 'center' });
            abonoInput.focus({ preventScroll: true });
        } catch (error) {
            saldoCaja.textContent = error.message;
        }
    });
});

estudianteInput?.addEventListener('input', () => {
    matriculaInput.value = '';
    apoderadoInput.value = '';
    abonoInput.value = '';
    saldoDisponible = 0;
    cuentaExiste = false;
    deudaPendiente = false;
    abonoInput.disabled = false;
    saldoCaja.textContent = 'Selecciona estudiante y concepto para consultar su cuenta.';
    estudianteSugerencias.replaceChildren();
    estudianteSugerencias.classList.add('d-none');
    clearTimeout(temporizadorBusquedaEstudiante);
    solicitudEstudiantes?.abort();
    const termino = estudianteInput.value.trim();
    if (termino.length < 2) {
        return;
    }

    temporizadorBusquedaEstudiante = setTimeout(async () => {
        solicitudEstudiantes = new AbortController();
        try {
            const respuesta = await fetch(`buscar_estudiantes_pago.php?q=${encodeURIComponent(termino)}`, {
                headers: { Accept: 'application/json' },
                signal: solicitudEstudiantes.signal
            });
            if (!respuesta.ok) throw new Error('No se pudo buscar estudiantes.');
            const resultados = await respuesta.json();
            resultados.forEach((item) => {
                const opcion = document.createElement('button');
                opcion.type = 'button';
                opcion.className = 'list-group-item list-group-item-action';
                opcion.textContent = `${item.nombre} · DNI ${item.dni}`;
                opcion.addEventListener('click', () => {
                    estudianteInput.value = item.nombre;
                    matriculaInput.value = item.matricula_id;
                    estudianteSugerencias.classList.add('d-none');
                    cargarApoderadoYSaldo().catch((error) => {
                        apoderadoInput.value = '';
                        saldoCaja.textContent = error.message;
                    });
                });
                estudianteSugerencias.append(opcion);
            });
            estudianteSugerencias.classList.toggle('d-none', resultados.length === 0);
        } catch (error) {
            if (error.name !== 'AbortError') console.error(error);
        }
    }, 250);
});

document.getElementById('payment-form')?.addEventListener('submit', (event) => {
    if (!matriculaInput.value || !apoderadoInput.value) {
        event.preventDefault();
        estudianteInput.setCustomValidity('Selecciona un estudiante con apoderado principal.');
        estudianteInput.reportValidity();
    }
});

estudianteInput?.addEventListener('input', () => estudianteInput.setCustomValidity(''));
conceptoSelect?.addEventListener('change', () => cargarConcepto().catch((error) => {
    totalInput.value = '';
    saldoCaja.textContent = error.message;
}));
abonoInput?.addEventListener('input', actualizarSaldo);
toggleConceptForm?.addEventListener('click', () => {
    const visible = conceptForm.classList.toggle('d-none') === false;
    toggleConceptForm.setAttribute('aria-expanded', String(visible));
});
</script>
</body>
</html>
