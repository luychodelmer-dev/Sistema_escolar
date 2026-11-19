<?php
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/auth.php';

require_auth(['asistente', 'director', 'hiperadmin']);

$pagoId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$pagoId) {
    http_response_code(400);
    exit('Debe especificar un ID de pago válido.');
}

$query = "
    SELECT p.*,
           COALESCE(cuenta.monto_total, p.monto_total, p.monto) AS total_cuenta,
           COALESCE(cuenta.monto_pagado, p.monto_pagado, p.monto) AS acumulado_cuenta,
           COALESCE(cuenta.saldo_restante, p.saldo_restante, 0) AS saldo_cuenta,
           e.id AS estudiante_id, e.dni AS estudiante_dni, e.nombres AS estudiante_nombres,
           e.apellido_paterno AS estudiante_apellido_paterno, e.apellido_materno AS estudiante_apellido_materno,
           an.anio, n.nombre AS nivel_nombre, g.nombre AS grado_nombre, s.nombre AS seccion_nombre,
           r.id AS apoderado_id, r.dni AS apoderado_dni, r.nombres AS apoderado_nombres,
           r.apellido_paterno AS apoderado_apellido_paterno, r.apellido_materno AS apoderado_apellido_materno,
           r.celular AS apoderado_celular,
           u.nombres AS cajero_nombre,
           COALESCE(NULLIF(p.concepto, ''), cp.descripcion, 'Aporte') AS concepto_nombre,
           cp.tipo_cobro
    FROM pago p
    LEFT JOIN matricula m ON m.id = p.matricula_id
    LEFT JOIN anio an ON an.id = m.anio_id
    LEFT JOIN nivel n ON n.id = m.nivel_id
    LEFT JOIN grado_seccion gs ON gs.id = m.grado_seccion_id
    LEFT JOIN grado g ON g.id = gs.grado_id
    LEFT JOIN seccion s ON s.id = gs.seccion_id
    LEFT JOIN estudiante e ON e.id = m.estudiante_id
    LEFT JOIN responsable r ON r.id = COALESCE(p.responsable_id, m.apoderado_principal_id)
    LEFT JOIN cuenta_pago cuenta ON cuenta.id = p.cuenta_pago_id
    LEFT JOIN concepto_pago cp ON cp.id = p.concepto_id
    LEFT JOIN usuario u ON u.id = p.registrado_por
    WHERE p.id = :id
    LIMIT 1
";
$stmt = $pdo->prepare($query);
$stmt->execute(['id' => $pagoId]);
$pago = $stmt->fetch();

if (!$pago) {
    http_response_code(404);
    exit('El pago solicitado no existe.');
}

// Helper para convertir montos a letras
function numero_a_letras(float $numero): string {
    $enteros = (int) floor($numero);
    $centavos = (int) round(($numero - $enteros) * 100);

    $unidades = ['', 'UN', 'DOS', 'TRES', 'CUATRO', 'CINCO', 'SEIS', 'SIETE', 'OCHO', 'NUEVE'];
    $decenas = ['', 'DIEZ', 'VEINTE', 'TREINTA', 'CUARENTA', 'CINCUENTA', 'SESENTA', 'SETENTA', 'OCHENTA', 'NOVENTA'];
    $especiales = [
        11 => 'ONCE', 12 => 'DOCE', 13 => 'TRECE', 14 => 'CATORCE', 15 => 'QUINCE',
        16 => 'DIECISÉIS', 17 => 'DIECISIETE', 18 => 'DIECIOCHO', 19 => 'DIECINUEVE',
        21 => 'VEINTIUNO', 22 => 'VEINTIDÓS', 23 => 'VEINTITRÉS', 24 => 'VEINTICUATRO',
        25 => 'VEINTICINCO', 26 => 'VEINTISÉIS', 27 => 'VEINTISIETE', 28 => 'VEINTIOCHO', 29 => 'VEINTINUEVE'
    ];
    $centenas = ['', 'CIENTO', 'DOSCIENTOS', 'TRESCIENTOS', 'CUATROCIENTOS', 'QUINIENTOS', 'SEISCIENTOS', 'SETECIENTOS', 'OCHOCIENTOS', 'NOVECIENTOS'];

    if ($enteros === 0) {
        $textoEntero = 'CERO';
    } elseif ($enteros === 100) {
        $textoEntero = 'CIEN';
    } else {
        $partes = [];
        // Centenas
        $c = (int) floor(($enteros % 1000) / 100);
        $d = (int) floor(($enteros % 100) / 10);
        $u = (int) ($enteros % 10);

        // Miles si aplica
        $miles = (int) floor($enteros / 1000);
        if ($miles === 1) {
            $partes[] = 'MIL';
        } elseif ($miles > 1) {
            $partes[] = ($miles < 10 ? $unidades[$miles] : $miles) . ' MIL';
        }

        if ($c > 0) $partes[] = $centenas[$c];

        $du = $d * 10 + $u;
        if (isset($especiales[$du])) {
            $partes[] = $especiales[$du];
        } else {
            if ($d > 0) {
                if ($u > 0) {
                    $partes[] = $decenas[$d] . ' Y ' . $unidades[$u];
                } else {
                    $partes[] = $decenas[$d];
                }
            } elseif ($u > 0) {
                $partes[] = $unidades[$u];
            }
        }
        $textoEntero = implode(' ', array_filter($partes));
    }

    $centavosStr = str_pad((string)$centavos, 2, '0', STR_PAD_LEFT);
    return "SON: " . $textoEntero . " CON " . $centavosStr . "/100 SOLES";
}

