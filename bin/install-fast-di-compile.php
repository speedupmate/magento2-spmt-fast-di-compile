#!/usr/bin/env php
<?php

declare(strict_types=1);

const GITHUB_OWNER = 'speedupmate';
const GITHUB_REPO = 'di-compiler';
const GITHUB_API_HOST = 'api.github.com';
const RELEASE_DOWNLOAD_HOSTS = [
    'github.com',
    'release-assets.githubusercontent.com',
    'objects.githubusercontent.com',
    'github-releases.githubusercontent.com',
];
const BINARY_NAME = 'fast-di-compile';
const CHECKSUM_ASSET = 'sha256sums.txt';
const STAMP_NAME = 'fast-di-compile.release-stamp';
const MAX_ARCHIVE_BYTES = 67108864;
const MAX_BINARY_BYTES = 67108864;
const MAX_CHECKSUM_BYTES = 1048576;
const MAX_RELEASE_JSON_BYTES = 2097152;
const MAX_PROCESS_OUTPUT_BYTES = 1048576;
const DOWNLOAD_CHUNK_BYTES = 1048576;
const PROCESS_TIMEOUT_SECONDS = 30;
const PROCESS_EXIT_DRAIN_SECONDS = 2;
const TEMP_FILE_ATTEMPTS = 20;
const MAX_RELEASE_ASSETS = 64;
const ALLOWED_ARCHIVE_ENTRIES = [
    '.' => 'directory',
    './' => 'directory',
    BINARY_NAME => 'file',
    './' . BINARY_NAME => 'file',
    'LICENSE' => 'file',
    './LICENSE' => 'file',
    'README.md' => 'file',
    './README.md' => 'file',
];
const BINARY_ARCHIVE_ENTRIES = [
    './' . BINARY_NAME,
    BINARY_NAME,
];

if (shouldRunInstaller($argv ?? [])) {
    exit(main($argv));
}

/**
 * @param string[] $argv
 */
function shouldRunInstaller(array $argv): bool
{
    $script = $_SERVER['SCRIPT_FILENAME'] ?? '';
    if (is_string($script) && realpath($script) === __FILE__) {
        return true;
    }

    $argv0 = $argv[0] ?? '';

    return is_string($argv0) && basename($argv0) === 'install-fast-di-compile.php';
}

/**
 * @param string[] $argv
 */
function main(array $argv): int
{
    try {
        $options = parseOptions($argv);
        if ($options['help']) {
            printHelp();
            return 0;
        }

        $platform = validatePlatform($options['platform'] ?? detectPlatform());
        $installDir = prepareInstallDirectory($options['install-dir'] ?? __DIR__);
        $targetBinary = $installDir . DIRECTORY_SEPARATOR . BINARY_NAME;
        $message = installRelease(
            $options['force'],
            getRequestedVersion($options),
            $platform,
            $installDir,
            $targetBinary
        );
        fwrite(STDOUT, $message . PHP_EOL);
        fwrite(STDOUT, $targetBinary . PHP_EOL);
        return 0;
    } catch (Throwable $exception) {
        fwrite(STDERR, 'install-fast-di-compile.php: ' . $exception->getMessage() . PHP_EOL);
        return 1;
    }
}

/**
 * @param string[] $argv
 * @return array{help: bool, force: bool, version?: string, platform?: string, install-dir?: string}
 */
function parseOptions(array $argv): array
{
    $options = [
        'help' => false,
        'force' => false,
    ];

    for ($i = 1, $count = count($argv); $i < $count; $i++) {
        $arg = $argv[$i];
        if ($arg === '--help' || $arg === '-h') {
            $options['help'] = true;
            continue;
        }
        if ($arg === '--force') {
            $options['force'] = true;
            continue;
        }

        foreach (['version', 'platform', 'install-dir'] as $name) {
            $prefix = '--' . $name . '=';
            if (str_starts_with($arg, $prefix)) {
                $options[$name] = substr($arg, strlen($prefix));
                continue 2;
            }
            if ($arg === '--' . $name) {
                if (!isset($argv[$i + 1]) || str_starts_with($argv[$i + 1], '--')) {
                    throw new InvalidArgumentException('Missing value for --' . $name);
                }
                $options[$name] = $argv[++$i];
                continue 2;
            }
        }

        throw new InvalidArgumentException('Unknown option: ' . $arg);
    }

    return $options;
}

function printHelp(): void
{
    fwrite(STDOUT, <<<'HELP'
Usage:
  install-fast-di-compile.php [options]

Downloads the latest GitHub release of speedupmate/di-compiler.
SHA-256 digests come from the GitHub API for that release.

Options:
  --version VERSION      Install this tag instead of the latest release.
                         Defaults to SPMT_FAST_DI_COMPILE_VERSION when that is set.
  --platform PLATFORM    Override detected platform: linux-x64, linux-arm64, macos-x64, macos-arm64.
  --install-dir DIR      Install directory. Defaults to this script's directory.
  --force                Replace a symlink, or a binary that is not the selected release.
                         A newer release replaces a previously stamped install without --force.
  -h, --help             Show this help.

Environment:
  GITHUB_TOKEN           A GitHub token for the API request (GH_TOKEN works too). GitHub limits
                         requests without one per IP address, which shared CI runners can reach.
                         It is sent to api.github.com only.

HELP);
}

