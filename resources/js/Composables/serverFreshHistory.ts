/** Install before Inertia initializes, so history never restores cached page props. */
export function initializeServerFreshHistory(reload: () => void = () => window.location.reload()): () => void {
    const onPopstate = (event: PopStateEvent) => {
        event.stopImmediatePropagation();
        reload();
    };
    const onPageshow = (event: PageTransitionEvent) => {
        if (event.persisted) reload();
    };
    window.addEventListener('popstate', onPopstate, true);
    window.addEventListener('pageshow', onPageshow);
    return () => {
        window.removeEventListener('popstate', onPopstate, true);
        window.removeEventListener('pageshow', onPageshow);
    };
}
