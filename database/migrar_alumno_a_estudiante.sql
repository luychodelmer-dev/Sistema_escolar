BEGIN;

DO $$
BEGIN
    IF to_regclass('public.alumno') IS NOT NULL AND to_regclass('public.estudiante') IS NULL THEN
        ALTER TABLE public.alumno RENAME TO estudiante;
    END IF;

    IF to_regclass('public.alumno_responsable') IS NOT NULL AND to_regclass('public.estudiante_responsable') IS NULL THEN
        ALTER TABLE public.alumno_responsable RENAME TO estudiante_responsable;
    END IF;

    IF to_regclass('public.alumno_padre') IS NOT NULL AND to_regclass('public.estudiante_padre') IS NULL THEN
        ALTER TABLE public.alumno_padre RENAME TO estudiante_padre;
    END IF;

    IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'matricula' AND column_name = 'alumno_id')
       AND NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'matricula' AND column_name = 'estudiante_id') THEN
        ALTER TABLE public.matricula RENAME COLUMN alumno_id TO estudiante_id;
    END IF;

    IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'estudiante_responsable' AND column_name = 'alumno_id')
       AND NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'estudiante_responsable' AND column_name = 'estudiante_id') THEN
        ALTER TABLE public.estudiante_responsable RENAME COLUMN alumno_id TO estudiante_id;
    END IF;

    IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'estudiante_padre' AND column_name = 'alumno_id')
       AND NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'estudiante_padre' AND column_name = 'estudiante_id') THEN
        ALTER TABLE public.estudiante_padre RENAME COLUMN alumno_id TO estudiante_id;
    END IF;

    IF to_regclass('public.alumno_id_seq') IS NOT NULL AND to_regclass('public.estudiante_id_seq') IS NULL THEN
        ALTER SEQUENCE public.alumno_id_seq RENAME TO estudiante_id_seq;
    END IF;

    IF to_regclass('public.matricula_alumno_anio_unique') IS NOT NULL AND to_regclass('public.matricula_estudiante_anio_unique') IS NULL THEN
        ALTER INDEX public.matricula_alumno_anio_unique RENAME TO matricula_estudiante_anio_unique;
    END IF;
    IF to_regclass('public.matricula_alumno_anio_id_unique') IS NOT NULL AND to_regclass('public.matricula_estudiante_anio_id_unique') IS NULL THEN
        ALTER INDEX public.matricula_alumno_anio_id_unique RENAME TO matricula_estudiante_anio_id_unique;
    END IF;
    IF to_regclass('public.alumno_responsable_alumno_tipo_idx') IS NOT NULL AND to_regclass('public.estudiante_responsable_estudiante_tipo_idx') IS NULL THEN
        ALTER INDEX public.alumno_responsable_alumno_tipo_idx RENAME TO estudiante_responsable_estudiante_tipo_idx;
    END IF;
    IF to_regclass('public.alumno_unico_padre_idx') IS NOT NULL AND to_regclass('public.estudiante_unico_padre_idx') IS NULL THEN
        ALTER INDEX public.alumno_unico_padre_idx RENAME TO estudiante_unico_padre_idx;
    END IF;
    IF to_regclass('public.alumno_unica_madre_idx') IS NOT NULL AND to_regclass('public.estudiante_unica_madre_idx') IS NULL THEN
        ALTER INDEX public.alumno_unica_madre_idx RENAME TO estudiante_unica_madre_idx;
    END IF;
    IF to_regclass('public.alumno_unico_apoderado_idx') IS NOT NULL AND to_regclass('public.estudiante_unico_apoderado_idx') IS NULL THEN
        ALTER INDEX public.alumno_unico_apoderado_idx RENAME TO estudiante_unico_apoderado_idx;
    END IF;
    IF to_regclass('public.alumno_padre_alumno_tipo_idx') IS NOT NULL AND to_regclass('public.estudiante_padre_estudiante_tipo_idx') IS NULL THEN
        ALTER INDEX public.alumno_padre_alumno_tipo_idx RENAME TO estudiante_padre_estudiante_tipo_idx;
    END IF;
END $$;

DO $$
DECLARE
    restriccion RECORD;
BEGIN
    FOR restriccion IN
        SELECT * FROM (VALUES
            ('estudiante', 'alumno_pkey', 'estudiante_pkey'),
            ('estudiante', 'alumnos_padre_id_fkey', 'estudiantes_padre_id_fkey'),
            ('matricula', 'matricula_alumno_id_fkey', 'matricula_estudiante_id_fkey'),
            ('estudiante_responsable', 'alumno_responsable_pkey', 'estudiante_responsable_pkey'),
            ('estudiante_responsable', 'alumno_responsable_alumno_id_fkey', 'estudiante_responsable_estudiante_id_fkey'),
            ('estudiante_responsable', 'alumno_responsable_responsable_id_fkey', 'estudiante_responsable_responsable_id_fkey'),
            ('estudiante_responsable', 'alumno_responsable_tipo_check', 'estudiante_responsable_tipo_check'),
            ('estudiante_responsable', 'alumno_responsable_alumno_id_responsable_id_key', 'estudiante_responsable_estudiante_id_responsable_id_key'),
            ('estudiante_padre', 'alumno_padre_pkey', 'estudiante_padre_pkey'),
            ('estudiante_padre', 'alumno_padre_alumno_id_fkey', 'estudiante_padre_estudiante_id_fkey'),
            ('estudiante_padre', 'alumno_padre_padre_id_fkey', 'estudiante_padre_padre_id_fkey'),
            ('estudiante_padre', 'alumno_padre_alumno_id_padre_id_key', 'estudiante_padre_estudiante_id_padre_id_key')
        ) AS nombres(tabla, anterior, nuevo)
    LOOP
        IF EXISTS (
            SELECT 1 FROM pg_constraint
            WHERE conrelid = to_regclass('public.' || restriccion.tabla)
              AND conname = restriccion.anterior
        ) AND NOT EXISTS (
            SELECT 1 FROM pg_constraint
            WHERE conrelid = to_regclass('public.' || restriccion.tabla)
              AND conname = restriccion.nuevo
        ) THEN
            EXECUTE format(
                'ALTER TABLE public.%I RENAME CONSTRAINT %I TO %I',
                restriccion.tabla, restriccion.anterior, restriccion.nuevo
            );
        END IF;
    END LOOP;
END $$;

COMMIT;
