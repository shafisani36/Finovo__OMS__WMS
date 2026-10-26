import React, { useEffect, useRef, useState } from 'react';
import Barcode from 'react-barcode';
import { QRCodeSVG } from 'qrcode.react';
import { Loader2, Printer, X } from 'lucide-react';
import CustomDropdown from '../common/CustomDropdown';
import { Alert } from '../common/Alert';
import omsService from '../../services/omsService';
import type { BarcodeSettings } from '../../services/omsService';
import './Barcode.css';

export const DEFAULT_BARCODE_SETTINGS: BarcodeSettings = {
  format: 'CODE128',
  width: 2,
  height: 60,
  display_value: true,
  font_size: 14,
  margin: 5,
  embed_details: true,
};

const FORMAT_OPTIONS: { value: BarcodeSettings['format']; label: string; description: string }[] = [
  { value: 'CODE128', label: 'Code 128', description: 'Any SKU or order number (recommended)' },
  { value: 'CODE39', label: 'Code 39', description: 'Capital letters, digits and - . $ / + %' },
  { value: 'EAN13', label: 'EAN-13', description: '12–13 digit retail barcodes' },
  { value: 'UPC', label: 'UPC-A', description: '11–12 digit retail barcodes' },
];

// The organization's label settings, loaded once and shared by every barcode on screen.
let cachedSettings: BarcodeSettings | null = null;
let loading: Promise<void> | null = null;
const listeners = new Set<(settings: BarcodeSettings) => void>();

const publish = (settings: BarcodeSettings) => {
  cachedSettings = settings;
  listeners.forEach((listener) => listener(settings));
};

export const useBarcodeSettings = () => {
  const [settings, setSettings] = useState<BarcodeSettings>(cachedSettings ?? DEFAULT_BARCODE_SETTINGS);

  useEffect(() => {
    listeners.add(setSettings);
    if (!cachedSettings && !loading) {
      loading = omsService
        .getBarcodeSettings()
        .then((res) => publish(res.data))
        .catch(() => {
          loading = null;
        });
    }
    return () => {
      listeners.delete(setSettings);
    };
  }, []);

  const save = async (next: BarcodeSettings) => {
    const res = await omsService.updateBarcodeSettings(next);
    publish(res.data);
    return res;
  };

  return { settings, save };
};

/** True when the check digit of an EAN-13 or UPC-A code is right. */
const validCheckDigit = (digits: string): boolean => {
  const body = digits.slice(0, -1);
  const sum = body
    .split('')
    .reverse()
    .reduce((total, digit, i) => total + Number(digit) * (i % 2 === 0 ? 3 : 1), 0);
  return (10 - (sum % 10)) % 10 === Number(digits.at(-1));
};

/** Whether a value can be printed in a format; values that cannot are printed as Code 128 instead. */
const fitsFormat = (value: string, format: BarcodeSettings['format']): boolean => {
  switch (format) {
    case 'EAN13':
      return /^\d{12}$/.test(value) || (/^\d{13}$/.test(value) && validCheckDigit(value));
    case 'UPC':
      return /^\d{11}$/.test(value) || (/^\d{12}$/.test(value) && validCheckDigit(value));
    case 'CODE39':
      return /^[0-9A-Z .$/+%-]+$/.test(value);
    default:
      return true;
  }
};

/**
 * Text inside the label's QR code: the code on the first line (the scan screen reads it), then the details,
 * one per line, so a phone camera shows e.g. the product name, price and stock as plain text.
 */
const qrText = (code: string, details: (string | null | undefined)[]): string =>
  [code, ...details.map((detail) => detail?.trim()).filter(Boolean)].join('\n');

interface BarcodeLabelProps {
  value: string;
  /** Product or order facts put in the label's QR code (when enabled in settings), shown by phone cameras. */
  details?: (string | null | undefined)[];
  /** Text printed under the barcode on the label, e.g. the product name. */
  caption?: string;
  printable?: boolean;
  /** Smaller on screen (printing still uses the full size), for labels shown inside cards. */
  compact?: boolean;
  /** Draft settings to preview instead of the saved ones. */
  settings?: BarcodeSettings;
}

