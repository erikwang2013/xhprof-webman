(function (factory) {
    if (typeof module === "object" && typeof module.exports === "object") {
        var v = factory(require, exports);
        if (v !== undefined) module.exports = v;
    }
    else if (typeof define === "function" && define.amd) {
        define(["require", "exports", "./array-extensions", "./expression-extension-error"], factory);
    }
})(function (require, exports) {
    "use strict";
    Object.defineProperty(exports, "__esModule", { value: true });
    exports.extendedFunctions = void 0;
    const array_extensions_1 = require("./array-extensions");
    const expression_extension_error_1 = require("./expression-extension-error");
    const min = Math.min;
    const max = Math.max;
    const numberList = (start, end) => {
        const size = Math.abs(start - end) + 1;
        const arr = new Array(size);
        let curr = start;
        for (let i = 0; i < size; i++) {
            if (start < end) {
                arr[i] = curr++;
            }
            else {
                arr[i] = curr--;
            }
        }
        return arr;
    };
    const zip = (keys, values) => {
        if (keys.length !== values.length) {
            throw new expression_extension_error_1.ExpressionExtensionError('keys and values not of equal length');
        }
        return keys.reduce((p, c, i) => {
            // eslint-disable-next-line @typescript-eslint/no-unsafe-member-access, @typescript-eslint/no-explicit-any
            p[c] = values[i];
            return p;
        }, {});
    };
    const average = (...args) => {
        return (0, array_extensions_1.average)(args);
    };
    const not = (value) => {
        return !value;
    };
    function ifEmpty(value, defaultValue) {
        if (arguments.length !== 2) {
            // DIVERGENCE from packages/workflow/src/extensions/extended-functions.ts:
            // The original throws ExpressionError (from ExecutionBaseError). The runtime
            // uses ExpressionExtensionError because the full ExpressionError class is not
            // available in the extensions layer — only a minimal shim exists in safe-globals.
            throw new expression_extension_error_1.ExpressionExtensionError('expected two arguments (value, defaultValue) for this function');
        }
        if (value === undefined || value === null || value === '') {
            return defaultValue;
        }
        if (typeof value === 'object') {
            if (Array.isArray(value) && !value.length) {
                return defaultValue;
            }
            if (!Object.keys(value).length) {
                return defaultValue;
            }
        }
        return value;
    }
    ifEmpty.doc = {
        name: 'ifEmpty',
        description: 'Returns the default value if the value is empty. Empty values are undefined, null, empty strings, arrays without elements and objects without keys.',
        returnType: 'any',
        args: [
            { name: 'value', type: 'any' },
            { name: 'defaultValue', type: 'any' },
        ],
        docURL: 'https://docs.n8n.io/code/builtin/convenience',
    };
    exports.extendedFunctions = {
        min,
        max,
        not,
        average,
        numberList,
        zip,
        $min: min,
        $max: max,
        $average: average,
        $not: not,
        $ifEmpty: ifEmpty,
    };
});
//# sourceMappingURL=function-extensions.js.map