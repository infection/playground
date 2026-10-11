/*
 * Monaco themes in the colors of the page (the tokens in app.css), so the editors sit in their panes
 * without a background of their own.
 */
import monaco from '../monaco.js';

export const LIGHT = 'playground-light';
export const DARK = 'playground-dark';

// Same as --surface in app.css: the X-Ray monochrome themes use it too, so the editor never flips polarity.
export const LIGHT_BACKGROUND = '#ffffff';
export const DARK_BACKGROUND = '#111815';

export const EDITOR_FONT_FAMILY = '"Geist Mono", ui-monospace, "SF Mono", Menlo, Consolas, monospace';

monaco.editor.defineTheme(LIGHT, {
    base: 'vs',
    inherit: true,
    rules: [
        {token: 'comment', foreground: '7b8a82', fontStyle: 'italic'},
        {token: 'keyword', foreground: '7a3fb3'},
        {token: 'variable', foreground: '1b5e9e'},
        {token: 'string', foreground: 'a3501b'},
        {token: 'number', foreground: '0d7a68'},
        {token: 'type', foreground: '0b6f8f'},
        {token: 'string.key.json', foreground: '1b5e9e'},
        {token: 'string.value.json', foreground: 'a3501b'},
    ],
    colors: {
        'editor.background': LIGHT_BACKGROUND,
        'editor.foreground': '#141c18',
        'editorLineNumber.foreground': '#9aa69f',
        'editorLineNumber.activeForeground': '#3a4640',
        'editor.lineHighlightBackground': '#f6f8f7',
        'editor.lineHighlightBorder': '#00000000',
        'editor.selectionBackground': '#2563eb33',
        'editorGutter.background': LIGHT_BACKGROUND,
        'editorWidget.background': '#ffffff',
        'editorWidget.border': '#d7ded9',
        'scrollbarSlider.background': '#b9c4bd66',
        'scrollbarSlider.hoverBackground': '#b9c4bd99',
        'diffEditor.insertedTextBackground': '#17864b2e',
        'diffEditor.removedTextBackground': '#cf2f3b2e',
        'diffEditor.insertedLineBackground': '#17864b14',
        'diffEditor.removedLineBackground': '#cf2f3b14',
    },
});

monaco.editor.defineTheme(DARK, {
    base: 'vs-dark',
    inherit: true,
    rules: [
        {token: 'comment', foreground: '6c7d75', fontStyle: 'italic'},
        {token: 'keyword', foreground: 'c99dff'},
        {token: 'variable', foreground: '8cc4ff'},
        {token: 'string', foreground: 'f0a57a'},
        {token: 'number', foreground: '6fd6c0'},
        {token: 'type', foreground: '6cc7e0'},
        {token: 'string.key.json', foreground: '8cc4ff'},
        {token: 'string.value.json', foreground: 'f0a57a'},
    ],
    colors: {
        'editor.background': DARK_BACKGROUND,
        'editor.foreground': '#e2eae5',
        'editorLineNumber.foreground': '#4f5e57',
        'editorLineNumber.activeForeground': '#b6c3bc',
        'editor.lineHighlightBackground': '#151d1a',
        'editor.lineHighlightBorder': '#00000000',
        'editor.selectionBackground': '#7aa7ff40',
        'editorGutter.background': DARK_BACKGROUND,
        'editorWidget.background': '#151d1a',
        'editorWidget.border': '#232e29',
        'scrollbarSlider.background': '#34433c80',
        'scrollbarSlider.hoverBackground': '#34433cb3',
        'diffEditor.insertedTextBackground': '#45c57c33',
        'diffEditor.removedTextBackground': '#ff687233',
        'diffEditor.insertedLineBackground': '#45c57c14',
        'diffEditor.removedLineBackground': '#ff687214',
    },
});

export function isDarkPage() {
    return document.documentElement.classList.contains('dark');
}

export function currentTheme() {
    return isDarkPage() ? DARK : LIGHT;
}

/**
 * Options every editor of the playground shares.
 */
export const EDITOR_OPTIONS = {
    minimap: {enabled: false},
    fontFamily: EDITOR_FONT_FAMILY,
    fontSize: 13,
    lineHeight: 20,
    padding: {top: 10, bottom: 10},
    scrollBeyondLastLine: false,
    automaticLayout: true,
    renderLineHighlightOnlyWhenFocus: true,
    overviewRulerBorder: false,
    scrollbar: {verticalScrollbarSize: 10, horizontalScrollbarSize: 10},
};

// Monaco measures the font when an editor is created; once Geist Mono has loaded, measure again.
if (document.fonts && document.fonts.ready) {
    document.fonts.ready.then(() => monaco.editor.remeasureFonts());
}
