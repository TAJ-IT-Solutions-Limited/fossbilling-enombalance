<?php

declare(strict_types=1);

/**
 * Fail when a module template uses a Twig filter or function that
 * FOSSBilling 0.8.9 does not register. Names come from TwigFactory and the
 * AsTwigFilter / AsTwigFunction attributes in that release, plus Twig core.
 */

$filters = [
    'abs', 'api_url', 'asset_url', 'batch', 'capitalize', 'column', 'convert_encoding',
    'country_name', 'currency_name', 'currency_symbol', 'data_uri', 'date', 'date_modify',
    'daysleft', 'default', 'e', 'escape', 'file_size', 'filter', 'first', 'format',
    'format_currency', 'format_date', 'format_datetime', 'format_number', 'format_time',
    'hash', 'html_to_markdown', 'ip_country_code', 'ip_country_name', 'join', 'json_encode',
    'keys', 'language_name', 'last', 'length', 'locale_name', 'lower', 'map',
    'markdown_to_html', 'merge', 'mod_asset_url', 'nl2br', 'number_format', 'period_title',
    'public_asset_url', 'raw', 'reduce', 'replace', 'reverse', 'round', 'script_tag',
    'slice', 'sort', 'spaceless', 'split', 'striptags', 'stylesheet_tag', 'timeago',
    'timezone_name', 'title', 'trans', 'trim', 'truncate', 'upper', 'url', 'url_encode',
];
$functions = [
    'antispam_honeypot', 'attribute', 'avatar', 'block', 'constant', 'cycle', 'date',
    'debug_bar_render', 'debug_bar_render_head', 'dump', 'fb_api', 'fb_api_form',
    'fb_api_link', 'has_permission', 'html_classes', 'include', 'max', 'min', 'parent',
    'random', 'range', 'render_widgets', 'source', 'svg_sprite', 'template_from_string',
    'wysiwyg',
];

$sampleUnknown = twig_unknown("{{ 'api/admin/enombalance/update_settings'|link }}", $filters, $functions);
if ($sampleUnknown !== ['link']) {
    fwrite(STDERR, "the checker did not reject |link\n");
    exit(1);
}
$sampleKnown = twig_unknown("{{ 'extension/settings/enombalance'|url }} {{ card.title }}", $filters, $functions);
if ($sampleKnown !== []) {
    fwrite(STDERR, "the checker rejected a 0.8.9 filter\n");
    exit(1);
}

$root = dirname(__DIR__);
$failures = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS)
);
foreach ($iterator as $file) {
    if (!$file->isFile() || !str_ends_with($file->getFilename(), '.twig')) {
        continue;
    }
    $relative = substr($file->getPathname(), strlen($root) + 1);
    $relative = str_replace('\\', '/', $relative);
    $source = (string) file_get_contents($file->getPathname());
    foreach (twig_unknown($source, $filters, $functions) as $name) {
        $failures[] = $relative . ' uses ' . $name;
    }
    if (preg_match("/['\"][^'\"]*api\\/[^'\"]*['\"]\\s*\\|\\s*url\\b/", $source) === 1) {
        $failures[] = $relative . ' pipes an api/ path through url';
    }
}

if ($failures !== []) {
    fwrite(STDERR, "Twig names FOSSBilling 0.8.9 does not register:\n" . implode("\n", $failures) . "\n");
    exit(1);
}

echo "twig names match FOSSBilling 0.8.9\n";

/**
 * @param list<string> $filters
 * @param list<string> $functions
 * @return list<string>
 */
function twig_unknown(string $source, array $filters, array $functions): array
{
    $unknown = [];
    if (preg_match_all('/\|([A-Za-z_][A-Za-z0-9_]*)/', $source, $found) === false) {
        return ['unreadable'];
    }
    foreach ($found[1] as $name) {
        if (!in_array($name, $filters, true)) {
            $unknown[$name] = true;
        }
    }
    if (preg_match_all('/\{%[-\s]*apply\s+([A-Za-z_][A-Za-z0-9_]*)/', $source, $applied) > 0) {
        foreach ($applied[1] as $name) {
            if (!in_array($name, $filters, true)) {
                $unknown[$name] = true;
            }
        }
    }
    if (preg_match_all('/(?<![\.\w|])([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $source, $calls) > 0) {
        foreach ($calls[1] as $name) {
            if (!in_array($name, $functions, true)) {
                $unknown[$name] = true;
            }
        }
    }

    $names = array_keys($unknown);
    sort($names);

    return $names;
}
