<?php

declare(strict_types=1);

namespace Psalm\Tests\Config;

use Override;
use Psalm\Context;
use Psalm\Exception\CodeException;
use Psalm\Internal\Analyzer\FileAnalyzer;
use Psalm\Plugin\EventHandler\Event\AfterCodebasePopulatedEvent;
use Psalm\Plugin\Yii2\Plugin;
use Psalm\PluginRegistrationSocket;
use Psalm\Tests\TestCase;

use function getcwd;

final class Yii2PluginTest extends TestCase
{
    #[Override]
    public function setUp(): void
    {
        parent::setUp();

        $codebase = $this->project_analyzer->getCodebase();
        (new Plugin())(new PluginRegistrationSocket($codebase->config, $codebase));

        self::assertTrue($codebase->config->eventDispatcher->hasAfterClassLikeVisitHandlers());
    }

    public function testActiveRecordQueriesAndMagicProperties(): void
    {
        $file_path = (string) getcwd() . '/src/yii.php';
        $this->addFile(
            $file_path,
            <<<'PHP'
                <?php

                namespace yii\base {
                    class Component {
                        /** @return mixed */
                        public function __get(string $name) {
                            return null;
                        }
                    }
                }

                namespace yii\db {
                    /** @template T of ActiveRecord */
                    class ActiveQuery {}

                    class BaseActiveRecord extends \yii\base\Component {
                        /** @param mixed $condition */
                        public static function findOne($condition): ?self { return null; }
                        /** @param mixed $condition */
                        public static function findAll($condition): array { return []; }
                    }

                    class ActiveRecord extends BaseActiveRecord {
                        public static function find(): ActiveQuery { return new ActiveQuery(); }
                        /** @param class-string<ActiveRecord> $class */
                        public function hasOne($class, array $link): ActiveQuery { return new ActiveQuery(); }
                        /** @param class-string<ActiveRecord> $class */
                        public function hasMany($class, array $link): ActiveQuery { return new ActiveQuery(); }
                    }
                }

                namespace app\models {
                    use yii\db\ActiveQuery;
                    use yii\db\ActiveRecord;

                    class Author extends ActiveRecord {}
                    class Tag extends ActiveRecord {}

                    class BasePost extends ActiveRecord {
                        /** @return ActiveQuery */
                        public function getAuthor() {
                            return $this->hasOne(Author::class, ['id' => 'author_id']);
                        }

                        /** @return ActiveQuery */
                        public function getTags() {
                            return $this->hasMany(Tag::class, ['post_id' => 'id']);
                        }

                        public function getDisplayName(): string {
                            return 'post';
                        }
                    }

                    class Post extends BasePost {}

                    /** @param ActiveQuery<Post> $query */
                    function acceptPostQuery(ActiveQuery $query): void {}
                    function acceptPost(?Post $post): void {}
                    /** @param array<array-key, Post> $posts */
                    function acceptPosts(array $posts): void {}
                    function acceptAuthor(?Author $author): void {}
                    /** @param array<array-key, Tag> $tags */
                    function acceptTags(array $tags): void {}

                    acceptPostQuery(Post::find());
                    acceptPost(Post::findOne(1));
                    acceptPosts(Post::findAll([]));

                    $post = new Post();
                    acceptAuthor($post->author);
                    acceptTags($post->tags);
                    strlen($post->displayName);
                }
                PHP,
        );

        $this->project_analyzer->initExtraFiles();
        $this->project_analyzer->initProjectFiles();
        $codebase = $this->project_analyzer->getCodebase();
        $codebase->addFilesToAnalyze([$file_path => $file_path]);
        $codebase->scanFiles();
        $codebase->config->eventDispatcher->dispatchAfterCodebasePopulated(
            new AfterCodebasePopulatedEvent($codebase),
        );

        (new FileAnalyzer($this->project_analyzer, $file_path, 'src/yii.php'))->analyze(new Context());
    }

    public function testInitThrowsPropagateThroughBaseObjectConstructor(): void
    {
        $this->expectException(CodeException::class);
        $this->expectExceptionMessage('DomainException is thrown but not caught');
        $this->testConfig->check_for_throws_docblock = true;

        $this->analyzeYiiFile(<<<'PHP'
            <?php

            namespace yii\base {
                class BaseObject {
                    public function __construct(array $config = []) { $this->init(); }
                    public function init(): void {}
                }
            }

            namespace app {
                class Service extends \yii\base\BaseObject {
                    /** @throws \DomainException */
                    public function init(): void { throw new \DomainException(); }
                }

                function make(): void { new Service(); }
            }
            PHP);
    }

