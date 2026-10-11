/*!
 * AST X-Ray for the Monaco editor: draws a dashed box around every AST node
 * and shows the node's details on hover; a click pins a node.
 *
 * Portions of this file (node visibility rules, nesting heights, box layout,
 * edge snapping, hover regions, popup rendering) are ported from PHPStan's
 * playground AST X-Ray, originally written for CodeMirror 6:
 * https://github.com/phpstan/phpstan/blob/HEAD/website/src/js/editor/xray.ts
 * and are used under the following license:
 *
 * MIT License
 *
 * Copyright (c) 2016 Ondřej Mirtes
 * Copyright (c) 2025 PHPStan s.r.o.
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 */

/*
 * The node data comes from AstController::xray() (see XRayBuilder for the format).
 * Monaco has no layer API, so boxes are absolutely positioned divs in an
 * overlay on top of the editor. Code is monospace and folding/word wrap are
 * off, so every box is computed from line/column alone, without measuring DOM.
 */
import * as monaco from 'monaco-editor';

const PAD_BASE = 3;
const PAD_STEP = 4;
const SNAP = 3;
// Boxes stop this short of the row above and below so neighbours never touch.
const ROW_MARGIN = 2;
const DASH_PERIOD = 6;
const MAX_PATH = 4;
const MAX_TOOLTIP_VALUE = 60;
const FETCH_DEBOUNCE_MS = 300;

