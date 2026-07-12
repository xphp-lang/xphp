# Stage 3 -- Method- and function-scope specialization

[← How the xphp compiler works](../how-it-works.md)

Method-scoped generics (`Cls::method::<T>(...)`) and free generic
functions (`function f<T>(...)`) are handled by a separate pass
before the class-level fixed-point loop runs. The reason is that
their specialization is **call-site driven**: each unique arg list
mints one mangled method or function appended to the same class /
namespace.

[`GenericMethodCompiler::process()`](../../../src/Transpiler/Monomorphize/GenericMethodCompiler.php)
runs on the full `astPerFile` map and:

1. Collects every method-template (`ClassFqn::methodName`) and
   free-function template (`Namespace\functionName`).
2. Collects every `StaticCall` / `FuncCall` carrying
   `ATTR_METHOD_GENERIC_ARGS` -- those are the call sites the
   scanner detected during parse.
3. For each call site, mangles a name (`methodName_T_<hash>` or
   `functionName_T_<hash>`), clones the template body, substitutes
   the type-param, and appends the result to the owning class
   (for methods) or namespace (for functions). Calls
   `Registry::checkBounds()` first so bound violations on
   method-level type-params fire before the body is cloned.
4. Strips the original template `ClassMethod` / `Function_`.
5. Rewrites each call site's identifier to the mangled name.

**Call-site coverage**: static calls (`Cls::method::<int>(...)`),
instance calls (`$obj->method::<int>(...)`), and the nullsafe variant
(`$obj?->method::<int>(...)`) all rewrite. Receiver-type analysis
walks the AST tracking each variable's class from typed parameters,
typed properties, `$this`, and local `$x = new Foo()` assignments;
when the receiver class is unambiguous, the call binds to the right
specialization. When the analysis can't prove a single class (e.g.,
after a branching reassignment whose arms disagree), the turbofish call
is a compile error (`xphp.undetermined_receiver`) rather than a silently
de-specialized call that would fatal at runtime
— see the [branching narrowing caveat](../../caveats.md#branching-narrowing-precision-loss).

Method-scoped generics work on non-generic AND generic enclosing
classes; the type-param scopes from each layer are kept distinct.

---

Prev: [Stage 2 -- Hierarchy and Registry construction](02-hierarchy-and-registry.md) · [Index](../how-it-works.md) · Next: [Stage 4 -- Class-level fixed-point specialization](04-fixed-point-specialization.md)
