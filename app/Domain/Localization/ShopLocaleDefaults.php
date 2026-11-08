<?php

namespace App\Domain\Localization;

use App\Domain\Localization\Models\Currency;
use App\Domain\Localization\Models\Language;
use App\Domain\Localization\Models\UiString;
use App\Domain\Localization\Models\UiStringTranslation;

/**
 * Languages, currencies, and chrome translations every fresh shop starts with.
 */
final class ShopLocaleDefaults
{
    /**
     * @return list<array{code: string, name: string, position: int}>
     */
    public static function languages(): array
    {
        return [
            ['code' => 'en', 'name' => 'English', 'position' => 1],
            ['code' => 'fr', 'name' => 'French', 'position' => 2],
            ['code' => 'es', 'name' => 'Spanish', 'position' => 3],
            ['code' => 'it', 'name' => 'Italian', 'position' => 4],
            ['code' => 'ar', 'name' => 'Arabic', 'position' => 5],
            ['code' => 'hi', 'name' => 'Hindi', 'position' => 6],
        ];
    }

    /**
     * Shopper language → currency. English and Arabic use the dollar,
     * French, Spanish, and Italian use the euro, Hindi uses the rupee.
     *
     * @return array<string, string>
     */
    public static function languageCurrencies(): array
    {
        return [
            'en' => 'USD',
            'fr' => 'EUR',
            'es' => 'EUR',
            'it' => 'EUR',
            'ar' => 'USD',
            'hi' => 'INR',
        ];
    }

    public static function currencyFor(string $locale): string
    {
        $code = strtolower(substr($locale, 0, 2));

        return self::languageCurrencies()[$code] ?? 'USD';
    }

    /**
     * Installer choice becomes the shop default language and currency.
     */
    public static function applyChosen(string $language, string $currency): void
    {
        $language = strtolower(substr(trim($language), 0, 12));
        $codes = array_column(self::languages(), 'code');
        if (! in_array($language, $codes, true)) {
            $language = 'en';
        }

        Language::query()->update(['is_default' => false]);
        $updated = Language::query()->where('code', $language)->update([
            'is_default' => true,
            'is_enabled' => true,
        ]);
        if ($updated === 0) {
            $language = 'en';
            Language::query()->where('code', 'en')->update([
                'is_default' => true,
                'is_enabled' => true,
            ]);
        }
        Language::forgetMemos();

        $currency = strtoupper(substr(trim($currency), 0, 8));
        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            $currency = 'USD';
        }

        $known = [
            'USD' => ['name' => 'US Dollar', 'symbol' => '$', 'position' => 1],
            'EUR' => ['name' => 'Euro', 'symbol' => '€', 'position' => 2],
            'INR' => ['name' => 'Indian Rupee', 'symbol' => '₹', 'position' => 3],
            'GBP' => ['name' => 'Pound', 'symbol' => '£', 'position' => 4],
        ];
        $row = Currency::query()->where('code', $currency)->first();
        if (! $row) {
            if (! isset($known[$currency])) {
                $currency = 'USD';
                $row = Currency::query()->where('code', 'USD')->first();
            } else {
                $meta = $known[$currency];
                $row = Currency::query()->create([
                    'code' => $currency,
                    'name' => $meta['name'],
                    'symbol' => $meta['symbol'],
                    'exchange_rate' => 1,
                    'decimal_places' => 2,
                    'is_enabled' => true,
                    'is_default' => false,
                    'position' => $meta['position'],
                ]);
            }
        }
        if ($row) {
            self::rebaseRatesToDefault($row);
        }

