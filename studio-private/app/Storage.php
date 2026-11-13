<?php
declare(strict_types=1);
namespace Studio;

interface StorageAdapter {
    public function path(string $key): string;
    public function usedBytes(): int;
}

final class LocalStorage implements StorageAdapter {
    public function __construct(private string $root) {}
    public function path(string $key): string {
        if (!preg_match('/\A[a-f0-9]{64}\.mp4\z/D', $key)) { throw new \RuntimeException('Invalid storage key'); }
        return rtrim($this->root, '/\\') . DIRECTORY_SEPARATOR . $key;
    }
    public function root(): string { return $this->root; }
    public function usedBytes(): int {
        if (!is_dir($this->root)) { return 0; }
        $size = 0;
        foreach (new \DirectoryIterator($this->root) as $file) { if ($file->isFile() && !$file->isLink()) { $size += $file->getSize(); } }
        return $size;
    }
}
function storage(): LocalStorage { return new LocalStorage((string)config('storage_path')); }

