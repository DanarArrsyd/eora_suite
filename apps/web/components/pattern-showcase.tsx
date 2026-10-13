'use client';

import * as React from 'react';
import Link from 'next/link';
import { z } from 'zod';
import { Undo2 } from 'lucide-react';
import {
  ConfirmDialog,
  DomainStatusBadge,
  EoraForm,
  ExportMenu,
  FormQuantityField,
  FormSelectField,
  FormTextareaField,
  FormTextField,
  ImportWizard,
  NotificationCenter,
  Pagination,
  STATUS_CATALOGUE,
  useFeedback,
  type StatusDomain,
} from '@eora/features/patterns';
import {
  Button,
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
  Drawer,
  DrawerContent,
  DrawerTrigger,
  EmptyState,
  ErrorState,
  InlineAlert,
  LoadingState,
  MockDataLabel,
  PermissionDeniedState,
  Skeleton,
} from '@eora/ui';
import { mockExportSource, mockImportSource } from '@/lib/samples/import-export.mock';

function Section({ id, title, description, children }: { id: string; title: string; description: string; children: React.ReactNode }) {
  return (
    <Card id={id} className="scroll-mt-20">
      <CardHeader>
        <div>
          <CardTitle>{title}</CardTitle>
          <CardDescription>{description}</CardDescription>
        </div>
      </CardHeader>
      <CardContent>{children}</CardContent>
    </Card>
  );
}

const DOMAIN_LABEL: Record<StatusDomain, string> = {
  stock: 'Stock status (DD-01)',
  document: 'Document state (doc 16)',
  schedule: 'Delivery schedule line (D-034)',
  membership: 'Tenant membership (doc 25 §2.6)',
  indicator: 'Variance indicator (ADR-0005)',
};

/** Sample form schema — validation mirrors the server, it never replaces it. */
const issueSchema = z.object({
  lot: z.string().min(1, 'Choose a lot'),
  quantity: z
    .string()
    .min(1, 'Enter a quantity')
    .regex(/^\d+(\.\d{1,3})?$/, 'Use a number with up to 3 decimals, e.g. 612.400')
    .refine((v) => Number(v) > 0, 'Quantity must be greater than zero'),
  neededBy: z.string().min(1, 'Choose the date the material is needed'),
  note: z.string().max(200, 'Keep the note under 200 characters').optional(),
});

