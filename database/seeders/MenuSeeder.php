<?php

namespace Database\Seeders;

use App\Models\Menu;
use Illuminate\Database\Seeder;
use Faker\Factory as Faker;

class MenuSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $faker = Faker::create('id_ID');
        
        // Kategori menu
        $categories = [
            'Makanan Utama',
            'Makanan Ringan',
            'Minuman Dingin',
            'Minuman Panas',
            'Dessert',
            'Makanan Pembuka',
            'Menu Spesial',
            'Menu Diet',
            'Menu Vegetarian',
            'Menu Anak'
        ];
        
        // Nama-nama makanan dan minuman Indonesia
        $foodNames = [
            'Nasi Goreng', 'Mie Goreng', 'Sate Ayam', 'Gado-Gado', 'Rendang',
            'Soto Ayam', 'Bakso', 'Rawon', 'Pecel Lele', 'Ayam Goreng',
            'Ayam Bakar', 'Ikan Bakar', 'Capcay', 'Bihun Goreng', 'Kwetiau Goreng',
            'Siomay', 'Batagor', 'Pempek', 'Ketoprak', 'Bubur Ayam',
            'Lontong Sayur', 'Nasi Uduk', 'Nasi Kuning', 'Nasi Liwet', 'Gudeg',
            'Opor Ayam', 'Sayur Asem', 'Sayur Lodeh', 'Tumis Kangkung', 'Cah Tauge',
            'Martabak Manis', 'Martabak Telur', 'Pisang Goreng', 'Tahu Goreng', 'Tempe Goreng',
            'Kerupuk', 'Rempeyek', 'Kue Cubit', 'Kue Pancong', 'Serabi',
            'Es Teh', 'Es Jeruk', 'Es Kelapa Muda', 'Es Cincau', 'Es Campur',
            'Jus Alpukat', 'Jus Mangga', 'Jus Jambu', 'Jus Sirsak', 'Jus Melon',
            'Kopi Tubruk', 'Kopi Susu', 'Teh Tawar', 'Teh Manis', 'Wedang Jahe',
            'Wedang Uwuh', 'Bandrek', 'Bajigur', 'STMJ', 'Sekoteng'
        ];
        
        // Generate 1000 menu items
        for ($i = 0; $i < 1000; $i++) {
            $name = $faker->randomElement($foodNames) . ' ' . $faker->word;
            $category = $faker->randomElement($categories);
            $price = $faker->numberBetween(10000, 150000);
            $orderCount = $faker->numberBetween(0, 500);
            
            Menu::create([
                'name' => $name,
                'description' => $faker->paragraph(2),
                'category' => $category,
                'price' => $price,
                'order_count' => $orderCount,
                'created_at' => $faker->dateTimeBetween('-1 year', 'now'),
                'updated_at' => $faker->dateTimeBetween('-1 year', 'now')
            ]);
        }
    }
}