(function (factory) {
    if (typeof module === "object" && typeof module.exports === "object") {
        var v = factory(require, exports);
        if (v !== undefined) module.exports = v;
    }
    else if (typeof define === "function" && define.amd) {
        define(["require", "exports", "./evaluator/expression-evaluator", "./bridge/isolated-vm-bridge", "./types", "./extensions/extend", "./extensions/expression-extension-error"], factory);
    }
})(function (require, exports) {
    "use strict";
    Object.defineProperty(exports, "__esModule", { value: true });
    exports.ExpressionExtensionError = exports.EXTENSION_OBJECTS = exports.extendOptional = exports.extend = exports.SyntaxError = exports.SecurityViolationError = exports.TimeoutError = exports.MemoryLimitError = exports.ExpressionError = exports.IsolatedVmBridge = exports.ExpressionEvaluator = void 0;
    // Main exports
    var expression_evaluator_1 = require("./evaluator/expression-evaluator");
    Object.defineProperty(exports, "ExpressionEvaluator", { enumerable: true, get: function () { return expression_evaluator_1.ExpressionEvaluator; } });
    // Bridge exports — IsolatedVmBridge lazy-loads isolated-vm internally,
    // so this value re-export does NOT pull in the native binary at import time.
    var isolated_vm_bridge_1 = require("./bridge/isolated-vm-bridge");
    Object.defineProperty(exports, "IsolatedVmBridge", { enumerable: true, get: function () { return isolated_vm_bridge_1.IsolatedVmBridge; } });
    // Error types
    var types_1 = require("./types");
    Object.defineProperty(exports, "ExpressionError", { enumerable: true, get: function () { return types_1.ExpressionError; } });
    Object.defineProperty(exports, "MemoryLimitError", { enumerable: true, get: function () { return types_1.MemoryLimitError; } });
    Object.defineProperty(exports, "TimeoutError", { enumerable: true, get: function () { return types_1.TimeoutError; } });
    Object.defineProperty(exports, "SecurityViolationError", { enumerable: true, get: function () { return types_1.SecurityViolationError; } });
    Object.defineProperty(exports, "SyntaxError", { enumerable: true, get: function () { return types_1.SyntaxError; } });
    // Extension runtime exports
    var extend_1 = require("./extensions/extend");
    Object.defineProperty(exports, "extend", { enumerable: true, get: function () { return extend_1.extend; } });
    Object.defineProperty(exports, "extendOptional", { enumerable: true, get: function () { return extend_1.extendOptional; } });
    Object.defineProperty(exports, "EXTENSION_OBJECTS", { enumerable: true, get: function () { return extend_1.EXTENSION_OBJECTS; } });
    var expression_extension_error_1 = require("./extensions/expression-extension-error");
    Object.defineProperty(exports, "ExpressionExtensionError", { enumerable: true, get: function () { return expression_extension_error_1.ExpressionExtensionError; } });
});
//# sourceMappingURL=index.js.map