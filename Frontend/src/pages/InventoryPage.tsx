import React, { useEffect, useState, useMemo } from 'react';
import {
  Boxes,
  Plus,
  ArrowLeftRight,
  ShieldAlert,
  Clock,
  Search,
  RefreshCw,
  CheckCircle2,
  AlertTriangle,
  Layers,
  ChevronDown,
  ChevronRight,
  TrendingDown,
  Truck,
  RotateCcw,
  Eye,
  SlidersHorizontal,
  Sparkles,
  ClipboardList,
  FileSpreadsheet,
  Building2,
  Check,
  X,
  Package,
  Trash2,
} from 'lucide-react';
import MetricCard from '../components/common/MetricCard';
import CustomDropdown from '../components/common/CustomDropdown';
import omsService from '../services/omsService';
import type {
  InventoryItemModel,
  InventoryKpiModel,
  InventoryReceiptModel,
  InventoryTransferModel,
  StockAdjustmentModel,
  CycleCountModel,
  ReplenishmentRecommendationModel,
  InventorySkuDetailModel,
  WarehouseModel,
  ProductModel,
} from '../services/omsService';

type TabKey = 'balances' | 'receipts' | 'transfers' | 'adjustments' | 'counts' | 'ledger' | 'replenishment';

export const InventoryPage: React.FC = () => {
  const [activeTab, setActiveTab] = useState<TabKey>('balances');
  const [loading, setLoading] = useState(true);
  const [feedback, setFeedback] = useState<{ type: 'success' | 'error'; text: string } | null>(null);

  // Master Data
  const [warehouses, setWarehouses] = useState<WarehouseModel[]>([]);
  const [products, setProducts] = useState<ProductModel[]>([]);
  const [selectedWarehouseId, setSelectedWarehouseId] = useState<string>('all');

  // KPIs
  const [kpis, setKpis] = useState<InventoryKpiModel>({
    total_skus: 0,
    total_on_hand: 0,
    total_reserved: 0,
    total_available: 0,
    total_quarantined: 0,
    total_damaged: 0,
    total_in_transit: 0,
    low_stock_count: 0,
    out_of_stock_count: 0,
    total_valuation: 0,
  });

  // Tab 1: Balances State
  const [balances, setBalances] = useState<InventoryItemModel[]>([]);
  const [balanceSearch, setBalanceSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState<'all' | 'in_stock' | 'low_stock' | 'out_of_stock'>('all');
  const [expandedRows, setExpandedRows] = useState<Record<number, boolean>>({});

  // Tab 2: Receipts State
  const [receipts, setReceipts] = useState<InventoryReceiptModel[]>([]);
  const [showNewReceiptModal, setShowNewReceiptModal] = useState(false);
  const [showConfirmReceiptModal, setShowConfirmReceiptModal] = useState<InventoryReceiptModel | null>(null);
  const [showPutawayModal, setShowPutawayModal] = useState<{ receiptId: number; itemId: number; sku?: string; maxQty?: number } | null>(null);

  // Tab 3: Transfers State
  const [transfers, setTransfers] = useState<InventoryTransferModel[]>([]);
  const [showNewTransferModal, setShowNewTransferModal] = useState(false);
  const [showReceiveTransferModal, setShowReceiveTransferModal] = useState<InventoryTransferModel | null>(null);

  // Tab 4: Adjustments State
  const [adjustments, setAdjustments] = useState<StockAdjustmentModel[]>([]);
  const [showNewAdjustmentModal, setShowNewAdjustmentModal] = useState(false);

  // Tab 5: Cycle Counts State
  const [cycleCounts, setCycleCounts] = useState<CycleCountModel[]>([]);
  const [showNewCycleCountModal, setShowNewCycleCountModal] = useState(false);
  const [reconciliationResult, setReconciliationResult] = useState<any | null>(null);

  // Tab 6: Movements State
  const [movements, setMovements] = useState<any[]>([]);

  // Tab 7: Replenishment State
  const [recommendations, setRecommendations] = useState<ReplenishmentRecommendationModel[]>([]);
  const [showNewRuleModal, setShowNewRuleModal] = useState(false);

  // Detail Modal State
  const [detailVariantId, setDetailVariantId] = useState<number | null>(null);
  const [skuDetail, setSkuDetail] = useState<InventorySkuDetailModel | null>(null);
  const [detailLoading, setDetailLoading] = useState(false);

  // ----------------------------------------------------------------
  // Data Fetching
  // ----------------------------------------------------------------
  const fetchMetadata = async () => {
    try {
      const [whRes, prodRes] = await Promise.all([
        omsService.getWarehouses(),
        omsService.getProducts({ per_page: 200 }),
      ]);
      setWarehouses(whRes.data || []);
      setProducts(prodRes.data || []);
    } catch (err) {
      console.error('Failed to load warehouses/products metadata', err);
    }
  };

  const fetchKpis = async () => {
    try {
      const whId = selectedWarehouseId !== 'all' ? Number(selectedWarehouseId) : undefined;
      const res = await omsService.getInventoryKpis(whId);
      setKpis(res.data);
    } catch (err) {
      console.error('Failed to fetch KPIs', err);
    }
  };

  const fetchBalances = async () => {
    try {
      setLoading(true);
      const whId = selectedWarehouseId !== 'all' ? Number(selectedWarehouseId) : undefined;
      const res = await omsService.getInventory({
        search: balanceSearch || undefined,
        warehouse_id: whId,
        status: statusFilter !== 'all' ? statusFilter : undefined,
        per_page: 50,
      });
      setBalances(res.data || []);
    } catch (err) {
      console.error('Failed to fetch balances', err);
    } finally {
      setLoading(false);
    }
  };

  const fetchReceipts = async () => {
    try {
      setLoading(true);
      const whId = selectedWarehouseId !== 'all' ? Number(selectedWarehouseId) : undefined;
      const res = await omsService.getInventoryReceipts({ warehouse_id: whId, per_page: 30 });
      setReceipts(res.data || []);
    } catch (err) {
      console.error(err);
    } finally {
      setLoading(false);
    }
  };

  const fetchTransfers = async () => {
    try {
      setLoading(true);
      const res = await omsService.getInventoryTransfers({ per_page: 30 });
      setTransfers(res.data || []);
    } catch (err) {
      console.error(err);
    } finally {
      setLoading(false);
    }
  };

  const fetchAdjustments = async () => {
    try {
      setLoading(true);
      const whId = selectedWarehouseId !== 'all' ? Number(selectedWarehouseId) : undefined;
      const res = await omsService.getInventoryAdjustments({ warehouse_id: whId, per_page: 30 });
      setAdjustments(res.data || []);
    } catch (err) {
      console.error(err);
    } finally {
      setLoading(false);
    }
  };

  const fetchCycleCounts = async () => {
    try {
      setLoading(true);
      const whId = selectedWarehouseId !== 'all' ? Number(selectedWarehouseId) : undefined;
      const res = await omsService.getCycleCounts({ warehouse_id: whId, per_page: 30 });
      setCycleCounts(res.data || []);
    } catch (err) {
      console.error(err);
    } finally {
      setLoading(false);
    }
  };

  const fetchMovements = async () => {
    try {
      setLoading(true);
      const whId = selectedWarehouseId !== 'all' ? Number(selectedWarehouseId) : undefined;
      const res = await omsService.getStockMovements({ warehouse_id: whId, per_page: 30 });
      setMovements(res.data || []);
    } catch (err) {
      console.error(err);
    } finally {
      setLoading(false);
    }
  };

  const fetchReplenishment = async () => {
    try {
      setLoading(true);
      const whId = selectedWarehouseId !== 'all' ? Number(selectedWarehouseId) : undefined;
      const res = await omsService.getReplenishmentRecommendations(whId);
      setRecommendations(res.data || []);
    } catch (err) {
      console.error(err);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchMetadata();
  }, []);

  useEffect(() => {
    fetchKpis();
    if (activeTab === 'balances') fetchBalances();
    else if (activeTab === 'receipts') fetchReceipts();
    else if (activeTab === 'transfers') fetchTransfers();
    else if (activeTab === 'adjustments') fetchAdjustments();
    else if (activeTab === 'counts') fetchCycleCounts();
    else if (activeTab === 'ledger') fetchMovements();
    else if (activeTab === 'replenishment') fetchReplenishment();
  }, [activeTab, selectedWarehouseId]);

  // SKU Detail Inspector
  const openSkuDetail = async (variantId: number) => {
    setDetailVariantId(variantId);
    try {
      setDetailLoading(true);
      const res = await omsService.getInventorySkuDetail(variantId);
      setSkuDetail(res.data);
    } catch (err) {
      console.error('Failed to load SKU detail', err);
    } finally {
      setDetailLoading(false);
    }
  };

  // Run Reconciliation Audit
  const handleRunReconciliation = async () => {
    try {
      setLoading(true);
      setFeedback(null);
      const whId = selectedWarehouseId !== 'all' ? Number(selectedWarehouseId) : undefined;
      const res = await omsService.reconcileInventory(whId);
      setReconciliationResult(res.data);
      if (res.data.reconciled) {
        setFeedback({ type: 'success', text: `Reconciliation Audit Complete: All ${res.data.total_checked} inventory records match ledger net entries.` });
      } else {
        setFeedback({ type: 'error', text: `Reconciliation Alert: Found ${res.data.discrepancy_count} discrepancy records.` });
      }
    } catch (err: any) {
      setFeedback({ type: 'error', text: err.message || 'Reconciliation failed.' });
    } finally {
      setLoading(false);
    }
  };

  // Memoized Variants List for Dropdowns
  const allVariants = useMemo(() => {
    const list: Array<{ id: number; sku: string; name: string }> = [];
    products.forEach((p) => {
      if (p.variants && p.variants.length > 0) {
        p.variants.forEach((v) => {
          list.push({
            id: v.id,
            sku: v.sku,
            name: `${p.name} (${v.sku})`,
          });
        });
      } else if (p.default_variant_id) {
        list.push({
          id: p.default_variant_id,
          sku: p.sku,
          name: `${p.name} (${p.sku})`,
        });
      } else {
        list.push({
          id: p.id,
          sku: p.sku,
          name: `${p.name} (${p.sku})`,
        });
      }
    });
    return list;
  }, [products]);

  // Tab Badge Metrics
  const draftReceiptsCount = useMemo(() => receipts.filter((r) => r.status === 'draft').length, [receipts]);
  const inTransitTransfersCount = useMemo(() => transfers.filter((t) => t.status === 'in_transit').length, [transfers]);
  const pendingAdjustmentsCount = useMemo(() => adjustments.filter((a) => a.status === 'pending_approval').length, [adjustments]);
  const reviewCountsCount = useMemo(() => cycleCounts.filter((c) => c.status === 'review').length, [cycleCounts]);

  // Form State: Inbound Receipt
  const [receiptWhId, setReceiptWhId] = useState<string>('');
  const [receiptSupplier, setReceiptSupplier] = useState<string>('');
  const [receiptPo, setReceiptPo] = useState<string>('');
  const [receiptNotes, setReceiptNotes] = useState<string>('');
  const [receiptItems, setReceiptItems] = useState<
    Array<{ product_variant_id: number; expected_quantity: number; lot_number?: string; expiry_date?: string; unit_cost?: number }>
  >([{ product_variant_id: 0, expected_quantity: 10 }]);

  // Form State: Confirm Receipt
  const [confirmRows, setConfirmRows] = useState<
    Record<number, { received_quantity: number; condition: string; lot_number: string; expiry_date: string }>
  >({});

  // Form State: Putaway
  const [putawayLocCode, setPutawayLocCode] = useState<string>('A-01-B-01');
  const [putawayQty, setPutawayQty] = useState<number>(1);

  // Form State: Transfer
  const [transferFromWh, setTransferFromWh] = useState<string>('');
  const [transferToWh, setTransferToWh] = useState<string>('');
  const [transferVariantId, setTransferVariantId] = useState<string>('');
  const [transferQty, setTransferQty] = useState<number>(10);
  const [transferInTransit, setTransferInTransit] = useState<boolean>(true);
  const [transferNotes, setTransferNotes] = useState<string>('');

  // Form State: Receive Transfer
  const [receiveTransferQty, setReceiveTransferQty] = useState<number>(0);
  const [receiveTransferReason, setReceiveTransferReason] = useState<string>('');

  // Form State: Adjustment
  const [adjWhId, setAdjWhId] = useState<string>('');
  const [adjVariantId, setAdjVariantId] = useState<string>('');
  const [adjType, setAdjType] = useState<'add' | 'subtract' | 'recount'>('add');
  const [adjQty, setAdjQty] = useState<number>(5);
  const [adjReason, setAdjReason] = useState<string>('Cycle Count Variance');
  const [adjNotes, setAdjNotes] = useState<string>('');

  // Form State: Cycle Count
  const [countWhId, setCountWhId] = useState<string>('');
  const [countTitle, setCountTitle] = useState<string>('');
  const [countScope, setCountScope] = useState<'full' | 'zone' | 'location'>('full');
  const [countScopeVal, setCountScopeVal] = useState<string>('');
  const [countNotes, setCountNotes] = useState<string>('');

  // Form State: Replenishment Rule
  const [ruleWhId, setRuleWhId] = useState<string>('');
  const [ruleVariantId, setRuleVariantId] = useState<string>('');
  const [ruleRop, setRuleRop] = useState<number>(20);
  const [ruleSafety, setRuleSafety] = useState<number>(10);
  const [ruleEoq, setRuleEoq] = useState<number>(50);
  const [ruleSupplier, setRuleSupplier] = useState<string>('');
  const [ruleLeadDays, setRuleLeadDays] = useState<number>(7);

  // Sync state when confirm receipt modal opens
  useEffect(() => {
    if (showConfirmReceiptModal && showConfirmReceiptModal.items) {
      const init: Record<number, { received_quantity: number; condition: string; lot_number: string; expiry_date: string }> = {};
      showConfirmReceiptModal.items.forEach((item) => {
        init[item.id] = {
          received_quantity: item.expected_quantity,
          condition: 'available',
          lot_number: item.lot_number || '',
          expiry_date: item.expiry_date || '',
        };
      });
      setConfirmRows(init);
    }
  }, [showConfirmReceiptModal]);

  // Sync state when receive transfer modal opens
  useEffect(() => {
    if (showReceiveTransferModal) {
      setReceiveTransferQty(showReceiveTransferModal.quantity);
      setReceiveTransferReason('');
    }
  }, [showReceiveTransferModal]);

  // Sync state when putaway modal opens
  useEffect(() => {
    if (showPutawayModal) {
      setPutawayQty(showPutawayModal.maxQty || 1);
      setPutawayLocCode('A-01-B-01');
    }
  }, [showPutawayModal]);

  // Submission Handlers
  const handleCreateReceipt = async (e: React.FormEvent) => {
    e.preventDefault();
    const whId = Number(receiptWhId || (warehouses[0]?.id || 0));
    if (!whId) {
      setFeedback({ type: 'error', text: 'Please select a receiving warehouse facility.' });
      return;
    }
    const validItems = receiptItems.filter((i) => i.product_variant_id > 0 && i.expected_quantity > 0);
    if (validItems.length === 0) {
      setFeedback({ type: 'error', text: 'Please select at least one valid product SKU and quantity.' });
      return;
    }
    try {
      setLoading(true);
      await omsService.createInventoryReceipt({
        warehouse_id: whId,
        supplier_name: receiptSupplier.trim() || undefined,
        purchase_order_number: receiptPo.trim() || undefined,
        notes: receiptNotes.trim() || undefined,
        items: validItems,
      });
      setShowNewReceiptModal(false);
      setReceiptSupplier('');
      setReceiptPo('');
      setReceiptNotes('');
      setReceiptItems([{ product_variant_id: 0, expected_quantity: 10 }]);
      setFeedback({ type: 'success', text: 'Inbound dock receipt created in Draft status.' });
      fetchReceipts();
      fetchKpis();
    } catch (err: any) {
      setFeedback({ type: 'error', text: err.message || 'Failed to create receipt.' });
    } finally {
      setLoading(false);
    }
  };

  const handleConfirmReceipt = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!showConfirmReceiptModal) return;
    const itemsPayload = Object.entries(confirmRows).map(([itemId, row]) => ({
      item_id: Number(itemId),
      received_quantity: Number(row.received_quantity),
      condition: row.condition,
      lot_number: row.lot_number.trim() || undefined,
      expiry_date: row.expiry_date.trim() || undefined,
    }));
    try {
      setLoading(true);
      await omsService.confirmInventoryReceipt(showConfirmReceiptModal.id, { items: itemsPayload });
      setShowConfirmReceiptModal(null);
      setFeedback({ type: 'success', text: `Receipt ${showConfirmReceiptModal.receipt_number} confirmed into dock inventory.` });
      fetchReceipts();
      fetchBalances();
      fetchKpis();
    } catch (err: any) {
      setFeedback({ type: 'error', text: err.message || 'Failed to confirm receipt.' });
    } finally {
      setLoading(false);
    }
  };

  const handlePutaway = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!showPutawayModal) return;
    try {
      setLoading(true);
      await omsService.putawayReceiptItem(showPutawayModal.receiptId, {
        item_id: showPutawayModal.itemId,
        destination_location_code: putawayLocCode.trim(),
        quantity: putawayQty,
      });
      setShowPutawayModal(null);
      setFeedback({ type: 'success', text: `Item putaway completed into storage bin ${putawayLocCode.trim()}.` });
      fetchReceipts();
      fetchBalances();
    } catch (err: any) {
      setFeedback({ type: 'error', text: err.message || 'Failed to complete putaway.' });
    } finally {
      setLoading(false);
    }
  };

  const handleCreateTransfer = async (e: React.FormEvent) => {
    e.preventDefault();
    const fromId = Number(transferFromWh || (warehouses[0]?.id || 0));
    const toId = Number(transferToWh || (warehouses[1]?.id || warehouses[0]?.id || 0));
    const varId = Number(transferVariantId || (allVariants[0]?.id || 0));
    if (fromId === toId) {
      setFeedback({ type: 'error', text: 'Source and destination facilities must be different.' });
      return;
    }
    if (!varId || transferQty <= 0) {
      setFeedback({ type: 'error', text: 'Please select a valid product SKU and quantity.' });
      return;
    }
    try {
      setLoading(true);
      await omsService.createInventoryTransfer({
        from_warehouse_id: fromId,
        to_warehouse_id: toId,
        product_variant_id: varId,
        quantity: transferQty,
        notes: transferNotes.trim() || undefined,
        in_transit_only: transferInTransit,
      });
      setShowNewTransferModal(false);
      setTransferNotes('');
      setFeedback({ type: 'success', text: 'Inter-warehouse transfer initiated and dispatched.' });
      fetchTransfers();
      fetchBalances();
      fetchKpis();
    } catch (err: any) {
      setFeedback({ type: 'error', text: err.message || 'Transfer failed.' });
    } finally {
      setLoading(false);
    }
  };

  const handleReceiveTransfer = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!showReceiveTransferModal) return;
    try {
      setLoading(true);
      await omsService.receiveInventoryTransfer(showReceiveTransferModal.id, {
        received_quantity: receiveTransferQty,
        discrepancy_reason: receiveTransferReason.trim() || undefined,
      });
      setShowReceiveTransferModal(null);
      setFeedback({ type: 'success', text: `Transfer ${showReceiveTransferModal.transfer_number} received at destination.` });
      fetchTransfers();
      fetchBalances();
      fetchKpis();
    } catch (err: any) {
      setFeedback({ type: 'error', text: err.message || 'Receive transfer failed.' });
    } finally {
      setLoading(false);
    }
  };

  const handleCreateAdjustment = async (e: React.FormEvent) => {
    e.preventDefault();
    const whId = Number(adjWhId || (warehouses[0]?.id || 0));
    const varId = Number(adjVariantId || (allVariants[0]?.id || 0));
    if (!whId || !varId || adjQty <= 0) {
      setFeedback({ type: 'error', text: 'Please specify facility, product SKU, and quantity.' });
      return;
    }
    try {
      setLoading(true);
      await omsService.createInventoryAdjustment({
        warehouse_id: whId,
        product_variant_id: varId,
        adjustment_type: adjType,
        quantity: adjQty,
        reason: adjReason,
        notes: adjNotes.trim() || undefined,
      });
      setShowNewAdjustmentModal(false);
      setAdjNotes('');
      setFeedback({ type: 'success', text: 'Stock adjustment submitted for approval.' });
      fetchAdjustments();
      fetchKpis();
    } catch (err: any) {
      setFeedback({ type: 'error', text: err.message || 'Adjustment creation failed.' });
    } finally {
      setLoading(false);
    }
  };

  const handleCreateCycleCount = async (e: React.FormEvent) => {
    e.preventDefault();
    const whId = Number(countWhId || (warehouses[0]?.id || 0));
    if (!whId || !countTitle.trim()) {
      setFeedback({ type: 'error', text: 'Please enter a count session title and facility.' });
      return;
    }
    try {
      setLoading(true);
      await omsService.createCycleCount({
        warehouse_id: whId,
        title: countTitle.trim(),
        scope_type: countScope,
        scope_value: countScopeVal.trim() || undefined,
        notes: countNotes.trim() || undefined,
      });
      setShowNewCycleCountModal(false);
      setCountTitle('');
      setCountNotes('');
      setFeedback({ type: 'success', text: 'Cycle count session initialized with baseline snapshot.' });
      fetchCycleCounts();
    } catch (err: any) {
      setFeedback({ type: 'error', text: err.message || 'Cycle count creation failed.' });
    } finally {
      setLoading(false);
    }
  };

  const handleSaveRule = async (e: React.FormEvent) => {
    e.preventDefault();
    const whId = Number(ruleWhId || (warehouses[0]?.id || 0));
    const varId = Number(ruleVariantId || (allVariants[0]?.id || 0));
    if (!whId || !varId || ruleRop < 0 || ruleEoq <= 0) {
      setFeedback({ type: 'error', text: 'Please enter valid reorder point and order lot quantity.' });
      return;
    }
    try {
      setLoading(true);
      await omsService.saveReplenishmentRule({
        warehouse_id: whId,
        product_variant_id: varId,
        min_quantity: ruleSafety,
        reorder_point: ruleRop,
        reorder_quantity: ruleEoq,
        safety_stock: ruleSafety,
        lead_time_days: ruleLeadDays,
        preferred_supplier: ruleSupplier.trim() || undefined,
      });
      setShowNewRuleModal(false);
      setFeedback({ type: 'success', text: 'Replenishment rule configured successfully.' });
      fetchReplenishment();
    } catch (err: any) {
      setFeedback({ type: 'error', text: err.message || 'Failed to save replenishment rule.' });
    } finally {
      setLoading(false);
    }
  };

  const toggleRowExpanded = (id: number) => {
    setExpandedRows((prev) => ({ ...prev, [id]: !prev[id] }));
  };

  return (
    <div className="page-container">
      {/* Breadcrumb Navigation */}
      <nav className="breadcrumb-nav">
        <span>Finovo</span>
        <span>&gt;</span>
        <span>Warehouse Operations</span>
        <span>&gt;</span>
        <span style={{ color: '#0f172a', fontWeight: 600 }}>Enterprise Inventory Management</span>
      </nav>

      {/* Page Header */}
      <div className="page-header-row">
        <div className="page-title-section">
          <div className="page-title-badge-wrap">
            <h1 className="page-title">Enterprise Inventory Engine</h1>
            <span className="page-count-pill">{kpis.total_skus} Active SKUs</span>
            {loading && (
              <span style={{ fontSize: 11, color: '#1e56ff', display: 'flex', alignItems: 'center', gap: 4, fontWeight: 500 }}>
                <RefreshCw size={11} className="animate-spin" /> Syncing...
              </span>
            )}
          </div>
          <p className="page-subtitle">
            Authoritative transactional inventory ledger, multi-location storage bin topology, reservations, receipts, and replenishment.
          </p>
        </div>

        {/* Global Warehouse Filter & Actions */}
        <div className="inventory-header-actions">
          <div className="inventory-header-dropdown">
            <CustomDropdown
              value={selectedWarehouseId}
              onChange={(val) => setSelectedWarehouseId(val)}
              options={[
                { value: 'all', label: 'All Warehouses (Global)' },
                ...warehouses.map((w) => ({ value: String(w.id), label: `${w.code} - ${w.name}` })),
              ]}
              size="sm"
            />
          </div>

          <div className="inventory-header-btn-group">
            <button
              onClick={handleRunReconciliation}
              className="btn-finovo-secondary"
              style={{ padding: '8px 12px', fontSize: 12 }}
              title="Verify Ledger vs Materialized Balances"
            >
              <Sparkles size={14} color="#1e56ff" />
              <span>Audit Ledger</span>
            </button>

            <button
              onClick={() => setShowNewTransferModal(true)}
              className="btn-finovo-secondary"
              style={{ padding: '8px 12px', fontSize: 12 }}
            >
              <ArrowLeftRight size={14} />
              <span>New Transfer</span>
            </button>

            <button
              onClick={() => setShowNewAdjustmentModal(true)}
              className="btn-finovo-primary"
              style={{ padding: '8px 14px', fontSize: 12 }}
            >
              <Plus size={14} />
              <span>Adjust Stock</span>
            </button>
          </div>
        </div>
      </div>

      {/* Global Feedback Banner */}
      {feedback && (
        <div
          style={{
            marginBottom: 18,
            padding: '12px 16px',
            borderRadius: 10,
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'space-between',
            backgroundColor: feedback.type === 'success' ? '#f0fdf4' : '#fef2f2',
            border: `1px solid ${feedback.type === 'success' ? '#bbf7d0' : '#fecaca'}`,
            color: feedback.type === 'success' ? '#166534' : '#991b1b',
            fontSize: 13,
            fontWeight: 500,
          }}
        >
          <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
            {feedback.type === 'success' ? <CheckCircle2 size={16} /> : <AlertTriangle size={16} />}
            <span>{feedback.text}</span>
          </div>
          <button onClick={() => setFeedback(null)} style={{ background: 'none', border: 'none', cursor: 'pointer' }}>
            <X size={14} />
          </button>
        </div>
      )}

      {/* Reconciliation Audit Result Banner */}
      {reconciliationResult && (
        <div
          style={{
            marginBottom: 18,
            padding: '12px 16px',
            borderRadius: 10,
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'space-between',
            backgroundColor: reconciliationResult.reconciled ? '#f0fdf4' : '#fffbeb',
            border: `1px solid ${reconciliationResult.reconciled ? '#bbf7d0' : '#fde68a'}`,
            color: reconciliationResult.reconciled ? '#166534' : '#92400e',
            fontSize: 13,
            fontWeight: 500,
          }}
        >
          <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
            <Sparkles size={16} />
            <span>
              <strong>Audit Ledger Check:</strong> {reconciliationResult.total_checked} records verified.{' '}
              {reconciliationResult.reconciled
                ? 'All physical balances match ledger net movements.'
                : `Found ${reconciliationResult.discrepancy_count} discrepancy records.`}
            </span>
          </div>
          <button
            onClick={() => setReconciliationResult(null)}
            style={{ background: 'none', border: 'none', cursor: 'pointer', color: 'inherit' }}
          >
            <X size={14} />
          </button>
        </div>
      )}

      {/* KPI Cards Grid */}
      <div className="kpi-grid">
        <MetricCard
          title="Total On-Hand"
          value={kpis.total_on_hand.toLocaleString()}
          icon={<Boxes size={20} />}
          pillText="Physical Stock"
          pillVariant="blue"
        />
        <MetricCard
          title="Available To Promise"
          value={kpis.total_available.toLocaleString()}
          icon={<CheckCircle2 size={20} />}
          pillText="Allocatable"
          pillVariant="green"
        />
        <MetricCard
          title="Reserved for Orders"
          value={kpis.total_reserved.toLocaleString()}
          icon={<Clock size={20} />}
          pillText="Locked"
          pillVariant="amber"
        />
        <MetricCard
          title="Quarantined / Damaged"
          value={(kpis.total_quarantined + kpis.total_damaged).toLocaleString()}
          icon={<ShieldAlert size={20} />}
          pillText="Inspection"
          pillVariant="amber"
        />
        <MetricCard
          title="In-Transit Units"
          value={kpis.total_in_transit.toLocaleString()}
          icon={<Truck size={20} />}
          pillText="Moving"
          pillVariant="blue"
        />
        <MetricCard
          title="Low / Out of Stock"
          value={`${kpis.low_stock_count} / ${kpis.out_of_stock_count}`}
          icon={<TrendingDown size={20} />}
          pillText="Restock Alert"
          pillVariant="amber"
        />
        <MetricCard
          title="Inventory Valuation"
          value={`$${kpis.total_valuation.toLocaleString()}`}
          icon={<FileSpreadsheet size={20} />}
          pillText="Asset Value"
          pillVariant="green"
        />
      </div>

      {/* Main Content Workspace Card */}
      <div className="content-card">
        {/* Navigation Tabs Bar */}
        <div className="finovo-tabs-wrapper" role="tablist">
          <button
            onClick={() => setActiveTab('balances')}
            className={`finovo-tab-btn ${activeTab === 'balances' ? 'active' : ''}`}
            role="tab"
            aria-selected={activeTab === 'balances'}
          >
            <Boxes size={16} />
            <span className="tab-label-full">Stock Balances & Topology</span>
            <span className="tab-label-short">Balances</span>
            <span className="finovo-tab-badge">{kpis.total_skus}</span>
          </button>

          <button
            onClick={() => setActiveTab('receipts')}
            className={`finovo-tab-btn ${activeTab === 'receipts' ? 'active' : ''}`}
            role="tab"
            aria-selected={activeTab === 'receipts'}
          >
            <Package size={16} />
            <span className="tab-label-full">Receiving & Putaway</span>
            <span className="tab-label-short">Receiving</span>
            <span className={`finovo-tab-badge ${draftReceiptsCount > 0 ? 'badge-amber' : ''}`}>
              {draftReceiptsCount > 0 ? `${draftReceiptsCount} Draft` : receipts.length}
            </span>
          </button>

          <button
            onClick={() => setActiveTab('transfers')}
            className={`finovo-tab-btn ${activeTab === 'transfers' ? 'active' : ''}`}
            role="tab"
            aria-selected={activeTab === 'transfers'}
          >
            <ArrowLeftRight size={16} />
            <span className="tab-label-full">Inter-Warehouse Transfers</span>
            <span className="tab-label-short">Transfers</span>
            <span className="finovo-tab-badge">
              {inTransitTransfersCount > 0 ? `${inTransitTransfersCount} Transit` : transfers.length}
            </span>
          </button>

          <button
            onClick={() => setActiveTab('adjustments')}
            className={`finovo-tab-btn ${activeTab === 'adjustments' ? 'active' : ''}`}
            role="tab"
            aria-selected={activeTab === 'adjustments'}
          >
            <SlidersHorizontal size={16} />
            <span className="tab-label-full">Adjustments & Approvals</span>
            <span className="tab-label-short">Adjustments</span>
            <span className={`finovo-tab-badge ${pendingAdjustmentsCount > 0 ? 'badge-amber' : ''}`}>
              {pendingAdjustmentsCount > 0 ? `${pendingAdjustmentsCount} Pending` : adjustments.length}
            </span>
          </button>

          <button
            onClick={() => setActiveTab('counts')}
            className={`finovo-tab-btn ${activeTab === 'counts' ? 'active' : ''}`}
            role="tab"
            aria-selected={activeTab === 'counts'}
          >
            <ClipboardList size={16} />
            <span className="tab-label-full">Cycle Counts & Audit</span>
            <span className="tab-label-short">Counts</span>
            <span className={`finovo-tab-badge ${reviewCountsCount > 0 ? 'badge-amber' : ''}`}>
              {reviewCountsCount > 0 ? `${reviewCountsCount} Review` : cycleCounts.length}
            </span>
          </button>

          <button
            onClick={() => setActiveTab('ledger')}
            className={`finovo-tab-btn ${activeTab === 'ledger' ? 'active' : ''}`}
            role="tab"
            aria-selected={activeTab === 'ledger'}
          >
            <Clock size={16} />
            <span className="tab-label-full">Movement Ledger</span>
            <span className="tab-label-short">Ledger</span>
            <span className="finovo-tab-badge">{movements.length}</span>
          </button>

          <button
            onClick={() => setActiveTab('replenishment')}
            className={`finovo-tab-btn ${activeTab === 'replenishment' ? 'active' : ''}`}
            role="tab"
            aria-selected={activeTab === 'replenishment'}
          >
            <RotateCcw size={16} />
            <span className="tab-label-full">Replenishment Rules</span>
            <span className="tab-label-short">Replenish</span>
            <span className={`finovo-tab-badge ${recommendations.length > 0 ? 'badge-amber' : ''}`}>
              {recommendations.length > 0 ? `${recommendations.length} Alert` : '0'}
            </span>
          </button>
        </div>

        {/* ------------------------------------------------------------- */}
        {/* TAB 1: STOCK BALANCES & TOPOLOGY */}
        {/* ------------------------------------------------------------- */}
        {activeTab === 'balances' && (
          <div>
            {/* Filter Bar */}
            <div className="inventory-filter-bar">
              <div className="inventory-filter-group">
                <div className="inventory-search-wrap">
                  <Search size={15} style={{ position: 'absolute', left: 12, top: 10, color: '#94a3b8' }} />
                  <input
                    type="text"
                    placeholder="Search SKU, product name, barcode..."
                    value={balanceSearch}
                    onChange={(e) => setBalanceSearch(e.target.value)}
                    onKeyDown={(e) => e.key === 'Enter' && fetchBalances()}
                    style={{
                      width: '100%',
                      padding: '8px 12px 8px 34px',
                      borderRadius: 8,
                      border: '1px solid #cbd5e1',
                      fontSize: 12,
                      boxSizing: 'border-box',
                    }}
                  />
                </div>

                <div className="inventory-status-dropdown-wrap">
                  <CustomDropdown
                    value={statusFilter}
                    onChange={(val) => setStatusFilter(val as any)}
                    options={[
                      { value: 'all', label: 'All Stock Statuses' },
                      { value: 'in_stock', label: 'In Stock (> 0)' },
                      { value: 'low_stock', label: 'Low Stock (≤ 10)' },
                      { value: 'out_of_stock', label: 'Out of Stock (0)' },
                    ]}
                    size="sm"
                  />
                </div>
              </div>

              <button
                onClick={fetchBalances}
                className="btn-finovo-secondary"
                style={{ padding: '6px 12px', fontSize: 12 }}
                title="Refresh Balances"
              >
                <RefreshCw size={13} />
                <span>Refresh</span>
              </button>
            </div>

            {/* Balances Table with Expandable Physical Storage Bins */}
            <div className="table-responsive-wrapper">
              <table className="finovo-table">
                <thead>
                  <tr>
                    <th style={{ width: 36 }}></th>
                    <th>Product & SKU</th>
                    <th>Warehouse Facility</th>
                    <th style={{ textAlign: 'right' }}>On-Hand</th>
                    <th style={{ textAlign: 'right' }}>Reserved</th>
                    <th style={{ textAlign: 'right' }}>Available (ATP)</th>
                    <th style={{ textAlign: 'right' }}>Quarantined</th>
                    <th style={{ textAlign: 'right' }}>In-Transit</th>
                    <th>Status</th>
                    <th style={{ textAlign: 'right' }}>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  {balances.length === 0 ? (
                    <tr>
                      <td colSpan={10} style={{ textAlign: 'center', padding: '32px 16px', color: '#64748b' }}>
                        No inventory records found matching your filters.
                      </td>
                    </tr>
                  ) : (
                    balances.map((b) => {
                      const isExpanded = !!expandedRows[b.id];
                      const hasLocations = b.locations && b.locations.length > 0;

                      return (
                        <React.Fragment key={b.id}>
                          <tr>
                            <td>
                              {hasLocations ? (
                                <button
                                  onClick={() => toggleRowExpanded(b.id)}
                                  style={{ background: 'none', border: 'none', cursor: 'pointer', padding: 2 }}
                                  title="Expand storage bin locations"
                                >
                                  {isExpanded ? <ChevronDown size={14} color="#1e56ff" /> : <ChevronRight size={14} color="#94a3b8" />}
                                </button>
                              ) : null}
                            </td>
                            <td>
                              <div style={{ display: 'flex', flexDirection: 'column' }}>
                                <span style={{ fontWeight: 600, color: '#0f172a' }}>{b.product_name}</span>
                                <div style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: 11, color: '#64748b' }}>
                                  <span style={{ fontFamily: 'monospace', fontWeight: 600, color: '#1e56ff' }}>{b.sku}</span>
                                  <span>•</span>
                                  <span>{b.category}</span>
                                  <span>•</span>
                                  <span style={{ textTransform: 'uppercase' }}>{b.base_uom}</span>
                                </div>
                              </div>
                            </td>
                            <td>
                              <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                                <Building2 size={13} color="#64748b" />
                                <span style={{ fontSize: 12, fontWeight: 500 }}>{b.warehouse_name}</span>
                              </div>
                            </td>
                            <td style={{ textAlign: 'right', fontWeight: 600 }}>{b.on_hand.toLocaleString()}</td>
                            <td style={{ textAlign: 'right', color: b.reserved > 0 ? '#b45309' : '#94a3b8' }}>
                              {b.reserved.toLocaleString()}
                            </td>
                            <td style={{ textAlign: 'right', fontWeight: 700, color: b.available > 0 ? '#16a34a' : '#dc2626' }}>
                              {b.available.toLocaleString()}
                            </td>
                            <td style={{ textAlign: 'right', color: b.quarantined > 0 ? '#dc2626' : '#94a3b8' }}>
                              {b.quarantined.toLocaleString()}
                            </td>
                            <td style={{ textAlign: 'right', color: b.in_transit > 0 ? '#2563eb' : '#94a3b8' }}>
                              {b.in_transit.toLocaleString()}
                            </td>
                            <td>
                              {b.available <= 0 ? (
                                <span className="kpi-pill kpi-pill-red" style={{ fontSize: 10 }}>Out of Stock</span>
                              ) : b.available <= 10 ? (
                                <span className="kpi-pill kpi-pill-amber" style={{ fontSize: 10 }}>Low Stock</span>
                              ) : (
                                <span className="kpi-pill kpi-pill-green" style={{ fontSize: 10 }}>In Stock</span>
                              )}
                            </td>
                            <td style={{ textAlign: 'right' }}>
                              <button
                                onClick={() => openSkuDetail(b.id)}
                                className="btn-finovo-secondary"
                                style={{ padding: '4px 10px', fontSize: 11 }}
                                title="View SKU Stock Details"
                              >
                                <Eye size={12} />
                                <span>Inspect</span>
                              </button>
                            </td>
                          </tr>

                          {/* Expanded Physical Storage Bins / Locations Row */}
                          {isExpanded && hasLocations && (
                            <tr style={{ backgroundColor: '#f8fafc' }}>
                              <td colSpan={10} style={{ padding: '12px 24px 16px 44px' }}>
                                <div style={{ fontSize: 12, fontWeight: 700, color: '#334155', marginBottom: 8, display: 'flex', alignItems: 'center', gap: 6 }}>
                                  <Layers size={13} color="#1e56ff" />
                                  <span>Physical Storage Bins & Lots in {b.warehouse_name}</span>
                                </div>
                                <table style={{ width: '100%', fontSize: 11, borderCollapse: 'collapse', backgroundColor: '#ffffff', borderRadius: 8, border: '1px solid #e2e8f0' }}>
                                  <thead>
                                    <tr style={{ borderBottom: '1px solid #e2e8f0', color: '#64748b', textAlign: 'left' }}>
                                      <th style={{ padding: '6px 12px' }}>Bin / Location Code</th>
                                      <th style={{ padding: '6px 12px' }}>Type</th>
                                      <th style={{ padding: '6px 12px' }}>Lot Number</th>
                                      <th style={{ padding: '6px 12px' }}>Condition</th>
                                      <th style={{ padding: '6px 12px', textAlign: 'right' }}>Physical Qty</th>
                                      <th style={{ padding: '6px 12px', textAlign: 'right' }}>Available</th>
                                    </tr>
                                  </thead>
                                  <tbody>
                                    {b.locations?.map((loc) => (
                                      <tr key={loc.location_id} style={{ borderBottom: '1px solid #f1f5f9' }}>
                                        <td style={{ padding: '6px 12px', fontFamily: 'monospace', fontWeight: 600 }}>{loc.location_code}</td>
                                        <td style={{ padding: '6px 12px', textTransform: 'capitalize' }}>{loc.location_type}</td>
                                        <td style={{ padding: '6px 12px' }}>{loc.lot_number || 'Standard (None)'}</td>
                                        <td style={{ padding: '6px 12px' }}>
                                          <span
                                            style={{
                                              padding: '2px 6px',
                                              borderRadius: 4,
                                              fontSize: 10,
                                              fontWeight: 600,
                                              backgroundColor: loc.condition === 'available' ? '#dcfce7' : '#fee2e2',
                                              color: loc.condition === 'available' ? '#166534' : '#991b1b',
                                            }}
                                          >
                                            {loc.condition}
                                          </span>
                                        </td>
                                        <td style={{ padding: '6px 12px', textAlign: 'right', fontWeight: 600 }}>{loc.quantity}</td>
                                        <td style={{ padding: '6px 12px', textAlign: 'right', color: '#16a34a', fontWeight: 700 }}>{loc.available}</td>
                                      </tr>
                                    ))}
                                  </tbody>
                                </table>
                              </td>
                            </tr>
                          )}
                        </React.Fragment>
                      );
                    })
                  )}
                </tbody>
              </table>
            </div>
          </div>
        )}

        {/* ------------------------------------------------------------- */}
        {/* TAB 2: RECEIVING & PUTAWAY */}
        {/* ------------------------------------------------------------- */}
        {activeTab === 'receipts' && (
          <div className="tab-pane-container">
            <div className="tab-content-header" style={{ borderBottom: 'none', padding: 0, marginBottom: 16 }}>
              <div>
                <h3 className="tab-content-header-title">Inbound Shipments & Receiving Dock</h3>
                <p className="tab-content-header-subtitle">
                  Register arriving vendor purchase orders, verify dock counts, and execute directed putaway to storage bins.
                </p>
              </div>
              <button onClick={() => setShowNewReceiptModal(true)} className="btn-finovo-primary" style={{ padding: '8px 14px', fontSize: 12 }}>
                <Plus size={14} />
                <span>Create Inbound Receipt</span>
              </button>
            </div>

            <div className="table-responsive-wrapper">
              <table className="finovo-table">
                <thead>
                  <tr>
                    <th>Receipt Number</th>
                    <th>Warehouse & Dock</th>
                    <th>Supplier / PO Reference</th>
                    <th style={{ textAlign: 'right' }}>Expected</th>
                    <th style={{ textAlign: 'right' }}>Received</th>
                    <th>Status</th>
                    <th>Received By</th>
                    <th>Date</th>
                    <th style={{ textAlign: 'right' }}>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  {receipts.length === 0 ? (
                    <tr>
                      <td colSpan={9} style={{ textAlign: 'center', padding: '32px 16px', color: '#64748b' }}>
                        No inbound receipts found.
                      </td>
                    </tr>
                  ) : (
                    receipts.map((r) => (
                      <tr key={r.id}>
                        <td style={{ fontWeight: 600, fontFamily: 'monospace' }}>{r.receipt_number}</td>
                        <td>
                          <div style={{ display: 'flex', flexDirection: 'column' }}>
                            <span style={{ fontWeight: 500 }}>{r.warehouse_name}</span>
                            <span style={{ fontSize: 11, color: '#64748b' }}>Dock: {r.receiving_location}</span>
                          </div>
                        </td>
                        <td>
                          <div style={{ display: 'flex', flexDirection: 'column' }}>
                            <span>{r.supplier_name || 'Vendor Inbound'}</span>
                            <span style={{ fontSize: 11, color: '#64748b' }}>PO: {r.purchase_order_number || 'N/A'}</span>
                          </div>
                        </td>
                        <td style={{ textAlign: 'right' }}>{r.total_expected}</td>
                        <td style={{ textAlign: 'right', fontWeight: 600 }}>{r.total_received}</td>
                        <td>
                          <span
                            className={`kpi-pill ${
                              r.status === 'completed' ? 'kpi-pill-green' : r.status === 'received' ? 'kpi-pill-blue' : 'kpi-pill-amber'
                            }`}
                            style={{ fontSize: 10, textTransform: 'capitalize' }}
                          >
                            {r.status.replace(/_/g, ' ')}
                          </span>
                        </td>
                        <td style={{ fontSize: 12 }}>{r.received_by || 'Pending'}</td>
                        <td style={{ fontSize: 12, color: '#64748b' }}>{r.created_at}</td>
                        <td style={{ textAlign: 'right' }}>
                          <div style={{ display: 'flex', gap: 6, justifyContent: 'flex-end' }}>
                            {r.status === 'draft' && (
                              <button
                                onClick={() => setShowConfirmReceiptModal(r)}
                                className="btn-finovo-primary"
                                style={{ padding: '4px 8px', fontSize: 11 }}
                              >
                                <Check size={11} />
                                <span>Confirm Dock Receipt</span>
                              </button>
                            )}
                            {r.status === 'received' && (
                              <button
                                onClick={() => {
                                  if (r.items && r.items.length > 0) {
                                    const it = r.items[0];
                                    setShowPutawayModal({
                                      receiptId: r.id,
                                      itemId: it.id,
                                      sku: it.sku,
                                      maxQty: it.received_quantity || it.expected_quantity,
                                    });
                                  }
                                }}
                                className="btn-finovo-secondary"
                                style={{ padding: '4px 8px', fontSize: 11 }}
                              >
                                <Layers size={11} />
                                <span>Putaway to Bins</span>
                              </button>
                            )}
                          </div>
                        </td>
                      </tr>
                    ))
                  )}
                </tbody>
              </table>
            </div>
          </div>
        )}

        {/* ------------------------------------------------------------- */}
        {/* TAB 3: INTER-WAREHOUSE TRANSFERS */}
        {/* ------------------------------------------------------------- */}
        {activeTab === 'transfers' && (
          <div className="tab-pane-container">
            <div className="tab-content-header" style={{ borderBottom: 'none', padding: 0, marginBottom: 16 }}>
              <div>
                <h3 className="tab-content-header-title">Inter-Warehouse Transfers & Transit</h3>
                <p className="tab-content-header-subtitle">
                  Track stock movement between fulfillment hubs with full in-transit visibility and destination shortage discrepancy handling.
                </p>
              </div>
              <button onClick={() => setShowNewTransferModal(true)} className="btn-finovo-primary" style={{ padding: '8px 14px', fontSize: 12 }}>
                <Plus size={14} />
                <span>Create Transfer</span>
              </button>
            </div>

            <div className="table-responsive-wrapper">
              <table className="finovo-table">
                <thead>
                  <tr>
                    <th>Transfer #</th>
                    <th>Product & SKU</th>
                    <th>Source Facility</th>
                    <th>Destination Facility</th>
                    <th style={{ textAlign: 'right' }}>Sent Qty</th>
                    <th style={{ textAlign: 'right' }}>Received Qty</th>
                    <th>Status</th>
                    <th>Dispatched Date</th>
                    <th style={{ textAlign: 'right' }}>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  {transfers.length === 0 ? (
                    <tr>
                      <td colSpan={9} style={{ textAlign: 'center', padding: '32px 16px', color: '#64748b' }}>
                        No warehouse transfers found.
                      </td>
                    </tr>
                  ) : (
                    transfers.map((t) => (
                      <tr key={t.id}>
                        <td style={{ fontFamily: 'monospace', fontWeight: 600 }}>{t.transfer_number}</td>
                        <td>
                          <div style={{ display: 'flex', flexDirection: 'column' }}>
                            <span style={{ fontWeight: 600 }}>{t.product_name}</span>
                            <span style={{ fontSize: 11, color: '#64748b', fontFamily: 'monospace' }}>{t.sku}</span>
                          </div>
                        </td>
                        <td>{t.from_warehouse}</td>
                        <td>{t.to_warehouse}</td>
                        <td style={{ textAlign: 'right', fontWeight: 600 }}>{t.quantity}</td>
                        <td style={{ textAlign: 'right', color: t.discrepancy_quantity > 0 ? '#dc2626' : '#16a34a' }}>
                          {t.received_quantity !== null && t.received_quantity !== undefined ? t.received_quantity : 'In Transit'}
                        </td>
                        <td>
                          <span
                            className={`kpi-pill ${
                              t.status === 'completed' ? 'kpi-pill-green' : t.status === 'in_transit' ? 'kpi-pill-blue' : 'kpi-pill-amber'
                            }`}
                            style={{ fontSize: 10, textTransform: 'capitalize' }}
                          >
                            {t.status.replace(/_/g, ' ')}
                          </span>
                        </td>
                        <td style={{ fontSize: 12, color: '#64748b' }}>{t.dispatched_at || t.created_at}</td>
                        <td style={{ textAlign: 'right' }}>
                          {t.status === 'in_transit' && (
                            <button
                              onClick={() => setShowReceiveTransferModal(t)}
                              className="btn-finovo-primary"
                              style={{ padding: '4px 10px', fontSize: 11 }}
                            >
                              <Check size={12} />
                              <span>Receive at Destination</span>
                            </button>
                          )}
                        </td>
                      </tr>
                    ))
                  )}
                </tbody>
              </table>
            </div>
          </div>
        )}

        {/* ------------------------------------------------------------- */}
        {/* TAB 4: ADJUSTMENTS & APPROVALS */}
        {/* ------------------------------------------------------------- */}
        {activeTab === 'adjustments' && (
          <div className="tab-pane-container">
            <div className="tab-content-header" style={{ borderBottom: 'none', padding: 0, marginBottom: 16 }}>
              <div>
                <h3 className="tab-content-header-title">Stock Adjustments & Separation of Duties</h3>
                <p className="tab-content-header-subtitle">
                  Controlled adjustments for damage, recount, or scrap. Requires dual-authorization when policy enforces approval.
                </p>
              </div>
              <button onClick={() => setShowNewAdjustmentModal(true)} className="btn-finovo-primary" style={{ padding: '8px 14px', fontSize: 12 }}>
                <Plus size={14} />
                <span>New Adjustment Request</span>
              </button>
            </div>

            <div className="table-responsive-wrapper">
              <table className="finovo-table">
                <thead>
                  <tr>
                    <th>Adjustment #</th>
                    <th>Warehouse & Location</th>
                    <th>Product & SKU</th>
                    <th>Type</th>
                    <th style={{ textAlign: 'right' }}>Quantity</th>
                    <th>Reason</th>
                    <th>Status</th>
                    <th>Requested By</th>
                    <th style={{ textAlign: 'right' }}>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  {adjustments.length === 0 ? (
                    <tr>
                      <td colSpan={9} style={{ textAlign: 'center', padding: '32px 16px', color: '#64748b' }}>
                        No stock adjustments found.
                      </td>
                    </tr>
                  ) : (
                    adjustments.map((a) => (
                      <tr key={a.id}>
                        <td style={{ fontFamily: 'monospace', fontWeight: 600 }}>{a.adjustment_number}</td>
                        <td>
                          <div style={{ display: 'flex', flexDirection: 'column' }}>
                            <span>{a.warehouse_name}</span>
                            <span style={{ fontSize: 11, color: '#64748b' }}>{a.location}</span>
                          </div>
                        </td>
                        <td>
                          <div style={{ display: 'flex', flexDirection: 'column' }}>
                            <span style={{ fontWeight: 600 }}>{a.product_name}</span>
                            <span style={{ fontSize: 11, color: '#64748b', fontFamily: 'monospace' }}>{a.sku}</span>
                          </div>
                        </td>
                        <td>
                          <span
                            style={{
                              padding: '2px 6px',
                              borderRadius: 4,
                              fontSize: 10,
                              fontWeight: 600,
                              textTransform: 'uppercase',
                              backgroundColor: a.adjustment_type === 'add' ? '#dcfce7' : a.adjustment_type === 'subtract' ? '#fee2e2' : '#f1f5f9',
                              color: a.adjustment_type === 'add' ? '#166534' : a.adjustment_type === 'subtract' ? '#991b1b' : '#334155',
                            }}
                          >
                            {a.adjustment_type}
                          </span>
                        </td>
                        <td style={{ textAlign: 'right', fontWeight: 700 }}>
                          {a.adjustment_type === 'subtract' ? `-${a.quantity}` : `+${a.quantity}`}
                        </td>
                        <td>{a.reason}</td>
                        <td>
                          <span
                            className={`kpi-pill ${
                              a.status === 'posted' || a.status === 'approved'
                                ? 'kpi-pill-green'
                                : a.status === 'rejected'
                                ? 'kpi-pill-red'
                                : 'kpi-pill-amber'
                            }`}
                            style={{ fontSize: 10, textTransform: 'capitalize' }}
                          >
                            {a.status.replace(/_/g, ' ')}
                          </span>
                        </td>
                        <td style={{ fontSize: 12 }}>{a.requested_by}</td>
                        <td style={{ textAlign: 'right' }}>
                          {a.status === 'pending_approval' && (
                            <div style={{ display: 'flex', gap: 6, justifyContent: 'flex-end' }}>
                              <button
                                onClick={async () => {
                                  try {
                                    await omsService.approveInventoryAdjustment(a.id);
                                    setFeedback({ type: 'success', text: `Adjustment ${a.adjustment_number} approved and posted.` });
                                    fetchAdjustments();
                                    fetchKpis();
                                  } catch (err: any) {
                                    setFeedback({ type: 'error', text: err.message || 'Approval failed.' });
                                  }
                                }}
                                className="btn-finovo-primary"
                                style={{ padding: '3px 8px', fontSize: 11 }}
                              >
                                Approve
                              </button>
                              <button
                                onClick={async () => {
                                  try {
                                    await omsService.rejectInventoryAdjustment(a.id);
                                    setFeedback({ type: 'success', text: `Adjustment ${a.adjustment_number} rejected.` });
                                    fetchAdjustments();
                                  } catch (err: any) {
                                    setFeedback({ type: 'error', text: err.message || 'Rejection failed.' });
                                  }
                                }}
                                className="btn-finovo-secondary"
                                style={{ padding: '3px 8px', fontSize: 11 }}
                              >
                                Reject
                              </button>
                            </div>
                          )}
                        </td>
                      </tr>
                    ))
                  )}
                </tbody>
              </table>
            </div>
          </div>
        )}

        {/* ------------------------------------------------------------- */}
        {/* TAB 5: CYCLE COUNTS & RECONCILIATION */}
        {/* ------------------------------------------------------------- */}
        {activeTab === 'counts' && (
          <div className="tab-pane-container">
            <div className="tab-content-header" style={{ borderBottom: 'none', padding: 0, marginBottom: 16 }}>
              <div>
                <h3 className="tab-content-header-title">Cycle Count Audits & Baseline Freezes</h3>
                <p className="tab-content-header-subtitle">
                  Execute scheduled inventory counts, verify physical quantities against system snapshots, and reconcile variances.
                </p>
              </div>
              <button onClick={() => setShowNewCycleCountModal(true)} className="btn-finovo-primary" style={{ padding: '8px 14px', fontSize: 12 }}>
                <Plus size={14} />
                <span>Start Cycle Count</span>
              </button>
            </div>

            <div className="table-responsive-wrapper">
              <table className="finovo-table">
                <thead>
                  <tr>
                    <th>Count Session</th>
                    <th>Warehouse Facility</th>
                    <th>Scope</th>
                    <th style={{ textAlign: 'right' }}>Total Items</th>
                    <th style={{ textAlign: 'right' }}>Counted</th>
                    <th style={{ textAlign: 'right' }}>Variances</th>
                    <th>Status</th>
                    <th>Created By</th>
                    <th style={{ textAlign: 'right' }}>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  {cycleCounts.length === 0 ? (
                    <tr>
                      <td colSpan={9} style={{ textAlign: 'center', padding: '32px 16px', color: '#64748b' }}>
                        No cycle count sessions found.
                      </td>
                    </tr>
                  ) : (
                    cycleCounts.map((c) => (
                      <tr key={c.id}>
                        <td>
                          <div style={{ display: 'flex', flexDirection: 'column' }}>
                            <span style={{ fontWeight: 600 }}>{c.title}</span>
                            <span style={{ fontSize: 11, color: '#64748b', fontFamily: 'monospace' }}>{c.count_number}</span>
                          </div>
                        </td>
                        <td>{c.warehouse_name}</td>
                        <td style={{ textTransform: 'capitalize' }}>{c.scope_type} Audit</td>
                        <td style={{ textAlign: 'right' }}>{c.total_items}</td>
                        <td style={{ textAlign: 'right' }}>{c.counted_items}</td>
                        <td style={{ textAlign: 'right', fontWeight: 600, color: c.variance_count > 0 ? '#dc2626' : '#16a34a' }}>
                          {c.variance_count}
                        </td>
                        <td>
                          <span
                            className={`kpi-pill ${
                              c.status === 'posted' ? 'kpi-pill-green' : c.status === 'review' ? 'kpi-pill-amber' : 'kpi-pill-blue'
                            }`}
                            style={{ fontSize: 10, textTransform: 'capitalize' }}
                          >
                            {c.status.replace(/_/g, ' ')}
                          </span>
                        </td>
                        <td style={{ fontSize: 12 }}>{c.created_by}</td>
                        <td style={{ textAlign: 'right' }}>
                          {c.status === 'review' && (
                            <button
                              onClick={async () => {
                                try {
                                  await omsService.approveAndPostCycleCount(c.id);
                                  setFeedback({ type: 'success', text: `Cycle count ${c.count_number} approved and posted to ledger.` });
                                  fetchCycleCounts();
                                  fetchKpis();
                                } catch (err: any) {
                                  setFeedback({ type: 'error', text: err.message || 'Post failed.' });
                                }
                              }}
                              className="btn-finovo-primary"
                              style={{ padding: '4px 10px', fontSize: 11 }}
                            >
                              Approve & Post
                            </button>
                          )}
                        </td>
                      </tr>
                    ))
                  )}
                </tbody>
              </table>
            </div>
          </div>
        )}

        {/* ------------------------------------------------------------- */}
        {/* TAB 6: MOVEMENT LEDGER */}
        {/* ------------------------------------------------------------- */}
        {activeTab === 'ledger' && (
          <div>
            <div className="tab-content-header">
              <div>
                <h3 className="tab-content-header-title">Immutable Stock Movement Log</h3>
                <p className="tab-content-header-subtitle">
                  Append-only audit trail recording every validated receipt, transfer, reservation, and count event.
                </p>
              </div>
              <button onClick={fetchMovements} className="btn-finovo-secondary" style={{ padding: '6px 12px', fontSize: 12 }}>
                <RefreshCw size={13} />
                <span>Refresh Ledger</span>
              </button>
            </div>

            <div className="table-responsive-wrapper">
              <table className="finovo-table">
                <thead>
                  <tr>
                    <th>Reference</th>
                    <th>Type</th>
                    <th>Product & SKU</th>
                    <th>Warehouse</th>
                    <th style={{ textAlign: 'right' }}>Quantity</th>
                    <th style={{ textAlign: 'right' }}>Balance Before</th>
                    <th style={{ textAlign: 'right' }}>Balance After</th>
                    <th>Reason / Context</th>
                    <th>Actor</th>
                    <th>Timestamp</th>
                  </tr>
                </thead>
                <tbody>
                  {movements.length === 0 ? (
                    <tr>
                      <td colSpan={10} style={{ textAlign: 'center', padding: '32px 16px', color: '#64748b' }}>
                        No movements recorded yet.
                      </td>
                    </tr>
                  ) : (
                    movements.map((m) => (
                      <tr key={m.id}>
                        <td style={{ fontFamily: 'monospace', fontWeight: 600 }}>{m.reference}</td>
                        <td>
                          <span
                            style={{
                              padding: '2px 8px',
                              borderRadius: 6,
                              fontSize: 10,
                              fontWeight: 600,
                              backgroundColor: '#f1f5f9',
                              color: '#475569',
                            }}
                          >
                            {m.type}
                          </span>
                        </td>
                        <td>
                          <div style={{ display: 'flex', flexDirection: 'column' }}>
                            <span style={{ fontWeight: 600 }}>{m.product}</span>
                            <span style={{ fontSize: 11, color: '#64748b', fontFamily: 'monospace' }}>{m.sku}</span>
                          </div>
                        </td>
                        <td>{m.warehouse}</td>
                        <td style={{ textAlign: 'right', fontWeight: 700, color: m.direction === 'in' ? '#16a34a' : '#dc2626' }}>
                          {m.direction === 'in' ? `+${m.quantity}` : `-${m.quantity}`}
                        </td>
                        <td style={{ textAlign: 'right', color: '#64748b' }}>{m.before}</td>
                        <td style={{ textAlign: 'right', fontWeight: 600 }}>{m.after}</td>
                        <td style={{ fontSize: 12, maxWidth: 220, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                          {m.reason || 'Standard movement'}
                        </td>
                        <td style={{ fontSize: 12 }}>{m.user}</td>
                        <td style={{ fontSize: 12, color: '#64748b' }}>{m.date}</td>
                      </tr>
                    ))
                  )}
                </tbody>
              </table>
            </div>
          </div>
        )}

        {/* ------------------------------------------------------------- */}
        {/* TAB 7: REPLENISHMENT & REORDER */}
        {/* ------------------------------------------------------------- */}
        {activeTab === 'replenishment' && (
          <div className="tab-pane-container">
            <div className="tab-content-header" style={{ borderBottom: 'none', padding: 0, marginBottom: 16 }}>
              <div>
                <h3 className="tab-content-header-title">Automated Replenishment Recommendations</h3>
                <p className="tab-content-header-subtitle">
                  Deterministic reorder recommendations calculated via: Effective Position = (On-Hand + In-Transit - Reserved) vs Reorder Point.
                </p>
              </div>
              <button onClick={() => setShowNewRuleModal(true)} className="btn-finovo-primary" style={{ padding: '8px 14px', fontSize: 12 }}>
                <Plus size={14} />
                <span>Configure Reorder Rule</span>
              </button>
            </div>

            <div className="table-responsive-wrapper">
              <table className="finovo-table">
                <thead>
                  <tr>
                    <th>Product & SKU</th>
                    <th>Warehouse Hub</th>
                    <th style={{ textAlign: 'right' }}>On-Hand</th>
                    <th style={{ textAlign: 'right' }}>Reserved</th>
                    <th style={{ textAlign: 'right' }}>In-Transit</th>
                    <th style={{ textAlign: 'right' }}>Effective Position</th>
                    <th style={{ textAlign: 'right' }}>Reorder Point</th>
                    <th style={{ textAlign: 'right' }}>Suggested Order Qty</th>
                    <th>Preferred Supplier</th>
                    <th>Urgency</th>
                  </tr>
                </thead>
                <tbody>
                  {recommendations.length === 0 ? (
                    <tr>
                      <td colSpan={10} style={{ textAlign: 'center', padding: '32px 16px', color: '#64748b' }}>
                        All stock positions are healthy! No replenishment orders required at this time.
                      </td>
                    </tr>
                  ) : (
                    recommendations.map((rec) => (
                      <tr key={rec.rule_id}>
                        <td>
                          <div style={{ display: 'flex', flexDirection: 'column' }}>
                            <span style={{ fontWeight: 600 }}>{rec.product_name}</span>
                            <span style={{ fontSize: 11, color: '#64748b', fontFamily: 'monospace' }}>{rec.sku}</span>
                          </div>
                        </td>
                        <td>{rec.warehouse_name}</td>
                        <td style={{ textAlign: 'right' }}>{rec.on_hand}</td>
                        <td style={{ textAlign: 'right', color: '#b45309' }}>{rec.reserved}</td>
                        <td style={{ textAlign: 'right', color: '#2563eb' }}>{rec.in_transit}</td>
                        <td style={{ textAlign: 'right', fontWeight: 700, color: rec.effective_position <= 0 ? '#dc2626' : '#b45309' }}>
                          {rec.effective_position}
                        </td>
                        <td style={{ textAlign: 'right' }}>{rec.reorder_point}</td>
                        <td style={{ textAlign: 'right', fontWeight: 700, color: '#16a34a' }}>
                          {rec.suggested_quantity} units
                        </td>
                        <td>{rec.preferred_supplier}</td>
                        <td>
                          <span
                            className={`kpi-pill ${
                              rec.urgency === 'critical' ? 'kpi-pill-red' : rec.urgency === 'high' ? 'kpi-pill-amber' : 'kpi-pill-blue'
                            }`}
                            style={{ fontSize: 10, textTransform: 'uppercase' }}
                          >
                            {rec.urgency}
                          </span>
                        </td>
                      </tr>
                    ))
                  )}
                </tbody>
              </table>
            </div>
          </div>
        )}
      </div>

      {/* ------------------------------------------------------------- */}
      {/* MODAL: SKU STOCK INSPECTOR */}
      {/* ------------------------------------------------------------- */}
      {detailVariantId && (
        <div className="modal-overlay" style={{ zIndex: 9999 }} onClick={() => setDetailVariantId(null)}>
          <div
            className="modal-container"
            style={{ maxWidth: 840, maxHeight: '90vh', overflowY: 'auto', backgroundColor: '#ffffff', padding: 24, borderRadius: 18, border: '1px solid #e2e8f0', boxShadow: '0 25px 50px -12px rgba(15, 23, 42, 0.25)' }}
            onClick={(e) => e.stopPropagation()}
          >
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 }}>
              <div>
                <h3 style={{ margin: 0, fontSize: 18, fontWeight: 700 }}>
                  {skuDetail?.product_name || 'SKU Stock Inspector'}
                </h3>
                <span style={{ fontSize: 12, color: '#1e56ff', fontWeight: 600, fontFamily: 'monospace' }}>
                  {skuDetail?.sku}
                </span>
              </div>
              <button onClick={() => setDetailVariantId(null)} className="btn-finovo-secondary" style={{ padding: 6 }}>
                <X size={16} />
              </button>
            </div>

            {detailLoading ? (
              <div style={{ padding: 40, textAlign: 'center', color: '#64748b' }}>Loading SKU details...</div>
            ) : skuDetail ? (
              <div style={{ display: 'flex', flexDirection: 'column', gap: 20 }}>
                {/* Metrics Row */}
                <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))', gap: 12 }}>
                  <div style={{ padding: 12, borderRadius: 8, backgroundColor: '#f8fafc', border: '1px solid #e2e8f0' }}>
                    <span style={{ fontSize: 11, color: '#64748b' }}>Total On-Hand</span>
                    <h4 style={{ margin: '4px 0 0 0', fontSize: 18, fontWeight: 700 }}>{skuDetail.total_on_hand} units</h4>
                  </div>
                  <div style={{ padding: 12, borderRadius: 8, backgroundColor: '#f8fafc', border: '1px solid #e2e8f0' }}>
                    <span style={{ fontSize: 11, color: '#64748b' }}>Reserved Commitments</span>
                    <h4 style={{ margin: '4px 0 0 0', fontSize: 18, fontWeight: 700, color: '#b45309' }}>
                      {skuDetail.total_reserved} units
                    </h4>
                  </div>
                  <div style={{ padding: 12, borderRadius: 8, backgroundColor: '#f8fafc', border: '1px solid #e2e8f0' }}>
                    <span style={{ fontSize: 11, color: '#64748b' }}>Available to Promise (ATP)</span>
                    <h4 style={{ margin: '4px 0 0 0', fontSize: 18, fontWeight: 700, color: '#16a34a' }}>
                      {skuDetail.total_available} units
                    </h4>
                  </div>
                </div>

                {/* Warehouse Breakdown */}
                <div>
                  <h4 style={{ margin: '0 0 8px 0', fontSize: 13, fontWeight: 700 }}>Warehouse Facility Breakdown</h4>
                  <div className="table-responsive-wrapper">
                    <table style={{ width: '100%', minWidth: 420, fontSize: 12, borderCollapse: 'collapse', border: '1px solid #e2e8f0', borderRadius: 8 }}>
                      <thead>
                        <tr style={{ backgroundColor: '#f8fafc', borderBottom: '1px solid #e2e8f0', textAlign: 'left' }}>
                          <th style={{ padding: 8 }}>Warehouse</th>
                          <th style={{ padding: 8, textAlign: 'right' }}>On-Hand</th>
                          <th style={{ padding: 8, textAlign: 'right' }}>Reserved</th>
                          <th style={{ padding: 8, textAlign: 'right' }}>Available</th>
                          <th style={{ padding: 8, textAlign: 'right' }}>In-Transit</th>
                        </tr>
                      </thead>
                      <tbody>
                        {skuDetail.warehouse_balances.map((wh) => (
                          <tr key={wh.warehouse_id} style={{ borderBottom: '1px solid #f1f5f9' }}>
                            <td style={{ padding: 8, fontWeight: 600 }}>{wh.warehouse_name}</td>
                            <td style={{ padding: 8, textAlign: 'right' }}>{wh.on_hand}</td>
                            <td style={{ padding: 8, textAlign: 'right', color: '#b45309' }}>{wh.reserved}</td>
                            <td style={{ padding: 8, textAlign: 'right', fontWeight: 700, color: '#16a34a' }}>{wh.available}</td>
                            <td style={{ padding: 8, textAlign: 'right', color: '#2563eb' }}>{wh.in_transit}</td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                </div>

                {/* Physical Location Bins & Lots */}
                {skuDetail.location_balances.length > 0 && (
                  <div>
                    <h4 style={{ margin: '0 0 8px 0', fontSize: 13, fontWeight: 700 }}>Physical Storage Bins & Lots</h4>
                    <div className="table-responsive-wrapper">
                      <table style={{ width: '100%', minWidth: 420, fontSize: 12, borderCollapse: 'collapse', border: '1px solid #e2e8f0', borderRadius: 8 }}>
                        <thead>
                          <tr style={{ backgroundColor: '#f8fafc', borderBottom: '1px solid #e2e8f0', textAlign: 'left' }}>
                            <th style={{ padding: 8 }}>Bin Location</th>
                            <th style={{ padding: 8 }}>Type</th>
                            <th style={{ padding: 8 }}>Lot / Batch</th>
                            <th style={{ padding: 8 }}>Condition</th>
                            <th style={{ padding: 8, textAlign: 'right' }}>Quantity</th>
                          </tr>
                        </thead>
                        <tbody>
                          {skuDetail.location_balances.map((loc) => (
                            <tr key={loc.location_id} style={{ borderBottom: '1px solid #f1f5f9' }}>
                              <td style={{ padding: 8, fontFamily: 'monospace', fontWeight: 600 }}>{loc.location_code}</td>
                              <td style={{ padding: 8, textTransform: 'capitalize' }}>{loc.location_type}</td>
                              <td style={{ padding: 8 }}>{loc.lot_number || 'Standard'}</td>
                              <td style={{ padding: 8 }}>{loc.condition}</td>
                              <td style={{ padding: 8, textAlign: 'right', fontWeight: 600 }}>{loc.quantity}</td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </div>
                  </div>
                )}
              </div>
            ) : null}
          </div>
        </div>
      )}

      {/* ------------------------------------------------------------- */}
      {/* MODAL 1: CREATE INBOUND DOCK RECEIPT */}
      {/* ------------------------------------------------------------- */}
      {showNewReceiptModal && (
        <div className="modal-overlay" style={{ zIndex: 9999 }} onClick={() => setShowNewReceiptModal(false)}>
          <div
            className="modal-container"
            style={{ maxWidth: 720, maxHeight: '90vh', overflowY: 'auto', backgroundColor: '#ffffff', padding: 24, borderRadius: 18, border: '1px solid #e2e8f0', boxShadow: '0 25px 50px -12px rgba(15, 23, 42, 0.25)' }}
            onClick={(e) => e.stopPropagation()}
          >
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 }}>
              <div>
                <h3 style={{ margin: 0, fontSize: 18, fontWeight: 700 }}>Create Inbound Dock Receipt</h3>
                <p style={{ margin: '4px 0 0 0', fontSize: 12, color: '#64748b' }}>
                  Register incoming ASN / Purchase Order delivery for dock unloading, quality inspection, and putaway staging.
                </p>
              </div>
              <button onClick={() => setShowNewReceiptModal(false)} className="btn-finovo-secondary" style={{ padding: 6 }}>
                <X size={16} />
              </button>
            </div>

            <form onSubmit={handleCreateReceipt} style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
              <div className="finovo-form-grid-2">
                <div>
                  <label style={{ display: 'block', fontSize: 12, fontWeight: 600, color: '#334155', marginBottom: 4 }}>
                    Receiving Warehouse Hub *
                  </label>
                  <CustomDropdown
                    value={receiptWhId || (warehouses[0] ? String(warehouses[0].id) : '')}
                    onChange={(val) => setReceiptWhId(val)}
                    options={warehouses.map((w) => ({ value: String(w.id), label: `${w.code} - ${w.name}` }))}
                    size="sm"
                  />
                </div>
                <div>
                  <label style={{ display: 'block', fontSize: 12, fontWeight: 600, color: '#334155', marginBottom: 4 }}>
                    Supplier / Vendor Name
                  </label>
                  <input
                    type="text"
                    placeholder="e.g. Apex Industrial Supplies"
                    value={receiptSupplier}
                    onChange={(e) => setReceiptSupplier(e.target.value)}
                    style={{ width: '100%', padding: '8px 12px', borderRadius: 8, border: '1px solid #cbd5e1', fontSize: 13, boxSizing: 'border-box' }}
                  />
                </div>
              </div>

              <div className="finovo-form-grid-2">
                <div>
                  <label style={{ display: 'block', fontSize: 12, fontWeight: 600, color: '#334155', marginBottom: 4 }}>
                    Purchase Order / Tracking #
                  </label>
                  <input
                    type="text"
                    placeholder="e.g. PO-2026-904"
                    value={receiptPo}
                    onChange={(e) => setReceiptPo(e.target.value)}
                    style={{ width: '100%', padding: '8px 12px', borderRadius: 8, border: '1px solid #cbd5e1', fontSize: 13, boxSizing: 'border-box' }}
                  />
                </div>
                <div>
                  <label style={{ display: 'block', fontSize: 12, fontWeight: 600, color: '#334155', marginBottom: 4 }}>
                    Receiving Notes
                  </label>
                  <input
                    type="text"
                    placeholder="e.g. Pallet delivery, dock bay 3"
                    value={receiptNotes}
                    onChange={(e) => setReceiptNotes(e.target.value)}
                    style={{ width: '100%', padding: '8px 12px', borderRadius: 8, border: '1px solid #cbd5e1', fontSize: 13, boxSizing: 'border-box' }}
                  />
                </div>
              </div>

              {/* Items Table */}
              <div>
                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 8 }}>
                  <label style={{ fontSize: 12, fontWeight: 600, color: '#334155' }}>
                    Expected Delivery Line Items ({receiptItems.length})
                  </label>
                  <button
                    type="button"
                    onClick={() => setReceiptItems([...receiptItems, { product_variant_id: 0, expected_quantity: 10 }])}
                    className="btn-finovo-secondary"
                    style={{ padding: '4px 8px', fontSize: 11 }}
                  >
                    <Plus size={12} />
                    <span>Add Item</span>
                  </button>
                </div>

                <div className="table-responsive-wrapper" style={{ border: '1px solid #e2e8f0', borderRadius: 8 }}>
                  <table style={{ width: '100%', minWidth: 460, fontSize: 12, borderCollapse: 'collapse' }}>
                    <thead>
                      <tr style={{ backgroundColor: '#f8fafc', borderBottom: '1px solid #e2e8f0', textAlign: 'left' }}>
                        <th style={{ padding: 8 }}>Product SKU *</th>
                        <th style={{ padding: 8, width: 100, textAlign: 'right' }}>Expected Qty *</th>
                        <th style={{ padding: 8, width: 120 }}>Lot / Batch #</th>
                        <th style={{ padding: 8, width: 120 }}>Expiry Date</th>
                        <th style={{ padding: 8, width: 40 }}></th>
                      </tr>
                    </thead>
                    <tbody>
                      {receiptItems.map((item, idx) => (
                        <tr key={idx} style={{ borderBottom: '1px solid #f1f5f9' }}>
                          <td style={{ padding: 6 }}>
                            <CustomDropdown
                              value={String(item.product_variant_id || (allVariants[0]?.id || ''))}
                              onChange={(val) => {
                                const copy = [...receiptItems];
                                copy[idx].product_variant_id = Number(val);
                                setReceiptItems(copy);
                              }}
                              options={allVariants.map((v) => ({ value: String(v.id), label: v.name }))}
                              size="sm"
                            />
                          </td>
                          <td style={{ padding: 6, textAlign: 'right' }}>
                            <input
                              type="number"
                              min="1"
                              value={item.expected_quantity}
                              onChange={(e) => {
                                const copy = [...receiptItems];
                                copy[idx].expected_quantity = Math.max(1, parseInt(e.target.value) || 1);
                                setReceiptItems(copy);
                              }}
                              style={{ width: '100%', padding: '6px 8px', borderRadius: 6, border: '1px solid #cbd5e1', fontSize: 12, textAlign: 'right', boxSizing: 'border-box' }}
                            />
                          </td>
                          <td style={{ padding: 6 }}>
                            <input
                              type="text"
                              placeholder="LOT-2026-X"
                              value={item.lot_number || ''}
                              onChange={(e) => {
                                const copy = [...receiptItems];
                                copy[idx].lot_number = e.target.value;
                                setReceiptItems(copy);
                              }}
                              style={{ width: '100%', padding: '6px 8px', borderRadius: 6, border: '1px solid #cbd5e1', fontSize: 12, boxSizing: 'border-box' }}
                            />
                          </td>
                          <td style={{ padding: 6 }}>
                            <input
                              type="date"
                              value={item.expiry_date || ''}
                              onChange={(e) => {
                                const copy = [...receiptItems];
                                copy[idx].expiry_date = e.target.value;
                                setReceiptItems(copy);
                              }}
                              style={{ width: '100%', padding: '6px 8px', borderRadius: 6, border: '1px solid #cbd5e1', fontSize: 12, boxSizing: 'border-box' }}
                            />
                          </td>
                          <td style={{ padding: 6, textAlign: 'center' }}>
                            {receiptItems.length > 1 && (
                              <button
                                type="button"
                                onClick={() => setReceiptItems(receiptItems.filter((_, i) => i !== idx))}
                                style={{ background: 'none', border: 'none', cursor: 'pointer', color: '#dc2626', padding: 4 }}
                              >
                                <Trash2 size={14} />
                              </button>
                            )}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </div>

              <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 10, marginTop: 8 }}>
                <button type="button" onClick={() => setShowNewReceiptModal(false)} className="btn-finovo-secondary" style={{ padding: '8px 16px', fontSize: 13 }}>
                  Cancel
                </button>
                <button type="submit" className="btn-finovo-primary" style={{ padding: '8px 16px', fontSize: 13 }}>
                  Save & Register Receipt
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* ------------------------------------------------------------- */}
      {/* MODAL 2: CONFIRM DOCK RECEIPT */}
      {/* ------------------------------------------------------------- */}
      {showConfirmReceiptModal && (
        <div className="modal-overlay" style={{ zIndex: 9999 }} onClick={() => setShowConfirmReceiptModal(null)}>
          <div
            className="modal-container"
            style={{ maxWidth: 740, maxHeight: '90vh', overflowY: 'auto', backgroundColor: '#ffffff', padding: 24, borderRadius: 18, border: '1px solid #e2e8f0', boxShadow: '0 25px 50px -12px rgba(15, 23, 42, 0.25)' }}
            onClick={(e) => e.stopPropagation()}
          >
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 }}>
              <div>
                <h3 style={{ margin: 0, fontSize: 18, fontWeight: 700 }}>
                  Confirm Dock Receipt: {showConfirmReceiptModal.receipt_number}
                </h3>
                <p style={{ margin: '4px 0 0 0', fontSize: 12, color: '#64748b' }}>
                  Facility: <strong>{showConfirmReceiptModal.warehouse_name}</strong> | PO: <strong>{showConfirmReceiptModal.purchase_order_number || 'N/A'}</strong>
                </p>
              </div>
              <button onClick={() => setShowConfirmReceiptModal(null)} className="btn-finovo-secondary" style={{ padding: 6 }}>
                <X size={16} />
              </button>
            </div>

            <form onSubmit={handleConfirmReceipt} style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
              <div style={{ padding: 12, borderRadius: 8, backgroundColor: '#eff6ff', border: '1px solid #bfdbfe', fontSize: 12, color: '#1e40af' }}>
                Stock received at dock will be placed into the warehouse receiving stage. Damaged or quarantined stock will be isolated from fulfillable availability.
              </div>

              <div className="table-responsive-wrapper" style={{ border: '1px solid #e2e8f0', borderRadius: 8 }}>
                <table style={{ width: '100%', minWidth: 560, fontSize: 12, borderCollapse: 'collapse' }}>
                  <thead>
                    <tr style={{ backgroundColor: '#f8fafc', borderBottom: '1px solid #e2e8f0', textAlign: 'left' }}>
                      <th style={{ padding: 8 }}>Product & SKU</th>
                      <th style={{ padding: 8, textAlign: 'right', width: 90 }}>Expected</th>
                      <th style={{ padding: 8, textAlign: 'right', width: 100 }}>Received Qty</th>
                      <th style={{ padding: 8, width: 120 }}>Condition</th>
                      <th style={{ padding: 8, width: 110 }}>Lot / Batch</th>
                      <th style={{ padding: 8, width: 110 }}>Expiry Date</th>
                    </tr>
                  </thead>
                  <tbody>
                    {showConfirmReceiptModal.items?.map((item) => {
                      const row = confirmRows[item.id] || {
                        received_quantity: item.expected_quantity,
                        condition: 'available',
                        lot_number: item.lot_number || '',
                        expiry_date: item.expiry_date || '',
                      };
                      return (
                        <tr key={item.id} style={{ borderBottom: '1px solid #f1f5f9' }}>
                          <td style={{ padding: 8 }}>
                            <div style={{ display: 'flex', flexDirection: 'column' }}>
                              <span style={{ fontWeight: 600 }}>{item.product_name}</span>
                              <span style={{ fontSize: 11, color: '#64748b', fontFamily: 'monospace' }}>{item.sku}</span>
                            </div>
                          </td>
                          <td style={{ padding: 8, textAlign: 'right', color: '#64748b' }}>{item.expected_quantity}</td>
                          <td style={{ padding: 8, textAlign: 'right' }}>
                            <input
                              type="number"
                              min="1"
                              value={row.received_quantity}
                              onChange={(e) => {
                                setConfirmRows({
                                  ...confirmRows,
                                  [item.id]: { ...row, received_quantity: Math.max(1, parseInt(e.target.value) || 1) },
                                });
                              }}
                              style={{ width: '100%', padding: '6px 8px', borderRadius: 6, border: '1px solid #cbd5e1', fontSize: 12, textAlign: 'right', boxSizing: 'border-box' }}
                            />
                          </td>
                          <td style={{ padding: 8 }}>
                            <select
                              value={row.condition}
                              onChange={(e) => {
                                setConfirmRows({
                                  ...confirmRows,
                                  [item.id]: { ...row, condition: e.target.value },
                                });
                              }}
                              style={{ width: '100%', padding: '6px 8px', borderRadius: 6, border: '1px solid #cbd5e1', fontSize: 12, boxSizing: 'border-box' }}
                            >
                              <option value="available">Available</option>
                              <option value="damaged">Damaged</option>
                              <option value="quarantined">Quarantine</option>
                            </select>
                          </td>
                          <td style={{ padding: 8 }}>
                            <input
                              type="text"
                              value={row.lot_number}
                              placeholder="Lot #"
                              onChange={(e) => {
                                setConfirmRows({
                                  ...confirmRows,
                                  [item.id]: { ...row, lot_number: e.target.value },
                                });
                              }}
                              style={{ width: '100%', padding: '6px 8px', borderRadius: 6, border: '1px solid #cbd5e1', fontSize: 12, boxSizing: 'border-box' }}
                            />
                          </td>
                          <td style={{ padding: 8 }}>
                            <input
                              type="date"
                              value={row.expiry_date}
                              onChange={(e) => {
                                setConfirmRows({
                                  ...confirmRows,
                                  [item.id]: { ...row, expiry_date: e.target.value },
                                });
                              }}
                              style={{ width: '100%', padding: '6px 8px', borderRadius: 6, border: '1px solid #cbd5e1', fontSize: 12, boxSizing: 'border-box' }}
                            />
                          </td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>

              <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 10, marginTop: 8 }}>
                <button type="button" onClick={() => setShowConfirmReceiptModal(null)} className="btn-finovo-secondary" style={{ padding: '8px 16px', fontSize: 13 }}>
                  Cancel
                </button>
                <button type="submit" className="btn-finovo-primary" style={{ padding: '8px 16px', fontSize: 13 }}>
                  Confirm Dock Receipt & Post to Ledger
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* ------------------------------------------------------------- */}
      {/* MODAL 3: PUTAWAY TO STORAGE BINS */}
      {/* ------------------------------------------------------------- */}
      {showPutawayModal && (
        <div className="modal-overlay" style={{ zIndex: 9999 }} onClick={() => setShowPutawayModal(null)}>
          <div
            className="modal-container"
            style={{ maxWidth: 480, maxHeight: '90vh', overflowY: 'auto', backgroundColor: '#ffffff', padding: 24, borderRadius: 18, border: '1px solid #e2e8f0', boxShadow: '0 25px 50px -12px rgba(15, 23, 42, 0.25)' }}
            onClick={(e) => e.stopPropagation()}
          >
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 }}>
              <div>
                <h3 style={{ margin: 0, fontSize: 17, fontWeight: 700 }}>Putaway to Storage Bin</h3>
                <p style={{ margin: '4px 0 0 0', fontSize: 12, color: '#64748b' }}>
                  SKU: <strong>{showPutawayModal.sku || 'Selected Item'}</strong> | Max: <strong>{showPutawayModal.maxQty || 1} units</strong>
                </p>
              </div>
              <button onClick={() => setShowPutawayModal(null)} className="btn-finovo-secondary" style={{ padding: 6 }}>
                <X size={16} />
              </button>
            </div>

            <form onSubmit={handlePutaway} style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
              <div>
                <label style={{ display: 'block', fontSize: 12, fontWeight: 600, color: '#334155', marginBottom: 4 }}>
                  Destination Storage Bin Code *
                </label>
                <input
                  type="text"
                  placeholder="e.g. A-01-B-01 or RACK-2-BIN-10"
                  value={putawayLocCode}
                  onChange={(e) => setPutawayLocCode(e.target.value)}
                  required
                  style={{ width: '100%', padding: '8px 12px', borderRadius: 8, border: '1px solid #cbd5e1', fontSize: 13, boxSizing: 'border-box' }}
                />
                <span style={{ fontSize: 11, color: '#64748b', marginTop: 4, display: 'block' }}>
                  Moves stock physically from the dock receiving stage into the storage bin.
                </span>
              </div>

              <div>
                <label style={{ display: 'block', fontSize: 12, fontWeight: 600, color: '#334155', marginBottom: 4 }}>
                  Putaway Quantity *
                </label>
                <input
                  type="number"
                  min="1"
                  max={showPutawayModal.maxQty || 9999}
                  value={putawayQty}
                  onChange={(e) => setPutawayQty(Math.max(1, parseInt(e.target.value) || 1))}
                  required
                  style={{ width: '100%', padding: '8px 12px', borderRadius: 8, border: '1px solid #cbd5e1', fontSize: 13, boxSizing: 'border-box' }}
                />
              </div>

              <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 10, marginTop: 8 }}>
                <button type="button" onClick={() => setShowPutawayModal(null)} className="btn-finovo-secondary" style={{ padding: '8px 16px', fontSize: 13 }}>
                  Cancel
                </button>
                <button type="submit" className="btn-finovo-primary" style={{ padding: '8px 16px', fontSize: 13 }}>
                  Complete Putaway
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* ------------------------------------------------------------- */}
      {/* MODAL 4: INTER-WAREHOUSE TRANSFER */}
      {/* ------------------------------------------------------------- */}
      {showNewTransferModal && (
        <div className="modal-overlay" style={{ zIndex: 9999 }} onClick={() => setShowNewTransferModal(false)}>
          <div
            className="modal-container"
            style={{ maxWidth: 540, maxHeight: '90vh', overflowY: 'auto', backgroundColor: '#ffffff', padding: 24, borderRadius: 18, border: '1px solid #e2e8f0', boxShadow: '0 25px 50px -12px rgba(15, 23, 42, 0.25)' }}
            onClick={(e) => e.stopPropagation()}
          >
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 }}>
              <div>
                <h3 style={{ margin: 0, fontSize: 18, fontWeight: 700 }}>Dispatch Inter-Warehouse Transfer</h3>
                <p style={{ margin: '4px 0 0 0', fontSize: 12, color: '#64748b' }}>
                  Move stock between fulfillment hubs with two-phase in-transit tracking and discrepancy control.
                </p>
              </div>
              <button onClick={() => setShowNewTransferModal(false)} className="btn-finovo-secondary" style={{ padding: 6 }}>
                <X size={16} />
              </button>
            </div>

            <form onSubmit={handleCreateTransfer} style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
              <div className="finovo-form-grid-2">
                <div>
                  <label style={{ display: 'block', fontSize: 12, fontWeight: 600, color: '#334155', marginBottom: 4 }}>
                    Source Warehouse Hub *
                  </label>
                  <CustomDropdown
                    value={transferFromWh || (warehouses[0] ? String(warehouses[0].id) : '')}
                    onChange={(val) => setTransferFromWh(val)}
                    options={warehouses.map((w) => ({ value: String(w.id), label: `${w.code} - ${w.name}` }))}
                    size="sm"
                  />
                </div>
                <div>
                  <label style={{ display: 'block', fontSize: 12, fontWeight: 600, color: '#334155', marginBottom: 4 }}>
                    Destination Warehouse Hub *
                  </label>
                  <CustomDropdown
                    value={transferToWh || (warehouses[1] ? String(warehouses[1].id) : warehouses[0] ? String(warehouses[0].id) : '')}
                    onChange={(val) => setTransferToWh(val)}
                    options={warehouses.map((w) => ({ value: String(w.id), label: `${w.code} - ${w.name}` }))}
                    size="sm"
                  />
                </div>
              </div>

              <div>
                <label style={{ display: 'block', fontSize: 12, fontWeight: 600, color: '#334155', marginBottom: 4 }}>
                  Product SKU to Transfer *
                </label>
                <CustomDropdown
                  value={transferVariantId || (allVariants[0] ? String(allVariants[0].id) : '')}
                  onChange={(val) => setTransferVariantId(val)}
                  options={allVariants.map((v) => ({ value: String(v.id), label: v.name }))}
                  size="sm"
                />
              </div>

              <div className="finovo-form-grid-2">
                <div>
                  <label style={{ display: 'block', fontSize: 12, fontWeight: 600, color: '#334155', marginBottom: 4 }}>
                    Transfer Quantity *
                  </label>
                  <input
                    type="number"
                    min="1"
                    value={transferQty}
                    onChange={(e) => setTransferQty(Math.max(1, parseInt(e.target.value) || 1))}
                    required
                    style={{ width: '100%', padding: '8px 12px', borderRadius: 8, border: '1px solid #cbd5e1', fontSize: 13, boxSizing: 'border-box' }}
                  />
                </div>
                <div style={{ display: 'flex', alignItems: 'center', paddingTop: 20 }}>
                  <label style={{ display: 'flex', alignItems: 'center', gap: 8, cursor: 'pointer', fontSize: 12, fontWeight: 600, color: '#1e56ff' }}>
                    <input
                      type="checkbox"
                      checked={transferInTransit}
                      onChange={(e) => setTransferInTransit(e.target.checked)}
                    />
                    <span>Track Two-Phase In-Transit</span>
                  </label>
                </div>
              </div>

              <div>
                <label style={{ display: 'block', fontSize: 12, fontWeight: 600, color: '#334155', marginBottom: 4 }}>
                  Transfer Reference / Notes
                </label>
                <input
                  type="text"
                  placeholder="e.g. Replenishing regional DC stock"
                  value={transferNotes}
                  onChange={(e) => setTransferNotes(e.target.value)}
                  style={{ width: '100%', padding: '8px 12px', borderRadius: 8, border: '1px solid #cbd5e1', fontSize: 13, boxSizing: 'border-box' }}
                />
              </div>

              <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 10, marginTop: 8 }}>
                <button type="button" onClick={() => setShowNewTransferModal(false)} className="btn-finovo-secondary" style={{ padding: '8px 16px', fontSize: 13 }}>
                  Cancel
                </button>
                <button type="submit" className="btn-finovo-primary" style={{ padding: '8px 16px', fontSize: 13 }}>
                  Dispatch Transfer
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* ------------------------------------------------------------- */}
      {/* MODAL 5: RECEIVE TRANSFER AT DESTINATION */}
      {/* ------------------------------------------------------------- */}
      {showReceiveTransferModal && (
        <div className="modal-overlay" style={{ zIndex: 9999 }} onClick={() => setShowReceiveTransferModal(null)}>
          <div
            className="modal-container"
            style={{ maxWidth: 480, maxHeight: '90vh', overflowY: 'auto', backgroundColor: '#ffffff', padding: 24, borderRadius: 18, border: '1px solid #e2e8f0', boxShadow: '0 25px 50px -12px rgba(15, 23, 42, 0.25)' }}
            onClick={(e) => e.stopPropagation()}
          >
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 }}>
              <div>
                <h3 style={{ margin: 0, fontSize: 17, fontWeight: 700 }}>
                  Receive Transfer: {showReceiveTransferModal.transfer_number}
                </h3>
                <p style={{ margin: '4px 0 0 0', fontSize: 12, color: '#64748b' }}>
                  {showReceiveTransferModal.from_warehouse} &rarr; {showReceiveTransferModal.to_warehouse} | SKU: <strong>{showReceiveTransferModal.sku}</strong>
                </p>
              </div>
              <button onClick={() => setShowReceiveTransferModal(null)} className="btn-finovo-secondary" style={{ padding: 6 }}>
                <X size={16} />
              </button>
            </div>

            <form onSubmit={handleReceiveTransfer} style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
              <div>
                <label style={{ display: 'block', fontSize: 12, fontWeight: 600, color: '#334155', marginBottom: 4 }}>
                  Dispatched Quantity Sent
                </label>
                <div style={{ padding: '8px 12px', borderRadius: 8, backgroundColor: '#f1f5f9', fontWeight: 700, fontSize: 14 }}>
                  {showReceiveTransferModal.quantity} units
                </div>
              </div>

              <div>
                <label style={{ display: 'block', fontSize: 12, fontWeight: 600, color: '#334155', marginBottom: 4 }}>
                  Quantity Actually Received at Destination *
                </label>
                <input
                  type="number"
                  min="0"
                  max={showReceiveTransferModal.quantity}
                  value={receiveTransferQty}
                  onChange={(e) => setReceiveTransferQty(parseInt(e.target.value) || 0)}
                  required
                  style={{ width: '100%', padding: '8px 12px', borderRadius: 8, border: '1px solid #cbd5e1', fontSize: 13, boxSizing: 'border-box' }}
                />
              </div>

              {receiveTransferQty < showReceiveTransferModal.quantity && (
                <div>
                  <label style={{ display: 'block', fontSize: 12, fontWeight: 600, color: '#dc2626', marginBottom: 4 }}>
                    Discrepancy / Shortage Reason *
                  </label>
                  <input
                    type="text"
                    placeholder="e.g. Damaged in transit by carrier"
                    value={receiveTransferReason}
                    onChange={(e) => setReceiveTransferReason(e.target.value)}
                    required
                    style={{ width: '100%', padding: '8px 12px', borderRadius: 8, border: '1px solid #f87171', fontSize: 13, boxSizing: 'border-box' }}
                  />
                </div>
              )}

              <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 10, marginTop: 8 }}>
                <button type="button" onClick={() => setShowReceiveTransferModal(null)} className="btn-finovo-secondary" style={{ padding: '8px 16px', fontSize: 13 }}>
                  Cancel
                </button>
                <button type="submit" className="btn-finovo-primary" style={{ padding: '8px 16px', fontSize: 13 }}>
                  Confirm Received Stock
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* ------------------------------------------------------------- */}
      {/* MODAL 6: STOCK ADJUSTMENT REQUEST */}
      {/* ------------------------------------------------------------- */}
      {showNewAdjustmentModal && (
        <div className="modal-overlay" style={{ zIndex: 9999 }} onClick={() => setShowNewAdjustmentModal(false)}>
          <div
            className="modal-container"
            style={{ maxWidth: 520, maxHeight: '90vh', overflowY: 'auto', backgroundColor: '#ffffff', padding: 24, borderRadius: 18, border: '1px solid #e2e8f0', boxShadow: '0 25px 50px -12px rgba(15, 23, 42, 0.25)' }}
            onClick={(e) => e.stopPropagation()}
          >
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 }}>
              <div>
                <h3 style={{ margin: 0, fontSize: 18, fontWeight: 700 }}>Request Stock Adjustment</h3>
                <p style={{ margin: '4px 0 0 0', fontSize: 12, color: '#64748b' }}>
                  Controlled adjustment with separation of duties and dual-authorization policy enforcement.
                </p>
              </div>
              <button onClick={() => setShowNewAdjustmentModal(false)} className="btn-finovo-secondary" style={{ padding: 6 }}>
                <X size={16} />
              </button>
            </div>

            <form onSubmit={handleCreateAdjustment} style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
              <div>
                <label style={{ display: 'block', fontSize: 12, fontWeight: 600, color: '#334155', marginBottom: 4 }}>
                  Warehouse Facility *
                </label>
                <CustomDropdown
                  value={adjWhId || (warehouses[0] ? String(warehouses[0].id) : '')}
                  onChange={(val) => setAdjWhId(val)}
                  options={warehouses.map((w) => ({ value: String(w.id), label: `${w.code} - ${w.name}` }))}
                  size="sm"
                />
              </div>

              <div>
                <label style={{ display: 'block', fontSize: 12, fontWeight: 600, color: '#334155', marginBottom: 4 }}>
                  Product SKU *
                </label>
                <CustomDropdown
                  value={adjVariantId || (allVariants[0] ? String(allVariants[0].id) : '')}
                  onChange={(val) => setAdjVariantId(val)}
                  options={allVariants.map((v) => ({ value: String(v.id), label: v.name }))}
                  size="sm"
                />
              </div>

              <div className="finovo-form-grid-2">
                <div>
                  <label style={{ display: 'block', fontSize: 12, fontWeight: 600, color: '#334155', marginBottom: 4 }}>
                    Adjustment Type *
                  </label>
                  <select
                    value={adjType}
                    onChange={(e) => setAdjType(e.target.value as any)}
                    style={{ width: '100%', padding: '8px 12px', borderRadius: 8, border: '1px solid #cbd5e1', fontSize: 13, boxSizing: 'border-box' }}
                  >
                    <option value="add">Add Stock (+)</option>
                    <option value="subtract">Deduct Stock (-)</option>
                    <option value="recount">Recount / Set Absolute (=)</option>
                  </select>
                </div>
                <div>
                  <label style={{ display: 'block', fontSize: 12, fontWeight: 600, color: '#334155', marginBottom: 4 }}>
                    Quantity Units *
                  </label>
                  <input
                    type="number"
                    min="1"
                    value={adjQty}
                    onChange={(e) => setAdjQty(Math.max(1, parseInt(e.target.value) || 1))}
                    required
                    style={{ width: '100%', padding: '8px 12px', borderRadius: 8, border: '1px solid #cbd5e1', fontSize: 13, boxSizing: 'border-box' }}
                  />
                </div>
              </div>

              <div>
                <label style={{ display: 'block', fontSize: 12, fontWeight: 600, color: '#334155', marginBottom: 4 }}>
                  Adjustment Reason Category *
                </label>
                <select
                  value={adjReason}
                  onChange={(e) => setAdjReason(e.target.value)}
                  style={{ width: '100%', padding: '8px 12px', borderRadius: 8, border: '1px solid #cbd5e1', fontSize: 13, boxSizing: 'border-box' }}
                >
                  <option value="Cycle Count Variance">Cycle Count Variance</option>
                  <option value="Damaged Stock">Damaged Stock</option>
                  <option value="Expired / Spoilage">Expired / Spoilage</option>
                  <option value="Found Inventory">Found Inventory</option>
                  <option value="Internal Use / Sample">Internal Use / Sample</option>
                  <option value="Scrap / Disposal">Scrap / Disposal</option>
                  <option value="Correction">Data Correction</option>
                </select>
              </div>

              <div>
                <label style={{ display: 'block', fontSize: 12, fontWeight: 600, color: '#334155', marginBottom: 4 }}>
                  Explanation & Notes
                </label>
                <input
                  type="text"
                  placeholder="e.g. Broken box found during aisle scan"
                  value={adjNotes}
                  onChange={(e) => setAdjNotes(e.target.value)}
                  style={{ width: '100%', padding: '8px 12px', borderRadius: 8, border: '1px solid #cbd5e1', fontSize: 13, boxSizing: 'border-box' }}
                />
              </div>

              <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 10, marginTop: 8 }}>
                <button type="button" onClick={() => setShowNewAdjustmentModal(false)} className="btn-finovo-secondary" style={{ padding: '8px 16px', fontSize: 13 }}>
                  Cancel
                </button>
                <button type="submit" className="btn-finovo-primary" style={{ padding: '8px 16px', fontSize: 13 }}>
                  Submit Adjustment
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* ------------------------------------------------------------- */}
      {/* MODAL 7: START CYCLE COUNT SESSION */}
      {/* ------------------------------------------------------------- */}
      {showNewCycleCountModal && (
        <div className="modal-overlay" style={{ zIndex: 9999 }} onClick={() => setShowNewCycleCountModal(false)}>
          <div
            className="modal-container"
            style={{ maxWidth: 520, maxHeight: '90vh', overflowY: 'auto', backgroundColor: '#ffffff', padding: 24, borderRadius: 18, border: '1px solid #e2e8f0', boxShadow: '0 25px 50px -12px rgba(15, 23, 42, 0.25)' }}
            onClick={(e) => e.stopPropagation()}
          >
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 }}>
              <div>
                <h3 style={{ margin: 0, fontSize: 18, fontWeight: 700 }}>Initialize Cycle Count Session</h3>
                <p style={{ margin: '4px 0 0 0', fontSize: 12, color: '#64748b' }}>
                  Freezes an immutable baseline snapshot for variance auditing without halting fulfillment operations.
                </p>
              </div>
              <button onClick={() => setShowNewCycleCountModal(false)} className="btn-finovo-secondary" style={{ padding: 6 }}>
                <X size={16} />
              </button>
            </div>

            <form onSubmit={handleCreateCycleCount} style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
              <div>
                <label style={{ display: 'block', fontSize: 12, fontWeight: 600, color: '#334155', marginBottom: 4 }}>
                  Warehouse Facility *
                </label>
                <CustomDropdown
                  value={countWhId || (warehouses[0] ? String(warehouses[0].id) : '')}
                  onChange={(val) => setCountWhId(val)}
                  options={warehouses.map((w) => ({ value: String(w.id), label: `${w.code} - ${w.name}` }))}
                  size="sm"
                />
              </div>

              <div>
                <label style={{ display: 'block', fontSize: 12, fontWeight: 600, color: '#334155', marginBottom: 4 }}>
                  Session Title *
                </label>
                <input
                  type="text"
                  placeholder="e.g. Q4 High-Velocity Count - Zone A"
                  value={countTitle}
                  onChange={(e) => setCountTitle(e.target.value)}
                  required
                  style={{ width: '100%', padding: '8px 12px', borderRadius: 8, border: '1px solid #cbd5e1', fontSize: 13, boxSizing: 'border-box' }}
                />
              </div>

              <div className="finovo-form-grid-2">
                <div>
                  <label style={{ display: 'block', fontSize: 12, fontWeight: 600, color: '#334155', marginBottom: 4 }}>
                    Audit Scope Type *
                  </label>
                  <select
                    value={countScope}
                    onChange={(e) => setCountScope(e.target.value as any)}
                    style={{ width: '100%', padding: '8px 12px', borderRadius: 8, border: '1px solid #cbd5e1', fontSize: 13, boxSizing: 'border-box' }}
                  >
                    <option value="full">Full Facility Audit</option>
                    <option value="zone">Specific Zone</option>
                    <option value="location">Specific Storage Bins</option>
                  </select>
                </div>
                <div>
                  <label style={{ display: 'block', fontSize: 12, fontWeight: 600, color: '#334155', marginBottom: 4 }}>
                    Scope Filter Value
                  </label>
                  <input
                    type="text"
                    placeholder="e.g. Zone A or Aisle 1-4"
                    value={countScopeVal}
                    onChange={(e) => setCountScopeVal(e.target.value)}
                    style={{ width: '100%', padding: '8px 12px', borderRadius: 8, border: '1px solid #cbd5e1', fontSize: 13, boxSizing: 'border-box' }}
                  />
                </div>
              </div>

              <div>
                <label style={{ display: 'block', fontSize: 12, fontWeight: 600, color: '#334155', marginBottom: 4 }}>
                  Session Instructions / Notes
                </label>
                <input
                  type="text"
                  placeholder="e.g. Two-person blind count protocol required"
                  value={countNotes}
                  onChange={(e) => setCountNotes(e.target.value)}
                  style={{ width: '100%', padding: '8px 12px', borderRadius: 8, border: '1px solid #cbd5e1', fontSize: 13, boxSizing: 'border-box' }}
                />
              </div>

              <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 10, marginTop: 8 }}>
                <button type="button" onClick={() => setShowNewCycleCountModal(false)} className="btn-finovo-secondary" style={{ padding: '8px 16px', fontSize: 13 }}>
                  Cancel
                </button>
                <button type="submit" className="btn-finovo-primary" style={{ padding: '8px 16px', fontSize: 13 }}>
                  Initialize Session
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* ------------------------------------------------------------- */}
      {/* MODAL 8: CONFIGURE REPLENISHMENT RULE */}
      {/* ------------------------------------------------------------- */}
      {showNewRuleModal && (
        <div className="modal-overlay" style={{ zIndex: 9999 }} onClick={() => setShowNewRuleModal(false)}>
          <div
            className="modal-container"
            style={{ maxWidth: 520, maxHeight: '90vh', overflowY: 'auto', backgroundColor: '#ffffff', padding: 24, borderRadius: 18, border: '1px solid #e2e8f0', boxShadow: '0 25px 50px -12px rgba(15, 23, 42, 0.25)' }}
            onClick={(e) => e.stopPropagation()}
          >
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 }}>
              <div>
                <h3 style={{ margin: 0, fontSize: 18, fontWeight: 700 }}>Configure Reorder Rule</h3>
                <p style={{ margin: '4px 0 0 0', fontSize: 12, color: '#64748b' }}>
                  Define automated reorder thresholds to prevent stockouts and optimize working capital.
                </p>
              </div>
              <button onClick={() => setShowNewRuleModal(false)} className="btn-finovo-secondary" style={{ padding: 6 }}>
                <X size={16} />
              </button>
            </div>

            <form onSubmit={handleSaveRule} style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
              <div>
                <label style={{ display: 'block', fontSize: 12, fontWeight: 600, color: '#334155', marginBottom: 4 }}>
                  Warehouse Hub *
                </label>
                <CustomDropdown
                  value={ruleWhId || (warehouses[0] ? String(warehouses[0].id) : '')}
                  onChange={(val) => setRuleWhId(val)}
                  options={warehouses.map((w) => ({ value: String(w.id), label: `${w.code} - ${w.name}` }))}
                  size="sm"
                />
              </div>

              <div>
                <label style={{ display: 'block', fontSize: 12, fontWeight: 600, color: '#334155', marginBottom: 4 }}>
                  Product SKU *
                </label>
                <CustomDropdown
                  value={ruleVariantId || (allVariants[0] ? String(allVariants[0].id) : '')}
                  onChange={(val) => setRuleVariantId(val)}
                  options={allVariants.map((v) => ({ value: String(v.id), label: v.name }))}
                  size="sm"
                />
              </div>

              <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(130px, 1fr))', gap: 10 }}>
                <div>
                  <label style={{ display: 'block', fontSize: 12, fontWeight: 600, color: '#334155', marginBottom: 4 }}>
                    Reorder Point *
                  </label>
                  <input
                    type="number"
                    min="0"
                    value={ruleRop}
                    onChange={(e) => setRuleRop(parseInt(e.target.value) || 0)}
                    required
                    style={{ width: '100%', padding: '8px 10px', borderRadius: 8, border: '1px solid #cbd5e1', fontSize: 13, boxSizing: 'border-box' }}
                  />
                </div>
                <div>
                  <label style={{ display: 'block', fontSize: 12, fontWeight: 600, color: '#334155', marginBottom: 4 }}>
                    Safety Stock *
                  </label>
                  <input
                    type="number"
                    min="0"
                    value={ruleSafety}
                    onChange={(e) => setRuleSafety(parseInt(e.target.value) || 0)}
                    required
                    style={{ width: '100%', padding: '8px 10px', borderRadius: 8, border: '1px solid #cbd5e1', fontSize: 13, boxSizing: 'border-box' }}
                  />
                </div>
                <div>
                  <label style={{ display: 'block', fontSize: 12, fontWeight: 600, color: '#334155', marginBottom: 4 }}>
                    Order Lot Size (EOQ) *
                  </label>
                  <input
                    type="number"
                    min="1"
                    value={ruleEoq}
                    onChange={(e) => setRuleEoq(Math.max(1, parseInt(e.target.value) || 1))}
                    required
                    style={{ width: '100%', padding: '8px 10px', borderRadius: 8, border: '1px solid #cbd5e1', fontSize: 13, boxSizing: 'border-box' }}
                  />
                </div>
              </div>

              <div className="finovo-form-grid-2">
                <div>
                  <label style={{ display: 'block', fontSize: 12, fontWeight: 600, color: '#334155', marginBottom: 4 }}>
                    Preferred Supplier
                  </label>
                  <input
                    type="text"
                    placeholder="e.g. Apex Industrial Supplies"
                    value={ruleSupplier}
                    onChange={(e) => setRuleSupplier(e.target.value)}
                    style={{ width: '100%', padding: '8px 12px', borderRadius: 8, border: '1px solid #cbd5e1', fontSize: 13, boxSizing: 'border-box' }}
                  />
                </div>
                <div>
                  <label style={{ display: 'block', fontSize: 12, fontWeight: 600, color: '#334155', marginBottom: 4 }}>
                    Lead Time (Days)
                  </label>
                  <input
                    type="number"
                    min="1"
                    value={ruleLeadDays}
                    onChange={(e) => setRuleLeadDays(Math.max(1, parseInt(e.target.value) || 1))}
                    style={{ width: '100%', padding: '8px 12px', borderRadius: 8, border: '1px solid #cbd5e1', fontSize: 13, boxSizing: 'border-box' }}
                  />
                </div>
              </div>

              <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 10, marginTop: 8 }}>
                <button type="button" onClick={() => setShowNewRuleModal(false)} className="btn-finovo-secondary" style={{ padding: '8px 16px', fontSize: 13 }}>
                  Cancel
                </button>
                <button type="submit" className="btn-finovo-primary" style={{ padding: '8px 16px', fontSize: 13 }}>
                  Save Rule
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
};

export default InventoryPage;