/**
 * @param array{help: bool, force: bool, version?: string, platform?: string, install-dir?: string} $options
 */
function getRequestedVersion(array $options): ?string
{
    if (array_key_exists('version', $options)) {
        return validateVersion($options['version']);
    }

    $version = getenv('SPMT_FAST_DI_COMPILE_VERSION');
    if (is_string($version) && $version !== '') {
        return validateVersion($version);
    }

    return null;
}

function validateVersion(string $version): string
{
    if (!preg_match('/^v[0-9][0-9A-Za-z._-]*$/', $version)) {
        throw new InvalidArgumentException('Invalid release version: ' . $version);
    }

    return $version;
}

function validatePlatform(string $platform): string
{
    if (!preg_match('/^(linux|macos)-(x64|arm64)$/', $platform)) {
        throw new InvalidArgumentException('Unsupported platform: ' . $platform);
    }

    return $platform;
}

function detectPlatform(): string
{
    $os = match (PHP_OS_FAMILY) {
        'Linux' => 'linux',
        'Darwin' => 'macos',
        default => throw new RuntimeException('Unsupported operating system: ' . PHP_OS_FAMILY),
    };

    $machine = strtolower(php_uname('m'));
    $arch = match (true) {
        in_array($machine, ['x86_64', 'amd64'], true) => 'x64',
        in_array($machine, ['aarch64', 'arm64'], true) => 'arm64',
        default => throw new RuntimeException('Unsupported CPU architecture: ' . $machine),
    };

    return $os . '-' . $arch;
}

function prepareInstallDirectory(string $installDir): string
{
    $created = false;
    if (!is_dir($installDir)) {
        if (!mkdir($installDir, 0755, true) && !is_dir($installDir)) {
            throw new RuntimeException('Unable to create install directory: ' . $installDir);
        }
        $created = true;
    }

    if ($created) {
        chmod($installDir, 0755);
    }

    $realPath = realpath($installDir);
    if (!is_string($realPath)) {
        throw new RuntimeException('Unable to resolve install directory: ' . $installDir);
    }

    return rtrim($realPath, DIRECTORY_SEPARATOR);
}

function installRelease(
    bool $force,
    ?string $requestedVersion,
    string $platform,
    string $installDir,
    string $targetBinary
): string {
    if (!function_exists('proc_open')) {
        throw new RuntimeException('PHP proc_open must be enabled to extract release archives.');
    }

    getTarBinary();
    $stampPath = $installDir . DIRECTORY_SEPARATOR . STAMP_NAME;
    $tmpRoot = createTemporaryDirectory('spmt-fast-di-compile-');

    try {
        $release = fetchRelease($tmpRoot, $requestedVersion);
        $version = $release['version'];
        $assetName = BINARY_NAME . '-' . $version . '-' . $platform . '.tar.gz';
        $archiveDigest = requireDigest($release['digests'], $assetName);
        $checksumDigest = requireDigest($release['digests'], CHECKSUM_ASSET);

        if (is_dir($targetBinary) && !is_link($targetBinary)) {
            throw new RuntimeException('Install path is a directory: ' . $targetBinary);
        }
        if (!$force && is_link($targetBinary)) {
            throw new RuntimeException('Refusing to replace a symlink at ' . $targetBinary . '. Use --force.');
        }

        $checksumPath = $tmpRoot . DIRECTORY_SEPARATOR . CHECKSUM_ASSET;
        downloadFile(
            githubDownloadUrl($version, CHECKSUM_ASSET),
            $checksumPath,
            MAX_CHECKSUM_BYTES,
            RELEASE_DOWNLOAD_HOSTS
        );
        assertFileSha256($checksumPath, $checksumDigest, CHECKSUM_ASSET);
        $checksumBody = file_get_contents($checksumPath);
        if (!is_string($checksumBody)) {
            throw new RuntimeException('Unable to read checksum file.');
        }
        $checksums = parseChecksumFile($checksumBody);
        if (!isset($checksums[$assetName]) || !hash_equals($archiveDigest, $checksums[$assetName])) {
            throw new RuntimeException('Checksum file does not agree with the GitHub digest for ' . $assetName);
        }

        $expectedBinaryHash = $checksums[BINARY_NAME] ?? null;
        if (!$force && is_file($targetBinary) && !is_link($targetBinary)) {
            if ($expectedBinaryHash !== null
                && is_executable($targetBinary)
                && hash_equals($expectedBinaryHash, sha256RegularFile($targetBinary, MAX_BINARY_BYTES, $targetBinary))
            ) {
                writeStamp($stampPath, $version, $platform, $archiveDigest, $expectedBinaryHash);
                return matchMessage($version, $platform);
            }
            if ($expectedBinaryHash === null && installationStampMatches($targetBinary, $stampPath, $platform, $archiveDigest)) {
                return matchMessage($version, $platform);
            }
        }

        $archivePath = $tmpRoot . DIRECTORY_SEPARATOR . $assetName;
        downloadFile(
            githubDownloadUrl($version, $assetName),
            $archivePath,
            MAX_ARCHIVE_BYTES,
            RELEASE_DOWNLOAD_HOSTS
        );
        assertFileSha256($archivePath, $archiveDigest, $assetName);

        return publishBinaryFromArchive(
            $archivePath,
            validateArchive($archivePath),
            $targetBinary,
            $stampPath,
            $version,
            $platform,
            $archiveDigest,
            $expectedBinaryHash,
            $force
        );
    } finally {
        removePath($tmpRoot);
    }
}

