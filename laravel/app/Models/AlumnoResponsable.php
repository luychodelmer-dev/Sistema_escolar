<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AlumnoResponsable extends Model
{
    protected $table = 'alumno_responsable';

    protected $fillable = [
        'alumno_id',
        'responsable_id',
        'parentesco',
        'tipo',
        'es_apoderado',
    ];

    protected $casts = [
        'es_apoderado' => 'boolean',
    ];

    public function alumno(): BelongsTo
    {
        return $this->belongsTo(Alumno::class);
    }

    public function responsable(): BelongsTo
    {
        return $this->belongsTo(Responsable::class);
    }
}
