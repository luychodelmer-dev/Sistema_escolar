# Arquitectura actual

El proyecto usa una organización por responsabilidades, sin cambiar las URLs existentes:

```text
Sistema_escolar/
├── backend/              # Lógica común del servidor PHP tradicional
│   ├── seguridad.php     # Acceso, permisos y auditoría
│   ├── inicio.php        # Sesión y carga de dependencias
│   └── encabezado.php    # Encabezado institucional compartido
├── configuracion/        # Configuración de la aplicación
│   └── base_datos.php    # Creación centralizada de PDO
├── frontend/             # Guía del frontend
│   └── README.md
├── app/                  # Puentes de compatibilidad con nombres antiguos
├── config/               # Puente antiguo hacia configuracion/
├── database/
│   ├── permissions.sql          # Permisos por usuario
│   └── install_permissions.php  # Instalación inicial de permisos
├── public/               # Frontend visible para el navegador
│   ├── login.php         # Entrada pública y autenticación
│   ├── asistente/        # Páginas protegidas del asistente
│   │   ├── matriculas.php # Registro y consulta de matrículas
│   │   └── pagos.php      # Registro y consulta de pagos
│   └── assets/images/    # Imágenes públicas
├── login.php             # Puente de compatibilidad
├── conexion.php          # Puente antiguo hacia configuracion/base_datos.php
├── laravel/               # Nueva aplicación Laravel 12
│   ├── app/               # Modelos, controladores y middleware
│   ├── resources/views/   # Vistas Blade
│   ├── routes/web.php     # Rutas web y roles
│   └── public/            # Recursos públicos Laravel
└── .env.example
```

La versión Laravel se ejecuta desde `laravel/` en `http://127.0.0.1:8001`. La versión PHP anterior permanece disponible en el puerto 8000 como respaldo durante la migración.

## Configuración local

Copiar `config/database.local.php.example` como `config/database.local.php` y completar la contraseña de PostgreSQL, o definir las variables de `.env.example` en el entorno del servidor. El archivo local está excluido de Git. La conexión nueva se llama `configuracion/base_datos.php`; acepta también el archivo local antiguo para no romper instalaciones existentes.

## Próximos pasos recomendados

1. Mover el SQL de creación de tablas a `database/schema.sql` y ejecutarlo mediante una migración, en lugar de crear tablas durante una petición web.
2. Extraer la lógica de `public/asistente/padres.php`, `matriculas.php` y `pagos.php` a servicios/repositorios.
3. Mover los estilos y scripts embebidos a `public/assets/` cuando haya varias páginas que los compartan.
4. Añadir autorización por módulo y un panel para el rol `director` antes de habilitar esa redirección.

## Permisos y auditoría

El usuario con rol `director` es quien otorga o revoca permisos desde Laravel en `/director/permisos`. Los permisos actuales son `p_editar`, `p_matricular` y `p_cobrar`.

La tabla `auditoria_movimientos` ya existía en PostgreSQL. Ahora registra inicios de sesión, cambios de permisos, ediciones de apoderados, matrículas y pagos. El director puede consultar los movimientos desde `/director/auditoria`.

## Alumnos, padres y apoderados

La relación familiar se modela separando a la persona responsable del vínculo con el escolar:

```text
alumnos
	└── alumno_responsable
				├── responsable: padre, parentesco "Padre"
				├── responsable: madre, parentesco "Madre"
				└── responsable: tercero, parentesco "Abuela", "Tío", etc.
```

Un alumno puede tener ambos padres registrados y exactamente un responsable marcado como apoderado. El apoderado puede ser el padre, la madre o un tercero. La restricción de un solo apoderado se aplica en PostgreSQL mediante un índice único parcial.

El diseño está en `laravel/database/migrations/2026_09_11_000003_create_student_guardians_tables.php` y en `database/modelo_estudiantes_responsables.sql`. La pantalla Laravel de matrícula está en `/matriculas/crear`: exige los datos del alumno, padre y madre, permite elegir como apoderado al padre, madre o tercero, y exige el parentesco del tercero. El módulo de pagos está en `/pagos/crear` y relaciona cada pago con una matrícula y un concepto. No elimina ni modifica todavía las tablas antiguas; primero se debe migrar la información existente y luego retirar la relación antigua `alumnos.padre_id`.
