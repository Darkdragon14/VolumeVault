import { afterEach, expect, it, vi } from 'vitest';
import { initializeServerFreshHistory } from './serverFreshHistory';

let cleanup: (() => void) | undefined;
afterEach(() => { cleanup?.(); cleanup = undefined; });

it('reloads the current history URL before Inertia can restore cached DOM', () => {
    const reload = vi.fn();
    const cachedSwap = vi.fn();
    cleanup = initializeServerFreshHistory(reload);
    window.addEventListener('popstate', cachedSwap);
    try {
        window.history.replaceState({}, '', '/volumes?docker_host_id=2');
        window.dispatchEvent(new PopStateEvent('popstate', { state: { page: 'encrypted-or-stale' } }));
        expect(reload).toHaveBeenCalledOnce();
        expect(cachedSwap).not.toHaveBeenCalled();
        expect(window.location.search).toBe('?docker_host_id=2');
    } finally { window.removeEventListener('popstate', cachedSwap); }
});

it('reloads BFCache restores but not normal pageshow, and unregisters both listeners', () => {
    const reload = vi.fn();
    cleanup = initializeServerFreshHistory(reload);
    window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: false }));
    expect(reload).not.toHaveBeenCalled();
    window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true }));
    expect(reload).toHaveBeenCalledOnce();
    cleanup();
    window.dispatchEvent(new PopStateEvent('popstate'));
    window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true }));
    expect(reload).toHaveBeenCalledOnce();
});
