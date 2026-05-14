import { useState, useEffect } from 'react';

export const useScrollThreshold = (threshold = 60) => {
  const [isScrolled, setIsScrolled] = useState(() => window.scrollY > threshold);

  useEffect(() => {
    // Keep track of the requestAnimationFrame ID so we can cancel it on unmount
    let frameId: number;
    let ticking = false;

    // We keep a local closure variable to track state without relying on React state reads
    let wasScrolled = window.scrollY > threshold;

    const updateScroll = () => {
      const nowScrolled = window.scrollY > threshold;

      // Only trigger a React state update if the boolean actually changed
      if (nowScrolled !== wasScrolled) {
        wasScrolled = nowScrolled;
        setIsScrolled(nowScrolled);
      }

      // Reset ticking so the next scroll event can fire a new animation frame
      ticking = false;
    };

    const handleScroll = () => {
      // If a frame is already queued, don't queue another one
      if (!ticking) {
        frameId = window.requestAnimationFrame(updateScroll);
        ticking = true;
      }
    };

    // Run once on mount to catch cases where the user refreshes halfway down the page
    updateScroll();

    // Use passive listener so the browser doesn't wait for the JS thread to scroll
    window.addEventListener('scroll', handleScroll, { passive: true });

    return () => {
      window.removeEventListener('scroll', handleScroll);
      if (frameId) {
        window.cancelAnimationFrame(frameId);
      }
    };
  }, [threshold]);

  return isScrolled;
};