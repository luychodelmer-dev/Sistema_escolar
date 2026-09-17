<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Responsable extends Model
{
    protected $table = 'responsables';

    protected $fillable = [
        'dni',
        'nombres',
        'apellido_paterno',
        'apellido_materno',
        'direccion',
        'celular',
        'correo',
    ];

    public function alumnos(): BelongsToMany
    {
        return $this->belongsToMany(Alumno::class, 'alumno_responsable')
            ->withPivot(['parentesco', 'tipo', 'es_apoderado'])
            ->withTimestamps();
    }
}
