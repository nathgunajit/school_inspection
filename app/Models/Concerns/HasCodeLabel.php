<?php

namespace App\Models\Concerns;

/** District, Block and Cluster names are shown with their code: "KOKRAJHAR (1801)". */
trait HasCodeLabel
{
    public function getLabelAttribute(): string
    {
        return self::labelFor($this->name, $this->code);
    }

    public static function labelFor(?string $name, ?string $code): string
    {
        return trim((string) $name).($code !== null && $code !== '' ? " ({$code})" : '');
    }
}
