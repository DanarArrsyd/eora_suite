<?php

declare(strict_types=1);

namespace App\Platform\Export;

use App\Platform\Query\Field;
use App\Platform\Query\QueryDefinition;

/**
 * `exchange.export_jobs` — the caller's own exports, newest first (D-092). RLS shows a job to its requester only: the
 * file holds what that person may see.
 */
final class ExportJobsQuery extends QueryDefinition
{
    public const array STATUSES = ['QUEUED', 'RUNNING', 'DONE', 'FAILED', 'EXPIRED'];

    public function resource(): string
    {
        return 'exchange.export_jobs';
    }

    public function title(): string
    {
        return 'Exports';
    }

    public function permission(): string
    {
        return 'exchange.export.export';
    }

    public function from(): string
    {
        return 'export_jobs j';
    }

    public function idColumn(): string
    {
        return 'j.id';
    }

    public function fields(): array
    {
        return [
            new Field('id', 'j.id', Field::UUID, filterable: true),
            new Field('resource', 'j.resource', filterable: true),
            new Field('title', "j.query->>'title'"),
            new Field('query', 'j.query::text'),
            new Field('format', 'j.format'),
            new Field('status', 'j.status', Field::ENUM, sortable: true, filterable: true, options: self::STATUSES),
            new Field('failureCode', 'j.failure_code'),
            new Field('failureDetail', 'j.failure_detail'),
            new Field('rowCount', 'j.row_count', Field::INTEGER),
            new Field('fileName', 'j.file_name', search: 'contains'),
            new Field('fileSize', 'j.file_size', Field::INTEGER),
            new Field('startedAt', 'j.started_at', Field::INSTANT),
            new Field('finishedAt', 'j.finished_at', Field::INSTANT),
            new Field('expiresAt', 'j.expires_at', Field::INSTANT),
            new Field('createdAt', 'j.created_at', Field::INSTANT, sortable: true),
            new Field('version', 'j.version', Field::INTEGER),
        ];
    }

    public function defaultSort(): array
    {
        return [['createdAt', true]];
    }

    public function row(\stdClass $row): array
    {
        $out = (array) $row;
        $out['query'] = json_decode((string) $row->query, true, flags: JSON_THROW_ON_ERROR);

        return $out;
    }
}
