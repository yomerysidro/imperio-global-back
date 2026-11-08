<?php

namespace App\Services\Core;

use App\Models\User;
use RuntimeException;

class CorporateRootService
{
    public function configuredUuid(): ?string
    {
        $uuid = trim((string) config('mlm.root_uuid', ''));
        return $uuid === '' ? null : $uuid;
    }

    public function uuid(): string
    {
        return $this->configuredUuid()
            ?? throw new RuntimeException('Configure MLM_ROOT_UUID para esta base de datos.');
    }

    public function isRootCode(?string $uuid): bool
    {
        $root = $this->configuredUuid();
        return $root !== null && $uuid !== null && strcasecmp($uuid, $root) === 0;
    }

    public function user(bool $forUpdate = false): User
    {
        $query = User::whereRaw('UPPER(uuid) = ?', [strtoupper($this->uuid())]);
        if ($forUpdate) $query->lockForUpdate();

        $user = $query->first()
            ?? throw new RuntimeException('MLM_ROOT_UUID no corresponde a un usuario existente.');
        if (\App\Models\SponsorRelation::where('user_code', $user->uuid)->where('state', true)->exists()) {
            throw new RuntimeException('La cuenta corporativa configurada tiene un patrocinador activo.');
        }
        return $user;
    }
}
