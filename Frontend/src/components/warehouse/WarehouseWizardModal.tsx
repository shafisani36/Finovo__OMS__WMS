import React, { useState } from 'react';
import {
  X,
  Warehouse,
  MapPin,
  Layers,
  ShieldAlert,
  ArrowRight,
  ArrowLeft,
  CheckCircle2,
  AlertTriangle,
  Loader2,
  PackageCheck,
  Building,
  Info,
} from 'lucide-react';
import omsService from '../../services/omsService';

interface WarehouseWizardModalProps {
  isOpen: boolean;
  onClose: () => void;
  onSuccess: () => void;
}

const TIMEZONES = [
  'UTC',
  'America/New_York',
  'America/Chicago',
  'America/Denver',
  'America/Los_Angeles',
  'Europe/London',
  'Europe/Paris',
  'Europe/Berlin',
  'Asia/Dubai',
  'Asia/Karachi',
  'Asia/Kolkata',
  'Asia/Singapore',
  'Asia/Tokyo',
  'Australia/Sydney',
];

const WAREHOUSE_TYPES = [
  { value: 'distribution_center', label: 'Distribution Center (DC)', desc: 'Primary central distribution hub' },
  { value: 'fulfillment_hub', label: 'Fulfillment Hub', desc: 'Fast-dispatch e-commerce order routing center' },
  { value: 'retail_backroom', label: 'Retail Backroom', desc: 'Store-level micro-fulfillment storage' },
  { value: 'cross_dock', label: 'Cross-Dock Facility', desc: 'Direct transfer without long-term storage' },
  { value: 'quarantine_zone', label: 'Quarantine Zone', desc: 'Hold & inspection area for damaged or pending goods' },
  { value: 'third_party_3pl', label: '3PL Partner Facility', desc: 'External third-party logistics center' },
];

