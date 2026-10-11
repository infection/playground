import '../css/app.css';
import '../css/xray.css';

import {initXRayEditor} from './app/xray';

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

const editorNode = document.getElementById('editor-code-ast2');

if (editorNode) {
    initXRayEditor({
        editorNode,
        statusNode: document.getElementById('xray-status'),
        endpoint: editorNode.dataset.endpoint,
        code: DEFAULT_CODE,
    });
}
