import React, { useCallback, useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { ArrowLeft, Download, Pencil, Send, CheckCircle2, XCircle, ShoppingCart, Trash2, Loader2, AlertCircle } from 'lucide-react';
import ConfirmDialog from '../components/common/ConfirmDialog';
import StatusPill from '../components/common/StatusPill';
import ProductThumb from '../components/catalog/ProductThumb';
import { apiErrorMessage } from '../components/quotations/quotationUtils';
import '../components/quotations/Quotations.css';
import omsService from '../services/omsService';
import type { QuotationModel } from '../services/omsService';
import { addressLines, formatDate, formatMoney } from '../utils/format';

type PendingAction = 'sent' | 'accepted' | 'rejected' | 'convert' | 'delete';

const CONFIRM: Record<PendingAction, { title: string; message: string; label: string; tone: 'primary' | 'danger' }> = {
  sent: { title: 'Mark as sent?', message: 'Use this after you have sent the PDF to the customer.', label: 'Mark as Sent', tone: 'primary' },
  accepted: { title: 'Mark as accepted?', message: 'The customer agreed to this quotation. You can then convert it into an order.', label: 'Mark as Accepted', tone: 'primary' },
  rejected: { title: 'Mark as rejected?', message: 'The customer declined. A rejected quotation cannot be edited or converted.', label: 'Mark as Rejected', tone: 'danger' },
  convert: { title: 'Convert to order?', message: 'A pending order with these items and prices is created, and stock is allocated where available.', label: 'Create Order', tone: 'primary' },
  delete: { title: 'Delete this quotation?', message: 'The draft and its items are removed permanently.', label: 'Delete', tone: 'danger' },
};

export const QuotationDetailPage: React.FC = () => {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const [quotation, setQuotation] = useState<QuotationModel | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [pending, setPending] = useState<PendingAction | null>(null);
  const [working, setWorking] = useState(false);
  const [downloading, setDownloading] = useState(false);

  const load = useCallback(async () => {
    try {
      const res = await omsService.getQuotation(Number(id));
      setQuotation(res.data);
    } catch (err) {
      setError(apiErrorMessage(err, 'This quotation could not be found.'));
    } finally {
      setLoading(false);
    }
  }, [id]);

  useEffect(() => {
    load();
  }, [load]);

  const runAction = async () => {
    if (!quotation || !pending) return;
    setWorking(true);
    setError(null);
    try {
      if (pending === 'delete') {
        await omsService.deleteQuotation(quotation.id);
        navigate('/quotations');
        return;
      }
      if (pending === 'convert') {
        const res = await omsService.convertQuotation(quotation.id);
        setNotice(res.message);
      } else {
        const res = await omsService.setQuotationStatus(quotation.id, pending);
        setNotice(res.message);
      }
      await load();
    } catch (err) {
      setError(apiErrorMessage(err));
    } finally {
      setWorking(false);
      setPending(null);
    }
  };

  const download = async () => {
    if (!quotation) return;
    setDownloading(true);
    try {
      await omsService.downloadQuotationPdf(quotation.id, quotation.quotation_number);
    } catch (err) {
      setError(apiErrorMessage(err, 'Could not download the PDF.'));
    } finally {
      setDownloading(false);
    }
  };

  if (loading) {
    return (
      <div className="page-container" style={{ display: 'flex', justifyContent: 'center', padding: 80 }}>
        <Loader2 size={28} className="animate-spin" color="#64748b" />
      </div>
    );
  }

  if (!quotation) {
    return (
      <div className="page-container">
        <div className="content-card" style={{ padding: 48, textAlign: 'center' }}>
          <AlertCircle size={28} color="#ef4444" />
          <p style={{ fontSize: 14, color: '#334155' }}>{error ?? 'Something went wrong.'}</p>
          <button className="btn-finovo-secondary" onClick={() => navigate('/quotations')}>
            <ArrowLeft size={14} />
            <span>Back to Quotations</span>
          </button>
        </div>
      </div>
    );
  }

  const q = quotation;
  const open = q.status === 'draft' || q.status === 'sent';
  const money = (amount: string | number) => formatMoney(amount, q.currency);
  const confirm = pending ? CONFIRM[pending] : null;

  return (
    <div className="page-container">
      <nav className="breadcrumb-nav">
        <span>Finovo</span>
        <span>&gt;</span>
        <span style={{ cursor: 'pointer' }} onClick={() => navigate('/quotations')}>Quotations</span>
        <span>&gt;</span>
        <span style={{ color: '#2563eb', fontWeight: 500 }}>{q.quotation_number}</span>
      </nav>

      <div className="page-header-row">
        <div className="page-title-section">
          <div className="page-title-badge-wrap">
            <h1 className="page-title">{q.quotation_number}</h1>
            <StatusPill status={q.is_expired ? 'expired' : q.status} />
          </div>
          <p className="page-subtitle">
            Issued {formatDate(q.issue_date)} · Valid until {formatDate(q.valid_until)}
            {q.creator ? ` · Prepared by ${q.creator.name}` : ''}
          </p>
        </div>

        <div className="qt-actions">
          <button className="btn-finovo-secondary" onClick={() => navigate('/quotations')}>
            <ArrowLeft size={14} />
            <span>Back</span>
          </button>
          <button className="btn-finovo-secondary" onClick={download} disabled={downloading}>
            {downloading ? <Loader2 size={14} className="animate-spin" /> : <Download size={14} />}
            <span>Download PDF</span>
          </button>
          {open && (
            <button className="btn-finovo-secondary" onClick={() => navigate(`/quotations/${q.id}/edit`)}>
              <Pencil size={14} />
              <span>Edit</span>
            </button>
          )}
          {q.status === 'draft' && (
            <button className="btn-finovo-secondary" onClick={() => setPending('sent')}>
              <Send size={14} />
              <span>Mark as Sent</span>
            </button>
          )}
          {open && !q.is_expired && (
            <button className="btn-finovo-secondary" style={{ color: '#047857' }} onClick={() => setPending('accepted')}>
              <CheckCircle2 size={14} />
              <span>Accepted</span>
            </button>
          )}
          {open && (
            <button className="btn-finovo-secondary" style={{ color: '#be123c' }} onClick={() => setPending('rejected')}>
              <XCircle size={14} />
              <span>Rejected</span>
            </button>
          )}
          {q.status === 'accepted' && (
            <button className="btn-finovo-primary" onClick={() => setPending('convert')}>
              <ShoppingCart size={14} />
              <span>Convert to Order</span>
            </button>
          )}
          {q.status === 'draft' && (
            <button className="btn-finovo-secondary" style={{ color: '#be123c' }} onClick={() => setPending('delete')} aria-label="Delete quotation">
              <Trash2 size={14} />
            </button>
          )}
        </div>
      </div>

      {error && <div className="qt-error">{error}</div>}
      {notice && (
        <div className="qt-error" style={{ background: '#ecfdf5', color: '#047857', borderColor: '#a7f3d0' }}>
          {notice}
        </div>
      )}
      {q.is_expired && (
        <div className="qt-error" style={{ background: '#fffbeb', color: '#b45309', borderColor: '#fde68a' }}>
          This quotation expired on {formatDate(q.valid_until)}. Edit it and extend "Valid until" before the customer accepts it.
        </div>
      )}

      <div className="qt-card">
        <div className="qt-grid">
          <div className="qt-span-2">
            <div className="qt-muted" style={{ marginBottom: 4 }}>Quotation for</div>
            <div style={{ fontSize: 15, fontWeight: 700, color: '#0f172a' }}>{q.customer_name}</div>
            {addressLines(q.billing_address).map((line) => (
              <div key={line} style={{ fontSize: 13, color: '#334155' }}>{line}</div>
            ))}
            {q.customer_email && <div style={{ fontSize: 13, color: '#2563eb' }}>{q.customer_email}</div>}
            {q.customer_phone && <div style={{ fontSize: 13, color: '#334155' }}>{q.customer_phone}</div>}
          </div>
          <div>
            <div className="qt-muted" style={{ marginBottom: 4 }}>Store</div>
            <div style={{ fontSize: 13, fontWeight: 600 }}>{q.store?.name ?? '—'}</div>
          </div>
          <div>
            <div className="qt-muted" style={{ marginBottom: 4 }}>Order</div>
            {q.order ? (
              <button type="button" className="btn-finovo-secondary" style={{ padding: '4px 10px', fontSize: 12 }} onClick={() => navigate('/orders')}>
                {q.order.order_number}
              </button>
            ) : (
              <div style={{ fontSize: 13, color: '#64748b' }}>Not converted yet</div>
            )}
          </div>
        </div>
      </div>

      <div className="qt-card">
        <h3 className="qt-card-title">Items</h3>
        <div className="table-responsive-wrapper">
          <table className="qt-items">
            <thead>
              <tr>
                <th>Description</th>
                <th style={{ textAlign: 'center' }}>Qty</th>
                <th style={{ textAlign: 'right' }}>Unit price</th>
                <th style={{ textAlign: 'right' }}>Disc.</th>
                <th style={{ textAlign: 'right' }}>Tax</th>
                <th style={{ textAlign: 'right' }}>Amount</th>
              </tr>
            </thead>
            <tbody>
              {(q.items ?? []).map((item) => (
                <tr key={item.id}>
                  <td>
                    <div style={{ display: 'flex', gap: 10, alignItems: 'center' }}>
                      {item.product_variant_id ? <ProductThumb src={item.variant?.product?.image_url} size={36} /> : null}
                      <div>
                        <div style={{ fontWeight: 600, color: '#0f172a' }}>{item.description}</div>
                        {item.sku && <div className="qt-muted">SKU: {item.sku}</div>}
                      </div>
                    </div>
                  </td>
                  <td style={{ textAlign: 'center' }}>{item.quantity}</td>
                  <td style={{ textAlign: 'right' }}>{money(item.unit_price)}</td>
                  <td style={{ textAlign: 'right' }}>{Number(item.discount_percent ?? 0)}%</td>
                  <td style={{ textAlign: 'right' }}>{Number(item.tax_percent ?? 0)}%</td>
                  <td style={{ textAlign: 'right', fontWeight: 600 }}>{money(item.line_total)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        <div className="qt-totals">
          <div>
            <span>Subtotal</span>
            <span>{money(q.subtotal)}</span>
          </div>
          {Number(q.discount_total) > 0 && (
            <div>
              <span>Discount</span>
              <span>- {money(q.discount_total)}</span>
            </div>
          )}
          {Number(q.tax_total) > 0 && (
            <div>
              <span>Tax</span>
              <span>{money(q.tax_total)}</span>
            </div>
          )}
          <div className="qt-grand">
            <span>Total</span>
            <span>{money(q.total_amount)}</span>
          </div>
        </div>
      </div>

      {(q.notes || q.terms) && (
        <div className="qt-card">
          <div className="qt-grid">
            {q.notes && (
              <div className="qt-span-2">
                <div className="qt-muted" style={{ marginBottom: 4 }}>Notes</div>
                <div style={{ fontSize: 13, color: '#334155', whiteSpace: 'pre-line' }}>{q.notes}</div>
              </div>
            )}
            {q.terms && (
              <div className="qt-span-2">
                <div className="qt-muted" style={{ marginBottom: 4 }}>Terms &amp; conditions</div>
                <div style={{ fontSize: 13, color: '#334155', whiteSpace: 'pre-line' }}>{q.terms}</div>
              </div>
            )}
          </div>
        </div>
      )}

      <ConfirmDialog
        open={Boolean(confirm)}
        title={confirm?.title ?? ''}
        message={confirm?.message ?? ''}
        confirmLabel={confirm?.label}
        tone={confirm?.tone}
        loading={working}
        onConfirm={runAction}
        onCancel={() => setPending(null)}
      />
    </div>
  );
};

export default QuotationDetailPage;
