<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Workflow;

use Closure;
use FastForward\Changelog\Repository\ReleaseChannelUpdater;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/** Supplies synthetic publication metadata over a mock transport and real disposable Git object/ref transactions. */
final class ReleaseChannelFixture
{
    public array $commits = [];
    public array $releases = [];
    public array $mutations = [];
    public ?Closure $beforeMutation = null;
    public ?Closure $releaseRead = null;
    public ?Closure $catalog = null;
    public ?Closure $referenceOverride = null;
    public bool $loseResponse = false;
    public bool $tagCycle = false;
    public bool $wrongTagIdentity = false;
    public string $repository = 'fixture/library';
    private readonly string $directory;
    private int $releaseReads = 0;
    private int $catalogReads = 0;
    private readonly ReleaseChannelUpdater $updater;

    /** Creates only local Git history and uses a mock HTTP client with no network connection. */
    public function __construct()
    {
        $this->directory = sys_get_temp_dir() . '/changelog-channel-' . bin2hex(random_bytes(8));
        mkdir($this->directory . '/home', 0700, true);
        file_put_contents($this->directory . '/gitconfig', '');
        $this->git(['init', '--initial-branch=main']);
        foreach (['v0.1.0', 'v0.2.0', 'unreleased'] as $index => $tag) {
            file_put_contents($this->directory . '/state.txt', $tag);
            $this->git(['add', 'state.txt']);
            $this->git(['commit', '--no-gpg-sign', '-m', 'Synthetic ' . $tag]);
            $this->commits[$tag] = $this->git(['rev-parse', 'HEAD']);
            if ('unreleased' !== $tag) {
                $this->git(['tag', $tag]);
                $this->releases[$tag] = ['id' => $index + 1, 'tag_name' => $tag,
                    'draft' => false, 'prerelease' => false, 'published_at' => '2026-01-01T00:00:00Z'];
            }
        }
        $this->updater = new ReleaseChannelUpdater(new MockHttpClient($this->respond(...)));
    }

    /** Executes the production updater with explicit fixture repository and publication selectors. */
    public function run(string $tag = 'v0.2.0', ?string $sha = null): array
    {
        return $this->updater->update(
            'fixture/library',
            $tag,
            $sha ?? $this->commits[$tag] ?? $this->commits['v0.2.0'],
        );
    }

