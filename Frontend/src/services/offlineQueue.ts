import { ApiError, request } from './apiClient';

/**
 * Offline scanning: work done on the phone without a connection is kept in one queue per module, in the order it
 * was done, and sent when the connection is back. Every scan carries its own ID (Idempotency-Key), so a scan sent
 * twice is applied once. A scan the server turns down (the stock or document changed meanwhile) becomes a conflict
 * for the worker to check.
 */

/** Modules with their own queue, in the order they are sent (a delivery is received before it is put away). */
export const OFFLINE_MODULES = ['receiving', 'putaway', 'count'] as const;
export type OfflineModule = (typeof OFFLINE_MODULES)[number];

export const OFFLINE_MODULE_LABELS: Record<OfflineModule, string> = {
  receiving: 'Receiving',
  putaway: 'Put away',
  count: 'Counts',
};

export interface QueuedScan {
  id: string;
  module: OfflineModule;
  /** What was done, for the worker (e.g. "5 × SER-01 into A01-B03"). */
  label: string;
  method: 'POST' | 'PUT';
  path: string;
  body: unknown;
  queuedAt: string;
}

export interface ScanConflict extends QueuedScan {
  message: string;
}

/** Fired whenever a queue or the conflict list changes. */
export const OFFLINE_QUEUE_EVENT = 'finovo:offline-queue';

const queueKey = (module: OfflineModule) => `finovo.offline.${module}`;
const CONFLICTS_KEY = 'finovo.offline.conflicts';

const read = <T>(key: string): T[] => {
  try {
    return JSON.parse(localStorage.getItem(key) ?? '[]') as T[];
  } catch {
    return [];
  }
};

const write = (key: string, items: unknown[]) => {
  try {
    localStorage.setItem(key, JSON.stringify(items));
  } catch {
    // Storage full or blocked: the scan stays in memory for this page only.
  }
  window.dispatchEvent(new Event(OFFLINE_QUEUE_EVENT));
};

export const queuedScans = (module: OfflineModule): QueuedScan[] => read<QueuedScan>(queueKey(module));

export const scanConflicts = (): ScanConflict[] => read<ScanConflict>(CONFLICTS_KEY);

export const dismissConflict = (id: string) => write(CONFLICTS_KEY, scanConflicts().filter((conflict) => conflict.id !== id));

/** A random scan ID (works on plain http too, unlike crypto.randomUUID). */
const newScanId = () => Array.from(crypto.getRandomValues(new Uint8Array(16)), (byte) => byte.toString(16).padStart(2, '0')).join('');

const send = async <T>(scan: QueuedScan) =>
  request<T>(scan.path, { method: scan.method, body: JSON.stringify(scan.body), headers: { 'Idempotency-Key': scan.id } });

/** No connection, or the server is unreachable. */
export const isOffline = (error: unknown) => error instanceof ApiError && error.status === 0;

export type ScanResult<T> = { queued: false; data: T } | { queued: true };

/**
 * Sends a scan now, or keeps it in its module's queue when the phone is offline. Scans queue behind the ones
 * already waiting in that module, so they reach the server in the order they were done.
 */
export const submitScan = async <T = { message?: string }>(module: OfflineModule, label: string, method: QueuedScan['method'], path: string, body: unknown): Promise<ScanResult<T>> => {
  const scan: QueuedScan = { id: newScanId(), module, label, method, path, body, queuedAt: new Date().toISOString() };
  const keep = () => {
    write(queueKey(module), [...queuedScans(module), scan]);
    return { queued: true } as const;
  };

  if (!navigator.onLine || queuedScans(module).length > 0) return keep();

  try {
    return { queued: false, data: (await send<T>(scan)) as T };
  } catch (error) {
    if (isOffline(error)) return keep();
    throw error;
  }
};

let syncing: Promise<{ sent: number; conflicts: number }> | null = null;

const sendWaitingScans = async (): Promise<{ sent: number; conflicts: number }> => {
  let sent = 0;
  let conflicts = 0;
  for (const module of OFFLINE_MODULES) {
    for (const scan of queuedScans(module)) {
      try {
        await send(scan);
        sent++;
      } catch (error) {
        if (isOffline(error) || (error instanceof ApiError && [401, 429].includes(error.status))) {
          return { sent, conflicts };
        }
        write(CONFLICTS_KEY, [...scanConflicts(), { ...scan, message: error instanceof Error ? error.message : 'The server turned this scan down.' }]);
        conflicts++;
      }
      write(queueKey(module), queuedScans(module).filter((queued) => queued.id !== scan.id));
    }
  }
  return { sent, conflicts };
};

/**
 * Sends every waiting scan, module by module, oldest first. Stops at the first connection failure (the rest waits
 * for the next try) and when the session has ended (they are sent after signing in again). Only one run at a time.
 */
export const syncOfflineScans = (): Promise<{ sent: number; conflicts: number }> => {
  syncing ??= sendWaitingScans().finally(() => {
    syncing = null;
  });
  return syncing;
};
