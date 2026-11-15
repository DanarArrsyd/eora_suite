<?php

declare(strict_types=1);

namespace App\Modules\Delivery\Demo;

use App\Modules\Delivery\Http\DeliveryController;
use App\Modules\Demand\Http\SalesOrderController;
use App\Modules\Inventory\Http\MaterialFlowController;
use App\Modules\Planning\Http\BomController;
use App\Modules\Planning\Http\RoutingController;
use App\Modules\Planning\Http\WorkOrderController;
use App\Modules\Production\Http\ProductionController;
use App\Modules\Quality\Http\QualityController;
use App\Modules\Warehouse\Http\FgReceiptController;
use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Demo\DemoSpec;
use App\Platform\Security\SecurityHasher;
use App\Platform\Tenancy\TenantContext;
use App\Platform\Tenancy\TenantTransaction;
use Closure;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use stdClass;

/**
 * One full order for a demo tenant (doc 33 §1.9, D-118, M4 exit): with the sample master data and the opening stock
 * (`eora:demo:master`, `eora:demo:stock`), the M4 flow runs through the real endpoints, each step as the demo account of
 * its role — BOM and routing of BRK-A100 approved (ppc asks, csa decides), a sales order of OEM-A for 300 PCS (ppc), a
 * work order for it (ppc asks, csa releases), coil and nuts issued (mat), one run posted and closed (prl), its output
 * released by QC (qci), received into FG (fgw), 200 allocated (dlv) and picked (fgw), and 150 shipped (dlv) — so the
 * trace, the order's delivered quantity and the stock show a real, partly shipped order.
 *
 * Safe to repeat: the order and the work order are found again by their note, every posting has its own fixed key, and a
 * step already done is skipped; a run cut off half-way finishes when run again.
 */
final class DemoOrder
{
    public const string NOTE = 'Demo M4 order';

    private const string ITEM = 'BRK-A100';

    public function __construct(
        private readonly TenantTransaction $transaction,
        private readonly SecurityHasher $hasher,
    ) {}

