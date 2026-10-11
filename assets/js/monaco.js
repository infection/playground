/*
 * Monaco Editor, loaded from its prebuilt AMD build.
 *
 * The Dockerfile extracts the npm package into the image and Caddy serves it under /monaco,
 * so no Node.js build is needed. The AMD loader brings Monaco's own CSS, languages and
 * web workers, which an importmap cannot.
 */
const MONACO_BASE_URL = '/monaco/vs';

const monaco = await new Promise((resolve, reject) => {
    const loaderScript = document.createElement('script');
    loaderScript.src = `${MONACO_BASE_URL}/loader.js`;
    loaderScript.onerror = () => reject(new Error(`Could not load ${loaderScript.src}`));
    loaderScript.onload = () => {
        window.require.config({paths: {vs: MONACO_BASE_URL}});
        window.require(['vs/editor/editor.main'], () => resolve(window.monaco), reject);
    };

    document.head.appendChild(loaderScript);
});

export default monaco;
