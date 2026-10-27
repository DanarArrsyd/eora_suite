<?php

declare(strict_types=1);

namespace App\Platform\Export;

use App\Platform\Access\Scope;
use App\Platform\Query\QueryDefinition;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The export rules of D-092 in one place: which lists can be exported (a QueryDefinition with export columns), the
 * row limit, the link and file lifetimes, and the list request kept with a job.
 */
final class Exports
{
    public const string DISK = 'exports';

    public const string PERMISSION = 'exchange.export.export';

    public static function maxRows(): int
    {
        return (int) config('eora.export.max_rows', 100_000);
    }

    public static function linkMinutes(): int
    {
        return (int) config('eora.export.link_minutes', 15);
    }

    public static function fileHours(): int
    {
        return (int) config('eora.export.file_hours', 24);
    }

    public static function tooMany(int $count): string
    {
        return sprintf('This list has %s rows; an export holds at most %s. Narrow the filter or the search.', number_format($count), number_format(self::maxRows()));
    }

    /** Storage folder of one job: every attempt writes here, cleanup removes the whole folder (no orphaned file). */
    public static function folder(string $tenant, string $job): string
    {
        return sprintf('tenant/%s/exports/%s', $tenant, $job);
    }

    /**
     * Scopes as kept with a job.
     *
     * @param  list<Scope>  $scopes
     * @return list<array{type: string, companyId: string|null, plantId: string|null}>
     */
    public static function scopes(array $scopes): array
    {
        return array_map(static fn (Scope $s) => ['type' => $s->type, 'companyId' => $s->companyId, 'plantId' => $s->plantId], $scopes);
    }

    /**
     * Whether $current still covers every scope a file was written with (a narrowed or lost scope refuses the file).
     *
     * @param  list<array{type: string, companyId: string|null, plantId: string|null}>  $written
     * @param  list<Scope>  $current
     */
    public static function covered(array $written, array $current): bool
    {
        foreach ($written as $w) {
            $scope = Scope::of($w['type'], $w['companyId'], $w['plantId'], $w['companyId']);
            if (! array_filter($current, static fn (Scope $c) => $c->covers($scope))) {
                return false;
            }
        }

        return true;
    }

    /** A list that can be exported: 404 for an unknown resource, 422 for one without export columns. */
    public function definition(string $resource): QueryDefinition
    {
        /** @var array<string, class-string<QueryDefinition>> $registry */
        $registry = config('eora.queries', []);
        $class = $registry[$resource] ?? throw new NotFoundHttpException;
        $definition = app($class);
        if ($definition->exportColumns() === []) {
            throw ValidationException::withMessages(['resource' => 'This list cannot be exported.']);
        }

        return $definition;
    }

    /**
     * The list request as it is kept with a job and replayed by the worker.
     *
     * @return array{filter: array<string, mixed>, q: string, sort: string|null}
     */
    public static function input(mixed $filter, mixed $q, mixed $sort): array
    {
        return [
            'filter' => is_array($filter) ? $filter : [],
            'q' => is_string($q) ? trim($q) : '',
            'sort' => is_string($sort) && trim($sort) !== '' ? trim($sort) : null,
        ];
    }
}
