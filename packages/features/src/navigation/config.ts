import {
  Activity,
  ArrowLeftToLine,
  ArrowRightLeft,
  ArrowRightToLine,
  BadgeCheck,
  BellRing,
  BookOpen,
  Bookmark,
  Boxes,
  Building,
  Building2,
  Calculator,
  CalendarCheck,
  CalendarClock,
  CalendarDays,
  CalendarRange,
  ChartColumn,
  ChartNoAxesColumn,
  CirclePause,
  CirclePlay,
  ClipboardCheck,
  ClipboardList,
  ClipboardPen,
  Clock,
  Cog,
  Component,
  Cylinder,
  Database,
  Handshake,
  Download,
  Factory,
  FileChartColumn,
  FileCheck,
  FileSignature,
  FileStack,
  FileText,
  GitBranch,
  GitMerge,
  Gauge,
  Hammer,
  Hand,
  Hourglass,
  House,
  KeyRound,
  LayoutDashboard,
  LayoutGrid,
  LayoutList,
  Layers,
  Link,
  List,
  ListChecks,
  Lock,
  MapPin,
  MoveDown,
  Network,
  OctagonAlert,
  Package,
  Package2,
  PackageCheck,
  PackageOpen,
  PackagePlus,
  Percent,
  Radar,
  RefreshCcw,
  Replace,
  RotateCcw,
  Ruler,
  Scale,
  Scissors,
  ScrollText,
  SearchCheck,
  Settings,
  ShieldCheck,
  SlidersHorizontal,
  Stamp,
  Tags,
  TimerOff,
  TrendingUp,
  TriangleAlert,
  Truck,
  Undo2,
  Upload,
  Users,
  Warehouse,
  Wrench,
  HandGrab,
} from 'lucide-react';
import type { NavModule } from './types';

/**
 * EORA navigation — single source for every page (docs/uiux/EORA-NAVIGATION-ARCHITECTURE.md §6–7).
 * Pages never declare menus. Routes are `/{module.id}/{item.id}`.
 */
