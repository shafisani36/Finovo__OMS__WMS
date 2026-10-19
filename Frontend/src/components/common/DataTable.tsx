import React from 'react';
import { ChevronLeft, ChevronRight, Loader2, Inbox } from 'lucide-react';

export interface Column<T> {
  key: string;
  header: string;
  render?: (row: T, index: number) => React.ReactNode;
  width?: string | number;
  align?: 'left' | 'center' | 'right';
}

export interface DataTableProps<T> {
  columns: Column<T>[];
  data: T[];
  keyField: keyof T | ((row: T) => string | number);
  isLoading?: boolean;
  emptyMessage?: string;
  pagination?: {
    currentPage: number;
    lastPage: number;
    perPage: number;
    total: number;
    onPageChange: (page: number) => void;
    onPerPageChange?: (perPage: number) => void;
  };
  selectable?: boolean;
  selectedIds?: Set<string | number>;
  onSelectRow?: (id: string | number) => void;
  onSelectAll?: (allIds: (string | number)[]) => void;
}

export function DataTable<T extends Record<string, any>>({
  columns,
  data,
  keyField,
  isLoading = false,
  emptyMessage = 'No records found.',
  pagination,
  selectable = false,
  selectedIds = new Set(),
  onSelectRow,
  onSelectAll,
}: DataTableProps<T>) {
  const getRowKey = (row: T): string | number => {
    return typeof keyField === 'function' ? keyField(row) : row[keyField];
  };

  const allRowKeys = data.map(getRowKey);
  const isAllSelected = data.length > 0 && allRowKeys.every((k) => selectedIds.has(k));

  return (
    <div className="table-responsive-wrapper">
      <table className="finovo-table">
        <thead>
          <tr>
            {selectable && (
              <th style={{ width: 44, padding: '14px 16px' }}>
                <input
                  type="checkbox"
                  checked={isAllSelected}
                  onChange={() => onSelectAll && onSelectAll(allRowKeys)}
                  style={{ cursor: 'pointer', width: 16, height: 16, accentColor: '#1e56ff' }}
                />
              </th>
            )}
            {columns.map((col) => (
              <th
                key={col.key}
                style={{
                  width: col.width,
                  textAlign: col.align || 'left',
                }}
              >
                {col.header}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {isLoading ? (
            <tr>
              <td colSpan={columns.length + (selectable ? 1 : 0)} style={{ padding: '48px 0', textAlign: 'center' }}>
                <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 10 }}>
                  <Loader2 className="animate-spin text-blue-600" size={28} color="#1e56ff" />
                  <span style={{ fontSize: 13, color: '#64748b' }}>Loading records...</span>
                </div>
              </td>
            </tr>
          ) : data.length === 0 ? (
            <tr>
              <td colSpan={columns.length + (selectable ? 1 : 0)} style={{ padding: '48px 0', textAlign: 'center' }}>
                <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 10 }}>
                  <div style={{ width: 48, height: 48, borderRadius: 12, backgroundColor: '#f1f5f9', display: 'flex', alignItems: 'center', justifyContent: 'center', color: '#94a3b8' }}>
                    <Inbox size={24} />
                  </div>
                  <span style={{ fontSize: 14, fontWeight: 600, color: '#334155' }}>{emptyMessage}</span>
                  <span style={{ fontSize: 12, color: '#94a3b8' }}>Try adjusting your filters or search terms.</span>
                </div>
              </td>
            </tr>
          ) : (
            data.map((row, idx) => {
              const rowKey = getRowKey(row);
              const isSelected = selectedIds.has(rowKey);

              return (
                <tr key={String(rowKey)} style={{ backgroundColor: isSelected ? '#f8fafc' : undefined }}>
                  {selectable && (
                    <td style={{ width: 44, padding: '16px' }}>
                      <input
                        type="checkbox"
                        checked={isSelected}
                        onChange={() => onSelectRow && onSelectRow(rowKey)}
                        style={{ cursor: 'pointer', width: 16, height: 16, accentColor: '#1e56ff' }}
                      />
                    </td>
                  )}
                  {columns.map((col) => (
                    <td
                      key={col.key}
                      style={{
                        textAlign: col.align || 'left',
                      }}
                    >
                      {col.render ? col.render(row, idx) : row[col.key]}
                    </td>
                  ))}
                </tr>
              );
            })
          )}
        </tbody>
      </table>

      {/* Pagination Footer */}
      {pagination && (
        <div className="table-pagination-footer">
          <div>
            Showing {data.length} of {pagination.total} records
          </div>

          <div style={{ display: 'flex', alignItems: 'center', gap: 16 }}>
            {pagination.onPerPageChange && (
              <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                <span style={{ fontSize: 13, color: '#64748b' }}>Per page:</span>
                <select
                  value={pagination.perPage}
                  onChange={(e) => pagination.onPerPageChange!(Number(e.target.value))}
                  style={{
                    padding: '4px 8px',
                    borderRadius: 8,
                    border: '1px solid #cbd5e1',
                    fontSize: 12,
                    outline: 'none',
                    backgroundColor: '#ffffff',
                    cursor: 'pointer',
                  }}
                >
                  <option value={10}>10</option>
                  <option value={25}>25</option>
                  <option value={50}>50</option>
                </select>
              </div>
            )}

            <div className="pagination-controls">
              <button
                className="pagination-btn"
                disabled={pagination.currentPage <= 1 || isLoading}
                onClick={() => pagination.onPageChange(pagination.currentPage - 1)}
              >
                <ChevronLeft size={16} />
              </button>

              {Array.from({ length: Math.min(5, pagination.lastPage) }, (_, i) => {
                const pageNum = i + 1;
                return (
                  <button
                    key={pageNum}
                    className={`pagination-btn ${pagination.currentPage === pageNum ? 'active' : ''}`}
                    onClick={() => pagination.onPageChange(pageNum)}
                    disabled={isLoading}
                  >
                    {pageNum}
                  </button>
                );
              })}

              <button
                className="pagination-btn"
                disabled={pagination.currentPage >= pagination.lastPage || isLoading}
                onClick={() => pagination.onPageChange(pagination.currentPage + 1)}
              >
                <ChevronRight size={16} />
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}

export default DataTable;
