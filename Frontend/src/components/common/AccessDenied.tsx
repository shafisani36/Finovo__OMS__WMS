import React from 'react';
import { useNavigate } from 'react-router-dom';
import { ArrowLeft, LayoutDashboard, SearchX, ShieldOff } from 'lucide-react';

interface AccessDeniedProps {
  /** 403: the page exists but this account may not open it. 404: no such page. */
  variant?: 403 | 404;
}

const CONTENT = {
  403: {
    icon: ShieldOff,
    title: 'You do not have access to this page',
    message: 'Your account does not include permission for this part of Finovo. Ask an administrator if you need it.',
  },
  404: {
    icon: SearchX,
    title: 'Page not found',
    message: 'The page you are looking for does not exist or may have been moved.',
  },
} as const;

/** Full-page message shown inside the app shell when a page is off-limits (403) or unknown (404). */
export const AccessDenied: React.FC<AccessDeniedProps> = ({ variant = 403 }) => {
  const navigate = useNavigate();
  const { icon: Icon, title, message } = CONTENT[variant];

  return (
    <div className="status-page" role="alert">
      <div className="status-page-card">
        <div className={`status-page-icon is-${variant}`}>
          <Icon size={28} aria-hidden="true" />
        </div>
        <span className="status-page-code">{variant === 403 ? 'Error 403 · Access denied' : 'Error 404'}</span>
        <h1 className="status-page-title">{title}</h1>
        <p className="status-page-message">{message}</p>
        <div className="status-page-actions">
          <button type="button" className="btn-outline" onClick={() => navigate(-1)}>
            <ArrowLeft size={16} aria-hidden="true" />
            <span>Go back</span>
          </button>
          <button type="button" className="btn-primary" onClick={() => navigate('/dashboard')}>
            <LayoutDashboard size={16} aria-hidden="true" />
            <span>Dashboard</span>
          </button>
        </div>
      </div>
    </div>
  );
};

export default AccessDenied;
