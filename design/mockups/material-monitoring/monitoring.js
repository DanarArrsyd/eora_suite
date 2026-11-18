/* Review-only state. Every operational quantity on this page is explicit sample data. */
(() => {
  'use strict';
  const $ = (selector) => document.querySelector(selector);
  const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[char]);
  const number = (value) => new Intl.NumberFormat('id-ID', { maximumFractionDigits: 3 }).format(value);
  const quantity = (value, unit = 'pcs') => `${number(value)} ${unit}`;
  const sum = (rows, get) => rows.reduce((total, row) => total + get(row), 0);
  const icons = () => window.lucide?.createIcons();
  const customers = ['DPM', 'SJM', 'AMI', 'SUP'];
  const ownerLabel = (owner) => (owner === 'KMI' ? 'Perusahaan (contoh KMI)' : `Aset ${owner}`);
  const definitions = {
    sheet: { spec: '1,6 × 120 × 1219', name: 'Sheet SPCC', unit: 'sheet', rate: 20 },
    coil: { spec: '2,0 × 150 · SPHC', name: 'Coil SPHC', unit: 'kg', rate: null },
  };
  const initial = {
    receipts: [
      {
        id: 'r1',
        lot: 'RM-DPM-091126-0001',
        owner: 'DPM',
        material: 'sheet',
        qty: 100,
        date: '2026-11-09',
        supplier: 'DPM · titipan customer',
        supplierLot: 'HEAT-D0911',
        actor: 'Petugas RM contoh',
        blocked: false,
      },
      {
        id: 'r2',
        lot: 'RM-KMI-091126-0001',
        owner: 'KMI',
        material: 'sheet',
        qty: 100,
        date: '2026-11-09',
        supplier: 'Supplier Baja contoh',
        supplierLot: 'HEAT-K0911',
        actor: 'Petugas RM contoh',
        blocked: false,
      },
      {
        id: 'r3',
        lot: 'RM-SJM-091126-0001',
        owner: 'SJM',
        material: 'coil',
        qty: 400,
        date: '2026-11-09',
        supplier: 'Supplier Baja contoh · pemilik SJM',
        supplierLot: 'HEAT-S0911',
        actor: 'Petugas RM contoh',
        blocked: false,
      },
      {
        id: 'r4',
        lot: 'RM-AMI-091126-0001',
        owner: 'AMI',
        material: 'sheet',
        qty: 50,
        date: '2026-11-09',
        supplier: 'AMI · titipan customer',
        supplierLot: 'HEAT-A0911',
        actor: 'Petugas RM contoh',
        blocked: true,
      },
      {
        id: 'r5',
        lot: 'RM-SUP-091126-0001',
        owner: 'SUP',
        material: 'sheet',
        qty: 40,
        date: '2026-11-09',
        supplier: 'SUP · titipan customer',
        supplierLot: 'HEAT-U0911',
        actor: 'Petugas RM contoh',
        blocked: false,
      },
      {
        id: 'r6',
        lot: 'RM-DPM-311026-0001',
        owner: 'DPM',
        material: 'sheet',
        qty: 2,
        date: '2026-10-31',
        supplier: 'DPM · saldo sebelumnya',
        supplierLot: 'HEAT-D3110',
        actor: 'Petugas RM contoh',
        blocked: false,
      },
    ],
    jobs: [
      {
        id: 'w1',
        wo: 'WO-261109-001',
        customer: 'DPM',
        part: 'Bracket Inner',
        partNo: 'DPM-BI-001',
        material: 'sheet',
        qty: 600,
        due: '2026-11-10',
        date: '2026-11-09',
        shift: '1',
        produced: true,
        inputs: [{ receipt: 'r1', issued: 30, used: 30 }],
        goodReported: 580,
        trial: 5,
        process: 5,
        qcReject: 8,
        qcReleased: 300,
        fgReceived: 200,
        qcHeld: 0,
        verification: true,
      },
      {
        id: 'w2',
        wo: 'WO-261109-002',
        customer: 'SUP',
        part: 'Support Plate',
        partNo: 'SUP-SP-010',
        material: 'sheet',
        qty: 400,
        due: '2026-11-10',
        date: '2026-11-09',
        shift: '2',
        produced: true,
        inputs: [{ receipt: 'r2', issued: 20, used: 18 }],
        goodReported: 350,
        trial: 4,
        process: 4,
        qcReject: 2,
        qcReleased: 200,
        fgReceived: 150,
        qcHeld: 0,
        verification: false,
      },
      {
        id: 'w3',
        wo: 'WO-261110-003',
        customer: 'DPM',
        part: 'Bracket Inner',
        partNo: 'DPM-BI-001',
        material: 'sheet',
        qty: 640,
        due: '2026-11-11',
        date: '2026-11-10',
        shift: '1',
        produced: true,
        inputs: [
          { receipt: 'r1', issued: 30, used: 29 },
          { receipt: 'r6', issued: 2, used: 2 },
        ],
        goodReported: 610,
        trial: 2,
        process: 4,
        qcReject: 0,
        qcReleased: 0,
        fgReceived: 0,
        qcHeld: 0,
        verification: false,
      },
      {
        id: 'w4',
        wo: 'WO-261119-004',
        customer: 'DPM',
        part: 'Bracket Inner',
        partNo: 'DPM-BI-001',
        material: 'sheet',
        qty: 600,
        due: '2026-11-19',
        date: '2026-11-19',
        shift: '1',
        produced: false,
        inputs: [],
        goodReported: 0,
        trial: 0,
        process: 0,
        qcReject: 0,
        qcReleased: 0,
        fgReceived: 0,
        qcHeld: 0,
        verification: false,
      },
      {
        id: 'w5',
        wo: 'WO-261119-005',
        customer: 'SUP',
        part: 'Support Plate',
        partNo: 'SUP-SP-010',
        material: 'sheet',
        qty: 1800,
        due: '2026-11-19',
        date: '2026-11-19',
        shift: '1',
        produced: false,
        inputs: [],
        goodReported: 0,
        trial: 0,
        process: 0,
        qcReject: 0,
        qcReleased: 0,
        fgReceived: 0,
        qcHeld: 0,
        verification: false,
      },
      {
        id: 'w6',
        wo: 'WO-261119-006',
        customer: 'AMI',
        part: 'Mounting Plate',
        partNo: 'AMI-MP-004',
        material: 'sheet',
        qty: 800,
        due: '2026-11-19',
        date: '2026-11-19',
        shift: '2',
        produced: false,
        inputs: [],
        goodReported: 0,
        trial: 0,
        process: 0,
        qcReject: 0,
        qcReleased: 0,
        fgReceived: 0,
        qcHeld: 0,
        verification: false,
      },
      {
        id: 'w7',
        wo: 'WO-261119-007',
        customer: 'SJM',
        part: 'Reinforcement',
        partNo: 'SJM-RF-008',
        material: 'coil',
        qty: 400,
        required: 120,
        due: '2026-11-19',
        date: '2026-11-19',
        shift: '2',
        produced: false,
        inputs: [],
        goodReported: 0,
        trial: 0,
        process: 0,
        qcReject: 0,
        qcReleased: 0,
        fgReceived: 0,
        qcHeld: 0,
        verification: false,
      },
    ],
    events: [],
  };
  let state = structuredClone(initial);
  const filters = { period: '2026-11', customer: '', owner: '' };
  let layout = 'table';
  let view = 'results';
  let action = 'receipt';
  const receipt = (id) => state.receipts.find((row) => row.id === id);
  const job = (id) => state.jobs.find((row) => row.id === id);
  const issued = (row) => sum(row.inputs, (input) => input.issued);
  const used = (row) => sum(row.inputs, (input) => input.used);
  const ng = (row) => row.trial + row.process + row.qcReject;
  const good = (row) => row.goodReported - row.qcReject;
  const theoretical = (row) => (definitions[row.material].rate === null ? null : used(row) * definitions[row.material].rate);
  const miss = (row) => (theoretical(row) === null ? null : theoretical(row) - row.goodReported - row.trial - row.process);
  const pending = (row) => row.goodReported - row.qcReject - row.qcReleased - row.qcHeld;
  const stock = (row) =>
    row.qty -
    sum(state.jobs, (wo) =>
      sum(
        wo.inputs.filter((input) => input.receipt === row.id),
        (input) => input.issued,
      ),
    );
  const matchesPeriod = (date) => !filters.period || date.startsWith(filters.period);
  const searchMatches = (row) => {
    const text = $('#search').value.trim().toLowerCase();
    return (
      !text || [row.customer, row.part, row.partNo, row.wo, ...row.inputs.map((input) => receipt(input.receipt).lot)].join(' ').toLowerCase().includes(text)
    );
  };
  const matchesJob = (row) =>
    matchesPeriod(row.date) &&
    (!filters.customer || filters.customer === row.customer) &&
    (!filters.owner ||
      (row.inputs.length ? row.inputs.some((input) => receipt(input.receipt).owner === filters.owner) : [row.customer, 'KMI'].includes(filters.owner))) &&
    searchMatches(row);
  const jobs = () => state.jobs.filter(matchesJob);
  const completed = () => jobs().filter((row) => row.produced);
  const activities = () => jobs().filter((row) => row.produced || row.inputs.length > 0);
  function visibleReceipts() {
    const text = $('#search').value.trim().toLowerCase();
    return state.receipts.filter(
      (row) =>
        (!filters.owner || row.owner === filters.owner) &&
        (!filters.customer || [filters.customer, 'KMI'].includes(row.owner)) &&
        (!filters.period || row.date.slice(0, 7) <= filters.period) &&
        (!text || [row.lot, row.owner, definitions[row.material].spec, row.supplierLot].join(' ').toLowerCase().includes(text)),
    );
  }
  function readiness() {
    // Allocate all open orders before filtering the view. Company stock cannot cover two customers simultaneously.
    const balance = new Map(state.receipts.map((row) => [row.id, row.blocked ? 0 : stock(row)]));
    return state.jobs
      .filter((row) => !row.produced)
      .sort((a, b) => a.due.localeCompare(b.due) || a.id.localeCompare(b.id))
      .map((row) => {
        const required = row.required ?? Math.ceil(row.qty / definitions[row.material].rate);
        let remaining = Math.max(0, required - issued(row));
        const allocations = [];
        const eligible = state.receipts
          .filter((lot) => lot.material === row.material && [row.customer, 'KMI'].includes(lot.owner) && !lot.blocked && lot.date <= row.due)
          .sort((a, b) => Number(a.owner === 'KMI') - Number(b.owner === 'KMI') || a.date.localeCompare(b.date));
        for (const lot of eligible) {
          const take = Math.min(balance.get(lot.id), remaining);
          if (take > 0) {
            allocations.push({ lot, qty: take });
            balance.set(lot.id, balance.get(lot.id) - take);
            remaining -= take;
          }
        }
        return { row, required, remaining, allocations, ready: remaining === 0 };
      });
  }
  function lotNumber(prefix, code, day) {
    const formatted = day.slice(8, 10) + day.slice(5, 7) + day.slice(2, 4);
    const start = `${prefix}-${code}-${formatted}-`;
    const existing = prefix === 'RM' ? state.receipts.map((row) => row.lot) : state.jobs.filter((row) => row.produced).map(outputLot);
    const next = Math.max(0, ...existing.filter((lot) => lot.startsWith(start)).map((lot) => Number(lot.slice(start.length)))) + 1;
    return `${start}${String(next).padStart(4, '0')}`;
  }
  function outputLot(row) {
    if (row.lot) return row.lot;
    return `FG-${row.customer}-${row.date.slice(8, 10)}${row.date.slice(5, 7)}${row.date.slice(2, 4)}-0001`;
  }
  function picker(container, key, options, value, change) {
    const label = container.closest('.filter-field').querySelector('span')?.id;
    container.innerHTML = `<button type="button" aria-haspopup="listbox" aria-expanded="false" ${label ? `aria-labelledby="${label} ${key}-value"` : `aria-label="${esc(key)}"`}><span id="${key}-value">${esc(options.find((option) => option.value === value)?.label ?? options[0]?.label ?? 'Tidak ada pilihan')}</span><span aria-hidden="true">⌄</span></button><div role="listbox" class="picker-options" aria-label="${esc(key)}" hidden>${options.map((option) => `<button type="button" role="option" aria-selected="${option.value === value}" data-value="${esc(option.value)}">${esc(option.label)}</button>`).join('')}</div>`;
    const trigger = container.querySelector('button');
    const list = container.querySelector('[role=listbox]');
    const close = () => {
      list.hidden = true;
      trigger.setAttribute('aria-expanded', 'false');
    };
    trigger.addEventListener('click', () => {
      const open = list.hidden;
      document.querySelectorAll('.picker-options').forEach((other) => {
        other.hidden = true;
        other.previousElementSibling.setAttribute('aria-expanded', 'false');
      });
      list.hidden = !open;
      trigger.setAttribute('aria-expanded', String(open));
      if (open) (list.querySelector('[aria-selected=true]') ?? list.querySelector('button'))?.focus();
    });
    list.addEventListener('click', (event) => {
      const option = event.target.closest('[role=option]');
      if (!option) return;
      close();
      trigger.querySelector('span').textContent = option.textContent;
      list.querySelectorAll('[role=option]').forEach((entry) => entry.setAttribute('aria-selected', String(entry === option)));
      change(option.dataset.value);
      container.querySelector('button')?.focus();
    });
    container.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') {
        event.stopPropagation();
        close();
        trigger.focus();
      }
      if (!['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) return;
      event.preventDefault();
      list.hidden = false;
      trigger.setAttribute('aria-expanded', 'true');
      const options = [...list.querySelectorAll('[role=option]')];
      const index = options.indexOf(document.activeElement);
      const next =
        event.key === 'Home' ? 0 : event.key === 'End' ? options.length - 1 : (index + (event.key === 'ArrowUp' ? -1 : 1) + options.length) % options.length;
      options[next]?.focus();
    });
  }
  function renderFilters() {
    const options = {
      period: [
        { value: '2026-11', label: 'November 2026' },
        { value: '2026-10', label: 'Oktober 2026' },
        { value: '', label: 'Semua periode contoh' },
      ],
      customer: [{ value: '', label: 'Semua customer' }, ...customers.map((code) => ({ value: code, label: code }))],
      owner: [{ value: '', label: 'Semua pemilik' }, ...['KMI', ...customers].map((code) => ({ value: code, label: ownerLabel(code) }))],
    };
    document.querySelectorAll('[data-picker]').forEach((container) => {
      const key = container.dataset.picker;
      picker(container, key, options[key], filters[key], (value) => {
        filters[key] = value;
        renderFilters();
        render();
        syncUrl();
      });
    });
  }
  function syncUrl() {
    const params = new URLSearchParams();
    for (const [key, value] of Object.entries(filters)) params.set(key, value);
    params.set('view', view);
    params.set('layout', layout);
    if ($('#search').value) params.set('q', $('#search').value);
    history.replaceState(null, '', `?${params}`);
  }
  function renderMetrics() {
    const rows = completed();
    const plans = readiness().filter(({ row }) => matchesJob(row));
    const ready = plans.filter((plan) => plan.ready).length;
    const theory = sum(
      rows.filter((row) => theoretical(row) !== null),
      theoretical,
    );
    const achieved = sum(
      rows.filter((row) => theoretical(row) !== null),
      good,
    );
    const values = [
      ['Availability material', plans.length ? `${number((ready / plans.length) * 100)}%` : '—', `${ready} dari ${plans.length} WO terbuka lengkap bahan`],
      ['Pencapaian hasil', theory ? `${number((achieved / theory) * 100)}%` : '—', 'Good terkini ÷ hasil teoritis konsumsi'],
      ['NG tercatat', `${number(sum(rows, ng))} <span>pcs</span>`, 'Trial, proses, dan Final QC'],
      [
        'Miss provisional',
        `${number(
          sum(
            rows.filter((row) => miss(row) !== null),
            miss,
          ),
        )} <span>pcs</span>`,
        'Selisih belum terjelaskan · detail per WO',
      ],
    ];
    $('#metrics').innerHTML = values
      .map(
        ([label, value, note]) =>
          `<div class="metric"><span class="metric-label">${label}</span><strong class="metric-value">${value}</strong><small>${note}</small></div>`,
      )
      .join('');
  }
  const button = (id, content, focus = '') => `<button type="button" data-job="${esc(id)}" ${focus ? `data-focus="${focus}"` : ''}>${content}</button>`;
  function resultsTable(rows) {
    const groups = Map.groupBy(rows, (row) => `${row.customer}|${row.partNo}|${row.material}`);
    const body = [...groups.values()]
      .map((group) => {
        const row = group[0];
        const unit = definitions[row.material].unit;
        const calculable = group.every((entry) => entry.produced && miss(entry) !== null);
        return `<tr><td>${esc(row.customer)}</td><td>${button(row.id, esc(row.part))}</td><td>${esc(row.partNo)}</td><td>${esc(definitions[row.material].spec)}</td><td>${button(row.id, 'Lihat lot RM', 'rm')}</td><td class="num">${quantity(sum(group, issued), unit)}</td><td class="num">${quantity(sum(group, used), unit)}</td><td class="num">${button(row.id, quantity(sum(group, good)), 'good')}</td><td class="num">${button(row.id, quantity(sum(group, ng)), 'ng')}</td><td class="num">${button(row.id, calculable ? `<span class="miss">${quantity(sum(group, miss))}</span>` : 'Menunggu hasil', 'miss')}</td></tr>`;
      })
      .join('');
    return table(['Customer', 'Part Name', 'Part No.', 'Spec Material', 'IN RM', 'OUT ke Produksi', 'Terpakai', 'Good', 'NG', 'Miss'], body);
  }
  function table(headers, body) {
    return `<div class="report-scroll" tabindex="0" role="region" aria-label="Tabel data, geser horizontal untuk kolom lainnya"><table class="monitor-table"><thead><tr>${headers.map((header, index) => `<th scope="col" ${index >= 5 ? 'class="num"' : ''}>${header}</th>`).join('')}</tr></thead><tbody>${body}</tbody></table></div>`;
  }
  function stockTable(rows) {
    return table(
      ['Pemilik', 'Lot RM', 'Spec Material', 'Penerimaan periode', 'Saldo gudang saat ini', 'Status', 'Penggunaan'],
      rows
        .map(
          (row) =>
            `<tr><td>${esc(ownerLabel(row.owner))}</td><td><button type="button" data-receipt="${row.id}">${esc(row.lot)}</button></td><td>${esc(definitions[row.material].spec)}</td><td>${quantity(matchesPeriod(row.date) ? row.qty : 0, definitions[row.material].unit)}</td><td>${quantity(stock(row), definitions[row.material].unit)}</td><td><span class="pill ${row.blocked ? 'warn' : 'ok'}">${row.blocked ? 'Blocked · incoming QC' : 'Unrestricted'}</span></td><td>${esc(row.owner === 'KMI' ? 'Lintas customer sesuai spec' : `Khusus ${row.owner}`)}</td></tr>`,
        )
        .join(''),
    );
  }
  function readinessTable(plans) {
    return table(
      ['Customer', 'WO / Part', 'Target', 'Kebutuhan', 'Usulan lot sesuai pemilik', 'Kekurangan', 'Kesiapan'],
      plans
        .map(
          ({ row, required, remaining, allocations, ready }) =>
            `<tr><td>${esc(row.customer)}</td><td>${button(row.id, esc(row.wo))}<br><small>${esc(row.part)}</small></td><td>${esc(row.due)}</td><td>${quantity(required, definitions[row.material].unit)}</td><td>${allocations.map(({ lot, qty }) => `<button type="button" data-receipt="${lot.id}">${esc(lot.lot)}</button> · ${quantity(qty, definitions[row.material].unit)}`).join('<br>') || 'Belum tersedia'}</td><td class="num">${quantity(remaining, definitions[row.material].unit)}</td><td><span class="pill ${ready ? 'ok' : 'warn'}">${ready ? 'Material siap' : 'Material kurang'}</span></td></tr>`,
        )
        .join(''),
    );
  }
  function render() {
    renderMetrics();
    const rows = activities();
    const lots = visibleReceipts();
    const plans = readiness().filter(({ row }) => matchesJob(row));
    let html = '';
    let count = 0;
    if (view === 'stock') {
      html = stockTable(lots);
      count = lots.length;
    } else if (view === 'readiness') {
      html = readinessTable(plans);
      count = plans.length;
    } else if (layout === 'owner') {
      const grouped = Map.groupBy(rows, (row) => [...new Set(row.inputs.map((input) => receipt(input.receipt).owner))].sort().join(' + '));
      const sections = [...grouped].map(([owner, rows]) => ({ owner, rows }));
      html = sections
        .map(
          ({ owner, rows }) =>
            `<section class="owner-section"><div class="owner-head"><h3>${esc(owner.includes(' + ') ? `Campuran pemilik ${owner}` : ownerLabel(owner))}</h3><p>${rows.length} WO · detail saldo lewat lot RM</p></div>${resultsTable(rows)}</section>`,
        )
        .join('');
      count = sections.length;
    } else if (layout === 'lot') {
      html = `<div class="lot-list">${rows.map((row) => `<article class="lot-entry"><div><button type="button" data-job="${row.id}">${esc(row.produced ? outputLot(row) : 'Menunggu hasil')}</button><p>${esc(row.date)} · Shift ${esc(row.shift)}</p></div><div><strong>${esc(row.customer)} · ${esc(row.part)}</strong><p>${esc(row.wo)} · ${row.inputs.map((input) => esc(receipt(input.receipt).lot)).join(' + ')}</p></div><div><strong>${quantity(good(row))} good</strong><p>${quantity(ng(row))} NG · ${quantity(miss(row) ?? 0)} Miss</p></div></article>`).join('')}</div>`;
      count = rows.length;
    } else {
      html = resultsTable(rows);
      count = Map.groupBy(rows, (row) => `${row.customer}|${row.partNo}|${row.material}`).size;
    }
    $('#report-body').innerHTML = count
      ? html
      : '<div class="empty"><h3>Belum ada data untuk filter ini</h3><p>Ubah periode atau hapus filter untuk melihat data simulasi lainnya.</p><button class="btn" type="button" id="clearFilters">Hapus filter</button></div>';
    $('#count').textContent =
      `${count} ${view === 'stock' ? 'lot' : view === 'readiness' ? 'WO' : layout === 'owner' ? 'pemilik' : layout === 'lot' ? 'lot hasil' : 'part'}`;
    $('#report-title').textContent =
      view === 'stock'
        ? 'Penerimaan & saldo material'
        : view === 'readiness'
          ? 'Kesiapan material WO'
          : layout === 'owner'
            ? 'Pemakaian per pemilik material'
            : layout === 'lot'
              ? 'Hasil produksi per lot'
              : 'Hasil per part';
    $('#report-subtitle').textContent =
      `${filters.period === '2026-11' ? 'November 2026' : filters.period === '2026-10' ? 'Oktober 2026' : 'Semua periode contoh'} · ${filters.customer || 'Semua customer'} · ${filters.owner ? ownerLabel(filters.owner) : 'Semua pemilik'}`;
    $('#report-foot').textContent =
      view === 'stock'
        ? 'Penerimaan dihitung satu kali per lot. Saldo gudang adalah posisi saat ini, termasuk penerimaan periode sebelumnya. Filter customer menunjukkan material yang boleh digunakan customer tersebut.'
        : view === 'readiness'
          ? 'Simulasi bahan saja, dialokasikan menurut tanggal target ke seluruh WO sebelum filter diterapkan. Aset customer didahulukan. Kalender, kapasitas mesin, dan waktu QC masih perlu diperhitungkan untuk tanggal selesai.'
          : 'IN RM membuka penerimaan lot sumber. Satu penerimaan dapat melayani beberapa part. Detail hasil terpisah menurut WO dan lot produksi.';
    document.querySelectorAll('[data-view]').forEach((element) => element.setAttribute('aria-pressed', String(element.dataset.view === view)));
    $('#clearFilters')?.addEventListener('click', clearFilters);
    icons();
  }
  function metadata(entries) {
    return `<dl class="detail-meta">${entries.map(([label, value]) => `<div><dt>${esc(label)}</dt><dd>${value}</dd></div>`).join('')}</dl>`;
  }
  function openDetail(title, kicker, html) {
    $('#detail-title').textContent = title;
    $('#detail-kicker').textContent = kicker;
    $('#detail-body').innerHTML = html;
    if (!$('#detail').open) $('#detail').showModal();
    $('#detail').scrollTop = 0;
    icons();
  }
  function showReceipt(id) {
    const row = receipt(id);
    const links = state.jobs.filter((wo) => wo.inputs.some((input) => input.receipt === id));
    openDetail(
      row.lot,
      'Lot RM · asal & penggunaan',
      `${metadata([
        ['Pemilik material', esc(ownerLabel(row.owner))],
        ['Spec / satuan', `${esc(definitions[row.material].spec)} / ${definitions[row.material].unit}`],
        ['Supplier / pengirim', esc(row.supplier)],
        ['Lot supplier', esc(row.supplierLot)],
        ['Tanggal penerimaan', esc(row.date)],
        ['Status', row.blocked ? 'Blocked · menunggu incoming release' : 'Unrestricted'],
      ])}<div class="detail-metrics"><div>Diterima<strong>${quantity(row.qty, definitions[row.material].unit)}</strong></div><div>OUT ke produksi<strong>${quantity(row.qty - stock(row), definitions[row.material].unit)}</strong></div><div>Saldo gudang<strong>${quantity(stock(row), definitions[row.material].unit)}</strong></div></div><h3>Pemakaian per WO dan customer</h3>${
        links.length
          ? `<table class="small-table"><thead><tr><th>Customer / WO</th><th>Issue</th><th>Terpakai</th><th>Lot hasil</th></tr></thead><tbody>${links
              .map((wo) => {
                const input = wo.inputs.find((entry) => entry.receipt === id);
                return `<tr><td>${esc(wo.customer)}<br>${button(wo.id, esc(wo.wo))}</td><td>${quantity(input.issued, definitions[row.material].unit)}</td><td>${quantity(input.used, definitions[row.material].unit)}</td><td>${wo.produced ? esc(outputLot(wo)) : 'Belum diproduksi'}</td></tr>`;
              })
              .join('')}</tbody></table>`
          : '<p>Lot ini belum di-issue ke produksi.</p>'
      }<h3>Riwayat penerimaan</h3><p>${esc(row.date)} · ${esc(row.actor)} menerima ${quantity(row.qty, definitions[row.material].unit)}. Nomor lot tetap mengikuti penerimaan ini saat material dipakai pada tanggal berikutnya.</p>`,
    );
  }
  function jobDetails(row) {
    const unit = definitions[row.material].unit;
    return `${metadata([
      ['Customer tujuan', esc(row.customer)],
      ['Part / Part No.', `${esc(row.part)} / ${esc(row.partNo)}`],
      ['WO', esc(row.wo)],
      ['Tanggal produksi / shift', `${esc(row.date)} / ${esc(row.shift)}`],
      ['Lot hasil', row.produced ? esc(outputLot(row)) : 'Dibuat saat hasil diposting'],
      ['Pemilik material', esc([...new Set(row.inputs.map((input) => ownerLabel(receipt(input.receipt).owner)))].join(', ') || 'Mengikuti lot yang di-issue')],
    ])}<h3>Lot input & sisa material</h3><div class="report-scroll"><table class="small-table"><thead><tr><th>Lot RM / pemilik</th><th>Issue</th><th>Terpakai</th><th>Sisa di produksi</th></tr></thead><tbody>${row.inputs.map((input) => `<tr><td><button type="button" class="btn-link" data-receipt="${input.receipt}">${esc(receipt(input.receipt).lot)}</button><br>${esc(ownerLabel(receipt(input.receipt).owner))}</td><td>${quantity(input.issued, unit)}</td><td>${quantity(input.used, unit)}</td><td>${quantity(input.issued - input.used, unit)}</td></tr>`).join('')}</tbody></table></div>${
      row.produced
        ? `<h3>Rekonsiliasi hasil <span class="pill warn">Provisional</span></h3><div class="conservation"><p>${quantity(used(row), unit)} × ${number(definitions[row.material].rate)} pcs/${unit} = <b>${quantity(theoretical(row))} teoritis</b></p><p>${quantity(good(row))} Good + ${quantity(ng(row))} NG + <b>${quantity(miss(row))} Miss</b> = ${quantity(theoretical(row))}</p><p>Konversi contoh untuk simulasi sheet. Selisih tetap memerlukan rekonsiliasi fisik.</p></div><h3>NG per sumber</h3><table class="small-table"><thead><tr><th>Sumber</th><th>Qty</th><th>Catatan petugas contoh</th></tr></thead><tbody><tr><td>Trial stamping</td><td>${quantity(row.trial)}</td><td>Produksi · laporan shift</td></tr><tr><td>Proses stamping</td><td>${quantity(row.process)}</td><td>Produksi${row.verification ? ' · diverifikasi Quality Line' : ' · menunggu verifikasi Quality Line'}</td></tr><tr><td>Final inspection</td><td>${quantity(row.qcReject)}</td><td>Quality Final · dari Good lot yang sama</td></tr></tbody></table><h3>Status hasil good</h3><div class="detail-metrics"><div>Pending QC<strong>${quantity(pending(row))}</strong></div><div>QC Accepted, belum FG<strong>${quantity(row.qcReleased - row.fgReceived)}</strong></div><div>FG diterima<strong>${quantity(row.fgReceived)}</strong></div></div><h3>Riwayat lot</h3><ol class="timeline"><li><h4>${esc(row.date)} · Shift ${esc(row.shift)} · Produksi</h4><p>Good dilaporkan ${quantity(row.goodReported)}, NG trial ${quantity(row.trial)}, NG proses ${quantity(row.process)}.</p><p>Lot ${esc(outputLot(row))} ditautkan ke ${row.inputs.map((input) => esc(receipt(input.receipt).lot)).join(' dan ')}.</p></li><li><h4>Quality Line · ${row.verification ? 'Sudah diverifikasi' : 'Menunggu pemeriksaan'}</h4><p>Verifikasi memakai catatan NG produksi yang sama.</p></li><li><h4>Quality Final · ${quantity(row.qcReleased)} diterima, ${quantity(row.qcReject)} NG</h4><p>Good terkini ${quantity(good(row))}. Miss tetap ${quantity(miss(row))} setelah klasifikasi QC.</p></li><li><h4>Gudang FG · ${quantity(row.fgReceived)} diterima</h4><p>Lot hasil tetap ${esc(outputLot(row))} meskipun QC dan penerimaan dilakukan pada shift berikutnya.</p></li>${state.events
            .filter((event) => event.job === row.id)
            .map((event) => `<li><h4>${esc(event.title)}</h4><p>${esc(event.note)}</p></li>`)
            .join('')}</ol>`
        : '<p>WO belum memiliki hasil produksi. Pilih Simulasi input untuk mencoba issue, hasil produksi, dan Final QC.</p>'
    }`;
  }
  function showJob(id, focus = '') {
    const row = job(id);
    const related = state.jobs.filter(
      (entry) =>
        (entry.produced || entry.inputs.length) &&
        entry.customer === row.customer &&
        entry.partNo === row.partNo &&
        entry.material === row.material &&
        matchesJob(entry),
    );
    const group = row.produced ? related : [row];
    openDetail(
      row.part,
      `${row.customer} · detail per WO & lot`,
      group
        .map(
          (entry, index) =>
            `<section class="job-detail">${group.length > 1 ? `<h3>${esc(entry.wo)} · ${esc(outputLot(entry))}</h3>` : ''}${jobDetails(entry)}${index < group.length - 1 ? '<hr>' : ''}</section>`,
        )
        .join(''),
    );
    if (focus) {
      const target = [...$('#detail-body').querySelectorAll('h3')].find((element) =>
        element.textContent.includes({ rm: 'Lot input', ng: 'NG per sumber', good: 'Status hasil', miss: 'Rekonsiliasi' }[focus]),
      );
      target?.scrollIntoView({ block: 'start' });
    }
  }
  const inputValues = {};
  function formPicker(key, label, options, value = options[0]?.value ?? '') {
    inputValues[key] = value;
    return `<div class="filter-field"><span id="input-${key}-label">${label}</span><div class="picker" data-input-picker="${key}"></div></div>`;
  }
  function numericField(key, label, value, max = '') {
    return `<label class="filter-field">${label}<input class="input" name="${key}" type="number" min="0" step="any" ${max !== '' ? `max="${max}"` : ''} value="${value}" required></label>`;
  }
  function renderInput() {
    $('#input-error').textContent = '';
    const pickers = [];
    const select = (key, label, options, value) => {
      pickers.push({ key, options });
      return formPicker(key, label, options, value);
    };
    let html = '';
    if (action === 'receipt') {
      html =
        select(
          'owner',
          'Pemilik material',
          ['KMI', ...customers].map((code) => ({ value: code, label: ownerLabel(code) })),
        ) +
        select(
          'material',
          'Material / satuan',
          Object.entries(definitions).map(([key, value]) => ({ value: key, label: `${value.name} ${value.spec} · ${value.unit}` })),
        ) +
        numericField('qty', 'Qty diterima', 30) +
        '<p class="input-hint">Tanggal contoh 18 November 2026. Nomor lot RM otomatis mengikuti pemilik dan tanggal penerimaan. Material contoh diterima Unrestricted.</p>';
    } else if (action === 'issue') {
      const availableJobs = state.jobs.filter((row) => !row.produced);
      const selected = availableJobs.find((row) => row.id === inputValues.job) ?? availableJobs[0];
      html = select(
        'job',
        'WO / customer tujuan',
        availableJobs.map((row) => ({ value: row.id, label: `${row.wo} · ${row.customer} · ${row.part}` })),
        selected?.id,
      );
      const eligible = state.receipts.filter(
        (lot) => selected && lot.material === selected.material && [selected.customer, 'KMI'].includes(lot.owner) && !lot.blocked && stock(lot) > 0,
      );
      html +=
        select(
          'receipt',
          'Lot sesuai pemilik dan customer',
          eligible.map((lot) => ({ value: lot.id, label: `${lot.lot} · ${quantity(stock(lot), definitions[lot.material].unit)}` })),
        ) +
        numericField('qty', 'Qty di-issue', 10) +
        '<p class="input-hint">Issue memindahkan material ke produksi. Pemakaian dicatat pada laporan hasil produksi.</p>';
    } else if (action === 'production') {
      const eligible = state.jobs.filter((row) => !row.produced && row.inputs.length && definitions[row.material].rate !== null);
      const selected = eligible.find((row) => row.id === inputValues.job) ?? eligible[0];
      html = select(
        'job',
        'WO yang sudah menerima sheet',
        eligible.map((row) => ({ value: row.id, label: `${row.wo} · ${row.customer} · ${quantity(issued(row), definitions[row.material].unit)}` })),
        selected?.id,
      );
      html +=
        numericField('remaining', 'Sisa sheet di produksi', 0) +
        '<div class="input-pair">' +
        numericField('good', 'Good produksi (pcs)', selected ? issued(selected) * 20 - 20 : 0) +
        numericField('trial', 'NG trial (pcs)', 5) +
        '</div>' +
        numericField('process', 'NG proses (pcs)', 5) +
        '<p class="input-hint">Simulasi sheet saja. Konsumsi = qty diterima produksi − sisa. Good masuk Pending QC, dan NG trial/proses tercatat sekali. Produksi contoh tanggal 18 November 2026, shift 1.</p>';
    } else {
      const eligible = state.jobs.filter((row) => row.produced && pending(row) > 0);
      html =
        select(
          'job',
          'Lot hasil produksi / Pending QC',
          eligible.map((row) => ({ value: row.id, label: `${outputLot(row)} · ${quantity(pending(row))} pending` })),
        ) +
        '<div class="input-pair">' +
        numericField('accepted', 'QC Accepted (pcs)', 100) +
        numericField('rejected', 'NG Final QC (pcs)', 8) +
        '</div><p class="input-hint">NG Final QC mengklasifikasi ulang Good pada lot yang dipilih. Miss tetap mengikuti hasil total yang sudah tercatat.</p>';
    }
    $('#input-fields').innerHTML = html;
    for (const { key, options } of pickers) {
      picker($(`[data-input-picker="${key}"]`), `input-${key}`, options, inputValues[key], (value) => {
        inputValues[key] = value;
        if ((action === 'issue' || action === 'production') && key === 'job') renderInput();
      });
    }
    document.querySelectorAll('[data-action]').forEach((element) => element.setAttribute('aria-pressed', String(element.dataset.action === action)));
  }
  function submitInput(event) {
    event.preventDefault();
    const data = new FormData(event.target);
    const value = (key) => Number(data.get(key));
    const required = (condition, message) => {
      if (!condition) throw new Error(message);
    };
    let message = '';
    try {
      const fractional =
        action === 'receipt' ? definitions[inputValues.material]?.unit === 'kg' : action === 'issue' ? job(inputValues.job)?.material === 'coil' : false;
      for (const [key, number] of data)
        required(
          Number.isFinite(Number(number)) && Number(number) >= 0 && (fractional || Number.isInteger(Number(number))),
          `Isi ${key} dengan qty ${fractional ? 'valid' : 'utuh'} minimal nol.`,
        );
      if (action === 'receipt') {
        required(value('qty') > 0, 'Isi qty penerimaan lebih dari nol.');
        const lot = lotNumber('RM', inputValues.owner, '2026-11-18');
        state.receipts.push({
          id: `r${state.receipts.length + 1}`,
          lot,
          owner: inputValues.owner,
          material: inputValues.material,
          qty: value('qty'),
          date: '2026-11-18',
          supplier: 'Pengirim simulasi',
          supplierLot: 'HEAT-SIMULASI',
          actor: 'Petugas RM simulasi',
          blocked: false,
        });
        message = `${lot} dibuat. Penerimaan ${quantity(value('qty'), definitions[inputValues.material].unit)} tersimpan di simulasi.`;
      } else if (action === 'issue') {
        const wo = job(inputValues.job);
        const lot = receipt(inputValues.receipt);
        required(
          wo && lot && !lot.blocked && lot.material === wo.material && [wo.customer, 'KMI'].includes(lot.owner),
          'Pilih lot yang tersedia dan sesuai pemilik, customer, serta material WO.',
        );
        required(value('qty') > 0 && value('qty') <= stock(lot), 'Qty issue harus lebih dari nol dan sesuai saldo gudang.');
        const input = wo.inputs.find((entry) => entry.receipt === lot.id);
        if (input) input.issued += value('qty');
        else wo.inputs.push({ receipt: lot.id, issued: value('qty'), used: 0 });
        message = `${quantity(value('qty'), definitions[lot.material].unit)} dari ${lot.lot} di-issue ke ${wo.wo}.`;
      } else if (action === 'production') {
        const wo = job(inputValues.job);
        required(wo && !wo.produced && wo.inputs.length && wo.material === 'sheet', 'Issue material sheet dulu ke WO agar hasil produksi bisa dicatat.');
        required(value('remaining') < issued(wo), 'Sisa harus lebih kecil dari material yang di-issue.');
        required(value('good') + value('trial') + value('process') > 0, 'Isi hasil good atau NG yang benar-benar tercatat.');
        const newLot = lotNumber('FG', wo.customer, '2026-11-18');
        let left = value('remaining');
        for (const input of [...wo.inputs].reverse()) {
          const remaining = Math.min(left, input.issued);
          input.used = input.issued - remaining;
          left -= remaining;
        }
        Object.assign(wo, {
          produced: true,
          date: '2026-11-18',
          shift: '1',
          goodReported: value('good'),
          trial: value('trial'),
          process: value('process'),
          lot: newLot,
        });
        message = `${wo.lot} dibuat. Miss provisional ${quantity(miss(wo))}. Good menunggu QC.`;
      } else {
        const wo = job(inputValues.job);
        required(wo && wo.produced, 'Pilih lot hasil produksi.');
        required(
          value('accepted') + value('rejected') > 0 && value('accepted') + value('rejected') <= pending(wo),
          'Total diperiksa harus lebih dari nol dan maksimal qty Pending QC.',
        );
        wo.qcReleased += value('accepted');
        wo.qcReject += value('rejected');
        state.events.push({
          job: wo.id,
          title: '18-11-2026 · Quality Final simulasi',
          note: `${quantity(value('accepted'))} accepted dan ${quantity(value('rejected'))} NG. Lot tetap ${outputLot(wo)}.`,
        });
        message = `QC ${outputLot(wo)} tersimpan. NG bertambah ${quantity(value('rejected'))}, Miss tetap ${quantity(miss(wo))}.`;
      }
      $('#input-dialog').close();
      render();
      $('#feedback').innerHTML = `<p class="notice">${esc(message)}</p>`;
    } catch (error) {
      $('#input-error').textContent = error.message;
    }
  }
  function clearFilters() {
    filters.period = '2026-11';
    filters.customer = '';
    filters.owner = '';
    $('#search').value = '';
    renderFilters();
    render();
    syncUrl();
  }
  $('#filters').addEventListener('reset', (event) => {
    event.preventDefault();
    clearFilters();
  });
  $('#search').addEventListener('input', () => {
    render();
    syncUrl();
  });
  document.addEventListener('click', (event) => {
    const viewButton = event.target.closest('[data-view]');
    if (viewButton) {
      view = viewButton.dataset.view;
      render();
      syncUrl();
    }
    const layoutButton = event.target.closest('[data-layout]');
    if (layoutButton) {
      layout = layoutButton.dataset.layout;
      view = 'results';
      document.querySelectorAll('[data-layout]').forEach((element) => element.setAttribute('aria-pressed', String(element.dataset.layout === layout)));
      render();
      syncUrl();
    }
    const woButton = event.target.closest('[data-job]');
    if (woButton) showJob(woButton.dataset.job, woButton.dataset.focus);
    const rmButton = event.target.closest('[data-receipt]');
    if (rmButton) showReceipt(rmButton.dataset.receipt);
    const closeButton = event.target.closest('[data-close]');
    if (closeButton) $(`#${closeButton.dataset.close}`).close();
    const actionButton = event.target.closest('[data-action]');
    if (actionButton) {
      action = actionButton.dataset.action;
      renderInput();
    }
    if (!event.target.closest('.picker'))
      document.querySelectorAll('.picker-options').forEach((list) => {
        list.hidden = true;
        list.previousElementSibling.setAttribute('aria-expanded', 'false');
      });
  });
  function simulate() {
    renderInput();
    $('#input-dialog').showModal();
  }
  $('#simulate').addEventListener('click', simulate);
  $('#mobileInput').addEventListener('click', simulate);
  $('#input-form').addEventListener('submit', submitInput);
  $('#resetDemo').addEventListener('click', () => {
    state = structuredClone(initial);
    $('#feedback').textContent = '';
    render();
  });
  $('#density').addEventListener('click', () => {
    const compact = $('.report-card').classList.toggle('compact');
    $('#density').setAttribute('aria-pressed', String(compact));
    $('#density').textContent = compact ? 'Comfortable' : 'Compact';
  });
  $('#printReport').addEventListener('click', () => window.print());
  const params = new URLSearchParams(location.search);
  for (const key of Object.keys(filters)) if (params.has(key)) filters[key] = params.get(key);
  if (!['', '2026-10', '2026-11'].includes(filters.period)) filters.period = '2026-11';
  if (filters.customer && !customers.includes(filters.customer)) filters.customer = '';
  if (filters.owner && !['KMI', ...customers].includes(filters.owner)) filters.owner = '';
  if (['results', 'stock', 'readiness'].includes(params.get('view'))) view = params.get('view');
  if (['table', 'owner', 'lot'].includes(params.get('layout'))) layout = params.get('layout');
  $('#search').value = params.get('q') ?? '';
  document.querySelectorAll('[data-layout]').forEach((element) => element.setAttribute('aria-pressed', String(element.dataset.layout === layout)));
  renderFilters();
  render();
})();
