import * as monaco from 'monaco-editor';

export function initEditors() {
    const codeEditor = initCodeEditor();
    const testEditor = initTestEditor();
    const configEditor = initConfigEditor();

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
    button.classList.remove('hover:bg-teal-500');
    button.classList.remove('hover:text-white');
    button.classList.remove('hover:border-transparent');
}

function copyValuesFromEditorsToTextAreas(codeEditor, testEditor, configEditor) {
    document.getElementById('create_example_code').textContent = codeEditor.getValue();
    document.getElementById('create_example_test').textContent = testEditor.getValue();
    document.getElementById('create_example_config').textContent = configEditor.getValue();
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

    return monaco.editor.create(document.getElementById('editor-code'), {
        minimap: {
            enabled: false
        },
        value: code,
        language: 'php'
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
        ].join('\n')

    return monaco.editor.create(document.getElementById('editor-test'), {
        minimap: {
            enabled: false
        },
        value: code,
        language: 'php'
    });
}

function initConfigEditor() {
    var loadedCodeElement = document.getElementById('loaded-config');
    var code = loadedCodeElement !== null
        ? loadedCodeElement.dataset.code
        : [
            '{',
            '    "bootstrap": "./autoload.php",',
            '    "timeout": 10,',
            '    "source": {',
            '        "directories": [',
            '            "src"',
            '        ]',
            '    },',
            '    "phpUnit": {',
            '        "customPath": "..\/phpunit.phar"',
            '    },',
            '    "tmpDir": ".",',
            '    "logs": {',
            '        "text": "php://stdout"',
            '    },',
            '    "mutators": {',
            '        "@default": true',
            '    }',
            '}'
        ].join('\n');

    return monaco.editor.create(document.getElementById('editor-config'), {
        minimap: {
            enabled: false
        },
        value: code,
        language: 'json',
        readOnly: true,
    });
}
