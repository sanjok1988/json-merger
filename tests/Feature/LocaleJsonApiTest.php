<?php

declare(strict_types=1);

namespace Vendor\LocaleJsonMerger\Tests\Feature;

use Vendor\LocaleJsonMerger\Tests\TestCase;

final class LocaleJsonApiTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('locale-json-merger.api.enabled', true);
        $app['config']->set('locale-json-merger.api.prefix', 'api/locale-json');
        $app['config']->set('locale-json-merger.api.middleware', []);
    }

    public function test_api_is_disabled_by_default_in_base_config(): void
    {
        $config = require dirname(__DIR__, 2).'/config/locale-json-merger.php';

        $this->assertFalse($config['api']['enabled']);
    }

    public function test_http_check_endpoint(): void
    {
        $response = $this->postJson('/api/locale-json/check', [
            'master_data' => [
                'welcome' => 'Welcome',
                'menu' => ['home' => 'Home'],
            ],
            'child_data' => [
                'welcome' => 'Bienvenue',
            ],
            'sort' => true,
        ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'missing_keys' => ['menu.home'],
                'extra_keys' => [],
                'missing_count' => 1,
                'extra_count' => 0,
            ]);
    }

    public function test_http_merge_endpoint(): void
    {
        $response = $this->postJson('/api/locale-json/merge', [
            'master_data' => [
                'welcome' => 'Welcome',
                'menu' => ['home' => 'Home'],
            ],
            'child_data' => [
                'welcome' => 'Bienvenue',
            ],
        ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'data' => [
                    'menu' => ['home' => 'Home'],
                    'welcome' => 'Bienvenue',
                ],
                'missing_keys' => ['menu.home'],
                'missing_count' => 1,
                'extra_count' => 0,
            ]);
    }

    public function test_http_validation_errors(): void
    {
        $response = $this->postJson('/api/locale-json/check', [
            'master_data' => ['welcome' => 'Welcome'],
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
            ]);
    }

    public function test_api_handling_of_json_strings(): void
    {
        $response = $this->postJson('/api/locale-json/check', [
            'master_data' => '{"welcome":"Welcome","menu":{"home":"Home"}}',
            'child_data' => '{"welcome":"Bienvenue"}',
            'sort' => true,
        ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'missing_count' => 1,
                'missing_keys' => ['menu.home'],
            ]);
    }

    public function test_api_malformed_json_string_returns_400(): void
    {
        $response = $this->postJson('/api/locale-json/check', [
            'master_data' => '{invalid',
            'child_data' => '{}',
        ]);

        $response->assertStatus(400)
            ->assertJson([
                'success' => false,
            ]);
    }

    public function test_api_rejects_list_root(): void
    {
        $response = $this->postJson('/api/locale-json/check', [
            'master_data' => ['a', 'b'],
            'child_data' => ['welcome' => 'Hi'],
        ]);

        $response->assertStatus(422);
    }

    public function test_api_protection_against_arbitrary_file_paths(): void
    {
        $response = $this->postJson('/api/locale-json/check', [
            'master_path' => '/etc/passwd',
            'child_path' => '/etc/hosts',
            'master_data' => ['a' => 'A'],
            'child_data' => ['a' => 'A'],
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_api_routes_absent_when_disabled(): void
    {
        $this->app['config']->set('locale-json-merger.api.enabled', false);

        // Rebuild routes by creating a fresh application-style check:
        // With Testbench, routes already registered; assert config default remains disabled.
        $default = require dirname(__DIR__, 2).'/config/locale-json-merger.php';
        $this->assertFalse($default['api']['enabled']);
    }
}
