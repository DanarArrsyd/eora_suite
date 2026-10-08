/* =====================================================================
   EORA Suite — centralized navigation configuration (prototype)
   Canonical spec: docs/uiux/EORA-NAVIGATION-ARCHITECTURE.md
   Production: packages/features/navigation/eora-nav.config.ts; API returns the
   user-filtered tree with live badge counts (GET /api/v1/me/navigation).
   Rules:
   - One config for all pages. Pages never hardcode menus.
   - `permission` is enforced server-side; UI filtering is convenience only.
   - `release`: R1 (vertical slice), R2 (V1 additions), V2 (hidden unless feature flag).
   - `decision`: depends on an OPEN decision in docs/13 (prototype shows a tag; production hides).
   - `badge`: ACTIONABLE source only (D-025). Never total record counts.
       approvals  — approval requests assigned to the user
       qc         — pending QC inspections in the user's scope
       variance   — variance investigations owned/assigned to the user or team
       tasks      — tasks assigned to the user (custody confirmation, measurement, count)
     Bell badge = action-required notifications. All counts tenant- and permission-scoped.
   ===================================================================== */
window.EORA_NAV = {
  /* SAMPLE actionable counts per prototype role (production: server query per user). */
  sampleBadges: {
    'PPIC Planner':        { approvals: 1, variance: 1, tasks: 2, bell: 2 },
    'Quality Inspector':   { qc: 4, tasks: 1, bell: 1 },
    'Materials PIC':       { tasks: 2, approvals: 0, bell: 1 },
    'Company Super Admin': { approvals: 2, variance: 3, bell: 3 },
  },
  roleModules: {
    'PPIC Planner': ['dashboard', 'demand', 'ppic', 'materials', 'production', 'quality', 'inventory', 'warehouse', 'delivery', 'traceability', 'analytics'],
    'Quality Inspector': ['dashboard', 'quality', 'traceability'],
    'Materials PIC': ['dashboard', 'materials', 'inventory', 'traceability'],
    'Company Super Admin': '*',
  },
  modules: [
    { id: 'dashboard', label: 'Dashboard', icon: 'layout-dashboard', permission: 'dashboard.view', groups: [
      { label: 'My Workspace', items: [
        { id: 'home', label: 'Overview', icon: 'house', release: 'R1' },
        { id: 'my-tasks', label: 'My Tasks', icon: 'list-checks', release: 'R1', badge: 'tasks' },
        { id: 'approvals', label: 'Approvals Inbox', icon: 'stamp', release: 'R1', badge: 'approvals', permission: 'core.approval.view' },
      ]},
    ]},
    { id: 'demand', label: 'Demand', icon: 'file-text', permission: 'demand.po.view', groups: [
      { label: 'Customer Orders', items: [
        { id: 'customer-po', label: 'Customer Purchase Orders', icon: 'file-text', release: 'R1' },
        { id: 'po-revisions', label: 'PO Revisions', icon: 'history', release: 'R1' },
      ]},
      { label: 'Releases & Forecast', items: [
        { id: 'forecast', label: 'Forecast', icon: 'trending-up', release: 'R2' },
        { id: 'releases', label: 'Kanban / Delivery Releases', icon: 'calendar-check', release: 'R2' },
        { id: 'netting', label: 'Demand Netting', icon: 'git-merge', release: 'R2', decision: 'O-019' },
      ]},
      { label: 'Master Data', items: [
        { id: 'customers', label: 'Customers', icon: 'building', release: 'R1' },
        { id: 'customer-items', label: 'Customer Part Mapping', icon: 'link', release: 'R1' },
      ]},
    ]},
    { id: 'ppic', label: 'PPIC / Planning', icon: 'calendar-range', permission: 'planning.control_tower.view', groups: [
      { label: 'Overview', items: [
        { id: 'control-tower', label: 'Control Tower', icon: 'radar', release: 'R1', href: '01-control-tower-desktop.html' },
        { id: 'planning-dashboard', label: 'Planning Dashboard', icon: 'layout-grid', release: 'R2' },
      ]},
      { label: 'Customer Demand', items: [
        { id: 'ppic-po', label: 'Customer Purchase Orders', icon: 'file-text', release: 'R1' },
        { id: 'sales-orders', label: 'Sales Orders', icon: 'file-check', release: 'R2' },
        { id: 'ppic-forecast', label: 'Forecast', icon: 'trending-up', release: 'R2' },
        { id: 'delivery-schedules', label: 'Delivery Schedules', icon: 'calendar-check', release: 'R2' },
      ]},
      { label: 'Production Planning', items: [
        { id: 'mps', label: 'Master Production Schedule', icon: 'calendar-days', release: 'R2' },
        { id: 'work-orders', label: 'Work Orders', icon: 'clipboard-list', release: 'R1' },
        { id: 'capacity', label: 'Capacity Planning', icon: 'gauge', release: 'R2' },
      ]},
      { label: 'Material Control', items: [
        { id: 'rm-availability', label: 'Raw Material Availability', icon: 'boxes', release: 'R1', href: '04-raw-material-list.html' },
        { id: 'conversion', label: 'Coil / Sheet Conversion', icon: 'calculator', release: 'R1' },
        { id: 'shortage', label: 'Material Shortage', icon: 'triangle-alert', release: 'R1' },
      ]},
      { label: 'Production Monitoring', items: [
        { id: 'wo-progress', label: 'Work Order Progress', icon: 'activity', release: 'R1' },
        { id: 'daily-results', label: 'Daily Production Results', icon: 'factory', release: 'R1' },
        { id: 'pending-quality', label: 'Pending Quality', icon: 'hourglass', release: 'R1' },
      ]},
      { label: 'Inventory Monitoring', items: [
        { id: 'fg-availability', label: 'Finished Goods Availability', icon: 'package-check', release: 'R1' },
        { id: 'mat-at-prod', label: 'Material at Production', icon: 'package-open', release: 'R1' },
      ]},
      { label: 'Reconciliation', items: [
        { id: 'consumption', label: 'Material Consumption', icon: 'scale', release: 'R1' },
        { id: 'material-variance', label: 'Material Variance', icon: 'chart-no-axes-column', release: 'R1' },
        { id: 'variance-cases', label: 'Variance Investigation', icon: 'search-check', release: 'R1', badge: 'variance', href: '02-run-reconciliation-desktop.html' },
      ]},
      { label: 'Reports', items: [
        { id: 'rpt-daily-fg', label: 'Daily Finished Goods', icon: 'file-bar-chart', release: 'R1' },
        { id: 'rpt-daily-delivery', label: 'Daily Delivery', icon: 'file-bar-chart', release: 'R1' },
        { id: 'rpt-fulfillment', label: 'Production Fulfillment', icon: 'file-bar-chart', release: 'R2' },
      ]},
    ]},
    { id: 'materials', label: 'Materials', icon: 'layers', permission: 'materials.issue.view', groups: [
      { label: 'Receiving', items: [
        { id: 'rm-receipts', label: 'RM Receipts', icon: 'package-plus', release: 'R1' },
        { id: 'incoming-release', label: 'Incoming Release', icon: 'shield-check', release: 'R2', decision: 'O-013' },
      ]},
      { label: 'Issue & Return', items: [
        { id: 'material-issue', label: 'Material Issue', icon: 'arrow-right-left', release: 'R1' },
        { id: 'custody', label: 'Custody Acceptance', icon: 'hand', release: 'R2', badge: 'tasks' },
        { id: 'material-return', label: 'Material Return', icon: 'undo-2', release: 'R1' },
        { id: 'substitution', label: 'Substitution Requests', icon: 'replace', release: 'R2' },
      ]},
      { label: 'Lots', items: [
        { id: 'material-lots', label: 'Material Lots', icon: 'tags', release: 'R1' },
        { id: 'remnants', label: 'Remnants', icon: 'scissors', release: 'R1' },
      ]},
    ]},
    { id: 'production', label: 'Production', icon: 'factory', permission: 'production.run.view', groups: [
      { label: 'Execution', items: [
        { id: 'runs', label: 'Production Runs', icon: 'circle-play', release: 'R1' },
        { id: 'run-report', label: 'Run Reporting', icon: 'clipboard-pen', release: 'R1' },
        { id: 'downtime', label: 'Downtime', icon: 'timer-off', release: 'R2' },
      ]},
      { label: 'Output', items: [
        { id: 'postings', label: 'Production Postings', icon: 'file-stack', release: 'R1' },
        { id: 'rework-orders', label: 'Rework Orders', icon: 'refresh-ccw', release: 'R2' },
      ]},
      { label: 'Reconciliation', items: [
        { id: 'run-recon', label: 'Run Reconciliation', icon: 'scale', release: 'R1', href: '02-run-reconciliation-desktop.html' },
        { id: 'measurements', label: 'Measurements', icon: 'ruler', release: 'R1' },
      ]},
    ]},
    { id: 'quality', label: 'Quality', icon: 'badge-check', permission: 'quality.inspection.view', groups: [
      { label: 'Inspection', items: [
        { id: 'qc-queue', label: 'Pending QC Queue', icon: 'hourglass', release: 'R1', badge: 'qc' },
        { id: 'inspection-lots', label: 'Inspection Lots', icon: 'clipboard-check', release: 'R1' },
        { id: 'hold-lots', label: 'Hold Lots', icon: 'circle-pause', release: 'R2' },
      ]},
      { label: 'Master Data', items: [
        { id: 'defect-codes', label: 'Defect Codes', icon: 'list', release: 'R2' },
        { id: 'sampling', label: 'Sampling Plans', icon: 'percent', release: 'R2' },
      ]},
    ]},
    { id: 'inventory', label: 'Inventory', icon: 'boxes', permission: 'inventory.view', groups: [
      { label: 'Stock', items: [
        { id: 'stock-overview', label: 'Stock Overview', icon: 'layout-list', release: 'R1' },
        { id: 'raw-material', label: 'Raw Material', icon: 'cylinder', release: 'R1', href: '04-raw-material-list.html' },
        { id: 'semi-finished', label: 'Semi-Finished Material', icon: 'component', release: 'R1' },
        { id: 'finished-goods', label: 'Finished Goods', icon: 'package-check', release: 'R1' },
        { id: 'inv-mat-at-prod', label: 'Material at Production', icon: 'package-open', release: 'R1' },
      ]},
      { label: 'Ledger', items: [
        { id: 'movements', label: 'Movement Ledger', icon: 'book-open', release: 'R1' },
        { id: 'allocations', label: 'Allocations', icon: 'bookmark', release: 'R1' },
      ]},
      { label: 'Control', items: [
        { id: 'stock-count', label: 'Stock Count', icon: 'clipboard-list', release: 'R2' },
        { id: 'adjustments', label: 'Adjustments', icon: 'sliders-horizontal', release: 'R1', badge: 'approvals' },
        { id: 'reversals', label: 'Reversals', icon: 'rotate-ccw', release: 'R1' },
        { id: 'period-close', label: 'Period Close', icon: 'lock', release: 'R2' },
      ]},
    ]},
    { id: 'warehouse', label: 'Warehouse', icon: 'warehouse', permission: 'warehouse.view', groups: [
      { label: 'Inbound', items: [
        { id: 'fg-receipt', label: 'FG Receipt', icon: 'package-plus', release: 'R1' },
        { id: 'putaway', label: 'Putaway', icon: 'move-down', release: 'R1' },
      ]},
      { label: 'Outbound', items: [
        { id: 'handling-units', label: 'Handling Units', icon: 'package', release: 'R2' },
        { id: 'picking', label: 'Picking', icon: 'hand-grab', release: 'R2' },
        { id: 'packing', label: 'Packing', icon: 'package-2', release: 'R2' },
      ]},
    ]},
    { id: 'delivery', label: 'Delivery', icon: 'truck', permission: 'delivery.view', groups: [
      { label: 'Shipping', items: [
        { id: 'delivery-orders', label: 'Delivery Orders', icon: 'file-text', release: 'R1' },
        { id: 'shipments', label: 'Shipments', icon: 'truck', release: 'R1' },
        { id: 'pod', label: 'Proof of Delivery', icon: 'file-signature', release: 'R2' },
      ]},
      { label: 'Monitoring', items: [
        { id: 'outstanding', label: 'Outstanding Demand', icon: 'clock', release: 'R1' },
      ]},
    ]},
    { id: 'maintenance', label: 'Maintenance', icon: 'wrench', permission: 'maintenance.view', release: 'R2', groups: [
      { label: 'Assets', items: [
        { id: 'assets', label: 'Machines', icon: 'cog', release: 'R2' },
        { id: 'dies', label: 'Dies & Tooling', icon: 'hammer', release: 'R2' },
      ]},
      { label: 'Work', items: [
        { id: 'breakdowns', label: 'Breakdowns', icon: 'octagon-alert', release: 'R2' },
        { id: 'pm', label: 'Preventive Maintenance', icon: 'calendar-clock', release: 'R2' },
      ]},
    ]},
    { id: 'traceability', label: 'Traceability', icon: 'git-branch', permission: 'traceability.view', groups: [
      { label: 'Lot Trace', items: [
        { id: 'backward', label: 'Backward Trace', icon: 'arrow-left-to-line', release: 'R1' },
        { id: 'forward', label: 'Forward Trace', icon: 'arrow-right-to-line', release: 'R1' },
        { id: 'genealogy', label: 'Genealogy Graph', icon: 'network', release: 'R2' },
      ]},
      { label: 'Reconciliation', items: [
        { id: 'trace-cases', label: 'Reconciliation Cases', icon: 'search-check', release: 'R1', badge: 'variance', href: '02-run-reconciliation-desktop.html' },
      ]},
    ]},
    { id: 'analytics', label: 'Analytics', icon: 'chart-column', permission: 'analytics.view', groups: [
      { label: 'Insights', items: [
        { id: 'dashboards', label: 'Dashboards', icon: 'layout-dashboard', release: 'R2' },
        { id: 'reports', label: 'Reports', icon: 'file-bar-chart', release: 'R1' },
      ]},
      { label: 'Data Exchange', items: [
        { id: 'export-center', label: 'Export Center', icon: 'download', release: 'R1' },
        { id: 'import-jobs', label: 'Import Jobs', icon: 'upload', release: 'R2' },
      ]},
    ]},
    { id: 'admin', label: 'Administration', icon: 'settings', permission: 'core.admin.view', pinBottom: true, groups: [
      { label: 'Organization', items: [
        { id: 'company', label: 'Company', icon: 'building-2', release: 'R1' },
        { id: 'plants', label: 'Plants & Warehouses', icon: 'map-pin', release: 'R1' },
        { id: 'work-centers', label: 'Work Centers', icon: 'factory', release: 'R1' },
      ]},
      { label: 'Access', items: [
        { id: 'users', label: 'Users', icon: 'users', release: 'R1' },
        { id: 'roles', label: 'Roles & Permissions', icon: 'key-round', release: 'R1' },
        { id: 'approval-policies', label: 'Approval Policies', icon: 'stamp', release: 'R1' },
      ]},
      { label: 'System', items: [
        { id: 'audit', label: 'Audit Log', icon: 'scroll-text', release: 'R1' },
        { id: 'notif-settings', label: 'Notification Settings', icon: 'bell', release: 'R2' },
        { id: 'tenant-settings', label: 'Tenant Settings', icon: 'sliders-horizontal', release: 'R1' },
      ]},
    ]},
  ],
};
