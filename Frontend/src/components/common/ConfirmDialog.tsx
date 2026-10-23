import React, { useCallback, useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { AlertTriangle, Info, Loader2, X } from 'lucide-react';
import './ConfirmDialog.css';

export interface ConfirmDialogProps {
  open: boolean;
  title: string;
  message: React.ReactNode;
  /** Optional muted line under the message, e.g. what will happen next. */
  note?: string;
  confirmLabel?: string;
  /** null hides the cancel button, for a popup that only informs. */
  cancelLabel?: string | null;
  /** "danger" for destructive actions (red), "primary" otherwise, "info" for a popup that only informs. */
  tone?: 'danger' | 'primary' | 'info';
  loading?: boolean;
  onConfirm: () => void;
  onCancel: () => void;
}

/**
 * Centered confirmation popup in the Finovo modal style; replaces the browser's confirm().
 * Rendered on <body> so it always sits in the middle of the screen, whatever page it is opened from.
 */
export const ConfirmDialog: React.FC<ConfirmDialogProps> = ({
  open,
  title,
  message,
  note,
  confirmLabel = 'Confirm',
  cancelLabel = 'Cancel',
  tone = 'danger',
  loading = false,
  onConfirm,
  onCancel,
}) => {
  const dialogRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (!open) return;
    dialogRef.current?.focus();
    const onKeyDown = (e: KeyboardEvent) => {
      if (e.key === 'Escape' && !loading) onCancel();
    };
    document.addEventListener('keydown', onKeyDown);
    return () => document.removeEventListener('keydown', onKeyDown);
  }, [open, loading, onCancel]);

  if (!open) return null;

  return createPortal(
    <div className="modal-overlay" onClick={loading ? undefined : onCancel}>
      <div
        ref={dialogRef}
        tabIndex={-1}
        className="confirm-dialog"
        role="alertdialog"
        aria-modal="true"
        aria-labelledby="confirm-dialog-title"
        aria-describedby="confirm-dialog-message"
        onClick={(e) => e.stopPropagation()}
      >
        <button type="button" className="confirm-dialog-close" onClick={onCancel} disabled={loading} aria-label="Close">
          <X size={18} />
        </button>

        <span className={`confirm-dialog-icon confirm-dialog-icon-${tone === 'danger' ? 'danger' : 'primary'}`}>
          {tone === 'info' ? <Info size={22} /> : <AlertTriangle size={22} />}
        </span>

        <h3 id="confirm-dialog-title" className="confirm-dialog-title">{title}</h3>
        <div id="confirm-dialog-message" className="confirm-dialog-message">{message}</div>
        {note && <p className="confirm-dialog-note">{note}</p>}

        <div className="confirm-dialog-actions">
          {cancelLabel !== null && (
            <button type="button" className="btn-finovo-secondary" onClick={onCancel} disabled={loading}>
              {cancelLabel}
            </button>
          )}
          <button
            type="button"
            className={tone === 'danger' ? 'btn-finovo-primary confirm-dialog-danger' : 'btn-finovo-primary'}
            onClick={onConfirm}
            disabled={loading}
          >
            {loading && <Loader2 size={16} className="animate-spin" />}
            <span>{confirmLabel}</span>
          </button>
        </div>
      </div>
    </div>,
    document.body
  );
};

export type ConfirmOptions = Pick<ConfirmDialogProps, 'title' | 'message' | 'note' | 'confirmLabel' | 'cancelLabel' | 'tone'>;

/**
 * Promise-based replacement for the browser's confirm() and alert():
 * `if (!(await confirm({ title, message }))) return;` and render `dialog` once in the page.
 */
export const useConfirmDialog = () => {
  const [request, setRequest] = useState<(ConfirmOptions & { resolve: (ok: boolean) => void }) | null>(null);

  const confirm = useCallback(
    (options: ConfirmOptions) => new Promise<boolean>((resolve) => setRequest({ ...options, resolve })),
    []
  );

  /** A popup with a single OK button. */
  const notify = useCallback(
    (options: Omit<ConfirmOptions, 'cancelLabel'>) =>
      confirm({ tone: 'info', confirmLabel: 'OK', ...options, cancelLabel: null }).then(() => undefined),
    [confirm]
  );

  const close = useCallback(
    (ok: boolean) => {
      request?.resolve(ok);
      setRequest(null);
    },
    [request]
  );

  const dialog = (
    <ConfirmDialog
      open={request !== null}
      title={request?.title ?? ''}
      message={request?.message}
      note={request?.note}
      confirmLabel={request?.confirmLabel}
      cancelLabel={request?.cancelLabel}
      tone={request?.tone ?? 'primary'}
      onConfirm={() => close(true)}
      onCancel={() => close(false)}
    />
  );

  return { confirm, notify, dialog };
};

export default ConfirmDialog;
