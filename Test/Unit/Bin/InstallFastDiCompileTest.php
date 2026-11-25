<?php

declare(strict_types=1);

namespace Spmt\FastDiCompile\Test\Unit\Bin;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once dirname(__DIR__, 3) . '/bin/install-fast-di-compile.php';

/**
 * @coversNothing
 */
class InstallFastDiCompileTest extends TestCase
{
    private const TOKEN_VARIABLES = ['GITHUB_TOKEN', 'GH_TOKEN'];

    private string $testRoot;

    /**
     * @var array<string, string|false>
     */
    private array $savedEnvironment = [];

    protected function setUp(): void
    {
        $this->testRoot = sys_get_temp_dir() . '/spmt-fast-di-compile-test-' . bin2hex(random_bytes(6));
        mkdir($this->testRoot, 0700, true);
        foreach (self::TOKEN_VARIABLES as $name) {
            $this->savedEnvironment[$name] = getenv($name);
            putenv($name);
        }
    }

    protected function tearDown(): void
    {
        \removePath($this->testRoot);
        foreach ($this->savedEnvironment as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
        }
    }

    public function testGithubApiHeadersCarryNoTokenWithoutOne(): void
    {
        $headers = \githubApiHeaders(null);

        $this->assertContains('Accept: application/vnd.github+json', $headers);
        $this->assertSame([], preg_grep('/^Authorization:/i', $headers));
    }

    #[DataProvider('tokenProvider')]
    public function testGithubApiHeadersCarryTheToken(string $token): void
    {
        $this->assertContains('Authorization: Bearer ' . $token, \githubApiHeaders($token));
    }

    /**
     * Classic, fine-grained and Actions tokens, and a longer form with `.`, `-` and `=`.
     *
     * @return array<string, array{0: string}>
     */
    public static function tokenProvider(): array
    {
        return [
            'classic' => ['ghp_' . str_repeat('a1B2', 9)],
            'fine-grained' => ['github_pat_11AB_' . str_repeat('x9', 20)],
            'actions' => ['ghs_' . str_repeat('Z0', 18)],
            'longer form' => ['ghs_eyJhbGciOi.eyJpc3Mi-Ii_x=='],
        ];
    }

    #[DataProvider('unsafeTokenProvider')]
    public function testGithubApiHeadersRejectATokenThatCouldAddAHeader(string $token): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('holds a space or a control character');

        \githubApiHeaders($token);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unsafeTokenProvider(): array
    {
        return [
            'line break' => ["ghs_abc\r\nX-Injected: 1"],
            'newline' => ["ghs_abc\nX-Injected: 1"],
            'space' => ['ghs_abc def'],
            'tab' => ["ghs_abc\tdef"],
            'null byte' => ["ghs_abc\0def"],
        ];
    }

    public function testGithubTokenReadsGithubTokenBeforeGhToken(): void
    {
        $this->assertNull(\githubToken());

        putenv('GH_TOKEN=gho_second');
        $this->assertSame('gho_second', \githubToken());

        putenv('GITHUB_TOKEN=ghs_first');
        $this->assertSame('ghs_first', \githubToken());
    }

    public function testRateLimitedApiRequestNamesGithubToken(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/HTTP 403.*set GITHUB_TOKEN/');

        \assertSuccessfulHttpStatus(403, 'https://api.github.com/repos/speedupmate/di-compiler/releases/latest');
    }

    public function testRefusedApiRequestWithATokenSaysSo(): void
    {
        putenv('GITHUB_TOKEN=ghs_expired');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/HTTP 403.*refused the request with the token/');

        \assertSuccessfulHttpStatus(403, 'https://api.github.com/repos/speedupmate/di-compiler/releases/latest');
    }

    public function testRefusedDownloadOutsideTheApiHasNoTokenHint(): void
    {
        try {
            \assertSuccessfulHttpStatus(403, 'https://github.com/speedupmate/di-compiler/releases/download/v1.0.5/a.tar.gz');
            $this->fail('A 403 must be an error.');
        } catch (RuntimeException $exception) {
            $this->assertStringNotContainsString('GITHUB_TOKEN', $exception->getMessage());
        }
    }

