<?php

namespace App\Services\Core;

use App\Models\SponsorRelation;
use App\Models\User;
use RuntimeException;

class AffiliationService
{
    /** The corporate lock serializes registration, invitations and branch moves. */
    public function sponsor(string $code): User
    {
        app(NetworkTreeService::class)->lockRootForMutation();
        $sponsor = User::whereRaw('UPPER(uuid) = ?', [strtoupper(trim($code))])->lockForUpdate()->first();
        if (!$sponsor) throw new RuntimeException('El patrocinador no existe.');

        $cursor = $sponsor->uuid;
        $seen = [];
        $root = app(CorporateRootService::class)->uuid();
        while (strcasecmp($cursor, $root) !== 0) {
            $key = strtoupper($cursor);
            if (isset($seen[$key])) throw new RuntimeException('La cadena del patrocinador contiene un ciclo.');
            $seen[$key] = true;
            if (!User::where('uuid', $cursor)->lockForUpdate()->first(['id'])) throw new RuntimeException('La cadena contiene un usuario inexistente.');
            $parents = SponsorRelation::where('user_code', $cursor)->where('state', true)->lockForUpdate()->get();
            if ($parents->count() !== 1) throw new RuntimeException('El patrocinador no tiene una ruta unica a la raiz corporativa.');
            $cursor = $parents->first()->sponsor_code;
        }

        return $sponsor;
    }

    /** Never reactivate or replace an assigned parent implicitly. */
    public function attach(User $user, string $sponsorCode, string $source): SponsorRelation
    {
        $sponsor = $this->sponsor($sponsorCode);
        if (strcasecmp($user->uuid, $sponsor->uuid) === 0
            || app(CorporateRootService::class)->isRootCode($user->uuid)) {
            throw new RuntimeException('No se permite esta relacion de patrocinio.');
        }
        $relations = SponsorRelation::where('user_code', $user->uuid)->lockForUpdate()->get();
        if ($relations->isNotEmpty()) {
            $relation = $relations->first();
            if ($relations->count() === 1 && $relation->state
                && strcasecmp($relation->sponsor_code, $sponsor->uuid) === 0) return $relation;
            throw new RuntimeException('El socio ya tiene una relacion asignada; requiere revision administrativa.');
        }
        return SponsorRelation::create([
            'user_code' => $user->uuid, 'sponsor_code' => $sponsor->uuid,
            'source' => $source, 'state' => true,
        ]);
    }
}
