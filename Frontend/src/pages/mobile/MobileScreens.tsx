import React, { useCallback, useEffect, useState } from 'react';
import { ChevronRight, Loader2, MapPin, Minus, Plus } from 'lucide-react';
import { Alert } from '../../components/common/Alert';
import { PickScanModal, PickWaveModal, ScanField } from '../../components/picking/PickingScan';
import omsService from '../../services/omsService';
import type {
  CycleCountModel,
  InventoryReceiptModel,
  MobileLocation,
  MobileProduct,
  PickWaveSummary,
  PickingItem,
  PutawaySuggestion,
} from '../../services/omsService';
import { formatDate } from '../../utils/format';
import { errorMessage } from '../../utils/errors';

type Feedback = { type: 'success' | 'error'; text: string } | null;

export interface ScreenProps {
  warehouseId: number;
  /** The store being managed: lists show only its orders and products. */
  storeId?: number;
  /** Whether the user may do the screen's work (not only look). */
  canAct: boolean;
}


/**
 * Quantities typed or scanned on the phone are kept on the device until they are sent, so a dropped Wi-Fi
 * signal or a closed tab does not lose the work. Storage can be unavailable (private mode); then they are
 * only kept in memory.
 */
const useDeviceDraft = (key: string) => {
  const [values, setValues] = useState<Record<number, number>>(() => {
    try {
      return JSON.parse(window.localStorage.getItem(key) ?? '{}');
    } catch {
      return {};
    }
  });

  useEffect(() => {
    try {
      window.localStorage.setItem(key, JSON.stringify(values));
    } catch {
      // Not saved on the device; the values stay in memory.
    }
  }, [key, values]);

  const clear = () => {
    try {
      window.localStorage.removeItem(key);
    } catch {
      // Nothing to remove.
    }
    setValues({});
  };

  return [values, setValues, clear] as const;
};

const Quantity: React.FC<{ value: number; max?: number; onChange: (value: number) => void; label: string }> = ({ value, max, onChange, label }) => (
  <div className="m-qty">
    <button type="button" onClick={() => onChange(Math.max(0, value - 1))} aria-label={`One less ${label}`}>
      <Minus size={16} />
    </button>
    <input
      type="number"
      inputMode="numeric"
      min={0}
      max={max}
      value={value}
      onChange={(e) => onChange(Math.max(0, Math.min(max ?? Number.MAX_SAFE_INTEGER, Number(e.target.value) || 0)))}
      aria-label={label}
    />
    <button type="button" onClick={() => onChange(Math.min(max ?? Number.MAX_SAFE_INTEGER, value + 1))} aria-label={`One more ${label}`}>
      <Plus size={16} />
    </button>
  </div>
);

const ListButton: React.FC<{ title: string; meta: string; badge?: string; onClick: () => void }> = ({ title, meta, badge, onClick }) => (
  <button type="button" className="m-list-item" onClick={onClick}>
    <div>
      <strong>{title}</strong>
      <span>{meta}</span>
    </div>
    {badge && <span className="m-badge">{badge}</span>}
    <ChevronRight size={18} />
  </button>
);

const Loading = () => (
  <div className="m-center">
    <Loader2 size={22} className="animate-spin" />
  </div>
);

/**
 * Pick: the warehouse's orders waiting to be picked or packed, and its open waves. Each opens the same scan
 * screen as on the desktop.
 */
