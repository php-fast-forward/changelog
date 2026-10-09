<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Automation\Policy;

trait PolicyFixtureTrait
{
    private function pr(string $actor = 'contributor', string $type = 'User', string $branch = 'feature'): array
    {
        return ['number' => 7, 'state' => 'open', 'user' => ['id' => 101, 'login' => $actor, 'type' => $type], 'labels' => [], 'head' => ['sha' => str_repeat(
            'a',
            40,
        ), 'ref' => $branch, 'repo' => ['id' => 1, 'full_name' => 'owner/project']], 'base' => ['sha' => str_repeat(
            'b',
            40,
        ), 'ref' => 'main', 'repo' => ['id' => 1, 'full_name' => 'owner/project']]];
    }

    private function account(string $login = 'maintainer', string $type = 'User', int $id = 202): array
    {
        return ['login' => $login, 'type' => $type, 'id' => $id];
    }

    private function label(
        string $label = 'changelog-not-required',
        string $event = 'labeled',
        int $id = 1,
        string $date = '2026-10-01T00:00:00Z',
        ?array $actor = null,
    ): array {
        return ['event' => $event, 'id' => $id, 'created_at' => $date, 'actor' => $actor ?? $this->account(), 'label' => ['name' => $label]];
    }

    private function file(string $contents): array
    {
        return ['type' => 'file', 'encoding' => 'base64', 'content' => base64_encode($contents)];
    }
}