function matchMessage(string $version, string $platform): string
{
    return BINARY_NAME . ' ' . $version . ' for ' . $platform . ' matches the GitHub release';
}

function githubApiUrl(?string $version): string
{
    $base = 'https://' . GITHUB_API_HOST . '/repos/' . rawurlencode(GITHUB_OWNER) . '/'
        . rawurlencode(GITHUB_REPO) . '/releases/';
    if ($version === null) {
        return $base . 'latest';
    }

    return $base . 'tags/' . rawurlencode($version);
}

function githubDownloadUrl(string $version, string $assetName): string
{
    return 'https://github.com/' . rawurlencode(GITHUB_OWNER) . '/' . rawurlencode(GITHUB_REPO)
        . '/releases/download/' . rawurlencode($version) . '/' . rawurlencode($assetName);
}

/**
 * The token from GITHUB_TOKEN, else GH_TOKEN, or null when neither is set.
 */
function githubToken(): ?string
{
    foreach (['GITHUB_TOKEN', 'GH_TOKEN'] as $name) {
        $value = getenv($name);
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }
    }

    return null;
}

/**
 * Headers of the GitHub API request. A token raises the rate limit that requests without one
 * share per IP address. Only this request carries it: its allowed hosts are api.github.com alone.
 *
 * @return string[]
 */
function githubApiHeaders(?string $token): array
{
    $headers = [
        'Accept: application/vnd.github+json',
        'X-GitHub-Api-Version: 2022-11-28',
    ];
    if ($token === null) {
        return $headers;
    }
    // Token formats change, so any visible ASCII passes; a space or control character could end
    // the header line and start another one.
    if (preg_match('/^[\x21-\x7E]+$/', $token) !== 1) {
        throw new RuntimeException('GITHUB_TOKEN or GH_TOKEN holds a space or a control character.');
    }
    $headers[] = 'Authorization: Bearer ' . $token;

    return $headers;
}

/**
 * @return array{version: string, digests: array<string, string>}
 */
function fetchRelease(string $tmpRoot, ?string $requestedVersion): array
{
    $responsePath = $tmpRoot . DIRECTORY_SEPARATOR . 'release.json';
    downloadFile(
        githubApiUrl($requestedVersion),
        $responsePath,
        MAX_RELEASE_JSON_BYTES,
        [GITHUB_API_HOST],
        githubApiHeaders(githubToken())
    );
    $body = file_get_contents($responsePath);
    if (!is_string($body)) {
        throw new RuntimeException('Unable to read GitHub release response.');
    }

    try {
        $decoded = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        throw new RuntimeException('GitHub release response is not valid JSON.');
    }
    if (!is_array($decoded) || isListArray($decoded)) {
        throw new RuntimeException('GitHub release response is not a release object.');
    }
    if (($decoded['draft'] ?? true) !== false) {
        throw new RuntimeException('Refusing a draft GitHub release.');
    }
    if ($requestedVersion === null && ($decoded['prerelease'] ?? true) !== false) {
        throw new RuntimeException('Refusing a prerelease as the latest release.');
    }
    if (!isset($decoded['tag_name']) || !is_string($decoded['tag_name'])) {
        throw new RuntimeException('GitHub release is missing a tag name.');
    }

    $version = validateVersion($decoded['tag_name']);
    if ($requestedVersion !== null && $version !== $requestedVersion) {
        throw new RuntimeException('GitHub release tag did not match the requested version.');
    }
    if (!isset($decoded['assets']) || !is_array($decoded['assets']) || !isListArray($decoded['assets'])) {
        throw new RuntimeException('GitHub release is missing an asset list.');
    }
    if (count($decoded['assets']) > MAX_RELEASE_ASSETS) {
        throw new RuntimeException('GitHub release contains too many assets.');
    }

    $digests = [];
    foreach ($decoded['assets'] as $asset) {
        if (!is_array($asset) || !isset($asset['name']) || !is_string($asset['name'])) {
            throw new RuntimeException('GitHub release contains an invalid asset.');
        }
        if (!preg_match('/^[A-Za-z0-9._-]+$/', $asset['name'])) {
            continue;
        }
        if (isset($digests[$asset['name']])) {
            throw new RuntimeException('GitHub release contains a duplicate asset: ' . $asset['name']);
        }
        if (!isset($asset['digest']) || !is_string($asset['digest'])) {
            continue;
        }
        if (preg_match('/^sha256:([a-fA-F0-9]{64})$/', $asset['digest'], $matches) !== 1) {
            continue;
        }
        $digests[$asset['name']] = strtolower($matches[1]);
    }

    return [
        'version' => $version,
        'digests' => $digests,
    ];
}

/**
 * @param array<string, string> $digests
 */
function requireDigest(array $digests, string $assetName): string
{
    if (!isset($digests[$assetName])) {
        throw new RuntimeException('GitHub release is missing a sha256 digest for ' . $assetName);
    }

    return $digests[$assetName];
}

