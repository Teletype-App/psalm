# MissingThrowsDocblock

Enabled when the `checkForThrowsDocblock` configuration option is enabled.

Emitted when a function throws (or fails to handle) an exception and does not have a `@throws` annotation.

Inferred exceptions are propagated through the selected callers before issues are reported. When a caught exception is rethrown unchanged, Psalm preserves the concrete caught exception types instead of replacing them with the catch variable's broader declared type.

```php
<?php

function foo(int $x, int $y) : int {
    if ($y === 0) {
        throw new \InvalidArgumentException('Cannot divide by zero');
    }

    return intdiv($x, $y);
}
```
