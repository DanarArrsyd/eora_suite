'use client';

import * as React from 'react';
import { Bookmark, ChevronDown, Plus, Users } from 'lucide-react';
import {
  Button,
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
  Field,
  Input,
  Modal,
  ModalBody,
  ModalClose,
  ModalContent,
  ModalFooter,
} from '@eora/ui';
import type { SavedView } from './types';

type Props = {
  views: SavedView[];
  onApply: (view: SavedView) => void;
  /** Persists the current filters as a personal view (server-side in production). */
  onSave: (name: string) => Promise<void> | void;
};

/** Saved views: my views, shared views and "Save current filters…" (EORA-COMPONENT-STANDARDS §4). */
export function SavedViewsMenu({ views, onApply, onSave }: Props) {
  const [saveOpen, setSaveOpen] = React.useState(false);
  const mine = views.filter((v) => v.scope === 'mine');
  const shared = views.filter((v) => v.scope === 'shared');
  return (
    <>
      <DropdownMenu modal={false}>
        <DropdownMenuTrigger asChild>
          <Button aria-label="Saved views">
            <Bookmark aria-hidden="true" />
            <span className="max-md:hidden">Views</span>
            <ChevronDown aria-hidden="true" />
          </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="start" className="w-64">
          <DropdownMenuLabel>My views</DropdownMenuLabel>
          {mine.length ? (
            mine.map((v) => (
              <DropdownMenuItem key={v.id} onSelect={() => onApply(v)}>
                <Bookmark aria-hidden="true" />
                {v.name}
              </DropdownMenuItem>
            ))
          ) : (
            <p className="m-0 px-3 py-1.5 text-[13px] text-ink-2">No saved views yet</p>
          )}
          {shared.length ? (
            <>
              <DropdownMenuLabel>Shared</DropdownMenuLabel>
              {shared.map((v) => (
                <DropdownMenuItem key={v.id} onSelect={() => onApply(v)}>
                  <Users aria-hidden="true" />
                  <span className="min-w-0 flex-1 truncate">{v.name}</span>
                  {v.sharedWith ? <span className="text-xs text-ink-2">{v.sharedWith}</span> : null}
                </DropdownMenuItem>
              ))}
            </>
          ) : null}
          <DropdownMenuSeparator />
          <DropdownMenuItem onSelect={() => setSaveOpen(true)}>
            <Plus aria-hidden="true" />
            Save current filters…
          </DropdownMenuItem>
        </DropdownMenuContent>
      </DropdownMenu>
      <SaveViewDialog open={saveOpen} onOpenChange={setSaveOpen} onSave={onSave} />
    </>
  );
}

function SaveViewDialog({ open, onOpenChange, onSave }: { open: boolean; onOpenChange: (v: boolean) => void; onSave: Props['onSave'] }) {
  const [name, setName] = React.useState('');
  const [error, setError] = React.useState<string>();
  const [busy, setBusy] = React.useState(false);
  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!name.trim()) {
      setError('Enter a name for this view');
      return;
    }
    setBusy(true);
    try {
      await onSave(name.trim());
      setName('');
      setError(undefined);
      onOpenChange(false);
    } finally {
      setBusy(false);
    }
  };
  return (
    <Modal open={open} onOpenChange={onOpenChange}>
      <ModalContent title="Save current filters as a view" closeLabel="Close">
        <form onSubmit={submit} noValidate className="flex min-h-0 flex-1 flex-col">
          <ModalBody className="flex flex-col gap-4">
            <p className="m-0 text-[13.5px] text-ink-2">The view stores the status tab, search and filters. Only you can see personal views.</p>
            <Field label="View name" required error={error}>
              {(c) => <Input {...c} value={name} maxLength={60} autoFocus onChange={(e) => setName(e.target.value)} />}
            </Field>
          </ModalBody>
          <ModalFooter>
            <ModalClose asChild>
              <Button>Cancel</Button>
            </ModalClose>
            <Button type="submit" variant="primary" disabled={busy}>
              Save view
            </Button>
          </ModalFooter>
        </form>
      </ModalContent>
    </Modal>
  );
}
