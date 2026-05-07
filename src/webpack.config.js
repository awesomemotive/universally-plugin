const path = require('path');
const defaultConfig = require('@wordpress/scripts/config/webpack.config');

// Don't externalize react/jsx-runtime — WP < 6.6 doesn't register this handle.
const plugins = defaultConfig.plugins.filter(
  (plugin) => plugin.constructor.name !== 'DependencyExtractionWebpackPlugin'
);

const DependencyExtractionWebpackPlugin = require('@wordpress/dependency-extraction-webpack-plugin');
plugins.push(
  new DependencyExtractionWebpackPlugin({
    requestToExternal(request) {
      if (request === 'react/jsx-runtime' || request === 'react/jsx-dev-runtime') {
        return false;
      }
    },
    requestToHandle(request) {
      if (request === 'react/jsx-runtime' || request === 'react/jsx-dev-runtime') {
        return false;
      }
    },
  })
);

// @wordpress/scripts skips the index.* fallback when block.json files are found.
// Use an async entry function so the default entries resolve AFTER --webpack-src-dir
// has set WP_SOURCE_PATH. Then explicitly add the panel entry alongside block entries.
module.exports = {
  ...defaultConfig,
  entry: async () => {
    const base = typeof defaultConfig.entry === 'function'
      ? await defaultConfig.entry()
      : defaultConfig.entry;
    return {
      ...base,
      index: path.resolve(__dirname, 'panel/js/index.tsx'),
    };
  },
  plugins,
};