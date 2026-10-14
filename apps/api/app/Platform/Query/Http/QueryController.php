<?php

declare(strict_types=1);

namespace App\Platform\Query\Http;

use App\Platform\Access\Authorizer;
use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Query\Field;
use App\Platform\Query\QueryDefinition;
use App\Platform\Query\QueryRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use LogicException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * `GET /api/v1/query/{resource}` and `…/schema` (ADR-0008, doc 24 §3). Resources are registered in `config/eora.php`
 * (`queries`: name => QueryDefinition class) by the modules; an unknown name answers 404. Runs inside the `tenant`
 * middleware (one read-only TenantTransaction).
 */
final class QueryController
{
    public function __construct(private readonly QueryRunner $runner, private readonly Authorizer $authorizer) {}

    /**
     * List rows of a resource.
     *
     * Grammar (doc 24 §3.1): `filter[key]=value` or `filter[key][]=a&filter[key][]=b`, `q`, `sort=-a,b`,
     * `page[number]`, `page[size]`. Response `{data, meta: {total, page: {number, size}, asOf, timezone}}`.
     */
    public function index(Request $request, string $resource): JsonResponse
    {
        /** @var array<string, mixed> $input */
        $input = $request->query();

        return response()->json($this->runner->run($this->db(), $this->definition($resource), $input));
    }

    /** Filter, sort and search fields of a resource for the caller (403 without its view permission). */
    public function schema(string $resource): JsonResponse
    {
        $definition = $this->definition($resource);
        $this->authorizer->requireAnywhere($this->db(), $definition->permission());

        return response()->json(['data' => [
            'resource' => $definition->resource(),
            'fields' => array_map(static fn (Field $f) => $f->schema(), $definition->fields()),
            'defaultSort' => array_map(static fn (array $s) => ($s[1] ? '-' : '').$s[0], $definition->defaultSort()),
            'maxPageSize' => $definition->maxPageSize(),
        ]]);
    }

    private function definition(string $resource): QueryDefinition
    {
        /** @var array<string, class-string<QueryDefinition>> $registry */
        $registry = config('eora.queries', []);
        $class = $registry[$resource] ?? throw new NotFoundHttpException;

        return app($class);
    }

    private function db(): EoraPostgresConnection
    {
        $db = DB::connection();

        return $db instanceof EoraPostgresConnection ? $db : throw new LogicException('Queries require the EORA PostgreSQL connection.');
    }
}