    public function testPrepareInstallDirectoryPreservesExistingPrivatePermissions(): void
    {
        $installDir = $this->testRoot . '/private-bin';
        mkdir($installDir, 0700);
        chmod($installDir, 0700);

        $resolvedPath = \prepareInstallDirectory($installDir);

        $this->assertSame(realpath($installDir), $resolvedPath);
        $this->assertSame(0700, $this->permissions($installDir));
    }

    public function testPrepareInstallDirectoryAppliesCreationPermissionsOnlyToNewDirectory(): void
    {
        $installDir = $this->testRoot . '/new-bin';

        $resolvedPath = \prepareInstallDirectory($installDir);

        $this->assertSame(realpath($installDir), $resolvedPath);
        $this->assertSame(0755, $this->permissions($installDir));
    }

    public function testParseChecksumFileAcceptsTextAndBinaryChecksumFormats(): void
    {
        $archiveHash = hash('sha256', 'archive');
        $binaryHash = hash('sha256', 'binary');

        $this->assertSame([
            'asset.tar.gz' => $archiveHash,
            'fast-di-compile' => $binaryHash,
        ], \parseChecksumFile($archiveHash . '  asset.tar.gz' . PHP_EOL . $binaryHash . ' *fast-di-compile' . PHP_EOL));
    }

    public function testParseChecksumFileRejectsMalformedLine(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('invalid line');

        \parseChecksumFile(hash('sha256', 'payload') . PHP_EOL . '  asset.tar.gz' . PHP_EOL);
    }

    public function testParseChecksumFileRejectsDuplicateEntry(): void
    {
        $hash = hash('sha256', 'payload');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('duplicate entry');

        \parseChecksumFile($hash . '  asset.tar.gz' . PHP_EOL . $hash . '  asset.tar.gz' . PHP_EOL);
    }

    public function testRequireDigestRejectsMissingGitHubDigest(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('missing a sha256 digest');

        \requireDigest([], 'fast-di-compile-v1.0.3-linux-x64.tar.gz');
    }

    public function testAssertFileSha256RejectsMismatchedDigest(): void
    {
        $archivePath = $this->testRoot . '/asset.tar.gz';
        file_put_contents($archivePath, 'payload');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SHA-256 mismatch');

        \assertFileSha256($archivePath, str_repeat('0', 64), 'asset.tar.gz');
    }

    public function testHttpStatusValidationAcceptsSuccessStatus(): void
    {
        \assertSuccessfulHttpStatus(200, 'https://example.test/archive.tar.gz');

        $this->addToAssertionCount(1);
    }

    public function testHttpStatusValidationRejectsFailureStatus(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('HTTP 404');

        \assertSuccessfulHttpStatus(404, 'https://example.test/archive.tar.gz');
    }

    public function testHttpsUrlValidationRejectsUnexpectedHost(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Refusing download host');

        \assertHttpsUrl('https://evil.example/archive.tar.gz', ['github.com']);
    }

    public function testGetTarBinaryUsesAbsoluteTarPath(): void
    {
        $tarBinary = \getTarBinary();
        $expectedPaths = PHP_OS_FAMILY === 'Darwin' ? ['/usr/bin/tar'] : ['/bin/tar', '/usr/bin/tar'];

        $this->assertContains($tarBinary, $expectedPaths);
        $this->assertStringStartsWith('/', $tarBinary);
    }

    public function testInstallationStampMustMatchBinaryDigest(): void
    {
        $binaryPath = $this->testRoot . '/fast-di-compile';
        $stampPath = $this->testRoot . '/fast-di-compile.release-stamp';
        file_put_contents($binaryPath, 'not-the-release-binary');
        chmod($binaryPath, 0755);
        \writeStamp($stampPath, 'v1.0.3', 'linux-x64', str_repeat('a', 64), str_repeat('b', 64));

        $this->assertFalse(\installationStampMatches($binaryPath, $stampPath, 'linux-x64', str_repeat('a', 64)));
    }

    public function testReadStampRejectsInvalidStamp(): void
    {
        $stampPath = $this->testRoot . '/fast-di-compile.release-stamp';
        file_put_contents($stampPath, 'version=v1.0.3' . PHP_EOL . 'platform=linux-x64' . PHP_EOL);

        $this->assertNull(\readStamp($stampPath));
    }

