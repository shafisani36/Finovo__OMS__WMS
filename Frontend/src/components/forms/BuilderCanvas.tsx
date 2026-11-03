import React, { useState } from 'react';
import { ArrowDown, ArrowUp, Copy, GripVertical, Trash2 } from 'lucide-react';
import FormFieldInput from './FormFieldInput';
import { FIELD_TYPES } from './fieldTypes';
import type { FieldTypeKey, FormField } from './fieldTypes';
import type { BuilderAction } from './builderReducer';
import { MOVED_FIELD, NEW_FIELD_TYPE } from './dragData';

interface BuilderCanvasProps {
  fields: FormField[];
  selectedId: string | null;
  dispatch: (action: BuilderAction) => void;
}

const isFieldType = (value: string): value is FieldTypeKey => value in FIELD_TYPES;

/**
 * The form as it will look. Fields from the palette are dropped between fields; fields on the canvas are dragged
 * to a new place (or moved with the arrow buttons from the keyboard).
 */
export const BuilderCanvas: React.FC<BuilderCanvasProps> = ({ fields, selectedId, dispatch }) => {
  const [dropIndex, setDropIndex] = useState<number | null>(null);

  const accepts = (e: React.DragEvent) => e.dataTransfer.types.includes(NEW_FIELD_TYPE) || e.dataTransfer.types.includes(MOVED_FIELD);

  /** The gap the pointer is nearest to: before the field when over its top half, after it otherwise. */
  const overField = (e: React.DragEvent<HTMLDivElement>, index: number) => {
    if (!accepts(e)) return;
    e.preventDefault();
    const box = e.currentTarget.getBoundingClientRect();
    setDropIndex(e.clientY < box.top + box.height / 2 ? index : index + 1);
  };

  const drop = (e: React.DragEvent) => {
    e.preventDefault();
    const index = dropIndex ?? fields.length;
    setDropIndex(null);
    const type = e.dataTransfer.getData(NEW_FIELD_TYPE);
    const moved = e.dataTransfer.getData(MOVED_FIELD);
    if (type && isFieldType(type)) dispatch({ type: 'added', fieldType: type, index });
    else if (moved) dispatch({ type: 'moved', id: moved, index });
  };

  return (
    <div
      className={`fb-canvas${dropIndex !== null ? ' is-dragging' : ''}`}
      onDragOver={(e) => {
        if (!accepts(e)) return;
        e.preventDefault();
        if (fields.length === 0) setDropIndex(0);
      }}
      onDragLeave={(e) => {
        if (!e.currentTarget.contains(e.relatedTarget as Node | null)) setDropIndex(null);
      }}
      onDrop={drop}
      onClick={() => dispatch({ type: 'selected', id: null })}
    >
      {fields.length === 0 && <div className="fb-canvas-empty">Drag fields here, or click a field type to add it.</div>}
      {fields.map((field, index) => (
        <React.Fragment key={field.id}>
          {dropIndex === index && <div className="fb-drop-line" />}
          <div
            className={`fb-canvas-field fb-width-${field.width ?? 'full'}${selectedId === field.id ? ' is-selected' : ''}`}
            draggable
            onDragStart={(e) => {
              e.dataTransfer.setData(MOVED_FIELD, field.id);
              e.dataTransfer.effectAllowed = 'move';
            }}
            onDragOver={(e) => overField(e, index)}
            onClick={(e) => {
              e.stopPropagation();
              dispatch({ type: 'selected', id: field.id });
            }}
          >
            <div className="fb-canvas-toolbar" onClick={(e) => e.stopPropagation()}>
              <GripVertical size={14} className="fb-grip" aria-hidden />
              <button type="button" aria-label={`Move ${field.label} up`} disabled={index === 0} onClick={() => dispatch({ type: 'moved', id: field.id, index: index - 1 })}>
                <ArrowUp size={13} />
              </button>
              <button type="button" aria-label={`Move ${field.label} down`} disabled={index === fields.length - 1} onClick={() => dispatch({ type: 'moved', id: field.id, index: index + 2 })}>
                <ArrowDown size={13} />
              </button>
              <button type="button" aria-label={`Duplicate ${field.label}`} onClick={() => dispatch({ type: 'duplicated', id: field.id })}>
                <Copy size={13} />
              </button>
              <button type="button" aria-label={`Remove ${field.label}`} onClick={() => dispatch({ type: 'removed', id: field.id })}>
                <Trash2 size={13} />
              </button>
            </div>
            <FormFieldInput field={field} value={null} preview />
          </div>
        </React.Fragment>
      ))}
      {dropIndex === fields.length && fields.length > 0 && <div className="fb-drop-line" />}
    </div>
  );
};

export default BuilderCanvas;