export const PickScreen: React.FC<ScreenProps> = ({ warehouseId, storeId, canAct }) => {
  const [tasks, setTasks] = useState<PickingItem[] | null>(null);
  const [waves, setWaves] = useState<PickWaveSummary[]>([]);
  const [taskId, setTaskId] = useState<number | null>(null);
  const [waveId, setWaveId] = useState<number | null>(null);
  const [feedback, setFeedback] = useState<Feedback>(null);

  const load = useCallback(async () => {
    try {
      const [list, open] = await Promise.all([
        omsService.getPickingTasks({ warehouse_id: warehouseId, store_id: storeId, per_page: 50 }),
        omsService.getPickWaves({ warehouse_id: warehouseId, store_id: storeId }),
      ]);
      setTasks((list.data as PickingItem[]).filter((task) => task.status !== 'packed'));
      setWaves(open);
    } catch (err) {
      setFeedback({ type: 'error', text: errorMessage(err, 'The picking list could not be loaded.') });
      setTasks([]);
    }
  }, [warehouseId, storeId]);

  useEffect(() => {
    load();
  }, [load]);

  const changed = (message: string) => {
    setFeedback({ type: 'success', text: message });
    load();
  };

  return (
    <div className="m-stack">
      {feedback && <Alert type={feedback.type} message={feedback.text} />}
      {waves.length > 0 && (
        <section>
          <h3 className="m-section">Open waves</h3>
          {waves.map((wave) => (
            <ListButton key={wave.id} title={wave.wave_number} meta={`${wave.orders} orders · pick into totes`} onClick={() => setWaveId(wave.id)} />
          ))}
        </section>
      )}
      <section>
        <h3 className="m-section">Orders</h3>
        {tasks === null ? (
          <Loading />
        ) : tasks.length === 0 ? (
          <p className="m-empty">Nothing to pick or pack in this warehouse.</p>
        ) : (
          tasks.map((task) => (
            <ListButton
              key={task.id}
              title={task.order_number}
              meta={`${task.customer} · ${task.items_count} item(s)${task.wave_number ? ` · ${task.wave_number}` : ''}`}
              badge={task.status === 'picked' ? 'Pack' : task.status === 'in_progress' ? 'Picking' : 'Pick'}
              onClick={() => setTaskId(task.id)}
            />
          ))
        )}
      </section>
      {taskId !== null && <PickScanModal taskId={taskId} canScan={canAct} onClose={() => {
            setTaskId(null);
            load();
          }} onChanged={changed} />}
      {waveId !== null && <PickWaveModal waveId={waveId} canScan={canAct} onClose={() => {
            setWaveId(null);
            load();
          }} onChanged={changed} />}
    </div>
  );
};

/**
 * Receive: count the units of an expected delivery by scanning them, then confirm the receipt.
 */
export const ReceiveScreen: React.FC<ScreenProps> = ({ warehouseId, storeId, canAct }) => {
  const [receipts, setReceipts] = useState<InventoryReceiptModel[] | null>(null);
  const [receipt, setReceipt] = useState<InventoryReceiptModel | null>(null);
  const [feedback, setFeedback] = useState<Feedback>(null);

  const load = useCallback(async () => {
    try {
      const res = await omsService.getInventoryReceipts({ warehouse_id: warehouseId, store_id: storeId, status: 'draft', per_page: 50 });
      setReceipts(res.data);
    } catch (err) {
      setFeedback({ type: 'error', text: errorMessage(err, 'The deliveries could not be loaded.') });
      setReceipts([]);
    }
  }, [warehouseId, storeId]);

  useEffect(() => {
    load();
  }, [load]);

  if (receipt) {
    return (
      <ReceiveReceipt
        warehouseId={warehouseId}
        receipt={receipt}
        canAct={canAct}
        onDone={(message) => {
          setReceipt(null);
          setFeedback({ type: 'success', text: message });
          load();
        }}
      />
    );
  }

  return (
    <div className="m-stack">
      {feedback && <Alert type={feedback.type} message={feedback.text} />}
      {receipts === null ? (
        <Loading />
      ) : receipts.length === 0 ? (
        <p className="m-empty">No deliveries are expected in this warehouse.</p>
      ) : (
        receipts.map((r) => (
          <ListButton key={r.id} title={r.receipt_number} meta={`${r.supplier_name || 'Supplier'} · ${r.items_count} product(s), ${r.total_expected} units expected`} onClick={() => setReceipt(r)} />
        ))
      )}
    </div>
  );
};

