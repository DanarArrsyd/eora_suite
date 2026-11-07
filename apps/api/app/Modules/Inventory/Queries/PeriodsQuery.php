<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Queries;

use App\Platform\Export\ExportColumn;
use App\Platform\Query\Field;
use App\Platform\Query\QueryDefinition;

/**
 * `inventory.periods` — monthly inventory periods per plant (M3-D8, BR-PER-01/03, D-102) with their close and reopen
 * requests and the requests of the month still waiting for a decision. Read by those who view, close or reopen periods,
 * inside the plants of those scopes.
 */
final class PeriodsQuery extends QueryDefinition
{
    public const array STATUSES = ['OPEN', 'CLOSING', 'CLOSED'];

    public function resource(): string
    {
        return 'inventory.periods';
    }

    public function permission(): string
    {
        return 'inventory.period_close.view';
    }

    public function permissions(): array
    {
        return ['inventory.period_close.view', 'inventory.period_close.post', 'inventory.period_close.approve',
            'inventory.period_reopen.view', 'inventory.period_reopen.request_correction', 'inventory.period_reopen.approve'];
    }

    public function from(): string
    {
        return 'inventory_periods ip
                 join plants p on p.tenant_id = ip.tenant_id and p.id = ip.plant_id
                 left join users ucr on ucr.id = ip.close_requested_by
                 left join users uc on uc.id = ip.closed_by
                 left join users urr on urr.id = ip.reopen_requested_by
                 left join users ur on ur.id = ip.reopened_by';
    }

    public function filterFrom(): string
    {
        return 'inventory_periods ip join plants p on p.tenant_id = ip.tenant_id and p.id = ip.plant_id';
    }

    public function idColumn(): string
    {
        return 'ip.id';
    }

    public function plantColumn(): string
    {
        return 'ip.plant_id';
    }

    public function companyColumn(): string
    {
        return 'p.company_id';
    }

    public function fields(): array
    {
        return [
            new Field('id', 'ip.id', Field::UUID, filterable: true),
            new Field('code', "to_char(ip.period, 'YYYY-MM')", sortable: true, search: 'prefix'),
            new Field('plantId', 'ip.plant_id', Field::UUID, filterable: true),
            new Field('plantCode', 'p.code', sortable: true),
            new Field('status', 'ip.status', Field::ENUM, sortable: true, filterable: true, options: self::STATUSES),
            new Field('closeRequestedById', 'ip.close_requested_by', Field::UUID),
            new Field('closeRequestedByName', 'ucr.display_name'),
            new Field('closeRequestedAt', 'ip.close_requested_at', Field::INSTANT),
            new Field('closedByName', 'uc.display_name'),
            new Field('closedAt', 'ip.closed_at', Field::INSTANT),
            new Field('reopenRequestedById', 'ip.reopen_requested_by', Field::UUID),
            new Field('reopenRequestedByName', 'urr.display_name'),
            new Field('reopenRequestedAt', 'ip.reopen_requested_at', Field::INSTANT),
            new Field('reopenReason', 'ip.reopen_reason'),
            new Field('reopenedByName', 'ur.display_name'),
            new Field('reopenedAt', 'ip.reopened_at', Field::INSTANT),
            // requests of the month and reversal requests of its documents, still undecided (they block a close)
            new Field('pendingRequests', "(select count(*) from inventory_documents d where d.tenant_id = ip.tenant_id and d.plant_id = ip.plant_id and d.status = 'SUBMITTED'
                and ((d.effective_date >= ip.period and d.effective_date < (ip.period + interval '1 month'))
                     or exists (select 1 from inventory_documents o where o.tenant_id = d.tenant_id and o.id = d.reverses_document_id
                                   and o.effective_date >= ip.period and o.effective_date < (ip.period + interval '1 month'))))", Field::INTEGER),
            new Field('version', 'ip.version', Field::INTEGER),
        ];
    }

    public function title(): string
    {
        return 'Inventory periods';
    }

    /** D-092: every column of the list, with the screen's headings. */
    public function exportColumns(): array
    {
        return [
            new ExportColumn('Month', 'code'),
            new ExportColumn('Plant', 'plantCode'),
            new ExportColumn('Status', 'status'),
            new ExportColumn('Close requested by', 'closeRequestedByName'),
            new ExportColumn('Closed by', 'closedByName'),
            new ExportColumn('Reopen requested by', 'reopenRequestedByName'),
            new ExportColumn('Reopen reason', 'reopenReason'),
            new ExportColumn('Reopened by', 'reopenedByName'),
        ];
    }

    public function defaultSort(): array
    {
        return [['code', true]];
    }
}
