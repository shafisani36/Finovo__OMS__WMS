import React, { useCallback, useEffect, useState } from 'react';
import { Loader2, Plus, ScanLine, Search } from 'lucide-react';
import { Alert } from '../components/common/Alert';
import StatusPill from '../components/common/StatusPill';
import omsService, { SERIAL_STATUS_LABELS, parseSerials } from '../services/omsService';
import type { ProductModel, SerialStatus, SerialTraceModel, SerialUnitModel } from '../services/omsService';
import { useDebouncedValue } from '../hooks/useDebouncedValue';
import { useAuth } from '../hooks/useAuth';
import { can } from '../types/auth';
import { formatDate } from '../utils/format';
import { errorMessage } from '../utils/errors';
import '../components/catalog/CatalogForms.css';

interface WarehouseOption {
  id: number;
  name: string;
}

/** Register serial numbers of units already on the shelf (new stock gets them on its goods received note). */
const RegisterSerials: React.FC<{ warehouses: WarehouseOption[]; onDone: (message: string) => void }> = ({ warehouses, onDone }) => {
  const [warehouseId, setWarehouseId] = useState<number | ''>(warehouses[0]?.id ?? '');
  const [search, setSearch] = useState('');
  const debounced = useDebouncedValue(search, 300);
  const [variants, setVariants] = useState<{ id: number; label: string }[]>([]);
  const [variantId, setVariantId] = useState<number | ''>('');
  const [text, setText] = useState('');
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const serials = parseSerials(text);

  useEffect(() => {
    omsService
      .getProducts({ search: debounced, per_page: 50 })
      .then((res: { data: ProductModel[] }) =>
        setVariants(res.data.flatMap((product) => (product.variants ?? []).filter((v) => v.track_serials).map((v) => ({ id: v.id, label: `${product.name} (${v.sku})` }))))
      )
      .catch(() => setVariants([]));
  }, [debounced]);

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (warehouseId === '' || variantId === '' || serials.length === 0) return;
    setSaving(true);
    setError(null);
    try {
      const res = await omsService.registerSerials(warehouseId, variantId, serials);
      setText('');
      onDone(res.message);
    } catch (err) {
      setError(errorMessage(err, 'The serial numbers could not be registered.'));
    } finally {
      setSaving(false);
    }
  };

  return (
    <form className="content-card" style={{ padding: 16, display: 'flex', flexDirection: 'column', gap: 10 }} onSubmit={submit}>
      <h3 style={{ margin: 0, fontSize: 15 }}>Register serial numbers</h3>
      <p className="page-subtitle" style={{ margin: 0 }}>
        For units already in stock. Only products set to track serial numbers are listed; new stock gets its serial numbers when it is received.
      </p>
      {error && <Alert type="error" message={error} />}
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))', gap: 10 }}>
        <label className="cf-field">
          <span className="cf-label">Warehouse</span>
          <select className="cf-input" value={warehouseId} onChange={(e) => setWarehouseId(e.target.value === '' ? '' : Number(e.target.value))} required>
            {warehouses.map((w) => (
              <option key={w.id} value={w.id}>
                {w.name}
              </option>
            ))}
          </select>
        </label>
        <label className="cf-field">
          <span className="cf-label">Find product</span>
          <input className="cf-input" value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Name or SKU" />
        </label>
        <label className="cf-field">
          <span className="cf-label">Product</span>
          <select className="cf-input" value={variantId} onChange={(e) => setVariantId(e.target.value === '' ? '' : Number(e.target.value))} required>
            <option value="">{variants.length ? 'Choose...' : 'No products track serial numbers'}</option>
            {variants.map((v) => (
              <option key={v.id} value={v.id}>
                {v.label}
              </option>
            ))}
          </select>
        </label>
      </div>
      <label className="cf-field">
        <span className="cf-label">Serial numbers (scan or type one per line)</span>
        <textarea className="cf-input" rows={4} value={text} onChange={(e) => setText(e.target.value)} />
      </label>
      <div>
        <button type="submit" className="btn-finovo-primary" disabled={saving || serials.length === 0 || variantId === ''}>
          {saving ? <Loader2 size={14} className="animate-spin" /> : <Plus size={14} />}
          <span>Register {serials.length || ''} serial number{serials.length === 1 ? '' : 's'}</span>
        </button>
      </div>
    </form>
  );
};

