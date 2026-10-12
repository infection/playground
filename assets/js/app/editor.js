import monaco from '../monaco.js';
import {currentTheme, EDITOR_OPTIONS} from './theme.js';

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
let sortOrder = 'status';
const activeStatuses = new Set();

/**
 * label, Infection's progress symbol, order of "worst first", and the marker in the Source Code editor.
 * A line gets one dot per marker, and the color of its worst marker.
 */
const STATUSES = {
    escaped: {label: 'Escaped', symbol: 'M', rank: 0, color: 'var(--escaped)', marker: 'bad'},
    uncovered: {label: 'Uncovered', symbol: 'U', rank: 1, color: 'var(--uncovered)', marker: 'warn'},
    timeouted: {label: 'Timed out', symbol: 'T', rank: 2, color: 'var(--timeout)', marker: 'warn'},
    errored: {label: 'Errored', symbol: 'E', rank: 3, color: 'var(--errored)', marker: 'good'},
    killed: {label: 'Killed', symbol: '.', rank: 4, color: 'var(--killed)', marker: 'good'},
};

const STATUS_EXPLANATIONS = {
    escaped: 'The tests passed with this change in place, so no test checks this behavior.',
    uncovered: 'No test executes this line, so Infection did not run any test for this mutant.',
    timeouted: 'The tests ran longer than the timeout with this change. Infection counts it as detected.',
    errored: 'The tests ended with a fatal error with this change. Infection counts it as detected.',
    killed: 'A test failed with this change in place, so the tests detect it.',
};

const MARKER_ORDER = ['bad', 'warn', 'good'];

// each marker has its own lane in the glyph margin, so the dots of a line sit side by side in this order
const MARKER_LANES = {
    bad: monaco.editor.GlyphMarginLane.Left,
    warn: monaco.editor.GlyphMarginLane.Center,
    good: monaco.editor.GlyphMarginLane.Right,
};

const IS_MAC = /Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent);

export function initMutationEditors() {
    codeEditor = initEditor('editor-code', 'loaded-code', DEFAULT_CODE, 'php', {
        // shows the mutant markers of a run
        glyphMargin: document.getElementById('json-log') !== null,
    });
    testEditor = initEditor('editor-test', 'loaded-test', DEFAULT_TEST, 'php');
    configEditor = initEditor('editor-config', 'loaded-config', DEFAULT_CONFIG, 'json');

    document.addEventListener('themeChanged', () => {
        monaco.editor.setTheme(currentTheme());
        // the overview ruler takes the status colors of the theme
        showSourceMarkers();
    });

    if (!IS_MAC) {
        document.querySelectorAll('.btn-run kbd, .kbd-run').forEach((kbd) => kbd.textContent = 'Ctrl↵');
    }

    initTabs();
    initRun();
    initShare();
    initKeyboard();

    processMutantsDetails();
    watchForChangesSinceRun();
}

// ---- Editors ---------------------------------------------------------------

function initEditor(nodeId, loadedId, defaultValue, language, options = {}) {
    const editorNode = document.getElementById(nodeId);

    if (!editorNode) {
        return null;
    }

    const loaded = document.getElementById(loadedId);

    const editor = monaco.editor.create(editorNode, Object.assign({}, EDITOR_OPTIONS, {
        value: loaded !== null ? loaded.dataset.code : defaultValue,
        language: language,
        theme: currentTheme(),
    }, options));

    // Ctrl/Cmd + Enter runs Infection from inside the editors too
    editor.addCommand(monaco.KeyMod.CtrlCmd | monaco.KeyCode.Enter, submit);

    return editor;
}

