<?php

namespace App\Domain\Catalog;

/**
 * 33 merchant products mapped to themes/classic/assets/merchant/products/{n}.png
 *
 * @phpstan-type MerchantProductDef array{file: string, name: string, category: string, brand: string, price: float, compare?: float, short: string, flags?: array{new?: bool, sale?: bool, best?: bool}}
 * @phpstan-type MerchantBrandDef array{slug: string, name: string, tagline?: string, logo?: string}
 */
final class MerchantProductCatalog
{
    /** @return list<MerchantProductDef> */
    public static function products(): array
    {
        return [
            ['file' => '1.png', 'name' => 'Pulse Apex RGB Gaming Mouse', 'category' => 'gaming-mice', 'brand' => 'razer', 'price' => 54.99, 'compare' => 69.99, 'short' => 'Ergonomic wireless gaming mouse with side macro buttons and dual-zone red and blue LED lighting.', 'flags' => ['new' => true, 'sale' => true]],
            ['file' => '2.png', 'name' => 'Sync Kit USB-C Cable & Earbuds', 'category' => 'phone-accessories', 'brand' => 'apple', 'price' => 19.99, 'compare' => 24.99, 'short' => 'Minimalist white USB-A to USB-C cable bundled with secure-fit wired in-ear earbuds.', 'flags' => ['sale' => true]],
            ['file' => '3.png', 'name' => 'Swift Swivel 64GB USB Drive', 'category' => 'storage-drives', 'brand' => 'samsung', 'price' => 14.99, 'compare' => 19.99, 'short' => 'Compact swivel USB flash drive with matte textured shell and integrated keychain loop.', 'flags' => ['sale' => true]],
            ['file' => '4.png', 'name' => 'SkyTab Air 10 Tablet', 'category' => 'tablets', 'brand' => 'apple', 'price' => 449.99, 'compare' => 499.99, 'short' => 'Slim 10-inch tablet in sky blue with rear camera and Smart Connector support.', 'flags' => ['sale' => true]],
            ['file' => '5.png', 'name' => 'Immersio VR Headset', 'category' => 'vr-headsets', 'brand' => 'sony', 'price' => 299.99, 'short' => 'Lightweight VR headset with adjustable head strap and cyan LED accent lighting.', 'flags' => ['new' => true]],
            ['file' => '6.png', 'name' => 'NovaPhone 12 Mini', 'category' => 'smartphones', 'brand' => 'apple', 'price' => 699.99, 'compare' => 799.99, 'short' => 'Compact flagship smartphone with dual rear cameras and flat-edge matte black design.', 'flags' => ['sale' => true]],
            ['file' => '7.png', 'name' => 'Prestige Automatic Dress Watch', 'category' => 'classic-watches', 'brand' => 'samsung', 'price' => 329.99, 'short' => 'Rose gold automatic watch with blue sunburst dial, date window, and blue leather strap.'],
            ['file' => '8.png', 'name' => 'PureTone Wireless Headphones', 'category' => 'over-ear-headphones', 'brand' => 'bose', 'price' => 79.99, 'compare' => 99.99, 'short' => 'Matte black over-ear headphones with cushioned ear cups and metallic driver accents.', 'flags' => ['best' => true, 'sale' => true]],
            ['file' => '9.png', 'name' => 'WavePhone A54', 'category' => 'smartphones', 'brand' => 'samsung', 'price' => 399.99, 'short' => 'Ice-blue smartphone with triple rear cameras and a clean matte finish.', 'flags' => ['new' => true]],
            ['file' => '10.png', 'name' => 'Fit Pro Smartwatch', 'category' => 'smartwatches', 'brand' => 'apple', 'price' => 249.99, 'compare' => 299.99, 'short' => 'Black sport smartwatch with heart rate tracking, activity rings, and silicone band.', 'flags' => ['best' => true, 'sale' => true]],
            ['file' => '11.png', 'name' => 'Station 5 Console Bundle', 'category' => 'consoles', 'brand' => 'sony', 'price' => 499.99, 'short' => 'Next-gen gaming console with included wireless controller and blue LED accents.', 'flags' => ['best' => true]],
            ['file' => '12.png', 'name' => 'Studio Pro Bookshelf Speaker', 'category' => 'bookshelf-speakers', 'brand' => 'jbl', 'price' => 149.99, 'short' => 'Single studio monitor with carbon-fiber woofer and silk dome tweeter in a wood-grain cabinet.'],
            ['file' => '13.png', 'name' => 'DeskPro 10-Digit Solar Calculator', 'category' => 'calculators', 'brand' => 'hp', 'price' => 12.99, 'short' => 'Matte black desktop calculator with solar panel, memory keys, and large LCD display.'],
            ['file' => '14.png', 'name' => 'Classic Full-Size Wired Keyboard', 'category' => 'keyboards', 'brand' => 'logitech', 'price' => 34.99, 'short' => 'Full-layout USB keyboard with numeric keypad and low-profile matte black keys.'],
            ['file' => '15.png', 'name' => 'Match Pro Soccer Ball', 'category' => 'team-sports', 'brand' => 'adidas', 'price' => 24.99, 'short' => 'Classic black-and-white stitched soccer ball for training and recreational play.'],
            ['file' => '16.png', 'name' => 'BlendMaster 750W Mixer Grinder', 'category' => 'kitchen-appliances', 'brand' => 'panasonic', 'price' => 89.99, 'compare' => 109.99, 'short' => '750W mixer grinder with three stainless steel jars and variable speed control.', 'flags' => ['sale' => true]],
            ['file' => '17.png', 'name' => 'Vision 55" 4K Smart TV', 'category' => 'home-entertainment', 'brand' => 'lg', 'price' => 599.99, 'compare' => 699.99, 'short' => 'Ultra-slim smart TV with vivid 4K panel and minimalist V-stand legs.', 'flags' => ['best' => true, 'sale' => true]],
            ['file' => '18.png', 'name' => 'Breeze Desk Table Fan', 'category' => 'climate-comfort', 'brand' => 'xiaomi', 'price' => 29.99, 'short' => 'Compact white and blue desk fan with three-speed rotary dial control.'],
            ['file' => '19.png', 'name' => 'Lounge Gaming Bean Bag', 'category' => 'gaming-furniture', 'brand' => 'razer', 'price' => 89.99, 'compare' => 119.99, 'short' => 'Charcoal textured bean bag chair with ergonomic teardrop shape for gaming lounges.', 'flags' => ['sale' => true]],
            ['file' => '20.png', 'name' => 'Trail M100 Mountain Bike', 'category' => 'cycling', 'brand' => 'nike', 'price' => 449.99, 'short' => 'Hardtail mountain bike with front suspension, disc brakes, and 21-speed drivetrain.', 'flags' => ['new' => true]],
            ['file' => '21.png', 'name' => 'Guard Pro Cricket Helmet', 'category' => 'protective-gear', 'brand' => 'adidas', 'price' => 59.99, 'short' => 'Navy blue cricket helmet with steel face grill and padded adjustable chin strap.'],
            ['file' => '22.png', 'name' => 'Endurance Smart Treadmill', 'category' => 'fitness-gear', 'brand' => 'under-armour', 'price' => 799.99, 'compare' => 899.99, 'short' => 'Motorized treadmill with digital console, cup holders, and magnetic safety key.', 'flags' => ['sale' => true]],
            ['file' => '23.png', 'name' => 'Willow Cricket Bat & Ball Set', 'category' => 'team-sports', 'brand' => 'nike', 'price' => 79.99, 'short' => 'English willow cricket bat with blue grip and included red leather match ball.'],
            ['file' => '24.png', 'name' => 'Glide Pro Inline Skates', 'category' => 'skating', 'brand' => 'puma', 'price' => 89.99, 'compare' => 109.99, 'short' => 'Four-wheel inline skates with lace, strap, and buckle closure system.', 'flags' => ['sale' => true]],
            ['file' => '25.png', 'name' => 'RoadWatch HD Dash Cam', 'category' => 'auto-tech', 'brand' => 'nvidia', 'price' => 69.99, 'compare' => 89.99, 'short' => 'Compact windshield dash camera with wide-angle lens and night-vision sensors.', 'flags' => ['new' => true, 'sale' => true]],
            ['file' => '26.png', 'name' => 'Carbon Full-Face Racing Helmet', 'category' => 'motorsport', 'brand' => 'puma', 'price' => 129.99, 'short' => 'Aerodynamic motorcycle helmet with clear visor, top vents, and red carbon graphics.'],
            ['file' => '27.png', 'name' => 'AeroCycle Road Helmet', 'category' => 'cycling', 'brand' => 'new-balance', 'price' => 49.99, 'short' => 'Lightweight cycling helmet with multiple air vents and adjustable rear dial fit.'],
            ['file' => '28.png', 'name' => '20W Fast Charge Kit', 'category' => 'phone-accessories', 'brand' => 'xiaomi', 'price' => 18.99, 'compare' => 24.99, 'short' => 'Wall adapter and USB-A to USB-C cable set for fast charging phones and tablets.', 'flags' => ['sale' => true]],
            ['file' => '29.png', 'name' => 'Pro-Rider Motorcycle Gloves', 'category' => 'motorsport', 'brand' => 'under-armour', 'price' => 39.99, 'short' => 'Protective riding gloves with carbon knuckle guards and touchscreen fingertips.'],
            ['file' => '30.png', 'name' => 'Kids 4x4 Electric Ride-On SUV', 'category' => 'racing-toys', 'brand' => 'new-balance', 'price' => 199.99, 'compare' => 249.99, 'short' => 'Battery-powered kids ride-on with LED headlights, safety belt, and rugged tires.', 'flags' => ['new' => true, 'sale' => true]],
            ['file' => '31.png', 'name' => 'Classic Stick Umbrella', 'category' => 'everyday-carry', 'brand' => 'beats', 'price' => 22.99, 'short' => 'Royal blue full-size stick umbrella with curved black handle and metal frame.'],
            ['file' => '32.png', 'name' => 'CrystalBuds True Wireless Earbuds', 'category' => 'true-wireless', 'brand' => 'beats', 'price' => 59.99, 'compare' => 79.99, 'short' => 'Glossy white true wireless earbuds with open charging case and status LED.', 'flags' => ['new' => true, 'sale' => true]],
            ['file' => '33.png', 'name' => 'Precision Pro Beard Trimmer', 'category' => 'grooming', 'brand' => 'panasonic', 'price' => 44.99, 'short' => 'Cordless T-blade trimmer with textured grip, LED battery indicator, and chrome finish.'],
        ];
    }