        config([
            'wento.default_language' => $language,
            'wento.default_currency' => $currency,
        ]);
        Money::forgetCache();
    }

    /**
     * Make $default the shop default and scale every exchange_rate so its rate is 1.
     * Rates are "units of this currency per 1 unit of the default".
     */
    public static function rebaseRatesToDefault(Currency $default): void
    {
        $divisor = (float) $default->exchange_rate;
        if ($divisor <= 0) {
            $divisor = 1;
        }

        foreach (Currency::query()->get() as $row) {
            $rate = (float) $row->exchange_rate / $divisor;
            $row->forceFill([
                'is_default' => $row->id === $default->id,
                'is_enabled' => $row->id === $default->id ? true : $row->is_enabled,
                'exchange_rate' => $row->id === $default->id ? 1.0 : $rate,
            ])->save();
        }
    }

    public static function seed(): void
    {
        self::seedLanguages();
        self::seedCurrencies();
        if (! app()->runningUnitTests()) {
            self::seedTranslations();
        }
    }

    public static function seedLanguages(): void
    {
        $hasDefault = Language::query()->where('is_default', true)->exists();
        foreach (self::languages() as $row) {
            $language = Language::query()->firstOrNew(['code' => $row['code']]);
            $language->name = $row['name'];
            $language->position = $row['position'];
            $language->is_enabled = true;
            if (! $language->exists) {
                $language->is_default = ! $hasDefault && $row['code'] === 'en';
            }
            $language->save();
            if ($language->is_default) {
                $hasDefault = true;
            }
        }
    }

    public static function seedCurrencies(): void
    {
        $rows = [
            ['code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$', 'exchange_rate' => 1, 'decimal_places' => 2, 'position' => 1, 'is_default' => true],
            ['code' => 'EUR', 'name' => 'Euro', 'symbol' => '€', 'exchange_rate' => 0.92, 'decimal_places' => 2, 'position' => 2, 'is_default' => false],
            ['code' => 'INR', 'name' => 'Indian Rupee', 'symbol' => '₹', 'exchange_rate' => 83, 'decimal_places' => 2, 'position' => 3, 'is_default' => false],
        ];
        $hasDefault = Currency::query()->where('is_default', true)->exists();
        foreach ($rows as $row) {
            $currency = Currency::query()->firstOrNew(['code' => $row['code']]);
            $currency->name = $row['name'];
            $currency->symbol = $row['symbol'];
            $currency->is_enabled = true;
            $currency->position = $row['position'];
            $currency->decimal_places = $row['decimal_places'];
            if (! $currency->exists || (float) $currency->exchange_rate <= 0) {
                $currency->exchange_rate = $row['exchange_rate'];
            }
            if (! $currency->exists) {
                $currency->is_default = ! $hasDefault && $row['is_default'];
            }
            $currency->save();
            if ($currency->is_default) {
                $hasDefault = true;
            }
        }
    }

    public static function seedTranslations(bool $force = false): void
    {
        UiI18n::sync();
        $pack = self::mergedLocalePack();
        $strings = UiString::query()->get(['id', 'key', 'source']);
        $existing = UiStringTranslation::query()
            ->whereIn('language_code', self::bundledUiLocales())
            ->get(['id', 'ui_string_id', 'language_code', 'text'])
            ->keyBy(fn (UiStringTranslation $row) => $row->ui_string_id.'|'.$row->language_code);

        $now = now();
        $upserts = [];
        foreach ($strings as $string) {
            $row = $pack[$string->key] ?? $pack[$string->source] ?? null;
            if (! is_array($row)) {
                continue;
            }
            foreach ($row as $locale => $text) {
                $text = trim((string) $text);
                if ($text === '' || $locale === 'en') {
                    continue;
                }
                $mapKey = $string->id.'|'.$locale;
                $currentRow = $existing->get($mapKey);
                $current = trim((string) ($currentRow->text ?? ''));
                // Keep merchant edits unless --force; overwrite empty or leftover English source copies.
                if (
                    ! $force
                    && $current !== ''
                    && $current !== trim((string) $string->source)
                    && $current !== $text
                ) {
                    continue;
                }
                $upserts[] = [
                    'ui_string_id' => (int) $string->id,
                    'language_code' => (string) $locale,
                    'text' => $text,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        foreach (array_chunk($upserts, 500) as $chunk) {
            UiStringTranslation::query()->upsert(
                $chunk,
                ['ui_string_id', 'language_code'],
                ['text', 'updated_at']
            );
        }

        UiI18n::forgetMessageCaches();
    }

    /**
     * Complete installer packs (key → locale → text) plus legacy chromePack fallback.
     *
     * @return array<string, array<string, string>>
     */
    public static function mergedLocalePack(): array
    {
        $pack = self::chromePack();
        foreach (['fr', 'es', 'it', 'ar', 'hi'] as $locale) {
            $path = database_path('i18n/ui/'.$locale.'.json');
            if (! is_file($path)) {
                continue;
            }
            $decoded = json_decode((string) file_get_contents($path), true);
            if (! is_array($decoded)) {
                continue;
            }
            foreach ($decoded as $key => $text) {
                if (! is_string($key) || ! is_string($text)) {
                    continue;
                }
                $text = trim($text);
                if ($text === '') {
                    continue;
                }
                $pack[$key][$locale] = $text;
            }
        }

        return $pack;
    }

    /**
     * Locales that ship with complete pre-translated UI chrome packs.
     *
     * @return list<string>
     */
    public static function bundledUiLocales(): array
    {
        return ['fr', 'es', 'it', 'ar', 'hi'];
    }

    /**
     * English source (or translation key) → fr, es, it, ar, hi.
     *
     * @return array<string, array<string, string>>
     */
    public static function chromePack(): array
    {
        $rows = [
            'Dashboard' => ['Tableau de bord', 'Panel', 'Pannello', 'لوحة التحكم', 'डैशबोर्ड'],
            'Catalog' => ['Catalogue', 'Catálogo', 'Catalogo', 'الكتالوج', 'कैटलॉग'],
            'Orders' => ['Commandes', 'Pedidos', 'Ordini', 'الطلبات', 'ऑर्डर'],
            'Customers' => ['Clients', 'Clientes', 'Clienti', 'العملاء', 'ग्राहक'],
            'Configure' => ['Configurer', 'Configurar', 'Configura', 'الإعدادات', 'कॉन्फ़िगर'],
            'Settings' => ['Paramètres', 'Ajustes', 'Impostazioni', 'الإعدادات', 'सेटिंग्स'],
            'Store' => ['Boutique', 'Tienda', 'Negozio', 'المتجر', 'स्टोर'],
            'General' => ['Général', 'General', 'Generale', 'عام', 'सामान्य'],
            'Save' => ['Enregistrer', 'Guardar', 'Salva', 'حفظ', 'सहेजें'],
            'Search' => ['Rechercher', 'Buscar', 'Cerca', 'بحث', 'खोज'],
            'Products' => ['Produits', 'Productos', 'Prodotti', 'المنتجات', 'उत्पाद'],
            'Product' => ['Produit', 'Producto', 'Prodotto', 'منتج', 'उत्पाद'],
            'Categories' => ['Catégories', 'Categorías', 'Categorie', 'الفئات', 'श्रेणियाँ'],
            'Category' => ['Catégorie', 'Categoría', 'Categoria', 'الفئة', 'श्रेणी'],
            'Cart' => ['Panier', 'Carrito', 'Carrello', 'السلة', 'कार्ट'],
            'Checkout' => ['Paiement', 'Pago', 'Cassa', 'الدفع', 'चेकआउट'],
            'Shop Now' => ['Acheter', 'Comprar ahora', 'Acquista ora', 'تسوق الآن', 'अभी खरीदें'],
            'View All' => ['Tout voir', 'Ver todo', 'Vedi tutto', 'عرض الكل', 'सभी देखें'],
            'Search for products' => ['Rechercher des produits', 'Buscar productos', 'Cerca prodotti', 'ابحث عن المنتجات', 'उत्पाद खोजें'],
            'Shop by Featured Categories' => ['Acheter par catégorie', 'Comprar por categoría', 'Acquista per categoria', 'تسوق حسب الفئة', 'श्रेणी के अनुसार खरीदें'],
            'New Products' => ['Nouveaux produits', 'Productos nuevos', 'Nuovi prodotti', 'منتجات جديدة', 'नए उत्पाद'],
            'Add to cart' => ['Ajouter au panier', 'Añadir al carrito', 'Aggiungi al carrello', 'أضف إلى السلة', 'कार्ट में जोड़ें'],
            'Sign in' => ['Connexion', 'Iniciar sesión', 'Accedi', 'تسجيل الدخول', 'साइन इन'],
            'Account' => ['Compte', 'Cuenta', 'Account', 'الحساب', 'खाता'],
            'Home' => ['Accueil', 'Inicio', 'Home', 'الرئيسية', 'होम'],
            'Price' => ['Prix', 'Precio', 'Prezzo', 'السعر', 'कीमत'],
            'Quantity' => ['Quantité', 'Cantidad', 'Quantità', 'الكمية', 'मात्रा'],
            'Description' => ['Description', 'Descripción', 'Descrizione', 'الوصف', 'विवरण'],
            'Name' => ['Nom', 'Nombre', 'Nome', 'الاسم', 'नाम'],
            'Subcategories' => ['Sous-catégories', 'Subcategorías', 'Sottocategorie', 'الفئات الفرعية', 'उपश्रेणियाँ'],
            'No products match these filters' => ['Aucun produit ne correspond', 'Ningún producto coincide', 'Nessun prodotto corrisponde', 'لا توجد منتجات مطابقة', 'कोई उत्पाद मेल नहीं खाता'],
            'Language' => ['Langue', 'Idioma', 'Lingua', 'اللغة', 'भाषा'],
            'Currency' => ['Devise', 'Moneda', 'Valuta', 'العملة', 'मुद्रा'],
            'Out of stock' => ['Rupture de stock', 'Agotado', 'Esaurito', 'غير متوفر', 'स्टॉक में नहीं'],
            'In stock' => ['En stock', 'En stock', 'Disponibile', 'متوفر', 'स्टॉक में'],
            'Wishlist' => ['Favoris', 'Favoritos', 'Preferiti', 'المفضلة', 'विशलिस्ट'],
            'Compare' => ['Comparer', 'Comparar', 'Confronta', 'قارن', 'तुलना'],
            'Contact' => ['Contact', 'Contacto', 'Contatto', 'اتصل بنا', 'संपर्क'],
            'Shipping' => ['Livraison', 'Envío', 'Spedizione', 'الشحن', 'शिपिंग'],
            'Payment' => ['Paiement', 'Pago', 'Pagamento', 'الدفع', 'भुगतान'],
            'Order' => ['Commande', 'Pedido', 'Ordine', 'طلب', 'ऑर्डर'],
            'Total' => ['Total', 'Total', 'Totale', 'الإجمالي', 'कुल'],
            'Subtotal' => ['Sous-total', 'Subtotal', 'Subtotale', 'المجموع الفرعي', 'उप-योग'],
            'Continue shopping' => ['Continuer vos achats', 'Seguir comprando', 'Continua lo shopping', 'متابعة التسوق', 'खरीदारी जारी रखें'],
            'My account' => ['Mon compte', 'Mi cuenta', 'Il mio account', 'حسابي', 'मेरा खाता'],
            'Log out' => ['Déconnexion', 'Cerrar sesión', 'Esci', 'تسجيل الخروج', 'लॉग आउट'],
            'Email' => ['E-mail', 'Correo', 'Email', 'البريد', 'ईमेल'],
            'Password' => ['Mot de passe', 'Contraseña', 'Password', 'كلمة المرور', 'पासवर्ड'],
            'Address' => ['Adresse', 'Dirección', 'Indirizzo', 'العنوان', 'पता'],
            'Phone' => ['Téléphone', 'Teléfono', 'Telefono', 'الهاتف', 'फ़ोन'],
            'Search products, orders, customers…' => ['Rechercher produits, commandes, clients…', 'Buscar productos, pedidos, clientes…', 'Cerca prodotti, ordini, clienti…', 'ابحث عن المنتجات والطلبات والعملاء…', 'उत्पाद, ऑर्डर, ग्राहक खोजें…'],
            'View Shop' => ['Voir la boutique', 'Ver tienda', 'Vedi negozio', 'عرض المتجر', 'दुकान देखें'],
            'Default' => ['Par défaut', 'Predeterminado', 'Predefinito', 'افتراضي', 'डिफ़ॉल्ट'],
            'Operate' => ['Exploiter', 'Operar', 'Opera', 'التشغيل', 'संचालन'],
            'Design' => ['Design', 'Diseño', 'Design', 'التصميم', 'डिज़ाइन'],
            'International' => ['À l’international', 'Internacional', 'Internazionale', 'الدولي', 'अंतर्राष्ट्रीय'],
            'Staff' => ['Personnel', 'Personal', 'Personale', 'الموظفون', 'स्टाफ'],
            'Marketing' => ['Marketing', 'Marketing', 'Marketing', 'التسويق', 'मार्केटिंग'],
            'AI Assistant' => ['Assistant IA', 'Asistente de IA', 'Assistente IA', 'مساعد الذكاء الاصطناعي', 'एआई सहायक'],
            'Main menu' => ['Menu principal', 'Menú principal', 'Menu principale', 'القائمة الرئيسية', 'मुख्य मेनू'],
            'Bulk actions' => ['Actions groupées', 'Acciones masivas', 'Azioni di gruppo', 'إجراءات جماعية', 'थोक कार्रवाई'],
            'Slug' => ['Slug', 'Slug', 'Slug', 'المعرف', 'स्लग'],
            'Status' => ['Statut', 'Estado', 'Stato', 'الحالة', 'स्थिति'],
            'Action' => ['Action', 'Acción', 'Azione', 'إجراء', 'कार्रवाई'],
            'Actions' => ['Actions', 'Acciones', 'Azioni', 'إجراءات', 'कार्रवाइयाँ'],
            'Active' => ['Actif', 'Activo', 'Attivo', 'نشط', 'सक्रिय'],
            'Inactive' => ['Inactif', 'Inactivo', 'Inattivo', 'غير نشط', 'निष्क्रिय'],
            'All' => ['Tous', 'Todos', 'Tutti', 'الكل', 'सभी'],
            'Pos' => ['Pos', 'Pos', 'Pos', 'ترتيب', 'क्रम'],
            'Position' => ['Position', 'Posición', 'Posizione', 'الموضع', 'स्थिति'],
            'Search name' => ['Rechercher un nom', 'Buscar nombre', 'Cerca nome', 'بحث بالاسم', 'नाम खोजें'],
            'Search slug' => ['Rechercher un slug', 'Buscar slug', 'Cerca slug', 'بحث بالمعرف', 'स्लग खोजें'],
            'Search category' => ['Rechercher une catégorie', 'Buscar categoría', 'Cerca categoria', 'بحث عن فئة', 'श्रेणी खोजें'],
            'Search reference' => ['Rechercher une référence', 'Buscar referencia', 'Cerca riferimento', 'بحث بالمرجع', 'संदर्भ खोजें'],
            'subcategory' => ['sous-catégorie', 'subcategoría', 'sottocategoria', 'فئة فرعية', 'उपश्रेणी'],
            'subcategories' => ['sous-catégories', 'subcategorías', 'sottocategorie', 'فئات فرعية', 'उपश्रेणियाँ'],
            'Min' => ['Mini', 'Mín', 'Min', 'أدنى', 'न्यून'],
            'Max' => ['Maxi', 'Máx', 'Max', 'أقصى', 'अधिकतम'],
            'ID min' => ['ID min', 'ID mín', 'ID min', 'أدنى معرف', 'न्यूनतम आईडी'],
            'ID max' => ['ID max', 'ID máx', 'ID max', 'أقصى معرف', 'अधिकतम आईडी'],
            'Reference' => ['Référence', 'Referencia', 'Riferimento', 'المرجع', 'संदर्भ'],
            'Price (tax excl.)' => ['Prix (HT)', 'Precio (sin IVA)', 'Prezzo (IVA escl.)', 'السعر (بدون ضريبة)', 'कीमत (कर रहित)'],
            'Add category' => ['Ajouter une catégorie', 'Añadir categoría', 'Aggiungi categoria', 'إضافة فئة', 'श्रेणी जोड़ें'],
            'Add Product' => ['Ajouter un produit', 'Añadir producto', 'Aggiungi prodotto', 'إضافة منتج', 'उत्पाद जोड़ें'],
            'Inventory' => ['Inventaire', 'Inventario', 'Inventario', 'المخزون', 'इन्वेंटरी'],
            'Stock journal' => ['Journal de stock', 'Diario de stock', 'Registro scorte', 'سجل المخزون', 'स्टॉक जर्नल'],
            'Warehouses' => ['Entrepôts', 'Almacenes', 'Magazzini', 'المستودعات', 'गोदाम'],
            'Purchase orders' => ['Bons de commande', 'Pedidos de compra', 'Ordini di acquisto', 'أوامر الشراء', 'खरीद ऑर्डर'],
            'Gift cards' => ['Cartes cadeaux', 'Tarjetas regalo', 'Carte regalo', 'بطاقات الهدايا', 'गिफ्ट कार्ड'],
            'Brand' => ['Marque', 'Marca', 'Marca', 'العلامة', 'ब्रांड'],
            'Supplier' => ['Fournisseur', 'Proveedor', 'Fornitore', 'المورد', 'आपूर्तिकर्ता'],
            'Feature' => ['Caractéristique', 'Característica', 'Caratteristica', 'خاصية', 'विशेषता'],
            'Attribute' => ['Attribut', 'Atributo', 'Attributo', 'سمة', 'विशेषता'],
            'Files' => ['Fichiers', 'Archivos', 'File', 'ملفات', 'फ़ाइलें'],
            'Subscription plans' => ['Formules', 'Planes', 'Piani', 'خطط الاشتراك', 'सदस्यता योजनाएँ'],
            'Subscribers' => ['Abonnés', 'Suscriptores', 'Iscritti', 'المشتركون', 'सदस्य'],
            'Login / Register' => ['Connexion / Inscription', 'Entrar / Registro', 'Accedi / Registrati', 'دخول / تسجيل', 'लॉगिन / रजिस्टर'],
            'Search for products' => ['Rechercher des produits', 'Buscar productos', 'Cerca prodotti', 'ابحث عن المنتجات', 'उत्पाद खोजें'],
            'Featured Products' => ['Produits en vedette', 'Productos destacados', 'Prodotti in evidenza', 'منتجات مميزة', 'विशेष उत्पाद'],
            'Bestseller Products' => ['Meilleures ventes', 'Más vendidos', 'I più venduti', 'الأكثر مبيعاً', 'सर्वाधिक बिकने वाले'],
            'Shop' => ['Boutique', 'Tienda', 'Negozio', 'المتجر', 'दुकान'],
            'Menu' => ['Menu', 'Menú', 'Menu', 'القائمة', 'मेनू'],
            'More' => ['Plus', 'Más', 'Altro', 'المزيد', 'और'],
            'Help' => ['Aide', 'Ayuda', 'Aiuto', 'مساعدة', 'सहायता'],
            'About Us' => ['À propos', 'Sobre nosotros', 'Chi siamo', 'من نحن', 'हमारे बारे में'],
            'Contact Us' => ['Contactez-nous', 'Contáctenos', 'Contattaci', 'اتصل بنا', 'संपर्क करें'],
            'Select' => ['Sélectionner', 'Seleccionar', 'Seleziona', 'تحديد', 'चुनें'],
            'Delete' => ['Supprimer', 'Eliminar', 'Elimina', 'حذف', 'हटाएँ'],
            'Edit' => ['Modifier', 'Editar', 'Modifica', 'تعديل', 'संपादित करें'],
            'Save changes' => ['Enregistrer', 'Guardar cambios', 'Salva modifiche', 'حفظ التغييرات', 'बदलाव सहेजें'],
            'Cancel' => ['Annuler', 'Cancelar', 'Annulla', 'إلغاء', 'रद्द करें'],
            'Yes' => ['Oui', 'Sí', 'Sì', 'نعم', 'हाँ'],
            'No' => ['Non', 'No', 'No', 'لا', 'नहीं'],
            'Loading…' => ['Chargement…', 'Cargando…', 'Caricamento…', 'جارٍ التحميل…', 'लोड हो रहा है…'],
            'No records yet.' => ['Aucun enregistrement.', 'Sin registros.', 'Nessun record.', 'لا توجد سجلات.', 'अभी कोई रिकॉर्ड नहीं.'],
        ];

        $rows = array_merge($rows, self::moreChrome());

        $locales = ['fr', 'es', 'it', 'ar', 'hi'];
        $pack = [];
        foreach ($rows as $english => $translations) {
            $mapped = [];
            foreach ($locales as $index => $locale) {
                $mapped[$locale] = $translations[$index];
            }
            $pack[$english] = $mapped;
        }

        return $pack;
    }

    /**
     * More admin and storefront phrases. English source => fr, es, it, ar, hi.
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: string}>
     */
    private static function moreChrome(): array
    {
        return [
            'Expand all' => ['Tout développer', 'Expandir todo', 'Espandi tutto', 'توسيع الكل', 'सभी खोलें'],
            'Collapse all' => ['Tout réduire', 'Contraer todo', 'Comprimi tutto', 'طي الكل', 'सभी समेटें'],
            'Expand' => ['Développer', 'Expandir', 'Espandi', 'توسيع', 'खोलें'],
            'Collapse' => ['Réduire', 'Contraer', 'Comprimi', 'طي', 'समेटें'],
            'All categories' => ['Toutes les catégories', 'Todas las categorías', 'Tutte le categorie', 'كل الفئات', 'सभी श्रेणियाँ'],
            'Active rate' => ['Taux actif', 'Tasa activa', 'Tasso attivi', 'نسبة النشط', 'सक्रिय दर'],
            'vs last 30 days' => ['vs 30 derniers jours', 'vs últimos 30 días', 'vs ultimi 30 giorni', 'مقارنة بآخر 30 يومًا', 'पिछले 30 दिनों से'],
            'Add child' => ['Ajouter un enfant', 'Añadir hijo', 'Aggiungi figlio', 'إضافة فرعي', 'चाइल्ड जोड़ें'],
            'Create category' => ['Créer une catégorie', 'Crear categoría', 'Crea categoria', 'إنشاء فئة', 'श्रेणी बनाएँ'],
            'Edit category' => ['Modifier la catégorie', 'Editar categoría', 'Modifica categoria', 'تعديل الفئة', 'श्रेणी संपादित करें'],
            'Parent category' => ['Catégorie parente', 'Categoría padre', 'Categoria padre', 'الفئة الأصل', 'मूल श्रेणी'],
            'No categories yet.' => ['Aucune catégorie.', 'Aún no hay categorías.', 'Nessuna categoria.', 'لا توجد فئات بعد.', 'अभी कोई श्रेणी नहीं.'],
            'Activity' => ['Activité', 'Actividad', 'Attività', 'النشاط', 'गतिविधि'],
            'Store health' => ['Santé de la boutique', 'Salud de la tienda', 'Stato del negozio', 'صحة المتجر', 'स्टोर स्वास्थ्य'],
            'Webhooks & web services' => ['Webhooks et services web', 'Webhooks y servicios web', 'Webhook e servizi web', 'ويب هوك وخدمات الويب', 'वेबहुक और वेब सेवाएँ'],
            'Addresses' => ['Adresses', 'Direcciones', 'Indirizzi', 'العناوين', 'पते'],
            'Advanced' => ['Avancé', 'Avanzado', 'Avanzate', 'متقدم', 'उन्नत'],
            'Extensions' => ['Extensions', 'Extensiones', 'Estensioni', 'الإضافات', 'एक्सटेंशन'],
            'Companies' => ['Sociétés', 'Empresas', 'Aziende', 'الشركات', 'कंपनियाँ'],
            'Countries' => ['Pays', 'Países', 'Paesi', 'البلدان', 'देश'],
            'Currencies' => ['Devises', 'Monedas', 'Valute', 'العملات', 'मुद्राएँ'],
            'Customer' => ['Client', 'Cliente', 'Cliente', 'العميل', 'ग्राहक'],
            'Customer Group' => ['Groupe de clients', 'Grupo de clientes', 'Gruppo clienti', 'مجموعة العملاء', 'ग्राहक समूह'],
            'Quotes' => ['Devis', 'Cotizaciones', 'Preventivi', 'عروض الأسعار', 'कोटेशन'],
            'Questions' => ['Questions', 'Preguntas', 'Domande', 'الأسئلة', 'प्रश्न'],
            'Reviews' => ['Avis', 'Reseñas', 'Recensioni', 'التقييمات', 'समीक्षाएँ'],
            'Support' => ['Assistance', 'Soporte', 'Supporto', 'الدعم', 'सहायता'],
            'GDPR' => ['RGPD', 'RGPD', 'GDPR', 'حماية البيانات', 'जीडीपीआर'],
            'GDPR Data Requests' => ['Demandes RGPD', 'Solicitudes RGPD', 'Richieste GDPR', 'طلبات حماية البيانات', 'जीडीपीआर अनुरोध'],
            'Languages' => ['Langues', 'Idiomas', 'Lingue', 'اللغات', 'भाषाएँ'],
            'Localization' => ['Localisation', 'Localización', 'Localizzazione', 'التوطين', 'स्थानीयकरण'],
            'Menus' => ['Menus', 'Menús', 'Menu', 'القوائم', 'मेनू'],
            'Newsletter' => ['Newsletter', 'Boletín', 'Newsletter', 'النشرة', 'न्यूज़लेटर'],
            'Pages' => ['Pages', 'Páginas', 'Pagine', 'الصفحات', 'पृष्ठ'],
            'Payment methods' => ['Moyens de paiement', 'Métodos de pago', 'Metodi di pagamento', 'طرق الدفع', 'भुगतान तरीके'],
            'Shipping methods' => ['Modes de livraison', 'Métodos de envío', 'Metodi di spedizione', 'طرق الشحن', 'शिपिंग तरीके'],
            'Taxes' => ['Taxes', 'Impuestos', 'Tasse', 'الضرائب', 'कर'],
            'Themes' => ['Thèmes', 'Temas', 'Temi', 'السمات', 'थीम'],
            'Theme settings' => ['Réglages du thème', 'Ajustes del tema', 'Impostazioni tema', 'إعدادات السمة', 'थीम सेटिंग्स'],
            'Translations' => ['Traductions', 'Traducciones', 'Traduzioni', 'الترجمات', 'अनुवाद'],
            'Zones' => ['Zones', 'Zonas', 'Zone', 'المناطق', 'क्षेत्र'],
            'Roles' => ['Rôles', 'Roles', 'Ruoli', 'الأدوار', 'भूमिकाएँ'],
            'Permissions' => ['Autorisations', 'Permisos', 'Permessi', 'الأذونات', 'अनुमतियाँ'],
            'Employees' => ['Employés', 'Empleados', 'Dipendenti', 'الموظفون', 'कर्मचारी'],
            'Invoices' => ['Factures', 'Facturas', 'Fatture', 'الفواتير', 'चालान'],
            'Returns' => ['Retours', 'Devoluciones', 'Resi', 'المرتجعات', 'रिटर्न'],
            'Discounts' => ['Remises', 'Descuentos', 'Sconti', 'الخصومات', 'छूट'],
            'Brands' => ['Marques', 'Marcas', 'Marche', 'العلامات', 'ब्रांड'],
            'Features' => ['Caractéristiques', 'Características', 'Caratteristiche', 'الخصائص', 'विशेषताएँ'],
            'Suppliers' => ['Fournisseurs', 'Proveedores', 'Fornitori', 'الموردون', 'आपूर्तिकर्ता'],
            'Order statuses' => ['Statuts de commande', 'Estados del pedido', 'Stati ordine', 'حالات الطلب', 'ऑर्डर स्थितियाँ'],
            'Stock Alerts' => ['Alertes de stock', 'Alertas de stock', 'Avvisi scorte', 'تنبيهات المخزون', 'स्टॉक अलर्ट'],
            'Performance' => ['Performance', 'Rendimiento', 'Prestazioni', 'الأداء', 'प्रदर्शन'],
            'Images' => ['Images', 'Imágenes', 'Immagini', 'الصور', 'चित्र'],
            'URLs' => ['URLs', 'URLs', 'URL', 'الروابط', 'यूआरएल'],
            'SEO' => ['SEO', 'SEO', 'SEO', 'تحسين البحث', 'एसईओ'],
            'Blocks' => ['Blocs', 'Bloques', 'Blocchi', 'الكتل', 'ब्लॉक'],
            'Add' => ['Ajouter', 'Añadir', 'Aggiungi', 'إضافة', 'जोड़ें'],
            'Add new' => ['Ajouter', 'Añadir nuevo', 'Aggiungi nuovo', 'إضافة جديد', 'नया जोड़ें'],
            'Back' => ['Retour', 'Volver', 'Indietro', 'رجوع', 'वापस'],
            'Back to list' => ['Retour à la liste', 'Volver a la lista', 'Torna all’elenco', 'العودة إلى القائمة', 'सूची पर वापस'],
            'Clear' => ['Effacer', 'Borrar', 'Cancella', 'مسح', 'साफ़ करें'],
            'Close' => ['Fermer', 'Cerrar', 'Chiudi', 'إغلاق', 'बंद करें'],
            'Copy' => ['Copier', 'Copiar', 'Copia', 'نسخ', 'कॉपी'],
            'Create' => ['Créer', 'Crear', 'Crea', 'إنشاء', 'बनाएँ'],
            'Customer' => ['Client', 'Cliente', 'Cliente', 'العميل', 'ग्राहक'],
            'Date' => ['Date', 'Fecha', 'Data', 'التاريخ', 'तारीख'],
            'Disable' => ['Désactiver', 'Desactivar', 'Disattiva', 'تعطيل', 'अक्षम करें'],
            'Enable' => ['Activer', 'Activar', 'Attiva', 'تفعيل', 'सक्षम करें'],
            'Enabled' => ['Activé', 'Activado', 'Attivato', 'مفعّل', 'सक्षम'],
            'Export' => ['Exporter', 'Exportar', 'Esporta', 'تصدير', 'निर्यात'],
            'Import' => ['Importer', 'Importar', 'Importa', 'استيراد', 'आयात'],
            'Filter' => ['Filtrer', 'Filtrar', 'Filtra', 'تصفية', 'फ़िल्टर'],
            'From' => ['De', 'Desde', 'Da', 'من', 'से'],
            'Hide' => ['Masquer', 'Ocultar', 'Nascondi', 'إخفاء', 'छिपाएँ'],
            'Image' => ['Image', 'Imagen', 'Immagine', 'صورة', 'चित्र'],
            'Items' => ['Articles', 'Artículos', 'Articoli', 'عناصر', 'आइटम'],
            'Next' => ['Suivant', 'Siguiente', 'Successivo', 'التالي', 'अगला'],
            'Previous' => ['Précédent', 'Anterior', 'Precedente', 'السابق', 'पिछला'],
            'None' => ['Aucun', 'Ninguno', 'Nessuno', 'لا شيء', 'कोई नहीं'],
            'Note' => ['Note', 'Nota', 'Nota', 'ملاحظة', 'नोट'],
            'Notes' => ['Notes', 'Notas', 'Note', 'ملاحظات', 'नोट्स'],
            'of' => ['sur', 'de', 'di', 'من', 'में से'],
            'Off' => ['Non', 'No', 'No', 'إيقاف', 'बंद'],
            'On' => ['Oui', 'Sí', 'Sì', 'تشغيل', 'चालू'],
            'optional' => ['facultatif', 'opcional', 'facoltativo', 'اختياري', 'वैकल्पिक'],
            'Refresh' => ['Actualiser', 'Actualizar', 'Aggiorna', 'تحديث', 'रीफ़्रेश'],
            'Remove' => ['Retirer', 'Quitar', 'Rimuovi', 'إزالة', 'हटाएँ'],
            'Reset' => ['Réinitialiser', 'Restablecer', 'Reimposta', 'إعادة تعيين', 'रीसेट'],
            'Reset filters' => ['Réinitialiser les filtres', 'Restablecer filtros', 'Reimposta filtri', 'إعادة تعيين عوامل التصفية', 'फ़िल्टर रीसेट करें'],
            'results' => ['résultats', 'resultados', 'risultati', 'نتائج', 'परिणाम'],
            'Search…' => ['Rechercher…', 'Buscar…', 'Cerca…', 'بحث…', 'खोजें…'],
            'selected' => ['sélectionnés', 'seleccionados', 'selezionati', 'محدد', 'चयनित'],
            'Showing' => ['Affichage', 'Mostrando', 'Visualizzazione', 'عرض', 'दिखा रहे हैं'],
            'SKU' => ['SKU', 'SKU', 'SKU', 'رمز المنتج', 'एसकेयू'],
            'to' => ['à', 'a', 'a', 'إلى', 'तक'],
            'View' => ['Voir', 'Ver', 'Vedi', 'عرض', 'देखें'],
            'View all' => ['Tout voir', 'Ver todo', 'Vedi tutto', 'عرض الكل', 'सभी देखें'],
            'Qty' => ['Qté', 'Cant.', 'Qtà', 'الكمية', 'मात्रा'],
            'Preview' => ['Aperçu', 'Vista previa', 'Anteprima', 'معاينة', 'पूर्वावलोकन'],
            'Type' => ['Type', 'Tipo', 'Tipo', 'النوع', 'प्रकार'],
            'Untitled' => ['Sans titre', 'Sin título', 'Senza titolo', 'بدون عنوان', 'बिना शीर्षक'],
            'In Stock' => ['En stock', 'En stock', 'Disponibile', 'متوفر', 'स्टॉक में'],
            'Out of Stock' => ['Rupture de stock', 'Agotado', 'Esaurito', 'غير متوفر', 'स्टॉक में नहीं'],
            'New Product' => ['Nouveau produit', 'Nuevo producto', 'Nuovo prodotto', 'منتج جديد', 'नया उत्पाद'],
            'Total Products' => ['Total des produits', 'Total de productos', 'Totale prodotti', 'إجمالي المنتجات', 'कुल उत्पाद'],
            'No products yet.' => ['Aucun produit.', 'Aún no hay productos.', 'Nessun prodotto.', 'لا توجد منتجات بعد.', 'अभी कोई उत्पाद नहीं.'],
            'Price min' => ['Prix min', 'Precio mín', 'Prezzo min', 'أدنى سعر', 'न्यूनतम कीमत'],
            'Price max' => ['Prix max', 'Precio máx', 'Prezzo max', 'أقصى سعر', 'अधिकतम कीमत'],
            'Quantity min' => ['Quantité min', 'Cantidad mín', 'Quantità min', 'أدنى كمية', 'न्यूनतम मात्रा'],
            'Quantity max' => ['Quantité max', 'Cantidad máx', 'Quantità max', 'أقصى كمية', 'अधिकतम मात्रा'],
            'Filters' => ['Filtres', 'Filtros', 'Filtri', 'عوامل التصفية', 'फ़िल्टर'],
            'All Filters' => ['Tous les filtres', 'Todos los filtros', 'Tutti i filtri', 'كل عوامل التصفية', 'सभी फ़िल्टर'],
            'Clear all' => ['Tout effacer', 'Borrar todo', 'Cancella tutto', 'مسح الكل', 'सभी साफ़ करें'],
            'Sort by' => ['Trier par', 'Ordenar por', 'Ordina per', 'ترتيب حسب', 'क्रमबद्ध करें'],
            'Grid view' => ['Vue grille', 'Vista de cuadrícula', 'Vista griglia', 'عرض شبكي', 'ग्रिड दृश्य'],
            'List view' => ['Vue liste', 'Vista de lista', 'Vista elenco', 'عرض قائمة', 'सूची दृश्य'],
            'Availability' => ['Disponibilité', 'Disponibilidad', 'Disponibilità', 'التوفر', 'उपलब्धता'],
            'Discount' => ['Remise', 'Descuento', 'Sconto', 'خصم', 'छूट'],
            'Rating' => ['Note', 'Valoración', 'Valutazione', 'التقييم', 'रेटिंग'],
            'Price range' => ['Fourchette de prix', 'Rango de precios', 'Fascia di prezzo', 'نطاق السعر', 'कीमत सीमा'],
            'Deal of the Day' => ['Offre du jour', 'Oferta del día', 'Offerta del giorno', 'عرض اليوم', 'आज का सौदा'],
            'Popular Brands' => ['Marques populaires', 'Marcas populares', 'Marche popolari', 'علامات شائعة', 'लोकप्रिय ब्रांड'],
            'Join Our Newsletter' => ['Rejoignez la newsletter', 'Únete al boletín', 'Iscriviti alla newsletter', 'انضم إلى النشرة', 'न्यूज़लेटर से जुड़ें'],
            'Subscribe' => ['S’inscrire', 'Suscribirse', 'Iscriviti', 'اشتراك', 'सदस्यता लें'],
            'Your email address' => ['Votre adresse e-mail', 'Tu correo', 'Il tuo indirizzo email', 'بريدك الإلكتروني', 'आपका ईमेल'],
            'Read more' => ['Lire la suite', 'Leer más', 'Leggi di più', 'اقرأ المزيد', 'और पढ़ें'],
            'Read less' => ['Réduire', 'Leer menos', 'Leggi meno', 'اقرأ أقل', 'कम पढ़ें'],
            'About' => ['À propos', 'Acerca de', 'Informazioni', 'حول', 'परिचय'],
            'FAQ' => ['FAQ', 'Preguntas frecuentes', 'FAQ', 'الأسئلة الشائعة', 'सामान्य प्रश्न'],
            'My Account' => ['Mon compte', 'Mi cuenta', 'Il mio account', 'حسابي', 'मेरा खाता'],
            'My Cart' => ['Mon panier', 'Mi carrito', 'Il mio carrello', 'سلتي', 'मेरा कार्ट'],
            'Track Order' => ['Suivre la commande', 'Seguir pedido', 'Traccia ordine', 'تتبع الطلب', 'ऑर्डर ट्रैक करें'],
            'Track Your Order' => ['Suivre votre commande', 'Sigue tu pedido', 'Traccia il tuo ordine', 'تتبع طلبك', 'अपना ऑर्डर ट्रैक करें'],
            'Privacy Policy' => ['Politique de confidentialité', 'Política de privacidad', 'Informativa privacy', 'سياسة الخصوصية', 'गोपनीयता नीति'],
            'Terms and Conditions' => ['Conditions générales', 'Términos y condiciones', 'Termini e condizioni', 'الشروط والأحكام', 'नियम और शर्तें'],
            'Best sellers' => ['Meilleures ventes', 'Más vendidos', 'I più venduti', 'الأكثر مبيعًا', 'सर्वाधिक बिकने वाले'],
            'New products' => ['Nouveaux produits', 'Productos nuevos', 'Nuovi prodotti', 'منتجات جديدة', 'नए उत्पाद'],
            'Quick view' => ['Aperçu rapide', 'Vista rápida', 'Anteprima', 'عرض سريع', 'त्वरित दृश्य'],
            'Compare Products' => ['Comparer les produits', 'Comparar productos', 'Confronta prodotti', 'قارن المنتجات', 'उत्पादों की तुलना'],
            'All rights reserved.' => ['Tous droits réservés.', 'Todos los derechos reservados.', 'Tutti i diritti riservati.', 'جميع الحقوق محفوظة.', 'सर्वाधिकार सुरक्षित.'],
            'Open menu' => ['Ouvrir le menu', 'Abrir menú', 'Apri menu', 'فتح القائمة', 'मेनू खोलें'],
            'Close menu' => ['Fermer le menu', 'Cerrar menú', 'Chiudi menu', 'إغلاق القائمة', 'मेनू बंद करें'],
            'Verified buyer' => ['Acheteur vérifié', 'Comprador verificado', 'Acquirente verificato', 'مشتري موثّق', 'सत्यापित खरीदार'],
            'Verified reviewer' => ['Avis vérifié', 'Opinión verificada', 'Recensione verificata', 'مراجعة موثّقة', 'सत्यापित समीक्षा'],
            'Could not load the homepage.' => ['Impossible de charger l’accueil.', 'No se pudo cargar el inicio.', 'Impossibile caricare la home.', 'تعذر تحميل الصفحة الرئيسية.', 'होम पेज लोड नहीं हो सका.'],
            'Meta title' => ['Méta-titre', 'Metatítulo', 'Meta titolo', 'عنوان ميتا', 'मेटा शीर्षक'],
            'Meta description' => ['Méta-description', 'Meta descripción', 'Meta descrizione', 'وصف ميتا', 'मेटा विवरण'],
            'Meta keywords' => ['Mots-clés', 'Palabras clave', 'Parole chiave', 'الكلمات المفتاحية', 'मेटा कीवर्ड'],
            'Media' => ['Médias', 'Medios', 'Media', 'الوسائط', 'मीडिया'],
            'Position min' => ['Position min', 'Posición mín', 'Posizione min', 'أدنى موضع', 'न्यूनतम स्थिति'],
            'Position max' => ['Position max', 'Posición máx', 'Posizione max', 'أقصى موضع', 'अधिकतम स्थिति'],
            'Select all on this page' => ['Tout sélectionner sur cette page', 'Seleccionar todo en esta página', 'Seleziona tutto in questa pagina', 'تحديد الكل في هذه الصفحة', 'इस पृष्ठ पर सभी चुनें'],
            'Delete this item?' => ['Supprimer cet élément ?', '¿Eliminar este elemento?', 'Eliminare questo elemento?', 'حذف هذا العنصر؟', 'यह आइटम हटाएँ?'],
            'Delete selected items?' => ['Supprimer les éléments sélectionnés ?', '¿Eliminar los elementos seleccionados?', 'Eliminare gli elementi selezionati?', 'حذف العناصر المحددة؟', 'चयनित आइटम हटाएँ?'],
            'No matches' => ['Aucun résultat', 'Sin coincidencias', 'Nessun risultato', 'لا توجد نتائج', 'कोई मेल नहीं'],
            'Items per page' => ['Éléments par page', 'Elementos por página', 'Elementi per pagina', 'عناصر في الصفحة', 'प्रति पृष्ठ आइटम'],
            'Main navigation' => ['Navigation principale', 'Navegación principal', 'Navigazione principale', 'التنقل الرئيسي', 'मुख्य नेविगेशन'],
            'Collapse sidebar' => ['Réduire la barre', 'Contraer barra', 'Comprimi barra', 'طي الشريط', 'साइडबार समेटें'],
            'Expand sidebar' => ['Développer la barre', 'Expandir barra', 'Espandi barra', 'توسيع الشريط', 'साइडबार खोलें'],
            'View my store' => ['Voir ma boutique', 'Ver mi tienda', 'Vedi il mio negozio', 'عرض متجري', 'मेरी दुकान देखें'],
            'Super admin' => ['Super administrateur', 'Superadministrador', 'Super amministratore', 'مدير أعلى', 'सुपर एडमिन'],
            'Maintenance' => ['Maintenance', 'Mantenimiento', 'Manutenzione', 'الصيانة', 'रखरखाव'],
            'Group' => ['Groupe', 'Grupo', 'Gruppo', 'مجموعة', 'समूह'],
            'Product name' => ['Nom du produit', 'Nombre del producto', 'Nome prodotto', 'اسم المنتج', 'उत्पाद का नाम'],
            'Save product' => ['Enregistrer le produit', 'Guardar producto', 'Salva prodotto', 'حفظ المنتج', 'उत्पाद सहेजें'],
            'Save changes' => ['Enregistrer', 'Guardar cambios', 'Salva modifiche', 'حفظ التغييرات', 'बदलाव सहेजें'],
            'New product' => ['Nouveau produit', 'Nuevo producto', 'Nuovo prodotto', 'منتج جديد', 'नया उत्पाद'],
            'Edit product' => ['Modifier le produit', 'Editar producto', 'Modifica prodotto', 'تعديل المنتج', 'उत्पाद संपादित करें'],
            'Video URL' => ['URL de la vidéo', 'URL del vídeo', 'URL del video', 'رابط الفيديو', 'वीडियो यूआरएल'],
            'YouTube or Vimeo. It plays under the photos on the product page.' => ['YouTube ou Vimeo. La vidéo se lit sous les photos de la fiche produit.', 'YouTube o Vimeo. Se reproduce bajo las fotos de la ficha.', 'YouTube o Vimeo. Si riproduce sotto le foto della scheda.', 'يوتيوب أو فيميو. يُعرض تحت صور صفحة المنتج.', 'YouTube या Vimeo. उत्पाद फ़ोटो के नीचे चलता है.'],
            'Brand' => ['Marque', 'Marca', 'Marca', 'العلامة', 'ब्रांड'],
            'Select brand' => ['Choisir une marque', 'Elegir marca', 'Scegli marca', 'اختر علامة', 'ब्रांड चुनें'],
            'Visibility' => ['Visibilité', 'Visibilidad', 'Visibilità', 'الظهور', 'दृश्यता'],
            'Where do you want your product to appear?' => ['Où le produit doit-il apparaître ?', '¿Dónde debe aparecer el producto?', 'Dove deve comparire il prodotto?', 'أين تريد ظهور المنتج؟', 'उत्पाद कहाँ दिखे?'],
            'Everywhere' => ['Partout', 'En todas partes', 'Ovunque', 'في كل مكان', 'हर जगह'],
            'Catalog only' => ['Catalogue seulement', 'Solo catálogo', 'Solo catalogo', 'الكتالوج فقط', 'केवल कैटलॉग'],
            'Search only' => ['Recherche seulement', 'Solo búsqueda', 'Solo ricerca', 'البحث فقط', 'केवल खोज'],
            'Nowhere' => ['Nulle part', 'En ninguna parte', 'Da nessuna parte', 'في لا مكان', 'कहीं नहीं'],
            'Customers can access the product by browsing the catalog, using the search bar, or the link.' => ['Les clients y accèdent par le catalogue, la recherche ou le lien.', 'Los clientes acceden por el catálogo, la búsqueda o el enlace.', 'I clienti lo trovano dal catalogo, dalla ricerca o dal link.', 'يمكن للعملاء الوصول عبر الكتالوج أو البحث أو الرابط.', 'ग्राहक कैटलॉग, खोज या लिंक से पहुँच सकते हैं.'],
            'Customers can access the product by browsing the catalog or the link.' => ['Les clients y accèdent par le catalogue ou le lien.', 'Los clientes acceden por el catálogo o el enlace.', 'I clienti lo trovano dal catalogo o dal link.', 'يمكن للعملاء الوصول عبر الكتالوج أو الرابط.', 'ग्राहक कैटलॉग या लिंक से पहुँच सकते हैं.'],
            'Customers can access the product by using the search bar or the link.' => ['Les clients y accèdent par la recherche ou le lien.', 'Los clientes acceden por la búsqueda o el enlace.', 'I clienti lo trovano dalla ricerca o dal link.', 'يمكن للعملاء الوصول عبر البحث أو الرابط.', 'ग्राहक खोज या लिंक से पहुँच सकते हैं.'],
            'The product is not visible. Customers can only access it with the link.' => ['Le produit est masqué. Seul le lien permet d’y accéder.', 'El producto está oculto. Solo se accede con el enlace.', 'Il prodotto è nascosto. Si raggiunge solo con il link.', 'المنتج غير ظاهر. الوصول فقط عبر الرابط.', 'उत्पाद छिपा है. केवल लिंक से खुलेगा.'],
            'Short description (max {max})' => ['Description courte (max {max})', 'Descripción breve (máx {max})', 'Descrizione breve (max {max})', 'وصف قصير (الحد {max})', 'संक्षिप्त विवरण (अधिकतम {max})'],
            'Description (max {max})' => ['Description (max {max})', 'Descripción (máx {max})', 'Descrizione (max {max})', 'الوصف (الحد {max})', 'विवरण (अधिकतम {max})'],
            'Max limit {max}' => ['Limite max {max}', 'Límite máx {max}', 'Limite max {max}', 'الحد الأقصى {max}', 'अधिकतम सीमा {max}'],
            'Write product description, headings, lists, and insert images…' => ['Rédigez la description, des titres, des listes, et insérez des images…', 'Escribe la descripción, títulos, listas e inserta imágenes…', 'Scrivi la descrizione, titoli, elenchi e inserisci immagini…', 'اكتب الوصف والعناوين والقوائم وأدرج الصور…', 'विवरण, शीर्षक, सूचियाँ लिखें और चित्र जोड़ें…'],
            'Details' => ['Détails', 'Detalles', 'Dettagli', 'التفاصيل', 'विवरण'],
            'Pricing' => ['Tarifs', 'Precios', 'Prezzi', 'التسعير', 'मूल्य'],
            'Combinations' => ['Déclinaisons', 'Combinaciones', 'Combinazioni', 'التركيبات', 'संयोजन'],
            'Customizable' => ['Personnalisable', 'Personalizable', 'Personalizzabile', 'قابل للتخصيص', 'अनुकूलन'],
            'Subscription' => ['Abonnement', 'Suscripción', 'Abbonamento', 'اشتراك', 'सदस्यता'],
            'Pre-order' => ['Précommande', 'Reserva', 'Preordine', 'طلب مسبق', 'प्री-ऑर्डर'],
            'Digital files' => ['Fichiers numériques', 'Archivos digitales', 'File digitali', 'ملفات رقمية', 'डिजिटल फ़ाइलें'],
            'Gift card' => ['Carte cadeau', 'Tarjeta regalo', 'Carta regalo', 'بطاقة هدية', 'गिफ्ट कार्ड'],
            'Gift Card' => ['Carte cadeau', 'Tarjeta regalo', 'Carta regalo', 'بطاقة هدية', 'गिफ्ट कार्ड'],
            'Service' => ['Service', 'Servicio', 'Servizio', 'خدمة', 'सेवा'],
            'Rental' => ['Location', 'Alquiler', 'Noleggio', 'إيجار', 'किराया'],
            'Bundle' => ['Lot', 'Pack', 'Bundle', 'حزمة', 'बंडल'],
            'Variants' => ['Variantes', 'Variantes', 'Varianti', 'المتغيرات', 'वेरिएंट'],
            'Attributes' => ['Attributs', 'Atributos', 'Attributi', 'السمات', 'विशेषताएँ'],
            'History' => ['Historique', 'Historial', 'Cronologia', 'السجل', 'इतिहास'],
            'Standard' => ['Standard', 'Estándar', 'Standard', 'قياسي', 'स्टैंडर्ड'],
            'Grouped' => ['Groupé', 'Agrupado', 'Raggruppato', 'مجمّع', 'समूहित'],
            'Virtual' => ['Virtuel', 'Virtual', 'Virtuale', 'افتراضي', 'वर्चुअल'],
            'Column title' => ['Titre de colonne', 'Título de columna', 'Titolo colonna', 'عنوان العمود', 'कॉलम शीर्षक'],
            'Link label' => ['Libellé du lien', 'Etiqueta del enlace', 'Etichetta link', 'نص الرابط', 'लिंक लेबल'],
            'Copyright line' => ['Ligne de copyright', 'Línea de copyright', 'Riga copyright', 'سطر حقوق النشر', 'कॉपीराइट पंक्ति'],
            'Button label' => ['Libellé du bouton', 'Texto del botón', 'Testo pulsante', 'نص الزر', 'बटन लेबल'],
            'Kicker' => ['Surtitre', 'Antetítulo', 'Occhiello', 'سطر علوي', 'किकर'],
            'Subtitle' => ['Sous-titre', 'Subtítulo', 'Sottotitolo', 'عنوان فرعي', 'उपशीर्षक'],
            'Title' => ['Titre', 'Título', 'Titolo', 'العنوان', 'शीर्षक'],
            'Announcement text' => ['Texte d’annonce', 'Texto del anuncio', 'Testo annuncio', 'نص الإعلان', 'घोषणा पाठ'],
            'Contact label' => ['Libellé du contact', 'Etiqueta de contacto', 'Etichetta contatto', 'نص جهة الاتصال', 'संपर्क लेबल'],
            'New tab' => ['Nouvel onglet', 'Nueva pestaña', 'Nuova scheda', 'تبويب جديد', 'नया टैब'],
            'Logo text override' => ['Texte du logo', 'Texto del logo', 'Testo del logo', 'نص الشعار', 'लोगो पाठ'],
            'Product video' => ['Vidéo du produit', 'Vídeo del producto', 'Video prodotto', 'فيديو المنتج', 'उत्पाद वीडियो'],
            'Type' => ['Type', 'Tipo', 'Tipo', 'النوع', 'प्रकार'],
            'Links' => ['Liens', 'Enlaces', 'Link', 'روابط', 'लिंक'],
            'Categories' => ['Catégories', 'Categorías', 'Categorie', 'الفئات', 'श्रेणियाँ'],
            'Add link' => ['Ajouter un lien', 'Añadir enlace', 'Aggiungi link', 'إضافة رابط', 'लिंक जोड़ें'],
            'Add column' => ['Ajouter une colonne', 'Añadir columna', 'Aggiungi colonna', 'إضافة عمود', 'कॉलम जोड़ें'],
            'Remove column' => ['Retirer la colonne', 'Quitar columna', 'Rimuovi colonna', 'إزالة العمود', 'कॉलम हटाएँ'],
            'URL' => ['URL', 'URL', 'URL', 'الرابط', 'यूआरएल'],
            'Image URL' => ['URL de l’image', 'URL de la imagen', 'URL immagine', 'رابط الصورة', 'चित्र यूआरएल'],
            'Body' => ['Texte', 'Texto', 'Testo', 'النص', 'पाठ'],
            'HTML' => ['HTML', 'HTML', 'HTML', 'HTML', 'HTML'],
            'Quote' => ['Citation', 'Cita', 'Citazione', 'اقتباس', 'उद्धरण'],
            'Role' => ['Rôle', 'Rol', 'Ruolo', 'الدور', 'भूमिका'],
            'Name' => ['Nom', 'Nombre', 'Nome', 'الاسم', 'नाम'],
            'How many products' => ['Nombre de produits', 'Cuántos productos', 'Quanti prodotti', 'عدد المنتجات', 'कितने उत्पाद'],
            'Shop Now' => ['Acheter', 'Comprar', 'Acquista', 'تسوق الآن', 'अभी खरीदें'],
            'Square PNG, JPG, WebP, or GIF (max 2 MB). Save theme settings after uploading.' => ['PNG, JPG, WebP ou GIF carré (2 Mo max). Enregistrez ensuite.', 'PNG, JPG, WebP o GIF cuadrado (máx. 2 MB). Guarda después.', 'PNG, JPG, WebP o GIF quadrato (max 2 MB). Poi salva.', 'PNG أو JPG أو WebP أو GIF مربع (2 م.ب). ثم احفظ.', 'वर्गाकार PNG, JPG, WebP या GIF (अधिकतम 2 MB). फिर सहेजें.'],
            'The small icon in the browser tab.' => ['La petite icône de l’onglet.', 'El icono de la pestaña.', 'L’icona della scheda.', 'أيقونة علامة التبويب.', 'ब्राउज़र टैब का आइकन.'],
            'Default icon' => ['Icône par défaut', 'Icono predeterminado', 'Icona predefinita', 'الأيقونة الافتراضية', 'डिफ़ॉल्ट आइकन'],
            'Default logo' => ['Logo par défaut', 'Logo predeterminado', 'Logo predefinito', 'الشعار الافتراضي', 'डिफ़ॉल्ट लोगो'],
            'Header menu' => ['Menu d’en-tête', 'Menú de cabecera', 'Menu intestazione', 'قائمة الترويسة', 'हेडर मेनू'],
            'Category menu' => ['Menu des catégories', 'Menú de categorías', 'Menu categorie', 'قائمة الفئات', 'श्रेणी मेनू'],
            'Custom menu' => ['Menu personnalisé', 'Menú personalizado', 'Menu personalizzato', 'قائمة مخصصة', 'कस्टम मेनू'],
            'Choose categories' => ['Choisir les catégories', 'Elegir categorías', 'Scegli categorie', 'اختر الفئات', 'श्रेणियाँ चुनें'],
            'Edit custom menu' => ['Modifier le menu', 'Editar el menú', 'Modifica menu', 'تعديل القائمة', 'मेनू संपादित करें'],
            'Logo text' => ['Texte du logo', 'Texto del logo', 'Testo del logo', 'نص الشعار', 'लोगो पाठ'],
            'Show top bar' => ['Afficher la barre', 'Mostrar la barra', 'Mostra la barra', 'إظهار الشريط', 'टॉप बार दिखाएँ'],
            'Announcement, phone, and links above the header.' => ['Annonce, téléphone et liens au-dessus de l’en-tête.', 'Anuncio, teléfono y enlaces sobre la cabecera.', 'Annuncio, telefono e link sopra l’intestazione.', 'إعلان وهاتف وروابط فوق الترويسة.', 'हेडर के ऊपर घोषणा, फ़ोन और लिंक.'],
            'Selected' => ['Sélectionné', 'Seleccionado', 'Selezionato', 'محدد', 'चयनित'],
            'Cart style' => ['Style du panier', 'Estilo del carrito', 'Stile carrello', 'شكل السلة', 'कार्ट शैली'],
            'Pill' => ['Pastille', 'Pastilla', 'Pillola', 'كبسولة', 'पिल'],
            'Icon only' => ['Icône seule', 'Solo icono', 'Solo icona', 'أيقونة فقط', 'केवल आइकन'],
            'Text inside the header search box.' => ['Texte dans la recherche de l’en-tête.', 'Texto del buscador de la cabecera.', 'Testo nella ricerca dell’intestazione.', 'نص مربع البحث في الترويسة.', 'हेडर खोज बॉक्स का पाठ.'],
            'Logo, search, and the menu in the header.' => ['Logo, recherche et menu de l’en-tête.', 'Logo, búsqueda y menú de la cabecera.', 'Logo, ricerca e menu dell’intestazione.', 'الشعار والبحث وقائمة الترويسة.', 'लोगो, खोज और हेडर मेनू.'],
            'Storefront preview' => ['Aperçu de la boutique', 'Vista de la tienda', 'Anteprima negozio', 'معاينة المتجر', 'स्टोर पूर्वावलोकन'],
            'Move left' => ['Déplacer à gauche', 'Mover a la izquierda', 'Sposta a sinistra', 'تحريك لليسار', 'बाएँ ले जाएँ'],
            'Move right' => ['Déplacer à droite', 'Mover a la derecha', 'Sposta a destra', 'تحريك لليمين', 'दाएँ ले जाएँ'],
            'Named pages in this column.' => ['Pages nommées dans cette colonne.', 'Páginas con nombre en esta columna.', 'Pagine con nome in questa colonna.', 'صفحات بأسماء في هذا العمود.', 'इस कॉलम में नामित पेज.'],
            'Shop name, address, email, and phone from store settings.' => ['Nom, adresse, e-mail et téléphone des réglages.', 'Nombre, dirección, correo y teléfono de la tienda.', 'Nome, indirizzo, email e telefono dalle impostazioni.', 'الاسم والعنوان والبريد والهاتف من إعدادات المتجر.', 'स्टोर सेटिंग से नाम, पता, ईमेल और फ़ोन.'],
            'Catalog categories, plus any extra links.' => ['Catégories du catalogue, plus des liens.', 'Categorías del catálogo y enlaces extra.', 'Categorie del catalogo, più link extra.', 'فئات الكتالوج وروابط إضافية.', 'कैटलॉग श्रेणियाँ और अतिरिक्त लिंक.'],
            'Pick categories. Each one already has its page URL.' => ['Choisissez des catégories. Chacune a déjà son URL.', 'Elige categorías. Cada una ya tiene su URL.', 'Scegli le categorie. Ognuna ha già il suo URL.', 'اختر الفئات. لكل منها رابطه.', 'श्रेणियाँ चुनें. हर एक का पेज यूआरएल पहले से है.'],
            'Add category' => ['Ajouter une catégorie', 'Añadir categoría', 'Aggiungi categoria', 'إضافة فئة', 'श्रेणी जोड़ें'],
            'Choose a category' => ['Choisir une catégorie', 'Elegir una categoría', 'Scegli una categoria', 'اختر فئة', 'श्रेणी चुनें'],
            'No categories yet.' => ['Aucune catégorie.', 'Sin categorías.', 'Nessuna categoria.', 'لا توجد فئات.', 'अभी कोई श्रेणी नहीं.'],
            'No links yet.' => ['Aucun lien.', 'Sin enlaces.', 'Nessun link.', 'لا توجد روابط.', 'अभी कोई लिंक नहीं.'],
            'Add the first column' => ['Ajouter la première colonne', 'Añadir la primera columna', 'Aggiungi la prima colonna', 'أضف العمود الأول', 'पहला कॉलम जोड़ें'],
            'Hero Banner' => ['Bannière principale', 'Banner principal', 'Banner principale', 'البانر الرئيسي', 'हीरो बैनर'],
            'Pick layout 1 / 2 / 3 and configure it.' => ['Choisissez la mise en page 1 / 2 / 3, puis configurez.', 'Elige el diseño 1 / 2 / 3 y configúralo.', 'Scegli il layout 1 / 2 / 3 e configuralo.', 'اختر التخطيط 1 / 2 / 3 ثم اضبطه.', 'लेआउट 1 / 2 / 3 चुनें और सेट करें.'],
            'Full width' => ['Pleine largeur', 'Ancho completo', 'Larghezza intera', 'عرض كامل', 'पूरी चौड़ाई'],
            'Two banners' => ['Deux bannières', 'Dos banners', 'Due banner', 'بانران', 'दो बैनर'],
            'Main and two sides' => ['Principal et deux côtés', 'Principal y dos laterales', 'Principale e due lati', 'رئيسي وجانبان', 'मुख्य और दो साइड'],
        ];
    }
}
