'use client';

import * as React from 'react';
import { DomainStatusBadge, type FilterField } from '@eora/features/patterns';
import { Badge } from '@eora/ui';
import type { ConversionRow, MaterialAtProductionRow, RmLotRow } from '../contracts';
import { CtScreen, type CtScreenConfig } from '../components/ct-screen';
import { ConversionBadge, EstimatedPcs, MethodLabel } from '../components/badges';
import { fmtDateTime, fmtQty } from '../components/format';
import { HREF, PART_FIELD, PLANT_FIELD, partLabel, q } from './common';

const FORM_FIELD: FilterField = {
  key: 'form',
  type: 'select',
  source: 'static',
  label: 'Form',
  options: [
    { value: 'COIL', label: 'Coil' },
    { value: 'SHEET', label: 'Sheet' },
  ],
};
const GRADE_FIELD: FilterField = {
  key: 'grade',
  type: 'multiselect',
  source: 'static',
  label: 'Grade',
  options: ['SPCC', 'SPHC', 'SGCC'].map((g) => ({ value: g, label: g })),
};
const WAREHOUSE_FIELD: FilterField = {
  key: 'warehouse',
  type: 'select',
  source: 'static',
  label: 'Warehouse',
  options: ['RM-WH1', 'RM-WH2'].map((w) => ({ value: w, label: w })),
};
const PROPS: Record<RmLotRow['propertiesSource'], string> = { MILL_CERT: 'Mill certificate', MEASURED: 'Measured', NOMINAL: 'Item nominal' };

/* ------------------------------------------------------------------ CT-1 Raw material availability ------------------------------------------------------------------ */

const RM: CtScreenConfig<RmLotRow> = {
  id: 'rm',
  code: 'CT-1',
  title: 'Raw Material Availability',
  description:
    'Coil (kg) and sheet stock by lot, location and stock status, with allocation and the planning estimate for the primary part. Estimated pieces are not stock.',
  source: (s) => s.rmLots,
  itemLabel: 'lots',
  fields: [PLANT_FIELD, WAREHOUSE_FIELD, FORM_FIELD, GRADE_FIELD],
  views: [
    { value: 'all', label: 'All' },
    { value: 'unrestricted', label: 'Unrestricted' },
    { value: 'allocated', label: 'Allocated' },
    { value: 'hold', label: 'Hold' },
    { value: 'blocked', label: 'Blocked' },
    { value: 'notCalculable', label: 'Conversion not calculable' },
  ],
  viewNote: 'Views are saved filters over stock status, allocation and conversion status. Material at Production is in CT-2.',
  defaultSort: [{ id: 'lot', desc: false }],
  searchPlaceholder: 'Search lot, item, location…',
  rowLabel: (r) => `lot ${r.lot}`,
  groups: [{ id: 'qty', label: 'Material quantity (lot UOM)', title: 'On hand = available + allocated for unrestricted stock' }],
  columns: [
    { id: 'lot', header: 'Lot', lead: true, sortable: true, cell: (r) => <span className="font-medium">{r.lot}</span>, sub: (r) => r.item },
    { id: 'form', header: 'Form', cell: (r) => (r.form === 'COIL' ? 'Coil' : 'Sheet'), sub: (r) => r.grade },
    { id: 'location', header: 'Location', cell: (r) => <code className="rounded-sm bg-stat-neutral-bg px-1.5 py-0.5 font-mono text-xs">{r.location}</code> },
    { id: 'status', header: 'Status', critical: true, cell: (r) => <DomainStatusBadge domain="stock" status={r.status} /> },
    { id: 'onHand', header: 'On hand', numeric: true, critical: true, sortable: true, group: 'qty', cell: (r) => q(r.onHand, r.uom) },
    {
      id: 'allocated',
      header: 'Allocated (reserved)',
      numeric: true,
      critical: true,
      group: 'qty',
      cell: (r) => q(r.allocated, r.uom),
      emptyOnPhone: (r) => !r.allocated,
    },
    {
      id: 'available',
      header: 'Available',
      numeric: true,
      critical: true,
      sortable: true,
      group: 'qty',
      cell: (r) => <b className="font-semibold">{q(r.available, r.uom)}</b>,
    },
    {
      id: 'est',
      header: 'Est. pcs (primary part)',
      numeric: true,
      cell: (r) => <EstimatedPcs lower={r.estPcsLower} upper={r.estPcsUpper} />,
      sub: (r) => r.primaryPart,
    },
    { id: 'conversion', header: 'Conversion', cell: (r) => <ConversionBadge status={r.conversionStatus} missing={r.missingInputs} /> },
    { id: 'props', header: 'Properties', cell: (r) => PROPS[r.propertiesSource] },
  ],
  detail: (r) => ({
    title: `Lot ${r.lot}`,
    subtitle: `${r.item} · ${r.location} · ${PROPS[r.propertiesSource]}`,
    sections: [
      {
        title: `Quantity (${r.uom})`,
        rows: [
          ['On hand', fmtQty(r.onHand, r.uom)],
          ['Allocated (reservation, not a movement)', fmtQty(r.allocated, r.uom)],
          ['Available', fmtQty(r.available, r.uom)],
          ['Stock status', <DomainStatusBadge key="s" domain="stock" status={r.status} />],
        ],
      },
      {
        title: 'Planning estimate (not stock)',
        rows: [
          ['Primary part', partLabel(r.primaryPart)],
          ['Estimated pieces', <EstimatedPcs key="e" lower={r.estPcsLower} upper={r.estPcsUpper} />],
          ['Conversion', <ConversionBadge key="c" status={r.conversionStatus} missing={r.missingInputs} />],
        ],
      },
      { title: 'Allocations', rows: r.woAllocations.length ? r.woAllocations.map((w): [string, React.ReactNode] => ['Work order', w]) : [['None', '—']] },
    ],
    sources: [
      { label: 'Lot balance and receipt', ref: r.lot, href: HREF.rawMaterial },
      { label: 'Movement ledger', ref: r.lot, href: HREF.movements },
      { label: 'Allocations', ref: r.lot, href: HREF.allocations },
      { label: 'Conversion detail (all parts)', ref: r.lot, href: `/ppic/conversion?q=${encodeURIComponent(r.lot)}` },
    ],
  }),
  footnote:
    'Totals are never summed across kg and sheet. Estimated pieces for alternative parts of the same lot are listed in the conversion view and never added together.',
};

