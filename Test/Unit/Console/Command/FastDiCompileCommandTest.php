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

    private ?string $originalMageMode = null;

    protected function setUp(): void
    {
        $this->testRoot = BP;
        $this->originalComposerAuth = getenv('COMPOSER_AUTH') === false ? null : (string) getenv('COMPOSER_AUTH');
        $this->originalMageMode = getenv('MAGE_MODE') === false ? null : (string) getenv('MAGE_MODE');
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

        if ($this->originalMageMode === null) {
            putenv('MAGE_MODE');
        } else {
            putenv('MAGE_MODE=' . $this->originalMageMode);
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

    private function createWatchBinary(string $version = 'fast-di-compile v1.0.5', int $exitCode = 0): string
    {
        $versionLine = var_export($version, true);

        return $this->createCompilerBinary(<<<PHP
if ((\$argv[1] ?? '') === '--version') {
    echo {$versionLine} . PHP_EOL;
    exit(0);
}
echo 'FAST_RECORD=' . json_encode(['argv' => array_slice(\$argv, 1), 'cwd' => getcwd()]) . PHP_EOL;
usleep(300000);
echo 'watch ended' . PHP_EOL;
exit({$exitCode});
PHP);
    }

    public function testWatchRunsTheBinaryWithoutTimeout(): void
    {
        putenv('MAGE_MODE=developer');
        $tester = new CommandTester($this->createCommand($this->createWatchBinary(), 0.05));

        $exitCode = $tester->execute(['--watch' => true, '--jobs' => '2']);

        $display = $tester->getDisplay();
        $record = $this->extractRecord($display);
        $this->assertSame(Command::SUCCESS, $exitCode, $display);
        $this->assertSame(
            [
                '--magento-root',
                $this->testRoot,
                '--fallback-php',
                realpath(PHP_BINARY),
                '--jobs',
                '2',
                '--watch',
                '--watch-exit-with-parent',
            ],
            $record['argv']
        );
        $this->assertStringContainsString('watch mode', $display);
        $this->assertStringContainsString('watch ended', $display);
        $this->assertStringNotContainsString('timed out', $display);
    }

    /**
     * @param array<string, mixed> $input
     */
    #[DataProvider('watchPollProvider')]
    public function testWatchPollPassesThrough(array $input, string $expectedFlag): void
    {
        putenv('MAGE_MODE=developer');
        $tester = new CommandTester($this->createCommand($this->createWatchBinary()));

        $tester->execute($input);

        $record = $this->extractRecord($tester->getDisplay());
        $this->assertSame([$expectedFlag, '--watch-exit-with-parent'], array_slice($record['argv'], -2));
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function watchPollProvider(): array
    {
        return [
            'default interval' => [['--watch-poll' => null], '--watch-poll'],
            'given interval' => [['--watch-poll' => '250'], '--watch-poll=250'],
        ];
    }

    public function testWatchReturnsTheExitCodeOfTheBinary(): void
    {
        putenv('MAGE_MODE=developer');
        $tester = new CommandTester($this->createCommand($this->createWatchBinary('fast-di-compile v1.0.5', 130)));

        $this->assertSame(130, $tester->execute(['--watch' => true]));
    }

    /**
     * @param array<string, mixed> $input
     */
    #[DataProvider('refusedWatchProvider')]
    public function testWatchIsRefusedBeforeStartingTheBinary(array $input, string $mode, string $expectedError): void
    {
        putenv('MAGE_MODE=' . $mode);
        $tester = new CommandTester($this->createCommand($this->createWatchBinary()));

        $exitCode = $tester->execute($input);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString($expectedError, $tester->getDisplay());
        $this->assertStringNotContainsString('FAST_RECORD', $tester->getDisplay());
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string, 2: string}>
     */
    public static function refusedWatchProvider(): array
    {
        return [
            'production mode' => [['--watch' => true], 'production', '--watch is for development; current mode is production.'],
            'with timeout' => [['--watch' => true, '--timeout' => '30'], 'developer', '--timeout cannot be combined with --watch.'],
            'with dry run' => [['--watch' => true, '--dry-run' => true], 'developer', '--dry-run cannot be combined with --watch.'],
            'with validate' => [['--watch-poll' => null, '--validate' => true], 'default', '--validate cannot be combined with --watch.'],
            'zero poll interval' => [['--watch-poll' => '0'], 'developer', '--watch-poll must be a whole number of milliseconds'],
            'poll interval too long' => [['--watch-poll' => '60001'], 'developer', '--watch-poll must be a whole number of milliseconds'],
        ];
    }

    /**
     * @param array<string, mixed> $input
     */
    #[DataProvider('standardWithWatchProvider')]
    public function testStandardWithWatchIsRefusedBeforeAnyCompiler(array $input): void
    {
        putenv('MAGE_MODE=production');
        $standardCommand = new class extends Command {
            public bool $executed = false;

            protected function configure(): void
            {
                $this->setName(DiCompileCommand::NAME);
            }

            protected function execute(InputInterface $input, OutputInterface $output): int
            {
                $this->executed = true;

                return Command::SUCCESS;
            }
        };
        $command = new FastDiCompileCommand($this->createWatchBinary(), $standardCommand);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute($input);

        $this->assertSame(1, $exitCode);
        $this->assertFalse($standardCommand->executed);
        $this->assertStringContainsString(
            '--standard cannot be combined with --watch or --watch-poll.',
            $tester->getDisplay()
        );
        $this->assertStringNotContainsString('FAST_RECORD', $tester->getDisplay());
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function standardWithWatchProvider(): array
    {
        return [
            'with watch' => [['--standard' => true, '--watch' => true]],
            'with watch poll' => [['--standard' => true, '--watch-poll' => '250']],
            'with watch poll default' => [['--standard' => true, '--watch-poll' => null]],
        ];
    }

    public function testProductionModeFromEnvPhpRefusesWatch(): void
    {
        putenv('MAGE_MODE');
        mkdir($this->testRoot . '/app/etc', 0755, true);
        file_put_contents($this->testRoot . '/app/etc/env.php', "<?php\nreturn ['MAGE_MODE' => 'production'];\n");
        $tester = new CommandTester($this->createCommand($this->createWatchBinary()));

        $this->assertSame(1, $tester->execute(['--watch' => true]));
        $this->assertStringContainsString('current mode is production', $tester->getDisplay());
    }

    #[DataProvider('binaryVersionProvider')]
    public function testWatchChecksTheBinaryVersion(string $reported, bool $accepted): void
    {
        putenv('MAGE_MODE=developer');
        $tester = new CommandTester($this->createCommand($this->createWatchBinary($reported)));

        $exitCode = $tester->execute(['--watch' => true]);

        $display = $tester->getDisplay();
        if ($accepted) {
            $this->assertSame(Command::SUCCESS, $exitCode, $display);
            $this->assertStringContainsString('FAST_RECORD', $display);
            return;
        }

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('--watch needs fast-di-compile 1.0.5 or newer', $display);
        $this->assertStringContainsString('install-fast-di-compile.php', $display);
        $this->assertStringNotContainsString('FAST_RECORD', $display);
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function binaryVersionProvider(): array
    {
        return [
            'release' => ['fast-di-compile v1.0.5', true],
            'newer patch' => ['fast-di-compile v1.0.10', true],
            'newer minor' => ['fast-di-compile v1.1.0', true],
            'newer release' => ['fast-di-compile v1.2.3', true],
            'local build' => ['fast-di-compile v1.0.5-3-gabc1234', true],
            'modified local build' => ['fast-di-compile v1.0.5-3-gabc1234-modified', true],
            'older release' => ['fast-di-compile v1.0.4', false],
            'older local build' => ['fast-di-compile v1.0.4-9-gabc1234', false],
            'unknown output' => ['something else', false],
            'no output' => ['', false],
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