export const NAV_MODULES: readonly NavModule[] = [
  {
    id: 'dashboard',
    label: 'Dashboard',
    icon: LayoutDashboard,
    permission: 'dashboard.view',
    groups: [
      {
        label: 'My Workspace',
        items: [
          { id: 'overview', label: 'Overview', icon: House, release: 'R1', phase: 'later' },
          { id: 'my-tasks', label: 'My Tasks', icon: ListChecks, release: 'R1', badge: 'tasks', phase: 'later' },
          { id: 'approvals', label: 'Approvals Inbox', icon: Stamp, release: 'R1', badge: 'approvals', permission: 'core.approval.view', phase: 'later' },
        ],
      },
    ],
  },
  {
    id: 'demand',
    label: 'Demand',
    icon: FileText,
    permission: 'demand.po.view',
    groups: [
      {
        label: 'Customer Orders',
        items: [
          { id: 'customer-po', label: 'Customer Purchase Orders', icon: FileText, release: 'R1', phase: 'later' },
          { id: 'sales-orders', label: 'Sales Orders', icon: FileCheck, release: 'R1', phase: 'later' },
          { id: 'po-revisions', label: 'Order Revisions', icon: RotateCcw, release: 'R1', phase: 'later' },
        ],
      },
      {
        label: 'Releases & Forecast',
        items: [
          { id: 'forecast', label: 'Forecast', icon: TrendingUp, release: 'R2', phase: 'later' },
          { id: 'releases', label: 'Kanban / Delivery Releases', icon: CalendarCheck, release: 'R2', phase: 'later' },
          { id: 'netting', label: 'Demand Netting', icon: GitMerge, release: 'R2', decision: 'O-011', phase: 'later' },
        ],
      },
      {
        label: 'Master Data',
        items: [
          { id: 'customers', label: 'Customers', icon: Building, release: 'R1', phase: 'later' },
          { id: 'customer-parts', label: 'Customer Part Mapping', icon: Link, release: 'R1', phase: 'later' },
        ],
      },
    ],
  },
  {
    id: 'ppic',
    label: 'PPIC / Planning',
    icon: CalendarRange,
    permission: 'planning.control_tower.view',
    groups: [
      {
        label: 'Overview',
        items: [
          { id: 'control-tower', label: 'Control Tower', icon: Radar, release: 'R1', phase: 3, topbarContext: 'period' },
          { id: 'planning-dashboard', label: 'Planning Dashboard', icon: LayoutGrid, release: 'R2', phase: 'later' },
        ],
      },
      {
        label: 'Customer Demand',
        items: [
          { id: 'demand-fulfillment', label: 'Demand & Fulfillment', icon: ListChecks, release: 'R1', phase: 3 },
          { id: 'customer-po', label: 'Customer Purchase Orders', icon: FileText, release: 'R1', phase: 'later' },
          { id: 'sales-orders', label: 'Sales Orders', icon: FileCheck, release: 'R1', phase: 'later' },
          { id: 'forecast', label: 'Forecast', icon: TrendingUp, release: 'R2', phase: 'later' },
          { id: 'delivery-schedules', label: 'Delivery Schedules', icon: CalendarCheck, release: 'R1', phase: 'later' },
        ],
      },
      {
        label: 'Production Planning',
        items: [
          { id: 'mps', label: 'Master Production Schedule', icon: CalendarDays, release: 'R1', phase: 'later' },
          { id: 'work-orders', label: 'Work Orders', icon: ClipboardList, release: 'R1', phase: 'later' },
          { id: 'capacity', label: 'Capacity Planning', icon: Gauge, release: 'R2', phase: 'later' },
        ],
      },
      {
        label: 'Material Control',
        items: [
          { id: 'rm-availability', label: 'Raw Material Availability', icon: Boxes, release: 'R1', phase: 3 },
          { id: 'conversion', label: 'Coil / Sheet Conversion', icon: Calculator, release: 'R1', phase: 3 },
          { id: 'shortage', label: 'Shortage & Delivery Risk', icon: TriangleAlert, release: 'R1', phase: 3 },
        ],
      },
      {
        label: 'Production Monitoring',
        items: [
          { id: 'wo-progress', label: 'Work Order Progress', icon: Activity, release: 'R1', phase: 3 },
          { id: 'daily-results', label: 'Daily Production Results', icon: Factory, release: 'R1', phase: 3 },
          { id: 'pending-quality', label: 'Pending Quality', icon: Hourglass, release: 'R1', phase: 3 },
        ],
      },
      {
        label: 'Inventory Monitoring',
        items: [
          { id: 'fg-availability', label: 'Finished Goods Availability', icon: PackageCheck, release: 'R1', phase: 3 },
          { id: 'material-at-production', label: 'Material at Production', icon: PackageOpen, release: 'R1', phase: 3 },
        ],
      },
      {
        label: 'Reconciliation',
        items: [
          { id: 'consumption', label: 'Material Consumption', icon: Scale, release: 'R1', phase: 4 },
          { id: 'material-variance', label: 'Material Variance', icon: ChartNoAxesColumn, release: 'R1', phase: 3 },
          { id: 'variance-investigation', label: 'Variance Investigation', icon: SearchCheck, release: 'R1', badge: 'variance', phase: 4 },
        ],
      },
      {
        label: 'Reports',
        items: [
          { id: 'daily-fg', label: 'Daily Finished Goods', icon: FileChartColumn, release: 'R1', phase: 3, topbarContext: 'period' },
          { id: 'daily-delivery', label: 'Daily Delivery', icon: FileChartColumn, release: 'R1', phase: 3, topbarContext: 'period' },
          { id: 'production-fulfillment', label: 'Production Fulfillment', icon: FileChartColumn, release: 'R2', phase: 'later' },
        ],
      },
    ],
  },
  {
    id: 'materials',
    label: 'Materials',
    icon: Layers,
    permission: 'materials.issue.view',
    groups: [
      {
        label: 'Receiving',
        items: [
          { id: 'rm-receipts', label: 'RM Receipts', icon: PackagePlus, release: 'R1', phase: 'later' },
          { id: 'incoming-release', label: 'Incoming Release', icon: ShieldCheck, release: 'R2', decision: 'O-013', phase: 'later' },
        ],
      },
      {
        label: 'Issue & Return',
        items: [
          { id: 'material-issue', label: 'Material Issue', icon: ArrowRightLeft, release: 'R1', phase: 'later' },
          { id: 'custody-acceptance', label: 'Custody Acceptance', icon: Hand, release: 'R2', badge: 'tasks', phase: 'later' },
          { id: 'material-return', label: 'Material Return', icon: Undo2, release: 'R1', phase: 'later' },
          { id: 'substitution', label: 'Substitution Requests', icon: Replace, release: 'R2', phase: 'later' },
        ],
      },
      {
        label: 'Lots',
        items: [
          { id: 'material-lots', label: 'Material Lots', icon: Tags, release: 'R1', phase: 'later' },
          { id: 'remnants', label: 'Remnants', icon: Scissors, release: 'R1', phase: 'later' },
        ],
      },
    ],
  },
  {
    id: 'production',
    label: 'Production',
    icon: Factory,
    permission: 'production.run.view',
    groups: [
      {
        label: 'Execution',
        items: [
          { id: 'runs', label: 'Production Runs', icon: CirclePlay, release: 'R1', phase: 4 },
          { id: 'run-reporting', label: 'Run Reporting', icon: ClipboardPen, release: 'R1', phase: 5 },
          { id: 'downtime', label: 'Downtime', icon: TimerOff, release: 'R2', phase: 'later' },
        ],
      },
      {
        label: 'Output',
        items: [
          { id: 'postings', label: 'Production Postings', icon: FileStack, release: 'R1', phase: 4 },
          { id: 'rework-orders', label: 'Rework Orders', icon: RefreshCcw, release: 'R2', phase: 'later' },
        ],
      },
      {
        label: 'Reconciliation',
        items: [
          { id: 'run-reconciliation', label: 'Run Reconciliation', icon: Scale, release: 'R1', phase: 4 },
          { id: 'measurements', label: 'Measurements', icon: Ruler, release: 'R1', phase: 4 },
        ],
      },
    ],
  },
  {
    id: 'quality',
    label: 'Quality',
    icon: BadgeCheck,
    permission: 'quality.inspection.view',
    groups: [
      {
        label: 'Inspection',
        items: [
          { id: 'pending-qc', label: 'Pending QC Queue', icon: Hourglass, release: 'R1', badge: 'qc', phase: 'later' },
          { id: 'inspection-lots', label: 'Inspection Lots', icon: ClipboardCheck, release: 'R1', phase: 'later' },
          { id: 'hold-lots', label: 'Hold Lots', icon: CirclePause, release: 'R2', phase: 'later' },
        ],
      },
      {
        label: 'Master Data',
        items: [
          { id: 'defect-codes', label: 'Defect Codes', icon: List, release: 'R2', phase: 'later' },
          { id: 'sampling-plans', label: 'Sampling Plans', icon: Percent, release: 'R2', phase: 'later' },
        ],
      },
    ],
  },
  {
    id: 'inventory',
    label: 'Inventory',
    icon: Boxes,
    permission: 'inventory.view',
    groups: [
      {
        label: 'Stock',
        items: [
          { id: 'stock-overview', label: 'Stock Overview', icon: LayoutList, release: 'R1', phase: 'later' },
          { id: 'raw-material', label: 'Raw Material', icon: Cylinder, release: 'R1', phase: 'later', topbarContext: 'warehouse' },
          { id: 'semi-finished', label: 'Semi-Finished Material', icon: Component, release: 'R1', phase: 'later' },
          { id: 'finished-goods', label: 'Finished Goods', icon: PackageCheck, release: 'R1', phase: 'later' },
          { id: 'material-at-production', label: 'Material at Production', icon: PackageOpen, release: 'R1', phase: 'later' },
        ],
      },
      {
        label: 'Ledger',
        items: [
          { id: 'movements', label: 'Movement Ledger', icon: BookOpen, release: 'R1', phase: 'later' },
          { id: 'allocations', label: 'Allocations', icon: Bookmark, release: 'R1', phase: 'later' },
        ],
      },
      {
        label: 'Control',
        items: [
          { id: 'stock-count', label: 'Stock Count', icon: ClipboardList, release: 'R2', phase: 'later' },
          { id: 'adjustments', label: 'Adjustments', icon: SlidersHorizontal, release: 'R1', badge: 'approvals', phase: 'later' },
          { id: 'reversals', label: 'Reversals', icon: RotateCcw, release: 'R1', phase: 'later' },
          { id: 'period-close', label: 'Period Close', icon: Lock, release: 'R2', phase: 'later' },
        ],
      },
    ],
  },
  {
    id: 'warehouse',
    label: 'Warehouse',
    icon: Warehouse,
    permission: 'warehouse.view',
    groups: [
      {
        label: 'Inbound',
        items: [
          { id: 'fg-receipt', label: 'FG Receipt', icon: PackagePlus, release: 'R1', phase: 'later' },
          { id: 'putaway', label: 'Putaway', icon: MoveDown, release: 'R1', phase: 'later' },
        ],
      },
      {
        label: 'Outbound',
        items: [
          { id: 'handling-units', label: 'Handling Units', icon: Package, release: 'R2', phase: 'later' },
          { id: 'picking', label: 'Picking', icon: HandGrab, release: 'R2', phase: 'later' },
          { id: 'packing', label: 'Packing', icon: Package2, release: 'R2', phase: 'later' },
        ],
      },
    ],
  },
  {
    id: 'delivery',
    label: 'Delivery',
    icon: Truck,
    permission: 'delivery.view',
    groups: [
      {
        label: 'Shipping',
        items: [
          { id: 'delivery-orders', label: 'Delivery Orders', icon: FileText, release: 'R1', phase: 'later' },
          { id: 'shipments', label: 'Shipments', icon: Truck, release: 'R1', phase: 'later' },
          { id: 'proof-of-delivery', label: 'Proof of Delivery', icon: FileSignature, release: 'R2', phase: 'later' },
        ],
      },
      { label: 'Monitoring', items: [{ id: 'outstanding-demand', label: 'Outstanding Demand', icon: Clock, release: 'R1', phase: 3 }] },
    ],
  },
  {
    id: 'maintenance',
    label: 'Maintenance',
    icon: Wrench,
    permission: 'maintenance.view',
    groups: [
      {
        label: 'Assets',
        items: [
          { id: 'machines', label: 'Machines', icon: Cog, release: 'R2', phase: 'later' },
          { id: 'dies', label: 'Dies & Tooling', icon: Hammer, release: 'R2', phase: 'later' },
        ],
      },
      {
        label: 'Work',
        items: [
          { id: 'breakdowns', label: 'Breakdowns', icon: OctagonAlert, release: 'R2', phase: 'later' },
          { id: 'preventive', label: 'Preventive Maintenance', icon: CalendarClock, release: 'R2', phase: 'later' },
        ],
      },
    ],
  },
  {
    id: 'traceability',
    label: 'Traceability',
    icon: GitBranch,
    permission: 'traceability.view',
    groups: [
      {
        label: 'Lot Trace',
        items: [
          { id: 'backward-trace', label: 'Backward Trace', icon: ArrowLeftToLine, release: 'R1', phase: 'later' },
          { id: 'forward-trace', label: 'Forward Trace', icon: ArrowRightToLine, release: 'R1', phase: 'later' },
          { id: 'genealogy', label: 'Genealogy Graph', icon: Network, release: 'R2', phase: 'later' },
        ],
      },
      {
        label: 'Reconciliation',
        items: [{ id: 'reconciliation-cases', label: 'Reconciliation Cases', icon: SearchCheck, release: 'R1', badge: 'variance', phase: 4 }],
      },
    ],
  },
  {
    id: 'analytics',
    label: 'Analytics',
    icon: ChartColumn,
    permission: 'analytics.view',
    groups: [
      {
        label: 'Insights',
        items: [
          { id: 'dashboards', label: 'Dashboards', icon: LayoutDashboard, release: 'R2', phase: 'later' },
          { id: 'reports', label: 'Reports', icon: FileChartColumn, release: 'R1', phase: 'later' },
        ],
      },
      {
        label: 'Data Exchange',
        items: [
          { id: 'export-center', label: 'Export Center', icon: Download, release: 'R1', phase: 'later' },
          { id: 'import-jobs', label: 'Import Jobs', icon: Upload, release: 'R2', phase: 'later' },
        ],
      },
    ],
  },
  {
    // D-074: one module for the part, partner and (later) engineering master
    id: 'master',
    label: 'Master Data',
    icon: Database,
    permission: 'master.part.view',
    groups: [
      {
        label: 'Parts',
        items: [
          { id: 'items', label: 'Items', icon: Package, release: 'R1', phase: 'later' },
          { id: 'units', label: 'Units & Conversions', icon: Ruler, release: 'R1', phase: 'later' },
        ],
      },
      {
        label: 'Partners',
        items: [
          { id: 'customers', label: 'Customers', icon: Building, release: 'R1', phase: 'later' },
          { id: 'customer-parts', label: 'Customer Part Mapping', icon: Link, release: 'R1', phase: 'later' },
          { id: 'suppliers', label: 'Suppliers', icon: Handshake, release: 'R1', phase: 'later' },
        ],
      },
    ],
  },
  {
    id: 'admin',
    label: 'Administration',
    icon: Settings,
    permission: 'core.admin.view',
    pinBottom: true,
    groups: [
      {
        label: 'Organization',
        items: [
          { id: 'company', label: 'Company', icon: Building2, release: 'R1', phase: 'later' },
          { id: 'plants', label: 'Plants & Warehouses', icon: MapPin, release: 'R1', phase: 'later' },
          { id: 'work-centers', label: 'Work Centers', icon: Factory, release: 'R1', phase: 'later' },
        ],
      },
      {
        label: 'Access',
        items: [
          { id: 'users', label: 'Users', icon: Users, release: 'R1', phase: 'later' },
          { id: 'roles', label: 'Roles & Permissions', icon: KeyRound, release: 'R1', phase: 'later' },
          { id: 'approval-policies', label: 'Approval Policies', icon: Stamp, release: 'R1', phase: 'later' },
        ],
      },
      {
        label: 'System',
        items: [
          { id: 'audit-log', label: 'Audit Log', icon: ScrollText, release: 'R1', phase: 'later' },
          { id: 'notification-settings', label: 'Notification Settings', icon: BellRing, release: 'R2', phase: 'later' },
          { id: 'tenant-settings', label: 'Tenant Settings', icon: SlidersHorizontal, release: 'R1', phase: 'later' },
        ],
      },
    ],
  },
] as const;
