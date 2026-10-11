import monaco from '../monaco.js';

let diffEditor;
let codeEditor;
let testEditor;
let configEditor;
let sourceMarkers = null;
let markerTooltip = null;
let markerTooltipLine = null;
let hideMarkerTooltipTimeout = null;

let mutants = [];
let selectedMutant = null;
const activeStatuses = new Set();

// a line gets one dot per marker, and the color of its worst marker
const MARKER_BY_STATUS = {
    escaped: 'bad',
    uncovered: 'bad',
    timeouted: 'warn',
    errored: 'good',
    killed: 'good',
};

const MARKER_ORDER = ['bad', 'warn', 'good'];

// each marker has its own lane in the glyph margin, so the dots of a line sit side by side in this order
const MARKER_LANES = {
    bad: monaco.editor.GlyphMarginLane.Left,
    warn: monaco.editor.GlyphMarginLane.Center,
    good: monaco.editor.GlyphMarginLane.Right,
};

const BADGE_CLASSES = {
    escaped: 'mutant-badge-escaped',
    killed: 'mutant-badge-killed',
    errored: 'mutant-badge-errored',
    timeouted: 'mutant-badge-timeout',
    uncovered: 'mutant-badge-uncovered'
};

const MARKER_COLORS = {
    bad: '#ef4444',
    warn: '#eab308',
    good: '#22c55e',
};

export function initMutationEditors() {
    codeEditor = initCodeEditor();
    testEditor = initTestEditor();
    configEditor = initConfigEditor();

    // Set initial theme based on current mode
    updateEditorsTheme();

    // Listen for theme changes
    document.addEventListener('themeChanged', updateEditorsTheme);

    processMutantsDetails();

    document.getElementById('js-submit').addEventListener(
        'click',
        function () {
            disableButton(this);

            copyValuesFromEditorsToTextAreas(
                codeEditor,
                testEditor,
                configEditor
            );
            document.getElementsByName("create_example")[0].submit()

            return false;
        }
    );

    const copyUrlButton = document.getElementById('copy-url');

    if (copyUrlButton !== null) {
        copyUrlButton.addEventListener(
            'click',
            function () {
                document.getElementById('copy-btn-text').textContent = 'Copied!'
                copyUrlToClipboard()

                return false;
            }
        );
    }

    window.addEventListener('resize', function() {
        setTimeout(function () {
            codeEditor.layout();
            testEditor.layout();
            configEditor.layout();
        }, 50);

    }, true);
}

function processMutantsDetails() {
    const logElement = document.getElementById('json-log');

    if (logElement === null) {
        return;
    }

    const jsonLog = JSON.parse(logElement.dataset.log);
    mutants = addSourceLines(getAllMutants(jsonLog), readLineMap());

    if (mutants.length === 0) {
        showNoMutantsAlert();

        return;
    }

    mutants.forEach((mutant) => activeStatuses.add(mutant.status));

    showStatusFilter();
    showMutantsTable(mutants);

    if (codeEditor) {
        codeEditor.onMouseDown(selectMutantFromMarker);
        codeEditor.onMouseMove(showMarkerTooltipOnHover);
        codeEditor.onMouseLeave(scheduleHideMarkerTooltip);
        codeEditor.onDidScrollChange(hideMarkerTooltip);
    }

    selectMutant(mutants[0]);
}

function readLineMap() {
    const lineMapElement = document.getElementById('line-map');

    if (lineMapElement === null) {
        return {};
    }

    try {
        const lineMap = JSON.parse(lineMapElement.dataset.map);

        // PHP encodes an empty map as a list
        return Array.isArray(lineMap) ? {} : lineMap;
    } catch (e) {
        return {};
    }
}

/**
 * Infection reports the line of the class file the playground generated from the submitted code,
 * the line map translates it to the line of the Source Code editor.
 */
function addSourceLines(mutants, lineMap) {
    return mutants.map((mutant, index) => {
        const mutator = mutant.mutator;
        const className = (mutator.originalFilePath || '').split('/').pop().replace(/\.php$/, '');
        const classLines = lineMap[className] || {};

        return Object.assign({}, mutant, {
            id: index + 1,
            sourceLine: classLines[mutator.originalStartLine] || null,
        });
    });
}

function showNoMutantsAlert() {
    document.getElementById('mutants-log').classList.add('hidden');
    document.getElementById('no-mutations-alert').classList.remove('hidden');
}

