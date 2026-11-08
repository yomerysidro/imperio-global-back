<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Core\NetworkTreeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class NetworkGenealogyController extends Controller
{
    public function __construct(private NetworkTreeService $tree) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless(Auth::user()?->is_admin, 403);
        $input = $request->validate([
            'q' => 'sometimes|string|max:100',
            'page' => 'sometimes|integer|min:1',
            'perPage' => 'sometimes|integer|min:1|max:100',
        ]);
        $snapshot = $this->tree->snapshotFromRoot();
        $query = User::query()->orderBy('id');
        if (isset($input['q'])) {
            $query->where(fn ($q) => $q->where('name', 'like', '%'.$input['q'].'%')
                ->orWhere('uuid', 'like', '%'.$input['q'].'%'));
        }
        $page = $query->paginate($input['perPage'] ?? 50);
        $page->through(function (User $user) use ($snapshot) {
            $node = $snapshot['nodes'][strtoupper($user->uuid)] ?? null;
            return [
                'uuid' => $user->uuid, 'name' => $user->name,
                'active' => $user->active, 'package_name' => $user->package_name,
                'sponsor_code' => $this->tree->sponsorCode($user->uuid),
                'level' => $node['level'] ?? null, 'connected' => $node !== null,
            ];
        });
        return response()->json($page);
    }

    public function root(): JsonResponse
    {
        abort_unless(Auth::user()?->is_admin, 403);
        $root = app(\App\Services\Core\CorporateRootService::class)->user();
        return response()->json(['uuid' => $root->uuid, 'name' => $root->name]);
    }

    public function children(Request $request, string $userCode): JsonResponse
    {
        return $this->related($request, $userCode, 'children');
    }

    public function ancestors(Request $request, string $userCode): JsonResponse
    {
        return $this->related($request, $userCode, 'ancestors');
    }

    public function descendants(Request $request, string $userCode): JsonResponse
    {
        return $this->related($request, $userCode, 'descendants');
    }

    private function related(Request $request, string $userCode, string $mode): JsonResponse
    {
        abort_unless(Auth::user()?->is_admin, 403);
        $request->validate(['page' => 'sometimes|integer|min:1', 'perPage' => 'sometimes|integer|min:1|max:100']);
        $user = User::where('uuid', $userCode)->firstOrFail();
        $snapshot = $this->tree->snapshotFromRoot($user->uuid);
        if ($mode === 'ancestors') {
            $codes = []; $seen = [strtoupper($user->uuid) => true]; $cursor = $user->uuid;
            while ($parent = $this->tree->sponsorCode($cursor)) {
                abort_if(isset($seen[strtoupper($parent)]), 409, 'Ciclo en patrocinio.');
                $seen[strtoupper($parent)] = true; $codes[] = $parent; $cursor = $parent;
            }
        } else {
            $codes = $mode === 'children' ? $this->tree->directUserCodes($user->uuid)
                : array_values(array_filter(array_column($snapshot['nodes'], 'user_code'), fn ($code) => strcasecmp($code, $user->uuid) !== 0));
        }
        $profiles = User::whereIn('uuid', $codes)->get()->keyBy(fn ($u) => strtoupper($u->uuid));
        $rows = collect($codes)->map(function ($code) use ($profiles) {
            $u = $profiles->get(strtoupper($code));
            return ['uuid' => $code, 'name' => $u?->name, 'active' => $u?->active,
                'package_name' => $u?->package_name, 'sponsor_code' => $this->tree->sponsorCode($code)];
        });
        $perPage = (int) $request->input('perPage', 50); $page = (int) $request->input('page', 1);
        return response()->json(new \Illuminate\Pagination\LengthAwarePaginator(
            $rows->forPage($page, $perPage)->values(), $rows->count(), $perPage, $page
        ));
    }

    /** Read current genealogy by UUID; direct children are paginated. */
    public function show(Request $request, string $userCode): JsonResponse
    {
        if (!Auth::user()?->is_admin) {
            return response()->json(['message' => 'Solo administradores.'], 403);
        }

        $validator = Validator::make($request->query(), [
            'page' => ['sometimes', 'integer', 'min:1'],
            'perPage' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Parámetros inválidos.', 'errors' => $validator->errors()], 422);
        }

        try {
            $rootCode = $this->tree->rootCode();
            $snapshot = $this->tree->snapshotFromRoot($rootCode);
            $key = strtoupper(trim($userCode));
            $node = $snapshot['nodes'][$key] ?? null;
            if (!$node) {
                return response()->json(['message' => 'El socio no está conectado al árbol de la raíz corporativa.'], 404);
            }

            $route = [];
            $visited = [];
            $cursor = $key;
            while (isset($snapshot['nodes'][$cursor]) && !isset($visited[$cursor])) {
                $visited[$cursor] = true;
                $current = $snapshot['nodes'][$cursor];
                $route[] = [
                    'uuid' => $current['user_code'],
                    'name' => $current['user_name'],
                    'level' => $current['level'],
                    'sponsor_code' => $current['parent_code'],
                ];
                if (!$current['parent_code']) break;
                $cursor = strtoupper($current['parent_code']);
            }
            if (strtoupper((string) end($route)['uuid']) !== strtoupper($rootCode)) {
                return response()->json(['message' => 'La ruta ascendente no llega a la raíz corporativa.'], 409);
            }

            $descendantSet = [];
            $walk = function (string $parentKey) use (&$walk, &$descendantSet, $snapshot): void {
                foreach ($snapshot['nodes'][$parentKey]['children'] ?? [] as $childCode) {
                    $childKey = strtoupper((string) $childCode);
                    if (isset($descendantSet[$childKey]) || !isset($snapshot['nodes'][$childKey])) continue;
                    $descendantSet[$childKey] = true;
                    $walk($childKey);
                }
            };
            $walk($key);

            $directCodes = array_values($node['children']);
            $directTotal = count($directCodes);
            $page = (int) $request->query('page', 1);
            $perPage = (int) $request->query('perPage', 50);
            $pageCodes = array_slice($directCodes, ($page - 1) * $perPage, $perPage);
            $directChildren = array_map(function (string $code) use ($snapshot): array {
                $child = $snapshot['nodes'][strtoupper($code)];
                return [
                    'uuid' => $child['user_code'],
                    'name' => $child['user_name'],
                    'sponsor_code' => $child['parent_code'],
                    'level' => $child['level'],
                    'direct_children_count' => count($child['children']),
                ];
            }, $pageCodes);

            $sponsor = $node['parent_code']
                ? ($snapshot['nodes'][strtoupper($node['parent_code'])] ?? null)
                : null;
            $profile = User::where('uuid', $node['user_code'])->first();

            return response()->json([
                'user' => [
                    'uuid' => $node['user_code'],
                    'name' => $node['user_name'],
                    'registered_at' => $profile?->created_at,
                    'active' => $profile?->active,
                    'package_name' => $profile?->package_name,
                ],
                'sponsor' => $sponsor ? ['uuid' => $sponsor['user_code'], 'name' => $sponsor['user_name']] : null,
                'level' => $node['level'],
                'direct_children_total' => $directTotal,
                'descendant_count' => count($descendantSet),
                'descendants_count' => count($descendantSet),
                'direct_children' => [
                    'data' => $directChildren,
                    'page' => $page,
                    'per_page' => $perPage,
                    'total' => $directTotal,
                    'last_page' => max(1, (int) ceil($directTotal / $perPage)),
                ],
                'route_ascending' => $route,
                'integrity_warnings' => [
                    'unconnected_users_count' => count($snapshot['integrity']['unconnected_users'] ?? []),
                ],
            ]);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['message' => 'No fue posible consultar la genealogía.'], 500);
        }
    }
}