/** On-screen size of compact labels: shorter bars, same crisp bar width. */
const COMPACT_SIZE = { height: 44, font_size: 12, margin: 4 };

/**
 * A label for a SKU, order number or tracking ID, drawn with the organization's label settings.
 * The barcode carries only the code, so it stays short and any scanner reads it first time; when details
 * are enabled, a QR code next to it carries the code and the details for phone cameras.
 */
export const BarcodeLabel: React.FC<BarcodeLabelProps> = ({ value, details, caption, printable = false, compact = false, settings }) => {
  const { settings: saved } = useBarcodeSettings();
  const labelRef = useRef<HTMLDivElement>(null);
  const code = value?.trim();

  if (!code) return null;

  const config = settings ?? saved;
  const format = fitsFormat(code, config.format) ? config.format : 'CODE128';
  const qr = config.embed_details && details?.some((detail) => detail?.trim()) ? qrText(code, details) : null;

  const print = () => {
    const label = labelRef.current?.innerHTML;
    const win = window.open('', '_blank', 'width=520,height=360');
    if (!label || !win) return;

    const doc = win.document;
    doc.title = code;
    doc.body.style.cssText = 'margin:0;display:flex;flex-direction:column;align-items:center;justify-content:center;min-height:100vh;font-family:sans-serif';
    doc.body.innerHTML = `<div style="display:flex;align-items:center;gap:16px">${label}</div>`;
    // On paper the label may be scaled down to fit the page; printers keep thin bars sharp.
    doc.querySelectorAll('svg').forEach((svg) => svg.setAttribute('style', 'max-width:100%;height:auto'));
    if (caption) {
      const text = doc.createElement('div');
      text.textContent = caption;
      text.style.cssText = 'font-size:12px;margin-top:4px;text-align:center';
      doc.body.appendChild(text);
    }
    win.focus();
    win.print();
    win.close();
  };

  const barcode = (size: Pick<BarcodeSettings, 'width' | 'height' | 'font_size' | 'margin'>) => (
    <Barcode
      value={code}
      format={format}
      width={size.width}
      height={size.height}
      displayValue={config.display_value}
      fontSize={size.font_size}
      margin={size.margin}
      renderer="svg"
    />
  );

  const qrCode = (size: number) => (qr ? <QRCodeSVG value={qr} size={size} level="M" marginSize={1} title={`${code} details`} /> : null);

  return (
    <div className={`barcode-label ${compact ? 'barcode-label--compact' : ''}`}>
      {/*
        On screen the bars are drawn at a whole-pixel width and never shrunk, because blurred bars cannot be
        scanned off a screen. Compact labels are only shorter. Printing always uses the saved settings.
      */}
      <div className="barcode-label-row">
        <div className="barcode-label-code">{barcode({ ...config, ...(compact ? COMPACT_SIZE : {}), width: Math.max(1, Math.round(config.width)) })}</div>
        {qr && <div className="barcode-label-qr">{qrCode(compact ? 72 : 96)}</div>}
      </div>
      {printable && (
        <div ref={labelRef} hidden>
          {barcode(config)}
          {qrCode(120)}
        </div>
      )}
      {caption && <div className="barcode-label-caption">{caption}</div>}
      {format !== config.format && <div className="barcode-label-note">{`Printed as Code 128: this value does not fit ${config.format}.`}</div>}
      {printable && (
        <button type="button" className="btn-finovo-secondary barcode-label-print" onClick={print}>
          <Printer size={14} />
          <span>Print Label</span>
        </button>
      )}
    </div>
  );
};

const PREVIEW_VALUES: Record<BarcodeSettings['format'], string> = {
  CODE128: 'SKU-1001-BLK',
  CODE39: 'SKU-1001',
  EAN13: '5901234123457',
  UPC: '012345678905',
};

