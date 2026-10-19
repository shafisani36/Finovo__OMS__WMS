import React from 'react';

interface LogoProps {
  className?: string;
}

export const Logo: React.FC<LogoProps> = ({ className = '' }) => {
  return (
    <div className={`brand-header ${className}`}>
      {/* Finovo Origami / WMS Box Hexagon Icon */}
      <svg
        className="brand-logo-icon"
        viewBox="0 0 44 44"
        fill="none"
        xmlns="http://www.w3.org/2000/svg"
        aria-hidden="true"
      >
        <rect width="44" height="44" rx="10" fill="#2563EB" />
        <path
          d="M22 10L32 16V28L22 34L12 28V16L22 10Z"
          stroke="#FFFFFF"
          strokeWidth="2.5"
          strokeLinejoin="round"
        />
        <path
          d="M22 10V34M12 16L22 22L32 16M12 28L22 22L32 28"
          stroke="#93C5FD"
          strokeWidth="2"
          strokeLinecap="round"
          strokeLinejoin="round"
        />
      </svg>
      <div className="brand-text-wrap">
        <span className="brand-name">Finovo</span>
        <span className="brand-sub">OMS / WMS</span>
      </div>
    </div>
  );
};

export default Logo;
