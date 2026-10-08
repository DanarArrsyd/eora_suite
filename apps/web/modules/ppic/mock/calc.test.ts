import { describe, expect, it } from 'vitest';
import { blanketSummary, bucketOf, canRelease, coilEstimate, outstanding, projectLines, sheetEstimate, varianceIndicators, weeklyDemand } from './calc';
import { BLANKET_ORDERS, CONVERSIONS, DELIVERY_RISK, DEMAND_LINES, FG_AVAILABILITY, MATERIAL_VARIANCE, RM_LOTS, WEEKLY_DEMAND } from './world';

const coil = {
  basis: 'COIL_PITCH' as const,
  densityGcm3: 7.85,
  thicknessMm: 1.2,
  widthMm: 120,
  pitch: 320,
  pitchUnit: 'mm' as const,
  cavity: 1,
  headTailMm: 1500,
  standardYield: 0.97,
};

describe('material conversion (doc 02, doc 14 #5, #49)', () => {
  it('coil sample: 700 kg → L 619,249.8 mm → 1,930 pcs; lower bound applies SY', () => {
    const r = coilEstimate(700, coil, { cpValidated: true, nominal: false });
    expect(r.status).toBe('CALCULATED');
    expect(r.lengthMm).toBeCloseTo(619249.8, 1);
    expect(r.upper).toBe(1930);
    expect(r.lower).toBe(Math.floor(1930 * 0.97));
  });
  it('pitch without unit is not calculable (never 700 ÷ 0.32)', () => {
    const r = coilEstimate(700, { ...coil, pitch: 0.32, pitchUnit: null }, { cpValidated: true, nominal: false });
    expect(r.status).toBe('NOT_CALCULABLE');
    expect(r.missing).toContain('pitch unit');
    expect(r.upper).toBeNull();
  });
  it('nominal properties or unvalidated CP make the estimate provisional', () => {
    expect(coilEstimate(700, coil, { cpValidated: true, nominal: true }).status).toBe('PROVISIONAL');
    expect(sheetEstimate(80, { basis: 'SHEET_LAYOUT', blanksPerSheet: 10, partsPerBlank: 1 }, { cpValidated: false, nominal: false }).status).toBe(
      'PROVISIONAL',
    );
  });
  it('sheet: 79 sheets × 10 = 790', () =>
    expect(sheetEstimate(79, { basis: 'SHEET_LAYOUT', blanksPerSheet: 10, partsPerBlank: 1 }, { cpValidated: true, nominal: false }).upper).toBe(790));
  it('sample lot C-21 shows 1,930 estimated pcs and alternative parts are separate rows', () => {
    expect(RM_LOTS.find((l) => l.lot === 'C-21')?.estPcsUpper).toBe(1930);
    const rows = CONVERSIONS.filter((c) => c.lot === 'C-21');
    expect(rows.map((c) => c.part).sort()).toEqual(['PART-A', 'PART-F']);
  });
  it('estimated pcs never appears as FG (doc 14 #49)', () => {
    expect(FG_AVAILABILITY.filter((f) => f.part === 'PART-A').reduce((a, f) => a + f.available, 0)).toBe(120);
  });
});

describe('variance indicators (ADR-0005)', () => {
  it('coil sample: GYV 47 (2.78 %), PvS −17, UMV 7.610 kg — all FINAL', () => {
    const r = varianceIndicators({
      T: 1688,
      G: 1620,
      NR: 12,
      NJ: 9,
      O: 0,
      SY: 0.97,
      NW: 0.29,
      Ckg: 612.4,
      S: 128.9,
      measured: true,
      cpValidated: true,
      reportingClosed: true,
    });
    expect([r.gyv, r.gyvPct, r.pvs, r.umvKg]).toEqual([47, 2.78, -17, 7.61]);
    expect([r.gyvStatus, r.pvsStatus, r.umvStatus]).toEqual(['FINAL', 'FINAL', 'FINAL']);
  });
  it('sample run RUN-0412 matches the doc example and opens a case on breach', () => {
    const run = MATERIAL_VARIANCE.find((m) => m.run === 'RUN-0412')!;
    expect([run.theoretical, run.gyv, run.pvs, run.umvKg]).toEqual([1688, 47, -17, 7.61]);
    expect(run.breach).toBe(true);
    expect(run.confirmedLossKg).toBeNull();
  });
  it('sheet B: GYV 25 (3.16 %) is provisional while reporting is open', () => {
    const run = MATERIAL_VARIANCE.find((m) => m.run === 'RUN-0418')!;
    expect([run.theoretical, run.gyv, run.gyvPct, run.gyvStatus]).toEqual([790, 25, 3.16, 'PROVISIONAL']);
  });
  it('by-difference consumption never becomes FINAL (doc 14 #24)', () => {
    const run = MATERIAL_VARIANCE.find((m) => m.method === 'UNMEASURED_BY_DIFFERENCE')!;
    expect(run.gyvStatus).not.toBe('FINAL');
    expect(run.umvStatus).not.toBe('FINAL');
    expect(run.case).not.toBeNull();
  });
  it('missing CP → NOT_CALCULABLE, missing scrap → UMV NOT_CALCULABLE', () => {
    expect(MATERIAL_VARIANCE.find((m) => m.part === 'PART-E')!.gyvStatus).toBe('NOT_CALCULABLE');
    expect(MATERIAL_VARIANCE.find((m) => m.scrapKg === null)!.umvStatus).toBe('NOT_CALCULABLE');
  });
});

