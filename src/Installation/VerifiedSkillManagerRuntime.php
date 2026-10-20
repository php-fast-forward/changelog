<?php

declare(strict_types=1);

namespace FastForward\Changelog\Installation;

use FastForward\Changelog\Filesystem\ManagedFileStoreInterface;
use FastForward\Changelog\Process\Factory\ProcessFactoryInterface;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Runs the official self-contained manager instead of activating a package supplied by the consumer. */
final readonly class VerifiedSkillManagerRuntime implements SkillManagerRuntimeInterface
{
    public const string COMMAND = 'verified-llm-skills-1.15.0.phar';
    public const string URL = 'https://github.com/roxblnfk/skills/releases/download/1.15.0/skills.phar';
    public const string SHA256 = '48df10e6b205e3584bef7a95d028e1f2e44c33d7122f46bd1a22edd2fbf8e9ad';
    private const int MAX_BYTES = 2_000_000;

    /**
     * Captures trusted composition values and replaceable IO without acquiring or loading executable code.
     *
     * @param HttpClientInterface       $http               Anonymous transport; project credentials and repository URLs are never supplied.
     * @param Filesystem                $filesystem         Temporary-file allocation outside the consumer checkout.
     * @param ManagedFileStoreInterface $files              Guarded writes, repeated byte reads and regular-file cleanup.
     * @param ProcessFactoryInterface   $processes          Unstarted PHP processes retaining the credential filtering boundary.
     * @param string                    $temporaryDirectory Canonical private temporary root from trusted runtime configuration.
     * @param string                    $expectedDigest     Trusted composition integrity anchor, defaulting to the fixed official release digest; never read from project metadata.
     */
    public function __construct(
        private HttpClientInterface $http,
        private Filesystem $filesystem,
        private ManagedFileStoreInterface $files,
        private ProcessFactoryInterface $processes,
        private string $temporaryDirectory,
        private string $expectedDigest = self::SHA256,
    ) {}

    /**
     * Executes only verified PHAR bytes whose own bootstrap contains its dependencies and disables consumer plugins/scripts.
     *
     * @param string       $root            Guarded consumer root; it must not contain the selected temporary directory.
     * @param string       $vendorDirectory Reviewed relative Composer vendor directory passed as declarative configuration.
     * @param list<string> $arguments       Manager command and literal arguments; no shell or consumer executable is used.
     *
     * @return void Manager success followed by removal of the temporary verified executable.
     *
     * @throws RuntimeException When containment, downloaded bytes, persisted bytes or manager completion cannot be verified.
     */
    public function run(string $root, string $vendorDirectory, array $arguments): void
    {
        if (! Path::isAbsolute($this->temporaryDirectory) || Path::isBasePath($root, $this->temporaryDirectory)) {
            throw new RuntimeException(
                'The verified skill runtime requires temporary storage outside the consumer checkout.',
            );
        }
        $bytes = $this->download();
        $path = $this->filesystem->tempnam($this->temporaryDirectory, 'fast-forward-skills-', '.phar');
        if (! Path::isBasePath($this->temporaryDirectory, $path) || Path::isBasePath($root, $path)) {
            throw new RuntimeException('The temporary skill runtime escaped its trusted storage directory.');
        }
        try {
            $this->files->write($path, $bytes);
            if (! hash_equals($this->expectedDigest, hash('sha256', $this->files->read($path) ?? ''))) {
                throw new RuntimeException('The persisted skill runtime failed its integrity check.');
            }
            $process = $this->processes->create([PHP_BINARY, $path, ...$arguments]);
            $process->setWorkingDirectory($root);
            $process->setEnv(
                [...$process->getEnv(), 'COMPOSER' => 'composer.json', 'COMPOSER_VENDOR_DIR' => $vendorDirectory],
            );
            $process->setTimeout(300);
            if (0 !== $process->run()) {
                throw new RuntimeException(
                    'The verified skill manager did not complete; project changes were retained for review.',
                );
            }
        } finally {
            $this->files->remove($path);
        }
    }

    /**
     * Fetches only the fixed official asset through bounded anonymous HTTPS and verifies the complete payload digest.
     *
     * @return string Authenticated-by-digest PHAR bytes; no project metadata can choose the URL or digest.
     *
     * @throws RuntimeException When a response exceeds the size bound or has a different release digest.
     */
    private function download(): string
    {
        $response = $this->http->request('GET', self::URL, [
            'timeout' => 10, 'max_duration' => 60,
            'on_progress' => static function (int $downloaded): void {
                if ($downloaded > self::MAX_BYTES) {
                    throw new RuntimeException('The official skill runtime exceeds its download size limit.');
                }
            },
        ]);
        $bytes = $response->getContent();
        if (strlen($bytes) > self::MAX_BYTES || ! hash_equals($this->expectedDigest, hash('sha256', $bytes))) {
            throw new RuntimeException('The official skill runtime does not match its verified release digest.');
        }

        return $bytes;
    }
}