    /**
     * @param  Closure(string): void  $progress  one line per step
     * @return array{so: string, wo: string, shipment: string, lot: string}
     */
    public function run(DemoSpec $spec, Closure $progress): array
    {
        $tenant = DB::connection('pgsql_provisioner')->selectOne(
            'select tenant_id, status from prov_api.provision_lookup(?, ?)',
            [$spec->code, $this->hasher->hash($spec->code."\n".$spec->email('tenant-admin'))],
        );
        if ($tenant === null || $tenant->status === 'STARTED') {
            throw new RuntimeException("No complete demo tenant {$spec->code}. Run eora:demo:seed --code={$spec->code} first.");
        }
        $tenant = (string) $tenant->tenant_id;
        $m = $this->system($tenant, function (EoraPostgresConnection $db): array {
            $one = static fn (string $sql, array $args = []) => $db->selectOne($sql, $args)?->id;
            $ids = [
                'plant' => $one("select id from plants where code = 'P1'"),
                'item' => $one('select id from items where code = ?', [self::ITEM]),
                'coil' => $one("select id from items where code = 'COIL-SPCC-12'"),
                'nut' => $one("select id from items where code = 'NUT-M8'"),
                'scrap' => $one("select id from items where code = 'SCRAP-SPCC'"),
                'customer' => $one("select id from customers where code = 'OEM-A'"),
                'part' => $one("select ci.id from customer_items ci join customers c on c.id = ci.customer_id where c.code = 'OEM-A' and ci.customer_part_no = 'A-77101-BK'"),
                'rack' => $one("select l.id from locations l join plants p on p.id = l.plant_id where p.code = 'P1' and l.code = 'RM-A01'"),
            ];
            if (in_array(null, $ids, true)) {
                throw new RuntimeException('The sample master data is missing. Run eora:demo:master first.');
            }

            return array_map('strval', $ids);
        });
        $who = fn (string $role) => $this->member($tenant, $spec->email($role));
        [$ppc, $csa, $mat, $prl, $qci, $fgw, $dlv] = array_map($who, ['ppc', 'csa', 'mat', 'prl', 'qci', 'fgw', 'dlv']);

        // 1. the master of BRK-A100 approved: the planner asks, an approver who neither prepared nor edited it decides
        //    (D-060) — csa@, or uat@ (eora:demo:uat-account) when csa@ committed the imported draft itself
        foreach ([[BomController::class, $this->bom($tenant), 'bom_version'], [RoutingController::class, $this->routing($tenant), 'routing_version']] as [$controller, $version, $table]) {
            $what = $controller === BomController::class ? 'BOM' : 'Primary routing';
            if ($version === null) {
                throw new RuntimeException("BRK-A100 has no {$what} version. Run eora:demo:master first.");
            }
            if ($version->status === 'APPROVED') {
                continue;
            }
            $approver = $this->approver($tenant, $spec, $table, (string) $version->id)
                ?? throw new RuntimeException("{$what} of BRK-A100 was prepared by its only approver (csa@). Add a second approver with eora:demo:uat-account, or approve it in UAT §2, then run again.");
            if ($version->status === 'DRAFT') {
                $this->as($tenant, $ppc, fn () => app($controller)->submit((string) $version->id));
            }
            $this->as($tenant, $approver, fn () => app($controller)->approve((string) $version->id));
            $progress("{$what} of BRK-A100 approved");
        }

        // 2. the sales order: OEM-A, 300 PCS a week from today (ppc)
        $so = $this->find($tenant, 'select id, so_no, status, version from sales_order_headers where note = ? order by created_at limit 1');
        if ($so === null) {
            $so = (object) $this->call($tenant, $ppc, SalesOrderController::class, 'store', ['plantId' => $m['plant'], 'customerId' => $m['customer'], 'note' => self::NOTE]);
            $so->status = 'DRAFT';
        }
        $soId = (string) $so->id;
        if ($so->status === 'DRAFT') {
            $due = (new DateTimeImmutable('today'))->modify('+7 days')->format('Y-m-d');
            $filled = $this->call($tenant, $ppc, SalesOrderController::class, 'putLines', ['lines' => [[
                'customerItemId' => $m['part'], 'customerPoNo' => 'PO-DEMO-M4', 'customerPoLine' => '10', 'schedules' => [['requirementDate' => $due, 'qty' => '300']],
            ]]], [$soId], ['If-Match' => '"'.$so->version.'"']);
            $this->call($tenant, $ppc, SalesOrderController::class, 'confirm', [], [$soId], ['If-Match' => '"'.$filled['version'].'"']);
        }
        $line = $this->system($tenant, fn (EoraPostgresConnection $db) => $db->selectOne(
            'select s.id, s.requirement_date::text as due, h.so_no from delivery_schedule_lines s join sales_order_lines ol on ol.id = s.so_line_id
               join sales_order_headers h on h.id = ol.sales_order_id where h.id = ? order by ol.line_no, s.schedule_no limit 1', [$soId]));
        $progress("{$line->so_no}  OEM-A 300 PCS, confirmed");

        // 3. the work order for it: the planner asks, the administrator releases (BOM and routing copied at release)
        $wo = $this->find($tenant, 'select id, wo_no, status, version from work_orders where note = ? order by created_at limit 1');
        if ($wo === null) {
            $wo = (object) $this->call($tenant, $ppc, WorkOrderController::class, 'store', [
                'plantId' => $m['plant'], 'itemId' => $m['item'], 'qty' => '300', 'dueDate' => $line->due, 'reasonCode' => 'URGENT_ORDER',
                'note' => self::NOTE, 'scheduleLineId' => (string) $line->id,
            ]);
            $wo->status = 'DRAFT';
        }
        $woId = (string) $wo->id;
        if ($wo->status === 'DRAFT') {
            $wo = (object) $this->call($tenant, $ppc, WorkOrderController::class, 'submit', [], [$woId], ['If-Match' => '"'.$wo->version.'"']);
            $wo->status = 'SUBMITTED';
        }
        if ($wo->status === 'SUBMITTED') {
            $this->call($tenant, $csa, WorkOrderController::class, 'release', [], [$woId], ['If-Match' => '"'.$wo->version.'"']);
        }
        $flow = $this->system($tenant, fn (EoraPostgresConnection $db) => $db->selectOne(
            "select w.wo_no, (select o.work_center_id from routing_operations o where o.routing_version_id = w.routing_version_id order by o.op_seq limit 1) as wc,
                    c.lot_id as coil_lot, c.qty as coil_qty, n.lot_id as nut_lot, n.qty as nut_qty
               from work_orders w
               left join lateral (select b.lot_id, b.qty from stock_balances b where b.location_id = ? and b.item_id = ? and b.stock_status = 'UNRESTRICTED' and b.lot_id is not null
                                   order by b.qty desc limit 1) c on true
               left join lateral (select b.lot_id, b.qty from stock_balances b where b.location_id = ? and b.item_id = ? and b.stock_status = 'UNRESTRICTED'
                                   order by b.qty desc limit 1) n on true
              where w.id = ?", [$m['rack'], $m['coil'], $m['rack'], $m['nut'], $woId]));
        $progress("{$flow->wo_no}  300 PCS for {$line->so_no}, released");
        if ($flow->coil_lot === null || $flow->nut_qty === null || (float) $flow->coil_qty < 120 || (float) $flow->nut_qty < 600) {
            throw new RuntimeException('RM-A01 needs 120 KG of released coil COIL-SPCC-12 (one lot) and 600 NUT-M8 to issue. Run eora:demo:stock first.');
        }

        // 4. coil and nuts issued to the first work center (mat)
        $this->call($tenant, $mat, MaterialFlowController::class, 'issue', ['workOrderId' => $woId, 'workCenterId' => (string) $flow->wc, 'lines' => [
            ['itemId' => $m['coil'], 'lotId' => (string) $flow->coil_lot, 'fromLocationId' => $m['rack'], 'qty' => '120'],
            ['itemId' => $m['nut'], 'lotId' => $flow->nut_lot, 'fromLocationId' => $m['rack'], 'qty' => '600'],
        ]], [], ['Idempotency-Key' => 'demo-m4-issue']);
        $progress('Coil 120 KG and nuts 600 PCS issued');

        // 5. one run, posted and closed (prl): 20 KG of coil weighed left, every nut used
        $run = $this->find($tenant, 'select r.id, r.status, r.version from production_runs r join work_orders w on w.id = r.work_order_id
                                       where w.note = ? order by r.opened_at limit 1');
        $run ??= (object) ($this->call($tenant, $prl, ProductionController::class, 'open', ['workOrderId' => $woId, 'workCenterId' => (string) $flow->wc]) + ['status' => 'OPEN']);
        $posted = $this->call($tenant, $prl, ProductionController::class, 'post', [
            'runId' => (string) $run->id,
            'consumptions' => [
                ['itemId' => $m['coil'], 'lotId' => (string) $flow->coil_lot, 'method' => 'WEIGHED_REMAINING', 'qty' => '20', 'evidence' => 'Scale S-01'],
                ['itemId' => $m['nut'], 'lotId' => $flow->nut_lot, 'method' => 'COUNTED_REMAINING', 'qty' => '0', 'evidence' => 'Counted by hand'],
            ],
            'goodQty' => '296', 'ngReworkQty' => '0', 'ngRejectQty' => '4',
            'scrap' => [['itemId' => $m['scrap'], 'qty' => '3', 'reasonCode' => 'SKELETON']],
        ], [], ['Idempotency-Key' => 'demo-m4-production']);
        if ($run->status === 'OPEN') {
            $current = $this->find($tenant, 'select r.version from production_runs r where r.id = ?', [(string) $run->id], false)
                ?? throw new RuntimeException('The demo run is gone.');
            $this->call($tenant, $prl, ProductionController::class, 'close', [], [(string) $run->id], ['If-Match' => '"'.$current->version.'"']);
        }
        $progress("{$posted['docNo']}  296 good, lot {$posted['lotNo']}; run closed");
        $lot = (string) $posted['lotId'];

        // 6. QC releases the good output (qci: another person than who reported it, SoD-01)
        $pending = $this->balance($tenant, $lot, 'QC_AREA', 'PENDING_QC');
        if ($pending !== null) {
            $qc = $this->call($tenant, $qci, QualityController::class, 'dispose', ['stockLineId' => $pending, 'lines' => [['outcome' => 'RELEASE', 'qty' => '296']]],
                [], ['Idempotency-Key' => 'demo-m4-qc']);
            $progress("{$qc['docNo']}  296 released");
        }

        // 7. received into FG (fgw)
        $released = $this->balance($tenant, $lot, 'QC_AREA', 'QC_RELEASED');
        if ($released !== null) {
            $fg = $this->call($tenant, $fgw, FgReceiptController::class, 'store', ['plantId' => $m['plant'], 'lines' => [['stockLineId' => $released, 'qty' => '296']], 'note' => self::NOTE],
                [], ['Idempotency-Key' => 'demo-m4-fg']);
            $progress("{$fg['docNo']}  296 PCS received into FG");
        }

        // 8. 200 allocated (dlv) and picked to staging (fgw)
        $fgLine = $this->balance($tenant, $lot, 'FG_WAREHOUSE', 'UNRESTRICTED');
        $staged = $this->balance($tenant, $lot, 'STAGING', 'UNRESTRICTED');
        if ($staged === null) {
            $allocated = $this->call($tenant, $dlv, DeliveryController::class, 'allocate', ['scheduleLineId' => (string) $line->id, 'lines' => [['stockLineId' => $fgLine, 'qty' => '200']]],
                [], ['Idempotency-Key' => 'demo-m4-allocate']);
            $pick = $this->call($tenant, $fgw, DeliveryController::class, 'pick', ['plantId' => $m['plant'], 'lines' => [['allocationId' => $allocated['allocationIds'][0], 'qty' => '200']]],
                [], ['Idempotency-Key' => 'demo-m4-pick']);
            $progress("{$pick['docNo']}  200 PCS picked to staging");
            $staged = $this->balance($tenant, $lot, 'STAGING', 'UNRESTRICTED');
        }

        // 9. 150 shipped to OEM-A (dlv): the order is partly delivered
        $shipment = $this->call($tenant, $dlv, DeliveryController::class, 'ship', [
            'plantId' => $m['plant'], 'customerId' => $m['customer'], 'vehicleNo' => 'B 1234 XY', 'driverName' => 'Budi', 'note' => self::NOTE,
            'lines' => [['scheduleLineId' => (string) $line->id, 'stockLineId' => $staged, 'qty' => '150']],
        ], [], ['Idempotency-Key' => 'demo-m4-ship']);
        $progress("{$shipment['docNo']}  150 PCS shipped to OEM-A (B 1234 XY)");

        return ['so' => (string) $line->so_no, 'wo' => (string) $flow->wo_no, 'shipment' => (string) $shipment['docNo'], 'lot' => (string) $posted['lotNo']];
    }

    /** The first of csa@ and uat@ who may approve and neither prepared nor edited the version (SoD, D-060). */
    private function approver(string $tenant, DemoSpec $spec, string $table, string $version): ?string
    {
        $row = $this->system($tenant, fn (EoraPostgresConnection $db) => $db->selectOne(
            "select u.id from users u join memberships m on m.user_id = u.id and m.status = 'ACTIVE'
              where u.email_display in (?, ?)
                and u.id is distinct from (select v.prepared_by from {$table}s v where v.id = ?)
                and not exists (select 1 from {$table}_contributors c where c.{$table}_id = ? and c.actor = u.id)
              order by u.email_display = ? desc limit 1",
            [$spec->email('csa'), $spec->email('uat'), $version, $version, $spec->email('csa')]));

        return $row === null ? null : (string) $row->id;
    }

    private function bom(string $tenant): ?stdClass
    {
        return $this->system($tenant, fn (EoraPostgresConnection $db) => $db->selectOne(
            "select v.id, v.status from bom_versions v join items i on i.id = v.item_id where i.code = ? and v.status in ('DRAFT', 'SUBMITTED', 'APPROVED')
              order by v.status = 'APPROVED' desc, v.version_no desc limit 1", [self::ITEM]));
    }

    private function routing(string $tenant): ?stdClass
    {
        return $this->system($tenant, fn (EoraPostgresConnection $db) => $db->selectOne(
            "select v.id, v.status from routing_versions v join routings r on r.id = v.routing_id join items i on i.id = r.item_id
              where i.code = ? and r.routing_kind = 'PRIMARY' and v.status in ('DRAFT', 'SUBMITTED', 'APPROVED')
              order by v.status = 'APPROVED' desc, v.version_no desc limit 1", [self::ITEM]));
    }

    /** The stock line of the lot at a location type and status, when it holds something. */
    private function balance(string $tenant, string $lot, string $type, string $status): ?string
    {
        $row = $this->system($tenant, fn (EoraPostgresConnection $db) => $db->selectOne(
            'select b.id from stock_balances b join locations l on l.id = b.location_id where b.lot_id = ? and l.location_type = ? and b.stock_status = ? and b.qty > 0
              order by b.qty desc limit 1', [$lot, $type, $status]));

        return $row === null ? null : (string) $row->id;
    }

    /** @param  list<mixed>  $args */
    private function find(string $tenant, string $sql, array $args = [], bool $byNote = true): ?stdClass
    {
        return $this->system($tenant, fn (EoraPostgresConnection $db) => $db->selectOne($sql, $byNote ? [self::NOTE] : $args));
    }

    /**
     * One endpoint as $user, as the API runs it for a signed-in request: its validation, authorization and database
     * guards all apply.
     *
     * @param  class-string  $controller
     * @param  array<string, mixed>  $body
     * @param  list<string>  $params
     * @param  array<string, string>  $headers
     * @return array<string, mixed> the response's data
     */
    private function call(string $tenant, string $user, string $controller, string $method, array $body, array $params = [], array $headers = []): array
    {
        $request = Request::create('/demo', 'POST', $body);
        foreach ($headers as $name => $value) {
            $request->headers->set($name, $value);
        }
        $response = $this->as($tenant, $user, fn () => app($controller)->{$method}($request, ...$params));

        return $response instanceof JsonResponse ? (array) ($response->getData(true)['data'] ?? []) : [];
    }

    private function member(string $tenant, string $email): string
    {
        $user = $this->system($tenant, fn (EoraPostgresConnection $db) => $db->selectOne(
            "select u.id from users u join memberships m on m.user_id = u.id where u.email_display = ? and m.status = 'ACTIVE'",
            [$email],
        ));

        return $user !== null ? (string) $user->id : throw new RuntimeException("No active demo account {$email}.");
    }

    /** @param  Closure(EoraPostgresConnection): mixed  $work  runs as $user, as the API does for a signed-in request */
    private function as(string $tenant, string $user, Closure $work): mixed
    {
        return $this->transaction->run(TenantContext::forTenant($tenant, $user), $work, null, 3);
    }

    /** @param  Closure(EoraPostgresConnection): mixed  $work */
    private function system(string $tenant, Closure $work): mixed
    {
        return $this->transaction->run(TenantContext::system($tenant), $work, 'pgsql_provisioner');
    }
}
