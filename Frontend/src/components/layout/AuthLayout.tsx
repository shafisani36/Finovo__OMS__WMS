import React from 'react';
import { Logo } from '../common/Logo';
import { Box, BarChart3, Network, type LucideIcon } from 'lucide-react';
import authIllustration from '../../assets/auth-illustration.png';
import waveBg from '../../assets/wave-bg.png';

export interface AuthFeature {
  icon: LucideIcon;
  title: string;
  text: string;
}

interface AuthLayoutProps {
  children: React.ReactNode;
  title?: string;
  highlight?: string;
  subtitle?: string;
  features?: AuthFeature[];
}

const STAFF_FEATURES: AuthFeature[] = [
  { icon: Box, title: 'Real-Time Control', text: 'Stay updated across all locations' },
  { icon: BarChart3, title: 'Smarter Fulfillment', text: 'From orders to delivery' },
  { icon: Network, title: 'One Unified Platform', text: 'Stores, inventory and warehouses' },
];

export const AuthLayout: React.FC<AuthLayoutProps> = ({
  children,
  title = 'LOG IN TO KEEP YOUR',
  highlight = 'OPERATIONS MOVING.',
  subtitle = 'Access your workspace and manage orders, inventory, stores and warehouses — all in one place.',
  features = STAFF_FEATURES,
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
              {title} {highlight && <span className="hero-title-highlight">{highlight}</span>}
            </h1>
            <p className="hero-desc">
              {subtitle}
            </p>

            <div className="feature-bullets">
              {features.map(({ icon: Icon, title: featureTitle, text }) => (
                <div className="feature-item" key={featureTitle}>
                  <div className="feature-icon-badge">
                    <Icon size={22} aria-hidden="true" />
                  </div>
                  <div className="feature-text">
                    <strong>{featureTitle}</strong>
                    <small>{text}</small>
                  </div>
                </div>
              ))}
            </div>
          </div>

          <div className="hero-illustration-box">
            <img
              src={authIllustration}
              alt=""
              aria-hidden="true"
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