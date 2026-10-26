(() => {
    const storageKey = 'current-theme';
    const choices = ['system', 'light', 'dark'];
    const accentStorageKey = 'current-accent';
    const accents = ['violet', 'emerald', 'blue', 'rose', 'orange'];
    // Keep the palettes and picker ready for later; Current currently uses Violet.
    const accentSelectionEnabled = false;
    const systemTheme = window.matchMedia('(prefers-color-scheme: dark)');
    let preference = 'system';
    let accent = 'violet';

    try {
        // Migrate existing appearance preferences without resetting the user's theme.
        for (const [previous, next] of [['horizon-lens-theme', storageKey], ['horizon-lens-accent', accentStorageKey]]) {
            if (window.localStorage.getItem(next) === null) {
                const value = window.localStorage.getItem(previous);
                if (value !== null) window.localStorage.setItem(next, value);
            }
        }
        const saved = window.localStorage.getItem(storageKey);
        if (choices.includes(saved)) preference = saved;
        const savedAccent = window.localStorage.getItem(accentStorageKey);
        if (accentSelectionEnabled && accents.includes(savedAccent)) accent = savedAccent;
    } catch {
        // Browser privacy settings may make storage unavailable.
    }

    function applyTheme() {
        const theme = preference === 'system'
            ? (systemTheme.matches ? 'dark' : 'light')
            : preference;
        document.documentElement.dataset.theme = theme;
        document.documentElement.dataset.accent = accent;
        document.documentElement.style.colorScheme = theme;
        document.querySelectorAll('[data-theme-choice]').forEach((button) => {
            button.setAttribute('aria-pressed', String(button.dataset.themeChoice === preference));
        });
        document.querySelectorAll('[data-accent-choice]').forEach((button) => {
            button.setAttribute('aria-pressed', String(button.dataset.accentChoice === accent));
        });
        document.querySelectorAll('[data-accent-label]').forEach(label => {
            label.textContent = accent.charAt(0).toUpperCase() + accent.slice(1);
        });
    }

    // This script loads before the stylesheet to avoid a light flash on navigation.
    applyTheme();
    systemTheme.addEventListener('change', applyTheme);
    window.addEventListener('storage', (event) => {
        if (event.key === storageKey || event.key === null) {
            preference = choices.includes(event.newValue) ? event.newValue : 'system';
        }
        if (accentSelectionEnabled && (event.key === accentStorageKey || event.key === null)) {
            accent = accents.includes(event.newValue) ? event.newValue : 'violet';
        }
        if ([storageKey, accentStorageKey, null].includes(event.key)) applyTheme();
    });
    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('[data-theme-choice]').forEach((button) => {
            button.addEventListener('click', () => {
                preference = button.dataset.themeChoice;
                try {
                    window.localStorage.setItem(storageKey, preference);
                } catch {
                    // The current page still works without persistent storage.
                }
                applyTheme();
            });
        });
        document.querySelectorAll('[data-accent-picker]').forEach(picker => {
            picker.hidden = !accentSelectionEnabled;
            if (!accentSelectionEnabled) return;
            const summary = picker.querySelector('summary');
            picker.querySelectorAll('[data-accent-choice]').forEach(button => {
                button.addEventListener('click', () => {
                    if (!accents.includes(button.dataset.accentChoice)) return;
                    accent = button.dataset.accentChoice;
                    try {
                        window.localStorage.setItem(accentStorageKey, accent);
                    } catch {
                        // Preferences still apply when persistent storage is unavailable.
                    }
                    applyTheme();
                    picker.open = false;
                    summary.focus();
                });
            });
            document.addEventListener('pointerdown', event => {
                if (!picker.contains(event.target)) picker.open = false;
            });
            picker.addEventListener('keydown', event => {
                if (event.key === 'Escape' && picker.open) {
                    event.preventDefault();
                    picker.open = false;
                    summary.focus();
                }
            });
        });
        applyTheme();
    });
})();
