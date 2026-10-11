/*
 * Draggable edges between the panes. Each [data-split] handle sits in its own track of a CSS grid,
 * dragging it changes the share of the two tracks around it. The shares are remembered in localStorage,
 * a double click restores the default. The handles also move with the arrow keys.
 *
 * A split with `collapsed` tracks also has [data-collapse] buttons: one gives all the room to the other side,
 * pressed again it restores the share. Dragging or moving the handle restores it too.
 *
 * Monaco editors follow the new sizes by themselves (automaticLayout).
 *
 * The module starts the handles itself: entrypoints import it before Monaco, whose loading would delay it.
 */
const STORAGE_KEY = 'playground.splits';
const KEYBOARD_STEP = 0.02;
const MIN_SHARE = 0.15;
const MAX_SHARE = 0.85;

/**
 * name => the grid it resizes, the default share of the first track, the tracks for a share,
 * and the tracks while one side is collapsed
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
        initial: 0.5,
        apply: (grid, share) => grid.style.gridTemplateRows = 'minmax(0, ' + share + 'fr) var(--gutter) minmax(160px, ' + (1 - share) + 'fr)',
        // the details keep their header when collapsed: it has the button that brings them back
        collapsed: {
            before: (grid) => grid.style.gridTemplateRows = '0 var(--gutter) minmax(0, 1fr)',
            after: (grid) => grid.style.gridTemplateRows = 'minmax(0, 1fr) var(--gutter) auto',
        },
    },
    ast: {
        container: '.ast',
        initial: 0.56,
        apply: (grid, share) => grid.style.gridTemplateColumns = 'minmax(0, ' + share + 'fr) var(--gutter) minmax(0, ' + (1 - share) + 'fr)',
    },
};

initSplitters();

function initSplitters() {
    const shares = readShares();

    document.querySelectorAll('[data-split]').forEach((handle) => {
        const name = handle.dataset.split;
        const split = SPLITS[name];
        const grid = handle.closest(split.container);

        if (!grid) {
            return;
        }

        const isVertical = handle.getAttribute('aria-orientation') === 'vertical';
        const collapseButtons = document.querySelectorAll('[data-collapse="' + name + '"]');
        const collapsedKey = name + '.collapsed';

        // the default is not stored (remember = false): a later change of it applies
        const set = (share, remember = true) => {
            share = Math.min(MAX_SHARE, Math.max(MIN_SHARE, share));

            if (remember) {
                shares[name] = share;
            } else {
                delete shares[name];
            }

            delete shares[collapsedKey];
            split.apply(grid, share);
            showCollapsed(null);
            handle.setAttribute('aria-valuenow', String(Math.round(share * 100)));
        };

        // side: 'before' or 'after' the handle, null when both are shown
        const collapse = (side) => {
            shares[collapsedKey] = side;
            split.collapsed[side](grid);
            showCollapsed(side);
            handle.setAttribute('aria-valuenow', side === 'before' ? '0' : '100');
        };

        const showCollapsed = (side) => {
            if (side === null) {
                delete grid.dataset.collapsed;
            } else {
                grid.dataset.collapsed = side;
            }

            collapseButtons.forEach((button) => button.setAttribute('aria-pressed', String(button.dataset.collapseSide === side)));
        };

        const isCollapsed = () => typeof shares[collapsedKey] === 'string';

        // back to the share the split had before it collapsed
        const expand = () => typeof shares[name] === 'number' ? set(shares[name]) : set(split.initial, false);

        // set() forgets it
        const savedSide = shares[collapsedKey];

        if (typeof shares[name] === 'number') {
            set(shares[name]);
        } else {
            handle.setAttribute('aria-valuenow', String(Math.round(split.initial * 100)));
        }

        if (split.collapsed?.[savedSide]) {
            collapse(savedSide);
        }

        collapseButtons.forEach((button) => button.addEventListener('click', () => {
            const side = button.dataset.collapseSide;

            if (shares[collapsedKey] === side) {
                expand();
            } else {
                collapse(side);
            }

            saveShares(shares);
        }));

        handle.addEventListener('pointerdown', (event) => {
            if (event.button !== 0) {
                return;
            }

            event.preventDefault();

            // a collapsed side comes back first, so there are two panes to measure
            if (isCollapsed()) {
                expand();
            }

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
            set(split.initial, false);
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
        const shares = JSON.parse(localStorage.getItem(STORAGE_KEY));

        return shares !== null && typeof shares === 'object' && !Array.isArray(shares) ? shares : {};
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