export function PatternShowcase() {
  const notify = useFeedback();
  const [confirmOpen, setConfirmOpen] = React.useState(false);
  const [page, setPage] = React.useState(3);
  const [size, setSize] = React.useState(20);

  return (
    <div className="flex flex-col gap-5">
      <nav aria-label="Patterns on this page" className="flex flex-wrap gap-x-4 gap-y-1 text-[13px]">
        {[
          ['list', 'Enterprise list'],
          ['badges', 'Status badges'],
          ['form', 'Form'],
          ['dialogs', 'Dialogs & drawer'],
          ['feedback', 'Feedback'],
          ['states', 'States'],
          ['pagination', 'Pagination'],
          ['import-export', 'Import / export'],
          ['notifications', 'Notifications'],
        ].map(([id, label]) => (
          <a key={id} href={`#${id}`} className="font-medium text-link hover:underline">
            {label}
          </a>
        ))}
      </nav>

      <Section
        id="list"
        title="Enterprise list (page template, status tabs, filter bar, data table)"
        description="The full composition runs on its own page so URL state, density and responsive reflow can be reviewed."
      >
        <Link href="/system/examples/raw-material-lots" className="font-medium text-link hover:underline">
          Open the raw material lots example →
        </Link>
      </Section>

      <Section id="badges" title="Status badge catalogue" description="One mapping per status domain. Text is always present; domains are never mixed.">
        <div className="grid grid-cols-[repeat(auto-fill,minmax(260px,1fr))] gap-4">
          {(Object.keys(STATUS_CATALOGUE) as StatusDomain[]).map((d) => (
            <div key={d}>
              <h3 className="mb-2 text-[13px] font-semibold">{DOMAIN_LABEL[d]}</h3>
              <div className="flex flex-wrap gap-1.5">
                {Object.keys(STATUS_CATALOGUE[d]).map((s) => (
                  <DomainStatusBadge key={s} domain={d} status={s as never} />
                ))}
              </div>
            </div>
          ))}
        </div>
      </Section>

      <Section
        id="form"
        title="Form (React Hook Form + Zod)"
        description="Labels, hints and errors wired for screen readers; error summary takes focus; submit is guarded and carries one idempotency key."
      >
        <div className="max-w-xl">
          <p className="mt-0 mb-3 flex items-center gap-2 text-xs text-ink-2">
            <MockDataLabel /> Submitting more than 450 kg returns a sample server-side field error.
          </p>
          <EoraForm
            label="Request material issue (sample)"
            schema={issueSchema}
            defaultValues={{ lot: '', quantity: '', neededBy: '', note: '' }}
            submitLabel="Submit request"
            onSubmit={async (values, { idempotencyKey }) => {
              await new Promise((r) => setTimeout(r, 600));
              if (Number(values.quantity) > 450) {
                return {
                  ok: false,
                  message: 'The request was not submitted',
                  fieldErrors: { quantity: 'Exceeds available 450.000 kg on this lot (sample server check)' },
                };
              }
              return { ok: true, message: `Mock: request submitted (idempotency key ${idempotencyKey.slice(0, 8)}…). Nothing was posted.` };
            }}
          >
            <FormSelectField
              name="lot"
              label="Lot"
              required
              placeholder="Choose a lot"
              options={[
                { value: 'C-108', label: 'C-108 · SPCC 1.2 × 120 coil' },
                { value: 'C-112', label: 'C-112 · SPHC 1.6 × 150 coil' },
              ]}
            />
            <div className="grid grid-cols-2 gap-4 max-md:grid-cols-1">
              <FormQuantityField name="quantity" label="Quantity" required uom="kg" decimals={3} />
              <FormTextField name="neededBy" label="Needed by" type="date" required />
            </div>
            <FormTextareaField name="note" label="Note for the warehouse" hint="Optional, up to 200 characters" rows={3} />
          </EoraForm>
        </div>
      </Section>

      <Section
        id="dialogs"
        title="Dialogs, confirmation and drawer"
        description="AlertDialog for irreversible actions (focus starts on Cancel, reason required for sensitive corrections); drawer for details."
      >
        <div className="flex flex-wrap gap-2">
          <Button variant="destructive" onClick={() => setConfirmOpen(true)}>
            <Undo2 aria-hidden="true" />
            Request reversal…
          </Button>
          <Drawer>
            <DrawerTrigger asChild>
              <Button>Open detail drawer</Button>
            </DrawerTrigger>
            <DrawerContent title="Shipment SHP-0042 (sample)" closeLabel="Close details">
              <div className="p-5 text-[13.5px] text-ink-2">
                Drawers show read-only detail and secondary actions. Esc closes and focus returns to the trigger.
              </div>
            </DrawerContent>
          </Drawer>
        </div>
        <ConfirmDialog
          open={confirmOpen}
          onOpenChange={setConfirmOpen}
          tone="danger"
          title="Request reversal of SHP-0042?"
          description="A reversal document is created for independent approval. The original shipment stays in history; nothing changes until it is approved."
          confirmLabel="Request reversal"
          reason={{ label: 'Reason (stored in the audit trail)', minLength: 10 }}
          onConfirm={async () => {
            await new Promise((r) => setTimeout(r, 500));
            notify({ tone: 'success', title: 'Mock: reversal requested', description: 'Waiting for approval. No stock changed.' });
          }}
        />
      </Section>

      <Section id="feedback" title="Feedback" description="Toasts confirm background actions only; persistent results use inline alerts and the inbox.">
        <div className="flex flex-col gap-4">
          <div className="flex flex-wrap gap-2">
            {(['info', 'success', 'warning', 'danger'] as const).map((t) => (
              <Button
                key={t}
                onClick={() => notify({ tone: t, title: `Sample ${t} message`, description: 'Toasts close after 6 s; hover or focus pauses them.' })}
              >
                Show {t} toast
              </Button>
            ))}
          </div>
          <div className="grid gap-2">
            <InlineAlert tone="info" title="Read model is updated every minute" />
            <InlineAlert tone="success" title="Posted as INV-2026-000412" />
            <InlineAlert tone="warning" title="Data may be outdated">
              Last update 47 minutes ago.
            </InlineAlert>
            <InlineAlert tone="danger" title="Posting failed — nothing was posted">
              Lot C-09 has 10.000 kg at this work center; 12.000 kg was requested.
            </InlineAlert>
          </div>
        </div>
      </Section>

      <Section
        id="states"
        title="Loading, empty, error and permission denied"
        description="Every state names the cause and the next action; no bare spinners or “No data”."
      >
        <div className="grid grid-cols-[repeat(auto-fill,minmax(280px,1fr))] gap-4">
          <div className="rounded-lg border border-line">
            <LoadingState title="Loading lots" />
            <div className="flex flex-col gap-2 px-6 pb-6">
              <Skeleton className="w-full" />
              <Skeleton className="w-4/5" />
              <Skeleton className="w-3/5" />
            </div>
          </div>
          <div className="rounded-lg border border-line">
            <EmptyState title="No lots match these filters" description="Clear filters or switch to All." action={<Button size="sm">Clear filters</Button>} />
          </div>
          <div className="rounded-lg border border-line">
            <ErrorState
              title="Lots could not be loaded"
              description="The server did not respond. Reference 7c1e-55a2."
              action={
                <Button size="sm" variant="primary">
                  Try again
                </Button>
              }
            />
          </div>
          <div className="rounded-lg border border-line">
            <PermissionDeniedState />
          </div>
        </div>
      </Section>

      <Section id="pagination" title="Pagination" description="Identical in every module; phones show the summary, current page and Prev/Next only.">
        <div className="rounded-lg border border-line">
          <Pagination page={page} pageSize={size} total={214} itemLabel="lots" onPageChange={setPage} onPageSizeChange={(s) => (setSize(s), setPage(1))} />
        </div>
      </Section>

      <Section
        id="import-export"
        title="Import / export shell"
        description="Export uses the table’s query scope and runs as a job; import validates, shows row errors, routes to approval and commits idempotently."
      >
        <div className="flex flex-wrap gap-2">
          <ImportWizard title="Import raw material lots" source={mockImportSource} />
          <ExportMenu resource="Raw material lots" source={mockExportSource} filtersApplied={2} />
          <ExportMenu resource="Raw material lots" source={mockExportSource} filtersApplied={0} disabledReason="Requires the inventory.export permission" />
        </div>
      </Section>

      <Section
        id="notifications"
        title="Notification center"
        description="Same component in the bell panel and on the Notifications page. Switch role in the account menu to see permission-aware links."
      >
        <div className="max-w-2xl rounded-lg border border-line">
          <NotificationCenter variant="page" />
        </div>
      </Section>
    </div>
  );
}