/**
 * @param array<mixed> $value
 */
function isListArray(array $value): bool
{
    $index = 0;
    foreach (array_keys($value) as $key) {
        if ($key !== $index) {
            return false;
        }
        $index++;
    }

    return true;
}

/**
 * @return array<string, string>
 */
function parseChecksumFile(string $checksums): array
{
    $entries = [];
    foreach (preg_split('/\r?\n/', $checksums) ?: [] as $line) {
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (preg_match('/^([a-fA-F0-9]{64})[ \t]+\*?([A-Za-z0-9._-]+)$/', $line, $matches) !== 1) {
            throw new RuntimeException('Checksum file contains an invalid line.');
        }
        $name = $matches[2];
        if (isset($entries[$name])) {
            throw new RuntimeException('Checksum file contains a duplicate entry: ' . $name);
        }
        $entries[$name] = strtolower($matches[1]);
    }

    return $entries;
}

function assertFileSha256(string $path, string $expectedHash, string $label): void
{
    if (is_link($path) || !is_file($path)) {
        throw new RuntimeException('Unable to read ' . $label);
    }

    $actual = hash_file('sha256', $path);
    if (!is_string($actual) || !hash_equals($expectedHash, strtolower($actual))) {
        throw new RuntimeException('SHA-256 mismatch for ' . $label);
    }
}

function installationStampMatches(
    string $targetBinary,
    string $stampPath,
    string $platform,
    string $archiveDigest
): bool {
    $stamp = readStamp($stampPath);
    if ($stamp === null || $stamp['platform'] !== $platform || !hash_equals($stamp['archive_sha256'], $archiveDigest)) {
        return false;
    }
    if (!is_executable($targetBinary)) {
        return false;
    }

    return hash_equals(
        $stamp['binary_sha256'],
        sha256RegularFile($targetBinary, MAX_BINARY_BYTES, $targetBinary)
    );
}

function publishBinaryFromArchive(
    string $archivePath,
    string $binaryEntry,
    string $targetBinary,
    string $stampPath,
    string $version,
    string $platform,
    string $archiveDigest,
    ?string $expectedBinaryHash,
    bool $force
): string {
    $stagingDir = createTemporaryDirectoryInDirectory(dirname($targetBinary), BINARY_NAME . '.stage-');
    $tmpTarget = $stagingDir . DIRECTORY_SEPARATOR . BINARY_NAME;
    $target = @fopen($tmpTarget, 'xb');
    if (!is_resource($target)) {
        removePath($stagingDir);
        throw new RuntimeException('Unable to create a temporary binary in ' . $stagingDir);
    }

    try {
        $result = runProcessToStream(
            [getTarBinary(), '-xOf', $archivePath, $binaryEntry],
            $target,
            MAX_BINARY_BYTES,
            MAX_PROCESS_OUTPUT_BYTES
        );
        fclose($target);
        $target = null;
        if ($result['exitCode'] !== 0) {
            $details = trim($result['stderr']);
            throw new RuntimeException('Unable to extract release binary.' . ($details !== '' ? ' ' . $details : ''));
        }

        $binaryHash = sha256RegularFile($tmpTarget, MAX_BINARY_BYTES, 'extracted ' . BINARY_NAME);
        if ($expectedBinaryHash !== null && !hash_equals($expectedBinaryHash, $binaryHash)) {
            throw new RuntimeException('Extracted binary does not match the release checksum for ' . BINARY_NAME);
        }
        if (!chmod($tmpTarget, 0755)) {
            throw new RuntimeException('Unable to mark staged binary executable: ' . $tmpTarget);
        }
        if (!is_executable($tmpTarget)) {
            throw new RuntimeException('Staged binary is not executable: ' . $tmpTarget);
        }

        if (is_link($targetBinary)) {
            if (!$force) {
                throw new RuntimeException('Refusing to replace a symlink at ' . $targetBinary . '. Use --force.');
            }
        } elseif (is_dir($targetBinary)) {
            throw new RuntimeException('Install path is a directory: ' . $targetBinary);
        } elseif (is_file($targetBinary)) {
            if (!$force) {
                $existingHash = sha256RegularFile($targetBinary, MAX_BINARY_BYTES, $targetBinary);
                if (hash_equals($binaryHash, $existingHash)) {
                    if (!is_executable($targetBinary)) {
                        throw new RuntimeException('Existing ' . BINARY_NAME . ' is not executable. Use --force to replace it.');
                    }
                    writeStamp($stampPath, $version, $platform, $archiveDigest, $binaryHash);
                    return matchMessage($version, $platform);
                }

                $stamp = readStamp($stampPath);
                $stampedBinary = is_array($stamp)
                    && $stamp['platform'] === $platform
                    && hash_equals($stamp['binary_sha256'], $existingHash);
                $upgrade = $stampedBinary && !hash_equals($stamp['archive_sha256'], $archiveDigest);
                if (!$upgrade) {
                    throw new RuntimeException('Existing ' . BINARY_NAME . ' does not match this release. Use --force to replace it.');
                }
            }
        }

        if (!rename($tmpTarget, $targetBinary)) {
            throw new RuntimeException('Unable to move binary into place: ' . $targetBinary);
        }
        $tmpTarget = null;
        if (!is_executable($targetBinary)) {
            @unlink($targetBinary);
            throw new RuntimeException('Installed binary is not executable: ' . $targetBinary);
        }

        writeStamp($stampPath, $version, $platform, $archiveDigest, $binaryHash);
        return 'Installed ' . BINARY_NAME . ' ' . $version . ' for ' . $platform;
    } finally {
        if (is_resource($target)) {
            fclose($target);
        }
        if (is_string($tmpTarget)) {
            @unlink($tmpTarget);
        }
        removePath($stagingDir);
    }
}

