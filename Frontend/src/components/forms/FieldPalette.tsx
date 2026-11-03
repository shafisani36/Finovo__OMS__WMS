import React from 'react';
import { Calendar, CheckSquare, ChevronDownSquare, FileUp, Hash, AlignLeft, ScanLine, Search, Type } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { FIELD_TYPES } from './fieldTypes';
import type { FieldTypeKey } from './fieldTypes';
import { NEW_FIELD_TYPE } from './dragData';

const FIELD_ICONS: Record<FieldTypeKey, LucideIcon> = {
  text: Type,
  textarea: AlignLeft,
  number: Hash,
  date: Calendar,
  dropdown: ChevronDownSquare,
  checkbox: CheckSquare,
  lookup: Search,
  barcode: ScanLine,
  file: FileUp,
};

/** Field types to drag onto the canvas (or click to add at the end). */
export const FieldPalette: React.FC<{ onAdd: (type: FieldTypeKey) => void }> = ({ onAdd }) => (
  <aside className="fb-palette" aria-label="Field types">
    <h4 className="fb-panel-title">Fields</h4>
    {Object.values(FIELD_TYPES).map((type) => {
      const Icon = FIELD_ICONS[type.key];
      return (
        <button
          key={type.key}
          type="button"
          className="fb-palette-item"
          draggable
          onDragStart={(e) => {
            e.dataTransfer.setData(NEW_FIELD_TYPE, type.key);
            e.dataTransfer.effectAllowed = 'copy';
          }}
          onClick={() => onAdd(type.key)}
          title={`Add ${type.label}`}
        >
          <Icon size={16} />
          <span>
            <strong>{type.label}</strong>
            <small>{type.description}</small>
          </span>
        </button>
      );
    })}
  </aside>
);

export default FieldPalette;