describe('demand rules (BR-DEM-07/08/10/13/14, doc 14 #28–#48)', () => {
  it('partial fulfilment: 250 required, 100 shipped → outstanding 150', () => {
    expect(outstanding(250, 100)).toEqual({ outstanding: 150, overDelivered: 0 });
    expect(DEMAND_LINES.find((l) => l.scheduleRef === 'DSL-1')).toMatchObject({ status: 'PARTIALLY_FULFILLED', outstanding: 150 });
  });
  it('revision: superseded DSL-3 counts zero; W43 demand is 300 not 550', () => {
    const w43 = DEMAND_LINES.filter((l) => l.bucket === '2026-W43' && l.part === 'PART-A');
    expect(w43.reduce((a, l) => a + l.outstanding, 0)).toBe(300);
    expect(w43.find((l) => l.scheduleRef === 'DSL-3')?.status).toBe('SUPERSEDED');
  });
  it('blanket: 10,000 / released 1,300 / delivered 800 / remaining 500 / unscheduled 8,700 (#43)', () => {
    expect(BLANKET_ORDERS.find((b) => b.soNo === 'SO-2002')).toMatchObject({
      blanketQty: 10000,
      releasedQty: 1300,
      deliveredQty: 800,
      remainingScheduledQty: 500,
      unscheduledBalance: 8700,
    });
    expect(blanketSummary(100, [{ required: 50, delivered: 0, confirmed: false }]).unscheduledBalance).toBe(100);
  });
  it('blanket over-release is rejected (#44)', () => {
    expect(canRelease(9000, 8700)).toEqual({ ok: false, message: 'exceeds unscheduled blanket balance 8,700' });
    expect(canRelease(500, 8700)).toEqual({ ok: true });
  });
  it('forecast consumption within bucket by customer + part + plant (#30)', () => {
    const w42 = WEEKLY_DEMAND.find((w) => w.bucket === '2026-W42' && w.part === 'PART-A' && w.customer === 'CUST-01')!;
    expect(w42).toMatchObject({ forecast: 1200, firmRequired: 250, netForecast: 950, firmOutstanding: 250, outstandingDemand: 1200 });
  });
  it('delivery does not reopen forecast (#34)', () => {
    const [g] = weeklyDemand(
      [{ customer: 'C', part: 'P', plant: 'PA', bucket: 'W41', required: 250, outstanding: 0, status: 'FULFILLED' }],
      [{ customer: 'C', part: 'P', plant: 'PA', bucket: 'W41', qty: 300 }],
    );
    expect(g).toMatchObject({ netForecast: 50, outstandingDemand: 50 });
  });
  it('no matching by part alone; forecast without customer needs review and is not netted (#45, #46)', () => {
    const rows = weeklyDemand(
      [{ customer: 'C-02', part: 'P', plant: 'PA', bucket: 'W42', required: 250, outstanding: 250, status: 'FIRM' }],
      [
        { customer: 'C-01', part: 'P', plant: 'PA', bucket: 'W42', qty: 600 },
        { customer: null, part: 'P', plant: 'PA', bucket: 'W42', qty: 400 },
      ],
    );
    expect(rows.reduce((a, r) => a + r.outstandingDemand, 0)).toBe(850);
    expect(rows.find((r) => r.customer === null)?.matchStatus).toBe('NEEDS_REVIEW');
    expect(WEEKLY_DEMAND.some((w) => w.matchStatus === 'NEEDS_REVIEW' && w.part === 'PART-C')).toBe(true);
  });
  it('weekly buckets keep daily dates; ISO week Monday start (#47)', () => {
    expect(bucketOf('2026-10-13')).toEqual({ bucket: '2026-W42', bucketStart: '2026-10-12' });
    expect(bucketOf('2026-10-18').bucket).toBe('2026-W42');
    expect(bucketOf('2026-10-19').bucket).toBe('2026-W43');
    const w42c = DEMAND_LINES.filter((l) => l.part === 'PART-C' && l.bucket === '2026-W42');
    expect(w42c.map((l) => l.requirementDate)).toEqual(['2026-10-13', '2026-10-16']);
  });
  it('daily risk inside a covered week is flagged (#48)', () => {
    const p = projectLines(
      [
        { id: 'a', part: 'X', dueDate: '2026-10-13', outstanding: 150 },
        { id: 'b', part: 'X', dueDate: '2026-10-16', outstanding: 50 },
      ],
      { X: { fgAvailable: 120, qcReleased: 0, plannedByDate: [{ date: '2026-10-15', qty: 200 }] } },
    );
    expect(p.get('a')!.gap).toBe(30);
    expect(p.get('b')!.gap).toBe(0);
    expect(DELIVERY_RISK.find((x) => x.scheduleRef === 'DSL-31')?.atRisk).toBe(true);
  });
  it('pending QC is never part of the projection (doc 19 CT-A2)', () => {
    const d = DELIVERY_RISK.find((x) => x.part === 'PART-D')!;
    expect(d.projected).toBe(d.fgAvailable + d.qcReleased + d.plannedOutput - d.earlierDemand);
  });
});