/**
 * @return array{version: string, platform: string, archive_sha256: string, binary_sha256: string}|null
 */
function readStamp(string $path): ?array
{
    if (is_link($path) || !is_file($path)) {
        return null;
    }

    $size = filesize($path);
    if (!is_int($size) || $size <= 0 || $size > 4096) {
        return null;
    }

    $contents = file_get_contents($path);
    if (!is_string($contents)) {
        return null;
    }

    $fields = [];
    foreach (preg_split('/\r?\n/', $contents) ?: [] as $line) {
        if ($line === '') {
            continue;
        }
        if (preg_match('/^(version|platform|archive_sha256|binary_sha256)=([A-Za-z0-9._:-]+)$/', $line, $matches) !== 1) {
            return null;
        }
        if (isset($fields[$matches[1]])) {
            return null;
        }
        $fields[$matches[1]] = $matches[2];
    }

    foreach (['version', 'platform', 'archive_sha256', 'binary_sha256'] as $key) {
        if (!isset($fields[$key])) {
            return null;
        }
    }
    if (preg_match('/^v[0-9][0-9A-Za-z._-]*$/', $fields['version']) !== 1) {
        return null;
    }
    if (preg_match('/^(linux|macos)-(x64|arm64)$/', $fields['platform']) !== 1) {
        return null;
    }
    if (preg_match('/^[a-f0-9]{64}$/', $fields['archive_sha256']) !== 1) {
        return null;
    }
    if (preg_match('/^[a-f0-9]{64}$/', $fields['binary_sha256']) !== 1) {
        return null;
    }

    return [
        'version' => $fields['version'],
        'platform' => $fields['platform'],
        'archive_sha256' => $fields['archive_sha256'],
        'binary_sha256' => $fields['binary_sha256'],
    ];
}

function writeStamp(
    string $stampPath,
    string $version,
    string $platform,
    string $archiveDigest,
    string $binaryHash
): void {
    $contents = 'version=' . $version . "\n"
        . 'platform=' . $platform . "\n"
        . 'archive_sha256=' . $archiveDigest . "\n"
        . 'binary_sha256=' . $binaryHash . "\n";
    [$tmpPath, $handle] = openExclusiveTemporaryFile(dirname($stampPath), STAMP_NAME . '.tmp-');
    $keepTemp = true;

    try {
        if (!writeBytes($handle, $contents)) {
            throw new RuntimeException('Unable to write release stamp: ' . $stampPath);
        }
        fclose($handle);
        $handle = null;
        if (!chmod($tmpPath, 0644)) {
            throw new RuntimeException('Unable to set permissions on release stamp: ' . $stampPath);
        }
        if (!rename($tmpPath, $stampPath)) {
            throw new RuntimeException('Unable to move release stamp into place: ' . $stampPath);
        }
        $keepTemp = false;
    } finally {
        if (is_resource($handle)) {
            fclose($handle);
        }
        if ($keepTemp) {
            @unlink($tmpPath);
        }
    }
}

/**
 * @param string[] $allowedHosts
 * @param string[] $headers
 */
function downloadFile(string $url, string $targetPath, int $maxBytes, array $allowedHosts, array $headers = []): void
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP ext-curl must be enabled to download release assets.');
    }

    assertHttpsUrl($url, $allowedHosts);
    [$tmpPath, $target] = openExclusiveTemporaryFile(dirname($targetPath), basename($targetPath) . '.download-');
    $curl = curl_init($url);
    if ($curl === false) {
        fclose($target);
        @unlink($tmpPath);
        throw new RuntimeException('Unable to initialize curl for ' . $url);
    }

    $bytes = 0;
    $tooLarge = false;
    $writeFailed = false;

    try {
        $options = [
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FAILONERROR => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HEADERFUNCTION => static function ($curlHandle, string $header) use ($allowedHosts): int {
                if (preg_match('/^Location:\s*(\S+)/i', $header, $matches) === 1) {
                    assertHttpsUrl($matches[1], $allowedHosts);
                }

                return strlen($header);
            },
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_USERAGENT => 'spmt-fast-di-compile-installer',
            CURLOPT_WRITEFUNCTION => static function ($curlHandle, string $chunk) use (
                $target,
                $maxBytes,
                &$bytes,
                &$tooLarge,
                &$writeFailed
            ): int {
                $length = strlen($chunk);
                $bytes += $length;
                if ($bytes > $maxBytes) {
                    $tooLarge = true;
                    return 0;
                }
                if (!writeBytes($target, $chunk)) {
                    $writeFailed = true;
                    return 0;
                }

                return $length;
            },
        ];
        if ($headers !== []) {
            $options[CURLOPT_HTTPHEADER] = $headers;
        }

        $configured = curl_setopt_array($curl, $options);
        if (!$configured) {
            throw new RuntimeException('Unable to configure curl downloader.');
        }

        $result = curl_exec($curl);
        $error = curl_error($curl);
        $statusCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $effectiveUrl = curl_getinfo($curl, CURLINFO_EFFECTIVE_URL);

        if ($tooLarge) {
            throw new RuntimeException('Downloaded file exceeds the maximum allowed size for ' . $url);
        }
        if ($writeFailed) {
            throw new RuntimeException('Unable to write downloaded file: ' . $targetPath);
        }
        if ($result !== true) {
            throw new RuntimeException('Unable to download ' . $url . ($error !== '' ? ': ' . $error : ''));
        }
        if (!is_string($effectiveUrl)) {
            throw new RuntimeException('Unable to determine the final download URL for ' . $url);
        }
        assertHttpsUrl($effectiveUrl, $allowedHosts);
        assertSuccessfulHttpStatus($statusCode, $url);

        fclose($target);
        $target = null;
        if (!rename($tmpPath, $targetPath)) {
            throw new RuntimeException('Unable to move downloaded file into place: ' . $targetPath);
        }
        $tmpPath = null;
    } finally {
        curl_close($curl);
        if (is_resource($target)) {
            fclose($target);
        }
        if (is_string($tmpPath)) {
            @unlink($tmpPath);
        }
    }
}

