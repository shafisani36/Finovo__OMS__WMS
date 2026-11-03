import { useCallback, useEffect, useState } from 'react';
import omsService from '../services/omsService';
import type { ShipmentCourier } from '../services/omsService';

/**
 * The company's active courier accounts and the carriers that can be entered by hand (one list, kept on the
 * server). Loads only while `enabled`; `reload` refreshes it after the courier accounts change.
 */
export const useShipmentCouriers = (enabled = true) => {
  const [accounts, setAccounts] = useState<ShipmentCourier[]>([]);
  const [carriers, setCarriers] = useState<string[]>([]);

  const reload = useCallback(async () => {
    try {
      const res = await omsService.getShipmentCouriers();
      setAccounts(res.data);
      setCarriers(res.carriers);
    } catch {
      setAccounts([]);
      setCarriers([]);
    }
  }, []);

  useEffect(() => {
    if (enabled) reload();
  }, [enabled, reload]);

  return { accounts, carriers, reload };
};

export default useShipmentCouriers;
