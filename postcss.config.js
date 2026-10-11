let tailwindcss = require('tailwindcss');

// module.exports = {
//     plugins: [
//         tailwindcss('./tailwind.config.js'),
//         require('autoprefixer'),
//         require('postcss-import')
//     ]
// }

module.exports = {
    plugins: {
        // include whatever plugins you want
        // but make sure you install these via npm!

        // add browserslist config to package.json (see below)
        autoprefixer: {},
        tailwindcss: {}
    }
}