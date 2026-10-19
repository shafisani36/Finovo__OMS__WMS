import React from 'react';
import { CheckCircle2, AlertCircle, AlertTriangle, ShieldCheck, Zap } from 'lucide-react';
import type { WarehouseReadinessModel } from '../../services/omsService';

interface WarehouseReadinessCardProps {
  readiness: WarehouseReadinessModel;
  onActivate?: () => void;
  isActivating?: boolean;
}

export const WarehouseReadinessCard: React.FC<WarehouseReadinessCardProps> = ({
  readiness,
  onActivate,
  isActivating = false,
}) => {
  const { score, ready_for_activation, issues, passed_checks } = readiness;

  const scoreColor =
    score === 100 ? '#10b981' : score >= 70 ? '#1e56ff' : score >= 40 ? '#f59e0b' : '#ef4444';

  return (
    <div
      style={{
        backgroundColor: '#ffffff',
        border: '1px solid #e2e8f0',
        borderRadius: 16,
        padding: 20,
        boxShadow: '0 1px 3px rgba(0,0,0,0.02)',
      }}
    >
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 16 }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
          <div
            style={{
              width: 38,
              height: 38,
              borderRadius: 10,
              backgroundColor: ready_for_activation ? '#ecfdf5' : '#eff6ff',
              color: ready_for_activation ? '#059669' : '#1e56ff',
              display: 'flex',
              alignItems: 'center',
              justifyContent: 'center',
            }}
          >
            <ShieldCheck size={20} />
          </div>
          <div>
            <div style={{ fontSize: 14, fontWeight: 700, color: '#0f172a' }}>
              Operational Readiness Engine
            </div>
            <div style={{ fontSize: 12, color: '#64748b' }}>
              Authoritative validation for activation eligibility
            </div>
          </div>
        </div>

        <div style={{ textAlign: 'right' }}>
          <div style={{ fontSize: 24, fontWeight: 800, color: scoreColor }}>
            {score}%
          </div>
          <div style={{ fontSize: 11, fontWeight: 600, color: '#94a3b8', textTransform: 'uppercase' }}>
            Readiness Score
          </div>
        </div>
      </div>

      {/* Progress Bar */}
      <div
        style={{
          width: '100%',
          height: 8,
          backgroundColor: '#f1f5f9',
          borderRadius: 999,
          overflow: 'hidden',
          marginBottom: 16,
        }}
      >
        <div
          style={{
            width: `${score}%`,
            height: '100%',
            backgroundColor: scoreColor,
            borderRadius: 999,
            transition: 'width 0.4s ease-in-out',
          }}
        />
      </div>

      {/* Activation Status Banner */}
      <div
        style={{
          padding: '10px 14px',
          borderRadius: 12,
          backgroundColor: ready_for_activation ? '#ecfdf5' : '#fffbeb',
          border: `1px solid ${ready_for_activation ? '#a7f3d0' : '#fde68a'}`,
          display: 'flex',
          alignItems: 'center',
          justifyContent: 'space-between',
          marginBottom: 16,
          fontSize: 13,
        }}
      >
        <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
          {ready_for_activation ? (
            <CheckCircle2 size={16} color="#059669" />
          ) : (
            <AlertTriangle size={16} color="#b45309" />
          )}
          <span style={{ fontWeight: 600, color: ready_for_activation ? '#065f46' : '#92400e' }}>
            {ready_for_activation
              ? 'All required checks passed. Warehouse is ready for full operation!'
              : 'Setup incomplete. Resolve required errors before activating this facility.'}
          </span>
        </div>

        {ready_for_activation && onActivate && (
          <button
            onClick={onActivate}
            disabled={isActivating}
            className="btn-finovo-primary"
            style={{ padding: '6px 14px', fontSize: 12 }}
          >
            <Zap size={14} />
            <span>{isActivating ? 'Activating...' : 'Activate Warehouse'}</span>
          </button>
        )}
      </div>

      {/* Issues / Blockers */}
      {issues.length > 0 && (
        <div style={{ marginBottom: 14 }}>
          <div style={{ fontSize: 12, fontWeight: 700, color: '#334155', marginBottom: 8, textTransform: 'uppercase', letterSpacing: '0.04em' }}>
            Pending Items ({issues.length})
          </div>
          <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
            {issues.map((issue, idx) => (
              <div
                key={idx}
                style={{
                  display: 'flex',
                  alignItems: 'center',
                  gap: 8,
                  fontSize: 12,
                  padding: '6px 10px',
                  borderRadius: 8,
                  backgroundColor: issue.severity === 'error' ? '#fef2f2' : '#f8fafc',
                  border: `1px solid ${issue.severity === 'error' ? '#fecaca' : '#e2e8f0'}`,
                }}
              >
                {issue.severity === 'error' ? (
                  <AlertCircle size={14} color="#dc2626" />
                ) : (
                  <AlertTriangle size={14} color="#f59e0b" />
                )}
                <span
                  style={{
                    color: issue.severity === 'error' ? '#991b1b' : '#475569',
                    fontWeight: issue.severity === 'error' ? 600 : 500,
                  }}
                >
                  {issue.message}
                </span>
                <span
                  style={{
                    marginLeft: 'auto',
                    fontSize: 10,
                    fontWeight: 700,
                    textTransform: 'uppercase',
                    color: issue.severity === 'error' ? '#b91c1c' : '#b45309',
                    backgroundColor: issue.severity === 'error' ? '#fee2e2' : '#fef3c7',
                    padding: '2px 6px',
                    borderRadius: 4,
                  }}
                >
                  {issue.severity}
                </span>
              </div>
            ))}
          </div>
        </div>
      )}

      {/* Passed Checks */}
      {passed_checks.length > 0 && (
        <div>
          <div style={{ fontSize: 12, fontWeight: 700, color: '#334155', marginBottom: 8, textTransform: 'uppercase', letterSpacing: '0.04em' }}>
            Passed Checks ({passed_checks.length})
          </div>
          <div style={{ display: 'flex', flexWrap: 'wrap', gap: 6 }}>
            {passed_checks.map((chk, idx) => (
              <span
                key={idx}
                style={{
                  display: 'inline-flex',
                  alignItems: 'center',
                  gap: 5,
                  fontSize: 11,
                  fontWeight: 600,
                  backgroundColor: '#f0fdf4',
                  color: '#15803d',
                  border: '1px solid #bbf7d0',
                  padding: '3px 8px',
                  borderRadius: 6,
                }}
              >
                <CheckCircle2 size={12} color="#16a34a" />
                {chk.replace(/_/g, ' ')}
              </span>
            ))}
          </div>
        </div>
      )}
    </div>
  );
};

export default WarehouseReadinessCard;
