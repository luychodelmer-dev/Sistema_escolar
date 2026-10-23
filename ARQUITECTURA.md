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
└── .env.example
```

La aplicación se ejecuta con el servidor PHP integrado desde `public/` en `http://127.0.0.1:8000`.

## Configuración local

Copiar `config/database.local.php.example` como `config/database.local.php` y completar la contraseña de PostgreSQL, o definir las variables de `.env.example` en el entorno del servidor. El archivo local está excluido de Git. La conexión nueva se llama `configuracion/base_datos.php`; acepta también el archivo local antiguo para no romper instalaciones existentes.

## Ejecución local

Desde la raíz del proyecto:

```powershell
php -S 127.0.0.1:8000 -t public
```

## Permisos y auditoría

El usuario con rol `director` es quien otorga o revoca permisos desde `public/director/permisos.php`. Los permisos actuales son `p_editar`, `p_matricular` y `p_cobrar`.

La tabla `auditoria_movimientos` ya existía en PostgreSQL. Ahora registra inicios de sesión, cambios de permisos, ediciones de apoderados, matrículas y pagos. El director puede consultar los movimientos desde `/director/auditoria`.

## Estudiantes, padres y apoderados

La relación familiar se modela separando a la persona responsable del vínculo con el escolar:

```text
estudiantes
	└── estudiante_responsable
				├── responsable: padre, parentesco "Padre"
				├── responsable: madre, parentesco "Madre"
				└── responsable: tercero, parentesco "Abuela", "Tío", etc.
```

Un estudiante puede tener ambos padres registrados y exactamente un responsable marcado como apoderado. El apoderado puede ser el padre, la madre o un tercero. La restricción de un solo apoderado se aplica en PostgreSQL mediante un índice único parcial.

El diseño de responsables está en `database/modelo_estudiantes_responsables.sql`. Las matrículas permiten registrar padres o contactos de emergencia y relacionan el grado y la sección mediante `grado_seccion`. El módulo de pagos relaciona cada pago con una matrícula y un concepto.