    public function testActiveRecordLifecycleThrowsPropagateThroughSave(): void
    {
        $this->expectException(CodeException::class);
        $this->expectExceptionMessage('RuntimeException is thrown but not caught');
        $this->testConfig->check_for_throws_docblock = true;

        $this->analyzeYiiFile(<<<'PHP'
            <?php

            namespace yii\base {
                class Model {
                    public function validate(): bool { return true; }
                    public function beforeValidate(): bool { return true; }
                    public function afterValidate(): void {}
                }
            }

            namespace yii\db {
                class BaseActiveRecord extends \yii\base\Model {
                    public function save(bool $runValidation = true): bool { return true; }
                    public function beforeSave(bool $insert): bool { return true; }
                    public function afterSave(bool $insert, array $changedAttributes): void {}
                }
            }

            namespace app {
                class Record extends \yii\db\BaseActiveRecord {
                    /** @throws \RuntimeException */
                    public function afterSave(bool $insert, array $changedAttributes): void {
                        throw new \RuntimeException();
                    }
                }

                function persist(Record $record): void { $record->save(); }
            }
            PHP);
    }

    public function testLiteralEventHandlerThrowsPropagateThroughTrigger(): void
    {
        $this->expectException(CodeException::class);
        $this->expectExceptionMessage('UnexpectedValueException is thrown but not caught');
        $this->testConfig->check_for_throws_docblock = true;

        $this->analyzeYiiFile(<<<'PHP'
            <?php

            namespace yii\base {
                class Component {
                    public function on(string $name, callable $handler): void {}
                    public function trigger(string $name): void {}
                }
            }

            namespace app {
                final class Listener {
                    /** @throws \UnexpectedValueException */
                    public static function handle(): void { throw new \UnexpectedValueException(); }
                }

                final class Channel extends \yii\base\Component {
                    public const EVENT_SEND = 'send';

                    public function init(): void {
                        $this->on(self::EVENT_SEND, [Listener::class, 'handle']);
                    }

                    public function send(): void { $this->trigger(self::EVENT_SEND); }
                }
            }
            PHP);
    }

    public function testCreateObjectPropagatesInitThrows(): void
    {
        $this->expectException(CodeException::class);
        $this->expectExceptionMessage('LengthException is thrown but not caught');
        $this->testConfig->check_for_throws_docblock = true;

        $this->analyzeYiiFile(<<<'PHP'
            <?php

            namespace yii {
                class BaseYii {
                    /** @param class-string $class */
                    public static function createObject(string $class): object { return new \stdClass(); }
                }
            }

            namespace yii\base {
                class BaseObject {
                    public function __construct(array $config = []) { $this->init(); }
                    public function init(): void {}
                }
            }

            namespace app {
                final class Service extends \yii\base\BaseObject {
                    /** @throws \LengthException */
                    public function init(): void { throw new \LengthException(); }
                }

                function make(): void { \yii\BaseYii::createObject(Service::class); }
            }
            PHP);
    }

    public function testSaveFalseDoesNotPropagateValidationHooks(): void
    {
        $this->testConfig->check_for_throws_docblock = true;

        $this->analyzeYiiFile(<<<'PHP'
            <?php

            namespace yii\base {
                class Model {
                    public function beforeValidate(): bool { return true; }
                    public function afterValidate(): void {}
                }
            }

            namespace yii\db {
                class BaseActiveRecord extends \yii\base\Model {
                    public function save(bool $runValidation = true): bool { return true; }
                    public function beforeSave(bool $insert): bool { return true; }
                    public function afterSave(bool $insert, array $changedAttributes): void {}
                }
            }

            namespace app {
                final class Record extends \yii\db\BaseActiveRecord {
                    /** @throws \LogicException */
                    public function beforeValidate(): bool { throw new \LogicException(); }
                }

                function persist(Record $record): void { $record->save(false); }
            }
            PHP);
    }