const DEFAULT_CODE = [
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

const DEFAULT_TEST = [
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

const DEFAULT_CONFIG = [
    '{',
    '    "mutators": {',
    '        "@default": true',
    '    }',
    '}'
].join('\n');

// ---- Tabs ------------------------------------------------------------------

function initTabs() {
    const workbench = document.getElementById('workbench');

    // Test / infection.json5
    document.querySelectorAll('[data-side-tab]').forEach((tab) => {
        tab.addEventListener('click', () => showSideTab(tab.dataset.sideTab));
    });

    // Mutants / Console output
    document.querySelectorAll('[data-result-tab]').forEach((tab) => {
        tab.addEventListener('click', () => {
            selectTab('[data-result-tab]', tab);
            document.getElementById('results-mutants').hidden = tab.dataset.resultTab !== 'mutants';
            document.getElementById('results-console').hidden = tab.dataset.resultTab !== 'console';
        });
    });

    // Diff / Test output of the selected mutant
    document.querySelectorAll('[data-detail-tab]').forEach((tab) => {
        tab.addEventListener('click', () => {
            selectTab('[data-detail-tab]', tab);
            document.getElementById('editor-diff').hidden = tab.dataset.detailTab !== 'diff';
            document.getElementById('mutant-output').hidden = tab.dataset.detailTab !== 'output';
        });
    });

    // the bottom bar of narrow screens: one pane at a time
    document.querySelectorAll('.mtabs [data-tab]').forEach((tab) => {
        tab.addEventListener('click', () => showPane(tab.dataset.tab));
    });

    if (workbench && (workbench.dataset.tab === 'test' || workbench.dataset.tab === 'config')) {
        showSideTab(workbench.dataset.tab);
    }
}

function showSideTab(name) {
    selectTab('[data-side-tab]', document.querySelector('[data-side-tab="' + name + '"]'));
    document.getElementById('editor-test').hidden = name !== 'test';
    document.getElementById('editor-config').hidden = name !== 'config';
    document.getElementById('side-meta').textContent = name === 'test'
        ? 'PHPUnit test case'
        : 'Validated against Infection\'s JSON schema';
}

function showPane(name) {
    const workbench = document.getElementById('workbench');

    workbench.dataset.tab = name;
    document.querySelectorAll('.mtabs [data-tab]').forEach((tab) => {
        tab.setAttribute('aria-selected', String(tab.dataset.tab === name));
    });

    if (name === 'test' || name === 'config') {
        showSideTab(name);
    }

    window.scrollTo({top: 0});
}

function selectTab(selector, selected) {
    document.querySelectorAll(selector).forEach((tab) => {
        tab.setAttribute('aria-selected', String(tab === selected));
    });
}

// ---- Run, share, keyboard --------------------------------------------------

function initRun() {
    document.getElementById('js-submit').addEventListener('click', submit);

    // Back after a run can restore this page from the cache as it was when submitted
    window.addEventListener('pageshow', (event) => {
        if (event.persisted) {
            resetRunState();
        }
    });

    const rerun = document.getElementById('rerun');

    if (rerun !== null) {
        rerun.addEventListener('click', submit);
    }
}

function submit() {
    const button = document.getElementById('js-submit');

    if (button.disabled || button.getAttribute('aria-disabled') === 'true') {
        return;
    }

    button.disabled = true;
    button.classList.add('is-running');
    button.querySelector('.run-label').textContent = 'Running';

    // the request takes as long as the run: the results pane says so meanwhile
    hideMarkerTooltip();
    document.getElementById('workbench').classList.add('is-running');
    document.getElementById('run-state').hidden = false;

    if (window.matchMedia('(max-width: 900px)').matches) {
        showPane('results');
    }

    document.getElementById('create_example_code').value = codeEditor.getValue();
    document.getElementById('create_example_test').value = testEditor.getValue();
    document.getElementById('create_example_config').value = configEditor.getValue();

    document.getElementsByName('create_example')[0].submit();
}

function resetRunState() {
    const button = document.getElementById('js-submit');

    button.disabled = false;
    button.classList.remove('is-running');
    button.querySelector('.run-label').textContent = 'Run';
    document.getElementById('workbench').classList.remove('is-running');
    document.getElementById('run-state').hidden = true;
}

function initShare() {
    const copyButton = document.getElementById('copy-url');

    if (copyButton === null) {
        return;
    }

    copyButton.addEventListener('click', async () => {
        const input = document.getElementById('share-url');

        input.value = window.location.toString();

        try {
            await navigator.clipboard.writeText(input.value);
        } catch (e) {
            input.select();
            document.execCommand('copy');
        }

        copyButton.textContent = 'Copied';
        setTimeout(() => copyButton.textContent = 'Copy', 1500);
    });
}

/**
 * Ctrl/Cmd + Enter runs, J/K go to the next/previous mutant, N to the next escaped one.
 */
function initKeyboard() {
    document.addEventListener('keydown', (event) => {
        if ((event.metaKey || event.ctrlKey) && event.key === 'Enter') {
            event.preventDefault();
            submit();

            return;
        }

        if (event.key === 'Escape') {
            hideMarkerTooltip();

            return;
        }

        const target = event.target;
        const isTyping = target.closest('.monaco-editor') !== null || /^(INPUT|TEXTAREA|SELECT)$/.test(target.tagName);

        if (isTyping || event.metaKey || event.ctrlKey || event.altKey || mutants.length === 0) {
            return;
        }

        const inList = target.closest('#mutants-list') !== null;

        if (event.key === 'j' || (inList && event.key === 'ArrowDown')) {
            event.preventDefault();
            stepMutant(1);
        } else if (event.key === 'k' || (inList && event.key === 'ArrowUp')) {
            event.preventDefault();
            stepMutant(-1);
        } else if (event.key === 'n') {
            selectNextEscaped();
        }
    });
}

/**
 * The results are of the code as it was run: once it is edited, the markers fade and the results say so.
 * Until then Run would open this same run again, unless the run used older versions than a new run does.
 */
function watchForChangesSinceRun() {
    if (document.getElementById('stale-note') === null) {
        return;
    }

    const editors = [codeEditor, testEditor, configEditor];
    const runValues = editors.map((editor) => editor.getValue());
    const button = document.getElementById('js-submit');
    const runTitle = button.title;

    const update = () => {
        const stale = editors.some((editor, index) => editor.getValue() !== runValues[index]);

        document.getElementById('editor-code').classList.toggle('is-stale', stale);
        document.getElementById('stale-note').hidden = !stale;

        if (stale) {
            hideMarkerTooltip();
        }

        if (button.dataset.runOnCurrentVersions !== undefined) {
            button.setAttribute('aria-disabled', String(!stale));
            button.title = stale ? runTitle : 'Nothing changed since this run: edit the code, test or config to run again';
        }
    };

    update();
    editors.forEach((editor) => editor.onDidChangeModelContent(update));
}

// ---- Mutants ---------------------------------------------------------------

function processMutantsDetails() {
    const logElement = document.getElementById('json-log');

    if (logElement === null) {
        return;
    }

    const jsonLog = JSON.parse(logElement.dataset.log);
    mutants = addSourceLines(getAllMutants(jsonLog), readLineMap());

    showMetrics(jsonLog.stats);

    if (mutants.length === 0) {
        showNoMutantsAlert();

        return;
    }

    mutants.forEach((mutant) => activeStatuses.add(mutant.status));

    document.getElementById('mutants-count').textContent = String(mutants.length);
    document.getElementById('run-strip').hidden = false;
    document.getElementById('mutants-list').hidden = false;
    document.getElementById('results-gutter').hidden = false;
    document.getElementById('mutant-detail').hidden = false;

    showSourceMeta();
    showResultsBadge();
    showStrip();
    showStatusFilter();
    showMutantsList();

    if (codeEditor) {
        codeEditor.onMouseDown(selectMutantFromMarker);
        codeEditor.onMouseMove(showMarkerTooltipOnHover);
        codeEditor.onMouseLeave(scheduleHideMarkerTooltip);
        codeEditor.onDidScrollChange(hideMarkerTooltip);
    }

    document.getElementById('mutants-list').addEventListener('click', (event) => {
        const row = event.target.closest('[data-mutant-id]');

        if (row !== null) {
            selectMutant(findMutant(row.dataset.mutantId), {scrollList: false});
        }
    });

    document.getElementById('strip').addEventListener('click', (event) => {
        const cell = event.target.closest('[data-mutant-id]');

        if (cell !== null) {
            selectMutant(findMutant(cell.dataset.mutantId));
        }
    });

    // the first escaped mutant, as the list shows them worst first
    selectMutant(getVisibleMutants()[0], {scrollList: false});
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
 * The mutants are numbered in the order of their lines, so #1 is the topmost.
 */
function addSourceLines(mutants, lineMap) {
    return mutants
        .map((mutant, index) => {
            const mutator = mutant.mutator;
            const className = (mutator.originalFilePath || '').split('/').pop().replace(/\.php$/, '');
            const classLines = lineMap[className] || {};

            return Object.assign({}, mutant, {
                logIndex: index,
                sourceLine: classLines[mutator.originalStartLine] || null,
                change: getChange(mutator),
            });
        })
        .sort((a, b) => (a.sourceLine || Infinity) - (b.sourceLine || Infinity)
            || (a.mutator.originalStartLine || 0) - (b.mutator.originalStartLine || 0)
            || a.logIndex - b.logIndex)
        .map((mutant, index) => Object.assign(mutant, {id: index + 1}));
}

/**
 * The part of the code the mutator changed, e.g. ">" and ">=", for the list and the line in the editor.
 */
function getChange(mutator) {
    const original = (mutator.originalSourceCode || '').split('\n');
    const mutated = (mutator.mutatedSourceCode || '').split('\n');

    let start = 0;

    while (start < original.length && start < mutated.length && original[start] === mutated[start]) {
        start++;
    }

    let end = 0;

    while (end < original.length - start && end < mutated.length - start
        && original[original.length - 1 - end] === mutated[mutated.length - 1 - end]) {
        end++;
    }

    const from = tokenize(original.slice(start, original.length - end).join(' '));
    const to = tokenize(mutated.slice(start, mutated.length - end).join(' '));

    // the tokens between the common start and the common end of the two
    let prefix = 0;

    while (prefix < from.length && prefix < to.length && from[prefix] === to[prefix]) {
        prefix++;
    }

    let suffix = 0;

    while (suffix < from.length - prefix && suffix < to.length - prefix
        && from[from.length - 1 - suffix] === to[to.length - 1 - suffix]) {
        suffix++;
    }

    return {
        from: shorten(joinTokens(from.slice(prefix, from.length - suffix))),
        to: shorten(joinTokens(to.slice(prefix, to.length - suffix))),
    };
}

// a space only between two words, e.g. "$prices as" but "-1" and "[]"
function joinTokens(tokens) {
    return tokens.reduce((code, token, index) => {
        const between = index > 0 && /\w$/.test(tokens[index - 1]) && /^[$\w]/.test(token) ? ' ' : '';

        return code + between + token;
    }, '');
}

// PHP tokens, roughly: variables and words, multi-character operators, any other single character
const TOKEN = /\$?\w+|<=>|===|!==|\?\?=|\*\*=|==|!=|<=|>=|&&|\|\||\+\+|--|\+=|-=|\*=|\/=|\.=|%=|\?\?|->|=>|::|\*\*|<<|>>|\S/g;

function tokenize(code) {
    return code.match(TOKEN) || [];
}

function shorten(text) {
    return text.length > 48 ? text.slice(0, 47) + '…' : text;
}

function getAllMutants(jsonLog) {
    return Object.keys(STATUSES).flatMap((status) => (jsonLog[status] || []).map((mutant) => {
        return Object.assign({}, mutant, {status: status});
    }));
}

function findMutant(id) {
    return mutants.find((mutant) => mutant.id === Number(id));
}

function getVisibleMutants() {
    const visible = mutants.filter((mutant) => activeStatuses.has(mutant.status));

    if (sortOrder === 'status') {
        return visible.sort((a, b) => STATUSES[a.status].rank - STATUSES[b.status].rank || a.id - b.id);
    }

    return visible.sort((a, b) => a.id - b.id);
}

function showSourceMeta() {
    const escaped = mutants.filter((mutant) => mutant.status === 'escaped').length;
    const lines = new Set(mutants.map((mutant) => mutant.sourceLine).filter(Boolean)).size;
    const meta = document.getElementById('source-meta');

    meta.replaceChildren();

    if (escaped > 0) {
        meta.append(element('span', 'dot'), escaped + ' escaped · ');
    }

    meta.append(plural(mutants.length, 'mutant') + (lines > 0 ? ' on ' + plural(lines, 'line') : ''));
}

function showResultsBadge() {
    const escaped = mutants.filter((mutant) => mutant.status === 'escaped').length;
    const badge = document.getElementById('mtab-badge');

    badge.textContent = String(escaped);
    badge.hidden = escaped === 0;
    badge.setAttribute('aria-label', plural(escaped, 'escaped mutant'));
}

function showNoMutantsAlert() {
    document.getElementById('no-mutations-alert').hidden = false;
    document.getElementById('mutants-count').textContent = '0';
}

// ---- Metrics ---------------------------------------------------------------

/**
 * MSI, Covered MSI and MCC as Infection calculates them (Infection\Metrics\Calculator),
 * each on the scale of Infection's thresholds, with the formulas filled in with the counts of this run.
 */
function showMetrics(stats) {
    const verdict = document.getElementById('verdict');

    if (verdict === null || !stats) {
        return;
    }

    // logs of older Infection versions don't have all the counts
    const count = (key) => stats[key] || 0;

    const total = count('totalMutantsCount') - count('skippedCount') - count('ignoredCount');

    if (total === 0) {
        return;
    }

    const covered = total - count('notCoveredCount');
    const detectedTerms = [
        ['killed', count('killedCount'), true],
        ['killed by SA', count('killedByStaticAnalysisCount'), false],
        ['timed out', count('timeOutCount'), true],
        ['errors', count('errorCount'), true],
        ['syntax errors', count('syntaxErrorCount'), false],
    ].filter(([, value, alwaysShown]) => alwaysShown || value > 0);

    const detected = detectedTerms.reduce((sum, [, value]) => sum + value, 0);
    const detectedNames = '(' + detectedTerms.map(([name]) => name).join(' + ') + ')';
    const detectedValues = '(' + detectedTerms.map(([, value]) => value).join(' + ') + ')';
    const minimums = readConfiguredMinimums();

    const scores = element('div', 'scores');
    scores.append(
        buildScore({
            title: 'MSI',
            name: 'Mutation Score Indicator: the share of all mutants that the tests detect. This is the main score.',
            value: stats.msi,
            minimumKey: 'minMsi',
            minimum: minimums.minMsi,
            primary: true,
        }),
        buildScore({
            title: 'Covered MSI',
            name: 'Covered Code MSI: the MSI of the code the tests execute, how strong the existing tests are.',
            value: stats.coveredCodeMsi,
            minimumKey: 'minCoveredMsi',
            minimum: minimums.minCoveredMsi,
        }),
        buildScore({
            title: 'MCC',
            name: 'Mutation Code Coverage: the share of mutants on lines the tests execute.',
            value: stats.mutationCodeCoverage,
        })
    );

    const grid = element('dl', 'formula-grid');

    [
        ['MSI', detectedNames + ' / total', detectedValues + ' / ' + total, stats.msi],
        ['Covered MSI', detectedNames + ' / (total − uncovered)', detectedValues + ' / ' + covered, stats.coveredCodeMsi],
        ['MCC', '(total − uncovered) / total', covered + ' / ' + total, stats.mutationCodeCoverage],
    ].forEach(([title, formula, counts, value]) => {
        if (typeof value !== 'number') {
            return;
        }

        const dd = element('dd');
        dd.append(formula + ' ', element('span', '', '='), ' ' + counts + ' ', element('span', '', '='), ' ' + value + '%');
        grid.append(element('dt', '', title), dd);
    });

    const legend = element('div', 'formula-legend');

    [['low', 'low, below 50%'], ['medium', 'medium, 50–90%'], ['high', 'high, 90% and above']].forEach(([level, label]) => {
        const item = element('span', 'level-' + level);
        item.append(element('i'), label);
        legend.appendChild(item);
    });

    if (typeof minimums.minMsi === 'number' || typeof minimums.minCoveredMsi === 'number') {
        legend.appendChild(element('span', '', 'Infection fails the run when a score is below its minimum from infection.json5.'));
    }

    grid.appendChild(legend);

    const formulas = element('details', 'formulas');
    formulas.append(element('summary', '', 'How these scores are calculated'), grid);

    verdict.replaceChildren(scores, formulas);
    verdict.hidden = false;
}

/**
 * The thresholds of Infection's console output and HTML report.
 */
function getMetricLevel(value) {
    if (value < 50) {
        return 'low';
    }

    return value < 90 ? 'medium' : 'high';
}

function buildScore(score) {
    const container = element('div', 'score' + (score.primary ? ' score-primary' : ''));

    if (typeof score.value !== 'number') {
        return container;
    }

    const level = getMetricLevel(score.value);

    const label = element('div', 'score-label');
    const abbr = element('abbr', '', score.title);
    abbr.title = score.name;
    label.appendChild(abbr);

    if (typeof score.minimum === 'number') {
        const passed = score.value >= score.minimum;
        label.appendChild(element(
            'span',
            'gate ' + (passed ? 'gate-pass' : 'gate-fail'),
            (passed ? '✓ meets ' : '✕ below ') + score.minimumKey + ' ' + score.minimum + '%'
        ));
    }

    const [integer, decimals] = score.value.toFixed(2).split('.');
    const value = element('div', 'score-val level-' + level, integer);
    value.appendChild(element('small', '', '.' + decimals + '%'));

    const scale = element('div', 'scale level-' + level);
    scale.setAttribute('aria-hidden', 'true');

    const track = element('div', 'scale-track');
    track.append(element('i', 'zone-low'), element('i', 'zone-medium'), element('i', 'zone-high'));

    const fill = element('div', 'scale-fill');
    fill.style.width = Math.max(0, Math.min(100, score.value)) + '%';

    scale.append(track, fill);

    if (typeof score.minimum === 'number') {
        const minimum = element('div', 'scale-min');
        minimum.style.left = score.minimum + '%';
        minimum.dataset.label = 'min ' + score.minimum;
        scale.appendChild(minimum);
    } else {
        scale.appendChild(scaleTick(50));
    }

    scale.appendChild(scaleTick(90));

    container.append(label, value, scale);

    return container;
}

function scaleTick(value) {
    const tick = element('span', 'scale-tick', String(value));
    tick.style.left = value + '%';

    return tick;
}

function readConfiguredMinimums() {
    const configElement = document.getElementById('loaded-config');
    // infection.json5 may have comments, trailing commas and unquoted keys, which JSON.parse rejects
    const config = (configElement !== null ? configElement.dataset.code : '')
        .replace(/\/\*[\s\S]*?\*\/|\/\/.*$/gm, '');

    const read = (key) => {
        const match = config.match(new RegExp('["\']?\\b' + key + '\\b["\']?\\s*:\\s*(-?\\d+(?:\\.\\d+)?)'));

        return match !== null ? Number(match[1]) : undefined;
    };

    return {
        minMsi: read('minMsi'),
        minCoveredMsi: read('minCoveredMsi'),
    };
}

// ---- Strip, filter, list ---------------------------------------------------

function showStrip() {
    const strip = document.getElementById('strip');

    strip.replaceChildren(...mutants.map((mutant) => {
        const status = STATUSES[mutant.status];
        const cell = element('button', 'cell', status.symbol);

        cell.type = 'button';
        cell.dataset.mutantId = String(mutant.id);
        cell.style.setProperty('--c', status.color);
        cell.title = '#' + mutant.id + ' ' + mutant.mutator.mutatorName + ' · ' + status.label + (mutant.sourceLine ? ' · line ' + mutant.sourceLine : '');
        cell.setAttribute('aria-label', 'Mutant ' + mutant.id + ', ' + status.label);

        return cell;
    }));

    const legend = document.getElementById('strip-legend');
    const present = Object.keys(STATUSES).filter((status) => mutants.some((mutant) => mutant.status === status));

    legend.replaceChildren(...present.map((status) => {
        const item = element('span', '', STATUSES[status].symbol + ' ' + STATUSES[status].label.toLowerCase());
        item.style.setProperty('--c', STATUSES[status].color);

        return item;
    }));
}

function updateStrip() {
    // the cells are in the order of the mutants
    document.querySelectorAll('#strip .cell').forEach((cell, index) => {
        const mutant = mutants[index];
        const isFiltered = !activeStatuses.has(mutant.status);

        cell.setAttribute('aria-current', String(mutant === selectedMutant));
        cell.classList.toggle('is-filtered', isFiltered);
        // a mutant hidden by the filter can't be selected
        cell.disabled = isFiltered;
    });
}

function showStatusFilter() {
    const container = document.getElementById('status-filter');
    const counts = {};

    mutants.forEach((mutant) => {
        counts[mutant.status] = (counts[mutant.status] || 0) + 1;
    });

    Object.keys(STATUSES).filter((status) => counts[status]).forEach((status) => {
        const button = element('button', 'chip');
        button.type = 'button';
        button.style.setProperty('--c', STATUSES[status].color);
        button.setAttribute('aria-pressed', 'true');
        button.append(element('i'), STATUSES[status].label, element('b', '', String(counts[status])));

        button.addEventListener('click', function () {
            if (activeStatuses.has(status)) {
                activeStatuses.delete(status);
            } else {
                activeStatuses.add(status);
            }

            button.setAttribute('aria-pressed', String(activeStatuses.has(status)));

            hideMarkerTooltip();
            showMutantsList();

            const visible = getVisibleMutants();

            if (!visible.includes(selectedMutant) && visible.length > 0) {
                selectMutant(visible[0]);
            } else {
                showSourceMarkers();
                updateStrip();
                showMutantDetail();
            }
        });

        container.appendChild(button);
    });

    const sort = element('label', 'sort', 'Order ');
    const select = element('select');
    select.id = 'mutants-order';
    [['status', 'Escaped first'], ['line', 'By line']].forEach(([value, label]) => {
        const option = element('option', '', label);
        option.value = value;
        select.appendChild(option);
    });
    select.addEventListener('change', () => {
        sortOrder = select.value;
        showMutantsList();
        showMutantDetail();
    });
    sort.appendChild(select);
    container.appendChild(sort);
}

function showMutantsList() {
    const list = document.getElementById('mutants-list');
    const visible = getVisibleMutants();

    if (visible.length === 0) {
        list.replaceChildren(element('div', 'list-empty', 'No mutants match these filters.'));

        return;
    }

    list.replaceChildren(...visible.map((mutant) => {
        const row = buildMutantRow(mutant);

        row.setAttribute('role', 'option');
        row.setAttribute('aria-selected', String(mutant === selectedMutant));
        row.dataset.mutantId = String(mutant.id);
        row.append(element('span', 'm-line', mutant.sourceLine ? 'L' + mutant.sourceLine : '—'));

        return row;
    }));
}

function buildMutantRow(mutant) {
    const status = STATUSES[mutant.status];
    const row = element('button', 'm-row');

    row.type = 'button';
    row.style.setProperty('--c', status.color);

    const symbol = element('span', 'm-sym', status.symbol);
    symbol.title = status.label;

    const change = element('span', 'm-change');
    change.append(element('del', '', mutant.change.from || '∅'), ' → ', element('ins', '', mutant.change.to || '∅'));

    row.append(symbol, element('span', 'm-id', '#' + mutant.id), element('span', 'm-name', mutant.mutator.mutatorName), change);

    return row;
}

// ---- Selection and detail --------------------------------------------------

function selectMutant(mutant, options = {}) {
    if (!mutant) {
        return;
    }

    selectedMutant = mutant;

    showDiffEditor(mutant.mutator);
    document.getElementById('mutant-output').textContent = mutant.processOutput || STATUS_EXPLANATIONS[mutant.status];

    document.querySelectorAll('#mutants-list [data-mutant-id]').forEach((row) => {
        row.setAttribute('aria-selected', String(row.dataset.mutantId === String(mutant.id)));
    });

    if (options.scrollList !== false) {
        scrollToMutantRow(mutant);
    }

    updateStrip();
    showMutantDetail();
    showSourceMarkers();

    if (codeEditor && mutant.sourceLine) {
        codeEditor.revealLineInCenterIfOutsideViewport(mutant.sourceLine, monaco.editor.ScrollType.Smooth);
    }
}

function showMutantDetail() {
    const mutant = selectedMutant;
    const title = document.getElementById('detail-title');
    const status = STATUSES[mutant.status];

    title.style.setProperty('--c', status.color);

    const navigation = element('div', 'd-nav');
    const escapedCount = getVisibleMutants().filter((m) => m.status === 'escaped').length;

    if (escapedCount > 0) {
        const nextEscaped = element('button', 'btn btn-sm');
        nextEscaped.type = 'button';
        nextEscaped.title = 'Go to the next escaped mutant (N)';
        nextEscaped.setAttribute('aria-label', 'Next escaped mutant, ' + plural(escapedCount, 'escaped mutant') + ' in all');
        nextEscaped.append(element('span', 'btn-label', 'Next escaped '), '(' + escapedCount + ')');
        nextEscaped.addEventListener('click', selectNextEscaped);
        navigation.appendChild(nextEscaped);
    }

    navigation.append(
        navigationButton('Previous mutant (K)', 'm15 18-6-6 6-6', () => stepMutant(-1)),
        navigationButton('Next mutant (J)', 'm9 18 6-6-6-6', () => stepMutant(1))
    );

    title.replaceChildren(
        element('span', 'm-sym', status.symbol),
        element('span', 'm-id', '#' + mutant.id),
        element('h3', '', mutant.mutator.mutatorName),
        navigation
    );

    const sub = document.getElementById('detail-sub');
    const level = {escaped: 'level-low', uncovered: '', timeouted: 'level-medium', errored: '', killed: 'level-high'}[mutant.status];
    const label = element('b', level, status.label + '.');

    if (level === '') {
        label.style.color = status.color;
    }

    sub.replaceChildren(label, ' ' + STATUS_EXPLANATIONS[mutant.status]);

    if (mutant.sourceLine) {
        sub.append(' ', element('span', 'line-ref', 'Line ' + mutant.sourceLine + '.'));
    }
}

function navigationButton(label, path, onClick) {
    const button = element('button', 'icon-btn');
    button.type = 'button';
    button.title = label;
    button.setAttribute('aria-label', label);
    button.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="' + path + '"/></svg>';
    button.addEventListener('click', onClick);

    return button;
}

function stepMutant(direction) {
    const visible = getVisibleMutants();

    if (visible.length === 0) {
        return;
    }

    const index = visible.indexOf(selectedMutant);

    selectMutant(visible[(index + direction + visible.length) % visible.length]);
}

/**
 * The next escaped mutant down the code, from the top again after the last one.
 */
function selectNextEscaped() {
    const escaped = getVisibleMutants()
        .filter((mutant) => mutant.status === 'escaped')
        .sort((a, b) => a.id - b.id);

    if (escaped.length === 0) {
        return;
    }

    selectMutant(escaped.find((mutant) => mutant.id > selectedMutant.id) || escaped[0]);
}

function scrollToMutantRow(mutant) {
    const list = document.getElementById('mutants-list');
    const row = list.querySelector('[data-mutant-id="' + mutant.id + '"]');

    if (row === null) {
        return;
    }

    // scrolls only the list: row.scrollIntoView() would scroll the page too
    const rowTop = row.getBoundingClientRect().top - list.getBoundingClientRect().top + list.scrollTop;

    if (rowTop < list.scrollTop) {
        list.scrollTop = rowTop - 4;
    } else if (rowTop + row.offsetHeight > list.scrollTop + list.clientHeight) {
        list.scrollTop = rowTop + row.offsetHeight - list.clientHeight + 4;
    }
}

// ---- Markers in the Source Code editor -------------------------------------

/**
 * Gutter dots and line highlights in the Source Code editor: a dot for each marker of the line's mutants,
 * the line colored by its worst marker, and the change of the selected mutant after its line.
 */
function showSourceMarkers() {
    if (!codeEditor) {
        return;
    }

    const decorations = [];
    const style = getComputedStyle(document.documentElement);
    const rulerColors = {
        bad: style.getPropertyValue('--escaped').trim(),
        warn: style.getPropertyValue('--uncovered').trim(),
        good: style.getPropertyValue('--killed').trim(),
    };

    getVisibleMutantsByLine().forEach((lineMutants, line) => {
        const range = new monaco.Range(line, 1, line, 1);
        const markers = MARKER_ORDER.filter((marker) => lineMutants.some((mutant) => getMarker(mutant) === marker));
        const worstMarker = markers[0];
        const isSelectedLine = lineMutants.includes(selectedMutant);

        decorations.push({
            range: range,
            options: {
                isWholeLine: true,
                className: isSelectedLine ? 'mutant-line-selected' : (worstMarker !== 'good' ? 'mutant-line-' + worstMarker : ''),
                overviewRuler: {
                    color: rulerColors[worstMarker],
                    position: monaco.editor.OverviewRulerLane.Left,
                },
            },
        });

        markers.forEach((marker) => {
            const isSelected = isSelectedLine && getMarker(selectedMutant) === marker;

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

    const model = codeEditor.getModel();

    // the line may be gone when the code was edited since the run
    if (selectedMutant !== null && selectedMutant.sourceLine && selectedMutant.sourceLine <= model.getLineCount() && activeStatuses.has(selectedMutant.status)) {
        const line = selectedMutant.sourceLine;
        const column = model.getLineMaxColumn(line);

        decorations.push({
            range: new monaco.Range(line, column, line, column),
            options: {
                showIfCollapsed: true,
                after: {
                    content: '#' + selectedMutant.id + '  ' + (selectedMutant.change.from || '∅') + ' → ' + (selectedMutant.change.to || '∅'),
                    inlineClassName: 'mutant-ghost',
                },
            },
        });
    }

    if (sourceMarkers === null) {
        sourceMarkers = codeEditor.createDecorationsCollection();
    }

    sourceMarkers.set(decorations);
}

function getMarker(mutant) {
    return STATUSES[mutant.status].marker;
}

function getVisibleMutantsByLine() {
    const mutantsByLine = new Map();

    mutants
        .filter((mutant) => mutant.sourceLine && activeStatuses.has(mutant.status))
        // worst first, as the dots and the popover show them
        .sort((a, b) => STATUSES[a.status].rank - STATUSES[b.status].rank || a.id - b.id)
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

    selectMutant(lineMutants[(lineMutants.indexOf(selectedMutant) + 1) % lineMutants.length]);

    if (markerTooltipLine !== null) {
        renderMarkerTooltip(markerTooltipLine);
    }
}

/**
 * Monaco's own hover can only show Markdown, so the mutants of a line are listed in a popover of ours.
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

    const header = element('div', 'mutant-tooltip-header');
    header.append(element('b', '', 'Line ' + line), plural(lineMutants.length, 'mutant'));

    const items = lineMutants.map((mutant) => {
        const item = buildMutantRow(mutant);

        item.style.gridTemplateColumns = '22px 34px minmax(0, 1fr) auto';
        item.setAttribute('aria-selected', String(mutant === selectedMutant));
        item.addEventListener('click', function () {
            selectMutant(mutant);
            hideMarkerTooltip();
        });

        return item;
    });

    tooltip.replaceChildren(header, ...items);

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

    tooltip.hidden = false;

    const left = Math.min(
        editorRect.left + layoutInfo.glyphMarginLeft + layoutInfo.glyphMarginWidth + 4,
        window.innerWidth - tooltip.offsetWidth - 16
    );
    let top = editorRect.top + position.top;

    // keep the popover inside the window, above the line when there is no room below
    if (top + tooltip.offsetHeight > window.innerHeight - 8) {
        top = Math.max(8, editorRect.top + position.top + position.height - tooltip.offsetHeight);
    }

    tooltip.style.left = Math.max(16, left) + 'px';
    tooltip.style.top = top + 'px';
}

function getMarkerTooltip() {
    if (markerTooltip === null) {
        markerTooltip = element('div', 'mutant-tooltip');
        markerTooltip.hidden = true;
        markerTooltip.setAttribute('role', 'dialog');
        markerTooltip.addEventListener('mouseenter', cancelHideMarkerTooltip);
        markerTooltip.addEventListener('mouseleave', scheduleHideMarkerTooltip);

        document.body.appendChild(markerTooltip);
    }

    return markerTooltip;
}

// a delay lets the mouse move from the marker into the popover
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
        markerTooltip.hidden = true;
    }

    markerTooltipLine = null;
}

// ---- Diff ------------------------------------------------------------------

function showDiffEditor(mutator) {
    diffEditor = diffEditor ? diffEditor : createDiffEditor();

    const previousModel = diffEditor.getModel();

    // New models first show the whole class, until the diff is computed and the unchanged regions collapse.
    // The box stays hidden meanwhile and fades in with the collapsed diff.
    hideDiffUntilComputed();

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

let showDiffTimeout = null;

function hideDiffUntilComputed() {
    const container = document.getElementById('editor-diff');

    container.classList.add('mutant-diff-pending');

    clearTimeout(showDiffTimeout);
    // in case no diff update comes, e.g. for 2 equal models
    showDiffTimeout = setTimeout(showComputedDiff, 500);
}

function showComputedDiff() {
    clearTimeout(showDiffTimeout);

    // the unchanged regions collapse in the 2nd frame after the diff update
    requestAnimationFrame(() => requestAnimationFrame(() => {
        document.getElementById('editor-diff').classList.remove('mutant-diff-pending');
    }));
}

function createDiffEditor() {
    const editor = monaco.editor.createDiffEditor(document.getElementById('editor-diff'), Object.assign({}, EDITOR_OPTIONS, {
        enableSplitViewResizing: false,
        // Side by side only when the box is wide enough, inline otherwise
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
        theme: currentTheme(),
    }));

    editor.onDidUpdateDiff(showComputedDiff);

    return editor;
}

// ---- Helpers ---------------------------------------------------------------

function element(tag, className, text) {
    const node = document.createElement(tag);

    if (className) {
        node.className = className;
    }

    if (text !== undefined) {
        node.textContent = text;
    }

    return node;
}

function plural(count, noun) {
    return count + ' ' + noun + (count === 1 ? '' : 's');
}
