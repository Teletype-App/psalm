# UnusedThrowsDocblock

Enabled when the `checkForThrowsDocblock` configuration option is enabled.

Emitted when a concrete function or method has an `@throws` annotation for an exception that Psalm does not find
among its inferred uncaught exceptions. Dynamic calls and exceptions not modeled by Psalm can require suppressing
this issue for an intentional API contract.

```php
<?php

/** @throws RuntimeException */
function foo(): void {}
```
