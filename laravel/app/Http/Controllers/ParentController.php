<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ParentController extends Controller
{
    public function index(Request $request)
    {
        $query = trim((string) $request->query('buscar', ''));
        $padres = DB::table('padres_apoderados')
            ->when($query !== '', function ($builder) use ($query): void {
                $like = "%{$query}%";
                $builder->where(function ($filter) use ($like): void {
                    $filter->where('dni', 'ilike', $like)
                        ->orWhere('nombres', 'ilike', $like)
                        ->orWhere('apellido_paterno', 'ilike', $like)
                        ->orWhere('apellido_materno', 'ilike', $like);
                });
            })
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        return view('parents.index', [
            'padres' => $padres,
            'buscar' => $query,
            'puedeEditar' => $this->canEdit(),
        ]);
    }

    public function create()
    {
        abort_unless($this->canEdit(), 403);

        return view('parents.create');
    }

    public function store(Request $request)
    {
        abort_unless($this->canEdit(), 403);

        $datos = $request->validate([
            'nombres' => ['required', 'string', 'max:100'],
            'apellido_paterno' => ['required', 'string', 'max:100'],
            'apellido_materno' => ['required', 'string', 'max:100'],
            'dni' => ['required', 'digits:8'],
            'direccion' => ['required', 'string', 'max:200'],
            'vinculo' => ['required', 'string', 'max:60'],
            'celular_actual' => ['nullable', 'string', 'max:20'],
            'celular_padre' => ['nullable', 'string', 'max:20'],
            'celular_madre' => ['nullable', 'string', 'max:20'],
            'numero_tarjeta' => ['nullable', 'string', 'max:30'],
            'cuota_apafa' => ['nullable', 'numeric', 'min:0'],
        ]);

        $id = DB::table('padres_apoderados')->insertGetId($datos);
        $this->audit('crear_apoderado', "Registró el apoderado ID {$id}.");

        return to_route('parents.index')->with('success', 'Apoderado registrado correctamente.');
    }

    public function edit(int $padre)
    {
        abort_unless($this->canEdit(), 403);
        $registro = DB::table('padres_apoderados')->where('id', $padre)->firstOrFail();

        return view('parents.edit', ['padre' => $registro]);
    }

    public function update(Request $request, int $padre)
    {
        abort_unless($this->canEdit(), 403);
        $datos = $request->validate([
            'direccion' => ['required', 'string', 'max:200'],
            'celular_actual' => ['nullable', 'string', 'max:20'],
            'celular_padre' => ['nullable', 'string', 'max:20'],
            'celular_madre' => ['nullable', 'string', 'max:20'],
        ]);

        DB::table('padres_apoderados')->where('id', $padre)->update($datos);
        $this->audit('editar_apoderado', "Actualizó dirección y teléfonos del apoderado ID {$padre}.");

        return to_route('parents.index')->with('success', 'Datos actualizados correctamente.');
    }

    private function canEdit(): bool
    {
        $user = auth()->user();

        return $user->rol === 'director' || DB::table('permisos_usuario')
            ->where('usuario_id', $user->id)
            ->where('permiso', 'p_editar')
            ->where('otorgado', true)
            ->exists();
    }

    private function audit(string $accion, string $detalles): void
    {
        DB::table('auditoria_movimientos')->insert([
            'usuario_id' => auth()->id(),
            'accion' => $accion,
            'detalles' => $detalles,
            'ip_origen' => request()->ip(),
            'creado_en' => now(),
        ]);
    }
}
