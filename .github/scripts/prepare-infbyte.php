<?php

declare(strict_types=1);

if ($argc !== 3) {
    fwrite(STDERR, "Usage: prepare-infbyte.php <infbyte-root> <foundation-mcp-root>\n");
    exit(2);
}

$infbyteRoot = realpath($argv[1]);
$foundationMcpRoot = realpath($argv[2]);

if (!is_string($infbyteRoot) || !is_string($foundationMcpRoot)) {
    fwrite(STDERR, "Both repository roots must exist.\n");
    exit(2);
}

$path = $infbyteRoot.DIRECTORY_SEPARATOR.'composer.json';
$data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

if (!is_array($data)) {
    throw new RuntimeException('Infbyte composer.json must decode to an object.');
}

$repository = [
    'type' => 'path',
    'url' => $foundationMcpRoot,
    'options' => [
        'symlink' => false,
        'versions' => ['infocyph/foundation-mcp' => '1.0.0'],
    ],
];

$data['repositories'] = array_values(array_merge([$repository], is_array($data['repositories'] ?? null) ? $data['repositories'] : []));
$data['require-dev'] = is_array($data['require-dev'] ?? null) ? $data['require-dev'] : [];
$data['require-dev']['infocyph/foundation-mcp'] = '^1.0';
ksort($data['require-dev'], SORT_STRING);

file_put_contents(
    $path,
    json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL,
);
