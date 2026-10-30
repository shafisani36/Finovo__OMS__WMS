import React, { useCallback, useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { Camera, CameraOff, CheckCircle2, Loader2, MapPin, ScanLine, X } from 'lucide-react';
import { Alert } from '../common/Alert';
import ProductThumb from '../catalog/ProductThumb';
import { CameraScanner } from '../barcode/BarcodeScanner';
import omsService from '../../services/omsService';
import type { PickScanTask, PickWaveDetail } from '../../services/omsService';
import { formatDate } from '../../utils/format';
import '../barcode/Barcode.css';
import './Picking.css';
import { errorMessage } from '../../utils/errors';

type Feedback = { type: 'success' | 'error'; text: string } | null;


/**
 * Scan field for a USB/Bluetooth scanner (it types the code and presses Enter) or the device camera. It keeps
 * the focus, so the operator can scan one unit after another without touching the screen.
 */
export const ScanField: React.FC<{ busy: boolean; disabled?: boolean; placeholder?: string; onScan: (code: string) => void }> = ({
  busy,
  disabled,
  placeholder = 'Scan a unit, or type its SKU and press Enter',
  onScan,
}) => {
  const [code, setCode] = useState('');
  const [camera, setCamera] = useState(false);
  const input = useRef<HTMLInputElement>(null);

  useEffect(() => {
    if (!busy) input.current?.focus();
  }, [busy]);

  const submit = (value: string) => {
    const term = value.trim();
    setCode('');
    if (term) onScan(term);
  };

  return (
    <>
      <form
        className="scanner-input"
        onSubmit={(e) => {
          e.preventDefault();
          submit(code);
        }}
      >
        {busy ? <Loader2 size={18} className="animate-spin" color="#1e56ff" /> : <ScanLine size={18} color="#1e56ff" />}
        <input
          ref={input}
          value={code}
          onChange={(e) => setCode(e.target.value)}
          placeholder={disabled ? 'Nothing left to scan' : placeholder}
          aria-label="Barcode"
          autoComplete="off"
          disabled={disabled}
        />
        <button
          type="button"
          className="scanner-camera-toggle"
          onClick={() => setCamera((on) => !on)}
          disabled={disabled}
          title={camera ? 'Close camera' : 'Scan with the camera'}
          aria-label={camera ? 'Close camera' : 'Scan with the camera'}
          aria-pressed={camera}
        >
          {camera ? <CameraOff size={18} /> : <Camera size={18} />}
        </button>
      </form>
      {camera && (
        <CameraScanner
          onDetected={(text) => {
            setCamera(false);
            submit(text);
          }}
        />
      )}
    </>
  );
};

/** Modal frame shared by the scan screens; closes on Escape and on a click outside. */
const ScanModal: React.FC<{ title: string; subtitle: string; onClose: () => void; children: React.ReactNode; footer?: React.ReactNode }> = ({
  title,
  subtitle,
  onClose,
  children,
  footer,
}) => {
  useEffect(() => {
    const onKeyDown = (e: KeyboardEvent) => e.key === 'Escape' && onClose();
    document.addEventListener('keydown', onKeyDown);
    return () => document.removeEventListener('keydown', onKeyDown);
  }, [onClose]);

  return createPortal(
    <div className="modal-overlay" onClick={onClose}>
      <div className="procurement-modal pick-scan-modal" onClick={(e) => e.stopPropagation()}>
        <div className="procurement-modal-header">
          <div>
            <h3 className="pick-scan-title">{title}</h3>
            <p className="pick-scan-subtitle">{subtitle}</p>
          </div>
          <button type="button" onClick={onClose} className="topbar-icon-button" aria-label="Close">
            <X size={18} />
          </button>
        </div>
        <div className="procurement-modal-body pick-scan-body">{children}</div>
        {footer && <div className="procurement-modal-footer">{footer}</div>}
      </div>
    </div>,
    document.body
  );
};

const Progress: React.FC<{ done: number; total: number; label: string }> = ({ done, total, label }) => (
  <div className="pick-progress">
    <div className="pick-progress-text">
      <strong>
        {done} / {total}
      </strong>
      <span>{label}</span>
    </div>
    <div className="pick-progress-track">
      <span style={{ width: `${total ? Math.min(100, (done / total) * 100) : 100}%` }} />
    </div>
  </div>
);

/** The bin to pick from, with the lot and expiry date of that stock (the nearest expiry is picked first). */
const Location: React.FC<{ code: string | null; lot?: string | null; expiry?: string | null }> = ({ code, lot, expiry }) => (
  <span className="pick-location-row">
    <span className={`pick-location${code ? '' : ' is-empty'}`}>
      <MapPin size={12} />
      {code ?? 'No bin'}
    </span>
    {lot && <span className="pick-lot">Lot {lot}</span>}
    {expiry && <span className="pick-lot">Expires {formatDate(expiry)}</span>}
  </span>
);

/**
 * Scan-verified picking and packing of one order. Each unit is scanned at the shelf, then again at packing;
 * a unit that is not in the order, or one too many, is refused. The order moves on by itself when complete.
 */
export const PickScanModal: React.FC<{ taskId: number; canScan: boolean; onClose: () => void; onChanged: (message: string) => void }> = ({
  taskId,
  canScan,
  onClose,
  onChanged,
}) => {
  const [task, setTask] = useState<PickScanTask | null>(null);
  const [busy, setBusy] = useState(false);
  const [feedback, setFeedback] = useState<Feedback>(null);

  useEffect(() => {
    omsService
      .getPickLines(taskId)
      .then(setTask)
      .catch((err) => setFeedback({ type: 'error', text: errorMessage(err, 'The order could not be loaded.') }));
  }, [taskId]);

  const scan = async (code: string) => {
    try {
      setBusy(true);
      const res = await omsService.scanPickUnit(taskId, code);
      setTask(res.data);
      setFeedback({ type: 'success', text: res.message });
      if (res.completed) onChanged(res.message);
    } catch (err) {
      setFeedback({ type: 'error', text: errorMessage(err, 'The scan could not be saved.') });
    } finally {
      setBusy(false);
    }
  };

  const stage = task?.stage;
  const field = stage === 'pack' ? 'packed' : 'picked';

  return (
    <ScanModal
      title={stage === 'pack' ? `Pack ${task?.order_number ?? ''}` : `Pick ${task?.order_number ?? ''}`}
      subtitle={
        stage === 'pack'
          ? 'Scan every unit again as it goes into the box.'
          : stage === 'pick'
            ? 'Scan every unit as you take it from the shelf.'
            : 'This order is packed and waiting for dispatch in Shipments.'
      }
      onClose={onClose}
    >
      {!task ? (
        feedback ? <Alert type="error" message={feedback.text} /> : <Loader2 size={20} className="animate-spin" color="#1e56ff" />
      ) : (
        <>
          <Progress done={task.scanned} total={task.units} label={stage === 'pick' ? 'units picked' : 'units packed'} />
          {canScan && <ScanField busy={busy} disabled={!stage} onScan={scan} />}
          {feedback && <Alert type={feedback.type} message={feedback.text} />}

          <ul className="pick-lines">
            {task.lines.map((line) => {
              const done = stage ? line[field] : line.quantity;
              const complete = line.scannable && done >= line.quantity;
              return (
                <li key={line.order_item_id} className={complete ? 'is-complete' : ''}>
                  <ProductThumb src={line.image_url} alt={line.name} size={40} />
                  <div className="pick-line-main">
                    <strong>{line.name}</strong>
                    <span>
                      SKU {line.sku}
                      {line.barcode ? ` · ${line.barcode}` : ''}
                    </span>
                    {line.scannable ? (
                      <Location code={line.location} lot={line.lot_number} expiry={line.expiry_date} />
                    ) : (
                      <span className="pick-line-note">Not a Finovo product: check it by eye.</span>
                    )}
                  </div>
                  <div className="pick-line-count">
                    {complete ? <CheckCircle2 size={18} color="#16a34a" /> : null}
                    <strong>
                      {line.scannable ? done : '–'} / {line.quantity}
                    </strong>
                  </div>
                </li>
              );
            })}
          </ul>
        </>
      )}
    </ScanModal>
  );
};

/**
 * Wave picking: one walk through the warehouse for several orders. The list is in the order of the bins, and
 * each scan says which order's tote the unit goes into.
 */
export const PickWaveModal: React.FC<{ waveId: number; canScan: boolean; onClose: () => void; onChanged: (message: string) => void }> = ({
  waveId,
  canScan,
  onClose,
  onChanged,
}) => {
  const [wave, setWave] = useState<PickWaveDetail | null>(null);
  const [busy, setBusy] = useState(false);
  const [feedback, setFeedback] = useState<Feedback>(null);
  const [tote, setTote] = useState<number | null>(null);

  const load = useCallback(
    () =>
      omsService
        .getPickWave(waveId)
        .then(setWave)
        .catch((err) => setFeedback({ type: 'error', text: errorMessage(err, 'The wave could not be loaded.') })),
    [waveId]
  );

  useEffect(() => {
    load();
  }, [load]);

  const scan = async (code: string) => {
    try {
      setBusy(true);
      const res = await omsService.scanWaveUnit(waveId, code);
      setWave(res.data);
      setTote(res.tote);
      setFeedback({ type: 'success', text: res.message });
      if (res.data.status !== 'open') onChanged(`Wave ${res.data.wave_number} is picked; take the totes to packing.`);
    } catch (err) {
      setTote(null);
      setFeedback({ type: 'error', text: errorMessage(err, 'The scan could not be saved.') });
    } finally {
      setBusy(false);
    }
  };

  const cancel = async () => {
    if (!wave || !window.confirm(`Cancel wave ${wave.wave_number}? Orders not picked yet go back to the picking list.`)) return;
    try {
      const res = await omsService.cancelPickWave(wave.id);
      onChanged(res.message);
      onClose();
    } catch (err) {
      setFeedback({ type: 'error', text: errorMessage(err, 'The wave could not be cancelled.') });
    }
  };

  const open = wave?.status === 'open';

  return (
    <ScanModal
      title={wave ? `Wave ${wave.wave_number}` : 'Wave'}
      subtitle={
        wave
          ? `${wave.totes.length} orders${wave.warehouse ? ` · ${wave.warehouse}` : ''}. Walk the list from top to bottom and put each unit in the tote shown.`
          : 'Loading…'
      }
      onClose={onClose}
      footer={
        canScan && open ? (
          <button type="button" className="btn-outline" onClick={cancel}>
            Cancel Wave
          </button>
        ) : undefined
      }
    >
      {!wave ? (
        feedback ? <Alert type="error" message={feedback.text} /> : <Loader2 size={20} className="animate-spin" color="#1e56ff" />
      ) : (
        <>
          <Progress done={wave.picked} total={wave.units} label={open ? 'units picked' : `units picked · wave ${wave.status}`} />
          {canScan && <ScanField busy={busy} disabled={!open} onScan={scan} />}

          {tote !== null && feedback?.type === 'success' ? (
            <div className="pick-tote-callout">
              <span>Put in tote</span>
              <strong>{tote}</strong>
              <em>{feedback.text}</em>
            </div>
          ) : (
            feedback && <Alert type={feedback.type} message={feedback.text} />
          )}

          <div className="pick-totes">
            {wave.totes.map((t) => (
              <span key={t.tote} className={t.status === 'not_started' || t.status === 'in_progress' ? '' : 'is-complete'} title={t.customer ?? ''}>
                <strong>Tote {t.tote}</strong> {t.order_number}
              </span>
            ))}
          </div>

          <ul className="pick-lines">
            {wave.lines.map((line) => {
              const complete = line.picked >= line.quantity;
              return (
                <li key={line.product_variant_id} className={complete ? 'is-complete' : ''}>
                  <ProductThumb src={line.image_url} alt={line.name} size={40} />
                  <div className="pick-line-main">
                    <strong>{line.name}</strong>
                    <span>SKU {line.sku}</span>
                    <Location code={line.location} lot={line.lot_number} expiry={line.expiry_date} />
                    <span className="pick-line-totes">
                      {line.totes.map((t) => (
                        <span key={t.tote} className={t.picked >= t.quantity ? 'is-complete' : ''}>
                          T{t.tote}: {t.picked}/{t.quantity}
                        </span>
                      ))}
                    </span>
                  </div>
                  <div className="pick-line-count">
                    {complete ? <CheckCircle2 size={18} color="#16a34a" /> : null}
                    <strong>
                      {line.picked} / {line.quantity}
                    </strong>
                  </div>
                </li>
              );
            })}
          </ul>
        </>
      )}
    </ScanModal>
  );
};
