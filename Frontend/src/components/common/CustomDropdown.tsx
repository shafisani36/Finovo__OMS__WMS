import React, {
  useState,
  useRef,
  useEffect,
  useCallback,
  useMemo,
  useId,
} from 'react';
import {
  ChevronDown,
  Check,
  Search,
  X,
  AlertCircle,
} from 'lucide-react';

export interface DropdownOption<T = any> {
  value: T;
  label: string;
  description?: string;
  icon?: React.ReactNode;
  badge?: string;
  badgeColor?: string;
  disabled?: boolean;
  group?: string;
}

export type RawOption<T = any> =
  | DropdownOption<T>
  | string
  | number
  | { value: T; label: string; [key: string]: any };

export interface CustomDropdownProps<T = any> {
  options: RawOption<T>[];
  value?: T | T[] | null;
  onChange?: (val: T, option?: DropdownOption<T>) => void;
  onMultiChange?: (vals: T[], options?: DropdownOption<T>[]) => void;
  placeholder?: string;
  label?: string;
  error?: string;
  helperText?: string;
  disabled?: boolean;
  clearable?: boolean;
  searchable?: boolean;
  searchPlaceholder?: string;
  multiple?: boolean;
  size?: 'sm' | 'md' | 'lg';
  icon?: React.ReactNode;
  fullWidth?: boolean;
  placement?: 'auto' | 'bottom' | 'top';
  maxHeight?: number | string;
  className?: string;
  style?: React.CSSProperties;
  id?: string;
  name?: string;
  required?: boolean;
  emptyText?: string;
  renderOption?: (option: DropdownOption<T>, isSelected: boolean) => React.ReactNode;
  zIndex?: number;
  menuZIndex?: number;
  menuStyle?: React.CSSProperties;
}

/**
 * Safely renders an icon regardless of whether it's a JSX Element, LucideIcon forwardRef, or function.
 */
function renderSafeIcon(iconItem: any, size = 15): React.ReactNode {
  if (!iconItem) return null;
  if (React.isValidElement(iconItem)) return iconItem;
  if (
    typeof iconItem === 'function' ||
    (typeof iconItem === 'object' && iconItem !== null && ('render' in iconItem || '$$typeof' in iconItem))
  ) {
    const IconComp = iconItem;
    return <IconComp size={size} />;
  }
  return null;
}

/**
 * Normalizes raw option types into standard DropdownOption structure.
 */
function normalizeOption<T = any>(opt: RawOption<T>): DropdownOption<T> {
  if (opt === null || opt === undefined) {
    return {
      value: '' as unknown as T,
      label: '',
    };
  }
  if (typeof opt === 'string' || typeof opt === 'number') {
    return {
      value: opt as unknown as T,
      label: String(opt),
    };
  }
  return {
    value: opt.value,
    label: opt.label !== undefined ? String(opt.label) : String(opt.value),
    description: (opt as any).description,
    icon: (opt as any).icon,
    badge: (opt as any).badge,
    badgeColor: (opt as any).badgeColor,
    disabled: (opt as any).disabled,
    group: (opt as any).group,
  };
}

