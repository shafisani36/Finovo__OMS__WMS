import React, { useState } from 'react';
import { Ban, Check, CheckCircle2, ExternalLink, Loader2, PackageCheck, Send } from 'lucide-react';
import CustomDropdown from '../common/CustomDropdown';
import { Alert } from '../common/Alert';
import { useConfirmDialog } from '../common/ConfirmDialog';
import omsService from '../../services/omsService';
import type { OrderFulfillment } from '../../services/omsService';
import './DetailModal.css';

type PickStatus = NonNullable<OrderFulfillment['pick_task']>['status'];

const STEPS = ['Placed', 'Allocated', 'Picked', 'Packed', 'Shipped', 'Delivered'];

const PICK_STEPS: { value: PickStatus; label: string }[] = [
  { value: 'not_started', label: 'Not Started' },
  { value: 'in_progress', label: 'In Progress' },
  { value: 'picked', label: 'Picked' },
  { value: 'packed', label: 'Packed' },
];

const CARRIERS = ['TCS', 'Leopards Courier', 'M&P', 'PostEx', 'Trax', 'BlueEx', 'DHL', 'FedEx', 'UPS'];

/** How many of the STEPS the order has completed. */
const completedSteps = (order: OrderFulfillment): number => {
  if (['delivered', 'returned'].includes(order.order_status)) return 6;
  if (order.shipment && order.shipment.status !== 'pending') return 5;
  if (order.shipment?.status === 'pending' || order.pick_task?.status === 'packed') return 4;
  if (order.pick_task?.status === 'picked') return 3;
  if (order.pick_task) return 2;
  return 1;
};

interface OrderWorkflowProps {
  order: OrderFulfillment;
  /** Called after a step succeeds, with the server's message; the parent reloads the order. */
  onChanged: (message: string) => void;
}

/**
 * Where the order is (a step tracker) and the one thing to do next: allocate stock, pick and pack, dispatch
 * (a unique tracking ID is generated) or mark delivered. Used on the order screen and after a barcode scan.
 */
