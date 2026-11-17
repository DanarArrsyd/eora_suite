<?php

declare(strict_types=1);

use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Tenancy\TenantContext;
use App\Platform\Tenancy\TenantTransaction;
use Tests\Support\AccessFixtures;
use Tests\Support\TenantFixtures;

/*
 * D-129: a document waiting for a second person names who decides it (by role and by name) and reaches them in the
 * inbox, with a link to it; the requester hears the decision; a request decided by one approver is no longer a task
 * for the others.
 */

beforeEach(function () {
    $this->t = TenantFixtures::provision(2);
    AccessFixtures::roles($this->t);
});

/** @return list<stdClass> approval notifications of $user, newest first */
function apInbox(array $t, string $user): array
{
    // read as that user: row-level security shows each user only their own inbox
    return app(TenantTransaction::class)->run(TenantContext::forTenant($t['tenant'], $user), fn (EoraPostgresConnection $db) => $db->select(
        "select kind, title, body, deep_link, action_required, subject_kind from inbox_notifications where kind like 'approval.%' order by created_at desc",
    ));
}

function apName(array $t, string $user): string
{
    return (string) AccessFixtures::run($t, fn (EoraPostgresConnection $db) => $db->selectOne('select display_name from users where id = ?', [$user])->display_name);
}

it('asks the approvers of the plant for a submitted MPS, names them on screen, and tells the planner the decision (D-129)', function () {
    [$p1, $p2] = $this->t['plants'];
    $f = mpsFixture($this, $this->t);
    $approver = member($this, $this->t);
    AccessFixtures::grant($this->t, $approver['id'], 'CSA', ['PLANT', $p1]);
    $elsewhere = member($this, $this->t);
    AccessFixtures::grant($this->t, $elsewhere['id'], 'CSA', ['PLANT', $p2]);
    $supervisor = member($this, $this->t);
    AccessFixtures::grant($this->t, $supervisor['id'], 'SUP');

    signIn($this, $f['ppc'], $this->t);
    $m = spa($this)->postJson('/api/v1/planning/mps-versions', ['plantId' => $f['plant']])->assertCreated()->json('data');
    $m = mpsAct($this, $m, 'submit')->assertOk()->json('data');

    // the approvers of plant 1 are asked, with a link; the planner and an approver of plant 2 are not
    foreach ([$approver, $supervisor] as $who) {
        $rows = apInbox($this->t, $who['id']);
        expect($rows)->toHaveCount(1)->and($rows[0]->kind)->toBe('approval.requested')->and($rows[0]->action_required)->toBeTrue()
            ->and($rows[0]->deep_link)->toBe("/ppic/mps?version={$m['id']}")->and($rows[0]->title)->toBe("Approval requested: {$m['code']}")
            ->and($rows[0]->body)->toContain(apName($this->t, $f['ppc']['id']));
    }
    expect(apInbox($this->t, $elsewhere['id']))->toBe([])->and(apInbox($this->t, $f['ppc']['id']))->toBe([]);
    // someone held in plant 2 only learns nothing about a plant 1 document, not even its approvers
    signIn($this, $elsewhere, $this->t);
    spa($this)->getJson("/api/v1/approvals/mps_version/{$m['id']}")->assertNotFound();
    signIn($this, $f['ppc'], $this->t);

    // the planner's screen says who decides it: the role (not the all-permission Supervisor) and the people
    $status = spa($this)->getJson("/api/v1/approvals/mps_version/{$m['id']}")->assertOk()->json('data');
    expect($status['waiting'])->toBeTrue()->and($status['requestedBy'])->toBe(apName($this->t, $f['ppc']['id']))
        ->and(array_column($status['roles'], 'code'))->toBe(['CSA'])
        ->and(array_column($status['approvers'], 'name'))->toContain(apName($this->t, $approver['id']), apName($this->t, $supervisor['id']))
        ->not->toContain(apName($this->t, $elsewhere['id']), apName($this->t, $f['ppc']['id']));

    // one approver decides: the planner hears it, and the request is no longer a task for the other approver
    signIn($this, $approver, $this->t);
    mpsAct($this, $m, 'approve')->assertOk()->assertJsonPath('data.status', 'APPROVED');
    $told = apInbox($this->t, $f['ppc']['id']);
    expect($told)->toHaveCount(1)->and($told[0]->kind)->toBe('approval.approved')->and($told[0]->action_required)->toBeFalse()
        ->and($told[0]->body)->toContain(apName($this->t, $approver['id']));
    signIn($this, $supervisor, $this->t);
    spa($this)->getJson('/api/v1/inbox')->assertOk()->assertJsonPath('meta.actionRequired', 0)->assertJsonPath('data.0.actionRequired', false);
    spa($this)->getJson("/api/v1/approvals/mps_version/{$m['id']}")->assertOk()->assertJsonPath('data.waiting', false)->assertJsonPath('data.approvers', []);
})->group('integration', 'planning');