/* ------------------------------------------------------------------ Coil / sheet conversion ------------------------------------------------------------------ */

function inputsLine(r: ConversionRow): string {
  const i = r.inputs;
  return i.basis === 'COIL_PITCH'
    ? `ρ ${i.densityGcm3 ?? '?'} g/cm³ · t ${i.thicknessMm ?? '?'} mm · w ${i.widthMm ?? '?'} mm · pitch ${i.pitch ?? '?'} ${i.pitchUnit ?? '(no unit)'} · cavity ${i.cavity ?? '?'} · head/tail ${i.headTailMm ?? '?'} mm`
    : `${i.blanksPerSheet ?? '?'} blanks/sheet × ${i.partsPerBlank ?? '?'} parts/blank`;
}

const CONVERSION: CtScreenConfig<ConversionRow> = {
  id: 'conversion',
  code: 'CT-1 · Conversion',
  title: 'Coil / Sheet Conversion',
  description:
    'Planning estimate of pieces from available material per lot and part (doc 02). Alternative parts of the same lot are separate rows and never summed. Estimated pieces are not Finished Goods.',
  source: (s) => s.conversion,
  itemLabel: 'lot × part estimates',
  fields: [PLANT_FIELD, FORM_FIELD, PART_FIELD],
  views: [
    { value: 'all', label: 'All' },
    { value: 'calculated', label: 'Calculated' },
    { value: 'provisional', label: 'Provisional' },
    { value: 'notCalculable', label: 'Not calculable' },
  ],
  defaultSort: [{ id: 'lot', desc: false }],
  searchPlaceholder: 'Search lot or part…',
  rowLabel: (r) => `lot ${r.lot} for ${r.part}`,
  columns: [
    {
      id: 'lot',
      header: 'Lot',
      lead: true,
      sortable: true,
      cell: (r) => <span className="font-medium">{r.lot}</span>,
      sub: (r) => (r.form === 'COIL' ? 'Coil' : 'Sheet'),
    },
    { id: 'part', header: 'Part', cell: (r) => r.part, sub: (r) => r.bomRef ?? '—' },
    { id: 'availableQty', header: 'Material basis', numeric: true, critical: true, cell: (r) => q(r.availableQty, r.uom) },
    {
      id: 'basis',
      header: 'Method',
      cell: (r) => (r.inputs.basis === 'COIL_PITCH' ? 'Coil length ÷ pitch × cavity' : 'Sheet layout × parts per blank'),
      sub: (r) => inputsLine(r),
      textCol: true,
      title: (r) => inputsLine(r),
    },
    {
      id: 'sy',
      header: 'Standard yield',
      numeric: true,
      cell: (r) => (r.inputs.standardYield !== undefined ? `${(r.inputs.standardYield * 100).toFixed(0)} %` : '—'),
    },
    {
      id: 'estPcsUpper',
      header: 'Est. pcs (SY – gross)',
      numeric: true,
      critical: true,
      sortable: true,
      cell: (r) => <EstimatedPcs lower={r.estPcsLower} upper={r.estPcsUpper} />,
    },
    {
      id: 'cp',
      header: 'Conversion profile',
      cell: (r) =>
        r.cpVersion ? (
          <>
            {r.cpVersion} {r.cpValidated ? <Badge tone="success">Validated</Badge> : <Badge tone="warning">Not validated</Badge>}
          </>
        ) : (
          <Badge tone="neutral">Missing</Badge>
        ),
    },
    { id: 'status', header: 'Confidence', critical: true, cell: (r) => <ConversionBadge status={r.status} missing={r.missingInputs} /> },
  ],
  detail: (r) => ({
    title: `Conversion · ${r.lot} → ${r.part}`,
    subtitle: `${partLabel(r.part)} · ${r.cpVersion ?? 'no conversion profile'} · properties: ${PROPS[r.propertiesSource]}`,
    sections: [
      {
        title: 'Inputs (with units)',
        rows:
          r.inputs.basis === 'COIL_PITCH'
            ? [
                ['Net usable mass', `${fmtQty(r.availableQty, 'kg')} kg`],
                ['Density', r.inputs.densityGcm3 ? `${r.inputs.densityGcm3} g/cm³` : 'missing'],
                ['Thickness × width', `${r.inputs.thicknessMm ?? '?'} mm × ${r.inputs.widthMm ?? '?'} mm`],
                ['Pitch', r.inputs.pitchUnit ? `${r.inputs.pitch} ${r.inputs.pitchUnit}` : `${r.inputs.pitch ?? '?'} (unit missing)`],
                ['Cavity (BOM / die)', `${r.inputs.cavity ?? '?'} · ${r.bomRef ?? '—'}`],
                ['Head + tail allowance', `${r.inputs.headTailMm ?? '?'} mm`],
                ['Standard yield (SY)', r.inputs.standardYield !== undefined ? String(r.inputs.standardYield) : '—'],
              ]
            : [
                ['Eligible sheets', `${fmtQty(r.availableQty, 'sheet')} sheet`],
                ['Blanks per sheet (layout)', String(r.inputs.blanksPerSheet ?? 'missing')],
                ['Parts per blank (BOM)', `${r.inputs.partsPerBlank ?? 'missing'} · ${r.bomRef ?? '—'}`],
                ['Standard yield (SY)', r.inputs.standardYield !== undefined ? String(r.inputs.standardYield) : '—'],
              ],
      },
      {
        title: 'Result (planning estimate)',
        rows: [
          ...(r.lengthMm !== null
            ? ([
                ['Usable length', `${r.lengthMm.toLocaleString('en-US', { maximumFractionDigits: 1 })} mm`],
                ['Strokes', fmtQty(r.strokes ?? 0, 'pcs')],
              ] as [string, React.ReactNode][])
            : []),
          ['Gross estimate', r.estPcsUpper !== null ? `${fmtQty(r.estPcsUpper, 'pcs')} pcs` : '—'],
          ['After standard yield', r.estPcsLower !== null ? `${fmtQty(r.estPcsLower, 'pcs')} pcs` : '—'],
          ['Confidence', <ConversionBadge key="c" status={r.status} missing={r.missingInputs} />],
        ],
      },
    ],
    calculation: {
      title: r.inputs.basis === 'COIL_PITCH' ? 'Coil (doc 02)' : 'Sheet (doc 02)',
      body:
        r.inputs.basis === 'COIL_PITCH' ? (
          <>
            L = kg × 10⁶ ÷ (ρ × t × w); strokes = floor((L − head/tail) ÷ pitch); pcs = strokes × cavity; lower bound = floor(pcs × SY). A pitch without unit is
            never converted (700 kg ÷ 0.32 is dimensionally invalid).
          </>
        ) : (
          <>pcs = floor(eligible sheets × blanks per sheet × parts per blank); lower bound = floor(pcs × SY).</>
        ),
    },
    sources: [
      { label: 'Lot', ref: r.lot, href: HREF.rawMaterial },
      { label: 'Work orders for the part', ref: r.part, href: HREF.workOrders },
    ],
  }),
  footnote:
    'Calculated = validated profile and measured/certified properties; Provisional = nominal properties or unvalidated profile; Not calculable lists the missing inputs.',
};

