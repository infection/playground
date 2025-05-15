import * as monaco from 'monaco-editor';

let diffEditor;
let codeEditor;
let testEditor;
let configEditor;

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
    const mutants = getAllMutants(jsonLog);

    if (mutants.length === 0) {
        showNoMutantsAlert();

        return;
    }

    const firstMutant = mutants[0];

    showMutantsTable(mutants)
    showDiffEditor(firstMutant.mutator);
    showProcessOutput(firstMutant);
    highlightRow(document.getElementById('mutants-table').getElementsByTagName('tr')[0]);
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

    const colorsMap = {
        escaped: 'red',
        killed: 'green',
        errored: 'green',
        timeouted: 'yellow',
        uncovered: 'red'
    }

    mutants.forEach((mutant, index) => {
        const td1 = document.createElement('td');

        td1.className = 'border-dashed border-t border-gray-200 dark:border-gray-700';
        const span1 = document.createElement('span');
        span1.className = 'text-gray-700 dark:text-gray-300 px-6 py-3 flex items-center';
        span1.textContent = `${index + 1}. ` + mutant.mutator.mutatorName; // + ', Line: ' + mutant.mutator.originalStartLine;
        td1.appendChild(span1);
        const td2 = document.createElement('td');

        td1.className = 'border-dashed border-t border-gray-200 dark:border-gray-700';
        const span2 = document.createElement('span');
        span2.className = 'rounded py-1 px-3 text-xs font-bold' + ' bg-' + colorsMap[mutant.status] + '-400';
        span2.textContent = mutant.status;
        td2.appendChild(span2);

        const tr = document.createElement('tr');
        tr.appendChild(td1);
        tr.className = 'cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-700';
        tr.appendChild(td2);

        tBody.appendChild(tr);

        tr.addEventListener('click',
            function () {
                showDiffEditor(mutant.mutator);
                showProcessOutput(mutant);
                unhighlightAllRows(tBody);
                highlightRow(tr);

                return false;
            }
        );
    });
}

function unhighlightAllRows() {
    const htmlCollection = document.getElementById('mutants-table').getElementsByTagName('tr');
    const rows = Array.prototype.slice.call(htmlCollection);

    rows.forEach((row) => {
        row.classList.remove('bg-gray-200', 'dark:bg-gray-700');
    });
}

function highlightRow(row) {
    row.classList.add('bg-gray-200', 'dark:bg-gray-700');
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
    const originalModel = monaco.editor.createModel(mutator.originalSourceCode, 'php');
    const modifiedModel = monaco.editor.createModel(mutator.mutatedSourceCode, 'php');

    diffEditor = diffEditor ? diffEditor : monaco.editor.createDiffEditor(document.getElementById('editor-diff'), {
        enableSplitViewResizing: false,
        // Render the diff inline
        renderSideBySide: false,
        readOnly: true,
        theme: isCurrentThemeDark() ? 'vs-dark' : 'vs'
    });

    diffEditor.setModel({
        original: originalModel,
        modified: modifiedModel
    });
}

export function initAstEditor() {
    const loadedCodeElement = document.getElementById('loaded-code');
    const code = loadedCodeElement !== null
        ? loadedCodeElement.dataset.code
        : [
            '<?php',
            '',
            'function add(int $a, int $b): int',
            '{',
            '    return $a + $b;',
            '}',
        ].join('\n');

    const editorNode = document.getElementById('editor-code-ast');

    if (!editorNode) {
        return;
    }

    const codeEditor = monaco.editor.create(editorNode, {
        minimap: {
            enabled: false
        },
        value: code,
        language: 'php',
        theme: isCurrentThemeDark() ? 'vs-dark' : 'vs'
    });

    // Set initial theme based on current mode
    updateEditorsTheme();

    // Listen for theme changes
    document.addEventListener('themeChanged', updateEditorsTheme);

    document.getElementById('js-submit').addEventListener(
        'click',
        function () {
            disableButton(this);

            document.getElementById('create_ast_run_code').textContent = codeEditor.getValue();
            document.getElementsByName("create_ast_run")[0].submit()

            return false;
        }
    );

    window.addEventListener('resize', function() {
        setTimeout(function () {
            codeEditor.layout();
        }, 50);

    }, true);
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
