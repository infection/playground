/*
 * Welcome to your app's main JavaScript file!
 *
 * We recommend including the built version of this JavaScript file
 * (and its CSS file) in your base layout (base.html.twig).
 */

// any CSS you import will output into a single css file (app.css in this case)
import '../css/app.css';

// before the editors: the panes get their saved sizes without waiting for Monaco to load
import './app/splitters.js';
import {initMutationEditors} from './app/editor.js';

initMutationEditors();
