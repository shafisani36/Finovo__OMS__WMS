import React from 'react';
import { Logo } from '../common/Logo';
import { Box, BarChart3, Network } from 'lucide-react';
import authIllustration from '../../assets/auth-illustration.png';
import waveBg from '../../assets/wave-bg.png';

interface AuthLayoutProps {
  children: React.ReactNode;
  title?: string;
  subtitle?: string;
}

export const AuthLayout: React.FC<AuthLayoutProps> = ({
  children,
  title = 'LOG IN TO KEEP YOUR OPERATIONS MOVING.',
  subtitle = 'Access your workspace and manage orders, inventory, stores and warehouses — all in one place.',
}) => {
  return (
    <div className="auth-wrapper">
      {/* Decorative Bottom Wave */}
      <img
        src={waveBg}
        alt=""
        aria-hidden="true"
        className="bottom-wave-accent"
      />

      <div className="auth-container">
        {/* Left Hero Brand & Metrics Showcase */}
        <div className="auth-hero-col">
          <Logo />

          <div className="hero-content">
            <h1 className="hero-title">
              {title}
            </h1>
            <p className="hero-desc">
              {subtitle}
            </p>

            <div className="feature-bullets">
              <div className="feature-item">
                <div className="feature-icon-badge">
                  <Box size={18} aria-hidden="true" />
                </div>
                <div className="feature-text">
                  <strong>Real-Time Control</strong> &mdash; Stay updated across all locations
                </div>
              </div>

              <div className="feature-item">
                <div className="feature-icon-badge">
                  <BarChart3 size={18} aria-hidden="true" />
                </div>
                <div className="feature-text">
                  <strong>Smarter Fulfillment</strong> &mdash; From orders to delivery
                </div>
              </div>

              <div className="feature-item">
                <div className="feature-icon-badge">
                  <Network size={18} aria-hidden="true" />
                </div>
                <div className="feature-text">
                  <strong>One Unified Platform</strong> &mdash; Stores, inventory and warehouses
                </div>
              </div>
            </div>
          </div>

          <div className="hero-illustration-box">
            <img
              src={authIllustration}
              alt="Finovo OMS / WMS Warehouse and fulfillment operations telemetry"
              className="hero-illustration-img"
            />
          </div>
        </div>

        {/* Right Form Action Card */}
        <div className="auth-form-col">
          {children}
        </div>
      </div>
    </div>
  );
};
