import '../css/app.css';
import '../css/xray.css';
import '../css/ast.css';

import monaco from './monaco.js';
import {initXRayEditor} from './app/xray.js';

const MIN_EDITOR_HEIGHT = 240;
const MAX_EDITOR_HEIGHT = 640;

const DEFAULT_CODE = [
    '<?php',
    '',
    'declare(strict_types=1);',
    '',
    'namespace Infected;',
    '',
    'final class SourceClass',
    '{',
    '    private int $calls = 0;',
    '',
    '    public function add(int $a, int|float $b): int|float',
    '    {',
    '        $this->calls++;',
    '',
    '        if ($a > 0 && $b !== 0) {',
    '            return $a + $b;',
    '        }',
    '',
    '        return array_sum([$a, $b]) * -1;',
    '    }',
    '}',
].join('\n');

const root = document.getElementById('ast-app');

if (root) {
    initAstExplorer(root);
}

function initAstExplorer(root) {
    const initial = JSON.parse(document.getElementById('ast-initial').textContent);
    const baseUrl = root.dataset.baseUrl;
    const shareButton = document.getElementById('ast-share');
    const shareLabel = document.getElementById('ast-share-label');
    const details = document.getElementById('ast-details');

    // The last shared version of the code: links are only offered while the editor still shows it.
    let saved = initial.idHash ? {idHash: initial.idHash, code: initial.code} : null;

    // The callbacks also fire while the editor initializes, before `xray` is assigned; the page syncs right after.
    let ready = false;
    const xray = initXRayEditor({
        editorNode: document.getElementById('editor-code-ast'),
        statusNode: document.getElementById('xray-status'),
        endpoint: root.dataset.xrayEndpoint,
        code: initial.code ?? DEFAULT_CODE,
        initialData: initial.xray,
        onPin: () => {
            if (ready) {
                syncUrl();
                renderDetails();
            }
        },
        onData: () => {
            if (ready) {
                renderDetails();
            }
        },
    });
    const editor = xray.editor;
    ready = true;

    if (initial.activeNodeId !== null) {
        // Node ids in links are 1-based, in the same pre-order as the X-Ray nodes.
        xray.pin(initial.activeNodeId - 1, true);
    }

    editor.onDidChangeModelContent(syncUrl);
    syncUrl();
    renderDetails();

    // The editor grows with the code, within limits.
    const editorNode = document.getElementById('editor-code-ast');
    const fitHeight = () => {
        const height = Math.min(MAX_EDITOR_HEIGHT, Math.max(MIN_EDITOR_HEIGHT, editor.getContentHeight() + 16));

        if (editorNode.style.height !== height + 'px') {
            editorNode.style.height = height + 'px';
            editor.layout();
        }
    };
    editor.onDidContentSizeChange(fitHeight);
    fitHeight();

    // ---- URL & sharing -----------------------------------------------------

    function isSaved() {
        return saved !== null && editor.getValue() === saved.code;
    }

    function currentUrl() {
        if (!isSaved()) {
            return baseUrl;
        }

        // Node ids are only valid for the X-Ray data of the current text (it is stale while a request is pending).
        const pinned = xray.isSynced() ? xray.pinned() : -1;

        return baseUrl + '/' + saved.idHash + (pinned >= 0 ? '/' + (pinned + 1) : '');
    }

    function syncUrl() {
        const url = currentUrl();

        if (window.location.pathname !== url) {
            history.replaceState(null, '', url);
        }

        if (!shareButton.disabled) {
            shareLabel.textContent = isSaved() ? 'Copy link' : 'Share';
        }
    }

    shareButton.addEventListener('click', async () => {
        shareButton.disabled = true;

        try {
            if (!isSaved()) {
                shareLabel.textContent = 'Saving…';
                await save();
            }

            // A link with a pinned node must use the node ids of the shared code.
            await xray.sync();
            syncUrl();
            await copyToClipboard(window.location.origin + currentUrl());
            shareLabel.textContent = 'Link copied!';
        } catch (e) {
            shareLabel.textContent = e.message;
        }

        setTimeout(() => {
            shareButton.disabled = false;
            syncUrl();
        }, 1500);
    });

    async function save() {
        const response = await fetch(root.dataset.shareEndpoint, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({code: editor.getValue()}),
        });
        const payload = await response.json();

        if (payload.error) {
            throw new Error(payload.error.message);
        }

        // The server adds an opening tag when it is missing, the editor must show exactly what was saved.
        // An edit (unlike setValue) keeps the undo history and moves the node boxes along.
        const current = editor.getValue();

        if (payload.code !== current) {
            const model = editor.getModel();
            const range = payload.code.endsWith(current)
                ? new monaco.Range(1, 1, 1, 1)
                : model.getFullModelRange();
            const text = payload.code.endsWith(current)
                ? payload.code.slice(0, payload.code.length - current.length)
                : payload.code;

            editor.pushUndoStop();
            editor.executeEdits('ast-share', [{range, text}]);
            editor.pushUndoStop();
        }

        saved = {idHash: payload.idHash, code: payload.code};
    }

    async function copyToClipboard(text) {
        if (navigator.clipboard && window.isSecureContext) {
            await navigator.clipboard.writeText(text);

            return;
        }

        const input = document.createElement('textarea');
        input.value = text;
        input.style.position = 'fixed';
        input.style.left = '-1000px';
        document.body.appendChild(input);
        input.select();
        document.execCommand('copy');
        input.remove();
    }

    // ---- Details panel -----------------------------------------------------

    function renderDetails() {
        const nodes = xray.nodes();
        const pinned = xray.pinned();

        details.textContent = '';

        if (nodes.length === 0) {
            return;
        }

        if (pinned < 0) {
            details.appendChild(el('p', 'ast-details-hint', 'Click on any part of the code to pin its node and see what to use in Mutator::canMutate(). The whole AST:'));
            const pre = el('pre', 'ast-dump');
            nodes.forEach((node, index) => {
                if (node.parent < 0) {
                    pre.appendChild(dumpNode(index, ''));
                    pre.appendChild(document.createTextNode('\n'));
                }
            });
            details.appendChild(pre);

            return;
        }

        const node = nodes[pinned];

        const path = el('div', 'ast-details-path');
        const ancestors = [];

        for (let p = node.parent; p >= 0; p = nodes[p].parent) {
            ancestors.unshift(p);
        }

        ancestors.forEach(index => {
            path.appendChild(nodeLink(index));
            path.appendChild(document.createTextNode(' › '));
        });

        const header = el('div', 'ast-details-header');
        header.appendChild(el('span', 'ast-details-kind', node.kind));
        const unpin = el('button', 'ast-details-unpin', 'Unpin (Esc)');
        unpin.type = 'button';
        unpin.addEventListener('click', () => xray.pin(-1));
        header.appendChild(unpin);

        const target = el('div', 'ast-details-target');
        target.appendChild(el('span', 'ast-details-label', 'In Mutator::canMutate() method you can use the following node to mutate it:'));
        target.appendChild(el('code', 'ast-details-target-class', node.target));

        const pre = el('pre', 'ast-dump');
        pre.appendChild(dumpNode(pinned, ''));

        details.append(path, header, target, pre);
    }

    // Renders a node like php-parser's NodeDumper; child node kinds are links that pin them.
    function dumpNode(index, indent) {
        const nodes = xray.nodes();
        const node = nodes[index];
        const fragment = document.createDocumentFragment();
        const inner = indent + '    ';

        fragment.appendChild(nodeLink(index));
        fragment.appendChild(document.createTextNode('('));

        for (const prop of node.props) {
            fragment.appendChild(document.createTextNode('\n' + inner));
            fragment.appendChild(el('span', 'ast-dump-name', prop.name));
            fragment.appendChild(document.createTextNode(': '));
            fragment.appendChild(dumpValue(prop.value, inner));
        }

        fragment.appendChild(document.createTextNode((node.props.length > 0 ? '\n' + indent : '') + ')'));

        return fragment;
    }

    function dumpValue(value, indent) {
        if (typeof value === 'number') {
            return dumpNode(value, indent);
        }

        if (typeof value === 'string') {
            return el('span', 'ast-dump-scalar', value);
        }

        const fragment = document.createDocumentFragment();

        if (value.length === 0) {
            fragment.appendChild(document.createTextNode('[]'));

            return fragment;
        }

        const inner = indent + '    ';
        fragment.appendChild(document.createTextNode('['));

        value.forEach((item, i) => {
            fragment.appendChild(document.createTextNode('\n' + inner + i + ': '));
            fragment.appendChild(dumpValue(item, inner));
        });

        fragment.appendChild(document.createTextNode('\n' + indent + ']'));

        return fragment;
    }

    function nodeLink(index) {
        const link = el('a', 'ast-node-link' + (index === xray.pinned() ? ' ast-node-link-active' : ''), xray.nodes()[index].kind);
        link.href = '#';
        link.addEventListener('click', e => {
            e.preventDefault();
            xray.pin(index, true);
        });

        return link;
    }

    function el(tag, className, text) {
        const element = document.createElement(tag);
        element.className = className;

        if (text !== undefined) {
            element.textContent = text;
        }

        return element;
    }
}
