# Arquitectura del Sistema Escolar

El proyecto cuenta con una arquitectura optimizada, limpia y profesional organizada por responsabilidades, eliminando capas redundantes y archivos puente innecesarios.

```text
Sistema_escolar/
├── app/                          # Núcleo de la aplicación / Servicios Backend
│   ├── auth.php                  # Control de acceso, roles, permisos y auditoría
│   ├── bootstrap.php             # Inicializador de sesión, timezone y conexión
│   ├── dashboard_metrics.php     # Métricas y cálculos del panel principal
│   └── header.php                # Barra lateral persistente y cabecera global
├── config/                       # Configuración del sistema
│   ├── database.php              # Configuración y creación centralizada de PDO
│   └── database.local.php        # Credenciales locales (ignorado en Git)
├── database/                     # Esquemas SQL, migraciones y scripts
│   ├── schema.sql                # Esquema principal de la base de datos
│   ├── permissions.sql           # Catálogo de permisos
│   └── ...                       # Scripts de migración y auditoría
├── public/                       # Document Root accesible por el servidor web
│   ├── index.php                 # Punto de entrada (redirección a login)
│   ├── login.php                 # Pantalla de inicio de sesión
│   ├── recuperar_password.php    # Flujo de recuperación de contraseña
│   ├── assets/                   # Recursos estáticos separados
│   │   ├── css/
│   │   │   ├── app.css           # Estilos globales, sidebar, tablas, tarjetas, componentes
│   │   │   ├── auth.css          # Estilos de autenticación (login y recuperación)
│   │   │   └── reports-print.css # Estilos para reportes impresos A4 y recibos
│   │   ├── js/
│   │   │   └── app_filtros.js    # Filtrado dinámico en vivo en tablas
│   │   └── images/               # Imágenes institucionales y logotipos
│   ├── asistente/                # Módulos y vistas para el rol Asistente
│   └── director/                 # Módulos y vistas para el rol Director
├── .env.example                  # Plantilla de variables de entorno
└── ARQUITECTURA.md               # Esta documentación
```

## Configuración y Ejecución

### Configuración local
Crear `config/database.local.php` a partir de las credenciales de PostgreSQL o configurar las variables de entorno detalladas en `.env.example`. El archivo `config/database.local.php` está excluido de Git para seguridad.

### Ejecución local
Desde la raíz del proyecto:

```powershell
php -S 127.0.0.1:8000 -t public
```

La aplicación se ejecuta sirviendo exclusivamente el directorio `public/` como raíz web.

---

## Separación de Estilos (CSS 1:1 por Módulo)

Los estilos CSS han sido extraídos al 100% de los archivos PHP y organizados dentro de `public/assets/css/`, vinculando cada pantalla a su propia hoja de estilos dedicada (`<link rel="stylesheet" href="../assets/css/{modulo}.css">`):

* **Hojas Globales y Base:**
  * `app.css`: Variables CSS del sistema (`--app-*`), reset, barra lateral persistente (`.app-sidebar`), topbar sticky y componentes compartidos.
  * `auth.css`: Estilos base de autenticación.
  * `reports-print.css`: Ajustes globales para impresión en hoja A4 (`@media print`).

* **Hojas Modulares Específicas (1 por cada archivo PHP):**
  * `login.css` &rarr; `login.php`
  * `recuperar_password.css` &rarr; `recuperar_password.php`
  * `panel.css` &rarr; `asistente/panel.php` y `director/panel.php`
  * `estudiantes.css` &rarr; `asistente/estudiantes.php`
  * `ver_estudiante.css` &rarr; `asistente/ver_estudiante.php`
  * `editar_estudiante.css` &rarr; `asistente/editar_estudiante.php`
  * `matriculas.css` &rarr; `asistente/matriculas.php`
  * `ficha_matricula.css` &rarr; `asistente/ficha_matricula.php`
  * `nomina_matriculas.css` &rarr; `asistente/nomina_matriculas.php`
  * `padres.css` &rarr; `asistente/padres.php`
  * `ver_padre.css` &rarr; `asistente/ver_padre.php`
  * `nuevo_padre.css` &rarr; `asistente/nuevo_padre.php`
  * `padron_apoderados.css` &rarr; `asistente/padron_apoderados.php`
  * `cursos_periodos.css` &rarr; `asistente/cursos_periodos.php`
  * `calificaciones.css` &rarr; `asistente/calificaciones.php`
  * `reporte_calificaciones.css` &rarr; `asistente/reporte_calificaciones.php`
  * `cierre_academico.css` &rarr; `asistente/cierre_academico.php`
  * `pagos.css` &rarr; `asistente/pagos.php`
  * `recibo_pago.css` &rarr; `asistente/recibo_pago.php`
  * `reporte_pagos.css` &rarr; `asistente/reporte_pagos.php`
  * `permisos.css` &rarr; `director/permisos.php`
  * `auditoria.css` &rarr; `director/auditoria.php`

