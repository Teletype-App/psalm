<?php

declare(strict_types=1);

namespace Psalm\Tests\Config;

use Override;
use Psalm\Context;
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

        self::assertFalse($codebase->config->eventDispatcher->hasAfterClassLikeVisitHandlers());
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
}