    /** Removes only this fixture's disposable checkout. */
    public function close(): void
    {
        $entries = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
            $this->directory,
            \FilesystemIterator::SKIP_DOTS,
        ), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($entries as $entry) {
            if (! $entry->isLink()) {
                @chmod($entry->getPathname(), $entry->isDir() ? 0700 : 0600);
            }
        }
        new Filesystem()->remove($this->directory);
    }

    /** Executes structured Git arguments with isolated HOME/configuration and a synthetic identity. */
    public function git(array $arguments, bool $optional = false): ?string
    {
        $environment = ['PATH' => getenv('PATH'), 'HOME' => $this->directory . '/home',
            'USERPROFILE' => $this->directory . '/home', 'GIT_CONFIG_NOSYSTEM' => '1',
            'GIT_CONFIG_GLOBAL' => $this->directory . '/gitconfig', 'GIT_TERMINAL_PROMPT' => '0',
            'GIT_AUTHOR_NAME' => 'Synthetic fixture', 'GIT_AUTHOR_EMAIL' => 'fixture@example.invalid',
            'GIT_COMMITTER_NAME' => 'Synthetic fixture', 'GIT_COMMITTER_EMAIL' => 'fixture@example.invalid'];
        $process = proc_open(['git', ...$arguments], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes, $this->directory, $environment, ['bypass_shell' => true]);
        if (! is_resource($process)) {
            throw new RuntimeException('Could not start disposable Git.');
        }
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        if (0 !== $status && ! $optional) {
            throw new RuntimeException('Disposable Git failed: ' . $error);
        }

        return 0 === $status ? trim($output) : null;
    }

    /** Responds to the updater's fixed GitHub routes using only fixture metadata and local Git evidence. */
    private function respond(string $method, string $url, array $options): MockResponse
    {
        $path = parse_url($url, PHP_URL_PATH);
        $status = 200;
        $data = [];
        if (str_starts_with($path, '/repos/fixture/library/releases/tags/')) {
            if (null !== $this->releaseRead) {
                ($this->releaseRead)(++$this->releaseReads);
            }
            $tag = rawurldecode(substr($path, strlen('/repos/fixture/library/releases/tags/')));
            $data = $this->releases[$tag] ?? [];
            $status = [] === $data ? 404 : 200;
        } elseif ('/repos/fixture/library/releases' === $path) {
            parse_str(parse_url($url, PHP_URL_QUERY), $query);
            $data = null !== $this->catalog ? ($this->catalog)(++$this->catalogReads, (int) $query['page'])
                : array_slice(array_values($this->releases), ((int) $query['page'] - 1) * 100, 100);
        } elseif (str_starts_with($path, '/repos/fixture/library/git/ref/')) {
            $ref = substr($path, strlen('/repos/fixture/library/git/ref/'));
            $sha = $this->git(['show-ref', '--verify', '--hash', 'refs/' . $ref], true);
            $status = null === $sha ? 404 : 200;
            $data = null === $sha ? [] : ['ref' => 'refs/' . $ref,
                'object' => ['sha' => $sha, 'type' => $this->git(['cat-file', '-t', $sha])]];
            if (null !== $this->referenceOverride) {
                $data = ($this->referenceOverride)($ref, $data);
            }
        } elseif (str_starts_with($path, '/repos/fixture/library/git/tags/')) {
            $sha = basename($path);
            $lines = explode("\n", $this->git(['cat-file', '-p', $sha]));
            $data = ['sha' => $this->wrongTagIdentity ? str_repeat('f', 40) : $sha,
                'object' => $this->tagCycle ? ['sha' => $sha, 'type' => 'tag']
                : ['sha' => substr($lines[0], 7), 'type' => substr($lines[1], 5)]];
        } elseif (str_starts_with($path, '/repos/fixture/library/compare/')) {
            [$base, $head] = explode('...', basename($path));
            $data = ['status' => null !== $this->git(
                ['merge-base', '--is-ancestor', $base, $head],
                true,
            ) ? 'ahead' : 'diverged',
                'merge_base_commit' => ['sha' => $this->git(['merge-base', $base, $head], true)]];
        } elseif ('/repos/fixture/library' === $path) {
            $data = ['node_id' => 'R_fixture', 'full_name' => $this->repository];
        } elseif ('POST' === $method && '/graphql' === $path) {
            $body = json_decode($options['body'], true, flags: JSON_THROW_ON_ERROR);
            $input = $body['variables']['input'];
            if ('R_fixture' !== $input['repositoryId'] || 1 !== count($input['refUpdates'])) {
                throw new RuntimeException('Unexpected mutation target.');
            }
            $change = $input['refUpdates'][0];
            if (false !== $change['force'] || 1 !== preg_match('~^refs/heads/v(0|[1-9][0-9]*)$~D', $change['name'])) {
                throw new RuntimeException('Only a conditional major branch update is allowed.');
            }
            if (null !== $this->beforeMutation) {
                ($this->beforeMutation)();
            }
            $current = $this->git(['show-ref', '--verify', '--hash', $change['name']], true);
            if (null !== $current && null === $this->git(
                ['merge-base', '--is-ancestor', $current, $change['afterOid']],
                true,
            )) {
                $data = ['errors' => [['message' => 'Synthetic non-fast-forward rejection']]];
            } elseif (null === $this->git(
                ['update-ref', $change['name'], $change['afterOid'], $change['beforeOid']],
                true,
            )) {
                $data = ['errors' => [['message' => 'Synthetic old-OID lease rejection']]];
            } else {
                $this->mutations[] = $change;
                $data = ['data' => ['updateRefs' => ['clientMutationId' => null]]];
                $status = $this->loseResponse ? 500 : 200;
            }
        } else {
            throw new RuntimeException('Unexpected fixture route: ' . $path);
        }

        return new MockResponse(json_encode($data, JSON_THROW_ON_ERROR), ['http_code' => $status]);
    }
}
