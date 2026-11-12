<?php

declare(strict_types=1);

namespace App\Platform\Demo;

use App\Modules\Inventory\Http\ReceiptController;
use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Security\SecurityHasher;
use App\Platform\Tenancy\TenantContext;
use App\Platform\Tenancy\TenantTransaction;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Opening stock for a demo tenant with its sample master data (doc 32 §1, M3 exit): supplier receipts into rack RM-A01
 * of plant P1 through the real receipt endpoint as the materials PIC (`mat@`), then one incoming release as the incoming
 * inspector (`qci@`) — so UAT starts with Blocked and Unrestricted lots, a consumable without lots, and real documents.
 */
final class DemoStock
{
    /** item code, supplier, quantity, supplier lot (null = item without lots) */
    private const array RECEIPTS = [
        ['COIL-SPCC-12', 'SUP-STEEL', '2500', 'HEAT-A101'],
        ['COIL-SPCC-12', 'SUP-STEEL', '1800', 'HEAT-A102'],
        ['SHEET-SPCC-16', 'SUP-STEEL', '400', 'HEAT-B201'],
        ['NUT-M8', 'SUP-NUT', '5000', 'NUT-LOT-7731'],
        ['OIL-PRESS', 'SUP-NUT', '200', null],
    ];

    public function __construct(
        private readonly TenantTransaction $transaction,
        private readonly SecurityHasher $hasher,
        private readonly ReceiptController $receipts,
    ) {}

    /**
     * @param  Closure(string): void  $progress  one line per posted document
     * @return list<string> the posted document numbers
     */
    public function load(DemoSpec $spec, Closure $progress): array
    {
        $tenant = DB::connection('pgsql_provisioner')->selectOne(
            'select tenant_id, status from prov_api.provision_lookup(?, ?)',
            [$spec->code, $this->hasher->hash($spec->code."\n".$spec->email('tenant-admin'))],
        );
        if ($tenant === null || $tenant->status === 'STARTED') {
            throw new RuntimeException("No complete demo tenant {$spec->code}. Run eora:demo:seed --code={$spec->code} first.");
        }
        $tenant = (string) $tenant->tenant_id;
        $ids = $this->system($tenant, function (EoraPostgresConnection $db): array {
            if ($db->selectOne("select 1 as posted from inventory_documents where doc_type = 'RM_RECEIPT' limit 1") !== null) {
                throw new RuntimeException('This demo tenant has stock postings already; the opening stock loads once.');
            }
            $id = static fn (string $sql, string $code) => $db->selectOne($sql, [$code])?->id;
            $plant = $id('select id from plants where code = ?', 'P1');
            $rack = $id("select l.id from locations l join plants p on p.id = l.plant_id where p.code = 'P1' and l.code = ?", 'RM-A01');
            if ($plant === null || $rack === null || $id('select id from items where code = ?', DemoMasterData::MARKER) === null) {
                throw new RuntimeException('The sample master data is missing. Run eora:demo:master first.');
            }

            return ['plant' => (string) $plant, 'rack' => (string) $rack,
                'items' => array_column($db->select('select code, id from items'), 'id', 'code'),
                'suppliers' => array_column($db->select('select code, id from suppliers'), 'id', 'code')];
        });
        $mat = $this->member($tenant, $spec->email('mat'));
        $qci = $this->member($tenant, $spec->email('qci'));

        $posted = [];
        foreach (self::RECEIPTS as $i => [$item, $supplier, $qty, $supplierLot]) {
            $request = Request::create('/api/v1/inventory/receipts', 'POST', array_filter([
                'plantId' => $ids['plant'], 'itemId' => (string) $ids['items'][$item], 'locationId' => $ids['rack'],
                'supplierId' => (string) $ids['suppliers'][$supplier], 'qty' => $qty, 'supplierLotNo' => $supplierLot,
                'note' => 'Demo opening stock',
            ], static fn ($v) => $v !== null));
            $request->headers->set('Idempotency-Key', "demo-stock-receipt-{$i}");
            $data = $this->as($tenant, $mat, fn () => $this->receipts->receive($request)->getData(true)['data']);
            $posted[] = $data['docNo'];
            $progress(sprintf('%s  %s %s %s%s', $data['docNo'], $item, $qty, $supplierLot ?? '', $data['lotNo'] ? " → lot {$data['lotNo']}" : ''));
        }

        // the second coil passed its incoming inspection: released where it lies
        $coil = $this->system($tenant, fn (EoraPostgresConnection $db) => $db->selectOne(
            "select b.lot_id, trim_scale(b.qty)::text as qty from stock_balances b join lots l on l.id = b.lot_id
              where l.supplier_lot_no = 'HEAT-A102' and b.stock_status = 'BLOCKED' and b.qty > 0",
        ));
        $release = Request::create('/api/v1/inventory/incoming-releases', 'POST', [
            'plantId' => $ids['plant'], 'itemId' => (string) $ids['items']['COIL-SPCC-12'], 'lotId' => (string) $coil->lot_id,
            'lines' => [['locationId' => $ids['rack'], 'qty' => (string) $coil->qty]], 'note' => 'Demo: incoming inspection passed',
        ]);
        $release->headers->set('Idempotency-Key', 'demo-stock-release-0');
        $data = $this->as($tenant, $qci, fn () => $this->receipts->release($release)->getData(true)['data']);
        $posted[] = $data['docNo'];
        $progress(sprintf('%s  COIL-SPCC-12 HEAT-A102 released', $data['docNo']));

        return $posted;
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