    public function testFindAndRefreshLifecycleThrowsPropagate(): void
    {
        $this->expectException(CodeException::class);
        $this->expectExceptionMessage('OutOfBoundsException is thrown but not caught');
        $this->testConfig->check_for_throws_docblock = true;

        $this->analyzeYiiFile(<<<'PHP'
            <?php

            namespace yii\db {
                class BaseActiveRecord {
                    public static function findOne(int $id): ?static { return null; }
                    public function refresh(): bool { return true; }
                    public function afterFind(): void {}
                    public function afterRefresh(): void {}
                }
            }

            namespace app {
                final class Record extends \yii\db\BaseActiveRecord {
                    /** @throws \OutOfBoundsException */
                    public function afterFind(): void { throw new \OutOfBoundsException(); }

                    /** @throws \OverflowException */
                    public function afterRefresh(): void { throw new \OverflowException(); }
                }

                function load(): void { Record::findOne(1); }
                /** @throws \OverflowException */
                function reload(Record $record): void { $record->refresh(); }
            }
            PHP);
    }

    public function testActiveQueryExecutionPropagatesDatabaseException(): void
    {
        $this->expectException(CodeException::class);
        $this->expectExceptionMessage('yii\\db\\Exception is thrown but not caught');
        $this->testConfig->check_for_throws_docblock = true;

        $this->analyzeYiiFile(<<<'PHP'
            <?php

            namespace yii\db {
                class Exception extends \Exception {}

                class Command {
                    /**
                     * @throws Exception
                     * @psalm-suppress UnusedThrowsDocblock
                     */
                    public function queryOne(): bool { return false; }
                }

                class Query {
                    public function where(array $condition): self { return $this; }
                    public function one() { return false; }
                }
            }

            namespace app {
                function build(\yii\db\Query $query): \yii\db\Query {
                    return $query->where(['active' => true]);
                }

                function execute(\yii\db\Query $query): void { $query->one(); }
            }
            PHP);
    }

    public function testActiveQueryBuilderDoesNotPropagateDatabaseException(): void
    {
        $this->testConfig->check_for_throws_docblock = true;

        $this->analyzeYiiFile(<<<'PHP'
            <?php

            namespace yii\db {
                class Exception extends \Exception {}

                class Command {
                    /**
                     * @throws Exception
                     * @psalm-suppress UnusedThrowsDocblock
                     */
                    public function queryOne(): bool { return false; }
                }

                class Query {
                    public function where(array $condition): self { return $this; }
                }
            }

            namespace app {
                function build(\yii\db\Query $query): \yii\db\Query {
                    return $query->where(['active' => true]);
                }
            }
            PHP);
    }

    public function testActiveRecordMagicGetterThrowsPropagate(): void
    {
        $this->expectException(CodeException::class);
        $this->expectExceptionMessage('DomainException is thrown but not caught');
        $this->testConfig->check_for_throws_docblock = true;

        $this->analyzeYiiFile(<<<'PHP'
            <?php

            namespace yii\base {
                class Component {
                    /** @return mixed */
                    public function __get(string $name) { return null; }
                }
            }

            namespace yii\db {
                class BaseActiveRecord extends \yii\base\Component {}
                class ActiveRecord extends BaseActiveRecord {}
            }

            namespace app {
                final class Record extends \yii\db\ActiveRecord {
                    /** @throws \DomainException */
                    public function getDisplayName(): string { throw new \DomainException(); }
                }

                function display(Record $record): void { echo $record->displayName; }
            }
            PHP);
    }

    public function testActiveRecordMagicSetterThrowsPropagate(): void
    {
        $this->expectException(CodeException::class);
        $this->expectExceptionMessage('LengthException is thrown but not caught');
        $this->testConfig->check_for_throws_docblock = true;

        $this->analyzeYiiFile(<<<'PHP'
            <?php

            namespace yii\base {
                class Component {
                    /** @param mixed $value */
                    public function __set(string $name, $value): void {}
                }
            }

            namespace yii\db {
                class BaseActiveRecord extends \yii\base\Component {}
                class ActiveRecord extends BaseActiveRecord {}
            }

            namespace app {
                final class Record extends \yii\db\ActiveRecord {
                    /** @throws \LengthException */
                    public function setDisplayName(string $value): void { throw new \LengthException(); }
                }

                function rename(Record $record): void { $record->displayName = 'new'; }
            }
            PHP);
    }

