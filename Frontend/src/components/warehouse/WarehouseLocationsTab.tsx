import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { Layers, Loader2, Plus, Search, Sparkles, Trash2, X } from 'lucide-react';
import { Alert } from '../common/Alert';
import CustomDropdown from '../common/CustomDropdown';
import { useConfirmDialog } from '../common/ConfirmDialog';
import omsService from '../../services/omsService';
import type { WarehouseBin, WarehouseLocationModel, WarehouseZone } from '../../services/omsService';
import { humanize } from '../../utils/format';
import { errorText } from './warehouseTabs';

const LOCATION_TYPES = [
  { value: 'zone', label: 'Zone', description: 'A top-level area' },
  { value: 'receiving', label: 'Receiving area', description: 'Top-level area where goods arrive' },
  { value: 'picking', label: 'Picking area', description: 'Top-level area for order picking' },
  { value: 'bulk_storage', label: 'Bulk storage', description: 'Top-level area for pallets and overstock' },
  { value: 'packing', label: 'Packing station', description: 'Top-level area' },
  { value: 'dispatch', label: 'Dispatch dock', description: 'Top-level area' },
  { value: 'quarantine', label: 'Returns & quarantine', description: 'Top-level area' },
  { value: 'aisle', label: 'Aisle', description: 'Inside a zone' },
  { value: 'rack', label: 'Rack', description: 'Inside an aisle' },
  { value: 'shelf', label: 'Shelf', description: 'Inside a rack' },
  { value: 'bin', label: 'Bin', description: 'Smallest place that holds stock' },
];

type BinFilter = 'all' | 'picking' | 'putaway';

const BIN_FILTERS: { value: BinFilter; label: string }[] = [
  { value: 'all', label: 'All bins' },
  { value: 'picking', label: 'Picking bins' },
  { value: 'putaway', label: 'Putaway bins' },
];

const EMPTY_FORM = { code: '', location_type: 'bin', parent_id: '', capacity: '', picking_eligible: true, putaway_eligible: true };

/** Bins of a zone grouped by rack, for a readable map. */
const groupBins = (bins: WarehouseBin[]) =>
  bins.reduce<Record<string, WarehouseBin[]>>((groups, bin) => {
    const key = bin.group ?? '';
    (groups[key] ??= []).push(bin);
    return groups;
  }, {});

interface Props {
  warehouseId: number;
  canManage: boolean;
  /** Called after locations change, so the page header can refresh its counts and readiness. */
  onChanged: () => void;
}

