<?php

declare(strict_types=1);

namespace GogoSpace\BulkCache\Tools;

use ZipArchive;

final class Distribution
{
    public static function create(string $rootDirectory): array
    {
        $outputDirectory = Verification::directory($rootDirectory.'/research/execution/distribution');
        $commit = trim(Verification::run(['git', 'rev-parse', '--verify', 'HEAD'], $rootDirectory, $outputDirectory.'/head.log'));
        $changes = Verification::run(['git', 'status', '--porcelain', '--untracked-files=normal'], $rootDirectory, $outputDirectory.'/status.log');
        Verification::require(trim($changes) === '', 'Commit the verified source before testing its distribution. The checkout is not clean.');
        $archivePath = $outputDirectory.'/laravel-bulk-cache-'.$commit.'.zip';
        Verification::run(['git', 'archive', '--format=zip', '--output='.$archivePath, 'HEAD'], $rootDirectory, $outputDirectory.'/archive.log');
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
            if (! str_ends_with($path, '/')) {
                Verification::require(hash('sha256', (string) $archive->getFromIndex($position)) === hash_file('sha256', $rootDirectory.'/'.$path), 'Archive differs from the checked-out committed file: '.$path);
                $fileCount++;
            }
        }
        $archive->close();
        $package = $manifest;
        unset($package['require-dev'], $package['autoload-dev'], $package['scripts'], $package['config'], $package['archive']);
        $package['version'] = 'dev-main';
        $package['dist'] = ['type' => 'zip', 'url' => 'file://'.$archivePath, 'reference' => $commit, 'shasum' => sha1_file($archivePath)];
        Verification::writeJson($outputDirectory.'/packages.json', ['packages' => [$package['name'] => ['dev-main' => $package]]]);

        return ['commit' => $commit, 'archive' => $archivePath, 'sha256' => hash_file('sha256', $archivePath), 'file_count' => $fileCount, 'repository_url' => 'file://'.$outputDirectory, 'repository' => ['type' => 'package', 'package' => $package]];
    }

    public static function install(array $distribution, string $consumerDirectory): void
    {
        Verification::run(['composer', 'config', 'repositories.bulk-cache', 'composer', $distribution['repository_url']], $consumerDirectory, $consumerDirectory.'/package-repository.log');
        Verification::run(['composer', 'require', 'gogospace/laravel-bulk-cache:dev-main', '--with-dependencies', '--update-no-dev', '--prefer-dist', '--no-interaction', '--optimize-autoloader'], $consumerDirectory, $consumerDirectory.'/package-install.log');
        $installedDirectory = $consumerDirectory.'/vendor/gogospace/laravel-bulk-cache';
        Verification::require(is_dir($installedDirectory) && ! is_link($installedDirectory), 'The installed package must be an extracted distribution, never a symlink.');
        Verification::require(! is_dir($consumerDirectory.'/vendor/orchestra') && ! is_dir($consumerDirectory.'/vendor/phpunit') && ! is_dir($consumerDirectory.'/vendor/predis'), 'Consumer unexpectedly installed package development or optional dependencies.');
        $installedManifest = Verification::json($installedDirectory.'/composer.json');
        Verification::require(($installedManifest['name'] ?? null) === 'gogospace/laravel-bulk-cache', 'The intended package was not installed.');
        $archive = new ZipArchive;
        Verification::require($archive->open($distribution['archive']) === true, 'Cannot inspect installed package archive.');
        for ($position = 0; $position < $archive->numFiles; $position++) {
            $path = (string) $archive->getNameIndex($position);
            if (! str_ends_with($path, '/')) {
                Verification::require(is_file($installedDirectory.'/'.$path) && hash_file('sha256', $installedDirectory.'/'.$path) === hash('sha256', (string) $archive->getFromIndex($position)), 'Installed file does not match the committed distribution: '.$path);
            }
        }
        $archive->close();
    }
}