$montoAbonado = (float) ($pago['abono_hoy'] ?: $pago['monto']);
$montoTotalConcepto = (float) $pago['total_cuenta'];
$saldoRestante = (float) $pago['saldo_cuenta'];

$numeroRecibo = !empty($pago['nro_recibo']) ? $pago['nro_recibo'] : ('REC-' . date('Y', strtotime($pago['fecha_pago'])) . '-' . str_pad((string)$pago['id'], 6, '0', STR_PAD_LEFT));
$nombreEstudiante = trim(($pago['estudiante_apellido_paterno'] ?? '') . ' ' . ($pago['estudiante_apellido_materno'] ?? '') . ', ' . ($pago['estudiante_nombres'] ?? ''));
$nombreApoderado = trim(($pago['apoderado_apellido_paterno'] ?? '') . ' ' . ($pago['apoderado_apellido_materno'] ?? '') . ', ' . ($pago['apoderado_nombres'] ?? ''));
if ($nombreApoderado === ',' || $nombreApoderado === '') {
    $nombreApoderado = $pago['responsable'] ?: 'No registrado';
}

$entregadoPor = $pago['entregado_por'] ?: $nombreApoderado;
$aulaTexto = trim(($pago['nivel_nombre'] ?? '') . ' ' . ($pago['grado_nombre'] ?? '') . ' "' . ($pago['seccion_nombre'] ?? '') . '"');
$fechaPagoFormatted = date('d/m/Y', strtotime($pago['fecha_pago']));
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recibo de Pago - <?= htmlspecialchars($numeroRecibo) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="../assets/css/app.css">
    <link rel="stylesheet" href="../assets/css/reports-print.css">
    <link rel="stylesheet" href="../assets/css/recibo_pago.css">