/**
 * @param string[] $allowedHosts
 */
function assertHttpsUrl(string $url, array $allowedHosts): void
{
    $parts = parse_url($url);
    if (!is_array($parts)) {
        throw new RuntimeException('Invalid download URL.');
    }

    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    $host = strtolower((string) ($parts['host'] ?? ''));
    if ($scheme !== 'https' || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
        throw new RuntimeException('Refusing download URL: ' . $url);
    }
    if (isset($parts['port']) && (int) $parts['port'] !== 443) {
        throw new RuntimeException('Refusing download URL on an unexpected port: ' . $url);
    }
    if (!in_array($host, $allowedHosts, true)) {
        throw new RuntimeException('Refusing download host: ' . $host);
    }
}

function assertSuccessfulHttpStatus(int $statusCode, string $url): void
{
    if ($statusCode >= 200 && $statusCode < 300) {
        return;
    }
    $message = 'Unable to download ' . $url . ' (HTTP ' . $statusCode . ')';
    $isApi = parse_url($url, PHP_URL_HOST) === GITHUB_API_HOST;
    if ($isApi && in_array($statusCode, [401, 403, 429], true)) {
        $message .= githubToken() === null
            ? '. GitHub limits API requests without a token per IP address; set GITHUB_TOKEN to a GitHub token to raise the limit.'
            : '. GitHub refused the request with the token from GITHUB_TOKEN or GH_TOKEN, or its rate limit is reached.';
    }
    throw new RuntimeException($message);
}

function validateArchive(string $archivePath): string
{
    $entryNames = listArchiveEntryNames($archivePath);
    $seenEntries = [];
    foreach ($entryNames as $entryName) {
        if (isset($seenEntries[$entryName])) {
            throw new RuntimeException('Release archive contains duplicate entry: ' . $entryName);
        }
        $seenEntries[$entryName] = true;
        if (!array_key_exists($entryName, ALLOWED_ARCHIVE_ENTRIES)) {
            throw new RuntimeException('Release archive contains unexpected entry: ' . $entryName);
        }
    }

    $entryTypes = listArchiveEntryTypes($archivePath);
    foreach ($entryTypes as $entryName => $entryType) {
        if (!isset($seenEntries[$entryName])) {
            throw new RuntimeException('Release archive listing is inconsistent.');
        }
        unset($entryType);
    }
    if (count($entryTypes) !== count($entryNames)) {
        throw new RuntimeException('Release archive listing is inconsistent.');
    }

    $binaryEntries = [];
    foreach ($entryNames as $entryName) {
        $expectedType = ALLOWED_ARCHIVE_ENTRIES[$entryName];
        $actualType = $entryTypes[$entryName] ?? null;
        if ($expectedType === 'directory' && $actualType !== 'directory') {
            throw new RuntimeException('Release archive entry is not a directory: ' . $entryName);
        }
        if ($expectedType === 'file' && $actualType !== 'file') {
            throw new RuntimeException('Release archive entry is not a regular file: ' . $entryName);
        }
        if (in_array($entryName, BINARY_ARCHIVE_ENTRIES, true)) {
            $binaryEntries[] = $entryName;
        }
    }
    if (count($binaryEntries) !== 1) {
        throw new RuntimeException('Release archive must contain exactly one ' . BINARY_NAME . ' entry.');
    }

    return $binaryEntries[0];
}

/**
 * @return string[]
 */
function listArchiveEntryNames(string $archivePath): array
{
    $output = runProcess([getTarBinary(), '-tzf', $archivePath], MAX_PROCESS_OUTPUT_BYTES);
    $entries = [];
    foreach (preg_split('/\r?\n/', trim($output)) ?: [] as $entry) {
        if ($entry !== '') {
            $entries[] = $entry;
        }
    }

    return $entries;
}

/**
 * @return array<string, string>
 */
