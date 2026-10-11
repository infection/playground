import monaco from '../monaco.js';

let diffEditor;
let codeEditor;
let testEditor;
let configEditor;
let sourceMarkers;

let mutants = [];
let selectedMutant = null;
const activeStatuses = new Set();

// a marker takes the color of the worst status on its line
const MARKER_BY_STATUS = {
    escaped: 'bad',
    uncovered: 'bad',
    timeouted: 'warn',
    errored: 'good',
    killed: 'good',
};

const MARKER_ORDER = ['bad', 'warn', 'good'];

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
        sourceMarkers = codeEditor.createDecorationsCollection([]);
        codeEditor.onMouseDown(selectMutantFromMarker);
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
        });

        container.appendChild(button);
    });
}

/**
 * Gutter dots and line highlights in the Source Code editor, colored by the worst status on the line.
 */
function showSourceMarkers() {
    if (!sourceMarkers) {
        return;
    }

    const mutantsByLine = getVisibleMutantsByLine();
    const decorations = [];

    mutantsByLine.forEach((lineMutants, line) => {
        const marker = lineMutants
            .map((mutant) => MARKER_BY_STATUS[mutant.status] || 'good')
            .sort((a, b) => MARKER_ORDER.indexOf(a) - MARKER_ORDER.indexOf(b))[0];
        const isSelected = lineMutants.includes(selectedMutant);

        decorations.push({
            range: new monaco.Range(line, 1, line, 1),
            options: {
                isWholeLine: true,
                className: 'mutant-line mutant-line-' + marker + (isSelected ? ' mutant-line-selected' : ''),
                glyphMarginClassName: 'mutant-glyph mutant-glyph-' + marker,
                glyphMarginHoverMessage: {
                    value: lineMutants
                        .map((mutant) => '#' + mutant.id + ' **' + mutant.mutator.mutatorName + '**: ' + mutant.status)
                        .join('  \n'),
                },
                overviewRuler: {
                    color: MARKER_COLORS[marker],
                    position: monaco.editor.OverviewRulerLane.Left,
                },
            },
        });
    });

    sourceMarkers.set(decorations);
}

function getVisibleMutantsByLine() {
    const mutantsByLine = new Map();

    mutants
        .filter((mutant) => mutant.sourceLine && activeStatuses.has(mutant.status))
        .forEach((mutant) => {
            if (!mutantsByLine.has(mutant.sourceLine)) {
                mutantsByLine.set(mutant.sourceLine, []);
            }

            mutantsByLine.get(mutant.sourceLine).push(mutant);
        });

    return mutantsByLine;
}

/**
 * A click on a marker selects the first mutant of its line, the next click the next one.
 */
function selectMutantFromMarker(event) {
    if (event.target.type !== monaco.editor.MouseTargetType.GUTTER_GLYPH_MARGIN) {
        return;
    }

    const lineMutants = getVisibleMutantsByLine().get(event.target.position.lineNumber);

    if (!lineMutants) {
        return;
    }

    const mutant = lineMutants[(lineMutants.indexOf(selectedMutant) + 1) % lineMutants.length];

    selectMutant(mutant);

    const row = document.querySelector('#mutants-table tr[data-mutant-id="' + mutant.id + '"]');

    if (row) {
        row.scrollIntoView({block: 'nearest'});
    }
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
