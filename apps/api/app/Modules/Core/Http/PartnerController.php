<?php

declare(strict_types=1);

namespace App\Modules\Core\Http;

use App\Modules\Core\Queries\CustomerItemsQuery;
use App\Modules\Core\Queries\CustomersQuery;
use App\Modules\Core\Queries\SuppliersQuery;
use App\Platform\Access\Authorizer;
use App\Platform\Audit\AuditRecorder;
use App\Platform\Http\Concurrency;
use App\Platform\Query\QueryRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Master Data → partners (doc 29 §1.2, doc 26 §2.2, D-073): customers, suppliers and the customer part mapping.
 * Tenant-wide master data under `master.part.create` held in any scope. Codes are immutable; a mapping's customer,
 * item, part number and start date are fixed — a change of item is a new mapping that closes the open one the day
 * before. Edits use If-Match; every write is audited.
 */
final class PartnerController
{
    use MasterWrites;

    private const string PART = 'master.part.create';

    public const string PARTNER_CODE = 'regex:/^[A-Z0-9][A-Z0-9._-]{0,19}$/';

    private const string CODE_MESSAGE = 'Use 1–20 characters A-Z, 0-9, ".", "_" or "-", starting with a letter or digit.';

    public function __construct(
        private readonly Authorizer $authorizer,
        private readonly AuditRecorder $audit,
        private readonly QueryRunner $rows,
    ) {}

