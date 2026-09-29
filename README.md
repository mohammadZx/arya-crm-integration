# Arya Portal Integration

افزونه یکپارچگی با پورتال آریا تهران برای همگام‌سازی محصولات، سفارشات و اطلاعات کاربران با WooCommerce.

## ویژگی‌ها

- 🔄 همگام‌سازی خودکار سفارشات با پورتال
- 📦 مدیریت محصولات و واریانت‌ها از طریق REST API
- 👥 مدیریت اطلاعات کاربران و ثبت‌نام‌ها
- ⚙️ تنظیمات کامل و قابل تنظیم
- 🔐 امنیت بالا با استفاده از توکن API

## نیازمندی‌ها

- WordPress 5.0 یا بالاتر
- WooCommerce 5.0 یا بالاتر
- PHP 7.4 یا بالاتر
 
## نصب

1. پوشه افزونه را در `wp-content/plugins/` قرار دهید
2. افزونه را از پنل مدیریت WordPress فعال کنید
3. به **WooCommerce > پورتال آریا** بروید
4. تنظیمات اتصال را وارد کنید:
   - آدرس پورتال
   - توکن API

## تنظیمات

پس از نصب، به بخش **WooCommerce > پورتال آریا** در پنل مدیریت بروید و تنظیمات زیر را وارد کنید:

- **آدرس پورتال**: آدرس کامل پورتال آریا تهران (مثال: https://portal.aryatehran.com)
- **توکن API**: توکن دسترسی API پورتال
- **شناسه دسته‌بندی دوره‌ها**: شناسه دسته‌بندی دوره‌ها در پورتال
- **کد کلاس خصوصی**: کد دوره کلاس خصوصی در پورتال

## REST API Endpoints

افزونه endpointهای زیر را ارائه می‌دهد:

- `POST /wp-json/portal/class-course` - افزودن کلاس دوره
- `PUT /wp-json/portal/class-course` - ویرایش کلاس دوره
- `DELETE /wp-json/portal/class-course` - حذف کلاس دوره
- `POST /wp-json/portal/product` - افزودن محصول
- `PUT /wp-json/portal/product` - ویرایش محصول
- `DELETE /wp-json/portal/product` - حذف محصول
- `POST /wp-json/portal/order` - افزودن سفارش
- `POST /wp-json/portal/course-headline` - افزودن سرفصل‌ها
- `GET /wp-json/portal/search` - جستجوی محصول
- `GET /wp-json/portal/products` - فهرست صفحه‌بندی‌شده‌ی کاتالوگ برای ایمپورت CRM
  - `with_parents=1`: ردیف خود محصول متغیر (`kind=parent` با `variation_ids`) پیش از واریانت‌هایش
  - `with_content=1`: `description`، `short_description`، `image` (عکس خود محصول/واریانت) و `attributes`
    (مادر/ساده: `options` و پرچم‌های `visible`/`variation`؛ واریانت: `option`)؛ ویژگی سراسری با نام مقدار، نه نامک
  - بدون این دو پارامتر خروجی همان نسخه‌ی قبلی است (سازگاری با CRM قدیمی)
  - `sku` واریانت (با هر پارامتری) فقط SKU خود آن است و اگر ندارد خالی؛ SKU ارثی مادر فرستاده
    نمی‌شود، چون CRM همه‌ی واریانت‌ها را یک کالا (یا کالای بی‌ربطی با همان کد) می‌دید
- `POST /wp-json/portal/sync-product` - همگام‌سازی محصول

## استفاده

### استفاده از PersonData

```php
use Arya\Portal\PersonData;

$personData = new PersonData('09123456789');
$registers = $personData->getPersonRegisters();
```

### استفاده از OrderHandler

```php
use Arya\Portal\OrderHandler;

$handler = OrderHandler::instance();
$handler->insert_on_portal($order_id);
```

## ساختار فایل‌ها

```
arya-portal-integration/
├── arya-portal-integration.php  # فایل اصلی افزونه
├── includes/
│   ├── PersonData.php           # کلاس ارتباط با API پورتال
│   ├── Settings.php             # مدیریت تنظیمات
│   ├── REST_API.php             # REST API endpoints
│   ├── OrderHandler.php         # مدیریت سفارشات
│   ├── ProductCatalog.php       # توضیحات، عکس و ویژگی‌ها برای ایمپورت CRM
│   └── helpers/
│       └── VariationHelper.php  # Helper برای واریانت‌ها
└── README.md
```

## توسعه

برای توسعه این افزونه:

1. از namespace `Arya\Portal` استفاده کنید
2. کلاس‌ها را در پوشه `includes/` قرار دهید
3. Helperها را در `includes/helpers/` قرار دهید

## پشتیبانی

برای پشتیبانی و گزارش باگ، لطفاً با تیم توسعه تماس بگیرید.

## لایسنس

این افزونه برای استفاده داخلی تیم آریا تهران توسعه یافته است.

