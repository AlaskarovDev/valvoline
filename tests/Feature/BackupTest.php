<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\BackupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class BackupTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = storage_path('framework/testing/backups-'.getmypid());
        $this->app->instance(BackupService::class, new class($this->dir) extends BackupService
        {
            public function __construct(private string $testDir) {}

            public function directory(): string
            {
                return $this->testDir;
            }
        });
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    public function test_admin_creates_and_downloads_a_full_sql_backup(): void
    {
        DB::table('products')->insert(['code' => "O'Neil-1", 'name' => 'Yağ «Ə»', 'created_at' => now(), 'updated_at' => now()]);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get(route('backups.index'))->assertOk()->assertSee('Hələ nüsxə yoxdur');
        $this->post(route('backups.store'))->assertSessionHas('popup');

        $list = app(BackupService::class)->list();
        $this->assertCount(1, $list);
        $sql = gzdecode((string) file_get_contents($this->dir.'/'.$list[0]['name']));
        $this->assertMatchesRegularExpression('/CREATE TABLE [`"]products[`"]/', $sql);
        $this->assertTrue(str_contains($sql, "'O''Neil-1'") || str_contains($sql, "'O\'Neil-1'"), 'dırnaq düzgün qorunmalıdır');   // SQLite: '' · MySQL: \'
        $this->assertStringContainsString('Yağ «Ə»', $sql);
        $this->assertFalse(app(BackupService::class)->isDue());

        $this->get(route('backups.index'))->assertSee($list[0]['name']);
        $this->get(route('backups.download', $list[0]['name']))->assertOk()->assertDownload($list[0]['name']);
        $this->get(route('backups.download', '..%2F.env'))->assertNotFound();

        $this->actingAs(User::factory()->create(['role' => 'muhasib']))->get(route('backups.index'))->assertForbidden();
    }

    public function test_old_backups_are_pruned(): void
    {
        File::ensureDirectoryExists($this->dir);
        foreach (range(1, 16) as $i) {
            touch($this->dir.sprintf('/valvo-202601%02d-030000.sql.gz', $i));
        }
        app(BackupService::class)->prune();
        $names = array_column(app(BackupService::class)->list(), 'name');
        $this->assertCount(BackupService::KEEP, $names);
        $this->assertSame('valvo-20260116-030000.sql.gz', $names[0]);
        $this->assertNotContains('valvo-20260101-030000.sql.gz', $names);
    }
}
