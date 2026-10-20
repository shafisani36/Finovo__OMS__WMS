import { useContext } from 'react';
import { ActiveStoreContext, type ActiveStoreContextType } from '../context/ActiveStoreContext';

export const useActiveStore = (): ActiveStoreContextType => {
  const context = useContext(ActiveStoreContext);
  if (!context) {
    throw new Error('useActiveStore must be used within an ActiveStoreProvider');
  }
  return context;
};