// Whitespace, or a line that is only a comment (or part of a docblock).
const BLANK_OR_COMMENT = /^\s*(?:\/\/.*|#.*|\/\*.*|\*.*)?$/;

// Monochrome themes for X-Ray mode: no syntax colours, so the boxes stand out.
// Same background as Monaco's own vs-dark / vs, so the editor does not flip polarity.
const MONO_DARK = 'xray-mono-dark';
const MONO_LIGHT = 'xray-mono-light';

monaco.editor.defineTheme(MONO_DARK, {
    base: 'vs-dark',
    inherit: false,
    rules: [{token: '', foreground: 'd4d4d4', background: '1e1e1e'}],
    colors: {
        'editor.background': '#1e1e1e',
        'editor.foreground': '#d4d4d4',
        'editorLineNumber.foreground': '#5a5a5a',
        'editorLineNumber.activeForeground': '#9a9a9a',
        'editor.lineHighlightBackground': '#00000000',
        'editor.lineHighlightBorder': '#00000000',
        'editorCursor.foreground': '#ffffff',
        'editor.selectionBackground': '#ffffff38',
    },
});

monaco.editor.defineTheme(MONO_LIGHT, {
    base: 'vs',
    inherit: false,
    rules: [{token: '', foreground: '1f1f1f', background: 'fffffe'}],
    colors: {
        'editor.background': '#fffffe',
        'editor.foreground': '#1f1f1f',
        'editorLineNumber.foreground': '#9a9a9a',
        'editorLineNumber.activeForeground': '#5a5a5a',
        'editor.lineHighlightBackground': '#00000000',
        'editor.lineHighlightBorder': '#00000000',
        'editorCursor.foreground': '#000000',
        'editor.selectionBackground': '#00000029',
    },
});

function isDarkPage() {
    return document.documentElement.classList.contains('dark');
}

function isHiddenKind(kind) {
    return kind.startsWith('Name') || kind.startsWith('Identifier') || kind === 'VarLikeIdentifier' || kind === 'Stmt\\Nop';
}

function shortClass(fqcn) {
    return fqcn.replace(/^PhpParser\\Node\\/, '');
}

/**
 * @param {object} options
 * @param {HTMLElement} options.editorNode
 * @param {HTMLElement|null} options.statusNode
 * @param {string} options.endpoint            POST endpoint returning the X-Ray data for {code}
 * @param {string} options.code
 * @param {object|null} options.initialData    X-Ray data for `code`, saves the first request
 * @param {function(number)} options.onPin     called with the pinned node index (-1 when unpinned)
 * @param {function()} options.onData          called whenever fresh X-Ray data is applied
 */
export function initXRayEditor({editorNode, statusNode, endpoint, code, initialData = null, onPin = () => {}, onData = () => {}}) {
    const editor = monaco.editor.create(editorNode, {
        value: code,
        language: 'php',
        minimap: {enabled: false},
        // Room between rows for the boxes.
        lineHeight: 24,
        fontSize: 14,
        folding: false,
        wordWrap: 'off',
        scrollBeyondLastLine: false,
        automaticLayout: true,
        renderLineHighlight: 'none',
        renderIndentGuides: false,
        // its highlight would look like one more box
        matchBrackets: 'never',
        theme: isDarkPage() ? 'vs-dark' : 'vs',
    });
    const model = editor.getModel();

    const state = {
        enabled: true,
        busy: false,
        nodes: [],
        decorationIds: [],
        hovered: -1,
        // clicked node: stays highlighted and is what the page shows details (and a link) for
        pinned: -1,
        boxes: [],
        hits: [],
        requestSeq: 0,
    };

    // ---- DOM ---------------------------------------------------------------

    editorNode.classList.add('xray-host');

    const layer = document.createElement('div');
    layer.className = 'xray-layer';
    editorNode.appendChild(layer);

    const toggle = document.createElement('button');
    toggle.type = 'button';
    toggle.className = 'xray-toggle';
    toggle.innerHTML = '<span class="xray-toggle-dot"></span>AST X-Ray';
    editorNode.appendChild(toggle);

    const tooltip = document.createElement('div');
    tooltip.className = 'xray-tooltip';
    tooltip.hidden = true;
    document.body.appendChild(tooltip);

    toggle.addEventListener('click', () => {
        state.enabled = !state.enabled;
        setHovered(-1);
        applyTheme();
        render();

        if (state.enabled) {
            fetchXRay();
        }
    });

    function applyTheme() {
        const dark = isDarkPage();

        if (state.enabled) {
            monaco.editor.setTheme(dark ? MONO_DARK : MONO_LIGHT);
        } else {
            monaco.editor.setTheme(dark ? 'vs-dark' : 'vs');
        }

        // xray-bg-dark / xray-bg-light name the editor background, the box colours depend on it.
        editorNode.classList.toggle('xray-on', state.enabled);
        editorNode.classList.toggle('xray-bg-dark', state.enabled && dark);
        editorNode.classList.toggle('xray-bg-light', state.enabled && !dark);
        toggle.classList.toggle('xray-toggle-active', state.enabled);
        toggle.classList.toggle('xray-toggle-busy', state.enabled && state.busy);
    }

    function setStatus(text, isError) {
        if (!statusNode) {
            return;
        }

        statusNode.textContent = text;
        statusNode.classList.toggle('xray-status-error', !!isError);
    }

    // ---- Data --------------------------------------------------------------

    let fetchTimer = null;

    function scheduleFetch() {
        clearTimeout(fetchTimer);
        fetchTimer = setTimeout(fetchXRay, FETCH_DEBOUNCE_MS);
    }

    async function fetchXRay() {
        if (!state.enabled) {
            return;
        }

        const seq = ++state.requestSeq;
        const version = model.getAlternativeVersionId();

        state.busy = true;
        applyTheme();

        let response;
        let payload;

        try {
            response = await fetch(endpoint, {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({code: model.getValue()}),
            });
            payload = await response.json();
        } catch (e) {
            payload = {error: {message: 'Failed to build AST: ' + e.message}};
        }

        // A newer request is on its way, or the offsets no longer match the text.
        if (seq !== state.requestSeq || version !== model.getAlternativeVersionId()) {
            return;
        }

        state.busy = false;
        applyTheme();

        if (payload.error) {
            showError(payload.error);

            return;
        }

        monaco.editor.setModelMarkers(model, 'xray', []);
        applyData(payload);
        setStatus(state.nodes.length + ' nodes. Hover over the code to inspect them, click to pin.', false);
    }

    function showError(error) {
        const markers = [];

        if (error.startLine > 0) {
            const startColumn = error.startColumn || 1;

            markers.push({
                severity: monaco.MarkerSeverity.Error,
                message: error.message,
                startLineNumber: error.startLine,
                endLineNumber: error.startLine,
                startColumn,
                endColumn: error.endColumn ? error.endColumn + 1 : model.getLineMaxColumn(error.startLine),
            });
        }

        monaco.editor.setModelMarkers(model, 'xray', markers);
        // The previous boxes stay, mapped through the edits, until the code parses again.
        setStatus(error.message + (error.startLine > 0 ? ' on line ' + error.startLine : ''), true);
    }

    function applyData(data) {
        const previouslyPinned = state.pinned >= 0 ? state.nodes[state.pinned] : null;
        const nodes = data.nodes.map(([kind, from, to, parent, props, target]) => ({
            kind,
            from,
            to,
            parent,
            props: props.map(([name, value]) => ({name, value})),
            target,
            depth: 0,
            drawn: false,
            multiLine: false,
            height: 0,
        }));

        for (const node of nodes) {
            node.depth = node.parent >= 0 ? nodes[node.parent].depth + 1 : 0;
        }

        // Decorations track the node ranges through edits until fresh data arrives.
        state.decorationIds = model.deltaDecorations(state.decorationIds, nodes.map(node => ({
            range: rangeOf(node.from, node.to),
            options: {stickiness: monaco.editor.TrackedRangeStickiness.NeverGrowsWhenTypingAtEdges},
        })));
        state.nodes = nodes;
        state.hovered = -1;

        // The same node (kind and range, mapped through the edits) stays pinned; its index may differ.
        const pinned = previouslyPinned === null
            ? -1
            : nodes.findIndex(n => n.kind === previouslyPinned.kind && n.from === previouslyPinned.from && n.to === previouslyPinned.to);

        state.pinned = pinned;

        updateNodes();
        render();
        onData();
        onPin(pinned);
    }

    function rangeOf(from, to) {
        return monaco.Range.fromPositions(model.getPositionAt(from), model.getPositionAt(to));
    }

    function mapNodesThroughEdits() {
        state.nodes.forEach((node, index) => {
            const range = model.getDecorationRange(state.decorationIds[index]);

            if (range === null) {
                node.to = node.from;

                return;
            }

            node.from = model.getOffsetAt(range.getStartPosition());
            node.to = model.getOffsetAt(range.getEndPosition());
        });

        updateNodes();
    }

    function updateNodes() {
        const nodes = state.nodes;

        for (const node of nodes) {
            node.drawn = !isHiddenKind(node.kind) && node.to > node.from;

            if (node.kind === 'Expr\\Variable' && node.parent >= 0 && nodes[node.parent].kind === 'Param') {
                node.drawn = false;
            }
        }

        // A node whose range is exactly one of its children's would only draw a second box.
        for (const node of nodes) {
            if (!node.drawn || node.parent < 0) {
                continue;
            }

            const parent = nodes[node.parent];

            if (parent.from === node.from && parent.to === node.to) {
                parent.drawn = false;
            }
        }

        for (const node of nodes) {
            node.startLine = model.getPositionAt(node.from).lineNumber;
            node.endLine = model.getPositionAt(Math.max(node.from, node.to - 1)).lineNumber;
            node.multiLine = node.startLine !== node.endLine;
            node.height = 0;
        }

        // Children follow their parents in pre-order, so walking backwards sees a
        // node's whole subtree before the node itself. height = nested drawn levels
        // of the same shape, which decides how far outwards the box is padded.
        for (let i = nodes.length - 1; i >= 0; i--) {
            const node = nodes[i];

            if (!node.drawn) {
                continue;
            }

            for (let p = node.parent; p >= 0; p = nodes[p].parent) {
                const ancestor = nodes[p];

                if (!ancestor.drawn) {
                    continue;
                }

                if (ancestor.multiLine === node.multiLine) {
                    ancestor.height = Math.max(ancestor.height, node.height + 1);
                }

                break;
            }
        }
    }

    // ---- Layout ------------------------------------------------------------

    function metrics() {
        const layout = editor.getLayoutInfo();
        // The layer starts where line decorations start, so boxes at column 0 have room for their padding.
        const originX = layout.decorationsLeft;

        return {
            originX,
            width: layout.width - originX - layout.verticalScrollbarWidth,
            height: layout.height - layout.horizontalScrollbarHeight,
            textLeft: layout.contentLeft - originX - editor.getScrollLeft(),
            charWidth: editor.getOption(monaco.editor.EditorOption.fontInfo).typicalHalfwidthCharacterWidth,
            lineHeight: editor.getOption(monaco.editor.EditorOption.lineHeight),
            scrollTop: editor.getScrollTop(),
            tabSize: model.getOptions().tabSize,
        };
    }

    function visualColumn(text, index, tabSize) {
        let column = 0;

        for (let i = 0; i < index && i < text.length; i++) {
            column = text[i] === '\t' ? column + tabSize - (column % tabSize) : column + 1;
        }

        return column;
    }

    function layoutBoxes(m) {
        const boxes = [];
        const visible = editor.getVisibleRanges();

        if (visible.length === 0) {
            return boxes;
        }

        const firstVisibleLine = visible[0].startLineNumber;
        const lastVisibleLine = visible[visible.length - 1].endLineNumber;
        const minLeft = -editor.getScrollLeft();
        const x = (lineNumber, offset) => {
            const text = model.getLineContent(lineNumber);
            const index = offset - model.getOffsetAt({lineNumber, column: 1});

            return m.textLeft + visualColumn(text, index, m.tabSize) * m.charWidth;
        };
        const rowTop = lineNumber => editor.getTopForLineNumber(lineNumber) - m.scrollTop;

        state.nodes.forEach((node, index) => {
            // A pinned node is drawn even when hidden by default (e.g. an Identifier from an old link).
            const shown = node.drawn || (index === state.pinned && node.to > node.from);

            if (!shown || node.endLine < firstVisibleLine || node.startLine > lastVisibleLine) {
                return;
            }

            const pad = PAD_BASE + PAD_STEP * node.height;

            if (!node.multiLine) {
                const top = rowTop(node.startLine);

                boxes.push({
                    node: index,
                    left: Math.max(minLeft, x(node.startLine, node.from) - pad),
                    right: Math.min(m.width, x(node.startLine, node.to) + pad),
                    top: top + ROW_MARGIN,
                    bottom: top + m.lineHeight - ROW_MARGIN,
                    block: false,
                    rowTop: top,
                    rowBottom: top + m.lineHeight,
                });

                return;
            }

            // Multi-line node: one box around everything it spans. Left hugs the
            // least indented of its (non-blank) lines, right the longest one.
            let left = Infinity;
            let right = -Infinity;

            for (let n = node.startLine; n <= node.endLine; n++) {
                const text = model.getLineContent(n);
                const lineFrom = model.getOffsetAt({lineNumber: n, column: 1});
                const indent = /^\s*/.exec(text)[0].length;
                const trimmedEnd = text.replace(/\s+$/, '').length;

                if (n !== node.startLine && n !== node.endLine && indent === text.length) {
                    continue;
                }

                const start = n === node.startLine ? node.from : lineFrom + indent;
                const end = n === node.endLine ? node.to : lineFrom + trimmedEnd;

                left = Math.min(left, x(n, start));
                right = Math.max(right, x(n, end));
            }

            const top = rowTop(node.startLine) + ROW_MARGIN;
            const bottom = rowTop(node.endLine) + m.lineHeight - ROW_MARGIN;

            boxes.push({
                node: index,
                left: Math.max(minLeft, left - pad),
                right: Math.min(m.width, right + pad),
                top,
                bottom,
                block: true,
            });
        });

        snapEdges(boxes);

        return boxes;
    }

    // Vertical edges of boxes on the same row that end up a pixel or two apart
    // (a sibling's padding meeting another's) are moved onto each other. Block
    // boxes are anchors; only single-line boxes move.
    function snapEdges(boxes) {
        const rows = new Map();

        for (const box of boxes) {
            if (box.block) {
                continue;
            }

            const key = Math.round(box.top);

            if (!rows.has(key)) {
                rows.set(key, []);
            }

            rows.get(key).push(box);
        }

        const blocks = boxes.filter(b => b.block);

        for (const row of rows.values()) {
            const rowTop = row[0].top;
            const anchors = [];

            for (const block of blocks) {
                if (block.top <= rowTop + 0.5 && block.bottom >= row[0].bottom - 0.5) {
                    anchors.push(block.left, block.right);
                }
            }

            const edges = [];

            for (const box of row) {
                edges.push({x: box.left, box, side: 'left'}, {x: box.right, box, side: 'right'});
            }

            for (const edge of edges) {
                for (const anchor of anchors) {
                    if (Math.abs(edge.x - anchor) <= SNAP) {
                        edge.x = anchor;
                    }
                }
            }

            edges.sort((a, b) => a.x - b.x);
            let previous = -Infinity;

            for (const edge of edges) {
                if (edge.x - previous > 0 && edge.x - previous <= SNAP) {
                    edge.x = previous;
                }

                previous = edge.x;
                edge.box[edge.side] = edge.x;
            }
        }
    }

    function nearestDrawnAncestor(index) {
        const nodes = state.nodes;

        for (let p = nodes[index].parent; p >= 0; p = nodes[p].parent) {
            if (nodes[p].drawn) {
                return p;
            }
        }

        return -1;
    }

    // The hover regions. The drawn boxes leave gaps (the margin between rows, the
    // space after a statement, blank lines) that would all fall to the enclosing
    // block, so the pointer crossing from one line to the next would flash the
    // block's popup. Instead the regions tile the area like states on a map:
    //  - a single-line region spans its whole row;
    //  - the outermost statement on a row also owns what follows it when that is
    //    only whitespace or a comment, and two on one row meet halfway;
    //  - blank or comment-only rows between two statements of the same block are
    //    split between them at the middle;
    //  - the statements of a block share one left edge (the leftmost of them).
    function hitRegions(boxes, m) {
        const nodes = state.nodes;
        const text = model.getValue();
        const lineEndOffset = lineNumber => model.getOffsetAt({lineNumber, column: model.getLineMaxColumn(lineNumber)});
        const hits = boxes.map(box => box.block ? {...box} : {...box, top: box.rowTop, bottom: box.rowBottom});

        const isOutermost = index => {
            const ancestor = nearestDrawnAncestor(index);

            return ancestor < 0 || nodes[ancestor].multiLine;
        };
        const blockOf = index => {
            let p = nearestDrawnAncestor(index);

            while (p >= 0 && !nodes[p].multiLine) {
                p = nearestDrawnAncestor(p);
            }

            return p;
        };
        const push = (map, key, value) => {
            if (!map.has(key)) {
                map.set(key, []);
            }

            map.get(key).push(value);
        };

        const rows = new Map();
        const groups = new Map();

        for (const hit of hits) {
            if (hit.block || !isOutermost(hit.node)) {
                continue;
            }

            const rowKey = Math.round(hit.top);
            push(rows, rowKey, hit);

            // Only statements take part in the block-wide rules; a parameter or a
            // type on the block's header line is the block's own business.
            if (!nodes[hit.node].kind.startsWith('Stmt\\')) {
                continue;
            }

            const block = blockOf(hit.node);

            if (!groups.has(block)) {
                groups.set(block, new Map());
            }

            push(groups.get(block), rowKey, hit);
        }

        for (const row of rows.values()) {
            row.sort((a, b) => a.left - b.left);

            for (let i = 0; i < row.length; i++) {
                const current = row[i];
                const next = row[i + 1];
                const node = nodes[current.node];

                if (next !== undefined) {
                    if (BLANK_OR_COMMENT.test(text.slice(node.to, nodes[next.node].from))) {
                        const middle = (current.right + next.left) / 2;
                        current.right = middle;
                        next.left = middle;
                    }
                } else if (BLANK_OR_COMMENT.test(text.slice(node.to, lineEndOffset(node.endLine)))) {
                    current.right = m.width;
                }
            }
        }

        for (const group of groups.values()) {
            const rowKeys = [...group.keys()].sort((a, b) => a - b);
            const groupLeft = Math.min(...[...group.values()].flat().map(h => h.left));

            for (const row of group.values()) {
                const first = row.reduce((a, b) => a.left <= b.left ? a : b);
                first.left = groupLeft;
            }

            for (let i = 0; i + 1 < rowKeys.length; i++) {
                const upper = group.get(rowKeys[i]);
                const lower = group.get(rowKeys[i + 1]);
                const upperBottom = Math.max(...upper.map(h => h.bottom));
                const lowerTop = Math.min(...lower.map(h => h.top));

                if (lowerTop <= upperBottom) {
                    continue; // adjacent rows, nothing in between
                }

                const firstLine = nodes[upper[0].node].startLine;
                const lastLine = nodes[lower[0].node].startLine;
                let blank = true;

                for (let n = firstLine + 1; n < lastLine && blank; n++) {
                    blank = BLANK_OR_COMMENT.test(model.getLineContent(n));
                }

                if (!blank) {
                    continue;
                }

                const middle = (upperBottom + lowerTop) / 2;

                for (const hit of upper) {
                    hit.bottom = middle;
                }

                for (const hit of lower) {
                    hit.top = middle;
                }
            }
        }

        return hits;
    }

    // ---- Rendering ---------------------------------------------------------

    function render() {
        layer.textContent = '';
        state.boxes = [];
        state.hits = [];

        if (!state.enabled || state.nodes.length === 0) {
            hideTooltip();

            return;
        }

        const m = metrics();

        layer.style.left = m.originX + 'px';
        layer.style.width = Math.max(0, m.width) + 'px';
        layer.style.height = Math.max(0, m.height) + 'px';

        state.boxes = layoutBoxes(m);
        state.hits = hitRegions(state.boxes, m);

        const phase = value => -(((value % DASH_PERIOD) + DASH_PERIOD) % DASH_PERIOD) + 'px';
        const fragment = document.createDocumentFragment();

        for (const box of state.boxes) {
            const el = document.createElement('div');
            el.style.left = box.left + 'px';
            el.style.top = box.top + 'px';
            el.style.width = (box.right - box.left) + 'px';
            el.style.height = (box.bottom - box.top) + 'px';
            // The dash pattern is anchored to the layer, so coinciding edges paint the same pixels.
            el.style.setProperty('--xray-dx', phase(box.left));
            el.style.setProperty('--xray-dy', phase(box.top));
            box.el = el;
            fragment.appendChild(el);
        }

        layer.appendChild(fragment);
        applyHover();
    }

    function boxClass(index) {
        const nodes = state.nodes;
        let cls = 'xray-box' + (nodes[index].multiLine ? ' xray-box-block' : '');

        if (state.pinned === index) {
            cls += ' xray-box-pinned';
        }

        if (state.hovered < 0) {
            return cls;
        }

        if (state.hovered === index) {
            return cls + ' xray-box-hovered';
        }

        for (let p = nodes[state.hovered].parent; p >= 0; p = nodes[p].parent) {
            if (p === index) {
                return cls + ' xray-box-ancestor';
            }
        }

        return cls;
    }

    function applyHover() {
        for (const box of state.boxes) {
            box.el.className = boxClass(box.node);
        }

        if (state.hovered < 0) {
            hideTooltip();

            return;
        }

        showTooltip();
    }

    function setHovered(index) {
        if (state.hovered === index) {
            return;
        }

        state.hovered = index;
        applyHover();
    }

    // ---- Hover & tooltip ---------------------------------------------------

    let pointer = null;
    let frame = 0;

    function resolveHover() {
        frame = 0;

        if (pointer === null || !state.enabled) {
            setHovered(-1);

            return;
        }

        setHovered(nodeAt(pointer.clientX, pointer.clientY));
    }

    function nodeAt(clientX, clientY) {
        const rect = layer.getBoundingClientRect();
        const x = clientX - rect.left;
        const y = clientY - rect.top;
        const nodes = state.nodes;
        let hit = -1;

        // The innermost box under the pointer wins.
        for (const box of state.hits) {
            if (x >= box.left && x <= box.right && y >= box.top && y <= box.bottom
                && (hit < 0 || nodes[box.node].depth > nodes[hit].depth)) {
                hit = box.node;
            }
        }

        return hit;
    }

    function pin(index, reveal = false) {
        const valid = index >= 0 && index < state.nodes.length ? index : -1;

        if (valid >= 0 && reveal) {
            editor.revealLineInCenterIfOutsideViewport(state.nodes[valid].startLine);
        }

        if (state.pinned === valid) {
            return;
        }

        state.pinned = valid;
        // a full render: a pinned node may be one that is otherwise not drawn
        render();
        onPin(valid);
    }

    // A click pins the node under the pointer (it also places the cursor, as usual).
    editor.onMouseDown(e => {
        if (!state.enabled || !e.event.leftButton) {
            return;
        }

        const hit = nodeAt(e.event.browserEvent.clientX, e.event.browserEvent.clientY);

        if (hit >= 0) {
            pin(hit);
        }
    });

    editor.onKeyDown(e => {
        if (e.keyCode === monaco.KeyCode.Escape && state.pinned >= 0) {
            pin(-1);
        }
    });

    editor.onMouseMove(e => {
        pointer = {clientX: e.event.browserEvent.clientX, clientY: e.event.browserEvent.clientY};

        if (frame === 0) {
            frame = requestAnimationFrame(resolveHover);
        }
    });

    editor.onMouseLeave(() => {
        pointer = null;
        setHovered(-1);
    });

    function describeValue(value) {
        const nodes = state.nodes;

        if (typeof value === 'number') {
            return nodes[value] ? nodes[value].kind : '?';
        }

        if (typeof value === 'string') {
            return value.length > MAX_TOOLTIP_VALUE ? value.slice(0, MAX_TOOLTIP_VALUE) + '…' : value;
        }

        if (value.length === 0) {
            return '[]';
        }

        const kinds = value.map(v => typeof v === 'number' ? (nodes[v] ? nodes[v].kind : '?') : v);

        if (kinds.every(k => k === kinds[0])) {
            return kinds[0] + '[' + kinds.length + ']';
        }

        return '[' + kinds.slice(0, 3).join(', ') + (kinds.length > 3 ? ', …' : '') + ']';
    }

    function renderPopup() {
        const nodes = state.nodes;
        const node = nodes[state.hovered];
        const dom = document.createElement('div');
        dom.className = 'xray-popup';

        const path = [];

        for (let p = node.parent; p >= 0; p = nodes[p].parent) {
            path.unshift(nodes[p].kind);
        }

        if (path.length > 0) {
            const pathDom = document.createElement('div');
            pathDom.className = 'xray-popup-path';
            const shown = path.length > MAX_PATH ? ['…', ...path.slice(path.length - MAX_PATH)] : path;
            pathDom.textContent = shown.join(' › ') + ' ›';
            dom.appendChild(pathDom);
        }

        const kindDom = document.createElement('div');
        kindDom.className = 'xray-popup-kind';
        kindDom.textContent = node.kind;
        dom.appendChild(kindDom);

        const rows = document.createElement('div');
        rows.className = 'xray-popup-rows';

        const addRow = (label, value, cls) => {
            const labelDom = document.createElement('span');
            labelDom.className = 'xray-popup-label';
            labelDom.textContent = label;

            const valueDom = document.createElement('span');
            valueDom.className = cls;
            valueDom.textContent = value;

            rows.append(labelDom, valueDom);
        };

        if (node.target) {
            addRow('canMutate()', shortClass(node.target), 'xray-popup-target');
        }

        for (const prop of node.props) {
            addRow(prop.name, describeValue(prop.value), '');
        }

        dom.appendChild(rows);

        return dom;
    }

    function showTooltip() {
        const box = state.boxes.find(b => b.node === state.hovered);

        if (!box) {
            hideTooltip();

            return;
        }

        tooltip.textContent = '';
        tooltip.appendChild(renderPopup());
        tooltip.hidden = false;

        const boxRect = box.el.getBoundingClientRect();
        const tipRect = tooltip.getBoundingClientRect();
        const gap = 6;
        let top = boxRect.top - tipRect.height - gap;

        if (top < gap) {
            top = boxRect.bottom + gap;
        }

        const left = Math.max(gap, Math.min(boxRect.left, window.innerWidth - tipRect.width - gap));

        tooltip.style.top = top + 'px';
        tooltip.style.left = left + 'px';
    }

    function hideTooltip() {
        tooltip.hidden = true;
    }

    // ---- Wiring ------------------------------------------------------------

    editor.onDidChangeModelContent(() => {
        if (state.nodes.length > 0) {
            mapNodesThroughEdits();
        }

        setHovered(-1);
        render();
        scheduleFetch();
    });

    editor.onDidScrollChange(() => {
        render();
        // The pointer stays put while the code moves under it.
        if (pointer !== null && frame === 0) {
            frame = requestAnimationFrame(resolveHover);
        }
    });

    editor.onDidLayoutChange(render);
    editor.onDidChangeConfiguration(render);

    document.addEventListener('themeChanged', () => {
        applyTheme();
        render();
    });

    if (document.fonts && document.fonts.ready) {
        document.fonts.ready.then(() => {
            monaco.editor.remeasureFonts();
            render();
        });
    }

    applyTheme();

    if (initialData !== null) {
        applyData(initialData);
        setStatus(state.nodes.length + ' nodes. Hover over the code to inspect them, click to pin.', false);
    } else {
        fetchXRay();
    }

    return {
        editor,
        pin,
        nodes: () => state.nodes,
        pinned: () => state.pinned,
    };
}