function getAllMutants(jsonLog) {
    return []
        .concat(
            jsonLog.escaped.map((mutant) => {
                return Object.assign({}, mutant, {status: 'escaped'});
            })
        )
        .concat(
            jsonLog.killed.map((mutant) => {
                return Object.assign({}, mutant, {status: 'killed'});
            })
        )
        .concat(
            jsonLog.errored.map((mutant) => {
                return Object.assign({}, mutant, {status: 'errored'});
            })
        )
        .concat(
            jsonLog.timeouted.map((mutant) => {
                return Object.assign({}, mutant, {status: 'timeouted'});
            })
        )
        .concat(
            jsonLog.uncovered.map((mutant) => {
                return Object.assign({}, mutant, {status: 'uncovered'});
            })
        )
}

function showMutantsTable(mutants) {
    const tBody = document.getElementById('mutants-table').getElementsByTagName('tbody')[0];

    mutants.forEach((mutant, index) => {
        const td1 = document.createElement('td');
        td1.className = 'py-2.5 px-3';
        const span1 = document.createElement('span');
        span1.className = 'text-sm text-gray-700 dark:text-gray-300 flex items-center';
        span1.innerHTML = '<span class="text-gray-400 dark:text-gray-500 text-xs mr-2 font-mono">' + (index + 1) + '</span>' + mutant.mutator.mutatorName;

        if (mutant.sourceLine) {
            const lineSpan = document.createElement('span');
            lineSpan.className = 'text-gray-400 dark:text-gray-500 text-xs ml-2 font-mono';
            lineSpan.textContent = 'line ' + mutant.sourceLine;
            span1.appendChild(lineSpan);
        }

        td1.appendChild(span1);

        const td2 = document.createElement('td');
        td2.className = 'py-2.5 px-3 text-right';
        const span2 = document.createElement('span');
        span2.className = 'mutant-badge ' + (BADGE_CLASSES[mutant.status] || '');
        span2.textContent = mutant.status;
        td2.appendChild(span2);

        const tr = document.createElement('tr');
        tr.appendChild(td1);
        tr.className = 'mutant-row';
        tr.dataset.mutantId = String(mutant.id);
        tr.dataset.status = mutant.status;
        tr.appendChild(td2);

        tBody.appendChild(tr);

        tr.addEventListener('click',
            function () {
                selectMutant(mutant);

                return false;
            }
        );
    });
}

function selectMutant(mutant) {
    selectedMutant = mutant;

    showDiffEditor(mutant.mutator);
    showProcessOutput(mutant);

    getMutantRows().forEach((row) => {
        row.classList.toggle('mutant-row-active', row.dataset.mutantId === String(mutant.id));
    });

    showSourceMarkers();

    if (codeEditor && mutant.sourceLine) {
        codeEditor.revealLineInCenterIfOutsideViewport(mutant.sourceLine, monaco.editor.ScrollType.Smooth);
    }
}

function getMutantRows() {
    return Array.prototype.slice.call(document.getElementById('mutants-table').getElementsByTagName('tr'));
}

function showStatusFilter() {
    const container = document.getElementById('status-filter');

    if (container === null) {
        return;
    }

    const counts = {};

    mutants.forEach((mutant) => {
        counts[mutant.status] = (counts[mutant.status] || 0) + 1;
    });

    const label = document.createElement('span');
    label.className = 'text-xs text-gray-500 dark:text-gray-400 mr-1';
    label.textContent = 'Show';
    container.appendChild(label);

    Object.keys(counts).forEach((status) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'status-chip';
        button.setAttribute('aria-pressed', 'true');

        const badge = document.createElement('span');
        badge.className = 'mutant-badge ' + (BADGE_CLASSES[status] || '');
        badge.textContent = status + ' ' + counts[status];
        button.appendChild(badge);

        button.addEventListener('click', function () {
            if (activeStatuses.has(status)) {
                activeStatuses.delete(status);
            } else {
                activeStatuses.add(status);
            }

            button.setAttribute('aria-pressed', String(activeStatuses.has(status)));

            getMutantRows().forEach((row) => {
                row.classList.toggle('hidden', !activeStatuses.has(row.dataset.status));
            });

            showSourceMarkers();
            hideMarkerTooltip();
        });

        container.appendChild(button);
    });
}

/**
 * Gutter dots and line highlights in the Source Code editor: a dot for each marker of the line's mutants,
 * the line colored by its worst marker.
 */
