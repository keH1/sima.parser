<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use GuzzleHttp\Client;
use Symfony\Component\DomCrawler\Crawler;
use App\Models\Category;
use App\Models\Product;
use App\Models\Brand;
use App\Models\Image;
use App\Models\Attribute;
use App\Models\AttributeGroup;
use App\Models\ProductAttribute;

class Parse2CentSite extends Command
{
    protected $signature = 'parse:2cent {--category-url=}';

    protected $description = 'Парсинг указанной категории сайта 2cent.ru и обновление базы данных';

    protected $baseUrl = 'https://2cent.ru';

    protected $httpClient;

    public function __construct()
    {
        parent::__construct();

        $this->httpClient = new Client([
            'base_uri' => $this->baseUrl,
            'verify' => false, // Если возникают проблемы с SSL-сертификатом
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (compatible; Bot/1.0)',
            ],
        ]);
    }

    public function handle()
    {
        $categoryUrl = $this->option('category-url');

        if (!$categoryUrl) {
            $this->error('Пожалуйста, укажите URL категории с помощью опции --category-url.');
            return;
        }

        $this->info('Начинаем парсинг категории: ' . $categoryUrl);

        $categoryName = $this->getCategoryName($categoryUrl);

        $category = [
            'name' => $categoryName,
            'url' => $categoryUrl,
        ];

        $this->info('Категория: ' . $categoryName);

        $productUrls = $this->getProductUrls($category['url']);

        $this->info('Найдено продуктов: ' . count($productUrls));

        foreach ($productUrls as $productUrl) {
            $this->info('Обрабатываем продукт: ' . $productUrl);

            $productData = $this->parseProduct($productUrl, $category);

            if ($productData) {
                $this->saveProduct($productData);
            }
        }

        $this->info('Парсинг завершен.');
    }

    protected function getCategoryName($categoryUrl)
    {
        $response = $this->httpClient->get($categoryUrl);

        $html = (string) $response->getBody();
        $crawler = new Crawler($html);

        $categoryNameNode = $crawler->filter('h1');

        if ($categoryNameNode->count()) {
            return trim($categoryNameNode->text());
        }

        return 'Без названия';
    }

    protected function getProductUrls($categoryUrl)
    {
        $productUrls = [];

        $page = 1;

        do {
            $this->info('Получаем продукты со страницы ' . $page);

            $response = $this->httpClient->get($categoryUrl, [
                'query' => ['p' => $page],
            ]);

            $html = (string) $response->getBody();
            file_put_contents('init.log', $html, FILE_APPEND);
            $crawler = new Crawler($html);

            $links = $crawler->filter('item-card__title')->each(function (Crawler $node) {
                $url = $node->attr('href');
                return $this->normalizeUrl($url);
            });

            $productUrls = array_merge($productUrls, $links);

            $nextPageNode = $crawler->filter('.pagination .next');

            $hasNextPage = $nextPageNode->count() > 0;

            $page++;
        } while ($hasNextPage);

        return $productUrls;
    }

    protected function parseProduct($productUrl, $category)
    {
        try {
            $response = $this->httpClient->get($productUrl);

            $html = (string) $response->getBody();
            $crawler = new Crawler($html);

            $productData = [];

            $productData['external_id'] = $this->extractExternalId($crawler);

            $productData['name'] = $crawler->filter('h1.product-title')->text();

            $productData['brand'] = $this->extractBrand($crawler);

            $productData['price'] = $this->extractPrice($crawler, '.rs-price-new');
            $productData['original_price'] = $this->extractPrice($crawler, '.rs-price-old');

            $descriptionNode = $crawler->filter('#tab-description');
            $productData['description'] = $descriptionNode->count() ? $descriptionNode->html() : '';

            $availabilityNode = $crawler->filter('.item-card__not-available');
            $productData['is_available'] = $availabilityNode->count() == 0;

            $productData['images'] = $crawler->filter('.product-gallery-top img')->each(function (Crawler $node) {
                return $this->normalizeUrl($node->attr('src'));
            });

            $productData['attributes'] = $this->extractAttributes($crawler);

            $productData['category'] = $category['name'];

            return $productData;
        } catch (\Exception $e) {
            $this->error('Ошибка при парсинге продукта: ' . $productUrl);
            $this->error($e->getMessage());
            return null;
        }
    }

    protected function extractExternalId(Crawler $crawler)
    {
        $externalId = $crawler->filter('input[name="offer"]')->attr('value');

        return $externalId ? $externalId : null;
    }

    protected function extractBrand(Crawler $crawler)
    {
        $brandNode = $crawler->filter('.product-chars li')->reduce(function (Crawler $node) {
            return strpos($node->text(), 'Производитель') !== false;
        });

        if ($brandNode->count()) {
            return $brandNode->filter('.fw-bold')->text();
        }

        return null;
    }

    protected function extractPrice(Crawler $crawler, $selector)
    {
        $priceNode = $crawler->filter($selector);

        if ($priceNode->count()) {
            $priceText = $priceNode->text();

            $price = preg_replace('/[^\d,.]/', '', $priceText);
            $price = str_replace(',', '.', $price);

            return (float) $price;
        }

        return null;
    }

    protected function extractAttributes(Crawler $crawler)
    {
        $attributes = [];

        $crawler->filter('#tab-property > div')->each(function (Crawler $groupNode) use (&$attributes) {
            $groupName = $groupNode->filter('.fw-bold')->first()->text();

            $groupNode->filter('ul.product-chars li')->each(function (Crawler $attrNode) use (&$attributes, $groupName) {
                $nameNode = $attrNode->filter('.col-sm-7, .col-6')->first();
                $valueNode = $attrNode->filter('.fw-bold')->first();

                if ($nameNode->count() && $valueNode->count()) {
                    $name = trim($nameNode->text());
                    $value = trim($valueNode->text());

                    $attributes[] = [
                        'group' => $groupName,
                        'name' => $name,
                        'value' => $value,
                    ];
                }
            });
        });

        return $attributes;
    }

    protected function saveProduct($productData)
    {
        if (!$productData) {
            return;
        }

        $brand = null;
        if ($productData['brand']) {
            $brand = Brand::firstOrCreate(
                ['name' => $productData['brand']],
                ['slug' => \Str::slug($productData['brand'])]
            );
        }

        $category = null;
        if ($productData['category']) {
            $category = Category::firstOrCreate(
                ['name' => $productData['category']],
                ['slug' => \Str::slug($productData['category']), 'parent_id' => null]
            );
        }

        $product = Product::where('external_id', $productData['external_id'])->first();

        if ($product) {
            $product->update([
                'price' => $productData['price'],
                'original_price' => $productData['original_price'],
                'is_available' => $productData['is_available'],
            ]);

            $this->info('Обновлен продукт: ' . $product->name);
        } else {
            $product = Product::create([
                'external_id' => $productData['external_id'],
                'name' => $productData['name'],
                'description' => $productData['description'],
                'price' => $productData['price'],
                'original_price' => $productData['original_price'],
                'is_available' => $productData['is_available'],
                'brand_id' => $brand ? $brand->id : null,
                'category_id' => $category ? $category->id : null,
            ]);

            foreach ($productData['images'] as $imageUrl) {
                $image = new Image([
                    'url' => $imageUrl,
                ]);
                $product->images()->save($image);
            }

            foreach ($productData['attributes'] as $attrData) {
                $group = AttributeGroup::firstOrCreate(['name' => $attrData['group']]);

                $attribute = Attribute::firstOrCreate(
                    ['name' => $attrData['name'], 'attribute_group_id' => $group->id]
                );

                ProductAttribute::create([
                    'product_id' => $product->id,
                    'attribute_id' => $attribute->id,
                    'value' => $attrData['value'],
                ]);
            }

            $this->info('Добавлен новый продукт: ' . $product->name);
        }
    }

    protected function normalizeUrl($url)
    {
        if (strpos($url, 'http') === 0) {
            return $url;
        } else {
            return $this->baseUrl . $url;
        }
    }
}
