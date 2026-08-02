# OverlyBroadThrowsDocblock

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

Psalter can replace `ApplicationException` with `InvalidApplicationState`. The annotation is preserved when the implementation can throw `ApplicationException` itself. Abstract methods and inherited annotations are not changed.
