<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Alumno extends Model
{
    protected $table = 'alumnos';
    public $timestamps = false;

    protected $fillable = [
        'dni',
        'nombres',
        'apellido_paterno',
        'apellido_materno',
        'fecha_nacimiento',
    ];

    protected $casts = [
        'fecha_nacimiento' => 'date',
    ];

    public function responsables(): BelongsToMany
    {
        return $this->belongsToMany(Responsable::class, 'alumno_responsable')
            ->withPivot(['parentesco', 'tipo', 'es_apoderado'])
            ->withTimestamps();
    }

    public function apoderado(): BelongsToMany
    {
        return $this->responsables()->wherePivot('es_apoderado', true);
    }
}
