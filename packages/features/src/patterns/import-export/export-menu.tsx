'use client';

import * as React from 'react';
import { ChevronDown, Download, FileSpreadsheet, FileText, Printer } from 'lucide-react';
import { Button, DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@eora/ui';
import { useFeedback } from '../feedback';
import type { ExportFormat, ExportQuery, ExportSource } from './types';

type Props = {
  resource: string;
  source: ExportSource;
  filtersApplied: number;
  /** The table's current filters, search and sort, replayed by the export. */
  query?: ExportQuery;
  /** Formats the server can produce (default Excel and PDF; live lists export Excel only until PDF templates exist). */
  formats?: readonly ExportFormat[];
  /** Missing export permission: menu stays visible with the reason. */ disabledReason?: string;
};

/** EoraExportMenu — Excel / PDF from the same query scope as the table; large exports run as jobs and notify via the bell. */
export function ExportMenu({ resource, source, filtersApplied, query, formats = ['xlsx', 'pdf'], disabledReason }: Props) {
  const notify = useFeedback();
  const run = async (format: ExportFormat) => {
    try {
      const { jobId } = await source.queue(format, { resource, filtersApplied, query });
      notify({
        tone: 'success',
        title: `${format === 'xlsx' ? 'Excel' : 'PDF'} export queued`,
        description: source.isMock
          ? `Job ${jobId} uses the current filters (mock — no file is produced). You will be notified when it is ready.`
          : 'It uses the current filters, search and sort. You will be notified when the file is ready; it is also listed under Exports.',
      });
    } catch (e) {
      notify({
        tone: 'danger',
        title: 'Export could not be queued',
        description: e instanceof Error && e.message ? e.message : 'Nothing was exported. Try again or contact support.',
      });
    }
  };
  return (
    <DropdownMenu modal={false}>
      <DropdownMenuTrigger asChild>
        <Button aria-label="Export">
          <Download aria-hidden="true" />
          <span className="max-md:hidden">Export</span>
          <ChevronDown aria-hidden="true" />
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent className="w-72">
        {formats.map((f) => (
          <DropdownMenuItem
            key={f}
            aria-disabled={disabledReason ? true : undefined}
            onSelect={(e) => {
              if (disabledReason) e.preventDefault();
              else void run(f);
            }}
            className={disabledReason ? 'cursor-not-allowed text-ink-2' : undefined}
          >
            {f === 'xlsx' ? <FileSpreadsheet aria-hidden="true" /> : <FileText aria-hidden="true" />}
            {f === 'xlsx' ? 'Excel (.xlsx)' : 'PDF'} — current filters
          </DropdownMenuItem>
        ))}
        <DropdownMenuItem onSelect={() => window.print()}>
          <Printer aria-hidden="true" />
          Print
        </DropdownMenuItem>
        <DropdownMenuSeparator />
        <p className="m-0 px-3 py-1.5 text-xs text-ink-2">
          {disabledReason ?? 'Same server query and permissions as this table. Large exports run in the background and notify you.'}
        </p>
      </DropdownMenuContent>
    </DropdownMenu>
  );
}
