<?php

declare(strict_types=1);

namespace App\Request;

use App\Entity\Example;
use Symfony\Component\Validator\Constraints as Assert;

class CreateExampleRequest
{
    private const CODE_DEFAULT_VALUE = <<<'PHP'
<?php

declare(strict_types=1);

namespace Infected;

class SourceClass
{
    public function add(int $a, int $b): int
    {
        return $a + $b;
    }
}
PHP;

    private const TEST_DEFAULT_VALUE = <<<'PHP'
<?php

declare(strict_types=1);

namespace Infected\Tests;

use Infected\SourceClass;
use PHPUnit\Framework\TestCase;

class SourceClassTest extends TestCase
{
    public function test_it_adds_2_numbers(): void
    {
        $source = new SourceClass();

        $result = $source->add(1, 2);

        self::assertSame(3, $result);
    }
}
PHP;

    private const CONFIG_DEFAULT_VALUE = <<<'JSON'
{
    "bootstrap": "./autoload.php",
    "timeout": 10,
    "source": {
        "directories": [
            "src"
        ]
    },
    "phpUnit": {
        "customPath": "..\/phpunit.phar"
    },
    "logs": {
        "text": "infection.log"
    },
    "mutators": {
        "@default": true
    }
}
JSON;

    /**
     * @Assert\NotBlank()
     *
     * @var string
     */
    public $code = self::CODE_DEFAULT_VALUE;

    /**
     * @Assert\NotBlank()
     *
     * @var string
     */
    public $test = self::TEST_DEFAULT_VALUE;

    /**
     * @Assert\NotBlank()
     *
     * @var string
     */
    public $config = self::CONFIG_DEFAULT_VALUE;

    /**
     * @var string
     */
    public $resultOutput;

    public static function fromEntity(Example $example): self
    {
        $self = new self();

        $self->code = $example->getCode();
        $self->test = $example->getTest();
        $self->config = $example->getConfig();

        return $self;
    }
}
