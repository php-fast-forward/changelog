<?php

declare(strict_types=1);

namespace FastForward\Changelog\Installation;

/** Reads the public installation contract from one verified stable product release. */
interface WorkflowReleaseResolverInterface
{
    /**
     * Resolves the runtime's stable release or an explicit full stable tag without GitHub mutations.
     *
     * @param  ?string                                                                             $reference Full published stable tag; null selects the executing package's stable version.
     * @return array{ref:string,sha:string,caller:string,inputs:array<string,array<string,mixed>>} Verified immutable release contract.
     * @throws \InvalidArgumentException                                                           When the selected reference is not a full stable version.
     * @throws \RuntimeException                                                                   When public release, tag or file evidence is unavailable or inconsistent.
     */
    public function resolve(?string $reference = null): array;
}
