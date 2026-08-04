/* ==========================================================================
   CTU MindCare — Motion One loader
   --------------------------------------------------------------------------
   Motion (motion.dev) is the vanilla-web distribution of Framer Motion —
   the same animation engine used in framer-motion, without React.
   Loaded as an ES module with a graceful CSS-only fallback inside
   mindcare.js when the CDN is unavailable.
   ========================================================================== */
import { animate, stagger } from 'https://cdn.jsdelivr.net/npm/motion@12/+esm';

window.Motion = { animate, stagger };