    /**
     * Demo category tree (parent → child → sub-child) with cover images under merchant assets.
     *
     * @return list<array{slug: string, name: string, parent?: string, description?: string, image?: string}>
     */
    public static function categories(): array
    {
        return [
            ['slug' => 'electronics', 'name' => 'Electronics', 'description' => 'Phones, computers, and everyday tech for work and play.', 'image' => 'categories/electronics.png'],
            ['slug' => 'mobile-tablets', 'name' => 'Mobile & Tablets', 'parent' => 'electronics', 'description' => 'Smartphones, tablets, and charging kits ready for daily use.', 'image' => 'categories/electronics.png'],
            ['slug' => 'smartphones', 'name' => 'Smartphones', 'parent' => 'mobile-tablets', 'description' => 'Flagship and midrange phones with clear cameras and long battery life.', 'image' => 'products/6.png'],
            ['slug' => 'tablets', 'name' => 'Tablets', 'parent' => 'mobile-tablets', 'description' => 'Slim tablets for streaming, sketching, and travel.', 'image' => 'products/4.png'],
            ['slug' => 'phone-accessories', 'name' => 'Phone Accessories', 'parent' => 'mobile-tablets', 'description' => 'Cables, chargers, and pocket-friendly mobile essentials.', 'image' => 'products/2.png'],
            ['slug' => 'computers', 'name' => 'Computers', 'parent' => 'electronics', 'description' => 'Laptops, monitors, and storage for creative and office work.', 'image' => 'categories/computers.png'],
            ['slug' => 'laptops', 'name' => 'Laptops', 'parent' => 'computers', 'description' => 'Portable machines for school, studio, and remote work.', 'image' => 'products/4.png'],
            ['slug' => 'monitors', 'name' => 'Monitors', 'parent' => 'computers', 'description' => 'Clear displays for desks that need more screen space.', 'image' => 'products/17.png'],
            ['slug' => 'storage-drives', 'name' => 'Storage & Drives', 'parent' => 'computers', 'description' => 'Flash drives and portable storage you can clip and go.', 'image' => 'products/3.png'],
            ['slug' => 'office-tech', 'name' => 'Office & Printing', 'parent' => 'electronics', 'description' => 'Keyboards, calculators, and desk tools that stay out of the way.', 'image' => 'categories/office.png'],
            ['slug' => 'keyboards', 'name' => 'Keyboards', 'parent' => 'office-tech', 'description' => 'Full-size and compact keyboards for typing all day.', 'image' => 'products/14.png'],
            ['slug' => 'calculators', 'name' => 'Calculators', 'parent' => 'office-tech', 'description' => 'Solar and desk calculators for quick everyday numbers.', 'image' => 'products/13.png'],
            ['slug' => 'desk-essentials', 'name' => 'Desk Essentials', 'parent' => 'office-tech', 'description' => 'Small office helpers that keep the desk tidy and ready.', 'image' => 'products/13.png'],
            ['slug' => 'auto-tech', 'name' => 'Auto Tech', 'parent' => 'electronics', 'description' => 'Dash cameras and in-car gadgets for safer trips.', 'image' => 'products/25.png'],
            ['slug' => 'gaming', 'name' => 'Gaming', 'description' => 'Consoles, PC gear, and lounge setups for play sessions.', 'image' => 'categories/gaming.png'],
            ['slug' => 'gaming-accessories', 'name' => 'Gaming Accessories', 'parent' => 'gaming', 'description' => 'Mice, controllers, and accessories built for reaction time.', 'image' => 'categories/gaming-accessories.png'],
            ['slug' => 'gaming-mice', 'name' => 'Gaming Mice', 'parent' => 'gaming-accessories', 'description' => 'RGB and wireless mice tuned for competitive play.', 'image' => 'products/1.png'],
            ['slug' => 'consoles', 'name' => 'Consoles', 'parent' => 'gaming-accessories', 'description' => 'Next-gen console bundles and controllers.', 'image' => 'products/11.png'],
            ['slug' => 'gaming-furniture', 'name' => 'Gaming Furniture', 'parent' => 'gaming-accessories', 'description' => 'Bean bags and lounge seating for long sessions.', 'image' => 'products/19.png'],
            ['slug' => 'racing-toys', 'name' => 'Racing & Ride-Ons', 'parent' => 'gaming', 'description' => 'Kids ride-ons and playful racing gear.', 'image' => 'products/30.png'],
            ['slug' => 'vr-gaming', 'name' => 'VR Gaming', 'parent' => 'gaming', 'description' => 'Immersive headsets and virtual play kits.', 'image' => 'products/5.png'],
            ['slug' => 'vr-headsets', 'name' => 'VR Headsets', 'parent' => 'vr-gaming', 'description' => 'Lightweight VR headsets with adjustable fit.', 'image' => 'products/5.png'],
            ['slug' => 'audio', 'name' => 'Audio & Headphones', 'description' => 'Headphones, earbuds, and speakers for focused listening.', 'image' => 'categories/audio.png'],
            ['slug' => 'headphones', 'name' => 'Headphones', 'parent' => 'audio', 'description' => 'Over-ear and on-ear headphones for studio and commute.', 'image' => 'categories/audio.png'],
            ['slug' => 'over-ear-headphones', 'name' => 'Over-Ear Headphones', 'parent' => 'headphones', 'description' => 'Cushioned over-ear models with rich everyday sound.', 'image' => 'products/8.png'],
            ['slug' => 'earbuds', 'name' => 'Earbuds', 'parent' => 'audio', 'description' => 'Wired and true wireless earbuds for light travel.', 'image' => 'products/32.png'],
            ['slug' => 'true-wireless', 'name' => 'True Wireless', 'parent' => 'earbuds', 'description' => 'Pocket-size TWS earbuds with charging cases.', 'image' => 'products/32.png'],
            ['slug' => 'speakers', 'name' => 'Speakers', 'parent' => 'audio', 'description' => 'Portable and bookshelf speakers for rooms and desks.', 'image' => 'categories/speakers.png'],
            ['slug' => 'portable-speakers', 'name' => 'Portable Speakers', 'parent' => 'speakers', 'description' => 'Bluetooth speakers you can take to the patio or office.', 'image' => 'products/12.png'],
            ['slug' => 'bookshelf-speakers', 'name' => 'Bookshelf Speakers', 'parent' => 'speakers', 'description' => 'Studio-style monitors for honest desktop playback.', 'image' => 'products/12.png'],
            ['slug' => 'wearables', 'name' => 'Wearables', 'description' => 'Watches, fitness tools, and gear you wear all day.', 'image' => 'categories/wearables.png'],
            ['slug' => 'smartwatches', 'name' => 'Smartwatches', 'parent' => 'wearables', 'description' => 'Sport watches with heart-rate and activity tracking.', 'image' => 'products/10.png'],
            ['slug' => 'classic-watches', 'name' => 'Classic Watches', 'parent' => 'wearables', 'description' => 'Dress watches with clean dials and leather straps.', 'image' => 'products/7.png'],
            ['slug' => 'fitness-gear', 'name' => 'Fitness Gear', 'parent' => 'wearables', 'description' => 'Training tools for steady home workouts.', 'image' => 'products/22.png'],
            ['slug' => 'smart-home', 'name' => 'Smart Home', 'description' => 'Kitchen, climate, and living-room tech for modern homes.', 'image' => 'categories/smart-home.png'],
            ['slug' => 'kitchen-appliances', 'name' => 'Kitchen Appliances', 'parent' => 'smart-home', 'description' => 'Mixers and countertop helpers for everyday cooking.', 'image' => 'categories/smart-home.png'],
            ['slug' => 'climate-comfort', 'name' => 'Climate & Comfort', 'parent' => 'smart-home', 'description' => 'Fans and comfort gear for desks and rooms.', 'image' => 'products/18.png'],
            ['slug' => 'home-entertainment', 'name' => 'Home Entertainment', 'parent' => 'smart-home', 'description' => 'Smart TVs and living-room displays with a slim profile.', 'image' => 'products/17.png'],
            ['slug' => 'home-essentials', 'name' => 'Home Essentials', 'parent' => 'smart-home', 'description' => 'Practical home pieces for rainy days and daily routines.', 'image' => 'products/31.png'],
            ['slug' => 'sports-outdoors', 'name' => 'Sports & Outdoors', 'description' => 'Cycling, team sports, and protective gear for active days.', 'image' => 'categories/sports.png'],
            ['slug' => 'cycling', 'name' => 'Cycling', 'parent' => 'sports-outdoors', 'description' => 'Bikes and helmets for road and trail rides.', 'image' => 'categories/sports.png'],
            ['slug' => 'team-sports', 'name' => 'Team Sports', 'parent' => 'sports-outdoors', 'description' => 'Soccer, cricket, and match-day essentials.', 'image' => 'products/15.png'],
            ['slug' => 'protective-gear', 'name' => 'Protective Gear', 'parent' => 'sports-outdoors', 'description' => 'Helmets and padding for field and road safety.', 'image' => 'products/21.png'],
            ['slug' => 'skating', 'name' => 'Skating', 'parent' => 'sports-outdoors', 'description' => 'Inline skates and rollers for weekend movement.', 'image' => 'products/24.png'],
            ['slug' => 'motorsport', 'name' => 'Motorsport', 'parent' => 'sports-outdoors', 'description' => 'Riding helmets and gloves for the open road.', 'image' => 'products/26.png'],
            ['slug' => 'personal-care', 'name' => 'Personal Care', 'description' => 'Grooming tools for a clean, confident routine.', 'image' => 'categories/personal-care.png'],
            ['slug' => 'grooming', 'name' => 'Grooming', 'parent' => 'personal-care', 'description' => 'Trimmers and personal-care appliances.', 'image' => 'products/33.png'],
            ['slug' => 'lifestyle', 'name' => 'Lifestyle', 'description' => 'Everyday carry and lifestyle picks beyond pure tech.', 'image' => 'categories/lifestyle.png'],
            ['slug' => 'everyday-carry', 'name' => 'Everyday Carry', 'parent' => 'lifestyle', 'description' => 'Small daily companions you take everywhere.', 'image' => 'products/31.png'],
        ];
    }