    public function testComponentMagicGetterThrowsPropagate(): void
    {
        $this->expectException(CodeException::class);
        $this->expectExceptionMessage('DomainException is thrown but not caught');
        $this->testConfig->check_for_throws_docblock = true;

        $this->analyzeYiiFile(<<<'PHP'
            <?php

            namespace yii\base {
                class Component {
                    /** @return mixed */
                    public function __get(string $name) { return null; }
                }
            }

            namespace app {
                /** @property-read string $token */
                final class Service extends \yii\base\Component {
                    /** @throws \DomainException */
                    public function getToken(): string { throw new \DomainException(); }
                }

                function consume(Service $service): void { echo $service->token; }
            }
            PHP);
    }

    public function testComponentMagicSetterThrowsPropagate(): void
    {
        $this->expectException(CodeException::class);
        $this->expectExceptionMessage('LengthException is thrown but not caught');
        $this->testConfig->check_for_throws_docblock = true;

        $this->analyzeYiiFile(<<<'PHP'
            <?php

            namespace yii\base {
                class Component {
                    /** @param mixed $value */
                    public function __set(string $name, $value): void {}
                }
            }

            namespace app {
                /** @property-write string $token */
                final class Service extends \yii\base\Component {
                    /** @throws \LengthException */
                    public function setToken(string $token): void { throw new \LengthException(); }
                }

                function configure(Service $service): void { $service->token = 'secret'; }
            }
            PHP);
    }

    public function testActiveRecordRelationPropertyPropagatesDatabaseException(): void
    {
        $this->expectException(CodeException::class);
        $this->expectExceptionMessage('yii\\db\\Exception is thrown but not caught');
        $this->testConfig->check_for_throws_docblock = true;

        $this->analyzeYiiFile(<<<'PHP'
            <?php

            namespace yii\base {
                class Component {
                    public function __get(string $name) { return null; }
                }
            }

            namespace yii\db {
                class Exception extends \Exception {}

                class Command {
                    /**
                     * @throws Exception
                     * @psalm-suppress UnusedThrowsDocblock
                     */
                    public function queryOne(): bool { return false; }
                }

                class ActiveQuery {}
                class BaseActiveRecord extends \yii\base\Component {}
                class ActiveRecord extends BaseActiveRecord {
                    /** @param class-string<ActiveRecord> $class */
                    public function hasOne($class, array $link): ActiveQuery { return new ActiveQuery(); }
                }
            }

            namespace app {
                final class Author extends \yii\db\ActiveRecord {}

                /** @property-read Author|null $author */
                final class Post extends \yii\db\ActiveRecord {
                    public function getAuthor(): \yii\db\ActiveQuery {
                        return $this->hasOne(Author::class, ['id' => 'author_id']);
                    }
                }

                function loadAuthor(Post $post): void { $author = $post->author; }
            }
            PHP);
    }

    public function testInlineValidatorThrowsPropagateThroughValidate(): void
    {
        $this->expectException(CodeException::class);
        $this->expectExceptionMessage('UnexpectedValueException is thrown but not caught');
        $this->testConfig->check_for_throws_docblock = true;

        $this->analyzeYiiFile(<<<'PHP'
            <?php

            namespace yii\base {
                class Model {
                    public function rules(): array { return []; }
                    public function validate(): bool { return true; }
                    public function beforeValidate(): bool { return true; }
                    public function afterValidate(): void {}
                }
            }

            namespace app {
                final class Form extends \yii\base\Model {
                    public function rules(): array {
                        return [[['email'], 'validateEmail']];
                    }

                    /** @throws \UnexpectedValueException */
                    public function validateEmail(string $attribute): void {
                        throw new \UnexpectedValueException();
                    }
                }

                function submit(Form $form): void { $form->validate(); }
            }
            PHP);
    }

    public function testNestedRulesListValidatorThrowsPropagateThroughValidate(): void
    {
        $this->expectException(CodeException::class);
        $this->expectExceptionMessage('RangeException is thrown but not caught');
        $this->testConfig->check_for_throws_docblock = true;

        $this->analyzeYiiFile(<<<'PHP'
            <?php

            namespace yii\base {
                class Model {
                    public function validate(): bool { return true; }
                    public function beforeValidate(): bool { return true; }
                    public function afterValidate(): void {}
                }
            }

            namespace app {
                final class Form extends \yii\base\Model {
                    public function rulesList(): array {
                        return [[['ids'], 'each', 'rule' => ['validateId']]];
                    }

                    /** @throws \RangeException */
                    public function validateId(string $attribute): void { throw new \RangeException(); }
                }

                function submit(Form $form): void { $form->validate(); }
            }
            PHP);
    }

