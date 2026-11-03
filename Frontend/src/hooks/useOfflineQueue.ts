import { useCallback, useEffect, useState } from 'react';
import { OFFLINE_MODULES, OFFLINE_QUEUE_EVENT, queuedScans, scanConflicts, syncOfflineScans } from '../services/offlineQueue';
import type { OfflineModule, ScanConflict } from '../services/offlineQueue';

/** How often waiting scans are retried while the app is open. */
const RETRY_MS = 30_000;

const waitingCounts = () => Object.fromEntries(OFFLINE_MODULES.map((module) => [module, queuedScans(module).length])) as Record<OfflineModule, number>;

/**
 * The offline scan queues on this phone: how many scans wait per module, the conflicts to check, and sending them
 * (on its own when the connection comes back, every 30 seconds, or on request).
 */
export const useOfflineQueue = () => {
  const [waiting, setWaiting] = useState(waitingCounts);
  const [conflicts, setConflicts] = useState<ScanConflict[]>(scanConflicts);
  const [syncing, setSyncing] = useState(false);
  const [lastResult, setLastResult] = useState<{ sent: number; conflicts: number } | null>(null);

  const syncNow = useCallback(async () => {
    if (!navigator.onLine) return;
    setSyncing(true);
    try {
      const result = await syncOfflineScans();
      if (result.sent > 0 || result.conflicts > 0) setLastResult(result);
    } finally {
      setSyncing(false);
    }
  }, []);

  useEffect(() => {
    const refresh = () => {
      setWaiting(waitingCounts());
      setConflicts(scanConflicts());
    };
    window.addEventListener(OFFLINE_QUEUE_EVENT, refresh);
    window.addEventListener('storage', refresh);
    window.addEventListener('online', syncNow);
    const timer = window.setInterval(syncNow, RETRY_MS);
    syncNow();
    return () => {
      window.removeEventListener(OFFLINE_QUEUE_EVENT, refresh);
      window.removeEventListener('storage', refresh);
      window.removeEventListener('online', syncNow);
      window.clearInterval(timer);
    };
  }, [syncNow]);

  const total = Object.values(waiting).reduce((sum, count) => sum + count, 0);

  return { waiting, total, conflicts, syncing, lastResult, syncNow };
};

export default useOfflineQueue;
