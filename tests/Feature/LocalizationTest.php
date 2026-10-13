<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Rus və ingilis dili: dil seçimi, açarların tamlığı və səhifələrdə tərcüməsiz Azərbaycanca mətn qalmaması.
 */
class LocalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_translation_key_has_russian_and_english_text(): void
    {
        $keys = [];
        $files = array_merge(File::allFiles(app_path()), File::allFiles(resource_path('views')));
        foreach ($files as $file) {
            if (str_contains($file->getFilename(), 'DemoWorkbooks')) {
                continue;
            }
            preg_match_all("/__\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/u", $file->getContents(), $matches);
            foreach ($matches[1] as $key) {
                $keys[str_replace(["\\'", '\\\\'], ["'", '\\'], $key)] = true;
            }
        }
        $this->assertGreaterThan(500, count($keys));

        foreach (['ru', 'en'] as $locale) {
            $translations = json_decode(File::get(lang_path("{$locale}.json")), true);
            $missing = array_values(array_filter(array_keys($keys), fn ($k) => empty($translations[$k])));
            $this->assertSame([], $missing, "{$locale}.json-da çatışan açarlar");
        }
    }

    public function test_locale_switch_persists_and_rejects_unknown(): void
    {
        $this->get(route('locale', 'ru'))->assertRedirect();
        $this->get(route('login'))->assertOk()->assertSee('Вход в систему')->assertSee('lang="ru"', false);

        $this->get(route('locale', 'en'))->assertRedirect();
        $this->get(route('login'))->assertOk()->assertSee('Sign in')->assertSee('Remember me');

        $this->get(route('locale', 'az'))->assertRedirect();
        $this->get(route('login'))->assertOk()->assertSee('Sistemə giriş');

        $this->get('/dil/de')->assertNotFound();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function locales(): array
    {
        return ['ru' => ['ru'], 'en' => ['en']];
    }

    #[DataProvider('locales')]
    public function test_pages_have_no_untranslated_azerbaijani_text(string $locale): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get(route('locale', $locale));

        $routes = ['dashboard', 'orders.index', 'sales.index', 'margin.index', 'returns.index', 'customers.index', 'debts.index', 'payments.index',
            'stock.index', 'movements.index', 'purchases.index', 'expenses.index', 'pnl.index', 'cash.index', 'quality.index', 'import.index',
            'aliases.index', 'categories.index', 'users.index', 'accounting.balance', 'accounting.pl', 'accounting.cashflow'];
        $leftovers = [];
        foreach ($routes as $route) {
            $html = $this->get(route($route))->assertOk()->getContent();
            $text = preg_replace(['#<script\b[^>]*>.*?</script>#su', '#<style\b[^>]*>.*?</style>#su', '#<[^>]+>#u'], ' ', $html);
            $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            // açıq-aydın data olmayan, yalnız Azərbaycan əlifbasına aid hərflər
            // məlumat (anbar adları) və hərf çevirmə qaydası (ə→e) tərcümə olunmur
            $data = DB::table('warehouses')->pluck('name')->implode(' ');
            if (preg_match_all('/[^\s]*[əƏğĞıİşŞ][^\s]*/u', $text, $m)) {
                foreach (array_unique($m[0]) as $word) {
                    if (str_contains($word, '→') || str_contains($data, $word)) {
                        continue;
                    }
                    $leftovers[] = "{$route}: {$word}";
                }
            }
        }
        $this->assertSame([], $leftovers, "{$locale}: tərcümə olunmamış mətn");
    }
}
