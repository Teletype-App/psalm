# OverlyBroadThrowsDocblock

Annotations matching `ignoreExceptions` are not reported or narrowed. Entries with `onlyGlobalScope="true"`
do not disable this check inside functions or methods.

Emitted when a concrete function or method documents an exception type that is broader than every uncaught exception inferred from its implementation.

The check is opt-in. Enable `OverlyBroadThrowsDocblock` in `issueHandlers` or select it with Psalter's `--issues` option.

```php
<?php

class ApplicationException extends Exception {}
class InvalidApplicationState extends ApplicationException {}

/** @throws ApplicationException */
function execute(): void
{
    throw new InvalidApplicationState();
}
```

Psalter can replace `ApplicationException` with `InvalidApplicationState`. The annotation is preserved when the implementation can throw `ApplicationException` itself. If both the parent and a concrete child can escape through the same unchanged catch-variable rethrow, both exception types remain part of the contract. Abstract methods and inherited annotations are not changed.
