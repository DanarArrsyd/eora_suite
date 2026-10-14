import AxeBuilder from '@axe-core/playwright';
import { expect, mockApi, problem, SESSION, test, type Page, choose } from './fixtures';

/* M1 Administration → Users, Roles & Permissions (doc 28 §1) against a mocked API; the real flow runs in the stack E2E. */

const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];
const ADMIN = { ...SESSION, permissions: ['core.audit.view', 'core.users_roles.create', 'core.users_roles.post', 'core.users_roles.view'] };
const PLANT = SESSION.plants[0]!;

const ROLES = [
  {
    id: 'r-admin',
    code: 'TENANT_ADMIN',
    name: 'Tenant Administrator',
    templateCode: 'TENANT_ADMIN',
    defaultScopeType: 'TENANT',
    permissions: ADMIN.permissions,
  },
  {
    id: 'r-qci',
    code: 'QCI',
    name: 'Quality Inspector',
    templateCode: 'QCI',
    defaultScopeType: 'PLANT',
    permissions: ['quality.inspection.create', 'quality.inspection.view'],
  },
  { id: 'r-apr', code: 'APR', name: 'Approver', templateCode: 'APR', defaultScopeType: null, permissions: [] },
];

function assignment(id: string, membershipId: string, role: (typeof ROLES)[number], plantId: string | null) {
  return {
    id,
    membershipId,
    roleId: role.id,
    roleCode: role.code,
    scopeType: plantId ? 'PLANT' : 'TENANT',
    companyId: null,
    plantId,
    validFrom: '2026-10-01T00:00:00+07:00',
    validUntil: null,
    grantedBy: SESSION.user.id,
    grantReason: 'initial setup',
    revokedAt: null,
    revokeReason: null,
    effective: true,
  };
}

const MEMBERS = [
  {
    membershipId: 'm-self',
    userId: SESSION.user.id,
    displayName: 'Review User',
    email: SESSION.user.email,
    status: 'ACTIVE',
    assignments: [assignment('a-self', 'm-self', ROLES[0]!, null)],
  },
  {
    membershipId: 'm-rina',
    userId: 'u-rina',
    displayName: 'Rina Wulandari',
    email: 'rina@example.test',
    status: 'ACTIVE',
    assignments: [assignment('a-rina', 'm-rina', ROLES[1]!, PLANT.id)],
  },
  { membershipId: 'm-budi', userId: 'u-budi', displayName: 'Budi Santoso', email: 'budi@example.test', status: 'INVITED', assignments: [] },
];

async function asAdmin(page: Page, permissions = ADMIN.permissions) {
  await mockApi(page, '/auth/me', { status: 200, json: { ...ADMIN, permissions } });
  await mockApi(page, '/core/members', { status: 200, json: { data: MEMBERS } });
  await mockApi(page, '/core/roles', { status: 200, json: { data: ROLES } });
}

async function axe(page: Page) {
  await page.waitForTimeout(250);
  const r = await new AxeBuilder({ page }).withTags(TAGS).analyze();
  expect(r.violations.map((v) => `${v.id}: ${v.nodes.map((n) => n.target.join(' ')).join(', ')}`)).toEqual([]);
}

test('users: members with status and roles in scope; status tabs count from the list', async ({ page }) => {
  await asAdmin(page);
  await page.goto('/admin/users');
  await expect(page.getByRole('heading', { level: 1, name: 'Users' })).toBeVisible();
  const table = page.getByRole('table', { name: 'Users' });
  await expect(table.getByRole('row', { name: /Rina Wulandari/ })).toContainText('QCI · Plant P1 · Plant 1');
  await expect(table.getByRole('row', { name: /Budi Santoso/ })).toContainText('No role');
  await expect(page.getByRole('tab', { name: 'Invited, 1' })).toBeVisible();

  await page.getByRole('tab', { name: 'Invited, 1' }).click();
  await expect(page).toHaveURL(/view=INVITED/);
  await expect(table.getByRole('row', { name: /Rina/ })).toHaveCount(0);
  await expect(table.getByRole('row', { name: /Budi Santoso/ })).toBeVisible();
  await axe(page);
});

