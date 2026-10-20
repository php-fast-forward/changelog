<?php

declare(strict_types=1);

namespace FastForward\Changelog\Installation;

use FastForward\Changelog\Filesystem\ManagedFileStoreInterface;
use FastForward\Changelog\Process\Factory\ProcessFactoryInterface;
use JsonException;
use RuntimeException;
use stdClass;
use Symfony\Component\Lock\LockFactory;

/** Configures one GitHub donor and delegates version resolution and copying to llm/skills. */
final readonly class ComposerSkillInstaller implements ProjectSkillInstallerInterface
{
    private const string PACKAGE = 'llm/skills';
    private const string DONOR = 'php-fast-forward/changelog';
    private const string SKILL = 'fast-forward-changelog';
    private const array METADATA = [
        'composer.json',
        'composer.lock',
        'skills.json',
        '.gitignore',
        '.gitattributes',
        'skills-lock.json',
    ];

    /**
     * Captures existing side-effect boundaries; construction performs no local or remote observation.
     *
     * @param InstallationFilesystemInterface $filesystem Guarded project-relative metadata reads.
     * @param ManagedFileStoreInterface       $files      Atomic guarded metadata replacement under the project lock.
     * @param ProcessFactoryInterface         $processes  Unstarted structured Composer processes; no shell interpolation.
     * @param LockFactory                     $locks      Cooperative project-setup locks, never acquired during preview.
     * @param SkillManagerRuntimeInterface    $runtime    Verified independent PHAR execution; never loads the consumer manager or autoloader.
     */
    public function __construct(
        private InstallationFilesystemInterface $filesystem,
        private ManagedFileStoreInterface $files,
        private ProcessFactoryInterface $processes,
        private LockFactory $locks,
        private SkillManagerRuntimeInterface $runtime,
    ) {}

    /**
     * Applies only a matching reviewed plan and reports the manager's observed result without creating receipts.
     *
     * Composer scripts and plugins are suppressed. Skill commands use a digest-verified independent PHAR;
     * this operation never grants consumer plugin execution or claims rollback of dependency-manager effects.
     *
     * @param string  $root                Previously guarded absolute Git root.
     * @param bool    $dryRun              Suppress writes, locks and process execution.
     * @param ?string $expectedFingerprint Fingerprint supplied by the caller's reviewed preview.
     *
     * @return array<string,mixed> Qualified plan or completed manager setup; existing configuration remains authoritative.
     *
     * @throws RuntimeException When metadata changes, a write fails or the delegated manager cannot complete.
     */
    public function run(string $root, bool $dryRun = false, ?string $expectedFingerprint = null): array
    {
        [$plan, $snapshot, $writes] = $this->plan($root);
        if (null !== $expectedFingerprint && ! hash_equals($expectedFingerprint, $plan['fingerprint'])) {
            return [...$plan, 'state' => 'blocked', 'diagnostics' => [
                'Skill setup changed after confirmation; review the new plan.',
            ]];
        }
        if ($dryRun || in_array($plan['state'], ['blocked', 'current'], true)) {
            return $plan;
        }

        $lock = $this->locks->createLock('fast-forward/changelog:project-skills:' . hash('sha256', $root));
        if (! $lock->acquire()) {
            return [...$plan, 'state' => 'blocked', 'diagnostics' => [
                'Another project skill setup is running; no metadata was changed.',
            ]];
        }

        try {
            foreach ($snapshot as $path => $bytes) {
                if ($this->filesystem->read($root, $path) !== $bytes) {
                    throw new RuntimeException(
                        'Project skill metadata changed before application; no setup commands were run.',
                    );
                }
            }
            foreach ($writes as $path => $bytes) {
                $this->files->write($root . '/' . $path, $bytes);
            }
            foreach ($plan['commands'] as $command) {
                if (VerifiedSkillManagerRuntime::COMMAND === $command[0]) {
                    $this->runtime->run($root, $plan['vendor_directory'], array_slice($command, 1));

                    continue;
                }
                $process = $this->processes->create($command);
                $process->setWorkingDirectory($root);
                $process->setEnv(
                    [...$process->getEnv(), 'COMPOSER' => 'composer.json', 'COMPOSER_VENDOR_DIR' => $plan['vendor_directory']],
                );
                $process->setTimeout(300);
                if (0 !== $process->run()) {
                    throw new RuntimeException(
                        'Composer skill setup failed (exit ' . $process->getExitCode() . '). Metadata and completed dependency changes were retained; inspect the displayed command before retrying.',
                    );
                }
            }
            $managed = $this->object($this->filesystem->read($root, 'skills.json') ?? '{}', 'skills.json');
            if (! $this->selected($this->source($managed))
                || null === $this->filesystem->read($root, $plan['target'] . '/' . self::SKILL . '/SKILL.md')) {
                throw new RuntimeException(
                    'The manager did not leave the selected skill and its GitHub source available; no native receipt was created.',
                );
            }

            return [...$plan, 'state' => 'installed'];
        } finally {
            $lock->release();
        }
    }

    /**
     * Builds a local plan without resolving releases or invoking Composer.
     *
     * @param string $root Previously inspected absolute project root.
     *
     * @return array{array<string,mixed>,array<string,?string>,array<string,string>} Public plan, exact input snapshot and intended metadata writes.
     *
     * @throws RuntimeException When JSON or paths cannot be interpreted without changing owner policy.
     */
    private function plan(string $root): array
    {
        $snapshot = [];
        foreach (self::METADATA as $path) {
            $snapshot[$path] = $this->filesystem->read($root, $path);
        }
        $plan = ['state' => 'planned', 'manager' => self::PACKAGE, 'source' => self::DONOR,
            'skill' => self::SKILL, 'target' => '.agents/skills', 'commands' => [], 'changes' => [], 'diagnostics' => []];
        $writes = [];
        $blocked = $this->ownership($snapshot);
        if (null !== $blocked || null === $snapshot['composer.json']) {
            $plan['state'] = 'blocked';
            $plan['diagnostics'][] = $blocked ?? 'Skill setup requires an existing composer.json; use --no-skill for workflow-only setup.';

            return $this->result($root, $plan, $snapshot, $writes);
        }

        $composer = $this->object($snapshot['composer.json'], 'composer.json');
        $inline = $composer->extra->skills ?? null;
        if (null === $snapshot['skills.json'] && null !== $inline
            && (! $inline instanceof stdClass || [] !== array_diff(array_keys(get_object_vars($inline)), ['source']))) {
            $plan['state'] = 'blocked';
            $plan['diagnostics'][] = 'Existing inline extra.skills settings were preserved; migrate them with llm/skills before project setup.';

            return $this->result($root, $plan, $snapshot, []);
        }
        $config = $this->configuration($snapshot['skills.json']);
        if (null !== ($config->{'path-from-root'} ?? null)) {
            $plan['state'] = 'blocked';
            $plan['diagnostics'][] = 'Existing path-from-root changes the manager target anchor; use llm/skills directly to preserve its ownership boundary.';

            return $this->result($root, $plan, $snapshot, []);
        }
        $target = $config->target ?? '.agents/skills';
        if (! is_string($target) || '' === $target) {
            throw new RuntimeException('skills.json target must be an explicit project-relative directory.');
        }
        $target = rtrim($target, '/');
        $plan['target'] = $target;
        $skillPath = $target . '/' . self::SKILL . '/';
        foreach (['SKILL.md', '.fast-forward-install.json'] as $file) {
            $snapshot[$skillPath . $file] = $this->filesystem->read($root, $skillPath . $file);
        }
        $source = $this->source($config);
        if (null !== $snapshot[$skillPath . '.fast-forward-install.json']
            || (null !== $snapshot[$skillPath . 'SKILL.md'] && ! $this->selected($source))) {
            $plan['state'] = 'blocked';
            $plan['diagnostics'][] = 'An existing native or unknown skill was preserved; migrate its ownership explicitly before using the Composer manager.';

            return $this->result($root, $plan, $snapshot, $writes);
        }

        $writes = $this->metadata($snapshot, $config, $target);
        $vendor = $composer->config->{'vendor-dir'} ?? 'vendor';
        if (! is_string($vendor) || '' === $vendor) {
            throw new RuntimeException('Composer vendor-dir must be a project-relative directory.');
        }
        $installedPath = rtrim($vendor, '/') . '/composer/installed.json';
        $plan['vendor_directory'] = rtrim($vendor, '/');
        $locked = $this->installed($snapshot['composer.lock'], 'Composer lock');
        $snapshot[$installedPath] = $this->filesystem->read($root, $installedPath);
        $installed = $this->installed($snapshot[$installedPath]);
        $declared = isset($composer->{'require-dev'}->{self::PACKAGE}) || isset($composer->require->{self::PACKAGE});
        if ($declared && ! $installed && ! $locked) {
            foreach (['require', 'require-dev'] as $section) {
                if (isset($composer->{$section}->{self::PACKAGE})
                    && ! $this->compatibleConstraint($composer->{$section}->{self::PACKAGE})) {
                    throw new RuntimeException(
                        'The existing llm/skills constraint cannot be verified without compatible lock or installed metadata. Review and install that dependency before project setup.',
                    );
                }
            }
        }
        $approval = $this->allowManager($composer);
        $plan['plugin_approval'] = match ($approval) {
            true => 'existing', false => 'denied', null => 'required',
        };
        if (false === $approval) {
            $plan['state'] = 'blocked';
            $plan['diagnostics'][] = 'Composer explicitly denies llm/skills; the existing plugin policy was preserved.';

            return $this->result($root, $plan, $snapshot, []);
        }
        $missingPayload = null === $snapshot[$skillPath . 'SKILL.md'];
        if (! $declared || ! $installed || ! $this->selected($source) || $missingPayload) {
            $plan['commands'] = $this->commands($root, $source, $declared, $installed);
        }
        if ([] === $writes && [] === $plan['commands']) {
            $plan['state'] = 'current';
        }
        $plan['diagnostics'][] = 'Skill commands use the verified official 1.15.0 PHAR; consumer Composer plugins and autoloaders are not activated by setup.';
        if (null === $approval) {
            $plan['diagnostics'][] = 'No plugin approval was added. Future Composer auto-sync requires your explicit approval of a trusted llm/skills installation after reviewing its provenance.';
        }
        $plan['diagnostics'][] = 'Commands use this root composer.json and its reviewed vendor-dir (' . $plan['vendor_directory'] . '); COMPOSER and COMPOSER_VENDOR_DIR environment overrides are replaced.';

        return $this->result($root, $plan, $snapshot, $writes);
    }

    /**
     * Preserves an existing manager document; fresh setup trusts only the selected GitHub skill.
     *
     * @param ?string $bytes Existing skills.json bytes, or null for a new project configuration.
     *
     * @return stdClass Owner configuration or the standard narrow llm/skills preset.
     *
     * @throws RuntimeException When existing JSON cannot be decoded as an object.
     */
    private function configuration(?string $bytes): stdClass
    {
        return null !== $bytes ? $this->object($bytes, 'skills.json') : (object) [
            '$schema' => 'https://raw.githubusercontent.com/roxblnfk/skills/master/resources/skills.schema.json',
            'target' => '.agents/skills', 'aliases' => [], 'auto-sync' => true,
            'dependencies' => (object) ['composer' => false], 'discovery' => false, 'vendor-sources' => false, 'sources' => [],
        ];
    }

    /**
     * Adds the new manager preset and fixed default-target ignore rules without rewriting existing policy.
     *
     * @param array<string,?string> $snapshot Exact existing root metadata.
     * @param stdClass              $config   Standard manager configuration, whose existing bytes are preserved.
     * @param string                $target   Guarded project-relative install directory.
     *
     * @return array<string,string> Only metadata paths requiring changes.
     */
    private function metadata(array $snapshot, stdClass $config, string $target): array
    {
        $writes = null === $snapshot['skills.json'] ? ['skills.json' => $this->json($config, "\n")] : [];
        if ('.agents/skills' === $target) {
            foreach (['.gitignore' => '/.agents/', '.gitattributes' => '/.agents/** export-ignore'] as $path => $rule) {
                $bytes = $this->appendRule($snapshot[$path], $rule);
                if ($bytes !== $snapshot[$path]) {
                    $writes[$path] = $bytes;
                }
            }
        }

        return $writes;
    }

    /**
     * Delegates dependency installation and one selected donor without changing an existing source constraint.
     *
     * @param string    $root      Guarded project identity supplied explicitly to Composer.
     * @param ?stdClass $source    Existing GitHub donor or null before registration.
     * @param bool      $declared  Whether Composer already declares llm/skills in either dependency scope.
     * @param bool      $installed Whether Composer's local package metadata records the plugin.
     *
     * @return list<list<string>> Composer argv always disables plugins; the fixed PHAR marker selects the independent verified runtime.
     *
     * @throws RuntimeException When adding an allowlisted skill would replace an implicit owner ref.
     */
    private function commands(string $root, ?stdClass $source, bool $declared, bool $installed): array
    {
        $base = ['composer', '--working-dir=' . $root, '--no-scripts', '--no-interaction'];
        $commands = [];
        if (! $declared) {
            $commands[] = [
                ...$base,
                '--no-plugins',
                'require',
                '--dev',
                '--prefer-dist',
                '--no-progress',
                self::PACKAGE . ':^1.15',
            ];
        } elseif (! $installed) {
            $commands[] = [...$base, '--no-plugins', 'install', '--prefer-dist', '--no-progress'];
        }
        $selected = $this->selected($source);
        $command = $selected ? [
            VerifiedSkillManagerRuntime::COMMAND,
            'update',
            'fast-forward/changelog',
            '--no-interaction',
        ]
            : [VerifiedSkillManagerRuntime::COMMAND, 'add', self::DONOR, '--skill=' . self::SKILL, '--no-interaction'];
        if (null !== $source && ! $selected) {
            if (! is_string($source->ref ?? null) || '' === $source->ref) {
                throw new RuntimeException(
                    'The existing donor has no explicit ref; review adding the skill through its manager.',
                );
            }
            $command[] = '--ref=' . $source->ref;
            if ('github.com' === ($source->host ?? null)) {
                $command[] = '--host=github.com';
            }
        }

        return [...$commands, $command];
    }

    /**
     * Refuses foreign lock ownership without loading or adopting an installed payload.
     *
     * @param array<string,?string> $snapshot Guarded root metadata bytes.
     *
     * @return ?string Blocking diagnostic, or null when no conflicting NPX owner is declared.
     *
     * @throws RuntimeException When the lock JSON cannot be interpreted safely.
     */
    private function ownership(array $snapshot): ?string
    {
        if (null === $snapshot['skills-lock.json']) {
            return null;
        }
        $lock = $this->object($snapshot['skills-lock.json'], 'skills-lock.json');
        if (! ($lock->skills ?? null) instanceof stdClass || property_exists($lock->skills, self::SKILL)) {
            return 'The NPX skill lock is unknown or owns fast-forward-changelog; its manager and installed files were preserved.';
        }

        return null;
    }

    /**
     * Selects only the canonical public GitHub donor while preserving its exact declared ref and allowlist.
     *
     * @param stdClass $config Existing standard llm/skills project configuration.
     *
     * @return ?stdClass Matching source, or null when that donor is not registered.
     *
     * @throws RuntimeException When source containers are malformed or the donor is ambiguous.
     */
    private function source(stdClass $config): ?stdClass
    {
        if (property_exists($config, 'remote')) {
            throw new RuntimeException(
                'Existing remote source alias was preserved; migrate it with llm/skills before project setup.',
            );
        }
        if (! is_array($config->sources ?? [])) {
            throw new RuntimeException('skills.json sources must be a list managed by llm/skills.');
        }
        $found = null;
        foreach ($config->sources ?? [] as $source) {
            if (! $source instanceof stdClass) {
                throw new RuntimeException('skills.json source entries must be objects.');
            }
            if ('github' !== ($source->from ?? null) || self::DONOR !== ($source->package ?? null)) {
                continue;
            }
            if (isset($source->host) && 'github.com' !== $source->host) {
                throw new RuntimeException(
                    'The changelog donor has a noncanonical host; preserve it and review that source with llm/skills.',
                );
            }
            if (null !== $found) {
                throw new RuntimeException('Multiple changelog GitHub sources require explicit manager review.');
            }
            $found = $source;
        }

        return $found;
    }

    /**
     * Honors an existing donor allowlist without broadening its selected skill set implicitly.
     *
     * @param ?stdClass $source Canonical GitHub donor entry, or null before registration.
     *
     * @return bool Whether the manager already selects this skill, including an unrestricted donor.
     *
     * @throws RuntimeException When the owner allowlist has an unsupported shape.
     */
    private function selected(?stdClass $source): bool
    {
        if (null === $source) {
            return false;
        }
        if (! property_exists($source, 'skills')) {
            return true;
        }
        if (! is_array($source->skills)) {
            throw new RuntimeException('The changelog donor skills allowlist must be a list.');
        }

        return in_array(self::SKILL, $source->skills, true);
    }

    /**
     * Observes existing plugin approval without ever granting execution to a consumer-supplied package.
     *
     * @param stdClass $composer Local manifest object whose unrelated values retain their JSON types.
     *
     * @return ?bool Existing approval or denial, or null when approval remains an explicit future user operation.
     *
     * @throws RuntimeException When Composer configuration has an unsupported shape.
     */
    private function allowManager(stdClass $composer): ?bool
    {
        $config = $composer->config ?? new stdClass();
        if (! $config instanceof stdClass) {
            throw new RuntimeException('Composer config must be an object.');
        }
        $allow = $config->{'allow-plugins'} ?? new stdClass();
        if (is_bool($allow)) {
            return $allow;
        }
        if (! $allow instanceof stdClass) {
            throw new RuntimeException('Composer allow-plugins must be a boolean or object.');
        }
        foreach ($allow as $pattern => $permitted) {
            if (1 === preg_match('/\A' . str_replace('\\*', '.*', preg_quote($pattern, '/')) . '\z/', self::PACKAGE)) {
                return true === $permitted;
            }
        }

        return null;
    }

    /**
     * Qualifies Composer package records without executing generated PHP metadata or resolving new dependencies.
     *
     * @param ?string $bytes Existing installed.json/lock bytes, or null before dependency installation.
     * @param string  $name  Fixed diagnostic metadata label.
     *
     * @return bool Whether the supplied local metadata records a manager version in the supported stable 1.x range.
     *
     * @throws RuntimeException When metadata is invalid or an existing manager cannot support the verified 1.15/1.x setup contract.
     */
    private function installed(?string $bytes, string $name = 'Composer installed metadata'): bool
    {
        if (null === $bytes) {
            return false;
        }
        $data = $this->decode($bytes, $name, true);
        if (! is_array($data)) {
            throw new RuntimeException('Composer installed metadata must contain a package list.');
        }
        $packages = $data['packages'] ?? $data;
        $development = $data['packages-dev'] ?? [];
        if (! is_array($packages) || ! is_array($development)) {
            throw new RuntimeException('Composer installed packages must be a list.');
        }

        foreach ([...$packages, ...$development] as $package) {
            if (! is_array($package) || self::PACKAGE !== ($package['name'] ?? null)) {
                continue;
            }
            $version = $package['version_normalized'] ?? $package['version'] ?? null;
            if (! is_string($version) || 1 !== preg_match('/\Av?(\d+\.\d+\.\d+)(?:\.0)?\z/', $version, $match)
                || version_compare($match[1], '1.15.0', '<') || version_compare($match[1], '2.0.0', '>=')) {
                throw new RuntimeException(
                    $name . ' records an unverified llm/skills; project setup requires stable 1.x from 1.15.0. Review its dependency constraint before retrying.',
                );
            }

            return true;
        }

        return false;
    }

    /**
     * Proves common 1.x constraints when no local package version exists; complex expressions require owner review.
     *
     * This deliberately recognizes only exact, caret and tilde forms with a known lower bound.
     * It does not reinterpret Composer's full constraint grammar or rewrite an existing requirement.
     *
     * @param mixed $constraint Existing owner requirement, never a value inferred from a directory or registry.
     *
     * @return bool Whether every accepted version remains in the verified stable 1.x line from 1.15.0.
     */
    private function compatibleConstraint(mixed $constraint): bool
    {
        return is_string($constraint)
            && 1 === preg_match('/\A[\^~]?v?1\.(\d+)(?:\.\d+)?\z/', $constraint, $match)
            && (int) $match[1] >= 15;
    }

    /**
     * Appends an exact owner-requested ignore rule without changing existing bytes or newline convention.
     *
     * @param ?string $bytes Existing file bytes, or null when absent.
     * @param string  $rule  Fixed .agents ignore/export rule.
     *
     * @return string Existing bytes plus the missing rule and terminating newline.
     */
    private function appendRule(?string $bytes, string $rule): string
    {
        $bytes ??= '';
        if (in_array($rule, preg_split('/\r\n|\n|\r/', $bytes), true)) {
            return $bytes;
        }
        $newline = str_contains($bytes, "\r\n") ? "\r\n" : "\n";

        return $bytes . ('' !== $bytes && ! str_ends_with($bytes, "\n") && ! str_ends_with(
            $bytes,
            "\r",
        ) ? $newline : '') . $rule . $newline;
    }

    /**
     * Decodes configuration as an object so unknown keys, empty objects and list values retain their JSON types.
     *
     * @param string $bytes Exact guarded metadata bytes.
     * @param string $name  Fixed diagnostic filename.
     *
     * @return stdClass Decoded owner configuration; no referenced code is loaded.
     *
     * @throws RuntimeException When the document root is not an object.
     */
    private function object(string $bytes, string $name): stdClass
    {
        $value = $this->decode($bytes, $name);
        if (! $value instanceof stdClass) {
            throw new RuntimeException($name . ' must contain a JSON object.');
        }

        return $value;
    }

    /**
     * Turns malformed JSON into an operational diagnostic without exposing the owner's metadata bytes.
     *
     * @param string $bytes       Exact guarded JSON contents.
     * @param string $name        Fixed diagnostic file label.
     * @param bool   $associative Decode package lists as arrays instead of configuration objects.
     *
     * @return mixed Decoded JSON value, retaining object/list distinctions by default.
     *
     * @throws RuntimeException When JSON is malformed or not valid UTF-8.
     */
    private function decode(string $bytes, string $name, bool $associative = false): mixed
    {
        try {
            return json_decode($bytes, $associative, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $failure) {
            throw new RuntimeException($name . ' contains invalid JSON.', previous: $failure);
        }
    }

    /**
     * Renders changed metadata while keeping nested owner values as objects or lists as originally decoded.
     *
     * @param stdClass $value   Complete metadata object with narrowly selected changes.
     * @param string   $newline Existing file's newline convention.
     *
     * @return string Complete JSON plus one terminating newline.
     */
    private function json(stdClass $value, string $newline): string
    {
        return str_replace(
            "\n",
            $newline,
            json_encode(
                $value,
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ),
        ) . $newline;
    }

    /**
     * Binds commands and input bytes to the reviewed root without exposing existing file contents in the preview.
     *
     * @param string                $root     Explicit project identity.
     * @param array<string,mixed>   $plan     Qualified public component state.
     * @param array<string,?string> $snapshot Exact prior bytes retained only for application checks.
     * @param array<string,string>  $writes   Planned guarded metadata contents.
     *
     * @return array{array<string,mixed>,array<string,?string>,array<string,string>} Public plan plus private application evidence.
     */
    private function result(string $root, array $plan, array $snapshot, array $writes): array
    {
        $plan['changes'] = array_keys($writes);
        $plan['fingerprint'] = hash('sha256', json_encode([
            'root' => $root,
            'metadata' => array_map(
                static fn(?string $bytes): ?string => null === $bytes ? null : hash('sha256', $bytes),
                $snapshot,
            ),
            'writes' => array_map(static fn(string $bytes): string => hash('sha256', $bytes), $writes),
            'commands' => $plan['commands'],
        ], JSON_THROW_ON_ERROR));

        return [$plan, $snapshot, $writes];
    }
}