    /** Create a customer. */
    public function storeCustomer(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', self::PARTNER_CODE],
            'name' => ['required', 'string', 'min:1', 'max:200'],
        ], ['code.regex' => self::CODE_MESSAGE]);

        return $this->storePartner($data, 'customers', 'customer', new CustomersQuery);
    }

    /** Rename a customer or change its status (If-Match). */
    public function updateCustomer(Request $request, string $customer): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'min:1', 'max:200'],
            'status' => ['sometimes', Rule::in(['ACTIVE', 'INACTIVE'])],
        ]);

        return $this->updatePartner($request, $data, 'customers', 'customer', $customer, new CustomersQuery);
    }

    /** Create a supplier. */
    public function storeSupplier(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', self::PARTNER_CODE],
            'name' => ['required', 'string', 'min:1', 'max:200'],
        ], ['code.regex' => self::CODE_MESSAGE]);

        return $this->storePartner($data, 'suppliers', 'supplier', new SuppliersQuery);
    }

    /** Rename a supplier or change its status (If-Match). */
    public function updateSupplier(Request $request, string $supplier): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'min:1', 'max:200'],
            'status' => ['sometimes', Rule::in(['ACTIVE', 'INACTIVE'])],
        ]);

        return $this->updatePartner($request, $data, 'suppliers', 'supplier', $supplier, new SuppliersQuery);
    }

    /**
     * Map a customer part number to one of our items from a day on. If the same customer part has an open mapping that
     * starts earlier, it is closed the day before; a mapping that starts on or after that day is a conflict (422).
     */
    public function storeCustomerItem(Request $request): JsonResponse
    {
        $data = $request->validate([
            'customerId' => ['required', 'uuid'],
            'itemId' => ['required', 'uuid'],
            'customerPartNo' => ['required', 'string', 'min:1', 'max:60'],
            'customerDrawingRev' => ['nullable', 'string', 'max:20'],
            'effectiveFrom' => ['required', 'date_format:Y-m-d'],
        ]);
        $partNo = trim($data['customerPartNo']);
        if ($partNo === '') {
            throw ValidationException::withMessages(['customerPartNo' => 'Enter the customer part number.']);
        }
        $db = $this->db();
        $this->authorizer->requireAnywhere($db, self::PART);
        $customer = $db->selectOne('select id, status from customers where id = ?', [strtolower($data['customerId'])])
            ?? throw ValidationException::withMessages(['customerId' => 'Unknown customer.']);
        $item = $db->selectOne('select id, status from items where id = ?', [strtolower($data['itemId'])])
            ?? throw ValidationException::withMessages(['itemId' => 'Unknown item.']);
        if ($customer->status !== 'ACTIVE') {
            throw ValidationException::withMessages(['customerId' => 'This customer is inactive.']);
        }
        if ($item->status !== 'ACTIVE') {
            throw ValidationException::withMessages(['itemId' => 'Only active items can be mapped.']);
        }

        // serialize mappings of this customer: the open one is closed in the same transaction
        $db->selectOne('select id from customers where id = ? for update', [$customer->id]);
        $same = 'customer_id = ? and customer_part_no = ?';
        if ($db->selectOne("select 1 as ok from customer_items where {$same} and effective_from >= ?", [$customer->id, $partNo, $data['effectiveFrom']]) !== null) {
            throw ValidationException::withMessages(['effectiveFrom' => 'A mapping of this customer part already starts on or after this day.']);
        }
        $open = $db->selectOne("select id from customer_items where {$same} and effective_to is null", [$customer->id, $partNo]);
        if ($open !== null) {
            $before = $this->state($db, 'customer_items', (string) $open->id);
            $db->update('update customer_items set effective_to = ?::date - 1 where id = ?', [$data['effectiveFrom'], $open->id]);
            $this->audit->record($db, 'customer_item.closed', 'customer_item', (string) $open->id, $before, $this->state($db, 'customer_items', (string) $open->id), ['customerId' => (string) $customer->id]);
        } elseif ($db->selectOne("select 1 as ok from customer_items where {$same} and effective_to >= ?", [$customer->id, $partNo, $data['effectiveFrom']]) !== null) {
            throw ValidationException::withMessages(['effectiveFrom' => 'This customer part is already mapped on this day.']);
        }
        $id = (string) $db->selectOne(
            'insert into customer_items (tenant_id, customer_id, item_id, customer_part_no, customer_drawing_rev, effective_from)
             values ((select c.tenant from ctx_api.context() c), ?, ?, ?, ?, ?) returning id',
            [$customer->id, $item->id, $partNo, self::optionalText($data['customerDrawingRev'] ?? null), $data['effectiveFrom']],
        )->id;
        $this->audit->record($db, 'customer_item.created', 'customer_item', $id, null, $this->state($db, 'customer_items', $id), [
            'customerId' => (string) $customer->id, 'itemId' => (string) $item->id,
        ]);

        return $this->respond($db, new CustomerItemsQuery, $id, 201);
    }

    /** Change a mapping's customer drawing revision or status (If-Match). */
    public function updateCustomerItem(Request $request, string $customerItem): JsonResponse
    {
        $data = $request->validate([
            'customerDrawingRev' => ['sometimes', 'nullable', 'string', 'max:20'],
            'status' => ['sometimes', Rule::in(['ACTIVE', 'INACTIVE'])],
        ]);
        $version = Concurrency::expectedVersion($request);
        $db = $this->db();
        $this->authorizer->requireAnywhere($db, self::PART);
        $id = $this->existing($db, 'customer_items', $customerItem);

        $changes = [];
        if (array_key_exists('customerDrawingRev', $data)) {
            $changes['customer_drawing_rev'] = self::optionalText($data['customerDrawingRev']);
        }
        if (isset($data['status'])) {
            $changes['status'] = $data['status'];
        }
        $this->update($db, 'customer_items', 'customer_item', $id, $version, $changes, 'customer_item.updated');

        return $this->respond($db, new CustomerItemsQuery, $id);
    }

    /** @param  array<string, mixed>  $data */
    private function storePartner(array $data, string $table, string $entity, CustomersQuery|SuppliersQuery $query): JsonResponse
    {
        $db = $this->db();
        $this->authorizer->requireAnywhere($db, self::PART);

        $id = $this->insert($db, "insert into {$table} (tenant_id, code, name) values ((select c.tenant from ctx_api.context() c), ?, ?) returning id",
            [$data['code'], $data['name']], "A {$entity} with this code already exists.");
        $this->audit->record($db, "{$entity}.created", $entity, $id, null, $this->state($db, $table, $id), ['code' => $data['code']]);

        return $this->respond($db, $query, $id, 201);
    }

    /** @param  array<string, mixed>  $data */
    private function updatePartner(Request $request, array $data, string $table, string $entity, string $raw, CustomersQuery|SuppliersQuery $query): JsonResponse
    {
        $version = Concurrency::expectedVersion($request);
        $db = $this->db();
        $this->authorizer->requireAnywhere($db, self::PART);
        $id = $this->existing($db, $table, $raw);

        $changes = array_filter(['name' => $data['name'] ?? null, 'status' => $data['status'] ?? null], static fn ($v) => $v !== null);
        $this->update($db, $table, $entity, $id, $version, $changes, "{$entity}.updated");

        return $this->respond($db, $query, $id);
    }
}
