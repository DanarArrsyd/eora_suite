'use client';

import * as React from 'react';
import { CircleCheck, Download, Ellipsis, Plus, Printer, RefreshCw, Trash2 } from 'lucide-react';
import {
  Badge,
  Button,
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
  DrawerClose,
  DrawerContent,
  Drawer,
  DrawerTrigger,
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
  EmptyState,
  ErrorState,
  Field,
  IconButton,
  Input,
  Kbd,
  LoadingState,
  MockDataLabel,
  Modal,
  ModalClose,
  ModalBody,
  ModalContent,
  ModalFooter,
  ModalTrigger,
  NumericBadge,
  Select,
  Skeleton,
  Tabs,
  TabsContent,
  TabsList,
  TabsTrigger,
  Textarea,
  Tooltip,
} from '@eora/ui';
import { StatusTabs } from '@eora/features/patterns';

function Section({ title, description, children }: { title: string; description: string; children: React.ReactNode }) {
  return (
    <Card>
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

export function UiKitDemos() {
  const [qty, setQty] = React.useState('');
  const [view, setView] = React.useState('all');
  const qtyError = qty !== '' && !/^\d+(\.\d{1,3})?$/.test(qty) ? 'Enter a number with up to 3 decimals, e.g. 612.400' : undefined;
  return (
    <div className="flex flex-col gap-5">
      <Section title="Buttons" description="One primary action per view region. Icon-only buttons always carry an accessible name.">
        <div className="flex flex-wrap items-center gap-2">
          <Button variant="primary">
            <Plus aria-hidden="true" />
            New RM receipt
          </Button>
          <Button>
            <Download aria-hidden="true" />
            Export
          </Button>
          <Button variant="ghost">Cancel</Button>
          <Button variant="link">View details</Button>
          <Button variant="destructive">
            <Trash2 aria-hidden="true" />
            Void draft
          </Button>
          <Button disabled>Post (disabled)</Button>
          <Button size="sm">Small</Button>
          <Tooltip content="Print">
            <IconButton aria-label="Print" variant="secondary">
              <Printer aria-hidden="true" />
            </IconButton>
          </Tooltip>
          <Tooltip content="Refresh">
            <IconButton aria-label="Refresh">
              <RefreshCw aria-hidden="true" />
            </IconButton>
          </Tooltip>
        </div>
      </Section>

      <Section title="Form controls" description="Field wires label, hint and error to the control (aria-describedby, aria-invalid). Control borders meet 3:1.">
        <div className="grid grid-cols-[repeat(auto-fill,minmax(240px,1fr))] gap-4">
          <Field label="Lot number" hint="Scan or type, e.g. C-08">
            {(p) => <Input {...p} placeholder="Lot number" />}
          </Field>
          <Field label="Weighed remaining (kg)" required error={qtyError} hint="Measured on scale SC-02">
            {(p) => <Input {...p} inputMode="decimal" value={qty} onChange={(e) => setQty(e.target.value)} placeholder="0.000" />}
          </Field>
          <Field label="Warehouse">
            {(p) => (
              <Select {...p} defaultValue="RM-WH1">
                <option>RM-WH1</option>
                <option>RM-WH2</option>
              </Select>
            )}
          </Field>
          <Field label="Disabled input">{(p) => <Input {...p} disabled defaultValue="Posted — read only" />}</Field>
          <Field label="Explanation" className="col-span-full">
            {(p) => <Textarea {...p} placeholder="What caused the variance and which evidence supports it" />}
          </Field>
        </div>
      </Section>

      <Section title="Status and badges" description="Text always present; numeric badges only for actionable counts (D-025).">
        <div className="flex flex-wrap items-center gap-2">
          <Badge tone="success">
            <CircleCheck aria-hidden="true" />
            Unrestricted
          </Badge>
          <Badge tone="warning">Pending QC</Badge>
          <Badge tone="danger">Rejected</Badge>
          <Badge tone="info">At production</Badge>
          <Badge tone="neutral">Release R1</Badge>
          <span className="inline-flex items-center gap-2 text-sm">
            Approvals inbox <NumericBadge count={3} label="to action" />
          </span>
          <MockDataLabel />
          <span className="text-sm">
            Shortcut <Kbd>Ctrl K</Kbd>
          </span>
        </div>
      </Section>

      <Section title="Overlays" description="Modal, drawer and menu use Radix: focus trap, Esc, focus return and scroll lock.">
        <div className="flex flex-wrap gap-2">
          <Modal>
            <ModalTrigger asChild>
              <Button>Open modal</Button>
            </ModalTrigger>
            <ModalContent title="Submit for review" description="Confirm submitting the case for independent review.">
              <ModalBody>
                <p className="m-0 text-sm text-ink-2">The resolution goes to the Inventory Controller. You cannot approve your own case.</p>
              </ModalBody>
              <ModalFooter>
                <ModalClose asChild>
                  <Button>Cancel</Button>
                </ModalClose>
                <ModalClose asChild>
                  <Button variant="primary">Submit</Button>
                </ModalClose>
              </ModalFooter>
            </ModalContent>
          </Modal>
          <Drawer>
            <DrawerTrigger asChild>
              <Button>Open drawer</Button>
            </DrawerTrigger>
            <DrawerContent title="Lot C-08 (sample)">
              <div className="flex-1 overflow-y-auto p-5 text-sm">
                <MockDataLabel />
                <p className="mt-3 text-ink-2">Detail drawer pattern used for row details (D-029: secondary information stays reachable without tooltips).</p>
              </div>
              <div className="flex justify-end gap-2 border-t border-line p-4">
                <DrawerClose asChild>
                  <Button>Close</Button>
                </DrawerClose>
              </div>
            </DrawerContent>
          </Drawer>
          <DropdownMenu modal={false}>
            <DropdownMenuTrigger asChild>
              <IconButton aria-label="More actions" variant="secondary">
                <Ellipsis aria-hidden="true" />
              </IconButton>
            </DropdownMenuTrigger>
            <DropdownMenuContent>
              <DropdownMenuItem>View details</DropdownMenuItem>
              <DropdownMenuItem>Movement ledger</DropdownMenuItem>
              <DropdownMenuSeparator />
              <DropdownMenuItem disabled>Adjust stock — requires Inventory Controller role</DropdownMenuItem>
            </DropdownMenuContent>
          </DropdownMenu>
        </div>
      </Section>

      <Section title="Tabs" description="Arrow keys move between tabs (Radix Tabs).">
        <Tabs defaultValue="summary">
          <TabsList aria-label="Example sections">
            <TabsTrigger value="summary">Summary</TabsTrigger>
            <TabsTrigger value="ledger">Ledger postings</TabsTrigger>
            <TabsTrigger value="audit">Audit trail</TabsTrigger>
          </TabsList>
          <TabsContent value="summary" className="pt-4 text-sm text-ink-2">
            Summary panel content.
          </TabsContent>
          <TabsContent value="ledger" className="pt-4 text-sm text-ink-2">
            Ledger panel content.
          </TabsContent>
          <TabsContent value="audit" className="pt-4 text-sm text-ink-2">
            Audit panel content.
          </TabsContent>
        </Tabs>
      </Section>

      <Section title="Status tabs" description="Saved views over approved dimensions. Counts belong to the view; the tab panel holds the shared table.">
        <div className="rounded-lg border border-line">
          <StatusTabs
            label="View by"
            value={view}
            onValueChange={setView}
            items={[
              { value: 'all', label: 'All', count: 11 },
              { value: 'unrestricted', label: 'Unrestricted', count: 6 },
              { value: 'pending-qc', label: 'Pending QC', count: 3 },
              { value: 'hold', label: 'Hold', count: 1 },
              { value: 'blocked', label: 'Blocked', count: 1 },
            ]}
          >
            <div className="flex items-center justify-between gap-3 p-4 text-sm text-ink-2">
              <span>Table for view “{view}” renders here (Phase 2 DataTable).</span>
              <MockDataLabel />
            </div>
          </StatusTabs>
        </div>
      </Section>

      <Section title="States" description="Every state names the cause and the next action.">
        <div className="grid grid-cols-[repeat(auto-fill,minmax(280px,1fr))] gap-4">
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
            <LoadingState title="Loading raw material lots…" />
          </div>
          <div className="flex flex-col gap-3 rounded-lg border border-line p-5" aria-hidden="true">
            <Skeleton className="w-3/4" />
            <Skeleton className="w-1/2" />
            <Skeleton className="w-2/3" />
          </div>
        </div>
      </Section>
    </div>
  );
}
