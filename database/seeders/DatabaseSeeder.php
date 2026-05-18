<?php

namespace Database\Seeders;

use App\Models\Restaurant;
use App\Models\RestaurantPayment;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Artisan;
use Nwidart\Modules\Facades\Module;

class DatabaseSeeder extends Seeder
{

    /**
     * Seed the application's database.
     */
    public function run(): void
    {

        $this->call(CountrySeeder::class);
        $this->call(GlobalCurrencySeeder::class);
        $this->call(GlobalSettingSeeder::class);
        $this->call(ModuleSeeder::class);
        $this->call(PackageSeeder::class);
        $this->call(SuperadminSeeder::class);

        $this->call(PermissionSeeder::class);
        $this->call(LanguageSettingSeeder::class);

        // BranchObserver syncs branches to purchase_locations (Inventory). Main migrate does
        // not run module migrations, so seeding must create Inventory tables before any Branch.
        $this->ensureInventoryModuleMigratedForSeeding();

        $this->call(RestaurantSettingSeeder::class);
        $this->call(FrontDetailSeeder::class);
        $this->call(EmailSettingSeeder::class);
        $this->call(SuperadminPaymentGatewaySeeder::class);
        $this->call(PusherSettinSeeder::class);


        $restaurants = Restaurant::with('branches')->get();

        foreach ($restaurants as $restaurant) {
            $this->command->info('Seeding restaurant: ' . ($restaurant->id));

            $branch = $restaurant->branches->first();

            $this->call(PaymentSettingSeeder::class, false, ['restaurant' => $restaurant]);
            $this->call(TaxSeeder::class, false, ['restaurant' => $restaurant]);
            $this->call(RoleSeeder::class, false, ['restaurant' => $restaurant]);
            $this->call(UserSeeder::class, false, ['branch' => $branch]);
            $this->call(ReservationSettingsSeeder::class, false, ['branch' => $branch]);
            $this->call(KotCancelReasonSeeder::class, false, ['restaurant' => $restaurant]);

            if (!App::environment('codecanyon')) {
                $this->call(AreaSeeder::class, false, ['branch' => $branch]);
                $this->call(TableSeeder::class, false, ['branch' => $branch]);
                $this->call(DeliveryExecutiveSeeder::class, false, ['branch' => $branch]);
                $this->call(MenuItemSeeder::class, false, ['branch' => $branch]);
                $this->call(OrderSeeder::class, false, ['branch' => $branch]);

                $restaurant->license_type = 'paid';
                $restaurant->save();

                RestaurantPayment::create([
                    'restaurant_id' => $restaurant->id,
                    'payment_date_time' => now()->toDateTimeString(),
                    'package_id' => package()->id,
                    'amount' => 99,
                    'status' => 'paid'
                ]);
            }
        }
    }

    /**
     * Enable and migrate Inventory so purchase_locations exists before RestaurantSettingSeeder
     * creates branches (BranchObserver::updated runs during generateQrCode() saves).
     */
    protected function ensureInventoryModuleMigratedForSeeding(): void
    {
        if (!class_exists(Module::class) || !Module::has('Inventory')) {
            return;
        }

        Artisan::call('module:enable', [
            'module' => 'Inventory',
            '--no-interaction' => true,
        ]);
        Artisan::call('module:migrate', [
            'module' => 'Inventory',
            '--force' => true,
            '--no-interaction' => true,
        ]);
    }
}