function showSourceMarkers() {
    if (!codeEditor) {
        return;
    }

    const decorations = [];

    getVisibleMutantsByLine().forEach((lineMutants, line) => {
        const range = new monaco.Range(line, 1, line, 1);
        const markers = MARKER_ORDER.filter((marker) => lineMutants.some((mutant) => getMarker(mutant) === marker));
        const worstMarker = markers[0];

        decorations.push({
            range: range,
            options: {
                isWholeLine: true,
                className: 'mutant-line mutant-line-' + worstMarker + (lineMutants.includes(selectedMutant) ? ' mutant-line-selected' : ''),
                overviewRuler: {
                    color: MARKER_COLORS[worstMarker],
                    position: monaco.editor.OverviewRulerLane.Left,
                },
            },
        });

        markers.forEach((marker) => {
            const isSelected = selectedMutant !== null
                && selectedMutant.sourceLine === line
                && getMarker(selectedMutant) === marker;

            decorations.push({
                range: range,
                options: {
                    glyphMarginClassName: 'mutant-glyph mutant-glyph-' + marker + (isSelected ? ' mutant-glyph-selected' : ''),
                    glyphMargin: {
                        position: MARKER_LANES[marker],
                    },
                },
            });
        });
    });

    if (sourceMarkers === null) {
        sourceMarkers = codeEditor.createDecorationsCollection();
    }

    sourceMarkers.set(decorations);
}

function getMarker(mutant) {
    return MARKER_BY_STATUS[mutant.status] || 'good';
}

function getVisibleMutantsByLine() {
    const mutantsByLine = new Map();

    mutants
        .filter((mutant) => mutant.sourceLine && activeStatuses.has(mutant.status))
        // worst first, as the dots and the tooltip show them
        .sort((a, b) => MARKER_ORDER.indexOf(getMarker(a)) - MARKER_ORDER.indexOf(getMarker(b)) || a.id - b.id)
        .forEach((mutant) => {
            if (!mutantsByLine.has(mutant.sourceLine)) {
                mutantsByLine.set(mutant.sourceLine, []);
            }

            mutantsByLine.get(mutant.sourceLine).push(mutant);
        });

    return mutantsByLine;
}

/**
 * A click on a dot selects the first of its mutants, the next click the next one.
 */
function selectMutantFromMarker(event) {
    if (event.target.type !== monaco.editor.MouseTargetType.GUTTER_GLYPH_MARGIN) {
        return;
    }

    const lane = event.target.detail.glyphMarginLane;
    const lineMutants = (getVisibleMutantsByLine().get(event.target.position.lineNumber) || [])
        .filter((mutant) => lane === undefined || MARKER_LANES[getMarker(mutant)] === lane);

    if (lineMutants.length === 0) {
        return;
    }

    const mutant = lineMutants[(lineMutants.indexOf(selectedMutant) + 1) % lineMutants.length];

    selectMutant(mutant);
    scrollToMutantRow(mutant);

    if (markerTooltipLine !== null) {
        renderMarkerTooltip(markerTooltipLine);
    }
}

function scrollToMutantRow(mutant) {
    const row = document.querySelector('#mutants-table tr[data-mutant-id="' + mutant.id + '"]');
    const panel = row ? row.closest('.mutant-list-panel') : null;

    if (panel === null) {
        return;
    }

    // scrolls only the list: row.scrollIntoView() would scroll the page away from the clicked marker too
    const rowTop = row.getBoundingClientRect().top - panel.getBoundingClientRect().top + panel.scrollTop;

    if (rowTop < panel.scrollTop) {
        panel.scrollTop = rowTop;
    } else if (rowTop + row.offsetHeight > panel.scrollTop + panel.clientHeight) {
        panel.scrollTop = rowTop + row.offsetHeight - panel.clientHeight;
    }
}

/**
 * Monaco's own hover can only show Markdown, so the mutants of a line are listed in a tooltip of ours,
 * where each mutant opens its diff and output below the editors.
 */
function showMarkerTooltipOnHover(event) {
    if (event.target.type !== monaco.editor.MouseTargetType.GUTTER_GLYPH_MARGIN || !event.target.position) {
        scheduleHideMarkerTooltip();

        return;
    }

    const line = event.target.position.lineNumber;

    if (!getVisibleMutantsByLine().has(line)) {
        scheduleHideMarkerTooltip();

        return;
    }

    cancelHideMarkerTooltip();

    if (markerTooltipLine !== line) {
        renderMarkerTooltip(line);
    }
}

