# UnusedThrowsDocblock

Enabled when the `checkForThrowsDocblock` configuration option is enabled.

Emitted when a concrete function or method has an `@throws` annotation for an exception that Psalm does not find
among its inferred uncaught exceptions. Dynamic calls and exceptions not modeled by Psalm can require suppressing
this issue for an intentional API contract.

Annotations matching `ignoreExceptions` are not reported or removed. Entries with `onlyGlobalScope="true"`
do not disable this check inside functions or methods.

```php
<?php

/** @throws RuntimeException */
function foo(): void {}
```