/* ------------------------------------------------------------------ CT-2 Material at Production ------------------------------------------------------------------ */

const WC_FIELD: FilterField = {
  key: 'workCenter',
  type: 'select',
  source: 'static',
  label: 'Work center',
  options: ['WC-PRESS1', 'WC-PRESS2', 'WC-PRESS3'].map((w) => ({ value: w, label: w })),
};

const MAP: CtScreenConfig<MaterialAtProductionRow> = {
  id: 'map',
  code: 'CT-2',
  title: 'Material at Production',
  description:
    'Material issued to work centers and still physically there: remaining material, reusable remnants and material in transit. It is still material stock — never variance, never WIP.',
  source: (s) => s.materialAtProduction,
  itemLabel: 'lots at production',
  fields: [PLANT_FIELD, WC_FIELD],
  views: [
    { value: 'all', label: 'All' },
    { value: 'idle', label: 'Idle > 48 h' },
    { value: 'remnant', label: 'Reusable remnant' },
    { value: 'inTransit', label: 'In transit' },
    { value: 'unmeasured', label: 'Not measured yet' },
  ],
  defaultSort: [{ id: 'idleHours', desc: true }],
  searchPlaceholder: 'Search lot, item, WO…',
  rowLabel: (r) => `lot ${r.lot} at ${r.workCenter}`,
  columns: [
    {
      id: 'lot',
      header: 'Lot',
      lead: true,
      sortable: true,
      cell: (r) => <span className="font-medium">{r.lot}</span>,
      sub: (r) => (r.isRemnant ? `Remnant of ${r.parentLot}` : r.item),
    },
    { id: 'workCenter', header: 'Work center', cell: (r) => r.workCenter },
    { id: 'qty', header: 'At production', numeric: true, critical: true, sortable: true, cell: (r) => <b className="font-semibold">{q(r.qty, r.uom)}</b> },
    {
      id: 'custody',
      header: 'Custody',
      cell: (r) => (r.custody === 'ACCEPTED' ? <Badge tone="success">Accepted</Badge> : <Badge tone="info">In transit</Badge>),
    },
    { id: 'type', header: 'Type', cell: (r) => (r.isRemnant ? <Badge tone="info">Reusable remnant</Badge> : 'Remaining material') },
    { id: 'wo', header: 'WO allocation', cell: (r) => r.woAllocations.join(', ') || '—' },
    {
      id: 'idleHours',
      header: 'Idle',
      numeric: true,
      sortable: true,
      cell: (r) => (r.idleHours > 48 ? <Badge tone="warning">{r.idleHours} h</Badge> : `${r.idleHours} h`),
    },
    {
      id: 'measurement',
      header: 'Last measurement',
      cell: (r) => <MethodLabel method={r.lastMeasurement.method} />,
      sub: (r) => (r.lastMeasurement.at ? fmtDateTime(r.lastMeasurement.at) : '—'),
    },
  ],
  detail: (r) => ({
    title: `${r.lot} at ${r.workCenter}`,
    subtitle: `${r.item} · ${r.isRemnant ? `reusable remnant split from ${r.parentLot}` : 'remaining material'} · last movement ${fmtDateTime(r.lastMovement)}`,
    sections: [
      {
        title: `Conservation for the period (${r.uom})`,
        rows: [
          ['Issued to production', fmtQty(r.conservation.issued, r.uom)],
          ['Actual consumed (measured)', fmtQty(r.conservation.consumed, r.uom)],
          ['Still at production', fmtQty(r.conservation.atProduction, r.uom)],
          ['Returned to warehouse', fmtQty(r.conservation.returned, r.uom)],
          ['Split to reusable remnant', fmtQty(r.conservation.splitToRemnant, r.uom)],
        ],
      },
    ],
    calculation: {
      title: 'ConservationLine',
      body: (
        <>
          Issued = consumed + at production + returned + split to remnant: {fmtQty(r.conservation.issued, r.uom)} = {fmtQty(r.conservation.consumed, r.uom)} +{' '}
          {fmtQty(r.conservation.atProduction, r.uom)} + {fmtQty(r.conservation.returned, r.uom)} + {fmtQty(r.conservation.splitToRemnant, r.uom)}. Issue is a
          transfer; only consumption depletes material.
        </>
      ),
    },
    sources: [
      { label: 'Material issue', ref: r.lot, href: HREF.materialIssue },
      { label: 'Measurements', ref: r.lot, href: HREF.measurements },
      ...(r.isRemnant ? [{ label: 'Remnant split', ref: `${r.parentLot} → ${r.lot}`, href: HREF.remnants }] : []),
      { label: 'Movement ledger', ref: r.lot, href: HREF.movements },
    ],
  }),
  footnote: 'Remaining material and remnants stay material stock at the work center until returned, transferred or consumed with a measurement (ADR-0003).',
};

export function RmAvailabilityScreen() {
  return <CtScreen config={RM} />;
}
export function ConversionScreen() {
  return <CtScreen config={CONVERSION} />;
}
export function MaterialAtProductionScreen() {
  return <CtScreen config={MAP} />;
}