/** Locations tab: storage by zone, the bin map and the list of every location, with add and delete. */
export const WarehouseLocationsTab: React.FC<Props> = ({ warehouseId, canManage, onChanged }) => {
  const { confirm, dialog } = useConfirmDialog();
  const [zones, setZones] = useState<WarehouseZone[] | null>(null);
  const [locations, setLocations] = useState<WarehouseLocationModel[]>([]);
  const [filter, setFilter] = useState<BinFilter>('all');
  const [search, setSearch] = useState('');
  const [message, setMessage] = useState<{ type: 'success' | 'error'; text: string } | null>(null);
  const [busy, setBusy] = useState(false);
  const [formOpen, setFormOpen] = useState(false);
  const [form, setForm] = useState(EMPTY_FORM);
  const [formError, setFormError] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      const [overview, list] = await Promise.all([
        omsService.getWarehouseOverview(warehouseId),
        omsService.getWarehouseLocations(warehouseId, { per_page: 1000 }),
      ]);
      setZones(overview.data.zones);
      setLocations(list.data ?? []);
    } catch (err) {
      setMessage({ type: 'error', text: errorText(err, 'Failed to load the locations.') });
      setZones([]);
    }
  }, [warehouseId]);

  useEffect(() => {
    load();
  }, [load]);

  const refresh = () => {
    load();
    onChanged();
  };

  const run = async (action: () => Promise<unknown>, success: string, failure: string) => {
    try {
      setBusy(true);
      setMessage(null);
      await action();
      setMessage({ type: 'success', text: success });
      refresh();
    } catch (err) {
      setMessage({ type: 'error', text: errorText(err, failure) });
    } finally {
      setBusy(false);
    }
  };

  const generateStarter = async () => {
    const ok = await confirm({
      title: 'Generate a starter layout?',
      message: 'Creates receiving, picking, bulk storage, packing, dispatch and returns areas, with aisles, racks, shelves and bins in the picking area.',
      confirmLabel: 'Generate Layout',
      tone: 'primary',
    });
    if (ok) run(() => omsService.generateStarterLayout(warehouseId, { preview: false }), 'Starter layout created.', 'Failed to create the layout.');
  };

  const removeLocation = async (location: WarehouseLocationModel) => {
    const ok = await confirm({
      title: `Delete ${location.code}?`,
      message: 'The location is removed. A location that holds stock or contains other locations cannot be deleted.',
      confirmLabel: 'Delete Location',
      tone: 'danger',
    });
    if (ok) run(() => omsService.deleteWarehouseLocation(warehouseId, location.id), `${location.code} deleted.`, 'Failed to delete the location.');
  };

  const createLocation = async (e: React.FormEvent) => {
    e.preventDefault();
    try {
      setBusy(true);
      setFormError(null);
      await omsService.createWarehouseLocation(warehouseId, {
        code: form.code.trim().toUpperCase(),
        location_type: form.location_type,
        parent_id: form.parent_id ? Number(form.parent_id) : undefined,
        capacity: form.capacity ? Number(form.capacity) : undefined,
        picking_eligible: form.picking_eligible,
        putaway_eligible: form.putaway_eligible,
      });
      setFormOpen(false);
      setForm(EMPTY_FORM);
      setMessage({ type: 'success', text: `${form.code.trim().toUpperCase()} added.` });
      refresh();
    } catch (err) {
      setFormError(errorText(err, 'Failed to add the location.'));
    } finally {
      setBusy(false);
    }
  };

  const mapZones = useMemo(
    () =>
      (zones ?? [])
        .map((zone) => ({ ...zone, bins: zone.bins.filter((bin) => filter === 'all' || bin[filter]) }))
        .filter((zone) => zone.bins.length > 0),
    [zones, filter]
  );

  const shownLocations = useMemo(() => {
    const term = search.trim().toUpperCase();
    return term ? locations.filter((l) => l.code.includes(term) || l.location_type.toUpperCase().includes(term)) : locations;
  }, [locations, search]);

  if (zones === null) {
    return (
      <div className="wh-loading">
        <Loader2 className="animate-spin" size={28} />
      </div>
    );
  }

  return (
    <div className="wh-stack">
      {message && <Alert type={message.type} message={message.text} />}

      <section className="wh-card" aria-labelledby="wh-zones-title">
        <div className="wh-card-head">
          <div>
            <h2 id="wh-zones-title" className="wh-card-title">Storage by Zone</h2>
            <p className="wh-card-subtitle">A bin counts as used when it holds any stock.</p>
          </div>
          {canManage && (
            <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
              {locations.length === 0 && (
                <button type="button" className="btn-finovo-secondary" onClick={generateStarter} disabled={busy}>
                  <Sparkles size={14} color="#1e56ff" />
                  <span>Generate Starter Layout</span>
                </button>
              )}
              <button type="button" className="btn-finovo-primary" onClick={() => setFormOpen(true)} disabled={busy}>
                <Plus size={14} />
                <span>Add Location</span>
              </button>
            </div>
          )}
        </div>

        {zones.length === 0 ? (
          <div className="wh-empty">
            <Layers size={32} color="#cbd5e1" />
            <h4>No locations yet</h4>
            <p>Add zones and bins, or generate a starter layout to begin.</p>
          </div>
        ) : (
          <div className="wh-table-wrap">
            <table className="wh-table">
              <thead>
                <tr>
                  <th>Zone</th>
                  <th>Type</th>
                  <th>Aisles</th>
                  <th>Main category</th>
                  <th>Units</th>
                  <th>Storage used</th>
                  <th>Used</th>
                  <th>Free bins</th>
                </tr>
              </thead>
              <tbody>
                {zones.map((zone) => (
                  <tr key={zone.id ?? 'loose'}>
                    <td style={{ fontWeight: 600 }}>{zone.code}</td>
                    <td style={{ textTransform: 'capitalize', color: '#475569' }}>{zone.type ? humanize(zone.type) : '—'}</td>
                    <td>{zone.aisles ?? '—'}</td>
                    <td>{zone.main_category ?? '—'}</td>
                    <td>{zone.units.toLocaleString()}</td>
                    <td>
                      {zone.percent === null ? (
                        <span style={{ color: '#94a3b8' }}>No bins</span>
                      ) : (
                        <div className="wh-progress" role="img" aria-label={`${zone.percent}% of bins used`}>
                          <span className={zone.percent >= 85 ? 'is-high' : ''} style={{ width: `${zone.percent}%` }} />
                        </div>
                      )}
                    </td>
                    <td style={{ fontWeight: 600 }}>{zone.percent === null ? '—' : `${zone.percent}%`}</td>
                    <td>
                      <strong>{zone.bins_free}</strong>
                      <span style={{ color: '#64748b' }}>/{zone.bins_total}</span>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>

      <section className="wh-card" aria-labelledby="wh-map-title">
        <div className="wh-card-head">
          <h2 id="wh-map-title" className="wh-card-title">Warehouse Map</h2>
          <div className="wh-tabs is-muted" role="tablist" aria-label="Bin type">
            {BIN_FILTERS.map((option) => (
              <button
                key={option.value}
                type="button"
                role="tab"
                aria-selected={filter === option.value}
                className={`wh-tab ${filter === option.value ? 'is-active' : ''}`}
                onClick={() => setFilter(option.value)}
              >
                {option.label}
              </button>
            ))}
          </div>
        </div>

        {mapZones.length === 0 ? (
          <div className="wh-empty">No bins to show.</div>
        ) : (
          <div className="wh-map">
            {mapZones.map((zone) => (
              <div key={zone.id ?? 'loose'} className="wh-zone">
                <div className="wh-zone-head">
                  <strong>{zone.code}</strong>
                  <span>{zone.main_category ?? (zone.type ? humanize(zone.type) : '')}</span>
                </div>
                {Object.entries(groupBins(zone.bins)).map(([group, bins]) => (
                  <div key={group} className="wh-bin-group">
                    {group && <span className="wh-bin-group-name">{group}</span>}
                    <div className="wh-bins">
                      {bins.map((bin) => (
                        <span
                          key={bin.id}
                          className={`wh-bin is-${bin.state}`}
                          title={`${bin.code}: ${bin.units} unit${bin.units === 1 ? '' : 's'}${bin.capacity ? ` of ${bin.capacity}` : ''}`}
                          style={{ display: 'inline-flex', alignItems: 'center', justifyContent: 'center' }}
                        >
                          {bin.label}
                        </span>
                      ))}
                    </div>
                  </div>
                ))}
                <span className="wh-zone-foot">
                  Free bins <strong style={{ color: '#0f172a' }}>{zone.bins.filter((b) => b.state === 'empty').length}/{zone.bins.length}</strong>
                </span>
              </div>
            ))}
          </div>
        )}

        <div className="wh-legend" style={{ marginTop: 14 }}>
          <span>
            <i /> Empty
          </span>
          <span>
            <i className="is-partial" /> Partly used
          </span>
          <span>
            <i className="is-full" /> Full (at capacity)
          </span>
        </div>
      </section>

      {locations.length > 0 && (
        <section className="wh-card" aria-labelledby="wh-all-locations-title">
          <div className="wh-card-head">
            <div>
              <h2 id="wh-all-locations-title" className="wh-card-title">All Locations</h2>
              <p className="wh-card-subtitle">{locations.length} locations in this warehouse.</p>
            </div>
            <div className="topbar-search-input" style={{ width: 260, maxWidth: '100%', boxSizing: 'border-box' }}>
              <Search size={16} color="#94a3b8" />
              <input type="text" placeholder="Search code or type..." value={search} onChange={(e) => setSearch(e.target.value)} aria-label="Search locations" />
            </div>
          </div>
          <div className="wh-table-wrap" style={{ maxHeight: 420, overflowY: 'auto' }}>
            <table className="wh-table">
              <thead>
                <tr>
                  <th>Code</th>
                  <th>Type</th>
                  <th>Inside</th>
                  <th>Capacity</th>
                  <th>Use</th>
                  {canManage && <th style={{ textAlign: 'right' }}>Action</th>}
                </tr>
              </thead>
              <tbody>
                {shownLocations.map((location) => (
                  <tr key={location.id}>
                    <td style={{ fontWeight: 600 }}>{location.code}</td>
                    <td style={{ textTransform: 'capitalize' }}>{humanize(location.location_type)}</td>
                    <td style={{ color: '#64748b' }}>{location.parent?.code ?? '—'}</td>
                    <td>{location.capacity ? `${Number(location.capacity)} ${location.capacity_unit ?? 'units'}` : '—'}</td>
                    <td>
                      <div style={{ display: 'flex', gap: 6 }}>
                        {location.picking_eligible && <span className="wh-badge">Pick</span>}
                        {location.putaway_eligible && <span className="wh-badge is-green">Putaway</span>}
                      </div>
                    </td>
                    {canManage && (
                      <td style={{ textAlign: 'right' }}>
                        <button
                          type="button"
                          className="table-action-btn is-danger"
                          onClick={() => removeLocation(location)}
                          disabled={busy}
                          title={`Delete ${location.code}`}
                          aria-label={`Delete ${location.code}`}
                        >
                          <Trash2 size={15} />
                        </button>
                      </td>
                    )}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </section>
      )}

      {formOpen && (
        <div className="modal-overlay" onClick={() => !busy && setFormOpen(false)}>
          <form className="procurement-modal" style={{ maxWidth: 560 }} onClick={(e) => e.stopPropagation()} onSubmit={createLocation}>
            <div className="procurement-modal-header">
              <div>
                <h3 style={{ margin: 0, fontSize: 18, fontWeight: 800, color: '#0f172a' }}>Add Location</h3>
                <p style={{ margin: '3px 0 0', fontSize: 12, color: '#64748b' }}>Zone → Aisle → Rack → Shelf → Bin.</p>
              </div>
              <button type="button" className="topbar-icon-button" onClick={() => setFormOpen(false)} aria-label="Close" disabled={busy}>
                <X size={18} />
              </button>
            </div>
            <div className="procurement-modal-body" style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
              {formError && <Alert type="error" message={formError} />}
              <div className="wh-form-grid">
                <div>
                  <label className="form-label" htmlFor="location-code">Code *</label>
                  <input
                    id="location-code"
                    className="wh-input"
                    value={form.code}
                    onChange={(e) => setForm({ ...form, code: e.target.value.toUpperCase() })}
                    placeholder="e.g. A01-R01-S1-B04"
                    maxLength={50}
                    required
                    autoFocus
                  />
                </div>
                <div>
                  <label className="form-label">Type *</label>
                  <CustomDropdown value={form.location_type} onChange={(val) => setForm({ ...form, location_type: String(val) })} options={LOCATION_TYPES} />
                </div>
                <div>
                  <label className="form-label">Inside</label>
                  <CustomDropdown
                    value={form.parent_id}
                    onChange={(val) => setForm({ ...form, parent_id: String(val) })}
                    searchable
                    options={[
                      { value: '', label: 'Nothing (top level)' },
                      ...locations
                        .filter((l) => l.location_type !== 'bin')
                        .map((l) => ({ value: String(l.id), label: l.code, description: humanize(l.location_type) })),
                    ]}
                  />
                </div>
                <div>
                  <label className="form-label" htmlFor="location-capacity">Capacity (units)</label>
                  <input
                    id="location-capacity"
                    className="wh-input"
                    type="number"
                    min={0}
                    value={form.capacity}
                    onChange={(e) => setForm({ ...form, capacity: e.target.value })}
                    placeholder="Optional"
                  />
                </div>
              </div>
              <div style={{ display: 'flex', gap: 20, flexWrap: 'wrap' }}>
                <label className="wh-check">
                  <input type="checkbox" checked={form.picking_eligible} onChange={(e) => setForm({ ...form, picking_eligible: e.target.checked })} />
                  Orders can be picked from here
                </label>
                <label className="wh-check">
                  <input type="checkbox" checked={form.putaway_eligible} onChange={(e) => setForm({ ...form, putaway_eligible: e.target.checked })} />
                  Stock can be put away here
                </label>
              </div>
            </div>
            <div className="procurement-modal-footer" style={{ justifyContent: 'flex-end' }}>
              <div style={{ display: 'flex', gap: 8 }}>
                <button type="button" className="btn-outline" onClick={() => setFormOpen(false)} disabled={busy}>
                  Cancel
                </button>
                <button type="submit" className="btn-primary" disabled={busy || !form.code.trim()}>
                  {busy && <Loader2 size={16} className="animate-spin" />}
                  <span>Add Location</span>
                </button>
              </div>
            </div>
          </form>
        </div>
      )}

      {dialog}
    </div>
  );
};

export default WarehouseLocationsTab;
