import { useEffect, useState } from 'react';
import omsService from '../services/omsService';
import type { StoreModel } from '../services/omsService';

/** The company's stores, for forms that pick a store (product and order forms). */
export const useStoreList = (): StoreModel[] => {
  const [stores, setStores] = useState<StoreModel[]>([]);

  useEffect(() => {
    let active = true;
    omsService
      .getStores({ per_page: 100 })
      .then((res) => active && setStores(res.data ?? []))
      .catch(() => active && setStores([]));
    return () => {
      active = false;
    };
  }, []);

  return stores;
};

export default useStoreList;