/** Popup where an admin sets how barcode labels look, with a live preview. */
export const BarcodeSettingsDialog: React.FC<{ onClose: () => void }> = ({ onClose }) => {
  const { settings, save } = useBarcodeSettings();
  const [draft, setDraft] = useState<BarcodeSettings>(settings);
  const [saving, setSaving] = useState(false);
  const [message, setMessage] = useState<{ type: 'success' | 'error'; text: string } | null>(null);

  useEffect(() => setDraft(settings), [settings]);

  const set = <K extends keyof BarcodeSettings>(key: K, value: BarcodeSettings[K]) => setDraft((prev) => ({ ...prev, [key]: value }));

  const handleSave = async () => {
    try {
      setSaving(true);
      const res = await save(draft);
      setMessage({ type: 'success', text: res.message });
    } catch (err) {
      setMessage({ type: 'error', text: err instanceof Error ? err.message : 'The settings could not be saved.' });
    } finally {
      setSaving(false);
    }
  };

  const number = (key: 'width' | 'height' | 'font_size' | 'margin', label: string, min: number, max: number, step = 1) => (
    <div>
      <label className="form-label">
        {label} <span className="barcode-settings-value">{draft[key]}</span>
      </label>
      <input
        type="range"
        className="barcode-settings-range"
        min={min}
        max={max}
        step={step}
        value={draft[key]}
        onChange={(e) => set(key, Number(e.target.value))}
      />
    </div>
  );

  return (
    <div className="modal-overlay" onClick={saving ? undefined : onClose}>
      <div className="procurement-modal" style={{ maxWidth: 560 }} onClick={(e) => e.stopPropagation()}>
        <div className="procurement-modal-header">
          <div>
            <h3 style={{ margin: 0, fontSize: 18, fontWeight: 800, color: '#0f172a' }}>Barcode Settings</h3>
            <p style={{ margin: '3px 0 0 0', fontSize: 12, color: '#64748b' }}>How product, order and tracking labels are drawn and printed.</p>
          </div>
          <button type="button" onClick={onClose} className="topbar-icon-button" aria-label="Close" disabled={saving}>
            <X size={18} />
          </button>
        </div>

        <div className="procurement-modal-body" style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
          {message && <Alert type={message.type} message={message.text} />}

          <div className="barcode-settings-preview">
            <BarcodeLabel value={PREVIEW_VALUES[draft.format]} details={['Brown bear cushion', 'Price 1890.00']} settings={draft} />
          </div>

          <div>
            <label className="form-label">Barcode Type</label>
            <CustomDropdown value={draft.format} onChange={(val) => set('format', val as BarcodeSettings['format'])} options={FORMAT_OPTIONS} searchable={false} />
          </div>

          <div className="finovo-form-grid-2">
            {number('width', 'Bar Width', 1, 4, 0.5)}
            {number('height', 'Bar Height', 30, 150, 5)}
            {number('font_size', 'Text Size', 10, 24)}
            {number('margin', 'Margin', 0, 20)}
          </div>

          <label className="barcode-settings-toggle">
            <input type="checkbox" checked={draft.display_value} onChange={(e) => set('display_value', e.target.checked)} />
            <span>Print the code as text under the bars</span>
          </label>

          <label className="barcode-settings-toggle">
            <input type="checkbox" checked={draft.embed_details} onChange={(e) => set('embed_details', e.target.checked)} />
            <span>Add a QR code with the product or order details, so a phone camera shows them as text</span>
          </label>
        </div>

        <div className="procurement-modal-footer" style={{ justifyContent: 'space-between' }}>
          <button type="button" className="btn-outline" onClick={() => setDraft(DEFAULT_BARCODE_SETTINGS)} disabled={saving}>
            Reset to Default
          </button>
          <div style={{ display: 'flex', gap: 8 }}>
            <button type="button" className="btn-outline" onClick={onClose} disabled={saving}>
              Close
            </button>
            <button type="button" className="btn-primary" onClick={handleSave} disabled={saving}>
              {saving && <Loader2 size={16} className="animate-spin" />}
              <span>Save Settings</span>
            </button>
          </div>
        </div>
      </div>
    </div>
  );
};

export default BarcodeLabel;
