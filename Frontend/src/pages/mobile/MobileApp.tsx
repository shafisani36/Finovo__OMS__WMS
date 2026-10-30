import React, { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { ArrowLeft, ArrowLeftRight, ClipboardCheck, Download, Layers, PackageCheck, ScanLine, Search, Truck, WifiOff } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import CustomDropdown from '../../components/common/CustomDropdown';
import { Alert } from '../../components/common/Alert';
import omsService from '../../services/omsService';
import type { MobileSummary, WarehouseModel } from '../../services/omsService';
import { useAuth } from '../../hooks/useAuth';
import { useActiveStore } from '../../hooks/useActiveStore';
import { can } from '../../types/auth';
import type { Permission } from '../../types/auth';
import { CountScreen, LookupScreen, MoveScreen, PickScreen, PutAwayScreen, ReceiveScreen } from './MobileScreens';
import type { ScreenProps } from './MobileScreens';
import './Mobile.css';

type ScreenKey = 'pick' | 'receive' | 'putaway' | 'move' | 'count' | 'lookup';

interface Tile {
  key: ScreenKey;
  label: string;
  icon: LucideIcon;
  /** Needed to open the screen. */
  view: Permission;
  /** Needed to do its work (scan, confirm, move). */
  act: Permission;
  badge: (summary: MobileSummary) => number;
  screen: React.FC<ScreenProps>;
}

const TILES: Tile[] = [
  { key: 'pick', label: 'Pick & Pack', icon: PackageCheck, view: 'picking.view', act: 'picking.update', badge: (s) => s.to_pick + s.to_pack, screen: PickScreen },
  { key: 'receive', label: 'Receive', icon: Truck, view: 'inventory.view', act: 'inventory.receive', badge: (s) => s.to_receive, screen: ReceiveScreen },
  { key: 'putaway', label: 'Put Away', icon: Layers, view: 'inventory.view', act: 'inventory.receive', badge: (s) => s.to_put_away, screen: PutAwayScreen },
  { key: 'move', label: 'Move Stock', icon: ArrowLeftRight, view: 'inventory.view', act: 'inventory.transfer', badge: () => 0, screen: MoveScreen },
  { key: 'count', label: 'Count', icon: ClipboardCheck, view: 'inventory.view', act: 'inventory.adjust', badge: (s) => s.to_count, screen: CountScreen },
  { key: 'lookup', label: 'Look Up', icon: Search, view: 'inventory.view', act: 'inventory.view', badge: () => 0, screen: LookupScreen },
];

const WAREHOUSE_KEY = 'finovo.mobile.warehouse';

const storedWarehouse = (): number | null => {
  try {
    return Number(window.localStorage.getItem(WAREHOUSE_KEY)) || null;
  } catch {
    return null;
  }
};

/** The browser's "install app" prompt, kept until the user asks for it. */
interface InstallPrompt extends Event {
  prompt: () => Promise<void>;
}

/**
 * Mobile scanning app for warehouse workers: pick and pack, receive, put away, move, count and look up stock
 * with a phone camera or a Bluetooth scanner. It can be installed on the phone's home screen.
 */
export const MobileApp: React.FC = () => {
  const { user } = useAuth();
  // Like the desktop, the app shows only the work of the store being managed (a warehouse can serve several).
  const { activeStore } = useActiveStore();
  const storeId = activeStore?.id;
  const [warehouses, setWarehouses] = useState<WarehouseModel[]>([]);
  const [warehouseId, setWarehouseId] = useState<number | null>(storedWarehouse);
  const [summary, setSummary] = useState<MobileSummary | null>(null);
  const [screen, setScreen] = useState<ScreenKey | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [online, setOnline] = useState(navigator.onLine);
  const [install, setInstall] = useState<InstallPrompt | null>(null);

  useEffect(() => {
    const update = () => setOnline(navigator.onLine);
    const offerInstall = (event: Event) => {
      event.preventDefault();
      setInstall(event as InstallPrompt);
    };
    window.addEventListener('online', update);
    window.addEventListener('offline', update);
    window.addEventListener('beforeinstallprompt', offerInstall);
    return () => {
      window.removeEventListener('online', update);
      window.removeEventListener('offline', update);
      window.removeEventListener('beforeinstallprompt', offerInstall);
    };
  }, []);

  useEffect(() => {
    omsService
      .getWarehouses({ status: 'active' })
      .then((res) => {
        const list: WarehouseModel[] = res.data ?? [];
        setWarehouses(list);
        setWarehouseId((current) => (current && list.some((w) => w.id === current) ? current : (list[0]?.id ?? null)));
      })
      .catch(() => setError('Your warehouses could not be loaded. Check the connection and open the app again.'));
  }, []);

  useEffect(() => {
    if (!warehouseId) return;
    try {
      window.localStorage.setItem(WAREHOUSE_KEY, String(warehouseId));
    } catch {
      // The choice is only kept for this visit.
    }
  }, [warehouseId]);

  const loadSummary = useCallback(() => {
    if (!warehouseId) return;
    omsService
      .getMobileSummary(warehouseId, storeId)
      .then(setSummary)
      .catch(() => setSummary(null));
  }, [warehouseId, storeId]);

  useEffect(() => {
    if (screen === null) loadSummary();
  }, [screen, loadSummary]);

  const tiles = TILES.filter((tile) => can(user, tile.view));
  const active = tiles.find((tile) => tile.key === screen) ?? null;
  const Screen = active?.screen;

  return (
    <div className="m-app">
      <header className="m-header">
        {active ? (
          <button type="button" className="m-icon-button" onClick={() => setScreen(null)} aria-label="Back to the menu">
            <ArrowLeft size={20} />
          </button>
        ) : (
          <img src="/finovo-icon.svg" alt="Finovo" className="m-logo" />
        )}
        <div className="m-header-title">
          <strong>{active?.label ?? 'Finovo Scanner'}</strong>
          <span>
            {warehouses.find((w) => w.id === warehouseId)?.name ?? 'Choose a warehouse'}
            {activeStore ? ` · ${activeStore.name}` : ''}
          </span>
        </div>
        {!active && (
          <Link to="/dashboard" className="m-header-link">
            Desktop
          </Link>
        )}
      </header>

      {!online && (
        <div className="m-offline">
          <WifiOff size={14} /> No connection. Scans are kept on this phone; send them when the signal is back.
        </div>
      )}

      <main className="m-main">
        {error && <Alert type="error" message={error} />}

        {Screen && warehouseId ? (
          <Screen warehouseId={warehouseId} storeId={storeId} canAct={can(user, active.act)} />
        ) : (
          <div className="m-stack">
            {warehouses.length > 1 && (
              <CustomDropdown
                value={warehouseId ? String(warehouseId) : null}
                onChange={(value) => setWarehouseId(Number(value))}
                options={warehouses.map((w) => ({ value: String(w.id), label: w.name, description: w.code }))}
                searchable={false}
              />
            )}
            <div className="m-tiles">
              {tiles.map((tile) => {
                const Icon = tile.icon;
                const count = summary ? tile.badge(summary) : 0;
                return (
                  <button key={tile.key} type="button" className="m-tile" onClick={() => setScreen(tile.key)} disabled={!warehouseId}>
                    <Icon size={26} />
                    <span>{tile.label}</span>
                    {count > 0 && <em>{count}</em>}
                  </button>
                );
              })}
            </div>
            {install && (
              <button
                type="button"
                className="btn-outline m-primary"
                onClick={async () => {
                  await install.prompt();
                  setInstall(null);
                }}
              >
                <Download size={16} /> Install on this phone
              </button>
            )}
            <p className="m-hint">
              <ScanLine size={14} /> Use the camera button on any scan box, or connect a Bluetooth scanner: it types the code and presses Enter.
            </p>
          </div>
        )}
      </main>
    </div>
  );
};

export default MobileApp;
