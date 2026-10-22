<?php

namespace Database\Seeders;

use App\Models\Unit;
use Illuminate\Database\Seeder;

class UnitSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([['name' => 'TK', 'code' => 'TK'], ['name' => 'SD', 'code' => 'SD']] as $unit) {
            Unit::updateOrCreate(['code' => $unit['code']], ['name' => $unit['name']]);
        }
    }
}
