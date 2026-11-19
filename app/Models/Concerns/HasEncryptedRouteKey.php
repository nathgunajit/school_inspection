<?php

namespace App\Models\Concerns;

use App\Support\UrlId;

/**
 * V6 Module 4 (URL encryption): the model appears in URLs as an encrypted token (App\Support\UrlId)
 * instead of its numeric ID. A plain number, an edited token or a token of another model type returns 404.
 * Database keys, foreign keys and the audit log keep the numeric ID.
 */
trait HasEncryptedRouteKey
{
    public function getRouteKey(): mixed
    {
        $key = $this->getKey();

        return $key === null ? null : UrlId::encode(static::class, $key);
    }

    /** The URL token for this model type and an ID, without loading the model. */
    public static function routeKeyFor(int|string $id): string
    {
        return UrlId::encode(static::class, $id);
    }

    public function resolveRouteBinding($value, $field = null)
    {
        if ($field !== null && $field !== $this->getKeyName()) {
            return parent::resolveRouteBinding($value, $field);
        }
        $id = UrlId::decode(static::class, is_string($value) ? $value : null);

        return $id === null ? null : $this->resolveRouteBindingQuery($this->newQuery(), $id, $this->getKeyName())->first();
    }

    public function resolveSoftDeletableRouteBinding($value, $field = null)
    {
        $id = UrlId::decode(static::class, is_string($value) ? $value : null);

        return $id === null ? null : $this->resolveRouteBindingQuery($this->newQuery()->withTrashed(), $id, $this->getKeyName())->first();
    }
}
