# Политика синхронизации форка

## Связь репозиториев

- Целевая ветка форка: `origin/teletype`
- Источник upstream: `upstream/master`
- Назначение форка: Поддерживаемый форк Psalm для точного сопровождения PHPDoc с исключениями (@throws) в больших PHP-приложениях и расширенного статического анализа Yii 2.

## Обязательные функции форка

- Транзитивный вывод @throws с учетом цепочек вызовов, условий, catch/rethrow, замыканий, трейтов и полиморфизма:
  - Подтверждение: `src/Psalm/Internal/Codebase/Analyzer.php`, `src/Psalm/Internal/Analyzer/CatchRethrowCollector.php`, `tests/ThrowsAnnotationTest.php`
- Исправление @throws через Psalter (MissingThrowsDocblock, OverlyBroadThrowsDocblock, UnusedThrowsDocblock):
  - Подтверждение: `src/Psalm/Internal/FileManipulation/FunctionDocblockManipulator.php`, `tests/FileManipulation/ThrowsBlockAdditionTest.php`
- Анализ только измененных строк Git (--changed, --base, --full-file, --report-changed):
  - Подтверждение: `src/Psalm/Internal/Cli/GitChangedLines.php`, `src/Psalm/Internal/Analyzer/ChangedFileScope.php`
- Диагностический флаг вывода цепочек исключений (--show-inferred-throws):
  - Подтверждение: `src/Psalm/Internal/Analyzer/InferredThrowsBuffer.php`, `tests/EndToEnd/PsalmEndToEndTest.php`
- Кэширование выведенных исключений с проверкой зависимостей:
  - Подтверждение: `src/Psalm/Internal/Provider/InferredThrowsCache.php`, `tests/Internal/Analyzer/InferredThrowsBufferTest.php`
- Провайдеры исключений и типов плагинов (MethodThrowsProviderInterface, PropertyThrowsProviderInterface, MixedMethodReturnTypeProviderInterface):
  - Подтверждение: `src/Psalm/Plugin/EventHandler/MethodThrowsProviderInterface.php`, `src/Psalm/PluginRegistrationSocket.php`
- Встроенный плагин для Yii 2 (ActiveRecord, relations, lifecycle hooks, validation callbacks, DB-вызовы):
  - Подтверждение: `src/Psalm/Plugin/Yii2/Plugin.php`, `src/Psalm/Plugin/Yii2/ThrowsProvider.php`, `tests/Config/Yii2PluginTest.php`

## Отклонённые поверхности upstream

- Не сохранять существующий @throws как fallback для статически неразрешенных вызовов:
  - Запрещенные пути/символы: fallback к декларированному @throws при unresolved targets
  - Подтверждение: коммит `38ddb511b` (Remove unresolved throws fallback)

## Security-инварианты

- Безопасная обработка путей и отсутствие выполнения недоверенного кода при анализе Git diff:
  - Проверка: тесты `GitChangedLines`, тесты плагина и запуск на тестовых репозиториях без запуска произвольных внешних скриптов.

## Правила интеграции upstream

- Принять: общие оптимизации, улучшения системы типов, поддержку новых версий PHP (PHP 8.5), расширение stubs, исправления taint analysis, багфиксы ядра Psalm.
- Адаптировать: изменения в FunctionLikeAnalyzer, FunctionDocblockManipulator, StatementsAnalyzer, Codebase/Analyzer, Context, PluginRegistrationSocket, где логика upstream пересекается с выводом @throws, ChangedFileScope и Yii2-провайдерами.
- Отклонить: откаты к консервативному поведению с подавлением/потерей графа исключений или возвращение fallback на неразрешенные ребра.

## Ограничения CI и релизов

- Целевая ветка разработки форка — `teletype` (default на GitHub может быть `6.x`).
- Теги релизов форка следуют схеме `7.0.0-p<N>`.
- Не выполнять push в origin без явного подтверждения; не публиковать релизы в рамках синхронизации.

## Обязательные проверки

```sh
composer install
composer test
```
