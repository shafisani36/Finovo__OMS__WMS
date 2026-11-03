import React from 'react';
import { Loader2 } from 'lucide-react';

/** Shown while a page's code is downloading (pages are loaded the first time they are opened). */
export const PageLoader: React.FC = () => (
  <div style={{ display: 'flex', justifyContent: 'center', padding: '80px 0', color: '#1e56ff' }} role="status" aria-label="Loading page">
    <Loader2 className="animate-spin" size={28} />
  </div>
);

export default PageLoader;