    public function testStaticCallableValidatorOptionThrowsPropagateThroughValidate(): void
    {
        $this->expectException(CodeException::class);
        $this->expectExceptionMessage('UnexpectedValueException is thrown but not caught');
        $this->testConfig->check_for_throws_docblock = true;

        $this->analyzeYiiFile(<<<'PHP'
            <?php

            namespace yii\base {
                class Model {
                    public function rules(): array { return []; }
                    public function validate(): bool { return true; }
                    public function beforeValidate(): bool { return true; }
                    public function afterValidate(): void {}
                }
            }

            namespace app {
                final class EmailFilter {
                    /** @throws \UnexpectedValueException */
                    public static function apply(string $value): string {
                        throw new \UnexpectedValueException();
                    }
                }

                final class Form extends \yii\base\Model {
                    public function rules(): array {
                        return [[['email'], 'filter', 'filter' => [EmailFilter::class, 'apply']]];
                    }
                }

                function submit(Form $form): void { $form->validate(); }
            }
            PHP);
    }

    public function testLiteralUnsafeSetAttributesPropagatesMagicSetterThrows(): void
    {
        $this->expectException(CodeException::class);
        $this->expectExceptionMessage('RangeException is thrown but not caught');
        $this->testConfig->check_for_throws_docblock = true;

        $this->analyzeYiiFile(<<<'PHP'
            <?php

            namespace yii\base {
                class Model {
                    public function setAttributes(array $values, bool $safeOnly = true): void {}
                }
            }

            namespace app {
                final class Form extends \yii\base\Model {
                    /** @throws \RangeException */
                    public function setSecret(string $secret): void { throw new \RangeException(); }
                }

                function hydrate(Form $form): void {
                    $form->setAttributes(['secret' => 'value'], false);
                }
            }
            PHP);
    }

    public function testSafeSetAttributesDoesNotGuessMagicSetterTargets(): void
    {
        $this->testConfig->check_for_throws_docblock = true;

        $this->analyzeYiiFile(<<<'PHP'
            <?php

            namespace yii\base {
                class Model {
                    public function setAttributes(array $values, bool $safeOnly = true): void {}
                }
            }

            namespace app {
                final class Form extends \yii\base\Model {
                    /** @throws \RangeException */
                    public function setSecret(string $secret): void { throw new \RangeException(); }
                }

                function hydrate(Form $form, array $input): void {
                    $form->setAttributes($input);
                }
            }
            PHP);
    }

    public function testSaveFalseDoesNotPropagateInlineValidators(): void
    {
        $this->testConfig->check_for_throws_docblock = true;

        $this->analyzeYiiFile(<<<'PHP'
            <?php

            namespace yii\base {
                class Model {
                    public function validate(): bool { return true; }
                    public function beforeValidate(): bool { return true; }
                    public function afterValidate(): void {}
                }
            }

            namespace yii\db {
                class BaseActiveRecord extends \yii\base\Model {
                    public function save(bool $runValidation = true): bool { return true; }
                    public function beforeSave(bool $insert): bool { return true; }
                    public function afterSave(bool $insert, array $changedAttributes): void {}
                }
            }

            namespace app {
                final class Record extends \yii\db\BaseActiveRecord {
                    public function rules(): array { return [[['name'], 'validateName']]; }

                    /** @throws \DomainException */
                    public function validateName(string $attribute): void { throw new \DomainException(); }
                }

                function persist(Record $record): void { $record->save(false); }
            }
            PHP);
    }

    private function analyzeYiiFile(string $contents): void
    {
        $file_path = (string) getcwd() . '/src/yii-throws.php';
        $this->addFile($file_path, $contents);

        $this->project_analyzer->initExtraFiles();
        $this->project_analyzer->initProjectFiles();
        $codebase = $this->project_analyzer->getCodebase();
        $codebase->addFilesToAnalyze([$file_path => $file_path]);
        $codebase->scanFiles();
        $codebase->config->eventDispatcher->dispatchAfterCodebasePopulated(
            new AfterCodebasePopulatedEvent($codebase),
        );

        (new FileAnalyzer($this->project_analyzer, $file_path, 'src/yii-throws.php'))->analyze(new Context());
    }
}
