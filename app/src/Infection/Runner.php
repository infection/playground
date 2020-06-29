<?php

declare(strict_types=1);

namespace App\Infection;

use App\Utils\DirectoryCreator;
use function file_exists;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class Runner
{
    private const PROCESS_TIMEOUT_SEC = 20;

    private DirectoryCreator $directoryCreator;
    private Filesystem $filesystem;

    public function __construct(DirectoryCreator $directoryCreator, Filesystem $filesystem)
    {
        $this->directoryCreator = $directoryCreator;
        $this->filesystem = $filesystem;
    }

    public function run(string $idHash, string $code, string $test, string $config): RunResult
    {
        $rootDir = __DIR__ . '/../../infection-builds/' . $idHash;

        $srcDir = sprintf('%s/src', $rootDir);
        $testsDir = sprintf('%s/tests', $rootDir);

        $this->filesystem->mkdir($srcDir);
        $this->filesystem->mkdir($testsDir);

        $this->filesystem->dumpFile(sprintf('%s/phpunit.xml', $rootDir), $this->getPhpUnitXmlConfig());

        $this->filesystem->dumpFile(sprintf('%s/infection.json', $rootDir), $config);
        $this->filesystem->dumpFile(sprintf('%s/autoload.php', $rootDir), $this->getAutoload());
        $this->filesystem->dumpFile(sprintf('%s/SourceClass.php', $srcDir), $code);
        $this->filesystem->dumpFile(sprintf('%s/SourceClassTest.php', $testsDir), $test);

        try {
            $process = new Process(['php', '--define', 'memory_limit=100M', '../infection.phar', '--log-verbosity=all', '--ansi', '--no-progress'], $rootDir);
            $process->setTimeout(self::PROCESS_TIMEOUT_SEC);

            $process->run();
            // todo download if not present infection/phpunit (cache warmer)?
            // todo remove tmp folder

            $infectionJsonLogPath = sprintf('%s/infection.log.json', $rootDir);

            return new RunResult(
                $process->getOutput() . $process->getErrorOutput(),
                file_exists($infectionJsonLogPath) ? (string) file_get_contents($infectionJsonLogPath) : null
            );
        } catch (ProcessTimedOutException $e) {
            return RunResult::fromError(
                sprintf(
                    'Infection process exceeded the timeout of %d seconds. Please check your code or report an issue',
                    self::PROCESS_TIMEOUT_SEC
                )
            );
        }
    }

    private function getPhpUnitXmlConfig(): string
    {
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<phpunit backupGlobals="false"
         backupStaticAttributes="false"
         bootstrap="./autoload.php"
         colors="true"
         convertErrorsToExceptions="true"
         convertNoticesToExceptions="true"
         convertWarningsToExceptions="true"
         processIsolation="false"
         stopOnFailure="true"
>
    <testsuites>
        <testsuite name="Application Test Suite">
            <directory>./tests/</directory>
        </testsuite>
    </testsuites>

    <filter>
        <whitelist>
            <directory>./src/</directory>
        </whitelist>
    </filter>
</phpunit>
XML;
    }

    private function getAutoload(): string
    {
        return <<<'PHP'
<?php

use Infected\Psr4AutoloaderClass;

require_once __DIR__ . '/../Psr4AutoloaderClass.php';

$loader = new Psr4AutoloaderClass();

// register the autoloader
$loader->register();

// register the base directories for the namespace prefix
$loader->addNamespace('Infected', __DIR__ . '/src');
$loader->addNamespace('Infected\Tests', __DIR__ . '/tests');
PHP;
    }
}