const ReceiveReceipt: React.FC<{ warehouseId: number; receipt: InventoryReceiptModel; canAct: boolean; onDone: (message: string) => void }> = ({ warehouseId, receipt, canAct, onDone }) => {
  const items = receipt.items ?? [];
  const [counts, setCounts, clear] = useDeviceDraft(`finovo.mobile.receive.${receipt.id}`);
  const [busy, setBusy] = useState(false);
  const [feedback, setFeedback] = useState<Feedback>(null);
  const total = items.reduce((sum, item) => sum + (counts[item.id] ?? 0), 0);

  const scan = async (code: string) => {
    try {
      setBusy(true);
      const product = await omsService.scanMobileProduct(warehouseId, code);
      const item = items.find((it) => it.product_variant_id === product.product_variant_id);
      if (!item) {
        setFeedback({ type: 'error', text: `${product.sku} is not on ${receipt.receipt_number}. Keep it aside.` });
        return;
      }
      setCounts((prev) => ({ ...prev, [item.id]: (prev[item.id] ?? 0) + 1 }));
      setFeedback({ type: 'success', text: `1 × ${product.sku} counted (${(counts[item.id] ?? 0) + 1} of ${item.expected_quantity} expected).` });
    } catch (err) {
      setFeedback({ type: 'error', text: errorMessage(err, 'The product could not be found.') });
    } finally {
      setBusy(false);
    }
  };

  const confirm = async () => {
    try {
      setBusy(true);
      const res = await omsService.confirmInventoryReceipt(receipt.id, {
        items: items.filter((item) => (counts[item.id] ?? 0) > 0).map((item) => ({ item_id: item.id, received_quantity: counts[item.id] })),
      });
      clear();
      onDone(res.message ?? `${receipt.receipt_number} received.`);
    } catch (err) {
      setFeedback({ type: 'error', text: errorMessage(err, 'The delivery could not be confirmed.') });
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="m-stack">
      <div className="m-card">
        <strong>{receipt.receipt_number}</strong>
        <span>
          {receipt.supplier_name || 'Supplier'} · {total} units counted
        </span>
      </div>
      {canAct && <ScanField busy={busy} onScan={scan} placeholder="Scan each unit as it comes off the van" />}
      {feedback && <Alert type={feedback.type} message={feedback.text} />}
      {items.map((item) => {
        const count = counts[item.id] ?? 0;
        return (
          <div key={item.id} className={`m-line${count >= item.expected_quantity ? ' is-done' : ''}`}>
            <div>
              <strong>{item.product_name}</strong>
              <span>
                {item.sku} · {item.expected_quantity} expected
              </span>
            </div>
            <Quantity value={count} onChange={(value) => setCounts((prev) => ({ ...prev, [item.id]: value }))} label={`${item.sku} received`} />
          </div>
        );
      })}
      {canAct && (
        <button type="button" className="btn-primary m-primary" onClick={confirm} disabled={busy || total === 0}>
          {busy ? <Loader2 size={16} className="animate-spin" /> : null}
          Confirm Receipt
        </button>
      )}
    </div>
  );
};

/**
 * Put away: received deliveries; for each product the suggested bin, then scan the bin the units go into.
 */
export const PutAwayScreen: React.FC<ScreenProps> = ({ warehouseId, storeId, canAct }) => {
  const [receipts, setReceipts] = useState<InventoryReceiptModel[] | null>(null);
  const [selected, setSelected] = useState<{ receipt: InventoryReceiptModel; itemId: number } | null>(null);
  const [feedback, setFeedback] = useState<Feedback>(null);

  const load = useCallback(async () => {
    try {
      const [received, started] = await Promise.all([
        omsService.getInventoryReceipts({ warehouse_id: warehouseId, store_id: storeId, status: 'received', per_page: 50 }),
        omsService.getInventoryReceipts({ warehouse_id: warehouseId, store_id: storeId, status: 'putaway_in_progress', per_page: 50 }),
      ]);
      setReceipts([...started.data, ...received.data]);
    } catch (err) {
      setFeedback({ type: 'error', text: errorMessage(err, 'The deliveries could not be loaded.') });
      setReceipts([]);
    }
  }, [warehouseId, storeId]);

  useEffect(() => {
    load();
  }, [load]);

  if (selected) {
    const item = selected.receipt.items?.find((it) => it.id === selected.itemId);
    if (item) {
      return (
        <PutAwayItem
          warehouseId={warehouseId}
          receipt={selected.receipt}
          item={item}
          canAct={canAct}
          onDone={(message) => {
            setSelected(null);
            setFeedback({ type: 'success', text: message });
            load();
          }}
        />
      );
    }
  }

  return (
    <div className="m-stack">
      {feedback && <Alert type={feedback.type} message={feedback.text} />}
      {receipts === null ? (
        <Loading />
      ) : receipts.length === 0 ? (
        <p className="m-empty">Nothing is waiting at the dock.</p>
      ) : (
        receipts.map((r) => (
          <section key={r.id}>
            <h3 className="m-section">{r.receipt_number}</h3>
            {(r.items ?? []).map((item) => (
              <ListButton
                key={item.id}
                title={item.product_name}
                meta={`${item.sku} · ${item.received_quantity} received${item.lot_number ? ` · Lot ${item.lot_number}` : ''}`}
                onClick={() => setSelected({ receipt: r, itemId: item.id })}
              />
            ))}
          </section>
        ))
      )}
    </div>
  );
};

type ReceiptItem = NonNullable<InventoryReceiptModel['items']>[number];

const PutAwayItem: React.FC<{ warehouseId: number; receipt: InventoryReceiptModel; item: ReceiptItem; canAct: boolean; onDone: (message: string) => void }> = ({
  warehouseId,
  receipt,
  item,
  canAct,
  onDone,
}) => {
  const [quantity, setQuantity] = useState(item.received_quantity || item.expected_quantity);
  const [suggestion, setSuggestion] = useState<PutawaySuggestion | null>(null);
  const [bin, setBin] = useState<MobileLocation | null>(null);
  const [busy, setBusy] = useState(false);
  const [feedback, setFeedback] = useState<Feedback>(null);

  useEffect(() => {
    omsService
      .getPutawaySuggestion(warehouseId, { product_variant_id: item.product_variant_id, quantity: Math.max(1, quantity), lot_number: item.lot_number ?? null })
      .then(setSuggestion)
      .catch(() => setSuggestion(null));
  }, [warehouseId, item.product_variant_id, item.lot_number, quantity]);

  const scanBin = async (code: string) => {
    try {
      setBusy(true);
      const location = await omsService.scanMobileLocation(warehouseId, code);
      setBin(location);
      setFeedback(
        location.putaway_eligible
          ? { type: 'success', text: `Bin ${location.code} scanned.` }
          : { type: 'error', text: `${location.code} does not take putaway. Scan another bin.` }
      );
    } catch (err) {
      setFeedback({ type: 'error', text: errorMessage(err, 'The bin could not be found.') });
    } finally {
      setBusy(false);
    }
  };

  const putAway = async () => {
    if (!bin) return;
    try {
      setBusy(true);
      await omsService.putawayReceiptItem(receipt.id, { item_id: item.id, destination_location_code: bin.code, quantity });
      onDone(`${quantity} × ${item.sku} put away into ${bin.code}.`);
    } catch (err) {
      setFeedback({ type: 'error', text: errorMessage(err, 'The putaway could not be saved.') });
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="m-stack">
      <div className="m-card">
        <strong>{item.product_name}</strong>
        <span>
          {item.sku}
          {item.lot_number ? ` · Lot ${item.lot_number}` : ''} · from {receipt.receipt_number}
        </span>
      </div>
      <div className="m-line">
        <span className="m-label">Units to put away</span>
        <Quantity value={quantity} max={item.received_quantity || undefined} onChange={setQuantity} label="units to put away" />
      </div>
      {suggestion?.location && (
        <div className="m-suggest">
          <MapPin size={18} />
          <div>
            <strong>Go to {suggestion.location.code}</strong>
            <span>{suggestion.reason}</span>
          </div>
        </div>
      )}
      {canAct && <ScanField busy={busy} onScan={scanBin} placeholder="Scan the bin you put the units in" />}
      {feedback && <Alert type={feedback.type} message={feedback.text} />}
      {bin && suggestion?.location && bin.code !== suggestion.location.code && bin.putaway_eligible && (
        <Alert type="info" message={`${bin.code} is not the suggested bin; the units are recorded where you put them.`} />
      )}
      {canAct && (
        <button type="button" className="btn-primary m-primary" onClick={putAway} disabled={busy || !bin?.putaway_eligible || quantity < 1}>
          {busy ? <Loader2 size={16} className="animate-spin" /> : null}
          {bin ? `Put ${quantity} into ${bin.code}` : 'Scan a bin first'}
        </button>
      )}
    </div>
  );
};

/**
 * Move: scan a product, choose the bin it comes from, then scan the bin it goes to.
 */
export const MoveScreen: React.FC<ScreenProps> = ({ warehouseId, canAct }) => {
  const [product, setProduct] = useState<MobileProduct | null>(null);
  const [from, setFrom] = useState<MobileProduct['bins'][number] | null>(null);
  const [quantity, setQuantity] = useState(1);
  const [busy, setBusy] = useState(false);
  const [feedback, setFeedback] = useState<Feedback>(null);

  const scanProduct = async (code: string) => {
    try {
      setBusy(true);
      setFrom(null);
      setProduct(await omsService.scanMobileProduct(warehouseId, code));
      setFeedback(null);
    } catch (err) {
      setFeedback({ type: 'error', text: errorMessage(err, 'The product could not be found.') });
    } finally {
      setBusy(false);
    }
  };

  const scanTarget = async (code: string) => {
    if (!product || !from) return;
    try {
      setBusy(true);
      const target = await omsService.scanMobileLocation(warehouseId, code);
      const res = await omsService.moveWarehouseStock(warehouseId, {
        product_variant_id: product.product_variant_id,
        from_location_id: from.location_id,
        to_location_id: target.id,
        quantity,
        lot_number: from.lot_number,
      });
      setFeedback({ type: 'success', text: res.message });
      setProduct(await omsService.scanMobileProduct(warehouseId, product.sku));
      setFrom(null);
    } catch (err) {
      setFeedback({ type: 'error', text: errorMessage(err, 'The units could not be moved.') });
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="m-stack">
      {!from && canAct && <ScanField busy={busy} onScan={scanProduct} placeholder="Scan the product to move" />}
      {feedback && <Alert type={feedback.type} message={feedback.text} />}
      {product && !from && (
        <section>
          <h3 className="m-section">
            {product.name} · {product.sku}
          </h3>
          {product.bins.length === 0 ? (
            <p className="m-empty">This product is not in any bin of this warehouse.</p>
          ) : (
            product.bins.map((bin) => (
              <ListButton
                key={`${bin.location_id}-${bin.lot_number ?? ''}`}
                title={`From ${bin.code}`}
                meta={`${bin.quantity} units${bin.lot_number ? ` · Lot ${bin.lot_number}` : ''}${bin.expiry_date ? ` · Expires ${formatDate(bin.expiry_date)}` : ''}`}
                onClick={() => {
                  setFrom(bin);
                  setQuantity(bin.quantity);
                }}
              />
            ))
          )}
        </section>
      )}
      {product && from && (
        <>
          <div className="m-card">
            <strong>
              {product.sku} from {from.code}
            </strong>
            <span>{from.quantity} units in the bin</span>
          </div>
          <div className="m-line">
            <span className="m-label">Units to move</span>
            <Quantity value={quantity} max={from.quantity} onChange={setQuantity} label="units to move" />
          </div>
          <ScanField busy={busy} onScan={scanTarget} placeholder="Scan the bin the units go to" />
          <button type="button" className="btn-outline m-primary" onClick={() => setFrom(null)}>
            Choose another bin
          </button>
        </>
      )}
    </div>
  );
};

/**
 * Count: scan a bin, then every unit in it; the counts are sent for review when done.
 */
export const CountScreen: React.FC<ScreenProps> = ({ warehouseId, storeId, canAct }) => {
  const [counts, setCounts] = useState<CycleCountModel[] | null>(null);
  const [count, setCount] = useState<CycleCountModel | null>(null);
  const [feedback, setFeedback] = useState<Feedback>(null);

  const load = useCallback(async () => {
    try {
      const res = await omsService.getCycleCounts({ warehouse_id: warehouseId, store_id: storeId, status: 'in_progress', per_page: 50 });
      setCounts(res.data);
    } catch (err) {
      setFeedback({ type: 'error', text: errorMessage(err, 'The counts could not be loaded.') });
      setCounts([]);
    }
  }, [warehouseId, storeId]);

  useEffect(() => {
    load();
  }, [load]);

  if (count) {
    return (
      <CountSheet
        warehouseId={warehouseId}
        count={count}
        canAct={canAct}
        onDone={(message) => {
          setCount(null);
          setFeedback({ type: 'success', text: message });
          load();
        }}
      />
    );
  }

  return (
    <div className="m-stack">
      {feedback && <Alert type={feedback.type} message={feedback.text} />}
      {counts === null ? (
        <Loading />
      ) : counts.length === 0 ? (
        <p className="m-empty">No cycle count is open in this warehouse.</p>
      ) : (
        counts.map((c) => <ListButton key={c.id} title={c.title} meta={`${c.count_number} · ${c.total_items} lines`} onClick={() => setCount(c)} />)
      )}
    </div>
  );
};

const CountSheet: React.FC<{ warehouseId: number; count: CycleCountModel; canAct: boolean; onDone: (message: string) => void }> = ({ warehouseId, count, canAct, onDone }) => {
  const items = count.items ?? [];
  const [values, setValues, clear] = useDeviceDraft(`finovo.mobile.count.${count.id}`);
  const [bin, setBin] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [feedback, setFeedback] = useState<Feedback>(null);
  const counted = items.filter((item) => values[item.id] !== undefined).length;
  const shown = bin ? items.filter((item) => item.location_code === bin) : items;

  const scan = async (code: string) => {
    try {
      setBusy(true);
      // A bin of the count sets where the counter is; a product then counts one unit in that bin.
      if (items.some((item) => item.location_code === code)) {
        setBin(code);
        setFeedback({ type: 'success', text: `Counting bin ${code}. Scan each unit in it.` });
        return;
      }
      const product = await omsService.scanMobileProduct(warehouseId, code);
      const item = items.find((it) => it.product_variant_id === product.product_variant_id && (!bin || it.location_code === bin));
      if (!item) {
        setFeedback({ type: 'error', text: `${product.sku} is not on this count${bin ? ` for bin ${bin}` : ''}. Put it aside and tell your supervisor.` });
        return;
      }
      setValues((prev) => ({ ...prev, [item.id]: (prev[item.id] ?? 0) + 1 }));
      setFeedback({ type: 'success', text: `1 × ${product.sku} counted in ${item.location_code} (${(values[item.id] ?? 0) + 1}).` });
    } catch (err) {
      setFeedback({ type: 'error', text: errorMessage(err, 'Nothing matches this code.') });
    } finally {
      setBusy(false);
    }
  };

  const submit = async () => {
    try {
      setBusy(true);
      await omsService.recordCycleCounts(
        count.id,
        items.filter((item) => values[item.id] !== undefined).map((item) => ({ item_id: item.id, counted_quantity: values[item.id] }))
      );
      clear();
      onDone(`${count.count_number} sent for review (${counted} of ${items.length} lines counted).`);
    } catch (err) {
      setFeedback({ type: 'error', text: errorMessage(err, 'The counts could not be sent.') });
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="m-stack">
      <div className="m-card">
        <strong>{count.title}</strong>
        <span>
          {counted} of {items.length} lines counted{bin ? ` · Bin ${bin}` : ''}
        </span>
      </div>
      {canAct && <ScanField busy={busy} onScan={scan} placeholder={bin ? `Scan units in ${bin}, or another bin` : 'Scan the bin you are counting'} />}
      {feedback && <Alert type={feedback.type} message={feedback.text} />}
      {bin && (
        <button type="button" className="btn-outline m-primary" onClick={() => setBin(null)}>
          Show every bin
        </button>
      )}
      {shown.map((item) => (
        <div key={item.id} className={`m-line${values[item.id] !== undefined ? ' is-done' : ''}`}>
          <div>
            <strong>{item.product_name}</strong>
            <span>
              {item.location_code} · {item.sku}
              {item.lot_number ? ` · Lot ${item.lot_number}` : ''}
            </span>
          </div>
          <Quantity value={values[item.id] ?? 0} onChange={(value) => setValues((prev) => ({ ...prev, [item.id]: value }))} label={`${item.sku} counted`} />
        </div>
      ))}
      {canAct && (
        <button type="button" className="btn-primary m-primary" onClick={submit} disabled={busy || counted === 0}>
          {busy ? <Loader2 size={16} className="animate-spin" /> : null}
          {counted < items.length ? `Send ${counted} of ${items.length} Lines` : 'Send Counts for Review'}
        </button>
      )}
    </div>
  );
};

/**
 * Look up: scan a product to see where it is, or a bin to see what is in it.
 */
export const LookupScreen: React.FC<ScreenProps> = ({ warehouseId }) => {
  const [result, setResult] = useState<{ product: MobileProduct } | { location: MobileLocation } | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const scan = async (code: string) => {
    try {
      setBusy(true);
      setError(null);
      try {
        setResult({ product: await omsService.scanMobileProduct(warehouseId, code) });
      } catch {
        setResult({ location: await omsService.scanMobileLocation(warehouseId, code) });
      }
    } catch {
      setResult(null);
      setError(`No product or bin matches "${code}".`);
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="m-stack">
      <ScanField busy={busy} onScan={scan} placeholder="Scan a product or a bin" />
      {error && <Alert type="error" message={error} />}
      {result && 'product' in result && (
        <section>
          <h3 className="m-section">
            {result.product.name} · {result.product.sku}
          </h3>
          {result.product.bins.length === 0 ? (
            <p className="m-empty">Not in any bin of this warehouse.</p>
          ) : (
            result.product.bins.map((bin) => (
              <div key={`${bin.location_id}-${bin.lot_number ?? ''}`} className="m-line">
                <div>
                  <strong>{bin.code}</strong>
                  <span>
                    {bin.lot_number ? `Lot ${bin.lot_number}` : 'No lot'}
                    {bin.expiry_date ? ` · Expires ${formatDate(bin.expiry_date)}` : ''}
                    {bin.condition !== 'available' ? ` · ${bin.condition}` : ''}
                  </span>
                </div>
                <span className="m-badge">{bin.quantity}</span>
              </div>
            ))
          )}
        </section>
      )}
      {result && 'location' in result && (
        <section>
          <h3 className="m-section">Bin {result.location.code}</h3>
          {result.location.stock.length === 0 ? (
            <p className="m-empty">This bin is empty.</p>
          ) : (
            result.location.stock.map((line) => (
              <div key={`${line.product_variant_id}-${line.lot_number ?? ''}`} className="m-line">
                <div>
                  <strong>{line.name}</strong>
                  <span>
                    {line.sku}
                    {line.lot_number ? ` · Lot ${line.lot_number}` : ''}
                  </span>
                </div>
                <span className="m-badge">{line.quantity}</span>
              </div>
            ))
          )}
        </section>
      )}
    </div>
  );
};
