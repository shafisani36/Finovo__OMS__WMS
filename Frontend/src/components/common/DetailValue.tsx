import React from 'react';
import { ExternalLink } from 'lucide-react';
import type { PlatformDetail } from '../../services/omsService';
import { formatDate } from '../../utils/format';

const ISO_DATE = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/;

/** A value a store platform gave (product or order facts): lists as chips, links as links, dates in the reader's format. */
export const DetailValue: React.FC<{ value: PlatformDetail }> = ({ value }) => {
  if (Array.isArray(value)) {
    return (
      <span className="pd-chips">
        {value.map((item) => (
          <span key={String(item)} className="pd-chip">
            {String(item)}
          </span>
        ))}
      </span>
    );
  }
  if (typeof value === 'string' && /^https?:\/\//.test(value)) {
    return (
      <a href={value} target="_blank" rel="noopener noreferrer" className="pd-link">
        Open <ExternalLink size={12} />
      </a>
    );
  }
  if (typeof value === 'string' && ISO_DATE.test(value)) return <>{formatDate(value, true)}</>;
  if (typeof value === 'boolean') return <>{value ? 'Yes' : 'No'}</>;
  return <>{value === null || value === '' ? '—' : String(value)}</>;
};

export default DetailValue;