---

## Separación de Scripts (JavaScript 1:1 por Módulo)

El 100% del código JavaScript ejecutable ha sido extraído de las vistas y componentes PHP hacia archivos modulares independientes ubicados en `public/assets/js/`. Los datos dinámicos generados por PHP (configuraciones, datasets de gráficas) se transfieren de forma segura mediante bloques estándar `<script id="..." type="application/json">` o atributos de datos:

* **Scripts Globales y Layout:**
  * `header.js` &rarr; Menú lateral responsive móvil y dropdown de usuario en `app/header.php`.
  * `app_filtros.js` &rarr; Motor de filtrado en vivo por AJAX para tablas de reportes y consultas.

* **Scripts Modulares por Vista (1 por cada archivo PHP):**
  * `login.js` &rarr; `login.php` (alternancia de visibilidad de contraseña).
  * `recuperar_password.js` &rarr; `recuperar_password.php` (alternancia de visibilidad de contraseñas).
  * `estudiantes.js` &rarr; `asistente/estudiantes.php` (filtros dinámicos, pestañas de estado académico, cambio de estado en lote, eliminación asistida).
  * `editar_estudiante.js` &rarr; `asistente/editar_estudiante.php` (carga dinámica dependiente de grados y secciones).
  * `matriculas.js` &rarr; `asistente/matriculas.php` (búsqueda en vivo de estudiantes, detección de hermanos/familias, precarga de historial académico y asignación de vacantes).
  * `padres.js` &rarr; `asistente/padres.php` (búsqueda en vivo de familias, asignación y edición de apoderados).
  * `cursos_periodos.js` &rarr; `asistente/cursos_periodos.php` (filtrado en vivo de asignaturas, vista previa de mallas curriculares y modales de sección).
  * `calificaciones.js` &rarr; `asistente/calificaciones.php` (registro reactivo de notas, cálculo de promedios, gráfica Chart.js y sincronización AJAX).
  * `reporte_calificaciones.js` &rarr; `asistente/reporte_calificaciones.php` (gráfica consolidada multiserie Chart.js con dataLabels y filtrado reactivo de nivel/grado).
  * `cierre_academico.js` &rarr; `asistente/cierre_academico.php` (control de fases de cierre anual y vacacional).
  * `pagos.js` &rarr; `asistente/pagos.php` (autocompletado de alumnos, consulta de saldos en tiempo real, desglose de abonos y modal de conceptos).
  * `reporte_pagos.js` &rarr; `asistente/reporte_pagos.php` (recalculo instantáneo de totales recaudados y pendientes al filtrar, sincronizado con `app_filtros.js`).
  * `ficha_matricula.js` &rarr; `asistente/ficha_matricula.php` (control de navegación y cierre seguro de pestañas de impresión).
  * `ficha_padre.js` &rarr; `asistente/ficha_padre.php` (control de navegación y cierre seguro de pestañas de impresión).
  * `recibo_pago.js` &rarr; `asistente/recibo_pago.php` (control de navegación y cierre seguro de pestañas de impresión).
  * `nomina_matriculas.js` &rarr; `asistente/nomina_matriculas.php` (filtro reactivo de grados por nivel educativo).
  * `padron_apoderados.js` &rarr; `asistente/padron_apoderados.php` (filtro reactivo de grados por nivel educativo).
  * `auditoria.js` &rarr; `director/auditoria.php` (filtrado de eventos en vivo y gráfica Chart.js de actividad por hora/usuario).
  * `permisos.js` &rarr; `director/permisos.php` (generador de contraseñas seguras, copia al portapapeles y modal de reseteo).