function listArchiveEntryTypes(string $archivePath): array
{
    $output = runProcess([getTarBinary(), '-tvzf', $archivePath], MAX_PROCESS_OUTPUT_BYTES);
    $types = [];
    foreach (preg_split('/\r?\n/', trim($output)) ?: [] as $line) {
        if ($line === '') {
            continue;
        }

        [$entryType, $entryName] = parseVerboseTarEntry($line);
        $types[$entryName] = $entryType;
    }

    return $types;
}

/**
 * @return array{0: string, 1: string}
 */
function parseVerboseTarEntry(string $line): array
{
    $mode = $line[0] ?? '';
    $entryType = match ($mode) {
        '-' => 'file',
        'd' => 'directory',
        default => 'other',
    };
    $entryLine = preg_replace('/ -> .*$/', '', $line) ?? $line;
    $knownEntries = array_keys(ALLOWED_ARCHIVE_ENTRIES);
    usort($knownEntries, static fn (string $left, string $right): int => strlen($right) <=> strlen($left));

    foreach ($knownEntries as $entryName) {
        if (str_ends_with($entryLine, ' ' . $entryName) || str_ends_with($entryLine, "\t" . $entryName)) {
            return [$entryType, $entryName];
        }
    }

    throw new RuntimeException('Unable to parse release archive entry: ' . $line);
}

/**
 * @param string[] $command
 */
function runProcess(array $command, int $maxOutputBytes): string
{
    $stdout = '';
    $result = runProcessWithHandler(
        $command,
        $maxOutputBytes,
        MAX_PROCESS_OUTPUT_BYTES,
        static function (string $chunk) use (&$stdout): void {
            $stdout .= $chunk;
        }
    );
    if ($result['exitCode'] !== 0) {
        $details = trim($result['stderr']);
        throw new RuntimeException(
            'Process failed: ' . describeCommand($command) . ($details !== '' ? ' ' . $details : '')
        );
    }

    return $stdout;
}

/**
 * @param string[] $command
 * @param resource $target
 * @return array{exitCode: int, stderr: string}
 */
function runProcessToStream(array $command, $target, int $maxOutputBytes, int $maxStderrBytes): array
{
    return runProcessWithHandler(
        $command,
        $maxOutputBytes,
        $maxStderrBytes,
        static function (string $chunk) use ($target): void {
            if (!writeBytes($target, $chunk)) {
                throw new RuntimeException('Unable to write extracted binary.');
            }
        }
    );
}

/**
 * @param string[] $command
 * @param callable(string): void $stdoutHandler
 * @return array{exitCode: int, stderr: string}
 */
function runProcessWithHandler(array $command, int $maxStdoutBytes, int $maxStderrBytes, callable $stdoutHandler): array
{
    $pipes = [];
    $process = proc_open(
        $command,
        [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        null,
        getProcessEnvironment()
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start process: ' . describeCommand($command));
    }

    $stdoutOpen = true;
    $stderrOpen = true;
    $stdoutBytes = 0;
    $stderr = '';
    $deadline = microtime(true) + PROCESS_TIMEOUT_SECONDS;
    $exitedAt = null;
    $exitKnown = false;
    $exitCode = 1;

    try {
        if (!stream_set_blocking($pipes[1], false) || !stream_set_blocking($pipes[2], false)) {
            throw new RuntimeException('Unable to set process pipes to non-blocking mode.');
        }

        while ($stdoutOpen || $stderrOpen) {
            $now = microtime(true);
            if ($now > $deadline) {
                throw new RuntimeException('Process timed out: ' . describeCommand($command));
            }

            $status = proc_get_status($process);
            if (!$status['running'] && !$exitKnown) {
                $exitKnown = true;
                $exitCode = exitCodeFromStatus($status);
                $exitedAt = $now;
            }
            if ($exitedAt !== null && $now - $exitedAt > PROCESS_EXIT_DRAIN_SECONDS) {
                throw new RuntimeException('Process pipes did not close after exit: ' . describeCommand($command));
            }

            $readBytes = 0;
            if ($stdoutOpen) {
                $readBytes += readProcessPipe(
                    $pipes[1],
                    $stdoutOpen,
                    static function (string $chunk) use (&$stdoutBytes, $maxStdoutBytes, $stdoutHandler): void {
                        $stdoutBytes += strlen($chunk);
                        if ($stdoutBytes > $maxStdoutBytes) {
                            throw new RuntimeException('Process stdout exceeds the maximum allowed size.');
                        }
                        $stdoutHandler($chunk);
                    }
                );
            }
            if ($stderrOpen) {
                $readBytes += readProcessPipe(
                    $pipes[2],
                    $stderrOpen,
                    static function (string $chunk) use (&$stderr, $maxStderrBytes): void {
                        $stderr .= $chunk;
                        if (strlen($stderr) > $maxStderrBytes) {
                            throw new RuntimeException('Process stderr exceeds the maximum allowed size.');
                        }
                    }
                );
            }
            if ($readBytes === 0) {
                usleep(10000);
            }
        }

        while (!$exitKnown) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Process timed out: ' . describeCommand($command));
            }
            $status = proc_get_status($process);
            if (!$status['running']) {
                $exitKnown = true;
                $exitCode = exitCodeFromStatus($status);
                break;
            }
            usleep(10000);
        }

        proc_close($process);
        $process = null;

        return [
            'exitCode' => $exitCode,
            'stderr' => $stderr,
        ];
    } catch (Throwable $exception) {
        closeProcessPipes($pipes);
        terminateProcess($process);
        throw $exception;
    } finally {
        closeProcessPipes($pipes);
        if (is_resource($process)) {
            proc_close($process);
        }
    }
}