function renderMarkerTooltip(line) {
    const lineMutants = getVisibleMutantsByLine().get(line);

    if (!lineMutants) {
        hideMarkerTooltip();

        return;
    }

    const tooltip = getMarkerTooltip();

    const header = document.createElement('div');
    header.className = 'mutant-tooltip-header';
    header.textContent = 'Line ' + line + ' · ' + lineMutants.length + (lineMutants.length === 1 ? ' mutant' : ' mutants');

    const list = document.createElement('div');
    list.className = 'mutant-tooltip-list';

    lineMutants.forEach((mutant) => {
        const item = document.createElement('button');
        item.type = 'button';
        item.className = 'mutant-tooltip-item' + (mutant === selectedMutant ? ' mutant-tooltip-item-active' : '');

        const number = document.createElement('span');
        number.className = 'mutant-tooltip-id';
        number.textContent = '#' + mutant.id;

        const name = document.createElement('span');
        name.className = 'mutant-tooltip-name';
        name.textContent = mutant.mutator.mutatorName;

        const badge = document.createElement('span');
        badge.className = 'mutant-badge ' + (BADGE_CLASSES[mutant.status] || '');
        badge.textContent = mutant.status;

        item.append(number, name, badge);

        item.addEventListener('click', function () {
            selectMutant(mutant);
            scrollToMutantRow(mutant);
            hideMarkerTooltip();

            document.getElementById('mutants-log').scrollIntoView({behavior: 'smooth', block: 'start'});
        });

        list.appendChild(item);
    });

    tooltip.replaceChildren(header, list);

    positionMarkerTooltip(tooltip, line);

    markerTooltipLine = line;
}

function positionMarkerTooltip(tooltip, line) {
    const position = codeEditor.getScrolledVisiblePosition({lineNumber: line, column: 1});
    const editorRect = codeEditor.getDomNode().getBoundingClientRect();
    const layoutInfo = codeEditor.getLayoutInfo();

    if (!position) {
        hideMarkerTooltip();

        return;
    }

    tooltip.classList.remove('hidden');

    const left = editorRect.left + layoutInfo.glyphMarginLeft + layoutInfo.glyphMarginWidth + 4;
    let top = editorRect.top + position.top;

    // keep the tooltip inside the window, above the line when there is no room below
    if (top + tooltip.offsetHeight > window.innerHeight - 8) {
        top = Math.max(8, editorRect.top + position.top + position.height - tooltip.offsetHeight);
    }

    tooltip.style.left = left + 'px';
    tooltip.style.top = top + 'px';
}

function getMarkerTooltip() {
    if (markerTooltip === null) {
        markerTooltip = document.createElement('div');
        markerTooltip.className = 'mutant-tooltip hidden';
        markerTooltip.setAttribute('role', 'dialog');
        markerTooltip.addEventListener('mouseenter', cancelHideMarkerTooltip);
        markerTooltip.addEventListener('mouseleave', scheduleHideMarkerTooltip);

        document.body.appendChild(markerTooltip);
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                hideMarkerTooltip();
            }
        });
    }

    return markerTooltip;
}

// a delay lets the mouse move from the marker into the tooltip
function scheduleHideMarkerTooltip() {
    if (markerTooltip === null || hideMarkerTooltipTimeout !== null) {
        return;
    }

    hideMarkerTooltipTimeout = setTimeout(hideMarkerTooltip, 250);
}

function cancelHideMarkerTooltip() {
    clearTimeout(hideMarkerTooltipTimeout);
    hideMarkerTooltipTimeout = null;
}

function hideMarkerTooltip() {
    cancelHideMarkerTooltip();

    if (markerTooltip !== null) {
        markerTooltip.classList.add('hidden');
    }

    markerTooltipLine = null;
}

function showProcessOutput(mutant) {
    document.getElementById('mutant-output').textContent = mutant.processOutput;
}

function copyUrlToClipboard() {
    let input = document.getElementById('copy-url-input');

    if (!input) {
        input = document.createElement('input');

        input.id = 'copy-url-input';
        input.type = 'text';
        input.style = 'position: absolute; left: -1000px; top: -1000px;';

        document.body.appendChild(input);
    }

    input.value = window.location.toString();

    input.select();
    input.setSelectionRange(0, 99999); // For mobile devices

    document.execCommand('copy');

}

function disableButton(button) {
    button.setAttribute('disabled', 'disabled');

    button.classList.add('opacity-50');
    button.classList.add('cursor-not-allowed');
    button.classList.remove('hover:bg-green-500');
    button.classList.remove('hover:text-white');
    button.classList.remove('hover:border-transparent');
}

function copyValuesFromEditorsToTextAreas(codeEditor, testEditor, configEditor) {
    document.getElementById('create_example_code').textContent = codeEditor.getValue();
    document.getElementById('create_example_test').textContent = testEditor.getValue();
    document.getElementById('create_example_config').textContent = configEditor.getValue();
}

