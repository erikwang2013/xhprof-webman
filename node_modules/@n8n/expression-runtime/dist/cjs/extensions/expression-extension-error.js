(function (factory) {
    if (typeof module === "object" && typeof module.exports === "object") {
        var v = factory(require, exports);
        if (v !== undefined) module.exports = v;
    }
    else if (typeof define === "function" && define.amd) {
        define(["require", "exports"], factory);
    }
})(function (require, exports) {
    "use strict";
    Object.defineProperty(exports, "__esModule", { value: true });
    exports.ExpressionExtensionError = void 0;
    class ExpressionExtensionError extends Error {
        description;
        constructor(message, options) {
            super(message);
            this.name = 'ExpressionExtensionError';
            if (options?.description !== undefined) {
                this.description = options.description;
            }
        }
    }
    exports.ExpressionExtensionError = ExpressionExtensionError;
});
//# sourceMappingURL=expression-extension-error.js.map