export const CustomDropdown = <T extends any = any>({
  options: rawOptions,
  value,
  onChange,
  onMultiChange,
  placeholder = 'Select...',
  label,
  error,
  helperText,
  disabled = false,
  clearable = false,
  searchable,
  searchPlaceholder = 'Search...',
  multiple = false,
  size = 'md',
  icon,
  fullWidth = true,
  placement = 'auto',
  maxHeight = 260,
  className = '',
  style,
  id: propId,
  name,
  required = false,
  emptyText = 'No options available',
  renderOption,
  zIndex = 99999,
  menuZIndex = 100000,
  menuStyle,
}: CustomDropdownProps<T>) => {
  const generatedId = useId();
  const dropdownId = propId || `custom-dropdown-${generatedId}`;

  const [isOpen, setIsOpen] = useState(false);
  const [searchTerm, setSearchTerm] = useState('');
  const [highlightedIndex, setHighlightedIndex] = useState<number>(-1);
  const [computedPlacement, setComputedPlacement] = useState<'bottom' | 'top'>('bottom');

  const containerRef = useRef<HTMLDivElement>(null);
  const triggerRef = useRef<HTMLButtonElement>(null);
  const menuRef = useRef<HTMLDivElement>(null);
  const searchInputRef = useRef<HTMLInputElement>(null);

  // Normalize options
  const options = useMemo(() => {
    return rawOptions.map(normalizeOption);
  }, [rawOptions]);

  // Determine if search should be enabled
  const isSearchable = searchable ?? options.length > 7;

  // Selected state checking
  const isValueSelected = useCallback(
    (val: T): boolean => {
      if (multiple && Array.isArray(value)) {
        return value.some((v) => String(v) === String(val));
      }
      return value !== undefined && value !== null && String(value) === String(val);
    },
    [multiple, value]
  );

  // Filtered options based on search query
  const filteredOptions = useMemo(() => {
    if (!searchTerm.trim()) return options;
    const query = searchTerm.toLowerCase();
    return options.filter((opt) => {
      const matchLabel = opt.label.toLowerCase().includes(query);
      const matchDesc = opt.description?.toLowerCase().includes(query);
      const matchVal = String(opt.value).toLowerCase().includes(query);
      return matchLabel || matchDesc || matchVal;
    });
  }, [options, searchTerm]);

  // Selected option(s) for trigger display
  const selectedOptions = useMemo(() => {
    if (multiple && Array.isArray(value)) {
      return options.filter((opt) => value.some((v) => String(v) === String(opt.value)));
    }
    return options.filter((opt) => value !== undefined && value !== null && String(value) === String(opt.value));
  }, [options, value, multiple]);

  // Calculate viewport boundaries and collision detection
  const calculatePosition = useCallback(() => {
    if (!containerRef.current) return;
    if (placement !== 'auto') {
      setComputedPlacement(placement);
      return;
    }
    const rect = containerRef.current.getBoundingClientRect();
    const dropdownHeight = typeof maxHeight === 'number' ? maxHeight : 260;
    let spaceBelow = window.innerHeight - rect.bottom;
    let spaceAbove = rect.top;

    // Check if within a scrollable or constrained parent container (such as a modal body)
    let parent = containerRef.current.parentElement;
    while (parent && parent !== document.body && parent !== document.documentElement) {
      const style = window.getComputedStyle(parent);
      const isScrollable =
        ['auto', 'scroll', 'hidden'].includes(style.overflowY) ||
        ['auto', 'scroll', 'hidden'].includes(style.overflow);
      if (isScrollable) {
        const parentRect = parent.getBoundingClientRect();
        const parentSpaceBelow = parentRect.bottom - rect.bottom;
        const parentSpaceAbove = rect.top - parentRect.top;
        if (parentSpaceBelow < spaceBelow) spaceBelow = parentSpaceBelow;
        if (parentSpaceAbove < spaceAbove) spaceAbove = parentSpaceAbove;
        break;
      }
      parent = parent.parentElement;
    }

    // Smart placement: if space below is too small and top has more room, flip upward
    if (spaceBelow < dropdownHeight && spaceAbove > spaceBelow) {
      setComputedPlacement('top');
    } else {
      setComputedPlacement('bottom');
    }
  }, [placement, maxHeight]);

  // Dynamic reposition on scroll or resize when open
  useEffect(() => {
    if (!isOpen) return;
    const handleReposition = () => {
      calculatePosition();
    };
    window.addEventListener('resize', handleReposition, { passive: true });
    window.addEventListener('scroll', handleReposition, { passive: true, capture: true });
    return () => {
      window.removeEventListener('resize', handleReposition);
      window.removeEventListener('scroll', handleReposition, { capture: true });
    };
  }, [isOpen, calculatePosition]);

  // Toggle open
  const toggleDropdown = () => {
    if (disabled) return;
    if (!isOpen) {
      calculatePosition();
      setIsOpen(true);
      setSearchTerm('');
      setHighlightedIndex(-1);
    } else {
      setIsOpen(false);
    }
  };

  // Close on outside click
  useEffect(() => {
    const handleOutsideClick = (e: MouseEvent) => {
      if (containerRef.current && !containerRef.current.contains(e.target as Node)) {
        setIsOpen(false);
      }
    };

    if (isOpen) {
      document.addEventListener('mousedown', handleOutsideClick, true);
    }
    return () => {
      document.removeEventListener('mousedown', handleOutsideClick, true);
    };
  }, [isOpen]);

  // Auto focus search input when opened
  useEffect(() => {
    if (isOpen && isSearchable && searchInputRef.current) {
      searchInputRef.current.focus();
    }
  }, [isOpen, isSearchable]);

  // Handle single option click
  const handleSelect = (option: DropdownOption<T>, e?: React.MouseEvent) => {
    if (e) {
      e.preventDefault();
      e.stopPropagation();
    }
    if (option.disabled) return;

    if (multiple) {
      const currentValues = Array.isArray(value) ? [...value] : [];
      const exists = currentValues.some((v) => String(v) === String(option.value));
      const nextValues = exists
        ? currentValues.filter((v) => String(v) !== String(option.value))
        : [...currentValues, option.value];

      if (onMultiChange) {
        const nextOptions = options.filter((opt) =>
          nextValues.some((v) => String(v) === String(opt.value))
        );
        onMultiChange(nextValues, nextOptions);
      }
    } else {
      if (onChange) {
        onChange(option.value, option);
      }
      setIsOpen(false);
      triggerRef.current?.focus();
    }
  };

  // Clear value handler
  const handleClear = (e: React.MouseEvent) => {
    e.stopPropagation();
    if (disabled) return;
    if (multiple) {
      if (onMultiChange) onMultiChange([]);
    } else {
      if (onChange) onChange(null as unknown as T);
    }
  };

  // Select all (for multi-select)
  const handleSelectAll = (e: React.MouseEvent) => {
    e.stopPropagation();
    if (disabled || !multiple) return;
    const allNonDisabled = filteredOptions.filter((o) => !o.disabled).map((o) => o.value);
    if (onMultiChange) {
      onMultiChange(allNonDisabled, filteredOptions.filter((o) => !o.disabled));
    }
  };

  // Keyboard navigation
  const handleKeyDown = (e: React.KeyboardEvent) => {
    if (disabled) return;

    if (!isOpen) {
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp' || e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        toggleDropdown();
      }
      return;
    }

    switch (e.key) {
      case 'Escape':
        e.preventDefault();
        setIsOpen(false);
        triggerRef.current?.focus();
        break;

      case 'ArrowDown':
        e.preventDefault();
        setHighlightedIndex((prev) => {
          const next = prev < filteredOptions.length - 1 ? prev + 1 : 0;
          return next;
        });
        break;

      case 'ArrowUp':
        e.preventDefault();
        setHighlightedIndex((prev) => {
          const next = prev > 0 ? prev - 1 : filteredOptions.length - 1;
          return next;
        });
        break;

      case 'Enter':
        e.preventDefault();
        if (highlightedIndex >= 0 && highlightedIndex < filteredOptions.length) {
          handleSelect(filteredOptions[highlightedIndex]);
        }
        break;

      case 'Tab':
        setIsOpen(false);
        break;
    }
  };

  // Sizing tokens
  const sizeStyles = {
    sm: {
      padding: '5px 10px',
      fontSize: '12px',
      borderRadius: '8px',
      minHeight: '32px',
      iconSize: 13,
    },
    md: {
      padding: '8px 12px',
      fontSize: '13px',
      borderRadius: '10px',
      minHeight: '38px',
      iconSize: 15,
    },
    lg: {
      padding: '11px 15px',
      fontSize: '14px',
      borderRadius: '12px',
      minHeight: '44px',
      iconSize: 17,
    },
  }[size];

  // Grouping options
  const hasGroups = filteredOptions.some((opt) => Boolean(opt.group));
  const groupedOptions = useMemo(() => {
    if (!hasGroups) return { '': filteredOptions };
    const groups: Record<string, DropdownOption<T>[]> = {};
    filteredOptions.forEach((opt) => {
      const g = opt.group || '';
      if (!groups[g]) groups[g] = [];
      groups[g].push(opt);
    });
    return groups;
  }, [filteredOptions, hasGroups]);

  return (
    <div
      ref={containerRef}
      className={`custom-dropdown-container ${isOpen ? 'is-open' : ''} ${className}`}
      style={{
        position: 'relative',
        display: fullWidth ? 'flex' : 'inline-flex',
        flexDirection: 'column',
        width: fullWidth ? '100%' : 'auto',
        boxSizing: 'border-box',
        zIndex: isOpen ? zIndex : (style?.zIndex ?? undefined),
        ...style,
        ...(isOpen ? { zIndex } : {}),
      }}
      onKeyDown={handleKeyDown}
    >
      {/* Label */}
      {label && (
        <label
          htmlFor={dropdownId}
          style={{
            display: 'block',
            fontSize: size === 'sm' ? 11 : 13,
            fontWeight: 600,
            color: '#334155',
            marginBottom: 6,
          }}
        >
          {label}
          {required && <span style={{ color: '#ef4444', marginLeft: 4 }}>*</span>}
        </label>
      )}

      {/* Trigger Button */}
      <button
        id={dropdownId}
        ref={triggerRef}
        type="button"
        disabled={disabled}
        onClick={toggleDropdown}
        aria-haspopup="listbox"
        aria-expanded={isOpen}
        aria-controls={`${dropdownId}-menu`}
        aria-invalid={Boolean(error)}
        style={{
          display: 'flex',
          alignItems: 'center',
          justifyContent: 'space-between',
          gap: 8,
          width: '100%',
          backgroundColor: disabled ? '#f8fafc' : '#ffffff',
          color: disabled ? '#94a3b8' : '#0f172a',
          border: `1px solid ${error ? '#ef4444' : isOpen ? '#1e56ff' : '#cbd5e1'}`,
          borderRadius: sizeStyles.borderRadius,
          padding: sizeStyles.padding,
          minHeight: sizeStyles.minHeight,
          fontSize: sizeStyles.fontSize,
          boxShadow: isOpen
            ? '0 0 0 3px rgba(30, 86, 255, 0.15)'
            : '0 1px 2px rgba(0, 0, 0, 0.03)',
          cursor: disabled ? 'not-allowed' : 'pointer',
          outline: 'none',
          transition: 'all 0.15s ease',
          boxSizing: 'border-box',
          textAlign: 'left',
        }}
      >
        {/* Leading Icon & Value Display */}
        <div
          style={{
            display: 'flex',
            alignItems: 'center',
            gap: 8,
            overflow: 'hidden',
            flex: 1,
          }}
        >
          {renderSafeIcon(icon, sizeStyles.iconSize) && (
            <span style={{ color: '#64748b', display: 'flex', flexShrink: 0 }}>
              {renderSafeIcon(icon, sizeStyles.iconSize)}
            </span>
          )}

          {/* Trigger Text / Badges */}
          {multiple ? (
            selectedOptions.length === 0 ? (
              <span style={{ color: '#94a3b8', whiteSpace: 'nowrap' }}>{placeholder}</span>
            ) : (
              <div style={{ display: 'flex', flexWrap: 'wrap', gap: 4, alignItems: 'center' }}>
                {selectedOptions.length <= 2 ? (
                  selectedOptions.map((opt) => (
                    <span
                      key={String(opt.value)}
                      style={{
                        display: 'inline-flex',
                        alignItems: 'center',
                        gap: 4,
                        padding: '1px 6px',
                        borderRadius: 6,
                        backgroundColor: '#eff6ff',
                        color: '#1e56ff',
                        fontSize: 11,
                        fontWeight: 600,
                      }}
                    >
                      {opt.label}
                      <span
                        onClick={(e) => handleSelect(opt, e)}
                        style={{ cursor: 'pointer', display: 'inline-flex' }}
                      >
                        <X size={10} />
                      </span>
                    </span>
                  ))
                ) : (
                  <span
                    style={{
                      display: 'inline-flex',
                      alignItems: 'center',
                      gap: 4,
                      padding: '2px 8px',
                      borderRadius: 6,
                      backgroundColor: '#eff6ff',
                      color: '#1e56ff',
                      fontSize: 11,
                      fontWeight: 700,
                    }}
                  >
                    {selectedOptions.length} Selected
                  </span>
                )}
              </div>
            )
          ) : selectedOptions.length > 0 ? (
            <div style={{ display: 'flex', alignItems: 'center', gap: 6, overflow: 'hidden' }}>
              {renderSafeIcon(selectedOptions[0].icon, sizeStyles.iconSize) && (
                <span style={{ display: 'flex', flexShrink: 0 }}>
                  {renderSafeIcon(selectedOptions[0].icon, sizeStyles.iconSize)}
                </span>
              )}
              <span
                style={{
                  fontWeight: 500,
                  whiteSpace: 'nowrap',
                  textOverflow: 'ellipsis',
                  overflow: 'hidden',
                }}
              >
                {selectedOptions[0].label}
              </span>
              {selectedOptions[0].badge && (
                <span
                  style={{
                    fontSize: 10,
                    fontWeight: 700,
                    padding: '1px 6px',
                    borderRadius: 4,
                    backgroundColor: selectedOptions[0].badgeColor || '#eff6ff',
                    color: selectedOptions[0].badgeColor ? '#ffffff' : '#1e56ff',
                  }}
                >
                  {selectedOptions[0].badge}
                </span>
              )}
            </div>
          ) : (
            <span style={{ color: '#94a3b8', whiteSpace: 'nowrap' }}>{placeholder}</span>
          )}
        </div>

        {/* Action Controls (Clear + Arrow) */}
        <div style={{ display: 'flex', alignItems: 'center', gap: 4, flexShrink: 0 }}>
          {clearable && selectedOptions.length > 0 && !disabled && (
            <span
              onClick={handleClear}
              style={{
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
                padding: 2,
                borderRadius: '50%',
                color: '#94a3b8',
                cursor: 'pointer',
              }}
              title="Clear selection"
            >
              <X size={13} />
            </span>
          )}

          <ChevronDown
            size={sizeStyles.iconSize}
            style={{
              color: '#64748b',
              transform: isOpen ? 'rotate(180deg)' : 'rotate(0deg)',
              transition: 'transform 0.2s ease',
            }}
          />
        </div>
      </button>

      {/* Floating Menu Popover */}
      {isOpen && (
        <div
          id={`${dropdownId}-menu`}
          ref={menuRef}
          role="listbox"
          aria-multiselectable={multiple}
          className="custom-dropdown-menu"
          style={{
            position: 'absolute',
            left: 0,
            right: 0,
            [computedPlacement === 'top' ? 'bottom' : 'top']: 'calc(100% + 4px)',
            backgroundColor: '#ffffff',
            border: '1px solid #cbd5e1',
            borderRadius: 12,
            boxShadow: '0 16px 36px -4px rgba(15, 23, 42, 0.16), 0 6px 14px -2px rgba(15, 23, 42, 0.08)',
            zIndex: menuZIndex,
            overflow: 'hidden',
            display: 'flex',
            flexDirection: 'column',
            maxHeight,
            boxSizing: 'border-box',
            ...menuStyle,
          }}
        >
          {/* Search Box Header */}
          {isSearchable && (
            <div
              style={{
                padding: '8px 10px',
                borderBottom: '1px solid #f1f5f9',
                backgroundColor: '#ffffff',
                position: 'sticky',
                top: 0,
                zIndex: 2,
              }}
            >
              <div
                style={{
                  display: 'flex',
                  alignItems: 'center',
                  gap: 6,
                  padding: '5px 8px',
                  borderRadius: 8,
                  backgroundColor: '#f8fafc',
                  border: '1px solid #e2e8f0',
                }}
              >
                <Search size={13} color="#94a3b8" />
                <input
                  ref={searchInputRef}
                  type="text"
                  placeholder={searchPlaceholder}
                  value={searchTerm}
                  onChange={(e) => setSearchTerm(e.target.value)}
                  style={{
                    border: 'none',
                    background: 'transparent',
                    outline: 'none',
                    width: '100%',
                    fontSize: 12,
                    color: '#0f172a',
                  }}
                  onClick={(e) => e.stopPropagation()}
                />
                {searchTerm && (
                  <button
                    type="button"
                    onClick={() => setSearchTerm('')}
                    style={{ background: 'none', border: 'none', padding: 0, cursor: 'pointer', color: '#94a3b8' }}
                  >
                    <X size={12} />
                  </button>
                )}
              </div>
            </div>
          )}

          {/* Multi-select Header actions */}
          {multiple && (
            <div
              style={{
                padding: '6px 12px',
                borderBottom: '1px solid #f1f5f9',
                backgroundColor: '#f8fafc',
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'space-between',
                fontSize: 11,
              }}
            >
              <span style={{ color: '#64748b' }}>
                {selectedOptions.length} of {options.length} selected
              </span>
              <div style={{ display: 'flex', gap: 10 }}>
                <button
                  type="button"
                  onClick={handleSelectAll}
                  style={{
                    background: 'none',
                    border: 'none',
                    color: '#1e56ff',
                    cursor: 'pointer',
                    fontWeight: 600,
                    padding: 0,
                  }}
                >
                  Select All
                </button>
                <button
                  type="button"
                  onClick={handleClear}
                  style={{
                    background: 'none',
                    border: 'none',
                    color: '#64748b',
                    cursor: 'pointer',
                    padding: 0,
                  }}
                >
                  Clear
                </button>
              </div>
            </div>
          )}

          {/* Options List */}
          <div
            className="custom-scrollbar"
            style={{
              overflowY: 'auto',
              flex: 1,
              padding: '4px',
              scrollbarWidth: 'thin',
              scrollbarColor: '#cbd5e1 transparent',
            }}
          >
            {filteredOptions.length === 0 ? (
              <div
                style={{
                  padding: '16px 12px',
                  textAlign: 'center',
                  fontSize: 12,
                  color: '#94a3b8',
                }}
              >
                {emptyText}
              </div>
            ) : (
              Object.entries(groupedOptions).map(([groupTitle, groupOpts]) => (
                <div key={groupTitle || 'root-group'}>
                  {groupTitle && (
                    <div
                      style={{
                        padding: '6px 10px 2px 10px',
                        fontSize: 10,
                        fontWeight: 700,
                        textTransform: 'uppercase',
                        letterSpacing: '0.04em',
                        color: '#94a3b8',
                      }}
                    >
                      {groupTitle}
                    </div>
                  )}

                  {groupOpts.map((opt) => {
                    const isSelected = isValueSelected(opt.value);
                    const globalIdx = filteredOptions.indexOf(opt);
                    const isHighlighted = globalIdx === highlightedIndex;

                    return (
                      <div
                        key={String(opt.value)}
                        role="option"
                        aria-selected={isSelected}
                        aria-disabled={opt.disabled}
                        onClick={(e) => handleSelect(opt, e)}
                        onMouseEnter={() => setHighlightedIndex(globalIdx)}
                        style={{
                          display: 'flex',
                          alignItems: 'center',
                          justifyContent: 'space-between',
                          gap: 10,
                          padding: '8px 10px',
                          borderRadius: 8,
                          fontSize: sizeStyles.fontSize,
                          color: opt.disabled ? '#cbd5e1' : isSelected ? '#1e56ff' : '#334155',
                          backgroundColor: isSelected
                            ? '#eff6ff'
                            : isHighlighted
                            ? '#f8fafc'
                            : 'transparent',
                          fontWeight: isSelected ? 600 : 400,
                          cursor: opt.disabled ? 'not-allowed' : 'pointer',
                          transition: 'background 0.12s ease',
                        }}
                      >
                        {/* Option Content or custom renderer */}
                        {renderOption ? (
                          renderOption(opt, isSelected)
                        ) : (
                          <div
                            style={{
                              display: 'flex',
                              alignItems: 'center',
                              gap: 8,
                              overflow: 'hidden',
                              flex: 1,
                            }}
                          >
                            {multiple && (
                              <input
                                type="checkbox"
                                checked={isSelected}
                                readOnly
                                disabled={opt.disabled}
                                style={{
                                  accentColor: '#1e56ff',
                                  cursor: 'pointer',
                                  pointerEvents: 'none',
                                }}
                              />
                            )}

                            {renderSafeIcon(opt.icon, sizeStyles.iconSize) && (
                              <span style={{ display: 'flex', flexShrink: 0 }}>
                                {renderSafeIcon(opt.icon, sizeStyles.iconSize)}
                              </span>
                            )}

                            <div style={{ display: 'flex', flexDirection: 'column', overflow: 'hidden' }}>
                              <span
                                style={{
                                  whiteSpace: 'nowrap',
                                  textOverflow: 'ellipsis',
                                  overflow: 'hidden',
                                }}
                              >
                                {opt.label}
                              </span>
                              {opt.description && (
                                <span style={{ fontSize: 11, color: '#64748b', marginTop: 1 }}>
                                  {opt.description}
                                </span>
                              )}
                            </div>

                            {opt.badge && (
                              <span
                                style={{
                                  marginLeft: 'auto',
                                  fontSize: 10,
                                  fontWeight: 700,
                                  padding: '1px 6px',
                                  borderRadius: 4,
                                  backgroundColor: opt.badgeColor || '#eff6ff',
                                  color: opt.badgeColor ? '#ffffff' : '#1e56ff',
                                  flexShrink: 0,
                                }}
                              >
                                {opt.badge}
                              </span>
                            )}
                          </div>
                        )}

                        {/* Selected Checkmark (for single-select) */}
                        {!multiple && isSelected && (
                          <Check size={14} color="#1e56ff" style={{ flexShrink: 0 }} />
                        )}
                      </div>
                    );
                  })}
                </div>
              ))
            )}
          </div>
        </div>
      )}

      {/* Helper / Error Text */}
      {error ? (
        <div
          style={{
            display: 'flex',
            alignItems: 'center',
            gap: 4,
            fontSize: 11,
            color: '#ef4444',
            marginTop: 4,
          }}
        >
          <AlertCircle size={12} />
          <span>{error}</span>
        </div>
      ) : helperText ? (
        <span style={{ fontSize: 11, color: '#64748b', marginTop: 4, display: 'block' }}>
          {helperText}
        </span>
      ) : null}

      {/* Hidden native input for form submissions */}
      {name && (
        <input
          type="hidden"
          name={name}
          value={
            multiple && Array.isArray(value)
              ? JSON.stringify(value)
              : value !== undefined && value !== null
              ? String(value)
              : ''
          }
        />
      )}
    </div>
  );
};

export default CustomDropdown;
