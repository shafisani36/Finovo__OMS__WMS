import React, { useState } from 'react';
import { Package } from 'lucide-react';

interface ProductThumbProps {
  src?: string | null;
  size?: number;
  alt?: string;
}

/** Product photo from the store, or a package icon when there is none or it fails to load. */
export const ProductThumb: React.FC<ProductThumbProps> = ({ src, size = 36, alt = '' }) => {
  const [failedSrc, setFailedSrc] = useState<string | null>(null);
  const box: React.CSSProperties = {
    width: size,
    height: size,
    borderRadius: Math.round(size / 4.5),
    flexShrink: 0,
    border: '1px solid #e2e8f0',
    background: '#f8fafc',
  };

  if (!src || failedSrc === src) {
    return (
      <div style={{ ...box, display: 'flex', alignItems: 'center', justifyContent: 'center', color: '#94a3b8' }}>
        <Package size={Math.round(size * 0.45)} />
      </div>
    );
  }

  return <img src={src} alt={alt} loading="lazy" onError={() => setFailedSrc(src)} style={{ ...box, objectFit: 'cover' }} />;
};

export default ProductThumb;
