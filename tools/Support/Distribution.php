<?php

declare(strict_types=1);

namespace GogoSpace\BulkCache\Tools;

use ZipArchive;

final class Distribution
{
    public static function create(string $rootDirectory): array
    {
        $outputDirectory = Verification::directory(Verification::evidenceDirectory($rootDirectory).'/distribution');
        $commit = trim(Verification::run(['git', 'rev-parse', '--verify', 'HEAD'], $rootDirectory, $outputDirectory.'/head.log'));
        $changes = Verification::run(['git', 'status', '--porcelain', '--untracked-files=normal'], $rootDirectory, $outputDirectory.'/status.log');
        Verification::require(trim($changes) === '', 'Commit the verified source before testing its distribution. The checkout is not clean.');
        $archivePath = $outputDirectory.'/laravel-bulk-cache-'.$commit.'.zip';
        Verification::run(['git', 'archive', '--format=zip', '--output='.$archivePath, 'HEAD'], $rootDirectory, $outputDirectory.'/archive.log');

        return self::inspect($rootDirectory, $outputDirectory, $commit, $archivePath);
    }

    public static function inspect(string $rootDirectory, string $outputDirectory, string $commit, string $archivePath, string $version = 'dev-main', bool $compareCheckout = true): array
    {
        $archive = new ZipArchive;
        Verification::require($archive->open($archivePath) === true, 'Cannot read the distribution ZIP.');
        $manifest = json_decode((string) $archive->getFromName('composer.json'), true, flags: JSON_THROW_ON_ERROR);
        Verification::require(($manifest['license'] ?? null) === 'MIT', 'Distribution must declare MIT.');
        Verification::require(str_contains((string) $archive->getFromName('LICENSE'), 'Permission is hereby granted'), 'MIT license text is missing.');
        foreach (['composer.json', 'README.md', 'LICENSE', 'config/bulk-cache.php', 'src/BulkCacheManager.php', 'src/BulkCacheServiceProvider.php'] as $requiredPath) {
            Verification::require($archive->locateName($requiredPath) !== false, 'Missing archive file: '.$requiredPath);
        }
        $fileCount = 0;
        for ($position = 0; $position < $archive->numFiles; $position++) {
            $path = (string) $archive->getNameIndex($position);
            Verification::require(! preg_match('~^(research/|vendor/|tests/|tools/|\.git/|\.github/|AGENTS\.md$|composer\.lock$|\.env(?:$|\.)|phpunit\.xml$|phpstan\.neon$)~', $path), 'Private/development file in distribution: '.$path);
            Verification::require(! str_ends_with($path, '.log'), 'Log file in distribution: '.$path);
            Verification::require(! str_contains($path, '..') && ! str_starts_with($path, '/'), 'Unsafe archive path: '.$path);
            $archive->getExternalAttributesIndex($position, $operatingSystem, $attributes);
            Verification::require(($attributes >> 16 & 0170000) !== 0120000, 'Symlink in distribution: '.$path);
            if (! str_ends_with($path, '/')) {
                Verification::require(! $compareCheckout || hash('sha256', (string) $archive->getFromIndex($position)) === hash_file('sha256', $rootDirectory.'/'.$path), 'Archive differs from the checked-out committed file: '.$path);
                $fileCount++;
            }
        }
        $archive->close();
        $package = $manifest;
        unset($package['require-dev'], $package['autoload-dev'], $package['scripts'], $package['config'], $package['archive']);
        $package['version'] = $version;
        $package['dist'] = ['type' => 'zip', 'url' => 'file://'.$archivePath, 'reference' => $commit, 'shasum' => sha1_file($archivePath)];
        Verification::writeJson($outputDirectory.'/packages.json', ['packages' => [$package['name'] => [$version => $package]]]);

        return ['version' => $version, 'commit' => $commit, 'archive' => $archivePath, 'sha256' => hash_file('sha256', $archivePath), 'file_count' => $fileCount, 'repository_url' => 'file://'.$outputDirectory, 'repository' => ['type' => 'package', 'package' => $package]];
    }

    public static function install(array $distribution, string $consumerDirectory): void
    {
        Verification::run(['composer', 'config', 'repositories.bulk-cache', 'composer', $distribution['repository_url']], $consumerDirectory, $consumerDirectory.'/package-repository.log');
        Verification::run(['composer', 'require', 'gogospace/laravel-bulk-cache:'.$distribution['version'], '--with-dependencies', '--update-no-dev', '--prefer-dist', '--no-interaction', '--optimize-autoloader'], $consumerDirectory, $consumerDirectory.'/package-install.log');
        self::verifyInstallation($distribution, $consumerDirectory);
    }

    public static function verifyInstallation(array $distribution, string $consumerDirectory): void
    {
        $installedDirectory = $consumerDirectory.'/vendor/gogospace/laravel-bulk-cache';
        Verification::require(is_dir($installedDirectory) && ! is_link($installedDirectory), 'The installed package must be an extracted distribution, never a symlink.');
        Verification::require(! is_dir($consumerDirectory.'/vendor/orchestra') && ! is_dir($consumerDirectory.'/vendor/phpunit') && ! is_dir($consumerDirectory.'/vendor/predis'), 'Consumer unexpectedly installed package development or optional dependencies.');
        $installedPackages = Verification::json($consumerDirectory.'/vendor/composer/installed.json');
        Verification::require(($installedPackages['dev'] ?? true) === false, 'Consumer must install with development mode disabled.');
        $installedPackage = array_values(array_filter($installedPackages['packages'], static fn (array $package): bool => $package['name'] === 'gogospace/laravel-bulk-cache'));
        Verification::require(count($installedPackage) === 1 && ($installedPackage[0]['dist']['reference'] ?? null) === $distribution['commit'], 'Installed package reference does not match the archive commit.');
        $installedManifest = Verification::json($installedDirectory.'/composer.json');
        Verification::require(($installedManifest['name'] ?? null) === 'gogospace/laravel-bulk-cache', 'The intended package was not installed.');
        $archive = new ZipArchive;
        Verification::require($archive->open($distribution['archive']) === true, 'Cannot inspect installed package archive.');
        for ($position = 0; $position < $archive->numFiles; $position++) {
            $path = (string) $archive->getNameIndex($position);
            if (! str_ends_with($path, '/')) {
                Verification::require(! is_link($installedDirectory.'/'.$path) && is_file($installedDirectory.'/'.$path) && hash_file('sha256', $installedDirectory.'/'.$path) === hash('sha256', (string) $archive->getFromIndex($position)), 'Installed file does not match the committed distribution: '.$path);
            }
        }
        $archive->close();
    }
}
