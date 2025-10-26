<?php

declare(strict_types=1);

class AFS_MetadataLoader
{
    private ?string $articleDir;
    private ?string $categoryDir;

    public function __construct(array $paths)
    {
        $this->articleDir = $this->normalizeDir($paths['articles'] ?? null);
        $this->categoryDir = $this->normalizeDir($paths['categories'] ?? null);
    }

    /**
     * @return array<string,array{Meta_Title:?string,Meta_Description:?string}>
     */
    public function loadArticleMetadata(): array
    {
        if ($this->articleDir === null) {
            return [];
        }
        return $this->scanDirectory($this->articleDir);
    }

    /**
     * @return array<string,array{Meta_Title:?string,Meta_Description:?string}>
     */
    public function loadCategoryMetadata(): array
    {
        if ($this->categoryDir === null) {
            return [];
        }
        return $this->scanDirectory($this->categoryDir);
    }

    private function normalizeDir(?string $path): ?string
    {
        if ($path === null) {
            return null;
        }
        $path = rtrim($path, DIRECTORY_SEPARATOR);
        return is_dir($path) ? $path : null;
    }

    /**
     * @return array<string,array{Meta_Title:?string,Meta_Description:?string}>
     */
    private function scanDirectory(string $baseDir): array
    {
        $result = [];
        try {
            $iterator = new DirectoryIterator($baseDir);
        } catch (\Throwable $e) {
            return [];
        }

        foreach ($iterator as $entry) {
            if (!$entry->isDir() || $entry->isDot()) {
                continue;
            }
            $name = $entry->getFilename();
            $path = $entry->getPathname();
            $result[$name] = [
                'Meta_Title' => $this->readMetaFile($path, 'Meta_Title'),
                'Meta_Description' => $this->readMetaFile($path, 'Meta_Description'),
            ];
        }
        return $result;
    }

    private function readMetaFile(string $dir, string $basename): ?string
    {
        $direct = $dir . DIRECTORY_SEPARATOR . $basename;
        if (is_file($direct)) {
            return $this->sanitize(file_get_contents($direct) ?: '');
        }

        $glob = glob($dir . DIRECTORY_SEPARATOR . $basename . '.*');
        if (is_array($glob)) {
            foreach ($glob as $candidate) {
                if (is_file($candidate)) {
                    return $this->sanitize(file_get_contents($candidate) ?: '');
                }
            }
        }

        return null;
    }

    private function sanitize(string $value): ?string
    {
        $trimmed = trim($value);
        return $trimmed === '' ? null : $trimmed;
    }
}
