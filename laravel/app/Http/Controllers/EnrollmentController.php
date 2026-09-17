<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class EnrollmentController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizeEnrollment();
        $search = trim((string) $request->query('buscar', ''));
        $matriculas = DB::table('matriculas')
            ->join('alumnos', 'alumnos.id', '=', 'matriculas.alumno_id')
            ->join('niveles', 'niveles.id', '=', 'matriculas.nivel_id')
            ->when($search !== '', function ($builder) use ($search): void {
                $like = "%{$search}%";
                $builder->where(function ($filter) use ($like): void {
                    $filter->where('alumnos.dni', 'ilike', $like)
                        ->orWhere('alumnos.nombres', 'ilike', $like)
                        ->orWhere('alumnos.apellido_paterno', 'ilike', $like)
                        ->orWhere('alumnos.apellido_materno', 'ilike', $like);
                });
            })
            ->select('matriculas.*', 'alumnos.dni', 'alumnos.nombres', 'alumnos.apellido_paterno', 'alumnos.apellido_materno', 'niveles.nombre as nivel')
            ->orderByDesc('matriculas.anio')
            ->orderByDesc('matriculas.id')
            ->paginate(15)
            ->withQueryString();

        return view('enrollments.index', compact('matriculas', 'search'));
    }

    public function create()
    {
        $this->authorizeEnrollment();

        return view('enrollments.create', [
            'niveles' => DB::table('niveles')->orderBy('id')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeEnrollment();

        $data = $request->validate([
            'alumno_dni' => ['required', 'digits:8'],
            'alumno_nombres' => ['required', 'string', 'max:100'],
            'alumno_apellido_paterno' => ['required', 'string', 'max:100'],
            'alumno_apellido_materno' => ['required', 'string', 'max:100'],
            'fecha_nacimiento' => ['nullable', 'date'],
            'anio' => ['required', 'integer', 'min:2020', 'max:2100'],
            'nivel_id' => ['required', Rule::exists('niveles', 'id')],
            'grado' => ['required', 'integer', 'min:1', 'max:6'],
            'seccion' => ['required', 'string', 'max:10'],
            'padre_dni' => ['required', 'digits:8'],
            'padre_nombres' => ['required', 'string', 'max:100'],
            'padre_apellido_paterno' => ['required', 'string', 'max:100'],
            'padre_apellido_materno' => ['nullable', 'string', 'max:100'],
            'padre_celular' => ['nullable', 'string', 'max:20'],
            'madre_dni' => ['required', 'digits:8', 'different:padre_dni'],
            'madre_nombres' => ['required', 'string', 'max:100'],
            'madre_apellido_paterno' => ['required', 'string', 'max:100'],
            'madre_apellido_materno' => ['nullable', 'string', 'max:100'],
            'madre_celular' => ['nullable', 'string', 'max:20'],
            'apoderado_tipo' => ['required', Rule::in(['padre', 'madre', 'otro'])],
            'otro_dni' => ['required_if:apoderado_tipo,otro', 'nullable', 'digits:8'],
            'otro_nombres' => ['required_if:apoderado_tipo,otro', 'nullable', 'string', 'max:100'],
            'otro_apellido_paterno' => ['required_if:apoderado_tipo,otro', 'nullable', 'string', 'max:100'],
            'otro_apellido_materno' => ['nullable', 'string', 'max:100'],
            'otro_parentesco' => ['required_if:apoderado_tipo,otro', 'nullable', 'string', 'max:60'],
            'otro_celular' => ['nullable', 'string', 'max:20'],
        ], [
            'required_if.required' => 'Completa los datos del apoderado externo.',
            'madre_dni.different' => 'El DNI de la madre debe ser diferente al del padre.',
        ]);

        $matriculaId = DB::transaction(function () use ($data): int {
            $alumnoId = DB::table('alumnos')->insertGetId([
                'dni' => $data['alumno_dni'],
                'nombres' => $data['alumno_nombres'],
                'apellido_paterno' => $data['alumno_apellido_paterno'],
                'apellido_materno' => $data['alumno_apellido_materno'],
                'fecha_nacimiento' => $data['fecha_nacimiento'] ?? null,
                'creado_en' => now(),
            ]);

            $padreId = $this->createResponsible($data, 'padre');
            $madreId = $this->createResponsible($data, 'madre');
            $this->attachResponsible($alumnoId, $padreId, 'padre', 'Padre', $data['apoderado_tipo'] === 'padre');
            $this->attachResponsible($alumnoId, $madreId, 'madre', 'Madre', $data['apoderado_tipo'] === 'madre');

            if ($data['apoderado_tipo'] === 'otro') {
                $otroId = $this->createResponsible($data, 'otro');
                $this->attachResponsible($alumnoId, $otroId, 'otro', $data['otro_parentesco'], true);
            }

            return DB::table('matriculas')->insertGetId([
                'alumno_id' => $alumnoId,
                'anio' => $data['anio'],
                'nivel_id' => $data['nivel_id'],
                'grado' => $data['grado'],
                'seccion' => $data['seccion'],
                'es_alumno_nuevo' => true,
                'registrado_por' => auth()->id(),
                'creado_en' => now(),
            ]);
        });

        DB::table('auditoria_movimientos')->insert([
            'usuario_id' => auth()->id(),
            'accion' => 'crear_matricula',
            'detalles' => "Registró matrícula ID {$matriculaId} con padre, madre y apoderado definido.",
            'ip_origen' => $request->ip(),
            'creado_en' => now(),
        ]);

        return to_route('enrollments.index')->with('success', 'Matrícula registrada correctamente.');
    }

    private function createResponsible(array $data, string $type): int
    {
        $prefix = $type . '_';
        $responsibleId = DB::table('responsables')->where('dni', $data[$prefix . 'dni'])->value('id');
        if ($responsibleId) {
            return (int) $responsibleId;
        }

        return (int) DB::table('responsables')->insertGetId([
            'dni' => $data[$prefix . 'dni'],
            'nombres' => $data[$prefix . 'nombres'],
            'apellido_paterno' => $data[$prefix . 'apellido_paterno'],
            'apellido_materno' => $data[$prefix . 'apellido_materno'] ?? null,
            'celular' => $data[$prefix . 'celular'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function attachResponsible(int $alumnoId, int $responsibleId, string $type, string $relationship, bool $isGuardian): void
    {
        DB::table('alumno_responsable')->insert([
            'alumno_id' => $alumnoId,
            'responsable_id' => $responsibleId,
            'parentesco' => $relationship,
            'tipo' => $type,
            'es_apoderado' => $isGuardian,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function authorizeEnrollment(): void
    {
        $user = auth()->user();
        $allowed = $user->rol === 'director' || DB::table('permisos_usuario')
            ->where('usuario_id', $user->id)
            ->where('permiso', 'p_matricular')
            ->where('otorgado', true)
            ->exists();
        abort_unless($allowed, 403);
    }
}