    /**
     * Demo brands with well-known marks for storefront polish (dummy data only).
     * Logos: themes/classic/assets/merchant/brands/{slug}.svg
     *
     * @return list<MerchantBrandDef>
     */
    public static function brands(): array
    {
        return [
            ['slug' => 'nike', 'name' => 'Nike', 'tagline' => 'Just do it.'],
            ['slug' => 'adidas', 'name' => 'Adidas', 'tagline' => 'Impossible is nothing.'],
            ['slug' => 'apple', 'name' => 'Apple', 'tagline' => 'Think different.'],
            ['slug' => 'samsung', 'name' => 'Samsung', 'tagline' => 'Do what you can’t.'],
            ['slug' => 'sony', 'name' => 'Sony', 'tagline' => 'Be moved.'],
            ['slug' => 'puma', 'name' => 'Puma', 'tagline' => 'Forever faster.'],
            ['slug' => 'microsoft', 'name' => 'Microsoft', 'tagline' => 'Empowering every person.'],
            ['slug' => 'google', 'name' => 'Google', 'tagline' => 'Organize the world’s information.'],
            ['slug' => 'dell', 'name' => 'Dell', 'tagline' => 'The power to do more.'],
            ['slug' => 'hp', 'name' => 'HP', 'tagline' => 'Keep reinventing.'],
            ['slug' => 'lenovo', 'name' => 'Lenovo', 'tagline' => 'Innovation never stands still.'],
            ['slug' => 'bose', 'name' => 'Bose', 'tagline' => 'Better sound through research.'],
            ['slug' => 'jbl', 'name' => 'JBL', 'tagline' => 'Hear the truth.'],
            ['slug' => 'lg', 'name' => 'LG', 'tagline' => 'Life’s good.'],
            ['slug' => 'xiaomi', 'name' => 'Xiaomi', 'tagline' => 'Innovation for everyone.'],
            ['slug' => 'asus', 'name' => 'ASUS', 'tagline' => 'In search of incredible.'],
            ['slug' => 'under-armour', 'name' => 'Under Armour', 'tagline' => 'I will.'],
            ['slug' => 'new-balance', 'name' => 'New Balance', 'tagline' => 'Fearlessly independent.'],
            ['slug' => 'beats', 'name' => 'Beats', 'tagline' => 'Tell me when you hear it.'],
            ['slug' => 'logitech', 'name' => 'Logitech', 'tagline' => 'Tools for creativity and productivity.'],
            ['slug' => 'razer', 'name' => 'Razer', 'tagline' => 'For gamers. By gamers.'],
            ['slug' => 'intel', 'name' => 'Intel', 'tagline' => 'Experience what’s inside.'],
            ['slug' => 'panasonic', 'name' => 'Panasonic', 'tagline' => 'A better life, a better world.'],
            ['slug' => 'nvidia', 'name' => 'NVIDIA', 'tagline' => 'The way it’s meant to be played.'],
        ];
    }
}
