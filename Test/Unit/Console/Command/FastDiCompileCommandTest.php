<?php

declare(strict_types=1);

namespace Spmt\FastDiCompile\Test\Unit\Console\Command;

use Magento\Setup\Console\Command\DiCompileCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Spmt\FastDiCompile\Console\Command\FastDiCompileCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @covers \Spmt\FastDiCompile\Console\Command\FastDiCompileCommand
 */
class FastDiCompileCommandTest extends TestCase
{
    private string $testRoot;

    private ?string $originalComposerAuth = null;

    protected function setUp(): void
    {
        $this->testRoot = BP;
        $this->originalComposerAuth = getenv('COMPOSER_AUTH') === false ? null : (string) getenv('COMPOSER_AUTH');
        $this->resetTestRoot();
        mkdir($this->testRoot . '/var/tmp', 0755, true);
        mkdir($this->testRoot . '/generated', 0755, true);
    }

    protected function tearDown(): void
    {
        if ($this->originalComposerAuth === null) {
            putenv('COMPOSER_AUTH');
        } else {
            putenv('COMPOSER_AUTH=' . $this->originalComposerAuth);
        }

        $this->resetTestRoot();
    }

    public function testStandardOptionDelegatesToStandardCommand(): void
    {
        $standardCommand = new class extends Command {
            public bool $executed = false;

            protected function configure(): void
            {
                $this->setName(DiCompileCommand::NAME);
            }

            protected function execute(InputInterface $input, OutputInterface $output): int
            {
                $this->executed = true;
                $output->writeln('standard compiler ran');

                return Command::SUCCESS;
            }
        };

        $tester = new CommandTester(new FastDiCompileCommand('/not-used', $standardCommand));
        $exitCode = $tester->execute(['--standard' => true]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertTrue($standardCommand->executed);
        $this->assertStringContainsString('Running standard setup:di:compile', $tester->getDisplay());
        $this->assertStringContainsString('standard compiler ran', $tester->getDisplay());
    }

    public function testRunsCompilerWithSupportedOptionsAndSanitizedEnvironment(): void
    {
        putenv('COMPOSER_AUTH=super-secret');
        $binary = $this->createCompilerBinary(<<<'PHP'
$record = [
    'argv' => array_slice($argv, 1),
    'cwd' => getcwd(),
    'composer_auth' => getenv('COMPOSER_AUTH') === false ? 'unset' : 'set',
    'path' => getenv('PATH') === false ? 'unset' : 'set',
];
echo 'FAST_RECORD=' . json_encode($record) . PHP_EOL;
echo "STDOUT_CONTROL:\x1B[31mred\0" . PHP_EOL;
fwrite(STDERR, "STDERR_CONTROL:\x1B[32mgreen\x7F" . PHP_EOL);
exit(0);
PHP);
        $tester = new CommandTester($this->createCommand($binary));
        $fallbackPhp = realpath(PHP_BINARY);

        $this->assertIsString($fallbackPhp);

        $exitCode = $tester->execute(
            [
                '--jobs' => '4',
                '--timeout' => '30',
                '--fallback-php' => $fallbackPhp,
                '--php-generated' => 'generated',
                '--output' => 'var/tmp/fast-di-output',
                '--archive-root' => 'var/tmp/magento-di-baseline',
                '--compare-report-dir' => 'var/tmp/fast-di-output/diff',
                '--validate' => true,
                '--incremental' => true,
                '--dry-run' => true,
                '--compare-archive' => true,
                '--compare-fail-on-diff' => true,
                '--ignore-constructor-integrity' => true,
            ],
            ['verbosity' => OutputInterface::VERBOSITY_VERBOSE]
        );

        $display = $tester->getDisplay();
        $record = $this->extractRecord($display);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertSame($this->testRoot, $record['cwd']);
        $this->assertSame('unset', $record['composer_auth']);
        $this->assertSame('set', $record['path']);
        $this->assertSame(
            [
                '--magento-root',
                $this->testRoot,
                '--fallback-php',
                $fallbackPhp,
                '--jobs',
                '4',
                '--php-generated',
                $this->testRoot . '/generated',
                '--output',
                $this->testRoot . '/var/tmp/fast-di-output',
                '--archive-root',
                $this->testRoot . '/var/tmp/magento-di-baseline',
                '--compare-report-dir',
                $this->testRoot . '/var/tmp/fast-di-output/diff',
                '--validate',
                '--incremental',
                '--dry-run',
                '--compare-archive',
                '--compare-fail-on-diff',
                '--ignore-constructor-integrity',
                '--verbose',
            ],
            $record['argv']
        );
        $this->assertStringContainsString('DI compilation complete.', $display);
        $this->assertStringNotContainsString("\x1B", $display);
        $this->assertStringNotContainsString("\0", $display);
        $this->assertStringNotContainsString("\x7F", $display);
    }

    public function testReturnsFailureWhenCompilerExitsNonZero(): void
    {
        $binary = $this->createCompilerBinary(<<<'PHP'
fwrite(STDERR, 'compiler failed' . PHP_EOL);
exit(7);
PHP);
        $tester = new CommandTester($this->createCommand($binary));

        $exitCode = $tester->execute(['--dry-run' => true]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('compiler failed', $tester->getDisplay());
        $this->assertStringContainsString('fast-di-compile exited with code 7', $tester->getDisplay());
    }

    public function testReturnsFailureWhenCompilerTimesOut(): void
    {
        $binary = $this->createCompilerBinary(<<<'PHP'
usleep(500000);
echo 'finished' . PHP_EOL;
exit(0);
PHP);
        $tester = new CommandTester($this->createCommand($binary, 0.1));

        $exitCode = $tester->execute(['--dry-run' => true]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('fast-di-compile timed out after 0.1 seconds', $tester->getDisplay());
        $this->assertStringNotContainsString('finished', $tester->getDisplay());
    }

    public function testTimeoutOptionOverridesConfiguredDefault(): void
    {
        $binary = $this->createCompilerBinary(<<<'PHP'
usleep(200000);
echo 'finished' . PHP_EOL;
exit(0);
PHP);
        $tester = new CommandTester($this->createCommand($binary, 0.05));

        $exitCode = $tester->execute(['--timeout' => '1']);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('finished', $tester->getDisplay());
        $this->assertStringContainsString('DI compilation complete.', $tester->getDisplay());
    }

    /**
     * @param array<string, mixed> $input
     */
    #[DataProvider('invalidInputProvider')]
    public function testRejectsInvalidInputBeforeStartingCompiler(array $input, string $expectedError): void
    {
        $binary = $this->createCompilerBinary(<<<'PHP'
echo 'compiler should not run' . PHP_EOL;
exit(0);
PHP);
        $tester = new CommandTester($this->createCommand($binary));

        $exitCode = $tester->execute($input);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString($expectedError, $tester->getDisplay());
        $this->assertStringNotContainsString('compiler should not run', $tester->getDisplay());
    }

    public function testRejectsSymlinkedPathOptionAncestor(): void
    {
        if (!symlink($this->testRoot . '/generated', $this->testRoot . '/var/tmp/generated-link')) {
            $this->markTestSkipped('Symlinks are not available in this environment.');
        }

        $binary = $this->createCompilerBinary(<<<'PHP'
echo 'compiler should not run' . PHP_EOL;
exit(0);
PHP);
        $tester = new CommandTester($this->createCommand($binary));

        $exitCode = $tester->execute(['--output' => 'var/tmp/generated-link/output']);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('--output must not include symlinked path components.', $tester->getDisplay());
        $this->assertStringNotContainsString('compiler should not run', $tester->getDisplay());
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidInputProvider(): array
    {
        return [
            'absolute path outside root' => [
                ['--output' => '/tmp/fast-di-output'],
                '--output must resolve inside Magento root.',
            ],
            'relative path outside root' => [
                ['--output' => '../fast-di-output'],
                '--output must resolve inside Magento root.',
            ],
            'empty output path' => [
                ['--output' => ''],
                '--output must be a non-empty path.',
            ],
            'null byte output path' => [
                ['--output' => "var/tmp/fast\0output"],
                '--output must be a non-empty path.',
            ],
            'zero jobs' => [
                ['--jobs' => '0'],
                '--jobs must be a positive integer.',
            ],
            'non numeric jobs' => [
                ['--jobs' => 'lots'],
                '--jobs must be a positive integer.',
            ],
            'too many jobs' => [
                ['--jobs' => '257'],
                '--jobs must be less than or equal to 256.',
            ],
            'zero timeout' => [
                ['--timeout' => '0'],
                '--timeout must be a positive integer.',
            ],
            'non numeric timeout' => [
                ['--timeout' => 'soon'],
                '--timeout must be a positive integer.',
            ],
            'timeout above maximum' => [
                ['--timeout' => '86401'],
                '--timeout must be less than or equal to 86400.',
            ],
            'arbitrary fallback executable' => [
                ['--fallback-php' => '/bin/sh'],
                '--fallback-php must resolve to the PHP binary running Magento CLI:',
            ],
        ];
    }

    private function createCommand(string $binary, float $timeout = 15.0): FastDiCompileCommand
    {
        return new FastDiCompileCommand($binary, $this->createStandardCommand(), $timeout);
    }

    private function createStandardCommand(): Command
    {
        return new class extends Command {
            protected function configure(): void
            {
                $this->setName(DiCompileCommand::NAME);
            }

            protected function execute(InputInterface $input, OutputInterface $output): int
            {
                return Command::SUCCESS;
            }
        };
    }

    private function createCompilerBinary(string $scriptBody): string
    {
        $path = $this->testRoot . '/var/tmp/fast-di-compile-' . bin2hex(random_bytes(6));
        file_put_contents($path, "#!/usr/bin/env php\n<?php\n" . $scriptBody . PHP_EOL);
        chmod($path, 0755);

        return $path;
    }

    /**
     * @return array{argv: string[], cwd: string, composer_auth: string, path: string}
     */
    private function extractRecord(string $display): array
    {
        $this->assertMatchesRegularExpression('/FAST_RECORD=(\{.*\})/', $display);
        preg_match('/FAST_RECORD=(\{.*\})/', $display, $matches);
        $record = json_decode($matches[1], true);

        $this->assertIsArray($record);

        return $record;
    }

    private function resetTestRoot(): void
    {
        if (is_dir($this->testRoot)) {
            $this->removePath($this->testRoot);
        }

        mkdir($this->testRoot, 0755, true);
    }

    private function removePath(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);
            return;
        }

        if (!is_dir($path)) {
            return;
        }

        $iterator = new \FilesystemIterator($path, \FilesystemIterator::SKIP_DOTS);
        foreach ($iterator as $item) {
            $this->removePath($item->getPathname());
        }

        rmdir($path);
    }
}