---

## Permisos Granulares y Auditoría

El usuario con rol `director` es quien otorga o revoca permisos granulares desde `public/director/permisos.php`. El sistema cuenta con un catálogo estructurado por áreas con retrocompatibilidad automática:

1. **Estudiantes y Familias:**
   * `p_estudiantes`: Crear, editar alumnos y consultar expediente 360°.
   * `p_padres`: Registrar familias, vincular apoderado único y controlar tarjeta APAFA (incluye compatibilidad con `p_editar`).
2. **Matrículas y Plan Académico:**
   * `p_matricular`: Registrar matrículas escolares, asignar grado/sección y emitir FUM/Nóminas.
   * `p_cursos_periodos`: Configurar asignaturas escolares, periodos académicos y secciones.
3. **Evaluaciones y Calificaciones:**
   * `p_calificar`: Ingresar y actualizar notas literales oficiales (AD, A, B, C) por periodo lectivo.
   * `p_cierre_academico`: Promociones de grado y clausura oficial del año escolar.
4. **Caja y Finanzas:**
   * `p_cobrar`: Registrar cobros de matrícula, cuotas de APAFA y emitir recibos oficiales.
   * `p_reportes_caja`: Consultar balance consolidado de caja, liquidación diaria y arqueo.
5. **Seguridad y Control:**
   * `p_auditoria`: Supervisar bitácora de eventos y movimientos de seguridad.

La pantalla de permisos incluye **plantillas rápidas (presets de 1 clic)** para roles institucionales (*Docente, Caja / APAFA, Secretaría, Coordinación*).

La eliminación de pagos realizados es una facultad exclusiva del usuario principal (Director/Administrador); ningún asistente puede eliminar cobros.

La tabla `auditoria_movimiento` en PostgreSQL registra inicios de sesión, cambios de permisos, ediciones de apoderados, matrículas, pagos y eliminaciones de pagos (`eliminar_pago`). El director puede consultar los movimientos desde `/director/auditoria`.

---

## Estudiantes, Padres y Apoderados

La relación familiar se modela separando a la persona responsable del vínculo con el escolar:

```text
estudiantes
	└── estudiante_responsable
				├── responsable: padre, parentesco "Padre"
				├── responsable: madre, parentesco "Madre"
				└── responsable: tercero, parentesco "Abuela", "Tío", etc.
```

Un estudiante puede tener ambos padres registrados y exactamente un responsable marcado como apoderado. El apoderado puede ser el padre, la madre o un tercero. La restricción de un solo apoderado se aplica en PostgreSQL mediante un índice único parcial.

---

## Reportes Oficiales (Impresión / PDF)

1. **Ficha Única de Matrícula (`public/asistente/ficha_matricula.php`):**
   - Constancia individual membretada en A4 con datos del estudiante, apoderado, declaración jurada y firmas.
2. **Nómina Oficial de Matrícula (`public/asistente/nomina_matriculas.php`):**
   - Padrón de aula filtrable por año, nivel, grado y sección.
3. **Padrón Oficial de Padres y Apoderados (`public/asistente/padron_apoderados.php`):**
   - Formato A4 apaisado para asambleas de APAFA y control de firmas.
4. **Ficha Familiar y de Apoderado (`public/asistente/ficha_padre.php`):**
   - Constancia individual por código de familia (`FAM-XXXXX`).
5. **Recibo Oficial de Ingreso a Caja (`public/asistente/recibo_pago.php`):**
   - Comprobante con doble talón (Original Apoderado y Copia Caja) y monto en letras.
6. **Reporte Consolidado de Caja y Pagos (`public/asistente/reporte_pagos.php`):**
   - Arqueo y liquidación en A4 apaisado con totales recaudados y pendientes.
