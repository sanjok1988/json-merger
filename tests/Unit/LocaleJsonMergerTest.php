<?php

declare(strict_types=1);

namespace Vendor\LocaleJsonMerger\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vendor\LocaleJsonMerger\Services\LocaleJsonMerger;

final class LocaleJsonMergerTest extends TestCase
{
    private LocaleJsonMerger $merger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->merger = new LocaleJsonMerger();
    }

    public function test_no_missing_keys(): void
    {
        $master = ['welcome' => 'Welcome'];
        $child = ['welcome' => 'Bienvenue'];

        $result = $this->merger->check($master, $child);

        $this->assertSame([], $result->missingKeys());
        $this->assertSame(0, $result->missingCount());
        $this->assertFalse($result->hasMissingKeys());
    }

    public function test_one_missing_top_level_key(): void
    {
        $master = ['welcome' => 'Welcome', 'goodbye' => 'Goodbye'];
        $child = ['welcome' => 'Bienvenue'];

        $result = $this->merger->merge($master, $child);

        $this->assertSame(['goodbye'], $result->missingKeys());
        $this->assertSame(1, $result->missingCount());
        $this->assertSame('Goodbye', $result->merged()['goodbye']);
        $this->assertSame('Bienvenue', $result->merged()['welcome']);
    }

    public function test_multiple_missing_nested_keys(): void
    {
        $master = [
            'auth' => [
                'login' => [
                    'title' => 'Login',
                    'email' => 'Email',
                    'password' => 'Password',
                ],
            ],
        ];
        $child = [
            'auth' => [
                'login' => [
                    'title' => 'Connexion',
                ],
            ],
        ];

        $result = $this->merger->merge($master, $child);

        $this->assertSame(['auth.login.email', 'auth.login.password'], $result->missingKeys());
        $this->assertSame('Connexion', $result->merged()['auth']['login']['title']);
        $this->assertSame('Email', $result->merged()['auth']['login']['email']);
        $this->assertSame('Password', $result->merged()['auth']['login']['password']);
    }

    public function test_extra_child_keys(): void
    {
        $master = [
            'welcome' => 'Welcome',
            'menu' => [
                'home' => 'Home',
                'settings' => 'Settings',
            ],
        ];
        $child = [
            'welcome' => 'Bienvenue',
            'menu' => [
                'home' => 'Accueil',
            ],
            'legacy' => [
                'title' => 'Old title',
            ],
        ];

        $result = $this->merger->merge($master, $child);

        $this->assertSame(['menu.settings'], $result->missingKeys());
        $this->assertSame(['legacy.title'], $result->extraKeys());
        $this->assertSame(1, $result->extraCount());
        $this->assertSame('Bienvenue', $result->merged()['welcome']);
        $this->assertSame('Accueil', $result->merged()['menu']['home']);
        $this->assertSame('Settings', $result->merged()['menu']['settings']);
        $this->assertSame('Old title', $result->merged()['legacy']['title']);
    }

    public function test_existing_translations_are_preserved(): void
    {
        $master = ['a' => 'A', 'b' => 'B'];
        $child = ['a' => 'Alpha', 'b' => 'Beta'];

        $result = $this->merger->merge($master, $child);

        $this->assertSame(['a' => 'Alpha', 'b' => 'Beta'], $result->merged());
    }

    public function test_existing_empty_strings_are_preserved(): void
    {
        $master = ['label' => 'Label'];
        $child = ['label' => ''];

        $result = $this->merger->merge($master, $child);

        $this->assertSame('', $result->merged()['label']);
    }

    public function test_existing_zero_values_are_preserved(): void
    {
        $master = ['count' => 1];
        $child = ['count' => 0];

        $result = $this->merger->merge($master, $child);

        $this->assertSame(0, $result->merged()['count']);
    }

    public function test_existing_false_values_are_preserved(): void
    {
        $master = ['enabled' => true];
        $child = ['enabled' => false];

        $result = $this->merger->merge($master, $child);

        $this->assertFalse($result->merged()['enabled']);
    }

    public function test_master_object_and_child_scalar_conflict(): void
    {
        $master = [
            'menu' => [
                'home' => 'Home',
                'settings' => 'Settings',
            ],
        ];
        $child = [
            'menu' => 'Menu',
        ];

        $result = $this->merger->merge($master, $child);

        $this->assertNotEmpty($result->typeConflicts());
        $this->assertSame('menu', $result->typeConflicts()[0]['path']);
        $this->assertSame('object', $result->typeConflicts()[0]['master_type']);
        $this->assertSame('string', $result->typeConflicts()[0]['child_type']);
        $this->assertContains('menu', $result->extraKeys());
        $this->assertContains('menu.home', $result->missingKeys());
        $this->assertContains('menu.settings', $result->missingKeys());
        $this->assertIsArray($result->merged()['menu']);
        $this->assertSame('Home', $result->merged()['menu']['home']);
    }

    public function test_master_scalar_and_child_object_conflict(): void
    {
        $master = [
            'menu' => 'Menu',
        ];
        $child = [
            'menu' => [
                'home' => 'Accueil',
            ],
        ];

        $result = $this->merger->merge($master, $child);

        $this->assertNotEmpty($result->typeConflicts());
        $this->assertSame('menu', $result->typeConflicts()[0]['path']);
        $this->assertSame('string', $result->typeConflicts()[0]['master_type']);
        $this->assertSame('object', $result->typeConflicts()[0]['child_type']);
        $this->assertContains('menu', $result->extraKeys());
        $this->assertSame(['home' => 'Accueil'], $result->merged()['menu']);
        $this->assertSame([], $result->missingKeys());
    }

    public function test_null_values(): void
    {
        $master = ['note' => null, 'title' => 'Title'];
        $child = ['note' => null];

        $result = $this->merger->merge($master, $child);

        $this->assertNull($result->merged()['note']);
        $this->assertSame(['title'], $result->missingKeys());
        $this->assertSame('Title', $result->merged()['title']);
    }

    public function test_empty_objects(): void
    {
        $master = ['meta' => []];
        $child = [];

        $result = $this->merger->merge($master, $child);

        $this->assertSame(['meta'], $result->missingKeys());
        $this->assertSame([], $result->merged()['meta']);
    }

    public function test_empty_child_locale(): void
    {
        $master = [
            'welcome' => 'Welcome',
            'menu' => ['home' => 'Home'],
        ];
        $child = [];

        $result = $this->merger->merge($master, $child);

        $this->assertSame(['menu.home', 'welcome'], $result->missingKeys());
        $this->assertSame('Welcome', $result->merged()['welcome']);
        $this->assertSame(['home' => 'Home'], $result->merged()['menu']);
    }

    public function test_unicode_japanese_vietnamese_turkish_arabic(): void
    {
        $master = [
            'greeting' => 'Hello',
            'farewell' => 'Goodbye',
        ];
        $child = [
            'greeting' => 'こんにちは',
            'vi' => 'Xin chào',
            'tr' => 'Merhaba',
            'ar' => 'مرحبا',
        ];

        $result = $this->merger->merge($master, $child);

        $this->assertSame('こんにちは', $result->merged()['greeting']);
        $this->assertSame('Goodbye', $result->merged()['farewell']);
        $this->assertSame('Xin chào', $result->merged()['vi']);
        $this->assertSame('Merhaba', $result->merged()['tr']);
        $this->assertSame('مرحبا', $result->merged()['ar']);
        $this->assertSame(['farewell'], $result->missingKeys());
    }

    public function test_arrays_remain_in_original_order(): void
    {
        $master = [
            'languages' => ['English', 'French', 'Japanese'],
        ];
        $child = [
            'languages' => ['English', 'French', 'Japanese'],
        ];

        $result = $this->merger->merge($master, $child);

        $this->assertSame(['English', 'French', 'Japanese'], $result->merged()['languages']);
    }

    public function test_missing_arrays_are_copied(): void
    {
        $master = [
            'languages' => ['English', 'French', 'Japanese'],
        ];
        $child = [];

        $result = $this->merger->merge($master, $child);

        $this->assertSame(['languages'], $result->missingKeys());
        $this->assertSame(['English', 'French', 'Japanese'], $result->merged()['languages']);
    }

    public function test_existing_child_arrays_are_preserved(): void
    {
        $master = [
            'languages' => ['English', 'French', 'Japanese'],
        ];
        $child = [
            'languages' => ['Français', '日本語'],
        ];

        $result = $this->merger->merge($master, $child);

        $this->assertSame(['Français', '日本語'], $result->merged()['languages']);
        $this->assertSame([], $result->missingKeys());
    }

    public function test_object_key_sorting(): void
    {
        $master = [
            'zebra' => 'Z',
            'apple' => 'A',
            'menu' => [
                'settings' => 'Settings',
                'home' => 'Home',
            ],
        ];
        $child = [
            'zebra' => 'Zed',
        ];

        $result = $this->merger->merge($master, $child, true);
        $keys = array_keys($result->merged());

        $this->assertSame(['apple', 'menu', 'zebra'], $keys);
        $this->assertSame(['home', 'settings'], array_keys($result->merged()['menu']));
    }

    public function test_sorting_does_not_affect_array_order(): void
    {
        $master = [
            'b' => 'B',
            'a' => ['z', 'y', 'x'],
        ];
        $child = [];

        $result = $this->merger->merge($master, $child, true);

        $this->assertSame(['a', 'b'], array_keys($result->merged()));
        $this->assertSame(['z', 'y', 'x'], $result->merged()['a']);
    }

    public function test_sorting_can_be_disabled(): void
    {
        $master = [
            'zebra' => 'Z',
            'apple' => 'A',
        ];
        $child = [];

        $result = $this->merger->merge($master, $child, false);
        $keys = array_keys($result->merged());

        $this->assertSame(['zebra', 'apple'], $keys);
    }

    public function test_does_not_mutate_input_arrays(): void
    {
        $master = [
            'zebra' => 'Z',
            'apple' => 'A',
        ];
        $child = [
            'legacy' => 'old',
        ];
        $masterSnapshot = $master;
        $childSnapshot = $child;

        $this->merger->merge($master, $child, true);

        $this->assertSame($masterSnapshot, $master);
        $this->assertSame($childSnapshot, $child);
    }

    public function test_result_dto_serialization(): void
    {
        $master = ['a' => 'A', 'b' => 'B'];
        $child = ['a' => 'Alpha', 'c' => 'C'];

        $check = $this->merger->check($master, $child);
        $merge = $this->merger->merge($master, $child);

        $this->assertArrayHasKey('missing_keys', $check->toArray());
        $this->assertArrayHasKey('extra_keys', $check->toArray());
        $this->assertArrayHasKey('missing_count', $check->toArray());
        $this->assertArrayHasKey('extra_count', $check->toArray());
        $this->assertArrayNotHasKey('merged', $check->toArray());

        $this->assertArrayHasKey('merged', $merge->toArray());
        $this->assertSame($merge->missingKeys(), $merge->toArray()['missing_keys']);
        $this->assertSame($merge->extraKeys(), $merge->toArray()['extra_keys']);
        $this->assertSame($merge->missingCount(), $merge->toArray()['missing_count']);
        $this->assertSame($merge->extraCount(), $merge->toArray()['extra_count']);
    }

    public function test_spec_example_merged_output_is_sorted(): void
    {
        $master = [
            'welcome' => 'Welcome',
            'menu' => [
                'home' => 'Home',
                'settings' => 'Settings',
            ],
        ];
        $child = [
            'welcome' => 'Bienvenue',
            'menu' => [
                'home' => 'Accueil',
            ],
            'legacy' => [
                'title' => 'Old title',
            ],
        ];

        $result = $this->merger->merge($master, $child, true);

        $this->assertSame(
            [
                'legacy' => ['title' => 'Old title'],
                'menu' => [
                    'home' => 'Accueil',
                    'settings' => 'Settings',
                ],
                'welcome' => 'Bienvenue',
            ],
            $result->merged(),
        );
    }
}