test('users: own access cannot be changed here (SoD-06); the reason is shown', async ({ page }) => {
  await asAdmin(page);
  await page.goto('/admin/users');
  await page.getByRole('button', { name: 'Manage Review User' }).click();
  const drawer = page.getByRole('dialog', { name: 'Review User' });
  await expect(drawer.getByRole('button', { name: /Suspend\. Your own access can be changed only by another administrator\./ })).toHaveAttribute(
    'aria-disabled',
    'true',
  );
  await expect(drawer.getByRole('button', { name: /Revoke TENANT_ADMIN\. Your own access/ })).toHaveAttribute('aria-disabled', 'true');
});

test('users: invite sends the e-mail with the CSRF header and confirms', async ({ page }) => {
  await asAdmin(page);
  let body: unknown;
  await mockApi(page, '/core/members/invitations', (route) => {
    expect(route.request().headers()['x-xsrf-token']).toBe('e2e-token');
    body = route.request().postDataJSON();
    return { status: 201, json: { data: { ...MEMBERS[2], membershipId: 'm-new', email: 'new@example.test', displayName: 'new' } } };
  });
  await page.goto('/admin/users');
  await page.getByRole('button', { name: 'Invite user' }).click();
  const dialog = page.getByRole('dialog', { name: 'Invite user' });
  await dialog.getByLabel(/^E-mail/).fill('not-an-email');
  await dialog.getByRole('button', { name: 'Send invitation' }).click();
  await expect(dialog.getByText('Enter a valid e-mail address.').first()).toBeVisible();
  await dialog.getByLabel(/^E-mail/).fill('new@example.test');
  await dialog.getByRole('button', { name: 'Send invitation' }).click();
  await expect(page.getByRole('main').getByText('Invitation sent to new@example.test.')).toBeVisible();
  expect(body).toEqual({ email: 'new@example.test' });
});

test('users: assign a plant-scoped role with a reason; the template scope is suggested', async ({ page }) => {
  await asAdmin(page);
  let body: Record<string, unknown> | undefined;
  await mockApi(page, '/core/role-assignments', (route) => {
    body = route.request().postDataJSON() as Record<string, unknown>;
    return { status: 201, json: { data: assignment('a-new', 'm-budi', ROLES[1]!, PLANT.id) } };
  });
  await page.goto('/admin/users');
  await page.getByRole('button', { name: 'Manage Budi Santoso' }).click();
  const drawer = page.getByRole('dialog', { name: 'Budi Santoso' });
  await drawer.getByRole('button', { name: 'Assign role' }).click();
  await choose(page, drawer.getByRole('combobox', { name: /^Role/ }), 'Quality Inspector (QCI)');
  await expect(drawer.getByRole('combobox', { name: /^Scope/ })).toHaveText(/One plant \(suggested\)/);
  await drawer.getByRole('button', { name: 'Assign role' }).click();
  await expect(drawer.getByText('Choose a plant').last()).toBeVisible();
  await choose(page, drawer.getByRole('combobox', { name: /^Plant/ }), `${PLANT.code} · ${PLANT.name}`);
  await drawer.getByLabel(/^Reason/).fill('QC line 2, ticket UAT-01');
  await drawer.getByRole('button', { name: 'Assign role' }).click();
  await expect(drawer.getByText('Quality Inspector assigned to Budi Santoso')).toBeVisible();
  expect(body).toEqual({
    membershipId: 'm-budi',
    roleId: 'r-qci',
    scopeType: 'PLANT',
    plantId: PLANT.id,
    validUntil: null,
    reason: 'QC line 2, ticket UAT-01',
  });
});

test('users: when roles fail to load, assigning waits and offers a retry', async ({ page }) => {
  await asAdmin(page);
  await mockApi(page, '/core/roles', problem(503, 'RETRYABLE_CONFLICT', 'Retry'));
  await page.goto('/admin/users');
  await page.getByRole('button', { name: 'Manage Budi Santoso' }).click();
  const drawer = page.getByRole('dialog', { name: 'Budi Santoso' });
  await expect(drawer.getByRole('alert')).toContainText('Roles could not be loaded');
  await expect(drawer.getByRole('button', { name: /Assign role\. Roles/ })).toHaveAttribute('aria-disabled', 'true');
});

test('users: a scope refusal is explained and nothing is claimed as saved', async ({ page }) => {
  await asAdmin(page);
  await mockApi(page, '/core/role-assignments', problem(403, 'SCOPE_FORBIDDEN', 'Forbidden'));
  await page.goto('/admin/users');
  await page.getByRole('button', { name: 'Manage Budi Santoso' }).click();
  const drawer = page.getByRole('dialog', { name: 'Budi Santoso' });
  await drawer.getByRole('button', { name: 'Assign role' }).click();
  await choose(page, drawer.getByRole('combobox', { name: /^Role/ }), 'Tenant Administrator (TENANT_ADMIN)');
  await drawer.getByLabel(/^Reason/).fill('needs admin');
  await drawer.getByRole('button', { name: 'Assign role' }).click();
  await expect(drawer.getByRole('alert')).toContainText('This is outside the scope you administer.');
  await expect(drawer.getByRole('alert')).toContainText('Nothing was saved.');
});