function showDiffEditor(mutator) {
    diffEditor = diffEditor ? diffEditor : createDiffEditor();

    const previousModel = diffEditor.getModel();

    // New models instead of setValue() on the old ones: only a new model collapses the unchanged regions again.
    diffEditor.setModel({
        original: monaco.editor.createModel(mutator.originalSourceCode, 'php'),
        modified: monaco.editor.createModel(mutator.mutatedSourceCode, 'php')
    });

    if (previousModel !== null) {
        previousModel.original.dispose();
        previousModel.modified.dispose();
    }
}

function createDiffEditor() {
    return monaco.editor.createDiffEditor(document.getElementById('editor-diff'), {
        enableSplitViewResizing: false,
        // Side by side only when the box is wide enough, inline otherwise (as in the 1/3 wide column)
        renderSideBySide: true,
        useInlineViewWhenSpaceIsLimited: true,
        // Show the mutated line with a few lines around it instead of the whole class.
        // The unfold icon on a hidden region shows its lines again.
        hideUnchangedRegions: {
            enabled: true,
            contextLineCount: 3,
            minimumLineCount: 3,
            revealLineCount: 10
        },
        readOnly: true,
        // the diff is read-only: no "revert change" arrows or gutter menu
        renderMarginRevertIcon: false,
        renderGutterMenu: false,
        renderOverviewRuler: false,
        minimap: {
            enabled: false
        },
        scrollBeyondLastLine: false,
        // follows the width of the box, which decides between side by side and inline
        automaticLayout: true,
        theme: isCurrentThemeDark() ? 'vs-dark' : 'vs'
    });
}

function initCodeEditor() {
    const loadedCodeElement = document.getElementById('loaded-code');
    const code = loadedCodeElement !== null
        ? loadedCodeElement.dataset.code
        : [
            '<?php',
            '',
            'declare(strict_types=1);',
            '',
            'namespace Infected;',
            '',
            'class SourceClass',
            '{',
            '    public function add(int $a, int $b): int',
            '    {',
            '        return $a + $b;',
            '    }',
            '}'
        ].join('\n');

    const editorNode = document.getElementById('editor-code');

    if (!editorNode) {
        return;
    }

    return monaco.editor.create(editorNode, {
        minimap: {
            enabled: false
        },
        // shows the mutant markers of a run
        glyphMargin: document.getElementById('json-log') !== null,
        value: code,
        language: 'php',
        theme: isCurrentThemeDark() ? 'vs-dark' : 'vs'
    });
}


function initTestEditor() {
    var loadedCodeElement = document.getElementById('loaded-test');
    var code = loadedCodeElement !== null
        ? loadedCodeElement.dataset.code
        : [
            '<?php',
            '',
            'declare(strict_types=1);',
            '',
            'namespace Infected\\Tests;',
            '',
            'use Infected\\SourceClass;',
            'use PHPUnit\\Framework\\TestCase;',
            '',
            'class SourceClassTest extends TestCase',
            '{',
            '    public function test_it_adds_2_numbers(): void',
            '    {',
            '        $source = new SourceClass();',
            '',
            '        $result = $source->add(1, 2);',
            '',
            '        self::assertSame(3, $result);',
            '    }',
            '}'
        ].join('\n');

    const editorNode = document.getElementById('editor-test');

    if (!editorNode) {
        return;
    }

    return monaco.editor.create(editorNode, {
        minimap: {
            enabled: false
        },
        value: code,
        language: 'php',
        theme: isCurrentThemeDark() ? 'vs-dark' : 'vs'
    });
}

function initConfigEditor() {
    var loadedCodeElement = document.getElementById('loaded-config');
    var code = loadedCodeElement !== null
        ? loadedCodeElement.dataset.code
        : [
            '{',
            '    "mutators": {',
            '        "@default": true',
            '    }',
            '}'
        ].join('\n');

    const editorNode = document.getElementById('editor-config');

    if (!editorNode) {
        return;
    }

    return monaco.editor.create(editorNode, {
        minimap: {
            enabled: false
        },
        value: code,
        language: 'json',
        theme: isCurrentThemeDark() ? 'vs-dark' : 'vs'
    });
}

/**
 * Check if the current theme is dark
 * @returns {boolean}
 */
function isCurrentThemeDark() {
    return document.documentElement.classList.contains('dark');
}

/**
 * Update the theme for all Monaco editors based on the current theme
 */
function updateEditorsTheme() {
    const theme = isCurrentThemeDark() ? 'vs-dark' : 'vs';

    monaco.editor.setTheme(theme);

    // Force layout update to ensure proper rendering
    if (codeEditor) {
        codeEditor.layout();
    }
    if (testEditor) {
        testEditor.layout();
    }
    if (configEditor) {
        configEditor.layout();
    }
    if (diffEditor) {
        diffEditor.layout();
    }
}