</head>
<body>

    <!-- BARRA SUPERIOR DE ACCIONES (SOLO PANTALLA) -->
    <div class="no-print-toolbar">
        <div class="toolbar-title">
            <i class="bi bi-receipt"></i>
            <span>Comprobante de Pago: <strong><?= htmlspecialchars($numeroRecibo) ?></strong> &bull; S/ <?= number_format($montoAbonado, 2) ?></span>
        </div>
        <div class="toolbar-actions">
            <button type="button" class="btn btn-print" onclick="window.print();">
                <i class="bi bi-printer-fill"></i> Imprimir Recibo / Guardar PDF
            </button>
            <button type="button" class="btn btn-back" onclick="cerrarYRegresar('pagos.php');" title="Volver a Pagos y cerrar esta pestaña">
                <i class="bi bi-arrow-left"></i> Volver a Pagos
            </button>
            <button type="button" class="btn btn-close-red" onclick="cerrarEstaPestana();" title="Cerrar esta pestaña">
                <i class="bi bi-x-lg"></i> Cerrar Pestaña
            </button>
        </div>
    </div>

    <!-- HOJA A4 CON COMPROBANTE EN DOS COPIAS (CLIENTE Y CAJA) -->
    <div class="sheet-container">

        <?php for ($copia = 1; $copia <= 2; $copia++): ?>
            <?php $esOriginal = ($copia === 1); ?>
            <div class="voucher-card">
                <!-- Encabezado -->
                <div class="voucher-header">
                    <img class="voucher-logo" src="../assets/images/logo-institucion.jpg.jpg" alt="Logo Institución">
                    <div class="voucher-inst-info">
                        <div class="inst-name">I.E. &quot;Divino Maestro&quot;</div>
                        <div class="inst-meta">Mollepampa &bull; Cajamarca &bull; R.D. N° 0124-ED</div>
                        <div class="inst-meta">Recibo Oficial de Ingresos a Caja</div>
                    </div>
                    <div class="recibo-badge-box">
                        <div class="box-title">RECIBO DE CAJA</div>
                        <div class="box-number"><?= htmlspecialchars($numeroRecibo) ?></div>
                        <div class="copy-tag"><?= $esOriginal ? 'ORIGINAL: APODERADO' : 'COPIA: ARCHIVO CAJA' ?></div>
                    </div>
                </div>

                <!-- Datos del Pago -->
                <table class="voucher-table">
                    <tr>
                        <th>Fecha de Pago</th>
                        <td class="font-monospace fw-bold"><?= htmlspecialchars($fechaPagoFormatted) ?></td>
                        <th>Monto Abonado</th>
                        <td class="amount-highlight">S/ <?= number_format($montoAbonado, 2) ?></td>
                    </tr>
                    <tr>
                        <th>Estudiante</th>
                        <td colspan="3" class="fw-bold" style="color: var(--primary); font-size: 11px;">
                            <?= htmlspecialchars($nombreEstudiante ?: 'Sin estudiante') ?>
                            <?php if ($pago['estudiante_dni']): ?>
                                <span style="font-weight: normal; color: #475569; margin-left: 6px;">(DNI: <?= htmlspecialchars($pago['estudiante_dni']) ?>)</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th>Nivel / Grado / Sec.</th>
                        <td><?= htmlspecialchars($aulaTexto ?: 'No especificado') ?></td>
                        <th>Año Lectivo</th>
                        <td class="font-monospace"><?= htmlspecialchars((string) ($pago['anio'] ?? date('Y'))) ?></td>
                    </tr>
                    <tr>
                        <th>Entregado por</th>
                        <td class="fw-semibold"><?= htmlspecialchars($entregadoPor) ?></td>
                        <th>Apoderado Titular</th>
                        <td><?= htmlspecialchars($nombreApoderado) ?></td>
                    </tr>
                    <tr>
                        <th>Concepto de Cobro</th>
                        <td colspan="3" class="fw-bold">
                            <span style="background: #e2e8f0; padding: 1px 6px; border-radius: 4px;">
                                <?= htmlspecialchars($pago['concepto_nombre']) ?>
                            </span>
                        </td>
                    </tr>
                    <tr>
                        <th>Costo del Concepto</th>
                        <td>S/ <?= number_format($montoTotalConcepto, 2) ?></td>
                        <th>Saldo Pendiente</th>
                        <td>
                            <?php if ($saldoRestante > 0): ?>
                                <span class="fw-bold" style="color: #b91c1c;">S/ <?= number_format($saldoRestante, 2) ?> (Deuda pendiente)</span>
                            <?php else: ?>
                                <span class="fw-bold" style="color: #15803d;"><i class="bi bi-check-circle"></i> CANCELADO / PAGADO</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php if (!empty($pago['observacion'])): ?>
                    <tr>
                        <th>Observación</th>
                        <td colspan="3"><?= htmlspecialchars($pago['observacion']) ?></td>
                    </tr>
                    <?php endif; ?>
                </table>

                <!-- Monto en Letras -->
                <div class="words-box">
                    <i class="bi bi-cash-stack me-1"></i> <?= numero_a_letras($montoAbonado) ?>
                </div>

                <!-- Firmas -->
                <div class="voucher-signatures">
                    <div class="sig-block">
                        <div class="sig-line"></div>
                        <div class="sig-label">Entregué Conforme</div>
                        <div class="sig-sub">Firma de quien paga / DNI</div>
                    </div>
                    <div class="sig-block">
                        <div class="sig-line"></div>
                        <div class="sig-label">Recibí Conforme / Caja</div>
                        <div class="sig-sub">Cajero: <?= htmlspecialchars($pago['cajero_nombre'] ?? 'Administración') ?></div>
                    </div>
                </div>
            </div>

            <?php if ($esOriginal): ?>
                <div class="cut-line">
                    <span><i class="bi bi-scissors"></i> Línea de corte &bull; Conservar este recibo para cualquier reclamo</span>
                </div>
            <?php endif; ?>
        <?php endfor; ?>

    </div>

    <script src="../assets/js/recibo_pago.js" defer></script>
</body>
</html>
