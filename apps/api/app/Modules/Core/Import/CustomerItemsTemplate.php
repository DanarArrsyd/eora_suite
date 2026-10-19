<?php

declare(strict_types=1);

namespace App\Modules\Core\Import;

use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Import\ImportColumn;
use App\Platform\Import\MasterTemplate;
use App\Platform\Import\RowRules;

/**
 * Customer part mapping (doc 29 §1.6, doc 26 §2.2): a customer part number → our item from a day on, with the screen's
 * rule — an open mapping of the same customer part that starts earlier is closed the day before; one starting on or
 * after that day is an error.
 */
final class CustomerItemsTemplate extends MasterTemplate
{
    public function code(): string
    {
        return 'customer_items';
    }

    public function title(): string
    {
        return 'Customer part mapping';
    }

    public function targetPermission(): string
    {
        return 'master.part.create';
    }

    public function columns(): array
    {
        return [
            new ImportColumn('Customer code', true, 'Code of an active customer.'),
            new ImportColumn('Customer part no', true, 'At most 60 characters, as the customer writes it.'),
            new ImportColumn('Item code', true, 'Code of an active item of ours.'),
            new ImportColumn('Customer drawing rev', false, 'At most 20 characters.'),
            new ImportColumn('Effective from', true, 'Date YYYY-MM-DD.'),
        ];
    }

    public function validate(EoraPostgresConnection $db, array $row, int $rowNo, array &$seen): array
    {
        $customerCode = strtoupper($row['Customer code']);
        $partNo = $row['Customer part no'];
        $from = $row['Effective from'];
        $errors = RowRules::collect([
            RowRules::required($customerCode, 'Customer code'),
            RowRules::required($partNo, 'Customer part no'),
            RowRules::maxLength($partNo, 60, 'Customer part no'),
            RowRules::required($row['Item code'], 'Item code'),
            RowRules::maxLength($row['Customer drawing rev'], 20, 'Customer drawing rev'),
            RowRules::required($from, 'Effective from'),
            $from !== '' && (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) !== 1 || ! checkdate((int) substr($from, 5, 2), (int) substr($from, 8, 2), (int) substr($from, 0, 4)))
                ? 'Effective from: enter a date as YYYY-MM-DD.' : null,
        ]);
        $customer = $customerCode === '' ? null : $db->selectOne('select id, status from customers where code = ?', [$customerCode]);
        if ($customerCode !== '' && ($customer === null || $customer->status !== 'ACTIVE')) {
            $errors[] = $customer === null ? "Customer {$customerCode} does not exist." : "Customer {$customerCode} is inactive.";
        }
        $item = $row['Item code'] === '' ? null : $db->selectOne('select status from items where code = ?', [strtoupper($row['Item code'])]);
        if ($row['Item code'] !== '' && ($item === null || $item->status !== 'ACTIVE')) {
            $errors[] = $item === null ? 'Item '.strtoupper($row['Item code']).' does not exist.' : 'Item '.strtoupper($row['Item code']).' is inactive.';
        }
        if ($errors === [] && $customer !== null) {
            $same = 'customer_id = ? and customer_part_no = ?';
            if ($db->selectOne("select 1 as ok from customer_items where {$same} and effective_from >= ?", [$customer->id, $partNo, $from]) !== null) {
                $errors[] = 'A mapping of this customer part already starts on or after this day.';
            } elseif ($db->selectOne("select 1 as ok from customer_items where {$same} and effective_to is null", [$customer->id, $partNo]) === null
                && $db->selectOne("select 1 as ok from customer_items where {$same} and effective_to >= ?", [$customer->id, $partNo, $from]) !== null) {
                $errors[] = 'This customer part is already mapped on this day.';
            }
        }

        return $errors === [] ? RowRules::collect([RowRules::once($seen, 'customer_part', "{$customerCode}/{$partNo}", $rowNo, "Customer part {$partNo} of {$customerCode}")]) : $errors;
    }

    public function commit(EoraPostgresConnection $db, array $row): string
    {
        $customer = (string) $db->selectOne('select id from customers where code = ? for update', [strtoupper($row['Customer code'])])->id;
        $item = (string) $db->selectOne('select id from items where code = ?', [strtoupper($row['Item code'])])->id;
        $open = $db->selectOne('select id from customer_items where customer_id = ? and customer_part_no = ? and effective_to is null', [$customer, $row['Customer part no']]);
        if ($open !== null) {
            $before = (array) $db->selectOne('select * from customer_items where id = ?', [$open->id]);
            $db->update('update customer_items set effective_to = ?::date - 1 where id = ?', [$row['Effective from'], $open->id]);
            $this->audit->record($db, 'customer_item.closed', 'customer_item', (string) $open->id, $before,
                (array) $db->selectOne('select * from customer_items where id = ?', [$open->id]), ['customerId' => $customer, 'source' => 'import']);
        }
        $id = (string) $db->selectOne(
            'insert into customer_items (tenant_id, customer_id, item_id, customer_part_no, customer_drawing_rev, effective_from)
             values ((select c.tenant from ctx_api.context() c), ?, ?, ?, ?, ?) returning id',
            [$customer, $item, $row['Customer part no'], RowRules::text($row['Customer drawing rev']), $row['Effective from']],
        )->id;

        return $this->created($db, 'customer_items', 'customer_item', $id, ['customerId' => $customer, 'itemId' => $item]);
    }
}
