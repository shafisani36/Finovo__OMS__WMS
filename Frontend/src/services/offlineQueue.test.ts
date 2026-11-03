import { beforeEach, describe, expect, it, vi } from 'vitest';
import { queuedScans, scanConflicts, submitScan, syncOfflineScans } from './offlineQueue';

const respond = (status: number, body: unknown) => Promise.resolve(new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } }));

describe('offline scan queues', () => {
  let online = true;

  beforeEach(() => {
    localStorage.clear();
    online = true;
    vi.spyOn(navigator, 'onLine', 'get').mockImplementation(() => online);
  });

  it('sends a scan at once when online, with its own scan ID', async () => {
    const fetchMock = vi.spyOn(globalThis, 'fetch').mockImplementation(() => respond(200, { message: 'Received.' }));

    const result = await submitScan('receiving', 'REC-1: 3 units', 'POST', '/inventory/receipts/1/confirm', { items: [] });

    expect(result).toEqual({ queued: false, data: { message: 'Received.' } });
    const headers = fetchMock.mock.calls[0][1]?.headers as Headers;
    expect(headers.get('Idempotency-Key')).toMatch(/^[0-9a-f]{32}$/);
    expect(queuedScans('receiving')).toEqual([]);
  });

  it('keeps scans in their own module queue while offline or when the server cannot be reached', async () => {
    online = false;
    expect(await submitScan('count', 'CYC-1', 'PUT', '/inventory/cycle-counts/1/record-counts', { counts: [] })).toEqual({ queued: true });

    online = true;
    vi.spyOn(globalThis, 'fetch').mockImplementation(() => Promise.reject(new TypeError('Failed to fetch')));
    expect(await submitScan('putaway', '5 x SER-01 into A01', 'POST', '/inventory/receipts/1/putaway', {})).toEqual({ queued: true });

    expect(queuedScans('count')).toHaveLength(1);
    expect(queuedScans('putaway')).toHaveLength(1);
    expect(queuedScans('receiving')).toHaveLength(0);
  });

  it('queues behind earlier scans of the same module so the order is kept', async () => {
    online = false;
    await submitScan('putaway', 'first', 'POST', '/a', {});
    online = true;
    const fetchMock = vi.spyOn(globalThis, 'fetch');

    expect(await submitScan('putaway', 'second', 'POST', '/b', {})).toEqual({ queued: true });
    expect(fetchMock).not.toHaveBeenCalled();
    expect(queuedScans('putaway').map((scan) => scan.label)).toEqual(['first', 'second']);
  });

  it('syncs receiving before put away, and turns a refused scan into a conflict', async () => {
    online = false;
    await submitScan('putaway', 'put away', 'POST', '/inventory/receipts/1/putaway', {});
    await submitScan('receiving', 'receive', 'POST', '/inventory/receipts/1/confirm', {});
    online = true;
    const paths: string[] = [];
    vi.spyOn(globalThis, 'fetch').mockImplementation((url) => {
      paths.push(String(url));
      return String(url).endsWith('/putaway') ? respond(422, { message: 'Receipt has not been received.' }) : respond(200, { message: 'ok' });
    });

    expect(await syncOfflineScans()).toEqual({ sent: 1, conflicts: 1 });
    expect(paths).toEqual(['/api/v1/inventory/receipts/1/confirm', '/api/v1/inventory/receipts/1/putaway']);
    expect(queuedScans('receiving')).toEqual([]);
    expect(queuedScans('putaway')).toEqual([]);
    expect(scanConflicts()).toMatchObject([{ label: 'put away', message: 'Receipt has not been received.' }]);
  });

  it('stops at a connection failure and keeps the rest for the next try', async () => {
    online = false;
    await submitScan('count', 'one', 'PUT', '/c/1', {});
    await submitScan('count', 'two', 'PUT', '/c/2', {});
    online = true;
    vi.spyOn(globalThis, 'fetch').mockImplementation(() => Promise.reject(new TypeError('Failed to fetch')));

    expect(await syncOfflineScans()).toEqual({ sent: 0, conflicts: 0 });
    expect(queuedScans('count')).toHaveLength(2);
    expect(scanConflicts()).toEqual([]);
  });
});