    public function testRunProcessStripsTarOptionsEnvironment(): void
    {
        $previousTarOptions = getenv('TAR_OPTIONS');
        putenv('TAR_OPTIONS=--definitely-not-a-valid-tar-option');

        try {
            $output = \runProcess([\getTarBinary(), '--version'], 1048576);
        } finally {
            if ($previousTarOptions === false) {
                putenv('TAR_OPTIONS');
            } else {
                putenv('TAR_OPTIONS=' . $previousTarOptions);
            }
        }

        $this->assertNotSame('', $output);
    }

    public function testRunProcessBoundsStderr(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('stderr exceeds');

        \runProcess([PHP_BINARY, '-r', 'fwrite(STDERR, str_repeat("x", 1048577));'], 1024);
    }

    public function testValidateArchiveAcceptsExpectedReleaseShape(): void
    {
        $sourceDir = $this->createArchiveSource();
        file_put_contents($sourceDir . '/fast-di-compile', '#!/bin/sh' . PHP_EOL . 'exit 0' . PHP_EOL);
        file_put_contents($sourceDir . '/LICENSE', 'license');
        file_put_contents($sourceDir . '/README.md', 'readme');
        $archivePath = $this->createArchive($sourceDir);

        $this->assertContains(\validateArchive($archivePath), ['./fast-di-compile', 'fast-di-compile']);
    }

    public function testValidateArchiveRejectsUnexpectedEntry(): void
    {
        $sourceDir = $this->createArchiveSource();
        file_put_contents($sourceDir . '/fast-di-compile', '#!/bin/sh' . PHP_EOL . 'exit 0' . PHP_EOL);
        file_put_contents($sourceDir . '/unexpected.txt', 'nope');
        $archivePath = $this->createArchive($sourceDir);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unexpected entry');

        \validateArchive($archivePath);
    }

    public function testValidateArchiveRejectsDuplicateBinaryEntry(): void
    {
        $sourceOne = $this->createArchiveSource();
        $sourceTwo = $this->createArchiveSource();
        file_put_contents($sourceOne . '/fast-di-compile', 'first');
        file_put_contents($sourceTwo . '/fast-di-compile', 'second');
        $archivePath = $this->testRoot . '/duplicate.tar.gz';
        \runProcess([
            \getTarBinary(),
            '-czf',
            $archivePath,
            '-C',
            $sourceOne,
            'fast-di-compile',
            '-C',
            $sourceTwo,
            'fast-di-compile',
        ], 1048576);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('duplicate entry');

        \validateArchive($archivePath);
    }

    public function testValidateArchiveRejectsSymlinkedBinary(): void
    {
        $sourceDir = $this->createArchiveSource();
        $target = $this->testRoot . '/target';
        file_put_contents($target, '#!/bin/sh' . PHP_EOL . 'exit 0' . PHP_EOL);
        if (!symlink($target, $sourceDir . '/fast-di-compile')) {
            $this->markTestSkipped('Symlinks are not available in this environment.');
        }
        $archivePath = $this->createArchive($sourceDir);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not a regular file');

        \validateArchive($archivePath);
    }

