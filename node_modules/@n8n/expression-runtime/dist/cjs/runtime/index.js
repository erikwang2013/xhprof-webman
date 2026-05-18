(function (factory) {
    if (typeof module === "object" && typeof module.exports === "object") {
        var v = factory(require, exports);
        if (v !== undefined) module.exports = v;
    }
    else if (typeof define === "function" && define.amd) {
        define(["require", "exports", "luxon", "../extensions/extend", "./safe-globals", "./lazy-proxy", "./reset", "./serialize"], factory);
    }
})(function (require, exports) {
    "use strict";
    Object.defineProperty(exports, "__esModule", { value: true });
    const luxon_1 = require("luxon");
    const extend_1 = require("../extensions/extend");
    const safe_globals_1 = require("./safe-globals");
    const lazy_proxy_1 = require("./lazy-proxy");
    const reset_1 = require("./reset");
    const serialize_1 = require("./serialize");
    // ============================================================================
    // Library Setup
    // ============================================================================
    // Expose globals required by tournament-transformed expressions
    globalThis.extend = extend_1.extend;
    globalThis.extendOptional = extend_1.extendOptional;
    globalThis.DateTime = luxon_1.DateTime;
    globalThis.Duration = luxon_1.Duration;
    globalThis.Interval = luxon_1.Interval;
    // ============================================================================
    // Expose security globals and runtime functions
    // ============================================================================
    globalThis.SafeObject = safe_globals_1.SafeObject;
    globalThis.SafeError = safe_globals_1.SafeError;
    globalThis.ExpressionError = safe_globals_1.ExpressionError;
    globalThis.createDeepLazyProxy = lazy_proxy_1.createDeepLazyProxy;
    globalThis.resetDataProxies = reset_1.resetDataProxies;
    globalThis.__prepareForTransfer = serialize_1.__prepareForTransfer;
    // Initialize empty __data object (populated by resetDataProxies before each evaluation)
    globalThis.__data = {};
});
//# sourceMappingURL=index.js.map