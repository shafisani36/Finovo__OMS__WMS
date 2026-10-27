import React, { useEffect, useLayoutEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { MoreVertical } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';

export interface RowAction {
  label: string;
  icon: LucideIcon;
  onSelect: () => void;
  danger?: boolean;
}

const MENU_WIDTH = 190;
const GAP = 6;

/**
 * Three-dot button that opens a row's actions. The list is drawn on the page body, so a scrolling table never
 * cuts it off; it opens upwards near the bottom of the window and closes on Escape, scroll or an outside click.
 */
export const RowActionsMenu: React.FC<{ actions: RowAction[]; label?: string }> = ({ actions, label = 'Actions' }) => {
  const [open, setOpen] = useState(false);
  const [position, setPosition] = useState<{ top: number; left: number } | null>(null);
  const buttonRef = useRef<HTMLButtonElement>(null);
  const menuRef = useRef<HTMLDivElement>(null);

  useLayoutEffect(() => {
    if (!open || !buttonRef.current || !menuRef.current) return;
    const button = buttonRef.current.getBoundingClientRect();
    const height = menuRef.current.offsetHeight;
    const below = button.bottom + GAP + height <= window.innerHeight;
    setPosition({
      top: below ? button.bottom + GAP : Math.max(GAP, button.top - GAP - height),
      left: Math.max(GAP, Math.min(button.right - MENU_WIDTH, window.innerWidth - MENU_WIDTH - GAP)),
    });
  }, [open]);

  useEffect(() => {
    if (!open) return;
    const close = () => setOpen(false);
    const onPointerDown = (event: MouseEvent) => {
      const target = event.target as Node;
      if (!menuRef.current?.contains(target) && !buttonRef.current?.contains(target)) close();
    };
    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        close();
        buttonRef.current?.focus();
      }
    };
    document.addEventListener('mousedown', onPointerDown);
    document.addEventListener('keydown', onKeyDown);
    window.addEventListener('scroll', close, true);
    window.addEventListener('resize', close);
    return () => {
      document.removeEventListener('mousedown', onPointerDown);
      document.removeEventListener('keydown', onKeyDown);
      window.removeEventListener('scroll', close, true);
      window.removeEventListener('resize', close);
    };
  }, [open]);

  if (actions.length === 0) return null;

  const toggle = (event: React.MouseEvent) => {
    event.stopPropagation();
    setPosition(null);
    setOpen((value) => !value);
  };

  return (
    <>
      <button
        ref={buttonRef}
        type="button"
        className="row-actions-trigger"
        aria-label={label}
        aria-haspopup="menu"
        aria-expanded={open}
        onClick={toggle}
      >
        <MoreVertical size={16} />
      </button>
      {open &&
        createPortal(
          <div
            ref={menuRef}
            role="menu"
            className="row-actions-menu"
            style={{ width: MENU_WIDTH, top: position?.top ?? 0, left: position?.left ?? 0, visibility: position ? 'visible' : 'hidden' }}
            onClick={(event) => event.stopPropagation()}
          >
            {actions.map(({ label: actionLabel, icon: Icon, onSelect, danger }) => (
              <button
                key={actionLabel}
                type="button"
                role="menuitem"
                className={`row-actions-item${danger ? ' is-danger' : ''}`}
                onClick={() => {
                  setOpen(false);
                  onSelect();
                }}
              >
                <Icon size={15} />
                {actionLabel}
              </button>
            ))}
          </div>,
          document.body,
        )}
    </>
  );
};

export default RowActionsMenu;
