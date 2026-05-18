import { DateTime, Duration, Interval } from 'luxon';
import { extend, extendOptional } from '../extensions/extend';
import { SafeObject, SafeError, ExpressionError } from './safe-globals';
import { createDeepLazyProxy } from './lazy-proxy';
import { resetDataProxies } from './reset';
import { __prepareForTransfer } from './serialize';
// ============================================================================
// Library Setup
// ============================================================================
// Expose globals required by tournament-transformed expressions
globalThis.extend = extend;
globalThis.extendOptional = extendOptional;
globalThis.DateTime = DateTime;
globalThis.Duration = Duration;
globalThis.Interval = Interval;
// ============================================================================
// Expose security globals and runtime functions
// ============================================================================
globalThis.SafeObject = SafeObject;
globalThis.SafeError = SafeError;
globalThis.ExpressionError = ExpressionError;
globalThis.createDeepLazyProxy = createDeepLazyProxy;
globalThis.resetDataProxies = resetDataProxies;
globalThis.__prepareForTransfer = __prepareForTransfer;
// Initialize empty __data object (populated by resetDataProxies before each evaluation)
globalThis.__data = {};
//# sourceMappingURL=index.js.map