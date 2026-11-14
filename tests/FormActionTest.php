<?php

declare(strict_types=1);

namespace Box\Mod\Enombalance;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Renders the settings form the way FOSSBilling 0.8.9 does.
 * api_url calls url->link("api/{role}/{action}"), which does not add the admin prefix.
 * The url filter in the admin area calls adminLink(), which does.
 */
final class FormActionTest extends TestCase
{
    public function testSettingsFormPostsToTheApiPath(): void
    {
        $layout = '{% block content %}{% endblock %}';
        $loader = new ChainLoader([
            new ArrayLoader([
                'layout_default.html.twig' => $layout,
                'layout_blank.html.twig' => $layout,
            ]),
            new FilesystemLoader(dirname(__DIR__) . '/src/templates/admin'),
        ]);
        $twig = new Environment($loader);
        $twig->addGlobal('request', ['ajax' => false]);
        $twig->addGlobal('admin', new class {
            public function enombalance_settings(): array
            {
                return ['low_balance_threshold' => '70'];
            }
        });
        $twig->addFilter(new TwigFilter('url', static function (string $path): string {
            return '/admin/' . trim($path, '/');
        }, ['is_safe' => ['html']]));
        $twig->addFilter(new TwigFilter('api_url', static function (string $action, ?string $role = null): string {
            $role = in_array($role, ['admin', 'client', 'guest'], true) ? $role : 'guest';

            return '/api/' . $role . '/' . trim($action, '/');
        }, ['is_safe' => ['html']]));
        $twig->addFilter(new TwigFilter('trans', static fn (string $text): string => $text));
        $twig->addFunction(new TwigFunction('fb_api_form', static function (array $config = []): string {
            return 'method="post" data-fb-api="' . htmlspecialchars((string) json_encode($config), ENT_QUOTES) . '"';
        }, ['is_safe' => ['html']]));

        $html = $twig->render('mod_enombalance_settings.html.twig');

        self::assertStringContainsString('action="/api/admin/enombalance/update_settings"', $html);
        self::assertStringNotContainsString('/admin/api/admin/', $html);
        self::assertStringContainsString('value="70"', $html);
    }
}
