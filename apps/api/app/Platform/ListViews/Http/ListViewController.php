<?php

declare(strict_types=1);

namespace App\Platform\ListViews\Http;

use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Exceptions\DomainProblem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The signed-in user's saved list views (EORA-COMPONENT-STANDARDS §4): a named search, filters, sort and visible columns
 * for one list. Personal only; row-level security limits every query to the actor in the active tenant. A view stores
 * screen settings, never data: opening it runs the list again under the user's own scope.
 */
final class ListViewController
{
    /** Views of one list, by name. */
    public function index(Request $request): JsonResponse
    {
        $list = $request->validate(['list' => ['required', 'string', 'regex:/^[a-z0-9][a-z0-9.-]{0,79}$/']])['list'];
        $rows = $this->db()->select('select id, name, state, updated_at from list_views where list_key = ? order by lower(name), id', [$list]);

        return response()->json(['data' => array_map(self::view(...), $rows)]);
    }

    /** Saves a view; the same name on the same list replaces its settings. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'list' => ['required', 'string', 'regex:/^[a-z0-9][a-z0-9.-]{0,79}$/'],
            'name' => ['required', 'string', 'min:1', 'max:60'],
            'state' => ['required', 'array:search,filters,sort,columns'],
            'state.search' => ['nullable', 'string', 'max:100'],
            'state.filters' => ['nullable', 'array', 'max:20'],
            'state.filters.*' => ['nullable'],
            'state.sort' => ['nullable', 'array', 'max:3'],
            'state.sort.*.id' => ['required', 'string', 'max:40'],
            'state.sort.*.desc' => ['required', 'boolean'],
            'state.columns' => ['nullable', 'array', 'max:40'],
            'state.columns.*' => ['boolean'],
        ]);
        $name = trim((string) $data['name']);
        if ($name === '') {
            throw new DomainProblem(422, 'VALIDATION_FAILED', 'Name required', 'Enter a name for this view.');
        }
        $state = json_encode($data['state'], JSON_THROW_ON_ERROR);
        if (strlen($state) > 8000) {
            throw new DomainProblem(422, 'VALIDATION_FAILED', 'View too large', 'Save fewer filters in one view.');
        }
        $row = $this->db()->selectOne(
            'insert into list_views (tenant_id, user_id, list_key, name, state)
               values ((select c.tenant from ctx_api.context() c), (select c.actor from ctx_api.context() c), ?, ?, ?::jsonb)
             on conflict (tenant_id, user_id, list_key, name) do update set state = excluded.state
             returning id, name, state, updated_at',
            [$data['list'], $name, $state],
        );

        return response()->json(['data' => self::view($row)], 201);
    }

    /** Deletes one of the user's views. */
    public function destroy(string $view): JsonResponse
    {
        if (preg_match('/^[0-9a-f-]{36}$/i', $view) !== 1 || $this->db()->delete('delete from list_views where id = ?', [strtolower($view)]) === 0) {
            throw new DomainProblem(404, 'NOT_FOUND', 'Not found');
        }

        return response()->json(null, 204);
    }

    /** @return array{id: string, name: string, state: array<string, mixed>, updatedAt: string} */
    private static function view(\stdClass $row): array
    {
        /** @var array<string, mixed> $state */
        $state = json_decode((string) $row->state, true, 16, JSON_THROW_ON_ERROR);

        return ['id' => (string) $row->id, 'name' => (string) $row->name, 'state' => $state, 'updatedAt' => (string) $row->updated_at];
    }

    private function db(): EoraPostgresConnection
    {
        $db = DB::connection();

        return $db instanceof EoraPostgresConnection ? $db : throw new LogicException('Saved views require the EORA PostgreSQL connection.');
    }
}
