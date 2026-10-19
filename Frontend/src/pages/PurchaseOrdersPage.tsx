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
  Loader2
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
      setErrorBanner(e.response?.data?.message || 'Failed to fetch purchase orders.');
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
      setErrorBanner(e.response?.data?.message || 'Failed to fetch purchase requisitions.');
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
      setErrorBanner(e.response?.data?.message || 'Failed to fetch goods received notes.');
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
            name: `${p.name} (${v.sku})`,
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
      showNotification(e.response?.data?.message || 'Failed to save requisition.', true);
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
      showNotification(e.response?.data?.message || 'Failed to load requisition details.', true);
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
      showNotification(e.response?.data?.message || 'Failed to submit requisition.', true);
    }
  };

  const handleApprovePr = async (pr: any) => {
    // Front-end warning if self-approving
    if (pr.requester_id === user?.id) {
      showNotification(
        'Separation of Duties Warning: You requested this requisition and cannot approve it. An independent manager must review and approve.',
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
      showNotification(e.response?.data?.message || 'Failed to approve requisition.', true);
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
      showNotification(e.response?.data?.message || 'Failed to reject requisition.', true);
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
    pr.items.forEach((item: any) => {
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
      showNotification(e.response?.data?.message || 'Failed to convert PR to Purchase Order.', true);
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
      showNotification('Please fill in all required fields and add items.', true);
      return;
    }

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
      showNotification(e.response?.data?.message || 'Failed to create PO.', true);
    }
  };

  const handleViewPo = async (id: number) => {
    try {
      const res = await omsService.getPurchaseOrder(id);
      setSelectedPo(res.data);
      setShowViewPoModal(true);
    } catch (e: any) {
      showNotification(e.response?.data?.message || 'Failed to load PO details.', true);
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

  const handleSaveGrn = async () => {
    if (!selectedPo) return;

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
      showNotification(e.response?.data?.message || 'Failed to create GRN.', true);
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

    try {
      const res = await omsService.postGrnToInventory(grn.id);
      showNotification(`GRN #${res.data?.grn_number || ''} successfully posted! Stock balances and ledger updated.`);
      fetchGrns();
      fetchPurchaseOrders();
      if (selectedGrn && selectedGrn.id === grn.id) {
        handleViewGrn(grn.id);
      }
    } catch (e: any) {
      showNotification(e.response?.data?.message || 'Failed to post GRN to inventory.', true);
    }
  };

  const handleViewGrn = async (id: number) => {
    try {
      const res = await omsService.getGoodsReceivedNote(id);
      setSelectedGrn(res.data);
      setShowViewGrnModal(true);
    } catch (e: any) {
      showNotification(e.response?.data?.message || 'Failed to load GRN details.', true);
    }
  };

  return (
    <div className="page-container" style={{ paddingBottom: 60 }}>
      {/* Breadcrumb Navigation */}
      <nav className="breadcrumb-nav">
        <span>Finovo</span>
        <span>&gt;</span>
        <span style={{ color: '#0f172a', fontWeight: 600 }}>Procurement &amp; Receiving</span>
      </nav>

      {/* Header Banner */}
      <div className="page-header-row">
        <div className="page-title-section">
          <div className="page-title-badge-wrap">
            <h1 className="page-title">Procurement &amp; Inbound Hub</h1>
            <span className="page-count-pill" style={{ background: '#dbeafe', color: '#1e40af' }}>
              Enterprise Grade
            </span>
          </div>
          <p className="page-subtitle">
            Manage Purchase Requisitions (PR), supplier commitments (PO), dock receiving &amp; inspection (GRN), and authoritative inventory ledger posting.
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
          borderRadius: 8,
          padding: '12px 16px',
          marginBottom: 16,
          display: 'flex',
          alignItems: 'center',
          gap: 12,
          color: '#b91c1c',
          fontSize: 13,
          fontWeight: 500,
        }}>
          <AlertCircle size={18} />
          <span>{errorBanner}</span>
        </div>
      )}

      {successBanner && (
        <div style={{
          backgroundColor: '#f0fdf4',
          border: '1px solid #bbf7d0',
          borderRadius: 8,
          padding: '12px 16px',
          marginBottom: 16,
          display: 'flex',
          alignItems: 'center',
          gap: 12,
          color: '#15803d',
          fontSize: 13,
          fontWeight: 500,
        }}>
          <CheckCircle2 size={18} />
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
        flexWrap: 'wrap'
      }}>
        <div style={{ display: 'flex', gap: 4 }}>
          <button
            onClick={() => setActiveTab('po')}
            style={{
              display: 'flex',
              alignItems: 'center',
              gap: 8,
              padding: '10px 18px',
              fontSize: 14,
              fontWeight: activeTab === 'po' ? 700 : 500,
              color: activeTab === 'po' ? '#1e56ff' : '#64748b',
              borderBottom: activeTab === 'po' ? '2px solid #1e56ff' : '2px solid transparent',
              background: 'none',
              borderTop: 'none',
              borderLeft: 'none',
              borderRight: 'none',
              cursor: 'pointer',
            }}
          >
            <FileSpreadsheet size={16} />
            <span>Purchase Orders</span>
            <span style={{
              background: activeTab === 'po' ? '#dbeafe' : '#f1f5f9',
              color: activeTab === 'po' ? '#1e40af' : '#64748b',
              fontSize: 11,
              fontWeight: 700,
              padding: '2px 6px',
              borderRadius: 12,
            }}>
              {poKpis.total || purchaseOrders.length}
            </span>
          </button>

          <button
            onClick={() => setActiveTab('pr')}
            style={{
              display: 'flex',
              alignItems: 'center',
              gap: 8,
              padding: '10px 18px',
              fontSize: 14,
              fontWeight: activeTab === 'pr' ? 700 : 500,
              color: activeTab === 'pr' ? '#1e56ff' : '#64748b',
              borderBottom: activeTab === 'pr' ? '2px solid #1e56ff' : '2px solid transparent',
              background: 'none',
              borderTop: 'none',
              borderLeft: 'none',
              borderRight: 'none',
              cursor: 'pointer',
            }}
          >
            <ClipboardCheck size={16} />
            <span>Purchase Requisitions (PR)</span>
            <span style={{
              background: activeTab === 'pr' ? '#dbeafe' : '#f1f5f9',
              color: activeTab === 'pr' ? '#1e40af' : '#64748b',
              fontSize: 11,
              fontWeight: 700,
              padding: '2px 6px',
              borderRadius: 12,
            }}>
              {prKpis.total || requisitions.length}
            </span>
          </button>

          <button
            onClick={() => setActiveTab('grn')}
            style={{
              display: 'flex',
              alignItems: 'center',
              gap: 8,
              padding: '10px 18px',
              fontSize: 14,
              fontWeight: activeTab === 'grn' ? 700 : 500,
              color: activeTab === 'grn' ? '#1e56ff' : '#64748b',
              borderBottom: activeTab === 'grn' ? '2px solid #1e56ff' : '2px solid transparent',
              background: 'none',
              borderTop: 'none',
              borderLeft: 'none',
              borderRight: 'none',
              cursor: 'pointer',
            }}
          >
            <PackageCheck size={16} />
            <span>Goods Received Notes (GRN)</span>
            <span style={{
              background: activeTab === 'grn' ? '#dbeafe' : '#f1f5f9',
              color: activeTab === 'grn' ? '#1e40af' : '#64748b',
              fontSize: 11,
              fontWeight: 700,
              padding: '2px 6px',
              borderRadius: 12,
            }}>
              {grnKpis.total || grns.length}
            </span>
          </button>
        </div>

        {/* Primary Action Button according to tab */}
        <div>
          {activeTab === 'po' && (
            <button
              onClick={openCreatePoModal}
              className="btn-primary"
              style={{ display: 'flex', alignItems: 'center', gap: 6, padding: '8px 16px' }}
            >
              <Plus size={16} />
              <span>Create Purchase Order</span>
            </button>
          )}

          {activeTab === 'pr' && (
            <button
              onClick={openCreatePrModal}
              className="btn-primary"
              style={{ display: 'flex', alignItems: 'center', gap: 6, padding: '8px 16px' }}
            >
              <Plus size={16} />
              <span>New Requisition</span>
            </button>
          )}

          {activeTab === 'grn' && (
            <button
              onClick={() => {
                if (purchaseOrders.length > 0) {
                  openCreateGrnFromPo(purchaseOrders[0]);
                } else {
                  showNotification('Please issue a Purchase Order first before receiving a shipment.', true);
                }
              }}
              className="btn-primary"
              style={{ display: 'flex', alignItems: 'center', gap: 6, padding: '8px 16px' }}
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
            <div style={{ display: 'flex', alignItems: 'center', gap: 8, flex: 1, minWidth: 260 }}>
              <div style={{ position: 'relative', width: '100%', maxWidth: 320 }}>
                <Search size={16} style={{ position: 'absolute', left: 12, top: 10, color: '#94a3b8' }} />
                <input
                  type="text"
                  placeholder="Search PO # or supplier..."
                  value={poSearch}
                  onChange={(e) => setPoSearch(e.target.value)}
                  onKeyDown={(e) => e.key === 'Enter' && fetchPurchaseOrders()}
                  className="form-input"
                  style={{ paddingLeft: 36, height: 36, fontSize: 13 }}
                />
              </div>

              <div style={{ width: 170 }}>
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

            <span style={{ fontSize: 12, color: '#64748b' }}>
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
                    <th>Items</th>
                    <th>Total Amount</th>
                    <th>Status</th>
                    <th style={{ textAlign: 'right' }}>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  {isLoading ? (
                    <tr>
                      <td colSpan={8} style={{ textAlign: 'center', padding: '48px 16px', color: '#1e56ff' }}>
                        <Loader2 className="animate-spin" size={24} style={{ margin: '0 auto 8px' }} />
                        <div style={{ fontSize: 13, color: '#64748b' }}>Loading purchase orders...</div>
                      </td>
                    </tr>
                  ) : purchaseOrders.length === 0 ? (
                    <tr>
                      <td colSpan={8} style={{ textAlign: 'center', padding: '48px 16px', color: '#64748b' }}>
                        <FileSpreadsheet size={36} color="#cbd5e1" style={{ marginBottom: 12 }} />
                        <div style={{ fontWeight: 600, fontSize: 14, color: '#1e293b' }}>No Purchase Orders Found</div>
                        <div style={{ fontSize: 12, marginTop: 4 }}>
                          Create a new purchase order or convert an approved Purchase Requisition.
                        </div>
                      </td>
                    </tr>
                  ) : (
                    purchaseOrders.map((po) => {
                      const totalQty = (po.items || []).reduce((acc: number, x: any) => acc + (x.ordered_quantity || 0), 0);
                      const recQty = (po.items || []).reduce((acc: number, x: any) => acc + (x.received_quantity || 0), 0);

                      let badgeColor = '#64748b';
                      let badgeBg = '#f1f5f9';
                      if (po.status === 'issued') { badgeColor = '#2563eb'; badgeBg = '#dbeafe'; }
                      else if (po.status === 'partially_received') { badgeColor = '#d97706'; badgeBg = '#fef3c7'; }
                      else if (po.status === 'received') { badgeColor = '#16a34a'; badgeBg = '#dcfce7'; }
                      else if (po.status === 'cancelled') { badgeColor = '#dc2626'; badgeBg = '#fee2e2'; }

                      return (
                        <tr key={po.id}>
                          <td>
                            <span style={{ fontWeight: 700, color: '#1e56ff' }}>{po.po_number}</span>
                            {po.requisition && (
                              <div style={{ fontSize: 10, color: '#64748b' }}>From {po.requisition.pr_number}</div>
                            )}
                          </td>
                          <td>
                            <div style={{ fontWeight: 600, color: '#0f172a' }}>{po.supplier_name}</div>
                            <div style={{ fontSize: 11, color: '#64748b' }}>{po.payment_terms || 'Net 30'}</div>
                          </td>
                          <td>
                            <div style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: 12 }}>
                              <Building size={14} color="#64748b" />
                              <span>{po.warehouse?.name || 'Warehouse'}</span>
                            </div>
                          </td>
                          <td>
                            <span style={{ fontSize: 12 }}>{po.order_date || po.created_at?.slice(0, 10)}</span>
                          </td>
                          <td>
                            <div style={{ fontSize: 12, fontWeight: 600 }}>{recQty} / {totalQty} units</div>
                            <div style={{ fontSize: 10, color: '#64748b' }}>{(po.items || []).length} SKUs</div>
                          </td>
                          <td>
                            <span style={{ fontWeight: 700, fontSize: 13, color: '#0f172a' }}>
                              ${Number(po.total_amount || 0).toLocaleString(undefined, { minimumFractionDigits: 2 })}
                            </span>
                          </td>
                          <td>
                            <span style={{
                              display: 'inline-block',
                              padding: '3px 8px',
                              borderRadius: 4,
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
                                  className="btn-primary"
                                  style={{ padding: '4px 10px', fontSize: 11, display: 'inline-flex', alignItems: 'center', gap: 4 }}
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
            <div style={{ display: 'flex', alignItems: 'center', gap: 8, flex: 1, minWidth: 260 }}>
              <div style={{ position: 'relative', width: '100%', maxWidth: 320 }}>
                <Search size={16} style={{ position: 'absolute', left: 12, top: 10, color: '#94a3b8' }} />
                <input
                  type="text"
                  placeholder="Search PR # or purpose..."
                  value={prSearch}
                  onChange={(e) => setPrSearch(e.target.value)}
                  onKeyDown={(e) => e.key === 'Enter' && fetchRequisitions()}
                  className="form-input"
                  style={{ paddingLeft: 36, height: 36, fontSize: 13 }}
                />
              </div>

              <div style={{ width: 170 }}>
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

            <span style={{ fontSize: 12, color: '#64748b' }}>
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
                  {requisitions.length === 0 ? (
                    <tr>
                      <td colSpan={8} style={{ textAlign: 'center', padding: '48px 16px', color: '#64748b' }}>
                        <ClipboardCheck size={36} color="#cbd5e1" style={{ marginBottom: 12 }} />
                        <div style={{ fontWeight: 600, fontSize: 14, color: '#1e293b' }}>No Purchase Requisitions Found</div>
                        <div style={{ fontSize: 12, marginTop: 4 }}>
                          Employees can submit purchase requisitions for management authorization.
                        </div>
                      </td>
                    </tr>
                  ) : (
                    requisitions.map((pr) => {
                      let badgeColor = '#64748b';
                      let badgeBg = '#f1f5f9';
                      if (pr.status === 'submitted') { badgeColor = '#d97706'; badgeBg = '#fef3c7'; }
                      else if (pr.status === 'approved') { badgeColor = '#16a34a'; badgeBg = '#dcfce7'; }
                      else if (pr.status === 'partially_ordered' || pr.status === 'ordered') { badgeColor = '#2563eb'; badgeBg = '#dbeafe'; }
                      else if (pr.status === 'rejected') { badgeColor = '#dc2626'; badgeBg = '#fee2e2'; }

                      return (
                        <tr key={pr.id}>
                          <td>
                            <span style={{ fontWeight: 700, color: '#1e56ff' }}>{pr.pr_number}</span>
                            <div style={{ fontSize: 10, color: '#64748b' }}>{pr.created_at?.slice(0, 10)}</div>
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
                              <span>{pr.warehouse?.name || 'Warehouse'}</span>
                            </div>
                          </td>
                          <td>
                            <span style={{
                              display: 'inline-block',
                              padding: '2px 6px',
                              borderRadius: 4,
                              fontSize: 10,
                              fontWeight: 700,
                              textTransform: 'uppercase',
                              backgroundColor: pr.priority === 'urgent' ? '#fee2e2' : (pr.priority === 'high' ? '#fef3c7' : '#f1f5f9'),
                              color: pr.priority === 'urgent' ? '#dc2626' : (pr.priority === 'high' ? '#b45309' : '#475569'),
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
                              padding: '3px 8px',
                              borderRadius: 4,
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
                                  className="btn-primary"
                                  style={{ padding: '4px 10px', fontSize: 11 }}
                                >
                                  Submit
                                </button>
                              )}

                              {pr.status === 'submitted' && (
                                <button
                                  onClick={() => handleApprovePr(pr)}
                                  className="btn-primary"
                                  style={{ padding: '4px 10px', fontSize: 11, backgroundColor: '#16a34a', borderColor: '#16a34a' }}
                                >
                                  Approve
                                </button>
                              )}

                              {(pr.status === 'approved' || pr.status === 'partially_ordered') && (
                                <button
                                  onClick={() => openConvertPoModal(pr)}
                                  className="btn-primary"
                                  style={{ padding: '4px 10px', fontSize: 11 }}
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
            <div style={{ display: 'flex', alignItems: 'center', gap: 8, flex: 1, minWidth: 260 }}>
              <div style={{ position: 'relative', width: '100%', maxWidth: 320 }}>
                <Search size={16} style={{ position: 'absolute', left: 12, top: 10, color: '#94a3b8' }} />
                <input
                  type="text"
                  placeholder="Search GRN # or carrier..."
                  value={grnSearch}
                  onChange={(e) => setGrnSearch(e.target.value)}
                  onKeyDown={(e) => e.key === 'Enter' && fetchGrns()}
                  className="form-input"
                  style={{ paddingLeft: 36, height: 36, fontSize: 13 }}
                />
              </div>

              <div style={{ width: 170 }}>
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

            <span style={{ fontSize: 12, color: '#64748b' }}>
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
                  {grns.length === 0 ? (
                    <tr>
                      <td colSpan={8} style={{ textAlign: 'center', padding: '48px 16px', color: '#64748b' }}>
                        <PackageCheck size={36} color="#cbd5e1" style={{ marginBottom: 12 }} />
                        <div style={{ fontWeight: 600, fontSize: 14, color: '#1e293b' }}>No Goods Received Notes</div>
                        <div style={{ fontSize: 12, marginTop: 4 }}>
                          Receive inbound shipments against Purchase Orders to record physical inventory.
                        </div>
                      </td>
                    </tr>
                  ) : (
                    grns.map((grn) => {
                      let badgeColor = '#64748b';
                      let badgeBg = '#f1f5f9';
                      if (grn.status === 'inspected') { badgeColor = '#d97706'; badgeBg = '#fef3c7'; }
                      else if (grn.status === 'posted') { badgeColor = '#16a34a'; badgeBg = '#dcfce7'; }

                      return (
                        <tr key={grn.id}>
                          <td>
                            <span style={{ fontWeight: 700, color: '#1e56ff' }}>{grn.grn_number}</span>
                            {grn.supplier_delivery_note && (
                              <div style={{ fontSize: 10, color: '#64748b' }}>Note: {grn.supplier_delivery_note}</div>
                            )}
                          </td>
                          <td>
                            <div style={{ fontWeight: 600, color: '#0f172a' }}>{grn.purchase_order?.po_number}</div>
                            <div style={{ fontSize: 10, color: '#64748b' }}>{grn.purchase_order?.supplier_name}</div>
                          </td>
                          <td>
                            <div style={{ fontSize: 12, fontWeight: 500 }}>{grn.carrier || 'Freight Line'}</div>
                            <div style={{ fontSize: 10, color: '#64748b' }}>{grn.tracking_or_vehicle_number || 'N/A'}</div>
                          </td>
                          <td>
                            <div style={{ fontSize: 12, fontWeight: 600 }}>{grn.warehouse?.name}</div>
                            <div style={{ fontSize: 10, color: '#0284c7' }}>{grn.receiving_location?.code || 'Inbound Dock'}</div>
                          </td>
                          <td>
                            <div style={{ fontSize: 12, fontWeight: 700 }}>
                              Total: {grn.total_received_units} units
                            </div>
                            <div style={{ display: 'flex', gap: 6, fontSize: 10, marginTop: 2 }}>
                              <span style={{ color: '#16a34a', fontWeight: 600 }}>Good: {grn.total_accepted_units}</span>
                              <span style={{ color: '#dc2626', fontWeight: 600 }}>Damaged: {grn.total_damaged_units}</span>
                              <span style={{ color: '#d97706', fontWeight: 600 }}>Quar: {grn.total_quarantined_units}</span>
                            </div>
                          </td>
                          <td>
                            <span style={{ fontSize: 12 }}>{grn.received_at?.slice(0, 16).replace('T', ' ')}</span>
                            <div style={{ fontSize: 10, color: '#64748b' }}>By {grn.received_by?.name || 'Staff'}</div>
                          </td>
                          <td>
                            <span style={{
                              display: 'inline-block',
                              padding: '3px 8px',
                              borderRadius: 4,
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
                                <button
                                  onClick={() => handlePostGrnToInventory(grn)}
                                  className="btn-primary"
                                  style={{ padding: '4px 10px', fontSize: 11, backgroundColor: '#059669', borderColor: '#059669', display: 'inline-flex', alignItems: 'center', gap: 4 }}
                                  title="Post quantities to authoritative inventory engine"
                                >
                                  <Boxes size={13} />
                                  <span>Post Stock</span>
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
      {/* MODAL 1: CREATE PURCHASE REQUISITION                      */}
      {/* ========================================================= */}
      {showCreatePrModal && (
        <div className="modal-overlay" style={{ zIndex: 9999 }}>
          <div className="modal-container" style={{ maxWidth: 840, width: '92%', backgroundColor: '#ffffff', borderRadius: 12, padding: 24 }}>
            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', borderBottom: '1px solid #e2e8f0', paddingBottom: 14, marginBottom: 18 }}>
              <div>
                <h3 style={{ margin: 0, fontSize: 18, fontWeight: 700, color: '#0f172a' }}>New Purchase Requisition</h3>
                <p style={{ margin: '4px 0 0 0', fontSize: 12, color: '#64748b' }}>
                  Request catalog items with business justification and live stock context.
                </p>
              </div>
              <button onClick={() => setShowCreatePrModal(false)} className="topbar-icon-button">
                <X size={18} />
              </button>
            </div>

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
                    { value: 'low', label: 'Low' },
                    { value: 'normal', label: 'Normal' },
                    { value: 'high', label: 'High' },
                    { value: 'urgent', label: 'Urgent' },
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

            <div style={{ marginBottom: 16 }}>
              <label className="form-label">Purpose / Objective</label>
              <input
                type="text"
                className="form-input"
                value={prPurpose}
                onChange={(e) => setPrPurpose(e.target.value)}
                placeholder="e.g. Replenishment for Black Friday wave; seasonal buffer"
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
            <div style={{ borderTop: '1px solid #e2e8f0', paddingTop: 16, marginBottom: 16 }}>
              <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 12 }}>
                <h4 style={{ margin: 0, fontSize: 14, fontWeight: 700, color: '#0f172a' }}>Requisition Items</h4>
                <div style={{ width: 280 }}>
                  <CustomDropdown
                    value=""
                    onChange={(val) => addPrItem(Number(val))}
                    placeholder="+ Add Product Variant..."
                    options={allVariants.map((v) => ({
                      value: String(v.id),
                      label: `${v.sku} - ${v.name}`,
                    }))}
                  />
                </div>
              </div>

              {prItems.length === 0 ? (
                <div style={{ textAlign: 'center', padding: '24px 0', border: '1px dashed #cbd5e1', borderRadius: 8, color: '#64748b', fontSize: 13 }}>
                  Select product variants from the dropdown above to add them to your requisition.
                </div>
              ) : (
                <div className="table-responsive-wrapper" style={{ maxHeight: 220, overflowY: 'auto' }}>
                  <table className="enterprise-data-table">
                    <thead>
                      <tr>
                        <th>SKU &amp; Product</th>
                        <th style={{ width: 120 }}>Requested Qty</th>
                        <th style={{ width: 130 }}>Est. Price ($)</th>
                        <th>Stock Context</th>
                        <th style={{ width: 110, textAlign: 'right' }}>Est. Subtotal</th>
                        <th style={{ width: 40 }}></th>
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
                                <div style={{ fontSize: 10, lineHeight: 1.3 }}>
                                  <span style={{ color: '#0284c7', fontWeight: 600 }}>Avail: {planning.available}</span> &bull;{' '}
                                  <span style={{ color: '#64748b' }}>On Hand: {planning.on_hand}</span> &bull;{' '}
                                  <span style={{ color: '#d97706' }}>On Order: {planning.on_order}</span>
                                </div>
                              ) : (
                                <span style={{ fontSize: 10, color: '#94a3b8' }}>Live stock synced</span>
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
                                <X size={16} />
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

            {/* Total Footer */}
            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', borderTop: '1px solid #e2e8f0', paddingTop: 14 }}>
              <div>
                <span style={{ fontSize: 13, color: '#64748b' }}>Estimated Total: </span>
                <span style={{ fontSize: 18, fontWeight: 800, color: '#0f172a' }}>
                  ${prItems.reduce((acc, x) => acc + x.qty * x.unit_price, 0).toFixed(2)}
                </span>
              </div>

              <div style={{ display: 'flex', gap: 8 }}>
                <button onClick={() => setShowCreatePrModal(false)} className="btn-outline">
                  Cancel
                </button>
                <button onClick={() => handleSaveRequisition(false)} className="btn-outline">
                  Save as Draft
                </button>
                <button onClick={() => handleSaveRequisition(true)} className="btn-primary">
                  Submit for Approval
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
        <div className="modal-overlay" style={{ zIndex: 9999 }}>
          <div className="modal-container" style={{ maxWidth: 780, width: '92%', backgroundColor: '#ffffff', borderRadius: 12, padding: 24 }}>
            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', borderBottom: '1px solid #e2e8f0', paddingBottom: 14, marginBottom: 16 }}>
              <div>
                <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                  <h3 style={{ margin: 0, fontSize: 18, fontWeight: 800, color: '#0f172a' }}>
                    Requisition {selectedPr.pr_number}
                  </h3>
                  <span style={{
                    padding: '2px 8px',
                    borderRadius: 4,
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

            {/* Requisition Meta Details */}
            <div style={{
              display: 'grid',
              gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))',
              gap: 12,
              backgroundColor: '#f8fafc',
              padding: 12,
              borderRadius: 8,
              marginBottom: 16,
              fontSize: 12,
            }}>
              <div><strong>Warehouse:</strong> {selectedPr.warehouse?.name}</div>
              <div><strong>Priority:</strong> {selectedPr.priority?.toUpperCase()}</div>
              <div><strong>Required By:</strong> {selectedPr.required_by_date || 'Flexible'}</div>
              <div><strong>Approved By:</strong> {selectedPr.approver?.name || 'Pending'}</div>
            </div>

            {selectedPr.purpose && (
              <div style={{ marginBottom: 12, fontSize: 13 }}>
                <strong>Purpose:</strong> {selectedPr.purpose}
              </div>
            )}
            {selectedPr.justification && (
              <div style={{ marginBottom: 16, fontSize: 13, color: '#475569' }}>
                <strong>Justification:</strong> {selectedPr.justification}
              </div>
            )}

            {/* Line Items */}
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

            {/* Audit History */}
            <div style={{ borderTop: '1px solid #e2e8f0', paddingTop: 12, marginBottom: 16 }}>
              <h5 style={{ margin: '0 0 8px 0', fontSize: 12, textTransform: 'uppercase', color: '#64748b' }}>
                Approval Audit Trail
              </h5>
              <div style={{ display: 'flex', flexDirection: 'column', gap: 6, fontSize: 11 }}>
                {(selectedPr.approvals || []).map((app: any, idx: number) => (
                  <div key={idx} style={{ display: 'flex', justifyContent: 'space-between', color: '#475569' }}>
                    <span>
                      <strong>{app.user?.name || 'User'}</strong> ({app.action}): {app.comments || 'No comment'}
                    </span>
                    <span style={{ color: '#94a3b8' }}>{app.created_at?.slice(0, 16).replace('T', ' ')}</span>
                  </div>
                ))}
              </div>
            </div>

            {/* Modal Actions */}
            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', borderTop: '1px solid #e2e8f0', paddingTop: 14 }}>
              <button
                onClick={() => omsService.downloadPrPdf(selectedPr.id, `${selectedPr.pr_number}.pdf`)}
                className="btn-outline"
                style={{ display: 'inline-flex', alignItems: 'center', gap: 6 }}
              >
                <Download size={14} />
                <span>Download PDF</span>
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
        <div className="modal-overlay" style={{ zIndex: 10000 }}>
          <div className="modal-container" style={{ maxWidth: 680, width: '92%', backgroundColor: '#ffffff', borderRadius: 12, padding: 24 }}>
            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', borderBottom: '1px solid #e2e8f0', paddingBottom: 14, marginBottom: 16 }}>
              <div>
                <h3 style={{ margin: 0, fontSize: 18, fontWeight: 700, color: '#0f172a' }}>
                  Convert PR #{selectedPr.pr_number} to PO
                </h3>
                <p style={{ margin: '4px 0 0 0', fontSize: 12, color: '#64748b' }}>
                  Assign supplier and specify quantities to commit into an active Purchase Order.
                </p>
              </div>
              <button onClick={() => setShowConvertPoModal(false)} className="topbar-icon-button">
                <X size={18} />
              </button>
            </div>

            <div className="finovo-form-grid-2" style={{ marginBottom: 16 }}>
              <div>
                <label className="form-label">Supplier Name *</label>
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
                    { value: 'Immediate', label: 'Cash on Delivery' },
                  ]}
                />
              </div>
            </div>

            <div style={{ borderTop: '1px solid #e2e8f0', paddingTop: 14, marginBottom: 16 }}>
              <h4 style={{ margin: '0 0 10px 0', fontSize: 13, fontWeight: 700, color: '#0f172a' }}>
                Allocate Quantities to Order
              </h4>
              <div className="table-responsive-wrapper">
                <table className="enterprise-data-table">
                  <thead>
                    <tr>
                      <th>SKU &amp; Description</th>
                      <th>Approved</th>
                      <th>Already Ordered</th>
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
                            <div style={{ fontSize: 10, color: '#64748b', marginTop: 2 }}>Max: {maxOrder}</div>
                          </td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
            </div>

            <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, borderTop: '1px solid #e2e8f0', paddingTop: 14 }}>
              <button onClick={() => setShowConvertPoModal(false)} className="btn-outline">
                Cancel
              </button>
              <button onClick={handleExecuteConvertPo} className="btn-primary">
                Generate Purchase Order
              </button>
            </div>
          </div>
        </div>
      )}

      {/* ========================================================= */}
      {/* MODAL 4: REJECT PR MODAL                                  */}
      {/* ========================================================= */}
      {showRejectPrModal && (
        <div className="modal-overlay" style={{ zIndex: 10001 }}>
          <div className="modal-container" style={{ maxWidth: 460, width: '90%', backgroundColor: '#ffffff', borderRadius: 12, padding: 24 }}>
            <h3 style={{ margin: '0 0 8px 0', fontSize: 16, fontWeight: 700, color: '#dc2626' }}>
              Reject Purchase Requisition
            </h3>
            <p style={{ margin: '0 0 14px 0', fontSize: 12, color: '#64748b' }}>
              Please provide a specific business reason for rejecting this requisition.
            </p>

            <textarea
              className="form-input"
              style={{ height: 90, marginBottom: 16 }}
              placeholder="e.g. Out of Q4 budget; inventory surplus discovered in secondary facility..."
              value={rejectionReason}
              onChange={(e) => setRejectionReason(e.target.value)}
            />

            <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8 }}>
              <button onClick={() => setShowRejectPrModal(false)} className="btn-outline">
                Cancel
              </button>
              <button onClick={handleRejectPrConfirm} className="btn-primary" style={{ backgroundColor: '#dc2626', borderColor: '#dc2626' }}>
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
        <div className="modal-overlay" style={{ zIndex: 9999 }}>
          <div className="modal-container" style={{ maxWidth: 860, width: '92%', backgroundColor: '#ffffff', borderRadius: 12, padding: 24 }}>
            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', borderBottom: '1px solid #e2e8f0', paddingBottom: 14, marginBottom: 16 }}>
              <div>
                <h3 style={{ margin: 0, fontSize: 18, fontWeight: 700, color: '#0f172a' }}>
                  Dock Receiving: Goods Received Note (GRN)
                </h3>
                <p style={{ margin: '4px 0 0 0', fontSize: 12, color: '#64748b' }}>
                  Receiving shipment against PO #{selectedPo.po_number} ({selectedPo.supplier_name})
                </p>
              </div>
              <button onClick={() => setShowCreateGrnModal(false)} className="topbar-icon-button">
                <X size={18} />
              </button>
            </div>

            <div className="finovo-form-grid-2" style={{ marginBottom: 16 }}>
              <div>
                <label className="form-label">Supplier Delivery Note / Bill of Lading *</label>
                <input
                  type="text"
                  className="form-input"
                  value={grnDeliveryNote}
                  onChange={(e) => setGrnDeliveryNote(e.target.value)}
                  placeholder="e.g. DN-902188"
                />
              </div>

              <div>
                <label className="form-label">Carrier / Transport</label>
                <input
                  type="text"
                  className="form-input"
                  value={grnCarrier}
                  onChange={(e) => setGrnCarrier(e.target.value)}
                  placeholder="e.g. FedEx, DHL, Freight Line"
                />
              </div>

              <div>
                <label className="form-label">Tracking / Vehicle #</label>
                <input
                  type="text"
                  className="form-input"
                  value={grnVehicle}
                  onChange={(e) => setGrnVehicle(e.target.value)}
                  placeholder="e.g. TRK-88219"
                />
              </div>

              <div style={{ display: 'flex', alignItems: 'center', paddingTop: 24 }}>
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
            <div style={{ borderTop: '1px solid #e2e8f0', paddingTop: 14, marginBottom: 16 }}>
              <h4 style={{ margin: '0 0 10px 0', fontSize: 13, fontWeight: 700, color: '#0f172a' }}>
                Item Receiving Count &amp; Condition Breakdown
              </h4>
              <div className="table-responsive-wrapper" style={{ maxHeight: 250, overflowY: 'auto' }}>
                <table className="enterprise-data-table">
                  <thead>
                    <tr>
                      <th>SKU</th>
                      <th>Ordered</th>
                      <th style={{ width: 100 }}>Received</th>
                      <th style={{ width: 100, color: '#16a34a' }}>Accepted</th>
                      <th style={{ width: 90, color: '#dc2626' }}>Damaged</th>
                      <th style={{ width: 90, color: '#d97706' }}>Quarantine</th>
                      <th style={{ width: 120 }}>Lot / Batch #</th>
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
                            <div style={{ fontSize: 10, color: '#64748b' }}>{poi.product_name}</div>
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

            <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, borderTop: '1px solid #e2e8f0', paddingTop: 14 }}>
              <button onClick={() => setShowCreateGrnModal(false)} className="btn-outline">
                Cancel
              </button>
              <button onClick={handleSaveGrn} className="btn-primary">
                Record Inbound GRN
              </button>
            </div>
          </div>
        </div>
      )}

      {/* ========================================================= */}
      {/* MODAL 6: VIEW GOODS RECEIVED NOTE DETAILS                 */}
      {/* ========================================================= */}
      {showViewGrnModal && selectedGrn && (
        <div className="modal-overlay" style={{ zIndex: 9999 }}>
          <div className="modal-container" style={{ maxWidth: 840, width: '92%', backgroundColor: '#ffffff', borderRadius: 12, padding: 24 }}>
            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', borderBottom: '1px solid #e2e8f0', paddingBottom: 14, marginBottom: 16 }}>
              <div>
                <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                  <h3 style={{ margin: 0, fontSize: 18, fontWeight: 800, color: '#0f172a' }}>
                    GRN {selectedGrn.grn_number}
                  </h3>
                  <span style={{
                    padding: '2px 8px',
                    borderRadius: 4,
                    fontSize: 10,
                    fontWeight: 700,
                    textTransform: 'uppercase',
                    backgroundColor: selectedGrn.status === 'posted' ? '#dcfce7' : '#fef3c7',
                    color: selectedGrn.status === 'posted' ? '#15803d' : '#b45309',
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

            <div style={{
              display: 'grid',
              gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))',
              gap: 12,
              backgroundColor: '#f8fafc',
              padding: 12,
              borderRadius: 8,
              marginBottom: 16,
              fontSize: 12,
            }}>
              <div><strong>Supplier:</strong> {selectedGrn.supplier_name}</div>
              <div><strong>Delivery Note:</strong> {selectedGrn.supplier_delivery_note || 'N/A'}</div>
              <div><strong>Carrier:</strong> {selectedGrn.carrier || 'N/A'}</div>
              <div><strong>Receiving Dock:</strong> {selectedGrn.receiving_location?.code || 'Dock Inbound'}</div>
            </div>

            {/* Item Breakdown Table */}
            <div className="table-responsive-wrapper" style={{ maxHeight: 220, overflowY: 'auto', marginBottom: 16 }}>
              <table className="enterprise-data-table">
                <thead>
                  <tr>
                    <th>SKU &amp; Description</th>
                    <th>Ordered</th>
                    <th>Prior</th>
                    <th>Received</th>
                    <th style={{ color: '#16a34a' }}>Accepted</th>
                    <th style={{ color: '#dc2626' }}>Damaged</th>
                    <th style={{ color: '#d97706' }}>Quarantine</th>
                    <th>Lot / Batch</th>
                  </tr>
                </thead>
                <tbody>
                  {(selectedGrn.items || []).map((item: any) => (
                    <tr key={item.id}>
                      <td>
                        <div style={{ fontWeight: 600 }}>{item.sku}</div>
                        <div style={{ fontSize: 10, color: '#64748b' }}>{item.product_name}</div>
                      </td>
                      <td>{item.ordered_quantity}</td>
                      <td>{item.previously_received_quantity}</td>
                      <td><strong>{item.received_quantity}</strong></td>
                      <td style={{ color: '#16a34a', fontWeight: 700 }}>{item.accepted_quantity}</td>
                      <td style={{ color: '#dc2626', fontWeight: 700 }}>{item.damaged_quantity}</td>
                      <td style={{ color: '#d97706', fontWeight: 700 }}>{item.quarantined_quantity}</td>
                      <td>{item.lot_number || '-'}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>

            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', borderTop: '1px solid #e2e8f0', paddingTop: 14 }}>
              <button
                onClick={() => omsService.downloadGrnPdf(selectedGrn.id, `${selectedGrn.grn_number}.pdf`)}
                className="btn-outline"
                style={{ display: 'inline-flex', alignItems: 'center', gap: 6 }}
              >
                <Download size={14} />
                <span>Download PDF</span>
              </button>

              <div style={{ display: 'flex', gap: 8 }}>
                {selectedGrn.status !== 'posted' && (
                  <button
                    onClick={() => handlePostGrnToInventory(selectedGrn)}
                    className="btn-primary"
                    style={{ backgroundColor: '#059669', borderColor: '#059669', display: 'inline-flex', alignItems: 'center', gap: 6 }}
                  >
                    <Boxes size={14} />
                    <span>Post to Authoritative Inventory</span>
                  </button>
                )}
              </div>
            </div>
          </div>
        </div>
      )}

      {/* ========================================================= */}
      {/* MODAL 7: STANDALONE PURCHASE ORDER CREATION               */}
      {/* ========================================================= */}
      {showCreatePoModal && (
        <div className="modal-overlay" style={{ zIndex: 9999 }}>
          <div className="modal-container" style={{ maxWidth: 800, width: '92%', backgroundColor: '#ffffff', borderRadius: 12, padding: 24 }}>
            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', borderBottom: '1px solid #e2e8f0', paddingBottom: 14, marginBottom: 16 }}>
              <div>
                <h3 style={{ margin: 0, fontSize: 18, fontWeight: 700, color: '#0f172a' }}>Create Purchase Order</h3>
                <p style={{ margin: '4px 0 0 0', fontSize: 12, color: '#64748b' }}>
                  Issue an official procurement order directly to an authorized supplier.
                </p>
              </div>
              <button onClick={() => setShowCreatePoModal(false)} className="topbar-icon-button">
                <X size={18} />
              </button>
            </div>

            <div className="finovo-form-grid-2" style={{ marginBottom: 16 }}>
              <div>
                <label className="form-label">Supplier Name *</label>
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
            <div style={{ borderTop: '1px solid #e2e8f0', paddingTop: 14, marginBottom: 16 }}>
              <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 10 }}>
                <h4 style={{ margin: 0, fontSize: 13, fontWeight: 700, color: '#0f172a' }}>Order Line Items</h4>
                <div style={{ width: 280 }}>
                  <CustomDropdown
                    value=""
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
                    options={allVariants.map((v) => ({
                      value: String(v.id),
                      label: `${v.sku} - ${v.name}`,
                    }))}
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
                      <th style={{ width: 40 }}></th>
                    </tr>
                  </thead>
                  <tbody>
                    {poStandaloneItems.map((item, idx) => (
                      <tr key={item.variant_id}>
                        <td>
                          <div style={{ fontWeight: 600 }}>{item.sku}</div>
                          <div style={{ fontSize: 10, color: '#64748b' }}>{item.name}</div>
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
                            <X size={16} />
                          </button>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>

            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', borderTop: '1px solid #e2e8f0', paddingTop: 14 }}>
              <div>
                <span style={{ fontSize: 13, color: '#64748b' }}>Total Order Cost: </span>
                <span style={{ fontSize: 18, fontWeight: 800, color: '#0f172a' }}>
                  ${poStandaloneItems.reduce((acc, x) => acc + x.qty * x.unit_cost, 0).toFixed(2)}
                </span>
              </div>

              <div style={{ display: 'flex', gap: 8 }}>
                <button onClick={() => setShowCreatePoModal(false)} className="btn-outline">
                  Cancel
                </button>
                <button onClick={handleCreatePo} className="btn-primary">
                  Issue Purchase Order
                </button>
              </div>
            </div>
          </div>
        </div>
      )}

      {/* ========================================================= */}
      {/* MODAL 8: VIEW PURCHASE ORDER DETAILS                      */}
      {/* ========================================================= */}
      {showViewPoModal && selectedPo && (
        <div className="modal-overlay" style={{ zIndex: 9999 }}>
          <div className="modal-container" style={{ maxWidth: 740, width: '92%', backgroundColor: '#ffffff', borderRadius: 12, padding: 24 }}>
            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', borderBottom: '1px solid #e2e8f0', paddingBottom: 14, marginBottom: 16 }}>
              <div>
                <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                  <h3 style={{ margin: 0, fontSize: 18, fontWeight: 800, color: '#0f172a' }}>
                    Purchase Order {selectedPo.po_number}
                  </h3>
                  <span style={{
                    padding: '2px 8px',
                    borderRadius: 4,
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
                  Supplier: {selectedPo.supplier_name} &bull; Ordered {selectedPo.order_date || selectedPo.created_at?.slice(0, 10)}
                </div>
              </div>
              <button onClick={() => setShowViewPoModal(false)} className="topbar-icon-button">
                <X size={18} />
              </button>
            </div>

            {/* Items */}
            <div className="table-responsive-wrapper" style={{ maxHeight: 220, overflowY: 'auto', marginBottom: 16 }}>
              <table className="enterprise-data-table">
                <thead>
                  <tr>
                    <th>SKU &amp; Description</th>
                    <th>Ordered Qty</th>
                    <th>Received Qty</th>
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
                        <div style={{ fontSize: 10, color: '#64748b' }}>{item.product_name}</div>
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

            <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, borderTop: '1px solid #e2e8f0', paddingTop: 14 }}>
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
    </div>
  );
};

export default PurchaseOrdersPage;
