import { useCallback, useEffect, useState } from 'react';
import omsService from '../services/omsService';
import type { CustomOrderStatus } from '../services/omsService';

/** The company's own order statuses, with a reload after they are edited. */
export const useCustomStatuses = () => {
  const [statuses, setStatuses] = useState<CustomOrderStatus[]>([]);

  const reload = useCallback(() => {
    omsService
      .getCustomStatuses()
      .then(setStatuses)
      .catch(() => setStatuses([]));
  }, []);

  useEffect(() => {
    reload();
  }, [reload]);

  return { statuses, reload };
};

export default useCustomStatuses;
