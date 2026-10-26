import React, { createContext, useCallback, useEffect, useState } from 'react';
import omsService from '../services/omsService';
import type { StoreModel, StorePlatform } from '../services/omsService';
import { useAuth } from '../hooks/useAuth';

export interface ActiveStore {
  id: number;
  name: string;
  platform: StorePlatform;
}

interface ActiveStoreContextType {
  /** The store the user is currently managing (shown in the sidebar). */
  activeStore: ActiveStore | null;
  setActiveStore: (store: StoreModel | ActiveStore) => void;
  /** Re-check the saved store against the server, e.g. after a store was added or removed. */
  refreshActiveStore: () => Promise<void>;
}

const ActiveStoreContext = createContext<ActiveStoreContextType | undefined>(undefined);

const storageKey = (userId: number | string) => `finovo_active_store_${userId}`;

const readSaved = (userId?: number | string): ActiveStore | null => {
  if (userId === undefined) return null;
  try {
    const raw = localStorage.getItem(storageKey(userId));
    return raw ? (JSON.parse(raw) as ActiveStore) : null;
  } catch {
    return null;
  }
};

const save = (userId: number | string | undefined, store: ActiveStore | null) => {
  if (userId === undefined) return;
  try {
    if (store) {
      localStorage.setItem(storageKey(userId), JSON.stringify(store));
    } else {
      localStorage.removeItem(storageKey(userId));
    }
  } catch {
    // Storage unavailable (private mode); the selection lasts for this session only.
  }
};

const toActiveStore = (store: StoreModel | ActiveStore): ActiveStore => ({
  id: store.id,
  name: store.name,
  platform: store.platform,
});

export const ActiveStoreProvider: React.FC<{ children: React.ReactNode }> = ({ children }) => {
  const { user } = useAuth();
  const userId = user?.id;
  const [activeStore, setActiveStoreState] = useState<ActiveStore | null>(() => readSaved(userId));

  const setActiveStore = useCallback(
    (store: StoreModel | ActiveStore) => {
      const next = toActiveStore(store);
      setActiveStoreState(next);
      save(userId, next);
    },
    [userId]
  );

  const refreshActiveStore = useCallback(async () => {
    try {
      // Only a connected store can be managed; a store still awaiting authorization has no data yet.
      const res = await omsService.getStores({ per_page: 100, status: 'connected' });
      const stores: StoreModel[] = res.data ?? [];
      const saved = readSaved(userId);
      const match = saved ? stores.find((s) => s.id === saved.id) : undefined;
      const next = match ? toActiveStore(match) : stores[0] ? toActiveStore(stores[0]) : null;
      setActiveStoreState(next);
      save(userId, next);
    } catch {
      // Keep the current selection if the store list cannot be loaded.
    }
  }, [userId]);

  useEffect(() => {
    setActiveStoreState(readSaved(userId));
    refreshActiveStore();
  }, [userId, refreshActiveStore]);

  return (
    <ActiveStoreContext.Provider value={{ activeStore, setActiveStore, refreshActiveStore }}>
      {children}
    </ActiveStoreContext.Provider>
  );
};

export { ActiveStoreContext, type ActiveStoreContextType };
