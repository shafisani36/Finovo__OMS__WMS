import React, { useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { useNavigate } from 'react-router-dom';
import { Camera, CameraOff, ExternalLink, Loader2, ScanLine, X } from 'lucide-react';
import StatusPill from '../common/StatusPill';
import { Alert } from '../common/Alert';
import ProductThumb from '../catalog/ProductThumb';
import { BarcodeLabel } from './BarcodeLabel';
import OrderWorkflow from '../catalog/OrderWorkflow';
import omsService from '../../services/omsService';
import type { OrderFulfillment, ScanResult, ScannedProduct } from '../../services/omsService';
import { formatMoney } from '../../utils/format';
import './Barcode.css';

const errorText = (err: unknown, fallback: string) => (err instanceof Error && err.message ? err.message : fallback);

/**
 * Live camera preview that reads barcodes and QR codes (phone back camera, or a laptop webcam).
 * The decoder is loaded only when the camera is opened. Browsers allow the camera on HTTPS or localhost.
 */
export const CameraScanner: React.FC<{ onDetected: (text: string) => void }> = ({ onDetected }) => {
  const video = useRef<HTMLVideoElement>(null);
  const detected = useRef(onDetected);
  detected.current = onDetected;
  const [error, setError] = useState<string | null>(null);
  const [starting, setStarting] = useState(true);

  useEffect(() => {
    let stop: (() => void) | undefined;
    let cancelled = false;

    if (!window.isSecureContext || !navigator.mediaDevices?.getUserMedia) {
      setError('The camera works only on a secure address (https:// or localhost). Use a USB scanner or type the code.');
      setStarting(false);
      return;
    }

    import('@zxing/browser')
      .then(({ BrowserMultiFormatReader }) =>
        new BrowserMultiFormatReader().decodeFromConstraints({ video: { facingMode: 'environment' } }, video.current!, (result, _error, controls) => {
          if (result) {
            controls.stop();
            detected.current(result.getText());
          }
        })
      )
      .then((controls) => {
        stop = () => controls.stop();
        if (cancelled) stop();
        setStarting(false);
      })
      .catch((err: unknown) => {
        setStarting(false);
        setError(
          err instanceof DOMException && err.name === 'NotAllowedError'
            ? 'Camera access was blocked. Allow the camera for this site in the browser settings and try again.'
            : 'No camera could be opened on this device. Use a USB scanner or type the code.'
        );
      });

    return () => {
      cancelled = true;
      stop?.();
    };
  }, []);

  if (error) return <Alert type="error" message={error} />;

  return (
    <div className="scanner-camera">
      <video ref={video} muted playsInline />
      <span className="scanner-camera-frame" aria-hidden="true" />
      {starting && (
        <span className="scanner-camera-status">
          <Loader2 size={16} className="animate-spin" /> Opening camera…
        </span>
      )}
    </div>
  );
};

/**
 * Top-bar scan button. A USB or Bluetooth scanner types the code and presses Enter, so the popup works with
 * a scanner or by typing. A scanned order shows its next steps (see OrderWorkflow); a product shows its stock.
 */
export const BarcodeScanner: React.FC = () => {
  const navigate = useNavigate();
  const [open, setOpen] = useState(false);
  const [code, setCode] = useState('');
  const [scannedCode, setScannedCode] = useState('');
  const [result, setResult] = useState<ScanResult | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [camera, setCamera] = useState(false);
  const inputRef = useRef<HTMLInputElement>(null);

  useEffect(() => {
    if (!open) {
      setCamera(false);
      return;
    }
    inputRef.current?.focus();
    const onKeyDown = (e: KeyboardEvent) => {
      if (e.key === 'Escape') setOpen(false);
    };
    document.addEventListener('keydown', onKeyDown);
    return () => document.removeEventListener('keydown', onKeyDown);
  }, [open]);

  const lookUp = async (value: string) => {
    const term = value.trim();
    if (!term) return;
    try {
      setBusy(true);
      setError(null);
      setResult(await omsService.scanBarcode(term));
      setScannedCode(term);
    } catch (err) {
      setResult(null);
      setError(errorText(err, 'Nothing matches this barcode.'));
    } finally {
      setBusy(false);
      setCode('');
      inputRef.current?.focus();
    }
  };

  const go = (path: string) => {
    setOpen(false);
    navigate(path);
  };

  const renderOrder = (order: OrderFulfillment) => (
    <div className="scanner-card">
      <div className="scanner-card-head">
        <div>
          <h4 className="scanner-card-title">{order.order_number}</h4>
          <p className="scanner-card-meta">
            {order.customer}
            {order.store ? ` · ${order.store}` : ''} · {formatMoney(order.total_amount, order.currency ?? 'USD')}
          </p>
        </div>
        <StatusPill status={order.order_status} />
      </div>

      <div className="scanner-lines">
        {order.items.map((item, i) => (
          <span key={i}>
            <strong>{item.quantity} ×</strong> {item.product_name}
            {item.sku ? <span className="scanner-card-meta"> ({item.sku})</span> : null}
          </span>
        ))}
      </div>

      <OrderWorkflow
        order={order}
        onChanged={async (message) => {
          setNotice(message);
          await lookUp(scannedCode);
        }}
      />

      <button type="button" className="btn-finovo-secondary" style={{ alignSelf: 'flex-start' }} onClick={() => go(`/orders?search=${encodeURIComponent(order.order_number)}`)}>
        <ExternalLink size={14} />
        <span>Open in Orders</span>
      </button>
    </div>
  );

  const renderProduct = (product: ScannedProduct) => (
    <div className="scanner-card">
      <div className="scanner-card-head" style={{ justifyContent: 'flex-start' }}>
        <ProductThumb src={product.image_url} alt={product.name} size={48} />
        <div>
          <h4 className="scanner-card-title">{product.name}</h4>
          <p className="scanner-card-meta">
            SKU {product.sku}
            {product.variant ? ` · ${product.variant}` : ''} · {formatMoney(product.price)}
            {!product.is_active ? ' · Inactive' : ''}
          </p>
        </div>
      </div>

      <div className="scanner-stock">
        <div>
          <strong>{product.stock.on_hand}</strong>
          <span>On hand</span>
        </div>
        <div>
          <strong>{product.stock.available}</strong>
          <span>Available</span>
        </div>
        <div>
          <strong>{product.stock.reserved}</strong>
          <span>Reserved</span>
        </div>
      </div>

      {product.warehouses.length > 0 && (
        <div className="scanner-lines">
          {product.warehouses.map((w, i) => (
            <span key={i}>
              <strong>{w.warehouse ?? 'Warehouse'}:</strong> {w.available} available of {w.on_hand}
            </span>
          ))}
        </div>
      )}

      <BarcodeLabel
        value={product.sku}
        details={[product.name, product.variant, `Price ${(Number(product.price) || 0).toFixed(2)}`, `Available ${product.stock.available} of ${product.stock.on_hand}`]}
        caption={product.name}
        printable
      />

      <button type="button" className="btn-finovo-secondary" style={{ alignSelf: 'flex-start' }} onClick={() => go(`/products/${product.product_id}`)}>
        <ExternalLink size={14} />
        <span>Open Product</span>
      </button>
    </div>
  );

  return (
    <>
      <button type="button" className="topbar-icon-button" title="Scan barcode" aria-label="Scan barcode" onClick={() => setOpen(true)}>
        <ScanLine size={18} />
      </button>

      {open &&
        createPortal(
          <div className="modal-overlay" onClick={() => setOpen(false)}>
            <div className="procurement-modal" style={{ maxWidth: 560 }} onClick={(e) => e.stopPropagation()}>
              <div className="procurement-modal-header">
                <div>
                  <h3 style={{ margin: 0, fontSize: 18, fontWeight: 800, color: '#0f172a' }}>Scan Barcode</h3>
                  <p style={{ margin: '3px 0 0 0', fontSize: 12, color: '#64748b' }}>Order number, tracking ID, SKU or product barcode.</p>
                </div>
                <button type="button" onClick={() => setOpen(false)} className="topbar-icon-button" aria-label="Close">
                  <X size={18} />
                </button>
              </div>

              <div className="procurement-modal-body" style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
                <form
                  className="scanner-input"
                  onSubmit={(e) => {
                    e.preventDefault();
                    setNotice(null);
                    lookUp(code);
                  }}
                >
                  {busy ? <Loader2 size={18} className="animate-spin" color="#1e56ff" /> : <ScanLine size={18} color="#1e56ff" />}
                  <input
                    ref={inputRef}
                    value={code}
                    onChange={(e) => setCode(e.target.value)}
                    placeholder="Scan now, or type a code and press Enter"
                    aria-label="Barcode"
                    autoComplete="off"
                  />
                  <button
                    type="button"
                    className="scanner-camera-toggle"
                    onClick={() => setCamera((on) => !on)}
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
                      setNotice(null);
                      lookUp(text);
                    }}
                  />
                )}

                {notice && <Alert type="success" message={notice} />}
                {error && <Alert type="error" message={error} />}

                {result?.type === 'order' && renderOrder(result.data)}
                {result?.type === 'product' && renderProduct(result.data)}
                {!result && !error && !camera && (
                  <p className="scanner-hint">Point a USB scanner at a label, or tap the camera to scan with this device. Each scan replaces the result, so you can scan one parcel after another.</p>
                )}
              </div>
            </div>
          </div>,
          document.body
        )}
    </>
  );
};

export default BarcodeScanner;