export const OrderWorkflow: React.FC<OrderWorkflowProps> = ({ order, onChanged }) => {
  const { confirm, dialog } = useConfirmDialog();
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [carrier, setCarrier] = useState(CARRIERS[0]);

  const run = async (action: () => Promise<{ message: string }>) => {
    try {
      setBusy(true);
      setError(null);
      onChanged((await action()).message);
    } catch (err) {
      setError(err instanceof Error && err.message ? err.message : 'The order could not be updated.');
    } finally {
      setBusy(false);
    }
  };

  const deliver = async () => {
    const ok = await confirm({
      title: 'Mark as delivered?',
      message: (
        <>
          Confirm that order <strong>{order.order_number}</strong> has reached <strong>{order.customer}</strong>.
        </>
      ),
      confirmLabel: 'Mark Delivered',
      tone: 'primary',
    });
    if (ok) run(() => omsService.deliverShipment(order.shipment!.id));
  };

  const cancel = async () => {
    const ok = await confirm({
      title: 'Cancel this order?',
      message: (
        <>
          Order <strong>{order.order_number}</strong> will be cancelled and its reserved stock released.
        </>
      ),
      confirmLabel: 'Cancel Order',
      cancelLabel: 'Keep Order',
      tone: 'danger',
    });
    if (ok) run(() => omsService.cancelOrder(order.id));
  };

  const shipment = order.shipment;
  const done = completedSteps(order);
  const cancelled = order.order_status === 'cancelled';
  const spinner = busy ? <Loader2 size={15} className="animate-spin" /> : null;

  /** The card describing the next step and its controls. */
  const nextStep = (): { title: string; text: string; body?: React.ReactNode } => {
    if (cancelled) return { title: 'Order cancelled', text: 'This order was cancelled and its reserved stock was released.' };

    if (order.can_allocate) {
      return {
        title: 'Reserve stock',
        text:
          order.order_status === 'exception'
            ? 'Stock could not be reserved last time. Try again once stock has arrived in the linked warehouse.'
            : 'Reserve stock in the linked warehouse to send this order to the picking list.',
        body: (
          <button type="button" className="btn-finovo-primary" disabled={busy} onClick={() => run(() => omsService.allocateOrder(order.id))}>
            {spinner ?? <PackageCheck size={15} />}
            <span>Allocate Stock</span>
          </button>
        ),
      };
    }

    if (order.pick_task && order.pick_task.status !== 'packed') {
      return {
        title: 'Pick and pack',
        text: 'Update the picking status as the order is collected. Marking it Packed sends it to Shipments for dispatch.',
        body: (
          <div className="ow-segmented" role="group" aria-label="Picking status">
            {PICK_STEPS.map((step) => (
              <button
                key={step.value}
                type="button"
                className={order.pick_task?.status === step.value ? 'is-current' : ''}
                disabled={busy || order.pick_task?.status === step.value}
                onClick={() => run(() => omsService.updatePickStatus(order.pick_task!.id, step.value))}
              >
                {step.label}
              </button>
            ))}
          </div>
        ),
      };
    }

    if (shipment?.status === 'pending') {
      return {
        title: 'Dispatch the parcel',
        text: 'Choose the courier. A unique tracking ID is generated and the customer is emailed the tracking details.',
        body: (
          <div className="ow-inline">
            <div className="ow-inline-grow">
              <CustomDropdown value={carrier} onChange={(val) => setCarrier(String(val))} options={CARRIERS.map((c) => ({ value: c, label: c }))} searchable={false} />
            </div>
            <button type="button" className="btn-finovo-primary" disabled={busy} onClick={() => run(() => omsService.dispatchShipment(shipment.id, { carrier }))}>
              {spinner ?? <Send size={15} />}
              <span>Dispatch</span>
            </button>
          </div>
        ),
      };
    }

    if (shipment?.status === 'shipped') {
      return {
        title: 'In transit',
        text: `The parcel is with ${shipment.carrier}. Confirm delivery once the customer has received it.`,
        body: (
          <button type="button" className="btn-finovo-primary ow-success" disabled={busy} onClick={deliver}>
            {spinner ?? <CheckCircle2 size={15} />}
            <span>Mark Delivered</span>
          </button>
        ),
      };
    }

    if (order.order_status === 'returned') return { title: 'Returned', text: 'All items of this order came back and were processed on the Returns page.' };
    if (done === 6) return { title: 'Delivered', text: 'This order is complete. Nothing else to do.' };
    return { title: 'Packed', text: 'The order is packed and waiting on the Shipments page.' };
  };

  const step = nextStep();

  return (
    <div className="ow">
      {!cancelled && (
        <ol className="ow-steps" aria-label="Order progress">
          {STEPS.map((label, i) => (
            <li key={label} className={i < done ? 'is-done' : i === done ? 'is-current' : ''}>
              <span className="ow-step-dot">{i < done ? <Check size={12} strokeWidth={3} /> : i + 1}</span>
              <span className="ow-step-label">{label}</span>
            </li>
          ))}
        </ol>
      )}

      {error && <Alert type="error" message={error} />}

      <div className={`ow-next ${cancelled ? 'is-cancelled' : ''}`}>
        <div>
          <div className="ow-next-title">{step.title}</div>
          <p className="ow-next-text">{step.text}</p>
        </div>
        {step.body}
      </div>

      {shipment && shipment.status !== 'pending' && (
        <dl className="ow-shipment">
          <div>
            <dt>Courier</dt>
            <dd>{shipment.carrier}</dd>
          </div>
          <div>
            <dt>Tracking ID</dt>
            <dd>
              {shipment.tracking_url ? (
                <a href={shipment.tracking_url} target="_blank" rel="noopener noreferrer">
                  {shipment.tracking_number} <ExternalLink size={12} />
                </a>
              ) : (
                shipment.tracking_number
              )}
            </dd>
          </div>
          <div>
            <dt>Shipment</dt>
            <dd>{shipment.status === 'delivered' ? 'Delivered' : 'In transit'}</dd>
          </div>
        </dl>
      )}

      {order.can_cancel && (
        <div className="ow-footer">
          <button type="button" className="ow-cancel" disabled={busy} onClick={cancel}>
            <Ban size={13} />
            <span>Cancel order</span>
          </button>
        </div>
      )}

      {dialog}
    </div>
  );
};

export default OrderWorkflow;