/**
 * Every unit of serial-tracked products: where it is now, and its whole history (received, put on an order,
 * shipped, returned) when a serial number is traced.
 */
export const SerialNumbersPage: React.FC = () => {
  const { user } = useAuth();
  const canRegister = can(user, 'inventory.receive');
  const [units, setUnits] = useState<SerialUnitModel[] | null>(null);
  const [meta, setMeta] = useState({ current_page: 1, last_page: 1, total: 0 });
  const [search, setSearch] = useState('');
  const debounced = useDebouncedValue(search, 300);
  const [status, setStatus] = useState<SerialStatus | ''>('');
  const [page, setPage] = useState(1);
  const [warehouses, setWarehouses] = useState<WarehouseOption[]>([]);
  const [banner, setBanner] = useState<{ type: 'success' | 'error'; text: string } | null>(null);
  const [traceInput, setTraceInput] = useState('');
  const [trace, setTrace] = useState<SerialTraceModel[] | null>(null);
  const [tracing, setTracing] = useState(false);

  const load = useCallback(() => {
    omsService
      .getSerials({ search: debounced, status, page })
      .then((res) => {
        setUnits(res.data);
        setMeta(res.meta);
      })
      .catch((err) => setBanner({ type: 'error', text: errorMessage(err, 'Could not load the serial numbers.') }));
  }, [debounced, status, page]);

  useEffect(() => {
    load();
  }, [load]);

  useEffect(() => {
    if (!canRegister) return;
    omsService
      .getWarehouses()
      .then((res: { data: WarehouseOption[] }) => setWarehouses(res.data))
      .catch(() => setWarehouses([]));
  }, [canRegister]);

  const runTrace = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!traceInput.trim()) return;
    setTracing(true);
    try {
      setTrace(await omsService.traceSerial(traceInput.trim()));
    } catch (err) {
      setBanner({ type: 'error', text: errorMessage(err, 'The serial number could not be traced.') });
    } finally {
      setTracing(false);
    }
  };

  return (
    <div className="page-container">
      <nav className="breadcrumb-nav">
        <span>Finovo</span>
        <span>&gt;</span>
        <span style={{ color: '#0f172a', fontWeight: 500 }}>Serial Numbers</span>
      </nav>
      <div className="page-header-row">
        <div className="page-title-section">
          <h1 className="page-title">Serial Numbers</h1>
          <p className="page-subtitle">Each unit of serial-tracked products, from the day it arrived to the customer who has it.</p>
        </div>
      </div>

      {banner && <Alert type={banner.type} message={banner.text} className="page-banner" />}

      <form className="content-card" style={{ padding: 16, display: 'flex', gap: 10, alignItems: 'flex-end', flexWrap: 'wrap', marginBottom: 16 }} onSubmit={runTrace}>
        <label className="cf-field" style={{ flex: '1 1 260px' }}>
          <span className="cf-label">Trace a serial number</span>
          <input className="cf-input" value={traceInput} onChange={(e) => setTraceInput(e.target.value)} placeholder="Scan or type a serial number" />
        </label>
        <button type="submit" className="btn-finovo-primary" disabled={tracing}>
          {tracing ? <Loader2 size={14} className="animate-spin" /> : <ScanLine size={14} />}
          <span>Trace</span>
        </button>
      </form>

      {trace && (
        <div className="content-card" style={{ padding: 16, marginBottom: 16 }}>
          {trace.length === 0 && <p className="page-subtitle">No unit has this serial number.</p>}
          {trace.map((unit) => (
            <div key={unit.id} style={{ marginBottom: 12 }}>
              <h3 style={{ margin: '0 0 6px', fontSize: 15 }}>
                {unit.serial} · {unit.product_name} ({unit.sku}) <StatusPill status={unit.status} label={SERIAL_STATUS_LABELS[unit.status]} />
              </h3>
              <p className="page-subtitle" style={{ margin: '0 0 8px' }}>
                {[unit.warehouse && `Warehouse: ${unit.warehouse}`, unit.location && `Bin: ${unit.location}`, unit.grn_number && `Received on ${unit.grn_number}`, unit.order_number && `Order ${unit.order_number}`, unit.customer && `Customer: ${unit.customer}`, unit.return_number && `Return ${unit.return_number}`]
                  .filter(Boolean)
                  .join(' · ')}
              </p>
              <ol style={{ margin: 0, paddingLeft: 18, fontSize: 13 }}>
                {unit.events.map((event, index) => (
                  <li key={index}>
                    {formatDate(event.at, true)} — {event.description}
                  </li>
                ))}
              </ol>
            </div>
          ))}
        </div>
      )}

      {canRegister && warehouses.length > 0 && (
        <div style={{ marginBottom: 16 }}>
          <RegisterSerials
            warehouses={warehouses}
            onDone={(message) => {
              setBanner({ type: 'success', text: message });
              load();
            }}
          />
        </div>
      )}

      <div className="content-card">
        <div style={{ display: 'flex', gap: 10, padding: 16, flexWrap: 'wrap' }}>
          <div className="cf-field" style={{ flex: '1 1 240px', position: 'relative' }}>
            <input
              className="cf-input"
              value={search}
              onChange={(e) => {
                setSearch(e.target.value);
                setPage(1);
              }}
              placeholder="Search serial number or SKU"
              aria-label="Search serial numbers"
            />
            <Search size={14} style={{ position: 'absolute', right: 10, top: 12, color: '#94a3b8' }} aria-hidden />
          </div>
          <select
            className="cf-input"
            style={{ width: 180 }}
            value={status}
            aria-label="Status"
            onChange={(e) => {
              setStatus(e.target.value as SerialStatus | '');
              setPage(1);
            }}
          >
            <option value="">All statuses</option>
            {Object.entries(SERIAL_STATUS_LABELS).map(([value, label]) => (
              <option key={value} value={value}>
                {label}
              </option>
            ))}
          </select>
        </div>
        <div className="table-responsive-wrapper">
          <table className="finovo-table">
            <thead>
              <tr>
                <th>Serial Number</th>
                <th>Product</th>
                <th>Warehouse</th>
                <th>Order</th>
                <th>Status</th>
                <th>Last Change</th>
              </tr>
            </thead>
            <tbody>
              {!units && (
                <tr>
                  <td colSpan={6}>Loading...</td>
                </tr>
              )}
              {units?.length === 0 && (
                <tr>
                  <td colSpan={6}>No serial numbers yet.</td>
                </tr>
              )}
              {units?.map((unit) => (
                <tr key={unit.id}>
                  <td style={{ fontFamily: 'monospace' }}>{unit.serial}</td>
                  <td>
                    {unit.product_name} <span className="page-subtitle">({unit.sku})</span>
                  </td>
                  <td>{unit.warehouse ?? '—'}</td>
                  <td>{unit.order_number ?? '—'}</td>
                  <td>
                    <StatusPill status={unit.status} label={SERIAL_STATUS_LABELS[unit.status]} />
                  </td>
                  <td>{formatDate(unit.updated_at, true)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        {meta.last_page > 1 && (
          <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', padding: 12, alignItems: 'center' }}>
            <span className="page-subtitle">
              Page {meta.current_page} of {meta.last_page} · {meta.total} units
            </span>
            <button type="button" className="btn-outline" disabled={page <= 1} onClick={() => setPage(page - 1)}>
              Previous
            </button>
            <button type="button" className="btn-outline" disabled={page >= meta.last_page} onClick={() => setPage(page + 1)}>
              Next
            </button>
          </div>
        )}
      </div>
    </div>
  );
};

export default SerialNumbersPage;