/**
 * @param array{running: bool, signaled: bool, exitcode: int, termsig: int} $status
 */
function exitCodeFromStatus(array $status): int
{
    if (!empty($status['signaled'])) {
        return 128 + (int) $status['termsig'];
    }

    return (int) $status['exitcode'];
}

/**
 * @param resource $pipe
 * @param callable(string): void $handler
 */
function readProcessPipe($pipe, bool &$isOpen, callable $handler): int
{
    if (!$isOpen) {
        return 0;
    }

    $chunk = fread($pipe, DOWNLOAD_CHUNK_BYTES);
    if ($chunk === false) {
        if (feof($pipe)) {
            fclose($pipe);
            $isOpen = false;
        }

        return 0;
    }
    if ($chunk !== '') {
        $handler($chunk);
    }
    if (feof($pipe)) {
        fclose($pipe);
        $isOpen = false;
    }

    return strlen($chunk);
}

/**
 * @return array<string, string>
 */
function getProcessEnvironment(): array
{
    return [
        'PATH' => '/usr/bin:/bin',
        'LANG' => 'C',
        'LC_ALL' => 'C',
    ];
}

/**
 * @param array<int, resource> $pipes
 */
function closeProcessPipes(array &$pipes): void
{
    foreach ($pipes as $index => $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
        unset($pipes[$index]);
    }
}

/**
 * @param resource|null $process
 */
function terminateProcess($process): void
{
    if (!is_resource($process)) {
        return;
    }

    $status = proc_get_status($process);
    if (!$status['running']) {
        return;
    }

    proc_terminate($process);
    for ($attempt = 0; $attempt < 20; $attempt++) {
        usleep(10000);
        $status = proc_get_status($process);
        if (!$status['running']) {
            return;
        }
    }

    proc_terminate($process, 9);
}

/**
 * @param resource $stream
 */
function writeBytes($stream, string $bytes): bool
{
    $offset = 0;
    $length = strlen($bytes);
    while ($offset < $length) {
        $written = fwrite($stream, substr($bytes, $offset));
        if (!is_int($written) || $written <= 0) {
            return false;
        }
        $offset += $written;
    }

    return true;
}

function sha256RegularFile(string $path, int $maxBytes, string $label): string
{
    if (is_link($path) || !is_file($path)) {
        throw new RuntimeException('Not a regular file: ' . $label);
    }

    $size = filesize($path);
    if (!is_int($size) || $size <= 0 || $size > $maxBytes) {
        throw new RuntimeException('File is outside the allowed size: ' . $label);
    }

    $hash = hash_file('sha256', $path);
    if (!is_string($hash)) {
        throw new RuntimeException('Unable to hash ' . $label);
    }

    return strtolower($hash);
}

function createTemporaryDirectory(string $prefix): string
{
    return createTemporaryDirectoryInDirectory(sys_get_temp_dir(), $prefix);
}

function createTemporaryDirectoryInDirectory(string $directory, string $prefix): string
{
    $baseDir = rtrim($directory, DIRECTORY_SEPARATOR);
    for ($attempt = 0; $attempt < TEMP_FILE_ATTEMPTS; $attempt++) {
        $path = $baseDir . DIRECTORY_SEPARATOR . $prefix . bin2hex(random_bytes(16));
        if (mkdir($path, 0700)) {
            chmod($path, 0700);
            return $path;
        }
    }

    throw new RuntimeException('Unable to create a temporary directory in ' . $baseDir);
}

/**
 * @return array{0: string, 1: resource}
 */
function openExclusiveTemporaryFile(string $directory, string $prefix): array
{
    for ($attempt = 0; $attempt < TEMP_FILE_ATTEMPTS; $attempt++) {
        $path = $directory . DIRECTORY_SEPARATOR . $prefix . bin2hex(random_bytes(16));
        $handle = @fopen($path, 'xb');
        if (is_resource($handle)) {
            return [$path, $handle];
        }
    }

    throw new RuntimeException('Unable to create a temporary file in ' . $directory);
}

function getTarBinary(): string
{
    $candidates = PHP_OS_FAMILY === 'Darwin'
        ? ['/usr/bin/tar']
        : ['/bin/tar', '/usr/bin/tar'];

    foreach ($candidates as $tarBinary) {
        if (is_file($tarBinary) && is_executable($tarBinary)) {
            return $tarBinary;
        }
    }

    throw new RuntimeException('Required tar binary is not executable: ' . implode(' or ', $candidates));
}

/**
 * @param string[] $command
 */
function describeCommand(array $command): string
{
    return implode(' ', $command);
}

function removePath(string $path): void
{
    if (is_link($path) || is_file($path)) {
        @unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }

    $items = scandir($path);
    if ($items !== false) {
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            removePath($path . DIRECTORY_SEPARATOR . $item);
        }
    }

    @rmdir($path);
}
