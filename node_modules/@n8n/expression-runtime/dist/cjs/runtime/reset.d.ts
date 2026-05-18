/**
 * Reset workflow data proxies before each evaluation.
 *
 * This function is called from the bridge before executing each expression
 * to clear proxy caches and initialize fresh workflow data references.
 *
 * Pattern:
 * 1. Create lazy proxies for complex properties ($json, $binary, etc.)
 * 2. Fetch primitives directly ($runIndex, $itemIndex)
 * 3. Create function wrappers for callable properties ($items, etc.)
 * 4. Expose all properties to globalThis for expression access
 *
 * Called from bridge: context.evalSync('resetDataProxies()')
 */
export declare function resetDataProxies(timezone?: string): void;
//# sourceMappingURL=reset.d.ts.map