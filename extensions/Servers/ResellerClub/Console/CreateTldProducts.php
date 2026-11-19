<?php

namespace Paymenter\Extensions\Servers\ResellerClub\Console;

use App\Models\Category;
use App\Models\Server;
use Illuminate\Console\Command;
use Paymenter\Extensions\Servers\ResellerClub\Core\TldProducts;

class CreateTldProducts extends Command
{
    protected $signature = 'resellerclub:create-tld-products {tlds* : TLDs without the dot, e.g. com net co.uk} {--category= : Category ID} {--years=10 : Longest term to offer}';

    protected $description = 'Create one ResellerClub domain product per TLD with 1-N year plans';

    public function handle(): int
    {
        $server = Server::where('extension', 'ResellerClub')->first();
        if (!$server) {
            $this->error('No ResellerClub server is configured.');

            return self::FAILURE;
        }
        $category = $this->option('category') ? Category::find($this->option('category')) : Category::first();
        if (!$category) {
            $this->error('Category not found; pass --category=ID.');

            return self::FAILURE;
        }

        $creator = new TldProducts();
        foreach ($this->argument('tlds') as $tld) {
            try {
                $r = $creator->create($tld, $category->id, $server, (int) $this->option('years'));
            } catch (\InvalidArgumentException $e) {
                $this->warn($e->getMessage());

                continue;
            }
            $this->line($r['created']
                ? ".$tld: product {$r['product_id']} created with " . count($r['terms']) . ' plans' . ($r['priced'] ? '' : ' (no prices yet; the pricing sync will propose them)')
                : ".$tld: already exists (product {$r['product_id']})");
        }

        return self::SUCCESS;
    }
}
