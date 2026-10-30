import React, { useEffect, useLayoutEffect, useRef } from 'react';
import gsap from 'gsap';
import { Check, Store as StoreIcon, X } from 'lucide-react';

export type ConnectingMode = 'redirecting' | 'connected' | 'failed';

interface StoreConnectingOverlayProps {
  mode: ConnectingMode;
  /** Platform name shown to the merchant, e.g. "Shopify". */
  platform: string;
  storeName?: string | null;
  /** Reason shown when the connection failed. */
  message?: string | null;
  /** Called once the animation has finished (go to the platform, or show the Stores page). */
  onComplete: () => void;
}

const STEPS: Record<ConnectingMode, (platform: string, store: string) => string[]> = {
  redirecting: (platform) => ['Preparing a secure connection', `Opening ${platform} to approve Finovo`],
  connected: (platform, store) => [`Approval received from ${platform}`, `Verifying access to ${store}`, 'Linking the store to its warehouse'],
  failed: (platform) => [`Response received from ${platform}`],
};

const TITLES: Record<ConnectingMode, (platform: string) => string> = {
  redirecting: (platform) => `Connecting to ${platform}`,
  connected: () => 'Store connected',
  failed: (platform) => `${platform} could not be connected`,
};

/**
 * Full-screen GSAP animation for connecting a store: Finovo and the platform are linked by a travelling pulse,
 * each step is ticked off, then a success tick (or a failure mark) is drawn. When it ends, onComplete runs.
 */
export const StoreConnectingOverlay: React.FC<StoreConnectingOverlayProps> = ({ mode, platform, storeName, message, onComplete }) => {
  const root = useRef<HTMLDivElement>(null);
  const done = useRef(onComplete);
  useEffect(() => {
    done.current = onComplete;
  });

  const steps = STEPS[mode](platform, storeName || 'your store');
  const failed = mode === 'failed';

  useLayoutEffect(() => {
    const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    const ctx = gsap.context(() => {
      const tl = gsap.timeline({ onComplete: () => done.current(), defaults: { ease: 'power2.out' } });
      if (reduced) tl.timeScale(3);

      tl.from('.sco-card', { y: 24, scale: 0.96, autoAlpha: 0, duration: 0.45 })
        .from('.sco-node', { scale: 0.4, autoAlpha: 0, stagger: 0.12, duration: 0.4, ease: 'back.out(2)' }, '-=0.15')
        .from('.sco-link-track', { scaleX: 0, transformOrigin: 'left center', duration: 0.45 }, '-=0.1')
        .fromTo('.sco-link-pulse', { left: '0%', autoAlpha: 1 }, { left: '100%', duration: 0.9, repeat: failed ? 0 : 1, ease: 'power1.inOut' }, '<');

      // Each step appears, then gets its tick.
      gsap.utils.toArray<HTMLElement>('.sco-step').forEach((step) => {
        tl.from(step, { x: -12, autoAlpha: 0, duration: 0.3 })
          .from(step.querySelector('.sco-step-icon'), { scale: 0, rotate: -90, duration: 0.3, ease: 'back.out(3)' }, '+=0.25');
      });

      if (failed) {
        tl.to('.sco-link-track', { backgroundColor: '#fca5a5', duration: 0.2 })
          .from('.sco-result', { scale: 0, autoAlpha: 0, duration: 0.4, ease: 'back.out(2)' })
          .to('.sco-result', { x: 8, duration: 0.06, repeat: 5, yoyo: true, ease: 'none' })
          .from('.sco-title, .sco-message', { y: 8, autoAlpha: 0, stagger: 0.1, duration: 0.3 }, '-=0.2')
          .to({}, { duration: 2.2 });
      } else {
        tl.to('.sco-link-track', { backgroundColor: '#22c55e', duration: 0.25 })
          .from('.sco-result', { scale: 0, autoAlpha: 0, duration: 0.45, ease: 'back.out(2.2)' })
          .fromTo('.sco-result-check', { strokeDashoffset: 48 }, { strokeDashoffset: 0, duration: 0.4, ease: 'power1.inOut' })
          .from('.sco-title, .sco-message', { y: 8, autoAlpha: 0, stagger: 0.1, duration: 0.3 }, '-=0.2')
          .to({}, { duration: mode === 'redirecting' ? 0.3 : 0.9 });
      }

      // Leaving for the platform keeps the card on screen until the browser has navigated.
      if (mode !== 'redirecting') {
        tl.to('.sco-card', { y: -16, autoAlpha: 0, duration: 0.3, ease: 'power2.in' }).to(root.current, { autoAlpha: 0, duration: 0.2 }, '<0.1');
      }
    }, root);

    return () => ctx.revert();
  }, [mode, failed]);

  return (
    <div ref={root} className="sco-overlay" role="status" aria-live="polite">
      <div className="sco-card">
        <div className="sco-link">
          <div className="sco-node sco-node-finovo" aria-label="Finovo">
            <svg viewBox="0 0 44 44" width="30" height="30" fill="none" aria-hidden="true">
              <path d="M22 10L32 16V28L22 34L12 28V16L22 10Z" stroke="#FFFFFF" strokeWidth="2.5" strokeLinejoin="round" />
              <path d="M22 10V34M12 16L22 22L32 16M12 28L22 22L32 28" stroke="#93C5FD" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" />
            </svg>
          </div>
          <div className="sco-link-track">
            <span className="sco-link-pulse" />
          </div>
          <div className="sco-node sco-node-platform" aria-label={platform}>
            <StoreIcon size={24} />
          </div>
        </div>

        <ul className="sco-steps">
          {steps.map((step) => (
            <li key={step} className="sco-step">
              <span className="sco-step-icon">
                <Check size={12} strokeWidth={3} />
              </span>
              <span>{step}</span>
            </li>
          ))}
        </ul>

        <div className={`sco-result ${failed ? 'is-failed' : ''}`}>
          {failed ? (
            <X size={30} strokeWidth={3} />
          ) : (
            <svg viewBox="0 0 32 32" width="32" height="32" aria-hidden="true">
              <path className="sco-result-check" d="M8 16.5l5.5 5.5L24 11" fill="none" stroke="currentColor" strokeWidth="3.2" strokeLinecap="round" strokeLinejoin="round" strokeDasharray="48" />
            </svg>
          )}
        </div>

        <h3 className="sco-title">{TITLES[mode](platform)}</h3>
        <p className="sco-message">
          {failed
            ? message
            : mode === 'redirecting'
              ? `You will approve Finovo on ${platform}, then come straight back here.`
              : `${storeName || 'Your store'} is now connected. Taking you to your stores…`}
        </p>
      </div>
    </div>
  );
};

export default StoreConnectingOverlay;
