/*
 * Draggable edges between the panes. Each [data-split] handle sits in its own track of a CSS grid,
 * dragging it changes the share of the two tracks around it. The shares are remembered in localStorage,
 * a double click restores the default. The handles also move with the arrow keys.
 *
 * Monaco editors follow the new sizes by themselves (automaticLayout).
 */
const STORAGE_KEY = 'playground.splits';
const KEYBOARD_STEP = 0.02;
const MIN_SHARE = 0.15;
const MAX_SHARE = 0.85;

/**
 * name => the grid it resizes, the default share of the first track, and the tracks for a share
 */
const SPLITS = {
    columns: {
        container: '.ws',
        initial: 0.52,
        apply: (grid, share) => grid.style.gridTemplateColumns = 'minmax(0, ' + share + 'fr) var(--gutter) minmax(0, ' + (1 - share) + 'fr)',
    },
    rows: {
        container: '.ws',
        initial: 0.56,
        apply: (grid, share) => grid.style.gridTemplateRows = 'minmax(0, ' + share + 'fr) var(--gutter) minmax(0, ' + (1 - share) + 'fr)',
    },
    results: {
        container: '.res',
        initial: 0.43,
        apply: (grid, share) => grid.style.gridTemplateRows = 'auto auto minmax(80px, ' + share + 'fr) var(--gutter) minmax(160px, ' + (1 - share) + 'fr)',
    },
    ast: {
        container: '.ast',
        initial: 0.56,
        apply: (grid, share) => grid.style.gridTemplateColumns = 'minmax(0, ' + share + 'fr) var(--gutter) minmax(0, ' + (1 - share) + 'fr)',
    },
};

export function initSplitters() {
    const shares = readShares();

    document.querySelectorAll('[data-split]').forEach((handle) => {
        const name = handle.dataset.split;
        const split = SPLITS[name];
        const grid = handle.closest(split.container);

        if (!grid) {
            return;
        }

        const isVertical = handle.getAttribute('aria-orientation') === 'vertical';

        const set = (share) => {
            share = Math.min(MAX_SHARE, Math.max(MIN_SHARE, share));
            shares[name] = share;
            split.apply(grid, share);
            handle.setAttribute('aria-valuenow', String(Math.round(share * 100)));
        };

        if (typeof shares[name] === 'number') {
            set(shares[name]);
        } else {
            handle.setAttribute('aria-valuenow', String(Math.round(split.initial * 100)));
        }

        handle.addEventListener('pointerdown', (event) => {
            if (event.button !== 0) {
                return;
            }

            event.preventDefault();
            handle.setPointerCapture(event.pointerId);
            document.body.classList.add(isVertical ? 'is-resizing-x' : 'is-resizing-y');
            handle.classList.add('is-dragging');

            // the two panes around the handle, measured once: the share is the pointer's position between them
            const before = handle.previousElementSibling.getBoundingClientRect();
            const after = handle.nextElementSibling.getBoundingClientRect();
            const start = isVertical ? before.left : before.top;
            const size = isVertical ? before.width + after.width : before.height + after.height;

            const move = (moveEvent) => {
                const position = isVertical ? moveEvent.clientX : moveEvent.clientY;

                set((position - start) / size);
            };

            const stop = () => {
                handle.removeEventListener('pointermove', move);
                handle.removeEventListener('pointerup', stop);
                handle.removeEventListener('pointercancel', stop);
                document.body.classList.remove('is-resizing-x', 'is-resizing-y');
                handle.classList.remove('is-dragging');
                saveShares(shares);
            };

            handle.addEventListener('pointermove', move);
            handle.addEventListener('pointerup', stop);
            handle.addEventListener('pointercancel', stop);
        });

        handle.addEventListener('dblclick', () => {
            delete shares[name];
            set(split.initial);
            delete shares[name];
            saveShares(shares);
        });

        handle.addEventListener('keydown', (event) => {
            const keys = isVertical ? ['ArrowLeft', 'ArrowRight'] : ['ArrowUp', 'ArrowDown'];
            const direction = keys.indexOf(event.key);

            if (direction < 0) {
                return;
            }

            event.preventDefault();
            set((shares[name] ?? split.initial) + (direction === 0 ? -KEYBOARD_STEP : KEYBOARD_STEP));
            saveShares(shares);
        });
    });
}

function readShares() {
    try {
        return JSON.parse(localStorage.getItem(STORAGE_KEY)) || {};
    } catch (e) {
        return {};
    }
}

function saveShares(shares) {
    try {
        localStorage.setItem(STORAGE_KEY, JSON.stringify(shares));
    } catch (e) {
        // the sizes are only a convenience
    }
}
