import type { ExtensionMap } from './extensions';
export declare const EXTENSION_OBJECTS: ExtensionMap[];
/**
 * Extender function injected by expression extension plugin to allow calls to extensions.
 *
 * ```ts
 * extend(input, "functionName", [...args]);
 * ```
 */
export declare function extend(input: unknown, functionName: string, args: unknown[]): any;
export declare function extendOptional(input: unknown, functionName: string): Function | undefined;
//# sourceMappingURL=extend.d.ts.map