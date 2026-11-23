<?php

declare(strict_types=1);

namespace Spmt\FastDiCompile\Console\Command;

use InvalidArgumentException;
use Magento\Framework\Console\Cli;
use Magento\Setup\Console\Command\DiCompileCommand;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class FastDiCompileCommand extends Command
{
    /**
     * Maximum time allowed for the Rust compiler process.
     */
    private const PROCESS_TIMEOUT_SECONDS = 15;

    /**
     * Maximum configurable compiler timeout.
     */
    private const MAX_PROCESS_TIMEOUT_SECONDS = 86400;

    /**
     * Maximum accepted worker count for the Rust compiler.
     */
    private const MAX_JOBS = 256;

    /**
     * First fast-di-compile release with --watch.
     */
    private const MIN_WATCH_VERSION = '1.1.0';

    /**
     * Maximum accepted polling interval for --watch-poll, in milliseconds.
     */
    private const MAX_WATCH_POLL_MS = 60000;

    /**
     * Maximum time allowed for `fast-di-compile --version`.
     */
    private const VERSION_CHECK_TIMEOUT_SECONDS = 3;

    /**
     * Options that only make sense for a single compile.
     */
    private const ONE_OFF_OPTIONS = ['dry-run', 'validate', 'compare-archive', 'compare-fail-on-diff'];

    /**
     * Command that installs or updates the bundled compiler binary.
     */
    private const INSTALLER_COMMAND = 'php vendor/spmt/magento2-spmt-fast-di-compile/bin/install-fast-di-compile.php';

    /**
     * Rust binary path options that must resolve inside Magento's project root.
     */
    private const ROOT_PATH_OPTIONS = [
        'php-generated' => '--php-generated',
        'output' => '--output',
        'archive-root' => '--archive-root',
        'compare-report-dir' => '--compare-report-dir',
    ];

    /**
     * Rust binary boolean flags, mapped to their Symfony option name.
     */
    private const FLAG_OPTIONS = [
        'validate' => '--validate',
        'incremental' => '--incremental',
        'dry-run' => '--dry-run',
        'compare-archive' => '--compare-archive',
        'compare-fail-on-diff' => '--compare-fail-on-diff',
        'ignore-constructor-integrity' => '--ignore-constructor-integrity',
    ];

    /**
     * Environment variable name markers that should not be inherited by the compiler process.
     */
    private const SENSITIVE_ENVIRONMENT_MARKERS = [
        'AUTH',
        'TOKEN',
        'SECRET',
        'PASSWORD',
        'PASS',
        'KEY',
        'CREDENTIAL',
        'DATABASE_URL',
        'DB_',
        'MYSQL_',
        'PGPASSWORD',
        'AWS_',
        'AZURE_',
        'GOOGLE_',
        'GCP_',
    ];

    /**
     * Control characters allowed through compiler output are tab and newline only.
     */
    private const UNSAFE_OUTPUT_CONTROL_CHARACTER_PATTERN = '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/';

    /**
     * Absolute path to the fast-di-compile binary.
     *
     * @var string
     */
    private string $binary;

    /**
     * Magento's standard DI compile command.
     *
     * @var Command
     */
    private Command $standardCommand;

    /**
     * Maximum time allowed for the Rust compiler process.
     *
     * @var float
     */
    private float $processTimeout;

    /**
     * Initialize the command wrapper.
     *
     * @param string $binary
     * @param Command $standardCommand
     * @param float $processTimeout
     */
    public function __construct(
        string $binary,
        Command $standardCommand,
        float $processTimeout = self::PROCESS_TIMEOUT_SECONDS
    ) {
        $this->binary = $binary;
        $this->standardCommand = $standardCommand;
        $this->processTimeout = $processTimeout;
        parent::__construct(DiCompileCommand::NAME);
    }

    /**
     * Configure command options.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->setName(DiCompileCommand::NAME)
            ->setDescription(
                'Generates DI configuration and all non-existing interceptors and factories (fast-di-compile)'
            )
            ->addOption(
                'standard',
                null,
                InputOption::VALUE_NONE,
                'Run the standard Magento PHP DI compiler instead of the fast Rust compiler'
            )
            ->addOption(
                'jobs',
                'j',
                InputOption::VALUE_REQUIRED,
                'Number of parallel jobs (default: number of CPUs)'
            )
            ->addOption(
                'timeout',
                null,
                InputOption::VALUE_REQUIRED,
                'Maximum compiler runtime in seconds (default: 15)'
            )
            ->addOption(
                'fallback-php',
                null,
                InputOption::VALUE_REQUIRED,
                'Path to PHP binary for Tier 3 fallback'
            )
            ->addOption(
                'validate',
                null,
                InputOption::VALUE_NONE,
                'Validate output against PHP ground truth'
            )
            ->addOption(
                'php-generated',
                null,
                InputOption::VALUE_REQUIRED,
                'PHP ground-truth generated dir (used with --validate)'
            )
            ->addOption(
                'output',
                null,
                InputOption::VALUE_REQUIRED,
                'Output directory (default: <magento-root>/generated)'
            )
            ->addOption(
                'incremental',
                null,
                InputOption::VALUE_NONE,
                'Enable incremental compilation (skip unchanged files)'
            )
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Dry run, do not write output files')
            ->addOption(
                'compare-archive',
                null,
                InputOption::VALUE_NONE,
                'Compare output against archive baseline (_code/_metadata) after generation'
            )
            ->addOption(
                'archive-root',
                null,
                InputOption::VALUE_REQUIRED,
                'Archive root containing _code and _metadata (default: <magento-root>/generated)'
            )
            ->addOption(
                'compare-report-dir',
                null,
                InputOption::VALUE_REQUIRED,
                'Where to write archive diff reports (default: <output>/diff)'
            )
            ->addOption(
                'compare-fail-on-diff',
                null,
                InputOption::VALUE_NONE,
                'Exit with code 1 when archive comparison has differences'
            )
            ->addOption(
                'ignore-constructor-integrity',
                null,
                InputOption::VALUE_NONE,
                'Continue when Magento-style constructor integrity validation fails'
            )
            ->addOption(
                'watch',
                null,
                InputOption::VALUE_NONE,
                'Compile, then compile again after every relevant code change until Ctrl+C (development)'
            )
            ->addOption(
                'watch-poll',
                null,
                InputOption::VALUE_OPTIONAL,
                'Like --watch, polling every MS milliseconds (default 1000) instead of file events'
            );
    }

    /**
     * Execute the DI compile command.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('standard') && $this->isWatchRequested($input)) {
            $this->writeError($output, '--standard cannot be combined with --watch or --watch-poll.');
            return Cli::RETURN_FAILURE;
        }

        if ($input->getOption('standard')) {
            $output->writeln('<info>Running standard setup:di:compile...</info>');
            return $this->standardCommand->run(new ArrayInput([]), $output);
        }

        if ($this->isWatchRequested($input)) {
            return $this->executeWatch($input, $output);
        }

        try {
            $magentoRoot = $this->getMagentoRoot();
            $processTimeout = $this->getProcessTimeout($input);
            $command = $this->buildCompilerCommand($input, $output, $magentoRoot);
        } catch (InvalidArgumentException | RuntimeException $exception) {
            $this->writeError($output, $exception->getMessage());
            return Cli::RETURN_FAILURE;
        }

        $output->writeln('<info>Running fast-di-compile...</info>');

        $process = new Process(
            $command,
            $magentoRoot,
            $this->getRestrictedEnvironment($magentoRoot)
        );
        $process->setTimeout($processTimeout);

        try {
            $exitCode = $process->run(
                function (string $type, string $buffer) use ($output): void {
                    $output->write(
                        $this->sanitizeProcessOutput($buffer),
                        false,
                        OutputInterface::OUTPUT_RAW
                    );
                }
            );
        } catch (ProcessTimedOutException $exception) {
            $process->stop(0.0);
            $this->writeError(
                $output,
                'fast-di-compile timed out after ' . $processTimeout . ' seconds.'
            );
            return Cli::RETURN_FAILURE;
        } catch (RuntimeException $exception) {
            $this->writeError($output, 'Unable to run fast-di-compile: ' . $exception->getMessage());
            return Cli::RETURN_FAILURE;
        }

        if ($exitCode !== 0) {
            $output->writeln('<error>fast-di-compile exited with code ' . $exitCode . '</error>');
            return Cli::RETURN_FAILURE;
        }

        $output->writeln('<info>DI compilation complete.</info>');
        return Cli::RETURN_SUCCESS;
    }

    /**
     * Whether --watch or --watch-poll was given.
     *
     * @param InputInterface $input
     * @return bool
     */
    private function isWatchRequested(InputInterface $input): bool
    {
        return $input->getOption('watch') || $input->hasParameterOption('--watch-poll', true);
    }

    /**
     * Run the compiler in watch mode until it exits.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    private function executeWatch(InputInterface $input, OutputInterface $output): int
    {
        try {
            $magentoRoot = $this->getMagentoRoot();
            $this->assertWatchAllowed($input, $magentoRoot);
            $command = $this->buildCompilerCommand($input, $output, $magentoRoot);
            $command[] = $this->getWatchFlag($input);
            // Without pcntl, PHP cannot forward Ctrl+C; the binary then stops when PHP ends.
            $command[] = '--watch-exit-with-parent';
            $this->assertBinarySupportsWatch($magentoRoot);
        } catch (InvalidArgumentException | RuntimeException $exception) {
            $this->writeError($output, $exception->getMessage());
            return Cli::RETURN_FAILURE;
        }

        $output->writeln('<info>Running fast-di-compile in watch mode, Ctrl+C to stop...</info>');

        $process = new Process($command, $magentoRoot, $this->getRestrictedEnvironment($magentoRoot));
        $process->setTimeout(null);
        $process->setIdleTimeout(null);

        try {
            $process->start(
                function (string $type, string $buffer) use ($output): void {
                    $output->write($this->sanitizeProcessOutput($buffer), false, OutputInterface::OUTPUT_RAW);
                }
            );
            $previousHandlers = $this->forwardStopSignals($process);
            $exitCode = $process->wait();
            $this->restoreSignalHandlers($previousHandlers);
        } catch (RuntimeException $exception) {
            $this->writeError($output, 'Unable to run fast-di-compile: ' . $exception->getMessage());
            return Cli::RETURN_FAILURE;
        }

        return $exitCode === 0 ? Cli::RETURN_SUCCESS : (int) $exitCode;
    }

    /**
     * Reject watch mode for production stores and for options that only fit a single compile.
     *
     * @param InputInterface $input
     * @param string $magentoRoot
     * @return void
     */
    private function assertWatchAllowed(InputInterface $input, string $magentoRoot): void
    {
        $mode = $this->getDeployMode($magentoRoot);
        if ($mode === 'production') {
            throw new InvalidArgumentException('--watch is for development; current mode is production.');
        }

        if ($input->getOption('timeout') !== null) {
            throw new InvalidArgumentException('--timeout cannot be combined with --watch.');
        }

        foreach (self::ONE_OFF_OPTIONS as $option) {
            if ($input->getOption($option)) {
                throw new InvalidArgumentException('--' . $option . ' cannot be combined with --watch.');
            }
        }
    }

    /**
     * Resolve the deploy mode as Magento does.
     *
     * The MAGE_MODE environment variable wins over MAGE_MODE in app/etc/env.php; without either
     * the mode is "default".
     *
     * @param string $magentoRoot
     * @return string
     */
    private function getDeployMode(string $magentoRoot): string
    {
        $mode = $this->readEnvironmentVariable('MAGE_MODE');
        if ($mode !== null && $mode !== '') {
            return $mode;
        }

        $envFile = $magentoRoot . '/app/etc/env.php';
        if ((new SplFileInfo($envFile))->isFile()) {
            // phpcs:ignore Magento2.Security.IncludeFile.FoundIncludeFile -- Magento's own deployment config.
            $config = include $envFile;
            if (is_array($config) && isset($config['MAGE_MODE']) && is_string($config['MAGE_MODE'])) {
                return $config['MAGE_MODE'];
            }
        }

        return 'default';
    }

    /**
     * The watch flag for the binary: --watch, or --watch-poll=MS.
     *
     * @param InputInterface $input
     * @return string
     */
    private function getWatchFlag(InputInterface $input): string
    {
        if (!$input->hasParameterOption('--watch-poll', true)) {
            return '--watch';
        }

        $interval = $input->getOption('watch-poll');
        if ($interval === null) {
            return '--watch-poll';
        }

        $interval = (string) $interval;
        if (preg_match('/^[1-9][0-9]*$/', $interval) !== 1 || (int) $interval > self::MAX_WATCH_POLL_MS) {
            throw new InvalidArgumentException(
                '--watch-poll must be a whole number of milliseconds from 1 to ' . self::MAX_WATCH_POLL_MS . '.'
            );
        }

        return '--watch-poll=' . (int) $interval;
    }

    /**
     * Require a binary release that knows --watch.
     *
     * @param string $magentoRoot
     * @return void
     */
    private function assertBinarySupportsWatch(string $magentoRoot): void
    {
        $process = new Process(
            [$this->binary, '--version'],
            $magentoRoot,
            $this->getRestrictedEnvironment($magentoRoot)
        );
        $process->setTimeout(self::VERSION_CHECK_TIMEOUT_SECONDS);

        try {
            $process->run();
            $reported = trim($process->getOutput());
        } catch (RuntimeException $exception) {
            $reported = '';
        }

        $version = $this->parseBinaryVersion($reported);
        if ($version !== null && version_compare($version, self::MIN_WATCH_VERSION, '>=')) {
            return;
        }

        throw new RuntimeException(
            '--watch needs fast-di-compile ' . self::MIN_WATCH_VERSION . ' or newer; found '
            . ($version ?? 'an unknown version') . '. Update it with: ' . self::INSTALLER_COMMAND
        );
    }

    /**
     * The release version in `fast-di-compile --version` output. A local build reports
     * `v1.1.0-3-gabc1234` or `v1.1.0-3-gabc1234-modified`, which counts as 1.1.0.
     *
     * @param string $reported
     * @return string|null
     */
    private function parseBinaryVersion(string $reported): ?string
    {
        $pattern = '/^fast-di-compile v([0-9]+\.[0-9]+\.[0-9]+)(?:-[0-9]+-g[0-9a-f]+)?(?:-modified)?$/';
        if (preg_match($pattern, $reported, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    /**
     * Forward SIGINT and SIGTERM to the binary, so it stops its running compile and cleans up.
     *
     * @param Process $process
     * @return array<int, mixed>
     */
    private function forwardStopSignals(Process $process): array
    {
        if (!function_exists('pcntl_signal') || !function_exists('pcntl_async_signals')) {
            return [];
        }

        // phpcs:ignore Magento2.Functions.DiscouragedFunction.Discouraged -- Ctrl+C must reach the binary.
        pcntl_async_signals(true);
        $previous = [];
        foreach ([SIGINT, SIGTERM] as $signal) {
            // phpcs:ignore Magento2.Functions.DiscouragedFunction.Discouraged -- Restored after watch mode.
            $previous[$signal] = pcntl_signal_get_handler($signal);
            // phpcs:ignore Magento2.Functions.DiscouragedFunction.Discouraged -- Ctrl+C must reach the binary.
            pcntl_signal(
                $signal,
                static function (int $received) use ($process): void {
                    if ($process->isRunning()) {
                        $process->signal($received);
                    }
                }
            );
        }

        return $previous;
    }

    /**
     * Put back the signal handlers that were active before watch mode.
     *
     * @param array $previous
     * @return void
     */
    private function restoreSignalHandlers(array $previous): void
    {
        foreach ($previous as $signal => $handler) {
            // phpcs:ignore Magento2.Functions.DiscouragedFunction.Discouraged -- Restores the earlier handler.
            pcntl_signal($signal, $handler);
        }
    }

    /**
     * Build a shell-safe argv list for the Rust compiler.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @param string $magentoRoot
     * @return string[]
     */
    private function buildCompilerCommand(InputInterface $input, OutputInterface $output, string $magentoRoot): array
    {
        $command = [
            $this->binary,
            '--magento-root',
            $magentoRoot,
            '--fallback-php',
            $this->getFallbackPhp($input),
        ];

        $this->appendJobsOption($command, $input);
        $this->appendRootPathOptions($command, $input, $magentoRoot);
        $this->appendFlagOptions($command, $input);
        $this->appendVerboseOption($command, $output);

        return $command;
    }

    /**
     * Append verbose mode when Magento console output is verbose.
     *
     * @param string[] $command
     * @param OutputInterface $output
     * @return void
     */
    private function appendVerboseOption(array &$command, OutputInterface $output): void
    {
        if ($output->isVerbose()) {
            $command[] = '--verbose';
        }
    }

    /**
     * Resolve the fallback PHP binary to the same executable running Magento CLI.
     *
     * @param InputInterface $input
     * @return string
     */
    private function getFallbackPhp(InputInterface $input): string
    {
        $currentPhp = $this->getCurrentPhpBinary();
        $fallbackPhp = $input->getOption('fallback-php');
        if ($fallbackPhp === null) {
            return $currentPhp;
        }

        $requestedPhp = $this->resolveExecutablePath((string) $fallbackPhp, '--fallback-php');
        if ($requestedPhp !== $currentPhp) {
            throw new InvalidArgumentException(
                '--fallback-php must resolve to the PHP binary running Magento CLI: ' . $currentPhp
            );
        }

        return $requestedPhp;
    }

    /**
     * Append a bounded jobs value if provided.
     *
     * @param string[] $command
     * @param InputInterface $input
     * @return void
     */
    private function appendJobsOption(array &$command, InputInterface $input): void
    {
        $jobs = $input->getOption('jobs');
        if ($jobs === null) {
            return;
        }

        $jobs = (string) $jobs;
        if (preg_match('/^[1-9][0-9]*$/', $jobs) !== 1) {
            throw new InvalidArgumentException('--jobs must be a positive integer.');
        }

        $jobsCount = (int) $jobs;
        if ($jobsCount > self::MAX_JOBS) {
            throw new InvalidArgumentException('--jobs must be less than or equal to ' . self::MAX_JOBS . '.');
        }

        $command[] = '--jobs';
        $command[] = (string) $jobsCount;
    }

    /**
     * Resolve the configured process timeout.
     *
     * @param InputInterface $input
     * @return float
     */
    private function getProcessTimeout(InputInterface $input): float
    {
        $timeout = $input->getOption('timeout');
        if ($timeout === null) {
            return $this->processTimeout;
        }

        $timeout = (string) $timeout;
        if (preg_match('/^[1-9][0-9]*$/', $timeout) !== 1) {
            throw new InvalidArgumentException('--timeout must be a positive integer.');
        }

        $timeoutSeconds = (int) $timeout;
        if ($timeoutSeconds > self::MAX_PROCESS_TIMEOUT_SECONDS) {
            throw new InvalidArgumentException(
                '--timeout must be less than or equal to ' . self::MAX_PROCESS_TIMEOUT_SECONDS . '.'
            );
        }

        return (float) $timeoutSeconds;
    }

    /**
     * Append path options after constraining them to Magento's project root.
     *
     * @param string[] $command
     * @param InputInterface $input
     * @param string $magentoRoot
     * @return void
     */
    private function appendRootPathOptions(array &$command, InputInterface $input, string $magentoRoot): void
    {
        foreach (self::ROOT_PATH_OPTIONS as $option => $flag) {
            $value = $input->getOption($option);
            if ($value === null) {
                continue;
            }

            $command[] = $flag;
            $command[] = $this->resolveMagentoPath((string) $value, '--' . $option, $magentoRoot);
        }
    }

    /**
     * Append boolean compiler flags.
     *
     * @param string[] $command
     * @param InputInterface $input
     * @return void
     */
    private function appendFlagOptions(array &$command, InputInterface $input): void
    {
        foreach (self::FLAG_OPTIONS as $option => $flag) {
            if ($input->getOption($option)) {
                $command[] = $flag;
            }
        }
    }

    /**
     * Resolve Magento root to a canonical absolute path.
     *
     * @return string
     */
    private function getMagentoRoot(): string
    {
        $root = (new SplFileInfo(BP))->getRealPath();
        if (!is_string($root)) {
            throw new RuntimeException('Magento root could not be resolved.');
        }

        return rtrim($root, DIRECTORY_SEPARATOR);
    }

    /**
     * Resolve the current PHP executable.
     *
     * @return string
     */
    private function getCurrentPhpBinary(): string
    {
        return $this->resolveExecutablePath(PHP_BINARY, 'current PHP binary');
    }

    /**
     * Resolve and validate an executable path.
     *
     * @param string $path
     * @param string $label
     * @return string
     */
    private function resolveExecutablePath(string $path, string $label): string
    {
        if ($path === '' || str_contains($path, "\0")) {
            throw new InvalidArgumentException($label . ' must be a non-empty executable path.');
        }

        $fileInfo = new SplFileInfo($path);
        if (!$fileInfo->isFile() || !$fileInfo->isExecutable()) {
            throw new InvalidArgumentException($label . ' must point to an executable file.');
        }

        $realPath = $fileInfo->getRealPath();
        if (!is_string($realPath)) {
            throw new InvalidArgumentException($label . ' could not be resolved.');
        }

        return $realPath;
    }

    /**
     * Resolve a path option under Magento's project root without requiring the target to exist.
     *
     * @param string $path
     * @param string $label
     * @param string $magentoRoot
     * @return string
     */
    private function resolveMagentoPath(string $path, string $label, string $magentoRoot): string
    {
        if ($path === '' || str_contains($path, "\0")) {
            throw new InvalidArgumentException($label . ' must be a non-empty path.');
        }

        $absolutePath = $this->isAbsolutePath($path) ? $path : $magentoRoot . DIRECTORY_SEPARATOR . $path;
        $resolvedPath = $this->normalizeAbsolutePath($absolutePath);
        if (!$this->isPathInsideRoot($resolvedPath, $magentoRoot)) {
            throw new InvalidArgumentException($label . ' must resolve inside Magento root.');
        }

        $this->assertExistingAncestorTrusted($resolvedPath, $magentoRoot, $label);

        return $resolvedPath;
    }

    /**
     * Check whether a path is absolute for the current platform.
     *
     * @param string $path
     * @return bool
     */
    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR);
    }

    /**
     * Normalize an absolute path lexically so missing target directories can still be validated.
     *
     * @param string $path
     * @return string
     */
    private function normalizeAbsolutePath(string $path): string
    {
        if (!$this->isAbsolutePath($path)) {
            throw new InvalidArgumentException('Path must be absolute.');
        }

        $pathParts = [];
        foreach (explode(DIRECTORY_SEPARATOR, $path) as $pathPart) {
            if ($pathPart === '' || $pathPart === '.') {
                continue;
            }

            if ($pathPart === '..') {
                array_pop($pathParts);
                continue;
            }

            $pathParts[] = $pathPart;
        }

        return DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $pathParts);
    }

    /**
     * Check whether a path is inside Magento's project root.
     *
     * @param string $path
     * @param string $magentoRoot
     * @return bool
     */
    private function isPathInsideRoot(string $path, string $magentoRoot): bool
    {
        $magentoRoot = rtrim($magentoRoot, DIRECTORY_SEPARATOR);

        return $path === $magentoRoot || str_starts_with($path, $magentoRoot . DIRECTORY_SEPARATOR);
    }

    /**
     * Ensure the existing ancestor of a path does not escape through symlinks.
     *
     * @param string $path
     * @param string $magentoRoot
     * @param string $label
     * @return void
     */
    private function assertExistingAncestorTrusted(string $path, string $magentoRoot, string $label): void
    {
        $ancestor = $path;
        while (!$this->pathExists($ancestor)) {
            $parent = $this->getParentPath($ancestor);
            if ($parent === $ancestor) {
                throw new InvalidArgumentException($label . ' could not be resolved.');
            }

            $ancestor = $parent;
        }

        $realAncestor = (new SplFileInfo($ancestor))->getRealPath();
        if (!is_string($realAncestor) || !$this->isPathInsideRoot($realAncestor, $magentoRoot)) {
            throw new InvalidArgumentException($label . ' must not resolve outside Magento root.');
        }

        if ($this->pathContainsSymlink($ancestor, $magentoRoot)) {
            throw new InvalidArgumentException($label . ' must not include symlinked path components.');
        }
    }

    /**
     * Return the parent path without using filesystem helpers that Magento discourages.
     *
     * @param string $path
     * @return string
     */
    private function getParentPath(string $path): string
    {
        $trimmedPath = rtrim($path, DIRECTORY_SEPARATOR);
        if ($trimmedPath === '') {
            return DIRECTORY_SEPARATOR;
        }

        $separatorPosition = strrpos($trimmedPath, DIRECTORY_SEPARATOR);
        if ($separatorPosition === false || $separatorPosition === 0) {
            return DIRECTORY_SEPARATOR;
        }

        return substr($trimmedPath, 0, $separatorPosition);
    }

    /**
     * Check whether a path currently exists.
     *
     * @param string $path
     * @return bool
     */
    private function pathExists(string $path): bool
    {
        return (new SplFileInfo($path))->getRealPath() !== false;
    }

    /**
     * Check whether a path from Magento root contains a symlinked component.
     *
     * @param string $path
     * @param string $magentoRoot
     * @return bool
     */
    private function pathContainsSymlink(string $path, string $magentoRoot): bool
    {
        $magentoRoot = rtrim($magentoRoot, DIRECTORY_SEPARATOR);
        $relativePath = ltrim(substr($path, strlen($magentoRoot)), DIRECTORY_SEPARATOR);
        $currentPath = $magentoRoot;

        foreach (explode(DIRECTORY_SEPARATOR, $relativePath) as $pathPart) {
            if ($pathPart === '') {
                continue;
            }

            $currentPath .= DIRECTORY_SEPARATOR . $pathPart;
            if ((new SplFileInfo($currentPath))->isLink()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Remove sensitive environment variables from the compiler process.
     *
     * @param string $magentoRoot
     * @return array<string, string|false>
     */
    private function getRestrictedEnvironment(string $magentoRoot): array
    {
        $environment = [
            'PWD' => $magentoRoot,
        ];

        foreach (['PATH', 'HOME', 'TMPDIR', 'TEMP', 'TMP'] as $key) {
            $value = $this->readEnvironmentVariable($key);
            if ($value !== null) {
                $environment[$key] = $value;
            }
        }

        foreach ($this->getCurrentEnvironmentKeys() as $key) {
            if ($this->isSensitiveEnvironmentKey($key)) {
                $environment[$key] = false;
            }
        }

        return $environment;
    }

    /**
     * Read one environment variable as a string.
     *
     * @param string $key
     * @return string|null
     */
    private function readEnvironmentVariable(string $key): ?string
    {
        // phpcs:ignore Magento2.Functions.DiscouragedFunction.Discouraged -- Needed to preserve safe env values.
        $value = getenv($key);

        return is_string($value) ? $value : null;
    }

    /**
     * Return the environment keys visible to this PHP process.
     *
     * @return string[]
     */
    private function getCurrentEnvironmentKeys(): array
    {
        // phpcs:ignore Magento2.Functions.DiscouragedFunction.Discouraged -- Needed to suppress inherited secrets.
        $currentEnvironment = getenv();
        if (!is_array($currentEnvironment)) {
            return [];
        }

        return array_keys($currentEnvironment);
    }

    /**
     * Decide whether an environment key is likely to contain a secret.
     *
     * @param string $key
     * @return bool
     */
    private function isSensitiveEnvironmentKey(string $key): bool
    {
        $upperKey = strtoupper($key);
        foreach (self::SENSITIVE_ENVIRONMENT_MARKERS as $marker) {
            if (str_contains($upperKey, $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Write a sanitized error message through Symfony's formatter.
     *
     * @param OutputInterface $output
     * @param string $message
     * @return void
     */
    private function writeError(OutputInterface $output, string $message): void
    {
        $output->writeln('<error>' . OutputFormatter::escape($this->sanitizeProcessOutput($message)) . '</error>');
    }

    /**
     * Strip terminal-control and log-forging control characters from compiler output.
     *
     * @param string $buffer
     * @return string
     */
    private function sanitizeProcessOutput(string $buffer): string
    {
        $sanitizedBuffer = preg_replace(self::UNSAFE_OUTPUT_CONTROL_CHARACTER_PATTERN, '', $buffer);

        return is_string($sanitizedBuffer) ? $sanitizedBuffer : '';
    }
}
