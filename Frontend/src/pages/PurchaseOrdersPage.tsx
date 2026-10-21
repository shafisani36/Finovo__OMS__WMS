import React, { useState, useEffect, useMemo } from 'react';
import { useLocation } from 'react-router-dom';
import {
  FileSpreadsheet,
  PackageCheck,
  ClipboardCheck,
  CheckCircle2,
  AlertCircle,
  Plus,
  Search,
  Download,
  Truck,
  ShieldAlert,
  Building,
  FileDown,
  Eye,
  X,
  Boxes,
  Loader2,
  Layers,
  UserCheck
} from 'lucide-react';
import { useAuth } from '../hooks/useAuth';
import omsService from '../services/omsService';
import CustomDropdown from '../components/common/CustomDropdown';
import MetricCard from '../components/common/MetricCard';
import InfoCalloutCard from '../components/common/InfoCalloutCard';

interface Warehouse {
  id: number;
  name: string;
  code: string;
}

export const PurchaseOrdersPage: React.FC = () => {
  const { user } = useAuth();
  const location = useLocation();

  // Active Main Tab: 'po' | 'pr' | 'grn'
  const [activeTab, setActiveTab] = useState<'po' | 'pr' | 'grn'>(() => {
    if (location.pathname.includes('purchase-requisition')) return 'pr';
    if (location.pathname.includes('goods-received-note')) return 'grn';
    return 'po';
  });

  useEffect(() => {
    if (location.pathname.includes('purchase-requisition')) setActiveTab('pr');
    else if (location.pathname.includes('goods-received-note')) setActiveTab('grn');
  }, [location.pathname]);

  const [isLoading, setIsLoading] = useState(false);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [errorBanner, setErrorBanner] = useState<string | null>(null);
  const [successBanner, setSuccessBanner] = useState<string | null>(null);

  // Reference Data
  const [warehouses, setWarehouses] = useState<Warehouse[]>([]);
  const [products, setProducts] = useState<any[]>([]);

  // Purchase Orders State
  const [purchaseOrders, setPurchaseOrders] = useState<any[]>([]);
  const [poKpis, setPoKpis] = useState<any>({});
  const [poSearch, setPoSearch] = useState('');
  const [poStatusFilter, setPoStatusFilter] = useState('all');

  // Purchase Requisitions State
  const [requisitions, setRequisitions] = useState<any[]>([]);
  const [prKpis, setPrKpis] = useState<any>({});
  const [prSearch, setPrSearch] = useState('');
  const [prStatusFilter, setPrStatusFilter] = useState('all');

  // Goods Received Notes State
  const [grns, setGrns] = useState<any[]>([]);
  const [grnKpis, setGrnKpis] = useState<any>({});
  const [grnSearch, setGrnSearch] = useState('');
  const [grnStatusFilter, setGrnStatusFilter] = useState('all');

  // Modals
  const [showCreatePrModal, setShowCreatePrModal] = useState(false);
  const [showViewPrModal, setShowViewPrModal] = useState(false);
  const [selectedPr, setSelectedPr] = useState<any | null>(null);
  const [showConvertPoModal, setShowConvertPoModal] = useState(false);
  const [showRejectPrModal, setShowRejectPrModal] = useState(false);
  const [rejectionReason, setRejectionReason] = useState('');

  const [showCreatePoModal, setShowCreatePoModal] = useState(false);
  const [showViewPoModal, setShowViewPoModal] = useState(false);
  const [selectedPo, setSelectedPo] = useState<any | null>(null);

  const [showCreateGrnModal, setShowCreateGrnModal] = useState(false);
  const [showViewGrnModal, setShowViewGrnModal] = useState(false);
  const [showInspectGrnModal, setShowInspectGrnModal] = useState(false);
  const [showSelectPoForGrnModal, setShowSelectPoForGrnModal] = useState(false);
  const [selectedGrn, setSelectedGrn] = useState<any | null>(null);

  // Stock planning cache for PR creation: variantId -> context
  const [stockPlanning, setStockPlanning] = useState<Record<number, any>>({});

  // -------------------------------------------------------------
  // Data Loading
  // -------------------------------------------------------------
  const loadInitialData = async () => {
    try {
      const [whRes, prodRes] = await Promise.all([
        omsService.getWarehouses(),
        omsService.getProducts({ per_page: 100 }),
      ]);
      setWarehouses(whRes.data || []);
      setProducts(prodRes.data || []);
    } catch (e) {
      console.error('Failed to load reference data', e);
    }
  };

  const fetchPurchaseOrders = async () => {
    setIsLoading(true);
    try {
      const params: any = { search: poSearch };
      if (poStatusFilter !== 'all') params.status = poStatusFilter;
      const res = await omsService.getPurchaseOrders(params);
      setPurchaseOrders(res.data || []);
      setPoKpis(res.kpis || {});
    } catch (e: any) {
      setErrorBanner(e?.message || 'Failed to fetch purchase orders.');
    } finally {
      setIsLoading(false);
    }
  };

  const fetchRequisitions = async () => {
    setIsLoading(true);
    try {
      const params: any = { search: prSearch };
      if (prStatusFilter !== 'all') params.status = prStatusFilter;
      const res = await omsService.getPurchaseRequisitions(params);
      setRequisitions(res.data || []);
      setPrKpis(res.kpis || {});
    } catch (e: any) {
      setErrorBanner(e?.message || 'Failed to fetch purchase requisitions.');
    } finally {
      setIsLoading(false);
    }
  };

  const fetchGrns = async () => {
    setIsLoading(true);
    try {
      const params: any = { search: grnSearch };
      if (grnStatusFilter !== 'all') params.status = grnStatusFilter;
      const res = await omsService.getGoodsReceivedNotes(params);
      setGrns(res.data || []);
      setGrnKpis(res.kpis || {});
    } catch (e: any) {
      setErrorBanner(e?.message || 'Failed to fetch goods received notes.');
    } finally {
      setIsLoading(false);
    }
  };

  useEffect(() => {
    loadInitialData();
  }, []);

  useEffect(() => {
    if (activeTab === 'po') fetchPurchaseOrders();
    else if (activeTab === 'pr') fetchRequisitions();
    else if (activeTab === 'grn') fetchGrns();
  }, [activeTab, poStatusFilter, prStatusFilter, grnStatusFilter]);

  const showNotification = (msg: string, isErr = false) => {
    if (isErr) {
      setErrorBanner(msg);
      setSuccessBanner(null);
    } else {
      setSuccessBanner(msg);
      setErrorBanner(null);
    }
    setTimeout(() => {
      setErrorBanner(null);
      setSuccessBanner(null);
    }, 6000);
  };

  // Flatten available variants for picker
  const allVariants = useMemo(() => {
    const list: Array<{ id: number; sku: string; name: string; price: number; uom: string }> = [];
    products.forEach((p) => {
      if (p.variants && p.variants.length > 0) {
        p.variants.forEach((v: any) => {
          list.push({
            id: v.id,
            sku: v.sku,
            name: v.option_value ? `${p.name} - ${v.option_value}` : p.name,
            price: Number(v.cost_price || v.cost || v.price || 50),
            uom: p.base_uom || 'pcs',
          });
        });
      } else {
        list.push({
          id: p.id,
          sku: p.sku || 'SKU',
          name: p.name,
          price: Number(p.price || 50),
          uom: p.base_uom || 'pcs',
        });
      }
    });
    return list;
  }, [products]);

  // Product name on top and SKU underneath, so long catalog names stay readable in the picker.
  const variantOptions = useMemo(
    () => allVariants.map((v) => ({ value: String(v.id), label: v.name, description: v.sku })),
    [allVariants],
  );

  // -------------------------------------------------------------
  // Form State: Create Requisition
  // -------------------------------------------------------------
  const [prWarehouseId, setPrWarehouseId] = useState<number>(0);
  const [prPriority, setPrPriority] = useState<'low' | 'normal' | 'high' | 'urgent'>('normal');
  const [prDepartment, setPrDepartment] = useState('Logistics & Inventory');
  const [prRequiredBy, setPrRequiredBy] = useState('');
  const [prPurpose, setPrPurpose] = useState('');
  const [prJustification, setPrJustification] = useState('');
  const [prItems, setPrItems] = useState<Array<{
    variant_id: number;
    sku: string;
    name: string;
    qty: number;
    unit_price: number;
    uom: string;
  }>>([]);

  const openCreatePrModal = () => {
    if (warehouses.length > 0 && prWarehouseId === 0) {
      setPrWarehouseId(warehouses[0].id);
    }
    setPrItems([]);
    setPrPurpose('');
    setPrJustification('');
    setShowCreatePrModal(true);
  };

  const addPrItem = (variantId: number) => {
    const v = allVariants.find((x) => x.id === variantId);
    if (!v) return;
    if (prItems.some((x) => x.variant_id === variantId)) return;

    setPrItems((prev) => [
      ...prev,
      {
        variant_id: v.id,
        sku: v.sku,
        name: v.name,
        qty: 10,
        unit_price: v.price,
        uom: v.uom,
      },
    ]);

    // Fetch real-time stock planning context
    if (prWarehouseId) {
      omsService.getStockPlanningContext(prWarehouseId, v.id).then((res) => {
        if (res.data) {
          setStockPlanning((prev) => ({ ...prev, [v.id]: res.data }));
        }
      }).catch(() => {});
    }
  };

  const handleSaveRequisition = async (submitNow: boolean) => {
    if (!prWarehouseId) {
      showNotification('Please select a target warehouse.', true);
      return;
    }
    if (prItems.length === 0) {
      showNotification('Please add at least one line item to the requisition.', true);
      return;
    }

    setIsSubmitting(true);
    try {
      const payload = {
        warehouse_id: prWarehouseId,
        department: prDepartment,
        priority: prPriority,
        required_by_date: prRequiredBy || undefined,
        purpose: prPurpose,
        justification: prJustification,
        currency: 'USD',
        tax_rate: 0,
        items: prItems.map((item) => ({
          product_variant_id: item.variant_id,
          sku: item.sku,
          product_name: item.name,
          requested_quantity: item.qty,
          estimated_unit_price: item.unit_price,
          uom: item.uom,
        })),
      };

      const res = await omsService.createPurchaseRequisition(payload);
      const prId = res.data?.id;

      if (submitNow && prId) {
        await omsService.submitPurchaseRequisition(prId, 'Submitted upon creation.');
        showNotification(`Requisition #${res.data.pr_number} created and submitted for authorization!`);
      } else {
        showNotification(`Draft Requisition #${res.data?.pr_number || ''} saved successfully!`);
      }

      setShowCreatePrModal(false);
      fetchRequisitions();
    } catch (e: any) {
      showNotification(e?.message || 'Failed to save requisition.', true);
    } finally {
      setIsSubmitting(false);
    }
  };

  // -------------------------------------------------------------
  // PR Lifecycle Actions
  // -------------------------------------------------------------
  const handleViewPr = async (id: number) => {
    try {
      const res = await omsService.getPurchaseRequisition(id);
      setSelectedPr(res.data);
      setShowViewPrModal(true);
    } catch (e: any) {
      showNotification(e?.message || 'Failed to load requisition details.', true);
    }
  };

  const handleSubmitPr = async (id: number) => {
    try {
      await omsService.submitPurchaseRequisition(id);
      showNotification('Requisition submitted for management approval.');
      fetchRequisitions();
      if (selectedPr && selectedPr.id === id) {
        handleViewPr(id);
      }
    } catch (e: any) {
      showNotification(e?.message || 'Failed to submit requisition.', true);
    }
  };

  const handleApprovePr = async (pr: any) => {
    if (pr.requester_id === user?.id) {
      showNotification(
        'Separation of Duties Violation: You cannot approve your own purchase requisition. An independent manager must review and approve.',
        true
      );
      return;
    }

    try {
      await omsService.approvePurchaseRequisition(pr.id, undefined, 'Approved for procurement.');
      showNotification(`Requisition #${pr.pr_number} approved successfully!`);
      fetchRequisitions();
      if (selectedPr && selectedPr.id === pr.id) {
        handleViewPr(pr.id);
      }
    } catch (e: any) {
      showNotification(e?.message || 'Failed to approve requisition.', true);
    }
  };

  const handleRejectPrConfirm = async () => {
    if (!selectedPr || !rejectionReason.trim()) {
      showNotification('Please enter a rejection reason.', true);
      return;
    }

    try {
      await omsService.rejectPurchaseRequisition(selectedPr.id, rejectionReason.trim());
      showNotification(`Requisition #${selectedPr.pr_number} has been rejected.`);
      setShowRejectPrModal(false);
      setRejectionReason('');
      setShowViewPrModal(false);
      fetchRequisitions();
    } catch (e: any) {
      showNotification(e?.message || 'Failed to reject requisition.', true);
    }
  };

  // -------------------------------------------------------------
  // PR to PO Conversion State
  // -------------------------------------------------------------
  const [poSupplierName, setPoSupplierName] = useState('Global Tech Distribution Ltd');
  const [poPaymentTerms, setPoPaymentTerms] = useState('Net 30');
  const [convertItemQuantities, setConvertItemQuantities] = useState<Record<number, number>>({});

  const openConvertPoModal = (pr: any) => {
    setSelectedPr(pr);
    const initialMap: Record<number, number> = {};
    (pr.items || []).forEach((item: any) => {
      initialMap[item.id] = item.remaining_quantity !== undefined ? item.remaining_quantity : (item.approved_quantity || item.requested_quantity);
    });
    setConvertItemQuantities(initialMap);
    setShowConvertPoModal(true);
  };

  const handleExecuteConvertPo = async () => {
    if (!selectedPr) return;
    if (!poSupplierName.trim()) {
      showNotification('Please provide a supplier name.', true);
      return;
    }

    const itemsPayload = Object.entries(convertItemQuantities)
      .map(([itemId, qty]) => ({
        purchase_requisition_item_id: Number(itemId),
        ordered_quantity: Number(qty),
      }))
      .filter((x) => x.ordered_quantity > 0);

    if (itemsPayload.length === 0) {
      showNotification('Please order at least 1 unit.', true);
      return;
    }

    setIsSubmitting(true);
    try {
      const res = await omsService.convertPrToPo(selectedPr.id, {
        supplier_name: poSupplierName,
        payment_terms: poPaymentTerms,
        items: itemsPayload,
      });

      showNotification(`Purchase Order #${res.data?.po_number || ''} created successfully from PR!`);
      setShowConvertPoModal(false);
      setShowViewPrModal(false);
      fetchRequisitions();
      fetchPurchaseOrders();
      setActiveTab('po');
    } catch (e: any) {
      showNotification(e?.message || 'Failed to convert PR to Purchase Order.', true);
    } finally {
      setIsSubmitting(false);
    }
  };

  // -------------------------------------------------------------
  // PO Management & Receiving Trigger
  // -------------------------------------------------------------
  const [poSupplier, setPoSupplier] = useState('');
  const [poWhId, setPoWhId] = useState<number>(0);
  const [poStandaloneItems, setPoStandaloneItems] = useState<Array<{
    variant_id: number;
    sku: string;
    name: string;
    qty: number;
    unit_cost: number;
    uom: string;
  }>>([]);

  const openCreatePoModal = () => {
    if (warehouses.length > 0 && poWhId === 0) {
      setPoWhId(warehouses[0].id);
    }
    setPoSupplier('');
    setPoStandaloneItems([]);
    setShowCreatePoModal(true);
  };

  const handleCreatePo = async () => {
    if (!poSupplier.trim() || !poWhId || poStandaloneItems.length === 0) {
      showNotification('Please fill in all required fields and add at least one line item.', true);
      return;
    }

    setIsSubmitting(true);
    try {
      const payload = {
        supplier_name: poSupplier,
        warehouse_id: poWhId,
        items: poStandaloneItems.map((item) => ({
          product_variant_id: item.variant_id,
          sku: item.sku,
          product_name: item.name,
          ordered_quantity: item.qty,
          unit_cost: item.unit_cost,
          uom: item.uom,
        })),
      };

      const res = await omsService.createPurchaseOrder(payload);
      showNotification(`Purchase Order #${res.data?.po_number || ''} issued successfully!`);
      setShowCreatePoModal(false);
      fetchPurchaseOrders();
    } catch (e: any) {
      showNotification(e?.message || 'Failed to create PO.', true);
    } finally {
      setIsSubmitting(false);
    }
  };

  const handleViewPo = async (id: number) => {
    try {
      const res = await omsService.getPurchaseOrder(id);
      setSelectedPo(res.data);
      setShowViewPoModal(true);
    } catch (e: any) {
      showNotification(e?.message || 'Failed to load PO details.', true);
    }
  };

  // -------------------------------------------------------------
  // GRN Creation & Receiving Workflow
  // -------------------------------------------------------------
  const [grnDeliveryNote, setGrnDeliveryNote] = useState('');
  const [grnCarrier, setGrnCarrier] = useState('FedEx Ground');
  const [grnVehicle, setGrnVehicle] = useState('');
  const [grnAllowOverReceipt, setGrnAllowOverReceipt] = useState(false);
  const [grnItemDistributions, setGrnItemDistributions] = useState<Record<number, {
    received: number;
    accepted: number;
    damaged: number;
    quarantined: number;
    rejected: number;
    lot_number: string;
    expiry_date: string;
  }>>({});

  const openCreateGrnFromPo = (po: any) => {
    setSelectedPo(po);
    setGrnDeliveryNote(`DN-${Math.floor(100000 + Math.random() * 900000)}`);
    setGrnCarrier('FedEx Ground');
    setGrnVehicle('');
    setGrnAllowOverReceipt(false);

    const initialMap: Record<number, any> = {};
    (po.items || []).forEach((item: any) => {
      const outstanding = Math.max(0, item.ordered_quantity - (item.received_quantity || 0));
      initialMap[item.id] = {
        received: outstanding,
        accepted: outstanding,
        damaged: 0,
        quarantined: 0,
        rejected: 0,
        lot_number: `LOT-${new Date().getFullYear()}-${Math.floor(100 + Math.random() * 900)}`,
        expiry_date: '',
      };
    });
    setGrnItemDistributions(initialMap);
    setShowCreateGrnModal(true);
  };

  const handleOpenReceiveGrnFromTop = () => {
    const openOrders = purchaseOrders.filter(
      (po) => po.status !== 'received' && po.status !== 'cancelled'
    );
    if (openOrders.length === 0) {
      if (purchaseOrders.length === 0) {
        showNotification('No Purchase Orders found. Please issue a Purchase Order first.', true);
      } else {
        showNotification('All existing Purchase Orders are already fully received or cancelled.', true);
      }
      return;
    }
    if (openOrders.length === 1) {
      openCreateGrnFromPo(openOrders[0]);
    } else {
      setShowSelectPoForGrnModal(true);
    }
  };

  const handleSaveGrn = async () => {
    if (!selectedPo) return;

    setIsSubmitting(true);
    try {
      const itemsPayload = Object.entries(grnItemDistributions).map(([poiId, dist]) => ({
        purchase_order_item_id: Number(poiId),
        received_quantity: Number(dist.received),
        accepted_quantity: Number(dist.accepted),
        damaged_quantity: Number(dist.damaged),
        quarantined_quantity: Number(dist.quarantined),
        rejected_quantity: Number(dist.rejected),
        lot_number: dist.lot_number || undefined,
        expiry_date: dist.expiry_date || undefined,
      }));

      const payload = {
        purchase_order_id: selectedPo.id,
        supplier_delivery_note: grnDeliveryNote,
        carrier: grnCarrier,
        tracking_or_vehicle_number: grnVehicle,
        allow_over_receipt: grnAllowOverReceipt,
        items: itemsPayload,
      };

      const res = await omsService.createGoodsReceivedNote(payload);
      showNotification(`Goods Received Note #${res.data?.grn_number || ''} recorded at dock!`);
      setShowCreateGrnModal(false);
      fetchGrns();
      fetchPurchaseOrders();
      setActiveTab('grn');
    } catch (e: any) {
      showNotification(e?.message || 'Failed to create GRN.', true);
    } finally {
      setIsSubmitting(false);
    }
  };

  // -------------------------------------------------------------
  // Post GRN to Authoritative Inventory
  // -------------------------------------------------------------
  const handlePostGrnToInventory = async (grn: any) => {
    if (grn.status === 'posted') {
      showNotification('GRN is already posted to inventory.', true);
      return;
    }

    setIsSubmitting(true);
    try {
      const res = await omsService.postGrnToInventory(grn.id);
      showNotification(`GRN #${res.data?.grn_number || ''} successfully posted! Stock balances and ledger updated.`);
      fetchGrns();
      fetchPurchaseOrders();
      if (selectedGrn && selectedGrn.id === grn.id) {
        handleViewGrn(grn.id);
      }
    } catch (e: any) {
      showNotification(e?.message || 'Failed to post GRN to inventory.', true);
    } finally {
      setIsSubmitting(false);
    }
  };

  const handleViewGrn = async (id: number) => {
    try {
      const res = await omsService.getGoodsReceivedNote(id);
      setSelectedGrn(res.data);
      setShowViewGrnModal(true);
    } catch (e: any) {
      showNotification(e?.message || 'Failed to load GRN details.', true);
    }
  };

  // Inspect GRN State
  const [inspectItems, setInspectItems] = useState<any[]>([]);
  const openInspectGrn = (grn: any) => {
    setSelectedGrn(grn);
    setInspectItems((grn.items || []).map((x: any) => ({ ...x })));
    setShowInspectGrnModal(true);
  };

  const handleSaveInspection = async () => {
    if (!selectedGrn) return;
    setIsSubmitting(true);
    try {
      const payload = {
        items: inspectItems.map((item) => ({
          id: item.id,
          accepted_quantity: Number(item.accepted_quantity),
          damaged_quantity: Number(item.damaged_quantity || 0),
          quarantined_quantity: Number(item.quarantined_quantity || 0),
          rejected_quantity: Number(item.rejected_quantity || 0),
          lot_number: item.lot_number,
          expiry_date: item.expiry_date ? String(item.expiry_date).slice(0, 10) : undefined,
          discrepancy_reason: item.discrepancy_reason,
        })),
      };

      const res = await omsService.inspectGoodsReceivedNote(selectedGrn.id, payload);
      showNotification(`Inspection recorded for GRN #${res.data?.grn_number || ''}.`);
      setShowInspectGrnModal(false);
      fetchGrns();
      if (selectedGrn && selectedGrn.id === res.data?.id) {
        handleViewGrn(selectedGrn.id);
      }
    } catch (e: any) {
      showNotification(e?.message || 'Failed to record inspection.', true);
    } finally {
      setIsSubmitting(false);
    }
  };

  return (
    <div className="page-container" style={{ paddingBottom: 60 }}>
      {/* Breadcrumb Navigation */}
      <nav className="breadcrumb-nav">
        <span>Finovo</span>
        <span>&gt;</span>
        <span style={{ color: '#0f172a', fontWeight: 600 }}>Procurement &amp; Inbound</span>
      </nav>

      {/* Header Banner */}
      <div className="page-header-row">
        <div className="page-title-section">
          <div className="page-title-badge-wrap">
            <h1 className="page-title">Procurement &amp; Receiving Hub</h1>
            <span className="page-count-pill" style={{ background: '#dbeafe', color: '#1e40af' }}>
              Enterprise Grade
            </span>
          </div>
          <p className="page-subtitle">
            Manage Purchase Requisitions (PR), supplier commitments (PO), physical dock receiving (GRN), and authoritative inventory ledger posting.
          </p>
        </div>

        <InfoCalloutCard
          title="Authoritative Inventory Safeguard"
          description="Purchase Requisition approvals do not create stock or financial commitments. Stock is updated only upon validated GRN ledger posting."
          icon={<ShieldAlert size={18} color="#0284c7" />}
        />
      </div>

      {/* Alert Banners */}
      {errorBanner && (
        <div style={{
          backgroundColor: '#fef2f2',
          border: '1px solid #fecaca',
          borderRadius: 10,
          padding: '12px 16px',
          marginBottom: 16,
          display: 'flex',
          alignItems: 'center',
          gap: 12,
          color: '#b91c1c',
          fontSize: 13,
          fontWeight: 500,
        }}>
          <AlertCircle size={18} style={{ flexShrink: 0 }} />
          <span>{errorBanner}</span>
        </div>
      )}

      {successBanner && (
        <div style={{
          backgroundColor: '#f0fdf4',
          border: '1px solid #bbf7d0',
          borderRadius: 10,
          padding: '12px 16px',
          marginBottom: 16,
          display: 'flex',
          alignItems: 'center',
          gap: 12,
          color: '#15803d',
          fontSize: 13,
          fontWeight: 500,
        }}>
          <CheckCircle2 size={18} style={{ flexShrink: 0 }} />
          <span>{successBanner}</span>
        </div>
      )}

      {/* Top Level KPIs */}
      <div className="kpi-grid">
        <MetricCard
          title="Requisitions Pending"
          value={prKpis.submitted || 0}
          icon={<ClipboardCheck size={20} />}
          pillText="Approval Required"
          pillVariant="amber"
        />
        <MetricCard
          title="Active Purchase Orders"
          value={(poKpis.issued || 0) + (poKpis.partially_received || 0)}
          icon={<FileSpreadsheet size={20} />}
          pillText="In Flight"
          pillVariant="blue"
        />
        <MetricCard
          title="GRNs Ready to Post"
          value={(grnKpis.draft || 0) + (grnKpis.inspected || 0)}
          icon={<PackageCheck size={20} />}
          pillText="Dock Received"
          pillVariant="blue"
        />
        <MetricCard
          title="Posted Stock Receipts"
          value={grnKpis.posted || 0}
          icon={<Boxes size={20} />}
          pillText="Ledger Confirmed"
          pillVariant="green"
        />
      </div>

      {/* Primary Subsystem Navigation Tabs */}
      <div style={{
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'space-between',
        borderBottom: '1px solid #e2e8f0',
        marginBottom: 20,
        gap: 12,
        flexWrap: 'wrap',
      }}>
        <div className="procurement-tabs" role="tablist">
          <button
            onClick={() => setActiveTab('po')}
            className={`procurement-tab-btn ${activeTab === 'po' ? 'is-active' : ''}`}
          >
            <FileSpreadsheet size={16} />
            <span>Purchase Orders</span>
            <span className="procurement-tab-count">
              {poKpis.total || purchaseOrders.length}
            </span>
          </button>

          <button
            onClick={() => setActiveTab('pr')}
            className={`procurement-tab-btn ${activeTab === 'pr' ? 'is-active' : ''}`}
          >
            <ClipboardCheck size={16} />
            <span>Purchase Requisitions (PR)</span>
            <span className="procurement-tab-count">
              {prKpis.total || requisitions.length}
            </span>
          </button>

          <button
            onClick={() => setActiveTab('grn')}
            className={`procurement-tab-btn ${activeTab === 'grn' ? 'is-active' : ''}`}
          >
            <PackageCheck size={16} />
            <span>Goods Received Notes (GRN)</span>
            <span className="procurement-tab-count">
              {grnKpis.total || grns.length}
            </span>
          </button>
        </div>

        {/* Primary Action Button according to tab */}
        <div style={{ padding: '6px 0' }}>
          {activeTab === 'po' && (
            <button
              onClick={openCreatePoModal}
              className="btn-primary"
              style={{ display: 'flex', alignItems: 'center', gap: 6, padding: '9px 18px' }}
            >
              <Plus size={16} />
              <span>Create Purchase Order</span>
            </button>
          )}

          {activeTab === 'pr' && (
            <button
              onClick={openCreatePrModal}
              className="btn-primary"
              style={{ display: 'flex', alignItems: 'center', gap: 6, padding: '9px 18px' }}
            >
              <Plus size={16} />
              <span>New Requisition</span>
            </button>
          )}

          {activeTab === 'grn' && (
            <button
              onClick={handleOpenReceiveGrnFromTop}
              className="btn-primary"
              style={{ display: 'flex', alignItems: 'center', gap: 6, padding: '9px 18px' }}
            >
              <Truck size={16} />
              <span>Receive Shipment (GRN)</span>
            </button>
          )}
        </div>
      </div>

      {/* ========================================================= */}
      {/* TAB 1: PURCHASE ORDERS (PO)                               */}
      {/* ========================================================= */}
      {activeTab === 'po' && (
        <div>
          {/* Filter Bar */}
          <div style={{
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'space-between',
            gap: 12,
            marginBottom: 16,
            flexWrap: 'wrap',
          }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 10, flex: 1, minWidth: 260 }}>
              <div style={{ position: 'relative', width: '100%', maxWidth: 340 }}>
                <Search size={16} style={{ position: 'absolute', left: 12, top: 10, color: '#94a3b8' }} />
                <input
                  type="text"
                  placeholder="Search PO # or supplier..."
                  value={poSearch}
                  onChange={(e) => setPoSearch(e.target.value)}
                  onKeyDown={(e) => e.key === 'Enter' && fetchPurchaseOrders()}
                  className="form-input"
                  style={{ paddingLeft: 36, height: 38, fontSize: 13 }}
                />
              </div>

              <div style={{ width: 185 }}>
                <CustomDropdown
                  value={poStatusFilter}
                  onChange={(val) => setPoStatusFilter(val)}
                  options={[
                    { value: 'all', label: 'All Statuses' },
                    { value: 'draft', label: 'Draft' },
                    { value: 'issued', label: 'Issued' },
                    { value: 'partially_received', label: 'Partially Received' },
                    { value: 'received', label: 'Fully Received' },
                    { value: 'cancelled', label: 'Cancelled' },
                  ]}
                />
              </div>
            </div>

            <span style={{ fontSize: 13, color: '#64748b' }}>
              Showing {purchaseOrders.length} purchase orders
            </span>
          </div>

          {/* PO Table */}
          <div className="content-card" style={{ padding: 0, overflow: 'hidden' }}>
            <div className="table-responsive-wrapper">
              <table className="enterprise-data-table">
                <thead>
                  <tr>
                    <th>PO Number</th>
                    <th>Supplier</th>
                    <th>Warehouse</th>
                    <th>Order Date</th>
                    <th>Items &amp; Delivery</th>
                    <th>Total Amount</th>
                    <th>Status</th>
                    <th style={{ textAlign: 'right' }}>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  {isLoading ? (
                    <tr>
                      <td colSpan={8} style={{ textAlign: 'center', padding: '48px 16px', color: '#1e56ff' }}>
                        <Loader2 className="animate-spin" size={26} style={{ margin: '0 auto 8px' }} />
                        <div style={{ fontSize: 13, color: '#64748b' }}>Loading purchase orders...</div>
                      </td>
                    </tr>
                  ) : purchaseOrders.length === 0 ? (
                    <tr>
                      <td colSpan={8} style={{ textAlign: 'center', padding: '54px 16px', color: '#64748b' }}>
                        <div style={{ width: 56, height: 56, borderRadius: 16, backgroundColor: '#f1f5f9', color: '#64748b', display: 'flex', alignItems: 'center', justifyContent: 'center', margin: '0 auto 12px' }}>
                          <FileSpreadsheet size={28} />
                        </div>
                        <div style={{ fontWeight: 700, fontSize: 15, color: '#0f172a' }}>No Purchase Orders Found</div>
                        <p style={{ margin: '4px auto 16px', fontSize: 13, color: '#64748b', maxWidth: 420 }}>
                          Issue purchase orders to suppliers or convert approved purchase requisitions into POs.
                        </p>
                        <button onClick={openCreatePoModal} className="btn-primary" style={{ padding: '7px 16px', fontSize: 12 }}>
                          <Plus size={14} style={{ marginRight: 6 }} />
                          <span>Create Purchase Order</span>
                        </button>
                      </td>
                    </tr>
                  ) : (
                    purchaseOrders.map((po) => {
                      const totalQty = (po.items || []).reduce((acc: number, x: any) => acc + (x.ordered_quantity || 0), 0);
                      const recQty = (po.items || []).reduce((acc: number, x: any) => acc + (x.received_quantity || 0), 0);

                      let badgeColor = '#64748b';
                      let badgeBg = '#f1f5f9';
                      if (po.status === 'issued') { badgeColor = '#1e40af'; badgeBg = '#dbeafe'; }
                      else if (po.status === 'partially_received') { badgeColor = '#9a3412'; badgeBg = '#ffedd5'; }
                      else if (po.status === 'received') { badgeColor = '#15803d'; badgeBg = '#dcfce7'; }
                      else if (po.status === 'cancelled') { badgeColor = '#b91c1c'; badgeBg = '#fee2e2'; }

                      return (
                        <tr key={po.id}>
                          <td>
                            <div style={{ fontWeight: 700, color: '#1e56ff', fontSize: 13 }}>{po.po_number}</div>
                            {po.requisition && (
                              <div style={{ fontSize: 11, color: '#64748b', marginTop: 2 }}>From {po.requisition.pr_number}</div>
                            )}
                          </td>
                          <td>
                            <div style={{ fontWeight: 600, color: '#0f172a' }}>{po.supplier_name}</div>
                            <div style={{ fontSize: 11, color: '#64748b' }}>Terms: {po.payment_terms || 'Net 30'}</div>
                          </td>
                          <td>
                            <div style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: 12 }}>
                              <Building size={14} color="#64748b" />
                              <span style={{ fontWeight: 500 }}>{po.warehouse?.name || 'Warehouse'}</span>
                            </div>
                          </td>
                          <td>
                            <span style={{ fontSize: 12 }}>{(po.order_date || po.created_at)?.slice(0, 10)}</span>
                          </td>
                          <td>
                            <div style={{ fontSize: 12, fontWeight: 700 }}>{recQty} / {totalQty} units</div>
                            <div style={{ fontSize: 11, color: '#64748b' }}>{(po.items || []).length} SKU lines</div>
                          </td>
                          <td>
                            <span style={{ fontWeight: 700, fontSize: 13, color: '#0f172a' }}>
                              ${Number(po.total_amount || 0).toLocaleString(undefined, { minimumFractionDigits: 2 })}
                            </span>
                          </td>
                          <td>
                            <span style={{
                              display: 'inline-block',
                              padding: '4px 10px',
                              borderRadius: 6,
                              fontSize: 11,
                              fontWeight: 700,
                              textTransform: 'uppercase',
                              backgroundColor: badgeBg,
                              color: badgeColor,
                            }}>
                              {po.status?.replace('_', ' ')}
                            </span>
                          </td>
                          <td style={{ textAlign: 'right' }}>
                            <div style={{ display: 'inline-flex', alignItems: 'center', gap: 6 }}>
                              <button
                                onClick={() => handleViewPo(po.id)}
                                className="table-action-btn"
                                title="View PO Details"
                              >
                                <Eye size={15} />
                              </button>

                              {po.status !== 'received' && po.status !== 'cancelled' && (
                                <button
                                  onClick={() => openCreateGrnFromPo(po)}
                                  className="table-action-primary"
                                  title="Receive Inbound Goods (GRN)"
                                >
                                  <Truck size={13} />
                                  <span>Receive</span>
                                </button>
                              )}
                            </div>
                          </td>
                        </tr>
                      );
                    })
                  )}
                </tbody>
              </table>
            </div>
          </div>
        </div>
      )}

      {/* ========================================================= */}
      {/* TAB 2: PURCHASE REQUISITIONS (PR)                         */}
      {/* ========================================================= */}
      {activeTab === 'pr' && (
        <div>
          {/* Filter Bar */}
          <div style={{
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'space-between',
            gap: 12,
            marginBottom: 16,
            flexWrap: 'wrap',
          }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 10, flex: 1, minWidth: 260 }}>
              <div style={{ position: 'relative', width: '100%', maxWidth: 340 }}>
                <Search size={16} style={{ position: 'absolute', left: 12, top: 10, color: '#94a3b8' }} />
                <input
                  type="text"
                  placeholder="Search PR #, requester, or purpose..."
                  value={prSearch}
                  onChange={(e) => setPrSearch(e.target.value)}
                  onKeyDown={(e) => e.key === 'Enter' && fetchRequisitions()}
                  className="form-input"
                  style={{ paddingLeft: 36, height: 38, fontSize: 13 }}
                />
              </div>

              <div style={{ width: 185 }}>
                <CustomDropdown
                  value={prStatusFilter}
                  onChange={(val) => setPrStatusFilter(val)}
                  options={[
                    { value: 'all', label: 'All Statuses' },
                    { value: 'draft', label: 'Draft' },
                    { value: 'submitted', label: 'Submitted' },
                    { value: 'approved', label: 'Approved' },
                    { value: 'partially_ordered', label: 'Partially Ordered' },
                    { value: 'ordered', label: 'Ordered' },
                    { value: 'rejected', label: 'Rejected' },
                  ]}
                />
              </div>
            </div>

            <span style={{ fontSize: 13, color: '#64748b' }}>
              Showing {requisitions.length} requisitions
            </span>
          </div>

          {/* PR Table */}
          <div className="content-card" style={{ padding: 0, overflow: 'hidden' }}>
            <div className="table-responsive-wrapper">
              <table className="enterprise-data-table">
                <thead>
                  <tr>
                    <th>PR Number</th>
                    <th>Requester</th>
                    <th>Department</th>
                    <th>Warehouse</th>
                    <th>Priority</th>
                    <th>Est. Total</th>
                    <th>Status</th>
                    <th style={{ textAlign: 'right' }}>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  {isLoading ? (
                    <tr>
                      <td colSpan={8} style={{ textAlign: 'center', padding: '48px 16px', color: '#1e56ff' }}>
                        <Loader2 className="animate-spin" size={26} style={{ margin: '0 auto 8px' }} />
                        <div style={{ fontSize: 13, color: '#64748b' }}>Loading requisitions...</div>
                      </td>
                    </tr>
                  ) : requisitions.length === 0 ? (
                    <tr>
                      <td colSpan={8} style={{ textAlign: 'center', padding: '54px 16px', color: '#64748b' }}>
                        <div style={{ width: 56, height: 56, borderRadius: 16, backgroundColor: '#f1f5f9', color: '#64748b', display: 'flex', alignItems: 'center', justifyContent: 'center', margin: '0 auto 12px' }}>
                          <ClipboardCheck size={28} />
                        </div>
                        <div style={{ fontWeight: 700, fontSize: 15, color: '#0f172a' }}>No Purchase Requisitions Found</div>
                        <p style={{ margin: '4px auto 16px', fontSize: 13, color: '#64748b', maxWidth: 420 }}>
                          Submit purchase requisitions with business justifications and live stock availability.
                        </p>
                        <button onClick={openCreatePrModal} className="btn-primary" style={{ padding: '7px 16px', fontSize: 12 }}>
                          <Plus size={14} style={{ marginRight: 6 }} />
                          <span>New Requisition</span>
                        </button>
                      </td>
                    </tr>
                  ) : (
                    requisitions.map((pr) => {
                      let badgeColor = '#64748b';
                      let badgeBg = '#f1f5f9';
                      if (pr.status === 'submitted') { badgeColor = '#9a3412'; badgeBg = '#ffedd5'; }
                      else if (pr.status === 'approved') { badgeColor = '#15803d'; badgeBg = '#dcfce7'; }
                      else if (pr.status === 'partially_ordered' || pr.status === 'ordered') { badgeColor = '#1e40af'; badgeBg = '#dbeafe'; }
                      else if (pr.status === 'rejected') { badgeColor = '#b91c1c'; badgeBg = '#fee2e2'; }

                      return (
                        <tr key={pr.id}>
                          <td>
                            <div style={{ fontWeight: 700, color: '#1e56ff', fontSize: 13 }}>{pr.pr_number}</div>
                            <div style={{ fontSize: 11, color: '#64748b', marginTop: 2 }}>{pr.created_at?.slice(0, 10)}</div>
                          </td>
                          <td>
                            <div style={{ fontWeight: 600, color: '#0f172a' }}>{pr.requester?.name || 'Staff'}</div>
                            <div style={{ fontSize: 11, color: '#64748b' }}>{pr.requester?.email}</div>
                          </td>
                          <td>
                            <span style={{ fontSize: 12 }}>{pr.department || 'General'}</span>
                          </td>
                          <td>
                            <div style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: 12 }}>
                              <Building size={14} color="#64748b" />
                              <span style={{ fontWeight: 500 }}>{pr.warehouse?.name || 'Warehouse'}</span>
                            </div>
                          </td>
                          <td>
                            <span style={{
                              display: 'inline-block',
                              padding: '2px 8px',
                              borderRadius: 4,
                              fontSize: 10,
                              fontWeight: 700,
                              textTransform: 'uppercase',
                              backgroundColor: pr.priority === 'urgent' ? '#fee2e2' : (pr.priority === 'high' ? '#ffedd5' : '#f1f5f9'),
                              color: pr.priority === 'urgent' ? '#dc2626' : (pr.priority === 'high' ? '#c2410c' : '#475569'),
                            }}>
                              {pr.priority}
                            </span>
                          </td>
                          <td>
                            <span style={{ fontWeight: 700, fontSize: 13, color: '#0f172a' }}>
                              ${Number(pr.total_amount || 0).toLocaleString(undefined, { minimumFractionDigits: 2 })}
                            </span>
                          </td>
                          <td>
                            <span style={{
                              display: 'inline-block',
                              padding: '4px 10px',
                              borderRadius: 6,
                              fontSize: 11,
                              fontWeight: 700,
                              textTransform: 'uppercase',
                              backgroundColor: badgeBg,
                              color: badgeColor,
                            }}>
                              {pr.status?.replace('_', ' ')}
                            </span>
                          </td>
                          <td style={{ textAlign: 'right' }}>
                            <div style={{ display: 'inline-flex', alignItems: 'center', gap: 6 }}>
                              <button
                                onClick={() => handleViewPr(pr.id)}
                                className="table-action-btn"
                                title="View Requisition Details"
                              >
                                <Eye size={15} />
                              </button>

                              <button
                                onClick={() => omsService.downloadPrPdf(pr.id, `${pr.pr_number}.pdf`)}
                                className="table-action-btn"
                                title="Download Official PDF"
                              >
                                <FileDown size={15} />
                              </button>

                              {pr.status === 'draft' && (
                                <button
                                  onClick={() => handleSubmitPr(pr.id)}
                                  className="table-action-primary"
                                >
                                  Submit
                                </button>
                              )}

                              {pr.status === 'submitted' && (
                                <button
                                  onClick={() => handleApprovePr(pr)}
                                  className="table-action-primary is-success"
                                >
                                  Approve
                                </button>
                              )}

                              {(pr.status === 'approved' || pr.status === 'partially_ordered') && (
                                <button
                                  onClick={() => openConvertPoModal(pr)}
                                  className="table-action-primary"
                                >
                                  Convert PO
                                </button>
                              )}
                            </div>
                          </td>
                        </tr>
                      );
                    })
                  )}
                </tbody>
              </table>
            </div>
          </div>
        </div>
      )}

      {/* ========================================================= */}
      {/* TAB 3: GOODS RECEIVED NOTES (GRN)                         */}
      {/* ========================================================= */}
      {activeTab === 'grn' && (
        <div>
          {/* Filter Bar */}
          <div style={{
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'space-between',
            gap: 12,
            marginBottom: 16,
            flexWrap: 'wrap',
          }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 10, flex: 1, minWidth: 260 }}>
              <div style={{ position: 'relative', width: '100%', maxWidth: 340 }}>
                <Search size={16} style={{ position: 'absolute', left: 12, top: 10, color: '#94a3b8' }} />
                <input
                  type="text"
                  placeholder="Search GRN #, PO #, or carrier..."
                  value={grnSearch}
                  onChange={(e) => setGrnSearch(e.target.value)}
                  onKeyDown={(e) => e.key === 'Enter' && fetchGrns()}
                  className="form-input"
                  style={{ paddingLeft: 36, height: 38, fontSize: 13 }}
                />
              </div>

              <div style={{ width: 185 }}>
                <CustomDropdown
                  value={grnStatusFilter}
                  onChange={(val) => setGrnStatusFilter(val)}
                  options={[
                    { value: 'all', label: 'All Statuses' },
                    { value: 'draft', label: 'Draft' },
                    { value: 'inspected', label: 'Inspected' },
                    { value: 'posted', label: 'Posted to Inventory' },
                    { value: 'cancelled', label: 'Cancelled' },
                  ]}
                />
              </div>
            </div>

            <span style={{ fontSize: 13, color: '#64748b' }}>
              Showing {grns.length} goods received notes
            </span>
          </div>

          {/* GRN Table */}
          <div className="content-card" style={{ padding: 0, overflow: 'hidden' }}>
            <div className="table-responsive-wrapper">
              <table className="enterprise-data-table">
                <thead>
                  <tr>
                    <th>GRN Number</th>
                    <th>PO Reference</th>
                    <th>Supplier &amp; Carrier</th>
                    <th>Warehouse &amp; Dock</th>
                    <th>Custody Breakdown</th>
                    <th>Received At</th>
                    <th>Status</th>
                    <th style={{ textAlign: 'right' }}>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  {isLoading ? (
                    <tr>
                      <td colSpan={8} style={{ textAlign: 'center', padding: '48px 16px', color: '#1e56ff' }}>
                        <Loader2 className="animate-spin" size={26} style={{ margin: '0 auto 8px' }} />
                        <div style={{ fontSize: 13, color: '#64748b' }}>Loading goods received notes...</div>
                      </td>
                    </tr>
                  ) : grns.length === 0 ? (
                    <tr>
                      <td colSpan={8} style={{ textAlign: 'center', padding: '54px 16px', color: '#64748b' }}>
                        <div style={{ width: 56, height: 56, borderRadius: 16, backgroundColor: '#f1f5f9', color: '#64748b', display: 'flex', alignItems: 'center', justifyContent: 'center', margin: '0 auto 12px' }}>
                          <PackageCheck size={28} />
                        </div>
                        <div style={{ fontWeight: 700, fontSize: 15, color: '#0f172a' }}>No Goods Received Notes</div>
                        <p style={{ margin: '4px auto 16px', fontSize: 13, color: '#64748b', maxWidth: 420 }}>
                          Receive inbound shipments against Purchase Orders to record physical inventory.
                        </p>
                      </td>
                    </tr>
                  ) : (
                    grns.map((grn) => {
                      let badgeColor = '#64748b';
                      let badgeBg = '#f1f5f9';
                      if (grn.status === 'inspected') { badgeColor = '#9a3412'; badgeBg = '#ffedd5'; }
                      else if (grn.status === 'posted') { badgeColor = '#15803d'; badgeBg = '#dcfce7'; }

                      return (
                        <tr key={grn.id}>
                          <td>
                            <div style={{ fontWeight: 700, color: '#1e56ff', fontSize: 13 }}>{grn.grn_number}</div>
                            {grn.supplier_delivery_note && (
                              <div style={{ fontSize: 11, color: '#64748b', marginTop: 2 }}>Note: {grn.supplier_delivery_note}</div>
                            )}
                          </td>
                          <td>
                            <div style={{ fontWeight: 600, color: '#0f172a' }}>{grn.purchase_order?.po_number}</div>
                            <div style={{ fontSize: 11, color: '#64748b' }}>{grn.purchase_order?.supplier_name}</div>
                          </td>
                          <td>
                            <div style={{ fontSize: 12, fontWeight: 500 }}>{grn.carrier || 'Freight Line'}</div>
                            <div style={{ fontSize: 11, color: '#64748b' }}>{grn.tracking_or_vehicle_number || 'N/A'}</div>
                          </td>
                          <td>
                            <div style={{ fontSize: 12, fontWeight: 600 }}>{grn.warehouse?.name}</div>
                            <div style={{ fontSize: 11, color: '#0284c7', fontWeight: 500 }}>{grn.receiving_location?.code || 'Inbound Dock'}</div>
                          </td>
                          <td>
                            <div style={{ fontSize: 12, fontWeight: 700 }}>
                              Total: {grn.total_received_units} units
                            </div>
                            <div style={{ display: 'flex', gap: 8, fontSize: 11, marginTop: 2 }}>
                              <span style={{ color: '#15803d', fontWeight: 600 }}>Good: {grn.total_accepted_units}</span>
                              <span style={{ color: '#dc2626', fontWeight: 600 }}>Damaged: {grn.total_damaged_units}</span>
                              <span style={{ color: '#c2410c', fontWeight: 600 }}>Quar: {grn.total_quarantined_units}</span>
                            </div>
                          </td>
                          <td>
                            <span style={{ fontSize: 12 }}>{grn.received_at?.slice(0, 16).replace('T', ' ')}</span>
                            <div style={{ fontSize: 11, color: '#64748b' }}>By {grn.received_by?.name || 'Staff'}</div>
                          </td>
                          <td>
                            <span style={{
                              display: 'inline-block',
                              padding: '4px 10px',
                              borderRadius: 6,
                              fontSize: 11,
                              fontWeight: 700,
                              textTransform: 'uppercase',
                              backgroundColor: badgeBg,
                              color: badgeColor,
                            }}>
                              {grn.status}
                            </span>
                          </td>
                          <td style={{ textAlign: 'right' }}>
                            <div style={{ display: 'inline-flex', alignItems: 'center', gap: 6 }}>
                              <button
                                onClick={() => handleViewGrn(grn.id)}
                                className="table-action-btn"
                                title="View GRN Details"
                              >
                                <Eye size={15} />
                              </button>

                              <button
                                onClick={() => omsService.downloadGrnPdf(grn.id, `${grn.grn_number}.pdf`)}
                                className="table-action-btn"
                                title="Download Official PDF"
                              >
                                <FileDown size={15} />
                              </button>

                              {grn.status !== 'posted' && (
                                <>
                                  <button
                                    onClick={() => openInspectGrn(grn)}
                                    className="table-action-btn"
                                    title="Inspect & Adjust Conditions"
                                  >
                                    <ClipboardCheck size={15} />
                                  </button>
                                  <button
                                    onClick={() => handlePostGrnToInventory(grn)}
                                    className="table-action-primary is-success"
                                    title="Post quantities to authoritative inventory engine"
                                  >
                                    <Boxes size={13} />
                                    <span>Post Stock</span>
                                  </button>
                                </>
                              )}
                            </div>
                          </td>
                        </tr>
                      );
                    })
                  )}
                </tbody>
              </table>
            </div>
          </div>
        </div>
      )}

      {/* ========================================================= */}
      {/* MODAL 1: CREATE PURCHASE REQUISITION                      */}
      {/* ========================================================= */}
      {showCreatePrModal && (
        <div
          className="modal-overlay"
          onClick={(e) => {
            if (e.target === e.currentTarget) setShowCreatePrModal(false);
          }}
        >
          <div className="procurement-modal" style={{ maxWidth: 880 }}>
            <div className="procurement-modal-header">
              <div>
                <h3 style={{ margin: 0, fontSize: 18, fontWeight: 800, color: '#0f172a' }}>
                  New Purchase Requisition
                </h3>
                <p style={{ margin: '3px 0 0 0', fontSize: 12, color: '#64748b' }}>
                  Request catalog products with required-by dates, business justification, and live stock context.
                </p>
              </div>
              <button onClick={() => setShowCreatePrModal(false)} className="topbar-icon-button">
                <X size={18} />
              </button>
            </div>

            <div className="procurement-modal-body">
              <div className="finovo-form-grid-2" style={{ marginBottom: 16 }}>
                <div>
                  <label className="form-label">Destination Warehouse *</label>
                  <CustomDropdown
                    value={String(prWarehouseId)}
                    onChange={(val) => setPrWarehouseId(Number(val))}
                    options={warehouses.map((w) => ({ value: String(w.id), label: `${w.name} (${w.code})` }))}
                  />
                </div>

                <div>
                  <label className="form-label">Department *</label>
                  <input
                    type="text"
                    className="form-input"
                    value={prDepartment}
                    onChange={(e) => setPrDepartment(e.target.value)}
                    placeholder="e.g. Warehouse Tech, Operations"
                  />
                </div>

                <div>
                  <label className="form-label">Priority</label>
                  <CustomDropdown
                    value={prPriority}
                    onChange={(val: any) => setPrPriority(val)}
                    options={[
                      { value: 'low', label: 'Low Priority' },
                      { value: 'normal', label: 'Normal' },
                      { value: 'high', label: 'High Priority' },
                      { value: 'urgent', label: 'Urgent (Priority Order)' },
                    ]}
                  />
                </div>

                <div>
                  <label className="form-label">Required By Date</label>
                  <input
                    type="date"
                    className="form-input"
                    value={prRequiredBy}
                    onChange={(e) => setPrRequiredBy(e.target.value)}
                  />
                </div>
              </div>

              <div style={{ marginBottom: 14 }}>
                <label className="form-label">Purpose / Operational Goal</label>
                <input
                  type="text"
                  className="form-input"
                  value={prPurpose}
                  onChange={(e) => setPrPurpose(e.target.value)}
                  placeholder="e.g. Replenishment for seasonal surge; terminal upgrade"
                />
              </div>

              <div style={{ marginBottom: 18 }}>
                <label className="form-label">Business Justification</label>
                <textarea
                  className="form-input"
                  style={{ height: 60, resize: 'vertical' }}
                  value={prJustification}
                  onChange={(e) => setPrJustification(e.target.value)}
                  placeholder="Why is this purchase required? State stock deficit or operational need..."
                />
              </div>

              {/* Line Items Picker */}
              <div style={{ borderTop: '1px solid #e2e8f0', paddingTop: 16 }}>
                <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 12, flexWrap: 'wrap', gap: 10 }}>
                  <div className="procurement-section-header" style={{ margin: 0 }}>
                    <Layers size={15} color="#1e56ff" />
                    <span>Requisition Line Items</span>
                  </div>

                  <div style={{ width: 300 }}>
                    <CustomDropdown
                      value=""
                      searchable={true}
                      onChange={(val) => addPrItem(Number(val))}
                      placeholder="+ Add Product Variant..."
                      options={variantOptions}
                      searchPlaceholder="Search product or SKU..."
                      menuMinWidth={420}
                    />
                  </div>
                </div>

                {prItems.length === 0 ? (
                  <div style={{ textAlign: 'center', padding: '32px 16px', border: '1px dashed #cbd5e1', borderRadius: 10, color: '#64748b', fontSize: 13, background: '#f8fafc' }}>
                    Select product variants from the dropdown above to add line items to your requisition.
                  </div>
                ) : (
                  <div className="table-responsive-wrapper" style={{ maxHeight: 220, overflowY: 'auto' }}>
                    <table className="enterprise-data-table">
                      <thead>
                        <tr>
                          <th>SKU &amp; Product</th>
                          <th style={{ width: 110 }}>Requested</th>
                          <th style={{ width: 120 }}>Est. Price ($)</th>
                          <th>Stock Advisory</th>
                          <th style={{ width: 110, textAlign: 'right' }}>Est. Subtotal</th>
                          <th style={{ width: 36 }}></th>
                        </tr>
                      </thead>
                      <tbody>
                        {prItems.map((item, idx) => {
                          const planning = stockPlanning[item.variant_id];
                          return (
                            <tr key={item.variant_id}>
                              <td>
                                <div style={{ fontWeight: 600 }}>{item.sku}</div>
                                <div style={{ fontSize: 11, color: '#64748b' }}>{item.name}</div>
                              </td>
                              <td>
                                <input
                                  type="number"
                                  min={1}
                                  className="form-input"
                                  style={{ height: 32, padding: '4px 8px' }}
                                  value={item.qty}
                                  onChange={(e) => {
                                    const val = Math.max(1, parseInt(e.target.value) || 1);
                                    setPrItems((prev) => prev.map((x, i) => (i === idx ? { ...x, qty: val } : x)));
                                  }}
                                />
                              </td>
                              <td>
                                <input
                                  type="number"
                                  min={0}
                                  step="0.01"
                                  className="form-input"
                                  style={{ height: 32, padding: '4px 8px' }}
                                  value={item.unit_price}
                                  onChange={(e) => {
                                    const val = Math.max(0, parseFloat(e.target.value) || 0);
                                    setPrItems((prev) => prev.map((x, i) => (i === idx ? { ...x, unit_price: val } : x)));
                                  }}
                                />
                              </td>
                              <td>
                                {planning ? (
                                  <div style={{ display: 'flex', gap: 6, fontSize: 10, flexWrap: 'wrap' }}>
                                    <span style={{ background: '#dcfce7', color: '#15803d', padding: '1px 5px', borderRadius: 4, fontWeight: 700 }}>
                                      Avail: {planning.available}
                                    </span>
                                    <span style={{ background: '#f1f5f9', color: '#475569', padding: '1px 5px', borderRadius: 4, fontWeight: 600 }}>
                                      On Hand: {planning.on_hand}
                                    </span>
                                    <span style={{ background: '#ffedd5', color: '#c2410c', padding: '1px 5px', borderRadius: 4, fontWeight: 600 }}>
                                      On Order: {planning.on_order}
                                    </span>
                                  </div>
                                ) : (
                                  <span style={{ fontSize: 11, color: '#94a3b8' }}>Live stock synced</span>
                                )}
                              </td>
                              <td style={{ textAlign: 'right', fontWeight: 700 }}>
                                ${(item.qty * item.unit_price).toFixed(2)}
                              </td>
                              <td>
                                <button
                                  onClick={() => setPrItems((prev) => prev.filter((_, i) => i !== idx))}
                                  style={{ background: 'none', border: 'none', color: '#dc2626', cursor: 'pointer' }}
                                >
                                  <X size={15} />
                                </button>
                              </td>
                            </tr>
                          );
                        })}
                      </tbody>
                    </table>
                  </div>
                )}
              </div>
            </div>

            {/* Modal Footer */}
            <div className="procurement-modal-footer">
              <div>
                <span style={{ fontSize: 13, color: '#64748b' }}>Estimated Subtotal: </span>
                <span style={{ fontSize: 18, fontWeight: 800, color: '#0f172a' }}>
                  ${prItems.reduce((acc, x) => acc + x.qty * x.unit_price, 0).toFixed(2)}
                </span>
              </div>

              <div style={{ display: 'flex', gap: 8 }}>
                <button onClick={() => setShowCreatePrModal(false)} className="btn-outline" disabled={isSubmitting}>
                  Cancel
                </button>
                <button onClick={() => handleSaveRequisition(false)} className="btn-outline" disabled={isSubmitting}>
                  Save as Draft
                </button>
                <button onClick={() => handleSaveRequisition(true)} className="btn-primary" disabled={isSubmitting}>
                  {isSubmitting ? 'Submitting...' : 'Submit for Approval'}
                </button>
              </div>
            </div>
          </div>
        </div>
      )}

      {/* ========================================================= */}
      {/* MODAL 2: VIEW PURCHASE REQUISITION DETAILS                */}
      {/* ========================================================= */}
      {showViewPrModal && selectedPr && (
        <div
          className="modal-overlay"
          onClick={(e) => {
            if (e.target === e.currentTarget) setShowViewPrModal(false);
          }}
        >
          <div className="procurement-modal" style={{ maxWidth: 840 }}>
            <div className="procurement-modal-header">
              <div>
                <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                  <h3 style={{ margin: 0, fontSize: 18, fontWeight: 800, color: '#0f172a' }}>
                    Requisition {selectedPr.pr_number}
                  </h3>
                  <span style={{
                    padding: '3px 8px',
                    borderRadius: 6,
                    fontSize: 10,
                    fontWeight: 700,
                    textTransform: 'uppercase',
                    backgroundColor: '#dbeafe',
                    color: '#1e40af',
                  }}>
                    {selectedPr.status}
                  </span>
                </div>
                <div style={{ fontSize: 12, color: '#64748b', marginTop: 2 }}>
                  Requested by {selectedPr.requester?.name} &bull; {selectedPr.department} &bull; {selectedPr.created_at?.slice(0, 10)}
                </div>
              </div>
              <button onClick={() => setShowViewPrModal(false)} className="topbar-icon-button">
                <X size={18} />
              </button>
            </div>

            <div className="procurement-modal-body">
              {/* Meta Grid */}
              <div className="procurement-meta-box">
                <div className="procurement-meta-item">
                  <strong>Warehouse</strong>
                  <span>{selectedPr.warehouse?.name} ({selectedPr.warehouse?.code})</span>
                </div>
                <div className="procurement-meta-item">
                  <strong>Priority</strong>
                  <span style={{ textTransform: 'uppercase', color: selectedPr.priority === 'urgent' ? '#dc2626' : '#2563eb' }}>
                    {selectedPr.priority}
                  </span>
                </div>
                <div className="procurement-meta-item">
                  <strong>Required By</strong>
                  <span>{selectedPr.required_by_date || 'Flexible'}</span>
                </div>
                <div className="procurement-meta-item">
                  <strong>Approver</strong>
                  <span>{selectedPr.approver?.name || 'Pending Review'}</span>
                </div>
              </div>

              {selectedPr.purpose && (
                <div style={{ marginBottom: 12, fontSize: 13 }}>
                  <strong style={{ color: '#475569' }}>Purpose:</strong> {selectedPr.purpose}
                </div>
              )}
              {selectedPr.justification && (
                <div style={{ marginBottom: 16, fontSize: 13, color: '#475569' }}>
                  <strong style={{ color: '#475569' }}>Business Justification:</strong> {selectedPr.justification}
                </div>
              )}

              {/* Items Table */}
              <div className="procurement-section-header">
                <Layers size={14} color="#1e56ff" />
                <span>Requisition Items</span>
              </div>
              <div className="table-responsive-wrapper" style={{ maxHeight: 200, overflowY: 'auto', marginBottom: 16 }}>
                <table className="enterprise-data-table">
                  <thead>
                    <tr>
                      <th>SKU &amp; Description</th>
                      <th>Requested</th>
                      <th>Approved</th>
                      <th>Ordered</th>
                      <th>Remaining</th>
                      <th style={{ textAlign: 'right' }}>Est. Total</th>
                    </tr>
                  </thead>
                  <tbody>
                    {(selectedPr.items || []).map((item: any) => (
                      <tr key={item.id}>
                        <td>
                          <div style={{ fontWeight: 600 }}>{item.sku}</div>
                          <div style={{ fontSize: 11, color: '#64748b' }}>{item.product_name}</div>
                        </td>
                        <td>{item.requested_quantity} {item.uom}</td>
                        <td>{item.approved_quantity || 0}</td>
                        <td>{item.ordered_quantity || 0}</td>
                        <td><strong style={{ color: '#2563eb' }}>{item.remaining_quantity || 0}</strong></td>
                        <td style={{ textAlign: 'right', fontWeight: 700 }}>
                          ${Number(item.estimated_subtotal || 0).toFixed(2)}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>

              {/* Approval Audit Trail */}
              <div style={{ borderTop: '1px solid #e2e8f0', paddingTop: 14 }}>
                <div className="procurement-section-header">
                  <UserCheck size={14} color="#1e56ff" />
                  <span>Approval Audit History</span>
                </div>
                <div style={{ display: 'flex', flexDirection: 'column', gap: 8, fontSize: 12 }}>
                  {(selectedPr.approvals || []).map((app: any, idx: number) => (
                    <div key={idx} style={{ display: 'flex', justifyContent: 'space-between', padding: '6px 10px', background: '#f8fafc', borderRadius: 6, color: '#475569' }}>
                      <span>
                        <strong>{app.user?.name || 'User'}</strong> ({app.action}): {app.comments || 'No comment'}
                      </span>
                      <span style={{ color: '#94a3b8', fontSize: 11 }}>{app.created_at?.slice(0, 16).replace('T', ' ')}</span>
                    </div>
                  ))}
                </div>
              </div>
            </div>

            {/* Modal Footer */}
            <div className="procurement-modal-footer">
              <button
                onClick={() => omsService.downloadPrPdf(selectedPr.id, `${selectedPr.pr_number}.pdf`)}
                className="btn-outline"
                style={{ display: 'inline-flex', alignItems: 'center', gap: 6 }}
              >
                <Download size={14} />
                <span>Download Official PDF</span>
              </button>

              <div style={{ display: 'flex', gap: 8 }}>
                {selectedPr.status === 'submitted' && (
                  <>
                    <button
                      onClick={() => setShowRejectPrModal(true)}
                      className="btn-outline"
                      style={{ color: '#dc2626', borderColor: '#fca5a5' }}
                    >
                      Reject PR
                    </button>
                    <button
                      onClick={() => handleApprovePr(selectedPr)}
                      className="btn-primary"
                      style={{ backgroundColor: '#16a34a', borderColor: '#16a34a' }}
                    >
                      Approve Requisition
                    </button>
                  </>
                )}

                {(selectedPr.status === 'approved' || selectedPr.status === 'partially_ordered') && (
                  <button
                    onClick={() => openConvertPoModal(selectedPr)}
                    className="btn-primary"
                  >
                    Convert to Purchase Order
                  </button>
                )}
              </div>
            </div>
          </div>
        </div>
      )}

      {/* ========================================================= */}
      {/* MODAL 3: CONVERT PR TO PURCHASE ORDER                     */}
      {/* ========================================================= */}
      {showConvertPoModal && selectedPr && (
        <div
          className="modal-overlay"
          onClick={(e) => {
            if (e.target === e.currentTarget) setShowConvertPoModal(false);
          }}
        >
          <div className="procurement-modal" style={{ maxWidth: 720 }}>
            <div className="procurement-modal-header">
              <div>
                <h3 style={{ margin: 0, fontSize: 18, fontWeight: 800, color: '#0f172a' }}>
                  Convert PR #{selectedPr.pr_number} to Purchase Order
                </h3>
                <p style={{ margin: '3px 0 0 0', fontSize: 12, color: '#64748b' }}>
                  Assign supplier and specify line quantities to commit into a formal Purchase Order.
                </p>
              </div>
              <button onClick={() => setShowConvertPoModal(false)} className="topbar-icon-button">
                <X size={18} />
              </button>
            </div>

            <div className="procurement-modal-body">
              <div className="finovo-form-grid-2" style={{ marginBottom: 16 }}>
                <div>
                  <label className="form-label">Supplier / Vendor Name *</label>
                  <input
                    type="text"
                    className="form-input"
                    value={poSupplierName}
                    onChange={(e) => setPoSupplierName(e.target.value)}
                    placeholder="e.g. Zebra Technologies, Honeywell"
                  />
                </div>

                <div>
                  <label className="form-label">Payment Terms</label>
                  <CustomDropdown
                    value={poPaymentTerms}
                    onChange={(val) => setPoPaymentTerms(val)}
                    options={[
                      { value: 'Net 15', label: 'Net 15 Days' },
                      { value: 'Net 30', label: 'Net 30 Days' },
                      { value: 'Net 60', label: 'Net 60 Days' },
                      { value: 'Immediate', label: 'Cash on Delivery (Immediate)' },
                    ]}
                  />
                </div>
              </div>

              <div className="procurement-section-header">
                <Layers size={14} color="#1e56ff" />
                <span>Allocate Quantities to Order</span>
              </div>
              <div className="table-responsive-wrapper">
                <table className="enterprise-data-table">
                  <thead>
                    <tr>
                      <th>SKU &amp; Description</th>
                      <th>Approved</th>
                      <th>Ordered</th>
                      <th style={{ width: 140 }}>Order Now Qty</th>
                    </tr>
                  </thead>
                  <tbody>
                    {(selectedPr.items || []).map((item: any) => {
                      const maxOrder = item.remaining_quantity !== undefined ? item.remaining_quantity : (item.approved_quantity || item.requested_quantity);
                      return (
                        <tr key={item.id}>
                          <td>
                            <div style={{ fontWeight: 600 }}>{item.sku}</div>
                            <div style={{ fontSize: 11, color: '#64748b' }}>{item.product_name}</div>
                          </td>
                          <td>{item.approved_quantity || item.requested_quantity}</td>
                          <td>{item.ordered_quantity || 0}</td>
                          <td>
                            <input
                              type="number"
                              min={0}
                              max={maxOrder}
                              className="form-input"
                              style={{ height: 32, padding: '4px 8px' }}
                              value={convertItemQuantities[item.id] || 0}
                              onChange={(e) => {
                                const val = Math.min(maxOrder, Math.max(0, parseInt(e.target.value) || 0));
                                setConvertItemQuantities((prev) => ({ ...prev, [item.id]: val }));
                              }}
                            />
                            <div style={{ fontSize: 10, color: '#64748b', marginTop: 2 }}>Remaining: {maxOrder}</div>
                          </td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
            </div>

            <div className="procurement-modal-footer">
              <button onClick={() => setShowConvertPoModal(false)} className="btn-outline" disabled={isSubmitting}>
                Cancel
              </button>
              <button onClick={handleExecuteConvertPo} className="btn-primary" disabled={isSubmitting}>
                {isSubmitting ? 'Generating...' : 'Generate Purchase Order'}
              </button>
            </div>
          </div>
        </div>
      )}

      {/* ========================================================= */}
      {/* MODAL 4: REJECT PR MODAL                                  */}
      {/* ========================================================= */}
      {showRejectPrModal && (
        <div
          className="modal-overlay"
          onClick={(e) => {
            if (e.target === e.currentTarget) setShowRejectPrModal(false);
          }}
        >
          <div className="procurement-modal" style={{ maxWidth: 480 }}>
            <div className="procurement-modal-header">
              <h3 style={{ margin: 0, fontSize: 16, fontWeight: 800, color: '#dc2626' }}>
                Reject Purchase Requisition
              </h3>
              <button onClick={() => setShowRejectPrModal(false)} className="topbar-icon-button">
                <X size={16} />
              </button>
            </div>

            <div className="procurement-modal-body">
              <p style={{ margin: '0 0 12px 0', fontSize: 13, color: '#64748b' }}>
                Please provide a formal business reason for rejecting this requisition.
              </p>

              <textarea
                className="form-input"
                style={{ height: 90, width: '100%' }}
                placeholder="e.g. Budget ceiling reached; alternate surplus located in secondary hub..."
                value={rejectionReason}
                onChange={(e) => setRejectionReason(e.target.value)}
              />
            </div>

            <div className="procurement-modal-footer">
              <button onClick={() => setShowRejectPrModal(false)} className="btn-outline">
                Cancel
              </button>
              <button onClick={handleRejectPrConfirm} className="btn-primary is-danger">
                Confirm Rejection
              </button>
            </div>
          </div>
        </div>
      )}

      {/* ========================================================= */}
      {/* MODAL 5: RECEIVE INBOUND SHIPMENT (CREATE GRN)            */}
      {/* ========================================================= */}
      {showCreateGrnModal && selectedPo && (
        <div
          className="modal-overlay"
          onClick={(e) => {
            if (e.target === e.currentTarget) setShowCreateGrnModal(false);
          }}
        >
          <div className="procurement-modal" style={{ maxWidth: 900 }}>
            <div className="procurement-modal-header">
              <div>
                <h3 style={{ margin: 0, fontSize: 18, fontWeight: 800, color: '#0f172a' }}>
                  Dock Receiving: Goods Received Note (GRN)
                </h3>
                <p style={{ margin: '3px 0 0 0', fontSize: 12, color: '#64748b' }}>
                  Receiving inbound shipment against PO #{selectedPo.po_number} ({selectedPo.supplier_name})
                </p>
              </div>
              <button onClick={() => setShowCreateGrnModal(false)} className="topbar-icon-button">
                <X size={18} />
              </button>
            </div>

            <div className="procurement-modal-body">
              <div className="finovo-form-grid-2" style={{ marginBottom: 16 }}>
                <div>
                  <label className="form-label">Delivery Note / Bill of Lading # *</label>
                  <input
                    type="text"
                    className="form-input"
                    value={grnDeliveryNote}
                    onChange={(e) => setGrnDeliveryNote(e.target.value)}
                    placeholder="e.g. DN-902188"
                  />
                </div>

                <div>
                  <label className="form-label">Carrier / Transport Method</label>
                  <input
                    type="text"
                    className="form-input"
                    value={grnCarrier}
                    onChange={(e) => setGrnCarrier(e.target.value)}
                    placeholder="e.g. FedEx Ground, DHL, Freight Line"
                  />
                </div>

                <div>
                  <label className="form-label">Tracking / Vehicle Plate #</label>
                  <input
                    type="text"
                    className="form-input"
                    value={grnVehicle}
                    onChange={(e) => setGrnVehicle(e.target.value)}
                    placeholder="e.g. TRK-88219"
                  />
                </div>

                <div style={{ display: 'flex', alignItems: 'center', paddingTop: 26 }}>
                  <label style={{ display: 'flex', alignItems: 'center', gap: 8, cursor: 'pointer', fontSize: 13 }}>
                    <input
                      type="checkbox"
                      checked={grnAllowOverReceipt}
                      onChange={(e) => setGrnAllowOverReceipt(e.target.checked)}
                    />
                    <span>Allow Over-Receipt (&gt; 10% tolerance override)</span>
                  </label>
                </div>
              </div>

              {/* Line Items Reconciliation Table */}
              <div className="procurement-section-header">
                <Boxes size={14} color="#1e56ff" />
                <span>Physical Receiving &amp; Quality Condition Breakdown</span>
              </div>
              <div className="table-responsive-wrapper" style={{ maxHeight: 250, overflowY: 'auto' }}>
                <table className="enterprise-data-table">
                  <thead>
                    <tr>
                      <th>SKU</th>
                      <th>Ordered</th>
                      <th style={{ width: 100 }}>Received</th>
                      <th style={{ width: 100, color: '#16a34a' }}>Accepted</th>
                      <th style={{ width: 90, color: '#dc2626' }}>Damaged</th>
                      <th style={{ width: 90, color: '#c2410c' }}>Quarantine</th>
                      <th style={{ width: 130 }}>Lot / Batch #</th>
                    </tr>
                  </thead>
                  <tbody>
                    {(selectedPo.items || []).map((poi: any) => {
                      const dist = grnItemDistributions[poi.id] || {
                        received: 0,
                        accepted: 0,
                        damaged: 0,
                        quarantined: 0,
                        rejected: 0,
                        lot_number: '',
                      };
                      return (
                        <tr key={poi.id}>
                          <td>
                            <div style={{ fontWeight: 600 }}>{poi.sku}</div>
                            <div style={{ fontSize: 11, color: '#64748b' }}>{poi.product_name}</div>
                          </td>
                          <td>
                            {poi.ordered_quantity}
                            <div style={{ fontSize: 10, color: '#64748b' }}>Prior: {poi.received_quantity || 0}</div>
                          </td>
                          <td>
                            <input
                              type="number"
                              min={0}
                              className="form-input"
                              style={{ height: 32, padding: '2px 6px' }}
                              value={dist.received}
                              onChange={(e) => {
                                const val = Math.max(0, parseInt(e.target.value) || 0);
                                setGrnItemDistributions((prev) => ({
                                  ...prev,
                                  [poi.id]: { ...dist, received: val, accepted: val, damaged: 0, quarantined: 0 },
                                }));
                              }}
                            />
                          </td>
                          <td>
                            <input
                              type="number"
                              min={0}
                              max={dist.received}
                              className="form-input"
                              style={{ height: 32, padding: '2px 6px', borderColor: '#bbf7d0' }}
                              value={dist.accepted}
                              onChange={(e) => {
                                const val = Math.max(0, parseInt(e.target.value) || 0);
                                setGrnItemDistributions((prev) => ({
                                  ...prev,
                                  [poi.id]: { ...dist, accepted: val },
                                }));
                              }}
                            />
                          </td>
                          <td>
                            <input
                              type="number"
                              min={0}
                              className="form-input"
                              style={{ height: 32, padding: '2px 6px', borderColor: '#fecaca' }}
                              value={dist.damaged}
                              onChange={(e) => {
                                const val = Math.max(0, parseInt(e.target.value) || 0);
                                setGrnItemDistributions((prev) => ({
                                  ...prev,
                                  [poi.id]: { ...dist, damaged: val },
                                }));
                              }}
                            />
                          </td>
                          <td>
                            <input
                              type="number"
                              min={0}
                              className="form-input"
                              style={{ height: 32, padding: '2px 6px', borderColor: '#fed7aa' }}
                              value={dist.quarantined}
                              onChange={(e) => {
                                const val = Math.max(0, parseInt(e.target.value) || 0);
                                setGrnItemDistributions((prev) => ({
                                  ...prev,
                                  [poi.id]: { ...dist, quarantined: val },
                                }));
                              }}
                            />
                          </td>
                          <td>
                            <input
                              type="text"
                              className="form-input"
                              style={{ height: 32, padding: '2px 6px' }}
                              value={dist.lot_number}
                              placeholder="LOT-XXX"
                              onChange={(e) => {
                                setGrnItemDistributions((prev) => ({
                                  ...prev,
                                  [poi.id]: { ...dist, lot_number: e.target.value },
                                }));
                              }}
                            />
                          </td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
            </div>

            <div className="procurement-modal-footer">
              <button onClick={() => setShowCreateGrnModal(false)} className="btn-outline" disabled={isSubmitting}>
                Cancel
              </button>
              <button onClick={handleSaveGrn} className="btn-primary" disabled={isSubmitting}>
                {isSubmitting ? 'Recording...' : 'Record Inbound GRN'}
              </button>
            </div>
          </div>
        </div>
      )}

      {/* ========================================================= */}
      {/* MODAL 6: VIEW GOODS RECEIVED NOTE DETAILS                 */}
      {/* ========================================================= */}
      {showViewGrnModal && selectedGrn && (
        <div
          className="modal-overlay"
          onClick={(e) => {
            if (e.target === e.currentTarget) setShowViewGrnModal(false);
          }}
        >
          <div className="procurement-modal" style={{ maxWidth: 860 }}>
            <div className="procurement-modal-header">
              <div>
                <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                  <h3 style={{ margin: 0, fontSize: 18, fontWeight: 800, color: '#0f172a' }}>
                    Goods Received Note {selectedGrn.grn_number}
                  </h3>
                  <span style={{
                    padding: '3px 8px',
                    borderRadius: 6,
                    fontSize: 10,
                    fontWeight: 700,
                    textTransform: 'uppercase',
                    backgroundColor: selectedGrn.status === 'posted' ? '#dcfce7' : '#ffedd5',
                    color: selectedGrn.status === 'posted' ? '#15803d' : '#c2410c',
                  }}>
                    {selectedGrn.status}
                  </span>
                </div>
                <div style={{ fontSize: 12, color: '#64748b', marginTop: 2 }}>
                  PO Reference: {selectedGrn.purchase_order?.po_number} &bull; Received {selectedGrn.received_at?.slice(0, 16).replace('T', ' ')}
                </div>
              </div>
              <button onClick={() => setShowViewGrnModal(false)} className="topbar-icon-button">
                <X size={18} />
              </button>
            </div>

            <div className="procurement-modal-body">
              <div className="procurement-meta-box">
                <div className="procurement-meta-item">
                  <strong>Supplier</strong>
                  <span>{selectedGrn.supplier_name}</span>
                </div>
                <div className="procurement-meta-item">
                  <strong>Delivery Note #</strong>
                  <span>{selectedGrn.supplier_delivery_note || 'N/A'}</span>
                </div>
                <div className="procurement-meta-item">
                  <strong>Carrier &amp; Tracking</strong>
                  <span>{selectedGrn.carrier || 'Freight Line'} ({selectedGrn.tracking_or_vehicle_number || 'N/A'})</span>
                </div>
                <div className="procurement-meta-item">
                  <strong>Receiving Dock</strong>
                  <span>{selectedGrn.receiving_location?.code || 'Inbound Dock'}</span>
                </div>
              </div>

              {/* Item Breakdown Table */}
              <div className="procurement-section-header">
                <Boxes size={14} color="#1e56ff" />
                <span>Physical Custody Matrix</span>
              </div>
              <div className="table-responsive-wrapper" style={{ maxHeight: 220, overflowY: 'auto' }}>
                <table className="enterprise-data-table">
                  <thead>
                    <tr>
                      <th>SKU &amp; Description</th>
                      <th>Ordered</th>
                      <th>Prior</th>
                      <th>Received</th>
                      <th style={{ color: '#15803d' }}>Accepted</th>
                      <th style={{ color: '#dc2626' }}>Damaged</th>
                      <th style={{ color: '#c2410c' }}>Quarantine</th>
                      <th>Lot / Batch</th>
                    </tr>
                  </thead>
                  <tbody>
                    {(selectedGrn.items || []).map((item: any) => (
                      <tr key={item.id}>
                        <td>
                          <div style={{ fontWeight: 600 }}>{item.sku}</div>
                          <div style={{ fontSize: 11, color: '#64748b' }}>{item.product_name}</div>
                        </td>
                        <td>{item.ordered_quantity}</td>
                        <td>{item.previously_received_quantity}</td>
                        <td><strong>{item.received_quantity}</strong></td>
                        <td style={{ color: '#15803d', fontWeight: 700 }}>{item.accepted_quantity}</td>
                        <td style={{ color: '#dc2626', fontWeight: 700 }}>{item.damaged_quantity}</td>
                        <td style={{ color: '#c2410c', fontWeight: 700 }}>{item.quarantined_quantity}</td>
                        <td>{item.lot_number || '-'}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>

            <div className="procurement-modal-footer">
              <button
                onClick={() => omsService.downloadGrnPdf(selectedGrn.id, `${selectedGrn.grn_number}.pdf`)}
                className="btn-outline"
                style={{ display: 'inline-flex', alignItems: 'center', gap: 6 }}
              >
                <Download size={14} />
                <span>Download Official PDF</span>
              </button>

              <div style={{ display: 'flex', gap: 8 }}>
                {selectedGrn.status !== 'posted' && (
                  <>
                    <button
                      onClick={() => {
                        setShowViewGrnModal(false);
                        openInspectGrn(selectedGrn);
                      }}
                      className="btn-outline"
                      style={{ display: 'inline-flex', alignItems: 'center', gap: 6 }}
                    >
                      <ClipboardCheck size={14} />
                      <span>Inspect &amp; Adjust</span>
                    </button>
                    <button
                      onClick={() => handlePostGrnToInventory(selectedGrn)}
                      className="btn-primary"
                      style={{ backgroundColor: '#059669', borderColor: '#059669', display: 'inline-flex', alignItems: 'center', gap: 6 }}
                      disabled={isSubmitting}
                    >
                      <Boxes size={14} />
                      <span>{isSubmitting ? 'Posting...' : 'Post to Authoritative Inventory'}</span>
                    </button>
                  </>
                )}
              </div>
            </div>
          </div>
        </div>
      )}

      {/* ========================================================= */}
      {/* MODAL 7: INSPECT / RECONCILE GRN                          */}
      {/* ========================================================= */}
      {showInspectGrnModal && selectedGrn && (
        <div
          className="modal-overlay"
          onClick={(e) => {
            if (e.target === e.currentTarget) setShowInspectGrnModal(false);
          }}
        >
          <div className="procurement-modal" style={{ maxWidth: 840 }}>
            <div className="procurement-modal-header">
              <div>
                <h3 style={{ margin: 0, fontSize: 18, fontWeight: 800, color: '#0f172a' }}>
                  Quality Inspection &amp; Condition Reconciliation: GRN #{selectedGrn.grn_number}
                </h3>
                <p style={{ margin: '3px 0 0 0', fontSize: 12, color: '#64748b' }}>
                  Adjust condition quantities following laboratory/QC inspection.
                </p>
              </div>
              <button onClick={() => setShowInspectGrnModal(false)} className="topbar-icon-button">
                <X size={18} />
              </button>
            </div>

            <div className="procurement-modal-body">
              <div className="table-responsive-wrapper" style={{ maxHeight: 260, overflowY: 'auto' }}>
                <table className="enterprise-data-table">
                  <thead>
                    <tr>
                      <th>SKU</th>
                      <th>Total Received</th>
                      <th style={{ width: 100, color: '#15803d' }}>Accepted</th>
                      <th style={{ width: 90, color: '#dc2626' }}>Damaged</th>
                      <th style={{ width: 90, color: '#c2410c' }}>Quarantined</th>
                      <th style={{ width: 130 }}>Lot / Batch #</th>
                    </tr>
                  </thead>
                  <tbody>
                    {inspectItems.map((item, idx) => (
                      <tr key={item.id}>
                        <td>
                          <div style={{ fontWeight: 600 }}>{item.sku}</div>
                          <div style={{ fontSize: 10, color: '#64748b' }}>{item.product_name}</div>
                        </td>
                        <td><strong>{item.received_quantity}</strong></td>
                        <td>
                          <input
                            type="number"
                            min={0}
                            className="form-input"
                            style={{ height: 32, padding: '2px 6px', borderColor: '#bbf7d0' }}
                            value={item.accepted_quantity}
                            onChange={(e) => {
                              const val = Math.max(0, parseInt(e.target.value) || 0);
                              setInspectItems((prev) => prev.map((x, i) => (i === idx ? { ...x, accepted_quantity: val } : x)));
                            }}
                          />
                        </td>
                        <td>
                          <input
                            type="number"
                            min={0}
                            className="form-input"
                            style={{ height: 32, padding: '2px 6px', borderColor: '#fecaca' }}
                            value={item.damaged_quantity}
                            onChange={(e) => {
                              const val = Math.max(0, parseInt(e.target.value) || 0);
                              setInspectItems((prev) => prev.map((x, i) => (i === idx ? { ...x, damaged_quantity: val } : x)));
                            }}
                          />
                        </td>
                        <td>
                          <input
                            type="number"
                            min={0}
                            className="form-input"
                            style={{ height: 32, padding: '2px 6px', borderColor: '#fed7aa' }}
                            value={item.quarantined_quantity}
                            onChange={(e) => {
                              const val = Math.max(0, parseInt(e.target.value) || 0);
                              setInspectItems((prev) => prev.map((x, i) => (i === idx ? { ...x, quarantined_quantity: val } : x)));
                            }}
                          />
                        </td>
                        <td>
                          <input
                            type="text"
                            className="form-input"
                            style={{ height: 32, padding: '2px 6px' }}
                            value={item.lot_number || ''}
                            onChange={(e) => {
                              setInspectItems((prev) => prev.map((x, i) => (i === idx ? { ...x, lot_number: e.target.value } : x)));
                            }}
                          />
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>

            <div className="procurement-modal-footer">
              <button onClick={() => setShowInspectGrnModal(false)} className="btn-outline" disabled={isSubmitting}>
                Cancel
              </button>
              <button onClick={handleSaveInspection} className="btn-primary" disabled={isSubmitting}>
                {isSubmitting ? 'Saving...' : 'Record Inspection'}
              </button>
            </div>
          </div>
        </div>
      )}

      {/* ========================================================= */}
      {/* MODAL 8: STANDALONE PURCHASE ORDER CREATION               */}
      {/* ========================================================= */}
      {showCreatePoModal && (
        <div
          className="modal-overlay"
          onClick={(e) => {
            if (e.target === e.currentTarget) setShowCreatePoModal(false);
          }}
        >
          <div className="procurement-modal" style={{ maxWidth: 840 }}>
            <div className="procurement-modal-header">
              <div>
                <h3 style={{ margin: 0, fontSize: 18, fontWeight: 800, color: '#0f172a' }}>
                  Create Purchase Order
                </h3>
                <p style={{ margin: '3px 0 0 0', fontSize: 12, color: '#64748b' }}>
                  Issue an official procurement order directly to an authorized vendor.
                </p>
              </div>
              <button onClick={() => setShowCreatePoModal(false)} className="topbar-icon-button">
                <X size={18} />
              </button>
            </div>

            <div className="procurement-modal-body">
              <div className="finovo-form-grid-2" style={{ marginBottom: 16 }}>
                <div>
                  <label className="form-label">Supplier / Vendor Name *</label>
                  <input
                    type="text"
                    className="form-input"
                    value={poSupplier}
                    onChange={(e) => setPoSupplier(e.target.value)}
                    placeholder="e.g. Acme Industrial Supplies Ltd"
                  />
                </div>

                <div>
                  <label className="form-label">Destination Warehouse *</label>
                  <CustomDropdown
                    value={String(poWhId)}
                    onChange={(val) => setPoWhId(Number(val))}
                    options={warehouses.map((w) => ({ value: String(w.id), label: `${w.name} (${w.code})` }))}
                  />
                </div>
              </div>

              {/* Line Items */}
              <div style={{ borderTop: '1px solid #e2e8f0', paddingTop: 14 }}>
                <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 12, flexWrap: 'wrap', gap: 10 }}>
                  <div className="procurement-section-header" style={{ margin: 0 }}>
                    <Layers size={14} color="#1e56ff" />
                    <span>Order Line Items</span>
                  </div>

                  <div style={{ width: 290 }}>
                    <CustomDropdown
                      value=""
                      searchable={true}
                      onChange={(val) => {
                        const v = allVariants.find((x) => x.id === Number(val));
                        if (v && !poStandaloneItems.some((x) => x.variant_id === v.id)) {
                          setPoStandaloneItems((prev) => [
                            ...prev,
                            {
                              variant_id: v.id,
                              sku: v.sku,
                              name: v.name,
                              qty: 10,
                              unit_cost: v.price,
                              uom: v.uom,
                            },
                          ]);
                        }
                      }}
                      placeholder="+ Add Product Variant..."
                      options={variantOptions}
                      searchPlaceholder="Search product or SKU..."
                      menuMinWidth={420}
                    />
                  </div>
                </div>

                <div className="table-responsive-wrapper" style={{ maxHeight: 200, overflowY: 'auto' }}>
                  <table className="enterprise-data-table">
                    <thead>
                      <tr>
                        <th>SKU &amp; Name</th>
                        <th style={{ width: 120 }}>Quantity</th>
                        <th style={{ width: 130 }}>Unit Cost ($)</th>
                        <th style={{ width: 120, textAlign: 'right' }}>Total Cost</th>
                        <th style={{ width: 36 }}></th>
                      </tr>
                    </thead>
                    <tbody>
                      {poStandaloneItems.map((item, idx) => (
                        <tr key={item.variant_id}>
                          <td>
                            <div style={{ fontWeight: 600 }}>{item.sku}</div>
                            <div style={{ fontSize: 11, color: '#64748b' }}>{item.name}</div>
                          </td>
                          <td>
                            <input
                              type="number"
                              min={1}
                              className="form-input"
                              style={{ height: 32, padding: '4px 8px' }}
                              value={item.qty}
                              onChange={(e) => {
                                const val = Math.max(1, parseInt(e.target.value) || 1);
                                setPoStandaloneItems((prev) => prev.map((x, i) => (i === idx ? { ...x, qty: val } : x)));
                              }}
                            />
                          </td>
                          <td>
                            <input
                              type="number"
                              min={0}
                              step="0.01"
                              className="form-input"
                              style={{ height: 32, padding: '4px 8px' }}
                              value={item.unit_cost}
                              onChange={(e) => {
                                const val = Math.max(0, parseFloat(e.target.value) || 0);
                                setPoStandaloneItems((prev) => prev.map((x, i) => (i === idx ? { ...x, unit_cost: val } : x)));
                              }}
                            />
                          </td>
                          <td style={{ textAlign: 'right', fontWeight: 700 }}>
                            ${(item.qty * item.unit_cost).toFixed(2)}
                          </td>
                          <td>
                            <button
                              onClick={() => setPoStandaloneItems((prev) => prev.filter((_, i) => i !== idx))}
                              style={{ background: 'none', border: 'none', color: '#dc2626', cursor: 'pointer' }}
                            >
                              <X size={15} />
                            </button>
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </div>
            </div>

            <div className="procurement-modal-footer">
              <div>
                <span style={{ fontSize: 13, color: '#64748b' }}>Total Order Cost: </span>
                <span style={{ fontSize: 18, fontWeight: 800, color: '#0f172a' }}>
                  ${poStandaloneItems.reduce((acc, x) => acc + x.qty * x.unit_cost, 0).toFixed(2)}
                </span>
              </div>

              <div style={{ display: 'flex', gap: 8 }}>
                <button onClick={() => setShowCreatePoModal(false)} className="btn-outline" disabled={isSubmitting}>
                  Cancel
                </button>
                <button onClick={handleCreatePo} className="btn-primary" disabled={isSubmitting}>
                  {isSubmitting ? 'Issuing...' : 'Issue Purchase Order'}
                </button>
              </div>
            </div>
          </div>
        </div>
      )}

      {/* ========================================================= */}
      {/* MODAL 9: VIEW PURCHASE ORDER DETAILS                      */}
      {/* ========================================================= */}
      {showViewPoModal && selectedPo && (
        <div
          className="modal-overlay"
          onClick={(e) => {
            if (e.target === e.currentTarget) setShowViewPoModal(false);
          }}
        >
          <div className="procurement-modal" style={{ maxWidth: 800 }}>
            <div className="procurement-modal-header">
              <div>
                <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                  <h3 style={{ margin: 0, fontSize: 18, fontWeight: 800, color: '#0f172a' }}>
                    Purchase Order {selectedPo.po_number}
                  </h3>
                  <span style={{
                    padding: '3px 8px',
                    borderRadius: 6,
                    fontSize: 10,
                    fontWeight: 700,
                    textTransform: 'uppercase',
                    backgroundColor: '#dbeafe',
                    color: '#1e40af',
                  }}>
                    {selectedPo.status}
                  </span>
                </div>
                <div style={{ fontSize: 12, color: '#64748b', marginTop: 2 }}>
                  Supplier: {selectedPo.supplier_name} &bull; Ordered {(selectedPo.order_date || selectedPo.created_at)?.slice(0, 10)}
                </div>
              </div>
              <button onClick={() => setShowViewPoModal(false)} className="topbar-icon-button">
                <X size={18} />
              </button>
            </div>

            <div className="procurement-modal-body">
              <div className="procurement-meta-box">
                <div className="procurement-meta-item">
                  <strong>Warehouse</strong>
                  <span>{selectedPo.warehouse?.name}</span>
                </div>
                <div className="procurement-meta-item">
                  <strong>Payment Terms</strong>
                  <span>{selectedPo.payment_terms || 'Net 30'}</span>
                </div>
                <div className="procurement-meta-item">
                  <strong>Total Value</strong>
                  <span>${Number(selectedPo.total_amount || 0).toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
                </div>
                <div className="procurement-meta-item">
                  <strong>Created By</strong>
                  <span>{selectedPo.creator?.name || 'Authorized Buyer'}</span>
                </div>
              </div>

              {/* Items Table */}
              <div className="procurement-section-header">
                <Layers size={14} color="#1e56ff" />
                <span>PO Line Items &amp; Inbound Fulfillment</span>
              </div>
              <div className="table-responsive-wrapper" style={{ maxHeight: 220, overflowY: 'auto' }}>
                <table className="enterprise-data-table">
                  <thead>
                    <tr>
                      <th>SKU &amp; Description</th>
                      <th>Ordered</th>
                      <th>Received</th>
                      <th>Outstanding</th>
                      <th style={{ textAlign: 'right' }}>Unit Cost</th>
                      <th style={{ textAlign: 'right' }}>Total</th>
                    </tr>
                  </thead>
                  <tbody>
                    {(selectedPo.items || []).map((item: any) => (
                      <tr key={item.id}>
                        <td>
                          <div style={{ fontWeight: 600 }}>{item.sku}</div>
                          <div style={{ fontSize: 11, color: '#64748b' }}>{item.product_name}</div>
                        </td>
                        <td>{item.ordered_quantity}</td>
                        <td><strong style={{ color: '#16a34a' }}>{item.received_quantity || 0}</strong></td>
                        <td>
                          <span style={{ color: '#2563eb', fontWeight: 600 }}>
                            {Math.max(0, item.ordered_quantity - (item.received_quantity || 0))}
                          </span>
                        </td>
                        <td style={{ textAlign: 'right' }}>${Number(item.unit_cost || 0).toFixed(2)}</td>
                        <td style={{ textAlign: 'right', fontWeight: 700 }}>
                          ${Number(item.total_cost || (item.ordered_quantity * item.unit_cost)).toFixed(2)}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>

            <div className="procurement-modal-footer">
              <button onClick={() => setShowViewPoModal(false)} className="btn-outline">
                Close
              </button>
              {selectedPo.status !== 'received' && selectedPo.status !== 'cancelled' && (
                <button
                  onClick={() => {
                    setShowViewPoModal(false);
                    openCreateGrnFromPo(selectedPo);
                  }}
                  className="btn-primary"
                  style={{ display: 'inline-flex', alignItems: 'center', gap: 6 }}
                >
                  <Truck size={14} />
                  <span>Receive Goods (GRN)</span>
                </button>
              )}
            </div>
          </div>
        </div>
      )}

      {showSelectPoForGrnModal && (
        <div
          className="modal-overlay"
          onClick={(e) => {
            if (e.target === e.currentTarget) setShowSelectPoForGrnModal(false);
          }}
        >
          <div className="procurement-modal" style={{ maxWidth: 640 }}>
            <div className="procurement-modal-header">
              <div>
                <h3 style={{ margin: 0, fontSize: 18, fontWeight: 800, color: '#0f172a' }}>
                  Select Purchase Order to Receive
                </h3>
                <p style={{ margin: '3px 0 0 0', fontSize: 12, color: '#64748b' }}>
                  Choose an active purchase order that has arrived at your warehouse receiving dock.
                </p>
              </div>
              <button
                onClick={() => setShowSelectPoForGrnModal(false)}
                className="topbar-icon-button"
              >
                <X size={18} />
              </button>
            </div>

            <div className="procurement-modal-body">
              <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
                {purchaseOrders
                  .filter((po) => po.status !== 'received' && po.status !== 'cancelled')
                  .map((po) => {
                    const totalQty = (po.items || []).reduce(
                      (acc: number, x: any) => acc + (x.ordered_quantity || 0),
                      0
                    );
                    const recQty = (po.items || []).reduce(
                      (acc: number, x: any) => acc + (x.received_quantity || 0),
                      0
                    );
                    const outstanding = Math.max(0, totalQty - recQty);

                    return (
                      <div
                        key={po.id}
                        style={{
                          display: 'flex',
                          alignItems: 'center',
                          justifyContent: 'space-between',
                          padding: '12px 16px',
                          border: '1px solid #e2e8f0',
                          borderRadius: 12,
                          backgroundColor: '#f8fafc',
                          transition: 'all 0.15s ease',
                          cursor: 'pointer',
                        }}
                        onMouseEnter={(e) => {
                          e.currentTarget.style.borderColor = '#1e56ff';
                          e.currentTarget.style.backgroundColor = '#eff6ff';
                        }}
                        onMouseLeave={(e) => {
                          e.currentTarget.style.borderColor = '#e2e8f0';
                          e.currentTarget.style.backgroundColor = '#f8fafc';
                        }}
                        onClick={() => {
                          setShowSelectPoForGrnModal(false);
                          openCreateGrnFromPo(po);
                        }}
                      >
                        <div>
                          <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                            <span style={{ fontWeight: 700, color: '#1e56ff', fontSize: 14 }}>
                              {po.po_number}
                            </span>
                            <span
                              style={{
                                fontSize: 10,
                                fontWeight: 700,
                                textTransform: 'uppercase',
                                padding: '2px 6px',
                                borderRadius: 4,
                                backgroundColor: po.status === 'partially_received' ? '#ffedd5' : '#dbeafe',
                                color: po.status === 'partially_received' ? '#9a3412' : '#1e40af',
                              }}
                            >
                              {po.status?.replace('_', ' ')}
                            </span>
                          </div>
                          <div style={{ fontSize: 12, color: '#475569', marginTop: 3 }}>
                            <strong>Supplier:</strong> {po.supplier_name} &bull;{' '}
                            <strong>Warehouse:</strong> {po.warehouse?.name}
                          </div>
                        </div>

                        <div style={{ textAlign: 'right' }}>
                          <div style={{ fontSize: 13, fontWeight: 700, color: '#0f172a' }}>
                            {outstanding} pending units
                          </div>
                          <div style={{ fontSize: 11, color: '#64748b' }}>
                            {recQty} / {totalQty} received
                          </div>
                        </div>
                      </div>
                    );
                  })}
              </div>
            </div>

            <div className="procurement-modal-footer">
              <button
                onClick={() => setShowSelectPoForGrnModal(false)}
                className="btn-outline"
              >
                Cancel
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};

export default PurchaseOrdersPage;
