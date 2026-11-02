<?php

declare(strict_types=1);

/** Schema.org JSON-LD graph for a single HTML document (one script tag). */
final class SeoJsonLd
{
    /** @var list<array<string, mixed>> */
    private $graph = [];

    /** @param array<string, mixed> $node */
    public function add(array $node): void
    {
        unset($node['@context']);
        if ($node === []) {
            return;
        }
        $this->graph[] = $node;
    }

    public function isEmpty(): bool
    {
        return $this->graph === [];
    }

    public function graphCount(): int
    {
        return count($this->graph);
    }

    public function hasType(string $type): bool
    {
        foreach ($this->graph as $node) {
            if (($node['@type'] ?? '') === $type) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        if ($this->graph === []) {
            return [];
        }

        return [
            '@context' => 'https://schema.org',
            '@graph' => $this->graph,
        ];
    }

    public function renderScriptTag(): string
    {
        $payload = $this->toArray();
        if ($payload === []) {
            return '';
        }
        $json = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );
        if ($json === false) {
            return '';
        }

        return '<script type="application/ld+json">' . $json . '</script>';
    }

    /** @return list<array<string, mixed>> */
    public function graphNodes(): array
    {
        return $this->graph;
    }
}