    public function testPublishBinaryRejectsEmptyBinaryMember(): void
    {
        $sourceDir = $this->createArchiveSource();
        file_put_contents($sourceDir . '/fast-di-compile', '');
        $archivePath = $this->createArchive($sourceDir);
        $installDir = $this->testRoot . '/install-empty';
        mkdir($installDir, 0700);
        $targetBinary = $installDir . '/fast-di-compile';
        $stampPath = $installDir . '/fast-di-compile.release-stamp';

        try {
            \publishBinaryFromArchive(
                $archivePath,
                './fast-di-compile',
                $targetBinary,
                $stampPath,
                'v1.0.3',
                'linux-x64',
                $this->hashFile($archivePath),
                null,
                false
            );
            $this->fail('Expected empty extracted binary to be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('outside the allowed size', $exception->getMessage());
        }

        $this->assertFileDoesNotExist($targetBinary);
        $this->assertSame([], glob($installDir . '/fast-di-compile.stage-*') ?: []);
    }

    public function testPublishBinaryReplacesSymlinkOnlyWithForceWithoutTouchingSymlinkTarget(): void
    {
        $sourceDir = $this->createArchiveSource();
        file_put_contents($sourceDir . '/fast-di-compile', 'installed-binary');
        $archivePath = $this->createArchive($sourceDir);
        $installDir = $this->testRoot . '/install';
        mkdir($installDir, 0700);
        $sensitiveFile = $this->testRoot . '/sensitive';
        $targetBinary = $installDir . '/fast-di-compile';
        $stampPath = $installDir . '/fast-di-compile.release-stamp';
        file_put_contents($sensitiveFile, 'keep');
        if (!symlink($sensitiveFile, $targetBinary)) {
            $this->markTestSkipped('Symlinks are not available in this environment.');
        }

        \publishBinaryFromArchive(
            $archivePath,
            './fast-di-compile',
            $targetBinary,
            $stampPath,
            'v1.0.3',
            'linux-x64',
            $this->hashFile($archivePath),
            hash('sha256', 'installed-binary'),
            true
        );

        $this->assertSame('keep', file_get_contents($sensitiveFile));
        $this->assertFalse(is_link($targetBinary));
        $this->assertSame('installed-binary', file_get_contents($targetBinary));
        $this->assertSame(0755, $this->permissions($targetBinary));
        $this->assertSame([], glob($installDir . '/fast-di-compile.stage-*') ?: []);
    }

    public function testPublishBinaryRefusesUnstampedDifferentExistingBinaryWithoutForce(): void
    {
        $sourceDir = $this->createArchiveSource();
        file_put_contents($sourceDir . '/fast-di-compile', 'release-binary');
        $archivePath = $this->createArchive($sourceDir);
        $installDir = $this->testRoot . '/install-existing';
        mkdir($installDir, 0700);
        $targetBinary = $installDir . '/fast-di-compile';
        $stampPath = $installDir . '/fast-di-compile.release-stamp';
        file_put_contents($targetBinary, 'local-binary');
        chmod($targetBinary, 0755);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not match this release');

        \publishBinaryFromArchive(
            $archivePath,
            './fast-di-compile',
            $targetBinary,
            $stampPath,
            'v1.0.3',
            'linux-x64',
            $this->hashFile($archivePath),
            hash('sha256', 'release-binary'),
            false
        );
    }

    public function testPublishBinaryAllowsStampedUpgradeOnlyWhenExistingBinaryMatchesStamp(): void
    {
        $sourceDir = $this->createArchiveSource();
        file_put_contents($sourceDir . '/fast-di-compile', 'new-release-binary');
        $archivePath = $this->createArchive($sourceDir);
        $installDir = $this->testRoot . '/install-stamped-upgrade';
        mkdir($installDir, 0700);
        $targetBinary = $installDir . '/fast-di-compile';
        $stampPath = $installDir . '/fast-di-compile.release-stamp';
        file_put_contents($targetBinary, 'tampered-old-binary');
        chmod($targetBinary, 0755);
        \writeStamp(
            $stampPath,
            'v1.0.2',
            'linux-x64',
            str_repeat('a', 64),
            hash('sha256', 'old-release-binary')
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not match this release');

        \publishBinaryFromArchive(
            $archivePath,
            './fast-di-compile',
            $targetBinary,
            $stampPath,
            'v1.0.3',
            'linux-x64',
            $this->hashFile($archivePath),
            hash('sha256', 'new-release-binary'),
            false
        );
    }

    private function createArchiveSource(): string
    {
        $sourceDir = $this->testRoot . '/archive-source-' . bin2hex(random_bytes(4));
        mkdir($sourceDir, 0700);

        return $sourceDir;
    }

    private function createArchive(string $sourceDir): string
    {
        $archivePath = $this->testRoot . '/archive-' . bin2hex(random_bytes(4)) . '.tar.gz';
        \runProcess([\getTarBinary(), '-czf', $archivePath, '-C', $sourceDir, '.'], 1048576);

        return $archivePath;
    }

    private function hashFile(string $path): string
    {
        $hash = hash_file('sha256', $path);
        $this->assertIsString($hash);

        return $hash;
    }

    private function permissions(string $path): int
    {
        $permissions = fileperms($path);
        $this->assertIsInt($permissions);

        return $permissions & 0777;
    }
}
