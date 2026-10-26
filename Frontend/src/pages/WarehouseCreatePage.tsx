import React, { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import {
  ArrowLeft,
  ArrowRight,
  Building,
  CheckCircle2,
  Layers,
  MapPin,
  PackageCheck,
  ShieldAlert,
  Loader2,
  Save,
  Check,
  Sparkles,
} from 'lucide-react';
import CustomDropdown from '../components/common/CustomDropdown';
import omsService, { WAREHOUSE_TYPES } from '../services/omsService';

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


export const WarehouseCreatePage: React.FC = () => {
  const navigate = useNavigate();

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

  const totalCalculatedBins = aislesCount * racksPerAisle * shelvesPerRack * binsPerShelf;

  const stepsMeta = [
    { num: 1, label: 'Identity & Type', icon: Building, desc: 'Facility basics & priority' },
    { num: 2, label: 'Location & Region', icon: MapPin, desc: 'Address, timezone, contact' },
    { num: 3, label: 'Physical Layout', icon: Layers, desc: 'Topology & starter bins' },
    { num: 4, label: 'Inventory Policies', icon: ShieldAlert, desc: 'Tracking & picking strategy' },
    { num: 5, label: 'Inbound & Outbound', icon: PackageCheck, desc: 'PO & dispatch rules' },
    { num: 6, label: 'Review & Save', icon: CheckCircle2, desc: 'Readiness & commit' },
  ];

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
    window.scrollTo({ top: 0, behavior: 'smooth' });
  };

  const handleBack = () => {
    setErrorMessage(null);
    setStep((prev) => Math.max(prev - 1, 1));
    window.scrollTo({ top: 0, behavior: 'smooth' });
  };

  const handleSubmit = async (activateImmediately: boolean = false) => {
    try {
      setSubmitting(true);
      setErrorMessage(null);

      const payload = {
        name,
        code: code.toUpperCase().trim(),
        warehouse_type: type,
        is_default: isDefault,
        priority: Number(priority),
        status: 'draft',
        address: addressLine1,
        address_line_2: addressLine2,
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

      // If starter layout requested, generate it:
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
          console.warn('Starter layout generation note:', starterErr);
        }
      }

      // If user chose to activate immediately, attempt activation:
      if (activateImmediately && newWh?.id) {
        try {
          await omsService.activateWarehouse(newWh.id);
        } catch (actErr: any) {
          console.warn('Activation note:', actErr);
        }
      }

      navigate(`/warehouses/${newWh.id}`, { replace: true });
    } catch (err: any) {
      console.error(err);
      setErrorMessage(err.message || 'Failed to create warehouse.');
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <div className="page-container">
      {/* Breadcrumb Navigation */}
      <nav className="breadcrumb-nav">
        <span>Finovo</span>
        <span>&gt;</span>
        <span style={{ cursor: 'pointer', color: '#1e56ff' }} onClick={() => navigate('/warehouses')}>
          Warehouses
        </span>
        <span>&gt;</span>
        <span style={{ color: '#0f172a', fontWeight: 600 }}>Creation Wizard</span>
      </nav>

      {/* Page Header */}
      <div className="page-header-row" style={{ marginBottom: 20 }}>
        <div className="page-title-section">
          <div className="page-title-badge-wrap">
            <h1 className="page-title">Create New Warehouse Facility</h1>
            <span className="page-count-pill" style={{ backgroundColor: '#eff6ff', color: '#1e56ff' }}>
              Step {step} of 6
            </span>
          </div>
          <p className="page-subtitle">
            Configure physical distribution parameters, layout zones, capacity, and tracking policies.
          </p>
        </div>

        <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
          <button
            type="button"
            onClick={() => navigate('/warehouses')}
            className="btn-finovo-secondary"
            style={{ padding: '9px 16px', fontSize: 13 }}
          >
            <ArrowLeft size={15} />
            <span>Back to Warehouses</span>
          </button>
          <button
            type="button"
            onClick={() => handleSubmit(false)}
            disabled={submitting || !name.trim() || !code.trim()}
            className="btn-finovo-secondary"
            style={{ padding: '9px 16px', fontSize: 13 }}
            title="Save draft at any stage"
          >
            <Save size={15} />
            <span>Save Draft</span>
          </button>
        </div>
      </div>

      {/* Stepper Progress Bar */}
      <div
        className="content-card"
        style={{
          padding: '16px 20px',
          marginBottom: 24,
          backgroundColor: '#ffffff',
        }}
      >
        <div
          style={{
            display: 'grid',
            gridTemplateColumns: 'repeat(auto-fit, minmax(140px, 1fr))',
            gap: 12,
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
                  gap: 10,
                  cursor: isCompleted ? 'pointer' : 'default',
                  padding: '8px 12px',
                  borderRadius: 10,
                  backgroundColor: isActive ? '#eff6ff' : isCompleted ? '#f8fafc' : 'transparent',
                  border: `1px solid ${isActive ? '#bfdbfe' : isCompleted ? '#e2e8f0' : 'transparent'}`,
                  transition: 'all 0.15s ease',
                }}
              >
                <div
                  style={{
                    width: 32,
                    height: 32,
                    borderRadius: 8,
                    backgroundColor: isCompleted ? '#ecfdf5' : isActive ? '#1e56ff' : '#f1f5f9',
                    color: isCompleted ? '#059669' : isActive ? '#ffffff' : '#64748b',
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                    fontSize: 13,
                    fontWeight: 700,
                    flexShrink: 0,
                  }}
                >
                  {isCompleted ? <Check size={16} /> : <IconComponent size={16} />}
                </div>
                <div style={{ display: 'flex', flexDirection: 'column', overflow: 'hidden' }}>
                  <span
                    style={{
                      fontSize: 12,
                      fontWeight: isActive ? 700 : 600,
                      color: isActive ? '#1e56ff' : isCompleted ? '#0f172a' : '#64748b',
                      whiteSpace: 'nowrap',
                      textOverflow: 'ellipsis',
                      overflow: 'hidden',
                    }}
                  >
                    {s.label}
                  </span>
                  <span style={{ fontSize: 10, color: '#94a3b8' }}>Step {s.num}</span>
                </div>
              </div>
            );
          })}
        </div>
      </div>

      {/* Main Wizard Form Body Card */}
      <div className="content-card" style={{ padding: 28, backgroundColor: '#ffffff', marginBottom: 24 }}>
        {errorMessage && (
          <div
            style={{
              padding: '12px 16px',
              borderRadius: 10,
              backgroundColor: '#fef2f2',
              border: '1px solid #fecaca',
              color: '#b91c1c',
              fontSize: 13,
              marginBottom: 24,
              display: 'flex',
              alignItems: 'center',
              gap: 8,
            }}
          >
            <span>{errorMessage}</span>
          </div>
        )}

        {/* STEP 1: IDENTITY & BASIC INFO */}
        {step === 1 && (
          <div>
            <div style={{ marginBottom: 20 }}>
              <h2 style={{ margin: 0, fontSize: 17, fontWeight: 700, color: '#0f172a' }}>
                Step 1: Facility Identity & Classification
              </h2>
              <p style={{ margin: '4px 0 0 0', fontSize: 13, color: '#64748b' }}>
                Provide foundational naming, unique facility codes, and allocation priority.
              </p>
            </div>

            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(300px, 1fr))', gap: 20 }}>
              <div>
                <label style={{ display: 'block', fontSize: 13, fontWeight: 600, marginBottom: 6, color: '#334155' }}>
                  Warehouse Name <span style={{ color: '#ef4444' }}>*</span>
                </label>
                <input
                  type="text"
                  placeholder="e.g. Central Dallas Fulfillment Center"
                  value={name}
                  onChange={(e) => setName(e.target.value)}
                  style={{
                    width: '100%',
                    padding: '10px 14px',
                    borderRadius: 10,
                    border: '1px solid #cbd5e1',
                    fontSize: 13,
                    boxSizing: 'border-box',
                  }}
                  required
                />
              </div>

              <div>
                <label style={{ display: 'block', fontSize: 13, fontWeight: 600, marginBottom: 6, color: '#334155' }}>
                  Facility Code <span style={{ color: '#ef4444' }}>*</span>
                </label>
                <input
                  type="text"
                  placeholder="e.g. WH-DAL-01"
                  value={code}
                  onChange={(e) => setCode(e.target.value.toUpperCase())}
                  style={{
                    width: '100%',
                    padding: '10px 14px',
                    borderRadius: 10,
                    border: '1px solid #cbd5e1',
                    fontSize: 13,
                    fontWeight: 700,
                    letterSpacing: '0.04em',
                    boxSizing: 'border-box',
                  }}
                  required
                />
                <span style={{ fontSize: 11, color: '#94a3b8', marginTop: 4, display: 'block' }}>
                  Uppercase alphanumeric identifier, must be unique within organization.
                </span>
              </div>

              <div style={{ gridColumn: '1 / -1' }}>
                <CustomDropdown
                  label="Warehouse Operating Type"
                  required
                  value={type}
                  onChange={(val) => setType(val)}
                  options={WAREHOUSE_TYPES.map((t) => ({ ...t }))}
                  size="md"
                />
              </div>

              <div>
                <label style={{ display: 'block', fontSize: 13, fontWeight: 600, marginBottom: 6, color: '#334155' }}>
                  Fulfillment Priority Rank (1 - 100)
                </label>
                <input
                  type="number"
                  min="1"
                  max="100"
                  value={priority}
                  onChange={(e) => setPriority(Number(e.target.value))}
                  style={{
                    width: '100%',
                    padding: '10px 14px',
                    borderRadius: 10,
                    border: '1px solid #cbd5e1',
                    fontSize: 13,
                    boxSizing: 'border-box',
                  }}
                />
                <span style={{ fontSize: 11, color: '#94a3b8', marginTop: 4, display: 'block' }}>
                  Lower numbers take precedence during multi-node inventory reservation.
                </span>
              </div>

              <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginTop: 24 }}>
                <input
                  type="checkbox"
                  id="chk-default-wh"
                  checked={isDefault}
                  onChange={(e) => setIsDefault(e.target.checked)}
                  style={{ width: 18, height: 18, accentColor: '#1e56ff', cursor: 'pointer' }}
                />
                <label htmlFor="chk-default-wh" style={{ fontSize: 13, fontWeight: 600, color: '#334155', cursor: 'pointer' }}>
                  Set as Default Primary Warehouse for Organization
                </label>
              </div>
            </div>
          </div>
        )}

        {/* STEP 2: ADDRESS & REGIONAL OPS */}
        {step === 2 && (
          <div>
            <div style={{ marginBottom: 20 }}>
              <h2 style={{ margin: 0, fontSize: 17, fontWeight: 700, color: '#0f172a' }}>
                Step 2: Geographic Location & Regional Operations
              </h2>
              <p style={{ margin: '4px 0 0 0', fontSize: 13, color: '#64748b' }}>
                Accurate physical location and operating timezone ensure precise SLA and cutoff calculations.
              </p>
            </div>

            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(280px, 1fr))', gap: 20 }}>
              <div style={{ gridColumn: '1 / -1' }}>
                <label style={{ display: 'block', fontSize: 13, fontWeight: 600, marginBottom: 6, color: '#334155' }}>
                  Street Address Line 1
                </label>
                <input
                  type="text"
                  placeholder="e.g. 10400 Logistics Blvd, Suite 200"
                  value={addressLine1}
                  onChange={(e) => setAddressLine1(e.target.value)}
                  style={{
                    width: '100%',
                    padding: '10px 14px',
                    borderRadius: 10,
                    border: '1px solid #cbd5e1',
                    fontSize: 13,
                    boxSizing: 'border-box',
                  }}
                />
              </div>

              <div style={{ gridColumn: '1 / -1' }}>
                <label style={{ display: 'block', fontSize: 13, fontWeight: 600, marginBottom: 6, color: '#334155' }}>
                  Street Address Line 2 (Optional)
                </label>
                <input
                  type="text"
                  placeholder="e.g. Building B, Dock 4-12"
                  value={addressLine2}
                  onChange={(e) => setAddressLine2(e.target.value)}
                  style={{
                    width: '100%',
                    padding: '10px 14px',
                    borderRadius: 10,
                    border: '1px solid #cbd5e1',
                    fontSize: 13,
                    boxSizing: 'border-box',
                  }}
                />
              </div>

              <div>
                <label style={{ display: 'block', fontSize: 13, fontWeight: 600, marginBottom: 6, color: '#334155' }}>
                  City <span style={{ color: '#ef4444' }}>*</span>
                </label>
                <input
                  type="text"
                  placeholder="e.g. Dallas"
                  value={city}
                  onChange={(e) => setCity(e.target.value)}
                  style={{
                    width: '100%',
                    padding: '10px 14px',
                    borderRadius: 10,
                    border: '1px solid #cbd5e1',
                    fontSize: 13,
                    boxSizing: 'border-box',
                  }}
                  required
                />
              </div>

              <div>
                <label style={{ display: 'block', fontSize: 13, fontWeight: 600, marginBottom: 6, color: '#334155' }}>
                  State / Province
                </label>
                <input
                  type="text"
                  placeholder="e.g. TX"
                  value={state}
                  onChange={(e) => setState(e.target.value)}
                  style={{
                    width: '100%',
                    padding: '10px 14px',
                    borderRadius: 10,
                    border: '1px solid #cbd5e1',
                    fontSize: 13,
                    boxSizing: 'border-box',
                  }}
                />
              </div>

              <div>
                <label style={{ display: 'block', fontSize: 13, fontWeight: 600, marginBottom: 6, color: '#334155' }}>
                  Postal / ZIP Code
                </label>
                <input
                  type="text"
                  placeholder="e.g. 75261"
                  value={postalCode}
                  onChange={(e) => setPostalCode(e.target.value)}
                  style={{
                    width: '100%',
                    padding: '10px 14px',
                    borderRadius: 10,
                    border: '1px solid #cbd5e1',
                    fontSize: 13,
                    boxSizing: 'border-box',
                  }}
                />
              </div>

              <div>
                <label style={{ display: 'block', fontSize: 13, fontWeight: 600, marginBottom: 6, color: '#334155' }}>
                  Country <span style={{ color: '#ef4444' }}>*</span>
                </label>
                <input
                  type="text"
                  placeholder="e.g. United States"
                  value={country}
                  onChange={(e) => setCountry(e.target.value)}
                  style={{
                    width: '100%',
                    padding: '10px 14px',
                    borderRadius: 10,
                    border: '1px solid #cbd5e1',
                    fontSize: 13,
                    boxSizing: 'border-box',
                  }}
                  required
                />
              </div>

              <div>
                <CustomDropdown
                  label="Operational Timezone"
                  required
                  value={timezone}
                  onChange={(val) => setTimezone(val)}
                  options={TIMEZONES.map((tz) => ({ value: tz, label: tz }))}
                  searchable
                  size="md"
                />
              </div>

              <div>
                <label style={{ display: 'block', fontSize: 13, fontWeight: 600, marginBottom: 6, color: '#334155' }}>
                  Primary Contact Name
                </label>
                <input
                  type="text"
                  placeholder="e.g. Sarah Jenkins (Facility Manager)"
                  value={contactName}
                  onChange={(e) => setContactName(e.target.value)}
                  style={{
                    width: '100%',
                    padding: '10px 14px',
                    borderRadius: 10,
                    border: '1px solid #cbd5e1',
                    fontSize: 13,
                    boxSizing: 'border-box',
                  }}
                />
              </div>

              <div>
                <label style={{ display: 'block', fontSize: 13, fontWeight: 600, marginBottom: 6, color: '#334155' }}>
                  Primary Contact Email
                </label>
                <input
                  type="email"
                  placeholder="e.g. dallas-ops@company.com"
                  value={contactEmail}
                  onChange={(e) => setContactEmail(e.target.value)}
                  style={{
                    width: '100%',
                    padding: '10px 14px',
                    borderRadius: 10,
                    border: '1px solid #cbd5e1',
                    fontSize: 13,
                    boxSizing: 'border-box',
                  }}
                />
              </div>

              <div>
                <label style={{ display: 'block', fontSize: 13, fontWeight: 600, marginBottom: 6, color: '#334155' }}>
                  Primary Contact Phone
                </label>
                <input
                  type="tel"
                  placeholder="e.g. +1 (214) 555-0199"
                  value={contactPhone}
                  onChange={(e) => setContactPhone(e.target.value)}
                  style={{
                    width: '100%',
                    padding: '10px 14px',
                    borderRadius: 10,
                    border: '1px solid #cbd5e1',
                    fontSize: 13,
                    boxSizing: 'border-box',
                  }}
                />
              </div>
            </div>
          </div>
        )}

        {/* STEP 3: PHYSICAL LAYOUT STARTER */}
        {step === 3 && (
          <div>
            <div style={{ marginBottom: 20 }}>
              <h2 style={{ margin: 0, fontSize: 17, fontWeight: 700, color: '#0f172a' }}>
                Step 3: Physical Layout & Topology Starter
              </h2>
              <p style={{ margin: '4px 0 0 0', fontSize: 13, color: '#64748b' }}>
                Establish a 5-tier location hierarchy (Zone → Aisle → Rack → Shelf → Bin) automatically or configure later.
              </p>
            </div>

            <div
              style={{
                padding: 18,
                borderRadius: 12,
                backgroundColor: generateStarter ? '#f0fdf4' : '#f8fafc',
                border: `1px solid ${generateStarter ? '#bbf7d0' : '#e2e8f0'}`,
                marginBottom: 24,
                display: 'flex',
                alignItems: 'center',
                gap: 12,
              }}
            >
              <input
                type="checkbox"
                id="chk-starter-layout"
                checked={generateStarter}
                onChange={(e) => setGenerateStarter(e.target.checked)}
                style={{ width: 18, height: 18, accentColor: '#10b981', cursor: 'pointer' }}
              />
              <label htmlFor="chk-starter-layout" style={{ fontSize: 13, fontWeight: 700, color: '#166534', cursor: 'pointer' }}>
                Auto-generate initial layout structure (Recommended for quick readiness activation)
              </label>
            </div>

            {generateStarter && (
              <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))', gap: 16 }}>
                <div>
                  <label style={{ display: 'block', fontSize: 12, fontWeight: 600, marginBottom: 6, color: '#334155' }}>
                    Primary Zone Code
                  </label>
                  <input
                    type="text"
                    value={zoneCode}
                    onChange={(e) => setZoneCode(e.target.value.toUpperCase())}
                    style={{
                      width: '100%',
                      padding: '8px 12px',
                      borderRadius: 8,
                      border: '1px solid #cbd5e1',
                      fontSize: 13,
                      boxSizing: 'border-box',
                    }}
                  />
                </div>

                <div>
                  <label style={{ display: 'block', fontSize: 12, fontWeight: 600, marginBottom: 6, color: '#334155' }}>
                    Aisles Count
                  </label>
                  <input
                    type="number"
                    min="1"
                    max="10"
                    value={aislesCount}
                    onChange={(e) => setAislesCount(Math.max(1, Number(e.target.value)))}
                    style={{
                      width: '100%',
                      padding: '8px 12px',
                      borderRadius: 8,
                      border: '1px solid #cbd5e1',
                      fontSize: 13,
                      boxSizing: 'border-box',
                    }}
                  />
                </div>

                <div>
                  <label style={{ display: 'block', fontSize: 12, fontWeight: 600, marginBottom: 6, color: '#334155' }}>
                    Racks Per Aisle
                  </label>
                  <input
                    type="number"
                    min="1"
                    max="10"
                    value={racksPerAisle}
                    onChange={(e) => setRacksPerAisle(Math.max(1, Number(e.target.value)))}
                    style={{
                      width: '100%',
                      padding: '8px 12px',
                      borderRadius: 8,
                      border: '1px solid #cbd5e1',
                      fontSize: 13,
                      boxSizing: 'border-box',
                    }}
                  />
                </div>

                <div>
                  <label style={{ display: 'block', fontSize: 12, fontWeight: 600, marginBottom: 6, color: '#334155' }}>
                    Shelves Per Rack
                  </label>
                  <input
                    type="number"
                    min="1"
                    max="10"
                    value={shelvesPerRack}
                    onChange={(e) => setShelvesPerRack(Math.max(1, Number(e.target.value)))}
                    style={{
                      width: '100%',
                      padding: '8px 12px',
                      borderRadius: 8,
                      border: '1px solid #cbd5e1',
                      fontSize: 13,
                      boxSizing: 'border-box',
                    }}
                  />
                </div>

                <div>
                  <label style={{ display: 'block', fontSize: 12, fontWeight: 600, marginBottom: 6, color: '#334155' }}>
                    Bins Per Shelf
                  </label>
                  <input
                    type="number"
                    min="1"
                    max="10"
                    value={binsPerShelf}
                    onChange={(e) => setBinsPerShelf(Math.max(1, Number(e.target.value)))}
                    style={{
                      width: '100%',
                      padding: '8px 12px',
                      borderRadius: 8,
                      border: '1px solid #cbd5e1',
                      fontSize: 13,
                      boxSizing: 'border-box',
                    }}
                  />
                </div>

                <div
                  style={{
                    gridColumn: '1 / -1',
                    padding: 16,
                    borderRadius: 10,
                    backgroundColor: '#eff6ff',
                    border: '1px solid #dbeafe',
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'space-between',
                  }}
                >
                  <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                    <Sparkles size={18} color="#1e56ff" />
                    <span style={{ fontSize: 13, color: '#1e40af', fontWeight: 600 }}>
                      Topology Preview: 1 Zone &rarr; {aislesCount} Aisles &rarr; {aislesCount * racksPerAisle} Racks &rarr; {aislesCount * racksPerAisle * shelvesPerRack} Shelves
                    </span>
                  </div>
                  <span style={{ fontSize: 13, fontWeight: 700, color: '#1e56ff', backgroundColor: '#ffffff', padding: '4px 10px', borderRadius: 6 }}>
                    Total {totalCalculatedBins} Standard Bins
                  </span>
                </div>
              </div>
            )}
          </div>
        )}

        {/* STEP 4: INVENTORY POLICIES */}
        {step === 4 && (
          <div>
            <div style={{ marginBottom: 20 }}>
              <h2 style={{ margin: 0, fontSize: 17, fontWeight: 700, color: '#0f172a' }}>
                Step 4: Inventory & Tracking Policies
              </h2>
              <p style={{ margin: '4px 0 0 0', fontSize: 13, color: '#64748b' }}>
                Enforce authoritative stock validation rules, tracking attributes, and picking strategies.
              </p>
            </div>

            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(280px, 1fr))', gap: 20 }}>
              <div>
                <CustomDropdown
                  label="Picking Strategy"
                  value={pickingStrategy}
                  onChange={(val) => setPickingStrategy(val)}
                  options={[
                    { value: 'fifo', label: 'FIFO (First-In, First-Out)' },
                    { value: 'fefo', label: 'FEFO (First-Expired, First-Out)' },
                    { value: 'lifo', label: 'LIFO (Last-In, First-Out)' },
                  ]}
                  size="md"
                />
              </div>

              <div>
                <CustomDropdown
                  label="Order Stock Reservation Policy"
                  value={reservationPolicy}
                  onChange={(val) => setReservationPolicy(val)}
                  options={[
                    { value: 'immediate_at_order', label: 'Immediate at Order Ingestion' },
                    { value: 'at_wave_release', label: 'Upon Wave / Pick Task Generation' },
                  ]}
                  size="md"
                />
              </div>

              <div>
                <CustomDropdown
                  label="Cycle Count Frequency"
                  value={cycleCountFrequency}
                  onChange={(val) => setCycleCountFrequency(val)}
                  options={[
                    { value: 'daily', label: 'Daily Continuous Cycle Counting' },
                    { value: 'weekly', label: 'Weekly ABC Class Counting' },
                    { value: 'monthly', label: 'Monthly Facility Count' },
                  ]}
                  size="md"
                />
              </div>

              <div style={{ gridColumn: '1 / -1', borderTop: '1px solid #f1f5f9', paddingTop: 16 }}>
                <h3 style={{ fontSize: 14, fontWeight: 700, margin: '0 0 12px 0', color: '#0f172a' }}>
                  Mandatory Stock Item Tracking Attributes
                </h3>
                <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))', gap: 14 }}>
                  <label style={{ display: 'flex', alignItems: 'center', gap: 10, fontSize: 13, cursor: 'pointer' }}>
                    <input
                      type="checkbox"
                      checked={lotTracking}
                      onChange={(e) => setLotTracking(e.target.checked)}
                      style={{ width: 16, height: 16, accentColor: '#1e56ff' }}
                    />
                    <span>Lot / Batch Tracking</span>
                  </label>
                  <label style={{ display: 'flex', alignItems: 'center', gap: 10, fontSize: 13, cursor: 'pointer' }}>
                    <input
                      type="checkbox"
                      checked={serialTracking}
                      onChange={(e) => setSerialTracking(e.target.checked)}
                      style={{ width: 16, height: 16, accentColor: '#1e56ff' }}
                    />
                    <span>Individual Serial Tracking</span>
                  </label>
                  <label style={{ display: 'flex', alignItems: 'center', gap: 10, fontSize: 13, cursor: 'pointer' }}>
                    <input
                      type="checkbox"
                      checked={expiryTracking}
                      onChange={(e) => setExpiryTracking(e.target.checked)}
                      style={{ width: 16, height: 16, accentColor: '#1e56ff' }}
                    />
                    <span>Expiration Date Enforcement</span>
                  </label>
                  <label style={{ display: 'flex', alignItems: 'center', gap: 10, fontSize: 13, cursor: 'pointer' }}>
                    <input
                      type="checkbox"
                      checked={allowNegativeInventory}
                      onChange={(e) => setAllowNegativeInventory(e.target.checked)}
                      style={{ width: 16, height: 16, accentColor: '#ef4444' }}
                    />
                    <span style={{ color: allowNegativeInventory ? '#ef4444' : '#334155' }}>
                      Allow Negative Stock (Over-dispatch)
                    </span>
                  </label>
                </div>
              </div>
            </div>
          </div>
        )}

        {/* STEP 5: INBOUND & OUTBOUND */}
        {step === 5 && (
          <div>
            <div style={{ marginBottom: 20 }}>
              <h2 style={{ margin: 0, fontSize: 17, fontWeight: 700, color: '#0f172a' }}>
                Step 5: Receiving & Dispatch Operating Rules
              </h2>
              <p style={{ margin: '4px 0 0 0', fontSize: 13, color: '#64748b' }}>
                Define inbound dock procedures, QC gates, picking methods, and packing scan verification.
              </p>
            </div>

            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(280px, 1fr))', gap: 20 }}>
              <div>
                <CustomDropdown
                  label="Inbound Putaway Strategy"
                  value={putawayStrategy}
                  onChange={(val) => setPutawayStrategy(val)}
                  options={[
                    { value: 'direct_to_bin', label: 'Direct to Primary Bins' },
                    { value: 'staging_first', label: 'Inbound Staging Inspection First' },
                    { value: 'dynamic_velocity', label: 'Dynamic SKU Velocity Routing' },
                  ]}
                  size="md"
                />
              </div>

              <div>
                <CustomDropdown
                  label="Outbound Wave & Pick Method"
                  value={outboundPickingStrategy}
                  onChange={(val) => setOutboundPickingStrategy(val)}
                  options={[
                    { value: 'batch', label: 'Batch Picking (Consolidated Items)' },
                    { value: 'single_order', label: 'Discrete Single-Order Picking' },
                    { value: 'zone_wave', label: 'Zone Wave Picking' },
                  ]}
                  size="md"
                />
              </div>

              <div style={{ gridColumn: '1 / -1', borderTop: '1px solid #f1f5f9', paddingTop: 16 }}>
                <h3 style={{ fontSize: 14, fontWeight: 700, margin: '0 0 12px 0', color: '#0f172a' }}>
                  Operational Compliance Toggles
                </h3>
                <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(240px, 1fr))', gap: 14 }}>
                  <label style={{ display: 'flex', alignItems: 'center', gap: 10, fontSize: 13, cursor: 'pointer' }}>
                    <input
                      type="checkbox"
                      checked={poRequired}
                      onChange={(e) => setPoRequired(e.target.checked)}
                      style={{ width: 16, height: 16, accentColor: '#1e56ff' }}
                    />
                    <span>Purchase Order Required on Receiving</span>
                  </label>
                  <label style={{ display: 'flex', alignItems: 'center', gap: 10, fontSize: 13, cursor: 'pointer' }}>
                    <input
                      type="checkbox"
                      checked={blindReceiving}
                      onChange={(e) => setBlindReceiving(e.target.checked)}
                      style={{ width: 16, height: 16, accentColor: '#1e56ff' }}
                    />
                    <span>Allow Blind Unplanned Receiving</span>
                  </label>
                  <label style={{ display: 'flex', alignItems: 'center', gap: 10, fontSize: 13, cursor: 'pointer' }}>
                    <input
                      type="checkbox"
                      checked={qcRequired}
                      onChange={(e) => setQcRequired(e.target.checked)}
                      style={{ width: 16, height: 16, accentColor: '#1e56ff' }}
                    />
                    <span>Mandatory QC Quarantine on Dock</span>
                  </label>
                  <label style={{ display: 'flex', alignItems: 'center', gap: 10, fontSize: 13, cursor: 'pointer' }}>
                    <input
                      type="checkbox"
                      checked={packingScanRequired}
                      onChange={(e) => setPackingScanRequired(e.target.checked)}
                      style={{ width: 16, height: 16, accentColor: '#1e56ff' }}
                    />
                    <span>Barcode Scan Verification at Packing</span>
                  </label>
                  <label style={{ display: 'flex', alignItems: 'center', gap: 10, fontSize: 13, cursor: 'pointer' }}>
                    <input
                      type="checkbox"
                      checked={partialFulfillment}
                      onChange={(e) => setPartialFulfillment(e.target.checked)}
                      style={{ width: 16, height: 16, accentColor: '#1e56ff' }}
                    />
                    <span>Allow Partial Order Shipments</span>
                  </label>
                  <label style={{ display: 'flex', alignItems: 'center', gap: 10, fontSize: 13, cursor: 'pointer' }}>
                    <input
                      type="checkbox"
                      checked={backorderAllowed}
                      onChange={(e) => setBackorderAllowed(e.target.checked)}
                      style={{ width: 16, height: 16, accentColor: '#1e56ff' }}
                    />
                    <span>Permit Automatic Backorder Queue</span>
                  </label>
                </div>
              </div>
            </div>
          </div>
        )}

        {/* STEP 6: REVIEW & COMMIT */}
        {step === 6 && (
          <div>
            <div style={{ marginBottom: 20 }}>
              <h2 style={{ margin: 0, fontSize: 17, fontWeight: 700, color: '#0f172a' }}>
                Step 6: Review Configuration & Readiness
              </h2>
              <p style={{ margin: '4px 0 0 0', fontSize: 13, color: '#64748b' }}>
                Confirm parameters before committing. You can save as draft or finalize immediately.
              </p>
            </div>

            <div
              style={{
                display: 'grid',
                gridTemplateColumns: 'repeat(auto-fit, minmax(280px, 1fr))',
                gap: 16,
                backgroundColor: '#f8fafc',
                padding: 20,
                borderRadius: 12,
                border: '1px solid #e2e8f0',
              }}
            >
              <div>
                <span style={{ fontSize: 11, color: '#64748b', textTransform: 'uppercase', fontWeight: 700 }}>
                  Facility Identity
                </span>
                <div style={{ fontSize: 15, fontWeight: 700, color: '#0f172a', marginTop: 4 }}>
                  {name || 'Untitled Warehouse'}
                </div>
                <div style={{ fontSize: 12, color: '#475569', marginTop: 2 }}>
                  Code: <strong>{code}</strong> &bull; Priority: #{priority} {isDefault && '(Default)'}
                </div>
                <div style={{ fontSize: 12, color: '#475569', marginTop: 2 }}>
                  Type: {type.replace(/_/g, ' ')}
                </div>
              </div>

              <div>
                <span style={{ fontSize: 11, color: '#64748b', textTransform: 'uppercase', fontWeight: 700 }}>
                  Location & Contact
                </span>
                <div style={{ fontSize: 13, fontWeight: 600, color: '#0f172a', marginTop: 4 }}>
                  {city}, {country}
                </div>
                <div style={{ fontSize: 12, color: '#475569', marginTop: 2 }}>
                  Timezone: {timezone}
                </div>
                <div style={{ fontSize: 12, color: '#475569', marginTop: 2 }}>
                  Contact: {contactName || 'Unassigned'} ({contactEmail || 'No email'})
                </div>
              </div>

              <div>
                <span style={{ fontSize: 11, color: '#64748b', textTransform: 'uppercase', fontWeight: 700 }}>
                  Physical Layout
                </span>
                <div style={{ fontSize: 13, fontWeight: 600, color: '#0f172a', marginTop: 4 }}>
                  {generateStarter ? `Zone ${zoneCode} (${totalCalculatedBins} Bins)` : 'Manual Layout'}
                </div>
                <div style={{ fontSize: 12, color: '#475569', marginTop: 2 }}>
                  Picking: {pickingStrategy.toUpperCase()} &bull; Reservation: {reservationPolicy.replace(/_/g, ' ')}
                </div>
              </div>
            </div>

            <div
              style={{
                marginTop: 20,
                padding: 16,
                borderRadius: 12,
                backgroundColor: '#eff6ff',
                border: '1px solid #bfdbfe',
                display: 'flex',
                alignItems: 'center',
                gap: 12,
              }}
            >
              <Building size={20} color="#1e56ff" />
              <div>
                <span style={{ fontSize: 13, fontWeight: 700, color: '#1e40af' }}>
                  Readiness Engine Validation Note
                </span>
                <p style={{ margin: '2px 0 0 0', fontSize: 12, color: '#3b82f6' }}>
                  Saving as Draft allows you to inspect bins, assign staff, and commit opening stock.
                  Activation requires passing all physical layout and staff readiness criteria.
                </p>
              </div>
            </div>
          </div>
        )}

        {/* Wizard Footer Navigation Controls */}
        <div
          style={{
            marginTop: 32,
            paddingTop: 20,
            borderTop: '1px solid #e2e8f0',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'space-between',
            gap: 12,
            flexWrap: 'wrap',
          }}
        >
          <button
            type="button"
            onClick={handleBack}
            disabled={step === 1 || submitting}
            className="btn-finovo-secondary"
            style={{ opacity: step === 1 ? 0.4 : 1, padding: '9px 18px', fontSize: 13 }}
          >
            <ArrowLeft size={15} />
            <span>Previous Step</span>
          </button>

          <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
            <button
              type="button"
              onClick={() => handleSubmit(false)}
              disabled={submitting || !name.trim() || !code.trim()}
              className="btn-finovo-secondary"
              style={{ padding: '9px 18px', fontSize: 13 }}
            >
              {submitting ? <Loader2 className="animate-spin" size={15} /> : <Save size={15} />}
              <span>Save Draft</span>
            </button>

            {step < 6 ? (
              <button
                type="button"
                onClick={handleNext}
                className="btn-finovo-primary"
                style={{ padding: '9px 20px', fontSize: 13 }}
              >
                <span>Continue</span>
                <ArrowRight size={15} />
              </button>
            ) : (
              <button
                type="button"
                onClick={() => handleSubmit(true)}
                disabled={submitting}
                className="btn-finovo-primary"
                style={{
                  padding: '9px 24px',
                  fontSize: 13,
                  backgroundColor: '#059669',
                }}
              >
                {submitting ? (
                  <Loader2 className="animate-spin" size={15} />
                ) : (
                  <CheckCircle2 size={16} />
                )}
                <span>Finalize & Activate Facility</span>
              </button>
            )}
          </div>
        </div>
      </div>
    </div>
  );
};

export default WarehouseCreatePage;