it('asks an approver for a stock adjustment and tells the requester why it was rejected (D-129)', function () {
    $f = rcFixture($this->t);
    $approver = member($this, $this->t);
    AccessFixtures::grant($this->t, $approver['id'], 'CSA');
    orgUser($this, $this->t, 'MAT');
    $f['lotId'] = rcReceive($this, $f)->assertCreated()->json('data.lotId');
    $requester = rtSwitch($this, $this->t, 'INV', ['PLANT', $f['plant']]);
    $id = adRequest($this, $f, [], 'ap-adj-0001')->assertCreated()->json('data.documentId');

    $asked = apInbox($this->t, $approver['id']);
    expect($asked)->toHaveCount(1)->and($asked[0]->deep_link)->toBe("/inventory/adjustments?request={$id}")->and($asked[0]->subject_kind)->toBe('inventory_document');
    spa($this)->getJson("/api/v1/approvals/stock_adjustment/{$id}")->assertOk()->assertJsonPath('data.waiting', true)
        ->assertJsonPath('data.approvers.0.name', apName($this->t, $approver['id']));

    signIn($this, $approver, $this->t);
    spa($this)->postJson("/api/v1/inventory/adjustments/{$id}/reject", ['reason' => 'Count it again'])->assertNoContent();
    $told = apInbox($this->t, $requester['id']);
    expect($told)->toHaveCount(1)->and($told[0]->kind)->toBe('approval.rejected')->and($told[0]->body)->toContain('Reason: Count it again');
    spa($this)->getJson('/api/v1/inbox')->assertOk()->assertJsonPath('meta.actionRequired', 0);
})->group('integration', 'inventory');

it('asks CSA for a routing the Supervisor submitted, never the Supervisor itself, and hides unknown kinds (D-128, D-129)', function () {
    $sup = orgUser($this, $this->t, 'SUP');
    $s = rtSetup($this, $this->t);
    $approver = member($this, $this->t);
    AccessFixtures::grant($this->t, $approver['id'], 'CSA');
    [$routing, $v] = rtDraft($this, $this->t, $s);
    spa($this)->postJson('/api/v1/planning/routing-versions/'.$v['id'].'/actions/submit')->assertOk();

    expect(apInbox($this->t, $sup['id']))->toBe([])
        ->and(apInbox($this->t, $approver['id'])[0]->deep_link)->toBe("/master/routings?routing={$routing['id']}&version={$v['id']}");
    spa($this)->getJson("/api/v1/approvals/routing_version/{$v['id']}")->assertOk()->assertJsonPath('data.approvers.0.name', apName($this->t, $approver['id']));
    spa($this)->getJson("/api/v1/approvals/unknown_kind/{$v['id']}")->assertNotFound();

    // a role without the view permission of the kind learns nothing
    rtSwitch($this, $this->t, 'TENANT_ADMIN');
    spa($this)->getJson("/api/v1/approvals/routing_version/{$v['id']}")->assertForbidden();
})->group('integration', 'master');
