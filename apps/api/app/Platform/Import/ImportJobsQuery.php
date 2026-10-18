<?php

declare(strict_types=1);

namespace App\Platform\Import;

use App\Platform\Query\Field;
use App\Platform\Query\QueryDefinition;

/**
 * `exchange.import_jobs` — the tenant's import jobs, newest first (doc 29 §1.6, D-081). Visible to whoever may upload
 * (`exchange.import.create`; the CSA template, which commits, holds it too since D-081). Every job stays in history.
 */
final class ImportJobsQuery extends QueryDefinition
{
    public const array STATUSES = ['RESERVED', 'VALIDATING', 'VALIDATED', 'SUBMITTED', 'COMMITTING', 'COMMITTED', 'REJECTED', 'FAILED'];

    public function resource(): string
    {
        return 'exchange.import_jobs';
    }

    public function permission(): string
    {
        return 'exchange.import.create';
    }

    public function from(): string
    {
        return 'import_jobs j
                 left join users uc on uc.id = j.created_by_user
                 left join users us on us.id = j.submitted_by
                 left join users ux on ux.id = j.committed_by';
    }

    public function idColumn(): string
    {
        return 'j.id';
    }

    public function fields(): array
    {
        // an expired worker lease reads as FAILED (WORKER_LOST) before the next write records it (ImportLease::reapIfLost)
        $lost = "(j.status in ('VALIDATING', 'COMMITTING') and j.lease_until < now())";

        return [
            new Field('id', 'j.id', Field::UUID, filterable: true),
            new Field('template', 'j.template', filterable: true),
            new Field('templateVersion', 'j.template_version', Field::INTEGER),
            new Field('fileName', 'j.file_name', search: 'contains'),
            new Field('fileSize', 'j.file_size', Field::INTEGER),
            new Field('status', "case when {$lost} then 'FAILED' else j.status end", Field::ENUM, sortable: true, filterable: true, options: self::STATUSES),
            new Field('failureCode', "case when {$lost} then 'WORKER_LOST' else j.failure_code end"),
            new Field('failureDetail', "case when {$lost} then 'The worker stopped before finishing. Upload the same file again to retry.' else j.failure_detail end"),
            new Field('rejectReason', 'j.reject_reason'),
            new Field('retryOfJobId', 'j.retry_of_job_id', Field::UUID),
            new Field('rootJobId', 'j.root_job_id', Field::UUID, filterable: true),
            new Field('hasErrorFile', '(j.error_file_key is not null)', Field::BOOLEAN),
            new Field('totalRows', 'j.total_rows', Field::INTEGER),
            new Field('validRows', 'j.valid_rows', Field::INTEGER),
            new Field('errorRows', 'j.error_rows', Field::INTEGER),
            new Field('skippedRows', 'j.skipped_rows', Field::INTEGER),
            new Field('committedRows', 'j.committed_rows', Field::INTEGER),
            new Field('uploadedByName', 'uc.display_name'),
            new Field('submittedByName', 'us.display_name'),
            new Field('submittedAt', 'j.submitted_at', Field::INSTANT),
            new Field('committedByName', 'ux.display_name'),
            new Field('committedAt', 'j.committed_at', Field::INSTANT),
            new Field('createdAt', 'j.created_at', Field::INSTANT, sortable: true),
            new Field('updatedAt', 'j.updated_at', Field::INSTANT),
            new Field('version', 'j.version', Field::INTEGER),
        ];
    }

    public function defaultSort(): array
    {
        return [['createdAt', true]];
    }
}
