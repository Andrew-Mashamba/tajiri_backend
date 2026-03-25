<?php

namespace Database\Seeders;

use App\Models\Shop\ProductCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ProductCategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['name' => 'Electronics', 'name_sw' => 'Vifaa vya Kielektroniki', 'icon' => 'device-mobile', 'sort_order' => 1, 'children' => [
                ['name' => 'Phones & Tablets', 'name_sw' => 'Simu na Tableti', 'icon' => 'smartphone'],
                ['name' => 'Computers & Laptops', 'name_sw' => 'Kompyuta na Laptop', 'icon' => 'laptop'],
                ['name' => 'TV & Audio', 'name_sw' => 'TV na Sauti', 'icon' => 'tv'],
                ['name' => 'Cameras', 'name_sw' => 'Kamera', 'icon' => 'camera'],
                ['name' => 'Accessories', 'name_sw' => 'Vifaa Vya Ziada', 'icon' => 'headphones'],
            ]],
            ['name' => 'Fashion', 'name_sw' => 'Mitindo', 'icon' => 'shopping-bag', 'sort_order' => 2, 'children' => [
                ['name' => 'Men\'s Clothing', 'name_sw' => 'Nguo za Wanaume', 'icon' => 'shirt'],
                ['name' => 'Women\'s Clothing', 'name_sw' => 'Nguo za Wanawake', 'icon' => 'dress'],
                ['name' => 'Shoes', 'name_sw' => 'Viatu', 'icon' => 'shoe'],
                ['name' => 'Bags & Luggage', 'name_sw' => 'Mabegi', 'icon' => 'briefcase'],
                ['name' => 'Jewelry & Watches', 'name_sw' => 'Mapambo na Saa', 'icon' => 'watch'],
            ]],
            ['name' => 'Home & Garden', 'name_sw' => 'Nyumba na Bustani', 'icon' => 'home', 'sort_order' => 3, 'children' => [
                ['name' => 'Furniture', 'name_sw' => 'Samani', 'icon' => 'sofa'],
                ['name' => 'Kitchen & Dining', 'name_sw' => 'Jikoni', 'icon' => 'utensils'],
                ['name' => 'Home Decor', 'name_sw' => 'Mapambo ya Nyumba', 'icon' => 'lamp'],
                ['name' => 'Garden & Outdoor', 'name_sw' => 'Bustani', 'icon' => 'plant'],
            ]],
            ['name' => 'Vehicles', 'name_sw' => 'Magari', 'icon' => 'truck', 'sort_order' => 4, 'children' => [
                ['name' => 'Cars', 'name_sw' => 'Magari', 'icon' => 'car'],
                ['name' => 'Motorcycles', 'name_sw' => 'Pikipiki', 'icon' => 'motorcycle'],
                ['name' => 'Parts & Accessories', 'name_sw' => 'Vipuri na Vifaa', 'icon' => 'wrench'],
            ]],
            ['name' => 'Services', 'name_sw' => 'Huduma', 'icon' => 'briefcase', 'sort_order' => 5, 'children' => [
                ['name' => 'Tutoring', 'name_sw' => 'Kufundisha', 'icon' => 'book'],
                ['name' => 'Repair & Maintenance', 'name_sw' => 'Ukarabati', 'icon' => 'tool'],
                ['name' => 'Beauty & Wellness', 'name_sw' => 'Urembo', 'icon' => 'sparkles'],
                ['name' => 'Digital Services', 'name_sw' => 'Huduma za Dijitali', 'icon' => 'code'],
            ]],
            ['name' => 'Food & Drinks', 'name_sw' => 'Chakula na Vinywaji', 'icon' => 'cake', 'sort_order' => 6, 'children' => [
                ['name' => 'Fresh Produce', 'name_sw' => 'Mazao Mapya', 'icon' => 'apple'],
                ['name' => 'Prepared Food', 'name_sw' => 'Chakula Kilichoandaliwa', 'icon' => 'bowl'],
                ['name' => 'Beverages', 'name_sw' => 'Vinywaji', 'icon' => 'cup'],
            ]],
            ['name' => 'Health & Beauty', 'name_sw' => 'Afya na Urembo', 'icon' => 'heart', 'sort_order' => 7, 'children' => [
                ['name' => 'Skincare', 'name_sw' => 'Utunzaji wa Ngozi', 'icon' => 'droplet'],
                ['name' => 'Makeup', 'name_sw' => 'Mapambo', 'icon' => 'palette'],
                ['name' => 'Health Supplements', 'name_sw' => 'Virutubisho', 'icon' => 'pill'],
            ]],
            ['name' => 'Sports', 'name_sw' => 'Michezo', 'icon' => 'trophy', 'sort_order' => 8, 'children' => [
                ['name' => 'Sports Equipment', 'name_sw' => 'Vifaa vya Michezo', 'icon' => 'dumbbell'],
                ['name' => 'Sportswear', 'name_sw' => 'Nguo za Michezo', 'icon' => 'jersey'],
                ['name' => 'Outdoor & Camping', 'name_sw' => 'Kambi', 'icon' => 'tent'],
            ]],
            ['name' => 'Books & Media', 'name_sw' => 'Vitabu na Media', 'icon' => 'book-open', 'sort_order' => 9, 'children' => [
                ['name' => 'Books', 'name_sw' => 'Vitabu', 'icon' => 'book'],
                ['name' => 'Music & Movies', 'name_sw' => 'Muziki na Filamu', 'icon' => 'film'],
                ['name' => 'Games', 'name_sw' => 'Michezo', 'icon' => 'gamepad'],
            ]],
            ['name' => 'Other', 'name_sw' => 'Mengineyo', 'icon' => 'squares-plus', 'sort_order' => 10],
        ];

        foreach ($categories as $categoryData) {
            $children = $categoryData['children'] ?? [];
            unset($categoryData['children']);

            $categoryData['slug'] = Str::slug($categoryData['name']);
            $parent = ProductCategory::create($categoryData);

            foreach ($children as $i => $child) {
                $child['slug'] = Str::slug($child['name']);
                $child['parent_id'] = $parent->id;
                $child['sort_order'] = $i + 1;
                ProductCategory::create($child);
            }
        }

        $this->command->info('Seeded ' . ProductCategory::count() . ' product categories.');
    }
}