export const WarehouseWizardModal: React.FC<WarehouseWizardModalProps> = ({
  isOpen,
  onClose,
  onSuccess,
}) => {
  const [step, setStep] = useState(1);
  const [submitting, setSubmitting] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);

  // Step 1: Basic Info
  const [name, setName] = useState('');
  const [code, setCode] = useState('');
  const [type, setType] = useState('distribution_center');
  const [isDefault, setIsDefault] = useState(false);
  const [priority, setPriority] = useState(10);

  // Step 2: Address & Regional
  const [addressLine1, setAddressLine1] = useState('');
  const [addressLine2, setAddressLine2] = useState('');
  const [city, setCity] = useState('');
  const [state, setState] = useState('');
  const [postalCode, setPostalCode] = useState('');
  const [country, setCountry] = useState('United States');
  const [timezone, setTimezone] = useState('UTC');
  const [contactName, setContactName] = useState('');
  const [contactEmail, setContactEmail] = useState('');
  const [contactPhone, setContactPhone] = useState('');

  // Step 3: Physical Layout Starter
  const [generateStarter, setGenerateStarter] = useState(true);
  const [zoneCode, setZoneCode] = useState('ZONE-A');
  const [aislesCount, setAislesCount] = useState(2);
  const [racksPerAisle, setRacksPerAisle] = useState(2);
  const [shelvesPerRack, setShelvesPerRack] = useState(2);
  const [binsPerShelf, setBinsPerShelf] = useState(2);

  // Step 4: Inventory & Tracking Policies
  const [allowNegativeInventory, setAllowNegativeInventory] = useState(false);
  const [reservationPolicy, setReservationPolicy] = useState('immediate_at_order');
  const [pickingStrategy, setPickingStrategy] = useState('fifo');
  const [lotTracking, setLotTracking] = useState(false);
  const [serialTracking, setSerialTracking] = useState(false);
  const [expiryTracking, setExpiryTracking] = useState(false);
  const [cycleCountFrequency, setCycleCountFrequency] = useState('weekly');

  // Step 5: Inbound & Outbound Policies
  const [poRequired, setPoRequired] = useState(true);
  const [blindReceiving, setBlindReceiving] = useState(false);
  const [putawayStrategy, setPutawayStrategy] = useState('direct_to_bin');
  const [qcRequired, setQcRequired] = useState(false);
  const [outboundPickingStrategy, setOutboundPickingStrategy] = useState('batch');
  const [packingScanRequired, setPackingScanRequired] = useState(true);
  const [partialFulfillment, setPartialFulfillment] = useState(true);
  const [backorderAllowed, setBackorderAllowed] = useState(false);

  if (!isOpen) return null;

  const totalCalculatedBins = aislesCount * racksPerAisle * shelvesPerRack * binsPerShelf;

  const handleNext = () => {
    setErrorMessage(null);
    if (step === 1) {
      if (!name.trim()) {
        setErrorMessage('Warehouse Name is required.');
        return;
      }
      if (!code.trim()) {
        setErrorMessage('Warehouse Code is required.');
        return;
      }
    } else if (step === 2) {
      if (!city.trim() || !country.trim()) {
        setErrorMessage('City and Country are required.');
        return;
      }
    }
    setStep((prev) => Math.min(prev + 1, 6));
  };

  const handleBack = () => {
    setErrorMessage(null);
    setStep((prev) => Math.max(prev - 1, 1));
  };

  const handleSubmit = async (activateImmediately: boolean = false) => {
    try {
      setSubmitting(true);
      setErrorMessage(null);

      const payload = {
        name,
        code: code.toUpperCase().trim(),
        type,
        is_default: isDefault,
        priority: Number(priority),
        status: 'draft',
        address_line1: addressLine1,
        address_line2: addressLine2,
        city,
        state,
        postal_code: postalCode,
        country,
        timezone,
        contact_name: contactName,
        contact_email: contactEmail,
        contact_phone: contactPhone,
        operating_hours: {
          timezone,
          workdays: ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'],
          open: '08:00',
          close: '17:00',
        },
        inventory_policies: {
          allow_negative_inventory: allowNegativeInventory,
          reservation_policy: reservationPolicy,
          picking_strategy: pickingStrategy,
          lot_tracking: lotTracking,
          serial_tracking: serialTracking,
          expiry_tracking: expiryTracking,
          cycle_count_frequency: cycleCountFrequency,
        },
        inbound_policies: {
          purchase_order_required: poRequired,
          blind_receiving_allowed: blindReceiving,
          putaway_strategy: putawayStrategy,
          qc_inspection_required: qcRequired,
        },
        outbound_policies: {
          picking_strategy: outboundPickingStrategy,
          packing_scan_verification: packingScanRequired,
          partial_fulfillment_allowed: partialFulfillment,
          backorder_allowed: backorderAllowed,
        },
        starter_layout: generateStarter
          ? {
              create: true,
              zone_code: zoneCode,
              aisles_count: aislesCount,
              racks_per_aisle: racksPerAisle,
              shelves_per_rack: shelvesPerRack,
              bins_per_shelf: binsPerShelf,
            }
          : undefined,
      };

      const res = await omsService.createWarehouse(payload);
      const newWh = res.data;

      // If starter layout was requested and wasn't processed inside store, generate it:
      if (generateStarter && newWh?.id) {
        try {
          await omsService.generateStarterLayout(newWh.id, {
            preview: false,
            zone_code: zoneCode,
            aisles_count: aislesCount,
            racks_per_aisle: racksPerAisle,
            shelves_per_rack: shelvesPerRack,
            bins_per_shelf: binsPerShelf,
          });
        } catch (starterErr) {
          console.warn('Starter layout generation notice:', starterErr);
        }
      }

      // If user chose to activate immediately, attempt activation:
      if (activateImmediately && newWh?.id) {
        try {
          await omsService.activateWarehouse(newWh.id);
        } catch (actErr: any) {
          // If activation failed due to readiness checks, inform user warehouse is saved as draft
          console.warn('Activation notice:', actErr);
        }
      }

      onSuccess();
      onClose();
    } catch (err: any) {
      console.error(err);
      setErrorMessage(err.message || 'Failed to create warehouse.');
    } finally {
      setSubmitting(false);
    }
  };

  const stepsMeta = [
    { num: 1, label: 'Identity', icon: Building },
    { num: 2, label: 'Location & Ops', icon: MapPin },
    { num: 3, label: 'Layout Starter', icon: Layers },
    { num: 4, label: 'Inventory Policies', icon: ShieldAlert },
    { num: 5, label: 'Inbound & Outbound', icon: PackageCheck },
    { num: 6, label: 'Review & Save', icon: CheckCircle2 },
  ];

  return (
    <div className="modal-overlay" onClick={onClose}>
      <div
        className="modal-dialog"
        onClick={(e) => e.stopPropagation()}
        style={{ maxWidth: 760, width: '100%', maxHeight: '90vh' }}
      >
        {/* Header */}
        <div
          style={{
            padding: '18px 24px',
            borderBottom: '1px solid #e2e8f0',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'space-between',
            backgroundColor: '#f8fafc',
          }}
        >
          <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
            <div
              style={{
                width: 36,
                height: 36,
                borderRadius: 10,
                backgroundColor: '#eff6ff',
                color: '#1e56ff',
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
              }}
            >
              <Warehouse size={18} />
            </div>
            <div>
              <h2 style={{ margin: 0, fontSize: 16, fontWeight: 700, color: '#0f172a' }}>
                Warehouse Creation Wizard
              </h2>
              <span style={{ fontSize: 12, color: '#64748b' }}>
                Step {step} of 6 — {stepsMeta[step - 1].label}
              </span>
            </div>
          </div>

          <button
            onClick={onClose}
            style={{ background: 'none', border: 'none', cursor: 'pointer', color: '#94a3b8' }}
          >
            <X size={20} />
          </button>
        </div>

        {/* Step Indicator */}
        <div
          style={{
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'space-between',
            padding: '12px 24px',
            backgroundColor: '#ffffff',
            borderBottom: '1px solid #f1f5f9',
            overflowX: 'auto',
          }}
        >
          {stepsMeta.map((s) => {
            const isActive = step === s.num;
            const isCompleted = step > s.num;
            const IconComponent = s.icon;
            return (
              <div
                key={s.num}
                onClick={() => isCompleted && setStep(s.num)}
                style={{
                  display: 'flex',
                  alignItems: 'center',
                  gap: 6,
                  cursor: isCompleted ? 'pointer' : 'default',
                  opacity: isActive || isCompleted ? 1 : 0.45,
                }}
              >
                <div
                  style={{
                    width: 26,
                    height: 26,
                    borderRadius: '50%',
                    backgroundColor: isCompleted ? '#ecfdf5' : isActive ? '#1e56ff' : '#f1f5f9',
                    color: isCompleted ? '#059669' : isActive ? '#ffffff' : '#64748b',
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                    fontSize: 12,
                    fontWeight: 700,
                  }}
                >
                  {isCompleted ? <CheckCircle2 size={14} /> : <IconComponent size={13} />}
                </div>
                <span
                  style={{
                    fontSize: 12,
                    fontWeight: isActive ? 700 : 500,
                    color: isActive ? '#1e56ff' : '#475569',
                  }}
                >
                  {s.label}
                </span>
              </div>
            );
          })}
        </div>

        {/* Body Content */}
        <div style={{ padding: '24px', overflowY: 'auto', flex: 1 }}>
          {errorMessage && (
            <div
              style={{
                padding: '12px 16px',
                borderRadius: 10,
                backgroundColor: '#fef2f2',
                border: '1px solid #fecaca',
                color: '#b91c1c',
                fontSize: 13,
                marginBottom: 20,
                display: 'flex',
                alignItems: 'center',
                gap: 8,
              }}
            >
              <AlertTriangle size={16} />
              <span>{errorMessage}</span>
            </div>
          )}

          {/* STEP 1: Basic Info */}
          {step === 1 && (
            <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
              <div>
                <label style={{ display: 'block', fontSize: 13, fontWeight: 600, color: '#334155', marginBottom: 6 }}>
                  Warehouse Name <span style={{ color: '#ef4444' }}>*</span>
                </label>
                <input
                  type="text"
                  placeholder="e.g. Seattle North Distribution Hub"
                  value={name}
                  onChange={(e) => {
                    setName(e.target.value);
                    if (!code && e.target.value) {
                      setCode('WH-' + e.target.value.substring(0, 4).toUpperCase().replace(/[^A-Z0-9]/g, ''));
                    }
                  }}
                  style={{
                    width: '100%',
                    padding: '10px 14px',
                    borderRadius: 10,
                    border: '1px solid #cbd5e1',
                    fontSize: 14,
                    boxSizing: 'border-box',
                  }}
                  required
                />
              </div>

              <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
                <div>
                  <label style={{ display: 'block', fontSize: 13, fontWeight: 600, color: '#334155', marginBottom: 6 }}>
                    Unique Code <span style={{ color: '#ef4444' }}>*</span>
                  </label>
                  <input
                    type="text"
                    placeholder="e.g. WH-SEATTLE-01"
                    value={code}
                    onChange={(e) => setCode(e.target.value.toUpperCase())}
                    style={{
                      width: '100%',
                      padding: '10px 14px',
                      borderRadius: 10,
                      border: '1px solid #cbd5e1',
                      fontSize: 14,
                      boxSizing: 'border-box',
                    }}
                    required
                  />
                  <span style={{ fontSize: 11, color: '#94a3b8' }}>Must be unique across your organization</span>
                </div>

                <div>
                  <label style={{ display: 'block', fontSize: 13, fontWeight: 600, color: '#334155', marginBottom: 6 }}>
                    Priority Rank
                  </label>
                  <input
                    type="number"
                    min="1"
                    max="999"
                    value={priority}
                    onChange={(e) => setPriority(Number(e.target.value))}
                    style={{
                      width: '100%',
                      padding: '10px 14px',
                      borderRadius: 10,
                      border: '1px solid #cbd5e1',
                      fontSize: 14,
                      boxSizing: 'border-box',
                    }}
                  />
                  <span style={{ fontSize: 11, color: '#94a3b8' }}>Lower number = higher allocation priority</span>
                </div>
              </div>

              <div>
                <label style={{ display: 'block', fontSize: 13, fontWeight: 600, color: '#334155', marginBottom: 8 }}>
                  Facility Type
                </label>
                <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 10 }}>
                  {WAREHOUSE_TYPES.map((t) => (
                    <div
                      key={t.value}
                      onClick={() => setType(t.value)}
                      style={{
                        padding: '10px 14px',
                        borderRadius: 10,
                        border: `1.5px solid ${type === t.value ? '#1e56ff' : '#e2e8f0'}`,
                        backgroundColor: type === t.value ? '#eff6ff' : '#ffffff',
                        cursor: 'pointer',
                        transition: 'all 0.15s ease',
                      }}
                    >
                      <div style={{ fontSize: 13, fontWeight: 600, color: type === t.value ? '#1e56ff' : '#0f172a' }}>
                        {t.label}
                      </div>
                      <div style={{ fontSize: 11, color: '#64748b', marginTop: 2 }}>{t.desc}</div>
                    </div>
                  ))}
                </div>
              </div>

              <div style={{ display: 'flex', alignItems: 'center', gap: 10, paddingTop: 4 }}>
                <input
                  type="checkbox"
                  id="is_default_wh"
                  checked={isDefault}
                  onChange={(e) => setIsDefault(e.target.checked)}
                  style={{ width: 16, height: 16, accentColor: '#1e56ff', cursor: 'pointer' }}
                />
                <label htmlFor="is_default_wh" style={{ fontSize: 13, fontWeight: 500, color: '#334155', cursor: 'pointer' }}>
                  Designate as Organization Default Fulfillment Warehouse
                </label>
              </div>
            </div>
          )}

          {/* STEP 2: Address & Regional */}
          {step === 2 && (
            <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
              <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
                <div>
                  <label style={{ display: 'block', fontSize: 13, fontWeight: 600, color: '#334155', marginBottom: 6 }}>
                    Address Line 1
                  </label>
                  <input
                    type="text"
                    placeholder="e.g. 1400 Industrial Pkwy"
                    value={addressLine1}
                    onChange={(e) => setAddressLine1(e.target.value)}
                    style={{ width: '100%', padding: '10px 14px', borderRadius: 10, border: '1px solid #cbd5e1', fontSize: 14, boxSizing: 'border-box' }}
                  />
                </div>

                <div>
                  <label style={{ display: 'block', fontSize: 13, fontWeight: 600, color: '#334155', marginBottom: 6 }}>
                    Address Line 2 (Suite / Dock / Bldg)
                  </label>
                  <input
                    type="text"
                    placeholder="e.g. Suite 400 / Dock 12"
                    value={addressLine2}
                    onChange={(e) => setAddressLine2(e.target.value)}
                    style={{ width: '100%', padding: '10px 14px', borderRadius: 10, border: '1px solid #cbd5e1', fontSize: 14, boxSizing: 'border-box' }}
                  />
                </div>
              </div>

              <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
                <div>
                  <label style={{ display: 'block', fontSize: 13, fontWeight: 600, color: '#334155', marginBottom: 6 }}>
                    City <span style={{ color: '#ef4444' }}>*</span>
                  </label>
                  <input
                    type="text"
                    placeholder="e.g. Seattle"
                    value={city}
                    onChange={(e) => setCity(e.target.value)}
                    style={{ width: '100%', padding: '10px 14px', borderRadius: 10, border: '1px solid #cbd5e1', fontSize: 14, boxSizing: 'border-box' }}
                    required
                  />
                </div>

                <div>
                  <label style={{ display: 'block', fontSize: 13, fontWeight: 600, color: '#334155', marginBottom: 6 }}>
                    State / Province
                  </label>
                  <input
                    type="text"
                    placeholder="e.g. WA"
                    value={state}
                    onChange={(e) => setState(e.target.value)}
                    style={{ width: '100%', padding: '10px 14px', borderRadius: 10, border: '1px solid #cbd5e1', fontSize: 14, boxSizing: 'border-box' }}
                  />
                </div>
              </div>

              <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
                <div>
                  <label style={{ display: 'block', fontSize: 13, fontWeight: 600, color: '#334155', marginBottom: 6 }}>
                    Postal / ZIP Code
                  </label>
                  <input
                    type="text"
                    placeholder="e.g. 98101"
                    value={postalCode}
                    onChange={(e) => setPostalCode(e.target.value)}
                    style={{ width: '100%', padding: '10px 14px', borderRadius: 10, border: '1px solid #cbd5e1', fontSize: 14, boxSizing: 'border-box' }}
                  />
                </div>

                <div>
                  <label style={{ display: 'block', fontSize: 13, fontWeight: 600, color: '#334155', marginBottom: 6 }}>
                    Country <span style={{ color: '#ef4444' }}>*</span>
                  </label>
                  <input
                    type="text"
                    placeholder="e.g. United States"
                    value={country}
                    onChange={(e) => setCountry(e.target.value)}
                    style={{ width: '100%', padding: '10px 14px', borderRadius: 10, border: '1px solid #cbd5e1', fontSize: 14, boxSizing: 'border-box' }}
                    required
                  />
                </div>
              </div>

              <div>
                <label style={{ display: 'block', fontSize: 13, fontWeight: 600, color: '#334155', marginBottom: 6 }}>
                  Operating IANA Timezone <span style={{ color: '#ef4444' }}>*</span>
                </label>
                <select
                  value={timezone}
                  onChange={(e) => setTimezone(e.target.value)}
                  style={{ width: '100%', padding: '10px 14px', borderRadius: 10, border: '1px solid #cbd5e1', fontSize: 14, boxSizing: 'border-box', backgroundColor: '#ffffff' }}
                >
                  {TIMEZONES.map((tz) => (
                    <option key={tz} value={tz}>
                      {tz}
                    </option>
                  ))}
                </select>
                <span style={{ fontSize: 11, color: '#94a3b8' }}>Critical for operational shifts and cutoff times</span>
              </div>

              <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3, 1fr)', gap: 16 }}>
                <div>
                  <label style={{ display: 'block', fontSize: 13, fontWeight: 600, color: '#334155', marginBottom: 6 }}>
                    Facility Contact Name
                  </label>
                  <input
                    type="text"
                    placeholder="e.g. Jane Doe"
                    value={contactName}
                    onChange={(e) => setContactName(e.target.value)}
                    style={{ width: '100%', padding: '10px 14px', borderRadius: 10, border: '1px solid #cbd5e1', fontSize: 14, boxSizing: 'border-box' }}
                  />
                </div>

                <div>
                  <label style={{ display: 'block', fontSize: 13, fontWeight: 600, color: '#334155', marginBottom: 6 }}>
                    Contact Email
                  </label>
                  <input
                    type="email"
                    placeholder="e.g. ops@finovo.com"
                    value={contactEmail}
                    onChange={(e) => setContactEmail(e.target.value)}
                    style={{ width: '100%', padding: '10px 14px', borderRadius: 10, border: '1px solid #cbd5e1', fontSize: 14, boxSizing: 'border-box' }}
                  />
                </div>

                <div>
                  <label style={{ display: 'block', fontSize: 13, fontWeight: 600, color: '#334155', marginBottom: 6 }}>
                    Contact Phone
                  </label>
                  <input
                    type="text"
                    placeholder="e.g. +1 206 555 0199"
                    value={contactPhone}
                    onChange={(e) => setContactPhone(e.target.value)}
                    style={{ width: '100%', padding: '10px 14px', borderRadius: 10, border: '1px solid #cbd5e1', fontSize: 14, boxSizing: 'border-box' }}
                  />
                </div>
              </div>
            </div>
          )}

          {/* STEP 3: Physical Warehouse Layout Starter */}
          {step === 3 && (
            <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
              <div
                style={{
                  padding: '14px 18px',
                  borderRadius: 12,
                  backgroundColor: '#eff6ff',
                  border: '1px solid #dbeafe',
                  display: 'flex',
                  alignItems: 'flex-start',
                  gap: 12,
                }}
              >
                <Info size={18} color="#1e56ff" style={{ flexShrink: 0, marginTop: 2 }} />
                <div style={{ fontSize: 13, color: '#1e3a8a', lineHeight: 1.5 }}>
                  <strong>Structured Physical Hierarchy</strong>: Finovo organizes physical storage as{' '}
                  <code>Zone &rarr; Aisle &rarr; Rack &rarr; Shelf &rarr; Bin</code>. An operational warehouse requires at least one picking-eligible and putaway-eligible bin.
                </div>
              </div>

              <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                <input
                  type="checkbox"
                  id="gen_starter"
                  checked={generateStarter}
                  onChange={(e) => setGenerateStarter(e.target.checked)}
                  style={{ width: 16, height: 16, accentColor: '#1e56ff', cursor: 'pointer' }}
                />
                <label htmlFor="gen_starter" style={{ fontSize: 13, fontWeight: 600, color: '#0f172a', cursor: 'pointer' }}>
                  Auto-generate Starter Physical Layout
                </label>
              </div>

              {generateStarter ? (
                <div
                  style={{
                    padding: 18,
                    borderRadius: 12,
                    border: '1px solid #e2e8f0',
                    backgroundColor: '#f8fafc',
                    display: 'flex',
                    flexDirection: 'column',
                    gap: 14,
                  }}
                >
                  <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 14 }}>
                    <div>
                      <label style={{ display: 'block', fontSize: 12, fontWeight: 600, color: '#475569', marginBottom: 4 }}>
                        Primary Zone Code
                      </label>
                      <input
                        type="text"
                        value={zoneCode}
                        onChange={(e) => setZoneCode(e.target.value.toUpperCase())}
                        style={{ width: '100%', padding: '8px 12px', borderRadius: 8, border: '1px solid #cbd5e1', fontSize: 13, boxSizing: 'border-box' }}
                      />
                    </div>
                    <div>
                      <label style={{ display: 'block', fontSize: 12, fontWeight: 600, color: '#475569', marginBottom: 4 }}>
                        Aisles Count
                      </label>
                      <input
                        type="number"
                        min="1"
                        max="20"
                        value={aislesCount}
                        onChange={(e) => setAislesCount(Math.max(1, Number(e.target.value)))}
                        style={{ width: '100%', padding: '8px 12px', borderRadius: 8, border: '1px solid #cbd5e1', fontSize: 13, boxSizing: 'border-box' }}
                      />
                    </div>
                  </div>

                  <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3, 1fr)', gap: 12 }}>
                    <div>
                      <label style={{ display: 'block', fontSize: 12, fontWeight: 600, color: '#475569', marginBottom: 4 }}>
                        Racks / Aisle
                      </label>
                      <input
                        type="number"
                        min="1"
                        max="20"
                        value={racksPerAisle}
                        onChange={(e) => setRacksPerAisle(Math.max(1, Number(e.target.value)))}
                        style={{ width: '100%', padding: '8px 12px', borderRadius: 8, border: '1px solid #cbd5e1', fontSize: 13, boxSizing: 'border-box' }}
                      />
                    </div>
                    <div>
                      <label style={{ display: 'block', fontSize: 12, fontWeight: 600, color: '#475569', marginBottom: 4 }}>
                        Shelves / Rack
                      </label>
                      <input
                        type="number"
                        min="1"
                        max="10"
                        value={shelvesPerRack}
                        onChange={(e) => setShelvesPerRack(Math.max(1, Number(e.target.value)))}
                        style={{ width: '100%', padding: '8px 12px', borderRadius: 8, border: '1px solid #cbd5e1', fontSize: 13, boxSizing: 'border-box' }}
                      />
                    </div>
                    <div>
                      <label style={{ display: 'block', fontSize: 12, fontWeight: 600, color: '#475569', marginBottom: 4 }}>
                        Bins / Shelf
                      </label>
                      <input
                        type="number"
                        min="1"
                        max="20"
                        value={binsPerShelf}
                        onChange={(e) => setBinsPerShelf(Math.max(1, Number(e.target.value)))}
                        style={{ width: '100%', padding: '8px 12px', borderRadius: 8, border: '1px solid #cbd5e1', fontSize: 13, boxSizing: 'border-box' }}
                      />
                    </div>
                  </div>

                  {/* Starter Layout Preview Banner */}
                  <div
                    style={{
                      padding: '12px 16px',
                      borderRadius: 10,
                      backgroundColor: '#ffffff',
                      border: '1px solid #cbd5e1',
                      display: 'flex',
                      alignItems: 'center',
                      justifyContent: 'space-between',
                    }}
                  >
                    <div>
                      <span style={{ fontSize: 13, fontWeight: 700, color: '#0f172a' }}>Layout Generator Preview:</span>
                      <div style={{ fontSize: 12, color: '#64748b' }}>
                        1 Zone &bull; {aislesCount} Aisles &bull; {aislesCount * racksPerAisle} Racks &bull;{' '}
                        {aislesCount * racksPerAisle * shelvesPerRack} Shelves &bull;{' '}
                        <strong>{totalCalculatedBins} Bins</strong>
                      </div>
                    </div>
                    <span
                      style={{
                        padding: '4px 10px',
                        borderRadius: 6,
                        backgroundColor: '#ecfdf5',
                        color: '#059669',
                        fontSize: 12,
                        fontWeight: 700,
                      }}
                    >
                      Eligible for Picking & Putaway
                    </span>
                  </div>
                </div>
              ) : (
                <div
                  style={{
                    padding: 20,
                    borderRadius: 12,
                    border: '1px dashed #cbd5e1',
                    textAlign: 'center',
                    color: '#64748b',
                    fontSize: 13,
                  }}
                >
                  No starter layout will be auto-generated. You can add zones, aisles, and bins manually via the Layout Manager tab once the facility is created.
                </div>
              )}
            </div>
          )}

          {/* STEP 4: Inventory & Tracking Policies */}
          {step === 4 && (
            <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
              {/* Negative Inventory Toggle */}
              <div
                style={{
                  padding: 16,
                  borderRadius: 12,
                  border: `1px solid ${allowNegativeInventory ? '#fde68a' : '#e2e8f0'}`,
                  backgroundColor: allowNegativeInventory ? '#fffbeb' : '#ffffff',
                }}
              >
                <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
                  <div>
                    <div style={{ fontSize: 13, fontWeight: 700, color: '#0f172a' }}>
                      Negative Inventory Balances
                    </div>
                    <div style={{ fontSize: 12, color: '#64748b' }}>
                      Strictly blocked by default to prevent stock desynchronization and phantom picks
                    </div>
                  </div>
                  <input
                    type="checkbox"
                    checked={allowNegativeInventory}
                    onChange={(e) => setAllowNegativeInventory(e.target.checked)}
                    style={{ width: 18, height: 18, accentColor: '#dc2626', cursor: 'pointer' }}
                  />
                </div>
                {allowNegativeInventory && (
                  <div style={{ marginTop: 10, fontSize: 12, color: '#b45309', display: 'flex', alignItems: 'center', gap: 6 }}>
                    <AlertTriangle size={14} />
                    <span>Warning: Allowing negative stock can cause severe inventory discrepancies across synced stores.</span>
                  </div>
                )}
              </div>

              <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
                <div>
                  <label style={{ display: 'block', fontSize: 13, fontWeight: 600, color: '#334155', marginBottom: 6 }}>
                    Stock Reservation Policy
                  </label>
                  <select
                    value={reservationPolicy}
                    onChange={(e) => setReservationPolicy(e.target.value)}
                    style={{ width: '100%', padding: '10px 14px', borderRadius: 10, border: '1px solid #cbd5e1', fontSize: 13, boxSizing: 'border-box' }}
                  >
                    <option value="immediate_at_order">Immediate at Order Creation (Recommended)</option>
                    <option value="at_pick_release">At Pick Release</option>
                    <option value="manual">Manual Reservation Only</option>
                  </select>
                </div>

                <div>
                  <label style={{ display: 'block', fontSize: 13, fontWeight: 600, color: '#334155', marginBottom: 6 }}>
                    Picking Strategy / Stock Rotation
                  </label>
                  <select
                    value={pickingStrategy}
                    onChange={(e) => setPickingStrategy(e.target.value)}
                    style={{ width: '100%', padding: '10px 14px', borderRadius: 10, border: '1px solid #cbd5e1', fontSize: 13, boxSizing: 'border-box' }}
                  >
                    <option value="fifo">FIFO (First In, First Out)</option>
                    <option value="fefo">FEFO (First Expired, First Out)</option>
                    <option value="lefo">LEFO (Last In, First Out)</option>
                  </select>
                </div>
              </div>

              {/* Tracking Toggles */}
              <div style={{ padding: 16, borderRadius: 12, border: '1px solid #e2e8f0', backgroundColor: '#f8fafc' }}>
                <span style={{ fontSize: 13, fontWeight: 700, color: '#0f172a', display: 'block', marginBottom: 12 }}>
                  Tracking & Traceability Toggles
                </span>
                <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3, 1fr)', gap: 12 }}>
                  <label style={{ display: 'flex', alignItems: 'center', gap: 8, fontSize: 13, color: '#334155', cursor: 'pointer' }}>
                    <input
                      type="checkbox"
                      checked={lotTracking}
                      onChange={(e) => setLotTracking(e.target.checked)}
                      style={{ accentColor: '#1e56ff' }}
                    />
                    <span>Lot / Batch Tracking</span>
                  </label>
                  <label style={{ display: 'flex', alignItems: 'center', gap: 8, fontSize: 13, color: '#334155', cursor: 'pointer' }}>
                    <input
                      type="checkbox"
                      checked={serialTracking}
                      onChange={(e) => setSerialTracking(e.target.checked)}
                      style={{ accentColor: '#1e56ff' }}
                    />
                    <span>Serial Tracking</span>
                  </label>
                  <label style={{ display: 'flex', alignItems: 'center', gap: 8, fontSize: 13, color: '#334155', cursor: 'pointer' }}>
                    <input
                      type="checkbox"
                      checked={expiryTracking}
                      onChange={(e) => setExpiryTracking(e.target.checked)}
                      style={{ accentColor: '#1e56ff' }}
                    />
                    <span>Expiry Tracking</span>
                  </label>
                </div>
              </div>

              <div>
                <label style={{ display: 'block', fontSize: 13, fontWeight: 600, color: '#334155', marginBottom: 6 }}>
                  Cycle Count Frequency
                </label>
                <select
                  value={cycleCountFrequency}
                  onChange={(e) => setCycleCountFrequency(e.target.value)}
                  style={{ width: '100%', padding: '10px 14px', borderRadius: 10, border: '1px solid #cbd5e1', fontSize: 13, boxSizing: 'border-box' }}
                >
                  <option value="continuous">Continuous Cycle Counting</option>
                  <option value="daily">Daily High-Velocity Recounts</option>
                  <option value="weekly">Weekly Category Audits</option>
                  <option value="monthly">Monthly Full Reconciliation</option>
                </select>
              </div>
            </div>
          )}

          {/* STEP 5: Inbound & Outbound Operations */}
          {step === 5 && (
            <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
              {/* Inbound Section */}
              <div style={{ padding: 16, borderRadius: 12, border: '1px solid #e2e8f0', backgroundColor: '#ffffff' }}>
                <span style={{ fontSize: 13, fontWeight: 700, color: '#0f172a', display: 'block', marginBottom: 12 }}>
                  Inbound Receiving Policies
                </span>
                <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12, marginBottom: 14 }}>
                  <label style={{ display: 'flex', alignItems: 'center', gap: 8, fontSize: 13, color: '#334155', cursor: 'pointer' }}>
                    <input
                      type="checkbox"
                      checked={poRequired}
                      onChange={(e) => setPoRequired(e.target.checked)}
                      style={{ accentColor: '#1e56ff' }}
                    />
                    <span>Purchase Order Required</span>
                  </label>
                  <label style={{ display: 'flex', alignItems: 'center', gap: 8, fontSize: 13, color: '#334155', cursor: 'pointer' }}>
                    <input
                      type="checkbox"
                      checked={blindReceiving}
                      onChange={(e) => setBlindReceiving(e.target.checked)}
                      style={{ accentColor: '#1e56ff' }}
                    />
                    <span>Allow Blind Inbound Receiving</span>
                  </label>
                  <label style={{ display: 'flex', alignItems: 'center', gap: 8, fontSize: 13, color: '#334155', cursor: 'pointer' }}>
                    <input
                      type="checkbox"
                      checked={qcRequired}
                      onChange={(e) => setQcRequired(e.target.checked)}
                      style={{ accentColor: '#1e56ff' }}
                    />
                    <span>Mandatory QC Inspection</span>
                  </label>
                </div>
                <div>
                  <label style={{ display: 'block', fontSize: 12, fontWeight: 600, color: '#475569', marginBottom: 4 }}>
                    Putaway Routing
                  </label>
                  <select
                    value={putawayStrategy}
                    onChange={(e) => setPutawayStrategy(e.target.value)}
                    style={{ width: '100%', padding: '8px 12px', borderRadius: 8, border: '1px solid #cbd5e1', fontSize: 13, boxSizing: 'border-box' }}
                  >
                    <option value="direct_to_bin">Direct to Primary Storage Bin</option>
                    <option value="staged_putaway">Staged Inbound Staging Area</option>
                    <option value="cross_dock">Cross-Dock to Outbound Staging</option>
                  </select>
                </div>
              </div>

              {/* Outbound Section */}
              <div style={{ padding: 16, borderRadius: 12, border: '1px solid #e2e8f0', backgroundColor: '#ffffff' }}>
                <span style={{ fontSize: 13, fontWeight: 700, color: '#0f172a', display: 'block', marginBottom: 12 }}>
                  Outbound Fulfillment Policies
                </span>
                <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12, marginBottom: 14 }}>
                  <label style={{ display: 'flex', alignItems: 'center', gap: 8, fontSize: 13, color: '#334155', cursor: 'pointer' }}>
                    <input
                      type="checkbox"
                      checked={packingScanRequired}
                      onChange={(e) => setPackingScanRequired(e.target.checked)}
                      style={{ accentColor: '#1e56ff' }}
                    />
                    <span>Barcode Scan Verification at Packing</span>
                  </label>
                  <label style={{ display: 'flex', alignItems: 'center', gap: 8, fontSize: 13, color: '#334155', cursor: 'pointer' }}>
                    <input
                      type="checkbox"
                      checked={partialFulfillment}
                      onChange={(e) => setPartialFulfillment(e.target.checked)}
                      style={{ accentColor: '#1e56ff' }}
                    />
                    <span>Allow Partial Fulfillment</span>
                  </label>
                  <label style={{ display: 'flex', alignItems: 'center', gap: 8, fontSize: 13, color: '#334155', cursor: 'pointer' }}>
                    <input
                      type="checkbox"
                      checked={backorderAllowed}
                      onChange={(e) => setBackorderAllowed(e.target.checked)}
                      style={{ accentColor: '#1e56ff' }}
                    />
                    <span>Allow Backorders on Out-of-Stock</span>
                  </label>
                </div>
                <div>
                  <label style={{ display: 'block', fontSize: 12, fontWeight: 600, color: '#475569', marginBottom: 4 }}>
                    Picking Workflow Strategy
                  </label>
                  <select
                    value={outboundPickingStrategy}
                    onChange={(e) => setOutboundPickingStrategy(e.target.value)}
                    style={{ width: '100%', padding: '8px 12px', borderRadius: 8, border: '1px solid #cbd5e1', fontSize: 13, boxSizing: 'border-box' }}
                  >
                    <option value="batch">Batch Picking (Multi-Order Consolidated)</option>
                    <option value="discrete">Discrete Order Picking (Single Order)</option>
                    <option value="wave">Wave Picking (Scheduled Windows)</option>
                    <option value="zone">Zone Picking (Zone Handoff)</option>
                  </select>
                </div>
              </div>
            </div>
          )}

          {/* STEP 6: Review & Save */}
          {step === 6 && (
            <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
              <div
                style={{
                  padding: 16,
                  borderRadius: 12,
                  backgroundColor: '#f8fafc',
                  border: '1px solid #e2e8f0',
                  display: 'grid',
                  gridTemplateColumns: '1fr 1fr',
                  gap: 16,
                }}
              >
                <div>
                  <div style={{ fontSize: 11, fontWeight: 700, color: '#94a3b8', textTransform: 'uppercase' }}>
                    Facility Info
                  </div>
                  <div style={{ fontSize: 15, fontWeight: 700, color: '#0f172a', marginTop: 4 }}>{name}</div>
                  <div style={{ fontSize: 12, color: '#64748b' }}>Code: {code}</div>
                  <div style={{ fontSize: 12, color: '#64748b' }}>Type: {type.replace(/_/g, ' ')}</div>
                  <div style={{ fontSize: 12, color: '#64748b' }}>Priority: #{priority}</div>
                </div>

                <div>
                  <div style={{ fontSize: 11, fontWeight: 700, color: '#94a3b8', textTransform: 'uppercase' }}>
                    Location & Regional
                  </div>
                  <div style={{ fontSize: 13, fontWeight: 600, color: '#0f172a', marginTop: 4 }}>
                    {city}, {country}
                  </div>
                  <div style={{ fontSize: 12, color: '#64748b' }}>Timezone: {timezone}</div>
                  <div style={{ fontSize: 12, color: '#64748b' }}>Contact: {contactName || 'None'}</div>
                </div>
              </div>

              <div
                style={{
                  padding: 16,
                  borderRadius: 12,
                  backgroundColor: '#f8fafc',
                  border: '1px solid #e2e8f0',
                  display: 'grid',
                  gridTemplateColumns: '1fr 1fr',
                  gap: 16,
                }}
              >
                <div>
                  <div style={{ fontSize: 11, fontWeight: 700, color: '#94a3b8', textTransform: 'uppercase' }}>
                    Layout Plan
                  </div>
                  <div style={{ fontSize: 13, fontWeight: 600, color: '#0f172a', marginTop: 4 }}>
                    {generateStarter ? `${totalCalculatedBins} Bins in ${aislesCount} Aisles` : 'Manual Setup'}
                  </div>
                  <div style={{ fontSize: 12, color: '#059669' }}>
                    {generateStarter ? 'Auto-creates picking/putaway bins' : 'Configured later'}
                  </div>
                </div>

                <div>
                  <div style={{ fontSize: 11, fontWeight: 700, color: '#94a3b8', textTransform: 'uppercase' }}>
                    Policies
                  </div>
                  <div style={{ fontSize: 12, color: '#64748b', marginTop: 4 }}>
                    Negative Stock: {allowNegativeInventory ? 'Allowed (Warning)' : 'Blocked (Safe)'}
                  </div>
                  <div style={{ fontSize: 12, color: '#64748b' }}>
                    Allocation: {pickingStrategy.toUpperCase()} &bull; Res: {reservationPolicy.replace(/_/g, ' ')}
                  </div>
                </div>
              </div>

              <div
                style={{
                  padding: '14px 18px',
                  borderRadius: 12,
                  backgroundColor: '#eff6ff',
                  border: '1px solid #bfdbfe',
                  display: 'flex',
                  alignItems: 'center',
                  gap: 12,
                }}
              >
                <CheckCircle2 size={20} color="#1e56ff" />
                <div style={{ fontSize: 13, color: '#1e3a8a' }}>
                  <strong>Ready to initialize:</strong> You can save this facility as a Draft to review physical racks and staff, or attempt immediate activation.
                </div>
              </div>
            </div>
          )}
        </div>

        {/* Footer Controls */}
        <div
          style={{
            padding: '16px 24px',
            borderTop: '1px solid #e2e8f0',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'space-between',
            backgroundColor: '#f8fafc',
          }}
        >
          {step > 1 ? (
            <button
              type="button"
              onClick={handleBack}
              disabled={submitting}
              className="btn-finovo-secondary"
            >
              <ArrowLeft size={16} />
              <span>Back</span>
            </button>
          ) : (
            <button
              type="button"
              onClick={onClose}
              disabled={submitting}
              className="btn-finovo-secondary"
            >
              Cancel
            </button>
          )}

          <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
            {step < 6 ? (
              <button
                type="button"
                onClick={handleNext}
                className="btn-finovo-primary"
              >
                <span>Continue</span>
                <ArrowRight size={16} />
              </button>
            ) : (
              <>
                <button
                  type="button"
                  onClick={() => handleSubmit(false)}
                  disabled={submitting}
                  className="btn-finovo-secondary"
                >
                  {submitting && <Loader2 size={16} className="animate-spin" />}
                  <span>Save as Draft</span>
                </button>
                <button
                  type="button"
                  onClick={() => handleSubmit(true)}
                  disabled={submitting}
                  className="btn-finovo-primary"
                >
                  {submitting && <Loader2 size={16} className="animate-spin" />}
                  <span>Save & Activate</span>
                </button>
              </>
            )}
          </div>
        </div>
      </div>
    </div>
  );
};

export default WarehouseWizardModal;
