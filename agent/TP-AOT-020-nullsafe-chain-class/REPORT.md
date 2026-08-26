# TP-AOT-020: chained nullsafe call loses the returned class type

Status: **open**, discovered and reproduced on 2026-08-20.

Severity: high compiler compatibility issue.

## Reproduction

```powershell
php examples/aot-torture/bugs/TP-AOT-020-nullsafe-chain-class/run-zend.php
examples\aot-torture\build-windows.cmd examples\aot-torture\bugs\TP-AOT-020-nullsafe-chain-class\project.yml tp_aot_020_nullsafe_chain_class
```

## Expected (Zend PHP 8.4.24)

```text
forward
```

The first nullsafe receiver comes from `WeakReference::get()`. The next method
has a concrete `ReproChainNode` return declaration, so the compiler has enough
static information to resolve the following `leaf` property.

## Actual (current TypePHP AOT v0.5.0 build)

Compilation stops during conversion without identifying the source line:

```text
Fatal error: Class name can not be empty
#0 TypePhp\CompilerBase->getNamespacedClassName('')
#1 TypePhp\CompilerBase->parsePropertyFetch(...)
#2 TypePhp\CompilerBase->parseBinaryOpConcat(...)
```

No executable is produced. The already-fixed TP-AOT-019 shapes with one
nullsafe method or property receiver compile and run. This reproducer adds a
typed method-return hop before the property read:

```php
$weak->get()?->self()?->leaf->value
```

Removing that hop makes the neighboring weak-reference probes compile.

## Impact

Fluent APIs and optional object graphs commonly chain typed returns. A valid
chain currently stops the whole build, and the diagnostic lacks the triggering
source location, making larger failures expensive to isolate.
