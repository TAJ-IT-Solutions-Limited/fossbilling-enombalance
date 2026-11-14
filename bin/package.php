<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$manifest = json_decode((string) file_get_contents($root . '/src/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
$version = (string) ($manifest['version'] ?? '');
if (preg_match('/^\d+\.\d+\.\d+$/', $version) !== 1) {
    fwrite(STDERR, "manifest version is not a release number\n");
    exit(1);
}

$src = $root . '/src';
$files = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS)
);
foreach ($iterator as $file) {
    if (!$file->isFile()) {
        continue;
    }
    $name = substr($file->getPathname(), strlen($src) + 1);
    $name = str_replace('\\', '/', $name);
    if (str_starts_with($name, 'preview/') || str_starts_with($name, 'tests/') || $name === 'preview' || $name === 'tests') {
        continue;
    }
    $files[$name] = package_lf((string) file_get_contents($file->getPathname()));
}

$outDir = $root . '/dist';
if (!is_dir($outDir) && !mkdir($outDir) && !is_dir($outDir)) {
    fwrite(STDERR, "cannot create dist\n");
    exit(1);
}
$tarPath = $outDir . '/enombalance-' . $version . '.tar';
if (is_file($tarPath)) {
    unlink($tarPath);
}
package_tar($tarPath, $files);
package_assert($tarPath, $files);

echo $tarPath . PHP_EOL;
echo 'sha256 ' . hash_file('sha256', $tarPath) . PHP_EOL;
foreach (array_keys($files) as $name) {
    echo $name . PHP_EOL;
}

function package_lf(string $bytes): string
{
    $text = str_replace("\r\n", "\n", $bytes);
    $text = str_replace("\r", "\n", $text);
    if (str_contains($text, "\r")) {
        throw new RuntimeException('a carriage return survived normalisation');
    }

    return $text;
}

function package_assert(string $tarPath, array $files): void
{
    $size = filesize($tarPath);
    if ($size === false || $size >= 5 * 1024 * 1024) {
        fwrite(STDERR, "package is missing or is 5 MiB or larger\n");
        exit(1);
    }
    foreach (['manifest.json', 'Service.php', 'EnomBalanceSource.php', 'Api/Admin.php', 'Controller/Admin.php'] as $name) {
        if (!isset($files[$name])) {
            fwrite(STDERR, "package is missing $name\n");
            exit(1);
        }
    }
    if (!str_contains($files['manifest.json'], '"id": "enombalance"')) {
        fwrite(STDERR, "manifest id is not enombalance\n");
        exit(1);
    }
    foreach ($files as $name => $data) {
        if (
            str_contains($name, '\\')
            || str_starts_with($name, 'src/')
            || str_starts_with($name, 'preview/')
            || str_starts_with($name, 'tests/')
            || str_starts_with($name, 'Enombalance/')
            || str_contains($data, "\r")
        ) {
            fwrite(STDERR, "bad entry $name\n");
            exit(1);
        }
    }
    if (!isset($files['manifest.json'])) {
        fwrite(STDERR, "package is wrapped\n");
        exit(1);
    }
}

function package_tar(string $path, array $files): void
{
    ksort($files);
    $out = '';
    $dirs = [];
    foreach (array_keys($files) as $name) {
        $parts = explode('/', $name);
        array_pop($parts);
        $prefix = '';
        foreach ($parts as $part) {
            $prefix .= $part . '/';
            $dirs[$prefix] = true;
        }
    }
    $names = array_keys($dirs);
    sort($names);
    foreach ($names as $dir) {
        $out .= package_header($dir, '', '5');
    }
    foreach ($files as $name => $data) {
        $out .= package_header($name, $data, '0') . $data;
        $pad = (512 - (strlen($data) % 512)) % 512;
        $out .= str_repeat("\0", $pad);
    }
    $out .= str_repeat("\0", 1024);
    if (file_put_contents($path, $out) === false) {
        fwrite(STDERR, "cannot write $path\n");
        exit(1);
    }
}

function package_header(string $name, string $data, string $type): string
{
    $header = str_repeat("\0", 512);
    package_put($header, 0, $name);
    package_put($header, 100, sprintf('%07o', 0644) . "\0");
    package_put($header, 108, sprintf('%07o', 0) . "\0");
    package_put($header, 116, sprintf('%07o', 0) . "\0");
    package_put($header, 124, sprintf('%011o', strlen($data)) . "\0");
    package_put($header, 136, sprintf('%011o', 1763131200) . "\0");
    package_put($header, 148, "        ");
    $header[156] = $type;
    package_put($header, 257, "ustar\0");
    package_put($header, 263, '00');
    $sum = 0;
    for ($i = 0; $i < 512; $i++) {
        $sum += ord($header[$i]);
    }
    package_put($header, 148, sprintf('%06o', $sum) . "\0 ");

    return $header;
}

function package_put(string &$header, int $offset, string $value): void
{
    $length = strlen($value);
    for ($i = 0; $i < $length; $i++) {
        $header[$offset + $i] = $value[$i];
    }
}
