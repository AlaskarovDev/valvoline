<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Rollar və icazələr: hər rol yalnız icazəli bölmələrə girir, menyu da buna uyğundur, admin cədvəli dəyişə bilir.
 */
class RolePermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_role_reaches_only_its_areas(): void
    {
        $storekeeper = User::factory()->create(['role' => 'anbardar']);
        $this->actingAs($storekeeper)->get(route('stock.index'))->assertOk();
        $this->get(route('write-offs.index'))->assertOk()->assertSee('Akt yarat');   // stock.manage
        $this->get(route('finance.documents'))->assertForbidden();
        $this->get(route('users.index'))->assertForbidden();
        $this->get(route('orders.create'))->assertForbidden();
        $this->get(route('dashboard'))->assertOk()->assertSee('Anbardar')->assertDontSee(route('finance.documents'), false)->assertSee(route('write-offs.index'), false);

        $cashier = User::factory()->create(['role' => 'kassir']);
        $this->actingAs($cashier)->get(route('finance.documents.create', ['type' => 'cash_receipt']))->assertOk();
        $this->get(route('finance.banks'))->assertForbidden();   // finance.manage
        $this->get(route('stock.index'))->assertForbidden();

        // mühasib bütün müştərilərin məlumatını görür (menecer kimi məhdudlaşmır)
        $accountant = User::factory()->create(['role' => 'muhasib']);
        $customer = DB::table('customers')->insertGetId(['code' => 'C1', 'name' => 'Qaraj', 'manager_id' => DB::table('managers')->insertGetId(['name' => 'Rasim', 'created_at' => now(), 'updated_at' => now()]), 'created_at' => now(), 'updated_at' => now()]);
        $this->assertNull($accountant->restrictedManagerId());
        $this->actingAs($accountant)->get(route('customers.show', $customer))->assertOk();
        $this->get(route('accounting.chart'))->assertOk();
        $this->get(route('period-lock.index'))->assertOk();
        $this->get(route('roles.index'))->assertForbidden();

        // menecer əvvəlki kimi: satış var, admin bölmələri yox, başqasının müştərisi yox
        $rep = User::factory()->create(['role' => 'menecer', 'manager_id' => DB::table('managers')->insertGetId(['name' => 'Əkbər', 'created_at' => now(), 'updated_at' => now()])]);
        $this->actingAs($rep)->get(route('orders.create'))->assertOk();
        $this->get(route('customers.show', $customer))->assertForbidden();
        $this->get(route('prices.index'))->assertForbidden();
    }

    public function test_admin_changes_the_matrix_and_users_get_new_roles(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $storekeeper = User::factory()->create(['role' => 'anbardar']);
        $this->actingAs($admin)->get(route('roles.index'))->assertOk()->assertSee('Rollar və icazələr')->assertSee('Anbardar');

        // anbardara kassa icazəsi verilir, silinmə aktı icazəsi götürülür
        $this->put(route('roles.update'), ['perm' => ['anbardar' => ['products', 'stock', 'finance'], 'menecer' => ['sales', 'customers']]])->assertSessionHas('popup');
        $this->actingAs($storekeeper)->get(route('finance.documents'))->assertOk();
        $this->post(route('write-offs.store'), ['date' => now()->toDateString(), 'warehouse_id' => Warehouse::query()->value('id'), 'reason' => 'damaged', 'lines' => []])->assertForbidden();

        $this->actingAs($admin)->post(route('users.store'), ['name' => 'Kassir Leyla', 'email' => 'leyla@valvo.az', 'password' => 'Parol-12345', 'role' => 'kassir'])->assertSessionHasNoErrors();
        $this->assertSame('kassir', User::query()->where('email', 'leyla@valvo.az')->value('role'));
        $this->post(route('users.store'), ['name' => 'X', 'email' => 'x@valvo.az', 'password' => 'Parol-12345', 'role' => 'superuser'])->assertSessionHasErrors('role');
    }
}
