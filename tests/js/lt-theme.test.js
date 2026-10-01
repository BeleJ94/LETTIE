'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const theme = require('../../public/assets/js/lt-theme.js');

test('sap_horizon is the default theme, whatever the system colour scheme', () => {
    assert.equal(theme.resolveTheme(null, {}), 'sap_horizon');
    assert.equal(theme.resolveTheme(null, { systemDark: true }), 'sap_horizon');
    assert.equal(theme.resolveTheme('light', { systemDark: true }), 'sap_horizon');
});

test('the dark preference selects sap_horizon_dark', () => {
    assert.equal(theme.resolveTheme('dark', {}), 'sap_horizon_dark');
    assert.equal(theme.resolveTheme('dark', { systemDark: false }), 'sap_horizon_dark');
});

test('a system request for more contrast selects a high contrast theme', () => {
    assert.equal(theme.resolveTheme(null, { moreContrast: true }), 'sap_horizon_hcw');
    assert.equal(theme.resolveTheme('light', { moreContrast: true }), 'sap_horizon_hcw');
    assert.equal(theme.resolveTheme('dark', { moreContrast: true }), 'sap_horizon_hcb');
    assert.equal(theme.resolveTheme(null, { moreContrast: true, systemDark: true }), 'sap_horizon_hcb');
    assert.equal(theme.resolveTheme('light', { moreContrast: true, systemDark: true }), 'sap_horizon_hcw');
});

test('density is compact with a precise pointer and cozy on touch screens', () => {
    assert.equal(theme.resolveDensity(true), 'compact');
    assert.equal(theme.resolveDensity(false), 'cozy');
});

test('the toggle switches between light and dark, high contrast included', () => {
    assert.equal(theme.nextPreference('sap_horizon'), 'dark');
    assert.equal(theme.nextPreference('sap_horizon_dark'), 'light');
    assert.equal(theme.nextPreference('sap_horizon_hcw'), 'dark');
    assert.equal(theme.nextPreference('sap_horizon_hcb'), 'light');
});

test('every theme returned is one of the four themes of the UI5 bundle', () => {
    const bundled = ['sap_horizon', 'sap_horizon_dark', 'sap_horizon_hcb', 'sap_horizon_hcw'];
    for (const preference of [null, 'light', 'dark']) {
        for (const moreContrast of [false, true]) {
            for (const systemDark of [false, true]) {
                assert.ok(bundled.includes(theme.resolveTheme(preference, { moreContrast, systemDark })));
            }
        }
    }
});
