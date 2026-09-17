<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PaymentController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizePayment();
        $search = trim((string) $request->query('buscar', ''));
        $pagos = DB::table('pagos')
            ->join('matriculas', 'matriculas.id', '=', 'pagos.matricula_id')
            ->join('alumnos', 'alumnos.id', '=', 'matriculas.alumno_id')
            ->join('conceptos_pago', 'conceptos_pago.id', '=', 'pagos.concepto_id')
            ->when($search !== '', function ($builder) use ($search): void {
                $like = "%{$search}%";
                $builder->where(function ($filter) use ($like): void {
                    $filter->where('alumnos.dni', 'ilike', $like)
                        ->orWhere('alumnos.nombres', 'ilike', $like)
                        ->orWhere('alumnos.apellido_paterno', 'ilike', $like)
                        ->orWhere('conceptos_pago.descripcion', 'ilike', $like)
                        ->orWhere('pagos.estado_pago', 'ilike', $like);
                });
            })
            ->select('pagos.*', 'alumnos.dni', 'alumnos.nombres', 'alumnos.apellido_paterno', 'alumnos.apellido_materno', 'conceptos_pago.descripcion as concepto')
            ->orderByDesc('pagos.fecha_pago')
            ->orderByDesc('pagos.id')
            ->paginate(15)
            ->withQueryString();

        return view('payments.index', compact('pagos', 'search'));
    }

    public function create()
    {
        $this->authorizePayment();

        return view('payments.create', [
            'matriculas' => $this->activeEnrollments(),
            'conceptos' => DB::table('conceptos_pago')->orderBy('descripcion')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizePayment();

        $data = $request->validate([
            'matricula_id' => ['required', Rule::exists('matriculas', 'id')],
            'concepto_id' => ['required', Rule::exists('conceptos_pago', 'id')],
            'monto' => ['required', 'numeric', 'min:0.01'],
            'nro_recibo' => ['nullable', 'string', 'max:30'],
            'talonario' => ['nullable', 'string', 'max:30'],
            'estado_pago' => ['required', Rule::in(['pagado', 'pendiente', 'anulado'])],
            'fecha_pago' => ['required', 'date'],
        ]);

        $id = DB::table('pagos')->insertGetId([
            ...$data,
            'registrado_por' => auth()->id(),
            'creado_en' => now(),
        ]);

        DB::table('auditoria_movimientos')->insert([
            'usuario_id' => auth()->id(),
            'accion' => 'crear_pago',
            'detalles' => "Registró el pago ID {$id} por monto {$data['monto']}.",
            'ip_origen' => $request->ip(),
            'creado_en' => now(),
        ]);

        return to_route('payments.index')->with('success', 'Pago registrado correctamente.');
    }

    private function activeEnrollments()
    {
        return DB::table('matriculas')
            ->join('alumnos', 'alumnos.id', '=', 'matriculas.alumno_id')
            ->join('niveles', 'niveles.id', '=', 'matriculas.nivel_id')
            ->select('matriculas.id', 'matriculas.anio', 'matriculas.grado', 'matriculas.seccion', 'alumnos.dni', 'alumnos.nombres', 'alumnos.apellido_paterno', 'alumnos.apellido_materno', 'niveles.nombre as nivel')
            ->orderByDesc('matriculas.anio')
            ->orderBy('alumnos.apellido_paterno')
            ->get();
    }

    private function authorizePayment(): void
    {
        $user = auth()->user();
        $allowed = $user->rol === 'director' || DB::table('permisos_usuario')
            ->where('usuario_id', $user->id)
            ->where('permiso', 'p_cobrar')
            ->where('otorgado', true)
            ->exists();
        abort_unless($allowed, 403);
    }
}
