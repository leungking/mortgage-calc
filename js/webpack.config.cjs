const config = require('flarum-webpack-config')();
// Flarum core exposes mithril as the global `m` (window.m). Map the
// 'mithril' import to that global instead of bundling a second copy:
// a bundled copy's m.redraw() redraws nothing (the app tree is mounted
// by core's mithril), which left async list pages stuck at "加载中…".
config.externals = [{ mithril: 'm' }, ...(config.externals || [])];
module.exports = config;