test('users: revoking a role needs a written reason', async ({ page }) => {
  await asAdmin(page);
  let reason: unknown;
  await mockApi(page, '/core/role-assignments/a-rina/actions/revoke', (route) => {
    reason = (route.request().postDataJSON() as { reason: string }).reason;
    return {
      status: 200,
      json: { data: { ...assignment('a-rina', 'm-rina', ROLES[1]!, PLANT.id), revokedAt: '2026-10-14T10:00:00+07:00', effective: false } },
    };
  });
  await page.goto('/admin/users');
  await page.getByRole('button', { name: 'Manage Rina Wulandari' }).click();
  await page.getByRole('button', { name: 'Revoke QCI, Plant P1 · Plant 1' }).click();
  const confirm = page.getByRole('alertdialog', { name: 'Revoke role?' });
  await confirm.getByRole('button', { name: 'Revoke role' }).click();
  await expect(confirm.getByText('Enter at least 3 characters')).toBeVisible();
  await confirm.getByLabel(/^Reason/).fill('moved to another team');
  await confirm.getByRole('button', { name: 'Revoke role' }).click();
  await expect(confirm).toBeHidden();
  expect(reason).toBe('moved to another team');
});

test('users: suspending asks for confirmation first', async ({ page }) => {
  await asAdmin(page);
  let called = false;
  await mockApi(page, '/core/members/m-rina/actions/suspend', () => {
    called = true;
    return { status: 200, json: { data: { ...MEMBERS[1], status: 'SUSPENDED' } } };
  });
  await page.goto('/admin/users');
  await page.getByRole('button', { name: 'More actions for Rina Wulandari' }).click();
  await page.getByRole('menuitem', { name: 'Suspend', exact: true }).click();
  const confirm = page.getByRole('alertdialog', { name: 'Suspend member?' });
  expect(called).toBe(false);
  await confirm.getByRole('button', { name: 'Suspend member' }).click();
  await expect(page.getByRole('main').getByText('Rina Wulandari is suspended.')).toBeVisible();
  expect(called).toBe(true);
});

test('users: without the view permission the page says so instead of failing', async ({ page }) => {
  await mockApi(page, '/auth/me', { status: 200, json: SESSION });
  await page.goto('/admin/users');
  await expect(page.getByText('Users is not available for your roles')).toBeVisible();
});

test('roles: permissions and holders in scope; an empty template is explained', async ({ page }) => {
  await asAdmin(page);
  await page.goto('/admin/roles');
  const table = page.getByRole('table', { name: 'Roles' });
  await expect(table.getByRole('row', { name: /Approver/ })).toContainText('None yet');
  await page.getByRole('button', { name: 'View Quality Inspector (QCI)' }).click();
  const drawer = page.getByRole('dialog', { name: 'Quality Inspector (QCI)' });
  await expect(drawer.getByText('quality.inspection.view')).toBeVisible();
  await expect(drawer.getByRole('list', { name: 'Members with QCI' })).toContainText('Rina Wulandari');
  await axe(page);
});

test('roles: when members fail to load, the drawer says so instead of claiming nobody holds the role', async ({ page }) => {
  await asAdmin(page);
  await mockApi(page, '/core/members', problem(503, 'RETRYABLE_CONFLICT', 'Retry'));
  await page.goto('/admin/roles');
  await page.getByRole('button', { name: 'View Quality Inspector (QCI)' }).click();
  const drawer = page.getByRole('dialog', { name: 'Quality Inspector (QCI)' });
  await expect(drawer.getByRole('alert')).toContainText('Members could not be loaded');
  await expect(drawer.getByText('Nobody holds this role')).toHaveCount(0);
});

for (const width of [1440, 768, 375]) {
  test(`users and roles fit ${width} px without page-level horizontal scroll`, async ({ page }) => {
    await page.setViewportSize({ width, height: 800 });
    await asAdmin(page);
    for (const path of ['/admin/users', '/admin/roles']) {
      await page.goto(path);
      await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
      expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
    }
  });
}
